import { EnterpriseIndexLayout } from '@/Components/enterprise/EnterpriseIndexLayout';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Badge } from '@/Components/ui/badge';
import {
    Camera, Terminal, Search, CheckCircle2, AlertCircle, Loader2, Copy, Zap, Info, Cable, RotateCcw, Network, MonitorSmartphone, User, MapPin, MessageCircle, RefreshCw, Paperclip, ArrowRight, ArrowLeft, KeyRound
} from 'lucide-react';
import { lazy, Suspense, useState, useRef, useEffect, useMemo } from 'react';
import { ScrollArea } from '@/Components/ui/scroll-area';
import { Popover, PopoverContent, PopoverTrigger } from '@/Components/ui/popover';
import { toast } from 'sonner';
import axios from 'axios';
import { readMetaCsrfToken, readXsrfCookieToken } from '@/lib/csrf';
import { formatIdr } from '@/lib/format';
import { formatWibTime } from '@/lib/dateTime';
import { useUnsavedChangesGuard } from '@/hooks/useUnsavedChangesGuard';
import { ConfirmDestructive } from '@/Components/ConfirmDestructive';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import { MigrationLinkPanel } from '@/Components/Aktivasi/MigrationLinkPanel';

const PsbWaPanel = lazy(() => import('@/Components/PsbWa/PsbWaPanel'));
const RouterVlanPanel = lazy(() => import('@/Components/Aktivasi/RouterVlanPanel'));

interface Props {
    olts: { id: number; name: string; area_id?: number | null; area_name?: string | null }[];
    /** Preloaded for Auto-PPPoE — avoids /api/areas + /api/routers on first paint */
    areas?: { id: number; name: string }[];
    routers?: { id: number; name: string }[];
    /** Huawei MA5608T OLTs (Gadang) for the Provision Huawei tab */
    huawei_olts?: { id: number; name: string; vendor?: string; area_id?: number | null; area_name?: string | null }[];
    /** Open tab: autopp | activate | reconfigure | fiberhome | huawei | psb | vlan-mt | vlan-olt | rename */
    initialTab?: string;
    /** Helper text under "Password PPPoE" (config site.psb_password_hint); backticks mark <code>. */
    psb_password_hint?: string; psb_default_acs_url?: string;
}

type Step = 'upload' | 'confirm' | 'provisioning' | 'done';
type LogType = 'info' | 'cmd' | 'success' | 'error' | 'phase' | 'output';
type LogEntry = { time: string; type: LogType; msg: string; };
type VlanPort = { value: string; label: string; vlan: number };
type VlanMapping = { id: number; olt_id?: number; pon_port: string; service_type?: string; vlan: number; description: string | null; is_active?: boolean; _effective_only?: boolean };
const RECONFIG_PORTS = [
    { value: '1/2/1', label: '1/2/1 (VLAN 100)' },
    { value: '1/2/2', label: '1/2/2 (VLAN 101)' },
    { value: '1/2/3', label: '1/2/3 (VLAN 102)' },
    { value: '1/2/4', label: '1/2/4 (VLAN 103)' },
    { value: '1/2/5', label: '1/2/5 (VLAN 104)' },
    { value: '1/2/6', label: '1/2/6 (VLAN 105)' },
    { value: '1/2/7', label: '1/2/7 (VLAN 207)' },
    { value: '1/2/8', label: '1/2/8 (VLAN 208)' },
    { value: '1/2/9', label: '1/2/9 (VLAN 202)' },
    { value: '1/2/10', label: '1/2/10 (VLAN 203)' },
    { value: '1/2/12', label: '1/2/12 (VLAN 205)' },
    { value: '1/2/13', label: '1/2/13 (VLAN 206)' },
    { value: '1/2/14', label: '1/2/14 (VLAN 72)' },
    { value: '1/2/15', label: '1/2/15 (VLAN 200)' },
    { value: '1/2/16', label: '1/2/16 (VLAN 216)' },
    { value: '1/3/1', label: '1/3/1 (VLAN 301)' },
    { value: '1/3/2', label: '1/3/2 (VLAN 302)' },
    { value: '1/3/11', label: '1/3/11 (VLAN 311)' },
    { value: '1/3/13', label: '1/3/13 (VLAN 313)' },
    { value: '1/3/16', label: '1/3/16 (VLAN 316)' },
];

function redactPppoeText(value: string): string {
    return value
        .replace(/(\bpassword\s+).*?(\s+vlan-profile\b)/gi, '$1[REDACTED]$2')
        .replace(/(\bpassword\s*[:=]\s*)[^\s,;]+/gi, '$1[REDACTED]');
}

function getProvisionCommands(port: string, onuId: number, name: string, vlan: number, sn: string, password: string, tr069Vlan = 69) {
    return [
        {
            phase: '1 — Daftar ONU',
            desc: 'Mendaftarkan ONU baru ke port PON di OLT',
            cmds: [`configure terminal`, `interface gpon-olt_${port}`, `onu ${onuId} type ZTE sn ${sn}`, `exit`],
        },
        {
            phase: '2 — Set Nama & VLAN',
            desc: 'Beri nama pelanggan, alokasi bandwidth 1G, pasang VLAN & service-port',
            cmds: [`interface gpon-onu_${port}:${onuId}`, `name ${name}`, `tcont 1 profile 1G`, `gemport 1 tcont 1`, `service-port 1 vport 1 user-vlan ${vlan} vlan ${vlan}`, `service-port 2 vport 1 user-vlan ${tr069Vlan} vlan ${tr069Vlan}`, `exit`, `exit`],
        },
        {
            phase: '3 — Set PPPoE & Remote',
            desc: 'Pasang PPPoE (username/password) + TR-069 (remote management)',
            cmds: [`pon-onu-mng gpon-onu_${port}:${onuId}`, `service 1 gemport 1 vlan ${vlan}`, `service 2 gemport 1 vlan ${tr069Vlan}`, `wan-ip 1 mode pppoe username ${name} password ${password} vlan-profile PPPoE${vlan} host 1`, `wan-ip 2 mode tr069 vlan-profile ${tr069Vlan} host 2`, `exit`, `exit`],
        },
        {
            phase: '4 — Cek Status',
            desc: 'Verifikasi ONU sudah online & redaman sinyal normal',
            cmds: [`show gpon onu detail-info gpon-onu_${port}:${onuId}`],
        },
    ];
}

function getReconfigCommands(port: string, onuId: number, name: string, vlan: number, sn: string, password: string, tr069Vlan = 69) {
    return [
        {
            phase: '1 — Hapus ONU Lama',
            desc: 'Menghapus konfigurasi ONU lama dari port PON',
            cmds: [`configure terminal`, `interface gpon-olt_${port}`, `no onu ${onuId}`, `exit`, `exit`],
        },
        {
            phase: '2 — Daftar Ulang ONU',
            desc: 'Mendaftarkan ONU baru dengan SN baru di slot yang sama',
            cmds: [`configure terminal`, `interface gpon-olt_${port}`, `onu ${onuId} type ZTE-F660 sn ${sn}`, `exit`, `exit`],
        },
        {
            phase: '3 — Set Nama & VLAN',
            desc: 'Beri nama pelanggan, bandwidth 1G, VLAN, dan service-port',
            cmds: [`configure terminal`, `interface gpon-onu_${port}:${onuId}`, `name ${name}`, `tcont 1 profile 1G`, `gemport 1 tcont 1`, `service-port 1 vport 1 user-vlan ${vlan} vlan ${vlan}`, `service-port 2 vport 1 user-vlan ${tr069Vlan} vlan ${tr069Vlan}`, `exit`, `exit`],
        },
        {
            phase: '4 — Set PPPoE & Remote',
            desc: 'Pasang PPPoE (username/password) + TR-069 (remote management)',
            cmds: [`configure terminal`, `pon-onu-mng gpon-onu_${port}:${onuId}`, `service 1 gemport 1 vlan ${vlan}`, `service 2 gemport 1 vlan ${tr069Vlan}`, `wan-ip 1 mode pppoe username ${name} password ${password} vlan-profile PPPoE${vlan} host 1`, `wan-ip 2 mode tr069 vlan-profile ${tr069Vlan} host 2`, `exit`, `exit`],
        },
        {
            phase: '5 — Cek Status',
            desc: 'Verifikasi ONU baru sudah online',
            cmds: [`show gpon onu detail-info gpon-onu_${port}:${onuId}`],
        },
    ];
}

function ConsoleLog({ log, onCopy, onReset, maxHeight = 'h-64' }: { log: LogEntry[]; onCopy?: () => void; onReset?: () => void; maxHeight?: string }) {
    const endRef = useRef<HTMLDivElement>(null);
    useEffect(() => { endRef.current?.scrollIntoView({ behavior: 'smooth' }); }, [log]);
    if (log.length === 0) return null;
    const colorMap: Record<string, string> = {
        info: 'text-blue-300', cmd: 'text-green-400', success: 'text-emerald-400',
        error: 'text-red-400', phase: 'text-amber-400 font-semibold', output: 'text-slate-400',
    };
    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between p-3">
                <div>
                    <span className="text-sm font-semibold text-foreground flex items-center gap-1.5">
                        <MonitorSmartphone className="h-4 w-4" /> Konsol Perintah OLT
                    </span>
                    <p className="text-xs text-muted-foreground mt-0.5">Perintah SSH yang dikirim ke OLT akan muncul di sini</p>
                </div>
                <div className="flex gap-2">
                    {onReset && <Button variant="ghost" size="sm" onClick={onReset}>Reset</Button>}
                    {onCopy && <Button variant="ghost" size="sm" onClick={onCopy}><Copy className="h-3 w-3 mr-1" />Salin</Button>}
                </div>
            </CardHeader>
            <CardContent className="p-0">
                <ScrollArea className={maxHeight}>
                    <pre className="text-xs font-mono p-3 bg-black rounded-b-lg whitespace-pre-wrap">
                        {log.map((l, i) => (
                            <div key={i} className={colorMap[l.type] || 'text-green-400'}>
                                <span className="text-slate-500">[{l.time}]</span> {l.msg}
                            </div>
                        ))}
                        <div ref={endRef} />
                    </pre>
                </ScrollArea>
            </CardContent>
        </Card>
    );
}

type AktivasiTab = 'autopp' | 'activate' | 'reconfigure' | 'fiberhome' | 'huawei' | 'psb' | 'vlan-mt' | 'vlan-olt' | 'rename';

function resolveInitialTab(raw?: string): AktivasiTab {
    const t = (raw || '').toLowerCase();
    if (t === 'activate' || t === 'reconfigure' || t === 'fiberhome' || t === 'huawei' || t === 'psb' || t === 'autopp' || t === 'vlan-mt' || t === 'vlan-olt' || t === 'rename') {
        return t;
    }
    // ?tab=psb-wa from redirects
    if (t === 'psb-wa' || t === 'psbwa') return 'psb';
    if (t === 'vlan' || t === 'vlanmt' || t === 'router-vlan') return 'vlan-mt';
    if (t === 'rename-onu' || t === 'ganti-nama') return 'rename';
    return 'autopp';
}

/** Normalize ONU display name: UPPER + underscore (sama OLT / ACS). */
function normalizeOnuName(raw: string): string {
    return raw
        .trim()
        .toUpperCase()
        .replace(/[\s-]+/g, '_')
        .replace(/[^A-Z0-9_.]/g, '')
        .replace(/_+/g, '_')
        .replace(/^_+|_+$/g, '');
}

