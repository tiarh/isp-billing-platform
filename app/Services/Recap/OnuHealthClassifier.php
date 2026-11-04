<?php

namespace App\Services\Recap;

use App\Models\OltOnu;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Pure ONU health classifier (no DB, no I/O). Works on one olt_onus snapshot row.
 *
 * - Data older than STATE_MAX_AGE_MINUTES is DATA_BASI: never counted as offline.
 * - An explicit online state wins over a stale LOS phase (phase is stamped by phase_collected_at (#513) or the inventory tick).
 * - LOS / MATI_LISTRIK is only claimed for ZTE / HiOSO style OLTs while the phase reading itself is fresh.
 *   Huawei only knows "offline" (cause unknown) -> LAIN.
 * - RX power is never used to decide online/offline here.
 */
final class OnuHealthClassifier
{
    public const STATE_MAX_AGE_MINUTES = 90;

    public const RX_MAX_AGE_HOURS = 6;

    public const ONLINE = 'online';

    public const LOS = 'los';

    public const MATI_LISTRIK = 'mati_listrik';

    public const LAIN = 'lain';

    public const DATA_BASI = 'data_basi';

    public const TIDAK_DIKETAHUI = 'tidak_diketahui';

    public const OFFLINE_KINDS = [self::LOS, self::MATI_LISTRIK, self::LAIN];

    /**
     * @param  array{vendor?: ?string, state?: ?string, phase_state?: ?string, state_collected_at?: mixed, inventory_collected_at?: mixed}  $onu
     */
    public static function classify(array $onu, CarbonInterface $now): string
    {
        $stateAt = self::at($onu['state_collected_at'] ?? null)
            ?? self::newest($onu['phase_collected_at'] ?? null, $onu['inventory_collected_at'] ?? null);
        if ($stateAt === null || $stateAt->lt($now->copy()->subMinutes(self::STATE_MAX_AGE_MINUTES))) {
            return self::DATA_BASI;
        }

        $state = strtolower(trim((string) ($onu['state'] ?? '')));
        $phaseRaw = strtolower((string) preg_replace('/[\s_\-]+/', '', (string) ($onu['phase_state'] ?? '')));
        if ($state === '' && $phaseRaw === '') {
            return self::TIDAK_DIKETAHUI;
        }

        // No RX argument on purpose: an optical reading is not evidence of "online".
        $operational = OltOnu::normalizeOperationalState($state, (string) ($onu['phase_state'] ?? ''), null);
        if ($operational === 'online') {
            return self::ONLINE;
        }
        if ($operational !== 'offline') {
            return self::TIDAK_DIKETAHUI;
        }

        if (str_contains(strtolower((string) ($onu['vendor'] ?? '')), 'huawei')) {
            return self::LAIN;
        }

        // Phase freshness = newer of the 5-min tick stamp (#513) and the inventory stamp.
        $phaseAt = self::newest($onu['phase_collected_at'] ?? null, $onu['inventory_collected_at'] ?? null);
        if ($phaseAt === null || $phaseAt->lt($now->copy()->subMinutes(self::STATE_MAX_AGE_MINUTES))) {
            return self::LAIN;
        }
        if ($phaseRaw === 'los' || $state === 'los') {
            return self::LOS;
        }
        if (in_array($phaseRaw, ['dyinggasp', 'dgi'], true) || $state === 'dyinggasp') {
            return self::MATI_LISTRIK;
        }

        return self::LAIN;
    }

    /** Weak RX: an ONLINE ONU, valid reading, strictly below the threshold, reading collected within 6 h. */
    public static function isWeakRx(string $kind, ?float $rx, mixed $rxCollectedAt, float $thresholdDbm, CarbonInterface $now): bool
    {
        if ($kind !== self::ONLINE || ! OltOnu::isValidLiveRxPower($rx)) {
            return false;
        }
        $at = self::at($rxCollectedAt);
        if ($at === null || $at->lt($now->copy()->subHours(self::RX_MAX_AGE_HOURS))) {
            return false;
        }

        return $rx < $thresholdDbm;
    }

    public static function isOffline(string $kind): bool
    {
        return in_array($kind, self::OFFLINE_KINDS, true);
    }

    private static function newest(mixed $a, mixed $b): ?CarbonInterface
    {
        $a = self::at($a);
        $b = self::at($b);
        if ($a === null || $b === null) {
            return $a ?? $b;
        }

        return $b->gt($a) ? $b : $a;
    }

    private static function at(mixed $value): ?CarbonInterface
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof CarbonInterface) {
            return $value;
        }
        try {
            return Carbon::parse((string) $value, 'UTC');
        } catch (\Throwable) {
            return null;
        }
    }
}
