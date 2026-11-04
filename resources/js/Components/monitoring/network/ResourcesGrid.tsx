import { EmptyState } from '@/Components/EmptyState';
import { cn } from '@/lib/utils';
import {
    DASH,
    LEVEL_BAR,
    LEVEL_LABEL,
    LEVEL_TEXT,
    THRESHOLDS,
    formatPct,
    formatTemp,
    formatUptime,
    formatVolt,
    levelFor,
} from './format';
import type { ResourceRow, ResourcesData, WidgetState } from './types';
import { WidgetFrame } from './WidgetFrame';

function Bar({ label, value, thresholds }: { label: string; value: number | null; thresholds: readonly [number, number] }) {
    const level = levelFor(value, thresholds);
    const width = value === null ? 0 : Math.max(0, Math.min(100, value));

    return (
        <div>
            <div className="flex items-baseline justify-between text-xs">
                <span className="text-muted-foreground">{label}</span>
                <span className={cn('font-data', level ? LEVEL_TEXT[level] : 'text-muted-foreground')}>
                    {formatPct(value, 0)}
                    {level && level !== 'ok' && <span className="sr-only"> ({LEVEL_LABEL[level]})</span>}
                </span>
            </div>
            <div className="mt-1.5 h-1.5 overflow-hidden rounded-full bg-muted" role="presentation">
                <div className={cn('h-full rounded-full', level ? LEVEL_BAR[level] : 'bg-muted')} style={{ width: `${width}%` }} />
            </div>
        </div>
    );
}

function Fact({ label, value, level }: { label: string; value: string; level?: 'ok' | 'warn' | 'crit' | null }) {
    return (
        <div>
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className={cn('font-data text-sm', level ? LEVEL_TEXT[level] : 'text-foreground')}>
                {value}
                {level && level !== 'ok' && <span className="sr-only"> ({LEVEL_LABEL[level]})</span>}
            </dd>
        </div>
    );
}

function ResourceCard({ row }: { row: ResourceRow }) {
    const hasAny = [row.cpu_pct, row.memory_pct, row.disk_pct, row.uptime_seconds, row.board_temp_c, row.cpu_temp_c, row.voltage_v].some((value) => value !== null);

    return (
        <li className="rounded-lg border border-border p-5">
            <h3 className="text-sm font-semibold">{row.name}</h3>
            {hasAny ? (
                <>
                    <div className="mt-4 space-y-3">
                        <Bar label="CPU" value={row.cpu_pct} thresholds={THRESHOLDS.cpu} />
                        <Bar label="Memori" value={row.memory_pct} thresholds={THRESHOLDS.memory} />
                        <Bar label="Disk" value={row.disk_pct} thresholds={THRESHOLDS.disk} />
                    </div>
                    <dl className="mt-4 grid grid-cols-2 gap-x-4 gap-y-3">
                        <Fact label="Suhu board" value={formatTemp(row.board_temp_c)} level={levelFor(row.board_temp_c, THRESHOLDS.temperature)} />
                        <Fact label="Suhu CPU" value={formatTemp(row.cpu_temp_c)} level={levelFor(row.cpu_temp_c, THRESHOLDS.temperature)} />
                        <Fact label="Tegangan" value={formatVolt(row.voltage_v)} />
                        <Fact label="Uptime" value={formatUptime(row.uptime_seconds)} />
                    </dl>
                </>
            ) : (
                <p className="mt-3 text-sm text-muted-foreground">{DASH} Belum ada data sumber daya untuk router ini.</p>
            )}
        </li>
    );
}

interface ResourcesGridProps {
    state: WidgetState<ResourcesData>;
    settingsHref?: string | null;
    suppressSetup?: boolean;
    compactUnavailable?: boolean;
}

export function ResourcesGrid({ state, settingsHref, suppressSetup, compactUnavailable }: ResourcesGridProps) {
    return (
        <WidgetFrame
            title="Sumber daya router"
            description="CPU, memori, disk, suhu, dan tegangan dari collector."
            state={state}
            settingsHref={settingsHref}
            suppressSetup={suppressSetup}
            compactUnavailable={compactUnavailable}
            skeletonClassName="h-64"
        >
            {(data) =>
                data.rows.length === 0 ? (
                    <EmptyState title="Belum ada data sumber daya" description="Collector belum mengirim metrik untuk router mana pun." />
                ) : (
                    <ul className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        {data.rows.map((row) => (
                            <ResourceCard key={row.router_id} row={row} />
                        ))}
                    </ul>
                )
            }
        </WidgetFrame>
    );
}
