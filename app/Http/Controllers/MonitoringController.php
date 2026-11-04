<?php

namespace App\Http\Controllers;

use App\Jobs\RefreshMonitoringCacheJob;
use App\Models\Area;
use App\Models\Customer;
use App\Models\GenieAcsDevice;
use App\Models\Olt;
use App\Models\OltOnu;
use App\Models\Router;
use App\Models\Setting;
use App\Models\SupportTicket;
use App\Services\ConnectivityAlertSettings;
use App\Services\EvolutionApiService;
use App\Services\FhttEnrollmentService;
use App\Services\Grafana\GrafanaClient;
use App\Services\MonitoringAdmissionGate;
use App\Services\MonitoringIncidentReadModel;
use App\Services\MtPlanCPulseService;
use App\Services\NocMonitoringReadModel;
use App\Services\PppoeOnlineFlagWriter;
use App\Services\UnifiedConnectivityReadModel;
use App\Support\AreaScope;
use App\Support\OpsTime;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;
use RouterOS\Client;
use RouterOS\Config;
use RouterOS\Query;

class MonitoringController extends Controller
{
    /**
     * Age (seconds) of monitoring.index.payload after which a page view may
     * dispatch RefreshMonitoringCacheJob. Keep it close to the payload TTL so the
     * scheduler stays the primary refresher.
     */
    public const REVALIDATE_AFTER_SECONDS = 90;

