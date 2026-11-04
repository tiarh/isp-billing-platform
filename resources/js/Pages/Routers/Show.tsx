import { EnterpriseResourceLayout } from '@/Components/enterprise/EnterpriseResourceLayout';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { Button } from '@/Components/ui/button';
import { Badge } from '@/Components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { Switch } from '@/Components/ui/switch';
import { Alert, AlertDescription, AlertTitle } from '@/Components/ui/alert';
import { Server, Wifi, Activity, Users, RefreshCw, Edit, Trash2, AlertCircle, Router as RouterIcon, KeyRound, ShieldCheck, Check, Loader2, Sparkles, Globe, Network } from 'lucide-react';
import { Skeleton } from '@/Components/ui/skeleton';
import { ConfirmDialog } from '@/Components/ConfirmDialog';
import { ConfirmDestructive } from '@/Components/ConfirmDestructive';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from "@/Components/ui/dialog";
import { FormEvent, useState, useEffect } from 'react';
import useSWR from 'swr';
import { formatDistanceToNow } from 'date-fns';
import { id as idLocale } from 'date-fns/locale';
import { toast } from 'sonner';
import { formatWibDate, formatWibDateTime } from '@/lib/dateTime';
import RouterCustomersTab, { initialRouterTab, initialUnlinkedFocus } from './Partials/RouterCustomersTab';
import UnlinkedSecretsNotice from './Partials/UnlinkedSecretsNotice';
import { RouterStatusBadge, SyncStatus } from '@/Components/RouterStatusBadge';
import { reloadOnly, usePollWhileVisible } from '@/hooks/usePollWhileVisible';
import { PollStoppedNotice } from '@/Components/PollStoppedNotice';

interface Customer {
    id: number;
    name: string;
    code: string;
    pppoe_user: string;
    status: string;
    package: {
        name: string;
        price: number;
    } | null;
}

interface ActiveConnection {
    name: string;
    address: string;
    uptime: string;
    encoding: string;
    caller_id: string;
}

interface LiveStats {
    data: {
        active_connections: ActiveConnection[];
        total_online: number;
        system_info: any;
    };
    last_updated: string;
    cached?: boolean;
}

