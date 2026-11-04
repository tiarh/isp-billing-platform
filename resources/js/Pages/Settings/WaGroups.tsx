import { useCallback, useEffect, useState, type ReactNode } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { AlertTriangle, Loader2, MessageCircle, RefreshCw, Save } from 'lucide-react';
import { toast } from 'sonner';
import { EnterpriseIndexLayout } from '@/Components/enterprise/EnterpriseIndexLayout';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Switch } from '@/Components/ui/switch';
import { Alert, AlertDescription } from '@/Components/ui/alert';
import { StatusBadge } from '@/Components/StatusBadge';
import { ConfirmDialog } from '@/Components/ConfirmDialog';
import { ConfirmDestructive } from '@/Components/ConfirmDestructive';
import { EmptyState } from '@/Components/EmptyState';
import { GroupPicker } from '@/Components/WaGroups/GroupPicker';
import { useUnsavedChangesGuard } from '@/hooks/useUnsavedChangesGuard';
import { formatWibDateTime } from '@/lib/dateTime';
import type { WaChannel, WaGroupsResponse, WaMembership, WaSection, WaSettings } from '@/Components/WaGroups/types';

interface Props {
    settings: WaSettings;
    version: string;
}

type Errors = Record<string, string | undefined>;

/** Loads the whitelisted group list for one channel (read-only GET, never blocks the page). */
function useGroups(channel: WaChannel, enabled: boolean) {
    const [data, setData] = useState<WaGroupsResponse | null>(null);
    const [loading, setLoading] = useState(false);
    const [failed, setFailed] = useState(false);

    const load = useCallback(
        async (fresh: boolean) => {
            setLoading(true);
            setFailed(false);
            try {
                const res = await fetch(`${route('api.settings.wa-groups.groups')}?channel=${channel}${fresh ? '&fresh=1' : ''}`, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });
                if (!res.ok) throw new Error('http');
                setData((await res.json()) as WaGroupsResponse);
            } catch {
                setFailed(true);
            } finally {
                setLoading(false);
            }
        },
        [channel],
    );

    useEffect(() => {
        if (enabled) void load(false);
    }, [enabled, load]);

    return { data, loading, failed, reload: () => load(true) };
}

type GroupsState = ReturnType<typeof useGroups>;

function GroupsBanner({ state }: { state: GroupsState }) {
    const stale = state.failed || state.data?.stale;
    return (
        <div className="space-y-2">
            {stale && (
                <Alert>
                    <AlertTriangle className="h-4 w-4" aria-hidden />
                    <AlertDescription>
                        Daftar grup tidak bisa dimuat (server WA lambat/timeout).
                        {state.data?.fetched_at ? ` Menampilkan data terakhir dari ${formatWibDateTime(new Date(state.data.fetched_at * 1000).toISOString())}.` : ''} Anda tetap bisa memasukkan JID manual.
                    </AlertDescription>
                </Alert>
            )}
            <Button type="button" variant="outline" size="sm" onClick={() => void state.reload()} disabled={state.loading}>
                {state.loading ? <Loader2 className="mr-2 h-4 w-4 animate-spin" aria-hidden /> : <RefreshCw className="mr-2 h-4 w-4" aria-hidden />}
                Muat ulang daftar grup
            </Button>
        </div>
    );
}

function SectionShell({ title, description, children }: { title: string; description: ReactNode; children: ReactNode }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>{title}</CardTitle>
                <CardDescription>{description}</CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">{children}</CardContent>
        </Card>
    );
}

function maskedJid(jid: string): string {
    return jid === '' ? '(kosong)' : jid;
}

/** Shared save plumbing: router.put with the page version, server errors, success toast. */
function useSectionSave(section: WaSection, version: string) {
    const [saving, setSaving] = useState(false);
    const [errors, setErrors] = useState<Errors>({});

    const save = (payload: Record<string, unknown>, forceUnverified: boolean, onDone: () => void) => {
        setSaving(true);
        setErrors({});
        router.put(
            route('settings.wa-groups.update'),
            { section, version, force_unverified: forceUnverified ? [section] : [], ...payload },
            {
                preserveScroll: true,
                onSuccess: () => onDone(),
                onError: (e) => setErrors(e as Errors),
                onFinish: () => setSaving(false),
            },
        );
    };

    return { saving, errors, save };
}

/** Server refusal text (WaGroupSettingsWriter) for "membership could not be verified"; the force checkbox answers it. */
const UNVERIFIED_ERROR_MARKER = 'belum bisa diverifikasi';

