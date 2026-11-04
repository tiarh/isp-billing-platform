import { useEffect, type ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import { CloudOff, Settings2 } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { CpeHealthCard } from './CpeHealthCard';
import { NetworkKpiStrip } from './NetworkKpiStrip';
import { PingPanel } from './PingPanel';
import { ResourcesGrid } from './ResourcesGrid';
import { RouterTable } from './RouterTable';
import type {
    MonitoringRange,
    PingData,
    ResourcesData,
    RouterRow,
    RouterTrafficData,
    RoutersData,
    SummaryData,
    UpstreamData,
    WidgetState,
} from './types';
import { UpstreamPanel } from './UpstreamPanel';
import { useAutoRefresh } from './useAutoRefresh';
import { useCpeHealth } from './useCpeHealth';
import { useDebouncedValue, useMonitoringWidget } from './useMonitoringWidget';

export interface JaringanMeta {
    /** ISO time of the freshest successful widget response. */
    updatedAt: string | null;
    grafanaUrl: string | null;
}

interface JaringanTabProps {
    range: MonitoringRange;
    /** Monitoring settings route for global admins; null hides the CTA. */
    settingsHref: string | null;
    onMeta?: (meta: JaringanMeta) => void;
    /** Router-name slot, passed to RouterTable (e.g. a link to a router detail page). */
    renderRouterName?: (row: RouterRow) => ReactNode;
    /** Extension slot rendered below the resources grid (e.g. a CPE health card). */
    children?: ReactNode;
}

function latest(values: Array<string | null>): string | null {
    let best: string | null = null;
    for (const value of values) {
        if (value && (best === null || new Date(value).getTime() > new Date(best).getTime())) {
            best = value;
        }
    }

    return best;
}

function Banner({ icon: Icon, title, text, settingsHref }: { icon: typeof Settings2; title: string; text: string; settingsHref: string | null }) {
    return (
        <div role="status" className="flex flex-col gap-3 rounded-lg border border-border bg-card p-5 sm:flex-row sm:items-center sm:justify-between">
            <div className="flex items-start gap-3">
                <div className="rounded-[var(--radius-control)] bg-status-degraded-bg p-2 text-status-degraded">
                    <Icon className="size-5" aria-hidden />
                </div>
                <div>
                    <p className="text-sm font-semibold">{title}</p>
                    <p className="mt-1 max-w-2xl text-sm text-muted-foreground">{text}</p>
                </div>
            </div>
            {settingsHref && (
                <Button asChild size="sm" variant="outline" className="shrink-0">
                    <Link href={settingsHref}>Buka Pengaturan</Link>
                </Button>
            )}
        </div>
    );
}

export function JaringanTab({ range, settingsHref, onMeta, renderRouterName, children }: JaringanTabProps) {
    const effectiveRange = useDebouncedValue(range, 300);
    const { tick } = useAutoRefresh(60_000);

    const summary: WidgetState<SummaryData> = useMonitoringWidget<SummaryData>('summary', effectiveRange, tick);
    const upstream = useMonitoringWidget<UpstreamData>('upstream', effectiveRange, tick);
    const routers = useMonitoringWidget<RoutersData>('routers', effectiveRange, tick);
    const ping = useMonitoringWidget<PingData>('ping', effectiveRange, tick);
    const resources = useMonitoringWidget<ResourcesData>('resources', effectiveRange, tick);
    const traffic = useMonitoringWidget<RouterTrafficData>('router-traffic', effectiveRange, tick);
    const cpe = useCpeHealth(tick);

    const updatedAt = latest([summary, upstream, routers, ping, resources, traffic].map((state) => state.lastSuccessAt));
    const grafanaUrl = summary.envelope?.grafana_dashboard_url ?? routers.envelope?.grafana_dashboard_url ?? null;

    useEffect(() => {
        onMeta?.({ updatedAt, grafanaUrl });
    }, [onMeta, updatedAt, grafanaUrl]);

    const setupRequired = summary.envelope?.status === 'setup_required';
    const grafanaDown = summary.envelope?.status === 'unavailable';

    return (
        <div className="space-y-8">
            {setupRequired && (
                <Banner
                    icon={Settings2}
                    title="Koneksi Grafana belum dikonfigurasi"
                    text={summary.envelope?.reason ?? 'Isi alamat dan token Grafana di pengaturan Monitoring agar grafik dan metrik router terisi.'}
                    settingsHref={settingsHref}
                />
            )}
            {grafanaDown && (
                <Banner
                    icon={CloudOff}
                    title="Grafana tidak dapat dijangkau"
                    text={`${summary.envelope?.reason ?? 'Metrik langsung tidak tersedia.'} Nama router dan jumlah PPPoE tetap diambil dari database.`}
                    settingsHref={null}
                />
            )}

            <NetworkKpiStrip state={summary} />

            <UpstreamPanel state={upstream} range={effectiveRange} settingsHref={settingsHref} suppressSetup={setupRequired} compactUnavailable={grafanaDown} />

            <RouterTable routers={routers} traffic={traffic} ping={ping} renderRouterName={renderRouterName} />

            <PingPanel state={ping} range={effectiveRange} settingsHref={settingsHref} suppressSetup={setupRequired} compactUnavailable={grafanaDown} />

            <ResourcesGrid state={resources} settingsHref={settingsHref} suppressSetup={setupRequired} compactUnavailable={grafanaDown} />

            <CpeHealthCard state={cpe} />

            {children}
        </div>
    );
}
