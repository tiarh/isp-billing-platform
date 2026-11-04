<?php

namespace App\Services\PaymentGateway\Data;

use Carbon\CarbonInterface;

final class ChargeResult
{
    /** Send the customer to $paymentUrl (hosted page, e-wallet checkout). */
    public const KIND_REDIRECT = 'redirect';

    /** Show $vaNumber (virtual account / retail payment code). */
    public const KIND_VA = 'va';

    /** Render $qrString as a QR code (QRIS). */
    public const KIND_QR = 'qr';

    /**
     * @param  array<string, mixed>  $raw  provider response without secrets (stored for audit)
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $merchantOrderId,
        public readonly ?string $providerReference,
        public readonly int $amount,
        public readonly ?string $paymentUrl = null,
        public readonly ?string $vaNumber = null,
        public readonly ?string $qrString = null,
        public readonly ?CarbonInterface $expiresAt = null,
        public readonly array $raw = [],
    ) {}
}
