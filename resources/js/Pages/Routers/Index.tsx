import { useState } from 'react';
import { EnterpriseIndexLayout } from '@/Components/enterprise/EnterpriseIndexLayout';
import { Link, router } from '@inertiajs/react';
import { Button } from '@/Components/ui/button';
import { Router as RouterIcon, Server, RefreshCw } from 'lucide-react';
import DataTable, { Column, FilterConfig, PaginatedData } from '@/Components/DataTable';
import { EditAction, DeleteAction } from '@/Components/TableActions';
import { ConfirmDestructive } from '@/Components/ConfirmDestructive';
import { RouterStatusBadge, SyncStatus } from '@/Components/RouterStatusBadge';
import { reloadOnly, usePollWhileVisible } from '@/hooks/usePollWhileVisible';
import { PollStoppedNotice } from '@/Components/PollStoppedNotice';

interface Router {
    id: number;
    name: string;
    ip_address: string;
    port: number;
    is_active: boolean;
    connection_status: 'unknown' | 'online' | 'offline';
    customers_count?: number;
    current_online_count: number;
    total_pppoe_count: number;
    cpu_load: number | null;
    sync_status: SyncStatus;
    sync_message: string | null;
    sync_lock_until: string | null;
    /** Plan C pulse metadata */
    mt_pulse_active?: boolean;
    mt_pulse_stale_s?: number | null;
    mt_pulse_quality?: string | null;
    mt_pulse_source?: string | null;
}

interface Props {
    routers: PaginatedData<Router>;
    filters: {
        search?: string;
        status?: string;
        sort?: string;
        direction?: 'asc' | 'desc';
    };
    mt_plan_c?: {
        use_snapshot_ui?: boolean;
        max_stale_seconds?: number;
    };
}

