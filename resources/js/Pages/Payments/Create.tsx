import { EnterpriseFormLayout } from '@/Components/enterprise/EnterpriseFormLayout';
import { useForm } from '@inertiajs/react';
import { CreditCard } from 'lucide-react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Separator } from '@/Components/ui/separator';
import { Alert, AlertDescription } from '@/Components/ui/alert';
import { FormEventHandler } from 'react';
import { optionalImage, requiredNumber, validateForm } from '@/lib/validation';
import { formatDate, formatIdr, formatMonth } from '@/lib/format';
import { z } from 'zod';

interface Customer {
    id: number;
    name: string;
    code: string | null;
}

interface Invoice {
    id: number;
    code: string;
    period: string;
    amount: number;
    due_date: string;
    customer: Customer;
}

interface Props {
    invoice: Invoice;
}

const paymentCreateSchema = z.object({
    amount: requiredNumber('Payment amount', 0),
    method: z.enum(['cash', 'transfer', 'qris', 'other'], { error: 'Payment method is required.' }),
    invoice_confirmation: z.string().min(1),
    customer_confirmation: z.string().min(1),
    proof: optionalImage('Payment proof'),
    idempotency_key: z.string().min(10),
});

export default function Create({ invoice }: Props) {
    const form = useForm({
        amount: String(invoice.amount),
        method: 'cash',
        invoice_confirmation: invoice.code,
        customer_confirmation: invoice.customer.code || invoice.customer.name,
        proof: null as File | null,
        idempotency_key: typeof crypto !== 'undefined' && crypto.randomUUID
            ? crypto.randomUUID()
            : `payment-${Date.now()}-${Math.random().toString(36).slice(2)}`,
    });
    const { data, setData, post, processing, errors } = form;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (!validateForm(paymentCreateSchema, data, form)) return;
        post(route('payments.store', invoice.id));
    };

    return (
        <EnterpriseFormLayout
            title="Record Payment"
            pageTitle="Record Payment"
            subtitle={`Invoice #${invoice.id} — ${invoice.customer.name}`}
            icon={CreditCard}
            backHref={route('invoices.show', invoice.id)}
            breadcrumbs={[
                { label: 'Invoices', href: route('invoices.index') },
                { label: `Invoice #${invoice.id}`, href: route('invoices.show', invoice.id) },
                { label: 'Record Payment' },
            ]}
            maxWidth="3xl"
        >
                    <Card>
                        <CardHeader>
                            <CardTitle>Record Payment for Invoice #{invoice.id}</CardTitle>
                            <CardDescription>
                                Enter payment details below
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {/* Invoice Summary */}
                            <Alert className="mb-6">
                                <AlertDescription>
                                    <div className="grid gap-2 text-sm">
                                        <div>
                                            <span className="font-semibold">Invoice:</span> {invoice.code}
                                        </div>
                                        <div>
                                            <span className="font-semibold">Customer:</span> {invoice.customer.name}
                                            {invoice.customer.code && ` (${invoice.customer.code})`}
                                        </div>
                                        <div>
                                            <span className="font-semibold">Period:</span>{' '}
                                            {formatMonth(invoice.period)}
                                        </div>
                                        <div>
                                            <span className="font-semibold">Invoice Amount:</span>{' '}
                                            <span className="text-lg font-bold font-data tabular-nums">{formatIdr(invoice.amount)}</span>
                                        </div>
                                        <div>
                                            <span className="font-semibold">Due Date:</span> {formatDate(invoice.due_date)}
                                        </div>
                                    </div>
                                </AlertDescription>
                            </Alert>

                            <Separator className="my-6" />

                            {/* Payment Form */}
                            <form onSubmit={submit} className="space-y-6">
                                <div className="space-y-2">
                                    <Label htmlFor="amount">Nominal Pembayaran *</Label>
                                    <Input
                                        id="amount"
                                        type="number"
                                        inputMode="numeric"
                                        step="1"
                                        min={0}
                                        autoFocus
                                        value={data.amount}
                                        onChange={(e) => setData('amount', e.target.value)}
                                        required
                                    />
                                    <p className="text-sm text-muted-foreground">
                                        Boleh diisi sebagian (pembayaran parsial).
                                    </p>
                                    {errors.amount && (
                                        <p className="text-sm text-destructive">{errors.amount}</p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="method">Payment Method *</Label>
                                    <Select
                                        value={data.method}
                                        onValueChange={(value) => setData('method', value)}
                                        required
                                    >
                                        <SelectTrigger id="method">
                                            <SelectValue placeholder="Select payment method" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="cash">Cash</SelectItem>
                                            <SelectItem value="transfer">Bank Transfer</SelectItem>
                                            <SelectItem value="qris">QRIS</SelectItem>
                                            <SelectItem value="other">Other</SelectItem>
                                        </SelectContent>
                                    </Select>
                                    {errors.method && (
                                        <p className="text-sm text-destructive">{errors.method}</p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="proof">Payment Proof (Optional)</Label>
                                    <Input
                                        id="proof"
                                        type="file"
                                        accept="image/jpeg,image/png,image/jpg"
                                        onChange={(e) => setData('proof', e.target.files?.[0] || null)}
                                    />
                                    <p className="text-sm text-muted-foreground">
                                        Upload receipt, screenshot, or transaction proof as an image. Max 2MB.
                                    </p>
                                    {errors.proof && (
                                        <p className="text-sm text-destructive">{errors.proof}</p>
                                    )}
                                </div>

                                <Separator />

                                <div className="flex justify-end gap-4">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => window.history.back()}
                                    >
                                        Cancel
                                    </Button>
                                    <Button type="submit" disabled={processing}>
                                        {processing ? 'Recording...' : 'Record Payment'}
                                    </Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>
        </EnterpriseFormLayout>
    );
}
