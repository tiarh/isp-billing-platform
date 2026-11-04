import { useEffect, useState } from 'react';
import { ArrowDown, ArrowUp, ChevronLeft, ChevronRight, ChevronsUpDown, Clock3, Gauge, Info, Search, Users } from 'lucide-react';
import { EmptyState } from '@/Components/EmptyState';
import { MobileCard } from '@/Components/mobile/MobileCard';
import { StatusBadge, type StatusTone } from '@/Components/StatusBadge';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { formatCount, formatMbps } from '@/Components/monitoring/network/format';
import { Sparkline } from '@/Components/monitoring/network/Sparkline';
import { useDebouncedValue } from '@/Components/monitoring/network/useMonitoringWidget';
import { WidgetFrame } from '@/Components/monitoring/network/WidgetFrame';
import { cn } from '@/lib/utils';
import { formatGB, sparkPoints } from './format';
import type { PppoeFilter, PppoeListData, PppoeRow, PppoeSort, PppoeStatus } from './types';
import { useRouterApi } from './useRouterApi';

const PER_PAGE = 50;

const STATUS_TONE: Record<PppoeStatus, StatusTone> = { online: 'up', offline: 'down', unlinked: 'info', unknown: 'neutral' };
const STATUS_LABEL: Record<PppoeStatus, string> = {
    online: 'Online',
    offline: 'Offline',
    unlinked: 'Tidak tertaut',
    unknown: 'Tidak diketahui',
};

const FILTERS: Array<{ value: PppoeFilter; label: string }> = [
    { value: 'all', label: 'Semua' },
    { value: 'online', label: 'Online' },
    { value: 'offline', label: 'Offline' },
    { value: 'unlinked', label: 'Tidak tertaut' },
];

type SortDir = 'asc' | 'desc';

function RowStatus({ row }: { row: PppoeRow }) {
    return (
        <span className="inline-flex flex-wrap items-center gap-1.5">
            <StatusBadge tone={STATUS_TONE[row.status]} label={STATUS_LABEL[row.status]} />
            {row.isolated && <StatusBadge tone="degraded" label="Isolir" showIcon={false} />}
        </span>
    );
}

interface PppoeTableProps {
    routerId: number;
    tick: number;
    onSelect: (row: PppoeRow) => void;
}

