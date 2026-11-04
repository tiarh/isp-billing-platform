<?php

namespace App\Services\Outage\Diagnosis;

use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RCA-4: DB-only read of what GenieACS / the RCA-3 WiFi layer knows about a customer's CPE. Every RCA-3 table and
 * column is guarded with hasTable / hasColumn, so RCA-4 works (WiFi rules simply return "unknown") whichever of the
 * two PRs reaches production first. No device I/O, never throws.
 *
 * Column / table / type names are exactly the ones of plans/rca3-rca4-plan.md section 2:
 *  genieacs_devices: last_boot_at, wlan24_status, wlan5_status, wlan24_enabled, wlan5_enabled, wlan24_assoc,
 *  wlan5_assoc, wlan_values_at, ppp_status, ppp_last_error, ppp_values_at, cpe_summoned_at;
 *  cpe_wifi_samples (rssi, lan_active, source watch|summon); cpe_events types boot, uptime_reset, wlan_down, wlan_up,
 *  assoc_zero, ppp_error.
 */
class CpeSnapshotReader
{
    /** Device data younger than this is "fresh" for a high-confidence answer. */
    public const FRESH_MINUTES = 15;

    public const BOOT_CLUSTER_SECONDS = 600;

    /**
     * @return array<string,mixed>
     */
    public function forCustomer(int $customerId, Carbon $now): array
    {
        $empty = [
            'present' => false, 'device_id' => null, 'model' => null, 'last_inform_at' => null, 'uptime_s' => null, 'uptime_now_s' => null,
            'boots_24h' => 0, 'boot_times' => [], 'last_boot_at' => null,
            'wlan' => ['24' => ['status' => null, 'enabled' => null, 'assoc' => null], '5' => ['status' => null, 'enabled' => null, 'assoc' => null]],
            'wlan_at' => null, 'wlan_fresh' => false, 'wlan_strong' => false, 'wlan_source' => null, 'band_verified' => false,
            'ppp_status' => null, 'ppp_last_error' => null, 'ppp_at' => null, 'ppp_fresh' => false,
            'rssi' => [], 'lan_active' => null, 'wlan_transitions_6h' => 0, 'wlan_transitions_24h' => 0, 'ppp_reconnects' => [], 'summoned_at' => null,
        ];
        try {
            if (! Schema::hasTable('genieacs_devices')) {
                return $empty;
            }
            $device = DB::table('genieacs_devices')->where('customer_id', $customerId)->orderByDesc('last_inform_at')->first();
            if ($device === null) {
                return $empty;
            }
            $out = $empty;
            $out['present'] = true;
            $out['device_id'] = (string) $device->device_id;
            $out['model'] = $device->product_class ?? ($device->model ?? null);
            $out['last_inform_at'] = $this->time($device->last_inform_at ?? null);
            $out['uptime_s'] = isset($device->uptime_seconds) && is_numeric($device->uptime_seconds) ? (int) $device->uptime_seconds : null;
            $out['last_boot_at'] = $this->time($device->last_boot_at ?? null);
            // Uptime AS OF NOW. The stored uptime is refreshed hourly, so it is only used through the boot time
            // (_lastBoot is fresh on every inform); without a boot time the uptime is unknown (null), never guessed.
            $out['uptime_now_s'] = $out['last_boot_at'] !== null && $out['last_boot_at']->lte($now) ? (int) $out['last_boot_at']->diffInSeconds($now) : null;
            $maxAge = $this->maxAgeMinutes();

            $hasWlan = property_exists($device, 'wlan24_status') || property_exists($device, 'wlan_values_at');
            if ($hasWlan) {
                $wlanAt = $this->time($device->wlan_values_at ?? null);
                foreach (['24' => 'wlan24', '5' => 'wlan5'] as $band => $prefix) {
                    $out['wlan'][$band] = [
                        'status' => $device->{$prefix.'_status'} ?? null,
                        'enabled' => isset($device->{$prefix.'_enabled'}) ? (bool) $device->{$prefix.'_enabled'} : null,
                        'assoc' => isset($device->{$prefix.'_assoc'}) ? (int) $device->{$prefix.'_assoc'} : null,
                    ];
                }
                $out['wlan_at'] = $wlanAt;
                $out['wlan_source'] = $wlanAt !== null ? 'sync' : null;
                $out['ppp_status'] = $device->ppp_status ?? null;
                $out['ppp_last_error'] = $device->ppp_last_error ?? null;
                $out['ppp_at'] = $this->time($device->ppp_values_at ?? null);
                $out['summoned_at'] = $this->time($device->cpe_summoned_at ?? null);
            }

            // Newest watch / summon sample (5 minute data for symptomatic devices): fresher than the hourly sync.
            if (Schema::hasTable('cpe_wifi_samples')) {
                $sample = DB::table('cpe_wifi_samples')->where('customer_id', $customerId)
                    ->where('at', '>=', $now->copy()->subHours(6)->utc()->format('Y-m-d H:i:s'))->orderByDesc('at')->first();
                if ($sample !== null) {
                    $sampleAt = $this->time($sample->at);
                    if ($sampleAt !== null && ($out['wlan_at'] === null || $sampleAt->gt($out['wlan_at']))) {
                        $out['wlan']['24']['status'] = $sample->wlan24_status;
                        $out['wlan']['5']['status'] = $sample->wlan5_status;
                        $out['wlan']['24']['assoc'] = $sample->assoc24 !== null ? (int) $sample->assoc24 : null;
                        $out['wlan']['5']['assoc'] = $sample->assoc5 !== null ? (int) $sample->assoc5 : null;
                        // a sample carries no Enable flag: the older synced one must not be mixed with newer values
                        $out['wlan']['24']['enabled'] = null;
                        $out['wlan']['5']['enabled'] = null;
                        $out['wlan_at'] = $sampleAt;
                        $out['wlan_source'] = (string) $sample->source;
                        if ($sample->ppp_status !== null) {
                            $out['ppp_status'] = $sample->ppp_status;
                            $out['ppp_at'] = $sampleAt;
                        }
                    }
                    $rssi = json_decode((string) ($sample->rssi ?? '[]'), true);
                    $out['rssi'] = is_array($rssi) ? array_values(array_filter($rssi, 'is_numeric')) : [];
                    $out['lan_active'] = $sample->lan_active !== null ? (int) $sample->lan_active : null;
                }
            }

            if ($out['wlan_at'] !== null) {
                $age = $out['wlan_at']->diffInMinutes($now, false);
                $out['wlan_fresh'] = $age >= 0 && $age <= $maxAge && $this->hasWlanValue($out);
                $strongSource = in_array($out['wlan_source'], ['watch', 'summon'], true)
                    || ($out['summoned_at'] !== null && $out['wlan_at']->gte($out['summoned_at']->copy()->subMinutes(2)));
                $out['wlan_strong'] = $out['wlan_fresh'] && $age <= self::FRESH_MINUTES && $strongSource;
                if ($strongSource && $out['wlan_source'] === 'sync') {
                    $out['wlan_source'] = 'summon';
                }
            }
            if ($out['ppp_at'] !== null) {
                $age = $out['ppp_at']->diffInMinutes($now, false);
                $out['ppp_fresh'] = $age >= 0 && $age <= $maxAge && $out['ppp_status'] !== null;
            }
            $out['band_verified'] = (bool) config('cpe_models.models.'.strtoupper((string) $out['model']).'.band_verified', false);

            $this->events($out, $customerId, $now);

            return $out;
        } catch (\Throwable) {
            return $empty;
        }
    }

