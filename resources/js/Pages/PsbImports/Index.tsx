import { EnterpriseIndexLayout } from '@/Components/enterprise/EnterpriseIndexLayout';
import { EnterpriseMetricCard } from '@/Components/enterprise/EnterpriseMetricCard';
import { router, usePage } from '@inertiajs/react';
import { isGlobalAdminUser, type AuthUserLike } from '@/lib/navAccess';
import {
    CheckCircle2,
    RefreshCw,
    UserPlus,
    Upload,
    TrendingUp,
    CalendarDays,
    FileSpreadsheet,
    Users,
    ListChecks,
    AlertCircle,
    ArrowRight,
} from 'lucide-react';
import { useMemo, useRef, useState } from 'react';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import DataTable, { Column, FilterConfig, PaginatedData } from '@/Components/DataTable';
import { currentMonthWib } from '@/lib/dateTime';
import { formatIdr } from '@/lib/format';
import { ConfirmDestructive } from '@/Components/ConfirmDestructive';

type Candidate = {
    id: number;
    customer_code: string;
    pppoe_user: string | null;
    name: string;
    address: string | null;
    area_name: string | null;
    package_name: string | null;
    speed: string | null;
    price: number | null;
    status: string;
    match_status: string;
    match_notes: string | null;
    resolved_area: string | null;
    resolved_package: string | null;
    resolved_package_price: string | null;
    resolved_router: string | null;
    matched_customer_name: string | null;
    applied_customer_name: string | null;
    applied_at: string | null;
};

type ChartMonth = {
    month: string;
    label: string;
    join_count: number;
    applied_count: number;
    applied_extra?: number;
    total_count?: number;
};

type Props = {
    candidates: PaginatedData<Candidate>;
    filters: { status?: string; search?: string; year?: number };
    summary: {
        total: number;
        ready: number;
        needsMapping: number;
        applied: number;
        existing: number;
    };
    chart?: {
        year: number;
        years: number[];
        months: ChartMonth[];
        total_join: number;
        total_applied: number;
        total_sum?: number;
    };
    kpis?: {
        this_month: number;
        last_month: number;
        ytd: number;
        total: number;
        this_month_join?: number;
        this_month_applied?: number;
        this_month_extra?: number;
        last_month_join?: number;
        last_month_applied?: number;
        last_month_extra?: number;
        note?: string;
    };
};

const MONTH_FULL: Record<string, string> = {
    Jan: 'Januari',
    Feb: 'Februari',
    Mar: 'Maret',
    Apr: 'April',
    Mei: 'Mei',
    Jun: 'Juni',
    Jul: 'Juli',
    Agu: 'Agustus',
    Sep: 'September',
    Okt: 'Oktober',
    Nov: 'November',
    Des: 'Desember',
};

const statusClass: Record<string, string> = {
    staged: 'border-transparent text-status-up bg-status-up-bg',
    needs_mapping: 'border-transparent text-status-degraded bg-status-degraded-bg',
    existing_customer: 'border-transparent text-status-info bg-status-info-bg',
    applied: 'border-transparent text-muted-foreground bg-muted',
};

function money(value?: number | string | null) {
    return formatIdr(Number(value || 0));
}

function statusLabel(value: string) {
    const labels: Record<string, string> = {
        staged: 'Siap Apply',
        needs_mapping: 'Butuh Mapping',
        existing_customer: 'Sudah Ada',
        applied: 'Applied',
    };
    return labels[value] || value.replaceAll('_', ' ');
}

