import { EnterpriseIndexLayout } from '@/Components/enterprise/EnterpriseIndexLayout';
import { Link, router, usePage } from '@inertiajs/react';
import { Button } from '@/Components/ui/button';
import { StatusBadge, type StatusTone } from '@/Components/StatusBadge';
import { MobileCard } from '@/Components/mobile/MobileCard';
import { FilterChips } from '@/Components/mobile/FilterChips';
import { BottomActionBar } from '@/Components/mobile/BottomActionBar';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import {
    Plus, RefreshCw, TicketCheck, Eye, Pencil, Clock,
    AlertTriangle, CheckCircle2, Users, CircleDot, Archive, Warehouse, BarChart3, User as UserIcon, Wrench,
} from 'lucide-react';
import { useState, useEffect } from 'react';
import DataTable, { Column, FilterConfig, PaginatedData } from '@/Components/DataTable';
import { formatWibDate } from '@/lib/dateTime';
import { MonitoringCandidatesDrawer, type MonitoringEvidence, type MonitoringSummary } from '@/Components/tickets/MonitoringCandidatesDrawer';

interface Customer { id: number; code: string; name: string; phone: string; }
interface User { id: number; name: string; role: string; }
interface TechStat { id: number; name: string; role: string; total_assigned: number; closed_count: number; open_count: number; }
interface Ticket {
    id: number; code: string; report_id: string | null; customer_id: number | null;
    customer_name: string | null; customer_phone: string | null; customer_coords: string | null;
    gangguan: string; keterangan: string | null; status: string; priority: string;
    type: string; assigned_to: number | null; created_by: number | null;
    kendala: string | null; action_taken: string | null; photos: string[] | null;
    created_at: string; updated_at: string; assigned_at: string | null;
    resolved_at: string | null; due_at: string | null;
    sla_status?: string; sla_label?: string;
    customer: Customer | null; assignee: User | null; creator: User | null;
}

interface Props {
    tickets: PaginatedData<Ticket>;
    filters: { status?: string; archive?: string | number; priority?: string; type?: string; assigned_to?: string; search?: string; };
    technicians: User[];
    statusOptions: string[];
    priorityOptions: string[];
    typeOptions: string[];
    statusCounts: Record<string, number>;
    technicianStats: TechStat[];
    monitoringSummary?: MonitoringSummary;
    monitoringEvidence?: MonitoringEvidence;
}

function statusBadge(status: string, showIcon = true) {
    const tones: Record<string, StatusTone> = {
        open: 'info',
        assigned: 'accent',
        in_progress: 'degraded',
        pending: 'neutral',
        resolved: 'up',
        closed: 'up',
    };
    const labels: Record<string, string> = { open: 'Open', assigned: 'Assigned', in_progress: 'Proses', pending: 'Pending', resolved: 'Resolved', closed: 'Closed', cancelled: 'Cancelled' };
    return <StatusBadge label={labels[status] || status} tone={tones[status] || 'neutral'} showIcon={showIcon} />;
}

function priorityBadge(priority: string) {
    const colors: Record<string, string> = { low: 'text-muted-foreground', normal: 'text-status-info', high: 'text-status-degraded', urgent: 'text-status-down' };
    const labels: Record<string, string> = { low: 'Rendah', normal: 'Normal', high: 'Tinggi', urgent: 'Urgent' };
    return <span className={`text-xs font-medium ${colors[priority] || ''}`}>{labels[priority] || priority}</span>;
}

function DueBadge({ dueAt, label }: { dueAt: string | null; label?: string }) {
    const [now, setNow] = useState(Date.now());
    useEffect(() => { const t = setInterval(() => setNow(Date.now()), 60000); return () => clearInterval(t); }, []);
    if (!dueAt) return label ? <span className="text-xs text-muted-foreground">{label}</span> : null;
    const diff = new Date(dueAt).getTime() - now;
    if (diff < 0) return <span className="text-xs font-semibold text-status-down">{label || 'Lewat SLA'}</span>;
    if (diff < 86400000) return <span className="text-xs font-semibold text-status-degraded"><Clock className="inline h-3 w-3" /> {label || `${Math.floor(diff / 3600000)}j`}</span>;
    return <span className="text-xs text-status-up"><Clock className="inline h-3 w-3" /> {label || `${Math.floor(diff / 86400000)}h`}</span>;
}

