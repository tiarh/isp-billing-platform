<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerMigration;
use App\Models\Router;
use App\Services\CustomerMigrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use Tests\Support\Canary;
use Tests\TestCase;

#[Group('blocking-psb')]
class MigrationTargetRouterRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_explicit_router_in_raw_text_overrides_stale_persisted_target(): void
    {
        Router::forceCreate([
            'id' => 1,
            'name' => 'Randuagung',
            'ip_address' => '127.0.0.1',
            'username' => 'test',
            'password' => Canary::of('test'),
            'is_active' => true,
        ]);
        Router::forceCreate([
            'id' => 513,
            'name' => 'SKYNET-RA-OLT',
            'ip_address' => '127.0.0.2',
            'username' => 'test',
            'password' => Canary::of('test'),
            'is_active' => true,
        ]);

        $migration = CustomerMigration::create([
            'status' => CustomerMigration::STATUS_FAILED,
            'source' => 'guided_lookup_wa',
            'message_id' => 'migration-router-recovery-test',
            'router_id' => 1,
            'raw_text' => "#migrasi\nRouter lama: Randuagung\nRouter Baru: Skynet-RA-OLT\nOLT: RA",
        ]);

        $method = new ReflectionMethod(CustomerMigrationService::class, 'resolveRouterForMigration');
        $method->setAccessible(true);
        $router = $method->invoke(app(CustomerMigrationService::class), $migration, new Customer(['router_id' => 1]));

        $this->assertNotNull($router);
        $this->assertSame(513, $router->id);
        $this->assertSame('SKYNET-RA-OLT', $router->name);
    }

    public function test_migration_source_prefers_exact_old_router_over_ra_registry_alias(): void
    {
        Router::forceCreate([
            'id' => 1,
            'name' => 'Randuagung',
            'ip_address' => '127.0.0.1',
            'username' => 'test',
            'password' => Canary::of('test'),
            'is_active' => true,
        ]);
        Router::forceCreate([
            'id' => 513,
            'name' => 'SKYNET-RA-OLT',
            'ip_address' => '127.0.0.2',
            'username' => 'test',
            'password' => Canary::of('test'),
            'is_active' => true,
        ]);

        $service = app(CustomerMigrationService::class);
        $method = new ReflectionMethod(CustomerMigrationService::class, 'resolveSourceRouterForMigration');
        $method->setAccessible(true);

        $migration = new CustomerMigration([
            'old_router_id' => 1,
            'raw_text' => "#migrasi\nRouter lama: Randuagung\nRouter Baru: Skynet-RA-OLT\nOLT: RA",
        ]);
        $source = $method->invoke($service, $migration, new Customer(['router_id' => 1]));

        $this->assertNotNull($source);
        $this->assertSame(1, $source->id);
        $this->assertSame('Randuagung', $source->name);
        $this->assertSame('127.0.0.1', $source->ip_address);
    }

    public function test_inactive_persisted_target_is_not_resolved_for_migration(): void
    {
        Router::forceCreate([
            'id' => 512,
            'name' => 'ARCHIVED-SKYNET-RA-OLT-512',
            'ip_address' => '127.0.0.3',
            'username' => 'test',
            'password' => Canary::of('test'),
            'is_active' => false,
        ]);

        $migration = new CustomerMigration(['router_id' => 512]);

        $method = new ReflectionMethod(CustomerMigrationService::class, 'resolveRouterForMigration');
        $method->setAccessible(true);
        $router = $method->invoke(app(CustomerMigrationService::class), $migration, new Customer(['router_id' => 512]));

        $this->assertNull($router);
    }

    public function test_inactive_persisted_source_is_not_resolved_for_migration(): void
    {
        Router::forceCreate([
            'id' => 512,
            'name' => 'ARCHIVED-SKYNET-RA-OLT-512',
            'ip_address' => '127.0.0.3',
            'username' => 'test',
            'password' => Canary::of('test'),
            'is_active' => false,
        ]);
        Router::forceCreate([
            'id' => 513,
            'name' => 'SKYNET-RA-OLT',
            'ip_address' => '127.0.0.2',
            'username' => 'test',
            'password' => Canary::of('test'),
            'is_active' => true,
        ]);

        $migration = new CustomerMigration([
            'old_router_id' => 512,
            'router_id' => 513,
        ]);

        $method = new ReflectionMethod(CustomerMigrationService::class, 'resolveSourceRouterForMigration');
        $method->setAccessible(true);
        $router = $method->invoke(app(CustomerMigrationService::class), $migration, new Customer(['router_id' => 513]));

        $this->assertNull($router);
    }
}
