<?php

namespace App\Services\Grafana;

use Carbon\Carbon;
use InvalidArgumentException;

/**
 * Builds the Monitoring widget payloads from whitelisted Grafana templates
 * (via GrafanaClient) joined with eBilling's own routers / upstream links.
 *
 * Router set = active eBilling routers; Grafana series for any other
 * router_id (and the edge, router_id 900) are dropped.
 */
class MonitoringWidgets
{
    public const WIDGETS = ['summary', 'routers', 'router-traffic', 'upstream', 'resources', 'ping'];

    public const DEGRADED_UTILIZATION_PCT = 80.0;

    public const PING_DEGRADED_LOSS_PCT = 2.0;

    public const PING_DEGRADED_RTT_MS = 100.0;

    private const EDGE_ROUTER_ID = '900';

    /** Grafana dashboard uid per widget (for the "Buka di Grafana" link). */
    private const DASHBOARDS = [
        'summary' => 'skynet-mikrotik-overview',
        'routers' => 'skynet-mikrotik-overview',
        'router-traffic' => 'skynet-traffic',
        'upstream' => 'skynet-jakarta-edge',
        'resources' => 'skynet-per-router',
        'ping' => 'skynet-mikrotik-overview',
    ];

    /** field => [template, primary] */
    private const RESOURCE_FIELDS = [
        'cpu_pct' => ['res_cpu', true],
        'memory_pct' => ['res_memory_pct', true],
        'disk_pct' => ['res_disk_pct', false],
        'uptime_seconds' => ['res_uptime', true],
        'board_temp_c' => ['res_board_temp', false],
        'cpu_temp_c' => ['res_cpu_temp', false],
        'voltage_v' => ['res_voltage', false],
    ];

    private const PING_KIND_ORDER = ['upstream' => 0, 'internet' => 1, 'router' => 2, 'area' => 3];

    public function __construct(
        private readonly GrafanaClient $grafana,
        private readonly MonitoringTopology $topology,
    ) {}

    /**
     * @return array{widget: string, range: string, status: string, fetched_at: ?string, reason: ?string, partial: bool, step_seconds: int, grafana_dashboard_url: ?string, data: array}
     */
    public function build(string $widget, string $range): array
    {
        if (! in_array($widget, self::WIDGETS, true)) {
            throw new InvalidArgumentException('Unknown monitoring widget.');
        }
        if (! isset(GrafanaQueryTemplates::RANGES[$range])) {
            throw new InvalidArgumentException('Unsupported monitoring range.');
        }

        $runs = [];
        $data = match ($widget) {
            'summary' => $this->summary($runs, $range),
            'routers' => $this->routers($runs, $range),
            'router-traffic' => $this->routerTraffic($runs, $range),
            'upstream' => $this->upstream($runs, $range),
            'resources' => $this->resources($runs, $range),
            'ping' => $this->ping($runs, $range),
        };

        $meta = self::combine($runs);
        $base = $this->grafana->baseUrl();

        return [
            'widget' => $widget,
            'range' => $range,
            'status' => $meta['status'],
            'fetched_at' => $meta['fetched_at'],
            'reason' => $meta['reason'],
            'partial' => $meta['partial'],
            'step_seconds' => GrafanaQueryTemplates::RANGES[$range]['step'],
            'grafana_dashboard_url' => $base !== null
                ? $base.'/d/'.self::DASHBOARDS[$widget].'?orgId=1&from=now-'.$range.'&to=now'
                : null,
            'data' => $data,
        ];
    }

