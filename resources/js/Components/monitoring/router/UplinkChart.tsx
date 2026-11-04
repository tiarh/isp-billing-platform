import { useMemo } from 'react';
import { Area, AreaChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { EmptyState } from '@/Components/EmptyState';
import { chartTooltipProps } from '@/lib/chartTheme';
import { formatMbps, formatPointTime, mergeSeries, seriesColor } from '@/Components/monitoring/network/format';
import type { MonitoringRange, WidgetState } from '@/Components/monitoring/network/types';
import { WidgetFrame } from '@/Components/monitoring/network/WidgetFrame';
import type { RouterOverviewData } from './types';

const DOWNLOAD_COLOR = seriesColor(0);
const UPLOAD_COLOR = seriesColor(1);

interface UplinkChartProps {
    state: WidgetState<RouterOverviewData>;
    range: MonitoringRange;
    compactUnavailable?: boolean;
    className?: string;
}

function Legend() {
    return (
        <ul className="mb-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
            <li className="inline-flex items-center gap-1.5">
                <span aria-hidden className="inline-block size-2.5 rounded-full" style={{ backgroundColor: DOWNLOAD_COLOR }} />
                Download (masuk dari upstream)
            </li>
            <li className="inline-flex items-center gap-1.5">
                <span aria-hidden className="inline-block size-2.5 rounded-full" style={{ backgroundColor: UPLOAD_COLOR }} />
                Upload (keluar ke upstream)
            </li>
        </ul>
    );
}

export function UplinkChart({ state, range, compactUnavailable, className }: UplinkChartProps) {
    const rows = useMemo(
        () =>
            mergeSeries([
                { key: 'rx', points: state.envelope?.data.uplink.rx ?? [] },
                { key: 'tx', points: state.envelope?.data.uplink.tx ?? [] },
            ]),
        [state.envelope],
    );

    return (
        <WidgetFrame
            title="Trafik uplink"
            description="Jumlah laju seluruh antarmuka uplink router ini."
            state={state}
            compactUnavailable={compactUnavailable}
            suppressSetup
            className={className}
            skeletonClassName="h-72"
        >
            {() => {
                if (rows.length === 0) {
                    return <EmptyState title="Belum ada data trafik" description="Grafana belum mengirim data uplink untuk router ini pada rentang yang dipilih." />;
                }

                return (
                    <div>
                        <Legend />
                        <div className="h-64 w-full" role="img" aria-label="Grafik trafik uplink download dan upload">
                            <ResponsiveContainer width="100%" height="100%">
                                <AreaChart data={rows} margin={{ top: 8, right: 8, bottom: 0, left: 0 }}>
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
                                    <Area type="monotone" dataKey="rx" name="Download" stroke={DOWNLOAD_COLOR} fill={DOWNLOAD_COLOR} fillOpacity={0.15} strokeWidth={2} dot={false} isAnimationActive={false} connectNulls={false} />
                                    <Area type="monotone" dataKey="tx" name="Upload" stroke={UPLOAD_COLOR} fill={UPLOAD_COLOR} fillOpacity={0.15} strokeWidth={2} dot={false} isAnimationActive={false} connectNulls={false} />
                                </AreaChart>
                            </ResponsiveContainer>
                        </div>
                    </div>
                );
            }}
        </WidgetFrame>
    );
}
