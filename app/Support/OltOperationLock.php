<?php

namespace App\Support;

use App\Models\Olt;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * Mutex for ONU deletes (+ their live reads/read-backs) on one OLT.
 *
 * The delete lock `olt-onu-delete:{olt}` serialises deletes among themselves
 * only. It is NEVER the PSB or migration key: PSB / migration take their keys
 * with `block(n)` and treat a timeout as a hard failure, so holding one of
 * those for the duration of a delete would make a technician's PSB fail.
 *
 * Instead, before acquiring the delete lock, the PSB key of the OLT
 * (`psb-olt-{id}` / `psb-huawei-olt-{id}`) and the migration key of the target
 * PON port (`device-olt-pon-{olt}-{port}`) are only PROBED, non-blocking
 * (`get()` then immediate release). If any probe fails a PSB/migration run is
 * active on that OLT/port: the delete defers (busy) and writes nothing. The
 * probed keys are never held for the duration of a delete.
 *
 * Callers hold the delete lock around one delete + read-back only.
 */
final class OltOperationLock
{
    public const TTL_SECONDS = 300;

    /** TTL of the non-blocking probes (released immediately). */
    private const PROBE_TTL_SECONDS = 5;

    public static function key(Olt $olt): string
    {
        return 'olt-onu-delete:'.$olt->id;
    }

    public static function psbKey(Olt $olt): string
    {
        $vendor = strtolower(trim((string) ($olt->vendor ?? '')));

        return (str_contains($vendor, 'huawei') || str_contains($vendor, 'ma5608') ? 'psb-huawei-olt-' : 'psb-olt-').$olt->id;
    }

    public static function migrationKey(Olt $olt, string $port): string
    {
        return 'device-olt-pon-'.$olt->id.'-'.$port;
    }

    /**
     * PON port ("1/3/6") of an ONU ref ("1/3/6:2"), or null.
     */
    public static function portOf(?string $onuRef): ?string
    {
        if ($onuRef !== null && preg_match('/(\d+\/\d+\/\d+):\d+/', $onuRef, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * True when a PSB provisioning (OLT) or a migration (that PON port) holds
     * its lock right now. Never holds anything afterwards.
     */
    public static function externalOperationActive(Olt $olt, ?string $onuRef = null): bool
    {
        $keys = [self::psbKey($olt)];
        $port = self::portOf($onuRef);
        if ($port !== null) {
            $keys[] = self::migrationKey($olt, $port);
        }

        foreach ($keys as $key) {
            $probe = Cache::lock($key, self::PROBE_TTL_SECONDS);
            if (! $probe->get()) {
                return true;
            }
            try {
                $probe->release();
            } catch (\Throwable) {
            }
        }

        return false;
    }

    /**
     * Run $fn while holding the delete lock, unless a PSB/migration run is
     * active (busy). $waitSeconds applies to the delete lock only; 0 never
     * blocks (queue workers).
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @return array{busy: bool, value: T|null}
     */
    public static function run(Olt $olt, int $waitSeconds, callable $fn, ?string $onuRef = null): array
    {
        if (self::externalOperationActive($olt, $onuRef)) {
            return ['busy' => true, 'value' => null];
        }

        $lock = Cache::lock(self::key($olt), self::TTL_SECONDS);

        try {
            $lock->block(max(0, $waitSeconds));
        } catch (LockTimeoutException) {
            return ['busy' => true, 'value' => null];
        }

        try {
            // Re-probe now that we own the delete lock (a PSB run may have
            // started while we waited).
            if (self::externalOperationActive($olt, $onuRef)) {
                return ['busy' => true, 'value' => null];
            }

            return ['busy' => false, 'value' => $fn()];
        } finally {
            try {
                $lock->release();
            } catch (\Throwable) {
            }
        }
    }
}