    /**
     * Fold per-query results into one envelope status.
     *
     * @param  list<array{primary: bool, result: array}>  $runs
     * @return array{status: string, fetched_at: ?string, reason: ?string, partial: bool}
     */
    public static function combine(array $runs): array
    {
        $primary = array_values(array_filter($runs, static fn (array $r): bool => $r['primary']));

        foreach ($primary as $r) {
            if ($r['result']['status'] === 'setup_required') {
                return ['status' => 'setup_required', 'fetched_at' => null, 'reason' => $r['result']['reason'], 'partial' => false];
            }
        }

        if ($primary !== [] && count(array_filter($primary, static fn (array $r): bool => $r['result']['status'] === 'unavailable')) === count($primary)) {
            return ['status' => 'unavailable', 'fetched_at' => null, 'reason' => $primary[0]['result']['reason'], 'partial' => true];
        }

        $oldest = null;
        $reason = null;
        $degraded = false;
        foreach ($runs as $r) {
            $res = $r['result'];
            if (in_array($res['status'], ['stale', 'unavailable'], true)) {
                $degraded = true;
                $reason ??= $res['reason'];
            }
            if (! empty($res['fetched_at'])) {
                $ts = Carbon::parse($res['fetched_at']);
                if ($oldest === null || $ts->lt($oldest)) {
                    $oldest = $ts;
                }
            }
        }

        return [
            'status' => $degraded ? 'stale' : 'ok',
            'fetched_at' => $oldest?->toIso8601String(),
            'reason' => $degraded ? $reason : null,
            'partial' => $degraded,
        ];
    }

    // ------------------------------------------------------------------
    // Widgets
    // ------------------------------------------------------------------

    private function summary(array &$runs, string $range): array
    {
        $routers = $this->topology->activeRouters();
        $idSet = $this->idSet($routers);

        $pppoeRes = $this->run($runs, true, 'pppoe_per_router', [], $range);
        $scrapeRes = $this->run($runs, true, 'collector_scrape_up', [], $range);
        $uplinkRes = $this->run($runs, true, 'router_uplink_mbps', [], $range);

        $scrape = $this->usable($scrapeRes) ? $this->valueByRouter($scrapeRes, $idSet) : null;
        $pppoe = $this->usable($pppoeRes) ? $this->valueByRouter($pppoeRes, $idSet) : [];
        $fromGrafana = 0;
        $pppoeSum = 0;
        foreach ($routers as $r) {
            $rid = (string) $r['id'];
            if (isset($pppoe[$rid])) {
                $fromGrafana++;
                $pppoeSum += (int) round($pppoe[$rid]);
            } else {
                $pppoeSum += $r['current_online_count'];
            }
        }
        $pppoeTotal = [
            'total' => $pppoeSum,
            'source' => $fromGrafana === 0 ? 'db' : ($fromGrafana === count($routers) ? 'grafana' : 'mixed'),
        ];

        $traffic = ['rx_mbps' => null, 'tx_mbps' => null];
        if ($this->usable($uplinkRes)) {
            $now = $this->uplinkNow($uplinkRes, $idSet);
            $traffic = [
                'rx_mbps' => $this->r1(array_sum(array_column($now, 'rx'))),
                'tx_mbps' => $this->r1(array_sum(array_column($now, 'tx'))),
            ];
        }

        $collectorsRes = $this->run($runs, false, 'collector_targets_up', [], $range);
        $collectors = null;
        if ($this->usable($collectorsRes)) {
            $jobs = [];
            foreach ($collectorsRes['series'] as $s) {
                $job = (string) ($s['labels']['job'] ?? '');
                if ($job === '') {
                    continue;
                }
                $up = ($this->last($s) ?? 0.0) >= 1.0;
                $jobs[$job] = ($jobs[$job] ?? true) && $up;
            }
            ksort($jobs);
            $collectors = [];
            foreach ($jobs as $job => $up) {
                $collectors[] = ['job' => $job, 'up' => $up];
            }
        }

        return [
            'routers' => [
                'online' => $scrape === null ? null : count(array_filter($scrape, static fn (float $v): bool => $v >= 1.0)),
                'total' => count($routers),
                'scraped' => $scrape === null ? null : count($scrape),
            ],
            'pppoe' => $pppoeTotal,
            'traffic' => $traffic,
            'upstream' => $this->summaryUpstream($runs, $range),
            'collectors' => $collectors,
        ];
    }