    public function export(Request $request, MonitoringAdmissionGate $gate): JsonResponse
    {
        $token = (string) config('services.monitoring_export.token');
        $givenToken = (string) ($request->bearerToken() ?: $request->header('X-Monitoring-Sync-Token', ''));

        abort_if($token === '' || ! hash_equals($token, $givenToken), 401);

        $decision = $gate->evaluate('monitoring_export');
        if (! ($decision['allowed'] ?? false)) {
            return response()->json([
                'status' => 'paused',
                'reason_codes' => $decision['reason_codes'] ?? ['admission_denied'],
                'retry_after' => 60,
            ], 503)->header('Retry-After', '60');
        }

        $scope = (string) $request->query('scope', 'standard');
        if (! in_array($scope, ['minimal', 'standard', 'full'], true)) {
            $scope = 'standard';
        }

        Log::info('monitoring.export', [
            'ip' => $request->ip(),
            'scope' => $scope,
            'refresh_sessions' => $request->boolean('refresh_sessions'),
        ]);

        $activePppByUser = $request->boolean('refresh_sessions')
            ? $this->activePppByUser()
            : [];

        $customers = Customer::query()
            ->with(['area:id,name', 'router:id,name'])
            ->whereNull('deleted_at')
            ->whereIn('status', ['active', 'isolated'])
            ->whereNotNull('pppoe_user')
            ->where('pppoe_user', '<>', '')
            ->orderBy('id')
            ->get(['id', 'code', 'name', 'pppoe_user', 'pppoe_remote_address', 'status', 'area_id', 'router_id', 'is_online'])
            ->filter(fn (Customer $customer) => filled($customer->pppoe_remote_address) || isset($activePppByUser[$customer->pppoe_user]))
            ->map(fn (Customer $customer) => array_filter([
                'source_id' => $customer->id,
                'code' => $customer->code,
                'name' => $scope === 'full' ? $customer->name : null,
                'pppoe_user' => $customer->pppoe_user,
                'ip_address' => $customer->pppoe_remote_address ?: ($activePppByUser[$customer->pppoe_user]['ip_address'] ?? null),
                'status' => $customer->status,
                'is_online' => (bool) $customer->is_online,
                'area' => $customer->area?->name ?: 'UNKNOWN',
                'router' => $activePppByUser[$customer->pppoe_user]['router'] ?? $customer->router?->name,
            ], fn ($value) => $value !== null))
            ->values();

        $pulse = app(MtPlanCPulseService::class);
        $routers = Router::query()
            ->orderBy('id')
            ->get(['id', 'name', 'ip_address', 'port', 'username', 'is_active', 'connection_status', 'cpu_load', 'current_online_count', 'total_pppoe_count', 'last_health_check_at'])
            ->map(function (Router $router) use ($pulse) {
                $router = $pulse->decorateRouterForDisplay($router);

                return [
                    'source_id' => $router->id,
                    'name' => $router->name,
                    'ip_address' => $router->ip_address,
                    'port' => $router->port ?: 8728,
                    'username' => $router->username ?: 'monitor',
                    'is_active' => (bool) $router->is_active,
                    'connection_status' => $router->connection_status,
                    'cpu_load' => $router->cpu_load,
                    'current_online_count' => $router->current_online_count,
                    'total_pppoe_count' => $router->total_pppoe_count,
                    'last_health_check_at' => $router->last_health_check_at?->toISOString(),
                    'mt_pulse_active' => (bool) $router->getAttribute('mt_pulse_active'),
                    'mt_pulse_stale_s' => $router->getAttribute('mt_pulse_stale_s'),
                    'mt_pulse_quality' => $router->getAttribute('mt_pulse_quality'),
                ];
            })
            ->values();

        $stagedCustomers = DB::table('router_staged_customers')
            ->join('routers', 'routers.id', '=', 'router_staged_customers.router_id')
            ->where('router_staged_customers.status', '<>', 'gone')
            ->whereNull('router_staged_customers.matched_customer_id')
            ->orderBy('router_staged_customers.id')
            ->get([
                'router_staged_customers.id',
                'router_staged_customers.pppoe_user',
                'router_staged_customers.profile',
                'router_staged_customers.disabled',
                'router_staged_customers.raw_payload',
                'routers.name as router_name',
            ])
            ->map(function ($row) {
                $raw = json_decode($row->raw_payload ?: '{}', true) ?: [];

                return [
                    'source_id' => $row->id,
                    'source' => 'mikrotik-staged',
                    'name' => $row->pppoe_user ?: ($raw['name'] ?? null),
                    'pppoe_user' => $row->pppoe_user,
                    'ip_address' => $raw['remote-address'] ?? null,
                    'status' => $row->disabled ? 'isolated' : 'active',
                    'is_online' => ! $row->disabled,
                    'profile' => $row->profile,
                    'area' => $row->router_name ?: 'UNKNOWN',
                    'router' => $row->router_name,
                ];
            })
            ->filter(fn (array $row) => filled($row['ip_address']) && filter_var($row['ip_address'], FILTER_VALIDATE_IP))
            ->values();

        $pppoeActive = $customers
            ->filter(fn (array $row) => ($row['is_online'] ?? false) && filled($row['ip_address'] ?? null))
            ->map(fn (array $row) => [
                'source_id' => $row['source_id'],
                'name' => $row['name'] ?? null,
                'pppoe_user' => $row['pppoe_user'] ?? null,
                'ip_address' => $row['ip_address'] ?? null,
                'area' => $row['area'] ?? null,
                'router' => $row['router'] ?? null,
            ])
            ->values();

        $genieAcsDevices = GenieAcsDevice::query()
            ->with(['customer:id,name,pppoe_user'])
            ->orderBy('id')
            ->get(['id', 'device_id', 'customer_id', 'serial_number', 'manufacturer', 'product_class', 'software_version', 'ip_address', 'last_inform_at', 'updated_at'])
            ->map(fn (GenieAcsDevice $device) => [
                'source_id' => $device->id,
                'device_id' => $device->device_id,
                'customer_id' => $device->customer_id,
                'customer_name' => $device->customer?->name ?: $device->pelanggan,
                'pppoe_user' => $device->customer?->pppoe_user,
                'serial_number' => $device->serial_number,
                'manufacturer' => $device->manufacturer,
                'product_class' => $device->product_class,
                'software_version' => $device->software_version,
                'ip_address' => $device->ip_address,
                'last_inform_at' => $device->last_inform_at?->toISOString(),
                'is_fresh' => (bool) ($device->last_inform_at && $device->last_inform_at->gt(now()->subMinutes(30))),
                'match_source' => $device->match_source,
            ])
            ->values();

        $oltOnus = Olt::query()
            ->orderBy('id')
            ->get(['id', 'name', 'vendor', 'last_gpon_snapshot', 'last_gpon_synced_at'])
            ->flatMap(function (Olt $olt) {
                // The monitoring_snapshots table/model was dropped; the cached GPON snapshot lives on the OLT row.
                $snapshot = is_array($olt->last_gpon_snapshot) ? $olt->last_gpon_snapshot : [];

                return collect($snapshot['onus'] ?? [])->map(fn (array $onu) => [
                    'olt_id' => $olt->id,
                    'olt_name' => $olt->name,
                    'vendor' => $olt->vendor,
                    'onu_ref' => $onu['onu_ref'] ?? null,
                    'pon_port' => $onu['pon_port'] ?? null,
                    'state' => $onu['state'] ?? null,
                    'phase_state' => $onu['phase_state'] ?? null,
                    'serial_number' => $onu['serial_number'] ?? null,
                    'onu_name' => $onu['onu_name'] ?? null,
                    'rx_power_dbm' => $onu['rx_power_dbm'] ?? null,
                    'distance_m' => $onu['distance_m'] ?? null,
                    'last_synced_at' => $olt->last_gpon_synced_at?->toISOString(),
                ]);
            })
            ->values();

        return response()->json([
            'exported_at' => now()->toISOString(),
            'generated_at' => now()->toDateTimeString(),
            'stats' => [
                'ebilling_customers_with_ip' => $customers->count(),
                'mikrotik_staged_candidates' => $stagedCustomers->count(),
                'combined_unique_ip' => $customers->pluck('ip_address')->merge($stagedCustomers->pluck('ip_address'))->unique()->count(),
                'pppoe_active' => $pppoeActive->count(),
                'genieacs_devices' => $genieAcsDevices->count(),
                'olt_onus' => $oltOnus->count(),
            ],
            'customers' => $customers->values(),
            'pppoe_unmatched' => $stagedCustomers->values(),
            'pppoe_active' => $pppoeActive,
            'genieacs_devices' => $genieAcsDevices,
            'olt_onus' => $oltOnus,
            'routers' => $routers,
        ]);
    }

    private function activePppByUser(): array
    {
        $sessions = [];

        Router::query()
            ->whereNotNull('ip_address')
            ->whereNotNull('username')
            ->get(['name', 'ip_address', 'port', 'username', 'password'])
            ->each(function (Router $router) use (&$sessions) {
                try {
                    $client = new Client(new Config([
                        'host' => $router->ip_address,
                        'user' => $router->username,
                        'pass' => $router->password,
                        'port' => (int) ($router->port ?: 8728),
                        'timeout' => 4,
                        'attempts' => 1,
                        'delay' => 1,
                    ]));

                    foreach ($this->safeRouterRead($client, '/ppp/active/print') as $row) {
                        $user = $row['name'] ?? null;
                        $address = $row['address'] ?? null;

                        if (! filled($user) || ! filled($address)) {
                            continue;
                        }

                        $sessions[$user] = [
                            'ip_address' => $address,
                            'router' => $router->name,
                        ];
                    }

                    $this->persistActivePppSessions($router, $sessions);
                } catch (\Throwable) {
                    return;
                }
            });

        return $sessions;
    }

