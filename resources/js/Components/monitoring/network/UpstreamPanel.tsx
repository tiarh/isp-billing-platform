import { useMemo, useState } from 'react';
import { CartesianGrid, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { StatusBadge } from '@/Components/StatusBadge';
import { chartTooltipProps } from '@/lib/chartTheme';
import { cn } from '@/lib/utils';
import { DASH, formatMbps, formatPct, formatPointTime, mergeSeries, seriesColor, seriesDash } from './format';
import type { MonitoringRange, UpstreamData, UpstreamLink, WidgetState } from './types';
import { WidgetFrame } from './WidgetFrame';

type Direction = 'rx' | 'tx';

interface UpstreamPanelProps {
    state: WidgetState<UpstreamData>;
    range: MonitoringRange;
    settingsHref?: string | null;
    suppressSetup?: boolean;
    compactUnavailable?: boolean;
}

function DirectionToggle({ value, onChange }: { value: Direction; onChange: (next: Direction) => void }) {
    return (
        <div role="radiogroup" aria-label="Arah trafik" className="inline-flex gap-0.5 rounded-[var(--radius-control)] border border-border p-0.5">
            {(['rx', 'tx'] as const).map((direction) => (
                <button
                    key={direction}
                    type="button"
                    role="radio"
                    aria-checked={value === direction}
                    onClick={() => onChange(direction)}
                    className={cn(
                        'h-7 min-w-10 rounded-[calc(var(--radius-control)-2px)] px-2 text-xs font-medium focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                        value === direction ? 'bg-primary-soft text-primary-soft-foreground' : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                    )}
                >
                    {direction.toUpperCase()}
                </button>
            ))}
        </div>
    );
}

function LinkStatus({ link }: { link: UpstreamLink }) {
    if (link.missing) {
        return <StatusBadge tone="neutral" label="Tanpa data" />;
    }
    if (link.degraded) {
        return <StatusBadge tone="degraded" label="Padat" />;
    }

    return <StatusBadge tone="up" label="Normal" />;
}

function UpstreamBody({ data, range }: { data: UpstreamData; range: MonitoringRange }) {
    const [direction, setDirection] = useState<Direction>('rx');
    const [hidden, setHidden] = useState<Set<string>>(() => new Set());

    const plain = useMemo(() => data.links.filter((link) => link.kind !== 'aggregate'), [data.links]);
    const aggregate = useMemo(() => data.links.filter((link) => link.kind === 'aggregate'), [data.links]);
    const chartRows = useMemo(
        () => mergeSeries(plain.filter((link) => !hidden.has(link.key)).map((link) => ({ key: link.key, points: link.series[direction] }))),
        [plain, hidden, direction],
    );

    const toggle = (key: string) =>
        setHidden((current) => {
            const next = new Set(current);
            if (next.has(key)) {
                next.delete(key);
            } else {
                next.add(key);
            }

            return next;
        });

    const row = (link: UpstreamLink, index: number | null) => (
        <tr key={link.key} className={cn('border-t border-border', index === null && 'bg-muted/50 font-semibold')}>
            <td className="py-2.5 pr-3">
                {index === null ? (
                    <span>{link.display_name} (total)</span>
                ) : (
                    <button
                        type="button"
                        aria-pressed={!hidden.has(link.key)}
                        onClick={() => toggle(link.key)}
                        className="inline-flex items-center gap-2 text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        title="Tampilkan / sembunyikan di grafik"
                    >
                        <span
                            aria-hidden
                            className={cn('inline-block size-2.5 rounded-full', hidden.has(link.key) && 'opacity-30')}
                            style={{ backgroundColor: seriesColor(index) }}
                        />
                        <span className={cn(hidden.has(link.key) && 'text-muted-foreground line-through')}>{link.display_name}</span>
                    </button>
                )}
            </td>
            <td className="px-3 py-2.5 text-right font-data">{formatMbps(link.now[direction])}</td>
            <td className="px-3 py-2.5 text-right font-data">{formatMbps(link.peak[direction])}</td>
            <td className="px-3 py-2.5 text-right font-data">{formatMbps(link.p95[direction])}</td>
            <td className="px-3 py-2.5 text-right font-data">{formatMbps(link.avg[direction])}</td>
            <td className="px-3 py-2.5 text-right font-data">{link.capacity_mbps === null ? DASH : formatMbps(link.capacity_mbps)}</td>
            <td className="px-3 py-2.5 text-right font-data">{formatPct(link.utilization_pct, 1)}</td>
            <td className="py-2.5 pl-3"><LinkStatus link={link} /></td>
        </tr>
    );

    return (
        <div className="space-y-5">
            <div className="flex items-center justify-between gap-3">
                <p className="text-xs text-muted-foreground">Arah ditampilkan: {direction === 'rx' ? 'masuk (RX)' : 'keluar (TX)'}. Klik nama link untuk menyembunyikan garisnya.</p>
                <DirectionToggle value={direction} onChange={setDirection} />
            </div>

            <div className="h-64 w-full" role="img" aria-label={`Grafik trafik upstream ${direction.toUpperCase()}`}>
                <ResponsiveContainer width="100%" height="100%">
                    <LineChart data={chartRows} margin={{ top: 8, right: 8, bottom: 0, left: 0 }}>
                        <CartesianGrid stroke="var(--border)" strokeDasharray="3 3" vertical={false} />
                        <XAxis
                            dataKey="ts"
                            type="number"
                            scale="time"
                            domain={['dataMin', 'dataMax']}
                            tickFormatter={(value) => formatPointTime(value, range)}
                            tick={{ fill: 'var(--muted-fg)', fontSize: 11 }}
                            tickLine={false}
                            axisLine={{ stroke: 'var(--border)' }}
                            minTickGap={48}
                        />
                        <YAxis
                            width={84}
                            tickFormatter={(value) => formatMbps(Number(value))}
                            tick={{ fill: 'var(--muted-fg)', fontSize: 11 }}
                            tickLine={false}
                            axisLine={false}
                        />
                        <Tooltip
                            {...chartTooltipProps}
                            labelFormatter={(value) => formatPointTime(value as number, range)}
                            formatter={(value: unknown, name: unknown) => [formatMbps(typeof value === 'number' ? value : null), String(name)]}
                        />
                        {plain.map((link, index) =>
                            hidden.has(link.key) ? null : (
                                <Line
                                    key={link.key}
                                    type="monotone"
                                    dataKey={link.key}
                                    name={link.display_name}
                                    stroke={seriesColor(index)}
                                    strokeDasharray={seriesDash(index)}
                                    strokeWidth={2}
                                    dot={false}
                                    isAnimationActive={false}
                                    connectNulls={false}
                                />
                            ),
                        )}
                    </LineChart>
                </ResponsiveContainer>
            </div>

            <div className="overflow-x-auto">
                <table className="w-full min-w-[720px] text-sm">
                    <thead>
                        <tr className="text-xs text-muted-foreground">
                            <th scope="col" className="pb-2 pr-3 text-left font-medium">Link</th>
                            <th scope="col" className="px-3 pb-2 text-right font-medium">Sekarang</th>
                            <th scope="col" className="px-3 pb-2 text-right font-medium">Puncak</th>
                            <th scope="col" className="px-3 pb-2 text-right font-medium">p95</th>
                            <th scope="col" className="px-3 pb-2 text-right font-medium">Rata-rata</th>
                            <th scope="col" className="px-3 pb-2 text-right font-medium">Kapasitas</th>
                            <th scope="col" className="px-3 pb-2 text-right font-medium">Pemakaian</th>
                            <th scope="col" className="pb-2 pl-3 text-left font-medium">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        {plain.map((link, index) => row(link, index))}
                        {aggregate.map((link) => row(link, null))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

export function UpstreamPanel({ state, range, settingsHref, suppressSetup, compactUnavailable }: UpstreamPanelProps) {
    return (
        <WidgetFrame
            title="Upstream"
            description="Trafik per link ke penyedia, dengan kapasitas dan pemakaiannya."
            state={state}
            settingsHref={settingsHref}
            suppressSetup={suppressSetup}
            compactUnavailable={compactUnavailable}
            skeletonClassName="h-80"
        >
            {(data) => <UpstreamBody data={data} range={range} />}
        </WidgetFrame>
    );
}
