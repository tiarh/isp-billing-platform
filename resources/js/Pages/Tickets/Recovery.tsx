import { DrawerDetail } from '@/Components/DrawerDetail';
import { EmptyState } from '@/Components/EmptyState';
import DataTable, { Column, PaginatedData } from '@/Components/DataTable';
import { EnterpriseIndexLayout } from '@/Components/enterprise/EnterpriseIndexLayout';
import { RecoveryDetail } from '@/Components/recovery/RecoveryDetail';
import { RecoveryStatusChip } from '@/Components/recovery/RecoveryStatusChip';
import { TechnicianReportForm } from '@/Components/recovery/TechnicianReportForm';
import { AreaOption, RecoveryCase, RecoveryDetail as RecoveryDetailData, TechnicianOption } from '@/Components/recovery/types';
import { SkeletonCard } from '@/Components/SkeletonCard';
import { Button } from '@/Components/ui/button';
import { BottomActionBar } from '@/Components/mobile/BottomActionBar';
import { FilterChips } from '@/Components/mobile/FilterChips';
import { MobileCard } from '@/Components/mobile/MobileCard';
import { Input } from '@/Components/ui/input';
import { cn } from '@/lib/utils';
import { Link, router, usePage } from '@inertiajs/react';
import { Archive, ArrowLeft, ClipboardCheck, Cpu, MapPin, PackagePlus, Search, SlidersHorizontal, Warehouse } from 'lucide-react';
import { FormEvent, useRef, useState } from 'react';

interface Props {
    cases: PaginatedData<RecoveryCase>;
    filters: { status: string | null; status_chip?: string | null; area_id: number | null; assigned_to: number | null; search: string; limit: number };
    statusOptions: Record<string, string>;
    statusChips?: { value: string; label: string }[];
    areas: AreaOption[];
    technicians: TechnicianOption[];
    summary: { total: number; in_progress: number; needs_review: number };
    readOnly: boolean;
    canReport?: boolean;
}

