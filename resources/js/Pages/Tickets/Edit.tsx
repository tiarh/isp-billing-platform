import { EnterpriseFormLayout } from '@/Components/enterprise/EnterpriseFormLayout';
import { Link, useForm, router } from '@inertiajs/react';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Textarea } from '@/Components/ui/textarea';
import { Save, TicketCheck } from 'lucide-react';
import { FormEvent } from 'react';
import { formatWibDateTimeLocalInput } from '@/lib/dateTime';
import DismantleReasonFields, { DismantleReasonOption } from '@/Components/DismantleReasonFields';
import RootCausePicker, { type RepairSignature, type RootCauseOption, type RootCauseSuggestion } from '@/Components/RootCausePicker';

interface Customer { id: number; code: string; name: string; phone: string; }
interface UserRec { id: number; name: string; role: string; phone?: string; }

interface Ticket {
    id: number; code: string; report_id: string | null; customer_id: number | null;
    customer_name: string | null; customer_phone: string | null; customer_coords: string | null;
    gangguan: string; keterangan: string | null; status: string; priority: string; type: string;
    assigned_to: number | null; due_at: string | null;
    dismantle_reason?: string | null; dismantle_reason_note?: string | null; root_cause?: string | null; root_cause_note?: string | null;
    customer: Customer | null;
}

interface Props {
    ticket: Ticket;
    customers: Customer[];
    technicians: UserRec[];
    validTransitions: string[];
    dismantleReasonOptions?: DismantleReasonOption[];
    rootCauseOptions?: RootCauseOption[];
    rootCauseSuggestion?: RootCauseSuggestion | null;
    repairSignature?: RepairSignature | null;
}

const STATUS_LABELS: Record<string, string> = {
    open: 'Open', assigned: 'Assigned', in_progress: 'Proses',
    pending: 'Pending', resolved: 'Resolved', closed: 'Closed',
};

