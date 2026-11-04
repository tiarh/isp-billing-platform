<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\GenieAcsDevice;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class CustomerConnectivityService
{
    public function pingFreshMinutes(): int
    {
        return max(1, (int) config('services.genieacs.ping_fresh_minutes', 45));
    }

    public function isPonOnline(?string $state): bool
    {
        $state = strtolower(trim((string) $state));

        if ($state === '') {
            return false;
        }

        return in_array($state, ['online', 'working', 'up', 'active'], true)
            || str_contains($state, 'online')
            || str_contains($state, 'working')
            || str_contains($state, 'operation');
    }

    public function isPingOk(?GenieAcsDevice $device): ?bool
    {
        if (! $device || ! $device->last_ping_at) {
            return null;
        }

        $pingAt = $device->last_ping_at instanceof CarbonInterface
            ? $device->last_ping_at
            : Carbon::parse((string) $device->last_ping_at);

        if (! $pingAt->gt(now()->subMinutes($this->pingFreshMinutes()))) {
            return null;
        }

        return (bool) $device->last_ping_success;
    }

    /**
     * @return array{
     *   pon_online: bool,
     *   pppoe_online: bool,
     *   acs_reachable: bool|null,
     *   ping_ok: bool|null,
     *   service_online: bool,
     *   reason: string
     * }
     */
    public function evaluate(Customer $customer, ?GenieAcsDevice $device = null): array
    {
        $device ??= $customer->relationLoaded('genieAcsDevice')
            ? $customer->genieAcsDevice
            : $customer->genieAcsDevice()->first();

        $ponOnline = $this->isPonOnline($customer->olt_status);
        $pppoeOnline = (bool) $customer->is_online;
        $acsReachable = $device?->isOnline() ?? null;
        $pingOk = $this->isPingOk($device);
        $serviceOnline = false;
        $reason = 'unknown';

        if (! $ponOnline) {
            $reason = 'pon_offline';
        } elseif (! $pppoeOnline) {
            $reason = 'pppoe_offline';
        } elseif ($device === null) {
            $serviceOnline = true;
            $reason = 'pon_and_pppoe_no_acs';
        } elseif ($pingOk === true) {
            $serviceOnline = true;
            $reason = 'full_stack_ping_ok';
        } elseif ($pingOk === false) {
            $reason = 'ping_failed';
        } elseif ($acsReachable === false) {
            $reason = 'acs_unreachable';
        } else {
            $reason = 'ping_pending';
        }

        return [
            'pon_online' => $ponOnline,
            'pppoe_online' => $pppoeOnline,
            'acs_reachable' => $acsReachable,
            'ping_ok' => $pingOk,
            'service_online' => $serviceOnline,
            'reason' => $reason,
        ];
    }

    public function syncCustomerServiceState(Customer $customer, ?GenieAcsDevice $device = null): array
    {
        $result = $this->evaluate($customer, $device);
        $fill = [
            'service_online' => $result['service_online'],
            'service_online_at' => now(),
        ];

        if (Schema::hasColumn('customers', 'service_online_reason')) {
            $fill['service_online_reason'] = $result['reason'];
        }

        $customer->forceFill($fill)->save();

        return $result;
    }
}
