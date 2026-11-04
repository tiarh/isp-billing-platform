/** Shapes returned by GET /api/monitoring/widgets/{widget} (App\Services\Grafana\MonitoringWidgets). */

export type MonitoringRange = '1h' | '6h' | '24h' | '7d' | '30d';

export const MONITORING_RANGES: ReadonlyArray<{ value: MonitoringRange; label: string; hint: string }> = [
    { value: '1h', label: '1j', hint: '1 jam terakhir' },
    { value: '6h', label: '6j', hint: '6 jam terakhir' },
    { value: '24h', label: '24j', hint: '24 jam terakhir' },
    { value: '7d', label: '7h', hint: '7 hari terakhir' },
    { value: '30d', label: '30h', hint: '30 hari terakhir' },
];

export type WidgetName = 'summary' | 'routers' | 'router-traffic' | 'upstream' | 'resources' | 'ping';

export type WidgetStatus = 'ok' | 'stale' | 'unavailable' | 'setup_required';

/** [timestamp in ms, value]. */
export type Point = [number | null, number | null];

export interface WidgetEnvelope<T> {
    /** 'cpe' is the DB-backed CPE health widget: no range, step or Grafana link. */
    widget: WidgetName | 'cpe';
    range: MonitoringRange | null;
    status: WidgetStatus;
    fetched_at: string | null;
    reason: string | null;
    partial: boolean;
    step_seconds: number | null;
    grafana_dashboard_url: string | null;
    data: T;
}

export interface SummaryData {
    routers: { online: number | null; total: number; scraped: number | null };
    pppoe: { total: number; source: 'db' | 'grafana' | 'mixed' };
    traffic: { rx_mbps: number | null; tx_mbps: number | null };
    upstream: {
        rx_mbps: number | null;
        tx_mbps: number | null;
        capacity_mbps: number | null;
        capacity_complete: boolean;
        utilization_pct: number | null;
    } | null;
    collectors: Array<{ job: string; up: boolean }> | null;
}

export interface RouterRow {
    router_id: number;
    name: string;
    scraped: boolean | null;
    pppoe_online: number;
    pppoe_source: 'db' | 'grafana';
    pppoe_secrets: number;
    rx_mbps: number | null;
    tx_mbps: number | null;
    cpu_pct: number | null;
    memory_pct: number | null;
    uptime_seconds: number | null;
}
export interface RoutersData {
    rows: RouterRow[];
}

export interface RouterTrafficSeries {
    router_id: number;
    name: string;
    rx: Point[];
    tx: Point[];
}
export interface RouterTrafficData {
    series: RouterTrafficSeries[];
}

export interface DirectionPair {
    rx: number | null;
    tx: number | null;
}
export interface UpstreamLink {
    key: string;
    display_name: string;
    kind: string;
    capacity_mbps: number | null;
    now: DirectionPair;
    p95: DirectionPair;
    peak: DirectionPair;
    avg: DirectionPair;
    utilization_pct: number | null;
    degraded: boolean;
    missing: boolean;
    series: { rx: Point[]; tx: Point[] };
}
export interface UpstreamData {
    links: UpstreamLink[];
}

export interface ResourceRow {
    router_id: number;
    name: string;
    cpu_pct: number | null;
    memory_pct: number | null;
    disk_pct: number | null;
    uptime_seconds: number | null;
    board_temp_c: number | null;
    cpu_temp_c: number | null;
    voltage_v: number | null;
    matched_by: 'router_id' | 'grafana_identity' | 'name' | null;
}
export interface ResourcesData {
    rows: ResourceRow[];
}

export type PingState = 'up' | 'degraded' | 'down' | 'icmp_blocked';
export interface PingTarget {
    target_name: string;
    kind: string;
    router_id: number | null;
    router_name: string | null;
    rtt_ms: number | null;
    loss_pct: number | null;
    state: PingState;
}
export interface PingLine {
    target_name: string;
    kind: string;
    points: Point[];
}
export interface PingData {
    targets: PingTarget[];
    series: { rtt: PingLine[]; loss: PingLine[] };
}

/** GET /api/monitoring/widgets/cpe (App\Services\Monitoring\CpeHealthReadModel). Aggregates only. */
export interface CpeHealthData {
    devices: {
        total: number;
        online: number;
        offline: number;
        online_pct: number | null;
        last_inform_at: string | null;
        /** Last GenieACS sync; drives the stale flag. */
        last_sync_at: string | null;
    };
    /** Online, non-isolated customers with a measured ONU RX power. */
    rx_power: { measured: number; good: number; fair: number; poor: number };
    top_offline_areas: Array<{ area: string; offline: number; total: number }>;
}

export type WidgetError = 'forbidden' | 'throttled' | 'server' | 'network' | null;

export interface WidgetState<T> {
    /** Latest envelope from the server (kept while a refresh is in flight). */
    envelope: WidgetEnvelope<T> | null;
    /** Last envelope whose status was ok/stale, for "last success" display. */
    lastGood: WidgetEnvelope<T> | null;
    /** True while a request (or its queue slot) is pending. */
    loading: boolean;
    error: WidgetError;
    /** ISO time of the last successful (ok/stale) fetch, if any. */
    lastSuccessAt: string | null;
}
