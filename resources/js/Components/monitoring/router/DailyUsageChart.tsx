import { Info } from 'lucide-react';
import { EmptyState } from '@/Components/EmptyState';
import type { WidgetState } from '@/Components/monitoring/network/types';
import { WidgetFrame } from '@/Components/monitoring/network/WidgetFrame';
import { DailyBars, DailyLegend } from './DailyBars';
import { formatDay, formatGB } from './format';
import type { RouterOverviewData } from './types';

interface DailyUsageChartProps {
    state: WidgetState<RouterOverviewData>;
    historyStart: string;
    krianInvalidThrough: string | null;
}

export function DailyUsageChart({ state, historyStart, krianInvalidThrough }: DailyUsageChartProps) {
    return (
        <WidgetFrame
            title="Pemakaian harian (30 hari)"
            description="Total unduh dan unggah semua pelanggan router ini per hari WIB, dalam GB (1 GB = 1 miliar byte)."
            state={state}
            renderOnDegraded
            suppressSetup
            skeletonClassName="h-64"
        >
            {(data) => {
                const withData = data.daily.filter((day) => day.download_gb !== null);
                const invalid = data.daily.filter((day) => day.quality === 'invalid');
                const partial = data.daily.filter((day) => day.quality === 'partial');
                if (withData.length === 0 && invalid.length === 0) {
                    return (
                        <EmptyState
                            title="Riwayat belum tersedia"
                            description={`Pemakaian harian per pelanggan mulai direkam ${formatDay(historyStart)}. Data akan terisi seiring berjalannya hari.`}
                        />
                    );
                }
                const total = withData.reduce((sum, day) => sum + (day.download_gb ?? 0) + (day.upload_gb ?? 0), 0);

                return (
                    <div className="space-y-4">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <DailyLegend />
                            <p className="text-xs text-muted-foreground">
                                Total {withData.length} hari terekam: <span className="font-data text-foreground">{formatGB(total)}</span>
                            </p>
                        </div>
                        <DailyBars days={data.daily} label="Grafik pemakaian harian 30 hari, download dan upload dalam GB" />
                        <ul className="space-y-1 text-xs text-muted-foreground">
                            {invalid.length > 0 && (
                                <li className="flex gap-1.5">
                                    <Info className="mt-0.5 size-3.5 shrink-0" aria-hidden />
                                    <span>
                                        {invalid.length} hari tidak valid dan dikosongkan
                                        {krianInvalidThrough ? ` (data router ini sebelum ${formatDay(nextDay(krianInvalidThrough))} tidak valid)` : ''}.
                                    </span>
                                </li>
                            )}
                            {partial.length > 0 && (
                                <li className="flex gap-1.5">
                                    <Info className="mt-0.5 size-3.5 shrink-0" aria-hidden />
                                    <span>{partial.length} hari terekam sebagian (rekaman dimulai {formatDay(historyStart)}).</span>
                                </li>
                            )}
                            <li className="flex gap-1.5">
                                <Info className="mt-0.5 size-3.5 shrink-0" aria-hidden />
                                <span>Hari tanpa batang berarti belum ada data, bukan nol pemakaian.</span>
                            </li>
                        </ul>
                    </div>
                );
            }}
        </WidgetFrame>
    );
}

function nextDay(date: string): string {
    const parsed = new Date(`${date}T00:00:00Z`);
    parsed.setUTCDate(parsed.getUTCDate() + 1);

    // Pure calendar-date arithmetic on a UTC-anchored date; not a clock time.
    // eslint-disable-next-line no-restricted-syntax
    return parsed.toISOString().slice(0, 10);
}
