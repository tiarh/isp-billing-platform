import { EnterpriseIndexLayout } from '@/Components/enterprise/EnterpriseIndexLayout';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { SkeletonTable } from '@/Components/SkeletonTable';
import { Button } from '@/Components/ui/button';
import { useCallback, useEffect, useState } from 'react';
import {
    LineChart,
    Line,
    BarChart,
    Bar,
    PieChart,
    Pie,
    Cell,
    XAxis,
    YAxis,
    CartesianGrid,
    Legend,
    ResponsiveContainer,
    Tooltip,
} from 'recharts';
import { BarChart3, TrendingUp, TrendingDown, DollarSign, Users, AlertCircle } from 'lucide-react';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import { formatIdr, formatNumber } from '@/lib/format';

interface MetricCardProps {
    title: string;
    value: string;
    trend?: string;
    trendDirection?: 'up' | 'down';
    icon?: React.ReactNode;
}

function MetricCard({ title, value, trend, trendDirection, icon }: MetricCardProps) {
    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                <CardTitle className="text-sm font-medium">{title}</CardTitle>
                {icon && <div className="h-4 w-4 text-muted-foreground">{icon}</div>}
            </CardHeader>
            <CardContent>
                <div className="text-2xl font-bold">{value}</div>
                {trend && (
                    <p className="text-xs text-muted-foreground flex items-center gap-1 mt-1">
                        {trendDirection === 'up' && <TrendingUp className="h-3 w-3 text-status-up" />}
                        {trendDirection === 'down' && <TrendingDown className="h-3 w-3 text-status-down" />}
                        {trend}
                    </p>
                )}
            </CardContent>
        </Card>
    );
}

type SectionKey =
    | 'revenueTrend'
    | 'mrr'
    | 'collectionRate'
    | 'revenueByArea'
    | 'packagePerformance'
    | 'paymentMethods'
    | 'outstandingAging';

const ENDPOINTS: Record<SectionKey, string> = {
    revenueTrend: '/api/analytics/revenue-trend',
    mrr: '/api/analytics/mrr',
    collectionRate: '/api/analytics/collection-rate',
    revenueByArea: '/api/analytics/revenue-by-area',
    packagePerformance: '/api/analytics/package-performance',
    paymentMethods: '/api/analytics/payment-methods',
    outstandingAging: '/api/analytics/outstanding-aging',
};

const SECTION_KEYS = Object.keys(ENDPOINTS) as SectionKey[];

type Results = Partial<Record<SectionKey, any>>;
type Failures = Partial<Record<SectionKey, boolean>>;

const AGE_BUCKET_LABELS: Record<string, string> = {
    '0-30 days': '0–30 hari',
    '30-60 days': '31–60 hari',
    '60-90 days': '61–90 hari',
    '90+ days': '> 90 hari',
};

const PAYMENT_METHOD_LABELS: Record<string, string> = {
    cash: 'Tunai',
    transfer: 'Transfer',
    qris: 'QRIS',
    midtrans: 'Midtrans',
};

// Angka dari SUM() MySQL datang sebagai string; recharts butuh number agar skala sumbu benar.
function toNumber(value: unknown): number {
    const parsed = Number(value);

    return Number.isFinite(parsed) ? parsed : 0;
}

function asRows(value: unknown): any[] {
    return Array.isArray(value) ? value : [];
}

const compactJuta = (value: number | string) => `${formatNumber(Number(value) / 1000000, { maximumFractionDigits: 1 })} jt`;

const tooltipProps = {
    formatter: (value: number | string, name: string) => [formatIdr(value), name] as [string, string],
    contentStyle: {
        background: 'var(--color-popover)',
        border: '1px solid var(--color-border)',
        borderRadius: 8,
        color: 'var(--color-popover-foreground)',
        fontSize: 12,
    },
    labelStyle: { color: 'var(--color-foreground)', fontWeight: 600 },
    cursor: { fill: 'var(--color-muted)', opacity: 0.4 },
};

