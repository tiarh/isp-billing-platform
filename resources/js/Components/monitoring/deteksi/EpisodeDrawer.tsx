import { StatusBadge } from '@/Components/StatusBadge';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/Components/ui/sheet';
import { formatDateTime } from '@/lib/format';
import { Link2 } from 'lucide-react';
import { type ReactNode, useCallback, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import CustomerLine from './CustomerLine';
import { type Customer, type Episode, HISTORY_SOURCE, type HistoryRow, locationText, STATE_TONE, WOULD_LABEL } from './types';

/** In-list filter box shows when a list is longer than this. */
const FILTER_BOX_FROM = 10;

function Section({ title, children, testid }: { title: string; children: ReactNode; testid?: string }) {
    return (
        <section className="space-y-1.5 border-t pt-3" data-testid={testid}>
            <h3 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">{title}</h3>
            {children}
        </section>
    );
}

function Row({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex flex-wrap gap-x-2 text-sm">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="tabular-nums">{children}</dd>
        </div>
    );
}

async function getJson<T>(url: string, signal: AbortSignal): Promise<T> {
    const res = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin', signal });
    if (!res.ok) throw new Error('unavailable');

    return (await res.json()) as T;
}

function DrawerBody({ episode, ticketAction }: { episode: Episode; ticketAction: ReactNode }) {
    const [full, setFull] = useState<Customer[] | null>(null);
    const [loadingFull, setLoadingFull] = useState(false);
    const [history, setHistory] = useState<HistoryRow[] | null>(null);
    const [historyOpen, setHistoryOpen] = useState(false);
    const [historyLoading, setHistoryLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [find, setFind] = useState('');
    const controllers = useRef<Set<AbortController>>(new Set());

    // Closing the panel (or moving to another episode) cancels what is still loading.
    useEffect(() => {
        const open = controllers.current;
        return () => open.forEach((c) => c.abort());
    }, []);

    const load = useCallback(async <T,>(url: string, done: (data: T) => void, failed: string, busy: (on: boolean) => void) => {
        const controller = new AbortController();
        controllers.current.add(controller);
        busy(true);
        setError(null);
        try {
            done((await getJson<{ data: T }>(url, controller.signal)).data);
        } catch (e) {
            if ((e as Error).name !== 'AbortError') setError(failed);
        } finally {
            controllers.current.delete(controller);
            if (!controller.signal.aborted) busy(false);
        }
    }, []);

    const expand = () =>
        load<Customer[]>(route('api.monitoring.outage-shadow.customers', episode.id), setFull, 'Gagal memuat daftar pelanggan.', setLoadingFull);

    const toggleHistory = () => {
        const open = !historyOpen;
        setHistoryOpen(open);
        if (open && history === null) {
            void load<HistoryRow[]>(route('api.monitoring.outage-shadow.history', episode.id), setHistory, 'Gagal memuat riwayat.', setHistoryLoading);
        }
    };

    const copyLink = async () => {
        const url = `${window.location.origin}/monitoring?tab=deteksi&open=${episode.id}`;
        try {
            await navigator.clipboard.writeText(url);
            toast.success('Tautan disalin.');
        } catch {
            toast.error('Tautan tidak bisa disalin.');
        }
    };

    const matched = episode.matched_customers ?? [];
    const matchedIds = new Set(matched.map((m) => m.id));
    const list = (full ?? episode.customers).filter((c) => !matchedIds.has(c.id));
    const needle = find.trim().toLowerCase();
    const shownList = needle === '' ? list : list.filter((c) => `${c.name} ${c.pppoe_user ?? ''} ${c.code ?? ''}`.toLowerCase().includes(needle));
    const more = full ? 0 : episode.customers_more;

    return (
        <>
            <SheetHeader>
                <SheetTitle>{episode.type_label}</SheetTitle>
                <SheetDescription asChild>
                    <div className="flex flex-wrap items-center gap-2">
                        <StatusBadge label={episode.state_label} tone={STATE_TONE[episode.state] ?? 'neutral'} />
                        <span className="font-mono text-xs">#{episode.id}</span>
                        <Button type="button" variant="ghost" size="sm" className="h-7 px-2 text-xs" onClick={() => void copyLink()}>
                            <Link2 className="mr-1 h-3.5 w-3.5" aria-hidden /> Salin tautan
                        </Button>
                    </div>
                </SheetDescription>
            </SheetHeader>

            <div className="min-h-0 flex-1 space-y-4 overflow-y-auto pr-1" data-testid="deteksi-drawer-body">
                {error && (
                    <p className="rounded-md border border-status-down/30 bg-status-down-bg px-3 py-2 text-sm" role="alert">
                        {error}
                    </p>
                )}

                <Section title="Waktu (WIB)">
                    <dl className="space-y-0.5">
                        <Row label="Mulai:">{formatDateTime(episode.opened_at)}</Row>
                        {episode.confirmed_at && <Row label="Masih putus sejak:">{formatDateTime(episode.confirmed_at)}</Row>}
                        {episode.resolved_at && <Row label="Selesai:">{formatDateTime(episode.resolved_at)}</Row>}
                    </dl>
                </Section>

                <Section title="Dugaan">
                    <dl className="space-y-0.5">
                        {episode.diagnosed_label && (
                            <Row label="Dugaan sistem:">
                                <span className="font-medium" data-testid="outage-diagnosed">
                                    {episode.diagnosed_label}
                                </span>
                            </Row>
                        )}
                        {episode.cause_text && (
                            <Row label="Dugaan sebab:">
                                <span className="font-medium">{episode.cause_text}</span>
                            </Row>
                        )}
                        {episode.cause_label && (
                            <Row label="Penyebab (diisi admin):">
                                <span className="font-medium">{episode.cause_label}</span>
                            </Row>
                        )}
                        {episode.detail && <Row label="Catatan:">{episode.detail}</Row>}
                        {!episode.diagnosed_label && !episode.cause_text && !episode.cause_label && (
                            <p className="text-sm text-muted-foreground">Belum ada dugaan.</p>
                        )}
                    </dl>
                </Section>

                <Section title="Lokasi">
                    <p className="text-sm">{locationText(episode)}</p>
                </Section>

                <Section title={`Pelanggan (${episode.affected_count})`} testid="deteksi-drawer-customers">
                    {(full ?? episode.customers).length > FILTER_BOX_FROM && (
                        <Input
                            type="search"
                            value={find}
                            onChange={(e) => setFind(e.target.value)}
                            placeholder="Cari di daftar ini"
                            aria-label="Cari di daftar pelanggan"
                            className="h-8 bg-card text-sm"
                        />
                    )}
                    <ul className={'space-y-1 text-sm' + (full ? ' max-h-72 overflow-auto' : '')}>
                        {matched.map((c) => (
                            <CustomerLine key={`m${c.id}`} c={c} matched />
                        ))}
                        {shownList.map((c) => (
                            <CustomerLine key={c.id} c={c} />
                        ))}
                    </ul>
                    {needle !== '' && shownList.length === 0 && <p className="text-xs text-muted-foreground">Tidak ada yang cocok.</p>}
                    {episode.customers_unlisted > 0 && <p className="text-xs text-muted-foreground">{episode.customers_unlisted} pelanggan tanpa rincian tersimpan</p>}
                    {more > 0 && (
                        <Button type="button" variant="link" size="sm" className="h-auto p-0 text-xs" disabled={loadingFull} onClick={() => void expand()}>
                            {loadingFull ? 'Memuat…' : `+${more} lainnya`}
                        </Button>
                    )}
                </Section>

                {episode.history_available && (
                    <Section title="Riwayat">
                        <Button type="button" variant="link" size="sm" className="h-auto p-0 text-xs" aria-expanded={historyOpen} onClick={toggleHistory}>
                            {historyOpen ? 'Tutup riwayat' : 'Riwayat'}
                        </Button>
                        {historyOpen && (
                            <ul className="space-y-0.5 text-xs" data-testid="outage-history">
                                {historyLoading && <li className="text-muted-foreground">Memuat…</li>}
                                {!historyLoading && (history ?? []).length === 0 && <li className="text-muted-foreground">Belum ada riwayat.</li>}
                                {(history ?? []).map((h, i) => (
                                    <li key={`${h.at}-${i}`} className="flex flex-wrap gap-x-2">
                                        <span className="tabular-nums text-muted-foreground">{formatDateTime(h.at)}</span>
                                        <span>{h.text}</span>
                                        <span className="text-muted-foreground">({HISTORY_SOURCE[h.kind]})</span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Section>
                )}

                <Section title="Tiket" testid="deteksi-drawer-ticket">
                    {ticketAction}
                </Section>

                <Section title="Mode uji">
                    <dl className="space-y-0.5">
                        <Row label="Seandainya aktif:">{episode.would_action ? (WOULD_LABEL[episode.would_action] ?? episode.would_action) : '-'}</Row>
                        {episode.suppressed_label && <Row label="Ditahan:">{episode.suppressed_label}</Row>}
                    </dl>
                </Section>
            </div>
        </>
    );
}

/**
 * Detail panel of one episode: right side on a desktop, the whole screen on a small one. It reads only the two
 * existing read endpoints (customers, history); everything else comes from the row the list already has.
 *
 * The panel is a side panel next to the list, not a question the user must answer, so it is marked as a
 * complementary region; the "Buat tiket" confirm box on top of it stays the one dialog.
 */
export default function EpisodeDrawer({ episode, onClose, ticketAction }: { episode: Episode | null; onClose: () => void; ticketAction: ReactNode }) {
    // keep the last episode while the panel slides out
    const last = useRef<Episode | null>(null);
    if (episode) last.current = episode;
    const shown = episode ?? last.current;

    return (
        <Sheet open={episode !== null} onOpenChange={(open) => !open && onClose()}>
            <SheetContent role="complementary" className="w-full sm:max-w-xl" data-testid="deteksi-drawer">
                {shown && <DrawerBody key={shown.id} episode={shown} ticketAction={ticketAction} />}
            </SheetContent>
        </Sheet>
    );
}
