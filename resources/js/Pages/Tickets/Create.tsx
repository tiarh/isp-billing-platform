import { EnterpriseFormLayout } from '@/Components/enterprise/EnterpriseFormLayout';
import { Link, useForm } from '@inertiajs/react';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Popover, PopoverContent, PopoverTrigger } from '@/Components/ui/popover';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/Components/ui/command';
import { Save, ChevronsUpDown, Check, TicketCheck } from 'lucide-react';
import { FormEvent, useState, useCallback, useRef, useEffect } from 'react';
import { cn } from '@/lib/utils';
import DismantleReasonFields, { DismantleReasonOption } from '@/Components/DismantleReasonFields';
import DiagnosisPanel, { type DiagnosisPanelProps } from '@/Components/tickets/DiagnosisPanel';

interface Customer {
    id: number;
    code: string;
    name: string;
    phone?: string | null;
    address?: string | null;
    pppoe_user?: string | null;
    status?: string | null;
    geo_lat: number | null;
    geo_long: number | null;
    area?: { id: number; name: string } | null;
    package?: { id: number; name: string } | null;
}

interface User {
    id: number;
    name: string;
    role: string;
    phone?: string;
}

interface Props {
    customers: Customer[];
    technicians: User[];
    dismantleReasonOptions?: DismantleReasonOption[];
    /** RCA-4: null when the "Diagnosa otomatis" panel is not shown to this role. */
    diagnosisPanel?: DiagnosisPanelProps | null;
}

