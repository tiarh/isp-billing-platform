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

class RouterScopedPackageSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_router_scan_preserves_ebilling_package_even_when_router_profile_matches_other_package()
    {
        // 1. Setup Routers
        $routerA = Router::create([
            'name' => 'Router A '.uniqid(),
            'ip_address' => '198.18.2.'.rand(1, 100),
            'port' => 8728,
            'username' => 'admin',
            'password' => Canary::of('pw'),
            'is_active' => true,
        ]);

        $routerB = Router::create([
            'name' => 'Router B '.uniqid(),
            'ip_address' => '198.18.2.'.rand(101, 200),
            'port' => 8728,
            'username' => 'admin',
            'password' => Canary::of('pw'),
            'is_active' => true,
        ]);

        // 2. Setup Packages
        // Both Routers use technical profile "10MB"
        // Package for Router A
        $pkgScopeA = Package::create([
            'name' => 'Paket A 10M',
            'code' => 'PKG-A-'.uniqid(),
            'mikrotik_profile' => '10MB',
            'router_id' => $routerA->id,
            'price' => 100000,
        ]);

        // Package for Router B
        $pkgScopeB = Package::create([
            'name' => 'Paket B 10M',
            'code' => 'PKG-B-'.uniqid(),
            'mikrotik_profile' => '10MB',
            'router_id' => $routerB->id,
            'price' => 120000,
        ]);

        // Global Package (Scenario: Should be ignored if scoped exists)
        $pkgGlobal = Package::create([
            'name' => 'Paket Global 10M',
            'code' => 'PKG-G-'.uniqid(),
            'mikrotik_profile' => '10MB',
            'router_id' => null,
            'price' => 90000,
        ]);

        // 3. Setup Customer on Router A
        // Initially on Global Package (or wrong one)
        $customer = Customer::create([
            'code' => 'CUST-'.uniqid(),
            'name' => 'User on Router A',
            'phone' => '08123456789',
            'address' => 'Test Address',
            'pppoe_user' => 'user.a',
            'router_id' => $routerA->id,
            'package_id' => $pkgGlobal->id, // Wrong package initially
            'status' => 'active',
            'join_date' => now(),
        ]);

        // 4. Mock Mikrotik Service for Router A
        $mikrotikMock = Mockery::mock(MikrotikService::class);
        $mikrotikMock->shouldReceive('connect')->once();
        $mikrotikMock->shouldReceive('disconnect')->once();
        $mikrotikMock->shouldReceive('getPPPSecrets')->once()->andReturn([
            [
                'name' => 'user.a',
                'profile' => '10MB', // Technical Profile
                'service' => 'pppoe',
            ],
        ]);

        // 5. Run Sync
        $service = new RouterSyncService($mikrotikMock);
        $service->syncCustomers($routerA);

        // 6. Assertions
        $customer->refresh();

        $this->assertNotEquals(
            $pkgScopeB->id,
            $customer->package_id,
            'Customer on Router A should NOT be mapped to Router B package.'
        );

        $this->assertEquals(
            $pkgGlobal->id,
            $customer->package_id,
            'Customer package should remain the eBilling package.'
        );

        $this->assertEquals('10MB', $customer->mikrotik_profile);
    }
}
