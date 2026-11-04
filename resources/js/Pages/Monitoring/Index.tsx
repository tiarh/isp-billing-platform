import { EnterpriseIndexLayout } from '@/Components/enterprise/EnterpriseIndexLayout';
import { EnterpriseMetricCard } from '@/Components/enterprise/EnterpriseMetricCard';
import { NocConnectivityAlertSettings, ConnectivityAlertSettings } from '@/Components/noc/NocConnectivityAlertSettings';
import { StatusBadge } from '@/Components/StatusBadge';
import { SkeletonCard } from '@/Components/SkeletonCard';
import { Deferred, Link, router, usePage } from '@inertiajs/react';
import { useCallback, useState, useEffect, useRef } from 'react';
import { JaringanTab, type JaringanMeta } from '@/Components/monitoring/network/JaringanTab';
import { RangeSelector, useStoredRange } from '@/Components/monitoring/network/RangeSelector';
import { formatClock } from '@/Components/monitoring/network/format';
import { isGlobalAdminUser } from '@/lib/navAccess';
import type { PageProps } from '@/types';
import { Activity, AlertTriangle, ExternalLink, RadioTower, Router, Signal, Wifi } from 'lucide-react';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Textarea } from '@/Components/ui/textarea';
import { formatNumber, formatRelative } from '@/lib/format';
import { formatWibDateTime } from '@/lib/dateTime';
import { CustomerTrafficPanel } from './CustomerTrafficPanel';
import IncidentInbox from './IncidentInbox';
import OutageShadowPanel from './OutageShadowPanel';

type Props = {
    source: {
        label: string;
        mode: string;
        cachedAt?: string | null;
        cacheTtlSeconds?: number | null;
        warming?: boolean;
    };
    summary: {
        uptime: number | null;
        activeSubscribers: number;
        badSignalOnts: number;
        onlineSubscribers: number;
        routerOnline: number;
        routerTotal: number;
        oltTotal: number;
        oltWithFreshData: number;
        acsOnline: number;
        acsTotal: number;
        serviceOnline?: number | null;
        serviceOnlineTotal?: number | null;
        ponOnlyOnline?: number | null;
        pppoeOnlyOnline?: number | null;
        customerCompositeOnline?: number | null;
        customerSetupRequired?: number | null;
        gangguanActive?: number | null;
        openTickets: number;
        urgentTickets: number;
    };
    /** Deferred — may be undefined until second Inertia request */
    tickets?: Record<string, any>;
    badSignalCustomers?: Array<Record<string, any>>;
    grafana?: {
        enabled: boolean;
        url: string | null;
        dashboards: Array<{ uid: string; title: string; url: string }>;
        error: string | null;
    };
    customerConnectivity?: Record<string, any>;
    gangguanSignals?: Record<string, any>;
    nocInbox?: {
        summary?: Record<string, number>;
        sources?: Array<Record<string, any>>;
        incidents?: Array<Record<string, any>>;
        incident_pagination?: { page?: number; per_page?: number; last_page?: number; total?: number };
        read_only?: boolean;
        ticket_triage_enabled?: boolean;
        coverage?: { total?: number; mapped?: number; needs_mapping?: number; isolated?: number };
        queue_status?: { max_pending?: number; queues?: Record<string, { pending?: number; consumer_active?: boolean }> };
        generated_at?: string | null;
    };
    unifiedConnectivity?: {
        enabled?: boolean;
        filters?: Record<string, string>;
        source_options?: Array<{ value: string; label: string }>;
        strategy_options?: string[];
        status_options?: string[];
        summary?: { total?: number; online?: number; isolated?: number; latest_snapshot?: boolean; history_note?: string };
        rows?: Array<Record<string, any>>;
        total_rows?: number;
        truncated?: boolean;
        read_only?: boolean;
    };
    viewTab?: 'gangguan' | 'deteksi' | 'jaringan' | 'alerts' | 'noc' | 'traffic';
    trafficEnabled?: boolean;
    fhttBacklog?: Array<Record<string, any>> | { items?: Array<Record<string, any>>; total?: number };
    connectivityAlerts?: ConnectivityAlertSettings;
};

type MonitoringTab = 'gangguan' | 'deteksi' | 'jaringan' | 'alerts' | 'noc' | 'traffic';
type TriageRequest = { action: string; ticketId: number; fields: Record<string, string> } | null;

function fmt(value?: number | null, suffix = '') {
    if (value === null || value === undefined || Number.isNaN(Number(value))) return '-';
    return `${formatNumber(Number(value), { maximumFractionDigits: 2 })}${suffix}`;
}

function KpiCard({ title, value, hint, icon: Icon }: { title: string; value: string; hint: string; icon: any }) {
    return <EnterpriseMetricCard title={title} value={value} hint={hint} icon={Icon} />;
}

function shortDate(value?: string | null) {
    return formatRelative(value);
}

