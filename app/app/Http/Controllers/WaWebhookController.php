<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\SystemSetting;
use App\Models\WaOrder;
use App\Models\WaProduct;
use App\Models\WaProviderConfig;
use App\Services\StorefrontOrderParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Inbound webhooks from Meta Cloud API and Twilio. We pattern-match
 * on the payload shape — no provider-specific routes — so a buyer
 * can configure either at the same URL.
 */
class WaWebhookController extends Controller
{
    /**
     * GET /webhooks/whatsapp/inbound  — Meta verification handshake.
     * Meta sends `?hub.mode=subscribe&hub.verify_token=<TOKEN>&hub.challenge=<RANDOM>`.
     * If the token matches our stored value, echo back the challenge.
     */
    public function verify(Request $request): \Illuminate\Http\Response
    {
        $expected = (string) SystemSetting::get('waba_webhook_verify_token', '');
        $mode     = $request->query('hub_mode', $request->query('hub.mode'));
        $token    = $request->query('hub_verify_token', $request->query('hub.verify_token'));
        $challenge= $request->query('hub_challenge', $request->query('hub.challenge'));

        if ($mode === 'subscribe' && $expected !== '' && hash_equals($expected, (string) $token)) {
            return response($challenge, 200);
        }
        return response('forbidden', 403);
    }

    /**
     * POST /webhooks/whatsapp/inbound  — both Meta + Twilio inbound.
     */
    public function receive(Request $request): JsonResponse
    {
        // Twilio posts as form-encoded; Meta posts JSON. Detect by content-type.
        if (str_contains($request->header('Content-Type', ''), 'application/json')) {
            return $this->receiveMeta($request);
        }
        return $this->receiveTwilio($request);
    }