    private function summaryUpstream(array &$runs, string $range): ?array
    {
        $links = $this->topology->upstreamLinks();
        $links = $links === null ? [] : array_values(array_filter($links, static fn (array $l): bool => $l['kind'] !== 'aggregate'));
        if ($links === []) {
            return null;
        }

        $rxRes = $this->run($runs, false, 'upstream_rx_all', [], $range);
        $txRes = $this->run($runs, false, 'upstream_tx_all', [], $range);
        $rx = $this->usable($rxRes) ? $this->valueByLabel($rxRes, 'upstream') : null;
        $tx = $this->usable($txRes) ? $this->valueByLabel($txRes, 'upstream') : null;

        if ($rx !== null || $tx !== null) {
            foreach ($links as $l) {
                if (! isset($rx[$l['upstream_key']]) && ! isset($tx[$l['upstream_key']])) {
                    $runs[] = ['primary' => false, 'result' => ['status' => 'stale', 'fetched_at' => null, 'series' => [], 'reason' => 'Sebagian link upstream tidak punya data.']];
                    break;
                }
            }
        }

        $sumFor = static function (?array $values) use ($links): ?float {
            if ($values === null) {
                return null;
            }
            $sum = 0.0;
            foreach ($links as $l) {
                $sum += $values[$l['upstream_key']] ?? 0.0;
            }

            return $sum;
        };
        $rxSum = $sumFor($rx);
        $txSum = $sumFor($tx);

        $capacity = 0.0;
        $complete = true;
        foreach ($links as $l) {
            if ($l['capacity_mbps'] === null) {
                $complete = false;
            } else {
                $capacity += $l['capacity_mbps'];
            }
        }
        $utilization = null;
        if ($complete && $capacity > 0 && ($rxSum !== null || $txSum !== null)) {
            $utilization = $this->r1(max($rxSum ?? 0.0, $txSum ?? 0.0) / $capacity * 100);
        }

        return [
            'rx_mbps' => $this->r1($rxSum),
            'tx_mbps' => $this->r1($txSum),
            'capacity_mbps' => $capacity > 0 ? $this->r1($capacity) : null,
            'capacity_complete' => $complete,
            'utilization_pct' => $utilization,
        ];
    }

    private function routers(array &$runs, string $range): array
    {
        $routers = $this->topology->activeRouters();
        $idSet = $this->idSet($routers);

        $pppoeRes = $this->run($runs, true, 'pppoe_per_router', [], $range);
        $scrapeRes = $this->run($runs, true, 'collector_scrape_up', [], $range);
        $uplinkRes = $this->run($runs, true, 'router_uplink_mbps', [], $range);
        $res = $this->resourceMap($runs, $routers, ['cpu_pct', 'memory_pct', 'uptime_seconds'], $range);

        $pppoe = $this->usable($pppoeRes) ? $this->valueByRouter($pppoeRes, $idSet) : [];
        $scrape = $this->usable($scrapeRes) ? $this->valueByRouter($scrapeRes, $idSet) : null;
        $uplink = $this->usable($uplinkRes) ? $this->uplinkNow($uplinkRes, $idSet) : null;

        $rows = [];
        foreach ($routers as $r) {
            $rid = (string) $r['id'];
            $fromGrafana = isset($pppoe[$rid]);
            $rows[] = [
                'router_id' => $r['id'],
                'name' => $r['name'],
                'scraped' => $scrape === null ? null : (isset($scrape[$rid]) && $scrape[$rid] >= 1.0),
                'pppoe_online' => $fromGrafana ? (int) round($pppoe[$rid]) : $r['current_online_count'],
                'pppoe_source' => $fromGrafana ? 'grafana' : 'db',
                'pppoe_secrets' => $r['total_pppoe_count'],
                'rx_mbps' => $uplink === null ? null : $this->r1($uplink[$rid]['rx'] ?? null),
                'tx_mbps' => $uplink === null ? null : $this->r1($uplink[$rid]['tx'] ?? null),
                'cpu_pct' => $res[$rid]['cpu_pct'] ?? null,
                'memory_pct' => $res[$rid]['memory_pct'] ?? null,
                'uptime_seconds' => $res[$rid]['uptime_seconds'] ?? null,
            ];
        }

        return ['rows' => $rows];
    }

