<?php

namespace App\Services\Forms;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\WaForm;
use App\Models\WaFormSubmission;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Lands a form submission from Meta's WABA webhook + resumes the paused
 * flow node so the conversation continues with the answers in scope.
 *
 * Meta's payload for a form reply (`type='interactive'`,
 * `interactive.type='nfm_reply'`) contains:
 *   {
 *     name: 'flow',
 *     body: 'Sent',
 *     response_json: '{"flow_token":"abc","field_id":"value", ...}'
 *   }
 * The `flow_token` is what we attached when we sent the form (carries
 * the session key); the rest of the JSON is the customer's answers.
 */
class WaFormSubmissionService
{
    public function ingest(int $workspaceId, array $msg, array $value): void
    {
        $interactive = $msg['interactive'] ?? null;
        if (!$interactive || ($interactive['type'] ?? '') !== 'nfm_reply') return;
        $reply = $interactive['nfm_reply'] ?? [];

        $rawJson = (string) ($reply['response_json'] ?? '');
        $payload = json_decode($rawJson, true);
        if (!is_array($payload)) {
            Log::warning('[WAFORM-SUB] non-JSON response_json: ' . substr($rawJson, 0, 200));
            return;
        }

        $flowToken = (string) ($payload['flow_token'] ?? '');
        if ($flowToken === '') {
            Log::warning('[WAFORM-SUB] no flow_token in response — cannot route to paused flow');
            return;
        }

        // The flow_token we stamped on send is `form-<form_id>-<session_key>`.
        // Extract both pieces.
        if (!preg_match('/^form-(\d+)-(.+)$/', $flowToken, $m)) {
            Log::warning('[WAFORM-SUB] flow_token does not match form-X-sessionKey shape: ' . $flowToken);
            return;
        }
        $formId     = (int) $m[1];
        $sessionKey = (string) $m[2];

        $form = WaForm::find($formId);
        if (!$form || $form->workspace_id !== $workspaceId) {
            Log::warning('[WAFORM-SUB] form not in workspace', ['form_id' => $formId, 'ws' => $workspaceId]);
            return;
        }

        $callerPhone = (string) ($msg['from'] ?? '');
        // Contact.mobile is encrypted-at-rest (non-deterministic ciphertext)
        // so a LIKE filter never matches; hydrate the workspace's contact
        // set and compare decrypted digits in PHP. Bounded by workspace
        // so a foreign submission can't reach into another tenant.
        $contact = null;
        if ($callerPhone !== '') {
            $digits = preg_replace('/\D+/', '', $callerPhone);
            $contact = Contact::query()
                ->where('workspace_id', $workspaceId)
                ->get()
                ->first(function ($c) use ($digits) {
                    $stored = preg_replace('/\D+/', '', (string) ($c->country_code . $c->mobile));
                    return $stored !== '' && $stored === $digits;
                });
        }
        $conv = $callerPhone
            ? Conversation::query()
                ->where('workspace_id', $workspaceId)
                ->where(function ($q) use ($callerPhone) {
                    $jid = preg_replace('/\D+/', '', $callerPhone);
                    $q->where('raw_jid', $jid . '@s.whatsapp.net')
                      ->orWhere('raw_jid', $jid);
                })
                ->orderByDesc('id')->first()
            : null;

        // Strip the flow_token from the answers — keep only the actual
        // field values so downstream nodes see {{field_id}}.
        $answers = $payload;
        unset($answers['flow_token']);

        $submission = WaFormSubmission::create([
            'form_id'         => $form->id,
            'workspace_id'    => $workspaceId,
            'contact_id'      => $contact?->id,
            'conversation_id' => $conv?->id,
            'flow_token'      => $flowToken,
            'caller_phone'    => $callerPhone,
            'answers_json'    => $answers,
            'meta_payload'    => $msg,
            'submitted_at'    => now(),
        ]);

        $form->increment('submission_count');

        // Resume the paused flow on Node — same shape as the commerce
        // resume-by-phone hook. Node sees the answers under
        // `formAnswers` and stamps them into session.userVariables
        // prefixed with `form_` so downstream nodes can reference
        // {{form_<field_id>}}.
        $this->resumeNodeFlow($sessionKey, $form->id, $answers);
    }

    private function resumeNodeFlow(string $sessionKey, int $formId, array $answers): void
    {
        $base = (string) (\App\Models\SystemSetting::get('baileys_server_url', '') ?: env('SERVER_URL', ''));
        if ($base === '') {
            Log::warning('[WAFORM-SUB] no Node URL — flow cannot resume');
            return;
        }
        try {
            Http::withHeaders(['X-Node-Token' => node_token()])
                ->timeout(8)
                ->acceptJson()
                ->post(rtrim($base, '/') . '/api/flow/resume-form/' . rawurlencode($sessionKey), [
                    'form_id'      => $formId,
                    'form_answers' => $answers,
                ]);
        } catch (\Throwable $e) {
            Log::warning('[WAFORM-SUB] resume Node failed: ' . $e->getMessage());
        }
    }
}
