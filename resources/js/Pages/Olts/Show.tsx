import { OltErrorBoundary } from '@/Components/OltErrorBoundary';
import { EnterpriseResourceLayout } from '@/Components/enterprise/EnterpriseResourceLayout';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { formatDateTime } from '@/lib/format';
import { Link, router } from '@inertiajs/react';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { ConfirmDestructive } from '@/Components/ConfirmDestructive';
import { DetailSection } from '@/Components/DetailSection';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import { toast } from 'sonner';

import {
    AlertCircle, Boxes, Copy, Edit, MapPin, Network, PlugZap, RefreshCw,
    Router as RouterIcon, Trash2, Search, ChevronLeft, ChevronRight,
    ArrowUpDown, ArrowUp, ArrowDown, Eye, Power, Trash, Zap,
    Signal, SignalZero, ChevronDown, CheckCircle, ShieldAlert, Pencil, Download,
    AlertTriangle, RadioTower,
} from 'lucide-react';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { Badge } from '@/Components/ui/badge';
import { Input } from '@/Components/ui/input';
import { Textarea } from '@/Components/ui/textarea';
import {
    Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/Components/ui/select';
import { usePollWhileVisible } from '@/hooks/usePollWhileVisible';

interface OltPelanggan {
    id: number; code: string | null; name: string; pppoe_user: string | null;
    status: string; olt_port_label: string | null; onu_serial: string | null;
    olt_status: string | null; onu_rx_power_dbm: string | number | null;
    onu_tx_power_dbm: string | number | null; fiber_distance_m: number | null;
    olt_last_synced_at: string | null;
}

interface PonPortGroup { label: string; value: string; customers: OltPelanggan[]; }

interface Props {
    olt: {
        id: number; name: string; code: string; vendor: string;
        management_ip: string | null; management_protocol: string | null;
        management_port: number | null; username: string | null;
        snmp_community: null; snmp_community_configured: boolean; location: string | null; notes: string | null;
        area: { id: number; name: string } | null;
        router: { id: number; name: string } | null;
        customer_count: number; pon_port_groups: PonPortGroup[];
        last_gpon_snapshot: GponSnapshot | null; last_gpon_synced_at: string | null;
    };
    /** Huawei ONU write (delete/rename/reboot): owner flag + superadmin, decided server-side too. */
    huaweiUiWrite?: { enabled: boolean; superadmin: boolean; save_pending_since?: string | null };
}

interface GponOnuSnapshot {
    onu_ref: string; pon_port: string | null; state: string | null;
    phase_state: string | null; serial_number: string | null; onu_name: string | null;
    /** API sometimes sends string — always coerce via coerceRx() before math/toFixed */
    rx_power_dbm: number | string | null; distance_m: number | null; detail_raw: Record<string, unknown> | string | null;
}

interface MatchedPelangganSnapshot {
    customer_id: number; customer_name: string; pppoe_user: string;
    onu_ref: string | null; rx_power_dbm: number | null; distance_m: number | null; changed?: boolean;
}

interface GponSnapshot {
    meta: {
        olt: string; host: string; port: number; protocol: string;
        collected_at: string; pon_ports_count: number; onus_count: number;
        matched_customers_count: number; updated_customers_count?: number; warning?: string | null;
        refresh_queued?: boolean;
        sync_in_progress?: boolean;
        sync_status?: string;
        snapshot_id?: string;
        stats_invariant_ok?: boolean;
        stats?: {
            total: number; online: number; offline: number; state_unknown: number;
            matched: number; unmatched: number; match_conflict: number;
            signal_good: number; signal_weak: number; signal_loss: number;
            signal_missing: number; signal_not_applicable: number;
            sn_duplicate_groups: number; sn_duplicate_rows: number;
        };
    };
    pon_ports: Array<{ name: string }>;
    onus: GponOnuSnapshot[];
    raw: Record<string, unknown>;
    commands: unknown[];
    matched_customers: MatchedPelangganSnapshot[];
}

type SnapshotFilter = 'all' | 'online' | 'offline' | 'matched' | 'unmatched' | 'match_conflict' | 'sn_double';
type SignalFilter = 'all' | 'good' | 'weak' | 'loss' | 'rx_missing' | 'no_signal';
type SortField = 'onu_ref' | 'pon_port' | 'onu_name' | 'rx_power_dbm' | 'distance_m' | 'state';
type SortDir = 'asc' | 'desc';
type DeleteHint = 'delete' | 'keep' | 'review';

interface SnDuplicateGroup {
    serial: string;
    onus: GponOnuSnapshot[];
    onlineCount: number;
    matchedCount: number;
}

interface FhttMigrationPair {
    key: string;
    pppoe: string;
    displayName: string;
    customer?: MatchedPelangganSnapshot;
    snLama: string | null;
    portLama: string | null;
    onuLama: GponOnuSnapshot | null;
    snBaru: string | null;
    portBaru: string | null;
    onuBaru: GponOnuSnapshot | null;
}

const PER_PAGE = 50;
const SIG = { good: -25, weak: -27, loss: -30, dead: -50 };

function parseOnuRef(ref: string): { port: string; onuId: string } | null {
    const normalized = ref.replace(/^gpon-onu_/i, '').trim();
    const match = normalized.match(/^(\d+\/\d+\/\d+):(\d+)$/i);
    if (!match) return null;
    return { port: match[1], onuId: match[2] };
}

/** UPPER + underscore — sama CLI OLT / tag ACS */
function normalizeOnuDisplayName(raw: string): string {
    return raw
        .trim()
        .toUpperCase()
        .replace(/[\s-]+/g, '_')
        .replace(/[^A-Z0-9_.]/g, '')
        .replace(/_+/g, '_')
        .replace(/^_+|_+$/g, '');
}

/** Coerce RX from API (number | string) — avoids toFixed/compare crashes. */
function coerceRx(rx: number | string | null | undefined): number | null {
    if (rx == null || rx === '') return null;
    const n = typeof rx === 'number' ? rx : Number(String(rx).replace(',', '.'));
    if (!Number.isFinite(n)) return null;
    return n;
}

/** Valid optical RX (−40..0 dBm) ⇒ ONU is up even if CLI state parse says offline. */
function isValidLiveRx(rx: number | string | null | undefined): boolean {
    const n = coerceRx(rx);
    if (n == null) return false;
    if (Math.abs(n) > 100) return false;
    return n >= -40 && n <= 0;
}

function isOnuOnline(
    state: string | null | undefined,
    phaseState?: string | null,
    rxPowerDbm?: number | string | null,
): boolean {
    const normalized = (state ?? '').toLowerCase();
    if (['online', 'working', 'up', 'active', 'enable', 'enabled'].includes(normalized)) {
        return true;
    }
    const phase = (phaseState ?? '').toUpperCase();
    if (phase === 'O5') return true;
    // Live redaman is stronger than a failed/stale "offline" parse
    if (isValidLiveRx(rxPowerDbm)) return true;
    return false;
}

function isMatchConflict(onu: GponOnuSnapshot): boolean {
    return Boolean((onu as GponOnuSnapshot & { match_conflict?: string }).match_conflict);
}

function signalBucket(onu: GponOnuSnapshot): 'good' | 'weak' | 'loss' | 'rx_missing' | 'no_signal' {
    if (!isOnuOnline(onu.state, onu.phase_state, onu.rx_power_dbm)) return 'no_signal';
    const n = coerceRx(onu.rx_power_dbm);
    if (n == null) return 'rx_missing';
    if (n >= SIG.good) return 'good';
    if (n >= SIG.weak) return 'weak';
    return 'loss';
}

/** Safe key for maps/filters — never throw on null onu_ref. */
function safeRef(ref: string | null | undefined): string {
    return (ref ?? '').toString().trim().toLowerCase();
}

function normalizeOnuRefKey(ref: string | null | undefined): string {
    return safeRef(ref).replace(/^gpon-(?:olt|onu)_/i, '');
}

/**
 * Natural sort for ONU/PON paths: 1/3/2:10 before 1/3/15:2 (not string "1/3/15" < "1/3/2").
 */
function compareOnuPath(a: string | null | undefined, b: string | null | undefined): number {
    const sa = (a ?? '').toString();
    const sb = (b ?? '').toString();
    const parts = (s: string) => (s.match(/\d+/g) || []).map((x) => Number(x));
    const pa = parts(sa);
    const pb = parts(sb);
    const len = Math.max(pa.length, pb.length);
    for (let i = 0; i < len; i++) {
        const da = pa[i] ?? -1;
        const db = pb[i] ?? -1;
        if (da !== db) return da - db;
    }
    return sa.localeCompare(sb, undefined, { sensitivity: 'base' });
}

function normalizeSerial(value: string | null | undefined): string | null {
    if (!value) return null;
    const normalized = value.trim().toUpperCase().replace(/[^A-Z0-9]/g, '');
    return normalized.length > 0 ? normalized : null;
}

function normPppoe(value: string | null | undefined): string | null {
    if (!value) return null;
    const normalized = value.trim().toLowerCase();
    return normalized.length > 0 ? normalized : null;
}

function isFhttSn(value: string | null | undefined): boolean {
    const sn = normalizeSerial(value);
    return sn?.startsWith('FHTT') ?? false;
}

function buildFhttMigrationPairs(
    onus: GponOnuSnapshot[],
    matchedByPppoe: Map<string, MatchedPelangganSnapshot>,
): FhttMigrationPair[] {
    const byName = new Map<string, GponOnuSnapshot[]>();
    for (const onu of onus) {
        const name = normPppoe(onu.onu_name);
        if (!name) continue;
        const list = byName.get(name) ?? [];
        list.push(onu);
        byName.set(name, list);
    }

    const pairs: FhttMigrationPair[] = [];

    for (const [pppoe, list] of byName) {
        if (list.length < 2) continue;

        const fhttList = list.filter(o => isFhttSn(o.serial_number));
        const legacyList = list.filter(o => !isFhttSn(o.serial_number) && normalizeSerial(o.serial_number));

        if (fhttList.length === 0 || legacyList.length === 0) {
            continue;
        }

        const onuBaru = fhttList.find(o => isOnuOnline(o.state, o.phase_state, o.rx_power_dbm))
            ?? [...fhttList].sort((a, b) => a.onu_ref.localeCompare(b.onu_ref)).at(-1)
            ?? null;
        const onuLama = legacyList.find(o => !isOnuOnline(o.state, o.phase_state, o.rx_power_dbm))
            ?? legacyList.find(o => onuBaru && o.onu_ref !== onuBaru.onu_ref)
            ?? legacyList[0]
            ?? null;

        if (!onuBaru || !onuLama || onuBaru.onu_ref === onuLama.onu_ref) {
            continue;
        }

        const customer = matchedByPppoe.get(pppoe);

        pairs.push({
            key: pppoe,
            pppoe,
            displayName: customer?.customer_name ?? list[0].onu_name ?? pppoe,
            customer,
            snLama: onuLama.serial_number,
            portLama: onuLama.onu_ref,
            onuLama,
            snBaru: onuBaru.serial_number,
            portBaru: onuBaru.onu_ref,
            onuBaru,
        });
    }

    return pairs.sort((a, b) => a.displayName.localeCompare(b.displayName));
}

function buildSnDuplicateGroups(onus: GponOnuSnapshot[]): SnDuplicateGroup[] {
    const bySn = new Map<string, GponOnuSnapshot[]>();
    for (const onu of onus) {
        const sn = normalizeSerial(onu.serial_number);
        if (!sn) continue;
        const list = bySn.get(sn) ?? [];
        list.push(onu);
        bySn.set(sn, list);
    }

    return Array.from(bySn.entries())
        .filter(([, list]) => list.length > 1)
        .map(([serial, list]) => ({
            serial,
            onus: [...list].sort((a, b) => {
                const aOnline = isOnuOnline(a.state, a.phase_state, a.rx_power_dbm) ? 0 : 1;
                const bOnline = isOnuOnline(b.state, b.phase_state, b.rx_power_dbm) ? 0 : 1;
                if (aOnline !== bOnline) return aOnline - bOnline;
                return a.onu_ref.localeCompare(b.onu_ref);
            }),
            onlineCount: list.filter(o => isOnuOnline(o.state, o.phase_state, o.rx_power_dbm)).length,
            matchedCount: 0,
        }))
        .sort((a, b) => b.onus.length - a.onus.length || a.serial.localeCompare(b.serial));
}

function deleteHintForOnu(onu: GponOnuSnapshot, matched?: MatchedPelangganSnapshot): DeleteHint {
    const online = isOnuOnline(onu.state, onu.phase_state, onu.rx_power_dbm);
    const hasMatch = !!matched;
    if (!online && !hasMatch) return 'delete';
    if (online && hasMatch) return 'keep';
    return 'review';
}

function deleteHintLabel(hint: DeleteHint): { label: string; className: string } {
    switch (hint) {
        case 'delete':
            return { label: 'Hapus (ghost)', className: 'border-status-down/30 bg-status-down-bg text-status-down' };
        case 'keep':
            return { label: 'Pertahankan', className: 'border-status-up/30 bg-status-up-bg text-status-up' };
        default:
            return { label: 'Cek manual', className: 'border-status-degraded/30 bg-status-degraded-bg text-status-degraded' };
    }
}

function removeOnuFromSnapshot(snapshot: GponSnapshot, ref: string): GponSnapshot {
    const key = ref.toLowerCase();
    const onus = snapshot.onus.filter(o => o.onu_ref.toLowerCase() !== key);
    const matched_customers = (snapshot.matched_customers ?? []).filter(
        c => (c.onu_ref ?? '').toLowerCase() !== key,
    );

    return {
        ...snapshot,
        onus,
        matched_customers,
        meta: {
            ...snapshot.meta,
            onus_count: onus.length,
            matched_customers_count: matched_customers.length,
        },
    };
}

function sigQuality(rx: number | string | null | undefined) {
    const n = coerceRx(rx);
    if (n === null) return { label: 'No Data', color: 'text-muted-foreground', bg: 'bg-muted' };
    if (n >= SIG.good) return { label: 'Bagus', color: 'text-status-up', bg: 'bg-status-up-bg' };
    if (n >= SIG.weak) return { label: 'Lemah', color: 'text-status-degraded', bg: 'bg-status-degraded-bg' };
    if (n >= SIG.loss) return { label: 'Loss', color: 'text-status-degraded', bg: 'bg-status-degraded-bg' };
    if (n >= SIG.dead) return { label: 'Loss', color: 'text-status-down', bg: 'bg-status-down-bg' };
    return { label: 'Offline', color: 'text-muted-foreground', bg: 'bg-muted' };
}

const HUAWEI_WRITE_LOCKED_TITLE = 'Tulis Huawei dimatikan (saklar owner) atau bukan superadmin';

export default function Show({ olt, huaweiUiWrite }: Props) {
    const isHuawei = /huawei|ma5608/i.test(olt.vendor || '');
    const huaweiWriteLocked = isHuawei && !(huaweiUiWrite?.enabled === true && huaweiUiWrite?.superadmin === true);
    const [savePending, setSavePending] = useState(Boolean(huaweiUiWrite?.save_pending_since));
    const [saveConfirmation, setSaveConfirmation] = useState('');
    const [saveSubmitting, setSaveSubmitting] = useState(false);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [oltDeleting, setOltDeleting] = useState(false);
    const [snapshot, setSnapshot] = useState<GponSnapshot | null>(olt.last_gpon_snapshot);
    const [snapshotLoading, setSnapshotLoading] = useState(false);
    const [snapshotError, setSnapshotError] = useState<string | null>(null);
    const [snapshotFilter, setSnapshotFilter] = useState<SnapshotFilter>('all');
    const [signalFilter, setSignalFilter] = useState<SignalFilter>('all');
    const [search, setSearch] = useState('');
    const [ponFilter, setPonFilter] = useState('all');
    const [sortField, setSortField] = useState<SortField>('pon_port');
    const [sortDir, setSortDir] = useState<SortDir>('asc');
    const [page, setPage] = useState(1);
    const [actionLoading, setActionLoading] = useState<string | null>(null);
    const [deleteTarget, setDeleteTarget] = useState<GponOnuSnapshot | null>(null);
    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [deleteSubmitting, setDeleteSubmitting] = useState(false);
    const [deleteConfirmOnline, setDeleteConfirmOnline] = useState(false);
    const [deleteConfirmation, setDeleteConfirmation] = useState('');
    const [deleteGuard, setDeleteGuard] = useState<{ message: string; canOverride: boolean; reasonMin: number } | null>(null);
    const [deleteOverrideReason, setDeleteOverrideReason] = useState('');
    const [deleteOverrideChecked, setDeleteOverrideChecked] = useState(false);
    const [rxPowerError, setRxPowerError] = useState<string | null>(null);
    const [detailDialogOpen, setDetailDialogOpen] = useState(false);
    const [detailLoading, setDetailLoading] = useState(false);
    const [detailPayload, setDetailPayload] = useState<{
        onu_ref: string;
        onu: GponOnuSnapshot;
        matched?: MatchedPelangganSnapshot;
    } | null>(null);
    const [renameDialogOpen, setRenameDialogOpen] = useState(false);
    const [renameTarget, setRenameTarget] = useState<GponOnuSnapshot | null>(null);
    const [renameName, setRenameName] = useState('');
    const [renameSubmitting, setRenameSubmitting] = useState(false);
    const [renameConfirmation, setRenameConfirmation] = useState('');
    const [rebootTarget, setRebootTarget] = useState<string | null>(null);
    const [rebootDialogOpen, setRebootDialogOpen] = useState(false);
    const [pageTab, setPageTab] = useState<'detail' | 'gpon'>('gpon');
    const autoSnapshotLoaded = useRef(false);

    const applyGponSnapshot = useCallback((payload: GponSnapshot | null | undefined) => {
        if (!payload || !Array.isArray(payload.onus)) {
            return false;
        }
        setSnapshot(payload);
        setSnapshotError(null);
        return true;
    }, []);

    const fetchGponSnapshot = useCallback(async (refresh = false): Promise<GponSnapshot | null> => {
        const url = refresh
            ? `${route('api.olts.gpon-snapshot', olt.id)}?refresh=1`
            : route('api.olts.gpon-snapshot', olt.id);
        const response = await fetch(url, {
            headers: { Accept: 'application/json' },
            credentials: 'include',
        });
        const payload = await response.json();
        if ((!response.ok && response.status !== 202) || !Array.isArray(payload.onus)) {
            return null;
        }
        return payload as GponSnapshot;
    }, [olt.id]);

    const pollGponSnapshotUntilReady = useCallback(async (attempt = 0): Promise<GponSnapshot | null> => {
        const payload = await fetchGponSnapshot(false);
        if (!payload) {
            return null;
        }

        applyGponSnapshot(payload);
        const queued = payload.meta?.refresh_queued === true || payload.meta?.sync_in_progress === true;
        if (!queued || attempt >= 40) {
            return payload;
        }

        await new Promise((resolve) => setTimeout(resolve, 3000));
        return pollGponSnapshotUntilReady(attempt + 1);
    }, [applyGponSnapshot, fetchGponSnapshot]);

    const syncGponSnapshot = useCallback(async () => {
        setSnapshotLoading(true);
        setSnapshotError(null);
        try {
            const kickoff = await fetchGponSnapshot(true);
            if (!kickoff) throw new Error('Gagal memulai sinkron ulang GPON dari OLT.');

            applyGponSnapshot(kickoff);
            if (kickoff.meta?.warning) {
                toast.info(kickoff.meta.warning);
            }

            const payload = kickoff.meta?.refresh_queued
                ? await pollGponSnapshotUntilReady()
                : kickoff;

            if (!payload) throw new Error('Gagal memuat data GPON dari OLT.');
            applyGponSnapshot(payload);
            router.reload({ only: ['olt'] });
        } catch (error) {
            setSnapshotError(error instanceof Error ? error.message : 'Gagal memuat data GPON dari OLT.');
        } finally { setSnapshotLoading(false); }
    }, [applyGponSnapshot, fetchGponSnapshot, pollGponSnapshotUntilReady]);

    const refreshSnapshotAfterDelete = useCallback(async (deletedRef: string) => {
        try {
            const cached = await fetchGponSnapshot(false);
            if (cached) {
                applyGponSnapshot(cached);
            } else {
                setSnapshot(prev => (prev ? removeOnuFromSnapshot(prev, deletedRef) : prev));
            }
        } catch {
            setSnapshot(prev => (prev ? removeOnuFromSnapshot(prev, deletedRef) : prev));
        }
        router.reload({ only: ['olt'] });
    }, [applyGponSnapshot, fetchGponSnapshot]);

    const pollRxPowerStatus = useCallback(async () => {
        try {
            const res = await fetch(route('api.olt-c300.rx-power-status', { olt_id: olt.id }), {
                headers: { Accept: 'application/json' },
                credentials: 'include',
            });
            const data = await res.json();
            if (data.status === 'done') {
                setActionLoading(null);
                const payload = await fetchGponSnapshot(false);
                if (payload) applyGponSnapshot(payload);
            } else if (data.status === 'error') {
                setActionLoading(null);
                setRxPowerError(data.error || 'Gagal mengambil data redaman dari OLT');
            } else if (data.status === 'processing') {
                setTimeout(() => void pollRxPowerStatus(), data.via === 'snmp' ? 5000 : 10000);
            } else {
                setActionLoading(null);
                setRxPowerError('Status pengambilan tidak diketahui');
            }
        } catch {
            setActionLoading(null);
            setRxPowerError('Gagal mengecek status pengambilan redaman');
        }
    }, [applyGponSnapshot, fetchGponSnapshot, olt.id]);

    const refreshRxPower = useCallback(async () => {
        setActionLoading('rx-power');
        setRxPowerError(null);
        try {
            const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
            const res = await fetch(route('api.olt-c300.rx-power-refresh'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token, Accept: 'application/json' },
                credentials: 'include',
                body: JSON.stringify({ olt_id: olt.id }),
            });
            const data = await res.json();
            if (data.status === 'processing') {
                // Started — begin polling
                setTimeout(() => void pollRxPowerStatus(), 10000);
            } else if (data.error) {
                setActionLoading(null);
                setRxPowerError(data.error);
            }
        } catch {
            setActionLoading(null);
            setRxPowerError('Gagal memulai pengambilan redaman');
        }
    }, [olt.id, pollRxPowerStatus]);

    useEffect(() => {
        if (autoSnapshotLoaded.current || snapshot || !olt.management_ip) return;
        autoSnapshotLoaded.current = true;
        void syncGponSnapshot();
    }, [olt.management_ip, snapshot, syncGponSnapshot]);

    // Background poll: pick up SNMP/state updates from scheduler without full SSH refresh.
    // R6: 45 s, paused while the tab is hidden (shared hook).
    usePollWhileVisible(
        async () => {
            const payload = await fetchGponSnapshot(false);
            if (payload) applyGponSnapshot(payload);
        },
        45000,
        { enabled: Boolean(snapshot) && !snapshotLoading && actionLoading !== 'rx-power' },
    );

    const matchedRefs = useMemo(() => new Set(
        (snapshot?.matched_customers ?? []).map(c => c.onu_ref?.toLowerCase()).filter(Boolean as any)
    ), [snapshot?.matched_customers]);

    const matchedMap = useMemo(() => {
        const m = new Map<string, MatchedPelangganSnapshot>();
        (snapshot?.matched_customers ?? []).forEach(c => { if (c.onu_ref) m.set(c.onu_ref.toLowerCase(), c); });
        return m;
    }, [snapshot?.matched_customers]);

    const customerSerialByRef = useMemo(() => {
        const serials = new Map<string, string>();
        (olt.pon_port_groups ?? []).forEach((group) => {
            group.customers.forEach((customer) => {
                const ref = normalizeOnuRefKey(customer.olt_port_label);
                const serial = customer.onu_serial?.trim();
                if (ref && serial) serials.set(ref, serial);
            });
        });
        return serials;
    }, [olt.pon_port_groups]);

    const matchedByPppoe = useMemo(() => {
        const m = new Map<string, MatchedPelangganSnapshot>();
        (snapshot?.matched_customers ?? []).forEach(c => {
            const pppoe = normPppoe(c.pppoe_user);
            if (pppoe) m.set(pppoe, c);
        });
        return m;
    }, [snapshot?.matched_customers]);

    const fhttMigrationPairs = useMemo(
        () => buildFhttMigrationPairs(snapshot?.onus ?? [], matchedByPppoe),
        [snapshot?.onus, matchedByPppoe],
    );

    const snDuplicateGroups = useMemo(() => {
        const groups = buildSnDuplicateGroups(snapshot?.onus ?? []);
        return groups.map(group => ({
            ...group,
            matchedCount: group.onus.filter(o => matchedRefs.has(o.onu_ref.toLowerCase())).length,
        }));
    }, [snapshot?.onus, matchedRefs]);

    const duplicateSnRefs = useMemo(() => {
        const refs = new Set<string>();
        snDuplicateGroups.forEach(group => group.onus.forEach(o => {
            const ref = safeRef(o.onu_ref);
            if (ref) refs.add(ref);
        }));
        return refs;
    }, [snDuplicateGroups]);

    const duplicateSnCount = duplicateSnRefs.size;

    const ponPorts = useMemo(() => {
        const s = new Set<string>();
        (snapshot?.onus ?? []).forEach((o) => {
            if (o.pon_port) s.add(String(o.pon_port));
        });
        return Array.from(s).sort(compareOnuPath);
    }, [snapshot?.onus]);

    const stats = useMemo(() => {
        const backend = snapshot?.meta?.stats ?? {
            total: 0,
            online: 0,
            offline: 0,
            matched: 0,
            unmatched: 0,
            match_conflict: 0,
            signal_good: 0,
            signal_weak: 0,
            signal_loss: 0,
            signal_missing: 0,
            signal_not_applicable: 0,
            sn_duplicate_groups: 0,
            sn_duplicate_rows: 0,
        };
        return {
            total: backend.total,
            online: backend.online,
            offline: backend.offline,
            matched: backend.matched,
            unmatched: backend.unmatched,
            matchConflict: backend.match_conflict,
            good: backend.signal_good,
            weak: backend.signal_weak,
            loss: backend.signal_loss,
            rxMissing: backend.signal_missing,
            noSig: backend.signal_not_applicable,
            snDoubleGroups: backend.sn_duplicate_groups,
            snDoubleOnus: backend.sn_duplicate_rows,
            fhttMigrationPairs: fhttMigrationPairs.length,
            identicalSnGroups: backend.sn_duplicate_groups,
        };
    }, [snapshot?.meta?.stats, fhttMigrationPairs.length]);

    const filtered = useMemo(() => {
        let onus = snapshot?.onus ?? [];
        if (snapshotFilter === 'online') onus = onus.filter((o) => isOnuOnline(o.state, o.phase_state, o.rx_power_dbm));
        if (snapshotFilter === 'offline') onus = onus.filter((o) => !isOnuOnline(o.state, o.phase_state, o.rx_power_dbm));
        if (snapshotFilter === 'matched') onus = onus.filter((o) => !isMatchConflict(o) && matchedRefs.has(safeRef(o.onu_ref)));
        if (snapshotFilter === 'unmatched') onus = onus.filter((o) => !isMatchConflict(o) && !matchedRefs.has(safeRef(o.onu_ref)));
        if (snapshotFilter === 'match_conflict') onus = onus.filter(isMatchConflict);
        if (snapshotFilter === 'sn_double') onus = onus.filter((o) => duplicateSnRefs.has(safeRef(o.onu_ref)));
        if (signalFilter !== 'all') onus = onus.filter((o) => signalBucket(o) === signalFilter);
        if (ponFilter !== 'all') {
            onus = onus.filter((o) => String(o.pon_port ?? '') === ponFilter);
        }
        if (search.trim()) {
            const q = search.toLowerCase().trim();
            onus = onus.filter((o) =>
                safeRef(o.onu_ref).includes(q) ||
                (o.onu_name ?? '').toLowerCase().includes(q) ||
                (o.serial_number ?? '').toLowerCase().includes(q) ||
                (o.pon_port ?? '').toLowerCase().includes(q) ||
                (o.state ?? '').toLowerCase().includes(q),
            );
        }
        onus = [...onus].sort((a, b) => {
            let cmp = 0;
            switch (sortField) {
                case 'onu_ref':
                    cmp = compareOnuPath(a.onu_ref, b.onu_ref);
                    break;
                case 'pon_port':
                    cmp = compareOnuPath(a.pon_port, b.pon_port);
                    break;
                case 'onu_name':
                    cmp = (a.onu_name ?? '').localeCompare(b.onu_name ?? '', undefined, { sensitivity: 'base' });
                    break;
                case 'rx_power_dbm': {
                    const va = coerceRx(a.rx_power_dbm) ?? -999;
                    const vb = coerceRx(b.rx_power_dbm) ?? -999;
                    cmp = va - vb;
                    break;
                }
                case 'distance_m': {
                    const va = a.distance_m != null && Number.isFinite(Number(a.distance_m)) ? Number(a.distance_m) : -1;
                    const vb = b.distance_m != null && Number.isFinite(Number(b.distance_m)) ? Number(b.distance_m) : -1;
                    cmp = va - vb;
                    break;
                }
                case 'state':
                    cmp = (a.state ?? '').localeCompare(b.state ?? '', undefined, { sensitivity: 'base' });
                    break;
                default:
                    cmp = compareOnuPath(a.onu_ref, b.onu_ref);
            }
            return sortDir === 'asc' ? cmp : -cmp;
        });
        return onus;
    }, [snapshot?.onus, snapshotFilter, signalFilter, ponFilter, search, sortField, sortDir, matchedRefs, duplicateSnRefs]);

    const deleteDuplicateGroup = useMemo(() => {
        if (!deleteTarget?.serial_number) return null;
        const sn = normalizeSerial(deleteTarget.serial_number);
        if (!sn) return null;
        return snDuplicateGroups.find(group => group.serial === sn) ?? null;
    }, [deleteTarget, snDuplicateGroups]);

    const deleteMigrationPair = useMemo(() => {
        if (!deleteTarget) return null;
        const ref = deleteTarget.onu_ref.toLowerCase();
        return fhttMigrationPairs.find(
            pair => pair.onuLama?.onu_ref.toLowerCase() === ref || pair.onuBaru?.onu_ref.toLowerCase() === ref,
        ) ?? null;
    }, [deleteTarget, fhttMigrationPairs]);

    const migrationPairByRef = useMemo(() => {
        const m = new Map<string, FhttMigrationPair>();
        fhttMigrationPairs.forEach(pair => {
            if (pair.onuLama) m.set(pair.onuLama.onu_ref.toLowerCase(), pair);
            if (pair.onuBaru) m.set(pair.onuBaru.onu_ref.toLowerCase(), pair);
        });
        return m;
    }, [fhttMigrationPairs]);

    const totalPages = Math.max(1, Math.ceil(filtered.length / PER_PAGE));
    const paged = filtered.slice((page - 1) * PER_PAGE, page * PER_PAGE);
    useEffect(() => { setPage(1); }, [snapshotFilter, signalFilter, ponFilter, search, sortField, sortDir]);

    useEffect(() => {
        if (snapshotFilter === 'sn_double' && stats.fhttMigrationPairs === 0 && stats.identicalSnGroups === 0) {
            setSnapshotFilter('all');
        }
    }, [snapshotFilter, stats.fhttMigrationPairs, stats.identicalSnGroups]);

    useEffect(() => {
        if (!olt.last_gpon_snapshot || !Array.isArray(olt.last_gpon_snapshot.onus)) {
            return;
        }
        const propCount = olt.last_gpon_snapshot.onus.length;
        const stateCount = snapshot?.onus.length ?? 0;
        if (propCount < stateCount) {
            applyGponSnapshot(olt.last_gpon_snapshot);
        }
    }, [olt.last_gpon_snapshot, snapshot?.onus.length, applyGponSnapshot]);

    const toggleSort = (f: SortField) => {
        if (sortField === f) setSortDir(d => d === 'asc' ? 'desc' : 'asc');
        else { setSortField(f); setSortDir('asc'); }
    };

    const SortIcon = ({ field }: { field: SortField }) =>
        sortField !== field ? <ArrowUpDown className="ml-1 h-3 w-3 opacity-40" /> :
        sortDir === 'asc' ? <ArrowUp className="ml-1 h-3 w-3" /> : <ArrowDown className="ml-1 h-3 w-3" />;

    const apiHeaders = () => ({
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
    });

    const openDeleteDialog = (onu: GponOnuSnapshot) => {
        if (huaweiWriteLocked) {
            toast.error(HUAWEI_WRITE_LOCKED_TITLE);
            return;
        }
        setDeleteTarget(onu);
        setDeleteConfirmOnline(false);
        setDeleteConfirmation('');
        setDeleteGuard(null);
        setDeleteOverrideReason('');
        setDeleteOverrideChecked(false);
        setDeleteDialogOpen(true);
    };

    const closeDeleteDialog = () => {
        if (deleteSubmitting) return;
        setDeleteDialogOpen(false);
        setDeleteTarget(null);
        setDeleteConfirmOnline(false);
        setDeleteConfirmation('');
        setDeleteGuard(null);
        setDeleteOverrideReason('');
        setDeleteOverrideChecked(false);
    };

    const executeDeleteOnu = async () => {
        if (!deleteTarget) return;
        const ref = deleteTarget.onu_ref;
        const online = isOnuOnline(deleteTarget.state, deleteTarget.phase_state, deleteTarget.rx_power_dbm);
        if (online && !deleteConfirmOnline) {
            toast.error('Centang konfirmasi karena ONU masih online.');
            return;
        }
        if (deleteConfirmation !== 'DELETE ONU') {
            toast.error('Ketik DELETE ONU untuk mengonfirmasi penghapusan.');
            return;
        }

        const overriding = deleteGuard?.canOverride === true && deleteOverrideChecked;
        if (overriding && deleteOverrideReason.trim().length < (deleteGuard?.reasonMin ?? 20)) {
            toast.error(`Alasan override minimal ${deleteGuard?.reasonMin ?? 20} karakter.`);
            return;
        }

        setDeleteSubmitting(true);
        setActionLoading(ref);
        try {
            const fetchOpts = { headers: apiHeaders(), credentials: 'include' as const };
            const deleteUrl = `${route('api.olts.onu.delete', olt.id)}?onu_ref=${encodeURIComponent(ref)}`;
            const r = await fetch(deleteUrl, {
                method: 'DELETE',
                ...fetchOpts,
                body: JSON.stringify({
                    onu_ref: ref,
                    live_confirm: deleteConfirmation,
                    ...(overriding ? { override: true, override_reason: deleteOverrideReason.trim() } : {}),
                }),
            });

            let data: Record<string, unknown> = {};
            try {
                data = await r.json();
            } catch {
                if (r.status === 504 || r.status === 502) {
                    toast.warning(`Gateway timeout (${r.status}) — operasi SSH mungkin masih berjalan. Memuat ulang snapshot...`);
                    closeDeleteDialog();
                    void refreshSnapshotAfterDelete(ref);
                    return;
                }
                throw new Error('Respons server tidak valid');
            }

            if (r.status === 409 && data.error_code === 'lifecycle_guard_denied') {
                // Lifecycle guard: ONU masih dipakai / status tidak diketahui. Tidak ada perintah dikirim ke OLT.
                setDeleteGuard({
                    message: (data.message as string) || 'ONU masih dipakai.',
                    canOverride: data.can_override === true,
                    reasonMin: typeof data.override_reason_min === 'number' ? data.override_reason_min : 20,
                });
                setDeleteOverrideChecked(false);
                toast.error('ONU tidak dihapus: pagar keamanan menahan penghapusan.');
                return;
            }

            if (!r.ok && Array.isArray(data.rebuild_hint) && data.rebuild_hint.length > 0) {
                // Service-port sudah ada yang terhapus tetapi ONU belum: jangan auto-dismiss, tampilkan petunjuk.
                toast.error((data.message as string) || 'Sebagian service-port sudah dihapus, ONU belum terhapus.', {
                    duration: Infinity,
                    closeButton: true,
                    description: (data.rebuild_hint as string[]).join('\n'),
                    style: { whiteSpace: 'pre-wrap' },
                });
                return;
            }

            if (!r.ok) {
                const validationMsg = (data?.errors as Record<string, string[]> | undefined)?.onu_ref?.[0];
                throw new Error((data.error as string) || validationMsg || (data.message as string) || 'Gagal menghapus ONU');
            }

            if (!applyGponSnapshot(data.gpon_snapshot as GponSnapshot | undefined)) {
                setSnapshot(prev => (prev ? removeOnuFromSnapshot(prev, ref) : prev));
            }

            if (data.save_pending === true) setSavePending(true);
            const warnings = Array.isArray(data.warnings) ? data.warnings as string[] : [];
            const remaining = (data.gpon_snapshot as GponSnapshot | undefined)?.onus?.length;
            if (warnings.length > 0) {
                toast.success(`ONU ${ref} dihapus (DB: ${data.db_purged ?? 0} baris)`, {
                    description: warnings.join(' '),
                });
            } else {
                toast.success(`ONU ${ref} berhasil dihapus dari OLT`, {
                    description: typeof remaining === 'number' ? `Sisa ${remaining} ONU di snapshot` : undefined,
                });
            }

            closeDeleteDialog();
            void refreshSnapshotAfterDelete(ref);
        } catch (e) {
            toast.error(e instanceof Error ? e.message : 'Gagal menghapus ONU');
        } finally {
            setDeleteSubmitting(false);
            setActionLoading(null);
        }
    };

    const patchOnuNameInSnapshot = (ref: string, name: string) => {
        const key = ref.toLowerCase();
        setSnapshot((prev) => {
            if (!prev) return prev;
            return {
                ...prev,
                onus: prev.onus.map((o) =>
                    o.onu_ref.toLowerCase() === key ? { ...o, onu_name: name } : o,
                ),
            };
        });
        setDetailPayload((prev) => {
            if (!prev || prev.onu_ref.toLowerCase() !== key) return prev;
            return { ...prev, onu: { ...prev.onu, onu_name: name } };
        });
    };

    /** Huawei: saran nama = PPPoE pelanggan yang cocok (peringatan saja, tidak memblokir). */
    const huaweiRenameSuggestion = (ref: string): string => {
        const pppoe = matchedMap.get(ref.toLowerCase())?.pppoe_user;
        return pppoe ? normalizeOnuDisplayName(pppoe) : '';
    };

    const openRenameDialog = (onu: GponOnuSnapshot) => {
        if (huaweiWriteLocked) {
            toast.error(HUAWEI_WRITE_LOCKED_TITLE);
            return;
        }
        setRenameTarget(onu);
        // Huawei: prefill the CURRENT name; the PPPoE suggestion is shown separately (click to use).
        setRenameName(onu.onu_name || '');
        setRenameConfirmation('');
        setRenameDialogOpen(true);
    };

    const submitRenameOnu = async () => {
        if (!renameTarget) return;
        const name = normalizeOnuDisplayName(renameName);
        if (!name) {
            toast.error('Nama tidak valid');
            return;
        }
        if (renameConfirmation !== 'RENAME ONU') {
            toast.error('Ketik RENAME ONU untuk mengonfirmasi perubahan.');
            return;
        }
        const ref = renameTarget.onu_ref;
        setRenameSubmitting(true);
        setActionLoading(ref);
        try {
            const r = await fetch(route('api.olts.onu.rename', olt.id), {
                method: 'POST',
                headers: apiHeaders(),
                credentials: 'include',
                body: JSON.stringify({
                    onu_ref: ref,
                    sn: renameTarget.serial_number || undefined,
                    name,
                    sync_acs: true,
                    live_confirm: renameConfirmation,
                }),
            });
            const d = await r.json().catch(() => null);
            if (d === null && (r.status === 502 || r.status === 504)) {
                toast.warning(`Gateway timeout (${r.status}) — operasi masih berjalan. Memuat ulang snapshot...`);
                setRenameDialogOpen(false);
                setRenameTarget(null);
                void refreshSnapshotAfterDelete(ref);
                return;
            }
            if (d === null) throw new Error('Respons server tidak valid');
            if (!r.ok || d.error) {
                throw new Error(d.error || d.message || 'Gagal rename ONU');
            }
            const finalName = (d.name as string) || name;
            patchOnuNameInSnapshot(ref, finalName);
            if (d.save_pending === true) setSavePending(true);
            if (Array.isArray(d.warnings) && d.warnings.length > 0) {
                toast.warning('Peringatan rename', { description: (d.warnings as string[]).join(' ') });
            }
            setRenameDialogOpen(false);
            setRenameTarget(null);
            if (d.acs_synced) {
                toast.success(`Nama OLT + ACS: ${finalName}`, {
                    description: d.message || ref,
                });
            } else {
                toast.success(`Nama OLT: ${finalName}`, {
                    description: d.acs_tag?.message
                        ? `ACS: ${d.acs_tag.message}`
                        : 'ACS tag diantrikan / pending',
                });
            }
        } catch (e) {
            toast.error(e instanceof Error ? e.message : 'Gagal rename ONU');
        } finally {
            setRenameSubmitting(false);
            setActionLoading(null);
        }
    };

    const openRebootDialog = (ref: string) => {
        if (huaweiWriteLocked) {
            toast.error(HUAWEI_WRITE_LOCKED_TITLE);
            return;
        }
        setRebootTarget(ref);
        setRebootDialogOpen(true);
    };

    // S4: `liveConfirm` = teks yang diketik operator di dialog; server mencocokkan frasa per aksi.
    const onuAction = async (action: string, ref: string, liveConfirm?: string) => {
        setActionLoading(ref);
        try {
            const hdrs = apiHeaders();
            const fetchOpts = { headers: hdrs, credentials: 'include' as const };
            if (action === 'reboot') {
                const r = await fetch(route('api.olts.onu.reboot', olt.id), { method: 'POST', ...fetchOpts, body: JSON.stringify({ onu_ref: ref, live_confirm: liveConfirm }) });
                const rd = await r.json().catch(() => ({} as Record<string, unknown>));
                if (!r.ok && (r.status === 502 || r.status === 504)) {
                    toast.warning(`Gateway timeout (${r.status}) — operasi masih berjalan. Memuat ulang snapshot...`);
                    void refreshSnapshotAfterDelete(ref);
                    return;
                }
                if (!r.ok) { throw new Error((rd.error as string) || (rd.message as string) || 'Gagal reboot ONU'); }
                toast.success('Reboot ONU ' + ref + ' berhasil');
            } else if (action === 'detail') {
                setDetailLoading(true);
                setDetailDialogOpen(true);
                setDetailPayload(null);
                const r = await fetch(route('api.olts.onu.detail', olt.id), { method: 'POST', ...fetchOpts, body: JSON.stringify({ onu_ref: ref }) });
                const d = await r.json();
                if (!r.ok) throw new Error(d.error || d.message || 'Gagal memuat detail ONU');
                const onu = (d.onu ?? d) as GponOnuSnapshot;
                // Prefer API matched (enriched) then snapshot map
                const matchedFromApi = d.matched && d.matched.customer_id
                    ? (d.matched as MatchedPelangganSnapshot)
                    : undefined;
                setDetailPayload({
                    onu_ref: ref,
                    onu,
                    matched: matchedFromApi ?? matchedMap.get(ref.toLowerCase()),
                });
            }
        } catch (e) {
            toast.error(e instanceof Error ? e.message : 'Unknown error');
            if (action === 'detail') setDetailDialogOpen(false);
        }
        finally {
            setActionLoading(null);
            if (action === 'detail') setDetailLoading(false);
        }
    };

    /** Huawei: ulangi hanya `save` setelah hasil "save tertunda". */
    const submitSaveConfig = async () => {
        if (saveConfirmation !== 'SAVE OLT') {
            toast.error('Ketik SAVE OLT untuk mengonfirmasi.');
            return;
        }
        setSaveSubmitting(true);
        try {
            const r = await fetch(route('api.olts.save-config', olt.id), {
                method: 'POST',
                headers: apiHeaders(),
                credentials: 'include',
                body: JSON.stringify({ live_confirm: saveConfirmation }),
            });
            const d = await r.json().catch(() => ({} as Record<string, unknown>));
            if (!r.ok && (r.status === 502 || r.status === 504)) {
                toast.warning(`Gateway timeout (${r.status}) — operasi masih berjalan; muat ulang halaman untuk status simpan.`);
                return;
            }
            if (!r.ok || d.success !== true) {
                throw new Error((d.error as string) || (d.message as string) || 'Gagal menyimpan konfigurasi OLT');
            }
            setSavePending(false);
            setSaveConfirmation('');
            toast.success('Konfigurasi OLT tersimpan');
        } catch (e) {
            toast.error(e instanceof Error ? e.message : 'Gagal menyimpan konfigurasi OLT');
        } finally {
            setSaveSubmitting(false);
        }
    };

    const renameSuggestion = isHuawei && renameTarget ? huaweiRenameSuggestion(renameTarget.onu_ref) : '';
    const renameNormalized = normalizeOnuDisplayName(renameName);
    const renameMismatchWarning = isHuawei && renameSuggestion !== '' && renameNormalized !== '' && renameNormalized !== renameSuggestion
        ? `Berbeda dari PPPoE pelanggan (${renameSuggestion}). Tetap bisa disimpan, tetapi pencocokan pelanggan berdasarkan nama bisa berubah.`
        : null;

    const deleteParsed = deleteTarget ? parseOnuRef(deleteTarget.onu_ref) : null;
    const deleteMatched = deleteTarget ? matchedMap.get(deleteTarget.onu_ref.toLowerCase()) : undefined;
    const deleteIsOnline = deleteTarget ? isOnuOnline(deleteTarget.state, deleteTarget.phase_state, deleteTarget.rx_power_dbm) : false;

    const statusCards = [
        { key: 'all' as SnapshotFilter, label: 'Total ONU', count: stats.total, icon: Boxes, color: 'text-status-info' },
        { key: 'online' as SnapshotFilter, label: 'Online', count: stats.online, icon: Zap, color: 'text-status-up' },
        { key: 'offline' as SnapshotFilter, label: 'Offline', count: stats.offline, icon: AlertCircle, color: 'text-status-down' },
        { key: 'matched' as SnapshotFilter, label: 'Matched', count: stats.matched, icon: Network, color: 'text-status-up' },
        { key: 'unmatched' as SnapshotFilter, label: 'Belum Match', count: stats.unmatched, icon: Boxes, color: 'text-status-degraded' },
        { key: 'match_conflict' as SnapshotFilter, label: 'Match Conflict', count: stats.matchConflict, icon: ShieldAlert, color: 'text-status-degraded' },
        { key: 'sn_double' as SnapshotFilter, label: 'SN Double', count: stats.snDoubleOnus, icon: Copy, color: 'text-status-down', badge: stats.fhttMigrationPairs },
    ];

    const signalPills = [
        { key: 'all' as SignalFilter, label: 'Semua', count: stats.total },
        { key: 'good' as SignalFilter, label: 'Good', count: stats.good },
        { key: 'weak' as SignalFilter, label: 'Lemah', count: stats.weak },
        { key: 'loss' as SignalFilter, label: 'Loss', count: stats.loss },
        { key: 'rx_missing' as SignalFilter, label: 'RX Missing', count: stats.rxMissing },
        { key: 'no_signal' as SignalFilter, label: 'Offline', count: stats.noSig },
    ];

    return (
        <OltErrorBoundary>
        <EnterpriseResourceLayout
            title={olt.name}
            pageTitle={`OLT: ${olt.name}`}
            subtitle={`${olt.code} · ${olt.vendor || 'ZTE'} · Enterprise GPON`}
            icon={Network}
            backHref={route('olts.index')}
            breadcrumbs={[
                { label: 'OLTs', href: route('olts.index') },
                { label: olt.name },
            ]}
            cachedAt={snapshot?.meta?.collected_at ?? olt.last_gpon_synced_at}
            cacheLabel="GPON Snapshot"
            freshMinutes={360}
            activeTab={pageTab}
            onTabChange={(id) => setPageTab(id as 'detail' | 'gpon')}
            tabs={[
                { id: 'gpon', label: 'GPON Snapshot', badge: stats.total || null },
                { id: 'detail', label: 'Detail OLT' },
            ]}
            actions={
                <div className="flex flex-wrap items-center gap-2">
                    <Button variant="outline" size="sm" className="h-8 gap-2" onClick={() => router.post(route('olts.test', olt.id))}>
                        <PlugZap className="h-3.5 w-3.5" /> Test
                    </Button>
                    <Button variant="outline" size="sm" className="h-8 gap-2" onClick={syncGponSnapshot} disabled={snapshotLoading}>
                        <RefreshCw className={`h-3.5 w-3.5 ${snapshotLoading ? 'animate-spin' : ''}`} /> Refresh
                    </Button>
                    <Button asChild variant="outline" size="sm" className="h-8 gap-2">
                        <Link href={route('olts.edit', olt.id)}><Edit className="h-3.5 w-3.5" /> Edit</Link>
                    </Button>
                    <Button variant="destructive" size="sm" className="h-8 gap-2" onClick={() => setConfirmOpen(true)}>
                        <Trash2 className="h-3.5 w-3.5" /> Hapus
                    </Button>
                </div>
            }
            statusStrip={
                snapshot?.meta?.warning ? (
                    <div className="rounded-md border border-status-degraded/30 bg-status-degraded-bg px-3 py-2 text-sm text-status-degraded">{snapshot.meta.warning}</div>
                ) : null
            }
        >
            <div className="mb-3 flex justify-end">
                <Button asChild variant="outline" size="sm" className="h-8 gap-2">
                    <a href={route('olts.export', olt.id)} download>
                        <Download className="h-3.5 w-3.5" /> Export Excel OLT {olt.name}
                    </a>
                </Button>
            </div>
            <div className="mx-auto max-w-7xl space-y-6">
                    {pageTab === 'detail' && (
                    <>
                    {/* OLT Quick Stats Cards */}
                    <div className="grid gap-4 md:grid-cols-4">
                        <Card className="border-l-4 border-l-status-info">
                            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                                <CardTitle className="text-sm font-medium text-muted-foreground">Kode OLT</CardTitle>
                                <Boxes className="h-4 w-4 text-muted-foreground" />
                            </CardHeader>
                            <CardContent><div className="font-mono text-2xl font-bold">{olt.code}</div></CardContent>
                        </Card>
                        <Card className="border-l-4 border-l-status-up">
                            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                                <CardTitle className="text-sm font-medium text-muted-foreground">IP & Koneksi</CardTitle>
                                <Network className="h-4 w-4 text-muted-foreground" />
                            </CardHeader>
                            <CardContent>
                                <div className="font-mono text-lg font-bold">{olt.management_ip || '-'}</div>
                                <div className="text-xs text-muted-foreground mt-0.5">
                                    {olt.management_protocol?.toUpperCase() || '-'} port {olt.management_port || '-'}
                                </div>
                            </CardContent>
                        </Card>
                        <Card className="border-l-4 border-l-status-degraded">
                            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                                <CardTitle className="text-sm font-medium text-muted-foreground">Area</CardTitle>
                                <MapPin className="h-4 w-4 text-muted-foreground" />
                            </CardHeader>
                            <CardContent><div className="text-lg font-bold">{olt.area?.name || '-'}</div></CardContent>
                        </Card>
                        <Card className="border-l-4 border-l-primary">
                            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                                <CardTitle className="text-sm font-medium text-muted-foreground">Router</CardTitle>
                                <RouterIcon className="h-4 w-4 text-muted-foreground" />
                            </CardHeader>
                            <CardContent><div className="text-lg font-bold">{olt.router?.name || '-'}</div></CardContent>
                        </Card>
                    </div>

                    {/* OLT Details */}
                    <DetailSection title="Detail OLT">
                        <dl className="grid gap-4 md:grid-cols-2">
                            <div><dt className="text-sm text-muted-foreground">Nama</dt><dd className="mt-1 font-medium">{olt.name}</dd></div>
                            <div><dt className="text-sm text-muted-foreground">Lokasi</dt><dd className="mt-1 font-medium">{olt.location || '-'}</dd></div>
                            <div><dt className="text-sm text-muted-foreground">IP Management</dt><dd className="mt-1 font-mono font-medium">{olt.management_ip || '-'}</dd></div>
                            <div><dt className="text-sm text-muted-foreground">Protokol</dt><dd className="mt-1 font-medium uppercase">{olt.management_protocol || '-'}</dd></div>
                            <div><dt className="text-sm text-muted-foreground">Port</dt><dd className="mt-1 font-mono font-medium">{olt.management_port || '-'}</dd></div>
                            <div><dt className="text-sm text-muted-foreground">Username</dt><dd className="mt-1 font-medium">{olt.username || '-'}</dd></div>
                            <div><dt className="text-sm text-muted-foreground">SNMP Community</dt><dd className="mt-1 font-medium">{olt.snmp_community_configured ? 'Configured' : 'Not configured'}</dd></div>
                            <div><dt className="text-sm text-muted-foreground">Kode</dt><dd className="mt-1 font-mono font-medium">{olt.code}</dd></div>
                            <div><dt className="text-sm text-muted-foreground">Vendor</dt><dd className="mt-1 font-medium uppercase">{olt.vendor?.replace('_', ' ') || '-'}</dd></div>
                        </dl>
                    </DetailSection>

                    <DetailSection title="Catatan">
                        <p className="whitespace-pre-wrap text-sm text-foreground">{olt.notes || 'Belum ada catatan untuk OLT ini.'}</p>
                    </DetailSection>
                    </>
                    )}

                    {pageTab === 'gpon' && (
                    <DetailSection title="Data GPON Snapshot">
                        <div className="space-y-6">
                            {snapshotError && (
                                <div className="rounded-xl border border-destructive/30 bg-destructive/5 p-4 text-sm text-destructive">
                                    <div className="flex items-center gap-2 font-medium">
                                        <AlertCircle className="h-4 w-4" /> {snapshotError}
                                    </div>
                                </div>
                            )}

                            {snapshot?.meta.warning && (
                                <div className="rounded-xl border border-status-degraded/30 bg-status-degraded-bg p-4 text-sm text-status-degraded">
                                    {snapshot.meta.warning}
                                </div>
                            )}

                            {/* Redaman (dBm) Collection Status */}
                            {snapshot && stats.noSig === stats.total && olt.vendor === 'zte_c300' && (
                                <div className="rounded-xl border border-status-info/30 bg-status-info-bg p-4">
                                    <div className="flex items-center justify-between">
                                        <div>
                                            <div className="flex items-center gap-2 text-sm font-medium text-status-info">
                                                <Signal className="h-4 w-4" />
                                                Redaman (dBm) belum tersedia
                                            </div>
                                            <p className="mt-1 text-xs text-muted-foreground">
                                                Redaman diambil via <strong>SNMP bulk walk</strong> dari OLT (3 OID ZTE C300).
                                                Klik tombol di bawah untuk refresh SNMP + gap-fill per-index.
                                            </p>
                                            {rxPowerError && (
                                                <p className="mt-1 flex items-center gap-1 text-xs text-status-down"><AlertTriangle className="h-3.5 w-3.5" aria-hidden="true" /> {rxPowerError}</p>
                                            )}
                                        </div>
                                        <div className="flex gap-2">
                                            <Button
                                                variant="default" size="sm" className="h-7 gap-1.5 text-xs"
                                                onClick={refreshRxPower}
                                                disabled={actionLoading === 'rx-power'}
                                            >
                                                <RefreshCw className={`h-3 w-3 ${actionLoading === 'rx-power' ? 'animate-spin' : ''}`} />
                                                {actionLoading === 'rx-power' ? 'SNMP sync...' : <><RadioTower className="h-3 w-3" aria-hidden="true" /> Refresh Redaman (SNMP)</>}
                                            </Button>
                                        </div>
                                    </div>
                                    {actionLoading === 'rx-power' && (
                                        <div className="mt-2 text-xs text-muted-foreground">
                                            SNMP walk + gap-fill untuk {stats.total} ONU... cek otomatis tiap 5 detik
                                        </div>
                                    )}
                                </div>
                            )}

                            {!snapshot && !snapshotLoading && (
                                <p className="text-sm text-muted-foreground">
                                    Menunggu data dari OLT. Sistem akan otomatis mengambil daftar ONU, status online/offline, redaman sinyal, dan jarak fiber.
                                </p>
                            )}

                            {snapshotLoading && !snapshot && (
                                <div className="flex items-center gap-3 text-sm text-muted-foreground">
                                    <RefreshCw className="h-4 w-4 animate-spin" /> Mengambil data dari OLT...
                                </div>
                            )}

                            {snapshot && (
                                <>
                                    {/* Status Stats Cards */}
                                    <div className="grid gap-3 grid-cols-2 md:grid-cols-3 xl:grid-cols-6">
                                        {statusCards.map((card) => {
                                            const Icon = card.icon;
                                            const groupBadge = 'badge' in card ? card.badge : null;
                                            return (
                                                <button
                                                    key={card.key}
                                                    type="button"
                                                    onClick={() => setSnapshotFilter(card.key)}
                                                    className={`rounded-xl border p-4 text-left shadow-sm transition hover:border-primary/50 ${
                                                        snapshotFilter === card.key ? 'border-primary ring-2 ring-primary/15 bg-primary/5' : 'border-border/60 bg-card'
                                                    }`}
                                                >
                                                    <div className="flex items-center justify-between">
                                                        <span className="text-xs font-medium text-muted-foreground">{card.label}</span>
                                                        <Icon className={`h-4 w-4 ${card.color}`} />
                                                    </div>
                                                    <div className={`mt-2 text-2xl font-bold ${card.color}`}>{card.count}</div>
                                                    {groupBadge != null && groupBadge > 0 && (
                                                        <p className="mt-1 text-[10px] text-muted-foreground">{groupBadge} migrasi FHTT</p>
                                                    )}
                                                </button>
                                            );
                                        })}
                                    </div>

                                    {stats.snDoubleGroups > 0 && snapshotFilter !== 'sn_double' && (
                                        <div className="rounded-xl border border-status-down/30 bg-status-down-bg p-4">
                                            <div className="flex flex-wrap items-center justify-between gap-3">
                                                <div>
                                                    <div className="flex items-center gap-2 text-sm font-medium text-status-down">
                                                        <ShieldAlert className="h-4 w-4" />
                                                        {stats.fhttMigrationPairs} migrasi FHTT (SN lama → SN baru) · {stats.identicalSnGroups} SN identik
                                                    </div>
                                                    <p className="mt-1 text-xs text-muted-foreground">
                                                        Tampilkan pasangan SN lama (ZTE/CIOT) dan SN FHTT baru per pelanggan supaya hapus ghost tidak salah port.
                                                    </p>
                                                </div>
                                                <Button variant="outline" size="sm" className="h-8 gap-1.5" onClick={() => setSnapshotFilter('sn_double')}>
                                                    <Copy className="h-3.5 w-3.5" /> Lihat SN Double
                                                </Button>
                                            </div>
                                        </div>
                                    )}

                                    {/* Signal Filter Pills */}
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="text-xs font-medium text-muted-foreground mr-1">Kualitas Sinyal:</span>
                                        {signalPills.map((pill) => (
                                            <button
                                                key={pill.key}
                                                type="button"
                                                onClick={() => setSignalFilter(pill.key)}
                                                className={`inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium transition ${
                                                    signalFilter === pill.key
                                                        ? 'border-primary bg-primary/10 text-primary'
                                                        : 'border-border/60 bg-card text-muted-foreground hover:border-primary/40'
                                                }`}
                                            >
                                                {pill.label}
                                                <span className={`ml-0.5 rounded-full px-1.5 py-0.5 text-[10px] ${
                                                    signalFilter === pill.key ? 'bg-primary/20 text-primary' : 'bg-muted text-muted-foreground'
                                                }`}>{pill.count}</span>
                                            </button>
                                        ))}
                                    </div>

                                    {/* Toolbar: Search + PON Filter */}
                                    <div className="flex flex-col sm:flex-row gap-3">
                                        <div className="relative flex-1">
                                            <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                                            <Input
                                                placeholder="Cari ref ONU, nama, serial number, PON port..."
                                                value={search}
                                                onChange={(e) => setSearch(e.target.value)}
                                                className="pl-9 h-9"
                                            />
                                        </div>
                                        <Select value={ponFilter} onValueChange={setPonFilter}>
                                            <SelectTrigger className="w-full sm:w-[200px] h-9">
                                                <SelectValue placeholder="PON Port" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="all">Semua PON Port</SelectItem>
                                                {ponPorts.map((p) => (
                                                    <SelectItem key={p} value={p}>{p}</SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    {/* Snapshot meta */}
                                    {olt.last_gpon_synced_at && (
                                        <div className="flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground">
                                            <span>
                                                Data terakhir: {formatDateTime(olt.last_gpon_synced_at)}. Protokol {snapshot.meta.protocol.toUpperCase()}, PON aktif {snapshot.meta.pon_ports_count}.
                                            </span>
                                            <span className="font-medium">
                                                {snapshotFilter === 'sn_double'
                                                    ? `${stats.fhttMigrationPairs} migrasi FHTT · ${stats.identicalSnGroups} SN identik · ${filtered.length} entri`
                                                    : `Menampilkan ${filtered.length} dari ${snapshot.onus.length} ONU`}
                                            </span>
                                        </div>
                                    )}

                                    {snapshotFilter === 'sn_double' && fhttMigrationPairs.length === 0 && snDuplicateGroups.length === 0 && (
                                        <div className="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground">
                                            Tidak ada migrasi FHTT (SN lama → SN baru) atau SN identik terdeteksi di snapshot ini.
                                        </div>
                                    )}

                                    {snapshotFilter === 'sn_double' && (fhttMigrationPairs.length > 0 || snDuplicateGroups.length > 0) && (
                                        <div className="space-y-4">
                                            <div className="rounded-xl border border-status-down/30 bg-status-down-bg p-4 text-sm text-foreground">
                                                <p className="font-medium">Panduan hapus SN double / migrasi FHTT</p>
                                                <ul className="mt-2 list-disc space-y-1 pl-5 text-xs text-muted-foreground">
                                                    <li><strong className="text-status-down">SN Lama</strong> — port lama, biasanya ZTE/CIOT offline (ghost) → <strong>hapus ini</strong>.</li>
                                                    <li><strong className="text-status-up">SN FHTT Baru</strong> — port baru, online → <strong>jangan hapus</strong>.</li>
                                                    <li><strong className="text-status-degraded">SN identik</strong> — serial sama di banyak port, cek status & pelanggan sebelum hapus.</li>
                                                </ul>
                                            </div>

                                            {fhttMigrationPairs.length > 0 && (
                                                <div className="rounded-xl border border-status-degraded/30 overflow-hidden">
                                                    <div className="border-b border-status-degraded/30 bg-status-degraded-bg px-4 py-3">
                                                        <p className="text-sm font-semibold text-foreground">
                                                            Migrasi FHTT — SN Lama vs SN Baru ({fhttMigrationPairs.length} pelanggan)
                                                        </p>
                                                    </div>
                                                    <Table>
                                                        <TableHeader>
                                                            <TableRow>
                                                                <TableHead>Pelanggan / PPPoE</TableHead>
                                                                <TableHead>SN Lama (port lama)</TableHead>
                                                                <TableHead>SN FHTT Baru (port baru)</TableHead>
                                                                <TableHead className="text-right">Aksi</TableHead>
                                                            </TableRow>
                                                        </TableHeader>
                                                        <TableBody>
                                                            {fhttMigrationPairs.map((pair) => {
                                                                const lamaLoading = pair.onuLama && actionLoading === pair.onuLama.onu_ref;
                                                                const baruLoading = pair.onuBaru && actionLoading === pair.onuBaru.onu_ref;
                                                                return (
                                                                    <TableRow key={pair.key}>
                                                                        <TableCell>
                                                                            <div className="text-xs font-medium">{pair.displayName}</div>
                                                                            <div className="font-mono text-[10px] text-muted-foreground">{pair.pppoe}</div>
                                                                            {pair.customer && (
                                                                                <Link href={route('customers.show', pair.customer.customer_id)} className="text-[10px] text-primary hover:underline">
                                                                                    Lihat pelanggan
                                                                                </Link>
                                                                            )}
                                                                        </TableCell>
                                                                        <TableCell className="bg-status-down-bg/60">
                                                                            <div className="font-mono text-xs font-semibold text-status-down">{pair.snLama || '—'}</div>
                                                                            <div className="font-mono text-[10px] text-muted-foreground">{pair.portLama || '—'}</div>
                                                                            <Badge variant="secondary" className="mt-1 text-[9px]">
                                                                                {pair.onuLama && isOnuOnline(pair.onuLama.state, pair.onuLama.phase_state, pair.onuLama.rx_power_dbm) ? 'online' : 'offline'}
                                                                            </Badge>
                                                                        </TableCell>
                                                                        <TableCell className="bg-status-up-bg/60">
                                                                            <div className="font-mono text-xs font-semibold text-status-up">{pair.snBaru || '—'}</div>
                                                                            <div className="font-mono text-[10px] text-muted-foreground">{pair.portBaru || '—'}</div>
                                                                            <Badge variant="default" className="mt-1 text-[9px]">
                                                                                {pair.onuBaru && isOnuOnline(pair.onuBaru.state, pair.onuBaru.phase_state, pair.onuBaru.rx_power_dbm) ? 'online' : 'offline'}
                                                                            </Badge>
                                                                        </TableCell>
                                                                        <TableCell className="text-right">
                                                                            <div className="flex flex-col items-end gap-1">
                                                                                {pair.onuLama && (
                                                                                    <Button
                                                                                        variant="destructive"
                                                                                        size="sm"
                                                                                        className="h-7 gap-1 text-xs"
                                                                                        onClick={() => openDeleteDialog(pair.onuLama!)}
                                                                                        disabled={!!lamaLoading}
                                                                                    >
                                                                                        <Trash className="h-3 w-3" /> Hapus SN Lama
                                                                                    </Button>
                                                                                )}
                                                                                {pair.onuBaru && (
                                                                                    <Button
                                                                                        variant="outline"
                                                                                        size="sm"
                                                                                        className="h-7 gap-1 text-xs border-status-up/30 text-status-up"
                                                                                        onClick={() => openDeleteDialog(pair.onuBaru!)}
                                                                                        disabled={!!baruLoading}
                                                                                    >
                                                                                        Hapus SN Baru
                                                                                    </Button>
                                                                                )}
                                                                            </div>
                                                                        </TableCell>
                                                                    </TableRow>
                                                                );
                                                            })}
                                                        </TableBody>
                                                    </Table>
                                                </div>
                                            )}

                                            {snDuplicateGroups.length > 0 && (
                                                <div className="rounded-xl border border-status-down/30 px-4 py-3 bg-status-down-bg/60">
                                                    <p className="text-sm font-semibold text-foreground">
                                                        SN Identik di Banyak Port ({snDuplicateGroups.length} grup)
                                                    </p>
                                                </div>
                                            )}
                                            {snDuplicateGroups.map((group) => (
                                                <div key={group.serial} className="rounded-xl border border-status-down/30 overflow-hidden">
                                                    <div className="flex flex-wrap items-center justify-between gap-2 border-b border-status-down/30 bg-status-down-bg px-4 py-3">
                                                        <div>
                                                            <p className="text-xs text-muted-foreground">Serial Number</p>
                                                            <p className="font-mono text-sm font-semibold">{group.serial}</p>
                                                        </div>
                                                        <div className="flex flex-wrap gap-2 text-[10px]">
                                                            <Badge variant="outline">{group.onus.length} port</Badge>
                                                            <Badge variant="outline">{group.onlineCount} online</Badge>
                                                            <Badge variant="outline">{group.matchedCount} matched</Badge>
                                                        </div>
                                                    </div>
                                                    <Table>
                                                        <TableHeader>
                                                            <TableRow>
                                                                <TableHead>Rekomendasi</TableHead>
                                                                <TableHead>PON / ONU Ref</TableHead>
                                                                <TableHead>Nama ONU</TableHead>
                                                                <TableHead>Status</TableHead>
                                                                <TableHead>Pelanggan</TableHead>
                                                                <TableHead className="text-right">Aksi</TableHead>
                                                            </TableRow>
                                                        </TableHeader>
                                                        <TableBody>
                                                            {group.onus.map((onu) => {
                                                                const matched = matchedMap.get(onu.onu_ref.toLowerCase());
                                                                const hint = deleteHintForOnu(onu, matched);
                                                                const hintStyle = deleteHintLabel(hint);
                                                                const isLoading = actionLoading === onu.onu_ref;
                                                                return (
                                                                    <TableRow key={onu.onu_ref} className={hint === 'delete' ? 'bg-status-down-bg/60' : hint === 'keep' ? 'bg-status-up-bg/60' : ''}>
                                                                        <TableCell>
                                                                            <span className={`inline-flex rounded-md border px-2 py-0.5 text-[10px] font-medium ${hintStyle.className}`}>
                                                                                {hintStyle.label}
                                                                            </span>
                                                                        </TableCell>
                                                                        <TableCell>
                                                                            <div className="font-mono text-xs">{onu.pon_port || '-'}</div>
                                                                            <div className="font-mono text-xs font-semibold">{onu.onu_ref}</div>
                                                                        </TableCell>
                                                                        <TableCell className="text-xs max-w-[180px]">
                                                                            <button
                                                                                type="button"
                                                                                className="group inline-flex max-w-full items-center gap-1 text-left hover:text-primary"
                                                                                title="Rename ONU + ACS"
                                                                                onClick={() => openRenameDialog(onu)}
                                                                                disabled={isLoading}
                                                                            >
                                                                                <span className="truncate font-mono">{onu.onu_name || '—'}</span>
                                                                                <Pencil className="h-3 w-3 shrink-0 opacity-40 group-hover:opacity-80" />
                                                                            </button>
                                                                        </TableCell>
                                                                        <TableCell>
                                                                            <Badge variant={isOnuOnline(onu.state, onu.phase_state, onu.rx_power_dbm) ? 'default' : 'secondary'} className="text-[10px]">
                                                                                {isOnuOnline(onu.state, onu.phase_state, onu.rx_power_dbm) ? 'Online' : (onu.state || onu.phase_state || 'Offline')}
                                                                            </Badge>
                                                                        </TableCell>
                                                                        <TableCell>
                                                                            {matched ? (
                                                                                <Link href={route('customers.show', { customer: matched.customer_id })} className="text-xs text-primary hover:underline font-medium">
                                                                                    {matched.customer_name}
                                                                                </Link>
                                                                            ) : (
                                                                                <span className="text-xs text-muted-foreground">—</span>
                                                                            )}
                                                                        </TableCell>
                                                                        <TableCell className="text-right">
                                                                            <div className="flex justify-end gap-1">
                                                                                <Button
                                                                                    variant="outline"
                                                                                    size="sm"
                                                                                    className="h-7 gap-1 text-xs"
                                                                                    onClick={() => openRenameDialog(onu)}
                                                                                    disabled={isLoading}
                                                                                >
                                                                                    <Pencil className="h-3 w-3" /> Rename
                                                                                </Button>
                                                                                <Button
                                                                                    variant={hint === 'delete' ? 'destructive' : 'outline'}
                                                                                    size="sm"
                                                                                    className="h-7 gap-1 text-xs"
                                                                                    onClick={() => openDeleteDialog(onu)}
                                                                                    disabled={isLoading}
                                                                                >
                                                                                    <Trash className="h-3 w-3" /> Hapus
                                                                                </Button>
                                                                            </div>
                                                                        </TableCell>
                                                                    </TableRow>
                                                                );
                                                            })}
                                                        </TableBody>
                                                    </Table>
                                                </div>
                                            ))}
                                        </div>
                                    )}

                                    {/* ONU Table */}
                                    {snapshotFilter !== 'sn_double' && (
                                    <div
                                        className="rounded-xl border border-border/60 overflow-hidden"
                                        data-olt-gpon-ui="olt-gpon-filter-v2-safeRef-compareOnuPath"
                                    >
                                        <Table>
                                            <TableHeader>
                                                <TableRow>
                                                    <TableHead className="cursor-pointer select-none" onClick={() => toggleSort('pon_port')}>
                                                        <span className="inline-flex items-center">PON Port <SortIcon field="pon_port" /></span>
                                                    </TableHead>
                                                    <TableHead className="cursor-pointer select-none" onClick={() => toggleSort('onu_ref')}>
                                                        <span className="inline-flex items-center">ONU Ref <SortIcon field="onu_ref" /></span>
                                                    </TableHead>
                                                    <TableHead className="cursor-pointer select-none" onClick={() => toggleSort('onu_name')}>
                                                        <span className="inline-flex items-center">Nama ONU <SortIcon field="onu_name" /></span>
                                                    </TableHead>
                                                    <TableHead className="cursor-pointer select-none" onClick={() => toggleSort('state')}>
                                                        <span className="inline-flex items-center">Status <SortIcon field="state" /></span>
                                                    </TableHead>
                                                    <TableHead>Serial Number</TableHead>
                                                    <TableHead className="cursor-pointer select-none" onClick={() => toggleSort('rx_power_dbm')}>
                                                        <span className="inline-flex items-center">Redaman (dBm) <SortIcon field="rx_power_dbm" /></span>
                                                    </TableHead>
                                                    <TableHead className="cursor-pointer select-none" onClick={() => toggleSort('distance_m')}>
                                                        <span className="inline-flex items-center">Jarak Fiber (m) <SortIcon field="distance_m" /></span>
                                                    </TableHead>
                                                    <TableHead>Pelanggan</TableHead>
                                                    <TableHead className="text-right">Aksi</TableHead>
                                                </TableRow>
                                            </TableHeader>
                                            <TableBody>
                                                {paged.length === 0 ? (
                                                    <TableRow>
                                                        <TableCell colSpan={9} className="h-24 text-center text-muted-foreground">
                                                            Tidak ada ONU yang cocok dengan filter ini.
                                                        </TableCell>
                                                    </TableRow>
                                                ) : (
                                                    paged.map((onu) => {
                                                        const rxN = coerceRx(onu.rx_power_dbm);
                                                        const sq = sigQuality(rxN);
                                                        const refKey = safeRef(onu.onu_ref);
                                                        const matched = matchedMap.get(refKey);
                                                        const isLoading = actionLoading === onu.onu_ref;
                                                        const isSnDouble = duplicateSnRefs.has(refKey);
                                                        const migrationPair = migrationPairByRef.get(onu.onu_ref.toLowerCase());
                                                        const isMigrationOld = migrationPair?.onuLama?.onu_ref === onu.onu_ref;
                                                        const isMigrationNew = migrationPair?.onuBaru?.onu_ref === onu.onu_ref;
                                                        const displaySerial = onu.serial_number || (matched ? customerSerialByRef.get(normalizeOnuRefKey(refKey)) : null) || '-';
                                                        return (
                                                            <TableRow key={onu.onu_ref} className={
                                                                isMigrationOld ? 'bg-status-down-bg/60'
                                                                : isMigrationNew ? 'bg-status-up-bg/60'
                                                                : isSnDouble ? 'bg-status-down-bg/60' : ''
                                                            }>
                                                                <TableCell className="font-mono text-xs whitespace-nowrap">{onu.pon_port || '-'}</TableCell>
                                                                <TableCell className="font-mono text-xs font-medium">{onu.onu_ref}</TableCell>
                                                                <TableCell className="text-xs max-w-[200px]">
                                                                    <button
                                                                        type="button"
                                                                        className="group inline-flex max-w-full items-center gap-1 text-left hover:text-primary"
                                                                        title="Klik untuk rename ONU (+ tag ACS)"
                                                                        onClick={() => openRenameDialog(onu)}
                                                                        disabled={isLoading}
                                                                    >
                                                                        <span className="truncate font-mono">{onu.onu_name || '—'}</span>
                                                                        <Pencil className="h-3 w-3 shrink-0 opacity-0 group-hover:opacity-70" />
                                                                    </button>
                                                                </TableCell>
                                                                <TableCell>
                                                                    <Badge variant={isOnuOnline(onu.state, onu.phase_state, onu.rx_power_dbm) ? 'default' : 'secondary'} className="text-[10px]">
                                                                        {isOnuOnline(onu.state, onu.phase_state, onu.rx_power_dbm) ? 'Online' : (onu.state || onu.phase_state || 'Offline')}
                                                                    </Badge>
                                                                </TableCell>
                                                                <TableCell className="font-mono text-xs">
                                                                    <div className="flex flex-col gap-1">
                                                                        <div className="flex items-center gap-1.5">
                                                                            <span>{displaySerial}</span>
                                                                            {migrationPair && (
                                                                                <Badge variant="outline" className={`text-[9px] ${isMigrationOld ? 'border-status-down/30 text-status-down' : 'border-status-up/30 text-status-up'}`}>
                                                                                    {isMigrationOld ? 'SN LAMA' : 'SN FHTT'}
                                                                                </Badge>
                                                                            )}
                                                                            {!migrationPair && isSnDouble && (
                                                                                <Badge variant="outline" className="border-status-down/30 text-[9px] text-status-down">DOUBLE</Badge>
                                                                            )}
                                                                        </div>
                                                                        {migrationPair && (
                                                                            <span className="text-[10px] text-muted-foreground">
                                                                                {isMigrationOld
                                                                                    ? `→ FHTT: ${migrationPair.snBaru ?? '—'} @ ${migrationPair.portBaru ?? '—'}`
                                                                                    : `← Lama: ${migrationPair.snLama ?? '—'} @ ${migrationPair.portLama ?? '—'}`}
                                                                            </span>
                                                                        )}
                                                                    </div>
                                                                </TableCell>
                                                                <TableCell>
                                                                    {isOnuOnline(onu.state, onu.phase_state, rxN) && rxN != null ? (
                                                                        <span className={`inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ${sq.bg} ${sq.color}`}>
                                                                            {rxN.toFixed(2)} dBm
                                                                        </span>
                                                                    ) : (
                                                                        <span className="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium bg-muted text-muted-foreground">
                                                                            {isOnuOnline(onu.state, onu.phase_state, rxN) ? 'RX n/a (OLT)' : 'Offline'}
                                                                        </span>
                                                                    )}
                                                                </TableCell>
                                                                <TableCell className="text-xs">{onu.distance_m !== null ? `${onu.distance_m}m` : '-'}</TableCell>
                                                                <TableCell>
                                                                    {matched?.customer_id ? (
                                                                        <Link
                                                                            href={route('customers.show', { customer: matched.customer_id })}
                                                                            className="text-xs text-primary hover:underline font-medium"
                                                                        >
                                                                            {matched.customer_name}
                                                                        </Link>
                                                                    ) : (
                                                                        <Badge variant="outline" className="text-[10px] text-muted-foreground">Belum Match</Badge>
                                                                    )}
                                                                </TableCell>
                                                                <TableCell className="text-right">
                                                                    <div className="flex items-center justify-end gap-1">
                                                                        <Button variant="ghost" size="icon" className="h-10 w-10 md:h-7 md:w-7" title="Lihat Detail" aria-label={`Lihat detail ONU ${onu.onu_ref}`} onClick={() => onuAction('detail', onu.onu_ref)} disabled={isLoading}>
                                                                            <Eye className="h-3.5 w-3.5" aria-hidden="true" />
                                                                        </Button>
                                                                        <Button variant="ghost" size="icon" className="h-10 w-10 md:h-7 md:w-7" title={huaweiWriteLocked ? HUAWEI_WRITE_LOCKED_TITLE : 'Rename ONU + ACS'} aria-label={`Rename ONU ${onu.onu_ref}`} onClick={() => openRenameDialog(onu)} disabled={isLoading || huaweiWriteLocked}>
                                                                            <Pencil className="h-3.5 w-3.5" aria-hidden="true" />
                                                                        </Button>
                                                                        <Button type="button" variant="ghost" size="icon" className="h-10 w-10 md:h-7 md:w-7" title={huaweiWriteLocked ? HUAWEI_WRITE_LOCKED_TITLE : 'Reboot ONU'} aria-label={`Reboot ONU ${onu.onu_ref}`} onClick={() => openRebootDialog(onu.onu_ref)} disabled={isLoading || huaweiWriteLocked}>
                                                                            <Power className="h-3.5 w-3.5" aria-hidden="true" />
                                                                        </Button>
                                                                        <Button variant="ghost" size="icon" className="h-10 w-10 text-destructive hover:text-destructive md:h-7 md:w-7" title={huaweiWriteLocked ? HUAWEI_WRITE_LOCKED_TITLE : 'Hapus ONU'} aria-label={`Hapus ONU ${onu.onu_ref}`} onClick={() => openDeleteDialog(onu)} disabled={isLoading || huaweiWriteLocked}>
                                                                            <Trash className="h-3.5 w-3.5" aria-hidden="true" />
                                                                        </Button>
                                                                    </div>
                                                                </TableCell>
                                                            </TableRow>
                                                        );
                                                    })
                                                )}
                                            </TableBody>
                                        </Table>
                                    </div>
                                    )}

                                    {/* Pagination */}
                                    {snapshotFilter !== 'sn_double' && totalPages > 1 && (
                                        <div className="flex items-center justify-between">
                                            <p className="text-xs text-muted-foreground">
                                                Halaman {page} dari {totalPages} ({filtered.length} ONU)
                                            </p>
                                            <div className="flex items-center gap-1">
                                                <Button variant="outline" size="icon" className="h-10 w-10 md:h-8 md:w-8" aria-label="Halaman sebelumnya" onClick={() => setPage(p => Math.max(1, p - 1))} disabled={page <= 1}>
                                                    <ChevronLeft className="h-4 w-4" aria-hidden="true" />
                                                </Button>
                                                {Array.from({ length: Math.min(5, totalPages) }, (_, i) => {
                                                    let pageNum: number;
                                                    if (totalPages <= 5) {
                                                        pageNum = i + 1;
                                                    } else if (page <= 3) {
                                                        pageNum = i + 1;
                                                    } else if (page >= totalPages - 2) {
                                                        pageNum = totalPages - 4 + i;
                                                    } else {
                                                        pageNum = page - 2 + i;
                                                    }
                                                    return (
                                                        <Button key={pageNum} variant={page === pageNum ? 'default' : 'outline'} size="icon" className="h-10 w-10 text-xs md:h-8 md:w-8" aria-label={`Halaman ${pageNum}`} aria-current={page === pageNum ? 'page' : undefined} onClick={() => setPage(pageNum)}>
                                                            {pageNum}
                                                        </Button>
                                                    );
                                                })}
                                                <Button variant="outline" size="icon" className="h-10 w-10 md:h-8 md:w-8" aria-label="Halaman berikutnya" onClick={() => setPage(p => Math.min(totalPages, p + 1))} disabled={page >= totalPages}>
                                                    <ChevronRight className="h-4 w-4" aria-hidden="true" />
                                                </Button>
                                            </div>
                                        </div>
                                    )}
                                </>
                            )}
                        </div>
                    </DetailSection>
                    )}
            </div>

            <Dialog open={detailDialogOpen} onOpenChange={(open) => { setDetailDialogOpen(open); if (!open) setDetailPayload(null); }}>
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Detail ONU</DialogTitle>
                        <DialogDescription>
                            Data live dari OLT (status, redaman CLI, nama konfigurasi).
                        </DialogDescription>
                    </DialogHeader>
                    {detailLoading ? (
                        <div className="flex items-center gap-2 py-6 text-sm text-muted-foreground">
                            <RefreshCw className="h-4 w-4 animate-spin" /> Memuat detail dari OLT...
                        </div>
                    ) : detailPayload ? (
                        <div className="space-y-3 text-sm">
                            <div className="grid grid-cols-2 gap-2 rounded-md border p-3 text-xs">
                                <div><span className="text-muted-foreground">Port</span><p className="font-mono font-medium">{detailPayload.onu_ref}</p></div>
                                <div>
                                    <span className="text-muted-foreground">Status</span>
                                    <p className={`font-medium capitalize ${isOnuOnline(detailPayload.onu.state, detailPayload.onu.phase_state, detailPayload.onu.rx_power_dbm) ? 'text-status-up' : 'text-status-down'}`}>
                                        {isOnuOnline(detailPayload.onu.state, detailPayload.onu.phase_state, detailPayload.onu.rx_power_dbm)
                                            ? 'online'
                                            : (detailPayload.onu.state || 'offline')}
                                        {detailPayload.onu.phase_state ? (
                                            <span className="ml-1 font-mono text-[10px] text-muted-foreground">({detailPayload.onu.phase_state})</span>
                                        ) : null}
                                    </p>
                                </div>
                                <div><span className="text-muted-foreground">SN</span><p className="font-mono">{detailPayload.onu.serial_number || '—'}</p></div>
                                <div>
                                    <span className="text-muted-foreground">Nama ONU</span>
                                    <p className="font-mono truncate flex items-center gap-1">
                                        {detailPayload.onu.onu_name || '—'}
                                        <button
                                            type="button"
                                            className="inline-flex min-h-10 min-w-10 items-center justify-center text-primary hover:underline md:min-h-0 md:min-w-0"
                                            title="Rename"
                                            aria-label="Rename ONU"
                                            onClick={() => openRenameDialog({
                                                ...detailPayload.onu,
                                                onu_ref: detailPayload.onu_ref,
                                            })}
                                        >
                                            <Pencil className="h-3.5 w-3.5" aria-hidden="true" />
                                        </button>
                                    </p>
                                </div>
                                <div><span className="text-muted-foreground">Redaman (CLI)</span>
                                    <p className="font-mono font-medium">
                                        {detailPayload.onu.rx_power_dbm != null ? `${Number(detailPayload.onu.rx_power_dbm).toFixed(2)} dBm` : '—'}
                                    </p>
                                </div>
                                <div><span className="text-muted-foreground">Jarak</span><p>{detailPayload.onu.distance_m != null ? `${detailPayload.onu.distance_m} m` : '—'}</p></div>
                            </div>
                            {detailPayload.matched?.customer_id ? (
                                <div className="rounded-md border border-status-up/30 bg-status-up-bg p-3 text-xs">
                                    <p className="font-medium text-status-up">Pelanggan terhubung</p>
                                    <p className="mt-1">{detailPayload.matched.customer_name}</p>
                                    <p className="font-mono text-muted-foreground">{detailPayload.matched.pppoe_user}</p>
                                    <Button asChild variant="link" className="mt-2 h-auto p-0 text-xs">
                                        <Link href={route('customers.show', { customer: detailPayload.matched.customer_id })}>
                                            Buka profil pelanggan
                                        </Link>
                                    </Button>
                                </div>
                            ) : (
                                <p className="text-xs text-muted-foreground">Belum match ke pelanggan eBilling.</p>
                            )}
                        </div>
                    ) : (
                        <p className="py-4 text-sm text-muted-foreground">Detail ONU tidak tersedia.</p>
                    )}
                    <DialogFooter className="gap-2 sm:gap-0">
                        {detailPayload && (
                            <Button
                                type="button"
                                variant="default"
                                className="sm:mr-auto"
                                onClick={() => openRenameDialog({
                                    ...detailPayload.onu,
                                    onu_ref: detailPayload.onu_ref,
                                })}
                            >
                                <Pencil className="h-3.5 w-3.5 mr-1.5" />
                                Rename ONU
                            </Button>
                        )}
                        <Button variant="outline" onClick={() => setDetailDialogOpen(false)}>Tutup</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <ConfirmDestructive
                open={rebootDialogOpen}
                onOpenChange={(open) => {
                    const busy = rebootTarget !== null && actionLoading === rebootTarget;
                    if (!open && busy) return;
                    setRebootDialogOpen(open);
                    if (!open) setRebootTarget(null);
                }}
                title="Konfirmasi reboot ONU"
                description="ONU akan direboot melalui OLT. Koneksi pelanggan akan terputus sementara."
                confirmationLabel="Saya sudah memastikan ONU yang dipilih benar."
                typedConfirmation="REBOOT ONU"
                typedConfirmationLabel="Ketik REBOOT ONU untuk mengizinkan write."
                confirmText="Reboot sekarang"
                onConfirm={(typed) => {
                    if (!rebootTarget) return;
                    const ref = rebootTarget;
                    setRebootDialogOpen(false);
                    setRebootTarget(null);
                    void onuAction('reboot', ref, typed);
                }}
                isLoading={rebootTarget !== null && actionLoading === rebootTarget}
            >
                {rebootTarget && (
                    <div className="rounded-md border bg-muted/40 p-3 text-sm">
                        Target ONU: <span className="font-mono font-medium">{rebootTarget}</span>
                    </div>
                )}
            </ConfirmDestructive>

            <Dialog open={renameDialogOpen} onOpenChange={(open) => {
                if (renameSubmitting) return;
                setRenameDialogOpen(open);
                if (!open) {
                    setRenameTarget(null);
                    setRenameConfirmation('');
                }
            }}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>Rename ONU</DialogTitle>
                        <DialogDescription>
                            Ubah <span className="font-mono">name</span> di OLT + tag GenieACS otomatis (tag lama dibersihkan).
                        </DialogDescription>
                    </DialogHeader>
                    {renameTarget && (
                        <div className="space-y-3 text-sm">
                            <div className="grid grid-cols-2 gap-2 rounded-md border p-3 text-xs">
                                <div>
                                    <span className="text-muted-foreground">ONU</span>
                                    <p className="font-mono font-medium">{renameTarget.onu_ref}</p>
                                </div>
                                <div>
                                    <span className="text-muted-foreground">SN</span>
                                    <p className="font-mono">{renameTarget.serial_number || '—'}</p>
                                </div>
                            </div>
                            <div>
                                <label className="text-xs text-muted-foreground">Nama baru</label>
                                <Input
                                    className="mt-1 font-mono"
                                    value={renameName}
                                    onChange={(e) => setRenameName(e.target.value)}
                                    onBlur={() => setRenameName(normalizeOnuDisplayName(renameName))}
                                    placeholder="NAMA_RT03_RW04_ODP"
                                    disabled={renameSubmitting}
                                    autoFocus
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter' && !renameSubmitting) {
                                            e.preventDefault();
                                            void submitRenameOnu();
                                        }
                                    }}
                                />
                                {renameName && (
                                    <p className="mt-1 text-[11px] text-muted-foreground">
                                        Preview: <span className="font-mono text-foreground">{normalizeOnuDisplayName(renameName) || '—'}</span>
                                    </p>
                                )}
                            </div>
                            <p className="text-[11px] text-muted-foreground rounded-md bg-muted/40 px-2 py-1.5">
                                ACS tag ikut diganti otomatis. Butuh SN di OLT/inventory.
                            </p>
                            {isHuawei && renameSuggestion !== '' && (
                                <p className="text-[11px] text-muted-foreground" data-testid="huawei-rename-suggestion">
                                    Saran nama (PPPoE pelanggan): <button type="button" className="font-mono text-primary underline" onClick={() => setRenameName(renameSuggestion)}>{renameSuggestion}</button>
                                </p>
                            )}
                            {renameMismatchWarning && (
                                <p className="flex items-start gap-1.5 rounded-md border border-status-degraded/30 px-2 py-1.5 text-[11px]" role="alert" data-testid="huawei-rename-warning">
                                    <AlertTriangle className="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                    <span>{renameMismatchWarning}</span>
                                </p>
                            )}
                            <div>
                                <label htmlFor="generic-rename-confirmation" className="text-xs font-medium">Ketik RENAME ONU untuk melanjutkan</label>
                                <Input
                                    id="generic-rename-confirmation"
                                    value={renameConfirmation}
                                    onChange={(e) => setRenameConfirmation(e.target.value)}
                                    placeholder="RENAME ONU"
                                    autoComplete="off"
                                    disabled={renameSubmitting}
                                />
                            </div>
                        </div>
                    )}
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => setRenameDialogOpen(false)} disabled={renameSubmitting}>
                            Batal
                        </Button>
                        <Button type="button" onClick={() => void submitRenameOnu()} disabled={renameSubmitting || !normalizeOnuDisplayName(renameName) || renameConfirmation !== 'RENAME ONU'}>
                            {renameSubmitting ? (
                                <><RefreshCw className="h-3.5 w-3.5 mr-1.5 animate-spin" />Menyimpan…</>
                            ) : (
                                <><Pencil className="h-3.5 w-3.5 mr-1.5" />Simpan OLT + ACS</>
                            )}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {isHuawei && savePending && (
                <div className="fixed bottom-4 right-4 z-50 w-80 space-y-2 rounded-md border border-status-degraded/40 bg-background p-3 text-xs shadow-lg" role="alert" data-testid="huawei-save-pending">
                    <p className="font-semibold">Simpan tertunda</p>
                    <p>Perubahan Huawei aktif tetapi belum disimpan di OLT; hilang bila OLT reboot.</p>
                    <Input
                        value={saveConfirmation}
                        onChange={(e) => setSaveConfirmation(e.target.value)}
                        placeholder="SAVE OLT"
                        autoComplete="off"
                        disabled={saveSubmitting}
                    />
                    <Button type="button" size="sm" onClick={() => void submitSaveConfig()} disabled={saveSubmitting || saveConfirmation !== 'SAVE OLT'}>
                        {saveSubmitting ? 'Menyimpan…' : 'Simpan ulang'}
                    </Button>
                </div>
            )}

            <Dialog open={deleteDialogOpen} onOpenChange={(open) => { if (!open) closeDeleteDialog(); else setDeleteDialogOpen(true); }}>
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Hapus ONU dari OLT</DialogTitle>
                        <DialogDescription>
                            Perintah <code className="text-xs">no onu</code> dikirim ke OLT. Data pelanggan tidak diubah otomatis.
                        </DialogDescription>
                    </DialogHeader>
                    {deleteTarget && (
                        <div className="space-y-3 text-sm">
                            <div className="grid grid-cols-2 gap-2 rounded-md border p-3 text-xs">
                                <div><span className="text-muted-foreground">Port</span><p className="font-mono font-medium">{deleteTarget.onu_ref}</p></div>
                                <div><span className="text-muted-foreground">Status</span><p className="font-medium capitalize">{deleteTarget.state ?? '-'}</p></div>
                                <div><span className="text-muted-foreground">SN</span><p className="font-mono">{deleteTarget.serial_number ?? '-'}</p></div>
                                <div><span className="text-muted-foreground">Nama ONU</span><p className="font-mono truncate">{deleteTarget.onu_name ?? '-'}</p></div>
                            </div>
                            {deleteMigrationPair && (
                                <div className="rounded-md border border-status-degraded/30 bg-status-degraded-bg p-3 text-foreground">
                                    <p className="text-xs font-semibold">Pasangan migrasi FHTT — {deleteMigrationPair.displayName}</p>
                                    <div className="mt-2 grid gap-2 text-xs sm:grid-cols-2">
                                        <div className={`rounded border p-2 ${deleteTarget?.onu_ref === deleteMigrationPair.portLama ? 'border-status-down bg-card/80' : 'border-status-down/30'}`}>
                                            <p className="font-medium text-status-down">SN Lama (hapus ghost)</p>
                                            <p className="font-mono">{deleteMigrationPair.snLama ?? '—'}</p>
                                            <p className="font-mono text-[10px] text-muted-foreground">{deleteMigrationPair.portLama ?? '—'}</p>
                                        </div>
                                        <div className={`rounded border p-2 ${deleteTarget?.onu_ref === deleteMigrationPair.portBaru ? 'border-status-up bg-card/80' : 'border-status-up/30'}`}>
                                            <p className="font-medium text-status-up">SN FHTT Baru (pertahankan)</p>
                                            <p className="font-mono">{deleteMigrationPair.snBaru ?? '—'}</p>
                                            <p className="font-mono text-[10px] text-muted-foreground">{deleteMigrationPair.portBaru ?? '—'}</p>
                                        </div>
                                    </div>
                                    {deleteTarget?.onu_ref === deleteMigrationPair.portBaru && (
                                        <p className="mt-2 text-xs font-semibold text-status-down">
                                            <AlertTriangle className="mr-1 inline-block h-3.5 w-3.5" aria-hidden="true" /> Anda akan menghapus SN FHTT BARU — pastikan ini memang yang ingin dihapus!
                                        </p>
                                    )}
                                </div>
                            )}
                            {deleteDuplicateGroup && deleteDuplicateGroup.onus.length > 1 && (
                                <div className="rounded-md border border-status-down/30 bg-status-down-bg p-3 text-foreground">
                                    <p className="flex items-center gap-1.5 text-xs font-semibold">
                                        <ShieldAlert className="h-3.5 w-3.5" />
                                        SN {deleteDuplicateGroup.serial} ada di {deleteDuplicateGroup.onus.length} port:
                                    </p>
                                    <ul className="mt-2 space-y-1 text-xs">
                                        {deleteDuplicateGroup.onus.map((onu) => {
                                            const matched = matchedMap.get(onu.onu_ref.toLowerCase());
                                            const hint = deleteHintForOnu(onu, matched);
                                            const hintStyle = deleteHintLabel(hint);
                                            const isTarget = onu.onu_ref === deleteTarget.onu_ref;
                                            return (
                                                <li key={onu.onu_ref} className={`rounded border px-2 py-1 ${isTarget ? 'border-status-down bg-card/80' : 'border-transparent'}`}>
                                                    <span className="font-mono font-medium">{onu.onu_ref}</span>
                                                    {' · '}
                                                    {isOnuOnline(onu.state, onu.phase_state, onu.rx_power_dbm) ? 'online' : 'offline'}
                                                    {matched ? ` · ${matched.customer_name}` : ' · tanpa pelanggan'}
                                                    {' · '}
                                                    <span>{hintStyle.label}</span>
                                                    {isTarget && <span className="ml-1 font-semibold">← yang akan dihapus</span>}
                                                </li>
                                            );
                                        })}
                                    </ul>
                                </div>
                            )}
                            {deleteMatched && (
                                <div className="rounded-md border border-status-degraded/30 bg-status-degraded-bg p-3 text-foreground">
                                    Pelanggan ter-link:{' '}
                                    <Link href={route('customers.show', deleteMatched.customer_id)} className="font-medium underline">
                                        {deleteMatched.customer_name}
                                    </Link>
                                    <p className="mt-1 text-xs opacity-90">Setelah hapus, update port/SN pelanggan manual bila sudah migrasi.</p>
                                </div>
                            )}
                            {isHuawei && deleteTarget && (
                                <div className="rounded-md border border-status-down/30 p-3 text-xs" data-testid="huawei-delete-warning">
                                    <p>SN: <span className="font-mono">{deleteTarget.serial_number || '—'}</span></p>
                                    <p className="mt-1">Service-port ONU ini akan dihapus dulu, lalu ONU dihapus dari OLT. Tidak bisa dibatalkan.</p>
                                </div>
                            )}
                            {!isHuawei && deleteParsed && (
                                <div className="rounded-md bg-muted/50 p-3 font-mono text-xs leading-relaxed">
                                    <p>configure terminal</p>
                                    <p>interface gpon-olt_{deleteParsed.port}</p>
                                    <p>no onu {deleteParsed.onuId}</p>
                                    <p>exit</p>
                                    <p>exit</p>
                                    <p>write</p>
                                </div>
                            )}
                            {deleteIsOnline && (
                                <label className="flex items-start gap-2 rounded-md border border-status-down/30 bg-status-down-bg p-3 text-foreground">
                                    <input
                                        type="checkbox"
                                        className="mt-0.5"
                                        checked={deleteConfirmOnline}
                                        onChange={(e) => setDeleteConfirmOnline(e.target.checked)}
                                    />
                                    <span className="text-xs">ONU masih <strong>online</strong> — saya yakin ingin menghapus dari OLT.</span>
                                </label>
                            )}
                            {deleteGuard && (
                                <div className="space-y-2 rounded-md border border-status-down/30 bg-status-down-bg p-3 text-foreground" role="alert">
                                    <p className="flex items-start gap-1.5 text-xs font-semibold">
                                        <ShieldAlert className="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                        <span>{deleteGuard.message}</span>
                                    </p>
                                    {deleteGuard.canOverride ? (
                                        <>
                                            <label htmlFor="delete-override-reason" className="text-xs font-medium">
                                                Alasan override (minimal {deleteGuard.reasonMin} karakter, tercatat di log aktivitas)
                                            </label>
                                            <Textarea
                                                id="delete-override-reason"
                                                value={deleteOverrideReason}
                                                onChange={(e) => setDeleteOverrideReason(e.target.value)}
                                                rows={3}
                                                disabled={deleteSubmitting}
                                            />
                                            <label className="flex items-start gap-2 text-xs">
                                                <input
                                                    type="checkbox"
                                                    className="mt-0.5"
                                                    checked={deleteOverrideChecked}
                                                    onChange={(e) => setDeleteOverrideChecked(e.target.checked)}
                                                    disabled={deleteSubmitting}
                                                />
                                                <span>Saya superadmin dan yakin ONU ini boleh dihapus meski pagar menahan.</span>
                                            </label>
                                        </>
                                    ) : (
                                        <p className="text-xs">Hanya superadmin yang dapat override dengan alasan tertulis.</p>
                                    )}
                                </div>
                            )}
                            <div>
                                <label htmlFor="generic-delete-confirmation" className="text-xs font-medium">Ketik DELETE ONU untuk melanjutkan</label>
                                <Input
                                    id="generic-delete-confirmation"
                                    value={deleteConfirmation}
                                    onChange={(e) => setDeleteConfirmation(e.target.value)}
                                    placeholder="DELETE ONU"
                                    autoComplete="off"
                                    disabled={deleteSubmitting}
                                />
                            </div>
                        </div>
                    )}
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={closeDeleteDialog} disabled={deleteSubmitting}>Batal</Button>
                        <Button
                            type="button"
                            variant="destructive"
                            onClick={() => void executeDeleteOnu()}
                            disabled={deleteSubmitting || deleteConfirmation !== 'DELETE ONU' || (deleteIsOnline && !deleteConfirmOnline) || (deleteGuard !== null && !(deleteGuard.canOverride && deleteOverrideChecked))}
                        >
                            {deleteSubmitting ? 'Menghapus...' : deleteGuard?.canOverride ? 'Tetap hapus (override)' : 'Hapus dari OLT'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* R3: hapus OLT wajib ketik nama OLT. */}
            <ConfirmDestructive
                open={confirmOpen}
                onOpenChange={(open) => {
                    if (!oltDeleting) setConfirmOpen(open);
                }}
                title="Hapus OLT?"
                description={`Yakin ingin menghapus ${olt.name}? Data ONU yang sudah dihapus tidak bisa dikembalikan.`}
                typedConfirmation={olt.name}
                typedConfirmationLabel="Ketik nama OLT untuk melanjutkan."
                confirmText="Hapus OLT"
                isLoading={oltDeleting}
                onConfirm={() => router.delete(route('olts.destroy', olt.id), {
                    onStart: () => setOltDeleting(true),
                    onFinish: () => {
                        setOltDeleting(false);
                        setConfirmOpen(false);
                    },
                })}
            />
        </EnterpriseResourceLayout>
        </OltErrorBoundary>
    );
}
// force rebuild
