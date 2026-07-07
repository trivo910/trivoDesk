<?php

namespace App\Services\Waba;

use App\Models\WaTemplate;

/**
 * Builds the Meta-Cloud `POST /{WABA_ID}/message_templates`
 * request body from a local `WaTemplate` row.
 *
 * Pure — no I/O, no DB writes, no Meta calls. Easy to unit-test
 * and reuse from CLI / queue jobs / preview endpoints.
 *
 * Local shape → Meta shape mapping:
 *
 *   template_type=standard       → BODY (+ optional HEADER text/media, FOOTER, BUTTONS)
 *   template_type=media          → BODY + HEADER format=IMAGE|VIDEO|DOCUMENT
 *   template_type=carousel       → BODY + CAROUSEL with 2..10 cards
 *   template_type=auth           → AUTHENTICATION with OTP button
 *
 *   buttons[].type:
 *     visit_website  → URL          (with `url` + `example` for variables)
 *     call_phone     → PHONE_NUMBER (with `phone_number`)
 *     copy_code      → COPY_CODE    (with `example`)
 *     quick_reply    → QUICK_REPLY  (text only)
 *     otp_one_tap    → OTP one-tap autofill (auth templates only)
 *     otp_copy       → OTP copy-code only (auth templates only)
 *
 * `variable_map` shape (local):
 *   [
 *     'body'   => [['var1'], ['var2']],   // each entry is the example for {{N}}
 *     'header' => ['example value'],
 *     'url_0'  => ['example-slug'],       // example values for URL button N
 *   ]
 */
class TemplatePayloadBuilder
{
    /**
     * Build the full CREATE-template payload. Caller is responsible
     * for header media upload (resumable handle) — pass the handle
     * in via $mediaHandles keyed by 'header' or 'card_<index>'.
     *
     * @param  WaTemplate  $t
     * @param  array<string,string>  $mediaHandles  e.g. ['header' => '4::aW...', 'card_0' => '...']
     * @return array
     */
    public function build(WaTemplate $t, array $mediaHandles = []): array
    {
        $name  = $this->normalizeName((string) $t->template_name);
        $lang  = $t->language ?: 'en_US';
        $meta  = strtoupper((string) ($t->meta_category ?: $this->guessMetaCategory($t)));

        $payload = [
            'name'                  => $name,
            'category'              => $meta,
            'language'              => $lang,
            'allow_category_change' => true,
            'parameter_format'      => $t->parameter_format ?: 'POSITIONAL',
            'components'            => $this->buildComponents($t, $mediaHandles),
        ];

        // Auth templates require message_send_ttl_seconds on Meta's side.
        if ($t->template_type === 'auth') {
            $payload['message_send_ttl_seconds'] = 60;
        }

        return $payload;
    }

