import { useEffect, useMemo, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { toast } from 'sonner';
import { EnterpriseIndexLayout } from '@/Components/enterprise/EnterpriseIndexLayout';
import { EnterpriseMetricCard } from '@/Components/enterprise/EnterpriseMetricCard';
import { ConfirmDestructive } from '@/Components/ConfirmDestructive';
import DataTable, { Column, FilterConfig, PaginatedData } from '@/Components/DataTable';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { formatDateTime, formatRelative } from '@/lib/format';
import {
    Activity, ChevronRight, Copy, ExternalLink, Globe2, Info, Link2,
    LoaderCircle, Power, RefreshCw, Router, Signal, Tag, User, Wifi, X,
} from 'lucide-react';

const GUIDE_DISMISS_KEY = 'genieacs-guide-dismissed-v3';

type CustomerRef = {
    id: number;
    code: string;
    name: string;
    pppoe_user?: string | null;
};

type TagLike = string | { value?: string; label?: string };

function tagLabel(tag: TagLike): string {
    if (typeof tag === 'string') return tag;
    if (tag && typeof tag === 'object') {
        return String(tag.label ?? tag.value ?? '');
    }
    return String(tag ?? '');
}

type Device = {
    id: number;
    device_id: string;
    serial_number: string | null;
    manufacturer: string | null;
    product_class: string | null;
    model: string | null;
    software_version: string | null;
    hardware_version: string | null;
    ip_address: string | null;
    ip_tr069: string | null;
    mac_address: string | null;
    ssid: string | null;
    ssid_5g: string | null;
    tags: TagLike[];
    pelanggan: string | null;
    genieacs_customer_name: string | null;
    pppoe_user: string | null;
    wan_status: string | null;
    uptime_seconds: number | null;
    uptime_text: string | null;
    pppoe_uptime: string | null;
    pon_mode: string | null;
    wifi_clients: number | null;
    wlan_enabled: boolean | null;
    wlan_channel: string | null;
    /** RCA-3: fresh WiFi state; `fresh` false = value older than the limit ("data lama") */
    cpe: {
        fresh: boolean;
        /** some WiFi value exists (fresh or stale); false = never read */
        known?: boolean;
        age_min: number | null;
        clients: number | null;
        wlan24_on: boolean | null;
        wlan5_on: boolean | null;
        last_boot_at: string | null;
        ppp_status: string | null;
    };
    rx_power: number | string | null;
    temperature: number | string | null;
    match_source: string | null;
    is_online: boolean;
    is_fiberhome: boolean;
    last_inform_at: string | null;
    registered_at: string | null;
    last_ping_at: string | null;
    last_ping_success: boolean | null;
    last_ping_rtt_ms: number | null;
    customer: CustomerRef | null;
    display_name: string;
    parameter_summary?: Array<{ label: string; value: string }>;
    updated_at?: string | null;
};

type Summary = {
    total: number;
    online: number;
    offline: number;
    fiberhome: number;
    linked: number;
    unlinked: number;
    ssid_read: number;
    tagged: number;
    ping_ok: number;
    ping_failed_recent: number;
};

type Props = {
    devices: PaginatedData<Device>;
    summary: Summary;
    filters: {
        search?: string;
        status?: string;
        vendor?: string;
        linked?: string;
    };
    genieacsUrl?: string | null;
    genieacsPanelUrl: string;
    cwmpUrl?: string | null;
    lastSyncedAt?: string | null;
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
        throw new Error(payload.message || payload.error || 'Request gagal');
    }
    return payload;
}

function fmtDate(value?: string | null) {
    return formatDateTime(value);
}

function fmtRelative(value?: string | null) {
    return formatRelative(value);
}

function fmtUptime(seconds?: number | null) {
    if (!seconds) return '-';
    const days = Math.floor(seconds / 86400);
    const hours = Math.floor((seconds % 86400) / 3600);
    if (days > 0) return `${days} hari ${hours} jam`;
    if (hours > 0) return `${hours} jam`;
    return `${Math.floor((seconds % 3600) / 60)} menit`;
}