export default function Index({ olts, areas: initialAreas = [], routers: initialRouters = [], huawei_olts: huaweiOlts = [], initialTab, psb_password_hint: psbPasswordHint = '', psb_default_acs_url: psbDefaultAcsUrl = '' }: Props) {
    const [step, setStep] = useState<Step>('upload');
    const [oltId, setOltId] = useState<string>('');
    const [sn, setSn] = useState<string>('');
    const [name, setName] = useState<string>('');
    const [password, setPassword] = useState<string>('');
    const [loading, setLoading] = useState(false);
    const [result, setResult] = useState<any>(null);
    const [log, setLog] = useState<LogEntry[]>([]);
    const fileInputRef = useRef<HTMLInputElement>(null);
    const [preview, setPreview] = useState<string | null>(null);
    const [selectedPort, setSelectedPort] = useState<string>('');
    const [portChoices, setPortChoices] = useState<any[]>([]);
    const [activeTab, setActiveTab] = useState<AktivasiTab>(() => resolveInitialTab(initialTab));
    const [connectionStatus, setConnectionStatus] = useState<any>(null);
    const [testingConnection, setTestingConnection] = useState(false);
    const [showCredModal, setShowCredModal] = useState(false);
    const [credUsername, setCredUsername] = useState<string>('');

    // --- Rename ONU ---
    const [renameOltId, setRenameOltId] = useState<string>('');
    const [renameSn, setRenameSn] = useState('');
    const [renameOnuRef, setRenameOnuRef] = useState('');
    const [renameCurrentName, setRenameCurrentName] = useState('');
    const [renameNewName, setRenameNewName] = useState('');
    const [renameLooking, setRenameLooking] = useState(false);
    const [renameSaving, setRenameSaving] = useState(false);
    const [renameLog, setRenameLog] = useState<LogEntry[]>([]);
    const [renameResult, setRenameResult] = useState<any>(null);
    const [renameConfirmOpen, setRenameConfirmOpen] = useState(false);

    const addRenameLog = (type: LogType, msg: string) => {
        const time = formatWibTime(new Date(), true);
        setRenameLog((prev) => [...prev, { time, type, msg }]);
    };

    const lookupRenameOnu = async () => {
        if (!renameOltId) {
            toast.error('Pilih OLT dulu');
            return;
        }
        if (!renameSn.trim() && !renameOnuRef.trim()) {
            toast.error('Isi SN atau ONU ref (contoh 1/3/3:40)');
            return;
        }
        setRenameLooking(true);
        setRenameResult(null);
        addRenameLog('info', 'Cari ONU di inventory…');
        try {
            const q = new URLSearchParams();
            if (renameSn.trim()) q.set('sn', renameSn.trim().toUpperCase());
            if (renameOnuRef.trim()) q.set('onu_ref', renameOnuRef.trim());
            const res = await fetch(`/api/olts/${renameOltId}/onu/lookup-rename?${q}`, {
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': getCsrf() },
                credentials: 'include',
            });
            const data = await res.json();
            if (!res.ok || !data.success) {
                addRenameLog('error', data.error || `HTTP ${res.status}`);
                toast.error(data.error || 'ONU tidak ketemu');
                return;
            }
            setRenameOnuRef(String(data.onu_ref || ''));
            setRenameSn(String(data.sn || renameSn).toUpperCase());
            setRenameCurrentName(String(data.onu_name || ''));
            if (!renameNewName && data.onu_name) setRenameNewName(String(data.onu_name));
            addRenameLog('success', `Ketemu ${data.onu_ref} · name=${data.onu_name || '-'} · SN=${data.sn || '-'}`);
            toast.success(`ONU ${data.onu_ref}`);
        } catch (e: any) {
            addRenameLog('error', e?.message || 'Lookup gagal');
            toast.error('Lookup gagal');
        } finally {
            setRenameLooking(false);
        }
    };

    const submitRenameOnu = async (liveConfirm: string) => {
        if (!renameOltId) {
            toast.error('Pilih OLT');
            return;
        }
        const name = normalizeOnuName(renameNewName);
        if (!name) {
            toast.error('Nama baru tidak valid');
            return;
        }
        if (!renameOnuRef.trim() && !renameSn.trim()) {
            toast.error('Isi ONU ref atau SN (klik Cari dulu)');
            return;
        }
        setRenameSaving(true);
        setRenameResult(null);
        addRenameLog('phase', `Rename OLT + ACS → ${name}`);
        addRenameLog('cmd', `interface gpon-onu_${renameOnuRef || '?'} · name ${name} · ACS tag auto`);
        try {
            const res = await fetch(`/api/olts/${renameOltId}/onu/rename`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': getCsrf(),
                    'X-XSRF-TOKEN': readXsrfCookieToken() || '',
                },
                credentials: 'include',
                body: JSON.stringify({
                    onu_ref: renameOnuRef.trim() || undefined,
                    sn: renameSn.trim() || undefined,
                    name,
                    sync_acs: true, // always — backend forces ACS rename
                    live_confirm: liveConfirm,
                }),
            });
            const data = await res.json();
            if (!res.ok || data.error) {
                addRenameLog('error', data.error || `HTTP ${res.status}`);
                toast.error(data.error || 'Rename gagal');
                return;
            }
            setRenameResult(data);
            setRenameCurrentName(data.name || name);
            setRenameNewName(data.name || name);
            if (data.onu_ref) setRenameOnuRef(String(data.onu_ref));
            if (data.sn) setRenameSn(String(data.sn));
            addRenameLog('success', data.message || `OK ${data.onu_ref} → ${data.name}`);
            if (data.acs_tag) {
                const acs = data.acs_tag;
                if (acs.pushed) {
                    addRenameLog('success', `ACS tag → ${acs.tag || name}${acs.removed_tags?.length ? ` (hapus tag lama: ${acs.removed_tags.join(', ')})` : ''}`);
                } else if (acs.queued) {
                    addRenameLog('info', `ACS: diantrikan — ${acs.message || 'device belum inform'}`);
                } else {
                    addRenameLog('info', `ACS: ${acs.message || 'pending'}`);
                }
            }
            toast.success(
                data.acs_synced
                    ? `OLT + ACS: ${data.name}`
                    : `OLT: ${data.name} (ACS pending/queue)`,
            );
        } catch (e: any) {
            addRenameLog('error', e?.message || 'Rename gagal');
            toast.error('Rename gagal');
        } finally {
            setRenameSaving(false);
        }
    };
    const [credPassword, setCredPassword] = useState<string>('');
    const [credSaving, setCredSaving] = useState(false);

    const [reconfigOltId, setReconfigOltId] = useState<string>('');
    const [reconfigPort, setReconfigPort] = useState<string>('');
    const [reconfigOnuId, setReconfigOnuId] = useState<string>('1');
    const [reconfigSn, setReconfigSn] = useState<string>('');
    const [reconfigName, setReconfigName] = useState<string>('');
    const [reconfigPassword, setReconfigPassword] = useState<string>('');
    const [reconfigLoading, setReconfigLoading] = useState(false);
    const [reconfigLog, setReconfigLog] = useState<LogEntry[]>([]);
    const [reconfigResult, setReconfigResult] = useState<any>(null);
    const [reconfigConfirmOpen, setReconfigConfirmOpen] = useState(false);

    // ===== FiberHome provision state =====
    const [fhOltId, setFhOltId] = useState<string>('');
    const [fhSn, setFhSn] = useState<string>('');
    const [fhPort, setFhPort] = useState<string>('');
    const [fhOnuId, setFhOnuId] = useState<string>('1');
    const [fhName, setFhName] = useState<string>('');
    const [fhNameSource, setFhNameSource] = useState<string | null>(null);
    const [fhPaket, setFhPaket] = useState<string>('100M');
    const [fhPurpose, setFhPurpose] = useState<'psb_new' | 'migration'>('psb_new');
    const [fhVlan, setFhVlan] = useState<number | null>(null);
    const [tr069Vlan, setTr069Vlan] = useState<number>(69);
    /** FiberHome C300 sites: RA and Krian · TR-069 VLAN 69 (68 Migrasi retired). */
    type FhMikrotikOpt = { key: string; router_id: number | null; label: string; tr069_vlan: number; hint: string; site_key?: string; olt_id?: number | null };
    const FH_MIKROTIK_DEFAULTS: FhMikrotikOpt[] = [
        { key: 'randuagung', router_id: null, label: 'MikroTik RA / Randuagung', tr069_vlan: 69, hint: 'PPPoE MikroTik RA · TR-069 VLAN 69 · path OLT C300' },
    ];
    const [fhMikrotikOptions, setFhMikrotikOptions] = useState<FhMikrotikOpt[]>(FH_MIKROTIK_DEFAULTS);
    // Keep RA as the backward-compatible initial value; selecting an OLT below
    // auto-aligns this to the registry site when the API returns a match.
    const [fhMikrotik, setFhMikrotik] = useState<string>('randuagung');
    const [fhDetecting, setFhDetecting] = useState(false);
    const [fhDryRun, setFhDryRun] = useState<boolean>(true);
    // Uncfg list state
    const [fhUncfg, setFhUncfg] = useState<Array<{port: string, onu_id: number, sn: string}>>([]);
    const [fhUncfgPopover, setFhUncfgPopover] = useState(false);
    const [fhUncfgLoading, setFhUncfgLoading] = useState(false);
    // Auto-suggest next ONU ID after port selected
    const [fhNextOnuId, setFhNextOnuId] = useState<number | null>(null);
    const [fhLoading, setFhLoading] = useState(false);
    const [fhLog, setFhLog] = useState<LogEntry[]>([]);
    const [fhResult, setFhResult] = useState<any>(null);
    const [fhProvisionConfirmOpen, setFhProvisionConfirmOpen] = useState(false);
    const [zteProvisionConfirmOpen, setZteProvisionConfirmOpen] = useState(false);

    // ===== Huawei Gadang (MA5608T) provision state =====
    const [hwOltId, setHwOltId] = useState<string>('');
    const [hwSn, setHwSn] = useState<string>('');
    const [hwName, setHwName] = useState<string>('');
    const [hwPaket, setHwPaket] = useState<string>('100M');
    const [hwPlacement, setHwPlacement] = useState<any>(null);
    const [hwDetecting, setHwDetecting] = useState(false);
    const [hwLoading, setHwLoading] = useState(false);
    const [hwResult, setHwResult] = useState<any>(null);
    const [hwConfirmOpen, setHwConfirmOpen] = useState(false);
    // "Lihat ONT baru": list of ONTs in autofind (read-only), pick one -> detect right away
    interface HwAutofindEntry { sn: string; sn_hex?: string | null; pon_port: string; vendor_id?: string | null; model?: string | null; software?: string | null; seen_at?: string | null; }
    const [hwAutofind, setHwAutofind] = useState<HwAutofindEntry[]>([]);
    const [hwAutofindOpen, setHwAutofindOpen] = useState(false);
    const [hwAutofindLoading, setHwAutofindLoading] = useState(false);
    const [hwAutofindError, setHwAutofindError] = useState<string | null>(null);
    const [hwAutofindMeta, setHwAutofindMeta] = useState<{ age_seconds: number; cached: boolean; partial: boolean } | null>(null);
    const [hwPonHint, setHwPonHint] = useState<string | null>(null);

    // ===== Auto PPPoE state (Hioso/HTB activation) =====
    interface AutoppOption { id: number; name: string; price?: number; mikrotik_profile?: string; }
    const [autoppName, setAutoppName] = useState('');
    const [autoppVendor, setAutoppVendor] = useState<string>('');
    const [autoppRt, setAutoppRt] = useState('');
    const [autoppRw, setAutoppRw] = useState('');
    const [autoppOdpId, setAutoppOdpId] = useState('');
    const [autoppOdpManual, setAutoppOdpManual] = useState('');
    const [autoppOdpMode, setAutoppOdpMode] = useState<'list' | 'manual'>('list');
    const [autoppAreaId, setAutoppAreaId] = useState('');
    const [autoppRouterId, setAutoppRouterId] = useState('');
    const [autoppPackageId, setAutoppPackageId] = useState('');
    const [autoppPhone, setAutoppPhone] = useState('');
    const [autoppNik, setAutoppNik] = useState('');
    const [autoppAddress, setAutoppAddress] = useState('');
    const [autoppLocalAddr, setAutoppLocalAddr] = useState('');
    const [autoppRemoteAddr, setAutoppRemoteAddr] = useState('');
    type RecentSecret = { id: number; name?: string; pppoe_user?: string; local_address?: string | null; remote_address?: string | null; profile?: string | null; source?: string };
    const [autoppIpSuggest, setAutoppIpSuggest] = useState<{
        last_remote_address: string | null;
        suggested_next: string | null;
        suggested_available?: boolean;
        local_address: string | null;
        recent_secrets?: RecentSecret[];
        source?: string;
        note?: string;
        used_remote_count?: number;
    } | null>(null);
    const [autoppIpLoading, setAutoppIpLoading] = useState(false);
    const [autoppKtpFile, setAutoppKtpFile] = useState<File | null>(null);
    const [autoppOdpFile, setAutoppOdpFile] = useState<File | null>(null);
    const [autoppKtpPreview, setAutoppKtpPreview] = useState<string | null>(null);
    const [autoppOdpPreview, setAutoppOdpPreview] = useState<string | null>(null);
    const [autoppOcrLoading, setAutoppOcrLoading] = useState(false);
    const [autoppOcrNote, setAutoppOcrNote] = useState<string | null>(null);
    const [autoppOcrConfidence, setAutoppOcrConfidence] = useState<number | null>(null);
    const [autoppLoading, setAutoppLoading] = useState(false);
    const [autoppDryRun, setAutoppDryRun] = useState(true);
    const [autoppResult, setAutoppResult] = useState<any>(null);
    const [autoppError, setAutoppError] = useState<string | null>(null);
    const [autoppAreas, setAutoppAreas] = useState<{id: number; name: string}[]>(() =>
        (initialAreas || []).map((a) => ({ id: Number(a.id), name: String(a.name || '') })).filter((a) => a.id && a.name)
    );
    const [autoppOdps, setAutoppOdps] = useState<{id: number; name: string; type: string; olt_port: string | null}[]>([]);
    const [autoppRouters, setAutoppRouters] = useState<{id: number; name: string}[]>(() =>
        (initialRouters || []).map((r) => ({ id: Number(r.id), name: String(r.name || '') })).filter((r) => r.id && r.name)
    );
    const [autoppPackages, setAutoppPackages] = useState<AutoppOption[]>([]);
    const autoppFormDirty = activeTab === 'autopp' && (
        [
            autoppName,
            autoppVendor,
            autoppRt,
            autoppRw,
            autoppOdpId,
            autoppOdpManual,
            autoppAreaId,
            autoppRouterId,
            autoppPackageId,
            autoppPhone,
            autoppNik,
            autoppAddress,
            autoppLocalAddr,
            autoppRemoteAddr,
        ].some(Boolean) || autoppOdpMode === 'manual' || !autoppDryRun || autoppKtpFile !== null || autoppOdpFile !== null
    );
    useUnsavedChangesGuard(autoppFormDirty && !autoppLoading && !autoppResult?.customer, 'Data PSB belum disimpan. Tinggalkan halaman?');
    const [vlanAreaFilter, setVlanAreaFilter] = useState('all');
    const [vlanOltId, setVlanOltId] = useState('');
    const flowOltForVlan = activeTab === 'fiberhome' ? fhOltId : activeTab === 'huawei' ? hwOltId : activeTab === 'reconfigure' ? reconfigOltId : oltId;
    const vlanOlts = useMemo(() => vlanAreaFilter === 'all'
        ? olts
        : olts.filter((olt) => String(olt.area_id ?? '') === vlanAreaFilter), [olts, vlanAreaFilter]);
    const activeOltForVlan = vlanOltId || (vlanAreaFilter !== 'all' ? String(vlanOlts[0]?.id ?? '') : flowOltForVlan);
    const activeVlanOlt = olts.find((olt) => String(olt.id) === String(activeOltForVlan));
    const [vlanPorts, setVlanPorts] = useState<VlanPort[]>([]);
    const [vlanMappings, setVlanMappings] = useState<VlanMapping[]>([]);
    // S5: hapus VLAN mapping wajib lewat konfirmasi.
    const [vlanDeleteTarget, setVlanDeleteTarget] = useState<VlanMapping | null>(null);
    const [vlanDeleting, setVlanDeleting] = useState(false);
    const [vlanPort, setVlanPort] = useState('');
    const [vlanValue, setVlanValue] = useState('');
    const [vlanDescription, setVlanDescription] = useState('');
    const [vlanSaving, setVlanSaving] = useState(false);
    const portOptions = vlanPorts.length > 0 ? vlanPorts : RECONFIG_PORTS.map((port) => ({
        value: port.value,
        label: port.label,
        vlan: parseInt(port.label.match(/\(VLAN (\d+)\)/)?.[1] || '0'),
    }));

    const reloadVlanMappings = async (targetOltId = activeOltForVlan) => {
        if (!targetOltId) {
            setVlanPorts([]);
            setVlanMappings([]);
            return;
        }

        try {
            const [portsRes, mappingsRes] = await Promise.all([
                fetch(`/api/olt-c300/vlan-map?olt_id=${targetOltId}`, { credentials: 'include' }),
                fetch(`/api/olts/${targetOltId}/vlan-mappings`, { credentials: 'include' }),
            ]);
            if (!portsRes.ok) {
                toast.error(`Gagal load VLAN map OLT (${portsRes.status})`);
            }
            if (!mappingsRes.ok) {
                toast.error(`Gagal load VLAN mappings DB (${mappingsRes.status}) — cek pilih OLT RA/Krian`);
            }
            const ports = portsRes.ok ? await portsRes.json() : {};
            const mappings = mappingsRes.ok ? await mappingsRes.json() : {};
            const portList = Array.isArray(ports.ports) ? ports.ports : [];
            setVlanPorts(portList);
            // Hanya tampilkan mapping DB — tidak ada fallback hardcode
            const dbList = Array.isArray(mappings.data) ? mappings.data : [];
            setVlanMappings(dbList);
            const mikrotikOptions = Array.isArray(ports.mikrotik_options) ? ports.mikrotik_options : [];
            if (mikrotikOptions.length > 0) {
                setFhMikrotikOptions(mikrotikOptions);
                // The selected C300 OLT is the source of truth for the
                // MikroTik path. This prevents a Krian OLT from silently
                // provisioning against the RA router (and vice versa).
                const matchedSite = mikrotikOptions.find((o: FhMikrotikOpt) => Number(o.olt_id || 0) === Number(targetOltId));
                if (matchedSite) {
                    setFhMikrotik(matchedSite.key);
                    setTr069Vlan(Number(matchedSite.tr069_vlan) || 69);
                }
            }
            // TR-069 follows selected MikroTik when set; otherwise API default
            if (fhMikrotik && !mikrotikOptions.some((o: FhMikrotikOpt) => Number(o.olt_id || 0) === Number(targetOltId))) {
                const opt = (mikrotikOptions.length > 0 ? mikrotikOptions : FH_MIKROTIK_DEFAULTS).find((o: FhMikrotikOpt) => o.key === fhMikrotik);
                setTr069Vlan(opt?.tr069_vlan ?? 69);
            } else if (mikrotikOptions.length === 0 && typeof ports.tr069_vlan === 'number' && ports.tr069_vlan > 0) {
                setTr069Vlan(ports.tr069_vlan);
            } else if (mikrotikOptions.length === 0) {
                setTr069Vlan(69);
            }
        } catch (e: any) {
            toast.error(e?.message || 'Gagal memuat VLAN mapping');
            setVlanPorts([]);
            setVlanMappings([]);
        }
    };

    // VLAN mapping only when an OLT is selected (avoid empty API storms on first paint)
    useEffect(() => {
        if (vlanOltId && vlanAreaFilter !== 'all' && !vlanOlts.some((olt) => String(olt.id) === vlanOltId)) {
            setVlanOltId('');
        }
    }, [vlanAreaFilter, vlanOltId, vlanOlts]);

    useEffect(() => {
        if (!activeOltForVlan) {
            setVlanPorts([]);
            setVlanMappings([]);
            return;
        }
        reloadVlanMappings().catch(() => {});
    }, [activeOltForVlan]);

    const saveVlanMapping = async () => {
        if (!activeOltForVlan || !vlanPort || !vlanValue) {
            toast.error('Pilih OLT, isi port PON, dan VLAN dulu');
            return;
        }

        setVlanSaving(true);
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
        try {
            const res = await fetch(`/api/olts/${activeOltForVlan}/vlan-mappings`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                credentials: 'include',
                body: JSON.stringify({ pon_port: vlanPort, service_type: 'internet', vlan: parseInt(vlanValue), description: vlanDescription || null, is_active: true }),
            });
            const data = await res.json();
            if (!res.ok) throw new Error(data.message || 'Gagal menyimpan VLAN mapping');
            setVlanPort('');
            setVlanValue('');
            setVlanDescription('');
            await reloadVlanMappings();
            toast.success('VLAN mapping tersimpan');
        } catch (error: any) {
            toast.error(error.message || 'Gagal menyimpan VLAN mapping');
        } finally {
            setVlanSaving(false);
        }
    };

    const deleteVlanMapping = async (mappingId: number) => {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
        setVlanDeleting(true);
        try {
            const res = await fetch(`/api/olt-vlan-mappings/${mappingId}`, {
                method: 'DELETE',
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                credentials: 'include',
            });
            if (!res.ok) {
                let message = `Gagal menghapus VLAN mapping (HTTP ${res.status})`;
                try {
                    const data = await res.json();
                    message = data.message || message;
                } catch {
                    // Keep the status-based message when the server response is not JSON.
                }
                throw new Error(message);
            }
            await reloadVlanMappings();
            toast.success('VLAN mapping dihapus');
            setVlanDeleteTarget(null);
        } catch (error: any) {
            toast.error(error.message || 'Gagal menghapus VLAN mapping');
        } finally {
            setVlanDeleting(false);
        }
    };

    // Areas: only re-fetch when vendor filter changes (SSR already seeded full catalog).
    useEffect(() => {
        if (!autoppVendor) {
            // Restore SSR list when vendor cleared
            if (initialAreas?.length) {
                setAutoppAreas(
                    initialAreas.map((a) => ({ id: Number(a.id), name: String(a.name || '') })).filter((a) => a.id && a.name)
                );
            }
            return;
        }
        const url = `/api/areas?vendor=${encodeURIComponent(autoppVendor)}`;
        fetch(url, { credentials: 'include', headers: { Accept: 'application/json' } })
            .then(async (r) => {
                if (!r.ok) throw new Error(`HTTP ${r.status}`);
                const d = await r.json();
                const list = Array.isArray(d) ? d : (Array.isArray(d?.data) ? d.data : []);
                setAutoppAreas(list.map((a: any) => ({ id: Number(a.id), name: String(a.name || '') })).filter((a: any) => a.id && a.name));
            })
            .catch((e) => {
                console.error('load areas failed', e);
            });
    }, [autoppVendor]);

    // Routers: SSR seed; optional background refresh only if empty (retired path / old deploy).
    useEffect(() => {
        if (autoppRouters.length > 0) return;
        fetch('/api/routers', { credentials: 'include', headers: { Accept: 'application/json' } })
            .then(async (r) => {
                if (!r.ok) throw new Error(`HTTP ${r.status}`);
                const d = await r.json();
                let list: { id: number; name: string }[] = [];
                if (Array.isArray(d)) list = d;
                else if (Array.isArray(d?.data)) list = d.data;
                else if (Array.isArray(d?.routers)) list = d.routers;
                else if (Array.isArray(d?.data?.data)) list = d.data.data;
                setAutoppRouters(list.map((x: any) => ({ id: Number(x.id), name: String(x.name || '') })).filter((x) => x.id && x.name));
            })
            .catch((e) => {
                console.error('load routers failed', e);
            });
    }, []);

    useEffect(() => {
        if (!autoppAreaId) { setAutoppOdps([]); return; }
        fetch(`/api/odp-odc?area_id=${autoppAreaId}`, { credentials: 'include' })
            .then(r => r.json())
            .then(d => {
                const list = Array.isArray(d) ? d : (d.data || []);
                const odps = list.filter((o: any) => (o.type || 'ODP').toUpperCase() === 'ODP');
                setAutoppOdps(odps);
                // Auto-switch to manual input when the area has no registered ODP
                setAutoppOdpMode(odps.length === 0 ? 'manual' : 'list');
                setAutoppOdpId('');
            }).catch(() => { setAutoppOdps([]); setAutoppOdpMode('manual'); });
    }, [autoppAreaId]);

    const [autoppPkgLoading, setAutoppPkgLoading] = useState(false);

    const loadAutoppPackages = (routerId: string) => {
        if (!routerId) {
            setAutoppPackages([]);
            return;
        }
        setAutoppPkgLoading(true);
        fetch(`/api/routers/${routerId}/packages`, { credentials: 'include' })
            .then(r => r.json())
            .then(d => {
                const list = Array.isArray(d) ? d : (d.data || []);
                setAutoppPackages(list.map((p: any) => ({ id: p.id, name: p.name, price: p.price, mikrotik_profile: p.mikrotik_profile })));
            })
            .catch(() => setAutoppPackages([]))
            .finally(() => setAutoppPkgLoading(false));
    };

    // Packages for selected router only (eBilling packages used on that router)
    useEffect(() => {
        if (!autoppRouterId) {
            setAutoppPackages([]);
            setAutoppPackageId('');
            return;
        }
        setAutoppPackageId('');
        loadAutoppPackages(autoppRouterId);
    }, [autoppRouterId]);

    const syncPackagesFromScraper = async () => {
        setAutoppPkgLoading(true);
        try {
            // axios already wires CSRF/XSRF via bootstrap.ts — more reliable than raw fetch
            const csrf = readMetaCsrfToken() || readXsrfCookieToken() || (window as any).csrfToken || '';
            const { data } = await axios.post(
                '/api/packages/sync-scraper',
                {},
                {
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': csrf,
                        'X-XSRF-TOKEN': readXsrfCookieToken() || csrf,
                    },
                    timeout: 120000,
                }
            );
            if (data?.success === false) {
                toast.error(data.error || 'Gagal sync paket scraper');
            } else {
                toast.success(data?.message || 'Paket scraper di-update');
                if (autoppRouterId) loadAutoppPackages(autoppRouterId);
            }
        } catch (e: any) {
            const status = e?.response?.status;
            const msg = e?.response?.data?.error || e?.response?.data?.message || e?.message || 'unknown';
            if (status === 419) {
                toast.error('Sesi/CSRF kedaluwarsa — refresh halaman (Ctrl+Shift+R) lalu coba lagi');
            } else {
                toast.error(`Gagal sync paket${status ? ` (${status})` : ''}: ${msg}`);
            }
        } finally {
            setAutoppPkgLoading(false);
        }
    };

    // Router → MikroTik secrets sorted by remote DESC + next FREE IP (e.g. .97 → .98 if free)
    const loadAutoppIpSuggest = (routerId: string, profile?: string, force = false) => {
        if (!routerId) {
            setAutoppIpSuggest(null);
            return;
        }
        setAutoppIpLoading(true);
        const params = new URLSearchParams();
        if (profile) params.set('profile', profile);
        if (force) params.set('fresh', '1');
        const q = params.toString() ? `?${params}` : '';
        fetch(`/api/routers/${routerId}/last-pppoe-ip${q}`, { credentials: 'include' })
            .then(r => r.json())
            .then(d => {
                setAutoppIpSuggest(d);
                // Auto-fill only when free; never stomp manual edits
                if (d?.local_address) setAutoppLocalAddr((prev) => prev || d.local_address);
                if (d?.suggested_next && d?.suggested_available !== false) {
                    setAutoppRemoteAddr((prev) => prev || d.suggested_next);
                }
            })
            .catch(() => setAutoppIpSuggest(null))
            .finally(() => setAutoppIpLoading(false));
    };

    useEffect(() => {
        if (!autoppRouterId) {
            setAutoppIpSuggest(null);
            setAutoppLocalAddr('');
            setAutoppRemoteAddr('');
            return;
        }
        const pkg = autoppPackages.find(p => String(p.id) === String(autoppPackageId));
        loadAutoppIpSuggest(autoppRouterId, pkg?.mikrotik_profile);
    }, [autoppRouterId, autoppPackageId, autoppPackages]);

    const setPhotoPreview = (file: File | null, kind: 'ktp' | 'odp') => {
        const setter = kind === 'ktp' ? setAutoppKtpPreview : setAutoppOdpPreview;
        if (!file) {
            setter(null);
            return;
        }
        const url = URL.createObjectURL(file);
        setter(url);
    };

    // OCR KTP → NIK, Nama, Alamat, RT/RW (multi-engine backend)
    const runKtpOcr = async (file: File) => {
        setAutoppOcrLoading(true);
        setAutoppOcrNote(null);
        setAutoppOcrConfidence(null);
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
        try {
            const fd = new FormData();
            fd.append('image', file);
            const res = await fetch('/api/ktp-ocr', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                body: fd,
            });
            const data = await res.json();
            const filled: string[] = [];
            if (data.nik) {
                setAutoppNik(data.nik);
                filled.push('NIK');
            }
            if (data.name) {
                setAutoppName(String(data.name).toUpperCase());
                filled.push('Nama');
            }
            if (data.address) {
                setAutoppAddress(data.address);
                filled.push('Alamat');
            }
            if (data.rt) {
                setAutoppRt(String(data.rt).replace(/\D/g, ''));
                filled.push('RT');
            }
            if (data.rw) {
                setAutoppRw(String(data.rw).replace(/\D/g, ''));
                filled.push('RW');
            }
            if (typeof data.confidence === 'number') {
                setAutoppOcrConfidence(data.confidence);
            }
            if (data.nik || filled.length > 0) {
                const conf = typeof data.confidence === 'number' ? ` · keyakinan ${Math.round(data.confidence * 100)}%` : '';
                setAutoppOcrNote(`OCR: ${filled.join(', ') || 'NIK'}${data.nik ? ` (${data.nik})` : ''}${conf}`);
            } else {
                setAutoppOcrNote(data.note || data.error || 'NIK tidak terbaca. Foto ulang (tajam, cahaya cukup) atau isi manual.');
            }
        } catch (err: any) {
            setAutoppOcrNote('OCR gagal: ' + err.message + '. Isi NIK manual.');
        } finally {
            setAutoppOcrLoading(false);
        }
    };


    const autoppPreviewUser = (() => {
        const cleanName = autoppName.toUpperCase().replace(/[^A-Z0-9]/g, '');
        if (!cleanName) return '';
        const rt = (autoppRt || '').replace(/\D/g, '');
        const rw = (autoppRw || '').replace(/\D/g, '');
        let odpShort = '';
        if (autoppOdpMode === 'manual') {
            const raw = autoppOdpManual.trim();
            const m = raw.match(/(\d+\.\d+)$/);
            if (m) odpShort = m[1];
            else { const m2 = raw.match(/(\d+)\s*$/); odpShort = m2 ? m2[1] : raw.toUpperCase().replace(/[^A-Z0-9]/g, ''); }
        } else {
            const odp = autoppOdps.find(o => String(o.id) === String(autoppOdpId));
            odpShort = odp?.name || '';
            if (odp) {
                const m = odp.name.match(/(\d+\.\d+)$/);
                if (m) odpShort = m[1];
                else { const m2 = odp.name.match(/_(\d+)$/); if (m2) odpShort = m2[1]; }
            }
        }
        if (!rt || !rw) return cleanName + (odpShort ? `_${odpShort}` : '');
        return `${cleanName}_RT${rt}_RW${rw}_${odpShort || '?'}`;
    })();

    const submitAutopp = async (e: React.FormEvent) => {
        e.preventDefault();
        setAutoppError(null);
        setAutoppResult(null);
        const odpOk = autoppOdpMode === 'manual' ? !!autoppOdpManual.trim() : !!autoppOdpId;
        if (!autoppName || !autoppRt || !autoppRw || !odpOk || !autoppAreaId || !autoppRouterId || !autoppPackageId) {
            setAutoppError('Lengkapi semua field wajib: Nama, RT, RW, ODP, Area, Router, Paket.');
            return;
        }
        setAutoppLoading(true);
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
        try {
            const fd = new FormData();
            fd.append('name', autoppName);
            if (autoppVendor) fd.append('vendor', autoppVendor);
            fd.append('rt', autoppRt);
            fd.append('rw', autoppRw);
            if (autoppOdpMode === 'manual') fd.append('odp_manual', autoppOdpManual.trim());
            else fd.append('odp_id', autoppOdpId);
            fd.append('area_id', autoppAreaId);
            fd.append('router_id', autoppRouterId);
            fd.append('package_id', autoppPackageId);
            if (autoppPhone) fd.append('phone', autoppPhone);
            if (autoppNik) fd.append('nik', autoppNik);
            if (autoppAddress) fd.append('address', autoppAddress);
            if (autoppLocalAddr) fd.append('local_address', autoppLocalAddr);
            if (autoppRemoteAddr) fd.append('remote_address', autoppRemoteAddr);
            if (autoppKtpFile) fd.append('ktp_photo', autoppKtpFile);
            if (autoppOdpFile) fd.append('odp_photo', autoppOdpFile);
            fd.append('dry_run', autoppDryRun ? '1' : '0');
            const res = await fetch('/api/olt-c300/auto-pppoe', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                body: fd,
            });
            const data = await res.json();
            if (!res.ok || data.success === false) {
                setAutoppError(data.error || `HTTP ${res.status}`);
            } else {
                setAutoppResult(data);
                if (!autoppDryRun && data.customer) {
                    addFhLog('success', `Aktivasi auto: ${data.customer.name} ke ${data.pppoe_user}`);
                }
            }
        } catch (err: any) {
            setAutoppError(err.message);
        } finally {
            setAutoppLoading(false);
        }
    };

    // Prefill the ZTE provisioning tab from Auto PPPoE output and switch to it.
    const goToZteProvision = () => {
        if (autoppResult?.pppoe_user) setName(autoppResult.pppoe_user);
        else if (autoppName) setName(autoppName);
        setActiveTab('activate');
    };

    const goToFiberhomeProvision = () => {
        if (autoppResult?.pppoe_user) setFhName(autoppResult.pppoe_user);
        else if (autoppName) setFhName(autoppName);
        setActiveTab('fiberhome');
    };

    const addFhLog = (type: LogEntry['type'], msg: string) => setFhLog(l => [...l, { time: formatWibTime(new Date(), true), type, msg }]);

    const fhNameSourceLabel = (source?: string | null) => {
        const labels: Record<string, string> = {
            ebilling_pppoe: 'eBilling · PPPoE',
            ebilling_name: 'eBilling · nama pelanggan',
            genieacs_pppoe: 'GenieACS · PPPoE',
            genieacs_tag: 'GenieACS · tag',
            genieacs_pelanggan: 'GenieACS · pelanggan',
        };
        return source ? labels[source] || source : null;
    };

    const applyFhOnuNameSuggestion = (
        payload: {
            suggested_name?: string;
            name_source?: string;
            customer?: { pppoe_user?: string; name?: string; code?: string };
            genieacs?: { tags?: string[]; matched_tag?: string };
        } | null | undefined,
        sn: string,
        force = false,
    ) => {
        const suggested = payload?.suggested_name || payload?.customer?.pppoe_user || payload?.customer?.name;
        if (suggested && (force || !fhName)) {
            setFhName(suggested);
            setFhNameSource(payload?.name_source || null);
            const source = fhNameSourceLabel(payload?.name_source);
            const customerLabel = payload?.customer?.name ? `${payload.customer.name} · ` : '';
            const tagHint = payload?.genieacs?.tags?.length
                ? ` (tags: ${payload.genieacs.tags.slice(0, 2).join(', ')})`
                : '';
            addFhLog('info', `Nama ONU${source ? ` [${source}]` : ''}: ${customerLabel}${suggested}${tagHint}`);
            return true;
        }
        if (!fhName && sn) {
            setFhName(sn);
            setFhNameSource(null);
        }
        return false;
    };

    const lookupFhCustomerBySn = async (sn: string, force = false) => {
        const normalized = sn.trim().toUpperCase();
        if (normalized.length < 8) return;
        try {
            const res = await fetch(`/api/olt-c300/customer-by-sn?sn=${encodeURIComponent(normalized)}`, {
                credentials: 'include',
                headers: { Accept: 'application/json' },
            });
            const data = await res.json();
            if (data.found) {
                applyFhOnuNameSuggestion(data, normalized, force);
            }
        } catch {
            // non-blocking lookup
        }
    };

    const addLog = (type: LogEntry['type'], msg: string) => setLog(l => [...l, { time: formatWibTime(new Date(), true), type, msg }]);
    const addReconfigLog = (type: LogEntry['type'], msg: string) => setReconfigLog(l => [...l, { time: formatWibTime(new Date(), true), type, msg }]);

    const processBackendLog = (backendLog: any[], addFn: (type: LogEntry['type'], msg: string) => void) => {
        if (!backendLog || !Array.isArray(backendLog)) return;
        backendLog.forEach((l: any) => {
            if (l.cmd?.startsWith('_')) {
                addFn('info', `  [${l.cmd.replace('_', '')}] ${redactPppoeText(String(l.output || l.note || ''))}`);
            } else if (l.cmd) {
                addFn('cmd', `  ${redactPppoeText(String(l.cmd))}`);
                const out = redactPppoeText(String(l.output || l.note || ''));
                if (out) addFn('output', `    → ${out}`);
            }
        });
    };


    const getCsrf = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

    const handleUpload = async (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (!file) return;
        setPreview(URL.createObjectURL(file));
        setLoading(true);
        addLog('info', 'Mengupload foto untuk membaca SN...');
        const form = new FormData();
        form.append('image', file);
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
        try {
            const res = await fetch('/api/olt-c300/ocr', { method: 'POST', body: form, credentials: 'include', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken } });
            const data = await res.json();
            if (data.sn) { setSn(data.sn); setName(data.sn); addLog('success', `SN terdeteksi: ${data.sn}`); toast.success('SN terdeteksi dari foto!'); }
            else { addLog('error', 'OCR tidak bisa baca SN — ketik manual'); toast.error('Gagal baca SN. Ketik manual.'); }
        } catch (err: any) { addLog('error', `OCR error: ${err.message}`); toast.error('OCR error'); }
        setLoading(false);
    };

    const handleDetect = async () => {
        if (!oltId || !sn) { toast.error('Pilih OLT dan masukkan SN dulu'); return; }
        setLoading(true); setStep('upload'); setLog([]);
        addLog('phase', 'FASE DETEKSI — Mencari ONU di OLT');
        addLog('cmd', 'show gpon onu uncfg');
        addLog('info', `Menghubungi OLT untuk mencari SN ${sn}...`);
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
        try {
            const res = await fetch('/api/olt-c300/detect', { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken }, credentials: 'include', body: JSON.stringify({ olt_id: parseInt(oltId), sn }) });
            const data = await res.json();
            setResult(data);
            if (data.found) {
                if (data.multiple_ports && data.ports) {
                    addLog('info', `SN terdeteksi di ${data.ports.length} PON port:`);
                    data.ports.forEach((p: any) => addLog('info', `   Port ${p.port} → VLAN ${p.vlan} (ONU ID: ${p.onu_id})${p.already_registered ? ' [sudah terdaftar]' : ''}`));
                    setPortChoices(data.ports); setSelectedPort(data.ports[0].port); setResult(data.ports[0]);
                    toast.warning('SN terdeteksi di beberapa port — pilih port yang benar');
                } else {
                    addLog('success', 'ONU DITEMUKAN');
                    addLog('info', `   Port PON: ${data.port}  |  ONU ID: ${data.onu_id}  |  VLAN: ${data.vlan}`);
                    addLog('cmd', `show running-config interface gpon-olt_${data.port}`);
                    toast.success('ONU ditemukan!'); setSelectedPort(data.port); setPortChoices([]);
                }
                if (!name) setName(sn);
                setStep('confirm'); setLoading(false);
            } else {
                // Check if this was an SSH connection error (not just SN not found)
                if (data.error_type === 'ssh_auth_failed' || data.error_type === 'ssh_network_error' || data.error_type === 'ssh_compat') {
                    handleSshError(data);
                    addLog('error', `${data.error}`);
                    if (data.user_hint) addLog('info', `${data.user_hint}`);
                } else {
                    addLog('error', `${data.message || 'SN tidak ditemukan di OLT'}`);
                    if (data.uncfg_onus && data.uncfg_onus.length > 0) {
                        addLog('info', 'ONU uncfg yang terdeteksi:');
                        data.uncfg_onus.forEach((u: any) => {
                            addLog('info', `   ${u.port}:${u.onu_id} — SN: ${u.sn}`);
                        });
                        addLog('info', 'Ketik salah satu SN di atas lalu coba lagi');
                    } else {
                        addLog('info', 'Pastikan: 1) ONU sudah nyala  2) Kabel fiber terhubung  3) SN benar');
                    }
                }
                toast.error('ONU tidak terdeteksi'); setLoading(false); setStep('upload');
            }
        } catch (err: any) { addLog('error', `Deteksi gagal: ${err.message}`); toast.error('Deteksi gagal'); setLoading(false); setStep('upload'); }
    };

    // Helper: handle SSH-related errors from API responses
    const handleSshError = (data: any) => {
        if (data.error_type === 'ssh_auth_failed') {
            setConnectionStatus({ ssh_login: false, error_type: 'auth_failed', hint: data.user_hint || 'SSH login gagal — kredensial salah.' });
            toast.error('SSH login gagal — kredensial salah');
        } else if (data.error_type === 'ssh_network_error') {
            setConnectionStatus({ ssh_login: false, error_type: 'network_error', hint: data.user_hint || 'OLT tidak bisa dijangkau.' });
            toast.error('OLT tidak bisa dijangkau');
        }
    };

    const handleTestConnection = async () => {
        if (!oltId) { toast.error('Pilih OLT dulu'); return; }
        setTestingConnection(true); setConnectionStatus(null);
        addLog('phase', 'TES KONEKSI SSH KE OLT');
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
        try {
            const res = await fetch('/api/olt-c300/test-connection', { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken }, credentials: 'include', body: JSON.stringify({ olt_id: parseInt(oltId) }) });
            const data = await res.json();
            setConnectionStatus(data);

            addLog('info', `OLT: ${data.olt} (${data.host}:${data.port})`);
            addLog('info', `TCP Reachable: ${data.tcp_reachable ? 'Ya' : 'Tidak'}`);
            if (!data.tcp_reachable) {
                addLog('error', 'OLT tidak bisa dijangkau dari server. Cek jaringan.');
                toast.error('OLT tidak bisa dijangkau');
            } else {
                addLog('info', `SSH Login: ${data.ssh_login ? 'Berhasil' : 'Gagal'}`);
                if (!data.ssh_login) {
                    addLog('error', `SSH login gagal: ${data.ssh_error || data.errors?.join(', ') || 'Unknown error'}`);
                    if (data.hint) addLog('info', `${data.hint}`);
                    if (data.error_type === 'auth_failed') {
                        addLog('info', 'Klik "Update Kredensial OLT" untuk mengganti username/password.');
                        toast.error('SSH login gagal — kredensial salah');
                    } else {
                        toast.error('Koneksi SSH ke OLT gagal');
                    }
                } else {
                    addLog('info', `ONU uncfg: ${data.uncfg_count} ditemukan`);
                    toast.success(`Koneksi OK! ${data.uncfg_count} ONU uncfg`);
                }
            }
        } catch (err: any) {
            addLog('error', `Tes gagal: ${err.message}`);
            toast.error('Tes koneksi gagal');
        }
        setTestingConnection(false);
    };

    const handleListUncfg = async () => {
        if (!oltId) { toast.error('Pilih OLT dulu'); return; }
        setLoading(true); setLog([]);
        addLog('phase', 'DAFTAR ONU UNCFG');
        addLog('cmd', 'show gpon onu uncfg');
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
        try {
            const res = await fetch('/api/olt-c300/list-uncfg', { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken }, credentials: 'include', body: JSON.stringify({ olt_id: parseInt(oltId) }) });
            const data = await res.json();
            if (data.error_type === 'ssh_auth_failed') {
                addLog('error', `SSH login gagal: ${data.error}`);
                addLog('info', `${data.user_hint}`);
                setConnectionStatus({ ssh_login: false, error_type: 'auth_failed', hint: data.user_hint });
                toast.error('SSH login gagal — kredensial salah');
            } else if (data.onus && data.onus.length > 0) {
                addLog('success', `${data.count} ONU uncfg ditemukan di ${data.olt}:`);
                data.onus.forEach((u: any) => {
                    addLog('info', `   ${u.port}:${u.onu_id} — SN: ${u.sn}`);
                });
            } else if (data.error) {
                addLog('error', `Gagal: ${data.error}`);
                if (data.user_hint) addLog('info', `${data.user_hint}`);
            } else {
                addLog('info', 'Tidak ada ONU uncfg saat ini');
            }
        } catch (err: any) {
            addLog('error', `Gagal: ${err.message}`);
        }
        setLoading(false);
    };

    const handleUpdateCredentials = async () => {
        if (!oltId) { toast.error('Pilih OLT dulu'); return; }
        setCredSaving(true);
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
        try {
            const res = await fetch('/api/olt-c300/update-credentials', {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                credentials: 'include',
                body: JSON.stringify({ olt_id: parseInt(oltId), username: credUsername || undefined, password: credPassword || undefined }),
            });
            const data = await res.json();
            if (data.success) {
                toast.success(`Kredensial OLT ${data.olt} berhasil diupdate!`);
                setShowCredModal(false);
                setCredUsername('');
                setCredPassword('');
                setConnectionStatus(null);
                addLog('success', `Kredensial OLT diupdate — username: ${data.username}`);
            } else {
                toast.error('Gagal update kredensial');
            }
        } catch (err: any) {
            toast.error(`Error: ${err.message}`);
        }
        setCredSaving(false);
    };

    const handleProvision = async (liveConfirm: string) => {
        if (!result || !result.found) return;
        if (!password.trim()) {
            toast.error('Password PPPoE wajib diisi sebelum provisioning');
            return;
        }
        setStep('provisioning'); setLoading(true);
        const provPort = selectedPort || result.port;
        const provVlan = result.vlan;
        const provPassword = password;
        addLog('phase', 'FASE AKTIVASI — Mengkonfigurasi ONU di OLT');
        addLog('info', 'Mengirim perintah SSH ke OLT...');
        addLog('info', '');
        const phases = getProvisionCommands(provPort, result.onu_id, name, provVlan, sn, '[REDACTED]', tr069Vlan);
        phases.forEach(phase => {
            addLog('phase', `${phase.phase}: ${phase.desc}`);
            phase.cmds.forEach(cmd => addLog('cmd', `  ${cmd}`));
            addLog('info', '');
        });
        addLog('info', 'Menunggu respons dari OLT...');
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
        try {
            const res = await fetch('/api/olt-c300/provision', { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken }, credentials: 'include', body: JSON.stringify({ olt_id: parseInt(oltId), port: provPort, onu_id: result.onu_id, vlan: provVlan, sn, name, password: provPassword, live_confirm: liveConfirm }) });
            const data = await res.json();
            if (data.success) {
                addLog('success', 'ONU BERHASIL DIAKTIFKAN');
                processBackendLog(data.log, addLog);
                logMigrationLink(data, addLog);
                if (data.status) addLog('info', `   Status: ${data.status}`);
                if (data.rx_power) addLog('info', `   Rx Power: ${data.rx_power} dBm`);
                if (data.distance) addLog('info', `   Jarak: ${data.distance}`);
                if (data.elapsed) addLog('info', `   Waktu proses: ${data.elapsed}`);
                setResult({ ...result, ...data });
                toast.success('ONU berhasil diaktifkan!');
                setStep('done'); setLoading(false);
            } else {
                addLog('error', `Gagal: ${data.message || 'Provisioning gagal'}`);
                if (data.log) processBackendLog(data.log, addLog);
                addLog('info', 'OLT akan otomatis rollback dalam 60 detik jika konfigurasi tidak disimpan.');
                toast.error('Provisioning gagal'); setLoading(false); setStep('confirm');
            }
        } catch (err: any) { addLog('error', `Error: ${err.message}`); toast.error('Provisioning error'); setLoading(false); setStep('confirm'); }
    };

    // Manual provision vs open migration: exact = auto-linked, near = warning + tombol Tautkan.
    const logMigrationLink = (data: any, add: (type: LogEntry['type'], msg: string) => void) => {
        if (data?.migration_link?.message) {
            add('success', data.migration_link.message);
            if (data.migration_link.mikrotik_note) add('info', data.migration_link.mikrotik_note);
            if (data.migration_link.old_onu_note) add('info', data.migration_link.old_onu_note);
        } else if (typeof data?.migration_warning === 'string' && data.migration_warning) {
            add('error', `PERINGATAN migrasi: ${data.migration_warning}`);
        }
    };

    const cancelConfirm = () => { setStep('upload'); setResult(null); setPortChoices([]); setSelectedPort(''); };

    const copyLog = () => { navigator.clipboard.writeText(log.map(l => `[${l.time}] ${l.msg}`).join('\n')); toast.success('Log disalin!'); };

    const reset = () => { setStep('upload'); setOltId(''); setSn(''); setName(''); setPassword(''); setLoading(false); setResult(null); setLog([]); setPreview(null); setSelectedPort(''); setPortChoices([]); };

    // ===== RECONFIGURE FLOW =====

    // ===== FiberHome LIST UNCFG (show ONU yang belum di-config, untuk pilih SN) =====
    const handleFiberhomeListUncfg = async () => {
        if (!fhOltId) { toast.error('Pilih OLT dulu'); return; }
        setFhUncfgLoading(true);
        setFhUncfgPopover(true);
        addFhLog('phase', 'List ONU uncfg dari OLT (untuk pilih SN)...');
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
        try {
            const res = await fetch('/api/olt-c300/list-uncfg', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                credentials: 'include',
                body: JSON.stringify({ olt_id: parseInt(fhOltId) }),
            });
            const data = await res.json();
            if (data.onus && Array.isArray(data.onus)) {
                setFhUncfg(data.onus);
                setFhResult({ port_stats: data.port_stats || [] });
                addFhLog('success', `${data.count} ONU uncfg ditemukan di ${data.olt}`);
                if (data.count === 0) {
                    addFhLog('info', 'Tidak ada ONU yang belum dikonfigurasi. Semua ONU sudah aktif.');
                }
                if (data.port_stats && data.port_stats.length > 0) {
                    addFhLog('info', 'Snapshot per-port (running-config):');
                    data.port_stats.forEach((p: any) => {
                        addFhLog('info', `   Port ${p.port}: ${p.configured_count} ONU, last=${p.last_onu_id}, next=${p.next_onu_id}`);
                    });
                }
            } else {
                addFhLog('error', `${data.error || 'Gagal list uncfg'}`);
                setFhUncfg([]);
            }
        } catch (err: any) {
            addFhLog('error', `Network error: ${err.message}`);
        }
        setFhUncfgLoading(false);
    };

    // ===== FiberHome CHECK PORT (setelah pilih SN, ambil last_onu_id + next_onu_id dari OLT) =====
    // Strategy: GAP-FILL — pakai ONU ID kosong terkecil (1..128) dari `show running-config
    // interface gpon-olt_<port>`. Backend yang menghitung & mengembalikan `next_onu_id`
    // (smallest unused). Kalau tidak ada gap (ID 1..N berurutan), otomatis jadi max+1.
    // `next_onu_id` = null artinya port penuh (semua 1..128 terpakai).
    const checkFiberhomePort = async (port: string) => {
        if (!fhOltId || !port) return null;
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
        try {
            const res = await fetch('/api/olt-c300/check', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                credentials: 'include',
                body: JSON.stringify({ olt_id: parseInt(fhOltId), port }),
            });
            const data = await res.json();
            if (data && typeof data.last_onu_id !== 'undefined') {
                const lastId = data.last_onu_id as number;
                const nextId = (data.next_onu_id ?? null) as number | null; // gap-fill dari server
                const usedCount = (data.onu || []).length;
                if (nextId === null) {
                    addFhLog('error', `Port ${port} sudah penuh (last_onu_id=${lastId}, max 128). Hapus ONU lama dulu.`);
                    return null;
                }
                setFhNextOnuId(nextId);
                addFhLog('info', `Port ${port}: ${usedCount} ONU terdaftar. ONU ID terakhir: ${lastId} → next (gap-fill): ${nextId}`);
                return {
                    vlan: data.vlan,
                    nextId,
                    lastId,
                    usedCount,
                };
            }
            addFhLog('error', 'Respons check port tidak valid (tidak ada last_onu_id).');
        } catch (err: any) {
            addFhLog('error', `Gagal check port: ${err.message}`);
        }
        return null;
    };

    // ===== FiberHome SELECT FROM UNCFG (klik salah satu SN di popover) =====
    const handleFiberhomeSelectUncfg = async (item: {port: string, onu_id: number, sn: string}) => {
        setFhSn(item.sn);
        setFhPort(item.port);
        setFhUncfgPopover(false);
        addFhLog('info', `Pilih dari uncfg: SN ${item.sn} di port ${item.port}:${item.onu_id}`);
        addFhLog('phase', 'Auto-check port untuk next available ONU ID...');
        const result = await checkFiberhomePort(item.port);
        if (result) {
            setFhVlan(result.vlan);
            setFhOnuId(String(result.nextId));
            addFhLog('success', `Auto-fill: SN=${item.sn}, Port=${item.port}, ONU ID=${result.nextId} (next available), VLAN=${result.vlan}`);
            toast.success(`SN ${item.sn} dipilih — ONU ID ${result.nextId} disarankan`);
        }
        await lookupFhCustomerBySn(item.sn);
    };

    // ===== FiberHome DETECT handler (reuse activate detect logic, fill fhPort/fhOnuId/fhVlan) =====
    const handleFiberhomeDetect = async () => {
        if (!fhOltId || !fhSn) { toast.error('Pilih OLT dan masukkan SN dulu'); return; }
        setFhDetecting(true);
        addFhLog('phase', 'DETEKSI FiberHome — mencari ONU di OLT');
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
        try {
            const res = await fetch('/api/olt-c300/detect', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                credentials: 'include',
                body: JSON.stringify({ olt_id: parseInt(fhOltId), sn: fhSn }),
            });
            const data = await res.json();
            if (data.found) {
                // Port dari detect; ONU ID selalu dari gap-fill (check port) — JANGAN pakai index uncfg
                if (data.multiple_ports && data.ports && data.ports.length > 1) {
                    addFhLog('info', `SN terdeteksi di ${data.ports.length} PON port — pakai port pertama: ${data.ports[0].port}`);
                    const first = data.ports[0];
                    setFhPort(first.port);
                    setFhVlan(first.vlan);
                    const chk = await checkFiberhomePort(first.port);
                    if (chk) {
                        setFhOnuId(String(chk.nextId));
                        if (chk.vlan != null) setFhVlan(chk.vlan);
                        addFhLog('success', `Port ${first.port}, ONU ID ${chk.nextId} (gap-fill), VLAN ${chk.vlan ?? first.vlan}`);
                    } else {
                        setFhOnuId(String(first.onu_id));
                    }
                    toast.warning('SN di beberapa port — default ke port pertama + gap-fill ONU ID');
                } else {
                    setFhPort(data.port);
                    setFhVlan(data.vlan);
                    const chk = await checkFiberhomePort(data.port);
                    if (chk) {
                        setFhOnuId(String(chk.nextId));
                        if (chk.vlan != null) setFhVlan(chk.vlan);
                        addFhLog('success', `Port ${data.port}, ONU ID ${chk.nextId} (gap-fill sama manual), VLAN ${chk.vlan ?? data.vlan}`);
                    } else {
                        setFhOnuId(String(data.onu_id));
                        addFhLog('success', `ONU di Port ${data.port}, ONU ID ${data.onu_id}, VLAN ${data.vlan}`);
                    }
                    toast.success('ONU terdeteksi!');
                }
                applyFhOnuNameSuggestion(data, fhSn);
            } else {
                addFhLog('error', `${data.message || 'SN tidak ditemukan di OLT'}`);
                if (data.uncfg_onus && data.uncfg_onus.length > 0) {
                    addFhLog('info', 'ONU uncfg yang tersedia:');
                    data.uncfg_onus.forEach((u: any) => addFhLog('info', `   ${u.port}:${u.onu_id} — SN: ${u.sn}`));
                }
                toast.error('ONU tidak terdeteksi');
            }
        } catch (err: any) {
            addFhLog('error', `Network error: ${err.message}`);
            toast.error('Deteksi gagal');
        }
        setFhDetecting(false);
    };

    // ===== FiberHome provision handler =====
    const handleHuaweiListAutofind = async (refresh = false) => {
        if (!hwOltId) { toast.error('Pilih OLT Huawei dulu'); return; }
        setHwAutofindLoading(true);
        setHwAutofindError(null);
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
        try {
            const res = await fetch('/api/olt-huawei/autofind', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                credentials: 'include',
                body: JSON.stringify({ olt_id: parseInt(hwOltId), refresh }),
            });
            const data = await res.json();
            if (!res.ok || !data.success) {
                setHwAutofindError(data.error || (data.message ? String(data.message) : 'Daftar ONT gagal dimuat.'));
                return;
            }
            setHwAutofind(Array.isArray(data.entries) ? data.entries : []);
            setHwAutofindMeta({ age_seconds: Number(data.age_seconds ?? 0), cached: !!data.cached, partial: !!data.partial });
        } catch {
            setHwAutofindError('Daftar ONT gagal dimuat.');
        } finally {
            setHwAutofindLoading(false);
        }
    };

    const handleHuaweiSelectAutofind = (entry: HwAutofindEntry) => {
        setHwAutofindOpen(false);
        setHwSn(entry.sn);
        setHwPonHint(entry.pon_port);
        void handleHuaweiDetect(entry.sn, entry.pon_port);
    };

    const handleHuaweiDetect = async (snOverride?: string, ponOverride?: string | null) => {
        const sn = snOverride ?? hwSn;
        const ponPort = snOverride !== undefined ? (ponOverride ?? null) : hwPonHint;
        if (!hwOltId || !sn) {
            toast.error('Pilih OLT Huawei dan isi SN dulu');
            return;
        }
        setHwDetecting(true);
        setHwPlacement(null);
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
        try {
            const res = await fetch('/api/olt-huawei/detect', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                credentials: 'include',
                body: JSON.stringify({ olt_id: parseInt(hwOltId), sn, ...(ponPort ? { pon_port: ponPort } : {}) }),
            });
            const data = await res.json();
            if (!res.ok || !data.success) {
                toast.error(data.error || 'Deteksi Huawei gagal');
                return;
            }
            if (typeof data.sn === 'string' && data.sn !== '') { setHwSn(data.sn); }
            setHwPlacement(data);
            toast.success(`Terdeteksi ${data.pon_port}:${data.onu_id} · VLAN ${data.vlan}`);
        } catch {
            toast.error('Deteksi Huawei gagal');
        } finally {
            setHwDetecting(false);
        }
    };

    const handleHuaweiProvision = async (dryRun: boolean, liveConfirm?: string) => {
        if (!hwOltId || !hwSn || !hwName) {
            toast.error('Lengkapi OLT, SN, dan Nama/PPPoE dulu');
            return;
        }
        setHwLoading(true);
        setHwResult(null);
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
        try {
            const res = await fetch('/api/olt-huawei/provision', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                credentials: 'include',
                body: JSON.stringify({
                    olt_id: parseInt(hwOltId),
                    sn: hwSn,
                    name: hwName,
                    paket: hwPaket,
                    dry_run: dryRun,
                    live_confirm: dryRun ? undefined : liveConfirm,
                }),
            });
            const data = await res.json();
            setHwResult(data);
            if (res.ok && data.success) {
                toast.success(dryRun ? 'Dry run Huawei OK' : 'Provisioning Huawei selesai');
            } else {
                toast.error(data.error || data.message || 'Provisioning Huawei gagal');
            }
        } catch {
            toast.error('Provisioning Huawei gagal');
        } finally {
            setHwLoading(false);
        }
    };

    const handleFiberhomeProvision = async (dryRun: boolean, liveConfirm?: string) => {
        if (!fhOltId || !fhSn || !fhPort || !fhOnuId || !fhName) {
            toast.error('Lengkapi semua field dulu (OLT, SN, Port, ONU ID, Name)');
            return;
        }
        if (!fhMikrotik) {
            toast.error('Pilih MikroTik dulu (MikroTik RA · TR-069 VLAN 69)');
            return;
        }
        setFhDryRun(dryRun);
        setFhLoading(true);
        setFhResult(null);
        if (dryRun) setFhLog([]);
        const mtOpt = fhMikrotikOptions.find(o => o.key === fhMikrotik);
        const mtLabel = mtOpt?.label || fhMikrotik;
        addFhLog('info', `Jenis data: ${fhPurpose === 'migration' ? 'Migrasi' : 'PSB Baru'}`);
        addFhLog('phase', dryRun ? 'DRY RUN FiberHome — preview command plan' : 'LIVE PROVISIONING FiberHome ke OLT');
        addFhLog('info', `MikroTik: ${mtLabel} → TR-069 VLAN ${mtOpt?.tr069_vlan ?? tr069Vlan}`);
        if (!dryRun) {
            addFhLog('info', 'Langkah: Koneksi → Daftar ONU → Setting → Simpan → Baca RX → Inventory + Tag ACS');
        }
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
        const onuIdInt = parseInt(fhOnuId);
        if (isNaN(onuIdInt) || onuIdInt < 1 || onuIdInt > 128) {
            addFhLog('error', 'ONU ID harus 1-128');
            toast.error('ONU ID invalid');
            setFhLoading(false);
            return;
        }
        // Log auto-suggest source for transparency
        if (fhNextOnuId === onuIdInt) {
            addFhLog('info', `ONU ID ${onuIdInt} = auto-suggested (last+1 dari running-config)`);
        } else {
            addFhLog('info', `ONU ID ${onuIdInt} = manual override`);
        }
        try {
            const res = await fetch('/api/olt-c300/provision-fiberhome', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                credentials: 'include',
                body: JSON.stringify({
                    olt_id: parseInt(fhOltId),
                    sn: fhSn,
                    port: fhPort,
                    onu_id: onuIdInt,
                    name: fhName,
                    paket: fhPaket,
                    dry_run: dryRun,
                    mikrotik: fhMikrotik,
                    router_id: mtOpt?.router_id ?? undefined,
                    purpose: fhPurpose,
                    live_confirm: dryRun ? undefined : liveConfirm,
                }),
            });
            const rawText = await res.text();
            let data: any = null;
            try {
                data = rawText ? JSON.parse(rawText) : null;
            } catch {
                const head = (rawText || '').replace(/\s+/g, ' ').slice(0, 180);
                addFhLog('error', `Server balas non-JSON (HTTP ${res.status}). Bukan error SN — biasanya timeout/proxy/CSRF/HTML error.`);
                if (head) addFhLog('info', `Cuplikan: ${head}`);
                if (/payload is invalid/i.test(rawText || '')) {
                    addFhLog('info', '"payload is invalid" = decrypt password lama. Sudah di-handle SafeEncrypted; hard-refresh halaman (Ctrl+F5) lalu coba lagi.');
                }
                toast.error(`HTTP ${res.status}: respons bukan JSON`);
                setFhLoading(false);
                return;
            }
            if (!data || typeof data !== 'object') {
                addFhLog('error', `Respons kosong (HTTP ${res.status})`);
                toast.error('Respons kosong dari server');
                setFhLoading(false);
                return;
            }
            setFhResult(data);
            if (typeof data.name_warning === 'string' && data.name_warning) {
                // Nama ONU harus mengikuti PPPoE lengkap (contoh USERAB27_..., bukan USERAB7).
                addFhLog('error', `PERINGATAN nama ONU: ${data.name_warning}`);
            }
            if (data.success || data.olt_committed) {
                if (data.dry_run) {
                    addFhLog('info', `Plan: ${data.command_count} command akan dikirim ke OLT`);
                    addFhLog('info', `   Traffic-limit: UP${data.traffic_up.replace('UP', '')} / DW${data.traffic_down.replace('DW', '')}`);
                    addFhLog('info', `   VLAN: ${data.vlan} (internet) + ${data.tr069_vlan ?? tr069Vlan} (TR-069)`);
                    data.plan.forEach((step: any, idx: number) => {
                        if (step.cmd) addFhLog('cmd', `  ${idx + 1}. [${step.label}] ${step.cmd}`);
                        else addFhLog('info', `  ${idx + 1}. [${step.label}]`);
                    });
                    addFhLog('success', 'Dry run OK — belum ada perubahan ke OLT');
                    toast.success('Dry run selesai — review command plan di log');
                } else {
                    addFhLog('success', 'Provisioning FiberHome BERHASIL di OLT');
                    addFhLog('success', `Tercatat sebagai ${data.recorded_as === 'migration' ? 'Migrasi' : data.recorded_as === 'migration_linked' ? 'PSB draft (tertaut migrasi)' : 'PSB Baru'}${data.record_id ? ` #${data.record_id}` : ''}`);
                    logMigrationLink(data, addFhLog);
                    if (Array.isArray(data.post_warnings) && data.post_warnings.length > 0) {
                        const soft = data.post_warnings.filter((w: string) => !/payload is invalid|DecryptException/i.test(w));
                        if (soft.length > 0) {
                            addFhLog('info', 'Catatan non-kritis (OLT sudah aktif):');
                            soft.forEach((w: string) => addFhLog('info', `   · ${w}`));
                        }
                    }
                    // ACS never shown as error — pending is normal
                    if (data.genieacs_tag && !data.genieacs_tag.pushed) {
                        addFhLog('info', `GenieACS: ${data.genieacs_tag.message || 'tag dijadwalkan otomatis'}`);
                    }
                    if (data.onu_id_reassigned) {
                        addFhLog(
                            'info',
                            `ONU ID di-reassign (aman concurrent): diminta ${data.onu_id_requested ?? '?'} → dipakai ${data.onu_id}`,
                        );
                        setFhOnuId(String(data.onu_id));
                    } else if (data.onu_id) {
                        setFhOnuId(String(data.onu_id));
                    }
                    if (data.log && Array.isArray(data.log)) {
                        processBackendLog(data.log, addFhLog);
                    }
                    const vi = data.verification || {};
                    const inv = data.olt_inventory || {};
                    const rx = vi.rx_power ?? inv.rx_power_dbm;
                    if (rx != null && rx !== '') {
                        addFhLog('success', `RX power: ${rx} dBm — tersimpan di OLT management`);
                    } else {
                        addFhLog('info', 'RX belum terukur (ONT mungkin belum optical) — nama sudah di inventory');
                    }
                    addFhLog('info', `Nama ONU di inventory: ${inv.onu_name || data.name || fhName}`);
                    const acsTag = data.genieacs_tag;
                    if (acsTag?.pushed) {
                        addFhLog('success', `Tag GenieACS: ${acsTag.tag} (langsung)`);
                        toast.success(
                            data.onu_id_reassigned
                                ? `FiberHome OK — ONU ID ${data.onu_id} (reassign concurrent)`
                                : `FiberHome OK — tag GenieACS: ${acsTag.tag}`,
                        );
                    } else if (acsTag?.pending || acsTag?.queued) {
                        addFhLog('info', `Tag GenieACS diantrikan: ${acsTag.tag || fhName} — retry otomatis tiap beberapa detik`);
                        toast.success(
                            data.onu_id_reassigned
                                ? `FiberHome OK — ONU ID ${data.onu_id} (reassign concurrent)`
                                : 'FiberHome OK — tag ACS diantrikan (cepat saat device online)',
                        );
                    } else {
                        toast.success(
                            data.onu_id_reassigned
                                ? `FiberHome OK — ONU ID ${data.onu_id} (reassign concurrent)`
                                : 'FiberHome berhasil diaktifkan!',
                        );
                    }
                }
            } else {
                if (res.status === 429 || data.concurrent_busy) {
                    const scope = data.concurrent_scope === 'sn'
                        ? 'SN yang sama sedang diaktivasi'
                        : data.concurrent_scope === 'claim'
                            ? 'Klaim ONU ID di port (sebentar)'
                            : 'Sibuk concurrent';
                    addFhLog('info', `${data.error || scope}`);
                    toast.warning(data.error || `${scope} — coba lagi sebentar`);
                } else {
                    const gateMessage = data.error_code?.startsWith('olt_provisioning_')
                        ? data.message || 'Provisioning ditahan safety gate OLT'
                        : data.error || 'Provisioning gagal';
                    addFhLog('error', gateMessage);
                    if (data.error_code === 'vlan_service_port' || /service-port gagal|vlan/i.test(String(data.error || ''))) {
                        addFhLog('info', `Port ${data.port || fhPort} map ke VLAN ${data.vlan ?? fhVlan ?? '?'}. Pastikan VLAN itu sudah ada di OLT, atau ubah VLAN Mapping di form.`);
                    }
                    if (/payload is invalid/i.test(String(data.error || ''))) {
                        addFhLog('info', 'Decrypt password legacy — hard-refresh (Ctrl+F5). Bukan masalah SN/port.');
                    }
                    if (data.log && Array.isArray(data.log)) {
                        processBackendLog(data.log, addFhLog);
                    }
                    toast.error(gateMessage);
                }
            }
        } catch (err: any) {
            addFhLog('error', `Network error: ${err.message}`);
            if (/JSON\.parse/i.test(String(err.message || ''))) {
                addFhLog('info', 'Respons server bukan JSON (HTML/timeout). Coba lagi; jika berulang cek session login.');
            }
            toast.error('Network error');
        }
        setFhLoading(false);
    };

    const handleReconfigure = async (liveConfirm: string) => {
        if (!reconfigOltId || !reconfigPort || !reconfigOnuId || !reconfigSn || !reconfigName || !reconfigPassword.trim()) {
            toast.error('Semua field wajib diisi'); return;
        }
        setReconfigLoading(true); setReconfigResult(null); setReconfigLog([]);
        const vlanMatch = portOptions.find(p => p.value === reconfigPort);
        const vlan = vlanMatch?.vlan || 0;
        const rPassword = reconfigPassword;
        addReconfigLog('phase', 'FASE REKONFIGURASI — Ganti ONU di Port PON');
        addReconfigLog('info', `Port: ${reconfigPort}  |  ONU ID: ${reconfigOnuId}  |  SN Baru: ${reconfigSn}  |  VLAN: ${vlan}`);
        addReconfigLog('info', '');
        const phases = getReconfigCommands(reconfigPort, parseInt(reconfigOnuId), reconfigName, vlan, reconfigSn, '[REDACTED]', tr069Vlan);
        phases.forEach(phase => {
            addReconfigLog('phase', `${phase.phase}: ${phase.desc}`);
            phase.cmds.forEach(cmd => addReconfigLog('cmd', `  ${cmd}`));
            addReconfigLog('info', '');
        });
        addReconfigLog('info', 'Menunggu respons dari OLT...');
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
        try {
            const res = await fetch('/api/olt-c300/reconfigure', { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken }, credentials: 'include', body: JSON.stringify({ olt_id: parseInt(reconfigOltId), port: reconfigPort, onu_id: parseInt(reconfigOnuId), sn: reconfigSn, name: reconfigName, password: rPassword, vlan, live_confirm: liveConfirm }) });
            const data = await res.json();
            if (data.success) {
                addReconfigLog('success', 'REKONFIGURASI BERHASIL');
                processBackendLog(data.log, addReconfigLog);
                if (data.status) addReconfigLog('info', `   Status: ${data.status}`);
                if (data.rx_power) addReconfigLog('info', `   Rx Power: ${data.rx_power} dBm`);
                if (data.distance) addReconfigLog('info', `   Jarak: ${data.distance}`);
                setReconfigResult(data);
                toast.success('Rekonfigurasi berhasil!');
            } else {
                addReconfigLog('error', `Gagal: ${data.message || 'Rekonfigurasi gagal'}`);
                if (data.log) processBackendLog(data.log, addReconfigLog);
                toast.error('Rekonfigurasi gagal');
            }
        } catch (err: any) { addReconfigLog('error', `Error: ${err.message}`); toast.error('Rekonfigurasi error'); }
        setReconfigLoading(false);
    };

    const copyFhLog = () => {
        const text = fhLog.map(l => `[${l.time}] ${l.msg}`).join('\n');
        navigator.clipboard.writeText(text).then(() => toast.success('Log disalin'));
    };
    const copyReconfigLog = () => { navigator.clipboard.writeText(reconfigLog.map(l => `[${l.time}] ${l.msg}`).join('\n')); toast.success('Log disalin!'); };

    return (
        <EnterpriseIndexLayout
            title="Aktivasi OLT"
            pageTitle="Aktivasi Pelanggan & ONU"
            subtitle="Buat pelanggan PPPoE, lalu provision ONU sesuai vendor OLT."
            icon={Cable}
            breadcrumbs={[{ label: 'OLT C300', href: route('oltc300.index') }]}
        >
            <div className="max-w-5xl mx-auto sm:px-4 lg:px-8 space-y-4">
                    {/* Tab Switcher — S6: ui/tabs (role="tablist", aria-selected, panah kiri/kanan), scroll horizontal di HP. */}
                    <Tabs value={activeTab} onValueChange={(value) => setActiveTab(value as AktivasiTab)}>
                        <TabsList aria-label="Mode aktivasi" className="flex h-auto w-full justify-start gap-2 overflow-x-auto rounded-none border-b bg-transparent p-0 pb-0 md:flex-wrap md:overflow-visible">
                            <TabsTrigger value="autopp" className="min-h-10 shrink-0 gap-1.5 rounded-b-none rounded-t-lg px-4 py-2 text-muted-foreground hover:bg-muted hover:text-foreground data-[state=active]:bg-primary data-[state=active]:text-primary-foreground data-[state=active]:shadow-none">
                                Auto PPPoE
                            </TabsTrigger>
                            <TabsTrigger value="activate" className="min-h-10 shrink-0 gap-1.5 rounded-b-none rounded-t-lg px-4 py-2 text-muted-foreground hover:bg-muted hover:text-foreground data-[state=active]:bg-primary data-[state=active]:text-primary-foreground data-[state=active]:shadow-none">
                                Provision ZTE C300
                            </TabsTrigger>
                            <TabsTrigger value="reconfigure" className="min-h-10 shrink-0 gap-1.5 rounded-b-none rounded-t-lg px-4 py-2 text-muted-foreground hover:bg-muted hover:text-foreground data-[state=active]:bg-primary data-[state=active]:text-primary-foreground data-[state=active]:shadow-none">
                                Rekonfig ZTE
                            </TabsTrigger>
                            <TabsTrigger value="fiberhome" className="min-h-10 shrink-0 gap-1.5 rounded-b-none rounded-t-lg px-4 py-2 text-muted-foreground hover:bg-muted hover:text-foreground data-[state=active]:bg-primary data-[state=active]:text-primary-foreground data-[state=active]:shadow-none">
                                Provision FiberHome
                            </TabsTrigger>
                            <TabsTrigger value="huawei" className="min-h-10 shrink-0 gap-1.5 rounded-b-none rounded-t-lg px-4 py-2 text-muted-foreground hover:bg-muted hover:text-foreground data-[state=active]:bg-primary data-[state=active]:text-primary-foreground data-[state=active]:shadow-none">
                                Provision Huawei
                            </TabsTrigger>
                            <TabsTrigger value="psb" className="min-h-10 shrink-0 gap-1.5 rounded-b-none rounded-t-lg px-4 py-2 text-muted-foreground hover:bg-muted hover:text-foreground data-[state=active]:bg-primary data-[state=active]:text-primary-foreground data-[state=active]:shadow-none">
                                <MessageCircle className="h-3.5 w-3.5" aria-hidden="true" />
                                PSB WA
                            </TabsTrigger>
                            <TabsTrigger value="vlan-mt" className="min-h-10 shrink-0 gap-1.5 rounded-b-none rounded-t-lg px-4 py-2 text-muted-foreground hover:bg-muted hover:text-foreground data-[state=active]:bg-primary data-[state=active]:text-primary-foreground data-[state=active]:shadow-none">
                                <Network className="h-3.5 w-3.5" aria-hidden="true" />
                                VLAN MikroTik
                            </TabsTrigger>
                            <TabsTrigger value="vlan-olt" className="min-h-10 shrink-0 gap-1.5 rounded-b-none rounded-t-lg px-4 py-2 text-muted-foreground hover:bg-muted hover:text-foreground data-[state=active]:bg-primary data-[state=active]:text-primary-foreground data-[state=active]:shadow-none">
                                <Network className="h-3.5 w-3.5" aria-hidden="true" />
                                VLAN OLT / Area
                            </TabsTrigger>
                            <TabsTrigger value="rename" className="min-h-10 shrink-0 gap-1.5 rounded-b-none rounded-t-lg px-4 py-2 text-muted-foreground hover:bg-muted hover:text-foreground data-[state=active]:bg-primary data-[state=active]:text-primary-foreground data-[state=active]:shadow-none">
                                <User className="h-3.5 w-3.5" aria-hidden="true" />
                                Rename ONU
                            </TabsTrigger>
                        </TabsList>

                    {/* Satu panel aktif: aria-controls tab resolve ke id panel ini (panel lain tidak dirender). */}
                    <TabsContent value={activeTab} className="mt-4 space-y-4">
                    {activeTab === 'psb' && (
                        <div className="pt-1">
                            <Suspense fallback={<Card><CardContent className="p-6 text-sm text-muted-foreground">Memuat panel PSB WA...</CardContent></Card>}>
                                <PsbWaPanel defaultAcsUrl={psbDefaultAcsUrl} />
                            </Suspense>
                        </div>
                    )}

                    {activeTab === 'vlan-mt' && (
                        <div className="pt-1">
                            <Suspense fallback={<Card><CardContent className="p-6 text-sm text-muted-foreground">Memuat panel VLAN...</CardContent></Card>}>
                                <RouterVlanPanel />
                            </Suspense>
                        </div>
                    )}

                    {activeTab === 'rename' && (
                        <div className="space-y-4 pt-1">
                            <Card className="border-border/80 overflow-hidden">
                                <CardHeader className="bg-muted/50 border-b border-border/60">
                                    <CardTitle className="text-lg flex items-center gap-2">
                                        <User className="h-5 w-5 text-muted-foreground" />
                                        Rename ONU di OLT
                                    </CardTitle>
                                    <p className="text-sm text-muted-foreground leading-relaxed">
                                        Ganti <span className="font-mono text-foreground">name</span> di chassis OLT
                                        <strong> dan otomatis rename tag GenieACS</strong> ke nama yang sama
                                        (tag lama dibersihkan). Pastikan SN terisi agar ACS ikut.
                                    </p>
                                </CardHeader>
                                <CardContent className="pt-5 space-y-4">
                                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div>
                                            <Label className="text-sm font-medium">OLT</Label>
                                            <Select value={renameOltId || '__none__'} onValueChange={(v) => setRenameOltId(v === '__none__' ? '' : v)}>
                                                <SelectTrigger><SelectValue placeholder="-- Pilih OLT --" /></SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="__none__">-- Pilih OLT --</SelectItem>
                                                    {olts.map((olt) => (
                                                        <SelectItem key={olt.id} value={String(olt.id)}>{olt.name}</SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        <div>
                                            <Label className="text-sm font-medium">SN ONU</Label>
                                            <p className="text-xs text-muted-foreground mb-1">Contoh FHTT0000A001 — cari inventory</p>
                                            <Input
                                                value={renameSn}
                                                onChange={(e) => setRenameSn(e.target.value.toUpperCase())}
                                                placeholder="FHTT…"
                                                className="font-mono"
                                            />
                                        </div>
                                        <div>
                                            <Label className="text-sm font-medium">ONU ref</Label>
                                            <p className="text-xs text-muted-foreground mb-1">Format <span className="font-mono">1/3/3:40</span></p>
                                            <Input
                                                value={renameOnuRef}
                                                onChange={(e) => setRenameOnuRef(e.target.value)}
                                                placeholder="1/3/3:40"
                                                className="font-mono"
                                            />
                                        </div>
                                        <div className="flex items-end gap-2">
                                            <Button type="button" variant="outline" className="flex-1" onClick={lookupRenameOnu} disabled={renameLooking || !renameOltId}>
                                                {renameLooking ? <><Loader2 className="h-4 w-4 mr-2 animate-spin" />Cari…</> : <><Search className="h-4 w-4 mr-2" />Cari inventory</>}
                                            </Button>
                                        </div>
                                    </div>

                                    {renameCurrentName && (
                                        <div className="rounded-lg border bg-muted/30 px-3 py-2 text-sm">
                                            Nama sekarang: <span className="font-mono font-semibold">{renameCurrentName}</span>
                                            {renameOnuRef && <> · <span className="font-mono text-muted-foreground">{renameOnuRef}</span></>}
                                        </div>
                                    )}

                                    <div>
                                        <Label className="text-sm font-medium">Nama baru</Label>
                                        <p className="text-xs text-muted-foreground mb-1">Otomatis UPPER + underscore (sama PSB)</p>
                                        <Input
                                            value={renameNewName}
                                            onChange={(e) => setRenameNewName(e.target.value)}
                                            onBlur={() => setRenameNewName(normalizeOnuName(renameNewName))}
                                            placeholder="NAMA_RT03_RW04_ODP"
                                            className="font-mono"
                                        />
                                        {renameNewName && (
                                            <p className="text-xs text-muted-foreground mt-1">
                                                Preview OLT: <span className="font-mono text-foreground">{normalizeOnuName(renameNewName) || '—'}</span>
                                            </p>
                                        )}
                                    </div>

                                    <div className="rounded-lg border border-border bg-muted/50 px-3 py-2 text-xs text-muted-foreground">
                                        <span className="font-medium text-foreground">ACS otomatis:</span>
                                        {' '}tag GenieACS diganti ke nama baru + tag sekunder di-prune.
                                        Kalau ONT belum inform, tag diantrikan retry.
                                    </div>

                                    <div className="flex flex-wrap gap-2">
                                        <Button
                                            type="button"
                                            onClick={() => setRenameConfirmOpen(true)}
                                            disabled={renameSaving || !renameOltId || !normalizeOnuName(renameNewName)}
                                            className="min-w-[160px]"
                                        >
                                            {renameSaving ? <><Loader2 className="h-4 w-4 mr-2 animate-spin" />Menulis OLT…</> : <><CheckCircle2 className="h-4 w-4 mr-2" />Simpan nama OLT</>}
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            onClick={() => {
                                                setRenameLog([]);
                                                setRenameResult(null);
                                                setRenameCurrentName('');
                                                setRenameNewName('');
                                                setRenameSn('');
                                                setRenameOnuRef('');
                                            }}
                                        >
                                            Reset
                                        </Button>
                                    </div>

                                    <ConfirmDestructive
                                        open={renameConfirmOpen}
                                        onOpenChange={setRenameConfirmOpen}
                                        title="Konfirmasi rename ONU"
                                        description="Nama akan ditulis ke OLT dan dipakai untuk sinkronisasi tag GenieACS."
                                        confirmationLabel="Saya sudah memeriksa OLT, SN/ONU ref, dan nama baru."
                                        typedConfirmation="RENAME ONU"
                                        typedConfirmationLabel="Ketik RENAME ONU untuk mengizinkan write."
                                        confirmText="Simpan nama"
                                        onConfirm={(typed) => {
                                            setRenameConfirmOpen(false);
                                            void submitRenameOnu(typed);
                                        }}
                                        isLoading={renameSaving}
                                    >
                                        <div className="grid grid-cols-2 gap-x-4 gap-y-1 rounded-lg border bg-muted/30 p-3 text-sm">
                                            <span className="text-muted-foreground">OLT</span><span>{olts.find((olt) => String(olt.id) === renameOltId)?.name || renameOltId || '-'}</span>
                                            <span className="text-muted-foreground">ONU ref</span><span className="font-mono">{renameOnuRef || '-'}</span>
                                            <span className="text-muted-foreground">SN</span><span className="font-mono">{renameSn || '-'}</span>
                                            <span className="text-muted-foreground">Nama baru</span><span className="font-mono">{normalizeOnuName(renameNewName) || '-'}</span>
                                        </div>
                                    </ConfirmDestructive>

                                    {renameResult?.success && (
                                        <div className="rounded-lg border border-status-up/30 bg-status-up-bg p-3 text-sm text-foreground">
                                            <CheckCircle2 className="inline-block h-4 w-4 mr-1" aria-hidden="true" />
                                            {renameResult.message || 'Rename OK'}
                                            {renameResult.acs_tag && (
                                                <div className="text-xs mt-1 opacity-90">
                                                    ACS: {renameResult.acs_tag.pushed ? 'tag pushed' : (renameResult.acs_tag.message || 'pending/skip')}
                                                </div>
                                            )}
                                        </div>
                                    )}

                                    <ConsoleLog log={renameLog} onReset={() => setRenameLog([])} maxHeight="h-48" />
                                </CardContent>
                            </Card>
                        </div>
                    )}

                    {activeTab !== 'autopp' && activeTab !== 'psb' && activeTab !== 'vlan-mt' && activeTab !== 'rename' && (
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-base flex items-center gap-2">
                                    <Network className="h-4 w-4" /> VLAN Mapping OLT
                                </CardTitle>
                                <p className="text-xs text-muted-foreground">
                                    Simpan VLAN internet per port PON per OLT. Area hanya menjadi filter/konteks dari area OLT; provisioning tetap membaca mapping OLT/PON yang sama.
                                    {activeOltForVlan ? ` · ${activeVlanOlt?.name || `OLT #${activeOltForVlan}`} · Area: ${activeVlanOlt?.area_name || 'belum diatur'} · ${vlanMappings.length} baris` : ''}
                                </p>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                <div className="grid gap-3 md:grid-cols-2">
                                    <div>
                                        <Label className="text-xs">Filter area OLT</Label>
                                        <Select value={vlanAreaFilter} onValueChange={setVlanAreaFilter}>
                                            <SelectTrigger><SelectValue placeholder="Semua area" /></SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="all">Semua area</SelectItem>
                                                {initialAreas.map((area) => (
                                                    <SelectItem key={area.id} value={String(area.id)}>{area.name}</SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div>
                                        <Label className="text-xs">OLT untuk mapping</Label>
                                        <Select value={vlanOltId || '__flow__'} onValueChange={(value) => setVlanOltId(value === '__flow__' ? '' : value)}>
                                            <SelectTrigger><SelectValue placeholder="Ikuti OLT tab aktif" /></SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="__flow__">Ikuti OLT tab aktif</SelectItem>
                                                {vlanOlts.map((olt) => (
                                                    <SelectItem key={olt.id} value={String(olt.id)}>
                                                        {olt.name} · {olt.area_name || 'tanpa area'}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                </div>
                                {!activeOltForVlan ? (
                                    <div className="rounded-md border border-dashed p-3 text-sm text-muted-foreground">
                                        Pilih area dan OLT untuk melihat mapping. Jika mengikuti tab aktif, pilih OLT dulu di Activate / FiberHome.
                                    </div>
                                ) : (
                                    <>
                                        <div className="grid gap-3 md:grid-cols-[1fr_120px_1.4fr_auto_auto]">
                                            <div>
                                                <Label className="text-xs">Port PON</Label>
                                                <Input value={vlanPort} onChange={(event) => setVlanPort(event.target.value)} placeholder="1/3/14" />
                                            </div>
                                            <div>
                                                <Label className="text-xs">VLAN</Label>
                                                <Input value={vlanValue} onChange={(event) => setVlanValue(event.target.value)} inputMode="numeric" placeholder="314" />
                                            </div>
                                            <div>
                                                <Label className="text-xs">Catatan</Label>
                                                <Input value={vlanDescription} onChange={(event) => setVlanDescription(event.target.value)} placeholder="optional" />
                                            </div>
                                            <div className="flex items-end">
                                                <Button type="button" onClick={saveVlanMapping} disabled={vlanSaving} className="w-full">
                                                    {vlanSaving ? <Loader2 className="h-4 w-4 animate-spin" /> : 'Simpan'}
                                                </Button>
                                            </div>
                                            <div className="flex items-end">
                                                <Button type="button" variant="outline" onClick={() => reloadVlanMappings()} className="w-full" title="Refresh mapping" aria-label="Muat ulang VLAN mapping">
                                                    <RefreshCw className="h-4 w-4" aria-hidden="true" />
                                                </Button>
                                            </div>
                                        </div>

                                        <div className="overflow-x-auto rounded-md border">
                                            <table className="w-full text-sm">
                                                <thead className="bg-muted/50 text-xs uppercase text-muted-foreground">
                                                    <tr>
                                                        <th className="px-3 py-2 text-left">Port</th>
                                                        <th className="px-3 py-2 text-left">VLAN</th>
                                                        <th className="px-3 py-2 text-left">Catatan</th>
                                                        <th className="px-3 py-2 text-right">Aksi</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    {vlanMappings.length === 0 ? (
                                                        <tr>
                                                            <td colSpan={4} className="px-3 py-3 text-center text-muted-foreground">
                                                                Belum ada mapping. Untuk OLT RA: pilih OLT RA lalu refresh. Untuk Krian: isi port+VLAN lalu Simpan.
                                                            </td>
                                                        </tr>
                                                    ) : vlanMappings.map((mapping) => (
                                                        <tr key={mapping.id} className="border-t">
                                                            <td className="px-3 py-2 font-mono">{mapping.pon_port}</td>
                                                            <td className="px-3 py-2 font-mono">{mapping.vlan}</td>
                                                            <td className="px-3 py-2 text-muted-foreground">{mapping.description || '-'}</td>
                                                            <td className="px-3 py-2 text-right">
                                                                <Button type="button" variant="ghost" size="sm" onClick={() => {
                                                                    setVlanPort(mapping.pon_port);
                                                                    setVlanValue(String(mapping.vlan));
                                                                    setVlanDescription(mapping.description || '');
                                                                }}>
                                                                    Edit
                                                                </Button>
                                                                {mapping.id > 0 && (
                                                                    <Button type="button" variant="ghost" size="sm" className="text-destructive hover:text-destructive" onClick={() => setVlanDeleteTarget(mapping)}>
                                                                        Hapus
                                                                    </Button>
                                                                )}
                                                            </td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        </div>
                                        <ConfirmDestructive
                                            open={vlanDeleteTarget !== null}
                                            onOpenChange={(open) => {
                                                if (!open && !vlanDeleting) setVlanDeleteTarget(null);
                                            }}
                                            title="Hapus VLAN mapping?"
                                            description="Mapping dinonaktifkan di database, jadi provisioning berikutnya di port ini tidak lagi memakai VLAN ini. Konfigurasi ONU yang sudah aktif di OLT tidak diubah."
                                            confirmationLabel="Saya sudah memastikan port dan VLAN yang dihapus benar."
                                            confirmText="Hapus mapping"
                                            isLoading={vlanDeleting}
                                            onConfirm={() => {
                                                if (vlanDeleteTarget) void deleteVlanMapping(vlanDeleteTarget.id);
                                            }}
                                        >
                                            {vlanDeleteTarget && (
                                                <div className="grid grid-cols-2 gap-x-4 gap-y-1 rounded-lg border bg-muted/30 p-3 text-sm">
                                                    <span className="text-muted-foreground">OLT</span><span>{activeVlanOlt?.name || '-'}</span>
                                                    <span className="text-muted-foreground">Port PON</span><span className="font-mono">{vlanDeleteTarget.pon_port}</span>
                                                    <span className="text-muted-foreground">VLAN</span><span className="font-mono">{vlanDeleteTarget.vlan}</span>
                                                    <span className="text-muted-foreground">Catatan</span><span>{vlanDeleteTarget.description || '-'}</span>
                                                </div>
                                            )}
                                        </ConfirmDestructive>
                                    </>
                                )}
                            </CardContent>
                        </Card>
                    )}

                    {/* ===== AUTO PPPoE TAB ===== */}
                    {activeTab === 'autopp' && (
                        <div className="space-y-4">
                            <Card className="border-border/80 overflow-hidden">
                                <CardHeader className="bg-muted/50 border-b border-border/60">
                                    <CardTitle className="text-lg flex items-center gap-2">
                                        <Zap className="h-5 w-5 text-muted-foreground" />
                                        Auto PPPoE — PSB cepat
                                    </CardTitle>
                                    <p className="text-sm text-muted-foreground leading-relaxed">
                                        Foto <b>KTP</b> (OCR otomatis) + foto <b>ODP</b> dokumentasi instalasi.
                                        User PPPoE: <span className="font-mono text-foreground">NAMA_RTx_RWy_ODP</span>
                                        {' '}· Customer aktif + RADIUS + secret MikroTik.
                                    </p>
                                    <div className="flex flex-wrap gap-2 pt-1">
                                        {['1. Foto KTP', '2. Identitas', '3. Area/ODP', '4. Aktivasi'].map((s) => (
                                            <Badge key={s} variant="outline" className="text-[11px] font-normal">{s}</Badge>
                                        ))}
                                    </div>
                                </CardHeader>
                                <CardContent className="pt-5">
                                    <form onSubmit={submitAutopp} className="space-y-5">
                                        {/* Vendor */}
                                        <section className="rounded-xl border border-border/70 bg-muted/20 p-4">
                                            <h3 className="text-sm font-semibold mb-3 flex items-center gap-2"><Zap className="h-4 w-4" />Vendor</h3>
                                            <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                                                <div>
                                                    <Label className="text-sm">Vendor OLT <span className="text-status-down">*</span></Label>
                                                    <Select value={autoppVendor} onValueChange={(v) => { setAutoppVendor(v); setAutoppAreaId(''); setAutoppOdpId(''); setAutoppPackageId(''); }}>
                                                        <SelectTrigger><SelectValue placeholder="Pilih vendor" /></SelectTrigger>
                                                        <SelectContent>
                                                            <SelectItem value="htb">HTB</SelectItem>
                                                            <SelectItem value="hioso">Hioso</SelectItem>
                                                            <SelectItem value="zte">ZTE</SelectItem>
                                                            <SelectItem value="fiberhome">FiberHome</SelectItem>
                                                        </SelectContent>
                                                    </Select>
                                                </div>
                                                <div className="flex items-end">
                                                    {autoppVendor && (
                                                        <p className="text-xs text-muted-foreground">
                                                            {(autoppVendor === 'htb' || autoppVendor === 'hioso') && 'PPPoE-only → secret MikroTik, router pelanggan manual.'}
                                                            {autoppVendor === 'zte' && 'Setelah PPPoE → lanjut tab Aktivasi ONU (ZTE).'}
                                                            {autoppVendor === 'fiberhome' && 'Setelah PPPoE → lanjut tab FiberHome (VLAN + SN).'}
                                                        </p>
                                                    )}
                                                </div>
                                            </div>
                                        </section>

                                        {/* Photos: KTP + ODP */}
                                        <section className="rounded-xl border border-border/70 bg-muted/20 p-4">
                                            <h3 className="text-sm font-semibold mb-3 flex items-center gap-2"><Camera className="h-4 w-4" />Dokumentasi foto</h3>
                                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                                <div className="rounded-lg border border-dashed border-status-info/30 bg-status-info-bg p-3 space-y-2">
                                                    <div className="flex items-center justify-between">
                                                        <Label className="text-sm font-medium">Foto KTP <span className="text-muted-foreground font-normal">(OCR)</span></Label>
                                                        {autoppOcrConfidence != null && (
                                                            <Badge variant="secondary" className="text-[10px]">{Math.round(autoppOcrConfidence * 100)}%</Badge>
                                                        )}
                                                    </div>
                                                    {autoppKtpPreview && (
                                                        <img src={autoppKtpPreview} alt="Preview KTP" className="w-full h-36 object-cover rounded-md border border-border" />
                                                    )}
                                                    <Input
                                                        type="file"
                                                        accept="image/*"
                                                        capture="environment"
                                                        className="cursor-pointer"
                                                        onChange={e => {
                                                            const f = e.target.files?.[0] || null;
                                                            setAutoppKtpFile(f);
                                                            setPhotoPreview(f, 'ktp');
                                                            if (f) runKtpOcr(f);
                                                        }}
                                                    />
                                                    {autoppOcrLoading && (
                                                        <p className="text-xs text-status-info flex items-center gap-1">
                                                            <Loader2 className="h-3 w-3 animate-spin" /> Membaca KTP (multi-engine OCR)...
                                                        </p>
                                                    )}
                                                    {!autoppOcrLoading && autoppOcrNote && (
                                                        <p className={`text-xs ${autoppOcrNote.startsWith('OCR:') ? 'text-status-up' : 'text-status-degraded'}`}>
                                                            {autoppOcrNote}
                                                        </p>
                                                    )}
                                                    <p className="text-[11px] text-muted-foreground">Tips: full KTP, fokus, hindari silau. NIK · Nama · RT/RW terisi otomatis.</p>
                                                </div>
                                                <div className="rounded-lg border border-dashed border-status-up/30 bg-status-up-bg p-3 space-y-2">
                                                    <Label className="text-sm font-medium">Foto ODP <span className="text-muted-foreground font-normal">(dokumentasi)</span></Label>
                                                    {autoppOdpPreview && (
                                                        <img src={autoppOdpPreview} alt="Preview ODP" className="w-full h-36 object-cover rounded-md border border-border" />
                                                    )}
                                                    <Input
                                                        type="file"
                                                        accept="image/*"
                                                        capture="environment"
                                                        className="cursor-pointer"
                                                        onChange={e => {
                                                            const f = e.target.files?.[0] || null;
                                                            setAutoppOdpFile(f);
                                                            setPhotoPreview(f, 'odp');
                                                        }}
                                                    />
                                                    <p className="text-[11px] text-muted-foreground">Foto label/port ODP di lapangan. Disimpan ke arsip pelanggan.</p>
                                                </div>
                                            </div>
                                        </section>

                                        {/* Identity */}
                                        <section className="rounded-xl border border-border/70 bg-muted/20 p-4">
                                            <h3 className="text-sm font-semibold mb-3 flex items-center gap-2"><User className="h-4 w-4" />Identitas pelanggan</h3>
                                            <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
                                                <div className="md:col-span-2">
                                                    <Label className="text-sm">Nama (KTP) <span className="text-status-down">*</span></Label>
                                                    <Input value={autoppName} onChange={e => setAutoppName(e.target.value.toUpperCase())} placeholder="SUMANI" className="font-mono" />
                                                </div>
                                                <div>
                                                    <Label className="text-sm">No HP</Label>
                                                    <Input value={autoppPhone} onChange={e => setAutoppPhone(e.target.value)} placeholder="0812..." className="font-mono" />
                                                </div>
                                                <div>
                                                    <Label className="text-sm">RT <span className="text-status-down">*</span></Label>
                                                    <Input value={autoppRt} onChange={e => setAutoppRt(e.target.value.replace(/\D/g, ''))} placeholder="4" inputMode="numeric" className="font-mono" />
                                                </div>
                                                <div>
                                                    <Label className="text-sm">RW <span className="text-status-down">*</span></Label>
                                                    <Input value={autoppRw} onChange={e => setAutoppRw(e.target.value.replace(/\D/g, ''))} placeholder="2" inputMode="numeric" className="font-mono" />
                                                </div>
                                                <div>
                                                    <Label className="text-sm">NIK (16 digit)</Label>
                                                    <Input value={autoppNik} onChange={e => setAutoppNik(e.target.value.replace(/\D/g, '').slice(0, 16))} placeholder="3578..." className="font-mono" maxLength={16} />
                                                </div>
                                                <div className="md:col-span-3">
                                                    <Label className="text-sm">Alamat instalasi</Label>
                                                    <Input value={autoppAddress} onChange={e => setAutoppAddress(e.target.value)} placeholder="Opsional — default: RT/RW · ODP · Area" />
                                                </div>
                                            </div>
                                        </section>

                                        {/* Location */}
                                        <section className="rounded-xl border border-border/70 bg-muted/20 p-4">
                                            <h3 className="text-sm font-semibold mb-3 flex items-center gap-2"><MapPin className="h-4 w-4" />Lokasi & infrastruktur</h3>
                                            <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                                                <div>
                                                    <Label className="text-sm">Area <span className="text-status-down">*</span></Label>
                                                    <Select value={autoppAreaId || '__none__'} onValueChange={(v) => setAutoppAreaId(v === '__none__' ? '' : v)}>
                                                        <SelectTrigger><SelectValue placeholder="Pilih area" /></SelectTrigger>
                                                        <SelectContent>
                                                            <SelectItem value="__none__">Pilih area</SelectItem>
                                                            {autoppAreas.map(a => <SelectItem key={a.id} value={String(a.id)}>{a.name}</SelectItem>)}
                                                        </SelectContent>
                                                    </Select>
                                                </div>
                                                <div>
                                                    <div className="flex items-center justify-between">
                                                        <Label className="text-sm">ODP <span className="text-status-down">*</span></Label>
                                                        <button type="button" className="text-xs text-primary hover:underline"
                                                            onClick={() => setAutoppOdpMode(m => m === 'manual' ? 'list' : 'manual')}>
                                                            {autoppOdpMode === 'manual' ? '↩ Pilih dari daftar' : '✎ Input manual'}
                                                        </button>
                                                    </div>
                                                    {autoppOdpMode === 'manual' ? (
                                                        <Input value={autoppOdpManual} onChange={e => setAutoppOdpManual(e.target.value)} placeholder="ODP-9.4 / 3.3 / nama bebas" className="font-mono" disabled={!autoppAreaId} />
                                                    ) : (
                                                        <Select value={autoppOdpId || '__none__'} onValueChange={(v) => setAutoppOdpId(v === '__none__' ? '' : v)} disabled={!autoppAreaId}>
                                                            <SelectTrigger><SelectValue placeholder={autoppAreaId ? "Pilih ODP" : "Pilih area dulu"} /></SelectTrigger>
                                                            <SelectContent>
                                                                <SelectItem value="__none__">Pilih ODP</SelectItem>
                                                                {autoppOdps.map(o => <SelectItem key={o.id} value={String(o.id)}>{o.name}</SelectItem>)}
                                                            </SelectContent>
                                                        </Select>
                                                    )}
                                                    {autoppOdpMode === 'list' && autoppOdps.length === 0 && autoppAreaId && <p className="text-xs text-status-degraded mt-1">Area tanpa ODP terdaftar — pakai input manual.</p>}
                                                </div>
                                                <div>
                                                    <Label className="text-sm">Router (MikroTik) <span className="text-status-down">*</span></Label>
                                                    <Select value={autoppRouterId || '__none__'} onValueChange={(v) => {
                                                        setAutoppRouterId(v === '__none__' ? '' : v);
                                                        setAutoppPackageId('');
                                                        setAutoppLocalAddr('');
                                                        setAutoppRemoteAddr('');
                                                    }}>
                                                        <SelectTrigger><SelectValue placeholder="Pilih router" /></SelectTrigger>
                                                        <SelectContent>
                                                            <SelectItem value="__none__">Pilih router</SelectItem>
                                                            {autoppRouters.map(r => <SelectItem key={r.id} value={String(r.id)}>{r.name}</SelectItem>)}
                                                        </SelectContent>
                                                    </Select>
                                                </div>
                                                <div>
                                                    <div className="flex items-center justify-between gap-2">
                                                        <Label className="text-sm">Paket Internet <span className="text-status-down">*</span></Label>
                                                        <button
                                                            type="button"
                                                            className="text-xs text-primary hover:underline disabled:opacity-50"
                                                            disabled={autoppPkgLoading}
                                                            onClick={() => syncPackagesFromScraper()}
                                                            title="Ambil ulang paket dari scraper eBilling + bersihkan duplikat"
                                                        >
                                                            {autoppPkgLoading ? 'Sync…' : 'Update dari scraper'}
                                                        </button>
                                                    </div>
                                                    <Select value={autoppPackageId || '__none__'} onValueChange={(v) => setAutoppPackageId(v === '__none__' ? '' : v)} disabled={!autoppRouterId || autoppPkgLoading}>
                                                        <SelectTrigger>
                                                            <SelectValue placeholder={
                                                                !autoppRouterId ? 'Pilih router dulu'
                                                                    : autoppPkgLoading ? 'Memuat paket…'
                                                                        : 'Pilih paket (pelanggan di router ini)'
                                                            } />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            <SelectItem value="__none__">Pilih paket</SelectItem>
                                                            {autoppPackages.map(p => (
                                                                <SelectItem key={p.id} value={String(p.id)}>
                                                                    {p.name}{p.mikrotik_profile ? ` [${p.mikrotik_profile}]` : ''}{p.price ? ` - ${formatIdr(Number(p.price))}` : ''}
                                                                </SelectItem>
                                                            ))}
                                                        </SelectContent>
                                                    </Select>
                                                    {autoppRouterId && !autoppPkgLoading && autoppPackages.length === 0 && (
                                                        <p className="text-xs text-status-degraded mt-1">
                                                            Belum ada paket terpakai di router ini. Klik &quot;Update dari scraper&quot; atau pastikan pelanggan sudah ter-map paket.
                                                        </p>
                                                    )}
                                                    {autoppRouterId && autoppPackages.length > 0 && (
                                                        <p className="text-[11px] text-muted-foreground mt-1">
                                                            {autoppPackages.length} paket (dari pelanggan router ini / eBilling — bukan dump semua router)
                                                        </p>
                                                    )}
                                                </div>
                                            </div>
                                        </section>

                                        {/* IP + top 5 remote (sorted highest first from MikroTik) */}
                                        <section className="rounded-xl border border-border/70 bg-muted/20 p-4 space-y-3">
                                            <div className="flex flex-wrap items-center justify-between gap-2">
                                                <h3 className="text-sm font-semibold">IP PPPoE</h3>
                                                <div className="flex items-center gap-2">
                                                    {autoppIpLoading && (
                                                        <span className="text-xs text-muted-foreground flex items-center gap-1">
                                                            <Loader2 className="h-3 w-3 animate-spin" /> Baca MikroTik…
                                                        </span>
                                                    )}
                                                    {autoppIpSuggest?.source && (
                                                        <Badge variant="outline" className="text-[10px]">
                                                            {autoppIpSuggest.source === 'mikrotik' ? 'live MikroTik (10m cache)' : 'DB eBilling (ringan)'}
                                                        </Badge>
                                                    )}
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        size="sm"
                                                        disabled={!autoppRouterId || autoppIpLoading}
                                                        title="Baca secret live dari MikroTik — hanya saat tombol ini (cache 10 menit)"
                                                        onClick={() => {
                                                            setAutoppLocalAddr('');
                                                            setAutoppRemoteAddr('');
                                                            const pkg = autoppPackages.find(p => String(p.id) === String(autoppPackageId));
                                                            loadAutoppIpSuggest(autoppRouterId, pkg?.mikrotik_profile, true);
                                                        }}
                                                    >
                                                        Refresh IP (MikroTik)
                                                    </Button>
                                                    {autoppIpSuggest?.suggested_next && autoppIpSuggest.suggested_available !== false && (
                                                        <Button type="button" variant="outline" size="sm" onClick={() => {
                                                            setAutoppRemoteAddr(autoppIpSuggest.suggested_next || '');
                                                            if (autoppIpSuggest.local_address) setAutoppLocalAddr(autoppIpSuggest.local_address);
                                                        }}>
                                                            Pakai saran bebas
                                                        </Button>
                                                    )}
                                                </div>
                                            </div>
                                            <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                                                <div>
                                                    <Label className="text-sm">Local Address</Label>
                                                    <Input value={autoppLocalAddr} onChange={e => setAutoppLocalAddr(e.target.value)} placeholder={autoppIpSuggest?.local_address || 'contoh 192.0.2.1'} className="font-mono" />
                                                </div>
                                                <div>
                                                    <Label className="text-sm">Remote Address</Label>
                                                    <Input value={autoppRemoteAddr} onChange={e => setAutoppRemoteAddr(e.target.value)} placeholder={autoppIpSuggest?.suggested_next || 'contoh 192.0.2.2'} className="font-mono" />
                                                    {autoppIpSuggest && (
                                                        <p className="text-xs mt-1">
                                                            <span className="text-muted-foreground">Remote tertinggi: </span>
                                                            <span className="font-mono">{autoppIpSuggest.last_remote_address || '—'}</span>
                                                            <span className="text-muted-foreground"> → saran: </span>
                                                            <span className={`font-mono ${autoppIpSuggest.suggested_available === false ? 'text-status-down' : 'text-status-up'}`}>
                                                                {autoppIpSuggest.suggested_next || '—'}
                                                            </span>
                                                            {autoppIpSuggest.suggested_next && (
                                                                <span className={`ml-1 ${autoppIpSuggest.suggested_available === false ? 'text-status-down' : 'text-status-up'}`}>
                                                                    {autoppIpSuggest.suggested_available === false ? '(terpakai)' : '(bebas)'}
                                                                </span>
                                                            )}
                                                        </p>
                                                    )}
                                                    {autoppIpSuggest?.note && (
                                                        <p className="text-[11px] text-muted-foreground mt-0.5">{autoppIpSuggest.note}</p>
                                                    )}
                                                </div>
                                            </div>
                                            {autoppRouterId && (
                                                <div className="rounded-lg border border-border bg-background/60 overflow-hidden">
                                                    <div className="px-3 py-2 text-xs font-medium text-muted-foreground border-b border-border bg-muted/40">
                                                        5 remote tertinggi (urut .x terbesar dulu)
                                                        {typeof autoppIpSuggest?.used_remote_count === 'number' && (
                                                            <span> · {autoppIpSuggest.used_remote_count} IP terpakai</span>
                                                        )}
                                                        {autoppIpSuggest?.source === 'db' && (
                                                            <span> · sumber DB · Refresh IP untuk cek live MT</span>
                                                        )}
                                                    </div>
                                                    {autoppIpLoading && !autoppIpSuggest?.recent_secrets?.length ? (
                                                        <p className="text-xs text-muted-foreground p-3 flex items-center gap-1">
                                                            <Loader2 className="h-3 w-3 animate-spin" /> Memuat secret dari MikroTik…
                                                        </p>
                                                    ) : !autoppIpSuggest?.recent_secrets?.length ? (
                                                        <p className="text-xs text-muted-foreground p-3">Tidak ada secret dengan remote IP (IPv4) di router ini.</p>
                                                    ) : (
                                                        <div className="overflow-x-auto">
                                                            <table className="w-full text-xs">
                                                                <thead>
                                                                    <tr className="text-left text-muted-foreground border-b border-border">
                                                                        <th className="px-3 py-1.5 font-medium">#</th>
                                                                        <th className="px-3 py-1.5 font-medium">User</th>
                                                                        <th className="px-3 py-1.5 font-medium">Local</th>
                                                                        <th className="px-3 py-1.5 font-medium">Remote</th>
                                                                        <th className="px-3 py-1.5 font-medium">Profile</th>
                                                                        <th className="px-3 py-1.5 font-medium w-16"></th>
                                                                    </tr>
                                                                </thead>
                                                                <tbody>
                                                                    {autoppIpSuggest.recent_secrets.map((s, idx) => (
                                                                        <tr key={`${s.pppoe_user}-${s.remote_address}-${idx}`} className="border-b border-border/50 last:border-0">
                                                                            <td className="px-3 py-1.5 text-muted-foreground">{idx + 1}</td>
                                                                            <td className="px-3 py-1.5 font-mono truncate max-w-[140px]" title={s.pppoe_user}>{s.pppoe_user || '—'}</td>
                                                                            <td className="px-3 py-1.5 font-mono">{s.local_address || '—'}</td>
                                                                            <td className="px-3 py-1.5 font-mono font-semibold">{s.remote_address || '—'}</td>
                                                                            <td className="px-3 py-1.5">{s.profile || '—'}</td>
                                                                            <td className="px-3 py-1.5 text-right">
                                                                                {s.remote_address && (
                                                                                    <button
                                                                                        type="button"
                                                                                        className="text-primary hover:underline"
                                                                                        title="Isi local dari baris ini; remote = octet+1 (tanpa cek bentrok penuh)"
                                                                                        onClick={() => {
                                                                                            const remoteAddress = s.remote_address;
                                                                                            if (!remoteAddress) return;
                                                                                            if (s.local_address) setAutoppLocalAddr(s.local_address);
                                                                                            // Prefer global free suggestion if same prefix as this row
                                                                                            if (
                                                                                                autoppIpSuggest.suggested_next
                                                                                                && autoppIpSuggest.suggested_available !== false
                                                                                                && autoppIpSuggest.last_remote_address === remoteAddress
                                                                                            ) {
                                                                                                setAutoppRemoteAddr(autoppIpSuggest.suggested_next);
                                                                                                return;
                                                                                            }
                                                                                            const parts = remoteAddress.split('.');
                                                                                            if (parts.length === 4) {
                                                                                                const n = parseInt(parts[3], 10);
                                                                                                if (!Number.isNaN(n) && n < 254) {
                                                                                                    setAutoppRemoteAddr(`${parts[0]}.${parts[1]}.${parts[2]}.${n + 1}`);
                                                                                                    return;
                                                                                                }
                                                                                            }
                                                                                            setAutoppRemoteAddr(remoteAddress);
                                                                                        }}
                                                                                    >
                                                                                        +1
                                                                                    </button>
                                                                                )}
                                                                            </td>
                                                                        </tr>
                                                                    ))}
                                                                </tbody>
                                                            </table>
                                                        </div>
                                                    )}
                                                </div>
                                            )}
                                        </section>

                                        <section className="rounded-xl border border-border/70 bg-muted/20 p-4 space-y-3">
                                            <h3 className="text-sm font-semibold">Password PPPoE</h3>
                                            <p className="text-xs text-muted-foreground">
                                                {psbPasswordHint.split('`').map((part, i) => (i % 2 === 1 ? <code key={i}>{part}</code> : part))}
                                            </p>
                                        </section>

                                        <div className="rounded-xl border border-status-info/30 bg-status-info-bg p-4 text-sm flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                                            <div>
                                                <div className="text-status-info font-medium mb-0.5">Preview PPPoE</div>
                                                <div className="text-xs text-muted-foreground">User</div>
                                                <code className="text-base font-mono text-foreground block">{autoppPreviewUser || '—'}</code>
                                                <div className="text-xs text-muted-foreground mt-1">Password</div>
                                                <code className="text-sm font-mono text-foreground block">disimpan terenkripsi &amp; diverifikasi router</code>
                                                <div className="text-[11px] text-muted-foreground mt-1 flex flex-wrap gap-2">
                                                    {autoppKtpFile && <span className="inline-flex items-center gap-1"><Paperclip className="h-3 w-3" aria-hidden="true" /> KTP</span>}
                                                    {autoppOdpFile && <span className="inline-flex items-center gap-1"><Paperclip className="h-3 w-3" aria-hidden="true" /> ODP</span>}
                                                    {autoppNik && <span>NIK {autoppNik}</span>}
                                                </div>
                                            </div>
                                            <div className="flex flex-col sm:items-end gap-2">
                                                <label className="flex items-center gap-2 text-sm">
                                                    <input type="checkbox" checked={autoppDryRun} onChange={e => setAutoppDryRun(e.target.checked)} className="rounded" />
                                                    <span>Dry run (preview only)</span>
                                                </label>
                                                <Button type="submit" disabled={autoppLoading} className="min-w-[200px]">
                                                    {autoppLoading ? <><Loader2 className="h-4 w-4 mr-2 animate-spin" />Memproses...</> : (autoppDryRun ? 'Preview' : 'Aktivasi sekarang')}
                                                </Button>
                                            </div>
                                        </div>
                                    </form>

                                    {autoppError && (
                                        <div className="mt-4 bg-destructive/15 text-destructive p-3 rounded-md border border-destructive/20 text-sm flex items-center gap-2">
                                            <AlertCircle className="h-4 w-4 shrink-0" aria-hidden="true" />
                                            <span>{autoppError}</span>
                                        </div>
                                    )}

                                    {autoppResult && (
                                        <div className="mt-4 space-y-2">
                                            <div className={`p-4 rounded-md border ${autoppResult.dry_run ? 'bg-status-degraded-bg border-status-degraded/30' : 'bg-status-up-bg border-status-up/30'}`}>
                                                <h4 className="font-semibold text-sm mb-2 flex items-center gap-2">
                                                    {autoppResult.dry_run ? <><Search className="h-4 w-4" aria-hidden="true" /> DRY RUN — Preview</> : <><CheckCircle2 className="h-4 w-4" aria-hidden="true" /> AKTIVASI BERHASIL</>}
                                                </h4>
                                                <div className="grid grid-cols-2 gap-2 text-sm font-mono">
                                                    <div><span className="text-muted-foreground">PPPoE User:</span> <span className="font-semibold">{autoppResult.pppoe_user}</span></div>
                                                    <div><span className="text-muted-foreground">PPPoE Password:</span> <span className="font-semibold">tersimpan terenkripsi &amp; diverifikasi router</span></div>
                                                    {(autoppResult.remote_address || autoppResult.customer?.pppoe_remote_address) && (
                                                        <div><span className="text-muted-foreground">Remote IP:</span> {autoppResult.remote_address || autoppResult.customer?.pppoe_remote_address}</div>
                                                    )}
                                                    {(autoppResult.local_address || autoppResult.customer?.pppoe_local_address) && (
                                                        <div><span className="text-muted-foreground">Local IP:</span> {autoppResult.local_address || autoppResult.customer?.pppoe_local_address}</div>
                                                    )}
                                                    {autoppResult.customer && (
                                                        <>
                                                            <div><span className="text-muted-foreground">Customer ID:</span> {autoppResult.customer.id}</div>
                                                            <div><span className="text-muted-foreground">Status:</span> {autoppResult.customer.status}</div>
                                                        </>
                                                    )}
                                                    {autoppResult.radius && (
                                                        <div className="col-span-2">
                                                            <span className="text-muted-foreground">RADIUS:</span> {autoppResult.radius.synced ? <span className="inline-flex items-center gap-1"><CheckCircle2 className="h-3 w-3 text-status-up" aria-hidden="true" /> Synced</span> : <span className="inline-flex items-center gap-1"><AlertCircle className="h-3 w-3 text-status-degraded" aria-hidden="true" /> {autoppResult.radius.reason}</span>}
                                                        </div>
                                                    )}
                                                    {autoppResult.mikrotik && (
                                                        <div className="col-span-2">
                                                            <span className="text-muted-foreground">Mikrotik:</span> {(autoppResult.mikrotik.success || autoppResult.mikrotik.pushed) ? <span className="inline-flex items-center gap-1"><CheckCircle2 className="h-3 w-3 text-status-up" aria-hidden="true" /> Secret pushed</span> : <span className="inline-flex items-center gap-1"><AlertCircle className="h-3 w-3 text-status-degraded" aria-hidden="true" /> {autoppResult.mikrotik.reason || 'gagal'}</span>}
                                                        </div>
                                                    )}
                                                </div>
                                                {autoppResult.note && <p className="text-xs text-muted-foreground mt-2">{autoppResult.note}</p>}
                                            </div>

                                            {/* Vendor branch CTAs (only on real activation) */}
                                            {!autoppResult.dry_run && autoppResult.next_step === 'zte_provision' && (
                                                <div className="p-3 rounded-md border border-status-info/30 bg-status-info-bg text-sm flex items-center justify-between gap-3">
                                                    <span>PPPoE ZTE selesai. Lanjut provisioning ONU di OLT.</span>
                                                    <Button type="button" size="sm" onClick={goToZteProvision}><ArrowRight className="h-4 w-4" aria-hidden="true" /> Lanjut Provisioning ZTE</Button>
                                                </div>
                                            )}
	                                            {!autoppResult.dry_run && autoppResult.next_step === 'fiberhome_hint' && (
	                                                    <div className="p-3 rounded-md border border-status-info/30 bg-status-info-bg text-sm flex items-center justify-between gap-3">
	                                                    <span>PPPoE selesai. Lanjut provisioning FiberHome untuk VLAN + SN.</span>
	                                                    <Button type="button" size="sm" onClick={goToFiberhomeProvision}><ArrowRight className="h-4 w-4" aria-hidden="true" /> Lanjut Provisioning FiberHome</Button>
	                                                </div>
	                                            )}
                                            {!autoppResult.dry_run && autoppResult.next_step === 'done' && (
                                                <div className="p-3 rounded-md border border-status-up/30 bg-status-up-bg text-sm">
                                                <CheckCircle2 className="inline-block h-4 w-4 mr-1 text-status-up" aria-hidden="true" /> Selesai. PPPoE sudah masuk Mikrotik. Teknisi tinggal setel router di sisi pelanggan.
                                                </div>
                                            )}
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        </div>
                    )}


                    {activeTab === 'activate' && (
                        <div className="space-y-4">

                            {/* Step 1: Upload & Detect */}
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-lg flex items-center gap-2">
                                        <Camera className="h-5 w-5" />
                                        Langkah 1: Ambil Foto & Deteksi SN ONU
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    {/* OLT Selector */}
                                    <div>
                                        <Label className="text-sm font-medium">Pilih OLT</Label>
                                        <p className="text-xs text-muted-foreground mb-1.5">Pilih perangkat OLT ZTE C300</p>
                                        <Select value={oltId || '__none__'} onValueChange={(v) => setOltId(v === '__none__' ? '' : v)}>
                                            <SelectTrigger><SelectValue placeholder="-- Pilih OLT --" /></SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="__none__">-- Pilih OLT --</SelectItem>
                                                {olts.map(olt => (
                                                    <SelectItem key={olt.id} value={String(olt.id)}>{olt.name}</SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    {/* Photo Upload */}
                                    <div>
                                        <Label className="text-sm font-medium">Foto Label ONU</Label>
                                        <p className="text-xs text-muted-foreground mb-1.5">Ambil foto dekat label SN di belakang/atas ONU</p>
                                        <input type="file" accept="image/*" capture="environment" ref={fileInputRef} onChange={handleUpload} className="hidden" />
                                        <div className="flex items-center gap-3">
                                            <Button variant="outline" onClick={() => fileInputRef.current?.click()} disabled={loading}>
                                                {loading ? <><Loader2 className="h-4 w-4 mr-2 animate-spin" />Membaca...</> : <><Camera className="h-4 w-4 mr-2" />Ambil Foto / Upload</>}
                                            </Button>
                                            {preview && <img src={preview} alt="Preview" className="h-12 w-12 rounded object-cover border" />}
                                        </div>
                                    </div>

                                    {/* SN Input */}
                                    <div>
                                        <Label className="text-sm font-medium">Serial Number (SN) ONU</Label>
                                        <p className="text-xs text-muted-foreground mb-1.5">12-16 karakter, contoh: HWTC0000A001. Bisa ketik manual jika OCR gagal.</p>
                                        <Input value={sn} onChange={e => setSn(e.target.value)} placeholder="HWTC0000A001" className="font-mono" />
                                    </div>

                                    {/* Detect Button */}
                                    <div className="flex gap-2">
                                        <Button onClick={handleDetect} disabled={loading || !oltId || !sn} className="flex-1">
                                            {loading ? <><Loader2 className="h-4 w-4 mr-2 animate-spin" />Mendeteksi...</> : <><Search className="h-4 w-4 mr-2" />Deteksi ONU</>}
                                        </Button>
                                        <Button variant="outline" onClick={handleListUncfg} disabled={loading || !oltId} title="Lihat semua ONU uncfg">
                                            Daftar
                                        </Button>
                                        <Button variant="outline" onClick={handleTestConnection} disabled={testingConnection || !oltId} title="Tes koneksi SSH ke OLT">
                                            {testingConnection ? <Loader2 className="h-4 w-4 animate-spin" /> : 'Tes'}
                                        </Button>
                                        <Button variant="outline" onClick={() => {
                                            setCredUsername('');
                                            setCredPassword('');
                                            setShowCredModal(true);
                                        }} disabled={!oltId} title="Update username/password OLT">
                                            Kredensial
                                        </Button>
                                    </div>

                                    {/* Connection Status Alert */}
                                    {connectionStatus && !connectionStatus.ssh_login && (
                                        <div className={`rounded-lg p-3 text-sm ${
                                            connectionStatus.error_type === 'auth_failed'
                                                ? 'bg-status-down-bg border border-status-down/30 text-foreground'
                                                : 'bg-status-degraded-bg border border-status-degraded/30 text-foreground'
                                        }`}>
                                            <p className="font-semibold flex items-center gap-1.5">
                                                <AlertCircle className="h-4 w-4" />
                                                {connectionStatus.error_type === 'auth_failed' ? 'SSH Login Gagal — Kredensial Salah' : 'Koneksi SSH Gagal'}
                                            </p>
                                            <p className="mt-1">{connectionStatus.hint || 'Koneksi ke OLT gagal. Cek jaringan dan kredensial.'}</p>
                                            {connectionStatus.error_type === 'auth_failed' && (
                                                <Button variant="outline" size="sm" className="mt-2" onClick={() => setShowCredModal(true)}>
                                                    Update Kredensial OLT
                                                </Button>
                                            )}
                                        </div>
                                    )}
                                    {connectionStatus && connectionStatus.ssh_login && (
                                        <div className="bg-status-up-bg border border-status-up/30 rounded-lg p-3 text-sm text-foreground">
                                            <p className="font-semibold flex items-center gap-1.5">
                                                <CheckCircle2 className="h-4 w-4" />
                                                Koneksi SSH OK — {connectionStatus.uncfg_count} ONU uncfg tersedia
                                            </p>
                                        </div>
                                    )}
                                </CardContent>
                            </Card>

                            {/* Step 2: Confirm & Activate */}
                            {step === 'confirm' && result && (
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="text-lg flex items-center gap-2">
                                            <CheckCircle2 className="h-5 w-5 text-status-info" />
                                            Langkah 2: Konfirmasi & Aktifkan
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent className="space-y-4">
                                        {/* Info Box */}
                                        <div className="bg-status-info-bg border border-status-info/30 rounded-lg p-4 space-y-2">
                                            <p className="font-semibold text-foreground">ONU Terdeteksi!</p>
                                            <div className="grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                                                <span className="text-muted-foreground">Port PON:</span>
                                                <span className="font-mono font-medium">{selectedPort || result.port}</span>
                                                <span className="text-muted-foreground">ONU ID:</span>
                                                <span className="font-mono font-medium">{result.onu_id}</span>
                                                <span className="text-muted-foreground">VLAN:</span>
                                                <span className="font-mono font-medium">{result.vlan}</span>
                                                <span className="text-muted-foreground">SN:</span>
                                                <span className="font-mono font-medium">{sn}</span>
                                            </div>

                                            {/* Multi-port selector */}
                                            {portChoices.length > 1 && (
                                                <div className="mt-3 space-y-2">
                                                    <p className="text-sm font-medium text-status-info">Pilih Port PON yang benar:</p>
                                                    {portChoices.map((p: any) => (
                                                        <label key={p.port} className={`flex items-center gap-2 p-2 rounded border cursor-pointer transition-colors ${selectedPort === p.port ? 'border-status-info bg-status-info-bg' : 'border-border hover:bg-muted'}`}>
                                                            <input type="radio" name="portChoice" checked={selectedPort === p.port} onChange={() => { setSelectedPort(p.port); setResult(p); }} className="accent-primary" />
                                                            <span className="font-mono text-sm">Port {p.port} <ArrowRight className="inline-block h-3 w-3" aria-hidden="true" /> VLAN {p.vlan} (ONU ID: {p.onu_id}){p.already_registered ? ' [sudah terdaftar]' : ''}</span>
                                                        </label>
                                                    ))}
                                                </div>
                                            )}
                                        </div>

                                        {/* PPPoE Username */}
                                        <div>
                                            <Label className="text-sm font-medium">Username PPPoE</Label>
                                            <p className="text-xs text-muted-foreground mb-1.5">Nama pelanggan untuk koneksi internet</p>
                                            <Input value={name} onChange={e => setName(e.target.value)} placeholder="nama_pelanggan" />
                                        </div>

                                        {/* PPPoE Password */}
                                        <div>
                                            <Label className="text-sm font-medium">Password PPPoE</Label>
                                            <p className="text-xs text-muted-foreground mb-1.5">Wajib diisi. Password dikirim ke OLT hanya setelah validasi.</p>
                                            <Input value={password} onChange={e => setPassword(e.target.value)} placeholder="Masukkan password PPPoE" type="text" />
                                        </div>

                                        {/* Action Buttons */}
                                        <div className="flex gap-3">
                                            <Button variant="outline" onClick={cancelConfirm} className="flex-1"><ArrowLeft className="h-4 w-4" aria-hidden="true" /> Kembali</Button>
                                            <Button type="button" onClick={() => setZteProvisionConfirmOpen(true)} disabled={loading} className="flex-1">
                                                {loading ? <><Loader2 className="h-4 w-4 mr-2 animate-spin" />Memproses...</> : <><Zap className="h-4 w-4 mr-2" />Aktifkan ONU Sekarang</>}
                                            </Button>
                                        </div>
                                        <ConfirmDestructive
                                            open={zteProvisionConfirmOpen}
                                            onOpenChange={setZteProvisionConfirmOpen}
                                            title="Konfirmasi provisioning ZTE C300"
                                            description="Tindakan ini menulis konfigurasi ONU ke OLT. Pastikan port, ONU ID, SN, VLAN, dan kredensial PPPoE sudah benar."
                                            confirmationLabel="Saya sudah memeriksa target dan memahami bahwa provisioning mengubah konfigurasi OLT."
                                            typedConfirmation="PROVISION OLT"
                                            typedConfirmationLabel="Ketik PROVISION OLT untuk mengizinkan write."
                                            confirmText="Provision sekarang"
                                            onConfirm={(typed) => {
                                                setZteProvisionConfirmOpen(false);
                                                void handleProvision(typed);
                                            }}
                                            isLoading={loading}
                                        >
                                            <div className="grid grid-cols-2 gap-x-4 gap-y-1 rounded-lg border bg-muted/30 p-3 text-sm">
                                                <span className="text-muted-foreground">Port PON</span><span className="font-mono">{selectedPort || result.port}</span>
                                                <span className="text-muted-foreground">ONU ID</span><span className="font-mono">{result.onu_id}</span>
                                                <span className="text-muted-foreground">VLAN</span><span className="font-mono">{result.vlan}</span>
                                                <span className="text-muted-foreground">SN</span><span className="font-mono">{sn}</span>
                                                <span className="text-muted-foreground">PPPoE</span><span className="font-mono">{name || '-'}</span>
                                            </div>
                                        </ConfirmDestructive>
                                    </CardContent>
                                </Card>
                            )}

                            {/* Step 3: Provisioning */}
                            {step === 'provisioning' && (
                                <Card className="border-status-degraded/30">
                                    <CardContent className="py-8 text-center space-y-2">
                                        <Loader2 className="h-8 w-8 animate-spin mx-auto text-status-degraded" />
                                        <p className="text-lg font-semibold">Sedang Mengaktifkan...</p>
                                        <p className="text-sm text-muted-foreground">Sedang mengirim perintah ke OLT. Tunggu beberapa detik...</p>
                                    </CardContent>
                                </Card>
                            )}

                            {/* Step 4: Done */}
                            {step === 'done' && (
                                <Card className="border-status-up/30">
                                    <CardContent className="py-6 space-y-4">
                                        <div className="text-center space-y-2">
                                            <CheckCircle2 className="h-10 w-10 mx-auto text-status-up" />
                                            <p className="text-xl font-semibold text-status-up">ONU Berhasil Diaktifkan!</p>
                                        </div>
                                        <div className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm bg-status-up-bg rounded-lg p-4">
                                            <span className="text-muted-foreground">Port PON:</span>
                                            <span className="font-mono font-medium">{result?.port || selectedPort}</span>
                                            <span className="text-muted-foreground">ONU ID:</span>
                                            <span className="font-mono font-medium">{result?.onu_id}</span>
                                            <span className="text-muted-foreground">VLAN:</span>
                                            <span className="font-mono font-medium">{result?.vlan}</span>
                                            <span className="text-muted-foreground">Status:</span>
                                            <span className="font-mono font-medium">{result?.status || 'Online'}</span>
                                            {result?.rx_power && (<><span className="text-muted-foreground">Rx Power:</span><span className="font-mono font-medium">{result.rx_power} dBm</span></>)}
                                        </div>
                                        <MigrationLinkPanel
                                            link={result?.migration_link}
                                            candidates={result?.migration_candidates}
                                            warning={result?.migration_warning}
                                            context={result?.onu_id ? {
                                                olt_id: parseInt(oltId),
                                                sn: result?.sn || sn,
                                                port: result?.port || selectedPort,
                                                onu_id: Number(result.onu_id),
                                                name: result?.name || name,
                                            } : null}
                                        />
                                        <Button variant="outline" onClick={reset} className="w-full"><ArrowLeft className="h-4 w-4" aria-hidden="true" /> Aktivasi ONU Lain</Button>
                                    </CardContent>
                                </Card>
                            )}

                            {/* Console Log - Activate */}
                            <ConsoleLog log={log} onCopy={copyLog} onReset={() => setLog([])} maxHeight="h-72" />
                        </div>
                    )}

                    {/* ===== RECONFIGURE TAB ===== */}
                    {activeTab === 'reconfigure' && (
                        <div className="space-y-4">
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-lg flex items-center gap-2">
                                        <RotateCcw className="h-5 w-5" />
                                        Rekonfigurasi ONU
                                    </CardTitle>
                                    <p className="text-sm text-muted-foreground">Ganti ONU lama dengan ONU baru di slot yang sama. ONU lama akan dihapus, ONU baru didaftarkan ulang.</p>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    {/* OLT Selector */}
                                    <div>
                                        <Label className="text-sm font-medium">Pilih OLT</Label>
                                        <Select value={reconfigOltId || '__none__'} onValueChange={(v) => setReconfigOltId(v === '__none__' ? '' : v)}>
                                            <SelectTrigger><SelectValue placeholder="-- Pilih OLT --" /></SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="__none__">-- Pilih OLT --</SelectItem>
                                                {olts.map(olt => (
                                                    <SelectItem key={olt.id} value={String(olt.id)}>{olt.name}</SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    {/* Port PON */}
                                    <div>
                                        <Label className="text-sm font-medium">Port PON</Label>
                                        <p className="text-xs text-muted-foreground mb-1.5">Port fiber di OLT tempat ONU terhubung</p>
                                        <Select value={reconfigPort || '__none__'} onValueChange={(v) => setReconfigPort(v === '__none__' ? '' : v)}>
                                            <SelectTrigger><SelectValue placeholder="-- Pilih Port PON --" /></SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="__none__">-- Pilih Port PON --</SelectItem>
                                                {portOptions.map(p => (
                                                    <SelectItem key={p.value} value={p.value}>{p.label}</SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    {/* ONU ID */}
                                    <div>
                                        <Label className="text-sm font-medium">ONU ID (1-128)</Label>
                                        <p className="text-xs text-muted-foreground mb-1.5">Nomor slot ONU di port PON tersebut</p>
                                        <Input type="number" min={1} max={128} value={reconfigOnuId} onChange={e => setReconfigOnuId(e.target.value)} placeholder="1" />
                                    </div>

                                    {/* SN Baru */}
                                    <div>
                                        <Label className="text-sm font-medium">SN ONU Baru</Label>
                                        <p className="text-xs text-muted-foreground mb-1.5">Serial Number ONU pengganti</p>
                                        <Input value={reconfigSn} onChange={e => setReconfigSn(e.target.value)} placeholder="HWTC0000A001" className="font-mono" />
                                    </div>

                                    {/* Username PPPoE */}
                                    <div>
                                        <Label className="text-sm font-medium">Username PPPoE</Label>
                                        <p className="text-xs text-muted-foreground mb-1.5">Nama pelanggan untuk koneksi internet</p>
                                        <Input value={reconfigName} onChange={e => setReconfigName(e.target.value)} placeholder="nama_pelanggan" />
                                    </div>

                                    {/* Password PPPoE */}
                                    <div>
                                        <Label className="text-sm font-medium">Password PPPoE</Label>
                                        <p className="text-xs text-muted-foreground mb-1.5">Wajib diisi. Password tidak ditampilkan pada log command.</p>
                                        <Input value={reconfigPassword} onChange={e => setReconfigPassword(e.target.value)} placeholder="Masukkan password PPPoE" type="text" />
                                    </div>

                                    {/* Reconfigure Button */}
                                    <Button type="button" onClick={() => setReconfigConfirmOpen(true)} disabled={reconfigLoading || !reconfigOltId || !reconfigPort || !reconfigOnuId || !reconfigSn || !reconfigName || !reconfigPassword.trim()} className="w-full">
                                        {reconfigLoading ? <><Loader2 className="h-4 w-4 mr-2 animate-spin" />Memproses...</> : <><RotateCcw className="h-4 w-4 mr-2" />Rekonfigurasi ONU</>}
                                    </Button>
                                    <ConfirmDestructive
                                        open={reconfigConfirmOpen}
                                        onOpenChange={setReconfigConfirmOpen}
                                        title="Konfirmasi rekonfigurasi ONU"
                                        description="ONU lama akan dihapus dari slot dan ONU baru didaftarkan pada target yang dipilih."
                                        confirmationLabel="Saya sudah memeriksa pelanggan, port, ONU ID, SN, VLAN, dan password."
                                        typedConfirmation="RECONFIGURE OLT"
                                        typedConfirmationLabel="Ketik RECONFIGURE OLT untuk mengizinkan write."
                                        confirmText="Rekonfigurasi sekarang"
                                        onConfirm={(typed) => {
                                            setReconfigConfirmOpen(false);
                                            void handleReconfigure(typed);
                                        }}
                                        isLoading={reconfigLoading}
                                    >
                                        <div className="grid grid-cols-2 gap-x-4 gap-y-1 rounded-lg border bg-muted/30 p-3 text-sm">
                                            <span className="text-muted-foreground">OLT / Port</span><span className="font-mono">{reconfigOltId || '-'} / {reconfigPort || '-'}</span>
                                            <span className="text-muted-foreground">ONU ID</span><span className="font-mono">{reconfigOnuId}</span>
                                            <span className="text-muted-foreground">SN baru</span><span className="font-mono">{reconfigSn || '-'}</span>
                                            <span className="text-muted-foreground">PPPoE</span><span className="font-mono">{reconfigName || '-'}</span>
                                        </div>
                                    </ConfirmDestructive>

                                    {/* Reconfigure Result */}
                                    {reconfigResult?.success && (
                                        <div className="bg-status-up-bg border border-status-up/30 rounded-lg p-4">
                                            <p className="font-semibold text-status-up flex items-center gap-2"><CheckCircle2 className="h-4 w-4" /> Rekonfigurasi Berhasil!</p>
                                            {reconfigResult.status && <p className="text-sm mt-1">Status: {reconfigResult.status}</p>}
                                            {reconfigResult.rx_power && <p className="text-sm">Rx Power: {reconfigResult.rx_power} dBm</p>}
                                        </div>
                                    )}
                                </CardContent>
                            </Card>

                            {/* Console Log - Reconfigure */}
                            <ConsoleLog log={reconfigLog} onCopy={copyReconfigLog} onReset={() => setReconfigLog([])} maxHeight="h-72" />
                        </div>
                    )}

                    {/* ===== FIBERHOME PROVISION TAB ===== */}
                    {activeTab === 'fiberhome' && (
                        <div className="space-y-4">
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-lg flex items-center gap-2">
                                        <Cable className="h-5 w-5" />
                                        Aktivasi ONU FiberHome (TR-069 VLAN {tr069Vlan})
                                    </CardTitle>
                                    <p className="text-sm text-muted-foreground">
                                        Provisioning khusus ONU FiberHome. Pakai <span className="font-mono">type ALL</span> (bukan ZTE-F660),
                                        tambah <span className="font-mono">service-port 2 vlan {tr069Vlan}</span> + <span className="font-mono">veip_1 vlan {tr069Vlan}</span> + <span className="font-mono">ip-host 1</span>.
                                        ACS URL auto-push via Mikrotik DHCP Option 43.
                                        {' '}Path aktif: <span className="font-mono">MikroTik RA / Randuagung · TR-069 VLAN 69</span> (VLAN 68 Migrasi sudah tidak dipakai).
                                    </p>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    <div>
                                        <Label className="text-sm font-medium">Jenis Data</Label>
                                        <div className="mt-1.5 grid grid-cols-2 overflow-hidden rounded-md border">
                                            <button
                                                type="button"
                                                onClick={() => setFhPurpose('psb_new')}
                                                className={`min-h-10 px-3 text-sm font-medium ${fhPurpose === 'psb_new' ? 'bg-primary text-primary-foreground' : 'bg-background hover:bg-muted'}`}
                                            >
                                                PSB Baru
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => setFhPurpose('migration')}
                                                className={`min-h-10 border-l px-3 text-sm font-medium ${fhPurpose === 'migration' ? 'bg-primary text-primary-foreground' : 'bg-background hover:bg-muted'}`}
                                            >
                                                Migrasi
                                            </button>
                                        </div>
                                        <p className="mt-1.5 text-xs text-muted-foreground">
                                            {fhPurpose === 'psb_new'
                                                ? 'Pelanggan baru; hasil masuk PSB WA/KPI.'
                                                : 'Pelanggan existing; nama ONU harus sama dengan PPPoE customer eBilling.'}
                                        </p>
                                    </div>

                                    {/* OLT Selector */}
                                    <div>
                                        <Label className="text-sm font-medium">Pilih OLT</Label>
                                        <Select value={fhOltId || '__none__'} onValueChange={(v) => setFhOltId(v === '__none__' ? '' : v)}>
                                            <SelectTrigger><SelectValue placeholder="-- Pilih OLT --" /></SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="__none__">-- Pilih OLT --</SelectItem>
                                                {olts.map(olt => (
                                                    <SelectItem key={olt.id} value={String(olt.id)}>{olt.name}</SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    {/* MikroTik PPPoE / TR-069 path */}
                                    <div>
                                        <Label className="text-sm font-medium">Pilih MikroTik (PPPoE / TR-069)</Label>
                                        <p className="text-xs text-muted-foreground mb-1.5">
                                            Pilih router sesuai OLT C300: <b>RA / Randuagung</b> atau <b>Krian</b> · TR-069 <b>VLAN 69</b>.
                                            {' '}(VLAN 68 hanya jalur migrasi lama dan tidak dipakai untuk PSB.)
                                        </p>
                                        <Select
                                            value={fhMikrotik}
                                            onValueChange={(v) => {
                                                setFhMikrotik(v);
                                                const opt = fhMikrotikOptions.find(o => o.key === v);
                                                if (opt) setTr069Vlan(opt.tr069_vlan);
                                            }}
                                        >
                                            <SelectTrigger>
                                                <SelectValue placeholder="-- Pilih MikroTik --" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {fhMikrotikOptions.map(opt => (
                                                    <SelectItem key={opt.key} value={opt.key}>
                                                        {opt.label} — VLAN {opt.tr069_vlan}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        {fhMikrotik && (
                                            <p className="mt-1.5 text-xs text-status-up">
                                                {fhMikrotikOptions.find(o => o.key === fhMikrotik)?.hint
                                                    || `TR-069 VLAN ${tr069Vlan}`}
                                            </p>
                                        )}
                                    </div>

                                    {/* SN + Deteksi */}
                                    <div>
                                        <Label className="text-sm font-medium">SN ONU FiberHome</Label>
                                        <p className="text-xs text-muted-foreground mb-1.5">12-16 karakter, contoh: <span className="font-mono">FHTT0000A001</span>. Isi SN → klik <b>Deteksi</b> untuk auto-fill Port, ONU ID, VLAN.</p>
                                        <div className="flex gap-2">
                                            <Input
                                                value={fhSn}
                                                onChange={e => setFhSn(e.target.value.toUpperCase())}
                                                onBlur={() => lookupFhCustomerBySn(fhSn)}
                                                placeholder="FHTT0000A001"
                                                className="font-mono flex-1"
                                            />
                                            <Popover open={fhUncfgPopover} onOpenChange={setFhUncfgPopover}>
                                                <PopoverTrigger asChild>
                                                    <Button variant="outline" onClick={handleFiberhomeListUncfg} disabled={fhUncfgLoading || !fhOltId} title="Lihat ONU yang belum di-config (pilih dari sini)">
                                                        {fhUncfgLoading ? <Loader2 className="h-4 w-4 animate-spin" /> : <><User className="h-4 w-4 mr-1" />Pilih SN</>}
                                                    </Button>
                                                </PopoverTrigger>
                                                <PopoverContent className="w-[--radix-popover-trigger-width] min-w-[320px] max-w-[500px] p-0" align="end">
                                                    <div className="p-3 border-b">
                                                        <p className="text-sm font-medium">Pilih SN dari ONU Uncfg</p>
                                                        <p className="text-xs text-muted-foreground">ONU yang baru terdeteksi di OLT tapi belum dikonfigurasi</p>
                                                    </div>
                                                    <div className="max-h-[300px] overflow-y-auto">
                                                        {fhUncfg.length === 0 ? (
                                                            <p className="p-4 text-sm text-muted-foreground text-center">
                                                                {fhUncfgLoading ? 'Loading...' : 'Tidak ada ONU uncfg'}
                                                            </p>
                                                        ) : (() => {
                                                        // Group by port to show next_onu_id context
                                                        const portInfo: Record<string, {next: number, count: number}> = {};
                                                        (fhResult?.port_stats || []).forEach((p: any) => {
                                                            portInfo[p.port] = {next: p.next_onu_id, count: p.configured_count};
                                                        });
                                                        return fhUncfg.map((u, i) => {
                                                            const info = portInfo[u.port];
                                                            return (
                                                                <button
                                                                    key={i}
                                                                    onClick={() => handleFiberhomeSelectUncfg(u)}
                                                                    className="w-full text-left p-3 border-b last:border-0 hover:bg-muted transition-colors"
                                                                >
                                                                    <div className="flex items-center justify-between gap-2">
                                                                        <span className="font-mono text-xs text-primary font-medium">{u.sn}</span>
                                                                        <span className="text-xs text-muted-foreground">{u.port}:{u.onu_id}</span>
                                                                    </div>
                                                                    {info && (
                                                                        <div className="mt-1 text-[10px] text-muted-foreground">
                                                                            {info.count} ONU terdaftar → next ID: <span className="font-mono font-semibold text-foreground">{info.next}</span>
                                                                        </div>
                                                                    )}
                                                                </button>
                                                            );
                                                        });
                                                    })()}
                                                    </div>
                                                </PopoverContent>
                                            </Popover>
                                            <Button variant="outline" onClick={handleFiberhomeDetect} disabled={fhDetecting || !fhOltId || !fhSn} title="Auto-deteksi port, ONU ID, VLAN dari SN">
                                                {fhDetecting ? <Loader2 className="h-4 w-4 animate-spin" /> : <><Search className="h-4 w-4 mr-1" />Deteksi</>}
                                            </Button>
                                        </div>
                                    </div>

                                    {/* Info box: hasil deteksi */}
                                    {fhVlan !== null && (
                                        <div className="bg-status-info-bg border border-status-info/30 rounded-lg p-3 text-sm">
                                            <p className="font-semibold text-foreground flex items-center gap-1.5"><Info className="h-4 w-4" />Hasil Deteksi</p>
                                            <div className="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 text-xs">
                                                <span className="opacity-70">Port PON:</span><span className="font-mono">{fhPort || '—'}</span>
                                                <span className="opacity-70">ONU ID:</span><span className="font-mono">{fhOnuId || '—'}</span>
                                                <span className="opacity-70">VLAN internet:</span><span className="font-mono">{fhVlan}</span>
                                                <span className="opacity-70">MikroTik:</span>
                                                <span className="font-mono">
                                                    {fhMikrotikOptions.find(o => o.key === fhMikrotik)?.label || fhMikrotik || '—'}
                                                </span>
                                                <span className="opacity-70">VLAN TR-069:</span><span className="font-mono">{tr069Vlan}</span>
                                            </div>
                                        </div>
                                    )}

                                    {/* Port PON */}
                                    <div>
                                        <Label className="text-sm font-medium">Port PON</Label>
                                        <p className="text-xs text-muted-foreground mb-1.5">Port fiber di OLT tempat ONU terhubung</p>
                                        <Select
                                            value={fhPort}
                                            onValueChange={async (port) => {
                                                setFhPort(port);
                                                if (!port || !fhOltId) return;
                                                addFhLog('phase', `Cek next ONU ID untuk port ${port}...`);
                                                const result = await checkFiberhomePort(port);
                                                if (result) {
                                                    setFhVlan(result.vlan);
                                                    setFhOnuId(String(result.nextId));
                                                    addFhLog('success', `Port ${port}: ONU ID ${result.nextId}, VLAN ${result.vlan}`);
                                                }
                                            }}
                                        >
                                            <SelectTrigger><SelectValue placeholder="-- Pilih Port PON --" /></SelectTrigger>
                                            <SelectContent>
                                                {portOptions.map(p => (
                                                    <SelectItem key={p.value} value={p.value}>{p.label}</SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    {/* ONU ID */}
                                    <div>
                                        <Label className="text-sm font-medium">ONU ID (1-128)</Label>
                                        <p className="text-xs text-muted-foreground mb-1.5">Nomor slot ONU di port PON tersebut</p>
                                        <Input type="number" min={1} max={128} value={fhOnuId} onChange={e => setFhOnuId(e.target.value)} placeholder="1" />
                                    </div>

                                    {/* Name */}
                                    <div>
                                        <Label className="text-sm font-medium">Nama ONU / Tag GenieACS</Label>
                                        <p className="text-xs text-muted-foreground mb-1.5">
                                            Dipakai sebagai `name` di OLT dan otomatis jadi <strong>tag GenieACS</strong> setelah provision — tidak perlu input manual di panel ACS.
                                        </p>
                                        <div className="flex items-center gap-2">
                                            <Input
                                                value={fhName}
                                                onChange={e => { setFhName(e.target.value); setFhNameSource(null); }}
                                                placeholder="nama_pelanggan"
                                                className="flex-1"
                                            />
                                            {fhNameSource && (
                                                <Badge variant="outline" className="shrink-0 text-[10px]">
                                                    {fhNameSourceLabel(fhNameSource)}
                                                </Badge>
                                            )}
                                        </div>
                                    </div>

                                    {/* Paket */}
                                    <div>
                                        <Label className="text-sm font-medium">Paket Bandwidth</Label>
                                        <p className="text-xs text-muted-foreground mb-1.5">Format: angka + M (25M, 50M, 100M, 200M, dst). Mapped ke traffic-limit UP/DW.</p>
                                        <Input value={fhPaket} onChange={e => setFhPaket(e.target.value)} placeholder="100M" className="font-mono" />
                                    </div>

                                    {/* Action buttons */}
                                    <div className="flex gap-3">
                                        <Button
                                            variant="outline"
                                            onClick={() => handleFiberhomeProvision(true)}
                                            disabled={fhLoading || !fhOltId || !fhSn || !fhPort || !fhOnuId || !fhName || !fhMikrotik}
                                            className="flex-1"
                                        >
                                            {fhLoading && fhDryRun ? <><Loader2 className="h-4 w-4 mr-2 animate-spin" />Preview...</> : <><Info className="h-4 w-4 mr-2" />Dry Run (Preview)</>}
                                        </Button>
                                        <Button
                                            onClick={() => setFhProvisionConfirmOpen(true)}
                                            disabled={fhLoading || !fhOltId || !fhSn || !fhPort || !fhOnuId || !fhName || !fhMikrotik}
                                            className="flex-1"
                                        >
                                            {fhLoading && !fhDryRun ? <><Loader2 className="h-4 w-4 mr-2 animate-spin" />Provisioning...</> : <><Zap className="h-4 w-4 mr-2" />Provision Now</>}
                                        </Button>
                                    </div>
                                    {!fhMikrotik && (
                                        <p className="text-xs text-status-degraded">Pilih MikroTik dulu sebelum Dry Run / Provision.</p>
                                    )}

                                    <ConfirmDestructive
                                        open={fhProvisionConfirmOpen}
                                        onOpenChange={setFhProvisionConfirmOpen}
                                        title="Konfirmasi provisioning FiberHome"
                                        description="Tindakan ini akan menulis konfigurasi ONU ke OLT. Periksa target dan data berikut sebelum melanjutkan."
                                        confirmationLabel="Saya sudah memeriksa target dan memahami bahwa provisioning mengubah konfigurasi OLT."
                                        typedConfirmation="PROVISION OLT"
                                        typedConfirmationLabel="Ketik PROVISION OLT untuk mengizinkan write."
                                        confirmText="Provision sekarang"
                                        onConfirm={(typed) => {
                                            setFhProvisionConfirmOpen(false);
                                            void handleFiberhomeProvision(false, typed);
                                        }}
                                        isLoading={fhLoading && !fhDryRun}
                                    >
                                        <div className="grid gap-2 rounded-lg border border-border bg-muted/30 p-3 text-sm sm:grid-cols-2">
                                            <div>
                                                <p className="text-xs text-muted-foreground">SN FiberHome</p>
                                                <p className="font-mono">{fhSn || '-'}</p>
                                            </div>
                                            <div>
                                                <p className="text-xs text-muted-foreground">Port / ONU ID</p>
                                                <p className="font-mono">{fhPort || '-'}:{fhOnuId || '-'}</p>
                                            </div>
                                            <div>
                                                <p className="text-xs text-muted-foreground">Jenis</p>
                                                <p>{fhPurpose === 'migration' ? 'Migrasi' : 'PSB Baru'}</p>
                                            </div>
                                            <div>
                                                <p className="text-xs text-muted-foreground">Nama ONU</p>
                                                <p>{fhName || '-'}</p>
                                            </div>
                                            <div>
                                                <p className="text-xs text-muted-foreground">MikroTik</p>
                                                <p className="font-mono">{fhMikrotik || '-'}</p>
                                            </div>
                                            <div>
                                                <p className="text-xs text-muted-foreground">Paket</p>
                                                <p className="font-mono">{fhPaket || '-'}</p>
                                            </div>
                                        </div>
                                    </ConfirmDestructive>

                                    {/* Result info box */}
                                    {fhResult && (fhResult.success || fhResult.olt_committed) && (
                                        <div className={`rounded-lg p-3 text-sm ${
                                            fhResult.dry_run
                                                ? 'bg-status-info-bg border border-status-info/30 text-foreground'
                                                : 'bg-status-up-bg border border-status-up/30 text-foreground'
                                        }`}>
                                            <p className="font-semibold flex items-center gap-1.5">
                                                {fhResult.dry_run ? <><Info className="h-4 w-4" />Dry Run Selesai</> : <><CheckCircle2 className="h-4 w-4" />Provisioning Berhasil di OLT</>}
                                            </p>
                                            {Array.isArray(fhResult.post_warnings) && fhResult.post_warnings.length > 0 && (
                                                <p className="mt-1 text-xs text-status-degraded">
                                                    OLT sudah aktif. Peringatan non-kritis: {fhResult.post_warnings.join(' · ')}
                                                </p>
                                            )}
                                            {fhResult.dry_run ? (
                                                <div className="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 text-xs">
                                                    <span className="opacity-70">Command count:</span><span className="font-mono">{fhResult.command_count}</span>
                                                    <span className="opacity-70">VLAN internet:</span><span className="font-mono">{fhResult.vlan}</span>
                                                    <span className="opacity-70">Traffic up:</span><span className="font-mono">{fhResult.traffic_up}</span>
                                                    <span className="opacity-70">Traffic down:</span><span className="font-mono">{fhResult.traffic_down}</span>
                                                </div>
                                            ) : (
                                                <div className="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 text-xs">
                                                    <span className="opacity-70">SN:</span><span className="font-mono">{fhResult.sn}</span>
                                                    <span className="opacity-70">Port / ONU ID:</span>
                                                    <span className="font-mono">
                                                        {fhResult.port}:{fhResult.onu_id}
                                                        {fhResult.onu_id_reassigned ? ' (reassign)' : ''}
                                                    </span>
                                                    <span className="opacity-70">Nama ONU:</span>
                                                    <span className="font-mono break-all">{fhResult.olt_inventory?.onu_name || fhResult.name}</span>
                                                    <span className="opacity-70">RX power:</span>
                                                    <span className="font-mono">
                                                        {(fhResult.verification?.rx_power ?? fhResult.olt_inventory?.rx_power_dbm) != null
                                                            ? `${fhResult.verification?.rx_power ?? fhResult.olt_inventory?.rx_power_dbm} dBm`
                                                            : 'belum optical'}
                                                    </span>
                                                    <span className="opacity-70">Status:</span>
                                                    <span className="font-mono">{fhResult.verification?.status || fhResult.olt_inventory?.state || '—'}</span>
                                                    <span className="opacity-70">VLAN / TR-069:</span>
                                                    <span className="font-mono">{fhResult.vlan} / {fhResult.tr069_vlan ?? tr069Vlan}</span>
                                                    <span className="opacity-70">GenieACS tag:</span>
                                                    <span className="font-mono break-all">
                                                        {fhResult.genieacs_tag?.pushed
                                                            ? `pushed: ${fhResult.genieacs_tag.tag}`
                                                            : fhResult.genieacs_tag?.pending || fhResult.genieacs_tag?.queued
                                                                ? `pending: ${fhResult.genieacs_tag.tag || fhResult.name} (retry otomatis)`
                                                                : (fhResult.genieacs_tag?.message || '—')}
                                                    </span>
                                                    {(fhResult.elapsed_total_ms || fhResult.elapsed_ms) && (
                                                        <>
                                                            <span className="opacity-70">Waktu:</span>
                                                            <span className="font-mono">
                                                                {fhResult.elapsed_total_ms
                                                                    ? `${Math.round(fhResult.elapsed_total_ms / 100) / 10}s total`
                                                                    : `${Math.round(fhResult.elapsed_ms / 100) / 10}s OLT`}
                                                            </span>
                                                        </>
                                                    )}
                                                </div>
                                            )}
                                        </div>
                                    )}
                                    {fhResult && !fhResult.dry_run && (fhResult.success || fhResult.olt_committed) && (
                                        <MigrationLinkPanel
                                            link={fhResult.migration_link}
                                            candidates={fhResult.migration_candidates}
                                            warning={fhResult.migration_warning}
                                            context={fhResult.onu_id ? {
                                                olt_id: parseInt(fhOltId),
                                                router_id: fhResult.router_id ?? null,
                                                sn: fhResult.sn,
                                                port: fhResult.port,
                                                onu_id: Number(fhResult.onu_id),
                                                name: fhResult.name,
                                                psb_draft_id: ['psb_new', 'migration_linked'].includes(fhResult.recorded_as) ? fhResult.record_id ?? null : null,
                                            } : null}
                                        />
                                    )}
                                    {fhResult && !fhResult.success && (
                                        <div className="bg-status-down-bg border border-status-down/30 rounded-lg p-3 text-sm text-foreground">
                                            <p className="font-semibold flex items-center gap-1.5"><AlertCircle className="h-4 w-4" />Gagal</p>
                                            <p className="mt-1">{fhResult.error}</p>
                                            {(fhResult.concurrent_busy || false) && (
                                                <p className="mt-1 text-xs opacity-80">Port sedang dipakai teknisi lain — coba lagi sebentar (concurrent-safe).</p>
                                            )}
                                        </div>
                                    )}
                                </CardContent>
                            </Card>

                            {/* Console Log - FiberHome */}
                            <ConsoleLog log={fhLog} onCopy={() => copyFhLog()} onReset={() => setFhLog([])} maxHeight="h-72" />
                        </div>
                    )}

                    {activeTab === 'huawei' && (
                        <div className="space-y-4">
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-lg flex items-center gap-2">
                                        <Cable className="h-5 w-5" />
                                        Provision ONU Huawei MA5608T (Gadang)
                                    </CardTitle>
                                    <p className="text-sm text-muted-foreground">
                                        Pilih ONT baru, cek hasil deteksi, lalu provision. Akun PPPoE tetap lewat PSB WA.
                                    </p>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    <div>
                                        <Label className="text-sm font-medium">Pilih OLT Huawei</Label>
                                        <Select value={hwOltId || '__none__'} onValueChange={(v) => { setHwOltId(v === '__none__' ? '' : v); setHwAutofind([]); setHwAutofindMeta(null); setHwAutofindError(null); setHwPonHint(null); setHwPlacement(null); }}>
                                            <SelectTrigger><SelectValue placeholder="-- Pilih OLT Huawei --" /></SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="__none__">-- Pilih OLT Huawei --</SelectItem>
                                                {huaweiOlts.map(olt => (
                                                    <SelectItem key={olt.id} value={String(olt.id)}>{olt.name}{olt.area_name ? ` · ${olt.area_name}` : ''}</SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        {huaweiOlts.length === 0 && (
                                            <p className="mt-1.5 text-xs text-status-degraded">Belum ada OLT Huawei (vendor ma5608t) terdaftar.</p>
                                        )}
                                    </div>

                                    <div className="grid gap-3 sm:grid-cols-2">
                                        <div>
                                            <Label className="text-sm font-medium">SN ONT</Label>
                                            <div className="flex gap-2">
                                                <Input
                                                    value={hwSn}
                                                    onChange={e => { setHwSn(e.target.value.toUpperCase()); setHwPonHint(null); setHwPlacement(null); }}
                                                    placeholder="HWTC… / FHTT…"
                                                    className="font-mono flex-1"
                                                />
                                                <Popover open={hwAutofindOpen} onOpenChange={(open) => { setHwAutofindOpen(open); if (open) { void handleHuaweiListAutofind(false); } }}>
                                                    <PopoverTrigger asChild>
                                                        <Button type="button" variant="outline" disabled={!hwOltId} title="Lihat ONT yang belum didaftarkan di OLT">
                                                            <User className="h-4 w-4 mr-1" />Lihat ONT baru
                                                        </Button>
                                                    </PopoverTrigger>
                                                    <PopoverContent className="w-[min(92vw,500px)] p-0" align="end">
                                                        <div className="p-3 border-b">
                                                            <p className="text-sm font-medium">ONT baru di OLT</p>
                                                            <p className="text-xs text-muted-foreground">Belum terdaftar. Klik salah satu.</p>
                                                        </div>
                                                        <div className="max-h-[300px] overflow-y-auto" data-testid="hw-autofind-list">
                                                            {hwAutofindLoading ? (
                                                                <p className="p-4 text-sm text-muted-foreground text-center">Memuat...</p>
                                                            ) : hwAutofindError ? (
                                                                <p className="p-4 text-sm text-status-degraded text-center" data-testid="hw-autofind-error">{hwAutofindError}</p>
                                                            ) : hwAutofind.length === 0 ? (
                                                                <p className="p-4 text-sm text-muted-foreground text-center" data-testid="hw-autofind-empty">
                                                                    Tidak ada ONT baru. Pastikan ONT menyala, tunggu 1 menit, lalu muat ulang.
                                                                </p>
                                                            ) : hwAutofind.map((u) => {
                                                                const time = (u.seen_at || '').match(/(\d{2}:\d{2})(?::\d{2})?/)?.[1];
                                                                const detail = [u.model, u.vendor_id, time].filter(Boolean).join(' · ');
                                                                return (
                                                                    <button
                                                                        type="button"
                                                                        key={`${u.pon_port}|${u.sn}`}
                                                                        onClick={() => handleHuaweiSelectAutofind(u)}
                                                                        className="w-full text-left p-3 border-b last:border-0 hover:bg-muted transition-colors"
                                                                    >
                                                                        <div className="flex flex-wrap items-center justify-between gap-x-2">
                                                                            <span className="font-mono text-sm text-primary font-semibold break-all">{u.sn}</span>
                                                                            <span className="text-xs text-muted-foreground">PON {u.pon_port}</span>
                                                                        </div>
                                                                        {detail && <div className="mt-0.5 text-xs text-muted-foreground">{detail}</div>}
                                                                    </button>
                                                                );
                                                            })}
                                                        </div>
                                                        <div className="flex flex-wrap items-center justify-between gap-2 p-3 border-t">
                                                            <span className="text-xs text-muted-foreground">
                                                                {hwAutofindMeta ? `Data ${hwAutofindMeta.age_seconds} detik lalu` : ''}
                                                            </span>
                                                            <Button type="button" size="sm" variant="outline" disabled={hwAutofindLoading} onClick={() => void handleHuaweiListAutofind(true)}>
                                                                Muat ulang
                                                            </Button>
                                                        </div>
                                                        {hwAutofindMeta?.partial && !hwAutofindError && (
                                                            <p className="px-3 pb-3 text-xs text-status-degraded">Sebagian ONT tidak terbaca. Muat ulang.</p>
                                                        )}
                                                    </PopoverContent>
                                                </Popover>
                                            </div>
                                            <p className="mt-1 text-xs text-muted-foreground">Boleh 12 huruf (HWTC…) atau 16 angka-huruf (4857…).</p>
                                        </div>
                                        <div>
                                            <Label className="text-sm font-medium">Nama / PPPoE (description ONT)</Label>
                                            <Input value={hwName} onChange={e => setHwName(e.target.value)} placeholder="NAMA_RT1_RW1_1.1" className="font-mono" />
                                        </div>
                                    </div>

                                    <div className="flex gap-3">
                                        <Button variant="outline" onClick={() => void handleHuaweiDetect()} disabled={hwDetecting || !hwOltId || !hwSn}>
                                            {hwDetecting ? <><Loader2 className="h-4 w-4 mr-2 animate-spin" />Deteksi...</> : <><Info className="h-4 w-4 mr-2" />Deteksi SN (autofind)</>}
                                        </Button>
                                    </div>

                                    {hwPlacement && (
                                        <div className="rounded-lg border border-border bg-muted/30 p-3 text-sm grid grid-cols-2 gap-x-4 gap-y-1">
                                            <span className="opacity-70">PON / ONT-ID:</span><span className="font-mono">{hwPlacement.pon_port}:{hwPlacement.onu_id}</span>
                                            <span className="opacity-70">VLAN internet / TR-069:</span><span className="font-mono">{hwPlacement.vlan} / {hwPlacement.tr069_vlan}</span>
                                            <span className="opacity-70">Line profile:</span><span className="font-mono">{hwPlacement.line_profile}</span>
                                            <span className="opacity-70">Sumber:</span><span className="font-mono">{hwPlacement.source === 'autofind' ? 'ONT baru' : hwPlacement.source === 'registered_olt_snapshot' ? 'Sudah terdaftar di OLT' : hwPlacement.source}</span>
                                        </div>
                                    )}

                                    <div>
                                        <Label className="text-sm font-medium">Paket Bandwidth (info)</Label>
                                        <Input value={hwPaket} onChange={e => setHwPaket(e.target.value)} placeholder="100M" className="font-mono" />
                                        <p className="mt-1 text-xs text-muted-foreground">Rate limit pelanggan tetap dari profile MikroTik; paket ini hanya label UI.</p>
                                    </div>

                                    <div className="flex gap-3">
                                        <Button
                                            variant="outline"
                                            onClick={() => handleHuaweiProvision(true)}
                                            disabled={hwLoading || !hwOltId || !hwSn || !hwName}
                                            className="flex-1"
                                        >
                                            {hwLoading ? <><Loader2 className="h-4 w-4 mr-2 animate-spin" />Memproses...</> : <><Info className="h-4 w-4 mr-2" />Dry Run (Preview)</>}
                                        </Button>
                                        <Button
                                            onClick={() => setHwConfirmOpen(true)}
                                            disabled={hwLoading || !hwOltId || !hwSn || !hwName}
                                            className="flex-1"
                                        >
                                            {hwLoading ? <><Loader2 className="h-4 w-4 mr-2 animate-spin" />Provisioning...</> : <><Zap className="h-4 w-4 mr-2" />Provision Now</>}
                                        </Button>
                                    </div>

                                    <ConfirmDestructive
                                        open={hwConfirmOpen}
                                        onOpenChange={setHwConfirmOpen}
                                        title="Konfirmasi provisioning Huawei"
                                        description="Tindakan ini akan menulis konfigurasi ONU ke OLT Huawei Gadang."
                                        confirmationLabel="Saya sudah memeriksa target dan memahami bahwa provisioning mengubah konfigurasi OLT."
                                        typedConfirmation="PROVISION OLT"
                                        typedConfirmationLabel="Ketik PROVISION OLT untuk mengizinkan write."
                                        confirmText="Provision sekarang"
                                        onConfirm={(typed) => {
                                            setHwConfirmOpen(false);
                                            void handleHuaweiProvision(false, typed);
                                        }}
                                        isLoading={hwLoading}
                                    >
                                        <div className="grid gap-2 rounded-lg border border-border bg-muted/30 p-3 text-sm sm:grid-cols-2">
                                            <div><p className="text-xs text-muted-foreground">OLT</p><p className="font-mono">{huaweiOlts.find(o => String(o.id) === hwOltId)?.name || '-'}</p></div>
                                            <div><p className="text-xs text-muted-foreground">SN</p><p className="font-mono">{hwSn || '-'}</p></div>
                                            <div><p className="text-xs text-muted-foreground">Nama / PPPoE</p><p className="font-mono">{hwName || '-'}</p></div>
                                            <div><p className="text-xs text-muted-foreground">PON / ONT-ID</p><p className="font-mono">{hwPlacement ? `${hwPlacement.pon_port}:${hwPlacement.onu_id}` : 'autofind'}</p></div>
                                        </div>
                                    </ConfirmDestructive>

                                    {hwResult && (
                                        <div className={`rounded-lg p-3 text-sm ${hwResult.success ? 'bg-status-up-bg border border-status-up/30 text-foreground' : 'bg-status-down-bg border border-status-down/30 text-foreground'}`}>
                                            <p className="font-semibold flex items-center gap-1.5">
                                                {hwResult.success ? <><CheckCircle2 className="h-4 w-4" />{hwResult.dry_run ? 'Dry Run Selesai' : 'Provisioning Huawei Berhasil'}</> : <><AlertCircle className="h-4 w-4" />Gagal</>}
                                            </p>
                                            {hwResult.success ? (
                                                <div className="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 text-xs">
                                                    <span className="opacity-70">SN:</span><span className="font-mono">{hwResult.sn}</span>
                                                    <span className="opacity-70">PON / ONT-ID:</span><span className="font-mono">{hwResult.pon_port}:{hwResult.onu_id}</span>
                                                    <span className="opacity-70">VLAN / TR-069:</span><span className="font-mono">{hwResult.vlan} / {hwResult.tr069_vlan}</span>
                                                    {!hwResult.dry_run && (<><span className="opacity-70">TR-069:</span><span className="font-mono">{hwResult.tr069_pending ? 'pending' : 'ok'}</span></>)}
                                                </div>
                                            ) : (
                                                <p className="mt-1">{hwResult.error || hwResult.message}</p>
                                            )}
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        </div>
                    )}
                    </TabsContent>
                    </Tabs>

                    {/* ===== CREDENTIALS MODAL ===== */}
                    {showCredModal && (
                        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50" onClick={() => setShowCredModal(false)}>
                            <Card className="w-full max-w-md mx-4" onClick={e => e.stopPropagation()}>
                                <CardHeader>
                                    <CardTitle className="text-lg flex items-center gap-2">
                                        <KeyRound className="h-5 w-5" aria-hidden="true" /> Update Kredensial OLT
                                    </CardTitle>
                                    <p className="text-sm text-muted-foreground">Update username atau password SSH untuk koneksi ke OLT</p>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    <div>
                                        <Label className="text-sm font-medium">Username SSH</Label>
                                        <Input value={credUsername} onChange={e => setCredUsername(e.target.value)} placeholder="host1" className="font-mono" />
                                    </div>
                                    <div>
                                        <Label className="text-sm font-medium">Password SSH</Label>
                                        <Input value={credPassword} onChange={e => setCredPassword(e.target.value)} placeholder="Password baru" type="text" className="font-mono" />
                                    </div>
                                    <div className="flex gap-3">
                                        <Button variant="outline" onClick={() => setShowCredModal(false)} className="flex-1">Batal</Button>
                                        <Button onClick={handleUpdateCredentials} disabled={credSaving || (!credUsername && !credPassword)} className="flex-1">
                                            {credSaving ? <><Loader2 className="h-4 w-4 mr-2 animate-spin" />Menyimpan...</> : <><CheckCircle2 className="h-4 w-4 mr-2" aria-hidden="true" />Simpan</>}
                                        </Button>
                                    </div>
                                </CardContent>
                            </Card>
                        </div>
                    )}
            </div>
        </EnterpriseIndexLayout>
    );
}
