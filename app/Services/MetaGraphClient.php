<?php

namespace App\Services;

use App\Models\MetaCampaign;
use App\Models\SystemSetting;
use App\Models\WaProviderConfig;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Meta Marketing Graph API client — full CTWA (Click-to-WhatsApp)
 * pipeline.
 *
 * Pre-2026-05-24 this class only POSTed to `/{account}/campaigns` and
 * stopped — Meta needs FIVE entities for a real CTWA ad to run:
 *
 *   1. POST /act_{id}/adimages    → image_hash
 *   2. POST /act_{id}/campaigns   → campaign_id (PAUSED)
 *   3. POST /act_{id}/adsets      → ad_set_id   (destination_type=WHATSAPP, promoted_object)
 *   4. POST /act_{id}/adcreatives → creative_id (object_story_spec.link_data with WHATSAPP_MESSAGE CTA)
 *   5. POST /act_{id}/ads         → ad_id       (links creative + adset)
 *
 * Each id is persisted on `meta_campaigns` so we can edit/toggle/
 * delete the whole tree later. Rollback on partial failure happens
 * in the controller — this client just reports per-step errors.
 *
 * Token + account resolution:
 *   - Per-workspace via WaProviderConfig.meta_json.fb_ad_account_id +
 *     credentials_json.ads_token (or access_token fallback).
 *   - Platform-default fallback to env() for single-tenant installs.
 *
 * Graph API version comes from system_settings.meta_ads_graph_api_version
 * (defaults to v23.0). Admin can bump via /admin/settings/wadesk-message
 * without redeploying.
 */
class MetaGraphClient
{
    public array $lastError = [];

    private string $version;
    private string $token;
    private string $account;     // 'act_{id}'
    private ?string $pageId      = null;
    private ?string $wabaId      = null;
    private ?string $phoneId     = null;   // Meta's internal phone_number_id (used by WABA Cloud sends)
    private ?string $phoneDigits = null;   // Actual E.164 digits (used by Marketing API promoted_object + wa.me link)
    private ?WaProviderConfig $cfg = null;

    public function __construct(?WaProviderConfig $cfg = null)
    {
        $this->cfg = $cfg;
        $this->version = (string) SystemSetting::get('meta_ads_graph_api_version', 'v23.0');

        if ($cfg) {
            $creds = $cfg->creds();
            $meta  = is_array($cfg->meta_json) ? $cfg->meta_json : [];
            $this->token   = (string) ($creds['ads_token'] ?? $creds['access_token'] ?? '');
            $this->account = 'act_' . preg_replace('/^act_/', '', (string) ($meta['fb_ad_account_id'] ?? ''));
            $this->pageId  = (string) ($meta['fb_page_id'] ?? '') ?: null;
            $this->wabaId  = (string) ($meta['waba_id'] ?? '') ?: null;
            // Meta's INTERNAL phone_number_id — used by WABA Cloud message
            // sends (graph.facebook.com/<phone_number_id>/messages).
            $this->phoneId = (string) ($meta['phone_number_id'] ?? '') ?: null;
            // Actual E.164 phone digits — used by Marketing API
            // promoted_object.whatsapp_phone_number AND the wa.me link
            // in the ad creative. Meta rejects phone_number_id here with
            // "WhatsApp phone number is not linked to your account".
            // Pull from meta_json.display_phone_number first (canonical),
            // fall back to wa_provider_configs.phone_number column.
            $rawDigits = (string) ($meta['display_phone_number']
                ?? $cfg->phone_number
                ?? $creds['phone_number']
                ?? '');
            $rawDigits = preg_replace('/\D+/', '', $rawDigits);
            $this->phoneDigits = $rawDigits !== '' ? $rawDigits : null;
        } else {
            $this->token   = '';
            $this->account = 'act_';
            $this->pageId  = null;
            $this->wabaId  = null;
            $this->phoneId = null;
            $this->phoneDigits = null;
        }

        // Admin fallback. The workspace's OWN Meta Ads keys always win;
        // anything the workspace left blank falls back to the platform
        // admin's global Meta Ads credentials (set at
        // /admin/meta-ads/keys). This is per-field so a workspace that
        // configured only an ad account can still borrow the admin
        // token, etc. Done last so it never overrides a real workspace
        // value.
        $this->applyAdminFallback();
    }