function MembershipOverride({
    membership,
    jidChanged,
    checked,
    onChange,
    serverError,
}: {
    membership: WaMembership | null;
    jidChanged: boolean;
    checked: boolean;
    onChange: (v: boolean) => void;
    /** A server error shown for this card (e.g. the undelivered-group re-check on a Report channel switch). */
    serverError?: string;
}) {
    const serverUnverified = typeof serverError === 'string' && serverError.includes(UNVERIFIED_ERROR_MARKER);
    if (!serverUnverified && (!jidChanged || membership === null || membership.member !== null)) return null;
    return (
        <label className="flex items-start gap-2 text-sm">
            <input type="checkbox" className="mt-1" checked={checked} onChange={(e) => onChange(e.target.checked)} />
            <span>Simpan walau belum terverifikasi (keanggotaan nomor WA di grup ini belum bisa dipastikan).</span>
        </label>
    );
}

function ReportCard({ settings, version, ops, ticket, wantTicket }: { settings: WaSettings; version: string; ops: GroupsState; ticket: GroupsState; wantTicket: () => void }) {
    const r = settings.report;
    const [draft, setDraft] = useState(r);
    const [membership, setMembership] = useState<WaMembership | null>(null);
    const [force, setForce] = useState(false);
    const [confirm, setConfirm] = useState(false);
    const { saving, errors, save } = useSectionSave('report', version);
    // One shared list per channel (fetched once at page level), never one fetch per card.
    const groups = draft.channel === 'ticket' ? ticket : ops;
    useEffect(() => {
        if (draft.channel === 'ticket') wantTicket();
    }, [draft.channel, wantTicket]);

    // Reset only when the saved Report values really changed (another card's save must not drop edits).
    const savedReportKey = JSON.stringify(settings.report);
    useEffect(() => setDraft(settings.report), [savedReportKey]); // eslint-disable-line react-hooks/exhaustive-deps

    const dirty = JSON.stringify(draft) !== JSON.stringify(r);
    useUnsavedChangesGuard(dirty && !saving, 'Perubahan grup Report belum disimpan. Tinggalkan halaman?');
    const jidChanged = draft.group_jid !== r.group_jid || draft.channel !== r.channel;

    const submit = () => {
        setConfirm(false);
        save({ ...draft }, force, () => {
            toast.success('Pengaturan grup WhatsApp disimpan.');
            setForce(false);
        });
    };

    const flag = (key: 'daily_enabled' | 'recap_cmd_enabled' | 'pppoe_cmd_enabled' | 'onu_daily_enabled' | 'psb_detail_daily_enabled' | 'onu_recap_cmd_enabled', label: string, help?: string) => (
        <div className="flex items-start justify-between gap-4 rounded-md border p-3">
            <div className="space-y-1">
                <Label htmlFor={`report-${key}`}>{label}</Label>
                {help && <p className="text-xs text-muted-foreground">{help}</p>}
            </div>
            <Switch id={`report-${key}`} checked={draft[key]} onCheckedChange={(v) => setDraft((d) => ({ ...d, [key]: v }))} />
        </div>
    );

    return (
        <SectionShell title="Skynet Report" description="Satu grup untuk laporan harian dan perintah #rekap / #pppoe. Kosongkan untuk mematikan fitur Report.">
            <GroupsBanner state={groups} />
            <div className="space-y-2">
                <Label>Saluran WA pengirim</Label>
                <div className="flex gap-4 text-sm" role="radiogroup" aria-label="Saluran WA pengirim">
                    {(['ops', 'ticket'] as WaChannel[]).map((c) => (
                        <label key={c} className="flex items-center gap-2">
                            <input type="radio" name="report-channel" checked={draft.channel === c} onChange={() => setDraft((d) => ({ ...d, channel: c }))} />
                            {c === 'ops' ? 'Ops' : 'Ticket'}
                        </label>
                    ))}
                </div>
                {errors.channel && <p className="text-xs text-destructive">{errors.channel}</p>}
                {errors.version && <p className="text-xs text-destructive">{errors.version}</p>}
            </div>
            <div className="space-y-2">
                <Label htmlFor="report-group-jid">Grup Report</Label>
                <GroupPicker
                    id="report-group-jid"
                    section="report"
                    value={draft.group_jid}
                    onChange={(jid) => setDraft((d) => ({ ...d, group_jid: jid }))}
                    channel={draft.channel}
                    groups={groups.data?.groups ?? []}
                    savedValue={draft.channel === r.channel ? r.group_jid : ''}
                    onMembership={setMembership}
                    error={errors.group_jid ?? errors.jid}
                />
                {r.extra_jids.length > 0 && (
                    <p className="text-xs text-status-degraded">Ada {r.extra_jids.length} grup Report tambahan di pengaturan lama; menyimpan akan menyisakan satu grup saja.</p>
                )}
                <MembershipOverride membership={membership} jidChanged={jidChanged} checked={force} onChange={setForce} serverError={errors.channel} />
            </div>
            <div className="space-y-2">
                {flag('daily_enabled', 'Laporan harian otomatis', 'Kirim ringkasan PSB + migrasi kemarin tiap pagi.')}
                {flag('recap_cmd_enabled', 'Perintah #rekap di grup Report')}
                {flag('pppoe_cmd_enabled', 'Perintah #pppoe cari di grup Report')}
                {flag('onu_daily_enabled', 'Bagian "Kesehatan ONU" di laporan harian')}
                {flag('psb_detail_daily_enabled', 'Bagian "Draft PSB dan kendala" di laporan harian', 'Jumlah draft belum selesai, macet, dan gagal.')}
                {flag('onu_recap_cmd_enabled', 'Perintah #rekap onu di grup Report')}
                <p className="text-xs text-muted-foreground">
                    Saklar global #rekap PSB: <StatusBadge label={settings.readonly.psb_recap_cmd_enabled ? 'ON' : 'OFF'} tone={settings.readonly.psb_recap_cmd_enabled ? 'up' : 'neutral'} /> (hanya ditampilkan; #rekap psb di grup Report butuh saklar ini aktif).
                </p>
            </div>
            <Button type="button" onClick={() => setConfirm(true)} disabled={!dirty || saving}>
                {saving ? <Loader2 className="mr-2 h-4 w-4 animate-spin" aria-hidden /> : <Save className="mr-2 h-4 w-4" aria-hidden />}
                Simpan
            </Button>
            <ConfirmDialog
                open={confirm}
                onOpenChange={setConfirm}
                title="Simpan pengaturan grup Report?"
                description={`Grup: ${maskedJid(r.group_jid)} -> ${maskedJid(draft.group_jid)}. Saluran: ${r.channel} -> ${draft.channel}.`}
                confirmText="Simpan"
                onConfirm={submit}
                isLoading={saving}
            />
        </SectionShell>
    );
}

