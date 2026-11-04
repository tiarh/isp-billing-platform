<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Router;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PackageActivationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_activate_a_valid_router_package(): void
    {
        $router = Router::create(['name' => 'SKYNET-GADANG', 'ip_address' => '192.0.2.1', 'username' => 'test', 'password' => 'test-only-router-password', 'is_active' => true]);
        $package = Package::create([
            'code' => 'GADANG-50MB', 'name' => 'Paket 50MB GADANG', 'price' => 115000,
            'mikrotik_profile' => '50MB', 'router_id' => $router->id, 'is_active' => false,
        ]);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->put(route('packages.update', $package), [
                'name' => $package->name,
                'price' => 115000,
                'mikrotik_profile' => '50MB',
                'router_id' => $router->id,
                'is_active' => true,
            ])->assertRedirect();

        $this->assertTrue((bool) $package->refresh()->is_active);
    }

    public function test_activation_rejects_zero_price_and_duplicate_profile(): void
    {
        $router = Router::create(['name' => 'Router Test', 'ip_address' => '192.0.2.2', 'username' => 'test', 'password' => 'test-only-router-password', 'is_active' => true]);
        $package = Package::create([
            'code' => 'TEST-100M', 'name' => 'Paket 100M', 'price' => 0,
            'mikrotik_profile' => '100M', 'router_id' => $router->id, 'is_active' => false,
        ]);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->put(route('packages.update', $package), [
            'name' => $package->name, 'price' => 0, 'mikrotik_profile' => '100M',
            'router_id' => $router->id, 'is_active' => true,
        ])->assertSessionHasErrors('price');

        $package->update(['price' => 100000]);
        Package::create([
            'code' => 'TEST-100M-2', 'name' => 'Paket 100M Duplikat', 'price' => 100000,
            'mikrotik_profile' => '100M', 'router_id' => $router->id, 'is_active' => true,
        ]);

        $this->actingAs($admin)->put(route('packages.update', $package), [
            'name' => $package->name, 'price' => 100000, 'mikrotik_profile' => '100M',
            'router_id' => $router->id, 'is_active' => true,
        ])->assertSessionHasErrors('mikrotik_profile');
    }
}
