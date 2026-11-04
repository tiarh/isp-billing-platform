import { EnterpriseIndexLayout } from '@/Components/enterprise/EnterpriseIndexLayout';
import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import { Button } from '@/Components/ui/button';
import { Badge } from '@/Components/ui/badge';
import { StatusBadge } from '@/Components/StatusBadge';
import { DrawerDetail } from '@/Components/DrawerDetail';
import { ConfirmDestructive } from '@/Components/ConfirmDestructive';
import { LifecycleGuardNotice, lifecycleOverrideReady } from '@/Components/LifecycleGuardNotice';
import { Download, Users, Archive, RotateCcw, ExternalLink, Phone, MessageCircle, Pencil, FileText, MoreHorizontal } from "lucide-react";
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/Components/ui/dropdown-menu';
import { cn } from '@/lib/utils';
import Checkbox from '@/Components/Checkbox';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import DataTable, { Column, FilterConfig, PaginatedData } from '@/Components/DataTable';
import { CUSTOMER_STATUS_OPTIONS, customerStatusLabel, customerStatusTone, mikrotikSyncLabel } from '@/lib/customerLabels';
import { isPastDueWib } from '@/lib/dateTime';
import { formatDate, formatDateTime, formatIdr } from '@/lib/format';
import { PageProps } from '@/types';
import { telLink, waLink } from '@/lib/phone';


const getStatusBadge = (status: string, pendingTermination = false) => {
    return (
        <span className="inline-flex flex-wrap items-center gap-1">
            <StatusBadge label={customerStatusLabel(status)} tone={customerStatusTone(status)} />
            {pendingTermination && <StatusBadge label="Terminate tertunda" tone="degraded" />}
        </span>
    );
};

const getMikrotikSyncDot = (customer: Customer) => {
    const status = customer.mikrotik_sync_status || 'unknown';
    const variants: Record<string, string> = {
        synced: 'bg-status-up ring-status-up/20',
        missing: 'bg-status-down ring-status-down/20',
        unknown: 'bg-muted-foreground ring-border',
    };
    const label = mikrotikSyncLabel(status);
    const checkedAt = customer.mikrotik_sync_checked_at
        ? formatDateTime(customer.mikrotik_sync_checked_at)
        : 'Belum dicek';
    const routerName = customer.router?.name || 'Tanpa router';

    return (
        <span
            className={`inline-block h-2.5 w-2.5 shrink-0 rounded-full ring-4 ${variants[status] || variants.unknown}`}
            title={`MikroTik: ${label} | ${routerName} | ${checkedAt}`}
            aria-label={`MikroTik ${label}`}
        />
    );
};

interface Package {
    id: number;
    name: string;
    price?: number;

}

interface Area {
    id: number;
    name: string;
}

interface Router {
    id: number;
    name: string;
}

interface Customer {
    id: number;
    code: string;
    name: string;
    address: string;
    phone?: string;
    pppoe_user: string;
    status: string;
    has_pending_termination?: boolean;
    mikrotik_sync_status: 'unknown' | 'synced' | 'missing';
    mikrotik_synced_at?: string | null;
    mikrotik_sync_checked_at?: string | null;
    unpaid_periods_count?: number;
    next_unpaid_due_date?: string | null;
    package?: Package | null;
    area?: Area;
    router?: Router | null;
    join_date: string;
    created_at: string;
    deleted_at?: string | null;
}

type PendingConfirmation =
    | { kind: 'bulk-package'; packageId: number; packageName: string; customerCount: number }
    | { kind: 'archive'; customerId: number }
    | { kind: 'restore'; customerId: number }
    | null;



interface Props {
    customers: PaginatedData<Customer>;
    packages: Package[];
    areas: Area[];
    view_mode?: 'active' | 'archive';
    archive_count?: number;
    filters: {
        search?: string;
        status?: string;
        package_id?: string;
        area_id?: string;
        mikrotik_sync?: string;
        unpaid_periods?: string;
        sort?: string;
        direction?: 'asc' | 'desc';
        limit?: string;
        archive?: string | number;
        view?: string;
    };
}