    /** @param array<string,mixed> $out */
    private function hasWlanValue(array $out): bool
    {
        foreach (['24', '5'] as $band) {
            if ($out['wlan'][$band]['status'] !== null || $out['wlan'][$band]['enabled'] !== null || $out['wlan'][$band]['assoc'] !== null) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed> $out */
    private function events(array &$out, int $customerId, Carbon $now): void
    {
        if (! Schema::hasTable('cpe_events')) {
            return;
        }
        $since = $now->copy()->subDay()->utc()->format('Y-m-d H:i:s');
        $rows = DB::table('cpe_events')->where('customer_id', $customerId)->where('at', '>=', $since)
            ->whereIn('type', ['boot', 'uptime_reset', 'wlan_down', 'wlan_up', 'assoc_zero', 'ppp_reconnect'])->orderBy('at')->limit(200)->get(['type', 'at']);
        $boots = [];
        $transitions = 0;
        $wlanTimes = [];
        $pppReconnects = [];
        $sixHours = $now->copy()->subHours(6);
        foreach ($rows as $row) {
            $at = $this->time($row->at);
            if ($at === null) {
                continue;
            }
            if (in_array($row->type, ['boot', 'uptime_reset'], true)) {
                $boots[] = $at;
            } elseif ($row->type === 'ppp_reconnect') {
                $pppReconnects[] = $at;
            } else {
                // 24 h rule: only a radio that really went down (assoc_zero = phones left / slept, wlan_up = the way back)
                if ($row->type === 'wlan_down') {
                    $wlanTimes[] = $at;
                }
                if ($at->gte($sixHours)) {
                    $transitions++;
                }
            }
        }
        // 24 h WiFi radio drops (wlan_down) that no restart explains (right after a restart the radios go down and up by themselves)
        $unexplained = 0;
        foreach ($wlanTimes as $at) {
            $afterBoot = false;
            foreach ($boots as $b) {
                $delta = $at->getTimestamp() - $b->getTimestamp();
                if ($delta >= -120 && $delta <= self::BOOT_CLUSTER_SECONDS) {
                    $afterBoot = true;
                    break;
                }
            }
            if (! $afterBoot) {
                $unexplained++;
            }
        }
        $out['wlan_transitions_24h'] = $unexplained;
        $out['ppp_reconnects'] = $pppReconnects;
        // one restart can leave a boot AND an uptime_reset row: cluster per 10 minutes
        $clustered = [];
        foreach ($boots as $at) {
            $last = end($clustered);
            if ($last === false || $last->diffInSeconds($at, true) > self::BOOT_CLUSTER_SECONDS) {
                $clustered[] = $at;
            }
        }
        $out['boot_times'] = $clustered;
        $out['boots_24h'] = count($clustered);
        $out['wlan_transitions_6h'] = $transitions;
    }

    private function maxAgeMinutes(): int
    {
        try {
            $raw = Setting::get('cpe_value_max_age_minutes');

            return is_numeric($raw) ? max(15, (int) $raw) : 120;
        } catch (\Throwable) {
            return 120;
        }
    }

    private function time(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return Carbon::parse((string) $value, 'UTC')->utc();
        } catch (\Throwable) {
            return null;
        }
    }
}
