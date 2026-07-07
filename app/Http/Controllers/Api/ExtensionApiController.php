<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attribute;
use App\Models\Device;
use App\Models\ExtensionApiToken;
use App\Models\Message;
use App\Models\User;
use App\Models\WaProviderConfig;
use App\Models\WaTemplate;
use App\Services\WhatsAppDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

/**
 * REST API consumed by the WaDesk browser extension (content.js).
 *
 * Public routes:  appConfig, login
 * Bearer routes:  devices, attributes, templates, messageHistory,
 *                 credits, sendQuickMessage, contactCsv
 *
 * The extension talks to these through its background-worker fetch
 * proxy, so there's no browser CORS to satisfy. Auth on the bearer
 * routes is the ExtensionApiAuth middleware (extension_api_tokens).
 */
class ExtensionApiController extends Controller
{
    // ───────── PUBLIC ─────────

    /** GET /api/ext/app-config — lets the extension discover the canonical app URL. */
    public function appConfig(): JsonResponse
    {
        return response()->json([
            'app_url'  => rtrim((string) config('app.url'), '/'),
            'app_name' => (string) \App\Models\SystemSetting::get('app_name', config('app.name', 'WaDesk')),
        ]);
    }

    /** POST /api/ext/login — email + password → bearer token. */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $data['email'])->first();
        if (!$user || !Hash::check($data['password'], $user->password)) {
            return response()->json(['status' => 'error', 'message' => 'Invalid email or password.'], 401);
        }
        if (method_exists($user, 'trashed') && $user->trashed()) {
            return response()->json(['status' => 'error', 'message' => 'Account is disabled.'], 403);
        }

        $token = ExtensionApiToken::issue($user->id, 'browser-extension');

        return response()->json([
            'status'       => 'success',
            'access_token' => $token,
            'user'         => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
            ],
        ]);
    }

    /** POST /api/ext/logout — revoke the presented token. */
    public function logout(Request $request): JsonResponse
    {
        $bearer = (string) $request->bearerToken();
        if ($bearer !== '') {
            ExtensionApiToken::where('token_hash', hash('sha256', $bearer))->delete();
        }
        return response()->json(['status' => 'success']);
    }

    // ───────── BEARER ─────────

    /**
     * GET /api/ext/devices — sender numbers for the workspace's ACTIVE
     * engine only. Mirrors WorkspaceEngine: baileys → paired phones;
     * waba → WABA numbers; twilio → the Twilio number. We never mix
     * engines, because the dispatcher routes by the workspace engine —
     * showing a WABA number while the workspace is on Baileys would let
     * the operator pick a sender that silently can't send.
     */
    public function devices(Request $request): JsonResponse
    {
        $user   = $request->user();
        $wsId   = $user->current_workspace_id;
        $engine = \App\Services\WorkspaceEngine::for($wsId);
        $out    = [];

        if ($engine === \App\Services\WorkspaceEngine::ENGINE_BAILEYS) {
            // Baileys: ONLY active + connected phone devices — an
            // inactive or disconnected device can't actually send, so
            // never offer it as a sender.
            Device::query()
                ->where('user_id', $user->id)
                ->where('active', true)
                ->where('status', 'connected')
                ->get(['id', 'device_name', 'country_code', 'phone_number', 'status', 'active'])
                ->each(function ($d) use (&$out) {
                    $phone = preg_replace('/\D+/', '', (string) (($d->country_code ?? '') . $d->phone_number));
                    if ($phone === '') return;
                    $out[] = [
                        'device_name'  => $d->device_name ?: ('Device #' . $d->id),
                        'phone_number' => $phone,
                        'status'       => $d->status,
                        'engine'       => 'baileys',
                    ];
                });
        } elseif ($wsId) {
            // WABA or Twilio: only CONNECTED provider numbers for THIS engine.
            WaProviderConfig::query()
                ->where('workspace_id', $wsId)
                ->where('provider', $engine)
                ->where('status', WaProviderConfig::STATUS_CONNECTED)
                ->get(['id', 'provider', 'phone_number', 'display_label', 'status'])
                ->each(function ($c) use (&$out, $engine) {
                    $phone = preg_replace('/\D+/', '', (string) $c->phone_number);
                    if ($phone === '') return;
                    $out[] = [
                        'device_name'  => $c->display_label ?: strtoupper($c->provider),
                        'phone_number' => $phone,
                        'status'       => $c->status ?: 'connected',
                        'engine'       => $engine,
                    ];
                });
        }

        return response()->json(['devices' => $out, 'engine' => $engine]);
    }

    /** GET /api/ext/attributes — workspace custom merge fields. */
    public function attributes(Request $request): JsonResponse
    {
        $user = $request->user();
        $rows = Attribute::query()
            ->where(function ($q) use ($user) {
                $q->where('workspace_id', $user->current_workspace_id)
                  ->orWhere('user_id', $user->id);
            })
            ->get(['attribute_key', 'attribute_name'])
            ->map(fn ($a) => [
                'attribute_key'  => $a->attribute_key,
                'attribute_name' => $a->attribute_name,
            ])
            ->values();

        return response()->json(['custom_attributes' => $rows]);
    }

    /**
     * GET /api/ext/templates — workspace message templates, filtered by
     * the active engine like the web app: on WABA only Meta-approved
     * (or public) templates can be sent, so we only surface those; on
     * Baileys / Twilio any saved template works, so we return all.
     */
    public function templates(Request $request): JsonResponse
    {
        $user   = $request->user();
        $wsId   = $user->current_workspace_id;
        $engine = \App\Services\WorkspaceEngine::for($wsId);

        // Mirror the web app exactly. forCurrentWorkspace() = this
        // workspace's rows + admin-seeded globals; approved() is
        // engine-aware: on WABA it requires a real Meta approval
        // (meta_template_id + meta_status=APPROVED), because the local
        // `status` column is SYNTHETIC on WABA (the Baileys flow stamps
        // every row 'approved'). Filtering on `status` here let non-Meta
        // templates leak into a WABA workspace's picker.
        $rows = WaTemplate::query()
            ->forCurrentWorkspace()
            ->approved()
            ->orderByDesc('id')
            ->get()
            ->map(fn ($t) => [
                'template_name' => $t->template_name,
                'template_body' => $t->template_body,
                'template_type' => $t->template_type ?? 'text',
                'status'        => $t->status,
            ])->values();

        return response()->json(['templates' => $rows, 'engine' => $engine]);
    }

    /** GET /api/ext/message-history?page=N — paginated sends for this user. */
    public function messageHistory(Request $request): JsonResponse
    {
        $user = $request->user();
        $paginator = Message::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->paginate(20, ['*'], 'page', (int) $request->query('page', 1));

        $paginator->getCollection()->transform(function ($m) {
            return [
                'to_number'  => $m->to_number,
                'message'    => $m->body,
                'status'     => $this->statusCode($m->status),
                'created_at' => optional($m->created_at)->toIso8601String(),
            ];
        });

        return response()->json(['data' => $paginator]);
    }

    /** GET /api/ext/credits — plan + usage snapshot. */
    public function credits(Request $request): JsonResponse
    {
        $user    = $request->user();
        $isAdmin = method_exists($user, 'isAdmin') ? (bool) $user->isAdmin() : false;
        $ws      = $user->currentWorkspace ?? null;

        $delivered = Message::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ['sent', 'delivered', 'read'])
            ->count();

        return response()->json([
            'success' => true,
            'data'    => [
                'plan_name'              => $ws->plan_name ?? ($isAdmin ? 'Admin' : 'Workspace'),
                'is_admin'               => $isAdmin,
                'unlimited_access'       => $isAdmin,
                'monthly_messages_limit' => (int) ($user->wallet_credits ?? 0),
                'delivered_count'        => $delivered,
                'plan_expiry'            => optional($ws->plan_expires_at ?? null)?->toIso8601String(),
            ],
        ]);
    }

    /** POST /api/ext/send-quick-message — send one message (text and/or media). */
    public function sendQuickMessage(Request $request, WhatsAppDispatcher $dispatcher): JsonResponse
    {
        $data = $request->validate([
            'to_number'    => 'required|string|max:32',
            'from_number'  => 'required|string|max:32',
            'message_text' => 'nullable|string',
            'image_file'   => 'nullable|file|max:8192',  // 8 MB
        ]);

        $to   = preg_replace('/\D+/', '', $data['to_number']);
        $from = preg_replace('/\D+/', '', $data['from_number']);
        $body = $data['message_text'] ?? '';

        if ($to === '' || strlen($to) < 8) {
            return response()->json(['status' => 'error', 'message' => 'Invalid destination number.'], 422);
        }
        if ($body === '' && !$request->hasFile('image_file')) {
            return response()->json(['status' => 'error', 'message' => 'Nothing to send.'], 422);
        }

        $params = ['from_number' => $from, 'to_number' => $to, 'body' => $body];

        if ($request->hasFile('image_file')) {
            $path = $request->file('image_file')->store('extension-sends', media_disk());
            $params['media_path'] = media_url($path);
            $params['media_type'] = $request->file('image_file')->getMimeType();
        }

        try {
            $result = $dispatcher->sendRaw($params, $request->user()->id, 'W');
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }

        $ok = !isset($result['success']) || $result['success'] !== false;
        if (!$ok) {
            return response()->json([
                'status'  => 'error',
                'message' => $result['message'] ?? $result['error'] ?? 'Send failed.',
            ], 422);
        }

        return response()->json(['status' => 'success', 'result' => $result]);
    }

    /** GET /api/ext/contact-csv — export this user's send history as CSV. */
    public function contactCsv(Request $request)
    {
        $user = $request->user();
        $rows = Message::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->limit(5000)
            ->get(['to_number', 'body', 'status', 'created_at']);

        $sanitize = function ($v) {
            $v = (string) ($v ?? '');
            // CSV formula-injection guard.
            if ($v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
                $v = "'" . $v;
            }
            return '"' . str_replace('"', '""', $v) . '"';
        };

        $lines = ['phone_number,message,status,sent_at'];
        foreach ($rows as $m) {
            $lines[] = implode(',', [
                $sanitize($m->to_number),
                $sanitize(mb_substr((string) $m->body, 0, 200)),
                $sanitize($m->status),
                $sanitize(optional($m->created_at)->toDateTimeString()),
            ]);
        }

        return response(implode("\n", $lines), 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="wadesk-history.csv"',
        ]);
    }

    /** Map our string message status to the 1/2/0 the extension expects. */
    private function statusCode($status): int
    {
        $s = strtolower((string) $status);
        if (in_array($s, ['sent', 'delivered', 'read', '1'], true)) return 1;
        if (in_array($s, ['failed', 'error', '2'], true)) return 2;
        return 0;
    }
}
