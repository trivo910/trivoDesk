// services/wabaCallBridge.js
// ============================================================
// WABA Calling — AI voice pickup, real-time audio loop end-to-end.
//
// Required deps (already in package.json):
//   - @roamhq/wrtc    WebRTC peer connection + audio sink/source
//                     (Maintained fork of the abandoned original `wrtc`
//                     package — same API. Required for Node 18+.)
//   - ws              WebSocket clients for STT and TTS
//
// Pipeline per call:
//
//   Meta webhook `connect`
//        ↓ (Laravel calls /api/waba-call/answer with sdp_offer)
//   openSession()
//        ↓ Build RTCPeerConnection
//        ↓ setRemoteDescription(offer)
//        ↓ Attach RTCAudioSource (outbound to caller)
//        ↓ Attach RTCAudioSink to inbound track when it lands
//        ↓ createAnswer + setLocalDescription
//        ↓ patch a=setup:actpass → a=setup:active
//        ↓ POST pre_accept then accept to Graph /calls
//
//   Caller speaks
//        ↓ wrtc gives us PCM frames (Int16, mono, 48 kHz)
//        ↓ resample to 16 kHz mono → Deepgram WS STT
//        ↓ STT emits `is_final` transcripts → buffer turn
//        ↓ end-of-speech → call LLM with conversation history
//        ↓ LLM reply → ElevenLabs TTS streaming WS (PCM 16k output)
//        ↓ upsample to 48 kHz → push frames into RTCAudioSource
//        ↓ caller hears the AI
//
//   Recording
//        ↓ Tee both PCM streams to .raw files under
//          public/uploads/call-recordings/{call_id}_(agent|user).pcm
//        ↓ On terminate, ffmpeg can convert → wav/mp3 (or do it lazily
//          in Laravel when the operator clicks Play). For now the raw
//          PCM URL lands in ai_call_logs.recording_url_*.
//
//   On terminate
//        ↓ closeSession() flushes sockets, finalises recordings, posts
//          terminate to Graph /calls, writes a transcript_complete
//          event back to Laravel via /api/waba-call/transcript-turn.
// ============================================================

import axios from 'axios';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import { WebSocket } from 'ws';
import { attachCallFlow, hasCallFlow, runFromStart, handleTurn } from './callFlowRuntime.js';

// @roamhq/wrtc is optional at module-load time so the rest of Node
// still boots when the dep isn't installed yet. The bridge gracefully
// falls back to "decline + voicemail" when wrtc is unavailable.
//
// Original `wrtc` (npm) was abandoned in 2023 and fails to build on Node
// 18+. We use @roamhq/wrtc — a community-maintained fork with the same
// exports — and dynamically import it so older installs still boot.
let RTCPeerConnection, RTCSessionDescription, RTCAudioSource, RTCAudioSink;
try {
  const wrtc = await import('@roamhq/wrtc');
  // The fork exposes the API as default for ESM and as named on CJS;
  // grab whichever shape we got.
  const m = wrtc.default || wrtc;
  RTCPeerConnection      = m.RTCPeerConnection;
  RTCSessionDescription  = m.RTCSessionDescription;
  const nm = m.nonstandard || {};
  RTCAudioSource = nm.RTCAudioSource;
  RTCAudioSink   = nm.RTCAudioSink;
  if (!RTCPeerConnection || !RTCAudioSource || !RTCAudioSink) {
    console.warn('[WABA-BRIDGE] @roamhq/wrtc loaded but expected exports missing — AI voice pickup disabled.');
    RTCPeerConnection = null;
  } else {
    console.log('[WABA-BRIDGE] @roamhq/wrtc loaded — AI voice pickup ready.');
  }
} catch (e) {
  console.warn('[WABA-BRIDGE] @roamhq/wrtc not installed (' + (e?.code || e?.message) + ') — AI voice pickup disabled. Run `npm install` in node/.');
}

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RECORDING_DIR = path.join(__dirname, '../../public/uploads/call-recordings');
try { fs.mkdirSync(RECORDING_DIR, { recursive: true }); } catch {}

const ICE_SERVERS = [
  { urls: 'stun:stun.l.google.com:19302' },
  { urls: 'stun:stun.relay.metered.ca:80' },
];