type CpeDisplay = Device['cpe'] | undefined;

/** RCA-3: a WiFi value older than the limit is never shown as a number. */
function cpeMissing(cpe: CpeDisplay) {
    return cpe && cpe.known ? '— (data lama)' : '— (belum ada data)';
}

function cpeClients(cpe: CpeDisplay) {
    if (!cpe || !cpe.fresh || cpe.clients === null) return cpeMissing(cpe);
    return `${cpe.clients} klien`;
}

function cpeBand(cpe: CpeDisplay, on: boolean | null | undefined) {
    if (!cpe || !cpe.fresh || on === null || on === undefined) return cpeMissing(cpe);
    return on ? 'Nyala' : 'Mati';
}

function cpeAge(cpe: CpeDisplay) {
    if (!cpe || cpe.age_min === null) return undefined;
    if (cpe.age_min < 1) return 'Dicek barusan';
    if (cpe.age_min < 120) return `Dicek ${cpe.age_min} menit lalu`;
    if (cpe.age_min < 2880) return `Dicek ${Math.floor(cpe.age_min / 60)} jam lalu`;
    return `Dicek ${Math.floor(cpe.age_min / 1440)} hari lalu`;
}

/** Prefer the GenieACS uptime VP (already "d hh:mm:ss"); fall back to seconds. */
function fmtUptimeText(text?: string | null, seconds?: number | null) {
    if (text && text !== 'N/A') return text;
    return fmtUptime(seconds);
}

function onlineBadge(online: boolean, compact = false) {
    return (
        <Badge
            variant="outline"
            className={online
                ? 'border-transparent bg-status-up-bg text-status-up'
                : 'border-transparent bg-muted text-muted-foreground'}
        >
            <span className={`mr-1.5 inline-block rounded-full ${online ? 'bg-status-up' : 'bg-muted-foreground/50'} ${compact ? 'h-1.5 w-1.5' : 'h-2 w-2'}`} />
            {online ? 'Online' : 'Offline'}
        </Badge>
    );
}

function copyText(value: string, label: string) {
    navigator.clipboard.writeText(value).then(() => toast.success(`${label} disalin`)).catch(() => toast.error('Gagal menyalin'));
}

function vendorShort(manufacturer?: string | null, isFiberhome?: boolean) {
    if (isFiberhome) return 'FHTT';
    const m = (manufacturer || '').toLowerCase();
    if (m.includes('fiber')) return 'FHTT';
    if (m.includes('zte')) return 'ZTE';
    if (m.includes('huawei')) return 'HW';
    if (m.includes('tp-link') || m.includes('tplink')) return 'TPL';
    if (!manufacturer) return null;
    return manufacturer.slice(0, 4).toUpperCase();
}

function matchSourceLabel(source?: string | null) {
    if (!source) return null;
    const map: Record<string, string> = {
        serial: 'Match SN',
        pppoe_tag: 'Match PPPoE',
        pelanggan_tag: 'Match tag',
    };
    return map[source] || source;
}

function pingBadge(device: Device) {
    if (!device.last_ping_at) return null;
    const ok = device.last_ping_success;
    return (
        <Badge
            variant="outline"
            className={`h-[18px] px-2 text-[10px] font-normal ${ok
                ? 'border-status-up/30 text-status-up'
                : 'border-status-down/30 text-status-down'}`}
        >
            Ping {ok ? 'OK' : 'fail'}{device.last_ping_rtt_ms ? ` ${device.last_ping_rtt_ms}ms` : ''}
        </Badge>
    );
}

type QuickFilter = { id: string; label: string; patch: Record<string, string> };