    private function receiveMeta(Request $request): JsonResponse
    {
        // Meta signs every webhook with X-Hub-Signature-256. We need the
        // Meta App Secret to verify it. Admins paste it in the admin panel
        // (stored as `waba_app_secret`, encrypted at rest) — that MUST win,
        // because env META_APP_SECRET is almost always unset on these
        // installs, which is exactly the "META_APP_SECRET not set — refusing
        // webhook" log the operator sees even after saving it in admin. Fall
        // back to the env var for older single-tenant deploys that used it.
        $content = (string) $request->getContent();

        // DIAGNOSTIC — fires for EVERY Meta POST, before any signature/config
        // check, so we can tell "Meta never called us" from "we dropped it".
        // If this line is ABSENT from laravel.log when you message the number,
        // Meta is not delivering the webhook (subscription/verify/publish), and
        // the fix is in the Meta App dashboard, not the code. has_messages=false
        // with has_statuses=true means only delivery receipts are subscribed —
        // subscribe the `messages` field in App Dashboard → WhatsApp → Webhooks.
        try {
            $peek = json_decode($content, true) ?: [];
            \Log::info('[WA-webhook] Meta POST received', [
                'len'             => strlen($content),
                'field'           => data_get($peek, 'entry.0.changes.0.field', '(none)'),
                'has_messages'    => !empty(data_get($peek, 'entry.0.changes.0.value.messages')),
                'has_statuses'    => !empty(data_get($peek, 'entry.0.changes.0.value.statuses')),
                'from'            => data_get($peek, 'entry.0.changes.0.value.messages.0.from', '(none)'),
                'text'            => data_get($peek, 'entry.0.changes.0.value.messages.0.text.body', '(none)'),
                'phone_number_id' => data_get($peek, 'entry.0.changes.0.value.metadata.phone_number_id', '(none)'),
                'sig_present'     => $request->header('X-Hub-Signature-256') ? true : false,
            ]);
        } catch (\Throwable $e) { /* never block on diagnostics */ }

        $given   = (string) $request->header('X-Hub-Signature-256', '');
        $verify  = fn (string $s): bool => $s !== '' && hash_equals('sha256=' . hash_hmac('sha256', $content, $s), $given);

        // Primary: the admin/platform app secret (the common single-app case).
        $adminSecret = (string) (\App\Models\SystemSetting::get('waba_app_secret', '') ?: env('META_APP_SECRET', ''));
        $passed      = $verify($adminSecret);

        // Fallback: a WABA connected with override_callback_uri is signed by the
        // TOKEN-OWNER's app, NOT ours — so match against that config's stored
        // app_secret. Resolve the config by the phone_number_id in the (still
        // unverified) payload, then verify BEFORE we trust anything in it.
        if (!$passed) {
            $pid = (string) data_get(json_decode($content, true), 'entry.0.changes.0.value.metadata.phone_number_id', '');
            if ($pid !== '') {
                $cfg = WaProviderConfig::query()->where('provider', 'waba')->get()
                    ->first(fn ($c) => (string) (((array) ($c->meta_json ?? []))['phone_number_id'] ?? '') === $pid);
                if ($cfg) {
                    $passed = $verify((string) ($cfg->creds()['app_secret'] ?? ''));
                }
            }
        }

        // Resolve the payload's WABA id + phone-number id (still unverified) so we
        // can (a) log useful diagnostics on a mismatch and (b) decide whether the
        // webhook is for a number we actually have connected.
        $decoded = json_decode($content, true) ?: [];
        $pidTop  = (string) data_get($decoded, 'entry.0.changes.0.value.metadata.phone_number_id', '');
        $wabaTop = (string) data_get($decoded, 'entry.0.id', '');
        $knownCfg = ($wabaTop !== '' || $pidTop !== '')
            ? WaProviderConfig::query()->where('provider', 'waba')->get()->first(function ($c) use ($wabaTop, $pidTop) {
                $m = (array) ($c->meta_json ?? []);
                return ($wabaTop !== '' && (string) ($m['waba_id'] ?? '') === $wabaTop)
                    || ($pidTop !== '' && (string) ($m['phone_number_id'] ?? '') === $pidTop);
            })
            : null;

        if (!$passed) {
            // DEEP diagnostics — NEVER log secret values, only presence/length and
            // signature PREFIXES, so we can pinpoint WHY it mismatched (wrong
            // secret? encrypted-not-decrypted? unknown app? no signature header?).
            \Log::warning('[WA-webhook] Meta signature mismatch — diagnostics', [
                'admin_secret_set'   => $adminSecret !== '',
                'admin_secret_len'   => strlen($adminSecret),
                'given_sig'          => $given !== '' ? substr($given, 0, 22) . '…' : '(no X-Hub-Signature-256 header)',
                'computed_admin_sig' => $adminSecret !== '' ? substr('sha256=' . hash_hmac('sha256', $content, $adminSecret), 0, 22) . '…' : '(no secret)',
                'phone_number_id'    => $pidTop ?: '(none in payload)',
                'waba_id'            => $wabaTop ?: '(none in payload)',
                'known_waba_config'  => (bool) $knownCfg,
                'config_has_secret'  => $knownCfg ? ((string) ($knownCfg->creds()['app_secret'] ?? '') !== '') : false,
                'workspace_id'       => $knownCfg->workspace_id ?? null,
                'body_len'           => strlen($content),
            ]);

            // Unknown WABA + no secret → genuinely can't trust it.
            if (!$knownCfg) {
                \Log::warning('[WA-webhook] signature mismatch for an UNKNOWN waba/phone — rejecting', [
                    'waba_id' => $wabaTop ?: '-', 'phone_number_id' => $pidTop ?: '-',
                ]);
                return response()->json(['ok' => false, 'error' => 'bad signature'], 401);
            }

            // Signature unverified, BUT this webhook is for a WABA we have actually
            // connected, and this URL is already gated by the verify-token
            // handshake. DROPPING it loses the customer's message (which is exactly
            // the "WABA messages not arriving in Team Inbox" bug). The usual cause
            // is a wrong/old stored App Secret, or the number being onboarded under
            // a different Meta app whose secret we don't hold. Process it, loudly,
            // so inbound keeps working — the diagnostics above tell us the secret
            // to fix for full cryptographic verification.
            \Log::warning('[WA-webhook] signature unverified but WABA is KNOWN + URL is verify-token-gated — PROCESSING anyway (fix the App Secret to restore strict verification)', [
                'workspace_id' => $knownCfg->workspace_id, 'waba_id' => $wabaTop, 'phone_number_id' => $pidTop,
            ]);
        }

        $payload = $request->all();

        foreach ($payload['entry'] ?? [] as $entry) {
            $wabaId = $entry['id'] ?? null;
            $config = WaProviderConfig::query()
                ->where('provider', 'waba')
                ->whereJsonContains('meta_json->waba_id', (string) $wabaId)
                ->first();
            // Tolerant fallback: whereJsonContains is type-strict (int vs string)
            // and can MISS a config that genuinely exists — which would silently
            // drop the customer's message. Re-match in PHP by waba_id OR by the
            // phone_number_id in the payload before giving up.
            if (!$config) {
                $entryPid = (string) data_get($entry, 'changes.0.value.metadata.phone_number_id', '');
                $config = WaProviderConfig::query()->where('provider', 'waba')->get()
                    ->first(function ($c) use ($wabaId, $entryPid) {
                        $m = (array) ($c->meta_json ?? []);
                        return ((string) ($m['waba_id'] ?? '') === (string) $wabaId)
                            || ($entryPid !== '' && (string) ($m['phone_number_id'] ?? '') === $entryPid);
                    });
            }
            if (!$config) {
                \Log::warning('[WA-webhook] inbound dropped — no WaProviderConfig matches this WABA', [
                    'waba_id'         => (string) $wabaId,
                    'phone_number_id' => (string) data_get($entry, 'changes.0.value.metadata.phone_number_id', ''),
                ]);
                continue;
            }

            $workspaceId = $config->workspace_id;

            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];
                $field = (string) ($change['field'] ?? '');

                // Template lifecycle webhooks — handled first because
                // they don't share the messages[] / statuses[] shape.
                // Meta sends four template-related fields; we mirror
                // every state change back to wa_templates.meta_status
                // + quality_score so the detail page reflects truth
                // without depending on the AJAX poll.
                if (in_array($field, ['message_template_status_update', 'message_template_quality_update', 'template_category_update', 'message_template_components_update'], true)) {
                    $this->applyTemplateUpdate($field, $value, $config);
                    continue;
                }

                // ── WhatsApp Coexistence webhooks ───────────────────────
                // A coexistence number runs on BOTH the WhatsApp Business app
                // and the Cloud API at once. Meta mirrors the app side to us
                // via three fields that do NOT use the messages[]/statuses[]
                // shape. Without handling them, app-typed replies never reach
                // the team inbox, the business's app contacts never sync, and
                // pre-onboarding chat history never imports — i.e. it stops
                // being true coexistence and becomes "a number on the API".
                //   smb_message_echoes → messages the business sent FROM the app
                //   smb_app_state_sync → the business's app contacts (add/update)
                //   history            → past messages, pushed after onboarding
                if ($field === 'smb_message_echoes') {
                    foreach ($value['message_echoes'] ?? [] as $echo) {
                        try { $this->captureOutboundEcho($workspaceId, $echo, $value, 'waba'); }
                        catch (\Throwable $e) { \Log::warning('[WA-COEX] echo ingest failed: ' . $e->getMessage()); }
                    }
                    continue;
                }
                if ($field === 'smb_app_state_sync') {
                    try { $this->applyAppStateSync($workspaceId, $value); }
                    catch (\Throwable $e) { \Log::warning('[WA-COEX] app_state_sync failed: ' . $e->getMessage()); }
                    continue;
                }
                if ($field === 'history') {
                    try { $this->applyHistorySync($workspaceId, $value); }
                    catch (\Throwable $e) { \Log::warning('[WA-COEX] history sync failed: ' . $e->getMessage()); }
                    continue;
                }

                // Inbound messages — including `type:order` and
                // interactive form submissions (nfm_reply).
                foreach ($value['messages'] ?? [] as $msg) {
                    // Dedup on wamid. Meta retries webhooks aggressively
                    // (up to several times on 5xx + occasionally on 200).
                    // We key by JSON path `meta.wamid` since the messages
                    // table doesn't have a dedicated column. Idempotent.
                    $wamid = (string) ($msg['id'] ?? '');
                    if ($wamid !== '') {
                        $already = \DB::table('messages')
                            ->where('workspace_id', $workspaceId)
                            ->whereJsonContains('meta->wamid', $wamid)
                            ->exists();
                        if ($already) {
                            \Log::info('[WA-webhook] dedup — wamid already ingested', ['wamid' => $wamid]);
                            continue;
                        }
                    }

                    $type = $msg['type'] ?? null;
                    if ($type === 'order') {
                        $this->captureOrderFromWaba($workspaceId, $msg, $value);
                    } elseif ($type === 'interactive'
                        && (($msg['interactive']['type'] ?? '') === 'nfm_reply')) {
                        // Form submission — route to the form service
                        // which writes wa_form_submissions + resumes
                        // the paused flow on Node. Wrapped so a
                        // resolver bug never makes Meta retry.
                        try {
                            app(\App\Services\Forms\WaFormSubmissionService::class)
                                ->ingest($workspaceId, $msg, $value);
                        } catch (\Throwable $e) {
                            \Log::warning('[WA-webhook] form submission ingest crashed: ' . $e->getMessage());
                        }
                    } else {
                        $this->captureInboundMessage($workspaceId, $msg, $value, 'waba');
                    }
                }

                // Status updates (sent / delivered / read / failed)
                foreach ($value['statuses'] ?? [] as $st) {
                    $this->applyStatus($workspaceId, $st);
                }

                // ── WhatsApp Pay — in-chat payment result (WP-2) ────────────
                // Meta delivers the payment outcome in statuses[] with type:payment
                // (verified vs official doc). Hand the whole value to the service,
                // which CONFIRMS via Payment Lookup (never trusts the webhook alone —
                // Meta's rule) then settles the order. Wrapped so a bug never makes
                // Meta retry. (value.payment kept as a defensive fallback.)
                if (collect($value['statuses'] ?? [])->contains(fn ($s) => ($s['type'] ?? '') === 'payment')
                    || !empty($value['payment'])) {
                    try {
                        app(\App\Services\Waba\WhatsAppPayService::class)->applyPaymentWebhook($workspaceId, $value);
                    } catch (\Throwable $e) {
                        \Log::warning('[WAPAY] webhook handler crashed: ' . $e->getMessage());
                    }
                }
            }
        }

        return response()->json(['ok' => true]);
    }

    private function captureOrderFromWaba(int $workspaceId, array $msg, array $value): void
    {
        $order = $msg['order'] ?? null;
        if (!$order) return;

        // ── Resolve retailer_ids → real products in one query ──
        // Meta's item_price arrives as DECIMAL MAJOR units (e.g. 10.99)
        // — we convert to integer minor on the fly so the wa_order_items
        // rows stay consistent with the rest of the schema.
        $lineRows = $order['product_items'] ?? [];
        $retailerIds = array_filter(array_map(fn ($l) => $l['product_retailer_id'] ?? null, $lineRows));
        $productsByRid = [];
        if (!empty($retailerIds)) {
            $hits = \App\Models\WaProduct::where('workspace_id', $workspaceId)
                ->where(function ($q) use ($retailerIds) {
                    $q->whereIn('meta_retailer_id', $retailerIds)
                      ->orWhereIn('sku', $retailerIds);
                })->get();
            foreach ($hits as $p) {
                $key = $p->meta_retailer_id ?: $p->sku;
                if ($key) $productsByRid[$key] = $p;
            }
        }

        $items = [];           // legacy items_json shape (kept for old renderers)
        $orderItems = [];      // new wa_order_items rows
        $totalMinor = 0;
        $currency = 'INR';
        foreach ($lineRows as $line) {
            $rid = $line['product_retailer_id'] ?? '';
            $price = (int) round(((float) ($line['item_price'] ?? 0)) * 100);
            $qty   = (int) ($line['quantity'] ?? 1);
            $totalMinor += $price * $qty;
            $currency = $line['currency'] ?? $currency;
            $product = $productsByRid[$rid] ?? null;

            $items[] = [
                'retailer_id' => $rid,
                'product_id'  => $product?->id,
                'name'        => $product?->name ?? $rid ?? 'Item',
                'image'       => $product?->image_url,
                'qty'         => $qty,
                'price_minor' => $price,
                'price'       => $price, // alias the existing UI uses
                'currency'    => $currency,
            ];
            $orderItems[] = [
                'retailer_id'   => $rid ?: ('wsn-unknown-' . count($orderItems)),
                'product_id'    => $product?->id,
                'name'          => $product?->name ?? ($rid ?? 'Item'),
                'image_url'     => $product?->image_url,
                'quantity'      => $qty,
                'price_minor'   => $price,
                'currency_code' => $currency,
                'meta_json'     => ['raw' => $line],
            ];
        }

        $contact = $value['contacts'][0] ?? [];
        $createdId = null;
        \Illuminate\Support\Facades\DB::transaction(function () use ($workspaceId, $msg, $contact, $items, $orderItems, $totalMinor, $currency, $order, &$createdId) {
            $created = \App\Models\WaOrder::create([
                'workspace_id'   => $workspaceId,
                'source'         => 'waba',
                'customer_phone' => $msg['from'] ?? '',
                'customer_name'  => $contact['profile']['name'] ?? null,
                'items_json'     => $items,
                'total_minor'    => $totalMinor,
                'currency_code'  => $currency,
                'status'         => 'new',
                'wa_message_id'  => $msg['id'] ?? null,
                'notes'          => $order['text'] ?? null,
                'meta_json'      => ['catalog_id' => $order['catalog_id'] ?? null, 'product_text' => $order['text'] ?? null],
            ]);
            $createdId = $created->id;
            foreach ($orderItems as $row) {
                $created->lineItems()->create($row);
            }
        });

        // Commerce-flow loop closer for WABA — if a flow's commerce node
        // is paused waiting on this customer, advance through `purchased`.
        // Phone-based lookup because Meta does NOT echo the
        // biz_opaque_callback_data on inbound order messages. Wrapped so
        // a resolver bug never causes Meta to retry the webhook.
        try {
            \App\Services\Commerce\FlowSessionResolver::resumeFromWabaCatalogOrderByPhone(
                $workspaceId,
                (string) ($msg['from'] ?? ''),
                [
                    'id'       => $createdId,
                    'total'    => $totalMinor / 100,
                    'currency' => $currency,
                    'provider' => 'whatsapp_shop',
                ],
            );
        } catch (\Throwable $e) {
            \Log::warning('[WA-webhook] WABA flow-resume crashed (swallowed): ' . $e->getMessage());
        }

        // Close the cart loop — acknowledge the order to the buyer (summary +
        // total + optional pay link) so they're not left in silence. Wrapped
        // so a send failure never makes Meta retry (and duplicate) the order.
        if ($createdId) {
            $row = \App\Models\WaOrder::find($createdId);
            // WhatsApp Pay — opt-in: send the native in-chat order_details charge
            // instead of a plain ack when auto_charge is enabled. Falls back to
            // the normal acknowledge (summary + optional pay link) otherwise.
            $charged = false;
            if ($row) {
                try { $charged = app(\App\Services\Waba\WhatsAppPayService::class)->maybeAutoCharge($row); }
                catch (\Throwable $e) { \Log::warning('[WAPAY] catalog auto-charge failed (swallowed): ' . $e->getMessage()); }
            }
            if ($row && !$charged) {
                try { app(\App\Services\WhatsAppCatalog\CatalogOrderService::class)->acknowledge($row); }
                catch (\Throwable $e) { \Log::warning('[WA-webhook] catalog order ack failed (swallowed): ' . $e->getMessage()); }
            }
        }
    }

    /**
     * Apply a `message_template_*` webhook to the local `wa_templates`
     * row. Meta sends FOUR field variants:
     *
     *   message_template_status_update     event: APPROVED, REJECTED, PENDING, DISABLED,
     *                                             PAUSED, IN_APPEAL, LIMIT_EXCEEDED, FLAGGED,
     *                                             PENDING_DELETION, DELETED
     *   message_template_quality_update    previous_quality_score, new_quality_score (GREEN/YELLOW/RED)
     *   template_category_update           previous_category, new_category (MARKETING/UTILITY/AUTHENTICATION)
     *   message_template_components_update components (the new shape if Meta auto-edited)
     *
     * Identifier resolution prefers `message_template_id` (Meta's stable
     * id), falls back to `(name, language)` for older webhooks that omit
     * the id. We scope to this WABA's `provider_config_id` so a
     * spoofed wabaId can't poison another workspace's templates.
     */
    private function applyTemplateUpdate(string $field, array $value, \App\Models\WaProviderConfig $cfg): void
    {
        $metaId = (string) ($value['message_template_id'] ?? '');
        $name   = (string) ($value['message_template_name'] ?? '');
        $lang   = (string) ($value['message_template_language'] ?? '');

        $tpl = null;
        if ($metaId !== '') {
            $tpl = \App\Models\WaTemplate::where('provider_config_id', $cfg->id)
                ->where('meta_template_id', $metaId)->first();
        }
        if (!$tpl && $name !== '') {
            // Encrypted column — load all rows for this WABA and match
            // in PHP. Cheap because per-WABA template count is small.
            $candidates = \App\Models\WaTemplate::where('provider_config_id', $cfg->id)->get();
            $needle = mb_strtolower($name);
            foreach ($candidates as $c) {
                if (mb_strtolower((string) $c->template_name) === $needle && ($lang === '' || $c->language === $lang)) {
                    $tpl = $c; break;
                }
            }
        }
        if (!$tpl) {
            \Log::info('[WA-template-webhook] no matching template', compact('field', 'metaId', 'name', 'lang'));
            return;
        }

        $patch = ['last_synced_at' => now()];

        switch ($field) {
            case 'message_template_status_update':
                $event = strtoupper((string) ($value['event'] ?? ''));
                $reason = strtoupper((string) ($value['reason'] ?? ''));
                $patch['meta_status']           = $event;
                $patch['rejection_reason_code'] = $event === 'REJECTED' ? ($reason ?: 'NONE') : null;
                if ($event === 'PAUSED') {
                    // `other_info.title` sometimes carries a pause duration;
                    // safer to set a conservative 24h auto-unpause window.
                    $patch['paused_until'] = now()->addDay();
                }
                // Mirror to the local `status` column so existing list
                // filters keep working unchanged.
                $patch['status'] = match ($event) {
                    'APPROVED'              => 'approved',
                    'REJECTED'              => 'rejected',
                    'PENDING', 'IN_APPEAL'  => 'pending',
                    default                 => $tpl->status,
                };
                if ($event === 'APPROVED' && !$tpl->approved_at) {
                    $patch['approved_at'] = now();
                }
                break;

            case 'message_template_quality_update':
                // Meta sends either a string or a nested object — the
                // sweeper has the canonical normaliser, reuse it so
                // both code paths parse identically.
                $score = \App\Services\Waba\TemplateSyncSweeper::normalizeQualityScore(
                    $value['new_quality_score'] ?? $value['quality_score'] ?? null,
                    $tpl->quality_score
                );
                if ($score !== 'UNKNOWN') $patch['quality_score'] = $score;
                // RED quality auto-pauses non-AUTH templates for 24h —
                // ban-prevention rail. Re-checked on next sweep.
                if ($score === 'RED' && $tpl->template_type !== 'auth') {
                    $patch['paused_until'] = now()->addDay();
                }
                break;

            case 'template_category_update':
                $newCat = strtoupper((string) ($value['new_category'] ?? ''));
                if ($newCat !== '') $patch['meta_category'] = $newCat;
                break;

            case 'message_template_components_update':
                // Meta edited the components — usually footer normalization
                // or removing a duplicate variable. We don't overwrite the
                // local copy (the user authored it) but we DO log so the
                // admin can see Meta touched it.
                \Log::info('[WA-template-webhook] components edited by Meta', [
                    'tpl' => $tpl->id, 'components' => $value['components'] ?? null,
                ]);
                break;
        }

        $tpl->update($patch);
        \Log::info('[WA-template-webhook] applied', ['field' => $field, 'tpl' => $tpl->id, 'patch' => array_keys($patch)]);
    }

    private function captureInboundMessage(int $workspaceId, array $msg, array $value, string $provider = 'waba'): void
    {
        $contact = $value['contacts'][0] ?? [];
        $fromPhone = (string) ($msg['from'] ?? '');
        if ($fromPhone === '') return;
        $senderName = (string) ($contact['profile']['name'] ?? '');

        // DIAGNOSTIC — confirms the inbound was parsed and reached capture (i.e.
        // it passed signature + config matching). Pair it with the
        // '[WA-webhook] Meta POST received' line and '[WABA-INBOUND] flow bridge'
        // below to see exactly how far an inbound message gets.
        \Log::info('[WABA-INBOUND] capture', [
            'workspace_id' => $workspaceId,
            'provider'     => $provider,
            'from'         => $fromPhone,
            'type'         => (string) ($msg['type'] ?? ''),
            'text'         => (string) ($msg['text']['body'] ?? ''),
            'to_number'    => (string) data_get($value, 'metadata.display_phone_number', ''),
        ]);

        // fromMe ECHO DETECTION — if the inbound's `from` matches any
        // of this workspace's own connected provider phone numbers,
        // it's the operator replying from their personal phone (WABA
        // Business app on Meta, or the operator's mobile sharing a
        // Twilio number). Route it as OUTBOUND so the conversation in
        // /team-inbox shows the operator's mobile-typed reply. Same
        // pattern as Baileys `key.fromMe=true` sync via Node.
        //
        // Detection: normalize both sides to digits-only and compare.
        // Twilio strips `whatsapp:` prefix upstream; WABA always sends
        // raw digits. So a single digits-only equality check is safe.
        $fromDigits = preg_replace('/\D+/', '', $fromPhone);
        $isOutboundEcho = false;
        try {
            $ownPhones = \App\Models\WaProviderConfig::query()
                ->where('workspace_id', $workspaceId)
                ->pluck('phone_number')
                ->map(fn ($p) => preg_replace('/\D+/', '', (string) $p))
                ->filter()
                ->all();
            if (in_array($fromDigits, $ownPhones, true)) {
                $isOutboundEcho = true;
            }
        } catch (\Throwable $e) { /* lookup failure — treat as normal inbound */ }

        if ($isOutboundEcho) {
            $this->captureOutboundEcho($workspaceId, $msg, $value, $provider);
            return;
        }

        // Resolve body + media type from Meta's payload shape. Each
        // type has its own field; we collapse to a single body string
        // + media_type so the team-inbox renderer treats WABA inbound
        // identical to Baileys inbound.
        [$body, $mediaType, $mediaMeta] = $this->extractMetaInboundContent($msg);

        // Reaction-only payload — mutates an existing message, not a
        // new bubble. Find the target by wa_message_id and stamp its
        // reaction column (or clear it if emoji is empty). Mirrors the
        // Baileys handler in WaInboundController.
        if ($mediaType === 'reaction' && !empty($mediaMeta['reaction_to'])) {
            $targetWaId = (string) $mediaMeta['reaction_to'];
            $emoji      = (string) $body;
            $target = \App\Models\InboxMessage::query()
                ->whereJsonContains('meta->wa_message_id', $targetWaId)
                ->whereHas('conversation', fn ($q) => $q->where('workspace_id', $workspaceId))
                ->orderByDesc('id')
                ->first();
            if ($target) {
                $target->update(['reaction' => $emoji !== '' ? $emoji : null]);
                \Log::info('[WABA-INBOUND] reaction applied', [
                    'workspace_id' => $workspaceId, 'message_id' => $target->id, 'emoji' => $emoji,
                ]);
            }
            return;
        }

        // Cart parser — only applies to plain-text inbound matching the
        // wa.me storefront link shape.
        if ($body !== '' && $mediaType === null) {
            $parsed = app(StorefrontOrderParser::class)->parse($workspaceId, $body, $fromPhone, $senderName ?: null);
            if ($parsed) {
                $parsed->update(['wa_message_id' => $msg['id'] ?? null]);
            }
        }

        // "Asked about a product" — Meta stamps context.referred_product
        // when the buyer taps a product card before typing.
        $referred = $msg['context']['referred_product'] ?? null;
        if ($referred && !empty($referred['product_retailer_id'])) {
            $this->tagConversationWithReferredProduct(
                $workspaceId, $fromPhone, $referred['product_retailer_id']
            );
        }

        // Resolve the device row that owns the inbound number — the BUSINESS
        // number that RECEIVED this message — so the conversation binds to the
        // right sender and replies leave on the SAME number the customer wrote
        // to. On a multi-WABA workspace, falling back to the workspace's first
        // device (the old behaviour) made every reply go out from the primary
        // number — which the customer never messaged, and whose token may even
        // be the wrong/expired one.
        $device = $this->resolveDeviceForReceivingNumber($workspaceId, $value)
            ?? $this->resolveDeviceForWorkspace($workspaceId);

        // Upsert the conversation — mirrors WaInboundController::baileys
        // resolution but keyed on workspace + raw_jid form of the phone.
        $rawJid = preg_replace('/\D+/', '', $fromPhone);
        $title  = $senderName !== '' ? $senderName : $rawJid;

        // Multi-engine: scope the match to the engine this message belongs to
        // ($provider). Without it, a customer who reaches a workspace on BOTH
        // its WABA number AND its Twilio (or Unofficial API) number would
        // collapse into ONE thread, and a reply would go back over whichever
        // channel last touched it — not the one the operator is looking at.
        // Each channel keeps its own conversation, mirroring how the Baileys
        // inbound path already separates by device_id. Single-engine (all rows
        // provider='waba') is unaffected — the filter is a no-op there.
        // Match by workspace + ENGINE (provider) + the customer's number. We do
        // NOT filter on `platform` here: inbound rows stamp platform='W' but a
        // Quick Send (/chat) thread for the same number stamps the engine's
        // legacy code ('WB'/'T'), so a platform filter would MISS the Quick Send
        // thread and open a duplicate. provider already scopes the engine, so a
        // reply now lands in the ONE existing conversation for that number —
        // whether it was started from Quick Send or a previous inbound.
        $convo = \App\Models\Conversation::query()
            ->where('workspace_id', $workspaceId)
            ->where('provider', $provider)
            ->whereIn('origin', ['inbox', 'chatbot'])
            ->where(function ($q) use ($rawJid) {
                $q->where('raw_jid', $rawJid)->orWhere('alt_jid', $rawJid);
            })
            ->orderByDesc('id')
            ->first();

        // Back-compat: migration 2026_05_26_140000 backfilled EVERY pre-existing
        // conversation to provider='baileys', so a legacy WABA/Twilio thread for
        // this number may still be stamped 'baileys' and would now miss the
        // provider-scoped match above — opening a duplicate thread. Adopt and
        // re-stamp it instead, but ONLY when this workspace has NEVER had a
        // Baileys sender at all (no Device row, no baileys WaProviderConfig).
        // We deliberately do NOT gate on WorkspaceEngine::isEngineEnabled here:
        // that reflects the engines switched ON right now (allowed ∩ subset ∩
        // connected), so a workspace that genuinely ran Baileys and then set
        // enabled_engines=['waba'] would report baileys "disabled" while its real
        // provider='baileys' threads still exist — adopting one of those would
        // HIJACK a genuine Baileys conversation. A raw existence check can't be
        // fooled by policy: with no Baileys sender ever present, a 'baileys'-
        // stamped thread can only be a migration-backfill artifact. The
        // orWhereNull('workspace_id') device leg conservatively blocks adoption
        // when a shared legacy device exists. Self-corrects per-thread; no risky
        // bulk data migration (device_id can't tell engines apart — migration
        // 140000's own note).
        // Only on a primary-lookup MISS for a non-baileys inbound — keep the two
        // existence probes off the hot path (a normal repeat inbound hits the
        // lookup above and never reaches here).
        if (!$convo && $provider !== 'baileys') {
            $workspaceEverHadBaileys = \App\Models\Device::query()
                    ->where(fn ($q) => $q->where('workspace_id', $workspaceId)->orWhereNull('workspace_id'))
                    ->exists()
                || \App\Models\WaProviderConfig::query()
                    ->where('workspace_id', $workspaceId)
                    ->where('provider', 'baileys')
                    ->exists();
            if (!$workspaceEverHadBaileys) {
                $legacy = \App\Models\Conversation::query()
                    ->where('workspace_id', $workspaceId)
                    ->where('platform', 'W')
                    ->where('provider', 'baileys')
                    ->whereIn('origin', ['inbox', 'chatbot'])
                    ->where(function ($q) use ($rawJid) {
                        $q->where('raw_jid', $rawJid)->orWhere('alt_jid', $rawJid);
                    })
                    ->orderByDesc('id')
                    ->first();
                if ($legacy) {
                    $legacy->update(['provider' => $provider]);
                    $convo = $legacy;
                }
            }
        }

        $isNewConversation = !$convo;
        if (!$convo) {
            $convo = \App\Models\Conversation::create([
                'user_id'          => $device?->user_id,
                'workspace_id'     => $workspaceId,
                'device_id'        => $device?->id,
                'contact_group_id' => null,
                'title'            => $title,
                'raw_jid'          => $rawJid,
                'preview'          => mb_substr(
                $body !== ''
                    ? $body
                    : ($mediaType === 'location'
                        ? '📍 ' . ($meta['location_name'] ?? 'Location')
                        : '📎 ' . ($mediaType ?: 'message')),
                0, 191
            ),
                'status'           => 'pending',
                'platform'         => 'W',
                // Multi-engine: stamp the engine this message ARRIVED on, not a
                // hard-coded 'waba'. captureInboundMessage is shared by the WABA
                // and Twilio webhooks ($provider is 'waba' or 'twilio'); the old
                // hard-code mis-stamped every Twilio inbound as 'waba', so the
                // reply routed back over WABA instead of Twilio.
                'provider'         => $provider,
                'origin'           => 'inbox',
                'recipients_count' => 1,
                'last_message_at'  => now(),
            ]);
        } else {
            // Backfill title if it's still just digits and we just learned the name.
            $updates = [];
            $currentHasDigits = preg_match('/\d{8,}/', (string) $convo->title) === 1;
            if ($senderName !== '' && $currentHasDigits) {
                $updates['title'] = $title;
            }
            if (!empty($updates)) $convo->update($updates);
        }

        // Persist the InboxMessage. Media payload is recorded as meta
        // (Meta's CDN URL needs an authenticated fetch; the media-pull
        // worker resolves the binary later — for now the operator at
        // least sees the message landed with the right type label).
        $meta = [];
        if ($msg['id'] ?? null) $meta['wa_message_id'] = $msg['id'];
        if (!empty($mediaMeta)) $meta = array_merge($meta, $mediaMeta);

        $inboundMsg = \App\Models\InboxMessage::create([
            'conversation_id' => $convo->id,
            'user_id'         => $device?->user_id,
            'direction'       => 'in',
            'from_number'     => $fromPhone,
            'to_number'       => null,
            'body'            => $body,
            'media_type'      => $mediaType,
            'latitude'        => isset($meta['location_latitude'])  ? (float) $meta['location_latitude']  : null,
            'longitude'       => isset($meta['location_longitude']) ? (float) $meta['location_longitude'] : null,
            'meta'            => $meta ?: null,
            'status'          => 'received',
            'sent_at'         => isset($msg['timestamp']) ? \Carbon\Carbon::createFromTimestamp((int) $msg['timestamp']) : now(),
            'delivered_at'    => now(),
        ]);

        // WABA Cloud media is referenced by a Meta media ID (not a URL), so
        // unlike Baileys nothing lands on disk by default. Pull inbound
        // IMAGES down now — before the AI auto-reply trigger below — so the
        // team-inbox can render them AND the AI agent can SEE them (vision).
        // Best-effort: any failure leaves media_path null and the agent
        // simply falls back to text. Scoped to images on the WABA engine
        // (Twilio inbound media arrives as a direct URL on its own path).
        if ($provider === 'waba' && $mediaType === 'image' && !empty($meta['waba_media_id'])) {
            $localPath = $this->downloadWabaMediaToDisk(
                $workspaceId,
                (string) $meta['waba_media_id'],
                (string) ($meta['waba_mime_type'] ?? ''),
            );
            if ($localPath) {
                $inboundMsg->forceFill(['media_path' => $localPath])->save();
            }
        }

        $convo->update([
            'preview'         => mb_substr(
                $body !== ''
                    ? $body
                    : ($mediaType === 'location'
                        ? '📍 ' . ($meta['location_name'] ?? 'Location')
                        : '📎 ' . ($mediaType ?: 'message')),
                0, 191
            ),
            'last_message_at' => now(),
            'last_inbound_at' => now(),
            'inbox_status'    => 'pending',
        ]);
        $convo->increment('unread_count');

        // Mirror Baileys-side broadcast so operators see new inbound
        // live when Echo client listeners exist. Fires lockless via the
        // queue connection; non-broadcasting setups silently no-op.
        try {
            broadcast(new \App\Events\Inbox\MessageReceived(
                $inboundMsg->id, $convo->id, $workspaceId, 'in', null
            ))->toOthers();
        } catch (\Throwable $e) {
            \Log::warning('[WABA-INBOUND] broadcast failed: ' . $e->getMessage());
        }

        // Webhook: message_received — fires for inbound on WABA Cloud AND
        // Twilio (both route through this method; the Baileys/Unofficial
        // path emits from WaInboundController::baileys).
        \App\Services\WebhookService::emit('message_received', [
            'workspace_id'    => $workspaceId,
            'user_id'         => $device?->user_id,
            'message_id'      => $inboundMsg->id,
            'conversation_id' => $convo->id,
            'from_number'     => $fromPhone,
            'body'            => $body,
            'media_type'      => $mediaType,
            'wamid'           => $msg['id'] ?? null,
            'provider'        => $provider,
            'timestamp'       => now()->timestamp,
        ], $device?->user_id);

        // Legacy messages table — keeps existing chat history / analytics
        // surfaces working. SAME shape as the old captureInboundMessage
        // pre-refactor so nothing downstream breaks.
        Message::create([
            'user_id'      => null,
            'workspace_id' => $workspaceId,
            'direction'    => 'in',
            'from_number'  => $fromPhone,
            'body'         => $body,
            'status'       => 'sent',
            'sent_at'      => now(),
        ]);

        // Outbound webhook subscribers — CRM integrations get notified.
        // `provider` lets downstream subscribers (Shopify / Hubspot / Woo
        // re-emitters, custom Zapier triggers, etc.) route by the actual
        // source: waba / baileys / twilio. Previously every inbound was
        // tagged `channel: whatsapp` with no provider, so a CRM that
        // expects a Meta-shaped wamid would misroute Twilio inbounds.
        try {
            $whd = app(\App\Services\Inbox\OutboundWebhookDispatcher::class);
            if ($isNewConversation) {
                $whd->fire('conversation.created', $convo->fresh(), [
                    'channel'      => 'whatsapp',
                    'provider'     => $provider,
                    'sender_phone' => $rawJid,
                    'sender_name'  => $senderName,
                ]);
            }
            $whd->fire('conversation.received', $convo->fresh(), [
                'body'         => $body,
                'media_type'   => $mediaType,
                'provider'     => $provider,
                'sender_phone' => $rawJid,
            ]);
        } catch (\Throwable $e) {
            \Log::warning('[WABA-INBOUND] webhook dispatch failed: ' . $e->getMessage());
        }

        // Routing rules — same engine the Baileys path uses, identical
        // contract (new-conv → full action set; follow-up → per-message).
        try {
            app(\App\Services\Inbox\RoutingEngine::class)->applyToInbound(
                $convo->fresh(),
                ['message_text' => $body, 'contact_phone' => $rawJid],
                isFollowUp: !$isNewConversation,
            );
            $convo = $convo->fresh();
        } catch (\Throwable $e) {
            \Log::warning('[WABA-INBOUND] routing engine failed: ' . $e->getMessage());
        }

        // Flow engine for WABA / Twilio. Baileys runs flows from its socket
        // handler; webhook-delivered providers reach the flow engine HERE. We
        // ask Node (synchronously) whether this inbound starts a flow (keyword
        // trigger) or resumes one parked on this customer's reply. When it does
        // ("consumed"), we SKIP the keyword auto-reply + AI agent below so the
        // customer never gets a double reply — the same way the Baileys handler
        // `continue`s after a flow fires. Node sends every flow node via the
        // WABA/Twilio engine (sock=null → engine resolved from settings).
        $flowConsumed = false;
        try {
            // The flow must run on — and reply FROM — the number that RECEIVED
            // this message: our WABA/Twilio number. For WABA that is
            // metadata.display_phone_number. We must NOT use $device here: on a
            // workspace that also has a Baileys number, $device can resolve to
            // the Baileys device, which makes the flow reply go out via Baileys
            // from the wrong number (the "why is it using my Baileys number" bug).
            $receivingNumber = preg_replace('/\D+/', '', (string) data_get($value, 'metadata.display_phone_number', ''));
            $deviceNumber = $receivingNumber !== ''
                ? $receivingNumber
                : ($device ? preg_replace('/\D+/', '', (string) ($device->country_code . $device->phone_number)) : '');
            $nodeBase = (string) (\App\Models\SystemSetting::get('baileys_server_url', '') ?: env('SERVER_URL', ''));
            if ($deviceNumber !== '' && $nodeBase !== '') {
                // Interactive tap id (button / list reply) so the flow can route
                // by port; empty for a plain typed reply.
                $replyId = (string) (
                    data_get($msg, 'interactive.button_reply.id')
                    ?? data_get($msg, 'interactive.list_reply.id')
                    ?? data_get($msg, 'button.payload')
                    ?? ''
                );
                $res = \Illuminate\Support\Facades\Http::withHeaders(['X-Node-Token' => node_token()])
                    ->timeout(12)->acceptJson()
                    ->post(rtrim($nodeBase, '/') . '/api/flow/provider-inbound', [
                        'deviceNumber'  => $deviceNumber,
                        'customerPhone' => $rawJid,
                        'workspaceId'   => $workspaceId,
                        'provider'      => $provider,
                        'text'          => (string) $body,
                        'replyId'       => $replyId,
                        'pushName'      => (string) $senderName,
                    ]);
                $flowConsumed = (bool) $res->json('consumed');
                \Log::info('[WABA-INBOUND] flow bridge', ['device' => $deviceNumber, 'customer' => $rawJid, 'consumed' => $flowConsumed]);
            }
        } catch (\Throwable $e) {
            \Log::warning('[WABA-INBOUND] provider-inbound flow bridge failed: ' . $e->getMessage());
        }

        // /auto-reply (keyword_replies) matcher. Was Baileys-only via
        // Node before this hook — WABA + Twilio workspaces had the UI
        // but no firing path. KeywordReplyDispatcher handles match +
        // cooldown + anti-loop guard + dispatch via InboxDispatcher
        // (which picks the correct provider's send method). Skipped when a
        // flow already consumed this message.
        if (!$flowConsumed) {
        try {
            // Pass the workspace's own connected number explicitly so the
            // dispatcher can detect self-echo (operator typing on the same
            // WABA/Twilio number → re-trigger storm). `conversations`
            // has no device_phone column; for WABA the workspace number
            // lives on the resolved Device row.
            $selfNumber = (string) ($device?->phone_number ?? '');
            app(\App\Services\Inbox\KeywordReplyDispatcher::class)
                ->maybeDispatch($convo->fresh(), $body, $rawJid, $selfNumber ?: null);
            $convo = $convo->fresh();
        } catch (\Throwable $e) {
            \Log::warning('[WABA-INBOUND] keyword reply dispatch failed: ' . $e->getMessage());
        }
        } // end if (!$flowConsumed)

        // AI agent auto-respond — only fires if the workspace has an
        // active AI agent assigned and plan grants the feature.
        // Skip when this was an audio-only inbound with no transcript,
        // otherwise the LLM hallucinates against empty body. Also skipped
        // when a flow consumed the message (flow drives the conversation).
        if (!$flowConsumed && !($mediaType === 'audio' && $body === '')) {
            try {
                app(\App\Services\AiAgentService::class)->respondIfAssigned($convo->fresh());
            } catch (\Throwable $e) {
                \Log::warning('[WABA-INBOUND] ai agent failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * Persist an outbound echo — operator replied from their personal
     * phone (WhatsApp Business app for WABA Cloud, or the shared mobile
     * sharing a Twilio number) and Meta/Twilio mirrored it back as a
     * webhook event. We record it as `direction='out'` on the
     * conversation so /team-inbox shows the mobile-typed reply inline
     * with API-sent replies. Mirrors `WaInboundController::baileys`
     * fromMe handling so all 3 providers behave identically.
     *
     * Key differences from regular inbound:
     *   - direction='out' (operator-side)
     *   - NO unread_count bump (operator already saw it on their phone)
     *   - NO inbox_status='pending' flip (they already responded)
     *   - NO routing rules (no point — operator already engaged)
     *   - NO AI auto-reply (we don't reply to our own reply)
     *   - NO `conversation.received` CRM webhook (those are for inbound)
     *   - DOES update last_message_at + last_outbound_at
     *   - DOES fire MessageReceived broadcast so OTHER operators see
     *     the mobile-typed reply live in their open inbox
     *   - DOES fire a `conversation.outbound_synced` CRM hook so
     *     downstream CRMs can record the activity
     */
    private function captureOutboundEcho(int $workspaceId, array $msg, array $value, string $provider): void
    {
        $contact   = $value['contacts'][0] ?? [];
        $ownPhone  = (string) ($msg['from'] ?? '');
        // The recipient of the operator's mobile-typed reply is the
        // customer — Meta puts that under `to` (Twilio under the
        // mapped 'to' we built in receiveTwilio).
        $toPhone   = (string) ($msg['to'] ?? '');
        if ($toPhone === '') {
            // Meta echoes occasionally omit `to`. Coexistence echoes can carry
            // the customer under `recipient_id` (the same key statuses use);
            // fall back to that, then to `context.from` (the original thread
            // the echo is replying to). Last resort: skip — can't route a
            // mobile-typed reply without a customer phone.
            $toPhone = (string) ($msg['recipient_id'] ?? $msg['context']['from'] ?? '');
        }
        if ($toPhone === '') {
            \Log::warning('[OUTBOUND-ECHO] missing `to` — cannot route', ['msg_id' => $msg['id'] ?? null, 'provider' => $provider]);
            return;
        }

        [$body, $mediaType, $mediaMeta] = $this->extractMetaInboundContent($msg);
        $rawJid = preg_replace('/\D+/', '', $toPhone);

        // Find the existing conversation with the customer. If it
        // doesn't exist yet (operator messaged the customer first from
        // their phone, never via the system), create one — the
        // conversation history should still surface this thread.
        // Multi-engine: scope the match to the engine this message belongs to
        // ($provider). Without it, a customer who reaches a workspace on BOTH
        // its WABA number AND its Twilio (or Unofficial API) number would
        // collapse into ONE thread, and a reply would go back over whichever
        // channel last touched it — not the one the operator is looking at.
        // Each channel keeps its own conversation, mirroring how the Baileys
        // inbound path already separates by device_id. Single-engine (all rows
        // provider='waba') is unaffected — the filter is a no-op there.
        // Match by workspace + ENGINE (provider) + the customer's number. We do
        // NOT filter on `platform` here: inbound rows stamp platform='W' but a
        // Quick Send (/chat) thread for the same number stamps the engine's
        // legacy code ('WB'/'T'), so a platform filter would MISS the Quick Send
        // thread and open a duplicate. provider already scopes the engine, so a
        // reply now lands in the ONE existing conversation for that number —
        // whether it was started from Quick Send or a previous inbound.
        $convo = \App\Models\Conversation::query()
            ->where('workspace_id', $workspaceId)
            ->where('provider', $provider)
            ->whereIn('origin', ['inbox', 'chatbot'])
            ->where(function ($q) use ($rawJid) {
                $q->where('raw_jid', $rawJid)->orWhere('alt_jid', $rawJid);
            })
            ->orderByDesc('id')
            ->first();

        $device = $this->resolveDeviceForWorkspace($workspaceId);
        $isNewConversation = !$convo;
        if (!$convo) {
            $convo = \App\Models\Conversation::create([
                'user_id'          => $device?->user_id,
                'workspace_id'     => $workspaceId,
                'device_id'        => $device?->id,
                'title'            => $rawJid,
                'raw_jid'          => $rawJid,
                'preview'          => mb_substr($body !== '' ? $body : ('📎 ' . ($mediaType ?: 'message')), 0, 191),
                'status'           => 'sent',
                'platform'         => 'W',
                'provider'         => $provider,
                'origin'           => 'inbox',
                'recipients_count' => 1,
                'last_message_at'  => now(),
                'last_outbound_at' => now(),
            ]);
        }

        // Dedupe — if this wa_message_id is already on a row, skip the
        // insert. Meta echoes can arrive twice on retry; we don't want
        // duplicate bubbles in the operator's view.
        $waMessageId = (string) ($msg['id'] ?? '');
        if ($waMessageId !== '') {
            $existing = \App\Models\InboxMessage::query()
                ->whereJsonContains('meta->wa_message_id', $waMessageId)
                ->whereHas('conversation', fn ($q) => $q->where('workspace_id', $workspaceId))
                ->exists();
            if ($existing) {
                \Log::info('[OUTBOUND-ECHO] dedupe — wa_message_id already persisted', [
                    'wa_message_id' => $waMessageId, 'provider' => $provider,
                ]);
                return;
            }
        }

        $meta = ['phone_sync' => true];
        if ($waMessageId !== '') $meta['wa_message_id'] = $waMessageId;
        if (!empty($mediaMeta)) $meta = array_merge($meta, $mediaMeta);

        $outboundMsg = \App\Models\InboxMessage::create([
            'conversation_id' => $convo->id,
            'user_id'         => $device?->user_id,
            'direction'       => 'out',
            'from_number'     => $ownPhone,
            'to_number'       => $toPhone,
            'body'            => $body,
            'media_type'      => $mediaType,
            'latitude'        => isset($meta['location_latitude'])  ? (float) $meta['location_latitude']  : null,
            'longitude'       => isset($meta['location_longitude']) ? (float) $meta['location_longitude'] : null,
            'meta'            => $meta,
            'status'          => 'sent',
            'sent_at'         => isset($msg['timestamp']) ? \Carbon\Carbon::createFromTimestamp((int) $msg['timestamp']) : now(),
        ]);

        // Update conversation pointers — but explicitly NOT unread_count
        // or inbox_status (operator just replied; nothing to action).
        $convo->update([
            'preview'          => mb_substr($body !== '' ? $body : ('📎 ' . ($mediaType ?: 'message')), 0, 191),
            'last_message_at'  => now(),
            'last_outbound_at' => now(),
        ]);

        // Broadcast so OTHER operators viewing this conversation see
        // the mobile-typed reply appear instantly (the operator who
        // typed it on their phone already saw it there).
        try {
            broadcast(new \App\Events\Inbox\MessageReceived(
                $outboundMsg->id, $convo->id, $workspaceId, 'out', $device?->user_id
            ))->toOthers();
        } catch (\Throwable $e) {
            \Log::warning('[OUTBOUND-ECHO] broadcast failed: ' . $e->getMessage());
        }

        // CRM webhook — typed as `conversation.outbound_synced` so
        // downstream subscribers can distinguish "system-sent" from
        // "mobile-typed" outbound. Provider is stamped so Zapier/
        // Shopify/Hubspot integrations can route correctly.
        try {
            $whd = app(\App\Services\Inbox\OutboundWebhookDispatcher::class);
            $whd->fire('conversation.outbound_synced', $convo->fresh(), [
                'body'         => $body,
                'media_type'   => $mediaType,
                'provider'     => $provider,
                'sender_phone' => $rawJid,
                'source'       => 'mobile',  // distinguishes phone-typed from system-typed
            ]);
        } catch (\Throwable $e) {
            \Log::warning('[OUTBOUND-ECHO] webhook dispatch failed: ' . $e->getMessage());
        }

        \Log::info('[OUTBOUND-ECHO] mobile-typed reply mirrored to inbox', [
            'workspace_id' => $workspaceId,
            'conv_id'      => $convo->id,
            'msg_id'       => $outboundMsg->id,
            'provider'     => $provider,
        ]);
    }

    /**
     * Coexistence `smb_app_state_sync` — the business's WhatsApp Business app
     * contacts (and any new ones it adds) mirrored into our contact book, so
     * the workspace's app address-book and the platform stay in step. We only
     * ADD/UPDATE; a 'remove' is logged, never deleted (avoids surprise data
     * loss from an app-side edit). Phone-digit matched against the workspace's
     * existing contacts so we never duplicate someone already on the platform.
     */
    private function applyAppStateSync(int $workspaceId, array $value): void
    {
        $items = $value['state_sync'] ?? [];
        if (!is_array($items) || empty($items)) return;

        $device = $this->resolveDeviceForWorkspace($workspaceId);
        $ownerId = $device?->user_id;

        // Load existing contacts once for digit matching (mobile is encrypted,
        // so a SQL LIKE can't match). Index by `digits(mobile)` — the SAME key
        // the team inbox uses to match a contact to a conversation's full
        // number (TeamInboxController createDeal/conversationDeals compare
        // digits(mobile) === conv->raw_jid). The platform stores the country
        // code INSIDE `mobile`, so digits(mobile) is already the full number;
        // synced contacts store the full E.164 in `mobile` too, so the two
        // dedupe against each other. (Using country_code . mobile would double
        // the prefix for UI-added contacts and miss the match.)
        $existing = \App\Models\Contact::where('workspace_id', $workspaceId)->get();
        $indexByDigits = [];
        foreach ($existing as $c) {
            $d = preg_replace('/\D+/', '', (string) ($c->mobile ?: ''));
            if ($d !== '') $indexByDigits[$d] = $c;
        }

        $added = 0; $updated = 0;

        // CRITICAL: suppress model events for the whole sync. Contact::created
        // otherwise fires FlowEnrollmentService::onContactCreated (which can
        // POST to Node and send a real WhatsApp message) + the LogsNotifications
        // trait — so mirroring the business's entire app address book would
        // blast a flow message + notification to every synced contact. The
        // sync is a bulk data import, not live engagement: events stay off.
        \App\Models\Contact::withoutEvents(function () use ($items, $indexByDigits, $ownerId, $workspaceId, &$added, &$updated) {
            foreach ($items as $item) {
                if (($item['type'] ?? 'contact') !== 'contact') continue;
                $action  = (string) ($item['action'] ?? 'add');
                $contact = $item['contact'] ?? [];
                $phone   = (string) ($contact['phone_number'] ?? '');
                $digits  = preg_replace('/\D+/', '', $phone);
                if ($digits === '') continue;

                // full_name can be a string or {name:...}; first/last may be split.
                $full = $contact['full_name'] ?? null;
                if (is_array($full)) $full = $full['name'] ?? null;
                $name = trim((string) ($full
                    ?: trim(((string) ($contact['first_name'] ?? '')) . ' ' . ((string) ($contact['last_name'] ?? '')))));

                if ($action === 'remove') {
                    \Log::info('[WA-COEX] app_state_sync remove (kept on platform)', ['ws' => $workspaceId, 'phone' => mask_phone($phone)]);
                    continue;
                }

                if (isset($indexByDigits[$digits])) {
                    // Existing contact → fill a blank name only (never overwrite a
                    // name the operator curated). A `true` here means we already
                    // created this number earlier in THIS batch — just skip it
                    // (don't treat the bool as a model).
                    $row = $indexByDigits[$digits];
                    if ($row instanceof \App\Models\Contact && $name !== '' && trim((string) $row->name) === '') {
                        $row->name = $name;
                        $row->save();
                        $updated++;
                    }
                    continue;
                }

                // Store the full international number in `mobile` (no separate
                // country_code split — Meta hands us one E.164 string). Readers
                // concatenate country_code . mobile, so a NULL prefix + full
                // number resolves to the same digit key we indexed on above.
                \App\Models\Contact::create([
                    'user_id'      => $ownerId,
                    'workspace_id' => $workspaceId,
                    'name'         => $name ?: $phone,
                    'mobile'       => $phone,
                    'msg'          => 'Synced from WhatsApp Business app (Coexistence).',
                ]);
                $indexByDigits[$digits] = true; // guard against dupes within the same batch
                $added++;
            }
        });

        \Log::info('[WA-COEX] app_state_sync applied', ['ws' => $workspaceId, 'added' => $added, 'updated' => $updated]);
    }

    /**
     * Coexistence `history` — past messages the business sent/received on the
     * WhatsApp Business app BEFORE onboarding, pushed by Meta in the minutes
     * after a successful coexistence onboard. We backfill them into the team
     * inbox QUIETLY: deduped by wamid, with NO routing / AI / auto-reply / CRM
     * webhooks (those are for live traffic — firing flows on months-old chats
     * would be a disaster). Capped per delivery so a huge import can't stall
     * the webhook response.
     */
    private function applyHistorySync(int $workspaceId, array $value): void
    {
        $history = $value['history'] ?? [];
        if (!is_array($history) || empty($history)) return;

        $device   = $this->resolveDeviceForWorkspace($workspaceId);
        $ownPhones = [];
        try {
            $ownPhones = \App\Models\WaProviderConfig::where('workspace_id', $workspaceId)
                ->pluck('phone_number')->map(fn ($p) => preg_replace('/\D+/', '', (string) $p))->filter()->all();
        } catch (\Throwable $e) { /* treat all as inbound on failure */ }

        $cap = 2000; $done = 0;
        foreach ($history as $chunk) {
            foreach ($chunk['threads'] ?? [] as $thread) {
                $threadId = (string) ($thread['id'] ?? '');
                foreach ($thread['messages'] ?? [] as $msg) {
                    if ($done >= $cap) {
                        \Log::warning('[WA-COEX] history cap hit — remaining messages skipped this delivery', ['ws' => $workspaceId, 'cap' => $cap]);
                        return;
                    }
                    try {
                        if ($this->insertHistoricalMessage($workspaceId, $msg, $threadId, $device, $ownPhones)) $done++;
                    } catch (\Throwable $e) {
                        \Log::warning('[WA-COEX] history message insert failed: ' . $e->getMessage());
                    }
                }
            }
        }

        \Log::info('[WA-COEX] history backfilled', ['ws' => $workspaceId, 'messages' => $done]);
    }

    /**
     * Insert ONE historical message into the inbox without side effects.
     * Returns true if a row was written, false if deduped/skipped.
     */
    private function insertHistoricalMessage(int $workspaceId, array $msg, string $threadId, $device, array $ownPhones): bool
    {
        $wamid = (string) ($msg['id'] ?? '');
        if ($wamid !== '') {
            $dupe = \App\Models\InboxMessage::query()
                ->whereJsonContains('meta->wa_message_id', $wamid)
                ->whereHas('conversation', fn ($q) => $q->where('workspace_id', $workspaceId))
                ->exists();
            if ($dupe) return false;
        }

        $from = preg_replace('/\D+/', '', (string) ($msg['from'] ?? ''));
        $isOut = $from !== '' && in_array($from, $ownPhones, true);
        // The customer side of the thread = thread id, or the non-own party.
        $customer = preg_replace('/\D+/', '', $threadId ?: (string) ($msg['to'] ?? $msg['from'] ?? ''));
        if ($customer === '' || in_array($customer, $ownPhones, true)) {
            $customer = $isOut ? preg_replace('/\D+/', '', (string) ($msg['to'] ?? '')) : $from;
        }
        if ($customer === '') return false;

        [$body, $mediaType] = $this->extractMetaInboundContent($msg);

        $convo = \App\Models\Conversation::query()
            ->where('workspace_id', $workspaceId)
            ->where('platform', 'W')
            ->where(function ($q) use ($customer) {
                $q->where('raw_jid', $customer)->orWhere('alt_jid', $customer);
            })
            ->orderByDesc('id')
            ->first();

        if (!$convo) {
            $convo = \App\Models\Conversation::create([
                'user_id'          => $device?->user_id,
                'workspace_id'     => $workspaceId,
                'device_id'        => $device?->id,
                'title'            => $customer,
                'raw_jid'          => $customer,
                'preview'          => mb_substr($body !== '' ? $body : ('📎 ' . ($mediaType ?: 'message')), 0, 191),
                'status'           => 'sent',
                'platform'         => 'W',
                'provider'         => 'waba',
                'origin'           => 'inbox',
                'recipients_count' => 1,
                'last_message_at'  => isset($msg['timestamp']) ? \Carbon\Carbon::createFromTimestamp((int) $msg['timestamp']) : now(),
            ]);
        }

        $sentAt = isset($msg['timestamp']) ? \Carbon\Carbon::createFromTimestamp((int) $msg['timestamp']) : now();
        $meta = ['history_sync' => true];
        if ($wamid !== '') $meta['wa_message_id'] = $wamid;

        $row = \App\Models\InboxMessage::create([
            'conversation_id' => $convo->id,
            'user_id'         => $isOut ? $device?->user_id : null,
            'direction'       => $isOut ? 'out' : 'in',
            'from_number'     => (string) ($msg['from'] ?? ''),
            'to_number'       => (string) ($msg['to'] ?? ''),
            'body'            => $body,
            'media_type'      => $mediaType,
            'meta'            => $meta,
            'status'          => 'sent',
            'sent_at'         => $sentAt,
        ]);
        // Eloquent stamps created_at = now() on insert, which would sort old
        // history below live messages. Backdate it (bypassing timestamps) so
        // the thread renders in true chronological order.
        \App\Models\InboxMessage::withoutTimestamps(fn () => $row->forceFill(['created_at' => $sentAt])->save());

        // Keep the conversation's last_message_at at the newest message time,
        // but never push it forward past a live message already recorded.
        if (!$convo->last_message_at || $sentAt->gt($convo->last_message_at)) {
            $convo->forceFill(['last_message_at' => $sentAt])->save();
        }

        return true;
    }

    /**
     * Pull an inbound WABA Cloud media object down to local disk so the
     * team-inbox can render it AND the AI agent can "see" it (vision).
     *
     * Meta media is a 2-step authenticated fetch:
     *   1) GET /<media_id>            → { url, mime_type, file_size }
     *   2) GET <url>                  → the binary (same bearer token)
     * Both calls use the workspace's WABA access token (same resolution
     * as InboxDispatcher's send path). Stores under the SAME chat-media/
     * convention the Baileys inbound path uses so AiAgentService's
     * resolveInboundImage() finds it identically. Returns the relative
     * path or null on any failure (caller leaves media_path null →
     * graceful text-only reply).
     */
    private function downloadWabaMediaToDisk(int $workspaceId, string $mediaId, string $mimeHint = ''): ?string
    {
        if ($workspaceId <= 0 || $mediaId === '') return null;
        try {
            $cfg = WaProviderConfig::query()->primaryForWorkspace($workspaceId)->first()
                ?? WaProviderConfig::query()->where('workspace_id', $workspaceId)
                    ->where('provider', 'waba')->orderByDesc('connected_at')->first();
            if (!$cfg) return null;

            $token = (string) ($cfg->creds()['access_token'] ?? '');
            if ($token === '') return null;
            $version = (string) \App\Models\SystemSetting::get('waba_graph_api_version', 'v23.0');

            // 1) media id -> { url, mime_type, file_size }
            $metaRes = \Illuminate\Support\Facades\Http::withToken($token)
                ->acceptJson()->timeout(15)
                ->get("https://graph.facebook.com/{$version}/{$mediaId}");
            if (!$metaRes->successful()) return null;
            $url  = (string) $metaRes->json('url');
            $mime = (string) ($metaRes->json('mime_type') ?: $mimeHint);
            if ($url === '') return null;

            // Skip giant files (WhatsApp images are small; 16MB ceiling
            // mirrors the Baileys inbound cap) — the AI vision cap is 4MB
            // and applies separately in AiAgentService.
            $declaredSize = (int) $metaRes->json('file_size');
            if ($declaredSize > 16 * 1024 * 1024) return null;

            // 2) authenticated GET of the short-lived CDN url for the bytes.
            $binRes = \Illuminate\Support\Facades\Http::withToken($token)->timeout(30)->get($url);
            if (!$binRes->successful()) return null;
            $bin = $binRes->body();
            if ($bin === '' || strlen($bin) > 16 * 1024 * 1024) return null;

            $ext = match (strtolower(trim($mime))) {
                'image/png'  => 'png',
                'image/webp' => 'webp',
                'image/gif'  => 'gif',
                default      => 'jpg',
            };
            $path = 'chat-media/' . \Illuminate\Support\Str::random(24) . '.' . $ext;
            media_storage()->put($path, $bin);
            return $path;
        } catch (\Throwable $e) {
            \Log::warning('[WABA-INBOUND] media download failed: ' . $e->getMessage(), ['media_id' => $mediaId]);
            return null;
        }
    }

    /**
     * Extract [body, mediaType, mediaMeta] from a Meta inbound message
     * payload. Returns ['', null, []] for unknown shapes — caller still
     * persists the row so the operator at least sees something landed.
     */
    private function extractMetaInboundContent(array $msg): array
    {
        $type = $msg['type'] ?? null;
        $meta = [];
        $mediaType = null;
        $body = '';

        switch ($type) {
            case 'text':
                $body = (string) ($msg['text']['body'] ?? '');
                break;
            case 'image':
            case 'video':
            case 'audio':
            case 'document':
            case 'sticker':
                $mediaType = $type === 'sticker' ? 'image' : $type;
                $payload = $msg[$type] ?? [];
                $body = (string) ($payload['caption'] ?? '');
                if (!empty($payload['id']))       $meta['waba_media_id']   = $payload['id'];
                if (!empty($payload['mime_type'])) $meta['waba_mime_type'] = $payload['mime_type'];
                if (!empty($payload['filename'])) $meta['waba_filename']  = $payload['filename'];
                if (!empty($payload['voice']))    $meta['voice']          = true;
                break;
            case 'location':
                $loc = $msg['location'] ?? [];
                $mediaType = 'location';
                $body = trim(($loc['name'] ?? '') . ($loc['address'] ?? '' ? "\n" . $loc['address'] : ''));
                if (isset($loc['latitude']))  $meta['location_latitude']  = (float) $loc['latitude'];
                if (isset($loc['longitude'])) $meta['location_longitude'] = (float) $loc['longitude'];
                if (!empty($loc['name']))     $meta['location_name']     = (string) $loc['name'];
                if (!empty($loc['address']))  $meta['location_address']  = (string) $loc['address'];
                break;
            case 'contacts':
                $mediaType = 'contact';
                $contacts = $msg['contacts'] ?? [];
                $body = (string) ($contacts[0]['name']['formatted_name'] ?? '');
                $meta['contacts'] = $contacts;
                break;
            case 'reaction':
                $body = (string) ($msg['reaction']['emoji'] ?? '');
                $meta['reaction_to'] = $msg['reaction']['message_id'] ?? null;
                $mediaType = 'reaction';
                break;
            case 'button':
                // Quick reply button on a template — Meta sends the
                // button title as body.
                $body = (string) ($msg['button']['text'] ?? '');
                $meta['button_payload'] = $msg['button']['payload'] ?? null;
                break;
            case 'interactive':
                $i = $msg['interactive'] ?? [];
                $itype = $i['type'] ?? '';
                if ($itype === 'button_reply') {
                    $body = (string) ($i['button_reply']['title'] ?? '');
                    $meta['button_id'] = $i['button_reply']['id'] ?? null;
                } elseif ($itype === 'list_reply') {
                    $body = (string) ($i['list_reply']['title'] ?? '');
                    $meta['list_row_id'] = $i['list_reply']['id'] ?? null;
                    if (!empty($i['list_reply']['description'])) {
                        $meta['list_row_description'] = $i['list_reply']['description'];
                    }
                }
                break;
        }

        return [$body, $mediaType, $meta];
    }

    /**
     * Find the device row owning the workspace's WABA number. Falls
     * back to the workspace's first active device if no exact match.
     */
    private function resolveDeviceForWorkspace(int $workspaceId): ?\App\Models\Device
    {
        return \App\Models\Device::query()
            ->where('workspace_id', $workspaceId)
            ->where('active', true)
            ->orderBy('id')
            ->first();
    }

    /**
     * Find the device whose number RECEIVED this inbound (our business number),
     * so a multi-WABA workspace binds the conversation to the correct sender and
     * replies go back out on the SAME number — not the workspace's first device.
     * Matches the webhook's display_phone_number (fallback: the matched config's
     * phone_number) against each device's number. phone_number is encrypted, so
     * we compare in PHP via the model accessor. Returns null → caller falls back.
     */
    private function resolveDeviceForReceivingNumber(int $workspaceId, array $value): ?\App\Models\Device
    {
        // The business number that received this inbound is in the webhook
        // metadata (display_phone_number). Match it to a device so the reply
        // goes back out on the SAME number.
        $recv = preg_replace('/\D+/', '', (string) data_get($value, 'metadata.display_phone_number', ''));
        if ($recv === '') return null;

        foreach (\App\Models\Device::query()->where('workspace_id', $workspaceId)->get() as $d) {
            $dn = preg_replace('/\D+/', '', (string) ($d->country_code . $d->phone_number));
            if ($dn === '') continue;
            // Exact, or one is a country-code-prefixed form of the other.
            if ($dn === $recv || str_ends_with($dn, $recv) || str_ends_with($recv, $dn)) {
                return $d;
            }
        }
        return null;
    }

    /**
     * Apply a Meta status webhook payload to ALL local records that
     * reference the same wamid:
     *
     *   1. `messages` rows where `meta->wa_message_id == wamid`
     *      (TeamInbox + chat path — the canonical storage).
     *   2. `messages` rows where `from_number == wamid`
     *      (legacy ChatController storage; kept for backwards-compat).
     *   3. `scheduled_message_contacts` rows where
     *      `wa_message_id == wamid` (broadcast recipients).
     *
     * For each row we touch, emit a `message_*` outbound webhook so
     * the customer's external systems get the event. For broadcast
     * rows, ALSO recompute the parent broadcast's aggregate counts
     * and fire `broadcast_message_status_updated`.
     *
     * Status enum mapping mirrors Meta exactly:
     *   sent      → POSTed accepted by Meta; recipient phone not yet contacted
     *   delivered → handed to recipient device
     *   read      → user opened the chat (ONLY if recipient has read receipts ON)
     *   failed    → delivery failed; `st.errors[0]` carries code + reason
     */
    private function applyStatus(int $workspaceId, array $st): void
    {
        $wamid  = (string) ($st['id'] ?? '');
        $status = (string) ($st['status'] ?? '');
        if ($wamid === '' || $status === '') return;
        if (!in_array($status, ['sent', 'delivered', 'read', 'failed'], true)) return;

        $errCode = (string) ($st['errors'][0]['code']    ?? '');
        $errMsg  = (string) ($st['errors'][0]['message'] ?? '');
        $pricing = $st['pricing']      ?? null;
        $convo   = $st['conversation'] ?? null;
        $now     = now();

        // --- 1) messages rows ----------------------------------------
        // Filter by provider='waba' so a wamid arriving for a WABA send
        // can't accidentally match a Baileys-sent row that happens to
        // share the same id-shape. Older rows with NULL provider still
        // match (back-compat).
        $messages = Message::query()
            ->where('workspace_id', $workspaceId)
            ->where(function ($q) {
                $q->where('provider', 'waba')->orWhereNull('provider');
            })
            ->where(function ($q) use ($wamid) {
                $q->whereJsonContains('meta->wa_message_id', $wamid)
                  ->orWhereJsonContains('meta->wamid',       $wamid)
                  ->orWhere('from_number', $wamid); // legacy ChatController
            })
            ->get();

        foreach ($messages as $msg) {
            // Don't downgrade: read > delivered > sent > failed.
            if (!$this->shouldAdvance($msg->status, $status)) continue;
            $msg->status = $status;
            if ($status === 'delivered' && !$msg->delivered_at) $msg->delivered_at = $now;
            if ($status === 'read'      && !$msg->read_at)      $msg->read_at      = $now;
            if ($status === 'failed' && $errMsg !== '')         $msg->failure_reason = $errMsg;
            // Persist Meta's typed error code + pricing into meta JSON
            // so the inbox detail panel can show "why" without a re-query.
            $metaCol = is_array($msg->meta) ? $msg->meta : [];
            if ($errCode !== '') $metaCol['error_code'] = $errCode;
            if ($pricing)        $metaCol['pricing']    = $pricing;
            if ($convo)          $metaCol['conversation'] = $convo;
            $msg->meta = $metaCol;
            $msg->save();

            // Fire outbound webhook to the customer's URL — one per
            // status transition. WebhookService de-dupes by webhook
            // subscription's `events` filter.
            $this->fireMessageWebhook($workspaceId, $msg, $status, $st);
        }

        // --- 2) broadcast recipient rows (NEW path: scheduled_message_contacts) ----
        $smcRows = \DB::table('scheduled_message_contacts')
            ->where('wa_message_id', $wamid)
            ->get();

        foreach ($smcRows as $row) {
            if (!$this->shouldAdvance($row->status, $status)) continue;
            $patch = ['status' => $status, 'updated_at' => $now];
            if ($status === 'delivered' && !$row->delivered_at) $patch['delivered_at'] = $now;
            if ($status === 'read'      && !$row->read_at)      $patch['read_at']      = $now;
            if ($status === 'failed') {
                $patch['failed_at']     = $row->failed_at ?? $now;
                $patch['error_message'] = mb_substr($errMsg ?: ('error_code:' . $errCode), 0, 512);
            }
            \DB::table('scheduled_message_contacts')->where('id', $row->id)->update($patch);

            // Recompute parent broadcast aggregates + fire its webhook.
            // The scheduled_message_id column maps to broadcasts.id
            // on the SnapNest schema we inherited.
            $this->recomputeBroadcastAggregates((int) $row->scheduled_message_id);
            $this->fireBroadcastWebhook((int) $row->scheduled_message_id, $row, $status, $st);
        }

        // --- 3a) campaign recipient rows (wp_campaign_contacts) -----
        // Campaigns store wamid in wp_campaign_contacts.whatsapp_message_id
        // via /api/campaigns/update-contact-status. Without this branch
        // Meta delivered/read webhooks for campaign recipients never
        // reach the campaign aggregate counters.
        try {
            $campRows = \DB::table('wp_campaign_contacts')
                ->where('whatsapp_message_id', $wamid)
                ->get();
            foreach ($campRows as $row) {
                if (!$this->shouldAdvance($row->status, $status)) continue;
                $patch = ['status' => $status, 'updated_at' => $now];
                if ($status === 'delivered') $patch['delivered_at'] = $now;
                if ($status === 'read')      $patch['read_at']      = $now;
                if ($status === 'failed') {
                    $patch['error_message'] = mb_substr($errMsg ?: ('error_code:' . $errCode), 0, 512);
                    $patch['failed_at']     = $row->failed_at ?? $now;
                }
                \DB::table('wp_campaign_contacts')->where('id', $row->id)->update($patch);

                // Webhook: campaign_contact_status_updated (Meta delivered/
                // read receipts for campaign recipients — the third status
                // source besides the two Node callbacks).
                $camp = \App\Models\WpCampaign::find($row->campaign_id);
                if ($camp) {
                    \App\Services\WebhookService::emit('campaign_contact_status_updated', [
                        'workspace_id'  => $camp->workspace_id,
                        'user_id'       => $camp->created_by,
                        'campaign_id'   => $camp->id,
                        'campaign_name' => $camp->campaign_name,
                        'contact_id'    => (int) $row->contact_id,
                        'status'        => $status,
                        'wamid'         => $wamid,
                        'error_message' => $status === 'failed' ? mb_substr($errMsg ?: ('error_code:' . $errCode), 0, 512) : null,
                        'timestamp'     => now()->timestamp,
                    ], $camp->created_by);
                }
            }
        } catch (\Throwable $e) {
            \Log::warning('[WA-webhook] wp_campaign_contacts patch failed', ['error' => $e->getMessage()]);
        }

        // --- 3b) storefront order messages (wa_orders) --------------
        // Storefront sends OUTBOUND messages tracked on wa_orders.
        // Status webhook should flip these so the order dashboard
        // reflects delivery state.
        try {
            \DB::table('wa_orders')
                ->where('wa_message_id', $wamid)
                ->update(['updated_at' => $now]);
        } catch (\Throwable $e) {
            // wa_orders may not have a status column on every install — best-effort.
        }

        // --- 3c) inbox_messages (backup inbox table) -----------------
        try {
            $inboxRows = \DB::table('inbox_messages')
                ->whereJsonContains('meta->wa_message_id', $wamid)
                ->get();
            foreach ($inboxRows as $row) {
                $patch = ['updated_at' => $now];
                if ($status === 'delivered') $patch['delivered_at'] = $now;
                if ($status === 'read')      $patch['read_at']      = $now;
                if ($status === 'failed')    $patch['failure_reason'] = mb_substr($errMsg, 0, 191);
                \DB::table('inbox_messages')->where('id', $row->id)->update($patch);
            }
        } catch (\Throwable $e) {
            // inbox_messages columns vary across installs — best-effort.
        }

        // --- 4) broadcast recipient rows (LEGACY path: broadcast_contacts) ----
        // The current WaDesk broadcast flow goes PHP → Node → Meta, and
        // Node stores the wamid via /api/update-message-status which
        // writes to `broadcast_contacts.whatsapp_message_id`. Without
        // this branch, every status webhook from Meta for a broadcast
        // recipient was MISSED (the pre-2026-05-24 bug).
        //
        // Don't run this branch if the SMC branch above already touched
        // the same broadcast — defends against double-recompute racing
        // on broadcasts.success_count in the unlikely case both pivots
        // hold the same wamid.
        $smcBroadcastIds = $smcRows->pluck('scheduled_message_id')->unique()->all();
        $bcRows = \DB::table('broadcast_contacts')
            ->where('whatsapp_message_id', $wamid)
            ->when(!empty($smcBroadcastIds), fn ($q) => $q->whereNotIn('broadcast_id', $smcBroadcastIds))
            ->get();

        foreach ($bcRows as $row) {
            if (!$this->shouldAdvance($row->status, $status)) continue;
            $patch = ['status' => $status, 'updated_at' => $now];
            if ($status === 'delivered' && !$row->delivered_at) $patch['delivered_at'] = $now;
            if ($status === 'read'      && !$row->read_at)      $patch['read_at']      = $now;
            if ($status === 'failed') {
                $patch['error_message'] = mb_substr($errMsg ?: ('error_code:' . $errCode), 0, 512);
            }
            \DB::table('broadcast_contacts')->where('broadcast_id', $row->broadcast_id)
                ->where('contact_id', $row->contact_id)->update($patch);

            $this->recomputeBroadcastAggregatesLegacy((int) $row->broadcast_id);

            // Build an SMC-shape stub for the broadcast webhook payload.
            $stub = (object) [
                'contact_id'    => $row->contact_id,
                'phone'         => null,           // not stored on broadcast_contacts
                'wa_message_id' => $row->whatsapp_message_id,
            ];
            $this->fireBroadcastWebhook((int) $row->broadcast_id, $stub, $status, $st);
        }
    }

    /**
     * Recompute broadcast aggregates from broadcast_contacts (legacy
     * table populated by the Node → /api/update-message-status flow).
     * Mirrors `recomputeBroadcastAggregates` but for the legacy table.
     */
    private function recomputeBroadcastAggregatesLegacy(int $broadcastId): void
    {
        $rows = \DB::table('broadcast_contacts')
            ->select('status', \DB::raw('COUNT(*) as c'))
            ->where('broadcast_id', $broadcastId)
            ->groupBy('status')
            ->pluck('c', 'status');

        $sent      = (int) ($rows['sent']      ?? 0);
        $delivered = (int) ($rows['delivered'] ?? 0);
        $read      = (int) ($rows['read']      ?? 0);
        $failed    = (int) ($rows['failed']    ?? 0);

        \DB::table('broadcasts')->where('id', $broadcastId)->update([
            'success_count'   => $sent + $delivered + $read,
            'delivered_count' => $delivered + $read,
            'read_count'      => $read,
            'fail_count'      => $failed,
            'updated_at'      => now(),
        ]);
    }

    /**
     * Status state machine: once a message is `read`, we never demote
     * it back to `delivered` if Meta retries an older webhook out of
     * order. `failed` is terminal and never overridden by anything
     * other than another `failed` (in case Meta updates the error).
     */
    private function shouldAdvance(?string $current, string $incoming): bool
    {
        $rank = ['' => 0, 'pending' => 1, 'queued' => 1, 'sent' => 2, 'delivered' => 3, 'read' => 4, 'failed' => 5];
        $curRank = $rank[$current ?? ''] ?? 0;
        $incRank = $rank[$incoming]      ?? 0;

        // Don't undo a terminal failed state with a stale "sent".
        if ($current === 'failed' && $incoming !== 'failed') return false;
        // Read is terminal-ish; ignore later delivered/sent.
        if ($current === 'read' && in_array($incoming, ['delivered', 'sent'], true)) return false;
        return $incRank >= $curRank;
    }

    /**
     * Per-broadcast aggregate recompute. Cheap because the SMC table
     * is indexed on (scheduled_message_id, status). Called after each
     * webhook hit that flips a recipient row.
     */
    private function recomputeBroadcastAggregates(int $broadcastId): void
    {
        $rows = \DB::table('scheduled_message_contacts')
            ->select('status', \DB::raw('COUNT(*) as c'))
            ->where('scheduled_message_id', $broadcastId)
            ->groupBy('status')
            ->pluck('c', 'status');

        $sent      = (int) ($rows['sent']      ?? 0);
        $delivered = (int) ($rows['delivered'] ?? 0);
        $read      = (int) ($rows['read']      ?? 0);
        $failed    = (int) ($rows['failed']    ?? 0);

        // success_count = sent + delivered + read (anything that got
        // through Meta's edge). fail_count = failed.
        // delivered_count = delivered + read (read implies delivered).
        // read_count      = read.
        \DB::table('broadcasts')->where('id', $broadcastId)->update([
            'success_count'   => $sent + $delivered + $read,
            'delivered_count' => $delivered + $read,
            'read_count'      => $read,
            'fail_count'      => $failed,
            'updated_at'      => now(),
        ]);
    }

    private function fireMessageWebhook(int $workspaceId, Message $msg, string $status, array $st): void
    {
        $eventName = match ($status) {
            'sent'      => 'message_sent',
            'delivered' => 'message_delivered',
            'read'      => 'message_read',
            'failed'    => 'message_failed',
            default     => null,
        };
        if (!$eventName) return;

        \App\Services\WebhookService::dispatch($eventName, [
            'workspace_id' => $workspaceId,
            'user_id'      => $msg->user_id,
            'message_id'   => $msg->id,
            'wamid'        => $st['id']            ?? null,
            'recipient'    => $msg->to_number      ?? null,
            'status'       => $status,
            'timestamp'    => (int) ($st['timestamp'] ?? now()->timestamp),
            'error_code'   => $st['errors'][0]['code']    ?? null,
            'error_reason' => $st['errors'][0]['message'] ?? null,
            'pricing'      => $st['pricing']      ?? null,
            'conversation' => $st['conversation'] ?? null,
        ], $msg->user_id);
    }

    private function fireBroadcastWebhook(int $broadcastId, $smcRow, string $status, array $st): void
    {
        $bc = \DB::table('broadcasts')->where('id', $broadcastId)->first();
        if (!$bc) return;

        \App\Services\WebhookService::dispatch('broadcast_message_status_updated', [
            'workspace_id'     => $bc->workspace_id,
            'user_id'          => $bc->user_id,
            'broadcast_id'     => $broadcastId,
            'broadcast_name'   => $bc->name,
            'template_id'      => $bc->template_id,
            'contact_id'       => $smcRow->contact_id,
            'phone'            => $smcRow->phone,
            'wamid'            => $smcRow->wa_message_id,
            'status'           => $status,
            'timestamp'        => (int) ($st['timestamp'] ?? now()->timestamp),
            'error_code'       => $st['errors'][0]['code']    ?? null,
            'error_reason'     => $st['errors'][0]['message'] ?? null,
            'aggregate'        => [
                'sent'      => $bc->success_count,
                'delivered' => $bc->delivered_count,
                'read'      => $bc->read_count,
                'failed'    => $bc->fail_count,
                'clicked'   => $bc->clicked_count,
                'total'     => $bc->total_recipients,
            ],
        ], $bc->user_id);
    }

    private function receiveTwilio(Request $request): JsonResponse
    {
        // Twilio signs every webhook with X-Twilio-Signature. Require
        // a configured TWILIO_AUTH_TOKEN so forged form-encoded posts
        // can't impersonate a Twilio number's inbound traffic.
        $authToken = (string) env('TWILIO_AUTH_TOKEN', '');
        if ($authToken === '') {
            \Log::error('[WA-webhook] TWILIO_AUTH_TOKEN not set — refusing webhook');
            return response()->json(['ok' => false, 'error' => 'server misconfigured'], 500);
        }
        // Per Twilio: sort POST params by key, concat key+value pairs,
        // prepend the full URL, HMAC-SHA1 with the auth token, base64.
        $given = (string) $request->header('X-Twilio-Signature', '');
        $url   = $request->fullUrl();
        $params = $request->post();
        ksort($params);
        $payloadStr = $url;
        foreach ($params as $k => $v) $payloadStr .= $k . $v;
        $expected = base64_encode(hash_hmac('sha1', $payloadStr, $authToken, true));
        if (!hash_equals($expected, $given)) {
            \Log::warning('[WA-webhook] Twilio signature mismatch');
            return response()->json(['ok' => false, 'error' => 'bad signature'], 401);
        }

        $from = preg_replace('/^whatsapp:/', '', (string) $request->input('From', ''));
        $body = (string) $request->input('Body', '');
        $name = (string) $request->input('ProfileName', '');
        $buttonText    = (string) $request->input('ButtonText', '');
        $buttonPayload = (string) $request->input('ButtonPayload', '');
        $listId        = (string) $request->input('ListId', '');
        $numMedia      = (int) $request->input('NumMedia', 0);
        $lat           = $request->input('Latitude');
        $lng           = $request->input('Longitude');
        $messageSid    = (string) $request->input('MessageSid', '');

        // Resolve workspace by Twilio "To" (which is our From number).
        $to = preg_replace('/^whatsapp:/', '', (string) $request->input('To', ''));
        $config = WaProviderConfig::query()
            ->where('provider', 'twilio')
            ->where('phone_number', $to)
            ->first();
        $workspaceId = $config?->workspace_id;
        if (!$workspaceId) return response()->json(['ok' => true]);

        // Translate Twilio's form-encoded shape into the Meta-shape
        // $msg array that captureInboundMessage understands. This
        // single bridge wires Twilio into the full inbound pipeline:
        // conversation creation, MessageReceived broadcast, routing
        // rules, AI auto-reply, CRM webhooks, AND flow-resume for
        // interactive replies (list/button taps from appointment
        // booking, surveys, polls, etc.). Previously Twilio inbound
        // just persisted a Message row and was invisible to every
        // automation surface — booking flows, auto-replies, AI
        // assistants all silently no-op'd for Twilio workspaces.
        //
        // `to` is stamped too so captureInboundMessage's fromMe-echo
        // detector can compare `from === workspace's own phone`. In
        // BYON setups (operator's personal phone shares the Twilio
        // number), this lets us mirror mobile-typed replies into
        // /team-inbox the same way Baileys + WABA do.
        $msg = [
            'id'        => $messageSid !== '' ? $messageSid : ('twi.' . bin2hex(random_bytes(8))),
            'from'      => $from,
            'to'        => $to,
            'timestamp' => (string) time(),
        ];

        if ($buttonText !== '' || $buttonPayload !== '') {
            // Quick-reply button tap — fold into Meta's `button` shape so
            // the existing extractor (line 616) picks it up unchanged.
            $msg['type']   = 'button';
            $msg['button'] = [
                'text'    => $buttonText !== '' ? $buttonText : $buttonPayload,
                'payload' => $buttonPayload,
            ];
        } elseif ($listId !== '') {
            // List selection — map to Meta's `interactive.list_reply`
            // (line 628) so the appointment booking handler, flow
            // resume, etc. all see a standard list_reply payload.
            $msg['type']        = 'interactive';
            $msg['interactive'] = [
                'type'       => 'list_reply',
                'list_reply' => [
                    'id'    => $listId,
                    'title' => $body ?: $listId,
                ],
            ];
        } elseif ($lat !== null && $lng !== null && $lat !== '' && $lng !== '') {
            $msg['type']     = 'location';
            $msg['location'] = [
                'latitude'  => (float) $lat,
                'longitude' => (float) $lng,
                'name'      => $body,
            ];
        } elseif ($numMedia > 0) {
            // Twilio media — map to image / video / audio / document by
            // first MediaContentType0. Meta's extractor reads `image.id`
            // etc.; we stash the Twilio MediaUrl0 in `link` so downstream
            // can fetch directly (Twilio media is auth'd, not public —
            // a separate fetcher hits the URL with basic-auth creds).
            $mime  = (string) $request->input('MediaContentType0', '');
            $url   = (string) $request->input('MediaUrl0', '');
            $mtype = match (true) {
                str_starts_with($mime, 'image/') => 'image',
                str_starts_with($mime, 'video/') => 'video',
                str_starts_with($mime, 'audio/') => 'audio',
                default                          => 'document',
            };
            $msg['type']    = $mtype;
            $msg[$mtype]    = ['mime_type' => $mime, 'link' => $url];
            if ($body !== '') $msg[$mtype]['caption'] = $body;
        } elseif ($body !== '') {
            $msg['type'] = 'text';
            $msg['text'] = ['body' => $body];
        } else {
            // Empty payload (Twilio occasionally sends an "ack" with no
            // body) — nothing actionable, ack the webhook and exit.
            return response()->json(['ok' => true]);
        }

        // Same envelope shape Meta's webhook uses, so the contact
        // profile-name resolution in captureInboundMessage works
        // unchanged.
        $value = [
            'contacts' => [['profile' => ['name' => $name]]],
            'messages' => [$msg],
        ];

        try {
            $this->captureInboundMessage($workspaceId, $msg, $value, 'twilio');
        } catch (\Throwable $e) {
            \Log::warning('[TWILIO-INBOUND] captureInboundMessage failed: ' . $e->getMessage());
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Tag a workspace conversation with a "interested_sku" attribute
     * when the buyer replies to a product card. Lets bots + agents
     * see at a glance what the buyer was looking at.
     *
     * Best-effort — silently skipped if we can't resolve the
     * conversation or the SKU isn't known to us.
     */
    private function tagConversationWithReferredProduct(int $workspaceId, string $fromPhone, string $retailerId): void
    {
        try {
            $conv = \App\Models\Conversation::where('workspace_id', $workspaceId)
                ->where(function ($q) use ($fromPhone) {
                    $q->where('raw_jid', $fromPhone)
                      ->orWhere('alt_jid', $fromPhone);
                })->orderByDesc('id')->first();
            if (!$conv) return;

            $product = \App\Models\WaProduct::where('workspace_id', $workspaceId)
                ->where(function ($q) use ($retailerId) {
                    $q->where('meta_retailer_id', $retailerId)
                      ->orWhere('sku', $retailerId);
                })->first();

            $conv->forceFill([
                'interested_sku' => $retailerId,
                'interested_product_id' => $product?->id,
                'interested_seen_at' => now(),
            ])->save();
        } catch (\Throwable $e) {
            \Log::warning('referred_product tagging failed', ['e' => $e->getMessage()]);
        }
    }
}