// Module-scope session table keyed by meta_call_id. Each session is a
// self-contained per-call orchestration object.
const sessions = new Map();

/**
 * Open (or re-attach to) a session for an incoming WABA call.
 * Idempotent — duplicate webhook delivery won't create two bridges.
 */
export function openSession(app, args) {
  if (sessions.has(args.metaCallId)) return sessions.get(args.metaCallId);

  if (!RTCPeerConnection) {
    console.warn(`[WABA-BRIDGE] cannot open — wrtc missing. call=${args.metaCallId}`);
    return null;
  }

  const session = {
    ...args,
    startedAt: Date.now(),
    closed: false,
    appDomain: app.locals.appDomainName,
    // Every credential flows through from Laravel per session —
    // sourced from system_settings + wa_provider_configs.credentials_json.
    // No env fallback so a partial admin setup fails loud instead of
    // accidentally using stale/dev keys.
    nodeToken:     args.nodeToken     || '',
    metaToken:     args.metaToken     || '',
    graphVersion:  args.graphVersion  || 'v23.0',
    phoneNumberId: args.phoneNumberId || '',

    pc: null,
    audioSource: null,            // outbound (AI → caller)
    audioSink: null,              // inbound  (caller → AI)
    sttSocket: null,
    ttsSocket: null,
    assistantConfig: null,

    // Recording — write raw 48k mono PCM to disk; Laravel converts to
    // wav/mp3 on first playback request.
    recAgent: null,
    recUser: null,

    // Transcript buffer for the live conversation
    transcript: [],
    pendingUserTurn: '',
    lastUserTurnAt: 0,
  };
  sessions.set(args.metaCallId, session);

  console.log(`[WABA-BRIDGE] opening call=${args.metaCallId} assistant=${args.assistantId} caller=${args.callerPhone}`);

  start(session).catch(e => {
    console.error(`[WABA-BRIDGE] start failed: ${e?.message}`);
    console.error(e?.stack);
    // Report the crash to Laravel so wa_calls.status flips to 'failed'
    // instead of staying stuck on 'ringing'/'connecting'. Without this
    // the operator sees a phantom active call in /call-logs.
    reportBridgeError(session, e?.message || 'start_failed').catch(() => {});
    closeSession(app, args.metaCallId);
  });

  return session;
}

/**
 * Tell Laravel that the bridge died so the call row doesn't stay
 * stuck. Best-effort — if Laravel is also down we just log and exit.
 */
async function reportBridgeError(s, reason) {
  try {
    await axios.post(
      `${s.appDomain}/api/waba-call/bridge-error`,
      { wa_call_id: s.waCallId, meta_call_id: s.metaCallId, reason: String(reason).slice(0, 500) },
      { timeout: 5000, headers: { 'X-Node-Token': s.nodeToken } },
    );
  } catch (e) {
    console.warn(`[WABA-BRIDGE] bridge-error report failed: ${e?.message}`);
  }
}