export default function Index({ devices, summary, filters, genieacsUrl, genieacsPanelUrl, cwmpUrl, lastSyncedAt }: Props) {
    const [syncing, setSyncing] = useState(false);
    const [selected, setSelected] = useState<Device | null>(null);
    const [detailLoading, setDetailLoading] = useState(false);
    const [rebooting, setRebooting] = useState(false);
    const [rebootConfirmOpen, setRebootConfirmOpen] = useState(false);
    const [guideOpen, setGuideOpen] = useState(true);

    useEffect(() => {
        try {
            setGuideOpen(localStorage.getItem(GUIDE_DISMISS_KEY) !== '1');
        } catch {
            setGuideOpen(true);
        }
    }, []);

    function dismissGuide() {
        setGuideOpen(false);
        try { localStorage.setItem(GUIDE_DISMISS_KEY, '1'); } catch { /* ignore */ }
    }

    const quickFilters: QuickFilter[] = useMemo(() => [
        { id: 'all', label: 'Semua', patch: { status: '', vendor: '', linked: '' } },
        { id: 'online', label: `Online (${summary.online})`, patch: { status: 'online', vendor: '', linked: '' } },
        { id: 'offline', label: `Offline (${summary.offline})`, patch: { status: 'offline', vendor: '', linked: '' } },
        { id: 'linked', label: `Sudah link (${summary.linked})`, patch: { status: '', vendor: '', linked: 'yes' } },
        { id: 'unlinked', label: `Belum link (${summary.unlinked})`, patch: { status: '', vendor: '', linked: 'no' } },
        { id: 'fiberhome', label: `FiberHome (${summary.fiberhome})`, patch: { status: '', vendor: 'fiberhome', linked: '' } },
    ], [summary]);

    const activeQuickId = useMemo(() => {
        if (filters.status === 'online') return 'online';
        if (filters.status === 'offline') return 'offline';
        if (filters.linked === 'yes') return 'linked';
        if (filters.linked === 'no') return 'unlinked';
        if (filters.vendor === 'fiberhome') return 'fiberhome';
        return 'all';
    }, [filters]);

    function applyQuickFilter(patch: Record<string, string>) {
        router.get(route('modules.genieacs'), {
            search: filters.search || undefined,
            ...patch,
        }, { preserveState: true, preserveScroll: true });
    }

    async function syncDevices() {
        setSyncing(true);
        const toastId = toast.loading('Sinkron dari GenieACS... (~15 detik)');
        try {
            const payload = await jsonRequest('/api/genieacs/sync', { method: 'POST', body: '{}' });
            if (payload.stale || payload.error_code) {
                toast.error(payload.message || 'Sync GenieACS belum berhasil; memakai snapshot terakhir.', { id: toastId });
                router.reload({ only: ['devices', 'summary', 'lastSyncedAt'] });
                return;
            }
            const backfilled = payload.backfilled_serials ? `, ${payload.backfilled_serials} SN pelanggan` : '';
            toast.success(`Sync selesai — ${payload.synced || 0} device${backfilled}`, { id: toastId });
            router.reload({ only: ['devices', 'summary', 'lastSyncedAt'] });
        } catch (err) {
            toast.error(err instanceof Error ? err.message : 'Sync gagal', { id: toastId });
        } finally {
            setSyncing(false);
        }
    }

    async function openDetail(device: Device) {
        setSelected(device);
        setDetailLoading(true);
        try {
            setSelected(await jsonRequest(`/api/genieacs/devices/${device.id}`));
        } catch {
            setSelected(device);
        } finally {
            setDetailLoading(false);
        }
    }

    async function rebootDevice() {
        if (!selected) return;
        setRebooting(true);
        try {
            await jsonRequest(`/api/genieacs/devices/${selected.id}/reboot`, { method: 'POST', body: '{}' });
            toast.success('Perintah reboot dikirim ke GenieACS');
            setRebootConfirmOpen(false);
        } catch (err) {
            toast.error(err instanceof Error ? err.message : 'Reboot gagal');
        } finally {
            setRebooting(false);
        }
    }

    const columns: Column<Device>[] = [
        {
            header: 'Pelanggan / Device',
            cell: (device) => (
                <div className="min-w-[200px]">
                    <div className="flex items-start gap-2">
                        <div className="min-w-0 flex-1">
                            <div className="flex items-center gap-1.5">
                                <p className="truncate font-medium leading-tight">{device.display_name}</p>
                                {device.customer ? (
                                    <>
                                        <span className="shrink-0 text-xs text-status-up">{device.customer.code}</span>
                                        {matchSourceLabel(device.match_source) && (
                                            <Badge variant="outline" className="h-[18px] shrink-0 px-2 text-[10px] font-normal text-status-up">{matchSourceLabel(device.match_source)}</Badge>
                                        )}
                                    </>
                                ) : (
                                    <span className="truncate text-xs text-status-degraded">
                                        {device.genieacs_customer_name || device.pelanggan || 'Belum link eBilling'}
                                    </span>
                                )}
                            </div>
                            <div className="mt-0.5 flex items-center gap-1.5">
                                <span className="truncate font-mono text-xs text-muted-foreground">{device.serial_number || 'Tanpa SN'}</span>
                                {vendorShort(device.manufacturer, device.is_fiberhome) && (
                                    <Badge variant="outline" className="h-[18px] shrink-0 border-border px-2 text-[10px] font-normal">{vendorShort(device.manufacturer, device.is_fiberhome)}</Badge>
                                )}
                                {pingBadge(device)}
                            </div>
                        </div>
                        <ChevronRight className="mt-1 h-4 w-4 shrink-0 text-muted-foreground/50" />
                    </div>
                </div>
            ),
        },
        {
            header: 'PPPoE',
            cell: (device) => (
                <div className="flex min-w-0 max-w-[220px] items-center gap-1.5">
                    <p className="min-w-0 truncate font-mono text-xs" title={device.pppoe_user || undefined}>{device.pppoe_user || '—'}</p>
                    {device.wan_status && (
                        <Badge
                            variant="outline"
                            className={/up|connected|active/i.test(String(device.wan_status))
                                ? 'h-[18px] border-transparent bg-status-up-bg px-2 text-status-up text-[10px]'
                                : 'h-[18px] border-border px-2 text-muted-foreground text-[10px]'}
                        >
                            {device.wan_status}
                        </Badge>
                    )}
                </div>
            ),
        },
        {
            header: 'PON',
            cell: (device) => (
                <div className="text-sm">
                    <p>{device.pon_mode || '—'}</p>
                    <p className="font-mono text-xs text-muted-foreground">{device.ip_address || '—'}</p>
                </div>
            ),
        },
        {
            header: 'WiFi',
            cell: (device) => (
                <div className="max-w-[190px] text-xs" title={`SSID 5G: ${device.ssid_5g || '—'}`}>
                    <p className="flex items-center gap-1 truncate">
                        <Wifi className="h-3 w-3 shrink-0 opacity-60" />
                        <span className="truncate">{device.ssid || 'SSID —'}</span>
                        <span className="shrink-0 text-muted-foreground">· {cpeClients(device.cpe)}</span>
                    </p>
                </div>
            ),
        },
        {
            header: 'Optik',
            cell: (device) => (
                <div className="text-xs">
                    <p>RX: {device.rx_power !== null && device.rx_power !== '' ? `${device.rx_power} dBm` : '—'}</p>
                    <p className="text-muted-foreground">Suhu: {device.temperature !== null && device.temperature !== '' ? `${device.temperature}°C` : '—'}</p>
                </div>
            ),
        },
        {
            header: 'Uptime',
            className: 'hidden 2xl:table-cell',
            cell: (device) => (
                <div className="text-xs">
                    <p>{fmtUptimeText(device.uptime_text, device.uptime_seconds)}</p>
                    {device.pppoe_uptime && device.pppoe_uptime !== 'N/A' && (
                        <p className="text-muted-foreground">PPPoE: {device.pppoe_uptime}</p>
                    )}
                </div>
            ),
        },
        {
            header: 'IP',
            className: 'hidden 2xl:table-cell',
            cell: (device) => (
                <div className="font-mono text-xs">
                    <p>{device.ip_address || '—'}</p>
                    {device.ip_tr069 && <p className="text-muted-foreground">TR069: {device.ip_tr069}</p>}
                </div>
            ),
        },
        {
            header: 'Status',
            className: 'min-w-[110px]',
            cell: (device) => (
                <div className="flex flex-col items-start gap-0.5">
                    {onlineBadge(device.is_online)}
                    <span className="whitespace-nowrap text-xs text-muted-foreground">{fmtRelative(device.last_inform_at)}</span>
                </div>
            ),
        },
        {
            header: '',
            className: 'sticky right-0 w-[90px] border-l border-border bg-card text-right group-hover:bg-muted [&:is(th)]:bg-muted',
            cell: (device) => (
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    className="h-8 text-xs"
                    onClick={(e) => { e.stopPropagation(); openDetail(device); }}
                >
                    Detail
                </Button>
            ),
        },
    ];

    const filterConfigs: FilterConfig[] = [
        {
            key: 'status',
            placeholder: 'Status',
            options: [
                { label: 'Online', value: 'online' },
                { label: 'Offline', value: 'offline' },
            ],
        },
        {
            key: 'linked',
            placeholder: 'Pelanggan',
            options: [
                { label: 'Sudah link', value: 'yes' },
                { label: 'Belum link', value: 'no' },
            ],
        },
    ];

    const mobileCard = (device: Device) => (
        <button
            type="button"
            onClick={() => openDetail(device)}
            className="w-full rounded-lg border border-border/70 bg-card p-4 text-left shadow-sm transition-colors hover:bg-muted/40"
        >
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <p className="truncate font-medium">{device.display_name}</p>
                    <p className="font-mono text-xs text-muted-foreground">{device.serial_number}</p>
                </div>
                {onlineBadge(device.is_online, true)}
            </div>
            <div className="mt-3 grid grid-cols-2 gap-2 text-xs text-muted-foreground">
                <span>IP: <span className="font-mono text-foreground">{device.ip_address || '—'}</span></span>
                <span>Inform: {fmtRelative(device.last_inform_at)}</span>
                <span className="col-span-2 truncate">SSID: {device.ssid || '—'}</span>
                {vendorShort(device.manufacturer, device.is_fiberhome) && (
                    <span className="col-span-2">Vendor: {vendorShort(device.manufacturer, device.is_fiberhome)}</span>
                )}
            </div>
        </button>
    );

    return (
        <EnterpriseIndexLayout
            title="GenieACS"
            pageTitle="GenieACS TR069"
            subtitle="Kelola CPE/ONU dari sini — tanpa buka panel GenieACS terpisah."
            icon={Globe2}
            breadcrumbs={[
                { label: 'Integrasi', href: route('modules.genieacs') },
                { label: 'GenieACS' },
            ]}
            actions={
                <div className="flex flex-wrap items-center gap-2">
                    {genieacsUrl && (
                        <Button asChild variant="outline" size="sm" className="h-9 gap-2">
                            <a href={genieacsPanelUrl} target="_blank" rel="noreferrer">
                                <ExternalLink className="h-4 w-4" />
                                Panel ACS
                            </a>
                        </Button>
                    )}
                    {lastSyncedAt && !syncing && (
                        <span className="hidden text-xs text-muted-foreground sm:inline">
                            Sync DB: {fmtRelative(lastSyncedAt)}
                        </span>
                    )}
                    <Button size="sm" className="h-9 gap-2" onClick={syncDevices} disabled={syncing}>
                        <RefreshCw className={`h-4 w-4 ${syncing ? 'animate-spin' : ''}`} />
                        {syncing ? 'Sync...' : 'Sync Sekarang'}
                    </Button>
                </div>
            }
        >
            <div className="space-y-5">
                {guideOpen && (
                    <Card className="border-status-info/20 bg-status-info-bg">
                        <CardContent className="flex gap-3 p-4 text-sm">
                            <Info className="mt-0.5 h-5 w-5 shrink-0 text-status-info" />
                            <div className="min-w-0 flex-1 space-y-1 text-muted-foreground">
                                <p className="font-medium text-foreground">Cara pakai (NOC)</p>
                                <p>1. Klik <strong>Sync Sekarang</strong> untuk refresh data TR-069 dari ACS.</p>
                                <p>2. Klik kartu KPI atau chip <strong>Belum link</strong> untuk filter cepat.</p>
                                <p>3. Klik baris / <strong>Detail</strong> untuk parameter, tag, reboot, dan link pelanggan.</p>
                                {lastSyncedAt && (
                                    <p className="text-xs">Terakhir sync DB: {fmtRelative(lastSyncedAt)} ({fmtDate(lastSyncedAt)})</p>
                                )}
                            </div>
                            <Button type="button" variant="ghost" size="icon" className="h-8 w-8 shrink-0" onClick={dismissGuide} aria-label="Tutup panduan">
                                <X className="h-4 w-4" />
                            </Button>
                        </CardContent>
                    </Card>
                )}

                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    {([
                        { title: 'Device', value: String(summary.total), hint: `${summary.online} online · ${summary.offline} offline`, icon: Router, patch: { status: '', vendor: '', linked: '' } },
                        { title: 'Link eBilling', value: `${summary.linked}`, hint: `${summary.unlinked} belum link`, icon: Link2, patch: { status: '', vendor: '', linked: 'no' } },
                        { title: 'FiberHome', value: String(summary.fiberhome), hint: `${summary.tagged} punya tag ACS`, icon: Signal, patch: { status: '', vendor: 'fiberhome', linked: '' } },
                        { title: 'Internet OK', value: `${summary.ping_ok}`, hint: `Ping gagal: ${summary.ping_failed_recent}`, icon: Activity, patch: { status: 'online', vendor: '', linked: '' } },
                    ]).map((kpi) => (
                        <button
                            key={kpi.title}
                            type="button"
                            className="text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring rounded-lg"
                            onClick={() => applyQuickFilter(kpi.patch)}
                        >
                            <EnterpriseMetricCard title={kpi.title} value={kpi.value} hint={kpi.hint} icon={kpi.icon} />
                        </button>
                    ))}
                </div>

                <div className="flex flex-wrap gap-2">
                    {quickFilters.map((qf) => (
                        <Button
                            key={qf.id}
                            type="button"
                            size="sm"
                            variant={activeQuickId === qf.id ? 'default' : 'outline'}
                            className="h-8 rounded-full text-xs"
                            onClick={() => applyQuickFilter(qf.patch)}
                        >
                            {qf.label}
                        </Button>
                    ))}
                </div>

                <DataTable
                    compact
                    density="compact"
                    data={devices}
                    columns={columns}
                    filters={filters}
                    title="Daftar Device"
                    description={`${devices.total} CPE — klik baris untuk detail lengkap`}
                    searchPlaceholder="Cari nama, SN, IP, SSID, tag..."
                    filterConfigs={filterConfigs}
                    routeName="modules.genieacs"
                    pageSizeOptions={[25, 50, 100, 250, 500]}
                    onRowClick={openDetail}
                    mobileCard={mobileCard}
                />

                {cwmpUrl && (
                    <p className="text-center text-xs text-muted-foreground">
                        URL provisioning CPE: <button type="button" className="font-mono underline-offset-2 hover:underline" onClick={() => copyText(cwmpUrl, 'CWMP URL')}>{cwmpUrl}</button>
                    </p>
                )}
            </div>

            <Dialog open={!!selected} onOpenChange={(open) => !open && setSelected(null)}>
                <DialogContent className="max-h-[92vh] max-w-lg overflow-y-auto sm:max-w-xl">
                    {selected && (
                        <>
                            <DialogHeader>
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <DialogTitle className="truncate text-lg">{selected.display_name}</DialogTitle>
                                        <DialogDescription className="mt-1 space-y-1">
                                            <span className="flex flex-wrap items-center gap-2">
                                                <span className="font-mono text-xs">{selected.serial_number || selected.device_id}</span>
                                                <Button type="button" variant="ghost" size="icon" className="h-10 w-10 md:h-7 md:w-7" aria-label="Salin SN" title="Salin SN" onClick={() => copyText(selected.serial_number || selected.device_id, 'SN')}>
                                                    <Copy className="h-3.5 w-3.5" aria-hidden="true" />
                                                </Button>
                                                {vendorShort(selected.manufacturer, selected.is_fiberhome) && (
                                                    <Badge variant="secondary" className="text-[10px]">{vendorShort(selected.manufacturer, selected.is_fiberhome)}</Badge>
                                                )}
                                                {selected.model && <Badge variant="outline" className="text-[10px] font-normal">{selected.model}</Badge>}
                                            </span>
                                            {(selected.manufacturer || selected.product_class) && (
                                                <span className="block text-xs text-muted-foreground">
                                                    {[selected.manufacturer, selected.product_class].filter(Boolean).join(' · ')}
                                                </span>
                                            )}
                                        </DialogDescription>
                                    </div>
                                    {onlineBadge(selected.is_online)}
                                </div>
                            </DialogHeader>

                            {detailLoading ? (
                                <div className="flex items-center justify-center py-12 text-muted-foreground">
                                    <LoaderCircle className="mr-2 h-5 w-5 animate-spin" />
                                    Memuat parameter live...
                                </div>
                            ) : (
                                <div className="space-y-4">
                                    {selected.customer ? (
                                        <Card className="border-status-up/25 bg-status-up-bg">
                                            <CardContent className="flex items-center justify-between gap-3 p-4">
                                                <div className="min-w-0">
                                                    <p className="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-status-up">
                                                        <User className="h-3.5 w-3.5" /> Pelanggan eBilling
                                                    </p>
                                                    <p className="mt-1 font-medium">{selected.customer.name}</p>
                                                    <p className="text-sm text-muted-foreground">{selected.customer.code} · {selected.customer.pppoe_user}</p>
                                                </div>
                                                <Button asChild variant="outline" size="sm" className="shrink-0">
                                                    <Link href={route('customers.show', selected.customer.id)}>Buka</Link>
                                                </Button>
                                            </CardContent>
                                        </Card>
                                    ) : (
                                        <Card className="border-status-degraded/25 bg-status-degraded-bg">
                                            <CardContent className="p-4 text-sm text-status-degraded">
                                                <p className="font-medium">Belum link ke pelanggan</p>
                                                <p className="mt-1 text-xs opacity-80">
                                                    Tag/nama ACS: {selected.genieacs_customer_name || selected.pelanggan || (selected.tags?.[0] ? tagLabel(selected.tags[0]) : null) || '—'}
                                                </p>
                                            </CardContent>
                                        </Card>
                                    )}

                                    <Section title="Jaringan" icon={Globe2}>
                                        <InfoGrid items={[
                                            { label: 'IP WAN', value: selected.ip_address, mono: true, copy: selected.ip_address },
                                            { label: 'PPPoE', value: selected.pppoe_user },
                                            { label: 'WAN', value: selected.wan_status },
                                            { label: 'MAC', value: selected.mac_address, mono: true },
                                            { label: 'Uptime', value: fmtUptime(selected.uptime_seconds) },
                                            { label: 'Last inform', value: fmtRelative(selected.last_inform_at), hint: fmtDate(selected.last_inform_at) },
                                        ]} />
                                    </Section>

                                    <Section title="WiFi" icon={Wifi}>
                                        <InfoGrid items={[
                                            { label: 'SSID', value: selected.ssid },
                                            { label: 'SSID 5G', value: selected.ssid_5g },
                                            { label: 'Klien WiFi', value: cpeClients(selected.cpe), hint: cpeAge(selected.cpe) },
                                            { label: 'WiFi 2.4 GHz', value: cpeBand(selected.cpe, selected.cpe?.wlan24_on), hint: cpeAge(selected.cpe) },
                                            { label: 'WiFi 5 GHz', value: cpeBand(selected.cpe, selected.cpe?.wlan5_on), hint: cpeAge(selected.cpe) },
                                            { label: 'Restart terakhir', value: selected.cpe?.last_boot_at ? fmtRelative(selected.cpe.last_boot_at) : null, hint: selected.cpe?.last_boot_at ? fmtDate(selected.cpe.last_boot_at) : undefined },
                                            { label: 'Firmware', value: selected.software_version },
                                        ]} />
                                    </Section>

                                    {(selected.tags || []).length > 0 && (
                                        <Section title="Tags ACS" icon={Tag}>
                                            <div className="flex flex-wrap gap-1.5">
                                                {selected.tags.map((tag, i) => (
                                                    <Badge key={`${tagLabel(tag)}-${i}`} variant="secondary">{tagLabel(tag)}</Badge>
                                                ))}
                                            </div>
                                            <p className="mt-2 text-xs text-muted-foreground">Tag dipakai sebagai fallback nama ONU di tab FiberHome.</p>
                                        </Section>
                                    )}

                                    {(selected.parameter_summary || []).length > 0 && (
                                        <Section title="Parameter TR-069" icon={Router}>
                                            <div className="divide-y rounded-md border text-sm">
                                                {selected.parameter_summary!.map((row) => (
                                                    <div key={row.label} className="grid grid-cols-[130px_1fr] gap-2 px-3 py-2">
                                                        <span className="text-muted-foreground">{row.label}</span>
                                                        <span className="break-all font-mono text-xs">{row.value}</span>
                                                    </div>
                                                ))}
                                            </div>
                                        </Section>
                                    )}

                                    <div className="flex flex-col-reverse gap-2 border-t pt-4 sm:flex-row sm:justify-between">
                                        <div className="flex flex-wrap gap-2">
                                            {selected.is_fiberhome && (
                                                <Button asChild variant="outline" size="sm" className="gap-2">
                                                    <Link href={route('oltc300.index')}>
                                                        <ExternalLink className="h-4 w-4" />
                                                        Tab FiberHome
                                                    </Link>
                                                </Button>
                                            )}
                                        </div>
                                        <div className="flex flex-col-reverse gap-2 sm:flex-row">
                                            <Button variant="outline" onClick={() => setSelected(null)}>Tutup</Button>
                                            <Button variant="destructive" className="gap-2" onClick={() => setRebootConfirmOpen(true)} disabled={rebooting}>
                                                <Power className="h-4 w-4" />
                                                {rebooting ? 'Mengirim...' : 'Reboot CPE'}
                                            </Button>
                                        </div>
                                    </div>
                                </div>
                            )}
                        </>
                    )}
                </DialogContent>
            </Dialog>
            <ConfirmDestructive
                open={rebootConfirmOpen}
                onOpenChange={setRebootConfirmOpen}
                title="Reboot CPE?"
                description={`Perintah reboot akan dikirim ke ${selected?.serial_number || selected?.device_id || 'device ini'} melalui GenieACS.`}
                confirmationLabel="Saya memahami perangkat akan offline sementara."
                confirmText="Reboot CPE"
                onConfirm={rebootDevice}
                isLoading={rebooting}
            />
        </EnterpriseIndexLayout>
    );
}

