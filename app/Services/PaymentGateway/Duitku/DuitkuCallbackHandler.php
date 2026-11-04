<?php

namespace App\Services\PaymentGateway\Duitku;

use App\Models\PaymentGatewayTransaction;
use App\Services\PaymentGateway\GatewayPaymentRecorder;
use App\Services\PaymentGatewayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * POST /webhooks/payment-gateway/duitku (x-www-form-urlencoded).
 *
 * Order of checks: configured → source IP whitelist (optional) → required
 * fields → merchantCode → signature (HMAC-SHA256 or legacy MD5, both keyed
 * with the API key) → known order → amount equals amount_charged → credit
 * via PaymentGatewayService. Only body fields are read (never the query).
 *
 * Every verified callback is answered 200 (even when rejected for money
 * reasons, which go to ops review) so Duitku does not keep retrying.
 */
class DuitkuCallbackHandler
{
    public const LAST_REJECTED_IP_CACHE_KEY = 'payment-gateway:duitku:last-rejected-ip';

    public function __construct(
        private readonly DuitkuConfig $config,
        private readonly GatewayPaymentRecorder $recorder,
        private readonly PaymentGatewayService $payments,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        if (! $this->config->isConfigured()) {
            return $this->deny(401, 'Duitku not configured');
        }

        $ip = (string) $request->ip();
        if ($this->config->ipWhitelistEnabled()) {
            $allowed = $this->config->callbackIps();
            if ($allowed !== [] && ! IpUtils::checkIp($ip, $allowed)) {
                Log::warning('[Duitku] callback from non-whitelisted IP', ['ip' => $ip]);
                Cache::put(self::LAST_REJECTED_IP_CACHE_KEY, ['ip' => $ip, 'at' => now()->toISOString()], now()->addDays(7));

                return $this->deny(403, 'Source not allowed');
            }
        }

        $fields = $request->request->all();
        $merchantCode = trim((string) ($fields['merchantCode'] ?? ''));
        $amount = trim((string) ($fields['amount'] ?? ''));
        $orderId = trim((string) ($fields['merchantOrderId'] ?? ''));
        $resultCode = trim((string) ($fields['resultCode'] ?? ''));
        $signature = (string) ($fields['signature'] ?? '');

        if ($merchantCode === '' || $amount === '' || $orderId === '' || $signature === '' || ! is_numeric($amount)) {
            return $this->deny(400, 'Missing fields');
        }

        if (! hash_equals($this->config->merchantCode(), $merchantCode)) {
            Log::warning('[Duitku] callback for another merchant code', ['order_id' => $orderId]);

            return $this->deny(401, 'Invalid merchant');
        }

        $mode = DuitkuSignature::verifyCallback($merchantCode, $amount, $orderId, $signature, $this->config->apiKey());
        if ($mode === null) {
            Log::warning('[Duitku] invalid callback signature', ['order_id' => $orderId, 'ip' => $ip]);

            return $this->deny(401, 'Invalid signature');
        }

        $payload = $this->auditPayload($fields, $ip, $mode);
        $txn = PaymentGatewayTransaction::query()
            ->where('provider', 'duitku')
            ->where('merchant_order_id', $orderId)
            ->first();

        if (! $txn) {
            $event = $this->payments->process(
                provider: 'duitku',
                eventKey: $orderId.':cb:'.$resultCode,
                creditKey: $orderId,
                reference: $orderId,
                status: $resultCode === '00' ? GatewayPaymentRecorder::STATUS_SUCCESS : 'failed',
                amount: (float) $amount,
                paid: false,
                payload: $payload,
                eventStatusOverride: $resultCode === '00' ? PaymentGatewayService::STATUS_UNMATCHED : null,
            );
            if ($resultCode === '00' && $event->wasRecentlyCreated) {
                $this->payments->notifyOps('duitku', $event, 'paid callback for an unknown order id (not credited)');
            }

            return $this->ok($event->id, $event->status);
        }

        if ($txn->provider_reference === null && ! empty($fields['reference'])) {
            $txn->update(['provider_reference' => mb_substr((string) $fields['reference'], 0, 100)]);
        }

        $event = $resultCode === '00'
            ? $this->recorder->recordPaid($txn, (int) round((float) $amount), $orderId.':cb:00', $payload)
            : $this->recorder->recordNotPaid($txn, $orderId.':cb:'.$resultCode, 'failed', $payload, final: true);

        return $this->ok($event->id, $event->status);
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function auditPayload(array $fields, string $ip, string $mode): array
    {
        $keep = ['merchantCode', 'amount', 'merchantOrderId', 'productDetail', 'additionalParam', 'paymentCode',
            'resultCode', 'merchantUserId', 'reference', 'publisherOrderId', 'settlementDate', 'issuerCode'];

        return array_merge(
            array_intersect_key($fields, array_flip($keep)),
            ['_source_ip' => $ip, '_signature_mode' => $mode],
        );
    }

    private function ok(int $eventId, string $status): JsonResponse
    {
        return response()->json(['ok' => true, 'event_id' => $eventId, 'status' => $status]);
    }

    private function deny(int $status, string $message): JsonResponse
    {
        return response()->json(['ok' => false, 'message' => $message], $status);
    }
}