async function start(s) {
  // 1. Pull the assistant config + workspace-scoped voice keys from
  //    Laravel. Keys travel through AiKeyResolver (workspace BYOK →
  //    admin → env) so Node doesn't need them in its own env on
  //    multi-tenant installs.
  const [cfgRes, keysRes] = await Promise.all([
    axios.get(
      // workspace_id is REQUIRED by the handler (it workspace-scopes the
      // lookup) — without it Laravel returns HTTP 400 and this un-caught
      // request rejects the whole Promise.all, tearing the call down before
      // the AI can accept. s.workspaceId is set from the /answer payload.
      `${s.appDomain}/api/waba-call/assistant/${s.assistantId}?workspace_id=${s.workspaceId}`,
      { timeout: 8000, headers: { 'X-Node-Token': s.nodeToken } },
    ),
    axios.get(
      `${s.appDomain}/api/waba-call/voice-keys?workspace_id=${s.workspaceId}`,
      { timeout: 8000, headers: { 'X-Node-Token': s.nodeToken } },
    ).catch(e => ({ data: { ok: false, error: e?.message } })),
  ]);
  if (!cfgRes.data?.ok) {
    console.warn(`[WABA-BRIDGE] assistant fetch failed call=${s.metaCallId}`);
    await metaAction(s, 'reject');
    await reportBridgeError(s, 'assistant_fetch_failed');
    closeSession(null, s.metaCallId);
    return;
  }
  s.assistantConfig = cfgRes.data.assistant;
  // Voice keys resolved server-side via AiKeyResolver (workspace BYOK
  // → admin api_keys row). No env fallback — admin configures keys at
  // /admin/api-keys.
  s.deepgramKey   = (keysRes.data?.deepgram   || '').toString();
  s.elevenlabsKey = (keysRes.data?.elevenlabs || '').toString();

  // Fail-fast guard. Without either key the AI bridge would accept the
  // call and then sit silent — the caller dials, hears the click, and
  // then nothing. Worse than declining. Reject up-front so the AI
  // voicemail fallback (AiFallback::trigger via the terminating timer)
  // can handle the call by sending a voice-note "sorry we missed you"
  // over chat instead.
  if (!s.deepgramKey || !s.elevenlabsKey) {
    const missing = [];
    if (!s.deepgramKey) missing.push('Deepgram');
    if (!s.elevenlabsKey) missing.push('ElevenLabs');
    console.warn(`[WABA-BRIDGE] missing ${missing.join(' + ')} key for ws=${s.workspaceId} — rejecting call so voicemail fallback fires. Configure at /admin/api-keys.`);
    await metaAction(s, 'reject');
    await reportBridgeError(s, `missing_voice_keys:${missing.join(',')}`);
    closeSession(null, s.metaCallId);
    return;
  }
  console.log(`[WABA-BRIDGE] hydrated "${s.assistantConfig.name}" provider=${s.assistantConfig.ai_provider} model=${s.assistantConfig.ai_model}`);

  // 2. WebRTC peer
  s.pc = new RTCPeerConnection({ iceServers: ICE_SERVERS });

  // 3. Outbound audio source — the AI's TTS frames go here.
  s.audioSource = new RTCAudioSource();
  const aiTrack = s.audioSource.createTrack();
  s.pc.addTrack(aiTrack);

  // 4. When the caller's track arrives, plug an AudioSink to capture
  //    their PCM frames for STT + recording. record_user is gated on
  //    the workspace plan (access_call_recording) AND the assistant's
  //    per-side toggle — see FlowNodeActionsController::wabaCallAssistant.
  s.pc.ontrack = (event) => {
    if (event.track.kind !== 'audio') return;
    console.log(`[WABA-BRIDGE] caller audio track received call=${s.metaCallId}`);
    s.audioSink = new RTCAudioSink(event.track);
    if (s.assistantConfig.record_user) {
      s.recUser = fs.createWriteStream(path.join(RECORDING_DIR, `${s.metaCallId}_user.pcm`));
    }
    s.audioSink.ondata = (frame) => {
      // frame.samples is Int16Array @ frame.sampleRate Hz, mono.
      // Wrap as Buffer and tee to: STT + recording.
      try {
        const buf = Buffer.from(frame.samples.buffer, frame.samples.byteOffset, frame.samples.byteLength);
        s.recUser?.write(buf);
        if (s.sttSocket && s.sttSocket.readyState === WebSocket.OPEN) {
          s.sttSocket.send(buf);
        }
      } catch (e) {
        // Single dropped frame is fine — never crash the call.
      }
    };
  };

  // 5. Set remote description, mint answer, wait for ICE gathering to
  //    complete (so candidates are baked into the answer SDP — Meta
  //    doesn't support trickle ICE), then send pre_accept → accept.
  //    Per Meta spec the SDP in pre_accept and accept MUST match
  //    byte-for-byte or the call is rejected.
  await s.pc.setRemoteDescription(new RTCSessionDescription({ type: 'offer', sdp: s.sdpOffer }));
  const answer = await s.pc.createAnswer();
  await s.pc.setLocalDescription(answer);

  // Wait for ICE gathering — non-trickle. Hard 4s cap so a stalled STUN
  // server doesn't hang the call past Meta's 30-60s accept window.
  await waitForIceGatheringComplete(s.pc, 4000);

  // pc.localDescription has the full SDP including candidates after
  // gathering. Patch a=setup:actpass → a=setup:active (Meta sends
  // actpass, so we pick active).
  const localSdp = s.pc.localDescription?.sdp || answer.sdp;
  const finalSdp = localSdp.replace('a=setup:actpass', 'a=setup:active');
  s.answerSdp = finalSdp;  // snapshot so pre_accept + accept use the same bytes

  const preOk = await metaAction(s, 'pre_accept', finalSdp);
  if (!preOk) {
    console.warn(`[WABA-BRIDGE] pre_accept failed — aborting call=${s.metaCallId}`);
    closeSession(null, s.metaCallId);
    return;
  }
  // Meta requires ~1s gap between pre_accept and accept (per spec doc).
  await new Promise(r => setTimeout(r, 1000));
  const acceptOk = await metaAction(s, 'accept', finalSdp);
  if (!acceptOk) {
    console.warn(`[WABA-BRIDGE] accept failed — aborting call=${s.metaCallId}`);
    closeSession(null, s.metaCallId);
    return;
  }
  console.log(`[WABA-BRIDGE] call accepted, waiting for peer connection call=${s.metaCallId}`);

  // Tell Laravel the AI bridge has claimed the call so the AI-fallback
  // timer (scheduled in WaCallingWebhookController::handleConnect)
  // doesn't fire voicemail on top of a live AI conversation. The
  // /api/waba-call/bridge-accepted endpoint flips wa_calls.status from
  // 'ringing' → 'active' + handler_type='ai_agent' atomically.
  try {
    await axios.post(
      `${s.appDomain}/api/waba-call/bridge-accepted`,
      { wa_call_id: s.waCallId, meta_call_id: s.metaCallId, assistant_id: s.assistantId },
      { timeout: 5000, headers: { 'X-Node-Token': s.nodeToken } },
    );
  } catch (e) {
    console.warn(`[WABA-BRIDGE] bridge-accepted callback failed: ${e?.message}`);
  }

  // 5a. Wait for the WebRTC peer connection to actually come up before
  //     we push TTS. Per Meta's docs: "make sure to flow the media only
  //     after you receive 200 OK for your accept call. If the media
  //     flows too early, consumers will miss hearing the first few
  //     words." Hard 6s cap so we don't sit silent forever on bad
  //     networks — at that point we speak anyway and hope the path
  //     comes up mid-utterance.
  await waitForPeerConnected(s.pc, 6000);
  console.log(`[WABA-BRIDGE] peer connected call=${s.metaCallId} state=${s.pc.connectionState}`);

  // Resolve the workspace's active CALL FLOW (if the merchant built one).
  if (!s.assistantConfig.call_flow) {
    try {
      const wsId = s.workspaceId || s.assistantConfig?.workspace_id || 0;
      if (wsId) {
        const cfRes = await axios.post(`${s.appDomain}/api/call-flow/active`,
          { workspace_id: wsId },
          { timeout: 6000, headers: { 'X-Node-Token': process.env.NODE_WEBHOOK_TOKEN || '' } });
        if (cfRes.data?.flow) s.assistantConfig.call_flow = cfRes.data.flow;
      }
    } catch (e) { console.warn(`[WABA-BRIDGE] call-flow fetch failed: ${e?.message}`); }
  }

  // 6. Speak the greeting immediately so the caller doesn't sit in silence.
  //    If a CALL FLOW is bound to this number/assistant, the flow runs
  //    instead (it speaks its own Answer/Say nodes up to the first Listen).
  if (s.assistantConfig.call_flow && attachCallFlow(s, s.assistantConfig.call_flow)) {
    console.log(`[WABA-BRIDGE] call flow attached call=${s.metaCallId}`);
    await runFromStart(s, callFlowDeps(s));
  } else {
    const greetings = (s.assistantConfig.meta?.greeting_variations || [])
      .filter(g => (g || '').trim());
    const greet = greetings.length
      ? greetings[Math.floor(Math.random() * greetings.length)]
      : (s.assistantConfig.greeting_text || 'Hi, how can I help you today?');
    await speak(s, greet, 'agent');
  }

  // 7. Start STT streaming so subsequent caller speech is transcribed.
  openStt(s);
}

