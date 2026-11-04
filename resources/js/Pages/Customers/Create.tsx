import { EnterpriseFormLayout } from '@/Components/enterprise/EnterpriseFormLayout';
import { Link, useForm } from '@inertiajs/react'; // Cleaned imports
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Textarea } from '@/Components/ui/textarea'; // Assuming you have this
import { Save, User, UserPlus, MapPin, Shield, Network } from 'lucide-react';
import MapPicker from '@/Components/MapPicker';
import { FormEventHandler, useEffect, useState } from 'react';
import { nullableId, optionalImage, optionalNumberInRange, requiredId, requiredString, validateForm } from '@/lib/validation';
import { formatIdr } from '@/lib/format';
import { useUnsavedChangesGuard } from '@/hooks/useUnsavedChangesGuard';
import { z } from 'zod';
import { CUSTOMER_STATUS_LABELS } from '@/lib/customerLabels';

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

/** Isi awal dari "secret belum terhubung" di halaman router (?staged=). */
interface Prefill {
    router_staged_customer_id: number;
    router_id: number;
    router_name: string;
    pppoe_user: string;
    profile: string;
    package_id: number | null;
    package_hint: string | null;
    /** "Mirip pelanggan #id ..." - saran saja, tidak memblokir. */
    lookalike_hint: string | null;
    status: 'active' | 'pending_installation';
}

interface Props {
    packages: Package[];
    areas: Area[];
    olts: Olt[];
    routers: Router[];
    prefill?: Prefill | null;
}

const customerCreateSchema = z.object({
    name: requiredString('Customer name'),
    address: z.string().trim().min(1, 'Installation address is required.'),
    phone: z.string().max(20, 'Phone may not be greater than 20 characters.').optional(),
    nik: z.string().max(20, 'NIK may not be greater than 20 characters.').optional(),
    pppoe_user: requiredString('PPPoE username'),
    pppoe_local_address: z.string().max(255).optional(),
    pppoe_remote_address: z.string().max(255).optional(),
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
    geo_lat: optionalNumberInRange('Latitude', -90, 90),
    geo_long: optionalNumberInRange('Longitude', -180, 180),
    ktp_photo: optionalImage('KTP photo'),
});

