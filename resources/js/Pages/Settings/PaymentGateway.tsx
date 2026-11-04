import { useEffect, useMemo, useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { EnterpriseIndexLayout } from '@/Components/enterprise/EnterpriseIndexLayout';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Switch } from '@/Components/ui/switch';
import { Badge } from '@/Components/ui/badge';
import { StatusBadge } from '@/Components/StatusBadge';
import { Textarea } from '@/Components/ui/textarea';
import { ConfirmDestructive } from '@/Components/ConfirmDestructive';
import { CreditCard, Loader2, Save, RefreshCw, ShieldCheck, ShieldOff, Copy, ListChecks } from 'lucide-react';
import { toast } from 'sonner';
import { formatWibDateTime } from '@/lib/dateTime';

type Provider = { key: string; label: string; configured: boolean };
type MethodRow = { code: string; name: string; group: string; fee: number; image_url?: string | null };

interface Props {
    active_provider: string;
    online_available: boolean;
    providers: Provider[];
    kill_switch: { enabled: boolean; held_count: number };
    general: {
        fee_bearer: 'customer' | 'merchant';
        va_expiry_minutes: number;
        min_amount: number;
        placeholder_email_domain: string;
        pilot_customer_ids: number[];
        pilot_customers: { id: number; code: string; name: string }[];
    };
    duitku: {
        merchant_code: string;
        api_key_set: boolean;
        api_key_masked: string;
        environment: 'sandbox' | 'production';
        signature_mode: 'hmac_sha256' | 'legacy';
        enabled_methods: string[];
        ip_whitelist_enabled: boolean;
        callback_ips: string[];
        callback_ips_customized: boolean;
        callback_ips_by_env: Record<string, string[]>;
        documented_ips: Record<string, string[]>;
        callback_url: string;
        return_url_example: string;
        last_rejected_ip: { ip: string; at: string } | null;
        supported_methods: Record<string, string>;
    };
    stats: { pending: number; rejected: number };
}

const GROUP_LABELS: Record<string, string> = {
    va: 'Virtual Account',
    qris: 'QRIS',
    ewallet: 'E-Wallet',
    retail: 'Retail (minimarket)',
};

// Non-secret draft survives the TOTP step-up redirect (the API key is never stored).
const DRAFT_KEY = 'payment-gateway-settings-draft';

function readDraft(): Record<string, unknown> | null {
    try {
        const raw = window.sessionStorage.getItem(DRAFT_KEY);
        return raw ? JSON.parse(raw) : null;
    } catch {
        return null;
    }
}

function writeDraft(value: Record<string, unknown> | null): void {
    try {
        if (value) window.sessionStorage.setItem(DRAFT_KEY, JSON.stringify(value));
        else window.sessionStorage.removeItem(DRAFT_KEY);
    } catch {
        // storage unavailable: nothing to keep
    }
}

function rupiah(value: number): string {
    return 'Rp ' + new Intl.NumberFormat('id-ID').format(value);
}

async function copy(text: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(text);
        toast.success('Disalin');
    } catch {
        toast.error('Gagal menyalin');
    }
}

