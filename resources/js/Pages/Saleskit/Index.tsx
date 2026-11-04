import { EnterpriseIndexLayout } from '@/Components/enterprise/EnterpriseIndexLayout';
import { EnterpriseMetricCard } from '@/Components/enterprise/EnterpriseMetricCard';
import DataTable, { Column, FilterConfig, PaginatedData } from '@/Components/DataTable';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/Components/ui/select';
import { Textarea } from '@/Components/ui/textarea';
import { useForm, router } from '@inertiajs/react';
import {
    CheckCircle2,
    ClipboardList,
    Download,
    MapPin,
    Pencil,
    Plus,
    RefreshCw,
    Trash2,
    Upload,
    UserPlus,
    Users,
    XCircle,
} from 'lucide-react';
import { useRef, useState } from 'react';

type Lead = {
    id: number;
    code: string | null;
    name: string;
    phone: string | null;
    address: string | null;
    area_id: number | null;
    area?: { id: number; name: string } | null;
    daerah: string;
    rt: string | null;
    rw: string | null;
    odp_name: string | null;
    status: string;
    reason_code: string | null;
    reason_note: string | null;
    notes: string | null;
    converted_customer_id: number | null;
    customer?: { id: number; name: string; code: string | null } | null;
};

type Summary = {
    total: number;
    installed: number;
    open: number;
    cancelled: number;
    conversion: number;
    by_reason: Record<string, number>;
};

type Props = {
    leads: PaginatedData<Lead>;
    filters: Record<string, any>;
    summary: Summary;
    areas: Array<{ id: number; name: string }>;
    daerahOptions: string[];
    reasons: string[];
    statuses: string[];
    canManage: boolean;
};

const REASON_LABELS: Record<string, string> = {
    belum_ada_odp: 'Belum ada ODP',
    odp_penuh: 'ODP penuh',
    jarak_jauh: 'Jarak jauh',
    belum_bayar: 'Belum bayar',
    rumah_kosong: 'Rumah kosong',
    tidak_jadi: 'Tidak jadi',
    tunggu_tiang_fo: 'Tunggu tiang/FO',
    lainnya: 'Lainnya',
};

const STATUS_LABELS: Record<string, string> = {
    calon: 'Calon',
    terpasang: 'Terpasang',
    batal: 'Batal',
};

function statusTone(status: string): string {
    if (status === 'terpasang') return 'border-transparent bg-status-up-bg text-status-up';
    if (status === 'batal') return 'border-transparent bg-status-down-bg text-status-down';
    return 'border-transparent bg-status-degraded-bg text-status-degraded';
}

