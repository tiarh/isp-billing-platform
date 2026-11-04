import { EnterpriseIndexLayout } from '@/Components/enterprise/EnterpriseIndexLayout';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { StatusBadge, type StatusTone } from '@/Components/StatusBadge';
import { StatCard } from '@/Components/StatCard';
import { Link } from '@inertiajs/react';
import { Activity, AlertCircle, AlertTriangle, CheckCircle2, CreditCard, FileWarning, LayoutDashboard, MapPin, Plus, Users } from 'lucide-react';
import { formatDateTime, formatIdr, formatMonth } from '@/lib/format';

interface Transaction {
    id: number;
    amount: number;
    method: string;
    paid_at: string;
    invoice: {
        id: number;
        period: string;
        customer: {
            id: number;
            name: string;
            code: string | null;
        } | null;
    } | null;
    admin: {
        name: string;
    } | null;
}

interface DashboardStats {
    projected_revenue: number;
    actual_revenue: number;
    outstanding: number;
    overdue_count: number;
    active_customers: number;
    paid_invoices: number;
    unpaid_invoices: number;
    collection_rate: number;
    customers_without_invoice: number;
}

interface LocationRow {
    area_id: number | null;
    area_name: string;
    active: number;
    isolated: number;
    berhenti: number;
    total: number;
    unpaid_invoices: number;
    unpaid_amount: number;
}

interface OperationalArea {
    summary: {
        migration_target: number;
        migrated: number;
        not_migrated: number;
        migration_progress: number;
        psb_this_month: number;
        psb_target: number;
        psb_progress: number;
        needs_review: number;
    };
    last_sync: string;
    stale: boolean;
    drilldowns?: {
        migration_url: string;
        psb_url: string;
    };
}

interface Props {
    stats?: DashboardStats;
    can_view_billing?: boolean;
    area_label?: string;
    recent_payments: Transaction[];
    by_location?: LocationRow[];
    operational_area?: OperationalArea;
}

