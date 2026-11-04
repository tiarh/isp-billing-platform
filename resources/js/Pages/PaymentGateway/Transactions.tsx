import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { EnterpriseIndexLayout } from '@/Components/enterprise/EnterpriseIndexLayout';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Badge } from '@/Components/ui/badge';
import { StatusBadge, type StatusTone } from '@/Components/StatusBadge';
import { formatIdr } from '@/lib/format';
import { formatWibDateTime } from '@/lib/dateTime';
import { CreditCard, RefreshCw, Search, AlertTriangle } from 'lucide-react';

interface Row {
    id: number;
    provider: string;
    environment: string;
    order_id: string;
    provider_reference: string | null;
    method: string;
    method_name: string | null;
    method_group: string;
    amount_invoice: number;
    fee: number;
    fee_bearer: string;
    amount_charged: number;
    status: string;
    source: string;
    expires_at: string | null;
    paid_at: string | null;
    created_at: string | null;
    status_check_count: number;
    last_status_check_at: string | null;
    last_error: string | null;
    can_check: boolean;
    invoice: { id: number; code: string; status: string; customer: string | null; customer_code: string | null } | null;
}

interface PageLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface Props {
    transactions: { data: Row[]; links: PageLink[]; total: number; from: number | null; to: number | null };
    filters: { status: string; q: string; date_from: string | null; date_to: string | null };
    summary: Record<string, { count: number; amount: number }>;
    attention: { id: number; provider: string; reference: string | null; status: string; amount: number | null; invoice_code: string | null; created_at: string | null }[];
    statuses: string[];
}

const STATUS_LABEL: Record<string, string> = {
    created: 'Dibuat',
    pending: 'Menunggu bayar',
    paid: 'Lunas',
    held: 'Ditahan (kill switch)',
    rejected: 'Perlu dicek',
    credited_balance: 'Jadi saldo pelanggan',
    failed: 'Gagal',
    expired: 'Kedaluwarsa',
};

const EVENT_LABEL: Record<string, string> = {
    held_kill_switch: 'Ditahan: kill switch OFF',
    unmatched_invoice: 'Order tidak dikenal',
    rejected_amount: 'Ditolak: invoice sudah lunas / lebih bayar',
    rejected_void: 'Ditolak: invoice void',
    rejected_amount_mismatch: 'Ditolak: nominal tidak cocok',
};

function statusTone(status: string): StatusTone {
    switch (status) {
        case 'paid':
            return 'up';
        case 'pending':
        case 'created':
            return 'info';
        case 'rejected':
        case 'held':
            return 'degraded';
        case 'failed':
            return 'down';
        default:
            return 'neutral';
    }
}

// Laravel paginator labels contain &laquo; / &raquo;; render as text, never HTML.
function pageLabel(label: string): string {
    return label.replace('&laquo;', '«').replace('&raquo;', '»');
}

function dt(iso: string | null): string {
    return formatWibDateTime(iso);
}

