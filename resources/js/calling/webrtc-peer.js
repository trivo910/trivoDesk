// WaDesk WABA WebRTC peer
//
// One JS class wraps everything the operator's browser needs to do
// for a WhatsApp call:
//
//   - Generate / accept SDP via RTCPeerConnection
//   - Capture mic via getUserMedia
//   - Play the remote audio track via a hidden <audio> element
//   - Talk to our action endpoints (/wa-calling/calls/{id}/...)
//
// The signalling channel is plain HTTPS — every SDP/ICE swap rides
// over the same JSON endpoints that the rest of the team-inbox uses.
// No socket, no Reverb, no queue. ICE candidates are gathered
// non-trickle (one shot) so we don't need a bi-directional stream.
//
// Two entry points:
//
//   peer.dial({ configId, to, contactId?, conversationId? })
//   peer.answer({ callId, sdpOffer })
//
// Both return a Promise that resolves when audio is bridged. After
// that, peer.hangup() terminates and tears down.
//
// Usage:
//   import { WaCallPeer } from './calling/webrtc-peer.js';
//   const peer = new WaCallPeer({ onState: s => updateUi(s) });
//   await peer.answer({ callId: 42, sdpOffer: '...' });

const ICE_SERVERS = [
    { urls: 'stun:stun.l.google.com:19302' },
    // Add TURN here for operators behind symmetric NAT. The relay
    // requirement is rare in practice — STUN works on >90% of
    // residential / corporate NATs.
];

function csrfToken() {
    return document.querySelector('meta[name=csrf-token]')?.content || '';
}

