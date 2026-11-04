import type { MutableRefObject } from 'react';

export interface RecoveryItem {
    id: number;
    device_type: string;
    expected_serial_number: string | null;
    captured_serial_number: string | null;
    status: string;
    condition: string;
    captured_at: string | null;
    verified_at: string | null;
    has_photos: boolean;
    warehouse_serial_number?: string | null;
    photos?: { index: number; url: string; kind?: string | null }[];
    notes: string | null;
}

export interface RecoveryCase {
    id: number;
    ticket: { id: number; code: string; status: string; dismantle_reason?: string | null; dismantle_reason_label?: string | null; dismantle_reason_note?: string | null } | null;
    customer: { id: number; code: string; name: string } | null;
    area: { id: number; name: string; code: string } | null;
    status: string;
    keep_customer_active?: boolean;
    pending_termination?: { id: number; reason_label: string; requested_at: string | null } | null;
    status_group: string;
    substatus: string;
    assigned_to: { id: number; name: string } | null;
    scheduled_at: string | null;
    started_at: string | null;
    received_at: string | null;
    exception_reason: string | null;
    version: number;
    items: RecoveryItem[];
}

export interface ReconciliationSource {
    available: boolean;
    matched: boolean;
    candidate_count: number;
    ambiguous: boolean;
    stale: boolean;
    last_synced_at: string | null;
}

export interface RecoveryReconciliation {
    customer_device: ReconciliationSource & {
        expected_serial_number: string | null;
        olt_id: number | null;
        olt_port_label: string | null;
        olt_status: string | null;
    };
    olt_onu: ReconciliationSource & {
        onu: { olt_id: number | null; onu_ref: string | null; serial_number: string | null; onu_name: string | null; state: string | null; phase_state: string | null; rx_power_dbm: number | null; distance_m: number | null } | null;
    };
    genieacs: ReconciliationSource & {
        device: { device_id: string | null; serial_number: string | null; manufacturer: string | null; model: string | null; last_inform_at: string | null; last_ping_at: string | null; last_ping_success: boolean | null } | null;
    };
}

export interface RecoveryEvent {
    id: number;
    item_id: number | null;
    event_type: string;
    from_status: string | null;
    to_status: string | null;
    reason: string | null;
    source: string;
    created_at: string | null;
}

export interface RecoveryDetail extends RecoveryCase {
    events: RecoveryEvent[];
    reconciliation?: RecoveryReconciliation | null;
}

export interface AreaOption { id: number; name: string; code: string; }
export interface TechnicianOption { id: number; name: string; role: string; }

export interface RecoveryUser { id: number; role: string; scope?: string; }

// Shared props for the action cards rendered inside RecoveryDetail. The
// operationKeys ref stays page-level so retry idempotency keys never change.
export interface ActionCardProps {
    selected: RecoveryCase;
    detail: RecoveryDetail;
    writesEnabled: boolean;
    operationKeys: MutableRefObject<Record<string, string>>;
    onDone: () => void;
}

export const STATUS_TONES: Record<string, 'up' | 'down' | 'degraded' | 'info' | 'neutral'> = {
    waiting_assignment: 'neutral',
    assigned: 'info',
    scheduled: 'info',
    pickup_started: 'degraded',
    carried: 'degraded',
    received: 'up',
    exception: 'down',
    exception_accepted: 'down',
    cancelled: 'neutral',
    reused: 'neutral',
};

export const CONDITION_LABELS: Record<string, string> = {
    good: 'Baik',
    damaged: 'Rusak',
    lost: 'Hilang',
    unknown: 'Tidak diketahui',
    mismatch: 'SN tidak cocok',
};

export const PHOTO_KIND_LABELS: Record<string, string> = {
    label_sn: 'Label SN',
    perangkat_utuh: 'Perangkat utuh',
    penerimaan: 'Penerimaan gudang',
    lainnya: 'Lainnya',
};
