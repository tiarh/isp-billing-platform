import type { LucideIcon } from 'lucide-react';
import { Card, CardContent } from '@/Components/ui/card';
import { cn } from '@/lib/utils';

interface StatCardProps {
    title: string;
    value: string;
    description?: string;
    icon?: LucideIcon;
    className?: string;
}

export function StatCard({ title, value, description, icon: Icon, className }: StatCardProps) {
    return (
        <Card className={cn('border-border', className)}>
            <CardContent className="flex items-start justify-between gap-4 p-4 sm:p-5">
                <div className="min-w-0">
                    <p className="text-xs font-medium text-muted-foreground">{title}</p>
                    <p className="mt-2 truncate font-data text-2xl font-semibold tracking-tight">{value}</p>
                    {description && <p className="mt-1 text-xs text-muted-foreground">{description}</p>}
                </div>
                {Icon && (
                    <div className="shrink-0 rounded-[var(--radius-control)] bg-primary-soft p-2 text-primary-soft-foreground">
                        <Icon className="size-5" aria-hidden />
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