    /**
     * Fill any credential the workspace config left blank from the
     * admin-configured global Meta Ads keys. Workspace values are never
     * overwritten — this only ever fills gaps.
     */
    private function applyAdminFallback(): void
    {
        $fb = self::adminFallbackKeys();
        if (empty($fb['token']) && empty($fb['ad_account_id'])) return; // nothing configured

        if ($this->token === '' && !empty($fb['token'])) {
            $this->token = (string) $fb['token'];
        }
        if ($this->account === 'act_' && !empty($fb['ad_account_id'])) {
            $this->account = 'act_' . preg_replace('/^act_/', '', (string) $fb['ad_account_id']);
        }
        if ($this->pageId === null && !empty($fb['page_id'])) {
            $this->pageId = (string) $fb['page_id'];
        }
        if ($this->wabaId === null && !empty($fb['waba_id'])) {
            $this->wabaId = (string) $fb['waba_id'];
        }
        if ($this->phoneId === null && !empty($fb['phone_number_id'])) {
            $this->phoneId = (string) $fb['phone_number_id'];
        }
        if ($this->phoneDigits === null && !empty($fb['phone'])) {
            $d = preg_replace('/\D+/', '', (string) $fb['phone']);
            $this->phoneDigits = $d !== '' ? $d : null;
        }
    }

    /**
     * Admin global Meta Ads fallback credentials, read from
     * system_settings (set on /admin/meta-ads/keys). Token is stored
     * encrypted; SystemSetting::get transparently decrypts.
     *
     * @return array{token:string,ad_account_id:string,page_id:string,phone:string,waba_id:string,phone_number_id:string}
     */
    public static function adminFallbackKeys(): array
    {
        return [
            'token'           => (string) SystemSetting::get('meta_ads.token', ''),
            'ad_account_id'   => (string) SystemSetting::get('meta_ads.ad_account_id', ''),
            'page_id'         => (string) SystemSetting::get('meta_ads.page_id', ''),
            'phone'           => (string) SystemSetting::get('meta_ads.phone', ''),
            'waba_id'         => (string) SystemSetting::get('meta_ads.waba_id', ''),
            'phone_number_id' => (string) SystemSetting::get('meta_ads.phone_number_id', ''),
        ];
    }

    public function isConfigured(): bool
    {
        return $this->token !== '' && $this->account !== 'act_';
    }

    /**
     * True if we have everything CTWA-specific needs (page + WABA +
     * BOTH phone identifiers). Marketing API needs raw digits;
     * WABA Cloud sends need the phone_number_id. Without both, the ad
     * either creates with the wrong route or can't be created at all.
     */
    public function isCtwaReady(): bool
    {
        return $this->isConfigured()
            && $this->pageId !== null
            && $this->wabaId !== null
            && $this->phoneId !== null
            && $this->phoneDigits !== null;
    }

    public function adAccountId(): string
    {
        return $this->account;
    }

    public function pageId(): ?string
    {
        return $this->pageId;
    }

    // =================================================================
    // STEP 1 — Image upload (POST /act_{id}/adimages)
    // =================================================================

    /**
     * Upload an image to Meta's ad image library, return the hash.
     * Hash is then referenced as `image_hash` in the ad creative.
     *
     * @param  string  $localPath  absolute path to a JPG/PNG/GIF
     * @return string  image_hash from Meta
     * @throws RuntimeException on failure
     */
    public function uploadImage(string $localPath): string
    {
        $this->requireConfigured();
        if (!is_readable($localPath)) {
            throw new RuntimeException("Image not readable: {$localPath}");
        }

        $resp = Http::withToken($this->token)
            ->timeout(30)
            ->attach('source', file_get_contents($localPath), basename($localPath))
            ->post($this->endpoint("{$this->account}/adimages"));

        $this->stash($resp, 'uploadImage', ['path' => $localPath]);

        if (!$resp->successful()) {
            throw new RuntimeException($this->errorHint($resp));
        }

        // Meta returns `{ images: { <filename>: { hash, url } } }`.
        $images = (array) $resp->json('images', []);
        $first  = $images ? array_values($images)[0] : null;
        $hash   = (string) ($first['hash'] ?? '');
        if ($hash === '') {
            throw new RuntimeException('Meta uploaded image but returned no hash.');
        }
        return $hash;
    }

