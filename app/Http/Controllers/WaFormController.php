<?php

namespace App\Http\Controllers;

use App\Models\WaForm;
use App\Models\WaProviderConfig;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * WhatsApp Forms — workspace-scoped reusable interactive form
 * definitions. Saved as a draft, then published to Meta's Flows API.
 * The flow-builder's `wa_form` node references a published row by id
 * and sends it as an interactive message at runtime.
 *
 * Meta endpoints used:
 *   POST graph.facebook.com/{v}/{waba_id}/flows
 *        body: { name, categories[], publish? }
 *        returns: { id (flow id) }
 *   POST graph.facebook.com/{v}/{flow_id}/assets
 *        multipart: file=flow.json, name=flow.json, asset_type=FLOW_JSON
 *   POST graph.facebook.com/{v}/{flow_id}/publish
 *        body: empty (after assets uploaded)
 */
class WaFormController extends Controller implements HasMiddleware
{
    /**
     * Engine guard — WhatsApp Flows are Meta Cloud API exclusive.
     * Without a WABA provider configured, every publish/send call
     * would 4xx. Block every entry point at controller construction
     * so a direct URL visit (bookmark, deep-link, copied URL from
     * a WABA workspace) doesn't 500 — instead redirect cleanly to
     * /more with a flash message explaining the requirement.
     */
    public static function middleware(): array
    {
        // Laravel 12 — controllers no longer have $this->middleware(); register
        // the engine guard via the HasMiddleware contract instead. (Calling
        // $this->middleware() in the constructor 500'd the whole /wa-forms page
        // with "Call to undefined method middleware()".)
        return [function ($request, $next) {
            $wsId   = (int) ($request->user()?->current_workspace_id ?? 0);
            // Multi-engine: allow WhatsApp Flows whenever WABA is among the
            // workspace's ENABLED engines, not only when it's the single
            // default — a workspace running Baileys + WABA can still use Forms.
            $wabaEnabled = $wsId && \App\Services\WorkspaceEngine::isEngineEnabled($wsId, 'waba');
            if (!$wabaEnabled) {
                if ($request->expectsJson() || $request->ajax()) {
                    return response()->json([
                        'ok' => false,
                        'message' => 'WhatsApp Flows require a WABA Cloud API workspace. Switch engine or connect a WABA number to use Forms.',
                    ], 403);
                }
                return redirect()->to('/more')->with('warning',
                    'WhatsApp Forms are only available on WABA workspaces. Connect a Cloud API number to enable this feature.');
            }
            return $next($request);
        }];
    }

    public function index(): View
    {
        $wsId = (int) (Auth::user()?->current_workspace_id ?? 0);
        $forms = WaForm::query()
            ->where('workspace_id', $wsId)
            ->withCount('submissions')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $stats = [
            'all'         => WaForm::where('workspace_id', $wsId)->count(),
            'published'   => WaForm::where('workspace_id', $wsId)->where('status', 'published')->count(),
            'draft'       => WaForm::where('workspace_id', $wsId)->where('status', 'draft')->count(),
            'submissions' => \App\Models\WaFormSubmission::where('workspace_id', $wsId)->count(),
        ];
        return view('user.wa-forms.index', compact('forms', 'stats'));
    }

    public function create(): View
    {
        return view('user.wa-forms.builder', ['form' => null, 'mode' => 'create']);
    }

    /**
     * GET /wa-forms/api/list — JSON list for the flow builder picker.
     * Returns id, title, is_published per form.
     */
    public function apiList(): JsonResponse
    {
        $wsId = (int) (Auth::user()?->current_workspace_id ?? 0);
        $forms = WaForm::query()
            ->where('workspace_id', $wsId)
            ->orderByDesc('id')
            ->get(['id', 'title', 'status', 'meta_flow_id'])
            ->map(fn ($f) => [
                'id'           => $f->id,
                'title'        => $f->title,
                'status'       => $f->status,
                'is_published' => $f->status === 'published' && $f->meta_flow_id,
            ]);
        return response()->json(['ok' => true, 'forms' => $forms]);
    }

    public function edit(int $id): View
    {
        $wsId = (int) (Auth::user()?->current_workspace_id ?? 0);
        $form = WaForm::where('workspace_id', $wsId)->findOrFail($id);
        return view('user.wa-forms.builder', ['form' => $form, 'mode' => 'edit']);
    }

