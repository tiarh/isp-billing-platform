import { EnterpriseIndexLayout } from '@/Components/enterprise/EnterpriseIndexLayout';
import { ConfirmDestructive } from '@/Components/ConfirmDestructive';
import { StatusBadge as SharedStatusBadge, type StatusTone } from '@/Components/StatusBadge';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { Link, router, usePage } from '@inertiajs/react';
import { AlertCircle, CheckCircle2, ChevronDown, ChevronRight, Clock, Download, FileSpreadsheet, PlayCircle, RefreshCw, Save, Search, ShieldCheck, UploadCloud, XCircle } from 'lucide-react';
import axios from 'axios';
import { FormEvent, Fragment, useState } from 'react';

type MigrationRow = {
    id: number;
    status: string;
    db_status?: string;
    can_manual_complete?: boolean;
    status_source?: string;
    status_detail?: string | null;
    technician_name?: string | null;
    source: string;
    pppoe_user: string | null;
    full_name: string | null;
    customer_id: number | null;
    old_onu_ref: string | null;
    old_sn: string | null;
    new_onu_ref: string | null;
    new_pon_port: string | null;
    new_onu_id: number | null;
    new_sn: string | null;
    router: string | null;
    old_olt: string | null;
    new_olt: string | null;
    vlan: number | null;
    tr069_vlan: number | null;
    last_error: string | null;
    review_status: string | null;
    review_reasons: string[];
    device_action?: {
        status: string | null;
        ledger_id: number | null;
        write_flags: Record<string, boolean>;
        rollback_hint: string | null;
        blocked_reason: string | null;
    };
    timeline: Array<{ step: string; status: string; at: string | null }>;
    created_at: string | null;
    old_onu_deleted_at: string | null;
    synced_at: string | null;
    rt: string | null;
    rw: string | null;
};

type Props = {
    migrations: MigrationRow[];
    importRows: ImportRow[];
    importStats: { total: number; migration: number; psb_new_candidate: number; needs_review: number; duplicate: number; rw_needs_review: number };
    filters: { status?: string; search?: string; import_filter?: string };
    pagination: PageMeta;
    importPagination: PageMeta;
    stats: {
        total: number;
        synced: number;
        success_actual?: number;
        olt_active?: number;
        waiting_sn: number;
        needs_review?: number;
        failed: number;
        by_rw?: Array<{
            rw: string;
            total: number;
            synced: number;
            rts: Record<string, { rt: string; total: number; synced: number }>;
        }>;
    };
    overallStats: {
        raw_total: number;
        total: number;
        synced: number;
        db_synced: number;
        olt_active: number;
        pending: number;
        waiting_sn: number;
        needs_review: number;
        failed: number;
        technicians: Array<{ name: string; total: number; synced: number; pending: number }>;
    };
    tableReady: boolean;
    importReady: boolean;
    activationSignals?: WaSignal[];
    guidedLookup?: {
        available: boolean;
        feature_enabled: boolean;
        allowlist?: {
            enabled: boolean;
            allowed_roles: string[];
            allowed_user_count: number;
        };
        consumer_enabled: boolean;
        queue: string;
    };
};

type ManualCompletePreview = {
    migration_id: number;
    status: string;
    closed: boolean;
    blocked: string | null;
    warning: string | null;
    verdict: { kind: string; label: string; reason_code: string; reason: string } | null;
    customer: { id: number; name: string; pppoe_user: string | null } | null;
    onu: { found: boolean; olt: string | null; onu_ref: string | null; state: string | null; online: boolean | null; collected_at: string | null } | null;
    pppoe: { online: boolean; checked_at: string | null; router: string | null } | null;
    traces: { mikrotik_moved: boolean; olt_provisioned: boolean; tr069_tagged: boolean; ledgers: Array<{ id: number; status: string }> } | null;
    reason_min: number;
};

type PageMeta = {
    current_page: number;
    per_page: number;
    total: number;
    has_more: boolean;
    next_page: number | null;
    previous_page: number | null;
};

type GuidedCandidate = {
    number: number;
    customer_id: number;
    customer_code: string | null;
    name: string;
    pppoe_user: string | null;
    area: string | null;
    odp: string | null;
    rt: string | null;
    rw: string | null;
    old_sn: string | null;
    old_onu_ref: string | null;
    old_onu_resolution: string;
    score: number;
    confidence: string;
    match_reasons: string[];
    conflicts: string[];
    warnings: string[];
    sources: string[];
    freshness: Record<string, { at: string | null; stale: boolean | null }>;
};

