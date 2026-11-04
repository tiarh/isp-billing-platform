import { useState, useEffect, useCallback, useRef } from 'react';
import { EnterpriseIndexLayout } from '@/Components/enterprise/EnterpriseIndexLayout';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Badge } from '@/Components/ui/badge';
import { ConfirmDestructive } from '@/Components/ConfirmDestructive';
import {
    MessageCircle,
    QrCode,
    Link2,
    RefreshCw,
    Send,
    Loader2,
    CheckCircle2,
    XCircle,
    Smartphone,
    ShieldAlert,
    RotateCw,
} from 'lucide-react';
import { toast } from 'sonner';
import { readMetaCsrfToken } from '@/lib/csrf';
import { usePollWhileVisible } from '@/hooks/usePollWhileVisible';

interface InstanceItem {
    key: string;
    label: string;
    description: string;
    name: string;
    state: string;
    connected: boolean;
    configured: boolean;
    number_masked?: string | null;
    used_by?: string[];
    also_handles?: string[];
    unused?: boolean;
    unused_message?: string | null;
}

interface TicketRouting {
    current: string;
    options: { value: string; label: string }[];
    can_edit: boolean;
}

interface Props {
    instances?: InstanceItem[];
    ticket_routing?: TicketRouting;
    instance: {
        name: string;
        state: string;
        connected: boolean;
        configured: boolean;
    };
    config: {
        base_url: string;
        api_key_masked: string;
    };
}

function qrImageSrc(value: string | null | undefined): string | null {
    if (!value) return null;
    if (value.startsWith('data:image/')) return value;
    return `data:image/png;base64,${value}`;
}

