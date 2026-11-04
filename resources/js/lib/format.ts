import { WIB_TIME_ZONE, parseServerDate, type DateValue } from './dateTime';

// lib/dateTime.ts is the single date module; these formatters delegate parsing
// to it (naive "Y-m-d H:i:s" server strings are UTC) and keep their output.
export type { DateValue };

const asDate = parseServerDate;

export function formatIdr(amount: number | string | null | undefined): string {
    const numericAmount = Number(amount);

    return new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        minimumFractionDigits: 0,
    }).format(Number.isFinite(numericAmount) ? numericAmount : 0);
}

export function formatNumber(value: number | null | undefined, options: Intl.NumberFormatOptions = { maximumFractionDigits: 2 }): string {
    if (value === null || value === undefined || !Number.isFinite(value)) return '-';

    return new Intl.NumberFormat('id-ID', options).format(value);
}

export function formatDate(value: DateValue): string {
    const date = asDate(value);
    if (!date) return '-';

    return new Intl.DateTimeFormat('id-ID', {
        timeZone: WIB_TIME_ZONE,
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    }).format(date);
}

export function formatMonth(value: DateValue): string {
    const date = asDate(value);
    if (!date) return '-';

    return new Intl.DateTimeFormat('id-ID', {
        timeZone: WIB_TIME_ZONE,
        month: 'long',
        year: 'numeric',
    }).format(date);
}

export function formatDateTime(value: DateValue): string {
    const date = asDate(value);
    if (!date) return '-';

    return new Intl.DateTimeFormat('id-ID', {
        timeZone: WIB_TIME_ZONE,
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
    }).format(date);
}

export function formatTime(value: DateValue): string {
    const date = asDate(value);
    if (!date) return '-';

    return new Intl.DateTimeFormat('id-ID', {
        timeZone: WIB_TIME_ZONE,
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
    }).format(date);
}

export function formatRelative(value: DateValue, now: Date = new Date()): string {
    const date = asDate(value);
    if (!date) return '-';

    const seconds = Math.round((date.getTime() - now.getTime()) / 1000);
    const future = seconds > 0;
    const absolute = Math.abs(seconds);
    const unit = absolute < 60
        ? `${absolute} dtk`
        : absolute < 3600
            ? `${Math.round(absolute / 60)} mnt`
            : absolute < 86400
                ? `${Math.round(absolute / 3600)} jam`
                : `${Math.round(absolute / 86400)} hari`;

    if (absolute < 5) return 'baru saja';
    return future ? `dalam ${unit}` : `${unit} lalu`;
}
