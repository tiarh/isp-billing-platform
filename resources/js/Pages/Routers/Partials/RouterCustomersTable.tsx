import { useState } from 'react';
import useSWR from 'swr';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { StatusBadge } from '@/Components/StatusBadge';
import { customerStatusLabel, customerStatusTone } from '@/lib/customerLabels';
import { Skeleton } from '@/Components/ui/skeleton';
import { router } from '@inertiajs/react';
import { MoreHorizontal, Eye, Edit } from 'lucide-react';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from "@/Components/ui/dropdown-menu";
import { Button } from '@/Components/ui/button';
import { PaginatedData } from '@/Components/DataTable';
import { DataTableToolbar } from '@/Components/DataTableToolbar';
import { DataTablePagination } from '@/Components/DataTablePagination';

interface Customer {
    id: number;
    name: string;
    code: string;
    pppoe_user: string;
    status: string;
    /** From DB / Plan C T1 session sync — source of truth on large pools */
    is_online?: boolean;
    package?: {
        name: string;
    };
    created_at: string;
}

interface ActiveConnection {
    name: string;
    address: string;
    uptime: string;
    // ... other props
}

interface RouterCustomersTableProps {
    routerId: number;
    /** Live name list — only present on small routers; empty on Krian-class by design */
    activeConnections: ActiveConnection[];
}

const fetcher = (url: string) => fetch(url).then((res) => res.json());

