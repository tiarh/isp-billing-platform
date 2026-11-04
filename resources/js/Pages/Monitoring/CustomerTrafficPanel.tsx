import { Fragment, useEffect, useMemo, useRef, useState } from 'react';
import { Activity, ChevronDown, ChevronUp, Radio, RefreshCw, Wifi } from 'lucide-react';
import { Area, AreaChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { chartTooltipProps } from '@/lib/chartTheme';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { formatDateTime, formatNumber } from '@/lib/format';

type DailySummary = Record<string, number | string | null>;

type CpeDetails = {
    last_inform_at?: string | null;
    wan_ip?: string | null;
    ssid?: string | null;
    uptime_seconds?: number | null;
    channel?: string | number | null;
    optical_rx_dbm?: number | null;
};

type TrafficRow = {
    id: number;
    code: string | null;
    name: string;
    pppoe_user?: string | null;
    status: string;
    area: string | null;
    router: string | null;
    package: string | null;
    source?: string | null;
    current: {
        online: boolean;
        download_bps: number;
        upload_bps: number;
        quality: string;
        sampled_at: string | null;
        fresh: boolean;
        freshness_seconds: number | null;
    };
    today: DailySummary | null;
    last_7_days: DailySummary;
    last_30_days: DailySummary;
    this_month: DailySummary;
    series_24h?: Array<{ t: string; d: number; u: number }>;
    daily_timeline?: Array<DailySummary & { traffic_date_wib: string }>;
    cpe: CpeDetails | null;
};

type Options = {
    areas: Array<{ id: number; name: string }>;
    routers: Array<{ id: number; name: string }>;
    profiles: string[];
    packages: Array<{ id: number; name: string }>;
};

type Admission = {
    status: 'ready' | 'paused' | string;
    reason_codes?: string[];
    checked_at?: string | null;
};

type Source = {
    current?: string;
    collector?: string;
    collector_display?: string;
    cpe?: string;
};

type Coverage = {
    page_mapped?: number;
    page_total?: number;
};

function rate(value: number): string {
    if (!Number.isFinite(value) || value <= 0) return '0 bps';
    const units = ['bps', 'Kbps', 'Mbps', 'Gbps'];
    let n = value;
    let unit = 0;
    while (n >= 1000 && unit < units.length - 1) {
        n /= 1000;
        unit += 1;
    }
    return `${formatNumber(n, { maximumFractionDigits: 1 })} ${units[unit]}`;
}

function bytes(value?: number | string | null): string {
    const n = Number(value || 0);
    if (!Number.isFinite(n) || n <= 0) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    let scaled = n;
    let unit = 0;
    while (scaled >= 1000 && unit < units.length - 1) {
        scaled /= 1000;
        unit += 1;
    }
    return `${formatNumber(scaled, { maximumFractionDigits: 1 })} ${units[unit]}`;
}

function dateLabel(value?: string | null): string {
    if (!value) return '-';
    return formatDateTime(value);
}

function uptimeLabel(value?: string | number | null): string {
    const seconds = Number(value || 0);
    if (!Number.isFinite(seconds) || seconds <= 0) return '-';
    const days = Math.floor(seconds / 86400);
    const hours = Math.floor((seconds % 86400) / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    return `${days ? `${days}h ` : ''}${hours}j ${minutes}m`;
}

function qualityLabel(row: TrafficRow): string {
    return row.current.fresh ? row.current.quality || 'good' : 'stale';
}

function Usage24hChart({ points }: { points: Array<{ t: string; d: number; u: number }> }) {
    const data = points.map((point) => {
        let label = '';
        try {
            label = new Date(point.t).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', timeZone: 'Asia/Jakarta' });
        } catch {
            label = '';
        }
        return {
            label,
            download: Math.round((Number(point.d) || 0) / 1000), // bps → Kbps
            upload: Math.round((Number(point.u) || 0) / 1000),
        };
    });

    return (
        <div className="h-56 w-full">
            <ResponsiveContainer width="100%" height="100%">
                <AreaChart data={data} margin={{ top: 8, right: 12, bottom: 0, left: 0 }}>
                    <defs>
                        <linearGradient id="trafficDown" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="5%" stopColor="#06b6d4" stopOpacity={0.6} />
                            <stop offset="95%" stopColor="#06b6d4" stopOpacity={0.05} />
                        </linearGradient>
                        <linearGradient id="trafficUp" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="5%" stopColor="#10b981" stopOpacity={0.5} />
                            <stop offset="95%" stopColor="#10b981" stopOpacity={0.05} />
                        </linearGradient>
                    </defs>
                    <CartesianGrid strokeDasharray="3 3" strokeOpacity={0.15} vertical={false} />
                    <XAxis dataKey="label" tick={{ fontSize: 10, fill: 'var(--color-muted-foreground)' }} minTickGap={24} />
                    <YAxis tick={{ fontSize: 10, fill: 'var(--color-muted-foreground)' }} width={48} tickFormatter={(value) => `${value} K`} />
                    <Tooltip {...chartTooltipProps} formatter={(value: any, name: any) => [`${value} Kbps`, name === 'download' ? 'Download' : 'Upload']} labelFormatter={(label) => `WIB ${label}`} />
                    <Area type="monotone" dataKey="download" name="download" stroke="#06b6d4" fill="url(#trafficDown)" strokeWidth={2} isAnimationActive={false} />
                    <Area type="monotone" dataKey="upload" name="upload" stroke="#10b981" fill="url(#trafficUp)" strokeWidth={2} isAnimationActive={false} />
                </AreaChart>
            </ResponsiveContainer>
        </div>
    );
}

export function CustomerTrafficPanel() {
    const [rows, setRows] = useState<TrafficRow[]>([]);
    const [options, setOptions] = useState<Options>({ areas: [], routers: [], profiles: [], packages: [] });
    const [search, setSearch] = useState('');
    const [areaId, setAreaId] = useState('');
    const [routerId, setRouterId] = useState('');
    const [packageId, setPackageId] = useState('');
    const [status, setStatus] = useState('all');
    const [profile, setProfile] = useState('');
    const [sourceFilter, setSourceFilter] = useState('');
    const [cpe, setCpe] = useState('');
    const [page, setPage] = useState(1);
    const [pagination, setPagination] = useState({ last_page: 1, total: 0 });
    const [source, setSource] = useState<Source>({});
    const [coverage, setCoverage] = useState<Coverage>({});
    const [admission, setAdmission] = useState<Admission>({ status: 'ready' });
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [refreshNonce, setRefreshNonce] = useState(0);
    const [expandedId, setExpandedId] = useState<number | null>(null);
    const [tabHidden, setTabHidden] = useState(false);
    const pollDelay = useRef(30_000);

    const query = useMemo(
        () => ({ search, area_id: areaId, router_id: routerId, package_id: packageId, status, profile, source: sourceFilter, cpe, page }),
        [search, areaId, routerId, packageId, status, profile, sourceFilter, cpe, page],
    );

    useEffect(() => {
        const controller = new AbortController();
        fetch(route('api.monitoring.traffic.options'), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal: controller.signal,
        })
            .then((response) => (response.ok ? response.json() : Promise.reject(new Error('options_unavailable'))))
            .then((payload: Options) => setOptions(payload))
            .catch(() => undefined);
        return () => controller.abort();
    }, []);

    useEffect(() => {
        const controller = new AbortController();
        let timer: number | undefined;
        let disposed = false;
        const params = new URLSearchParams({ per_page: '50', page: String(query.page) });
        Object.entries(query).forEach(([key, value]) => {
            if (key !== 'page' && String(value).trim() !== '') params.set(key, String(value));
        });

        const scheduleNextPoll = () => {
            if (disposed || document.visibilityState === 'hidden') return;
            timer = window.setTimeout(() => setRefreshNonce((current) => current + 1), pollDelay.current);
        };

        const load = async () => {
            setLoading(true);
            setError(null);
            try {
                const response = await fetch(`${route('api.monitoring.traffic.index')}?${params.toString()}`, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                    signal: controller.signal,
                });
                const payload = await response.json().catch(() => ({}));
                if (!response.ok) throw new Error(payload.message || payload.status || 'traffic_unavailable');
                setRows(Array.isArray(payload.rows) ? payload.rows : []);
                setPagination(payload.pagination || { last_page: 1, total: 0 });
                setSource(payload.source || {});
                setCoverage(payload.coverage || {});
                setAdmission(payload.admission || { status: 'ready' });
                pollDelay.current = 30_000;
            } catch (reason) {
                if (reason instanceof Error && reason.name !== 'AbortError') {
                    setError(reason.message);
                    pollDelay.current = Math.min(120_000, Math.max(30_000, pollDelay.current * 2));
                }
            } finally {
                setLoading(false);
                scheduleNextPoll();
            }
        };

        const handleVisibility = () => {
            const hidden = document.visibilityState === 'hidden';
            setTabHidden(hidden);
            if (timer !== undefined) window.clearTimeout(timer);
            timer = undefined;
            if (!hidden) setRefreshNonce((current) => current + 1);
        };

        document.addEventListener('visibilitychange', handleVisibility);
        void load();
        return () => {
            disposed = true;
            if (timer !== undefined) window.clearTimeout(timer);
            document.removeEventListener('visibilitychange', handleVisibility);
            controller.abort();
        };
    }, [query, refreshNonce]);

    const clearFilters = () => {
        setSearch('');
        setAreaId('');
        setRouterId('');
        setPackageId('');
        setStatus('all');
        setProfile('');
        setSourceFilter('');
        setCpe('');
        setPage(1);
        setExpandedId(null);
    };

    const paused = admission.status === 'paused';

    return (
        <div className="space-y-4">
            <div className="grid gap-3 md:grid-cols-3">
                <Card className="border-border bg-card text-foreground md:col-span-2">
                    <CardContent className="flex flex-wrap items-center gap-4 p-4 text-sm">
                        <div className="flex items-center gap-2"><Activity className="h-4 w-4 text-muted-foreground" /> {source.collector_display || 'MikroTik / VM107'}</div>
                        <div className="flex items-center gap-2"><Radio className="h-4 w-4 text-muted-foreground" /> Current snapshot / 102</div>
                        <div className="flex items-center gap-2"><Wifi className="h-4 w-4 text-status-info" /> GenieACS projection</div>
                        <Badge variant="outline" className="border-border text-muted-foreground">Read-only</Badge>
                    </CardContent>
                </Card>
                <Card>
                    <CardContent className="grid grid-cols-2 gap-3 p-4 text-sm">
                        <div><div className="text-xs text-muted-foreground">Coverage halaman</div><div className="font-semibold">{coverage.page_mapped ?? 0}/{coverage.page_total ?? 0}</div></div>
                        <div><div className="text-xs text-muted-foreground">Total pelanggan</div><div className="font-semibold tabular-nums">{formatNumber(Number(pagination.total || 0), { maximumFractionDigits: 0 })}</div></div>
                    </CardContent>
                </Card>
            </div>

            {paused && <div role="status" className="rounded-md border border-status-degraded/40 bg-status-degraded-bg p-3 text-sm text-status-degraded">Monitoring dijeda oleh admission gate. Current ditandai stale dan last-good tetap dipertahankan. {admission.reason_codes?.join(', ')}</div>}
            {tabHidden && <div role="status" className="rounded-md border border-border bg-muted/40 p-3 text-sm text-muted-foreground">Polling dijeda karena tab tidak terlihat. Data diperbarui saat tab dibuka kembali.</div>}

            <Card>
                <CardHeader className="pb-3"><div className="flex flex-wrap items-center justify-between gap-2"><CardTitle className="text-base">Pemakaian Bandwidth Pelanggan</CardTitle><Button type="button" variant="outline" size="sm" onClick={() => setRefreshNonce((current) => current + 1)}><RefreshCw className="mr-2 h-3.5 w-3.5" />Refresh</Button></div></CardHeader>
                <CardContent className="space-y-4">
                    <div className="grid gap-2 md:grid-cols-8">
                        <Input aria-label="Cari pelanggan" className="md:col-span-2" placeholder="Cari nama, kode, PPPoE" value={search} onChange={(event) => { setSearch(event.target.value); setPage(1); }} />
                        <select aria-label="Filter area" className="h-10 rounded-md border bg-background px-3 text-sm" value={areaId} onChange={(event) => { setAreaId(event.target.value); setPage(1); }}><option value="">Semua area</option>{options.areas.map((area) => <option key={area.id} value={area.id}>{area.name}</option>)}</select>
                        <select aria-label="Filter router" className="h-10 rounded-md border bg-background px-3 text-sm" value={routerId} onChange={(event) => { setRouterId(event.target.value); setPage(1); }}><option value="">Semua router</option>{options.routers.map((router) => <option key={router.id} value={router.id}>{router.name}</option>)}</select>
                        <select aria-label="Filter paket" className="h-10 rounded-md border bg-background px-3 text-sm" value={packageId} onChange={(event) => { setPackageId(event.target.value); setPage(1); }}><option value="">Semua paket</option>{options.packages.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select>
                        <select aria-label="Filter status" className="h-10 rounded-md border bg-background px-3 text-sm" value={status} onChange={(event) => { setStatus(event.target.value); setPage(1); }}><option value="all">Semua status</option><option value="active">Active</option><option value="isolated">Isolated</option></select>
                        <select aria-label="Filter profile" className="h-10 rounded-md border bg-background px-3 text-sm" value={profile} onChange={(event) => { setProfile(event.target.value); setPage(1); }}><option value="">Semua profile</option>{options.profiles.map((item) => <option key={item} value={item}>{item}</option>)}</select>
                        <select aria-label="Filter source" className="h-10 rounded-md border bg-background px-3 text-sm" value={sourceFilter} onChange={(event) => { setSourceFilter(event.target.value); setPage(1); }}><option value="">Semua source</option><option value="mikrotik_pppoe">Current MikroTik</option><option value="api_poll">Daily API poll</option></select>
                        <select aria-label="Filter capability CPE" className="h-10 rounded-md border bg-background px-3 text-sm" value={cpe} onChange={(event) => { setCpe(event.target.value); setPage(1); }}><option value="">Semua capability CPE</option><option value="fresh">CPE fresh</option><option value="linked">CPE linked</option><option value="unlinked">Belum linked</option></select>
                    </div>
                    <div className="flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground"><span>Sumber current: {source.current || 'unknown'} | collector: {source.collector || 'unknown'} | CPE: {source.cpe || 'unknown'}</span><button type="button" className="underline" onClick={clearFilters}>Reset filter</button></div>
                    {error && <div role="alert" className="rounded-md border border-status-degraded/30 bg-status-degraded-bg p-3 text-sm text-status-degraded">Monitoring trafik belum tersedia: {error}. Last-good dan daily tidak dianggap nol.</div>}
                    {loading ? <div className="rounded-md border border-dashed p-10 text-center text-sm text-muted-foreground" role="status">Memuat snapshot trafik...</div> : rows.length === 0 ? <div className="rounded-md border border-dashed p-10 text-center text-sm text-muted-foreground">Belum ada snapshot trafik aktif.</div> : <div className="overflow-x-auto rounded-md border"><table className="w-full min-w-[1100px] text-left text-sm"><thead className="bg-muted/40 text-xs uppercase text-muted-foreground"><tr><th className="p-3">Pelanggan / PPPoE</th><th className="p-3">Area / Router</th><th className="p-3">Current Download</th><th className="p-3">Current Upload</th><th className="p-3">Pemakaian</th><th className="p-3">CPE / Freshness</th><th className="p-3">Detail</th></tr></thead><tbody>{rows.map((row) => { const expanded = expandedId === row.id; const quality = qualityLabel(row); return <Fragment key={row.id}>
                        <tr className="border-t">
                            <td className="p-3"><div className="font-medium">{row.name}</div><div className="text-xs text-muted-foreground">{row.code || '-'} · PPPoE: {row.pppoe_user || '-'}</div></td>
                            <td className="p-3"><div>{row.area || '-'}</div><div className="text-xs text-muted-foreground">{row.router || '-'}</div></td>
                            <td className="p-3 font-medium">{rate(row.current.download_bps)}</td>
                            <td className="p-3 font-medium">{rate(row.current.upload_bps)}</td>
                            <td className="p-3"><div>Hari ini {bytes(row.today?.total_bytes)}</div><div className="text-xs text-muted-foreground">Bulan ini {bytes(row.this_month?.total_bytes)}</div><div className="text-xs text-muted-foreground">30 hari {bytes(row.last_30_days?.total_bytes)}</div></td>
                            <td className="p-3"><div>{row.cpe ? 'Linked' : 'Unlinked'}</div><div className="text-xs text-muted-foreground">{quality} · {dateLabel(row.current.sampled_at)}</div></td>
                            <td className="p-3"><Button type="button" variant="outline" size="sm" onClick={() => setExpandedId(expanded ? null : row.id)}>{expanded ? <ChevronUp className="mr-1 h-3.5 w-3.5" /> : <ChevronDown className="mr-1 h-3.5 w-3.5" />}Detail</Button></td>
                        </tr>
                        {expanded && <tr key={`${row.id}-detail`} className="border-t bg-muted/20"><td colSpan={7} className="p-4"><div className="grid gap-4 md:grid-cols-3"><div><h4 className="font-medium">Current</h4><p className="text-sm text-muted-foreground">Download {rate(row.current.download_bps)} | Upload {rate(row.current.upload_bps)}</p><p className="text-sm text-muted-foreground">Quality {quality} | Sample {dateLabel(row.current.sampled_at)}</p></div><div><h4 className="font-medium">Daily</h4><p className="text-sm text-muted-foreground">Hari ini ↓ {bytes(row.today?.download_bytes)} / ↑ {bytes(row.today?.upload_bytes)} / total {bytes(row.today?.total_bytes)}</p><p className="text-sm text-muted-foreground">Bulan ini {bytes(row.this_month?.total_bytes)} | 7 hari {bytes(row.last_7_days?.total_bytes)} | 30 hari {bytes(row.last_30_days?.total_bytes)}</p><p className="text-xs text-muted-foreground">Coverage {row.today?.coverage_ratio || '-'} | Reset {row.today?.reset_count || '0'} | Gap {row.today?.gap_count || '0'}</p><div className="mt-2 max-h-24 space-y-1 overflow-auto text-xs text-muted-foreground"><div>Reset/gap timeline</div>{(row.daily_timeline || []).slice(0, 7).map((item) => <div key={item.traffic_date_wib}>{item.traffic_date_wib}: reset {item.reset_count || '0'}, gap {item.gap_count || '0'}, {item.quality || 'unknown'}</div>)}</div></div><div><h4 className="font-medium">CPE tier A</h4>{row.cpe ? <><p className="text-sm text-muted-foreground">WAN {row.cpe.wan_ip || '-'}</p><p className="text-sm text-muted-foreground">SSID {row.cpe.ssid || '-'} | Channel {row.cpe.channel || '-'}</p><p className="text-sm text-muted-foreground">Inform {dateLabel(row.cpe.last_inform_at)} | Uptime {uptimeLabel(row.cpe.uptime_seconds)} | RX {row.cpe.optical_rx_dbm ?? '-'} dBm</p></> : <p className="text-sm text-muted-foreground">CPE belum linked.</p>}</div></div>{row.series_24h && row.series_24h.length > 1 ? <div className="mt-4"><h4 className="mb-2 font-medium">Pemakaian 24 jam</h4><Usage24hChart points={row.series_24h} /></div> : null}</td></tr>}
                    </Fragment>; })}</tbody></table></div>}
                    <div className="flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground"><span>Download = transmitted router | Upload = received router | Grafik 24 jam tampil di baris Detail (interval ~15 menit).</span><div className="flex items-center gap-2"><Button type="button" variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage((current) => Math.max(1, current - 1))}>Sebelumnya</Button><span className="tabular-nums">{page}/{pagination.last_page || 1}</span><Button type="button" variant="outline" size="sm" disabled={page >= (pagination.last_page || 1)} onClick={() => setPage((current) => current + 1)}>Berikutnya</Button></div></div>
                </CardContent>
            </Card>
        </div>
    );
}
