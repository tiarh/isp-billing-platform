import { Link } from '@inertiajs/react';
import { ChevronLeft, ExternalLink } from 'lucide-react';
import { StatusBadge } from '@/Components/StatusBadge';
import { Button } from '@/Components/ui/button';
import { formatClock } from '@/Components/monitoring/network/format';
import { RangeSelector } from '@/Components/monitoring/network/RangeSelector';
import type { MonitoringRange } from '@/Components/monitoring/network/types';
import type { RouterInfo } from './types';

interface RouterDetailHeaderProps {
    router: RouterInfo;
    range: MonitoringRange;
    onRangeChange: (range: MonitoringRange) => void;
    updatedAt: string | null;
    grafanaUrl: string | null;
    /** 'ok' | 'stale' | 'unavailable' | 'setup_required' | null while loading. */
    grafanaStatus: string | null;
}

export function RouterDetailHeader({ router, range, onRangeChange, updatedAt, grafanaUrl, grafanaStatus }: RouterDetailHeaderProps) {
    return (
        <header className="space-y-4">
            <Link
                href={route('monitoring.index', { tab: 'jaringan' })}
                className="inline-flex items-center gap-1 rounded-sm text-sm text-muted-foreground hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            >
                <ChevronLeft className="size-4" aria-hidden />
                Monitoring &rsaquo; Jaringan
            </Link>

            <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div className="min-w-0 space-y-2">
                    <div className="flex flex-wrap items-center gap-3">
                        <h1 className="break-words text-xl font-semibold tracking-tight text-foreground sm:text-2xl">{router.name}</h1>
                        {!router.is_active && <StatusBadge tone="neutral" label="Nonaktif" />}
                        {router.is_active && grafanaStatus === 'unavailable' && <StatusBadge tone="degraded" label="Grafana tidak terjangkau" />}
                        {router.is_active && (grafanaStatus === 'ok' || grafanaStatus === 'stale') && (
                            <StatusBadge tone={grafanaStatus === 'ok' ? 'up' : 'degraded'} label={grafanaStatus === 'ok' ? 'Data langsung' : 'Data tertunda'} />
                        )}
                    </div>
                    <p className="text-[13px] text-muted-foreground">
                        Pelanggan PPPoE, trafik uplink, dan pemakaian harian router ini.{updatedAt ? ` Diperbarui ${formatClock(updatedAt)} WIB.` : ''}
                    </p>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <RangeSelector value={range} onChange={onRangeChange} />
                    {grafanaUrl && (
                        <Button asChild variant="outline" size="sm">
                            <a href={grafanaUrl} target="_blank" rel="noopener noreferrer">
                                <ExternalLink className="size-4" aria-hidden />
                                Buka di Grafana
                            </a>
                        </Button>
                    )}
                </div>
            </div>
        </header>
    );
}
