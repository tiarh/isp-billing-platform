/**
 * STARLITE Performance Dashboard — layout mendekati Excel Google Sheet.
 * Data: Excel import (sebelum bot) + psb_drafts live. Read-only.
 */
import { EnterpriseIndexLayout } from '@/Components/enterprise/EnterpriseIndexLayout';
import { StatusBadge, type StatusTone } from '@/Components/StatusBadge';
import { ConfirmDestructive } from '@/Components/ConfirmDestructive';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ChevronDown, ChevronRight, Download, RefreshCw, Settings2, Target, UserPlus } from 'lucide-react';
import { Fragment, useMemo, useState } from 'react';
import {
    Bar,
    BarChart,
    CartesianGrid,
    Cell,
    LabelList,
    Legend,
    ReferenceLine,
    ResponsiveContainer,
    XAxis,
    YAxis,
} from 'recharts';

type RwRow = { rw: string; total: number; success: number; rts?: Record<string, { rt: string; total: number; success: number }> };
type HistRow = { date: string; label: string; success: number; target: number; pct: number };
type DraftRow = {
    id: number | string;
    source?: string;
    status: string;
    status_label: string;
    sheet_status?: string;
    full_name: string | null;
    rt: string | null;
    rw: string | null;
    odp: string | null;
    daerah: string | null;
    phone: string | null;
    sn: string | null;
    pppoe_user: string | null;
    sender_name: string | null;
    service_type?: string | null;
    tikor?: string | null;
    vlan?: string | number | null;
    pon_port?: string | null;
    created_at: string | null;
    work_date?: string | null;
    olt_at: string | null;
};

type SiteOpt = { key: string; name: string; mode?: string; daily_target?: number };
type AreaOpt = { id: number; name: string };
type SiteRow = {
    site_key: string;
    name: string;
    input: number;
    success: number;
    input_today?: number;
    success_today?: number;
    daily_target?: number;
    target_daily?: number;
    target_period?: number;
    target?: number;
    day_count?: number;
    pct?: number;
    pct_today?: number;
    pct_period?: number;
    vendor?: string;
};
type AreaRow = { area_id: number | null; area_name: string; input: number; success: number; success_today?: number };
type CsrSummary = { input: number; success: number; success_today: number; pending: number; failed: number };

type Starlite = {
    ready: boolean;
    target: number;
    last_update: string;
    total_input: number;
    success_today: number;
    success_total?: number;
    pending: number;
    failed: number;
    waiting_photo?: number;
    pct_today: number;
    by_rw: RwRow[];
    by_site?: SiteRow[];
    by_area?: AreaRow[];
    csr?: CsrSummary;
    by_source?: { wa?: number; excel?: number; sheet?: number; other?: number; manual?: number };
    history: HistRow[];
    recent_rows: DraftRow[];
    rows_shown?: number;
    rows_total?: number;
    success_definition?: string;
    excel_count?: number;
    draft_count?: number;
    project_title?: string;
    project_sub?: string;
    cluster?: string;
    note?: string;
    csv_columns?: string[];
    sites?: SiteOpt[];
    areas?: AreaOpt[];
    filters?: { site?: string | null; area_id?: number | null; from?: string; to?: string };
    input_today?: number;
    target_daily?: number;
    target_period?: number;
    day_count?: number;
    pct_period?: number;
};

type Props = { starlite: Starlite; filters?: { site?: string | null; area_id?: number | null; from?: string; to?: string } };

function pctColor(pct: number) {
    if (pct >= 90) return 'bg-status-up-bg text-status-up';
    if (pct >= 70) return 'bg-status-degraded-bg text-status-degraded';
    return 'bg-status-down-bg text-status-down';
}

function normalizeStatus(label: string) {
    return (label || '').trim().toUpperCase().replace(/[\s-]+/g, '_');
}

function statusTone(label: string): StatusTone {
    const status = normalizeStatus(label);
    if (status === 'SUCCESS' || status === 'OLT_OK' || status === 'APPLIED') return 'up';
    if (status === 'FAILED' || status === 'TIMEOUT' || status === 'CANCELLED') return 'down';
    if (status === 'SECRET_OK' || status === 'PENDING' || status === 'RECEIVED' || status === 'WAITING_SN' || status === 'PENDING_VLAN') {
        return 'degraded';
    }
    return 'neutral';
}

const psbFlowColumns: Array<{ key: string; label: string; statuses: string[]; tone: StatusTone }> = [
    { key: 'inbox', label: 'Masuk', statuses: ['RECEIVED', 'PENDING', 'WAITING_SN', 'PENDING_VLAN'], tone: 'info' },
    { key: 'review', label: 'Verifikasi', statuses: ['WAITING_PHOTO', 'DOCUMENT_PENDING', 'FAILED', 'TIMEOUT', 'CORRECTION_PENDING_REVIEW'], tone: 'degraded' },
    { key: 'provisioning', label: 'Provisioning', statuses: ['SECRET_OK', 'OLT_OK'], tone: 'info' },
    { key: 'done', label: 'Selesai', statuses: ['SUCCESS', 'APPLIED'], tone: 'up' },
];