    private function persistActivePppSessions(Router $router, array $sessions): void
    {
        $routerSessions = collect($sessions)
            ->filter(fn (array $session) => ($session['router'] ?? null) === $router->name)
            ->filter(fn (array $session) => filter_var($session['ip_address'] ?? null, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4));

        if ($routerSessions->isEmpty()) {
            return;
        }

        $sessionsByUser = $routerSessions->mapWithKeys(
            fn (array $session, string $username) => [$username => $session['ip_address']]
        );

        Customer::withoutEvents(function () use ($router, $sessionsByUser) {
            Customer::query()
                ->whereIn('pppoe_user', $sessionsByUser->keys())
                ->whereNull('deleted_at')
                ->get(['id', 'pppoe_user'])
                ->each(function (Customer $customer) use ($router, $sessionsByUser) {
                    Customer::whereKey($customer->id)->update([
                        'router_id' => $router->id,
                        'pppoe_remote_address' => $sessionsByUser[$customer->pppoe_user] ?? null,
                        'mikrotik_sync_checked_at' => now(),
                    ]);
                });
        });

        // This export read swallows router errors (safeRouterRead) and has a
        // 4s budget, so it can never prove a complete /ppp/active list and must
        // not demote anyone. Names seen are fresh online evidence: promote them
        // with a fresh pppoe_online_checked_at. Everyone else keeps an ageing
        // stamp (read model => "unknown" once stale) until the scheduled
        // authoritative session sync writes online AND offline.
        app(PppoeOnlineFlagWriter::class)->applySeen($router, $sessionsByUser->keys()->all());
    }

    public function noc(Request $request): RedirectResponse
    {
        return redirect()->route('monitoring.index', ['tab' => 'noc']);
    }

    public function index(Request $request): Response|RedirectResponse
    {
        // The old Overview/legacy tabs are gone; keep bookmarks working.
        if (in_array($request->query('tab'), ['overview', 'overview-legacy', 'infrastructure', 'analytics'], true)) {
            return redirect()->route('monitoring.index', array_merge($request->query(), ['tab' => 'jaringan']));
        }

        $payload = Cache::get('monitoring.index.payload');
        $generatedAt = Cache::get('monitoring.index.payload_generated_at');

        if (! $payload || ! $generatedAt) {
            // Cold start: never block HTTP 10–20s on full generatePayload.
            // Prefer the last-good snapshot so the cockpit shows real (slightly
            // stale) data instead of an empty stub while the cache refills.
            if (Cache::add('monitoring.index.payload_revalidating', true, now()->addMinutes(2))) {
                RefreshMonitoringCacheJob::dispatch();
            }

            $lastGood = Cache::get('monitoring.index.payload.last_good');
            if (is_array($lastGood) && $lastGood !== []) {
                $payload = $lastGood;
                $generatedAt = Cache::get('monitoring.index.payload.last_good_at') ?: now()->getTimestamp();
                $payload['source']['mode'] = 'last_good';
            } else {
                $payload = $this->lightweightMonitoringStub();
                $generatedAt = now()->getTimestamp();
                $payload['source']['mode'] = 'cold_stub';
            }
        } else {
            // Viewer-triggered revalidation is only a safety net for the
            // 5-min scheduler / 6-min TTL (RefreshMonitoringCacheJob). At 30s every
            // open tab forced a full generatePayload every ~30s; 90s means a
            // refresh is only dispatched when the cache is about to expire.
            if (now()->getTimestamp() - $generatedAt > self::REVALIDATE_AFTER_SECONDS) {
                if (Cache::add('monitoring.index.payload_revalidating', true, now()->addMinutes(2))) {
                    RefreshMonitoringCacheJob::dispatch();
                }
            }
        }

        $payload['source']['cachedAt'] = $generatedAt ? date('c', $generatedAt) : now()->toISOString();
        // Keep explicit fallback modes; otherwise mark the normal cached path.
        if (! in_array($payload['source']['mode'] ?? '', ['cold_stub', 'last_good'], true)) {
            $payload['source']['mode'] = 'lazy_cache';
        }

        $summary = $payload['summary'] ?? [];
        $trafficEnabled = (bool) config('services.monitoring.traffic.enabled', false);

        // First HTML paint: only shell props (tiny data-page). Heavy panels load via Inertia deferred request.
        return Inertia::render('Monitoring/Index', [
            'source' => $payload['source'] ?? ['mode' => 'lazy_cache', 'label' => 'eBilling'],
            'summary' => $summary,
            'trafficEnabled' => $trafficEnabled,
            'viewTab' => in_array($request->query('tab'), ['gangguan', 'deteksi', 'jaringan', 'alerts', 'noc'], true)
                || ($request->query('tab') === 'traffic' && $trafficEnabled)
                ? $request->query('tab')
                : 'jaringan',
            'connectivityAlerts' => $this->connectivityAlertSettingsPayload(false),

            // Deferred: not embedded in initial data-page HTML
            'tickets' => Inertia::defer(fn () => $payload['tickets'] ?? [], 'dashboard'),
            'badSignalCustomers' => Inertia::defer(fn () => $payload['badSignalCustomers'] ?? [], 'dashboard'),
            'grafana' => Inertia::defer(fn () => $payload['grafana'] ?? ['enabled' => false, 'url' => null, 'dashboards' => [], 'error' => null], 'dashboard'),
            'customerConnectivity' => Inertia::defer(fn () => $payload['customerConnectivity'] ?? [], 'dashboard'),
            'gangguanSignals' => Inertia::defer(fn () => $payload['gangguanSignals'] ?? [], 'dashboard'),
            'unifiedConnectivity' => Inertia::defer(
                fn () => app(UnifiedConnectivityReadModel::class)->payload($request),
                'monitoring-unified'
            ),
            'nocInbox' => Inertia::defer(
                fn () => app(NocMonitoringReadModel::class)->payload($request),
                'monitoring-noc'
            ),
            'fhttBacklog' => Inertia::defer(function () {
                $fhttKey = 'monitoring.fhtt_backlog.v1';
                $cached = Cache::get($fhttKey);
                if ($cached !== null) {
                    return $cached;
                }

                if (! class_exists(FhttEnrollmentService::class)) {
                    // Enrollment service is not shipped in this build; report the
                    // gap honestly instead of masking a container error as empty.
                    return [
                        'items' => [],
                        'total' => 0,
                        'error' => false,
                        'available' => false,
                        'note' => 'Fitur backlog FHTT belum tersedia di build ini.',
                    ];
                }

                try {
                    $data = app(FhttEnrollmentService::class)->backlog(null, 'olt_only', 15);
                    Cache::put($fhttKey, $data, now()->addMinutes(3));

                    return $data;
                } catch (\Throwable) {
                    return ['items' => [], 'total' => 0, 'error' => true];
                }
            }, 'dashboard'),
        ]);
    }

