<?php

namespace App\Services\Grafana;

use InvalidArgumentException;

/**
 * Whitelist of PromQL templates the server may send to Grafana. The browser
 * never supplies PromQL; callers pick a template name and pass label values,
 * which are validated and escaped here. Recording rules (skynet:*) are used
 * for the expensive Jakarta upstream series (see monitoring plan D8).
 */
final class GrafanaQueryTemplates
{
    /** Allowed range => window seconds and step seconds (plan D8). */
    public const RANGES = [
        '1h' => ['seconds' => 3600, 'step' => 60],
        '6h' => ['seconds' => 21600, 'step' => 120],
        '24h' => ['seconds' => 86400, 'step' => 300],
        '7d' => ['seconds' => 604800, 'step' => 1800],
        '30d' => ['seconds' => 2592000, 'step' => 3600],
    ];

    /** Instant queries look back this far. */
    public const INSTANT_LOOKBACK_SECONDS = 900;

    /** Placeholder => value pattern. Anything else is rejected, not stripped. */
    private const PARAM_PATTERNS = [
        'rid' => '/^[0-9]{1,6}$/',
        'rb' => '/^[A-Za-z0-9 ._:\/\[\]-]{1,96}$/',
        'ifc' => '/^[A-Za-z0-9 ._:\/\[\]-]{1,96}$/',
        // PromQL range window in seconds, e.g. "86400s"; clamped by WIN_MIN/WIN_MAX.
        'win' => '/^[0-9]{3,6}s$/',
    ];

    /** Clamp for the `win` parameter (seconds): 5 minutes .. 25 hours. */
    public const WIN_MIN_SECONDS = 300;

    public const WIN_MAX_SECONDS = 90000;

    private const MBPS = '*8/1e6';

