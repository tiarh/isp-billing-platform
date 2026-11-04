<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Package;
use App\Models\Router;
use App\Services\MikrotikService;
use App\Services\RouterSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Support\Canary;
use Tests\TestCase;

class PackageProfileSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_mikrotik_profile_does_not_overwrite_ebilling_package()
    {
        try {
            // 1. Setup Dummy Data
            // Router
            $router = Router::create([
                'name' => 'Test Router '.uniqid(),
                'ip_address' => '198.18.2.'.rand(1, 254),
                'username' => 'admin', // Added required field
                'is_active' => true,
                'password' => Canary::of('pw'),
                'port' => 8728, // Ensure port is set just in case
            ]);
        } catch (\Exception $e) {
            $this->fail('Router creation failed: '.$e->getMessage());
        }

        // Target Package (The mapped one)
        // Name is "Marketing Name", mikrotik_profile is "Technical Name"
        $goldPackage = Package::create([
            'name' => 'Paket Sultan 100M',
            'code' => 'PKG-GOLD-'.uniqid(),
            'mikrotik_profile' => 'GOLD_PROFILE',
            'price' => 500000,
            'bandwidth_label' => '100 Mbps',
        ]);

        // Other Package (Should NOT be picked)
        $silverPackage = Package::create([
            'name' => 'Paket Rakyat',
            'code' => 'PKG-SILVER-'.uniqid(),
            'mikrotik_profile' => 'SILVER_PROFILE',
            'price' => 100000,
            'bandwidth_label' => '10 Mbps',
        ]);

        // Customer (Initially has no package or wrong package)
        $customer = Customer::create([
            'code' => 'CUST-'.uniqid(),
            'name' => 'John Doe',
            'phone' => '080000000001',
            'pppoe_user' => 'john.doe',
            'status' => 'active',
            'package_id' => $silverPackage->id, // Currently on Silver
            'address' => '123 Fake St',
            'router_id' => $router->id,
        ]);

        // 2. Mock Mikrotik Service
        // We simulate the Router saying: "john.doe is using profile 'GOLD_PROFILE'"
        $mikrotikMock = Mockery::mock(MikrotikService::class);
        $mikrotikMock->shouldReceive('connect')->once();
        $mikrotikMock->shouldReceive('disconnect')->once();
        $mikrotikMock->shouldReceive('getPPPSecrets')->once()->andReturn([
            [
                'name' => 'john.doe',
                'profile' => 'GOLD_PROFILE', // <--- The match
                'service' => 'pppoe',
            ],
        ]);

        // 3. Run Sync Service
        $service = new RouterSyncService($mikrotikMock);
        $result = $service->syncCustomers($router);

        $customer->refresh();

        $this->assertEquals(
            $silverPackage->id,
            $customer->package_id,
            'Customer package must remain owned by eBilling, not MikroTik profile sync'
        );

        $this->assertEquals('GOLD_PROFILE', $customer->mikrotik_profile);
        $this->assertEquals(0, $result['synced_package']);
    }
}
