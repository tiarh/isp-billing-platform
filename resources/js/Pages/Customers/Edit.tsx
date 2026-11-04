import { EnterpriseFormLayout } from '@/Components/enterprise/EnterpriseFormLayout';
import { Link, useForm, usePage } from '@inertiajs/react';
import { PageProps } from '@/types';
import { LifecycleGuardNotice, lifecycleOverrideReady } from '@/Components/LifecycleGuardNotice';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Textarea } from '@/Components/ui/textarea';
import { Save, User, UserPlus, MapPin, Shield, Network, Trash2 } from 'lucide-react';
import MapPicker from '@/Components/MapPicker';
import { FormEventHandler, useEffect, useState } from 'react';
import { nullableId, optionalImage, optionalNumberInRange, requiredId, requiredString, validateForm } from '@/lib/validation';
import { formatIdr } from '@/lib/format';
import { useUnsavedChangesGuard } from '@/hooks/useUnsavedChangesGuard';
import { z } from 'zod';
import { CUSTOMER_STATUS_LABELS, type CustomerStatus } from '@/lib/customerLabels';
import { ConfirmDestructive } from '@/Components/ConfirmDestructive';

// Interfaces
interface Package {
    id: number;
    name: string;
    price: number;
    mikrotik_profile?: string;
    router_id?: number | null;
}

interface Area {
    id: number;
    name: string;
}

interface Router {
    id: number;
    name: string;
}

interface Olt {
    id: number;
    name: string;
    router_id: number | null;
}

interface Customer {
    id: number;
    name: string;
    // internal_id removed
    address: string;
    phone?: string;
    nik?: string;
    pppoe_user: string;
    package_id: number;
    area_id?: number | null;
    olt_id?: number | null;
    olt_port_label?: string | null;
    onu_serial?: string | null;
    olt_status?: string | null;
    onu_rx_power_dbm?: string | null;
    onu_tx_power_dbm?: string | null;
    fiber_distance_m?: number | null;
    status: CustomerStatus;
    due_day?: number | null;
    geo_lat?: string;
    geo_long?: string;
    ktp_photo_url?: string | null;
    router_id?: number | null;
}

interface Props {
    customer: Customer;
    packages: Package[];
    areas: Area[];
    olts: Olt[];
    routers: Router[];
    canEditPppoeUsername: boolean;
}

const customerUpdateSchema = z.object({
    name: requiredString('Customer name'),
    address: z.string().trim().min(1, 'Installation address is required.'),
    phone: z.string().max(20, 'Phone may not be greater than 20 characters.').optional(),
    nik: z.string().max(20, 'NIK may not be greater than 20 characters.').optional(),
    package_id: requiredId('Package'),
    area_id: nullableId('Area'),
    router_id: nullableId('Router'),
    olt_id: nullableId('OLT'),
    olt_port_label: z.string().max(255, 'OLT port may not be greater than 255 characters.').optional(),
    onu_serial: z.string().max(255, 'ONU serial may not be greater than 255 characters.').optional(),
    olt_status: z.string().max(50, 'OLT status may not be greater than 50 characters.').optional(),
    onu_rx_power_dbm: optionalNumberInRange('ONU Rx Power', -100, 100),
    onu_tx_power_dbm: optionalNumberInRange('ONU Tx Power', -100, 100),
    fiber_distance_m: z.preprocess(
        (value) => value === '' || value === null || value === undefined ? null : Number(value),
        z.number({ error: 'Fiber distance must be a number.' }).int().min(0).nullable()
    ),
    status: z.enum(['pending_installation', 'active', 'isolated', 'terminated'], { error: 'Status is required.' }),
    due_day: z.preprocess(
        (value) => value === '' || value === null || value === undefined ? null : Number(value),
        z.number({ error: 'Tanggal jatuh tempo harus angka.' }).int().min(1).max(31).nullable()
    ),
    pppoe_user: z.string().max(255, 'PPPoE username may not be greater than 255 characters.'),
    geo_lat: optionalNumberInRange('Latitude', -90, 90),
    geo_long: optionalNumberInRange('Longitude', -180, 180),
    ktp_photo: optionalImage('KTP photo'),
});

