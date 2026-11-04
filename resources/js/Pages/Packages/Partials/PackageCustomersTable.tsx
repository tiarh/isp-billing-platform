import { useEffect, useState } from 'react';
import useSWR from 'swr';
import { Link } from '@inertiajs/react';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { StatusBadge } from '@/Components/StatusBadge';
import { Skeleton } from '@/Components/ui/skeleton';
import { Button } from '@/Components/ui/button';
import { PaginatedData } from '@/Components/DataTable';
import { DataTableToolbar } from '@/Components/DataTableToolbar';
import { DataTablePagination } from '@/Components/DataTablePagination';
import { CUSTOMER_STATUS_LABELS, customerStatusLabel, customerStatusTone } from '@/lib/customerLabels';

interface PackageCustomer {
    id: number;
    name: string;
    code: string;
    pppoe_user: string | null;
    status: string;
    is_online?: boolean;
    area?: { id: number; name: string } | null;
}

interface PackageCustomersTableProps {
    packageId: number;
}

const fetcher = (url: string) =>
    fetch(url, { headers: { Accept: 'application/json' } }).then((res) => {
        if (!res.ok) throw new Error('request failed');
        return res.json();
    });

const STATUS_OPTIONS = (['active', 'isolated', 'suspended', 'terminated', 'pending_installation'] as const).map((value) => ({
    value,
    label: CUSTOMER_STATUS_LABELS[value],
}));

export default function PackageCustomersTable({ packageId }: PackageCustomersTableProps) {
    const [page, setPage] = useState(1);
    const [perPage, setPerPage] = useState(20);
    const [search, setSearch] = useState('');
    const [debouncedSearch, setDebouncedSearch] = useState('');
    const [statusFilter, setStatusFilter] = useState('all');

    useEffect(() => {
        const timer = setTimeout(() => {
            setDebouncedSearch(search);
            setPage(1);
        }, 400);

        return () => clearTimeout(timer);
    }, [search]);

    const params = new URLSearchParams({
        page: String(page),
        limit: String(perPage),
        search: debouncedSearch,
        status: statusFilter === 'all' ? '' : statusFilter,
    });

    const { data, error, isLoading } = useSWR<PaginatedData<PackageCustomer>>(
        `${route('packages.customers', packageId)}?${params.toString()}`,
        fetcher,
        { keepPreviousData: true },
    );

    const reset = () => {
        setSearch('');
        setDebouncedSearch('');
        setStatusFilter('all');
        setPage(1);
    };

    return (
        <div className="space-y-4">
            <DataTableToolbar
                search={search}
                onSearchChange={setSearch}
                searchPlaceholder="Cari nama, kode, PPPoE..."
                activeFilters={{ status: statusFilter }}
                filterConfigs={[{ key: 'status', placeholder: 'Status', options: STATUS_OPTIONS }]}
                onFilterChange={(key, value) => {
                    if (key === 'status') {
                        setStatusFilter(value);
                        setPage(1);
                    }
                }}
                onReset={reset}
                actions={
                    <Link href={route('customers.index', { package_id: packageId })}>
                        <Button type="button" variant="outline" size="sm">
                            Lihat semua di halaman Pelanggan
                        </Button>
                    </Link>
                }
            />

            <div className="rounded-md border border-border overflow-hidden bg-card">
                <Table>
                    <TableHeader className="bg-muted/50">
                        <TableRow>
                            <TableHead>Kode</TableHead>
                            <TableHead>Nama</TableHead>
                            <TableHead>PPPoE</TableHead>
                            <TableHead>Area</TableHead>
                            <TableHead>Koneksi</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead className="text-right">Aksi</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {isLoading && !data ? (
                            Array.from({ length: 5 }).map((_, i) => (
                                <TableRow key={i}>
                                    {Array.from({ length: 7 }).map((__, j) => (
                                        <TableCell key={j}><Skeleton className="h-4 w-20" /></TableCell>
                                    ))}
                                </TableRow>
                            ))
                        ) : error ? (
                            <TableRow>
                                <TableCell colSpan={7} className="h-24 text-center text-status-down">
                                    Daftar pelanggan gagal dimuat. Coba muat ulang halaman.
                                </TableCell>
                            </TableRow>
                        ) : data?.data.length === 0 ? (
                            <TableRow>
                                <TableCell colSpan={7} className="h-24 text-center text-muted-foreground">
                                    Tidak ada pelanggan yang cocok.
                                </TableCell>
                            </TableRow>
                        ) : (
                            data?.data.map((customer) => (
                                <TableRow key={customer.id} className="hover:bg-muted/20 transition-colors">
                                    <TableCell className="h-11 whitespace-nowrap py-1.5 font-mono text-xs">{customer.code}</TableCell>
                                    <TableCell className="h-11 whitespace-nowrap py-1.5 font-medium">{customer.name}</TableCell>
                                    <TableCell className="h-11 whitespace-nowrap py-1.5 font-mono text-sm text-muted-foreground">{customer.pppoe_user || '-'}</TableCell>
                                    <TableCell className="h-11 whitespace-nowrap py-1.5">{customer.area?.name || '-'}</TableCell>
                                    <TableCell className="h-11 py-1.5">
                                        <StatusBadge label={customer.is_online ? 'Online' : 'Offline'} tone={customer.is_online ? 'up' : 'neutral'} />
                                    </TableCell>
                                    <TableCell className="py-1.5">
                                        <StatusBadge label={customerStatusLabel(customer.status)} tone={customerStatusTone(customer.status)} />
                                    </TableCell>
                                    <TableCell className="text-right py-1.5">
                                        <Link href={route('customers.show', customer.id)}>
                                            <Button type="button" variant="ghost" size="sm">
                                                Lihat
                                            </Button>
                                        </Link>
                                    </TableCell>
                                </TableRow>
                            ))
                        )}
                    </TableBody>
                </Table>
            </div>

            {data && (
                <DataTablePagination
                    data={data}
                    onPageChange={setPage}
                    onPageSizeChange={(size) => {
                        setPerPage(size);
                        setPage(1);
                    }}
                    pageSizeOptions={[10, 20, 50, 100]}
                />
            )}
        </div>
    );
}