    /**
     * Build the SEND-template payload — the `template` object inside
     *   POST /{PHONE_ID}/messages { type: 'template', template: { ... } }
     *
     * $vars is the per-recipient parameter map:
     *   [
     *     'header'    => 'A12345',
     *     'header_media_id' => 'MEDIA_ID',   // for IMAGE/VIDEO/DOCUMENT headers
     *     'body'      => ['Sudhir', '1 × Hoodie', 'May 25'],
     *     'buttons'   => [['index' => 1, 'sub_type' => 'url', 'value' => 'INV-001']],
     *     'cards'     => [
     *       0 => ['header_media_id' => '...', 'body' => ['30'], 'buttons' => [...]],
     *       1 => [...],
     *     ],
     *   ]
     */
    public function buildSend(WaTemplate $t, array $vars = []): array
    {
        // Authentication templates have a single body parameter (the OTP
        // code) which ALSO populates the OTP button's parameter. The
        // caller may pass it as $vars['otp'] OR as $vars['body'][0];
        // normalize to both so downstream code is consistent.
        if ($t->template_type === 'auth') {
            $code = (string) ($vars['otp'] ?? $vars['body'][0] ?? '');
            $vars['body']    = [$code];
            $vars['buttons'] = [['index' => 0, 'sub_type' => 'url', 'value' => $code]];
        }

        $send = [
            'name'       => $this->normalizeName((string) $t->template_name),
            'language'   => ['code' => $t->language ?: 'en_US'],
            'components' => [],
        ];

        $headerComp = $this->buildSendHeader($t, $vars);
        if ($headerComp) $send['components'][] = $headerComp;

        // BODY — pad params to match the placeholder count in the
        // approved template body. Meta rejects with #132000 when the
        // count differs. Safer to pad with empty strings (Meta accepts
        // empty body params) than to fail the whole broadcast.
        $bodyVars      = (array) ($vars['body'] ?? []);
        $bodyPlaceholders = $this->countPlaceholders((string) $t->template_body);
        if ($bodyPlaceholders > 0 || !empty($bodyVars)) {
            // Pad up to the expected count.
            while (count($bodyVars) < $bodyPlaceholders) $bodyVars[] = '';
            $bodyVars = array_slice($bodyVars, 0, max($bodyPlaceholders, count($bodyVars)));
            $send['components'][] = [
                'type'       => 'body',
                'parameters' => array_values(array_map(fn ($v) => ['type' => 'text', 'text' => (string) $v], $bodyVars)),
            ];
        }

        // BUTTONS — emit a send-time param ONLY for buttons that need
        // one. Meta rejects with #132000 if you send params for a
        // static URL button (no placeholder in URL) or a phone_number
        // button. The rule is:
        //   - URL button: only if the template URL has `{{N}}`
        //   - quick_reply / copy_code / voice_call: always
        //   - phone_number: never
        $tplButtons = is_array($t->buttons) ? $t->buttons : [];
        foreach (($vars['buttons'] ?? []) as $btn) {
            if (!$this->shouldEmitButtonParam($btn, $tplButtons)) continue;
            $send['components'][] = $this->buildSendButton($btn);
        }

        if ($t->template_type === 'carousel') {
            // Re-index keys to 0..N-1 — if the source array has gaps
            // (e.g. operator deleted card 2 in the UI but the JSON
            // kept keys [0,1,3]), Meta rejects with #132000
            // "card_index must be sequential". array_values defends
            // against that without changing the underlying data.
            $cards = array_values($vars['cards'] ?? []);
            // Template-side card button definitions, re-indexed the same way,
            // so each card's send buttons can be filtered against whether the
            // approved button actually accepts a parameter (mirrors the
            // top-level shouldEmitButtonParam gate — a static URL or phone
            // button must NOT carry a send param or Meta rejects with #132000).
            $tplCards = array_values(is_array($t->carousel_data) ? $t->carousel_data : []);
            $cardComps = [];
            foreach ($cards as $idx => $cardVars) {
                $tplCardButtons = is_array($tplCards[$idx]['buttons'] ?? null) ? $tplCards[$idx]['buttons'] : [];
                $cardComps[] = $this->buildSendCard((int) $idx, $cardVars, $tplCardButtons);
            }
            if ($cardComps) {
                $send['components'][] = ['type' => 'carousel', 'cards' => $cardComps];
            }
        }

        return $send;
    }

    /**
     * Returns true if a send-time button param is needed for this
     * button. Cross-references the template's BUTTONS definition by
     * index so we know whether the URL has `{{N}}` in it.
     *
     * Special cases:
     *   - quick_reply / copy_code / voice_call: ALWAYS need params
     *   - URL with no placeholder: NEVER (Meta error 132000)
     *   - OTP button (otp_one_tap / otp_copy): ALWAYS — the OTP code
     *     is the parameter, even though the create-time URL has no
     *     placeholder in the conventional sense
     */
    private function shouldEmitButtonParam(array $btn, array $tplButtons): bool
    {
        $sub = strtolower((string) ($btn['sub_type'] ?? ''));
        if (in_array($sub, ['quick_reply', 'copy_code', 'voice_call'], true)) return true;
        if ($sub !== 'url') return false;
        $idx = (int) ($btn['index'] ?? 0);
        $tplBtn = $tplButtons[$idx] ?? null;
        if (!$tplBtn) return false;
        // OTP buttons (auth templates) always need the OTP code as a
        // parameter at send time.
        $type = (string) ($tplBtn['type'] ?? '');
        if (in_array($type, ['otp_one_tap', 'otp_copy', 'otp'], true)) return true;
        $url = (string) ($tplBtn['value'] ?? $tplBtn['url'] ?? '');
        return $this->countPlaceholders($url) > 0;
    }

