import { Activity, ArrowDownUp, Network, Router as RouterIcon } from 'lucide-react';
import { StatCard } from '@/Components/StatCard';
import { StatusBadge } from '@/Components/StatusBadge';
import { DASH, formatCount, formatMbps, formatPct } from './format';
import type { SummaryData, WidgetState } from './types';
import { WidgetFrame } from './WidgetFrame';

const PPPOE_SOURCE: Record<SummaryData['pppoe']['source'], string> = {
    grafana: 'Sumber: Grafana',
    mixed: 'Sumber: Grafana + database',
    db: 'Sumber: database (Grafana belum menjawab)',
};

interface NetworkKpiStripProps {
    state: WidgetState<SummaryData>;
}

export function NetworkKpiStrip({ state }: NetworkKpiStripProps) {
    return (
        <WidgetFrame title="Ringkasan jaringan" state={state} renderOnDegraded bare skeletonClassName="h-[104px]">
            {(data) => {
                const { routers, pppoe, traffic, upstream, collectors } = data;
                const unscraped = routers.scraped === null ? null : Math.max(0, routers.total - routers.scraped);
                const upstreamDescription = upstream
                    ? upstream.capacity_mbps !== null
                        ? `RX ${formatMbps(upstream.rx_mbps)} · TX ${formatMbps(upstream.tx_mbps)} dari ${formatMbps(upstream.capacity_mbps)}${upstream.capacity_complete ? '' : ' (kapasitas belum lengkap)'}`
                        : `RX ${formatMbps(upstream.rx_mbps)} · TX ${formatMbps(upstream.tx_mbps)}`
                    : 'Link upstream belum dikonfigurasi';

                return (
                    <div className="space-y-4">
                        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                            <StatCard
                                title="Router online"
                                value={routers.online === null ? `${DASH} / ${formatCount(routers.total)}` : `${formatCount(routers.online)} / ${formatCount(routers.total)}`}
                                description={unscraped === null ? 'Status scrape belum tersedia' : unscraped === 0 ? 'Semua router terbaca collector' : `${formatCount(unscraped)} router belum terbaca collector`}
                                icon={RouterIcon}
                            />
                            <StatCard title="PPPoE online" value={formatCount(pppoe.total)} description={PPPOE_SOURCE[pppoe.source]} icon={Network} />
                            <StatCard
                                title="Trafik router"
                                value={`↓ ${formatMbps(traffic.rx_mbps)}`}
                                description={`↑ ${formatMbps(traffic.tx_mbps)} · total uplink semua router`}
                                icon={ArrowDownUp}
                            />
                            <StatCard
                                title="Pemakaian upstream"
                                value={upstream?.utilization_pct !== null && upstream?.utilization_pct !== undefined ? formatPct(upstream.utilization_pct, 1) : formatMbps(upstream?.rx_mbps)}
                                description={upstreamDescription}
                                icon={Activity}
                            />
                        </div>
                        {collectors && collectors.length > 0 && (
                            <div className="flex flex-wrap items-center gap-2" aria-label="Status collector">
                                <span className="text-xs text-muted-foreground">Collector</span>
                                {collectors.map((collector) => (
                                    <StatusBadge key={collector.job} tone={collector.up ? 'up' : 'down'} label={`${collector.job} ${collector.up ? 'aktif' : 'mati'}`} />
                                ))}
                            </div>
                        )}
                    </div>
                );
            }}
        </WidgetFrame>
    );
}
