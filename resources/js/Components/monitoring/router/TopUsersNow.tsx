import { Link } from '@inertiajs/react';
import { EmptyState } from '@/Components/EmptyState';
import { formatMbps } from '@/Components/monitoring/network/format';
import type { WidgetState } from '@/Components/monitoring/network/types';
import { WidgetFrame } from '@/Components/monitoring/network/WidgetFrame';
import type { RouterOverviewData } from './types';

interface TopUsersNowProps {
    state: WidgetState<RouterOverviewData>;
    compactUnavailable?: boolean;
    className?: string;
}

export function TopUsersNow({ state, compactUnavailable, className }: TopUsersNowProps) {
    return (
        <WidgetFrame
            title="10 pemakai terbesar"
            description="Laju download + upload saat ini."
            state={state}
            compactUnavailable={compactUnavailable}
            suppressSetup
            className={className}
            skeletonClassName="h-72"
        >
            {(data) => {
                if (data.top.length === 0) {
                    return <EmptyState title="Belum ada trafik" description="Tidak ada pelanggan dengan trafik saat ini." />;
                }
                const peak = Math.max(...data.top.map((user) => user.download_mbps + user.upload_mbps), 0.001);

                return (
                    <ol className="space-y-3">
                        {data.top.map((user, index) => {
                            const total = user.download_mbps + user.upload_mbps;
                            const label = user.name ?? user.pppoe_user;

                            return (
                                <li key={user.pppoe_user} className="space-y-1">
                                    <div className="flex items-baseline justify-between gap-3 text-sm">
                                        <span className="min-w-0 truncate">
                                            <span className="mr-2 font-data text-xs text-muted-foreground">{index + 1}</span>
                                            {user.customer_id !== null ? (
                                                <Link href={route('customers.show', user.customer_id)} className="font-medium hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
                                                    {label}
                                                </Link>
                                            ) : (
                                                <span className="font-medium">{label}</span>
                                            )}
                                            {user.name !== null && <span className="ml-2 font-data text-xs text-muted-foreground">{user.pppoe_user}</span>}
                                        </span>
                                        <span className="shrink-0 font-data text-xs">
                                            {formatMbps(user.download_mbps)} <span className="text-muted-foreground">↓</span> · {formatMbps(user.upload_mbps)} <span className="text-muted-foreground">↑</span>
                                        </span>
                                    </div>
                                    <div className="h-1.5 overflow-hidden rounded-full bg-muted" aria-hidden>
                                        <div className="h-full rounded-full" style={{ width: `${Math.max(2, (total / peak) * 100)}%`, backgroundColor: 'var(--chart-2)' }} />
                                    </div>
                                </li>
                            );
                        })}
                    </ol>
                );
            }}
        </WidgetFrame>
    );
}
