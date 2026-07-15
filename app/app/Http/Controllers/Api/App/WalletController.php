<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\PaymentGateway;
use App\Models\WalletTransaction;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Mobile-app Wallet API (B11).
 *
 * Two parallel balances per user:
 *   - credit   = message credits the send-path checks (one credit per
 *                outbound; price configurable via `credits_per_message`)
 *   - currency = paid top-ups in the workspace's wallet_currency_code
 *                (INR / USD / etc.). A `currency` top-up is paired with
 *                a `credit` earn row inside the same DB transaction by
 *                WalletService.
 *
 * Endpoints exposed:
 *   GET  /wallet                       — balance + KPIs
 *   GET  /wallet/transactions          — paginated ledger
 *   POST /wallet/topup                 — create a payment order for a top-up
 *   POST /wallet/topup/confirm         — confirm an order after the gateway
 *                                        SDK on the device returns success
 *
 * Every write path delegates to WalletService — direct DB writes here
 * would skip the `users.wallet_credits` mirror + the ledger denorm.
 */
class WalletController extends Controller
{
    public function __construct(private readonly WalletService $wallet)
    {
    }

    // -----------------------------------------------------------------
    // GET /wallet — current balances + price-per-message.
    // -----------------------------------------------------------------
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $credit   = (int) $this->wallet->creditBalance($user);
        $currency = (int) $this->wallet->currencyBalance($user);
        $pricePerMsg = max(1, (int) \App\Models\SystemSetting::get('credits_per_message', 1));

