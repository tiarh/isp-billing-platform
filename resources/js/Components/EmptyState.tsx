import type { ComponentType, ReactNode } from 'react';
import { Inbox } from 'lucide-react';
import { cn } from '@/lib/utils';

interface EmptyStateProps {
    title: string;
    description?: string;
    icon?: ComponentType<{ className?: string; 'aria-hidden'?: boolean }>;
    action?: ReactNode;
    className?: string;
}

export function EmptyState({ title, description, icon: Icon = Inbox, action, className }: EmptyStateProps) {
    return (
        <div className={cn('flex min-h-48 flex-col items-center justify-center rounded-lg border border-dashed border-border px-6 py-10 text-center', className)}>
            <div className="mb-3 flex size-12 items-center justify-center rounded-full bg-primary-soft text-primary-soft-foreground">
                <Icon className="size-6" aria-hidden />
            </div>
            <h3 className="text-sm font-semibold text-foreground">{title}</h3>
            {description && <p className="mt-1 max-w-md text-sm text-muted-foreground">{description}</p>}
            {action && <div className="mt-4">{action}</div>}
        </div>
    );
}
