<?php

namespace App\Http\Controllers;

use App\Enums\CustomerStatus;
use App\Models\Area;
use App\Models\Customer;
use App\Models\OdpOdc;
use App\Models\User;
use App\Services\UnifiedConnectivityReadModel;
use App\Support\AreaScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class MapsController extends Controller
{
    public function peta()
    {
        // Send only area list + stats (lightweight). GeoJSON loaded lazily per area.
        // Customer markers are useful before the ODP/ODC inventory is complete.
        // Keep asset areas, but also include areas with customers that already
        // have coordinates so the map does not silently hide them.
        $query = Area::where(function ($query) {
            $query->whereHas('odpOdc')
                ->orWhereHas('customers', function ($customerQuery) {
                    $customerQuery->whereNotNull('geo_lat')
                        ->whereNotNull('geo_long');
                });
        });
        AreaScope::applyToAreas($query, request()->user());
        $dbAreas = $query->get();
        $areas = [];
        foreach ($dbAreas as $area) {
            $odpCount = OdpOdc::where('area_id', $area->id)->where('type', 'ODP')->count();
            $odcCount = OdpOdc::where('area_id', $area->id)->where('type', '!=', 'ODP')->count();
            $rwList = Customer::query()
                ->where('area_id', $area->id)
                ->whereNotNull('geo_lat')
                ->whereNotNull('geo_long')
                ->limit(500)
                ->get(['address', 'odp_name'])
                ->map(fn (Customer $customer) => $this->customerRw($customer))
                ->filter()
                ->unique()
                ->sort()
                ->values();
            $areas[$area->code] = [
                'name' => $area->name,
                'stats' => [
                    'odp' => $odpCount,
                    'odc' => $odcCount,
                    'customers' => Customer::where('area_id', $area->id)->whereNotNull('geo_lat')->whereNotNull('geo_long')->count(),
                    'customers_total' => Customer::where('area_id', $area->id)->count(),
                    'customers_missing_coordinates' => Customer::where('area_id', $area->id)
                        ->where(function ($customerQuery) {
                            $customerQuery->whereNull('geo_lat')->orWhereNull('geo_long');
                        })
                        ->count(),
                ],
                'rws' => $rwList,
            ];
        }

        return Inertia::render('Peta/Index', [
            'areas' => $areas,
        ]);
    }

    public function petaGeojson(string $area)
    {
        $disk = Storage::disk(config('filesystems.default'));
        $dbArea = Area::where('code', $area)->firstOrFail();
        AreaScope::authorizeAreaId((int) $dbArea->id, request()->user());
        $request = request();
        $layers = collect(explode(',', (string) $request->query('layers', 'ODP,ODC,POLE,CABLE')))
            ->map(fn ($layer) => strtoupper(trim($layer)))
            ->filter()
            ->values()
            ->all();
        $withCustomers = in_array('CUSTOMER', $layers, true);
        $rwFilter = trim((string) $request->query('rw', ''));
        $limit = max(1, min((int) $request->query('limit', 500), 1000));
        $bbox = $this->parseBbox($request->query('bbox'));
        $user = $request->user();
        $preciseCoordinates = $this->canViewPreciseCustomerCoordinates($user);
        $cacheKey = 'peta:geojson:v6d:'.$dbArea->id.':'.($preciseCoordinates ? 'precise' : 'area').':'.md5(json_encode([$layers, $rwFilter, $limit, $bbox]));

        $payload = Cache::remember($cacheKey, now()->addSeconds(90), function () use ($disk, $dbArea, $layers, $withCustomers, $rwFilter, $limit, $bbox, $user) {
            return $this->buildPetaGeojson($disk, $dbArea, $layers, $withCustomers, $rwFilter, $limit, $bbox, $user);
        });

        return response()->json($payload);
    }

    public function petaCustomers(string $area)
    {
        $dbArea = Area::where('code', $area)->firstOrFail();
        AreaScope::authorizeAreaId((int) $dbArea->id, request()->user());
        $request = request();
        $bbox = $this->parseBbox($request->query('bbox'));
        $zoom = max(0, min(22, (int) $request->query('zoom', 12)));
        $limit = max(50, min(300, (int) $request->query('limit', 250)));
        $rwFilter = trim((string) $request->query('rw', ''));
        $user = $request->user();
        $preciseCoordinates = $this->canViewPreciseCustomerCoordinates($user);
        $cacheKey = 'peta:customers:v3:'.$dbArea->id.':'.($preciseCoordinates ? 'precise' : 'area').':'.md5(json_encode([$bbox, $zoom, $limit, $rwFilter]));

        $payload = Cache::remember($cacheKey, now()->addSeconds(60), function () use ($dbArea, $bbox, $zoom, $limit, $rwFilter, $user) {
            $query = Customer::query()
                ->select($this->mapCustomerColumns())
                ->with('olt:id,vendor')
                ->where('area_id', $dbArea->id)
                ->whereNotNull('geo_lat')
                ->whereNotNull('geo_long');

            if ($bbox) {
                $query->whereBetween('geo_long', [$bbox['west'], $bbox['east']])
                    ->whereBetween('geo_lat', [$bbox['south'], $bbox['north']]);
            }

            $missingCoordinates = Customer::query()
                ->where('area_id', $dbArea->id)
                ->where(fn ($q) => $q->whereNull('geo_lat')->orWhereNull('geo_long'))
                ->count();

            if ($zoom <= 12) {
                $grid = $zoom <= 9 ? 0.05 : ($zoom <= 11 ? 0.01 : 0.003);
                $clusters = [];
                $clusterTotal = 0;
                $clusterQuery = clone $query;
                $clusterQuery->orderBy('id')->chunkById(500, function ($customers) use (&$clusters, &$clusterTotal, $grid, $rwFilter): void {
                    // Same rule as the NOC connectivity table (fresh PPPoE + ONU + RX).
                    $connectivity = app(UnifiedConnectivityReadModel::class)->composeMany($customers);
                    foreach ($customers as $customer) {
                        $connectivityStatus = $connectivity[(int) $customer->id]['status'] ?? 'unknown';
                        if ($rwFilter !== '' && strcasecmp($this->customerRw($customer), $rwFilter) !== 0) {
                            continue;
                        }

                        $key = round((float) $customer->geo_lat / $grid) * $grid.','.round((float) $customer->geo_long / $grid) * $grid;
                        if (! isset($clusters[$key])) {
                            $clusters[$key] = ['lat' => 0.0, 'lng' => 0.0, 'count' => 0, 'online_count' => 0, 'offline_count' => 0, 'unknown_count' => 0, 'isolated_count' => 0];
                        }

                        $clusters[$key]['lat'] += (float) $customer->geo_lat;
                        $clusters[$key]['lng'] += (float) $customer->geo_long;
                        $clusters[$key]['count']++;
                        $clusters[$key]['online_count'] += (int) ($connectivityStatus === 'online');
                        $clusters[$key]['offline_count'] += (int) ($connectivityStatus === 'offline');
                        $clusters[$key]['unknown_count'] += (int) ! in_array($connectivityStatus, ['online', 'offline', 'isolated'], true);
                        $clusters[$key]['isolated_count'] += (int) in_array($customer->status, CustomerStatus::isolatedOrClosed(), true);
                        $clusterTotal++;
                    }
                });
                $clusterRows = collect($clusters)->sortByDesc('count')->take($limit);
                $features = $clusterRows->map(function (array $cluster) use ($user): array {
                    $lat = $this->customerMapCoordinate($cluster['lat'] / max(1, $cluster['count']), $user);
                    $lng = $this->customerMapCoordinate($cluster['lng'] / max(1, $cluster['count']), $user);

                    return [
                        'type' => 'Feature',
                        'properties' => [
                            'type' => 'CUSTOMER_CLUSTER',
                            'name' => 'Cluster pelanggan',
                            'count' => $cluster['count'],
                            'online_count' => $cluster['online_count'],
                            'offline_count' => $cluster['offline_count'],
                            'unknown_count' => $cluster['unknown_count'],
                            'isolated_count' => $cluster['isolated_count'],
                        ],
                        'geometry' => ['type' => 'Point', 'coordinates' => [$lng, $lat]],
                    ];
                })->values()->take($limit)->all();
                $clusteredCount = count($features);
                $customerTotal = $rwFilter === '' ? (int) (clone $query)->count() : $clusterTotal;
                $truncated = $clusterTotal > $limit;
            } else {
                $customers = $query->orderByDesc('is_online')->orderBy('name')->limit($rwFilter === '' ? $limit + 1 : 10000)->get()
                    ->filter(fn (Customer $customer): bool => $rwFilter === '' || strcasecmp($this->customerRw($customer), $rwFilter) === 0)
                    ->values();
                $customerTotal = $rwFilter === '' ? (int) (clone $query)->count() : $customers->count();
                $truncated = $rwFilter === '' ? $customers->count() > $limit : $customers->count() >= 10000;
                $connectivity = app(UnifiedConnectivityReadModel::class)->composeMany($customers->take($limit));
                $features = $customers->take($limit)->map(fn (Customer $customer): array => $this->customerFeature($customer, $user, $connectivity[(int) $customer->id] ?? null))->all();
                $clusteredCount = 0;
            }

            return [
                'type' => 'FeatureCollection',
                'features' => array_values($features),
                'stats' => [
                    'customer_total' => $customerTotal,
                    'customer_visible' => count($features),
                    'customer_clustered' => $clusteredCount,
                    'customer_missing_coordinates' => $missingCoordinates,
                    'customer_truncated' => $truncated,
                    'zoom' => $zoom,
                ],
                'as_of' => now()->timezone('Asia/Jakarta')->toISOString(),
            ];
        });

        $response = response()->json($payload)
            ->setEtag(sha1(json_encode($payload)))
            ->header('Cache-Control', 'private, max-age=60, stale-while-revalidate=30');
        $response->isNotModified($request);

        return $response;
    }

    public function searchCustomers(Request $request)
    {
        $term = trim((string) $request->query('q', ''));
        $limit = max(1, min(30, (int) $request->query('limit', 20)));

        if (mb_strlen($term) < 2) {
            return response()->json(['results' => [], 'as_of' => now()->timezone('Asia/Jakarta')->toISOString()]);
        }

        $like = '%'.$term.'%';
        $user = $request->user();
        $query = Customer::query()
            ->with('area:id,code,name')
            ->select(['id', 'area_id', 'code', 'name', 'pppoe_user', 'odp_name', 'geo_lat', 'geo_long'])
            ->where(function ($customer) use ($like) {
                $customer->where('name', 'like', $like)
                    ->orWhere('code', 'like', $like)
                    ->orWhere('pppoe_user', 'like', $like)
                    ->orWhere('odp_name', 'like', $like);
            });
        AreaScope::applyToCustomers($query, $request->user());

        $results = $query->orderBy('name')->limit($limit)->get()->map(function (Customer $customer) use ($user): array {
            return [
                'id' => (int) $customer->id,
                'area_code' => $customer->area?->code,
                'area_name' => $customer->area?->name,
                'name' => $customer->name,
                'code' => $customer->code,
                'odp' => $customer->odp_name,
                'lat' => $customer->geo_lat !== null ? $this->customerMapCoordinate((float) $customer->geo_lat, $user) : null,
                'lng' => $customer->geo_long !== null ? $this->customerMapCoordinate((float) $customer->geo_long, $user) : null,
            ];
        })->values();

        return response()->json([
            'results' => $results,
            'truncated' => $results->count() >= $limit,
            'as_of' => now()->timezone('Asia/Jakarta')->toISOString(),
        ]);
    }

    private function buildPetaGeojson($disk, Area $dbArea, array $layers, bool $withCustomers, string $rwFilter, int $limit, ?array $bbox, ?User $user)
    {
        $includeAsset = fn (string $type): bool => in_array($type, $layers, true);

        $features = [];
        $stats = [
            'odp' => 0,
            'odc' => 0,
            'pole' => 0,
            'customer' => 0,
            'customer_total' => 0,
            'customer_truncated' => false,
            'cable_km' => 0,
        ];

        // Build KML path mapping
        $kmlPaths = [];
        $primary = "maps/{$dbArea->code}/doc.kml";
        if ($disk->exists($primary)) {
            $kmlPaths[] = $primary;
        }
        $areaFolderPatterns = [];
        $nameLower = strtolower($dbArea->name);
        if (str_contains($nameLower, 'randuagung')) {
            $areaFolderPatterns = ['randuagung', 'randugumo', 'randu-telu', 'kunci', 'rw-'];
        } elseif (str_contains($nameLower, 'mangliawan')) {
            $areaFolderPatterns = ['mangliawan'];
        } elseif (str_contains($nameLower, 'gadang')) {
            $areaFolderPatterns = ['gadang'];
        }
        $allKmlDirs = $disk->directories('maps');
        foreach ($allKmlDirs as $dir) {
            $folder = strtolower(basename($dir));
            foreach ($areaFolderPatterns as $pattern) {
                if (str_contains($folder, $pattern)) {
                    $kmlPath = "{$dir}/doc.kml";
                    if ($disk->exists($kmlPath) && ! in_array($kmlPath, $kmlPaths)) {
                        $kmlPaths[] = $kmlPath;
                    }
                    break;
                }
            }
        }

        $dbPoints = OdpOdc::where('area_id', $dbArea->id)->get()->keyBy(fn ($item) => strtolower(trim($item->name)));
        $matchedDbKeys = [];

        foreach ($kmlPaths as $kmlPath) {
            $kmlData = $this->kmlToGeoJson($disk->get($kmlPath));
            foreach ($kmlData['features'] as $feat) {
                $geomType = $feat['geometry']['type'] ?? '';
                $featName = $feat['properties']['name'] ?? '';
                $featNameLower = strtolower(trim($featName));

                if ($geomType === 'LineString') {
                    if ($includeAsset('CABLE')) {
                        $features[] = $feat;
                    }

                    continue;
                }

                if ($geomType === 'Point') {
                    $featNameUpper = strtoupper($featName);
                    $isOdp = str_starts_with($featNameUpper, 'ODP') || str_contains($featNameUpper, '_ODP_');
                    $isOdc = str_starts_with($featNameUpper, 'ODC') || str_starts_with($featNameUpper, 'POP') || str_contains($featNameUpper, '_ODC_');

                    $dbMatch = null;
                    $featCoords = $feat['geometry']['coordinates'] ?? null;
                    $expectedType = $isOdc ? 'ODC' : ($isOdp ? 'ODP' : null);

                    if (isset($dbPoints[$featNameLower])) {
                        $dbMatch = $dbPoints[$featNameLower];
                        $matchedDbKeys[] = $featNameLower;
                    } else {
                        $partialMatch = null;
                        $partialDist = PHP_FLOAT_MAX;
                        $partialMatchKey = null;
                        foreach ($dbPoints as $dbKey => $dbPoint) {
                            if ($expectedType && $dbPoint->type !== $expectedType) {
                                continue;
                            }
                            if (str_contains($featNameLower, $dbKey) || str_contains($dbKey, $featNameLower)) {
                                if ($featCoords && $dbPoint->lat && $dbPoint->lng) {
                                    $d = sqrt(pow($featCoords[1] - $dbPoint->lat, 2) + pow($featCoords[0] - $dbPoint->lng, 2));
                                    if ($d < $partialDist) {
                                        $partialDist = $d;
                                        $partialMatch = $dbPoint;
                                        $partialMatchKey = $dbKey;
                                    }
                                } else {
                                    $partialMatch = $dbPoint;
                                    $partialMatchKey = $dbKey;
                                    break;
                                }
                            }
                        }
                        if ($partialMatch) {
                            $dbMatch = $partialMatch;
                            $matchedDbKeys[] = $partialMatchKey;
                        } elseif ($featCoords && $expectedType) {
                            $nearestMatch = null;
                            $nearestDist = 0.0005;
                            $nearestKey = null;
                            foreach ($dbPoints as $dbKey => $dbPoint) {
                                if (! $dbPoint->lat || ! $dbPoint->lng) {
                                    continue;
                                }
                                if ($dbPoint->type !== $expectedType) {
                                    continue;
                                }
                                $d = sqrt(pow($featCoords[1] - $dbPoint->lat, 2) + pow($featCoords[0] - $dbPoint->lng, 2));
                                if ($d < $nearestDist) {
                                    $nearestDist = $d;
                                    $nearestMatch = $dbPoint;
                                    $nearestKey = $dbKey;
                                }
                            }
                            if ($nearestMatch) {
                                $dbMatch = $nearestMatch;
                                $matchedDbKeys[] = $nearestKey;
                            }
                        }
                    }

                    $props = $feat['properties'];
                    if ($dbMatch) {
                        $props['name'] = $dbMatch->name;
                        $props['type'] = $dbMatch->type;
                        if ($dbMatch->type === 'ODC') {
                            $props['olt_port'] = $dbMatch->olt_port;
                            $props['total_ports'] = $dbMatch->total_ports;
                            $props['active_ports'] = $dbMatch->active_ports;
                            $props['description'] = $dbMatch->description;
                        }
                    } else {
                        if ($isOdc) {
                            $props['type'] = 'ODC';
                            $props['olt_port'] = null;
                            $props['total_ports'] = null;
                            $props['active_ports'] = 0;
                        } elseif ($isOdp) {
                            $props['type'] = 'ODP';
                        } elseif (str_starts_with($featName, 'POLE')) {
                            $props['type'] = 'POLE';
                            $stats['pole']++;
                        } else {
                            $props['type'] = 'OTHER';
                        }
                    }

                    $feat['properties'] = $props;
                    if ($includeAsset((string) ($props['type'] ?? 'OTHER'))) {
                        $features[] = $feat;
                    }

                    if ($isOdp && $includeAsset('ODP')) {
                        $stats['odp']++;
                    } elseif ($isOdc && $includeAsset('ODC')) {
                        $stats['odc']++;
                    }
                }
            }
            if ($includeAsset('CABLE')) {
                $stats['cable_km'] += $kmlData['stats']['cable_km'] ?? 0;
            }
        }

        // Add DB-only points not matched in KML
        $odcCoords = [];
        foreach ($features as $f) {
            if (($f['properties']['type'] ?? '') === 'ODC' && ($f['geometry']['type'] ?? '') === 'Point') {
                $c = $f['geometry']['coordinates'];
                $odcCoords[] = round($c[1], 4).','.round($c[0], 4);
            }
        }
        foreach ($dbPoints as $dbKey => $point) {
            if (in_array($dbKey, $matchedDbKeys)) {
                continue;
            }
            if ($point->type === 'ODP') {
                $coordKey = round((float) $point->lat, 4).','.round((float) $point->lng, 4);
                if (in_array($coordKey, $odcCoords)) {
                    continue;
                }
            }
            $props = ['name' => $point->name, 'type' => $point->type];
            if ($point->type === 'ODC') {
                $props['olt_port'] = $point->olt_port;
                $props['total_ports'] = $point->total_ports;
                $props['active_ports'] = $point->active_ports;
                $props['description'] = $point->description;
            }
            if ($includeAsset((string) $point->type)) {
                $features[] = [
                    'type' => 'Feature',
                    'properties' => $props,
                    'geometry' => ['type' => 'Point', 'coordinates' => [(float) $point->lng, (float) $point->lat]],
                ];
            }
            if ($point->type === 'ODP' && $includeAsset('ODP')) {
                $stats['odp']++;
            } elseif ($point->type === 'ODC' && $includeAsset('ODC')) {
                $stats['odc']++;
            }
        }

        if ($withCustomers) {
            $customerQuery = Customer::query()
                ->select($this->mapCustomerColumns())
                ->with('olt:id,vendor')
                ->where('area_id', $dbArea->id)
                ->whereNotNull('geo_lat')
                ->whereNotNull('geo_long');

            if ($bbox) {
                $customerQuery->whereBetween('geo_long', [$bbox['west'], $bbox['east']])
                    ->whereBetween('geo_lat', [$bbox['south'], $bbox['north']]);
            }

            $stats['customer_total'] = (clone $customerQuery)->count();
            $stats['customer_truncated'] = $stats['customer_total'] > $limit;
            $customers = $customerQuery->orderByDesc('is_online')->orderBy('name')->limit($limit)->get();
            $connectivity = app(UnifiedConnectivityReadModel::class)->composeMany($customers);

            foreach ($customers->take($limit) as $customer) {
                if ($rwFilter !== '' && strcasecmp($this->customerRw($customer), $rwFilter) !== 0) {
                    continue;
                }

                $features[] = $this->customerFeature($customer, $user, $connectivity[(int) $customer->id] ?? null);
                $stats['customer']++;
            }
        }

        return [
            'type' => 'FeatureCollection',
            'features' => $features,
            'stats' => $stats,
        ];
    }

    private function parseBbox($bbox): ?array
    {
        if (! is_string($bbox) || $bbox === '') {
            return null;
        }

        $parts = array_map('floatval', explode(',', $bbox));
        if (count($parts) !== 4) {
            return null;
        }

        return ['west' => min($parts[0], $parts[2]), 'south' => min($parts[1], $parts[3]), 'east' => max($parts[0], $parts[2]), 'north' => max($parts[1], $parts[3])];
    }

    private function customerRw(Customer $customer): string
    {
        $text = strtoupper((string) $customer->address.' '.(string) $customer->odp_name);
        if (preg_match('/\bRW\.?\s*0*(\d{1,2})\b/', $text, $m)) {
            return 'RW '.$m[1];
        }

        return 'Tanpa RW';
    }

    /** @return list<string> */
    private function mapCustomerColumns(): array
    {
        return array_values(array_unique(array_merge(
            ['address', 'geo_lat', 'geo_long', 'odp_name'],
            UnifiedConnectivityReadModel::customerColumns(),
        )));
    }

    /**
     * @param  array<string, mixed>|null  $connectivity  compose() row
     */
    private function customerFeature(Customer $customer, ?User $user = null, ?array $connectivity = null): array
    {
        $rw = $this->customerRw($customer);
        $lastSnapshot = $customer->olt_last_synced_at ?? $customer->pppoe_online_checked_at ?? $customer->mikrotik_sync_checked_at;
        // Never fall back to the bare is_online flag: no compose row = unknown.
        $connectivityStatus = (string) ($connectivity['status'] ?? 'unknown');

        return [
            'type' => 'Feature',
            'properties' => [
                'type' => 'CUSTOMER',
                'name' => $customer->name,
                'code' => $customer->code,
                'odp' => $customer->odp_name,
                'rw' => $rw,
                'status' => $customer->status,
                'online' => $connectivityStatus === 'online',
                'connectivity_status' => $connectivityStatus,
                'connectivity_label' => $this->connectivityLabel($connectivityStatus),
                'connectivity_reason' => $connectivity['reason'] ?? 'Belum ada bukti konektivitas.',
                'olt_status' => $customer->olt_status,
                'onu_rx_power_dbm' => $customer->onu_rx_power_dbm,
                'last_snapshot_at' => $lastSnapshot ? $lastSnapshot->timezone('Asia/Jakarta')->format('d/m/Y H:i').' WIB' : '-',
            ],
            'geometry' => ['type' => 'Point', 'coordinates' => [
                $this->customerMapCoordinate((float) $customer->geo_long, $user),
                $this->customerMapCoordinate((float) $customer->geo_lat, $user),
            ]],
        ];
    }

    private function connectivityLabel(string $status): string
    {
        return match ($status) {
            'online' => 'Online',
            'offline' => 'Offline',
            'isolated' => 'Isolir',
            'degraded' => 'Sebagian (degraded)',
            'setup_required' => 'Tidak diketahui (mapping OLT/ONU belum ada)',
            default => 'Tidak diketahui (data basi)',
        };
    }

    private function canViewPreciseCustomerCoordinates(?User $user): bool
    {
        return $user?->isAdminLike() ?? false;
    }

    private function customerMapCoordinate(float $coordinate, ?User $user): float
    {
        return $this->canViewPreciseCustomerCoordinates($user) ? $coordinate : round($coordinate, 3);
    }

    public function kml(string $area)
    {
        $disk = Storage::disk(config('filesystems.default'));
        $path = "maps/{$area}/doc.kml";
        if (! $disk->exists($path)) {
            abort(404);
        }

        return response($disk->get($path), 200, [
            'Content-Type' => 'application/vnd.google-earth.kml+xml',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    private function kmlToGeoJson(string $kml): array
    {
        $xml = simplexml_load_string($kml);
        $ns = 'http://www.opengis.net/kml/2.2';
        $xml->registerXPathNamespace('kml', $ns);
        $xml->registerXPathNamespace('gx', 'http://www.google.com/kml/ext/2.2');

        $features = [];
        $stats = ['odp' => 0, 'odc' => 0, 'pole' => 0, 'other_point' => 0, 'cable_km' => 0];

        $placemarks = $xml->xpath('//kml:Placemark');
        foreach ($placemarks as $pm) {
            $name = (string) $pm->name;
            $desc = (string) $pm->description;
            $styleUrl = (string) $pm->styleUrl;

            $point = $pm->children($ns)->Point;
            if ($point) {
                $coords = trim((string) $point->coordinates);
                $parts = explode(',', $coords);
                if (count($parts) >= 2) {
                    $features[] = [
                        'type' => 'Feature',
                        'properties' => [
                            'name' => $name,
                            'description' => strip_tags($desc),
                            'styleUrl' => $styleUrl,
                        ],
                        'geometry' => [
                            'type' => 'Point',
                            'coordinates' => [(float) $parts[0], (float) $parts[1]],
                        ],
                    ];
                    $nameUpper = strtoupper($name);
                    if (str_starts_with($nameUpper, 'ODP') || str_contains($nameUpper, '_ODP_')) {
                        $stats['odp']++;
                    } elseif (str_starts_with($nameUpper, 'ODC') || str_starts_with($nameUpper, 'POP') || str_contains($nameUpper, '_ODC_')) {
                        $stats['odc']++;
                    } elseif (str_starts_with($nameUpper, 'POLE') || str_starts_with($nameUpper, 'MARKER')) {
                        $stats['pole']++;
                    } else {
                        $stats['other_point']++;
                    }
                }

                continue;
            }

            $line = $pm->children($ns)->LineString;
            if ($line) {
                $coordStr = trim((string) $line->coordinates);
                $coords = [];
                $totalDist = 0;
                $prevLat = null;
                $prevLon = null;
                foreach (preg_split('/\s+/', $coordStr) as $c) {
                    if (empty(trim($c))) {
                        continue;
                    }
                    $parts = explode(',', trim($c));
                    if (count($parts) >= 2) {
                        $lon = (float) $parts[0];
                        $lat = (float) $parts[1];
                        if ($prevLat !== null) {
                            $totalDist += $this->haversine($prevLat, $prevLon, $lat, $lon);
                        }
                        $prevLat = $lat;
                        $prevLon = $lon;
                        $coords[] = [$lon, $lat];
                    }
                }
                if (count($coords) > 0) {
                    $features[] = [
                        'type' => 'Feature',
                        'properties' => [
                            'name' => $name,
                            'description' => strip_tags($desc),
                            'styleUrl' => $styleUrl,
                            'cable_km' => round($totalDist, 3),
                        ],
                        'geometry' => [
                            'type' => 'LineString',
                            'coordinates' => $coords,
                        ],
                    ];
                    $stats['cable_km'] += $totalDist;
                }
            }
        }

        return [
            'type' => 'FeatureCollection',
            'features' => $features,
            'stats' => [
                'odp' => $stats['odp'],
                'odc' => $stats['odc'],
                'pole' => $stats['pole'],
                'other_point' => $stats['other_point'],
                'cable_km' => round($stats['cable_km'], 2),
            ],
        ];
    }

    private function haversine(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return 2 * $r * asin(sqrt($a));
    }
}
