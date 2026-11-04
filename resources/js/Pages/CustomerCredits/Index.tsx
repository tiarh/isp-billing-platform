import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { EnterpriseIndexLayout } from '@/Components/enterprise/EnterpriseIndexLayout';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { StatusBadge, type StatusTone } from '@/Components/StatusBadge';
import { formatIdr } from '@/lib/format';
import { formatWibDateTime } from '@/lib/dateTime';
import { Search, Wallet } from 'lucide-react';

interface OpenInvoice {
    id: number;
    code: string;
    period: string | null;
    remaining: number;
}

interface Row {
    id: number;
    status: string;
    reason_code: string;
    amount: number;
    provider: string | null;
    gateway_reference: string | null;
    reason: string | null;
    created_at: string | null;
    resolved_at: string | null;
    applied_at: string | null;
    customer: { id: number; name: string; code: string; archived: boolean } | null;
    source_invoice: { id: number; code: string } | null;
    applied_invoice: { id: number; code: string } | null;
    actor: string | null;
    open_invoices: OpenInvoice[];
}

interface PageLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface Props {
    credits: { data: Row[]; links: PageLink[]; total: number };
    filters: { status: string; q: string };
    summary: Record<string, { count: number; amount: number }>;
    statuses: string[];
}

const STATUS_LABEL: Record<string, string> = {
    available: 'Saldo tersedia',
    needs_review: 'Perlu cek admin',
    applied: 'Sudah diterapkan',
    refunded_manual: 'Sudah direfund manual',
    dismissed: 'Diabaikan',
};

const REASON_LABEL: Record<string, string> = {
    double_payment: 'Bayar ganda (tagihan sudah lunas)',
    overpayment: 'Kelebihan dari kode bayar lama (tagihan sudah dilunasi)',
    void_invoice: 'Dibayar ke tagihan yang dibatalkan',
};

function statusTone(status: string): StatusTone {
    switch (status) {
        case 'available':
            return 'up';
        case 'needs_review':
            return 'degraded';
        case 'applied':
            return 'info';
        default:
            return 'neutral';
    }
}

// Laravel paginator labels contain &laquo; / &raquo;; render as text, never HTML.
function pageLabel(label: string): string {
    return label.replace('&laquo;', '«').replace('&raquo;', '»');
}

function CreditActions({ row }: { row: Row }) {
    const [busy, setBusy] = useState(false);
    const [invoiceId, setInvoiceId] = useState<string>(row.open_invoices[0] ? String(row.open_invoices[0].id) : '');
    const [amount, setAmount] = useState('');
    const [reason, setReason] = useState('');
    const [mode, setMode] = useState<'' | 'refunded_manual' | 'dismissed'>('');

    const post = (name: string, data: Record<string, string | number | null>) => {
        setBusy(true);
        router.post(route(name, row.id), data, { preserveScroll: true, onFinish: () => setBusy(false) });
    };

    const resolve = (action: 'make_balance' | 'refunded_manual' | 'dismissed') => post('customer-credits.resolve', { action, reason });

    if (row.status !== 'available' && row.status !== 'needs_review') {
        return null;
    }

    return (
        <div className="space-y-2">
            {row.status === 'needs_review' && (
                <Button size="sm" data-testid={`credit-make-balance-${row.id}`} disabled={busy} onClick={() => resolve('make_balance')}>
                    Jadikan saldo
                </Button>
            )}

            {row.status === 'available' && (
                <div className="space-y-1" data-testid={`credit-apply-${row.id}`}>
                    {row.open_invoices.length === 0 ? (
                        <p className="text-xs text-muted-foreground">Tidak ada tagihan belum lunas untuk pelanggan ini.</p>
                    ) : (
                        <div className="flex flex-wrap items-center gap-1">
                            <select
                                className="h-8 rounded-md border bg-background px-2 text-xs"
                                value={invoiceId}
                                onChange={(e) => setInvoiceId(e.target.value)}
                                aria-label="Tagihan tujuan"
                            >
                                {row.open_invoices.map((i) => (
                                    <option key={i.id} value={i.id}>
                                        {i.code} ({i.period ?? '-'}) sisa {formatIdr(i.remaining)}
                                    </option>
                                ))}
                            </select>
                            <Input
                                type="number"
                                min={1}
                                className="h-8 w-28 text-xs"
                                placeholder="Otomatis"
                                value={amount}
                                onChange={(e) => setAmount(e.target.value)}
                                aria-label="Nominal diterapkan (kosong = maksimal)"
                            />
                            <Button
                                size="sm"
                                disabled={busy || invoiceId === ''}
                                onClick={() =>
                                    post('customer-credits.apply', {
                                        invoice_id: Number(invoiceId),
                                        amount: amount === '' ? null : Number(amount),
                                        expected_amount: row.amount,
                                    })
                                }
                            >
                                Terapkan ke tagihan
                            </Button>
                        </div>
                    )}
                </div>
            )}

            <div className="flex flex-wrap gap-1">
                <Button size="sm" variant="outline" disabled={busy} onClick={() => setMode(mode === 'refunded_manual' ? '' : 'refunded_manual')}>
                    Sudah direfund manual
                </Button>
                <Button size="sm" variant="outline" disabled={busy} onClick={() => setMode(mode === 'dismissed' ? '' : 'dismissed')}>
                    Abaikan
                </Button>
            </div>
            {mode !== '' && (
                <div className="flex flex-wrap items-center gap-1" data-testid={`credit-reason-${row.id}`}>
                    <Input
                        className="h-8 w-56 text-xs"
                        placeholder="Alasan (wajib)"
                        value={reason}
                        onChange={(e) => setReason(e.target.value)}
                        aria-label="Alasan"
                    />
                    <Button size="sm" disabled={busy || reason.trim().length < 3} onClick={() => resolve(mode)}>
                        Simpan
                    </Button>
                </div>
            )}
        </div>
    );
}

