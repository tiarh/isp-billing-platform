import type { Point } from '@/Components/monitoring/network/types';

/** Shapes returned by GET /api/monitoring/routers/{router}/... (App\Services\Monitoring\RouterPppoeReadModel). */

export interface RouterInfo {
    id: number;
    name: string;
    is_active: boolean;
    grafana_identity: string | null;
}

export interface RouterDetailPageProps {
    router: RouterInfo;
    grafanaUrl: string | null;
    /** First WIB day with per-user history (YYYY-MM-DD). */
    historyStart: string;
    /** Set only for the Krian router: usage before the day after this date is not valid. */
    krianInvalidThrough: string | null;
}

export type DayQuality = 'ok' | 'partial' | 'invalid' | 'none';

export interface DailyPoint {
    /** YYYY-MM-DD, WIB. */
    date: string;
    download_gb: number | null;
    upload_gb: number | null;
    quality: DayQuality;
}

export interface RouterKpi {
    pppoe_online: number;
    pppoe_online_source: 'grafana' | 'db';
    pppoe_secrets: number;
    download_now_mbps: number | null;
    upload_now_mbps: number | null;
    month_gb: number;
    month_has_invalid_days: boolean;
    month_history_partial: boolean;
}

export interface TopUser {
    pppoe_user: string;
    customer_id: number | null;
    name: string | null;
    download_mbps: number;
    upload_mbps: number;
}

export interface RouterOverviewData {
    kpi: RouterKpi;
    uplink: { rx: Point[]; tx: Point[] };
    top: TopUser[];
    daily: DailyPoint[];
}

export type PppoeStatus = 'online' | 'offline' | 'unlinked' | 'unknown';
export type PppoeFilter = 'all' | PppoeStatus;
export type PppoeSort = 'name' | 'status' | 'download_now' | 'upload_now' | 'today' | 'month';

export interface PppoeRow {
    pppoe_user: string;
    name: string | null;
    customer_id: number | null;
    customer_code: string | null;
    status: PppoeStatus;
    is_online: boolean;
    isolated: boolean;
    download_mbps: number | null;
    upload_mbps: number | null;
    today_gb: number;
    month_gb: number;
    has_invalid_days: boolean;
    history_partial: boolean;
    /** 7 WIB days GB/day, oldest first; null = invalid day. */
    spark7: Array<number | null>;
}

export interface PppoeListMeta {
    total: number;
    page: number;
    per_page: number;
    last_page: number;
    counts: { all: number; online: number; offline: number; unlinked: number; unknown: number };
    /** False when Grafana is down: online/offline cannot be told apart. */
    live: boolean;
}

export interface PppoeListData {
    rows: PppoeRow[];
    meta: PppoeListMeta;
}

export interface PppoeDailyData {
    pppoe_user: string;
    found: boolean;
    customer_id: number | null;
    name: string | null;
    days: DailyPoint[];
    totals: { download_gb: number; upload_gb: number };
    has_invalid_days: boolean;
    history_partial: boolean;
}
