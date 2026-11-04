<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Package;
use App\Models\Router;
use App\Services\LegacySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\Canary;
use Tests\TestCase;

class LegacySyncAreaResolutionStatsTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_sync_records_area_resolution_source_counts(): void
    {
        Http::fake([
            '*/api/v1/customers' => Http::response([
                [
                    'id' => 'API001',
                    'name' => 'Api Area Customer',
                    'phone' => '0800',
                    'address' => 'Address',
                    'pppoe_user' => 'api-area-user',
                    'status' => 'active',
                    'join_date' => '2026-01-01',
                    'due_day' => 20,
                    'is_online' => false,
                    'geo_lat' => null,
                    'geo_long' => null,
                    'ktp_photo_url' => null,
                    'area' => ['name' => 'SUBNET-WAJAK'],
                    'package' => ['name' => 'Package A'],
                ],
                [
                    'id' => 'RDG001',
                    'name' => 'Fallback Customer',
                    'phone' => '0801',
                    'address' => 'Address',
                    'pppoe_user' => 'fallback-user',
                    'status' => 'active',
                    'join_date' => '2026-01-01',
                    'due_day' => 20,
                    'is_online' => false,
                    'geo_lat' => null,
                    'geo_long' => null,
                    'ktp_photo_url' => null,
                    'area' => ['name' => ''],
                    'package' => ['name' => 'Package A'],
                ],
            ]),
        ]);

        $service = app(LegacySyncService::class);

        $this->assertSame(2, $service->syncCustomers());

        $this->assertSame([
            'api_area' => 1,
            'legacy_location' => 0,
            'prefix' => 1,
            'package_keyword' => 0,
            'address_keyword' => 0,
            'unmapped' => 0,
        ], $service->lastCustomerAreaResolutionStats());

        $this->assertSame('SUBNET-WAJAK', Customer::where('code', 'API001')->first()->area->name);
        $this->assertSame('SKYNET-RANDUAGUNG', Customer::where('code', 'RDG001')->first()->area->name);
    }

    public function test_customer_sync_uses_mikrotik_classification_and_preserves_blank_pppoe(): void
    {
        $package = Package::create([
            'name' => 'Package A',
            'code' => 'PKG-A',
            'price' => 100000,
        ]);
        $router = Router::create([
            'name' => 'Arjosari',
            'ip_address' => '198.18.2.9',
            'port' => 8728,
            'username' => 'admin',
            'password' => Canary::of('pw'),
            'is_active' => true,
        ]);
        Customer::create([
            'code' => 'BLANK001',
            'name' => 'Existing Blank PPPoE Source',
            'phone' => '0800',
            'address' => 'Address',
            'pppoe_user' => 'EXISTING_USER',
            'package_id' => $package->id,
            'status' => 'active',
        ]);

        Http::fake([
            '*/api/v1/customers' => Http::response([
                [
                    'id' => 'ARJ1002',
                    'code' => 'ARJ1002',
                    'name' => 'Syncable Customer',
                    'phone' => '0801',
                    'address' => 'Address',
                    'pppoe_user' => 'BUDIB_RT6_RW1',
                    'router_name' => 'Skynet Arjosari',
                    'source' => 'warga',
                    'is_mikrotik_syncable' => true,
                    'status' => 'active',
                    'join_date' => '2026-01-01',
                    'due_day' => 20,
                    'is_online' => false,
                    'geo_lat' => null,
                    'geo_long' => null,
                    'ktp_photo_url' => null,
                    'area' => ['name' => 'SKYNET-ARJOSARI'],
                    'package' => ['name' => 'Package A'],
                ],
                [
                    'id' => 'BLANK001',
                    'code' => 'BLANK001',
                    'name' => 'Blank PPPoE Customer',
                    'phone' => '0802',
                    'address' => 'Address',
                    'pppoe_user' => '',
                    'router_name' => '',
                    'source' => 'stale',
                    'is_mikrotik_syncable' => false,
                    'status' => 'isolated',
                    'join_date' => '2026-01-01',
                    'due_day' => 20,
                    'is_online' => false,
                    'geo_lat' => null,
                    'geo_long' => null,
                    'ktp_photo_url' => null,
                    'area' => ['name' => 'SUBNET-WAJAK'],
                    'package' => ['name' => 'Package A'],
                ],
            ]),
        ]);

        $service = app(LegacySyncService::class);

        $this->assertSame(2, $service->syncCustomers());

        $syncable = Customer::where('code', 'ARJ1002')->first();
        $preserved = Customer::where('code', 'BLANK001')->first();

        $this->assertSame('BUDIB_RT6_RW1', $syncable->pppoe_user);
        $this->assertSame($router->id, $syncable->router_id);
        $this->assertSame('EXISTING_USER', $preserved->pppoe_user);
        $this->assertNull($preserved->router_id);
        $this->assertSame(1, $service->lastCustomerNetworkSyncStats()['mikrotik_syncable']);
        $this->assertSame(1, $service->lastCustomerNetworkSyncStats()['not_mikrotik_syncable']);
        $this->assertSame(1, $service->lastCustomerNetworkSyncStats()['pppoe_from_scraper']);
        $this->assertSame(1, $service->lastCustomerNetworkSyncStats()['pppoe_preserved_existing']);
        $this->assertSame(1, $service->lastCustomerNetworkSyncStats()['router_mapped']);
    }
}