interface JidCardProps {
    section: 'ops_alert' | 'shadow' | 'undelivered' | 'ticket' | 'connectivity' | 'attendance';
    title: string;
    description: ReactNode;
    saved: string;
    version: string;
    channel: WaChannel;
    groups: GroupsState;
    extra?: ReactNode | ((errors: Errors) => ReactNode);
    extraPayload?: Record<string, unknown>;
    extraDirty?: boolean;
    emptyHint?: string;
    afterSave?: () => void;
}

function JidCard({ section, title, description, saved, version, channel, groups, extra, extraPayload, extraDirty, emptyHint, afterSave }: JidCardProps) {
    const [value, setValue] = useState(saved);
    const [membership, setMembership] = useState<WaMembership | null>(null);
    const [force, setForce] = useState(false);
    const [confirm, setConfirm] = useState(false);
    const { saving, errors, save } = useSectionSave(section, version);
    useEffect(() => setValue(saved), [saved]);

    const dirty = value !== saved || Boolean(extraDirty);
    useUnsavedChangesGuard(dirty && !saving, `Perubahan "${title}" belum disimpan. Tinggalkan halaman?`);

    const submit = () => {
        setConfirm(false);
        save({ jid: value, ...extraPayload }, force, () => {
            toast.success('Pengaturan grup WhatsApp disimpan.');
            setForce(false);
            afterSave?.();
        });
    };

    return (
        <SectionShell title={title} description={description}>
            <GroupsBanner state={groups} />
            <GroupPicker
                id={`${section}-jid`}
                section={section}
                value={value}
                onChange={setValue}
                channel={channel}
                groups={groups.data?.groups ?? []}
                savedValue={saved}
                onMembership={setMembership}
                error={errors.jid}
            />
            {value === '' && emptyHint && <p className="text-xs text-muted-foreground">{emptyHint}</p>}
            <MembershipOverride membership={membership} jidChanged={value !== saved && value !== ''} checked={force} onChange={setForce} />
            {typeof extra === 'function' ? extra(errors) : extra}
            {errors.version && <p className="text-xs text-destructive">{errors.version}</p>}
            <Button type="button" onClick={() => setConfirm(true)} disabled={!dirty || saving}>
                {saving ? <Loader2 className="mr-2 h-4 w-4 animate-spin" aria-hidden /> : <Save className="mr-2 h-4 w-4" aria-hidden />}
                Simpan
            </Button>
            <ConfirmDialog
                open={confirm}
                onOpenChange={setConfirm}
                title={`Simpan "${title}"?`}
                description={`${maskedJid(saved)} -> ${maskedJid(value)}`}
                confirmText="Simpan"
                onConfirm={submit}
                isLoading={saving}
            />
        </SectionShell>
    );
}

