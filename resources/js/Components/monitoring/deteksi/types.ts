import type { StatusTone } from '@/Components/StatusBadge';
import type { EpisodeTicket } from '@/Pages/Monitoring/OutageTicketAction';

export type Customer = {
    id: number;
    code: string | null;
    name: string;
    pppoe_user: string | null;
    onu_port: string | null;
    rx_dbm: number | null;
    rx_collected_at: string | null;
    status: string | null;
};

export type MatchedCustomer = { id: number; code: string | null; name: string; pppoe_user: string | null };

export type Episode = {
    id: number;
    signal_type: string;
    type_label: string;
    detail?: string | null;
    cause_code?: string | null;
    cause_label?: string | null;
    cause_text?: string | null;
    diagnosed_label?: string | null;
    history_available?: boolean;
    olt_name: string | null;
    pon_port: string | null;
    odp_label: string | null;
    state: string;
    state_label: string;
    suppressed_reason: string | null;
    suppressed_label: string | null;
    would_action: string | null;
    would_ticket: boolean;
    opened_at: string | null;
    pinned_age_days?: number | null;
    confirmed_at: string | null;
    resolved_at: string | null;
    affected_count: number;
    customers: Customer[];
    /** Search only: the searched customers inside this episode (also when beyond the inline ten). */
    matched_customers?: MatchedCustomer[];
    customers_more: number;
    customers_unlisted: number;
    ticket?: EpisodeTicket | null;
    customer_open_ticket?: { id: number; url: string } | null;
    can_ticket?: boolean;
    report_wa_status?: string | null;
};

export type HistoryRow = { at: string; kind: 'onu' | 'cpe' | 'pppoe'; text: string };

export const HISTORY_SOURCE: Record<HistoryRow['kind'], string> = { onu: 'OLT', cpe: 'Perangkat', pppoe: 'PPPoE' };

export const STATE_TONE: Record<string, StatusTone> = {
    open: 'info',
    confirmed: 'down',
    resolved: 'up',
    expired: 'neutral',
};

export const WOULD_LABEL: Record<string, string> = {
    ticket_mass: 'Akan buat tiket massal',
    ticket_single: 'Akan buat tiket',
    ticket_maintenance: 'Akan buat tiket maintenance',
    noc_alarm: 'Alarm NOC',
    weekly_list: 'Daftar mingguan (di luar batas harian)',
    log_only: 'Hanya dicatat',
    none: 'Tidak ada aksi',
};

/** Location line: OLT, PON, ODP (whatever is known). */
export function locationText(row: Episode): string {
    return [row.olt_name, row.pon_port && `PON ${row.pon_port}`, row.odp_label].filter(Boolean).join(' · ') || '-';
}

/** The one cause line of a row: the cause an admin saved wins, then the system guess, then what the detector read. */
export function causeLine(row: Episode): { prefix: string; text: string } | null {
    if (row.cause_label) return { prefix: 'Penyebab', text: row.cause_label };
    const guess = row.diagnosed_label || row.cause_text;

    return guess ? { prefix: 'Dugaan', text: guess } : null;
}

/** "8 mnt", "3 jam", "2 hari": how long ago, in the shortest plain words. */
export function shortAge(iso: string | null, now: number = Date.now()): string {
    if (!iso) return '-';
    const minutes = Math.max(0, Math.round((now - Date.parse(iso)) / 60_000));
    if (minutes < 1) return 'baru saja';
    if (minutes < 60) return `${minutes} mnt`;
    const hours = Math.floor(minutes / 60);
    if (hours < 48) return `${hours} jam`;

    return `${Math.floor(hours / 24)} hari`;
}

/** Age text of the status cell: pinned rows say how many days they have been open, closed rows say when they ended. */
export function ageText(row: Episode): string | null {
    if (row.pinned_age_days != null) return null;
    const ago = (iso: string | null): string => {
        const age = shortAge(iso);

        return age === 'baru saja' || age === '-' ? age : `${age} lalu`;
    };
    if (row.state === 'resolved' || row.state === 'expired') return row.resolved_at ? `Selesai ${ago(row.resolved_at)}` : null;

    return ago(row.opened_at);
}