// C4: setiap error validasi server dirender di bawah field-nya (bukan hanya di ringkasan atas).
function FieldError({ id, message }: { id: string; message?: string }) {
    if (!message) return null;

    return (
        <p id={`${id}-error`} className="text-sm text-destructive">
            {message}
        </p>
    );
}

function fieldErrorProps(id: string, message?: string) {
    return message ? { 'aria-invalid': true as const, 'aria-describedby': `${id}-error` } : {};
}

export default function Edit({ customer, packages, areas, olts, routers, canEditPppoeUsername }: Props) {
    const form = useForm({
        name: customer.name || '',
        address: customer.address || '',
        phone: customer.phone || '',
        nik: customer.nik || '',
        pppoe_user: customer.pppoe_user || '',
        pppoe_local_address: (customer as any).pppoe_local_address || '',
        pppoe_remote_address: (customer as any).pppoe_remote_address || '',

        package_id: String(customer.package_id),
        area_id: customer.area_id ? String(customer.area_id) : '',
        router_id: customer.router_id ? String(customer.router_id) : '',
        olt_id: customer.olt_id ? String(customer.olt_id) : '',
        olt_port_label: customer.olt_port_label || '',
        onu_serial: customer.onu_serial || '',
        olt_status: customer.olt_status || '',
        onu_rx_power_dbm: customer.onu_rx_power_dbm || '',
        onu_tx_power_dbm: customer.onu_tx_power_dbm || '',
        fiber_distance_m: customer.fiber_distance_m ? String(customer.fiber_distance_m) : '',
        status: customer.status,
        due_day: customer.due_day ? String(customer.due_day) : '',
        geo_lat: customer.geo_lat || '',
        geo_long: customer.geo_long || '',
        ktp_photo: null as File | null,
        // Roadmap 1.3: superadmin override of the lifecycle guard when status -> terminated is refused.
        lifecycle_override: false,
        lifecycle_override_reason: '',
    });
    const { data, setData, put, errors } = form;
    const { auth } = usePage<PageProps>().props;
    const isSuperadmin = auth.user.role === 'superadmin';
    const updateGuardMessage = (errors as Record<string, string | undefined>).lifecycle_guard;
    // Archive has its own form so the customer fields are never sent with the DELETE.
    const archiveForm = useForm({ override: false, override_reason: '' });
    const processing = form.processing || archiveForm.processing;
    const archiveGuardMessage = (archiveForm.errors as Record<string, string | undefined>).lifecycle_guard;
    useUnsavedChangesGuard(form.isDirty && !form.processing);

    // Fetch last PPPoE remote address when router is selected
    const [lastIpInfo, setLastIpInfo] = useState<{last_remote_address: string | null, suggested_next: string | null} | null>(null);

    useEffect(() => {
        if (!data.router_id) {
            setLastIpInfo(null);
            return;
        }
        fetch(`/api/routers/${data.router_id}/last-pppoe-ip`)
            .then(r => r.json())
            .then(setLastIpInfo)
            .catch(() => setLastIpInfo(null));
    }, [data.router_id]);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (!validateForm(customerUpdateSchema, data, form)) return;
        put(route('customers.update', customer.id));
    };

    const [archiveDialogOpen, setArchiveDialogOpen] = useState(false);
    const isActiveCustomer = customer.status === 'active';

    // Archive = soft-delete (status -> terminated, restorable from the Arsip
    // tab). The server refuses active customers; mirror that so the dialog
    // never promises something the backend will reject.
    // On a lifecycle-guard denial the dialog stays open and shows the reason (+ the
    // superadmin override form); on success the server redirects to the list.
    const handleDelete = () => {
        if (isActiveCustomer || processing || archiveForm.processing) return;
        archiveForm.delete(route('customers.destroy', customer.id), { preserveScroll: true });
    };
    const handleArchiveDialogChange = (open: boolean) => {
        setArchiveDialogOpen(open);
        if (!open) {
            archiveForm.reset();
            archiveForm.clearErrors();
        }
    };

    return (
        <EnterpriseFormLayout
            title={`Edit Customer: ${customer.name}`}
            pageTitle={`Edit ${customer.name}`}
            subtitle="Perbarui data pelanggan dan konfigurasi layanan"
            icon={UserPlus}
            backHref={route('customers.show', customer.id)}
            maxWidth="full"
            breadcrumbs={[
                { label: 'Customers', href: route('customers.index') },
                { label: customer.name, href: route('customers.show', customer.id) },
                { label: 'Edit' },
            ]}
            actions={
                <>
                    <Button
                        type="button"
                        variant="destructive"
                        size="sm"
                        className="h-9"
                        onClick={() => setArchiveDialogOpen(true)}
                        disabled={isActiveCustomer}
                        title={isActiveCustomer ? 'Pelanggan aktif tidak bisa diarsip. Isolir/dismantle dulu.' : undefined}
                    >
                        <Trash2 className="h-4 w-4 mr-2" />
                        Arsipkan
                    </Button>
                    <ConfirmDestructive
                        open={archiveDialogOpen}
                        onOpenChange={handleArchiveDialogChange}
                        title="Arsipkan pelanggan ini?"
                        description={`${customer.name} akan dipindahkan ke arsip dan hilang dari daftar aktif. Status diubah menjadi Berhenti. Data invoice dan pembayaran tetap tersimpan; pelanggan bisa dipulihkan dari tab Arsip. Pelanggan yang masih aktif harus diisolir/dismantle dulu.`}
                        confirmationLabel="Saya paham pelanggan ini akan diarsipkan (bisa dipulihkan dari tab Arsip)."
                        confirmText={archiveGuardMessage && isSuperadmin ? 'Tetap arsipkan (override)' : 'Arsipkan'}
                        onConfirm={handleDelete}
                        isLoading={processing}
                        confirmDisabled={!!archiveGuardMessage && !(isSuperadmin && lifecycleOverrideReady(archiveForm.data.override_reason, archiveForm.data.override))}
                    >
                        {archiveGuardMessage && (
                            <LifecycleGuardNotice
                                message={archiveGuardMessage}
                                isSuperadmin={isSuperadmin}
                                reason={archiveForm.data.override_reason}
                                onReasonChange={(value) => archiveForm.setData('override_reason', value)}
                                checked={archiveForm.data.override}
                                onCheckedChange={(value) => archiveForm.setData('override', value)}
                                reasonError={archiveForm.errors.override_reason as string | undefined}
                                disabled={archiveForm.processing}
                            />
                        )}
                    </ConfirmDestructive>
                </>
            }
        >
            <form onSubmit={submit} className="space-y-8">
                {Object.keys(errors).length > 0 && (
                    <div role="alert" className="bg-destructive/15 text-destructive p-4 rounded-md border border-destructive/20">
                        <p className="font-semibold">Periksa kembali isian berikut:</p>
                        <ul className="list-disc list-inside text-sm mt-1">
                            {Object.entries(errors).map(([field, msg]) => (
                                <li key={field}>{msg}</li>
                            ))}
                        </ul>
                    </div>
                )}
                <div className="grid gap-8 lg:grid-cols-2">
                    {/* Left Column: Personal Information */}
                    <div className="space-y-6">
                        <Card className="border-border bg-card">
                            <CardHeader>
                                <div className="flex items-center gap-2">
                                    <div className="p-2 bg-primary/10 rounded-lg text-primary">
                                        <User className="h-5 w-5" />
                                    </div>
                                    <div>
                                        <CardTitle>Personal Information</CardTitle>
                                        <CardDescription>Identity and contact details</CardDescription>
                                    </div>
                                </div>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="grid grid-cols-3 gap-4">
                                    {/* Internal ID removed */}
                                    <div className="col-span-2 grid gap-2">
                                        <Label htmlFor="name">Full Name <span className="text-destructive">*</span></Label>
                                        <Input
                                            id="name"
                                            value={data.name}
                                            onChange={(e) => setData('name', e.target.value)}
                                            className={errors.name ? 'border-destructive' : ''}
                                            maxLength={255}
                                            required
                                        />
                                        {errors.name && <p className="text-sm text-destructive">{errors.name}</p>}
                                    </div>
                                </div>

                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div className="grid gap-2">
                                        <Label htmlFor="phone">No. HP</Label>
                                        <Input
                                            id="phone"
                                            type="tel"
                                            inputMode="tel"
                                            autoComplete="tel"
                                            value={data.phone}
                                            onChange={(e) => setData('phone', e.target.value)}
                                            maxLength={20}
                                        />
                                        {errors.phone && <p className="text-sm text-destructive">{errors.phone}</p>}
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="nik">NIK (Identity)</Label>
                                        <Input
                                            id="nik"
                                            value={data.nik}
                                            onChange={(e) => setData('nik', e.target.value)}
                                            maxLength={20}
                                            {...fieldErrorProps('nik', errors.nik)}
                                        />
                                        <FieldError id="nik" message={errors.nik} />
                                    </div>
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="address">Installation Address <span className="text-destructive">*</span></Label>
                                    <Textarea
                                        id="address"
                                        value={data.address}
                                        onChange={(e) => setData('address', e.target.value)}
                                        className={`min-h-[100px] bg-background/50 ${errors.address ? 'border-destructive' : ''}`}
                                        required
                                    />
                                    {errors.address && <p className="text-sm text-destructive">{errors.address}</p>}
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="ktp_photo">KTP Photo</Label>
                                    {customer.ktp_photo_url && (
                                        <div className="mb-2 border rounded-lg p-2 bg-muted/30">
                                            <p className="text-xs text-muted-foreground mb-2">Current KTP:</p>
                                            <img
                                                src={customer.ktp_photo_url}
                                                alt="Current KTP"
                                                className="max-w-[200px] rounded border"
                                            />
                                        </div>
                                    )}
                                    <Input
                                        id="ktp_photo"
                                        type="file"
                                        accept="image/jpeg,image/png,image/jpg"
                                        onChange={(e) => setData('ktp_photo', e.target.files?.[0] || null)}
                                        className="cursor-pointer"
                                    />
                                    {errors.ktp_photo && <p className="text-sm text-destructive">{errors.ktp_photo}</p>}
                                    <p className="text-xs text-muted-foreground">
                                        {customer.ktp_photo_url ? 'Upload new to replace' : 'Max 2MB - JPEG, PNG, JPG'}
                                    </p>
                                </div>
                            </CardContent>
                        </Card>

                        {/* Geo Location */}
                        <Card className="border-border bg-card">
                            <CardHeader>
                                <div className="flex items-center gap-2">
                                    <div className="p-2 bg-status-up-bg rounded-lg text-status-up">
                                        <MapPin className="h-5 w-5" />
                                    </div>
                                    <div>
                                        <CardTitle>Geolocation</CardTitle>
                                        <CardDescription>GPS Coordinates for mapping</CardDescription>
                                    </div>
                                </div>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="rounded-md overflow-hidden border border-border">
                                    <MapPicker
                                        initialLat={Number(data.geo_lat) || -6.200000}
                                        initialLong={Number(data.geo_long) || 106.816666}
                                        onLocationSelect={(lat: number, lng: number) => {
                                            setData((prev) => ({
                                                ...prev,
                                                geo_lat: lat.toFixed(6),
                                                geo_long: lng.toFixed(6)
                                            }));
                                        }}
                                    />
                                </div>
                                <div className="grid grid-cols-2 gap-4">
                                    <div className="grid gap-2">
                                        <Label htmlFor="lat">Latitude</Label>
                                        <Input
                                            id="lat"
                                            value={data.geo_lat}
                                            onChange={(e) => setData('geo_lat', e.target.value)}
                                            {...fieldErrorProps('lat', errors.geo_lat)}
                                        />
                                        <FieldError id="lat" message={errors.geo_lat} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="long">Longitude</Label>
                                        <Input
                                            id="long"
                                            value={data.geo_long}
                                            onChange={(e) => setData('geo_long', e.target.value)}
                                            {...fieldErrorProps('long', errors.geo_long)}
                                        />
                                        <FieldError id="long" message={errors.geo_long} />
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    </div>

                    {/* Right Column: Network Information */}
                    <div className="space-y-6">
                        <Card className="border-border bg-card h-full">
                            <CardHeader>
                                <div className="flex items-center gap-2">
                                    <div className="p-2 bg-status-info-bg rounded-lg text-status-info">
                                        <Network className="h-5 w-5" />
                                    </div>
                                    <div>
                                        <CardTitle>Service Configuration</CardTitle>
                                        <CardDescription>Package and Authentication</CardDescription>
                                    </div>
                                </div>
                            </CardHeader>
                            <CardContent className="space-y-6">
                                <div className="grid gap-2">
                                    <Label htmlFor="area_id">Area</Label>
                                    <Select
                                        value={data.area_id}
                                        onValueChange={(val) => setData('area_id', val)}
                                    >
                                        <SelectTrigger className="bg-background/50">
                                            <SelectValue placeholder="Select Area" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {areas.map((area) => (
                                                <SelectItem key={area.id} value={String(area.id)}>
                                                    {area.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {errors.area_id && <p className="text-sm text-destructive">{errors.area_id}</p>}
                                </div>

                                {/* Router FIRST — determines packages */}
                                <div className="grid gap-2">
                                    <Label htmlFor="router_id">Mikrotik Router</Label>
                                    <Select
                                        value={data.router_id || 'manual'}
                                        onValueChange={(val) => {
                                            setData('router_id', val === 'manual' ? '' : val);
                                            setData('package_id', '');
                                        }}
                                    >
                                        <SelectTrigger className="bg-background/50">
                                            <SelectValue placeholder="Select Router" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="manual">-- None (Manual Mode) --</SelectItem>
                                            {routers.map((router) => (
                                                <SelectItem key={router.id} value={String(router.id)}>
                                                    {router.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {errors.router_id && <p className="text-sm text-destructive">{errors.router_id}</p>}
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="package">Subscription Package <span className="text-destructive">*</span></Label>
                                    {data.router_id ? (
                                        <Select
                                            value={data.package_id}
                                            onValueChange={(val) => setData('package_id', val)}
                                        >
                                            <SelectTrigger className="bg-background/50">
                                                <SelectValue placeholder="Select a package" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {packages
                                                    .filter(pkg => String(pkg.router_id) === String(data.router_id))
                                                    .map((pkg) => (
                                                        <SelectItem key={pkg.id} value={String(pkg.id)}>
                                                            <span className="font-medium">{pkg.name}</span>
                                                            {pkg.mikrotik_profile && <span className="text-muted-foreground ml-1">[{pkg.mikrotik_profile}]</span>}
                                                            <span className="text-muted-foreground ml-2">
                                                                ({formatIdr(pkg.price)})
                                                            </span>
                                                        </SelectItem>
                                                ))}
                                                {packages.filter(pkg => String(pkg.router_id) === String(data.router_id)).length === 0 && (
                                                    <div className="p-2 text-sm text-muted-foreground text-center">
                                                        No packages for this router. Sync profiles first.
                                                    </div>
                                                )}
                                            </SelectContent>
                                        </Select>
                                    ) : (
                                        <Select
                                            value={data.package_id}
                                            onValueChange={(val) => setData('package_id', val)}
                                        >
                                            <SelectTrigger className="bg-background/50">
                                                <SelectValue placeholder="Select a router first" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {packages.filter(pkg => !pkg.router_id || String(pkg.router_id) === String(data.router_id)).map((pkg) => (
                                                    <SelectItem key={pkg.id} value={String(pkg.id)}>
                                                        <span className="font-medium">{pkg.name}</span>
                                                        <span className="text-muted-foreground ml-2">
                                                            ({formatIdr(pkg.price)})
                                                        </span>
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    )}
                                    {errors.package_id && <p className="text-sm text-destructive">{errors.package_id}</p>}
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="status">Account Status</Label>
                                    <Select
                                        value={data.status}
                                        onValueChange={(val: any) => setData('status', val)}
                                    >
                                        <SelectTrigger className="bg-background/50">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="pending_installation">{CUSTOMER_STATUS_LABELS.pending_installation}</SelectItem>
                                            <SelectItem value="active">{CUSTOMER_STATUS_LABELS.active}</SelectItem>
                                            <SelectItem value="isolated">{CUSTOMER_STATUS_LABELS.isolated}</SelectItem>
                                            <SelectItem value="terminated">{CUSTOMER_STATUS_LABELS.terminated}</SelectItem>
                                        </SelectContent>
                                    </Select>
                                    {errors.status && <p className="text-sm text-destructive">{errors.status}</p>}
                                    {updateGuardMessage && (
                                        <>
                                            <LifecycleGuardNotice
                                                message={updateGuardMessage}
                                                isSuperadmin={isSuperadmin}
                                                reason={data.lifecycle_override_reason}
                                                onReasonChange={(value) => setData('lifecycle_override_reason', value)}
                                                checked={data.lifecycle_override}
                                                onCheckedChange={(value) => setData('lifecycle_override', value)}
                                                reasonError={(errors as Record<string, string | undefined>).lifecycle_override_reason}
                                                disabled={processing}
                                            />
                                            {isSuperadmin && (
                                                <Button
                                                    type="button"
                                                    variant="destructive"
                                                    size="sm"
                                                    disabled={processing || !lifecycleOverrideReady(data.lifecycle_override_reason, data.lifecycle_override)}
                                                    onClick={() => put(route('customers.update', customer.id))}
                                                >
                                                    Tetap terminate (override)
                                                </Button>
                                            )}
                                        </>
                                    )}
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="due_day">Tanggal Jatuh Tempo (1–31)</Label>
                                    <Input
                                        id="due_day"
                                        type="number"
                                        min={1}
                                        max={31}
                                        value={data.due_day}
                                        onChange={(event) => setData('due_day', event.target.value)}
                                        placeholder="contoh: 8"
                                        className="bg-background/50"
                                    />
                                    <p className="text-xs text-muted-foreground">Dipakai saat membuat invoice baru: tanggal jatuh tempo tiap bulan.</p>
                                    {errors.due_day && <p className="text-sm text-destructive">{errors.due_day}</p>}
                                </div>

                                <div className="border-t border-border/50 my-4"></div>

                                <div className="space-y-4">
                                    <div className="flex items-center gap-2 mb-4">
                                        <Shield className="h-4 w-4 text-status-degraded" />
                                        <span className="font-medium text-sm text-foreground">PPPoE Credentials</span>
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="pppoe_user">Username</Label>
                                        <Input
                                            id="pppoe_user"
                                            value={data.pppoe_user}
                                            disabled={!canEditPppoeUsername}
                                            onChange={(e) => setData('pppoe_user', e.target.value)}
                                            className={canEditPppoeUsername ? 'font-mono' : 'font-mono bg-muted text-muted-foreground cursor-not-allowed'}
                                            {...fieldErrorProps('pppoe_user', errors.pppoe_user)}
                                        />
                                        <FieldError id="pppoe_user" message={errors.pppoe_user} />
                                        <p className="text-[10px] text-muted-foreground">
                                            {canEditPppoeUsername
                                                ? 'Global Admin dapat mengubah username. Username harus unik dan akan disinkronkan ke data layanan.'
                                                : 'PPPoE Username cannot be changed. Create a new customer if needed.'}
                                        </p>
                                    </div>

                                    <FieldError id="pppoe_password" message={(errors as Record<string, string | undefined>).pppoe_password} />
                                    <p className="text-xs text-muted-foreground">
                                        Password PPPoE dibaca dari MikroTik. Perubahan password harus melalui workflow router terverifikasi.
                                    </p>

                                    <div className="border-t border-border/50 my-4"></div>

                                    <div className="flex items-center gap-2 mb-4">
                                        <Network className="h-4 w-4 text-status-info" />
                                        <span className="font-medium text-sm text-foreground">PPPoE IP Address</span>
                                    </div>

                                    {lastIpInfo && lastIpInfo.suggested_next && (
                                        <div className="bg-status-info-bg border border-status-info/20 rounded-md p-3 text-sm">
                                            <span className="text-status-info font-medium">IP terakhir:</span>{' '}
                                            <span className="font-mono text-foreground">{lastIpInfo.last_remote_address}</span>
                                            {' → '}
                                            <span className="text-status-info font-medium">Saran berikutnya:</span>{' '}
                                            <button
                                                type="button"
                                                className="font-mono text-status-info underline hover:text-status-info/80"
                                                onClick={() => setData('pppoe_remote_address' as any, lastIpInfo.suggested_next || '')}
                                            >
                                                {lastIpInfo.suggested_next}
                                            </button>
                                        </div>
                                    )}

                                    <div className="grid grid-cols-2 gap-4">
                                        <div className="grid gap-2">
                                            <Label htmlFor="pppoe_local_address">Local Address <span className="text-muted-foreground">(gateway)</span></Label>
                                            <Input
                                                id="pppoe_local_address"
                                                value={data.pppoe_local_address || ''}
                                                onChange={(e) => setData('pppoe_local_address' as any, e.target.value)}
                                                placeholder="e.g. 192.0.2.1"
                                                className="font-mono"
                                                {...fieldErrorProps('pppoe_local_address', (errors as Record<string, string | undefined>).pppoe_local_address)}
                                            />
                                            <FieldError id="pppoe_local_address" message={(errors as Record<string, string | undefined>).pppoe_local_address} />
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="pppoe_remote_address">Remote Address <span className="text-muted-foreground">(IP customer)</span></Label>
                                            <Input
                                                id="pppoe_remote_address"
                                                value={data.pppoe_remote_address || ''}
                                                onChange={(e) => setData('pppoe_remote_address' as any, e.target.value)}
                                                placeholder={lastIpInfo?.suggested_next || 'e.g. 192.0.2.2'}
                                                className="font-mono"
                                                {...fieldErrorProps('pppoe_remote_address', (errors as Record<string, string | undefined>).pppoe_remote_address)}
                                            />
                                            <FieldError id="pppoe_remote_address" message={(errors as Record<string, string | undefined>).pppoe_remote_address} />
                                        </div>
                                    </div>

                                    <p className="text-xs text-muted-foreground mt-1">
                                        Kosongkan = IP otomatis dari pool MikroTik. Isi = static IP via RADIUS Framed-IP-Address.
                                    </p>
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                </div>

                <div className="flex justify-end gap-4 mt-8">
                    <Link href={route('customers.index')}>
                        <Button variant="outline" type="button">Cancel</Button>
                    </Link>
                    <Button type="submit" disabled={processing} className="min-w-[150px]">
                        {processing ? 'Saving...' : 'Save Changes'}
                        {!processing && <Save className="ml-2 h-4 w-4" />}
                    </Button>
                </div>
            </form>
        </EnterpriseFormLayout>
    );
}
