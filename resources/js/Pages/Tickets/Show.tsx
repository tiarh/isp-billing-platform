import { EnterpriseResourceLayout } from '@/Components/enterprise/EnterpriseResourceLayout';
import { Link, useForm, router, usePage } from '@inertiajs/react';
import { formatWibDateTime } from '@/lib/dateTime';
import { compressImage } from '@/lib/compressImage';
import { Button } from '@/Components/ui/button';
import { Badge } from '@/Components/ui/badge';
import { ConfirmDestructive } from '@/Components/ConfirmDestructive';
import { LifecycleGuardNotice, lifecycleOverrideReady } from '@/Components/LifecycleGuardNotice';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Textarea } from '@/Components/ui/textarea';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import RootCausePicker, { type RepairSignature, type RootCauseOption, type RootCauseSuggestion } from '@/Components/RootCausePicker';
import DiagnosisPanel, { type DiagnosisPanelProps } from '@/Components/tickets/DiagnosisPanel';
import {
    Calendar, MapPin, Phone, User, AlertCircle, LoaderCircle, X, Camera, Wrench, FileText,
    MessageCircle, Plus, Clock, RefreshCw, UserPlus, Pencil, Activity, Send, TicketCheck, PackageCheck,
} from 'lucide-react';
import { FormEvent, useState, useRef, useEffect, useMemo } from 'react';

interface Customer { id: number; code: string; name: string; phone: string; geo_lat: number | null; geo_long: number | null; }
interface UserRec { id: number; name: string; role: string; phone?: string; }
interface TicketActivity { id: number; action: string; description: string | null; old_value: Record<string, any> | null; new_value: Record<string, any> | null; created_at: string; user: UserRec | null; }
interface TicketComment { id: number; body: string; created_at: string; user: UserRec | null; }
interface MonitoringEvidence {
    id: number; issue_label: string; severity: string; status: string; customer_name: string | null;
    customer_code: string | null; area: string | null; odp: string | null; pon_port: string | null;
    olt_name: string | null; onu_serial: string | null; onu_rx_power_dbm: string | number | null;
    pppoe_online: boolean | null; olt_status: string | null; first_seen_at: string | null; last_seen_at: string | null;
}
interface CustomerLifecycleApproval {
    id: number; lifecycle_action: string; label: string; reason: string; requested_by: string | null; requested_at: string;
    before: Record<string, any>; after: Record<string, any>; rollback_hint: string;
}
interface Ticket {
    id: number; code: string; report_id: string | null; customer_id: number | null;
    customer_name: string | null; customer_phone: string | null; customer_coords: string | null;
    gangguan: string; keterangan: string | null; status: string; priority: string; type: string;
    assigned_to: number | null; created_by: number | null; kendala: string | null;
    action_taken: string | null; photos: string[] | null; created_at: string; updated_at: string;
    assigned_at: string | null; resolved_at: string | null; due_at: string | null;
    sla_status?: string; sla_label?: string; root_cause?: string | null; root_cause_note?: string | null;
    customer: Customer | null; assignee: UserRec | null; creator: UserRec | null;
    activities: TicketActivity[]; comments: TicketComment[];
}
interface Props { ticket: Ticket; technicians: UserRec[]; validTransitions: string[]; allStatuses: string[]; waMeLink: string | null; waProvider?: string; monitoringEvidence?: MonitoringEvidence[]; customerLifecycleApprovals?: CustomerLifecycleApproval[]; dismantleReason?: { code: string; label: string; note: string | null } | null; rootCauseOptions?: RootCauseOption[]; rootCauseSuggestion?: RootCauseSuggestion | null; repairSignature?: RepairSignature | null; rootCause?: { code: string; label: string; note?: string | null } | null; diagnosisPanel?: DiagnosisPanelProps | null; detectionSource?: { episode_id: number; url: string } | null; deviceRecovery?: { approved_dismantle: boolean; pending_dismantle?: boolean; dismantle_ticket?: boolean; case: { id: number; status: string; linked?: boolean; ticket_code?: string | null } | null; close_blocked?: boolean }; }

const STATUS_COLORS: Record<string, string> = { open: 'border-transparent bg-status-info-bg text-status-info', assigned: 'border-transparent bg-primary-soft text-primary-soft-foreground', in_progress: 'border-transparent bg-status-degraded-bg text-status-degraded', pending: 'border-transparent bg-muted text-muted-foreground', resolved: 'border-transparent bg-status-up-bg text-status-up', closed: 'border-transparent bg-status-up-bg text-status-up' };
const STATUS_LABELS: Record<string, string> = { open: 'Open', assigned: 'Assigned', in_progress: 'Proses', pending: 'Pending', resolved: 'Resolved', closed: 'Closed' };
const PRIORITY_COLORS: Record<string, string> = { low: 'text-muted-foreground', normal: 'text-status-info', high: 'text-status-degraded', urgent: 'text-status-down' };
const TYPE_LABELS: Record<string, string> = { installation: 'Pasang Baru', incident: 'Gangguan', billing: 'Tagihan', request: 'Request', dismantle: 'Dismantle', other: 'Lainnya' };