/** Pure CSS bar chart — total = join + applied; stacked for clarity. */
function PsbMonthBars({
    months,
    year,
    currentYear,
    currentMonthIdx,
}: {
    months: ChartMonth[];
    year: number;
    currentYear: number;
    currentMonthIdx: number;
}) {
    const maxVal = Math.max(
        1,
        ...months.map((m) => m.total_count ?? m.join_count + (m.applied_extra ?? 0)),
    );

    return (
        <div className="w-full min-w-0">
            <div className="flex h-[280px] items-end gap-1.5 border-b border-border/60 px-1 pb-0 sm:gap-2 sm:px-2">
                {months.map((m, idx) => {
                    const isCurrent = year === currentYear && idx === currentMonthIdx;
                                const extra = m.applied_extra ?? 0;
                    const total = m.total_count ?? m.join_count + extra;
                    const totalH = Math.max(total > 0 ? 10 : 0, Math.round((total / maxVal) * 210));
                    const joinShare = total > 0 ? m.join_count / total : 0;
                    const joinH = Math.round(totalH * joinShare);
                    const appH = Math.max(0, totalH - joinH);

                    return (
                        <div
                            key={m.month}
                            className="group relative flex min-w-0 flex-1 flex-col items-center justify-end"
                            title={`${MONTH_FULL[m.label] || m.label}: unik ${total} = join ${m.join_count} + extra staging ${m.applied_extra ?? 0} (applied raw ${m.applied_count})`}
                        >
                            <div
                                className={`mb-1 h-5 text-center text-[10px] font-bold tabular-nums leading-none sm:text-[11px] ${
                                    isCurrent ? 'text-primary' : 'text-foreground/80'
                                }`}
                            >
                                {total > 0 ? total : ''}
                            </div>
                            <div
                                className={`flex w-full max-w-[36px] flex-col justify-end overflow-hidden rounded-t-md ${
                                    isCurrent ? 'ring-2 ring-primary/40 ring-offset-1 ring-offset-background' : ''
                                }`}
                                style={{ height: Math.max(totalH, 2) }}
                            >
                                {/* stacked: extra staging (top) + join (bottom) = unique total */}
                                <div
                                    className={isCurrent ? 'w-full bg-muted-foreground' : 'w-full bg-muted-foreground/40'}
                                    style={{ height: appH }}
                                />
                                <div
                                    className={isCurrent ? 'w-full bg-primary' : 'w-full bg-primary/40'}
                                    style={{ height: joinH || (total === 0 ? 2 : 0) }}
                                />
                            </div>
                            <div
                                className={`mt-2 text-[10px] font-medium sm:text-[11px] ${
                                    isCurrent ? 'text-primary' : 'text-muted-foreground'
                                }`}
                            >
                                {m.label}
                            </div>
                            {isCurrent && (
                                <span className="mt-0.5 rounded bg-primary px-1 py-px text-[9px] font-semibold text-primary-foreground">
                                    ini
                                </span>
                            )}
                        </div>
                    );
                })}
            </div>
            <div className="mt-3 flex flex-wrap items-center justify-center gap-4 text-[11px] text-muted-foreground">
                <span className="inline-flex items-center gap-1.5">
                    <span className="inline-block h-2.5 w-2.5 rounded-sm bg-primary" /> Join scraper
                </span>
                <span className="inline-flex items-center gap-1.5">
                    <span className="inline-block h-2.5 w-2.5 rounded-sm bg-muted-foreground" /> Staging ekstra (belum di join)
                </span>
                <span className="font-medium text-foreground">Angka di atas = total unik (tanpa double-count)</span>
            </div>
        </div>
    );
}

