import { EnterpriseIndexLayout } from '@/Components/enterprise/EnterpriseIndexLayout';
import { Link, router } from '@inertiajs/react';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { ArrowLeft, BarChart3 } from 'lucide-react';

interface Reason { value: string; label: string; }
interface Row { month: string; counts: Record<string, number>; total: number; }
interface Props {
    report: { reasons: Reason[]; rows: Row[]; totals: Record<string, number>; total: number };
    months: number;
}

const MONTH_NAMES = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

function monthLabel(month: string): string {
    const [year, mm] = month.split('-');
    return `${MONTH_NAMES[Number(mm) - 1] || mm} ${year}`;
}

export default function DismantleReasons({ report, months }: Props) {
    const changeMonths = (value: number) => router.get(route('tickets.dismantle-reasons'), { months: value }, { preserveScroll: true });

    return (
        <EnterpriseIndexLayout
            title="Alasan Dismantle"
            pageTitle="Alasan Dismantle"
            subtitle={`Jumlah tiket Dismantle per alasan per bulan (WIB), ${months} bulan terakhir`}
            icon={BarChart3}
            breadcrumbs={[{ label: 'Ticketing', href: route('tickets.index') }, { label: 'Alasan Dismantle' }]}
            actions={
                <div className="flex flex-wrap gap-2">
                    {[3, 6, 12, 24].map((value) => (
                        <Button key={value} type="button" size="sm" variant={value === months ? 'default' : 'outline'} onClick={() => changeMonths(value)}>{value} bln</Button>
                    ))}
                    <Button asChild type="button" variant="outline" size="sm"><Link href={route('device-recovery.index')}><ArrowLeft className="mr-2 size-4" aria-hidden />Pengambilan Perangkat</Link></Button>
                </div>
            }
        >
            <Card>
                <CardHeader><CardTitle className="text-base">Total {report.total} tiket dismantle</CardTitle></CardHeader>
                <CardContent className="overflow-x-auto">
                    {report.rows.length === 0 ? (
                        <p className="text-sm text-muted-foreground">Belum ada tiket dismantle pada periode ini.</p>
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Bulan</TableHead>
                                    {report.reasons.map((reason) => <TableHead key={reason.value} className="text-right">{reason.label}</TableHead>)}
                                    <TableHead className="text-right">Total</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {report.rows.map((row) => (
                                    <TableRow key={row.month}>
                                        <TableCell className="font-medium whitespace-nowrap">{monthLabel(row.month)}</TableCell>
                                        {report.reasons.map((reason) => <TableCell key={reason.value} className="text-right tabular-nums">{row.counts[reason.value] || 0}</TableCell>)}
                                        <TableCell className="text-right font-semibold tabular-nums">{row.total}</TableCell>
                                    </TableRow>
                                ))}
                                <TableRow>
                                    <TableCell className="font-semibold">Total</TableCell>
                                    {report.reasons.map((reason) => <TableCell key={reason.value} className="text-right font-semibold tabular-nums">{report.totals[reason.value] || 0}</TableCell>)}
                                    <TableCell className="text-right font-semibold tabular-nums">{report.total}</TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                    )}
                </CardContent>
            </Card>
        </EnterpriseIndexLayout>
    );
}
