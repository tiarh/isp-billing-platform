<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\OltOnu;
use Illuminate\Support\Carbon;

/**
 * Vendor-agnostic "is this customer really online" rule (audit 2026-10-08).
 *
 * Three signals, each with its own freshness window
 * (config services.online_freshness.*):
 *   1. PPPoE  - customers.is_online confirmed at customers.pppoe_online_checked_at
 *   2. ONU    - OLT state (olt_onus.state/phase_state @ state_collected_at,
 *               or the customers.olt_status projection @ olt_last_synced_at)
 *   3. RX     - valid optical RX (olt_onus.rx_power_dbm @ rx_collected_at)
 *
 * Strict OLTs (vendor in strict_vendors, e.g. ZTE C300 RA/Krian, Huawei
 * Gadang): ONLINE only when all three are positive AND fresh. Any fresh
 * negative => OFFLINE. Anything else => UNKNOWN ("data basi") with the reason.
 * A stale snapshot never produces ONLINE.
 *
 * This class is pure: it reads only the given models/arrays, never a device.
 */
class OnlineStatusEvaluator
{
    /** @var list<string>|null */
    private ?array $strictVendors = null;

    public function isStrictVendor(?string $vendor): bool
    {
        $vendor = strtolower(trim((string) $vendor));
        if ($vendor === '') {
            return false;
        }

        $this->strictVendors ??= array_values(array_filter(
            (array) config('services.online_freshness.strict_vendors', ['zte', 'c300', 'huawei']),
            static fn ($needle): bool => is_string($needle) && trim($needle) !== '',
        ));

        foreach ($this->strictVendors as $needle) {
            if (str_contains($vendor, strtolower(trim($needle)))) {
                return true;
            }
        }

        return false;
    }

    public function pppMaxAgeMinutes(): int
    {
        return max(1, (int) config('services.online_freshness.ppp_max_age_minutes', 15));
    }

    public function stateMaxAgeMinutes(): int
    {
        return max(1, (int) config('services.online_freshness.olt_state_max_age_minutes', 15));
    }

    public function rxMaxAgeMinutes(): int
    {
        return max(1, (int) config('services.online_freshness.rx_max_age_minutes', 20));
    }

    /**
     * PPPoE signal. Freshness comes ONLY from pppoe_online_checked_at, which is
     * stamped for online AND offline outcomes of an authoritative session sync
     * (mikrotik_sync_checked_at is only touched for onliners, so it cannot
     * prove a fresh offline and is deliberately not used).
     *
     * @return array{online: bool, fresh: bool, checked_at: ?Carbon}
     */
    public function pppoeSignal(Customer $customer): array
    {
        $checkedAt = $this->carbon($customer->getAttribute('pppoe_online_checked_at'));

        return [
            'online' => (bool) $customer->is_online,
            'fresh' => $this->isFresh($checkedAt, $this->pppMaxAgeMinutes()),
            'checked_at' => $checkedAt,
        ];
    }

    /**
     * ONU state + RX signals from the newest of (olt_onus snapshot, customer
     * projection). State is normalized WITHOUT RX: RX is its own signal.
     *
     * @param  array<string, mixed>|null  $snapshot
     * @return array{
     *   state: array{online: bool, value: ?string, fresh: bool, checked_at: ?Carbon, source: ?string},
     *   rx: array{dbm: ?float, valid: bool, fresh: bool, checked_at: ?Carbon}
     * }
     */
    public function oltSignals(Customer $customer, ?array $snapshot): array
    {
        $projectionAt = $this->carbon($customer->olt_last_synced_at);
        $snapshotAt = $this->carbon($snapshot['state_collected_at'] ?? null);
        $useSnapshot = is_array($snapshot)
            && filled($snapshot['state'] ?? null)
            && (! $projectionAt || ($snapshotAt && $snapshotAt->gte($projectionAt)));

        if ($useSnapshot) {
            $value = OltOnu::normalizeOperationalState(
                $snapshot['state'] ?? null,
                $snapshot['phase_state'] ?? null,
            );
            $stateAt = $snapshotAt;
            $source = 'olt_onus';
        } elseif (filled($customer->olt_status)) {
            $value = OltOnu::normalizeOperationalState((string) $customer->olt_status);
            $stateAt = $projectionAt;
            $source = 'customer_projection';
        } else {
            $value = null;
            $stateAt = null;
            $source = null;
        }

        if (is_array($snapshot) && array_key_exists('rx_power_dbm', $snapshot)) {
            $rx = $snapshot['rx_power_dbm'] !== null ? (float) $snapshot['rx_power_dbm'] : null;
            $rxAt = $this->carbon($snapshot['rx_collected_at'] ?? null);
        } else {
            $rx = $customer->onu_rx_power_dbm !== null ? (float) $customer->onu_rx_power_dbm : null;
            $rxAt = $projectionAt;
        }
        $rxValid = OltOnu::isValidLiveRxPower($rx);

        return [
            'state' => [
                'online' => $value === 'online',
                'value' => $value,
                'fresh' => $value !== null && $this->isFresh($stateAt, $this->stateMaxAgeMinutes()),
                'checked_at' => $stateAt,
                'source' => $source,
            ],
            'rx' => [
                'dbm' => $rx,
                'valid' => $rxValid,
                'fresh' => $rxValid && $this->isFresh($rxAt, $this->rxMaxAgeMinutes()),
                'checked_at' => $rxAt,
            ],
        ];
    }