function ShadowCard({ settings, version, groups }: { settings: WaSettings; version: string; groups: GroupsState }) {
    const saved = settings.shadow;
    const [allow, setAllow] = useState(saved.allow_report_group);
    const [typed, setTyped] = useState(false);

    useEffect(() => setAllow(saved.allow_report_group), [saved.allow_report_group]);

    return (
        <>
            <JidCard
                section="shadow"
                title="Log teknis (shadow deteksi gangguan)"
                description="Ringkasan deteksi gangguan untuk tim teknis. Kosong = tidak ada WA. Grup PSB/Migrasi tidak pernah diizinkan."
                saved={saved.jid}
                version={version}
                channel="ops"
                groups={groups}
                extraPayload={{ allow_report_group: allow }}
                extraDirty={allow !== saved.allow_report_group}
                extra={
                    <div className="flex items-start justify-between gap-4 rounded-md border p-3">
                        <div className="space-y-1">
                            <Label htmlFor="shadow-allow">Izinkan ke grup Report/Alert Ops</Label>
                            <p className="text-xs text-muted-foreground">Default mati. Bila dimatikan sementara grup log teknis = grup Report/Ops, simpan akan ditolak.</p>
                        </div>
                        <Switch
                            id="shadow-allow"
                            checked={allow}
                            onCheckedChange={(v) => (v ? setTyped(true) : setAllow(false))}
                        />
                    </div>
                }
            />
            <ConfirmDestructive
                open={typed}
                onOpenChange={setTyped}
                title="Izinkan log teknis ke grup Report/Ops?"
                description="Ringkasan deteksi gangguan akan bisa masuk ke grup Report atau Alert Ops. Grup PSB tetap tidak pernah diizinkan."
                typedConfirmation="IZINKAN"
                typedConfirmationLabel="Ketik IZINKAN untuk melanjutkan."
                confirmText="Izinkan"
                onConfirm={() => {
                    setAllow(true);
                    setTyped(false);
                }}
            />
        </>
    );
}

function AttendanceCard({ settings, version, groups }: { settings: WaSettings; version: string; groups: GroupsState }) {
    const saved = settings.attendance;
    const [label, setLabel] = useState(saved.label);
    useEffect(() => setLabel(saved.label), [saved.label]);

    return (
        <JidCard
            section="attendance"
            title="Absensi"
            description="Grup WhatsApp yang dipantau untuk absensi teknisi. Kosong = absensi dari semua grup. Tidak boleh grup PSB/Migrasi."
            saved={saved.jid}
            version={version}
            channel="ops"
            groups={groups}
            extraPayload={{ label }}
            extraDirty={label !== saved.label}
            emptyHint="Kosong: absensi diterima dari semua grup."
            extra={(errors) => (
                <div className="space-y-2">
                    <Label htmlFor="attendance-label">Label grup (opsional)</Label>
                    <Input id="attendance-label" value={label} maxLength={255} onChange={(e) => setLabel(e.target.value)} placeholder="Nama grup absensi" />
                    {errors.label && <p className="text-xs text-destructive">{errors.label}</p>}
                </div>
            )}
        />
    );
}

