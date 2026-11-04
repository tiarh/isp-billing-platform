<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class OltOnu extends Model
{
    protected $fillable = [
        'olt_id',
        'onu_ref',
        'snmp_index',
        'pon_port',
        'serial_number',
        'onu_name',
        'state',
        'phase_state',
        'rx_power_dbm',
        'distance_m',
        'rx_source',
        'serial_source',
        'name_source',
        'state_collected_at',
        'rx_collected_at',
        'inventory_collected_at',
        'phase_collected_at',
    ];

    protected $casts = [
        'rx_power_dbm' => 'float',
        'distance_m' => 'integer',
        'state_collected_at' => 'datetime',
        'rx_collected_at' => 'datetime',
        'inventory_collected_at' => 'datetime',
        'phase_collected_at' => 'datetime',
    ];

    public function olt(): BelongsTo
    {
        return $this->belongsTo(Olt::class);
    }

    public function isFhtt(): bool
    {
        return str_starts_with(strtoupper((string) $this->serial_number), 'FHTT');
    }

    public static function normalizeOperationalState(?string $state, ?string $phaseState = null, ?float $rxPowerDbm = null): string
    {
        $normalized = strtolower(trim((string) $state));
        if (in_array($normalized, ['online', 'working', 'up', 'active', 'enable', 'enabled'], true)) {
            return 'online';
        }

        $phase = strtoupper(trim((string) $phaseState));
        if ($phase === 'O5') {
            return 'online';
        }

        // An explicit offline verdict from the OLT wins over an optical reading:
        // RX values are snapshots that are not cleared reliably when an ONU dies
        // (audit 2026-10-08, Huawei Gadang), so "any RX = online" faked onliners.
        if (in_array($normalized, ['offline', 'down', 'los', 'dyinggasp'], true)) {
            return 'offline';
        }

        if (in_array($phase, ['O7', 'LOS', 'DYINGGASP', 'DGI', 'SYNCMIB', 'O1', 'O2', 'O3', 'O4', 'O6'], true)) {
            return 'offline';
        }

        // Without an explicit state, live optical RX is evidence the ONU is up
        // (CLI state parse often lags / fails). Callers reading a stored
        // snapshot must pass RX only when it is fresh (see isFreshLiveRx()).
        if (self::isValidLiveRxPower($rxPowerDbm)) {
            return 'online';
        }

        return $normalized !== '' ? $normalized : 'offline';
    }

    /**
     * Valid RX reading that was collected within $maxAgeMinutes. A reading
     * without a collection timestamp is never fresh.
     */
    public static function isFreshLiveRx(?float $rxPowerDbm, mixed $collectedAt, ?int $maxAgeMinutes = null): bool
    {
        if (! self::isValidLiveRxPower($rxPowerDbm) || ! $collectedAt) {
            return false;
        }

        $maxAgeMinutes ??= (int) config('services.online_freshness.rx_max_age_minutes', 20);
        $at = $collectedAt instanceof \DateTimeInterface
            ? Carbon::instance($collectedAt)
            : Carbon::parse((string) $collectedAt);

        return $at->gt(now()->subMinutes($maxAgeMinutes));
    }

    /**
     * Typical ONU RX: about -8..-30 dBm. Treat -40..0 as "has live light" (online).
     * Excludes SNMP residual sentinels and empty readings.
     */
    public static function isValidLiveRxPower(?float $rxPowerDbm): bool
    {
        if ($rxPowerDbm === null || ! is_finite($rxPowerDbm)) {
            return false;
        }

        // Reject nonsense / residual placeholders
        if (abs($rxPowerDbm) > 100) {
            return false;
        }

        return $rxPowerDbm >= -40.0 && $rxPowerDbm <= 0.0;
    }
}
