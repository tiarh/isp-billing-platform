<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Customer;
use App\Models\CustomerMigration;
use App\Models\Olt;
use App\Models\Package;
use App\Models\Router;
use App\Models\Setting;
use App\Services\CustomerMigrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use Tests\Support\Canary;
use Tests\TestCase;

#[Group('blocking-psb')]
class CustomerMigrationKrianC300Test extends TestCase
{
    use RefreshDatabase;

    public function test_krian_migration_resolves_olt_9_from_the_olt_field_even_when_the_target_router_is_15(): void
    {
        [$router15, $olt9] = $this->seedKrianSites();
        $customer = $this->customer(['router_id' => $router15->id]);

        $migration = new CustomerMigration([
            'raw_text' => "#migrasi\nPPPoE: KRIANUSER\nSN: FHTTKRIAN001\nRouter: Krian\nOLT: KRIAN",
            'router_id' => $router15->id,
        ]);
        $migration->exists = true;

        $olt = $this->invoke('resolveOltForMigration', $migration, $customer, $router15);

        $this->assertNotNull($olt);
        $this->assertSame($olt9->id, $olt->id);
        $this->assertSame('OLT KRIAN', $olt->name);
    }

    public function test_unknown_olt_field_does_not_match_any_olt(): void
    {
        $this->seedKrianSites();

        $migration = new CustomerMigration([
            'raw_text' => "#migrasi\nPPPoE: KRIANUSER\nSN: FHTTKRIAN001\nOLT: TIDAKDIKENAL",
        ]);
        $migration->exists = true;

        $this->assertNull($this->invoke('resolveOltFromMigrationField', $migration));
    }

    public function test_krian_migration_preserves_the_customer_package_and_profile(): void
    {
        [$router15] = $this->seedKrianSites();
        $package = Package::create([
            'code' => '50MB_15',
            'name' => 'Paket 50MB',
            'mikrotik_profile' => '50MB',
            'price' => 150000,
            'router_id' => $router15->id,
            'is_active' => true,
        ]);
        $customer = $this->customer([
            'router_id' => $router15->id,
            'package_id' => $package->id,
            'mikrotik_profile' => '50MB',
        ]);

        $contract = $this->targetContract($router15, $customer);

        $this->assertTrue($contract['preserve_package']);
        $this->assertSame('50MB', $contract['mikrotik_profile']);
        $this->assertSame($package->id, $contract['package_id']);
        $this->assertNull($contract['package_blocker']);
        $this->assertTrue($contract['krian']['applies']);
        $this->assertSame(514, $contract['krian']['target_router_id']);
    }

    public function test_krian_target_contract_is_not_blocked_without_a_startlite_package(): void
    {
        [$router15] = $this->seedKrianSites();
        // No STARTLITE/200M package exists anywhere for this target.
        $customer = $this->customer([
            'router_id' => $router15->id,
            'mikrotik_profile' => '20MB',
        ]);

        $contract = $this->targetContract($router15, $customer);

        $this->assertTrue($contract['preserve_package']);
        $this->assertSame('20MB', $contract['mikrotik_profile']);
        $this->assertNull($contract['package_blocker']);
    }

    public function test_non_krian_migration_keeps_the_startlite_target_profile(): void
    {
        $ra = $this->router(513, 'SKYNET-RA-OLT');
        $package = Package::create([
            'code' => 'STARTLITE_513',
            'name' => 'STARTLITE 200M',
            'mikrotik_profile' => 'STARTLITE/200M',
            'price' => 200000,
            'router_id' => $ra->id,
            'is_active' => true,
        ]);
        $customer = $this->customer([
            'router_id' => $ra->id,
            'package_id' => $package->id,
            'mikrotik_profile' => '10MB',
        ]);

        $contract = $this->targetContract($ra, $customer);

        $this->assertFalse($contract['preserve_package']);
        $this->assertSame('STARTLITE/200M', $contract['profile']);
        $this->assertSame('STARTLITE/200M', $contract['mikrotik_profile']);
    }