    // -----------------------------------------------------------------
    // CREATE — components builder
    // -----------------------------------------------------------------

    private function buildComponents(WaTemplate $t, array $mediaHandles): array
    {
        return match ($t->template_type) {
            'auth'     => $this->buildAuthComponents($t),
            'carousel' => $this->buildCarouselComponents($t, $mediaHandles),
            default    => $this->buildStandardComponents($t, $mediaHandles),
        };
    }

    private function buildStandardComponents(WaTemplate $t, array $mediaHandles): array
    {
        $components = [];

        if ($t->header || ($t->attachment_type && $t->attachment_type !== 'none')) {
            $components[] = $this->buildHeader($t, $mediaHandles['header'] ?? null);
        }

        $components[] = $this->buildBody($t);

        if ($t->footer) {
            $components[] = ['type' => 'FOOTER', 'text' => (string) $t->footer];
        }

        $buttons = $this->buildButtons($t->buttons ?? []);
        if ($buttons) $components[] = $buttons;

        return array_values(array_filter($components));
    }

    private function buildCarouselComponents(WaTemplate $t, array $mediaHandles): array
    {
        $components = [];

        // Carousel REQUIRES a top-level BODY message bubble — that's the
        // text that shows above the carousel. Falls back to a sensible
        // default if the user left it blank (Meta rejects empty body).
        $components[] = $this->buildBody($t);

        $cards = [];
        foreach (($t->carousel_data ?? []) as $idx => $card) {
            $cardComps = [];

            // Card header — must be IMAGE or VIDEO; format inferred from upload.
            // Only emit when we actually have a usable media handle — emitting
            // `header_handle:[""]` triggers Meta error code 100 "invalid
            // header_handle" and the whole carousel submission gets rejected.
            $cardHandle = (string) ($mediaHandles['card_' . $idx] ?? '');
            if ($cardHandle !== '') {
                $cardComps[] = [
                    'type'    => 'HEADER',
                    'format'  => $this->inferCardMediaFormat($card),
                    'example' => ['header_handle' => [$cardHandle]],
                ];
            }

            if (!empty($card['body'])) {
                $cardComps[] = [
                    'type' => 'BODY',
                    'text' => (string) $card['body'],
                ] + $this->bodyExample((string) $card['body'], $t->variable_map['cards'][$idx]['body'] ?? null);
            }

            $cardButtons = $this->buildButtons($card['buttons'] ?? []);
            if ($cardButtons) $cardComps[] = $cardButtons;

            $cards[] = ['components' => $cardComps];
        }

        $components[] = ['type' => 'CAROUSEL', 'cards' => $cards];

        return $components;
    }

