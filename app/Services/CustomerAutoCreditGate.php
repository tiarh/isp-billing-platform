<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Str;

/**
 * Kill switch "Potong saldo otomatis" (Setting billing_credit_auto_apply_enabled).
 *
 * ON: available customer credits are applied to newly generated monthly
 * invoices (ensureForPeriod) and by the daily catch-up sweep. Code default is
 * OFF; the owner turns it on after deploy. Deliberately independent of
 * PaymentWriteGate (that one only guards online gateway charges).
 */
class CustomerAutoCreditGate
{
    public const SETTING_KEY = 'billing_credit_auto_apply_enabled';

    public static function enabled(): bool
    {
        $value = Setting::get(self::SETTING_KEY, false);
        if (is_bool($value)) {
            return $value;
        }

        return in_array(Str::lower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }
}
