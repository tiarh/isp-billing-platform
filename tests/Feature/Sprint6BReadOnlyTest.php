<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Customer;
use App\Models\OdpOdc;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class Sprint6BReadOnlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_sidebar_uses_short_labels_and_hides_customer_portal(): void
    {
        $source = file_get_contents(resource_path('js/Components/AppSidebar.tsx'));

        $this->assertStringNotContainsString('Portal Pelanggan', $source);
        $this->assertStringNotContainsString('PSB & WA Automation', $source);
        $this->assertStringContainsString("name: 'PSB WA'", $source);
        $this->assertStringContainsString("name: 'Mapping'", $source);

        preg_match_all("/route:\\s*'([^']+)'/", $source, $matches);
        $routes = array_unique($matches[1] ?? []);
        $this->assertNotEmpty($routes);

        foreach ($routes as $route) {
            $this->assertTrue(Route::has($route), "Sidebar route [{$route}] harus terdaftar.");
        }
    }

    public function test_mapping_customer_layer_reads_customer_snapshot_without_device_query(): void
    {
        $area = Area::create(['code' => 'RA', 'name' => 'Randuagung']);
        $package = Package::create(['code' => 'MAP', 'name' => 'Map Test', 'price' => 100000]);
        Customer::create([
            'code' => 'MAP-001',
            'name' => 'Pelanggan Map',
            'address' => 'RW 4 Randuagung',
            'phone' => '081',
            'pppoe_user' => 'map001',
            'package_id' => $package->id,
            'area_id' => $area->id,
            'geo_lat' => -7.9,
            'geo_long' => 112.6,
            'status' => 'isolated',
            'is_online' => true,
            'odp_name' => 'ODP RW4',
            'olt_status' => 'online',
            'onu_rx_power_dbm' => -24.5,
            'olt_last_synced_at' => now(),
        ]);

        $user = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($user)->getJson(route('api.peta.geojson', [
            'area' => 'RA',
            'layers' => 'CUSTOMER',
            'rw' => 'RW 4',
            'limit' => 10,
        ]));

        $response->assertOk()
            ->assertJsonPath('stats.customer', 1)
            ->assertJsonPath('features.0.properties.type', 'CUSTOMER')
            ->assertJsonPath('features.0.properties.connectivity_status', 'isolated')
            ->assertJsonPath('features.0.properties.online', false)
            ->assertJsonPath('features.0.properties.status', 'isolated')
            ->assertJsonPath('features.0.properties.onu_rx_power_dbm', '-24.50');
    }

    public function test_inventory_import_preview_is_dry_run_and_detects_duplicate(): void
    {
        $area = Area::create(['code' => 'RA', 'name' => 'Randuagung']);
        OdpOdc::create([
            'area_id' => $area->id,
            'name' => 'ODP DUP',
            'type' => 'ODP',
            'lat' => -7.9,
            'lng' => 112.6,
        ]);
        $admin = User::factory()->create(['role' => 'admin']);
        $csv = "name,area,type,lat,lng,total_ports\nODP DUP,Randuagung,ODP,-7.9,112.6,8\nODP BARU,Randuagung,ODP,-7.91,112.61,8\n";

        $response = $this->actingAs($admin)->postJson(route('inventaris.import-preview'), [
            'file' => UploadedFile::fake()->createWithContent('inventaris.csv', $csv),
            'type' => 'auto',
        ]);

        $response->assertOk()
            ->assertJsonPath('mode', 'dry_run')
            ->assertJsonPath('summary.total', 2)
            ->assertJsonPath('summary.duplicates', 1)
            ->assertJsonPath('summary.ready', 1);

        $this->assertSame(1, OdpOdc::count());
    }
}