    private function routerTraffic(array &$runs, string $range): array
    {
        $routers = $this->topology->activeRouters();
        $idSet = $this->idSet($routers);

        $res = $this->run($runs, true, 'router_uplink_mbps_range', [], $range);

        $byRouter = [];
        if ($this->usable($res)) {
            foreach ($res['series'] as $s) {
                $rid = (string) ($s['labels']['router_id'] ?? '');
                $dir = $s['labels']['direction'] ?? '';
                if (! isset($idSet[$rid]) || ! in_array($dir, ['rx', 'tx'], true)) {
                    continue;
                }
                $byRouter[$rid][$dir] ??= $this->points($s);
            }
        }

        $series = [];
        foreach ($routers as $r) {
            $rid = (string) $r['id'];
            if (! isset($byRouter[$rid])) {
                continue;
            }
            $series[] = [
                'router_id' => $r['id'],
                'name' => $r['name'],
                'rx' => $byRouter[$rid]['rx'] ?? [],
                'tx' => $byRouter[$rid]['tx'] ?? [],
            ];
        }

        return ['series' => $series];
    }

    private function upstream(array &$runs, string $range): array
    {
        $links = $this->topology->upstreamLinks();
        if ($links === null || $links === []) {
            $runs[] = ['primary' => true, 'result' => $this->setupRequired('Upstream link belum dikonfigurasi di pengaturan Monitoring.')];

            return ['links' => []];
        }

        $now = [
            'rx' => $this->run($runs, true, 'upstream_rx_all', [], $range),
            'tx' => $this->run($runs, true, 'upstream_tx_all', [], $range),
        ];
        $rng = [
            'rx' => $this->run($runs, true, 'upstream_rx_range', [], $range),
            'tx' => $this->run($runs, true, 'upstream_tx_range', [], $range),
        ];
        $stats = [];
        foreach (['p95', 'peak', 'avg'] as $stat) {
            foreach (['rx', 'tx'] as $dir) {
                $stats[$stat][$dir] = $this->run($runs, false, 'upstream_'.$dir.'_'.$stat, [], $range);
            }
        }

        $val = fn (array $res): array => $this->usable($res) ? $this->valueByLabel($res, 'upstream') : [];
        $nowV = ['rx' => $val($now['rx']), 'tx' => $val($now['tx'])];
        $statV = [];
        foreach ($stats as $stat => $dirs) {
            foreach ($dirs as $dir => $res) {
                $statV[$stat][$dir] = $val($res);
            }
        }
        $rngS = [];
        foreach ($rng as $dir => $res) {
            $rngS[$dir] = $this->usable($res) ? $this->seriesByLabel($res, 'upstream') : [];
        }

        $out = [];
        foreach ($links as $l) {
            $key = $l['upstream_key'];
            $missing = ! isset($nowV['rx'][$key]) && ! isset($nowV['tx'][$key])
                && ! isset($rngS['rx'][$key]) && ! isset($rngS['tx'][$key]);

            $pair = fn (array $map): array => [
                'rx' => $this->r1($map['rx'][$key] ?? null),
                'tx' => $this->r1($map['tx'][$key] ?? null),
            ];
            $nowPair = $pair($nowV);

            $util = null;
            if ($l['capacity_mbps'] !== null && ($nowPair['rx'] !== null || $nowPair['tx'] !== null)) {
                $util = $this->r1(max($nowV['rx'][$key] ?? 0.0, $nowV['tx'][$key] ?? 0.0) / $l['capacity_mbps'] * 100);
            }

            $out[] = [
                'key' => $key,
                'display_name' => $l['display_name'],
                'kind' => $l['kind'],
                'capacity_mbps' => $l['capacity_mbps'],
                'now' => $nowPair,
                'p95' => $pair($statV['p95']),
                'peak' => $pair($statV['peak']),
                'avg' => $pair($statV['avg']),
                'utilization_pct' => $util,
                'degraded' => $util !== null && $util > self::DEGRADED_UTILIZATION_PCT,
                'missing' => $missing,
                'series' => [
                    'rx' => isset($rngS['rx'][$key]) ? $this->points($rngS['rx'][$key]) : [],
                    'tx' => isset($rngS['tx'][$key]) ? $this->points($rngS['tx'][$key]) : [],
                ],
            ];
        }

        $primaryUsable = count(array_filter(
            [$now['rx'], $now['tx'], $rng['rx'], $rng['tx']],
            fn (array $res): bool => $this->usable($res)
        )) > 0;
        // Only "no data for any link" when Grafana actually answered; an outage stays unavailable.
        if ($primaryUsable && count(array_filter($out, static fn (array $l): bool => $l['missing'])) === count($out)) {
            $runs[] = ['primary' => true, 'result' => $this->setupRequired('Belum ada data upstream di Grafana untuk link yang dikonfigurasi (cek recording rule / nama interface).')];
        }

        return ['links' => $out];
    }