function Section({ title, icon: Icon, children }: { title: string; icon: typeof Router; children: React.ReactNode }) {
    return (
        <div>
            <p className="mb-2 flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                <Icon className="h-3.5 w-3.5" /> {title}
            </p>
            {children}
        </div>
    );
}

function InfoGrid({ items }: { items: Array<{ label: string; value?: string | null; mono?: boolean; copy?: string | null; hint?: string }> }) {
    return (
        <div className="grid gap-3 sm:grid-cols-2">
            {items.map((item) => (
                <div key={item.label} className="rounded-md border border-border/60 bg-muted/20 px-3 py-2">
                    <p className="text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">{item.label}</p>
                    <div className="mt-1 flex items-center gap-1">
                        <p className={`text-sm ${item.mono ? 'font-mono text-xs break-all' : ''}`}>{item.value || '—'}</p>
                        {item.copy && (
                            <Button type="button" variant="ghost" size="icon" className="h-10 w-10 shrink-0 md:h-6 md:w-6" aria-label={`Salin ${item.label}`} title={`Salin ${item.label}`} onClick={() => copyText(item.copy!, item.label)}>
                                <Copy className="h-3 w-3" aria-hidden="true" />
                            </Button>
                        )}
                    </div>
                    {item.hint && <p className="mt-0.5 text-[10px] text-muted-foreground">{item.hint}</p>}
                </div>
            ))}
        </div>
    );
}