    /**
     * POST /wa-forms/api/save — wizard sends the full state on every save.
     * Returns the persisted form id so the builder can switch URL to edit-mode.
     */
    public function apiSave(Request $request): JsonResponse
    {
        $user = Auth::user();
        $wsId = (int) ($user?->current_workspace_id ?? 0);
        if (!$wsId) return response()->json(['ok' => false, 'error' => 'no_workspace'], 400);

        $data = $request->validate([
            'id'                  => 'nullable|integer',
            'title'               => 'required|string|max:140',
            'purpose'             => 'nullable|string|max:2000',
            'audience_type'       => 'required|string|max:32',
            'submission_cap'      => 'nullable|integer|min:0|max:1000000',
            'cap_reached_note'    => 'nullable|string|max:500',
            'send_button_label'   => 'nullable|string|max:40',
            'thank_you_note'      => 'nullable|string|max:500',
            'definition'          => 'required|array',
            'definition.screens'  => 'required|array|min:1',
        ]);

        $form = !empty($data['id']) ? WaForm::where('workspace_id', $wsId)->find($data['id']) : null;
        if (!$form) {
            $form = new WaForm();
            $form->workspace_id = $wsId;
            $form->user_id      = $user->id;
        }

        // Generate a unique slug per workspace.
        $slug = $form->slug ?: Str::slug($data['title']);
        $base = $slug; $n = 1;
        while (WaForm::where('workspace_id', $wsId)
            ->where('slug', $slug)
            ->where('id', '!=', $form->id ?? 0)
            ->exists()) {
            $slug = $base . '-' . (++$n);
        }

        $form->fill([
            'title'             => $data['title'],
            'slug'              => $slug,
            'purpose'           => $data['purpose'] ?? null,
            'audience_type'     => $data['audience_type'],
            'submission_cap'    => (int) ($data['submission_cap'] ?? 0),
            'cap_reached_note'  => $data['cap_reached_note'] ?? null,
            'send_button_label' => $data['send_button_label'] ?? 'Send',
            'thank_you_note'    => $data['thank_you_note'] ?? null,
            'definition_json'   => $data['definition'],
        ]);
        // Editing a published form drops it back to draft until re-publish.
        if ($form->status === 'published') {
            $form->status = 'draft';
        }
        $form->save();

        return response()->json([
            'ok'           => true,
            'id'           => $form->id,
            'slug'         => $form->slug,
            'redirect_to'  => route('user.wa-forms.edit', $form->id),
        ]);
    }

    /**
     * POST /wa-forms/{id}/publish — pushes the form to Meta's Flows API.
     * 3-step Meta flow: create flow row → upload flow_json asset → publish.
     */
    public function publish(int $id): RedirectResponse
    {
        $wsId = (int) (Auth::user()?->current_workspace_id ?? 0);
        $form = WaForm::where('workspace_id', $wsId)->findOrFail($id);

        $cfg = WaProviderConfig::where('workspace_id', $wsId)
            ->where('provider', 'waba')
            ->first();
        if (!$cfg) {
            return back()->with('error', 'No WABA provider configured — connect Meta first.');
        }
        $creds = $cfg->creds();
        $token = (string) ($creds['access_token'] ?? '');
        $wabaId = (string) ($creds['waba_id'] ?? '');
        if ($token === '' || $wabaId === '') {
            return back()->with('error', 'WABA credentials missing (access_token or waba_id).');
        }
        $version = (string) (env('META_GRAPH_VERSION') ?: 'v21.0');
        $base = "https://graph.facebook.com/{$version}";

        try {
            $flowId = $form->meta_flow_id;
            if (!$flowId) {
                $resp = Http::withToken($token)->acceptJson()->timeout(15)
                    ->post("{$base}/{$wabaId}/flows", [
                        'name'       => $form->title,
                        'categories' => [strtoupper($this->mapCategory($form->audience_type))],
                    ]);
                if (!$resp->successful()) {
                    throw new \RuntimeException('create flow ' . $resp->status() . ': ' . substr($resp->body(), 0, 300));
                }
                $flowId = (string) ($resp->json('id') ?? '');
                if ($flowId === '') {
                    throw new \RuntimeException('Meta did not return flow id');
                }
                $form->meta_flow_id = $flowId;
                $form->save();
            }

            $flowJson = $this->buildMetaFlowJson($form);

            $assetResp = Http::withToken($token)->timeout(20)
                ->attach('file', $flowJson, 'flow.json', ['Content-Type' => 'application/json'])
                ->post("{$base}/{$flowId}/assets", [
                    'name'       => 'flow.json',
                    'asset_type' => 'FLOW_JSON',
                ]);
            if (!$assetResp->successful()) {
                throw new \RuntimeException('upload asset ' . $assetResp->status() . ': ' . substr($assetResp->body(), 0, 300));
            }

            $pubResp = Http::withToken($token)->acceptJson()->timeout(15)
                ->post("{$base}/{$flowId}/publish");
            if (!$pubResp->successful()) {
                throw new \RuntimeException('publish ' . $pubResp->status() . ': ' . substr($pubResp->body(), 0, 300));
            }

            $form->forceFill([
                'status'        => 'published',
                'published_at'  => now(),
                'publish_error' => null,
            ])->save();

            return back()->with('success', 'Form published to Meta — ready to send.');
        } catch (\Throwable $e) {
            Log::warning('[WAFORM] publish failed: ' . $e->getMessage());
            $form->forceFill(['publish_error' => mb_substr($e->getMessage(), 0, 1000)])->save();
            return back()->with('error', 'Publish failed — ' . mb_substr($e->getMessage(), 0, 220));
        }
    }

