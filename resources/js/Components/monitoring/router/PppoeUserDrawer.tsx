import { Link } from '@inertiajs/react';
import { Info } from 'lucide-react';
import { EmptyState } from '@/Components/EmptyState';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/Components/ui/sheet';
import { WidgetFrame } from '@/Components/monitoring/network/WidgetFrame';
import { DailyBars, DailyLegend } from './DailyBars';
import { formatDay, formatGB } from './format';
import type { PppoeDailyData, PppoeRow } from './types';
import { useRouterApi } from './useRouterApi';

interface PppoeUserDrawerProps {
    routerId: number;
    row: PppoeRow | null;
    historyStart: string;
    onClose: () => void;
}

function DrawerBody({ routerId, row, historyStart }: { routerId: number; row: PppoeRow; historyStart: string }) {
    const state = useRouterApi<PppoeDailyData>(route('api.monitoring.routers.pppoe-daily', routerId), { user: row.pppoe_user });

    return (
        <WidgetFrame title="Pemakaian harian" state={state} bare skeletonClassName="h-64">
            {(data) => {
                if (!data.found) {
                    return (
                        <EmptyState
                            title="Belum ada riwayat"
                            description={`Pemakaian harian akun ini belum terekam. Perekaman dimulai ${formatDay(historyStart)}.`}
                        />
                    );
                }
                const invalid = data.days.filter((day) => day.quality === 'invalid').length;
                const partial = data.days.filter((day) => day.quality === 'partial').length;

                return (
                    <div className="space-y-5">
                        <dl className="grid grid-cols-3 gap-3 text-sm">
                            <div>
                                <dt className="text-xs text-muted-foreground">Download 30 hari</dt>
                                <dd className="font-data text-base font-semibold">{formatGB(data.totals.download_gb)}</dd>
                            </div>
                            <div>
                                <dt className="text-xs text-muted-foreground">Upload 30 hari</dt>
                                <dd className="font-data text-base font-semibold">{formatGB(data.totals.upload_gb)}</dd>
                            </div>
                            <div>
                                <dt className="text-xs text-muted-foreground">Total</dt>
                                <dd className="font-data text-base font-semibold">{formatGB(data.totals.download_gb + data.totals.upload_gb)}</dd>
                            </div>
                        </dl>

                        <div className="space-y-2">
                            <DailyLegend />
                            <DailyBars days={data.days} height={200} label={`Grafik pemakaian harian ${row.pppoe_user}, 30 hari`} />
                        </div>

                        <ul className="space-y-1 text-xs text-muted-foreground">
                            {invalid > 0 && (
                                <li className="flex gap-1.5">
                                    <Info className="mt-0.5 size-3.5 shrink-0" aria-hidden />
                                    <span>{invalid} hari tidak valid dan dikosongkan, tidak ikut dijumlahkan.</span>
                                </li>
                            )}
                            {partial > 0 && (
                                <li className="flex gap-1.5">
                                    <Info className="mt-0.5 size-3.5 shrink-0" aria-hidden />
                                    <span>{partial} hari terekam sebagian.</span>
                                </li>
                            )}
                            <li className="flex gap-1.5">
                                <Info className="mt-0.5 size-3.5 shrink-0" aria-hidden />
                                <span>GB desimal (1 GB = 1 miliar byte). Hari tanpa batang belum ada datanya.</span>
                            </li>
                        </ul>
                    </div>
                );
            }}
        </WidgetFrame>
    );
}

export function PppoeUserDrawer({ routerId, row, historyStart, onClose }: PppoeUserDrawerProps) {
    return (
        <Sheet open={row !== null} onOpenChange={(open) => !open && onClose()}>
            <SheetContent>
                {row && (
                    <>
                        <SheetHeader>
                            <SheetTitle>{row.name ?? row.pppoe_user}</SheetTitle>
                            <SheetDescription asChild>
                                <div className="space-y-1">
                                    <p className="font-data">{row.pppoe_user}</p>
                                    {row.customer_id !== null ? (
                                        <p>
                                            <Link href={route('customers.show', row.customer_id)} className="font-medium text-primary hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
                                                Buka detail pelanggan{row.customer_code ? ` ${row.customer_code}` : ''}
                                            </Link>
                                        </p>
                                    ) : (
                                        <p>Akun ini belum tertaut ke pelanggan.</p>
                                    )}
                                </div>
                            </SheetDescription>
                        </SheetHeader>
                        <div className="min-h-0 flex-1 overflow-y-auto">
                            <DrawerBody routerId={routerId} row={row} historyStart={historyStart} />
                        </div>
                    </>
                )}
            </SheetContent>
        </Sheet>
    );
}
