import type { ComponentType, HTMLAttributes } from 'react';
import { AlertTriangle, Check, CircleHelp, Info, X } from 'lucide-react';
import { cn } from '@/lib/utils';

export type StatusTone = 'up' | 'down' | 'degraded' | 'info' | 'neutral' | 'accent';

const toneStyles: Record<StatusTone, string> = {
    up: 'bg-status-up-bg text-status-up',
    down: 'bg-status-down-bg text-status-down',
    degraded: 'bg-status-degraded-bg text-status-degraded',
    info: 'bg-status-info-bg text-status-info',
    neutral: 'bg-muted text-muted-foreground',
    accent: 'bg-primary-soft text-primary-soft-foreground',
};

const toneIcons: Record<StatusTone, ComponentType<{ className?: string; 'aria-hidden'?: boolean }>> = {
    up: Check,
    down: X,
    degraded: AlertTriangle,
    info: Info,
    neutral: CircleHelp,
    accent: Info,
};

export interface StatusBadgeProps extends HTMLAttributes<HTMLSpanElement> {
    label: string;
    tone?: StatusTone;
    showIcon?: boolean;
}

export function StatusBadge({ label, tone = 'neutral', showIcon = true, className, ...props }: StatusBadgeProps) {
    const Icon = toneIcons[tone];

    return (
        <span
            {...props}
            className={cn(
                'inline-flex items-center h-[22px] max-w-full gap-1.5 whitespace-nowrap rounded-[var(--radius-pill)] border border-transparent px-2.5 text-xs font-semibold',
                toneStyles[tone],
                className,
            )}
        >
            {showIcon && <Icon className="size-3.5" aria-hidden />}
            {label}
        </span>
    );
}

export function StatusBadgeFromValue({ value, ...props }: Omit<StatusBadgeProps, 'label' | 'tone'> & { value: string }) {
    const normalized = value.trim().toLowerCase();
    const tone: StatusTone = ['online', 'active', 'healthy', 'success', 'paid', 'up'].includes(normalized)
        ? 'up'
        : ['offline', 'failed', 'error', 'critical', 'isolated', 'down'].includes(normalized)
            ? 'down'
            : ['pending', 'queued', 'degraded', 'warning', 'retry'].includes(normalized)
                ? 'degraded'
                : ['info', 'running', 'processing'].includes(normalized)
                    ? 'info'
                    : 'neutral';

    return <StatusBadge {...props} label={value} tone={tone} />;
}