    public function test_customer_snapshot_exposes_package_and_profile_for_the_target_contract(): void
    {
        $router = $this->router(15, 'Krian');
        $package = Package::create([
            'code' => 'MIG15',
            'name' => 'Paket Migrasi',
            'mikrotik_profile' => '20MB',
            'price' => 120000,
        ]);
        $customer = $this->customer([
            'router_id' => $router->id,
            'package_id' => $package->id,
            'mikrotik_profile' => '20MB',
        ]);

        $snapshot = $this->invoke('customerSnapshot', $customer);

        $this->assertSame($package->id, $snapshot['package_id']);
        $this->assertSame('20MB', $snapshot['mikrotik_profile']);
    }

    public function test_preserve_needles_are_setting_driven_and_default_to_krian(): void
    {
        $this->assertFalse($this->invoke('migrationPreservesCustomerPackage', 900, 'SomeOtherRouter'));

        Setting::set('migration_preserve_package_router_needles', 'someother');
        $this->assertTrue($this->invoke('migrationPreservesCustomerPackage', 900, 'SomeOtherRouter'));
    }

    public function test_daerah_krian_also_resolves_olt_9_through_the_registry(): void
    {
        [$router15, $olt9] = $this->seedKrianSites();
        $customer = $this->customer(['router_id' => $router15->id]);

        $migration = new CustomerMigration([
            'raw_text' => "#migrasi\nPPPoE: KRIANUSER\nSN: FHTTKRIAN001\nDaerah: Krian",
            'router_id' => $router15->id,
        ]);
        $migration->exists = true;

        $olt = $this->invoke('resolveOltForMigration', $migration, $customer, $router15);

        $this->assertNotNull($olt);
        $this->assertSame($olt9->id, $olt->id);
    }

    /**
     * @return array{0: Router, 1: Olt}
     */
    private function seedKrianSites(): array
    {
        $krianArea = Area::create(['name' => 'SKYNET-KRIAN', 'code' => 'skynet-krian']);
        $router15 = $this->router(15, 'Krian');
        $router514 = $this->router(514, 'Skynet-Krian-OLT');
        $olt9 = new Olt([
            'name' => 'OLT KRIAN',
            'code' => 'OLT-KRIAN',
            'vendor' => 'zte_c300',
            'management_ip' => '198.18.33.10',
            'router_id' => $router514->id,
            'area_id' => $krianArea->id,
        ]);
        $olt9->id = 9;
        $olt9->save();

        // The global RA default must exist so a wrong fallback is detectable.
        $routerRa = $this->router(513, 'SKYNET-RA-OLT');
        $oltRa = new Olt([
            'name' => 'OLT RA',
            'code' => 'OLT-RA',
            'vendor' => 'zte_c300',
            'management_ip' => '198.18.4.1',
            'router_id' => $routerRa->id,
        ]);
        $oltRa->id = 1;
        $oltRa->save();

        return [$router15, $olt9];
    }

    private function targetContract(Router $router, Customer $customer): array
    {
        $migration = new CustomerMigration([
            'full_name' => $customer->name,
            'router_id' => $router->id,
        ]);
        $migration->exists = true;

        return $this->invoke('migrationTargetContract', $migration, [
            'customer_before' => $this->invoke('customerSnapshot', $customer),
            'router' => ['id' => $router->id, 'name' => $router->name],
        ]);
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
            'name' => 'Pelanggan Krian',
            'phone' => '081234567890',
            'address' => 'Krian',
            'pppoe_user' => 'krian.'.substr(uniqid(), -6),
            'package_id' => $package->id,
            'status' => 'active',
        ], $overrides));
    }

    private function invoke(string $method, mixed ...$args): mixed
    {
        return (new ReflectionMethod(app(CustomerMigrationService::class), $method))
            ->invoke(app(CustomerMigrationService::class), ...$args);
    }
}