export default function CustomerCreditsIndex({ credits, filters, summary, statuses }: Props) {
    const [q, setQ] = useState(filters.q);

    const apply = (next: Partial<Props['filters']>) => {
        router.get(route('customer-credits.index'), { ...filters, ...next }, { preserveState: true, preserveScroll: true, replace: true });
    };

    return (
        <EnterpriseIndexLayout
            title="Kelebihan Bayar"
            pageTitle="Kelebihan Bayar"
            subtitle="Saldo dari pembayaran ganda atau kelebihan bayar online (tanpa refund)"
            icon={Wallet}
            breadcrumbs={[{ label: 'Billing' }, { label: 'Kelebihan Bayar' }]}
        >
            <div className="space-y-6">
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-5">
                    {statuses.map((s) => (
                        <button
                            key={s}
                            type="button"
                            onClick={() => apply({ status: filters.status === s ? '' : s })}
                            className={`rounded-lg border p-3 text-left transition-colors hover:bg-accent ${filters.status === s ? 'border-primary' : ''}`}
                        >
                            <div className="text-xs text-muted-foreground">{STATUS_LABEL[s] ?? s}</div>
                            <div className="text-xl font-semibold">{summary[s]?.count ?? 0}</div>
                            <div className="text-xs text-muted-foreground">{formatIdr(summary[s]?.amount ?? 0)}</div>
                        </button>
                    ))}
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Daftar kelebihan bayar</CardTitle>
                        <CardDescription>
                            Pembayaran pertama selalu melunasi tagihan. Pembayaran kedua penuh menjadi saldo. Jika kode bayar lama lebih besar dari sisa tagihan, tagihan
                            tetap dilunasi dan kelebihannya menunggu keputusan admin. Pelanggan tidak menerima WA untuk kasus ini.
                        </CardDescription>
                        <div className="flex flex-wrap items-end gap-2 pt-2">
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
                                    placeholder="Cari pelanggan, kode tagihan, order"
                                    className="max-w-sm"
                                    aria-label="Cari kelebihan bayar"
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
                        </div>
                    </CardHeader>
                    <CardContent className="overflow-x-auto">
                        {credits.data.length === 0 ? (
                            <p className="py-8 text-center text-sm text-muted-foreground">Belum ada kelebihan bayar.</p>
                        ) : (
                            <table className="w-full text-sm">
                                <thead className="text-left text-xs text-muted-foreground">
                                    <tr>
                                        <th className="py-2 pr-3">Dicatat</th>
                                        <th className="py-2 pr-3">Pelanggan</th>
                                        <th className="py-2 pr-3">Sumber</th>
                                        <th className="py-2 pr-3 text-right">Nominal</th>
                                        <th className="py-2 pr-3">Status</th>
                                        <th className="py-2">Tindakan</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {credits.data.map((row) => (
                                        <tr key={row.id} className="border-t align-top" data-testid={`credit-row-${row.id}`}>
                                            <td className="py-2 pr-3 whitespace-nowrap">{formatWibDateTime(row.created_at)}</td>
                                            <td className="py-2 pr-3">
                                                <div className="font-medium">{row.customer?.name ?? '-'}{row.customer?.archived ? ' (arsip)' : ''}</div>
                                                <div className="text-xs text-muted-foreground">{row.customer?.code ?? ''}</div>
                                            </td>
                                            <td className="py-2 pr-3">
                                                {row.source_invoice ? (
                                                    <Link href={route('invoices.show', row.source_invoice.id)} className="hover:underline">
                                                        {row.source_invoice.code}
                                                    </Link>
                                                ) : (
                                                    '-'
                                                )}
                                                <div className="text-xs text-muted-foreground">{REASON_LABEL[row.reason_code] ?? row.reason_code}</div>
                                                <div className="font-mono text-[11px] text-muted-foreground">{row.gateway_reference ?? ''}</div>
                                            </td>
                                            <td className="py-2 pr-3 text-right font-data">{formatIdr(row.amount)}</td>
                                            <td className="py-2 pr-3">
                                                <StatusBadge label={STATUS_LABEL[row.status] ?? row.status} tone={statusTone(row.status)} />
                                                {row.applied_invoice && (
                                                    <div className="mt-1 text-[11px] text-muted-foreground">
                                                        Dipakai di {row.applied_invoice.code} {formatWibDateTime(row.applied_at)}
                                                    </div>
                                                )}
                                                {row.reason && <div className="mt-1 max-w-[220px] text-[11px] text-muted-foreground">Alasan: {row.reason}</div>}
                                                {row.actor && <div className="text-[11px] text-muted-foreground">Oleh {row.actor}</div>}
                                            </td>
                                            <td className="py-2">
                                                <CreditActions row={row} />
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}

                        {credits.links.length > 3 && (
                            <div className="mt-4 flex flex-wrap items-center gap-1">
                                {credits.links.map((link, idx) =>
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
