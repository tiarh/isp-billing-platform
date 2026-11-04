<?php

namespace App\Services\PaymentGateway\Drivers;

use App\Contracts\PaymentGateway;
use App\Services\PaymentGateway\Data\ChargeRequest;
use App\Services\PaymentGateway\Data\ChargeResult;
use App\Services\PaymentGateway\Data\PaymentMethodOption;
use App\Services\PaymentGateway\Data\StatusResult;
use App\Services\PaymentGateway\Duitku\DuitkuConfig;
use App\Services\PaymentGateway\Duitku\DuitkuSignature;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Duitku direct API (v2/inquiry): VA / QRIS / e-wallet / retail shown or
 * redirected from our own /pay page.
 *
 * Endpoints (docs.duitku.com/api/en):
 *  - POST {base}/paymentmethod/getpaymentmethod
 *  - POST {base}/v2/inquiry
 *  - POST {base}/transactionStatus (rate limited by Duitku: keep occasional)
 */
class DuitkuGateway implements PaymentGateway
{
    /**
     * Supported method codes → group. Card, paylater, account-link and
     * e-commerce methods need extra customer/item data and are not offered.
     */
    public const METHOD_GROUPS = [
        // Virtual accounts
        'BC' => PaymentMethodOption::GROUP_VA, 'M2' => PaymentMethodOption::GROUP_VA,
        'VA' => PaymentMethodOption::GROUP_VA, 'I1' => PaymentMethodOption::GROUP_VA,
        'B1' => PaymentMethodOption::GROUP_VA, 'BT' => PaymentMethodOption::GROUP_VA,
        'A1' => PaymentMethodOption::GROUP_VA, 'AG' => PaymentMethodOption::GROUP_VA,
        'NC' => PaymentMethodOption::GROUP_VA, 'BR' => PaymentMethodOption::GROUP_VA,
        'S1' => PaymentMethodOption::GROUP_VA, 'DM' => PaymentMethodOption::GROUP_VA,
        'BV' => PaymentMethodOption::GROUP_VA,
        // QRIS
        'SP' => PaymentMethodOption::GROUP_QRIS, 'NQ' => PaymentMethodOption::GROUP_QRIS,
        'SQ' => PaymentMethodOption::GROUP_QRIS,
        // Direct e-wallets
        'OV' => PaymentMethodOption::GROUP_EWALLET, 'SA' => PaymentMethodOption::GROUP_EWALLET,
        'LF' => PaymentMethodOption::GROUP_EWALLET, 'LA' => PaymentMethodOption::GROUP_EWALLET,
        'DA' => PaymentMethodOption::GROUP_EWALLET,
        // Retail outlets
        'FT' => PaymentMethodOption::GROUP_RETAIL, 'IR' => PaymentMethodOption::GROUP_RETAIL,
    ];

    /** Provider default validity (minutes) used only to display an expiry when none was requested. */
    private const DEFAULT_EXPIRY = [
        PaymentMethodOption::GROUP_VA => 1440,
        PaymentMethodOption::GROUP_RETAIL => 1440,
        PaymentMethodOption::GROUP_QRIS => 10,
        PaymentMethodOption::GROUP_EWALLET => 10,
    ];

    public function __construct(private readonly DuitkuConfig $config) {}

    public function provider(): string
    {
        return 'duitku';
    }

    public function label(): string
    {
        return 'Duitku';
    }

    public function isConfigured(): bool
    {
        return $this->config->isConfigured();
    }

    public function isProduction(): bool
    {
        return $this->config->isProduction();
    }

