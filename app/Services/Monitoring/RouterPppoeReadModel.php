<?php

namespace App\Services\Monitoring;

use App\Models\Customer;
use App\Models\Router;
use App\Services\Grafana\GrafanaClient;
use App\Services\Grafana\GrafanaQueryTemplates;
use App\Services\Grafana\MonitoringWidgets;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * M-4c-2a: read model behind the router detail page. Live per-user rates come
 * from Grafana (cached 60 s per router), usage history from pppoe_usage_daily.
 * History rows with quality=invalid are excluded from every total and flagged;
 * partial days are counted and flagged. Usernames are never logged.
 */
class RouterPppoeReadModel
{
    public const STATUSES = ['all', 'online', 'offline', 'unlinked', 'unknown'];

    public const SORTS = ['name', 'status', 'download_now', 'upload_now', 'today', 'month'];

    public const DAILY_DAYS = 30;

    public const SPARK_DAYS = 7;

    public const LIVE_TTL = 60;

    public const GRAFANA_DASHBOARD = 'skynet-per-router';

    private const GB = 1.0e9;

    private const STATUS_RANK = ['online' => 0, 'unlinked' => 1, 'offline' => 2, 'unknown' => 3];

    public function __construct(
        private readonly GrafanaClient $grafana,
        private readonly PppoeCustomerMatcher $matcher,
    ) {}

    // ------------------------------------------------------------------
    // Live (Grafana)
    // ------------------------------------------------------------------

    /**
     * Live per-user rates (Mbps) of one router, cached 60 s.
     *
     * users: keyed by lowercase username; null when Grafana gave no usable answer.
     *
     * @return array{status: string, fetched_at: ?string, reason: ?string, users: ?array<string, array{name: string, download: float, upload: float}>}
     */
    public function live(Router $router): array
    {
        $id = (int) $router->id;
        $key = 'monitoring.router.'.$id.'.pppoe';
        $hit = Cache::get($key);
        if (is_array($hit)) {
            return $hit;
        }

        $params = ['rid' => $id];
        $results = [];
        foreach (['download', 'upload'] as $dir) {
            $results[$dir] = $this->grafana->query('pppoe_user_'.PppoeUsageRollup::DIRECTION[$dir].'_rate', $params);
        }

        foreach ($results as $res) {
            if ($res['status'] === 'setup_required') {
                return ['status' => 'setup_required', 'fetched_at' => null, 'reason' => $res['reason'], 'users' => null];
            }
        }
        foreach ($results as $res) {
            if (! in_array($res['status'], ['ok', 'stale'], true)) {
                return ['status' => 'unavailable', 'fetched_at' => null, 'reason' => $res['reason'], 'users' => null];
            }
        }

        $users = [];
        foreach ($results as $dir => $res) {
            foreach ($res['series'] as $series) {
                $user = PppoeUsageRollup::normalizeUserLabel((string) ($series['labels']['user'] ?? ''));
                if ($user === '' || mb_strlen($user) > 128) {
                    continue;
                }
                $value = $this->lastValue($series);
                if ($value === null || $value < 0) {
                    continue;
                }
                $lower = mb_strtolower($user);
                $users[$lower] ??= ['name' => $user, 'download' => 0.0, 'upload' => 0.0];
                $users[$lower][$dir] += $value;
            }
        }

        $meta = MonitoringWidgets::combine([
            ['primary' => true, 'result' => $results['download']],
            ['primary' => true, 'result' => $results['upload']],
        ]);
        $live = [
            'status' => $meta['status'],
            'fetched_at' => $meta['fetched_at'],
            'reason' => $meta['reason'],
            'users' => $users,
        ];
        Cache::put($key, $live, self::LIVE_TTL);

        return $live;
    }

    // ------------------------------------------------------------------
    // Table
    // ------------------------------------------------------------------

