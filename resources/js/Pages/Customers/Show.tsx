import { EnterpriseResourceLayout } from '@/Components/enterprise/EnterpriseResourceLayout';
import { Link, useForm, router, usePage } from '@inertiajs/react';
import { Button } from '@/Components/ui/button';
import { Badge } from '@/Components/ui/badge';
import { Textarea } from '@/Components/ui/textarea';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { ArrowLeft, Edit, Trash2, User, Network, MapPin, Search, ChevronLeft, MoreHorizontal, Eye, Loader2, CheckCircle2, AlertTriangle, Power } from 'lucide-react';
import { ReactNode, Suspense, lazy, useEffect, useState } from 'react';
import { toast } from 'sonner';
import { ConfirmDialog } from '@/Components/ConfirmDialog';
import { LifecycleGuardNotice, lifecycleOverrideReady } from '@/Components/LifecycleGuardNotice';
import { StatusBadge, type StatusTone } from '@/Components/StatusBadge';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from "@/Components/ui/dropdown-menu";
// C11: react-leaflet + CSS (`vendor-map`) only load when the customer has coordinates.
const MapPicker = lazy(() => import('@/Components/MapPicker'));
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from "@/Components/ui/dialog";
import { PageProps } from '@/types';
import { customerStatusLabel, customerStatusTone, normalizeCustomerStatus, type CustomerStatus } from '@/lib/customerLabels';
import { isPastDueWib } from '@/lib/dateTime';
import { formatDate, formatIdr, formatMonth } from '@/lib/format';

// Types
interface PaymentReversal {
    id: number;
    amount: number;
    reason?: string | null;
    reversed_at?: string | null;
}

interface Transaction {
    id: number;
    amount: number;
    method: string;
    status?: string | null;
    paid_at: string;
    reversals?: PaymentReversal[];
}

interface Invoice {
    id: number;
    period: string;
    amount: number;
    status: 'unpaid' | 'paid' | 'void';
    due_date: string;
    transactions: Transaction[];
}

interface Package {
    id: number;
    name: string;
    price: number;
    mikrotik_profile?: string | null;
}

interface Customer {
    id: number;
    name: string;
    code: string;
    address: string;
    phone: string;
    nik?: string;
    pppoe_user: string;
    status: CustomerStatus;
    geo_lat: string;
    geo_long: string;
    join_date: string | null;
    mikrotik_profile?: string | null;
    previous_profile?: string | null;
    mikrotik_sync_status?: 'unknown' | 'synced' | 'missing' | null;
    mikrotik_sync_checked_at?: string | null;
    olt_id?: number | null;
    olt_port_label?: string | null;
    onu_serial?: string | null;
    olt_status?: string | null;
    onu_rx_power_dbm?: string | null;
    onu_tx_power_dbm?: string | null;
    fiber_distance_m?: number | null;
    olt_last_synced_at?: string | null;
    /** Backend may return null when package_id empty — do not assume present */
    package: Package | null;
    area?: { id: number; name: string } | null;
    router?: {
        id: number;
        name: string;
        connection_status: 'unknown' | 'online' | 'offline';
        is_active: boolean;
    } | null;
    olt?: {
        id: number;
        name: string;
        code: string;
        management_ip?: string | null;
    } | null;
    invoices: Invoice[] | null;
    invoices_count?: number;
    ktp_photo_url?: string | null;
}

export interface PendingTermination {
    id: number;
    source: string;
    source_label: string;
    requested_at: string | null;
    requested_at_label: string;
    last_checked_at: string | null;
    last_checked_label: string;
    last_verdict: string | null;
    last_reasons: string[];
    last_reason_label: string;
    hold_billing: boolean;
    can_cancel: boolean;
}

interface Props {
    customer: Customer;
    pendingTermination?: PendingTermination | null;
}

// C3: hanya transaksi settled (verified/paid) tanpa reversal penuh yang dihitung sebagai uang masuk.
const SETTLED_TRANSACTION_STATUSES = ['verified', 'paid'];