/**
 * Open the streaming STT connection (Deepgram realtime via WebSocket).
 * Falls back to no-STT (AI just keeps greeting on a loop) if the key
 * isn't available — bridge stays alive but the AI can't hear.
 */
function openStt(s) {
  const apiKey = s.deepgramKey || '';
  if (!apiKey) {
    console.warn(`[WABA-BRIDGE] Deepgram key missing — STT disabled call=${s.metaCallId} (admin configures at /admin/api-keys).`);
    return;
  }

  // Deepgram realtime — Linear16 PCM @ 48 kHz mono. wrtc gives us that
  // natively. Endpointing 600 ms = "if user pauses 600ms, finalise turn."
  const lang = (s.assistantConfig.meta?.languages || ['en'])[0] || 'en';
  const url = `wss://api.deepgram.com/v1/listen?encoding=linear16&sample_rate=48000&channels=1&interim_results=false&punctuate=true&endpointing=600&language=${encodeURIComponent(lang)}&model=nova-2`;
  s.sttSocket = new WebSocket(url, { headers: { Authorization: `Token ${apiKey}` } });

  s.sttSocket.on('open', () => console.log(`[WABA-BRIDGE] STT connected call=${s.metaCallId}`));
  s.sttSocket.on('message', async (data) => {
    try {
      const msg = JSON.parse(data.toString());
      const alt = msg.channel?.alternatives?.[0];
      if (!alt) return;
      const text = (alt.transcript || '').trim();
      if (!text || !msg.is_final) return;

      console.log(`[WABA-BRIDGE] user said: "${text}"`);
      recordTurn(s, 'user', text);

      // Call flow drives the turn when one is active (it handles its own
      // hang-up / goodbye via cf_hangup + endOnGoodbye).
      if (hasCallFlow(s)) {
        await handleTurn(s, text, callFlowDeps(s));
        return;
      }

      // Check exit keywords — short-circuit and hang up.
      const exit = (s.assistantConfig.exit_keywords || []).map(k => k.toLowerCase());
      if (exit.some(k => text.toLowerCase().includes(k))) {
        await speak(s, s.assistantConfig.last_greeting || 'Thank you for calling. Goodbye!', 'agent');
        setTimeout(() => closeSession(null, s.metaCallId), 1500);
        return;
      }

      // Generate AI reply (Laravel handles provider + admin keys + tools).
      const reply = await generateReply(s, text);
      if (reply) await speak(s, reply, 'agent');
    } catch (e) {
      console.warn(`[WABA-BRIDGE] STT parse failed: ${e?.message}`);
    }
  });
  s.sttSocket.on('error', e => console.warn(`[WABA-BRIDGE] STT socket error: ${e?.message}`));
  s.sttSocket.on('close', () => console.log(`[WABA-BRIDGE] STT closed call=${s.metaCallId}`));
}