    private function buildAuthComponents(WaTemplate $t): array
    {
        $buttons = $t->buttons ?? [];
        $otp     = collect($buttons)->first(fn ($b) => in_array($b['type'] ?? '', ['otp_one_tap', 'otp_copy'], true));

        // Component `type` must be uppercase to match Meta's canonical
        // spec — `BODY` / `FOOTER` / `BUTTONS` (not `body` / `footer` /
        // `buttons`). The button entries inside `buttons[]` keep their
        // existing lowercase keys (`type:"otp"`, `otp_type:"copy_code"`)
        // which is what Meta's authentication-template docs show.
        $components = [
            ['type' => 'BODY',   'add_security_recommendation' => true],
            ['type' => 'FOOTER', 'code_expiration_minutes'     => 10],
        ];

        if ($otp) {
            $btn = [
                'type'     => 'OTP',
                'otp_type' => ($otp['type'] === 'otp_one_tap') ? 'ONE_TAP' : 'COPY_CODE',
                'text'     => (string) ($otp['text'] ?: 'Copy code'),
            ];
            if ($otp['type'] === 'otp_one_tap') {
                $btn['autofill_text']  = (string) ($otp['autofill_text']  ?? 'Autofill');
                $btn['package_name']   = (string) ($otp['package_name']   ?? '');
                $btn['signature_hash'] = (string) ($otp['signature_hash'] ?? '');
            }
            $components[] = ['type' => 'BUTTONS', 'buttons' => [$btn]];
        }

        return $components;
    }

    private function buildHeader(WaTemplate $t, ?string $mediaHandle): array
    {
        $format = strtoupper((string) ($t->attachment_type ?: 'TEXT'));
        if (!in_array($format, ['TEXT', 'IMAGE', 'VIDEO', 'DOCUMENT', 'LOCATION'], true)) {
            $format = 'TEXT';
        }
        if ($format === 'TEXT') {
            $headerText = (string) $t->header;
            $node = ['type' => 'HEADER', 'format' => 'TEXT', 'text' => $headerText];
            if ($this->countPlaceholders($headerText) > 0) {
                $hExamples = (array) ($t->variable_map['header'] ?? []);
                if (empty($hExamples)) {
                    // Use the placeholder name from the header text itself
                    // instead of the literal token "example" — Meta's review
                    // queue auto-rejects "example" as filler-not-replaced.
                    $names = $this->extractPlaceholderNames($headerText);
                    $hExamples = $names ?: ['Sample'];
                }
                $node['example'] = ['header_text' => array_values($hExamples)];
            }
            return $node;
        }

        return [
            'type'    => 'HEADER',
            'format'  => $format,
            'example' => ['header_handle' => [(string) ($mediaHandle ?? '')]],
        ];
    }

    private function buildBody(WaTemplate $t): array
    {
        $text = (string) $t->template_body;
        $node = ['type' => 'BODY', 'text' => $text];
        return $node + $this->bodyExample($text, $t->variable_map['body'] ?? null);
    }

    /**
     * `example.body_text` is a 2-D array: outer = number of placeholder
     * sets, inner = one entry per placeholder. We always send a single
     * set, so wrap once.
     */
    private function bodyExample(string $text, $examples): array
    {
        $n = $this->countPlaceholders($text);
        if ($n === 0) return [];

        $examples = is_array($examples) ? $examples : [];
        // Pad missing slots with the placeholder-name pulled from the
        // template text itself ({{name}} → "name", {{1}} → "Sample 1").
        // Meta's review queue auto-rejects the literal token "example"
        // because that pattern is the canonical "filler / placeholder
        // not replaced" signal. Using the variable name is safer for
        // approval AND gives reviewers a real-world hint.
        if (count($examples) < $n) {
            $names = $this->extractPlaceholderNames($text);
            for ($i = count($examples); $i < $n; $i++) {
                $tag = $names[$i] ?? null;
                $examples[] = ($tag !== null && $tag !== '' && !is_numeric($tag))
                    ? $tag
                    : ('Sample ' . ($i + 1));
            }
        }
        $examples = array_slice($examples, 0, $n);

        return ['example' => ['body_text' => [array_values($examples)]]];
    }

    /**
     * Pull the raw placeholder names out of body text so we can use them
     * as Meta `example` filler instead of the literal word "example".
     */
    private function extractPlaceholderNames(string $text): array
    {
        if (!preg_match_all('/\{\{\s*([^\s{}]+?)\s*\}\}/', $text, $m)) {
            return [];
        }
        return $m[1] ?? [];
    }