type GuidedLookupResult = {
    readonly: boolean;
    feature_enabled: boolean;
    status: string;
    reasons: string[];
    candidates: GuidedCandidate[];
    next_action: string;
    input: Record<string, string>;
    queue: { name: string; consumer_enabled: boolean };
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

type ImportRow = {
    id: number;
    row_number: number;
    batch_key: string;
    source_label: string | null;
    classification: string;
    classification_source: string;
    project: string | null;
    area: string | null;
    sub_area: string | null;
    rw: string | null;
    rw_needs_review: boolean;
    full_name: string | null;
    pppoe_user: string | null;
    sn_lama: string | null;
    sn_baru: string | null;
    sn_baru_source: string | null;
    old_onu_ref: string | null;
    status: string;
    match_status: string;
    match_notes: string | null;
    override_reason: string | null;
    applied_target_type: string | null;
    applied_target_id: number | null;
    applied_error: string | null;
    imported_at: string | null;
    corrected_at: string | null;
    applied_at: string | null;
    candidate_customers?: Array<{
        id: number;
        code: string | null;
        name: string;
        pppoe_user: string | null;
        area: string | null;
        router: string | null;
        sn: string | null;
        status: string | null;
    }>;
    last_correction?: {
        reason?: string;
        actor_id?: number | null;
        at?: string;
    } | null;
};

const timelineStepLabel: Record<string, string> = {
    manual_completed_synced: 'Ditandai selesai manual',
    auto_reconciled_synced: 'Ditandai selesai otomatis',
};

const statusLabel: Record<string, string> = {
    received: 'Received',
    waiting_sn: 'Tunggu SN',
    partially_executed: 'Sebagian dieksekusi',
    olt_ok: 'OLT OK',
    synced: 'Selesai',
    failed: 'Gagal',
    needs_review: 'Review',
    cancelled: 'Batal',
    migration: 'Migrasi',
    psb_new_candidate: 'PSB Baru',
    duplicate: 'Duplikat',
    not_migrated: 'Belum Migrasi',
    migrated: 'Sudah Migrasi',
    staged: 'Staged',
    applied: 'Applied',
    confirmed_pending_approval: 'Confirmed',
    pending_execution: 'Siap Execute',
    dry_run_ready: 'Dry-run Ready',
    blocked_kill_switch: 'Blocked',
};

function MigrationStatusBadge({ status }: { status: string }) {
    const tones: Record<string, StatusTone> = {
        received: 'info',
        waiting_sn: 'degraded',
        partially_executed: 'down',
        olt_ok: 'info',
        synced: 'up',
        failed: 'down',
        needs_review: 'degraded',
        cancelled: 'neutral',
        migration: 'info',
        psb_new_candidate: 'up',
        duplicate: 'degraded',
        not_migrated: 'neutral',
        migrated: 'up',
        staged: 'neutral',
        applied: 'up',
        confirmed_pending_approval: 'up',
        pending_execution: 'info',
        dry_run_ready: 'info',
        blocked_kill_switch: 'down',
    };

    return <SharedStatusBadge label={statusLabel[status] || status} tone={tones[status] || 'neutral'} />;
}

const IMPORT_REASON_MIN = 8;
const IMPORT_CLASSIFICATION_LABELS: Record<string, string> = {
    migration: 'Migrasi',
    psb_new_candidate: 'PSB Baru',
    needs_review: 'Review',
};

export default function Index({ migrations, importRows, importStats, filters, pagination, importPagination, stats, overallStats, tableReady, importReady, activationSignals = [], guidedLookup }: Props) {
    const page = usePage();
    const authRole = (page.props as { auth?: { user?: { role?: string } } }).auth?.user?.role;
    const canManualComplete = authRole === 'admin' || authRole === 'superadmin' || authRole === 'noc';
    const [manualRow, setManualRow] = useState<MigrationRow | null>(null);
    const [manualPreview, setManualPreview] = useState<ManualCompletePreview | null>(null);
    const [manualLoading, setManualLoading] = useState(false);
    const [manualReason, setManualReason] = useState('');
    const [manualError, setManualError] = useState('');
    const [manualBusy, setManualBusy] = useState(false);

    const openManualComplete = async (row: MigrationRow) => {
        setManualRow(row);
        setManualPreview(null);
        setManualReason('');
        setManualError('');
        setManualLoading(true);
        try {
            const response = await axios.get<ManualCompletePreview>(route('customer-migrations.manual-complete.preview', row.id), { headers: { Accept: 'application/json' } });
            setManualPreview(response.data);
        } catch {
            setManualError('Bukti gagal dimuat. Coba lagi.');
        } finally {
            setManualLoading(false);
        }
    };

    const closeManualComplete = () => {
        if (manualBusy) return;
        setManualRow(null);
        setManualPreview(null);
    };

    const submitManualComplete = async () => {
        if (!manualRow) return;
        setManualBusy(true);
        setManualError('');
        try {
            await axios.post(route('customer-migrations.manual-complete', manualRow.id), { reason: manualReason.trim() }, { headers: { Accept: 'application/json' } });
            setManualBusy(false);
            setManualRow(null);
            setManualPreview(null);
            router.reload();
        } catch (error) {
            setManualBusy(false);
            if (axios.isAxiosError(error)) {
                const validation = error.response?.data?.errors as Record<string, string[]> | undefined;
                setManualError(validation ? Object.values(validation).flat()[0] : (error.response?.data?.message || 'Gagal menandai selesai.'));
            } else {
                setManualError('Gagal menandai selesai.');
            }
        }
    };

    const flash = (page.props as { flash?: { success?: string; error?: string } }).flash;
    const pageErrors = (page.props as { errors?: Record<string, string> }).errors || {};
    // Kumpulkan semua feedback error: flash.error + validation errors
    // (errors.migration_action dari Dry-run/Execute, errors.file/source_url/reason dari Import/Apply).
    const errorMessages = [
        ...(flash?.error ? [flash.error] : []),
        ...Object.values(pageErrors).filter(Boolean),
    ];
    const [search, setSearch] = useState(filters.search || '');
    const [corrections, setCorrections] = useState<Record<number, { classification: string; rw: string; reason: string }>>({});
    const [selectedImportIds, setSelectedImportIds] = useState<number[]>([]);
    const [expandedRw, setExpandedRw] = useState<Record<string, boolean>>({});
    const [bulkCorrection, setBulkCorrection] = useState({ classification: 'migration', rw: '', reason: '' });
    const [applyReason, setApplyReason] = useState('');
    // S3: koreksi/apply terpilih wajib konfirmasi + alasan (server: min 8 karakter).
    const [pendingImportAction, setPendingImportAction] = useState<'correct' | 'apply' | null>(null);
    const [importActionBusy, setImportActionBusy] = useState(false);
    const [actionReasons, setActionReasons] = useState<Record<number, string>>({});
    const [pendingExecution, setPendingExecution] = useState<MigrationRow | null>(null);
    const [lookupInput, setLookupInput] = useState({
        name: '',
        new_sn: '',
        phone: '',
        customer_code: '',
        area: '',
        odp: '',
        rt: '',
        rw: '',
    });
    const [lookupResult, setLookupResult] = useState<GuidedLookupResult | null>(null);
    const [lookupLoading, setLookupLoading] = useState(false);
    const [lookupError, setLookupError] = useState('');
    const [guidedApprovalReasons, setGuidedApprovalReasons] = useState<Record<number, string>>({});

    const submitSearch = (e: FormEvent) => {
        e.preventDefault();
        router.get(route('customer-migrations.index'), { ...filters, search: search || undefined }, { preserveState: true });
    };

    const setStatus = (status: string) => {
        router.get(route('customer-migrations.index'), { ...filters, status: status === 'active' ? undefined : status }, { preserveState: true });
    };

    const setImportFilter = (filter: string) => {
        router.get(route('customer-migrations.index'), { ...filters, import_filter: filter === 'all' ? undefined : filter, import_page: undefined }, { preserveState: true });
    };

    const goToMigrationPage = (nextPage: number) => {
        router.get(route('customer-migrations.index'), {
            ...filters,
            page: nextPage > 1 ? nextPage : undefined,
        }, { preserveState: true, preserveScroll: true });
    };

    const goToImportPage = (nextPage: number) => {
        router.get(route('customer-migrations.index'), {
            ...filters,
            import_page: nextPage > 1 ? nextPage : undefined,
        }, { preserveState: true, preserveScroll: true });
    };

    const updateCorrection = (row: ImportRow, patch: Partial<{ classification: string; rw: string; reason: string }>) => {
        setCorrections((current) => ({
            ...current,
            [row.id]: {
                ...(current[row.id] || { classification: row.classification, rw: row.rw || '', reason: '' }),
                ...patch,
            },
        }));
    };

    const saveCorrection = (row: ImportRow) => {
        const payload = corrections[row.id] || { classification: row.classification, rw: row.rw || '', reason: '' };
        router.patch(route('customer-migrations.import-preview.update', row.id), payload, { preserveScroll: true });
    };

    const toggleImportRow = (id: number, checked: boolean) => {
        setSelectedImportIds((current) => checked ? Array.from(new Set([...current, id])) : current.filter((rowId) => rowId !== id));
    };

    const toggleAllImportRows = (checked: boolean) => {
        setSelectedImportIds(checked ? importRows.filter((row) => !row.applied_at).map((row) => row.id) : []);
    };

    const bulkReasonValid = bulkCorrection.reason.trim().length >= IMPORT_REASON_MIN;
    const applyReasonValid = applyReason.trim().length >= IMPORT_REASON_MIN;

    const importRequestOptions = {
        preserveScroll: true,
        onStart: () => setImportActionBusy(true),
        onFinish: () => {
            setImportActionBusy(false);
            setPendingImportAction(null);
        },
    };

    const bulkCorrectSelected = () => {
        router.patch(
            route('customer-migrations.import-preview.bulk-correct'),
            { ids: selectedImportIds, ...bulkCorrection, reason: bulkCorrection.reason.trim() },
            importRequestOptions,
        );
    };

    const applySelected = () => {
        router.post(
            route('customer-migrations.import-preview.apply'),
            { ids: selectedImportIds, reason: applyReason.trim() },
            importRequestOptions,
        );
    };

    const migrationAction = (id: number, action: 'prepare' | 'execute') => {
        const reason = actionReasons[id] || '';
        if (action === 'execute') {
            const row = migrations.find((migration) => migration.id === id);
            if (row) setPendingExecution(row);

            return;
        }

        router.post(route(`customer-migrations.device-action.${action}`, id), { reason }, { preserveScroll: true });
    };

    const confirmMigrationExecution = () => {
        if (!pendingExecution) return;

        const migration = pendingExecution;
        setPendingExecution(null);
        router.post(
            route('customer-migrations.device-action.execute', migration.id),
            { reason: actionReasons[migration.id] || '' },
            { preserveScroll: true },
        );
    };

    const canExecuteMigrationAction = (row: MigrationRow) => {
        const flags = row.device_action?.write_flags || {};
        return !!(flags.migration_actions && flags.olt && flags.mikrotik && flags.genieacs && !flags.global_read_only);
    };

    const previewGuidedLookup = async (event: FormEvent) => {
        event.preventDefault();
        setLookupLoading(true);
        setLookupError('');
        setLookupResult(null);

        try {
            const response = await axios.post<GuidedLookupResult>(
                route('customer-migrations.guided-lookup.preview'),
                lookupInput,
                { timeout: 15000, headers: { Accept: 'application/json' } },
            );
            setLookupResult(response.data);
        } catch (error) {
            if (axios.isAxiosError(error)) {
                const validation = error.response?.data?.errors as Record<string, string[]> | undefined;
                setLookupError(validation ? Object.values(validation).flat()[0] : (error.response?.data?.message || 'Preview gagal.'));
            } else {
                setLookupError('Preview gagal.');
            }
        } finally {
            setLookupLoading(false);
        }
    };

    const approveGuidedCandidate = (candidate: GuidedCandidate) => {
        router.post(route('customer-migrations.guided-lookup.approve'), {
            ...lookupInput,
            candidate_number: candidate.number,
            reason: guidedApprovalReasons[candidate.number] || '',
        }, { preserveScroll: true });
    };

    return (
        <EnterpriseIndexLayout
            title="Migrasi"
            subtitle="Migrasi pelanggan lama C300 ke ONU FiberHome tanpa mencampur data PSB"
            icon={RefreshCw}
            breadcrumbs={[{ label: 'Migrasi' }]}
            actions={
                <div className="flex w-full flex-wrap justify-end gap-2">
                    <form onSubmit={submitSearch} className="flex min-w-0 max-w-sm flex-1 gap-2">
                        <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Cari PPPoE / SN / ONU" className="h-9" />
                        <Button type="submit" size="sm" variant="outline" className="h-9 gap-2"><Search className="h-4 w-4" />Cari</Button>
                    </form>
                    <Button size="sm" variant="outline" className="h-9 gap-2" asChild>
                        <a href={route('customer-migrations.export', { status: filters.status || 'active', search: filters.search || undefined })}>
                            <Download className="h-4 w-4" />Export CSV
                        </a>
                    </Button>
                </div>
            }
        >
            <div className="space-y-4">
                {errorMessages.length > 0 && (
                    <div role="alert" className="flex items-start gap-2 rounded-xl border border-status-down/30 bg-status-down-bg px-4 py-3 text-sm text-status-down">
                        <AlertCircle className="mt-0.5 h-4 w-4 shrink-0" />
                        <div className="space-y-1">
                            {errorMessages.map((message, index) => (
                                <p key={index}>{message}</p>
                            ))}
                        </div>
                    </div>
                )}
                {errorMessages.length === 0 && flash?.success && (
                    <div role="status" className="flex items-start gap-2 rounded-xl border border-status-up/30 bg-status-up-bg px-4 py-3 text-sm text-status-up">
                        <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0" />
                        <span>{flash.success}</span>
                    </div>
                )}
                <div className="mb-2 rounded-md border border-status-up/30 bg-status-up-bg px-3 py-2 text-xs text-muted-foreground">
                    <strong className="text-status-up">Sukses final</strong> = row unik yang sudah synced atau terbukti live. <strong>DB diagnostik</strong> hanya status database; row live tidak masuk Review.
                </div>
                <section className="rounded-lg border bg-card p-4">
                    <div className="mb-3 flex flex-wrap items-baseline justify-between gap-2">
                        <div>
                            <h2 className="text-sm font-semibold">Ringkasan semua data migrasi</h2>
                            <p className="text-xs text-muted-foreground">Dedupe per customer, PPPoE, atau SN. Sumber database/read model.</p>
                        </div>
                        <span className="text-xs text-muted-foreground">Tidak termasuk batal</span>
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
                        <div className="rounded-md border bg-muted/20 p-3"><p className="text-xs text-muted-foreground">Baris masuk</p><p className="text-2xl font-bold tabular-nums">{overallStats.raw_total}</p><p className="text-[11px] text-muted-foreground">termasuk duplikat</p></div>
                        <div className="rounded-md border bg-muted/20 p-3"><p className="text-xs text-muted-foreground">Total unik</p><p className="text-2xl font-bold tabular-nums">{overallStats.total}</p></div>
                        <div className="rounded-md border bg-status-up-bg p-3"><p className="text-xs text-muted-foreground">Terverifikasi aktual</p><p className="text-2xl font-bold text-status-up tabular-nums">{overallStats.synced}</p><p className="text-[11px] text-muted-foreground">DB {overallStats.db_synced} · OLT {overallStats.olt_active}</p></div>
                        <div className="rounded-md border bg-status-degraded-bg p-3"><p className="text-xs text-muted-foreground">Belum selesai</p><p className="text-2xl font-bold text-status-degraded tabular-nums">{overallStats.pending}</p></div>
                        <div className="rounded-md border bg-status-degraded-bg p-3"><p className="text-xs text-muted-foreground">Perlu review</p><p className="text-2xl font-bold text-status-degraded tabular-nums">{overallStats.needs_review}</p></div>
                        <div className="rounded-md border bg-status-down-bg p-3"><p className="text-xs text-muted-foreground">Gagal</p><p className="text-2xl font-bold text-status-down tabular-nums">{overallStats.failed}</p></div>
                    </div>
                </section>
                {overallStats.technicians.length > 0 && (
                    <section className="rounded-lg border bg-card p-4">
                        <div className="mb-3">
                            <h2 className="text-sm font-semibold">Teknisi pengirim #migrasi</h2>
                            <p className="text-xs text-muted-foreground">Hanya sender dari command WA #migrasi; data manual tidak dihitung.</p>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead><tr className="border-b text-left text-xs text-muted-foreground"><th className="px-3 py-2">Teknisi</th><th className="px-3 py-2 text-right">Total</th><th className="px-3 py-2 text-right">Selesai</th><th className="px-3 py-2 text-right">Belum selesai</th></tr></thead>
                                <tbody>{overallStats.technicians.map((technician) => (
                                    <tr key={technician.name} className="border-b last:border-0">
                                        <td className="px-3 py-2 font-medium">{technician.name}</td>
                                        <td className="px-3 py-2 text-right tabular-nums">{technician.total}</td>
                                        <td className="px-3 py-2 text-right text-status-up tabular-nums">{technician.synced}</td>
                                        <td className="px-3 py-2 text-right text-status-degraded tabular-nums">{technician.pending}</td>
                                    </tr>
                                ))}</tbody>
                            </table>
                        </div>
                    </section>
                )}
                <div className="text-sm font-semibold">Operasional hari ini</div>
                <div className="grid gap-3 md:grid-cols-5">
                    <Card><CardContent className="flex items-center gap-3 p-4"><Clock className="h-5 w-5 text-muted-foreground" /><div><p className="text-xs text-muted-foreground">Input migrasi hari ini (unik)</p><p className="text-2xl font-bold">{stats.total}</p></div></CardContent></Card>
                    <Card><CardContent className="flex items-center gap-3 p-4"><RefreshCw className="h-5 w-5 text-status-degraded" /><div><p className="text-xs text-muted-foreground">Tunggu SN</p><p className="text-2xl font-bold">{stats.waiting_sn}</p></div></CardContent></Card>
                    <Card><CardContent className="flex items-center gap-3 p-4"><CheckCircle2 className="h-5 w-5 text-status-up" /><div><p className="text-xs text-muted-foreground">Sukses final migrasi</p><p className="text-2xl font-bold">{stats.success_actual ?? stats.synced}</p><p className="text-[11px] text-muted-foreground">DB synced {stats.synced} · OLT cocok {stats.olt_active ?? 0}</p></div></CardContent></Card>
                    <Card><CardContent className="flex items-center gap-3 p-4"><Clock className="h-5 w-5 text-status-degraded" /><div><p className="text-xs text-muted-foreground">Review belum selesai</p><p className="text-2xl font-bold">{stats.needs_review || 0}</p><p className="text-[11px] text-muted-foreground">Live terverifikasi tidak dihitung review</p></div></CardContent></Card>
                    <Card><CardContent className="flex items-center gap-3 p-4"><XCircle className="h-5 w-5 text-status-down" /><div><p className="text-xs text-muted-foreground">Gagal</p><p className="text-2xl font-bold">{stats.failed}</p></div></CardContent></Card>
                </div>

                <div className="overflow-hidden rounded-lg border bg-card">
                    <div className="border-b px-4 py-3">
                        <div className="text-sm font-semibold">Rekap Migrasi per RW / RT</div>
                        <div className="text-xs text-muted-foreground">Mengikuti filter halaman. RT kosong masuk review.</div>
                    </div>
                    {stats.by_rw?.length ? (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <tbody>
                                    {stats.by_rw.map((rw) => {
                                        const rts = Object.values(rw.rts || {});

                                        return (
                                            <Fragment key={rw.rw}>
                                                <tr className="border-b border-border">
                                                    <td className="px-4 py-2 font-medium" colSpan={2}>
                                                        <button
                                                            type="button"
                                                            className="flex w-full items-center gap-1.5 text-left hover:text-foreground"
                                                            aria-expanded={Boolean(expandedRw[rw.rw])}
                                                            onClick={() => setExpandedRw((current) => ({ ...current, [rw.rw]: !current[rw.rw] }))}
                                                        >
                                                            {expandedRw[rw.rw] ? <ChevronDown className="h-4 w-4" /> : <ChevronRight className="h-4 w-4" />}
                                                            <span>Total RW {rw.rw}</span>
                                                            {rts.length > 0 && <span className="text-xs font-normal text-muted-foreground">({rts.length} RT)</span>}
                                                        </button>
                                                    </td>
                                                    <td className="px-4 py-2 text-right font-semibold tabular-nums">{rw.total}</td>
                                                    <td className="px-4 py-2 text-xs text-muted-foreground">synced {rw.synced}</td>
                                                </tr>
                                                {expandedRw[rw.rw] && rts.map((rt) => (
                                                    <tr key={`${rw.rw}-${rt.rt}`} className="border-b border-border bg-muted/50 text-xs">
                                                        <td className="px-4 py-1.5 pl-8 text-muted-foreground" colSpan={2}>
                                                            {rt.rt && rt.rt !== '(kosong)' ? `RT ${rt.rt.replace(/\D/g, '').padStart(2, '0')}` : 'RT perlu review'}
                                                        </td>
                                                        <td className="px-4 py-1.5 text-right font-semibold tabular-nums">{rt.total}</td>
                                                        <td className="px-4 py-1.5 text-muted-foreground">synced {rt.synced}</td>
                                                    </tr>
                                                ))}
                                            </Fragment>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        <div className="px-4 py-6 text-sm text-muted-foreground">
                            Belum ada migrasi pada filter ini untuk ditampilkan per RW / RT.
                        </div>
                    )}
                </div>

                <div className="flex flex-wrap gap-2">
                    {['active', 'all', 'received', 'needs_review', 'waiting_sn', 'partially_executed', 'olt_ok', 'synced', 'failed', 'cancelled'].map((status) => (
                        <Button key={status} size="sm" variant={(filters.status || 'active') === status ? 'default' : 'outline'} onClick={() => setStatus(status)}>
                            {status === 'active' ? 'Aktif' : status === 'all' ? 'Semua' : statusLabel[status] || status}
                        </Button>
                    ))}
                </div>

                {!tableReady && <div className="rounded-lg border border-status-degraded/30 bg-status-degraded-bg p-4 text-sm text-status-degraded">Tabel migrasi belum ada. Jalankan migrate di server.</div>}

                {guidedLookup?.available && (
                    <section className="border-y bg-card py-4">
                        <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <h2 className="text-sm font-semibold">Lookup Migrasi Operator</h2>
                                <p className="text-xs text-muted-foreground">Preview snapshot eBilling, OLT, MikroTik, dan GenieACS.</p>
                            </div>
                            <div className="flex gap-2">
                                <Badge variant="outline">Read-only</Badge>
                                <Badge variant="warning">
                                    Flag {guidedLookup.feature_enabled ? 'ON' : 'OFF'}
                                </Badge>
                                <Badge variant="outline">
                                    Allowlist {guidedLookup.allowlist?.enabled ? 'ON' : 'OFF'}
                                </Badge>
                                <Badge variant="outline">Worker {guidedLookup.consumer_enabled ? 'ON' : 'OFF'}</Badge>
                            </div>
                        </div>

                        <form onSubmit={previewGuidedLookup} className="grid gap-2 md:grid-cols-2 xl:grid-cols-4">
                            <Input aria-label="Nama pelanggan" required value={lookupInput.name} onChange={(e) => setLookupInput((v) => ({ ...v, name: e.target.value }))} placeholder="Nama pelanggan" />
                            <Input aria-label="SN baru" required value={lookupInput.new_sn} onChange={(e) => setLookupInput((v) => ({ ...v, new_sn: e.target.value }))} placeholder="SN baru" />
                            <Input aria-label="Nomor HP" value={lookupInput.phone} onChange={(e) => setLookupInput((v) => ({ ...v, phone: e.target.value }))} placeholder="HP" />
                            <Input aria-label="Kode pelanggan" value={lookupInput.customer_code} onChange={(e) => setLookupInput((v) => ({ ...v, customer_code: e.target.value }))} placeholder="Kode pelanggan" />
                            <Input aria-label="Area pelanggan" value={lookupInput.area} onChange={(e) => setLookupInput((v) => ({ ...v, area: e.target.value }))} placeholder="Area" />
                            <Input aria-label="ODP pelanggan" value={lookupInput.odp} onChange={(e) => setLookupInput((v) => ({ ...v, odp: e.target.value }))} placeholder="ODP" />
                            <Input aria-label="RT pelanggan" value={lookupInput.rt} onChange={(e) => setLookupInput((v) => ({ ...v, rt: e.target.value }))} placeholder="RT" />
                            <div className="grid grid-cols-[1fr_auto] gap-2">
                                <Input aria-label="RW pelanggan" value={lookupInput.rw} onChange={(e) => setLookupInput((v) => ({ ...v, rw: e.target.value }))} placeholder="RW" />
                                <Button type="submit" size="sm" className="h-9 gap-2" disabled={lookupLoading}>
                                    <Search className="h-4 w-4" />{lookupLoading ? 'Mencari' : 'Preview'}
                                </Button>
                            </div>
                        </form>

                        {lookupError && <p role="alert" className="mt-3 text-sm text-status-down">{lookupError}</p>}
                        {lookupResult && (
                            <div className="mt-4 border-t pt-3">
                                <div className="flex flex-wrap items-center gap-2 text-sm">
                                    <MigrationStatusBadge status={lookupResult.status} />
                                    <span className="text-muted-foreground">Queue {lookupResult.queue.name}: consumer OFF</span>
                                    {lookupResult.reasons.map((reason) => <Badge key={reason} variant="outline">{reason}</Badge>)}
                                </div>
                                {lookupResult.candidates.length === 0 ? (
                                    <p className="mt-3 text-sm text-muted-foreground">{lookupResult.next_action}</p>
                                ) : (
                                    <div className="mt-3 divide-y">
                                        {lookupResult.candidates.map((candidate) => (
                                            <div key={candidate.customer_id} className="grid gap-2 py-3 text-sm lg:grid-cols-[1.2fr_1fr_1fr_1fr]">
                                                <div>
                                                    <div className="font-semibold">#{candidate.number} {candidate.name}</div>
                                                    <div className="text-muted-foreground">{candidate.customer_code || '-'} · {candidate.pppoe_user || '-'}</div>
                                                    <div className="mt-1">{candidate.area || '-'} · {candidate.odp || '-'} · RT {candidate.rt || '-'} / RW {candidate.rw || '-'}</div>
                                                </div>
                                                <div>
                                                    <div><span className="text-muted-foreground">Confidence:</span> {candidate.confidence} ({candidate.score})</div>
                                                    <div><span className="text-muted-foreground">SN lama:</span> {candidate.old_sn || '-'}</div>
                                                    <div><span className="text-muted-foreground">ONU lama:</span> {candidate.old_onu_ref || candidate.old_onu_resolution}</div>
                                                </div>
                                                <div>
                                                    <div className="text-xs text-muted-foreground">{candidate.sources.join(', ') || 'customer_db'}</div>
                                                    <div className="mt-1 flex flex-wrap gap-1 text-xs">
                                                        {Object.entries(candidate.freshness).map(([source, freshness]) => (
                                                            <span key={source} className={freshness.stale ? 'text-status-degraded' : 'text-muted-foreground'}>
                                                                {source}: {freshness.at ? (freshness.stale ? 'stale' : 'fresh') : '-'}
                                                            </span>
                                                        ))}
                                                    </div>
                                                    <div className="mt-1 flex flex-wrap gap-1">
                                                        {candidate.match_reasons.map((reason) => <Badge key={reason} variant="outline">{reason}</Badge>)}
                                                        {candidate.conflicts.map((reason) => <Badge key={reason} variant="down">{reason}</Badge>)}
                                                        {candidate.warnings.map((reason) => <Badge key={reason} variant="warning">{reason}</Badge>)}
                                                    </div>
                                                </div>
                                                <div className="grid gap-2">
                                                    <Input
                                                        aria-label={`Alasan approve kandidat ${candidate.number}`}
                                                        value={guidedApprovalReasons[candidate.number] || ''}
                                                        onChange={(e) => setGuidedApprovalReasons((current) => ({ ...current, [candidate.number]: e.target.value }))}
                                                        placeholder="Alasan approval DB-only"
                                                        className="h-8 text-xs"
                                                    />
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        variant="outline"
                                                        className="h-8 gap-2"
                                                        disabled={candidate.conflicts.length > 0}
                                                        onClick={() => approveGuidedCandidate(candidate)}
                                                    >
                                                        <ShieldCheck className="h-3.5 w-3.5" />Approve DB
                                                    </Button>
                                                    <p className="text-xs text-muted-foreground">Tidak update customer/perangkat.</p>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                )}
                                <div className="border-t pt-2 text-xs text-muted-foreground">Kontrak berikutnya: {lookupResult.next_action}. Belum ada data atau perangkat yang diubah.</div>
                            </div>
                        )}
                    </section>
                )}

                <div className="rounded-lg border bg-card p-4">
                    <div className="mb-3 flex items-center justify-between gap-2">
                        <div className="text-sm font-semibold">Sinyal Grup Aktivasi</div>
                        <Badge variant="outline">Read-only</Badge>
                    </div>
                    {activationSignals.length === 0 ? (
                        <p className="text-sm text-muted-foreground">Belum ada sinyal WA untuk migrasi/PSB.</p>
                    ) : (
                        <div className="grid gap-2 md:grid-cols-2 xl:grid-cols-3">
                            {activationSignals.map((s) => (
                                <div key={s.id} className="rounded-md border bg-background/60 p-3 text-sm">
                                    <div className="flex items-center justify-between gap-2"><MigrationStatusBadge status={s.intent} /><span className="text-xs text-muted-foreground">{s.created_at}</span></div>
                                    <p className="mt-2 line-clamp-2">{s.summary || '-'}</p>
                                    <p className="mt-1 text-xs text-muted-foreground">{s.sender_name || s.group_label || '-'}</p>
                                </div>
                            ))}
                        </div>
                    )}
                </div>

                {importReady && (
                    <div className="rounded-lg border bg-card">
                        <div className="flex flex-col gap-3 border-b p-4 lg:flex-row lg:items-end lg:justify-between">
                            <div>
                                <div className="flex items-center gap-2 text-sm font-semibold"><FileSpreadsheet className="h-4 w-4" />Import Migrasi Staging</div>
                                <div className="text-xs text-muted-foreground">Dry-run snapshot, tidak commit ke pelanggan/OLT/MikroTik. PSB baru Starlite/Randuagung dari Excel dinormalisasi ke RW 4; jika domisili asli beda, koreksi manual per row/bulk dengan alasan.</div>
                            </div>
                            <form
                                className="grid gap-2 md:grid-cols-[220px_1fr_160px_auto]"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    router.post(route('customer-migrations.import-preview'), new FormData(e.currentTarget), { forceFormData: true, preserveScroll: true });
                                }}
                            >
                                <Input type="file" name="file" accept=".csv,.xlsx" className="h-9" />
                                <Input name="source_url" placeholder="URL snapshot opsional" className="h-9" />
                                <Input name="source_label" placeholder="Label batch" className="h-9" />
                                <Button type="submit" size="sm" className="h-9 gap-2"><FileSpreadsheet className="h-4 w-4" />Dry-run</Button>
                            </form>
                        </div>

                        <div className="grid gap-2 p-4 md:grid-cols-6">
                            <Card><CardContent className="p-3"><p className="text-xs text-muted-foreground">Staging</p><p className="text-xl font-bold">{importStats.total}</p></CardContent></Card>
                            <Card><CardContent className="p-3"><p className="text-xs text-muted-foreground">Migrasi</p><p className="text-xl font-bold">{importStats.migration}</p></CardContent></Card>
                            <Card><CardContent className="p-3"><p className="text-xs text-muted-foreground">PSB Baru</p><p className="text-xl font-bold">{importStats.psb_new_candidate}</p></CardContent></Card>
                            <Card><CardContent className="p-3"><p className="text-xs text-muted-foreground">Review</p><p className="text-xl font-bold">{importStats.needs_review}</p></CardContent></Card>
                            <Card><CardContent className="p-3"><p className="text-xs text-muted-foreground">Duplikat</p><p className="text-xl font-bold">{importStats.duplicate}</p></CardContent></Card>
                            <Card><CardContent className="p-3"><p className="text-xs text-muted-foreground">RW Review</p><p className="text-xl font-bold">{importStats.rw_needs_review}</p></CardContent></Card>
                        </div>

                        <div className="flex flex-wrap gap-2 px-4 pb-4">
                            {[
                                ['all', 'Semua'],
                                ['migration', 'Migrasi'],
                                ['psb_new_candidate', 'PSB Baru'],
                                ['needs_review', 'Match Lemah / Review'],
                                ['duplicate', 'Duplikat'],
                                ['not_migrated', 'Belum Migrasi'],
                                ['migrated', 'Sudah Migrasi'],
                                ['rw_needs_review', 'RW Review'],
                            ].map(([key, label]) => (
                                <Button key={key} size="sm" variant="outline" onClick={() => setImportFilter(key)}>{label}</Button>
                            ))}
                        </div>

                        <div className="grid gap-3 border-t p-4 lg:grid-cols-2">
                            <div className="grid gap-2 rounded-md border p-3">
                                <div className="text-sm font-semibold">Koreksi Bulk</div>
                                <div className="grid gap-2 md:grid-cols-[1fr_90px]">
                                    <select className="h-9 rounded-md border bg-background px-2 text-sm" value={bulkCorrection.classification} onChange={(e) => setBulkCorrection((current) => ({ ...current, classification: e.target.value }))}>
                                        <option value="migration">Migrasi</option>
                                        <option value="psb_new_candidate">PSB Baru</option>
                                        <option value="needs_review">Review</option>
                                    </select>
                                    <Input value={bulkCorrection.rw} onChange={(e) => setBulkCorrection((current) => ({ ...current, rw: e.target.value }))} placeholder="RW" className="h-9" />
                                </div>
                                <div className="grid gap-2 md:grid-cols-[1fr_auto]">
                                    <Input value={bulkCorrection.reason} onChange={(e) => setBulkCorrection((current) => ({ ...current, reason: e.target.value }))} placeholder={`Alasan koreksi bulk (wajib, min. ${IMPORT_REASON_MIN} karakter)`} aria-label="Alasan koreksi bulk" className="h-9" />
                                    <Button size="sm" variant="outline" className="h-9 gap-2" disabled={selectedImportIds.length === 0 || !bulkReasonValid || importActionBusy} onClick={() => setPendingImportAction('correct')}><Save className="h-4 w-4" />Koreksi Terpilih</Button>
                                </div>
                                {selectedImportIds.length > 0 && !bulkReasonValid && (
                                    <p className="text-xs text-muted-foreground">Isi alasan minimal {IMPORT_REASON_MIN} karakter untuk mengoreksi {selectedImportIds.length} baris.</p>
                                )}
                            </div>
                            <div className="grid gap-2 rounded-md border p-3">
                                <div className="text-sm font-semibold">Apply Staging</div>
                                <div className="text-xs text-muted-foreground">Apply hanya ke data Migrasi/PSB Starlite. Tidak ada write ke OLT, MikroTik, PPPoE, atau data pelanggan.</div>
                                <div className="grid gap-2 md:grid-cols-[1fr_auto]">
                                    <Input value={applyReason} onChange={(e) => setApplyReason(e.target.value)} placeholder={`Alasan apply staging (wajib, min. ${IMPORT_REASON_MIN} karakter)`} aria-label="Alasan apply staging" className="h-9" />
                                    <Button size="sm" className="h-9 gap-2" disabled={selectedImportIds.length === 0 || !applyReasonValid || importActionBusy} onClick={() => setPendingImportAction('apply')}><UploadCloud className="h-4 w-4" />Apply Terpilih</Button>
                                </div>
                                {selectedImportIds.length > 0 && !applyReasonValid && (
                                    <p className="text-xs text-muted-foreground">Isi alasan minimal {IMPORT_REASON_MIN} karakter untuk apply {selectedImportIds.length} baris.</p>
                                )}
                            </div>
                        </div>

                        <div className="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="w-10"><input type="checkbox" checked={importRows.length > 0 && selectedImportIds.length === importRows.filter((row) => !row.applied_at).length} onChange={(e) => toggleAllImportRows(e.target.checked)} /></TableHead>
                                        <TableHead>Batch</TableHead>
                                        <TableHead>Pelanggan</TableHead>
                                        <TableHead>Klasifikasi</TableHead>
                                        <TableHead>Area/RW</TableHead>
                                        <TableHead>SN Lama/Baru</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead>Apply</TableHead>
                                        <TableHead>Koreksi</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {importRows.length === 0 && (
                                        <TableRow><TableCell colSpan={9} className="h-20 text-center text-sm text-muted-foreground">Belum ada preview import.</TableCell></TableRow>
                                    )}
                                    {importRows.map((row) => {
                                        const correction = corrections[row.id] || { classification: row.classification, rw: row.rw || '', reason: '' };
                                        return (
                                            <TableRow key={row.id}>
                                                <TableCell><input type="checkbox" checked={selectedImportIds.includes(row.id)} disabled={!!row.applied_at} onChange={(e) => toggleImportRow(row.id, e.target.checked)} /></TableCell>
                                                <TableCell className="font-mono text-xs">{row.batch_key}<span className="block text-muted-foreground">row {row.row_number} · {row.source_label || '-'}</span></TableCell>
                                                <TableCell>
                                                    <div className="font-medium">{row.full_name || '-'}</div>
                                                    <div className="font-mono text-xs text-muted-foreground">{row.pppoe_user || '-'}</div>
                                                    {!!row.candidate_customers?.length && (
                                                        <div className="mt-2 space-y-1 border-l-2 pl-2 text-xs text-muted-foreground">
                                                            {row.candidate_customers.map((candidate) => (
                                                                <div key={candidate.id}>
                                                                    #{candidate.id} {candidate.name} · {candidate.pppoe_user || '-'}
                                                                    <span className="block">{candidate.area || '-'} / {candidate.router || '-'} / {candidate.sn || '-'} / {candidate.status || '-'}</span>
                                                                </div>
                                                            ))}
                                                        </div>
                                                    )}
                                                </TableCell>
                                                <TableCell><MigrationStatusBadge status={row.classification} /><span className="mt-1 block text-xs text-muted-foreground">{row.classification_source}</span></TableCell>
                                                <TableCell className="text-xs">{row.project || '-'}<span className="block text-muted-foreground">{row.area || '-'} / {row.rw || '-'}</span>{row.rw_needs_review && <Badge variant="warning" className="mt-1">RW review</Badge>}</TableCell>
                                                <TableCell className="font-mono text-xs">{row.sn_lama || '-'}<span className="block text-muted-foreground">{row.sn_baru || '-'} {row.sn_baru_source ? `(${row.sn_baru_source})` : ''}</span></TableCell>
                                                <TableCell className="max-w-[220px] text-xs">
                                                    <MigrationStatusBadge status={row.match_status} />
                                                    <div className="mt-1 text-muted-foreground">{row.match_notes || row.override_reason || '-'}</div>
                                                    {row.last_correction?.reason && <div className="mt-1 text-muted-foreground">Audit: {row.last_correction.reason}</div>}
                                                </TableCell>
                                                <TableCell className="max-w-[180px] text-xs">
                                                    <MigrationStatusBadge status={row.status} />
                                                    <div className="mt-1 text-muted-foreground">{row.applied_at ? `Applied ${row.applied_at}` : (row.applied_error || '-')}</div>
                                                    {row.applied_target_id && <div className="font-mono text-muted-foreground">#{row.applied_target_id}</div>}
                                                </TableCell>
                                                <TableCell>
                                                    <div className="grid min-w-[320px] gap-2">
                                                        <div className="grid grid-cols-[1fr_90px] gap-2">
                                                            <select className="h-9 rounded-md border bg-background px-2 text-sm" value={correction.classification} onChange={(e) => updateCorrection(row, { classification: e.target.value })}>
                                                                <option value="migration">Migrasi</option>
                                                                <option value="psb_new_candidate">PSB Baru</option>
                                                                <option value="needs_review">Review</option>
                                                            </select>
                                                            <Input value={correction.rw} onChange={(e) => updateCorrection(row, { rw: e.target.value })} placeholder="RW" className="h-9" />
                                                        </div>
                                                        <div className="grid grid-cols-[1fr_auto] gap-2">
                                                            <Input value={correction.reason} onChange={(e) => updateCorrection(row, { reason: e.target.value })} placeholder="Alasan koreksi" className="h-9" />
                                                            <Button size="sm" variant="outline" className="h-9 gap-2" onClick={() => saveCorrection(row)}><Save className="h-4 w-4" />Simpan</Button>
                                                        </div>
                                                    </div>
                                                </TableCell>
                                            </TableRow>
                                        );
                                    })}
                                </TableBody>
                            </Table>
                        </div>
                        <div className="flex items-center justify-between gap-3 border-t px-4 py-3 text-xs text-muted-foreground">
                            <span>Halaman {importPagination.current_page} · {importRows.length} dari {importPagination.total} baris</span>
                            <div className="flex gap-2">
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    disabled={!importPagination.previous_page}
                                    onClick={() => importPagination.previous_page && goToImportPage(importPagination.previous_page)}
                                >
                                    Sebelumnya
                                </Button>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    disabled={!importPagination.next_page}
                                    onClick={() => importPagination.next_page && goToImportPage(importPagination.next_page)}
                                >
                                    Berikutnya
                                </Button>
                            </div>
                        </div>
                    </div>
                )}

                <div className="overflow-hidden rounded-lg border bg-card">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>ID</TableHead>
                                <TableHead>Pelanggan</TableHead>
                                <TableHead>Teknisi WA</TableHead>
                                <TableHead>ONU Lama</TableHead>
                                <TableHead>ONU Baru</TableHead>
                                <TableHead>Router/OLT</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Catatan</TableHead>
                                <TableHead className="text-right">Aksi</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {migrations.length === 0 && (
                                <TableRow><TableCell colSpan={9} className="h-24 text-center text-sm text-muted-foreground">Belum ada data migrasi.</TableCell></TableRow>
                            )}
                            {migrations.map((m) => (
                                <TableRow key={m.id}>
                                    <TableCell className="font-mono text-xs">#{m.id}<span className="block text-muted-foreground">{m.created_at || '-'}</span></TableCell>
                                    <TableCell>
                                        <div className="font-medium">{m.full_name || '-'}</div>
                                        <div className="font-mono text-xs text-muted-foreground">{m.pppoe_user || '-'}</div>
                                    </TableCell>
                                    <TableCell className="text-sm">
                                        {m.technician_name || <span className="text-muted-foreground">-</span>}
                                        {m.technician_name && <span className="block text-xs text-muted-foreground">pengirim #migrasi</span>}
                                    </TableCell>
                                    <TableCell className="font-mono text-xs">
                                        {m.old_onu_ref || '-'}
                                        <span className="block text-muted-foreground">{m.old_sn || '-'}</span>
                                        <span className="block text-muted-foreground">{m.old_onu_deleted_at ? 'hapus: '+m.old_onu_deleted_at : 'hapus: -'}</span>
                                    </TableCell>
                                    <TableCell className="font-mono text-xs">
                                        {m.new_onu_ref || m.new_pon_port || '-'}
                                        <span className="block text-muted-foreground">{m.new_sn || '-'}</span>
                                        <span className="block text-muted-foreground">VLAN {m.vlan || '-'} / TR069 {m.tr069_vlan || '-'}</span>
                                    </TableCell>
                                    <TableCell className="text-sm">
                                        {m.router || '-'}
                                        <span className="block text-xs text-muted-foreground">{m.new_olt || m.old_olt || '-'}</span>
                                    </TableCell>
                                    <TableCell>
                                        <MigrationStatusBadge status={m.synced_at ? 'synced' : m.status} />
                                        {m.status_source === 'olt_snapshot+pppoe' && <span className="mt-1 block text-[10px] text-status-degraded">Snapshot live</span>}
                                    </TableCell>
                                    <TableCell className="max-w-[300px] text-xs text-muted-foreground">
                                        <div className="line-clamp-2">{m.status_detail || m.last_error || (m.synced_at ? 'Selesai '+m.synced_at : '-')}</div>
                                        {m.status !== 'synced' && m.review_status && <div className="mt-1"><MigrationStatusBadge status={m.review_status} /></div>}
                                        {m.device_action?.status && (
                                            <div className="mt-1 space-y-1">
                                                <MigrationStatusBadge status={m.device_action.status} />
                                                {m.device_action.ledger_id && <div className="font-mono">ledger #{m.device_action.ledger_id}</div>}
                                                {m.device_action.blocked_reason && <div>{m.device_action.blocked_reason}</div>}
                                            </div>
                                        )}
                                        {m.review_reasons?.length > 0 && <div className="mt-1">{m.review_reasons.join(', ')}</div>}
                                        {m.timeline?.length > 0 && (
                                            <div className="mt-1 font-mono text-[10px]">
                                                {timelineStepLabel[m.timeline[m.timeline.length - 1].step] ?? m.timeline[m.timeline.length - 1].step} · {m.timeline[m.timeline.length - 1].at || '-'}
                                            </div>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <div className="flex flex-col items-end gap-2">
                                            {m.review_status === 'confirmed_pending_approval' && m.status !== 'synced' && m.status !== 'cancelled' && (
                                                <div className="grid min-w-[260px] gap-1">
                                                    <Input
                                                        value={actionReasons[m.id] || ''}
                                                        onChange={(e) => setActionReasons((current) => ({ ...current, [m.id]: e.target.value }))}
                                                        placeholder="Alasan action migrasi"
                                                        className="h-8 text-xs"
                                                    />
                                                    <div className="flex justify-end gap-1">
                                                        <Button size="sm" variant="outline" className="h-8 gap-1" onClick={() => migrationAction(m.id, 'prepare')}>
                                                            <ShieldCheck className="h-3.5 w-3.5" />Dry-run
                                                        </Button>
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            className="h-8 gap-1"
                                                            disabled={!canExecuteMigrationAction(m)}
                                                            title={canExecuteMigrationAction(m) ? 'Execute perangkat' : 'Execute diblok kill switch; MIG-007 hanya dry-run'}
                                                            onClick={() => migrationAction(m.id, 'execute')}
                                                        >
                                                            <PlayCircle className="h-3.5 w-3.5" />Execute
                                                        </Button>
                                                    </div>
                                                </div>
                                            )}
                                            <div className="flex justify-end gap-1">
                                            {m.status !== 'synced' && m.status !== 'cancelled' && (
                                                <Button size="sm" variant="outline" onClick={() => router.post(route('customer-migrations.retry', m.id))}>Retry</Button>
                                            )}
                                            {m.status !== 'cancelled' && m.status !== 'synced' && (
                                                <Button size="sm" variant="ghost" onClick={() => router.post(route('customer-migrations.cancel', m.id))}>Batal</Button>
                                            )}
                                            {canManualComplete && m.can_manual_complete !== false && !m.synced_at && !['synced', 'cancelled'].includes(m.db_status ?? m.status) && (
                                                <Button size="sm" variant="outline" className="gap-1" onClick={() => openManualComplete(m)}>
                                                    <CheckCircle2 className="h-3.5 w-3.5" />Tandai selesai
                                                </Button>
                                            )}
                                            {m.customer_id && (
                                                <Button size="sm" variant="ghost" asChild>
                                                    <Link href={route('customers.show', m.customer_id)}>Customer</Link>
                                                </Button>
                                            )}
                                            </div>
                                        </div>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                    <div className="flex items-center justify-between gap-3 border-t px-4 py-3 text-xs text-muted-foreground">
                        <span>Halaman {pagination.current_page} · {migrations.length} dari {pagination.total} migrasi</span>
                        <div className="flex gap-2">
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                disabled={!pagination.previous_page}
                                onClick={() => pagination.previous_page && goToMigrationPage(pagination.previous_page)}
                            >
                                Sebelumnya
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                disabled={!pagination.next_page}
                                onClick={() => pagination.next_page && goToMigrationPage(pagination.next_page)}
                            >
                                Berikutnya
                            </Button>
                        </div>
                    </div>
                </div>
            </div>
            <ConfirmDestructive
                open={pendingImportAction !== null}
                onOpenChange={(open) => {
                    if (!open && !importActionBusy) setPendingImportAction(null);
                }}
                title={pendingImportAction === 'apply' ? 'Apply baris staging terpilih?' : 'Koreksi baris terpilih?'}
                description={
                    pendingImportAction === 'apply'
                        ? 'Baris terpilih akan dibuat/diperbarui sebagai data Migrasi/PSB Starlite. Tidak ada write ke OLT, MikroTik, PPPoE, atau data pelanggan.'
                        : 'Klasifikasi dan RW baris terpilih akan diganti. Alasan dicatat di audit setiap baris.'
                }
                confirmationLabel="Saya sudah memeriksa baris yang dipilih."
                confirmText={pendingImportAction === 'apply' ? `Apply ${selectedImportIds.length} baris` : `Koreksi ${selectedImportIds.length} baris`}
                isLoading={importActionBusy}
                confirmDisabled={pendingImportAction === 'apply' ? !applyReasonValid : !bulkReasonValid}
                onConfirm={() => (pendingImportAction === 'apply' ? applySelected() : bulkCorrectSelected())}
            >
                <div className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 rounded-lg border bg-muted/30 p-3 text-sm">
                    <span className="text-muted-foreground">Baris</span><span className="font-mono">{selectedImportIds.length}</span>
                    {pendingImportAction === 'correct' && (
                        <>
                            <span className="text-muted-foreground">Klasifikasi</span><span>{IMPORT_CLASSIFICATION_LABELS[bulkCorrection.classification] ?? bulkCorrection.classification}</span>
                            <span className="text-muted-foreground">RW</span><span className="font-mono">{bulkCorrection.rw || '-'}</span>
                        </>
                    )}
                    <span className="text-muted-foreground">Alasan</span>
                    <span className="break-words">{(pendingImportAction === 'apply' ? applyReason : bulkCorrection.reason).trim() || '-'}</span>
                </div>
            </ConfirmDestructive>
            <Dialog open={manualRow !== null} onOpenChange={(open) => { if (!open) closeManualComplete(); }}>
                <DialogContent className="max-w-xl">
                    <DialogHeader>
                        <DialogTitle>Tandai selesai migrasi #{manualRow?.id}</DialogTitle>
                        <DialogDescription>
                            Hanya mengubah data di eBilling. Tidak ada perubahan ke MikroTik, OLT, GenieACS, ONU lama, data pelanggan, atau WA.
                        </DialogDescription>
                    </DialogHeader>
                    {manualLoading && <p className="text-sm text-muted-foreground">Memuat bukti...</p>}
                    {manualPreview && (
                        <div className="grid gap-3 text-sm" data-testid="manual-complete-evidence">
                            {manualPreview.blocked && (
                                <div className="rounded-lg border border-destructive/40 bg-destructive/10 p-3 text-destructive">{manualPreview.blocked}</div>
                            )}
                            {manualPreview.warning && (
                                <div className="rounded-lg border border-status-degraded/40 bg-status-degraded/10 p-3">{manualPreview.warning}</div>
                            )}
                            <div className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 rounded-lg border bg-muted/30 p-3">
                                <span className="text-muted-foreground">Hasil cek</span>
                                <span>{manualPreview.verdict ? <><strong>{manualPreview.verdict.label}</strong> · {manualPreview.verdict.reason}</> : '-'}</span>
                                <span className="text-muted-foreground">Pelanggan</span>
                                <span>{manualPreview.customer ? `${manualPreview.customer.name} (${manualPreview.customer.pppoe_user || '-'})` : '-'}</span>
                                <span className="text-muted-foreground">ONU di OLT tujuan</span>
                                <span>
                                    {manualPreview.onu?.found
                                        ? `${manualPreview.onu.olt || '-'} ${manualPreview.onu.onu_ref || ''} · ${manualPreview.onu.online ? 'online' : (manualPreview.onu.state || 'offline')} · ${manualPreview.onu.collected_at || '-'}`
                                        : 'Tidak ditemukan'}
                                </span>
                                <span className="text-muted-foreground">PPPoE</span>
                                <span>
                                    {manualPreview.pppoe
                                        ? `${manualPreview.pppoe.online ? 'online' : 'offline'} · ${manualPreview.pppoe.router || '-'} · cek ${manualPreview.pppoe.checked_at || '-'}`
                                        : '-'}
                                </span>
                                <span className="text-muted-foreground">Jejak eksekusi</span>
                                <span>
                                    {manualPreview.traces
                                        ? [
                                            `MikroTik sudah pindah: ${manualPreview.traces.mikrotik_moved ? 'ya' : 'tidak'}`,
                                            `OLT sudah diprovisi: ${manualPreview.traces.olt_provisioned ? 'ya' : 'tidak'}`,
                                            `Ledger: ${manualPreview.traces.ledgers.length ? manualPreview.traces.ledgers.map((l) => `#${l.id} ${l.status}`).join(', ') : '-'}`,
                                        ].join(' · ')
                                        : '-'}
                                </span>
                            </div>
                            {!manualPreview.blocked && (
                                <div className="grid gap-1">
                                    <label htmlFor="manual-complete-reason" className="text-sm font-medium">Alasan (wajib, min. {manualPreview.reason_min} huruf)</label>
                                    <Input
                                        id="manual-complete-reason"
                                        value={manualReason}
                                        onChange={(e) => setManualReason(e.target.value)}
                                        placeholder="Contoh: sudah dicek, pelanggan jalan normal"
                                        maxLength={500}
                                    />
                                </div>
                            )}
                        </div>
                    )}
                    {manualError && <p className="text-sm text-destructive" role="alert">{manualError}</p>}
                    <DialogFooter>
                        <Button variant="ghost" onClick={closeManualComplete} disabled={manualBusy}>Batal</Button>
                        <Button
                            onClick={submitManualComplete}
                            disabled={manualBusy || manualLoading || !manualPreview || !!manualPreview.blocked || manualReason.trim().length < (manualPreview?.reason_min ?? 10)}
                        >
                            {manualBusy ? 'Menyimpan...' : 'Tandai selesai'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
            <ConfirmDestructive
                open={pendingExecution !== null}
                onOpenChange={(open) => {
                    if (!open) setPendingExecution(null);
                }}
                title="Konfirmasi execute migrasi"
                description="Periksa perubahan read-only berikut sebelum mengirim action perangkat. Execute dapat mengubah state perangkat dan data operasional."
                confirmationLabel="Saya sudah memeriksa preview dan menyetujui execute migrasi ini."
                confirmText="Execute migrasi"
                onConfirm={confirmMigrationExecution}
            >
                {pendingExecution && (
                    <div className="grid gap-3 rounded-lg border border-border bg-muted/30 p-3 text-sm">
                        <p className="font-medium">Preview perubahan #{pendingExecution.id}</p>
                        <div className="grid gap-2 sm:grid-cols-2">
                            <div>
                                <p className="text-xs text-muted-foreground">Identitas</p>
                                <p className="font-mono">{pendingExecution.pppoe_user || '-'} · {pendingExecution.full_name || '-'}</p>
                            </div>
                            <div>
                                <p className="text-xs text-muted-foreground">Router target</p>
                                <p>{pendingExecution.router || pendingExecution.new_olt || '-'}</p>
                            </div>
                            <div>
                                <p className="text-xs text-muted-foreground">SN lama → SN baru</p>
                                <p className="font-mono">{pendingExecution.old_sn || 'NOT_FOUND'} → {pendingExecution.new_sn || '-'}</p>
                            </div>
                            <div>
                                <p className="text-xs text-muted-foreground">ONU lama → ONU baru</p>
                                <p className="font-mono">{pendingExecution.old_onu_ref || 'NOT_FOUND'} → {pendingExecution.new_onu_ref || '-'}</p>
                            </div>
                            <div>
                                <p className="text-xs text-muted-foreground">OLT lama → OLT baru</p>
                                <p>{pendingExecution.old_olt || '-'} → {pendingExecution.new_olt || '-'}</p>
                            </div>
                            <div>
                                <p className="text-xs text-muted-foreground">VLAN internet / TR-069</p>
                                <p className="font-mono">{pendingExecution.vlan ?? '-'} / {pendingExecution.tr069_vlan ?? '-'}</p>
                            </div>
                        </div>
                        <p className="text-xs text-muted-foreground">Password PPPoE tidak ditampilkan di UI. Source of truth tetap router/MikroTik saat preflight.</p>
                    </div>
                )}
            </ConfirmDestructive>
        </EnterpriseIndexLayout>
    );
}