/**
 * Primitives the call-flow walker (callFlowRuntime.js) uses to drive the
 * call. All voice goes through the existing speak()/TTS path; AI + search
 * go through Laravel so keys stay server-side.
 */
function callFlowDeps(s) {
  const token = process.env.NODE_WEBHOOK_TOKEN || '';
  const wsId  = s.workspaceId || s.assistantConfig?.workspace_id || 0;
  return {
    speak: (text) => speak(s, text, 'agent'),
    aiReply: async (node) => {
      try {
        const history = (s.transcript || []).slice(-12)
          .map(t => (t.role === 'user' ? 'Customer' : 'Agent') + ': ' + t.text).join('\n');
        const userPrompt = (history ? history + '\n' : '')
          + 'Customer: ' + (s.callFlow?.vars?.__lastTurn || '') + '\nAgent:';
        const res = await axios.post(`${s.appDomain}/api/flow-node/ai-call`, {
          workspace_id: wsId,
          model: node.model || 'gpt-4o-mini',
          system_prompt: node.prompt || 'You are a helpful phone assistant. Keep replies short and natural.',
          user_prompt: userPrompt,
          ...(node.assistantId ? { assistant_id: node.assistantId } : {}),
          max_tokens: 200, temperature: 0.6,
        }, { timeout: 12000, headers: { 'X-Node-Token': token } });
        return String(res.data?.reply || '');
      } catch (e) { console.warn(`[CALLFLOW] ai failed: ${e?.message}`); return ''; }
    },
    webSearch: async (query) => {
      try {
        const res = await axios.post(`${s.appDomain}/api/flow-node/web-search`,
          { query, max_results: 5 },
          { timeout: 10000, headers: { 'X-Node-Token': token } });
        return String(res.data?.text || '');
      } catch (e) { console.warn(`[CALLFLOW] search failed: ${e?.message}`); return ''; }
    },
    endCall: () => { setTimeout(() => closeSession(null, s.metaCallId), 1200); },
    // Meta call transfer isn't wired yet → return false so the flow takes
    // the "if no answer" path instead of dropping the caller.
    transfer: async () => false,
    callerSaidGoodbye: () => {
      const exit = (s.assistantConfig.exit_keywords || []).map(k => k.toLowerCase());
      const said = String(s.callFlow?.vars?.__lastTurn || '').toLowerCase();
      return exit.some(k => said.includes(k));
    },
  };
}

