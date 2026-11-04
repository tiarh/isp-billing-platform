/**
 * PSB WA panel embedded inside Aktivasi (not a separate sidebar page).
 * Lazy-loads data from GET /api/psb-wa/panel when mounted.
 */
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Skeleton } from '@/Components/ui/skeleton';
import { parseWaGroups, resolveWaGroupFetchHint, waGroupLabel, type WaGroup } from '@/lib/wa-groups';
import { readMetaCsrfToken, readXsrfCookieToken } from '@/lib/csrf';
import axios from 'axios';
import { Activity, Cable, Check, CheckCircle2, Loader2, RefreshCw, Settings2 } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { ConfirmDestructive } from '@/Components/ConfirmDestructive';
import { toast } from 'sonner';
import { formatWibDateTime } from '@/lib/dateTime';

type Draft = {
    id: number;
    status: string;
    full_name: string | null;
    pppoe_user: string | null;
    pppoe_password_available?: boolean;
    sn: string | null;
    pon_port: string | null;
    vlan: number | null;
    daerah: string | null;
    odp: string | null;
    rt: string | null;
    rw: string | null;
    phone: string | null;
    customer_id: number | null;
    last_error: string | null;
    ktp_photo_url: string | null;
    router_photo_url: string | null;
    media_review?: {
        ktp?: { status?: string; checked_at?: string };
        router?: { status?: string; checked_at?: string };
    } | null;
    created_at: string | null;
    /** RX dBm dari inventory OLT (DB) — bukan spam grup */
    rx_dbm?: number | null;
    has_secret?: boolean;
    has_olt?: boolean;
    customer_sync?: { status?: string; updated?: string[]; skipped?: string[] } | null;
    /** Draft diblok sebelum tulis device: area kosong/ambigu ("Perlu area"). */
    needs_area?: boolean;
    area_id?: number | null;
    area_resolution?: { status?: string; source?: string } | null;
    correction?: {
        status?: string;
        diff?: Record<string, { old?: string | number | null; new?: string | number | null }>;
        preflight?: Record<string, unknown>;
    } | null;
};

type SiteOption = {
    key: string;
    name: string;
    mode: string;
    vendor: string;
    router_id: number | null;
    olt_id: number | null;
    daily_target: number;
};

type SiteSetting = {
    router_id: number;
    package_id: number;
    olt_id: number;
    acs_url: string;
};

type WaSignal = {
    id: number;
    intent: string;
    domain: string;
    severity: string;
    summary: string | null;
    sender_name: string | null;
    group_label: string | null;
    created_at: string | null;
};

type PanelData = {
    drafts: Draft[];
    pagination?: {
        current_page: number;
        per_page: number;
        has_more: boolean;
        next_page: number | null;
    };
    filters: { status: string; site: string };
    settings: {
        psb_wa_group_ids: string[];
        psb_router_id: number;
        psb_package_id: number;
        psb_olt_id: number;
        psb_acs_url: string;
        site_settings?: Record<string, SiteSetting>;
        psb_recap?: { start: number; end: number; interval: number };
    };
    routers: { id: number; name: string; ip_address: string }[];
    packages: { id: number; name: string; mikrotik_profile: string | null }[];
    olts: { id: number; name: string; vendor: string | null }[];
    areas?: { id: number; name: string }[];
    sites: SiteOption[];
    today_count: number;
    evo_configured: boolean;
    evo_connected: boolean;
    health?: {
        status: string;
        label: string;
        wa_state: string;
        webhook_status: string;
        last_webhook_at: string | null;
        last_reply_at: string | null;
        queue_psb_size: number | null;
        message: string;
        checked_at: string;
    };
    voice_ops_signals?: WaSignal[];
    /** false for area-scoped admins: bot settings, KPI/CSV and health detail are global-admin only. */
    can_manage?: boolean;
};

const statusTone: Record<string, string> = {
    olt_ok: 'bg-status-up-bg text-status-up border-transparent',
    secret_ok: 'bg-status-info-bg text-status-info border-transparent',
    waiting_sn: 'bg-status-degraded-bg text-status-degraded border-transparent',
    pending_vlan: 'bg-status-degraded-bg text-status-degraded border-transparent',
    applied: 'bg-status-info-bg text-status-info border-transparent',
    correction_pending_review: 'bg-status-degraded-bg text-status-degraded border-transparent',
    failed: 'bg-status-down-bg text-status-down border-transparent',
    timeout: 'bg-muted text-muted-foreground border-transparent',
};

const STATUS_FILTERS = [
    { id: '', label: 'Semua' },
    { id: 'waiting_sn', label: 'Tunggu SN' },
    { id: 'pending_vlan', label: 'Pending VLAN' },
    { id: 'correction_pending_review', label: 'Koreksi' },
    { id: 'secret_ok', label: 'Secret OK' },
    { id: 'olt_ok', label: 'OLT OK' },
    { id: 'applied', label: 'Applied' },
    { id: 'failed', label: 'Failed' },
    { id: 'timeout', label: 'Timeout' },
];

function normalizeJid(raw: string): string {
    const j = raw.trim();
    if (!j) return '';
    return j.includes('@') ? j : `${j}@g.us`;
}

function recapSendHoursLabel(startRaw: string, endRaw: string, intervalRaw: string): string {
    const start = Number(startRaw);
    const end = Number(endRaw);
    const interval = Number(intervalRaw);
    const valid = [start, end, interval].every((n) => Number.isInteger(n))
        && start >= 0 && start <= 23 && end >= start && end <= 23 && interval >= 1 && interval <= 12;
    if (!valid) {
        return 'Pengaturan tidak valid: jam 0-23, jam selesai >= jam mulai, jarak 1-12 jam.';
    }
    const hours: string[] = [];
    for (let h = start; h <= end; h += interval) {
        hours.push(String(h).padStart(2, '0'));
    }
    return `Terkirim jam ${hours.join(', ')} WIB (hanya jika data berubah)`;
}