    /**
     * All supported methods the merchant account offers for this amount,
     * ignoring the admin selection (used by the settings page).
     *
     * @return list<PaymentMethodOption>
     */
    public function availableMethods(int $amount): array
    {
        $this->assertConfigured();
        $key = sprintf('duitku:methods:%s:%s:%d', $this->config->environment(), md5($this->config->merchantCode()), $amount);

        $rows = Cache::remember($key, now()->addMinutes(10), function () use ($amount): array {
            $datetime = now('Asia/Jakarta')->format('Y-m-d H:i:s');
            $response = $this->http()->post($this->config->baseUrl().'/paymentmethod/getpaymentmethod', [
                'merchantcode' => $this->config->merchantCode(),
                'amount' => $amount,
                'datetime' => $datetime,
                'signature' => DuitkuSignature::paymentMethods(
                    $this->config->merchantCode(), $amount, $datetime, $this->config->apiKey(), $this->config->signatureMode(),
                ),
            ]);
            $this->assertOk($response, 'getpaymentmethod');

            $rows = $response->json('paymentFee');
            if (! is_array($rows)) {
                throw new RuntimeException('Duitku getpaymentmethod: unexpected response ('.(string) $response->json('responseMessage', 'no paymentFee').')');
            }

            return array_values(array_filter($rows, 'is_array'));
        });

        $options = [];
        foreach ($rows as $row) {
            $code = strtoupper(trim((string) ($row['paymentMethod'] ?? '')));
            if (! isset(self::METHOD_GROUPS[$code])) {
                continue;
            }
            $options[] = new PaymentMethodOption(
                code: $code,
                name: trim((string) ($row['paymentName'] ?? $code)) ?: $code,
                group: self::METHOD_GROUPS[$code],
                fee: (int) ceil((float) ($row['totalFee'] ?? 0)),
                imageUrl: is_string($row['paymentImage'] ?? null) ? $row['paymentImage'] : null,
            );
        }

        return $options;
    }

    public function paymentMethods(int $amount): array
    {
        $enabled = $this->config->enabledMethods();
        $options = $this->availableMethods($amount);
        if ($enabled === []) {
            return $options;
        }

        return array_values(array_filter($options, fn (PaymentMethodOption $o): bool => in_array($o->code, $enabled, true)));
    }

    public function createCharge(ChargeRequest $request): ChargeResult
    {
        $this->assertConfigured();
        $code = strtoupper($request->methodCode);
        $group = self::METHOD_GROUPS[$code] ?? PaymentMethodOption::GROUP_OTHER;
        $merchantCode = $this->config->merchantCode();

        $payload = array_filter([
            'merchantCode' => $merchantCode,
            'paymentAmount' => $request->amount,
            'paymentMethod' => $code,
            'merchantOrderId' => $request->merchantOrderId,
            'productDetails' => mb_substr($request->productDetails, 0, 255),
            'email' => mb_substr($request->email, 0, 255),
            'phoneNumber' => $request->phone ? mb_substr($request->phone, 0, 50) : null,
            'customerVaName' => mb_substr(trim(preg_replace('/[^\pL\pN ]+/u', ' ', $request->customerName) ?? '') ?: 'Pelanggan', 0, 20),
            'callbackUrl' => mb_substr($request->callbackUrl, 0, 255),
            'returnUrl' => mb_substr($request->returnUrl, 0, 255),
            'expiryPeriod' => $request->expiryMinutes,
            'signature' => DuitkuSignature::inquiry(
                $merchantCode, $request->merchantOrderId, $request->amount, $this->config->apiKey(), $this->config->signatureMode(),
            ),
        ], fn ($value): bool => $value !== null && $value !== '');

        $response = $this->http()->post($this->config->baseUrl().'/v2/inquiry', $payload);
        $this->assertOk($response, 'inquiry');

        $statusCode = (string) $response->json('statusCode', '');
        if ($statusCode !== '' && $statusCode !== '00') {
            throw new RuntimeException('Duitku inquiry: status '.$statusCode.' '.mb_substr((string) $response->json('statusMessage', ''), 0, 120));
        }

        $vaNumber = trim((string) $response->json('vaNumber', ''));
        $qrString = trim((string) $response->json('qrString', ''));
        $paymentUrl = trim((string) $response->json('paymentUrl', ''));
        $reference = trim((string) $response->json('reference', ''));
        if ($vaNumber === '' && $qrString === '' && $paymentUrl === '') {
            throw new RuntimeException('Duitku inquiry: response without vaNumber/qrString/paymentUrl');
        }

        $kind = match (true) {
            $qrString !== '' => ChargeResult::KIND_QR,
            $vaNumber !== '' => ChargeResult::KIND_VA,
            default => ChargeResult::KIND_REDIRECT,
        };
        $expiry = $request->expiryMinutes ?? (self::DEFAULT_EXPIRY[$group] ?? null);

        return new ChargeResult(
            kind: $kind,
            merchantOrderId: $request->merchantOrderId,
            providerReference: $reference !== '' ? $reference : null,
            amount: (int) round((float) $response->json('amount', $request->amount)),
            paymentUrl: $paymentUrl !== '' ? $paymentUrl : null,
            vaNumber: $vaNumber !== '' ? $vaNumber : null,
            qrString: $qrString !== '' ? $qrString : null,
            expiresAt: $expiry ? now()->addMinutes($expiry) : null,
            raw: [
                'reference' => $reference,
                'statusCode' => $statusCode,
                'statusMessage' => mb_substr((string) $response->json('statusMessage', ''), 0, 120),
                'amount' => $response->json('amount'),
                'environment' => $this->config->environment(),
            ],
        );
    }

