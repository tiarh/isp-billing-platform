import { Link } from '@inertiajs/react';
import { EnterpriseIndexLayout } from '@/Components/enterprise/EnterpriseIndexLayout';
import { PageProps } from '@/types';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import DataTable, { type Column, type PaginatedData } from '@/Components/DataTable';
import { Badge } from '@/Components/ui/badge';
import { StatusBadge } from '@/Components/StatusBadge';
import { EmptyState } from '@/Components/EmptyState';
import { Button } from '@/Components/ui/button';
import { LayoutDashboard, Settings, Phone } from 'lucide-react';
import { MobileCard } from '@/Components/mobile/MobileCard';
import { formatGroupLabel } from '@/Components/WaGroups/types';
import { formatDateTime } from '@/lib/format';

interface Attendance {
    id: number;
    sender_name: string;
    sender_number: string;
    caption: string;
    photo_url: string;
    created_at: string;
}

export default function Index({
    attendances,
    attendanceGroupId,
    attendanceGroupLabel,
    canManageSettings = false,
}: PageProps<{
    attendances: PaginatedData<Attendance>;
    attendanceGroupId: string;
    attendanceGroupLabel: string;
    canManageSettings?: boolean;
}>) {
    const columns: Column<Attendance>[] = [
        { header: 'Waktu', cell: (att) => <span className="whitespace-nowrap tabular-nums">{formatDateTime(att.created_at)}</span> },
        {
            header: 'Karyawan',
            cell: (att) => (
                <div>
                    <div className="font-medium">{att.sender_name}</div>
                    <div className="text-sm text-muted-foreground">{att.sender_number}</div>
                </div>
            ),
        },
        { header: 'Caption', className: 'max-w-xs', cell: (att) => <span className="block truncate">{att.caption || '-'}</span> },
        {
            header: 'Lokasi Foto Drive',
            cell: (att) => (
                <Badge variant="outline" className="font-mono text-xs font-normal bg-muted/50">
                    {att.photo_url}
                </Badge>
            ),
        },
    ];

    // Read-only: the attendance group is chosen on Pengaturan > Grup WhatsApp (single source of truth).
    const activeFilterLabel = attendanceGroupId
        ? formatGroupLabel(attendanceGroupLabel || null, attendanceGroupId)
        : '';
    const subtitle = attendanceGroupId
        ? `Grup aktif: ${activeFilterLabel}`
        : 'Belum ada grup absensi — absensi diterima dari semua grup';

    return (
        <EnterpriseIndexLayout
            title="Rekap Absensi Harian"
            pageTitle="Absensi"
            subtitle={subtitle}
            icon={LayoutDashboard}
            breadcrumbs={[{ label: 'Absensi', href: route('attendances.index') }]}
            actions={canManageSettings ? (
                <Button asChild type="button" variant="outline" size="sm" className="h-9">
                    <Link href={route('settings.wa-groups')}>
                        <Settings className="mr-2 h-4 w-4" />
                        Ubah di Pengaturan › Grup WhatsApp
                    </Link>
                </Button>
            ) : undefined}
        >
            {attendances.total === 0 ? (
                <Card>
                    <CardHeader>
                        <CardTitle>Log Absensi WA Group</CardTitle>
                        <CardDescription>Otomatis disinkronisasi dari grup WA dan disimpan ke Nextcloud Drive.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <EmptyState
                            title="Belum ada data absensi"
                            description="Data akan muncul setelah pesan absensi diterima di grup WA."
                            className="min-h-24 border-0 py-4"
                        />
                    </CardContent>
                </Card>
            ) : (
                <div className="space-y-3">
                    {attendanceGroupId && (
                        <StatusBadge label={`Filter aktif: ${activeFilterLabel}`} tone="info" />
                    )}
                    {/* T1: DataTable = paging server (50/hal) + kartu HP; absensi ke-51 dst. kini terjangkau. */}
                    <DataTable
                        data={attendances}
                        columns={columns}
                        title="Log Absensi WA Group"
                        description="Otomatis disinkronisasi dari grup WA dan disimpan ke Nextcloud Drive."
                        routeName="attendances.index"
                        hideToolbar
                        pageSizeOptions={[25, 50, 100, 250, 500]}
                        density="compact"
                        mobileCard={(att) => (
                            <MobileCard
                                code={<span className="font-data">{formatDateTime(att.created_at)}</span>}
                                title={att.sender_name}
                                meta={[{ icon: Phone, text: att.sender_number }]}
                            >
                                {att.caption && <span className="mt-2 line-clamp-2 block text-sm">{att.caption}</span>}
                                <span className="mt-2 block truncate font-mono text-[11px] text-muted-foreground" title={att.photo_url}>{att.photo_url}</span>
                            </MobileCard>
                        )}
                    />
                </div>
            )}
        </EnterpriseIndexLayout>
    );
}