export default function Create({ customers: initialCustomers, technicians, dismantleReasonOptions = [], diagnosisPanel = null }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        customer_id: '' as string,
        customer_name: '' as string,
        customer_phone: '' as string,
        customer_coords: '' as string,
        gangguan: '' as string,
        keterangan: '' as string,
        type: 'incident' as string,
        priority: 'normal' as string,
        assigned_to: '' as string,
        due_at: '' as string,
        dismantle_reason: '' as string,
        dismantle_reason_note: '' as string,
        diagnosis_check_id: '' as string,
    });

    // ── Customer Combobox state ──
    const [open, setOpen] = useState(false);
    const [search, setSearch] = useState('');
    const [customers, setCustomers] = useState<Customer[]>(initialCustomers);
    const [loading, setLoading] = useState(false);
    const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    // Debounced API search
    const handleSearch = useCallback((value: string) => {
        setSearch(value);
        if (debounceRef.current) clearTimeout(debounceRef.current);

        if (!value.trim()) {
            setCustomers(initialCustomers);
            setLoading(false);
            return;
        }

        setLoading(true);
        debounceRef.current = setTimeout(async () => {
            try {
                const res = await fetch(`/api/customers/search?q=${encodeURIComponent(value)}`, {
                    headers: { Accept: 'application/json' },
                });
                if (res.ok) {
                    const json = await res.json();
                    setCustomers(json);
                }
            } catch {
                // fallback to initial list
            } finally {
                setLoading(false);
            }
        }, 200);
    }, [initialCustomers]);

    // Cleanup debounce on unmount
    useEffect(() => {
        return () => {
            if (debounceRef.current) clearTimeout(debounceRef.current);
        };
    }, []);

    const handleSelect = (customerId: string) => {
        if (customerId === '__none__') {
            setData('customer_id', '');
            setData('customer_name', '');
            setData('customer_phone', '');
            setData('customer_coords', '');
            setSearch('');
            setOpen(false);
            return;
        }

        const customer = customers.find(c => c.id === Number(customerId));
        if (customer) {
            setData('customer_id', customerId);
            setData('customer_name', customer.name);
            setData('customer_phone', customer.phone || '');
            if (customer.geo_lat && customer.geo_long) {
                setData('customer_coords', `${customer.geo_lat},${customer.geo_long}`);
            }
            setSearch(`${customer.code} - ${customer.name}`);
        }
        setOpen(false);
    };

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        post(route('tickets.store'));
    };

    const selectedCustomer = customers.find(c => c.id === Number(data.customer_id));
    const selectedCustomerLabel = selectedCustomer
        ? `${selectedCustomer.name} · ${selectedCustomer.code}${selectedCustomer.area?.name ? ` · ${selectedCustomer.area.name}` : ''}`
        : data.customer_id && data.customer_name
            ? `${data.customer_name}${data.customer_phone ? ` · ${data.customer_phone}` : ''}`
            : 'Ketik nama / kode / PPPoE / area / no HP...';

    return (
        <EnterpriseFormLayout
            title="Buat Tiket Baru"
            pageTitle="Buat Tiket"
            subtitle="Input tiket gangguan atau pasang baru"
            icon={TicketCheck}
            backHref={route('tickets.index')}
            breadcrumbs={[
                { label: 'Ticketing', href: route('tickets.index') },
                { label: 'Create' },
            ]}
            maxWidth="5xl"
        >
            <form onSubmit={handleSubmit} className="space-y-6">
                    {/* Data Pelanggan */}
                    <Card>
                        <CardHeader><CardTitle className="text-base">Data Pelanggan</CardTitle></CardHeader>
                        <CardContent className="grid gap-4 md:grid-cols-2">
                            <div className="space-y-2">
                                <Label>Pelanggan</Label>
                                <Popover open={open} onOpenChange={setOpen}>
                                    <PopoverTrigger asChild>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            role="combobox"
                                            aria-expanded={open}
                                            className="h-9 w-full justify-between font-normal"
                                        >
                                            <span className="truncate text-left">{selectedCustomerLabel}</span>
                                            <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                                        </Button>
                                    </PopoverTrigger>
                                    <PopoverContent className="w-[min(760px,calc(100vw-2rem))] p-0" align="start">
                                        <Command shouldFilter={false}>
                                            <CommandInput
                                                placeholder="Ketik nama, kode, PPPoE, area, alamat, atau no HP..."
                                                value={search}
                                                onValueChange={handleSearch}
                                            />
                                            <CommandList>
                                                <CommandEmpty>
                                                    {loading ? 'Mencari...' : 'Pelanggan tidak ditemukan'}
                                                </CommandEmpty>
                                                <CommandGroup>
                                                    <CommandItem
                                                        value="__none__"
                                                        onSelect={() => handleSelect('__none__')}
                                                    >
                                                        <Check className={cn('mr-2 h-4 w-4', !data.customer_id ? 'opacity-100' : 'opacity-0')} />
                                                        Tanpa pelanggan
                                                    </CommandItem>
                                                    {customers.map(c => (
                                                        <CommandItem
                                                            key={c.id}
                                                            value={String(c.id)}
                                                            onSelect={() => handleSelect(String(c.id))}
                                                            className="items-start gap-2 py-2"
                                                        >
                                                            <Check className={cn('mt-0.5 h-4 w-4 shrink-0', data.customer_id === String(c.id) ? 'opacity-100' : 'opacity-0')} />
                                                            <div className="min-w-0 flex-1">
                                                                <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                                                                    <span className="font-medium">{c.name}</span>
                                                                    <span className="font-mono text-xs text-muted-foreground">{c.code}</span>
                                                                    {c.pppoe_user && <span className="font-mono text-xs text-muted-foreground">PPPoE: {c.pppoe_user}</span>}
                                                                </div>
                                                                <div className="mt-1 flex flex-wrap gap-x-2 gap-y-1 text-xs text-muted-foreground">
                                                                    {c.area?.name && <span>{c.area.name}</span>}
                                                                    {c.package?.name && <span>{c.package.name}</span>}
                                                                    {c.phone && <span>{c.phone}</span>}
                                                                    {c.status && <span>{c.status}</span>}
                                                                </div>
                                                                {c.address && <div className="mt-1 truncate text-xs text-muted-foreground">{c.address}</div>}
                                                            </div>
                                                        </CommandItem>
                                                    ))}
                                                </CommandGroup>
                                            </CommandList>
                                        </Command>
                                    </PopoverContent>
                                </Popover>
                                {errors.customer_id && <p className="text-xs text-destructive">{errors.customer_id}</p>}
                            </div>
                            <div className="space-y-2">
                                <Label>Nama Pelanggan</Label>
                                <Input
                                    value={data.customer_name}
                                    onChange={(e) => setData('customer_name', e.target.value)}
                                    placeholder="Isi manual atau auto-fill"
                                    className="h-9"
                                />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="ticket-customer-phone">No HP</Label>
                                <Input
                                    id="ticket-customer-phone"
                                    type="tel"
                                    inputMode="tel"
                                    value={data.customer_phone}
                                    onChange={(e) => setData('customer_phone', e.target.value)}
                                    placeholder="Isi manual atau auto-fill"
                                    className="h-9"
                                />
                            </div>
                            <div className="space-y-2">
                                <Label>Koordinat</Label>
                                <Input
                                    value={data.customer_coords}
                                    onChange={(e) => setData('customer_coords', e.target.value)}
                                    placeholder="lat,lng (auto-fill dari data pelanggan)"
                                    className="h-9"
                                />
                            </div>
                        </CardContent>
                    </Card>

                    {/* RCA-4: Diagnosa otomatis (only for a Gangguan ticket with a customer, and only for allowed roles) */}
                    {diagnosisPanel && data.type === 'incident' && data.customer_id !== '' && (
                        <DiagnosisPanel
                            customerId={Number(data.customer_id)}
                            initial={null}
                            fetchPreview
                            onCheckId={(id) => setData('diagnosis_check_id', id === null ? '' : String(id))}
                        />
                    )}

                    {/* Detail Gangguan */}
                    <Card>
                        <CardHeader><CardTitle className="text-base">Detail Gangguan</CardTitle></CardHeader>
                        <CardContent className="grid gap-4">
                            <div className="space-y-2">
                                <Label>Gangguan / Kendala *</Label>
                                <Textarea
                                    value={data.gangguan}
                                    onChange={(e) => setData('gangguan', e.target.value)}
                                    placeholder="Deskripsi gangguan atau keluhan pelanggan..."
                                    rows={3}
                                    required
                                />
                                {errors.gangguan && <p className="text-xs text-destructive">{errors.gangguan}</p>}
                            </div>
                            <div className="space-y-2">
                                <Label>Keterangan</Label>
                                <Textarea
                                    value={data.keterangan}
                                    onChange={(e) => setData('keterangan', e.target.value)}
                                    placeholder="Catatan tambahan (opsional)..."
                                    rows={2}
                                />
                            </div>
                            <div className="grid gap-4 md:grid-cols-3">
                                <div className="space-y-2">
                                    <Label>Tipe</Label>
                                    <Select value={data.type} onValueChange={(v) => setData('type', v)}>
                                        <SelectTrigger className="h-9"><SelectValue /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="incident">Gangguan</SelectItem>
                                            <SelectItem value="installation">Pasang Baru</SelectItem>
                                            <SelectItem value="billing">Tagihan</SelectItem>
                                            <SelectItem value="request">Request</SelectItem>
                                            <SelectItem value="dismantle">Dismantle (ambil perangkat)</SelectItem>
                                            <SelectItem value="other">Lainnya</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="space-y-2">
                                    <Label>Prioritas</Label>
                                    <Select value={data.priority} onValueChange={(v) => setData('priority', v)}>
                                        <SelectTrigger className="h-9"><SelectValue /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="low">Low</SelectItem>
                                            <SelectItem value="normal">Normal</SelectItem>
                                            <SelectItem value="high">High</SelectItem>
                                            <SelectItem value="urgent">Urgent</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="space-y-2">
                                    <Label>Deadline / SLA</Label>
                                    <Input
                                        type="datetime-local"
                                        value={data.due_at}
                                        onChange={(e) => setData('due_at', e.target.value)}
                                        className="h-9"
                                    />
                                    <p className="text-xs text-muted-foreground">Opsional. Teknisi akan melihat countdown ke deadline.</p>
                                </div>
                            </div>
                            {data.type === 'dismantle' && (
                                <DismantleReasonFields
                                    options={dismantleReasonOptions}
                                    reason={data.dismantle_reason}
                                    note={data.dismantle_reason_note}
                                    onReasonChange={(v) => setData('dismantle_reason', v)}
                                    onNoteChange={(v) => setData('dismantle_reason_note', v)}
                                    reasonError={errors.dismantle_reason}
                                    noteError={errors.dismantle_reason_note}
                                />
                            )}
                        </CardContent>
                    </Card>

                    {/* Assign Teknisi */}
                    <Card>
                        <CardHeader><CardTitle className="text-base">Assign Teknisi</CardTitle></CardHeader>
                        <CardContent>
                            <div className="space-y-2">
                                <Label>Teknisi</Label>
                                <Select value={data.assigned_to} onValueChange={(v) => setData('assigned_to', v)}>
                                    <SelectTrigger className="h-9 max-w-sm">
                                        <SelectValue placeholder="Pilih teknisi (opsional)" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="__none__">Belum assign</SelectItem>
                                        {technicians.map(t => (
                                            <SelectItem key={t.id} value={String(t.id)}>
                                                {t.name} ({t.role === 'teknisi' ? 'Teknisi' : t.role === 'noc' ? 'NOC' : 'Admin'}){t.phone ? ` · ${t.phone}` : ''}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <p className="text-xs text-muted-foreground">Teknisi akan menerima notifikasi WA saat di-assign</p>
                            </div>
                        </CardContent>
                    </Card>

                    <div className="flex gap-3">
                        <Button type="submit" disabled={processing} className="gap-2">
                            <Save className="h-4 w-4" />
                            Simpan Tiket
                        </Button>
                        <Button variant="outline" asChild>
                            <Link href={route('tickets.index')}>Batal</Link>
                        </Button>
                    </div>
            </form>
        </EnterpriseFormLayout>
    );
}
