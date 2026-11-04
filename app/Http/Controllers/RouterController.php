<?php

namespace App\Http\Controllers;

use App\Http\Requests\RouterStoreRequest;
use App\Http\Requests\RouterUpdateRequest;
use App\Http\Requests\RouterVpnUpdateRequest;
use App\Jobs\SyncRouterJob;
use App\Models\Customer;
use App\Models\Package;
use App\Models\PppoeAddressPoolBlock;
use App\Models\Router;
use App\Models\RouterProfile;
use App\Models\Setting;
use App\Services\MikrotikService;
use App\Services\MtPlanCPulseService;
use App\Services\RadiusUserService;
use App\Services\RouterSyncService;
use App\Services\WireGuardProvisioningService;
use App\Support\SensitiveDataRedactor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;

class RouterController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Router::query()->withCount('customers');

        // Search
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('ip_address', 'like', "%{$search}%");
            });
        }

        // Filter by status — default: active only (retired MT like Srigading/Lawang stay hidden)
        // Use status=all or status=inactive to see the rest.
        $status = $request->input('status', 'active');
        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        }
        // status=all → no is_active filter

        // Sorting
        $allowedSorts = ['name', 'ip_address', 'connection_status', 'created_at'];
        $sortField = in_array($request->input('sort'), $allowedSorts, true)
            ? $request->input('sort')
            : 'name';
        $sortDirection = $request->input('direction') === 'desc' ? 'desc' : 'asc';
        $query->orderBy($sortField, $sortDirection);

        // Paginate
        $limit = min(max((int) $request->input('limit', 25), 1), 100);
        $routers = $query->paginate($limit)->withQueryString();

        // Plan C / C1: Online/Secret from T0 snapshot when enabled
        $pulse = app(MtPlanCPulseService::class);
        $routers->setCollection(
            $routers->getCollection()->map(fn (Router $r) => $pulse->decorateRouterForDisplay($r))
        );

        return Inertia::render('Routers/Index', [
            'routers' => $routers,
            'filters' => $request->only(['search', 'status', 'sort', 'direction', 'limit']),
            'mt_plan_c' => [
                'use_snapshot_ui' => (bool) config('services.mt_plan_c.use_snapshot_ui', false),
                'max_stale_seconds' => (int) config('services.mt_plan_c.max_stale_seconds', 180),
            ],
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return Inertia::render('Routers/Create', ['ui_examples' => (array) config('site.ui_examples')]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(RouterStoreRequest $request)
    {
        $validated = $request->validated();

        Router::create($validated);

        return redirect()->route('routers.index')
            ->with('success', 'Router added successfully.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, Router $router)
    {
        $router->load(['profiles']);
        $router->loadCount([
            'customers',
            'stagedCustomers as staged_unmatched_customers_count' => fn ($query) => $query->where('status', 'unmatched'),
        ]);
        // Customers will be loaded lazily via API
        $router = app(MtPlanCPulseService::class)->decorateRouterForDisplay($router);

        return Inertia::render('Routers/Show', [
            'router' => $router,
            'ui_examples' => (array) config('site.ui_examples'),
            'address_pool_blocks' => PppoeAddressPoolBlock::query()
                ->where('router_id', $router->id)
                ->orderByDesc('is_active')
                ->orderBy('priority')
                ->orderBy('block_index')
                ->limit(50)
                ->get([
                    'id',
                    'site_profile_key',
                    'pppoe_profile',
                    'customer_area',
                    'block_name',
                    'block_index',
                    'address_format',
                    'local_address',
                    'remote_start',
                    'remote_end',
                    'next_block_index',
                    'last_remote_address',
                    'last_remote_source',
                    'last_remote_synced_at',
                    'is_active',
                    'capacity',
                    'used_count',
                    'available_count',
                    'warning_threshold',
                    'last_synced_at',
                ]),
            'package_drafts' => Package::query()
                ->where('router_id', $router->id)
                ->where(function ($query) {
                    $query->where('is_active', false)
                        ->orWhere('price', '<=', 0);
                })
                ->orderBy('mikrotik_profile')
                ->limit(20)
                ->get(['id', 'name', 'mikrotik_profile', 'rate_limit', 'price', 'is_active']),
            'mt_plan_c' => [
                'use_snapshot_ui' => (bool) config('services.mt_plan_c.use_snapshot_ui', false),
                'max_stale_seconds' => (int) config('services.mt_plan_c.max_stale_seconds', 180),
            ],
            'vpn' => [
                // Secret-bearing WireGuard/RADIUS scripts are generated only by
                // an operator-controlled channel; never serialize them to HTML.
                'credentials_configured' => [
                    'client_key' => filled($router->vpn_client_private_key),
                    'preshared_key' => filled($router->vpn_preshared_key),
                    'radius_secret' => filled($router->radius_secret),
                ],
                'radius_tables_ready' => app(RadiusUserService::class)->tablesReady(),
                'defaults' => app(WireGuardProvisioningService::class)->defaults($request),
            ],
        ]);
    }

    /**
     * Get paginated customers for this router (API)
     */
    public function customers(Request $request, Router $router)
    {
        $query = $router->customers()->ebilling()->with('package');

        // Search functionality
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('pppoe_user', 'like', "%{$search}%");
            });
        }

        // Filter by status
        if ($request->filled('status') && $request->input('status') !== 'all') {
            $query->where('status', $request->input('status'));
        }

        // is_online required for Connection column (large pools skip live name dump)
        $customers = $query
            ->select([
                'id', 'name', 'code', 'pppoe_user', 'status', 'package_id',
                'is_online', 'created_at', 'router_id',
            ])
            ->latest()
            ->paginate(min(max((int) $request->input('limit', 20), 1), 100));

        return response()->json($customers);
    }

    /**
     * Get list of PPP profiles from this router (API)
     */
    public function getProfiles(Router $router)
    {
        // Read from database (synced via routers:sync-profiles command)
        $profiles = RouterProfile::where('router_id', $router->id)
            ->get()
            ->map(function ($profile) {
                return [
                    'name' => $profile->name,
                    'rate_limit' => $profile->rate_limit,
                    'bandwidth' => $profile->bandwidth,
                    'local_address' => $profile->local_address,
                    'remote_address' => $profile->remote_address,
                ];
            });

        return response()->json($profiles);
    }

    /**
     * Get live router stats for the router detail page.
     * Plan C: Online KPI = count-only / pulse snapshot — NEVER count full /ppp/active dump
     * (Krian stream truncation used to show ~365 instead of ~460).
     */
    public function liveStats(Router $router)
    {
        $host = $router->ip_address ?: null;
        $vpn = $router->vpn_enabled ? (string) ($router->vpn_address ?? '') : '';
        if (empty($host) && $vpn === '') {
            return response()->json([
                'data' => [
                    'active_connections' => [],
                    'total_online' => 0,
                    'total_secrets' => 0,
                    'system_info' => [],
                ],
                'last_updated' => null,
                'unreachable' => true,
                'message' => 'Router belum dikonfigurasi (host/VPN kosong)',
                'cached' => false,
            ]);
        }

        $pulse = app(MtPlanCPulseService::class);
        $decorated = $pulse->decorateRouterForDisplay($router->fresh());
        $baselineOnline = (int) ($decorated->current_online_count ?? 0);
        $baselineSecret = (int) ($decorated->total_pppoe_count ?? 0);
        $pulseActive = (bool) $decorated->getAttribute('mt_pulse_active');

        $cacheKey = "router:{$router->id}:live-stats:v2";

        try {
            $payload = \Cache::remember($cacheKey, now()->addSeconds(45), function () use ($router, $baselineOnline, $baselineSecret, $pulseActive) {
                $mikrotik = app(MikrotikService::class);
                // Larger pools need more than 5s; still avoid full dump
                $prev = max($baselineOnline, (int) ($router->current_online_count ?? 0));
                $timeout = $prev >= 200 ? 18 : 12;
                $mikrotik->connect($router, ['timeout' => $timeout, 'attempts' => 1]);

                try {
                    $stats = $mikrotik->getHealthStats(false); // count-only first
                    $countOnly = (int) ($stats['online_count'] ?? 0);
                    $secretCount = isset($stats['total_pppoe_count']) ? (int) $stats['total_pppoe_count'] : $baselineSecret;
                    $system = [
                        'cpu-load' => $stats['cpu_load'] ?? null,
                        'uptime' => $stats['uptime'] ?? null,
                        'version' => $stats['version'] ?? null,
                        'board-name' => $stats['board_name'] ?? null,
                        'free-memory' => $stats['free_memory'] ?? null,
                        'total-memory' => $stats['total_memory'] ?? null,
                    ];

                    // Reject under-count vs baseline pulse/DB
                    $totalOnline = $countOnly;
                    $source = (string) ($stats['source'] ?? 'count_only');
                    if ($baselineOnline >= 50 && $countOnly > 0 && $countOnly < (int) floor($baselineOnline * 0.85)) {
                        $totalOnline = $baselineOnline;
                        $source = 'pulse_baseline_reject_undercount';
                    }
                    if ($countOnly <= 0 && $baselineOnline > 0) {
                        $totalOnline = $baselineOnline;
                        $source = 'pulse_baseline';
                    }

                    // Optional sample of active names ONLY for small pools (UI list)
                    $activeConnections = [];
                    if ($totalOnline > 0 && $totalOnline < 120) {
                        try {
                            $activeConnections = $mikrotik->getActiveConnections(false);
                            // Never lower KPI from name dump length
                            $unique = count(array_unique(array_filter(array_column($activeConnections, 'name'))));
                            if ($unique > $totalOnline) {
                                $totalOnline = $unique;
                                $source = 'unique_name';
                            }
                        } catch (\Throwable) {
                            $activeConnections = [];
                        }
                    }

                    return [
                        'data' => [
                            'active_connections' => $activeConnections,
                            'total_online' => $totalOnline,
                            'total_secrets' => $secretCount > 0 ? $secretCount : $baselineSecret,
                            'system_info' => $system,
                            'online_source' => $source,
                            'pulse_active' => $pulseActive,
                        ],
                        'last_updated' => now()->toISOString(),
                    ];
                } finally {
                    $mikrotik->disconnect();
                }
            });

            // Always re-merge latest pulse baseline (cache may be slightly stale)
            $payload['data']['total_online'] = max(
                (int) ($payload['data']['total_online'] ?? 0),
                $baselineOnline
            );
            if ($baselineSecret > 0) {
                $payload['data']['total_secrets'] = max(
                    (int) ($payload['data']['total_secrets'] ?? 0),
                    $baselineSecret
                );
            }
            $payload['data']['pulse_active'] = $pulseActive;
            $payload['data']['mt_pulse_stale_s'] = $decorated->getAttribute('mt_pulse_stale_s');

            return response()->json($payload + ['cached' => true, 'unreachable' => false]);
        } catch (\Exception $e) {
            // Fallback: Plan C pulse / DB — never show 0 when we know Online
            return response()->json([
                'data' => [
                    'active_connections' => [],
                    'total_online' => $baselineOnline,
                    'total_secrets' => $baselineSecret,
                    'system_info' => [],
                    'online_source' => 'pulse_fallback',
                    'pulse_active' => $pulseActive,
                ],
                'last_updated' => $decorated->getAttribute('mt_pulse_collected_at') ?: now()->toISOString(),
                'unreachable' => $baselineOnline <= 0,
                'message' => $baselineOnline > 0
                    ? 'API live partial; menampilkan Plan C pulse: '.SensitiveDataRedactor::redact($e->getMessage())
                    : 'Router tidak dapat dihubungi: '.SensitiveDataRedactor::redact($e->getMessage()),
                'cached' => false,
            ]);
        }
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Router $router)
    {
        return Inertia::render('Routers/Edit', ['router' => $router, 'ui_examples' => (array) config('site.ui_examples')]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(RouterUpdateRequest $request, Router $router)
    {
        $validated = $request->validated();

        // Only update password if provided
        if (empty($validated['password'])) {
            unset($validated['password']);
        }

        $router->update($validated);

        return redirect()->route('routers.index')
            ->with('success', 'Router updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Router $router)
    {
        // Prevent deletion if router has customers
        if ($router->customers()->ebilling()->count() > 0) {
            return back()->with('error', 'Cannot delete router with assigned customers.');
        }

        $router->delete();

        return redirect()->route('routers.index')
            ->with('success', 'Router deleted successfully.');
    }

    /**
     * Test connection to router
     */
    public function testConnection(Router $router)
    {
        $syncService = app(RouterSyncService::class);
        $result = $syncService->syncHealthStatus($router);

        if ($result['success']) {
            return back()->with('success', $result['message']);
        } else {
            return back()->with('error', $result['message']);
        }
    }

    public function syncOnlineStatus(Router $router)
    {
        return $this->testConnection($router);
    }

    public function updateVpn(
        RouterVpnUpdateRequest $request,
        Router $router,
        WireGuardProvisioningService $wireGuard,
        RadiusUserService $radius
    ) {
        $validated = $request->validated();
        $generateKeys = (bool) ($validated['generate_client_keys'] ?? false);
        unset($validated['generate_client_keys']);

        $validated = array_merge(
            $wireGuard->defaults($request),
            array_filter($validated, fn ($value) => $value !== null && $value !== '')
        );

        $validated['vpn_enabled'] = (bool) ($validated['vpn_enabled'] ?? true);
        $validated['radius_enabled'] = (bool) ($validated['radius_enabled'] ?? true);
        $validated['radius_secret'] = $validated['radius_secret'] ?? $router->radius_secret ?? Str::password(32, symbols: false);

        // Generate keys if requested OR if any key is missing
        if ($generateKeys || ! $router->vpn_client_private_key || ! $router->vpn_client_public_key || ! $router->vpn_preshared_key) {
            $keys = $wireGuard->generateClientKeySet();
            $validated['vpn_client_private_key'] = $keys['private_key'];
            $validated['vpn_client_public_key'] = $keys['public_key'];
            $validated['vpn_preshared_key'] = $keys['preshared_key'];
        }

        // Ensure server public key is correct (from key file, not stale env var)
        if (empty($validated['vpn_server_public_key'])) {
            $validated['vpn_server_public_key'] = $wireGuard->serverPublicKey();
        }

        $router->update($validated);
        $router = $router->fresh();

        // RADIUS sync
        $radius->upsertNas($router);
        $radiusResult = $router->radius_enabled && $radius->tablesReady()
            ? $radius->syncRouter($router)
            : null;

        // WireGuard peer provisioning — try webhook first (adds to both runtime + config on host),
        // then fall back to direct runtime + config write from container
        $webhookOk = $wireGuard->addPeerToVps($router);
        $persistResult = null;
        if (! $webhookOk) {
            // Webhook failed or not configured — add directly from container
            $wireGuardResult = $wireGuard->applyPeer($router);
            $persistResult = $wireGuard->savePeerToConfig($router);
        } else {
            $wireGuardResult = ['applied' => true, 'message' => 'Peer added via webhook.'];
        }

        $message = "{$router->name}: VPN and RADIUS settings saved.";
        if ($radiusResult) {
            $message .= " RADIUS users synced: {$radiusResult['synced']}/{$radiusResult['total']}.";
        }
        if (! $wireGuardResult['applied']) {
            $message .= ' WireGuard peer config is ready to copy.';
        }
        if (! empty($persistResult) && ! $persistResult['applied']) {
            $message .= ' Warning: '.$persistResult['message'];
        }

        return back()->with('success', $message);
    }

    /**
     * Generate WireGuard keys (private, public, preshared) via AJAX.
     */
    public function generateWgKeys(WireGuardProvisioningService $wireGuard)
    {
        try {
            $keys = $wireGuard->generateClientKeySet();

            return response()->json([
                'private_key' => $keys['private_key'],
                'public_key' => $keys['public_key'],
                'preshared_key' => $keys['preshared_key'],
            ]);
        } catch (\Throwable $e) {
            return $this->wireGuardFailure('key generation', $e, 'WireGuard key generation failed.');
        }
    }

    /**
     * Find the next available IP in the WG subnet.
     */
    public function nextWgAddress(WireGuardProvisioningService $wireGuard)
    {
        try {
            $nextIp = $wireGuard->getNextAvailableIp();

            return response()->json(['next_address' => $nextIp]);
        } catch (\Throwable $e) {
            return $this->wireGuardFailure('next address lookup', $e, 'WireGuard address lookup failed.');
        }
    }

    /**
     * Return VPS WireGuard server config for auto-fill.
     */
    public function wgServerConfig(Request $request, WireGuardProvisioningService $wireGuard)
    {
        try {
            $defaults = $wireGuard->defaults($request);

            return response()->json([
                'server_public_key' => $defaults['vpn_server_public_key'] ?? '',
                'server_endpoint' => $defaults['vpn_server_endpoint'] ?? '',
                'server_port' => $defaults['vpn_server_port'] ?? 443,
                'server_address' => $defaults['vpn_server_address'] ?? config('site.wireguard.server_address'),
                'allowed_ips' => $defaults['vpn_allowed_ips'] ?? config('site.wireguard.allowed_ips'),
            ]);
        } catch (\Throwable $e) {
            return $this->wireGuardFailure('server config lookup', $e, 'WireGuard server configuration unavailable.');
        }
    }

    /**
     * Check WireGuard tunnel status for a router.
     * Returns handshake info, bytes transferred, and tunnel status.
     * Uses the host-level WG API (port 8000) since the container cannot
     * access wg kernel interface directly.
     */
    public function wgTunnelStatus(Router $router)
    {
        if (! $router->vpn_enabled || ! $router->vpn_client_public_key) {
            return response()->json([
                'tunnel_up' => false,
                'message' => 'VPN not configured',
            ]);
        }

        try {
            $apiUrl = config('services.wireguard.api_url', config('site.wireguard.api_url_fallback'));
            $pubkey = $router->vpn_client_public_key;

            $response = Http::timeout(5)
                ->get("{$apiUrl}/wg/tunnel-status", ['pubkey' => $pubkey]);

            if ($response->successful()) {
                return response()->json($response->json());
            }

            return response()->json([
                'tunnel_up' => false,
                'message' => 'WG API returned: '.$response->status(),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'tunnel_up' => false,
                'message' => 'Could not check tunnel: '.SensitiveDataRedactor::redact($e->getMessage()),
            ]);
        }
    }

    /**
     * Get the last PPPoE remote address used for a router,
     * so the next customer can be assigned the next IP.
     */
    public function lastPppoeIp(Router $router)
    {
        $lastCustomer = Customer::where('router_id', $router->id)
            ->whereNotNull('pppoe_remote_address')
            ->orderByDesc('id')
            ->first();

        $lastRemote = $lastCustomer?->pppoe_remote_address;

        // Also get the profile's local/remote from router_profiles
        $profiles = RouterProfile::where('router_id', $router->id)
            ->whereNotNull('local_address')
            ->get(['name', 'local_address', 'remote_address']);

        return response()->json([
            'last_remote_address' => $lastRemote,
            'suggested_next' => $lastRemote ? $this->incrementIp($lastRemote) : null,
            'profiles_with_ip' => $profiles,
        ]);
    }

    private function incrementIp(string $ip): ?string
    {
        $ip = preg_replace('#/\d+$#', '', $ip); // strip CIDR
        $parts = explode('.', $ip);
        if (count($parts) !== 4) {
            return null;
        }
        $last = (int) array_pop($parts);
        if ($last >= 254) {
            return null;
        } // overflow
        $parts[] = (string) ($last + 1);

        return implode('.', $parts);
    }

    public function syncRadius(Router $router, RadiusUserService $radius)
    {
        if (! $radius->tablesReady()) {
            return back()->with('error', 'FreeRADIUS tables are not ready. Run migrations or import the FreeRADIUS SQL schema first.');
        }

        if (! $router->radius_enabled) {
            return back()->with('error', 'Enable RADIUS on this router before syncing customers.');
        }

        $radius->upsertNas($router);
        $result = $radius->syncRouter($router);

        return back()->with('success', "RADIUS sync finished: {$result['synced']}/{$result['total']} users synced.");
    }

    /**
     * Receive pushed PPP profiles from MikroTik router.
     * Called by MikroTik /tool fetch script or by Sync Profiles button.
     */
    public function pushProfiles(Request $request)
    {
        // PSB-2: shared-secret token. This endpoint mutates packages and profiles,
        // so an unset token is a hard configuration error, never an open fallback.
        $token = trim((string) Setting::get('wg_push_profile_token', ''));
        if ($token === '') {
            Log::error('[wg/push-profile] shared token is not configured');

            return response()->json(['success' => false, 'error' => 'push endpoint unavailable'], 503);
        }

        // Header-only prevents the secret from entering URLs, access logs,
        // browser history, referrers, and copied query strings.
        $given = trim((string) $request->header('X-Push-Token', ''));
        if ($given === '' || ! hash_equals($token, $given)) {
            return response()->json(['success' => false, 'error' => 'push token invalid'], 403);
        }

        $rules = [
            'router_id' => 'required|integer|exists:routers,id',
            'profiles' => 'nullable|array|max:100',
            'profiles.*.name' => 'required|string|max:191',
            'profiles.*.rate-limit' => 'nullable|string|max:255',
            'profiles.*.local-address' => 'nullable|string|max:255',
            'profiles.*.remote-address' => 'nullable|string|max:255',
            'profiles.*.only-one' => 'nullable|string|max:20',
            'name' => 'nullable|string|max:191',
            'rate-limit' => 'nullable|string|max:255',
            'local-address' => 'nullable|string|max:255',
            'remote-address' => 'nullable|string|max:255',
            'only-one' => 'nullable|string|max:20',
        ];
        $validated = $request->validate($rules);

        $router = Router::findOrFail($validated['router_id']);
        $profiles = $validated['profiles'] ?? [[
            'name' => $validated['name'] ?? '',
            'rate-limit' => $validated['rate-limit'] ?? null,
            'local-address' => $validated['local-address'] ?? null,
            'remote-address' => $validated['remote-address'] ?? null,
            'only-one' => $validated['only-one'] ?? null,
        ]];
        $synced = 0;
        $skipped = 0;
        $skipNames = ['default', 'default-encryption', 'isolation', 'ISOLATION'];

        foreach ($profiles as $profile) {
            $name = trim($profile['name']);
            if (in_array(strtolower($name), array_map('strtolower', $skipNames)) || $name === '') {
                $skipped++;

                continue;
            }

            $rateLimit = $profile['rate-limit'] ?? '';

            $this->syncPackageFromProfile($router, $name, $rateLimit ?: null);
            $synced++;

            RouterProfile::updateOrCreate(
                [
                    'router_id' => $router->id,
                    'name' => $name,
                ],
                [
                    'rate_limit' => $rateLimit ?: null,
                    'bandwidth' => $rateLimit ?: null,
                    'local_address' => $profile['local-address'] ?? null,
                    'remote_address' => $profile['remote-address'] ?? null,
                    'only_one' => $profile['only-one'] ?? 'yes',
                ]
            );
        }

        return response()->json([
            'success' => true,
            'synced' => $synced,
            'skipped' => $skipped,
            'router' => $router->name,
        ]);
    }

    /**
     * PSB-2: sync profile MT → Package TANPA menimpa data operator.
     * - Profile BARU → Package draft: is_active=0, price=0, nama placeholder.
     *   Operator wajib isi harga + aktifkan sebelum bisa dipakai bot PSB.
     * - Profile EXISTING → hanya update rate_limit; name/price/is_active TIDAK disentuh.
     */
    protected function syncPackageFromProfile(Router $router, string $profileName, ?string $rateLimit): void
    {
        $existing = Package::query()
            ->where('router_id', $router->id)
            ->where('mikrotik_profile', $profileName)
            ->first();

        if ($existing) {
            if ((string) $existing->rate_limit !== (string) $rateLimit) {
                $existing->rate_limit = $rateLimit;
                $existing->save();
            }

            return;
        }

        Package::create([
            'router_id' => $router->id,
            'mikrotik_profile' => $profileName,
            'name' => 'Paket '.$profileName,
            'rate_limit' => $rateLimit,
            'code' => strtoupper(preg_replace('/[^a-z0-9]+/i', '_', $profileName)).'_'.$router->id,
            'price' => 0,
            'is_active' => false, // draft — operator isi harga lalu aktifkan
        ]);
    }

    /**
     * Sync PPP profiles from MikroTik router into local DB (packages).
     * Creates/updates Package records linked to this router.
     */
    public function syncProfiles(Router $router, MikrotikService $mikrotik)
    {
        try {
            $mikrotik->connect($router, ['timeout' => 15, 'attempts' => 2]);
            $profiles = $mikrotik->getProfiles();
        } catch (\Exception $e) {
            // API connection failed — likely firewall on MikroTik blocks API from VPN
            // Return with a MikroTik push script the user can run instead
            $pushScript = $this->generatePushProfilesScript($router);

            return back()->with('error', 'Tidak bisa konek ke API MikroTik via VPN. Jalankan skrip ini di terminal MikroTik untuk push profile ke server:')
                ->with('push_script', $pushScript);
        } finally {
            $mikrotik->disconnect();
        }

        $synced = 0;
        $skipped = 0;
        $skipNames = ['default', 'default-encryption', 'isolation', 'ISOLATION'];

        foreach ($profiles as $profile) {
            $name = $profile['name'] ?? '';
            if (in_array(strtolower($name), array_map('strtolower', $skipNames)) || trim($name) === '') {
                $skipped++;

                continue;
            }

            $rateLimit = $profile['rate-limit'] ?? '';

            // PSB-2: sync tanpa menimpa harga/nama yang sudah diisi operator
            $this->syncPackageFromProfile($router, $name, $rateLimit ?: null);
            $synced++;

            // Also update RouterProfile
            RouterProfile::updateOrCreate(
                [
                    'router_id' => $router->id,
                    'name' => $name,
                ],
                [
                    'rate_limit' => $rateLimit ?: null,
                    'bandwidth' => $rateLimit ?: null,
                    'local_address' => $profile['local-address'] ?? null,
                    'remote_address' => $profile['remote-address'] ?? null,
                    'only_one' => $profile['only-one'] ?? 'yes',
                ]
            );
        }

        // Update router status
        $router->update(['connection_status' => 'online']);

        return back()->with('success', "Synced {$synced} profiles from {$router->name}".($skipped ? ", skipped {$skipped} system profiles" : '').'.');
    }

    /**
     * Generate a MikroTik RouterOS script that pushes PPP profiles
     * to the eBilling server via HTTP POST.
     * Used when VPS cannot connect to MikroTik API directly.
     */
    protected function generatePushProfilesScript(Router $router): string
    {
        $serverIp = config('services.wireguard.server_address', config('site.wireguard.server_address'));
        $routerId = $router->id;

        $script = <<<'SCRIPT'
# Skynet E-Billing — Push PPP Profiles to Server
# Jalankan di terminal MikroTik (Winbox/Terminal)
# Setiap profile dikirim satu per satu via /tool fetch

:local routerId ROUTERID
:local pushToken "PUSHTOKEN"
:local base "http://SERVERIP/wg/push-profile\?router_id=$routerId"

:foreach p in=[/ppp/profile/find] do={
    :local pName [/ppp/profile get $p name]
    :local pRate [/ppp/profile get $p rate-limit]
    :local pLocal [/ppp/profile get $p local-address]
    :local pRemote [/ppp/profile get $p remote-address]
    :local pOnlyOne [/ppp/profile get $p only-one]
    :local url ($base . "&name=" . $pName . "&rate-limit=" . $pRate . "&local-address=" . $pLocal . "&remote-address=" . $pRemote . "&only-one=" . $pOnlyOne)
    :put ("Pushing profile: " . $pName)
    /tool fetch url=$url mode=http http-header-field=("X-Push-Token: " . $pushToken) keep-result=no
    :delay 1s
}

:put "Selesai! Semua profile sudah dikirim ke server."
SCRIPT;

        $script = str_replace('SERVERIP', $serverIp, $script);
        $script = str_replace('ROUTERID', (string) $routerId, $script);
        $script = str_replace('PUSHTOKEN', trim((string) Setting::get('wg_push_profile_token', '')), $script);

        return $script;
    }

    public function storeIsolationProfile(Request $request, Router $router, MikrotikService $mikrotik)
    {
        $validated = $request->validate([
            'isolation_profile' => ['required', 'string', 'max:255'],
            'isolation_rate_limit' => ['nullable', 'string', 'max:255'],
            'isolation_local_address' => ['nullable', 'string', 'max:255'],
            'isolation_remote_address' => ['nullable', 'string', 'max:255'],
        ]);

        $profileName = trim($validated['isolation_profile']);

        try {
            $mikrotik->connect($router, ['timeout' => 30, 'attempts' => 1]);
            $result = $mikrotik->ensureIsolationProfile(
                $profileName,
                $validated['isolation_rate_limit'] ?? null,
                $validated['isolation_local_address'] ?? null,
                $validated['isolation_remote_address'] ?? null,
            );
        } catch (\Exception $e) {
            return back()->with('error', 'Failed to save isolation profile: '.SensitiveDataRedactor::redact($e->getMessage()));
        } finally {
            $mikrotik->disconnect();
        }

        $router->update([
            'isolation_profile' => $profileName,
            'connection_status' => 'online',
        ]);

        RouterProfile::updateOrCreate(
            [
                'router_id' => $router->id,
                'name' => $result['name'],
            ],
            [
                'rate_limit' => $result['rate_limit'] ?? ($validated['isolation_rate_limit'] ?? null),
                'bandwidth' => $result['rate_limit'] ?? ($validated['isolation_rate_limit'] ?? null),
                'local_address' => $result['local_address'] ?? null,
                'remote_address' => $result['remote_address'] ?? null,
                'only_one' => $result['only_one'] ?? 'yes',
            ]
        );

        $mode = $result['created'] ? 'created' : 'updated';

        return back()->with('success', "Isolation profile {$profileName} {$mode} on {$router->name}.");
    }

    public function destroyIsolationProfile(Router $router, MikrotikService $mikrotik)
    {
        $profileName = trim((string) ($router->isolation_profile ?: ''));

        if ($profileName === '') {
            return back()->with('error', 'This router does not have a saved isolation profile.');
        }

        try {
            $mikrotik->connect($router, ['timeout' => 30, 'attempts' => 1]);
            $result = $mikrotik->deleteIsolationProfile($profileName);
        } catch (\Exception $e) {
            return back()->with('error', 'Failed to delete isolation profile: '.SensitiveDataRedactor::redact($e->getMessage()));
        } finally {
            $mikrotik->disconnect();
        }

        RouterProfile::where('router_id', $router->id)
            ->where('name', $profileName)
            ->delete();

        $router->update([
            'isolation_profile' => null,
            'connection_status' => 'online',
        ]);

        if (! $result['deleted']) {
            return back()->with('success', "Isolation profile {$profileName} was already missing on {$router->name}. Saved profile reference has been cleared.");
        }

        return back()->with('success', "Isolation profile {$profileName} deleted from {$router->name}.");
    }

    /**
     * Scan this router for customers
     */
    public function scanRouter(Router $router)
    {
        if (! $router->is_active) {
            return back()->with('error', "Cannot scan inactive router. Please enable it first or 'Test Connection'.");
        }

        try {
            Log::info("Initiating synchronous scan for router: {$router->name} (ID: {$router->id})");

            $syncService = app(RouterSyncService::class);
            $stats = $syncService->syncCustomers($router);

            $message = "Scan completed. Mapped: {$stats['mapped']}, Router-only staged: {$stats['staged_router_only']}, eBilling missing: {$stats['not_found_ebilling']}";

            return back()->with('success', $message);
        } catch (\Exception $e) {
            Log::error("Scan failed for {$router->name}: {$e->getMessage()}");

            return back()->with('error', 'Failed to scan: '.SensitiveDataRedactor::redact($e->getMessage()));
        }
    }

    /**
     * Unified Sync (Test + Scan)
     */
    public function sync(Router $router)
    {
        $router->refresh();

        if (! $router->is_active) {
            return back()->with('error', "Cannot sync inactive router {$router->name}. Enable it first.");
        }

        $isLocked = in_array($router->sync_status, ['queued', 'running'], true)
            && $router->sync_lock_until
            && $router->sync_lock_until->isFuture();

        if ($isLocked) {
            return back()->with('success', "{$router->name}: Sync is already {$router->sync_status}.");
        }

        $router->update([
            'sync_status' => 'queued',
            'sync_started_at' => null,
            'sync_finished_at' => null,
            'sync_lock_until' => now()->addMinutes(10),
            'sync_message' => 'Full sync is queued.',
        ]);

        SyncRouterJob::dispatch($router->id);

        return back()->with('success', "{$router->name}: Full sync queued.");
    }

    /**
     * Sync All Active Routers
     */
    public function syncAll()
    {
        $routers = Router::where('is_active', true)->get();
        $results = [
            'total' => $routers->count(),
            'synced' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        foreach ($routers as $router) {
            $isLocked = in_array($router->sync_status, ['queued', 'running'], true)
                && $router->sync_lock_until
                && $router->sync_lock_until->isFuture();

            if ($isLocked) {
                $results['failed']++;
                $results['errors'][] = "{$router->name}: already {$router->sync_status}";

                continue;
            }

            $router->update([
                'sync_status' => 'queued',
                'sync_started_at' => null,
                'sync_finished_at' => null,
                'sync_lock_until' => now()->addMinutes(10),
                'sync_message' => 'Full sync is queued.',
            ]);

            SyncRouterJob::dispatch($router->id);
            $results['synced']++;
        }

        $message = "Queued {$results['synced']}/{$results['total']} routers";
        if ($results['failed'] > 0) {
            $message .= ". {$results['failed']} failed.";
        }

        return back()->with('success', $message);
    }

    /**
     * WireGuard helpers shell out to `wg` and touch key material; their
     * exception text can echo command lines or key files. Never return it to
     * the browser - log a redacted copy (the log tap redacts again) instead.
     */
    private function wireGuardFailure(string $operation, object $e, string $publicMessage)
    {
        Log::warning('[WireGuard] '.$operation.' failed', [
            'exception_class' => $e::class,
            'message' => SensitiveDataRedactor::redact($e->getMessage()),
        ]);

        return response()->json(['error' => $publicMessage], 500);
    }
}