    private function buildButtons(array $btns): ?array
    {
        if (empty($btns)) return null;

        $out = [];
        foreach ($btns as $b) {
            if (!is_array($b)) continue;
            $type = (string) ($b['type'] ?? '');
            $text = (string) ($b['text'] ?? '');
            $val  = (string) ($b['value'] ?? '');

            $node = ['text' => $text];
            switch ($type) {
                case 'visit_website':
                case 'url':
                    $node['type'] = 'URL';
                    $node['url']  = $val;
                    if ($this->countPlaceholders($val) > 0) {
                        // Positional array — `array_values` defends against
                        // accidental associative arrays from user input.
                        // If no example provided, derive one from the URL's
                        // own placeholder name rather than shipping the
                        // literal word "example" which Meta auto-rejects.
                        $userExample = $b['example'] ?? null;
                        if (!empty($userExample)) {
                            $node['example'] = array_values((array) $userExample);
                        } else {
                            $names = $this->extractPlaceholderNames($val);
                            $node['example'] = array_values($names ?: ['sample']);
                        }
                    }
                    break;
                case 'call_phone':
                case 'phone_number':
                    $node['type']         = 'PHONE_NUMBER';
                    $node['phone_number'] = $val;
                    break;
                case 'copy_code':
                    $node['type']    = 'COPY_CODE';
                    // Meta requires `example` as a positional array of strings.
                    // Cast through array_values so an assoc map can't leak in.
                    $node['example'] = array_values((array) ($b['example'] ?? [$val ?: 'ABC123']));
                    break;
                case 'quick_reply':
                default:
                    $node['type'] = 'QUICK_REPLY';
            }
            $out[] = $node;
        }

        if (empty($out)) return null;
        return ['type' => 'BUTTONS', 'buttons' => array_slice($out, 0, 10)];
    }

    // -----------------------------------------------------------------
    // SEND — per-recipient parameter builder
    // -----------------------------------------------------------------

    private function buildSendHeader(WaTemplate $t, array $vars): ?array
    {
        $type = strtoupper((string) ($t->attachment_type ?: 'TEXT'));

        if ($type === 'TEXT') {
            if (empty($vars['header'])) return null;
            return [
                'type'       => 'header',
                'parameters' => [['type' => 'text', 'text' => (string) $vars['header']]],
            ];
        }

        // LOCATION header — Meta needs latitude + longitude + name +
        // address inside a `location` object, NOT the `link|id` shape
        // used for media. Without this branch a LOCATION-header
        // template would either silently strip the header or fail
        // with #132012.
        if ($type === 'LOCATION') {
            $loc = $vars['header_location'] ?? null;
            if (!is_array($loc) || empty($loc['latitude']) || empty($loc['longitude'])) {
                return null;
            }
            return [
                'type'       => 'header',
                'parameters' => [[
                    'type'     => 'location',
                    'location' => [
                        'latitude'  => (string) $loc['latitude'],
                        'longitude' => (string) $loc['longitude'],
                        'name'      => (string) ($loc['name']    ?? ''),
                        'address'   => (string) ($loc['address'] ?? ''),
                    ],
                ]],
            ];
        }

        if (empty($vars['header_media_id']) && empty($vars['header_media_url'])) return null;

        $mediaKey = strtolower($type); // image / video / document
        $media = isset($vars['header_media_id'])
            ? ['id'   => (string) $vars['header_media_id']]
            : ['link' => (string) $vars['header_media_url']];

        return [
            'type'       => 'header',
            'parameters' => [['type' => $mediaKey, $mediaKey => $media]],
        ];
    }

