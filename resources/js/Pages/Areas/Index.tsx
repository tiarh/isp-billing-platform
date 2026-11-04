import { useState } from 'react';
import { EnterpriseIndexLayout } from '@/Components/enterprise/EnterpriseIndexLayout';
import { Link, router } from '@inertiajs/react';
import { Button } from '@/Components/ui/button';
import { Badge } from '@/Components/ui/badge';
import DataTable, { Column, PaginatedData } from '@/Components/DataTable';
import { ConfirmDialog } from '@/Components/ConfirmDialog';
import { DeleteAction, EditAction } from '@/Components/TableActions';
import { MapPin, Plus, RefreshCw } from 'lucide-react';
import { formatNumber } from '@/lib/format';

interface Area {
    id: number;
    name: string;
    code: string;
    customers_count?: number;
    olts_count?: number;
}

interface Props {
    areas: PaginatedData<Area>;
    filters: {
        search?: string;
        limit?: number;
        sort?: string;
        direction?: 'asc' | 'desc';
    };
}

export default function Index({ areas, filters = {} }: Props) {
    const [areaToDelete, setAreaToDelete] = useState<Area | null>(null);

    const columns: Column<Area>[] = [
        {
            header: 'Nama',
            accessorKey: 'name',
            sortable: true,
            cell: (area) => (
                <Link
                    href={route('areas.show', area.id)}
                    className="font-medium underline-offset-4 hover:underline"
                >
                    {area.name}
                </Link>
            ),
        },
        {
            header: 'Kode',
            accessorKey: 'code',
            sortable: true,
            cell: (area) => <span className="font-mono text-sm">{area.code}</span>,
        },
        {
            header: 'Pelanggan',
            accessorKey: 'customers_count',
            sortable: true,
            cell: (area) => (
                <span className="font-data text-sm">
                    {formatNumber(area.customers_count ?? 0, { maximumFractionDigits: 0 })} pelanggan
                </span>
            ),
        },
        {
            header: 'Perangkat (OLT)',
            accessorKey: 'olts_count',
            sortable: true,
            cell: (area) => (
                <Badge variant="secondary">
                    {formatNumber(area.olts_count ?? 0, { maximumFractionDigits: 0 })} perangkat
                </Badge>
            ),
        },
        {
            header: 'Aksi',
            className: 'w-[100px] text-right',
            cell: (area) => (
                <div className="flex items-center justify-end gap-2">
                    <EditAction onClick={() => router.visit(route('areas.edit', area.id))} title="Edit Area" />
                    <DeleteAction onClick={() => setAreaToDelete(area)} title="Hapus Area" />
                </div>
            ),
        },
    ];

    return (
        <EnterpriseIndexLayout
            title="Areas"
            pageTitle="Area"
            subtitle="Kelola wilayah operasi dan cakupan pelanggan"
            icon={MapPin}
            breadcrumbs={[{ label: 'Area', href: route('areas.index') }]}
            actions={
                <div className="flex gap-2">
                    <Button asChild size="sm" className="h-9 gap-2">
                        <Link href={route('areas.create')}><Plus className="h-4 w-4" />Tambah Area</Link>
                    </Button>
                    <Button asChild size="sm" variant="outline" className="h-9 gap-2">
                        <Link href={route('areas.migration')}><RefreshCw className="h-4 w-4" />Migrasi Area</Link>
                    </Button>
                </div>
            }
        >
            <DataTable
                compact
                data={areas}
                columns={columns}
                filters={filters}
                title=""
                description={`Menampilkan ${areas.data.length} dari ${areas.total} area`}
                searchPlaceholder="Cari area..."
                routeName="areas.index"
            />

            <ConfirmDialog
                open={!!areaToDelete}
                onOpenChange={(open) => !open && setAreaToDelete(null)}
                title="Hapus Area?"
                description={`Yakin hapus ${areaToDelete?.name}? Tindakan ini tidak bisa dibatalkan.`}
                confirmText="Hapus"
                variant="destructive"
                onConfirm={() => areaToDelete && router.delete(route('areas.destroy', areaToDelete.id), {
                    onFinish: () => setAreaToDelete(null),
                })}
            />
        </EnterpriseIndexLayout>
    );
}
