import { FormEvent, ReactNode, useEffect, useMemo, useState } from 'react';
import { EnterpriseIndexLayout } from '@/Components/enterprise/EnterpriseIndexLayout';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Textarea } from '@/Components/ui/textarea';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { currentMonthWib, formatWibDateTime } from '@/lib/dateTime';
import { Check, Globe, Headphones, LoaderCircle, LucideIcon, Plus, RefreshCw, Router, Send, Ticket, Users, Wifi } from 'lucide-react';

type ModuleKey = 'hotspot' | 'payment_gateway' | 'ticketing' | 'portal' | 'reseller' | 'genieacs' | 'support';

interface Option {
    id: number;
    name: string;
    code?: string;
    price?: number;
    mikrotik_profile?: string;
    rate_limit?: string;
}

interface Props {
    module: ModuleKey;
    title: string;
    description: string;
    routers?: Option[];
    packages?: Option[];
    customers?: Option[];
    users?: Option[];
}

// Must match the provider allowlist in PaymentGatewayController::webhook (#462).
const PAYMENT_WEBHOOK_PROVIDERS = ['midtrans', 'duitku'] as const;

interface PageData {
    data?: Record<string, any>[];
    total?: number;
}

const moduleIcons: Record<ModuleKey, LucideIcon> = {
    hotspot: Wifi,
    payment_gateway: Send,
    ticketing: Ticket,
    portal: Globe,
    reseller: Users,
    genieacs: Router,
    support: Headphones,
};

const endpoints: Partial<Record<ModuleKey, string>> = {
    hotspot: '/api/hotspot-vouchers',
    payment_gateway: '/api/payment-gateway/events',
    ticketing: '/api/support-tickets',
    reseller: '/api/reseller-commissions',
    genieacs: '/api/genieacs/devices',
};

function csrfToken(): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '';
}

async function jsonRequest(url: string, options: RequestInit = {}) {
    const response = await fetch(url, {
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            ...(options.headers || {}),
        },
        ...options,
    });

    const payload = await response.json().catch(() => ({}));
    if (!response.ok) {
        throw new Error(payload.message || payload.error || 'Request failed');
    }

    return payload;
}

function statusBadge(status?: string) {
    const value = status || '-';
    const good = ['paid', 'success', 'used', 'active', 'resolved', 'closed', 'approved'].includes(value);
    const warn = ['pending', 'open', 'assigned', 'unused'].includes(value);

    return (
        <Badge variant="outline" className={good ? 'border-status-up/30 text-status-up' : warn ? 'border-status-degraded/30 text-status-degraded' : ''}>
            {value}
        </Badge>
    );
}

