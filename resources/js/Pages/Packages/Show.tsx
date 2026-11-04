import { EnterpriseResourceLayout } from '@/Components/enterprise/EnterpriseResourceLayout';
import { Link, router } from '@inertiajs/react';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import PackageCustomersTable from './Partials/PackageCustomersTable';
import { ConfirmDestructive } from '@/Components/ConfirmDestructive';
import { Edit, Trash2, Package as PackageIcon, Users, DollarSign } from 'lucide-react';
import { useState } from 'react';
import { formatIdr } from '@/lib/format';

interface Package {
    id: number;
    name: string;
    price: number;
    router?: { id: number; name: string } | null;
    /** Semua pelanggan di paket ini (tanpa yang sudah dihapus), sama dengan isi tabel. */
    customers_count: number;
    /** Hanya pelanggan Aktif dan Isolir (yang ditagih). */
    billable_customers_count: number;
}

interface Props {
    package: Package;
}

export default function Show({ package: pkg }: Props) {
    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [deleteProcessing, setDeleteProcessing] = useState(false);

    const handleDelete = () => {
        setDeleteProcessing(true);
        router.delete(route('packages.destroy', pkg.id), {
            onSuccess: () => setDeleteDialogOpen(false),
            onFinish: () => setDeleteProcessing(false),
        });
    };

    return (
        <EnterpriseResourceLayout
            title={pkg.name}
            pageTitle={`Paket: ${pkg.name}`}
            subtitle={`${pkg.customers_count} pelanggan, ${pkg.billable_customers_count} ditagih`}
            icon={PackageIcon}
            backHref={route('packages.index')}
            breadcrumbs={[
                { label: 'Paket', href: route('packages.index') },
                { label: pkg.name },
            ]}
            actions={
                <div className="flex flex-wrap items-center gap-2">
                    <Link href={route('packages.edit', pkg.id)}>
                        <Button type="button" variant="outline" size="sm">
                            <Edit className="mr-2 h-4 w-4" />
                            Edit
                        </Button>
                    </Link>
                    <Button
                        type="button"
                        variant="destructive"
                        size="sm"
                        onClick={() => setDeleteDialogOpen(true)}
                        aria-label={`Hapus paket ${pkg.name}`}
                    >
                        <Trash2 className="mr-2 h-4 w-4" aria-hidden="true" />
                        Hapus
                    </Button>
                    <ConfirmDestructive
                        open={deleteDialogOpen}
                        onOpenChange={setDeleteDialogOpen}
                        title="Hapus paket?"
                        description={`Paket ${pkg.name} akan dihapus. Paket ini dipakai ${pkg.customers_count} pelanggan. Tindakan ini tidak bisa dibatalkan.`}
                        confirmationLabel="Saya paham paket ini dan langganannya akan dihapus."
                        confirmText="Hapus paket"
                        onConfirm={handleDelete}
                        isLoading={deleteProcessing}
                    />
                </div>
            }
        >
            <div className="mx-auto max-w-5xl space-y-6">
                {/* Ringkasan */}
                <div className="grid gap-6 md:grid-cols-3">
                    <Card className="border-border bg-card">
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                Harga per bulan
                            </CardTitle>
                            <DollarSign className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">{formatIdr(pkg.price)}</div>
                            <p className="text-xs text-muted-foreground mt-1">
                                Per pelanggan
                            </p>
                        </CardContent>
                    </Card>

                    <Card className="border-border bg-card">
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                Pelanggan ditagih
                            </CardTitle>
                            <Users className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">{pkg.billable_customers_count}</div>
                            <p className="text-xs text-muted-foreground mt-1">
                                Aktif dan Isolir, dari {pkg.customers_count} pelanggan
                            </p>
                        </CardContent>
                    </Card>

                    <Card className="border-border bg-card">
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                Pendapatan per bulan
                            </CardTitle>
                            <PackageIcon className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">
                                {formatIdr(pkg.price * pkg.billable_customers_count)}
                            </div>
                            <p className="text-xs text-muted-foreground mt-1">
                                Harga x pelanggan ditagih
                            </p>
                        </CardContent>
                    </Card>
                </div>

                {/* Detail paket */}
                <Card className="border-border bg-card">
                    <CardHeader>
                        <CardTitle>Detail Paket</CardTitle>
                        <CardDescription>
                            Data paket layanan
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="grid grid-cols-2 gap-6">
                            <div>
                                <p className="text-sm font-medium text-muted-foreground">Nama paket</p>
                                <p className="text-base font-semibold mt-1">{pkg.name}</p>
                            </div>
                            <div>
                                <p className="text-sm font-medium text-muted-foreground">Harga</p>
                                <p className="text-base font-semibold mt-1">{formatIdr(pkg.price)}</p>
                            </div>
                            <div>
                                <p className="text-sm font-medium text-muted-foreground">Router asal</p>
                                <p className="text-base font-semibold mt-1">{pkg.router?.name || 'Global / belum dipetakan'}</p>
                            </div>
                            <div>
                                <p className="text-sm font-medium text-muted-foreground">Jumlah pelanggan</p>
                                <p className="text-base font-semibold mt-1">{pkg.customers_count}</p>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Pelanggan dengan paket ini */}
                <Card className="border-border bg-card">
                    <CardHeader>
                        <CardTitle>Pelanggan dengan paket ini</CardTitle>
                        <CardDescription>
                            {pkg.customers_count} pelanggan memakai paket ini.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <PackageCustomersTable packageId={pkg.id} />
                    </CardContent>
                </Card>
            </div>
        </EnterpriseResourceLayout>
    );
}