export default function Transactions({ transactions, filters, summary, attention, statuses }: Props) {
    const [q, setQ] = useState(filters.q);
    const [checking, setChecking] = useState<number | null>(null);

    const apply = (next: Partial<Props['filters']>) => {
        router.get(route('payment-gateway.transactions'), { ...filters, ...next }, { preserveState: true, preserveScroll: true, replace: true });
    };

    const check = (row: Row) => {
        setChecking(row.id);
        router.post(route('payment-gateway.transactions.check', row.id), {}, { preserveScroll: true, onFinish: () => setChecking(null) });
    };

    return (
        <EnterpriseIndexLayout
            title="Transaksi Online"
            pageTitle="Transaksi Online"
            subtitle="Rekonsiliasi pembayaran payment gateway (30 hari terakhir di ringkasan)"
            icon={CreditCard}
            breadcrumbs={[{ label: 'Billing' }, { label: 'Transaksi Online' }]}
        >
            <div className="space-y-6">
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    {['paid', 'pending', 'rejected', 'held'].map((s) => (
                        <button
                            key={s}
                            type="button"
                            onClick={() => apply({ status: filters.status === s ? '' : s })}
                            className={`rounded-lg border p-3 text-left transition-colors hover:bg-accent ${filters.status === s ? 'border-primary' : ''}`}
                        >
                            <div className="text-xs text-muted-foreground">{STATUS_LABEL[s]}</div>
                            <div className="text-xl font-semibold">{summary[s]?.count ?? 0}</div>
                            <div className="text-xs text-muted-foreground">{formatIdr(summary[s]?.amount ?? 0)}</div>
                        </button>
                    ))}
                </div>

                {attention.length > 0 && (
                    <Card className="border-status-degraded/40">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <AlertTriangle className="h-4 w-4 text-status-degraded" /> Perlu tindakan ops
                            </CardTitle>
                            <CardDescription>
                                Uang sudah diterima provider tetapi tidak dikreditkan otomatis. Cek di dashboard provider, lalu catat manual. Pembayaran ganda dan
                                kelebihan bayar Duitku ada di menu Kelebihan Bayar.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="text-left text-xs text-muted-foreground">
                                    <tr>
                                        <th className="py-1 pr-3">Waktu</th>
                                        <th className="py-1 pr-3">Order</th>
                                        <th className="py-1 pr-3">Invoice</th>
                                        <th className="py-1 pr-3">Nominal</th>
                                        <th className="py-1">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {attention.map((e) => (
                                        <tr key={e.id} className="border-t">
                                            <td className="py-1 pr-3 whitespace-nowrap">{dt(e.created_at)}</td>
                                            <td className="py-1 pr-3 font-mono text-xs">{e.reference ?? '-'}</td>
                                            <td className="py-1 pr-3">{e.invoice_code ?? '-'}</td>
                                            <td className="py-1 pr-3">{e.amount !== null ? formatIdr(e.amount) : '-'}</td>
                                            <td className="py-1">{EVENT_LABEL[e.status] ?? e.status}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader>
                        <div className="flex flex-wrap items-end gap-2">
                            <form
                                className="flex flex-1 items-center gap-2"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    apply({ q });
                                }}
                            >
                                <Input
                                    value={q}
                                    onChange={(e) => setQ(e.target.value)}
                                    placeholder="Cari order, VA, invoice, pelanggan"
                                    className="max-w-sm"
                                    aria-label="Cari transaksi"
                                />
                                <Button type="submit" variant="outline" size="sm" className="gap-1">
                                    <Search className="h-4 w-4" /> Cari
                                </Button>
                            </form>
                            <select
                                className="h-9 rounded-md border bg-background px-2 text-sm"
                                value={filters.status}
                                onChange={(e) => apply({ status: e.target.value })}
                                aria-label="Filter status"
                            >
                                <option value="">Semua status</option>
                                {statuses.map((s) => (
                                    <option key={s} value={s}>
                                        {STATUS_LABEL[s] ?? s}
                                    </option>
                                ))}
                            </select>
                            <Input
                                type="date"
                                className="h-9 w-auto"
                                value={filters.date_from ?? ''}
                                onChange={(e) => apply({ date_from: e.target.value || null })}
                                aria-label="Dari tanggal"
                            />
                            <Input
                                type="date"
                                className="h-9 w-auto"
                                value={filters.date_to ?? ''}
                                onChange={(e) => apply({ date_to: e.target.value || null })}
                                aria-label="Sampai tanggal"
                            />
                        </div>
                    </CardHeader>
                    <CardContent className="overflow-x-auto">
                        {transactions.data.length === 0 ? (
                            <p className="py-8 text-center text-sm text-muted-foreground">Belum ada transaksi online.</p>
                        ) : (
                            <table className="w-full text-sm">
                                <thead className="text-left text-xs text-muted-foreground">
                                    <tr>
                                        <th className="py-2 pr-3">Dibuat</th>
                                        <th className="py-2 pr-3">Invoice / Pelanggan</th>
                                        <th className="py-2 pr-3">Metode</th>
                                        <th className="py-2 pr-3 text-right">Tagihan</th>
                                        <th className="py-2 pr-3 text-right">Dibayar</th>
                                        <th className="py-2 pr-3">Status</th>
                                        <th className="py-2"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {transactions.data.map((row) => (
                                        <tr key={row.id} className="border-t align-top">
                                            <td className="py-2 pr-3 whitespace-nowrap">
                                                {dt(row.created_at)}
                                                <div className="font-mono text-[11px] text-muted-foreground">{row.order_id}</div>
                                                {row.environment !== 'production' && (
                                                    <Badge variant="outline" className="mt-1 text-[10px]">
                                                        {row.environment}
                                                    </Badge>
                                                )}
                                            </td>
                                            <td className="py-2 pr-3">
                                                {row.invoice ? (
                                                    <Link href={route('invoices.show', row.invoice.id)} className="font-medium hover:underline">
                                                        {row.invoice.code}
                                                    </Link>
                                                ) : (
                                                    '-'
                                                )}
                                                <div className="text-xs text-muted-foreground">{row.invoice?.customer ?? ''}</div>
                                            </td>
                                            <td className="py-2 pr-3">
                                                {row.method_name ?? row.method}
                                                <div className="text-xs text-muted-foreground">
                                                    {row.provider} · {row.source}
                                                </div>
                                            </td>
                                            <td className="py-2 pr-3 text-right font-data">{formatIdr(row.amount_invoice)}</td>
                                            <td className="py-2 pr-3 text-right font-mono">
                                                {formatIdr(row.amount_charged)}
                                                {row.fee > 0 && (
                                                    <div className="text-[11px] text-muted-foreground">
                                                        fee {formatIdr(row.fee)} ({row.fee_bearer === 'customer' ? 'pelanggan' : 'ISP'})
                                                    </div>
                                                )}
                                            </td>
                                            <td className="py-2 pr-3">
                                                <StatusBadge label={STATUS_LABEL[row.status] ?? row.status} tone={statusTone(row.status)} showIcon={row.status !== 'expired'} />
                                                <div className="mt-1 text-[11px] text-muted-foreground">
                                                    {row.paid_at ? `Lunas ${dt(row.paid_at)}` : row.expires_at ? `Exp ${dt(row.expires_at)}` : ''}
                                                </div>
                                                {row.last_error && <div className="mt-1 max-w-[220px] text-[11px] text-status-degraded">{row.last_error}</div>}
                                            </td>
                                            <td className="py-2 text-right">
                                                {row.can_check && (
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        className="gap-1"
                                                        disabled={checking === row.id}
                                                        onClick={() => check(row)}
                                                        title={`Sudah dicek ${row.status_check_count}x`}
                                                    >
                                                        <RefreshCw className={`h-3 w-3 ${checking === row.id ? 'animate-spin' : ''}`} /> Cek status
                                                    </Button>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}

                        {transactions.links.length > 3 && (
                            <div className="mt-4 flex flex-wrap items-center gap-1">
                                {transactions.links.map((link, idx) =>
                                    link.url ? (
                                        <Link
                                            key={idx}
                                            href={link.url}
                                            preserveScroll
                                            className={`rounded border px-2 py-1 text-xs ${link.active ? 'border-primary bg-primary/10' : ''}`}
                                        >
                                            {pageLabel(link.label)}
                                        </Link>
                                    ) : (
                                        <span key={idx} className="px-2 py-1 text-xs text-muted-foreground">
                                            {pageLabel(link.label)}
                                        </span>
                                    ),
                                )}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </EnterpriseIndexLayout>
    );
}
