import { EnterpriseIndexLayout } from '@/Components/enterprise/EnterpriseIndexLayout';
import { Link, router } from '@inertiajs/react';
import { Card, CardContent, CardHeader } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Tabs, TabsList, TabsTrigger, TabsContent } from '@/Components/ui/tabs';
import {
    Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/Components/ui/select';
import DataTable, { Column, PaginatedData } from '@/Components/DataTable';
import { EmptyState as SharedEmptyState } from '@/Components/EmptyState';
import { StatusBadge, StatusBadgeFromValue } from '@/Components/StatusBadge';
import { StatCard } from '@/Components/StatCard';
import { formatDateTime, formatNumber } from '@/lib/format';
import { Download, Search, Box, Server, Cable, GitBranch, MapPin, Warehouse, Upload, PackageCheck } from 'lucide-react';
import { useState } from 'react';
import type { ComponentType } from 'react';

interface OdpItem {
    id: number; name: string; area: string | null;
    total_ports: number | null; active_ports: number; available_ports: number;
    status: string; lat: number; lng: number; type: string;
}
interface OdcItem {
    id: number; name: string; area: string | null; olt_port: string | null;
    total_ports: number | null; active_ports: number; available_ports: number;
    status: string; lat: number; lng: number; type: string;
}
interface KabelItem {
    id: number; name: string; area: string | null;
    type: string; length_km: number; status: string;
}
interface SplitterItem {
    id: number; name: string; area: string | null; type: string;
    total_ports: number; used_ports: number; available_ports: number;
    status: string; parent_odp_odc: string | null;
}
interface AreaItem { id: number; name: string; code: string; }
interface Stats { odp: number; odc: number; kabel: number; splitter: number; }
interface Filters { search: string; type: string; area: string; }
interface ImportPreviewRow {
    row: number; status: string; type: string; name: string; area: string | null;
    area_id: number | null; duplicate_id: number | null; note: string | null;
}
interface ImportPreview {
    filename: string;
    mode: string;
    rows: ImportPreviewRow[];
    summary: { total: number; previewed: number; duplicates: number; needs_review: number; ready: number };
}
interface ImportPreviewTableRow extends ImportPreviewRow { id: string; }
interface RecoveryCaseItem {
    id: number;
    ticket_code: string | null;
    customer_name: string | null;
    area: string | null;
    assigned_to: string | null;
    item_count: number;
    serial_numbers: string[];
    status: string;
    status_group: string;
    substatus: string;
    updated_at: string | null;
}
interface RecoveryData {
    total: number;
    by_status: { status: string; label: string; count: number }[];
    cases: RecoveryCaseItem[];
}

interface Props {
    stats: Stats; odps: OdpItem[]; odcs: OdcItem[];
    kabels: KabelItem[]; splitters: SplitterItem[];
    areas: AreaItem[]; filters: Filters; recovery: RecoveryData;
}

function toLocalTableData<T extends { id: number | string }>(items: T[]): PaginatedData<T> {
    return {
        data: items,
        path: route('inventaris.index'),
        links: [],
        current_page: 1,
        last_page: 1,
        per_page: Math.max(items.length, 1),
        total: items.length,
        from: items.length > 0 ? 1 : 0,
        to: items.length,
    };
}

function InventoryTable<T extends { id: number | string }>({
    data,
    title,
    columns,
}: {
    data: T[];
    title: string;
    columns: Column<T>[];
}) {
    return (
        <DataTable
            density="compact"
            data={toLocalTableData(data)}
            columns={columns}
            title={`${title} (${data.length})`}
            routeName="inventaris.index"
            filters={{}}
            syncUrl={false}
            hideToolbar
            hidePagination
        />
    );
}

function InventoryEmptyState({ icon: Icon, label }: {
    icon: ComponentType<{ className?: string; 'aria-hidden'?: boolean }>;
    label: string;
}) {
    return (
        <SharedEmptyState
            icon={Icon}
            title={`Belum ada data ${label}`}
            description="Data akan tampil setelah aset tersedia."
        />
    );
}

function CoordCell({ lat, lng }: { lat: number; lng: number }) {
    if (!lat && !lng) return <span>—</span>;
    return (
        <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
            <MapPin className="h-3 w-3" aria-hidden />
            {Number(lat).toFixed(5)}, {Number(lng).toFixed(5)}
        </span>
    );
}

function OdpTable({ data }: { data: OdpItem[] }) {
    const columns: Column<OdpItem>[] = [
        { header: 'Nama', accessorKey: 'name', className: 'font-medium' },
        { header: 'Area', cell: (item) => item.area || '—', className: 'text-muted-foreground' },
        { header: 'Total Port', cell: (item) => item.total_ports ?? '—', className: 'text-center' },
        { header: 'Aktif', accessorKey: 'active_ports', className: 'text-center' },
        { header: 'Tersedia', accessorKey: 'available_ports', className: 'text-center' },
        { header: 'Status', cell: (item) => <StatusBadgeFromValue value={item.status} /> },
        { header: 'Koordinat', cell: (item) => <CoordCell lat={item.lat} lng={item.lng} /> },
    ];
    return <InventoryTable data={data} title="ODP" columns={columns} />;
}

function OdcTable({ data }: { data: OdcItem[] }) {
    const columns: Column<OdcItem>[] = [
        { header: 'Nama', accessorKey: 'name', className: 'font-medium' },
        { header: 'Area', cell: (item) => item.area || '—', className: 'text-muted-foreground' },
        { header: 'OLT Port', cell: (item) => item.olt_port ? <StatusBadge label={item.olt_port} tone="info" /> : '—' },
        { header: 'Total Port', cell: (item) => item.total_ports ?? '—', className: 'text-center' },
        { header: 'Aktif', accessorKey: 'active_ports', className: 'text-center' },
        { header: 'Tersedia', accessorKey: 'available_ports', className: 'text-center' },
        { header: 'Status', cell: (item) => <StatusBadgeFromValue value={item.status} /> },
        { header: 'Koordinat', cell: (item) => <CoordCell lat={item.lat} lng={item.lng} /> },
    ];
    return <InventoryTable data={data} title="ODC/POP" columns={columns} />;
}

function KabelTable({ data }: { data: KabelItem[] }) {
    const columns: Column<KabelItem>[] = [
        { header: 'Nama', accessorKey: 'name', className: 'font-medium' },
        { header: 'Area', cell: (item) => item.area || '—', className: 'text-muted-foreground' },
        { header: 'Tipe', cell: (item) => <StatusBadge label={item.type} tone="info" /> },
        { header: 'Panjang (km)', cell: (item) => Number(item.length_km).toFixed(3), className: 'text-right font-mono tabular-nums text-sm' },
        { header: 'Status', cell: (item) => <StatusBadgeFromValue value={item.status} /> },
    ];
    return <InventoryTable data={data} title="Kabel" columns={columns} />;
}

function SplitterTable({ data }: { data: SplitterItem[] }) {
    const columns: Column<SplitterItem>[] = [
        { header: 'Nama', accessorKey: 'name', className: 'font-medium' },
        { header: 'Area', cell: (item) => item.area || '—', className: 'text-muted-foreground' },
        { header: 'Tipe', cell: (item) => <StatusBadge label={item.type} tone="info" /> },
        { header: 'Total Port', accessorKey: 'total_ports', className: 'text-center font-mono tabular-nums' },
        { header: 'Terpakai', accessorKey: 'used_ports', className: 'text-center font-mono tabular-nums' },
        { header: 'Tersedia', accessorKey: 'available_ports', className: 'text-center font-mono tabular-nums' },
        { header: 'Status', cell: (item) => <StatusBadgeFromValue value={item.status} /> },
        { header: 'Parent ODP/ODC', cell: (item) => item.parent_odp_odc || '—', className: 'text-muted-foreground' },
    ];
    return <InventoryTable data={data} title="Splitter" columns={columns} />;
}

function RecoveryTable({ data }: { data: RecoveryCaseItem[] }) {
    const columns: Column<RecoveryCaseItem>[] = [
        { header: 'Tiket', cell: (item) => <Link className="font-mono text-xs font-semibold underline-offset-4 hover:underline" href={route('device-recovery.index', { search: item.ticket_code || '' })}>{item.ticket_code || '—'}</Link> },
        { header: 'Pelanggan', cell: (item) => item.customer_name || '—', className: 'font-medium' },
        { header: 'Perangkat / SN', cell: (item) => <div className="font-mono text-xs"><div>{item.item_count} perangkat</div><div>{item.serial_numbers.length > 0 ? item.serial_numbers.join(' · ') : 'SN belum tersedia'}</div></div> },
        { header: 'Status fisik', cell: (item) => <StatusBadge label={item.status_group} tone={['exception', 'exception_accepted'].includes(item.status) ? 'down' : item.status === 'received' ? 'up' : 'info'} /> },
        { header: 'Teknisi', cell: (item) => item.assigned_to || 'Belum ditugaskan' },
        { header: 'Diperbarui', cell: (item) => formatDateTime(item.updated_at), className: 'text-muted-foreground' },
    ];

    return <InventoryTable data={data} title="Perangkat kembali" columns={columns} />;
}

export default function InventarisIndex({ stats, odps, odcs, kabels, splitters, areas, filters, recovery }: Props) {
    const [search, setSearch] = useState(filters.search);
    const [activeTab, setActiveTab] = useState(filters.type === 'all' ? 'all' : filters.type);
    const [areaFilter, setAreaFilter] = useState(filters.area);
    const [preview, setPreview] = useState<ImportPreview | null>(null);
    const [importing, setImporting] = useState(false);
    const [importError, setImportError] = useState('');

    const applyFilters = (newSearch: string, newType: string, newArea: string) => {
        router.get(route('inventaris.index'), {
            search: newSearch || undefined,
            type: newType === 'all' ? undefined : newType,
            area: newArea || undefined,
        }, { preserveState: true, preserveScroll: true });
    };

    const handleSearchSubmit = (e: React.FormEvent) => { e.preventDefault(); applyFilters(search, activeTab, areaFilter); };
    const handleTabChange = (value: string) => { setActiveTab(value); applyFilters(search, value, areaFilter); };
    const handleAreaChange = (value: string) => {
        const newArea = value === '_all' ? '' : value;
        setAreaFilter(newArea);
        applyFilters(search, activeTab, newArea);
    };
    const handleExport = () => {
        const params = new URLSearchParams();
        if (activeTab !== 'all') params.set('type', activeTab);
        if (areaFilter) params.set('area', areaFilter);
        window.open(route('inventaris.export') + (params.toString() ? `?${params.toString()}` : ''), '_blank');
    };
    const handleImportPreview = async (file: File | null) => {
        if (!file) return;
        setImporting(true);
        setImportError('');
        const form = new FormData();
        form.append('file', file);
        form.append('type', 'auto');
        try {
            const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '';
            const res = await fetch(route('inventaris.import-preview'), {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
                body: form,
            });
            const data = await res.json();
            if (!res.ok) throw new Error(data.message || 'Import preview gagal');
            setPreview(data);
        } catch (error: unknown) {
            setImportError(error instanceof Error ? error.message : 'Import preview gagal');
        } finally {
            setImporting(false);
        }
    };

    const statCards = [
        { label: 'ODP', count: stats.odp, icon: Box },
        { label: 'ODC/POP', count: stats.odc, icon: Server },
        { label: 'Kabel', count: stats.kabel, icon: Cable },
        { label: 'Splitter', count: stats.splitter, icon: GitBranch },
    ];
    const importRows: ImportPreviewTableRow[] = preview?.rows.map((row, index) => ({
        ...row,
        id: `${row.row}-${index}`,
    })) || [];
    const importColumns: Column<ImportPreviewTableRow>[] = [
        { header: 'Row', accessorKey: 'row', className: 'font-mono tabular-nums' },
        { header: 'Status', cell: (row) => <StatusBadgeFromValue value={row.status} /> },
        { header: 'Tipe', cell: (row) => row.type.toUpperCase() },
        { header: 'Nama', cell: (row) => row.name || '—', className: 'font-medium' },
        { header: 'Area', cell: (row) => row.area || '—' },
        { header: 'Catatan', wrap: true, cell: (row) => row.note || (row.duplicate_id ? `Duplikat #${row.duplicate_id}` : '—'), className: 'text-xs text-muted-foreground' },
    ];

    return (
        <EnterpriseIndexLayout
            title="Inventaris Aset"
            pageTitle="Inventaris Aset"
            subtitle="Kelola aset jaringan ODP, ODC, kabel, dan splitter"
            icon={Warehouse}
            breadcrumbs={[{ label: 'Inventaris', href: route('inventaris.index') }]}
            actions={
                <div className="flex flex-wrap gap-2">
                    <label htmlFor="inventory-import-file" className="inline-flex h-9 cursor-pointer items-center gap-2 rounded-md border border-input bg-background px-3 text-sm font-medium shadow-sm hover:bg-accent hover:text-accent-foreground">
                        <Upload className="h-4 w-4" aria-hidden />
                        {importing ? 'Preview...' : 'Import Preview'}
                        <input id="inventory-import-file" className="hidden" type="file" accept=".csv,.xlsx,.kml,.kmz" aria-label="Pilih file import inventaris" onChange={(e) => handleImportPreview(e.target.files?.[0] || null)} />
                    </label>
                    <Button type="button" variant="outline" size="sm" className="h-9 gap-2" onClick={handleExport}>
                        <Download className="h-4 w-4" aria-hidden /> Export CSV
                    </Button>
                    <Button asChild type="button" variant="outline" size="sm" className="h-9 gap-2">
                        <Link href={route('device-recovery.index')}><PackageCheck className="h-4 w-4" aria-hidden /> Perangkat kembali</Link>
                    </Button>
                </div>
            }
        >
            <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
                {statCards.map((card) => (
                    <StatCard
                        key={card.label}
                        title={card.label}
                        value={formatNumber(card.count, { maximumFractionDigits: 0 })}
                        icon={card.icon}
                    />
                ))}
            </div>

            <Card className="mb-6 border-border/50 bg-card/80">
                <CardContent className="p-4">
                    <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div className="flex flex-1 flex-col gap-3 sm:flex-row sm:items-center">
                            <form onSubmit={handleSearchSubmit} className="relative max-w-md flex-1">
                                <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" aria-hidden />
                                <Input type="search" aria-label="Cari inventaris" placeholder="Cari nama, area, deskripsi..." value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    className="border-transparent bg-muted/50 pl-9 hover:bg-muted focus-visible:border-primary" />
                            </form>
                            <Select value={areaFilter || '_all'} onValueChange={handleAreaChange}>
                                <SelectTrigger className="w-[180px] border-transparent bg-muted/50 hover:bg-muted" aria-label="Filter area">
                                    <SelectValue placeholder="Semua Area" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="_all">Semua Area</SelectItem>
                                    {areas.map((area) => (
                                        <SelectItem key={area.id} value={String(area.id)}>{area.name}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </div>
                </CardContent>
            </Card>

            {importError && (
                <Card className="mb-6 border-status-down/30 bg-status-down-bg" role="alert">
                    <CardContent className="p-4 text-sm text-status-down">{importError}</CardContent>
                </Card>
            )}
            {preview && (
                <Card className="mb-6 border-border/50 bg-card/80">
                    <CardHeader className="pb-3">
                        <h2 className="text-base font-semibold">Preview Import: {preview.filename}</h2>
                        <p className="text-sm text-muted-foreground">{preview.summary.previewed} row ditampilkan untuk ditinjau.</p>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        <div className="grid grid-cols-2 gap-2 text-xs md:grid-cols-4">
                            <div className="rounded-md bg-status-up-bg p-2"><b>{preview.summary.ready}</b><span className="ml-1 text-muted-foreground">ready</span></div>
                            <div className="rounded-md bg-status-degraded-bg p-2"><b>{preview.summary.needs_review}</b><span className="ml-1 text-muted-foreground">review</span></div>
                            <div className="rounded-md bg-status-down-bg p-2"><b>{preview.summary.duplicates}</b><span className="ml-1 text-muted-foreground">duplikat</span></div>
                            <div className="rounded-md bg-status-info-bg p-2"><b>{preview.summary.total}</b><span className="ml-1 text-muted-foreground">row</span></div>
                        </div>
                        <DataTable
                            density="compact"
                            data={toLocalTableData(importRows)}
                            columns={importColumns}
                            title="Baris import"
                            routeName="inventaris.index"
                            filters={{}}
                            syncUrl={false}
                            hideToolbar
                            hidePagination
                            compact
                        />
                    </CardContent>
                </Card>
            )}

            <Tabs value={activeTab} onValueChange={handleTabChange}>
                <TabsList className="mb-4 flex-wrap">
                    <TabsTrigger value="all">Semua</TabsTrigger>
                    <TabsTrigger value="odp">ODP</TabsTrigger>
                    <TabsTrigger value="odc">ODC/POP</TabsTrigger>
                    <TabsTrigger value="kabel">Kabel</TabsTrigger>
                    <TabsTrigger value="splitter">Splitter</TabsTrigger>
                    <TabsTrigger value="recovery">Perangkat kembali{recovery.total > 0 ? ' (' + recovery.total + ')' : ''}</TabsTrigger>
                </TabsList>

                <TabsContent value="all" className="space-y-6">
                    {odps.length > 0 && <OdpTable data={odps} />}
                    {odcs.length > 0 && <OdcTable data={odcs} />}
                    {kabels.length > 0 && <KabelTable data={kabels} />}
                    {splitters.length > 0 && <SplitterTable data={splitters} />}
                    {odps.length === 0 && odcs.length === 0 && kabels.length === 0 && splitters.length === 0 && (
                        <InventoryEmptyState icon={Box} label="aset" />
                    )}
                </TabsContent>
                <TabsContent value="odp">{odps.length > 0 ? <OdpTable data={odps} /> : <InventoryEmptyState icon={Box} label="ODP" />}</TabsContent>
                <TabsContent value="odc">{odcs.length > 0 ? <OdcTable data={odcs} /> : <InventoryEmptyState icon={Server} label="ODC/POP" />}</TabsContent>
                <TabsContent value="kabel">{kabels.length > 0 ? <KabelTable data={kabels} /> : <InventoryEmptyState icon={Cable} label="Kabel" />}</TabsContent>
                <TabsContent value="splitter">{splitters.length > 0 ? <SplitterTable data={splitters} /> : <InventoryEmptyState icon={GitBranch} label="Splitter" />}</TabsContent>
                <TabsContent value="recovery" className="space-y-4">
                    <Card>
                        <CardHeader className="pb-3">
                            <h2 className="text-base font-semibold">Status pengambilan perangkat</h2>
                            <p className="text-sm text-muted-foreground">Assignment bukan bukti perangkat sudah diterima gudang.</p>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="flex flex-wrap gap-2">
                                {recovery.by_status.map((status) => <StatusBadge key={status.status} label={status.label + ': ' + status.count} tone={['exception', 'exception_accepted'].includes(status.status) ? 'down' : status.status === 'received' ? 'up' : 'info'} />)}
                            </div>
                            {recovery.cases.length > 0 ? <RecoveryTable data={recovery.cases} /> : <SharedEmptyState icon={PackageCheck} title="Belum ada perangkat kembali" description="Case dibuat dari tiket dismantle setelah lifecycle customer di-approve." action={<Button asChild type="button" variant="outline"><Link href={route('device-recovery.index')}>Buka pengambilan perangkat</Link></Button>} />}
                        </CardContent>
                    </Card>
                </TabsContent>
            </Tabs>
        </EnterpriseIndexLayout>
    );
}