export default function Index({ tickets, filters, technicians, statusOptions, priorityOptions, statusCounts, technicianStats, monitoringSummary, monitoringEvidence }: Props) {
    const authUser = (usePage().props as { auth?: { user?: { role?: string } } }).auth?.user;
    const isAdminLike = ['admin', 'noc', 'superadmin'].includes(authUser?.role || '');
    const isArchive = filters.status === 'archive' || filters.archive === '1' || filters.archive === 1;

    const handleFilterChange = (key: string, value: string) => {
        router.get(route('tickets.index'), {
            ...filters,
            [key]: value !== 'all' ? value : undefined,
            // Picking an active-status KPI/chip leaves the archive view; a stale archive=1 would give an empty list.
            ...(key === 'status' && value !== 'archive' && value !== 'all' ? { archive: undefined } : {}),
        }, { preserveState: false, preserveScroll: true });
    };

    const switchView = (archive: boolean) => {
        router.get(route('tickets.index'), {
            ...filters,
            status: archive ? 'archive' : undefined,
            archive: undefined,
        }, { preserveState: false, preserveScroll: true });
    };

    const sc = statusCounts || {};
    const openCount = (sc['open'] || 0) + (sc['assigned'] || 0);
    const progressCount = sc['in_progress'] || 0;
    const pendingCount = sc['pending'] || 0;
    const closedCount = (sc['resolved'] || 0) + (sc['closed'] || 0);

    const chipValue = ['open_assigned', 'in_progress', 'pending'].includes(filters.status || '') ? (filters.status as string) : 'all';

    const kpiCards = [
        { label: 'Open / Assigned', value: openCount, icon: CircleDot, onClick: () => handleFilterChange('status', 'open_assigned') },
        { label: 'Proses', value: progressCount, icon: RefreshCw, onClick: () => handleFilterChange('status', 'in_progress') },
        { label: 'Pending', value: pendingCount, icon: AlertTriangle, onClick: () => handleFilterChange('status', 'pending') },
        { label: 'Resolved / Closed', value: closedCount, icon: CheckCircle2, onClick: () => switchView(true) },
    ];

    const columns: Column<Ticket>[] = [
        { header: 'Kode', cell: (t) => <span className="font-mono text-xs font-medium">{t.code}</span> },
        { header: 'Report ID', cell: (t) => <span className="font-mono text-xs text-muted-foreground">{t.report_id || '-'}</span> },
        { header: 'Gangguan', cell: (t) => <span className="block max-w-[160px] truncate text-sm">{t.gangguan}</span> },
        {
            header: 'Pelanggan',
            cell: (t) => (
                <div className="text-sm">
                    {t.customer_name || t.customer?.name || '-'}
                    {t.customer_phone && <span className="block text-xs text-muted-foreground">{t.customer_phone}</span>}
                </div>
            ),
        },
        { header: 'Teknisi', cell: (t) => <span className="text-sm">{t.assignee?.name || '-'}</span> },
        { header: 'Prioritas', cell: (t) => priorityBadge(t.priority) },
        { header: 'Status', cell: (t) => statusBadge(t.status, false) },
        { header: 'SLA', cell: (t) => <DueBadge dueAt={t.due_at} label={t.sla_label} /> },
        {
            header: 'Tanggal',
            cell: (t) => (
                <span className="text-xs text-muted-foreground">
                    {formatWibDate(t.created_at)}
                </span>
            ),
        },
        {
            header: '',
            className: 'text-right w-[90px]',
            cell: (t) => (
                <div className="flex justify-end gap-1" onClick={(e) => e.stopPropagation()}>
                    <Button variant="ghost" size="sm" asChild>
                        <Link href={route('tickets.show', t.id)} aria-label={`Buka tiket ${t.code}`}><Eye className="h-4 w-4" aria-hidden="true" /></Link>
                    </Button>
                    <Button variant="ghost" size="sm" asChild>
                        <Link href={route('tickets.edit', t.id)} aria-label={`Edit tiket ${t.code}`}><Pencil className="h-4 w-4" aria-hidden="true" /></Link>
                    </Button>
                </div>
            ),
        },
    ];

    const filterConfigs: FilterConfig[] = [
        {
            key: 'status',
            hideOnMobile: !isArchive,
            placeholder: 'Semua Status',
            options: [
                { label: 'Semua Arsip', value: 'archive' },
                { label: 'Open + Assigned', value: 'open_assigned' },
                ...statusOptions.map((s) => ({
                    label: s === 'in_progress' ? 'Proses' : s.charAt(0).toUpperCase() + s.slice(1),
                    value: s,
                })),
            ],
        },
        {
            key: 'priority',
            placeholder: 'Semua Prioritas',
            options: priorityOptions.map((p) => ({
                label: p.charAt(0).toUpperCase() + p.slice(1),
                value: p,
            })),
        },
        {
            key: 'assigned_to',
            placeholder: 'Semua Teknisi',
            options: technicians.map((t) => ({ label: t.name, value: String(t.id) })),
        },
    ];

    return (
        <EnterpriseIndexLayout
            title="Ticketing"
            pageTitle="Ticketing"
            subtitle={isArchive ? 'Riwayat tiket selesai, terpisah dari pekerjaan aktif' : 'Pekerjaan aktif teknisi dan tiket yang masih perlu ditindaklanjuti'}
            icon={TicketCheck}
            breadcrumbs={[{ label: 'Ticketing' }]}
            actions={
                <div className="flex flex-wrap gap-2">
                    <Button asChild type="button" size="sm" className="hidden h-9 gap-2 md:inline-flex">
                        <Link href={route('tickets.create')}><Plus className="h-4 w-4" />Buat Tiket</Link>
                    </Button>
                    <Button asChild type="button" variant="outline" size="sm" className="hidden h-9 gap-2 md:inline-flex">
                        <Link href={route('device-recovery.index')}><Warehouse className="h-4 w-4" aria-hidden />Pengambilan Perangkat</Link>
                    </Button>
                    {isAdminLike && (
                        <Button asChild type="button" variant="outline" size="sm" className="h-9 gap-2">
                            <Link href={route('tickets.dismantle-reasons')}><BarChart3 className="h-4 w-4" aria-hidden />Alasan Dismantle</Link>
                        </Button>
                    )}
                </div>
            }
        >
            <div className="space-y-6">
                <div className="flex flex-col gap-3 rounded-xl border bg-card p-3 shadow-sm sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p className="text-sm font-semibold">{isArchive ? 'Arsip tiket' : 'Tiket aktif'}</p>
                        <p className="text-xs text-muted-foreground">{isArchive ? 'Tiket selesai tetap tersimpan dan dapat dicari.' : 'Tiket selesai dipindahkan dari daftar kerja utama.'}</p>
                    </div>
                    <div className="grid grid-cols-2 gap-2 sm:flex sm:items-center">
                        <MonitoringCandidatesDrawer summary={monitoringSummary} evidence={monitoringEvidence} canCreateDraft={isAdminLike} />
                        <Button type="button" size="sm" variant={!isArchive ? 'default' : 'outline'} className="gap-2" onClick={() => switchView(false)}>
                            <TicketCheck className="h-4 w-4" />Aktif
                        </Button>
                        <Button type="button" size="sm" variant={isArchive ? 'default' : 'outline'} className="gap-2" onClick={() => switchView(true)}>
                            <Archive className="h-4 w-4" />Arsip{closedCount > 0 ? ` (${closedCount})` : ''}
                        </Button>
                    </div>
                </div>

                <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                    {kpiCards.map((kpi) => (
                        <button
                            key={kpi.label}
                            type="button"
                            onClick={kpi.onClick}
                            className="flex items-center gap-3 rounded-lg border bg-card p-4 text-left shadow-[var(--shadow-card)] transition-colors hover:bg-muted/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        >
                            <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-primary-soft">
                                <kpi.icon className="h-5 w-5 text-primary-soft-foreground" aria-hidden />
                            </span>
                            <span className="min-w-0">
                                <span className="block text-xs text-muted-foreground">{kpi.label}</span>
                                <span className="block font-data text-2xl font-bold">{kpi.value}</span>
                            </span>
                        </button>
                    ))}
                </div>

                {!isArchive && (
                    <div className="md:hidden">
                        <FilterChips
                            label="Filter status tiket"
                            value={chipValue}
                            onChange={(value) => handleFilterChange('status', value)}
                            options={[
                                { value: 'all', label: 'Semua', count: openCount + progressCount + pendingCount },
                                { value: 'open_assigned', label: 'Open', count: openCount },
                                { value: 'in_progress', label: 'Proses', count: progressCount },
                                { value: 'pending', label: 'Pending', count: pendingCount },
                            ]}
                        />
                    </div>
                )}

                {technicianStats && technicianStats.length > 0 && (
                    <Card>
                        <CardHeader><CardTitle className="flex items-center gap-2 text-base"><Users className="h-4 w-4 text-primary-soft-foreground" />Performa Teknisi</CardTitle></CardHeader>
                        <CardContent className="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Nama</TableHead>
                                        <TableHead className="text-center">Total</TableHead>
                                        <TableHead className="text-center">Open</TableHead>
                                        <TableHead className="text-center">Closed</TableHead>
                                        <TableHead className="text-center">Close Rate</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {technicianStats.map(ts => (
                                        <TableRow key={ts.id}>
                                            <TableCell className="font-medium">{ts.name} <span className="text-xs text-muted-foreground">({ts.role})</span></TableCell>
                                            <TableCell className="text-center">{ts.total_assigned}</TableCell>
                                            <TableCell className="text-center"><span className="font-medium text-status-degraded">{ts.open_count}</span></TableCell>
                                            <TableCell className="text-center"><span className="font-medium text-status-up">{ts.closed_count}</span></TableCell>
                                            <TableCell className="text-center">
                                                {ts.total_assigned > 0 ? Math.round((ts.closed_count / ts.total_assigned) * 100) : 0}%
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                )}

                <DataTable
                    compact
                    data={tickets}
                    columns={columns}
                    filters={filters}
                    title="Daftar Tiket"
                    description={`${tickets.total} tiket`}
                    searchPlaceholder="Cari kode, gangguan, pelanggan..."
                    filterConfigs={filterConfigs}
                    routeName="tickets.index"
                    onRowClick={(ticket) => router.visit(route('tickets.show', ticket.id))}
                    mobileCard={(ticket) => (
                        <MobileCard
                            href={route('tickets.show', ticket.id)}
                            code={ticket.code}
                            title={<span className="line-clamp-2">{ticket.gangguan}</span>}
                            badge={statusBadge(ticket.status)}
                            meta={[
                                { icon: UserIcon, text: ticket.customer_name || ticket.customer?.name || '-' },
                                { icon: Wrench, text: ticket.assignee?.name || '-' },
                            ]}
                            actions={!isArchive ? (
                                <Button type="button" size="sm" variant="outline" className="h-9" asChild>
                                    <Link href={route('tickets.edit', ticket.id)} aria-label={`Edit tiket ${ticket.code}`}><Pencil className="mr-1 h-4 w-4" aria-hidden />Edit</Link>
                                </Button>
                            ) : undefined}
                        >
                            <span className="mt-2 flex items-center justify-between gap-3">
                                {priorityBadge(ticket.priority)}
                                <DueBadge dueAt={ticket.due_at} label={ticket.sla_label} />
                            </span>
                        </MobileCard>
                    )}
                />
            </div>
            <BottomActionBar>
                <Button asChild type="button" variant="outline" className="h-12 flex-1 gap-2">
                    <Link href={route('device-recovery.index')}><Warehouse className="h-4 w-4" aria-hidden />Pengambilan Perangkat</Link>
                </Button>
                <Button asChild type="button" className="h-12 flex-[2] gap-2">
                    <Link href={route('tickets.create')}><Plus className="h-4 w-4" aria-hidden />Buat Tiket</Link>
                </Button>
            </BottomActionBar>
        </EnterpriseIndexLayout>
    );
}