export function PppoeTable({ routerId, tick, onSelect }: PppoeTableProps) {
    const [search, setSearch] = useState('');
    const q = useDebouncedValue(search.trim(), 300);
    const [status, setStatus] = useState<PppoeFilter>('all');
    const [sort, setSort] = useState<{ key: PppoeSort; dir: SortDir }>({ key: 'name', dir: 'asc' });
    // A new search always starts on page 1: the page is tied to the query it was chosen for,
    // so a changed `q` yields page 1 in the same render (one request, not two).
    const [pageState, setPageState] = useState({ q, page: 1 });
    const page = pageState.q === q ? pageState.page : 1;
    const setPage = (next: number) => setPageState({ q, page: next });

    const state = useRouterApi<PppoeListData>(
        route('api.monitoring.routers.pppoe', routerId),
        { q, status, sort: sort.key, dir: sort.dir, page, per_page: PER_PAGE },
        tick,
        { keepOnChange: true },
    );

    // The "Tidak diketahui" filter disappears once Grafana is back; do not stay on it.
    const unknownCount = state.envelope?.data.meta.counts.unknown ?? null;
    useEffect(() => {
        if (status === 'unknown' && unknownCount === 0) {
            setStatus('all');
        }
    }, [status, unknownCount]);

    const toggleSort = (key: PppoeSort) => {
        setPage(1);
        setSort((current) => (current.key === key ? { key, dir: current.dir === 'asc' ? 'desc' : 'asc' } : { key, dir: key === 'name' ? 'asc' : 'desc' }));
    };

    const header = (key: PppoeSort, label: string, align: 'left' | 'right' = 'left') => {
        const active = sort.key === key;
        const Icon = !active ? ChevronsUpDown : sort.dir === 'asc' ? ArrowUp : ArrowDown;

        return (
            <th
                scope="col"
                aria-sort={active ? (sort.dir === 'asc' ? 'ascending' : 'descending') : 'none'}
                className={cn('px-3 pb-2 font-medium', align === 'right' ? 'text-right' : 'text-left')}
            >
                <button
                    type="button"
                    onClick={() => toggleSort(key)}
                    className={cn(
                        'inline-flex items-center gap-1 rounded-sm hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                        active && 'text-foreground',
                    )}
                >
                    {label}
                    <Icon className="size-3" aria-hidden />
                </button>
            </th>
        );
    };

    return (
        <WidgetFrame
            title="Pelanggan PPPoE"
            description="Status, laju saat ini, dan pemakaian harian per akun PPPoE di router ini."
            state={state}
            renderOnDegraded
            suppressSetup
            skeletonClassName="h-96"
        >
            {(data) => {
                const { rows, meta } = data;
                const counts = meta.counts;
                const filters = counts.unknown > 0 ? [...FILTERS, { value: 'unknown' as const, label: 'Tidak diketahui' }] : FILTERS;
                const hasInvalid = rows.some((row) => row.has_invalid_days);

                return (
                    <div className="space-y-4">
                        <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                            <div className="relative w-full lg:max-w-xs">
                                <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden />
                                <Input
                                    type="search"
                                    value={search}
                                    onChange={(event) => setSearch(event.target.value)}
                                    maxLength={64}
                                    placeholder="Cari nama, kode, atau akun PPPoE"
                                    aria-label="Cari pelanggan PPPoE"
                                    className="pl-9"
                                />
                            </div>
                            <div role="radiogroup" aria-label="Filter status" className="flex max-w-full gap-0.5 overflow-x-auto rounded-[var(--radius-control)] border border-border bg-card p-0.5">
                                {filters.map((option) => {
                                    const active = option.value === status;
                                    const count = option.value === 'all' ? counts.all : counts[option.value];

                                    return (
                                        <button
                                            key={option.value}
                                            type="button"
                                            role="radio"
                                            aria-checked={active}
                                            onClick={() => {
                                                setPage(1);
                                                setStatus(option.value);
                                            }}
                                            className={cn(
                                                'inline-flex h-8 shrink-0 items-center gap-1.5 rounded-[calc(var(--radius-control)-2px)] px-2.5 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                                                active ? 'bg-primary-soft text-primary-soft-foreground' : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                                            )}
                                        >
                                            {option.label}
                                            <span className="font-data text-xs">{formatCount(count)}</span>
                                        </button>
                                    );
                                })}
                            </div>
                        </div>

                        {!meta.live && (
                            <p role="status" className="flex gap-2 rounded-lg border border-dashed border-border px-4 py-3 text-sm text-muted-foreground">
                                <Info className="mt-0.5 size-4 shrink-0" aria-hidden />
                                Status online dan laju saat ini tidak tersedia karena Grafana tidak dapat dijangkau. Daftar pelanggan dan pemakaian harian tetap dari database.
                            </p>
                        )}

                        {rows.length === 0 ? (
                            <EmptyState
                                icon={Users}
                                title={q !== '' || status !== 'all' ? 'Tidak ada akun yang cocok' : 'Belum ada akun PPPoE'}
                                description={q !== '' || status !== 'all' ? 'Ubah kata kunci atau filter status.' : 'Router ini belum punya pelanggan terdaftar atau riwayat pemakaian.'}
                            />
                        ) : (
                            <>
                                <div className="hidden md:block">
                                    <table className="w-full text-sm">
                                        <thead>
                                            <tr className="text-xs text-muted-foreground">
                                                {header('name', 'Pelanggan')}
                                                {header('status', 'Status')}
                                                {header('download_now', 'Download', 'right')}
                                                {header('upload_now', 'Upload', 'right')}
                                                {header('today', 'Hari ini', 'right')}
                                                {header('month', 'Bulan ini', 'right')}
                                                <th scope="col" className="px-3 pb-2 text-left font-medium">7 hari</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {rows.map((row) => (
                                                <tr
                                                    key={row.pppoe_user.toLowerCase()}
                                                    onClick={() => onSelect(row)}
                                                    className="cursor-pointer border-t border-border hover:bg-muted/40"
                                                >
                                                    <td className="max-w-[16rem] px-3 py-3">
                                                        <button
                                                            type="button"
                                                            onClick={(event) => {
                                                                event.stopPropagation();
                                                                onSelect(row);
                                                            }}
                                                            aria-label={`Lihat pemakaian harian ${row.pppoe_user}`}
                                                            className="block w-full rounded-sm text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                                        >
                                                            <span className="block truncate font-medium">{row.name ?? row.pppoe_user}</span>
                                                            <span className="block truncate font-data text-xs text-muted-foreground">
                                                                {row.name !== null ? row.pppoe_user : 'Belum tertaut ke pelanggan'}
                                                                {row.customer_code ? ` · ${row.customer_code}` : ''}
                                                            </span>
                                                        </button>
                                                    </td>
                                                    <td className="px-3 py-3"><RowStatus row={row} /></td>
                                                    <td className="px-3 py-3 text-right font-data">{formatMbps(row.download_mbps)}</td>
                                                    <td className="px-3 py-3 text-right font-data">{formatMbps(row.upload_mbps)}</td>
                                                    <td className="px-3 py-3 text-right font-data">{formatGB(row.today_gb)}</td>
                                                    <td className="px-3 py-3 text-right font-data">
                                                        {formatGB(row.month_gb)}
                                                        {row.has_invalid_days && <span title="Ada hari dengan data tidak valid yang tidak dihitung"> *</span>}
                                                    </td>
                                                    <td className="px-3 py-3">
                                                        <Sparkline points={sparkPoints(row.spark7)} label={`Pemakaian 7 hari ${row.pppoe_user}`} width={72} />
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>

                                <ul className="space-y-3 md:hidden">
                                    {rows.map((row) => (
                                        <li key={row.pppoe_user.toLowerCase()}>
                                            <MobileCard
                                                onSelect={() => onSelect(row)}
                                                title={row.name ?? row.pppoe_user}
                                                code={row.name !== null ? row.pppoe_user : 'Belum tertaut ke pelanggan'}
                                                badge={<RowStatus row={row} />}
                                                meta={[
                                                    { icon: Gauge, text: `${formatMbps(row.download_mbps)} ↓ · ${formatMbps(row.upload_mbps)} ↑` },
                                                    { icon: Clock3, text: `Hari ini ${formatGB(row.today_gb)} · Bulan ini ${formatGB(row.month_gb)}${row.has_invalid_days ? ' *' : ''}` },
                                                ]}
                                            >
                                                <span className="mt-2 block">
                                                    <Sparkline points={sparkPoints(row.spark7)} label={`Pemakaian 7 hari ${row.pppoe_user}`} width={120} />
                                                </span>
                                            </MobileCard>
                                        </li>
                                    ))}
                                </ul>

                                {hasInvalid && (
                                    <p className="text-xs text-muted-foreground">* Pemakaian bulan ini tidak termasuk hari dengan data tidak valid.</p>
                                )}

                                <div className="flex flex-wrap items-center justify-between gap-3 border-t border-border pt-3 text-sm">
                                    <p className="text-muted-foreground">
                                        {formatCount(meta.total)} akun · halaman {formatCount(meta.page)} dari {formatCount(meta.last_page)}
                                    </p>
                                    <div className="flex gap-2">
                                        <Button type="button" variant="outline" size="sm" disabled={meta.page <= 1} onClick={() => setPage(meta.page - 1)}>
                                            <ChevronLeft className="size-4" aria-hidden />
                                            Sebelumnya
                                        </Button>
                                        <Button type="button" variant="outline" size="sm" disabled={meta.page >= meta.last_page} onClick={() => setPage(meta.page + 1)}>
                                            Berikutnya
                                            <ChevronRight className="size-4" aria-hidden />
                                        </Button>
                                    </div>
                                </div>
                            </>
                        )}
                    </div>
                );
            }}
        </WidgetFrame>
    );
}
