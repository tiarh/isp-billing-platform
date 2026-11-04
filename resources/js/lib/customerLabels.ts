import type { StatusTone } from '@/Components/StatusBadge';

/** Official customers.status values (DB enum). 'inactive' is legacy. */
export type CustomerStatus =
    | 'pending_installation'
    | 'active'
    | 'isolated'
    | 'suspended'
    | 'terminated'
    | 'inactive';

export const CUSTOMER_STATUS_LABELS: Record<CustomerStatus, string> = {
    pending_installation: 'Menunggu Pasang',
    active: 'Aktif',
    isolated: 'Isolir',
    suspended: 'Cuti',
    terminated: 'Berhenti',
    inactive: 'Berhenti (lama)',
};

export const CUSTOMER_STATUS_TONES: Record<CustomerStatus, StatusTone> = {
    pending_installation: 'info',
    active: 'up',
    isolated: 'degraded',
    suspended: 'neutral',
    terminated: 'neutral',
    inactive: 'neutral',
};

/** Legacy spellings that are never stored but may still appear in old payloads. */
const LEGACY_STATUS_ALIASES: Record<string, CustomerStatus> = {
    isolir: 'isolated',
    dismantled: 'terminated',
    offboarding: 'terminated',
    cuti: 'suspended',
};

export const MIKROTIK_SYNC_LABELS: Record<string, string> = {
    synced: 'Tersinkron',
    missing: 'Tidak Ada',
    unknown: 'Belum Dicek',
};

export function normalizeCustomerStatus(status: string): CustomerStatus | null {
    const key = status.toLowerCase();
    if (key in CUSTOMER_STATUS_LABELS) {
        return key as CustomerStatus;
    }

    return LEGACY_STATUS_ALIASES[key] ?? null;
}

export function customerStatusLabel(status: string): string {
    const normalized = normalizeCustomerStatus(status);

    return normalized ? CUSTOMER_STATUS_LABELS[normalized] : status.replaceAll('_', ' ');
}

export function customerStatusTone(status: string): StatusTone {
    const normalized = normalizeCustomerStatus(status);

    return normalized ? CUSTOMER_STATUS_TONES[normalized] : 'neutral';
}

/** Filter/select options: the 5 official statuses (no legacy 'inactive'). */
export const CUSTOMER_STATUS_OPTIONS: { label: string; value: CustomerStatus }[] = (
    ['pending_installation', 'active', 'isolated', 'suspended', 'terminated'] as CustomerStatus[]
).map((value) => ({ label: CUSTOMER_STATUS_LABELS[value], value }));

export function mikrotikSyncLabel(status: string): string {
    return MIKROTIK_SYNC_LABELS[status] || status;
}