function paymentRowState(txn: Transaction): { label: string; tone: StatusTone; counted: boolean; note?: string } {
    const amount = Number(txn.amount) || 0;
    const reversed = (txn.reversals ?? []).reduce((sum, r) => sum + (Number(r.amount) || 0), 0);
    const status = String(txn.status ?? 'verified').toLowerCase();

    if (reversed > 0 && reversed >= amount) {
        return { label: 'Dibatalkan', tone: 'down', counted: false, note: txn.reversals?.[0]?.reason || undefined };
    }

    if (!SETTLED_TRANSACTION_STATUSES.includes(status)) {
        return {
            label: status === 'pending' ? 'Menunggu verifikasi' : status === 'failed' ? 'Gagal' : status,
            tone: status === 'pending' ? 'degraded' : 'down',
            counted: false,
        };
    }

    if (reversed > 0) {
        return {
            label: 'Dibatalkan sebagian',
            tone: 'degraded',
            counted: true,
            note: `${formatIdr(reversed)} dibatalkan, bersih ${formatIdr(amount - reversed)}`,
        };
    }

    return { label: 'Diterima', tone: 'up', counted: true };
}

export default function Show({ customer, pendingTermination = null }: Props) {
    const { auth } = usePage<PageProps>().props;
    const cancelPendingForm = useForm({ reason: '', pending_id: pendingTermination?.id ?? 0 });
    const [cancelPendingOpen, setCancelPendingOpen] = useState(false);
    const submitCancelPending = () => {
        // Always the id of the row this page rendered (the server rejects a changed row).
        cancelPendingForm.transform((data) => ({ ...data, pending_id: pendingTermination?.id ?? 0 }));
        cancelPendingForm.post(route('customers.pending-termination.cancel', customer.id), {
            preserveScroll: true,
            onSuccess: () => {
                cancelPendingForm.reset('reason');
                setCancelPendingOpen(false);
            },
        });
    };
    const isAdmin = auth.user.role === 'admin' || auth.user.role === 'superadmin';
    const isSuperadmin = auth.user.role === 'superadmin';
    // Archive: the server's lifecycle guard may refuse (customer still in use / unknown);
    // a superadmin can then override with a written reason (data.override + data.override_reason).
    const archiveForm = useForm({ override: false, override_reason: '' });
    const { delete: destroy } = archiveForm;
    const guardMessage = (archiveForm.errors as Record<string, string | undefined>).lifecycle_guard;
    const overrideReady = lifecycleOverrideReady(archiveForm.data.override_reason, archiveForm.data.override);
    const [loading, setLoading] = useState(false);
    const invoices = customer.invoices ?? [];
    const invoiceTotal = customer.invoices_count ?? invoices.length;
    const invoicesTruncated = invoiceTotal > invoices.length;
    const paymentRows = invoices
        .flatMap((inv) => (inv.transactions ?? []).map((t) => ({ ...t, invoice_id: inv.id })))
        .sort((a, b) => new Date(b.paid_at).getTime() - new Date(a.paid_at).getTime());
    const pkg = customer.package;
    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);

    const handleDelete = () => {
        destroy(route('customers.destroy', customer.id), { preserveScroll: true });
    };
    // Forget a previous denial / typed override when the dialog closes (form helpers are not
    // referentially stable, so only the open flag is a dependency).
    useEffect(() => {
        if (!deleteDialogOpen) {
            archiveForm.reset();
            archiveForm.clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [deleteDialogOpen]);

    const [confirmOpen, setConfirmOpen] = useState(false);
    const [confirmAction, setConfirmAction] = useState<'block' | 'unblock' | null>(null);
    const hasRouter = !!customer.router;
    const hasPppoeUser = !!customer.pppoe_user?.trim();
    const canEnforceOnMikrotik = hasRouter && hasPppoeUser;
    const enforcementBlockers = [
        !hasRouter ? 'Assign a MikroTik router.' : null,
        !hasPppoeUser ? 'Set a PPPoE username.' : null,
    ].filter((reason): reason is string => Boolean(reason));
    const syncWarning = customer.mikrotik_sync_status === 'missing'
        ? 'Last sync did not find this PPPoE user on MikroTik. The action may fail until the router record is fixed or synced again.'
        : customer.mikrotik_sync_status === 'unknown'
            ? 'This customer has not been verified against MikroTik yet.'
            : null;
    const enforcementLabel = canEnforceOnMikrotik ? 'Ready' : 'Needs setup';
    const enforcementBadgeClass = canEnforceOnMikrotik
        ? 'border-status-up/30 bg-status-up-bg text-status-up'
        : 'border-status-degraded/30 bg-status-degraded-bg text-status-degraded';

    // C2: "Isolir" only from active, "Buka isolir" only from isolated.
    // Terminated / pending_installation / dismantled customers must not be
    // switched on by a single click here (backend guard lands in F0 PR-A).
    const [toggleBusy, setToggleBusy] = useState(false);
    const serviceIsActive = customer.status === 'active';
    const serviceIsIsolated = (customer.status as string) === 'isolated' || (customer.status as string) === 'isolir';
    const canToggleService = serviceIsActive || serviceIsIsolated;
    const toggleBlockedReason = canToggleService
        ? null
        : `Buka isolir hanya untuk pelanggan berstatus Isolir. Status sekarang: ${customerStatusLabel(customer.status)}.`;
    const pppoeTarget = `${customer.pppoe_user || '-'} di router ${customer.router?.name || '-'}`;

    const handleToggleBlock = () => {
        if (!canEnforceOnMikrotik || !canToggleService || toggleBusy) {
            return;
        }

        const isActive = customer.status === 'active';
        setConfirmAction(isActive ? 'block' : 'unblock');
        setConfirmOpen(true);
    };

    const confirmToggle = () => {
        if (toggleBusy) return;
        if (confirmAction === 'unblock' && !serviceIsIsolated) return;
        if (confirmAction === 'block' && !serviceIsActive) return;

        const target = confirmAction === 'block'
            ? route('customers.isolate', customer.id)
            : route('customers.reconnect', customer.id);
        setToggleBusy(true);
        router.post(target, {}, {
            onFinish: () => {
                setToggleBusy(false);
                setConfirmOpen(false);
            },
        });
    };

    const getStatusBadge = (status: string) => {
        const tones: Record<string, StatusTone> = {
            params: 'up',
            paid: 'up',
            unpaid: 'down',
            void: 'neutral',
            synced: 'up',
            missing: 'down',
            unknown: 'neutral',
        };

        return <StatusBadge label={customerStatusLabel(status)} tone={tones[status] ?? (normalizeCustomerStatus(status) ? customerStatusTone(status) : 'neutral')} />;
    };

    const DetailRow = ({ label, children }: { label: string; children: ReactNode }) => (
        <div className="flex items-start justify-between gap-4">
            <span className="shrink-0 text-muted-foreground">{label}</span>
            <div className="min-w-0 text-right font-medium">{children}</div>
        </div>
    );

    const ProfileValue = ({ value }: { value?: string | null }) => {
        if (!value) {
            return <span className="text-muted-foreground">-</span>;
        }

        return (
            <span className="inline-block max-w-full truncate rounded bg-muted px-2 py-1 font-mono text-xs">
                {value}
            </span>
        );
    };

    return (
        <EnterpriseResourceLayout
            title={customer.name}
            pageTitle={`Customer: ${customer.name}`}
            subtitle={`ID: ${customer.code} | PPPoE: ${customer.pppoe_user}`}
            icon={User}
            backHref={route('customers.index')}
            breadcrumbs={[
                { label: 'Customers', href: route('customers.index') },
                { label: customer.name },
            ]}
            actions={isAdmin ? (
                <div className="flex flex-wrap items-center gap-2">
                    <Link href={route('customers.edit', customer.id)}>
                        <Button variant="outline" size="sm">
                            <Edit className="mr-2 h-4 w-4" />
                            Ubah Pelanggan
                        </Button>
                    </Link>
                    <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
                        <DialogTrigger asChild>
                            <Button variant="destructive" size="sm">
                                <Trash2 className="mr-2 h-4 w-4" />
                                Arsipkan
                            </Button>
                        </DialogTrigger>
                        <DialogContent className="border-destructive/50">
                            <DialogHeader>
                                <DialogTitle>Arsipkan pelanggan ini?</DialogTitle>
                                <DialogDescription>
                                    <strong>{customer.name}</strong> akan dipindahkan ke arsip dan hilang dari daftar aktif.
                                    Data invoice dan pembayaran tetap tersimpan; pelanggan bisa dipulihkan dari tab Arsip.
                                    Pelanggan yang masih aktif harus diisolir/dismantle dulu.
                                </DialogDescription>
                            </DialogHeader>
                            {guardMessage && (
                                <LifecycleGuardNotice
                                    message={guardMessage}
                                    isSuperadmin={isSuperadmin}
                                    reason={archiveForm.data.override_reason}
                                    onReasonChange={(value) => archiveForm.setData('override_reason', value)}
                                    checked={archiveForm.data.override}
                                    onCheckedChange={(value) => archiveForm.setData('override', value)}
                                    reasonError={archiveForm.errors.override_reason as string | undefined}
                                    disabled={archiveForm.processing}
                                />
                            )}
                            <DialogFooter>
                                <Button variant="outline" onClick={() => setDeleteDialogOpen(false)}>Batal</Button>
                                <Button
                                    variant="destructive"
                                    onClick={handleDelete}
                                    disabled={archiveForm.processing || (!!guardMessage && !(isSuperadmin && overrideReady))}
                                >
                                    {guardMessage && isSuperadmin ? 'Tetap arsipkan (override)' : 'Arsipkan'}
                                </Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
                </div>
            ) : undefined}
        >
                {pendingTermination && (
                    <Card className="mb-6 border-status-degraded/40 bg-status-degraded-bg" data-testid="pending-termination-card">
                        <CardHeader>
                            <CardTitle className="text-base flex items-center gap-2 text-status-degraded">
                                <AlertTriangle className="h-4 w-4" />
                                Terminate tertunda
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3 text-sm">
                            <p>
                                Terminate tertunda sejak {pendingTermination.requested_at_label} ({pendingTermination.source_label}).
                                {' '}Alasan terakhir: {pendingTermination.last_reason_label} (dicek {pendingTermination.last_checked_label}).
                                {' '}Tagihan baru ditahan; pelanggan tidak diisolir. Sistem akan menterminate otomatis setelah pelanggan terbukti tidak memakai layanan (PPPoE mati, ONU mati, tidak ada trafik 2 hari).
                            </p>
                            {pendingTermination.can_cancel && (
                                <Dialog open={cancelPendingOpen} onOpenChange={setCancelPendingOpen}>
                                    <DialogTrigger asChild>
                                        <Button variant="outline" size="sm">Batalkan (pelanggan masih berlangganan)</Button>
                                    </DialogTrigger>
                                    <DialogContent>
                                        <DialogHeader>
                                            <DialogTitle>Batalkan terminate tertunda?</DialogTitle>
                                            <DialogDescription>
                                                Pelanggan tetap berlangganan. Tagihan bulan berjalan dibuat sekarang (jika belum ada) dan aturan isolir normal berlaku lagi — tunggakan lama bisa langsung memicu isolir. Alasan (minimal 10 karakter).
                                            </DialogDescription>
                                        </DialogHeader>
                                        <Textarea
                                            value={cancelPendingForm.data.reason}
                                            onChange={(e) => cancelPendingForm.setData('reason', e.target.value)}
                                            rows={3}
                                            aria-label="Alasan pembatalan"
                                        />
                                        {cancelPendingForm.errors.reason && (
                                            <p className="text-sm text-destructive">{cancelPendingForm.errors.reason}</p>
                                        )}
                                        <DialogFooter>
                                            <Button variant="outline" onClick={() => setCancelPendingOpen(false)}>Tutup</Button>
                                            <Button
                                                onClick={submitCancelPending}
                                                disabled={cancelPendingForm.processing || cancelPendingForm.data.reason.trim().length < 10}
                                            >
                                                Batalkan terminate tertunda
                                            </Button>
                                        </DialogFooter>
                                    </DialogContent>
                                </Dialog>
                            )}
                        </CardContent>
                    </Card>
                )}
                <Tabs defaultValue="overview" className="space-y-6">
                    <div className="flex items-center justify-between">
                        <TabsList className="bg-card border border-border">
                            <TabsTrigger value="overview">Overview</TabsTrigger>
                            <TabsTrigger value="invoices">Invoice ({invoiceTotal})</TabsTrigger>
                            <TabsTrigger value="payments">Riwayat Bayar</TabsTrigger>
                        </TabsList>
                    </div>

                    {/* OVERVIEW TAB */}
                    <TabsContent value="overview" className="space-y-6">
                        <div className="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
                            {/* Personal Info */}
                            <Card className="bg-card border-border">
                                <CardHeader>
                                    <CardTitle className="flex items-center gap-2">
                                        <User className="h-4 w-4 text-primary" />
                                        Personal Information
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-4 text-sm">
                                    <div className="grid grid-cols-3 gap-1">
                                        <span className="text-muted-foreground">Full Name</span>
                                        <span className="col-span-2 font-medium">{customer.name}</span>
                                    </div>
                                    {isAdmin && (
                                        <div className="grid grid-cols-3 gap-1">
                                            <span className="text-muted-foreground">NIK</span>
                                            <span className="col-span-2 font-mono">{customer.nik || '-'}</span>
                                        </div>
                                    )}
                                    <div className="grid grid-cols-3 gap-1">
                                        <span className="text-muted-foreground">Phone</span>
                                        <span className="col-span-2 font-mono">{customer.phone || '-'}</span>
                                    </div>
                                    <div className="grid grid-cols-3 gap-1">
                                        <span className="text-muted-foreground">Area</span>
                                        <span className="col-span-2 font-medium">{customer.area?.name || '-'}</span>
                                    </div>
                                    <div className="grid grid-cols-3 gap-1">
                                        <span className="text-muted-foreground">Address</span>
                                        <span className="col-span-2">{customer.address}</span>
                                    </div>
                                    <div className="grid grid-cols-3 gap-1">
                                        <span className="text-muted-foreground">Joined</span>
                                        <span className="col-span-2">{formatDate(customer.join_date)}</span>
                                    </div>
                                </CardContent>
                            </Card>

                            {/* Service Info */}
                            <Card className="bg-card border-border">
                                <CardHeader>
                                    <CardTitle className="flex items-center gap-2">
                                        <Network className="h-4 w-4 text-muted-foreground" />
                                        Service Details
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-4 text-sm">
                                    <DetailRow label="Status">
                                        {getStatusBadge(customer.status)}
                                    </DetailRow>
                                    <DetailRow label="Package">
                                        <span className="block truncate">{pkg?.name ?? '—'}</span>
                                    </DetailRow>
                                    <DetailRow label="Price">
                                        {pkg ? `${formatIdr(pkg.price)} / mo` : '—'}
                                    </DetailRow>
                                    <DetailRow label="Package Profile">
                                        <ProfileValue value={pkg?.mikrotik_profile} />
                                    </DetailRow>
                                    <DetailRow label="Router Profile">
                                        <ProfileValue value={customer.mikrotik_profile || 'Unknown'} />
                                    </DetailRow>
                                    {customer.previous_profile && (
                                        <DetailRow label="Previous Profile">
                                            <ProfileValue value={customer.previous_profile} />
                                        </DetailRow>
                                    )}
                                </CardContent>
                            </Card>

                            {isAdmin && (
                                <Card className="bg-card border-border">
                                    <CardHeader>
                                        <div className="flex items-start justify-between gap-3">
                                            <div>
                                                <CardTitle className="flex items-center gap-2">
                                                    <Power className="h-4 w-4 text-status-degraded" />
                                                    Network Enforcement
                                                </CardTitle>
                                                <CardDescription className="mt-1">
                                                    MikroTik isolation and reconnection readiness
                                                </CardDescription>
                                            </div>
                                            <Badge variant="outline" className={`shrink-0 border ${enforcementBadgeClass}`}>
                                                {enforcementLabel}
                                            </Badge>
                                        </div>
                                    </CardHeader>
                                    <CardContent className="space-y-4 text-sm">
                                        <div className="space-y-3">
                                            <div className="grid grid-cols-3 gap-1">
                                                <span className="text-muted-foreground">Router</span>
                                                <span className="col-span-2 font-medium">{customer.router?.name || '-'}</span>
                                            </div>
                                            <div className="grid grid-cols-3 gap-1">
                                                <span className="text-muted-foreground">PPPoE User</span>
                                                <span className="col-span-2 font-mono text-xs bg-muted px-2 py-0.5 rounded w-fit">
                                                    {customer.pppoe_user || '-'}
                                                </span>
                                            </div>
                                            <div className="grid grid-cols-3 gap-1 items-center">
                                                <span className="text-muted-foreground">Sync Status</span>
                                                <span className="col-span-2">{getStatusBadge(customer.mikrotik_sync_status || 'unknown')}</span>
                                            </div>
                                            <div className="grid grid-cols-3 gap-1">
                                                <span className="text-muted-foreground">Last Checked</span>
                                                <span className="col-span-2">
                                                    {customer.mikrotik_sync_checked_at ? formatDate(customer.mikrotik_sync_checked_at) : '-'}
                                                </span>
                                            </div>
                                        </div>

                                        {!canEnforceOnMikrotik && (
                                            <div className="rounded-md border border-status-degraded/30 bg-status-degraded-bg px-3 py-2 text-status-degraded">
                                                <div className="flex gap-2">
                                                    <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                                                    <p>{enforcementBlockers.join(' ')}</p>
                                                </div>
                                            </div>
                                        )}

                                        {canEnforceOnMikrotik && syncWarning && (
                                            <div className="flex gap-2 rounded-md border border-status-degraded/30 bg-status-degraded-bg px-3 py-2 text-status-degraded">
                                                <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                                                <p>{syncWarning}</p>
                                            </div>
                                        )}

                                        <div className="flex flex-wrap justify-end gap-2 border-t border-border/50 pt-4">
                                            {!canEnforceOnMikrotik && (
                                                <Button asChild variant="outline" size="sm">
                                                    <Link href={route('customers.edit', customer.id)}>
                                                        Edit Network Info
                                                    </Link>
                                                </Button>
                                            )}
                                            <Button
                                                variant={serviceIsActive ? 'destructive' : 'default'}
                                                size="sm"
                                                onClick={handleToggleBlock}
                                                disabled={!canEnforceOnMikrotik || !canToggleService || toggleBusy}
                                                title={toggleBlockedReason ?? undefined}
                                            >
                                                {toggleBusy && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                                                {serviceIsActive ? 'Isolir layanan' : 'Buka isolir'}
                                            </Button>
                                        </div>
                                        {toggleBlockedReason && (
                                            <p className="text-right text-xs text-muted-foreground" data-testid="customer-toggle-blocked-reason">
                                                {toggleBlockedReason}
                                            </p>
                                        )}
                                    </CardContent>
                                </Card>
                            )}

                            {/* Geo Info */}
                            <Card className="bg-card border-border">
                                <CardHeader>
                                    <CardTitle className="flex items-center gap-2">
                                        <MapPin className="h-4 w-4 text-muted-foreground" />
                                        Location
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-4 text-sm">
                                    <div className="grid grid-cols-3 gap-1">
                                        <span className="text-muted-foreground">Latitude</span>
                                        <span className="col-span-2 font-mono">{customer.geo_lat || 'N/A'}</span>
                                    </div>
                                    <div className="grid grid-cols-3 gap-1">
                                        <span className="text-muted-foreground">Longitude</span>
                                        <span className="col-span-2 font-mono">{customer.geo_long || 'N/A'}</span>
                                    </div>
                                    <div className="mt-4 space-y-4">
                                        <div className="rounded-md overflow-hidden border border-border h-48">
                                            {customer.geo_lat && customer.geo_long ? (
                                                <Suspense fallback={<div className="h-full w-full bg-muted" role="status" aria-label="Memuat peta" />}>
                                                    <MapPicker
                                                        initialLat={Number(customer.geo_lat)}
                                                        initialLong={Number(customer.geo_long)}
                                                    />
                                                </Suspense>
                                            ) : (
                                                <div className="h-full w-full flex items-center justify-center bg-muted text-muted-foreground text-sm">
                                                    No coordinates available
                                                </div>
                                            )}
                                        </div>
                                        <Button variant="outline" size="sm" className="w-full" disabled={!customer.geo_lat} onClick={() => window.open(`https://www.google.com/maps?q=${customer.geo_lat},${customer.geo_long}`, '_blank')}>
                                            Open in Google Maps
                                        </Button>
                                    </div>
                                </CardContent>
                            </Card>

                            {/* KTP Photo Card */}
                            {isAdmin && (
                            <Card className="bg-card border-border">
                                <CardHeader>
                                    <CardTitle className="flex items-center gap-2">
                                        <User className="h-4 w-4 text-status-info" />
                                        KTP Photo
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    {customer.ktp_photo_url ? (
                                        <div className="space-y-3">
                                            <div className="rounded-lg border border-border overflow-hidden h-48 bg-muted/30 flex items-center justify-center">
                                                <img
                                                    src={customer.ktp_photo_url}
                                                    alt="KTP Photo"
                                                    className="w-full h-full object-cover"
                                                />
                                            </div>
                                            <Button variant="outline" size="sm" className="w-full" onClick={() => window.open(customer.ktp_photo_url!, '_blank')}>
                                                <Eye className="w-3.5 h-3.5 mr-2" />
                                                View Full Image
                                            </Button>
                                        </div>
                                    ) : (
                                        <div className="flex flex-col items-center justify-center h-48 border-2 border-dashed border-border/50 rounded-lg bg-muted/20 text-muted-foreground gap-2">
                                            <User className="h-8 w-8 opacity-20" />
                                            <span className="text-xs">No KTP Photo Uploaded</span>
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                            )}
                        </div>
                    </TabsContent>

                    {/* INVOICES TAB */}
                    <TabsContent value="invoices">
                        <Card className="bg-card border-border">
                            <CardHeader>
                                <CardTitle>Riwayat Invoice</CardTitle>
                                <CardDescription>
                                    {invoicesTruncated
                                        ? `Menampilkan ${invoices.length} invoice terakhir dari ${invoiceTotal}.`
                                        : 'Semua tagihan yang pernah dibuat untuk pelanggan ini.'}
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <Table>
                                    <TableHeader>
                                        <TableRow className="border-border hover:bg-transparent">
                                            <TableHead>No. Invoice</TableHead>
                                            <TableHead>Periode</TableHead>
                                            <TableHead className="text-right">Nominal</TableHead>
                                            <TableHead>Jatuh Tempo</TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead><span className="sr-only">Aksi</span></TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {invoices.length === 0 ? (
                                            <TableRow>
                                                <TableCell colSpan={6} className="h-24 text-center text-muted-foreground">
                                                    Belum ada invoice.
                                                </TableCell>
                                            </TableRow>
                                        ) : (
                                            invoices.map((inv) => (
                                                <TableRow key={inv.id} className="border-border hover:bg-muted/50">
                                                    <TableCell className="font-mono">INV-{String(inv.id).padStart(6, '0')}</TableCell>
                                                    <TableCell>{formatMonth(inv.period)}</TableCell>
                                                    <TableCell className="text-right tabular-nums">{formatIdr(inv.amount)}</TableCell>
                                                    <TableCell className={isPastDueWib(inv.due_date) && inv.status === 'unpaid' ? 'text-destructive font-bold' : ''}>
                                                        {formatDate(inv.due_date)}
                                                    </TableCell>
                                                    <TableCell>{getStatusBadge(inv.status)}</TableCell>
                                                    <TableCell className="text-right">
                                                        <DropdownMenu>
                                                            <DropdownMenuTrigger asChild>
                                                                <Button variant="ghost" className="h-8 w-8 p-0">
                                                                    <span className="sr-only">Open menu</span>
                                                                    <MoreHorizontal className="h-4 w-4" />
                                                                </Button>
                                                            </DropdownMenuTrigger>
                                                            <DropdownMenuContent align="end">
                                                                <DropdownMenuItem onClick={() => router.visit(route('invoices.show', inv.id))}>
                                                                    <Eye className="mr-2 h-4 w-4" />
                                                                    Lihat Invoice
                                                                </DropdownMenuItem>
                                                            </DropdownMenuContent>
                                                        </DropdownMenu>
                                                    </TableCell>
                                                </TableRow>
                                            ))
                                        )}
                                    </TableBody>
                                </Table>
                            </CardContent>
                        </Card>
                    </TabsContent>

                    {/* PAYMENTS TAB (Derived from invoice transactions) */}
                    <TabsContent value="payments">
                        <Card className="bg-card border-border">
                            <CardHeader>
                                <CardTitle>Riwayat Pembayaran</CardTitle>
                                <CardDescription>
                                    Transaksi pada {invoicesTruncated ? `${invoices.length} invoice terakhir` : 'semua invoice'}. Transaksi yang dibatalkan (reversal) atau belum terverifikasi tidak dihitung sebagai uang masuk.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <Table>
                                    <TableHeader>
                                        <TableRow className="border-border hover:bg-transparent">
                                            <TableHead>Tanggal</TableHead>
                                            <TableHead>No. Invoice</TableHead>
                                            <TableHead>Metode</TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead className="text-right">Nominal</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {paymentRows.length === 0 ? (
                                            <TableRow>
                                                <TableCell colSpan={5} className="h-24 text-center text-muted-foreground">
                                                    Belum ada pembayaran tercatat.
                                                </TableCell>
                                            </TableRow>
                                        ) : (
                                            paymentRows.map((txn) => {
                                                const state = paymentRowState(txn);

                                                return (
                                                    <TableRow key={txn.id} className="border-border hover:bg-muted/50">
                                                        <TableCell>{formatDate(txn.paid_at)}</TableCell>
                                                        <TableCell className="font-mono">INV-{String(txn.invoice_id).padStart(6, '0')}</TableCell>
                                                        <TableCell className="capitalize">{String(txn.method ?? '-').replace('_', ' ')}</TableCell>
                                                        <TableCell>
                                                            <StatusBadge tone={state.tone} label={state.label} />
                                                            {state.note && <p className="mt-1 text-xs text-muted-foreground">{state.note}</p>}
                                                        </TableCell>
                                                        <TableCell
                                                            className={
                                                                state.counted
                                                                    ? 'text-right font-medium tabular-nums text-status-up'
                                                                    : 'text-right tabular-nums text-muted-foreground line-through'
                                                            }
                                                        >
                                                            {state.counted ? '+ ' : ''}
                                                            {formatIdr(txn.amount)}
                                                        </TableCell>
                                                    </TableRow>
                                                );
                                            })
                                        )}
                                    </TableBody>
                                </Table>
                                {invoicesTruncated && (
                                    <p className="mt-3 text-sm text-muted-foreground">
                                        Riwayat lebih lama:{' '}
                                        <Link href={route('invoices.index', { search: customer.code })} className="font-medium text-primary underline-offset-4 hover:underline">
                                            lihat semua invoice pelanggan ini
                                        </Link>
                                        .
                                    </p>
                                )}
                            </CardContent>
                        </Card>
                    </TabsContent>
                </Tabs>

            {isAdmin && (
                <ConfirmDialog
                    open={confirmOpen}
                    onOpenChange={setConfirmOpen}
                    title={confirmAction === 'block' ? 'Isolir layanan pelanggan?' : 'Buka isolir pelanggan?'}
                    description={confirmAction === 'block'
                        ? `Profil PPPoE ${pppoeTarget} diganti ke profil isolir dan sesi yang sedang aktif diputus (kick). Internet pelanggan langsung terputus dan diarahkan ke portal isolir. Status menjadi Isolir.`
                        : `Profil PPPoE ${pppoeTarget} dikembalikan ke profil paket dan sesi diputus (kick) agar pelanggan tersambung ulang. Status menjadi Aktif. Aksi ini tidak mencatat pembayaran; pastikan tagihan sudah lunas atau ada alasan yang sah.`}
                    confirmText={confirmAction === 'block' ? 'Isolir sekarang' : 'Buka isolir'}
                    variant={confirmAction === 'block' ? 'destructive' : 'default'}
                    onConfirm={confirmToggle}
                    isLoading={toggleBusy}
                />
            )}
        </EnterpriseResourceLayout>
    );
}
