<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerMigration;
use App\Models\Package;
use App\Models\Router;
use App\Services\CustomerMigrationService;
use App\Services\MikrotikService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Krian keeps the customer's package. When that package has no MikroTik
 * profile (e.g. "Paket Free") the target profile must come from the live
 * PPPoE secret on the source router, not from a possibly stale
 * customers.mikrotik_profile (migration #1123 looked for a deleted "10MB").
 * Fake RouterOS adapter only; no device is contacted.
 */
#[Group('blocking-psb')]
class CustomerMigrationKrianC300PackageProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        parent::tearDown();

        Mockery::close();
    }

    public function test_package_without_profile_uses_live_source_secret_profile(): void
    {
        $router = $this->router(15, 'Krian');
        $package = $this->freePackage();
        $customer = $this->customer($package, '10MB');
        $this->fakeRouterOs(fn ($mock) => $mock->shouldReceive('getPPPSecret')
            ->with($customer->pppoe_user)
            ->andReturn(['name' => $customer->pppoe_user, 'profile' => '100MB']));

        $secret = $this->invoke('verifyExistingPppoeSecret', $customer, $router);
        $this->assertSame(['found' => true, 'profile' => '100MB'], $secret);

        $contract = $this->targetContract($router, $customer, $secret);

        $this->assertTrue($contract['preserve_package']);
        $this->assertSame('100MB', $contract['profile']);
        $this->assertSame('100MB', $contract['mikrotik_profile']);
        $this->assertSame('live_pppoe_secret', $contract['profile_source']);
        $this->assertSame($package->id, $contract['package_id']);
        $this->assertNull($contract['package_blocker']);
        $this->assertNull($contract['profile_warning']);
    }

    public function test_router_read_failure_falls_back_to_customer_column_with_warning(): void
    {
        $router = $this->router(15, 'Krian');
        $package = $this->freePackage();
        $customer = $this->customer($package, '10MB');
        $this->fakeRouterOs(fn ($mock) => $mock->shouldReceive('getPPPSecret')
            ->andThrow(new \RuntimeException('Connection timed out')));

        $secret = $this->invoke('verifyExistingPppoeSecret', $customer, $router);
        $this->assertNull($secret['found']);

        $contract = $this->targetContract($router, $customer, $secret);

        $this->assertSame('10MB', $contract['mikrotik_profile']);
        $this->assertSame('customer_column_fallback', $contract['profile_source']);
        $this->assertNull($contract['package_blocker']);
        $this->assertStringContainsString('package_profile_fallback_customer_column', (string) $contract['profile_warning']);
    }

    public function test_no_profile_anywhere_blocks_with_package_profile_missing(): void
    {
        $router = $this->router(15, 'Krian');
        $package = $this->freePackage();
        $customer = $this->customer($package, null);
        $this->fakeRouterOs(fn ($mock) => $mock->shouldReceive('getPPPSecret')
            ->andThrow(new \RuntimeException('Connection timed out')));

        $secret = $this->invoke('verifyExistingPppoeSecret', $customer, $router);
        $contract = $this->targetContract($router, $customer, $secret);

        $this->assertSame('', $contract['profile']);
        $this->assertSame('package_profile_missing:package_'.$package->id, $contract['package_blocker']);
        $this->assertSame(
            ' — Paket tidak punya profil MikroTik — pilih paket atau isi profil.',
            $this->invoke('migrationReasonHint', [$contract['package_blocker']])
        );
    }

    public function test_package_with_profile_is_unchanged_even_when_live_secret_differs(): void
    {
        $router = $this->router(15, 'Krian');
        $package = Package::create([
            'code' => '50MB_15',
            'name' => 'Paket 50MB',
            'mikrotik_profile' => '50MB',
            'price' => 150000,
            'router_id' => $router->id,
            'is_active' => true,
        ]);
        $customer = $this->customer($package, '10MB');

        $contract = $this->targetContract($router, $customer, ['found' => true, 'profile' => '100MB']);

        $this->assertSame('50MB', $contract['mikrotik_profile']);
        $this->assertSame('package', $contract['profile_source']);
        $this->assertNull($contract['package_blocker']);
        $this->assertNull($contract['profile_warning']);
    }

    private function fakeRouterOs(callable $secretExpectation): void
    {
        $mock = Mockery::mock(MikrotikService::class);
        $mock->shouldReceive('connect')->andReturnSelf();
        $secretExpectation($mock);
        $mock->shouldReceive('disconnect')->andReturnNull();
        $this->app->instance(MikrotikService::class, $mock);
    }

    private function targetContract(Router $router, Customer $customer, array $secret): array
    {
        $migration = new CustomerMigration([
            'full_name' => $customer->name,
            'router_id' => $router->id,
        ]);
        $migration->exists = true;

        return $this->invoke('migrationTargetContract', $migration, [
            'customer_before' => $this->invoke('customerSnapshot', $customer),
            'router' => ['id' => $router->id, 'name' => $router->name],
            'mikrotik_secret' => $secret,
        ]);
    }

    private function freePackage(): Package
    {
        return Package::create([
            'code' => 'FREE',
            'name' => 'Paket Free',
            'price' => 0,
        ]);
    }

    private function router(int $id, string $name): Router
    {
        $router = new Router([
            'name' => $name,
            'ip_address' => '10.150.6.'.($id % 250 + 1),
            'username' => 'test',
            'password' => 'canary-test',
            'is_active' => true,
        ]);
        $router->id = $id;
        $router->save();

        return $router;
    }

    private function customer(Package $package, ?string $profile): Customer
    {
        return Customer::create([
            'code' => 'CUST-'.strtoupper(substr(uniqid(), -6)),
            'name' => 'Pelanggan Krian',
            'phone' => '081234567890',
            'address' => 'Krian',
            'pppoe_user' => 'USERPAK_RT30_RW01',
            'package_id' => $package->id,
            'mikrotik_profile' => $profile,
            'router_id' => 15,
            'status' => 'active',
        ]);
    }

    private function invoke(string $method, mixed ...$args): mixed
    {
        $service = app(CustomerMigrationService::class);

        return (new ReflectionMethod($service, $method))->invoke($service, ...$args);
    }
}