    private function resources(array &$runs, string $range): array
    {
        $routers = $this->topology->activeRouters();
        $res = $this->resourceMap($runs, $routers, array_keys(self::RESOURCE_FIELDS), $range);

        $rows = [];
        foreach ($routers as $r) {
            $row = ['router_id' => $r['id'], 'name' => $r['name']];
            foreach (array_keys(self::RESOURCE_FIELDS) as $field) {
                $row[$field] = $res[(string) $r['id']][$field] ?? null;
            }
            $row['matched_by'] = $res[(string) $r['id']]['matched_by'] ?? null;
            $rows[] = $row;
        }

        return ['rows' => $rows];
    }

    private function ping(array &$runs, string $range): array
    {
        $routers = $this->topology->activeRouters();
        $idSet = $this->idSet($routers);
        $names = array_column($routers, 'name', 'id');

        $rttRes = $this->run($runs, true, 'ping_rtt', [], $range);
        if ($rttRes['status'] === 'ok' && $rttRes['series'] === []) {
            $runs[] = ['primary' => true, 'result' => $this->setupRequired('Belum ada data ping (blackbox exporter belum aktif).')];

            return ['targets' => [], 'series' => ['rtt' => [], 'loss' => []]];
        }
        $lossRes = $this->run($runs, false, 'ping_loss_pct', [], $range);
        $scrapeRes = $this->run($runs, false, 'collector_scrape_up', [], $range);
        $rttRange = $this->run($runs, false, 'ping_rtt_range', [], $range);
        $lossRange = $this->run($runs, false, 'ping_loss_range', [], $range);

        $scrape = $this->usable($scrapeRes) ? $this->valueByRouter($scrapeRes, $idSet) : [];
        // Optional: a live mktxp resources series also proves the router is reachable via API
        // (some routers drop ICMP and have no/zero skynet_scrape_up).
        $mktxp = $this->resourceMap($runs, $routers, ['cpu_pct'], $range, true);

        $targets = [];
        $collect = function (array $res, string $field) use (&$targets): void {
            if (! $this->usable($res)) {
                return;
            }
            foreach ($res['series'] as $s) {
                $name = (string) ($s['labels']['target_name'] ?? '');
                if ($name === '') {
                    continue;
                }
                $kind = (string) ($s['labels']['kind'] ?? '');
                $k = $kind.'|'.$name;
                $targets[$k] ??= [
                    'target_name' => $name,
                    'kind' => $kind,
                    'router_id' => isset($s['labels']['router_id']) ? (string) $s['labels']['router_id'] : null,
                    'rtt' => null,
                    'loss' => null,
                ];
                $v = $this->last($s);
                if ($field === 'rtt' && $v !== null && $v <= 0) {
                    $v = null; // blackbox keeps the rtt gauge at 0 on failed probes
                }
                $targets[$k][$field] ??= $v;
            }
        };
        $collect($rttRes, 'rtt');
        $collect($lossRes, 'loss');

        $out = [];
        foreach ($targets as $t) {
            $rid = $t['router_id'];
            if ($t['kind'] === 'router' && ($rid === null || ! isset($idSet[$rid]))) {
                continue;
            }
            $rttMs = $t['rtt'] === null ? null : $t['rtt'] * 1000;
            $loss = $t['loss'];

            $down = $rttMs === null || ($loss !== null && $loss >= 99.99);
            if ($down) {
                $apiReachable = $t['kind'] === 'router'
                    && ((isset($scrape[$rid]) && $scrape[$rid] >= 1.0) || isset($mktxp[$rid]['cpu_pct']));
                $state = $apiReachable ? 'icmp_blocked' : 'down';
            } elseif (($loss !== null && $loss >= self::PING_DEGRADED_LOSS_PCT) || $rttMs >= self::PING_DEGRADED_RTT_MS) {
                $state = 'degraded';
            } else {
                $state = 'up';
            }

            $out[] = [
                'target_name' => $t['target_name'],
                'kind' => $t['kind'],
                'router_id' => $rid !== null && ctype_digit($rid) ? (int) $rid : null,
                'router_name' => $t['kind'] === 'router' && $rid !== null ? ($names[(int) $rid] ?? null) : null,
                'rtt_ms' => $this->r1($rttMs),
                'loss_pct' => $this->r1($loss),
                'state' => $state,
            ];
        }
        usort($out, static fn (array $a, array $b): int => [self::PING_KIND_ORDER[$a['kind']] ?? 9, $a['target_name']]
            <=> [self::PING_KIND_ORDER[$b['kind']] ?? 9, $b['target_name']]);

        $line = function (array $res, float $factor, bool $zeroIsNull = false): array {
            $lines = [];
            if (! $this->usable($res)) {
                return $lines;
            }
            foreach ($res['series'] as $s) {
                $kind = (string) ($s['labels']['kind'] ?? '');
                $name = (string) ($s['labels']['target_name'] ?? '');
                if ($name === '' || ! in_array($kind, ['upstream', 'internet'], true)) {
                    continue;
                }
                $lines[] = ['target_name' => $name, 'kind' => $kind, 'points' => $this->points($s, $factor, $zeroIsNull)];
            }
            usort($lines, static fn (array $a, array $b): int => [self::PING_KIND_ORDER[$a['kind']] ?? 9, $a['target_name']]
                <=> [self::PING_KIND_ORDER[$b['kind']] ?? 9, $b['target_name']]);

            return $lines;
        };

        return [
            'targets' => $out,
            'series' => ['rtt' => $line($rttRange, 1000.0, true), 'loss' => $line($lossRange, 1.0)],
        ];
    }

