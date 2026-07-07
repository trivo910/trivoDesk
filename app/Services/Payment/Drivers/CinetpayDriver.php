<?php

namespace App\Services\Payment\Drivers;

use App\Models\Order;
use App\Services\Payment\AbstractGatewayDriver;
use App\Services\Payment\PaymentResult;
use Illuminate\Support\Facades\Http;

/**
 * CinetPay payment gateway driver (Francophone West Africa).
 *
 * Creates a CinetPay payment and redirects to the hosted page. Server
 * confirmation is fetched via the payment/check endpoint.
 *
 * @see https://docs.cinetpay.com/
 */
class CinetpayDriver extends AbstractGatewayDriver
{
    private const API_BASE = 'https://api-checkout.cinetpay.com/v2';

    public static function credentialFields(): array
    {
        return [
            'api_key'    => ['label' => 'API Key',    'type' => 'text',     'required' => true],
            'site_id'    => ['label' => 'Site ID',    'type' => 'text',     'required' => true],
            'secret_key' => ['label' => 'Secret Key', 'type' => 'password', 'required' => false, 'hint' => 'For webhook verification.'],
        ];
    }

    public function initiate(Order $order, string $callbackUrl): PaymentResult
    {
        $apiKey = (string) $this->cred('api_key');
        $siteId = (string) $this->cred('site_id');
        if ($apiKey === '' || $siteId === '') return PaymentResult::failed('cinetpay_credentials_missing');

        $email = optional($order->user)->email;
        if (!$email) return PaymentResult::failed('cinetpay_customer_email_missing');

        $txId = 'CINET_' . $order->order_number . '_' . time();
        $body = [
            'apikey'           => $apiKey,
            'site_id'          => $siteId,
            'transaction_id'   => $txId,
            'amount'           => (int) round((float) $order->amount),
            'currency'         => strtoupper($order->currency ?? 'XOF'),
            'description'      => "Order #{$order->order_number}",
            'return_url'       => $callbackUrl,
            'notify_url'       => route('payment.webhook', ['gateway' => 'cinetpay']),
            'channels'         => 'ALL',
            'metadata'         => json_encode(['order_id' => $order->id, 'order_number' => $order->order_number]),
            'customer_name'    => optional($order->user)->name ?? 'Customer',
            'customer_email'   => $email,
            'customer_surname' => 'User',
        ];

        try {
            $r = Http::asJson()->acceptJson()->timeout(self::HTTP_TIMEOUT_SECONDS)
                ->post(self::API_BASE . '/payment', $body);
            $json = $r->json() ?: [];
            if (($json['code'] ?? '') === '201' && isset($json['data']['payment_url'])) {
                return PaymentResult::redirect($json['data']['payment_url'], $txId, $json);
            }
            return PaymentResult::failed('cinetpay: ' . ($json['message'] ?? 'create_failed'));
        } catch (\Throwable $e) {
            return PaymentResult::failed('cinetpay_exception: ' . $e->getMessage());
        }
    }

    public function handleCallback(array $payload): PaymentResult
    {
        $txId = $payload['transaction_id'] ?? $payload['cpm_trans_id'] ?? null;
        if (!$txId) return PaymentResult::failed('missing_cinetpay_tx');

        $apiKey = (string) $this->cred('api_key');
        $siteId = (string) $this->cred('site_id');

        try {
            $r = Http::asJson()->acceptJson()->timeout(self::HTTP_TIMEOUT_SECONDS)
                ->post(self::API_BASE . '/payment/check', [
                    'apikey'         => $apiKey,
                    'site_id'        => $siteId,
                    'transaction_id' => $txId,
                ]);
            $json     = $r->json() ?: [];
            $respCode = $json['code'] ?? '';
            $status   = $json['data']['status'] ?? '';
            if ($respCode !== '00') return PaymentResult::failed("cinetpay_check_code: {$respCode}", $json);
            if ($status === 'ACCEPTED') {
                return PaymentResult::paid(
                    gatewayPaymentId: (string) $txId,
                    gatewayOrderId:   (string) $txId,
                    payload:          $json,
                );
            }
            return PaymentResult::failed("cinetpay_status: {$status}", $json);
        } catch (\Throwable $e) {
            return PaymentResult::failed('cinetpay_callback_exception: ' . $e->getMessage());
        }
    }

    public function handleWebhook(array $payload): PaymentResult
    {
        $txId = $payload['cpm_trans_id'] ?? null;
        if (!$txId) return PaymentResult::failed('missing_cinetpay_tx');
        return $this->handleCallback(['transaction_id' => $txId]);
    }
}
