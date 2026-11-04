<?php

namespace App\Services\Cpe;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RCA-3: cpe_daily, one row per device per WIB day, upserted by every genieacs sync (and inform ingest).
 * Counters are merged read-modify-write in PHP (portable, one indexed read per 200 devices), then one upsert.
 * Never throws into the GenieACS paths.
 */
class CpeDailyRollup
{
    public const COLUMNS = [
        'customer_id', 'samples', 'fresh_wlan_samples', 'wlan24_down_samples', 'wlan5_down_samples',
        'assoc24_max', 'assoc5_max', 'assoc_zero_samples', 'boots', 'min_uptime_s', 'ppp_error_samples',
        'rx_min', 'rx_max', 'updated_at',
    ];

    /**
     * @param  list<array<string,mixed>>  $stats  per device: device_id, customer_id, fresh_wlan, down24, down5, assoc24,
     *                                            assoc5, assoc_zero, boot, uptime, ppp_error, rx
     */
    public static function apply(array $stats, ?CarbonInterface $now = null): int
    {
        if ($stats === []) {
            return 0;
        }
        try {
            if (! Schema::hasTable('cpe_daily')) {
                return 0;
            }
            $now = $now ? Carbon::instance($now) : Carbon::now();
            $day = $now->copy()->timezone('Asia/Jakarta')->toDateString();
            $stamp = $now->copy()->utc()->format('Y-m-d H:i:s');
            $written = 0;

            foreach (array_chunk($stats, 200) as $chunk) {
                $ids = array_values(array_unique(array_map(fn ($s) => (string) $s['device_id'], $chunk)));
                $existing = DB::table('cpe_daily')->where('day_wib', $day)->whereIn('device_id', $ids)->get()->keyBy('device_id');
                $rows = [];
                foreach ($chunk as $s) {
                    $id = (string) $s['device_id'];
                    $old = $existing[$id] ?? null;
                    $row = [
                        'device_id' => mb_substr($id, 0, 191),
                        'day_wib' => $day,
                        'customer_id' => $s['customer_id'] ?? ($old->customer_id ?? null),
                        'samples' => min(32000, (int) ($old->samples ?? 0) + 1),
                        'fresh_wlan_samples' => min(32000, (int) ($old->fresh_wlan_samples ?? 0) + (! empty($s['fresh_wlan']) ? 1 : 0)),
                        'wlan24_down_samples' => min(32000, (int) ($old->wlan24_down_samples ?? 0) + (! empty($s['down24']) ? 1 : 0)),
                        'wlan5_down_samples' => min(32000, (int) ($old->wlan5_down_samples ?? 0) + (! empty($s['down5']) ? 1 : 0)),
                        'assoc24_max' => self::maxOf($old->assoc24_max ?? null, $s['assoc24'] ?? null),
                        'assoc5_max' => self::maxOf($old->assoc5_max ?? null, $s['assoc5'] ?? null),
                        'assoc_zero_samples' => min(32000, (int) ($old->assoc_zero_samples ?? 0) + (! empty($s['assoc_zero']) ? 1 : 0)),
                        'boots' => min(255, (int) ($old->boots ?? 0) + (! empty($s['boot']) ? 1 : 0)),
                        'min_uptime_s' => self::minOf($old->min_uptime_s ?? null, $s['uptime'] ?? null),
                        'ppp_error_samples' => min(32000, (int) ($old->ppp_error_samples ?? 0) + (! empty($s['ppp_error']) ? 1 : 0)),
                        'rx_min' => self::minOf($old->rx_min ?? null, $s['rx'] ?? null),
                        'rx_max' => self::maxOf($old->rx_max ?? null, $s['rx'] ?? null),
                        'updated_at' => $stamp,
                    ];
                    $rows[$id] = $row; // one row per device per call
                }
                DB::table('cpe_daily')->upsert(array_values($rows), ['device_id', 'day_wib'], self::COLUMNS);
                $written += count($rows);
            }

            return $written;
        } catch (\Throwable) {
            return 0;
        }
    }

    private static function maxOf(mixed $a, mixed $b): int|float|null
    {
        $a = is_numeric($a) ? $a + 0 : null;
        $b = is_numeric($b) ? $b + 0 : null;

        return $a === null ? $b : ($b === null ? $a : max($a, $b));
    }

    private static function minOf(mixed $a, mixed $b): int|float|null
    {
        $a = is_numeric($a) ? $a + 0 : null;
        $b = is_numeric($b) ? $b + 0 : null;

        return $a === null ? $b : ($b === null ? $a : min($a, $b));
    }
}
