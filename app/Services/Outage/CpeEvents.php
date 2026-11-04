<?php

namespace App\Services\Outage;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * RCA-2: cpe_events writer (GenieACS BOOT / BOOTSTRAP inform and uptime resets seen by genieacs:sync).
 * Transitions only; never throws into the GenieACS paths and never talks to a device.
 */
class CpeEvents
{
    /** Uptime counts as reset when it dropped by more than this many seconds (a normal inform jitter is smaller). */
    public const RESET_MIN_DROP_SECONDS = 60;

    /** The same device + type inside this window is one event (BOOTSTRAP + BOOT arrive together). */
    public const DEDUPE_SECONDS = 120;

    /**
     * Event types in cpe_events (column is a plain string). RCA-2: boot, uptime_reset (wan_down / wan_up reserved).
     * RCA-3 (written by App\Services\Cpe\CpeTransitions, fresh values only): wlan_down, wlan_up (meta.bands),
     * assoc_zero, ppp_error. RCA-4 history: ppp_reconnect (the CPE's PPP session uptime dropped while the ONU kept
     * running; at most one per device per hourly refresh, so a floor, not an exact count).
     */
    public const TYPES = ['boot', 'uptime_reset', 'wan_down', 'wan_up', 'wlan_down', 'wlan_up', 'assoc_zero', 'ppp_error', 'ppp_reconnect'];

    private static ?bool $tableReady = null;

    private static function ready(): bool
    {
        if (self::$tableReady === null) {
            try {
                self::$tableReady = Schema::hasTable('cpe_events');
            } catch (\Throwable) {
                return false;
            }
        }

        return self::$tableReady;
    }

    /** Test hook. */
    public static function forgetSchemaCache(): void
    {
        self::$tableReady = null;
    }

    public static function isBootEvent(?string $event): bool
    {
        return $event !== null && preg_match('/\bboot(strap)?\b/i', $event) === 1;
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    public static function record(?int $customerId, string $deviceId, string $type, ?CarbonInterface $at = null, array $meta = []): bool
    {
        if ($deviceId === '' || ! self::ready()) {
            return false;
        }
        try {
            $at = $at ? Carbon::instance($at) : now();
            $utc = $at->copy()->utc();
            $dup = DB::table('cpe_events')->where('device_id', $deviceId)->where('type', $type)
                ->where('at', '>=', $utc->copy()->subSeconds(self::DEDUPE_SECONDS)->format('Y-m-d H:i:s'))->exists();
            if ($dup) {
                return false;
            }
            DB::table('cpe_events')->insert([
                'customer_id' => $customerId,
                'device_id' => mb_substr($deviceId, 0, 191),
                'type' => $type,
                'at' => $utc->format('Y-m-d H:i:s'),
                'meta' => $meta === [] ? null : json_encode($meta),
            ]);

            return true;
        } catch (\Throwable $e) {
            self::warn($e);

            return false;
        }
    }

    /**
     * Compare the uptime about to be written with the stored one: a clear decrease is a restart the CPE did
     * not announce as BOOT (or that we missed). Call BEFORE the upsert overwrites the old value.
     *
     * @param  list<array<string,mixed>>  $rows  genieacs_devices rows (device_id, customer_id, uptime_seconds)
     * @return int events written
     */
    public static function recordUptimeResets(array $rows): int
    {
        if ($rows === [] || ! self::ready() || ! Schema::hasTable('genieacs_devices')) {
            return 0;
        }
        try {
            $incoming = [];
            foreach ($rows as $row) {
                $id = (string) ($row['device_id'] ?? '');
                $up = $row['uptime_seconds'] ?? null;
                if ($id !== '' && is_numeric($up) && (int) $up >= 0) {
                    $incoming[$id] = ['up' => (int) $up, 'customer_id' => isset($row['customer_id']) ? (int) $row['customer_id'] : null];
                }
            }
            $written = 0;
            foreach (array_chunk(array_keys($incoming), 400) as $chunk) {
                $old = DB::table('genieacs_devices')->whereIn('device_id', $chunk)->whereNotNull('uptime_seconds')
                    ->get(['device_id', 'customer_id', 'uptime_seconds']);
                foreach ($old as $o) {
                    $new = $incoming[$o->device_id]['up'] ?? null;
                    if ($new !== null && (int) $o->uptime_seconds - $new > self::RESET_MIN_DROP_SECONDS) {
                        $cid = $incoming[$o->device_id]['customer_id'] ?? ($o->customer_id !== null ? (int) $o->customer_id : null);
                        if (self::record($cid, (string) $o->device_id, 'uptime_reset', null, ['old_uptime' => (int) $o->uptime_seconds, 'new_uptime' => $new])) {
                            $written++;
                        }
                    }
                }
            }

            return $written;
        } catch (\Throwable $e) {
            self::warn($e);

            return 0;
        }
    }

    private static function warn(\Throwable $e): void
    {
        try {
            Log::warning('[Outage] cpe_events write failed', ['exception_class' => $e::class]);
        } catch (\Throwable) {
        }
    }
}
