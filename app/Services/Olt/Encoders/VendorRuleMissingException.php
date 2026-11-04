<?php

namespace App\Services\Olt\Encoders;

class VendorRuleMissingException extends \RuntimeException
{
    public static function forVendor(string $vendor, string $rule): self
    {
        return new self(sprintf(
            'Vendor rule [%s] missing for [%s]; official CLI spec required — see docs/vendor-cli-encoding-specs.md',
            $rule,
            $vendor,
        ));
    }
}
