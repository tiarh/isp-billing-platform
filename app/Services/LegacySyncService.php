<?php

namespace App\Services;

use App\Models\Area;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\Router;
use Carbon\Carbon;
use Generator;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class LegacySyncService
{
    protected string $baseUrl;

    /**
     * @var array<string, int>
     */
    private array $lastCustomerAreaResolutionStats = [];

    /**
     * @var array<string, int>
     */
    private array $lastCustomerNetworkSyncStats = [];

    /** @var array<string, int> */
    private array $invoiceGuardStats = [];

    /**
     * Roadmap 1.3 A8: customer ids whose legacy "terminated/deleted" was NOT
     * applied because the eBilling row is live (status/deleted_at kept).
     *
     * @var list<int>
     */
    private array $lastTerminateSuppressedIds = [];

    public function __construct(private LegacyAreaResolver $areaResolver)
    {
        $this->baseUrl = rtrim((string) config(
            'services.legacy_scraper.url',
            config('site.legacy_scraper.primary_url')
        ), '/');
    }

    /**
     * Resolve a working scraper base URL (primary + fallbacks).
     * Avoids 500 when public sslip is unreachable from Docker.
     */
    protected function scraperBaseUrl(): string
    {
        $candidates = [];
        $primary = rtrim((string) config('services.legacy_scraper.url', $this->baseUrl), '/');
        if ($primary !== '') {
            $candidates[] = $primary;
        }
        $fallbacks = config('services.legacy_scraper.fallback_urls', []);
        if (is_array($fallbacks)) {
            foreach ($fallbacks as $u) {
                $u = rtrim((string) $u, '/');
                if ($u !== '' && ! in_array($u, $candidates, true)) {
                    $candidates[] = $u;
                }
            }
        }
        // Always try internal last-known good
        foreach ((array) config('site.legacy_scraper.internal_urls') as $u) {
            if (! in_array($u, $candidates, true)) {
                $candidates[] = $u;
            }
        }

        $timeout = max(5, (int) config('services.legacy_scraper.timeout', 45));
        $lastError = 'no scraper candidates';

        foreach ($candidates as $base) {
            try {
                $probe = Http::timeout(min(8, $timeout))
                    ->connectTimeout(5)
                    ->get("{$base}/api/v1/packages");
                if ($probe->successful()) {
                    $this->baseUrl = $base;

                    return $base;
                }
                $lastError = "HTTP {$probe->status()} from {$base}";
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                Log::debug('legacy scraper probe failed', ['url' => $base, 'error' => $lastError]);
            }
        }

        throw new \RuntimeException(
            'Scraper eBilling tidak reachable dari app container. '
            .'Set LEGACY_SCRAPER_URL='.config('site.legacy_scraper.primary_url').' di .env. '
            ."Last error: {$lastError}"
        );
    }

    protected function scraperGet(string $path, int $timeout = 0): Response
    {
        $base = $this->scraperBaseUrl();
        $timeout = $timeout > 0 ? $timeout : max(15, (int) config('services.legacy_scraper.timeout', 45));

        return Http::timeout($timeout)->connectTimeout(10)->get($base.$path);
    }

    /**
     * Sync all data in the correct dependency order.
     */
    public function syncAll(): array
    {
        $stats = [
            'areas' => $this->syncAreas(),
            'packages' => $this->syncPackages(),
        ];
        $stats['customers'] = $this->syncCustomers();
        $stats['deleted_empty_areas'] = count($this->cleanupEmptyAreas());
        $stats['invoices'] = $this->syncInvoices();

        return $stats;
    }

    public function syncAreas(): int
    {
        $response = Http::timeout(30)->get("{$this->baseUrl}/api/v1/areas");
        if (! $response->successful()) {
            return $this->syncAreasFromCustomers();
        }

        return collect($response->json())
            ->pluck('name')
            ->filter()
            ->map(fn (string $name) => $this->areaResolver->normalizeAreaName($name))
            ->filter(fn (string $name) => $this->areaResolver->isApprovedArea($name))
            ->unique()
            ->sum(function (string $name) {
                Area::updateOrCreate(
                    ['name' => $name],
                    ['code' => Str::slug($name)]
                );

                return 1;
            });
    }

    public function syncPackages(): int
    {
        $response = $this->scraperGet('/api/v1/packages');
        if (! $response->successful()) {
            throw new \Exception('Failed to fetch packages ('.$response->status().'): '.substr($response->body(), 0, 200));
        }

        $packages = $response->json();
        if (! is_array($packages)) {
            throw new \Exception('Invalid packages payload from scraper');
        }

        $count = 0;

        foreach ($packages as $data) {
            if (! is_array($data)) {
                continue;
            }
            $name = trim((string) ($data['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $code = 'PKG-'.strtoupper(substr(md5($name), 0, 8));
            $rawPrice = $data['price'] ?? null;
            $hasPositivePrice = is_numeric($rawPrice) && (float) $rawPrice > 0;
            $price = $hasPositivePrice ? $rawPrice : null;

            // Prefer exact name match with null router (global scraper catalog)
            $pkg = Package::query()
                ->whereNull('router_id')
                ->where('name', $name)
                ->orderBy('id')
                ->first();

            if (! $pkg) {
                // Case-insensitive match on global packages
                $pkg = Package::query()
                    ->whereNull('router_id')
                    ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                    ->orderBy('id')
                    ->first();
            }

            try {
                if ($pkg) {
                    $updates = [
                        'name' => $name,
                    ];
                    if ($hasPositivePrice) {
                        $updates['price'] = $price;
                    }
                    if (empty($pkg->code)) {
                        $updates['code'] = $code;
                    }
                    if (Schema::hasColumn('packages', 'is_active')) {
                        $updates['is_active'] = true;
                    }
                    $pkg->update($updates);
                } else {
                    $create = [
                        'name' => $name,
                        'code' => $code,
                        'price' => $price ?? 0,
                        'router_id' => null,
                    ];
                    if (Schema::hasColumn('packages', 'is_active')) {
                        $create['is_active'] = true;
                    }
                    // Unique code collision: append short suffix
                    if (Package::where('code', $code)->exists()) {
                        $create['code'] = $code.'-'.substr(uniqid(), -4);
                    }
                    Package::create($create);
                }
                $count++;
            } catch (\Throwable $e) {
                Log::warning('syncPackages row failed', [
                    'name' => $name,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // After scraper upsert, collapse exact/case-insensitive doubles
        $this->dedupePackages();

        return $count;
    }

    /**
     * Merge duplicate packages (same name + same router_id, case-insensitive).
     * Keeps the row with most customers (then highest price, then lowest id).
     * Reassigns customers then deletes empty duplicates.
     *
     * @return array{merged:int,deleted:int,groups:int}
     */
    public function dedupePackages(): array
    {
        $merged = 0;
        $deleted = 0;
        $groups = 0;

        // Group keys: lower(name)|router_id|null
        $all = Package::query()
            ->orderBy('id')
            ->get(['id', 'name', 'router_id', 'price', 'code', 'mikrotik_profile']);

        $buckets = [];
        foreach ($all as $pkg) {
            $key = mb_strtolower(trim((string) $pkg->name)).'|'.($pkg->router_id === null ? 'null' : (string) $pkg->router_id);
            $buckets[$key][] = $pkg;
        }

        foreach ($buckets as $list) {
            if (count($list) < 2) {
                continue;
            }
            $groups++;

            // Score: customer count desc, price desc, id asc
            usort($list, function ($a, $b) {
                $ca = Customer::where('package_id', $a->id)->count();
                $cb = Customer::where('package_id', $b->id)->count();
                if ($ca !== $cb) {
                    return $cb <=> $ca;
                }
                $pa = (float) ($a->price ?? 0);
                $pb = (float) ($b->price ?? 0);
                if ($pa !== $pb) {
                    return $pb <=> $pa;
                }

                return $a->id <=> $b->id;
            });

            $keeper = array_shift($list);
            foreach ($list as $loser) {
                $moved = Customer::where('package_id', $loser->id)->update(['package_id' => $keeper->id]);
                if ($moved > 0) {
                    $merged += $moved;
                }
                // Copy useful fields onto keeper if empty
                $updates = [];
                if (empty($keeper->mikrotik_profile) && ! empty($loser->mikrotik_profile)) {
                    $updates['mikrotik_profile'] = $loser->mikrotik_profile;
                }
                if ((float) ($keeper->price ?? 0) <= 0 && (float) ($loser->price ?? 0) > 0) {
                    $updates['price'] = $loser->price;
                }
                if ($updates !== []) {
                    $keeper->update($updates);
                }
                // Only delete if no remaining customers
                if (Customer::where('package_id', $loser->id)->count() === 0) {
                    $loser->delete();
                    $deleted++;
                }
            }
        }

        Log::info('packages:dedupe done', compact('merged', 'deleted', 'groups'));

        return compact('merged', 'deleted', 'groups');
    }

    public function syncCustomers(): int
    {
        $response = Http::timeout(60)->get("{$this->baseUrl}/api/v1/customers");
        if (! $response->successful()) {
            throw new \Exception('Failed to fetch customers: '.$response->body());
        }

        $customers = $response->json();
        $packagesByName = Package::query()
            ->orderBy('id')
            ->get(['id', 'name'])
            ->mapWithKeys(fn (Package $package): array => [
                $this->packageNameKey($package->name) => $package->id,
            ]);
        $areasByName = Area::pluck('id', 'name');
        $customerCodes = collect($customers)
            ->map(fn (array $data): string => (string) ($data['code'] ?? $data['id'] ?? ''))
            ->filter()
            ->unique()
            ->values();
        $existingPackageIdsByCode = Customer::withTrashed()
            ->whereIn('code', $customerCodes)
            ->pluck('package_id', 'code');
        $fallbackPkg = null;
        $seenPppoeUsers = [];
        $existingPppoeByCode = Customer::withTrashed()
            ->whereNotNull('pppoe_user')
            ->pluck('pppoe_user', 'code');
        $existingPppoeOwners = Customer::withTrashed()
            ->whereNotNull('pppoe_user')
            ->get(['id', 'code', 'pppoe_user', 'deleted_at'])
            ->keyBy('pppoe_user');
        // A8: status/deleted_at of rows that already exist, to keep live customers live.
        $existingLifecycleByCode = Customer::withTrashed()
            ->whereIn('code', $customerCodes)
            ->get(['id', 'code', 'status', 'deleted_at'])
            ->keyBy('code');
        $terminateSuppressedIds = [];
        $suppressedCodes = [];
        $routersByScraperName = $this->routersByScraperName();
        $rows = [];
        $now = now();
        $areaResolutionStats = $this->emptyAreaResolutionStats();
        $networkSyncStats = $this->emptyNetworkSyncStats();

        foreach ($customers as $data) {
            $customerCode = (string) ($data['code'] ?? $data['id']);
            $source = strtolower((string) ($data['source'] ?? 'unknown'));
            $networkSyncStats["source_{$source}"] = ($networkSyncStats["source_{$source}"] ?? 0) + 1;

            $packageId = null;
            if (! empty($data['package'])) {
                $packageId = $packagesByName->get($this->packageNameKey($data['package']['name']));
            }

            if (! $packageId) {
                $packageId = $existingPackageIdsByCode->get($customerCode);
            }

            if (! $packageId) {
                $fallbackPkg ??= Package::firstOrCreate(
                    ['name' => 'Legacy/Unknown Package'],
                    [
                        'code' => 'PKG-UNKNOWN',
                        'price' => 0,
                    ]
                );
                $packageId = $fallbackPkg->id;
            }

            $areaId = null;
            $resolvedArea = $this->areaResolver->resolve($data);
            $areaResolutionStats[$resolvedArea['reason']] = ($areaResolutionStats[$resolvedArea['reason']] ?? 0) + 1;
            if ($resolvedArea['area']) {
                $areaId = $areasByName->get($resolvedArea['area']);
                if (! $areaId) {
                    $area = Area::firstOrCreate(
                        ['name' => $resolvedArea['area']],
                        ['code' => Str::slug($resolvedArea['area'])]
                    );
                    $areaId = $area->id;
                    $areasByName->put($resolvedArea['area'], $areaId);
                }
            } else {
                Log::warning('Legacy customer has no approved area mapping', [
                    'customer_id' => $data['id'] ?? null,
                    'customer_name' => $data['name'] ?? null,
                ]);
            }

            $joinDate = ! empty($data['join_date']) ? Carbon::parse($data['join_date']) : null;

            $scrapedPppoeUser = $data['pppoe_username'] ?? $data['pppoe_user'] ?? null;
            $pppoeUser = $this->resolvePppoeUser(
                customerCode: $customerCode,
                scrapedPppoeUser: $scrapedPppoeUser,
                existingPppoeByCode: $existingPppoeByCode,
                existingPppoeOwners: $existingPppoeOwners,
                stats: $networkSyncStats
            );
            $attempts = 0;
            while (
                isset($seenPppoeUsers[$pppoeUser])
                && $seenPppoeUsers[$pppoeUser] !== $customerCode
            ) {
                $attempts++;
                $pppoeUser = $customerCode.'_USR_'.($attempts + 1);
            }
            $seenPppoeUsers[$pppoeUser] = $customerCode;

            $isMikrotikSyncable = (bool) ($data['is_mikrotik_syncable'] ?? false);
            $networkSyncStats[$isMikrotikSyncable ? 'mikrotik_syncable' : 'not_mikrotik_syncable']++;
            $routerId = $this->resolveRouterId($data, $routersByScraperName, $networkSyncStats);

            $phone = ! empty($data['phone']) ? $data['phone'] : '';
            $address = ! empty($data['address']) ? $data['address'] : '-';

            $statusRaw = strtolower($data['status'] ?? 'active');
            $validStatuses = ['active', 'suspended', 'inactive', 'isolated', 'terminated', 'pending_installation'];
            if ($statusRaw === 'deleted') {
                $statusRaw = 'terminated';
            } elseif (! in_array($statusRaw, $validStatuses)) {
                $statusRaw = 'active';
            }

            $deletedAt = strtolower($data['status'] ?? '') === 'deleted' ? $now : null;

            // Roadmap 1.3 A8 (owner Q10): legacy "terminated/deleted" for a LIVE
            // eBilling customer (exists, not archived, not terminated) never changes
            // status/deleted_at; it is only reported. No guard call per row.
            $existingLifecycle = $existingLifecycleByCode->get($customerCode);
            if ($existingLifecycle
                && $existingLifecycle->deleted_at === null
                && (string) $existingLifecycle->status !== 'terminated'
                && ($statusRaw === 'terminated' || $deletedAt !== null)) {
                // N9 (known limitation): suppression is keyed by customer code. If the
                // feed lists the same code twice (one deleted, one non-terminal) both
                // rows take the status-less update, so the non-terminal duplicate's
                // status is not applied either. Legacy feeds have unique codes and the
                // importer is gated by legacy_full_sync_enabled (default off).
                // Written by a separate update WITHOUT status/deleted_at below, so a
                // status change made meanwhile is never reverted to the preloaded one.
                $suppressedCodes[$customerCode] = true;
                $terminateSuppressedIds[] = (int) $existingLifecycle->id;
            }

            $rows[] = [
                'code' => $customerCode,
                'legacy_id' => $customerCode,
                'name' => $data['name'],
                'nik' => ! empty($data['nik']) ? $data['nik'] : null,
                'address' => $address,
                'phone' => $phone,
                'geo_lat' => $data['geo_lat'],
                'geo_long' => $data['geo_long'],
                'pppoe_user' => $pppoeUser,
                'package_id' => $packageId,
                'area_id' => $areaId,
                'router_id' => $routerId,
                'status' => $statusRaw,
                'join_date' => $joinDate?->toDateString(),
                'due_day' => $data['due_day'] ?? 20,
                'ktp_photo_url' => $data['ktp_photo_url'],
                'is_online' => $data['is_online'] ?? false,
                'deleted_at' => $deletedAt,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // A8: suppressed rows never carry status/deleted_at.
        $totalRows = count($rows);
        $suppressedRows = [];
        $rows = array_values(array_filter($rows, function (array $row) use ($suppressedCodes, &$suppressedRows): bool {
            if (isset($suppressedCodes[$row['code']])) {
                $suppressedRows[] = $row;

                return false;
            }

            return true;
        }));
        foreach ($suppressedRows as $row) {
            DB::table('customers')
                ->where('code', $row['code'])
                ->update(Arr::except($row, ['code', 'status', 'deleted_at', 'created_at']));
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('customers')->upsert($chunk, ['code'], [
                'legacy_id',
                'name',
                'nik',
                'address',
                'phone',
                'geo_lat',
                'geo_long',
                'pppoe_user',
                'package_id',
                'area_id',
                'router_id',
                'status',
                'join_date',
                'due_day',
                'ktp_photo_url',
                'is_online',
                'deleted_at',
                'updated_at',
            ]);
        }

        $this->lastCustomerAreaResolutionStats = $areaResolutionStats;
        $this->lastCustomerNetworkSyncStats = $networkSyncStats;
        $terminateSuppressedIds = array_values(array_unique($terminateSuppressedIds));
        $this->lastTerminateSuppressedIds = $terminateSuppressedIds;

        if ($terminateSuppressedIds !== []) {
            Log::warning('[LifecycleGuard] legacy_terminate_suppressed', [
                'count' => count($terminateSuppressedIds),
                'customer_ids' => array_slice($terminateSuppressedIds, 0, 200),
            ]);
        }

        $fallbackCount = collect($areaResolutionStats)
            ->except(['api_area'])
            ->sum();

        if ($fallbackCount > 0) {
            Log::info('Legacy customer sync used fallback area mapping for some rows.', [
                'area_resolution' => $areaResolutionStats,
            ]);
        }

        Log::info('Legacy customer sync network classification summary.', [
            'network_sync' => $networkSyncStats,
        ]);

        return $totalRows;
    }

    /**
     * @return array<string, int>
     */
    public function lastCustomerAreaResolutionStats(): array
    {
        return $this->lastCustomerAreaResolutionStats;
    }

    /**
     * @return array<string, int>
     */
    public function lastCustomerNetworkSyncStats(): array
    {
        return $this->lastCustomerNetworkSyncStats;
    }

    /**
     * Customer ids whose legacy terminate/delete was suppressed in the last sync.
     *
     * @return list<int>
     */
    public function lastTerminateSuppressedIds(): array
    {
        return $this->lastTerminateSuppressedIds;
    }

    /**
     * @return array<string, int>
     */
    private function emptyAreaResolutionStats(): array
    {
        return [
            'api_area' => 0,
            'legacy_location' => 0,
            'prefix' => 0,
            'package_keyword' => 0,
            'address_keyword' => 0,
            'unmapped' => 0,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function emptyNetworkSyncStats(): array
    {
        return [
            'source_warga' => 0,
            'source_stale' => 0,
            'mikrotik_syncable' => 0,
            'not_mikrotik_syncable' => 0,
            'pppoe_from_scraper' => 0,
            'pppoe_preserved_existing' => 0,
            'pppoe_placeholder' => 0,
            'pppoe_conflict' => 0,
            'released_deleted_imp_conflicts' => 0,
            'router_mapped' => 0,
            'router_unmapped' => 0,
            'router_blank' => 0,
        ];
    }

    /**
     * @param  Collection<string, string>  $existingPppoeByCode
     * @param  Collection<string, Customer>  $existingPppoeOwners
     * @param  array<string, int>  $stats
     */
    private function resolvePppoeUser(
        string $customerCode,
        mixed $scrapedPppoeUser,
        $existingPppoeByCode,
        $existingPppoeOwners,
        array &$stats
    ): string {
        $scrapedPppoeUser = trim((string) $scrapedPppoeUser);

        if ($scrapedPppoeUser !== '') {
            $owner = $existingPppoeOwners->get($scrapedPppoeUser);

            if ($owner && $owner->code !== $customerCode) {
                if ($owner->trashed() && str_starts_with((string) $owner->code, 'IMP-')) {
                    DB::table('customers')
                        ->where('id', $owner->id)
                        ->update(['pppoe_user' => $this->releasedImportedPppoeValue($owner)]);
                    $existingPppoeOwners->forget($scrapedPppoeUser);
                    $stats['released_deleted_imp_conflicts']++;
                } else {
                    $stats['pppoe_conflict']++;

                    return $existingPppoeByCode->get($customerCode) ?: $customerCode.'_USR';
                }
            }

            $stats['pppoe_from_scraper']++;

            return $scrapedPppoeUser;
        }

        $existingPppoeUser = trim((string) ($existingPppoeByCode->get($customerCode) ?? ''));
        if ($existingPppoeUser !== '') {
            $stats['pppoe_preserved_existing']++;

            return $existingPppoeUser;
        }

        $stats['pppoe_placeholder']++;

        return $customerCode.'_USR';
    }

    private function releasedImportedPppoeValue(Customer $customer): string
    {
        return 'RELEASED-IMP-'.$customer->id.'-'.substr(md5((string) $customer->pppoe_user), 0, 8);
    }

    /**
     * @return array<string, Router>
     */
    private function routersByScraperName(): array
    {
        $routersByName = Router::all()->keyBy('name');
        $aliases = config('legacy_sync.router_aliases', []);
        $mapped = [];

        foreach ($aliases as $scraperName => $localRouterName) {
            $router = $routersByName->get($localRouterName);
            if ($router) {
                $mapped[$scraperName] = $router;
            }
        }

        foreach ($routersByName as $routerName => $router) {
            $mapped[$routerName] = $router;
        }

        return $mapped;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, Router>  $routersByScraperName
     * @param  array<string, int>  $stats
     */
    private function resolveRouterId(array $data, array $routersByScraperName, array &$stats): ?int
    {
        $routerName = trim((string) (
            data_get($data, 'router.name')
            ?? $data['router_name']
            ?? $data['nama_router']
            ?? ''
        ));

        if ($routerName === '') {
            $stats['router_blank']++;

            return null;
        }

        $router = $routersByScraperName[$routerName] ?? null;
        if (! $router) {
            $stats['router_unmapped']++;

            return null;
        }

        $stats['router_mapped']++;

        return $router->id;
    }

    /**
     * Delete areas that remain unused after the latest customer sync.
     *
     * @return array<int, string>
     */
    public function cleanupEmptyAreas(): array
    {
        $areas = Area::query()
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('customers')
                    ->whereColumn('customers.area_id', 'areas.id');
            })
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('area_user')
                    ->whereColumn('area_user.area_id', 'areas.id');
            })
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('wa_campaigns')
                    ->whereColumn('wa_campaigns.target_area_id', 'areas.id');
            })
            ->orderBy('name')
            ->get(['id', 'name']);

        foreach ($areas as $area) {
            $area->delete();
        }

        return $areas->pluck('name')->all();
    }

    public function syncInvoices(): int
    {
        $tmpDir = storage_path('app/legacy-sync');
        File::ensureDirectoryExists($tmpDir);

        $tmpFile = tempnam($tmpDir, 'invoices-');
        if ($tmpFile === false) {
            throw new \RuntimeException('Failed to create temporary file for invoice sync.');
        }

        try {
            $response = Http::sink($tmpFile)
                ->timeout(180)
                ->get("{$this->baseUrl}/api/v1/invoices");

            if (! $response->successful()) {
                $body = File::exists($tmpFile) ? File::get($tmpFile) : '';

                throw new \Exception('Failed to fetch invoices: '.$body);
            }

            $customerIdsByCode = Customer::withTrashed()->pluck('id', 'code');
            $rows = [];
            $count = 0;
            $now = now();

            foreach ($this->streamJsonArrayFile($tmpFile) as $data) {
                $customerId = $customerIdsByCode->get((string) $data['customer_id']);
                if (! $customerId) {
                    // If customer is missing locally, we cannot assign the invoice.
                    Log::warning("Skipping invoice sync for missing customer: {$data['customer_id']}");

                    continue;
                }

                $periodDate = Carbon::parse($data['period']);
                $dueDate = $data['due_date'] ? Carbon::parse($data['due_date']) : $periodDate->copy()->addDays(20);

                $rows[] = [
                    'customer_id' => $customerId,
                    'period' => $periodDate->toDateString(),
                    'legacy_id' => isset($data['id']) ? (string) $data['id'] : null,
                    'uuid' => $data['uuid'] ?? (string) Str::uuid(),
                    'code' => $data['code'] ?? 'INV-'.$periodDate->format('Ym').'-'.$data['customer_id'],
                    'amount' => $data['amount'],
                    'status' => $data['status'],
                    'due_date' => $dueDate->toDateString(),
                    'generated_at' => ! empty($data['generated_at']) ? Carbon::parse($data['generated_at']) : $now,
                    'last_synced_at' => ! empty($data['last_synced_at']) ? Carbon::parse($data['last_synced_at']) : $now,
                    'payment_link' => $data['payment_link'] ?? null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $count++;

                if (count($rows) >= 1000) {
                    $this->upsertInvoiceRows($rows);
                    $rows = [];
                }
            }

            $this->upsertInvoiceRows($rows);

            return $count;
        } finally {
            File::delete($tmpFile);
        }
    }

    private function upsertInvoiceRows(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        // Legacy invoice codes are globally unique; dedupe within batch (last row wins).
        $rows = collect($rows)
            ->keyBy(fn (array $row) => (string) $row['code'])
            ->values()
            ->all();

        $codes = collect($rows)->pluck('code')->all();
        $incomingUuids = collect($rows)->pluck('uuid')->filter()->unique()->values()->all();

        $uuidByCode = DB::table('invoices')
            ->whereIn('code', $codes)
            ->pluck('uuid', 'code');
        $codeByUuid = $incomingUuids === []
            ? collect()
            : DB::table('invoices')
                ->whereIn('uuid', $incomingUuids)
                ->pluck('code', 'uuid');

        $rows = $this->guardInvoiceRows($rows);
        if ($rows === []) {
            return;
        }

        foreach ($rows as &$row) {
            $code = (string) $row['code'];
            $incomingUuid = (string) ($row['uuid'] ?? '');

            if ($uuidByCode->has($code)) {
                $row['uuid'] = $uuidByCode[$code];

                continue;
            }

            if ($incomingUuid === '' || ($codeByUuid->has($incomingUuid) && $codeByUuid[$incomingUuid] !== $code)) {
                $row['uuid'] = (string) Str::uuid();
            }
        }
        unset($row);

        DB::table('invoices')->upsert($rows, ['code'], [
            'customer_id',
            'period',
            'legacy_id',
            'amount',
            'status',
            'due_date',
            'generated_at',
            'last_synced_at',
            'payment_link',
            'updated_at',
        ]);
    }

    /**
     * M6: legacy must never overwrite what eBilling owns.
     *
     * The upsert matches on `code`, but MySQL ON DUPLICATE KEY also fires on
     * the unique (customer_id, period), so the existing row is looked up both
     * ways. A row is dropped (and logged with its diff) when the existing
     * invoice is paid in eBilling (status paid) or has any eBilling
     * transaction or payment reversal (status, amount are then eBilling-owned).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function guardInvoiceRows(array $rows): array
    {
        $codes = array_map(fn (array $row) => (string) $row['code'], $rows);
        $customerIds = array_values(array_unique(array_map(fn (array $row) => (int) $row['customer_id'], $rows)));
        $periods = array_values(array_unique(array_map(fn (array $row) => substr((string) $row['period'], 0, 10), $rows)));

        $existing = DB::table('invoices')
            ->where(function ($q) use ($codes, $customerIds, $periods) {
                $q->whereIn('code', $codes)
                    ->orWhere(fn ($q2) => $q2->whereIn('customer_id', $customerIds)->whereIn('period', $periods));
            })
            ->get(['id', 'code', 'customer_id', 'period', 'amount', 'status', 'due_date']);
        if ($existing->isEmpty()) {
            return $rows;
        }

        $byCode = $existing->keyBy(fn ($inv) => (string) $inv->code);
        $byCustomerPeriod = $existing->keyBy(fn ($inv) => $inv->customer_id.'|'.substr((string) $inv->period, 0, 10));
        $ids = $existing->pluck('id')->all();
        $owned = DB::table('transactions')->whereIn('invoice_id', $ids)->distinct()->pluck('invoice_id')
            ->merge(DB::table('payment_reversals')->whereIn('invoice_id', $ids)->distinct()->pluck('invoice_id'))
            ->map(fn ($id) => (int) $id)
            ->flip();

        $kept = [];
        foreach ($rows as $row) {
            $current = $byCode->get((string) $row['code'])
                ?? $byCustomerPeriod->get($row['customer_id'].'|'.substr((string) $row['period'], 0, 10));
            if ($current === null) {
                $kept[] = $row;

                continue;
            }

            $reason = null;
            if ($owned->has((int) $current->id)) {
                $reason = 'has_ebilling_payment';
            } elseif ($current->status === 'paid') {
                $reason = 'ebilling_paid';
            }

            if ($reason === null) {
                $kept[] = $row;

                continue;
            }

            $diff = [];
            foreach (['status', 'amount', 'due_date', 'period', 'code'] as $field) {
                $old = match ($field) {
                    'amount' => (float) $current->amount,
                    'due_date', 'period' => substr((string) $current->{$field}, 0, 10),
                    default => (string) $current->{$field},
                };
                $new = match ($field) {
                    'amount' => (float) $row['amount'],
                    'due_date', 'period' => substr((string) $row[$field], 0, 10),
                    default => (string) $row[$field],
                };
                if ($old !== $new) {
                    $diff[$field] = ['ebilling' => $old, 'legacy' => $new];
                }
            }
            $this->invoiceGuardStats[$reason] = ($this->invoiceGuardStats[$reason] ?? 0) + 1;
            if ($diff !== []) {
                Log::warning('[legacy-sync] invoice update skipped', [
                    'invoice_id' => (int) $current->id,
                    'reason' => $reason,
                    'diff' => $diff,
                ]);
            }
        }

        return $kept;
    }

    /**
     * Counts of legacy invoice rows skipped by the M6 guard in this process.
     *
     * @return array<string, int>
     */
    public function lastInvoiceGuardStats(): array
    {
        return $this->invoiceGuardStats;
    }

    /**
     * Stream a top-level JSON array of objects without decoding the whole file.
     */
    private function streamJsonArrayFile(string $path): Generator
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("Failed to open streamed JSON file: {$path}");
        }

        $buffer = '';
        $depth = 0;
        $inString = false;
        $escaped = false;

        try {
            while (! feof($handle)) {
                $chunk = fread($handle, 8192);
                if ($chunk === false) {
                    throw new \RuntimeException("Failed to read streamed JSON file: {$path}");
                }

                $length = strlen($chunk);
                for ($i = 0; $i < $length; $i++) {
                    $char = $chunk[$i];

                    if ($depth > 0) {
                        $buffer .= $char;
                    }

                    if ($inString) {
                        if ($escaped) {
                            $escaped = false;
                        } elseif ($char === '\\') {
                            $escaped = true;
                        } elseif ($char === '"') {
                            $inString = false;
                        }

                        continue;
                    }

                    if ($char === '"') {
                        $inString = true;

                        continue;
                    }

                    if ($char === '{') {
                        if ($depth === 0) {
                            $buffer = '{';
                        }

                        $depth++;

                        continue;
                    }

                    if ($char === '}') {
                        $depth--;

                        if ($depth === 0) {
                            $decoded = json_decode($buffer, true, flags: JSON_THROW_ON_ERROR);
                            if (is_array($decoded)) {
                                yield $decoded;
                            }

                            $buffer = '';
                        }
                    }
                }
            }
        } finally {
            fclose($handle);
        }
    }

    private function syncAreasFromCustomers(): int
    {
        $response = Http::timeout(60)->get("{$this->baseUrl}/api/v1/customers");
        if (! $response->successful()) {
            throw new \Exception('Failed to fetch areas and customer fallback: '.$response->body());
        }

        return collect($response->json())
            ->map(fn (array $customer) => $this->areaResolver->resolve($customer)['area'])
            ->filter()
            ->unique()
            ->sum(function (string $name) {
                Area::updateOrCreate(
                    ['name' => $name],
                    ['code' => Str::slug($name)]
                );

                return 1;
            });
    }

    private function packageNameKey(mixed $name): string
    {
        return preg_replace('/\s+/', ' ', mb_strtolower(trim((string) $name))) ?? '';
    }
}