/**
 * Build the LLM prompt from the assistant config + transcript history
 * and call Laravel's `/api/flow-node/ai-call` (already wired with admin
 * keys via AiKeyResolver). Returns the reply text.
 */
async function generateReply(s, userText) {
  try {
    const history = s.transcript
      .slice(-12)
      .map(t => (t.role === 'user' ? 'Customer' : 'Agent') + ': ' + t.text)
      .join('\n');
    const userPrompt = (history ? history + '\n' : '') + 'Customer: ' + userText + '\nAgent:';

    const r = await axios.post(
      `${s.appDomain}/api/flow-node/ai-call`,
      {
        workspace_id:  s.workspaceId,
        model:         s.assistantConfig.ai_model,
        system_prompt: s.assistantConfig.ai_system_prompt
          || 'You are a helpful voice assistant on a phone call. Reply with one short sentence at a time so the caller can interject.',
        user_prompt:   userPrompt,
        max_tokens:    180,
        temperature:   0.7,
      },
      { timeout: 12000, headers: { 'X-Node-Token': s.nodeToken } },
    );
    return String(r.data?.reply || '').trim();
  } catch (e) {
    console.warn(`[WABA-BRIDGE] LLM call failed: ${e?.response?.data?.message || e?.message}`);
    return '';
  }
}

/**
 * Convert text → audio frames → push into the outbound MediaStreamTrack.
 *
 * Uses ElevenLabs TTS streaming WebSocket so the first audio chunk
 * comes back in ~200 ms. Each chunk is base64 PCM @ 16 kHz mono; we
 * upsample 3x to 48 kHz so wrtc accepts it (mono Opus @ 48 kHz is the
 * implicit codec after WebRTC's audio path).
 */