export default function Index({ candidates, filters, summary, chart, kpis }: Props) {
    const [refreshing, setRefreshing] = useState(false);
    const [applyingId, setApplyingId] = useState<number | null>(null);
    const [bulkApplying, setBulkApplying] = useState(false);
    const [bulkApplyConfirmOpen, setBulkApplyConfirmOpen] = useState(false);
    const [importing, setImporting] = useState(false);
    const [sourceLabel, setSourceLabel] = useState('');
    const [fileName, setFileName] = useState('');
    const fileRef = useRef<HTMLInputElement>(null);
    const page = usePage();
    const flash = (page.props as { flash?: { success?: string; error?: string } }).flash;
    // KPI dashboard aggregates every area: global admin only (area admins would hit 403).
    const canOpenPsbKpi = isGlobalAdminUser((page.props as { auth?: { user?: AuthUserLike | null } }).auth?.user ?? {});

    const currentMonthKey = currentMonthWib();
    const currentYear = Number(currentMonthKey.slice(0, 4));
    const currentMonthIdx = Number(currentMonthKey.slice(5, 7)) - 1;

    const safeKpis = kpis ?? {
        this_month: 0,
        last_month: 0,
        ytd: 0,
        total: 0,
        this_month_join: 0,
        this_month_applied: 0,
        last_month_join: 0,
        last_month_applied: 0,
    };
    const safeChart = chart ?? {
        year: currentYear,
        years: [currentYear],
        months: [],
        total_join: 0,
        total_applied: 0,
        total_sum: 0,
    };
    const safeSummary = summary ?? { total: 0, ready: 0, needsMapping: 0, applied: 0, existing: 0 };

    const months = useMemo(
        () =>
            (safeChart.months ?? []).map((m) => ({
                ...m,
                applied_extra: m.applied_extra ?? 0,
                total_count: m.total_count ?? m.join_count + (m.applied_extra ?? 0),
            })),
        [safeChart.months],
    );
    const thisMonthRow = safeChart.year === currentYear ? months[currentMonthIdx] : undefined;
    const thisMonthLabel = thisMonthRow
        ? `${MONTH_FULL[thisMonthRow.label] || thisMonthRow.label} ${safeChart.year}`
        : `Bulan ini`;
    const thisMonthJoin =
        safeKpis.this_month_join ??
        (safeChart.year === currentYear ? thisMonthRow?.join_count || 0 : 0);
    const thisMonthApplied =
        safeKpis.this_month_applied ??
        (safeChart.year === currentYear ? thisMonthRow?.applied_count || 0 : 0);
    const thisMonthExtra =
        safeKpis.this_month_extra ??
        (safeChart.year === currentYear ? thisMonthRow?.applied_extra || 0 : 0);
    // Unik: join + applied yang belum ada di join bulan sama
    const thisMonthTotal = safeKpis.this_month || thisMonthJoin + thisMonthExtra;

    const deltaVsLast =
        safeKpis.last_month > 0
            ? Math.round(((safeKpis.this_month - safeKpis.last_month) / safeKpis.last_month) * 100)
            : safeKpis.this_month > 0
              ? 100
              : 0;

    function setYear(year: number) {
        router.get(route('psb-imports.index'), { ...filters, year }, { preserveState: true, preserveScroll: true });
    }

    function refresh() {
        setRefreshing(true);
        router.post(route('psb-imports.refresh'), {}, { preserveScroll: true, onFinish: () => setRefreshing(false) });
    }

    function applyCandidate(id: number) {
        setApplyingId(id);
        router.post(route('psb-imports.apply', id), {}, { preserveScroll: true, onFinish: () => setApplyingId(null) });
    }

    // S1: bulk apply creates N customers in one request, so it always goes
    // through a confirmation that states the count first.
    function bulkApply() {
        if (bulkApplying) return;
        setBulkApplying(true);
        router.post(route('psb-imports.bulk-apply'), {}, {
            preserveScroll: true,
            onFinish: () => {
                setBulkApplying(false);
                setBulkApplyConfirmOpen(false);
            },
        });
    }

    function submitImport(e: React.FormEvent) {
        e.preventDefault();
        const file = fileRef.current?.files?.[0];
        if (!file) {
            return;
        }
        const form = new FormData();
        form.append('file', file);
        if (sourceLabel.trim()) {
            form.append('source_label', sourceLabel.trim());
        }
        setImporting(true);
        router.post(route('psb-imports.import'), form, {
            forceFormData: true,
            preserveScroll: true,
            onFinish: () => {
                setImporting(false);
                setFileName('');
                if (fileRef.current) {
                    fileRef.current.value = '';
                }
            },
        });
    }

    function downloadTemplate() {
        const csv = [
            'customer_code,name,area_name,package_name,price,speed,pppoe_user,phone,address,join_date',
            'RDG1200,CONTOH PELANGGAN,RANDUAGUNG,20Mbps,200000,20Mbps,rdg1200,08123456789,Alamat contoh,2026-07-15',
        ].join('\n');
        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'template-psb-import.csv';
        a.click();
        URL.revokeObjectURL(url);
    }

    const columns: Column<Candidate>[] = [
        {
            header: 'Status',
            cell: (c) => (
                <Badge variant="outline" className={statusClass[c.status] || 'border-border text-muted-foreground'}>
                    {statusLabel(c.status)}
                </Badge>
            ),
        },
        {
            header: 'Pelanggan',
            cell: (c) => (
                <div className="min-w-0">
                    <div className="font-medium leading-snug">{c.name}</div>
                    <div className="font-mono text-xs text-muted-foreground">{c.customer_code}</div>
                    <div className="mt-0.5 max-w-[220px] truncate text-xs text-muted-foreground">{c.address || '—'}</div>
                </div>
            ),
        },
        {
            header: 'Sumber',
            cell: (c) => (
                <div className="text-sm">
                    <div className="font-medium">{c.area_name || '—'}</div>
                    <div className="mt-0.5 text-xs text-muted-foreground">
                        {c.package_name || '—'}
                        {c.speed ? ` · ${c.speed}` : ''}
                        {c.price != null ? ` · ${money(c.price)}` : ''}
                    </div>
                </div>
            ),
        },
        {
            header: 'Mapping',
            cell: (c) => (
                <div className="text-sm">
                    <div className="font-medium">{c.resolved_area || <span className="text-muted-foreground">Belum</span>}</div>
                    <div className="mt-0.5 text-xs text-muted-foreground">{c.resolved_package || '—'}</div>
                    <div className="mt-0.5 text-xs text-muted-foreground">{c.resolved_router || 'router —'}</div>
                </div>
            ),
        },
        {
            header: 'Catatan',
            wrap: true,
            cell: (c) => (
                <div className="max-w-[200px] text-xs text-muted-foreground">
                    {c.match_notes || '—'}
                    {c.matched_customer_name && (
                        <div className="mt-1 text-status-info">Existing: {c.matched_customer_name}</div>
                    )}
                </div>
            ),
        },
        {
            header: '',
            className: 'text-right w-[110px]',
            cell: (c) => (
                <Button
                    type="button"
                    size="sm"
                    className="h-8"
                    disabled={c.status !== 'staged' || applyingId === c.id}
                    onClick={(e) => {
                        e.stopPropagation();
                        applyCandidate(c.id);
                    }}
                >
                    {applyingId === c.id ? '…' : 'Apply'}
                </Button>
            ),
        },
    ];

    const filterConfigs: FilterConfig[] = [
        {
            key: 'status',
            placeholder: 'Semua status',
            options: [
                { label: 'Siap Apply', value: 'staged' },
                { label: 'Butuh Mapping', value: 'needs_mapping' },
                { label: 'Sudah Ada', value: 'existing_customer' },
                { label: 'Applied', value: 'applied' },
            ],
        },
    ];

    return (
        <EnterpriseIndexLayout
            title="PSB"
            pageTitle="PSB"
            subtitle="Pantau sambungan baru per bulan (data scraper) dan kelola import cadangan."
            icon={UserPlus}
            breadcrumbs={[{ label: 'Operasi', href: route('psb-imports.index') }, { label: 'PSB' }]}
            actions={
                <div className="flex flex-wrap items-center gap-2">
                    {canOpenPsbKpi && (
                        <Button type="button" variant="outline" size="sm" className="h-9 gap-2" asChild>
                            <a href={route('psb-drafts.dashboard')}>
                                <FileSpreadsheet className="h-4 w-4" />
                                <span className="hidden sm:inline">PSB WA KPI</span>
                            </a>
                        </Button>
                    )}
                    <Button type="button" variant="outline" size="sm" className="h-9 gap-2" onClick={refresh} disabled={refreshing}>
                        <RefreshCw className={`h-4 w-4 ${refreshing ? 'animate-spin' : ''}`} />
                        <span className="hidden sm:inline">Refresh mapping</span>
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        className="h-9 gap-2"
                        onClick={() => setBulkApplyConfirmOpen(true)}
                        disabled={bulkApplying || safeSummary.ready === 0}
                    >
                        <CheckCircle2 className="h-4 w-4" />
                        Apply siap ({safeSummary.ready})
                    </Button>
                </div>
            }
        >
            <div className="space-y-6">
                {(flash?.success || flash?.error) && (
                    <div
                        role="status"
                        className={`flex items-start gap-2 rounded-xl border px-4 py-3 text-sm ${
                            flash.error
                                ? 'border-status-down/30 bg-status-down-bg text-status-down'
                                : 'border-status-up/30 bg-status-up-bg text-status-up'
                        }`}
                    >
                        {flash.error ? <AlertCircle className="mt-0.5 h-4 w-4 shrink-0" /> : <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0" />}
                        <span>{flash.error || flash.success}</span>
                    </div>
                )}

                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <EnterpriseMetricCard
                        title="PSB bulan ini (unik)"
                        value={String(thisMonthTotal)}
                        hint={`${thisMonthLabel} · join ${thisMonthJoin} + ekstra ${thisMonthExtra} (applied raw ${thisMonthApplied})`}
                        icon={CalendarDays}
                        delta={
                            safeKpis.last_month > 0 || safeKpis.this_month > 0
                                ? `${deltaVsLast >= 0 ? '+' : ''}${deltaVsLast}% vs bulan lalu`
                                : undefined
                        }
                    />
                    <EnterpriseMetricCard
                        title="Bulan lalu (unik)"
                        value={String(safeKpis.last_month)}
                        hint={`join ${safeKpis.last_month_join ?? '—'} + ekstra ${safeKpis.last_month_extra ?? '—'} (applied raw ${safeKpis.last_month_applied ?? '—'})`}
                        icon={TrendingUp}
                    />
                    <EnterpriseMetricCard
                        title={`YTD ${safeChart.year} (unik)`}
                        value={String(safeKpis.ytd)}
                        hint="tanpa double-count"
                        icon={Users}
                    />
                    <EnterpriseMetricCard
                        title="Total join scraper"
                        value={String(safeKpis.total)}
                        hint="semua periode (join_date)"
                        icon={ListChecks}
                    />
                </div>

                <Card className="border-border/80">
                    <CardHeader className="gap-3 space-y-0 border-b border-border/60 bg-muted/20 pb-4 sm:flex-row sm:items-start sm:justify-between">
                        <div className="space-y-1">
                            <CardTitle className="text-base tracking-tight">Grafik PSB per bulan</CardTitle>
                            <CardDescription className="max-w-xl text-xs sm:text-sm">
                                <span className="font-medium text-foreground">
                                    Total unik = join scraper + staging ekstra
                                </span>
                                {' '}
                                (applied yang sudah ada di join bulan sama tidak dijumlah 2×).
                            </CardDescription>
                        </div>
                        <div className="flex items-center gap-2">
                            <label className="text-xs font-medium text-muted-foreground" htmlFor="psb-year">
                                Tahun
                            </label>
                            <select
                                id="psb-year"
                                className="h-9 min-w-[5.5rem] rounded-lg border border-border bg-background px-2.5 text-sm font-medium shadow-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                value={safeChart.year}
                                onChange={(e) => setYear(Number(e.target.value))}
                            >
                                {(safeChart.years?.length ? safeChart.years : [safeChart.year]).map((y) => (
                                    <option key={y} value={y}>
                                        {y}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </CardHeader>
                    <CardContent className="space-y-4 pt-4">
                        {/* Total bulan ini = join + applied */}
                        <div className="flex flex-col gap-3 rounded-xl border border-border bg-primary-soft px-4 py-4 sm:flex-row sm:items-center sm:justify-between">
                            <div className="flex items-start gap-3">
                                <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-primary text-primary-foreground shadow">
                                    <CalendarDays className="h-6 w-6" />
                                </div>
                                <div>
                                    <p className="text-[11px] font-bold uppercase tracking-wider text-primary-soft-foreground">
                                        Total bulan ini
                                    </p>
                                    <p className="mt-0.5 text-lg font-semibold text-foreground">{thisMonthLabel}</p>
                                    <p className="text-xs text-muted-foreground">
                                        join {thisMonthJoin} + ekstra staging {thisMonthExtra} (applied raw {thisMonthApplied})
                                    </p>
                                </div>
                            </div>
                            <div className="flex items-end gap-6 pl-14 sm:pl-0 sm:text-right">
                                <div>
                                    <p className="text-[11px] font-medium text-muted-foreground">Total unik</p>
                                    <p className="text-5xl font-bold tabular-nums leading-none tracking-tight text-primary-soft-foreground">
                                        {thisMonthTotal}
                                    </p>
                                </div>
                                <div className="text-left text-xs text-muted-foreground sm:text-right">
                                    <p>
                                        Join scraper:{' '}
                                        <strong className="text-primary-soft-foreground">{thisMonthJoin}</strong>
                                    </p>
                                    <p className="mt-1">
                                        Applied (raw):{' '}
                                        <strong className="text-foreground">{thisMonthApplied}</strong>
                                    </p>
                                    <p className="mt-1">
                                        Extra (tidak double):{' '}
                                        <strong className="text-foreground">{thisMonthExtra}</strong>
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div className="flex flex-wrap gap-x-5 gap-y-1 text-xs text-muted-foreground">
                            <span>
                                Join {safeChart.year}: <strong className="text-foreground">{safeChart.total_join}</strong>
                            </span>
                            <span>
                                Applied raw {safeChart.year}:{' '}
                                <strong className="text-foreground">{safeChart.total_applied}</strong>
                            </span>
                            <span>
                                Total unik {safeChart.year}:{' '}
                                <strong className="text-foreground">
                                    {(safeChart as { total_unique?: number }).total_unique ??
                                        (safeChart as { total_sum?: number }).total_sum ??
                                        safeChart.total_join}
                                </strong>
                            </span>
                        </div>

                        {months.length > 0 ? (
                            <PsbMonthBars
                                months={months}
                                year={safeChart.year}
                                currentYear={currentYear}
                                currentMonthIdx={currentMonthIdx}
                            />
                        ) : (
                            <div className="flex h-40 items-center justify-center text-sm text-muted-foreground">
                                Belum ada data join untuk tahun ini.
                            </div>
                        )}

                        <p className="rounded-lg bg-muted/50 px-3 py-2.5 text-center text-sm">
                            <span className="font-bold text-primary">
                                Total unik {thisMonthLabel}: {thisMonthTotal}
                            </span>
                            <span className="text-muted-foreground">
                                {' '}
                                (= {thisMonthJoin} join + {thisMonthExtra} staging ekstra; applied raw {thisMonthApplied}{' '}
                                — yang sudah di join tidak dijumlah 2×)
                            </span>
                        </p>
                    </CardContent>
                </Card>

                <div className="grid gap-4 lg:grid-cols-5">
                    <Card className="lg:col-span-3">
                        <CardHeader className="pb-3">
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Upload className="h-4 w-4 text-muted-foreground" />
                                Import cadangan
                            </CardTitle>
                            <CardDescription className="text-xs sm:text-sm">
                                Cadangan jika scraper error.{' '}
                                <span className="inline-flex flex-wrap items-center gap-1 font-medium text-foreground">
                                    Upload <ArrowRight className="inline h-3 w-3" /> Refresh mapping{' '}
                                    <ArrowRight className="inline h-3 w-3" /> Apply
                                </span>
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={submitImport} className="space-y-4">
                                <div
                                    className="relative flex cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-dashed border-border/80 bg-muted/20 px-4 py-8 transition hover:border-primary/40 hover:bg-muted/40"
                                    onClick={() => fileRef.current?.click()}
                                    onKeyDown={(e) => e.key === 'Enter' && fileRef.current?.click()}
                                    role="button"
                                    tabIndex={0}
                                >
                                    <FileSpreadsheet className="mb-2 h-8 w-8 text-muted-foreground/80" />
                                    <p className="text-sm font-medium">{fileName || 'Pilih file CSV'}</p>
                                    <p className="mt-1 text-center text-xs text-muted-foreground">Klik untuk pilih · template tersedia</p>
                                    <input
                                        ref={fileRef}
                                        type="file"
                                        accept=".csv,text/csv"
                                        className="sr-only"
                                        required
                                        onChange={(e) => setFileName(e.target.files?.[0]?.name || '')}
                                    />
                                </div>
                                <div>
                                    <label className="mb-1.5 block text-xs font-medium text-muted-foreground">
                                        Label sumber (opsional)
                                    </label>
                                    <input
                                        type="text"
                                        value={sourceLabel}
                                        onChange={(e) => setSourceLabel(e.target.value)}
                                        placeholder="contoh: psb-juli-2026-manual"
                                        className="h-9 w-full rounded-lg border border-border bg-background px-3 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                    />
                                </div>
                                <div className="flex flex-wrap gap-2">
                                    <Button type="submit" size="sm" className="h-9" disabled={importing || !fileName}>
                                        <Upload className="mr-2 h-4 w-4" />
                                        {importing ? 'Mengimpor…' : 'Upload ke staging'}
                                    </Button>
                                    <Button type="button" variant="outline" size="sm" className="h-9" onClick={downloadTemplate}>
                                        Unduh template
                                    </Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>

                    <Card className="lg:col-span-2">
                        <CardHeader className="pb-3">
                            <CardTitle className="text-base">Antrian staging</CardTitle>
                            <CardDescription className="text-xs sm:text-sm">Dari CSV — terpisah dari grafik scraper.</CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-2.5">
                            {[
                                { label: 'Total staging', value: safeSummary.total, className: '' },
                                {
                                    label: 'Siap apply',
                                    value: safeSummary.ready,
                                    className: 'border-status-up/30 bg-status-up-bg',
                                },
                                {
                                    label: 'Butuh mapping',
                                    value: safeSummary.needsMapping,
                                    className: 'border-status-degraded/30 bg-status-degraded-bg',
                                },
                                {
                                    label: 'Sudah ada',
                                    value: safeSummary.existing,
                                    className: 'border-status-info/30 bg-status-info-bg',
                                },
                                { label: 'Sudah di-apply', value: safeSummary.applied, className: '' },
                            ].map((row) => (
                                <div
                                    key={row.label}
                                    className={`flex items-center justify-between rounded-lg border border-border/60 px-3 py-2.5 ${row.className}`}
                                >
                                    <span className="text-sm text-muted-foreground">{row.label}</span>
                                    <span className="text-lg font-semibold tabular-nums">{row.value}</span>
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                </div>

                <DataTable
                    compact
                    data={candidates}
                    columns={columns}
                    filters={filters}
                    title="Kandidat staging"
                    description={`${candidates?.total ?? 0} baris · apply setelah area & paket ter-mapping`}
                    searchPlaceholder="Cari kode, nama, atau area…"
                    filterConfigs={filterConfigs}
                    routeName="psb-imports.index"
                    pageSizeOptions={[10, 20, 25, 30, 50, 100]}
                    extraParams={{ year: safeChart.year }}
                />
            </div>
            <ConfirmDestructive
                open={bulkApplyConfirmOpen}
                onOpenChange={setBulkApplyConfirmOpen}
                title={`Apply ${safeSummary.ready} kandidat siap?`}
                description={`${safeSummary.ready} kandidat berstatus Siap Apply akan dibuat sebagai pelanggan Aktif di eBilling sekaligus (paket, area, dan router sesuai hasil mapping; jatuh tempo tanggal 20). Kandidat yang kode atau PPPoE-nya sudah ada ditandai pelanggan lama dan dilewati. MikroTik dan OLT tidak diubah oleh aksi ini.`}
                confirmationLabel={`Saya sudah memeriksa daftar dan ingin membuat ${safeSummary.ready} pelanggan baru.`}
                confirmText={`Apply ${safeSummary.ready} kandidat`}
                onConfirm={bulkApply}
                isLoading={bulkApplying}
            />
        </EnterpriseIndexLayout>
    );
}
