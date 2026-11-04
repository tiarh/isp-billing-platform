import { ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import { ChevronLeft, LucideIcon } from 'lucide-react';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { StaleDataBadge } from '@/Components/StaleDataBadge';

type Tab = {
    id: string;
    label: string;
    badge?: string | number | null;
    group?: string;
};

type Props = {
    icon: LucideIcon;
    title: string;
    subtitle?: string;
    backHref?: string;
    cachedAt?: string | null;
    cacheLabel?: string;
    freshMinutes?: number;
    actions?: ReactNode;
    statusStrip?: ReactNode;
    tabs?: Tab[];
    activeTab?: string;
    onTabChange?: (id: string) => void;
    children: ReactNode;
};

export function EnterprisePageShell({
    icon: Icon,
    title,
    subtitle,
    backHref,
    cachedAt,
    cacheLabel = 'Data',
    freshMinutes = 30,
    actions,
    statusStrip,
    tabs,
    activeTab,
    onTabChange,
    children,
}: Props) {
    const groupedTabs = tabs?.reduce<Array<{ label: string | null; tabs: Tab[] }>>((groups, tab) => {
        const label = tab.group ?? null;
        const last = groups[groups.length - 1];

        if (last && last.label === label) {
            last.tabs.push(tab);
        } else {
            groups.push({ label, tabs: [tab] });
        }

        return groups;
    }, []);

    const renderTab = (tab: Tab) => {
        const active = tab.id === activeTab;

        return (
            <button
                key={tab.id}
                type="button"
                disabled={active}
                onClick={() => {
                    if (!active) {
                        onTabChange?.(tab.id);
                    }
                }}
                className={`inline-flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium transition-colors ${
                    active
                        ? 'bg-primary/10 text-primary'
                        : 'text-muted-foreground hover:bg-muted/60 hover:text-foreground'
                }`}
            >
                {tab.label}
                {tab.badge !== undefined && tab.badge !== null && (
                    <Badge variant={active ? 'default' : 'secondary'} className="h-5 min-w-5 px-1.5 text-[10px]">
                        {tab.badge}
                    </Badge>
                )}
            </button>
        );
    };

    return (
        <div className="space-y-5">
            <div className="relative overflow-hidden rounded-xl border border-border/80 bg-card p-5 shadow-sm dark:border-border/60">
                <div className="relative flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div className="min-w-0 space-y-2">
                        <div className="flex items-center gap-2.5">
                            {backHref && (
                                <Button asChild variant="ghost" size="icon" className="h-9 w-9 shrink-0 rounded-full">
                                    <Link href={backHref} aria-label="Kembali">
                                        <ChevronLeft className="h-5 w-5" />
                                    </Link>
                                </Button>
                            )}
                            <div className="rounded-lg bg-primary/10 p-2 text-primary dark:bg-primary/15">
                                <Icon className="h-5 w-5" />
                            </div>
                            <div>
                                <h2 className="text-lg font-semibold tracking-tight">{title}</h2>
                                {subtitle && <p className="text-sm text-muted-foreground">{subtitle}</p>}
                            </div>
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            {cachedAt !== undefined && (
                                <StaleDataBadge timestamp={cachedAt} freshMinutes={freshMinutes} label={cacheLabel} />
                            )}
                        </div>
                    </div>
                    {actions && <div className="flex flex-wrap items-end gap-2">{actions}</div>}
                </div>
                {statusStrip && <div className="relative mt-4">{statusStrip}</div>}
            </div>

            {tabs && tabs.length > 0 && onTabChange && (
                tabs.some((tab) => tab.group) ? (
                    <div className="grid gap-3 border-b pb-3 sm:grid-cols-2 lg:grid-cols-3" aria-label="Kelompok pengaturan">
                        {groupedTabs?.map((group, index) => (
                            <div key={`${group.label ?? 'ungrouped'}-${index}`} className="space-y-1">
                                {group.label && (
                                    <p className="px-3 text-xs font-medium uppercase tracking-wide text-muted-foreground">
                                        {group.label}
                                    </p>
                                )}
                                <div className="flex flex-wrap gap-2">{group.tabs.map(renderTab)}</div>
                            </div>
                        ))}
                    </div>
                ) : (
                    <div className="flex flex-wrap gap-2 border-b pb-1">{tabs.map(renderTab)}</div>
                )
            )}

            {children}
        </div>
    );
}
