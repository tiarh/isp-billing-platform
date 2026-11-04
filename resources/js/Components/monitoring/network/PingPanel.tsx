import { useMemo } from 'react';
import { CartesianGrid, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { EmptyState } from '@/Components/EmptyState';
import { StatusBadge, type StatusTone } from '@/Components/StatusBadge';
import { chartTooltipProps } from '@/lib/chartTheme';
import { DASH, formatMs, formatPct, formatPointTime, mergeSeries, seriesColor, seriesDash } from './format';
import type { MonitoringRange, PingData, PingLine, PingState, WidgetState } from './types';
import { WidgetFrame } from './WidgetFrame';

const STATE: Record<PingState, { tone: StatusTone; label: string }> = {
    up: { tone: 'up', label: 'Normal' },
    degraded: { tone: 'degraded', label: 'Lambat / loss' },
    down: { tone: 'down', label: 'Mati' },
    icmp_blocked: { tone: 'info', label: 'ICMP diblokir' },
};

const KIND_LABEL: Record<string, string> = { upstream: 'Upstream', internet: 'Internet', router: 'Router', area: 'Area' };

function LineChartCard({ title, lines, range, format }: { title: string; lines: PingLine[]; range: MonitoringRange; format: (value: number | null) => string }) {
    const rows = useMemo(() => mergeSeries(lines.map((line) => ({ key: `${line.kind}|${line.target_name}`, points: line.points }))), [lines]);

    return (
        <div>
            <h4 className="mb-2 text-xs font-medium text-muted-foreground">{title}</h4>
            <div className="h-44 w-full" role="img" aria-label={title}>
                <ResponsiveContainer width="100%" height="100%">
                    <LineChart data={rows} margin={{ top: 6, right: 8, bottom: 0, left: 0 }}>
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
                        <YAxis width={72} tickFormatter={(value) => format(Number(value))} tick={{ fill: 'var(--muted-fg)', fontSize: 11 }} tickLine={false} axisLine={false} />
                        <Tooltip
                            {...chartTooltipProps}
                            labelFormatter={(value) => formatPointTime(value as number, range)}
                            formatter={(value: unknown, name: unknown) => [format(typeof value === 'number' ? value : null), String(name)]}
                        />
                        {lines.map((line, index) => (
                            <Line
                                key={`${line.kind}|${line.target_name}`}
                                name={line.target_name}
                                type="monotone"
                                dataKey={`${line.kind}|${line.target_name}`}
                                stroke={seriesColor(index)}
                                strokeDasharray={seriesDash(index)}
                                strokeWidth={2}
                                dot={false}
                                isAnimationActive={false}
                                connectNulls={false}
                            />
                        ))}
                    </LineChart>
                </ResponsiveContainer>
            </div>
            <ul className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                {lines.map((line, index) => (
                    <li key={`${line.kind}|${line.target_name}`} className="inline-flex items-center gap-1.5">
                        <span aria-hidden className="inline-block size-2 rounded-full" style={{ backgroundColor: seriesColor(index) }} />
                        {line.target_name}
                    </li>
                ))}
            </ul>
        </div>
    );
}

interface PingPanelProps {
    state: WidgetState<PingData>;
    range: MonitoringRange;
    settingsHref?: string | null;
    suppressSetup?: boolean;
    compactUnavailable?: boolean;
}

export function PingPanel({ state, range, settingsHref, suppressSetup, compactUnavailable }: PingPanelProps) {
    return (
        <WidgetFrame
            title="Ping dan packet loss"
            description="Probe dari blackbox exporter ke upstream, internet, dan router."
            state={state}
            settingsHref={settingsHref}
            suppressSetup={suppressSetup}
            compactUnavailable={compactUnavailable}
            skeletonClassName="h-64"
        >
            {(data) => {
                if (data.targets.length === 0) {
                    return <EmptyState title="Belum ada target ping" description="Blackbox exporter belum mengirim data probe." />;
                }
                const kinds = [...new Set(data.targets.map((target) => target.kind))];
                const hasCharts = data.series.rtt.length > 0 || data.series.loss.length > 0;

                return (
                    <div className="space-y-6">
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[520px] text-sm">
                                <thead>
                                    <tr className="text-xs text-muted-foreground">
                                        <th scope="col" className="pb-2 pr-3 text-left font-medium">Target</th>
                                        <th scope="col" className="px-3 pb-2 text-right font-medium">RTT</th>
                                        <th scope="col" className="px-3 pb-2 text-right font-medium">Loss</th>
                                        <th scope="col" className="pb-2 pl-3 text-left font-medium">Status</th>
                                    </tr>
                                </thead>
                                {kinds.map((kind) => (
                                    <tbody key={kind}>
                                        <tr>
                                            <th colSpan={4} scope="colgroup" className="pb-1 pt-4 text-left text-xs font-medium uppercase tracking-wide text-muted-foreground">
                                                {KIND_LABEL[kind] ?? kind}
                                            </th>
                                        </tr>
                                        {data.targets
                                            .filter((target) => target.kind === kind)
                                            .map((target) => (
                                                <tr key={`${kind}-${target.target_name}`} className="border-t border-border">
                                                    <td className="py-2.5 pr-3">{target.router_name ?? target.target_name}</td>
                                                    <td className="px-3 py-2.5 text-right font-data">{target.rtt_ms === null ? DASH : formatMs(target.rtt_ms)}</td>
                                                    <td className="px-3 py-2.5 text-right font-data">{formatPct(target.loss_pct, 1)}</td>
                                                    <td className="py-2.5 pl-3"><StatusBadge tone={STATE[target.state].tone} label={STATE[target.state].label} /></td>
                                                </tr>
                                            ))}
                                    </tbody>
                                ))}
                            </table>
                        </div>

                        {hasCharts && (
                            <div className="grid gap-6 lg:grid-cols-2">
                                <LineChartCard title="RTT (ms)" lines={data.series.rtt} range={range} format={formatMs} />
                                <LineChartCard title="Packet loss (%)" lines={data.series.loss} range={range} format={(value) => formatPct(value, 1)} />
                            </div>
                        )}
                    </div>
                );
            }}
        </WidgetFrame>
    );
}