function TechnicianCard({ settings, version, ops, ticket }: { settings: WaSettings; version: string; ops: GroupsState; ticket: GroupsState }) {
    const t = settings.technician;
    const [draft, setDraft] = useState(t);
    const [shareMembership, setShareMembership] = useState<WaMembership | null>(null);
    const [inputMembership, setInputMembership] = useState<WaMembership | null>(null);
    const [force, setForce] = useState(false);
    const [confirm, setConfirm] = useState(false);
    const { saving, errors, save } = useSectionSave('technician', version);
    const inputGroups = t.reply_channel === 'ops' ? ops : ticket;

    const savedKey = JSON.stringify(t);
    useEffect(() => setDraft(t), [savedKey]); // eslint-disable-line react-hooks/exhaustive-deps

    const dirty = JSON.stringify(draft) !== JSON.stringify(t);
    useUnsavedChangesGuard(dirty && !saving, 'Perubahan grup progres teknisi belum disimpan. Tinggalkan halaman?');

    const submit = () => {
        setConfirm(false);
        save(
            {
                share_jid: draft.share_jid,
                share_label: draft.share_label,
                input_jid: draft.input_jid,
                input_label: draft.input_label,
            },
            force,
            () => {
                toast.success('Pengaturan grup WhatsApp disimpan.');
                setForce(false);
            },
        );
    };

    return (
        <SectionShell
            title="Progres teknisi"
            description="Grup Share menerima rekap progres harian; Grup Input adalah chat tempat teknisi menulis progres (boleh nomor pribadi). Keduanya tidak boleh grup PSB/Migrasi. Jadwal dan template tetap diatur di Settings > Progress Harian Teknisi."
        >
            <GroupsBanner state={ticket} />
            <div className="space-y-2">
                <Label htmlFor="technician-share-jid">Grup Share progres</Label>
                <GroupPicker
                    id="technician-share-jid"
                    section="technician_share"
                    value={draft.share_jid}
                    onChange={(jid) => setDraft((d) => ({ ...d, share_jid: jid }))}
                    channel="ticket"
                    groups={ticket.data?.groups ?? []}
                    savedValue={t.share_jid}
                    onMembership={setShareMembership}
                    error={errors.share_jid}
                />
                <MembershipOverride membership={shareMembership} jidChanged={draft.share_jid !== t.share_jid && draft.share_jid !== ''} checked={force} onChange={setForce} />
                <Label htmlFor="technician-share-label">Label Grup Share</Label>
                <Input id="technician-share-label" value={draft.share_label} maxLength={120} onChange={(e) => setDraft((d) => ({ ...d, share_label: e.target.value }))} />
                {errors.share_label && <p className="text-xs text-destructive">{errors.share_label}</p>}
            </div>
            <div className="space-y-2">
                <Label htmlFor="technician-input-jid">Grup Input manual</Label>
                <GroupPicker
                    id="technician-input-jid"
                    section="technician_input"
                    allowPersonal
                    value={draft.input_jid}
                    onChange={(jid) => setDraft((d) => ({ ...d, input_jid: jid }))}
                    channel={t.reply_channel}
                    groups={inputGroups.data?.groups ?? []}
                    savedValue={t.input_jid}
                    onMembership={setInputMembership}
                    error={errors.input_jid}
                />
                <MembershipOverride membership={inputMembership} jidChanged={draft.input_jid !== t.input_jid && draft.input_jid !== ''} checked={force} onChange={setForce} />
                <Label htmlFor="technician-input-label">Label Grup Input</Label>
                <Input id="technician-input-label" value={draft.input_label} maxLength={120} onChange={(e) => setDraft((d) => ({ ...d, input_label: e.target.value }))} />
                {errors.input_label && <p className="text-xs text-destructive">{errors.input_label}</p>}
            </div>
            {errors.version && <p className="text-xs text-destructive">{errors.version}</p>}
            <Button type="button" onClick={() => setConfirm(true)} disabled={!dirty || saving}>
                {saving ? <Loader2 className="mr-2 h-4 w-4 animate-spin" aria-hidden /> : <Save className="mr-2 h-4 w-4" aria-hidden />}
                Simpan
            </Button>
            <ConfirmDialog
                open={confirm}
                onOpenChange={setConfirm}
                title="Simpan grup progres teknisi?"
                description={`Share: ${maskedJid(t.share_jid)} -> ${maskedJid(draft.share_jid)}. Input: ${maskedJid(t.input_jid)} -> ${maskedJid(draft.input_jid)}.`}
                confirmText="Simpan"
                onConfirm={submit}
                isLoading={saving}
            />
        </SectionShell>
    );
}

