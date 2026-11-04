<?php

namespace App\Services\Grafana;

use App\Models\Setting;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Server-side Grafana client. Runs whitelisted PromQL templates against the
 * Grafana datasource proxy (POST /api/ds/query). The service-account token is
 * read from the encrypted Setting and never leaves this class: results carry
 * only series data, and logs carry only template names / status codes.
 *
 * Result contract (array, safe to hand to the frontend):
 *   status: ok | stale | unavailable | setup_required
 *   fetched_at: ISO-8601 of the data (null when none)
 *   series: list of {labels: array<string,string>, name: ?string, points: list<[ts_ms, float|null]>}
 *   reason: short human text for non-ok states
 */
class GrafanaClient
{
    public const SETTING_URL = 'grafana_url';

    public const SETTING_TOKEN = 'grafana_service_account_token';

    public const SETTING_DATASOURCE_UID = 'grafana_datasource_uid';

    public const CONNECT_TIMEOUT = 2;

    public const TIMEOUT = 4;

    public const BREAKER_THRESHOLD = 3;

    public const BREAKER_OPEN_SECONDS = 60;

    private const BREAKER_FAILURE_WINDOW = 120;

    private const LAST_GOOD_TTL = 900;

    private const LOCK_SECONDS = 10;

    private const LOCK_WAIT_SECONDS = 2;

    private const KEY_FAILURES = 'grafana.cb.failures';

    private const KEY_OPEN = 'grafana.cb.open';

    private const LABEL_CACHE_TTL = 600;

    private const LABEL_MAX_VALUES = 500;

    /** Upper bound of points per queryRangeBetween() call (a few hours at 1 min step). */
    public const MAX_RANGE_POINTS = 720;

    /**
     * Whitelisted label-value sources for the settings dropdowns.
     *
     * @var array<string, array{label: string, match: string}>
     */
    public const LABEL_SOURCES = [
        'routerboard_name' => ['label' => 'routerboard_name', 'match' => 'mktxp_system_identity_info'],
        'router' => ['label' => 'router', 'match' => 'skynet_scrape_up'],
        'upstream' => ['label' => 'upstream', 'match' => 'skynet:upstream_rx_mbps:rate5m'],
        'edge_interface' => ['label' => 'interface', 'match' => 'skynet_iface_rx_bytes{router="Jakarta-Edge"}'],
    ];

    /** Dashboards verified to exist in the Skynet folder (plan D2). */
    private const KNOWN_DASHBOARDS = [
        'skynet-mikrotik-overview' => 'Skynet MikroTik - Overview',
        'skynet-jakarta-edge' => 'Skynet - Jakarta Edge (VLAN/Bonding)',
        'skynet-per-router' => 'Skynet - Per Router (MikroTik)',
        'skynet-traffic' => 'Skynet - Traffic & Pelanggan',
    ];

    /**
     * Effective connection settings. Setting > config/env > legacy
     * storage/app/grafana.env (kept until it is migrated into Settings).
     *
     * @return array{url: string, token: string, datasource_uid: string}
     */
    public function connection(): array
    {
        $settingUrl = trim((string) Setting::get(self::SETTING_URL, ''));
        $url = $settingUrl;
        $token = Setting::secret(self::SETTING_TOKEN);
        $uid = trim((string) Setting::get(self::SETTING_DATASOURCE_UID, ''));

        if ($url === '') {
            $url = trim((string) config('services.grafana.url', ''));
        }
        if ($token === '') {
            $token = trim((string) config('services.grafana.token', ''));
        }
        if ($token === '') {
            $legacy = $this->legacyEnvFile();
            $token = trim((string) ($legacy['GRAFANA_TOKEN'] ?? ''));
            if ($token !== '' && ! empty($legacy['GRAFANA_URL']) && $settingUrl === '') {
                $url = trim((string) $legacy['GRAFANA_URL']);
            }
        }
        if ($uid === '') {
            $uid = trim((string) config('services.grafana.datasource_uid', 'prom-main')) ?: 'prom-main';
        }

        $url = rtrim($url, '/');
        if (! preg_match('#^https?://#i', $url) || ! $this->hostAllowed($url)) {
            $url = '';
        }

        return ['url' => $url, 'token' => $token, 'datasource_uid' => $uid];
    }

