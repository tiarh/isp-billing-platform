import { EnterpriseFormLayout } from '@/Components/enterprise/EnterpriseFormLayout';
import { useForm } from '@inertiajs/react';
import { TextField } from '@/Components/ResourceFields';
import { ResourceFormShell } from '@/Components/ResourceFormShell';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { nullableId, optionalIpAddress, requiredString, validateForm } from '@/lib/validation';
import { z } from 'zod';
import { Boxes, Cable, Globe, Info, KeyRound, LockKeyhole, Monitor, RadioTower, Server } from 'lucide-react';

interface AreaOption { id: number; name: string; }
interface RouterOption { id: number; name: string; }

interface Props {
    olt: {
        id: number;
        name: string;
        code: string;
        vendor: string | null;
        area_id: number | null;
        router_id: number | null;
        management_ip: string | null;
        management_protocol: string | null;
        management_port: number | null;
        username: string | null;
        password: string | null;
        snmp_community: null;
        snmp_community_configured: boolean;
        location: string | null;
        notes: string | null;
    };
    areas: AreaOption[];
    routers: RouterOption[];
    /** Example values for placeholders (config site.ui_examples). */
    ui_examples?: { router_public_ip?: string };
}

const oltSchema = z.object({
    name: requiredString('Nama OLT'),
    code: requiredString('Kode OLT'),
    vendor: z.enum(['zte_c300', 'hioso', 'huawei_ma5608t']).optional(),
    area_id: nullableId('Area'),
    router_id: nullableId('Router'),
    management_ip: optionalIpAddress('IP Management'),
    management_protocol: z.enum(['ssh', 'telnet', 'snmp', 'http', 'https']).optional().or(z.literal('')),
    management_port: z.string().optional(),
    username: z.string().max(255).optional(),
    password: z.string().max(255).optional(),
    snmp_community: z.string().max(255).optional(),
    location: z.string().max(255).optional(),
    notes: z.string().optional(),
});

const defaultPortForProtocol = (protocol: string) => {
    if (protocol === 'telnet') return '23';
    if (protocol === 'snmp') return '161';
    if (protocol === 'http') return '80';
    if (protocol === 'https') return '443';
    return '22';
};

const vendorDescriptions: Record<string, string> = {
    huawei_ma5608t: 'Huawei MA5608T — inventory ONU menggunakan SSH read-only; operasi tulis ONU tetap diblokir.',
    zte_c300: 'OLT ZTE C300/C320 — umumnya menggunakan SSH/Telnet untuk konfigurasi CLI dan SNMP untuk monitoring.',
    hioso: 'OLT Hioso — umumnya menggunakan HTTP/HTTPS untuk akses web. CLI Telnet/SSH bisa dipakai jika firmware mendukung.',
};

const protocolDescriptions: Record<string, string> = {
    ssh: 'Akses CLI terenkripsi — paling aman untuk ZTE C300',
    telnet: 'Akses CLI tanpa enkripsi — cepat tapi kurang aman',
    http: 'Akses web HTTP — untuk Hioso (tanpa enkripsi)',
    https: 'Akses web HTTPS — untuk Hioso (terenkripsi)',
    snmp: 'Monitoring via SNMP — hanya untuk baca data, bukan konfigurasi',
};

