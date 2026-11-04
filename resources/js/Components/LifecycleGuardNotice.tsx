import { useId } from 'react';
import { ShieldAlert } from 'lucide-react';
import { Textarea } from '@/Components/ui/textarea';

/**
 * Roadmap 1.3: shown when the server's lifecycle guard refuses to archive /
 * terminate a customer that is still in use (PPPoE active, ONU working,
 * traffic in the last 2 days) or whose status is unknown. Everyone sees the
 * reason; only a superadmin gets the override form (written reason + checkbox).
 * The server enforces all of it (superadmin, reason length) again.
 */
export const LIFECYCLE_OVERRIDE_REASON_MIN = 20;

export function lifecycleOverrideReady(reason: string, checked: boolean, min: number = LIFECYCLE_OVERRIDE_REASON_MIN): boolean {
    return checked && reason.trim().length >= min;
}

interface LifecycleGuardNoticeProps {
    message: string;
    isSuperadmin: boolean;
    reason: string;
    onReasonChange: (value: string) => void;
    checked: boolean;
    onCheckedChange: (value: boolean) => void;
    reasonError?: string;
    disabled?: boolean;
    checkboxLabel?: string;
}

export function LifecycleGuardNotice({
    message,
    isSuperadmin,
    reason,
    onReasonChange,
    checked,
    onCheckedChange,
    reasonError,
    disabled = false,
    checkboxLabel = 'Saya superadmin dan yakin pelanggan ini boleh diproses meski pagar menahan.',
}: LifecycleGuardNoticeProps) {
    const reasonId = useId();

    return (
        <div className="space-y-2 rounded-md border border-status-down/30 bg-status-down-bg p-3 text-foreground" role="alert" data-testid="lifecycle-guard-notice">
            <p className="flex items-start gap-1.5 text-sm font-semibold">
                <ShieldAlert className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                <span>{message}</span>
            </p>
            {isSuperadmin ? (
                <>
                    <label htmlFor={reasonId} className="text-xs font-medium">
                        Alasan override (minimal {LIFECYCLE_OVERRIDE_REASON_MIN} karakter, tercatat di log aktivitas)
                    </label>
                    <Textarea
                        id={reasonId}
                        value={reason}
                        onChange={(event) => onReasonChange(event.target.value)}
                        rows={3}
                        disabled={disabled}
                    />
                    {reasonError && <p className="text-xs text-destructive">{reasonError}</p>}
                    <label className="flex items-start gap-2 text-xs">
                        <input
                            type="checkbox"
                            className="mt-0.5"
                            checked={checked}
                            onChange={(event) => onCheckedChange(event.target.checked)}
                            disabled={disabled}
                        />
                        <span>{checkboxLabel}</span>
                    </label>
                </>
            ) : (
                <p className="text-xs">Hanya superadmin yang dapat override dengan alasan tertulis.</p>
            )}
        </div>
    );
}