function statusBadge(s: string) { return <Badge variant="outline" className={`${STATUS_COLORS[s] || ''} text-sm px-3 py-1`}>{STATUS_LABELS[s] || s}</Badge>; }
function priorityBadge(p: string) { return <span className={`text-xs font-semibold ${PRIORITY_COLORS[p] || ''}`}>{p.toUpperCase()}</span>; }
function DeadlineBadge({ dueAt }: { dueAt: string | null }) {
    const [now, setNow] = useState(Date.now());
    useEffect(() => { const t = setInterval(() => setNow(Date.now()), 60000); return () => clearInterval(t); }, []);
    if (!dueAt) return null;
    const diff = new Date(dueAt).getTime() - now;
    if (diff < 0) { const h = Math.floor(-diff / 3600000); const d = Math.floor(h / 24); return <Badge className="border-transparent bg-status-down-bg text-status-down gap-1"><Clock className="h-3 w-3" />OVERDUE {d > 0 ? `${d}d ` : ''}{h % 24}jam</Badge>; }
    if (diff < 86400000) { const h = Math.floor(diff / 3600000); const m = Math.floor((diff % 3600000) / 60000); return <Badge className="border-transparent bg-status-degraded-bg text-status-degraded gap-1"><Clock className="h-3 w-3" />Hampir deadline {h}j {m}m</Badge>; }
    const d = Math.floor(diff / 86400000); const h = Math.floor((diff % 86400000) / 3600000);
    return <Badge className="border-transparent bg-status-up-bg text-status-up gap-1"><Clock className="h-3 w-3" />{d}d {h}j lagi</Badge>;
}