    /**
     * @param  array{q?: ?string, status?: string, sort?: string, dir?: string, page?: int, per_page?: int}  $opts
     * @return array{status: string, fetched_at: ?string, reason: ?string, partial: bool, rows: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function table(Router $router, array $opts): array
    {
        $live = $this->live($router);
        $all = $this->mergedRows($router, $live);

        $q = mb_strtolower(trim((string) ($opts['q'] ?? '')));
        if ($q !== '') {
            $all = array_values(array_filter($all, static function (array $r) use ($q): bool {
                foreach ([$r['pppoe_user'], (string) $r['name'], (string) $r['customer_code']] as $hay) {
                    if ($hay !== '' && str_contains(mb_strtolower($hay), $q)) {
                        return true;
                    }
                }

                return false;
            }));
        }

        $counts = ['all' => count($all), 'online' => 0, 'offline' => 0, 'unlinked' => 0, 'unknown' => 0];
        foreach ($all as $r) {
            if (isset($counts[$r['status']])) {
                $counts[$r['status']]++;
            }
        }

        $status = (string) ($opts['status'] ?? 'all');
        if ($status !== 'all') {
            $all = array_values(array_filter($all, static fn (array $r): bool => $r['status'] === $status));
        }

        $this->sortRows($all, (string) ($opts['sort'] ?? 'name'), (string) ($opts['dir'] ?? 'asc'));

        $perPage = max(1, (int) ($opts['per_page'] ?? 50));
        $total = count($all);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($lastPage, (int) ($opts['page'] ?? 1)));
        $slice = array_slice($all, ($page - 1) * $perPage, $perPage);

        $spark = $this->spark7((int) $router->id, array_column($slice, 'pppoe_user'));
        $out = [];
        foreach ($slice as $r) {
            $r['spark7'] = $spark[mb_strtolower($r['pppoe_user'])] ?? array_fill(0, self::SPARK_DAYS, 0.0);
            $out[] = $r;
        }

        return [
            'status' => $live['status'],
            'fetched_at' => $live['fetched_at'],
            'reason' => $live['reason'],
            'partial' => $live['users'] === null || $live['status'] === 'stale',
            'rows' => $out,
            'meta' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'last_page' => $lastPage,
                'counts' => $counts,
                'live' => $live['users'] !== null,
            ],
        ];
    }

    /**
     * Union of live users, router customers and unlinked month history.
     *
     * @return list<array<string, mixed>>
     */
    private function mergedRows(Router $router, array $live): array
    {
        $id = (int) $router->id;
        $today = $this->today();
        $month = $this->monthUsage($id, $today->startOfMonth(), $today);
        $users = $live['users'];

        $linked = [];
        if ($users !== null) {
            foreach ($users as $lower => $u) {
                $linked[$lower] = $this->matcher->resolve($id, $u['name']);
            }
        }
        $customerIds = array_values(array_filter($linked, static fn ($c): bool => $c !== null));
        $customers = $customerIds === []
            ? collect()
            : Customer::query()->whereIn('id', $customerIds)->get(['id', 'name', 'code', 'status'])->keyBy('id');

        $rows = [];
        $seenUser = [];
        $seenCustomer = [];

        if ($users !== null) {
            foreach ($users as $lower => $u) {
                $cid = $linked[$lower];
                $customer = $cid !== null ? $customers->get($cid) : null;
                $seenUser[$lower] = true;
                if ($cid !== null) {
                    $seenCustomer[$cid] = true;
                }
                $rows[] = $this->row($u['name'], $customer, $cid !== null ? 'online' : 'unlinked', true, $u['download'], $u['upload'], $month);
            }
        }

        foreach ($this->matcher->customersForRouter($id) as $c) {
            $lower = mb_strtolower($c['pppoe_user']);
            if (isset($seenCustomer[$c['id']]) || isset($seenUser[$lower])) {
                continue;
            }
            $seenUser[$lower] = true;
            $rows[] = $this->row($c['pppoe_user'], $c, $users === null ? 'unknown' : 'offline', false, null, null, $month);
        }

        foreach ($month['users'] as $lower => $h) {
            if (isset($seenUser[$lower]) || $h['customer_id'] !== null) {
                continue;
            }
            $rows[] = $this->row($h['name'], null, 'unlinked', false, null, null, $month);
        }

        return $rows;
    }

