import { useState, useEffect, useRef, ReactNode } from 'react';
import { Fragment } from 'react';
import { router } from '@inertiajs/react';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { ArrowUpDown, ArrowUp, ArrowDown, Loader2 } from "lucide-react";
import { DataTableToolbar } from '@/Components/DataTableToolbar';
import { DataTablePagination } from '@/Components/DataTablePagination';
import { EmptyState } from '@/Components/EmptyState';
import { SkeletonTable } from '@/Components/SkeletonTable';
import Checkbox from '@/Components/Checkbox';
import { cn } from '@/lib/utils';

// Helper for debounce
function useDebounce<T>(value: T, delay: number): T {
    const [debouncedValue, setDebouncedValue] = useState(value);
    useEffect(() => {
        const handler = setTimeout(() => {
            setDebouncedValue(value);
        }, delay);
        return () => {
            clearTimeout(handler);
        };
    }, [value, delay]);
    return debouncedValue;
}

// Generic Types
export interface Column<T> {
    header: ReactNode;
    accessorKey?: keyof T;
    cell?: (item: T) => ReactNode;
    sortable?: boolean;
    className?: string; // For applying column specific styles like width or alignment
    /** Cells are nowrap by default; set true for long free text that should wrap. */
    wrap?: boolean;
}

export interface FilterOption {
    label: string;
    value: string;
    icon?: React.ComponentType<{ className?: string }>; // Enhanced to support icons
}

export interface FilterConfig {
    key: string;
    placeholder: string;
    options: FilterOption[];
    /** Hide this pill on phones (secondary filters). */
    hideOnMobile?: boolean;
}

export interface PaginatedData<T> {
    data: T[];
    path: string;
    links: {
        url: string | null;
        label: string;
        active: boolean;
    }[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number;
    to: number;
}

interface DataTableProps<T> {
    data: PaginatedData<T>;
    columns: Column<T>[];
    title: string;
    description?: string;
    resourceName?: string; // e.g. "customers" for route generation if needed, mostly for logging/debugging
    filters?: Record<string, any>; // Current filter processing from backend
    searchPlaceholder?: string;
    filterConfigs?: FilterConfig[]; // Dropdown filters configuration
    actions?: ReactNode; // Slot for "Add Button" etc.
    /** Short count text shown at the right of the toolbar, e.g. "1.240 pelanggan". */
    summary?: ReactNode;
    /** Page-size choices for the "Baris per halaman" select; the current size is always included. */
    pageSizeOptions?: number[];
    /** Override for pages that manage their own URL (syncUrl={false}); default just updates the local limit. */
    onPageSizeChange?: (pageSize: number) => void;
    /** Page-level query params that are not filters (e.g. a chart year); sent with every search/filter/size change. */
    extraParams?: Record<string, any>;
    loading?: boolean;
    error?: ReactNode;
    onRowClick?: (item: T) => void;
    mobileCard?: (item: T) => ReactNode;
    routeName: string; // Base route name for router.get() e.g. "customers.index"
    routeParams?: any; // Parameters required for the route e.g. { id: 1 }
    /** Hide card title row when page shell already shows the title */
    compact?: boolean;
    syncUrl?: boolean;
    hideToolbar?: boolean;
    hidePagination?: boolean;
    density?: 'comfortable' | 'compact';
    selectable?: boolean;
    bulkActions?: ReactNode;
    onSelectionChange?: (items: T[]) => void;
    onRetry?: () => void;
    /** Optional full-width band drawn above a row (a group title, a divider). Return null for no band. Not a row: no click, no selection. */
    rowBand?: (item: T, index: number, items: T[]) => ReactNode;
}

export default function DataTable<T extends { id: number | string }>({
    data,
    columns,
    title,
    description,
    filters = {},
    searchPlaceholder = "Cari...",
    filterConfigs = [],
    actions,
    summary,
    pageSizeOptions,
    onPageSizeChange,
    extraParams,
    onRowClick,
    mobileCard,
    routeName,
    routeParams,
    compact = false,
    loading = false,
    error,
    syncUrl = true,
    hideToolbar = false,
    hidePagination = false,
    density = 'comfortable',
    selectable = false,
    bulkActions,
    onSelectionChange,
    onRetry,
    rowBand,
}: DataTableProps<T>) {
    const showHeader = !compact && Boolean(title);
    const safeFilters = Array.isArray(filters) ? {} : (filters || {});

    // State
    const [search, setSearch] = useState(safeFilters.search || '');
    const [limit, setLimit] = useState<number>(safeFilters.limit || data.per_page || 20);
    const [activeFilters, setActiveFilters] = useState<Record<string, string>>(() => {
        const initial: Record<string, string> = {};
        filterConfigs.forEach(config => {
            initial[config.key] = safeFilters[config.key] || 'all';
        });
        return initial;
    });

    const [sortField, setSortField] = useState<string>(
        (typeof safeFilters.sort === 'string') ? safeFilters.sort : 'created_at'
    );
    const [sortDirection, setSortDirection] = useState<'asc' | 'desc'>(
        safeFilters.direction === 'asc' ? 'asc' : 'desc'
    );
    const [isLoading, setIsLoading] = useState(false);
    const [selectedIds, setSelectedIds] = useState<Array<number | string>>([]);

    const debouncedSearch = useDebounce(search, 350);

    // The server already rendered this page with the current filters. Skip the
    // very first run of the sync effect: without this guard every DataTable
    // page fired a second, identical router.get right after first paint.
    const skipInitialSync = useRef(true);

    // Effect to trigger search/filter
    useEffect(() => {
        if (!syncUrl || loading || error) return;
        if (skipInitialSync.current) {
            skipInitialSync.current = false;
            return;
        }

        const params: Record<string, any> = {
            ...(extraParams || {}),
            search: debouncedSearch,
            sort: sortField,
            direction: sortDirection,
            limit: limit,
        };

        // Add active filters excluding 'all'
        Object.keys(activeFilters).forEach(key => {
            if (activeFilters[key] !== 'all') {
                params[key] = activeFilters[key];
            }
        });

        setIsLoading(true);
        router.get(
            route(routeName, routeParams),
            params,
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onFinish: () => setIsLoading(false)
            }
        );
    }, [debouncedSearch, activeFilters, sortField, sortDirection, limit, routeName, routeParams, syncUrl, loading, error]);

