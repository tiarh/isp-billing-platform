import { EmptyState } from '@/Components/EmptyState';
import { StatusBadge, type StatusTone } from '@/Components/StatusBadge';
import { formatRelative } from '@/lib/format';
import { cn } from '@/lib/utils';
import { DASH, formatCount, formatPct } from './format';
import type { CpeHealthData, WidgetState } from './types';
import { WidgetFrame } from './WidgetFrame';

interface Bucket {
    key: 'good' | 'fair' | 'poor';
    label: string;
    range: string;
    tone: StatusTone;
    bar: string;
}

const BUCKETS: Bucket[] = [
    { key: 'good', label: 'Baik', range: '≥ -25 dBm', tone: 'up', bar: 'bg-status-up' },
    { key: 'fair', label: 'Sedang', range: '-25 s/d -27 dBm', tone: 'degraded', bar: 'bg-status-degraded' },
    { key: 'poor', label: 'Lemah', range: '≤ -27 dBm', tone: 'down', bar: 'bg-status-down' },
];

function onlineTone(pct: number | null): StatusTone {
    if (pct === null) {
        return 'neutral';
    }

    return pct >= 90 ? 'up' : pct >= 75 ? 'degraded' : 'down';
}

function Online({ data }: { data: CpeHealthData }) {
    const { devices } = data;
    const pct = devices.online_pct;
    const tone = onlineTone(pct);
    const barClass = tone === 'up' ? 'bg-status-up' : tone === 'degraded' ? 'bg-status-degraded' : tone === 'down' ? 'bg-status-down' : 'bg-muted';

    return (
        <div>
            <h3 className="text-xs font-medium text-muted-foreground">CPE online</h3>
            <p className="mt-2 flex items-baseline gap-2">
                <span className="font-data text-2xl font-semibold">{formatPct(pct, 1)}</span>
                <StatusBadge tone={tone} label={tone === 'up' ? 'Sehat' : tone === 'degraded' ? 'Perlu dicek' : tone === 'down' ? 'Banyak offline' : 'Belum ada data'} />
            </p>
            <div className="mt-3 h-1.5 overflow-hidden rounded-full bg-muted" role="presentation">
                <div className={cn('h-full rounded-full', barClass)} style={{ width: `${Math.max(0, Math.min(100, pct ?? 0))}%` }} />
            </div>
            <p className="mt-2 text-xs text-muted-foreground">
                {formatCount(devices.online)} dari {formatCount(devices.total)} perangkat ({formatCount(devices.offline)} offline)
            </p>
            <p className="mt-1 text-xs text-muted-foreground">
                Inform terakhir: {devices.last_inform_at ? formatRelative(devices.last_inform_at) : DASH}
            </p>
        </div>
    );
}

function RxPower({ data }: { data: CpeHealthData }) {
    const { rx_power: rx } = data;

    return (
        <div>
            <h3 className="text-xs font-medium text-muted-foreground">Daya optik ONU (RX)</h3>
            {rx.measured === 0 ? (
                <p className="mt-3 text-sm text-muted-foreground">{DASH} Belum ada pembacaan RX dari pelanggan online.</p>
            ) : (
                <>
                    <div className="mt-3 flex h-1.5 overflow-hidden rounded-full bg-muted" role="presentation">
                        {BUCKETS.map((bucket) => (
                            <div key={bucket.key} className={bucket.bar} style={{ width: `${(rx[bucket.key] / rx.measured) * 100}%` }} />
                        ))}
                    </div>
                    <ul className="mt-3 space-y-2">
                        {BUCKETS.map((bucket) => (
                            <li key={bucket.key} className="flex items-center justify-between gap-3 text-sm">
                                <span className="flex min-w-0 items-center gap-2">
                                    <StatusBadge tone={bucket.tone} label={bucket.label} />
                                    <span className="truncate text-xs text-muted-foreground">{bucket.range}</span>
                                </span>
                                <span className="font-data">{formatCount(rx[bucket.key])}</span>
                            </li>
                        ))}
                    </ul>
                    <p className="mt-2 text-xs text-muted-foreground">{formatCount(rx.measured)} pelanggan online terukur</p>
                </>
            )}
        </div>
    );
}

function TopAreas({ data }: { data: CpeHealthData }) {
    return (
        <div>
            <h3 className="text-xs font-medium text-muted-foreground">Area dengan CPE offline terbanyak</h3>
            {data.top_offline_areas.length === 0 ? (
                <p className="mt-3 text-sm text-muted-foreground">{DASH} Tidak ada CPE offline pada area mana pun.</p>
            ) : (
                <ol className="mt-3 space-y-2">
                    {data.top_offline_areas.map((area, index) => (
                        <li key={area.area} className="flex items-center justify-between gap-3 text-sm">
                            <span className="flex min-w-0 items-center gap-2">
                                <span className="w-4 shrink-0 text-xs text-muted-foreground">{index + 1}</span>
                                <span className="truncate">{area.area}</span>
                            </span>
                            <span className="shrink-0 font-data text-status-down">
                                {formatCount(area.offline)}
                                <span className="text-xs text-muted-foreground"> / {formatCount(area.total)}</span>
                            </span>
                        </li>
                    ))}
                </ol>
            )}
        </div>
    );
}

export function CpeHealthCard({ state }: { state: WidgetState<CpeHealthData> }) {
    return (
        <WidgetFrame title="Kesehatan CPE" description="Status inform GenieACS dan daya optik ONU. Hanya agregat, tanpa data pelanggan." state={state} skeletonClassName="h-48" forbiddenText="Akses ditolak untuk akun ini.">
            {(data) =>
                data.devices.total === 0 ? (
                    <EmptyState title="Belum ada data CPE" description="Belum ada perangkat yang tersinkron dari GenieACS." />
                ) : (
                    <div className="grid gap-6 md:grid-cols-3">
                        <Online data={data} />
                        <RxPower data={data} />
                        <TopAreas data={data} />
                    </div>
                )
            }
        </WidgetFrame>
    );
}
