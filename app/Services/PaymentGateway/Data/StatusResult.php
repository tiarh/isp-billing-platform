<?php

namespace App\Services\PaymentGateway\Data;

final class StatusResult
{
    public const PAID = 'paid';

    public const PENDING = 'pending';

    /** Failed, cancelled or expired at the provider: will never be paid. */
    public const FAILED = 'failed';

    /** Provider could not tell (error, not supported, not found). */
    public const UNKNOWN = 'unknown';

    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $state,
        public readonly ?int $amount = null,
        public readonly ?int $fee = null,
        public readonly ?string $providerReference = null,
        public readonly array $raw = [],
    ) {}

    public static function unknown(array $raw = []): self
    {
        return new self(self::UNKNOWN, raw: $raw);
    }
}
