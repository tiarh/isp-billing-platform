import { Button } from '@/Components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { readMetaCsrfToken, readXsrfCookieToken } from '@/lib/csrf';
import { Link } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';

export type EpisodeTicket = { id: number; code: string | null; status: string; status_label: string; url: string };

export type ManualTicketPage = { enabled: boolean; allowed: boolean; report_group_set: boolean };

export type TicketEpisode = {
    id: number;
    ticket?: EpisodeTicket | null;
    customer_open_ticket?: { id: number; url: string } | null;
    can_ticket?: boolean;
    report_wa_status?: string | null;
};

type Preview = {
    kind: 'single' | 'mass';
    title: string;
    priority: string;
    affected_count: number;
    lines: string[];
    report_group_set: boolean;
    ticket: EpisodeTicket | null;
};

const PRIORITIES = [
    { value: 'low', label: 'Rendah' },
    { value: 'normal', label: 'Normal' },
    { value: 'high', label: 'Tinggi' },
    { value: 'urgent', label: 'Mendesak' },
];

const FAILED_MESSAGE = 'Gagal membuat tiket. Coba lagi.';

function csrf(): string {
    return readMetaCsrfToken() || readXsrfCookieToken() || (window as any).csrfToken || '';
}

function errorText(status: number, message: unknown): string {
    if (status === 403) return 'Anda tidak punya akses membuat tiket.';
    if ((status === 409 || status === 422) && typeof message === 'string' && message !== '') return message;
    return FAILED_MESSAGE;
}

