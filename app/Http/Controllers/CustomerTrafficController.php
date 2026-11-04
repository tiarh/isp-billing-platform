<?php

namespace App\Http\Controllers;

use App\Exceptions\CustomerTrafficChunkConflict;
use App\Models\Area;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Router;
use App\Models\Setting;
use App\Services\CustomerTrafficIngestService;
use App\Services\MonitoringAdmissionGate;
use App\Support\AreaScope;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;

class CustomerTrafficController extends Controller
{
    private const ACTIVE_KEY = 'skynet:traffic:current:active';

    private const SUMMARY_KEY = 'skynet:traffic:current:summary';

    private const CURRENT_PREFIX = 'skynet:traffic:current:';

    public function current(Request $request, CustomerTrafficIngestService $ingest, MonitoringAdmissionGate $gate): JsonResponse
    {
        $payload = $this->authenticatedPayload($request);
        $this->ensureEnabled();
        $this->ensureGate($gate);

        try {
            return $this->ingestResponse($ingest->ingestCurrent($payload));
        } catch (\InvalidArgumentException $exception) {
            return response()->json([
                'status' => 'invalid_payload',
                'message' => $exception->getMessage(),
            ], 422);
        } catch (CustomerTrafficChunkConflict $exception) {
            return response()->json([
                'status' => 'chunk_conflict',
                'message' => $exception->getMessage(),
            ], 409)->header('Retry-After', '1');
        }
    }

    public function daily(Request $request, CustomerTrafficIngestService $ingest, MonitoringAdmissionGate $gate): JsonResponse
    {
        $payload = $this->authenticatedPayload($request);
        $this->ensureEnabled();
        $this->ensureGate($gate);
        $payload['_body_hash'] = $this->dailyBodyHash($payload);

        try {
            return $this->ingestResponse($ingest->ingestDaily($payload));
        } catch (\InvalidArgumentException $exception) {
            return response()->json([
                'status' => 'invalid_payload',
                'message' => $exception->getMessage(),
            ], 422);
        } catch (CustomerTrafficChunkConflict $exception) {
            return response()->json([
                'status' => 'chunk_conflict',
                'message' => $exception->getMessage(),
            ], 409)->header('Retry-After', '1');
        }
    }

