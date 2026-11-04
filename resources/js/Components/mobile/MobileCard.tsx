import type { ComponentType, ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import { cn } from '@/lib/utils';

export interface MobileCardMeta {
    icon: ComponentType<{ className?: string; 'aria-hidden'?: boolean }>;
    text: ReactNode;
    className?: string;
}

interface MobileCardProps {
    href?: string;
    onSelect?: () => void;
    title: ReactNode;
    code?: ReactNode;
    badge?: ReactNode;
    meta?: MobileCardMeta[];
    /** Extra controls (links/buttons). When present the card uses a stretched link so controls stay valid HTML. */
    actions?: ReactNode;
    children?: ReactNode;
}

const cardClass = 'relative block w-full rounded-lg border bg-card p-4 text-left shadow-[var(--shadow-card)] transition-colors active:bg-muted/40 focus-visible:ring-2 focus-visible:ring-ring';

export function MobileCard({ href, onSelect, title, code, badge, meta, actions, children }: MobileCardProps) {
    const body = (
        <>
            <span className="flex items-start justify-between gap-3">
                <span className="min-w-0">
                    {code && <span className="block truncate font-mono text-xs text-muted-foreground">{code}</span>}
                    <span className="block text-[15px] font-semibold leading-snug">{title}</span>
                </span>
                {badge && <span className="shrink-0">{badge}</span>}
            </span>
            {meta && meta.length > 0 && (
                <span className="mt-2 flex flex-col gap-1 text-xs text-muted-foreground">
                    {meta.map((item, index) => (
                        <span key={index} className={cn('flex min-w-0 items-center gap-1.5', item.className)}>
                            <item.icon className="size-3.5 shrink-0" aria-hidden />
                            <span className="min-w-0 truncate">{item.text}</span>
                        </span>
                    ))}
                </span>
            )}
            {children}
        </>
    );

    if (!actions) {
        if (href) {
            return <Link href={href} className={cardClass}>{body}</Link>;
        }
        if (onSelect) {
            return <button type="button" onClick={onSelect} className={cardClass}>{body}</button>;
        }
        // Read-only card (no destination): plain block, not a dead button.
        return <div className={cardClass}>{body}</div>;
    }

    const stretch = 'after:absolute after:inset-0 after:content-[""] after:rounded-lg focus-visible:outline-none focus-visible:after:ring-2 focus-visible:after:ring-ring focus-visible:after:ring-offset-2';
    return (
        <div className={cardClass}>
            {href ? (
                <Link href={href} className={cn('block text-left', stretch)}>{body}</Link>
            ) : (
                <button type="button" onClick={onSelect} className={cn('block w-full text-left', stretch)}>{body}</button>
            )}
            <div className="relative z-10 mt-3 flex justify-end gap-2">{actions}</div>
        </div>
    );
}
