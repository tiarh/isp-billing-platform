import { LucideIcon } from 'lucide-react';
import { ReactNode } from 'react';

type Props = {
    icon: LucideIcon;
    title: string;
    subtitle?: string;
    statusStrip?: ReactNode;
    children: ReactNode;
};

export function PublicPageShell({ icon: Icon, title, subtitle, statusStrip, children }: Props) {
    return (
        <div className="space-y-6">
            <div className="relative overflow-hidden rounded-xl border border-border/80 bg-card p-5 shadow-sm dark:border-border/60">
                <div className="relative flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div className="flex min-w-0 items-start gap-3">
                        <div className="rounded-lg bg-primary/10 p-2 text-primary dark:bg-primary/15">
                            <Icon className="h-5 w-5" />
                        </div>
                        <div>
                            <h1 className="text-xl font-semibold tracking-tight">{title}</h1>
                            {subtitle && <p className="text-sm text-muted-foreground">{subtitle}</p>}
                        </div>
                    </div>
                    {statusStrip}
                </div>
            </div>
            {children}
        </div>
    );
}
