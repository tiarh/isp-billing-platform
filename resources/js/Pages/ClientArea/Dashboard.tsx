import { Head, router } from '@inertiajs/react';
import ClientAreaLayout from '@/Layouts/ClientAreaLayout';
import { Button } from '@/Components/ui/button';
import { Badge } from '@/Components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { invoiceStatusLabel } from '@/lib/invoiceLabels';
import { formatDate, formatIdr, formatMonth } from '@/lib/format';

interface ClientInvoice {
    uuid: string;
    code: string;
    period: string | null;
    amount: string;
    status: string;
    due_date: string | null;
    pay_url: string | null;
    receipt_url: string | null;
}

interface Props {
    customer: { code: string; name: string };
    invoices: ClientInvoice[];
    company: { name: string };
    sandbox?: boolean;
}

export default function Dashboard({ customer, invoices, company, sandbox = false }: Props) {
    return (
        <ClientAreaLayout companyName={company.name} sandbox={sandbox}>
            <Head title="Tagihan Saya" />
            <div className="mb-6 flex items-start justify-between gap-4">
                <div>
                    <h1 className="text-xl font-semibold">Halo, {customer.name}</h1>
                    <p className="text-sm text-muted-foreground">Kode pelanggan: {customer.code}</p>
                </div>
                <Button variant="outline" size="sm" onClick={() => router.post('/pelanggan/logout')}>
                    Keluar
                </Button>
            </div>

            <h2 className="mb-3 text-base font-semibold">Tagihan</h2>
            {invoices.length === 0 && <p className="text-sm text-muted-foreground">Belum ada tagihan.</p>}
            <div className="space-y-3">
                {invoices.map((invoice) => (
                    <Card key={invoice.uuid}>
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center justify-between text-base">
                                <span>{invoice.period ? formatMonth(invoice.period) : invoice.code}</span>
                                <Badge variant={invoice.status === 'paid' ? 'secondary' : 'outline'}>
                                    {invoiceStatusLabel(invoice.status)}
                                </Badge>
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-wrap items-center justify-between gap-3">
                            <div className="text-sm">
                                <div className="font-semibold">{formatIdr(invoice.amount)}</div>
                                <div className="text-muted-foreground">
                                    {invoice.code}
                                    {invoice.due_date ? ` - jatuh tempo ${formatDate(invoice.due_date)}` : ''}
                                </div>
                            </div>
                            <div className="flex gap-2">
                                {invoice.pay_url && (
                                    <Button asChild size="sm">
                                        <a href={invoice.pay_url}>Bayar</a>
                                    </Button>
                                )}
                                {invoice.receipt_url && (
                                    <Button asChild size="sm" variant="outline">
                                        <a href={invoice.receipt_url} target="_blank" rel="noopener noreferrer">Kuitansi</a>
                                    </Button>
                                )}
                            </div>
                        </CardContent>
                    </Card>
                ))}
            </div>
        </ClientAreaLayout>
    );
}
