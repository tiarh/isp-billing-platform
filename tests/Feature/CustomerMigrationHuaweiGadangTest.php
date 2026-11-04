<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerMigration;
use App\Models\Package;
use App\Models\Router;
use App\Services\CustomerMigrationService;
use App\Services\HuaweiOltOnlyProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use Tests\Support\Canary;
use Tests\TestCase;

#[Group('blocking-psb')]
class CustomerMigrationHuaweiGadangTest extends TestCase
{
    use RefreshDatabase;

    public function test_pppoe_resolves_from_the_old_sn_when_not_sent_by_technician(): void
    {
        $router = $this->router(511, 'SKYNET-GADANG');
        $this->customer([
            'router_id' => $router->id,
            'pppoe_user' => 'USERGDG_RT01_RW02',
            'onu_serial' => 'FHTT-OLDLAMA1',
        ]);

        $resolved = $this->resolvePppoe(
            ['full_name' => 'Pelanggan Gadang'],
            'FHTTOLDLAMA1',
            'Gadang',
        );

        $this->assertSame('USERGDG_RT01_RW02', $resolved['pppoe']);
        $this->assertSame('old_sn', $resolved['source']);
    }

    public function test_pppoe_resolves_from_the_customer_name_as_fallback(): void
    {
        $router = $this->router(511, 'SKYNET-GADANG');
        $this->customer([
            'name' => 'Budi Gadang',
            'router_id' => $router->id,
            'pppoe_user' => 'BUDI_GDG',
            'onu_serial' => 'FHTT-OTHER01',
        ]);

        $resolved = $this->resolvePppoe(
            ['full_name' => 'Budi Gadang'],
            '',
            'Gadang',
        );

        $this->assertSame('BUDI_GDG', $resolved['pppoe']);
        $this->assertSame('name', $resolved['source']);
    }

    public function test_pppoe_unresolved_when_no_customer_matches(): void
    {
        $this->router(511, 'SKYNET-GADANG');

        $resolved = $this->resolvePppoe(
            ['full_name' => 'Tidak Ada'],
            'FHTTTIDAKADA',
            'Gadang',
        );

        $this->assertNull($resolved['pppoe']);
        $this->assertSame('unresolved', $resolved['source']);
    }

    public function test_cross_router_source_router_resolves_from_old_router_id(): void
    {
        $gadang = $this->router(511, 'SKYNET-GADANG');
        $bumiayu = $this->router(10, 'Bumiayu');

        $migration = new CustomerMigration([
            'router_id' => $gadang->id,
            'old_router_id' => $bumiayu->id,
            'raw_text' => "#migrasi\nRouter Lama: Bumiayu\nRouter Baru: Gadang",
        ]);
        $migration->exists = true;

        $source = $this->resolveSourceRouter($migration, $gadang);

        $this->assertNotNull($source);
        $this->assertSame($bumiayu->id, $source->id);
    }

    public function test_source_router_is_null_when_old_equals_target(): void
    {
        $gadang = $this->router(511, 'SKYNET-GADANG');

        $migration = new CustomerMigration([
            'router_id' => $gadang->id,
            'old_router_id' => $gadang->id,
            'raw_text' => "#migrasi\nRouter: Gadang",
        ]);
        $migration->exists = true;

        $this->assertNull($this->resolveSourceRouter($migration, $gadang));
    }

    public function test_cross_router_secret_comment_names_the_route(): void
    {
        $migration = new CustomerMigration([
            'raw_text' => '#migrasi',
            'meta' => [
                'cross_router' => true,
                'router_mapping' => ['source_input' => 'Bumiayu', 'target_input' => 'Gadang'],
                'keterangan' => 'pindah router',
            ],
        ]);

        $comment = $this->invokeService('migrationSecretComment', $migration);

        $this->assertStringContainsString('Bumiayu->Gadang', $comment);
        $this->assertStringContainsString('pindah router', $comment);
    }

    private function resolveSourceRouter(CustomerMigration $migration, Router $target): ?Router
    {
        $service = app(HuaweiOltOnlyProvisioningService::class);

        return (new ReflectionMethod($service, 'resolveSourceRouterForMigration'))->invoke($service, $migration, $target);
    }

    private function invokeService(string $method, mixed ...$args): mixed
    {
        $service = app(CustomerMigrationService::class);

        return (new ReflectionMethod($service, $method))->invoke($service, ...$args);
    }

    /** @param array<string, mixed> $parsed @return array<string, mixed> */
    private function resolvePppoe(array $parsed, string $oldSn, string $routerField): array
    {
        $service = app(CustomerMigrationService::class);

        return (new ReflectionMethod($service, 'resolvePppoeForHuaweiDraft'))
            ->invoke($service, $parsed, $oldSn, $routerField);
    }

    private function router(int $id, string $name): Router
    {
        $router = new Router([
            'name' => $name,
            'ip_address' => '10.150.6.'.($id % 250 + 1),
            'username' => 'test',
            'password' => Canary::of('test'),
            'is_active' => true,
        ]);
        $router->id = $id;
        $router->save();

        return $router;
    }

    private function customer(array $overrides = []): Customer
    {
        $package = Package::first() ?: Package::create([
            'code' => 'PKG',
            'name' => 'Paket',
            'price' => 100000,
        ]);

        return Customer::create(array_merge([
            'code' => 'CUST-'.strtoupper(substr(uniqid(), -6)),
            'name' => 'Pelanggan Gadang',
            'phone' => '081234567890',
            'address' => 'Gadang',
            'pppoe_user' => 'gdg.'.substr(uniqid(), -6),
            'package_id' => $package->id,
            'status' => 'active',
        ], $overrides));
    }
}
