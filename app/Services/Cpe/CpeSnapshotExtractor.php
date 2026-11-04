<?php

namespace App\Services\Cpe;

use App\Services\Outage\OutageSettings;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * RCA-3: turns one GenieACS NBI device document into the fresh CPE columns of genieacs_devices.
 *
 * Every leaf is read with its GenieACS `_timestamp` (valueWithAge). A value older than
 * cpe_value_max_age_minutes (default 120) is NOT stored for decisions (column = NULL); the raw value and its
 * age only go to parameters.cpe_raw for the UI. Pure function of the document: no I/O, never throws.
 *
 * Privacy: only band, state, counts and RSSI are read. MACs, hostnames and SSIDs are never touched here.
 */
class CpeSnapshotExtractor
{
    public function __construct(private ?CpeModelMap $map = null)
    {
        $this->map ??= new CpeModelMap;
    }

    /** Columns this extractor writes on genieacs_devices (the upsert update list). */
    public const COLUMNS = [
        'last_boot_at',
        'wlan24_status', 'wlan5_status',
        'wlan24_enabled', 'wlan5_enabled',
        'wlan24_assoc', 'wlan5_assoc',
        'wlan_values_at',
        'ppp_status', 'ppp_last_error', 'ppp_values_at',
    ];
    // NOT in COLUMNS on purpose: the PPP session uptime (`_cpe.ppp_uptime_s`) is written by CpeTransitions with its own
    // column guard, so new code running before `migrate` (deploy window) never puts an unknown column into the upsert.

    /**
     * @param  array<string,mixed>  $device
     * @return array{value: mixed, at: ?CarbonInterface}
     */
    public function valueWithAge(array $device, string $path): array
    {
        $node = data_get($device, $path);
        if (! is_array($node) || ! array_key_exists('_value', $node)) {
            return ['value' => null, 'at' => null];
        }
        $at = null;
        if (isset($node['_timestamp']) && is_string($node['_timestamp'])) {
            try {
                $at = Carbon::parse($node['_timestamp']);
            } catch (\Throwable) {
                $at = null;
            }
        } elseif (isset($node['_timestamp']) && is_numeric($node['_timestamp'])) {
            $at = Carbon::createFromTimestampMs((int) $node['_timestamp']);
        }

        return ['value' => $node['_value'], 'at' => $at];
    }