export default function WaGroups({ settings, version }: Props) {
    const flashErrors = (usePage().props as { errors?: Errors }).errors ?? {};
    const overlap = settings.readonly.overlap_with_psb;
    const sections = settings;
    // One list fetch per channel for the whole page (cards share it); the ticket list only when needed.
    const ops = useGroups('ops', true);
    // The ticket list is always needed now (Grup Tiket + Share progres teknisi use the ticket channel).
    const ticket = useGroups('ticket', true);
    const wantTicket = useCallback(() => undefined, []);

    return (
        <EnterpriseIndexLayout
            title="Grup WhatsApp"
            pageTitle="Grup WhatsApp"
            subtitle="Satu tempat untuk semua grup WhatsApp: Report, Alert Ops, Tiket, Connectivity, Absensi, Progres teknisi, log teknis, dan peringatan laporan gagal. Grup PSB/Migrasi hanya ditampilkan."
            icon={MessageCircle}
            backHref={route('settings.index')}
            breadcrumbs={[{ label: 'Settings', href: route('settings.index') }, { label: 'Grup WhatsApp' }]}
        >
            <div className="mx-auto max-w-4xl space-y-6">
                {flashErrors.version && (
                    <Alert>
                        <AlertTriangle className="h-4 w-4" aria-hidden />
                        <AlertDescription>
                            {flashErrors.version}{' '}
                            <Button type="button" variant="outline" size="sm" onClick={() => router.reload()}>
                                Muat ulang
                            </Button>
                        </AlertDescription>
                    </Alert>
                )}
                {overlap.length > 0 && (
                    <Alert>
                        <AlertTriangle className="h-4 w-4" aria-hidden />
                        <AlertDescription>BENTROK: {overlap.length} grup Report juga terdaftar sebagai grup PSB/Migrasi. Pilih grup Report lain.</AlertDescription>
                    </Alert>
                )}

                <SectionShell title="Aktivasi / PSB dan Migrasi" description="Halaman ini tidak mengubah grup PSB.">
                    {sections.readonly.psb_group_jids.length === 0 ? (
                        <EmptyState title="Belum ada grup PSB/Migrasi" description="Atur di Aktivasi > PSB WA." />
                    ) : (
                        <ul className="space-y-1 font-mono text-xs">
                            {sections.readonly.psb_group_jids.map((j) => (
                                <li key={j}>{j}</li>
                            ))}
                        </ul>
                    )}
                    <Link href={route('oltc300.index')} className="text-sm text-primary underline">
                        Ubah di Aktivasi &gt; PSB WA
                    </Link>
                </SectionShell>

                <ReportCard settings={settings} version={version} ops={ops} ticket={ticket} wantTicket={wantTicket} />

                <JidCard
                    section="ops_alert"
                    title="Alert Ops"
                    description="Grup tujuan alert operasional. Juga menerima laporan tiket selesai (ticket-closed). Tidak boleh grup PSB/Migrasi."
                    saved={settings.ops_alert.jid}
                    version={version}
                    channel="ops"
                    groups={ops}
                    emptyHint={settings.ops_alert.uses_env_default ? 'Kosong: memakai grup bawaan dari .env server.' : 'Kosong: memakai grup bawaan dari .env bila ada.'}
                />

                <ShadowCard settings={settings} version={version} groups={ops} />

                <JidCard
                    section="undelivered"
                    title="Peringatan laporan harian gagal"
                    description="Dikirim bila laporan harian belum terkirim. Tidak boleh grup Report, Alert Ops, atau PSB/Migrasi."
                    saved={settings.undelivered.jid}
                    version={version}
                    channel={settings.report.channel}
                    groups={settings.report.channel === 'ticket' ? ticket : ops}
                    emptyHint="Kosong: tidak ada WA peringatan (hanya log)."
                />

                <JidCard
                    section="ticket"
                    title="Grup Tiket"
                    description="Grup tujuan laporan tiket selesai dan perintah laporan tiket. Tidak boleh grup PSB/Migrasi dan tidak boleh sama dengan Alert Ops."
                    saved={settings.ticket.jid}
                    version={version}
                    channel="ticket"
                    groups={ticket}
                    emptyHint={settings.ticket.uses_env_default ? 'Kosong: memakai grup bawaan dari .env server.' : 'Kosong: memakai grup bawaan dari .env bila ada.'}
                />

                <JidCard
                    section="connectivity"
                    title="Alert Connectivity (NOC)"
                    description="Grup tujuan alert konektivitas pelanggan/OLT. Saklar aktif dan dry-run tetap di Monitoring. Tidak boleh grup PSB/Migrasi."
                    saved={settings.connectivity.jid}
                    version={version}
                    channel="ops"
                    groups={ops}
                    emptyHint={settings.connectivity.uses_env_default ? 'Kosong: memakai grup bawaan dari .env server.' : 'Kosong: alert tidak punya grup tujuan (kecuali .env).'}
                />

                <AttendanceCard settings={settings} version={version} groups={ops} />

                <TechnicianCard settings={settings} version={version} ops={ops} ticket={ticket} />
            </div>
        </EnterpriseIndexLayout>
    );
}
