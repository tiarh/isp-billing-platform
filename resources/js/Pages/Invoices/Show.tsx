import { useState } from 'react';
import { EnterpriseResourceLayout } from '@/Components/enterprise/EnterpriseResourceLayout';
import { Link, useForm, usePage, router } from '@inertiajs/react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { Badge } from '@/Components/ui/badge';
import { Separator } from '@/Components/ui/separator';
import { Button } from '@/Components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from "@/Components/ui/dialog";
import { Input } from "@/Components/ui/input";
import { Label } from "@/Components/ui/label";
import { Textarea } from "@/Components/ui/textarea";
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from "@/Components/ui/select";
import {
    User, MapPin, Phone, Hash, Calendar, DollarSign,
    CreditCard, FileText, CheckCircle2, Clock, AlertCircle,
    Download, ExternalLink, Plus, Ban, Trash2, Pencil
} from 'lucide-react';
import { toast } from 'sonner';
import { optionalImage, requiredNumber, requiredString, validateForm } from '@/lib/validation';
import { StatusBadge } from '@/Components/StatusBadge';
import { formatDate, formatDateTime, formatIdr, formatMonth } from '@/lib/format';
import { invoiceStatusLabel } from '@/lib/invoiceLabels';
import { formatWibDateTimeLocalInput, isPastDueWib, wibLocalInputToOffsetString } from '@/lib/dateTime';
import { z } from 'zod';

interface Customer {
    id: number;
    name: string;
    code: string | null;
    phone: string | null;
    address: string;
}

interface Transaction {
    id: number;
    amount: number;
    method: string;
    reference?: string | null;
    status: string;
    paid_at: string;
    proof_url?: string | null;
    reversals?: Array<{
        id: number;
        amount: number;
        reversal_reference: string;
        reason: string;
        reversed_at: string;
    }>;
    admin: {
        name: string;
    } | null;
}

interface Invoice {
    id: number;
    code: string;
    period: string;
    amount: number;
    status: 'unpaid' | 'paid' | 'void' | 'overdue';
    due_date: string;
    generated_at: string;
    customer: Customer;
    transactions: Transaction[];
    paid_amount?: number;
    public_invoice_url?: string;
}

interface Props {
    invoice: Invoice;
}

import { PageProps } from '@/types';

const paymentSchema = z.object({
    amount: requiredNumber('Payment amount', 0.01),
    method: z.enum(['cash', 'transfer', 'qris', 'other'], { error: 'Payment method is required.' }),
    paid_at: requiredString('Payment date'),
    invoice_confirmation: requiredString('Invoice confirmation'),
    customer_confirmation: requiredString('Customer confirmation'),
    proof: optionalImage('Payment proof'),
});

