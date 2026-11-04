/**
 * Single date/time module for the UI. Storage and API payloads are UTC; every
 * time shown to or entered by a user is WIB (Asia/Jakarta, UTC+7, no DST).
 *
 * Do not use toLocale*String / toISOString().slice / Intl.DateTimeFormat
 * without a timeZone elsewhere (see the no-restricted-syntax rule in
 * eslint.config.mjs).
 */
export const WIB_TIME_ZONE = 'Asia/Jakarta';

export type DateValue = string | Date | number | null | undefined;

const NAIVE_DATETIME = /^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?)$/;

/**
 * Parse a value coming from the server into a Date.
 * - ISO with Z or an offset: parsed as-is.
 * - Naive "Y-m-d H:i:s" (DB::table / max() output): treated as UTC, with the
 *   space replaced by "T" so Safari does not return Invalid Date.
 * - Date-only "Y-m-d": parsed as UTC midnight (same calendar date in WIB).
 */
export function parseServerDate(value: DateValue): Date | null {
    if (value === null || value === undefined || value === '') return null;

    if (value instanceof Date) {
        return Number.isNaN(value.getTime()) ? null : value;
    }
    if (typeof value === 'number') {
        const fromNumber = new Date(value);
        return Number.isNaN(fromNumber.getTime()) ? null : fromNumber;
    }

    const text = value.trim();
    const naive = NAIVE_DATETIME.exec(text);
    const date = new Date(naive ? `${naive[1]}T${naive[2]}Z` : text);

    return Number.isNaN(date.getTime()) ? null : date;
}

function wibParts(date: Date): Record<string, string> {
    return new Intl.DateTimeFormat('en-CA', {
        timeZone: WIB_TIME_ZONE,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    }).formatToParts(date).reduce<Record<string, string>>((result, part) => {
        result[part.type] = part.value;
        return result;
    }, {});
}

export function formatWibDateTime(value: DateValue): string {
    const date = parseServerDate(value);
    if (!date) return '-';

    return `${new Intl.DateTimeFormat('id-ID', {
        timeZone: WIB_TIME_ZONE,
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    }).format(date)} WIB`;
}

export function formatWibDate(value: DateValue): string {
    const date = parseServerDate(value);
    if (!date) return '-';

    return new Intl.DateTimeFormat('id-ID', {
        timeZone: WIB_TIME_ZONE,
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    }).format(date);
}

/** HH.mm (id-ID style) in WIB. */
export function formatWibTime(value: DateValue, withSeconds = false): string {
    const date = parseServerDate(value);
    if (!date) return '-';

    return new Intl.DateTimeFormat('id-ID', {
        timeZone: WIB_TIME_ZONE,
        hour: '2-digit',
        minute: '2-digit',
        ...(withSeconds ? { second: '2-digit' } : {}),
        hourCycle: 'h23',
    }).format(date);
}

export function formatWibMonth(value: DateValue): string {
    const date = parseServerDate(value);
    if (!date) return '-';

    return new Intl.DateTimeFormat('id-ID', {
        timeZone: WIB_TIME_ZONE,
        month: 'long',
        year: 'numeric',
    }).format(date);
}

/** Today's calendar date in WIB, "YYYY-MM-DD". */
export function todayWib(now: Date = new Date()): string {
    const p = wibParts(now);
    return `${p.year}-${p.month}-${p.day}`;
}

/** Current month in WIB, "YYYY-MM". */
export function currentMonthWib(now: Date = new Date()): string {
    return todayWib(now).slice(0, 7);
}

/**
 * True when a due date (date-only "Y-m-d" or a full timestamp) is before
 * today in WIB. A date-only value is due at the end of that WIB day, so an
 * invoice due today is not overdue until the next WIB day begins.
 */
export function isPastDueWib(dateStr: DateValue, now: Date = new Date()): boolean {
    if (typeof dateStr === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(dateStr.trim())) {
        return dateStr.trim() < todayWib(now);
    }

    const date = parseServerDate(dateStr);
    if (!date) return false;

    const p = wibParts(date);
    return `${p.year}-${p.month}-${p.day}` < todayWib(now);
}

/** Value for <input type="datetime-local">, "YYYY-MM-DDTHH:mm", in WIB. */
export function toWibDateTimeLocal(value: DateValue = new Date()): string {
    const date = parseServerDate(value);
    if (!date) return '';

    const p = wibParts(date);
    return `${p.year}-${p.month}-${p.day}T${p.hour}:${p.minute}`;
}

/**
 * Turns a datetime-local value ("2026-10-13T22:00", read as WIB wall-clock time)
 * into an unambiguous ISO string with an explicit +07:00 offset.
 */
export function wibLocalInputToOffsetString(value: string): string {
    const trimmed = value.trim();
    if (!trimmed) return '';
    const withSeconds = /T\d{2}:\d{2}$/.test(trimmed) ? `${trimmed}:00` : trimmed;

    return `${withSeconds}+07:00`;
}

/** @deprecated use toWibDateTimeLocal */
export const formatWibDateTimeLocalInput = toWibDateTimeLocal;