export default function RadiusSuite({ module, title, description, routers = [], packages = [], customers = [], users = [] }: Props) {
    const endpoint = endpoints[module];
    const [items, setItems] = useState<Record<string, any>[]>([]);
    const [total, setTotal] = useState(0);
    const [loading, setLoading] = useState(false);
    const [message, setMessage] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);

    const [voucherForm, setVoucherForm] = useState({
        router_id: routers[0]?.id ? String(routers[0].id) : '',
        package_id: packages[0]?.id ? String(packages[0].id) : '',
        prefix: 'HS',
        count: '10',
        duration_minutes: '1440',
    });
    const selectedPackage = useMemo(() => packages.find((item) => String(item.id) === voucherForm.package_id), [packages, voucherForm.package_id]);

    const [ticketForm, setTicketForm] = useState({
        customer_id: customers[0]?.id ? String(customers[0].id) : '',
        assigned_to: '',
        type: 'incident',
        priority: 'normal',
        subject: '',
        description: '',
    });

    const [commissionForm, setCommissionForm] = useState({
        reseller_id: users[0]?.id ? String(users[0].id) : '',
        period: currentMonthWib(),
        base_amount: '',
        commission_amount: '',
    });

    const load = async () => {
        if (!endpoint) return;
        setLoading(true);
        setError(null);
        try {
            const payload: PageData = await jsonRequest(endpoint);
            setItems(payload.data || []);
            setTotal(payload.total || 0);
        } catch (err) {
            setError(err instanceof Error ? err.message : 'Gagal load data');
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        load();
    }, [module]);

    const submit = async (event: FormEvent, action: () => Promise<void>) => {
        event.preventDefault();
        setLoading(true);
        setMessage(null);
        setError(null);
        try {
            await action();
            await load();
        } catch (err) {
            setError(err instanceof Error ? err.message : 'Aksi gagal');
        } finally {
            setLoading(false);
        }
    };

    return (
        <EnterpriseIndexLayout
            title={title}
            pageTitle={title}
            subtitle={description}
            icon={moduleIcons[module]}
            actions={
                endpoint ? (
                    <Button type="button" variant="outline" className="h-9 gap-2" onClick={load} disabled={loading}>
                        <RefreshCw className="h-4 w-4" />
                        Muat Ulang
                    </Button>
                ) : undefined
            }
        >
            <div className="space-y-6">
                {(message || error) && (
                    <div className={`rounded-md border px-4 py-3 text-sm ${error ? 'border-destructive/30 text-destructive' : 'border-status-up/30 text-status-up'}`}>
                        {error || message}
                    </div>
                )}

                {module === 'hotspot' && (
                    <Card>
                        <CardHeader><CardTitle className="text-base">Generate Voucher Hotspot</CardTitle></CardHeader>
                        <CardContent>
                            <form className="grid gap-4 md:grid-cols-6" onSubmit={(event) => submit(event, async () => {
                                await jsonRequest('/api/hotspot-vouchers', {
                                    method: 'POST',
                                    body: JSON.stringify({
                                        router_id: voucherForm.router_id || null,
                                        package_id: voucherForm.package_id || null,
                                        prefix: voucherForm.prefix,
                                        count: Number(voucherForm.count),
                                        duration_minutes: Number(voucherForm.duration_minutes),
                                        price: selectedPackage?.price || 0,
                                        profile: selectedPackage?.mikrotik_profile,
                                        rate_limit: selectedPackage?.rate_limit,
                                    }),
                                });
                                setMessage('Voucher berhasil dibuat dan disync ke FreeRADIUS.');
                            })}>
                                <Field label="Router/NAS"><Select value={voucherForm.router_id || 'none'} onValueChange={(value) => setVoucherForm({ ...voucherForm, router_id: value === 'none' ? '' : value })}><SelectTrigger><SelectValue /></SelectTrigger><SelectContent><SelectItem value="none">Semua NAS</SelectItem>{routers.map((item) => <SelectItem key={item.id} value={String(item.id)}>{item.name}</SelectItem>)}</SelectContent></Select></Field>
                                <Field label="Paket"><Select value={voucherForm.package_id || 'none'} onValueChange={(value) => setVoucherForm({ ...voucherForm, package_id: value === 'none' ? '' : value })}><SelectTrigger><SelectValue /></SelectTrigger><SelectContent><SelectItem value="none">Manual</SelectItem>{packages.map((item) => <SelectItem key={item.id} value={String(item.id)}>{item.name}</SelectItem>)}</SelectContent></Select></Field>
                                <Field label="Prefix"><Input value={voucherForm.prefix} onChange={(e) => setVoucherForm({ ...voucherForm, prefix: e.target.value })} /></Field>
                                <Field label="Jumlah"><Input type="number" value={voucherForm.count} onChange={(e) => setVoucherForm({ ...voucherForm, count: e.target.value })} /></Field>
                                <Field label="Durasi Menit"><Input type="number" value={voucherForm.duration_minutes} onChange={(e) => setVoucherForm({ ...voucherForm, duration_minutes: e.target.value })} /></Field>
                                <div className="flex items-end"><Button type="submit" className="h-9 w-full gap-2" disabled={loading}><Plus className="h-4 w-4" />Generate</Button></div>
                            </form>
                        </CardContent>
                    </Card>
                )}

                {module === 'ticketing' && (
                    <Card>
                        <CardHeader><CardTitle className="text-base">Buat Tiket</CardTitle></CardHeader>
                        <CardContent>
                            <form className="grid gap-4 md:grid-cols-6" onSubmit={(event) => submit(event, async () => {
                                await jsonRequest('/api/support-tickets', { method: 'POST', body: JSON.stringify(ticketForm) });
                                setTicketForm({ ...ticketForm, subject: '', description: '' });
                                setMessage('Tiket berhasil dibuat.');
                            })}>
                                <Field label="Pelanggan"><Select value={ticketForm.customer_id || 'none'} onValueChange={(value) => setTicketForm({ ...ticketForm, customer_id: value === 'none' ? '' : value })}><SelectTrigger><SelectValue /></SelectTrigger><SelectContent><SelectItem value="none">Tanpa pelanggan</SelectItem>{customers.map((item) => <SelectItem key={item.id} value={String(item.id)}>{item.code} - {item.name}</SelectItem>)}</SelectContent></Select></Field>
                                <Field label="Teknisi"><Select value={ticketForm.assigned_to || 'none'} onValueChange={(value) => setTicketForm({ ...ticketForm, assigned_to: value === 'none' ? '' : value })}><SelectTrigger><SelectValue /></SelectTrigger><SelectContent><SelectItem value="none">Belum assign</SelectItem>{users.map((item) => <SelectItem key={item.id} value={String(item.id)}>{item.name}</SelectItem>)}</SelectContent></Select></Field>
                                <Field label="Tipe"><Select value={ticketForm.type} onValueChange={(value) => setTicketForm({ ...ticketForm, type: value })}><SelectTrigger><SelectValue /></SelectTrigger><SelectContent>{['installation', 'incident', 'billing', 'request', 'other'].map((value) => <SelectItem key={value} value={value}>{value}</SelectItem>)}</SelectContent></Select></Field>
                                <Field label="Prioritas"><Select value={ticketForm.priority} onValueChange={(value) => setTicketForm({ ...ticketForm, priority: value })}><SelectTrigger><SelectValue /></SelectTrigger><SelectContent>{['low', 'normal', 'high', 'urgent'].map((value) => <SelectItem key={value} value={value}>{value}</SelectItem>)}</SelectContent></Select></Field>
                                <Field label="Subjek"><Input value={ticketForm.subject} onChange={(e) => setTicketForm({ ...ticketForm, subject: e.target.value })} required /></Field>
                                <div className="flex items-end"><Button type="submit" className="h-9 w-full gap-2" disabled={loading}><Ticket className="h-4 w-4" />Simpan</Button></div>
                                <div className="md:col-span-6"><Textarea placeholder="Catatan tiket" value={ticketForm.description} onChange={(e) => setTicketForm({ ...ticketForm, description: e.target.value })} /></div>
                            </form>
                        </CardContent>
                    </Card>
                )}

                {module === 'payment_gateway' && (
                    <Card>
                        <CardHeader><CardTitle className="text-base">Webhook Payment Gateway</CardTitle></CardHeader>
                        <CardContent className="grid gap-3">
                            {PAYMENT_WEBHOOK_PROVIDERS.map((provider) => {
                                const url = `${window.location.origin}/webhooks/payment-gateway/${provider}`;
                                return (
                                    <div key={provider} className="grid gap-3 md:grid-cols-[1fr_auto]">
                                        <Input readOnly value={url} className="font-mono text-xs" aria-label={`Webhook ${provider}`} />
                                        <Button type="button" variant="outline" onClick={() => navigator.clipboard.writeText(url)}>Copy URL</Button>
                                    </div>
                                );
                            })}
                        </CardContent>
                    </Card>
                )}

                {module === 'genieacs' && (
                    <Card>
                        <CardHeader><CardTitle className="text-base">Sinkron GenieACS</CardTitle></CardHeader>
                        <CardContent>
                            <Button type="button" className="h-9 gap-2" disabled={loading} onClick={(event) => submit(event as any, async () => {
                                const payload = await jsonRequest('/api/genieacs/sync', { method: 'POST', body: '{}' });
                                setMessage(`GenieACS selesai sync: ${payload.synced || 0} device.`);
                            })}><Router className="h-4 w-4" />Sync Device</Button>
                        </CardContent>
                    </Card>
                )}

                {module === 'reseller' && (
                    <Card>
                        <CardHeader><CardTitle className="text-base">Input Komisi Mitra</CardTitle></CardHeader>
                        <CardContent>
                            <form className="grid gap-4 md:grid-cols-5" onSubmit={(event) => submit(event, async () => {
                                await jsonRequest('/api/reseller-commissions', { method: 'POST', body: JSON.stringify(commissionForm) });
                                setMessage('Komisi berhasil disimpan.');
                            })}>
                                <Field label="Mitra"><Select value={commissionForm.reseller_id} onValueChange={(value) => setCommissionForm({ ...commissionForm, reseller_id: value })}><SelectTrigger><SelectValue /></SelectTrigger><SelectContent>{users.map((item) => <SelectItem key={item.id} value={String(item.id)}>{item.name}</SelectItem>)}</SelectContent></Select></Field>
                                <Field label="Periode"><Input type="month" value={commissionForm.period} onChange={(e) => setCommissionForm({ ...commissionForm, period: e.target.value })} /></Field>
                                <Field label="Dasar"><Input type="number" value={commissionForm.base_amount} onChange={(e) => setCommissionForm({ ...commissionForm, base_amount: e.target.value })} /></Field>
                                <Field label="Komisi"><Input type="number" value={commissionForm.commission_amount} onChange={(e) => setCommissionForm({ ...commissionForm, commission_amount: e.target.value })} /></Field>
                                <div className="flex items-end"><Button type="submit" className="h-9 w-full gap-2" disabled={loading}><Check className="h-4 w-4" />Simpan</Button></div>
                            </form>
                        </CardContent>
                    </Card>
                )}

                {module === 'portal' || module === 'support' ? (
                    <Card>
                        <CardHeader><CardTitle className="text-base">{module === 'portal' ? 'Portal Pelanggan' : 'Pusat Dukungan'}</CardTitle></CardHeader>
                        <CardContent className="grid gap-4 md:grid-cols-3">
                            <InfoCard icon={<Wifi className="h-4 w-4" />} title="FreeRADIUS" value="Aktif" />
                            <InfoCard icon={<Router className="h-4 w-4" />} title="MikroTik & OLT" value="Siap koneksi" />
                            <InfoCard icon={<Send className="h-4 w-4" />} title="Webhook/API" value="Tersedia" />
                        </CardContent>
                    </Card>
                ) : (
                    <DataCard module={module} items={items} total={total} loading={loading} reload={load} />
                )}
            </div>
        </EnterpriseIndexLayout>
    );
}

