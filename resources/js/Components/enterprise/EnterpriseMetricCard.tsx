import { LucideIcon } from 'lucide-react';
import { Card, CardContent } from '@/Components/ui/card';

// Redesign A: KPI icons are uniformly primary-soft (status colour lives on
// values/badges), so the card has a single icon style.
const ICON_TILE = 'bg-primary-soft text-primary-soft-foreground';

type Props = {
    title: string;
    value: string;
    hint?: string;
    icon: LucideIcon;
    delta?: string | null;
};

export function EnterpriseMetricCard({ title, value, hint, icon: Icon, delta }: Props) {
    return (
        <Card className="border-border/80 shadow-sm dark:border-border/60">
            <CardContent className="p-4">
                <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                        <p className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">{title}</p>
                        <p className="mt-2 truncate text-2xl font-semibold tracking-tight tabular-nums">{value}</p>
                        {hint && <p className="mt-1 text-xs text-muted-foreground">{hint}</p>}
                        {delta && <p className="mt-1 text-[11px] font-medium text-muted-foreground">{delta}</p>}
                    </div>
                    <div className={`shrink-0 rounded-lg p-2.5 ${ICON_TILE}`}>
                        <Icon className="h-5 w-5" />
                    </div>
                </div>
            </CardContent>
        </Card>
    );
}