function ActivityIcon({ action }: { action: string }) {
    const cm: Record<string, string> = { created: 'text-status-info bg-status-info-bg', assigned: 'bg-primary-soft text-primary-soft-foreground', status_changed: 'text-status-degraded bg-status-degraded-bg', updated: 'text-status-up bg-status-up-bg', commented: 'bg-primary-soft text-primary-soft-foreground' };
    const im: Record<string, any> = { created: Plus, assigned: UserPlus, status_changed: RefreshCw, updated: Pencil, commented: MessageCircle };
    const Icon = im[action] || Activity;
    return <div className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-full ${cm[action] || 'text-muted-foreground bg-muted'}`}><Icon className="h-4 w-4" /></div>;
}

function fmtVal(val: Record<string, any> | null): string {
    if (!val) return '';
    return Object.entries(val).map(([k, v]) => { if (k === 'status') return STATUS_LABELS[v] || v; if (k === 'assignee_name') return v; return `${k}: ${typeof v === 'object' ? JSON.stringify(v) : v}`; }).join(', ');
}

export default function Show({ ticket, technicians, validTransitions, allStatuses, waMeLink, waProvider, monitoringEvidence = [], customerLifecycleApprovals = [], dismantleReason = null, rootCauseOptions = [], rootCauseSuggestion = null, repairSignature = null, rootCause = null, diagnosisPanel = null, detectionSource = null, deviceRecovery }: Props) {
    const { auth } = usePage().props as any;
    const user = auth.user;
    const isAdmin = user.role === 'admin' || user.role === 'superadmin' || user.role === 'noc';
    const isAssignee = ticket.assigned_to === user.id;
    const canEdit = isAssignee || isAdmin;
    const [assignTo, setAssignTo] = useState(String(ticket.assigned_to || ''));
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const fileRef = useRef<HTMLInputElement>(null);

    // RCA-1: closing an incident ticket needs "Penyebab sebenarnya" (only while the server offers the options).
    const isClosingStatus = (s: string) => s === 'resolved' || s === 'closed';
    const causeRequiredForClose = ticket.type === 'incident' && rootCauseOptions.length > 0 && !isClosingStatus(ticket.status) && !ticket.root_cause;
    const [closeDialogStatus, setCloseDialogStatus] = useState<string | null>(null);

    // A cause only the system writes ("Ditutup otomatis") is not in the picker: do not send it back.
    const initialCause = ticket.root_cause && rootCauseOptions.some((o) => o.value === ticket.root_cause) ? ticket.root_cause : '';
    const updateForm = useForm({ status: ticket.status, root_cause: initialCause, root_cause_note: ticket.root_cause_note ?? '', root_cause_via: 'manual' as string });
    const submitStatus = (s: string) => { updateForm.setData('status', s); updateForm.put(route('tickets.update', ticket.id), { onSuccess: () => { setCloseDialogStatus(null); router.reload(); } }); };
    const handleStatus = (s: string) => {
        if (causeRequiredForClose && isClosingStatus(s)) {
            updateForm.clearErrors();
            setCloseDialogStatus(s);
            return;
        }
        submitStatus(s);
    };

    const assignForm = useForm({ assigned_to: '' });
    const handleAssign = (e: FormEvent) => { e.preventDefault(); assignForm.post(route('tickets.assign', ticket.id), { onSuccess: () => router.reload() }); };

    const techForm = useForm({ kendala: ticket.kendala || '', action_taken: ticket.action_taken || '', status: ticket.status, root_cause: initialCause, root_cause_note: ticket.root_cause_note ?? '', root_cause_via: 'manual' as string, photos: [] as File[] });
    const techNeedsCause = causeRequiredForClose && isClosingStatus(techForm.data.status);
    const recoveryForm = useForm({ device_type: 'other', idempotency_key: `recovery:create:ticket:${ticket.id}` });
    const photoPreviews = useMemo(
        () => techForm.data.photos.map((file) => ({ file, url: URL.createObjectURL(file) })),
        [techForm.data.photos],
    );
    useEffect(() => () => photoPreviews.forEach(({ url }) => URL.revokeObjectURL(url)), [photoPreviews]);
    const photoError = Object.entries(techForm.errors)
        .filter(([key]) => key === 'photos' || key.startsWith('photos.'))
        .map(([, value]) => value)
        .join(' ');
    // Phone cameras produce 3-8 MB / up to 8160px photos. Resize on the device
    // (same helper as Pengambilan Perangkat) so uploads stay small and within
    // the server limits; on failure compressImage returns the original file.
    const [photoBusy, setPhotoBusy] = useState(false);
    const addPhotos = async (picked: File[]) => {
        if (picked.length === 0) return;
        setPhotoBusy(true);
        try {
            const compressed = await Promise.all(picked.map((file) => compressImage(file)));
            techForm.setData((data) => ({ ...data, photos: [...data.photos, ...compressed].slice(0, 5) }));
        } finally {
            setPhotoBusy(false);
        }
    };
    // Dismantle tickets close automatically on warehouse receipt; until then the
    // server rejects Closed, so do not offer it (owner 2026-10-10).
    const closeBlocked = deviceRecovery?.close_blocked === true;
    const DISMANTLE_CLOSE_HINT = 'Tiket dismantle tertutup otomatis setelah perangkat diterima gudang. Pilih Resolved setelah perangkat diambil.';
    const techStatusOptions = validTransitions.filter((s) => ['in_progress', 'pending', 'resolved', 'closed'].includes(s) && !(s === 'closed' && closeBlocked));
    const techFieldError = [techForm.errors.status, techForm.errors.kendala, techForm.errors.action_taken].filter(Boolean).join(' ');
    const handleTechUpdate = (e: FormEvent) => {
        e.preventDefault();
        techForm.post(route('tickets.technician-update', ticket.id), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => router.reload(),
        });
    };

    const commentForm = useForm({ body: '' });
    const handleComment = (e: FormEvent) => { e.preventDefault(); commentForm.post(route('tickets.comment', ticket.id), { onSuccess: () => { commentForm.reset(); router.reload(); } }); };
    const lifecycleForm = useForm({ lifecycle_action: 'isolir', reason: '', new_odp_name: '', new_address: '' });
    const handleLifecycle = (e: FormEvent) => {
        e.preventDefault();
        lifecycleForm.post(route('tickets.customer-lifecycle.request', ticket.id), {
            onSuccess: () => {
                lifecycleForm.reset('reason', 'new_odp_name', 'new_address');
                router.reload();
            },
        });
    };
    const handleCreateRecovery = () => {
        recoveryForm.post(route('device-recovery.create', ticket.id), {
            preserveScroll: true,
            onSuccess: () => router.visit(route('device-recovery.index')),
        });
    };

    // T2: approve lifecycle mengubah status pelanggan di DB, jadi wajib konfirmasi before → after.
    const [pendingApproval, setPendingApproval] = useState<CustomerLifecycleApproval | null>(null);
    const [approving, setApproving] = useState(false);
    // Roadmap 1.3: a dismantle approval terminates the customer, so the lifecycle guard may refuse it
    // (customer still in use / unknown). The request stays pending; only a superadmin can override.
    const isSuperadmin = auth?.user?.role === 'superadmin';
    const [approvalGuard, setApprovalGuard] = useState<{ message: string; reasonError?: string } | null>(null);
    const [approvalOverrideReason, setApprovalOverrideReason] = useState('');
    const [approvalOverrideChecked, setApprovalOverrideChecked] = useState(false);
    useEffect(() => {
        setApprovalGuard(null);
        setApprovalOverrideReason('');
        setApprovalOverrideChecked(false);
    }, [pendingApproval]);
    const confirmApproval = () => {
        if (!pendingApproval) return;
        const overriding = approvalGuard !== null && isSuperadmin && approvalOverrideChecked;
        router.post(route('tickets.customer-lifecycle.approve', [ticket.id, pendingApproval.id]), overriding
            ? { override: true, override_reason: approvalOverrideReason.trim() }
            : {}, {
            preserveScroll: true,
            onStart: () => setApproving(true),
            onSuccess: () => {
                setPendingApproval(null);
                router.reload();
            },
            onError: (errors) => {
                if (errors.lifecycle_guard) {
                    setApprovalGuard({ message: errors.lifecycle_guard, reasonError: errors.override_reason });
                }
            },
            onFinish: () => setApproving(false),
        });
    };

    const confirmDelete = () => {
        setDeleting(true);
        router.delete(route('tickets.destroy', ticket.id), {
            onSuccess: () => setDeleteOpen(false),
            onError: () => setDeleteOpen(false),
            onFinish: () => setDeleting(false),
        });
    };

    return (
        <EnterpriseResourceLayout
            title={ticket.code}
            pageTitle={`Tiket ${ticket.code}`}
            subtitle={`Report ID: ${ticket.report_id} • ${TYPE_LABELS[ticket.type] || ticket.type}`}
            icon={TicketCheck}
            backHref={route('tickets.index')}
            breadcrumbs={[
                { label: 'Ticketing', href: route('tickets.index') },
                { label: ticket.code },
            ]}
            statusStrip={
                <div className="flex flex-wrap items-center gap-2">
                    {statusBadge(ticket.status)}
                    {priorityBadge(ticket.priority)}
                    <DeadlineBadge dueAt={ticket.due_at} />
                </div>
            }
            actions={
                <div className="flex gap-2 flex-wrap">
                    {canEdit && <Button variant="outline" size="sm" asChild><Link href={route('tickets.edit', ticket.id)}>Edit Tiket</Link></Button>}
                    {isAdmin && (((deviceRecovery?.approved_dismantle || deviceRecovery?.pending_dismantle) && ['resolved', 'closed'].includes(ticket.status)) || deviceRecovery?.dismantle_ticket || deviceRecovery?.case) && (deviceRecovery?.case ? <Button type="button" variant="outline" size="sm" asChild><Link href={route('device-recovery.index', { search: deviceRecovery?.case?.ticket_code || ticket.code })}><PackageCheck className="mr-2 h-4 w-4" aria-hidden />{deviceRecovery?.case?.linked ? 'Lihat Pengambilan (tertaut)' : 'Lihat Pengambilan'}</Link></Button> : <Button type="button" variant="outline" size="sm" onClick={handleCreateRecovery} disabled={recoveryForm.processing}><PackageCheck className="mr-2 h-4 w-4" aria-hidden />{recoveryForm.processing ? 'Membuat...' : 'Buat Pengambilan'}</Button>)}
                    {isAdmin && !ticket.resolved_at && ticket.status !== 'closed' && validTransitions.filter(s => !(s === 'closed' && closeBlocked)).map(s => (
                        <Button key={s} variant="outline" size="sm" onClick={() => handleStatus(s)} className={s === 'resolved' || s === 'closed' ? 'text-status-up' : s === 'pending' ? 'text-status-degraded' : ''}>{STATUS_LABELS[s] || s}</Button>
                    ))}
                    {updateForm.errors.status && <p className="w-full text-xs text-destructive" role="alert">{updateForm.errors.status}</p>}
                </div>
            }
        >
            <div className="grid gap-6 lg:grid-cols-3">
                    <div className="lg:col-span-2 space-y-6">
                        <Card>
                            <CardHeader><CardTitle className="flex items-center gap-2 text-base"><AlertCircle className="h-4 w-4 text-muted-foreground" />Detail Gangguan</CardTitle></CardHeader>
                            <CardContent className="space-y-3">
                                <div><Label className="text-muted-foreground">Gangguan</Label><p className="mt-1 text-sm whitespace-pre-wrap">{ticket.gangguan}</p></div>
                                {ticket.keterangan && <div><Label className="text-muted-foreground">Keterangan</Label><p className="mt-1 text-sm whitespace-pre-wrap">{ticket.keterangan}</p></div>}
                                {rootCause && <div data-testid="ticket-root-cause"><Label className="text-muted-foreground">Penyebab sebenarnya</Label><p className="mt-1 text-sm font-medium">{rootCause.label}</p>{rootCause.note && <p className="mt-1 text-sm whitespace-pre-wrap text-muted-foreground" data-testid="ticket-root-cause-note">{rootCause.note}</p>}</div>}
                                {dismantleReason && <div><Label className="text-muted-foreground">Alasan dismantle</Label><p className="mt-1 text-sm font-medium">{dismantleReason.label}</p>{dismantleReason.note && <p className="mt-1 text-sm whitespace-pre-wrap text-muted-foreground">{dismantleReason.note}</p>}</div>}
                            </CardContent>
                        </Card>

                        {diagnosisPanel && ticket.customer_id && (
                            <DiagnosisPanel customerId={ticket.customer_id} ticketId={ticket.id} initial={diagnosisPanel} />
                        )}

                        {canEdit && (
                            <Card>
                                <CardHeader><CardTitle className="flex items-center gap-2 text-base"><Wrench className="h-4 w-4 text-muted-foreground" />Input Teknisi</CardTitle></CardHeader>
                                <CardContent>
                                    <form onSubmit={handleTechUpdate} className="space-y-4" data-testid="ticket-technician-form">
                                        <div className="space-y-2"><Label htmlFor="tech-kendala">Kendala</Label><Textarea id="tech-kendala" value={techForm.data.kendala} onChange={e => techForm.setData('kendala', e.target.value)} placeholder="Kendala di lapangan..." rows={3} /></div>
                                        <div className="space-y-2"><Label htmlFor="tech-action">Tindakan</Label><Textarea id="tech-action" value={techForm.data.action_taken} onChange={e => techForm.setData('action_taken', e.target.value)} placeholder="Tindakan yang dilakukan..." rows={3} /></div>
                                        <div className="space-y-2"><Label htmlFor="tech-photo-input">Foto Bukti</Label>
                                            <div className="flex items-center gap-3"><Button type="button" variant="outline" className="h-10 gap-2" onClick={() => fileRef.current?.click()}><Camera className="h-4 w-4" />Pilih Foto</Button>
                                                <input ref={fileRef} id="tech-photo-input" type="file" multiple accept="image/jpeg,image/png,image/webp" className="hidden" data-testid="ticket-photo-input" onChange={e => { const f = Array.from(e.target.files || []); e.currentTarget.value = ''; void addPhotos(f); }} /></div>
                                            <p className="text-xs text-muted-foreground">{photoBusy ? 'Memproses foto...' : 'Maksimal 5 foto. JPG, PNG, atau WebP; foto otomatis diperkecil sebelum diupload.'}</p>
                                            {photoError && <p className="text-xs text-destructive" role="alert">{photoError}</p>}
                                            {techForm.data.photos.length > 0 && <div className="mt-2 flex flex-wrap gap-2">{photoPreviews.map(({ file, url }, i) => (
                                                <div key={`${file.name}-${file.lastModified}-${i}`} className="relative"><img src={url} alt={`Pratinjau foto ${i + 1}`} className="h-20 w-20 rounded-md border object-cover" />
                                                    <button type="button" aria-label={`Hapus foto ${i + 1}`} className="absolute -top-2 -right-2 flex h-8 w-8 items-center justify-center rounded-full bg-destructive text-destructive-foreground shadow" onClick={() => { const p = [...techForm.data.photos]; p.splice(i, 1); techForm.setData('photos', p as any); }}><X className="h-4 w-4" aria-hidden="true" /></button></div>
                                            ))}</div>}
                                        </div>
                                        {ticket.photos && ticket.photos.length > 0 && <div className="space-y-2"><Label className="text-muted-foreground">Foto Terupload</Label><div className="flex flex-wrap gap-2">{ticket.photos.map((p, i) => <a key={i} href={p} target="_blank" rel="noreferrer"><img src={p} alt="" className="h-20 w-20 rounded-md object-cover border hover:ring-2 hover:ring-primary" /></a>)}</div></div>}
                                        <div className="space-y-2"><Label htmlFor="tech-status">Update Status</Label>
                                            <Select value={techForm.data.status} onValueChange={v => techForm.setData('status', v)}>
                                                <SelectTrigger id="tech-status" className="h-10 w-full max-w-xs" data-testid="ticket-status-select"><SelectValue /></SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value={ticket.status}>{STATUS_LABELS[ticket.status] || ticket.status} (saat ini)</SelectItem>
                                                    {techStatusOptions.map(s => <SelectItem key={s} value={s}>{STATUS_LABELS[s] || s}</SelectItem>)}
                                                </SelectContent>
                                            </Select>
                                            {techStatusOptions.length === 0 && <p className="text-xs text-muted-foreground">Tidak ada status lain.</p>}
                                            {closeBlocked && <p className="text-xs text-muted-foreground" data-testid="ticket-dismantle-close-hint">{DISMANTLE_CLOSE_HINT}</p>}
                                            {techFieldError && <p className="text-xs text-destructive" role="alert" data-testid="ticket-update-error">{techFieldError}</p>}
                                        </div>
                                        {techNeedsCause && <RootCausePicker id="tech-root-cause" options={rootCauseOptions} suggestion={rootCauseSuggestion} signature={repairSignature} value={techForm.data.root_cause} onChange={(v, via) => { techForm.clearErrors('root_cause', 'root_cause_note'); techForm.setData(d => ({ ...d, root_cause: v, root_cause_via: via, root_cause_note: v === 'other' ? d.root_cause_note : '' })); }} note={techForm.data.root_cause_note} onNoteChange={v => techForm.setData('root_cause_note', v)} error={techForm.errors.root_cause} noteError={techForm.errors.root_cause_note} />}
                                        <Button type="submit" disabled={techForm.processing || photoBusy} className="w-full gap-2 sm:w-auto" data-testid="ticket-save-update">{techForm.processing || photoBusy ? <LoaderCircle className="h-4 w-4 animate-spin" /> : <Wrench className="h-4 w-4" />}Simpan Update</Button>
                                    </form>
                                </CardContent>
                            </Card>
                        )}

                        {(ticket.kendala || ticket.action_taken) && !canEdit && (
                            <Card>
                                <CardHeader><CardTitle className="flex items-center gap-2 text-base"><FileText className="h-4 w-4 text-muted-foreground" />Laporan Teknisi</CardTitle></CardHeader>
                                <CardContent className="space-y-3">
                                    {ticket.kendala && <div><Label className="text-muted-foreground">Kendala</Label><p className="mt-1 text-sm whitespace-pre-wrap">{ticket.kendala}</p></div>}
                                    {ticket.action_taken && <div><Label className="text-muted-foreground">Action</Label><p className="mt-1 text-sm whitespace-pre-wrap">{ticket.action_taken}</p></div>}
                                    {ticket.photos && ticket.photos.length > 0 && <div><Label className="text-muted-foreground">Foto</Label><div className="flex flex-wrap gap-2 mt-1">{ticket.photos.map((p, i) => <a key={i} href={p} target="_blank" rel="noreferrer"><img src={p} alt="" className="h-20 w-20 rounded-md object-cover border" /></a>)}</div></div>}
                                </CardContent>
                            </Card>
                        )}

                        <Card>
                            <CardHeader><CardTitle className="flex items-center gap-2 text-base"><Activity className="h-4 w-4 text-muted-foreground" />Bukti Monitoring</CardTitle></CardHeader>
                            <CardContent>
                                {monitoringEvidence.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">Belum ada bukti monitoring tertaut dari snapshot/cache.</p>
                                ) : (
                                    <div className="grid gap-2 md:grid-cols-2">
                                        {monitoringEvidence.map((e) => (
                                            <div key={e.id} className="rounded-md border bg-background/60 p-3 text-sm">
                                                <div className="flex items-center justify-between gap-2">
                                                    <Badge variant="outline" className={e.severity === 'critical' ? 'border-status-down/30 text-status-down' : 'border-status-degraded/30 text-status-degraded'}>{e.issue_label}</Badge>
                                                    <Badge variant="outline">{e.status}</Badge>
                                                </div>
                                                <p className="mt-2 font-medium">{e.customer_name || ticket.customer_name || '-'}</p>
                                                <p className="text-xs text-muted-foreground">{[e.customer_code, e.area, e.odp, e.pon_port].filter(Boolean).join(' · ') || '-'}</p>
                                                <div className="mt-2 grid grid-cols-2 gap-2 text-xs">
                                                    <span>OLT: {e.olt_status || '-'}</span>
                                                    <span>PPPoE: {e.pppoe_online ? 'online' : 'offline'}</span>
                                                    <span>RX: {e.onu_rx_power_dbm ?? '-'}</span>
                                                    <span>SN: {e.onu_serial || '-'}</span>
                                                </div>
                                                <p className="mt-2 text-xs text-muted-foreground">First {e.first_seen_at || '-'} · Last {e.last_seen_at || '-'}</p>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader><CardTitle className="flex items-center gap-2 text-base"><Activity className="h-4 w-4 text-muted-foreground" />Activity Timeline</CardTitle></CardHeader>
                            <CardContent>
                                {ticket.activities && ticket.activities.length > 0 ? (
                                    <div className="space-y-4">
                                        {ticket.activities.map((act) => (
                                            <div key={act.id} className="flex gap-3">
                                                <ActivityIcon action={act.action} />
                                                <div className="min-w-0 flex-1 pb-4">
                                                    <div className="flex items-baseline gap-2 flex-wrap">
                                                        <span className="text-sm font-medium">{act.user?.name || 'System'}</span>
                                                        <span className="text-xs text-muted-foreground">{formatWibDateTime(act.created_at)}</span>
                                                    </div>
                                                    <p className="text-sm text-muted-foreground mt-0.5">{act.description || act.action}</p>
                                                    {act.old_value && Object.keys(act.old_value).length > 0 && (
                                                        <div className="mt-1 text-xs">
                                                            <span className="text-status-down line-through">{fmtVal(act.old_value)}</span>
                                                            {act.new_value && Object.keys(act.new_value).length > 0 && (
                                                                <> <span className="text-muted-foreground mx-1">&rarr;</span> <span className="text-status-up">{fmtVal(act.new_value)}</span></>
                                                            )}
                                                        </div>
                                                    )}
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                ) : (
                                    <p className="text-sm text-muted-foreground">Belum ada activity.</p>
                                )}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader><CardTitle className="flex items-center gap-2 text-base"><MessageCircle className="h-4 w-4 text-muted-foreground" />Internal Comments</CardTitle></CardHeader>
                            <CardContent className="space-y-4">
                                {ticket.comments && ticket.comments.length > 0 && (
                                    <div className="space-y-3">
                                        {ticket.comments.map((c) => (
                                            <div key={c.id} className="rounded-lg border bg-muted/30 p-3">
                                                <div className="flex items-baseline gap-2 mb-1">
                                                    <span className="text-sm font-medium">{c.user?.name || 'Unknown'}</span>
                                                    <span className="text-xs text-muted-foreground">{formatWibDateTime(c.created_at)}</span>
                                                </div>
                                                <p className="text-sm whitespace-pre-wrap">{c.body}</p>
                                            </div>
                                        ))}
                                    </div>
                                )}
                                <form onSubmit={handleComment} className="space-y-2">
                                    <Textarea aria-label="Komentar internal" value={commentForm.data.body} onChange={e => commentForm.setData('body', e.target.value)} placeholder="Tulis komentar internal..." rows={3} />
                                    <div className="flex justify-end">
                                        <Button type="submit" size="sm" disabled={commentForm.processing || !commentForm.data.body.trim()} className="gap-2">
                                            {commentForm.processing ? <LoaderCircle className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4" />}Kirim
                                        </Button>
                                    </div>
                                </form>
                            </CardContent>
                        </Card>
                    </div>

                    {/* On phones (teknisi) customer contact comes first; on desktop it stays the right column. */}
                    <div className="order-first space-y-6 lg:order-none">
                        <Card>
                            <CardHeader><CardTitle className="text-base">Info Pelanggan</CardTitle></CardHeader>
                            <CardContent className="space-y-3 text-sm">
                                <div className="flex items-center gap-2"><User className="h-4 w-4 text-muted-foreground" /><span className="font-medium">{ticket.customer_name || ticket.customer?.name || '-'}</span></div>
                                {detectionSource && (
                                    <div className="flex items-center gap-2 text-muted-foreground" data-testid="ticket-detection-source">
                                        <FileText className="h-4 w-4" />
                                        <span>Sumber: Deteksi Gangguan, <Link href={detectionSource.url} className="font-medium text-primary underline-offset-4 hover:underline">kejadian #{detectionSource.episode_id}</Link></span>
                                    </div>
                                )}
                                {ticket.customer?.code && <div className="flex items-center gap-2 text-muted-foreground"><FileText className="h-4 w-4" />ID: {ticket.customer.code}</div>}
                                {(() => {
                                    const phone = ticket.customer_phone || ticket.customer?.phone || '';
                                    const digits = phone.replace(/[^0-9+]/g, '');
                                    return (
                                        <div className="flex items-center gap-2"><Phone className="h-4 w-4 text-muted-foreground" />
                                            {digits ? <a href={`tel:${digits}`} className="font-medium text-primary underline-offset-4 hover:underline">{phone}</a> : '-'}
                                        </div>
                                    );
                                })()}
                                {ticket.customer_coords && <div className="flex items-center gap-2"><MapPin className="h-4 w-4 text-muted-foreground" /><a href={`https://www.google.com/maps?q=${encodeURIComponent(ticket.customer_coords)}`} target="_blank" rel="noreferrer" className="text-primary underline-offset-4 hover:underline">{ticket.customer_coords}</a></div>}
                            </CardContent>
                        </Card>
                        {isAdmin && ticket.customer_id && (
                            <Card>
                                <CardHeader><CardTitle className="text-base">Lifecycle Pelanggan</CardTitle></CardHeader>
                                <CardContent className="space-y-4">
                                    <form onSubmit={handleLifecycle} className="space-y-3">
                                        <div className="space-y-2">
                                            <Label>Action</Label>
                                            <Select value={lifecycleForm.data.lifecycle_action} onValueChange={v => lifecycleForm.setData('lifecycle_action', v)}>
                                                <SelectTrigger className="h-9"><SelectValue /></SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="isolir">Isolir</SelectItem>
                                                    <SelectItem value="reconnect">Reconnect</SelectItem>
                                                    <SelectItem value="dismantle">Dismantle</SelectItem>
                                                    <SelectItem value="pindah_odp">Pindah ODP</SelectItem>
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        {lifecycleForm.data.lifecycle_action === 'pindah_odp' && (
                                            <>
                                                <div className="space-y-2">
                                                    <Label>ODP Baru</Label>
                                                    <input className="h-9 w-full rounded-md border bg-background px-3 text-sm" value={lifecycleForm.data.new_odp_name} onChange={e => lifecycleForm.setData('new_odp_name', e.target.value)} placeholder="ODP/RW baru" />
                                                </div>
                                                <div className="space-y-2">
                                                    <Label>Alamat Baru</Label>
                                                    <Textarea rows={2} value={lifecycleForm.data.new_address} onChange={e => lifecycleForm.setData('new_address', e.target.value)} placeholder="Opsional" />
                                                </div>
                                            </>
                                        )}
                                        <div className="space-y-2">
                                            <Label>Alasan</Label>
                                            <Textarea rows={3} value={lifecycleForm.data.reason} onChange={e => lifecycleForm.setData('reason', e.target.value)} placeholder="Wajib isi alasan approval" />
                                        </div>
                                        <Button type="submit" size="sm" disabled={lifecycleForm.processing || lifecycleForm.data.reason.trim().length < 5}>Ajukan Approval</Button>
                                    </form>

                                    {customerLifecycleApprovals.length > 0 && (
                                        <div className="space-y-2">
                                            <Label>Pending Approval</Label>
                                            {customerLifecycleApprovals.map(item => (
                                                <div key={item.id} className="rounded-md border bg-muted/30 p-3 text-xs">
                                                    <div className="flex items-center justify-between gap-2">
                                                        <Badge variant="outline">{item.label}</Badge>
                                                        <span className="text-muted-foreground">{item.requested_at}</span>
                                                    </div>
                                                    <p className="mt-2 text-sm">{item.reason}</p>
                                                    <p className="mt-1 text-muted-foreground">Status: {item.before?.status || '-'} ke {item.after?.status || '-'}</p>
                                                    {item.lifecycle_action === 'pindah_odp' && <p className="text-muted-foreground">ODP: {item.before?.odp_name || '-'} ke {item.after?.odp_name || '-'}</p>}
                                                    <Button size="sm" variant="outline" className="mt-2" disabled={approving} onClick={() => setPendingApproval(item)}>Approve</Button>
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                    <p className="text-xs text-muted-foreground">Approval ini hanya update DB pelanggan dan audit. Tidak menjalankan MikroTik/OLT.</p>
                                </CardContent>
                            </Card>
                        )}
                        <Card>
                            <CardHeader><CardTitle className="text-base">Teknisi</CardTitle></CardHeader>
                            <CardContent className="space-y-3">
                                {ticket.assignee ? (
                                    <div className="space-y-1">
                                        <div className="flex items-center gap-2 text-sm"><User className="h-4 w-4 text-muted-foreground" /><span className="font-medium">{ticket.assignee.name}</span><Badge variant="outline" className="text-xs">{ticket.assignee.role}</Badge></div>
                                        {ticket.assignee.phone && <a href={`https://wa.me/${ticket.assignee.phone}`} target="_blank" rel="noopener" className="inline-flex items-center gap-1 text-xs text-primary hover:underline pl-6"><Phone className="h-3.5 w-3.5" />{ticket.assignee.phone}</a>}
                                    </div>
                                ) : <p className="text-sm text-muted-foreground">Belum di-assign</p>}
                                {isAdmin && !ticket.resolved_at && (
                                    <form onSubmit={handleAssign} className="space-y-2">
                                        <Select value={assignTo} onValueChange={v => { setAssignTo(v); assignForm.setData('assigned_to', v); }}>
                                            <SelectTrigger className="h-9"><SelectValue placeholder="Assign teknisi" /></SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="__none__">Tanpa teknisi</SelectItem>
                                                {technicians.map(t => <SelectItem key={t.id} value={String(t.id)}>{t.name}{t.role === 'teknisi' ? ' (Teknisi)' : t.role === 'noc' ? ' (NOC)' : ''}{t.phone ? ` · ${t.phone}` : ''}</SelectItem>)}
                                            </SelectContent>
                                        </Select>
                                        <Button type="submit" size="sm" variant="outline" disabled={assignForm.processing}>Assign</Button>
                                    </form>
                                )}
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader><CardTitle className="text-base">Timeline</CardTitle></CardHeader>
                            <CardContent className="space-y-3 text-sm">
                                <div className="flex items-center gap-2 text-muted-foreground"><Calendar className="h-4 w-4" />Dibuat: {formatWibDateTime(ticket.created_at)}</div>
                                {ticket.creator && <div className="text-muted-foreground pl-6">oleh {ticket.creator.name}</div>}
                                {ticket.assigned_at && <div className="flex items-center gap-2 text-muted-foreground"><Calendar className="h-4 w-4" />Di-assign: {formatWibDateTime(ticket.assigned_at)}</div>}
                                {ticket.due_at && <div className="flex items-center gap-2"><Calendar className="h-4 w-4 text-muted-foreground" />SLA: {ticket.sla_label || 'Deadline'} - {formatWibDateTime(ticket.due_at)}</div>}
                                {ticket.resolved_at && <div className="flex items-center gap-2 text-status-up"><Calendar className="h-4 w-4" />Resolved: {formatWibDateTime(ticket.resolved_at)}</div>}
                                <div className="text-muted-foreground"><Calendar className="h-4 w-4 inline mr-2" />Update: {formatWibDateTime(ticket.updated_at)}</div>
                            </CardContent>
                        </Card>
                        {isAdmin && (
                            <Card>
                                <CardHeader><CardTitle className="text-base">Aksi</CardTitle></CardHeader>
                                <CardContent className="space-y-2">
                                    <Button variant="destructive" size="sm" onClick={() => setDeleteOpen(true)}>Hapus Tiket</Button>
                                    {waMeLink && ticket.assignee && (
                                        <div className="mt-3 space-y-2">
                                            <a href={waMeLink} target="_blank" rel="noopener noreferrer"
                                               className="inline-flex items-center gap-2 rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90 transition-colors">
                                                <MessageCircle className="h-4 w-4" />
                                                Kirim Notifikasi WA Manual
                                            </a>
                                            <p className="text-xs text-muted-foreground">Klik untuk buka WhatsApp Web dengan pesan terisi otomatis</p>
                                            {waProvider && waProvider !== 'wa_link' && waProvider !== 'disabled' && (
                                                <p className="text-xs text-status-info">Provider: {waProvider} — fallback ke manual link</p>
                                            )}
                                        </div>
                                    )}
                                    {!waMeLink && ticket.assignee && (
                                        <p className="text-xs text-muted-foreground mt-2">Nomor WA teknisi tidak tersedia</p>
                                    )}
                                </CardContent>
                            </Card>
                        )}
                    </div>
            </div>
            <Dialog open={closeDialogStatus !== null} onOpenChange={(open) => { if (!open && !updateForm.processing) setCloseDialogStatus(null); }}>
                <DialogContent className="max-h-[90vh] overflow-y-auto" data-testid="ticket-close-dialog">
                    <DialogHeader>
                        <DialogTitle>Tutup tiket gangguan</DialogTitle>
                        <DialogDescription>Pilih penyebab gangguan sebelum tiket {STATUS_LABELS[closeDialogStatus ?? ''] ?? ''} ditutup.</DialogDescription>
                    </DialogHeader>
                    <RootCausePicker id="close-root-cause" options={rootCauseOptions} suggestion={rootCauseSuggestion} signature={repairSignature} value={updateForm.data.root_cause} onChange={(v, via) => { updateForm.clearErrors('root_cause', 'root_cause_note'); updateForm.setData(d => ({ ...d, root_cause: v, root_cause_via: via, root_cause_note: v === 'other' ? d.root_cause_note : '' })); }} note={updateForm.data.root_cause_note} onNoteChange={v => updateForm.setData('root_cause_note', v)} error={updateForm.errors.root_cause} noteError={updateForm.errors.root_cause_note} />
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => setCloseDialogStatus(null)} disabled={updateForm.processing}>Batal</Button>
                        <Button type="button" onClick={() => closeDialogStatus && submitStatus(closeDialogStatus)} disabled={updateForm.processing || !updateForm.data.root_cause} data-testid="ticket-close-confirm">Tutup tiket</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
            <ConfirmDestructive
                open={pendingApproval !== null}
                onOpenChange={(open) => {
                    if (!open && !approving) setPendingApproval(null);
                }}
                title={`Approve ${pendingApproval?.label ?? 'perubahan pelanggan'}?`}
                description="Status pelanggan di database akan langsung diubah dan dicatat di audit. Approval ini tidak menjalankan MikroTik/OLT."
                confirmationLabel="Saya sudah memeriksa perubahan status pelanggan ini."
                confirmText={approvalGuard && isSuperadmin ? 'Tetap approve (override)' : 'Approve'}
                isLoading={approving}
                onConfirm={confirmApproval}
                confirmDisabled={approvalGuard !== null && !(isSuperadmin && lifecycleOverrideReady(approvalOverrideReason, approvalOverrideChecked))}
            >
                {approvalGuard && (
                    <LifecycleGuardNotice
                        message={approvalGuard.message}
                        isSuperadmin={isSuperadmin}
                        reason={approvalOverrideReason}
                        onReasonChange={setApprovalOverrideReason}
                        checked={approvalOverrideChecked}
                        onCheckedChange={setApprovalOverrideChecked}
                        reasonError={approvalGuard.reasonError}
                        disabled={approving}
                    />
                )}
                {pendingApproval && (
                    <div className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 rounded-lg border bg-muted/30 p-3 text-sm">
                        <span className="text-muted-foreground">Pelanggan</span><span>{ticket.customer_name || '-'}</span>
                        <span className="text-muted-foreground">Status</span>
                        <span className="font-medium">{pendingApproval.before?.status || '-'} → {pendingApproval.after?.status || '-'}</span>
                        {pendingApproval.lifecycle_action === 'pindah_odp' && (
                            <>
                                <span className="text-muted-foreground">ODP</span>
                                <span>{pendingApproval.before?.odp_name || '-'} → {pendingApproval.after?.odp_name || '-'}</span>
                            </>
                        )}
                        <span className="text-muted-foreground">Alasan</span><span className="break-words">{pendingApproval.reason}</span>
                        <span className="text-muted-foreground">Diajukan</span><span>{pendingApproval.requested_by || '-'} · {pendingApproval.requested_at}</span>
                        {pendingApproval.rollback_hint && (
                            <>
                                <span className="text-muted-foreground">Rollback</span><span className="text-muted-foreground">{pendingApproval.rollback_hint}</span>
                            </>
                        )}
                    </div>
                )}
            </ConfirmDestructive>
            <ConfirmDestructive
                open={deleteOpen}
                onOpenChange={setDeleteOpen}
                title="Hapus tiket"
                description={`Hapus tiket ${ticket.code}? Riwayat tiket akan ikut terhapus dari tampilan operasional.`}
                confirmationLabel="Saya memahami bahwa penghapusan tiket tidak dapat dibatalkan dari aplikasi."
                confirmText="Hapus tiket"
                onConfirm={confirmDelete}
                isLoading={deleting}
            />
        </EnterpriseResourceLayout>
    );
}
