import { useState } from 'react';
import { EnterpriseIndexLayout } from '@/Components/enterprise/EnterpriseIndexLayout';
import { Link, router } from '@inertiajs/react';
import { Button } from '@/Components/ui/button';
import { Badge } from '@/Components/ui/badge';
import { StatusBadge } from '@/Components/StatusBadge';
import DataTable, { Column, PaginatedData } from '@/Components/DataTable';
import { ConfirmDialog } from '@/Components/ConfirmDialog';
import { MoneyText } from '@/Components/Format';
import { DeleteAction, EditAction } from '@/Components/TableActions';
import { Package, Plus } from 'lucide-react';

interface Package {
    id: number;
    name: string;
    price: number;
    mikrotik_profile?: string;
    rate_limit?: string;
    router?: {
        id: number;
        name: string;
    } | null;
    is_active?: boolean;
    customers_count: number;
}

interface Props {
    packages: PaginatedData<Package>;
    filters?: {
        search?: string;
        limit?: number;
        sort?: string;
        direction?: 'asc' | 'desc';
    };
}

export default function Index({ packages, filters = {} }: Props) {
    const [packageToDelete, setPackageToDelete] = useState<Package | null>(null);

    const columns: Column<Package>[] = [
        {
            header: 'Nama Paket',
            accessorKey: 'name',
            sortable: true,
            cell: (pkg) => (
                <div className="space-y-1">
                    <span className="font-medium">{pkg.name}</span>
                    <div className="flex flex-wrap gap-1">
                        {Number(pkg.price) <= 0 && <StatusBadge label="Perlu harga" tone="down" />}
                        {pkg.is_active === false && <StatusBadge label="Draft" tone="degraded" />}
                    </div>
                </div>
            ),
        },
        {
            header: 'Profil Teknis',
            accessorKey: 'mikrotik_profile',
            sortable: true,
            cell: (pkg) => <span className="font-mono text-xs text-muted-foreground">{pkg.mikrotik_profile || '-'}</span>,
        },
        {
            header: 'Batas Kecepatan',
            accessorKey: 'rate_limit',
            cell: (pkg) => <span className="font-mono text-xs text-muted-foreground">{pkg.rate_limit || '-'}</span>,
        },
        {
            header: 'Router Asal',
            accessorKey: 'router',
            sortable: false,
            cell: (pkg) => pkg.router?.name || <span className="text-muted-foreground">Global / belum dipetakan</span>,
        },
        {
            header: 'Harga',
            accessorKey: 'price',
            sortable: true,
            cell: (pkg) => <MoneyText amount={pkg.price} className="font-data font-medium text-foreground" />,
        },
        {
            header: 'Pelanggan Aktif',
            accessorKey: 'customers_count',
            sortable: true,
            cell: (pkg) => (
                <Badge variant="secondary">
                    {pkg.customers_count} pelanggan
                </Badge>
            ),
        },
        {
            header: 'Aksi',
            className: 'w-[100px] text-right',
            cell: (pkg) => (
                <div className="flex items-center justify-end gap-2">
                    <EditAction onClick={() => router.visit(route('packages.edit', pkg.id))} title="Edit Paket" />
                    <DeleteAction onClick={() => setPackageToDelete(pkg)} title="Hapus Paket" />
                </div>
            ),
        },
    ];

    return (
        <EnterpriseIndexLayout
            title="Paket Internet"
            pageTitle="Paket Internet"
            subtitle="Kelola paket layanan dan profil MikroTik"
            icon={Package}
            breadcrumbs={[{ label: 'Paket', href: route('packages.index') }]}
            actions={
                <Button asChild size="sm" className="h-9 gap-2">
                    <Link href={route('packages.create')}>
                        <Plus className="h-4 w-4" />
                        Tambah Paket
                    </Link>
                </Button>
            }
        >
            <DataTable
                compact
                data={packages}
                columns={columns}
                filters={filters}
                title=""
                description={`Menampilkan ${packages.data.length} dari ${packages.total} paket`}
                searchPlaceholder="Cari paket..."
                routeName="packages.index"
                onRowClick={(item) => router.visit(route('packages.show', item.id))}
            />

            <ConfirmDialog
                open={!!packageToDelete}
                onOpenChange={(open) => !open && setPackageToDelete(null)}
                title="Hapus Paket?"
                description={`Yakin hapus ${packageToDelete?.name}? Paket ini sedang dipakai oleh ${packageToDelete?.customers_count ?? 0} pelanggan. Tindakan ini tidak bisa dibatalkan.`}
                confirmText="Hapus"
                variant="destructive"
                onConfirm={() => packageToDelete && router.delete(route('packages.destroy', packageToDelete.id), {
                    onFinish: () => setPackageToDelete(null),
                })}
            />
        </EnterpriseIndexLayout>
    );
}