    // =================================================================
    // STEP 2 — Campaign (POST /act_{id}/campaigns)
    // =================================================================

    public function createCampaign(MetaCampaign $c): string
    {
        $this->requireConfigured();

        $resp = Http::withToken($this->token)
            ->acceptJson()
            ->timeout(15)
            ->post($this->endpoint("{$this->account}/campaigns"), [
                'name'                  => (string) $c->name,
                'objective'             => $this->normalizeObjective((string) $c->objective),
                // special_ad_categories MUST be an array since v18+.
                // Use [] for normal ads, ["HOUSING"|"CREDIT"|"EMPLOYMENT"|"ISSUES_ELECTIONS_POLITICS"]
                // for restricted categories.
                'special_ad_categories' => [],
                'status'                => 'PAUSED',
                'buying_type'           => 'AUCTION',
            ]);

        $this->stash($resp, 'createCampaign', ['name' => $c->name]);
        if (!$resp->successful()) throw new RuntimeException($this->errorHint($resp));
        return (string) $resp->json('id');
    }

    // =================================================================
    // STEP 3 — Ad Set (POST /act_{id}/adsets)
    // =================================================================

    /**
     * Create an ad set with CTWA destination + promoted_object linking
     * the WABA phone, and basic targeting derived from the local
     * campaign row.
     */
    public function createAdSet(MetaCampaign $c, string $campaignId): string
    {
        $this->requireConfigured();

        $dailyBudgetCents = (int) round(((float) ($c->daily_budget ?? 5)) * 100);
        $startTime  = optional($c->start_date)->copy()?->setTime(0, 1)->toIso8601String() ?: now()->addMinutes(5)->toIso8601String();
        $endTime    = optional($c->end_date)?->copy()?->setTime(23, 59)->toIso8601String();

        // Honour the form's `adset_name` override (stored in
        // targeting['_adset_name'] since there's no dedicated column).
        // Falls back to the campaign name as the display label, not the
        // older "— Ad Set" suffix which polluted Meta's Ads Manager UI.
        $t              = is_array($c->targeting) ? $c->targeting : [];
        $adsetNameOverride = trim((string) ($t['_adset_name'] ?? ''));
        $adsetName      = $adsetNameOverride !== '' ? $adsetNameOverride : (string) $c->name;

        $adType = $c->adType();

        $payload = [
            'name'              => $adsetName,
            'campaign_id'       => $campaignId,
            'daily_budget'      => max(100, $dailyBudgetCents), // Meta minimum
            'billing_event'     => 'IMPRESSIONS',
            'optimization_goal' => $this->adsetOptimizationGoal($c),
            'bid_strategy'      => 'LOWEST_COST_WITHOUT_CAP',
            'status'            => 'PAUSED',
            'start_time'        => $startTime,
            'targeting'         => $this->buildTargeting($c),
        ];
        if ($endTime) $payload['end_time'] = $endTime;

        // Destination + promoted_object by ad type.
        if ($adType === MetaCampaign::AD_TYPE_CTWA) {
            // Click-to-WhatsApp. destination_type=WHATSAPP needs a
            // promoted_object identifying WHICH WhatsApp number routes.
            //
            // CRITICAL: `whatsapp_phone_number` takes the ACTUAL E.164
            // digits (e.g. "919876543210"), NOT Meta's internal
            // phone_number_id — that causes "This WhatsApp phone number
            // is not linked to your account". Priority: form's ctwa_phone
            // override → workspace primary WABA digits.
            $payload['destination_type'] = 'WHATSAPP';
            $overrideDigits = preg_replace('/\D+/', '', (string) ($c->ctwa_phone ?? ''));
            $resolvedDigits = $overrideDigits !== '' ? $overrideDigits : $this->phoneDigits;
            if ($this->pageId)   $payload['promoted_object']['page_id'] = $this->pageId;
            if ($resolvedDigits) $payload['promoted_object']['whatsapp_phone_number'] = $resolvedDigits;
        }
        // else: plain link ad (traffic/awareness) — no messaging
        // destination and no promoted_object (LINK_CLICKS/REACH don't
        // require one and Meta rejects an unexpected promoted_object).

        $resp = Http::withToken($this->token)
            ->acceptJson()
            ->timeout(15)
            ->post($this->endpoint("{$this->account}/adsets"), $payload);

        $this->stash($resp, 'createAdSet', ['campaign_id' => $campaignId]);
        if (!$resp->successful()) throw new RuntimeException($this->errorHint($resp));
        return (string) $resp->json('id');
    }