    public function destroy(int $id): RedirectResponse
    {
        $wsId = (int) (Auth::user()?->current_workspace_id ?? 0);
        $form = WaForm::where('workspace_id', $wsId)->findOrFail($id);
        $form->delete();
        return redirect('/wa-forms')->with('success', 'Form deleted.');
    }

    public function duplicate(int $id): RedirectResponse
    {
        $wsId = (int) (Auth::user()?->current_workspace_id ?? 0);
        $src = WaForm::where('workspace_id', $wsId)->findOrFail($id);
        $copy = $src->replicate(['slug', 'meta_flow_id', 'published_at', 'status', 'submission_count']);
        $copy->title = $src->title . ' (copy)';
        $copy->slug  = Str::slug($copy->title . '-' . substr((string) Str::uuid(), 0, 4));
        $copy->status = 'draft';
        $copy->save();
        return redirect()->route('user.wa-forms.edit', $copy->id)->with('success', 'Form duplicated.');
    }

    // ────────────────────────────────────────────────────────────

    private function mapCategory(string $audienceType): string
    {
        // Meta's enum: SIGN_UP | SIGN_IN | APPOINTMENT_BOOKING | LEAD_GENERATION
        //              | CONTACT_US | CUSTOMER_SUPPORT | SURVEY | OTHER
        return match (strtolower($audienceType)) {
            'lead_capture' => 'LEAD_GENERATION',
            'survey'       => 'SURVEY',
            'appointment'  => 'APPOINTMENT_BOOKING',
            'feedback'     => 'SURVEY',
            'onboarding'   => 'SIGN_UP',
            'support'      => 'CUSTOMER_SUPPORT',
            default        => 'OTHER',
        };
    }