function DeferredPanelSkeleton({ label = 'Memuat panel…' }: { label?: string }) {
    return (
        <div className="grid gap-3 md:grid-cols-3" role="status" aria-label={label}>
            <span className="sr-only">{label}</span>
            <SkeletonCard />
            <SkeletonCard />
            <SkeletonCard />
        </div>
    );
}

export default function Index({
    source,
    summary,
    tickets,
    badSignalCustomers = [],
    grafana,
    customerConnectivity = {},
    unifiedConnectivity,
    gangguanSignals = {},
    nocInbox = {},
    viewTab = 'jaringan',
    trafficEnabled = false,
    fhttBacklog = [],
    connectivityAlerts,
}: Props) {
    const safeFhtt = Array.isArray(fhttBacklog)
        ? fhttBacklog
        : Array.isArray((fhttBacklog as any)?.items)
          ? (fhttBacklog as any).items
          : [];

    const [activeTab, setActiveTab] = useState<MonitoringTab>(viewTab);
    const activeTabRef = useRef<MonitoringTab>(activeTab);
    activeTabRef.current = activeTab;
    const authUser = usePage<PageProps>().props.auth.user;
    const isGlobalAdmin = isGlobalAdminUser(authUser);
    const [range, setRange] = useStoredRange();
    const [jaringanMeta, setJaringanMeta] = useState<JaringanMeta>({ updatedAt: null, grafanaUrl: null });
    const handleJaringanMeta = useCallback((next: JaringanMeta) => {
        setJaringanMeta((current) => (current.updatedAt === next.updatedAt && current.grafanaUrl === next.grafanaUrl ? current : next));
    }, []);
    const autoReloadInFlight = useRef(false);
    const autoReloadFailures = useRef(0);
    const tabNavigationInFlight = useRef(false);
    const [nocView, setNocView] = useState<'action' | 'mass' | 'customers' | 'sources' | 'history'>('action');
    const [nocPreset, setNocPreset] = useState<'all' | 'p1p2' | 'unassigned' | 'bad_signal' | 'stale' | 'mapping'>('all');
    const [triageRequest, setTriageRequest] = useState<TriageRequest>(null);
    const [triageReason, setTriageReason] = useState('');
    const [triageSubmitting, setTriageSubmitting] = useState(false);

    useEffect(() => {
        const saved = window.localStorage.getItem('monitoring:noc-view');
        if (saved && ['action', 'mass', 'customers', 'sources', 'history'].includes(saved)) {
            setNocView(saved as 'action' | 'mass' | 'customers' | 'sources' | 'history');
        }
        const savedPreset = window.localStorage.getItem('monitoring:noc-preset');
        if (savedPreset && ['all', 'p1p2', 'unassigned', 'bad_signal', 'stale', 'mapping'].includes(savedPreset)) {
            setNocPreset(savedPreset as 'all' | 'p1p2' | 'unassigned' | 'bad_signal' | 'stale' | 'mapping');
        }
    }, []);

    // Keep the dashboard fresh without stacking reloads when the split web tier is busy.
    useEffect(() => {
        let cancelled = false;
        let timer: ReturnType<typeof window.setTimeout> | undefined;

        const reloadProps = ['summary', 'tickets', 'badSignalCustomers', 'customerConnectivity', 'unifiedConnectivity', 'gangguanSignals', 'nocInbox', 'source', 'fhttBacklog'];

        // 60s base interval (the server payload is only regenerated every
        // ~120s anyway), doubled per consecutive failure, capped at 5 min.
        const scheduleReload = () => {
            const delay = Math.min(300000, 60000 * Math.max(1, autoReloadFailures.current + 1));
            timer = window.setTimeout(runReload, delay);
        };

        const runReload = () => {
            if (cancelled) {
                return;
            }

            // A hidden tab kept reloading many props every 30s (and triggering
            // server-side revalidation). Skip while hidden; the visibilitychange
            // listener below reloads once as soon as the tab is shown again.
            if (document.visibilityState === 'hidden') {
                scheduleReload();
                return;
            }

            if (autoReloadInFlight.current || tabNavigationInFlight.current) {
                scheduleReload();
                return;
            }

            // The Jaringan tab polls its own widget endpoints; reloading the
            // legacy snapshot props there would only add server load.
            if (activeTabRef.current === 'jaringan' || activeTabRef.current === 'deteksi') {
                scheduleReload();
                return;
            }

            autoReloadInFlight.current = true;
            router.reload({
                only: reloadProps,
                onSuccess: () => {
                    autoReloadFailures.current = 0;
                },
                onError: () => {
                    autoReloadFailures.current += 1;
                },
                onCancel: () => {
                    autoReloadFailures.current += 1;
                },
                onFinish: () => {
                    autoReloadInFlight.current = false;
                    if (!cancelled) {
                        scheduleReload();
                    }
                },
            });
        };

        const onVisibilityChange = () => {
            if (document.visibilityState === 'visible' && !cancelled) {
                if (timer) {
                    window.clearTimeout(timer);
                }
                runReload();
            }
        };
        document.addEventListener('visibilitychange', onVisibilityChange);

        scheduleReload();

        return () => {
            cancelled = true;
            document.removeEventListener('visibilitychange', onVisibilityChange);
            if (timer) {
                window.clearTimeout(timer);
            }
        };
    }, []);

    const triagePost = (action: string, ticketId: number, fields: Record<string, string> = {}) => {
        setTriageReason('');
        setTriageRequest({ action, ticketId, fields });
    };
    const submitTriage = () => {
        if (!triageRequest || !triageReason.trim()) return;

        setTriageSubmitting(true);
        router.post(
            route(`monitoring.tickets.${triageRequest.action}`, triageRequest.ticketId),
            { reason: triageReason.trim(), ...triageRequest.fields },
            {
                preserveScroll: true,
                onSuccess: () => setTriageRequest(null),
                onFinish: () => setTriageSubmitting(false),
            },
        );
    };
    const nocIncidents = Array.isArray(nocInbox?.incidents) ? nocInbox.incidents : [];
    const visibleNocIncidents = nocIncidents
        .filter((incident: Record<string, any>) => nocView !== 'mass' || Number(incident.affected_count || 0) >= 5)
        .filter((incident: Record<string, any>) => {
            const issue = String(incident.issue || '').toLowerCase();
            if (nocPreset === 'p1p2') return ['P1', 'P2'].includes(String(incident.priority || ''));
            if (nocPreset === 'unassigned') return !incident.ticket_id;
            if (nocPreset === 'bad_signal') return issue.includes('signal') || issue.includes('rx');
            if (nocPreset === 'stale') return issue.includes('stale') || issue.includes('unknown');
            if (nocPreset === 'mapping') return issue.includes('mapping') || issue.includes('conflict');
            return true;
        });
    const nocPagination = nocInbox?.incident_pagination || { page: 1, last_page: 1, total: nocIncidents.length };
    const changeNocPage = (page: number) => {
        router.get(route('monitoring.index'), {
            tab: 'noc',
            noc_page: page,
            noc_per_page: nocPagination.per_page || 25,
        }, { preserveState: false, preserveScroll: true, replace: true });
    };
    const saveNocView = () => {
        window.localStorage.setItem('monitoring:noc-view', nocView);
        window.localStorage.setItem('monitoring:noc-preset', nocPreset);
    };
    const cacheTtl = source?.cacheTtlSeconds ?? 60;
    const fhttCount = safeFhtt.length;
    const alertCount = (summary?.badSignalOnts ?? 0) + (tickets?.urgent ?? summary?.urgentTickets ?? 0);

    useEffect(() => {
        if (activeTab !== viewTab) {
            setActiveTab(viewTab);
        }
    }, [activeTab, viewTab]);

    function changeTab(tab: MonitoringTab) {
        if (tab === activeTab || tabNavigationInFlight.current) {
            return;
        }

        tabNavigationInFlight.current = true;
        router.get(route('monitoring.index'), { tab }, {
            preserveState: false,
            preserveScroll: true,
            replace: true,
            onError: () => {
                autoReloadFailures.current += 1;
            },
            onFinish: () => {
                tabNavigationInFlight.current = false;
            },
        });
    }

    const isJaringan = activeTab === 'jaringan';

    return (
        <EnterpriseIndexLayout
            title="Monitoring"
            pageTitle="Monitoring"
            subtitle={isJaringan ? `Sumber: Grafana · diperbarui ${formatClock(jaringanMeta.updatedAt)}` : source.label}
            icon={Activity}
            breadcrumbs={[
                { label: 'Network', href: route('monitoring.index') },
                { label: 'Monitoring' },
            ]}
            cachedAt={isJaringan ? undefined : source.cachedAt}
            cacheLabel="Snapshot"
            freshMinutes={Math.max(1, Math.round(cacheTtl / 60))}
            activeTab={activeTab}
            onTabChange={(id) => changeTab(id as MonitoringTab)}
            tabs={[
                { id: 'jaringan', label: 'Jaringan' },
                { id: 'gangguan', label: 'Gangguan' },
                { id: 'deteksi', label: 'Deteksi Gangguan' },
                { id: 'alerts', label: 'Alerts', badge: alertCount || null },
                { id: 'noc', label: 'NOC', badge: fhttCount || null },
                ...(trafficEnabled ? [{ id: 'traffic' as const, label: 'Pemakaian Bandwidth' }] : []),
            ]}
            actions={
                <>
                    {isJaringan ? (
                        <>
                            <RangeSelector value={range} onChange={setRange} />
                            {jaringanMeta.grafanaUrl && (
                                <Button asChild variant="outline" size="sm">
                                    <a href={jaringanMeta.grafanaUrl} target="_blank" rel="noopener noreferrer">
                                        <ExternalLink className="size-4" aria-hidden />
                                        Buka di Grafana
                                    </a>
                                </Button>
                            )}
                        </>
                    ) : (
                        <>
                            <Badge variant="outline">Read-only snapshot</Badge>
                            <Badge variant={grafana?.enabled ? 'default' : 'secondary'}>{grafana?.enabled ? 'Grafana OK' : 'Grafana off'}</Badge>
                        </>
                    )}
                    {isGlobalAdmin && (
                        <Button asChild variant="outline" size="sm">
                            <Link href={route('monitoring.settings.index')}>Pengaturan</Link>
                        </Button>
                    )}
                </>
            }
        >
            <div className="space-y-5">
                {activeTab === 'jaringan' && (
                    <JaringanTab
                        range={range}
                        settingsHref={isGlobalAdmin ? route('monitoring.settings.index') : null}
                        onMeta={handleJaringanMeta}
                        renderRouterName={
                            isGlobalAdmin
                                ? (row) => (
                                      <Link
                                          href={route('monitoring.routers.show', row.router_id)}
                                          className="rounded-sm font-medium text-primary hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                      >
                                          {row.name}
                                      </Link>
                                  )
                                : undefined
                        }
                    />
                )}
                {activeTab === 'gangguan' && <IncidentInbox />}
                {activeTab === 'deteksi' && <OutageShadowPanel />}
                {activeTab === 'traffic' && <CustomerTrafficPanel />}
                {activeTab === 'alerts' && (
                <Deferred data={['fhttBacklog', 'badSignalCustomers', 'tickets', 'gangguanSignals']} fallback={<DeferredPanelSkeleton label="Memuat alerts…" />}>
                <>
                    {connectivityAlerts && (
                        <NocConnectivityAlertSettings initial={connectivityAlerts} />
                    )}
                    <Card>
                        <CardHeader>
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <CardTitle className="text-base">Alert Aktif</CardTitle>
                                    <p className="mt-1 text-xs text-muted-foreground">Connectivity scan + OLT collector (weak RX / collector failed). Sumber <span className="font-mono">{gangguanSignals?.source || 'monitoring_alert_events'}</span>.</p>
                                </div>
                                <div className="flex flex-wrap items-center gap-2">
                                    <Badge variant="secondary">Total {fmt(gangguanSignals?.active_total)}</Badge>
                                    <Badge variant="outline" className="border-status-down/40 text-status-down">Critical {fmt(gangguanSignals?.critical)}</Badge>
                                    <Badge variant="outline" className="border-status-degraded/40 text-status-degraded">Warning {fmt(gangguanSignals?.warning)}</Badge>
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {Object.keys(gangguanSignals?.by_issue || {}).length > 0 && (
                                <div className="flex flex-wrap gap-2">
                                    {Object.entries(gangguanSignals.by_issue).map(([issue, total]) => (
                                        <Badge key={issue} variant="outline" className="font-mono text-[11px]">{issue}: {String(total)}</Badge>
                                    ))}
                                </div>
                            )}
                            {(gangguanSignals?.recent || []).length === 0 ? (
                                <div className="rounded-md border border-dashed p-6 text-center text-sm text-muted-foreground">Belum ada alert aktif. Data diisi oleh connectivity scan dan OLT collector (weak RX / collector failed).</div>
                            ) : (
                                <div className="overflow-x-auto rounded-md border">
                                    <table className="w-full min-w-[640px] text-sm">
                                        <thead className="bg-muted/60 text-muted-foreground">
                                            <tr>
                                                <th className="px-3 py-2 text-left font-medium">Entitas</th>
                                                <th className="px-3 py-2 text-left font-medium">Issue</th>
                                                <th className="px-3 py-2 text-left font-medium">Severity</th>
                                                <th className="px-3 py-2 text-left font-medium">Sumber</th>
                                                <th className="px-3 py-2 text-left font-medium">Terakhir</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {(gangguanSignals.recent || []).map((row: Record<string, any>, idx: number) => (
                                                <tr key={`${row.entity}-${row.issue}-${idx}`} className="border-t">
                                                    <td className="px-3 py-2 font-medium">{row.entity || '-'}</td>
                                                    <td className="px-3 py-2 font-mono text-xs">{row.issue || '-'}</td>
                                                    <td className="px-3 py-2"><StatusBadge label={row.severity || '-'} tone={row.severity === 'critical' ? 'down' : row.severity === 'warning' ? 'degraded' : 'neutral'} /></td>
                                                    <td className="px-3 py-2 text-xs text-muted-foreground">{row.source || '-'}</td>
                                                    <td className="px-3 py-2 text-xs text-muted-foreground">{formatWibDateTime(row.last_seen_at)}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">FHTT Enrollment Backlog</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {fhttCount === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    {(fhttBacklog as any)?.available === false
                                        ? ((fhttBacklog as any)?.note || 'Fitur backlog FHTT belum tersedia.')
                                        : 'Tidak ada backlog FHTT OLT.'}
                                </p>
                            ) : (
                                <div className="overflow-x-auto rounded-md border">
                                    <table className="w-full min-w-[640px] text-sm">
                                        <thead className="bg-muted/60 text-muted-foreground">
                                            <tr>
                                                <th className="px-3 py-2 text-left font-medium">Pelanggan</th>
                                                <th className="px-3 py-2 text-left font-medium">PPPoE</th>
                                                <th className="px-3 py-2 text-left font-medium">OLT / Port</th>
                                                <th className="px-3 py-2 text-left font-medium">SN</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {safeFhtt.map((row: Record<string, any>, idx: number) => (
                                                <tr key={row.id ?? idx} className="border-t">
                                                    <td className="px-3 py-2 font-medium">{row.name ?? row.customer_name ?? '-'}</td>
                                                    <td className="px-3 py-2 font-mono text-xs">{row.pppoe_user ?? '-'}</td>
                                                    <td className="px-3 py-2">{row.olt_name ?? '-'}<div className="text-xs text-muted-foreground">{row.olt_port_label ?? row.port ?? '-'}</div></td>
                                                    <td className="px-3 py-2 font-mono text-xs">{row.onu_serial ?? row.serial ?? '-'}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">ONT Redaman Buruk</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="overflow-x-auto rounded-md border">
                                <table className="w-full min-w-[640px] text-sm">
                                    <thead className="bg-muted/60 text-muted-foreground">
                                        <tr>
                                            <th className="px-3 py-2 text-left font-medium">Pelanggan</th>
                                            <th className="px-3 py-2 text-left font-medium">Area</th>
                                            <th className="px-3 py-2 text-left font-medium">OLT / Port</th>
                                            <th className="px-3 py-2 text-right font-medium">RX</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {(badSignalCustomers || []).length === 0 ? (
                                            <tr><td colSpan={4} className="px-3 py-6 text-center text-muted-foreground">Tidak ada redaman buruk tersimpan.</td></tr>
                                        ) : (badSignalCustomers || []).map((customer) => (
                                            <tr key={customer.id} className="border-t">
                                                <td className="px-3 py-2"><span className="font-medium">{customer.name}</span><div className="text-xs text-muted-foreground">{customer.code}</div></td>
                                                <td className="px-3 py-2">{customer.area || '-'}</td>
                                                <td className="px-3 py-2">{customer.olt || '-'}<div className="text-xs text-muted-foreground">{customer.port || '-'}</div></td>
                                                <td className="px-3 py-2 text-right font-mono text-status-down">{fmt(customer.rx, ' dBm')}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </CardContent>
                    </Card>
                </>
                </Deferred>
                )}

                {activeTab === 'noc' && (
                <Deferred data={['grafana', 'fhttBacklog', 'customerConnectivity', 'gangguanSignals', 'nocInbox']} fallback={<DeferredPanelSkeleton label="Memuat NOC…" />}>
                <>
                <Card>
                    <CardContent className="flex flex-wrap items-center gap-2 p-3">
                        {([
                            ['action', 'Perlu Tindakan'],
                            ['mass', 'Gangguan Massal'],
                            ['customers', 'Pelanggan'],
                            ['sources', 'Sumber & Perangkat'],
                            ['history', 'Histori & SLA'],
                        ] as const).map(([value, label]) => (
                            <button key={value} type="button" className={`rounded-md border px-3 py-2 text-xs font-medium ${nocView === value ? 'border-primary bg-primary/10 text-primary' : 'text-muted-foreground hover:bg-muted'}`} onClick={() => setNocView(value)}>{label}</button>
                        ))}
                        {([
                            ['all', 'Semua'],
                            ['p1p2', 'P1/P2 Aktif'],
                            ['unassigned', 'Belum Ditangani'],
                            ['bad_signal', 'Redaman Buruk'],
                            ['stale', 'Stale'],
                            ['mapping', 'Mapping Issue'],
                        ] as const).map(([value, label]) => (
                            <button key={value} type="button" className={`rounded px-2 py-1 text-[11px] ${nocPreset === value ? 'bg-muted font-semibold text-foreground' : 'text-muted-foreground hover:bg-muted'}`} onClick={() => { setNocPreset(value); setNocView('action'); }}>{label}</button>
                        ))}
                        <button type="button" className="ml-auto rounded-md border px-3 py-2 text-xs text-muted-foreground hover:bg-muted" onClick={saveNocView}>Simpan Tampilan</button>
                    </CardContent>
                </Card>
                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                    <KpiCard title="Incident Aktif" value={fmt(nocInbox?.summary?.active_incidents)} hint="Parent incident read-only" icon={AlertTriangle} />
                    <KpiCard title="Pelanggan Terdampak" value={fmt(nocInbox?.summary?.affected_customers)} hint="Child evidence" icon={Signal} />
                    <KpiCard title="P1" value={fmt(nocInbox?.summary?.p1)} hint="Critical" icon={AlertTriangle} />
                    <KpiCard title="P2" value={fmt(nocInbox?.summary?.p2)} hint="Major" icon={RadioTower} />
                    <KpiCard title="Source Alert" value={fmt(nocInbox?.summary?.source_alerts)} hint="Bukan outage pelanggan" icon={Router} />
                    <KpiCard title="Queue monitoring" value={fmt(nocInbox?.queue_status?.max_pending)} hint="Read-only lag tertinggi" icon={Activity} />
                </div>
                {nocInbox?.coverage && <div className="grid gap-2 sm:grid-cols-3">
                    <KpiCard title="Coverage Customer" value={`${fmt(nocInbox.coverage.mapped)}/${fmt(nocInbox.coverage.total)}`} hint="Mapped ke sumber monitoring" icon={Signal} />
                    <KpiCard title="Perlu Mapping" value={fmt(nocInbox.coverage.needs_mapping)} hint="Tidak disembunyikan dari audit" icon={AlertTriangle} />
                    <KpiCard title="Excluded" value={fmt(nocInbox.coverage.isolated)} hint="Isolir/dismantle tidak jadi gangguan" icon={Wifi} />
                </div>}
                {(nocView === 'action' || nocView === 'mass' || nocView === 'history') && <Card>
                    <CardHeader>
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <CardTitle className="text-base">{nocView === 'mass' ? 'Gangguan Massal' : nocView === 'history' ? 'Histori & SLA' : 'Perlu Tindakan'}</CardTitle>
                                <p className="mt-1 text-xs text-muted-foreground">Gangguan dikelompokkan dari snapshot/cache. Tidak ada query live atau aksi perangkat dari halaman ini.</p>
                            </div>
                            <Badge variant="outline">{nocInbox?.read_only ? 'Read-only' : 'Snapshot'}</Badge>
                        </div>
                    </CardHeader>
                    <CardContent>
                        {visibleNocIncidents.length === 0 ? (
                            <div className="rounded-md border border-dashed p-4 text-sm text-muted-foreground">Tidak ada parent incident aktif.</div>
                        ) : (
                            <div className="overflow-x-auto rounded-md border">
                                <table className="w-full min-w-[980px] text-xs">
                                    <thead className="bg-muted/60 text-muted-foreground"><tr>
                                        <th className="px-3 py-2 text-left">Prioritas</th>
                                        <th className="px-3 py-2 text-left">Dugaan</th>
                                        <th className="px-3 py-2 text-left">Terdampak</th>
                                        <th className="px-3 py-2 text-left">Topologi</th>
                                        <th className="px-3 py-2 text-left">Ticket / SLA</th>
                                        <th className="px-3 py-2 text-left">Last seen</th>
                                    </tr></thead>
                                    <tbody>{visibleNocIncidents.map((incident: Record<string, any>) => (
                                        <tr key={incident.incident_key} className="border-t align-top">
                                            <td className="px-3 py-2"><Badge variant="outline" className={incident.priority === 'P1' ? 'border-status-down/30 text-status-down' : incident.priority === 'P2' ? 'border-status-degraded/30 text-status-degraded' : 'border-border text-foreground'}>{incident.priority || 'P3'}</Badge></td>
                                            <td className="px-3 py-2"><div className="font-medium">{incident.issue_label || incident.issue || '-'}</div><div className="text-muted-foreground">{incident.source || '-'}</div></td>
                                            <td className="px-3 py-2 font-medium">{fmt(incident.affected_count)} customer</td>
                                            <td className="px-3 py-2">{[incident.area, incident.router, incident.olt, incident.pon, incident.odp].filter(Boolean).join(' · ') || 'Topologi belum tersedia'}</td>
                                            <td className="px-3 py-2">
                                                {incident.ticket_id ? (
                                                    <a href={route('tickets.show', incident.ticket_id)} className="font-medium text-primary hover:underline">#{incident.ticket_id}</a>
                                                ) : <span className="text-muted-foreground">Belum ada</span>}
                                                <div className="text-muted-foreground">{incident.ticket_status || 'Draft approval'}</div>
                                                {nocInbox?.ticket_triage_enabled && incident.ticket_id && <div className="mt-1 flex flex-wrap gap-1">
                                                    <button type="button" className="text-[11px] text-primary hover:underline" onClick={() => triagePost('acknowledge', incident.ticket_id)}>Ack</button>
                                                    <button type="button" className="text-[11px] text-destructive hover:underline" onClick={() => triagePost('false-positive', incident.ticket_id)}>False positive</button>
                                                </div>}
                                                {incident.ticket_due_at && <div className="text-[11px] text-muted-foreground">SLA {shortDate(incident.ticket_due_at)}{incident.ticket_owner ? ` · ${incident.ticket_owner}` : ''}</div>}
                                            </td>
                                            <td className="px-3 py-2 text-muted-foreground">{shortDate(incident.last_seen_at)}</td>
                                        </tr>
                                    ))}</tbody>
                                </table>
                            </div>
                        )}
                        <div className="mt-3 flex items-center justify-between text-xs text-muted-foreground">
                            <span>{fmt(nocPagination.total)} incident terkelompok</span>
                            <div className="flex gap-2">
                                <button type="button" disabled={(nocPagination.page || 1) <= 1} className="rounded border px-2 py-1 disabled:opacity-40" onClick={() => changeNocPage(Math.max(1, (nocPagination.page || 1) - 1))}>Sebelumnya</button>
                                <span className="px-2 py-1">{nocPagination.page || 1}/{nocPagination.last_page || 1}</span>
                                <button type="button" disabled={(nocPagination.page || 1) >= (nocPagination.last_page || 1)} className="rounded border px-2 py-1 disabled:opacity-40" onClick={() => changeNocPage(Math.min(nocPagination.last_page || 1, (nocPagination.page || 1) + 1))}>Berikutnya</button>
                            </div>
                        </div>
                    </CardContent>
                </Card>}
                {nocView === 'customers' && <Card>
                    <CardHeader><CardTitle className="text-base">Pelanggan</CardTitle></CardHeader>
                    <CardContent>
                        <div className="overflow-x-auto rounded-md border"><table className="w-full min-w-[780px] text-xs"><thead className="bg-muted/60 text-muted-foreground"><tr><th className="px-3 py-2 text-left">Pelanggan</th><th className="px-3 py-2 text-left">Status</th><th className="px-3 py-2 text-left">Confidence</th><th className="px-3 py-2 text-left">Sumber</th><th className="px-3 py-2 text-left">Area/Router</th><th className="px-3 py-2 text-left">Last checked</th></tr></thead><tbody>{(unifiedConnectivity?.rows || []).slice(0, 50).map((row: Record<string, any>) => <tr key={row.customer_id} className="border-t"><td className="px-3 py-2 font-medium">{row.customer_name || row.customer_code || '-'}</td><td className="px-3 py-2"><Badge variant="outline">{row.status || 'unknown'}</Badge></td><td className="px-3 py-2">{row.confidence || '-'}</td><td className="px-3 py-2">{(row.source_badges || []).join(' · ') || row.source || '-'}</td><td className="px-3 py-2">{[row.area, row.router, row.olt].filter(Boolean).join(' · ') || '-'}</td><td className="px-3 py-2 text-muted-foreground">{row.checked_at || '-'}</td></tr>)}</tbody></table></div>
                        {(unifiedConnectivity?.rows || []).length === 0 && <p className="mt-3 text-sm text-muted-foreground">Snapshot pelanggan belum tersedia untuk panel ini.</p>}
                    </CardContent>
                </Card>}
                <Card>
                    <CardHeader><CardTitle className="text-base">Kesehatan Sumber Monitoring</CardTitle></CardHeader>
                    <CardContent><div className="grid gap-2 md:grid-cols-2 xl:grid-cols-4">
                        {(nocInbox?.sources || []).map((source: Record<string, any>) => (
                            <div key={source.key} className="rounded-md border p-3">
                                <div className="flex items-center justify-between gap-2"><span className="text-sm font-medium">{source.label}</span><Badge variant="outline">{source.status}</Badge></div>
                                <div className="mt-2 text-xs text-muted-foreground">Fresh {fmt(source.fresh)} / {fmt(source.mapped)} · Stale {fmt(source.stale)}</div>
                                <div className="mt-1 text-[11px] text-muted-foreground">Attempt: {formatWibDateTime(source.last_attempt_at)} · Success: {formatWibDateTime(source.last_success_at)}</div>
                                <div className="mt-1 text-[11px] text-muted-foreground">Collector: {source.collector_status || '-'}{source.freshness_note ? ` · ${source.freshness_note}` : ''}</div>
                                <div className="mt-1 text-[11px] text-muted-foreground">Duration: {source.duration_ms != null ? `${source.duration_ms} ms` : '-'}{source.error ? ` · ${String(source.error).slice(0, 100)}` : ''}</div>
                            </div>
                        ))}
                    </div></CardContent>
                </Card>
                {connectivityAlerts && (
                    <NocConnectivityAlertSettings initial={connectivityAlerts} />
                )}
                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <KpiCard
                        title="Composite Online"
                        value={`${fmt(customerConnectivity?.composite_online ?? summary.customerCompositeOnline)}/${fmt(customerConnectivity?.active_total ?? summary.activeSubscribers)}`}
                        hint="PPPoE, PON/OLT, GenieACS inform/ping"
                        icon={Signal}
                    />
                    <KpiCard
                        title="PPPoE / PON"
                        value={`${fmt(customerConnectivity?.pppoe_online)}/${fmt(customerConnectivity?.pon_online)}`}
                        hint="PPPoE dan PON/OLT"
                        icon={RadioTower}
                    />
                    <KpiCard
                        title="GenieACS Fresh"
                        value={fmt(customerConnectivity?.genieacs_fresh)}
                        hint={`${fmt(customerConnectivity?.genieacs_ping_ok)} ping OK fresh`}
                        icon={Router}
                    />
                    <KpiCard
                        title="Gangguan Signal"
                        value={fmt(gangguanSignals?.active_total ?? summary.gangguanActive)}
                        hint={`${fmt(gangguanSignals?.critical)} critical, ${fmt(gangguanSignals?.warning)} warning`}
                        icon={AlertTriangle}
                    />
                </div>
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Connectivity Composite</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="grid gap-3 md:grid-cols-3">
                            <div className="rounded-md border p-3">
                                <div className="text-xs text-muted-foreground">Setup required</div>
                                <div className="mt-1 text-xl font-semibold">{fmt(customerConnectivity?.setup_required ?? summary.customerSetupRequired)}</div>
                            </div>
                            <div className="rounded-md border p-3 md:col-span-2">
                                <div className="text-xs text-muted-foreground">Rule</div>
                                <div className="mt-1 text-sm">{customerConnectivity?.note || 'Composite membaca snapshot/cache; dashboard tidak menjalankan aksi ke MikroTik/OLT.'}</div>
                            </div>
                        </div>
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Gangguan Signal untuk Ticketing</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {(gangguanSignals?.recent || []).length === 0 ? (
                            <div className="rounded-md border border-dashed p-4 text-sm text-muted-foreground">Belum ada gangguan aktif dari connectivity scan.</div>
                        ) : (
                            <div className="overflow-x-auto rounded-md border">
                                <table className="w-full min-w-[720px] text-sm">
                                    <thead className="bg-muted/60 text-muted-foreground">
                                        <tr>
                                            <th className="px-3 py-2 text-left font-medium">Entity</th>
                                            <th className="px-3 py-2 text-left font-medium">Issue</th>
                                            <th className="px-3 py-2 text-left font-medium">Severity</th>
                                            <th className="px-3 py-2 text-left font-medium">ODP / Port</th>
                                            <th className="px-3 py-2 text-left font-medium">Last Seen</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {(gangguanSignals?.recent || []).map((event: Record<string, any>, idx: number) => (
                                            <tr key={`${event.entity}-${idx}`} className="border-t">
                                                <td className="px-3 py-2 font-medium">{event.entity || '-'}</td>
                                                <td className="px-3 py-2">{event.issue || '-'}</td>
                                                <td className="px-3 py-2">
                                                    <Badge variant="outline" className={event.severity === 'critical' ? 'border-status-down/30 text-status-down' : 'border-status-degraded/30 text-status-degraded'}>
                                                        {event.severity || '-'}
                                                    </Badge>
                                                </td>
                                                <td className="px-3 py-2">
                                                    {event.payload?.odp || '-'}
                                                    <div className="text-xs text-muted-foreground">{event.payload?.olt_port_label || event.payload?.pon_port || '-'}</div>
                                                </td>
                                                <td className="px-3 py-2">{shortDate(event.last_seen_at)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Grafana Uplink</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {grafana?.enabled ? (
                            <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                                {(grafana?.dashboards || []).map((dashboard) => (
                                    <a key={dashboard.uid} href={dashboard.url} target="_blank" rel="noreferrer" className="flex items-center justify-between rounded-md border p-3 transition hover:bg-muted/60">
                                        <div>
                                            <div className="text-sm font-medium">{dashboard.title}</div>
                                            <div className="mt-1 text-xs text-muted-foreground">{dashboard.uid}</div>
                                        </div>
                                        <ExternalLink className="h-4 w-4 text-muted-foreground" />
                                    </a>
                                ))}
                                {(grafana?.dashboards?.length ?? 0) === 0 && <div className="rounded-md border border-dashed p-4 text-sm text-muted-foreground">Grafana tersambung, tapi dashboard belum terbaca.</div>}
                            </div>
                        ) : (
                            <div className="rounded-md border border-dashed p-4 text-sm text-muted-foreground">{grafana?.error || 'Grafana belum dikonfigurasi.'}</div>
                        )}
                    </CardContent>
                </Card>
                </>
                </Deferred>
                )}

                <Dialog
                    open={triageRequest !== null}
                    onOpenChange={(open) => {
                        if (!open && !triageSubmitting) {
                            setTriageRequest(null);
                            setTriageReason('');
                        }
                    }}
                >
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Alasan triage</DialogTitle>
                            <DialogDescription>
                                Tulis alasan sebelum status ticket diperbarui. Alasan ini dicatat sebagai bagian dari audit.
                            </DialogDescription>
                        </DialogHeader>
                        <Textarea
                            aria-label="Alasan triage"
                            value={triageReason}
                            onChange={(event) => setTriageReason(event.target.value)}
                            placeholder="Contoh: sumber monitoring tidak konsisten dengan bukti lapangan."
                            rows={4}
                            maxLength={1000}
                        />
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setTriageRequest(null)} disabled={triageSubmitting}>
                                Batal
                            </Button>
                            <Button type="button" onClick={submitTriage} disabled={!triageReason.trim() || triageSubmitting}>
                                {triageSubmitting ? 'Menyimpan...' : 'Simpan triage'}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </div>
        </EnterpriseIndexLayout>
    );
}