    /**
     * Scoped monitoring incidents (PON/ODC + ODP) for the Gangguan tab.
     * Area-scoped admins only see incidents in their areas.
     */
    public function incidents(Request $request, MonitoringIncidentReadModel $incidents): JsonResponse
    {
        $filters = [
            'area' => (string) $request->query('area', ''),
        ];

        $user = $request->user();
        if ($user && method_exists($user, 'isGlobalAdmin') && ! $user->isGlobalAdmin()) {
            $names = AreaScope::applyToAreas(Area::query(), $user)
                ->pluck('name')
                ->all();
            // Non-global admin with no explicit scope sees nothing rather than everything.
            $filters['areas'] = $names === [] ? ['__none__'] : $names;
        }

        return response()->json($incidents->payload($filters));
    }

    public function getConnectivityAlertSettings(): JsonResponse
    {
        return response()->json($this->connectivityAlertSettingsPayload());
    }

    public function saveConnectivityAlertSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // The alert group lives on Pengaturan > Grup WhatsApp (WaGroupSettingsWriter) only.
            'group_id' => ['prohibited'],
            'enabled' => ['nullable', 'boolean'],
            'dry_run' => ['nullable', 'boolean'],
        ], [
            'group_id.prohibited' => 'Grup alert dipindah ke Pengaturan › Grup WhatsApp.',
        ]);

        Setting::set(
            'connectivity_alerts_enabled',
            ! empty($validated['enabled']) ? '1' : '0'
        );
        Setting::set(
            'connectivity_alerts_dry_run',
            array_key_exists('dry_run', $validated)
                ? (! empty($validated['dry_run']) ? '1' : '0')
                : '1'
        );

        return response()->json([
            'status' => 'success',
            'settings' => $this->connectivityAlertSettingsPayload(),
        ]);
    }

    public function fetchConnectivityWaGroups(): JsonResponse
    {
        return app(SettingController::class)->waGroups(request());
    }

    /**
     * @return array{
     *   group_id: string,
     *   enabled: bool,
     *   dry_run: bool,
     *   group_label: string|null
     * }
     */
    public function connectivityAlertSettingsPayload(bool $resolveLabel = true): array
    {
        $settings = app(ConnectivityAlertSettings::class);
        if ($resolveLabel) {
            $payload = $settings->payload(fn (string $groupId) => $this->resolveWaGroupLabel($groupId));
            $payload['group_label'] = $payload['group_label'] ?? $this->resolveWaGroupLabel($payload['group_id']);
        } else {
            // Fast path for page open — label fills later from cache/API when needed.
            $payload = $settings->payload(fn () => null);
            $payload['group_label'] = null;
            $gid = (string) ($payload['group_id'] ?? '');
            if ($gid !== '') {
                $cached = Cache::get('monitoring.wa_group_label.'.md5($gid));
                if (is_string($cached)) {
                    $payload['group_label'] = $cached;
                }
            }
        }

        // The group itself is edited on Pengaturan > Grup WhatsApp (superadmin + admin without area scope).
        $user = request()->user();
        $payload['can_manage_wa_groups'] = (bool) ($user && ($user->isSuperAdmin() || ($user->isAdmin() && ! $user->hasAreaScope())));

        return $payload;
    }

    protected function resolveWaGroupLabel(string $groupId): ?string
    {
        if ($groupId === '') {
            return null;
        }

        $cacheKey = 'monitoring.wa_group_label.'.md5($groupId);
        $cached = Cache::get($cacheKey);
        if (is_string($cached) || $cached === false) {
            return $cached === false ? null : $cached;
        }

        try {
            // Reuse Evolution service cache (groups TTL ~90s) instead of 8s blocking HTTP every page load.
            $groups = app(EvolutionApiService::class)->fetchGroups();
            foreach ($groups as $group) {
                if (($group['id'] ?? '') === $groupId) {
                    $label = $group['subject'] ?? $group['name'] ?? $groupId;
                    Cache::put($cacheKey, $label, now()->addMinutes(10));

                    return $label;
                }
            }
        } catch (\Throwable) {
            // fall through
        }

        Cache::put($cacheKey, false, now()->addMinutes(2));

        return null;
    }

    /**
     * Minimal payload for cold cache so /monitoring opens in <1s.
     * Full metrics arrive after RefreshMonitoringCacheJob.
     */
    protected function lightweightMonitoringStub(): array
    {
        $activeCustomers = (int) Customer::where('status', 'active')->count();
        $onlineCustomers = (int) Customer::where('status', 'active')->where('is_online', true)->count();
        $routerTotal = (int) Router::count();
        $routerOnline = (int) Router::where('connection_status', 'online')->count();

        return [
            'summary' => [
                'uptime' => $routerTotal > 0 ? round(($routerOnline / $routerTotal) * 100, 2) : null,
                'activeSubscribers' => $activeCustomers,
                'onlineSubscribers' => $onlineCustomers,
                'badSignalOnts' => 0,
                'routerTotal' => $routerTotal,
                'routerOnline' => $routerOnline,
                'oltTotal' => 0,
                'oltWithFreshData' => 0,
                'acsOnline' => 0,
                'acsTotal' => 0,
                'serviceOnline' => 0,
                'serviceOnlineTotal' => 0,
                'ponOnlyOnline' => 0,
                'pppoeOnlyOnline' => 0,
                'customerCompositeOnline' => null,
                'customerSetupRequired' => null,
                'gangguanActive' => null,
                'openTickets' => 0,
                'urgentTickets' => 0,
            ],
            // Keep object shape stable: the UI reads tickets.open/urgent.
            'tickets' => ['open' => 0, 'urgent' => 0],
            'badSignalCustomers' => [],
            'alerts' => [],
            'source' => [
                'mode' => 'cold_stub',
                'cachedAt' => now()->toISOString(),
                'warming' => true,
            ],
        ];
    }

    public function generatePayload(): array
    {
        $activeCustomers = Customer::where('status', 'active')->count();
        $onlineCustomers = Customer::where('status', 'active')->where('is_online', true)->count();

        $routers = $this->routerRows();

        $routerTotal = $routers->count();
        $routerOnline = $routers->where('status', 'online')->count();
        $routerAvailability = $routerTotal > 0 ? round(($routerOnline / $routerTotal) * 100, 2) : null;

        $oltStats = $this->oltStats();
        $genieAcsStats = $this->genieAcsStats();
        $serviceOnlineStats = $this->serviceOnlineStats();
        $ticketStats = $this->ticketStats();

        $weakRxThreshold = (float) config('services.monitoring.weak_rx_dbm', -27);

        $badSignalOntsCount = Customer::query()
            ->badSignalOntOnline($weakRxThreshold)
            ->count();

        $badSignalCustomers = Customer::query()
            ->with(['area:id,name', 'olt:id,name'])
            ->badSignalOntOnline($weakRxThreshold)
            ->orderBy('onu_rx_power_dbm')
            ->limit(20)
            ->get(['id', 'code', 'name', 'area_id', 'olt_id', 'olt_port_label', 'onu_rx_power_dbm', 'olt_status', 'olt_last_synced_at'])
            ->map(fn (Customer $customer) => [
                'id' => $customer->id,
                'code' => $customer->code,
                'name' => $customer->name,
                'area' => $customer->area?->name,
                'olt' => $customer->olt?->name,
                'port' => $customer->olt_port_label,
                'rx' => $customer->onu_rx_power_dbm !== null ? (float) $customer->onu_rx_power_dbm : null,
                'olt_status' => $customer->olt_status,
                'synced_at' => $customer->olt_last_synced_at?->toISOString(),
            ])
            ->values();

        $customerConnectivity = $this->customerConnectivityComposite();
        $gangguanSignals = $this->gangguanSignals();

        return [
            'source' => [
                'label' => 'Cached dari database eBilling.',
                'mode' => 'lazy_cache',
                'cachedAt' => now()->toISOString(),
                'cacheTtlSeconds' => 30,
            ],
            'summary' => [
                'uptime' => $routerAvailability,
                'activeSubscribers' => $activeCustomers,
                'badSignalOnts' => $badSignalOntsCount,
                'onlineSubscribers' => $onlineCustomers,
                'routerOnline' => $routerOnline,
                'routerTotal' => $routerTotal,
                'oltTotal' => $oltStats['total_olts'],
                'oltWithFreshData' => $oltStats['fresh_olts'],
                'acsOnline' => $genieAcsStats['online'],
                'acsTotal' => $genieAcsStats['total'],
                'serviceOnline' => $serviceOnlineStats['service_online'],
                'serviceOnlineTotal' => $serviceOnlineStats['active_total'],
                'ponOnlyOnline' => $serviceOnlineStats['pon_only'],
                'pppoeOnlyOnline' => $serviceOnlineStats['pppoe_only'],
                'customerCompositeOnline' => $customerConnectivity['composite_online'] ?? null,
                'customerSetupRequired' => $customerConnectivity['setup_required'] ?? null,
                'gangguanActive' => $gangguanSignals['active_total'] ?? null,
                'openTickets' => $ticketStats['open'],
                'urgentTickets' => $ticketStats['urgent'],
            ],
            'tickets' => $ticketStats,
            'badSignalCustomers' => $badSignalCustomers,
            'grafana' => $this->grafanaDashboards(),
            'customerConnectivity' => $customerConnectivity,
            'gangguanSignals' => $gangguanSignals,
        ];
    }

    public function refreshRouters(): JsonResponse
    {
        $rows = $this->routerRows();

        return response()->json([
            'summary' => [
                'online' => $rows->where('status', 'online')->count(),
                'total' => $rows->count(),
            ],
            'routers' => $rows,
        ]);
    }

    public function refreshRouterStatus(Router $router): JsonResponse
    {
        // Browser refresh is snapshot-only. Device reads belong to collectors/jobs.
        return response()->json([
            'router' => $this->routerPayload($router),
        ]);
    }

    private function routerRows(): Collection
    {
        return Router::query()
            ->where(function ($q) {
                $q->where('is_active', true);
                if (Schema::hasColumn('routers', 'monitoring_enabled')) {
                    $q->orWhere('monitoring_enabled', true);
                }
            })
            ->orderBy('name')
            ->get(['id', 'name', 'ip_address', 'port', 'connection_status', 'current_online_count', 'total_pppoe_count', 'cpu_load', 'uptime', 'version', 'board_name', 'last_health_check_at', 'last_scanned_at', 'sync_message'])
            ->map(fn (Router $router) => $this->routerPayload($router));
    }

    private function routerPayload(Router $router, array $extra = []): array
    {
        $router = app(MtPlanCPulseService::class)->decorateRouterForDisplay($router);
        $online = (int) ($router->current_online_count ?? 0);
        $total = (int) ($router->total_pppoe_count ?? 0);

        return array_merge([
            'id' => $router->id,
            'name' => $router->name,
            'ip' => $router->ip_address,
            'port' => $router->port,
            'status' => $router->connection_status,
            'online' => $online,
            'total' => $total,
            'online_percent' => $total > 0 ? round(($online / $total) * 100, 2) : null,
            'cpu' => $router->cpu_load,
            'uptime' => $router->uptime,
            'version' => $router->version,
            'board' => $router->board_name,
            'last_check' => $router->last_health_check_at?->toISOString(),
            'message' => $router->sync_message,
            'mt_pulse_active' => (bool) $router->getAttribute('mt_pulse_active'),
            'mt_pulse_stale_s' => $router->getAttribute('mt_pulse_stale_s'),
            'mt_pulse_quality' => $router->getAttribute('mt_pulse_quality'),
        ], $extra);
    }

    private function safeRouterRead(Client $client, string $path): array
    {
        try {
            return $client->query(new Query($path))->read();
        } catch (\Throwable) {
            return [];
        }
    }

    private function grafanaDashboards(): array
    {
        return app(GrafanaClient::class)->dashboards();
    }

    private function oltStats(): array
    {
        $olts = Olt::query()
            ->orderBy('id')
            ->get(['id', 'name', 'vendor', 'management_ip', 'last_gpon_snapshot', 'last_gpon_synced_at']);

        $rows = [];
        $totalOnus = 0;
        $onlineOnus = 0;
        $withRx = 0;
        $badSignal = 0;
        $criticalSignal = 0;
        $freshOlts = 0;

        $collectorHealth = Cache::get('olt.collector.health', []);
        $healthByOlt = collect($collectorHealth['rows'] ?? [])->keyBy('olt_id');
        $freshWindow = now()->subHours(6);

        // Enterprise path: prefer olt_onus live counters (live-sync / SNMP update these continuously).
        $onuAgg = Schema::hasTable('olt_onus')
            ? OltOnu::query()
                ->selectRaw('olt_id')
                ->selectRaw('COUNT(*) as total')
                ->selectRaw("SUM(CASE WHEN state = 'online' THEN 1 ELSE 0 END) as online")
                ->selectRaw('SUM(CASE WHEN rx_power_dbm IS NOT NULL THEN 1 ELSE 0 END) as with_rx')
                ->selectRaw('SUM(CASE WHEN rx_power_dbm IS NOT NULL AND rx_power_dbm < -27 THEN 1 ELSE 0 END) as bad')
                ->selectRaw('SUM(CASE WHEN rx_power_dbm IS NOT NULL AND rx_power_dbm < -30 THEN 1 ELSE 0 END) as critical')
                ->selectRaw('MAX(state_collected_at) as latest_state')
                ->selectRaw('MAX(rx_collected_at) as latest_rx')
                ->groupBy('olt_id')
                ->get()
                ->keyBy('olt_id')
            : collect();

        foreach ($olts as $olt) {
            $snapshot = is_array($olt->last_gpon_snapshot) ? $olt->last_gpon_snapshot : [];
            $snapOnus = collect($snapshot['onus'] ?? []);
            $agg = $onuAgg->get($olt->id);
            $useEnterprise = $agg && (int) $agg->total > 0;

            if ($useEnterprise) {
                $total = (int) $agg->total;
                $online = (int) $agg->online;
                $rxCount = (int) $agg->with_rx;
                $bad = (int) $agg->bad;
                $critical = (int) $agg->critical;
            } else {
                $total = $snapOnus->count();
                $online = $snapOnus->filter(fn ($onu) => $this->isOnuOnline($onu))->count();
                $rxValues = $snapOnus
                    ->map(fn ($onu) => $onu['rx_power_dbm'] ?? null)
                    ->filter(fn ($rx) => $rx !== null)
                    ->map(fn ($rx) => (float) $rx);
                $rxCount = $rxValues->count();
                $bad = $rxValues->filter(fn (float $rx) => $rx < -27)->count();
                $critical = $rxValues->filter(fn (float $rx) => $rx < -30)->count();
            }

            // Fresh = any of snapshot sync / state collect / rx collect within 6h.
            $candidates = array_filter([
                $olt->last_gpon_synced_at,
                $agg->latest_state ?? null,
                $agg->latest_rx ?? null,
            ]);
            $latest = null;
            foreach ($candidates as $ts) {
                $c = $ts instanceof Carbon ? $ts : (Carbon::parse($ts));
                if ($latest === null || $c->gt($latest)) {
                    $latest = $c;
                }
            }
            $fresh = $latest !== null && $latest->gt($freshWindow);
            $enterprise = $useEnterprise || (($snapshot['meta']['collector'] ?? '') === 'olt_onus');
            $health = $healthByOlt->get($olt->id);

            $totalOnus += $total;
            $onlineOnus += $online;
            $withRx += $rxCount;
            $badSignal += $bad;
            $criticalSignal += $critical;
            $freshOlts += $fresh ? 1 : 0;

            $rows[] = [
                'id' => $olt->id,
                'name' => $olt->name,
                'vendor' => $olt->vendor,
                'ip' => $olt->management_ip,
                'enterprise' => $enterprise,
                // No dedicated collector-health writer exists yet; fall back to
                // data freshness so an enterprise OLT with recent state/RX is
                // reported OK instead of always "stale".
                'collector_healthy' => $health['healthy'] ?? $fresh,
                'total_onus' => $total,
                'online_onus' => $online,
                'with_rx' => $rxCount,
                'bad_signal' => $bad,
                'critical_signal' => $critical,
                'last_sync' => ($latest ?? $olt->last_gpon_synced_at)?->toISOString(),
                'fresh' => $fresh,
                'source' => $useEnterprise ? 'olt_onus' : 'snapshot',
            ];
        }

        return [
            'total_olts' => $olts->count(),
            'fresh_olts' => $freshOlts,
            'total_onus' => $totalOnus,
            'online_onus' => $onlineOnus,
            'with_rx' => $withRx,
            'bad_signal_onts' => $badSignal,
            'critical_signal_onts' => $criticalSignal,
            'rows' => $rows,
        ];
    }

    private function genieAcsStats(): array
    {
        $devices = GenieAcsDevice::query()
            ->get(['id', 'manufacturer', 'ssid', 'last_inform_at', 'customer_id', 'last_ping_at', 'last_ping_success', 'last_ping_rtt_ms']);

        $online = $devices->filter(fn (GenieAcsDevice $device) => $device->last_inform_at && $device->last_inform_at->gt(now()->subMinutes(30)))->count();
        $pingFreshMinutes = (int) config('services.genieacs.ping_fresh_minutes', 45);
        $pingOk = $devices->filter(
            fn (GenieAcsDevice $device) => $device->last_ping_success
                && $device->last_ping_at
                && $device->last_ping_at->gt(now()->subMinutes($pingFreshMinutes))
        )->count();

        return [
            'total' => $devices->count(),
            'online' => $online,
            'offline' => max($devices->count() - $online, 0),
            'fiberhome' => $devices->filter(fn (GenieAcsDevice $device) => str_contains(strtolower((string) $device->manufacturer), 'fiber'))->count(),
            'linked' => $devices->whereNotNull('customer_id')->count(),
            'ssid_read' => $devices->filter(fn (GenieAcsDevice $device) => filled($device->ssid))->count(),
            'ping_ok' => $pingOk,
            'ping_failed_recent' => $devices->filter(
                fn (GenieAcsDevice $device) => $device->last_ping_at
                    && $device->last_ping_at->gt(now()->subMinutes($pingFreshMinutes))
                    && $device->last_ping_success === false
            )->count(),
        ];
    }

    private function serviceOnlineStats(): array
    {
        $active = Customer::query()->where('status', 'active');
        $activeTotal = (clone $active)->count();

        $hasServiceOnline = Schema::hasColumn('customers', 'service_online');
        $serviceOnline = $hasServiceOnline
            ? (clone $active)->where('service_online', true)->count()
            : (clone $active)
                ->where(function ($query) {
                    $query->where('is_online', true)
                        ->orWhere('olt_status', 'online')
                        ->orWhere('olt_status', 'like', '%working%');
                })
                ->count();
        $ponOnly = (clone $active)
            ->where(function ($q) {
                $q->where('olt_status', 'online')
                    ->orWhere('olt_status', 'like', '%working%');
            })
            ->where('is_online', false)
            ->count();
        $pppoeOnly = (clone $active)
            ->where('is_online', true)
            ->where(function ($q) {
                $q->whereNull('olt_status')
                    ->orWhere('olt_status', 'offline')
                    ->orWhere('olt_status', 'not like', '%online%');
            })
            ->count();

        return [
            'active_total' => $activeTotal,
            'service_online' => $serviceOnline,
            'pon_only' => $ponOnly,
            'pppoe_only' => $pppoeOnly,
            'pppoe_online' => (clone $active)->where('is_online', true)->count(),
            'layers' => [
                'pon' => 'olt_status dari OLT C300 (GPON registered)',
                'pppoe' => 'is_online dari MikroTik /ppp/active',
                'internet' => 'GenieACS IPPing ke '.config('services.genieacs.ping_host', '8.8.8.8'),
            ],
        ];
    }

    private function customerConnectivityComposite(): array
    {
        $active = Customer::query()->where('status', 'active');
        $activeTotal = (clone $active)->count();
        $pppoeOnline = (clone $active)->where('is_online', true)->count();
        $ponOnline = (clone $active)
            ->where(function ($q) {
                $q->where('olt_status', 'online')
                    ->orWhere('olt_status', 'working')
                    ->orWhere('olt_status', 'like', '%online%')
                    ->orWhere('olt_status', 'like', '%working%');
            })
            ->count();

        $genieFresh = 0;
        $geniePingOk = 0;
        if (Schema::hasTable('genieacs_devices')) {
            $freshAt = now()->subMinutes((int) config('services.genieacs.online_minutes', 30));
            $pingFreshAt = now()->subMinutes((int) config('services.genieacs.ping_fresh_minutes', 45));

            $genieFresh = Customer::query()
                ->where('status', 'active')
                ->whereExists(function ($query) use ($freshAt) {
                    $query->selectRaw('1')
                        ->from('genieacs_devices')
                        ->whereColumn('genieacs_devices.customer_id', 'customers.id')
                        ->where('genieacs_devices.last_inform_at', '>=', $freshAt);
                })
                ->count();

            $geniePingOk = Customer::query()
                ->where('status', 'active')
                ->whereExists(function ($query) use ($pingFreshAt) {
                    $query->selectRaw('1')
                        ->from('genieacs_devices')
                        ->whereColumn('genieacs_devices.customer_id', 'customers.id')
                        ->where('genieacs_devices.last_ping_success', true)
                        ->where('genieacs_devices.last_ping_at', '>=', $pingFreshAt);
                })
                ->count();
        }

        $compositeOnline = Customer::query()
            ->where('status', 'active')
            ->where(function ($query) {
                $query->where('is_online', true)
                    ->orWhere('olt_status', 'online')
                    ->orWhere('olt_status', 'working')
                    ->orWhere('olt_status', 'like', '%online%')
                    ->orWhere('olt_status', 'like', '%working%');

                if (Schema::hasTable('genieacs_devices')) {
                    $freshAt = now()->subMinutes((int) config('services.genieacs.online_minutes', 30));
                    $pingFreshAt = now()->subMinutes((int) config('services.genieacs.ping_fresh_minutes', 45));
                    $query->orWhereExists(function ($exists) use ($freshAt, $pingFreshAt) {
                        $exists->selectRaw('1')
                            ->from('genieacs_devices')
                            ->whereColumn('genieacs_devices.customer_id', 'customers.id')
                            ->where(function ($inner) use ($freshAt, $pingFreshAt) {
                                $inner->where('genieacs_devices.last_inform_at', '>=', $freshAt)
                                    ->orWhere(function ($ping) use ($pingFreshAt) {
                                        $ping->where('genieacs_devices.last_ping_success', true)
                                            ->where('genieacs_devices.last_ping_at', '>=', $pingFreshAt);
                                    });
                            });
                    });
                }
            })
            ->count();

        $setupRequired = Customer::query()
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('router_id')
                    ->orWhere(function ($olt) {
                        $olt->whereNull('olt_id')
                            ->whereNull('onu_serial')
                            ->whereNull('olt_port_label');
                    });
            })
            ->count();

        return [
            'active_total' => $activeTotal,
            'composite_online' => $compositeOnline,
            'pppoe_online' => $pppoeOnline,
            'pon_online' => $ponOnline,
            'genieacs_fresh' => $genieFresh,
            'genieacs_ping_ok' => $geniePingOk,
            'setup_required' => $setupRequired,
            'checked_at' => now()->toISOString(),
            'note' => 'Composite online memakai PPPoE, PON/OLT, GenieACS inform, dan GenieACS ping; FiberHome/TR-069 tidak dianggap offline hanya karena host ping gagal.',
        ];
    }

    private function gangguanSignals(): array
    {
        if (! Schema::hasTable('monitoring_alert_events')) {
            return [
                'active_total' => 0,
                'critical' => 0,
                'warning' => 0,
                'by_issue' => [],
                'recent' => [],
                'source' => 'table_missing',
            ];
        }

        // Count every active alert source (connectivity scan AND OLT collector
        // weak-RX/collector-failed). Previously only source=connectivity_scan
        // was counted, so OLT alerts never surfaced in the cockpit.
        $active = DB::table('monitoring_alert_events')->where('status', 'active');

        return [
            'active_total' => (clone $active)->count(),
            'critical' => (clone $active)->where('severity', 'critical')->count(),
            'warning' => (clone $active)->where('severity', 'warning')->count(),
            'by_issue' => (clone $active)
                ->selectRaw('issue, COUNT(*) as total')
                ->groupBy('issue')
                ->pluck('total', 'issue')
                ->all(),
            'by_source' => (clone $active)
                ->selectRaw('source, COUNT(*) as total')
                ->groupBy('source')
                ->pluck('total', 'source')
                ->all(),
            'recent' => (clone $active)
                ->orderByDesc('last_seen_at')
                ->limit(12)
                ->get(['entity_name', 'issue', 'severity', 'source', 'last_seen_at', 'payload'])
                ->map(fn ($row) => [
                    'entity' => $row->entity_name,
                    'issue' => $row->issue,
                    'severity' => $row->severity,
                    'source' => $row->source,
                    'last_seen_at' => $row->last_seen_at,
                    'payload' => json_decode($row->payload ?: '{}', true) ?: [],
                ])
                ->all(),
            'source' => 'monitoring_alert_events',
        ];
    }

    private function ticketStats(): array
    {
        $openStatuses = ['open', 'assigned', 'pending', 'in_progress'];

        return [
            'open' => SupportTicket::whereIn('status', $openStatuses)->count(),
            'urgent' => SupportTicket::whereIn('status', $openStatuses)->whereIn('priority', ['high', 'urgent'])->count(),
            'today' => SupportTicket::whereBetween('created_at', OpsTime::dayBounds())->count(),
        ];
    }

    private function isOnuOnline(array $onu): bool
    {
        $state = strtolower((string) ($onu['state'] ?? $onu['phase_state'] ?? ''));

        return $state === 'online' || str_contains($state, 'working') || str_contains($state, 'up');
    }
}