function Field({ label, children }: { label: string; children: ReactNode }) {
    return <div className="space-y-2"><Label>{label}</Label>{children}</div>;
}

function InfoCard({ icon, title, value }: { icon: ReactNode; title: string; value: string }) {
    return <div className="rounded-md border p-4"><div className="flex items-center gap-2 text-sm text-muted-foreground">{icon}{title}</div><div className="mt-2 text-lg font-semibold">{value}</div></div>;
}

function DataCard({ module, items, total, loading, reload }: { module: ModuleKey; items: Record<string, any>[]; total: number; loading: boolean; reload: () => void }) {
    const heads = module === 'hotspot'
        ? ['Username', 'Batch', 'Profile', 'Harga', 'Status']
        : module === 'payment_gateway'
            ? ['Provider', 'Reference', 'Amount', 'Status', 'Processed']
            : module === 'ticketing'
                ? ['Kode', 'Subjek', 'Pelanggan', 'Prioritas', 'Status']
                : module === 'genieacs'
                    ? ['Device', 'Serial', 'Pelanggan', 'IP', 'Last Inform']
                    : ['Mitra', 'Periode', 'Dasar', 'Komisi', 'Status'];

    const row = (item: Record<string, any>) => {
        if (module === 'hotspot') return [item.username, item.batch_code, item.profile || '-', item.price || 0, statusBadge(item.status)];
        if (module === 'payment_gateway') return [item.provider, item.reference || '-', item.amount || '-', statusBadge(item.status), formatWibDateTime(item.processed_at)];
        if (module === 'ticketing') return [item.code, item.subject, item.customer?.name || '-', item.priority, statusBadge(item.status)];
        if (module === 'genieacs') return [item.device_id, item.serial_number || '-', item.customer?.name || '-', item.ip_address || '-', formatWibDateTime(item.last_inform_at)];
        return [item.reseller?.name || item.reseller_id, item.period, item.base_amount, item.commission_amount, statusBadge(item.status)];
    };

    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0">
                <CardTitle className="text-base">Data Modul <span className="text-muted-foreground">({total})</span></CardTitle>
                {loading && <LoaderCircle className="h-4 w-4 animate-spin text-muted-foreground" />}
            </CardHeader>
            <CardContent>
                <Table>
                    <TableHeader><TableRow>{heads.map((head) => <TableHead key={head}>{head}</TableHead>)}</TableRow></TableHeader>
                    <TableBody>
                        {items.length === 0 ? (
                            <TableRow><TableCell colSpan={heads.length} className="h-20 text-center text-muted-foreground">Belum ada data. Jalankan aksi modul lalu refresh.</TableCell></TableRow>
                        ) : items.map((item) => (
                            <TableRow key={item.id}>{row(item).map((cell, index) => <TableCell key={index}>{cell}</TableCell>)}</TableRow>
                        ))}
                    </TableBody>
                </Table>
                <div className="mt-4 flex justify-end"><Button type="button" variant="outline" size="sm" onClick={reload}>Muat Ulang Data</Button></div>
            </CardContent>
        </Card>
    );
}
