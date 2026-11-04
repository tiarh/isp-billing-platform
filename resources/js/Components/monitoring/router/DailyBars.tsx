import { useMemo } from 'react';
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { chartTooltipProps } from '@/lib/chartTheme';
import { seriesColor } from '@/Components/monitoring/network/format';
import { formatDay, formatGB, formatGBShort } from './format';
import type { DailyPoint } from './types';

export const DOWNLOAD_COLOR = seriesColor(0);
export const UPLOAD_COLOR = seriesColor(1);

interface DailyBarsProps {
    days: DailyPoint[];
    height?: number;
    label: string;
}

const QUALITY_NOTE: Record<string, string> = {
    invalid: 'Data tidak valid, tidak dihitung',
    none: 'Belum ada data',
    partial: 'Data sebagian',
};

function DayTooltip({ active, payload }: { active?: boolean; payload?: Array<{ payload: DailyPoint }> }) {
    const day = payload?.[0]?.payload;
    if (!active || !day) {
        return null;
    }
    const note = QUALITY_NOTE[day.quality];

    return (
        <div style={chartTooltipProps.contentStyle} className="border px-3 py-2 shadow-sm">
            <p style={chartTooltipProps.labelStyle}>{formatDay(day.date)}</p>
            {day.download_gb !== null && (
                <p style={chartTooltipProps.itemStyle}>
                    Download {formatGB(day.download_gb)} · Upload {formatGB(day.upload_gb)}
                </p>
            )}
            {note && <p style={chartTooltipProps.itemStyle} className="text-muted-foreground">{note}</p>}
        </div>
    );
}

/** Stacked GB/day bars. Invalid and missing days stay empty (gaps) instead of showing a wrong 0. */
export function DailyBars({ days, height = 224, label }: DailyBarsProps) {
    const rows = useMemo(() => days.map((day) => ({ ...day, down: day.download_gb, up: day.upload_gb })), [days]);

    return (
        <div className="w-full" style={{ height }} role="img" aria-label={label}>
            <ResponsiveContainer width="100%" height="100%">
                <BarChart data={rows} margin={{ top: 8, right: 8, bottom: 0, left: 0 }}>
                    <CartesianGrid stroke="var(--border)" strokeDasharray="3 3" vertical={false} />
                    <XAxis
                        dataKey="date"
                        tickFormatter={(value) => formatDay(String(value))}
                        tick={{ fill: 'var(--muted-fg)', fontSize: 11 }}
                        tickLine={false}
                        axisLine={{ stroke: 'var(--border)' }}
                        minTickGap={24}
                    />
                    <YAxis width={44} tickFormatter={(value) => formatGBShort(Number(value))} tick={{ fill: 'var(--muted-fg)', fontSize: 11 }} tickLine={false} axisLine={false} />
                    <Tooltip content={<DayTooltip />} cursor={chartTooltipProps.cursor} />
                    <Bar dataKey="down" name="Download" stackId="gb" fill={DOWNLOAD_COLOR} isAnimationActive={false} />
                    <Bar dataKey="up" name="Upload" stackId="gb" fill={UPLOAD_COLOR} isAnimationActive={false} />
                </BarChart>
            </ResponsiveContainer>
        </div>
    );
}

export function DailyLegend() {
    return (
        <ul className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
            <li className="inline-flex items-center gap-1.5">
                <span aria-hidden className="inline-block size-2.5 rounded-sm" style={{ backgroundColor: DOWNLOAD_COLOR }} />
                Download
            </li>
            <li className="inline-flex items-center gap-1.5">
                <span aria-hidden className="inline-block size-2.5 rounded-sm" style={{ backgroundColor: UPLOAD_COLOR }} />
                Upload
            </li>
        </ul>
    );
}