interface PaginatedCustomers {
    data: Customer[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number;
    to: number;
}

interface RouterData {
    id: number;
    name: string;
    ip_address: string;
    port: number;
    username: string;
    is_active: boolean;
    connection_status: 'unknown' | 'online' | 'offline';
    created_at: string;
    customers_count: number;
    staged_unmatched_customers_count?: number;
    last_scanned_at: string | null;
    last_scan_customers_count: number;
    current_online_count?: number;
    total_pppoe_count: number;
    mt_pulse_active?: boolean;
    mt_pulse_stale_s?: number | null;
    mt_pulse_quality?: string | null;
    sync_status: SyncStatus;
    sync_started_at: string | null;
    sync_finished_at: string | null;
    sync_lock_until: string | null;
    sync_message: string | null;
    vpn_enabled: boolean;
    vpn_interface: string | null;
    vpn_address: string | null;
    vpn_server_address: string | null;
    vpn_server_public_key: string | null;
    vpn_server_endpoint: string | null;
    vpn_server_port: number | null;
    vpn_allowed_ips: string | null;
    vpn_client_private_key: string | null;
    vpn_client_public_key: string | null;
    vpn_preshared_key: string | null;
    radius_enabled: boolean;
    radius_secret: string | null;
    radius_auth_port: number | null;
    radius_acct_port: number | null;
    last_radius_synced_at: string | null;
    isolation_profile: string | null;
    last_sync_stats: {
        total_secrets?: number;
        mapped?: number;
        unmatched_mikrotik?: number;
        staged_router_only?: number;
        staged_gone?: number;
        not_found_ebilling?: number;
        synced_status?: number;
    } | null;
    profiles: Array<{
        name: string;
        rate_limit?: string;
        bandwidth?: string;
        local_address?: string;
        remote_address?: string;
    }>;
}

interface AddressPoolBlock {
    id: number;
    site_profile_key?: string | null;
    pppoe_profile?: string | null;
    customer_area?: string | null;
    block_name?: string | null;
    block_index?: number | null;
    address_format?: string | null;
    local_address?: string | null;
    remote_start?: string | null;
    remote_end?: string | null;
    next_block_index?: number | null;
    last_remote_address?: string | null;
    last_remote_source?: string | null;
    last_remote_synced_at?: string | null;
    is_active: boolean;
    capacity?: number | null;
    used_count?: number | null;
    available_count?: number | null;
    warning_threshold?: number | null;
    last_synced_at?: string | null;
}

interface PackageDraft {
    id: number;
    name: string;
    mikrotik_profile?: string | null;
    rate_limit?: string | null;
    price: number | string;
    is_active: boolean;
}

interface Props {
    router: RouterData;
    address_pool_blocks?: AddressPoolBlock[];
    package_drafts?: PackageDraft[]; ui_examples?: { vpn_server_endpoint?: string };
    vpn: {
        credentials_configured: {
            client_key: boolean;
            preshared_key: boolean;
            radius_secret: boolean;
        };
        radius_tables_ready: boolean;
        defaults: {
            vpn_interface: string;
            vpn_server_address: string;
            vpn_server_public_key: string | null;
            vpn_server_endpoint: string;
            vpn_server_port: number;
            vpn_allowed_ips: string;
            radius_auth_port: number;
            radius_acct_port: number;
        };
    };
}

// SWR fetcher
const fetcher = async (url: string) => {
    const res = await fetch(url);
    if (!res.ok) {
        const data = await res.json();
        const error: any = new Error(data.error || 'An error occurred while fetching the data.');
        error.info = data;
        error.status = res.status;
        throw error;
    }
    return res.json();
};

export default function Show({ router: routerData, address_pool_blocks = [], package_drafts = [], vpn, ui_examples: uiExamples }: Props) {
    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [deleteIsolationDialogOpen, setDeleteIsolationDialogOpen] = useState(false);
    const pageUrl = usePage().url;
    const [activeTab, setActiveTab] = useState(() => initialRouterTab(pageUrl));
    const [unlinkedFocus, setUnlinkedFocus] = useState(() => initialUnlinkedFocus(pageUrl));
    const vpnForm = useForm({
        vpn_enabled: routerData.vpn_enabled ?? false,
        vpn_interface: routerData.vpn_interface || vpn.defaults.vpn_interface || 'wg-ebilling',
        vpn_address: routerData.vpn_address || '',
        vpn_server_address: routerData.vpn_server_address || vpn.defaults.vpn_server_address || '10.99.0.1',
        vpn_server_public_key: routerData.vpn_server_public_key || vpn.defaults.vpn_server_public_key || '',
        vpn_server_endpoint: routerData.vpn_server_endpoint || vpn.defaults.vpn_server_endpoint || '',
        vpn_server_port: routerData.vpn_server_port || vpn.defaults.vpn_server_port || 443,
        vpn_allowed_ips: routerData.vpn_allowed_ips || vpn.defaults.vpn_allowed_ips || '10.99.0.0/24',
        radius_enabled: routerData.radius_enabled ?? false,
        radius_secret: routerData.radius_secret || '',
        radius_auth_port: routerData.radius_auth_port || vpn.defaults.radius_auth_port || 1812,
        radius_acct_port: routerData.radius_acct_port || vpn.defaults.radius_acct_port || 1813,

    });
    const isolationProfile = routerData.isolation_profile || 'isolirebilling';
    const isolationForm = useForm({
        isolation_profile: isolationProfile,
        isolation_rate_limit: '128k/128k',
        isolation_local_address: '',
        isolation_remote_address: '',
    });

    // Use SWR for live stats with smart caching
    const { data: liveStats, error: statsError, isLoading: isLoadingStats, mutate } = useSWR<LiveStats>(
        `/api/routers/${routerData.id}/live-stats`,
        fetcher,
        {
            refreshInterval: 60000, // Refresh every 60 seconds (matches backend cache)
            revalidateOnFocus: false,
            dedupingInterval: 30000,
        }
    );

    // Never trust under-count live dump vs pulse/DB baseline (e.g. 365 vs 461)
    const baselineOnline = routerData.current_online_count ?? 0;
    const baselineSecret = routerData.total_pppoe_count || routerData.customers_count || 0;
    const liveOnlineRaw = liveStats?.data?.total_online ?? 0;
    const liveSecretRaw = (liveStats?.data as { total_secrets?: number } | undefined)?.total_secrets;
    const displayOnline = (() => {
        if (liveOnlineRaw <= 0) return baselineOnline;
        if (baselineOnline <= 0) return liveOnlineRaw;
        if (liveOnlineRaw < Math.floor(baselineOnline * 0.85)) return baselineOnline;
        return Math.max(liveOnlineRaw, baselineOnline);
    })();
    const displaySecret =
        Math.max(liveSecretRaw && liveSecretRaw > 0 ? liveSecretRaw : 0, baselineSecret || 0) || 0;
    // Human-readable update line only (no internal source labels)
    const updatedLabel = liveStats?.last_updated
        ? `Diupdate ${formatDistanceToNow(new Date(liveStats.last_updated), { locale: idLocale })} lalu`
        : baselineOnline > 0
            ? 'Terakhir diketahui'
            : isLoadingStats
                ? 'Memuat...'
                : null;
    // Hide technical noise (stream timeout / pulse OK soft-success) when Online sehat
    const cleanSyncMessage = (() => {
        const msg = (routerData.sync_message || '').trim();
        if (!msg) return null;
        const onlineOk = (routerData.current_online_count ?? 0) > 0 || displayOnline > 0;
        const failed = routerData.sync_status === 'failed';
        if (!failed && onlineOk && /stream timed out|pulse OK|full secrets|Online pulse|health partial/i.test(msg)) {
            return null;
        }
        if (!failed && /^Plan C pulse OK/i.test(msg)) return null;
        // Strip long technical tails after · or •
        const short = msg.replace(/\s*[·•].*$/, '').trim();
        return short || null;
    })();

    // WireGuard tunnel status
    const { data: wgTunnelStatus, mutate: mutateTunnel } = useSWR<{tunnel_up: boolean; last_handshake?: string; transfer?: string; endpoint?: string; peer_public_key?: string}>(
        routerData.vpn_enabled && routerData.vpn_client_public_key ? `/api/routers/${routerData.id}/wg-tunnel-status` : null,
        fetcher,
        {
            refreshInterval: 30000,
            revalidateOnFocus: false,
            dedupingInterval: 15000,
        }
    );

    const [isRefreshing, setIsRefreshing] = useState(false);
    const isSyncActive = ['queued', 'running'].includes(routerData.sync_status);

    // R1: shared visible-only poll with backoff and a 5-minute stop.
    const syncPoll = usePollWhileVisible(() => reloadOnly(['router']), 3000, {
        enabled: isSyncActive,
        backoff: 1.5,
        maxIntervalMs: 15000,
        maxMs: 5 * 60 * 1000,
    });

    // Manual Refresh (Overrides Scheduled Sync)
    const handleRefresh = () => {
        setIsRefreshing(true);
        router.post(`/routers/${routerData.id}/sync`, {}, {
            preserveScroll: true,
            preserveState: true,
            only: ['router', 'flash'],
            onSuccess: () => {
                mutate(); // Trigger refresh but don't wait for it
            },
            onFinish: () => setIsRefreshing(false),
        });
    };

    const [deleting, setDeleting] = useState(false);
    const handleDelete = () => {
        router.delete(route('routers.destroy', routerData.id), {
            onStart: () => setDeleting(true),
            onFinish: () => {
                setDeleting(false);
                setDeleteDialogOpen(false);
            },
        });
    };

    const handleVpnSubmit = (e: FormEvent) => {
        e.preventDefault();
        vpnForm.post(route('routers.vpn.update', routerData.id), {
            preserveScroll: true,
            preserveState: false,
        });
    };

    // R2: sync RADIUS / profil wajib konfirmasi dengan nama router.
    const [pendingSync, setPendingSync] = useState<'radius' | 'profiles' | null>(null);
    const [syncing, setSyncing] = useState(false);
    const syncRequestOptions = {
        preserveScroll: true,
        onStart: () => setSyncing(true),
        onFinish: () => {
            setSyncing(false);
            setPendingSync(null);
        },
    };

    const handleRadiusSync = () => {
        router.post(route('routers.radius.sync', routerData.id), {}, syncRequestOptions);
    };

    const handleSyncProfiles = () => {
        router.post(route('routers.profiles.sync', routerData.id), {}, syncRequestOptions);
    };

    const blockTone = (block: AddressPoolBlock) => {
        const available = Number(block.available_count ?? 0);
        const warning = Number(block.warning_threshold ?? 0);
        if (!block.is_active) return 'secondary';
        if (available <= 0) return 'destructive';
        if (warning > 0 && available <= warning) return 'outline';
        return 'default';
    };

    const handleIsolationProfileSubmit = (e: FormEvent) => {
        e.preventDefault();
        isolationForm.post(route('routers.isolation-profile.store', routerData.id), {
            preserveScroll: true,
            preserveState: false,
        });
    };

    const handleIsolationProfileDelete = () => {
        router.delete(route('routers.isolation-profile.destroy', routerData.id), {
            preserveScroll: true,
            preserveState: false,
            onFinish: () => setDeleteIsolationDialogOpen(false),
        });
    };

    // VPN auto-fill state
    const [findingIp, setFindingIp] = useState(false);
    const [loadingServerConfig, setLoadingServerConfig] = useState(false);
    const [ipAutoFilled, setIpAutoFilled] = useState(false);
    const [serverConfigLoaded, setServerConfigLoaded] = useState(false);

    const handleAutoFillIp = async () => {
        setFindingIp(true);
        try {
            const res = await fetch('/api/routers/next-wg-address', {
                headers: { 'Accept': 'application/json' },
                credentials: 'include',
            });
            const data = await res.json();
            if (data.error) throw new Error(data.error);
            vpnForm.setData('vpn_address' as any, data.next_address);
            setIpAutoFilled(true);
            window.setTimeout(() => setIpAutoFilled(false), 3000);
        } catch (e: any) {
            toast.error('Failed to find next IP: ' + (e.message || 'Unknown error'));
        } finally {
            setFindingIp(false);
        }
    };

    const handleLoadServerConfig = async () => {
        setLoadingServerConfig(true);
        try {
            const res = await fetch('/api/routers/wg-server-config', {
                headers: { 'Accept': 'application/json' },
                credentials: 'include',
            });
            const data = await res.json();
            if (data.error) throw new Error(data.error);
            vpnForm.setData('vpn_server_public_key' as any, data.server_public_key);
            vpnForm.setData('vpn_server_endpoint' as any, data.server_endpoint);
            vpnForm.setData('vpn_server_port' as any, data.server_port);
            vpnForm.setData('vpn_server_address' as any, data.server_address);
            vpnForm.setData('vpn_allowed_ips' as any, data.allowed_ips);
            setServerConfigLoaded(true);
            window.setTimeout(() => setServerConfigLoaded(false), 3000);
        } catch (e: any) {
            toast.error('Failed to load server config: ' + (e.message || 'Unknown error'));
        } finally {
            setLoadingServerConfig(false);
        }
    };

    const formatDate = (dateString: string) => formatWibDate(dateString);

    return (
        <EnterpriseResourceLayout
            title={routerData.name}
            pageTitle={`Router: ${routerData.name}`}
            subtitle={`${routerData.ip_address}:${routerData.port}`}
            icon={RouterIcon}
            backHref={route('routers.index')}
            breadcrumbs={[
                { label: 'Router', href: route('routers.index') },
                { label: routerData.name },
            ]}
            actions={
                <div className="flex flex-wrap items-center gap-2">
                    <Link href={route('routers.edit', routerData.id)}>
                        <Button variant="outline" size="sm">
                            <Edit className="mr-2 h-4 w-4" />
                            Edit
                        </Button>
                    </Link>
                    <Button variant="destructive" size="sm" onClick={() => setDeleteDialogOpen(true)}>
                        <Trash2 className="mr-2 h-4 w-4" aria-hidden="true" />
                        Hapus
                    </Button>
                </div>
            }
        >
            <div className="mx-auto max-w-7xl">
                <PollStoppedNotice show={syncPoll.stopped && isSyncActive} onRestart={syncPoll.restart} />
                    <Tabs value={activeTab} onValueChange={setActiveTab} className="space-y-6">
                        {/* ... TabsList unchanged ... */}
                        <TabsList>
                            <TabsTrigger value="overview">
                                <Server className="h-4 w-4 mr-2" />
                                Ringkasan
                            </TabsTrigger>
                            <TabsTrigger value="customers">
                                <Users className="h-4 w-4 mr-2" />
                                Pelanggan ({routerData.customers_count})
                            </TabsTrigger>
                            <TabsTrigger value="profiles">
                                <Activity className="h-4 w-4 mr-2" />
                                Paket ({String(routerData.profiles?.length || 0)})
                            </TabsTrigger>
                            <TabsTrigger value="vpn">
                                <KeyRound className="h-4 w-4 mr-2" />
                                VPN & RADIUS
                            </TabsTrigger>
                        </TabsList>

                        {/* Overview Tab */}
                        <TabsContent value="overview" className="space-y-6">
                            {/* Stats Grid */}
                            <div className="grid gap-6 md:grid-cols-3">
                                <Card className="border-border bg-card">
                                    <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                                        <CardTitle className="text-sm font-medium text-muted-foreground">
                                            Status Koneksi
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        <Badge
                                            variant={routerData.connection_status === 'online' ? 'up' : routerData.connection_status === 'offline' ? 'down' : 'neutral'}
                                        >
                                            {routerData.connection_status === 'online' ? 'Online' :
                                                routerData.connection_status === 'offline' ? 'Offline' : 'Belum dicek'}
                                        </Badge>
                                        <p className="text-xs text-muted-foreground mt-2">
                                            {routerData.is_active ? 'Monitoring aktif' : 'Monitoring nonaktif'}
                                        </p>
                                    </CardContent>
                                </Card>

                                {/* ... Online/Total Card unchanged ... */}
                                <Card className="border-border bg-card">
                                    <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                                        <CardTitle className="text-sm font-medium text-muted-foreground">
                                            Pelanggan Online / Total
                                        </CardTitle>
                                        <Users className="h-4 w-4 text-muted-foreground" />
                                    </CardHeader>
                                    <CardContent>
                                        {isLoadingStats && !baselineOnline ? (
                                            <Skeleton className="h-8 w-20" />
                                        ) : (
                                            <div className="text-2xl font-bold">
                                                <span className="text-status-up">{displayOnline}</span>
                                                <span className="text-muted-foreground"> / {displaySecret}</span>
                                            </div>
                                        )}
                                        {updatedLabel && (
                                            <p className="text-xs text-muted-foreground mt-1">{updatedLabel}</p>
                                        )}
                                        {statsError && baselineOnline <= 0 && displayOnline <= 0 && (
                                            <p className="text-xs text-status-down mt-1">Tidak dapat memuat angka online</p>
                                        )}
                                    </CardContent>
                                </Card>

                                {/* ... API Port Card unchanged ... */}
                                <Card className="border-border bg-card">
                                    <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                                        <CardTitle className="text-sm font-medium text-muted-foreground">
                                            Port API MikroTik
                                        </CardTitle>
                                        <Server className="h-4 w-4 text-muted-foreground" />
                                    </CardHeader>
                                    <CardContent>
                                        <div className="text-2xl font-bold">{routerData.port}</div>
                                        <p className="text-xs text-muted-foreground mt-1">
                                            Port untuk komunikasi sistem ke router
                                        </p>
                                    </CardContent>
                                </Card>
                            </div>

                            {/* Last Scan Info */}
                            <Card className="border-border bg-card">
                                <CardContent className="pt-6">
                                    <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                                        <div className="flex items-center gap-3">
                                            <Activity className="h-5 w-5 text-muted-foreground" />
                                            <div>
                                                <p className="text-sm font-medium">Sinkronisasi Terakhir</p>
                                                <p className="text-xs text-muted-foreground">
                                                    {routerData.last_scanned_at
                                                        ? `${formatDistanceToNow(new Date(routerData.last_scanned_at), { locale: idLocale })} lalu • Ditemukan ${routerData.last_scan_customers_count} pelanggan`
                                                        : 'Belum pernah disinkronkan'
                                                    }
                                                </p>
                                                {cleanSyncMessage && (
                                                    <p className="mt-1 text-xs text-muted-foreground">{cleanSyncMessage}</p>
                                                )}
                                                {routerData.last_sync_stats && (
                                                    <p className="mt-1 text-xs text-muted-foreground">
                                                        {routerData.last_sync_stats.mapped ?? 0} cocok • {routerData.last_sync_stats.staged_router_only ?? routerData.last_sync_stats.unmatched_mikrotik ?? 0} hanya di router • {routerData.last_sync_stats.not_found_ebilling ?? 0} tidak ada di eBilling
                                                    </p>
                                                )}
                                                <UnlinkedSecretsNotice
                                                    count={routerData.staged_unmatched_customers_count || 0}
                                                    onOpen={() => { setActiveTab('customers'); setUnlinkedFocus((n) => n + 1); }}
                                                />
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-2 self-start sm:self-center">
                                            <RouterStatusBadge
                                                connectionStatus={routerData.connection_status}
                                                syncStatus={routerData.sync_status || 'idle'}
                                                syncMessage={cleanSyncMessage}
                                            />
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={handleRefresh}
                                                disabled={isRefreshing || isSyncActive}
                                                title={isSyncActive ? 'Sinkronisasi sedang berjalan' : 'Sinkronkan ulang data dari router'}
                                                aria-label={isSyncActive ? 'Sinkronisasi sedang berjalan' : 'Sinkronkan ulang data dari router'}
                                            >
                                                <RefreshCw className={`h-4 w-4 ${(isRefreshing || isSyncActive) ? 'animate-spin' : ''}`} aria-hidden="true" />
                                            </Button>
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>

                            {/* Active Connections */}
                            {liveStats?.data?.active_connections && Array.isArray(liveStats.data.active_connections) && liveStats.data.active_connections.length > 0 && (
                                <Card className="border-border bg-card">
                                    <CardHeader>
                                        <div className="flex items-center justify-between">
                                            <CardTitle>Koneksi Aktif</CardTitle>
                                            <Badge variant="outline" className="text-status-up">
                                                {displayOnline} online
                                            </Badge>
                                        </div>
                                        <CardDescription>
                                            Sesi PPPoE yang sedang terhubung (10 teratas)
                                        </CardDescription>
                                    </CardHeader>
                                    <CardContent>
                                        <div className="space-y-3">
                                            {liveStats.data.active_connections.slice(0, 10).map((conn, idx) => (
                                                <div key={idx} className="flex items-center justify-between p-3 rounded-lg bg-muted/50 hover:bg-muted transition-colors">
                                                    <div>
                                                        <p className="font-medium">{conn.name}</p>
                                                        <p className="text-xs text-muted-foreground">{conn.address}</p>
                                                    </div>
                                                    <div className="text-right">
                                                        <p className="text-xs font-mono text-status-up">{conn.uptime}</p>
                                                        <p className="text-xs text-muted-foreground">{conn.encoding}</p>
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    </CardContent>
                                </Card>
                            )}

                            {/* Configuration Details */}
                            <Card className="border-border bg-card">
                                <CardHeader>
                                    <CardTitle>Konfigurasi Router</CardTitle>
                                    <CardDescription>
                                        Detail teknis dan kredensial router
                                    </CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <div className="grid grid-cols-2 gap-6">
                                        <div>
                                            <p className="text-sm font-medium text-muted-foreground">Nama Router</p>
                                            <p className="text-base font-semibold mt-1">{routerData.name}</p>
                                        </div>
                                        <div>
                                            <p className="text-sm font-medium text-muted-foreground">Username</p>
                                            <p className="text-base font-mono mt-1">{routerData.username}</p>
                                        </div>
                                        <div>
                                            <p className="text-sm font-medium text-muted-foreground">Alamat IP</p>
                                            <p className="text-base font-mono mt-1">{routerData.ip_address}:{routerData.port}</p>
                                        </div>
                                        <div>
                                            <p className="text-sm font-medium text-muted-foreground">Dibuat</p>
                                            <p className="text-base mt-1">{formatDate(routerData.created_at)}</p>
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>
                        </TabsContent>

                        {/* Customers Tab */}
                        <TabsContent value="customers">
                            {activeTab === 'customers' && (
                                <RouterCustomersTab
                                    routerId={routerData.id}
                                    activeConnections={liveStats?.data?.active_connections || []}
                                    focusUnlinked={unlinkedFocus}
                                />
                            )}
                        </TabsContent>

                        {/* Profiles Tab */}
                        <TabsContent value="profiles" className="space-y-4">
                            <div className="grid gap-4 lg:grid-cols-2">
                                <Card>
                                    <CardHeader>
                                        <CardTitle>PPPoE Address Pool</CardTitle>
                                        <CardDescription>
                                            Master block static untuk allocator PSB. Data ini read-only.
                                        </CardDescription>
                                    </CardHeader>
                                    <CardContent>
                                        {address_pool_blocks.length === 0 ? (
                                            <div className="rounded-md border border-dashed p-4 text-sm text-muted-foreground">
                                                Belum ada block allocator untuk router ini. Jika strict allocator aktif, PSB akan gagal jujur dan minta NOC tambah block.
                                            </div>
                                        ) : (
                                            <div className="space-y-3">
                                                {address_pool_blocks.map((block) => (
                                                    <div key={block.id} className="rounded-md border p-3">
                                                        <div className="flex flex-wrap items-start justify-between gap-2">
                                                            <div>
                                                                <div className="font-mono text-sm font-semibold">
                                                                    {block.block_name || `${block.local_address || '-'} -> ${block.remote_start || '-'}-${block.remote_end || '-'}`}
                                                                </div>
                                                                <div className="mt-1 text-xs text-muted-foreground">
                                                                    {[block.site_profile_key, block.pppoe_profile, block.customer_area].filter(Boolean).join(' / ') || 'Semua area/profile'}
                                                                </div>
                                                            </div>
                                                            <Badge variant={blockTone(block) as any}>
                                                                {block.is_active ? `${block.available_count ?? 0} free` : 'nonaktif'}
                                                            </Badge>
                                                        </div>
                                                        <div className="mt-3 grid gap-2 text-xs sm:grid-cols-3">
                                                            <div>
                                                                <div className="text-muted-foreground">Used</div>
                                                                <div className="font-mono">{block.used_count ?? 0}/{block.capacity ?? 0}</div>
                                                            </div>
                                                            <div>
                                                                <div className="text-muted-foreground">Last remote</div>
                                                                <div className="font-mono">{block.last_remote_address || '-'}</div>
                                                            </div>
                                                            <div>
                                                                <div className="text-muted-foreground">Last sync</div>
                                                                <div className="font-mono">{formatWibDateTime(block.last_synced_at || block.last_remote_synced_at)}</div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                ))}
                                            </div>
                                        )}
                                    </CardContent>
                                </Card>

                                <Card>
                                    <CardHeader>
                                        <CardTitle>Paket Perlu Review</CardTitle>
                                        <CardDescription>
                                            Draft profile hasil sync yang belum siap dipakai bot PSB.
                                        </CardDescription>
                                    </CardHeader>
                                    <CardContent>
                                        {package_drafts.length === 0 ? (
                                            <div className="rounded-md border border-dashed p-4 text-sm text-muted-foreground">
                                                Tidak ada paket draft/perlu harga untuk router ini.
                                            </div>
                                        ) : (
                                            <div className="space-y-2">
                                                {package_drafts.map((pkg) => (
                                                    <div key={pkg.id} className="flex items-center justify-between gap-3 rounded-md border p-3 text-sm">
                                                        <div>
                                                            <div className="font-medium">{pkg.name}</div>
                                                            <div className="font-mono text-xs text-muted-foreground">{pkg.mikrotik_profile || '-'} {pkg.rate_limit ? `- ${pkg.rate_limit}` : ''}</div>
                                                        </div>
                                                        <div className="flex items-center gap-2">
                                                            {Number(pkg.price) <= 0 && <Badge variant="outline">perlu harga</Badge>}
                                                            {!pkg.is_active && <Badge variant="secondary">draft</Badge>}
                                                            <Button type="button" variant="outline" size="sm" onClick={() => router.visit(route('packages.edit', pkg.id))}>
                                                                Edit
                                                            </Button>
                                                        </div>
                                                    </div>
                                                ))}
                                            </div>
                                        )}
                                    </CardContent>
                                </Card>
                            </div>

                            <Card>
                                <CardHeader className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <CardTitle>Paket PPP (Profil)</CardTitle>
                                        <CardDescription>
                                            Profil PPP yang disinkronkan dari router. Dipakai untuk membuat paket internet.
                                        </CardDescription>
                                    </div>
                                    <Button type="button" variant="outline" size="sm" onClick={() => setPendingSync('profiles')} disabled={syncing}>
                                        <RefreshCw className="mr-2 h-4 w-4" />
                                        Sync Profile
                                    </Button>
                                </CardHeader>
                                <CardContent>
                                    {(!routerData.profiles || routerData.profiles.length === 0) ? (
                                        <div className="text-center py-12 border-2 border-dashed rounded-lg">
                                            <AlertCircle className="h-10 w-10 text-muted-foreground mx-auto mb-3" />
                                            <h3 className="text-lg font-medium">Belum Ada Profil</h3>
                                            <p className="text-muted-foreground mb-4">
                                                Jalukan "Sinkronisasi" untuk mengambil profil dari router.
                                            </p>
                                        </div>
                                    ) : (
                                        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                                            {routerData.profiles.map((profile) => (
                                                <div key={profile.name} className="flex items-start justify-between p-4 border rounded-lg hover:bg-muted transition-colors">
                                                    <div>
                                                        <div className="flex items-center gap-2 mb-1">
                                                            <span className="font-mono font-semibold text-lg">{profile.name}</span>
                                                            {profile.bandwidth && (
                                                                <Badge variant="secondary">{profile.bandwidth}</Badge>
                                                            )}
                                                        </div>
                                                        <div className="text-xs text-muted-foreground font-mono space-y-1">
                                                            {profile.rate_limit && (
                                                                <div className="flex items-center gap-1">
                                                                    <Activity className="h-3 w-3" />
                                                                    {profile.rate_limit}
                                                                </div>
                                                            )}
                                                            {profile.local_address && (
                                                                <div className="flex items-center gap-1">
                                                                    <Server className="h-3 w-3" />
                                                                    Local: {profile.local_address}
                                                                </div>
                                                            )}
                                                            {profile.remote_address && (
                                                                <div className="flex items-center gap-1">
                                                                    <Wifi className="h-3 w-3" />
                                                                    Remote: {profile.remote_address}
                                                                </div>
                                                            )}
                                                        </div>
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        </TabsContent>

                        <TabsContent value="vpn" className="space-y-6">
                            {/* VPN Connection Status */}
                            <Card className={`border-border bg-card ${routerData.vpn_enabled ? '' : 'opacity-60'}`}>
                                <CardContent className="pt-6">
                                    <div className="flex items-center justify-between">
                                        <div className="flex items-center gap-3">
                                            <KeyRound className={`h-6 w-6 ${wgTunnelStatus?.tunnel_up ? 'text-status-up' : routerData.vpn_enabled ? 'text-status-degraded' : 'text-muted-foreground'}`} />
                                            <div>
                                                <p className="text-sm font-medium">Status VPN</p>
                                                <p className="text-xs text-muted-foreground">
                                                    {!routerData.vpn_enabled ? 'VPN tidak aktif' : !routerData.vpn_client_public_key ? 'Belum dikonfigurasi' : `Interface: ${routerData.vpn_interface || 'wg-ebilling'} • IP: ${routerData.vpn_address || '-'} • Port: ${routerData.vpn_server_port || 443}`}
                                                </p>
                                                {wgTunnelStatus?.last_handshake && (
                                                    <p className="text-xs text-muted-foreground">
                                                        Handshake: {wgTunnelStatus.last_handshake}
                                                        {wgTunnelStatus.transfer && ` • ${wgTunnelStatus.transfer}`}
                                                    </p>
                                                )}
                                            </div>
                                        </div>
                                        {routerData.vpn_enabled && (
                                            <Badge variant={wgTunnelStatus?.tunnel_up ? 'up' : 'warning'}>
                                                {wgTunnelStatus?.tunnel_up ? 'Terhubung' : 'Menunggu Handshake'}
                                            </Badge>
                                        )}
                                    </div>
                                </CardContent>
                            </Card>

                            {/* Panduan VPN untuk Admin */}
                                        <Alert className="border-status-info/30 bg-status-info-bg">
                                <Globe className="h-4 w-4 text-status-info" />
                                <AlertTitle className="text-status-info">VPN & RADIUS</AlertTitle>
                                <AlertDescription className="text-status-info text-sm">
                                    <p><strong>VPN</strong> — Jalur aman antara MikroTik dan VPS. <strong>RADIUS</strong> — Autentikasi PPPoE terpusat dari eBilling.</p>
                                    <p className="mt-1"><strong>Setup:</strong> ① Isi pengaturan di bawah ② Klik "Simpan VPN" ③ Jalankan konfigurasi melalui kanal operasi yang disetujui ④ Setelah terhubung, ubah IP router ke alamat VPN</p>
                                </AlertDescription>
                            </Alert>

                            <form onSubmit={handleIsolationProfileSubmit}>
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="flex items-center gap-2">
                                            <ShieldCheck className="h-5 w-5 text-status-degraded" />
                                            Profil Isolir (Suspend)
                                        </CardTitle>
                                        <CardDescription>
                                            Profil PPP yang dipakai saat pelanggan di-suspend. Pelanggan isolir akan dibatasi kecepatannya dan diarahkan ke halaman pembayaran.
                                        </CardDescription>
                                    </CardHeader>
                                    <CardContent className="space-y-4">
                                        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                                            <div className="space-y-2">
                                                <Label htmlFor="isolation_profile">Nama Profil</Label>
                                                <Input
                                                    id="isolation_profile"
                                                    value={isolationForm.data.isolation_profile}
                                                    onChange={(e) => isolationForm.setData('isolation_profile', e.target.value)}
                                                />
                                            </div>
                                            <div className="space-y-2">
                                                <Label htmlFor="isolation_rate_limit">Batas Kecepatan</Label>
                                                <Input
                                                    id="isolation_rate_limit"
                                                    placeholder="128k/128k"
                                                    value={isolationForm.data.isolation_rate_limit}
                                                    onChange={(e) => isolationForm.setData('isolation_rate_limit', e.target.value)}
                                                />
                                                <p className="text-xs text-muted-foreground">Upload/Download (contoh: 128k/128k)</p>
                                            </div>
                                            <div className="space-y-2">
                                                <Label htmlFor="isolation_local_address">IP Lokal Router</Label>
                                                <Input
                                                    id="isolation_local_address"
                                                    placeholder="contoh: 198.51.100.1"
                                                    value={isolationForm.data.isolation_local_address}
                                                    onChange={(e) => isolationForm.setData('isolation_local_address', e.target.value)}
                                                />
                                            </div>
                                            <div className="space-y-2">
                                                <Label htmlFor="isolation_remote_address">Pool IP / IP Remote</Label>
                                                <Input
                                                    id="isolation_remote_address"
                                                    placeholder="contoh: isolir-pool"
                                                    value={isolationForm.data.isolation_remote_address}
                                                    onChange={(e) => isolationForm.setData('isolation_remote_address', e.target.value)}
                                                />
                                            </div>
                                        </div>
                                        <p className="text-sm text-muted-foreground">
                                            Jika mengisi nama pool di "Pool IP", router akan otomatis membuat pool IP dari subnet tersebut.
                                        </p>
                                        <div className="flex items-center justify-between border-t pt-4">
                                            <div className="text-sm text-muted-foreground">
                                                Profil isolir saat ini: <span className="font-mono">{isolationProfile}</span>
                                            </div>
                                            <div className="flex items-center gap-2">
                                                <Button
                                                    type="button"
                                                    variant="destructive"
                                                    onClick={() => setDeleteIsolationDialogOpen(true)}
                                                    disabled={isolationForm.processing || !routerData.isolation_profile}
                                                >
                                                    <Trash2 className="mr-2 h-4 w-4" />
                                                    Hapus Profil
                                                </Button>
                                                <Button type="submit" disabled={isolationForm.processing}>
                                                    <ShieldCheck className="mr-2 h-4 w-4" />
                                                    {isolationForm.processing ? 'Menyimpan...' : 'Buat Profil Isolir'}
                                                </Button>
                                            </div>
                                        </div>
                                    </CardContent>
                                </Card>
                            </form>

                            <form onSubmit={handleVpnSubmit} className="grid gap-6 lg:grid-cols-[1fr_420px]">
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="flex items-center gap-2">
                                            <KeyRound className="h-5 w-5 text-primary" />
                                            Pengaturan VPN & RADIUS
                                        </CardTitle>
                                        <CardDescription>
                                            Konfigurasi koneksi WireGuard dan RADIUS untuk router ini. Klik tombol otomatis untuk mengisi dengan mudah.
                                        </CardDescription>
                                    </CardHeader>
                                    <CardContent className="space-y-6">
                                        {/* Toggle Switches */}
                                        <div className="grid gap-4 md:grid-cols-2">
                                            <div className="flex items-center gap-3 rounded-lg border p-4">
                                                <Switch
                                                    id="vpn_enabled"
                                                    checked={vpnForm.data.vpn_enabled}
                                                    onCheckedChange={(checked) => vpnForm.setData('vpn_enabled', checked)}
                                                />
                                                <div>
                                                    <Label htmlFor="vpn_enabled" className="font-medium">Aktifkan VPN</Label>
                                                    <p className="text-xs text-muted-foreground">Router akan dikelola melalui jalur VPN.</p>
                                                </div>
                                            </div>
                                            <div className="flex items-center gap-3 rounded-lg border p-4">
                                                <Switch
                                                    id="radius_enabled"
                                                    checked={vpnForm.data.radius_enabled}
                                                    onCheckedChange={(checked) => vpnForm.setData('radius_enabled', checked)}
                                                />
                                                <div>
                                                    <Label htmlFor="radius_enabled" className="font-medium">Aktifkan RADIUS</Label>
                                                    <p className="text-xs text-muted-foreground">Data pelanggan ditulis ke server RADIUS.</p>
                                                </div>
                                            </div>
                                        </div>

                                        {/* VPN Settings */}
                                        <div className="space-y-3">
                                            <h4 className="text-sm font-semibold text-muted-foreground uppercase tracking-wider">Pengaturan WireGuard</h4>
                                            <div className="grid gap-4 md:grid-cols-2">
                                                <div className="space-y-2">
                                                    <Label htmlFor="vpn_interface">Nama Interface di MikroTik</Label>
                                                    <Input id="vpn_interface" value={vpnForm.data.vpn_interface} onChange={(e) => vpnForm.setData('vpn_interface', e.target.value)} />
                                                    <p className="text-xs text-muted-foreground">Nama antarmuka WireGuard di router</p>
                                                </div>
                                                <div className="space-y-2">
                                                    <Label htmlFor="vpn_address">IP Address VPN MikroTik</Label>
                                                    <div className="flex gap-2">
                                                        <Input id="vpn_address" placeholder="10.99.0.2/24" value={vpnForm.data.vpn_address} onChange={(e) => vpnForm.setData('vpn_address', e.target.value)} className="flex-1" />
                                                        <Button type="button" variant="outline" size="sm" onClick={handleAutoFillIp} disabled={findingIp} className="whitespace-nowrap">
                                                            {findingIp ? <Loader2 className="h-4 w-4 animate-spin" /> : ipAutoFilled ? <Check className="h-4 w-4 text-status-up" /> : <Sparkles className="h-4 w-4" />}
                                                            {ipAutoFilled ? 'OK' : 'Otomatis'}
                                                        </Button>
                                                    </div>
                                                    <p className="text-xs text-muted-foreground">Alamat IP router di jaringan VPN</p>
                                                </div>
                                            </div>
                                        </div>

                                        {/* Server Settings */}
                                        <div className="space-y-3">
                                            <h4 className="text-sm font-semibold text-muted-foreground uppercase tracking-wider">Pengaturan Server VPS</h4>
                                            <div className="grid gap-4 md:grid-cols-2">
                                                <div className="space-y-2">
                                                    <Label htmlFor="vpn_server_address">IP RADIUS Server (via VPN)</Label>
                                                    <Input id="vpn_server_address" value={vpnForm.data.vpn_server_address} onChange={(e) => vpnForm.setData('vpn_server_address', e.target.value)} />
                                                    <p className="text-xs text-muted-foreground">IP VPS di jaringan VPN (RADIUS)</p>
                                                </div>
                                                <div className="space-y-2">
                                                    <Label htmlFor="vpn_server_endpoint">IP Publik VPS</Label>
                                                    <Input id="vpn_server_endpoint" placeholder={uiExamples?.vpn_server_endpoint ?? ''} value={vpnForm.data.vpn_server_endpoint} onChange={(e) => vpnForm.setData('vpn_server_endpoint', e.target.value)} />
                                                    <p className="text-xs text-muted-foreground">Alamat publik VPS untuk koneksi VPN</p>
                                                </div>
                                                <div className="space-y-2">
                                                    <Label htmlFor="vpn_server_port">Port WireGuard</Label>
                                                    <Input id="vpn_server_port" type="number" value={vpnForm.data.vpn_server_port} onChange={(e) => vpnForm.setData('vpn_server_port', Number(e.target.value) || 443)} />
                                                    <p className="text-xs text-muted-foreground">Port UDP WireGuard di VPS (default: 443)</p>
                                                </div>
                                                <div className="space-y-2">
                                                    <Label htmlFor="vpn_allowed_ips">IP yang Diizinkan</Label>
                                                    <Input id="vpn_allowed_ips" value={vpnForm.data.vpn_allowed_ips} onChange={(e) => vpnForm.setData('vpn_allowed_ips', e.target.value)} />
                                                    <p className="text-xs text-muted-foreground">Subnet yang bisa diakses via VPN</p>
                                                </div>
                                            </div>
                                            <div className="space-y-2">
                                                <Label htmlFor="vpn_server_public_key">Public Key VPS</Label>
                                                <div className="flex gap-2">
                                                    <Input id="vpn_server_public_key" value={vpnForm.data.vpn_server_public_key} onChange={(e) => vpnForm.setData('vpn_server_public_key', e.target.value)} className="flex-1" />
                                                    <Button type="button" variant="outline" size="sm" onClick={handleLoadServerConfig} disabled={loadingServerConfig} className="whitespace-nowrap">
                                                        {loadingServerConfig ? <Loader2 className="h-4 w-4 animate-spin" /> : serverConfigLoaded ? <Check className="h-4 w-4 text-status-up" /> : <Network className="h-4 w-4" />}
                                                        {serverConfigLoaded ? 'OK' : 'Isi Otomatis'}
                                                    </Button>
                                                </div>
                                                <p className="text-xs text-muted-foreground">Kunci publik WireGuard server VPS</p>
                                            </div>
                                        </div>

                                        {/* RADIUS Settings */}
                                        <div className="space-y-3">
                                            <h4 className="text-sm font-semibold text-muted-foreground uppercase tracking-wider">Pengaturan RADIUS</h4>
                                            <div className="grid gap-4 md:grid-cols-3">
                                                <div className="space-y-2">
                                                    <Label htmlFor="radius_secret">RADIUS Secret</Label>
                                                    <Input id="radius_secret" value={vpnForm.data.radius_secret} onChange={(e) => vpnForm.setData('radius_secret', e.target.value)} placeholder="Kata kunci bersama" />
                                                    <p className="text-xs text-muted-foreground">Kata kunci bersama router-VPS</p>
                                                </div>
                                                <div className="space-y-2">
                                                    <Label htmlFor="radius_auth_port">Port Autentikasi</Label>
                                                    <Input id="radius_auth_port" type="number" value={vpnForm.data.radius_auth_port} onChange={(e) => vpnForm.setData('radius_auth_port', Number(e.target.value) || 1812)} />
                                                </div>
                                                <div className="space-y-2">
                                                    <Label htmlFor="radius_acct_port">Port Accounting</Label>
                                                    <Input id="radius_acct_port" type="number" value={vpnForm.data.radius_acct_port} onChange={(e) => vpnForm.setData('radius_acct_port', Number(e.target.value) || 1813)} />
                                                </div>
                                            </div>
                                        </div>

                                        <div className="flex items-center justify-between border-t pt-4">
                                            <div className="text-sm text-muted-foreground">
                                                Tabel RADIUS: {vpn.radius_tables_ready ? 'Siap' : 'Belum ada'}
                                            </div>
                                            <div className="flex gap-2 flex-wrap">
                                                <Button type="button" variant="outline" onClick={() => setPendingSync('profiles')} disabled={!routerData.vpn_enabled || syncing}>
                                                    <RefreshCw className="mr-2 h-4 w-4" />
                                                    Sync Profile MikroTik
                                                </Button>
                                                <Button type="button" variant="outline" onClick={() => setPendingSync('radius')} disabled={!routerData.radius_enabled || syncing}>
                                                    <RefreshCw className="mr-2 h-4 w-4" />
                                                    Sinkronkan RADIUS
                                                </Button>
                                                <Button type="submit" disabled={vpnForm.processing}>
                                                    <KeyRound className="mr-2 h-4 w-4" />
                                                    {vpnForm.processing ? 'Menyimpan...' : 'Simpan VPN'}
                                                </Button>
                                            </div>
                                        </div>


                                    </CardContent>
                                </Card>

                                <Card className="border-status-degraded/30 bg-status-degraded-bg">
                                    <CardHeader>
                                        <CardTitle className="flex items-center gap-2">
                                            <Server className="h-5 w-5 text-status-degraded" />
                                            Kredensial VPN/RADIUS
                                        </CardTitle>
                                        <CardDescription>
                                            Nilai rahasia tidak ditampilkan di browser. Gunakan kanal operasi yang disetujui untuk konfigurasi MikroTik.
                                        </CardDescription>
                                    </CardHeader>
                                    <CardContent className="text-sm text-muted-foreground space-y-1">
                                        <p>Private key WireGuard: {vpn.credentials_configured.client_key ? 'tersimpan' : 'belum diatur'}</p>
                                        <p>Pre-shared key: {vpn.credentials_configured.preshared_key ? 'tersimpan' : 'belum diatur'}</p>
                                        <p>RADIUS secret: {vpn.credentials_configured.radius_secret ? 'tersimpan' : 'belum diatur'}</p>
                                    </CardContent>
                                </Card>
                            </form>

                        </TabsContent>
                    </Tabs>
            </div>
            <ConfirmDestructive
                open={deleteDialogOpen}
                onOpenChange={(open) => {
                    if (!deleting) setDeleteDialogOpen(open);
                }}
                title="Hapus router?"
                description={
                    routerData.customers_count > 0
                        ? `Router ini masih punya ${routerData.customers_count} pelanggan. Server akan menolak penghapusan sampai semua pelanggan dipindahkan.`
                        : 'Router akan dihapus dari eBilling. Tindakan ini tidak dapat dibatalkan dari aplikasi.'
                }
                typedConfirmation={routerData.name}
                typedConfirmationLabel="Ketik nama router untuk melanjutkan."
                confirmText="Hapus router"
                isLoading={deleting}
                onConfirm={handleDelete}
            />
            <ConfirmDestructive
                open={pendingSync !== null}
                onOpenChange={(open) => {
                    if (!open && !syncing) setPendingSync(null);
                }}
                title={pendingSync === 'radius' ? `Sync RADIUS untuk ${routerData.name}?` : `Sync profil PPP dari ${routerData.name}?`}
                description={
                    pendingSync === 'radius'
                        ? 'NAS router ini dan semua user PPPoE pelanggannya akan ditulis ulang ke tabel FreeRADIUS. Login pelanggan di router ini memakai data hasil sync.'
                        : 'Profil PPP dibaca dari MikroTik lalu paket eBilling dibuat/diperbarui (harga yang sudah diisi tidak ditimpa).'
                }
                confirmationLabel={`Saya sudah memastikan router ${routerData.name} yang dipilih benar.`}
                confirmText={pendingSync === 'radius' ? 'Sync RADIUS' : 'Sync profil'}
                isLoading={syncing}
                onConfirm={() => (pendingSync === 'radius' ? handleRadiusSync() : handleSyncProfiles())}
            />
            <ConfirmDialog
                open={deleteIsolationDialogOpen}
                onOpenChange={setDeleteIsolationDialogOpen}
                title="Hapus Profil Isolir?"
                description={`Hapus profil isolir ${routerData.isolation_profile || isolationProfile} dari router ini? Referensi profil di database juga akan dihapus.`}
                confirmText="Hapus"
                variant="destructive"
                onConfirm={handleIsolationProfileDelete}
            />
        </EnterpriseResourceLayout>
    );
}