    public function index(Request $request, MonitoringAdmissionGate $gate): JsonResponse
    {
        $this->ensureEnabled();
        // Read-only bandwidth view: exempt from host-load gate (operator hold +
        // DB check still apply). Using the strict traffic_export gate here made
        // the panel show "paused/stale" under normal load while data was present.
        $admissionAllowed = $gate->allowsTrafficRead();
        $admission = [
            'allowed' => $admissionAllowed,
            'reason_codes' => $admissionAllowed ? [] : ['operator_hold_or_db_unavailable'],
            'checked_at' => now('Asia/Jakarta')->toIso8601String(),
        ];
        if (! Schema::hasTable('customer_traffic_current') || ! Schema::hasTable('customer_traffic_daily')) {
            return response()->json([
                'status' => 'setup_required',
                'message' => 'Tabel trafik belum diaktifkan.',
            ], 503);
        }

        $perPage = min(100, max(1, (int) $request->integer('per_page', 50)));
        $query = Customer::query();
        AreaScope::applyToCustomers($query, $request->user());
        $source = (string) $request->input('source', '');
        $query
            ->with([
                'area:id,name',
                'router:id,name',
                'package:id,name',
                'genieAcsDevice:id,customer_id,last_inform_at,ip_address,ssid,uptime_seconds,wlan_channel',
            ])
            ->whereIn('status', ['active', 'isolated'])
            ->whereNotNull('pppoe_user')
            ->where('pppoe_user', '<>', '')
            ->when($request->filled('area_id'), fn ($q) => $q->where('area_id', (int) $request->input('area_id')))
            ->when($request->filled('router_id'), fn ($q) => $q->where('router_id', (int) $request->input('router_id')))
            ->when($request->filled('package_id'), fn ($q) => $q->where('package_id', (int) $request->input('package_id')))
            ->when($request->filled('status') && $request->input('status') !== 'all', fn ($q) => $q->where('status', (string) $request->input('status')))
            ->when($request->filled('profile'), fn ($q) => $q->where('mikrotik_profile', (string) $request->input('profile')))
            ->when(in_array($source, ['mikrotik_pppoe', 'api_poll'], true), function ($q) use ($source): void {
                $table = $source === 'api_poll' ? 'customer_traffic_daily' : 'customer_traffic_current';
                $q->whereExists(fn ($sub) => $sub
                    ->from($table)
                    ->whereColumn($table.'.customer_id', 'customers.id')
                    ->where('source', $source));
            })
            ->when(in_array($request->input('cpe'), ['linked', 'fresh', 'unlinked'], true), function ($q) use ($request): void {
                $capability = (string) $request->input('cpe');
                if ($capability === 'linked') {
                    $q->whereHas('genieAcsDevice');
                } elseif ($capability === 'unlinked') {
                    $q->whereDoesntHave('genieAcsDevice');
                } else {
                    $q->whereHas('genieAcsDevice', fn ($device) => $device
                        ->whereNotNull('last_inform_at')
                        ->where('last_inform_at', '>=', now()->subMinutes((int) config('services.genieacs.online_minutes', 30))));
                }
            })
            ->when($request->filled('search'), function ($q) use ($request): void {
                $search = '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $request->input('search')).'%';
                $q->where(function ($inner) use ($search): void {
                    $inner->where('name', 'like', $search)
                        ->orWhere('code', 'like', $search)
                        ->orWhere('pppoe_user', 'like', $search);
                });
            })
            ->orderBy('id');

        $page = $query->paginate($perPage)->withQueryString();
        $customers = collect($page->items());
        $ids = $customers->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $current = $this->currentRows($ids);
        $daily = $this->dailyRows($ids);
        $series = $this->seriesRows($ids, $customers->pluck('router_id', 'id')->all());
        $nowWib = now('Asia/Jakarta');
        $monthStart = $nowWib->copy()->startOfMonth()->toDateString();
        $todayWib = $nowWib->toDateString();

        $rows = $customers->map(function (Customer $customer) use ($current, $daily, $series, $admissionAllowed, $monthStart, $todayWib): array {
            $traffic = $current[(int) $customer->id] ?? null;
            $dailyRows = $daily[(int) $customer->id] ?? [];
            $today = $dailyRows[$todayWib] ?? null;
            $last7 = $this->sumDaily(array_slice($dailyRows, 0, 7, true));
            $last30 = $this->sumDaily($dailyRows);
            // Calendar month-to-date (1st..today, WIB) — the ISP-facing number.
            $monthRows = array_filter(
                $dailyRows,
                fn ($date): bool => (string) $date >= $monthStart,
                ARRAY_FILTER_USE_KEY,
            );
            $thisMonth = $this->sumDaily($monthRows);
            $timeline = collect($dailyRows)->map(function (array $dailyRow, string|int $date): array {
                return array_merge(['traffic_date_wib' => (string) $date], $this->serializeDaily($dailyRow));
            })->values()->all();
            $sampledAt = $traffic['sampled_at'] ?? null;
            $freshnessSeconds = null;
            if (is_string($sampledAt) && $sampledAt !== '') {
                try {
                    $freshnessSeconds = max(0, now('UTC')->diffInSeconds(CarbonImmutable::parse($sampledAt, 'UTC')));
                } catch (\Throwable) {
                    $sampledAt = null;
                }
            }
            $fresh = $admissionAllowed
                && $freshnessSeconds !== null
                && $freshnessSeconds <= (int) config('services.monitoring.traffic.freshness_seconds', 180);

            return [
                'id' => (int) $customer->id,
                'code' => $customer->code,
                'name' => $customer->name,
                'pppoe_user' => $customer->pppoe_user,
                'status' => $customer->status,
                'area' => $customer->area?->name,
                'router' => $customer->router?->name,
                'package' => $customer->package?->name,
                'source' => (string) ($traffic['source'] ?? 'mikrotik_pppoe'),
                'current' => [
                    'online' => (bool) ($traffic['online'] ?? false),
                    'download_bps' => (int) ($traffic['download_bps'] ?? 0),
                    'upload_bps' => (int) ($traffic['upload_bps'] ?? 0),
                    'quality' => $fresh ? ($traffic['quality'] ?? 'stale') : 'stale',
                    'sampled_at' => $sampledAt,
                    'fresh' => $fresh,
                    'freshness_seconds' => $freshnessSeconds,
                ],
                'today' => $today ? $this->serializeDaily($today) : null,
                'last_7_days' => $last7,
                'last_30_days' => $last30,
                'this_month' => $thisMonth,
                'series_24h' => $series[(int) $customer->id] ?? [],
                'daily_timeline' => $timeline,
                'cpe' => $customer->genieAcsDevice ? [
                    'last_inform_at' => $customer->genieAcsDevice->last_inform_at?->toIso8601String(),
                    'wan_ip' => $customer->genieAcsDevice->ip_address,
                    'ssid' => $customer->genieAcsDevice->ssid,
                    'uptime_seconds' => $customer->genieAcsDevice->uptime_seconds,
                    'channel' => $customer->genieAcsDevice->wlan_channel,
                    'optical_rx_dbm' => $customer->onu_rx_power_dbm,
                ] : null,
            ];
        })->values()->all();

        $active = $this->redisRead(self::ACTIVE_KEY);
        $summary = $this->redisJson(self::SUMMARY_KEY);
        $mapped = collect($rows)->filter(fn (array $row): bool => $row['current']['sampled_at'] !== null)->count();

        return response()->json([
            'status' => 'ok',
            'source' => [
                'current' => $active ? 'redis_102' : 'mysql_fallback_102',
                'collector' => (string) config('services.monitoring.traffic.collector_label', 'mikrotik_107'),
                'collector_display' => (string) config('services.monitoring.traffic.collector_display', 'VM107 / MikroTik'),
                'cpe' => $this->cpeSourceLabel(),
                'active_cycle_id' => $active,
                'summary' => $summary,
            ],
            'admission' => [
                'status' => $admissionAllowed ? 'ready' : 'paused',
                'reason_codes' => $admission['reason_codes'] ?? [],
                'checked_at' => $admission['checked_at'] ?? now('Asia/Jakarta')->toIso8601String(),
            ],
            'period' => [
                'month_start' => $monthStart,
                'today' => $todayWib,
                'month_label' => $nowWib->locale('id')->translatedFormat('F Y'),
            ],
            'rows' => $rows,
            'pagination' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
            'coverage' => [
                'page_mapped' => $mapped,
                'page_total' => count($rows),
                'ratio' => count($rows) > 0 ? round($mapped / count($rows), 4) : 0,
            ],
            'generated_at' => now('Asia/Jakarta')->toIso8601String(),
        ]);
    }