async function api(path, opts = {}) {
    const res = await fetch(path, {
        ...opts,
        credentials: 'same-origin',
        headers: {
            ...(opts.headers || {}),
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken(),
        },
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data?.error || data?.message || `HTTP ${res.status}`);
    return data;
}

export class WaCallPeer {
    constructor({ onState = null, onError = null } = {}) {
        this.onState = onState || (() => {});
        this.onError = onError || ((e) => console.error('[wa-call]', e));
        this.pc = null;
        this.localStream = null;
        this.remoteAudio = null;
        this.callId = null;
        this.state = 'idle'; // idle | connecting | active | ended
    }

    /**
     * Operator answers an inbound call.
     * Server gave us the SDP offer via the pending-calls poll.
     */
    async answer({ callId, sdpOffer }) {
        if (!callId) throw new Error('answer() needs callId');
        if (!sdpOffer || sdpOffer.length < 50) throw new Error('Invalid SDP offer — missing v=0 or media line');
        this.callId = callId;
        this._setState('connecting');

        await this._setupPeer();
        await this.pc.setRemoteDescription({ type: 'offer', sdp: sdpOffer });
        // Attach mic BEFORE createAnswer so the answer SDP includes the
        // local audio m-line — without it, Meta sees a recvonly session
        // and the caller hears silence even though we hear them.
        await this._attachLocalAudio();
        const answer = await this.pc.createAnswer();
        await this.pc.setLocalDescription(answer);
        const sdp = await this._waitForIceComplete();
        if (!sdp || sdp.length < 50) throw new Error('Local SDP empty — ICE gathering failed');

        await api(`/wa-calling/calls/${callId}/accept`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ sdp_answer: sdp }),
        });

        this._setState('active');
    }

    /**
     * Operator dials out. Permission must already be granted (server
     * enforces; UI grays the button until /wa-calling/status confirms).
     */
    async dial({ configId, to, contactId = null, conversationId = null }) {
        if (!configId || !to) throw new Error('dial() needs configId and to');
        this._setState('connecting');

        await this._setupPeer();
        await this._attachLocalAudio();
        const offer = await this.pc.createOffer({ offerToReceiveAudio: true });
        await this.pc.setLocalDescription(offer);
        const sdp = await this._waitForIceComplete();

        const r = await api('/wa-calling/calls/dial', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                config_id: configId,
                to,
                sdp_offer: sdp,
                contact_id: contactId,
                conversation_id: conversationId,
            }),
        });
        this.callId = r.call_id;
        // Meta starts ringing the customer; their phone answers → Meta
        // sends a call.connect webhook to our server → server stores
        // the SDP answer on the wa_calls row. We don't poll for that
        // here in Phase 2 — the dispatch from server-side will hand
        // us back through the pending-calls path. (Phase 3 will add a
        // dedicated dial-progress poll.)
        this._setState('active');
    }

    async hangup() {
        try {
            if (this.callId && (this.state === 'active' || this.state === 'connecting')) {
                await api(`/wa-calling/calls/${this.callId}/terminate`, { method: 'POST' });
            }
        } catch (e) {
            // Don't block tear-down on a missed terminate; Meta's
            // call.terminate webhook will reconcile.
            console.warn('[wa-call] terminate POST failed', e);
        }
        this._teardown();
    }

    /* ───────────────────────── internals ───────────────────────── */

    async _setupPeer() {
        this.pc = new RTCPeerConnection({ iceServers: ICE_SERVERS });
        this.pc.ontrack = (ev) => {
            const stream = ev.streams?.[0];
            if (!stream) return;
            if (!this.remoteAudio) {
                this.remoteAudio = document.createElement('audio');
                this.remoteAudio.autoplay = true;
                this.remoteAudio.playsInline = true;
                this.remoteAudio.style.display = 'none';
                document.body.appendChild(this.remoteAudio);
            }
            this.remoteAudio.srcObject = stream;
        };
        this.pc.onconnectionstatechange = () => {
            const s = this.pc?.connectionState;
            if (s === 'failed' || s === 'disconnected' || s === 'closed') {
                this._setState('ended');
                this._teardown(false);
            }
        };
    }

    async _attachLocalAudio() {
        // Operators MUST grant mic access — without a sendrecv audio
        // track the SDP answer goes out as recvonly and Meta won't
        // bridge our outbound audio. We surface the failure with a
        // clear error so the operator can re-prompt the permission
        // instead of joining a one-way call.
        try {
            this.localStream = await navigator.mediaDevices.getUserMedia({
                audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true },
                video: false,
            });
            this.localStream.getTracks().forEach((t) => this.pc.addTrack(t, this.localStream));
        } catch (e) {
            const err = new Error('Microphone access blocked — the caller will not hear you. Grant mic permission in your browser and retry.');
            this.onError(err);
            // Re-throw so answer() / dial() abort instead of pushing a
            // one-way call live. Silent calls are worse than declined.
            throw err;
        }
    }

    /**
     * Non-trickle ICE: wait for all candidates to gather, then return
     * the complete SDP. Simpler than trickle + fits the one-shot
     * action-endpoint design.
     */
    async _waitForIceComplete() {
        if (this.pc.iceGatheringState === 'complete') {
            return this.pc.localDescription.sdp;
        }
        return new Promise((resolve) => {
            const onChange = () => {
                if (this.pc.iceGatheringState === 'complete') {
                    this.pc.removeEventListener('icegatheringstatechange', onChange);
                    resolve(this.pc.localDescription.sdp);
                }
            };
            this.pc.addEventListener('icegatheringstatechange', onChange);
            // Safety timer — some browsers stall ICE on networks
            // without external connectivity; cap at 3s and ship what
            // we have. Meta retries failed accept with NO_ANSWER so a
            // late-arriving candidate isn't fatal.
            setTimeout(() => resolve(this.pc.localDescription.sdp), 3000);
        });
    }

    _setState(s) {
        this.state = s;
        try { this.onState(s); } catch (_) {}
    }

    _teardown(setIdle = true) {
        try { this.pc?.getSenders().forEach((s) => s.track?.stop()); } catch (_) {}
        try { this.localStream?.getTracks().forEach((t) => t.stop()); } catch (_) {}
        try { this.pc?.close(); } catch (_) {}
        if (this.remoteAudio?.parentNode) this.remoteAudio.remove();
        this.pc = null;
        this.localStream = null;
        this.remoteAudio = null;
        this.callId = null;
        if (setIdle) this._setState('idle');
    }
}
