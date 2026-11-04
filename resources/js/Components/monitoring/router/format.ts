import { formatNumber } from '@/lib/format';
import { DASH } from '@/Components/monitoring/network/format';
import type { Point } from '@/Components/monitoring/network/types';

export { DASH };

/** Decimal gigabytes (1 GB = 1e9 bytes), as the server sends them. */
export function formatGB(value: number | null | undefined): string {
    if (value === null || value === undefined || !Number.isFinite(value)) {
        return DASH;
    }
    if (Math.abs(value) >= 1000) {
        return `${formatNumber(value / 1000, { maximumFractionDigits: 2 })} TB`;
    }
    if (Math.abs(value) >= 100) {
        return `${formatNumber(value, { maximumFractionDigits: 0 })} GB`;
    }

    return `${formatNumber(value, { maximumFractionDigits: value >= 10 ? 1 : 2 })} GB`;
}

/** Short axis label, no unit. */
export function formatGBShort(value: number | null | undefined): string {
    if (value === null || value === undefined || !Number.isFinite(value)) {
        return DASH;
    }

    return formatNumber(value, { maximumFractionDigits: value >= 10 ? 0 : 1 });
}

const DAY_FORMAT = new Intl.DateTimeFormat('id-ID', { timeZone: 'UTC', day: 'numeric', month: 'short' });

/** "12 Okt" from a YYYY-MM-DD WIB date string (no timezone shift: the date is already WIB). */
export function formatDay(date: string): string {
    const parsed = new Date(`${date}T00:00:00Z`);

    return Number.isNaN(parsed.getTime()) ? date : DAY_FORMAT.format(parsed);
}

/** Wraps 7 daily GB values as sparkline points (index as x); invalid days become gaps. */
export function sparkPoints(values: Array<number | null>): Point[] {
    return values.map((value, index) => [index, value]);
}
