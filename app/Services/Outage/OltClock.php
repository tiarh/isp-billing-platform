<?php

namespace App\Services\Outage;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * RCA-2: OLT clock drift. The ZTE C300 prints its clock in UTC and has no NTP (OLT RA ran about +45 min ahead on
 * 2026-10-17); a Huawei prints +08:00 stamps but may drift too. Every enrichment session also reads the clock
 * (read-only `show clock` / `display time`) and the history timestamps are corrected by drift = OLT now - app now.
 * Pure parsing + a small cache record for the NOC warning. No device I/O here.
 */
class OltClock
{
    public const CACHE_PREFIX = 'outage:olt-clock:';

    public const WARN_COUNT_PREFIX = 'outage:olt-clock-warn:';

    /** ZTE `show clock`: "06:25:22 Sat Oct 17 2026 UTC". */
    public static function parseZte(?string $text): ?Carbon
    {
        if ($text === null) {
            return null;
        }
        if (preg_match('/(\d{2}:\d{2}:\d{2})\s+[A-Za-z]{3}\s+([A-Za-z]{3})\s+(\d{1,2})\s+(\d{4})/', $text, $m) !== 1) {
            return null;
        }
        try {
            return Carbon::createFromFormat('H:i:s M j Y', $m[1].' '.$m[2].' '.$m[3].' '.$m[4], (string) config('outage.olt_clock_timezone', 'UTC'))->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    /** Huawei `display time`: "2026-10-17 09:40:00+08:00" (no offset is read as +08:00). */
    public static function parseHuawei(?string $text): ?Carbon
    {
        if ($text === null || preg_match('/(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})(?:\s*([+-]\d{2}:\d{2}))?/', $text, $m) !== 1) {
            return null;
        }
        try {
            return Carbon::parse($m[1].' '.$m[2].($m[3] ?? '+08:00'))->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    /** Seconds the OLT clock is ahead of the app clock (negative = behind). */
    public static function drift(?Carbon $oltNow, CarbonInterface $appNow): ?int
    {
        return $oltNow === null ? null : $oltNow->getTimestamp() - $appNow->getTimestamp();
    }

    /** OLT-clock time (UTC-expressed) => real UTC. */
    public static function correct(Carbon $oltTime, ?int $drift): Carbon
    {
        return $oltTime->copy()->subSeconds($drift ?? 0);
    }

    /** Remember the latest drift per OLT and count a warning when it is beyond the threshold. Never throws. */
    public static function note(int $oltId, ?int $drift, CarbonInterface $at): void
    {
        if ($drift === null) {
            return;
        }
        try {
            Cache::put(self::CACHE_PREFIX.$oltId, ['drift_seconds' => $drift, 'at' => $at->copy()->utc()->toIso8601String()], now()->addDay());
            if (abs($drift) > (int) config('outage.olt_clock_drift_warn_seconds', 600)) {
                Cache::add(self::WARN_COUNT_PREFIX.$oltId, 0, now()->addDay());
                Cache::increment(self::WARN_COUNT_PREFIX.$oltId);
                Log::warning('[Outage] OLT clock out of sync', ['olt_id' => $oltId, 'drift_seconds' => $drift]);
            }
        } catch (\Throwable) {
        }
    }

    /**
     * OLTs whose last measured drift is beyond the threshold (last 24 h), for the panel banner.
     *
     * @param  array<int,string>  $oltNames  id => name
     * @return list<array{olt_id:int,olt_name:string,drift_minutes:int}>
     */
    public static function warnings(array $oltNames): array
    {
        $out = [];
        foreach ($oltNames as $id => $name) {
            try {
                $row = Cache::get(self::CACHE_PREFIX.$id);
            } catch (\Throwable) {
                continue;
            }
            if (is_array($row) && abs((int) ($row['drift_seconds'] ?? 0)) > (int) config('outage.olt_clock_drift_warn_seconds', 600)) {
                $out[] = ['olt_id' => (int) $id, 'olt_name' => (string) $name, 'drift_minutes' => (int) round(((int) $row['drift_seconds']) / 60)];
            }
        }

        return $out;
    }
}