export default function Dashboard({ starlite, filters: filtersProp }: Props) {
    const s = starlite;
    const [importing, setImporting] = useState(false);
    const [importConfirmOpen, setImportConfirmOpen] = useState(false);
    const [showManageTargets, setShowManageTargets] = useState(false);
    const [targetForm, setTargetForm] = useState<Record<string, number>>({});
    const page = usePage();
    const flash = (page.props as { flash?: { success?: string; error?: string } }).flash;
    const f = s.filters || filtersProp || {};
    const [site, setSite] = useState<string>(f.site || 'ra');
    const [areaId, setAreaId] = useState<string>(f.area_id ? String(f.area_id) : 'all');
    const [from, setFrom] = useState<string>(f.from || '');
    const [to, setTo] = useState<string>(f.to || '');
    const [expandedRw, setExpandedRw] = useState<Record<string, boolean>>({});

    function applyFilters() {
        router.get(
            route('psb-drafts.dashboard'),
            {
                site: site === 'all' ? undefined : site,
                area_id: areaId === 'all' ? undefined : areaId,
                from: from || undefined,
                to: to || undefined,
            },
            { preserveState: false, preserveScroll: true },
        );
    }

    function importSheet() {
        setImporting(true);
        router.post(
            route('psb-drafts.import-starlite'),
            {},
            {
                preserveScroll: true,
                onFinish: () => {
                    setImporting(false);
                    setImportConfirmOpen(false);
                },
            },
        );
    }

    // RW aktif dari payload KPI; nol/RT kosong tidak dipaksa tampil.
    const rwLookup: Record<string, RwRow> = {};
    for (const r of s.by_rw) {
        const digits = (r.rw.match(/\d+/) || [r.rw])[0].padStart(2, '0');
        rwLookup[digits] = r;
    }
    const rwWanted = Object.keys(rwLookup).sort((a, b) => a.localeCompare(b, undefined, { numeric: true }));

    /** Grafik harian: 14 hari terakhir (batang lebar, label tidak nabrak) */
    const dailyChartData = useMemo(() => {
        const slice = s.history.length > 14 ? s.history.slice(-14) : s.history;
        return slice.map((h) => {
            // label: 15/07 → 15 Jul
            const parts = h.label.split('/');
            const dd = parts[0] || '';
            const mm = parts[1] || '';
            const mon = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'][
                Number(mm) || 0
            ];
            return {
                label: `${Number(dd)} ${mon}`,
                fullLabel: h.label,
                Success: h.success,
                Target: h.target,
                pct: h.pct,
            };
        });
    }, [s.history]);

    /** Grafik RW horizontal: Total + Success + % */
    const rwChartData = useMemo(
        () =>
            rwWanted.map((rw) => {
                const total = rwLookup[rw]?.total ?? 0;
                const success = rwLookup[rw]?.success ?? 0;
                const rate = total > 0 ? Math.round((success / total) * 100) : 0;
                return {
                    name: `RW ${rw}`,
                    Total: total,
                    Success: success,
                    rate,
                };
            }),
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [s.by_rw],
    );

    const maxDaily = Math.max(s.target, ...dailyChartData.map((h) => h.Success), 4);
    const maxRw = Math.max(1, ...rwChartData.map((r) => Math.max(r.Total, r.Success)));
    const psbFlow = useMemo(
        () => psbFlowColumns.map((column) => ({
            ...column,
            rows: s.recent_rows.filter((row) => column.statuses.includes(normalizeStatus(row.sheet_status || row.status_label || row.status))),
        })),
        [s.recent_rows],
    );

    function barFill(pct: number, success: number) {
        if (success <= 0) return 'var(--color-muted-foreground)';
        if (pct >= 90) return 'var(--color-status-up)';
        if (pct >= 70) return 'var(--color-status-degraded)';
        return 'var(--color-status-down)';
    }

    return (
        <EnterpriseIndexLayout
            title="Skynet KPI"
            pageTitle="KPI PSB · Multi-site"
            subtitle="WA + manual + Excel · filter site/area. Read-only."
            icon={UserPlus}
            breadcrumbs={[
                { label: 'Operasi', href: route('psb-imports.index') },
                { label: 'PSB WA KPI' },
            ]}
            actions={
                <div className="flex flex-wrap items-center gap-2">
                    <Button variant="outline" size="sm" className="h-9" asChild>
                        <Link href={route('psb-imports.index')}>Menu PSB</Link>
                    </Button>
                    <Button variant="outline" size="sm" className="h-9" asChild>
                        <Link href={route('oltc300.index', { tab: 'psb' })}>Aktivasi PSB WA</Link>
                    </Button>
                    <Button variant="outline" size="sm" className="h-9 gap-1.5" onClick={() => setImportConfirmOpen(true)} disabled={importing}>
                        <RefreshCw className={`h-3.5 w-3.5 ${importing ? 'animate-spin' : ''}`} />
                        Import Excel/Sheet
                    </Button>
                    <Button size="sm" className="h-9 gap-1.5" asChild>
                        <a href={route('psb-drafts.export')}>
                            <Download className="h-3.5 w-3.5" />
                            Export CSV
                        </a>
                    </Button>
                </div>
            }
        >
            <Head title="KPI PSB Dashboard" />

            <div className="space-y-4">
                <Card className="border-border/80">
                    <CardContent className="flex flex-wrap items-end gap-3 pt-4">
                        <div className="space-y-1">
                            <label className="text-xs text-muted-foreground">Site</label>
                            <select
                                className="h-9 rounded-md border bg-background px-2 text-sm min-w-[140px]"
                                value={site}
                                onChange={(e) => setSite(e.target.value)}
                            >
                                <option value="all">Semua site</option>
                                {(s.sites || []).map((x) => (
                                    <option key={x.key} value={x.key}>
                                        {x.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="space-y-1">
                            <label className="text-xs text-muted-foreground">Area</label>
                            <select
                                className="h-9 rounded-md border bg-background px-2 text-sm min-w-[160px]"
                                value={areaId}
                                onChange={(e) => setAreaId(e.target.value)}
                            >
                                <option value="all">Semua area</option>
                                {(s.areas || []).map((a) => (
                                    <option key={a.id} value={String(a.id)}>
                                        {a.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="space-y-1">
                            <label className="text-xs text-muted-foreground">Dari</label>
                            <input type="date" className="h-9 rounded-md border bg-background px-2 text-sm" value={from} onChange={(e) => setFrom(e.target.value)} />
                        </div>
                        <div className="space-y-1">
                            <label className="text-xs text-muted-foreground">Sampai</label>
                            <input type="date" className="h-9 rounded-md border bg-background px-2 text-sm" value={to} onChange={(e) => setTo(e.target.value)} />
                        </div>
                        <Button size="sm" className="h-9" onClick={applyFilters}>
                            Terapkan filter
                        </Button>
                    </CardContent>
                </Card>

                {/* Target Management Modal / Section */}
                {showManageTargets && (
                    <Card className="border-primary/30 bg-primary-soft/40 shadow-md">
                        <CardHeader className="pb-3 pt-4">
                            <div className="flex items-center justify-between">
                                <CardTitle className="text-sm font-semibold flex items-center gap-2">
                                    <Settings2 className="h-4 w-4" aria-hidden="true" />
                                    Kelola Target KPI Harian per Site
                                </CardTitle>
                                <Button size="sm" variant="ghost" className="h-7 text-xs" onClick={() => setShowManageTargets(false)}>
                                    Batal / Tutup
                                </Button>
                            </div>
                            <CardDescription className="text-xs">
                                Atur target harian per site. Isi <b>0</b> jika site tersebut belum/tidak memiliki target KPI (monitoring saja).
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                                {(s.by_site || []).map((site) => (
                                    <div key={site.site_key} className="space-y-1 bg-background p-2.5 rounded-lg border">
                                        <label className="text-xs font-medium block truncate" title={site.name}>
                                            {site.name}
                                        </label>
                                        <div className="flex items-center gap-2">
                                            <input
                                                type="number"
                                                min="0"
                                                max="500"
                                                className="w-full h-8 text-xs font-mono px-2 rounded border bg-background"
                                                value={targetForm[site.site_key] ?? site.daily_target ?? 0}
                                                onChange={(e) =>
                                                    setTargetForm((prev) => ({
                                                        ...prev,
                                                        [site.site_key]: Math.max(0, parseInt(e.target.value) || 0),
                                                    }))
                                                }
                                            />
                                            <span className="text-[10px] text-muted-foreground shrink-0">/hari</span>
                                        </div>
                                    </div>
                                ))}
                            </div>
                            <div className="flex justify-end gap-2 pt-2 border-t">
                                <Button
                                    size="sm"
                                    className="h-8 text-xs"
                                    onClick={() => {
                                        router.post(
                                            route('psb-drafts.targets'),
                                            { targets: targetForm },
                                            {
                                                preserveScroll: true,
                                                onSuccess: () => setShowManageTargets(false),
                                            },
                                        );
                                    }}
                                >
                                    Simpan Target KPI
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* KPI per site — Randuagung & Active KPI Sites Only */}
                {s.by_site && s.by_site.length > 0 && (() => {
                    const activeKpi = s.by_site.filter((r) => (r.daily_target ?? 0) > 0);

                    return (
                        <section className="space-y-6">
                            {/* Active KPI Sites (Randuagung C300 / Project Active) */}
                            <div className="space-y-3">
                                <div className="flex flex-wrap items-center justify-between gap-2 border-b pb-2">
                                    <div>
                                        <h3 className="text-base font-semibold tracking-tight flex items-center gap-2">
                                            <Target className="h-4 w-4" aria-hidden="true" />
                                            Project dengan Target KPI Active
                                        </h3>
                                        <p className="text-xs text-muted-foreground mt-0.5">
                                            Site terpilih dengan target pencapaian harian aktif.
                                        </p>
                                    </div>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        className="h-8 text-xs gap-1.5"
                                        onClick={() => {
                                            const initial: Record<string, number> = {};
                                            s.by_site?.forEach((st) => {
                                                initial[st.site_key] = st.daily_target ?? 0;
                                            });
                                            setTargetForm(initial);
                                            setShowManageTargets(!showManageTargets);
                                        }}
                                    >
                                        <Settings2 className="h-3.5 w-3.5" aria-hidden="true" />
                                        Kelola Target KPI Site
                                    </Button>
                                </div>

                                {activeKpi.length > 0 ? (
                                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                                        {activeKpi.map((r) => {
                                            const daily = r.daily_target ?? 0;
                                            const periodT = r.target_period ?? daily * (s.day_count ?? 1);
                                            const st = r.success_today ?? 0;
                                            const sp = r.success ?? 0;
                                            const pctT = r.pct_today ?? (daily > 0 ? Math.round((st / daily) * 1000) / 10 : 0);
                                            const pctP = r.pct_period ?? (periodT > 0 ? Math.round((sp / periodT) * 1000) / 10 : 0);
                                            const bar = Math.min(100, pctT);
                                            const barColor =
                                                pctT >= 90 ? 'bg-status-up' : pctT >= 50 ? 'bg-status-degraded' : pctT > 0 ? 'bg-status-info' : 'bg-muted';

                                            return (
                                                <Card key={r.site_key} className="border-border shadow-sm overflow-hidden bg-card">
                                                    <CardHeader className="pb-2 pt-4 px-4 space-y-1">
                                                        <div className="flex items-start justify-between gap-2">
                                                            <CardTitle className="text-sm font-semibold leading-snug">
                                                                {r.name || r.site_key}
                                                            </CardTitle>
                                                            <Badge variant="accent" className="text-[10px] shrink-0 font-mono">
                                                                Target {daily}/hari
                                                            </Badge>
                                                        </div>
                                                    </CardHeader>
                                                    <CardContent className="px-4 pb-4 space-y-3">
                                                        <div>
                                                            <div className="flex justify-between text-xs mb-1">
                                                                <span className="text-muted-foreground">Hari ini</span>
                                                                <span className="font-mono font-semibold">
                                                                    {st}/{daily}{' '}
                                                                    <span className={pctT >= 90 ? 'text-status-up' : pctT >= 50 ? 'text-status-degraded' : 'text-muted-foreground'}>
                                                                        ({pctT}%)
                                                                    </span>
                                                                </span>
                                                            </div>
                                                            <div className="h-2 rounded-full bg-muted overflow-hidden">
                                                                <div className={`h-full rounded-full transition-all ${barColor}`} style={{ width: `${bar}%` }} />
                                                            </div>
                                                        </div>
                                                        <div className="grid grid-cols-3 gap-2 text-center text-xs border-t pt-3">
                                                            <div>
                                                                <p className="text-muted-foreground">Input</p>
                                                                <p className="font-mono font-semibold tabular-nums">{r.input}</p>
                                                            </div>
                                                            <div>
                                                                <p className="text-muted-foreground">Sukses</p>
                                                                <p className="font-mono font-semibold tabular-nums text-status-up">{sp}</p>
                                                            </div>
                                                            <div>
                                                                <p className="text-muted-foreground">% periode</p>
                                                                <p className="font-mono font-semibold tabular-nums">{pctP}%</p>
                                                            </div>
                                                        </div>
                                                        <p className="text-[10px] text-muted-foreground leading-relaxed">
                                                            Target periode {periodT} ({daily}×{r.day_count ?? s.day_count ?? 1} hari)
                                                        </p>
                                                    </CardContent>
                                                </Card>
                                            );
                                        })}
                                    </div>
                                ) : (
                                    <div className="p-4 rounded-lg border border-dashed text-center text-xs text-muted-foreground">
                                        Belum ada site dengan target KPI aktif. Klik tombol <b>Kelola Target KPI Site</b> di atas untuk mengaktifkan target per site.
                                    </div>
                                )}
                            </div>
                        </section>
                    );
                })()}

                {(s.by_area && s.by_area.length > 0) && (
                    <Card className="border-border/80">
                        <CardHeader className="pb-2">
                            <CardTitle className="text-base">KPI per area</CardTitle>
                            <CardDescription>Wilayah pelanggan (bukan site MT/OLT). Urut sukses terbanyak.</CardDescription>
                        </CardHeader>
                        <CardContent className="overflow-x-auto max-h-72 overflow-y-auto">
                            <table className="w-full text-sm">
                                <thead className="text-xs text-muted-foreground sticky top-0 bg-card z-10">
                                    <tr className="border-b">
                                        <th className="text-left py-2 pr-2 font-medium">Area</th>
                                        <th className="text-right py-2 px-2 font-medium">Input</th>
                                        <th className="text-right py-2 px-2 font-medium">Sukses</th>
                                        <th className="text-right py-2 pl-2 font-medium">Hari ini</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {s.by_area.slice(0, 40).map((r, i) => (
                                        <tr key={`${r.area_id ?? 'n'}-${i}`} className="border-t border-border/60">
                                            <td className="py-2 pr-2">{r.area_name}</td>
                                            <td className="py-2 px-2 text-right font-mono tabular-nums">{r.input}</td>
                                            <td className="py-2 px-2 text-right font-mono tabular-nums text-status-up">{r.success}</td>
                                            <td className="py-2 pl-2 text-right font-mono tabular-nums">{r.success_today ?? '—'}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </CardContent>
                    </Card>
                )}

                {(s.csr && s.csr.input > 0) && (
                    <Card className="border-status-info/30 bg-status-info-bg">
                        <CardHeader className="pb-2">
                            <CardTitle className="text-base">CSR / Sekolah / Institusi</CardTitle>
                            <CardDescription>Tidak masuk KPI RW/RT reguler.</CardDescription>
                        </CardHeader>
                        <CardContent className="grid grid-cols-2 gap-3 text-sm sm:grid-cols-5">
                            <div><p className="text-xs text-muted-foreground">Input</p><p className="font-mono font-semibold">{s.csr.input}</p></div>
                            <div><p className="text-xs text-muted-foreground">Sukses</p><p className="font-mono font-semibold text-status-up">{s.csr.success}</p></div>
                            <div><p className="text-xs text-muted-foreground">Hari ini</p><p className="font-mono font-semibold">{s.csr.success_today}</p></div>
                            <div><p className="text-xs text-muted-foreground">Pending</p><p className="font-mono font-semibold text-status-degraded">{s.csr.pending}</p></div>
                            <div><p className="text-xs text-muted-foreground">Gagal</p><p className="font-mono font-semibold text-status-down">{s.csr.failed}</p></div>
                        </CardContent>
                    </Card>
                )}

                {(flash?.success || flash?.error) && (
                    <div
                        className={`rounded-lg border px-4 py-3 text-sm ${
                            flash.error
                                ? 'border-status-down/30 bg-status-down-bg text-status-down'
                                : 'border-status-up/30 bg-status-up-bg text-status-up'
                        }`}
                    >
                        {flash.error || flash.success}
                    </div>
                )}

                <Card className="border-border/80">
                    <CardHeader className="pb-3">
                        <CardTitle className="text-base">Alur draft PSB</CardTitle>
                        <CardDescription>Status read-only dari data terbaru; eksekusi tetap melalui alur PSB yang sudah ada.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                            {psbFlow.map((column) => (
                                <section key={column.key} className="min-w-0 rounded-lg border border-border bg-muted/20 p-3" aria-labelledby={`psb-flow-${column.key}`}>
                                    <div className="mb-3 flex items-center justify-between gap-2">
                                        <h3 id={`psb-flow-${column.key}`} className="text-sm font-semibold">{column.label}</h3>
                                        <StatusBadge label={String(column.rows.length)} tone={column.tone} showIcon={false} className="px-2 py-0.5 text-[11px]" />
                                    </div>
                                    <div className="space-y-2">
                                        {column.rows.slice(0, 4).map((row) => {
                                            const label = row.sheet_status || row.status_label || row.status;

                                            return (
                                                <div key={`${column.key}-${row.source}-${row.id}`} className="rounded-md border border-border bg-card p-2">
                                                    <div className="flex items-start justify-between gap-2">
                                                        <p className="min-w-0 truncate text-xs font-medium">{row.full_name || 'Nama belum tersedia'}</p>
                                                        <StatusBadge label={label} tone={statusTone(label)} showIcon={false} className="shrink-0 px-1.5 py-0.5 text-[10px]" />
                                                    </div>
                                                    <p className="mt-1 truncate font-mono text-[11px] text-muted-foreground">{row.pppoe_user || 'PPPoE belum tersedia'}</p>
                                                </div>
                                            );
                                        })}
                                        {column.rows.length === 0 && <p className="rounded-md border border-dashed border-border px-2 py-4 text-center text-xs text-muted-foreground">Belum ada draft.</p>}
                                        {column.rows.length > 4 && <p className="text-[11px] text-muted-foreground">+{column.rows.length - 4} draft lain di tabel detail.</p>}
                                    </div>
                                </section>
                            ))}
                        </div>
                    </CardContent>
                </Card>

                {/* Excel-like title banner */}
                <div className="rounded-xl border border-border bg-primary-soft px-5 py-4 text-primary-soft-foreground shadow-sm">
                    <p className="text-[11px] font-semibold uppercase tracking-[0.2em] text-primary-soft-foreground/80">Skynet · KPI PSB</p>
                    <h2 className="mt-1 text-lg font-bold tracking-tight sm:text-xl">
                        {s.project_title || 'SKYNET PROJECT - PERFORMANCE DASHBOARD'}
                    </h2>
                    <p className="mt-0.5 text-sm text-primary-soft-foreground">
                        {s.project_sub || 'Ringkasan Performa & Persentase Harian Progres'}
                    </p>
                    <p className="mt-2 text-xs text-primary-soft-foreground">
                        {s.cluster || 'Cluster PSB'} · Last update: {s.last_update} · Excel rows:{' '}
                        {s.excel_count ?? 0} · WA drafts: {s.draft_count ?? 0}
                        {s.by_source
                            ? ` · Unified WA ${s.by_source.wa ?? 0} / Excel ${(s.by_source.excel ?? 0) + (s.by_source.sheet ?? 0)}`
                            : ''}
                        {typeof s.waiting_photo === 'number' && s.waiting_photo > 0
                            ? ` · Menunggu foto: ${s.waiting_photo}`
                            : ''}
                    </p>
                    {s.success_definition && (
                        <p className="mt-2 rounded-md bg-background/60 px-2 py-1.5 text-[11px] leading-relaxed text-foreground">
                            {s.success_definition}
                        </p>
                    )}
                </div>

                {/* Excel-like two column: KPI left + History right */}
                <div className="grid gap-4 lg:grid-cols-12">
                    {/* LEFT KPI panel like Excel cols B–F */}
                    <div className="lg:col-span-5">
                        <div className="overflow-hidden rounded-xl border border-border bg-card shadow-sm">
                            <table className="w-full text-sm">
                                <tbody>
                                    <tr className="border-b bg-muted/50">
                                        <td className="px-3 py-2 font-medium text-foreground" colSpan={2}>
                                            Target Kerja Harian Skynet
                                        </td>
                                        <td className="px-3 py-2 text-right text-xl font-bold tabular-nums text-primary">
                                            {s.target}
                                        </td>
                                        <td className="px-3 py-2 text-xs text-muted-foreground">Unit / Hari</td>
                                    </tr>
                                    <tr className="border-b">
                                        <td className="px-3 py-2" colSpan={2}>
                                            Total Inputan Data Masuk Keseluruhan
                                        </td>
                                        <td className="px-3 py-2 text-right text-lg font-semibold tabular-nums">
                                            {s.total_input}
                                        </td>
                                        <td className="px-3 py-2 text-xs text-muted-foreground">User</td>
                                    </tr>
                                    <tr className="border-b bg-status-up-bg">
                                        <td className="px-3 py-2" colSpan={2}>
                                            Total Status SUCCESS (per hari ini)
                                        </td>
                                        <td className="px-3 py-2 text-right text-lg font-bold tabular-nums text-status-up">
                                            {s.success_today}
                                        </td>
                                        <td className="px-3 py-2 text-xs text-muted-foreground">1 PPPoE</td>
                                    </tr>
                                    <tr className="border-b">
                                        <td className="px-3 py-2" colSpan={2}>
                                            Total SUCCESS (semua baris unified)
                                        </td>
                                        <td className="px-3 py-2 text-right text-lg font-semibold tabular-nums">
                                            {s.success_total ?? '—'}
                                        </td>
                                        <td className="px-3 py-2 text-xs text-muted-foreground">1 PPPoE</td>
                                    </tr>
                                    <tr className="border-b">
                                        <td className="px-3 py-2" colSpan={2}>
                                            Pending / nunggu (SN·VLAN·foto)
                                        </td>
                                        <td className="px-3 py-2 text-right text-lg font-semibold tabular-nums text-status-degraded">
                                            {s.pending}
                                            {typeof s.waiting_photo === 'number' && s.waiting_photo > 0
                                                ? ` (+foto ${s.waiting_photo})`
                                                : ''}
                                        </td>
                                        <td className="px-3 py-2 text-xs text-muted-foreground">Draft</td>
                                    </tr>
                                    <tr className="border-b bg-status-degraded-bg">
                                        <td className="px-3 py-2" colSpan={2}>
                                            Total Status PENDING (Backlog)
                                        </td>
                                        <td className="px-3 py-2 text-right text-lg font-semibold tabular-nums text-status-degraded">
                                            {s.pending}
                                        </td>
                                        <td className="px-3 py-2 text-xs text-muted-foreground">User</td>
                                    </tr>
                                    {rwWanted.map((rw) => {
                                        const row = rwLookup[rw];
                                        const hasRts = row?.rts && Object.keys(row.rts).length > 0;
                                        return (
                                            <Fragment key={rw}>
                                                <tr className="border-b border-border">
                                                    <td className="px-3 py-1.5" colSpan={2}>
                                                        <button
                                                            type="button"
                                                            className="flex w-full items-center gap-1.5 text-left font-medium hover:text-foreground"
                                                            aria-expanded={Boolean(expandedRw[rw])}
                                                            onClick={() => setExpandedRw((current) => ({ ...current, [rw]: !current[rw] }))}
                                                        >
                                                            {expandedRw[rw] ? <ChevronDown className="h-4 w-4" /> : <ChevronRight className="h-4 w-4" />}
                                                            <span>{rw === '(kosong)' ? 'RW perlu review' : `Total RW ${rw}`}</span>
                                                            {hasRts && <span className="text-xs font-normal text-muted-foreground">({Object.keys(row.rts!).length} RT)</span>}
                                                        </button>
                                                    </td>
                                                    <td className="px-3 py-1.5 text-right tabular-nums font-medium">
                                                        {row?.total ?? 0}
                                                    </td>
                                                    <td className="px-3 py-1.5 text-xs text-muted-foreground">
                                                        ok {row?.success ?? 0}
                                                    </td>
                                                </tr>
                                                {expandedRw[rw] && hasRts && Object.values(row.rts!).map((rt) => (
                                                    <tr key={`${rw}-${rt.rt}`} className="border-b border-border bg-muted/50 text-xs">
                                                        <td className="px-3 py-1.5 pl-8 text-muted-foreground" colSpan={2}>
                                                            {rt.rt && rt.rt !== '(kosong)' ? `RT ${rt.rt.replace(/\D/g, '').padStart(2, '0')}` : 'RT perlu review'}
                                                        </td>
                                                        <td className="px-3 py-1.5 text-right text-xs font-semibold tabular-nums">
                                                            {rt.total}
                                                        </td>
                                                        <td className="px-3 py-1.5 text-xs text-muted-foreground">
                                                            ok {rt.success}
                                                        </td>
                                                    </tr>
                                                ))}
                                            </Fragment>
                                        );
                                    })}
                                    <tr className="border-t-2 border-border">
                                        <td className="px-3 py-2.5 font-semibold" colSpan={2}>
                                            PERSENTASE PROGRES HARIAN (vs Target)
                                        </td>
                                        <td className="px-3 py-2.5 text-right">
                                            <span
                                                className={`inline-block min-w-[4.5rem] rounded-md px-2 py-1 text-lg font-bold tabular-nums ${pctColor(s.pct_today)}`}
                                            >
                                                {s.pct_today.toFixed(1).replace('.', ',')}%
                                            </span>
                                        </td>
                                        <td className="px-3 py-2.5 text-xs text-muted-foreground">%</td>
                                    </tr>
                                </tbody>
                            </table>
                            <div className="border-t bg-muted/50 px-3 py-2 text-[11px] leading-relaxed text-muted-foreground">
                                <p className="font-semibold text-foreground">
                                    Indikator Persentase Progres Harian:
                                </p>
                                <ol className="mt-1 list-decimal space-y-0.5 pl-4">
                                    <li>Dihitung dari SUCCESS hari ini ÷ Target harian ({s.target}).</li>
                                    <li>
                                        Standar lulus ≥ <strong>90%</strong> (hijau). Di bawah <strong>70%</strong> =
                                        merah.
                                    </li>
                                    <li>
                                        SUCCESS = status Excel Success + draft WA <code>olt_ok</code>/
                                        <code>applied</code>. SN sama: data WA menang.
                                    </li>
                                </ol>
                            </div>
                        </div>
                    </div>

                    {/* RIGHT history like Excel H–J */}
                    <div className="lg:col-span-7">
                        <div className="overflow-hidden rounded-xl border border-border bg-card shadow-sm">
                            <div className="border-b bg-muted px-3 py-2 text-sm font-semibold text-foreground">
                                HISTORI PROGRES HARIAN
                            </div>
                            <div className="max-h-[520px] overflow-auto">
                                <table className="w-full text-sm">
                                    <thead className="sticky top-0 bg-muted text-left text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                        <tr>
                                            <th className="px-3 py-2">Tanggal</th>
                                            <th className="px-3 py-2 text-right">Success</th>
                                            <th className="px-3 py-2 text-right">Target</th>
                                            <th className="px-3 py-2 text-right">%</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {[...s.history].reverse().map((h) => (
                                            <tr
                                                key={h.date}
                                                className="border-t border-border odd:bg-card even:bg-muted/40"
                                            >
                                                <td className="px-3 py-1.5 font-medium">{h.label}</td>
                                                <td className="px-3 py-1.5 text-right tabular-nums font-semibold text-status-up">
                                                    {h.success}
                                                </td>
                                                <td className="px-3 py-1.5 text-right tabular-nums text-muted-foreground">
                                                    {h.target}
                                                </td>
                                                <td className="px-3 py-1.5 text-right">
                                                    <span
                                                        className={`inline-block rounded px-1.5 py-0.5 text-xs font-bold tabular-nums ${pctColor(h.pct)}`}
                                                    >
                                                        {h.pct.toFixed(0)}%
                                                    </span>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                {/* Grafik Success harian — batang lebar, label angka, warna status */}
                <Card className="overflow-hidden border-border shadow-sm">
                    <CardHeader className="border-b bg-muted py-3 text-foreground sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <CardTitle className="text-base font-semibold tracking-wide">
                                Grafik Success harian vs Target
                            </CardTitle>
                            <CardDescription>
                                14 hari terakhir · Target {s.target} unit/hari · Hover batang untuk detail
                            </CardDescription>
                        </div>
                        <div className="mt-2 flex flex-wrap gap-2 text-[11px] sm:mt-0">
                            <span className="inline-flex items-center gap-1.5 rounded-full bg-card px-2.5 py-1 font-medium text-foreground ring-1 ring-border">
                                <span className="h-2.5 w-2.5 rounded-sm bg-status-up" />
                                ≥90% lulus
                            </span>
                            <span className="inline-flex items-center gap-1.5 rounded-full bg-card px-2.5 py-1 font-medium text-foreground ring-1 ring-border">
                                <span className="h-2.5 w-2.5 rounded-sm bg-status-degraded" />
                                70–89%
                            </span>
                            <span className="inline-flex items-center gap-1.5 rounded-full bg-card px-2.5 py-1 font-medium text-foreground ring-1 ring-border">
                                <span className="h-2.5 w-2.5 rounded-sm bg-status-down" />
                                &lt;70%
                            </span>
                            <span className="inline-flex items-center gap-1.5 rounded-full bg-card px-2.5 py-1 font-medium text-foreground ring-1 ring-border">
                                <span className="h-2.5 w-2.5 rounded-sm bg-muted-foreground" />
                                0
                            </span>
                        </div>
                    </CardHeader>
                    <CardContent className="bg-muted/30 pt-4">
                        {dailyChartData.length === 0 ? (
                            <p className="py-16 text-center text-sm text-muted-foreground">
                                Belum ada histori harian. Import Excel/Sheet dulu.
                            </p>
                        ) : (
                            <div className="w-full min-w-0 sm:h-[420px]">
                                <div className="w-full overflow-x-auto"><div style={{ minWidth: "760px", height: 400 }}>
                                    <BarChart width={760} height={350}
                                        data={dailyChartData}
                                        margin={{ top: 28, right: 20, left: 0, bottom: 4 }}
                                        barCategoryGap="20%"
                                    >
                                        <CartesianGrid
                                            strokeDasharray="4 4"
                                            vertical={false}
                                            stroke="var(--color-border)"
                                            strokeOpacity={0.7}
                                        />
                                        <XAxis
                                            dataKey="label"
                                            tick={{ fontSize: 12, fill: 'var(--color-foreground)', fontWeight: 700 }}
                                            tickLine={false}
                                            axisLine={{ stroke: '#94a3b8' }}
                                            interval={0}
                                            height={40}
                                            dy={6}
                                        />
                                        <YAxis
                                            allowDecimals={false}
                                            domain={[0, Math.ceil(maxDaily * 1.15) + 1]}
                                            tick={{ fontSize: 12, fill: 'var(--color-muted-foreground)', fontWeight: 600 }}
                                            tickLine={false}
                                            axisLine={false}
                                            width={32}
                                        />

                                        <ReferenceLine
                                            y={s.target}
                                            stroke="var(--color-muted-foreground)"
                                            strokeWidth={2}
                                            strokeDasharray="7 5"
                                            label={{
                                                value: `▲ Target ${s.target}`,
                                                position: 'insideTopRight',
                                                fill: 'var(--color-foreground)',
                                                fontSize: 12,
                                                fontWeight: 800,
                                            }}
                                        />
                                        <Bar
                                            dataKey="Success"
                                            name="Success"
                                            radius={[8, 8, 0, 0]}
                                            maxBarSize={48}
                                        >
                                            {dailyChartData.map((entry, i) => (
                                                <Cell
                                                    key={`day-${i}`}
                                                    fill={barFill(entry.pct, entry.Success)}
                                                    stroke={entry.Success > 0 ? 'rgba(0,0,0,0.06)' : 'transparent'}
                                                    strokeWidth={1}
                                                />
                                            ))}
                                            <LabelList
                                                dataKey="Success"
                                                position="top"
                                                offset={8}
                                                style={{
                                                    fontSize: 13,
                                                    fontWeight: 800,
                                                    fill: 'var(--color-foreground)',
                                                }}
                                                formatter={(v: unknown) => (Number(v) > 0 ? String(v) : '')}
                                            />
                                        </Bar>
                                    </BarChart>
                                </div></div>
                            </div>
                        )}
                        <div className="mt-2 flex flex-wrap items-center justify-center gap-x-4 gap-y-1 border-t border-border pt-3 text-sm">
                            <span className="text-muted-foreground">Hari ini</span>
                            <span className="tabular-nums font-bold text-foreground">
                                {s.success_today} / {s.target}
                            </span>
                            <span
                                className={`rounded-md px-2 py-0.5 text-sm font-bold tabular-nums ${pctColor(s.pct_today)}`}
                            >
                                {s.pct_today.toFixed(1).replace('.', ',')}%
                            </span>
                        </div>
                    </CardContent>
                </Card>

                {/* Grafik RW horizontal — Total vs Success, angka di ujung batang */}
                <Card className="overflow-hidden border-border shadow-sm">
                    <CardHeader className="border-b bg-muted py-3 text-foreground">
                        <CardTitle className="text-base font-semibold tracking-wide">
                            Grafik total input per RW (04–13)
                        </CardTitle>
                        <CardDescription>
                            Batang biru = total input · hijau = success · angka di ujung batang
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="bg-muted/30 pt-4">
                        <div className="w-full min-w-0 sm:h-[440px]">
                            <div className="w-full overflow-x-auto"><div style={{ minWidth: "760px", height: 420 }}>
                                <BarChart width={760} height={350}
                                    layout="vertical"
                                    data={rwChartData}
                                    margin={{ top: 8, right: 56, left: 4, bottom: 4 }}
                                    barGap={3}
                                    barCategoryGap="18%"
                                >
                                    <CartesianGrid
                                        strokeDasharray="4 4"
                                        horizontal={false}
                                        stroke="var(--color-border)"
                                        strokeOpacity={0.7}
                                    />
                                    <XAxis
                                        type="number"
                                        allowDecimals={false}
                                        domain={[0, Math.ceil(maxRw * 1.2) + 1]}
                                        tick={{ fontSize: 12, fill: 'var(--color-muted-foreground)', fontWeight: 600 }}
                                        tickLine={false}
                                        axisLine={{ stroke: '#94a3b8' }}
                                    />
                                    <YAxis
                                        type="category"
                                        dataKey="name"
                                        width={62}
                                        tick={{ fontSize: 13, fill: 'var(--color-foreground)', fontWeight: 800 }}
                                        tickLine={false}
                                        axisLine={false}
                                    />

                                    <Legend
                                        verticalAlign="top"
                                        align="right"
                                        height={30}
                                        iconType="circle"
                                        iconSize={10}
                                        wrapperStyle={{ fontSize: 13, fontWeight: 700, paddingBottom: 4 }}
                                    />
                                    <Bar
                                        dataKey="Total"
                                        name="Total input"
                                        fill="var(--color-status-info)"
                                        radius={[0, 6, 6, 0]}
                                        maxBarSize={18}
                                    >
                                        <LabelList
                                            dataKey="Total"
                                            position="right"
                                            offset={6}
                                            style={{ fontSize: 12, fontWeight: 800, fill: 'var(--color-status-info)' }}
                                            formatter={(v: unknown) => (Number(v) > 0 ? String(v) : '')}
                                        />
                                    </Bar>
                                    <Bar
                                        dataKey="Success"
                                        name="Success"
                                        fill="var(--color-status-up)"
                                        radius={[0, 6, 6, 0]}
                                        maxBarSize={18}
                                    >
                                        <LabelList
                                            dataKey="Success"
                                            position="right"
                                            offset={6}
                                            style={{ fontSize: 12, fontWeight: 800, fill: 'var(--color-status-up)' }}
                                            formatter={(v: unknown) => (Number(v) > 0 ? String(v) : '')}
                                        />
                                    </Bar>
                                </BarChart>
                            </div></div>
                        </div>
                    </CardContent>
                </Card>

                {/* Daily Tracker Detail table — Excel sheet 2 columns */}
                <div className="overflow-hidden rounded-xl border border-border bg-card shadow-sm">
                    <div className="flex flex-wrap items-center justify-between gap-2 border-b bg-muted px-3 py-2 text-foreground">
                        <div>
                            <p className="text-sm font-semibold">SKYNET · DAILY TRACKER DETAIL</p>
                            <p className="text-[11px] text-muted-foreground">
                                Selaras export CSV · 1 PPPoE = 1 baris · Excel + WA live (tanpa double SN)
                            </p>
                        </div>
                        <Badge variant="accent">
                            {s.rows_shown ?? s.recent_rows.length}
                            {typeof s.rows_total === 'number' && s.rows_total !== (s.rows_shown ?? s.recent_rows.length)
                                ? ` / ${s.rows_total}`
                                : ''}{' '}
                            baris (urut terbaru)
                        </Badge>
                    </div>
                    <div className="overflow-auto">
                        <table className="w-full min-w-[1400px] text-left text-xs sm:text-sm">
                            <thead className="bg-muted text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                                <tr>
                                    <th className="px-2 py-2">No</th>
                                    <th className="px-2 py-2">Sumber</th>
                                    <th className="px-2 py-2">Tanggal</th>
                                    <th className="px-2 py-2">RW</th>
                                    <th className="px-2 py-2">RT</th>
                                    <th className="px-2 py-2">Nama Pelanggan</th>
                                    <th className="px-2 py-2">Nama PPPoE</th>
                                    <th className="px-2 py-2">SN</th>
                                    <th className="px-2 py-2">Jenis</th>
                                    <th className="px-2 py-2">Status</th>
                                    <th className="px-2 py-2">Koordinat</th>
                                    <th className="px-2 py-2">ODP/Port</th>
                                    <th className="px-2 py-2">Daerah</th>
                                    <th className="px-2 py-2">HP</th>
                                    <th className="px-2 py-2">VLAN</th>
                                    <th className="px-2 py-2">PON</th>
                                    <th className="px-2 py-2">PIC Teknisi</th>
                                    <th className="px-2 py-2">ID</th>
                                </tr>
                            </thead>
                            <tbody>
                                {s.recent_rows.length === 0 && (
                                    <tr>
                                        <td colSpan={18} className="px-3 py-10 text-center text-muted-foreground">
                                            Belum ada data. Klik <strong>Import Excel/Sheet</strong> untuk tarik
                                            histori sebelum tgl 20, atau jalankan bot #psb.
                                        </td>
                                    </tr>
                                )}
                                {s.recent_rows.map((r, idx) => (
                                    <tr
                                        key={`${r.source}-${r.id}-${r.sn || ''}-${r.pppoe_user || ''}-${idx}`}
                                        className="border-t border-border odd:bg-card even:bg-muted/40"
                                    >
                                        <td className="px-2 py-1.5 tabular-nums text-muted-foreground">{idx + 1}</td>
                                        <td className="px-2 py-1.5">
                                            <Badge variant="outline" className="text-[10px]">
                                                {r.source === 'excel' || r.source === 'sheet' ? 'Excel' : 'WA'}
                                            </Badge>
                                        </td>
                                        <td className="whitespace-nowrap px-2 py-1.5">
                                            {r.work_date || r.created_at || '—'}
                                        </td>
                                        <td className="px-2 py-1.5 font-medium">{r.rw || '—'}</td>
                                        <td className="px-2 py-1.5 font-medium">{r.rt || '—'}</td>
                                        <td className="max-w-[160px] truncate px-2 py-1.5 font-medium">
                                            {r.full_name || '—'}
                                        </td>
                                        <td className="max-w-[140px] truncate px-2 py-1.5 font-mono text-[11px]">
                                            {r.pppoe_user || '—'}
                                        </td>
                                        <td className="px-2 py-1.5 font-mono text-[11px]">{r.sn || '—'}</td>
                                        <td className="px-2 py-1.5">{r.service_type || 'PSB'}</td>
                                        <td className="px-2 py-1.5">
                                            {(() => {
                                                const label = r.sheet_status || r.status_label || r.status;

                                                return <StatusBadge label={label} tone={statusTone(label)} showIcon={false} className="px-1.5 py-0.5 text-[10px]" />;
                                            })()}
                                        </td>
                                        <td className="max-w-[130px] truncate px-2 py-1.5 font-mono text-[11px] text-muted-foreground">
                                            {r.tikor || '—'}
                                        </td>
                                        <td className="px-2 py-1.5 text-[11px]">{r.odp || '—'}</td>
                                        <td className="max-w-[100px] truncate px-2 py-1.5 text-[11px]">{r.daerah || '—'}</td>
                                        <td className="px-2 py-1.5 font-mono text-[11px]">{r.phone || '—'}</td>
                                        <td className="px-2 py-1.5 tabular-nums text-[11px]">
                                            {r.vlan != null && r.vlan !== '' ? r.vlan : '—'}
                                        </td>
                                        <td className="px-2 py-1.5 font-mono text-[11px]">{r.pon_port || '—'}</td>
                                        <td className="px-2 py-1.5 text-[11px]">{r.sender_name || '—'}</td>
                                        <td className="px-2 py-1.5 font-mono text-[11px] text-muted-foreground">
                                            {r.id}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>

                {s.note && <p className="text-xs text-muted-foreground">{s.note}</p>}
                {s.csv_columns && s.csv_columns.length > 0 && (
                    <p className="text-[11px] text-muted-foreground">
                        Kolom export CSV: {s.csv_columns.join(' · ')}
                    </p>
                )}
            </div>
            <ConfirmDestructive
                open={importConfirmOpen}
                onOpenChange={setImportConfirmOpen}
                title="Import ulang data KPI?"
                description="Data KPI Excel/Sheet akan dibaca ulang untuk memperbarui dashboard. Aktivasi MikroTik dan OLT tidak dijalankan oleh aksi ini."
                confirmationLabel="Saya memahami data KPI akan diperbarui."
                confirmText="Import data KPI"
                onConfirm={importSheet}
                isLoading={importing}
            />
        </EnterpriseIndexLayout>
    );
}
