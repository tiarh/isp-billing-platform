<?php

namespace App\Services;

use App\Jobs\ProcessGenieAcsInformJob;
use App\Models\Customer;
use App\Models\GenieAcsDevice;
use App\Models\OltOnu;
use App\Models\Setting;
use App\Services\Cpe\CpeModelMap;
use App\Services\Cpe\CpeSnapshotExtractor;
use App\Services\Cpe\CpeTransitions;
use App\Services\Outage\CpeEvents;
use App\Services\Outage\OutageSettings;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class GenieAcsService
{
    /**
     * Fast production sync: projected NBI list + bulk upsert. Target wall &lt;15s for ~100–200 CPE.
     *
     * @param  array{auto_tag?: bool, auto_tag_limit?: int}  $options
     */
    public function syncDevices(array $options = []): array
    {
        @set_time_limit(90);
        @ini_set('memory_limit', '256M');
        $this->lastBackfillCount = 0;
        $this->lastAutoTagCount = 0;
        $this->cpeReady = null;
        $t0 = microtime(true);

        $baseUrl = rtrim((string) Setting::get('genieacs_url', ''), '/');

        if ($baseUrl === '') {
            throw new \RuntimeException('GenieACS URL belum diisi di settings.');
        }

        $autoTag = (bool) ($options['auto_tag'] ?? true);
        // Higher default: many FHTT devices already carry PPPoE tags; still need name tags.
        $autoTagLimit = max(0, (int) ($options['auto_tag_limit'] ?? 40));

        // Projection only — NEVER full /devices dump (was multi‑MB / multi‑minute).
        $request = $this->httpClient((int) config('services.genieacs.sync_timeout', 20));
        $response = $request->get($baseUrl.'/devices', [
            'projection' => $this->syncProjectionCsv(),
        ]);
        $response->throw();
        $devices = $response->json();
        $fetchMs = (int) round((microtime(true) - $t0) * 1000);

        if (! is_array($devices)) {
            throw new \RuntimeException('Respons GenieACS tidak valid.');
        }

        $customerMaps = $this->buildCustomerLinkMaps();
        $now = now();
        $rows = [];
        $seenDeviceIds = [];
        $hasPresenceColumns = Schema::hasColumn('genieacs_devices', 'snapshot_status')
            && Schema::hasColumn('genieacs_devices', 'last_seen_sync_at');
        $pendingTags = [];

        foreach ($devices as $device) {
            if (! is_array($device)) {
                continue;
            }

            $fields = $this->extractDeviceFields($device);
            $link = $this->resolveCustomerLink($fields, $customerMaps);
            $deviceId = (string) ($device['_id'] ?? $fields['serial_number'] ?? '');
            if ($deviceId === '') {
                continue;
            }
            $seenDeviceIds[] = $deviceId;

            $row = array_merge($fields, $link, [
                'device_id' => $deviceId,
                'last_inform_at' => isset($device['_lastInform']) ? Carbon::parse($device['_lastInform']) : null,
                'registered_at' => isset($device['_registered']) ? Carbon::parse($device['_registered']) : null,
                'parameters' => $this->opticalSnapshot($device),
                'updated_at' => $now,
                'created_at' => $now,
            ]);
            if ($hasPresenceColumns) {
                $row['snapshot_status'] = 'active';
                $row['last_seen_sync_at'] = $now;
            }
            // RCA-3: fresh WLAN / PPP / boot columns (stale values are NULL), raw values + age go to parameters.cpe_raw
            $cpe = $this->cpeLayerRow($device);
            if ($cpe !== null) {
                $row = array_merge($row, $cpe['columns'], ['_cpe' => $cpe['cpe']]);
                $row['parameters'] = array_merge(is_array($row['parameters']) ? $row['parameters'] : [], ['cpe_raw' => $cpe['cpe']['raw']]);
            }
            $rows[] = $row;

            $this->maybeBackfillCustomerOnuSerial($link['customer_id'] ?? null, $fields['serial_number'] ?? null);

            if (
                $autoTag
                && count($pendingTags) < $autoTagLimit
                && ! empty($fields['serial_number'])
            ) {
                // Linked customers + empty-tag CPE (fallback OLT onu_name for RT/RW/ODP).
                $pendingTags[] = ['fields' => $fields, 'link' => $link];
            }
        }

        $synced = $this->bulkUpsertDevices($rows);
        $notFound = count($devices) > 0
            ? $this->markMissingDevices($seenDeviceIds)
            : 0;

        // Auto-tag AFTER upsert, capped — each tag is an HTTP POST to GenieACS.
        foreach ($pendingTags as $item) {
            $this->tryAutoTagFromCustomer($item['fields'], $item['link']);
        }

        $totalMs = (int) round((microtime(true) - $t0) * 1000);

        return [
            'synced' => $synced,
            'fetched' => count($devices),
            'not_found' => $notFound,
            'backfilled_serials' => $this->lastBackfillCount,
            'auto_tagged' => $this->lastAutoTagCount,
            'fetch_ms' => $fetchMs,
            'duration_ms' => $totalMs,
            'projection' => 'csv_minimal',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    protected function bulkUpsertDevices(array $rows, bool $cpeRollup = true): int
    {
        if ($rows === []) {
            return 0;
        }

        // RCA-3: one upsert needs identical keys in every row; a row without CPE columns gets NULLs
        if (collect($rows)->contains(fn ($r) => array_key_exists('wlan_values_at', $r))) {
            $nulls = array_fill_keys(CpeSnapshotExtractor::COLUMNS, null);
            $rows = array_map(fn ($r) => array_key_exists('wlan_values_at', $r) ? $r : $r + $nulls, $rows);
        }

        // RCA-2: an uptime that went down since the last sync/inform is a restart (history only, never throws).
        CpeEvents::recordUptimeResets($rows);

        // RCA-3: boot / WLAN / PPP transitions + cpe_daily from the fresh values (never throws, DB only).
        CpeTransitions::process($rows, null, $cpeRollup);

        $updateColumns = [
            'customer_id', 'match_source', 'serial_number', 'oui', 'product_class',
            'manufacturer', 'model', 'software_version', 'hardware_version',
            'ip_address', 'ip_tr069', 'mac_address', 'ssid', 'ssid_5g', 'tags', 'pelanggan',
            'genieacs_customer_name', 'pppoe_user', 'wan_status', 'uptime_seconds',
            'uptime_text', 'pppoe_uptime', 'pon_mode', 'wifi_clients',
            'wlan_enabled', 'wlan_channel', 'last_inform_at', 'registered_at',
            'parameters', 'updated_at',
        ];
        if (Schema::hasColumn('genieacs_devices', 'snapshot_status')) {
            $updateColumns[] = 'snapshot_status';
        }
        if (Schema::hasColumn('genieacs_devices', 'last_seen_sync_at')) {
            $updateColumns[] = 'last_seen_sync_at';
        }
        // RCA-3: only when every row carries the CPE columns (built by cpeLayerRow, column existence checked there)
        if (array_key_exists('wlan_values_at', $rows[0]) && Schema::hasColumn('genieacs_devices', 'wlan_values_at')) {
            $updateColumns = array_merge($updateColumns, CpeSnapshotExtractor::COLUMNS);
        }

        foreach (array_chunk($rows, 80) as $chunk) {
            // DB::upsert (raw) — Eloquent upsert does not reliably cast JSON arrays.
            $normalized = array_map(function (array $row) {
                unset($row['_cpe']); // RCA-3 internal data (rx, raw ages), not a column
                if (isset($row['tags']) && is_array($row['tags'])) {
                    $row['tags'] = json_encode(array_values($row['tags']));
                } elseif (! isset($row['tags'])) {
                    $row['tags'] = json_encode([]);
                }
                $row['parameters'] = is_array($row['parameters'] ?? null)
                    ? json_encode($row['parameters'])
                    : ($row['parameters'] ?? null);
                if (array_key_exists('wlan_enabled', $row)) {
                    $row['wlan_enabled'] = $row['wlan_enabled'] === null
                        ? null
                        : ($row['wlan_enabled'] ? 1 : 0);
                }
                foreach (['last_inform_at', 'registered_at', 'created_at', 'updated_at'] as $dt) {
                    if (isset($row[$dt]) && $row[$dt] instanceof \DateTimeInterface) {
                        $row[$dt] = $row[$dt]->format('Y-m-d H:i:s');
                    }
                }

                return $row;
            }, $chunk);

            DB::table('genieacs_devices')->upsert($normalized, ['device_id'], $updateColumns);
        }

        return count($rows);
    }

    /**
     * Keep local history, but distinguish devices absent from the latest
     * non-empty GenieACS snapshot from devices that are merely offline.
     */
    protected function markMissingDevices(array $seenDeviceIds): int
    {
        if (! Schema::hasColumn('genieacs_devices', 'snapshot_status') || $seenDeviceIds === []) {
            return 0;
        }

        return DB::table('genieacs_devices')
            ->whereNotIn('device_id', array_values(array_unique($seenDeviceIds)))
            ->where(function ($query): void {
                $query->whereNull('snapshot_status')
                    ->orWhere('snapshot_status', '!=', 'not_found');
            })
            ->update(['snapshot_status' => 'not_found']);
    }

    protected int $lastBackfillCount = 0;

    protected int $lastAutoTagCount = 0;

    protected function maybeBackfillCustomerOnuSerial(?int $customerId, ?string $serial): void
    {
        $serial = $serial ? strtoupper(trim($serial)) : '';
        if ($customerId === null || $serial === '') {
            return;
        }

        $customer = Customer::query()->find($customerId, ['id', 'onu_serial']);
        if ($customer === null || filled($customer->onu_serial)) {
            return;
        }

        $customer->forceFill(['onu_serial' => $serial])->save();
        $this->lastBackfillCount++;
    }

    public function summary(): array
    {
        $currentQuery = GenieAcsDevice::query()
            ->where(function ($query): void {
                $query->whereNull('snapshot_status')
                    ->orWhere('snapshot_status', 'active');
            });
        $devices = (clone $currentQuery)
            ->get(['id', 'manufacturer', 'serial_number', 'ssid', 'last_inform_at', 'customer_id', 'last_ping_at', 'last_ping_success', 'last_ping_rtt_ms', 'tags', 'pppoe_user']);
        $notFound = GenieAcsDevice::query()->where('snapshot_status', 'not_found')->count();

        $online = $devices->filter(fn (GenieAcsDevice $device) => $device->isOnline())->count();
        $pingFreshMinutes = (int) config('services.genieacs.ping_fresh_minutes', 45);
        $pingOk = $devices->filter(
            fn (GenieAcsDevice $device) => $device->last_ping_success
                && $device->last_ping_at
                && $device->last_ping_at->gt(now()->subMinutes($pingFreshMinutes))
        )->count();

        return [
            'total' => $devices->count(),
            'not_found' => $notFound,
            'online' => $online,
            'offline' => max($devices->count() - $online, 0),
            'fiberhome' => $devices->filter(fn (GenieAcsDevice $device) => $this->isFiberhome($device))->count(),
            'linked' => $devices->whereNotNull('customer_id')->count(),
            'unlinked' => $devices->whereNull('customer_id')->count(),
            'ssid_read' => $devices->filter(fn (GenieAcsDevice $device) => filled($device->ssid))->count(),
            'tagged' => $devices->filter(fn (GenieAcsDevice $device) => ! empty($device->tags))->count(),
            'ping_ok' => $pingOk,
            'ping_failed_recent' => $devices->filter(
                fn (GenieAcsDevice $device) => $device->last_ping_at
                    && $device->last_ping_at->gt(now()->subMinutes($pingFreshMinutes))
                    && $device->last_ping_success === false
            )->count(),
        ];
    }

    public function formatListItem(GenieAcsDevice $device): array
    {
        return [
            'id' => $device->id,
            'device_id' => $device->device_id,
            'serial_number' => $device->serial_number,
            'manufacturer' => $device->manufacturer,
            'product_class' => $device->product_class,
            'model' => $device->model,
            'software_version' => $device->software_version,
            'hardware_version' => $device->hardware_version,
            'ip_address' => $device->ip_address,
            'ip_tr069' => $device->ip_tr069,
            'mac_address' => $device->mac_address,
            'ssid' => $device->ssid,
            'ssid_5g' => $device->ssid_5g,
            'tags' => $this->normalizeTags($device->tags ?? []),
            'pelanggan' => $device->pelanggan,
            'genieacs_customer_name' => $device->genieacs_customer_name,
            'pppoe_user' => $device->pppoe_user,
            'snapshot_status' => $device->snapshot_status ?: 'active',
            'last_seen_sync_at' => $device->last_seen_sync_at?->toIso8601String(),
            'wan_status' => $device->wan_status,
            'uptime_seconds' => $device->uptime_seconds,
            'uptime_text' => $device->uptime_text,
            'pppoe_uptime' => $device->pppoe_uptime,
            'pon_mode' => $device->pon_mode,
            'wifi_clients' => $device->wifi_clients,
            'wlan_enabled' => $device->wlan_enabled,
            'wlan_channel' => $device->wlan_channel,
            // RCA-3: fresh WiFi state with its age (stale values are not shown as numbers)
            'cpe' => $this->cpeDisplay($device),
            'rx_power' => is_array($device->parameters) ? ($device->parameters['rx_power'] ?? null) : null,
            'temperature' => is_array($device->parameters) ? ($device->parameters['temperature'] ?? null) : null,
            'match_source' => $device->match_source,
            'is_online' => $device->isOnline(),
            'is_fiberhome' => $this->isFiberhome($device),
            'last_inform_at' => $device->last_inform_at?->toIso8601String(),
            'registered_at' => $device->registered_at?->toIso8601String(),
            'last_ping_at' => $device->last_ping_at?->toIso8601String(),
            'last_ping_success' => $device->last_ping_success,
            'last_ping_rtt_ms' => $device->last_ping_rtt_ms,
            'customer' => $device->customer ? [
                'id' => $device->customer->id,
                'code' => $device->customer->code,
                'name' => $device->customer->name,
                'pppoe_user' => $device->customer->pppoe_user,
            ] : null,
            'display_name' => $device->customer?->name
                ?: $device->pelanggan
                ?: $device->genieacs_customer_name
                ?: $device->pppoe_user
                ?: '-',
        ];
    }

    /**
     * RCA-3: display block of the fresh CPE columns. `fresh` is false (and every value null) when the newest WLAN
     * value is older than cpe_value_max_age_minutes, so the UI shows "data lama" instead of a stale number.
     *
     * `known` = some WiFi value exists (fresh or stale); false = nothing was ever read ("belum ada data").
     *
     * @return array{fresh: bool, known?: bool, age_min: int|null, clients: int|null, wlan24_on: bool|null, wlan5_on: bool|null, last_boot_at: string|null, ppp_status: string|null}
     */
    protected function cpeDisplay(GenieAcsDevice $device): array
    {
        $out = ['fresh' => false, 'age_min' => null, 'clients' => null, 'wlan24_on' => null, 'wlan5_on' => null,
            'last_boot_at' => $device->last_boot_at?->toIso8601String(), 'ppp_status' => null];
        $out['known'] = false;
        $at = $device->wlan_values_at;
        if ($at === null) {
            // stale values are stored as NULL; the raw value and its age live in parameters.cpe_raw
            $raw = is_array($device->parameters) ? ($device->parameters['cpe_raw'] ?? null) : null;
            $ages = array_filter([
                is_array($raw['wlan24'] ?? null) ? ($raw['wlan24']['age_min'] ?? null) : null,
                is_array($raw['wlan5'] ?? null) ? ($raw['wlan5']['age_min'] ?? null) : null,
            ], fn ($v) => is_numeric($v));
            if ($ages !== []) {
                $out['known'] = true;
                $out['age_min'] = (int) min($ages);
            }

            return $out;
        }
        $out['known'] = true;
        $age = max(0, (int) $at->diffInMinutes(now(), false));
        $out['age_min'] = $age;
        if ($age > OutageSettings::cpeValueMaxAgeMinutes()) {
            return $out;
        }
        $on = function (?string $status, mixed $enabled): ?bool {
            if ($status !== null && $status !== '') {
                return strtolower($status) === 'up';
            }

            return $enabled === null ? null : (bool) $enabled;
        };
        $known = array_filter([$device->wlan24_assoc, $device->wlan5_assoc], fn ($v) => $v !== null);

        return array_merge($out, [
            'fresh' => true,
            'clients' => $known === [] ? null : (int) array_sum($known),
            'wlan24_on' => $on($device->wlan24_status, $device->wlan24_enabled),
            'wlan5_on' => $on($device->wlan5_status, $device->wlan5_enabled),
            'ppp_status' => $device->ppp_status,
        ]);
    }

    public function formatDetail(GenieAcsDevice $device): array
    {
        $item = $this->formatListItem($device);
        $liveDevice = $this->fetchDevice($device->device_id);
        $summary = $this->parameterSummary(is_array($liveDevice) ? $liveDevice : []);
        $stored = is_array($device->parameters) ? $device->parameters : [];
        $labels = array_column($summary, 'label');
        foreach ([
            'Optical RX' => $stored['rx_power'] ?? null,
            'Suhu optik' => $stored['temperature'] ?? null,
            'SSID 5G' => $stored['ssid_5g'] ?? null,
        ] as $label => $value) {
            if ($value === null || $value === '' || in_array($label, $labels, true)) {
                continue;
            }
            $summary[] = ['label' => $label, 'value' => is_scalar($value) ? (string) $value : json_encode($value)];
        }
        $item['parameter_summary'] = $summary;
        $item['updated_at'] = $device->updated_at?->toIso8601String();

        return $item;
    }

    public function suggestOnuNameForSerial(string $serial): ?array
    {
        $normalized = strtoupper(preg_replace('/[^A-Z0-9]/', '', trim($serial)) ?? '');
        if ($normalized === '') {
            return null;
        }

        $device = GenieAcsDevice::query()
            ->whereRaw('UPPER(REPLACE(REPLACE(serial_number, "-", ""), " ", "")) = ?', [$normalized])
            ->first(['id', 'device_id', 'serial_number', 'pppoe_user', 'genieacs_customer_name', 'pelanggan', 'tags']);

        if (! $device) {
            return null;
        }

        $base = [
            'device_id' => $device->device_id,
            'serial_number' => $device->serial_number,
            'tags' => $this->normalizeTags($device->tags ?? []),
        ];

        if (filled($device->pppoe_user)) {
            return $base + [
                'suggested_name' => $this->normalizeOnuDisplayName((string) $device->pppoe_user),
                'name_source' => 'genieacs_pppoe',
            ];
        }

        $tagName = $this->pickOnuNameFromTags($device->tags ?? []);
        if ($tagName !== null) {
            return $base + [
                'suggested_name' => $tagName,
                'name_source' => 'genieacs_tag',
                'matched_tag' => $tagName,
            ];
        }

        $pelanggan = $device->genieacs_customer_name ?: $device->pelanggan;
        if (filled($pelanggan)) {
            return $base + [
                'suggested_name' => $this->normalizeOnuDisplayName((string) $pelanggan),
                'name_source' => 'genieacs_pelanggan',
            ];
        }

        return null;
    }

    /**
     * Nama pelanggan / tag GenieACS dari form aktivasi.
     * Pertahankan titik & strip (contoh: "2.9" tetap "2.9", bukan "29").
     * Spasi → underscore; karakter lain dibuang.
     */
    public function normalizeOnuDisplayName(string $value): string
    {
        $value = strtoupper(str_replace(' ', '_', trim($value)));
        // Allow letters, digits, underscore, dot, hyphen (package names like 2.9, FOO-BAR)
        $value = preg_replace('/[^A-Z0-9_.\-]/', '', $value) ?? '';
        // Collapse runs of separators; trim edges
        $value = preg_replace('/[_.\-]{2,}/', '_', $value) ?? $value;
        $value = trim($value, '_.-');

        return mb_substr($value, 0, 64);
    }

    public function pickOnuNameFromTags(array $tags): ?string
    {
        foreach ($this->normalizeTags($tags) as $tag) {
            if ($tag === '') {
                continue;
            }

            if (str_contains($tag, '/')) {
                $tag = trim(explode('/', $tag, 2)[0]);
            }

            $normalized = $this->normalizeOnuDisplayName($tag);

            return $normalized !== '' ? $normalized : null;
        }

        return null;
    }

    public function fetchDevice(string $deviceId): ?array
    {
        $baseUrl = rtrim((string) Setting::get('genieacs_url', ''), '/');
        if ($baseUrl === '') {
            return null;
        }

        $request = $this->httpClient();
        $projection = $this->detailProjection();

        // Path lookup first
        $response = $request->get(
            $baseUrl.'/devices/'.rawurlencode($deviceId).'?projection='.rawurlencode($projection)
        );

        if ($response->successful()) {
            $device = $response->json();
            if (is_array($device) && ! empty($device)) {
                // NBI sometimes returns a 1-element list for path GETs
                if (isset($device[0]) && is_array($device[0])) {
                    return $device[0];
                }

                return $device;
            }
        }

        // Fallback: query by _id (more reliable on some GenieACS builds)
        $response = $request->get($baseUrl.'/devices', [
            'query' => json_encode(['_id' => $deviceId], JSON_UNESCAPED_SLASHES),
            'projection' => $projection,
        ]);

        if (! $response->successful()) {
            return null;
        }

        $devices = $response->json();
        if (is_array($devices) && isset($devices[0]) && is_array($devices[0])) {
            return $devices[0];
        }

        return is_array($devices) && ! isset($devices[0]) && isset($devices['_id']) ? $devices : null;
    }

    /**
     * @return array{
     *   success: bool,
     *   host: string,
     *   success_count: int,
     *   failure_count: int,
     *   average_rtt_ms: int|null,
     *   diagnostics_state: string|null,
     *   error: string|null
     * }
     */
    public function runPingDiagnostic(GenieAcsDevice $device, ?string $host = null): array
    {
        $host = trim((string) ($host ?: Setting::get(
            'genieacs_ping_host',
            config('services.genieacs.ping_host', '8.8.8.8')
        )));
        if ($host === '') {
            throw new \RuntimeException('Ping host kosong. Isi GENIEACS_PING_HOST atau setting genieacs_ping_host.');
        }

        $freshDevice = $this->fetchDevice($device->device_id);
        if ($freshDevice === null) {
            throw new \RuntimeException('Device tidak ditemukan di GenieACS.');
        }

        $prefix = $this->resolvePingPrefix($freshDevice);
        $repetitions = max(1, (int) config('services.genieacs.ping_repetitions', 3));

        $this->task($device, 'setParameterValues', [
            'parameterValues' => [
                [$prefix.'.Host', $host, 'xsd:string'],
                [$prefix.'.NumberOfRepetitions', $repetitions, 'xsd:unsignedInt'],
                [$prefix.'.Timeout', 5000, 'xsd:unsignedInt'],
                [$prefix.'.DataBlockSize', 64, 'xsd:unsignedInt'],
                [$prefix.'.DiagnosticsState', 'Requested', 'xsd:string'],
            ],
        ]);

        $deadline = microtime(true) + max(10, (int) config('services.genieacs.ping_poll_seconds', 30));
        $successCount = 0;
        $failureCount = 0;
        $averageRtt = null;
        $diagnosticsState = null;
        $error = null;

        while (microtime(true) < $deadline) {
            sleep(3);
            $freshDevice = $this->fetchDevice($device->device_id) ?? [];
            $successCount = (int) ($this->value($freshDevice, $prefix.'.SuccessCount') ?? 0);
            $failureCount = (int) ($this->value($freshDevice, $prefix.'.FailureCount') ?? 0);
            $diagnosticsState = (string) ($this->value($freshDevice, $prefix.'.DiagnosticsState') ?? '');
            $averageRtt = $this->value($freshDevice, $prefix.'.AverageResponseTime');
            $averageRtt = $averageRtt !== null ? (int) round((float) $averageRtt) : null;

            if (strcasecmp($diagnosticsState, 'Complete') === 0 || $successCount > 0) {
                break;
            }
            if (strcasecmp($diagnosticsState, 'Error') === 0) {
                $error = 'DiagnosticsState=Error';
                break;
            }
        }

        $success = $successCount > 0;
        if (! $success && $error === null) {
            $error = $failureCount > 0
                ? "Ping failures={$failureCount}"
                : 'Ping timeout (device tidak merespons diagnostics)';
        }

        $device->forceFill([
            'last_ping_at' => now(),
            'last_ping_success' => $success,
            'last_ping_host' => $host,
            'last_ping_success_count' => $successCount,
            'last_ping_failure_count' => $failureCount,
            'last_ping_rtt_ms' => $averageRtt,
            'last_ping_error' => $success ? null : $error,
            'parameters' => $freshDevice,
        ])->save();

        return [
            'success' => $success,
            'host' => $host,
            'success_count' => $successCount,
            'failure_count' => $failureCount,
            'average_rtt_ms' => $averageRtt,
            'diagnostics_state' => $diagnosticsState,
            'error' => $error,
        ];
    }

    protected function resolvePingPrefix(array $device): string
    {
        foreach (['InternetGatewayDevice.IPPingDiagnostics', 'Device.IP.Diagnostics.IPPing'] as $prefix) {
            if ($this->deviceHasParameter($device, $prefix)) {
                return $prefix;
            }
        }

        return 'InternetGatewayDevice.IPPingDiagnostics';
    }

    protected function deviceHasParameter(array $device, string $prefix): bool
    {
        foreach (array_keys($device) as $key) {
            if (is_string($key) && str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return data_get($device, $prefix) !== null;
    }

    /**
     * Instant ingest when GenieACS (or ext) notifies eBilling that a CPE informed / registered.
     * Pulls one device from ACS NBI, upserts eBilling row, tries auto-tag if customer matched.
     *
     * @param  array{device_id?: string, serial?: string, serial_number?: string, event?: string, tag?: string}  $payload
     * @return array{ok: bool, device_id?: string|null, serial?: string|null, upserted?: bool, tagged?: bool, tag?: string|null, pending?: bool, message?: string, match_source?: string|null}
     */
    public function ingestDeviceInform(array $payload): array
    {
        $deviceId = trim((string) ($payload['device_id'] ?? $payload['_id'] ?? ''));
        $serial = strtoupper(trim((string) ($payload['serial'] ?? $payload['serial_number'] ?? '')));
        $preferredTag = $this->normalizeOnuDisplayName((string) ($payload['tag'] ?? $payload['name'] ?? ''));
        $event = (string) ($payload['event'] ?? 'inform');

        if ($deviceId === '' && $serial === '') {
            return ['ok' => false, 'message' => 'device_id atau serial wajib'];
        }

        $baseUrl = rtrim((string) Setting::get('genieacs_url', ''), '/');
        if ($baseUrl === '') {
            return ['ok' => false, 'message' => 'GenieACS URL belum diisi di settings'];
        }

        if ($deviceId === '' && $serial !== '') {
            $deviceId = (string) ($this->findDeviceIdBySerial($serial) ?? '');
        }

        if ($deviceId === '') {
            return [
                'ok' => false,
                'pending' => true,
                'serial' => $serial !== '' ? $serial : null,
                'message' => 'Device belum di ACS — retry inform nanti',
            ];
        }

        $device = $this->fetchDevice($deviceId);
        if ($device === null) {
            // Fallback: query by serial if device_id stale
            if ($serial !== '') {
                $found = $this->findDeviceIdBySerial($serial);
                if ($found) {
                    $deviceId = $found;
                    $device = $this->fetchDevice($deviceId);
                }
            }
        }

        if ($device === null) {
            // Tag-only path: post-provision has serial+name; POST /tags does not need full fetch.
            if ($preferredTag !== '' && $serial !== '') {
                $tagResult = $this->pushTagForSerial($serial, $preferredTag);
                if ($tagResult['pushed'] ?? false) {
                    return [
                        'ok' => true,
                        'device_id' => $tagResult['device_id'] ?? $deviceId,
                        'serial' => $serial,
                        'upserted' => false,
                        'tagged' => true,
                        'tag' => $preferredTag,
                        'message' => 'Tagged tanpa full fetch (device path NBI bermasalah)',
                    ];
                }
            }

            return [
                'ok' => false,
                'pending' => true,
                'device_id' => $deviceId,
                'serial' => $serial !== '' ? $serial : null,
                'message' => 'Gagal fetch device dari ACS',
            ];
        }

        $fields = $this->extractDeviceFields($device);
        if (empty($fields['serial_number']) && $serial !== '') {
            $fields['serial_number'] = $serial;
        }
        $serial = $fields['serial_number'] ?? $serial;

        $link = $this->resolveCustomerLink($fields);
        $now = now();
        $row = array_merge($fields, $link, [
            'device_id' => $deviceId,
            'last_inform_at' => isset($device['_lastInform']) ? Carbon::parse($device['_lastInform']) : $now,
            'registered_at' => isset($device['_registered']) ? Carbon::parse($device['_registered']) : null,
            'parameters' => null,
            'updated_at' => $now,
            'created_at' => $now,
        ]);
        // RCA-3: same fresh CPE columns as the sync (parameters stays null here, as before)
        $this->cpeReady = null;
        $cpe = $this->cpeLayerRow($device);
        if ($cpe !== null) {
            $row = array_merge($row, $cpe['columns'], ['_cpe' => $cpe['cpe']]);
        }

        $this->bulkUpsertDevices([$row], false); // an inform ingest is not a sync sample: no cpe_daily counters
        $this->maybeBackfillCustomerOnuSerial($link['customer_id'] ?? null, is_string($serial) ? $serial : null);

        // RCA-2: a BOOT / BOOTSTRAP inform is a CPE restart, kept as history (not only logged).
        if (CpeEvents::isBootEvent($event)) {
            CpeEvents::record(isset($link['customer_id']) ? (int) $link['customer_id'] : null, $deviceId, 'boot', $now, ['event' => mb_substr($event, 0, 40)]);
        }

        $tagged = false;
        $tagName = $preferredTag;
        if ($tagName === '' && ! empty($link['customer_id'])) {
            $customer = Customer::query()->find($link['customer_id'], ['id', 'name', 'pppoe_user']);
            $tagName = $customer ? $this->preferredCustomerTag($customer) : '';
        }

        if ($tagName !== '' && is_string($serial) && $serial !== '') {
            $existingTags = $fields['tags'] ?? [];
            $already = is_array($existingTags) && collect($existingTags)->contains(
                fn ($t) => strcasecmp((string) $t, $tagName) === 0
            );
            if (! $already) {
                $tagResult = $this->pushTagForSerial($serial, $tagName);
                $tagged = (bool) ($tagResult['pushed'] ?? false);
                if (! $tagged && empty($tagResult['pending'])) {
                    Log::warning('[GenieACS] inform auto-tag failed', [
                        'serial' => $serial,
                        'tag' => $tagName,
                        'result' => $tagResult,
                    ]);
                }
            } else {
                $tagged = true;
            }
        }

        Log::info('[GenieACS] device inform ingested', [
            'event' => $event,
            'device_id' => $deviceId,
            'serial' => $serial,
            'customer_id' => $link['customer_id'] ?? null,
            'tagged' => $tagged,
        ]);

        return [
            'ok' => true,
            'device_id' => $deviceId,
            'serial' => is_string($serial) ? $serial : null,
            'upserted' => true,
            'tagged' => $tagged,
            'tag' => $tagName !== '' ? $tagName : null,
            'match_source' => $link['match_source'] ?? null,
            'message' => $tagged
                ? 'Device upserted + tagged'
                : 'Device upserted'.(empty($link['customer_id']) ? ' (belum match customer — tag ditunda)' : ' (tag pending/failed)'),
        ];
    }

    /**
     * After FiberHome activate/migrate: push customer name as ACS tag ASAP.
     * Polls ACS briefly (CPE often already registered on migrate), then queues retries.
     *
     * @return array{pushed: bool, pending?: bool, queued?: bool, device_id?: string, tag?: string, message?: string, attempts?: int}
     */
    public function ensureTagAfterProvision(string $serial, string $name): array
    {
        // Never throw — FiberHome provision UI must stay green after OLT write.
        try {
            $tag = $this->normalizeOnuDisplayName($name);
            $serial = strtoupper(trim($serial));

            if ($tag === '' || $serial === '') {
                return [
                    'pushed' => false,
                    'pending' => true,
                    'message' => 'SN/nama kosong — tag ACS dilewati',
                ];
            }

            // One immediate push — no sleep (do not block provision HTTP).
            $result = $this->pushTagForSerial($serial, $tag);
            $result['attempts'] = 1;
            $result['tag'] = $tag;

            if ($result['pushed'] ?? false) {
                Log::info('[GenieACS] ensureTagAfterProvision pushed', [
                    'serial' => $serial,
                    'tag' => $tag,
                    'device_id' => $result['device_id'] ?? null,
                    'attempts' => 1,
                ]);

                return $result;
            }

            // Config missing — soft pending, no spam queue
            if (empty($result['pending']) && isset($result['message'])
                && str_contains((string) $result['message'], 'belum diisi')) {
                $result['pending'] = true;

                return $result;
            }

            // Always queue aggressive retries — never surface ACS miss as hard error
            try {
                ProcessGenieAcsInformJob::dispatch([
                    'serial' => $serial,
                    'tag' => $tag,
                    'event' => 'post_provision',
                ])->delay(now()->addSeconds(5));
            } catch (\Throwable $dispatchErr) {
                Log::warning('[GenieACS] ensureTagAfterProvision queue dispatch failed', [
                    'serial' => $serial,
                    'error' => $dispatchErr->getMessage(),
                ]);
            }

            return [
                'pushed' => false,
                'pending' => true,
                'queued' => true,
                'tag' => $tag,
                'attempts' => 1,
                // Friendly only — never raw exception / "payload is invalid"
                'message' => 'Tag ACS dijadwalkan otomatis (device mungkin belum inform)',
            ];
        } catch (\Throwable $e) {
            Log::warning('[GenieACS] ensureTagAfterProvision soft-fail', [
                'serial' => $serial,
                'error' => $e->getMessage(),
            ]);

            try {
                ProcessGenieAcsInformJob::dispatch([
                    'serial' => strtoupper(trim($serial)),
                    'tag' => $this->normalizeOnuDisplayName($name),
                    'event' => 'post_provision_soft_fail',
                ])->delay(now()->addSeconds(5));
            } catch (\Throwable) {
            }

            return [
                'pushed' => false,
                'pending' => true,
                'queued' => true,
                'tag' => $this->normalizeOnuDisplayName($name),
                'message' => 'Tag ACS dijadwalkan otomatis',
            ];
        }
    }

    /**
     * Push pelanggan name as GenieACS device tag (POST /devices/{id}/tags/{tag}).
     *
     * @return array{pushed: bool, pending?: bool, device_id?: string, tag?: string, message?: string}
     */
    public function pushTagForSerial(string $serial, string $tag): array
    {
        try {
            $tagTimeout = max(2, min(8, (int) config('services.genieacs.tag_timeout', 5)));
            $tag = $this->normalizeOnuDisplayName($tag);
            $serial = strtoupper(trim($serial));

            if ($tag === '' || $serial === '') {
                return ['pushed' => false, 'pending' => true, 'message' => 'SN atau tag kosong'];
            }

            $baseUrl = rtrim((string) Setting::get('genieacs_url', ''), '/');
            if ($baseUrl === '') {
                return [
                    'pushed' => false,
                    'pending' => true,
                    'tag' => $tag,
                    'message' => 'GenieACS URL belum diisi di settings',
                ];
            }

            $deviceId = $this->findDeviceIdBySerial($serial, false, $tagTimeout);
            if ($deviceId === null) {
                return [
                    'pushed' => false,
                    'pending' => true,
                    'tag' => $tag,
                    'message' => 'Device belum muncul di GenieACS — tag otomatis saat inform',
                ];
            }

            $posted = $this->postDeviceTag($baseUrl, $deviceId, $tag, $tagTimeout);
            if (! $posted['ok']) {
                // Stale local device_id? Re-resolve from ACS only and retry once.
                $freshId = $this->findDeviceIdBySerial($serial, preferAcs: true, timeout: $tagTimeout);
                if ($freshId && $freshId !== $deviceId) {
                    $deviceId = $freshId;
                    $posted = $this->postDeviceTag($baseUrl, $deviceId, $tag, $tagTimeout);
                }
            }

            if (! $posted['ok']) {
                return [
                    'pushed' => false,
                    'pending' => true,
                    'device_id' => $deviceId,
                    'tag' => $tag,
                    'message' => 'Tag ACS akan di-retry otomatis',
                ];
            }

            // One primary customer tag: drop PPPoE/legacy extras so ACS UI shows a single name.
            try {
                $removed = $this->pruneSecondaryTags($baseUrl, $deviceId, $tag);
                $this->setLocalPrimaryTag($deviceId, $tag, $removed);
            } catch (\Throwable $pruneErr) {
                Log::debug('[GenieACS] pruneSecondaryTags soft-fail', ['error' => $pruneErr->getMessage()]);
                $removed = [];
            }

            return [
                'pushed' => true,
                'device_id' => $deviceId,
                'tag' => $tag,
                'removed_tags' => $removed,
            ];
        } catch (\Throwable $e) {
            Log::warning('[GenieACS] pushTagForSerial soft-fail', [
                'serial' => $serial,
                'error' => $e->getMessage(),
            ]);

            return [
                'pushed' => false,
                'pending' => true,
                'tag' => $this->normalizeOnuDisplayName($tag),
                'message' => 'Tag ACS akan di-retry otomatis',
            ];
        }
    }

    /**
     * @return array{ok: bool, status?: int, message?: string}
     */
    protected function postDeviceTag(string $baseUrl, string $deviceId, string $tag, ?int $timeout = null): array
    {
        $urls = $this->deviceTagUrls($baseUrl, $deviceId, $tag);

        $lastStatus = 0;
        foreach ($urls as $url) {
            try {
                $response = $this->httpClient($timeout)->post($url);
                $lastStatus = $response->status();
                // 200 OK or 201 Created; 409/200 if tag already exists on some builds
                if ($response->successful() || $lastStatus === 409) {
                    return ['ok' => true, 'status' => $lastStatus];
                }
            } catch (\Throwable $e) {
                Log::debug('[GenieACS] postDeviceTag attempt failed', [
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'ok' => false,
            'status' => $lastStatus,
            'message' => 'GenieACS menolak tag (HTTP '.$lastStatus.')',
        ];
    }

    /**
     * DELETE /devices/{id}/tags/{tag}
     * GenieACS 1.2 treats unencoded "." in tag path as missing (404) — must use %2E.
     *
     * @return array{ok: bool, status?: int}
     */
    protected function deleteDeviceTag(string $baseUrl, string $deviceId, string $tag): array
    {
        $tag = $this->normalizeOnuDisplayName($tag);
        if ($tag === '') {
            return ['ok' => false];
        }

        $urls = $this->deviceTagUrls($baseUrl, $deviceId, $tag);

        foreach ($urls as $url) {
            try {
                $response = $this->httpClient()->delete($url);
                $status = $response->status();
                if ($response->successful()) {
                    return ['ok' => true, 'status' => $status];
                }
            } catch (\Throwable $e) {
                Log::debug('[GenieACS] deleteDeviceTag failed', [
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['ok' => false];
    }

    /**
     * Build candidate NBI URLs for device tags.
     * Dots in tags must be %2E or GenieACS returns 404 on DELETE.
     *
     * @return list<string>
     */
    protected function deviceTagUrls(string $baseUrl, string $deviceId, string $tag): array
    {
        $tagEnc = $this->encodeGenieAcsPathSegment($tag);
        $idEnc = rawurlencode($deviceId);

        return array_values(array_unique([
            $baseUrl.'/devices/'.$idEnc.'/tags/'.$tagEnc,
            $baseUrl.'/devices/'.$deviceId.'/tags/'.$tagEnc,
        ]));
    }

    /**
     * Path-segment encode for GenieACS tags/device ids.
     * PHP rawurlencode leaves "." unencoded; GenieACS DELETE requires %2E for dots in tags.
     */
    protected function encodeGenieAcsPathSegment(string $value): string
    {
        return str_replace(['.', '*'], ['%2E', '%2A'], rawurlencode($value));
    }

    /**
     * Preferred ACS tag for a customer: PPPoE first (has RT/RW/ODP), then name.
     */
    public function preferredCustomerTag(Customer $customer): string
    {
        $pppoe = $this->normalizeOnuDisplayName((string) ($customer->pppoe_user ?? ''));
        if ($pppoe !== '') {
            return $pppoe;
        }

        return $this->normalizeOnuDisplayName((string) ($customer->name ?? ''));
    }

    /**
     * Resolve best tag string for provision/sync:
     * 1) explicit form value if it already looks like PPPoE (has _RT/_RW)
     * 2) customer pppoe_user / name
     * 3) raw form value
     */
    public function resolvePreferredTag(string $formName, ?Customer $customer = null): string
    {
        $form = $this->normalizeOnuDisplayName($formName);
        if ($form !== '' && preg_match('/_RT\d|_RW\d/i', $form)) {
            return $form;
        }
        if ($customer) {
            $fromCustomer = $this->preferredCustomerTag($customer);
            if ($fromCustomer !== '') {
                return $fromCustomer;
            }
        }

        return $form;
    }

    /**
     * Keep only $keepTag on the ACS device (single primary tag with RT/RW/ODP when possible).
     *
     * @return list<string> removed tag names
     */
    protected function pruneSecondaryTags(string $baseUrl, string $deviceId, string $keepTag): array
    {
        $keepTag = $this->normalizeOnuDisplayName($keepTag);
        $existing = $this->fetchDeviceTags($baseUrl, $deviceId);
        $removed = [];

        foreach ($existing as $tag) {
            $norm = $this->normalizeOnuDisplayName((string) $tag);
            if ($norm === '' || strcasecmp($norm, $keepTag) === 0) {
                continue;
            }
            if ($this->deleteDeviceTag($baseUrl, $deviceId, $norm)['ok'] ?? false) {
                $removed[] = $norm;
            }
        }

        return $removed;
    }

    /**
     * @return list<string>
     */
    protected function fetchDeviceTags(string $baseUrl, string $deviceId): array
    {
        try {
            $response = $this->httpClient()->get($baseUrl.'/devices', [
                'query' => json_encode(['_id' => $deviceId], JSON_UNESCAPED_SLASHES),
                'projection' => '_id,_tags',
            ]);
            if (! $response->successful()) {
                return [];
            }
            $devices = $response->json();
            $row = is_array($devices) && isset($devices[0]) ? $devices[0] : null;
            if (! is_array($row)) {
                return [];
            }

            return $this->normalizeTags($row['_tags'] ?? []);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param  bool  $preferAcs  Skip local DB cache (stale device_id after replace/migrate).
     */
    public function findDeviceIdBySerial(string $serial, bool $preferAcs = false, ?int $timeout = null): ?string
    {
        $serial = strtoupper(trim($serial));
        $normalized = strtoupper(preg_replace('/[^A-Z0-9]/', '', $serial) ?? '');

        if ($normalized === '') {
            return null;
        }

        if (! $preferAcs) {
            $local = GenieAcsDevice::query()
                ->whereRaw('UPPER(REPLACE(REPLACE(serial_number, "-", ""), " ", "")) = ?', [$normalized])
                ->value('device_id');

            if (filled($local)) {
                return (string) $local;
            }
        }

        $baseUrl = rtrim((string) Setting::get('genieacs_url', ''), '/');
        if ($baseUrl === '') {
            return null;
        }

        $queries = [
            ['_deviceId._SerialNumber' => $serial],
            ['_deviceId._SerialNumber' => $normalized],
            // FiberHome SN sometimes only in TR-069 path
            ['Device.DeviceInfo.SerialNumber' => $serial],
            ['InternetGatewayDevice.DeviceInfo.SerialNumber' => $serial],
            // Regex ends-with for id suffix (e.g. 000AC2-HG6045F3-FHTT0000A001)
            ['_id' => ['$regex' => $normalized.'$']],
        ];

        foreach ($queries as $query) {
            $response = $this->httpClient($timeout)->get($baseUrl.'/devices', [
                'query' => json_encode($query, JSON_UNESCAPED_SLASHES),
                'projection' => '_id',
            ]);

            if (! $response->successful()) {
                continue;
            }

            $devices = $response->json();
            if (is_array($devices) && isset($devices[0]['_id'])) {
                return (string) $devices[0]['_id'];
            }
        }

        // If preferAcs failed, still allow local as last resort
        if ($preferAcs) {
            $local = GenieAcsDevice::query()
                ->whereRaw('UPPER(REPLACE(REPLACE(serial_number, "-", ""), " ", "")) = ?', [$normalized])
                ->value('device_id');

            if (filled($local)) {
                return (string) $local;
            }
        }

        return null;
    }

    protected function tryAutoTagFromCustomer(array $fields, array $link): void
    {
        $serial = (string) ($fields['serial_number'] ?? '');
        if ($serial === '') {
            return;
        }

        $existing = $this->normalizeTags($fields['tags'] ?? []);
        // Prefer PPPoE user (includes RT/RW/ODP), then customer name, then OLT onu_name.
        $tag = '';
        if (! empty($link['customer_id'])) {
            $customer = Customer::query()->find($link['customer_id'], ['id', 'name', 'pppoe_user']);
            if ($customer) {
                $tag = $this->preferredCustomerTag($customer);
            }
        }
        if ($tag === '') {
            $tag = $this->normalizeOnuDisplayName((string) ($fields['pppoe_user'] ?? ''));
        }
        if ($tag === '') {
            $tag = $this->lookupOltOnuNameTag($serial);
        }
        if ($tag === '') {
            return;
        }

        $already = collect($existing)->contains(
            fn ($t) => strcasecmp((string) $t, $tag) === 0
        );
        // If already has preferred tag and nothing else needed, skip.
        if ($already && count($existing) <= 1) {
            return;
        }
        // Empty tags OR wrong/secondary-only tags → push preferred (prunes extras).
        if ($already && count($existing) > 1) {
            // Still prune duals to preferred
        } elseif ($already) {
            return;
        }

        $result = $this->pushTagForSerial($serial, $tag);
        if ($result['pushed'] ?? false) {
            $this->lastAutoTagCount++;
        }
    }

    /**
     * Fallback when CPE not linked to customer yet: use OLT inventory ONU name (has RT/RW/ODP).
     */
    protected function lookupOltOnuNameTag(string $serial): string
    {
        $serial = strtoupper(trim($serial));
        $norm = strtoupper(preg_replace('/[^A-Z0-9]/', '', $serial) ?? '');
        if ($norm === '' || ! class_exists(OltOnu::class)) {
            // raw query works even if model missing
            try {
                $name = DB::table('olt_onus')
                    ->whereRaw('UPPER(REPLACE(REPLACE(serial_number,"-","")," ","")) = ?', [$norm])
                    ->value('onu_name');

                return $this->normalizeOnuDisplayName((string) ($name ?? ''));
            } catch (\Throwable) {
                return '';
            }
        }

        try {
            $name = OltOnu::query()
                ->whereRaw('UPPER(REPLACE(REPLACE(serial_number,"-","")," ","")) = ?', [$norm])
                ->value('onu_name');

            return $this->normalizeOnuDisplayName((string) ($name ?? ''));
        } catch (\Throwable) {
            return '';
        }
    }

    protected function mergeLocalDeviceTag(string $deviceId, string $tag): void
    {
        $this->setLocalPrimaryTag($deviceId, $tag, []);
    }

    /**
     * Mirror ACS primary-tag policy into local genieacs_devices row.
     *
     * @param  list<string>  $removed
     */
    protected function setLocalPrimaryTag(string $deviceId, string $tag, array $removed = []): void
    {
        $device = GenieAcsDevice::query()->where('device_id', $deviceId)->first();
        if ($device === null) {
            return;
        }

        $tags = $this->normalizeTags($device->tags ?? []);
        $tags = array_values(array_filter(
            $tags,
            fn ($t) => ! collect($removed)->contains(fn ($r) => strcasecmp((string) $r, (string) $t) === 0)
                && strcasecmp((string) $t, $tag) !== 0
        ));
        // Primary tag first (and only logical customer tag we care about)
        array_unshift($tags, $tag);
        $tags = array_values(array_unique($tags));

        // Prefer single tag in eBilling mirror when we pruned ACS extras
        if ($removed !== []) {
            $tags = [$tag];
        }

        $device->forceFill([
            'tags' => $tags,
            'genieacs_customer_name' => $tag,
        ])->save();
    }

    public function reboot(GenieAcsDevice $device): array
    {
        return $this->task($device, 'reboot');
    }

    public function setParameter(GenieAcsDevice $device, string $name, mixed $value): array
    {
        // GenieACS setParameterValues expects a 3-tuple [name, value, type];
        // a 2-tuple is rejected by the NBI.
        $type = match (true) {
            is_bool($value) => 'xsd:boolean',
            is_int($value) => 'xsd:int',
            is_float($value) => 'xsd:double',
            default => 'xsd:string',
        };

        return $this->task($device, 'setParameterValues', [
            'parameterValues' => [[$name, $value, $type]],
        ]);
    }

    protected function extractDeviceFields(array $device): array
    {
        $serial = $this->firstValue($device, [
            'VirtualParameters.ontSerial',
            '_deviceId._SerialNumber',
            'Device.DeviceInfo.SerialNumber',
            'InternetGatewayDevice.DeviceInfo.SerialNumber',
        ]);

        $tags = $this->normalizeTags($device['_tags'] ?? []);
        $pelanggan = $this->value($device, 'VirtualParameters.pelanggan')
            ?: $this->value($device, 'InternetGatewayDevice.X_FH_Account.CustomerID');

        $pppoeUser = $this->firstValue($device, [
            'VirtualParameters.pppUsername',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.Username',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.2.WANPPPConnection.1.Username',
            'Device.PPP.Interface.1.Username',
        ]);

        if (! $pppoeUser) {
            $pppoeUser = $this->guessPppoeFromTags($tags);
        }

        $genieacsCustomerName = $pelanggan ?: $this->guessCustomerNameFromTags($tags);

        // PPPoE IP (public) and TR-069/management IP are distinct; keep them
        // separate so the UI never shows a doubled/ambiguous "IP" value.
        $pppoeIp = $this->firstValue($device, [
            'VirtualParameters.pppIp',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.ExternalIPAddress',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.2.WANPPPConnection.1.ExternalIPAddress',
        ]);
        $ipTr069 = $this->firstValue($device, [
            'VirtualParameters.ipTr069',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.ExternalIPAddress',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.2.WANIPConnection.1.ExternalIPAddress',
            'Device.IP.Interface.1.IPv4Address.1.IPAddress',
        ]);

        $wanStatus = $this->firstValue($device, [
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.ConnectionStatus',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.ConnectionStatus',
        ]);
        if (! $wanStatus && ($pppoeUser || $pppoeIp)) {
            // FiberHome HG6045F3 exposes no ConnectionStatus; derive from the
            // presence of a live PPPoE username/IP instead of the Enable flag.
            $wanStatus = 'Up';
        }

        return [
            'serial_number' => $serial ? strtoupper(trim((string) $serial)) : null,
            'oui' => $this->value($device, '_deviceId._OUI'),
            'product_class' => $this->value($device, '_deviceId._ProductClass'),
            'manufacturer' => $this->firstValue($device, [
                'Device.DeviceInfo.Manufacturer',
                '_deviceId._Manufacturer',
                'InternetGatewayDevice.DeviceInfo.Manufacturer',
            ]),
            // RCA-3: GenieACS never fetches DeviceInfo.ModelName on the FiberHome fleet, so fall back to the
            // always-present _deviceId._ProductClass (HG6045F3 / HG6145F3 / HG6145D2).
            'model' => CpeModelMap::normalizeModel($this->asString($this->firstValue($device, [
                'Device.DeviceInfo.ModelName',
                'Device.DeviceInfo.ProductClass',
                'InternetGatewayDevice.DeviceInfo.ModelName',
                '_deviceId._ProductClass',
            ]))),
            'software_version' => $this->firstValue($device, [
                'Device.DeviceInfo.SoftwareVersion',
                'InternetGatewayDevice.DeviceInfo.SoftwareVersion',
            ]),
            'hardware_version' => $this->firstValue($device, [
                'Device.DeviceInfo.HardwareVersion',
                'InternetGatewayDevice.DeviceInfo.HardwareVersion',
            ]),
            'ip_address' => $pppoeIp,
            'ip_tr069' => $ipTr069,
            'mac_address' => $this->firstValue($device, [
                'VirtualParameters.pppMac',
                'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.MACAddress',
                'InternetGatewayDevice.LANDevice.1.LANHostConfigManagement.MACAddress',
                'Device.Ethernet.Interface.1.MACAddress',
            ]),
            'ssid' => $this->firstValue($device, [
                'VirtualParameters.wifiSsid',
                'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID',
                'Device.WiFi.SSID.1.SSID',
            ]),
            'ssid_5g' => $this->firstValue($device, [
                'VirtualParameters.wifiSsid5g',
                'InternetGatewayDevice.LANDevice.1.WLANConfiguration.9.SSID',
                'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.SSID',
            ]),
            'tags' => $tags,
            'pelanggan' => $pelanggan ? trim((string) $pelanggan) : null,
            'genieacs_customer_name' => $genieacsCustomerName,
            'pppoe_user' => $pppoeUser ? trim((string) $pppoeUser) : null,
            'wan_status' => $wanStatus,
            'uptime_seconds' => $this->toInt($this->firstValue($device, [
                'Device.DeviceInfo.UpTime',
                'InternetGatewayDevice.DeviceInfo.UpTime',
            ])),
            'uptime_text' => $this->asString($this->value($device, 'VirtualParameters.ontUptime'), 32),
            'pppoe_uptime' => $this->asString($this->value($device, 'VirtualParameters.pppUptime'), 32),
            'pon_mode' => $this->asString($this->value($device, 'VirtualParameters.ponMode'), 32),
            'wifi_clients' => $this->toInt($this->value($device, 'VirtualParameters.wifiClients')),
            'wlan_enabled' => $this->toBool($this->firstValue($device, [
                'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.Enable',
                'Device.WiFi.SSID.1.Enable',
            ])),
            'wlan_channel' => $this->asString($this->firstValue($device, [
                'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.Channel',
                'Device.WiFi.Radio.1.Channel',
            ]), 16),
        ];
    }

    protected function buildCustomerLinkMaps(): array
    {
        $customers = Customer::query()
            ->where(function ($query) {
                $query->whereNotNull('onu_serial')
                    ->orWhereNotNull('pppoe_user')
                    ->orWhereNotNull('name');
            })
            ->get(['id', 'onu_serial', 'pppoe_user', 'name']);

        return [
            'by_serial' => $customers
                ->filter(fn (Customer $customer) => filled($customer->onu_serial))
                ->keyBy(fn (Customer $customer) => strtoupper((string) $customer->onu_serial)),
            'by_pppoe' => $customers
                ->filter(fn (Customer $customer) => filled($customer->pppoe_user))
                ->keyBy('pppoe_user'),
            'by_name' => $customers
                ->filter(fn (Customer $customer) => filled($customer->name))
                ->keyBy('name'),
        ];
    }

    protected function resolveCustomerLink(array $fields, ?array $maps = null): array
    {
        $maps ??= $this->buildCustomerLinkMaps();
        $serial = $fields['serial_number'] ?? null;
        $pppoeUser = $fields['pppoe_user'] ?? null;

        if ($serial) {
            $customer = $maps['by_serial'][strtoupper((string) $serial)] ?? null;
            if ($customer) {
                return ['customer_id' => $customer->id, 'match_source' => 'serial'];
            }
        }

        if ($pppoeUser) {
            $customer = $maps['by_pppoe'][$pppoeUser] ?? null;
            if ($customer) {
                return ['customer_id' => $customer->id, 'match_source' => 'pppoe_tag'];
            }
        }

        $pelanggan = $fields['pelanggan'] ?? $fields['genieacs_customer_name'] ?? null;
        if ($pelanggan) {
            $customer = $maps['by_name'][$pelanggan] ?? $maps['by_pppoe'][$pelanggan] ?? null;
            if ($customer) {
                return ['customer_id' => $customer->id, 'match_source' => 'pelanggan_tag'];
            }
        }

        return ['customer_id' => null, 'match_source' => null];
    }

    /**
     * GenieACS NBI projection = comma-separated paths (NOT JSON object).
     * Keep list tight for list-sync SLA.
     */
    /**
     * Compact optical and 5 GHz SSID fields kept on the eBilling snapshot.
     * Full device documents stay on GenieACS.
     *
     * @return array{rx_power?: mixed, temperature?: mixed, ssid_5g?: mixed}|null
     */
    protected function opticalSnapshot(array $device): ?array
    {
        $keep = static function (mixed $value): bool {
            return $value !== null && $value !== '' && $value !== 'N/A';
        };
        $out = array_filter([
            'rx_power' => $this->firstValue($device, [
                'VirtualParameters.ontRxPower',
                'VirtualParameters.RXPower',
                'InternetGatewayDevice.WANDevice.1.X_FH_GponInterfaceConfig.RXPower',
            ]),
            'temperature' => $this->firstValue($device, [
                'VirtualParameters.ontTemp',
                'VirtualParameters.gettemp',
                'InternetGatewayDevice.WANDevice.1.X_FH_GponInterfaceConfig.TransceiverTemperature',
            ]),
            'ssid_5g' => $this->firstValue($device, [
                'VirtualParameters.wifiSsid5g',
                'InternetGatewayDevice.LANDevice.1.WLANConfiguration.9.SSID',
                'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.SSID',
            ]),
        ], $keep);

        return $out === [] ? null : $out;
    }

    /** Memo (per sync / ingest call): is the RCA-3 CPE layer on and are its columns migrated? */
    protected ?bool $cpeReady = null;

    protected function cpeLayerReady(): bool
    {
        if ($this->cpeReady === null) {
            try {
                $this->cpeReady = OutageSettings::cpeLayerEnabled() && Schema::hasColumn('genieacs_devices', 'wlan_values_at');
            } catch (\Throwable) {
                $this->cpeReady = false;
            }
        }

        return $this->cpeReady;
    }

    /**
     * RCA-3: fresh CPE columns of one NBI document, or null when the layer is off / not migrated / failed.
     * Never throws: an extraction problem must not stop the sync.
     *
     * @return array{columns: array<string,mixed>, cpe: array<string,mixed>}|null
     */
    protected function cpeLayerRow(array $device): ?array
    {
        if (! $this->cpeLayerReady()) {
            return null;
        }
        try {
            return (new CpeSnapshotExtractor)->extract($device);
        } catch (\Throwable) {
            // keep the row shape identical for the bulk upsert: all CPE columns NULL
            return ['columns' => array_fill_keys(CpeSnapshotExtractor::COLUMNS, null), 'cpe' => ['supported' => false, 'raw' => [], 'rx_fresh' => null]];
        }
    }

    /** RCA-3: meta field + per-model WLAN / PPP / ModelName leaves (about +16 paths) so the `_timestamp` of each is returned. */
    protected function cpeProjectionPaths(): array
    {
        if (! $this->cpeLayerReady()) {
            return [];
        }

        return array_merge(['_lastBoot'], (new CpeModelMap)->projectionLeaves());
    }

    protected function syncProjectionCsv(): string
    {
        return implode(',', array_merge($this->syncProjectionBase(), $this->cpeProjectionPaths()));
    }

    /** @return list<string> */
    protected function syncProjectionBase(): array
    {
        return [
            '_id',
            '_lastInform',
            '_registered',
            '_tags',
            '_deviceId._SerialNumber',
            '_deviceId._OUI',
            '_deviceId._ProductClass',
            '_deviceId._Manufacturer',
            'Device.DeviceInfo.Manufacturer',
            'Device.DeviceInfo.ModelName',
            'Device.DeviceInfo.SerialNumber',
            'Device.DeviceInfo.SoftwareVersion',
            'Device.DeviceInfo.HardwareVersion',
            'Device.DeviceInfo.UpTime',
            'InternetGatewayDevice.DeviceInfo.Manufacturer',
            'InternetGatewayDevice.DeviceInfo.ModelName',
            'InternetGatewayDevice.DeviceInfo.SoftwareVersion',
            'InternetGatewayDevice.DeviceInfo.HardwareVersion',
            'InternetGatewayDevice.DeviceInfo.UpTime',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.ExternalIPAddress',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.ConnectionStatus',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.Username',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.ExternalIPAddress',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.ConnectionStatus',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.MACAddress',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.2.WANPPPConnection.1.Username',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.2.WANPPPConnection.1.ExternalIPAddress',
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID',
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.Enable',
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.Channel',
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.2.SSID',
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.SSID',
            'VirtualParameters.RXPower',
            'VirtualParameters.gettemp',
            'VirtualParameters.ontSerial',
            'VirtualParameters.ponMode',
            'VirtualParameters.ontRxPower',
            'VirtualParameters.ontTemp',
            'VirtualParameters.ontUptime',
            'VirtualParameters.pppUptime',
            'VirtualParameters.ipTr069',
            'VirtualParameters.pppIp',
            'VirtualParameters.pppMac',
            'VirtualParameters.pppUsername',
            'VirtualParameters.wifiSsid',
            'VirtualParameters.wifiSsid5g',
            'VirtualParameters.wifiClients',
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.9.SSID',
            'InternetGatewayDevice.WANDevice.1.X_FH_GponInterfaceConfig.RXPower',
            'InternetGatewayDevice.WANDevice.1.X_FH_GponInterfaceConfig.TransceiverTemperature',
            'InternetGatewayDevice.LANDevice.1.LANHostConfigManagement.MACAddress',
            'InternetGatewayDevice.X_FH_Account.CustomerID',
            'VirtualParameters.pelanggan',
            'Device.PPP.Interface.1.Username',
            'Device.IP.Interface.1.IPv4Address.1.IPAddress',
            'Device.WiFi.SSID.1.SSID',
            'Device.WiFi.SSID.1.Enable',
            'Device.WiFi.Radio.1.Channel',
            'Device.Ethernet.Interface.1.MACAddress',
        ];
    }

    protected function detailProjection(): string
    {
        // Single-device detail can request slightly more; still CSV.
        return $this->syncProjectionCsv().',InternetGatewayDevice.WANDevice.1.X_FH_GponInterface.1.Stats.RXPower,InternetGatewayDevice.WANDevice.1.X_FH_GponInterface.1.Stats.TXPower';
    }

    protected function httpClient(?int $timeout = null)
    {
        $timeout ??= (int) config('services.genieacs.sync_timeout', 20);
        $request = Http::timeout($timeout)->connectTimeout(min(8, $timeout))->acceptJson();
        $username = Setting::get('genieacs_username');
        $password = Setting::get('genieacs_password');
        if ($username || $password) {
            $request = $request->withBasicAuth((string) $username, (string) $password);
        }

        return $request;
    }

    protected function parameterSummary(array $parameters): array
    {
        $paths = [
            'Manufacturer' => ['Device.DeviceInfo.Manufacturer', 'InternetGatewayDevice.DeviceInfo.Manufacturer'],
            'Model' => ['Device.DeviceInfo.ModelName', 'InternetGatewayDevice.DeviceInfo.ModelName'],
            'Serial' => ['VirtualParameters.ontSerial', 'Device.DeviceInfo.SerialNumber', '_deviceId._SerialNumber'],
            'Software' => ['Device.DeviceInfo.SoftwareVersion', 'InternetGatewayDevice.DeviceInfo.SoftwareVersion'],
            'Hardware' => ['Device.DeviceInfo.HardwareVersion', 'InternetGatewayDevice.DeviceInfo.HardwareVersion'],
            'IP PPPoE' => [
                'VirtualParameters.pppIp',
                'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.ExternalIPAddress',
            ],
            'IP TR069' => [
                'VirtualParameters.ipTr069',
                'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.ExternalIPAddress',
            ],
            'PPPoE User' => [
                'VirtualParameters.pppUsername',
                'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.Username',
                'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.2.WANPPPConnection.1.Username',
            ],
            'PPPoE Status' => [
                'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.ConnectionStatus',
                'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.ConnectionStatus',
            ],
            'SSID 2.4G' => [
                'VirtualParameters.wifiSsid',
                'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID',
            ],
            'SSID 5G' => [
                'VirtualParameters.wifiSsid5g',
                'InternetGatewayDevice.LANDevice.1.WLANConfiguration.9.SSID',
                'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.SSID',
            ],
            'Klien WiFi' => ['VirtualParameters.wifiClients'],
            'WLAN Enable' => ['InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.Enable'],
            'WLAN Channel' => ['InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.Channel'],
            'PON' => ['VirtualParameters.ponMode'],
            'Optical RX' => [
                'VirtualParameters.ontRxPower',
                'VirtualParameters.RXPower',
                'InternetGatewayDevice.WANDevice.1.X_FH_GponInterfaceConfig.RXPower',
                'InternetGatewayDevice.WANDevice.1.X_FH_GponInterface.1.Stats.RXPower',
                'InternetGatewayDevice.WANDevice.1.X_FH_GponInterface.1.RXPower',
            ],
            'Suhu optik' => [
                'VirtualParameters.ontTemp',
                'VirtualParameters.gettemp',
                'InternetGatewayDevice.WANDevice.1.X_FH_GponInterfaceConfig.TransceiverTemperature',
            ],
            'Optical TX' => [
                'VirtualParameters.TXPower',
                'InternetGatewayDevice.WANDevice.1.X_FH_GponInterface.1.Stats.TXPower',
                'InternetGatewayDevice.WANDevice.1.X_FH_GponInterface.1.TXPower',
            ],
            'Uptime ONT' => ['VirtualParameters.ontUptime'],
            'Uptime PPPoE' => ['VirtualParameters.pppUptime'],
            'MAC PPPoE' => ['VirtualParameters.pppMac'],
            'Pelanggan VP' => ['VirtualParameters.pelanggan'],
        ];

        $summary = [];
        foreach ($paths as $label => $candidates) {
            $value = $this->firstValue($parameters, $candidates);
            if ($value !== null && $value !== '') {
                $summary[] = ['label' => $label, 'value' => is_scalar($value) ? (string) $value : json_encode($value)];
            }
        }

        return $summary;
    }

    protected function task(GenieAcsDevice $device, string $name, array $payload = []): array
    {
        $baseUrl = rtrim((string) Setting::get('genieacs_url', ''), '/');
        if ($baseUrl === '') {
            throw new \RuntimeException('GenieACS URL belum diisi di settings.');
        }

        $response = $this->httpClient()
            ->post($baseUrl.'/devices/'.rawurlencode($device->device_id).'/tasks?connection_request', [
                'name' => $name,
            ] + $payload)
            ->throw()
            ->json();

        return ['queued' => true, 'response' => $response];
    }

    protected function value(array $device, string $path): mixed
    {
        $node = data_get($device, $path);
        if (is_array($node) && array_key_exists('_value', $node)) {
            return $node['_value'];
        }

        if ($this->isGenieAcsMetaNode($node)) {
            return null;
        }

        return is_scalar($node) ? $node : null;
    }

    protected function isGenieAcsMetaNode(mixed $node): bool
    {
        if (! is_array($node)) {
            return false;
        }

        if (array_key_exists('_value', $node)) {
            return false;
        }

        return array_key_exists('_object', $node)
            || array_key_exists('_writable', $node)
            || array_key_exists('_timestamp', $node);
    }

    protected function firstValue(array $device, array $paths): mixed
    {
        foreach ($paths as $path) {
            $value = $this->value($device, $path);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    protected function normalizeTags(mixed $tags): array
    {
        if (! is_array($tags)) {
            return [];
        }

        return array_values(array_filter(array_map(function ($tag) {
            if (is_array($tag)) {
                $tag = $tag['value'] ?? $tag['label'] ?? $tag['name'] ?? null;
            } elseif (is_object($tag)) {
                $tag = $tag->value ?? $tag->label ?? $tag->name ?? null;
            }

            $tag = trim((string) ($tag ?? ''));

            return $tag !== '' ? $tag : null;
        }, $tags)));
    }

    protected function guessPppoeFromTags(array $tags): ?string
    {
        foreach ($tags as $tag) {
            if (preg_match('/^[a-z0-9][a-z0-9._-]{2,}$/i', $tag) && ! str_contains(strtolower($tag), ' ')) {
                return $tag;
            }
        }

        return null;
    }

    protected function guessCustomerNameFromTags(array $tags): ?string
    {
        return $tags[0] ?? null;
    }

    protected function isFiberhome(GenieAcsDevice $device): bool
    {
        $manufacturer = strtolower((string) $device->manufacturer);
        $serial = strtoupper((string) $device->serial_number);
        $productClass = strtoupper((string) $device->product_class);

        return str_contains($manufacturer, 'fiber')
            || str_starts_with($serial, 'FHTT')
            || str_contains($productClass, 'FH');
    }

    protected function asString(mixed $value, int $maxLength = 128): ?string
    {
        if ($value === null || $value === '' || is_array($value)) {
            return null;
        }

        $string = trim((string) $value);
        if ($string === '') {
            return null;
        }

        return mb_substr($string, 0, $maxLength);
    }

    protected function toInt(mixed $value): ?int
    {
        if ($value === null || $value === '' || is_array($value)) {
            return null;
        }

        return (int) $value;
    }

    protected function toBool(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value)) {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'enabled', 'up', 'yes'], true);
    }
}