    // ------------------------------------------------------------------
    // Shared helpers
    // ------------------------------------------------------------------

    /**
     * One query per metric for all routers, joined in PHP:
     * router_id label, then routerboard_name == grafana_identity, then
     * case-insensitive name match.
     *
     * @param  list<array>  $routers
     * @param  list<string>  $fields
     * @return array<string, array<string, mixed>> keyed by router id
     */
    private function resourceMap(array &$runs, array $routers, array $fields, string $range, bool $forceOptional = false): array
    {
        $idSet = $this->idSet($routers);
        $byIdentity = [];
        $byName = [];
        foreach ($routers as $r) {
            if ($r['grafana_identity'] !== null) {
                $byIdentity[$r['grafana_identity']] ??= (string) $r['id'];
            }
            $byName[mb_strtolower($r['name'])] ??= (string) $r['id'];
        }

        $out = [];
        foreach ($fields as $field) {
            [$template, $primary] = self::RESOURCE_FIELDS[$field];
            $res = $this->run($runs, $forceOptional ? false : $primary, $template, [], $range);
            if (! $this->usable($res)) {
                continue;
            }
            foreach ($res['series'] as $s) {
                $value = $this->last($s);
                if ($value === null) {
                    continue;
                }
                $labels = $s['labels'];
                $rb = (string) ($labels['routerboard_name'] ?? '');
                $rid = (string) ($labels['router_id'] ?? '');
                if ($rid !== '' && isset($idSet[$rid])) {
                    $match = [$rid, 'router_id'];
                } elseif ($rb !== '' && isset($byIdentity[$rb])) {
                    $match = [$byIdentity[$rb], 'grafana_identity'];
                } elseif ($rb !== '' && isset($byName[mb_strtolower($rb)])) {
                    $match = [$byName[mb_strtolower($rb)], 'name'];
                } else {
                    continue;
                }
                [$id, $by] = $match;
                if (array_key_exists($field, $out[$id] ?? [])) {
                    continue;
                }
                $out[$id][$field] = $field === 'uptime_seconds' ? (int) round($value) : $this->r1($value);
                $out[$id]['matched_by'] ??= $by;
            }
        }

        return $out;
    }