export default function Dashboard({ stats, can_view_billing = false, area_label = 'Semua area', recent_payments, by_location = [], operational_area }: Props) {
    const getMethodTone = (method: string): StatusTone => {
        switch (method.toLowerCase()) {
            case 'cash':
                return 'up';
            case 'transfer':
            case 'payment_gateway':
                return 'info';
            default:
                return 'neutral';
        }
    };

    const percentWidth = (value: number) => `${Math.min(Math.max(value, 0), 100)}%`;
    const ops = operational_area;
    const showBilling = can_view_billing && !!stats;

    const actions = can_view_billing ? (
        <div className="flex flex-wrap items-center gap-2">
            <Badge variant="outline" className="font-data">{formatMonth(new Date())}</Badge>
            <Badge variant="outline" className="font-data">{area_label}</Badge>
            <Button asChild size="sm">
                <Link href={route('invoices.create')}>
                    <Plus className="mr-1.5 h-4 w-4" aria-hidden="true" />
                    Buat Invoice
                </Link>
            </Button>
        </div>
    ) : undefined;

    const operationalCard = ops && (
        <Card className="border-border bg-card shadow-none">
            <CardHeader className="pb-3">
                <div className="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <CardTitle className="flex items-center gap-2 text-base">
                            <MapPin className="h-4 w-4 text-primary" aria-hidden="true" />
                            Operasional Area
                        </CardTitle>
                        <CardDescription>Migrasi Starlite dan PSB bulan ini</CardDescription>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <StatusBadge
                            label={ops.last_sync}
                            tone={ops.stale ? 'degraded' : 'up'}
                            showIcon={false}
                        />
                        {ops.summary.needs_review > 0 && (
                            <StatusBadge label={`Perlu ditinjau ${ops.summary.needs_review}`} tone="degraded" />
                        )}
                    </div>
                </div>
            </CardHeader>
            <CardContent className="space-y-4">
                <div className="grid grid-cols-2 gap-3">
                    <div className="rounded-lg border border-border bg-muted/40 p-3">
                        <span className="text-xs font-medium text-muted-foreground">Target Migrasi</span>
                        <p className="mt-1 font-data text-xl font-semibold tabular-nums">{ops.summary.migration_target}</p>
                        <p className="text-xs text-muted-foreground">Belum {ops.summary.not_migrated}</p>
                    </div>
                    <div className="rounded-lg border border-border bg-muted/40 p-3">
                        <span className="text-xs font-medium text-muted-foreground">Sudah Migrasi</span>
                        <p className="mt-1 font-data text-xl font-semibold tabular-nums">{ops.summary.migrated}</p>
                        <p className="text-xs text-muted-foreground">{ops.summary.migration_progress}%</p>
                    </div>
                    <div className="rounded-lg border border-border bg-muted/40 p-3">
                        <span className="text-xs font-medium text-muted-foreground">PSB Bulan Ini</span>
                        <p className="mt-1 font-data text-xl font-semibold tabular-nums">{ops.summary.psb_this_month}</p>
                        <p className="text-xs text-muted-foreground">Target {ops.summary.psb_target}</p>
                    </div>
                    <div className="rounded-lg border border-border bg-muted/40 p-3">
                        <span className="text-xs font-medium text-muted-foreground">Progress PSB</span>
                        <p className="mt-1 font-data text-xl font-semibold tabular-nums">{ops.summary.psb_progress}%</p>
                        <p className="text-xs text-muted-foreground">dari target bulan ini</p>
                    </div>
                </div>
                <div className="space-y-1.5">
                    <div className="flex items-center justify-between text-xs text-muted-foreground">
                        <span>Progress migrasi</span>
                        <span className="font-data tabular-nums">{ops.summary.migration_progress}%</span>
                    </div>
                    <div className="h-2 overflow-hidden rounded-full bg-muted">
                        <div className="h-full rounded-full bg-status-up transition-[width] duration-150 ease-out" style={{ width: percentWidth(ops.summary.migration_progress) }} />
                    </div>
                </div>
                {can_view_billing && (
                    <Link
                        href={route('finance.per-area.index', { tab: 'operational' })}
                        className="inline-flex text-sm font-medium text-primary hover:underline"
                    >
                        Lihat detail
                    </Link>
                )}
            </CardContent>
        </Card>
    );

    return (
        <EnterpriseIndexLayout
            title="Dashboard"
            pageTitle="Dashboard"
            subtitle={can_view_billing ? 'Ringkasan billing, koleksi, dan pembayaran terbaru' : 'Ringkasan operasional area Anda'}
            icon={LayoutDashboard}
            breadcrumbs={[{ label: 'Dashboard' }]}
            actions={actions}
        >
            <div className="space-y-6">
                {showBilling && stats && (
                    <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                        <StatCard
                            title="Tagihan bulan ini"
                            value={formatIdr(stats.projected_revenue)}
                            description={`Diterima ${formatIdr(stats.actual_revenue)}`}
                            icon={Activity}
                        />
                        <StatCard
                            title="Pelanggan aktif"
                            value={String(stats.active_customers)}
                            description="Pelanggan dengan layanan aktif"
                            icon={Users}
                        />
                        <StatCard
                            title="Piutang berjalan"
                            value={formatIdr(stats.outstanding)}
                            description={`${stats.unpaid_invoices} invoice belum dibayar`}
                            icon={CreditCard}
                        />
                        <StatCard
                            title="Jatuh tempo"
                            value={String(stats.overdue_count)}
                            description="Invoice melewati batas pembayaran"
                            icon={AlertCircle}
                        />
                    </div>
                )}

                <div className={showBilling ? 'grid gap-6 xl:grid-cols-[1fr_360px]' : 'grid gap-6'}>
                    {showBilling && stats && (
                        <div className="min-w-0 space-y-6">
                            {/* Recent Payments */}
                            <Card className="border-border bg-card shadow-none">
                                <CardHeader className="pb-3">
                                    <div className="flex items-start justify-between gap-2">
                                        <div>
                                            <CardTitle className="text-base">Recent Payments</CardTitle>
                                            <CardDescription>Pembayaran terkonfirmasi terbaru</CardDescription>
                                        </div>
                                        <Link
                                            href={route('invoices.index', { status: 'paid' })}
                                            className="shrink-0 text-sm font-medium text-primary hover:underline"
                                        >
                                            Lihat semua
                                        </Link>
                                    </div>
                                </CardHeader>
                                <CardContent className="overflow-x-auto">
                                    <Table className="min-w-[560px]">
                                        <TableHeader>
                                            <TableRow className="hover:bg-transparent border-border">
                                                <TableHead className="w-[160px]">Tanggal</TableHead>
                                                <TableHead>Pelanggan</TableHead>
                                                <TableHead className="text-right">Jumlah</TableHead>
                                                <TableHead>Periode</TableHead>
                                                <TableHead>Metode</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {recent_payments.length === 0 ? (
                                                <TableRow>
                                                    <TableCell colSpan={5} className="h-32 text-center text-muted-foreground">
                                                        Belum ada pembayaran tercatat
                                                    </TableCell>
                                                </TableRow>
                                            ) : (
                                                recent_payments.map((payment) => (
                                                    <TableRow key={payment.id} className="border-border transition-colors hover:bg-muted/50">
                                                        <TableCell className="font-data text-xs tabular-nums text-muted-foreground">
                                                            {formatDateTime(payment.paid_at)}
                                                        </TableCell>
                                                        <TableCell>
                                                            <div className="flex flex-col">
                                                                <span className="font-medium text-foreground">
                                                                    {payment.invoice?.customer?.name ?? 'Pelanggan tidak tersedia'}
                                                                </span>
                                                                {payment.invoice?.customer?.code && (
                                                                    <span className="font-data text-xs text-muted-foreground">
                                                                        {payment.invoice?.customer?.code}
                                                                    </span>
                                                                )}
                                                            </div>
                                                        </TableCell>
                                                        <TableCell className="text-right font-data font-semibold tabular-nums text-foreground">
                                                            {formatIdr(payment.amount)}
                                                        </TableCell>
                                                        <TableCell className="text-sm">
                                                            {formatMonth(payment.invoice?.period)}
                                                        </TableCell>
                                                        <TableCell>
                                                            <StatusBadge
                                                                label={payment.method.replace('_', ' ')}
                                                                tone={getMethodTone(payment.method)}
                                                                className="capitalize"
                                                            />
                                                        </TableCell>
                                                    </TableRow>
                                                ))
                                            )}
                                        </TableBody>
                                    </Table>
                                </CardContent>
                            </Card>

                            {/* Per lokasi / cabang */}
                            <Card className="border-border bg-card shadow-none">
                                <CardHeader className="pb-3">
                                    <CardTitle className="text-base">Dashboard cabang (per lokasi)</CardTitle>
                                    <CardDescription>
                                        Aktif · Isolir · Berhenti + invoice unpaid per area. User cabang hanya lihat area-nya.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent>
                                    {by_location.length === 0 ? (
                                        <p className="py-4 text-sm text-muted-foreground">Belum ada data per lokasi.</p>
                                    ) : (
                                        <div className="max-h-[420px] overflow-x-auto overflow-y-auto">
                                            <Table>
                                                <TableHeader>
                                                    <TableRow className="hover:bg-transparent border-border">
                                                        <TableHead>Lokasi</TableHead>
                                                        <TableHead className="text-right">Aktif</TableHead>
                                                        <TableHead className="text-right">Isolir</TableHead>
                                                        <TableHead className="text-right">Berhenti</TableHead>
                                                        <TableHead className="text-right">Unpaid inv</TableHead>
                                                        <TableHead className="text-right">Unpaid Rp</TableHead>
                                                    </TableRow>
                                                </TableHeader>
                                                <TableBody>
                                                    {by_location.map((row) => (
                                                        <TableRow key={row.area_id ?? 'none'} className="border-border">
                                                            <TableCell className="font-medium">{row.area_name}</TableCell>
                                                            <TableCell className="text-right font-data tabular-nums text-status-up">
                                                                {row.active}
                                                            </TableCell>
                                                            <TableCell className="text-right font-data tabular-nums text-status-degraded">
                                                                {row.isolated}
                                                            </TableCell>
                                                            <TableCell className="text-right font-data tabular-nums text-muted-foreground">
                                                                {row.berhenti}
                                                            </TableCell>
                                                            <TableCell className="text-right font-data tabular-nums">{row.unpaid_invoices}</TableCell>
                                                            <TableCell className="text-right font-data text-sm tabular-nums">
                                                                {formatIdr(row.unpaid_amount)}
                                                            </TableCell>
                                                        </TableRow>
                                                    ))}
                                                </TableBody>
                                            </Table>
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        </div>
                    )}

                    <div className="min-w-0 space-y-6">
                        {showBilling && stats && (
                            <Card className="border-border bg-card shadow-none">
                                <CardHeader className="pb-3">
                                    <CardTitle className="flex items-center gap-2 text-base">
                                        <Activity className="h-4 w-4 text-primary" aria-hidden="true" />
                                        Billing Health
                                    </CardTitle>
                                    <CardDescription>Status koleksi invoice periode berjalan</CardDescription>
                                </CardHeader>
                                <CardContent className="space-y-5">
                                    <div className="space-y-2">
                                        <div className="flex items-center justify-between text-sm">
                                            <span className="text-muted-foreground">Collection Rate</span>
                                            <span className="font-data text-lg font-semibold tabular-nums text-foreground">{stats.collection_rate}%</span>
                                        </div>
                                        <div className="h-2.5 overflow-hidden rounded-full bg-muted">
                                            <div
                                                className="h-full rounded-full bg-status-up transition-[width] duration-150 ease-out"
                                                style={{ width: percentWidth(stats.collection_rate) }}
                                            />
                                        </div>
                                    </div>

                                    <div className="grid grid-cols-3 gap-2">
                                        <div className="rounded-lg border border-status-up/20 bg-status-up-bg p-3 text-center">
                                            <div className="mb-1 flex items-center justify-center gap-1 text-xs text-muted-foreground">
                                                <CheckCircle2 className="h-3.5 w-3.5 text-status-up" aria-hidden="true" />
                                                Lunas
                                            </div>
                                            <span className="font-data text-xl font-semibold tabular-nums text-status-up">{stats.paid_invoices}</span>
                                        </div>
                                        <div className="rounded-lg border border-status-down/20 bg-status-down-bg p-3 text-center">
                                            <div className="mb-1 flex items-center justify-center gap-1 text-xs text-muted-foreground">
                                                <AlertCircle className="h-3.5 w-3.5 text-status-down" aria-hidden="true" />
                                                Belum bayar
                                            </div>
                                            <span className="font-data text-xl font-semibold tabular-nums text-status-down">{stats.unpaid_invoices}</span>
                                        </div>
                                        <div className="rounded-lg border border-status-degraded/20 bg-status-degraded-bg p-3 text-center">
                                            <div className="mb-1 flex items-center justify-center gap-1 text-xs text-muted-foreground">
                                                <FileWarning className="h-3.5 w-3.5 text-status-degraded" aria-hidden="true" />
                                                Tanpa invoice
                                            </div>
                                            <span className="font-data text-xl font-semibold tabular-nums text-status-degraded">{stats.customers_without_invoice}</span>
                                        </div>
                                    </div>

                                    {stats.customers_without_invoice > 0 && (
                                        <div className="flex items-start gap-3 rounded-lg border border-status-degraded/20 bg-status-degraded-bg p-3 text-sm">
                                            <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-status-degraded" aria-hidden="true" />
                                            <div>
                                                <p className="font-medium text-status-degraded">
                                                    {stats.customers_without_invoice} pelanggan belum punya invoice periode ini
                                                </p>
                                                <p className="mt-0.5 text-xs text-muted-foreground">
                                                    Jalankan <code className="rounded bg-status-degraded/15 px-1 py-0.5 font-data text-status-degraded">php artisan billing:generate</code> atau buat invoice manual.
                                                </p>
                                            </div>
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        )}

                        {operationalCard}
                    </div>
                </div>
            </div>
        </EnterpriseIndexLayout>
    );
}
