<?php

namespace App\Services\Olt\Encoders;

interface VendorCommandEncoder
{
    /**
     * Encode a single CLI argument value.
     *
     * MUST reject known-dangerous patterns via VendorRuleViolationException.
     * MAY throw VendorRuleMissingException for vendor-specific limits pending
     * documentation.
     */
    public function encodeArg(string $value): string;

    /** Vendor-specific max length per CLI argument. Default 256 universal floor. */
    public function maxArgLength(): int;

    /** Universally-forbidden characters. Vendor-specific blacklist TBD. */
    public function forbidChars(): array;

    /** Vendor identifier for logging/routing. */
    public function vendorName(): string;
}
