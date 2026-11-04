import { EnterpriseIndexLayout } from '@/Components/enterprise/EnterpriseIndexLayout';
import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/Components/ui/button';
import { Eye, CreditCard, Calendar, AlertCircle, AlertTriangle, CheckCircle2, Plus, Download, FileText } from "lucide-react";
import Checkbox from '@/Components/Checkbox';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from "@/Components/ui/dropdown-menu";
import DataTable, { Column, FilterConfig, PaginatedData } from '@/Components/DataTable';
import { ConfirmDestructive } from '@/Components/ConfirmDestructive';
import { StatusBadge } from '@/Components/StatusBadge';
import { isPastDueWib } from '@/lib/dateTime';
import { formatDate, formatIdr, formatMonth } from '@/lib/format';
import { invoiceStatusLabel } from '@/lib/invoiceLabels';
import { PageProps } from '@/types';

interface Customer {
    id: number;
    name: string;
    code: string | null;
    area_id?: number | null;
    area?: {
        name: string;
    } | null;
    package?: {
        id: number;
        name: string;
        price: number;
        rate_limit?: string | null;
    } | null;
}

interface Area {
    id: number;
    name: string;
    code?: string | null;
}

interface Invoice {
    id: number;
    code: string | null;
    period: string;
    amount: number;
    status: 'unpaid' | 'paid' | 'void' | 'overdue';
    due_date: string;
    package_price: number | null;
    paid_amount: number;
    amount_mismatch: boolean;
    transactions_count: number;
    customer: Customer;
}

interface Props {
    invoices: PaginatedData<Invoice>;
    filters: {
        search?: string;
        status?: string;
        sort?: string;
        direction?: 'asc' | 'desc';
        limit?: string;
        area_id?: string;
        period?: string;
        customer_id?: string | number | null;
    };
    areas: Area[];
    periods: string[];
}

