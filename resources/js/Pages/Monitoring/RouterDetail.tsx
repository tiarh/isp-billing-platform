import { useState } from 'react';
import { Head } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useStoredRange } from '@/Components/monitoring/network/RangeSelector';
import { useAutoRefresh } from '@/Components/monitoring/network/useAutoRefresh';
import { DailyUsageChart } from '@/Components/monitoring/router/DailyUsageChart';
import { PppoeTable } from '@/Components/monitoring/router/PppoeTable';
import { PppoeUserDrawer } from '@/Components/monitoring/router/PppoeUserDrawer';
import { RouterDetailHeader } from '@/Components/monitoring/router/RouterDetailHeader';
import { RouterKpiStrip } from '@/Components/monitoring/router/RouterKpiStrip';
import { TopUsersNow } from '@/Components/monitoring/router/TopUsersNow';
import type { PppoeRow, RouterDetailPageProps, RouterOverviewData } from '@/Components/monitoring/router/types';
import { UplinkChart } from '@/Components/monitoring/router/UplinkChart';
import { useRouterApi } from '@/Components/monitoring/router/useRouterApi';

export default function RouterDetail({ router, grafanaUrl, historyStart, krianInvalidThrough }: RouterDetailPageProps) {
    const [range, setRange] = useStoredRange();
    const { tick } = useAutoRefresh(60_000);
    const [selected, setSelected] = useState<PppoeRow | null>(null);

    const overview = useRouterApi<RouterOverviewData>(route('api.monitoring.routers.overview', router.id), { range }, tick, { keepOnChange: true });
    const updatedAt = overview.lastSuccessAt;
    const grafanaStatus = overview.envelope?.status ?? null;

    return (
        <AuthenticatedLayout
            breadcrumbs={[
                { label: 'Monitoring', href: route('monitoring.index', { tab: 'jaringan' }) },
                { label: router.name },
            ]}
        >
            <Head title={`Router ${router.name}`} />
            <div className="mx-auto w-full max-w-6xl space-y-10 px-4 py-6 sm:px-6">
                <RouterDetailHeader
                    router={router}
                    range={range}
                    onRangeChange={setRange}
                    updatedAt={updatedAt}
                    grafanaUrl={overview.envelope?.grafana_dashboard_url ?? grafanaUrl}
                    grafanaStatus={grafanaStatus}
                />

                <RouterKpiStrip state={overview} />

                <div className="grid gap-6 lg:grid-cols-3">
                    <UplinkChart state={overview} range={range} className="min-w-0 lg:col-span-2" />
                    <TopUsersNow state={overview} className="min-w-0" />
                </div>

                <DailyUsageChart state={overview} historyStart={historyStart} krianInvalidThrough={krianInvalidThrough} />

                <PppoeTable routerId={router.id} tick={tick} onSelect={setSelected} />

                <PppoeUserDrawer routerId={router.id} row={selected} historyStart={historyStart} onClose={() => setSelected(null)} />
            </div>
        </AuthenticatedLayout>
    );
}