    public function checkStatus(string $merchantOrderId): StatusResult
    {
        $this->assertConfigured();
        $merchantCode = $this->config->merchantCode();

        $response = $this->http()->post($this->config->baseUrl().'/transactionStatus', [
            'merchantCode' => $merchantCode,
            'merchantOrderId' => $merchantOrderId,
            'signature' => DuitkuSignature::status($merchantCode, $merchantOrderId, $this->config->apiKey(), $this->config->signatureMode()),
        ]);

        if (! $response->successful()) {
            Log::warning('[Duitku] transactionStatus failed', ['order_id' => $merchantOrderId, 'status' => $response->status()]);

            return StatusResult::unknown(['http_status' => $response->status()]);
        }

        if ((string) $response->json('merchantOrderId', $merchantOrderId) !== $merchantOrderId) {
            return StatusResult::unknown(['reason' => 'order_id_mismatch']);
        }

        $raw = [
            'statusCode' => (string) $response->json('statusCode', ''),
            'statusMessage' => mb_substr((string) $response->json('statusMessage', ''), 0, 120),
            'reference' => $response->json('reference'),
            'amount' => $response->json('amount'),
            'fee' => $response->json('fee'),
        ];
        $amount = is_numeric($response->json('amount')) ? (int) round((float) $response->json('amount')) : null;
        $fee = is_numeric($response->json('fee')) ? (int) round((float) $response->json('fee')) : null;
        $reference = $response->json('reference') ? (string) $response->json('reference') : null;

        return match ($raw['statusCode']) {
            '00' => new StatusResult(StatusResult::PAID, $amount, $fee, $reference, $raw),
            '01' => new StatusResult(StatusResult::PENDING, $amount, $fee, $reference, $raw),
            '02' => new StatusResult(StatusResult::FAILED, $amount, $fee, $reference, $raw),
            default => StatusResult::unknown($raw),
        };
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()
            ->asJson()
            ->timeout(max(5, (int) config('services.duitku.http_timeout', 20)));
    }

    private function assertConfigured(): void
    {
        if (! $this->config->isConfigured()) {
            throw new RuntimeException('Duitku belum dikonfigurasi (merchant code / API key).');
        }
    }

    private function assertOk(Response $response, string $endpoint): void
    {
        if ($response->successful()) {
            return;
        }

        $message = (string) ($response->json('Message') ?? $response->json('message') ?? $response->json('statusMessage') ?? '');
        Log::warning('[Duitku] request failed', [
            'endpoint' => $endpoint,
            'status' => $response->status(),
            'message' => mb_substr($message, 0, 200),
        ]);

        throw new RuntimeException(sprintf('Duitku %s HTTP %d%s', $endpoint, $response->status(), $message !== '' ? ': '.mb_substr($message, 0, 120) : ''));
    }
}
