<?php

namespace App\Support;

use App\Models\Olt;

class OltVendor
{
    public static function isEnterprise(Olt $olt): bool
    {
        $vendor = strtolower(trim((string) ($olt->vendor ?? '')));

        if ($vendor === '') {
            return true;
        }

        return str_contains($vendor, 'zte')
            || str_contains($vendor, 'c300')
            || str_contains($vendor, 'fiberhome')
            || str_contains($vendor, 'huawei')
            || str_contains($vendor, 'ma5608');
    }
}