function ChartBody({
    failed,
    empty,
    emptyMessage,
    height,
    label,
    onRetry,
    children,
}: {
    failed: boolean;
    empty: boolean;
    emptyMessage: string;
    height: number;
    label: string;
    onRetry: () => void;
    children: React.ReactElement;
}) {
    if (failed) {
        return (
            <div role="alert" className="flex flex-col items-start gap-3 rounded-md border border-destructive/30 p-4 text-sm">
                <p className="flex items-center gap-2 text-destructive">
                    <AlertCircle className="h-4 w-4" aria-hidden="true" />
                    Data grafik ini gagal dimuat.
                </p>
                <Button type="button" size="sm" variant="outline" onClick={onRetry}>
                    Coba lagi
                </Button>
            </div>
        );
    }

    if (empty) {
        return <p className="py-12 text-center text-sm text-muted-foreground">{emptyMessage}</p>;
    }

    return (
        <div className="w-full" style={{ height }} role="img" aria-label={label}>
            <ResponsiveContainer width="100%" height="100%">
                {children}
            </ResponsiveContainer>
        </div>
    );
}

export default function Index() {
    const [results, setResults] = useState<Results>({});
    const [failures, setFailures] = useState<Failures>({});
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(false);

    const fetchAnalytics = useCallback(async () => {
        setLoading(true);
        setError(false);

        const parseResponse = (response: Response) => {
            if (!response.ok) throw new Error('Analytics request failed');

            return response.json();
        };

        // allSettled: satu endpoint gagal tidak boleh menjatuhkan seluruh laporan.
        const settled = await Promise.allSettled(
            SECTION_KEYS.map((key) => fetch(ENDPOINTS[key]).then(parseResponse)),
        );

        const nextResults: Results = {};
        const nextFailures: Failures = {};
        settled.forEach((outcome, index) => {
            const key = SECTION_KEYS[index];
            if (outcome.status === 'fulfilled') {
                nextResults[key] = outcome.value;
            } else {
                console.error(`Failed to fetch analytics (${key}):`, outcome.reason);
                nextFailures[key] = true;
            }
        });

        setResults(nextResults);
        setFailures(nextFailures);
        if (Object.keys(nextFailures).length === SECTION_KEYS.length) {
            setError(true);
        }
        setLoading(false);
    }, []);

    useEffect(() => {
        void fetchAnalytics();
    }, [fetchAnalytics]);

    const retry = () => void fetchAnalytics();

    if (loading) {
        return (
            <EnterpriseIndexLayout
                title="Laporan Keuangan"
                pageTitle="Laporan Keuangan"
                subtitle="Insight pendapatan dan performa bisnis"
                icon={BarChart3}
                breadcrumbs={[{ label: 'Laporan Keuangan' }]}
            >
                <div role="status" aria-label="Memuat laporan keuangan">
                    <SkeletonTable rows={4} columns={4} />
                </div>
            </EnterpriseIndexLayout>
        );
    }

    if (error) {
        return (
            <EnterpriseIndexLayout
                title="Laporan Keuangan"
                pageTitle="Laporan Keuangan"
                subtitle="Insight pendapatan dan performa bisnis"
                icon={BarChart3}
                breadcrumbs={[{ label: 'Laporan Keuangan' }]}
            >
                <Card role="alert" className="border-destructive/30">
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-destructive">
                            <AlertCircle className="h-4 w-4" aria-hidden="true" />
                            Laporan belum tersedia
                        </CardTitle>
                        <CardDescription>
                            Data laporan gagal dimuat. Coba lagi untuk memuat ulang data terbaru.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Button type="button" variant="outline" onClick={retry}>
                            Coba lagi
                        </Button>
                    </CardContent>
                </Card>
            </EnterpriseIndexLayout>
        );
    }

    const mrrData = results.mrr ?? null;
    const collectionData = results.collectionRate ?? null;
    const revenueTrendData = asRows(results.revenueTrend).map((row) => ({
        ...row,
        total_invoiced: toNumber(row.total_invoiced),
        total_collected: toNumber(row.total_collected),
        outstanding: toNumber(row.outstanding),
    }));
    const outstandingAgingData = asRows(results.outstandingAging).map((row) => ({
        ...row,
        age_label: AGE_BUCKET_LABELS[row.age_bucket] ?? row.age_bucket,
        total_amount: toNumber(row.total_amount),
    }));
    const areaData = asRows(results.revenueByArea).map((row) => ({
        ...row,
        total_billed: toNumber(row.total_billed),
        total_collected: toNumber(row.total_collected),
    }));
    const packageData = asRows(results.packagePerformance).map((row) => ({
        ...row,
        total_revenue: toNumber(row.total_revenue),
    }));
    const paymentMethodData = asRows(results.paymentMethods).map((row) => ({
        ...row,
        method_label: PAYMENT_METHOD_LABELS[String(row.method ?? '').toLowerCase()] ?? (row.method || 'Lainnya'),
        transaction_count: toNumber(row.transaction_count),
        total_amount: toNumber(row.total_amount),
    }));

    const growth = toNumber(mrrData?.growth_percentage);
    const verticalChartHeight = (rows: number) => Math.max(220, rows * 44 + 60);

    return (
        <EnterpriseIndexLayout
            title="Laporan Keuangan"
            pageTitle="Laporan Keuangan"
            subtitle="Insight pendapatan dan performa bisnis"
            icon={BarChart3}
            breadcrumbs={[{ label: 'Laporan Keuangan' }]}
        >
            <div className="space-y-6">
                    {/* Summary Metrics */}
                    <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4 mb-6">
                        <MetricCard
                            title="MRR Saat Ini"
                            value={failures.mrr ? '-' : formatIdr(mrrData?.current_mrr || 0)}
                            trend={failures.mrr ? 'Gagal dimuat' : `${growth >= 0 ? '+' : ''}${formatNumber(growth)}% dari bulan lalu`}
                            trendDirection={failures.mrr ? undefined : growth >= 0 ? 'up' : 'down'}
                            icon={<DollarSign />}
                        />
                        <MetricCard
                            title="Tingkat Koleksi"
                            value={failures.collectionRate ? '-' : `${collectionData?.collection_rate || 0}%`}
                            trend={failures.collectionRate ? 'Gagal dimuat' : 'Bulan ini'}
                            icon={<TrendingUp />}
                        />
                        <MetricCard
                            title="Piutang Belum Lunas"
                            value={failures.collectionRate ? '-' : formatIdr(collectionData?.by_status?.unpaid?.total_amount || 0)}
                            trend={failures.collectionRate ? 'Gagal dimuat' : `${collectionData?.by_status?.unpaid?.count || 0} invoice belum bayar`}
                            icon={<AlertCircle />}
                        />
                        <MetricCard
                            title="Rata-rata Hari Bayar"
                            value={failures.collectionRate ? '-' : `${collectionData?.avg_days_to_payment || 0} hari`}
                            trend={failures.collectionRate ? 'Gagal dimuat' : 'Efisiensi pembayaran'}
                            icon={<Users />}
                        />
                    </div>

                    <Tabs defaultValue="revenue" className="space-y-4">
                        <TabsList className="flex h-auto flex-wrap justify-start">
                            <TabsTrigger value="revenue">Pendapatan</TabsTrigger>
                            <TabsTrigger value="areas">Area</TabsTrigger>
                            <TabsTrigger value="packages">Paket</TabsTrigger>
                            <TabsTrigger value="payments">Pembayaran</TabsTrigger>
                        </TabsList>

                        <TabsContent value="revenue" className="space-y-4">
                            {/* Monthly Revenue Trend */}
                            <Card>
                                <CardHeader>
                                    <CardTitle>Tren Pendapatan Bulanan (12 Bulan)</CardTitle>
                                    <CardDescription>Perbandingan tagihan vs koleksi dari waktu ke waktu</CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <ChartBody
                                        failed={Boolean(failures.revenueTrend)}
                                        empty={revenueTrendData.length === 0}
                                        emptyMessage="Belum ada data tagihan dalam 12 bulan terakhir."
                                        height={350}
                                        label="Grafik tren tagihan, koleksi, dan piutang per bulan"
                                        onRetry={retry}
                                    >
                                        <LineChart data={revenueTrendData} margin={{ top: 8, right: 16, bottom: 0, left: 0 }}>
                                            <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="var(--color-border)" />
                                            <XAxis dataKey="month" stroke="var(--color-muted-foreground)" fontSize={12} tickLine={false} axisLine={false} />
                                            <YAxis tickFormatter={compactJuta} stroke="var(--color-muted-foreground)" fontSize={12} tickLine={false} axisLine={false} width={64} />
                                            <Tooltip {...tooltipProps} cursor={{ stroke: 'var(--color-border)' }} />
                                            <Legend wrapperStyle={{ paddingTop: '12px' }} />
                                            <Line type="monotone" dataKey="total_invoiced" stroke="var(--color-chart-1)" name="Ditagihkan" strokeWidth={3} dot={false} activeDot={{ r: 6 }} />
                                            <Line type="monotone" dataKey="total_collected" stroke="var(--color-chart-2)" name="Terkoleksi" strokeWidth={3} dot={false} activeDot={{ r: 6 }} />
                                            <Line type="monotone" dataKey="outstanding" stroke="var(--color-chart-3)" name="Piutang" strokeWidth={3} dot={false} activeDot={{ r: 6 }} />
                                        </LineChart>
                                    </ChartBody>
                                </CardContent>
                            </Card>

                            {/* Outstanding Revenue Aging */}
                            <Card>
                                <CardHeader>
                                    <CardTitle>Umur Piutang</CardTitle>
                                    <CardDescription>Invoice belum lunas per periode keterlambatan</CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <ChartBody
                                        failed={Boolean(failures.outstandingAging)}
                                        empty={outstandingAgingData.length === 0}
                                        emptyMessage="Tidak ada invoice yang lewat jatuh tempo."
                                        height={300}
                                        label="Grafik nominal piutang per umur keterlambatan"
                                        onRetry={retry}
                                    >
                                        <BarChart data={outstandingAgingData} margin={{ top: 8, right: 16, bottom: 0, left: 0 }}>
                                            <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="var(--color-border)" />
                                            <XAxis dataKey="age_label" stroke="var(--color-muted-foreground)" fontSize={12} tickLine={false} axisLine={false} />
                                            <YAxis tickFormatter={compactJuta} stroke="var(--color-muted-foreground)" fontSize={12} tickLine={false} axisLine={false} width={64} />
                                            <Tooltip {...tooltipProps} />
                                            <Legend wrapperStyle={{ paddingTop: '12px' }} />
                                            <Bar dataKey="total_amount" fill="var(--color-chart-5)" radius={[4, 4, 0, 0]} name="Nominal" />
                                        </BarChart>
                                    </ChartBody>
                                </CardContent>
                            </Card>
                        </TabsContent>

                        <TabsContent value="areas" className="space-y-4">
                            <Card>
                                <CardHeader>
                                    <CardTitle>Pendapatan per Area (Top 10)</CardTitle>
                                    <CardDescription>Tagihan dan koleksi per area, ditampilkan berdampingan (3 bulan terakhir)</CardDescription>
                                </CardHeader>
                                <CardContent>
                                    {/* L1: bar berdampingan (tidak ditumpuk), jadi tinggi batang = nilai sebenarnya, bukan tagihan + koleksi. */}
                                    <ChartBody
                                        failed={Boolean(failures.revenueByArea)}
                                        empty={areaData.length === 0}
                                        emptyMessage="Belum ada data tagihan per area."
                                        height={verticalChartHeight(areaData.length)}
                                        label="Grafik tagihan dan koleksi per area"
                                        onRetry={retry}
                                    >
                                        <BarChart data={areaData} layout="vertical" margin={{ top: 4, right: 16, bottom: 4, left: 8 }} barGap={2}>
                                            <CartesianGrid strokeDasharray="3 3" horizontal={false} stroke="var(--color-border)" />
                                            <XAxis type="number" tickFormatter={compactJuta} stroke="var(--color-muted-foreground)" fontSize={12} tickLine={false} axisLine={false} />
                                            <YAxis dataKey="area_name" type="category" width={140} stroke="var(--color-foreground)" fontSize={12} tickLine={false} axisLine={false} />
                                            <Tooltip {...tooltipProps} />
                                            <Legend wrapperStyle={{ paddingTop: '12px' }} />
                                            <Bar dataKey="total_billed" fill="var(--color-chart-1)" radius={[0, 4, 4, 0]} barSize={12} name="Ditagihkan" />
                                            <Bar dataKey="total_collected" fill="var(--color-chart-2)" radius={[0, 4, 4, 0]} barSize={12} name="Terkoleksi" />
                                        </BarChart>
                                    </ChartBody>
                                </CardContent>
                            </Card>
                        </TabsContent>

                        <TabsContent value="packages" className="space-y-4">
                            <Card>
                                <CardHeader>
                                    <CardTitle>Performa Paket</CardTitle>
                                    <CardDescription>Pendapatan dan jumlah pelanggan per paket</CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <ChartBody
                                        failed={Boolean(failures.packagePerformance)}
                                        empty={packageData.length === 0}
                                        emptyMessage="Belum ada data pendapatan per paket."
                                        height={verticalChartHeight(packageData.length)}
                                        label="Grafik pendapatan per paket"
                                        onRetry={retry}
                                    >
                                        <BarChart data={packageData} layout="vertical" margin={{ top: 4, right: 16, bottom: 4, left: 8 }}>
                                            <CartesianGrid strokeDasharray="3 3" horizontal={false} stroke="var(--color-border)" />
                                            <XAxis type="number" tickFormatter={compactJuta} stroke="var(--color-muted-foreground)" fontSize={12} tickLine={false} axisLine={false} />
                                            <YAxis dataKey="package_name" type="category" width={160} stroke="var(--color-foreground)" fontSize={12} tickLine={false} axisLine={false} />
                                            <Tooltip {...tooltipProps} />
                                            <Legend wrapperStyle={{ paddingTop: '12px' }} />
                                            <Bar dataKey="total_revenue" fill="var(--color-chart-4)" radius={[0, 4, 4, 0]} barSize={16} name="Pendapatan (3 bln)" />
                                        </BarChart>
                                    </ChartBody>
                                </CardContent>
                            </Card>
                        </TabsContent>

                        <TabsContent value="payments" className="space-y-4">
                            <Card>
                                <CardHeader>
                                    <CardTitle>Distribusi Metode Bayar</CardTitle>
                                    <CardDescription>Preferensi pembayaran pelanggan (jumlah transaksi, 6 bulan terakhir)</CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <ChartBody
                                        failed={Boolean(failures.paymentMethods)}
                                        empty={paymentMethodData.length === 0}
                                        emptyMessage="Belum ada transaksi terverifikasi dalam 6 bulan terakhir."
                                        height={300}
                                        label="Grafik distribusi metode pembayaran"
                                        onRetry={retry}
                                    >
                                        <PieChart>
                                            <Pie
                                                data={paymentMethodData}
                                                cx="50%"
                                                cy="50%"
                                                innerRadius={60}
                                                outerRadius={100}
                                                paddingAngle={5}
                                                dataKey="transaction_count"
                                                nameKey="method_label"
                                            >
                                                {paymentMethodData.map((entry: any, index: number) => (
                                                    <Cell key={`cell-${entry.method_label}-${index}`} fill={`var(--color-chart-${(index % 5) + 1})`} stroke="var(--color-card)" strokeWidth={2} />
                                                ))}
                                            </Pie>
                                            <Tooltip
                                                contentStyle={tooltipProps.contentStyle}
                                                formatter={(value: number | string, name: string, item: any) => [
                                                    `${formatNumber(Number(value), { maximumFractionDigits: 0 })} transaksi · ${formatIdr(item?.payload?.total_amount)}`,
                                                    name,
                                                ]}
                                            />
                                            <Legend />
                                        </PieChart>
                                    </ChartBody>
                                </CardContent>
                            </Card>
                        </TabsContent>
                    </Tabs>
            </div>
        </EnterpriseIndexLayout>
    );
}
