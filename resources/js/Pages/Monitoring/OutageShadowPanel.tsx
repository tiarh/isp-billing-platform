import DataTable, { type Column, type PaginatedData } from '@/Components/DataTable';
import DiagnosisPrecisionCard from '@/Components/monitoring/DiagnosisPrecisionCard';
import CustomerSummary from '@/Components/monitoring/deteksi/CustomerSummary';
import EpisodeCard from '@/Components/monitoring/deteksi/EpisodeCard';
import EpisodeDrawer from '@/Components/monitoring/deteksi/EpisodeDrawer';
import { ageText, causeLine, type Episode, locationText, STATE_TONE } from '@/Components/monitoring/deteksi/types';
import { DataTablePagination } from '@/Components/DataTablePagination';
import { StatusBadge } from '@/Components/StatusBadge';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Switch } from '@/Components/ui/switch';
import { Tabs, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import { formatDateTime } from '@/lib/format';
import { router } from '@inertiajs/react';
import { ChevronRight, RefreshCw, Search, X } from 'lucide-react';
import { type ReactNode, useCallback, useEffect, useRef, useState } from 'react';
import OutageTicketAction, { type ManualTicketPage } from './OutageTicketAction';

type Suppression = {
    psb_days: number;
    migration_days: number;
    psb_customers_open: number;
    psb_customers_suppressing: number;
    psb_customers_aged_out: number;
    migration_customers_open: number;
    migration_customers_suppressing: number;
    migration_customers_aged_out: number;
};

type BlindRow = { id: number; kind: string; label: string; reason: string; since: string | null };

type Payload = PaginatedData<Episode> & {
    manual_ticket?: ManualTicketPage;
    suppression?: Suppression;
    data_blind?: BlindRow[];
    olt_clock_warnings?: Array<{ olt_id: number; olt_name: string; drift_minutes: number }>;
    olts: Array<{ id: number; name: string }>;
    /** Tab counts, chip counts, hidden-info count and pinned total: all computed by the server, never in the browser. */
    counts?: Record<View, number>;
    facets?: Array<{ value: string; label: string; count: number; info: boolean }>;
    info_count?: number;
    pinned_total?: number;
    search?: { q: string | null; customers_found: number; too_many: boolean };
    filters: {
        from: string;
        to: string;
        olt_id: number | null;
        type: string | null;
        state: string | null;
        episode: number | null;
        per_page: number;
        q: string | null;
        view: View;
        info: boolean;
        hours?: number | null;
    };
};

type View = 'perlu' | 'sedang' | 'selesai' | 'semua';

type Filters = {
    from: string;
    to: string;
    olt: string;
    type: string;
    state: string;
    episode: string;
    q: string;
    view: View;
    info: boolean;
    /** The "24 jam" preset: really the last 24 hours, not yesterday + today. */
    hours: boolean;
};

/** Status tabs; values are the server's VIEW_FILTERS. */
const VIEW_TABS: Array<{ value: View; label: string }> = [
    { value: 'perlu', label: 'Perlu dicek' },
    { value: 'sedang', label: 'Sedang dicek' },
    { value: 'selesai', label: 'Selesai' },
    { value: 'semua', label: 'Semua' },
];

/** Type chips of the "info" group: hidden unless the info toggle is on (the server's INFO_TYPES). */
const INFO_TYPES = ['weak_rx', 'data_blind'];

const SEARCH_MIN = 2;
const SEARCH_DEBOUNCE_MS = 300;

const PRESETS = [
    { value: '24h', label: '24 jam' },
    { value: '7d', label: '7 hari' },
    { value: '31d', label: '31 hari' },
    { value: 'custom', label: 'Pilih tanggal…' },
];

/** The old `state=` URL param (WA / ticket links) still filters; shown as a removable chip. */
const STATE_WORDS: Record<string, string> = {
    open: 'Baru',
    confirmed: 'Masih putus',
    resolved: 'Sudah normal',
    expired: 'Ditutup otomatis',
    suppressed: 'Ditahan',
};

function readFilters(): Filters {
    const q = new URLSearchParams(typeof window === 'undefined' ? '' : window.location.search);
    const view = q.get('view');
    const type = q.get('type') ?? 'all';
    const state = q.get('state') ?? 'all';
    return {
        from: q.get('from') ?? '',
        to: q.get('to') ?? '',
        olt: q.get('olt') ?? 'all',
        type,
        state,
        episode: q.get('episode') ?? '',
        q: (q.get('q') ?? '').trim(),
        // same rule as the server: an old `state=` link shows every tab
        view: VIEW_TABS.some((t) => t.value === view) ? (view as View) : state !== 'all' ? 'semua' : 'perlu',
        // asking for an info type / "ditahan" by name shows it without the toggle
        info: q.get('info') === '1' || INFO_TYPES.includes(type) || state === 'suppressed',
        hours: q.get('hours') === '24',
    };
}

/** The episode a shared link (`open=<id>`) points at, if any. */
function readOpenId(): number | null {
    const raw = new URLSearchParams(typeof window === 'undefined' ? '' : window.location.search).get('open');
    const id = raw === null ? 0 : Number(raw);

    return Number.isInteger(id) && id > 0 ? id : null;
}

function toQuery(f: Filters, page: number, perPage: number): Record<string, string> {
    const out: Record<string, string> = { view: f.view };
    if (f.from) out.from = f.from;
    if (f.to) out.to = f.to;
    if (f.hours) out.hours = '24';
    if (f.olt !== 'all') out.olt_id = f.olt;
    if (f.type !== 'all') out.type = f.type;
    if (f.state !== 'all') out.state = f.state;
    if (f.episode) out.episode = f.episode;
    if (f.q) out.q = f.q;
    if (f.info) out.info = '1';
    if (page > 1) out.page = String(page);
    if (perPage !== 25) out.per_page = String(perPage);
    return out;
}

let desiredUrl: string | null = null;

/** Puts the filters back in the URL when a late Inertia answer (it carries the URL of the first visit) replaced them. A Back step clears desiredUrl (popstate), so it is never undone. */
function restoreUrl() {
    if (desiredUrl === null) return;
    if (window.location.pathname + window.location.search === desiredUrl) return;
    const stayed = new URLSearchParams(window.location.search).get('tab') === 'deteksi' && window.location.pathname === desiredUrl.split('?')[0];
    if (!stayed) return;
    window.history.replaceState(window.history.state, '', desiredUrl);
    router.replace({ url: desiredUrl, preserveState: true, preserveScroll: true });
}

/**
 * Keep the browser URL in sync with the filters: the URL in the browser changes at once, and Inertia's own client-side replace
 * moves its saved page URL with it (no request, no history entry; its scroll saving cannot put an old URL back).
 */
function writeUrl(f: Filters, open: number | null) {
    const params = new URLSearchParams({ tab: 'deteksi' });
    if (f.from) params.set('from', f.from);
    if (f.to) params.set('to', f.to);
    if (f.hours) params.set('hours', '24');
    if (f.olt !== 'all') params.set('olt', f.olt);
    if (f.type !== 'all') params.set('type', f.type);
    if (f.state !== 'all') params.set('state', f.state);
    if (f.episode) params.set('episode', f.episode);
    if (f.q) params.set('q', f.q);
    if (f.view !== 'perlu') params.set('view', f.view);
    if (f.info) params.set('info', '1');
    if (open !== null) params.set('open', String(open));
    desiredUrl = `${window.location.pathname}?${params.toString()}`;
    window.history.replaceState(window.history.state, '', desiredUrl);
    router.replace({ url: `${window.location.pathname}?${params.toString()}`, preserveState: true, preserveScroll: true });
}

function todayWib(): string {
    return new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Jakarta' }).format(new Date());
}

function addDaysYmd(ymd: string, days: number): string {
    const d = new Date(`${ymd}T00:00:00Z`);
    d.setUTCDate(d.getUTCDate() + days);
    return d.toISOString().slice(0, 10);
}

function spanDays(from: string, to: string): number {
    return Math.round((Date.parse(`${to}T00:00:00Z`) - Date.parse(`${from}T00:00:00Z`)) / 86_400_000) + 1;
}

/** Which preset the applied range matches; anything else is a picked range. */
function presetOf(range: { from: string; to: string; hours?: number | null } | undefined): string {
    if (!range) return '7d';
    if (range.hours === 24) return '24h';
    const span = spanDays(range.from, range.to);
    if (span === 7) return '7d';
    if (span === 31) return '31d';
    return 'custom';
}

/** True from the md breakpoint up (the width where the table replaces the cards). */
function useIsWide(): boolean {
    const query = '(min-width: 768px)';
    const [wide, setWide] = useState(() => (typeof window === 'undefined' ? true : window.matchMedia(query).matches));
    useEffect(() => {
        const media = window.matchMedia(query);
        const onChange = () => setWide(media.matches);
        onChange();
        media.addEventListener('change', onChange);
        return () => media.removeEventListener('change', onChange);
    }, []);
    return wide;
}

/** Stops a click inside a row control from also opening the detail panel. */
const stop = (e: { stopPropagation: () => void }) => e.stopPropagation();

export default function OutageShadowPanel() {
    const [filters, setFilters] = useState<Filters>(readFilters);
    const [page, setPage] = useState(1);
    const [perPage, setPerPage] = useState(25);
    const [data, setData] = useState<Payload | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [nonce, setNonce] = useState(0);
    const wide = useIsWide();

    // Detail panel: the episode id lives in the URL (`open=`); the row comes from the list, or is fetched when the list does not hold it.
    const [openId, setOpenId] = useState<number | null>(readOpenId);
    const [openRow, setOpenRow] = useState<Episode | null>(null);
    const openIdRef = useRef(openId);
    openIdRef.current = openId;
    // Inertia loads the page's deferred props right after the first visit; that answer carries the URL the visit started
    // with. When it lands after a filter change, put our URL back (the same client-side replace, no request).
    useEffect(() => {
        const offNavigate = router.on('navigate', restoreUrl);
        const offFinish = router.on('finish', restoreUrl);
        // the Back / Forward buttons are the user's choice: never put the filters back over them
        const forget = () => {
            desiredUrl = null;
        };
        window.addEventListener('popstate', forget);
        return () => {
            offNavigate();
            offFinish();
            window.removeEventListener('popstate', forget);
            desiredUrl = null;
        };
    }, []);
    const autoOpened = useRef('');
    const probed = useRef<number | null>(null);

    useEffect(() => {
        const controller = new AbortController();
        const load = async () => {
            setLoading(true);
            setError(null);
            try {
                const res = await fetch(route('api.monitoring.outage-shadow', toQuery(filters, page, perPage)), {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                    signal: controller.signal,
                });
                if (!res.ok) throw new Error('unavailable');
                const payload = (await res.json()) as Payload;
                setData(payload);
                // The server clamps the range (max 31 days): reflect what was really applied in the inputs + URL.
                if (!filters.episode && ((filters.from && filters.from !== payload.filters.from) || (filters.to && filters.to !== payload.filters.to))) {
                    const applied = { ...filters, from: payload.filters.from, to: payload.filters.to };
                    setFilters((cur) => (cur === filters ? applied : cur));
                    writeUrl(applied, openIdRef.current);
                }
            } catch (e) {
                if ((e as Error).name !== 'AbortError') setError('Gagal memuat data deteksi gangguan.');
            } finally {
                setLoading(false);
            }
        };
        void load();
        return () => controller.abort();
    }, [filters, page, perPage, nonce]);

    /** One episode by id (the list endpoint with `episode=`), for the detail panel when the list does not hold it. */
    const fetchEpisode = useCallback(async (id: number): Promise<Episode | null> => {
        try {
            const res = await fetch(route('api.monitoring.outage-shadow', { episode: id }), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (!res.ok) return null;
            const json = (await res.json()) as Payload;
            const row = json.data[0] ?? null;
            setOpenRow(row);
            return row;
        } catch {
            return null;
        }
    }, []);

    function openEpisode(row: Episode) {
        setOpenId(row.id);
        setOpenRow(row);
        writeUrl(filters, row.id);
    }

    function closeDrawer() {
        setOpenId(null);
        writeUrl(filters, null);
    }

    // A deep link to one episode (`episode=`, the WA ticket link) shows that row and opens its detail panel once.
    useEffect(() => {
        if (!data || !filters.episode || autoOpened.current === filters.episode) return;
        const row = data.data.find((r) => String(r.id) === filters.episode);
        if (row) {
            autoOpened.current = filters.episode;
            setOpenId(row.id);
            setOpenRow(row);
            writeUrl(filters, row.id);
        }
    }, [data, filters]);

    // A shared `open=<id>` link whose row is not on this page of the list: fetch that one episode.
    useEffect(() => {
        if (openId === null || !data || loading || filters.episode) return;
        if (data.data.some((r) => r.id === openId) || openRow?.id === openId || probed.current === openId) return;
        probed.current = openId;
        void fetchEpisode(openId).then((row) => {
            if (row === null) {
                setOpenId(null);
                writeUrl(filters, null);
            }
        });
    }, [data, loading, filters, openId, openRow, fetchEpisode]);

    function apply(next: Partial<Filters>) {
        const merged = { ...filters, ...next };
        setOpenId(null);
        setFilters(merged);
        setPage(1);
        writeUrl(merged, null);
    }

    // Search box: 300 ms after the last key, at least 2 characters; Enter or the clear button apply at once.
    // Always the latest apply(), so the timer never uses a stale filter set.
    const applyRef = useRef(apply);
    applyRef.current = apply;
    const [qInput, setQInput] = useState(filters.q);
    useEffect(() => {
        const text = qInput.trim();
        if (text === filters.q || (text.length > 0 && text.length < SEARCH_MIN)) return;
        const timer = window.setTimeout(() => applyRef.current({ q: text, episode: '' }), SEARCH_DEBOUNCE_MS);
        return () => window.clearTimeout(timer);
    }, [qInput, filters.q]);

    function submitSearch() {
        const text = qInput.trim();
        if (text.length > 0 && text.length < SEARCH_MIN) return;
        if (text !== filters.q) apply({ q: text, episode: '' });
    }

    function clearSearch() {
        setQInput('');
        if (filters.q) apply({ q: '' });
    }

    const [datesOpen, setDatesOpen] = useState(false);
    const preset = datesOpen ? 'custom' : presetOf(data?.filters);

    function pickPreset(value: string) {
        if (value === 'custom') {
            setDatesOpen(true);
            return;
        }
        setDatesOpen(false);
        const today = todayWib();
        const days = value === '24h' ? 1 : value === '31d' ? 30 : 6;
        apply({ from: addDaysYmd(today, -days), to: today, hours: value === '24h', episode: '' });
    }

    function pickView(view: View) {
        if (view !== filters.view) apply({ view, type: 'all', episode: '' });
    }

    function pickInfo(info: boolean) {
        apply({ info, type: !info && INFO_TYPES.includes(filters.type) ? 'all' : filters.type });
    }

    const counts = data?.counts;
    const facets = data?.facets ?? [];
    // one episode: an `episode=` link, or a `#123` search (the server answers that as a single episode)
    const singleEpisode = filters.episode !== '' || data?.filters.episode != null;
    const drawerRow = openId === null ? null : (data?.data.find((r) => r.id === openId) ?? (openRow?.id === openId ? openRow : null));

    const ticketAction = (row: Episode): ReactNode => (
        <OutageTicketAction
            episode={row}
            manual={data?.manual_ticket}
            onChanged={() => {
                setNonce((n) => n + 1);
                // the row may leave this tab now (it has a ticket): keep the open panel up to date from its own fetch
                void fetchEpisode(row.id);
            }}
        />
    );

    // The open row shows its ticket in the detail panel, so the list does not repeat the same control.
    const rowAction = (row: Episode): ReactNode =>
        (row.would_ticket || row.ticket || row.can_ticket || row.customer_open_ticket) && openId !== row.id ? ticketAction(row) : null;

    const columns: Column<Episode>[] = [
        {
            header: 'Kejadian',
            wrap: true,
            className: 'min-w-[220px]',
            cell: (row) => {
                const cause = causeLine(row);
                return (
                    <div className={'space-y-0.5' + (row.pinned_age_days != null ? ' border-l-4 border-status-degraded pl-2' : '')}>
                        <div className="font-medium">{row.type_label}</div>
                        {row.detail && <div className="text-xs text-muted-foreground">{row.detail}</div>}
                        <div className="text-xs text-muted-foreground">{locationText(row)}</div>
                        {cause && (
                            <div className="text-xs" data-testid="outage-cause-text">
                                <span className="text-muted-foreground">{cause.prefix}:</span> <span className="font-medium">{cause.text}</span>
                            </div>
                        )}
                    </div>
                );
            },
        },
        {
            header: 'Pelanggan',
            wrap: true,
            className: 'min-w-[220px]',
            cell: (row) => <CustomerSummary row={row} />,
        },
        {
            header: 'Status',
            wrap: true,
            cell: (row) => {
                const age = ageText(row);
                return (
                    <div className="space-y-1" title={row.opened_at ? `Mulai ${formatDateTime(row.opened_at)}` : undefined}>
                        <StatusBadge label={row.state_label} tone={STATE_TONE[row.state] ?? 'neutral'} />
                        {row.pinned_age_days != null && (
                            <div className="text-xs font-medium text-foreground" data-testid="pinned-age">
                                Terbuka {row.pinned_age_days} hari
                            </div>
                        )}
                        {age && <div className="text-xs text-muted-foreground">{age}</div>}
                        {row.suppressed_label && <div className="text-xs text-muted-foreground">Ditahan: {row.suppressed_label}</div>}
                    </div>
                );
            },
        },
        {
            header: 'Aksi',
            wrap: true,
            cell: (row) => (
                <div className="flex flex-wrap items-center gap-2" onClick={stop}>
                    {rowAction(row)}
                    <Button type="button" variant="ghost" size="sm" className="h-7 px-2 text-xs" aria-label={`Buka detail kejadian ${row.id}`} onClick={() => openEpisode(row)}>
                        Detail <ChevronRight className="ml-0.5 h-3.5 w-3.5" aria-hidden />
                    </Button>
                </div>
            ),
        },
    ];

    // Empty states (plain words). Only for a loaded, error-free, empty answer.
    let empty: { text: string; action?: { label: string; run: () => void } } | null = null;
    if (data && !loading && !error && data.total === 0) {
        const search = data.search;
        const range = `${data.filters.from} s/d ${data.filters.to}`;
        const hiddenInfo = !filters.info && (data.info_count ?? 0) > 0;
        if (data.filters.episode != null) {
            empty = { text: `Kejadian #${data.filters.episode} tidak ditemukan.` };
        } else if (search?.q && search.too_many) {
            empty = { text: 'Ada lebih dari 50 pelanggan cocok. Ketik lebih lengkap.' };
        } else if (search?.q && search.customers_found === 0) {
            empty = { text: `Pelanggan "${search.q}" tidak ditemukan. Coba nama lain, PPPoE atau no HP.` };
        } else if (hiddenInfo) {
            empty = {
                text: `Tidak ada gangguan. ${data.info_count} info disembunyikan.`,
                action: { label: 'Tampilkan info', run: () => pickInfo(true) },
            };
        } else if (search?.q) {
            const wider = data.filters.hours === 24 || spanDays(data.filters.from, data.filters.to) < 31;
            empty =
                (counts?.semua ?? 0) > 0
                    ? { text: 'Tidak ada kejadian di tab ini. Cek tab lain.' }
                    : {
                          text: `Pelanggan ditemukan (${search.customers_found}), tapi tidak ada kejadian di ${data.filters.hours === 24 ? '24 jam terakhir' : range}.`,
                          action: wider ? { label: 'Cari 31 hari', run: () => apply({ from: addDaysYmd(todayWib(), -30), to: todayWib(), hours: false, episode: '' }) } : undefined,
                      };
        } else if (filters.view === 'perlu') {
            empty = { text: 'Tidak ada gangguan yang perlu dicek.', action: { label: 'Lihat yang selesai', run: () => pickView('selesai') } };
        } else if (filters.view === 'sedang') {
            empty = { text: 'Tidak ada gangguan yang sedang dicek.' };
        } else if (filters.view === 'selesai') {
            empty = { text: `Belum ada gangguan selesai di ${data.filters.hours === 24 ? '24 jam terakhir' : range}.` };
        } else {
            empty = { text: `Tidak ada kejadian di ${data.filters.hours === 24 ? '24 jam terakhir' : range}.` };
        }
    }

    const tableData: PaginatedData<Episode> = data ?? {
        data: [],
        path: '',
        links: [],
        current_page: 1,
        last_page: 1,
        per_page: perPage,
        total: 0,
        from: 0,
        to: 0,
    };

    /** Band above the first pinned row ("Belum selesai > 24 jam") and a divider where the pinned rows end ("Terbaru"). */
    const rowBand = (row: Episode, index: number, items: Episode[]): ReactNode => {
        const pinned = row.pinned_age_days != null;
        if (pinned && index === 0) {
            return (
                <div className="border-l-4 border-status-degraded bg-muted/40 px-3 py-1.5 text-xs font-semibold uppercase tracking-wide text-foreground" data-testid="deteksi-pinned-band">
                    {'Belum selesai > 24 jam'} ({data?.pinned_total ?? items.length})
                </div>
            );
        }
        if (!pinned && index > 0 && items[index - 1].pinned_age_days != null) {
            return (
                <div className="px-3 py-1.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground" data-testid="deteksi-pinned-divider">
                    Terbaru
                </div>
            );
        }
        return null;
    };

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center gap-2">
                <Badge variant="outline">Mode uji — tiket hanya dibuat manual</Badge>
                <span className="text-xs text-muted-foreground">
                    Deteksi hanya dicatat. Tiket dibuat dengan tombol "Buat tiket". Tidak ada WA ke pelanggan.
                </span>
            </div>

            {data?.data_blind && data.data_blind.length > 0 && (
                <div className="rounded-md border border-status-degraded/30 bg-status-degraded-bg px-3 py-2 text-sm" role="status" data-testid="outage-data-blind">
                    <span className="font-medium">Data tidak terbaca:</span>{' '}
                    {data.data_blind.map((b) => `${b.label}${b.reason ? ` — ${b.reason}` : ''}`).join('; ')}
                </div>
            )}
            {data?.olt_clock_warnings && data.olt_clock_warnings.length > 0 && (
                <div className="rounded-md border border-status-degraded/30 bg-status-degraded-bg px-3 py-2 text-sm" role="status" data-testid="outage-olt-clock">
                    <span className="font-medium">Jam OLT tidak sinkron:</span>{' '}
                    {data.olt_clock_warnings.map((w) => `${w.olt_name} (${w.drift_minutes > 0 ? '+' : ''}${w.drift_minutes} menit)`).join('; ')}
                </div>
            )}

            <div className="space-y-4" data-testid="deteksi-main">
                <div className="space-y-3" data-testid="deteksi-controls">
                    <div className="flex flex-col gap-3 lg:flex-row lg:items-center">
                        <div className="relative min-w-0 flex-1">
                            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" aria-hidden />
                            <Input
                                type="text"
                                inputMode="search"
                                autoComplete="off"
                                maxLength={64}
                                aria-label="Cari pelanggan"
                                data-testid="deteksi-search"
                                placeholder="Cari pelanggan: nama, PPPoE, ID, no HP, #kejadian"
                                value={qInput}
                                onChange={(e) => setQInput(e.target.value)}
                                onKeyDown={(e) => {
                                    if (e.key === 'Enter') submitSearch();
                                }}
                                className="h-9 bg-card pl-9 pr-9"
                            />
                            {qInput !== '' && (
                                <button
                                    type="button"
                                    aria-label="Hapus pencarian"
                                    className="absolute right-2 top-1/2 -translate-y-1/2 rounded-sm p-1 text-muted-foreground hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                    onClick={clearSearch}
                                >
                                    <X className="h-4 w-4" aria-hidden />
                                </button>
                            )}
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            <Select value={preset} onValueChange={pickPreset}>
                                <SelectTrigger aria-label="Rentang waktu" className="h-9 w-[150px]">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {PRESETS.map((p) => (
                                        <SelectItem key={p.value} value={p.value}>
                                            {p.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <Select value={filters.olt} onValueChange={(v) => apply({ olt: v })}>
                                <SelectTrigger aria-label="Filter OLT" className="h-9 w-[160px]">
                                    <SelectValue placeholder="Semua OLT" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">Semua OLT</SelectItem>
                                    {(data?.olts ?? []).map((o) => (
                                        <SelectItem key={o.id} value={String(o.id)}>
                                            {o.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <Button type="button" variant="outline" size="sm" onClick={() => setNonce((n) => n + 1)}>
                                <RefreshCw className="mr-2 h-3.5 w-3.5" aria-hidden /> Muat ulang
                            </Button>
                        </div>
                    </div>
                    {qInput.trim().length === 1 && (
                        <p className="text-xs text-muted-foreground" data-testid="deteksi-search-hint">
                            Ketik minimal 2 huruf
                        </p>
                    )}
                    {preset === 'custom' && (
                        <div className="flex flex-wrap items-center gap-2">
                            <Input
                                type="date"
                                aria-label="Dari tanggal"
                                value={filters.from || data?.filters.from || ''}
                                max={filters.to || data?.filters.to || undefined}
                                onChange={(e) => apply({ from: e.target.value, hours: false, episode: '' })}
                                className="h-9 w-[160px] bg-card"
                            />
                            <Input
                                type="date"
                                aria-label="Sampai tanggal"
                                value={filters.to || data?.filters.to || ''}
                                min={filters.from || data?.filters.from || undefined}
                                onChange={(e) => apply({ to: e.target.value, hours: false, episode: '' })}
                                className="h-9 w-[160px] bg-card"
                            />
                        </div>
                    )}
                    {singleEpisode && (
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => {
                                setQInput('');
                                apply({ episode: '', q: '' });
                            }}
                        >
                            Kejadian #{filters.episode || data?.filters.episode} — tampilkan semua
                        </Button>
                    )}
                    {filters.state !== 'all' && (
                        <Button type="button" variant="outline" size="sm" data-testid="deteksi-state-chip" onClick={() => apply({ state: 'all' })}>
                            Status: {STATE_WORDS[filters.state] ?? filters.state} <X className="ml-1.5 h-3.5 w-3.5" aria-hidden />
                        </Button>
                    )}

                    {!singleEpisode && (
                        <>
                            <div className="overflow-x-auto pb-1">
                                <Tabs value={filters.view} onValueChange={(v) => pickView(v as View)}>
                                    <TabsList className="w-max" aria-label="Status kejadian">
                                        {VIEW_TABS.map((t) => (
                                            <TabsTrigger key={t.value} value={t.value} data-testid={`deteksi-tab-${t.value}`}>
                                                {t.label}{' '}
                                                <span className="ml-1.5 tabular-nums text-xs text-muted-foreground" data-testid={`deteksi-tab-count-${t.value}`}>
                                                    {counts ? counts[t.value] : ''}
                                                </span>
                                            </TabsTrigger>
                                        ))}
                                    </TabsList>
                                </Tabs>
                            </div>

                            {facets.length > 0 && (
                                <div className="flex gap-2 overflow-x-auto pb-1" data-testid="deteksi-chips">
                                    <Button
                                        type="button"
                                        variant={filters.type === 'all' ? 'default' : 'outline'}
                                        size="sm"
                                        className="h-7 shrink-0 rounded-full px-3 text-xs"
                                        aria-pressed={filters.type === 'all'}
                                        onClick={() => apply({ type: 'all' })}
                                    >
                                        Semua jenis <span className="ml-1 tabular-nums">{counts ? counts[filters.view] : ''}</span>
                                    </Button>
                                    {facets.map((f) => (
                                        <Button
                                            key={f.value}
                                            type="button"
                                            variant={filters.type === f.value ? 'default' : 'outline'}
                                            size="sm"
                                            className="h-7 shrink-0 rounded-full px-3 text-xs"
                                            aria-pressed={filters.type === f.value}
                                            data-testid={`deteksi-chip-${f.value}`}
                                            onClick={() => apply({ type: filters.type === f.value ? 'all' : f.value })}
                                        >
                                            {f.label} <span className="ml-1 tabular-nums">{f.count}</span>
                                        </Button>
                                    ))}
                                </div>
                            )}
                        </>
                    )}

                    <div className="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
                        {!singleEpisode ? (
                            <label className="flex items-start gap-2 text-sm">
                                <Switch checked={filters.info} onCheckedChange={pickInfo} aria-label="Tampilkan info" data-testid="deteksi-info-toggle" className="mt-0.5" />
                                <span>
                                    Tampilkan info{data ? ` (${data.info_count ?? 0})` : ''}
                                    <span className="block text-xs text-muted-foreground">sinyal redup, data tidak terbaca, diisolir, ditahan</span>
                                </span>
                            </label>
                        ) : (
                            <span />
                        )}
                        <span className="text-sm text-muted-foreground" data-testid="deteksi-total">
                            {data ? `${data.total} kejadian` : 'memuat…'}
                        </span>
                    </div>
                </div>

                {empty ? (
                    <Card className="border-border/80 bg-card shadow-sm dark:border-border/60" data-testid="deteksi-empty">
                        <CardContent className="space-y-3 pt-6">
                            <p className="text-sm font-medium">{empty.text}</p>
                            {empty.action && (
                                <Button type="button" variant="outline" size="sm" onClick={empty.action.run}>
                                    {empty.action.label}
                                </Button>
                            )}
                        </CardContent>
                    </Card>
                ) : (
                    <DataTable<Episode>
                        title="Deteksi Gangguan"
                        compact
                        data={tableData}
                        // one copy of each row in the page: the table on a wide screen, the cards on a small screen
                        columns={wide ? columns : []}
                        mobileCard={
                            wide
                                ? undefined
                                : (row) => <EpisodeCard row={row} action={rowAction(row)} onOpen={() => openEpisode(row)} />
                        }
                        onRowClick={openEpisode}
                        rowBand={rowBand}
                        routeName="monitoring.index"
                        syncUrl={false}
                        hideToolbar
                        hidePagination
                        density="compact"
                        loading={loading && !data}
                        error={error}
                        onRetry={() => setNonce((n) => n + 1)}
                    />
                )}

                {data && data.total > 0 && (
                    <DataTablePagination
                        data={tableData}
                        onPageChange={(p) => setPage(p)}
                        onPageSizeChange={(n) => {
                            setPerPage(n);
                            setPage(1);
                        }}
                        pageSizeOptions={[25, 50, 100]}
                    />
                )}

                <details className="rounded-md border bg-card px-3 py-2" data-testid="deteksi-precision-details">
                    <summary className="cursor-pointer text-sm font-medium">Ketepatan diagnosa</summary>
                    <div className="mt-3">
                        <DiagnosisPrecisionCard />
                    </div>
                </details>
                {data?.suppression && (
                    <details className="rounded-md border bg-card px-3 py-2" data-testid="deteksi-held-details">
                        <summary className="cursor-pointer text-sm font-medium">
                            Yang ditahan: PSB {data.suppression.psb_customers_suppressing} · migrasi {data.suppression.migration_customers_suppressing}
                        </summary>
                        <p className="mt-2 text-xs text-muted-foreground" data-testid="outage-suppression">
                            Ditahan: PSB {data.suppression.psb_customers_suppressing} pelanggan menahan (diubah ≤{data.suppression.psb_days} hari; {data.suppression.psb_customers_aged_out} diabaikan) · migrasi{' '}
                            {data.suppression.migration_customers_suppressing} menahan (≤{data.suppression.migration_days} hari; {data.suppression.migration_customers_aged_out} diabaikan)
                        </p>
                    </details>
                )}
            </div>

            <EpisodeDrawer episode={drawerRow} onClose={closeDrawer} ticketAction={drawerRow ? ticketAction(drawerRow) : null} />
        </div>
    );
}
