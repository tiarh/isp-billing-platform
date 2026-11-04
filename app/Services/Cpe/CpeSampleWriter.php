<?php

namespace App\Services\Cpe;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RCA-3: applies one NBI document read back by the watch / summon services: refreshes the fresh CPE columns of
 * the device row, writes the transitions (events only, no cpe_daily counters) and one cpe_wifi_samples row.
 *
 * Privacy: only band, state, counts, RSSI (anonymous ints) and the number of active wired hosts are kept.
 * MAC addresses, hostnames and SSIDs are read from nothing and written nowhere.
 */
class CpeSampleWriter
{
    public const MAX_RSSI = 32;

    public function __construct(
        private ?CpeSnapshotExtractor $extractor = null,
        private ?CpeModelMap $map = null,
    ) {
        $this->map ??= new CpeModelMap;
        $this->extractor ??= new CpeSnapshotExtractor($this->map);
    }

    /**
     * Projection for the read-back of watched / summoned devices: identity, boot time, uptime and the fixed leaves
     * of every known model. $withClients adds the AssociatedDevice and Hosts subtrees (summon only).
     *
     * @return list<string>
     */
    public function projection(bool $withClients = false): array
    {
        $paths = ['_id', '_lastBoot', '_lastInform', '_deviceId._ProductClass', 'InternetGatewayDevice.DeviceInfo.UpTime'];
        $paths = array_merge($paths, $this->map->projectionLeaves());
        if ($withClients) {
            foreach ((array) config('cpe_models.models', []) as $entry) {
                if (! ($entry['assoc_refresh'] ?? false)) {
                    continue;
                }
                foreach ($this->map->primaries($entry) as $instance) {
                    if ($instance !== null) {
                        $paths[] = $this->map->abs($entry, str_replace('{i}', (string) $instance, $entry['paths']['wlan']).'.'.$entry['paths']['assoc_device']);
                    }
                }
                $paths[] = $this->map->abs($entry, $entry['paths']['hosts']);
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * Returns null when the device is unknown to eBilling (nothing is stored for unknown devices).
     *
     * @param  array<string,mixed>  $doc  NBI document
     * @return array{customer_id: ?int, columns: array<string,mixed>, rssi: list<int>, lan_active: ?int, at: ?string, sample: bool}|null
     */
    public function apply(array $doc, string $source, bool $withClients = false, ?CarbonInterface $now = null): ?array
    {
        $deviceId = (string) ($doc['_id'] ?? '');
        if ($deviceId === '' || ! Schema::hasTable('genieacs_devices') || ! Schema::hasColumn('genieacs_devices', 'wlan_values_at')) {
            return null;
        }
        $now = $now ? Carbon::instance($now) : Carbon::now();
        $old = DB::table('genieacs_devices')->where('device_id', $deviceId)->first(['device_id', 'customer_id', 'uptime_seconds', 'wlan_values_at']);
        if ($old === null) {
            return null;
        }

        $extracted = $this->extractor->extract($doc, $now);
        $columns = $extracted['columns'];
        $uptime = $this->uptimeOf($doc);
        $customerId = $old->customer_id !== null ? (int) $old->customer_id : null;

        CpeTransitions::process([array_merge($columns, [
            'device_id' => $deviceId,
            'customer_id' => $customerId,
            'uptime_seconds' => $uptime ?? $old->uptime_seconds,
        ])], $now, false);

        $oldAt = $old->wlan_values_at !== null ? Carbon::parse($old->wlan_values_at, 'UTC') : null;
        $newAt = $columns['wlan_values_at'] !== null ? Carbon::parse($columns['wlan_values_at'], 'UTC') : null;
        $regress = $oldAt !== null && ($newAt === null || $newAt->lt($oldAt));
        if (! $regress) {
            DB::table('genieacs_devices')->where('device_id', $deviceId)->update($columns);
        }

        $rssi = [];
        $lanActive = null;
        if ($withClients) {
            [$rssi, $lanActive] = $this->clients($doc);
        }

        // "new data" is judged against the device's last SAMPLE, not its row: the 10-minute sync may have stored the
        // value first, and that answer must still become a watch sample (source = watch).
        $lastSample = Schema::hasTable('cpe_wifi_samples')
            ? DB::table('cpe_wifi_samples')->where('device_id', $deviceId)->max('at')
            : null;
        $lastSampleAt = $lastSample !== null ? Carbon::parse($lastSample, 'UTC') : null;
        $isNew = $newAt !== null && ($lastSampleAt === null || $newAt->gt($lastSampleAt) || $source === 'summon');
        $sampled = false;
        if ($isNew && Schema::hasTable('cpe_wifi_samples')) {
            DB::table('cpe_wifi_samples')->insert([
                'device_id' => mb_substr($deviceId, 0, 191),
                'customer_id' => $customerId,
                'at' => $newAt->copy()->utc()->format('Y-m-d H:i:s'),
                'source' => $source,
                'wlan24_status' => $columns['wlan24_status'],
                'wlan5_status' => $columns['wlan5_status'],
                'assoc24' => $columns['wlan24_assoc'],
                'assoc5' => $columns['wlan5_assoc'],
                'uptime_s' => $uptime,
                'ppp_status' => $columns['ppp_status'],
                'rssi' => $rssi === [] ? null : json_encode($rssi),
                'lan_active' => $lanActive,
            ]);
            $sampled = true;
        }

        return [
            'customer_id' => $customerId,
            'columns' => $columns,
            'rssi' => $rssi,
            'lan_active' => $lanActive,
            'at' => $columns['wlan_values_at'],
            'sample' => $sampled,
        ];
    }

    private function uptimeOf(array $doc): ?int
    {
        $node = data_get($doc, 'InternetGatewayDevice.DeviceInfo.UpTime');
        $value = is_array($node) ? ($node['_value'] ?? null) : null;

        return is_numeric($value) && (int) $value >= 0 ? (int) $value : null;
    }

    /**
     * Anonymous client data: RSSI ints of the primary instances' AssociatedDevice list (max 32) and the number of
     * active wired hosts. Nothing identifying is returned.
     *
     * @param  array<string,mixed>  $doc
     * @return array{0: list<int>, 1: ?int}
     */
    public function clients(array $doc): array
    {
        $entry = $this->map->forDevice($doc);
        $rssi = [];
        if (($entry['supported'] ?? false) && ($entry['assoc_refresh'] ?? false)) {
            foreach ($this->map->primaries($entry) as $instance) {
                if ($instance === null) {
                    continue;
                }
                $base = $this->map->abs($entry, str_replace('{i}', (string) $instance, $entry['paths']['wlan']).'.'.$entry['paths']['assoc_device']);
                $list = data_get($doc, $base);
                foreach (is_array($list) ? $list : [] as $key => $item) {
                    if (! is_array($item) || str_starts_with((string) $key, '_')) {
                        continue;
                    }
                    $value = data_get($item, $entry['paths']['assoc_rssi'].'._value');
                    if (is_numeric($value) && count($rssi) < self::MAX_RSSI) {
                        $rssi[] = (int) round((float) $value);
                    }
                }
            }
        }

        $lan = null;
        if (($entry['supported'] ?? false)) {
            $hosts = data_get($doc, $this->map->abs($entry, $entry['paths']['hosts']));
            if (is_array($hosts)) {
                $lan = 0;
                foreach ($hosts as $key => $host) {
                    if (! is_array($host) || str_starts_with((string) $key, '_')) {
                        continue;
                    }
                    $type = strtolower((string) data_get($host, $entry['paths']['host_interface'].'._value', ''));
                    $active = data_get($host, $entry['paths']['host_active'].'._value');
                    $isActive = $active === true || in_array(strtolower((string) $active), ['1', 'true'], true);
                    if ($isActive && str_contains($type, 'ethernet')) {
                        $lan++;
                    }
                }
            }
        }

        return [$rssi, $lan];
    }
}
