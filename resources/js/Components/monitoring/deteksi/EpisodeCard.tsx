import { StatusBadge } from '@/Components/StatusBadge';
import { Button } from '@/Components/ui/button';
import { formatDateTime } from '@/lib/format';
import { ChevronRight } from 'lucide-react';
import type { ReactNode } from 'react';
import CustomerSummary from './CustomerSummary';
import { ageText, causeLine, type Episode, locationText, STATE_TONE } from './types';

/** One episode on a small screen: type + status, customers, place and age. Tapping the card opens the detail sheet. */
export default function EpisodeCard({ row, action, onOpen }: { row: Episode; action: ReactNode; onOpen: () => void }) {
    const cause = causeLine(row);
    const age = ageText(row);

    return (
        <div
            className={'space-y-2 rounded-lg border bg-card p-3 shadow-sm' + (row.pinned_age_days != null ? ' border-l-4 border-l-status-degraded' : '')}
            data-testid="deteksi-card"
            data-episode-id={row.id}
        >
            <div className="flex items-start justify-between gap-2">
                <div className="min-w-0 font-medium">{row.type_label}</div>
                <StatusBadge label={row.state_label} tone={STATE_TONE[row.state] ?? 'neutral'} />
            </div>
            <CustomerSummary row={row} />
            <div className="text-xs text-muted-foreground">{locationText(row)}</div>
            {row.detail && <div className="text-xs text-muted-foreground">{row.detail}</div>}
            {cause && (
                <div className="text-xs">
                    <span className="text-muted-foreground">{cause.prefix}:</span> <span className="font-medium">{cause.text}</span>
                </div>
            )}
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="space-y-0.5 text-xs" title={row.opened_at ? `Mulai ${formatDateTime(row.opened_at)}` : undefined}>
                    {row.pinned_age_days != null && <div className="font-medium text-foreground" data-testid="pinned-age">Terbuka {row.pinned_age_days} hari</div>}
                    {age && <div className="text-muted-foreground">{age}</div>}
                    {row.suppressed_label && <div className="text-muted-foreground">Ditahan: {row.suppressed_label}</div>}
                </div>
                <div className="flex items-center gap-2" onClick={(e) => e.stopPropagation()}>
                    {action}
                    <Button type="button" variant="ghost" size="sm" className="h-7 px-2 text-xs" aria-label={`Buka detail kejadian ${row.id}`} onClick={onOpen}>
                        Detail <ChevronRight className="ml-0.5 h-3.5 w-3.5" aria-hidden />
                    </Button>
                </div>
            </div>
        </div>
    );
}