export default function WaPair({ instances: initialInstances, ticket_routing, instance, config }: Props) {
    const [instances, setInstances] = useState<InstanceItem[] | undefined>(initialInstances);
    const [routing, setRouting] = useState<TicketRouting | undefined>(ticket_routing);
    const [routingChoice, setRoutingChoice] = useState<string>(ticket_routing?.current ?? '');
    const [routingConfirmOpen, setRoutingConfirmOpen] = useState(false);
    const [routingWarnings, setRoutingWarnings] = useState<string[]>([]);
    const [routingBusy, setRoutingBusy] = useState(false);
    const [activeChannel, setActiveChannel] = useState('ops');
    const [status, setStatus] = useState(instance);
    const [instanceName, setInstanceName] = useState(instance.name);
    const [qrCode, setQrCode] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);
    const [creating, setCreating] = useState(false);
    const [disconnecting, setDisconnecting] = useState(false);
    const [rePairConfirmOpen, setRePairConfirmOpen] = useState(false);

    const [testTo, setTestTo] = useState('');
    const [testMsg, setTestMsg] = useState('Test message dari Skynet eBilling');
    const [sendingTest, setSendingTest] = useState(false);
    const activeInstance = instances?.find((item) => item.key === activeChannel);
    const instQuery = activeInstance ? `&instance=${encodeURIComponent(activeInstance.name)}` : '';

    // Replies for a card that is no longer active must be ignored (fast tab switching).
    const activeChannelRef = useRef(activeChannel);
    activeChannelRef.current = activeChannel;

    // Reset only when the active card or its instance name changes. A status refresh rebuilds the
    // card object, which must NOT clear the QR the operator is about to scan.
    const activeInstanceName = activeInstance?.name;
    useEffect(() => {
        if (!activeInstance) return;

        setStatus({
            name: activeInstance.name,
            state: activeInstance.state,
            connected: activeInstance.connected,
            configured: activeInstance.configured,
        });
        setInstanceName(activeInstance.name);
        setQrCode(null);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [activeChannel, activeInstanceName]);

    const refreshStatus = useCallback(async (channel = activeChannel) => {
        try {
            const res = await fetch(`/api/wa-gateway/status?channel=${channel}${instQuery}&fresh=1`);
            const data = await res.json();
            if (channel !== activeChannelRef.current) return;
            setStatus((prev) => ({ ...prev, ...data }));
            if (data.instance_name) setInstanceName(data.instance_name);
            setInstances((prev) => prev?.map((item) => (item.key === channel
                ? { ...item, state: data.state ?? item.state, connected: data.connected ?? item.connected, number_masked: data.number_masked ?? null }
                : item)));
        } catch {
            // ignore
        }
    }, [activeChannel, instQuery]);

    const refreshQr = useCallback(async (channel = activeChannel) => {
        try {
            const res = await fetch(`/api/wa-gateway/qr-code?channel=${channel}${instQuery}`);
            const data = await res.json();
            if (channel !== activeChannelRef.current) return;
            setQrCode(data.qr_code);
            if (data.instance_name) setInstanceName(data.instance_name);
        } catch {
            setQrCode(null);
        }
    }, [activeChannel, instQuery]);

    // Load status & QR once per channel / connection change.
    useEffect(() => {
        refreshStatus(activeChannel);
        refreshQr(activeChannel);

        if (status.connected) {
            setQrCode(null);
        }
    }, [activeChannel, status.connected, refreshStatus, refreshQr]);

    // R6: while waiting for a scan, poll status every 5 s and the QR every
    // ~20 s (WhatsApp rotates the QR about every 20 s; matches the on-page copy); both pause while the tab is hidden.
    usePollWhileVisible(() => refreshStatus(activeChannel), 5000, { enabled: !status.connected });
    usePollWhileVisible(() => refreshQr(activeChannel), 20000, { enabled: !status.connected });

    const waHeaders = (): Record<string, string> => ({
        'X-CSRF-TOKEN': readMetaCsrfToken() || (window as any).csrfToken || '',
        'Content-Type': 'application/json',
        Accept: 'application/json',
    });

    const parseWaResponse = async (res: Response) => {
        const text = await res.text();
        try {
            return { ok: res.ok, status: res.status, data: JSON.parse(text) as Record<string, unknown> };
        } catch {
            return { ok: false, status: res.status, data: { error: text.slice(0, 200) || `Server error ${res.status}` } };
        }
    };

    const handlePair = async () => {
        setCreating(true);
        try {
            const res = await fetch(`/api/wa-gateway/create-instance?channel=${activeChannel}${instQuery}`, {
                method: 'POST',
                headers: waHeaders(),
            });
            const { ok, status, data } = await parseWaResponse(res);
            if (!ok) {
                toast.error(String(data.error || data.message || `Gagal membuat instance (HTTP ${status})`));
                return;
            }
            if (data.success || data.instance || data.qr_code) {
                if (data.state === 'open') {
                    toast.success('Instance sudah terhubung — tidak perlu scan QR');
                } else if (data.qr_code) {
                    setQrCode(String(data.qr_code));
                    toast.success('QR siap discan.');
                } else {
                    toast.success('Instance dibuat. Scan QR untuk pairing.');
                }
                await refreshQr(activeChannel);
                await refreshStatus(activeChannel);
            } else {
                toast.error(String(data.error || data.message || 'Gagal membuat instance'));
            }
        } catch (e) {
            toast.error(e instanceof Error ? e.message : 'Gagal membuat instance');
        } finally {
            setCreating(false);
        }
    };

    const handleRePair = async () => {
        setDisconnecting(true);
        try {
            const res = await fetch(`/api/wa-gateway/re-pair?channel=${activeChannel}${instQuery}`, {
                method: 'POST',
                headers: waHeaders(),
            });
            const { ok, status, data } = await parseWaResponse(res);
            if (!ok) {
                toast.error(String(data.error || data.message || `Pairing ulang gagal (HTTP ${status})`));
                return;
            }
            if (data.qr_code) {
                setQrCode(String(data.qr_code));
                toast.success('QR baru siap discan.');
            } else if (data.state === 'open') {
                toast.info(String(data.message || 'Masih terhubung — refresh halaman lalu coba lagi.'));
            } else {
                toast.info(String(data.message || 'Logout berhasil — klik Buat / Refresh QR jika QR belum muncul.'));
            }
            await refreshQr(activeChannel);
            await refreshStatus(activeChannel);
        } catch (e) {
            toast.error(e instanceof Error ? e.message : 'Gagal re-pair');
        } finally {
            setDisconnecting(false);
            setRePairConfirmOpen(false);
        }
    };

    const handleRefresh = async () => {
        setLoading(true);
        try {
            await refreshQr(activeChannel);
            await refreshStatus(activeChannel);
            toast.success(`Status ${activeInstance?.label || activeChannel} di-refresh`);
        } finally {
            setLoading(false);
        }
    };

    const handleTest = async () => {
        if (!testTo) {
            toast.error('Nomor tujuan wajib diisi');
            return;
        }
        setSendingTest(true);
        try {
            const res = await fetch('/api/wa-gateway/test', {
                method: 'POST',
                headers: waHeaders(),
                body: JSON.stringify({ channel: activeChannel, instance: activeInstance?.name, phone: testTo, message: testMsg }),
            });
            const data = await res.json();
            if (data.success || data.id || data.key) toast.success(`Pesan terkirim via ${activeInstance?.label || activeChannel}!`);
            else toast.error(data.message || data.error || 'Gagal kirim');
        } catch {
            toast.error('Gagal kirim test message');
        } finally {
            setSendingTest(false);
        }
    };

    const startRoutingChange = async () => {
        if (!routing || routingChoice === routing.current) return;
        setRoutingBusy(true);
        try {
            const res = await fetch(`/api/wa-gateway/ticket-routing/check?instance=${encodeURIComponent(routingChoice)}`, {
                headers: { Accept: 'application/json' },
            });
            const { data } = await parseWaResponse(res);
            setRoutingWarnings(Array.isArray(data.warnings) ? (data.warnings as string[]) : []);
            setRoutingConfirmOpen(true);
        } catch {
            toast.error('Gagal memeriksa instance');
        } finally {
            setRoutingBusy(false);
        }
    };

    const saveRouting = async () => {
        setRoutingBusy(true);
        try {
            const res = await fetch('/api/wa-gateway/ticket-routing', {
                method: 'POST',
                headers: waHeaders(),
                body: JSON.stringify({ instance: routingChoice }),
            });
            const { ok, data } = await parseWaResponse(res);
            if (!ok || !data.success) {
                toast.error(String(data.message || data.error || 'Gagal menyimpan routing'));
                return;
            }
            setRouting((prev) => (prev ? { ...prev, current: String(data.current) } : prev));
            if (Array.isArray(data.instances)) setInstances(data.instances as InstanceItem[]);
            toast.success('Routing Ticket & Progress disimpan');
        } catch {
            toast.error('Gagal menyimpan routing');
        } finally {
            setRoutingBusy(false);
            setRoutingConfirmOpen(false);
        }
    };

    // The instance that carries PSB/Ops traffic needs an explicit red warning before logout.
    const handlesOps = (activeInstance?.used_by ?? []).some((label) => label.includes('PSB WA'));

    const isConnected = status.connected === true;
    const isWaiting = !isConnected && (status.state === 'close' || qrCode);

    return (
        <EnterpriseIndexLayout
            title="WA Gateway - Pairing Multi-Nomor"
            pageTitle="WA Gateway Pairing"
            subtitle="Kelola koneksi 3 nomor WhatsApp: Ops, Billing, dan Ticket/Progress"
            icon={MessageCircle}
            backHref={route('settings.index')}
            breadcrumbs={[
                { label: 'Settings', href: route('settings.index') },
                { label: 'WA Gateway 3 Nomor' },
            ]}
        >
            <div className="mx-auto max-w-3xl space-y-6">
                {/* Multi-Instance Tabs */}
                <div className="grid grid-cols-1 gap-3 p-1.5 bg-muted/50 rounded-xl border md:grid-cols-3">
                    {(instances ?? []).map((item) => (
                        <button
                            key={item.key}
                            type="button"
                            onClick={() => setActiveChannel(item.key)}
                            className={`flex flex-col items-start p-3 rounded-lg text-left transition-all ${
                                activeChannel === item.key
                                    ? 'bg-background shadow text-foreground border border-primary/30'
                                    : 'text-muted-foreground hover:text-foreground'
                            }`}
                        >
                            <div className="flex flex-wrap items-center gap-2 font-semibold text-xs sm:text-sm">
                                <span>{item.label}</span>
                                <span className="font-mono text-[11px] font-normal">{item.name}</span>
                                {item.connected ? (
                                    <Badge variant="up" className="text-[9px] px-1.5 py-0">CONNECTED</Badge>
                                ) : (
                                    <Badge variant="warning" className="text-[9px] px-1.5 py-0">
                                        {(item.state || 'unknown').toUpperCase()}
                                    </Badge>
                                )}
                            </div>
                            {item.number_masked && (
                                <p className="text-[11px] font-mono text-muted-foreground mt-0.5">{item.number_masked}</p>
                            )}
                            {item.unused ? (
                                <Badge variant="secondary" className="mt-1 text-[10px] px-1.5 py-0 whitespace-normal text-left">
                                    {item.unused_message}
                                </Badge>
                            ) : (
                                <p className="text-[11px] text-muted-foreground mt-0.5 leading-tight">
                                    Dipakai untuk: {(item.used_by ?? []).join(', ')}
                                </p>
                            )}
                            {(item.also_handles ?? []).map((note) => (
                                <p key={note} className="text-[11px] font-medium text-status-degraded mt-0.5 leading-tight">{note}</p>
                            ))}
                        </button>
                    ))}
                </div>

                {routing && (
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-base">Routing Ticket &amp; Progress</CardTitle>
                            <CardDescription>
                                Ticket, progress teknisi, dan Report (saluran ticket) dikirim lewat instance ini. Default tidak berubah kecuali Anda mengubahnya.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="flex flex-wrap items-center gap-3">
                            <Label htmlFor="ticket-routing">Ticket &amp; Progress memakai:</Label>
                            <select
                                id="ticket-routing"
                                className="h-9 rounded-md border bg-background px-3 text-sm"
                                value={routingChoice}
                                onChange={(e) => setRoutingChoice(e.target.value)}
                                disabled={!routing.can_edit || routingBusy}
                            >
                                {routing.options.map((opt) => (
                                    <option key={opt.value} value={opt.value}>{opt.label}</option>
                                ))}
                            </select>
                            {routing.can_edit ? (
                                <Button
                                    variant="outline"
                                    onClick={startRoutingChange}
                                    disabled={routingBusy || routingChoice === routing.current}
                                >
                                    Simpan routing
                                </Button>
                            ) : (
                                <span className="text-xs text-muted-foreground">Hanya superadmin yang dapat mengubah.</span>
                            )}
                        </CardContent>
                    </Card>
                )}
                    {/* Status Hero */}
                    <Card>
                        <CardHeader className="pb-3">
                            <div className="flex items-center justify-between">
                                <div className="flex items-center gap-3">
                                    {isConnected ? (
                                        <CheckCircle2 className="w-10 h-10 text-status-up" />
                                    ) : isWaiting ? (
                                        <QrCode className="w-10 h-10 text-status-degraded" />
                                    ) : (
                                        <XCircle className="w-10 h-10 text-destructive" />
                                    )}
                                    <div>
                                        <CardTitle className="text-lg">Status Koneksi</CardTitle>
                                        <CardDescription>
                                            Instance: <span className="font-mono">{instanceName}</span>
                                        </CardDescription>
                                    </div>
                                </div>
                                <Badge variant={isConnected ? 'default' : isWaiting ? 'secondary' : 'destructive'}>
                                    {isConnected ? 'CONNECTED' : isWaiting ? 'WAITING SCAN' : status.state?.toUpperCase() || 'UNKNOWN'}
                                </Badge>
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {isConnected && handlesOps && (
                                <div className="flex items-start gap-2 p-3 rounded-md bg-destructive/10 text-destructive text-sm">
                                    <ShieldAlert className="w-4 h-4 mt-0.5 shrink-0" />
                                    <div>
                                        Nomor ini menangani PSB WA, migrasi, /pppoe, alert OLT, dan absensi. Logout menghentikan semuanya sampai dipairing ulang.
                                    </div>
                                </div>
                            )}
                            <div className="flex flex-wrap gap-2">
                                {isConnected ? (
                                    <Button onClick={() => setRePairConfirmOpen(true)} disabled={disconnecting} variant="destructive" className="gap-2">
                                        {disconnecting ? <Loader2 className="w-4 h-4 animate-spin" /> : <RotateCw className="w-4 h-4" />}
                                        Pairing Ulang (Logout Device)
                                    </Button>
                                ) : (
                                    <Button onClick={handlePair} disabled={creating || status.configured === false} className="gap-2">
                                        {creating ? <Loader2 className="w-4 h-4 animate-spin" /> : <Link2 className="w-4 h-4" />}
                                        {status.configured ? 'Buat / Refresh QR' : 'Hubungkan'}
                                    </Button>
                                )}
                                <Button variant="outline" onClick={handleRefresh} disabled={loading} className="gap-2">
                                    {loading ? <Loader2 className="w-4 h-4 animate-spin" /> : <RefreshCw className="w-4 h-4" />}
                                    Refresh
                                </Button>
                            </div>
                            {status.configured === false && (
                                <div className="flex items-start gap-2 p-3 rounded-md bg-destructive/10 text-destructive text-sm">
                                    <ShieldAlert className="w-4 h-4 mt-0.5 shrink-0" />
                                    <div>
                                        <div className="font-semibold">Belum dikonfigurasi</div>
                                        <div>Set <code className="font-mono">EVOLUTION_API_BASE_URL</code> dan <code className="font-mono">EVOLUTION_API_API_KEY</code> di <code className="font-mono">.env</code>, lalu restart container.</div>
                                    </div>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* QR Code */}
                    {!isConnected && (
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2"><QrCode className="w-5 h-5" />QR Pairing</CardTitle>
                                <CardDescription>Buka WhatsApp di HP → Setelan → Perangkat Tertaut → Tautkan Perangkat → scan QR di bawah.</CardDescription>
                            </CardHeader>
                            <CardContent className="flex flex-col items-center gap-3">
                                {qrImageSrc(qrCode) ? (
                                    <img src={qrImageSrc(qrCode)!} alt="WA QR" className="border rounded-lg" style={{ maxWidth: 320 }} />
                                ) : (
                                    <div className="flex flex-col items-center gap-2 p-8 text-muted-foreground text-sm">
                                        <Smartphone className="w-12 h-12 opacity-50" />
                                        <span>QR akan muncul setelah klik "Buat / Refresh QR".</span>
                                    </div>
                                )}
                                <p className="text-xs text-muted-foreground">QR refresh otomatis setiap ~20 detik. Halaman ini poll status tiap 5 detik.</p>
                            </CardContent>
                        </Card>
                    )}

                    {/* Config info (read-only, auto-filled) */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Konfigurasi Evolution API</CardTitle>
                            <CardDescription>Auto-filled dari <code className="font-mono">.env</code> lewat <code className="font-mono">config/services.php</code>. Edit .env lalu restart container untuk mengubah.</CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div className="space-y-1">
                                    <Label>Base URL</Label>
                                    <Input value={config.base_url} readOnly className="font-mono text-xs" />
                                </div>
                                <div className="space-y-1">
                                    <Label>API Key</Label>
                                    <Input value={config.api_key_masked} readOnly className="font-mono text-xs" />
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Test message */}
                    {isConnected && (
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2"><Send className="w-5 h-5" />Test Kirim Pesan</CardTitle>
                                <CardDescription>Pakai nomor sendiri dulu (format intl, contoh: 6281234567890).</CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                                    <div className="space-y-1">
                                        <Label>Nomor Tujuan</Label>
                                        <Input value={testTo} onChange={(e) => setTestTo(e.target.value)} placeholder="6281234567890" />
                                    </div>
                                    <div className="space-y-1">
                                        <Label>Pesan</Label>
                                        <Input value={testMsg} onChange={(e) => setTestMsg(e.target.value)} />
                                    </div>
                                </div>
                                <div className="flex justify-end">
                                    <Button onClick={handleTest} disabled={sendingTest} variant="outline" className="gap-2">
                                        {sendingTest ? <Loader2 className="w-4 h-4 animate-spin" /> : <Send className="w-4 h-4" />}
                                        Kirim Test
                                    </Button>
                                </div>
                            </CardContent>
                        </Card>
                    )}
            </div>
            <ConfirmDestructive
                open={routingConfirmOpen}
                onOpenChange={setRoutingConfirmOpen}
                title="Ubah routing Ticket & Progress?"
                description={`Ticket, progress teknisi, dan Report (saluran ticket) akan dikirim lewat ${routingChoice}.${
                    routingWarnings.length ? ` Peringatan: ${routingWarnings.join(' ')}` : ''
                }`}
                confirmationLabel="Saya memahami pesan ticket/progress akan memakai instance ini."
                confirmText="Simpan routing"
                onConfirm={saveRouting}
                isLoading={routingBusy}
            />
            <ConfirmDestructive
                open={rePairConfirmOpen}
                onOpenChange={setRePairConfirmOpen}
                title="Pairing ulang perangkat WhatsApp?"
                description={`Instance ${activeInstance?.name || activeChannel} (${activeInstance?.label || activeChannel}) akan logout dan memerlukan scan QR baru. Dipakai untuk: ${
                    (activeInstance?.used_by ?? []).join(', ') || 'tidak ada fungsi'
                }.${handlesOps ? ' PERINGATAN: PSB WA, migrasi, /pppoe, alert OLT, absensi akan berhenti sampai dipairing ulang.' : ''}`}
                typedConfirmation={activeInstance?.name || 'LOGOUT'}
                typedConfirmationLabel={`Ketik nama instance (${activeInstance?.name || 'LOGOUT'}) untuk konfirmasi logout.`}
                confirmationLabel="Saya memahami sesi perangkat akan dihentikan."
                confirmText="Logout dan tampilkan QR"
                onConfirm={handleRePair}
                isLoading={disconnecting}
            />
        </EnterpriseIndexLayout>
    );
}
