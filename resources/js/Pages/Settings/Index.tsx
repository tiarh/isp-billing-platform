import { useState, useEffect } from 'react';
import { Link } from '@inertiajs/react';
import { formatGroupLabel } from '@/Components/WaGroups/types';
import { EnterpriseIndexLayout } from '@/Components/enterprise/EnterpriseIndexLayout';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';

import { Switch } from '@/Components/ui/switch';
import { Badge } from '@/Components/ui/badge';
import { Skeleton } from '@/Components/ui/skeleton';
import { ScrollArea } from '@/Components/ui/scroll-area';
import { Separator } from '@/Components/ui/separator';
import { ConfirmDestructive } from '@/Components/ConfirmDestructive';
import { toast } from 'sonner';
import {
    Save,
    Settings,
    MessageCircle,
    QrCode,
    Link2,
    Unlink,
    Send,
    RefreshCw,
    Loader2,
    CheckCircle2,
    XCircle,
    Zap,
} from 'lucide-react';

interface WaGroup {
    id: string;
    name: string;
    subject?: string;
}

interface WaConfig {
    evo_base_url: string;
    evo_api_key_configured?: boolean;
    evo_instance_name: string;
    evo_delay_ms?: number;
    evo_group_id?: string;
}

interface BillingWaConfig {
    payment_enabled: boolean;
    customer_created_enabled: boolean;
    isolation_enabled: boolean;
    isolation_deferred: boolean;
    billing_sending_enabled?: boolean;
    broadcast_sending_enabled?: boolean;
    live_sending_enabled?: boolean;
    payment_template: string;
    customer_created_template: string;
    isolation_template: string;
    area_templates: Record<string, { payment?: string; customer_created?: string; isolation?: string }>;
    placeholders: string[];
    last_invoice_sync_at?: string | null;
}

interface FinanceBulkWaConfig {
    enabled: boolean;
    dry_run: boolean;
    max_recipients: number;
    rate_limit_per_minute: number;
    allowed_roles: string[];
    confirmation_text: string;
}

const billingPreviewValues: Record<string, string> = {
    '{name}': 'Contoh Pelanggan',
    '{code}': 'DEMO-001',
    '{phone}': '081200000000',
    '{area}': 'Contoh Area',
    '{package}': 'Paket 100M',
    '{amount}': 'Rp 200.000',
    '{billing_amount}': 'Rp 200.000',
    '{invoice_code}': 'INV-DEMO-2822399',
    '{due_date}': '31/08/2026',
    '{paid_at}': '22/08/2026 12:34',
    '{period}': 'Agustus 2026',
    '{payment_link}': 'https://pay.example.test/pay/PREVIEW_ONLY',
    '{receipt_link}': 'https://pay.example.test/invoices/999999/receipt?expires=PREVIEW_ONLY&signature=PREVIEW_ONLY',
    '{company_name}': 'Skynet Network',
};

function renderBillingPreview(template: string): string {
    const source = template.trim() || 'Halo {name},\n\nPembayaran invoice {invoice_code} sudah kami terima.\n\nKuitansi: {receipt_link}';
    const rendered = Object.entries(billingPreviewValues).reduce(
        (result, [placeholder, value]) => result.split(placeholder).join(value),
        source,
    );

    return source.includes('{receipt_link}')
        ? rendered
        : `${rendered.trimEnd()}\n\nKuitansi atas nama: ${billingPreviewValues['{name}']}\n${billingPreviewValues['{receipt_link}']}`;
}

interface TechnicianProgressConfig {
    share_group_jid: string;
    share_group_label?: string;
    input_group_jid: string;
    input_group_label?: string;
    preview_time?: string;
    morning_send_time?: string;
    summary_times?: string;
    auto_send_enabled?: boolean;
    summary_enabled?: boolean;
    dry_run_enabled?: boolean;
    reply_enabled?: boolean;
    reply_channel?: string;
    photo_archive_enabled?: boolean;
    photo_attach_minutes?: number;
    auto_area_enabled?: boolean;
    legacy_scraper_enabled?: boolean;
    manual_text: string;
    notes: string;
    message: string;
}

interface AreaOpt {
    id: number;
    name: string;
}

interface BillingEnforcement {
    isolir_after_days: number;
    dismantle_after_days: number;
    dry_run: boolean;
    fixed_due_day?: number;
    fixed_due_day_router_ids?: string;
    fixed_due_day_area_ids?: string;
    last_run?: {
        at?: string;
        dry_run?: boolean;
        stats?: {
            isolir_candidates?: number;
            dismantle_candidates?: number;
            isolir_dispatched?: number;
            dismantle_applied?: number;
        };
        isolir_days?: number;
        dismantle_days?: number;
    } | null;
}

interface IsolationPortalConfig {
    enabled: boolean;
    test_mode: boolean;
    test_ip: string;
    mode: 'general' | 'customer';
    wa_admin: string;
    office_address: string;
    office_hours: string;
    areas: string[];
    all_areas: boolean;
    area_count: number;
}

interface Props {
    grouped_settings: {
        billing: {
            company_name: string;
            company_address: string;
            company_email?: string;
            company_phone?: string;
            company_wa_admin?: string;
        };
        wa_gateway: WaConfig;
    };
    wa_gateway?: {
        connected: boolean;
        groups: WaGroup[];
        config: WaConfig & {
            ticket_group_id: string;
            report_group_id: string;
            report_interval_hours: number;
            notify_ticket_created: boolean;
            notify_ticket_status_change: boolean;
            notify_report: boolean;
        };
    };
    billing_wa?: BillingWaConfig;
    finance_bulk_wa?: FinanceBulkWaConfig;
    technician_progress?: TechnicianProgressConfig;
    billing_enforcement?: BillingEnforcement;
    isolation_portal?: IsolationPortalConfig;
    areas?: AreaOpt[]; isolation_portal_test_ip_default?: string;
}

type SettingsSection = 'company' | 'isolation' | 'wagateway' | 'billingwa' | 'enforcement' | 'midtrans';

interface PendingConfirmation {
    title: string;
    description: string;
    confirmationLabel: string;
    confirmText: string;
    typedConfirmation?: string;
    onConfirm: () => void;
}

