import { useMemo, useState, type ReactNode } from 'react';
import { ArrowDown, ArrowUp, ChevronsUpDown } from 'lucide-react';
import { EmptyState } from '@/Components/EmptyState';
import { StatusBadge, type StatusTone } from '@/Components/StatusBadge';
import { cn } from '@/lib/utils';
import {
    DASH,
    LEVEL_LABEL,
    LEVEL_TEXT,
    THRESHOLDS,
    formatCount,
    formatMbps,
    formatMs,
    formatPct,
    formatUptime,
    levelFor,
} from './format';
import { Sparkline } from './Sparkline';
import type { PingData, PingState, PingTarget, RouterRow, RouterTrafficData, RouterTrafficSeries, RoutersData, WidgetState } from './types';
import { WidgetFrame } from './WidgetFrame';

type SortKey = 'name' | 'status' | 'pppoe' | 'rx' | 'tx' | 'cpu' | 'memory' | 'uptime';
type SortDir = 'asc' | 'desc';

const PING_TONE: Record<PingState, StatusTone> = { up: 'up', degraded: 'degraded', down: 'down', icmp_blocked: 'info' };

function pingLabel(target: PingTarget): string {
    switch (target.state) {
        case 'up':
            return `Ping ${formatMs(target.rtt_ms)}`;
        case 'degraded':
            return `Ping lambat ${formatMs(target.rtt_ms)}`;
        case 'icmp_blocked':
            return 'ICMP diblokir';
        default:
            return 'Ping mati';
    }
}

function statusRank(row: RouterRow): number {
    return row.scraped === false ? 0 : row.scraped === null ? 1 : 2;
}

function sortValue(row: RouterRow, key: SortKey): string | number | null {
    switch (key) {
        case 'name':
            return row.name.toLowerCase();
        case 'status':
            return statusRank(row);
        case 'pppoe':
            return row.pppoe_online;
        case 'rx':
            return row.rx_mbps;
        case 'tx':
            return row.tx_mbps;
        case 'cpu':
            return row.cpu_pct;
        case 'memory':
            return row.memory_pct;
        case 'uptime':
            return row.uptime_seconds;
    }
}

function compareRows(a: RouterRow, b: RouterRow, key: SortKey, dir: SortDir): number {
    const left = sortValue(a, key);
    const right = sortValue(b, key);
    if (left === null && right === null) {
        return 0;
    }
    if (left === null) {
        return 1;
    }
    if (right === null) {
        return -1;
    }
    const result = typeof left === 'string' && typeof right === 'string' ? left.localeCompare(right, 'id') : Number(left) - Number(right);

    return dir === 'asc' ? result : -result;
}

function Metric({ value, thresholds }: { value: number | null; thresholds: readonly [number, number] }) {
    const level = levelFor(value, thresholds);
    if (level === null) {
        return <span className="text-muted-foreground">{DASH}</span>;
    }

    return (
        <span className={cn('font-data', LEVEL_TEXT[level])}>
            {formatPct(value, 0)}
            {level !== 'ok' && <span className="sr-only"> ({LEVEL_LABEL[level]})</span>}
        </span>
    );
}

function StatusCell({ row, ping }: { row: RouterRow; ping: PingTarget | undefined }) {
    return (
        <div className="flex flex-wrap items-center gap-1.5">
            {row.scraped === true && <StatusBadge tone="up" label="Online" />}
            {row.scraped === false && <StatusBadge tone="down" label="Tidak terbaca" />}
            {row.scraped === null && <StatusBadge tone="neutral" label="Belum diketahui" />}
            {ping && <StatusBadge tone={PING_TONE[ping.state]} label={pingLabel(ping)} showIcon={false} />}
        </div>
    );
}

interface RouterTableProps {
    routers: WidgetState<RoutersData>;
    traffic: WidgetState<RouterTrafficData>;
    ping: WidgetState<PingData>;
    /**
     * Extension slot: a later PR can turn the name into a link to a router
     * detail page without touching the table. Defaults to plain text.
     */
    renderRouterName?: (row: RouterRow) => ReactNode;
}