export default function Edit({ olt, areas, routers, ui_examples: uiExamples }: Props) {
    const examplePublicIp = uiExamples?.router_public_ip ?? '';
    const form = useForm({
        name: olt.name,
        code: olt.code,
        vendor: olt.vendor || 'zte_c300',
        area_id: olt.area_id ? String(olt.area_id) : '',
        router_id: olt.router_id ? String(olt.router_id) : '',
        management_ip: olt.management_ip || '',
        management_protocol: olt.management_protocol || 'ssh',
        management_port: olt.management_port ? String(olt.management_port) : '',
        username: olt.username || '',
        password: olt.password || '',
        snmp_community: '',
        location: olt.location || '',
        notes: olt.notes || '',
    });
    const { data, setData, put, processing, errors } = form;
    const isWebAccess = ['http', 'https'].includes(data.management_protocol);
    const isSnmpAccess = data.management_protocol === 'snmp';

    const setProtocol = (protocol: string) => {
        setData((current) => ({
            ...current,
            management_protocol: protocol,
            management_port: defaultPortForProtocol(protocol),
        }));
    };

    const setVendor = (vendor: string) => {
        setData((current) => ({
            ...current,
            vendor,
            management_protocol: vendor === 'hioso' && !['http', 'https'].includes(current.management_protocol) ? 'http' : current.management_protocol,
            management_port: vendor === 'hioso' && !['http', 'https'].includes(current.management_protocol) ? '80' : current.management_port,
        }));
    };

    const submit = (event: React.FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (!validateForm(oltSchema, data, form)) return;
        put(route('olts.update', olt.id));
    };

    return (
        <EnterpriseFormLayout
            title={`Edit ${olt.name}`}
            pageTitle={`Edit ${olt.name}`}
            subtitle="Perbarui data konfigurasi OLT"
            icon={Boxes}
            backHref={route('olts.show', olt.id)}
            breadcrumbs={[
                { label: 'Manajemen OLT', href: route('olts.index') },
                { label: `Edit ${olt.name}` },
            ]}
        >
            <ResourceFormShell
                title="Informasi OLT"
                description="Perbarui data konfigurasi OLT. Pastikan informasi koneksi benar agar sistem bisa mengakses perangkat."
                onSubmit={submit}
                submitLabel="Simpan Perubahan"
                processing={processing}
                cancelHref={route('olts.show', olt.id)}
            >
                {/* Nama & Kode */}
                <div className="grid gap-6 md:grid-cols-2">
                    <TextField
                        id="name"
                        label="Nama OLT"
                        value={data.name}
                        onChange={(value) => setData('name', value)}
                        placeholder="contoh: OLT Mangliawan"
                        help="Nama identifikasi OLT, biasanya berdasarkan lokasi"
                        required
                        autoFocus
                        maxLength={255}
                        error={errors.name}
                    />
                    <TextField
                        id="code"
                        label="Kode OLT"
                        value={data.code}
                        onChange={(value) => setData('code', value)}
                        placeholder="contoh: OLT-MGL-01"
                        help="Kode singkat unik untuk OLT ini"
                        required
                        maxLength={255}
                        error={errors.code}
                    />
                </div>

                {/* Vendor */}
                <div className="space-y-2">
                    <Label htmlFor="vendor">Vendor / Merek OLT</Label>
                    <Select value={data.vendor || 'zte_c300'} onValueChange={setVendor}>
                        <SelectTrigger id="vendor">
                            <SelectValue placeholder="Pilih vendor" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="zte_c300">
                                <span className="flex items-center gap-2">
                                    <Server className="h-4 w-4 text-muted-foreground" />
                                    ZTE C300/C320
                                </span>
                            </SelectItem>
                            <SelectItem value="hioso">
                                <span className="flex items-center gap-2">
                                    <Globe className="h-4 w-4 text-muted-foreground" />
                                    Hioso
                                </span>
                            </SelectItem>
                            <SelectItem value="huawei_ma5608t">
                                <span className="flex items-center gap-2">
                                    <RadioTower className="h-4 w-4 text-muted-foreground" />
                                    Huawei MA5608T
                                </span>
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    {vendorDescriptions[data.vendor || 'zte_c300'] && (
                        <div className="flex items-start gap-2 rounded-lg border border-status-info/30 bg-status-info-bg p-3 text-xs text-status-info">
                            <Info className="h-4 w-4 mt-0.5 shrink-0" />
                            <span>{vendorDescriptions[data.vendor || 'zte_c300']}</span>
                        </div>
                    )}
                    {errors.vendor && <p className="text-sm text-destructive">{errors.vendor}</p>}
                </div>

                {/* Area & Router */}
                <div className="grid gap-6 md:grid-cols-2">
                    <div className="space-y-2">
                        <Label htmlFor="area_id">Area / Wilayah</Label>
                        <Select value={data.area_id || '__none__'} onValueChange={(value) => setData('area_id', value === '__none__' ? '' : value)}>
                            <SelectTrigger id="area_id">
                                <SelectValue placeholder="Pilih area" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="__none__">Tanpa area</SelectItem>
                                {areas.map((area) => (
                                    <SelectItem key={area.id} value={String(area.id)}>{area.name}</SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <p className="text-xs text-muted-foreground">Area lokasi OLT berada (untuk pengelompokan)</p>
                        {errors.area_id && <p className="text-sm text-destructive">{errors.area_id}</p>}
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="router_id">Router Terhubung</Label>
                        <Select value={data.router_id || '__none__'} onValueChange={(value) => setData('router_id', value === '__none__' ? '' : value)}>
                            <SelectTrigger id="router_id">
                                <SelectValue placeholder="Pilih router" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="__none__">Tanpa router</SelectItem>
                                {routers.map((routerOption) => (
                                    <SelectItem key={routerOption.id} value={String(routerOption.id)}>{routerOption.name}</SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <p className="text-xs text-muted-foreground">Router yang terhubung ke OLT ini (Mikrotik, dll)</p>
                        {errors.router_id && <p className="text-sm text-destructive">{errors.router_id}</p>}
                    </div>
                </div>

                {/* IP & Koneksi */}
                <div className="space-y-4">
                    <h3 className="flex items-center gap-2 text-sm font-semibold text-foreground">
                        <Cable className="h-4 w-4" aria-hidden="true" />
                        Pengaturan Koneksi
                    </h3>

                    <TextField
                        id="management_ip"
                        label={isWebAccess ? 'IP Publik / Host Web OLT' : 'IP Management OLT'}
                        value={data.management_ip}
                        onChange={(value) => setData('management_ip', value)}
                        placeholder={isWebAccess ? `contoh: ${examplePublicIp}` : 'contoh: 192.0.2.10'}
                        help={isWebAccess
                            ? `Untuk NAT Hioso, isi IP publik router + port dstnat, misalnya ${examplePublicIp} port 8355`
                            : 'IP address yang digunakan untuk mengakses OLT dari jaringan management'}
                        error={errors.management_ip}
                    />

                    <div className="grid gap-6 md:grid-cols-2">
                        <div className="space-y-2">
                            <Label htmlFor="management_protocol">Protokol Akses</Label>
                            <Select value={data.management_protocol || 'ssh'} onValueChange={setProtocol}>
                                <SelectTrigger id="management_protocol">
                                    <SelectValue placeholder="Pilih protokol" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="ssh">
                                        <span className="flex items-center gap-2">
                                            <KeyRound className="h-4 w-4" aria-hidden="true" />
                                            SSH (CLI Terenkripsi)
                                        </span>
                                    </SelectItem>
                                    <SelectItem value="telnet">
                                        <span className="flex items-center gap-2">
                                            <Monitor className="h-4 w-4" aria-hidden="true" />
                                            Telnet (CLI)
                                        </span>
                                    </SelectItem>
                                    <SelectItem value="http">
                                        <span className="flex items-center gap-2">
                                            <Globe className="h-4 w-4" aria-hidden="true" />
                                            HTTP (Web)
                                        </span>
                                    </SelectItem>
                                    <SelectItem value="https">
                                        <span className="flex items-center gap-2">
                                            <LockKeyhole className="h-4 w-4" aria-hidden="true" />
                                            HTTPS (Web Aman)
                                        </span>
                                    </SelectItem>
                                    <SelectItem value="snmp">
                                        <span className="flex items-center gap-2">
                                            <RadioTower className="h-4 w-4" aria-hidden="true" />
                                            SNMP (Monitoring)
                                        </span>
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            {protocolDescriptions[data.management_protocol || 'ssh'] && (
                                <p className="text-xs text-muted-foreground">
                                    {protocolDescriptions[data.management_protocol || 'ssh']}
                                </p>
                            )}
                            {errors.management_protocol && <p className="text-sm text-destructive">{errors.management_protocol}</p>}
                        </div>
                        <TextField
                            id="management_port"
                            label="Port"
                            type="number"
                            value={data.management_port}
                            onChange={(value) => setData('management_port', value)}
                            placeholder="contoh: 22"
                            help="SSH=22, Telnet=23, HTTP=80, HTTPS=443, SNMP=161. Untuk NAT bisa pakai port custom."
                            error={errors.management_port}
                        />
                    </div>
                </div>

                {/* Username & Password */}
                {!isSnmpAccess && (
                    <div className="grid gap-6 md:grid-cols-2">
                        <TextField
                            id="username"
                            label={isWebAccess ? 'Username Web' : 'Username CLI'}
                            value={data.username}
                            onChange={(value) => setData('username', value)}
                            placeholder="contoh: admin"
                            help={isWebAccess ? 'Username login web interface OLT' : 'Username login SSH/Telnet ke OLT'}
                            error={errors.username}
                        />
                        <TextField
                            id="password"
                            label={isWebAccess ? 'Password Web' : 'Password CLI'}
                            type="password"
                            value={data.password}
                            onChange={(value) => setData('password', value)}
                            placeholder="Kosongkan jika tidak diubah"
                            help="Password dienkripsi dan disimpan dengan aman"
                            error={errors.password}
                        />
                    </div>
                )}

                {/* SNMP */}
                {(isSnmpAccess || ['hioso', 'huawei_ma5608t'].includes(data.vendor)) && (
                    <TextField
                        id="snmp_community"
                        label="SNMP Community"
                        value={data.snmp_community}
                        onChange={(value) => setData('snmp_community', value)}
                        placeholder={olt.snmp_community_configured ? 'Kosongkan jika tidak diubah' : 'Isi community SNMP'}
                        help="Kosongkan untuk mempertahankan nilai tersimpan. Nilai tidak ditampilkan kembali."
                        error={errors.snmp_community}
                    />
                )}

                {/* Lokasi */}
                <TextField
                    id="location"
                    label="Lokasi Pemasangan"
                    value={data.location}
                    onChange={(value) => setData('location', value)}
                    placeholder="contoh: POP Mangliawan, Gedung Utama Lt.2"
                    help="Deskripsi lokasi fisik OLT di lapangan"
                    maxLength={255}
                    error={errors.location}
                />

                {/* Catatan */}
                <div className="space-y-2">
                    <Label htmlFor="notes">Catatan Tambahan</Label>
                    <Textarea
                        id="notes"
                        value={data.notes}
                        onChange={(event) => setData('notes', event.target.value)}
                        placeholder="Catatan instalasi, keterangan khusus, dll..."
                        className="min-h-[100px]"
                    />
                    <p className="text-xs text-muted-foreground">Opsional. Informasi tambahan tentang OLT ini.</p>
                    {errors.notes && <p className="text-sm text-destructive">{errors.notes}</p>}
                </div>
            </ResourceFormShell>
        </EnterpriseFormLayout>
    );
}