    /**
     * Build the SEND-time button component. Meta uses different
     * parameter-object shapes per sub_type:
     *
     *   quick_reply → { type: "payload",     payload:     "..." }
     *   url         → { type: "text",        text:        "..." }
     *   copy_code   → { type: "coupon_code", coupon_code: "..." }
     *   voice_call  → no parameters
     *
     * `index` is sent as a 0-based INTEGER — the canonical form in
     * Meta's current Cloud API examples (the API also tolerates the
     * quoted-string "0", but integer is the documented primary).
     */
    private function buildSendButton(array $btn): array
    {
        $sub   = strtolower((string) ($btn['sub_type'] ?? 'url'));
        $value = (string) ($btn['value'] ?? '');

        $params = match ($sub) {
            'quick_reply' => [['type' => 'payload',     'payload'     => $value]],
            'copy_code'   => [['type' => 'coupon_code', 'coupon_code' => $value]],
            'voice_call'  => [],
            default       => [['type' => 'text',        'text'        => $value]], // url + fallback
        };

        return [
            'type'       => 'button',
            'sub_type'   => $sub,
            // Meta's 2025-2026 Cloud API send spec shows `index` as an
            // integer in the canonical example. Older docs accepted a
            // string ("0", "1"), but the latest reference is integer
            // only — send it as int to stay forward-compatible.
            'index'      => (int) ($btn['index'] ?? 0),
            'parameters' => $params,
        ];
    }

    private function buildSendCard(int $cardIdx, array $cardVars, array $tplCardButtons = []): array
    {
        $comps = [];

        if (!empty($cardVars['header_media_id']) || !empty($cardVars['header_media_url'])) {
            $media = isset($cardVars['header_media_id'])
                ? ['id'   => (string) $cardVars['header_media_id']]
                : ['link' => (string) $cardVars['header_media_url']];
            $type = strtolower((string) ($cardVars['header_format'] ?? 'image'));
            $comps[] = [
                'type'       => 'header',
                'parameters' => [['type' => $type, $type => $media]],
            ];
        }

        if (!empty($cardVars['body'])) {
            $comps[] = [
                'type'       => 'body',
                'parameters' => array_map(fn ($v) => ['type' => 'text', 'text' => (string) $v], (array) $cardVars['body']),
            ];
        }

        // Same rule as the top-level buttons: a send-time button parameter is
        // ONLY valid when the approved button actually expects one (quick_reply /
        // copy_code always; URL only when its template URL has a {{N}} suffix).
        // Emitting a param for a static URL/phone card button → Meta #132000.
        foreach (($cardVars['buttons'] ?? []) as $btn) {
            if (!$this->shouldEmitButtonParam($btn, $tplCardButtons)) continue;
            $comps[] = $this->buildSendButton($btn);
        }

        return ['card_index' => $cardIdx, 'components' => $comps];
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Meta accepts template names ^[a-z0-9_]{1,512}$ — slugify whatever
     * the user typed so we don't reject silently. Mirrors Meta's own
     * normalization shown in Manager.
     */
    public function normalizeName(string $name): string
    {
        $n = mb_strtolower(trim($name));
        $n = preg_replace('/[^a-z0-9]+/u', '_', $n);
        $n = trim($n, '_');
        return mb_substr($n, 0, 512) ?: 'untitled_template';
    }

    /** Count distinct `{{N}}` placeholders in a string. */
    public function countPlaceholders(string $s): int
    {
        preg_match_all('/\{\{\s*[\w_]+\s*\}\}/', $s, $m);
        return count($m[0]);
    }

    /**
     * Maps the local industry-category to Meta's three-bucket category
     * when the user didn't explicitly pick one. Conservative — defaults
     * to UTILITY (Meta's strictest) rather than MARKETING to avoid
     * accidental promotional misclassification.
     */
    private function guessMetaCategory(WaTemplate $t): string
    {
        if ($t->template_type === 'auth') return 'AUTHENTICATION';
        return match ($t->category) {
            'ecommerce', 'festival', 'travel' => 'MARKETING',
            default                            => 'UTILITY',
        };
    }

    private function inferCardMediaFormat(array $card): string
    {
        $path = (string) ($card['image'] ?? '');
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return match ($ext) {
            'mp4', 'mov', '3gp' => 'VIDEO',
            default              => 'IMAGE',
        };
    }
}