export default function Show({ invoice }: Props) {
    const { settings, auth } = usePage<PageProps>().props;
    const isAdmin = auth.user.role === 'admin' || auth.user.role === 'superadmin';
    const [isPaymentOpen, setIsPaymentOpen] = useState(false);
    const [isVoidOpen, setIsVoidOpen] = useState(false);
    const [isDeleteOpen, setIsDeleteOpen] = useState(false);
    const [voidReason, setVoidReason] = useState('');
    const [voidProcessing, setVoidProcessing] = useState(false);
    const [deleteProcessing, setDeleteProcessing] = useState(false);
    const [reversalTransaction, setReversalTransaction] = useState<Transaction | null>(null);
    const [reversalInvoiceConfirmation, setReversalInvoiceConfirmation] = useState('');
    const [reversalCustomerConfirmation, setReversalCustomerConfirmation] = useState('');
    const [reversalReason, setReversalReason] = useState('');
    const [reversalProcessing, setReversalProcessing] = useState(false);
    const [midtransLoading, setMidtransLoading] = useState(false);

    const createMidtransLink = async () => {
        if (invoice.status === 'paid') {
            toast.error('Invoice sudah lunas');
            return;
        }
        setMidtransLoading(true);
        try {
            const res = await fetch(`/api/payment-gateway/invoices/${invoice.id}/midtrans`, {
                method: 'POST',
                credentials: 'include',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': (window as any).csrfToken || '',
                },
            });
            const d = await res.json();
            if (!res.ok || !d.success) {
                toast.error(d.message || 'Gagal buat link Midtrans');
                return;
            }
            toast.success('Link Midtrans siap');
            if (d.redirect_url) {
                window.open(d.redirect_url, '_blank');
            }
        } catch (e: any) {
            toast.error(e?.message || 'Network error');
        } finally {
            setMidtransLoading(false);
        }
    };

    const calculatedPaid = invoice.transactions.reduce((sum, t) => {
        const reversed = t.reversals?.reduce((total, reversal) => total + (Number(reversal.amount) || 0), 0) || 0;
        return sum + Math.max(0, (Number(t.amount) || 0) - reversed);
    }, 0);
    const totalPaid = Number(invoice.paid_amount ?? calculatedPaid);
    const balance = Number(invoice.amount) - totalPaid;
    const hasTransactions = invoice.transactions.length > 0;
    const canReversePayment = auth.user.scope === 'global_admin' || auth.user.scope === 'superadmin';
    const customerConfirmationTarget = invoice.customer.code || invoice.customer.name;
    // Seeded/legacy rows may carry the raw status 'overdue'; treat it like unpaid + past due.
    const isOverdue = invoice.status === 'overdue'
        || (invoice.status === 'unpaid' && isPastDueWib(invoice.due_date));
    const statusTone = invoice.status === 'paid'
        ? 'up'
        : invoice.status === 'void'
            ? 'neutral'
            : isOverdue
                ? 'down'
                : 'degraded';

    const paymentForm = useForm({
        amount: String(balance),
        method: 'cash',
        paid_at: formatWibDateTimeLocalInput(new Date()), // datetime-local, WIB wall-clock
        invoice_confirmation: invoice.code,
        customer_confirmation: invoice.customer.code || invoice.customer.name,
        proof: null as File | null,
        idempotency_key: typeof crypto !== 'undefined' && crypto.randomUUID
            ? crypto.randomUUID()
            : 'invoice-payment-' + Date.now() + '-' + Math.random().toString(36).slice(2),
    });
    const { data, setData, post, processing, errors, reset, transform } = paymentForm;
    // Send paid_at with an explicit +07:00 offset (the field is WIB wall-clock time).
    transform((formData) => ({ ...formData, paid_at: wibLocalInputToOffsetString(formData.paid_at) }));

    const handleVoid = () => {
        setVoidProcessing(true);
        router.post(route('invoices.void', invoice.id), { reason: voidReason }, {
            onSuccess: () => {
                setIsVoidOpen(false);
                setVoidReason('');
                // Flash from the controller is toasted by AuthenticatedLayout.
            },
            onFinish: () => setVoidProcessing(false),
        });
    };

    const handleDelete = () => {
        setDeleteProcessing(true);
        router.delete(route('invoices.destroy', invoice.id), {
            onFinish: () => setDeleteProcessing(false),
        });
    };

    const handlePaymentSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        if (!validateForm(paymentSchema, data, paymentForm)) return;
        post(route('payments.store', invoice.id), {
            onSuccess: () => {
                setIsPaymentOpen(false);
                reset();
            },
        });
    };

    const openReversal = (transaction: Transaction) => {
        setReversalTransaction(transaction);
        setReversalInvoiceConfirmation('');
        setReversalCustomerConfirmation('');
        setReversalReason('');
    };

    const closeReversal = () => {
        setReversalTransaction(null);
        setReversalInvoiceConfirmation('');
        setReversalCustomerConfirmation('');
        setReversalReason('');
    };

    const handleReversal = () => {
        if (!reversalTransaction) return;
        if (reversalInvoiceConfirmation !== invoice.code) {
            toast.error('Ketik kode invoice yang tepat.');
            return;
        }
        if (reversalCustomerConfirmation !== customerConfirmationTarget) {
            toast.error('Konfirmasi pelanggan tidak cocok.');
            return;
        }
        if (reversalReason.trim().length < 10) {
            toast.error('Alasan koreksi minimal 10 karakter.');
            return;
        }

        setReversalProcessing(true);
        router.post(route('payments.reverse', {
            invoice: invoice.id,
            transaction: reversalTransaction.id,
        }), {
            invoice_confirmation: reversalInvoiceConfirmation,
            customer_confirmation: reversalCustomerConfirmation,
            reason: reversalReason.trim(),
            idempotency_key: typeof crypto !== 'undefined' && crypto.randomUUID
                ? `invoice-reversal-${crypto.randomUUID()}`
                : `invoice-reversal-${Date.now()}-${Math.random().toString(36).slice(2)}`,
        }, {
            onSuccess: () => {
                closeReversal();
                toast.success('Payment dibatalkan dan histori tetap tersimpan.');
            },
            onFinish: () => setReversalProcessing(false),
        });
    };

    return (
        <EnterpriseResourceLayout
            title={`Invoice #${invoice.code || invoice.id}`}
            pageTitle={`Invoice: #${invoice.id}`}
            subtitle={`${invoice.customer.name} • ${formatDate(invoice.generated_at)}`}
            icon={FileText}
            backHref={route('invoices.index')}
            breadcrumbs={[
                { label: 'Invoices', href: route('invoices.index') },
                { label: `Invoice #${invoice.id}` },
            ]}
            actions={
                <div className="flex flex-wrap items-center gap-2">
                    {isAdmin && invoice.status === 'unpaid' && !hasTransactions && (
                        <Button variant="outline" size="sm" className="gap-2" asChild>
                            <Link href={route('invoices.edit', invoice.id)}>
                                <Pencil className="w-4 h-4" />
                                Edit
                            </Link>
                        </Button>
                    )}
                    {isAdmin && invoice.status === 'unpaid' && (
                        <Button
                            variant="default"
                            size="sm"
                            className="gap-2"
                            disabled={midtransLoading}
                            onClick={createMidtransLink}
                        >
                            <CreditCard className="w-4 h-4" />
                            {midtransLoading ? 'Midtrans…' : 'Bayar Midtrans'}
                        </Button>
                    )}
                    <Button variant="outline" size="sm" className="gap-2" asChild>
                        <a href={route('invoices.download', invoice.id)} target="_blank">
                            <Download className="w-4 h-4" />
                            Download PDF
                        </a>
                    </Button>
                    {invoice.public_invoice_url && (
                        <Button variant="outline" size="sm" className="gap-2" asChild>
                            <a href={invoice.public_invoice_url} target="_blank" rel="noreferrer">
                                <ExternalLink className="w-4 h-4" />
                                Link Pelanggan
                            </a>
                        </Button>
                    )}
                </div>
            }
        >
            <div className="mx-auto max-w-5xl space-y-6">
                    {/* Status Banner */}
                    <div className="flex flex-col sm:flex-row gap-4 items-start sm:items-center justify-between p-5 rounded-lg border border-border bg-card shadow-[var(--shadow-card)]">
                        <div className="flex items-center gap-4">
                            <div className={`p-3 rounded-full ${invoice.status === 'paid'
                                ? 'bg-status-up-bg text-status-up'
                                : invoice.status === 'void'
                                    ? 'bg-muted text-muted-foreground'
                                    : isOverdue
                                        ? 'bg-status-down-bg text-status-down'
                                        : 'bg-status-degraded-bg text-status-degraded'}`}>
                                {invoice.status === 'paid'
                                    ? <CheckCircle2 className="w-6 h-6" />
                                    : invoice.status === 'void'
                                        ? <Ban className="w-6 h-6" />
                                        : <AlertCircle className="w-6 h-6" />}
                            </div>
                            <div>
                                <h3 className="font-semibold text-lg">
                                    {invoice.status === 'paid'
                                        ? 'Tagihan lunas'
                                        : invoice.status === 'void'
                                            ? 'Tagihan dibatalkan'
                                            : 'Menunggu pembayaran'}
                                </h3>
                                <p className="text-sm text-muted-foreground">
                                    {invoice.status === 'paid'
                                        ? 'Tagihan ini sudah dibayar penuh.'
                                        : invoice.status === 'void'
                                            ? 'Tagihan ini sudah dibatalkan (void) dan tidak dapat dibayar. Data disimpan untuk audit.'
                                            : `Jatuh tempo ${formatDate(invoice.due_date)}`
                                    }
                                </p>
                            </div>
                        </div>

                        <div className="flex flex-wrap items-center gap-3">
                            <StatusBadge label={invoiceStatusLabel(invoice.status)} tone={statusTone} showIcon={invoice.status !== 'void'} />
                            {/* Payment Dialog */}
                            {isAdmin && invoice.status === 'unpaid' && (
                                <Dialog open={isPaymentOpen} onOpenChange={setIsPaymentOpen}>
                                    <DialogTrigger asChild>
                                        <Button className="gap-2">
                                            <CreditCard className="w-4 h-4" />
                                            Record Payment
                                        </Button>
                                    </DialogTrigger>
                                    <DialogContent className="sm:max-w-[425px]">
                                        <DialogHeader>
                                            <DialogTitle>Record Payment</DialogTitle>
                                            <DialogDescription>
                                                Enter the payment details for Invoice #{invoice.id}.
                                            </DialogDescription>
                                        </DialogHeader>
                                        <form onSubmit={handlePaymentSubmit} className="space-y-4 pt-4">
                                            <div className="space-y-2">
                                                <Label htmlFor="amount">Amount (IDR)</Label>
                                                <Input
                                                    id="amount"
                                                    type="number"
                                                    inputMode="numeric"
                                                    value={data.amount}
                                                    onChange={(e) => setData('amount', e.target.value)}
                                                    min={0}
                                                    max={balance}
                                                    step="1"
                                                    required
                                                />
                                                {errors.amount && <p className="text-sm text-destructive">{errors.amount}</p>}
                                                <p className="text-xs text-muted-foreground">
                                                    Max payable: <span className="font-data tabular-nums">{formatIdr(balance)}</span>
                                                </p>
                                            </div>

                                            <div className="space-y-2">
                                                <Label htmlFor="date">Payment Date (WIB)</Label>
                                                <Input
                                                    id="date"
                                                    type="datetime-local"
                                                    value={data.paid_at}
                                                    onChange={(e) => setData('paid_at', e.target.value)}
                                                    required
                                                />
                                                {errors.paid_at && <p className="text-sm text-destructive">{errors.paid_at}</p>}
                                            </div>

                                            <div className="space-y-2">
                                                <Label htmlFor="method">Payment Method</Label>
                                                <Select
                                                    value={data.method}
                                                    onValueChange={(val) => setData('method', val)}
                                                >
                                                    <SelectTrigger>
                                                        <SelectValue placeholder="Select method" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        <SelectItem value="cash">Cash</SelectItem>
                                                        <SelectItem value="transfer">Bank Transfer</SelectItem>
                                                        <SelectItem value="qris">QRIS</SelectItem>
                                                        <SelectItem value="other">Other</SelectItem>
                                                    </SelectContent>
                                                </Select>
                                                {errors.method && <p className="text-sm text-destructive">{errors.method}</p>}
                                            </div>

                                            {data.method === 'transfer' && (
                                                <div className="rounded-md bg-status-info-bg p-3 text-sm text-status-info border border-status-info/30">
                                                    <p className="font-semibold mb-1">Bank Account Details:</p>
                                                    {settings.payment_channels && settings.payment_channels.length > 0 ? (
                                                        settings.payment_channels.map((channel, i) => (
                                                            <p key={i}>
                                                                {channel.bank}: {channel.account_number} ({channel.account_name})
                                                            </p>
                                                        ))
                                                    ) : (
                                                        <p className="italic text-xs">No bank accounts configured.</p>
                                                    )}
                                                    <p className="mt-1 text-xs opacity-80">Please upload the transfer receipt below.</p>
                                                </div>
                                            )}

                                            <div className="space-y-2">
                                                <Label htmlFor="proof">Proof of Payment (Image)</Label>
                                                <Input
                                                    id="proof"
                                                    type="file"
                                                    accept="image/jpeg,image/png,image/jpg"
                                                    onChange={(e) => setData('proof', e.target.files ? e.target.files[0] : null)}
                                                />
                                                {errors.proof && <p className="text-sm text-destructive">{errors.proof}</p>}
                                            </div>

                                            <DialogFooter className="pt-4">
                                                <Button type="button" variant="outline" onClick={() => setIsPaymentOpen(false)}>
                                                    Cancel
                                                </Button>
                                                <Button type="submit" disabled={processing}>
                                                    {processing ? 'Recording...' : 'Save Payment'}
                                                </Button>
                                            </DialogFooter>
                                        </form>
                                    </DialogContent>
                                </Dialog>
                            )}

                            {canReversePayment && (
                                <Dialog open={reversalTransaction !== null} onOpenChange={(open) => !open && closeReversal()}>
                                    <DialogContent className="sm:max-w-[500px]">
                                        <DialogHeader>
                                            <DialogTitle>Koreksi payment</DialogTitle>
                                            <DialogDescription>
                                                Transaksi asli tidak dihapus. Sistem membuat reversal audit dan menghitung ulang saldo invoice.
                                            </DialogDescription>
                                        </DialogHeader>
                                        <div className="space-y-4 pt-4">
                                            <div className="rounded-md border bg-muted/30 p-3 text-sm">
                                                <p><span className="text-muted-foreground">Invoice:</span> <strong>{invoice.code}</strong></p>
                                                <p><span className="text-muted-foreground">Pelanggan:</span> <strong>{invoice.customer.name}</strong></p>
                                                <p><span className="text-muted-foreground">Nominal:</span> <strong className="font-data tabular-nums">{reversalTransaction ? formatIdr(reversalTransaction.amount) : '-'}</strong></p>
                                            </div>
                                            <div className="space-y-2">
                                                <Label htmlFor="reversal-invoice-confirmation">Ketik kode invoice</Label>
                                                <Input
                                                    id="reversal-invoice-confirmation"
                                                    value={reversalInvoiceConfirmation}
                                                    onChange={(e) => setReversalInvoiceConfirmation(e.target.value)}
                                                    placeholder={invoice.code}
                                                    autoComplete="off"
                                                />
                                            </div>
                                            <div className="space-y-2">
                                                <Label htmlFor="reversal-customer-confirmation">Ketik kode/nama pelanggan</Label>
                                                <Input
                                                    id="reversal-customer-confirmation"
                                                    value={reversalCustomerConfirmation}
                                                    onChange={(e) => setReversalCustomerConfirmation(e.target.value)}
                                                    placeholder={customerConfirmationTarget}
                                                    autoComplete="off"
                                                />
                                            </div>
                                            <div className="space-y-2">
                                                <Label htmlFor="reversal-reason">Alasan koreksi</Label>
                                                <Textarea
                                                    id="reversal-reason"
                                                    value={reversalReason}
                                                    onChange={(e) => setReversalReason(e.target.value)}
                                                    placeholder="Contoh: salah memilih invoice saat input pembayaran"
                                                    rows={3}
                                                />
                                            </div>
                                        </div>
                                        <DialogFooter className="pt-4">
                                            <Button type="button" variant="outline" onClick={closeReversal}>
                                                Batal
                                            </Button>
                                            <Button type="button" variant="destructive" onClick={handleReversal} disabled={reversalProcessing}>
                                                {reversalProcessing ? 'Memproses...' : 'Batalkan payment'}
                                            </Button>
                                        </DialogFooter>
                                    </DialogContent>
                                </Dialog>
                            )}

                            {/* Void Invoice Dialog */}
                            {isAdmin && invoice.status === 'unpaid' && (
                                <Dialog open={isVoidOpen} onOpenChange={setIsVoidOpen}>
                                    <DialogTrigger asChild>
                                        <Button variant="outline" className="gap-2 text-status-degraded border-status-degraded/40 hover:bg-status-degraded-bg hover:text-status-degraded">
                                            <Ban className="w-4 h-4" />
                                            Void
                                        </Button>
                                    </DialogTrigger>
                                    <DialogContent className="sm:max-w-[425px]">
                                        <DialogHeader>
                                            <DialogTitle>Void Invoice</DialogTitle>
                                            <DialogDescription>
                                                This will mark the invoice as void. The record will be kept for auditing purposes.
                                            </DialogDescription>
                                        </DialogHeader>
                                        <div className="space-y-4 pt-4">
                                            <div className="space-y-2">
                                                <Label htmlFor="reason">Reason (optional)</Label>
                                                <Textarea
                                                    id="reason"
                                                    placeholder="e.g. Customer terminated, duplicate invoice..."
                                                    value={voidReason}
                                                    onChange={(e) => setVoidReason(e.target.value)}
                                                    rows={3}
                                                />
                                            </div>
                                        </div>
                                        <DialogFooter className="pt-4">
                                            <Button type="button" variant="outline" onClick={() => setIsVoidOpen(false)}>
                                                Cancel
                                            </Button>
                                            <Button
                                                onClick={handleVoid}
                                                disabled={voidProcessing}
                                                className="bg-status-degraded text-status-degraded-bg hover:bg-status-degraded/90"
                                            >
                                                {voidProcessing ? 'Voiding...' : 'Void Invoice'}
                                            </Button>
                                        </DialogFooter>
                                    </DialogContent>
                                </Dialog>
                            )}

                            {/* Delete Invoice Dialog */}
                            {isAdmin && !hasTransactions && (invoice.status === 'unpaid' || invoice.status === 'void') && (
                                <Dialog open={isDeleteOpen} onOpenChange={setIsDeleteOpen}>
                                    <DialogTrigger asChild>
                                        <Button variant="outline" className="gap-2 text-destructive border-destructive/40 hover:bg-destructive/10 hover:text-destructive">
                                            <Trash2 className="w-4 h-4" />
                                            Delete
                                        </Button>
                                    </DialogTrigger>
                                    <DialogContent className="sm:max-w-[425px]">
                                        <DialogHeader>
                                            <DialogTitle className="text-destructive">Delete Invoice</DialogTitle>
                                            <DialogDescription>
                                                This will permanently delete Invoice #{invoice.code || invoice.id}. This action cannot be undone.
                                            </DialogDescription>
                                        </DialogHeader>
                                        <DialogFooter className="pt-4">
                                            <Button type="button" variant="outline" onClick={() => setIsDeleteOpen(false)}>
                                                Cancel
                                            </Button>
                                            <Button
                                                onClick={handleDelete}
                                                disabled={deleteProcessing}
                                                variant="destructive"
                                            >
                                                {deleteProcessing ? 'Deleting...' : 'Delete Permanently'}
                                            </Button>
                                        </DialogFooter>
                                    </DialogContent>
                                </Dialog>
                            )}
                        </div>
                    </div>

                    <div className="grid gap-6 md:grid-cols-2">
                        {/* Customer Info Card */}
                        <Card className="shadow-sm border-border">
                            <CardHeader className="pb-4">
                                <CardTitle className="flex items-center gap-2">
                                    <User className="w-4 h-4 text-muted-foreground" />
                                    Customer Details
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="grid gap-4">
                                <div className="space-y-1">
                                    <Link href={`/customers/${invoice.customer.id}`} className="font-semibold text-lg hover:underline text-foreground">
                                        {invoice.customer.name}
                                    </Link>
                                    {invoice.customer.code && (
                                        <div className="flex items-center gap-2 text-sm text-muted-foreground">
                                            <Hash className="w-3.5 h-3.5" />
                                            <span>{invoice.customer.code}</span>
                                        </div>
                                    )}
                                </div>
                                <Separator />
                                <div className="space-y-3 text-sm">
                                    <div className="flex items-start gap-3">
                                        <MapPin className="w-4 h-4 text-muted-foreground mt-0.5" />
                                        <span className="text-muted-foreground">{invoice.customer.address}</span>
                                    </div>
                                    {invoice.customer.phone && (
                                        <div className="flex items-center gap-3">
                                            <Phone className="w-4 h-4 text-muted-foreground" />
                                            <span className="text-muted-foreground">{invoice.customer.phone}</span>
                                        </div>
                                    )}
                                </div>
                            </CardContent>
                        </Card>

                        {/* Invoice Summary Card */}
                        <Card className="shadow-sm border-border">
                            <CardHeader className="pb-4">
                                <CardTitle className="flex items-center gap-2">
                                    <FileText className="w-4 h-4 text-muted-foreground" />
                                    Invoice Summary
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="grid grid-cols-2 gap-4 text-sm">
                                    <div className="space-y-1">
                                        <p className="text-muted-foreground">Billing Period</p>
                                        <p className="font-medium flex items-center gap-2">
                                            <Calendar className="w-3.5 h-3.5 text-muted-foreground" />
                                            {formatMonth(invoice.period)}
                                        </p>
                                    </div>
                                    <div className="space-y-1">
                                        <p className="text-muted-foreground">Generated On</p>
                                        <p className="font-medium flex items-center gap-2">
                                            <Clock className="w-3.5 h-3.5 text-muted-foreground" />
                                            {formatDate(invoice.generated_at)}
                                        </p>
                                    </div>
                                </div>

                                <Separator />

                                <div className="space-y-2">
                                    <div className="flex justify-between items-center text-sm">
                                        <span className="text-muted-foreground">Subtotal</span>
                                        <span className="font-medium font-data tabular-nums">{formatIdr(invoice.amount)}</span>
                                    </div>
                                    <div className="flex justify-between items-center text-sm">
                                        <span className="text-muted-foreground">Total Paid</span>
                                        <span className="font-medium text-status-up font-data tabular-nums">-{formatIdr(totalPaid)}</span>
                                    </div>
                                    <Separator />
                                    <div className="flex justify-between items-center pt-2">
                                        <span className="font-semibold">Balance Due</span>
                                        <span className="font-bold text-xl text-foreground font-data tabular-nums">{formatIdr(balance)}</span>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    </div>

                    {/* Payment History */}
                    <Card className="shadow-sm border-border">
                        <CardHeader className="flex flex-row items-center justify-between">
                            <div>
                                <CardTitle className="text-lg">Payment History</CardTitle>
                                <CardDescription>
                                    Transactions recorded for this invoice
                                </CardDescription>
                            </div>
                            <Badge variant="outline" className="font-mono">
                                {invoice.transactions.length} Transactions
                            </Badge>
                        </CardHeader>
                        <CardContent>
                            <div className="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow className="bg-muted/50 hover:bg-muted/50">
                                        <TableHead>Date</TableHead>
                                        <TableHead>Method</TableHead>
                                        <TableHead className="hidden sm:table-cell">Recorded By</TableHead>
                                        {isAdmin && <TableHead>Proof</TableHead>}
                                        <TableHead className="text-right">Amount</TableHead>
                                        {canReversePayment && <TableHead className="hidden sm:table-cell">Action</TableHead>}
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {invoice.transactions.length === 0 ? (
                                        <TableRow>
                                            <TableCell colSpan={(isAdmin ? 5 : 4) + (canReversePayment ? 1 : 0)} className="h-24 text-center text-muted-foreground">
                                                No payments found
                                            </TableCell>
                                        </TableRow>
                                    ) : (
                                        invoice.transactions.map((t) => {
                                            // Same action in two places: first cell on phones (no horizontal scroll), Action column from sm up.
                                            const reversalAction = t.reversals && t.reversals.length > 0 ? (
                                                <Badge variant="outline">Dibatalkan</Badge>
                                            ) : ['verified', 'paid'].includes(t.status) ? (
                                                <Button type="button" variant="outline" size="sm" onClick={() => openReversal(t)}>
                                                    Koreksi
                                                </Button>
                                            ) : (
                                                <span className="text-muted-foreground text-sm">-</span>
                                            );
                                            return (
                                            <TableRow key={t.id}>
                                                <TableCell className="font-medium">
                                                    {formatDateTime(t.paid_at)}
                                                    {canReversePayment && <div className="mt-2 sm:hidden">{reversalAction}</div>}
                                                </TableCell>
                                                <TableCell>
                                                    <Badge variant="secondary" className="capitalize">
                                                        {t.reference?.startsWith('CREDIT-') ? 'Saldo' : t.method.replace('_', ' ')}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell className="hidden sm:table-cell text-muted-foreground text-sm">
                                                    {t.admin?.name || 'System'}
                                                </TableCell>
                                                {isAdmin && (
                                                    <TableCell>
                                                        {t.proof_url ? (
                                                            <a
                                                                href={t.proof_url}
                                                                target="_blank"
                                                                rel="noopener noreferrer"
                                                                className="inline-flex items-center gap-1 text-primary hover:underline text-sm"
                                                            >
                                                                <ExternalLink className="w-3 h-3" />
                                                                View
                                                            </a>
                                                        ) : (
                                                            <span className="text-muted-foreground text-sm">-</span>
                                                        )}
                                                    </TableCell>
                                                )}
                                                <TableCell className="text-right font-medium">
                                                    <span className="font-data tabular-nums">{formatIdr(t.amount)}</span>
                                                </TableCell>
                                                {canReversePayment && (
                                                    <TableCell className="hidden sm:table-cell">{reversalAction}</TableCell>
                                                )}
                                            </TableRow>
                                            );
                                        })
                                    )}
                                </TableBody>
                            </Table>
                            </div>
                        </CardContent>
                    </Card>
            </div>
        </EnterpriseResourceLayout>
    );
}