    public function options(): JsonResponse
    {
        $this->ensureEnabled();

        $areas = Area::query();
        AreaScope::applyToAreas($areas, request()->user());

        $routers = Router::query()->where('is_active', true);
        if (request()->user()?->hasAreaScope()) {
            $routers->whereHas('customers', fn ($customer) => AreaScope::applyToCustomers($customer, request()->user()));
        }

        $profileCustomers = Customer::query()
            ->whereIn('status', ['active', 'isolated'])
            ->whereNotNull('mikrotik_profile')
            ->where('mikrotik_profile', '<>', '');
        AreaScope::applyToCustomers($profileCustomers, request()->user());

        $packages = Package::query()->where('is_active', true)
            ->whereHas('customers', function ($customer): void {
                $customer->whereIn('status', ['active', 'isolated']);
                AreaScope::applyToCustomers($customer, request()->user());
            });

        return response()->json([
            'areas' => $areas->orderBy('name')->get(['id', 'name']),
            'routers' => $routers->orderBy('name')->get(['id', 'name']),
            'profiles' => $profileCustomers
                ->distinct()
                ->orderBy('mikrotik_profile')
                ->pluck('mikrotik_profile')
                ->values(),
            'packages' => $packages->orderBy('name')->get(['id', 'name']),
        ]);
    }

