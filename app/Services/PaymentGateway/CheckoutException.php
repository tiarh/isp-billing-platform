<?php

namespace App\Services\PaymentGateway;

use RuntimeException;

/**
 * Checkout refusal with a message that is safe to show to the customer.
 */
class CheckoutException extends RuntimeException
{
    public const UNAVAILABLE = 'unavailable';

    public const NOT_PAYABLE = 'not_payable';

    public const VOID = 'void';

    public const INVALID_METHOD = 'invalid_method';

    public const AMOUNT_TOO_SMALL = 'amount_too_small';

    public const BUSY = 'busy';

    public const PROVIDER_ERROR = 'provider_error';

    public const TOO_MANY_OPEN = 'too_many_open';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function unavailable(): self
    {
        return new self(self::UNAVAILABLE, 'Pembayaran online belum tersedia. Gunakan transfer bank atau hubungi admin.');
    }

    public static function notPayable(): self
    {
        return new self(self::NOT_PAYABLE, 'Tagihan ini sudah lunas atau tidak bisa dibayar online.');
    }

    public static function void(): self
    {
        return new self(self::VOID, 'Tagihan ini sudah dibatalkan dan tidak dapat dibayar. Hubungi admin.');
    }

    public static function invalidMethod(): self
    {
        return new self(self::INVALID_METHOD, 'Metode pembayaran tidak tersedia. Pilih metode lain.');
    }

    public static function amountTooSmall(int $min): self
    {
        return new self(self::AMOUNT_TOO_SMALL, 'Nominal di bawah minimum pembayaran online (Rp '.number_format($min, 0, ',', '.').'). Hubungi admin.');
    }

    public static function busy(): self
    {
        return new self(self::BUSY, 'Pembayaran sedang diproses. Coba lagi beberapa detik lagi.');
    }

    public static function providerError(): self
    {
        return new self(self::PROVIDER_ERROR, 'Pembayaran online sedang gangguan. Coba lagi sebentar atau gunakan transfer bank.');
    }

    public static function tooManyOpen(): self
    {
        return new self(self::TOO_MANY_OPEN, 'Anda sudah punya 3 kode bayar aktif. Gunakan salah satu atau tunggu hingga kedaluwarsa.');
    }

    public function httpStatus(): int
    {
        return match ($this->reason) {
            self::TOO_MANY_OPEN => 409,
            self::BUSY => 429,
            self::PROVIDER_ERROR => 502,
            default => 422,
        };
    }
}
