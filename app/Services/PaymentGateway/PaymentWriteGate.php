<?php

namespace App\Services\PaymentGateway;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Production Control Plane kill switch "Payment write"
 * (Setting control_plane.payment_writes_enabled, default OFF).
 *
 * Scope: online payment gateway money movement only.
 *  - OFF: no new gateway charges can be created, and paid gateway
 *    notifications are stored as held_kill_switch (never credited) until the
 *    switch is turned ON again, at which point they are released.
 *  - Manual/admin payments (PaymentController) are NOT gated by this switch.
 *
 * Deliberately independent of control_plane.device_read_only_mode: that switch
 * protects network devices, not money.
 */
class PaymentWriteGate
{
    public const SETTING_KEY = 'control_plane.payment_writes_enabled';

    public function enabled(): bool
    {
        $value = Setting::get(self::SETTING_KEY, false);
        if (is_bool($value)) {
            return $value;
        }

        return in_array(Str::lower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    public function set(bool $enabled, ?User $actor = null): void
    {
        $before = $this->enabled();
        Setting::set(self::SETTING_KEY, $enabled ? '1' : '0', 'boolean', 'control_plane', 'Payment write');

        if ($before !== $enabled) {
            activity()
                ->causedBy($actor)
                ->withProperties(['key' => self::SETTING_KEY, 'from' => $before, 'to' => $enabled])
                ->log($enabled ? 'payment_write_kill_switch_on' : 'payment_write_kill_switch_off');
        }
    }
}