export default function PaymentGateway(props: Props) {
    const { duitku, general, kill_switch } = props;

    const form = useForm({
        active_provider: props.active_provider,
        fee_bearer: general.fee_bearer,
        va_expiry_minutes: general.va_expiry_minutes,
        min_amount: general.min_amount,
        placeholder_email_domain: general.placeholder_email_domain,
        pilot_customer_ids: general.pilot_customer_ids,
        duitku_merchant_code: duitku.merchant_code,
        duitku_api_key: '',
        duitku_clear_api_key: false,
        duitku_environment: duitku.environment,
        duitku_signature_mode: duitku.signature_mode,
        duitku_enabled_methods: duitku.enabled_methods,
        duitku_ip_whitelist_enabled: duitku.ip_whitelist_enabled,
        duitku_callback_ips: duitku.callback_ips,
        confirm_production: false,
    });

    const [ipText, setIpText] = useState(duitku.callback_ips.join('\n'));
    const [pilotText, setPilotText] = useState(general.pilot_customer_ids.join('\n'));
    const [methods, setMethods] = useState<MethodRow[] | null>(null);
    const [methodsLoading, setMethodsLoading] = useState(false);
    const [killConfirmOpen, setKillConfirmOpen] = useState(false);
    const [killSaving, setKillSaving] = useState(false);

    useEffect(() => {
        const draft = readDraft();
        if (draft) {
            form.setData((data) => ({ ...data, ...draft, duitku_api_key: '' }));
            if (Array.isArray(draft.duitku_callback_ips)) setIpText((draft.duitku_callback_ips as string[]).join('\n'));
            if (Array.isArray(draft.pilot_customer_ids)) setPilotText((draft.pilot_customer_ids as (number | string)[]).join('\n'));
            toast.info('Isian sebelumnya dipulihkan. Isi ulang API key bila tadi diubah, lalu klik Simpan.');
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const switchingToProduction = form.data.duitku_environment === 'production' && duitku.environment !== 'production';

    const groupedMethods = useMemo(() => {
        const groups: Record<string, MethodRow[]> = {};
        (methods ?? []).forEach((m) => {
            (groups[m.group] ??= []).push(m);
        });
        return groups;
    }, [methods]);

    const loadMethods = async () => {
        setMethodsLoading(true);
        try {
            const res = await fetch(route('api.settings.payment-gateway.duitku-methods'), {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            const body = await res.json();
            if (!res.ok || !body.ok) {
                toast.error(body.message ?? 'Gagal memuat metode dari Duitku');
                return;
            }
            setMethods(body.methods);
            toast.success(`Terhubung ke Duitku (${body.environment}). ${body.methods.length} metode tersedia.`);
        } catch {
            toast.error('Gagal menghubungi server');
        } finally {
            setMethodsLoading(false);
        }
    };

    const toggleMethod = (code: string, checked: boolean) => {
        const current = new Set(form.data.duitku_enabled_methods);
        if (checked) current.add(code);
        else current.delete(code);
        form.setData('duitku_enabled_methods', Array.from(current));
    };

    const submit = () => {
        const ips = ipText
            .split(/[\s,]+/)
            .map((s) => s.trim())
            .filter(Boolean);
        const pilot = pilotText
            .split(/[\s,]+/)
            .map((s) => s.trim())
            .filter(Boolean)
            .map((s) => (/^\d+$/.test(s) ? Number(s) : s));
        form.transform((data) => ({ ...data, duitku_callback_ips: ips, pilot_customer_ids: pilot }));
        const { duitku_api_key: _secret, ...nonSecret } = { ...form.data, duitku_callback_ips: ips, pilot_customer_ids: pilot };
        writeDraft(nonSecret);
        form.post(route('settings.payment-gateway.update'), {
            preserveScroll: true,
            onSuccess: () => {
                writeDraft(null);
                form.setData('duitku_api_key', '');
            },
        });
    };

    const toggleKillSwitch = () => {
        setKillSaving(true);
        router.post(
            route('api.payment-gateway.kill-switch'),
            { enabled: !kill_switch.enabled },
            {
                preserveScroll: true,
                onFinish: () => {
                    setKillSaving(false);
                    setKillConfirmOpen(false);
                },
            },
        );
    };

    const err = (key: string) => (form.errors as Record<string, string | undefined>)[key];
    const pilotErrorKeys = Object.keys(form.errors).filter((k) => k === 'pilot_customer_ids' || k.startsWith('pilot_customer_ids.'));
    const pilotNonNumeric = pilotText
        .split(/[\s,]+/)
        .map((s) => s.trim())
        .filter((s) => s !== '' && !/^\d+$/.test(s));

    return (
        <EnterpriseIndexLayout
            title="Payment Gateway"
            pageTitle="Payment Gateway"
            subtitle="Pembayaran online tagihan (Duitku / Midtrans): kredensial, metode, fee dan kill switch"
            icon={CreditCard}
            backHref={route('settings.index')}
            breadcrumbs={[{ label: 'Settings', href: route('settings.index') }, { label: 'Payment Gateway' }]}
        >
            <div className="mx-auto max-w-4xl space-y-6">
                <Card>
                    <CardHeader>
                        <div className="flex flex-wrap items-center gap-2">
                            <CardTitle>Status</CardTitle>
                            {props.online_available ? (
                                <StatusBadge label="Online payment aktif" tone="up" />
                            ) : (
                                <Badge variant="outline">Online payment belum aktif</Badge>
                            )}
                        </div>
                        <CardDescription>
                            Pelanggan hanya bisa bayar online bila provider aktif sudah lengkap kredensialnya <b>dan</b> kill switch
                            &quot;Payment write&quot; ON.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="grid gap-3 sm:grid-cols-3 text-sm">
                            {props.providers.map((p) => (
                                <div key={p.key} className="rounded-md border p-3">
                                    <div className="font-medium">{p.label}</div>
                                    <div className="text-muted-foreground">{p.configured ? 'Kredensial lengkap' : 'Belum dikonfigurasi'}</div>
                                    {props.active_provider === p.key && <Badge className="mt-1">Aktif</Badge>}
                                </div>
                            ))}
                            <div className="rounded-md border p-3">
                                <div className="font-medium">Transaksi</div>
                                <div className="text-muted-foreground">
                                    Pending {props.stats.pending} · Perlu dicek {props.stats.rejected}
                                </div>
                            </div>
                        </div>
                        <div className="flex flex-wrap items-center justify-between gap-3 rounded-md border p-3">
                            <div className="text-sm">
                                <div className="font-medium flex items-center gap-2">
                                    {kill_switch.enabled ? <ShieldCheck className="h-4 w-4 text-status-up" /> : <ShieldOff className="h-4 w-4 text-status-degraded" />}
                                    Kill switch Payment write: {kill_switch.enabled ? 'ON' : 'OFF'}
                                </div>
                                <div className="text-muted-foreground">
                                    OFF = link bayar online disembunyikan dan callback yang masuk ditahan (tidak dikreditkan).
                                    {kill_switch.held_count > 0 && ` ${kill_switch.held_count} pembayaran sedang ditahan.`}
                                </div>
                            </div>
                            <Button variant={kill_switch.enabled ? 'outline' : 'default'} onClick={() => setKillConfirmOpen(true)}>
                                {kill_switch.enabled ? 'Matikan (OFF)' : 'Nyalakan (ON)'}
                            </Button>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Umum</CardTitle>
                        <CardDescription>Berlaku untuk provider mana pun.</CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-2">
                            <Label htmlFor="pg-provider">Provider aktif</Label>
                            <select
                                id="pg-provider"
                                className="h-10 w-full rounded-md border bg-background px-3 text-sm"
                                value={form.data.active_provider}
                                onChange={(e) => form.setData('active_provider', e.target.value)}
                            >
                                <option value="none">Tidak ada (online payment mati)</option>
                                {props.providers.map((p) => (
                                    <option key={p.key} value={p.key}>
                                        {p.label}
                                    </option>
                                ))}
                            </select>
                            {err('active_provider') && <p className="text-xs text-destructive">{err('active_provider')}</p>}
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="pg-fee">Biaya transaksi ditanggung</Label>
                            <select
                                id="pg-fee"
                                className="h-10 w-full rounded-md border bg-background px-3 text-sm"
                                value={form.data.fee_bearer}
                                onChange={(e) => form.setData('fee_bearer', e.target.value as 'customer' | 'merchant')}
                            >
                                <option value="customer">Pelanggan (fee ditambahkan ke total bayar)</option>
                                <option value="merchant">ISP (fee dipotong dari settlement)</option>
                            </select>
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="pg-expiry">Masa berlaku VA / kode retail (menit)</Label>
                            <Input
                                id="pg-expiry"
                                type="number"
                                min={60}
                                max={10080}
                                value={form.data.va_expiry_minutes}
                                onChange={(e) => form.setData('va_expiry_minutes', Number(e.target.value))}
                            />
                            <p className="text-xs text-muted-foreground">Default 180 (3 jam). QRIS/e-wallet memakai default provider.</p>
                            {err('va_expiry_minutes') && <p className="text-xs text-destructive">{err('va_expiry_minutes')}</p>}
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="pg-min">Minimum bayar online</Label>
                            <Input
                                id="pg-min"
                                type="number"
                                min={10000}
                                value={form.data.min_amount}
                                onChange={(e) => form.setData('min_amount', Number(e.target.value))}
                            />
                            <p className="text-xs text-muted-foreground">Duitku menolak di bawah {rupiah(10000)}.</p>
                        </div>
                        <div className="space-y-2 sm:col-span-2">
                            <Label htmlFor="pg-email">Domain email placeholder</Label>
                            <Input
                                id="pg-email"
                                value={form.data.placeholder_email_domain}
                                onChange={(e) => form.setData('placeholder_email_domain', e.target.value)}
                            />
                            <p className="text-xs text-muted-foreground">
                                Pelanggan tanpa email dikirim sebagai customer-ID@{form.data.placeholder_email_domain || 'invalid.local'} (tidak pernah
                                terkirim).
                            </p>
                            {err('placeholder_email_domain') && <p className="text-xs text-destructive">{err('placeholder_email_domain')}</p>}
                        </div>
                        <div className="space-y-2 sm:col-span-2">
                            <Label htmlFor="pg-pilot">Pelanggan uji bayar online</Label>
                            <Textarea
                                id="pg-pilot"
                                rows={3}
                                value={pilotText}
                                onChange={(e) => setPilotText(e.target.value)}
                                className="font-mono text-xs"
                                placeholder="Kosong = semua pelanggan"
                            />
                            <p className="text-xs text-muted-foreground" data-testid="pilot-hint">
                                ID pelanggan, satu per baris. Kosong = semua pelanggan. Bila diisi, hanya pelanggan ini yang bisa mulai bayar online;
                                yang lain tetap transfer manual. Pembayaran yang sudah dibuat tetap tercatat.
                            </p>
                            {general.pilot_customers.length > 0 && (
                                <ul className="text-xs text-muted-foreground" data-testid="pilot-customers">
                                    {general.pilot_customers.map((c) => (
                                        <li key={c.id}>
                                            {c.id}: {c.name} ({c.code})
                                        </li>
                                    ))}
                                </ul>
                            )}
                            {(pilotErrorKeys.length > 0 || pilotNonNumeric.length > 0) && (
                                <p className="text-xs text-destructive" data-testid="pilot-error">
                                    {pilotNonNumeric.length > 0
                                        ? `Isi dengan ID pelanggan (angka), bukan kode. Perlu diperbaiki: ${pilotNonNumeric.join(', ')}.`
                                        : 'Ada ID pelanggan yang tidak ditemukan atau dobel. Periksa lagi daftarnya.'}
                                </p>
                            )}
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <div className="flex flex-wrap items-center gap-2">
                            <CardTitle>Duitku</CardTitle>
                            <Badge variant="outline">{duitku.environment === 'production' ? 'Production' : 'Sandbox'}</Badge>
                            {duitku.api_key_set ? <Badge variant="outline">API key tersimpan</Badge> : <Badge variant="outline">API key kosong</Badge>}
                        </div>
                        <CardDescription>API key disimpan terenkripsi dan tidak pernah dikirim ke browser.</CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-5">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-2">
                                <Label htmlFor="dk-merchant">Merchant code</Label>
                                <Input
                                    id="dk-merchant"
                                    value={form.data.duitku_merchant_code}
                                    onChange={(e) => form.setData('duitku_merchant_code', e.target.value)}
                                    autoComplete="off"
                                />
                                {err('duitku_merchant_code') && <p className="text-xs text-destructive">{err('duitku_merchant_code')}</p>}
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="dk-key">API key (kosongkan = tidak diubah)</Label>
                                <Input
                                    id="dk-key"
                                    type="password"
                                    value={form.data.duitku_api_key}
                                    placeholder={duitku.api_key_masked || 'Belum diisi'}
                                    onChange={(e) => form.setData('duitku_api_key', e.target.value)}
                                    autoComplete="new-password"
                                />
                                {duitku.api_key_set && (
                                    <label className="flex items-center gap-2 text-xs text-muted-foreground">
                                        <input
                                            type="checkbox"
                                            checked={form.data.duitku_clear_api_key}
                                            onChange={(e) => form.setData('duitku_clear_api_key', e.target.checked)}
                                        />
                                        Hapus API key tersimpan
                                    </label>
                                )}
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="dk-env">Environment</Label>
                                <select
                                    id="dk-env"
                                    className="h-10 w-full rounded-md border bg-background px-3 text-sm"
                                    value={form.data.duitku_environment}
                                    onChange={(e) => {
                                        const env = e.target.value as 'sandbox' | 'production';
                                        form.setData('duitku_environment', env);
                                        // Daftar IP ikut environment yang dipilih.
                                        setIpText((duitku.callback_ips_by_env[env] ?? []).join('\n'));
                                    }}
                                >
                                    <option value="sandbox">Sandbox (uji coba)</option>
                                    <option value="production">Production (uang asli)</option>
                                </select>
                                {switchingToProduction && (
                                    <label className="flex items-start gap-2 rounded-md border border-status-degraded/40 bg-status-degraded-bg p-2 text-xs">
                                        <input
                                            type="checkbox"
                                            checked={form.data.confirm_production}
                                            onChange={(e) => form.setData('confirm_production', e.target.checked)}
                                        />
                                        Saya paham: setelah disimpan, transaksi memakai uang asli. Pastikan API key production dan callback URL
                                        sudah dipasang di dashboard Duitku.
                                    </label>
                                )}
                                {err('duitku_environment') && <p className="text-xs text-destructive">{err('duitku_environment')}</p>}
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="dk-sig">Format signature</Label>
                                <select
                                    id="dk-sig"
                                    className="h-10 w-full rounded-md border bg-background px-3 text-sm"
                                    value={form.data.duitku_signature_mode}
                                    onChange={(e) => form.setData('duitku_signature_mode', e.target.value as 'hmac_sha256' | 'legacy')}
                                >
                                    <option value="hmac_sha256">HMAC-SHA256 (dokumentasi terbaru)</option>
                                    <option value="legacy">Legacy MD5/SHA256</option>
                                </select>
                                <p className="text-xs text-muted-foreground">Ganti ke legacy hanya bila tes koneksi gagal &quot;wrong signature&quot;.</p>
                            </div>
                        </div>

                        <div className="space-y-2 rounded-md border p-3 text-sm">
                            <div className="font-medium">Pasang di dashboard Duitku (Project → Callback URL)</div>
                            <div className="flex items-center gap-2 font-mono text-xs break-all">
                                {duitku.callback_url}
                                <Button size="sm" variant="ghost" onClick={() => copy(duitku.callback_url)} aria-label="Salin callback URL">
                                    <Copy className="h-3 w-3" />
                                </Button>
                            </div>
                            <div className="text-xs text-muted-foreground">Return URL dikirim otomatis per transaksi, contoh: {duitku.return_url_example}</div>
                        </div>

                        <div className="space-y-3">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    <div className="font-medium text-sm">Metode pembayaran</div>
                                    <div className="text-xs text-muted-foreground">
                                        {form.data.duitku_enabled_methods.length === 0
                                            ? 'Belum dipilih: semua metode yang didukung boleh dipakai.'
                                            : `${form.data.duitku_enabled_methods.length} metode dipilih.`}
                                    </div>
                                </div>
                                <Button variant="outline" size="sm" onClick={loadMethods} disabled={methodsLoading} className="gap-2">
                                    {methodsLoading ? <Loader2 className="h-4 w-4 animate-spin" /> : <RefreshCw className="h-4 w-4" />}
                                    Tes koneksi &amp; muat metode
                                </Button>
                            </div>
                            {methods === null ? (
                                <p className="text-xs text-muted-foreground">
                                    Simpan merchant code + API key, lalu klik tombol di atas untuk memuat metode beserta fee dari akun Duitku.
                                    Tersimpan: {form.data.duitku_enabled_methods.join(', ') || '-'}
                                </p>
                            ) : (
                                <div className="grid gap-3 sm:grid-cols-2">
                                    {Object.entries(groupedMethods).map(([group, rows]) => (
                                        <div key={group} className="rounded-md border p-3">
                                            <div className="mb-2 text-sm font-medium">{GROUP_LABELS[group] ?? group}</div>
                                            {rows.map((m) => (
                                                <label key={m.code} className="flex items-center justify-between gap-2 py-1 text-sm">
                                                    <span className="flex items-center gap-2">
                                                        <input
                                                            type="checkbox"
                                                            checked={form.data.duitku_enabled_methods.includes(m.code)}
                                                            onChange={(e) => toggleMethod(m.code, e.target.checked)}
                                                        />
                                                        {m.name} <span className="text-xs text-muted-foreground">({m.code})</span>
                                                    </span>
                                                    <span className="text-xs text-muted-foreground">fee {rupiah(m.fee)}</span>
                                                </label>
                                            ))}
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>

                        <div className="space-y-2">
                            <div className="flex items-center gap-2">
                                <Switch
                                    id="dk-ipwl"
                                    checked={form.data.duitku_ip_whitelist_enabled}
                                    onCheckedChange={(v) => form.setData('duitku_ip_whitelist_enabled', v)}
                                />
                                <Label htmlFor="dk-ipwl">Batasi callback hanya dari IP Duitku</Label>
                            </div>
                            <Textarea
                                rows={4}
                                value={ipText}
                                onChange={(e) => setIpText(e.target.value)}
                                className="font-mono text-xs"
                                aria-label="Daftar IP callback"
                            />
                            <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                <span>Satu IP per baris. {duitku.callback_ips_customized ? 'Daftar kustom.' : 'Default: daftar resmi dari dokumentasi Duitku.'}</span>
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    onClick={() => setIpText((duitku.documented_ips[form.data.duitku_environment] ?? []).join('\n'))}
                                >
                                    <ListChecks className="mr-1 h-3 w-3" /> Pakai daftar resmi ({form.data.duitku_environment})
                                </Button>
                            </div>
                            {duitku.last_rejected_ip ? (
                                <p className="text-xs text-status-degraded" data-testid="duitku-last-rejected-ip">
                                    Callback terakhir yang ditolak karena IP: {duitku.last_rejected_ip.ip} ({formatWibDateTime(duitku.last_rejected_ip.at)}).
                                    Bila ini IP proxy internal, perbaiki TRUSTED_PROXIES di server.
                                </p>
                            ) : (
                                <p className="text-xs text-muted-foreground" data-testid="duitku-last-rejected-ip">Belum ada callback yang ditolak karena IP.</p>
                            )}
                            {err('duitku_callback_ips.0') && <p className="text-xs text-destructive">IP tidak valid.</p>}
                        </div>
                    </CardContent>
                </Card>

                <div className="flex flex-wrap items-center gap-3">
                    <Button onClick={submit} disabled={form.processing} className="gap-2">
                        {form.processing ? <Loader2 className="h-4 w-4 animate-spin" /> : <Save className="h-4 w-4" />}
                        Simpan pengaturan
                    </Button>
                    <span className="text-xs text-muted-foreground">Menyimpan butuh verifikasi TOTP. Setelah verifikasi, klik Simpan sekali lagi.</span>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Checklist go-live</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <ol className="list-decimal space-y-1 pl-5 text-sm">
                            <li>Isi merchant code + API key <b>sandbox</b>, environment Sandbox, provider aktif Duitku, Simpan.</li>
                            <li>Klik &quot;Tes koneksi &amp; muat metode&quot;, pilih metode (VA, QRIS, e-wallet, retail), Simpan.</li>
                            <li>Pasang Callback URL di atas pada project Duitku.</li>
                            <li>Nyalakan kill switch Payment write, lalu bayar 1 tagihan uji di sandbox: cek invoice lunas, un-isolir, WA kuitansi.</li>
                            <li>Ganti ke API key production + environment Production (centang konfirmasi), Simpan.</li>
                            <li>Uji 1 transaksi asli nominal kecil, pantau halaman Transaksi Online 1-2 hari.</li>
                        </ol>
                    </CardContent>
                </Card>
            </div>

            <ConfirmDestructive
                open={killConfirmOpen}
                onOpenChange={setKillConfirmOpen}
                title={kill_switch.enabled ? 'Matikan pembayaran online?' : 'Nyalakan pembayaran online?'}
                description={
                    kill_switch.enabled
                        ? 'Link bayar online akan disembunyikan. Callback yang masuk tetap disimpan tapi ditahan sampai dinyalakan lagi.'
                        : 'Pelanggan bisa bayar online dan callback langsung melunasi invoice. Pembayaran yang ditahan akan diproses.'
                }
                confirmText={kill_switch.enabled ? 'Matikan' : 'Nyalakan'}
                onConfirm={toggleKillSwitch}
                isLoading={killSaving}
            />
        </EnterpriseIndexLayout>
    );
}