export default function Edit({ ticket, customers, technicians, validTransitions, dismantleReasonOptions = [], rootCauseOptions = [], rootCauseSuggestion = null, repairSignature = null }: Props) {
    const dueAtStr = ticket.due_at
        ? formatWibDateTimeLocalInput(ticket.due_at)
        : '';

    const { data, setData, put, processing, errors, clearErrors } = useForm({
        customer_name: ticket.customer_name || '',
        customer_phone: ticket.customer_phone || '',
        customer_coords: ticket.customer_coords || '',
        gangguan: ticket.gangguan || '',
        keterangan: ticket.keterangan || '',
        type: ticket.type || 'incident',
        priority: ticket.priority || 'normal',
        status: ticket.status || 'open',
        due_at: dueAtStr as string,
        dismantle_reason: ticket.dismantle_reason || '',
        dismantle_reason_note: ticket.dismantle_reason_note || '',
        // A cause only the system writes ("Ditutup otomatis") is not in the picker: do not send it back.
        root_cause: ticket.root_cause && rootCauseOptions.some((o) => o.value === ticket.root_cause) ? ticket.root_cause : '',
        root_cause_note: ticket.root_cause_note || '',
        root_cause_via: 'manual' as string,
    });
    // Closing an incident ticket needs "Penyebab sebenarnya", also when the type is changed in the same save.
    const closing = data.status === 'resolved' || data.status === 'closed';
    const incidentTicket = ticket.type === 'incident' || data.type === 'incident';
    const needsCause = rootCauseOptions.length > 0 && incidentTicket && closing
        && ticket.status !== 'resolved' && ticket.status !== 'closed' && !ticket.root_cause;

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        put(route('tickets.update', ticket.id), {
            onSuccess: () => router.visit(route('tickets.show', ticket.id)),
        });
    };

    return (
        <EnterpriseFormLayout
            title={`Edit ${ticket.code}`}
            pageTitle={`Edit Tiket ${ticket.code}`}
            subtitle={`Report ID: ${ticket.report_id || '-'}`}
            icon={TicketCheck}
            backHref={route('tickets.show', ticket.id)}
            breadcrumbs={[
                { label: 'Ticketing', href: route('tickets.index') },
                { label: ticket.code, href: route('tickets.show', ticket.id) },
                { label: 'Edit' },
            ]}
            maxWidth="5xl"
        >
            <form onSubmit={handleSubmit} className="space-y-6">
                    <Card>
                        <CardHeader><CardTitle className="text-base">Detail Gangguan</CardTitle></CardHeader>
                        <CardContent className="grid gap-4">
                            <div className="space-y-2">
                                <Label>Gangguan / Kendala</Label>
                                <Textarea
                                    value={data.gangguan}
                                    onChange={(e) => setData('gangguan', e.target.value)}
                                    rows={3}
                                />
                                {errors.gangguan && <p className="text-xs text-destructive">{errors.gangguan}</p>}
                            </div>
                            <div className="space-y-2">
                                <Label>Keterangan</Label>
                                <Textarea
                                    value={data.keterangan || ''}
                                    onChange={(e) => setData('keterangan', e.target.value)}
                                    rows={2}
                                />
                            </div>
                            <div className="grid gap-4 md:grid-cols-2">
                                <div className="space-y-2">
                                    <Label>Tipe</Label>
                                    <Select value={data.type} onValueChange={(v) => setData('type', v)}>
                                        <SelectTrigger className="h-9"><SelectValue /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="incident">Gangguan</SelectItem>
                                            <SelectItem value="installation">Pasang Baru</SelectItem>
                                            <SelectItem value="billing">Tagihan</SelectItem>
                                            <SelectItem value="request">Request</SelectItem>
                                            <SelectItem value="dismantle">Dismantle (ambil perangkat)</SelectItem>
                                            <SelectItem value="other">Lainnya</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="space-y-2">
                                    <Label>Prioritas</Label>
                                    <Select value={data.priority} onValueChange={(v) => setData('priority', v)}>
                                        <SelectTrigger className="h-9"><SelectValue /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="low">Low</SelectItem>
                                            <SelectItem value="normal">Normal</SelectItem>
                                            <SelectItem value="high">High</SelectItem>
                                            <SelectItem value="urgent">Urgent</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>
                            {data.type === 'dismantle' && (
                                <DismantleReasonFields
                                    options={dismantleReasonOptions}
                                    reason={data.dismantle_reason}
                                    note={data.dismantle_reason_note}
                                    onReasonChange={(v) => setData('dismantle_reason', v)}
                                    onNoteChange={(v) => setData('dismantle_reason_note', v)}
                                    reasonError={errors.dismantle_reason}
                                    noteError={errors.dismantle_reason_note}
                                />
                            )}
                            {rootCauseOptions.length > 0 && incidentTicket && (
                                <RootCausePicker
                                    id="edit-root-cause"
                                    required={needsCause}
                                    options={rootCauseOptions}
                                    suggestion={rootCauseSuggestion}
                                    signature={repairSignature}
                                    value={data.root_cause}
                                    onChange={(v, via) => { clearErrors('root_cause', 'root_cause_note'); setData((d) => ({ ...d, root_cause: v, root_cause_via: via, root_cause_note: v === 'other' ? d.root_cause_note : '' })); }}
                                    note={data.root_cause_note}
                                    onNoteChange={(v) => setData('root_cause_note', v)}
                                    error={errors.root_cause}
                                    noteError={errors.root_cause_note}
                                />
                            )}
                            <div className="grid gap-4 md:grid-cols-2">
                                <div className="space-y-2">
                                    <Label>Status</Label>
                                    <Select value={data.status} onValueChange={(v) => setData('status', v)}>
                                        <SelectTrigger className="h-9"><SelectValue /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value={ticket.status}>{STATUS_LABELS[ticket.status] || ticket.status} (current)</SelectItem>
                                            {validTransitions.map(s => (
                                                <SelectItem key={s} value={s}>{STATUS_LABELS[s] || s}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {errors.status && <p className="text-xs text-destructive">{errors.status}</p>}
                                </div>
                                <div className="space-y-2">
                                    <Label>Deadline / SLA</Label>
                                    <Input
                                        type="datetime-local"
                                        value={data.due_at}
                                        onChange={(e) => setData('due_at', e.target.value)}
                                        className="h-9"
                                    />
                                    <p className="text-xs text-muted-foreground">Opsional. Waktu mengikuti WIB. Kosongkan untuk menghapus deadline.</p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader><CardTitle className="text-base">Data Pelanggan</CardTitle></CardHeader>
                        <CardContent className="grid gap-4 md:grid-cols-2">
                            <div className="space-y-2">
                                <Label>Nama Pelanggan</Label>
                                <Input value={data.customer_name || ''} onChange={(e) => setData('customer_name', e.target.value)} className="h-9" />
                            </div>
                            <div className="space-y-2">
                                <Label>No HP</Label>
                                <Input value={data.customer_phone || ''} onChange={(e) => setData('customer_phone', e.target.value)} className="h-9" />
                            </div>
                            <div className="space-y-2 md:col-span-2">
                                <Label>Koordinat</Label>
                                <Input value={data.customer_coords || ''} onChange={(e) => setData('customer_coords', e.target.value)} className="h-9" />
                            </div>
                        </CardContent>
                    </Card>

                    <div className="flex gap-3">
                        <Button type="submit" disabled={processing} className="gap-2">
                            <Save className="h-4 w-4" />
                            Simpan Perubahan
                        </Button>
                        <Button variant="outline" asChild>
                            <Link href={route('tickets.show', ticket.id)}>Batal</Link>
                        </Button>
                    </div>
            </form>
        </EnterpriseFormLayout>
    );
}