export default function Index({ invoices, filters = {}, areas = [], periods = [] }: Props) {
    const { auth } = usePage<PageProps>().props;
    const isAdmin = auth.user.role === 'admin' || auth.user.role === 'superadmin';
    const [selectedIds, setSelectedIds] = useState<number[]>([]);
    const [paymentMethod, setPaymentMethod] = useState('');
    const [pendingConfirmation, setPendingConfirmation] = useState<'bulk-payment' | null>(null);
    const [confirmationProcessing, setConfirmationProcessing] = useState(false);
    const payableIds = useMemo(
        () => invoices.data.filter((invoice) => invoice.status === 'unpaid').map((invoice) => invoice.id),
        [invoices.data]
    );
    const selectedInvoices = invoices.data.filter((invoice) => selectedIds.includes(invoice.id) && invoice.status === 'unpaid');
    const selectedTotal = selectedInvoices.reduce((sum, invoice) => sum + Number(invoice.amount || 0), 0);
    const allPayableSelected = payableIds.length > 0 && payableIds.every((id) => selectedIds.includes(id));

    // I5: pilihan bulk hanya berlaku untuk halaman yang tampil. Saat data
    // halaman berganti (paging/filter), pilihan yang tidak lagi terlihat
    // dibuang secara eksplisit, bukan diam-diam diabaikan saat submit.
    useEffect(() => {
        setSelectedIds((current) => {
            const next = current.filter((id) => payableIds.includes(id));
            return next.length === current.length ? current : next;
        });
    }, [payableIds]);

    const toggleAllPayable = (checked: boolean) => {
        setSelectedIds((current) => checked
            ? Array.from(new Set([...current, ...payableIds]))
            : current.filter((id) => !payableIds.includes(id)));
    };

    const toggleInvoice = (id: number, checked: boolean) => {
        setSelectedIds((current) => checked
            ? Array.from(new Set([...current, id]))
            : current.filter((selected) => selected !== id));
    };

    const handleBulkPayment = () => {
        if (!paymentMethod || selectedInvoices.length === 0) return;
        setPendingConfirmation('bulk-payment');
    };

    const executeBulkPayment = () => {
        if (!paymentMethod || selectedInvoices.length === 0) {
            setPendingConfirmation(null);
            return;
        }

        setConfirmationProcessing(true);
        const idempotencyKey = typeof crypto !== 'undefined' && crypto.randomUUID
            ? `invoice-bulk-${crypto.randomUUID()}`
            : `invoice-bulk-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
        router.post(route('payments.bulk'), {
            invoice_ids: selectedInvoices.map((invoice) => invoice.id),
            invoice_confirmations: selectedInvoices.map((invoice) => ({
                id: invoice.id,
                code: invoice.code,
                customer_code: invoice.customer.code,
                customer_name: invoice.customer.name,
                area_id: invoice.customer.area_id,
                period: invoice.period.slice(0, 10),
                amount: invoice.amount,
                due_date: invoice.due_date ? invoice.due_date.slice(0, 10) : null,
                status: invoice.status,
            })),
            method: paymentMethod,
            idempotency_key: idempotencyKey,
            confirmed: true,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setSelectedIds([]);
                setPaymentMethod('');
            },
            onError: (errors) => {
                const message = Object.values(errors).filter(Boolean).join(' ')
                    || 'Pembayaran massal gagal. Muat ulang halaman lalu coba lagi.';
                toast.error(message);
            },
            onFinish: () => {
                setConfirmationProcessing(false);
                setPendingConfirmation(null);
            },
        });
    };

    const isOverdue = (invoice: Invoice) => {
        // Seeded/legacy rows may carry the raw status 'overdue'; treat it like unpaid + past due.
        return invoice.status === 'overdue'
            || (invoice.status === 'unpaid' && isPastDueWib(invoice.due_date));
    };

    const getStatusBadge = (invoice: Invoice) => {
        const tone = invoice.status === 'paid'
            ? 'up'
            : invoice.status === 'void'
                ? 'neutral'
                : (isOverdue(invoice) ? 'down' : 'degraded');

        return <StatusBadge label={invoiceStatusLabel(invoice.status)} tone={tone} showIcon={invoice.status !== 'void'} />;
    };

    const columns: Column<Invoice>[] = [
        ...(isAdmin ? [{
            header: (
                <Checkbox
                    aria-label="Pilih semua invoice belum lunas di halaman ini"
                    checked={allPayableSelected}
                    onChange={(event) => toggleAllPayable(event.target.checked)}
                    onClick={(event) => event.stopPropagation()}
                />
            ),
            className: "w-[42px]",
            cell: (invoice: Invoice) => (
                <Checkbox
                    aria-label={`Pilih invoice ${invoice.id}`}
                    checked={selectedIds.includes(invoice.id)}
                    disabled={invoice.status !== 'unpaid'}
                    onChange={(event) => toggleInvoice(invoice.id, event.target.checked)}
                    onClick={(event) => event.stopPropagation()}
                />
            ),
        }] : []),
        {
            header: "ID",
            className: "hidden w-[80px] 2xl:table-cell",
            cell: (invoice) => (
                <span className="font-mono text-xs text-muted-foreground font-medium">
                    #{invoice.id}
                </span>
            )
        },
        {
            header: "Kode",
            accessorKey: "code",
            className: "w-[180px]",
            cell: (invoice) => (
                <div className="flex flex-col">
                    <span className="font-mono text-xs font-semibold">
                        {invoice.code}
                    </span>
                    <span className="text-xs text-muted-foreground 2xl:hidden">
                        {formatMonth(invoice.period)}
                    </span>
                </div>
            )
        },
        {
            header: "Pelanggan",
            accessorKey: "customer", // Sorting might need specific handling backend side if we want to sort by customer name
            sortable: false,
            cell: (invoice) => (
                <div className="flex flex-col">
                    <Link
                        href={route('customers.show', invoice.customer.id)}
                        className="font-medium text-foreground hover:underline"
                    >
                        {invoice.customer.name}
                    </Link>
                    {invoice.customer.code && (
                        <span className="text-xs text-muted-foreground">
                            {invoice.customer.code}
                        </span>
                    )}
                </div>
            )
        },
        {
            header: "Periode",
            accessorKey: "period",
            sortable: true,
            className: "hidden 2xl:table-cell",
            cell: (invoice) => (
                <span className="font-medium">
                    {formatMonth(invoice.period)}
                </span>
            )
        },
        {
            header: "Paket Aktif",
            accessorKey: "package_price",
            sortable: false,
            cell: (invoice) => invoice.customer.package ? (
                <div className="flex max-w-[150px] flex-col gap-0.5">
                    <span className="truncate text-sm font-medium" title={invoice.customer.package.name}>{invoice.customer.package.name}</span>
                    <span className="text-xs text-muted-foreground">
                        {formatIdr(invoice.customer.package.price)} / bulan
                    </span>
                </div>
            ) : (
                <span className="text-xs text-status-degraded">Belum ada paket</span>
            )
        },
        {
            header: "Nominal",
            accessorKey: "amount",
            className: "text-right",
            cell: (invoice) => (
                <div className="flex flex-col items-end gap-0.5 tabular-nums">
                    <span className={`font-data ${invoice.amount_mismatch ? 'text-destructive font-semibold' : ''}`}>
                        {formatIdr(invoice.amount)}
                    </span>
                    {invoice.amount_mismatch && invoice.package_price !== null && (
                        <span className="text-[11px] text-muted-foreground">
                            Seharusnya {formatIdr(invoice.package_price)}
                        </span>
                    )}
                </div>
            )
        },
        {
            header: "Status",
            accessorKey: "status",
            sortable: true,
            cell: (invoice) => (
                <div className="flex flex-col items-start gap-1">
                    {getStatusBadge(invoice)}
                    {invoice.amount_mismatch ? (
                        <span className="inline-flex items-center gap-1 text-[11px] font-medium text-status-down">
                            <AlertTriangle className="h-3 w-3" />
                            Nominal perlu sync
                        </span>
                    ) : invoice.customer.package && invoice.status !== 'void' ? (
                        <span className="inline-flex items-center gap-1 text-[11px] text-status-up">
                            <CheckCircle2 className="h-3 w-3" />
                            Sesuai paket
                        </span>
                    ) : null}
                </div>
            )
        },
        {
            header: "Jatuh Tempo",
            accessorKey: "due_date",
            cell: (invoice) => (
                <div className="flex flex-col gap-1">
                    <div className={`flex items-center gap-2 text-sm ${isOverdue(invoice) ? 'text-status-down font-medium' : 'text-muted-foreground'}`}>
                        <Calendar className="h-3.5 w-3.5" />
                        <span className="whitespace-nowrap">{formatDate(invoice.due_date)}</span>
                    </div>
                    {isOverdue(invoice) && (
                        <div className="flex items-center gap-1.5 text-status-down font-medium mt-0.5">
                            <AlertCircle className="h-3 w-3" />
                            <span className="text-[10px] font-bold uppercase tracking-wide">Terlambat</span>
                        </div>
                    )}
                </div>
            )
        },
        {
            header: "Aksi",
            className: "sticky right-0 w-[176px] border-l border-border bg-card text-right group-hover:bg-muted [&:is(th)]:bg-muted",
            cell: (invoice) => (
                <div className="flex items-center justify-end gap-2" onClick={(e) => e.stopPropagation()}>
                    {isAdmin && invoice.status === 'unpaid' && (
                        <Button size="sm" className="h-8 px-2 text-xs" asChild>
                            <Link href={route('invoices.show', invoice.id)}>
                                <CreditCard className="mr-1.5 h-3 w-3" aria-hidden="true" />
                                Bayar
                            </Link>
                        </Button>
                    )}
                    <Button
                        variant="ghost"
                        size="icon"
                        className="h-8 w-8 text-muted-foreground hover:text-foreground hover:bg-muted"
                        title="Lihat Detail"
                        aria-label="Lihat detail invoice"
                        onClick={() => router.visit(route('invoices.show', invoice.id))}
                    >
                        <Eye className="h-4 w-4" />
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        className="h-8 w-8 text-muted-foreground hover:text-foreground hover:bg-muted"
                        title="Unduh PDF"
                        aria-label="Unduh PDF invoice"
                        onClick={() => window.open(route('invoices.download', invoice.id), '_blank')}
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="lucide lucide-download"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" /><polyline points="7 10 12 15 17 10" /><line x1="12" x2="12" y1="15" y2="3" /></svg>
                    </Button>
                </div>
            )
        }
    ];

    // I5: kartu HP — pelanggan, periode, nominal, status, jatuh tempo + pilih/bayar.
    const renderMobileCard = (invoice: Invoice) => {
        const stop = (event: { stopPropagation: () => void }) => event.stopPropagation();

        return (
            <div className="rounded-xl border bg-card p-4 shadow-sm transition-colors active:bg-muted/40">
                <div className="flex items-start justify-between gap-3">
                    <div className="flex min-w-0 items-start gap-3">
                        {isAdmin && invoice.status === 'unpaid' && (
                            <span onClick={stop} onKeyDown={stop}>
                                <Checkbox
                                    aria-label={`Pilih invoice ${invoice.code ?? invoice.id}`}
                                    checked={selectedIds.includes(invoice.id)}
                                    onChange={(event) => toggleInvoice(invoice.id, event.target.checked)}
                                />
                            </span>
                        )}
                        <div className="min-w-0">
                            <p className="truncate font-medium text-foreground">{invoice.customer.name}</p>
                            <p className="font-mono text-xs text-muted-foreground">{invoice.code ?? `#${invoice.id}`}</p>
                        </div>
                    </div>
                    {getStatusBadge(invoice)}
                </div>
                <div className="mt-3 grid grid-cols-2 gap-3 text-xs">
                    <div>
                        <p className="text-muted-foreground">Periode</p>
                        <p className="mt-0.5 font-medium">{formatMonth(invoice.period)}</p>
                    </div>
                    <div className="text-right">
                        <p className="text-muted-foreground">Nominal</p>
                        <p className={`mt-0.5 font-mono font-medium tabular-nums ${invoice.amount_mismatch ? 'text-destructive' : ''}`}>{formatIdr(invoice.amount)}</p>
                    </div>
                    <div className="col-span-2">
                        <p className="text-muted-foreground">Jatuh tempo</p>
                        <p className={`mt-0.5 ${isOverdue(invoice) ? 'font-medium text-status-down' : ''}`}>
                            {formatDate(invoice.due_date)}{isOverdue(invoice) ? ' · Terlambat' : ''}
                        </p>
                    </div>
                </div>
                {isAdmin && invoice.status === 'unpaid' && (
                    <div className="mt-3 flex justify-end" onClick={stop} onKeyDown={stop}>
                        <Button size="sm" className="h-10 gap-1.5" asChild>
                            <Link href={route('invoices.show', invoice.id)}>
                                <CreditCard className="h-4 w-4" aria-hidden="true" />
                                Bayar
                            </Link>
                        </Button>
                    </div>
                )}
            </div>
        );
    };

    const filterConfigs: FilterConfig[] = [
        {
            key: 'period',
            placeholder: 'Semua Periode',
            options: periods.map((period) => ({
                label: formatMonth(period),
                value: period,
            })),
        },
        {
            key: 'status',
            placeholder: 'Semua Status',
            options: [
                { label: 'Lunas', value: 'paid' },
                { label: 'Belum Lunas', value: 'unpaid' },
                { label: 'Batal', value: 'void' },
            ]
        },
        {
            key: 'area_id',
            placeholder: 'Semua Area',
            options: areas.map((area) => ({
                label: area.name,
                value: String(area.id),
            })),
        }
    ];

    // Customer filter (from the customers list "Invoice" link). DataTable only
    // syncs its own filterConfigs, so keep customer_id via routeParams and let
    // the user clear it explicitly with the chip.
    const customerId = filters.customer_id ? String(filters.customer_id) : null;
    const tableRouteParams = useMemo(
        () => (customerId ? { customer_id: customerId } : undefined),
        [customerId],
    );
    const clearCustomerFilter = () => {
        const { customer_id: _removed, ...rest } = filters;
        const params: Record<string, string> = {};
        Object.entries(rest).forEach(([key, value]) => {
            if (value) params[key] = String(value);
        });
        router.get(route('invoices.index'), params);
    };

    const exportUrl = () => {
        const params = new URLSearchParams();
        Object.entries(filters).forEach(([key, value]) => {
            if (value) {
                params.set(key, String(value));
            }
        });

        const query = params.toString();
        return query ? `${route('invoices.export')}?${query}` : route('invoices.export');
    };

    return (
        <EnterpriseIndexLayout
            title="Invoice"
            pageTitle="Tagihan"
            subtitle="Kelola tagihan dan status pembayaran pelanggan"
            icon={FileText}
            breadcrumbs={[{ label: 'Tagihan', href: route('invoices.index') }]}
            actions={
                <div className="flex flex-wrap items-center gap-2">
                    {isAdmin && selectedInvoices.length > 0 && (
                        <div className="flex w-full flex-wrap items-center gap-2 rounded-md border border-border bg-muted/30 px-2 py-1 sm:w-auto">
                            <span className="text-xs text-muted-foreground" title="Pilihan hanya berlaku untuk halaman ini">
                                {selectedInvoices.length} di halaman ini · {formatIdr(selectedTotal)}
                            </span>
                            <Select value={paymentMethod} onValueChange={setPaymentMethod}>
                                <SelectTrigger aria-label="Metode bayar" className="h-8 min-w-0 flex-1 bg-background text-xs sm:w-[140px] sm:flex-none">
                                    <SelectValue placeholder="Metode bayar" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="cash">Tunai</SelectItem>
                                    <SelectItem value="transfer">Transfer</SelectItem>
                                </SelectContent>
                            </Select>
                            <Button type="button" size="sm" className="h-8" disabled={!paymentMethod} onClick={handleBulkPayment}>
                                Catat pembayaran
                            </Button>
                        </div>
                    )}
                    <Button variant="outline" size="sm" className="h-9 gap-2" asChild>
                        <a href={exportUrl()}>
                            <Download className="h-4 w-4" aria-hidden="true" />
                            Export XLSX
                        </a>
                    </Button>
                    {isAdmin && (
                        <Button size="sm" className="h-9 gap-2" asChild>
                            <Link href={route('invoices.create')}>
                                <Plus className="h-4 w-4" aria-hidden="true" />
                                Buat Tagihan
                            </Link>
                        </Button>
                    )}
                </div>
            }
        >
            {customerId && (
                <div className="mb-2">
                    <Button type="button" variant="outline" size="sm" className="h-8 gap-2" onClick={clearCustomerFilter}>
                        Pelanggan: {filters.search || `#${customerId}`}
                        <span aria-hidden="true">✕</span>
                        <span className="sr-only">Hapus filter pelanggan</span>
                    </Button>
                </div>
            )}
            <DataTable
                compact
                routeParams={tableRouteParams}
                data={invoices}
                columns={columns}
                filters={filters}
                title=""
                summary={`${invoices.total} invoice`}
                searchPlaceholder="Cari pelanggan, kode..."
                filterConfigs={filterConfigs}
                pageSizeOptions={[20, 50, 100]}
                routeName="invoices.index"
                onRowClick={(item: Invoice) => router.visit(route('invoices.show', item.id))}
                mobileCard={(invoice: Invoice) => renderMobileCard(invoice)}
            />
            <ConfirmDestructive
                open={pendingConfirmation === 'bulk-payment'}
                onOpenChange={(open) => {
                    if (!open && !confirmationProcessing) setPendingConfirmation(null);
                }}
                title="Konfirmasi pembayaran massal"
                description={`Catat ${selectedInvoices.length} invoice sebagai lunas dengan total ${formatIdr(selectedTotal)}.`}
                confirmationLabel="Saya sudah memeriksa pembayaran dan bukti untuk invoice yang dipilih."
                confirmText="Catat pembayaran"
                onConfirm={executeBulkPayment}
                isLoading={confirmationProcessing}
            >
                <div className="max-h-72 space-y-2 overflow-y-auto rounded-md border border-border p-2">
                    {selectedInvoices.map((invoice) => (
                        <div key={invoice.id} className="rounded border border-border/70 bg-background p-2 text-xs">
                            <div className="flex items-center justify-between gap-2">
                                <span className="font-mono font-semibold">{invoice.code || `#${invoice.id}`}</span>
                                <span className="font-data tabular-nums">{formatIdr(invoice.amount)}</span>
                            </div>
                            <div className="text-muted-foreground">
                                {invoice.customer.name} {invoice.customer.code ? `(${invoice.customer.code})` : ''}
                                {' · '}{invoice.customer.area?.name || 'Area tidak tersedia'}
                            </div>
                            <div className="text-muted-foreground">
                                {formatMonth(invoice.period)} · jatuh tempo {formatDate(invoice.due_date)} · {invoiceStatusLabel(invoice.status)}
                            </div>
                        </div>
                    ))}
                </div>
                <p className="text-xs text-muted-foreground">
                    Semua identitas di atas akan divalidasi ulang server sebelum transaksi dibuat. Invoice yang sudah memiliki transaksi akan dilewati.
                </p>
            </ConfirmDestructive>
        </EnterpriseIndexLayout>
    );
}