export default function Index({ grouped_settings, wa_gateway, billing_wa, finance_bulk_wa, technician_progress, billing_enforcement, isolation_portal, areas = [], isolation_portal_test_ip_default: isolationTestIpDefault = '' }: Props) {
    const [loading, setLoading] = useState(false);
    const [dirtySections, setDirtySections] = useState<Partial<Record<SettingsSection, boolean>>>({});

    const markSectionDirty = (section: SettingsSection) => {
        setDirtySections((current) => ({ ...current, [section]: true }));
    };

    const clearSectionDirty = (section: SettingsSection) => {
        setDirtySections((current) => ({ ...current, [section]: false }));
    };

    const sectionLabels: Record<SettingsSection, string> = {
        company: 'Umum',
        isolation: 'Portal Isolir',
        wagateway: 'WA',
        billingwa: 'Billing',
        enforcement: 'Billing Enforcement',
        midtrans: 'Midtrans',
    };

    // Company form
    const [company_name, setCompany_name] = useState(grouped_settings.billing.company_name || '');
    const [company_address, setCompany_address] = useState(grouped_settings.billing.company_address || '');
    // Customer-facing contact (invoice PDF + public payment page). Server sends
    // the effective value (setting or the previous hardcoded default).
    const [company_email, setCompany_email] = useState(grouped_settings.billing.company_email || '');
    const [company_phone, setCompany_phone] = useState(grouped_settings.billing.company_phone || '');
    const [company_wa_admin, setCompany_wa_admin] = useState(grouped_settings.billing.company_wa_admin || '');

    // WA Config — auto-filled from server-side config (env / config/services.php)
    const initialWa = grouped_settings.wa_gateway || (wa_gateway?.config as WaConfig) || {};
    const [waConfig, setWaConfig] = useState<WaConfig>({
        evo_base_url: initialWa.evo_base_url || '',
        evo_api_key_configured: initialWa.evo_api_key_configured ?? false,
        evo_instance_name: initialWa.evo_instance_name || 'skynet-ebilling',
    });

    const [waNotifs, setWaNotifs] = useState({
        wa_notif_ticket_created: wa_gateway?.config?.notify_ticket_created ?? true,
        wa_notif_ticket_status: wa_gateway?.config?.notify_ticket_status_change ?? true,
        wa_notif_report: wa_gateway?.config?.notify_report ?? true,
    });
    // Read-only since the Grup WhatsApp page became the single source of truth.
    const waTicketGroupJid: string = wa_gateway?.config?.ticket_group_id || '';
    const waReportGroupJid: string = wa_gateway?.config?.report_group_id || '';
    const [waGroupsList, setWaGroupsList] = useState<WaGroup[]>(wa_gateway?.groups || []);
    const [waStatus, setWaStatus] = useState<any>(wa_gateway ? { connected: wa_gateway.connected } : null);
    const [waChannelStatus, setWaChannelStatus] = useState<Record<string, any>>({});
    const defaultTechProgress: TechnicianProgressConfig = {
        share_group_jid: '',
        share_group_label: 'SKYNET REPORT',
        input_group_jid: '',
        input_group_label: 'KERJAAN',
        preview_time: '21:00',
        morning_send_time: '08:00',
        summary_times: '12:00,15:00,17:00',
        auto_send_enabled: false,
        summary_enabled: false,
        dry_run_enabled: true,
        reply_enabled: false,
        reply_channel: 'ticket',
        photo_archive_enabled: true,
        photo_attach_minutes: 90,
        auto_area_enabled: true,
        legacy_scraper_enabled: true,
        manual_text: '',
        notes: '',
        message: '',
    };
    const [techProgress, setTechProgress] = useState<TechnicianProgressConfig>({
        ...defaultTechProgress,
        ...(technician_progress || {}),
    });
    const [savingTechProgress, setSavingTechProgress] = useState(false);
    const [previewingTechProgress, setPreviewingTechProgress] = useState(false);
    const [connecting, setConnecting] = useState(false);
    const [loadingGroups, setLoadingGroups] = useState(false);
    const [groupsFetchError, setGroupsFetchError] = useState(false);
    const [testTo, setTestTo] = useState('');
    const [testMsg, setTestMsg] = useState('Test message dari eBilling WA Gateway');
    const [sendingTest, setSendingTest] = useState(false);

    const fetchWithTimeout = (url: string, ms = 8000, init?: RequestInit) => {
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), ms);
        return fetch(url, { ...init, signal: controller.signal }).finally(() => clearTimeout(timer));
    };

    const reloadWaChannelStatus = async () => {
        const rows = await Promise.all(
            ['ops', 'billing', 'ticket'].map((channel) =>
                fetchWithTimeout(`/api/wa-gateway/status?channel=${channel}&fresh=1`)
                    .then((r) => r.json())
                    .then((data) => [channel, data] as const)
                    .catch(() => [channel, null] as const)
            )
        );
        setWaChannelStatus(Object.fromEntries(rows.filter(([, data]) => data)));
    };

    const reloadWaGroups = async () => {
        setLoadingGroups(true);
        setGroupsFetchError(false);
        try {
            const response = await fetchWithTimeout('/api/wa-gateway/groups?channel=ticket&fresh=1');
            if (!response.ok) {
                throw new Error(String(response.status));
            }
            const data = await response.json();
            setWaGroupsList(data.groups || []);
            setWaStatus((prev: any) => ({
                ...prev,
                connected: data.connected,
                state: data.state,
                configured: data.configured,
            }));
        } catch {
            setGroupsFetchError(true);
        } finally {
            setLoadingGroups(false);
        }
    };

    useEffect(() => {
        fetchWithTimeout('/api/wa-gateway/status')
            .then((r) => r.json())
            .then((d) => setWaStatus(d))
            .catch(() => {});
        void reloadWaChannelStatus();
        void reloadWaGroups();
    }, []);

    const handleSaveCompany = async () => {
        setLoading(true);
        try {
            const res = await fetch('/settings', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': (window as any).csrfToken },
                body: JSON.stringify({ settings: [{ key: 'company_name', value: company_name, type: 'text' }, { key: 'company_address', value: company_address, type: 'text' }] }),
            });
            if (res.ok) {
                clearSectionDirty('company');
                toast.success('Company settings saved');
            }
            else toast.error('Failed to save');
        } catch { toast.error('Failed to save'); }
        finally { setLoading(false); }
    };

    const handleSaveCompanyContact = async () => {
        setLoading(true);
        try {
            const res = await fetch('/settings', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': (window as any).csrfToken },
                body: JSON.stringify({
                    settings: [
                        { key: 'company_email', value: company_email.trim(), type: 'text', group: 'billing' },
                        { key: 'company_phone', value: company_phone.trim(), type: 'text', group: 'billing' },
                        { key: 'company_wa_admin', value: company_wa_admin.trim(), type: 'text', group: 'billing' },
                    ],
                }),
            });
            if (res.ok) {
                clearSectionDirty('company');
                toast.success('Kontak perusahaan disimpan');
            }
            else toast.error('Failed to save');
        } catch { toast.error('Failed to save'); }
        finally { setLoading(false); }
    };

    const handleSaveWaConfig = async () => {
        setLoading(true);
        try {
            const res = await fetch('/api/wa-gateway/config', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': (window as any).csrfToken },
                body: JSON.stringify(waConfig),
            });
            const d = await res.json();
            if (d.success) {
                clearSectionDirty('wagateway');
                toast.success('WA Config saved');
            }
            else toast.error('Failed to save WA config');
        } catch { toast.error('Failed to save WA config'); }
        finally { setLoading(false); }
    };

    const handleConnect = async () => {
        setConnecting(true);
        try {
            const res = await fetch('/api/wa-gateway/create-instance', { method: 'POST', headers: { 'X-CSRF-TOKEN': (window as any).csrfToken } });
            const d = await res.json();
            if (d.instance?.state === 'open') { toast.success('WhatsApp connected!'); fetch('/api/wa-gateway/status').then(r => r.json()).then(setWaStatus); }
            else toast.error(d.instance?.state || 'Connection failed');
        } catch { toast.error('Connection failed'); }
        finally { setConnecting(false); }
    };

    const handleDisconnect = async () => {
        setConnecting(true);
        try {
            await fetch('/wa-gateway/disconnect', { method: 'POST', headers: { 'X-CSRF-TOKEN': (window as any).csrfToken } });
            toast.success('Disconnected');
            fetch('/api/wa-gateway/status').then(r => r.json()).then(setWaStatus);
        } catch { toast.error('Failed to disconnect'); }
        finally { setConnecting(false); }
    };

    const handleSaveWaNotifs = async () => {
        setLoading(true);
        try {
            const res = await fetch('/wa-gateway/notifications', {
                method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': (window as any).csrfToken },
                body: JSON.stringify(waNotifs),
            });
            if (!res.ok) {
                toast.error('Failed');
                return;
            }
            clearSectionDirty('wagateway');
            toast.success('Notifications saved');
        } catch { toast.error('Failed'); }
        finally { setLoading(false); }
    };

    /** "Nama Grup · …123456@g.us" for a group known to the connected instance, else just the JID suffix. */
    const groupLabelFor = (jid: string): string => {
        if (!jid) return '(belum diatur)';
        const g = waGroupsList.find((x) => x.id === jid);
        return formatGroupLabel(g?.name || g?.subject || null, jid);
    };

    const previewTechProgress = async () => {
        setPreviewingTechProgress(true);
        try {
            const res = await fetch('/api/technician-progress/preview', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': (window as any).csrfToken || '',
                    Accept: 'application/json',
                },
                body: JSON.stringify({
                    manual_text: techProgress.manual_text,
                    notes: techProgress.notes,
                }),
            });
            const d = await res.json();
            if (!res.ok || !d.message) {
                toast.error(d.message || 'Gagal preview progress');
                return;
            }
            setTechProgress((s) => ({ ...s, ...d }));
            toast.success('Preview progress diperbarui');
        } catch (e: any) {
            toast.error(e?.message || 'Gagal preview progress');
        } finally {
            setPreviewingTechProgress(false);
        }
    };

    const saveTechProgress = async () => {
        setSavingTechProgress(true);
        try {
            const res = await fetch('/api/technician-progress', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': (window as any).csrfToken || '',
                    Accept: 'application/json',
                },
                body: JSON.stringify({
                    preview_time: techProgress.preview_time,
                    morning_send_time: techProgress.morning_send_time,
                    summary_times: techProgress.summary_times,
                    auto_send_enabled: !!techProgress.auto_send_enabled,
                    summary_enabled: !!techProgress.summary_enabled,
                    dry_run_enabled: !!techProgress.dry_run_enabled,
                    reply_enabled: !!techProgress.reply_enabled,
                    reply_channel: techProgress.reply_channel || 'ticket',
                    photo_archive_enabled: !!techProgress.photo_archive_enabled,
                    photo_attach_minutes: Number(techProgress.photo_attach_minutes || 90),
                    auto_area_enabled: !!techProgress.auto_area_enabled,
                    legacy_scraper_enabled: !!techProgress.legacy_scraper_enabled,
                    manual_text: techProgress.manual_text,
                    notes: techProgress.notes,
                }),
            });
            const d = await res.json();
            if (!res.ok || !d.success) {
                toast.error(d.message || 'Gagal simpan progress harian');
                return;
            }
            if (d.progress) setTechProgress((s) => ({ ...s, ...d.progress }));
            clearSectionDirty('wagateway');
            toast.success(d.message || 'Progress harian disimpan');
        } catch (e: any) {
            toast.error(e?.message || 'Gagal simpan progress harian');
        } finally {
            setSavingTechProgress(false);
        }
    };

    const copyTechProgress = async () => {
        try {
            await navigator.clipboard.writeText(techProgress.message || '');
            toast.success('Template progress disalin');
        } catch {
            toast.error('Gagal copy template');
        }
    };

    const handleTestMessage = async () => {
        if (!testTo) { toast.error('Nomor tujuan wajib diisi'); return; }
        setSendingTest(true);
        try {
            const res = await fetch('/api/wa-gateway/test', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': (window as any).csrfToken || '' },
                body: JSON.stringify({ phone: testTo, message: testMsg }),
            });
            // Read text first to detect non-JSON (HTML page, login redirect, 419)
            const text = await res.text();
            let d: any = {};
            try { d = text ? JSON.parse(text) : {}; } catch { d = { _raw: text.slice(0, 200) }; }
            console.log('[WA test]', res.status, d);

            // Detect HTML responses = session expired (redirected to login)
            if (text && (text.trim().startsWith('<!DOCTYPE') || text.trim().startsWith('<html'))) {
                toast.error('Session habis. Refresh halaman (Ctrl+Shift+R) lalu coba lagi.', {
                    duration: 6000,
                    action: {
                        label: 'Refresh',
                        onClick: () => window.location.reload(),
                    },
                });
                return;
            }

            // Detect CSRF mismatch
            if (res.status === 419 || (d.message && /csrf/i.test(d.message))) {
                toast.error('Sesi/CSRF kadaluarsa. Refresh halaman lalu coba lagi.', {
                    duration: 6000,
                    action: { label: 'Refresh', onClick: () => window.location.reload() },
                });
                return;
            }

            if (!res.ok) {
                toast.error(d.message || d.error || `HTTP ${res.status} - gagal`);
            } else if (d.success || d.id || d.message_id) {
                toast.success(`Pesan terkirim! (${d.message_id || d.id || 'ok'})`);
            } else {
                // Fallback: backend returned 200 but no success flag — show whatever message it gave
                toast.error(d.error || d.message || 'Gagal: respons tidak dikenali');
            }
        } catch (e: any) {
            console.error('[WA test] exception', e);
            toast.error('Gagal kirim: ' + (e?.message || 'network error'));
        } finally {
            setSendingTest(false);
        }
    };

    const isConnected = waStatus?.connected === true;
    const [activeTab, setActiveTab] = useState<SettingsSection>('company');

    const [midtrans, setMidtrans] = useState({
        merchant_id: '',
        client_key: '',
        server_key: '',
        is_production: false,
        configured: false,
        webhook_url: '',
    });
    const [midtransLoading, setMidtransLoading] = useState(false);
    const [midtransSaving, setMidtransSaving] = useState(false);

    const defaultIsolationPortal: IsolationPortalConfig = {
        enabled: false,
        test_mode: true,
        test_ip: isolationTestIpDefault,
        mode: 'general',
        wa_admin: '',
        office_address: '',
        office_hours: '',
        areas: [],
        all_areas: false,
        area_count: 0,
    };
    const [isolationPortal, setIsolationPortal] = useState<IsolationPortalConfig>({
        ...defaultIsolationPortal,
        ...(isolation_portal || {}),
    });
    const [savingIsolationPortal, setSavingIsolationPortal] = useState(false);

    const toggleIsolationArea = (areaName: string, checked: boolean) => {
        setIsolationPortal((current) => {
            const selected = new Set(current.areas.filter((area) => area !== '*'));
            if (checked) selected.add(areaName);
            else selected.delete(areaName);
            return { ...current, areas: Array.from(selected), all_areas: false };
        });
        markSectionDirty('isolation');
    };

    const toggleAllIsolationAreas = (checked: boolean) => {
        setIsolationPortal((current) => ({
            ...current,
            areas: checked ? ['*'] : [],
            all_areas: checked,
        }));
        markSectionDirty('isolation');
    };

    const saveIsolationPortal = async () => {
        setSavingIsolationPortal(true);
        try {
            const res = await fetch('/api/settings/isolation-portal', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': (window as any).csrfToken || '',
                },
                body: JSON.stringify({
                    enabled: isolationPortal.enabled,
                    test_mode: isolationPortal.test_mode,
                    test_ip: isolationPortal.test_ip.trim(),
                    mode: isolationPortal.mode,
                    wa_admin: isolationPortal.wa_admin.trim(),
                    office_address: isolationPortal.office_address.trim(),
                    office_hours: isolationPortal.office_hours.trim(),
                    areas: isolationPortal.all_areas ? ['*'] : isolationPortal.areas.filter((area) => area !== '*'),
                }),
            });
            const text = await res.text();
            const data = text ? JSON.parse(text) : {};
            if (!res.ok || !data.success) {
                toast.error(data.message || 'Gagal menyimpan setting portal isolir');
                return;
            }
            if (data.isolation_portal) setIsolationPortal({ ...defaultIsolationPortal, ...data.isolation_portal });
            clearSectionDirty('isolation');
            toast.success(data.message || 'Setting portal isolir disimpan');
        } catch (error: any) {
            toast.error(error?.message || 'Gagal menyimpan setting portal isolir');
        } finally {
            setSavingIsolationPortal(false);
        }
    };

    useEffect(() => {
        if (activeTab !== 'midtrans') return;
        setMidtransLoading(true);
        fetch('/api/payment-gateway/config', { credentials: 'include', headers: { Accept: 'application/json' } })
            .then((r) => r.json())
            .then((d) => {
                const m = d.midtrans || {};
                setMidtrans((s) => ({
                    ...s,
                    merchant_id: m.merchant_id || '',
                    is_production: !!m.is_production,
                    configured: !!m.configured,
                    webhook_url: m.webhook_url || '',
                    client_key: '',
                    server_key: '',
                }));
            })
            .catch(() => toast.error('Gagal load config Midtrans'))
            .finally(() => setMidtransLoading(false));
    }, [activeTab]);

    const saveMidtrans = async () => {
        setMidtransSaving(true);
        try {
            const body: Record<string, unknown> = {
                merchant_id: midtrans.merchant_id,
                is_production: midtrans.is_production,
            };
            if (midtrans.client_key.trim()) body.client_key = midtrans.client_key.trim();
            if (midtrans.server_key.trim()) body.server_key = midtrans.server_key.trim();
            const res = await fetch('/api/payment-gateway/config', {
                method: 'POST',
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': (window as any).csrfToken || '',
                },
                body: JSON.stringify(body),
            });
            const d = await res.json();
            if (!res.ok || !d.success) {
                toast.error(d.message || 'Gagal simpan Midtrans');
                return;
            }
            clearSectionDirty('midtrans');
            toast.success(d.message || 'Midtrans disimpan');
            setMidtrans((s) => ({ ...s, client_key: '', server_key: '', configured: !!d.config }));
        } catch (e: any) {
            toast.error(e?.message || 'Network error');
        } finally {
            setMidtransSaving(false);
        }
    };

    const defaultEnforcement: BillingEnforcement = {
        isolir_after_days: 3,
        dismantle_after_days: 33,
        dry_run: true,
        last_run: null,
        fixed_due_day: 25,
        fixed_due_day_router_ids: '513',
        fixed_due_day_area_ids: '14',
    };
    const [enforcement, setEnforcement] = useState<BillingEnforcement>({
        ...defaultEnforcement,
        ...(billing_enforcement || {}),
    });
    const [savingEnforcement, setSavingEnforcement] = useState(false);
    const [pendingConfirmation, setPendingConfirmation] = useState<PendingConfirmation | null>(null);

    const saveEnforcement = async (next: Partial<BillingEnforcement>, confirmLive = false, confirmed = false) => {
        const dry_run = next.dry_run !== undefined ? next.dry_run : enforcement.dry_run;
        if (!dry_run && confirmLive && !confirmed) {
            setPendingConfirmation({
                title: 'Matikan dry-run?',
                description: 'Scheduler billing:check-overdue akan mengisolir atau memberhentikan pelanggan secara nyata pada MikroTik dan status pelanggan. Lanjutkan hanya setelah kandidat dry-run ditinjau.',
                confirmationLabel: 'Saya memahami dampak isolir dan berhenti nyata.',
                confirmText: 'Aktifkan isolir nyata',
                onConfirm: () => {
                    setPendingConfirmation(null);
                    void saveEnforcement(next, true, true);
                },
            });
            return;
        }
        setSavingEnforcement(true);
        try {
            const body = {
                isolir_after_days: next.isolir_after_days ?? enforcement.isolir_after_days,
                dismantle_after_days: next.dismantle_after_days ?? enforcement.dismantle_after_days,
                dry_run,
                fixed_due_day: next.fixed_due_day ?? enforcement.fixed_due_day,
                fixed_due_day_router_ids: next.fixed_due_day_router_ids ?? enforcement.fixed_due_day_router_ids ?? '',
                fixed_due_day_area_ids: next.fixed_due_day_area_ids ?? enforcement.fixed_due_day_area_ids ?? '',
            };
            const res = await fetch('/api/settings/billing-enforcement', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': (window as any).csrfToken || '',
                    Accept: 'application/json',
                },
                body: JSON.stringify(body),
            });
            const d = await res.json();
            if (!res.ok || !d.success) {
                toast.error(d.message || 'Gagal simpan enforcement');
                return;
            }
            if (d.billing_enforcement) {
                setEnforcement({ ...defaultEnforcement, ...d.billing_enforcement });
            }
            clearSectionDirty('enforcement');
            toast.success(d.message || 'Enforcement disimpan');
        } catch (e: any) {
            toast.error(e?.message || 'Network error');
        } finally {
            setSavingEnforcement(false);
        }
    };

    const defaultBillingWa: BillingWaConfig = {
        payment_enabled: false,
        customer_created_enabled: false,
        isolation_enabled: false,
        isolation_deferred: true,
        live_sending_enabled: false,
        billing_sending_enabled: false,
        broadcast_sending_enabled: false,
        payment_template: '',
        customer_created_template: '',
        isolation_template: '',
        area_templates: {},
        placeholders: [],
        last_invoice_sync_at: null,
    };
    const [billingWa, setBillingWa] = useState<BillingWaConfig>({ ...defaultBillingWa, ...(billing_wa || {}) });
    const defaultFinanceBulkWa: FinanceBulkWaConfig = {
        enabled: false,
        dry_run: true,
        max_recipients: 100,
        rate_limit_per_minute: 10,
        allowed_roles: ['global_admin', 'superadmin'],
        confirmation_text: 'KIRIM WA TAGIHAN',
    };
    const [financeBulkWa, setFinanceBulkWa] = useState<FinanceBulkWaConfig>({ ...defaultFinanceBulkWa, ...(finance_bulk_wa || {}) });
    const [savingFinanceBulkWa, setSavingFinanceBulkWa] = useState(false);
    const [togglingBroadcastLive, setTogglingBroadcastLive] = useState(false);
    const [togglingBillingLive, setTogglingBillingLive] = useState(false);
    const [areaEditId, setAreaEditId] = useState<string>('');
    const [areaPaymentTpl, setAreaPaymentTpl] = useState('');
    const [areaCustomerTpl, setAreaCustomerTpl] = useState('');
    const [areaIsolationTpl, setAreaIsolationTpl] = useState('');

    useEffect(() => {
        if (!areaEditId) {
            setAreaPaymentTpl('');
            setAreaCustomerTpl('');
            setAreaIsolationTpl('');
            return;
        }
        const row = billingWa.area_templates?.[areaEditId] || {};
        setAreaPaymentTpl(row.payment || '');
        setAreaCustomerTpl(row.customer_created || '');
        setAreaIsolationTpl(row.isolation || '');
    }, [areaEditId]);

    const handleSaveBillingWa = async () => {
        setLoading(true);
        try {
            let area_templates = { ...(billingWa.area_templates || {}) };
            if (areaEditId) {
                area_templates[areaEditId] = {
                    payment: areaPaymentTpl,
                    customer_created: areaCustomerTpl,
                    isolation: areaIsolationTpl,
                };
            }
            const res = await fetch('/api/settings/billing-wa', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': (window as any).csrfToken || '' },
                body: JSON.stringify({
                    payment_enabled: billingWa.payment_enabled,
                    customer_created_enabled: billingWa.customer_created_enabled,
                    isolation_enabled: false,
                    payment_template: billingWa.payment_template,
                    customer_created_template: billingWa.customer_created_template,
                    isolation_template: billingWa.isolation_template,
                    area_templates,
                }),
            });
            const d = await res.json();
            if (d.success) {
                clearSectionDirty('billingwa');
                toast.success(d.message || 'Broadcast billing WA disimpan');
                if (d.billing_wa) setBillingWa({ ...defaultBillingWa, ...d.billing_wa });
            } else {
                toast.error(d.message || 'Gagal simpan');
            }
        } catch {
            toast.error('Gagal simpan billing WA');
        } finally {
            setLoading(false);
        }
    };

    const handleSaveFinanceBulkWa = async (confirmed = false) => {
        if (financeBulkWa.enabled && !financeBulkWa.dry_run && !confirmed) {
            setPendingConfirmation({
                title: 'Aktifkan WA tagihan massal?',
                description: 'Pengiriman nyata akan memakai daftar penerima yang dipreview dan mengantrekan pesan ke channel Billing.',
                confirmationLabel: 'Saya sudah menyetujui pengiriman sesuai cap dan rate limit.',
                typedConfirmation: financeBulkWa.confirmation_text,
                confirmText: 'Simpan mode LIVE',
                onConfirm: () => {
                    setPendingConfirmation(null);
                    void handleSaveFinanceBulkWa(true);
                },
            });
            return;
        }

        setSavingFinanceBulkWa(true);
        try {
            const res = await fetch('/api/settings/finance-bulk-wa', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': (window as any).csrfToken || '', Accept: 'application/json' },
                body: JSON.stringify({
                    enabled: financeBulkWa.enabled,
                    dry_run: financeBulkWa.dry_run,
                    max_recipients: financeBulkWa.max_recipients,
                    rate_limit_per_minute: financeBulkWa.rate_limit_per_minute,
                    confirmation: confirmed ? financeBulkWa.confirmation_text : undefined,
                }),
            });
            const data = await res.json();
            if (!res.ok || !data.success) {
                toast.error(data.message || 'Gagal menyimpan setting WA tagihan massal');
                return;
            }
            if (data.finance_bulk_wa) setFinanceBulkWa({ ...defaultFinanceBulkWa, ...data.finance_bulk_wa });
            clearSectionDirty('billingwa');
            toast.success(data.message || 'Setting WA tagihan massal disimpan');
        } catch (error: any) {
            toast.error(error?.message || 'Network error');
        } finally {
            setSavingFinanceBulkWa(false);
        }
    };

    const toggleBroadcastLive = async (live: boolean, confirmed = false) => {
        if (live && !confirmed) {
            setPendingConfirmation({
                title: 'Aktifkan live broadcast?',
                description: 'Broadcast berikutnya akan dikirim nyata ke pelanggan lewat nomor billing atau broadcast.',
                confirmationLabel: 'Saya memahami bahwa pesan akan dikirim nyata.',
                confirmText: 'Aktifkan live broadcast',
                onConfirm: () => {
                    setPendingConfirmation(null);
                    void toggleBroadcastLive(true, true);
                },
            });
            return;
        }

        setTogglingBroadcastLive(true);
        try {
            const res = await fetch('/api/settings/billing-wa/live', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': (window as any).csrfToken || '',
                    Accept: 'application/json',
                },
                body: JSON.stringify({ live }),
            });
            const d = await res.json();
            if (!res.ok || !d.success) {
                toast.error(d.message || 'Gagal ubah mode broadcast');
                return;
            }
            if (d.billing_wa) setBillingWa({ ...defaultBillingWa, ...d.billing_wa });
            clearSectionDirty('billingwa');
            toast.success(d.message || 'Mode broadcast disimpan');
        } catch (e: any) {
            toast.error(e?.message || 'Network error');
        } finally {
            setTogglingBroadcastLive(false);
        }
    };

    const toggleBillingLive = async (live: boolean, confirmed = false) => {
        if (live && !confirmed) {
            setPendingConfirmation({
                title: 'Aktifkan WA pembayaran?',
                description: 'WA otomatis akan dikirim setelah pembayaran terverifikasi. Pesan akan dikirim nyata.',
                confirmationLabel: 'Saya memahami bahwa pesan pembayaran akan dikirim nyata.',
                confirmText: 'Aktifkan WA pembayaran',
                onConfirm: () => {
                    setPendingConfirmation(null);
                    void toggleBillingLive(true, true);
                },
            });
            return;
        }
        setTogglingBillingLive(true);
        try {
            const res = await fetch('/api/settings/billing-wa/billing-live', {
                method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': (window as any).csrfToken || '', Accept: 'application/json' },
                body: JSON.stringify({ live }),
            });
            const d = await res.json();
            if (!res.ok || !d.success) { toast.error(d.message || 'Gagal ubah mode WA pembayaran'); return; }
            if (d.billing_wa) setBillingWa({ ...defaultBillingWa, ...d.billing_wa });
            clearSectionDirty('billingwa');
            toast.success(d.message || 'Mode WA pembayaran disimpan');
        } catch (e: any) { toast.error(e?.message || 'Network error'); }
        finally { setTogglingBillingLive(false); }
    };

    return (
        <EnterpriseIndexLayout
            title="Settings"
            pageTitle="Settings"
            subtitle="Konfigurasi perusahaan, WhatsApp, broadcast, dan enforcement isolir"
            icon={Settings}
            breadcrumbs={[{ label: 'Settings' }]}
            activeTab={activeTab}
            onTabChange={(id) => setActiveTab(id as typeof activeTab)}
            tabs={[
                { id: 'company', label: 'Company', group: 'Umum' },
                { id: 'isolation', label: 'Portal Isolir', group: 'Billing' },
                { id: 'wagateway', label: 'WhatsApp Gateway', group: 'WA' },
                { id: 'billingwa', label: 'Broadcast Billing', group: 'Billing' },
                { id: 'enforcement', label: 'Enforcement', group: 'Billing' },
                { id: 'midtrans', label: 'Midtrans', group: 'Billing' },
            ]}
        >
            <div className="mx-auto max-w-5xl">
                <ConfirmDestructive
                    open={pendingConfirmation !== null}
                    onOpenChange={(open) => {
                        if (!open) setPendingConfirmation(null);
                    }}
                    title={pendingConfirmation?.title || ''}
                    description={pendingConfirmation?.description || ''}
                    confirmationLabel={pendingConfirmation?.confirmationLabel}
                    typedConfirmation={pendingConfirmation?.typedConfirmation}
                    confirmText={pendingConfirmation?.confirmText}
                    onConfirm={() => pendingConfirmation?.onConfirm()}
                    isLoading={savingEnforcement || savingFinanceBulkWa || togglingBroadcastLive || togglingBillingLive}
                />
                {dirtySections[activeTab] && (
                    <div className="mb-4 flex items-start gap-2 rounded-md border border-status-degraded/30 bg-status-degraded-bg p-3 text-sm" role="status" aria-live="polite">
                        <Badge variant="warning" className="shrink-0">Belum tersimpan</Badge>
                        <div>
                            <p className="font-medium">Perubahan pada bagian {sectionLabels[activeTab]} belum disimpan.</p>
                            <p className="text-xs text-muted-foreground">Simpan bagian ini untuk menerapkan perubahan.</p>
                        </div>
                    </div>
                )}
                {activeTab === 'company' && (
                        <div className="space-y-6" onChangeCapture={() => markSectionDirty('company')}>
                            <Card>
                                <CardHeader>
                                    <CardTitle>Company Details</CardTitle>
                                    <CardDescription>Information used in invoices and receipts.</CardDescription>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    <div className="space-y-2">
                                        <Label>Company Name</Label>
                                        <Input value={company_name} onChange={e => setCompany_name(e.target.value)} placeholder="PT. SKYNET LINTAS NUSANTARA" />
                                        <p className="text-xs text-muted-foreground">Nama ini tampil pada invoice dan kuitansi.</p>
                                    </div>
                                    <div className="space-y-2">
                                        <Label>Address</Label>
                                        <Input value={company_address} onChange={e => setCompany_address(e.target.value)} placeholder="Full address" />
                                        <p className="text-xs text-muted-foreground">Alamat ini dipakai pada dokumen billing.</p>
                                    </div>
                                    <div className="flex justify-end">
                                        <Button onClick={handleSaveCompany} disabled={loading} className="gap-2">
                                            {loading ? <Loader2 className="w-4 h-4 animate-spin" /> : <Save className="w-4 h-4" />}
                                            Save Company
                                        </Button>
                                    </div>
                                </CardContent>
                            </Card>
                            <Card>
                                <CardHeader>
                                    <CardTitle>Kontak Pelanggan</CardTitle>
                                    <CardDescription>Kontak yang tampil di invoice PDF dan halaman bayar publik. Kosongkan untuk memakai nilai bawaan.</CardDescription>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    <div className="space-y-2">
                                        <Label>Email CS</Label>
                                        <Input value={company_email} onChange={e => setCompany_email(e.target.value)} />
                                        <p className="text-xs text-muted-foreground">Tampil pada invoice PDF.</p>
                                    </div>
                                    <div className="space-y-2">
                                        <Label>Telepon CS</Label>
                                        <Input value={company_phone} onChange={e => setCompany_phone(e.target.value)} />
                                        <p className="text-xs text-muted-foreground">Tampil pada invoice PDF.</p>
                                    </div>
                                    <div className="space-y-2">
                                        <Label>WhatsApp CS (cadangan)</Label>
                                        <Input value={company_wa_admin} onChange={e => setCompany_wa_admin(e.target.value)} />
                                        <p className="text-xs text-muted-foreground">Dipakai di halaman bayar publik bila nomor WA CS portal isolir kosong.</p>
                                    </div>
                                    <div className="flex justify-end">
                                        <Button onClick={handleSaveCompanyContact} disabled={loading} className="gap-2">
                                            {loading ? <Loader2 className="w-4 h-4 animate-spin" /> : <Save className="w-4 h-4" />}
                                            Simpan Kontak
                                        </Button>
                                    </div>
                                </CardContent>
                            </Card>
                        </div>
                )}

                {activeTab === 'isolation' && (
                    <div className="space-y-6" onChangeCapture={() => markSectionDirty('isolation')}>
                        <Card>
                            <CardHeader>
                                <div className="flex flex-wrap items-center gap-2">
                                    <CardTitle>Portal Isolir</CardTitle>
                                    <Badge variant={isolationPortal.enabled ? 'destructive' : 'secondary'}>
                                        {isolationPortal.enabled ? 'AKTIF' : 'OFF'}
                                    </Badge>
                                </div>
                                <CardDescription>
                                    Portal hanya tampil untuk pelanggan yang area-nya dipilih dan benar-benar terbaca terisolir di MikroTik.
                                    Area kosong berarti semua portal customer tetap tertutup.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-5">
                                <div className="flex items-center justify-between rounded-md border p-3">
                                    <div>
                                        <Label>Aktifkan portal isolir</Label>
                                        <p className="text-xs text-muted-foreground">Tidak mengubah proses isolir MikroTik; hanya mengatur halaman yang dibuka pelanggan terisolir.</p>
                                    </div>
                                    <Switch
                                        checked={isolationPortal.enabled}
                                        onCheckedChange={(checked) => {
                                            markSectionDirty('isolation');
                                            setIsolationPortal((current) => ({ ...current, enabled: checked }));
                                        }}
                                    />
                                </div>

                                <div className="grid gap-4 md:grid-cols-2">
                                    <div className="flex items-center justify-between rounded-md border p-3">
                                        <div>
                                            <Label>Test mode</Label>
                                            <p className="text-xs text-muted-foreground">Saat aktif, hanya TEST IP yang boleh melewati canary portal.</p>
                                        </div>
                                        <Switch
                                            checked={isolationPortal.test_mode}
                                            onCheckedChange={(checked) => {
                                                markSectionDirty('isolation');
                                                setIsolationPortal((current) => ({ ...current, test_mode: checked }));
                                            }}
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="isolation-test-ip">TEST IP</Label>
                                        <Input
                                            id="isolation-test-ip"
                                            value={isolationPortal.test_ip}
                                            onChange={(event) => setIsolationPortal((current) => ({ ...current, test_ip: event.target.value }))}
                                            placeholder={isolationTestIpDefault}
                                            className="font-mono"
                                        />
                                        <p className="text-xs text-muted-foreground">Wajib berupa IP valid saat test mode aktif.</p>
                                    </div>
                                </div>

                                <div className="space-y-2 rounded-md border p-3">
                                    <Label>Halaman yang dibuka pelanggan</Label>
                                    <div className="flex flex-col gap-2 sm:flex-row">
                                        <label className="flex items-center gap-2 text-sm">
                                            <input
                                                type="radio"
                                                name="isolation-portal-mode"
                                                checked={isolationPortal.mode === 'general'}
                                                onChange={() => {
                                                    markSectionDirty('isolation');
                                                    setIsolationPortal((current) => ({ ...current, mode: 'general' }));
                                                }}
                                                className="h-4 w-4 accent-primary"
                                            />
                                            Umum (<span className="font-mono">/isolir</span>) — tanpa data pelanggan
                                        </label>
                                        <label className="flex items-center gap-2 text-sm">
                                            <input
                                                type="radio"
                                                name="isolation-portal-mode"
                                                checked={isolationPortal.mode === 'customer'}
                                                onChange={() => {
                                                    markSectionDirty('isolation');
                                                    setIsolationPortal((current) => ({ ...current, mode: 'customer' }));
                                                }}
                                                className="h-4 w-4 accent-primary"
                                            />
                                            Per pelanggan (<span className="font-mono">/isolated</span>) — tagihan + bayar
                                        </label>
                                    </div>
                                    <p className="text-xs text-muted-foreground">
                                        Dipakai oleh endpoint captive <span className="font-mono">/isolated/captive</span>. Mengubah pilihan ini tidak perlu ubah router.
                                    </p>
                                </div>

                                <div className="space-y-4 rounded-md border p-3">
                                    <div>
                                        <Label>Kontak di halaman isolir</Label>
                                        <p className="text-xs text-muted-foreground">
                                            Tampil untuk semua pelanggan terisolir di halaman <span className="font-mono">/isolir</span>. Kosongkan untuk menyembunyikan bagiannya.
                                        </p>
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="isolation-wa-admin">Nomor WhatsApp CS</Label>
                                        <Input
                                            id="isolation-wa-admin"
                                            inputMode="tel"
                                            value={isolationPortal.wa_admin}
                                            onChange={(event) => setIsolationPortal((current) => ({ ...current, wa_admin: event.target.value }))}
                                            placeholder="0812-0000-0000"
                                            className="font-mono"
                                            maxLength={32}
                                        />
                                        <p className="text-xs text-muted-foreground">Menjadi tombol &quot;Chat CS via WhatsApp&quot;, tombol &quot;Telepon CS&quot;, dan nomor yang ditampilkan sebagai teks.</p>
                                    </div>
                                    <div className="grid gap-4 md:grid-cols-2">
                                        <div className="space-y-2">
                                            <Label htmlFor="isolation-office-address">Alamat kantor</Label>
                                            <Input
                                                id="isolation-office-address"
                                                value={isolationPortal.office_address}
                                                onChange={(event) => setIsolationPortal((current) => ({ ...current, office_address: event.target.value }))}
                                                placeholder="Jl. ... No. ..., Kecamatan, Kota"
                                                maxLength={500}
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label htmlFor="isolation-office-hours">Jam buka kantor</Label>
                                            <Input
                                                id="isolation-office-hours"
                                                value={isolationPortal.office_hours}
                                                onChange={(event) => setIsolationPortal((current) => ({ ...current, office_hours: event.target.value }))}
                                                placeholder="Senin-Sabtu 08.00-16.00"
                                                maxLength={160}
                                            />
                                        </div>
                                    </div>
                                    <p className="text-xs text-muted-foreground">Alamat dan jam buka tampil di bagian &quot;Kantor layanan&quot;. Jika alamat dikosongkan, dipakai Address di tab Company (Company Details).</p>
                                </div>

                                <Separator />

                                <div className="space-y-3">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <div>
                                            <Label>Area yang boleh memakai portal</Label>
                                            <p className="text-xs text-muted-foreground">Terpilih: {isolationPortal.all_areas ? 'semua area' : `${isolationPortal.areas.filter((area) => area !== '*').length} area`}.</p>
                                        </div>
                                        <label className="flex items-center gap-2 text-sm font-medium">
                                            <input
                                                type="checkbox"
                                                checked={isolationPortal.all_areas}
                                                onChange={(event) => toggleAllIsolationAreas(event.target.checked)}
                                                className="h-4 w-4 rounded border-input accent-primary"
                                            />
                                            Semua area
                                        </label>
                                    </div>
                                    <div className="grid max-h-72 gap-2 overflow-y-auto rounded-md border p-3 sm:grid-cols-2 lg:grid-cols-3">
                                        {areas.length === 0 ? (
                                            <p className="text-sm text-muted-foreground">Belum ada data area.</p>
                                        ) : areas.map((area) => (
                                            <label key={area.id} className="flex items-center gap-2 rounded px-2 py-1.5 text-sm hover:bg-muted">
                                                <input
                                                    type="checkbox"
                                                    checked={isolationPortal.all_areas || isolationPortal.areas.includes(area.name)}
                                                    disabled={isolationPortal.all_areas}
                                                    onChange={(event) => toggleIsolationArea(area.name, event.target.checked)}
                                                    className="h-4 w-4 rounded border-input accent-primary"
                                                />
                                                <span className="truncate" title={area.name}>{area.name}</span>
                                            </label>
                                        ))}
                                    </div>
                                    {!isolationPortal.test_mode && isolationPortal.enabled && !isolationPortal.all_areas && isolationPortal.areas.length === 0 && (
                                        <p className="text-sm text-status-degraded" role="alert">Pilih minimal satu area atau centang Semua area sebelum mengaktifkan portal.</p>
                                    )}
                                </div>

                                <div className="flex justify-end">
                                    <Button type="button" onClick={saveIsolationPortal} disabled={savingIsolationPortal} className="gap-2">
                                        {savingIsolationPortal ? <Loader2 className="h-4 w-4 animate-spin" /> : <Save className="h-4 w-4" />}
                                        {savingIsolationPortal ? 'Menyimpan...' : 'Simpan Portal Isolir'}
                                    </Button>
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                )}

                {activeTab === 'wagateway' && (
                        <div className="space-y-6" onChangeCapture={() => markSectionDirty('wagateway')}>
                            <Card>
                                <CardHeader>
                                    <CardTitle>WA Gateway 3 Nomor</CardTitle>
                                    <CardDescription>
                                        Pairing nomor ops, billing/broadcast, dan ticket/progress dikelola di halaman khusus multi-nomor.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="flex flex-wrap items-center gap-3">
                                    <Button asChild className="gap-2">
                                        <Link href={route('wa-gateway.pair')}>
                                            <MessageCircle className="w-4 h-4" />
                                        Buka Pairing 3 Nomor
                                        </Link>
                                    </Button>
                                    <div className="flex flex-wrap gap-2">
                                        {[
                                            ['ops', 'Nomor Ops'],
                                            ['billing', 'Nomor Billing'],
                                            ['ticket', 'Nomor Ticket'],
                                        ].map(([key, label]) => {
                                            const row = waChannelStatus[key];
                                            const connected = row?.connected === true;

                                            return (
                                                <Badge
                                                    key={key}
                                                    variant={connected ? 'up' : 'warning'}
                                                >
                                                    {label}: {connected ? 'CONNECTED' : (row?.state || 'UNKNOWN')}
                                                </Badge>
                                            );
                                        })}
                                    </div>
                                    <p className="text-sm text-muted-foreground">
                                        Nomor ops tetap untuk PSB/monitoring, nomor billing untuk pelanggan, nomor ticket untuk tiket/progress teknisi.
                                    </p>
                                </CardContent>
                            </Card>

                            {/* Status */}
                            <Card className="hidden">
                                <CardHeader className="pb-3">
                                    <div className="flex items-center justify-between">
                                        <div className="flex items-center gap-3">
                                            {isConnected ? <CheckCircle2 className="w-8 h-8 text-status-up" /> : <XCircle className="w-8 h-8 text-destructive" />}
                                            <div>
                                                <CardTitle>WA Connection</CardTitle>
                                                <CardDescription>{isConnected ? 'WhatsApp aktif' : waStatus?.configured ? 'Belum scan QR' : 'Belum dikonfigurasi'}</CardDescription>
                                            </div>
                                        </div>
                                        <Badge variant={isConnected ? 'default' : 'destructive'}>{isConnected ? 'CONNECTED' : waStatus?.configured ? 'WAITING' : 'NOT CONFIGURED'}</Badge>
                                    </div>
                                </CardHeader>
                                <CardContent>
                                    <div className="flex flex-wrap gap-3">
                                        {!isConnected && !connecting && <Button onClick={handleConnect} className="gap-2"><Link2 className="w-4 h-4" />Hubungkan</Button>}
                                        {isConnected && <Button onClick={handleDisconnect} variant="destructive" className="gap-2"><Unlink className="w-4 h-4" />Putuskan</Button>}
                                        {connecting && <Button disabled className="gap-2"><Loader2 className="w-4 h-4 animate-spin" />Memproses</Button>}
                                        <Button variant="outline" onClick={() => fetch('/api/wa-gateway/status').then(r => r.json()).then(setWaStatus)} className="gap-2"><RefreshCw className="w-4 h-4" />Refresh</Button>
                                    </div>
                                </CardContent>
                            </Card>

                            {/* QR Code */}
                            {false && !isConnected && waStatus?.configured && waStatus?.qr_code && (
                                <Card>
                                    <CardHeader><CardTitle className="flex items-center gap-2"><QrCode className="w-5 h-5" />Scan QR</CardTitle></CardHeader>
                                    <CardContent className="flex justify-center p-4">
                                        <img src={waStatus.qr_code.startsWith('data:image/') ? waStatus.qr_code : `data:image/png;base64,${waStatus.qr_code}`} alt="QR" className="border rounded" style={{ maxWidth: 280 }} />
                                    </CardContent>
                                </Card>
                            )}

                            {/* Config */}
                            <Card className="hidden">
                                <CardHeader>
                                    <CardTitle>Evolution API Config</CardTitle>
                                    <CardDescription>URL, API Key, Instance Name — auto-filled dari .env (server-side).</CardDescription>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div className="space-y-2">
                                            <Label>Base URL</Label>
                                            <Input value={waConfig.evo_base_url} onChange={e => setWaConfig(c => ({ ...c, evo_base_url: e.target.value }))} placeholder="http://evolution.example.test:8085" />
                                        </div>
                                        <div className="space-y-2">
                                            <Label>Instance Name</Label>
                                            <Input value={waConfig.evo_instance_name} onChange={e => setWaConfig(c => ({ ...c, evo_instance_name: e.target.value }))} placeholder="skynet-ebilling" />
                                        </div>
                                        <div className="space-y-2 md:col-span-2">
                                            <Label>API Key</Label>
                                            <p className="text-sm text-muted-foreground">
                                                {waConfig.evo_api_key_configured
                                                    ? 'Configured via server .env (EVOLUTION_API_API_KEY) — tidak ditampilkan di browser.'
                                                    : 'Belum dikonfigurasi — set EVOLUTION_API_API_KEY di .env server.'}
                                            </p>
                                        </div>
                                    </div>
                                    <div className="flex justify-end">
                                        <Button onClick={handleSaveWaConfig} disabled={loading} className="gap-2">
                                            {loading ? <Loader2 className="w-4 h-4 animate-spin" /> : <Zap className="w-4 h-4" />}Simpan Config
                                        </Button>
                                    </div>
                                </CardContent>
                            </Card>

                            {/* Groups */}
                            <Card>
                                <CardHeader><CardTitle>Grup WhatsApp</CardTitle><CardDescription>Pilihan grup dipindah ke satu tempat. Halaman ini hanya menampilkan grup yang sedang dipakai.</CardDescription></CardHeader>
                                <CardContent className="space-y-4">
                                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div className="space-y-1">
                                            <Label>Grup Tiket</Label>
                                            <p className="font-mono text-xs break-all">{groupLabelFor(waTicketGroupJid)}</p>
                                        </div>
                                        <div className="space-y-1">
                                            <Label>Grup Alert Ops (laporan periodik)</Label>
                                            <p className="font-mono text-xs break-all">{groupLabelFor(waReportGroupJid)}</p>
                                        </div>
                                    </div>
                                    <div className="flex items-center justify-between gap-2 text-xs text-muted-foreground">
                                        <span>
                                            {loadingGroups
                                                ? 'Memuat daftar grup...'
                                                : `WA ${waStatus?.connected ? 'terhubung' : 'belum terhubung'}`}
                                        </span>
                                        <Button type="button" variant="ghost" size="sm" className="h-7 px-2" onClick={() => { void reloadWaGroups(); void reloadWaChannelStatus(); }} disabled={loadingGroups}>
                                            Muat ulang
                                        </Button>
                                    </div>
                                    <Link href={route('settings.wa-groups')} className="text-sm text-primary underline">
                                        Ubah di Pengaturan › Grup WhatsApp
                                    </Link>
                                </CardContent>
                            </Card>

                            <Card>
                                <CardHeader>
                                    <CardTitle>Progress Harian Teknisi</CardTitle>
                                    <CardDescription>
                                        Template harian dari ticket maintenance + PSB eBilling. Preview/copy saja; belum mengirim WA otomatis.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                                        <div className="space-y-1">
                                            <Label>Grup Share Progress</Label>
                                            <p className="font-mono text-xs break-all">
                                                {groupLabelFor(techProgress.share_group_jid)}
                                                {techProgress.share_group_label ? ` (${techProgress.share_group_label})` : ''}
                                            </p>
                                        </div>
                                        <div className="space-y-1">
                                            <Label>Grup Input Manual</Label>
                                            <p className="font-mono text-xs break-all">
                                                {groupLabelFor(techProgress.input_group_jid)}
                                                {techProgress.input_group_label ? ` (${techProgress.input_group_label})` : ''}
                                            </p>
                                        </div>
                                    </div>
                                    <Link href={route('settings.wa-groups')} className="text-sm text-primary underline">
                                        Ubah di Pengaturan › Grup WhatsApp
                                    </Link>

                                    <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
                                        <div className="space-y-2">
                                            <Label>Preview H-1</Label>
                                            <Input
                                                type="time"
                                                value={techProgress.preview_time || '21:00'}
                                                onChange={e => setTechProgress(s => ({ ...s, preview_time: e.target.value }))}
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label>Jadwal Pagi</Label>
                                            <Input
                                                type="time"
                                                value={techProgress.morning_send_time || '08:00'}
                                                onChange={e => setTechProgress(s => ({ ...s, morning_send_time: e.target.value }))}
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label>Jam Rekap</Label>
                                            <Input
                                                value={techProgress.summary_times || '12:00,15:00,17:00'}
                                                onChange={e => setTechProgress(s => ({ ...s, summary_times: e.target.value }))}
                                            />
                                        </div>
                                    </div>

                                    <div className="grid grid-cols-1 gap-3 md:grid-cols-3">
                                        <div className="flex items-center justify-between rounded-md border p-3">
                                            <Label>Dry Run</Label>
                                            <Switch
                                                checked={!!techProgress.dry_run_enabled}
                                                onCheckedChange={v => { markSectionDirty('wagateway'); setTechProgress(s => ({ ...s, dry_run_enabled: v })); }}
                                            />
                                        </div>
                                        <div className="flex items-center justify-between rounded-md border p-3">
                                            <Label>Live Jadwal 08:00</Label>
                                            <Switch
                                                checked={!!techProgress.auto_send_enabled}
                                                onCheckedChange={v => { markSectionDirty('wagateway'); setTechProgress(s => ({ ...s, auto_send_enabled: v })); }}
                                            />
                                        </div>
                                        <div className="flex items-center justify-between rounded-md border p-3">
                                            <Label>Live Rekap</Label>
                                            <Switch
                                                checked={!!techProgress.summary_enabled}
                                                onCheckedChange={v => { markSectionDirty('wagateway'); setTechProgress(s => ({ ...s, summary_enabled: v })); }}
                                            />
                                        </div>
                                    </div>

                                    <div className="grid grid-cols-1 gap-3 md:grid-cols-3">
                                        <div className="flex items-center justify-between rounded-md border p-3">
                                            <Label>Auto Area Planner</Label>
                                            <Switch
                                                checked={!!techProgress.auto_area_enabled}
                                                onCheckedChange={v => { markSectionDirty('wagateway'); setTechProgress(s => ({ ...s, auto_area_enabled: v })); }}
                                            />
                                        </div>
                                        <div className="flex items-center justify-between rounded-md border p-3">
                                            <Label>Scraper Lama</Label>
                                            <Switch
                                                checked={!!techProgress.legacy_scraper_enabled}
                                                onCheckedChange={v => { markSectionDirty('wagateway'); setTechProgress(s => ({ ...s, legacy_scraper_enabled: v })); }}
                                            />
                                        </div>
                                        <div className="flex items-center justify-between rounded-md border p-3">
                                            <Label>Auto Reply KERJAAN</Label>
                                            <Switch
                                                checked={!!techProgress.reply_enabled}
                                                onCheckedChange={v => { markSectionDirty('wagateway'); setTechProgress(s => ({ ...s, reply_enabled: v })); }}
                                            />
                                        </div>
                                    </div>

                                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                                        <div className="space-y-2">
                                            <Label>Balasan Bot KERJAAN via</Label>
                                            <select
                                                className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                                                value={techProgress.reply_channel || 'ticket'}
                                                onChange={e => setTechProgress(s => ({ ...s, reply_channel: e.target.value }))}
                                            >
                                                <option value="ticket">Nomor 3 - Ticket/Progress</option>
                                                <option value="ops">Nomor 1 - Ops/PSB</option>
                                            </select>
                                            <p className="text-xs text-muted-foreground">Jadwal pagi dan rekap tetap dikirim via nomor 3.</p>
                                        </div>
                                    </div>

                                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                                        <div className="flex items-center justify-between rounded-md border p-3">
                                            <Label>Arsip Foto Progress</Label>
                                            <Switch
                                                checked={!!techProgress.photo_archive_enabled}
                                                onCheckedChange={v => { markSectionDirty('wagateway'); setTechProgress(s => ({ ...s, photo_archive_enabled: v })); }}
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label>Window attach foto (menit)</Label>
                                            <Input
                                                type="number"
                                                min={5}
                                                max={720}
                                                value={techProgress.photo_attach_minutes || 90}
                                                onChange={e => setTechProgress(s => ({ ...s, photo_attach_minutes: Number(e.target.value || 90) }))}
                                            />
                                        </div>
                                    </div>

                                    <div className="space-y-2">
                                        <Label>Template manual team</Label>
                                        <textarea
                                            className="flex min-h-[260px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm font-mono"
                                            value={techProgress.manual_text || ''}
                                            onChange={e => setTechProgress(s => ({ ...s, manual_text: e.target.value }))}
                                        />
                                    </div>

                                    <div className="space-y-2">
                                        <Label>Note daily</Label>
                                        <textarea
                                            className="flex min-h-[90px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm font-mono"
                                            value={techProgress.notes || ''}
                                            onChange={e => setTechProgress(s => ({ ...s, notes: e.target.value }))}
                                        />
                                    </div>

                                    <div className="space-y-2">
                                        <div className="flex items-center justify-between gap-2">
                                            <Label>Preview auto template</Label>
                                            <div className="flex flex-wrap gap-2">
                                                <Button type="button" variant="outline" size="sm" onClick={previewTechProgress} disabled={previewingTechProgress}>
                                                    {previewingTechProgress ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : null}
                                                    Preview
                                                </Button>
                                                <Button type="button" variant="outline" size="sm" onClick={copyTechProgress} disabled={!techProgress.message}>
                                                    Copy
                                                </Button>
                                            </div>
                                        </div>
                                        <textarea
                                            readOnly
                                            className="flex min-h-[260px] w-full rounded-md border border-input bg-muted/40 px-3 py-2 text-sm font-mono"
                                            value={techProgress.message || ''}
                                        />
                                    </div>

                                    <div className="flex justify-end">
                                        <Button onClick={saveTechProgress} disabled={savingTechProgress} className="gap-2">
                                            {savingTechProgress ? <Loader2 className="w-4 h-4 animate-spin" /> : <Save className="w-4 h-4" />}
                                            Simpan Progress Harian
                                        </Button>
                                    </div>
                                </CardContent>
                            </Card>

                            {/* Notifications */}
                            <Card>
                                <CardHeader><CardTitle>Notifikasi</CardTitle><CardDescription>Toggle notifikasi WA.</CardDescription></CardHeader>
                                <CardContent className="space-y-4">
                                    <div className="flex items-center justify-between">
                                        <div><Label>Tiket Baru</Label><p className="text-sm text-muted-foreground">Notif saat tiket dibuat.</p></div>
                                        <Switch checked={waNotifs.wa_notif_ticket_created} onCheckedChange={v => { markSectionDirty('wagateway'); setWaNotifs(n => ({ ...n, wa_notif_ticket_created: v })); }} />
                                    </div>
                                    <Separator />
                                    <div className="flex items-center justify-between">
                                        <div><Label>Status Berubah</Label><p className="text-sm text-muted-foreground">Notif saat status berubah.</p></div>
                                        <Switch checked={waNotifs.wa_notif_ticket_status} onCheckedChange={v => { markSectionDirty('wagateway'); setWaNotifs(n => ({ ...n, wa_notif_ticket_status: v })); }} />
                                    </div>
                                    <Separator />
                                    <div className="flex items-center justify-between">
                                        <div><Label>Laporan</Label><p className="text-sm text-muted-foreground">Kirim laporan ke grup.</p></div>
                                        <Switch checked={waNotifs.wa_notif_report} onCheckedChange={v => { markSectionDirty('wagateway'); setWaNotifs(n => ({ ...n, wa_notif_report: v })); }} />
                                    </div>
                                    <div className="flex justify-end pt-2">
                                        <Button onClick={handleSaveWaNotifs} disabled={loading} className="gap-2">
                                            {loading ? <Loader2 className="w-4 h-4 animate-spin" /> : <Zap className="w-4 h-4" />}Simpan Notifikasi
                                        </Button>
                                    </div>
                                </CardContent>
                            </Card>

                            {/* Test */}
                            <Card>
                                <CardHeader><CardTitle className="flex items-center gap-2"><Send className="w-5 h-5" />Kirim Test</CardTitle><CardDescription>Uji coba kirim WA.</CardDescription></CardHeader>
                                <CardContent className="space-y-4">
                                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div className="space-y-2"><Label>Nomor</Label><Input value={testTo} onChange={e => setTestTo(e.target.value)} placeholder="6281234567890" /></div>
                                        <div className="space-y-2"><Label>Pesan</Label><Input value={testMsg} onChange={e => setTestMsg(e.target.value)} /></div>
                                    </div>
                                    <div className="flex justify-end">
                                        <Button onClick={handleTestMessage} disabled={sendingTest} variant="outline" className="gap-2">
                                            {sendingTest ? <Loader2 className="w-4 h-4 animate-spin" /> : <Send className="w-4 h-4" />}Kirim Test
                                        </Button>
                                    </div>
                                </CardContent>
                            </Card>
                        </div>
                )}

                {activeTab === 'billingwa' && (
                    <div className="space-y-6" onChangeCapture={() => markSectionDirty('billingwa')}>
                        <Card className="border-status-degraded/30">
                            <CardHeader>
                                <CardTitle>WA tagihan massal</CardTitle>
                                <CardDescription>
                                    Capability terpisah dari PSB/migrasi. Default disabled + dry-run; dry-run tidak memanggil provider dan tidak membuat job.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="grid gap-3 md:grid-cols-2">
                                    <div className="flex items-center justify-between rounded-md border p-3">
                                        <div><Label>Enabled</Label><p className="text-xs text-muted-foreground">Boleh dipakai untuk preview/execute.</p></div>
                                        <Switch checked={financeBulkWa.enabled} onCheckedChange={(value) => { markSectionDirty('billingwa'); setFinanceBulkWa((state) => ({ ...state, enabled: value })); }} />
                                    </div>
                                    <div className="flex items-center justify-between rounded-md border p-3">
                                        <div><Label>Dry-run</Label><p className="text-xs text-muted-foreground">Tetap ON sampai ada approval pengiriman nyata.</p></div>
                                        <Switch checked={financeBulkWa.dry_run} onCheckedChange={(value) => { markSectionDirty('billingwa'); setFinanceBulkWa((state) => ({ ...state, dry_run: value })); }} />
                                    </div>
                                </div>
                                <div className="grid gap-3 md:grid-cols-2">
                                    <label className="grid gap-1 text-sm font-medium">Maksimal penerima<Input type="number" min={1} max={1000} value={financeBulkWa.max_recipients} onChange={(event) => setFinanceBulkWa((state) => ({ ...state, max_recipients: Number(event.target.value || 1) }))} /></label>
                                    <label className="grid gap-1 text-sm font-medium">Rate limit per menit<Input type="number" min={1} max={600} value={financeBulkWa.rate_limit_per_minute} onChange={(event) => setFinanceBulkWa((state) => ({ ...state, rate_limit_per_minute: Number(event.target.value || 1) }))} /></label>
                                </div>
                                <div className="flex flex-wrap items-center justify-between gap-3 rounded-md border bg-muted/30 p-3 text-sm">
                                    <span>Status: <strong>{financeBulkWa.enabled ? (financeBulkWa.dry_run ? 'ENABLED / DRY-RUN' : 'ENABLED / LIVE') : 'DISABLED'}</strong>. Role: {financeBulkWa.allowed_roles.join(', ')}.</span>
                                    <Button type="button" variant={financeBulkWa.enabled && !financeBulkWa.dry_run ? 'destructive' : 'outline'} disabled={savingFinanceBulkWa} onClick={() => void handleSaveFinanceBulkWa()}>
                                        {savingFinanceBulkWa ? <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" /> : <Save className="h-4 w-4" aria-hidden="true" />}
                                        Simpan setting massal
                                    </Button>
                                </div>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <MessageCircle className="w-5 h-5" />
                                    Broadcast Billing (WA otomatis)
                                </CardTitle>
                                <CardDescription>
                                    Auto-payment WA dan mass broadcast adalah capability terpisah. Template bisa beda per lokasi (area).
                                    {(!billingWa.billing_sending_enabled && !billingWa.broadcast_sending_enabled) && (
                                        <span className="block mt-1 text-xs font-medium text-status-degraded">
                                            Dry-run aktif: pesan billing tidak dikirim sampai Tiar approve live.
                                        </span>
                                    )}
                                    Isolir: template disimpan saja — <strong>belum dijalankan</strong>.
                                    {billingWa.last_invoice_sync_at && (
                                        <span className="block mt-1 text-xs">
                                            Last invoice sync eBilling: {billingWa.last_invoice_sync_at} WIB
                                        </span>
                                    )}
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-5">
                                <div className="grid gap-3 md:grid-cols-2">
                                    <div className="rounded-md border bg-muted/30 p-3">
                                        <div className="flex items-center justify-between gap-2"><Label>Auto-payment WA</Label><Badge variant={billingWa.billing_sending_enabled ? 'destructive' : 'secondary'}>{billingWa.billing_sending_enabled ? 'LIVE' : 'DRY RUN'}</Badge></div>
                                        <p className="mt-1 text-xs text-muted-foreground">Kirim setelah payment verified/paid. Tidak terkait mass broadcast.</p>
                                        <Button className="mt-3" size="sm" variant={billingWa.billing_sending_enabled ? 'outline' : 'destructive'} disabled={togglingBillingLive} onClick={() => void toggleBillingLive(!billingWa.billing_sending_enabled)}>{togglingBillingLive ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : null}{billingWa.billing_sending_enabled ? 'Jadikan Dry Run' : 'Aktifkan Auto-payment'}</Button>
                                    </div>
                                    <div className="rounded-md border bg-muted/30 p-3">
                                        <div className="flex items-center justify-between gap-2"><Label>Mass broadcast</Label><Badge variant={billingWa.broadcast_sending_enabled ? 'destructive' : 'secondary'}>{billingWa.broadcast_sending_enabled ? 'LIVE' : 'DRY RUN'}</Badge></div>
                                        <p className="mt-1 text-xs text-muted-foreground">Kirim kampanye massal melalui channel Billing & Broadcast.</p>
                                    </div>
                                </div>
                                <div className="flex flex-wrap items-center justify-between gap-3 rounded-md border bg-muted/30 p-3">
                                    <div className="space-y-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <Label>Mode Mass Broadcast</Label>
                                            {billingWa.live_sending_enabled ? (
                                                <Badge variant="destructive">LIVE</Badge>
                                            ) : (
                                                <Badge variant="warning">
                                                    DRY RUN
                                                </Badge>
                                            )}
                                        </div>
                                        <p className="text-sm text-muted-foreground">
                                            {billingWa.live_sending_enabled
                                                ? 'Broadcast berikutnya akan dikirim nyata lewat nomor Billing & Broadcast.'
                                                : 'Broadcast berikutnya akan dipause/dry-run, tidak dikirim ke pelanggan.'}
                                        </p>
                                    </div>
                                    {billingWa.live_sending_enabled ? (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            disabled={togglingBroadcastLive}
                                            onClick={() => void toggleBroadcastLive(false)}
                                        >
                                            {togglingBroadcastLive ? <Loader2 className="w-4 h-4 animate-spin mr-2" /> : null}
                                            Jadikan Dry Run
                                        </Button>
                                    ) : (
                                        <Button
                                            type="button"
                                            variant="destructive"
                                            disabled={togglingBroadcastLive}
                                            onClick={() => void toggleBroadcastLive(true)}
                                        >
                                            {togglingBroadcastLive ? <Loader2 className="w-4 h-4 animate-spin mr-2" /> : null}
                                            Aktifkan Live Broadcast
                                        </Button>
                                    )}
                                </div>

                                <div className="flex items-center justify-between">
                                    <div>
                                        <Label>Pembayaran lunas</Label>
                                        <p className="text-sm text-muted-foreground">Kirim WA setelah invoice paid (manual + gateway).</p>
                                    </div>
                                    <Switch
                                        checked={!!billingWa.payment_enabled}
                                        onCheckedChange={(v) => { markSectionDirty('billingwa'); setBillingWa((s) => ({ ...s, payment_enabled: v })); }}
                                    />
                                </div>
                                <Separator />
                                <div className="flex items-center justify-between">
                                    <div>
                                        <Label>Pelanggan baru</Label>
                                        <p className="text-sm text-muted-foreground">Kirim WA welcome saat customer dibuat.</p>
                                    </div>
                                    <Switch
                                        checked={!!billingWa.customer_created_enabled}
                                        onCheckedChange={(v) => { markSectionDirty('billingwa'); setBillingWa((s) => ({ ...s, customer_created_enabled: v })); }}
                                    />
                                </div>
                                <Separator />
                                <div className="flex items-center justify-between opacity-70">
                                    <div>
                                        <Label>Isolir</Label>
                                        <p className="text-sm text-muted-foreground">
                                            Scaffold only — tidak dikirim di fase ini.
                                            <Badge variant="secondary" className="ml-2">Belum aktif</Badge>
                                        </p>
                                    </div>
                                    <Switch checked={false} disabled />
                                </div>

                                <div className="rounded-md border bg-muted/30 p-3 text-xs text-muted-foreground">
                                    Placeholder: {(billingWa.placeholders || []).join(' ')}
                                    <div className="mt-2">
                                        Template pembayaran mendukung <code>{'{receipt_link}'}</code> untuk kuitansi PDF pelanggan.
                                        <code>{'{payment_link}'}</code> tetap khusus link pembayaran.
                                    </div>
                                </div>

                                <div className="space-y-2 rounded-md border bg-background p-3">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <Label>Preview pembayaran (dummy)</Label>
                                        <Badge variant="secondary">TIDAK DIKIRIM</Badge>
                                    </div>
                                    <p className="text-xs text-muted-foreground">
                                        Contoh ini dirender di browser. URL kuitansi bertanda PREVIEW_ONLY dan tidak valid untuk pelanggan.
                                    </p>
                                    <pre className="max-h-64 overflow-auto whitespace-pre-wrap rounded bg-muted/40 p-3 text-xs leading-relaxed">
                                        {renderBillingPreview(billingWa.payment_template || '')}
                                    </pre>
                                </div>

                                <div className="space-y-2">
                                    <Label>Template global — Pembayaran</Label>
                                    <textarea
                                        className="flex min-h-[100px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm font-mono"
                                        value={billingWa.payment_template || ''}
                                        onChange={(e) => setBillingWa((s) => ({ ...s, payment_template: e.target.value }))}
                                    />
                                </div>
                                <div className="space-y-2">
                                    <Label>Template global — Pelanggan baru</Label>
                                    <textarea
                                        className="flex min-h-[100px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm font-mono"
                                        value={billingWa.customer_created_template || ''}
                                        onChange={(e) => setBillingWa((s) => ({ ...s, customer_created_template: e.target.value }))}
                                    />
                                </div>
                                <div className="space-y-2">
                                    <Label>Template global — Isolir (belum dikirim)</Label>
                                    <textarea
                                        className="flex min-h-[80px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm font-mono opacity-80"
                                        value={billingWa.isolation_template || ''}
                                        onChange={(e) => setBillingWa((s) => ({ ...s, isolation_template: e.target.value }))}
                                    />
                                </div>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle>Template per lokasi (Area)</CardTitle>
                                <CardDescription>
                                    Override global untuk area tertentu. Kosongkan = pakai template global.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="space-y-2">
                                    <Label>Pilih Area</Label>
                                    <select
                                        className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                                        value={areaEditId}
                                        onChange={(e) => setAreaEditId(e.target.value)}
                                    >
                                        <option value="">-- Pilih area --</option>
                                        {areas.map((a) => (
                                            <option key={a.id} value={String(a.id)}>{a.name}</option>
                                        ))}
                                    </select>
                                </div>
                                {areaEditId && (
                                    <>
                                        <div className="space-y-2">
                                            <Label>Override pembayaran</Label>
                                            <textarea
                                                className="flex min-h-[80px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm font-mono"
                                                value={areaPaymentTpl}
                                                onChange={(e) => setAreaPaymentTpl(e.target.value)}
                                                placeholder="Kosong = global"
                                            />
                                        </div>
                                        <div className="space-y-2 rounded-md border bg-muted/30 p-3">
                                            <div className="flex flex-wrap items-center justify-between gap-2">
                                                <Label>Preview pembayaran area (dummy)</Label>
                                                <Badge variant="secondary">TIDAK DIKIRIM</Badge>
                                            </div>
                                            <pre className="max-h-48 overflow-auto whitespace-pre-wrap rounded bg-background p-3 text-xs leading-relaxed">
                                                {renderBillingPreview(areaPaymentTpl || billingWa.payment_template || '')}
                                            </pre>
                                        </div>
                                        <div className="space-y-2">
                                            <Label>Override pelanggan baru</Label>
                                            <textarea
                                                className="flex min-h-[80px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm font-mono"
                                                value={areaCustomerTpl}
                                                onChange={(e) => setAreaCustomerTpl(e.target.value)}
                                                placeholder="Kosong = global"
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label>Override isolir (scaffold)</Label>
                                            <textarea
                                                className="flex min-h-[60px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm font-mono opacity-80"
                                                value={areaIsolationTpl}
                                                onChange={(e) => setAreaIsolationTpl(e.target.value)}
                                                placeholder="Kosong = global"
                                            />
                                        </div>
                                    </>
                                )}
                                <div className="flex justify-end">
                                    <Button onClick={handleSaveBillingWa} disabled={loading} className="gap-2">
                                        {loading ? <Loader2 className="w-4 h-4 animate-spin" /> : <Save className="w-4 h-4" />}
                                        Simpan Broadcast Billing
                                    </Button>
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                )}

                {activeTab === 'enforcement' && (
                    <div className="space-y-6" onChangeCapture={() => markSectionDirty('enforcement')}>
                        <Card>
                            <CardHeader>
                                <div className="flex flex-wrap items-center gap-2">
                                    <CardTitle>Billing Enforcement</CardTitle>
                                    {enforcement.dry_run ? (
                                        <Badge variant="warning">
                                            DRY RUN AKTIF
                                        </Badge>
                                    ) : (
                                        <Badge variant="destructive">LIVE — isolir nyata</Badge>
                                    )}
                                </div>
                                <CardDescription>
                                    Status langganan: Aktif → Isolir (H+N) → Berhenti (H+M).
                                    Saat dry-run, scheduler hanya mencatat kandidat — tidak putus internet / ganti status.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-5">
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="space-y-2">
                                        <Label>Isolir setelah jatuh tempo (hari)</Label>
                                        <Input
                                            type="number"
                                            min={0}
                                            max={365}
                                            value={enforcement.isolir_after_days}
                                            onChange={(e) =>
                                                setEnforcement((s) => ({
                                                    ...s,
                                                    isolir_after_days: Number(e.target.value || 0),
                                                }))
                                            }
                                        />
                                        <p className="text-xs text-muted-foreground">Default request: H+3</p>
                                    </div>
                                    <div className="space-y-2">
                                        <Label>Berhenti setelah jatuh tempo (hari)</Label>
                                        <Input
                                            type="number"
                                            min={1}
                                            max={730}
                                            value={enforcement.dismantle_after_days}
                                            onChange={(e) =>
                                                setEnforcement((s) => ({
                                                    ...s,
                                                    dismantle_after_days: Number(e.target.value || 33),
                                                }))
                                            }
                                        />
                                        <p className="text-xs text-muted-foreground">Default request: H+33</p>
                                    </div>
                                </div>

                                <div className="space-y-3 rounded-md border border-border p-4">
                                    <div>
                                        <p className="text-sm font-medium">Jatuh tempo tetap pelanggan baru</p>
                                        <p className="text-xs text-muted-foreground">
                                            Pelanggan baru (PSB) di router / area berikut mendapat tanggal jatuh tempo tetap.
                                            Lainnya tetap memakai tanggal bergabung. Hanya berlaku saat pelanggan dibuat.
                                        </p>
                                    </div>
                                    <div className="grid gap-4 sm:grid-cols-3">
                                        <div className="space-y-2">
                                            <Label>Tanggal jatuh tempo</Label>
                                            <Input
                                                type="number"
                                                min={1}
                                                max={31}
                                                value={enforcement.fixed_due_day ?? 25}
                                                onChange={(e) =>
                                                    setEnforcement((s) => ({
                                                        ...s,
                                                        fixed_due_day: Number(e.target.value || 25),
                                                    }))
                                                }
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label>Router ID (koma)</Label>
                                            <Input
                                                value={enforcement.fixed_due_day_router_ids ?? ''}
                                                placeholder="513"
                                                onChange={(e) =>
                                                    setEnforcement((s) => ({
                                                        ...s,
                                                        fixed_due_day_router_ids: e.target.value,
                                                    }))
                                                }
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label>Area ID (koma)</Label>
                                            <Input
                                                value={enforcement.fixed_due_day_area_ids ?? ''}
                                                placeholder="14"
                                                onChange={(e) =>
                                                    setEnforcement((s) => ({
                                                        ...s,
                                                        fixed_due_day_area_ids: e.target.value,
                                                    }))
                                                }
                                            />
                                        </div>
                                    </div>
                                    <p className="text-xs text-muted-foreground">
                                        Default: tanggal 25 untuk router 513 (SKYNET-RA-OLT) dan area 14 (STARLITE-AKUISISI).
                                        Disimpan lewat tombol &quot;Simpan threshold H+N&quot;.
                                    </p>
                                </div>

                                <Separator />

                                <div className="rounded-md border border-border bg-muted/30 p-4 space-y-2">
                                    <p className="text-sm font-medium">Mode dry-run</p>
                                    <p className="text-sm text-muted-foreground">
                                        {enforcement.dry_run
                                            ? 'ON — aman. Job harian log kandidat isolir/Berhenti saja.'
                                            : 'OFF — berbahaya. Scheduler akan isolir MT & set Berhenti.'}
                                    </p>
                                    <div className="flex flex-wrap gap-2 pt-2">
                                        {enforcement.dry_run ? (
                                            <Button
                                                variant="destructive"
                                                disabled={savingEnforcement}
                                                onClick={() => saveEnforcement({ dry_run: false }, true)}
                                            >
                                                {savingEnforcement ? (
                                                    <Loader2 className="w-4 h-4 animate-spin mr-2" />
                                                ) : null}
                                                Matikan dry-run (aktifkan isolir nyata)
                                            </Button>
                                        ) : (
                                            <Button
                                                variant="default"
                                                disabled={savingEnforcement}
                                                onClick={() => saveEnforcement({ dry_run: true })}
                                            >
                                                {savingEnforcement ? (
                                                    <Loader2 className="w-4 h-4 animate-spin mr-2" />
                                                ) : null}
                                                Nyalakan dry-run (aman)
                                            </Button>
                                        )}
                                        <Button
                                            variant="outline"
                                            disabled={savingEnforcement}
                                            onClick={() => saveEnforcement({})}
                                            className="gap-2"
                                        >
                                            {savingEnforcement ? (
                                                <Loader2 className="w-4 h-4 animate-spin" />
                                            ) : (
                                                <Save className="w-4 h-4" />
                                            )}
                                            Simpan threshold H+N
                                        </Button>
                                    </div>
                                </div>

                                {enforcement.last_run && (
                                    <div className="text-sm space-y-1 rounded-md border p-3 font-mono">
                                        <p className="font-sans font-medium">Last run</p>
                                        <p>at: {enforcement.last_run.at || '—'}</p>
                                        <p>dry_run: {String(enforcement.last_run.dry_run)}</p>
                                        <p>
                                            isolir_candidates:{' '}
                                            {enforcement.last_run.stats?.isolir_candidates ?? '—'} / dispatched:{' '}
                                            {enforcement.last_run.stats?.isolir_dispatched ?? '—'}
                                        </p>
                                        <p>
                                            dismantle_candidates:{' '}
                                            {enforcement.last_run.stats?.dismantle_candidates ?? '—'} / applied:{' '}
                                            {enforcement.last_run.stats?.dismantle_applied ?? '—'}
                                        </p>
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </div>
                )}

                {activeTab === 'midtrans' && (
                    <div className="space-y-6" onChangeCapture={() => markSectionDirty('midtrans')}>
                        <Card>
                            <CardHeader>
                                <CardTitle>Payment Gateway (Duitku / Midtrans)</CardTitle>
                                <CardDescription>
                                    Provider aktif, kredensial Duitku, metode, fee, whitelist IP callback dan kill switch Payment write
                                    diatur di halaman Payment Gateway.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <Button asChild variant="outline">
                                    <Link href={route('settings.payment-gateway')}>Buka pengaturan Payment Gateway</Link>
                                </Button>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader>
                                <div className="flex flex-wrap items-center gap-2">
                                    <CardTitle>Midtrans</CardTitle>
                                    {midtrans.configured ? (
                                        <Badge variant="up">
                                            Configured
                                        </Badge>
                                    ) : (
                                        <Badge variant="outline">Belum lengkap</Badge>
                                    )}
                                </div>
                                <CardDescription>
                                    Bayar tagihan online (Snap). Server key tidak dikirim ke browser setelah simpan.
                                    Webhook: POST signature Midtrans (bukan X-Webhook-Token).
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                {midtransLoading ? (
                                    <div className="flex items-center gap-2 text-sm text-muted-foreground">
                                        <Loader2 className="h-4 w-4 animate-spin" /> Memuat…
                                    </div>
                                ) : (
                                    <>
                                        <div className="grid gap-4 sm:grid-cols-2">
                                            <div className="space-y-2">
                                                <Label>Merchant ID</Label>
                                                <p className="text-xs text-muted-foreground">Gunakan nilai dari dashboard Midtrans.</p>
                                                <Input
                                                    value={midtrans.merchant_id}
                                                    onChange={(e) =>
                                                        setMidtrans((s) => ({ ...s, merchant_id: e.target.value }))
                                                    }
                                                    placeholder="M4041…"
                                                />
                                            </div>
                                            <div className="flex items-center gap-2 pt-6">
                                                <Switch
                                                    checked={midtrans.is_production}
                                                    onCheckedChange={(v) => {
                                                        markSectionDirty('midtrans');
                                                        setMidtrans((s) => ({ ...s, is_production: v }));
                                                    }}
                                                    id="mt-prod"
                                                />
                                                <Label htmlFor="mt-prod">
                                                    Production mode {midtrans.is_production ? '(live)' : '(sandbox)'}
                                                </Label>
                                            </div>
                                            <div className="space-y-2 sm:col-span-2">
                                                <Label>Client Key (kosongkan = tidak diubah)</Label>
                                                <Input
                                                    value={midtrans.client_key}
                                                    onChange={(e) =>
                                                        setMidtrans((s) => ({ ...s, client_key: e.target.value }))
                                                    }
                                                    placeholder="Mid-client-…"
                                                    autoComplete="off"
                                                />
                                            </div>
                                            <div className="space-y-2 sm:col-span-2">
                                                <Label>Server Key (kosongkan = tidak diubah)</Label>
                                                <Input
                                                    type="password"
                                                    value={midtrans.server_key}
                                                    onChange={(e) =>
                                                        setMidtrans((s) => ({ ...s, server_key: e.target.value }))
                                                    }
                                                    placeholder="Mid-server-…"
                                                    autoComplete="new-password"
                                                />
                                            </div>
                                        </div>
                                        {midtrans.webhook_url && (
                                            <div className="rounded-md border p-3 text-sm font-mono break-all">
                                                <p className="font-sans font-medium mb-1">Notification URL (Midtrans dashboard)</p>
                                                {midtrans.webhook_url}
                                            </div>
                                        )}
                                        <Button onClick={saveMidtrans} disabled={midtransSaving} className="gap-2">
                                            {midtransSaving ? (
                                                <Loader2 className="h-4 w-4 animate-spin" />
                                            ) : (
                                                <Save className="h-4 w-4" />
                                            )}
                                            Simpan Midtrans
                                        </Button>
                                    </>
                                )}
                            </CardContent>
                        </Card>
                    </div>
                )}
            </div>
        </EnterpriseIndexLayout>
    );
}