async function speak(s, text, role) {
  if (!text || !s.audioSource) return;
  recordTurn(s, role, text);
  console.log(`[WABA-BRIDGE] agent says: "${text}"`);

  const apiKey = s.elevenlabsKey || '';
  const voiceId = s.assistantConfig.voice_id || '21m00Tcm4TlvDq8ikWAM'; // ElevenLabs default "Rachel"
  if (!apiKey) {
    console.warn(`[WABA-BRIDGE] ElevenLabs key missing — TTS disabled. Silent reply. (admin configures at /admin/api-keys)`);
    return;
  }

  // Open recording stream if not already (lazy — only when AI talks).
  // record_agent gates this — assistant-side toggle ANDed with the
  // plan's access_call_recording feature.
  if (s.assistantConfig.record_agent && !s.recAgent) {
    s.recAgent = fs.createWriteStream(path.join(RECORDING_DIR, `${s.metaCallId}_agent.pcm`));
  }

  // ElevenLabs streaming WS: text in (with optional flush), PCM out.
  // We request pcm_16000 (16 kHz mono LE int16) and upsample to 48 kHz.
  const wsUrl = `wss://api.elevenlabs.io/v1/text-to-speech/${voiceId}/stream-input?model_id=eleven_flash_v2_5&output_format=pcm_16000`;
  const ws = new WebSocket(wsUrl, { headers: { 'xi-api-key': apiKey } });

  await new Promise((resolve, reject) => {
    const timer = setTimeout(() => reject(new Error('TTS ws open timeout')), 8000);
    ws.once('open', () => { clearTimeout(timer); resolve(); });
    ws.once('error', e => { clearTimeout(timer); reject(e); });
  });

  // Initial config + text + flush.
  ws.send(JSON.stringify({ text: ' ', voice_settings: { stability: 0.5, similarity_boost: 0.8 } }));
  ws.send(JSON.stringify({ text }));
  ws.send(JSON.stringify({ text: '', flush: true }));

  // Receive audio chunks, decode base64 → upsample → push to wrtc.
  await new Promise((resolve) => {
    ws.on('message', (data) => {
      try {
        const msg = JSON.parse(data.toString());
        if (msg.audio) {
          const pcm16k = Buffer.from(msg.audio, 'base64'); // mono 16-bit @ 16 kHz
          const pcm48k = upsample3x(pcm16k);
          s.recAgent?.write(pcm48k);
          pushFramesToWrtc(s, pcm48k);
        }
        if (msg.isFinal) {
          ws.close();
          resolve();
        }
      } catch (e) {
        // ignore individual parse errors; the final chunk will resolve us
      }
    });
    ws.on('close', resolve);
    ws.on('error', e => { console.warn(`[WABA-BRIDGE] TTS ws error: ${e?.message}`); resolve(); });
  });
}

/**
 * Push PCM 48 kHz mono Int16 buffer to wrtc's RTCAudioSource in
 * 10 ms chunks (480 samples). wrtc requires 10 ms exactly.
 */
function pushFramesToWrtc(s, pcm48k) {
  const SAMPLES_PER_FRAME = 480; // 10 ms @ 48 kHz
  const BYTES_PER_FRAME   = SAMPLES_PER_FRAME * 2;
  for (let off = 0; off + BYTES_PER_FRAME <= pcm48k.length; off += BYTES_PER_FRAME) {
    const samples = new Int16Array(pcm48k.buffer, pcm48k.byteOffset + off, SAMPLES_PER_FRAME);
    try {
      s.audioSource.onData({
        samples,
        sampleRate: 48000,
        channelCount: 1,
        bitsPerSample: 16,
        numberOfFrames: SAMPLES_PER_FRAME,
      });
    } catch (e) {
      // wrtc throws if the source is detached; closeSession handles this.
      return;
    }
  }
}

/**
 * Crude 3x linear interpolation upsample from 16 kHz to 48 kHz.
 * Good enough for telephony quality; no aliasing artefacts that matter
 * once Opus re-encodes for the WebRTC peer.
 */
function upsample3x(buf16k) {
  const inSamples = new Int16Array(buf16k.buffer, buf16k.byteOffset, buf16k.length / 2);
  const out = new Int16Array(inSamples.length * 3);
  for (let i = 0; i < inSamples.length - 1; i++) {
    const a = inSamples[i];
    const b = inSamples[i + 1];
    out[i * 3]     = a;
    out[i * 3 + 1] = a + Math.round((b - a) / 3);
    out[i * 3 + 2] = a + Math.round(2 * (b - a) / 3);
  }
  out[out.length - 3] = out[out.length - 4];
  out[out.length - 2] = out[out.length - 4];
  out[out.length - 1] = out[out.length - 4];
  return Buffer.from(out.buffer);
}

/**
 * Record a transcript turn locally + send to Laravel so /call-logs
 * shows the conversation as it unfolds.
 */
function recordTurn(s, role, text) {
  if (!text) return;
  const tMs = Date.now() - s.startedAt;
  s.transcript.push({ role, text, t: tMs });
  axios.post(
    `${s.appDomain}/api/waba-call/transcript-turn`,
    { wa_call_id: s.waCallId, role, text, t_ms: tMs },
    { timeout: 5000, headers: { 'X-Node-Token': s.nodeToken } },
  ).catch(e => console.warn(`[WABA-BRIDGE] turn record failed: ${e?.message}`));
}

/**
 * Call Meta's Graph API to pre_accept / accept / reject / terminate.
 */
