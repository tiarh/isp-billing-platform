<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Package;
use App\Models\Router;
use App\Models\RouterStagedCustomer;
use App\Services\MikrotikService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Support\Canary;
use Tests\TestCase;

class AuditRouterCustomersTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_reports_live_mikrotik_vs_ebilling_statistics_without_mutating_data(): void
    {
        $package = $this->package();
        $routerA = $this->router('Audit A', '198.18.2.1');
        $routerB = $this->router('Audit B', '198.18.2.2');

        $matched = $this->customer($package, 'CUST-MATCH', 'matched.user', ['router_id' => $routerA->id]);
        $assignedMissing = $this->customer($package, 'CUST-MISSING', 'missing.user', ['router_id' => $routerA->id]);
        $globalOnly = $this->customer($package, 'CUST-ONLY', 'global.only');
        $this->customer($package, 'CUST-BLANK', '');
        $softDeleted = $this->customer($package, 'CUST-SOFT', 'soft.user');
        $softDeleted->delete();

        RouterStagedCustomer::create([
            'router_id' => $routerA->id,
            'pppoe_user' => 'staged.user',
            'status' => 'unmatched',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $mikrotik = Mockery::mock(MikrotikService::class);
        $mikrotik->shouldReceive('connect')->withArgs(fn ($router) => $router->id === $routerA->id)->once()->andReturnSelf();
        $mikrotik->shouldReceive('getPPPSecrets')->once()->andReturn([
            ['name' => 'matched.user', 'disabled' => 'false'],
            ['name' => 'router.only', 'disabled' => 'false'],
            ['name' => 'disabled.user', 'disabled' => 'true'],
            ['name' => 'soft.user', 'disabled' => 'false'],
        ]);
        $mikrotik->shouldReceive('disconnect')->twice();
        $mikrotik->shouldReceive('connect')->withArgs(fn ($router) => $router->id === $routerB->id)->once()->andReturnSelf();
        $mikrotik->shouldReceive('getPPPSecrets')->once()->andReturn([
            ['name' => 'duplicate.router', 'disabled' => 'false'],
            ['name' => 'duplicate.router', 'disabled' => 'false'],
        ]);
        $this->app->instance(MikrotikService::class, $mikrotik);

        $this->artisan('routers:audit-customers')
            ->expectsOutput('Auditing Audit A (198.18.2.1)...')
            ->expectsOutput('Auditing Audit B (198.18.2.2)...')
            ->expectsTable(['Metric', 'Count'], [
                ['Routers audited', 2],
                ['Routers failed', 0],
                ['eBilling customers total', 4],
                ['eBilling with PPPoE', 3],
                ['eBilling without PPPoE', 1],
                ['MikroTik PPPoE total', 6],
                ['MikroTik enabled total', 5],
                ['MikroTik disabled total', 1],
                ['Synced/matched total', 1],
                ['MikroTik-only total', 4],
                ['eBilling-only total', 2],
                ['Soft-deleted matches', 1],
            ])
            ->assertExitCode(0);

        $this->assertDatabaseHas('customers', [
            'id' => $matched->id,
            'mikrotik_sync_status' => 'unknown',
            'deleted_at' => null,
        ]);
        $this->assertDatabaseHas('customers', [
            'id' => $assignedMissing->id,
            'mikrotik_sync_status' => 'unknown',
            'deleted_at' => null,
        ]);
        $this->assertDatabaseHas('customers', [
            'id' => $globalOnly->id,
            'deleted_at' => null,
        ]);
        $this->assertDatabaseHas('router_staged_customers', [
            'router_id' => $routerA->id,
            'pppoe_user' => 'staged.user',
            'status' => 'unmatched',
        ]);
    }

    public function test_command_supports_single_router_audit(): void
    {
        $package = $this->package();
        $routerA = $this->router('Single A', '198.18.3.1');
        $routerB = $this->router('Single B', '198.18.3.2');
        $this->customer($package, 'CUST-SINGLE', 'single.user', ['router_id' => $routerA->id]);

        $mikrotik = Mockery::mock(MikrotikService::class);
        $mikrotik->shouldReceive('connect')->once()->withArgs(fn ($router) => $router->id === $routerA->id)->andReturnSelf();
        $mikrotik->shouldReceive('getPPPSecrets')->once()->andReturn([
            ['name' => 'single.user', 'disabled' => 'false'],
        ]);
        $mikrotik->shouldReceive('disconnect')->once();
        $this->app->instance(MikrotikService::class, $mikrotik);

        $this->artisan('routers:audit-customers', ['router' => $routerA->id])
            ->expectsOutput('Auditing Single A (198.18.3.1)...')
            ->doesntExpectOutput('Auditing Single B (198.18.3.2)...')
            ->expectsTable(['Metric', 'Count'], [
                ['Routers audited', 1],
                ['Routers failed', 0],
                ['eBilling customers total', 1],
                ['eBilling with PPPoE', 1],
                ['eBilling without PPPoE', 0],
                ['MikroTik PPPoE total', 1],
                ['MikroTik enabled total', 1],
                ['MikroTik disabled total', 0],
                ['Synced/matched total', 1],
                ['MikroTik-only total', 0],
                ['eBilling-only total', 0],
                ['Soft-deleted matches', 0],
            ])
            ->assertExitCode(0);

        $this->assertDatabaseHas('routers', ['id' => $routerB->id]);
    }

    private function router(string $name, string $ip): Router
    {
        return Router::create([
            'name' => $name,
            'ip_address' => $ip,
            'username' => 'admin',
            'password' => Canary::of('pw'),
            'port' => 8728,
            'is_active' => true,
        ]);
    }

    private function package(): Package
    {
        return Package::create([
            'name' => 'Audit Package',
            'code' => 'PKG-AUDIT-'.uniqid(),
            'price' => 100000,
        ]);
    }

    private function customer(Package $package, string $code, string $pppoeUser, array $overrides = []): Customer
    {
        return Customer::create(array_merge([
            'code' => $code,
            'name' => $code,
            'phone' => '080000000000',
            'address' => 'Audit Address',
            'pppoe_user' => $pppoeUser,
            'package_id' => $package->id,
            'status' => 'active',
        ], $overrides));
    }
}