        return response()->json([
            'success' => true,
            'data'    => [
                'credit_balance'      => $credit,
                'currency_balance'    => $currency,
                'currency_balance_major' => round($currency / 100, 2),
                'currency_code'       => $user->wallet_currency_code ?? 'INR',
                'credits_per_message' => $pricePerMsg,
                'messages_remaining'  => (int) floor($credit / $pricePerMsg),
            ],
        ]);
    }

    // -----------------------------------------------------------------
    // GET /wallet/transactions — paginated ledger.
    // -----------------------------------------------------------------
    public function transactions(Request $request): JsonResponse
    {
        $user  = $request->user();
        $kind  = $request->query('kind');             // 'credit' | 'currency' | null=both
        $limit = max(1, min(200, (int) $request->query('limit', 50)));
        $page  = max(1, (int) $request->query('page', 1));

        $q = WalletTransaction::query()
            ->where('user_id', $user->id)
            ->when(in_array($kind, ['credit', 'currency'], true), fn ($q) => $q->where('kind', $kind))
            ->orderByDesc('id');

        $total = (clone $q)->count();
        $rows  = $q->limit($limit)->offset(($page - 1) * $limit)->get();

        return response()->json([
            'success' => true,
            'data'    => $rows->map(fn (WalletTransaction $t) => [
                'id'             => $t->id,
                'kind'           => $t->kind,            // 'credit' | 'currency'
                'type'           => $t->type,            // 'earn' | 'spend' | 'refund' | 'adjust'
                'amount'         => (int) $t->amount,    // signed; positive=add, negative=spend
                'balance_after'  => (int) $t->balance_after,
                'source'         => $t->source,
                'description'    => $t->description,
                'subject_type'   => $t->subject_type,
                'subject_id'     => $t->subject_id,
                'meta'           => is_array($t->meta) ? $t->meta : null,
                'created_at'     => $t->created_at?->toIso8601String(),
            ])->values(),
            'pagination' => [
                'page'   => $page,
                'limit'  => $limit,
                'total'  => $total,
                'pages'  => (int) ceil($total / $limit),
            ],
        ]);
    }

    // -----------------------------------------------------------------
    // POST /wallet/topup — create a pending Order the device's payment
    // SDK (Stripe / Razorpay / PayPal / …) can complete.
    //
    // Body:
    //   amount         (int, required) — minor units (paise/cents)
    //   currency       (string, optional, default: user's wallet_currency_code)
    //   gateway        (string, optional) — slug (`razorpay`, `stripe`, …)
    //   gateway_id     (int,    optional) — alt to slug
    //   description    (string, optional)
    //
    // Returns a payment intent shape identical to /create-order so the
    // app's existing payment flow works for both plan upgrades AND wallet
    // top-ups — only the `kind: "wallet_topup"` flag in meta tells the
    // /confirm endpoint to credit the wallet (vs. activate a plan).
    // -----------------------------------------------------------------
    public function topup(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'amount'      => 'required|integer|min:1',
            'currency'    => 'nullable|string|max:10',
            'gateway'     => 'nullable|string|max:64',
            'gateway_id'  => 'nullable|integer|exists:payment_gateways,id',
            'description' => 'nullable|string|max:255',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $user = $request->user();
        $workspace = $user->currentWorkspace;
        if (! $workspace) {
            return response()->json(['success' => false, 'message' => 'No workspace context.'], 422);
        }

        $currency = strtoupper((string) ($request->input('currency') ?: $user->wallet_currency_code ?: 'INR'));
        $amountMinor = (int) $request->input('amount');

        // Resolve gateway by slug OR id.
        $gateway = null;
        if ($request->filled('gateway_id')) {
            $gateway = PaymentGateway::find($request->gateway_id);
        } elseif ($request->filled('gateway')) {
            $gateway = PaymentGateway::where('slug', strtolower($request->gateway))->first();
        }
        if ($gateway && ! $gateway->is_active) {
            return response()->json(['success' => false, 'message' => 'That gateway is not available.'], 422);
        }
        if ($gateway && method_exists($gateway, 'acceptsCurrency') && ! $gateway->acceptsCurrency($currency)) {
            return response()->json([
                'success' => false,
                'message' => $gateway->name . ' does not support ' . $currency . '.',
            ], 422);
        }

        $totalMajor = round($amountMinor / 100, 2);

        $order = Order::create([
            'order_number'    => Order::generateOrderNumber(),
            'workspace_id'    => $workspace->id,
            'user_id'         => $user->id,
            'package_id'      => null,
            'gateway_id'      => $gateway?->id,
            'gateway_slug'    => $gateway?->slug,
            'currency'        => $currency,
            'amount'          => $totalMajor,
            'discount_amount' => 0,
            'tax_rate'        => 0,
            'tax_amount'      => 0,
            'total_amount'    => $totalMajor,
            'status'          => 'pending',
            'customer_name'   => $user->name,
            'customer_email'  => $user->email,
            'billing_company' => $workspace->name,
            'meta'            => ['kind' => 'wallet_topup', 'minor' => $amountMinor],
        ]);

        Log::info('[App\Wallet] topup order created', [
            'user_id' => $user->id, 'order' => $order->id, 'amount_minor' => $amountMinor, 'currency' => $currency, 'gateway' => $gateway?->slug,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Top-up order created. Complete the payment with your gateway SDK, then POST to /wallet/topup/confirm with the order_id.',
            'data'    => [
                'order_id'    => $order->order_number,
                'payment_id'  => $order->id,
                'amount'      => $totalMajor,
                'amount_minor'=> $amountMinor,
                'currency'    => $currency,
                'gateway'     => $gateway?->slug,
                'description' => $request->description ?: 'Wallet top-up',
                'user'        => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
            ],
        ], 201);
    }

    // -----------------------------------------------------------------
    // POST /wallet/topup/confirm — confirm a wallet top-up order after
    // the device's payment SDK returned success. Credits the wallet via
    // WalletService::topup() (which writes paired ledger rows + mirrors
    // the new balance onto users.wallet_credits).
    //
    // Body:
    //   order_id       (string, required) — the order_number we returned
    //   transaction_id (string, optional) — gateway txn id (e.g. razorpay_payment_id)
    // -----------------------------------------------------------------
    public function topupConfirm(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_id'       => 'required|string|max:64',
            'transaction_id' => 'nullable|string|max:191',
        ]);

        $user  = $request->user();
        $order = Order::query()
            ->where('user_id', $user->id)
            ->where('order_number', $data['order_id'])
            ->first();
        if (! $order) {
            return response()->json(['success' => false, 'message' => 'Order not found.'], 404);
        }
        if ($order->status === 'paid') {
            return response()->json([
                'success' => true,
                'message' => 'Order already confirmed.',
                'data'    => ['order_id' => $order->order_number, 'status' => 'paid'],
            ]);
        }
        $meta = is_array($order->meta) ? $order->meta : [];
        if (($meta['kind'] ?? null) !== 'wallet_topup') {
            return response()->json([
                'success' => false,
                'message' => 'This order is not a wallet top-up. Use /create-order/confirm for plan orders.',
            ], 422);
        }

        $minor = (int) ($meta['minor'] ?? round(((float) $order->total_amount) * 100));
        if ($minor <= 0) {
            return response()->json(['success' => false, 'message' => 'Order has no chargeable amount.'], 422);
        }

        try {
            $result = $this->wallet->topup(
                $user,
                $minor,
                ($order->gateway_slug ?: 'gateway') . '.topup',
                'topup.conversion',
                'Wallet top-up — order ' . $order->order_number,
                ['order_id' => $order->id, 'gateway' => $order->gateway_slug, 'transaction_id' => $data['transaction_id'] ?? null],
            );
        } catch (\Throwable $e) {
            Log::error('[App\Wallet] topupConfirm WalletService::topup threw', ['order' => $order->id, 'err' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to credit wallet.', 'error' => $e->getMessage()], 500);
        }

        $order->update([
            'status'              => 'paid',
            'paid_at'             => now(),
            'gateway_payment_id'  => $data['transaction_id'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Wallet credited.',
            'data'    => [
                'order_id'           => $order->order_number,
                'status'             => 'paid',
                'credit_added'       => $result['credit_tx']?->amount ?? null,
                'currency_added'     => $result['currency_tx']?->amount ?? null,
                'credit_balance'     => (int) $this->wallet->creditBalance($user->fresh()),
                'currency_balance'   => (int) $this->wallet->currencyBalance($user->fresh()),
            ],
        ]);
    }
}