async function metaAction(s, action, sdp = null) {
  if (!s.metaToken || !s.phoneNumberId) {
    console.warn(`[WABA-BRIDGE] no Meta token / phone_number_id for call=${s.metaCallId} — cannot ${action}. Workspace's WaProviderConfig is missing credentials.`);
    return false;
  }
  const url = `https://graph.facebook.com/${s.graphVersion}/${s.phoneNumberId}/calls`;
  const body = {
    messaging_product: 'whatsapp',
    call_id: s.metaCallId,
    action,
  };
  if (sdp) body.session = { sdp_type: 'answer', sdp };
  try {
    const r = await axios.post(url, body, {
      timeout: 8000,
      headers: { Authorization: `Bearer ${s.metaToken}`, 'Content-Type': 'application/json' },
    });
    if (r.data?.success === true) {
      console.log(`[WABA-BRIDGE] meta ${action} ok call=${s.metaCallId}`);
      return true;
    }
    console.warn(`[WABA-BRIDGE] meta ${action} response not success`, r.data);
    return false;
  } catch (e) {
    console.error(`[WABA-BRIDGE] meta ${action} failed: ${e?.response?.data ? JSON.stringify(e.response.data) : e?.message}`);
    return false;
  }
}

/**
 * Close session — flush sockets, close recordings, post terminate to
 * Meta. Idempotent so concurrent close paths (caller hangup vs our
 * exit-keyword detection) don't double-fire.
 */
export function closeSession(app, metaCallId) {
  const s = sessions.get(metaCallId);
  if (!s || s.closed) return;
  s.closed = true;

  try { s.sttSocket?.close?.(); } catch {}
  try { s.ttsSocket?.close?.(); } catch {}
  try { s.audioSink?.stop?.(); } catch {}
  try { s.pc?.close?.(); } catch {}
  try { s.recUser?.end?.(); } catch {}
  try { s.recAgent?.end?.(); } catch {}

  // Best-effort terminate so Meta knows we hung up. Skip when WS-driven
  // close (Meta sent us terminate first).
  metaAction(s, 'terminate').catch(() => {});

  console.log(`[WABA-BRIDGE] closed call=${metaCallId} duration=${Math.floor((Date.now() - s.startedAt) / 1000)}s turns=${s.transcript.length}`);
  sessions.delete(metaCallId);
}

export function activeSessions() {
  return Array.from(sessions.values());
}

/**
 * Wait until ICE gathering finishes (state === 'complete') OR until the
 * timeout fires. Meta's calling API doesn't support trickle ICE, so the
 * answer SDP must carry all candidates before we POST it.
 */
function waitForIceGatheringComplete(pc, timeoutMs) {
  return new Promise((resolve) => {
    if (!pc) return resolve();
    if (pc.iceGatheringState === 'complete') return resolve();
    let done = false;
    const finish = () => { if (done) return; done = true; pc.removeEventListener?.('icegatheringstatechange', onChange); resolve(); };
    const onChange = () => { if (pc.iceGatheringState === 'complete') finish(); };
    pc.addEventListener?.('icegatheringstatechange', onChange);
    setTimeout(finish, Math.max(500, timeoutMs));
  });
}

/**
 * Wait until the RTCPeerConnection reaches `connected` (or `completed`).
 * Returns even on failure so the caller can still try to push audio —
 * occasionally Meta accepts the call before our local state flips.
 */
function waitForPeerConnected(pc, timeoutMs) {
  return new Promise((resolve) => {
    if (!pc) return resolve();
    const isUp = () => ['connected', 'completed'].includes(pc.connectionState)
      || ['connected', 'completed'].includes(pc.iceConnectionState);
    if (isUp()) return resolve();
    let done = false;
    const finish = () => { if (done) return; done = true; pc.removeEventListener?.('connectionstatechange', onChange); pc.removeEventListener?.('iceconnectionstatechange', onChange); resolve(); };
    const onChange = () => { if (isUp()) finish(); };
    pc.addEventListener?.('connectionstatechange', onChange);
    pc.addEventListener?.('iceconnectionstatechange', onChange);
    setTimeout(finish, Math.max(1000, timeoutMs));
  });
}
