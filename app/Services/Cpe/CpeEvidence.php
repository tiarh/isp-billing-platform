<?php

namespace App\Services\Cpe;

use App\Services\Outage\OutageSettings;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RCA-3: read-only CPE evidence for one customer, consumed by RCA-4 (diagnosis). RCA-3 itself never classifies.
 *
 * DB only, no device I/O. Every value carries its source (sync | watch | summon) and age; a value older than
 * cpe_value_max_age_minutes is reported as null with `fresh` = false (the diagnoser then treats it as unknown).
 *
 * Shape (all keys always present; `device` is null when the customer has no GenieACS device):
 *  device: {device_id, product_class, supported, band_verified, last_inform_at, uptime_s, last_boot_at}
 *  wlan:   {band24|band5: {status, enabled, assoc, source, at, age_min, fresh}}
 *  rssi:   {at, source, count, median, weak_share, histogram{ge_-60, -60_-70, -70_-75, lt_-75}} (latest watch/summon sample)
 *  boots_24h: int (boot + uptime_reset events clustered per 10 minutes), boot_times: list<iso>
 *  events_6h: {wlan_down, wlan_up, assoc_zero}
 *  ppp:    {status, error, at, age_min, fresh}
 *  lan_active: ?int
 *  max_age_minutes: int
 */
class CpeEvidence
{
    public const BOOT_CLUSTER_MINUTES = 10;

    /** @return array<string,mixed> */
    public static function forCustomer(int $customerId, ?CarbonInterface $now = null): array
    {
        $now = $now ? Carbon::instance($now) : Carbon::now();
        $max = OutageSettings::cpeValueMaxAgeMinutes();
        $empty = [
            'device' => null,
            'wlan' => ['band24' => null, 'band5' => null],
            'rssi' => null,
            'boots_24h' => 0,
            'boot_times' => [],
            'events_6h' => ['wlan_down' => 0, 'wlan_up' => 0, 'assoc_zero' => 0],
            'ppp' => null,
            'lan_active' => null,
            'max_age_minutes' => $max,
        ];

        try {
            if (! Schema::hasTable('genieacs_devices') || ! Schema::hasColumn('genieacs_devices', 'wlan_values_at')) {
                return $empty;
            }
            $d = DB::table('genieacs_devices')->where('customer_id', $customerId)->orderByDesc('last_inform_at')->first();
            if ($d === null) {
                return $empty;
            }
            $map = new CpeModelMap;
            $entry = $map->forProductClass($d->product_class);
            $deviceId = (string) $d->device_id;

            $sample = null;
            if (Schema::hasTable('cpe_wifi_samples')) {
                $sample = DB::table('cpe_wifi_samples')->where('device_id', $deviceId)->orderByDesc('at')->first();
            }
            $valuesAt = $d->wlan_values_at !== null ? Carbon::parse($d->wlan_values_at, 'UTC') : null;
            $sampleAt = $sample !== null ? Carbon::parse($sample->at, 'UTC') : null;
            $source = $sampleAt !== null && $valuesAt !== null && $sampleAt->gte($valuesAt) ? (string) $sample->source : 'sync';

            $age = fn (?CarbonInterface $at): ?int => $at === null ? null : max(0, (int) $at->diffInMinutes($now, false));
            $wlan = [];
            foreach (['band24' => '24', 'band5' => '5'] as $band => $suffix) {
                $status = $d->{'wlan'.$suffix.'_status'};
                $enabled = $d->{'wlan'.$suffix.'_enabled'};
                $assoc = $d->{'wlan'.$suffix.'_assoc'};
                if ($status === null && $enabled === null && $assoc === null) {
                    $wlan[$band] = null;

                    continue;
                }
                $a = $age($valuesAt);
                $wlan[$band] = [
                    'status' => $status,
                    'enabled' => $enabled === null ? null : (bool) $enabled,
                    'assoc' => $assoc === null ? null : (int) $assoc,
                    'source' => $source,
                    'at' => $valuesAt?->copy()->utc()->toIso8601String(),
                    'age_min' => $a,
                    'fresh' => $a !== null && $a <= $max,
                ];
            }

            $rssi = null;
            $lan = null;
            if ($sample !== null && $sampleAt !== null && $age($sampleAt) <= $max) {
                $lan = $sample->lan_active !== null ? (int) $sample->lan_active : null;
                $list = is_string($sample->rssi) ? json_decode($sample->rssi, true) : (is_array($sample->rssi) ? $sample->rssi : null);
                if (is_array($list) && $list !== []) {
                    $rssi = self::rssiSummary($list, $sampleAt, (string) $sample->source);
                }
            }

            [$boots, $bootTimes] = self::boots($deviceId, $now);
            $ppp = null;
            if ($d->ppp_status !== null || $d->ppp_last_error !== null) {
                $pa = $d->ppp_values_at !== null ? Carbon::parse($d->ppp_values_at, 'UTC') : null;
                $ppp = [
                    'status' => $d->ppp_status,
                    'error' => $d->ppp_last_error,
                    'at' => $pa?->copy()->utc()->toIso8601String(),
                    'age_min' => $age($pa),
                    'fresh' => $pa !== null && $age($pa) <= $max,
                ];
            }

            return [
                'device' => [
                    'device_id' => $deviceId,
                    'product_class' => $d->product_class,
                    'supported' => (bool) ($entry['supported'] ?? false),
                    'band_verified' => $map->bandVerified($entry),
                    'last_inform_at' => $d->last_inform_at !== null ? Carbon::parse($d->last_inform_at, 'UTC')->toIso8601String() : null,
                    'uptime_s' => $d->uptime_seconds !== null ? (int) $d->uptime_seconds : null,
                    'last_boot_at' => $d->last_boot_at !== null ? Carbon::parse($d->last_boot_at, 'UTC')->toIso8601String() : null,
                ],
                'wlan' => $wlan,
                'rssi' => $rssi,
                'boots_24h' => $boots,
                'boot_times' => $bootTimes,
                'events_6h' => self::eventCounts($deviceId, $now),
                'ppp' => $ppp,
                'lan_active' => $lan,
                'max_age_minutes' => $max,
            ];
        } catch (\Throwable) {
            return $empty;
        }
    }

