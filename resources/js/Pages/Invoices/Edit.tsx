import { EnterpriseFormLayout } from '@/Components/enterprise/EnterpriseFormLayout';
import { useForm } from '@inertiajs/react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { FileText, Save } from 'lucide-react';
import { FormEventHandler } from 'react';
import { requiredNumber, requiredString, validateForm } from '@/lib/validation';
import { z } from 'zod';

interface Invoice {
    id: number;
    code: string;
    period: string;
    amount: number;
    due_date: string;
    status: string;
    customer: {
        name: string;
        code: string | null;
        package?: { name: string; price: number } | null;
    };
}

interface Props {
    invoice: Invoice;
}

const invoiceEditSchema = z.object({
    period: requiredString('Billing period').regex(/^\d{4}-\d{2}$/, 'Billing period is invalid.'),
    amount: requiredNumber('Amount', 0.01),
    due_date: requiredString('Due date').regex(/^\d{4}-\d{2}-\d{2}$/, 'Due date is invalid.'),
});

export default function Edit({ invoice }: Props) {
    const form = useForm({
        period: invoice.period.slice(0, 7),
        amount: String(invoice.amount),
        due_date: invoice.due_date.slice(0, 10),
    });
    const { data, setData, put, processing, errors } = form;

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        if (!validateForm(invoiceEditSchema, data, form)) return;
        put(route('invoices.update', invoice.id));
    };

    return (
        <EnterpriseFormLayout
            title={'Edit Invoice #' + (invoice.code || invoice.id)}
            pageTitle="Edit Invoice"
            subtitle="Perbarui periode, nominal, atau jatuh tempo sebelum ada pembayaran"
            icon={FileText}
            backHref={route('invoices.show', invoice.id)}
            breadcrumbs={[
                { label: 'Invoices', href: route('invoices.index') },
                { label: 'Invoice #' + invoice.id, href: route('invoices.show', invoice.id) },
                { label: 'Edit' },
            ]}
            maxWidth="3xl"
        >
            <form onSubmit={submit} className="space-y-6">
                {Object.keys(errors).length > 0 && (
                    <div className="rounded-md border border-destructive/20 bg-destructive/10 p-4 text-sm text-destructive">
                        {Object.values(errors).map((message, index) => <p key={index}>{message}</p>)}
                    </div>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle>Invoice Details</CardTitle>
                        <CardDescription>
                            {invoice.customer.name} {invoice.customer.code ? '(' + invoice.customer.code + ')' : ''}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-5">
                        <div className="grid gap-2">
                            <Label htmlFor="period">Billing Period</Label>
                            <Input
                                id="period"
                                type="month"
                                value={data.period}
                                onChange={(event) => setData('period', event.target.value)}
                            />
                            {errors.period && <p className="text-sm text-destructive">{errors.period}</p>}
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="amount">Amount (IDR)</Label>
                            <Input
                                id="amount"
                                type="number"
                                min="0.01"
                                step="0.01"
                                value={data.amount}
                                onChange={(event) => setData('amount', event.target.value)}
                            />
                            <p className="text-xs text-muted-foreground">
                                Nominal harus sama dengan harga paket aktif pelanggan.
                            </p>
                            {errors.amount && <p className="text-sm text-destructive">{errors.amount}</p>}
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="due_date">Due Date</Label>
                            <Input
                                id="due_date"
                                type="date"
                                value={data.due_date}
                                onChange={(event) => setData('due_date', event.target.value)}
                            />
                            {errors.due_date && <p className="text-sm text-destructive">{errors.due_date}</p>}
                        </div>
                        <div className="flex justify-end gap-3">
                            <Button type="button" variant="outline" onClick={() => window.history.back()}>
                                Cancel
                            </Button>
                            <Button type="submit" disabled={processing} className="gap-2">
                                <Save className="h-4 w-4" />
                                {processing ? 'Saving...' : 'Save Changes'}
                            </Button>
                        </div>
                    </CardContent>
                </Card>
            </form>
        </EnterpriseFormLayout>
    );
}
