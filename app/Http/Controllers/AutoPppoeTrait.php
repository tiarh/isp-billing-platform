<?php

// Auto-PPPoE creation block to be appended to OltC300Controller
// This handles Hioso/HTB activation (PPPoE-only, no OLT provisioning)

namespace App\Http\Controllers;

use App\Contracts\PsbPppoePasswordPolicy;
use App\Exceptions\PsbPasswordPolicyException;
use App\Models\Area;
use App\Models\Customer;
use App\Models\OdpOdc;
use App\Models\Olt;
use App\Models\Package;
use App\Models\PppoeAddressPoolBlock;
use App\Models\Router;
use App\Models\RouterProfile;
use App\Services\DueDayResolver;
use App\Services\KtpOcrService;
use App\Services\LegacySyncService;
use App\Services\MikrotikService;
use App\Services\PppoeAddressPoolAllocator;
use App\Services\PsbActivationRecorder;
use App\Services\RadiusUserService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RouterOS\Query;

trait AutoPppoeTrait
{
    /**
     * Auto-create PPPoE customer (Hioso/HTB/ZTE/FiberHome PPPoE flow)
     * Input: name (from KTP or manual), rt, rw, odp_id|odp_manual, area_id, router_id, package_id, vendor
     * Generate: pppoe_user = NAMA_RT{x}_RW{y}_{odp_port}
     * Action: create customer + RADIUS sync + (optional) Mikrotik PPPoE secret
     *
     * @route POST /api/olt-c300/auto-pppoe
     */
    public function autoPppoe(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:64',
            'rt' => 'required|string|max:8',     // e.g. "4" → "RT4"
            'rw' => 'required|string|max:8',     // e.g. "2" → "RW2"
            'odp_id' => 'nullable|integer|exists:odp_odc,id',
            'odp_manual' => 'nullable|string|max:64',    // free-text ODP when area has none
            'area_id' => 'required|integer|exists:areas,id',
            'router_id' => 'required|integer|exists:routers,id',
            'package_id' => 'required|integer|exists:packages,id',
            'vendor' => 'nullable|string|max:32',    // htb|hioso|zte|fiberhome
            'phone' => 'nullable|string|max:20',
            'nik' => 'nullable|string|max:32',
            'address' => 'nullable|string|max:255',
            'local_address' => 'nullable|string|max:32',
            'remote_address' => 'nullable|string|max:32',
            'ktp_photo' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:8192|dimensions:min_width=100,min_height=100,max_width=6000,max_height=6000',
            'odp_photo' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:8192|dimensions:min_width=100,min_height=100,max_width=6000,max_height=6000',
            'dry_run' => 'nullable|boolean',
        ]);

        // Require either a selected ODP or a manual ODP string
        if (! $request->filled('odp_id') && ! $request->filled('odp_manual')) {
            return response()->json([
                'success' => false,
                'error' => 'Pilih ODP atau isi ODP manual.',
            ], 422);
        }

        $dryRun = (bool) $request->input('dry_run', false);
        $vendor = strtolower(trim((string) $request->input('vendor', '')));

        // === 1. Build pppoe_user ===
        $name = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $request->name));
        if (empty($name)) {
            return response()->json([
                'success' => false,
                'error' => 'Nama tidak valid setelah normalisasi (gak ada alphanumeric).',
            ], 422);
        }
        $rt = preg_replace('/\D/', '', $request->rt);
        $rw = preg_replace('/\D/', '', $request->rw);
        if (empty($rt) || empty($rw)) {
            return response()->json([
                'success' => false,
                'error' => 'RT dan RW harus angka (1-99).',
            ], 422);
        }

        // ODP: from DB record or manual free-text
        $odp = null;
        $odpName = null;
        $odpShort = null;
        $oltPortLabel = null;
        if ($request->filled('odp_id')) {
            $odp = OdpOdc::findOrFail($request->odp_id);
            $odpName = $odp->name;
            $oltPortLabel = $odp->olt_port;
            // Extract short ODP name (e.g. "SKY_RDG_ODP_9.4" → "9.4")
            $odpShort = $odp->name;
            if (preg_match('/(\d+\.\d+)$/', $odp->name, $m)) {
                $odpShort = $m[1];
            } elseif (preg_match('/_(\d+)$/', $odp->name, $m)) {
                $odpShort = $m[1];
            }
        } else {
            $odpName = trim($request->odp_manual);
            // Normalize manual ODP for the pppoe_user suffix
            $odpShort = $odpName;
            if (preg_match('/(\d+\.\d+)$/', $odpName, $m)) {
                $odpShort = $m[1];
            } elseif (preg_match('/(\d+)\s*$/', $odpName, $m)) {
                $odpShort = $m[1];
            } else {
                $odpShort = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $odpName));
            }
        }

        $pppoeUser = "{$name}_RT{$rt}_RW{$rw}_{$odpShort}";

        // === 2. Uniqueness check ===
        $existing = Customer::where('pppoe_user', $pppoeUser)->first();
        if ($existing) {
            return response()->json([
                'success' => false,
                'error' => "PPPoE user '{$pppoeUser}' sudah ada (customer: {$existing->name}, ID: {$existing->id}).",
            ], 409);
        }

        $router = Router::findOrFail($request->router_id);

        if (! $router->is_active) {
            return response()->json([
                'success' => false,
                'error' => "Router {$router->name} sedang non-aktif. Tidak bisa melakukan auto-provisioning.",
            ], 403);
        }

        $package = Package::findOrFail($request->package_id);
        // Block only packages owned by a *different* router
        if ($package->router_id !== null && (int) $package->router_id !== (int) $router->id) {
            return response()->json([
                'success' => false,
                'error' => "Paket \"{$package->name}\" milik router lain. Pilih paket untuk {$router->name}.",
            ], 422);
        }
        $area = Area::findOrFail($request->area_id);

        // === 3. PPPoE password ===
        try {
            $pppoePassword = app(PsbPppoePasswordPolicy::class)->passwordForArea($area);
        } catch (PsbPasswordPolicyException $e) {
            return response()->json([
                'success' => false,
                'error' => (string) config('site.psb_password_manual_error'),
                'code' => $e->status,
            ], 422);
        }

        // === 4. Determine OLT for this activation (by vendor + area, else area, else router) ===
        $oltId = null;
        $oltQuery = Olt::query();
        if ($vendor !== '') {
            // map UI vendor to olt.vendor values (zte_c300, hioso, ...)
            $oltQuery->where(function ($q) use ($vendor) {
                $q->where('vendor', $vendor)
                    ->orWhere('vendor', 'like', $vendor.'%');
            });
        }
        $olt = (clone $oltQuery)->where('area_id', $area->id)->first()
            ?: (clone $oltQuery)->where('router_id', $router->id)->first()
            ?: Olt::where('area_id', $area->id)->first()
            ?: Olt::where('router_id', $router->id)->first();
        if ($olt) {
            $oltId = $olt->id;
            if (! $oltPortLabel && $odp) {
                $oltPortLabel = $odp->olt_port;
            }
        }

        // === 5. Build address if not provided ===
        $address = $request->address ?: "RT {$rt} / RW {$rw} - ODP {$odpName} - Area {$area->name}";

        // === 5b. PPPoE local/remote (form → else suggest from last 5 secrets / profile) ===
        $localAddress = $request->input('local_address') ?: null;
        $remoteAddress = $request->input('remote_address') ?: null;
        $suggestion = $this->suggestPppoeIp($router, $package->mikrotik_profile ?: null);
        if (! $dryRun && ! $remoteAddress) {
            try {
                $allocated = $this->allocatePppoeIpForCreate($router, $package->mikrotik_profile ?: null, (string) $area->name);
                if ($allocated !== null) {
                    $suggestion = [
                        'suggested_next' => $allocated['remote'],
                        'local_address' => $allocated['local'],
                        'source' => $allocated['source'],
                        'note' => 'Dialokasikan dari master PPPoE address pool static.',
                    ];
                }
            } catch (\RuntimeException $e) {
                if (str_contains($e->getMessage(), 'Blok PPPoE penuh')) {
                    return response()->json([
                        'success' => false,
                        'error' => $e->getMessage(),
                    ], 422);
                }

                throw $e;
            }
        }
        if (! $remoteAddress) {
            $remoteAddress = $suggestion['suggested_next'] ?? null;
        }
        if (! $localAddress) {
            $localAddress = $suggestion['local_address'] ?? null;
        }

        // === 6. Handle KTP + ODP photo uploads ===
        $ktpPhotoPath = null;
        if ($request->hasFile('ktp_photo')) {
            $file = $request->file('ktp_photo');
            $filename = "auto-activate-{$name}_RT{$rt}_RW{$rw}_".uniqid().'.'.$file->extension();
            $ktpPhotoPath = $file->storeAs('ktp/'.now()->format('Y/m'), $filename, 'private');
        }

        $odpPhotoPath = null;
        if ($request->hasFile('odp_photo')) {
            $file = $request->file('odp_photo');
            $filename = "odp-{$name}_RT{$rt}_RW{$rw}_".uniqid().'.'.$file->extension();
            $odpPhotoPath = $file->storeAs('odp/'.now()->format('Y/m'), $filename, 'private');
        }

        $customerData = [
            'name' => $request->name,
            'address' => $address,
            'phone' => $request->phone,
            'nik' => $request->nik,
            'pppoe_user' => $pppoeUser,
            'pppoe_password' => $pppoePassword,
            'pppoe_local_address' => $localAddress,
            'pppoe_remote_address' => $remoteAddress,
            'package_id' => $package->id,
            'area_id' => $area->id,
            'router_id' => $router->id,
            'olt_id' => $oltId,
            'olt_port_label' => $oltPortLabel,
            'status' => $dryRun ? 'pending_installation' : 'active',
            'join_date' => now(),
            'ktp_photo_url' => $ktpPhotoPath,
            'mikrotik_profile' => $package->mikrotik_profile,
            // Fixed day for RA-OLT / Starlite akuisisi, else today's day; admin can edit later
            'due_day' => app(DueDayResolver::class)->resolve((int) $router->id, (int) $area->id, now()),
        ];
        if (Schema::hasColumn('customers', 'odp_photo_url')) {
            $customerData['odp_photo_url'] = $odpPhotoPath;
        }
        if (Schema::hasColumn('customers', 'odp_name')) {
            $customerData['odp_name'] = $odpName;
        }

        // === 7. Dry run: preview only ===
        if ($dryRun) {
            return response()->json([
                'success' => true,
                'dry_run' => true,
                'preview' => Arr::except($customerData, ['pppoe_password']),
                'pppoe_user' => $pppoeUser,
                'pppoe_password_available' => $pppoePassword !== '',
                'odp_short' => $odpShort,
                'vendor' => $vendor,
                'local_address' => $localAddress,
                'remote_address' => $remoteAddress,
                'note' => 'Dry run — customer TIDAK dibuat. Set dry_run=false untuk eksekusi beneran.',
            ]);
        }

        // === 8. Live: create customer + RADIUS sync ===
        try {
            // Never persist a candidate password before the router accepts and
            // read-back verifies it. The encrypted customer value is filled
            // only from the verified RouterOS secret below.
            $customer = Customer::create(array_merge($customerData, ['pppoe_password' => null]));

            $radiusResult = ['synced' => false, 'reason' => 'unknown'];
            $mikrotikService = null;

            // Push PPPoE secret to Mikrotik
            $mikrotikResult = ['pushed' => false, 'reason' => 'not_attempted', 'success' => false];
            try {
                $mikrotikService = app(MikrotikService::class);
                $mikrotikService->connect($router, ['timeout' => 15, 'attempts' => 2]);
                $profile = $this->resolveMikrotikProfile($package, $mikrotikService);
                $mikrotikResult = $mikrotikService->addPPPSecret(
                    $pppoeUser,
                    $pppoePassword,
                    $profile,
                    $customer->pppoe_local_address ?: null,
                    $customer->pppoe_remote_address ?: null,
                    'pppoe',
                    'source=ebilling;psb_at='.now()->toDateString().";customer_id={$customer->id}"
                );
                $mikrotikResult['profile'] = $profile;
                $verifiedSecret = ($mikrotikResult['success'] ?? false)
                    ? $mikrotikService->getPPPSecret($pppoeUser)
                    : null;
                $verifiedPassword = trim((string) ($verifiedSecret['password'] ?? ''));
                if (($mikrotikResult['success'] ?? false)
                    && ($verifiedSecret === null || $verifiedPassword === '' || preg_match('/^\*+$/', $verifiedPassword) === 1
                        || ! hash_equals(hash('sha256', $pppoePassword), hash('sha256', $verifiedPassword)))) {
                    $mikrotikResult = [
                        'success' => false,
                        'pushed' => false,
                        'message' => 'Password PPPoE belum terverifikasi live setelah write.',
                        'code' => 'verify_failed',
                        'profile' => $profile,
                    ];
                }

                // Update customer sync status — only on verified success
                if ($mikrotikResult['success'] ?? false) {
                    $customer->update([
                        'pppoe_password' => $verifiedPassword,
                        'mikrotik_sync_status' => 'synced',
                        'mikrotik_synced_at' => now(),
                        'mikrotik_sync_checked_at' => now(),
                        'mikrotik_profile' => $profile,
                    ]);
                    $mikrotikResult['pushed'] = true;
                    try {
                        $radiusResult = app(RadiusUserService::class)->syncCustomer($customer->fresh() ?? $customer);
                    } catch (\Throwable $e) {
                        Log::warning('Auto-PPPoE: RADIUS sync failed', [
                            'customer_id' => $customer->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                    // KPI sejalur PSB WA: catat draft manual (RA/Krian/HTB)
                    try {
                        app(PsbActivationRecorder::class)->recordManualPppoe(
                            $customer->fresh() ?? $customer,
                            $router,
                            $package,
                            [
                                'rt' => $rt,
                                'rw' => $rw,
                                'odp' => $odpName,
                                'daerah' => $area->name ?? null,
                                'olt_id' => $oltId,
                                'manual_path' => 'auto_pppoe',
                                'sender_name' => auth()->user()?->name ?? 'manual',
                            ]
                        );
                    } catch (\Throwable $e) {
                        Log::warning('Auto-PPPoE: KPI record failed', ['error' => $e->getMessage()]);
                    }
                } else {
                    $customer->update([
                        'mikrotik_sync_status' => 'failed',
                        'mikrotik_sync_checked_at' => now(),
                    ]);
                    $mikrotikResult['pushed'] = false;
                    $mikrotikResult['reason'] = $mikrotikResult['message']
                        ?? $mikrotikResult['code']
                        ?? 'push_failed';
                }
            } catch (\Throwable $e) {
                Log::warning('Auto-PPPoE: Mikrotik push failed', [
                    'customer_id' => $customer->id,
                    'error' => $e->getMessage(),
                ]);
                $mikrotikResult = [
                    'success' => false,
                    'pushed' => false,
                    'reason' => $e->getMessage(),
                    'message' => $e->getMessage(),
                    'code' => 'exception',
                ];
                try {
                    $customer->update([
                        'mikrotik_sync_status' => 'failed',
                        'mikrotik_sync_checked_at' => now(),
                    ]);
                } catch (\Throwable) {
                    // ignore status write failure
                }
            } finally {
                $mikrotikService?->disconnect();
            }

            // Activity log
            activity()
                ->performedOn($customer)
                ->withProperties([
                    'pppoe_user' => $pppoeUser,
                    'router' => $router->name,
                    'package' => $package->name,
                    'odp' => $odpName,
                    'area' => $area->name,
                    'vendor' => $vendor,
                    'radius_result' => $radiusResult,
                    'mikrotik_result' => $mikrotikResult,
                    'method' => 'auto_pppoe',
                ])
                ->log('auto_pppoe_activated');

            // Vendor branch hint for the frontend
            $nextStep = 'done';
            if ($vendor === 'zte' || str_starts_with($vendor, 'zte')) {
                $nextStep = 'zte_provision';
            } elseif ($vendor === 'fiberhome') {
                $nextStep = 'fiberhome_hint';
            }

            return response()->json([
                'success' => true,
                'dry_run' => false,
                'customer' => $customer->only([
                    'id', 'name', 'pppoe_user', 'phone', 'address',
                    'status', 'join_date', 'area_id', 'router_id', 'package_id',
                    'pppoe_local_address', 'pppoe_remote_address', 'olt_id', 'olt_port_label',
                ]),
                'pppoe_user' => $pppoeUser,
                'pppoe_password_available' => $pppoePassword !== '',
                'vendor' => $vendor,
                'next_step' => $nextStep,
                'radius' => $radiusResult,
                'mikrotik' => $mikrotikResult,
                'olt_port_label' => $oltPortLabel,
                'note' => $this->autoPppoeResultNote(
                    $pppoeUser,
                    $radiusResult,
                    $mikrotikResult
                ),
            ]);
        } catch (\Throwable $e) {
            Log::error('Auto-PPPoE: customer create failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'error' => 'Gagal create customer: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Suggest next free PPPoE remote IP.
     * Default = eBilling DB only (ringan, no MikroTik load).
     * Live MikroTik only when $live=true (tombol Refresh IP) + cache 10 menit.
     */
    private function suggestPppoeIp(Router $router, ?string $profileName = null, bool $live = false): array
    {
        if ($live) {
            $cacheKey = "router:{$router->id}:pppoe-ip-suggest:mt:v3";

            return Cache::remember($cacheKey, now()->addMinutes(10), function () use ($router, $profileName) {
                $fromMt = $this->suggestPppoeIpFromMikrotik($router, $profileName);
                if ($fromMt !== null) {
                    return $fromMt;
                }

                $db = $this->suggestPppoeIpFromDb($router, $profileName);
                $db['note'] = 'MikroTik live gagal/timeout — pakai eBilling DB (urut remote, cek free di DB).';

                return $db;
            });
        }

        $cacheKey = "router:{$router->id}:pppoe-ip-suggest:db:v3";

        return Cache::remember($cacheKey, now()->addMinutes(2), function () use ($router, $profileName) {
            $db = $this->suggestPppoeIpFromDb($router, $profileName);
            $db['note'] = ($db['note'] ?? '').(empty($db['note']) ? '' : ' ')
                .'Sumber DB eBilling (ringan). Klik "Refresh IP" untuk baca live MikroTik (cache 10 mnt).';

            return $db;
        });
    }

    /**
     * Live MikroTik: all secrets with remote IP → sort desc by remote → next free.
     *
     * @return array<string, mixed>|null
     */
    private function suggestPppoeIpFromMikrotik(Router $router, ?string $profileName = null): ?array
    {
        try {
            $mt = app(MikrotikService::class);
            $timeout = ((int) ($router->total_pppoe_count ?? 0) >= 200) ? 28 : 18;
            $mt->connect($router, ['timeout' => $timeout, 'attempts' => 1]);
            try {
                $secrets = $mt->getPPPSecretsWithAddresses();
            } finally {
                $mt->disconnect();
            }
        } catch (\Throwable $e) {
            Log::warning('Auto-PPPoE IP suggest: MikroTik live failed', [
                'router' => $router->name,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        // Map remote → secret; only real IPv4 remotes
        $usedRemotes = [];
        $withRemote = [];
        foreach ($secrets as $s) {
            $remote = $this->normalizeIp((string) ($s['remote_address'] ?? ''));
            $local = $this->normalizeIp((string) ($s['local_address'] ?? ''));
            if ($remote === '') {
                continue;
            }
            $usedRemotes[$remote] = true;
            $withRemote[] = [
                'id' => 0,
                'name' => $s['name'] ?? '',
                'pppoe_user' => $s['name'] ?? '',
                'local_address' => $local,
                'remote_address' => $remote,
                'profile' => $s['profile'] ?? '',
                'disabled' => (bool) ($s['disabled'] ?? false),
                'source' => 'mikrotik',
            ];
        }

        if ($withRemote === []) {
            return null;
        }

        // Sort by remote address DESC (highest last-octet / full IP last first)
        usort($withRemote, function (array $a, array $b) {
            return ip2long($b['remote_address']) <=> ip2long($a['remote_address']);
        });

        // Dominant /24 among remotes (most secrets) — allocate within that pool
        $prefixCounts = [];
        foreach ($withRemote as $row) {
            $p = $this->ipPrefix24($row['remote_address']);
            if ($p) {
                $prefixCounts[$p] = ($prefixCounts[$p] ?? 0) + 1;
            }
        }
        arsort($prefixCounts);
        $dominantPrefix = (string) (array_key_first($prefixCounts) ?: '');

        // Highest remote in dominant subnet (or overall if no prefix)
        $lastRemote = null;
        foreach ($withRemote as $row) {
            if ($dominantPrefix !== '' && $this->ipPrefix24($row['remote_address']) !== $dominantPrefix) {
                continue;
            }
            $lastRemote = $row['remote_address'];
            break;
        }
        if (! $lastRemote) {
            $lastRemote = $withRemote[0]['remote_address'];
        }

        // Find next FREE IP after lastRemote (skip holes that are already used)
        $suggestedNext = $this->nextFreeRemoteIp($lastRemote, $usedRemotes);
        $nextIsFree = $suggestedNext !== null && ! isset($usedRemotes[$suggestedNext]);

        // Local: majority among same /24, else .1 of that subnet
        $localAddress = '';
        $localCounts = [];
        $prefix = $this->ipPrefix24($lastRemote);
        foreach ($withRemote as $row) {
            if ($prefix && $this->ipPrefix24($row['remote_address']) !== $prefix) {
                continue;
            }
            if ($row['local_address'] !== '') {
                $localCounts[$row['local_address']] = ($localCounts[$row['local_address']] ?? 0) + 1;
            }
        }
        if ($localCounts !== []) {
            arsort($localCounts);
            $localAddress = (string) array_key_first($localCounts);
        } elseif ($prefix) {
            $localAddress = $prefix.'.1';
        }
        if ($localAddress === '' && $profileName && class_exists(RouterProfile::class)) {
            $match = RouterProfile::where('router_id', $router->id)
                ->where('name', $profileName)
                ->whereNotNull('local_address')
                ->first();
            $localAddress = $this->normalizeIp((string) ($match?->local_address ?? ''));
        }

        // Preview: top 5 by remote DESC
        $recentRows = array_slice($withRemote, 0, 5);

        return [
            'last_remote_address' => $lastRemote,
            'suggested_next' => $suggestedNext,
            'suggested_available' => $nextIsFree,
            'local_address' => $localAddress ?: null,
            'recent_secrets' => $recentRows,
            'used_remote_count' => count($usedRemotes),
            'source' => 'mikrotik',
            'router_id' => $router->id,
            'router_name' => $router->name,
            'note' => $suggestedNext
                ? ($nextIsFree
                    ? "Saran {$suggestedNext} bebas di MikroTik (setelah {$lastRemote})."
                    : "IP {$suggestedNext} masih terpakai — cek manual.")
                : 'Tidak ada remote IP bebas di subnet (penuh .254).',
        ];
    }

    /** @return array{local:string,remote:string,source:string,block_id:int,block_index:int,address_format:string}|null */
    private function allocatePppoeIpForCreate(Router $router, ?string $profileName = null, ?string $customerArea = null): ?array
    {
        if (! $this->hasPppoeStaticAllocatorBlocks($router)) {
            return null;
        }

        $secrets = [];
        try {
            $mt = app(MikrotikService::class);
            $timeout = ((int) ($router->total_pppoe_count ?? 0) >= 200) ? 28 : 18;
            $mt->connect($router, ['timeout' => $timeout, 'attempts' => 1]);
            try {
                $secrets = $mt->getPPPSecretsWithAddresses();
            } finally {
                $mt->disconnect();
            }
        } catch (\Throwable $e) {
            Log::warning('Auto-PPPoE static allocator: MikroTik live failed, using DB pointer if available', [
                'router' => $router->name,
                'error' => $e->getMessage(),
            ]);
        }

        return app(PppoeAddressPoolAllocator::class)->allocate($router, $secrets, null, $profileName, $customerArea);
    }

    private function hasPppoeStaticAllocatorBlocks(Router $router): bool
    {
        try {
            return Schema::hasTable('pppoe_address_pool_blocks')
                && PppoeAddressPoolBlock::query()
                    ->where('router_id', $router->id)
                    ->where('is_active', true)
                    ->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * DB fallback when MikroTik unreachable.
     *
     * @return array<string, mixed>
     */
    private function suggestPppoeIpFromDb(Router $router, ?string $profileName = null): array
    {
        $rows = Customer::query()
            ->where('router_id', $router->id)
            ->whereNotNull('pppoe_remote_address')
            ->get(['id', 'name', 'pppoe_user', 'pppoe_local_address', 'pppoe_remote_address', 'mikrotik_profile']);

        $usedRemotes = [];
        $withRemote = [];
        foreach ($rows as $c) {
            $remote = $this->normalizeIp((string) ($c->pppoe_remote_address ?? ''));
            if ($remote === '') {
                continue;
            }
            $usedRemotes[$remote] = true;
            $withRemote[] = [
                'id' => $c->id,
                'name' => $c->name,
                'pppoe_user' => $c->pppoe_user,
                'local_address' => $this->normalizeIp((string) ($c->pppoe_local_address ?? '')),
                'remote_address' => $remote,
                'profile' => $c->mikrotik_profile,
                'source' => 'db',
            ];
        }

        usort($withRemote, fn ($a, $b) => ip2long($b['remote_address']) <=> ip2long($a['remote_address']));

        $lastRemote = $withRemote[0]['remote_address'] ?? null;
        $suggestedNext = $lastRemote ? $this->nextFreeRemoteIp($lastRemote, $usedRemotes) : null;

        $localAddress = '';
        if ($lastRemote) {
            $prefix = $this->ipPrefix24($lastRemote);
            $localCounts = [];
            foreach ($withRemote as $row) {
                if ($row['local_address'] !== '' && $this->ipPrefix24($row['remote_address']) === $prefix) {
                    $localCounts[$row['local_address']] = ($localCounts[$row['local_address']] ?? 0) + 1;
                }
            }
            if ($localCounts !== []) {
                arsort($localCounts);
                $localAddress = (string) array_key_first($localCounts);
            } elseif ($prefix) {
                $localAddress = $prefix.'.1';
            }
        }

        return [
            'last_remote_address' => $lastRemote,
            'suggested_next' => $suggestedNext,
            'suggested_available' => $suggestedNext !== null && ! isset($usedRemotes[$suggestedNext]),
            'local_address' => $localAddress ?: null,
            'recent_secrets' => array_slice($withRemote, 0, 5),
            'used_remote_count' => count($usedRemotes),
            'source' => 'db',
            'router_id' => $router->id,
            'router_name' => $router->name,
            'note' => 'MikroTik live gagal — saran dari eBilling DB (urut remote tertinggi, cek bentrok di DB).',
        ];
    }

    /**
     * After $from (e.g. 192.0.2.97), find first free IP in same /24 not in $usedRemotes.
     * Skips .0 and .255; also skips .1 if that is typically gateway (still allow if free and > last).
     *
     * @param  array<string, bool>  $usedRemotes
     */
    private function nextFreeRemoteIp(string $from, array $usedRemotes): ?string
    {
        $from = $this->normalizeIp($from);
        if ($from === '') {
            return null;
        }
        $prefix = $this->ipPrefix24($from);
        $start = $this->ipLastOctet($from);
        if (! $prefix || $start === null) {
            return null;
        }

        for ($oct = $start + 1; $oct <= 254; $oct++) {
            $candidate = $prefix.'.'.$oct;
            if (! isset($usedRemotes[$candidate])) {
                return $candidate;
            }
        }

        // Wrap-around: try lower free holes (1–start) if pool exhausted above
        for ($oct = 2; $oct <= $start; $oct++) {
            $candidate = $prefix.'.'.$oct;
            if (! isset($usedRemotes[$candidate])) {
                return $candidate;
            }
        }

        return null;
    }

    private function normalizeIp(string $ip): string
    {
        $ip = trim($ip);
        if ($ip === '') {
            return '';
        }
        // strip CIDR / comments
        $ip = preg_replace('#/\d+$#', '', $ip) ?? $ip;
        $ip = preg_replace('/\s+.*/', '', $ip) ?? $ip;

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $ip : '';
    }

    private function ipPrefix24(string $ip): ?string
    {
        $parts = explode('.', $ip);
        if (count($parts) !== 4) {
            return null;
        }

        return $parts[0].'.'.$parts[1].'.'.$parts[2];
    }

    private function ipLastOctet(string $ip): ?int
    {
        $parts = explode('.', $ip);
        if (count($parts) !== 4) {
            return null;
        }

        return (int) $parts[3];
    }

    /** @param Collection<int, Customer> $recent */
    private function majorityLocal($recent): string
    {
        $counts = [];
        foreach ($recent as $c) {
            $local = $this->normalizeIp((string) ($c->pppoe_local_address ?? ''));
            if ($local === '') {
                continue;
            }
            $counts[$local] = ($counts[$local] ?? 0) + 1;
        }
        if ($counts === []) {
            return '';
        }
        arsort($counts);

        return (string) array_key_first($counts);
    }

    private function bumpIp(string $ip): ?string
    {
        $ip = $this->normalizeIp($ip);
        if ($ip === '') {
            return null;
        }
        $parts = explode('.', $ip);
        if (count($parts) !== 4) {
            return null;
        }
        $last = (int) array_pop($parts);
        if ($last >= 254) {
            return null;
        }
        $parts[] = (string) ($last + 1);

        return implode('.', $parts);
    }

    /**
     * JSON lookups used by the Auto-PPPoE (Hioso/HTB) activation tab dropdowns.
     * These were missing, which left the Area/Router/ODP/Package selects empty.
     */

    /**
     * Areas for Auto-PPPoE / PSB dropdown.
     *
     * IMPORTANT: do NOT hard-filter by OLT.vendor→area_id alone.
     * Most Hioso OLTs have area_id=null in DB, so vendor=hioso previously
     * returned ONLY SKYNET-SRIGADING (the sole OLT with area_id set).
     *
     * Vendor is a workflow hint (next_step), not a strict area catalog filter.
     * Soft preference: areas linked to that vendor's OLTs first, then all others.
     *
     * @route GET /api/areas?vendor=hioso
     */
    public function apiAreas(Request $request)
    {
        $vendor = strtolower(trim((string) $request->input('vendor', '')));

        $all = Area::query()
            ->orderBy('name')
            ->get(['id', 'name']);

        if ($vendor === '' || $all->isEmpty()) {
            return response()->json($all->values()->all());
        }

        // Soft highlight only — never hide the rest of the catalog
        $preferredIds = Olt::query()
            ->where(function ($q) use ($vendor) {
                $q->where('vendor', $vendor)
                    ->orWhere('vendor', 'like', $vendor.'%');
            })
            ->whereNotNull('area_id')
            ->distinct()
            ->pluck('area_id')
            ->flip(); // id => index for isset

        if ($preferredIds->isEmpty()) {
            return response()->json($all->values()->all());
        }

        $preferred = $all->filter(fn (Area $a) => $preferredIds->has($a->id))->values();
        $rest = $all->reject(fn (Area $a) => $preferredIds->has($a->id))->values();

        return response()->json($preferred->concat($rest)->values()->all());
    }

    /**
     * All active routers for Auto-PPPoE dropdown (full list, not paginated).
     *
     * @route GET /api/routers
     */
    public function apiRouters()
    {
        // Active fleet only for Auto-PPPoE (retired MT: Srigading/Lawang is_active=0)
        $rows = Router::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'is_active', 'connection_status']);

        return response()->json($rows->values()->all());
    }

    /** @route GET /api/odp-odc?area_id={id} */
    public function apiOdpOdc(Request $request)
    {
        $query = OdpOdc::query();
        if ($request->filled('area_id')) {
            $query->where('area_id', (int) $request->input('area_id'));
        }

        return response()->json(
            $query->orderBy('name')->get(['id', 'name', 'type', 'olt_port'])
        );
    }

    /**
     * Packages for ONE router — eBilling packages used on this router first
     * (bukan dump profile MikroTik semua + bukan paket router lain).
     *
     * @route GET /api/routers/{router}/packages
     */
    public function apiRouterPackages(Router $router)
    {
        // 1) Packages actually assigned to customers on this router (scraper eBilling truth)
        $usedIds = Customer::query()
            ->where('router_id', $router->id)
            ->whereNotNull('package_id')
            ->distinct()
            ->pluck('package_id');

        $packages = collect();
        if ($usedIds->isNotEmpty()) {
            $packages = Package::query()
                ->whereIn('id', $usedIds)
                ->orderBy('name')
                ->get(['id', 'name', 'price', 'mikrotik_profile', 'router_id', 'code']);
        }

        // 2) If thin, add router-linked packages that have price (real billing, not empty MT stubs)
        if ($packages->count() < 3) {
            $extra = Package::query()
                ->where('router_id', $router->id)
                ->where(function ($q) {
                    $q->where('price', '>', 0)
                        ->orWhereNotNull('code');
                })
                ->orderBy('name')
                ->get(['id', 'name', 'price', 'mikrotik_profile', 'router_id', 'code']);
            $packages = $packages->concat($extra)->unique('id')->values();
        }

        // 3) Dedupe display by normalized name (keep most used / highest price)
        $counts = Customer::query()
            ->whereIn('package_id', $packages->pluck('id'))
            ->selectRaw('package_id, count(*) as c')
            ->groupBy('package_id')
            ->pluck('c', 'package_id');

        $byName = [];
        foreach ($packages as $p) {
            $key = mb_strtolower(trim((string) $p->name));
            if ($key === '') {
                continue;
            }
            if (! isset($byName[$key])) {
                $byName[$key] = $p;

                continue;
            }
            $cur = $byName[$key];
            $curCust = (int) ($counts[$cur->id] ?? 0);
            $newCust = (int) ($counts[$p->id] ?? 0);
            if ($newCust > $curCust || ((float) $p->price > (float) $cur->price && $newCust >= $curCust)) {
                $byName[$key] = $p;
            }
        }
        $out = collect(array_values($byName))->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values();

        return response()->json($out->map(fn (Package $p) => [
            'id' => $p->id,
            'name' => $p->name,
            'price' => $p->price,
            'mikrotik_profile' => $p->mikrotik_profile,
            'router_id' => $p->router_id,
        ]));
    }

    /**
     * Re-sync packages from legacy eBilling scraper + dedupe doubles.
     *
     * @route POST /api/packages/sync-scraper
     */
    public function apiSyncPackagesFromScraper(Request $request)
    {
        try {
            @set_time_limit(120);
            $sync = app(LegacySyncService::class);
            // syncPackages() already runs dedupePackages() at the end
            $synced = $sync->syncPackages();

            return response()->json([
                'success' => true,
                'synced' => $synced,
                'total_packages' => Package::count(),
                'message' => "Paket scraper: {$synced} di-update/diambil. Duplikat sudah dibersihkan otomatis.",
            ]);
        } catch (\Throwable $e) {
            Log::error('Package scraper sync failed', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Gagal sync paket: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Packages relevant to an area. Package has no area_id, so we bridge:
     *   Area → routers of (OLTs in area) ∪ (customers in area) → packages on those routers.
     * Fallback: all active packages when the area has no router signal.
     *
     * @route GET /api/areas/{area}/packages?vendor=hioso
     */
    public function apiAreaPackages(Request $request, Area $area)
    {
        $vendor = strtolower(trim((string) $request->input('vendor', '')));

        $oltRouterQuery = Olt::where('area_id', $area->id)
            ->whereNotNull('router_id');

        $vendorRouters = collect();
        if ($vendor !== '') {
            $vendorRouters = (clone $oltRouterQuery)
                ->where(function ($q) use ($vendor) {
                    $q->where('vendor', $vendor)
                        ->orWhere('vendor', 'like', $vendor.'%');
                })
                ->distinct()
                ->pluck('router_id');
        }

        $oltRouters = $vendorRouters->isNotEmpty()
            ? $vendorRouters
            : $oltRouterQuery->distinct()->pluck('router_id');

        $custRouters = Customer::where('area_id', $area->id)
            ->whereNotNull('router_id')
            ->distinct()
            ->pluck('router_id');

        $routerIds = $oltRouters->merge($custRouters)->unique()->values();

        $query = Package::where('is_active', true);
        if ($routerIds->isNotEmpty()) {
            $query->where(function ($q) use ($routerIds) {
                $q->whereIn('router_id', $routerIds)->orWhereNull('router_id');
            });
        }

        return response()->json(
            $query->orderBy('name')->get(['id', 'name', 'price', 'mikrotik_profile', 'router_id'])
        );
    }

    /**
     * Last PPPoE IPs for router — includes 5 newest secrets for preview.
     *
     * @route GET /api/routers/{router}/last-pppoe-ip?profile=10MB
     */
    public function apiLastPppoeIp(Router $router, Request $request)
    {
        $profile = $request->input('profile');
        $live = $request->boolean('fresh') || $request->boolean('live');
        if ($live) {
            Cache::forget("router:{$router->id}:pppoe-ip-suggest:mt:v3");
            Cache::forget("router:{$router->id}:pppoe-ip-suggest:db:v3");
            Cache::forget("router:{$router->id}:pppoe-ip-suggest:v2");
        }

        return response()->json(
            $this->suggestPppoeIp($router, $profile ? (string) $profile : null, $live)
        );
    }

    /**
     * OCR a KTP photo — multi-engine OCR.space + robust NIK/Nama/RT-RW parsing.
     *
     * @route POST /api/ktp-ocr
     */
    public function ktpOcr(Request $request)
    {
        $request->validate([
            'image' => 'required|image|mimes:jpeg,png,jpg,webp|max:8192',
        ]);

        $result = app(KtpOcrService::class)->parseKtp($request->file('image'));

        return response()->json($result, $result['success'] ? 200 : 200);
    }

    /**
     * Resolve MikroTik PPP profile: package field → speed heuristic → default.
     */
    private function resolveMikrotikProfile($package, MikrotikService $mikrotikService): string
    {
        $fromPkg = trim((string) ($package->mikrotik_profile ?? ''));
        if ($fromPkg !== '') {
            return $fromPkg;
        }

        $names = [];
        try {
            $profiles = $mikrotikService->getClient()
                ? $mikrotikService->getClient()->query(new Query('/ppp/profile/print'))->read()
                : [];
            foreach ($profiles as $p) {
                if (! empty($p['name'])) {
                    $names[] = (string) $p['name'];
                }
            }
        } catch (\Throwable) {
            $names = [];
        }

        $pkgName = (string) ($package->name ?? '');
        $candidates = [];
        if (preg_match('/(\d+)\s*(mbps|mb|m\b)/i', $pkgName, $m)) {
            $n = $m[1];
            $candidates[] = $n.'MB';
            $candidates[] = $n.'Mbps';
            $candidates[] = $n.'M';
            $candidates[] = $n.' MB';
        }
        $candidates[] = 'default';

        foreach ($candidates as $cand) {
            foreach ($names as $name) {
                if (strcasecmp($name, $cand) === 0) {
                    return $name;
                }
            }
        }

        return $names !== [] && in_array('default', $names, true) ? 'default' : ($names[0] ?? 'default');
    }

    /**
     * Human-readable activate note for UI (RADIUS + MikroTik honesty).
     *
     * @param  array<string, mixed>  $radiusResult
     * @param  array<string, mixed>  $mikrotikResult
     */
    private function autoPppoeResultNote(
        string $pppoeUser,
        array $radiusResult,
        array $mikrotikResult
    ): string {
        $radiusNote = ($radiusResult['synced'] ?? false)
            ? 'RADIUS synced'
            : 'RADIUS gagal: '.($radiusResult['reason'] ?? '?');

        if ($mikrotikResult['success'] ?? false) {
            $mtNote = 'Mikrotik: OK'
                .(! empty($mikrotikResult['profile']) ? ' profile='.$mikrotikResult['profile'] : '')
                .(! empty($mikrotikResult['existed']) ? ' (updated)' : ' (added)');
        } else {
            $reason = $mikrotikResult['reason']
                ?? $mikrotikResult['message']
                ?? $mikrotikResult['code']
                ?? 'push_failed';
            $code = $mikrotikResult['code'] ?? '';
            $mtNote = 'Mikrotik: FAIL '.$reason
                .($code !== '' && $code !== $reason ? " [{$code}]" : '');
        }

        return "Customer aktif. PPPoE user: {$pppoeUser}; password tersimpan terenkripsi dan diverifikasi router ({$radiusNote}) · {$mtNote}";
    }
}