    /**
     * @param  list<int|float>  $list
     * @return array<string,mixed>
     */
    private static function rssiSummary(array $list, CarbonInterface $at, string $source): array
    {
        $values = array_map('intval', $list);
        sort($values);
        $n = count($values);
        $median = $n % 2 === 1 ? $values[intdiv($n, 2)] : (int) round(($values[$n / 2 - 1] + $values[$n / 2]) / 2);
        $histogram = ['ge_-60' => 0, '-60_-70' => 0, '-70_-75' => 0, 'lt_-75' => 0];
        foreach ($values as $v) {
            if ($v >= -60) {
                $histogram['ge_-60']++;
            } elseif ($v >= -70) {
                $histogram['-60_-70']++;
            } elseif ($v >= -75) {
                $histogram['-70_-75']++;
            } else {
                $histogram['lt_-75']++;
            }
        }

        return [
            'at' => $at->copy()->utc()->toIso8601String(),
            'source' => $source,
            'count' => $n,
            'median' => $median,
            'weak_share' => round($histogram['lt_-75'] / max(1, $n), 2),
            'histogram' => $histogram,
        ];
    }

    /** @return array{0:int, 1:list<string>} boots in the last 24 h, clustered per 10 minutes */
    private static function boots(string $deviceId, CarbonInterface $now): array
    {
        if (! Schema::hasTable('cpe_events')) {
            return [0, []];
        }
        $since = $now->copy()->utc()->subHours(24)->format('Y-m-d H:i:s');
        $times = DB::table('cpe_events')->where('device_id', $deviceId)->whereIn('type', ['boot', 'uptime_reset'])
            ->where('at', '>=', $since)->orderBy('at')->pluck('at')->all();
        $clusters = [];
        $last = null;
        foreach ($times as $t) {
            $at = Carbon::parse($t, 'UTC');
            if ($last === null || $last->diffInMinutes($at) > self::BOOT_CLUSTER_MINUTES) {
                $clusters[] = $at->toIso8601String();
            }
            $last = $at;
        }

        return [count($clusters), $clusters];
    }

    /** @return array{wlan_down:int, wlan_up:int, assoc_zero:int} */
    private static function eventCounts(string $deviceId, CarbonInterface $now): array
    {
        $out = ['wlan_down' => 0, 'wlan_up' => 0, 'assoc_zero' => 0];
        if (! Schema::hasTable('cpe_events')) {
            return $out;
        }
        $since = $now->copy()->utc()->subHours(6)->format('Y-m-d H:i:s');
        $rows = DB::table('cpe_events')->where('device_id', $deviceId)->whereIn('type', array_keys($out))->where('at', '>=', $since)
            ->selectRaw('type, COUNT(*) as n')->groupBy('type')->get();
        foreach ($rows as $r) {
            $out[$r->type] = (int) $r->n;
        }

        return $out;
    }
}