    /**
     * @param  Customer|array<string, mixed>|null  $customer
     * @return array<string, mixed>
     */
    private function row(string $user, Customer|array|null $customer, string $status, bool $online, ?float $down, ?float $up, array $month): array
    {
        $lower = mb_strtolower($user);
        $h = $month['users'][$lower] ?? null;
        $flags = $month['flags'][$lower] ?? ['invalid' => false, 'partial' => false];

        $get = static fn (string $f) => $customer instanceof Customer ? $customer->getAttribute($f) : ($customer[$f] ?? null);

        return [
            'pppoe_user' => $user,
            'name' => $customer !== null ? (string) $get('name') : null,
            'customer_id' => $customer !== null ? (int) $get('id') : null,
            'customer_code' => $customer !== null ? (string) $get('code') : null,
            'status' => $status,
            'is_online' => $online,
            'isolated' => $customer !== null && $get('status') === 'isolated',
            'download_mbps' => $down === null ? null : round($down, 2),
            'upload_mbps' => $up === null ? null : round($up, 2),
            'today_gb' => $this->gb(($h['today_download'] ?? 0) + ($h['today_upload'] ?? 0)),
            'month_gb' => $this->gb(($h['download'] ?? 0) + ($h['upload'] ?? 0)),
            'has_invalid_days' => $flags['invalid'],
            'history_partial' => $flags['partial'],
        ];
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function sortRows(array &$rows, string $sort, string $dir): void
    {
        $sign = $dir === 'desc' ? -1 : 1;
        $key = static function (array $r) use ($sort) {
            return match ($sort) {
                'status' => self::STATUS_RANK[$r['status']] ?? 9,
                'download_now' => $r['download_mbps'] ?? -1.0,
                'upload_now' => $r['upload_mbps'] ?? -1.0,
                'today' => $r['today_gb'],
                'month' => $r['month_gb'],
                default => mb_strtolower($r['name'] ?? $r['pppoe_user']),
            };
        };
        usort($rows, static function (array $a, array $b) use ($key, $sign): int {
            $cmp = $key($a) <=> $key($b);
            if ($cmp === 0) {
                return mb_strtolower($a['pppoe_user']) <=> mb_strtolower($b['pppoe_user']);
            }

            return $cmp * $sign;
        });
    }

    // ------------------------------------------------------------------
    // Overview
    // ------------------------------------------------------------------

    /**
     * @return array{status: string, fetched_at: ?string, reason: ?string, partial: bool, step_seconds: int, grafana_dashboard_url: ?string, data: array<string, mixed>}
     */
    public function overview(Router $router, string $range): array
    {
        $id = (int) $router->id;
        $live = $this->live($router);
        $runs = [['primary' => true, 'result' => [
            'status' => $live['status'], 'fetched_at' => $live['fetched_at'], 'reason' => $live['reason'],
        ]]];

        $onlineRes = $this->grafana->query('pppoe_per_router', [], $range);
        $runs[] = ['primary' => false, 'result' => $onlineRes];
        $online = null;
        if (in_array($onlineRes['status'], ['ok', 'stale'], true)) {
            foreach ($onlineRes['series'] as $s) {
                if ((string) ($s['labels']['router_id'] ?? '') === (string) $id) {
                    $v = $this->lastValue($s);
                    if ($v !== null) {
                        $online = (int) round($v);
                        break;
                    }
                }
            }
        }

        $uplink = ['rx' => [], 'tx' => []];
        foreach (['rx', 'tx'] as $dir) {
            $res = $this->grafana->query('router_uplink_'.$dir.'_range', ['rid' => $id], $range);
            $runs[] = ['primary' => true, 'result' => $res];
            if (in_array($res['status'], ['ok', 'stale'], true)) {
                $uplink[$dir] = $this->sumSeries($res['series']);
            }
        }

        $today = $this->today();
        $month = $this->monthUsage($id, $today->startOfMonth(), $today);
        $monthBytes = 0;
        foreach ($month['users'] as $h) {
            $monthBytes += $h['download'] + $h['upload'];
        }

        $downNow = $upNow = null;
        $top = [];
        if ($live['users'] !== null) {
            $downNow = $upNow = 0.0;
            $items = [];
            foreach ($live['users'] as $u) {
                $downNow += $u['download'];
                $upNow += $u['upload'];
                $items[] = $u;
            }
            usort($items, static fn (array $a, array $b): int => ($b['download'] + $b['upload']) <=> ($a['download'] + $a['upload']));
            $items = array_values(array_filter(array_slice($items, 0, 10), static fn (array $u): bool => $u['download'] + $u['upload'] > 0));

            $ids = [];
            foreach ($items as $u) {
                $ids[mb_strtolower($u['name'])] = $this->matcher->resolve($id, $u['name']);
            }
            $names = array_filter($ids) === []
                ? collect()
                : Customer::query()->whereIn('id', array_values(array_filter($ids)))->get(['id', 'name'])->keyBy('id');
            foreach ($items as $u) {
                $cid = $ids[mb_strtolower($u['name'])];
                $top[] = [
                    'pppoe_user' => $u['name'],
                    'customer_id' => $cid,
                    'name' => $cid !== null ? ($names->get($cid)?->name) : null,
                    'download_mbps' => round($u['download'], 2),
                    'upload_mbps' => round($u['upload'], 2),
                ];
            }
        }

        $flags = ['invalid' => false, 'partial' => false];
        foreach ($month['flags'] as $f) {
            $flags['invalid'] = $flags['invalid'] || $f['invalid'];
            $flags['partial'] = $flags['partial'] || $f['partial'];
        }

        $meta = MonitoringWidgets::combine($runs);

        return [
            'status' => $meta['status'],
            'fetched_at' => $meta['fetched_at'],
            'reason' => $meta['reason'],
            'partial' => $meta['partial'],
            'step_seconds' => GrafanaQueryTemplates::RANGES[$range]['step'],
            'grafana_dashboard_url' => $this->grafanaUrl($router, $range),
            'data' => [
                'kpi' => [
                    'pppoe_online' => $online ?? (int) $router->current_online_count,
                    'pppoe_online_source' => $online !== null ? 'grafana' : 'db',
                    'pppoe_secrets' => (int) $router->total_pppoe_count,
                    'download_now_mbps' => $downNow === null ? null : round($downNow, 2),
                    'upload_now_mbps' => $upNow === null ? null : round($upNow, 2),
                    'month_gb' => $this->gb($monthBytes),
                    'month_has_invalid_days' => $flags['invalid'],
                    'month_history_partial' => $flags['partial'],
                ],
                'uplink' => $uplink,
                'top' => $top,
                'daily' => $this->dailyRows($id, null, $today),
            ],
        ];
    }

    public function grafanaUrl(Router $router, string $range): ?string
    {
        $base = $this->grafana->baseUrl();
        if ($base === null) {
            return null;
        }
        $url = $base.'/d/'.self::GRAFANA_DASHBOARD.'?orgId=1';
        $identity = trim((string) $router->getAttribute('grafana_identity'));
        if ($identity !== '') {
            $url .= '&var-router='.rawurlencode($identity);
        }

        return $url.'&from=now-'.$range.'&to=now';
    }

    // ------------------------------------------------------------------
    // Daily history
    // ------------------------------------------------------------------

    /**
     * Last 30 WIB days for one username (case-insensitive).
     *
     * @return array<string, mixed>
     */
    public function daily(Router $router, string $user): array
    {
        $id = (int) $router->id;
        $days = $this->dailyRows($id, $user, $this->today());

        $found = false;
        $down = $up = 0.0;
        $invalid = false;
        $partial = false;
        foreach ($days as $d) {
            $found = $found || $d['quality'] !== 'none';
            $down += (float) ($d['download_gb'] ?? 0);
            $up += (float) ($d['upload_gb'] ?? 0);
            $invalid = $invalid || $d['quality'] === PppoeUsageRollup::QUALITY_INVALID;
            $partial = $partial || $d['quality'] === PppoeUsageRollup::QUALITY_PARTIAL;
        }

        $cid = $this->matcher->resolve($id, $user);
        $customer = $cid !== null ? Customer::query()->find($cid, ['id', 'name']) : null;

        return [
            'pppoe_user' => $user,
            'found' => $found,
            'customer_id' => $customer?->id,
            'name' => $customer?->name,
            'days' => $days,
            'totals' => ['download_gb' => round($down, 3), 'upload_gb' => round($up, 3)],
            'has_invalid_days' => $invalid,
            'history_partial' => $partial,
        ];
    }

    /**
     * 30 WIB days ending today, whole router or one user. Invalid days return
     * null GB with quality=invalid; days without rows return quality=none.
     *
     * @return list<array{date: string, download_gb: ?float, upload_gb: ?float, quality: string}>
     */
    private function dailyRows(int $routerId, ?string $user, CarbonImmutable $today): array
    {
        $from = $today->subDays(self::DAILY_DAYS - 1);
        $query = DB::table('pppoe_usage_daily')
            ->where('router_id', $routerId)
            ->whereBetween('usage_date_wib', [$from->format('Y-m-d'), $today->format('Y-m-d')]);
        if ($user !== null) {
            // pppoe_user collation is case-insensitive: plain equality keeps the index usable.
            $query->where('pppoe_user', $user);
        }

        // At most ~60 rows (day x quality): aggregated in SQL, never per user in PHP.
        $byDay = [];
        $query->groupBy('usage_date_wib', 'quality')
            ->selectRaw('usage_date_wib, quality, SUM(download_bytes) AS dl, SUM(upload_bytes) AS ul')
            ->get()
            ->each(function ($r) use (&$byDay): void {
                $date = substr((string) $r->usage_date_wib, 0, 10);
                $d = &$byDay[$date];
                $d ??= ['down' => 0, 'up' => 0, 'invalid' => false, 'partial' => false];
                if ($r->quality === PppoeUsageRollup::QUALITY_INVALID) {
                    $d['invalid'] = true;
                } else {
                    $d['down'] += (int) $r->dl;
                    $d['up'] += (int) $r->ul;
                    $d['partial'] = $d['partial'] || $r->quality === PppoeUsageRollup::QUALITY_PARTIAL;
                }
                unset($d);
            });

        $out = [];
        for ($i = 0; $i < self::DAILY_DAYS; $i++) {
            $date = $from->addDays($i)->format('Y-m-d');
            $d = $byDay[$date] ?? null;
            if ($d === null) {
                $out[] = ['date' => $date, 'download_gb' => null, 'upload_gb' => null, 'quality' => 'none'];
            } elseif ($d['invalid']) {
                $out[] = ['date' => $date, 'download_gb' => null, 'upload_gb' => null, 'quality' => PppoeUsageRollup::QUALITY_INVALID];
            } else {
                $out[] = [
                    'date' => $date,
                    'download_gb' => $this->gb($d['down']),
                    'upload_gb' => $this->gb($d['up']),
                    'quality' => $d['partial'] ? PppoeUsageRollup::QUALITY_PARTIAL : PppoeUsageRollup::QUALITY_OK,
                ];
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // History helpers
    // ------------------------------------------------------------------

    /**
     * Month-to-date (calendar month WIB) sums per user, invalid rows excluded,
     * plus per-user quality flags. Keyed by lowercase username.
     *
     * @return array{users: array<string, array{name: string, customer_id: ?int, download: int, upload: int, today_download: int, today_upload: int}>, flags: array<string, array{invalid: bool, partial: bool}>}
     */
    private function monthUsage(int $routerId, CarbonImmutable $monthStart, CarbonImmutable $today): array
    {
        $todayStr = $today->format('Y-m-d');
        $range = [$monthStart->format('Y-m-d'), $todayStr];

        $users = [];
        DB::table('pppoe_usage_daily')
            ->where('router_id', $routerId)
            ->whereBetween('usage_date_wib', $range)
            ->where('quality', '!=', PppoeUsageRollup::QUALITY_INVALID)
            ->groupBy('pppoe_user')
            ->selectRaw(
                'pppoe_user, MAX(customer_id) AS cid, SUM(download_bytes) AS dl, SUM(upload_bytes) AS ul, '
                .'SUM(CASE WHEN usage_date_wib = ? THEN download_bytes ELSE 0 END) AS tdl, '
                .'SUM(CASE WHEN usage_date_wib = ? THEN upload_bytes ELSE 0 END) AS tul',
                [$todayStr, $todayStr]
            )
            ->get()
            ->each(function ($r) use (&$users): void {
                $lower = mb_strtolower((string) $r->pppoe_user);
                $users[$lower] ??= [
                    'name' => (string) $r->pppoe_user,
                    'customer_id' => $r->cid !== null ? (int) $r->cid : null,
                    'download' => 0, 'upload' => 0, 'today_download' => 0, 'today_upload' => 0,
                ];
                $users[$lower]['download'] += (int) $r->dl;
                $users[$lower]['upload'] += (int) $r->ul;
                $users[$lower]['today_download'] += (int) $r->tdl;
                $users[$lower]['today_upload'] += (int) $r->tul;
            });

        $flags = [];
        DB::table('pppoe_usage_daily')
            ->where('router_id', $routerId)
            ->whereBetween('usage_date_wib', $range)
            ->whereIn('quality', [PppoeUsageRollup::QUALITY_INVALID, PppoeUsageRollup::QUALITY_PARTIAL])
            ->groupBy('pppoe_user', 'quality')
            ->get(['pppoe_user', 'quality'])
            ->each(function ($r) use (&$flags): void {
                $lower = mb_strtolower((string) $r->pppoe_user);
                $flags[$lower] ??= ['invalid' => false, 'partial' => false];
                $flags[$lower][$r->quality === PppoeUsageRollup::QUALITY_INVALID ? 'invalid' : 'partial'] = true;
            });

        return ['users' => $users, 'flags' => $flags];
    }

    /**
     * GB per day for the last 7 WIB days (oldest first) for the given users;
     * invalid days are null. Keyed by lowercase username.
     *
     * @param  list<string>  $usernames
     * @return array<string, list<?float>>
     */
    private function spark7(int $routerId, array $usernames): array
    {
        if ($usernames === []) {
            return [];
        }
        $today = $this->today();
        $from = $today->subDays(self::SPARK_DAYS - 1);

        $acc = [];
        DB::table('pppoe_usage_daily')
            ->where('router_id', $routerId)
            ->whereIn('pppoe_user', $usernames)
            ->whereBetween('usage_date_wib', [$from->format('Y-m-d'), $today->format('Y-m-d')])
            ->get(['pppoe_user', 'usage_date_wib', 'download_bytes', 'upload_bytes', 'quality'])
            ->each(function ($r) use (&$acc): void {
                $lower = mb_strtolower((string) $r->pppoe_user);
                $date = substr((string) $r->usage_date_wib, 0, 10);
                $acc[$lower][$date] ??= ['bytes' => 0, 'invalid' => false];
                if ($r->quality === PppoeUsageRollup::QUALITY_INVALID) {
                    $acc[$lower][$date]['invalid'] = true;
                } else {
                    $acc[$lower][$date]['bytes'] += (int) $r->download_bytes + (int) $r->upload_bytes;
                }
            });

        $out = [];
        foreach ($acc as $lower => $byDate) {
            $points = [];
            for ($i = 0; $i < self::SPARK_DAYS; $i++) {
                $cell = $byDate[$from->addDays($i)->format('Y-m-d')] ?? null;
                $points[] = $cell === null ? 0.0 : ($cell['invalid'] ? null : $this->gb($cell['bytes']));
            }
            $out[$lower] = $points;
        }

        return $out;
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::now(PppoeUsageRollup::TZ)->startOfDay();
    }

    private function gb(int|float $bytes): float
    {
        return round($bytes / self::GB, 3);
    }

    // ------------------------------------------------------------------
    // Series helpers
    // ------------------------------------------------------------------

    private function lastValue(array $series): ?float
    {
        $points = $series['points'] ?? [];
        if ($points === []) {
            return null;
        }
        $v = end($points)[1] ?? null;

        return is_numeric($v) && is_finite((float) $v) ? (float) $v : null;
    }

    /**
     * Sum all series per timestamp.
     *
     * @param  list<array>  $series
     * @return list<array{0: mixed, 1: float}>
     */
    private function sumSeries(array $series): array
    {
        $by = [];
        foreach ($series as $s) {
            foreach ($s['points'] ?? [] as $p) {
                $v = $p[1] ?? null;
                if (is_numeric($v) && is_finite((float) $v)) {
                    $by[$p[0]] = ($by[$p[0]] ?? 0.0) + (float) $v;
                }
            }
        }
        ksort($by);
        $out = [];
        foreach ($by as $ts => $v) {
            $out[] = [$ts, round($v, 1)];
        }

        return $out;
    }
}
