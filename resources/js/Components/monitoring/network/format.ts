import { formatNumber } from '@/lib/format';
import type { MonitoringRange, Point } from './types';

export const DASH = '—';

export type Level = 'ok' | 'warn' | 'crit';

/** [warn, crit] per metric (percent, except temperature in °C). */
export const THRESHOLDS = {
    cpu: [70, 90],
    memory: [80, 90],
    disk: [80, 90],
    temperature: [55, 65],
} as const;

export function levelFor(value: number | null | undefined, [warn, crit]: readonly [number, number]): Level | null {
    if (value === null || value === undefined || !Number.isFinite(value)) {
        return null;
    }
    if (value >= crit) {
        return 'crit';
    }
    if (value >= warn) {
        return 'warn';
    }

    return 'ok';
}

/** Semantic-token classes for a level; the text is never the only signal (see levelLabel). */
export const LEVEL_TEXT: Record<Level, string> = {
    ok: 'text-foreground',
    warn: 'text-status-degraded',
    crit: 'text-status-down',
};

export const LEVEL_BAR: Record<Level, string> = {
    ok: 'bg-status-up',
    warn: 'bg-status-degraded',
    crit: 'bg-status-down',
};

export const LEVEL_LABEL: Record<Level, string> = { ok: 'normal', warn: 'tinggi', crit: 'kritis' };

export function formatMbps(value: number | null | undefined): string {
    if (value === null || value === undefined || !Number.isFinite(value)) {
        return DASH;
    }
    if (Math.abs(value) >= 1000) {
        return `${formatNumber(value / 1000, { maximumFractionDigits: 2 })} Gbps`;
    }

    return `${formatNumber(value, { maximumFractionDigits: 1 })} Mbps`;
}

export function formatPct(value: number | null | undefined, digits = 0): string {
    if (value === null || value === undefined || !Number.isFinite(value)) {
        return DASH;
    }

    return `${formatNumber(value, { maximumFractionDigits: digits })}%`;
}

export function formatMs(value: number | null | undefined): string {
    if (value === null || value === undefined || !Number.isFinite(value)) {
        return DASH;
    }

    return `${formatNumber(value, { maximumFractionDigits: 1 })} ms`;
}

export function formatTemp(value: number | null | undefined): string {
    if (value === null || value === undefined || !Number.isFinite(value)) {
        return DASH;
    }

    return `${formatNumber(value, { maximumFractionDigits: 0 })}°C`;
}

export function formatVolt(value: number | null | undefined): string {
    if (value === null || value === undefined || !Number.isFinite(value)) {
        return DASH;
    }

    return `${formatNumber(value, { maximumFractionDigits: 1 })} V`;
}

export function formatCount(value: number | null | undefined): string {
    if (value === null || value === undefined || !Number.isFinite(value)) {
        return DASH;
    }

    return formatNumber(value, { maximumFractionDigits: 0 });
}

export function formatUptime(seconds: number | null | undefined): string {
    if (seconds === null || seconds === undefined || !Number.isFinite(seconds) || seconds < 0) {
        return DASH;
    }
    const days = Math.floor(seconds / 86400);
    const hours = Math.floor((seconds % 86400) / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    if (days > 0) {
        return `${days} hr ${hours} j`;
    }
    if (hours > 0) {
        return `${hours} j ${minutes} m`;
    }

    return `${minutes} m`;
}

const WIB = 'Asia/Jakarta';

/** HH:MM in WIB. */
export function formatClock(value: string | number | Date | null | undefined): string {
    if (value === null || value === undefined || value === '') {
        return DASH;
    }
    const date = value instanceof Date ? value : new Date(value);
    if (Number.isNaN(date.getTime())) {
        return DASH;
    }

    return new Intl.DateTimeFormat('id-ID', { timeZone: WIB, hour: '2-digit', minute: '2-digit', hour12: false }).format(date).replace('.', ':');
}

/** Axis/tooltip label for a point timestamp; includes the date for ranges of a day or more. */
export function formatPointTime(ts: number | string | null | undefined, range: MonitoringRange): string {
    const value = Number(ts);
    if (ts === null || ts === undefined || !Number.isFinite(value)) {
        return '';
    }
    const date = new Date(value);
    if (range === '1h' || range === '6h') {
        return formatClock(date);
    }

    return new Intl.DateTimeFormat('id-ID', { timeZone: WIB, day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit', hour12: false })
        .format(date)
        .replace(/\./g, ':');
}

export interface NamedSeries {
    key: string;
    points: Point[];
}

/** Merge several [ts, value] series into Recharts rows: { ts, [key]: value }. */
export function mergeSeries(series: NamedSeries[]): Array<Record<string, number | null>> {
    const rows = new Map<number, Record<string, number | null>>();
    for (const line of series) {
        for (const [ts, value] of line.points) {
            if (ts === null || ts === undefined) {
                continue;
            }
            const row = rows.get(ts) ?? { ts };
            row[line.key] = value;
            rows.set(ts, row);
        }
    }

    return [...rows.values()].sort((a, b) => Number(a.ts) - Number(b.ts));
}

/** Series colour for index i, from the chart tokens (cycles, with a dash pattern after the first lap). */
// chart-1 renders red in light mode, so it goes last: ordinary lines must not look like alarms.
const SERIES_VARS = ['var(--chart-2)', 'var(--chart-4)', 'var(--chart-3)', 'var(--chart-5)', 'var(--chart-1)'];
const SERIES_DASH = [undefined, '6 3', '2 3'];

export function seriesColor(index: number): string {
    return SERIES_VARS[index % SERIES_VARS.length];
}

export function seriesDash(index: number): string | undefined {
    return SERIES_DASH[Math.floor(index / SERIES_VARS.length) % SERIES_DASH.length];
}