export function RouterTable({ routers, traffic, ping, renderRouterName }: RouterTableProps) {
    const [sort, setSort] = useState<{ key: SortKey; dir: SortDir }>({ key: 'name', dir: 'asc' });

    const trafficByRouter = useMemo(() => {
        const map = new Map<number, RouterTrafficSeries>();
        for (const series of traffic.envelope?.data.series ?? []) {
            map.set(series.router_id, series);
        }

        return map;
    }, [traffic.envelope]);

    const pingByRouter = useMemo(() => {
        const map = new Map<number, PingTarget>();
        for (const target of ping.envelope?.data.targets ?? []) {
            if (target.kind === 'router' && target.router_id !== null && !map.has(target.router_id)) {
                map.set(target.router_id, target);
            }
        }

        return map;
    }, [ping.envelope]);

    const name = (row: RouterRow): ReactNode => (renderRouterName ? renderRouterName(row) : row.name);

    const toggleSort = (key: SortKey) =>
        setSort((current) => (current.key === key ? { key, dir: current.dir === 'asc' ? 'desc' : 'asc' } : { key, dir: key === 'name' ? 'asc' : 'desc' }));

    const header = (key: SortKey, label: string, align: 'left' | 'right' = 'left') => {
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
        <WidgetFrame title="Router" description="Status, PPPoE, trafik uplink, dan sumber daya per router MikroTik." state={routers} renderOnDegraded skeletonClassName="h-64">
            {(data) => {
                const rows = [...data.rows].sort((a, b) => compareRows(a, b, sort.key, sort.dir));
                if (rows.length === 0) {
                    return <EmptyState title="Belum ada router aktif" description="Tambahkan router di menu Router agar muncul di sini." />;
                }

                return (
                    <>
                        <div className="hidden md:block">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="text-xs text-muted-foreground">
                                        {header('name', 'Router')}
                                        {header('status', 'Status')}
                                        {header('pppoe', 'PPPoE', 'right')}
                                        {header('rx', 'RX', 'right')}
                                        {header('tx', 'TX', 'right')}
                                        <th scope="col" className="px-3 pb-2 text-left font-medium">Tren</th>
                                        {header('cpu', 'CPU', 'right')}
                                        {header('memory', 'Memori', 'right')}
                                        {header('uptime', 'Uptime', 'right')}
                                    </tr>
                                </thead>
                                <tbody>
                                    {rows.map((row) => {
                                        const series = trafficByRouter.get(row.router_id);

                                        return (
                                            <tr key={row.router_id} className="border-t border-border">
                                                <td className="px-3 py-3 font-medium">{name(row)}</td>
                                                <td className="px-3 py-3"><StatusCell row={row} ping={pingByRouter.get(row.router_id)} /></td>
                                                <td className="px-3 py-3 text-right font-data">
                                                    {formatCount(row.pppoe_online)}
                                                    <span className="text-xs text-muted-foreground"> / {formatCount(row.pppoe_secrets)}</span>
                                                </td>
                                                <td className="px-3 py-3 text-right font-data">{formatMbps(row.rx_mbps)}</td>
                                                <td className="px-3 py-3 text-right font-data">{formatMbps(row.tx_mbps)}</td>
                                                <td className="px-3 py-3">
                                                    {series ? <Sparkline points={series.rx} label={`Tren RX ${row.name}`} /> : <span className="text-muted-foreground">{DASH}</span>}
                                                </td>
                                                <td className="px-3 py-3 text-right"><Metric value={row.cpu_pct} thresholds={THRESHOLDS.cpu} /></td>
                                                <td className="px-3 py-3 text-right"><Metric value={row.memory_pct} thresholds={THRESHOLDS.memory} /></td>
                                                <td className="px-3 py-3 text-right font-data">{formatUptime(row.uptime_seconds)}</td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>

                        <ul className="space-y-3 md:hidden">
                            {rows.map((row) => {
                                const series = trafficByRouter.get(row.router_id);

                                return (
                                    <li key={row.router_id} className="rounded-lg border border-border p-4">
                                        <div className="flex items-start justify-between gap-3">
                                            <span className="font-medium">{name(row)}</span>
                                            <span className="font-data text-sm">
                                                {formatCount(row.pppoe_online)}
                                                <span className="text-xs text-muted-foreground"> / {formatCount(row.pppoe_secrets)} PPPoE</span>
                                            </span>
                                        </div>
                                        <div className="mt-2"><StatusCell row={row} ping={pingByRouter.get(row.router_id)} /></div>
                                        <dl className="mt-3 grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                                            <div><dt className="text-xs text-muted-foreground">RX</dt><dd className="font-data">{formatMbps(row.rx_mbps)}</dd></div>
                                            <div><dt className="text-xs text-muted-foreground">TX</dt><dd className="font-data">{formatMbps(row.tx_mbps)}</dd></div>
                                            <div><dt className="text-xs text-muted-foreground">CPU</dt><dd><Metric value={row.cpu_pct} thresholds={THRESHOLDS.cpu} /></dd></div>
                                            <div><dt className="text-xs text-muted-foreground">Memori</dt><dd><Metric value={row.memory_pct} thresholds={THRESHOLDS.memory} /></dd></div>
                                            <div><dt className="text-xs text-muted-foreground">Uptime</dt><dd className="font-data">{formatUptime(row.uptime_seconds)}</dd></div>
                                            <div>
                                                <dt className="text-xs text-muted-foreground">Tren RX</dt>
                                                <dd>{series ? <Sparkline points={series.rx} label={`Tren RX ${row.name}`} /> : DASH}</dd>
                                            </div>
                                        </dl>
                                    </li>
                                );
                            })}
                        </ul>
                    </>
                );
            }}
        </WidgetFrame>
    );
}
