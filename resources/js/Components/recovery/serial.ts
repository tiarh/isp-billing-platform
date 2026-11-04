import type { MutableRefObject } from 'react';
import { toWibDateTimeLocal } from '@/lib/dateTime';

// Same rule as DeviceRecoveryService::serialsMatch (uppercase, alnum only;
// GPON vendor+8hex equals its 16-hex form).
export function normalizeSerial(value: string | null | undefined): string {
    return (value || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
}

export function gponHexSerial(serial: string): string {
    const match = /^([A-Z]{4})([0-9A-F]{8})$/.exec(serial);
    if (!match) return serial;

    return Array.from(match[1]).map((char) => char.charCodeAt(0).toString(16).toUpperCase().padStart(2, '0')).join('') + match[2];
}

export function serialsMatch(first: string | null | undefined, second: string | null | undefined): boolean {
    const a = normalizeSerial(first);
    const b = normalizeSerial(second);
    if (!a || !b) return false;

    return a === b || gponHexSerial(a) === gponHexSerial(b);
}

export function operationKey(store: MutableRefObject<Record<string, string>>, name: string): string {
    if (!store.current[name]) {
        store.current[name] = globalThis.crypto?.randomUUID?.() || `${name}-${Date.now()}`;
    }

    return store.current[name];
}

export function toDateTimeLocal(value: string | null): string {
    // datetime-local value in WIB (the server parses it as WIB), never the browser zone.
    return toWibDateTimeLocal(value);
}
