<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Customer;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MapViewportClusterTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_map_endpoint_clusters_and_preserves_total_count(): void
    {
        $area = Area::create(['code' => 'CLUSTER', 'name' => 'Cluster Area']);
        $package = Package::create(['code' => 'CLUSTER-PKG', 'name' => 'Cluster package', 'price' => 0]);
        foreach (range(1, 3) as $id) {
            Customer::create([
                'code' => 'CLUSTER-'.$id,
                'name' => 'Cluster customer '.$id,
                'address' => 'RW 4',
                'phone' => '08'.$id,
                'package_id' => $package->id,
                'pppoe_user' => 'cluster-customer-'.$id,
                'area_id' => $area->id,
                'geo_lat' => -6.9000 + ($id / 100000),
                'geo_long' => 111.6000 + ($id / 100000),
                'status' => 'active',
                'is_online' => $id !== 3,
            ]);
        }

        $user = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($user)->getJson(route('api.peta.customers', [
            'area' => 'CLUSTER',
            'zoom' => 10,
            'limit' => 100,
        ]));

        $response->assertOk()
            ->assertHeader('ETag')
            ->assertJsonPath('stats.customer_total', 3)
            ->assertJsonPath('stats.customer_visible', 1)
            ->assertJsonPath('stats.customer_clustered', 1)
            ->assertJsonPath('features.0.properties.type', 'CUSTOMER_CLUSTER')
            ->assertJsonPath('features.0.properties.count', 3);

        $cacheControl = collect(explode(',', (string) $response->headers->get('Cache-Control')))
            ->map(fn (string $directive): string => trim($directive))
            ->sort()
            ->values()
            ->all();

        $this->assertSame([
            'max-age=60',
            'private',
            'stale-while-revalidate=30',
        ], $cacheControl);

        $this->actingAs($user)->withHeaders([
            'If-None-Match' => $response->headers->get('ETag'),
        ])->getJson(route('api.peta.customers', ['area' => 'CLUSTER', 'zoom' => 10, 'limit' => 100]))
            ->assertStatus(304);
    }

    public function test_customer_search_is_server_side_and_area_scoped(): void
    {
        $area = Area::create(['code' => 'SEARCH', 'name' => 'Search Area']);
        $package = Package::create(['code' => 'SEARCH-PKG', 'name' => 'Search package', 'price' => 0]);
        Customer::create([
            'code' => 'SEARCH-1',
            'name' => 'Search customer',
            'address' => 'RW 4',
            'phone' => '0800000001',
            'package_id' => $package->id,
            'pppoe_user' => 'search-customer',
            'area_id' => $area->id,
            'geo_lat' => -6.9,
            'geo_long' => 111.6,
            'status' => 'active',
        ]);

        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->getJson(route('api.peta.customer-search', ['q' => 'search-customer']))
            ->assertOk()
            ->assertJsonPath('results.0.code', 'SEARCH-1')
            ->assertJsonPath('results.0.area_code', 'SEARCH');
    }

    public function test_technician_customer_coordinates_are_rounded_and_cache_is_role_scoped(): void
    {
        $area = Area::create(['code' => 'TECH-MAP', 'name' => 'Technician map area']);
        $package = Package::create(['code' => 'TECH-MAP-PKG', 'name' => 'Technician map package', 'price' => 0]);
        Customer::create([
            'code' => 'TECH-MAP-1',
            'name' => 'Technician map customer',
            'address' => 'RW 4',
            'phone' => '0800000002',
            'package_id' => $package->id,
            'pppoe_user' => 'technician-map-customer',
            'area_id' => $area->id,
            'geo_lat' => -6.901234,
            'geo_long' => 111.601987,
            'status' => 'active',
        ]);

        $technician = User::factory()->create(['role' => 'teknisi']);
        $this->actingAs($technician)->getJson(route('api.peta.customers', [
            'area' => 'TECH-MAP',
            'zoom' => 14,
            'limit' => 100,
        ]))
            ->assertOk()
            ->assertJsonPath('features.0.geometry.coordinates.0', 111.602)
            ->assertJsonPath('features.0.geometry.coordinates.1', -6.901);

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->getJson(route('api.peta.customers', [
            'area' => 'TECH-MAP',
            'zoom' => 14,
            'limit' => 100,
        ]))
            ->assertOk()
            ->assertJsonPath('features.0.geometry.coordinates.0', 111.601987)
            ->assertJsonPath('features.0.geometry.coordinates.1', -6.901234);
    }

    public function test_technician_customer_search_and_geojson_coordinates_are_rounded(): void
    {
        $area = Area::create(['code' => 'TECH-SEARCH', 'name' => 'Technician search area']);
        $package = Package::create(['code' => 'TECH-SEARCH-PKG', 'name' => 'Technician search package', 'price' => 0]);
        Customer::create([
            'code' => 'TECH-SEARCH-1',
            'name' => 'Technician search customer',
            'address' => 'RW 5',
            'phone' => '0800000003',
            'package_id' => $package->id,
            'pppoe_user' => 'technician-search-customer',
            'area_id' => $area->id,
            'geo_lat' => -6.902345,
            'geo_long' => 111.602876,
            'status' => 'active',
        ]);

        $technician = User::factory()->create(['role' => 'teknisi']);
        $this->actingAs($technician)->getJson(route('api.peta.customer-search', ['q' => 'technician-search-customer']))
            ->assertOk()
            ->assertJsonPath('results.0.lat', -6.902)
            ->assertJsonPath('results.0.lng', 111.603);

        $this->actingAs($technician)->getJson(route('api.peta.geojson', [
            'area' => 'TECH-SEARCH',
            'layers' => 'CUSTOMER',
            'limit' => 100,
        ]))
            ->assertOk()
            ->assertJsonPath('features.0.geometry.coordinates.0', 111.603)
            ->assertJsonPath('features.0.geometry.coordinates.1', -6.902);
    }
}