function csrfHeaders() {
    const csrf = readMetaCsrfToken() || readXsrfCookieToken() || '';
    return {
        Accept: 'application/json',
        'X-CSRF-TOKEN': csrf,
        'X-XSRF-TOKEN': readXsrfCookieToken() || csrf,
    };
}

export default function PsbWaPanel({ defaultAcsUrl = '' }: { defaultAcsUrl?: string }) {
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [statusFilter, setStatusFilter] = useState('');
    const [siteFilter, setSiteFilter] = useState('ra');
    const [data, setData] = useState<PanelData | null>(null);

    const [settingsOpen, setSettingsOpen] = useState(false);
    const [recapStart, setRecapStart] = useState('9');
    const [recapEnd, setRecapEnd] = useState('21');
    const [recapInterval, setRecapInterval] = useState('2');
    const [selectedGroups, setSelectedGroups] = useState<string[]>([]);
    const [manualJid, setManualJid] = useState('');
    const [groups, setGroups] = useState<WaGroup[]>([]);
    const [groupsLoading, setGroupsLoading] = useState(false);
    const [groupsHint, setGroupsHint] = useState<string | null>(null);
    const [groupsError, setGroupsError] = useState(false);
    const [saving, setSaving] = useState(false);
    // S2: per-row busy state so a double tap cannot provision twice. The ref
    // blocks a second call synchronously (before React re-renders).
    const [busyId, setBusyId] = useState<Record<number, string>>({});
    const [areaPick, setAreaPick] = useState<Record<number, string>>({});
    const busyRef = useRef<Set<number>>(new Set());
    const [pendingConfirm, setPendingConfirm] = useState<{ kind: 'apply' | 'correction'; draft: Draft } | null>(null);

    const [routerId, setRouterId] = useState(0);
    const [packageId, setPackageId] = useState(0);
    const [oltId, setOltId] = useState(0);
    const [acsUrl, setAcsUrl] = useState(defaultAcsUrl);
    const [siteSettings, setSiteSettings] = useState<Record<string, SiteSetting>>({});
    const [settingsSiteKey, setSettingsSiteKey] = useState('ra');

    const loadPanel = useCallback(async (
        status = statusFilter,
        site = siteFilter,
        page = 1,
        append = false,
    ) => {
        setLoading(true);
        setError(null);
        try {
            const params: Record<string, string> = {};
            if (status) params.status = status;
            if (site) params.site = site;
            params.page = String(page);
            params.per_page = '50';
            const res = await axios.get('/api/psb-wa/panel', {
                params,
                headers: { Accept: 'application/json' },
                timeout: 30000,
            });
            const payload = res.data as PanelData;
            setData((current) => append && current
                ? { ...payload, drafts: [...current.drafts, ...payload.drafts] }
                : payload);
            setSelectedGroups((payload.settings.psb_wa_group_ids || []).map(normalizeJid).filter(Boolean));
            setRouterId(payload.settings.psb_router_id || 0);
            setPackageId(payload.settings.psb_package_id || 0);
            setOltId(payload.settings.psb_olt_id || 0);
            setAcsUrl(payload.settings.psb_acs_url || defaultAcsUrl);
            setSiteSettings(payload.settings.site_settings || {});
            if (payload.settings.psb_recap) {
                setRecapStart(String(payload.settings.psb_recap.start));
                setRecapEnd(String(payload.settings.psb_recap.end));
                setRecapInterval(String(payload.settings.psb_recap.interval));
            }
            const activeConfig = payload.settings.site_settings?.[payload.filters?.site] || null;
            if (activeConfig) {
                setRouterId(activeConfig.router_id || 0);
                setPackageId(activeConfig.package_id || 0);
                setOltId(activeConfig.olt_id || 0);
                setAcsUrl(activeConfig.acs_url || defaultAcsUrl);
            }
            // Sync site filter from backend response
            if (payload.filters?.site) {
                setSiteFilter(payload.filters.site);
            }
        } catch (e: unknown) {
            const msg = axios.isAxiosError(e)
                ? e.response?.data?.message || e.message
                : 'Gagal memuat panel PSB WA';
            setError(String(msg));
        } finally {
            setLoading(false);
        }
    }, [statusFilter, siteFilter, defaultAcsUrl]);

    const loadMoreDrafts = () => {
        const nextPage = data?.pagination?.next_page;
        if (!nextPage || loading) return;
        void loadPanel(statusFilter, siteFilter, nextPage, true);
    };

    const loadHealth = useCallback(async () => {
        try {
            const res = await axios.get('/api/psb-wa/health', { timeout: 5000 });
            const full = res.data as {
                status?: string;
                label?: string;
                alerts?: string[];
                evolution?: { state?: string };
                webhook?: { status?: string; last_accepted_at?: string | null; last_received_at?: string | null };
                reply?: { last_reply_at?: string | null };
                queue?: { psb_size?: number | null };
                recommendation?: string;
                checked_at?: string;
            };
            const health = {
                status: full.status || 'unknown',
                label: full.label || 'Status WA belum diketahui',
                alerts: full.alerts || [],
                wa_state: full.evolution?.state || 'unknown',
                webhook_status: full.webhook?.status || 'unknown',
                last_webhook_at: full.webhook?.last_accepted_at || full.webhook?.last_received_at || null,
                last_reply_at: full.reply?.last_reply_at || null,
                queue_psb_size: full.queue?.psb_size ?? null,
                message: full.recommendation || 'Status WA diperbarui.',
                checked_at: full.checked_at || new Date().toISOString(),
            };
            setData((current) => current ? {
                ...current,
                evo_connected: health.wa_state === 'open',
                health,
            } : current);
        } catch {
            // Health is supplementary; the PSB operational list remains usable.
        }
    }, []);

    useEffect(() => {
        void loadPanel();
        void loadHealth();
    }, [loadPanel, loadHealth]);

    const fetchGroups = useCallback(async () => {
        setGroupsLoading(true);
        setGroupsError(false);
        try {
            const res = await axios.get('/api/attendances/groups', { timeout: 20000 });
            const parsed = parseWaGroups(res.data);
            setGroups(parsed);
            const payload = res.data && typeof res.data === 'object' ? (res.data as Record<string, unknown>) : null;
            setGroupsHint(resolveWaGroupFetchHint(payload, parsed.length, false));
        } catch (err) {
            setGroups([]);
            setGroupsError(true);
            const status = axios.isAxiosError(err) ? err.response?.status : null;
            setGroupsHint(resolveWaGroupFetchHint(null, 0, true, status));
        } finally {
            setGroupsLoading(false);
        }
    }, []);

    useEffect(() => {
        if (settingsOpen) void fetchGroups();
    }, [settingsOpen, fetchGroups]);

    const groupLabelMap = useMemo(() => {
        const m = new Map<string, string>();
        for (const g of groups) m.set(g.id, waGroupLabel(g));
        return m;
    }, [groups]);

    const selectedSummary = useMemo(() => {
        if (selectedGroups.length === 0) return 'Belum pilih grup';
        return selectedGroups
            .map((id) => groupLabelMap.get(id) || id.replace(/@g\.us$/, '').slice(0, 16) + '…')
            .join(', ');
    }, [selectedGroups, groupLabelMap]);

    const toggleGroup = (jid: string) => {
        const id = normalizeJid(jid);
        if (!id) return;
        setSelectedGroups((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]));
    };

    const addManualJid = () => {
        const id = normalizeJid(manualJid);
        if (!id) {
            toast.error('JID kosong');
            return;
        }
        setSelectedGroups((prev) => (prev.includes(id) ? prev : [...prev, id]));
        setManualJid('');
        toast.success('Grup ditambahkan');
    };

    const selectSettingsSite = (key: string) => {
        const config = siteSettings[key];
        setSettingsSiteKey(key);
        setRouterId(config?.router_id || 0);
        setPackageId(config?.package_id || 0);
        setOltId(config?.olt_id || 0);
        setAcsUrl(config?.acs_url || defaultAcsUrl);
    };

    const saveSettings = async () => {
        setSaving(true);
        try {
            const nextSiteSettings = {
                ...siteSettings,
                [settingsSiteKey]: {
                    router_id: routerId,
                    package_id: packageId,
                    olt_id: oltId,
                    acs_url: acsUrl,
                },
            };
            await axios.post(
                '/psb-wa/settings',
                {
                    psb_wa_group_ids: selectedGroups,
                    psb_site_settings: nextSiteSettings,
                    psb_recap_start_hour: Number(recapStart),
                    psb_recap_end_hour: Number(recapEnd),
                    psb_recap_interval_hours: Number(recapInterval),
                },
                { headers: csrfHeaders() },
            );
            setSiteSettings(nextSiteSettings);
            toast.success('Pengaturan PSB WA disimpan');
            setSettingsOpen(false);
            await loadPanel();
        } catch (e: unknown) {
            toast.error(axios.isAxiosError(e) ? e.response?.data?.message || e.message : 'Gagal simpan');
        } finally {
            setSaving(false);
        }
    };

    const runRowAction = async (id: number, action: string, fn: () => Promise<void>) => {
        if (busyRef.current.has(id)) return;
        busyRef.current.add(id);
        setBusyId((prev) => ({ ...prev, [id]: action }));
        try {
            await fn();
        } finally {
            busyRef.current.delete(id);
            setBusyId((prev) => {
                const next = { ...prev };
                delete next[id];
                return next;
            });
        }
    };

    const applyDraft = (id: number) => runRowAction(id, 'apply', async () => {
        try {
            const res = await axios.post(`/psb-wa/${id}/apply`, {}, { headers: csrfHeaders() });
            toast.success(res.data?.message || 'Applied');
            await loadPanel();
        } catch (e: unknown) {
            toast.error(axios.isAxiosError(e) ? e.response?.data?.message || e.message : 'Gagal apply');
        }
    });

    const retryDraft = (id: number) => runRowAction(id, 'retry', async () => {
        try {
            const res = await axios.post(`/psb-wa/${id}/retry`, {}, { headers: csrfHeaders() });
            toast.success(res.data?.message || 'Retry dikirim');
            await loadPanel();
        } catch (e: unknown) {
            toast.error(axios.isAxiosError(e) ? e.response?.data?.message || e.message : 'Gagal retry');
        }
    });

    const setDraftArea = (id: number) => runRowAction(id, 'set-area', async () => {
        const areaId = parseInt(areaPick[id] || '', 10);
        if (!areaId) {
            toast.error('Pilih area dulu');
            return;
        }
        try {
            const res = await axios.post(`/psb-wa/${id}/set-area`, { area_id: areaId }, { headers: csrfHeaders() });
            toast.success(res.data?.message || 'Area disimpan, draft di-retry');
            await loadPanel();
        } catch (e: unknown) {
            toast.error(axios.isAxiosError(e) ? e.response?.data?.message || e.message : 'Gagal set area');
        }
    });

    const applyCorrection = (id: number) => runRowAction(id, 'correction', async () => {
        try {
            const res = await axios.post(`/psb-wa/${id}/apply-correction`, {}, { headers: csrfHeaders() });
            toast.success(res.data?.message || `Koreksi draft #${id} diproses`);
            await loadPanel();
        } catch (err) {
            const msg = axios.isAxiosError(err) ? err.response?.data?.message : null;
            toast.error(msg || `Gagal apply koreksi draft #${id}`);
        }
    });

    const cancelCorrection = (id: number) => runRowAction(id, 'cancel-correction', async () => {
        try {
            const res = await axios.post(`/psb-wa/${id}/cancel-correction`, {}, { headers: csrfHeaders() });
            toast.success(res.data?.message || `Koreksi draft #${id} dibatalkan`);
            await loadPanel();
        } catch (err) {
            const msg = axios.isAxiosError(err) ? err.response?.data?.message : null;
            toast.error(msg || `Gagal batalkan koreksi draft #${id}`);
        }
    });

    const confirmPending = async () => {
        if (!pendingConfirm) return;
        const { kind, draft } = pendingConfirm;
        if (kind === 'apply') {
            await applyDraft(draft.id);
        } else {
            await applyCorrection(draft.id);
        }
        setPendingConfirm(null);
    };

    const syncCustomerMedia = (id: number) => runRowAction(id, 'sync', async () => {
        try {
            const res = await axios.post(`/psb-wa/${id}/sync-customer-media`, {}, { headers: csrfHeaders() });
            toast.success(res.data?.message || 'Sync customer selesai');
            await loadPanel();
        } catch (e: unknown) {
            toast.error(axios.isAxiosError(e) ? e.response?.data?.message || e.message : 'Gagal sync customer');
        }
    });

    // Always string so Radix Select stays controlled (never undefined).
    const selectVal = (n: number) => (n > 0 ? String(n) : '__none__');

    if (loading && !data) {
        return (
            <Card>
                <CardContent className="space-y-4 py-8">
                    <Skeleton className="h-5 w-48" />
                    <Skeleton className="h-4 w-full max-w-xl" />
                    <div className="grid gap-3 sm:grid-cols-3">
                        <Skeleton className="h-24 w-full" />
                        <Skeleton className="h-24 w-full sm:col-span-2" />
                        <Skeleton className="h-32 w-full sm:col-span-2" />
                    </div>
                </CardContent>
            </Card>
        );
    }

    if (error && !data) {
        return (
            <Card>
                <CardContent className="space-y-3 py-10 text-center">
                    <p className="text-sm text-status-down">{error}</p>
                    <Button onClick={() => void loadPanel()}>Coba lagi</Button>
                </CardContent>
            </Card>
        );
    }

    const drafts = data?.drafts || [];
    const routers = data?.routers || [];
    const packages = data?.packages || [];
    const olts = data?.olts || [];
    const sites = data?.sites || [];
    const todayCount = data?.today_count ?? 0;
    const evoConfigured = data?.evo_configured ?? false;
    const evoConnected = data?.evo_connected ?? false;
    const health = data?.health;
    const voiceOpsSignals = data?.voice_ops_signals || [];

    return (
        <div className="w-full max-w-5xl space-y-4 overflow-x-hidden">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div className="min-w-0 space-y-1">
                    <h2 className="text-base font-semibold sm:text-lg">PSB WA · Auto FiberHome</h2>
                    <p className="text-xs text-muted-foreground break-words">
                        Grup: <span className="font-medium text-foreground">{selectedSummary}</span>
                    </p>
                    <p className="text-[11px] text-muted-foreground">
                        Chat: <code className="rounded bg-muted px-1">#psb</code> ·{' '}
                        <code className="rounded bg-muted px-1">done</code> ·{' '}
                        <code className="rounded bg-muted px-1">/psb</code>
                    </p>
                </div>
                <div className="flex shrink-0 gap-2">
                    {data?.can_manage === true && (
                        <Button type="button" variant="outline" size="sm" className="h-9" asChild>
                            <a href={route('psb-drafts.dashboard')}>KPI / CSV</a>
                        </Button>
                    )}
                    <Button type="button" variant="outline" size="sm" className="h-9" onClick={() => void loadPanel()}>
                        <RefreshCw className={`mr-1.5 h-3.5 w-3.5 ${loading ? 'animate-spin' : ''}`} />
                        Refresh
                    </Button>
                    {data?.can_manage === true && (
                    <Dialog open={settingsOpen} onOpenChange={(open) => {
                        if (open) {
                            selectSettingsSite(siteFilter && siteFilter !== '__all__' ? siteFilter : 'ra');
                        }
                        setSettingsOpen(open);
                    }}>
                        <DialogTrigger asChild>
                            <Button type="button" size="sm" className="h-9">
                                <Settings2 className="mr-1.5 h-3.5 w-3.5" />
                                Pilih grup
                            </Button>
                        </DialogTrigger>
                        <DialogContent className="max-h-[90dvh] w-[calc(100%-1.5rem)] max-w-lg overflow-y-auto p-4 sm:p-6">
                            <DialogHeader className="text-left">
                                <DialogTitle>Pengaturan bot #psb</DialogTitle>
                                <DialogDescription>
                                    Pilih grup WA dan target PSB per-site. Tersimpan di database (aman redeploy).
                                </DialogDescription>
                            </DialogHeader>

                            <div className="space-y-5 py-2">
                                <div className="space-y-2">
                                    <div className="flex items-center justify-between gap-2">
                                        <Label>Grup WhatsApp</Label>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            className="h-8 px-2 text-xs"
                                            disabled={groupsLoading}
                                            onClick={() => void fetchGroups()}
                                        >
                                            {groupsLoading ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : (
                                                <><RefreshCw className="mr-1 h-3.5 w-3.5" />Muat ulang</>
                                            )}
                                        </Button>
                                    </div>

                                    {groupsLoading && groups.length === 0 ? (
                                        <div className="space-y-2 rounded-md border p-2" aria-label="Memuat daftar grup">
                                            <Skeleton className="h-10 w-full" />
                                            <Skeleton className="h-10 w-full" />
                                            <Skeleton className="h-10 w-full" />
                                        </div>
                                    ) : groups.length > 0 ? (
                                        <div className="max-h-52 space-y-1 overflow-y-auto overscroll-contain rounded-md border p-2">
                                            {groups.map((g) => {
                                                const on = selectedGroups.includes(g.id);
                                                return (
                                                    <button
                                                        key={g.id}
                                                        type="button"
                                                        onClick={() => toggleGroup(g.id)}
                                                        className={`flex w-full items-start gap-3 rounded-md px-3 py-2.5 text-left text-sm ${
                                                            on
                                                                ? 'bg-primary-soft text-primary-soft-foreground'
                                                                : 'hover:bg-muted/80'
                                                        }`}
                                                    >
                                                        <span
                                                            className={`mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded border text-[11px] ${
                                                                on ? 'border-primary bg-primary text-primary-foreground' : 'border-muted-foreground/40'
                                                            }`}
                                                        >
                                                            {on ? <Check className="h-3 w-3" aria-hidden="true" /> : null}
                                                        </span>
                                                        <span className="min-w-0 flex-1">
                                                            <span className="block font-medium break-words">{waGroupLabel(g)}</span>
                                                            <span className="mt-0.5 block font-mono text-[10px] text-muted-foreground break-all">{g.id}</span>
                                                        </span>
                                                    </button>
                                                );
                                            })}
                                        </div>
                                    ) : (
                                        <p className="rounded-md border border-dashed px-3 py-3 text-xs text-muted-foreground">
                                            {groupsHint ||
                                                (!evoConfigured
                                                    ? 'Evolution API belum dikonfigurasi — paste JID manual.'
                                                    : !evoConnected
                                                        ? 'WA gateway belum connected — paste JID atau pair dulu.'
                                                        : 'Daftar grup kosong — paste JID manual di bawah.')}
                                        </p>
                                    )}

                                    {selectedGroups.length > 0 && (
                                        <div className="flex flex-wrap gap-1.5">
                                            {selectedGroups.map((id) => (
                                                <button
                                                    key={id}
                                                    type="button"
                                                    onClick={() => toggleGroup(id)}
                                                    className="inline-flex max-w-full items-center gap-1 rounded-full border border-transparent bg-primary-soft px-2.5 py-1 text-[11px] text-primary-soft-foreground"
                                                >
                                                    <span className="truncate">{groupLabelMap.get(id) || id}</span>
                                                    <span>×</span>
                                                </button>
                                            ))}
                                        </div>
                                    )}

                                    <div className="flex flex-col gap-2 sm:flex-row">
                                        <Input
                                            value={manualJid}
                                            onChange={(e) => setManualJid(e.target.value)}
                                            placeholder="Paste JID: 120363…@g.us"
                                            className="min-w-0 flex-1 font-mono text-xs"
                                            onKeyDown={(e) => {
                                                if (e.key === 'Enter') {
                                                    e.preventDefault();
                                                    addManualJid();
                                                }
                                            }}
                                        />
                                        <Button type="button" variant="secondary" className="shrink-0" onClick={addManualJid}>
                                            Tambah
                                        </Button>
                                    </div>
                                </div>

                                <div className="grid gap-3">
                                    <div className="space-y-1.5">
                                        <Label>Site PSB</Label>
                                        <Select value={settingsSiteKey} onValueChange={selectSettingsSite}>
                                            <SelectTrigger className="w-full"><SelectValue placeholder="Pilih site PSB" /></SelectTrigger>
                                            <SelectContent position="popper" className="max-h-60">
                                                {(sites.filter((s) => ['full_olt', 'huawei_olt'].includes(s.mode))).map((s) => (
                                                    <SelectItem key={s.key} value={s.key}>{s.name}</SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <p className="text-[11px] text-muted-foreground">
                                            Konfigurasi disimpan per-site; Pilihan Gadang tidak mengganti RA/C300.
                                        </p>
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label>Router target</Label>
                                        <Select value={selectVal(routerId)} onValueChange={(v) => setRouterId(v === '__none__' ? 0 : parseInt(v, 10) || 0)}>
                                            <SelectTrigger className="w-full"><SelectValue placeholder="Pilih router" /></SelectTrigger>
                                            <SelectContent position="popper" className="max-h-60">
                                                <SelectItem value="__none__">Pilih router</SelectItem>
                                                {routers.map((r) => (
                                                    <SelectItem key={r.id} value={String(r.id)}>{r.name} ({r.ip_address})</SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label>Package / profile</Label>
                                        <Select value={selectVal(packageId)} onValueChange={(v) => setPackageId(v === '__none__' ? 0 : parseInt(v, 10) || 0)}>
                                            <SelectTrigger className="w-full"><SelectValue placeholder="200M+ STARTLITE" /></SelectTrigger>
                                            <SelectContent position="popper" className="max-h-60">
                                                <SelectItem value="__none__">Pilih package</SelectItem>
                                                {packages.map((p) => (
                                                    <SelectItem key={p.id} value={String(p.id)}>
                                                        {p.name}{p.mikrotik_profile ? ` · ${p.mikrotik_profile}` : ''}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label>OLT target</Label>
                                        <Select value={selectVal(oltId)} onValueChange={(v) => setOltId(v === '__none__' ? 0 : parseInt(v, 10) || 0)}>
                                            <SelectTrigger className="w-full"><SelectValue placeholder="Pilih OLT" /></SelectTrigger>
                                            <SelectContent position="popper" className="max-h-60">
                                                <SelectItem value="__none__">Pilih OLT</SelectItem>
                                                {olts.map((o) => (
                                                    <SelectItem key={o.id} value={String(o.id)}>{o.name} ({o.vendor || '?'})</SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label>ACS URL</Label>
                                        <Input value={acsUrl} onChange={(e) => setAcsUrl(e.target.value)} className="font-mono text-xs" />
                                    </div>
                                </div>
                            </div>

                            <div className="space-y-2 rounded-md border p-3">
                                <div className="text-sm font-medium">Rekap PSB otomatis</div>
                                <div className="grid gap-3 sm:grid-cols-3">
                                    <div className="space-y-1.5">
                                        <Label>Rekap PSB otomatis: jam mulai (WIB)</Label>
                                        <Input type="number" min={0} max={23} inputMode="numeric" value={recapStart} onChange={(e) => setRecapStart(e.target.value)} />
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label>Rekap PSB otomatis: jam selesai (WIB)</Label>
                                        <Input type="number" min={0} max={23} inputMode="numeric" value={recapEnd} onChange={(e) => setRecapEnd(e.target.value)} />
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label>Rekap PSB otomatis: jarak (jam)</Label>
                                        <Input type="number" min={1} max={12} inputMode="numeric" value={recapInterval} onChange={(e) => setRecapInterval(e.target.value)} />
                                    </div>
                                </div>
                                <p className="text-xs text-muted-foreground">{recapSendHoursLabel(recapStart, recapEnd, recapInterval)}</p>
                            </div>

                            <DialogFooter className="flex-col gap-2 sm:flex-row">
                                <Button type="button" variant="outline" className="w-full sm:w-auto" onClick={() => setSettingsOpen(false)}>
                                    Batal
                                </Button>
                                <Button type="button" className="w-full sm:w-auto" disabled={saving} onClick={() => void saveSettings()}>
                                    {saving ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <Cable className="mr-2 h-4 w-4" />}
                                    Simpan
                                </Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
                    )}
                </div>
            </div>

            <div className="grid gap-3 sm:grid-cols-3">
                <Card>
                    <CardHeader className="px-4 pb-2 pt-4">
                        <CardTitle className="text-sm text-muted-foreground">Draft hari ini</CardTitle>
                    </CardHeader>
                    <CardContent className="px-4 pb-4">
                        <div className="text-3xl font-semibold tabular-nums">{todayCount}</div>
                    </CardContent>
                </Card>
                <Card className="sm:col-span-2">
                    <CardHeader className="px-4 pb-2 pt-4">
                        <div className="flex items-start justify-between gap-3">
                            <div>
                                <CardTitle className="text-sm">Health Bot PSB WA</CardTitle>
                                <CardDescription className="text-xs">{health?.message || 'Status webhook, WA, dan queue PSB.'}</CardDescription>
                            </div>
                            <Badge
                                variant="outline"
                                className={health?.status === 'normal'
                                    ? 'border-transparent bg-status-up-bg text-status-up'
                                    : 'border-transparent bg-status-degraded-bg text-status-degraded'}
                            >
                                <Activity className="mr-1 h-3 w-3" />
                                {health?.label || (evoConnected ? 'Normal' : 'Cek WA')}
                            </Badge>
                        </div>
                    </CardHeader>
                    <CardContent className="grid gap-2 px-4 pb-4 text-xs text-muted-foreground sm:grid-cols-4">
                        <div><span className="block text-[11px]">WA</span><span className="font-medium text-foreground">{health?.wa_state || (evoConnected ? 'open' : 'unknown')}</span></div>
                        <div><span className="block text-[11px]">Webhook</span><span className="font-medium text-foreground">{health?.webhook_status || 'unknown'}</span></div>
                        <div><span className="block text-[11px]">Queue PSB</span><span className="font-medium text-foreground">{health?.queue_psb_size ?? '-'}</span></div>
                        <div><span className="block text-[11px]">Last reply</span><span className="font-medium text-foreground">{formatWibDateTime(health?.last_reply_at)}</span></div>
                        {data?.can_manage === true && (
                            <div className="sm:col-span-4">
                                <Button type="button" variant="outline" size="sm" className="h-8" asChild>
                                    <a href="/psb-wa/health" target="_blank" rel="noreferrer">Buka health detail</a>
                                </Button>
                            </div>
                        )}
                    </CardContent>
                </Card>
                <Card className="sm:col-span-2">
                    <CardHeader className="px-4 pb-2 pt-4">
                        <CardTitle className="text-sm">Cara pakai</CardTitle>
                        <CardDescription className="text-xs">Hanya grup yang dipilih di tombol «Pilih grup»</CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-1 px-4 pb-4 text-xs text-muted-foreground">
                        <p>Teknisi: <code className="rounded bg-muted px-1">#psb</code> + data + foto</p>
                        <p>NOC VLAN: <code className="rounded bg-muted px-1">done</code> / <code className="rounded bg-muted px-1">done #ID</code></p>
                        <p>Rekap: <code className="rounded bg-muted px-1">/psb</code> · Cek: <code className="rounded bg-muted px-1">#psb cek</code> · Antri: <code className="rounded bg-muted px-1">#psb antri</code></p>
                        <p>Live PPPoE di WA: <code className="rounded bg-muted px-1">#psb cek #ID</code> (on-demand, anti-spam)</p>
                    </CardContent>
                </Card>
            </div>

            <Card>
                <CardHeader className="pb-3">
                    <CardTitle className="text-sm">Sinyal Grup Aktivasi</CardTitle>
                    <CardDescription>Read-only dari chat, tidak membuat PSB/migrasi/PPPoE otomatis.</CardDescription>
                </CardHeader>
                <CardContent>
                    {voiceOpsSignals.length === 0 ? (
                        <p className="text-sm text-muted-foreground">Belum ada sinyal grup aktivasi.</p>
                    ) : (
                        <div className="grid gap-2 md:grid-cols-2">
                            {voiceOpsSignals.map((s) => (
                                <div key={s.id} className="rounded-md border bg-background/60 p-3 text-sm">
                                    <div className="flex items-center justify-between gap-2">
                                        <Badge variant="outline">{s.intent}</Badge>
                                        <span className="text-xs text-muted-foreground">{s.created_at}</span>
                                    </div>
                                    <p className="mt-2 line-clamp-2">{s.summary || '-'}</p>
                                </div>
                            ))}
                        </div>
                    )}
                </CardContent>
            </Card>

            <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                <Select value={siteFilter || 'ra'} onValueChange={(v) => {
                    const newSite = v;
                    setSiteFilter(newSite);
                }}>
                    <SelectTrigger className="h-8 w-full sm:w-48 text-xs">
                        <SelectValue placeholder="Pilih site" />
                    </SelectTrigger>
                    <SelectContent position="popper" className="max-h-60">
                        <SelectItem value="__all__">Semua Area</SelectItem>
                        {sites.map((s) => (
                            <SelectItem key={s.key} value={s.key}>{s.name}</SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <div className="-mx-1 flex gap-1.5 overflow-x-auto px-1 pb-1">
                    {STATUS_FILTERS.map((s) => (
                        <Button
                            key={s.id || 'all'}
                            size="sm"
                            variant={statusFilter === s.id ? 'default' : 'outline'}
                            className="h-8 shrink-0 rounded-full px-3 text-xs"
                            onClick={() => {
                                setStatusFilter(s.id);
                            }}
                        >
                            {s.label}
                        </Button>
                    ))}
                </div>
            </div>

            <div className="space-y-3">
                {drafts.length === 0 && (
                    <Card>
                        <CardContent className="py-10 text-center text-sm text-muted-foreground">
                            Belum ada draft. Pilih grup dulu, lalu teknisi kirim #psb.
                        </CardContent>
                    </Card>
                )}
                {drafts.map((d) => (
                    <Card key={d.id} className="overflow-hidden">
                        <CardContent className="space-y-3 p-4">
                            <div className="flex flex-wrap items-start justify-between gap-2">
                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="font-medium break-words">#{d.id} {d.full_name || '—'}</span>
                                        <Badge variant="outline" className={`text-[10px] ${statusTone[d.status] || ''}`}>{d.status}</Badge>
                                        {d.needs_area && (
                                            <Badge variant="warning" className="text-[10px]">
                                                Perlu area
                                            </Badge>
                                        )}
                                        {d.has_secret && (
                                            <Badge variant="info" className="text-[10px]">
                                                Secret
                                            </Badge>
                                        )}
                                        {d.has_olt && (
                                            <Badge variant="up" className="text-[10px]">
                                                OLT OK
                                            </Badge>
                                        )}
                                        {d.rx_dbm != null && Number.isFinite(d.rx_dbm) && (
                                            <Badge variant="info" className="text-[10px]">
                                                RX {d.rx_dbm.toFixed(1)} dBm
                                            </Badge>
                                        )}
                                    </div>
                                    {d.created_at && <p className="text-[11px] text-muted-foreground">{d.created_at}</p>}
                                </div>
                                <div className="flex w-full flex-wrap gap-2 sm:w-auto" aria-busy={Boolean(busyId[d.id])}>
                                    {['waiting_sn', 'pending_vlan', 'failed', 'timeout', 'secret_ok'].includes(d.status) && (
                                        <Button size="sm" variant="outline" className="h-9 flex-1 sm:flex-none" disabled={Boolean(busyId[d.id])} onClick={() => void retryDraft(d.id)}>
                                            {busyId[d.id] === 'retry' ? <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" /> : <RefreshCw className="mr-1.5 h-3.5 w-3.5" />} Retry
                                        </Button>
                                    )}
                                    {d.status === 'correction_pending_review' && (
                                        <>
                                            <Button size="sm" variant="outline" className="h-9 flex-1 sm:flex-none" disabled={Boolean(busyId[d.id])} onClick={() => setPendingConfirm({ kind: 'correction', draft: d })}>
                                                {busyId[d.id] === 'correction' && <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" />}
                                                Apply Koreksi
                                            </Button>
                                            <Button size="sm" variant="ghost" className="h-9 flex-1 sm:flex-none" disabled={Boolean(busyId[d.id])} onClick={() => void cancelCorrection(d.id)}>
                                                {busyId[d.id] === 'cancel-correction' && <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" />}
                                                Batalkan Koreksi
                                            </Button>
                                        </>
                                    )}
                                    {d.customer_id && d.status !== 'applied' && (
                                        <Button size="sm" className="h-9 flex-1 sm:flex-none" disabled={Boolean(busyId[d.id])} onClick={() => setPendingConfirm({ kind: 'apply', draft: d })}>
                                            {busyId[d.id] === 'apply' ? <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" /> : <CheckCircle2 className="mr-1.5 h-3.5 w-3.5" />} Apply
                                        </Button>
                                    )}
                                    {d.customer_id && (
                                        <Button size="sm" variant="outline" className="h-9 flex-1 sm:flex-none" disabled={Boolean(busyId[d.id])} onClick={() => void syncCustomerMedia(d.id)}>
                                            {busyId[d.id] === 'sync' && <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" />}
                                            Kirim ke Data Pelanggan
                                        </Button>
                                    )}
                                </div>
                            </div>
                            <div className="rounded-md bg-muted/50 px-2.5 py-2 font-mono text-[11px] text-muted-foreground break-all">
                                <div>{d.pppoe_user || '—'}</div>
                                {d.pppoe_password_available && <div>password: tersedia di router</div>}
                                <div className="mt-0.5 flex flex-wrap gap-x-2">
                                    {d.vlan != null && <span>VLAN {d.vlan}</span>}
                                    {d.sn && <span>SN {d.sn}</span>}
                                    {d.pon_port && <span>PON {d.pon_port}</span>}
                                </div>
                            </div>
                            <p className="text-xs text-muted-foreground break-words">
                                ODP {d.odp || '—'} · RT{d.rt}/RW{d.rw} · {d.daerah || '—'}
                                {d.phone ? ` · ${d.phone}` : ''}
                            </p>
                            {d.last_error && <p className="text-xs text-status-down break-words">{d.last_error}</p>}
                            {d.needs_area && (
                                <div className="flex flex-wrap items-center gap-2 rounded-md border border-status-degraded/30 bg-status-degraded-bg p-2" aria-busy={busyId[d.id] === 'set-area'}>
                                    <Select value={areaPick[d.id] || ''} onValueChange={(v) => setAreaPick((prev) => ({ ...prev, [d.id]: v }))}>
                                        <SelectTrigger className="h-9 w-full sm:w-64"><SelectValue placeholder="Pilih area untuk draft ini" /></SelectTrigger>
                                        <SelectContent position="popper" className="max-h-60">
                                            {(data?.areas ?? []).map((a) => (
                                                <SelectItem key={a.id} value={String(a.id)}>{a.name}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <Button size="sm" className="h-9" disabled={Boolean(busyId[d.id]) || !areaPick[d.id]} onClick={() => void setDraftArea(d.id)}>
                                        {busyId[d.id] === 'set-area' && <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" />}
                                        Set area &amp; Retry
                                    </Button>
                                </div>
                            )}
                            {d.correction?.status && (
                                <div className="rounded-md border border-status-degraded/30 bg-status-degraded-bg px-2.5 py-2 text-[11px] text-status-degraded">
                                    <div className="font-medium">Koreksi provisioning: {d.correction.status}</div>
                                    {d.correction.diff && Object.entries(d.correction.diff).slice(0, 5).map(([key, value]) => (
                                        <div key={key} className="font-mono break-all">
                                            {key}: {String(value.old ?? '-')} → {String(value.new ?? '-')}
                                        </div>
                                    ))}
                                </div>
                            )}
                            {d.customer_sync?.status && (
                                <p className="text-[11px] text-muted-foreground">
                                    Media pelanggan: {d.customer_sync.status}
                                    {d.customer_sync.updated?.length ? ` · ${d.customer_sync.updated.join(', ')}` : ''}
                                </p>
                            )}
                            {(d.ktp_photo_url || d.router_photo_url) && (
                                <div className="flex flex-wrap gap-3">
                                    {d.ktp_photo_url && (
                                        <a href={d.ktp_photo_url} target="_blank" rel="noreferrer" className="text-xs font-medium text-primary underline">Foto KTP</a>
                                    )}
                                    {d.router_photo_url && (
                                        <a href={d.router_photo_url} target="_blank" rel="noreferrer" className="text-xs font-medium text-primary underline">Foto router</a>
                                    )}
                                </div>
                            )}
                            {d.media_review?.ktp?.status && (
                                <p className="text-xs font-medium text-status-degraded">
                                    Foto KTP perlu dikirim ulang
                                </p>
                            )}
                        </CardContent>
                    </Card>
                ))}
                {data?.pagination?.has_more && (
                    <div className="flex justify-center pt-1">
                        <Button type="button" variant="outline" onClick={loadMoreDrafts} disabled={loading}>
                            {loading ? 'Memuat…' : 'Muat 50 draft berikutnya'}
                        </Button>
                    </div>
                )}
            </div>
            <ConfirmDestructive
                open={pendingConfirm !== null}
                onOpenChange={(open) => {
                    if (!open && !(pendingConfirm && busyId[pendingConfirm.draft.id])) setPendingConfirm(null);
                }}
                title={pendingConfirm?.kind === 'correction'
                    ? `Apply koreksi draft #${pendingConfirm.draft.id}?`
                    : `Apply draft #${pendingConfirm?.draft.id ?? ''}?`}
                description={pendingConfirm?.kind === 'correction'
                    ? `Koreksi provisioning untuk ${pendingConfirm.draft.full_name || 'pelanggan ini'} (${pendingConfirm.draft.pppoe_user || '-'}) akan ditulis ke perangkat (MikroTik/OLT) sesuai perubahan yang ditampilkan. Pastikan preflight sudah dicek NOC.`
                    : `Draft #${pendingConfirm?.draft.id ?? ''} (${pendingConfirm?.draft.full_name || '-'}, ${pendingConfirm?.draft.pppoe_user || '-'}) ditandai selesai dan pelanggan #${pendingConfirm?.draft.customer_id ?? '-'} diaktifkan (status Aktif) di eBilling, lalu data media disinkronkan.`}
                confirmationLabel={pendingConfirm?.kind === 'correction'
                    ? 'Saya sudah memeriksa perubahan koreksi dan preflight.'
                    : 'Saya sudah memeriksa data draft ini.'}
                confirmText={pendingConfirm?.kind === 'correction' ? 'Apply koreksi' : 'Apply draft'}
                onConfirm={() => void confirmPending()}
                isLoading={pendingConfirm ? Boolean(busyId[pendingConfirm.draft.id]) : false}
            />
        </div>
    );
}
