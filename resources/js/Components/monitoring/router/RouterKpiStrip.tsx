import { ArrowDown, ArrowUp, Database, Network } from 'lucide-react';
import { StatCard } from '@/Components/StatCard';
import { formatCount, formatMbps } from '@/Components/monitoring/network/format';
import type { WidgetState } from '@/Components/monitoring/network/types';
import { WidgetFrame } from '@/Components/monitoring/network/WidgetFrame';
import { formatGB } from './format';
import type { RouterOverviewData } from './types';

interface RouterKpiStripProps {
    state: WidgetState<RouterOverviewData>;
}

export function RouterKpiStrip({ state }: RouterKpiStripProps) {
    return (
        <WidgetFrame title="Ringkasan router" state={state} renderOnDegraded bare skeletonClassName="h-[104px]">
            {(data) => {
                const { kpi } = data;
                const live = kpi.download_now_mbps !== null;
                const monthNote = kpi.month_has_invalid_days
                    ? 'Tidak termasuk hari dengan data tidak valid'
                    : kpi.month_history_partial
                      ? 'Beberapa hari datanya tidak lengkap'
                      : 'Total unduh + unggah bulan kalender (WIB)';

                return (
                    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <StatCard
                            title="PPPoE online"
                            value={`${formatCount(kpi.pppoe_online)} / ${formatCount(kpi.pppoe_secrets)}`}
                            description={kpi.pppoe_online_source === 'grafana' ? 'Online dari total secret · sumber Grafana' : 'Online dari total secret · sumber database'}
                            icon={Network}
                        />
                        <StatCard
                            title="Download sekarang"
                            value={formatMbps(kpi.download_now_mbps)}
                            description={live ? 'Jumlah laju semua pelanggan online' : 'Menunggu data Grafana'}
                            icon={ArrowDown}
                        />
                        <StatCard
                            title="Upload sekarang"
                            value={formatMbps(kpi.upload_now_mbps)}
                            description={live ? 'Jumlah laju semua pelanggan online' : 'Menunggu data Grafana'}
                            icon={ArrowUp}
                        />
                        <StatCard title="Pemakaian bulan ini" value={formatGB(kpi.month_gb)} description={monthNote} icon={Database} />
                    </div>
                );
            }}
        </WidgetFrame>
    );
}