    /**
     * mode: instant | range. {range} inside an expression is a validated range key.
     *
     * @return array<string, array{expr: string, mode: string, ttl: int, params: list<string>}>
     */
    public static function all(): array
    {
        $m = self::MBPS;
        $t = static fn (string $expr, string $mode, int $ttl, array $params = []): array => [
            'expr' => $expr, 'mode' => $mode, 'ttl' => $ttl, 'params' => $params,
        ];

        return [
            'pppoe_total' => $t('sum(skynet_pppoe_online{router_id!="900"})', 'instant', 60),
            'pppoe_per_router' => $t('skynet_pppoe_online{router_id!="900"}', 'instant', 60),
            'pppoe_per_router_range' => $t('skynet_pppoe_online{router_id!="900"}', 'range', 60),
            'collector_scrape_up' => $t('skynet_scrape_up', 'instant', 30),
            'collector_targets_up' => $t('up{job=~"mktxp_106|skynet_traffic_106|blackbox_exporter"}', 'instant', 30),

            'router_traffic_rx' => $t('sum by (router_id, router) (rate(skynet_uplink_rx_bytes{router_id!="900"}[5m]))'.$m, 'instant', 60),
            'router_traffic_tx' => $t('sum by (router_id, router) (rate(skynet_uplink_tx_bytes{router_id!="900"}[5m]))'.$m, 'instant', 60),
            'router_traffic_rx_range' => $t('sum by (router_id, router) (rate(skynet_uplink_rx_bytes{router_id!="900"}[5m]))'.$m, 'range', 60),
            'router_traffic_tx_range' => $t('sum by (router_id, router) (rate(skynet_uplink_tx_bytes{router_id!="900"}[5m]))'.$m, 'range', 60),
            'router_top_traffic' => $t('topk(10, sum by (router_id, router) (rate(skynet_uplink_rx_bytes{router_id!="900"}[5m])+rate(skynet_uplink_tx_bytes{router_id!="900"}[5m]))'.$m.')', 'instant', 60),
            'router_uplink_rx_range' => $t('rate(skynet_uplink_rx_bytes{router_id="{rid}"}[5m])'.$m, 'range', 60, ['rid']),
            'router_uplink_tx_range' => $t('rate(skynet_uplink_tx_bytes{router_id="{rid}"}[5m])'.$m, 'range', 60, ['rid']),

            // Per-router uplink via recording rule; labels router_id, router, direction=rx|tx (edge 900 excluded by the rule)
            'router_uplink_mbps' => $t('skynet:router_uplink_mbps:rate5m', 'instant', 60),
            'router_uplink_mbps_range' => $t('skynet:router_uplink_mbps:rate5m', 'range', 60),

            // Jakarta upstream via recording rules; label upstream=THC|TLN|JKTIX|IIX|RBN|DATAUTAMA|N3K_TOTAL
            'upstream_rx_all' => $t('skynet:upstream_rx_mbps:rate5m', 'instant', 30),
            'upstream_tx_all' => $t('skynet:upstream_tx_mbps:rate5m', 'instant', 30),
            'upstream_rx_range' => $t('skynet:upstream_rx_mbps:rate5m', 'range', 60),
            'upstream_tx_range' => $t('skynet:upstream_tx_mbps:rate5m', 'range', 60),
            'upstream_rx_p95' => $t('quantile_over_time(0.95, skynet:upstream_rx_mbps:rate5m[{range}])', 'instant', 300),
            'upstream_rx_peak' => $t('max_over_time(skynet:upstream_rx_mbps:rate5m[{range}])', 'instant', 300),
            'upstream_rx_avg' => $t('avg_over_time(skynet:upstream_rx_mbps:rate5m[{range}])', 'instant', 300),
            'upstream_tx_p95' => $t('quantile_over_time(0.95, skynet:upstream_tx_mbps:rate5m[{range}])', 'instant', 300),
            'upstream_tx_peak' => $t('max_over_time(skynet:upstream_tx_mbps:rate5m[{range}])', 'instant', 300),
            'upstream_tx_avg' => $t('avg_over_time(skynet:upstream_tx_mbps:rate5m[{range}])', 'instant', 300),
            // Exact interface on the edge; never query the whole skynet_iface family.
            'edge_iface_rx_range' => $t('rate(skynet_iface_rx_bytes{router="Jakarta-Edge",interface="{ifc}"}[5m])'.$m, 'range', 60, ['ifc']),
            'edge_iface_tx_range' => $t('rate(skynet_iface_tx_bytes{router="Jakarta-Edge",interface="{ifc}"}[5m])'.$m, 'range', 60, ['ifc']),

            // Resources: one query for all routers, mapped by routerboard_name in PHP
            'res_cpu' => $t('mktxp_system_cpu_load', 'instant', 60),
            'res_memory_pct' => $t('(1 - mktxp_system_free_memory / mktxp_system_total_memory) * 100', 'instant', 60),
            'res_disk_pct' => $t('(1 - mktxp_system_free_hdd_space / mktxp_system_total_hdd_space) * 100', 'instant', 60),
            'res_uptime' => $t('mktxp_system_uptime', 'instant', 60),
            'res_board_temp' => $t('mktxp_system_routerboard_temperature', 'instant', 60),
            'res_cpu_temp' => $t('mktxp_system_cpu_temperature', 'instant', 60),
            'res_voltage' => $t('mktxp_system_routerboard_voltage', 'instant', 60),
            'res_cpu_range' => $t('mktxp_system_cpu_load{routerboard_name="{rb}"}', 'range', 60, ['rb']),

            // Ping via blackbox_exporter (empty result => setup_required in the widget layer)
            'ping_rtt' => $t('probe_icmp_duration_seconds{job="blackbox_icmp",phase="rtt"}', 'instant', 30),
            'ping_loss_pct' => $t('(1 - avg_over_time(probe_success{job="blackbox_icmp"}[5m])) * 100', 'instant', 30),

            'ping_rtt_range' => $t('probe_icmp_duration_seconds{job="blackbox_icmp",phase="rtt",kind=~"upstream|internet"}', 'range', 60),
            'ping_loss_range' => $t('(1-avg_over_time(probe_success{job="blackbox_icmp",kind=~"upstream|internet"}[5m]))*100', 'range', 60),

            // Per-PPPoE-user series of ONE router (label `user`). Rates are Mbps; increases are
            // bytes over the `win` window ending at the query time (see GrafanaClient::queryAt).
            'pppoe_user_rx_rate' => $t('rate(skynet_user_rx_bytes{router_id="{rid}"}[5m])'.$m, 'instant', 60, ['rid']),
            'pppoe_user_tx_rate' => $t('rate(skynet_user_tx_bytes{router_id="{rid}"}[5m])'.$m, 'instant', 60, ['rid']),
            'pppoe_user_rx_increase' => $t('increase(skynet_user_rx_bytes{router_id="{rid}"}[{win}])', 'instant', 60, ['rid', 'win']),
            'pppoe_user_tx_increase' => $t('increase(skynet_user_tx_bytes{router_id="{rid}"}[{win}])', 'instant', 60, ['rid', 'win']),

            // PPPoE sessions of ONE router (one series per session, label `user`; a session that
            // drops disappears from the series). Used by GrafanaClient::queryRangeBetween() for the
            // nightly presence archive (explicit from/to/step, not the fixed RANGES).
            'pppoe_session_presence' => $t('skynet_pppoe_session{router_id="{rid}"}', 'range', 60, ['rid']),
            // Poll health of the same router: value 1 = the exporter polled the router; a minute without 1
            // means "unknown" (every session of that router is missing then, not really down).
            'pppoe_scrape_up' => $t('skynet_scrape_up{router_id="{rid}"}', 'range', 60, ['rid']),

            // Connection test
            'health_up' => $t('up', 'instant', 15),
        ];
    }