    // =================================================================
    // STEP 4 — Ad Creative (POST /act_{id}/adcreatives)
    // =================================================================

    /**
     * Build the CTWA ad creative with the exact `object_story_spec`
     * shape Meta documents:
     *
     *   object_story_spec.page_id
     *   object_story_spec.link_data.{name,message,description,image_hash,link}
     *   object_story_spec.link_data.page_welcome_message
     *   object_story_spec.link_data.call_to_action.type = WHATSAPP_MESSAGE
     *   object_story_spec.link_data.call_to_action.value.app_destination = WHATSAPP
     */
    public function createAdCreative(MetaCampaign $c, string $imageHash): string
    {
        $this->requireConfigured();
        if (!$this->pageId) {
            throw new RuntimeException('Cannot build ad creative — workspace has no fb_page_id configured.');
        }

        // Read from the columns the form actually populates:
        //   form creative_title → MetaCampaign.creative_title
        //   form creative_body  → MetaCampaign.creative_body
        $adType   = $c->adType();
        $headline = (string) ($c->creative_title ?: $c->name);
        $body     = (string) ($c->creative_body  ?: '');

        if ($adType === MetaCampaign::AD_TYPE_CTWA) {
            // Click-to-WhatsApp. link_data.link MUST be the wa.me/<digits>
            // deep-link (phone_number_id renders unreachable on tap).
            $waLink = $this->phoneDigits ? 'https://wa.me/' . $this->phoneDigits : 'https://wa.me/';
            $cta    = (string) ($c->ctwa_cta ?: 'WHATSAPP_MESSAGE');
            $linkData = [
                'name'                 => $headline,
                'message'              => $body,
                'image_hash'           => $imageHash,
                'link'                 => $waLink,
                'page_welcome_message' => (string) ($c->ctwa_message ?: 'Hi, I saw your ad and I\'m interested. Can you tell me more?'),
                'call_to_action'       => [
                    'type'  => $cta,
                    'value' => ['app_destination' => 'WHATSAPP'],
                ],
            ];
        } else {
            // Plain link ad (traffic / awareness) to a website / landing page.
            $cta  = (string) ($c->ctwa_cta && $c->ctwa_cta !== 'WHATSAPP_MESSAGE' ? $c->ctwa_cta : 'LEARN_MORE');
            $link = (string) ($c->creative_link_url ?: 'https://');
            $linkData = [
                'name'           => $headline,
                'message'        => $body,
                'image_hash'     => $imageHash,
                'link'           => $link,
                'call_to_action' => ['type' => $cta],
            ];
        }

        // object_story_spec: page_id is the Facebook Page identity. Instagram
        // has been removed, so ads always run with the Facebook Page identity.
        $objectStorySpec = ['page_id' => $this->pageId, 'link_data' => $linkData];

        $resp = Http::withToken($this->token)
            ->acceptJson()
            ->timeout(15)
            ->post($this->endpoint("{$this->account}/adcreatives"), [
                'name'              => (string) $c->name . ' — Creative',
                'object_story_spec' => $objectStorySpec,
            ]);

        $this->stash($resp, 'createAdCreative', ['name' => $c->name]);
        if (!$resp->successful()) throw new RuntimeException($this->errorHint($resp));
        return (string) $resp->json('id');
    }

    // =================================================================
    // STEP 5 — Ad (POST /act_{id}/ads)
    // =================================================================

    public function createAd(MetaCampaign $c, string $adSetId, string $creativeId): string
    {
        $this->requireConfigured();

        $resp = Http::withToken($this->token)
            ->acceptJson()
            ->timeout(15)
            ->post($this->endpoint("{$this->account}/ads"), [
                'name'        => (string) $c->name . ' — Ad',
                'adset_id'    => $adSetId,
                'creative'    => ['creative_id' => $creativeId],
                'status'      => 'PAUSED',
            ]);

        $this->stash($resp, 'createAd', ['adset' => $adSetId]);
        if (!$resp->successful()) throw new RuntimeException($this->errorHint($resp));
        return (string) $resp->json('id');
    }