export default function Index({
    leads,
    filters,
    summary,
    areas,
    daerahOptions,
    reasons,
    statuses,
    canManage,
}: Props) {
    const [dialogOpen, setDialogOpen] = useState(false);
    const [editing, setEditing] = useState<Lead | null>(null);
    const fileRef = useRef<HTMLInputElement>(null);

    const form = useForm({
        name: '',
        phone: '',
        address: '',
        area_id: '',
        daerah: '',
        rt: '',
        rw: '',
        odp_name: '',
        status: 'calon',
        reason_code: '',
        reason_note: '',
        notes: '',
    });

    const openCreate = () => {
        setEditing(null);
        form.reset();
        form.clearErrors();
        setDialogOpen(true);
    };

    const openEdit = (lead: Lead) => {
        setEditing(lead);
        form.setData({
            name: lead.name,
            phone: lead.phone || '',
            address: lead.address || '',
            area_id: lead.area_id ? String(lead.area_id) : '',
            daerah: lead.daerah || '',
            rt: lead.rt || '',
            rw: lead.rw || '',
            odp_name: lead.odp_name || '',
            status: lead.status || 'calon',
            reason_code: lead.reason_code || '',
            reason_note: lead.reason_note || '',
            notes: lead.notes || '',
        });
        form.clearErrors();
        setDialogOpen(true);
    };

    const submit = () => {
        const options = {
            preserveScroll: true,
            onSuccess: () => {
                setDialogOpen(false);
                form.reset();
            },
        };
        if (editing) {
            form.put(route('saleskit.update', editing.id), options);
        } else {
            form.post(route('saleskit.store'), options);
        }
    };

    const remove = (lead: Lead) => {
        if (!window.confirm(`Hapus calon pelanggan ${lead.name}?`)) return;
        router.delete(route('saleskit.destroy', lead.id), { preserveScroll: true });
    };

    const importCsv = () => fileRef.current?.click();

    const onFile = (event: React.ChangeEvent<HTMLInputElement>) => {
        const file = event.target.files?.[0];
        if (!file) return;
        router.post(
            route('saleskit.import'),
            { file },
            {
                forceFormData: true,
                preserveScroll: true,
                onFinish: () => {
                    if (fileRef.current) fileRef.current.value = '';
                },
            },
        );
    };

    const columns: Column<Lead>[] = [
        {
            header: 'Calon pelanggan',
            cell: (lead) => (
                <div>
                    <div className="font-medium">{lead.name}</div>
                    <div className="text-xs text-muted-foreground">
                        {lead.code || '-'} · {lead.phone || 'tanpa HP'}
                    </div>
                </div>
            ),
        },
        {
            header: 'Daerah / RT / RW',
            cell: (lead) => (
                <div>
                    <div>{lead.daerah}</div>
                    <div className="text-xs text-muted-foreground">
                        RT {lead.rt || '-'} / RW {lead.rw || '-'}
                        {lead.odp_name ? ` · ODP ${lead.odp_name}` : ''}
                    </div>
                </div>
            ),
        },
        {
            header: 'Area',
            cell: (lead) => <span>{lead.area?.name || '-'}</span>,
        },
        {
            header: 'Alamat',
            cell: (lead) => (
                <span className="block max-w-[260px] truncate text-xs text-muted-foreground" title={lead.address || undefined}>{lead.address || '-'}</span>
            ),
        },
        {
            header: 'Status',
            cell: (lead) => (
                <div className="flex flex-col gap-1">
                    <Badge variant="outline" className={statusTone(lead.status)}>
                        {STATUS_LABELS[lead.status] || lead.status}
                    </Badge>
                    {lead.status === 'calon' && lead.reason_code && (
                        <span className="text-xs text-muted-foreground">
                            {REASON_LABELS[lead.reason_code] || lead.reason_code}
                        </span>
                    )}
                    {lead.customer && (
                        <span className="text-xs text-muted-foreground">→ {lead.customer.name}</span>
                    )}
                </div>
            ),
        },
        {
            header: '',
            className: 'text-right',
            cell: (lead) => (
                <div className="flex justify-end gap-1">
                    <Button variant="ghost" size="icon" className="h-10 w-10 md:h-9 md:w-9" onClick={() => openEdit(lead)} title="Edit" aria-label={`Edit ${lead.name ?? 'lead'}`}>
                        <Pencil className="h-4 w-4" aria-hidden="true" />
                    </Button>
                    {canManage && (
                        <Button
                            variant="ghost"
                            size="icon"
                            className="h-10 w-10 md:h-9 md:w-9"
                            onClick={() => remove(lead)}
                            title="Hapus"
                            aria-label={`Hapus ${lead.name ?? 'lead'}`}
                        >
                            <Trash2 className="h-4 w-4 text-destructive" aria-hidden="true" />
                        </Button>
                    )}
                </div>
            ),
        },
    ];

    const filterConfigs: FilterConfig[] = [
        {
            key: 'status',
            placeholder: 'Semua status',
            options: statuses.map((s) => ({ label: STATUS_LABELS[s] || s, value: s })),
        },
        {
            key: 'area_id',
            placeholder: 'Semua area',
            options: areas.map((a) => ({ label: a.name, value: String(a.id) })),
        },
        {
            key: 'reason',
            placeholder: 'Semua alasan',
            options: reasons.map((r) => ({ label: REASON_LABELS[r] || r, value: r })),
        },
    ];

    return (
        <EnterpriseIndexLayout
            title="Saleskit — Calon Pelanggan"
            subtitle="Pendataan calon pelanggan per daerah & RT/RW sebelum dipasang."
            icon={ClipboardList}
            actions={
                <div className="flex flex-wrap items-center gap-2">
                    <input
                        ref={fileRef}
                        type="file"
                        accept=".csv,text/csv,text/plain"
                        className="hidden"
                        onChange={onFile}
                    />
                    {canManage && (
                        <>
                            <Button variant="outline" size="sm" onClick={importCsv}>
                                <Upload className="mr-2 h-4 w-4" /> Import CSV
                            </Button>
                            <Button variant="outline" size="sm" onClick={() => router.post(route('saleskit.sync-installed'), {}, { preserveScroll: true })}>
                                <RefreshCw className="mr-2 h-4 w-4" /> Sinkron terpasang
                            </Button>
                            <Button variant="outline" size="sm" onClick={() => window.location.assign(route('saleskit.export'))}>
                                <Download className="mr-2 h-4 w-4" /> Export
                            </Button>
                        </>
                    )}
                    <Button size="sm" onClick={openCreate}>
                        <Plus className="mr-2 h-4 w-4" /> Tambah calon
                    </Button>
                </div>
            }
        >
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                <EnterpriseMetricCard title="Total calon" value={String(summary.total)} icon={Users} />
                <EnterpriseMetricCard title="Terpasang" value={String(summary.installed)} icon={CheckCircle2} hint={`${summary.conversion}% konversi`} />
                <EnterpriseMetricCard title="Belum terpasang" value={String(summary.open)} icon={UserPlus} />
                <EnterpriseMetricCard title="Batal" value={String(summary.cancelled)} icon={XCircle} />
                <EnterpriseMetricCard
                    title="Alasan terbanyak"
                    value={topReason(summary.by_reason)}
                    icon={MapPin}
                />
            </div>

            {Object.keys(summary.by_reason).length > 0 && (
                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle className="text-base">Alasan belum terpasang</CardTitle>
                    </CardHeader>
                    <CardContent className="flex flex-wrap gap-2">
                        {Object.entries(summary.by_reason).map(([code, total]) => (
                            <Badge key={code} variant="outline">
                                {REASON_LABELS[code] || code}: {total}
                            </Badge>
                        ))}
                    </CardContent>
                </Card>
            )}

            <DataTable<Lead>
                compact
                data={leads}
                columns={columns}
                title=""
                description={`${leads.total} calon pelanggan · tambah manual atau import CSV (wajib: nama, daerah)`}
                routeName="saleskit.index"
                filters={filters}
                filterConfigs={filterConfigs}
                searchPlaceholder="Cari nama, HP, alamat, kode…"
            />

            <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
                <DialogContent className="max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>{editing ? 'Edit calon pelanggan' : 'Tambah calon pelanggan'}</DialogTitle>
                    </DialogHeader>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Nama" required error={form.errors.name}>
                            <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder="Nama calon pelanggan" />
                        </Field>
                        <Field label="No HP" error={form.errors.phone}>
                            <Input value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} placeholder="08xxxxxxxxxx" />
                        </Field>
                        <Field label="Daerah" required error={form.errors.daerah}>
                            <Input
                                value={form.data.daerah}
                                onChange={(e) => form.setData('daerah', e.target.value)}
                                placeholder="mis. GONDANG"
                                list="saleskit-daerah"
                            />
                            <datalist id="saleskit-daerah">
                                {daerahOptions.map((d) => (
                                    <option key={d} value={d} />
                                ))}
                            </datalist>
                        </Field>
                        <Field label="Area" error={form.errors.area_id}>
                            <Select value={form.data.area_id || 'none'} onValueChange={(v) => form.setData('area_id', v === 'none' ? '' : v)}>
                                <SelectTrigger>
                                    <SelectValue placeholder="Pilih area" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="none">Tanpa area</SelectItem>
                                    {areas.map((a) => (
                                        <SelectItem key={a.id} value={String(a.id)}>
                                            {a.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>
                        <Field label="RT" error={form.errors.rt}>
                            <Input value={form.data.rt} onChange={(e) => form.setData('rt', e.target.value)} placeholder="05" />
                        </Field>
                        <Field label="RW" error={form.errors.rw}>
                            <Input value={form.data.rw} onChange={(e) => form.setData('rw', e.target.value)} placeholder="06" />
                        </Field>
                        <Field label="ODP (opsional)" error={form.errors.odp_name}>
                            <Input value={form.data.odp_name} onChange={(e) => form.setData('odp_name', e.target.value)} placeholder="kode ODP" />
                        </Field>
                        <Field label="Status" error={form.errors.status}>
                            <Select value={form.data.status} onValueChange={(v) => form.setData('status', v)}>
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {statuses.map((s) => (
                                        <SelectItem key={s} value={s}>
                                            {STATUS_LABELS[s] || s}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>
                        {form.data.status === 'calon' && (
                            <Field label="Alasan belum terpasang" error={form.errors.reason_code}>
                                <Select value={form.data.reason_code || 'none'} onValueChange={(v) => form.setData('reason_code', v === 'none' ? '' : v)}>
                                    <SelectTrigger>
                                        <SelectValue placeholder="Pilih alasan" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="none">Belum ada alasan</SelectItem>
                                        {reasons.map((r) => (
                                            <SelectItem key={r} value={r}>
                                                {REASON_LABELS[r] || r}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </Field>
                        )}
                        <div className="sm:col-span-2">
                            <Field label="Alamat" error={form.errors.address}>
                                <Textarea value={form.data.address} onChange={(e) => form.setData('address', e.target.value)} placeholder="Alamat / patokan" rows={2} />
                            </Field>
                        </div>
                        <div className="sm:col-span-2">
                            <Field label="Catatan" error={form.errors.notes}>
                                <Textarea value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} rows={2} />
                            </Field>
                        </div>
                    </div>

                    <DialogFooter>
                        <Button variant="outline" onClick={() => setDialogOpen(false)}>
                            Batal
                        </Button>
                        <Button onClick={submit} disabled={form.processing}>
                            {editing ? 'Simpan' : 'Tambah'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </EnterpriseIndexLayout>
    );
}

function topReason(byReason: Record<string, number>): string {
    const entries = Object.entries(byReason);
    if (entries.length === 0) return '-';
    entries.sort((a, b) => b[1] - a[1]);
    return REASON_LABELS[entries[0][0]] || entries[0][0];
}

function Field({
    label,
    required,
    error,
    children,
}: {
    label: string;
    required?: boolean;
    error?: string;
    children: React.ReactNode;
}) {
    return (
        <div className="grid gap-1.5">
            <Label>
                {label}
                {required && <span className="text-status-down"> *</span>}
            </Label>
            {children}
            {error && <p className="text-xs text-status-down">{error}</p>}
        </div>
    );
}