export default function RouterCustomersTable({ routerId, activeConnections }: RouterCustomersTableProps) {
    const [page, setPage] = useState(1);
    const [perPage, setPerPage] = useState(10);
    const [search, setSearch] = useState('');
    const [statusFilter, setStatusFilter] = useState('all');
    const [debouncedSearch, setDebouncedSearch] = useState('');

    // Debounce search input
    const handleSearchChange = (value: string) => {
        setSearch(value);
        const timer = setTimeout(() => {
            setDebouncedSearch(value);
            setPage(1); // Reset to page 1 on search
        }, 500);
        return () => clearTimeout(timer);
    };

    const handleFilterChange = (key: string, value: string) => {
        if (key === 'status') {
            setStatusFilter(value);
            setPage(1);
        }
    };

    const handleReset = () => {
        setSearch('');
        setDebouncedSearch('');
        setStatusFilter('all');
        setPage(1);
    };

    // Construct URL for SWR
    const queryParams = new URLSearchParams({
        page: page.toString(),
        limit: perPage.toString(),
        search: debouncedSearch,
        status: statusFilter,
    });

    const { data: customersData, error, isLoading, mutate } = useSWR<PaginatedData<Customer>>(
        `/api/routers/${routerId}/customers?${queryParams.toString()}`,
        fetcher,
        {
            keepPreviousData: true, // Keep showing old data while fetching new page
        }
    );

    // Live list only reliable when parent actually dumped names (small pools).
    // Large pools (Krian ~460) leave activeConnections empty on purpose — use DB is_online.
    const liveNamesReady = Array.isArray(activeConnections) && activeConnections.length > 0;
    const liveNameSet = liveNamesReady
        ? new Set(
            activeConnections
                .map((c) => (c.name || '').trim().toLowerCase())
                .filter(Boolean)
        )
        : null;

    const checkIsOnline = (customer: Customer) => {
        const user = (customer.pppoe_user || '').trim();
        if (!user) return false;
        if (liveNameSet) {
            return liveNameSet.has(user.toLowerCase());
        }
        return Boolean(customer.is_online);
    };

    const filterConfigs = [
        {
            key: 'status',
            placeholder: 'Filter Status',
            options: [
                { label: 'Active', value: 'active' },
                { label: 'Isolated', value: 'isolated' },
            ]
        }
    ];

    const activeFilters = {
        status: statusFilter
    };

    return (
        <div className="space-y-4">
            {/* Modular Toolbar */}
            <DataTableToolbar
                search={search}
                onSearchChange={handleSearchChange}
                searchPlaceholder="Search by name, PPPoE..."
                activeFilters={activeFilters}
                filterConfigs={filterConfigs}
                onFilterChange={handleFilterChange}
                onReset={handleReset}
                actions={
                    <Button variant="outline" size="sm" onClick={() => mutate()} title="Refresh Data">
                        Refresh
                    </Button>
                }
            />

            {/* Table */}
            <div className="rounded-md border border-border overflow-hidden bg-card">
                <Table>
                    <TableHeader className="bg-muted/50">
                        <TableRow>
                            <TableHead>Code</TableHead>
                            <TableHead>Customer Name</TableHead>
                            <TableHead>PPPoE Account</TableHead>
                            <TableHead>Package</TableHead>
                            <TableHead>Connection</TableHead>
                            <TableHead>Billing Status</TableHead>
                            <TableHead className="text-right">Actions</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {isLoading && !customersData ? (
                            Array.from({ length: 5 }).map((_, i) => (
                                <TableRow key={i}>
                                    <TableCell><Skeleton className="h-4 w-12" /></TableCell>
                                    <TableCell><Skeleton className="h-4 w-32" /></TableCell>
                                    <TableCell><Skeleton className="h-4 w-24" /></TableCell>
                                    <TableCell><Skeleton className="h-4 w-20" /></TableCell>
                                    <TableCell><Skeleton className="h-4 w-16" /></TableCell>
                                    <TableCell><Skeleton className="h-4 w-16" /></TableCell>
                                    <TableCell><Skeleton className="h-4 w-8 ml-auto" /></TableCell>
                                </TableRow>
                            ))
                        ) : error ? (
                            <TableRow>
                                <TableCell colSpan={7} className="h-24 text-center text-status-down">
                                    Failed to load customers. Please try refreshing.
                                </TableCell>
                            </TableRow>
                        ) : customersData?.data.length === 0 ? (
                            <TableRow>
                                <TableCell colSpan={7} className="h-24 text-center text-muted-foreground">
                                    No customers found matching your criteria.
                                </TableCell>
                            </TableRow>
                        ) : (
                            customersData?.data.map((customer) => {
                                const isOnline = checkIsOnline(customer);
                                return (
                                    <TableRow key={customer.id} className="hover:bg-muted/20 transition-colors">
                                        <TableCell className="h-11 whitespace-nowrap py-1.5 font-mono text-xs">{customer.code}</TableCell>
                                        <TableCell className="h-11 whitespace-nowrap py-1.5 font-medium">{customer.name}</TableCell>
                                        <TableCell className="h-11 whitespace-nowrap py-1.5 font-mono text-sm text-muted-foreground">{customer.pppoe_user}</TableCell>
                                        <TableCell className="h-11 whitespace-nowrap py-1.5">{customer.package?.name || '-'}</TableCell>
                                        <TableCell className="h-11 py-1.5">
                                            <StatusBadge label={isOnline ? 'Online' : 'Offline'} tone={isOnline ? 'up' : 'neutral'} />
                                        </TableCell>
                                        <TableCell>
                                            <StatusBadge label={customerStatusLabel(customer.status)} tone={customerStatusTone(customer.status)} />
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <DropdownMenu>
                                                <DropdownMenuTrigger asChild>
                                                    <Button variant="ghost" className="h-8 w-8 p-0">
                                                        <span className="sr-only">Open menu</span>
                                                        <MoreHorizontal className="h-4 w-4" />
                                                    </Button>
                                                </DropdownMenuTrigger>
                                                <DropdownMenuContent align="end">
                                                    <DropdownMenuItem onClick={() => router.visit(route('customers.show', customer.id))}>
                                                        <Eye className="mr-2 h-4 w-4" />
                                                        View Details
                                                    </DropdownMenuItem>
                                                    <DropdownMenuItem onClick={() => router.visit(route('customers.edit', customer.id))}>
                                                        <Edit className="mr-2 h-4 w-4" />
                                                        Edit Customer
                                                    </DropdownMenuItem>
                                                </DropdownMenuContent>
                                            </DropdownMenu>
                                        </TableCell>
                                    </TableRow>
                                );
                            })
                        )}
                    </TableBody>
                </Table>
            </div>

            {/* Modular Pagination */}
            {customersData && (
                <DataTablePagination
                    data={customersData}
                    onPageChange={setPage}
                    onPageSizeChange={(size) => {
                        setPerPage(size);
                        setPage(1);
                    }}
                />
            )}
        </div>
    );
}