/** "Buat tiket" of one Deteksi Gangguan row: the ticket link, or the button + confirm dialog. */
export default function OutageTicketAction({ episode, manual, onChanged }: { episode: TicketEpisode; manual?: ManualTicketPage; onChanged: () => void }) {
    const [open, setOpen] = useState(false);
    const [preview, setPreview] = useState<Preview | null>(null);
    const [loading, setLoading] = useState(false);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [priority, setPriority] = useState('');
    const [note, setNote] = useState('');

    const ticket = episode.ticket ?? null;

    if (ticket) {
        const done = ticket.status === 'closed' || ticket.status === 'resolved';
        const cancelled = ticket.status === 'cancelled';
        return (
            <div className="space-y-0.5 text-xs" data-testid="outage-ticket-link">
                <Link href={ticket.url} className="font-medium text-primary hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
                    Tiket #{ticket.id}
                </Link>
                {(done || cancelled) && <span className="text-muted-foreground"> ({done ? 'selesai' : 'dibatalkan'})</span>}
                {episode.report_wa_status === 'failed' && <div className="text-muted-foreground">Pesan ke grup Report gagal terkirim.</div>}
                {episode.report_wa_status === 'unknown' && <div className="text-muted-foreground">Pesan ke grup Report belum pasti terkirim.</div>}
            </div>
        );
    }

    if (episode.customer_open_ticket) {
        return (
            <div className="text-xs" data-testid="outage-ticket-existing">
                <Link href={episode.customer_open_ticket.url} className="font-medium text-primary hover:underline">
                    Sudah ada tiket #{episode.customer_open_ticket.id}
                </Link>
            </div>
        );
    }

    if (!episode.can_ticket || !manual?.enabled || !manual.allowed) {
        return <div className="text-xs text-muted-foreground">Belum ada tiket</div>;
    }

    async function openDialog() {
        setOpen(true);
        setPreview(null);
        setError(null);
        setNote('');
        setLoading(true);
        try {
            const res = await fetch(route('api.monitoring.outage-shadow.ticket-preview', episode.id), {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            const json = await res.json().catch(() => null);
            if (!res.ok) {
                setError(errorText(res.status, json?.message));
                return;
            }
            const data = json.data as Preview;
            setPreview(data);
            setPriority(data.priority);
            if (data.ticket) {
                // Someone made it meanwhile: show the result, nothing to confirm.
                toast.success('Tiket sudah ada: #' + data.ticket.id + '.');
                setOpen(false);
                onChanged();
            }
        } catch {
            setError(FAILED_MESSAGE);
        } finally {
            setLoading(false);
        }
    }

    async function submit() {
        setSaving(true);
        setError(null);
        try {
            const res = await fetch(route('api.monitoring.outage-shadow.ticket', episode.id), {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
                body: JSON.stringify({ priority: priority || undefined, note: note.trim() || undefined }),
            });
            const json = await res.json().catch(() => null);
            if (!res.ok) {
                setError(errorText(res.status, json?.message));
                return;
            }
            toast.success(typeof json?.message === 'string' ? json.message : 'Tiket dibuat.');
            setOpen(false);
            onChanged();
        } catch {
            setError(FAILED_MESSAGE);
        } finally {
            setSaving(false);
        }
    }

    const reportSet = preview?.report_group_set ?? manual.report_group_set;

    return (
        <>
            <Button type="button" size="sm" variant="outline" className="h-7 text-xs" onClick={() => void openDialog()} data-testid="outage-ticket-button">
                Buat tiket
            </Button>
            <Dialog open={open} onOpenChange={(v) => !saving && setOpen(v)}>
                <DialogContent className="max-h-[90vh] max-w-lg overflow-y-auto" data-testid="outage-ticket-dialog">
                    <DialogHeader>
                        <DialogTitle>Buat tiket gangguan?</DialogTitle>
                        <DialogDescription>Kejadian #{episode.id}</DialogDescription>
                    </DialogHeader>

                    {loading && <p className="text-sm text-muted-foreground">Memuat…</p>}
                    {error && (
                        <p className="rounded-md border border-status-down/30 bg-status-down-bg px-3 py-2 text-sm" role="alert" data-testid="outage-ticket-error">
                            {error}
                        </p>
                    )}

                    {preview && (
                        <div className="space-y-3 text-sm">
                            <p className="font-medium" data-testid="outage-ticket-title">
                                {preview.title}
                            </p>
                            <ul className="max-h-48 space-y-0.5 overflow-auto rounded-md border bg-muted/40 p-2 text-xs" data-testid="outage-ticket-lines">
                                {preview.lines.map((line, i) => (
                                    <li key={i} className="break-words">
                                        {line}
                                    </li>
                                ))}
                            </ul>
                            <div className="space-y-1">
                                <Label htmlFor={`outage-ticket-priority-${episode.id}`}>Prioritas</Label>
                                <select
                                    id={`outage-ticket-priority-${episode.id}`}
                                    value={priority}
                                    onChange={(e) => setPriority(e.target.value)}
                                    className="h-9 w-full rounded-md border border-input bg-card px-3 text-sm"
                                >
                                    {PRIORITIES.map((p) => (
                                        <option key={p.value} value={p.value}>
                                            {p.label}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor={`outage-ticket-note-${episode.id}`}>Catatan (opsional)</Label>
                                <Textarea
                                    id={`outage-ticket-note-${episode.id}`}
                                    value={note}
                                    maxLength={500}
                                    rows={2}
                                    onChange={(e) => setNote(e.target.value)}
                                />
                            </div>
                            <p className="text-xs text-muted-foreground" data-testid="outage-ticket-info">
                                Tiket dibuat tanpa teknisi. Pelanggan tidak dikirimi pesan.{' '}
                                {reportSet ? 'Grup Report menerima 1 pesan.' : 'Grup Report belum diatur, jadi tidak ada pesan WA.'}
                            </p>
                        </div>
                    )}

                    <DialogFooter className="gap-2 sm:gap-0">
                        <Button type="button" variant="outline" onClick={() => setOpen(false)} disabled={saving}>
                            Batal
                        </Button>
                        <Button type="button" onClick={() => void submit()} disabled={saving || loading || !preview}>
                            {saving ? 'Membuat…' : 'Buat tiket'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