    /**
     * Strict three-signal verdict.
     *
     * @param  array{online: bool, fresh: bool, checked_at: ?Carbon}  $ppp
     * @param  array{state: array<string, mixed>, rx: array<string, mixed>}  $olt
     * @return array{status: string, reason_code: string, reason: string, confidence: string}
     */
    public function strictVerdict(array $ppp, array $olt): array
    {
        $state = $olt['state'];
        $rx = $olt['rx'];

        // Only an explicit offline verdict is negative evidence; an
        // unrecognized state (e.g. "deactive") is treated as unknown.
        if ($state['fresh'] && $state['value'] === 'offline') {
            return $this->verdict(
                'offline',
                $ppp['fresh'] && $ppp['online'] ? 'onu_offline_pppoe_active' : 'onu_offline',
                'ONU offline di OLT ('.$this->age($state['checked_at']).')'
                    .($ppp['fresh'] && $ppp['online'] ? '; sesi PPPoE masih aktif, cek mapping ONU/pelanggan.' : '.'),
                'high',
            );
        }

        if ($ppp['fresh'] && ! $ppp['online']) {
            return $this->verdict(
                'offline',
                $state['fresh'] && $state['online'] ? 'pppoe_inactive_onu_online' : 'pppoe_inactive',
                'PPPoE tidak aktif di MikroTik ('.$this->age($ppp['checked_at']).')'
                    .($state['fresh'] && $state['online'] ? '; ONU masih online di OLT.' : '.'),
                'high',
            );
        }

        if ($ppp['fresh'] && $ppp['online'] && $state['fresh'] && $state['online'] && $rx['fresh']) {
            return $this->verdict(
                'online',
                'pppoe_onu_rx_fresh',
                sprintf(
                    'PPPoE aktif (%s), ONU online di OLT (%s), RX %.2f dBm (%s).',
                    $this->age($ppp['checked_at']),
                    $this->age($state['checked_at']),
                    (float) $rx['dbm'],
                    $this->age($rx['checked_at']),
                ),
                'high',
            );
        }

        $missing = [];
        if (! $ppp['fresh']) {
            $missing[] = 'PPPoE '.($ppp['checked_at'] ? 'cek terakhir '.$this->age($ppp['checked_at']) : 'belum pernah dicek');
        }
        if ($state['value'] === null) {
            $missing[] = 'state ONU tidak ada di snapshot OLT';
        } elseif (! $state['fresh']) {
            $missing[] = 'state ONU '.($state['checked_at'] ? 'dari '.$this->age($state['checked_at']) : 'tanpa waktu cek');
        } elseif (! $state['online']) {
            $missing[] = 'state ONU tidak dikenal ('.$state['value'].')';
        }
        if (! $rx['valid']) {
            $missing[] = 'RX/redaman tidak terbaca';
        } elseif (! $rx['fresh']) {
            $missing[] = 'RX '.($rx['checked_at'] ? 'dari '.$this->age($rx['checked_at']) : 'tanpa waktu cek');
        }

        return $this->verdict(
            'unknown',
            ! $ppp['fresh'] || ($state['value'] !== null && ! $state['fresh']) || ($rx['valid'] && ! $rx['fresh'])
                ? 'stale_evidence'
                : 'insufficient_evidence',
            'Tidak diketahui (data basi): '.implode('; ', $missing).'.',
            'low',
        );
    }

    public function isFresh(?Carbon $at, int $maxAgeMinutes): bool
    {
        return $at !== null && $at->gt(now()->subMinutes($maxAgeMinutes));
    }

    public function age(?Carbon $at): string
    {
        if (! $at) {
            return 'belum pernah';
        }

        $minutes = (int) max(0, floor($at->diffInSeconds(now(), false) / 60));
        if ($minutes < 60) {
            return $minutes.' mnt lalu';
        }
        if ($minutes < 1440) {
            return intdiv($minutes, 60).' jam lalu';
        }

        return intdiv($minutes, 1440).' hari lalu';
    }

    public function carbon(mixed $value): ?Carbon
    {
        if (! $value) {
            return null;
        }

        return $value instanceof \DateTimeInterface ? Carbon::instance($value) : Carbon::parse((string) $value);
    }

    /** @return array{status: string, reason_code: string, reason: string, confidence: string} */
    private function verdict(string $status, string $code, string $reason, string $confidence): array
    {
        return ['status' => $status, 'reason_code' => $code, 'reason' => $reason, 'confidence' => $confidence];
    }
}