export default function Recovery({ cases, summary, filters, statusOptions, statusChips, areas, technicians, readOnly, canReport = false }: Props) {
    // One option per visible label; multi-status labels carry a group key the server expands.
    const statusFilterOptions = statusChips ?? Object.entries(statusOptions).map(([value, label]) => ({ value, label }));
    const { auth } = usePage().props as { auth: { user: { id: number; role: string; scope?: string } } };
    const user = auth.user;
    const writesEnabled = readOnly === false;
    const isAdminLike = writesEnabled && ['admin', 'noc', 'superadmin'].includes(user.role);
    const isGlobalAdmin = writesEnabled && (['noc', 'superadmin'].includes(user.role) || user.role === 'admin' && user.scope !== 'scoped_admin');
    const [search, setSearch] = useState(filters.search || '');
    const [status, setStatus] = useState(filters.status_chip || filters.status || '');
    const [areaId, setAreaId] = useState(filters.area_id ? String(filters.area_id) : '');
    const [assignedTo, setAssignedTo] = useState(filters.assigned_to ? String(filters.assigned_to) : '');
    const [drawerOpen, setDrawerOpen] = useState(false);
    const [selected, setSelected] = useState<RecoveryCase | null>(null);
    const [detail, setDetail] = useState<RecoveryDetailData | null>(null);
    const [detailLoading, setDetailLoading] = useState(false);
    const [detailError, setDetailError] = useState('');
    // Page-level so retry idempotency keys survive closing/reopening a case.
    const operationKeys = useRef<Record<string, string>>({});
    const [photoBusy, setPhotoBusy] = useState(false);
    const [reportOpen, setReportOpen] = useState(false);
    const [filterOpen, setFilterOpen] = useState(false);
    const [snMode, setSnMode] = useState(false);
    const searchRef = useRef<HTMLInputElement>(null);
    const activeFilterCount = (filters.area_id ? 1 : 0) + (filters.assigned_to && user.role !== 'teknisi' ? 1 : 0);

    const applyFilters = (event: FormEvent) => {
        event.preventDefault();
        router.get(route('device-recovery.index'), {
            search: search || undefined,
            status: status || undefined,
            area_id: areaId || undefined,
            assigned_to: assignedTo || undefined,
            limit: filters.limit,
        }, { preserveState: true, preserveScroll: true, replace: true });
    };

    const applyLimit = (limit: number) => {
        router.get(route('device-recovery.index'), {
            search: filters.search || undefined,
            status: filters.status || undefined,
            area_id: filters.area_id || undefined,
            assigned_to: filters.assigned_to || undefined,
            limit,
        }, { preserveState: true, preserveScroll: true, replace: true });
    };

    // Status chips (phones) apply immediately and keep the other applied filters.
    const applyStatus = (value: string) => {
        setStatus(value);
        router.get(route('device-recovery.index'), {
            search: filters.search || undefined,
            status: value || undefined,
            area_id: filters.area_id || undefined,
            assigned_to: filters.assigned_to || undefined,
            limit: filters.limit,
        }, { preserveState: true, preserveScroll: true, replace: true });
    };

    // No scanner (camera is blocked by Permissions-Policy): focus search so a scanned or typed SN can be entered.
    const focusSerialSearch = () => {
        setSnMode(true);
        searchRef.current?.scrollIntoView({ block: 'center' });
        searchRef.current?.focus();
    };

    const closeDrawer = () => {
        setDrawerOpen(false);
        setDetail(null);
        setDetailError('');
    };

    const refreshAfterAction = () => {
        closeDrawer();
        router.reload({ only: ['cases', 'summary'] });
    };

    const openDetail = async (item: RecoveryCase) => {
        setSelected(item);
        setDrawerOpen(true);
        setDetail(null);
        setDetailError('');
        setDetailLoading(true);
        try {
            const response = await fetch(route('device-recovery.show', item.id), { headers: { Accept: 'application/json' } });
            const body = await response.json() as RecoveryDetailData | { message?: string };
            if (!response.ok) throw new Error(('message' in body && body.message) || 'Detail recovery tidak dapat dimuat.');
            setDetail(body as RecoveryDetailData);
        } catch (error) {
            setDetailError(error instanceof Error ? error.message : 'Detail recovery tidak dapat dimuat.');
        } finally {
            setDetailLoading(false);
        }
    };

    const columns: Column<RecoveryCase>[] = [
        { header: 'Tiket', cell: (item) => <div><span className="font-mono text-xs font-semibold">{item.ticket?.code || '—'}</span>{item.ticket?.dismantle_reason_label && <p className="text-xs text-muted-foreground">{item.ticket.dismantle_reason_label}</p>}</div> },
        { header: 'Pelanggan', cell: (item) => <div><p className="font-medium">{item.customer?.name || '—'}</p><p className="font-mono text-xs text-muted-foreground">{item.customer?.code || '—'}</p></div> },
        { header: 'Status fisik', cell: (item) => <RecoveryStatusChip item={item} /> },
        { header: 'Perangkat / SN', cell: (item) => { const serials = item.items.map((recoveryItem) => recoveryItem.captured_serial_number || recoveryItem.expected_serial_number || 'SN—').join(' · '); return <div className="max-w-[220px] font-mono text-xs"><div>{item.items.length} perangkat</div><div className="truncate" title={serials}>{serials}</div></div>; } },
        { header: 'Teknisi', cell: (item) => item.assigned_to?.name || 'Belum ditugaskan' },
        { header: 'Area', cell: (item) => item.area?.name || '—', className: 'text-muted-foreground' },
    ];

    const mobileCard = (item: RecoveryCase) => (
        <MobileCard
            onSelect={() => openDetail(item)}
            code={item.ticket?.code || '—'}
            title={<>{item.customer?.name || 'Customer tidak ditemukan'}<span className="mt-1.5 flex flex-wrap font-normal"><RecoveryStatusChip item={item} /></span></>}
            meta={[
                { icon: Cpu, className: 'font-data', text: item.items.map((recoveryItem) => recoveryItem.captured_serial_number || recoveryItem.expected_serial_number || 'SN belum tersedia').join(' · ') || 'SN belum tersedia' },
                { icon: MapPin, text: `${item.assigned_to?.name || 'Belum ditugaskan'} · ${item.area?.name || 'Area tidak tersedia'}` },
            ]}
        >
            {item.ticket?.dismantle_reason_label && <span className="mt-1 block text-xs text-muted-foreground">Alasan: {item.ticket.dismantle_reason_label}</span>}
        </MobileCard>
    );

    return (
        <EnterpriseIndexLayout
            title="Pengambilan Perangkat"
            pageTitle="Pengambilan Perangkat"
            subtitle="Lacak perangkat dari tiket dismantle sampai diterima gudang"
            icon={Warehouse}
            breadcrumbs={[{ label: 'Ticketing', href: route('tickets.index') }, { label: 'Pengambilan Perangkat' }]}
            actions={<div className="flex flex-wrap gap-2">{canReport && writesEnabled && <Button type="button" size="sm" className="h-11 sm:h-9" onClick={() => setReportOpen(true)} data-testid="recovery-report-open"><PackagePlus className="mr-2 size-4" aria-hidden />Lapor pengambilan tanpa tiket</Button>}<Button asChild type="button" variant="outline" size="sm" className="hidden md:inline-flex"><Link href={route('tickets.index')}><ArrowLeft className="mr-2 size-4" aria-hidden />Ticketing</Link></Button><Button asChild type="button" variant="outline" size="sm" className="hidden md:inline-flex"><Link href={route('inventaris.index')}><Archive className="mr-2 size-4" aria-hidden />Inventaris</Link></Button></div>}
        >
            <h1 className="sr-only">Pengambilan Perangkat</h1>
            {readOnly && <div className="mb-6 rounded-lg border border-status-degraded/30 bg-status-degraded-bg p-4 text-sm" role="status"><p className="font-medium">Mode read-only</p><p className="mt-1 text-muted-foreground">Data recovery dapat dilihat, tetapi aksi perubahan dinonaktifkan selama verifikasi production.</p></div>}
            <div className="mb-4 grid grid-cols-3 gap-2 md:mb-6 md:gap-3">
                {[
                    { label: 'Total', value: summary.total },
                    { label: 'Berjalan', value: summary.in_progress },
                    { label: 'Perlu tinjauan', value: summary.needs_review },
                ].map((tile) => (
                    <div key={tile.label} className="rounded-lg border bg-card p-3"><p className="text-[11px] text-muted-foreground">{tile.label}</p><p className="mt-1 font-data text-xl font-semibold tabular-nums">{tile.value}</p></div>
                ))}
            </div>

            <form onSubmit={applyFilters} className="mb-4 grid gap-3 md:mb-6 md:grid-cols-[minmax(0,1fr)_180px_180px_180px_auto] md:items-end md:rounded-lg md:border md:bg-card md:p-4">
                <div className="flex items-end gap-2">
                    <div className="min-w-0 flex-1 space-y-1.5"><label htmlFor="recovery-search" className="text-xs font-medium">Cari tiket, pelanggan, atau SN</label><div className="relative"><Search className="pointer-events-none absolute left-3 top-3.5 size-4 text-muted-foreground md:top-2.5" aria-hidden /><Input ref={searchRef} id="recovery-search" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Contoh: TCK-2026 atau HWTC..." enterKeyHint="search" autoCapitalize={snMode ? 'characters' : undefined} autoComplete="off" className="h-11 pl-9 md:h-9" /></div></div>
                    <Button type="button" variant="outline" aria-label="Filter area dan teknisi" aria-expanded={filterOpen} aria-controls="recovery-filters" onClick={() => setFilterOpen((open) => !open)} className="relative h-11 w-11 shrink-0 p-0 md:hidden"><SlidersHorizontal className="size-4" aria-hidden />{activeFilterCount > 0 && <span className="absolute -right-1 -top-1 flex size-5 items-center justify-center rounded-full bg-primary font-data text-[11px] text-primary-foreground">{activeFilterCount}</span>}</Button>
                </div>
                <div className="hidden space-y-1.5 md:block"><label htmlFor="recovery-status" className="text-xs font-medium">Status fisik</label><select id="recovery-status" value={status} onChange={(event) => setStatus(event.target.value)} className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"><option value="">Semua status</option>{statusFilterOptions.map(({ value, label }) => <option key={value} value={value}>{label}</option>)}</select></div>
                <div id="recovery-filters" className={cn('gap-3', filterOpen ? 'grid' : 'hidden', 'md:contents')}>
                    <div className="space-y-1.5"><label htmlFor="recovery-area" className="text-xs font-medium">Area</label><select id="recovery-area" value={areaId} onChange={(event) => setAreaId(event.target.value)} className="h-11 w-full rounded-md border border-input bg-background px-3 text-sm md:h-9"><option value="">Semua area</option>{areas.map((area) => <option key={area.id} value={area.id}>{area.name}</option>)}</select></div>
                    {user.role !== 'teknisi' && <div className="space-y-1.5"><label htmlFor="recovery-technician" className="text-xs font-medium">Teknisi</label><select id="recovery-technician" value={assignedTo} onChange={(event) => setAssignedTo(event.target.value)} className="h-11 w-full rounded-md border border-input bg-background px-3 text-sm md:h-9"><option value="">Semua teknisi</option>{technicians.map((technician) => <option key={technician.id} value={technician.id}>{technician.name}</option>)}</select></div>}
                    <Button type="submit" className="h-11 md:h-9">Terapkan</Button>
                </div>
            </form>

            <div className="mb-4 md:hidden"><FilterChips label="Filter status fisik" options={[{ value: '', label: 'Semua' }, ...statusFilterOptions]} value={filters.status_chip || filters.status || ''} onChange={applyStatus} /></div>

            {cases.data.length === 0 ? <EmptyState icon={ClipboardCheck} title="Belum ada recovery perangkat" description="Case dibuat otomatis saat tiket Dismantle disimpan dan ditugaskan ke teknisi tiket tersebut." action={<Button asChild type="button" variant="outline"><Link href={route('tickets.index')}>Buka Ticketing</Link></Button>} /> : <DataTable data={cases} columns={columns} title="Daftar pengambilan" description={`${cases.total} tiket · Assignment bukan bukti perangkat sudah diambil.`} filters={filters} routeName="device-recovery.index" syncUrl={false} hideToolbar pageSizeOptions={[10, 20, 25, 50]} onPageSizeChange={applyLimit} onRowClick={openDetail} mobileCard={mobileCard} />}

            {canReport && writesEnabled && <DrawerDetail open={reportOpen} onOpenChange={setReportOpen} title="Lapor pengambilan tanpa tiket" description="Perangkat sudah diambil sebelum ada tiket dari admin.">
                <TechnicianReportForm onDone={() => { setReportOpen(false); router.reload({ only: ['cases', 'summary'] }); }} />
            </DrawerDetail>}

            <DrawerDetail open={drawerOpen} onOpenChange={(open) => open ? setDrawerOpen(true) : closeDrawer()} title={selected?.ticket?.code || 'Detail recovery'} description={selected?.customer?.name || 'Perangkat kembali'}>
                {detailLoading && <div className="space-y-3"><SkeletonCard /><SkeletonCard /></div>}
                {!detailLoading && detailError && <div className="rounded-lg border border-status-down/30 bg-status-down-bg p-4 text-sm text-status-down" role="alert"><p>{detailError}</p><Button type="button" variant="outline" size="sm" className="mt-3" onClick={() => selected && openDetail(selected)}>Coba lagi</Button></div>}
                {!detailLoading && !detailError && detail && selected && <RecoveryDetail key={detail.id} selected={selected} detail={detail} writesEnabled={writesEnabled} operationKeys={operationKeys} onDone={refreshAfterAction} user={user} isAdminLike={isAdminLike} isGlobalAdmin={isGlobalAdmin} technicians={technicians} photoBusy={photoBusy} setPhotoBusy={setPhotoBusy} />}
            </DrawerDetail>
            <BottomActionBar label="Aksi pengambilan perangkat">
                <Button asChild type="button" variant="outline" className="h-12 flex-1"><Link href={route('tickets.index')}>Tiket</Link></Button>
                <Button type="button" className="h-12 flex-[2]" onClick={focusSerialSearch}><Search className="mr-2 size-4" aria-hidden />Cari SN</Button>
            </BottomActionBar>
        </EnterpriseIndexLayout>
    );
}