export default function Create({ packages, areas, olts, routers, prefill = null }: Props) {
    const form = useForm({
        name: '',
        // internal_id removed
        address: '',
        phone: '',
        nik: '',
        pppoe_user: prefill?.pppoe_user ?? '',
        pppoe_local_address: '',
        pppoe_remote_address: '',
        package_id: prefill?.package_id ? String(prefill.package_id) : '',
        area_id: '',
        router_id: prefill ? String(prefill.router_id) : '',
        router_staged_customer_id: prefill ? String(prefill.router_staged_customer_id) : '',
        olt_id: '',
        onu_serial: '',
        olt_port_label: '',
        olt_status: '',
        onu_rx_power_dbm: '',
        onu_tx_power_dbm: '',
        fiber_distance_m: '',
        status: (prefill?.status ?? 'pending_installation') as string,
        due_day: '',
        geo_lat: '',
        geo_long: '',
        ktp_photo: null as File | null,
    });
    const { data, setData, post, processing, errors } = form;
    useUnsavedChangesGuard(form.isDirty && !form.processing);

    // Fetch last PPPoE remote address when router is selected
    const [lastIpInfo, setLastIpInfo] = useState<{last_remote_address: string | null, suggested_next: string | null, profiles_with_ip: {name: string, local_address: string, remote_address: string}[]} | null>(null);

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
        if (!validateForm(customerCreateSchema, data, form)) return;
        post(route('customers.store'));
    };

    // Auto-generate PPPoE user based on name (Optional helper)
    const handleNameChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const val = e.target.value;
        setData('name', val);
        // Simple auto-slug for pppoe if empty
        if (!data.pppoe_user) {
            setData((data) => ({
                ...data,
                name: val,
                pppoe_user: val.toLowerCase().replace(/\s+/g, '')
            }));
        } else {
            setData('name', val);
        }
    };

    return (
        <EnterpriseFormLayout
            title="Register New Customer"
            pageTitle="Create Customer"
            subtitle="Daftarkan pelanggan baru ke sistem billing"
            icon={UserPlus}
            backHref={route('customers.index')}
            maxWidth="full"
            breadcrumbs={[
                { label: 'Customers', href: route('customers.index') },
                { label: 'Create' },
            ]}
        >
            <form onSubmit={submit} className="space-y-8">
                {prefill && (
                    <div className="rounded-md border border-status-info/20 bg-status-info-bg p-4 text-sm text-status-info">
                        Data dari secret di router {prefill.router_name}. Password dan profil di MikroTik tidak diubah.
                    </div>
                )}
                {prefill?.lookalike_hint && (
                    <div role="note" className="rounded-md border border-status-degraded/30 bg-status-degraded-bg p-4 text-sm text-status-degraded">
                        {prefill.lookalike_hint}
                    </div>
                )}
                {Object.keys(errors).length > 0 && (
                    <div className="bg-destructive/15 text-destructive p-4 rounded-md border border-destructive/20">
                        <p className="font-semibold">Please fix the following errors:</p>
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
                                            onChange={handleNameChange}
                                            placeholder="e.g. John Doe"
                                            className={errors.name ? 'border-destructive' : ''}
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
                                            placeholder="0812..."
                                        />
                                        {errors.phone && <p className="text-sm text-destructive">{errors.phone}</p>}
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="nik">NIK</Label>
                                        <Input
                                            id="nik"
                                            inputMode="numeric"
                                            maxLength={16}
                                            value={data.nik}
                                            onChange={(e) => setData('nik', e.target.value)}
                                            placeholder="16 digit"
                                        />
                                        {errors.nik && <p className="text-sm text-destructive">{errors.nik}</p>}
                                    </div>
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="area_id">Area / Location</Label>
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

                                <div className="grid gap-2">
                                    <Label htmlFor="address">Installation Address <span className="text-destructive">*</span></Label>
                                    <Textarea
                                        id="address"
                                        value={data.address}
                                        onChange={(e) => setData('address', e.target.value)}
                                        placeholder="Full street address..."
                                        className={`min-h-[100px] bg-background/50 ${errors.address ? 'border-destructive' : ''}`}
                                        required
                                    />
                                    {errors.address && <p className="text-sm text-destructive">{errors.address}</p>}
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="ktp_photo">KTP Photo</Label>
                                    <Input
                                        id="ktp_photo"
                                        type="file"
                                        accept="image/jpeg,image/png,image/jpg"
                                        onChange={(e) => setData('ktp_photo', e.target.files?.[0] || null)}
                                        className="cursor-pointer"
                                    />
                                    {errors.ktp_photo && <p className="text-sm text-destructive">{errors.ktp_photo}</p>}
                                    <p className="text-xs text-muted-foreground">Max 2MB - JPEG, PNG, JPG</p>
                                </div>
                            </CardContent>
                        </Card>

                        {/* Geo Location (Simplified) */}
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
                                            placeholder="-6.200000"
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="long">Longitude</Label>
                                        <Input
                                            id="long"
                                            value={data.geo_long}
                                            onChange={(e) => setData('geo_long', e.target.value)}
                                            placeholder="106.816666"
                                        />
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
                                {/* Router selection FIRST — determines available packages */}
                                <div className="grid gap-2">
                                    <Label htmlFor="router_id">Mikrotik Router <span className="text-destructive">*</span></Label>
                                    <Select
                                        value={data.router_id || 'manual'}
                                        disabled={prefill !== null}
                                        onValueChange={(val) => {
                                            setData('router_id', val === 'manual' ? '' : val);
                                            setData('package_id', ''); // Reset package when router changes
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
                                    {prefill?.package_hint && <p className="text-xs text-muted-foreground">{prefill.package_hint}</p>}
                                    {errors.package_id && <p className="text-sm text-destructive">{errors.package_id}</p>}
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="status">Account Status</Label>
                                    <Select
                                        value={data.status}
                                        onValueChange={(val) => setData('status', val)}
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
                                    {prefill && <p className="text-xs text-muted-foreground">Aktif = pelanggan mulai ditagih.</p>}
                                    {errors.status && <p className="text-sm text-destructive">{errors.status}</p>}
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
                                    <p className="text-xs text-muted-foreground">Tanggal jatuh tempo tiap bulan (default: tanggal bergabung).</p>
                                    {errors.due_day && <p className="text-sm text-destructive">{errors.due_day}</p>}
                                </div>



                                <div className="space-y-4">
                                    <div className="flex items-center gap-2 mb-4">
                                        <Shield className="h-4 w-4 text-status-degraded" />
                                        <span className="font-medium text-sm text-foreground">PPPoE Credentials</span>
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="pppoe_user">Username <span className="text-destructive">*</span></Label>
                                        <Input
                                            id="pppoe_user"
                                            value={data.pppoe_user}
                                            onChange={(e) => setData('pppoe_user', e.target.value)}
                                            readOnly={prefill !== null}
                                            placeholder="john.doe"
                                            className="font-mono"
                                            required
                                        />
                                        {errors.pppoe_user && <p className="text-sm text-destructive">{errors.pppoe_user}</p>}
                                    </div>

                                    <p className="text-xs text-muted-foreground">
                                        Password PPPoE dibuat dan diverifikasi melalui MikroTik. Form pelanggan tidak menyimpan password secara langsung.
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
                                                onClick={() => setData('pppoe_remote_address', lastIpInfo.suggested_next || '')}
                                            >
                                                {lastIpInfo.suggested_next}
                                            </button>
                                        </div>
                                    )}

                                    {lastIpInfo && lastIpInfo.profiles_with_ip && lastIpInfo.profiles_with_ip.length > 0 && (
                                        <div className="text-xs text-muted-foreground mt-1">
                                            Profile local-address: {lastIpInfo.profiles_with_ip.map(p => `${p.name}(${p.local_address})`).join(', ')}
                                        </div>
                                    )}

                                    <div className="grid grid-cols-2 gap-4">
                                        <div className="grid gap-2">
                                            <Label htmlFor="pppoe_local_address">Local Address <span className="text-muted-foreground">(gateway)</span></Label>
                                            <Input
                                                id="pppoe_local_address"
                                                value={data.pppoe_local_address}
                                                onChange={(e) => setData('pppoe_local_address', e.target.value)}
                                                placeholder="e.g. 192.0.2.1"
                                                className="font-mono"
                                            />
                                            {errors.pppoe_local_address && <p className="text-sm text-destructive">{errors.pppoe_local_address}</p>}
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="pppoe_remote_address">Remote Address <span className="text-muted-foreground">(IP customer)</span></Label>
                                            <Input
                                                id="pppoe_remote_address"
                                                value={data.pppoe_remote_address}
                                                onChange={(e) => setData('pppoe_remote_address', e.target.value)}
                                                placeholder={lastIpInfo?.suggested_next || 'e.g. 192.0.2.2'}
                                                className="font-mono"
                                            />
                                            {errors.pppoe_remote_address && <p className="text-sm text-destructive">{errors.pppoe_remote_address}</p>}
                                        </div>
                                    </div>

                                    <p className="text-xs text-muted-foreground mt-1">
                                        Kosongkan = IP otomatis dari pool MikroTik. Isi = static IP via RADIUS Framed-IP-Address.
                                    </p>
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                </div >

                <div className="flex justify-end gap-4 mt-8">
                    <Link href={route('customers.index')}>
                        <Button variant="outline" type="button">Cancel</Button>
                    </Link>
                    <Button type="submit" disabled={processing} className="min-w-[150px]">
                        {processing ? 'Saving...' : 'Create Customer'}
                        {!processing && <Save className="ml-2 h-4 w-4" />}
                    </Button>
                </div>
            </form>
        </EnterpriseFormLayout>
    );
}