    /**
     * Translates our internal definition_json into Meta's Flow JSON.
     *
     * Verified against the official WhatsApp/WhatsApp-Flows-Tools repo
     * (Meta's own examples) — three things matter:
     *   1. Form fields MUST be wrapped in a `Form` component (name="form")
     *      — bare children directly in SingleColumnLayout don't capture
     *      values for the complete-action payload.
     *   2. Property names are HYPHENATED in Flow JSON:
     *      `on-click-action`, `data-source`, `input-type`, `helper-text`,
     *      `min-chars`, `max-chars`.
     *   3. The `complete` action's payload must reference each field as
     *      `${form.<field_name>}` so submission carries the values back
     *      to our webhook.
     *
     * Version `5.1` is broadly supported (Meta's own examples use 3.1;
     * pywa uses 7.0). 5.1 supports navigate + complete + all our
     * components without version-gated quirks.
     */
    private function buildMetaFlowJson(WaForm $form): string
    {
        $kindToMeta = [
            'text'     => 'TextInput',
            'long_text'=> 'TextArea',
            'email'    => 'TextInput',
            'phone'    => 'TextInput',
            'number'   => 'TextInput',
            'dropdown' => 'Dropdown',
            'choice'   => 'RadioButtonsGroup',
            'multi'    => 'CheckboxGroup',
            'date'     => 'DatePicker',
            'heading'  => 'TextHeading',
        ];
        $inputType = [
            'text'   => 'text',
            'email'  => 'email',
            'phone'  => 'phone',
            'number' => 'number',
        ];

        $screens = [];
        $screensDef = (array) ($form->definition_json['screens'] ?? []);
        foreach ($screensDef as $i => $screen) {
            $formChildren = [];   // goes inside the Form component
            $captureMap   = [];   // { field_id => "${form.field_id}" } for complete payload

            foreach ((array) ($screen['fields'] ?? []) as $field) {
                $kind = strtolower((string) ($field['kind'] ?? 'text'));
                $type = $kindToMeta[$kind] ?? 'TextInput';
                $fieldName = (string) ($field['id'] ?? ('fld_' . Str::random(6)));

                if ($type === 'TextHeading') {
                    // Display-only — TextHeading takes `text`, no name/required.
                    $formChildren[] = [
                        'type' => 'TextHeading',
                        'text' => (string) ($field['label'] ?? ''),
                    ];
                    continue;
                }

                $node = [
                    'type'     => $type,
                    'name'     => $fieldName,
                    'label'    => (string) ($field['label'] ?? 'Field'),
                    'required' => (bool) ($field['required'] ?? false),
                ];
                if (isset($inputType[$kind])) {
                    $node['input-type'] = $inputType[$kind];
                }
                if (in_array($type, ['Dropdown','RadioButtonsGroup','CheckboxGroup'], true)) {
                    $node['data-source'] = array_values(array_map(
                        fn ($o, $idx) => ['id' => 'opt_' . $idx, 'title' => is_string($o) ? $o : ($o['title'] ?? '')],
                        (array) ($field['options'] ?? []),
                        array_keys((array) ($field['options'] ?? []))
                    ));
                }
                if (!empty($field['hint'])) {
                    $node['helper-text'] = (string) $field['hint'];
                }
                $formChildren[] = $node;

                // Capture for the complete-action payload so each value
                // travels back to our nfm_reply webhook.
                $captureMap[$fieldName] = '${form.' . $fieldName . '}';
            }

            $isLast  = $i === count($screensDef) - 1;
            $btnText = (string) ($form->send_button_label ?: ($isLast ? 'Send' : 'Continue'));

            // Footer with the operator-configured Send button. On the
            // last screen the on-click-action is `complete` and carries
            // every captured field as a payload entry. Intermediate
            // screens navigate to the next screen.
            $footer = [
                'type'  => 'Footer',
                'label' => $btnText,
            ];
            if ($isLast) {
                // `complete` payload MUST be an object literal of
                // `${form.X}` references — otherwise the values don't
                // make it back to the submission webhook.
                $footer['on-click-action'] = [
                    'name'    => 'complete',
                    'payload' => $captureMap ?: (object) [],
                ];
            } else {
                $footer['on-click-action'] = [
                    'name' => 'navigate',
                    'next' => ['type' => 'screen', 'name' => 'SCREEN_' . ($i + 1)],
                    // Forward the captured values to the next screen so
                    // multi-step forms can keep state without re-asking.
                    'payload' => $captureMap ?: (object) [],
                ];
            }
            $formChildren[] = $footer;

            $screens[] = [
                'id'       => 'SCREEN_' . $i,
                'title'    => (string) ($screen['label'] ?? ('Step ' . ($i + 1))),
                'terminal' => $isLast,
                'data'     => (object) [],
                'layout'   => [
                    'type'     => 'SingleColumnLayout',
                    'children' => [[
                        'type'     => 'Form',
                        'name'     => 'form',
                        'children' => $formChildren,
                    ]],
                ],
            ];
        }

        // Meta WhatsApp Flows JSON spec (v3.1+) requires:
        //   - version:           "5.1" — Flow schema version (we use 5.1 across the platform)
        //   - data_api_version:  "3.0" — Endpoint data exchange protocol version. Even forms
        //                        that don't use server-side data exchange must declare this or
        //                        Meta's validator rejects the upload as "Missing required field".
        //   - routing_model:     {} — Required dict mapping each screen id to the screens it
        //                        can navigate to. For our linear builder we build it from the
        //                        screen order below.
        $routingModel = [];
        foreach ($screens as $i => $s) {
            $next = $i + 1 < count($screens) ? ['SCREEN_' . ($i + 1)] : [];
            $routingModel[$s['id']] = $next;
        }
        return json_encode([
            'version'          => '5.1',
            'data_api_version' => '3.0',
            'routing_model'    => $routingModel ?: (object) [],
            'screens'          => $screens,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
