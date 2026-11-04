import { Badge } from '@/Components/ui/badge';

type Props = {
    timestamp?: string | null;
    freshMinutes?: number;
    label?: string;
};

function ageMinutes(timestamp?: string | null): number | null {
    if (!timestamp) return null;
    const date = new Date(timestamp);
    if (Number.isNaN(date.getTime())) return null;

    return Math.max(0, Math.round((Date.now() - date.getTime()) / 60000));
}

function formatAge(minutes: number): string {
    if (minutes < 1) return 'baru saja';
    if (minutes < 60) return `${minutes} menit lalu`;
    const hours = Math.floor(minutes / 60);
    if (hours < 48) return `${hours} jam lalu`;

    return `${Math.floor(hours / 24)} hari lalu`;
}

export function StaleDataBadge({ timestamp, freshMinutes = 30, label = 'Snapshot' }: Props) {
    const age = ageMinutes(timestamp);

    if (age === null) {
        return (
            <Badge variant="outline" className="border-border bg-muted text-muted-foreground">
                {label}: belum ada
            </Badge>
        );
    }

    const stale = age > freshMinutes;

    return (
        <Badge
            variant="outline"
            className={stale
                ? 'border-status-degraded/30 bg-status-degraded-bg text-status-degraded'
                : 'border-status-up/30 bg-status-up-bg text-status-up'}
        >
            {label}: {formatAge(age)}{stale ? ' · stale' : ' · live'}
        </Badge>
    );
}