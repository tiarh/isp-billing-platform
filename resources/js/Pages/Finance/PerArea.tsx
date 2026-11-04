import { useEffect, useMemo, useState } from 'react';
import { usePage } from '@inertiajs/react';
import { isGlobalAdminUser, type AuthUserLike } from '@/lib/navAccess';
import { EnterpriseIndexLayout } from '@/Components/enterprise/EnterpriseIndexLayout';
import { ChartCard } from '@/Components/ChartCard';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { SkeletonTable } from '@/Components/SkeletonTable';
import { AlertCircle, BarChart3, Download, RefreshCw } from 'lucide-react';
import { Bar, BarChart, CartesianGrid, LabelList, Legend, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { chartTooltipProps } from '@/lib/chartTheme';
import { currentMonthWib, todayWib } from '@/lib/dateTime';
import { formatDate, formatDateTime, formatIdr, formatNumber, formatRelative } from '@/lib/format';

type FinanceTab = 'summary' | 'detail' | 'operational';

type AreaRow = {
    area_id: number | null;
    area_code: string | null;
    area_name: string;
    invoice_count: number;
    paid_count: number;
    legacy_settled_count: number;
    unpaid_count: number;
    billable_amount: string;
    cash_paid_amount: string;
    legacy_settled_amount: string;
    recorded_paid_amount: string;
    outstanding_amount: string;
    overpayment_amount: string;
    collection_rate: number | null;
    aging: Record<string, number>;
};

type SummaryResponse = {
    period: string;
    previous_period?: string;
    as_of: string;
    scope: string;
    formula_version: string;
    generated_at: string;
    stale: boolean;
    totals: AreaRow;
    areas: AreaRow[];
    data_quality: Record<string, number>;
};

type DetailRow = {
    invoice_id: number;
    invoice_code: string;
    customer_id: number;
    customer_code: string;
    customer_name: string | null;
    area_name: string | null;
    package_name: string | null;
    period: string;
    amount: string;
    effective_paid: string;
    outstanding: string;
    overpayment: string;
    due_date: string | null;
    status: string;
    aging: { bucket: string; days: number | null };
};

type DetailResponse = {
    period: string;
    as_of: string;
    scope: string;
    formula_version: string;
    generated_at: string;
    stale: boolean;
    data: DetailRow[];
    meta: { current_page: number; last_page: number; per_page: number; total: number };
};

type OperationsOption = { value: string; label: string };

type OperationsRow = {
    project: string;
    project_label: string;
    area: string;
    area_label: string;
    sub_area: string;
    rw: string;
    migration_target: number;
    migrated: number;
    psb_success: number;
    needs_review: number;
};

type OperationsResponse = {
    generated_at?: string;
    last_sync?: string;
    stale?: boolean;
    source_note?: string;
    snapshot_note?: string;
    options?: {
        projects: OperationsOption[];
        rws: OperationsOption[];
        sub_areas: OperationsOption[];
    };
    summary?: {
        migration_target: number;
        migrated: number;
        not_migrated: number;
        migration_progress: number;
        psb_this_month: number;
        psb_target: number;
        psb_progress: number;
        needs_review: number;
    };
    rows?: OperationsRow[];
    drilldowns?: { migration_url: string; psb_url: string };
};

type BulkWaPreview = {
    enabled: boolean;
    dry_run: boolean;
    max_recipients: number;
    rate_limit_per_minute: number;
    can_execute: boolean;
    confirmation_text: string;
    rows: Array<{
        invoice_id: number;
        invoice_code: string;
        customer_code: string;
        customer_name: string | null;
        phone: string | null;
        period: string;
        amount: string;
        outstanding: string;
        due_date: string | null;
        status: string;
        reason: string;
        message_preview: string;
    }>;
    skipped: Array<{ invoice_code: string | null; customer_code: string | null; reason: string }>;
    eligible_count: number;
    skipped_count: number;
    scan_limited: boolean;
    source_note: string;
};

type QueryState = {
    period: string;
    area: string;
    asOf: string;
    tab: FinanceTab;
    search: string;
    status: string;
    due: string;
    page: number;
    project: string;
    rw: string;
    subArea: string;
};

function currentMonth(): string {
    return currentMonthWib();
}

function today(): string {
    return todayWib();
}

function readQuery(): QueryState {
    const params = new URLSearchParams(window.location.search);
    const tab = params.get('tab');

    return {
        period: /^\d{4}-\d{2}$/.test(params.get('period') || '') ? params.get('period') as string : currentMonth(),
        area: params.get('area') || '',
        asOf: /^\d{4}-\d{2}-\d{2}$/.test(params.get('as_of') || '') ? params.get('as_of') as string : today(),
        tab: tab === 'detail' || tab === 'operational' ? tab : 'summary',
        search: params.get('search') || '',
        status: params.get('status') || '',
        due: params.get('due') || '',
        page: Math.max(1, Number(params.get('page') || 1) || 1),
        project: params.get('project') || '',
        rw: params.get('rw') || '',
        subArea: params.get('sub_area') || '',
    };
}

function statusLabel(status: string): string {
    return ({
        paid: 'Lunas',
        legacy_settled: 'Lunas legacy',
        unpaid: 'Belum lunas',
        overdue: 'Jatuh tempo',
        unknown: 'Perlu review',
    } as Record<string, string>)[status] || status;
}

function StatusPill({ status }: { status: string }) {
    const tone = status === 'paid' || status === 'legacy_settled'
        ? 'border-status-up/30 bg-status-up-bg text-status-up'
        : status === 'overdue'
            ? 'border-status-down/30 bg-status-down-bg text-status-down'
            : status === 'unknown'
                ? 'border-status-degraded/30 bg-status-degraded-bg text-status-degraded'
                : 'border-border bg-muted text-muted-foreground';

    return <span className={`inline-flex rounded-full border px-2 py-0.5 text-xs font-medium ${tone}`}>{statusLabel(status)}</span>;
}

function Rate({ value }: { value: number | null | undefined }) {
    return <span className="font-mono tabular-nums">{value === null || value === undefined ? 'N/A' : `${value}%`}</span>;
}

function Metric({ label, value, hint }: { label: string; value: string; hint: string }) {
    return (
        <Card>
            <CardHeader className="pb-2">
                <CardDescription>{label}</CardDescription>
            </CardHeader>
            <CardContent>
                <p className="font-mono text-2xl font-semibold tabular-nums">{value}</p>
                <p className="mt-1 text-xs text-muted-foreground">{hint}</p>
            </CardContent>
        </Card>
    );
}

function compactIdr(value: number | string): string {
    const amount = Number(value);
    if (!Number.isFinite(amount)) return 'Rp 0';
    if (Math.abs(amount) >= 1_000_000_000) return `Rp ${formatNumber(amount / 1_000_000_000, { maximumFractionDigits: 1 })} M`;
    if (Math.abs(amount) >= 1_000_000) return `Rp ${formatNumber(amount / 1_000_000, { maximumFractionDigits: 1 })} jt`;
    if (Math.abs(amount) >= 1_000) return `Rp ${formatNumber(amount / 1_000, { maximumFractionDigits: 0 })} rb`;

    return formatIdr(amount);
}

function shortAreaLabel(value: string): string {
    return value.length > 20 ? `${value.slice(0, 19)}…` : value;
}

async function getJson<T>(url: string, signal: AbortSignal): Promise<T> {
    const response = await fetch(url, { headers: { Accept: 'application/json' }, signal });
    if (!response.ok) {
        throw new Error(`REPORT_${response.status}`);
    }

    return response.json() as Promise<T>;
}

export default function PerArea() {
    // PSB KPI dashboard aggregates every area: global admin only (area admins would hit 403).
    const canOpenPsbKpi = isGlobalAdminUser((usePage().props as { auth?: { user?: AuthUserLike | null } }).auth?.user ?? {});
    const [query, setQuery] = useState<QueryState>(() => readQuery());
    const [summary, setSummary] = useState<SummaryResponse | null>(null);
    const [detail, setDetail] = useState<DetailResponse | null>(null);
    const [operations, setOperations] = useState<OperationsResponse | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(false);
    const [searchInput, setSearchInput] = useState(query.search);
    const [reloadToken, setReloadToken] = useState(0);
    const [bulkPreview, setBulkPreview] = useState<BulkWaPreview | null>(null);
    const [bulkLoading, setBulkLoading] = useState(false);
    const [bulkError, setBulkError] = useState<string | null>(null);
    const [bulkConfirmation, setBulkConfirmation] = useState('');
    const [bulkIdempotency] = useState(() => window.crypto?.randomUUID?.() || `${Date.now()}-finance-bulk`);

    const reportParams = useMemo(() => {
        const params = new URLSearchParams({ period: query.period, as_of: query.asOf });
        if (query.area) params.set('area', query.area);

        return params;
    }, [query.period, query.area, query.asOf]);

    const exportUrl = useMemo(() => {
        const params = new URLSearchParams(reportParams);
        if (query.search) params.set('search', query.search);
        if (query.status) params.set('status', query.status);
        if (query.due) params.set('due', query.due);

        return `/api/finance/per-area/export?${params.toString()}`;
    }, [reportParams, query.search, query.status, query.due]);

    const updateQuery = (changes: Partial<QueryState>) => {
        const next = { ...query, ...changes };
        const params = new URLSearchParams();
        params.set('period', next.period);
        params.set('as_of', next.asOf);
        params.set('tab', next.tab);
        if (next.area) params.set('area', next.area);
        if (next.search) params.set('search', next.search);
        if (next.status) params.set('status', next.status);
        if (next.due) params.set('due', next.due);
        if (next.page > 1) params.set('page', String(next.page));
        if (next.tab === 'operational') {
            if (next.project) params.set('project', next.project);
            if (next.rw) params.set('rw', next.rw);
            if (next.subArea) params.set('sub_area', next.subArea);
        }
        window.history.replaceState({}, '', `${window.location.pathname}?${params.toString()}`);
        setQuery(next);
    };

    useEffect(() => {
        const controller = new AbortController();
        setLoading(true);
        setError(false);

        const requests: Promise<unknown>[] = [
            getJson<SummaryResponse>(`/api/finance/per-area/summary?${reportParams.toString()}`, controller.signal),
        ];
        if (query.tab === 'detail') {
            const detailParams = new URLSearchParams(reportParams);
            if (query.search) detailParams.set('search', query.search);
            if (query.status) detailParams.set('status', query.status);
            if (query.due) detailParams.set('due', query.due);
            detailParams.set('page', String(query.page));
            requests.push(getJson<DetailResponse>(`/api/finance/per-area/detail?${detailParams.toString()}`, controller.signal));
        }
        if (query.tab === 'operational') {
            const operationsParams = new URLSearchParams(reportParams);
            if (query.project) operationsParams.set('project', query.project);
            if (query.rw) operationsParams.set('rw', query.rw);
            if (query.subArea) operationsParams.set('sub_area', query.subArea);
            requests.push(getJson<OperationsResponse>(`/api/finance/per-area/operations?${operationsParams.toString()}`, controller.signal));
        }

        Promise.all(requests)
            .then((responses) => {
                setSummary(responses[0] as SummaryResponse);
                setDetail(query.tab === 'detail' ? responses[1] as DetailResponse : null);
                setOperations(query.tab === 'operational' ? responses[1] as OperationsResponse : null);
            })
            .catch((requestError: unknown) => {
                if ((requestError as Error)?.name !== 'AbortError') {
                    setError(true);
                }
            })
            .finally(() => {
                if (!controller.signal.aborted) setLoading(false);
            });

        return () => controller.abort();
    }, [query.tab, query.page, query.search, query.status, query.due, query.project, query.rw, query.subArea, reportParams, reloadToken]);

    const areas = summary?.areas || [];
    const totals = summary?.totals;
    const chartAreas = useMemo(() => [...areas]
        .filter((area) => area.area_id !== null)
        .sort((left, right) => Number(right.billable_amount) - Number(left.billable_amount))
        .slice(0, 10)
        .map((area) => ({
            ...area,
            billable: Number(area.billable_amount) || 0,
            outstanding: Number(area.outstanding_amount) || 0,
        })), [areas]);

    const retry = () => setReloadToken((current) => current + 1);
    const selectTab = (tab: FinanceTab) => updateQuery({ tab, page: 1 });
    const submitSearch = (event: React.FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        updateQuery({ search: searchInput.trim(), page: 1 });
    };

    const bulkParams = useMemo(() => {
        const params = new URLSearchParams(reportParams);
        if (query.search) params.set('search', query.search);
        if (query.status) params.set('status', query.status);
        if (query.due) params.set('due', query.due);

        return params;
    }, [reportParams, query.search, query.status, query.due]);

    const previewBulkWa = async () => {
        setBulkLoading(true);
        setBulkError(null);
        try {
            const response = await fetch(`/api/finance/per-area/bulk-wa/preview?${bulkParams.toString()}`, { headers: { Accept: 'application/json' } });
            const data = await response.json();
            if (!response.ok) throw new Error(data.message || `REPORT_${response.status}`);
            setBulkPreview(data as BulkWaPreview);
        } catch (error: any) {
            setBulkError(error?.message || 'Preview WA tagihan gagal');
        } finally {
            setBulkLoading(false);
        }
    };

    const executeBulkWa = async () => {
        setBulkLoading(true);
        setBulkError(null);
        try {
            const body = Object.fromEntries(bulkParams.entries());
            const response = await fetch('/api/finance/per-area/bulk-wa/execute', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': (window as any).csrfToken || '' },
                body: JSON.stringify({ ...body, confirmation: bulkConfirmation, idempotency_key: bulkIdempotency }),
            });
            const data = await response.json();
            if (!response.ok || !data.success) throw new Error(data.message || 'WA tagihan massal diblokir');
            setBulkConfirmation('');
            await previewBulkWa();
        } catch (error: any) {
            setBulkError(error?.message || 'Pengiriman WA tagihan gagal');
        } finally {
            setBulkLoading(false);
        }
    };

    return (
        <EnterpriseIndexLayout
            title="Keuangan per Area"
            pageTitle="Keuangan per Area"
            subtitle="Tagihan, pemasukan efektif, dan rekonsiliasi operasional berbasis periode invoice"
            icon={BarChart3}
            breadcrumbs={[{ label: 'Keuangan per Area' }]}
            cachedAt={summary?.generated_at || null}
            cacheLabel="Snapshot laporan"
            freshMinutes={15}
            activeTab={query.tab}
            onTabChange={(tab) => selectTab(tab as FinanceTab)}
            tabs={[
                { id: 'summary', label: 'Ringkasan' },
                { id: 'detail', label: 'Detail Area' },
                { id: 'operational', label: 'Operasional' },
            ]}
            actions={
                <div className="flex gap-2">
                    <Button asChild type="button" variant="outline" size="sm">
                        <a href={exportUrl} download>
                            <Download className="mr-2 h-4 w-4" aria-hidden="true" />
                            XLSX
                        </a>
                    </Button>
                    <Button type="button" variant="outline" size="sm" onClick={retry} disabled={loading}>
                        <RefreshCw className="mr-2 h-4 w-4" aria-hidden="true" />
                        Muat ulang
                    </Button>
                </div>
            }
        >
            <div className="space-y-5">
                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle className="text-base">Filter laporan</CardTitle>
                        <CardDescription>Pilih bulan invoice dan area. Hasil filter tersimpan di URL sehingga bisa dibagikan atau di-refresh.</CardDescription>
                    </CardHeader>
                    <CardContent className="grid min-w-0 gap-4 sm:grid-cols-2 xl:grid-cols-[minmax(0,12rem)_minmax(0,1fr)_minmax(0,15rem)]">
                        <label className="grid min-w-0 gap-1 text-sm font-medium">
                            <span>Periode invoice</span>
                            <input className="h-9 w-full min-w-0 rounded-md border bg-background px-3 font-mono text-sm" type="month" value={query.period} onChange={(event) => updateQuery({ period: event.target.value || currentMonth(), page: 1 })} />
                        </label>
                        <label className="grid min-w-0 gap-1 text-sm font-medium">
                            <span>Area</span>
                            <select className="h-9 w-full min-w-0 max-w-full truncate rounded-md border bg-background px-3 text-sm" value={query.area} onChange={(event) => updateQuery({ area: event.target.value, page: 1 })}>
                                <option value="">Semua area yang diizinkan</option>
                                {areas.filter((area) => area.area_id !== null).map((area) => <option key={area.area_id} value={area.area_id as number}>{area.area_name}</option>)}
                            </select>
                        </label>
                        <div className="grid min-w-0 gap-1 text-sm">
                            <label className="grid gap-1 font-medium" htmlFor="finance-as-of">
                                Tanggal acuan laporan
                                <input id="finance-as-of" className="h-9 w-full min-w-0 rounded-md border bg-background px-3 font-mono text-sm" type="date" value={query.asOf} onChange={(event) => updateQuery({ asOf: event.target.value || today(), page: 1 })} aria-describedby="finance-as-of-help" />
                            </label>
                            <p id="finance-as-of-help" className="text-xs leading-5 text-muted-foreground">Dipakai untuk menghitung jatuh tempo dan umur piutang; tidak mengubah periode invoice.</p>
                        </div>
                    </CardContent>
                </Card>

                {loading && <div role="status" aria-label="Memuat laporan keuangan"><SkeletonTable rows={5} columns={5} /></div>}
                {!loading && error && (
                    <Card role="alert" className="border-status-down/30">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-status-down"><AlertCircle className="h-4 w-4" aria-hidden="true" />Laporan tidak dapat dimuat</CardTitle>
                            <CardDescription>Server tidak mengembalikan data laporan. Coba lagi; filter yang dipilih tetap disimpan.</CardDescription>
                        </CardHeader>
                        <CardContent><Button type="button" variant="outline" onClick={retry}>Coba lagi</Button></CardContent>
                    </Card>
                )}

                {!loading && !error && summary && query.tab === 'summary' && (
                    <section aria-labelledby="finance-summary-heading" className="space-y-5">
                        <h2 id="finance-summary-heading" className="sr-only">Ringkasan keuangan</h2>
                        <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                            <Metric label="Tagihan" value={formatIdr(totals?.billable_amount)} hint={`${totals?.invoice_count || 0} invoice non-void`} />
                            <Metric label="Pemasukan tercatat" value={formatIdr(totals?.recorded_paid_amount)} hint="Termasuk invoice legacy paid yang belum punya transaction" />
                            <Metric label="Pemasukan terverifikasi" value={formatIdr(totals?.cash_paid_amount)} hint="Transaction valid dikurangi reversal" />
                            <Metric label="Lunas legacy" value={formatIdr(totals?.legacy_settled_amount)} hint={`${totals?.legacy_settled_count || 0} invoice paid tanpa transaction; perlu rekonsiliasi`} />
                            <Metric label="Outstanding" value={formatIdr(totals?.outstanding_amount)} hint="Dihitung per invoice" />
                            <Metric label="Collection rate" value={totals?.collection_rate === null ? 'N/A' : `${totals?.collection_rate || 0}%`} hint="Overpayment dipisah sebagai data quality" />
                        </div>
                        <ChartCard title="Tagihan dan outstanding per area" description={`Top 10 area berdasarkan tagihan pada periode ${summary.period}.`}>
                            {chartAreas.length > 0 ? (
                                <div className="h-[22rem] w-full" aria-label="Grafik tagihan dan outstanding per area" role="img">
                                    <ResponsiveContainer width="100%" height="100%">
                                        <BarChart data={chartAreas} layout="vertical" margin={{ top: 4, right: 88, bottom: 4, left: 8 }}>
                                            <CartesianGrid horizontal={false} stroke="var(--color-border)" />
                                            <XAxis type="number" tickFormatter={compactIdr} stroke="var(--color-muted-foreground)" fontSize={12} tickLine={false} axisLine={false} />
                                            <YAxis dataKey="area_name" type="category" width={150} tickFormatter={shortAreaLabel} stroke="var(--color-foreground)" fontSize={12} tickLine={false} axisLine={false} />
                                            <Tooltip {...chartTooltipProps} formatter={(value: number | string, name: string) => [formatIdr(value), name === 'billable' ? 'Tagihan' : 'Outstanding']} />
                                            <Legend />
                                            <Bar dataKey="billable" fill="var(--color-chart-1)" name="Tagihan" radius={[0, 3, 3, 0]} barSize={12}>
                                                <LabelList dataKey="billable" position="right" formatter={(value: number | string) => compactIdr(value as number)} fill="var(--color-foreground)" fontSize={11} />
                                            </Bar>
                                            <Bar dataKey="outstanding" fill="var(--color-chart-3)" name="Outstanding" radius={[0, 3, 3, 0]} barSize={12}>
                                                <LabelList dataKey="outstanding" position="right" formatter={(value: number | string) => compactIdr(value as number)} fill="var(--color-muted-foreground)" fontSize={11} />
                                            </Bar>
                                        </BarChart>
                                    </ResponsiveContainer>
                                </div>
                            ) : (
                                <p className="py-12 text-center text-sm text-muted-foreground">Belum ada data area untuk periode ini.</p>
                            )}
                        </ChartCard>
                        <Card>
                            <CardHeader><CardTitle>Performa area</CardTitle><CardDescription>Periode {summary.period} · scope {summary.scope} · formula {summary.formula_version}</CardDescription></CardHeader>
                            <CardContent>
                                {summary.stale && <p className="mb-3 rounded-md border border-status-degraded/30 bg-status-degraded-bg p-2 text-sm text-status-degraded">Data snapshot stale. Timestamp terakhir {formatRelative(summary.generated_at)}.</p>}
                                <div className="space-y-3 md:hidden">
                                    {areas.map((area) => <div key={area.area_id ?? 'unassigned'} className="rounded-md border p-3"><div className="flex justify-between gap-3"><span className="font-medium">{area.area_name}</span><Rate value={area.collection_rate} /></div><div className="mt-2 grid grid-cols-2 gap-2 text-sm"><span>Tagihan <strong className="block font-data tabular-nums">{formatIdr(area.billable_amount)}</strong></span><span>Pemasukan tercatat <strong className="block font-data tabular-nums">{formatIdr(area.recorded_paid_amount)}</strong></span><span>Pemasukan terverifikasi <strong className="block font-data tabular-nums">{formatIdr(area.cash_paid_amount)}</strong></span><span>Lunas legacy <strong className="block font-data tabular-nums">{formatIdr(area.legacy_settled_amount)}</strong></span><span>Outstanding <strong className="block font-data tabular-nums">{formatIdr(area.outstanding_amount)}</strong></span></div></div>)}
                                </div>
                                <div className="hidden overflow-x-auto md:block"><table className="w-full text-sm"><caption className="sr-only">Ringkasan keuangan per area</caption><thead><tr className="border-b text-left text-muted-foreground"><th scope="col" className="pb-2">Area</th><th scope="col" className="pb-2 text-right">Invoice</th><th scope="col" className="pb-2 text-right">Tagihan</th><th scope="col" className="pb-2 text-right">Pemasukan tercatat</th><th scope="col" className="pb-2 text-right">Pemasukan terverifikasi</th><th scope="col" className="pb-2 text-right">Lunas legacy</th><th scope="col" className="pb-2 text-right">Outstanding</th><th scope="col" className="pb-2 text-right">Collection</th></tr></thead><tbody>{areas.map((area) => <tr key={area.area_id ?? 'unassigned'} className="border-b last:border-0"><th scope="row" className="py-3 text-left font-medium">{area.area_name}</th><td className="py-3 text-right font-data tabular-nums">{area.invoice_count}</td><td className="py-3 text-right font-data tabular-nums">{formatIdr(area.billable_amount)}</td><td className="py-3 text-right font-data tabular-nums">{formatIdr(area.recorded_paid_amount)}</td><td className="py-3 text-right font-data tabular-nums">{formatIdr(area.cash_paid_amount)}</td><td className="py-3 text-right font-data tabular-nums">{formatIdr(area.legacy_settled_amount)}</td><td className="py-3 text-right font-data tabular-nums">{formatIdr(area.outstanding_amount)}</td><td className="py-3 text-right"><Rate value={area.collection_rate} /></td></tr>)}</tbody></table></div>
                            </CardContent>
                        </Card>
                        <Card><CardHeader><CardTitle>Data quality</CardTitle><CardDescription>Baris yang perlu ditinjau tidak dibuang diam-diam.</CardDescription></CardHeader><CardContent><div className="flex flex-wrap gap-2 text-sm">{Object.entries(summary.data_quality).map(([key, value]) => <span key={key} className="rounded-md border px-2 py-1">{key}: <strong className="font-mono tabular-nums">{value}</strong></span>)}</div></CardContent></Card>
                    </section>
                )}

                {!loading && !error && query.tab === 'detail' && (
                    <section aria-labelledby="finance-detail-heading" className="space-y-4">
                        <h2 id="finance-detail-heading" className="sr-only">Detail invoice per area</h2>
                        <Card className="border-status-degraded/30">
                            <CardHeader><CardTitle>WA tagihan massal</CardTitle><CardDescription>Preview hanya mengambil invoice outstanding. Nomor ditampilkan masked; password PPPoE dan credential tidak pernah ikut.</CardDescription></CardHeader>
                            <CardContent className="space-y-3">
                                <div className="flex flex-wrap items-center justify-between gap-3 text-sm">
                                    <span>{bulkPreview ? `${bulkPreview.eligible_count} eligible, ${bulkPreview.skipped_count} dilewati` : 'Belum ada preview'}{bulkPreview?.scan_limited ? ' · batas scan tercapai' : ''}</span>
                                    <Button type="button" variant="outline" onClick={() => void previewBulkWa()} disabled={bulkLoading}>Preview penerima</Button>
                                </div>
                                {bulkError && <p role="alert" className="rounded-md border border-status-down/30 bg-status-down-bg p-2 text-sm text-status-down">{bulkError}</p>}
                                {bulkPreview && (
                                    <>
                                        <p className="text-xs text-muted-foreground">{bulkPreview.source_note} Cap {bulkPreview.max_recipients}; rate limit {bulkPreview.rate_limit_per_minute}/menit. Status: {bulkPreview.enabled ? (bulkPreview.dry_run ? 'enabled/dry-run' : 'enabled/live') : 'disabled'}.</p>
                                        <div className="space-y-2 md:hidden">{bulkPreview.rows.map((row) => <div key={row.invoice_id} className="rounded-md border p-3 text-sm"><p className="font-medium">{row.customer_name || row.customer_code}</p><p className="font-data text-xs">{row.invoice_code} · {row.phone || '-'}</p><p className="mt-1">Outstanding: <strong className="font-data tabular-nums">{formatIdr(row.outstanding)}</strong></p></div>)}</div>
                                        <div className="hidden overflow-x-auto md:block"><table className="w-full text-sm"><caption className="sr-only">Preview penerima WA tagihan massal</caption><thead><tr className="border-b text-left text-muted-foreground"><th scope="col" className="pb-2">Pelanggan</th><th scope="col" className="pb-2">Invoice</th><th scope="col" className="pb-2">Nomor masked</th><th scope="col" className="pb-2 text-right">Outstanding</th></tr></thead><tbody>{bulkPreview.rows.map((row) => <tr key={row.invoice_id} className="border-b last:border-0"><th scope="row" className="py-2 text-left">{row.customer_name || row.customer_code}</th><td className="py-2 font-data text-xs">{row.invoice_code}</td><td className="py-2 font-data text-xs">{row.phone || '-'}</td><td className="py-2 text-right font-data tabular-nums">{formatIdr(row.outstanding)}</td></tr>)}</tbody></table></div>
                                        {bulkPreview.can_execute && <div className="flex flex-col gap-2 rounded-md border bg-muted/30 p-3 md:flex-row md:items-end"><label className="grid flex-1 gap-1 text-sm font-medium">Ketik {bulkPreview.confirmation_text}<input className="h-9 rounded-md border bg-background px-3 font-mono text-sm" value={bulkConfirmation} onChange={(event) => setBulkConfirmation(event.target.value)} /></label><Button type="button" variant="destructive" onClick={() => void executeBulkWa()} disabled={bulkLoading || bulkConfirmation !== bulkPreview.confirmation_text}>Antrekan pengiriman</Button></div>}
                                    </>
                                )}
                            </CardContent>
                        </Card>
                        <form className="flex flex-col gap-2 rounded-lg border bg-card p-4 md:flex-row md:items-end" onSubmit={submitSearch}>
                            <label className="grid flex-1 gap-1 text-sm font-medium">Cari invoice atau pelanggan<input className="h-9 rounded-md border bg-background px-3 text-sm" value={searchInput} onChange={(event) => setSearchInput(event.target.value)} placeholder="Kode invoice, kode pelanggan, nama" /></label>
                            <label className="grid gap-1 text-sm font-medium">Status<select className="h-9 rounded-md border bg-background px-3 text-sm" value={query.status} onChange={(event) => updateQuery({ status: event.target.value, page: 1 })}><option value="">Semua</option><option value="paid">Lunas</option><option value="unpaid">Belum lunas</option><option value="overdue">Jatuh tempo</option><option value="legacy_settled">Lunas legacy</option><option value="unknown">Perlu review</option></select></label>
                            <label className="grid gap-1 text-sm font-medium">Jatuh tempo<select className="h-9 rounded-md border bg-background px-3 text-sm" value={query.due} onChange={(event) => updateQuery({ due: event.target.value, page: 1 })}><option value="">Semua</option><option value="not_due">Belum jatuh tempo</option><option value="overdue">Sudah jatuh tempo</option><option value="unknown">Tanggal kosong</option></select></label>
                            <Button type="submit">Terapkan</Button>
                        </form>
                        <Card><CardHeader><CardTitle>Invoice {detail?.period || query.period}</CardTitle><CardDescription>{detail?.meta.total || 0} baris · dibuat {detail?.generated_at ? formatDateTime(detail.generated_at) : '-'}</CardDescription></CardHeader><CardContent>
                            <div className="space-y-3 md:hidden">{(detail?.data || []).map((row) => <div key={row.invoice_id} className="rounded-md border p-3"><div className="flex items-start justify-between gap-3"><div><p className="font-medium">{row.customer_name || row.customer_code}</p><p className="font-data text-xs text-muted-foreground">{row.invoice_code} · {row.area_name || 'Tanpa area'}</p></div><StatusPill status={row.status} /></div><div className="mt-3 grid grid-cols-2 gap-2 text-sm"><span>Nominal<strong className="block font-data tabular-nums">{formatIdr(row.amount)}</strong></span><span>Outstanding<strong className="block font-data tabular-nums">{formatIdr(row.outstanding)}</strong></span></div></div>)}</div>
                            <div className="hidden overflow-x-auto md:block"><table className="w-full text-sm"><caption className="sr-only">Detail invoice per area</caption><thead><tr className="border-b text-left text-muted-foreground"><th scope="col" className="pb-2">Invoice / pelanggan</th><th scope="col" className="pb-2">Area</th><th scope="col" className="pb-2">Status</th><th scope="col" className="pb-2 text-right">Nominal</th><th scope="col" className="pb-2 text-right">Terbayar efektif</th><th scope="col" className="pb-2 text-right">Outstanding</th><th scope="col" className="pb-2">Jatuh tempo</th></tr></thead><tbody>{(detail?.data || []).map((row) => <tr key={row.invoice_id} className="border-b last:border-0"><th scope="row" className="py-3 text-left"><span className="font-data text-xs">{row.invoice_code}</span><span className="block font-medium">{row.customer_name || row.customer_code}</span></th><td className="py-3">{row.area_name || 'Tanpa area'}</td><td className="py-3"><StatusPill status={row.status} /></td><td className="py-3 text-right font-data tabular-nums">{formatIdr(row.amount)}</td><td className="py-3 text-right font-data tabular-nums">{formatIdr(row.effective_paid)}</td><td className="py-3 text-right font-data tabular-nums">{formatIdr(row.outstanding)}</td><td className="py-3">{formatDate(row.due_date)}</td></tr>)}</tbody></table></div>
                            {detail && detail.meta.last_page > 1 && <div className="mt-4 flex items-center justify-between text-sm"><span>Halaman {detail.meta.current_page} dari {detail.meta.last_page}</span><div className="flex gap-2"><Button type="button" variant="outline" disabled={detail.meta.current_page <= 1} onClick={() => updateQuery({ page: detail.meta.current_page - 1 })}>Sebelumnya</Button><Button type="button" variant="outline" disabled={detail.meta.current_page >= detail.meta.last_page} onClick={() => updateQuery({ page: detail.meta.current_page + 1 })}>Berikutnya</Button></div></div>}
                        </CardContent></Card>
                    </section>
                )}

                {!loading && !error && query.tab === 'operational' && (
                    <section aria-labelledby="finance-operational-heading" className="space-y-4">
                        <h2 id="finance-operational-heading" className="sr-only">Laporan operasional per area</h2>
                        <Card><CardHeader><CardTitle>Operasional</CardTitle><CardDescription>{operations?.source_note || 'Snapshot report-only PSB dan migrasi; tidak ada write ke perangkat atau queue.'}</CardDescription></CardHeader><CardContent>
                            <div className="mb-4 grid gap-3 sm:grid-cols-3">
                                <label className="grid min-w-0 gap-1 text-sm font-medium">
                                    <span>Project</span>
                                    <select className="h-9 w-full min-w-0 rounded-md border bg-background px-3 text-sm" value={query.project} onChange={(event) => updateQuery({ project: event.target.value })}>
                                        {(operations?.options?.projects || []).map((option) => <option key={option.value} value={option.value === 'all' ? '' : option.value}>{option.label}</option>)}
                                    </select>
                                </label>
                                <label className="grid min-w-0 gap-1 text-sm font-medium">
                                    <span>Sub area</span>
                                    <select className="h-9 w-full min-w-0 rounded-md border bg-background px-3 text-sm" value={query.subArea} onChange={(event) => updateQuery({ subArea: event.target.value })}>
                                        {(operations?.options?.sub_areas || []).map((option) => <option key={option.value} value={option.value === 'all' ? '' : option.value}>{option.label}</option>)}
                                    </select>
                                </label>
                                <label className="grid min-w-0 gap-1 text-sm font-medium">
                                    <span>RW</span>
                                    <select className="h-9 w-full min-w-0 rounded-md border bg-background px-3 text-sm" value={query.rw} onChange={(event) => updateQuery({ rw: event.target.value })}>
                                        {(operations?.options?.rws || []).map((option) => <option key={option.value} value={option.value === 'all' ? '' : option.value}>{option.label}</option>)}
                                    </select>
                                </label>
                            </div>
                            {operations?.summary && (
                                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                    <Metric label="Target Migrasi" value={String(operations.summary.migration_target)} hint={`Belum migrasi ${operations.summary.not_migrated}`} />
                                    <Metric label="Sudah Migrasi" value={String(operations.summary.migrated)} hint={`${operations.summary.migration_progress}% dari target`} />
                                    <Metric label="PSB Bulan Ini" value={String(operations.summary.psb_this_month)} hint={`Target ${operations.summary.psb_target}`} />
                                    <Metric label="Progress PSB" value={`${operations.summary.psb_progress}%`} hint={`Perlu ditinjau ${operations.summary.needs_review}`} />
                                </div>
                            )}
                            {operations?.drilldowns && (
                                <div className="mt-4 flex flex-wrap gap-2 text-sm">
                                    <a className="inline-flex h-9 items-center rounded-md border px-3 font-medium hover:bg-muted" href={operations.drilldowns.migration_url}>Buka Migrasi</a>
                                    {canOpenPsbKpi && (
                                        <a className="inline-flex h-9 items-center rounded-md border px-3 font-medium hover:bg-muted" href={operations.drilldowns.psb_url}>Buka PSB WA</a>
                                    )}
                                </div>
                            )}
                            {(operations?.rows || []).length === 0 ? (
                                <p className="mt-4 rounded-md border border-dashed p-6 text-center text-sm text-muted-foreground">Belum ada summary operasional untuk filter ini.</p>
                            ) : (
                                <>
                                    <div className="mt-4 space-y-2 md:hidden">{(operations?.rows || []).map((row) => <div key={`${row.project}-${row.area}-${row.sub_area}-${row.rw}`} className="rounded-md border p-3 text-sm"><p className="font-medium">{row.project_label} · {row.area_label}</p><p className="text-xs text-muted-foreground">{row.sub_area} / {row.rw}</p><div className="mt-2 grid grid-cols-2 gap-2"><span>Target Migrasi <strong className="block font-data tabular-nums">{row.migration_target}</strong></span><span>Migrasi <strong className="block font-data tabular-nums">{row.migrated}</strong></span><span>PSB <strong className="block font-data tabular-nums">{row.psb_success}</strong></span><span>Perlu ditinjau <strong className="block font-data tabular-nums">{row.needs_review > 0 ? row.needs_review : '-'}</strong></span></div></div>)}</div>
                                    <div className="mt-4 hidden overflow-x-auto md:block"><table className="w-full text-sm"><caption className="sr-only">Rekonsiliasi operasional per area</caption><thead><tr className="border-b text-left text-muted-foreground"><th scope="col" className="pb-2">Project</th><th scope="col" className="pb-2">Area</th><th scope="col" className="pb-2">Sub area</th><th scope="col" className="pb-2">RW</th><th scope="col" className="pb-2 text-right">Target Migrasi</th><th scope="col" className="pb-2 text-right">Migrasi</th><th scope="col" className="pb-2 text-right">PSB</th><th scope="col" className="pb-2 text-right">Perlu ditinjau</th></tr></thead><tbody>{(operations?.rows || []).map((row) => <tr key={`${row.project}-${row.area}-${row.sub_area}-${row.rw}`} className="border-b last:border-0"><td className="py-3">{row.project_label}</td><td className="py-3">{row.area_label}</td><td className="py-3 text-muted-foreground">{row.sub_area}</td><td className="py-3 text-muted-foreground">{row.rw}</td><td className="py-3 text-right font-data tabular-nums">{row.migration_target}</td><td className="py-3 text-right font-data tabular-nums text-status-up">{row.migrated}</td><td className="py-3 text-right font-data tabular-nums text-status-info">{row.psb_success}</td><td className="py-3 text-right font-data tabular-nums">{row.needs_review > 0 ? row.needs_review : '-'}</td></tr>)}</tbody></table></div>
                                </>
                            )}
                            <p className="mt-4 text-xs text-muted-foreground">Dibuat {operations?.generated_at ? formatDateTime(operations.generated_at) : (operations?.last_sync || '-')}{operations?.stale ? ' · stale' : ''}. Rekonsiliasi ini tidak otomatis mengubah customer, draft, migration, queue, OLT, MikroTik, atau WA.</p>
                        </CardContent></Card>
                    </section>
                )}
            </div>
        </EnterpriseIndexLayout>
    );
}