    /**
     * @param  list<array{primary: bool, result: array}>  $runs
     */
    private function run(array &$runs, bool $primary, string $template, array $params, string $range): array
    {
        $result = $this->grafana->query($template, $params, $range);
        $runs[] = ['primary' => $primary, 'result' => $result];

        return $result;
    }

    private function setupRequired(string $reason): array
    {
        return ['status' => 'setup_required', 'fetched_at' => null, 'series' => [], 'reason' => $reason];
    }

    private function usable(array $result): bool
    {
        return in_array($result['status'], ['ok', 'stale'], true);
    }

    /**
     * @param  list<array>  $routers
     * @return array<string, true>
     */
    private function idSet(array $routers): array
    {
        $set = [];
        foreach ($routers as $r) {
            $id = (string) $r['id'];
            if ($id !== self::EDGE_ROUTER_ID) {
                $set[$id] = true;
            }
        }

        return $set;
    }

    private function last(array $series): ?float
    {
        $points = $series['points'] ?? [];
        if ($points === []) {
            return null;
        }
        $v = end($points)[1] ?? null;

        return is_numeric($v) ? (float) $v : null;
    }

    /** @return array<string, float> first value per router_id, active routers only */
    private function valueByRouter(array $res, array $idSet): array
    {
        $out = [];
        foreach ($res['series'] as $s) {
            $rid = (string) ($s['labels']['router_id'] ?? '');
            $v = $this->last($s);
            if (isset($idSet[$rid]) && $v !== null && ! isset($out[$rid])) {
                $out[$rid] = $v;
            }
        }

        return $out;
    }

    /** @return array<string, array{rx?: float, tx?: float}> */
    private function uplinkNow(array $res, array $idSet): array
    {
        $out = [];
        foreach ($res['series'] as $s) {
            $rid = (string) ($s['labels']['router_id'] ?? '');
            $dir = $s['labels']['direction'] ?? '';
            $v = $this->last($s);
            if (isset($idSet[$rid]) && in_array($dir, ['rx', 'tx'], true) && $v !== null) {
                $out[$rid][$dir] = ($out[$rid][$dir] ?? 0.0) + $v;
            }
        }

        return $out;
    }

    /** @return array<string, float> */
    private function valueByLabel(array $res, string $label): array
    {
        $out = [];
        foreach ($res['series'] as $s) {
            $k = (string) ($s['labels'][$label] ?? '');
            $v = $this->last($s);
            if ($k !== '' && $v !== null && ! isset($out[$k])) {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    /** @return array<string, array> */
    private function seriesByLabel(array $res, string $label): array
    {
        $out = [];
        foreach ($res['series'] as $s) {
            $k = (string) ($s['labels'][$label] ?? '');
            if ($k !== '' && ! isset($out[$k])) {
                $out[$k] = $s;
            }
        }

        return $out;
    }

    /** @return list<array{0: mixed, 1: ?float}> */
    private function points(array $series, float $factor = 1.0, bool $zeroIsNull = false): array
    {
        $out = [];
        foreach ($series['points'] ?? [] as $p) {
            $v = $p[1] ?? null;
            if ($zeroIsNull && is_numeric($v) && (float) $v <= 0) {
                $v = null;
            }
            $out[] = [$p[0] ?? null, is_numeric($v) ? $this->r1((float) $v * $factor) : null];
        }

        return $out;
    }

    private function r1(?float $v): ?float
    {
        return $v === null ? null : round($v, 1);
    }
}
