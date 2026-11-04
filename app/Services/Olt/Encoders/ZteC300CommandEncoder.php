<?php

namespace App\Services\Olt\Encoders;

final class ZteC300CommandEncoder implements VendorCommandEncoder
{
    private const UNIVERSAL_MAX = 256;

    public function encodeArg(string $value): string
    {
        // [VENDOR-RULE-TBD] ZTE C300-specific escape rules for firmware v2.1.x.
        // Reference: docs/vendor-cli-encoding-specs.md "TBD" section.
        // Until spec arrives, only universal dangerous patterns are enforced.
        $this->validateUniversally($value, $this->vendorName());

        return $value;
    }

    public function maxArgLength(): int
    {
        // [VENDOR-RULE-TBD] ZTE C300 actual max arg length. Floor = UNIVERSAL_MAX.
        return self::UNIVERSAL_MAX;
    }

    public function forbidChars(): array
    {
        return [
            "\x00", "\x01", "\x02", "\x03", "\x04", "\x05", "\x06", "\x07",
            "\x08", "\x09", "\x0a", "\x0b", "\x0c", "\x0d", "\x0e", "\x0f",
            "\x10", "\x11", "\x12", "\x13", "\x14", "\x15", "\x16", "\x17",
            "\x18", "\x19", "\x1a", "\x1b", "\x1c", "\x1d", "\x1e", "\x1f", "\x7f",
        ];
    }

    public function vendorName(): string
    {
        return 'zte-c300';
    }

    private function validateUniversally(string $value, string $vendor): void
    {
        if (strlen($value) > self::UNIVERSAL_MAX) {
            throw new VendorRuleViolationException(sprintf(
                '[%s] argument length %d exceeds %d-char universal floor',
                $vendor,
                strlen($value),
                self::UNIVERSAL_MAX,
            ));
        }

        if (str_starts_with($value, '-')) {
            throw new VendorRuleViolationException(sprintf(
                '[%s] leading dash in value rejected (CLI option injection)',
                $vendor,
            ));
        }

        if (str_contains($value, "\r") || str_contains($value, "\n") || str_contains($value, '"')) {
            throw new VendorRuleViolationException(sprintf(
                '[%s] control char / double quote rejected',
                $vendor,
            ));
        }

        if (preg_match('/[`$()]/', $value)) {
            throw new VendorRuleViolationException(sprintf(
                '[%s] shell expansion token rejected (defensive)',
                $vendor,
            ));
        }

        if (preg_match('/[\x00-\x1f\x7f]/', $value)) {
            throw new VendorRuleViolationException(sprintf(
                '[%s] control byte rejected',
                $vendor,
            ));
        }
    }
}