    private function ensureEnabled(): void
    {
        abort_unless((bool) config('services.monitoring.traffic.enabled', false), 503, 'Monitoring trafik belum diaktifkan.');
        abort_unless((string) config('services.monitoring.traffic.hash_secret', '') !== '', 503, 'Hash key trafik belum dikonfigurasi.');
        abort_unless((string) config('services.monitoring.traffic.hmac_secret', '') !== '', 503, 'HMAC trafik belum dikonfigurasi.');
        abort_unless((array) config('services.monitoring.traffic.source_allowlist', []) !== [], 503, 'Allowlist collector trafik belum dikonfigurasi.');
    }

    private function cpeSourceLabel(): string
    {
        $url = (string) Setting::get('genieacs_url', '');
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));

        return in_array($host, (array) config('site.cpe_source_hosts'), true)
            ? 'genieacs_107'
            : ($host !== '' ? 'genieacs_projection' : 'genieacs_unconfigured');
    }

    private function ensureGate(MonitoringAdmissionGate $gate): void
    {
        // Traffic ingest is a bounded, authenticated write and the primary
        // bandwidth data plane. It is exempt from the host-load gate (operator
        // hold + DB availability still apply) so collector pushes are not
        // dropped with normalized_load_high.
        if ($gate->allowsTrafficIngest()) {
            return;
        }

        abort(response()->json([
            'status' => 'paused',
            'reason_codes' => ['operator_hold_or_db_unavailable'],
            'retry_after' => 60,
        ], 503)->header('Retry-After', '60'));
    }

    /**
     * Keep retry idempotency stable when a collector allocates a new batch id
     * after an HTTP timeout. The chunk content/date remain part of the hash.
     *
     * @param  array<string, mixed>  $payload
     */
    private function dailyBodyHash(array $payload): string
    {
        unset($payload['batch_id'], $payload['_body_hash']);

        return hash('sha256', $this->canonicalJson($payload));
    }

    private function canonicalJson(mixed $value): string
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return '['.implode(',', array_map(fn (mixed $item): string => $this->canonicalJson($item), $value)).']';
            }

            ksort($value);
            $parts = [];
            foreach ($value as $key => $item) {
                $parts[] = json_encode((string) $key, JSON_THROW_ON_ERROR).':'.$this->canonicalJson($item);
            }

            return '{'.implode(',', $parts).'}';
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function authenticatedPayload(Request $request): array
    {
        $body = (string) $request->getContent();
        $maxBytes = (int) config('services.monitoring.traffic.max_request_bytes', 4194304);
        if (strlen($body) > $maxBytes) {
            abort(413, 'Payload terlalu besar.');
        }

        $secret = (string) config('services.monitoring.traffic.hmac_secret', '');
        $timestamp = trim((string) $request->header('X-Monitoring-Timestamp', ''));
        $nonce = trim((string) $request->header('X-Monitoring-Nonce', ''));
        $signature = strtolower(trim((string) $request->header('X-Monitoring-Signature', '')));
        $collector = trim((string) $request->header('X-Monitoring-Collector', ''));
        $allowedSources = (array) config('services.monitoring.traffic.source_allowlist', []);

        if ($secret === '' || $timestamp === '' || $nonce === '' || $signature === '' || $collector === '') {
            abort(401, 'HMAC header tidak lengkap.');
        }
        if (! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 60) {
            abort(401, 'Timestamp HMAC kedaluwarsa.');
        }
        if (! preg_match('/^[A-Za-z0-9_.:-]{8,128}$/', $nonce) || ! preg_match('/^[a-f0-9]{64}$/', $signature)) {
            abort(401, 'Nonce/signature tidak valid.');
        }
        $allowedCollectors = (array) config('services.monitoring.traffic.collector_allowlist', []);
        if ($allowedCollectors === [] || ! in_array($collector, $allowedCollectors, true)) {
            abort(403, 'Collector trafik tidak diizinkan.');
        }
        if ($allowedSources === []) {
            abort(503, 'Allowlist collector trafik belum dikonfigurasi.');
        }
        if (! in_array((string) $request->ip(), $allowedSources, true)) {
            abort(403, 'Source collector tidak diizinkan.');
        }

        $expected = hash_hmac('sha256', $timestamp."\n".$nonce."\n".$body, $secret);
        if (! hash_equals($expected, $signature)) {
            abort(401, 'Signature HMAC tidak cocok.');
        }
        if (! Cache::add('monitoring.traffic.nonce.'.$nonce, true, now()->addMinutes(2))) {
            abort(409, 'Nonce HMAC sudah dipakai.');
        }

        $payload = json_decode($body, true);
        if (! is_array($payload)) {
            abort(422, 'Payload JSON tidak valid.');
        }
        $payload['collector_id'] = $collector;

        return $payload;
    }

    /** @param array<string, mixed> $result */
    private function ingestResponse(array $result): JsonResponse
    {
        $status = in_array($result['status'] ?? '', ['checksum_mismatch', 'row_count_mismatch', 'cache_budget_exceeded'], true)
            ? 422
            : (($result['complete'] ?? false) || ($result['status'] ?? '') === 'committed' || ($result['status'] ?? '') === 'duplicate_ack' ? 200 : 202);

        return response()->json($result, $status);
    }

    /** @param list<int> $ids @return array<int, array<string, mixed>> */
    private function currentRows(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $fallback = DB::table('customer_traffic_current')->whereIn('customer_id', $ids)->get()->keyBy('customer_id')->map(fn ($row): array => (array) $row)->all();
        try {
            $active = $this->redisRead(self::ACTIVE_KEY);
            if ($active === null) {
                return $fallback;
            }
            $summary = $this->redisJson(self::SUMMARY_KEY);
            $sampledAt = is_string($summary['sampled_at_utc'] ?? null) ? $summary['sampled_at_utc'] : null;
            $values = Redis::connection('cache')->hmget(self::CURRENT_PREFIX.$active, array_map('strval', $ids));
            foreach ($ids as $index => $id) {
                $value = $values[$index] ?? null;
                if (is_string($value) && $value !== '') {
                    $decoded = json_decode($value, true);
                    if (is_array($decoded)) {
                        $fallback[$id] = $decoded;
                    }
                } elseif ($value === null || $value === false) {
                    // A complete active snapshot contains online sessions only.
                    // Missing from that snapshot means offline at the snapshot
                    // sample, while a missing active pointer still uses the
                    // database last-good row above and remains stale.
                    $fallback[$id] = [
                        'online' => false,
                        'download_bps' => 0,
                        'upload_bps' => 0,
                        'quality' => 'offline',
                        'sampled_at' => $sampledAt,
                    ];
                }
            }
        } catch (\Throwable) {
            // The DB current table is the bounded last-good fallback.
        }

        return $fallback;
    }

    /** @param list<int> $ids @return array<int, array<string, array<string, mixed>>> */
    private function dailyRows(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        // Cover both the rolling 30-day window and the current WIB calendar
        // month (month-to-date can be up to 31 days).
        $rollingStart = now('Asia/Jakarta')->subDays(29)->toDateString();
        $monthStart = now('Asia/Jakarta')->startOfMonth()->toDateString();
        $start = min($rollingStart, $monthStart);

        return DB::table('customer_traffic_daily')
            ->whereIn('customer_id', $ids)
            ->where('traffic_date_wib', '>=', $start)
            ->orderByDesc('traffic_date_wib')
            ->get()
            ->groupBy('customer_id')
            ->map(fn ($rows): array => $rows->keyBy('traffic_date_wib')->map(fn ($row): array => (array) $row)->all())
            ->all();
    }

    /**
     * Read the bounded 24h Redis ring buffer per customer (oldest first).
     *
     * @param  list<int>  $ids
     * @param  array<int|string, int|string|null>  $routerById
     * @return array<int, list<array{t: string, d: int, u: int}>>
     */
    private function seriesRows(array $ids, array $routerById): array
    {
        if ($ids === []) {
            return [];
        }

        $out = [];
        try {
            $redis = Redis::connection('cache');
            foreach ($ids as $id) {
                $rid = (int) ($routerById[$id] ?? 0);
                $raw = $redis->lrange('traffic:series:'.$id.':'.$rid, 0, 95);
                $points = [];
                foreach (array_reverse(is_array($raw) ? $raw : []) as $item) {
                    $decoded = json_decode((string) $item, true);
                    if (is_array($decoded) && isset($decoded['t'])) {
                        $points[] = $decoded;
                    }
                }
                if ($points !== []) {
                    $out[(int) $id] = $points;
                }
            }
        } catch (\Throwable) {
            // Series is optional; fall back to no sparkline.
        }

        return $out;
    }

    /** @param array<string, mixed> $rows @return array<string, string> */
    private function sumDaily(array $rows): array
    {
        return [
            'download_bytes' => (string) array_sum(array_map(fn ($row): int => (int) ($row['download_bytes'] ?? 0), $rows)),
            'upload_bytes' => (string) array_sum(array_map(fn ($row): int => (int) ($row['upload_bytes'] ?? 0), $rows)),
            'total_bytes' => (string) array_sum(array_map(fn ($row): int => (int) ($row['download_bytes'] ?? 0) + (int) ($row['upload_bytes'] ?? 0), $rows)),
            'sample_count' => (string) array_sum(array_map(fn ($row): int => (int) ($row['sample_count'] ?? 0), $rows)),
            'expected_sample_count' => (string) array_sum(array_map(fn ($row): int => (int) ($row['expected_sample_count'] ?? 0), $rows)),
            'reset_count' => (string) array_sum(array_map(fn ($row): int => (int) ($row['reset_count'] ?? 0), $rows)),
            'gap_count' => (string) array_sum(array_map(fn ($row): int => (int) ($row['gap_count'] ?? 0), $rows)),
            'coverage_ratio' => (string) round(min(1, array_sum(array_map(fn ($row): int => (int) ($row['sample_count'] ?? 0), $rows)) / max(1, array_sum(array_map(fn ($row): int => (int) ($row['expected_sample_count'] ?? 0), $rows)))), 4),
        ];
    }

    /** @param array<string, mixed> $row @return array<string, string> */
    private function serializeDaily(array $row): array
    {
        return [
            'download_bytes' => (string) ($row['download_bytes'] ?? 0),
            'upload_bytes' => (string) ($row['upload_bytes'] ?? 0),
            'total_bytes' => (string) ((int) ($row['download_bytes'] ?? 0) + (int) ($row['upload_bytes'] ?? 0)),
            'sample_count' => (string) ($row['sample_count'] ?? 0),
            'expected_sample_count' => (string) ($row['expected_sample_count'] ?? 0),
            'reset_count' => (string) ($row['reset_count'] ?? 0),
            'gap_count' => (string) ($row['gap_count'] ?? 0),
            'coverage_ratio' => (string) ($row['coverage_ratio'] ?? 0),
            'quality' => (string) ($row['quality'] ?? 'unknown'),
            'first_sampled_at' => (string) ($row['first_sampled_at'] ?? ''),
            'last_sampled_at' => (string) ($row['last_sampled_at'] ?? ''),
        ];
    }

    private function redisRead(string $key): ?string
    {
        try {
            $value = Redis::connection('cache')->get($key);

            return is_string($value) && $value !== '' ? $value : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed>|null */
    private function redisJson(string $key): ?array
    {
        $value = $this->redisRead($key);
        if ($value === null) {
            return null;
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }
}