    /**
     * `columns` = genieacs_devices columns (all keys always present, so one bulk upsert has uniform rows);
     * `cpe` = internal data for transitions / rollup / UI (never stored as a column).
     *
     * @param  array<string,mixed>  $device
     * @return array{columns: array<string,mixed>, cpe: array<string,mixed>}
     */
    public function extract(array $device, ?CarbonInterface $now = null): array
    {
        $now = $now ? Carbon::instance($now) : Carbon::now();
        $max = max(1, OutageSettings::cpeValueMaxAgeMinutes());
        $entry = $this->map->forDevice($device);

        $columns = array_fill_keys(self::COLUMNS, null);
        $raw = ['at' => $now->copy()->utc()->format('Y-m-d H:i:s')];
        $freshWlan = [];
        $freshPpp = [];
        $pppUptime = null;

        $lastBoot = $device['_lastBoot'] ?? null;
        if (is_string($lastBoot) && $lastBoot !== '') {
            try {
                $columns['last_boot_at'] = Carbon::parse($lastBoot)->utc()->format('Y-m-d H:i:s');
            } catch (\Throwable) {
                // keep null
            }
        }

        $fresh = function (?CarbonInterface $at) use ($now, $max): bool {
            return $at !== null && $at->lte($now->copy()->addMinutes(5)) && $at->diffInMinutes($now, false) <= $max;
        };
        $ageMin = fn (?CarbonInterface $at): ?int => $at === null ? null : max(0, (int) $at->diffInMinutes($now, false));

        $bandData = [];
        if ($entry['supported'] ?? false) {
            foreach ($this->map->primaries($entry) as $band => $instance) {
                if ($instance === null) {
                    continue;
                }
                $prefix = $band === CpeModelMap::BAND_24 ? 'wlan24' : 'wlan5';
                $read = [];
                foreach (['status' => 'wlan_status', 'enable' => 'wlan_enable', 'radio' => 'wlan_radio', 'assoc' => 'wlan_assoc', 'channel' => 'wlan_channel'] as $name => $field) {
                    $path = $this->map->wlanPath($entry, $instance, $field);
                    $read[$name] = $path === null ? ['value' => null, 'at' => null] : $this->valueWithAge($device, $path);
                }

                $rawBand = [];
                $oldestRaw = null;
                foreach ($read as $name => $r) {
                    if ($r['value'] === null || $r['value'] === '') {
                        continue;
                    }
                    $rawBand[$name] = is_scalar($r['value']) ? $r['value'] : null;
                    $at = $r['at'] ?? Carbon::createFromTimestamp(0); // unknown age counts as very old
                    if ($oldestRaw === null || $at->lt($oldestRaw)) {
                        $oldestRaw = $at;
                    }
                }
                if ($rawBand !== []) {
                    $rawBand['age_min'] = $ageMin($oldestRaw);
                    $raw[$prefix] = $rawBand;
                }

                $freshOf = fn (string $name) => $fresh($read[$name]['at']) && $read[$name]['value'] !== null && $read[$name]['value'] !== ''
                    ? $read[$name]['value'] : null;

                $status = $freshOf('status');
                if (is_scalar($status)) {
                    $columns[$prefix.'_status'] = mb_substr(trim((string) $status), 0, 12);
                    $freshWlan[] = $read['status']['at'];
                }
                $enable = $this->toBool($freshOf('enable'));
                $radio = $this->toBool($freshOf('radio'));
                if ($enable !== null || $radio !== null) {
                    // one flag per band: the SSID is enabled AND its radio is not switched off
                    $columns[$prefix.'_enabled'] = (($enable ?? true) && ($radio ?? true)) ? 1 : 0;
                    foreach (['enable', 'radio'] as $n) {
                        if ($freshOf($n) !== null) {
                            $freshWlan[] = $read[$n]['at'];
                        }
                    }
                }
                $assoc = $freshOf('assoc');
                if ($assoc !== null && is_numeric($assoc)) {
                    $columns[$prefix.'_assoc'] = max(0, (int) $assoc);
                    $freshWlan[] = $read['assoc']['at'];
                }
                $bandData[$band] = ['instance' => $instance];
            }
        }

        if ($freshWlan !== []) {
            $oldest = collect($freshWlan)->sort(fn ($a, $b) => $a <=> $b)->first();
            $columns['wlan_values_at'] = $oldest->copy()->utc()->format('Y-m-d H:i:s');
        }

        if ($entry['supported'] ?? false) {
            $rawPpp = [];
            $oldestRawPpp = null;
            foreach (['status' => 'ppp_status', 'error' => 'ppp_error', 'uptime' => 'ppp_uptime'] as $name => $field) {
                $path = $this->map->pppPath($entry, $field);
                $r = $path === null ? ['value' => null, 'at' => null] : $this->valueWithAge($device, $path);
                if ($r['value'] === null || $r['value'] === '' || ! is_scalar($r['value'])) {
                    continue;
                }
                $rawPpp[$name] = (string) $r['value'];
                $at = $r['at'] ?? Carbon::createFromTimestamp(0);
                if ($oldestRawPpp === null || $at->lt($oldestRawPpp)) {
                    $oldestRawPpp = $at;
                }
                if ($fresh($r['at'])) {
                    if ($name === 'uptime') {
                        // the CPE's own PPP session uptime (seconds) and the time the CPE reported it; anything that is
                        // not a plain number is dropped. ppp_values_at keeps its meaning (status / last error only).
                        if (is_numeric($r['value']) && (float) $r['value'] >= 0 && (float) $r['value'] < 4294967295) {
                            $pppUptime = ['s' => (int) $r['value'], 'at' => $r['at']];
                        }

                        continue;
                    }
                    $col = $name === 'status' ? 'ppp_status' : 'ppp_last_error';
                    $columns[$col] = mb_substr(trim((string) $r['value']), 0, $name === 'status' ? 24 : 48);
                    $freshPpp[] = $r['at'];
                }
            }
            if ($rawPpp !== []) {
                $rawPpp['age_min'] = $ageMin($oldestRawPpp);
                $raw['ppp'] = $rawPpp;
            }
            if ($freshPpp !== []) {
                $oldest = collect($freshPpp)->sort(fn ($a, $b) => $a <=> $b)->first();
                $columns['ppp_values_at'] = $oldest->copy()->utc()->format('Y-m-d H:i:s');
            }
        }

        // Optical power read from its source leaf (the VP timestamp only says when the VP ran, not when the
        // ONU was asked).
        $rx = $this->valueWithAge($device, 'InternetGatewayDevice.WANDevice.1.X_FH_GponInterfaceConfig.RXPower');
        $rxFresh = null;
        if (is_numeric($rx['value'])) {
            $raw['rx'] = ['value' => round((float) $rx['value'], 2), 'age_min' => $ageMin($rx['at'])];
            if ($fresh($rx['at'])) {
                $rxFresh = round((float) $rx['value'], 2);
            }
        }

        return [
            'columns' => $columns,
            'cpe' => [
                'supported' => (bool) ($entry['supported'] ?? false),
                'product_class' => $entry['product_class'] ?? null,
                'raw' => $raw,
                'rx_fresh' => $rxFresh,
                'ppp_uptime_s' => $pppUptime['s'] ?? null,
                'ppp_uptime_at' => $pppUptime['at'] ?? null,
            ],
        ];
    }

    private function toBool(mixed $value): ?bool
    {
        if ($value === null || $value === '' || is_array($value)) {
            return null;
        }
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'enabled', 'up', 'yes'], true);
    }
}