    // Handlers
    const handleSort = (field: string) => {
        if (sortField === field) {
            setSortDirection(sortDirection === 'asc' ? 'desc' : 'asc');
        } else {
            setSortField(field);
            setSortDirection('asc');
        }
    };

    const handleFilterChange = (key: string, value: string) => {
        setActiveFilters(prev => ({ ...prev, [key]: value }));
    };

    const handleReset = () => {
        setSearch('');
        const resetFilters: Record<string, string> = {};
        filterConfigs.forEach(config => {
            resetFilters[config.key] = 'all';
        });
        setActiveFilters(resetFilters);
        setSortField('created_at');
        setSortDirection('desc');
    };

    const rowId = (item: T): number | string => item.id;

    // C5: clickable rows are reachable and operable from the keyboard. Only
    // the row itself reacts; Enter/Space on a nested button keeps its own action.
    // Table rows keep their native `row` role. Mobile cards usually contain
    // their own buttons/links, so the card wrapper stays a plain click target
    // (making it a focusable role=button would nest interactive controls).
    const rowInteractionProps = (item: T) => onRowClick ? {
        tabIndex: 0,
        onClick: () => onRowClick(item),
        onKeyDown: (event: React.KeyboardEvent<HTMLElement>) => {
            if (event.target !== event.currentTarget) return;
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                onRowClick(item);
            }
        },
    } : {};
    const visibleIds = data.data.map(rowId);
    const allVisibleSelected = visibleIds.length > 0 && visibleIds.every((id) => selectedIds.includes(id));

    const updateSelection = (ids: Array<number | string>) => {
        setSelectedIds(ids);
        onSelectionChange?.(data.data.filter((item) => ids.includes(rowId(item))));
    };

    const toggleAllVisible = () => {
        const next = allVisibleSelected
            ? selectedIds.filter((id) => !visibleIds.includes(id))
            : Array.from(new Set([...selectedIds, ...visibleIds]));
        updateSelection(next);
    };

    const toggleRow = (item: T) => {
        const id = rowId(item);
        const next = selectedIds.includes(id)
            ? selectedIds.filter((selectedId) => selectedId !== id)
            : [...selectedIds, id];
        updateSelection(next);
    };

    const cellDensity = density === 'compact' ? 'h-11 py-1.5' : 'h-[52px] py-2';

    const SortIcon = ({ field }: { field: string }) => {
        if (sortField !== field) return <ArrowUpDown aria-hidden="true" className="ml-1.5 h-3 w-3 text-muted-foreground/50" />;
        return sortDirection === 'asc'
            ? <ArrowUp aria-hidden="true" className="ml-1.5 h-3 w-3 text-foreground" />
            : <ArrowDown aria-hidden="true" className="ml-1.5 h-3 w-3 text-foreground" />;
    };

    if (loading) {
        return <SkeletonTable rows={5} columns={columns.length || 3} className="mt-4" />;
    }

    if (error) {
        return (
            <div className="mt-4 space-y-3" role="alert">
                <div>{error}</div>
                {onRetry && (
                    <Button type="button" variant="outline" onClick={onRetry}>
                        Coba lagi
                    </Button>
                )}
            </div>
        );
    }

    return (
        <div className="space-y-4">
            {/* Filter Bar */}
            {!hideToolbar && (
                <DataTableToolbar
                    search={search}
                    onSearchChange={setSearch}
                    activeFilters={activeFilters}
                    filterConfigs={filterConfigs}
                    onFilterChange={handleFilterChange}
                    onReset={handleReset}
                    searchPlaceholder={searchPlaceholder}
                    actions={actions}
                    summary={summary}
                />
            )}

            {/* Table Card */}
            <Card className="border-border/80 bg-card shadow-sm dark:border-border/60">
                {selectable && selectedIds.length > 0 && (
                    <div className="flex flex-wrap items-center justify-between gap-3 border-b border-border bg-muted/30 px-6 py-3 text-sm">
                        <span>{selectedIds.length} baris dipilih</span>
                        {bulkActions}
                    </div>
                )}
                {showHeader && (
                    <CardHeader className="pb-3">
                        <div className="flex items-center justify-between">
                            <div>
                                <CardTitle>{title}</CardTitle>
                                {description && (
                                    <CardDescription className="mt-1.5 flex items-center gap-2">
                                        {description}
                                        {isLoading && <Loader2 className="h-3 w-3 animate-spin" />}
                                    </CardDescription>
                                )}
                            </div>
                        </div>
                    </CardHeader>
                )}
                <CardContent className={showHeader ? undefined : 'pt-6'}>
                    {!showHeader && description && (
                        <p className="mb-4 flex items-center gap-2 text-sm text-muted-foreground">
                            {description}
                            {isLoading && <Loader2 className="h-3 w-3 animate-spin" />}
                        </p>
                    )}
                    {mobileCard && (
                        data.data.length === 0 ? (
                            <div className="md:hidden">
                                <EmptyState
                                    title="Tidak ada data yang cocok"
                                    description="Ubah kata kunci atau filter yang dipakai."
                                    action={<Button variant="link" onClick={handleReset}>Hapus semua filter</Button>}
                                />
                            </div>
                        ) : (
                            <ul role="list" className="space-y-3 md:hidden">
                                {data.data.map((item, index) => {
                                    const band = rowBand?.(item, index, data.data);
                                    return (
                                        <Fragment key={item.id}>
                                            {band && <li role="presentation">{band}</li>}
                                            <li
                                                className={onRowClick ? 'cursor-pointer' : ''}
                                                onClick={onRowClick ? (event) => {
                                                    // Controls inside the card (buttons, links, inputs) keep their own action:
                                                    // otherwise a card button would also fire onRowClick (double fetch).
                                                    if ((event.target as Element).closest('a,button,input,select,textarea,label')) return;
                                                    onRowClick(item);
                                                } : undefined}
                                            >
                                                {mobileCard(item)}
                                            </li>
                                        </Fragment>
                                    );
                                })}
                            </ul>
                        )
                    )}

                    {/* No columns + cards: the caller draws the rows only as cards (no hidden copy of them in the table). */}
                    {(columns.length > 0 || !mobileCard) && (
                    <div className={cn('overflow-x-auto', mobileCard && 'hidden md:block')}>
                        <Table className="min-w-[640px]">
                            <caption className="sr-only">{title || 'Data tabel'}</caption>
                            <TableHeader>
                                <TableRow className="border-0 hover:bg-transparent">
                                    {selectable && (
                                        <TableHead className="w-12 whitespace-nowrap bg-muted first:rounded-l-[var(--radius-control)]">
                                            <Checkbox
                                                aria-label="Pilih semua baris pada halaman ini"
                                                checked={allVisibleSelected}
                                                onChange={toggleAllVisible}
                                            />
                                        </TableHead>
                                    )}
                                    {columns.map((col, idx) => (
                                        <TableHead key={idx} className={cn('whitespace-nowrap bg-muted first:rounded-l-[var(--radius-control)] last:rounded-r-[var(--radius-control)]', col.className)}>
                                            {col.sortable && col.accessorKey ? (
                                                <Button
                                                    variant="ghost"
                                                    onClick={() => handleSort(col.accessorKey as string)}
                                                    className="-ml-2 h-7 px-2 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground hover:text-foreground"
                                                >
                                                    {col.header}
                                                    <SortIcon field={col.accessorKey as string} />
                                                </Button>
                                            ) : (
                                                <span className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">{col.header}</span>
                                            )}
                                        </TableHead>
                                    ))}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {data.data.length === 0 ? (
                                    <TableRow>
                                        <TableCell colSpan={columns.length + (selectable ? 1 : 0)} className="h-48 text-center">
                                            <EmptyState
                                                title="Tidak ada data yang cocok"
                                                description="Ubah kata kunci atau filter yang dipakai."
                                                action={<Button variant="link" onClick={handleReset}>Hapus semua filter</Button>}
                                            />
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    data.data.map((item, index) => {
                                        const band = rowBand?.(item, index, data.data);
                                        return (
                                        <Fragment key={item.id}>
                                        {band && (
                                            <TableRow className="border-border hover:bg-transparent">
                                                <TableCell colSpan={columns.length + (selectable ? 1 : 0)} className="p-0">
                                                    {band}
                                                </TableCell>
                                            </TableRow>
                                        )}
                                        <TableRow
                                            className={`group border-border transition-colors hover:bg-muted/50 ${onRowClick ? 'cursor-pointer focus-visible:bg-muted/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring' : ''}`}
                                            {...rowInteractionProps(item)}
                                        >
                                            {selectable && (
                                                <TableCell className={cn('whitespace-nowrap', cellDensity)}>
                                                    <Checkbox
                                                        aria-label={`Pilih baris ${item.id}`}
                                                        checked={selectedIds.includes(rowId(item))}
                                                        onClick={(event) => event.stopPropagation()}
                                                        onChange={() => toggleRow(item)}
                                                    />
                                                </TableCell>
                                            )}
                                            {columns.map((col, idx) => (
                                                <TableCell key={idx} className={cn(cellDensity, col.wrap ? 'whitespace-normal' : 'whitespace-nowrap', col.className)}>
                                                    {col.cell
                                                        ? col.cell(item)
                                                        : (col.accessorKey ? (item[col.accessorKey] as ReactNode) : null)
                                                    }
                                                </TableCell>
                                            ))}
                                        </TableRow>
                                        </Fragment>
                                        );
                                    })
                                )}
                            </TableBody>
                        </Table>
                    </div>
                    )}

                    {/* Pagination */}
                    {!hidePagination && (
                        <DataTablePagination
                            data={data}
                            filters={{ ...filters, limit }}
                            onPageSizeChange={(newLimit) => (onPageSizeChange ? onPageSizeChange(newLimit) : setLimit(newLimit))}
                            pageSizeOptions={pageSizeOptions}
                        />
                    )}
                </CardContent>
            </Card>
        </div>
    );
}