    /**
     * Resolve a template into a final expression.
     *
     * @param  array<string, string|int>  $params
     * @return array{expr: string, mode: string, ttl: int, range: string, uses_range: bool}
     *
     * @throws InvalidArgumentException on unknown template, bad range or bad/missing/extra params
     */
    public static function resolve(string $template, array $params = [], string $range = '1h'): array
    {
        $all = self::all();
        if (! isset($all[$template])) {
            throw new InvalidArgumentException('Unknown Grafana query template.');
        }
        if (! isset(self::RANGES[$range])) {
            throw new InvalidArgumentException('Unsupported Grafana range.');
        }

        $def = $all[$template];
        $given = array_keys($params);
        sort($given);
        $expected = $def['params'];
        sort($expected);
        if ($given !== $expected) {
            throw new InvalidArgumentException('Grafana template parameters do not match.');
        }

        $expr = $def['expr'];
        foreach ($def['params'] as $name) {
            $value = (string) $params[$name];
            if (! preg_match(self::PARAM_PATTERNS[$name], $value)) {
                throw new InvalidArgumentException("Invalid Grafana label value for {$name}.");
            }
            if ($name === 'win') {
                $value = max(self::WIN_MIN_SECONDS, min(self::WIN_MAX_SECONDS, (int) $value)).'s';
            }
            $expr = str_replace('{'.$name.'}', self::escapeLabel($value), $expr);
        }
        $usesRange = $def['mode'] === 'range' || str_contains($expr, '{range}');
        $expr = str_replace('{range}', $range, $expr);
        $ttl = $def['ttl'];
        if ($def['mode'] === 'range') {
            if (in_array($range, ['7d', '30d'], true)) {
                $ttl = max($ttl, 1800);
            } elseif ($range === '24h') {
                $ttl = max($ttl, 300);
            }
        } elseif (str_contains($def['expr'], '{range}')) {
            $ttl = in_array($range, ['7d', '30d'], true) ? 1800 : 300;
        }

        return ['expr' => $expr, 'mode' => $def['mode'], 'ttl' => $ttl, 'range' => $range, 'uses_range' => $usesRange];
    }

    /** Escape for use inside a double-quoted PromQL string. */
    public static function escapeLabel(string $value): string
    {
        return str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '\\n', '\\r'], $value);
    }
}