export default function Index({
    customers,
    packages = [],
    areas = [],
    filters = {},
    view_mode = 'active',
    archive_count = 0,
}: Props) {
    const { auth } = usePage<PageProps>().props;
    const isAdmin = auth.user.role === 'admin' || auth.user.role === 'superadmin';
    // The export route is admin/superadmin/noc only; hide the button for everyone else (teknisi gets 403).
    const canExport = isAdmin || auth.user.role === 'noc';
    const isArchive = view_mode === 'archive' || filters.archive === 1 || filters.archive === '1' || filters.view === 'archive';
    const [selectedIds, setSelectedIds] = useState<number[]>([]);
    const [bulkPackageId, setBulkPackageId] = useState('');
    const [selectedCustomer, setSelectedCustomer] = useState<Customer | null>(null);
    const [pendingConfirmation, setPendingConfirmation] = useState<PendingConfirmation>(null);
    const [confirmationProcessing, setConfirmationProcessing] = useState(false);
    // Roadmap 1.3: archive refused by the lifecycle guard (customer in use / unknown).
    // Superadmin may override with a written reason; everyone else sees the reason only.
    const isSuperadmin = auth.user.role === 'superadmin';
    const [archiveGuard, setArchiveGuard] = useState<{ message: string; reasonError?: string } | null>(null);
    const [overrideReason, setOverrideReason] = useState('');
    const [overrideChecked, setOverrideChecked] = useState(false);
    useEffect(() => {
        setArchiveGuard(null);
        setOverrideReason('');
        setOverrideChecked(false);
    }, [pendingConfirmation]);
    const visibleIds = useMemo(() => customers.data.map((customer) => customer.id), [customers.data]);
    const allVisibleSelected = visibleIds.length > 0 && visibleIds.every((id) => selectedIds.includes(id));

    useEffect(() => {
        if (isArchive) setSelectedIds([]);
    }, [isArchive]);

    const toggleSelected = (id: number, checked: boolean) => {
        setSelectedIds((current) => checked
            ? Array.from(new Set([...current, id]))
            : current.filter((selected) => selected !== id));
    };

    const toggleAllVisible = (checked: boolean) => {
        setSelectedIds((current) => checked
            ? Array.from(new Set([...current, ...visibleIds]))
            : current.filter((id) => !visibleIds.includes(id)));
    };

    const handleBulkPackage = () => {
        if (!bulkPackageId || selectedIds.length === 0) return;
        const packageName = packages.find((pkg) => String(pkg.id) === bulkPackageId)?.name || 'paket baru';
        setPendingConfirmation({
            kind: 'bulk-package',
            packageId: Number(bulkPackageId),
            packageName,
            customerCount: selectedIds.length,
        });
    };

    const columns: Column<Customer>[] = [
        ...(isAdmin && !isArchive ? [{
            header: (
                <Checkbox
                    aria-label="Pilih semua pelanggan di halaman ini"
                    checked={allVisibleSelected}
                    onChange={(event) => toggleAllVisible(event.target.checked)}
                    onClick={(event) => event.stopPropagation()}
                />
            ),
            className: "w-[42px]",
            cell: (customer: Customer) => (
                <span
                    className={cn(
                        'inline-flex',
                        // Pointer devices: reveal on row hover/focus, or once anything is selected. Touch: always visible.
                        selectedIds.length === 0 && '[@media(hover:hover)]:opacity-0 group-hover:opacity-100 focus-within:opacity-100',
                    )}
                >
                    <Checkbox
                        aria-label={`Pilih ${customer.name}`}
                        checked={selectedIds.includes(customer.id)}
                        onChange={(event) => toggleSelected(customer.id, event.target.checked)}
                        onClick={(event) => event.stopPropagation()}
                    />
                </span>
            ),
        }] : []),
        {
            header: "ID",
            className: "w-[100px]",
            cell: (customer) => (
                <span className="font-mono text-xs text-muted-foreground font-medium">
                    {customer.code}
                </span>
            )
        },
        {
            header: "Nama Pelanggan",
            accessorKey: "name",
            sortable: true,
            cell: (customer) => (
                <div className="flex flex-col">
                    <div className="flex items-center gap-2">
                        {getMikrotikSyncDot(customer)}
                        <span className="max-w-[190px] truncate font-medium text-foreground" title={customer.name}>{customer.name}</span>
                    </div>
                    <span className="max-w-[200px] truncate text-xs text-muted-foreground" title={customer.address}>
                        {customer.address}
                    </span>
                </div>
            )
        },
        {
            header: "Area",
            sortable: false,
            cell: (customer) => (
                <span className="block max-w-[120px] truncate text-sm text-foreground" title={customer.area?.name || undefined}>
                    {customer.area?.name || '-'}
                </span>
            )
        },
        {
            header: "Paket",
            accessorKey: "package",
            sortable: false,
            cell: (customer) => (
                <div className="flex flex-col">
                    <span className="text-sm font-medium text-foreground">
                        {customer.package?.name || '—'}
                    </span>
                    <span className="text-xs font-mono text-muted-foreground">
                        {customer.package
                            ? customer.package.price !== undefined ? formatIdr(customer.package.price) : 'Harga belum diatur'
                            : '—'}
                    </span>
                </div>
            )
        },
        {
            header: "Status",
            accessorKey: "status",
            sortable: true,
            cell: (customer) => (
                <div className="flex flex-col gap-1">
                    {getStatusBadge(customer.status, !!customer.has_pending_termination)}
                    {isArchive && customer.deleted_at && (
                        <span className="text-[10px] text-muted-foreground">
                            Arsip {formatDate(customer.deleted_at)}
                        </span>
                    )}
                </div>
            )
        },
        {
            header: "Gabung",
            accessorKey: "join_date",
            sortable: true,
            cell: (customer) => (
                <span className="text-sm text-muted-foreground">
                    {formatDate(customer.join_date)}
                </span>
            )
        },
        {
            header: "Jatuh Tempo",
            cell: (customer) => {
                const nextDueDate = customer.next_unpaid_due_date;
                const isOverdue = nextDueDate ? isPastDueWib(nextDueDate) : false;

                return (
                    <div className="flex flex-col">
                        {nextDueDate ? (
                            <span className={cn('text-sm', isOverdue ? 'font-medium text-status-down' : 'text-muted-foreground')}>
                                {formatDate(nextDueDate)}
                            </span>
                        ) : (
                            <span className="text-xs text-muted-foreground">Tidak ada tagihan</span>
                        )}
                        {isOverdue && (
                            <span className="text-[10px] font-semibold uppercase tracking-wide text-status-down">TERLAMBAT</span>
                        )}
                        {(customer.unpaid_periods_count || 0) >= 3 && (
                            <Badge variant="outline" className="w-fit border-status-down/30 bg-status-down-bg text-[10px] text-status-down">
                                {customer.unpaid_periods_count} belum bayar
                            </Badge>
                        )}
                    </div>
                );
            }
        },
        ...(isAdmin ? [{
            header: "Aksi",
            // Sticky: the row menu must never scroll out of view on narrow desktops.
            className: "sticky right-0 w-[112px] border-l border-border bg-card text-right group-hover:bg-muted [&:is(th)]:bg-muted",
            cell: (customer: Customer) => (
                <div className="flex items-center justify-end gap-1" onClick={(e) => e.stopPropagation()}>
                    {isArchive ? (
                        <Button
                            size="sm"
                            variant="outline"
                            className="h-8 gap-1"
                            onClick={() => handleRestore(customer.id)}
                            title="Restore dari arsip"
                        >
                            <RotateCcw className="h-3.5 w-3.5" />
                            Restore
                        </Button>
                    ) : (
                        <>
                            <Button asChild variant="ghost" size="icon" className="h-8 w-8 text-muted-foreground hover:text-foreground" title="Edit pelanggan" aria-label={`Edit ${customer.name}`}>
                                <Link href={route('customers.edit', customer.id)}><Pencil className="h-4 w-4" aria-hidden="true" /></Link>
                            </Button>
                            <Button asChild variant="ghost" size="icon" className="h-8 w-8 text-muted-foreground hover:text-foreground" title="Invoice pelanggan" aria-label={`Invoice ${customer.name}`}>
                                <Link href={route('customers.invoices', customer.id)}><FileText className="h-4 w-4" aria-hidden="true" /></Link>
                            </Button>
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button type="button" variant="ghost" size="icon" className="h-8 w-8 text-muted-foreground hover:text-foreground" title="Aksi lainnya" aria-label={`Aksi lainnya untuk ${customer.name}`}>
                                        <MoreHorizontal className="h-4 w-4" aria-hidden="true" />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem onClick={() => router.visit(route('customers.show', customer.id))}>
                                        <ExternalLink className="mr-2 h-4 w-4" aria-hidden="true" />
                                        Detail lengkap
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        className="text-status-down focus:text-status-down"
                                        onClick={() => handleDelete(customer.id)}
                                    >
                                        <Archive className="mr-2 h-4 w-4" aria-hidden="true" />
                                        Pindah ke Arsip
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </>
                    )}
                </div>
            )
        }] : []),
    ];

    const handleDelete = (id: number) => {
        setPendingConfirmation({ kind: 'archive', customerId: id });
    };

    const handleRestore = (id: number) => {
        setPendingConfirmation({ kind: 'restore', customerId: id });
    };

    const executeConfirmation = () => {
        if (!pendingConfirmation) return;

        setConfirmationProcessing(true);
        const finish = () => {
            setConfirmationProcessing(false);
            setPendingConfirmation(null);
        };

        if (pendingConfirmation.kind === 'bulk-package') {
            router.post(route('customers.bulk-package'), {
                customer_ids: selectedIds,
                package_id: pendingConfirmation.packageId,
            }, {
                preserveScroll: true,
                onSuccess: () => {
                    setSelectedIds([]);
                    setBulkPackageId('');
                },
                onFinish: finish,
            });
        } else if (pendingConfirmation.kind === 'archive') {
            router.delete(route('customers.destroy', pendingConfirmation.customerId), {
                data: archiveGuard && isSuperadmin && overrideChecked
                    ? { override: true, override_reason: overrideReason.trim() }
                    : {},
                preserveScroll: true,
                onSuccess: finish,
                // Always leave the "Memproses..." state, also on 403/419/500/network errors.
                onFinish: () => setConfirmationProcessing(false),
                onError: (errors) => {
                    // Guard denial: keep the dialog open and show the reason (+ override form for superadmin).
                    if (errors.lifecycle_guard) {
                        setArchiveGuard({ message: errors.lifecycle_guard, reasonError: errors.override_reason });
                    } else {
                        setPendingConfirmation(null);
                    }
                },
            });
        } else {
            router.post(route('customers.restore', pendingConfirmation.customerId), {}, {
                preserveScroll: true,
                onFinish: finish,
            });
        }
    };

    const switchView = (archive: boolean) => {
        const next: Record<string, string> = {};
        Object.entries(filters).forEach(([k, v]) => {
            if (v !== undefined && v !== null && String(v) !== '' && k !== 'archive' && k !== 'view') {
                next[k] = String(v);
            }
        });
        if (archive) {
            next.archive = '1';
        }
        router.get(route('customers.index'), next, { preserveState: true, replace: true });
    };

    const exportUrl = () => {
        const params = new URLSearchParams();
        Object.entries(filters).forEach(([key, value]) => {
            if (value) {
                params.set(key, String(value));
            }
        });
        if (isArchive) {
            params.set('archive', '1');
        }

        const query = params.toString();
        return query ? `${route('customers.export')}?${query}` : route('customers.export');
    };

    const filterConfigs: FilterConfig[] = [
        {
            key: 'package_id',
            placeholder: 'Semua Paket',
            options: packages.map(pkg => ({ label: pkg.name, value: String(pkg.id) }))
        },
        {
            key: 'area_id',
            placeholder: 'Semua Area',
            options: areas.map(area => ({ label: area.name, value: String(area.id) }))
        },
        {
            key: 'status',
            placeholder: 'Semua Status',
            options: [
                ...CUSTOMER_STATUS_OPTIONS,
            ]
        },
        {
            key: 'mikrotik_sync',
            placeholder: 'Semua MikroTik',
            options: [
                { label: 'Tersinkron', value: 'synced' },
                { label: 'Tidak Ada', value: 'missing' },
                { label: 'Belum Dicek', value: 'unknown' },
            ]
        },
        {
            key: 'unpaid_periods',
            placeholder: 'Semua Billing',
            options: [
                { label: '3+ periode belum bayar', value: '3plus' },
            ]
        }
    ];

    // C6: kartu HP (nama, kode, status, paket, jatuh tempo + telepon/WA) menggantikan tabel 9 kolom.
    const renderMobileCard = (customer: Customer) => {
        const nextDueDate = customer.next_unpaid_due_date;
        const isOverdue = nextDueDate ? isPastDueWib(nextDueDate) : false;
        const tel = telLink(customer.phone);
        const wa = waLink(customer.phone);
        const stop = (event: { stopPropagation: () => void }) => event.stopPropagation();

        return (
            <div className="rounded-xl border bg-card p-4 shadow-sm transition-colors active:bg-muted/40">
                <div className="flex items-start justify-between gap-3">
                    <div className="flex min-w-0 items-start gap-3">
                        {isAdmin && !isArchive && (
                            <span onClick={stop} onKeyDown={stop}>
                                <Checkbox
                                    aria-label={`Pilih ${customer.name}`}
                                    checked={selectedIds.includes(customer.id)}
                                    onChange={(event) => toggleSelected(customer.id, event.target.checked)}
                                />
                            </span>
                        )}
                        <div className="min-w-0">
                            <p className="truncate font-medium text-foreground">{customer.name}</p>
                            <p className="font-mono text-xs text-muted-foreground">{customer.code}</p>
                        </div>
                    </div>
                    {getStatusBadge(customer.status, !!customer.has_pending_termination)}
                </div>
                <div className="mt-3 grid grid-cols-2 gap-3 text-xs">
                    <div>
                        <p className="text-muted-foreground">Paket</p>
                        <p className="mt-0.5 truncate font-medium">{customer.package?.name || '—'}</p>
                    </div>
                    <div>
                        <p className="text-muted-foreground">Jatuh tempo</p>
                        {nextDueDate ? (
                            <p className={`mt-0.5 font-medium ${isOverdue ? 'text-status-down' : ''}`}>
                                {formatDate(nextDueDate)}{isOverdue ? ' · Terlambat' : ''}
                            </p>
                        ) : (
                            <p className="mt-0.5 text-muted-foreground">Tidak ada tagihan</p>
                        )}
                    </div>
                </div>
                {(tel || wa || !isArchive || (isArchive && isAdmin)) && (
                    <div className="mt-3 flex flex-wrap justify-end gap-2" onClick={stop} onKeyDown={stop}>
                        {/* Always-focusable entry point: the <li> wrapper itself is not keyboard reachable. */}
                        {!isArchive && (
                            <Button type="button" size="sm" variant="outline" className="h-10 gap-1.5" onClick={() => setSelectedCustomer(customer)}>
                                <ExternalLink className="h-4 w-4" aria-hidden="true" />
                                Detail
                            </Button>
                        )}
                        {tel && (
                            <Button size="sm" variant="outline" className="h-10 gap-1.5" asChild>
                                <a href={tel} aria-label={`Telepon ${customer.name}`}><Phone className="h-4 w-4" aria-hidden="true" />Telepon</a>
                            </Button>
                        )}
                        {wa && (
                            <Button size="sm" variant="outline" className="h-10 gap-1.5" asChild>
                                <a href={wa} target="_blank" rel="noreferrer" aria-label={`WhatsApp ${customer.name}`}><MessageCircle className="h-4 w-4" aria-hidden="true" />WA</a>
                            </Button>
                        )}
                        {isArchive && isAdmin && (
                            <Button type="button" size="sm" variant="outline" className="h-10 gap-1.5" onClick={() => handleRestore(customer.id)}>
                                <RotateCcw className="h-4 w-4" aria-hidden="true" />
                                Restore
                            </Button>
                        )}
                    </div>
                )}
            </div>
        );
    };

    return (
        <EnterpriseIndexLayout
            title="Pelanggan"
            pageTitle="Pelanggan"
            subtitle={isArchive
                ? 'Arsip / tidak aktif — terpisah dari list Aktif, Isolir, dan Dismantle'
                : 'Direktori pelanggan dan status billing (bukan arsip)'}
            icon={Users}
            breadcrumbs={[{ label: 'Pelanggan', href: route('customers.index') }]}
            actions={
                <div className="flex flex-wrap items-center gap-2">
                    {isAdmin && !isArchive && selectedIds.length > 0 && (
                        <div className="flex w-full flex-wrap items-center gap-2 rounded-md border border-border bg-muted/30 px-2 py-1 sm:w-auto">
                            <span className="text-xs text-muted-foreground">{selectedIds.length} dipilih</span>
                            <Select value={bulkPackageId} onValueChange={setBulkPackageId}>
                                <SelectTrigger aria-label="Paket tujuan" className="h-8 min-w-0 flex-1 bg-background text-xs sm:w-[180px] sm:flex-none">
                                    <SelectValue placeholder="Pilih paket" />
                                </SelectTrigger>
                                <SelectContent>
                                    {packages.map((pkg) => (
                                        <SelectItem key={pkg.id} value={String(pkg.id)}>
                                            {pkg.name}{pkg.price !== undefined ? ` · ${formatIdr(pkg.price)}` : ''}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <Button type="button" size="sm" className="h-8" disabled={!bulkPackageId} onClick={handleBulkPackage}>
                                Ganti paket
                            </Button>
                        </div>
                    )}
                    <div role="group" aria-label="Tampilan daftar pelanggan" className="flex overflow-hidden rounded-[var(--radius-control)] border border-border bg-card p-0.5">
                        <Button
                            type="button"
                            size="sm"
                            variant={!isArchive ? 'secondary' : 'ghost'}
                            aria-pressed={!isArchive}
                            className="h-8 rounded-[calc(var(--radius-control)-2px)]"
                            onClick={() => switchView(false)}
                        >
                            List utama
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant={isArchive ? 'secondary' : 'ghost'}
                            aria-pressed={isArchive}
                            className="h-8 gap-1 rounded-[calc(var(--radius-control)-2px)]"
                            onClick={() => switchView(true)}
                        >
                            <Archive className="h-3.5 w-3.5" aria-hidden="true" />
                            Arsip{archive_count > 0 ? ` (${archive_count})` : ''}
                        </Button>
                    </div>
                    {canExport && (
                        <Button variant="outline" size="sm" className="h-9 gap-2" asChild>
                            <a href={exportUrl()}>
                                <Download className="h-4 w-4" aria-hidden="true" />
                                Export XLSX
                            </a>
                        </Button>
                    )}
                    {isAdmin && !isArchive && (
                        <Button size="sm" className="h-9" asChild>
                            <Link href={route('customers.create')}>Tambah Pelanggan</Link>
                        </Button>
                    )}
                </div>
            }
        >
            <DataTable
                compact
                data={customers}
                columns={columns}
                filters={filters}
                title=""
                summary={isArchive ? `${customers.total} arsip` : `${customers.total} pelanggan`}
                searchPlaceholder="Cari nama, telepon, alamat..."
                filterConfigs={filterConfigs}
                pageSizeOptions={[20, 50, 100]}
                routeName="customers.index"
                onRowClick={(item) => {
                    if (!isArchive) {
                        setSelectedCustomer(item);
                    }
                }}
                mobileCard={(customer) => renderMobileCard(customer)}
            />
            <DrawerDetail
                open={selectedCustomer !== null}
                onOpenChange={(open) => {
                    if (!open) setSelectedCustomer(null);
                }}
                title={selectedCustomer?.name || 'Detail pelanggan'}
                description={selectedCustomer ? `${selectedCustomer.code} · ${selectedCustomer.pppoe_user}` : undefined}
                footer={selectedCustomer ? (
                    <div className="flex w-full justify-end gap-2">
                        <Button type="button" variant="outline" onClick={() => setSelectedCustomer(null)}>
                            Tutup
                        </Button>
                        <Button
                            type="button"
                            onClick={() => {
                                const customerId = selectedCustomer.id;
                                setSelectedCustomer(null);
                                router.visit(route('customers.show', customerId));
                            }}
                        >
                            Detail lengkap
                            <ExternalLink className="ml-2 h-4 w-4" aria-hidden="true" />
                        </Button>
                    </div>
                ) : undefined}
            >
                {selectedCustomer && (
                    <div className="space-y-5">
                        <div className="flex items-center justify-between gap-3">
                            <span className="text-sm text-muted-foreground">Status layanan</span>
                            {getStatusBadge(selectedCustomer.status, !!selectedCustomer.has_pending_termination)}
                        </div>
                        <dl className="space-y-3 text-sm">
                            <div className="flex items-start justify-between gap-4">
                                <dt className="text-muted-foreground">Area</dt>
                                <dd className="text-right font-medium">{selectedCustomer.area?.name || '-'}</dd>
                            </div>
                            <div className="flex items-start justify-between gap-4">
                                <dt className="text-muted-foreground">Router</dt>
                                <dd className="text-right font-medium">{selectedCustomer.router?.name || '-'}</dd>
                            </div>
                            <div className="flex items-start justify-between gap-4">
                                <dt className="text-muted-foreground">PPPoE</dt>
                                <dd className="max-w-[65%] truncate text-right font-mono text-xs">{selectedCustomer.pppoe_user || '-'}</dd>
                            </div>
                            <div className="flex items-start justify-between gap-4">
                                <dt className="text-muted-foreground">Paket</dt>
                                <dd className="text-right font-medium">
                                    {selectedCustomer.package?.name || '-'}
                                    {selectedCustomer.package?.price !== undefined && (
                                        <span className="block font-mono text-xs text-muted-foreground">
                                            {formatIdr(selectedCustomer.package.price)}
                                        </span>
                                    )}
                                </dd>
                            </div>
                            <div className="flex items-start justify-between gap-4">
                                <dt className="text-muted-foreground">Bergabung</dt>
                                <dd className="text-right">{formatDate(selectedCustomer.join_date)}</dd>
                            </div>
                        </dl>
                        <div className="rounded-md border border-border bg-muted/30 p-3 text-sm">
                            <p className="mb-1 text-xs font-medium text-muted-foreground">Alamat instalasi</p>
                            <p>{selectedCustomer.address || '-'}</p>
                        </div>
                    </div>
                )}
            </DrawerDetail>
            <ConfirmDestructive
                open={pendingConfirmation !== null}
                onOpenChange={(open) => {
                    if (!open && !confirmationProcessing) setPendingConfirmation(null);
                }}
                title={pendingConfirmation?.kind === 'bulk-package'
                    ? 'Ganti paket pelanggan?'
                    : pendingConfirmation?.kind === 'archive'
                        ? 'Pindahkan pelanggan ke arsip?'
                        : 'Restore pelanggan?'}
                description={pendingConfirmation?.kind === 'bulk-package'
                    ? `Ganti ${pendingConfirmation.customerCount} pelanggan ke ${pendingConfirmation.packageName}. Invoice unpaid tanpa transaksi mengikuti harga paket; invoice yang sudah punya transaksi dilewati.`
                    : pendingConfirmation?.kind === 'archive'
                        ? 'Pelanggan dipindahkan ke arsip (soft-delete), bukan dihapus permanen. Secret MikroTik tidak dihapus otomatis dan data dapat di-restore.'
                        : 'Pelanggan dikembalikan dari arsip ke list utama.'}
                confirmationLabel="Saya memahami dampak perubahan ini dan sudah memeriksa targetnya."
                confirmText={pendingConfirmation?.kind === 'restore'
                    ? 'Restore'
                    : archiveGuard && isSuperadmin ? 'Tetap arsipkan (override)' : 'Lanjutkan'}
                onConfirm={executeConfirmation}
                isLoading={confirmationProcessing}
                confirmDisabled={!!archiveGuard && !(isSuperadmin && lifecycleOverrideReady(overrideReason, overrideChecked))}
            >
                {archiveGuard && (
                    <LifecycleGuardNotice
                        message={archiveGuard.message}
                        isSuperadmin={isSuperadmin}
                        reason={overrideReason}
                        onReasonChange={setOverrideReason}
                        checked={overrideChecked}
                        onCheckedChange={setOverrideChecked}
                        reasonError={archiveGuard.reasonError}
                        disabled={confirmationProcessing}
                    />
                )}
            </ConfirmDestructive>
        </EnterpriseIndexLayout>
    );
}