    // =================================================================
    // Lifecycle — status toggle / delete / insights
    // =================================================================

    /**
     * Toggle status on a Meta entity (campaign, adset, or ad).
     * Same endpoint shape for all three — different id types.
     */
    public function setStatus(string $entityId, string $status): bool
    {
        if (!$this->isConfigured() || $entityId === '') return false;

        try {
            $resp = Http::withToken($this->token)
                ->acceptJson()
                ->timeout(10)
                ->post($this->endpoint($entityId), ['status' => $status]);
            $this->stash($resp, 'setStatus', ['id' => $entityId, 'status' => $status]);
            return $resp->successful();
        } catch (\Throwable $e) {
            Log::warning('Meta setStatus threw', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Set status on the campaign + propagate to adset + ad so the
     * whole tree pauses/activates together. Returns true if ALL legs
     * succeeded.
     */
    public function setStatusCascade(MetaCampaign $c, string $status): bool
    {
        $ok = true;
        if ($c->facebook_id)      $ok = $this->setStatus($c->facebook_id, $status) && $ok;
        if ($c->meta_adset_id)    $ok = $this->setStatus($c->meta_adset_id, $status) && $ok;
        if ($c->meta_ad_id)       $ok = $this->setStatus($c->meta_ad_id, $status) && $ok;
        return $ok;
    }

    public function fetchInsights(string $campaignId): array
    {
        if (!$this->isConfigured() || $campaignId === '') return [];

        try {
            $resp = Http::withToken($this->token)
                ->acceptJson()
                ->timeout(10)
                ->get($this->endpoint("{$campaignId}/insights"), [
                    'fields'      => 'impressions,clicks,spend,reach,cpc,cpm,ctr,frequency,actions',
                    'date_preset' => 'last_7d',
                ]);
            $this->stash($resp, 'fetchInsights', ['id' => $campaignId]);
            if (!$resp->successful()) return [];

            $row = $resp->json('data.0') ?? [];
            return [
                'spend'       => (float) ($row['spend']       ?? 0),
                'impressions' => (int)   ($row['impressions'] ?? 0),
                'clicks'      => (int)   ($row['clicks']      ?? 0),
                'reach'       => (int)   ($row['reach']       ?? 0),
                'conversions' => $this->extractConversations($row['actions'] ?? []),
                'ctr'         => (float) ($row['ctr']         ?? 0),
                'cpc'         => (float) ($row['cpc']         ?? 0),
                'cpm'         => (float) ($row['cpm']         ?? 0),
                'frequency'   => (float) ($row['frequency']   ?? 0),
                'revenue'     => 0.0,
            ];
        } catch (\Throwable $e) {
            Log::warning('Meta fetchInsights threw', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Delete the full CTWA tree in reverse order (Ad → Creative →
     * Ad Set → Campaign). Each leg is best-effort — losing a remote
     * entity is worse than partial cleanup.
     */
    public function deleteCascade(MetaCampaign $c): bool
    {
        $ok = true;
        foreach ([$c->meta_ad_id, $c->meta_creative_id, $c->meta_adset_id, $c->facebook_id] as $id) {
            if (!$id) continue;
            try {
                $resp = Http::withToken($this->token)->timeout(10)->delete($this->endpoint($id));
                $ok = $resp->successful() && $ok;
            } catch (\Throwable $e) {
                $ok = false;
            }
        }
        return $ok;
    }

    /** @deprecated — kept for back-compat with old callers; use deleteCascade. */
    public function deleteCampaign(string $facebookId): bool
    {
        if (!$this->isConfigured() || $facebookId === '') return false;
        try {
            return Http::withToken($this->token)->timeout(10)->delete($this->endpoint($facebookId))->successful();
        } catch (\Throwable $e) {
            return false;
        }
    }

    // =================================================================
    // Helpers
    // =================================================================

    private function endpoint(string $path): string
    {
        return "https://graph.facebook.com/{$this->version}/{$path}";
    }

    private function requireConfigured(): void
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('Meta Ads not configured. Connect a Meta Business account at /devices first.');
        }
    }

    private function stash(Response $resp, string $op, array $context): void
    {
        $this->lastError = [
            'op'      => $op,
            'status'  => $resp->status(),
            'body'    => $resp->json() ?? $resp->body(),
            'context' => $context,
        ];
    }

    /**
     * Convert OUTCOME_xxx objectives stored locally to whatever the
     * current Marketing API version accepts. v23 still accepts the
     * legacy 2024 names (LINK_CLICKS, MESSAGES, etc.) but v25 enforces
     * the OUTCOME_ prefix. We normalise both ways defensively.
     */
    private function normalizeObjective(string $objective): string
    {
        $map = [
            // Legacy → OUTCOME_*
            'LINK_CLICKS'       => 'OUTCOME_TRAFFIC',
            'MESSAGES'          => 'OUTCOME_ENGAGEMENT',
            'CONVERSIONS'       => 'OUTCOME_SALES',
            'LEAD_GENERATION'   => 'OUTCOME_LEADS',
            'BRAND_AWARENESS'   => 'OUTCOME_AWARENESS',
            'REACH'             => 'OUTCOME_AWARENESS',
            'VIDEO_VIEWS'       => 'OUTCOME_ENGAGEMENT',
        ];
        return $map[strtoupper($objective)] ?? strtoupper($objective);
    }

    /**
     * Build a Meta `targeting` object from the local row. Hands the
     * absolute minimum so Meta accepts the ad set; the controller can
     * supply richer targeting once the UI gives users a way to pick
     * detailed interests.
     */
    private function buildTargeting(MetaCampaign $c): array
    {
        // Targeting fields live INSIDE the model's encrypted-array
        // `targeting` column (the form mapper packs countries / age /
        // gender / interests in there). Old code referenced $c->countries
        // / $c->age_min / $c->gender directly — those aren't columns,
        // so every user-typed targeting was silently dropped and Meta
        // got the hardcoded ['IN','US'] / 18 / 65 / all-genders default.
        $t = is_array($c->targeting) ? $c->targeting : [];

        $countries = (array) ($t['countries'] ?? []);
        if (empty($countries)) $countries = ['IN', 'US'];
        $countries = array_values(array_map('strtoupper', $countries));

        $payload = [
            'geo_locations'        => ['countries' => $countries],
            'age_min'              => max(13, (int) ($t['age_min'] ?: 18)),
            'age_max'              => min(65, (int) ($t['age_max'] ?: 65)),
            'genders'              => $this->genders($t['gender'] ?? null),
            'targeting_automation' => ['advantage_audience' => 1],
        ];

        // Interests — form ships pure NAMES from the curated catalog
        // (config/meta_targeting.php). Meta's targeting API wants
        // `{id, name}` pairs, so we resolve each name to its current
        // Meta interest ID via the Targeting Search endpoint and ship
        // the resolved pairs. Results are cached per-name for 7 days so
        // repeated ad submits don't re-hit the API. Names that don't
        // resolve are silently dropped — the rest of the targeting
        // still ships, broadening the audience instead of failing.
        $picked = (array) ($t['interests'] ?? []);
        $picked = array_values(array_filter(array_map('trim', array_map('strval', $picked))));
        if (!empty($picked)) {
            $resolved = $this->resolveInterests($picked);
            if (!empty($resolved)) {
                $payload['interests'] = $resolved;
            }
        }

        // Placement — pinned to Facebook only (Instagram removed). The
        // campaign always stores publisher_platforms = ['facebook'].
        $platforms = array_values(array_intersect(
            array_map('strtolower', (array) ($c->publisher_platforms ?? [])),
            ['facebook', 'audience_network', 'messenger']
        ));
        if (empty($platforms)) $platforms = ['facebook'];
        $payload['publisher_platforms'] = $platforms;

        // Drop any underscore-prefixed keys from the targeting array —
        // those are local metadata stashed alongside the real targeting
        // fields (e.g. `_adset_name`). Sending unknown keys to Meta
        // results in a 100/Bad parameter rejection.
        foreach (array_keys($payload) as $k) {
            if (is_string($k) && str_starts_with($k, '_')) unset($payload[$k]);
        }

        return $payload;
    }

    /**
     * The ad-set optimization_goal per ad type. Messaging ads (CTWA +
     * Instagram-Direct) optimize for CONVERSATIONS (started chats);
     * plain link ads optimize for LINK_CLICKS, or REACH when the user
     * picked a reach/awareness goal. Both link goals are pixel-free and
     * reliable — richer goals (offsite conversions, leads) need a pixel
     * and are intentionally out of scope here.
     */
    private function adsetOptimizationGoal(MetaCampaign $c): string
    {
        if ($c->isMessagingAd()) {
            return 'CONVERSATIONS';
        }
        // REACH + BRAND_AWARENESS both run under OUTCOME_AWARENESS, whose
        // valid goal is REACH (LINK_CLICKS belongs to OUTCOME_TRAFFIC and
        // would be rejected). Everything else → LINK_CLICKS (traffic).
        return in_array(strtoupper((string) $c->optimization_goal), ['REACH', 'BRAND_AWARENESS'], true)
            ? 'REACH'
            : 'LINK_CLICKS';
    }

    private function genders($g): array
    {
        return match (strtolower((string) $g)) {
            'male'   => [1],
            'female' => [2],
            default  => [1, 2],
        };
    }

    /**
     * Resolve a list of interest NAMES (from the curated catalog) to
     * Meta `{id, name}` objects via the Targeting Search endpoint.
     *
     * Each name is cached for 7 days under the running Graph API
     * version so a Meta-side ID rename doesn't trap us on stale data
     * forever. Names that don't return a match are skipped — better to
     * ship a slightly broader audience than to fail the whole ad.
     */
    /**
     * List existing campaigns in the connected ad account — powers the
     * "Fetch from Meta" import so ads created directly in Ads Manager show
     * up in WaDesk with their stats. Insights are nested (lifetime) so the
     * import gets analytics in a single round-trip.
     */
    /** Last error from listCampaigns() (Meta permission/API message) — surfaced inline to the user. */
    public ?string $lastListError = null;

    public function listCampaigns(int $limit = 100): array
    {
        $this->lastListError = null;
        if (!$this->isConfigured()) {
            $this->lastListError = 'Meta ad account not connected — add your Ad Account ID + access token in Keys.';
            Log::warning('[META-IMPORT] not configured', ['account' => $this->account, 'has_token' => $this->token !== '']);
            return [];
        }
        try {
            // Basic fields ONLY — a nested insights expansion can error out the
            // WHOLE request (returning zero campaigns); insights are pulled
            // per-campaign by the caller instead. effective_status widens the
            // result to include paused/archived/issue campaigns too.
            $resp = Http::withToken($this->token)->acceptJson()->timeout(25)
                ->get($this->endpoint("{$this->account}/campaigns"), [
                    'fields'           => 'id,name,objective,status,effective_status,daily_budget,lifetime_budget,created_time',
                    'effective_status' => json_encode([
                        'ACTIVE', 'PAUSED', 'CAMPAIGN_PAUSED', 'ADSET_PAUSED', 'ARCHIVED',
                        'IN_PROCESS', 'WITH_ISSUES', 'PENDING_REVIEW', 'DISAPPROVED',
                    ]),
                    'limit'            => max(1, min(500, $limit)),
                ]);
            $data = (array) $resp->json('data', []);
            Log::info('[META-IMPORT] list campaigns', [
                'account' => $this->account,
                'http'    => $resp->status(),
                'ok'      => $resp->successful(),
                'count'   => count($data),
                'error'   => $resp->json('error.message'),
                'code'    => $resp->json('error.code'),
            ]);
            if (!$resp->successful()) {
                $this->lastListError = (string) ($resp->json('error.message') ?? 'Meta API returned an error.');
            }
            return $resp->successful() ? $data : [];
        } catch (\Throwable $e) {
            $this->lastListError = $e->getMessage();
            Log::warning('[META-IMPORT] list exception: ' . $e->getMessage());
            return [];
        }
    }

    private function resolveInterests(array $names): array
    {
        $out = [];
        foreach ($names as $name) {
            $key = 'meta_interest:' . md5($this->version . '|' . mb_strtolower($name));
            $hit = Cache::remember($key, now()->addDays(7), function () use ($name) {
                try {
                    $resp = Http::withToken($this->token)
                        ->acceptJson()
                        ->timeout(8)
                        ->get($this->endpoint('search'), [
                            'type'  => 'adinterest',
                            'q'     => $name,
                            'limit' => 1,
                        ]);
                    if (!$resp->successful()) return null;
                    $row = $resp->json('data.0');
                    if (!is_array($row) || empty($row['id'])) return null;
                    return ['id' => (string) $row['id'], 'name' => (string) ($row['name'] ?? $name)];
                } catch (\Throwable $e) {
                    Log::warning('Meta interest resolve failed', ['name' => $name, 'error' => $e->getMessage()]);
                    return null;
                }
            });
            if (is_array($hit) && !empty($hit['id'])) $out[] = $hit;
        }
        return $out;
    }

    /**
     * Pluck the CTWA "conversation started" count from Meta's actions
     * array. For CTWA campaigns this is the meaningful conversion
     * metric — clicks that turned into a real WhatsApp conversation.
     *
     * Meta surfaces this under several action_type names since the
     * Oct-2024 Insights API consolidation. Resolution order:
     *
     *   1. `onsite_conversion.messaging_conversation_started_7d`
     *      (the canonical CTWA metric Meta kept post-cleanup)
     *   2. `onsite_conversion.total_messaging_connection`
     *      (Meta's "new messaging connections" replacement metric for
     *      accounts that have it enabled)
     *   3. `onsite_conversion.messaging_first_reply`
     *      (older surface, still emitted for some accounts)
     *
     * Returns the FIRST tier that has rows, summed (1d/7d/28d windows
     * all appear as separate rows of the same action_type — picking
     * one is wrong, summing across windows would double-count, so we
     * filter to action_type WITHOUT a window suffix and take its value).
     */
    private function extractConversations(array $actions): int
    {
        $priority = [
            'onsite_conversion.messaging_conversation_started_7d',
            'onsite_conversion.total_messaging_connection',
            'onsite_conversion.messaging_first_reply',
        ];
        // Substring matchers — Meta sometimes emits unprefixed variants
        // (e.g. just "messaging_conversation_started_7d"); fall through
        // to a `str_contains` pass if exact match misses everything.
        foreach ($priority as $target) {
            foreach ($actions as $a) {
                if (($a['action_type'] ?? '') === $target) {
                    return (int) ($a['value'] ?? 0);
                }
            }
        }
        foreach (['messaging_conversation_started', 'total_messaging_connection', 'messaging_first_reply'] as $needle) {
            foreach ($actions as $a) {
                if (str_contains((string) ($a['action_type'] ?? ''), $needle)) {
                    return (int) ($a['value'] ?? 0);
                }
            }
        }
        return 0;
    }

    /**
     * Translate Meta's typed error envelope into actionable copy.
     * Mirrors WaConnectController's helper but for Marketing API
     * codes (per Meta docs Q1 2026).
     */
    private function errorHint(Response $resp): string
    {
        $err     = (array) $resp->json('error', []);
        $code    = (int) ($err['code']          ?? 0);
        $sub     = (int) ($err['error_subcode'] ?? 0);
        $msg     = (string) ($err['message']    ?? 'Unknown Meta error.');
        $userMsg = (string) ($err['error_user_msg'] ?? '');

        $hint = match (true) {
            $code === 190                  => 'Meta access token expired or revoked. Reconnect your Meta Business account.',
            $code === 200 && $sub === 1359047 => 'Your Meta app is missing the ads_management permission. Re-authorize via App Review.',
            $code === 200                  => 'Permission denied: ' . $msg . '. Check that your access token has ads_management + pages_manage_ads scopes.',
            $code === 100                  => 'Bad parameter: ' . $msg . '. Often a missing field, wrong enum value, or stale Graph API version.',
            $code === 17                   => 'Meta API rate limit reached. Wait a few minutes and retry.',
            $code === 4                    => 'Meta application request limit reached. Wait an hour.',
            $code === 32                   => 'Page-level rate limit reached for the connected Facebook Page.',
            $code === 803                  => 'Some of the requested fields are invalid for this Graph API version. Bump meta_ads_graph_api_version.',
            $code === 1487616              => 'Ad image is too small. Use at least 1080×1080 px.',
            $code === 1815269              => 'Ad creative violates Meta\'s commerce/marketing policy. Review headline + body for restricted content.',
            default                        => "Meta error {$code}" . ($sub ? "/{$sub}" : '') . ': ' . $msg,
        };
        return $userMsg ? ($hint . ' [' . $userMsg . ']') : $hint;
    }
}
