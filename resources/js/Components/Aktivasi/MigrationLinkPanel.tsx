import { useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/Components/ui/button';
import { readMetaCsrfToken, readXsrfCookieToken } from '@/lib/csrf';

export interface MigrationLinkInfo {
    migration_id: number;
    pppoe_user?: string | null;
    status?: string;
    psb_draft_id?: number | null;
    message?: string;
    mikrotik_note?: string | null;
    old_onu_note?: string | null;
}

export interface MigrationLinkCandidate {
    migration_id: number;
    pppoe_user?: string | null;
    new_sn?: string | null;
    status?: string;
    reasons?: string[];
    linkable: boolean;
    blocked_reasons?: string[];
    message: string;
}

export interface MigrationLinkContext {
    olt_id: number;
    router_id?: number | null;
    sn: string;
    port: string;
    onu_id: number;
    name?: string;
    psb_draft_id?: number | null;
}

interface Props {
    link?: MigrationLinkInfo | null;
    candidates?: MigrationLinkCandidate[] | null;
    warning?: string | null;
    context: MigrationLinkContext | null;
    onLinked?: (link: MigrationLinkInfo) => void;
}

/**
 * Manual provision vs open customer migration (#1102/#1106).
 * Exact match is linked by the server; a near match (PPPoE/SN beda ≤2 huruf)
 * is only a warning until the technician presses "Tautkan".
 */
export function MigrationLinkPanel({ link, candidates, warning, context, onLinked }: Props) {
    const [busyId, setBusyId] = useState<number | null>(null);
    const [linked, setLinked] = useState<MigrationLinkInfo | null>(null);
    const current = linked ?? link ?? null;

    const confirmLink = async (candidate: MigrationLinkCandidate) => {
        if (!context) return;
        setBusyId(candidate.migration_id);
        try {
            const csrf = readMetaCsrfToken() || readXsrfCookieToken() || (window as any).csrfToken || '';
            const res = await fetch('/api/olt-c300/link-migration', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
                credentials: 'include',
                body: JSON.stringify({ ...context, migration_id: candidate.migration_id }),
            });
            const data = await res.json().catch(() => null);
            if (res.ok && data?.success && data.migration_link) {
                setLinked(data.migration_link);
                onLinked?.(data.migration_link);
                toast.success(data.message || `Tertaut ke migrasi #${candidate.migration_id}`);
            } else {
                toast.error(data?.error || `Gagal menautkan migrasi #${candidate.migration_id}`);
            }
        } catch (err: any) {
            toast.error(`Gagal menautkan: ${err?.message ?? 'error'}`);
        } finally {
            setBusyId(null);
        }
    };

    if (current) {
        return (
            <div className="rounded-lg border border-status-up/30 bg-status-up-bg p-3 text-sm text-foreground">
                <p className="font-semibold">{current.message || `Tertaut ke migrasi #${current.migration_id}`}</p>
                {current.psb_draft_id ? <p className="mt-1 text-xs">PSB draft #{current.psb_draft_id} ditautkan ke pelanggan migrasi.</p> : null}
                {current.mikrotik_note ? <p className="mt-1 text-xs text-status-degraded">{current.mikrotik_note}</p> : null}
                {current.old_onu_note ? <p className="mt-1 text-xs text-status-degraded">{current.old_onu_note}</p> : null}
            </div>
        );
    }

    const rows = Array.isArray(candidates) ? candidates : [];
    if (rows.length === 0 && !warning) return null;

    return (
        <div className="rounded-lg border border-status-degraded/30 bg-status-degraded-bg p-3 text-sm text-foreground space-y-2">
            <p className="font-semibold">Cek migrasi terbuka</p>
            {rows.length === 0 && warning ? <p className="text-xs">{warning}</p> : null}
            {rows.map((row) => (
                <div key={row.migration_id} className="flex flex-wrap items-center justify-between gap-2">
                    <span className="text-xs">{row.message}</span>
                    {row.linkable && context ? (
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            disabled={busyId !== null}
                            onClick={() => confirmLink(row)}
                        >
                            {busyId === row.migration_id ? 'Menautkan...' : `Tautkan ke migrasi #${row.migration_id}`}
                        </Button>
                    ) : null}
                </div>
            ))}
        </div>
    );
}