export default function Index({ routers, filters = {} }: Props) {
    const [isSyncingAll, setIsSyncingAll] = useState(false);

    // R3: hapus router wajib ketik nama router.
    const [routerToDelete, setRouterToDelete] = useState<Router | null>(null);
    const [deleting, setDeleting] = useState(false);

    const hasActiveSync = routers.data.some((routerItem) => ['queued', 'running'].includes(routerItem.sync_status));

    // R1: 3 s → 15 s backoff, pause while the tab is hidden, stop after 5 min
    // so a sync stuck on "running" no longer polls forever.
    const syncPoll = usePollWhileVisible(() => reloadOnly(['routers']), 3000, {
        enabled: hasActiveSync,
        backoff: 1.5,
        maxIntervalMs: 15000,
        maxMs: 5 * 60 * 1000,
    });

    const handleSyncAll = () => {
        setIsSyncingAll(true);
        router.post(route('routers.sync-all'), {}, {
            preserveScroll: true,
            preserveState: true,
            only: ['routers', 'flash'],
            onFinish: () => setIsSyncingAll(false),
        });
    };

    const handleDelete = (routerItem: Router) => {
        setRouterToDelete(routerItem);
    };

    const confirmDelete = () => {
        if (routerToDelete) {
            router.delete(route('routers.destroy', routerToDelete.id), {
                onStart: () => setDeleting(true),
                onFinish: () => {
                    setDeleting(false);
                    setRouterToDelete(null);
                }
            });
        }
    };

    const getRouterSyncStatus = (routerItem: Router): SyncStatus => {
        return routerItem.sync_status || 'idle';
    };

    const routerTotalLabel = (routerData: Router) => {
        const secrets = routerData.total_pppoe_count || 0;
        const customers = routerData.customers_count || 0;
        return secrets > 0 ? secrets : customers;
    };

    const columns: Column<Router>[] = [
        {
            header: "Name",
            accessorKey: "name",
            sortable: true,
            cell: (routerData) => (
                <div className="flex items-center gap-2">
                    <div className="flex flex-col">
                        <span className="font-medium">{routerData.name}</span>
                        <span className="text-xs text-muted-foreground">
                            {routerData.connection_status === 'online' ? 'Connected' :
                                routerData.connection_status === 'offline' ? 'Tidak Terjangkau' :
                                    'Belum Dicek'} • IP: {routerData.ip_address}
                        </span>
                    </div>
                </div>
            ),
        },
        {
            header: "Status",
            className: "w-[150px]",
            cell: (routerData) => (
                <RouterStatusBadge
                    connectionStatus={routerData.connection_status}
                    syncStatus={getRouterSyncStatus(routerData)}
                    syncMessage={routerData.sync_message}
                    cpuLoad={routerData.cpu_load}
                />
            ),
        },
        {
            header: "Online / Secret",
            accessorKey: "current_online_count",
            sortable: true,
            className: "text-right w-[150px]",
            cell: (routerData) => {
                const total = routerTotalLabel(routerData);
                const online = routerData.current_online_count ?? 0;
                const activeAboveSecrets = total > 0 && online > total;
                const needsResync = activeAboveSecrets && getRouterSyncStatus(routerData) !== 'success';
                // 0 online + many secrets + reachable is suspicious (health failed previously wrote 0)
                const zeroSuspicious =
                    online === 0 &&
                    total >= 20 &&
                    routerData.connection_status === 'online' &&
                    getRouterSyncStatus(routerData) === 'success';
                const offlineStale =
                    routerData.connection_status === 'offline' && total > 0;
                return (
                    <div className="flex flex-col items-end">
                        <div className="flex items-center gap-1 font-semibold">
                            <span className={activeAboveSecrets || zeroSuspicious ? 'text-status-degraded' : 'text-status-up'}>{online}</span>
                            <span className="text-muted-foreground">/ {total}</span>
                        </div>
                        {needsResync && (
                            <span className="text-[10px] text-status-degraded" title="Secret di DB mungkin stale — Sinkron ulang">
                                perlu sync
                            </span>
                        )}
                        {activeAboveSecrets && (
                            <span className="text-[10px] text-status-degraded" title="Sesi aktif lebih banyak dari secret (multi-session / secret terhapus)">
                                multi-sesi?
                            </span>
                        )}
                        {zeroSuspicious && (
                            <span className="text-[10px] text-status-degraded" title="Online 0 padahal secret banyak">
                                cek ulang
                            </span>
                        )}
                        {offlineStale && (
                            <span className="text-[10px] text-muted-foreground" title="Router tidak terjangkau">
                                last-known
                            </span>
                        )}
                        {typeof routerData.mt_pulse_stale_s === 'number'
                            && routerData.mt_pulse_stale_s > 180
                            && routerData.connection_status === 'online' && (
                            <span className="text-[10px] text-muted-foreground" title="Data monitoring agak lama">
                                data lama
                            </span>
                        )}
                    </div>
                );
            },
        },
        {
            header: "Aksi",
            className: "text-right w-[100px]",
            cell: (routerData) => (
                <div className="flex items-center justify-end gap-2">
                    <EditAction
                        onClick={() => router.visit(route('routers.edit', routerData.id))}
                        title="Edit Router"
                    />
                    <DeleteAction
                        onClick={() => handleDelete(routerData)}
                        title="Delete Router"
                    />
                </div>
            ),
        },
    ];

    const filterConfigs: FilterConfig[] = [
        {
            key: 'status',
            placeholder: 'Status',
            options: [
                { value: 'active', label: 'Aktif' },
                { value: 'inactive', label: 'Tidak Aktif' },
                { value: 'all', label: 'Semua' },
            ],
        },
    ];

    return (
        <EnterpriseIndexLayout
            title="Daftar Router"
            pageTitle="Daftar Router"
            subtitle={`Menampilkan ${routers.data.length} dari ${routers.total} router`}
            icon={RouterIcon}
            breadcrumbs={[{ label: 'Routers', href: route('routers.index') }]}
            actions={
                <div className="flex items-center gap-2">
                    <Button
                        onClick={handleSyncAll}
                        disabled={isSyncingAll || hasActiveSync}
                        variant="outline"
                        size="sm"
                        className="h-9 gap-2 border-dashed"
                    >
                        <RefreshCw className={`h-4 w-4 ${(isSyncingAll || hasActiveSync) ? 'animate-spin' : ''}`} />
                        {isSyncingAll || hasActiveSync ? 'Menyinkronkan...' : 'Sinkron Semua'}
                    </Button>
                    <Link href={route('routers.create')}>
                        <Button size="sm" className="h-9 gap-2">
                            <Server className="h-4 w-4" />
                            Tambah Router
                        </Button>
                    </Link>
                </div>
            }
        >
            <PollStoppedNotice show={syncPoll.stopped && hasActiveSync} onRestart={syncPoll.restart} />
            <DataTable
                compact
                density="compact"
                data={routers}
                columns={columns}
                filters={filters}
                title=""
                description={`Menampilkan ${routers.data.length} dari ${routers.total} router`}
                searchPlaceholder="Cari Nama, IP, Status..."
                filterConfigs={filterConfigs}
                routeName="routers.index"
                onRowClick={(item) => router.visit(route('routers.show', item.id))}
            />

            <ConfirmDestructive
                open={routerToDelete !== null}
                onOpenChange={(open) => {
                    if (!open && !deleting) setRouterToDelete(null);
                }}
                title="Hapus router?"
                description={
                    (routerToDelete?.customers_count ?? 0) > 0
                        ? `Router ${routerToDelete?.name} masih punya ${routerToDelete?.customers_count} pelanggan. Server akan menolak penghapusan sampai semua pelanggan dipindahkan.`
                        : `Router ${routerToDelete?.name ?? ''} akan dihapus dari eBilling. Tindakan ini tidak dapat dibatalkan dari aplikasi.`
                }
                typedConfirmation={routerToDelete?.name}
                typedConfirmationLabel="Ketik nama router untuk melanjutkan."
                confirmText="Hapus router"
                isLoading={deleting}
                onConfirm={confirmDelete}
            />
        </EnterpriseIndexLayout>
    );
}
