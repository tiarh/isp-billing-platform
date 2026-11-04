export type WaChannel = 'ops' | 'ticket';

export type WaSection = 'report' | 'ops_alert' | 'shadow' | 'undelivered' | 'ticket' | 'connectivity' | 'attendance' | 'technician';

/** Keys of WaGroupRow.blocked: one per picker (the technician section has two pickers). */
export type WaBlockKey = Exclude<WaSection, 'technician'> | 'technician_share' | 'technician_input';

export interface WaGroupRow {
    jid: string;
    name: string;
    members: number;
    is_psb: boolean;
    is_report: boolean;
    is_ops_alert: boolean;
    /** Per role: null = selectable, otherwise the server refusal code. */
    blocked: Record<WaBlockKey, string | null>;
}

export interface WaGroupsResponse {
    channel: WaChannel;
    groups: WaGroupRow[];
    stale: boolean;
    fetched_at: number | null;
    error_code: string | null;
}

export interface WaMembership {
    member: boolean | null;
    subject: string | null;
    size: number | null;
    error: string | null;
}

export interface WaSettings {
    report: {
        group_jid: string;
        extra_jids: string[];
        channel: WaChannel;
        daily_enabled: boolean;
        recap_cmd_enabled: boolean;
        pppoe_cmd_enabled: boolean;
        onu_daily_enabled: boolean;
        psb_detail_daily_enabled: boolean;
        onu_recap_cmd_enabled: boolean;
    };
    ops_alert: { jid: string; effective_jid: string; uses_env_default: boolean };
    shadow: { jid: string; allow_report_group: boolean };
    undelivered: { jid: string };
    ticket: { jid: string; effective_jid: string; uses_env_default: boolean };
    connectivity: { jid: string; effective_jid: string; uses_env_default: boolean };
    attendance: { jid: string; label: string };
    technician: {
        share_jid: string;
        share_label: string;
        input_jid: string;
        input_label: string;
        reply_channel: WaChannel;
    };
    readonly: {
        psb_group_jids: string[];
        technician_share_jid: string;
        psb_recap_cmd_enabled: boolean;
        overlap_with_psb: string[];
    };
}

/**
 * Owner-facing group label: "Nama Grup · …123456@g.us" (name + last 6 chars of the JID's local part).
 * A name that merely repeats the JID is not shown twice. Pure, so it is pinned by a contract test.
 */
export function formatGroupLabel(name: string | null | undefined, jid: string): string {
    const [local, domain] = jid.split('@');
    const suffix = jid === '' ? '' : `…${local.slice(-6)}${domain ? `@${domain}` : ''}`;
    const clean = (name ?? '').trim();
    if (clean === '' || clean === jid) return suffix === '' ? '' : suffix;
    return `${clean} · ${suffix}`;
}

/** Indonesian text for the refusal codes returned in WaGroupRow.blocked. */
export const BLOCK_REASON_TEXT: Record<string, string> = {
    psb_group: 'Grup PSB/Migrasi',
    report_group: 'Grup Report',
    ops_group: 'Grup Alert Ops',
    ticket_group: 'Grup Tiket',
    share_group: 'Grup Share progres teknisi',
    report_or_ops_group: 'Grup Report/Alert Ops (perlu izin)',
};
