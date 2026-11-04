<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Midtrans Snap — create payment + verify notification.
 * Keys via Setting (or env fallback). Never expose server_key to browser.
 */
class MidtransService
{
    public function isConfigured(): bool
    {
        return $this->serverKey() !== '' && $this->clientKey() !== '';
    }

    public function isProduction(): bool
    {
        $v = Setting::get('midtrans_is_production', config('services.midtrans.is_production', false));
        if (is_bool($v)) {
            return $v;
        }

        return in_array(strtolower(trim((string) $v)), ['1', 'true', 'yes', 'on'], true);
    }

    public function serverKey(): string
    {
        return trim(Setting::secret('midtrans_server_key') ?: (string) config('services.midtrans.server_key', ''));
    }

    public function clientKey(): string
    {
        return trim(Setting::secret('midtrans_client_key') ?: (string) config('services.midtrans.client_key', ''));
    }

    public function merchantId(): string
    {
        return trim((string) (Setting::get('midtrans_merchant_id') ?: config('services.midtrans.merchant_id', '')));
    }

    public function snapApiBase(): string
    {
        return $this->isProduction()
            ? 'https://app.midtrans.com'
            : 'https://app.sandbox.midtrans.com';
    }

    public function snapJsUrl(): string
    {
        return $this->isProduction()
            ? 'https://app.midtrans.com/snap/snap.js'
            : 'https://app.sandbox.midtrans.com/snap/snap.js';
    }

    /**
     * Create Snap transaction. Returns token + redirect_url.
     *
     * @return array{token: string, redirect_url: string, order_id: string}
     */
    public function createSnap(Invoice $invoice): array
    {
        if (! $this->isConfigured()) {
            throw new \RuntimeException('Midtrans belum dikonfigurasi (server/client key).');
        }

        $invoice->loadMissing('customer');
        $orderId = $this->orderIdForInvoice($invoice);
        $amount = $this->chargeableAmount($invoice);
        if ($amount < 1) {
            throw new \RuntimeException('Nominal invoice tidak valid.');
        }

        $customer = $invoice->customer;
        $name = $customer?->name ?: 'Pelanggan';
        $phone = preg_replace('/\D+/', '', (string) ($customer?->phone ?? '')) ?: null;

        $payload = [
            'transaction_details' => [
                'order_id' => $orderId,
                'gross_amount' => $amount,
            ],
            // Do not restrict the provider to a stale/static channel list.
            // When omitted, Snap uses the payment methods currently active in
            // the merchant's Snap preferences, including newly enabled ones.
            'customer_details' => array_filter([
                'first_name' => mb_substr($name, 0, 50),
                'phone' => $phone ? mb_substr($phone, 0, 20) : null,
            ]),
            'item_details' => [
                [
                    'id' => 'INV-'.$invoice->id,
                    'price' => $amount,
                    'quantity' => 1,
                    'name' => mb_substr('Tagihan '.($invoice->code ?: '#'.$invoice->id), 0, 50),
                ],
            ],
            'callbacks' => [
                // Keep the provider return distinct from the payment entrypoint.
                // QRIS may return here while the webhook is still pending.
                'finish' => url('/pay/'.$invoice->uuid.'?payment_result=finish'),
            ],
        ];

        $url = $this->snapApiBase().'/snap/v1/transactions';
        $response = Http::withBasicAuth($this->serverKey(), '')
            ->acceptJson()
            ->asJson()
            ->timeout(30)
            ->post($url, $payload);

        if (! $response->successful()) {
            Log::warning('[Midtrans] createSnap failed', [
                'invoice_id' => $invoice->id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException(
                'Midtrans error: '.($response->json('error_messages.0')
                    ?? $response->json('message')
                    ?? ('HTTP '.$response->status()))
            );
        }

        $token = (string) $response->json('token', '');
        $redirect = (string) $response->json('redirect_url', '');
        if ($token === '' || $redirect === '') {
            throw new \RuntimeException('Midtrans response tanpa token/redirect_url.');
        }

        // Persist the stable eBilling entrypoint, not the provider redirect.
        // Provider redirect URLs expire and bypass the public Snap bootstrap.
        $invoice->update([
            'payment_link' => route('public.invoice.show', $invoice->uuid),
        ]);

        return [
            'token' => $token,
            'redirect_url' => $redirect,
            'order_id' => $orderId,
        ];
    }

    /**
     * Charge only the remaining balance (invoice amount minus effective
     * payments). Charging the full amount after a partial/manual payment made
     * the webhook reject the money as an overpayment.
     */
    public function chargeableAmount(Invoice $invoice): int
    {
        return (int) round(max(0, (float) $invoice->amount - $invoice->totalPaid()));
    }

    public function orderIdForInvoice(Invoice $invoice): string
    {
        // Unique enough for retries: INV{id}-{YmdHis}
        return 'INV'.$invoice->id.'-'.now()->format('YmdHis');
    }

    /**
     * Midtrans notification signature:
     * sha512(order_id + status_code + gross_amount + serverKey)
     */
    public function verifyNotificationSignature(array $payload): bool
    {
        $orderId = (string) ($payload['order_id'] ?? '');
        $statusCode = (string) ($payload['status_code'] ?? '');
        $gross = (string) ($payload['gross_amount'] ?? '');
        $signature = (string) ($payload['signature_key'] ?? '');
        $server = $this->serverKey();
        if ($orderId === '' || $signature === '' || $server === '') {
            return false;
        }
        $expected = hash('sha512', $orderId.$statusCode.$gross.$server);

        return hash_equals($expected, $signature);
    }

    /**
     * Extract invoice id from order_id INV{id}-...
     */
    public function invoiceIdFromOrderId(string $orderId): ?int
    {
        if (preg_match('/^INV(\d+)(?:-|$)/i', $orderId, $m)) {
            return (int) $m[1];
        }

        return null;
    }
}