    /** SSRF guard: the bearer token is only ever sent to an allow-listed host. */
    private function hostAllowed(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return false;
        }
        $allowed = config('services.grafana.allowed_hosts', []);
        if (is_string($allowed)) {
            $allowed = explode(',', $allowed);
        }
        $allowed = array_map(fn ($h) => strtolower(trim((string) $h)), (array) $allowed);

        return in_array($host, $allowed, true);
    }

    public function isConfigured(): bool
    {
        $c = $this->connection();

        return $c['url'] !== '' && $c['token'] !== '';
    }

    /** Base URL for "Buka di Grafana" links (no secret). */
    public function baseUrl(): ?string
    {
        $url = $this->connection()['url'];

        return $url !== '' ? $url : null;
    }

    public function breakerIsOpen(): bool
    {
        return (bool) Cache::get(self::KEY_OPEN, false);
    }

    /**
     * Run a whitelisted template.
     *
     * @param  array<string, string|int>  $params
     * @return array{status: string, fetched_at: ?string, series: array, reason: ?string}
     *
     * @throws InvalidArgumentException for an unknown template or invalid parameters
     */
    public function query(string $template, array $params = [], string $range = '1h'): array
    {
        $resolved = GrafanaQueryTemplates::resolve($template, $params, $range);

        $conn = $this->connection();
        if ($conn['url'] === '' || $conn['token'] === '') {
            return $this->result('setup_required', null, [], 'Grafana belum dikonfigurasi (URL/token).');
        }

        $key = 'grafana.w.'.$template.'.'.sha1(json_encode([$params, $resolved['uses_range'] ? $range : null, $conn['datasource_uid'], $conn['url']]));

        $hit = Cache::get($key);
        if (is_array($hit)) {
            return $hit;
        }

        if ($this->breakerIsOpen()) {
            return $this->fallback($key, 'Grafana sementara tidak dijangkau (circuit breaker terbuka).');
        }

        $lock = Cache::lock($key.'.lock', self::LOCK_SECONDS);
        try {
            $lock->block(self::LOCK_WAIT_SECONDS);
        } catch (LockTimeoutException) {
            $hit = Cache::get($key);

            return is_array($hit) ? $hit : $this->fallback($key, 'Grafana sedang dimuat oleh proses lain.');
        }

        try {
            // Another process may have filled the cache while we waited.
            $hit = Cache::get($key);
            if (is_array($hit)) {
                return $hit;
            }
            if ($this->breakerIsOpen()) {
                return $this->fallback($key, 'Grafana sementara tidak dijangkau (circuit breaker terbuka).');
            }

            try {
                $series = $this->fetch($conn, $resolved);
            } catch (Throwable $e) {
                $this->recordFailure();
                Log::warning('[Grafana] query failed', [
                    'template' => $template,
                    'error' => $this->safeErrorLabel($e),
                ]);

                return $this->fallback($key, 'Grafana tidak dapat dijangkau atau menolak query.');
            }

            $this->recordSuccess();
            $result = $this->result('ok', now()->toIso8601String(), $series, null);
            Cache::put($key, $result, $resolved['ttl']);
            Cache::put($key.'.last_good', $result, self::LAST_GOOD_TTL);

            return $result;
        } finally {
            $lock->release();
        }
    }

    /**
     * Run a whitelisted INSTANT template evaluated at a given moment. Meant for
     * off-request batch jobs (daily usage rollup): never reads or writes the
     * result cache and never serves last-good data, but respects and feeds the
     * circuit breaker, and is single-flight per (template, params, instant).
     * A concurrent identical call returns `unavailable` instead of queueing.
     *
     * @param  array<string, string|int>  $params
     * @return array{status: string, fetched_at: ?string, series: array, reason: ?string}
     *
     * @throws InvalidArgumentException for an unknown template, invalid parameters or a range-mode template
     */
    public function queryAt(string $template, array $params, CarbonInterface $at, int $timeout = 15): array
    {
        $resolved = GrafanaQueryTemplates::resolve($template, $params);
        if ($resolved['mode'] !== 'instant') {
            throw new InvalidArgumentException('queryAt supports instant templates only.');
        }
        $timeout = max(1, min(60, $timeout));

        $conn = $this->connection();
        if ($conn['url'] === '' || $conn['token'] === '') {
            return $this->result('setup_required', null, [], 'Grafana belum dikonfigurasi (URL/token).');
        }
        if ($this->breakerIsOpen()) {
            return $this->result('unavailable', null, [], 'Grafana sementara tidak dijangkau (circuit breaker terbuka).');
        }

        $key = 'grafana.at.'.$template.'.'.sha1(json_encode([$params, $at->getTimestamp(), $conn['datasource_uid'], $conn['url']]));
        $lock = Cache::lock($key.'.lock', $timeout * 3 + 10);
        if (! $lock->get()) {
            return $this->result('unavailable', null, [], 'Query identik sedang berjalan di proses lain.');
        }

        try {
            if ($this->breakerIsOpen()) {
                return $this->result('unavailable', null, [], 'Grafana sementara tidak dijangkau (circuit breaker terbuka).');
            }

            try {
                // Single attempt: a 5xx after a heavy query must not resend it.
                $series = $this->fetch($conn, $resolved, $at, $timeout, false);
            } catch (Throwable $e) {
                $this->recordFailure();
                Log::warning('[Grafana] queryAt failed', [
                    'template' => $template,
                    'error' => $this->safeErrorLabel($e),
                ]);

                return $this->result('unavailable', null, [], 'Grafana tidak dapat dijangkau atau menolak query.');
            }

            $this->recordSuccess();

            return $this->result('ok', $at->toIso8601String(), $series, null);
        } finally {
            $lock->release();
        }
    }

    /**
     * Run a whitelisted RANGE template over an explicit window (query_range with from/to/step).
     * Meant for off-request batch jobs (presence archive): never reads or writes the result cache,
     * respects and feeds the circuit breaker, single attempt (no resend of a heavy query), and is
     * single-flight per (template, params, window). A malformed answer is `unavailable`, never an exception.
     * The window is bounded to MAX_RANGE_POINTS points so one call cannot ask Prometheus for a huge matrix.
     *
     * @param  array<string, string|int>  $params
     * @return array{status: string, fetched_at: ?string, series: array, reason: ?string}
     *
     * @throws InvalidArgumentException for an unknown template, invalid parameters, an instant template or a bad window
     */
    public function queryRangeBetween(string $template, array $params, CarbonInterface $from, CarbonInterface $to, int $step = 60, int $timeout = 30, ?callable $each = null): array
    {
        $resolved = GrafanaQueryTemplates::resolve($template, $params);
        if ($resolved['mode'] !== 'range' || str_contains(GrafanaQueryTemplates::all()[$template]['expr'], '{range}')) {
            throw new InvalidArgumentException('queryRangeBetween supports plain range templates only.');
        }
        $step = max(15, min(3600, $step));
        $spanSeconds = $to->getTimestamp() - $from->getTimestamp();
        if ($spanSeconds <= 0 || intdiv($spanSeconds, $step) + 1 > self::MAX_RANGE_POINTS) {
            throw new InvalidArgumentException('Invalid Grafana range window.');
        }
        $timeout = max(1, min(120, $timeout));

        $conn = $this->connection();
        if ($conn['url'] === '' || $conn['token'] === '') {
            return $this->result('setup_required', null, [], 'Grafana belum dikonfigurasi (URL/token).');
        }
        if ($this->breakerIsOpen()) {
            return $this->result('unavailable', null, [], 'Grafana sementara tidak dijangkau (circuit breaker terbuka).');
        }

        $key = 'grafana.rb.'.$template.'.'.sha1(json_encode([$params, $from->getTimestamp(), $to->getTimestamp(), $step, $conn['datasource_uid'], $conn['url']]));
        $lock = Cache::lock($key.'.lock', $timeout * 3 + 10);
        if (! $lock->get()) {
            return $this->result('unavailable', null, [], 'Query identik sedang berjalan di proses lain.');
        }

        try {
            try {
                $series = $this->fetch($conn, $resolved, $to, $timeout, false, ['from' => $from, 'step' => $step, 'each' => $each]);
            } catch (Throwable $e) {
                $this->recordFailure();
                Log::warning('[Grafana] queryRangeBetween failed', [
                    'template' => $template,
                    'error' => $this->safeErrorLabel($e),
                ]);

                return $this->result('unavailable', null, [], 'Grafana tidak dapat dijangkau atau menolak query.');
            }

            $this->recordSuccess();

            return $this->result('ok', $to->toIso8601String(), $series, null);
        } finally {
            $lock->release();
        }
    }

    /**
     * Live connection test for the settings page: bypasses cache and breaker,
     * never leaks the token or raw provider error.
     *
     * @return array{ok: bool, message: string, series_count: ?int}
     */
    public function testConnection(): array
    {
        $conn = $this->connection();
        if ($conn['url'] === '' || $conn['token'] === '') {
            return ['ok' => false, 'message' => 'URL atau token belum diisi.', 'series_count' => null];
        }

        try {
            $series = $this->fetch($conn, GrafanaQueryTemplates::resolve('health_up'));
        } catch (Throwable $e) {
            Log::warning('[Grafana] connection test failed', ['error' => $this->safeErrorLabel($e)]);

            return ['ok' => false, 'message' => 'Grafana tidak dapat dijangkau atau token/datasource ditolak.', 'series_count' => null];
        }

        return ['ok' => true, 'message' => 'Terhubung.', 'series_count' => count($series)];
    }

    /**
     * Dashboard list for the Monitoring page (same payload shape the page
     * already consumed). Falls back to the verified Skynet dashboards.
     *
     * @return array{enabled: bool, url: ?string, dashboards: array, error: ?string}
     */
    public function dashboards(): array
    {
        $conn = $this->connection();
        if ($conn['url'] === '' || $conn['token'] === '') {
            return ['enabled' => false, 'url' => $conn['url'] ?: null, 'dashboards' => [], 'error' => 'Grafana belum dikonfigurasi (URL/token).'];
        }

        $url = $conn['url'];

        $cacheKey = 'monitoring.grafana.dashboards.'.sha1($url);
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        if ($this->breakerIsOpen()) {
            $result = $this->dashboardsFallback($url);
            Cache::put($cacheKey, $result, now()->addMinutes(10));

            return $result;
        }

        try {
            $dashboards = $this->http($conn)
                ->get($url.'/api/search', ['type' => 'dash-db'])
                ->throw()
                ->collect()
                ->filter(fn ($d) => filled($d['uid'] ?? null))
                ->map(fn ($d) => [
                    'uid' => $d['uid'],
                    'title' => $d['title'] ?? 'Grafana Dashboard',
                    'url' => $url.($d['url'] ?? ('/d/'.$d['uid'])),
                ])
                ->values()
                ->all();
            $this->recordSuccess();
            $result = ['enabled' => true, 'url' => $url, 'dashboards' => $dashboards, 'error' => null];
            Cache::put($cacheKey, $result, now()->addHours(12));

            return $result;
        } catch (Throwable $e) {
            $this->recordFailure();
            Log::warning('[Grafana] dashboard search failed', ['error' => $this->safeErrorLabel($e)]);
            $result = $this->dashboardsFallback($url);
            Cache::put($cacheKey, $result, now()->addMinutes(10));

            return $result;
        }
    }

    /**
     * Distinct label values for a whitelisted source (settings dropdowns).
     * Cached 600s on success; respects and feeds the circuit breaker.
     *
     * @return array{status: string, values: list<string>, reason: ?string}
     *
     * @throws InvalidArgumentException for an unknown source or invalid datasource UID
     */
    public function labelValues(string $source): array
    {
        $spec = self::LABEL_SOURCES[$source] ?? null;
        if ($spec === null) {
            throw new InvalidArgumentException('Unknown label source.');
        }

        $conn = $this->connection();
        if (! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $conn['datasource_uid'])) {
            throw new InvalidArgumentException('Invalid datasource UID.');
        }
        if ($conn['url'] === '' || $conn['token'] === '') {
            return ['status' => 'setup_required', 'values' => [], 'reason' => 'Grafana belum dikonfigurasi (URL/token).'];
        }

        $key = 'grafana.labels.'.$source.'.'.sha1($conn['url'].'|'.$conn['datasource_uid']);
        $hit = Cache::get($key);
        if (is_array($hit)) {
            return ['status' => 'ok', 'values' => $hit, 'reason' => null];
        }

        if ($this->breakerIsOpen()) {
            return ['status' => 'unavailable', 'values' => [], 'reason' => 'Grafana sementara tidak dijangkau (circuit breaker terbuka).'];
        }

        $endpoint = $conn['url'].'/api/datasources/uid/'.$conn['datasource_uid'].'/resources/api/v1/label/'.$spec['label'].'/values';

        try {
            $http = $this->http($conn);
            $response = $http->get($endpoint, ['match[]' => $spec['match']]);
            if ($response->clientError()) {
                // Some Prometheus versions reject match[]; retry once without it.
                $response = $http->get($endpoint);
            }
            if (! $response->successful()) {
                throw new RequestException($response);
            }
            $json = $response->json();
            if (! is_array($json) || ($json['status'] ?? null) !== 'success' || ! is_array($json['data'] ?? null)) {
                throw new RuntimeException('Unexpected label response');
            }
        } catch (Throwable $e) {
            $this->recordFailure();
            Log::warning('[Grafana] label values failed', ['source' => $source, 'error' => $this->safeErrorLabel($e)]);

            return ['status' => 'unavailable', 'values' => [], 'reason' => 'Grafana tidak dapat dijangkau atau menolak permintaan.'];
        }

        $this->recordSuccess();
        $values = array_values(array_unique(array_filter(
            array_map('strval', array_filter($json['data'], 'is_scalar')),
            fn (string $v) => $v !== ''
        )));
        sort($values, SORT_STRING);
        $values = array_slice($values, 0, self::LABEL_MAX_VALUES);
        Cache::put($key, $values, self::LABEL_CACHE_TTL);

        return ['status' => 'ok', 'values' => $values, 'reason' => null];
    }

    private function dashboardsFallback(string $url): array
    {
        $list = [];
        foreach (self::KNOWN_DASHBOARDS as $uid => $title) {
            $list[] = ['uid' => $uid, 'title' => $title, 'url' => $url.'/d/'.$uid.'?orgId=1&from=now-6h&to=now&timezone=browser'];
        }

        return ['enabled' => true, 'url' => $url, 'dashboards' => $list, 'error' => 'API Grafana tidak reachable dari server eBilling.'];
    }

    /**
     * @param  array{url: string, token: string, datasource_uid: string}  $conn
     * @param  array{expr: string, mode: string, ttl: int, range: string, uses_range: bool}  $resolved
     * @return list<array{labels: array, name: ?string, points: array}>
     */
    private function fetch(array $conn, array $resolved, ?CarbonInterface $at = null, int $timeout = self::TIMEOUT, bool $retry = true, ?array $window = null): array
    {
        $nowMs = $at !== null ? $at->getTimestamp() * 1000 : (int) round(microtime(true) * 1000);
        if ($resolved['mode'] === 'range' && $window !== null) {
            // explicit window: $at is the end, $window = ['from' => CarbonInterface, 'step' => seconds]
            $fromMs = $window['from']->getTimestamp() * 1000;
            $intervalMs = $window['step'] * 1000;
            $maxPoints = (int) ceil(($nowMs - $fromMs) / $intervalMs) + 1;
            $isRange = true;
        } elseif ($resolved['mode'] === 'range') {
            $spec = GrafanaQueryTemplates::RANGES[$resolved['range']];
            $fromMs = $nowMs - $spec['seconds'] * 1000;
            $intervalMs = $spec['step'] * 1000;
            $maxPoints = (int) ceil($spec['seconds'] / $spec['step']) + 1;
            $isRange = true;
        } else {
            $fromMs = $nowMs - GrafanaQueryTemplates::INSTANT_LOOKBACK_SECONDS * 1000;
            $intervalMs = 60000;
            $maxPoints = 1;
            $isRange = false;
        }

        $response = $this->http($conn, $timeout, $retry)->post($conn['url'].'/api/ds/query', [
            'queries' => [[
                'refId' => 'A',
                'datasource' => ['type' => 'prometheus', 'uid' => $conn['datasource_uid']],
                'expr' => $resolved['expr'],
                'instant' => ! $isRange,
                'range' => $isRange,
                'intervalMs' => $intervalMs,
                'maxDataPoints' => $maxPoints,
            ]],
            'from' => (string) $fromMs,
            'to' => (string) $nowMs,
        ]);

        if (! $response->successful()) {
            throw new RequestException($response);
        }

        $json = $response->json();
        if (! is_array($json) || ! empty($json['results']['A']['error'])) {
            throw new RuntimeException('Grafana query error');
        }

        if (isset($window['each']) && is_callable($window['each'])) {
            $this->eachSeries($json['results']['A']['frames'] ?? [], $window['each']);

            return [];
        }

        return $this->parseFrames($json['results']['A']['frames'] ?? [], ! $isRange);
    }

    /**
     * Lean walk over the frames: one callback per series with (labels, times_ms, values), no [t, v] pair arrays
     * (a 1100 session router would not fit the 128M scheduler limit otherwise).
     *
     * @param  callable(array, array, array): void  $each
     */
    private function eachSeries(mixed $frames, callable $each): void
    {
        if (! is_array($frames)) {
            throw new RuntimeException('Unexpected Grafana frames');
        }
        foreach ($frames as $frame) {
            $fields = $frame['schema']['fields'] ?? [];
            $values = $frame['data']['values'] ?? [];
            if (! is_array($fields) || ! is_array($values)) {
                throw new RuntimeException('Unexpected Grafana frame');
            }
            $times = is_array($values[0] ?? null) ? $values[0] : [];
            for ($i = 1; $i < count($fields); $i++) {
                $col = $values[$i] ?? [];
                $each(is_array($fields[$i]['labels'] ?? null) ? $fields[$i]['labels'] : [], $times, is_array($col) ? $col : []);
            }
        }
    }

    /**
     * @param  array<int, mixed>  $frames
     * @return list<array{labels: array, name: ?string, points: array}>
     */
    private function parseFrames(array $frames, bool $instant): array
    {
        $series = [];
        foreach ($frames as $frame) {
            $fields = $frame['schema']['fields'] ?? [];
            $values = $frame['data']['values'] ?? [];
            $times = $values[0] ?? [];
            for ($i = 1; $i < count($fields); $i++) {
                $col = $values[$i] ?? [];
                $points = [];
                foreach ($col as $idx => $v) {
                    $points[] = [$times[$idx] ?? null, is_numeric($v) ? (float) $v : null];
                }
                if ($instant && $points !== []) {
                    $points = [end($points)];
                }
                $series[] = [
                    'labels' => is_array($fields[$i]['labels'] ?? null) ? $fields[$i]['labels'] : [],
                    'name' => $fields[$i]['config']['displayNameFromDS'] ?? ($fields[$i]['name'] ?? null),
                    'points' => $points,
                ];
            }
        }

        return $series;
    }

    /** @param array{url: string, token: string, datasource_uid: string} $conn */
    private function http(array $conn, int $timeout = self::TIMEOUT, bool $retry = true): PendingRequest
    {
        $request = Http::withToken($conn['token'])
            ->acceptJson()
            ->connectTimeout(self::CONNECT_TIMEOUT)
            ->timeout($timeout)
            ->withoutRedirecting();

        if (! $retry) {
            return $request;
        }

        return $request->retry(2, 200, function (Throwable $e): bool {
            return $e instanceof RequestException && $e->response->serverError();
        }, throw: false);
    }

    private function recordFailure(): void
    {
        Cache::add(self::KEY_FAILURES, 0, self::BREAKER_FAILURE_WINDOW);
        $failures = (int) Cache::increment(self::KEY_FAILURES);
        if ($failures >= self::BREAKER_THRESHOLD) {
            Cache::put(self::KEY_OPEN, true, self::BREAKER_OPEN_SECONDS);
            Cache::forget(self::KEY_FAILURES);
        }
    }

    private function recordSuccess(): void
    {
        Cache::forget(self::KEY_FAILURES);
        Cache::forget(self::KEY_OPEN);
    }

    /** Last-good data (badge "data lama") or unavailable. */
    private function fallback(string $key, string $reason): array
    {
        $good = Cache::get($key.'.last_good');
        if (is_array($good)) {
            return $this->result('stale', $good['fetched_at'] ?? null, $good['series'] ?? [], $reason);
        }

        return $this->result('unavailable', null, [], $reason);
    }

    private function result(string $status, ?string $fetchedAt, array $series, ?string $reason): array
    {
        return ['status' => $status, 'fetched_at' => $fetchedAt, 'series' => $series, 'reason' => $reason];
    }

    /** Class + HTTP status only; exception messages may embed URLs. */
    private function safeErrorLabel(Throwable $e): string
    {
        $label = class_basename($e);
        if ($e instanceof RequestException) {
            $label .= ' '.$e->response->status();
        } elseif ($e instanceof ConnectionException) {
            $label .= ' (connect/timeout)';
        }

        return $label;
    }

    /**
     * Legacy storage/app/grafana.env fallback (token/URL). Only consulted when neither the Setting nor
     * config/env supplies a token; prod stores the token in Settings, so this is dead weight to remove
     * once no host still carries the file.
     *
     * @return array<string, string>
     */
    private function legacyEnvFile(): array
    {
        $path = storage_path('app/grafana.env');
        if (! is_file($path)) {
            return [];
        }

        $values = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (str_starts_with(trim($line), '#') || ! str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $values[trim($k)] = trim($v);
        }

        return $values;
    }
}
