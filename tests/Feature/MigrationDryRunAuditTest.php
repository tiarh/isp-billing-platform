<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerMigration;
use App\Models\DeviceActionLog;
use App\Models\Olt;
use App\Models\Package;
use App\Models\Router;
use App\Models\User;
use App\Services\CustomerMigrationService;
use App\Services\EvolutionApiService;
use App\Services\GenieAcsService;
use App\Services\MikrotikService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use Tests\Support\Canary;
use Tests\TestCase;

/**
 * Dry-run audit for the migration flow (router sama dan antar-router).
 *
 * Semua skenario dijalankan terhadap database test disposable dan fake/mock
 * adapter. Tidak ada koneksi ke MikroTik/OLT/GenieACS/Evolution production,
 * tidak ada WhatsApp yang dikirim, dan tidak ada perubahan data pelanggan
 * permanen di luar rollback transaksi test.
 */
#[Group('blocking-psb')]
class MigrationDryRunAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureOltOnusTestTable();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        Mockery::close();
    }

    public function test_cross_router_dry_run_resolves_source_and_target_separately_without_device_write(): void
    {
        $admin = $this->admin();
        [$source, $target, $sourceOlt, $targetOlt, $package] = $this->crossRouterRoutersAndPackage();
        $customer = $this->auditCustomer([
            'code' => 'AUDIT-832',
            'name' => 'AUDIT PELANGGAN 832',
            'phone' => '081234567890',
            'pppoe_user' => 'AUDIT832_RANDUTELU',
            'package_id' => $package->id,
            'router_id' => $source->id,
            'olt_id' => $sourceOlt->id,
        ]);
        $migration = $this->confirmedMigration([
            'customer_id' => $customer->id,
            'pppoe_user' => 'AUDIT832_RANDUTELU',
            'full_name' => 'AUDIT PELANGGAN 832',
            'old_router_id' => $source->id,
            'old_olt_id' => $sourceOlt->id,
            'router_id' => $target->id,
            'new_olt_id' => $targetOlt->id,
            'new_sn' => 'FHTTAUDIT83201',
            'raw_text' => "#migrasi\nNama: AUDIT PELANGGAN 832\nPPPoE: AUDIT832_RANDUTELU\nRouter lama: Randuagung\nRouter baru: Skynet-RA-OLT\nOLT: RA\nSN Baru: FHTTAUDIT83201",
        ]);

        $this->fakeMikrotikSecretFound('AUDIT832_RANDUTELU');
        $this->forbidWhatsapp();

        $prepared = app(CustomerMigrationService::class)->prepareDeviceActionApproval(
            $migration->fresh(),
            $admin,
            'Audit cross-router dry-run 832'
        );

        $ledger = DeviceActionLog::firstOrFail();

        $this->assertSame('dry_run_ready', data_get($prepared->meta, 'device_action_approval.status'));
        $this->assertTrue((bool) data_get($ledger->preflight, 'cross_router'));
        $this->assertSame($source->id, data_get($ledger->preflight, 'source_router.id'));
        $this->assertSame($target->id, data_get($ledger->preflight, 'router.id'));
        $this->assertSame($targetOlt->id, data_get($ledger->preflight, 'olt.id'));
        $this->assertFalse((bool) data_get($ledger->payload, 'device_write'));
        $this->assertFalse((bool) data_get($ledger->payload, 'customer_write'));
        $this->assertSame($source->id, $customer->fresh()->router_id);
    }

    public function test_cross_router_olt_does_not_inherit_source_olt_as_target(): void
    {
        [$source, $target, $sourceOlt, $targetOlt, $package] = $this->crossRouterRoutersAndPackage();

        $customer = $this->auditCustomer([
            'code' => 'AUDIT-836',
            'name' => 'AUDIT PELANGGAN 836',
            'phone' => '081234567891',
            'pppoe_user' => 'AUDIT836_RANDUTELU',
            'package_id' => $package->id,
            'router_id' => $source->id,
            'olt_id' => $sourceOlt->id,
        ]);
        $migration = $this->confirmedMigration([
            'customer_id' => $customer->id,
            'pppoe_user' => 'AUDIT836_RANDUTELU',
            'full_name' => 'AUDIT PELANGGAN 836',
            'old_router_id' => $source->id,
            'router_id' => $target->id,
            'new_olt_id' => null,
            'new_sn' => 'FHTTAUDIT83601',
            'raw_text' => "#migrasi\nNama: AUDIT PELANGGAN 836\nPPPoE: AUDIT836_RANDUTELU\nRouter lama: Randuagung\nRouter baru: Skynet-RA-OLT\nSN Baru: FHTTAUDIT83601",
        ]);

        $olt = $this->callProtected(CustomerMigrationService::class, 'resolveOltForMigration', [$migration, $customer, $target]);

        $this->assertNotNull($olt);
        $this->assertSame($targetOlt->id, $olt->id, 'Target OLT harus milik router target, bukan OLT lama pelanggan.');
        $this->assertNotSame($sourceOlt->id, $olt->id);
    }

    public function test_cross_router_finds_fiberhome_target_olt_without_explicit_new_olt_id(): void
    {
        $source = Router::create(['name' => 'Randuagung', 'ip_address' => '198.18.2.1', 'username' => 'admin', 'password' => Canary::of('pw'), 'is_active' => true]);
        $target = Router::create(['name' => 'SKYNET-RA-OLT', 'ip_address' => '198.18.2.2', 'username' => 'admin', 'password' => Canary::of('pw'), 'is_active' => true]);
        $sourceOlt = Olt::create(['name' => 'OLT RA Lama', 'code' => 'OLT-RA-LAMA', 'vendor' => 'zte_c300', 'router_id' => $source->id]);
        $fiberhomeTarget = Olt::create(['name' => 'OLT RA FiberHome', 'code' => 'OLT-RA-FH', 'vendor' => 'fiberhome', 'router_id' => $target->id]);
        $package = Package::create(['code' => 'AUDIT-FH-PKG', 'name' => 'Paket 200M+ STARTLITE', 'price' => 200000, 'mikrotik_profile' => '200M+ STARTLITE', 'router_id' => $target->id]);

        $customer = $this->auditCustomer([
            'code' => 'AUDIT-FH',
            'name' => 'AUDIT FIBERHOME',
            'phone' => '081234567892',
            'pppoe_user' => 'AUDITFH_RANDUTELU',
            'package_id' => $package->id,
            'router_id' => $source->id,
            'olt_id' => $sourceOlt->id,
        ]);
        $migration = $this->confirmedMigration([
            'customer_id' => $customer->id,
            'pppoe_user' => 'AUDITFH_RANDUTELU',
            'old_router_id' => $source->id,
            'router_id' => $target->id,
            'new_olt_id' => null,
            'new_sn' => 'FHTTAUDITFH01',
            'raw_text' => "#migrasi\nRouter lama: Randuagung\nRouter baru: Skynet-RA-OLT\nSN Baru: FHTTAUDITFH01",
        ]);

        $olt = $this->callProtected(CustomerMigrationService::class, 'resolveOltForMigration', [$migration, $customer, $target]);

        $this->assertNotNull($olt);
        $this->assertSame($fiberhomeTarget->id, $olt->id, 'Router target dengan OLT FiberHome saja tetap harus terselesaikan.');
    }

    public function test_same_router_dry_run_inherits_customer_olt_and_marks_same_router(): void
    {
        [$source, , $sourceOlt, , $package] = $this->crossRouterRoutersAndPackage();
        $customer = $this->auditCustomer([
            'code' => 'AUDIT-SAME',
            'name' => 'AUDIT SAME ROUTER',
            'phone' => '081234567893',
            'pppoe_user' => 'AUDITSAME_RANDUTELU',
            'package_id' => $package->id,
            'router_id' => $source->id,
            'olt_id' => $sourceOlt->id,
        ]);
        $migration = $this->confirmedMigration([
            'customer_id' => $customer->id,
            'pppoe_user' => 'AUDITSAME_RANDUTELU',
            'old_router_id' => $source->id,
            'router_id' => $source->id,
            'new_olt_id' => $sourceOlt->id,
            'new_sn' => 'FHTTAUDITSAME01',
            'raw_text' => "#migrasi\nNama: AUDIT SAME ROUTER\nPPPoE: AUDITSAME_RANDUTELU\nSN Baru: FHTTAUDITSAME01",
        ]);

        $this->fakeMikrotikSecretFound('AUDITSAME_RANDUTELU');

        $preflight = $this->callProtected(CustomerMigrationService::class, 'deviceActionPreflight', [$migration->fresh()]);

        $this->assertFalse((bool) data_get($preflight, 'cross_router'));
        $this->assertSame($source->id, data_get($preflight, 'router.id'));
        $this->assertSame($sourceOlt->id, data_get($preflight, 'olt.id'));
    }

    public function test_unresolvable_source_router_blocks_preflight_without_device_write(): void
    {
        [$source, $target, $sourceOlt, $targetOlt, $package] = $this->crossRouterRoutersAndPackage();
        $customer = $this->auditCustomer([
            'code' => 'AUDIT-NOSRC',
            'name' => 'AUDIT NO SOURCE',
            'phone' => '081234567894',
            'pppoe_user' => 'AUDITNOSRC',
            'package_id' => $package->id,
            'router_id' => $source->id,
            'olt_id' => $sourceOlt->id,
        ]);
        $migration = $this->confirmedMigration([
            'customer_id' => $customer->id,
            'pppoe_user' => 'AUDITNOSRC',
            'old_router_id' => null,
            'router_id' => $target->id,
            'new_olt_id' => $targetOlt->id,
            'new_sn' => 'FHTTAUDITNOSRC01',
            'raw_text' => "#migrasi\nRouter lama: RouterYangTidakAda123\nRouter baru: Skynet-RA-OLT\nSN Baru: FHTTAUDITNOSRC01",
        ]);

        $preflight = $this->callProtected(CustomerMigrationService::class, 'deviceActionPreflight', [$migration->fresh()]);

        $this->assertContains('source_router_missing', $preflight['reasons']);
        $this->assertSame(0, DeviceActionLog::count());
        $this->assertSame($source->id, $customer->fresh()->router_id);
    }

    public function test_target_profile_missing_blocks_preflight(): void
    {
        [$source, $target, $sourceOlt, $targetOlt, $package] = $this->crossRouterRoutersAndPackage();
        $customer = $this->auditCustomer([
            'code' => 'AUDIT-NOPROF',
            'name' => 'AUDIT NO PROFILE',
            'phone' => '081234567895',
            'pppoe_user' => 'AUDITNOPROF',
            'package_id' => $package->id,
            'router_id' => $source->id,
            'olt_id' => $sourceOlt->id,
        ]);
        $migration = $this->confirmedMigration([
            'customer_id' => $customer->id,
            'pppoe_user' => 'AUDITNOPROF',
            'old_router_id' => $source->id,
            'router_id' => $target->id,
            'new_olt_id' => $targetOlt->id,
            'new_sn' => 'FHTTAUDITNOPROF01',
            'raw_text' => "#migrasi\nRouter lama: Randuagung\nRouter baru: Skynet-RA-OLT\nSN Baru: FHTTAUDITNOPROF01",
        ]);

        $this->fakeMikrotikProfiles(['SOMEOTHERPROFILE'], 'AUDITNOPROF');

        $preflight = $this->callProtected(CustomerMigrationService::class, 'deviceActionPreflight', [$migration->fresh()]);

        $this->assertContains('target_profile_missing', $preflight['reasons']);
        $this->assertSame(0, DeviceActionLog::count());
    }

    public function test_profile_alias_startlite_and_200m_plus_are_accepted(): void
    {
        [$source, $target, $sourceOlt, $targetOlt, $package] = $this->crossRouterRoutersAndPackage();
        $customer = $this->auditCustomer([
            'code' => 'AUDIT-ALIAS',
            'name' => 'AUDIT ALIAS',
            'phone' => '081234567896',
            'pppoe_user' => 'AUDITALIAS',
            'package_id' => $package->id,
            'router_id' => $source->id,
            'olt_id' => $sourceOlt->id,
        ]);
        $migration = $this->confirmedMigration([
            'customer_id' => $customer->id,
            'pppoe_user' => 'AUDITALIAS',
            'old_router_id' => $source->id,
            'router_id' => $target->id,
            'new_olt_id' => $targetOlt->id,
            'new_sn' => 'FHTTAUDITALIAS01',
            'raw_text' => "#migrasi\nRouter lama: Randuagung\nRouter baru: Skynet-RA-OLT\nSN Baru: FHTTAUDITALIAS01",
        ]);

        $this->fakeMikrotikProfiles(['200M+ STARTLITE'], 'AUDITALIAS');

        $preflight = $this->callProtected(CustomerMigrationService::class, 'deviceActionPreflight', [$migration->fresh()]);

        $this->assertTrue((bool) data_get($preflight, 'target_profile.found'));
        $this->assertStringContainsString('STARTLITE', (string) data_get($preflight, 'target_profile.resolved'));
    }

    public function test_host_null_router_yields_old_pppoe_secret_unverified(): void
    {
        [$source, $target, $sourceOlt, $targetOlt, $package] = $this->crossRouterRoutersAndPackage();
        $customer = $this->auditCustomer([
            'code' => 'AUDIT-HOSTNULL',
            'name' => 'AUDIT HOST NULL',
            'phone' => '081234567897',
            'pppoe_user' => 'AUDITHOSTNULL',
            'package_id' => $package->id,
            'router_id' => $source->id,
            'olt_id' => $sourceOlt->id,
        ]);
        $migration = $this->confirmedMigration([
            'customer_id' => $customer->id,
            'pppoe_user' => 'AUDITHOSTNULL',
            'old_router_id' => $source->id,
            'router_id' => $target->id,
            'new_olt_id' => $targetOlt->id,
            'new_sn' => 'FHTTAUDITHOSTNULL01',
            'raw_text' => "#migrasi\nRouter lama: Randuagung\nRouter baru: Skynet-RA-OLT\nSN Baru: FHTTAUDITHOSTNULL01",
        ]);

        $mock = Mockery::mock(MikrotikService::class);
        $mock->shouldReceive('connect')->andThrow(new \InvalidArgumentException('Router Randuagung belum memiliki IP address/VPN address yang valid.'));
        $mock->shouldReceive('disconnect')->andReturnNull();
        $this->app->instance(MikrotikService::class, $mock);

        $preflight = $this->callProtected(CustomerMigrationService::class, 'deviceActionPreflight', [$migration->fresh()]);

        $this->assertContains('old_pppoe_secret_unverified', $preflight['reasons']);
        $this->assertSame(0, DeviceActionLog::count());
    }

    public function test_unconfirmed_migration_blocks_prepare_and_creates_no_ledger(): void
    {
        $admin = $this->admin();
        [$source, $target, $sourceOlt, $targetOlt, $package] = $this->crossRouterRoutersAndPackage();
        $customer = $this->auditCustomer([
            'code' => 'AUDIT-UNC',
            'name' => 'AUDIT UNCONFIRMED',
            'phone' => '081234567898',
            'pppoe_user' => 'AUDITUNC',
            'package_id' => $package->id,
            'router_id' => $source->id,
            'olt_id' => $sourceOlt->id,
        ]);
        $migration = $this->confirmedMigration([
            'customer_id' => $customer->id,
            'pppoe_user' => 'AUDITUNC',
            'old_router_id' => $source->id,
            'router_id' => $target->id,
            'new_olt_id' => $targetOlt->id,
            'new_sn' => 'FHTTAUDITUNC01',
            'meta' => ['migration_review' => ['match_status' => 'needs_review', 'reasons' => ['excel_match_multiple']]],
            'raw_text' => "#migrasi\nRouter lama: Randuagung\nRouter baru: Skynet-RA-OLT\nSN Baru: FHTTAUDITUNC01",
        ]);

        $this->fakeMikrotikSecretFound('AUDITUNC');

        try {
            app(CustomerMigrationService::class)->prepareDeviceActionApproval($migration->fresh(), $admin, 'Audit unconfirmed');
            $this->fail('Prepare harus menolak migrasi yang belum confirmed.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('belum layak', $e->getMessage());
        }

        $this->assertSame(0, DeviceActionLog::count());
        $this->assertSame($source->id, $customer->fresh()->router_id);
    }

    public function test_prepare_is_idempotent_three_times_and_creates_single_ledger(): void
    {
        $admin = $this->admin();
        [$source, $target, $sourceOlt, $targetOlt, $package] = $this->crossRouterRoutersAndPackage();
        $customer = $this->auditCustomer([
            'code' => 'AUDIT-IDEMP',
            'name' => 'AUDIT IDEMPOTENT',
            'phone' => '081234567899',
            'pppoe_user' => 'AUDITIDEMP',
            'package_id' => $package->id,
            'router_id' => $source->id,
            'olt_id' => $sourceOlt->id,
        ]);
        $migration = $this->confirmedMigration([
            'customer_id' => $customer->id,
            'pppoe_user' => 'AUDITIDEMP',
            'old_router_id' => $source->id,
            'old_olt_id' => $sourceOlt->id,
            'router_id' => $target->id,
            'new_olt_id' => $targetOlt->id,
            'new_sn' => 'FHTTAUDITIDEMP01',
            'raw_text' => "#migrasi\nNama: AUDIT IDEMPOTENT\nPPPoE: AUDITIDEMP\nRouter lama: Randuagung\nRouter baru: Skynet-RA-OLT\nSN Baru: FHTTAUDITIDEMP01",
        ]);

        $this->fakeMikrotikSecretFound('AUDITIDEMP');
        $this->forbidWhatsapp();

        // Tiga kali submit approval yang sama melalui jalur resmi (controller).
        for ($i = 1; $i <= 3; $i++) {
            $this->actingAs($admin)
                ->post(route('customer-migrations.device-action.prepare', $migration->fresh()), [
                    'reason' => "Idempotensi attempt {$i}",
                ])
                ->assertSessionHasNoErrors()
                ->assertSessionHas('success');
        }

        $this->assertSame(1, DeviceActionLog::count());
        $this->assertSame('dry_run_ready', data_get($migration->fresh()->meta, 'device_action_approval.status'));
        $this->assertFalse((bool) data_get(DeviceActionLog::first()->payload, 'device_write'));
        $this->assertSame($source->id, $customer->fresh()->router_id);
    }

    public function test_dry_run_never_sends_whatsapp(): void
    {
        $admin = $this->admin();
        [$source, $target, $sourceOlt, $targetOlt, $package] = $this->crossRouterRoutersAndPackage();
        $customer = $this->auditCustomer([
            'code' => 'AUDIT-NOWA',
            'name' => 'AUDIT NO WA',
            'phone' => '081234567800',
            'pppoe_user' => 'AUDITNOWA',
            'package_id' => $package->id,
            'router_id' => $source->id,
            'olt_id' => $sourceOlt->id,
        ]);
        $migration = $this->confirmedMigration([
            'customer_id' => $customer->id,
            'group_jid' => '120363AUDITTEST@g.us',
            'pppoe_user' => 'AUDITNOWA',
            'old_router_id' => $source->id,
            'router_id' => $target->id,
            'new_olt_id' => $targetOlt->id,
            'new_sn' => 'FHTTAUDITNOWA01',
            'raw_text' => "#migrasi\nRouter lama: Randuagung\nRouter baru: Skynet-RA-OLT\nSN Baru: FHTTAUDITNOWA01",
        ]);

        $this->fakeMikrotikSecretFound('AUDITNOWA');
        $this->forbidWhatsapp();

        app(CustomerMigrationService::class)->prepareDeviceActionApproval($migration->fresh(), $admin, 'Audit no WA');

        $this->assertSame(1, DeviceActionLog::count());
        $this->assertFalse((bool) data_get(DeviceActionLog::first()->payload, 'device_write'));
    }

    public function test_router_alias_case_and_spacing_variants_resolve_correctly(): void
    {
        $source = Router::create(['name' => 'Randuagung', 'ip_address' => '198.18.2.1', 'username' => 'admin', 'password' => Canary::of('pw'), 'is_active' => true]);
        $target = Router::create(['name' => 'SKYNET-RA-OLT', 'ip_address' => '198.18.2.2', 'username' => 'admin', 'password' => Canary::of('pw'), 'is_active' => true]);

        $service = app(CustomerMigrationService::class);
        $resolve = new ReflectionMethod($service, 'resolveMigrationRouterAlias');
        $resolve->setAccessible(true);

        $this->assertSame($source->id, $resolve->invoke($service, ' randuagung ')->id);
        $this->assertSame($source->id, $resolve->invoke($service, 'RANDUAGUNG')->id);
        $this->assertSame($target->id, $resolve->invoke($service, 'skynet-ra-olt')->id);
        $this->assertSame($target->id, $resolve->invoke($service, ' Skynet RA OLT ')->id);
        $this->assertNull($resolve->invoke($service, 'RouterYangTidakAda'));
    }

    // ---------- Helpers ----------

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function crossRouterRoutersAndPackage(): array
    {
        $source = Router::create(['name' => 'Randuagung', 'ip_address' => '198.18.28.2', 'username' => 'admin', 'password' => Canary::of('pw'), 'is_active' => true]);
        $target = Router::create(['name' => 'SKYNET-RA-OLT', 'ip_address' => '198.18.24.2', 'username' => 'admin', 'password' => Canary::of('pw'), 'is_active' => true]);
        $sourceOlt = Olt::create(['name' => 'OLT RA Lama', 'code' => 'OLT-RA-LAMA', 'vendor' => 'zte_c300', 'router_id' => $source->id]);
        $targetOlt = Olt::create(['name' => 'OLT RA FiberHome', 'code' => 'OLT-RA-FH', 'vendor' => 'fiberhome', 'router_id' => $target->id]);
        $package = Package::create(['code' => 'AUDIT-STARTLITE', 'name' => 'Paket 200M+ STARTLITE', 'price' => 200000, 'mikrotik_profile' => '200M+ STARTLITE', 'router_id' => $target->id]);

        return [$source, $target, $sourceOlt, $targetOlt, $package];
    }

    private function auditCustomer(array $overrides): Customer
    {
        return Customer::create(array_merge([
            'code' => 'AUDIT-CUSTOMER',
            'name' => 'Audit Pelanggan',
            'phone' => '081234567800',
            'address' => 'RANDUTELU',
            'pppoe_user' => 'AUDITUSER',
            'onu_serial' => 'OLDSN',
            'olt_port_label' => 'OLDREF',
            'mikrotik_profile' => '10MB',
            'status' => 'active',
        ], $overrides));
    }

    private function confirmedMigration(array $overrides): CustomerMigration
    {
        return CustomerMigration::create(array_merge([
            'status' => CustomerMigration::STATUS_NEEDS_REVIEW,
            'source' => 'wa',
            'group_jid' => '120363AUDITTEST@g.us',
            'sender_name' => 'Auditor',
            'message_id' => 'audit-'.uniqid(),
            'full_name' => 'Audit Pelanggan',
            'pppoe_user' => 'AUDITUSER',
            'rt' => '02',
            'rw' => '10',
            'daerah' => 'RANDUTELU',
            'old_router_id' => 1,
            'old_sn' => 'OLDSN',
            'old_onu_ref' => '1/1/1:1',
            'new_onu_ref' => '1/1/1:10',
            'router_id' => 2,
            'new_sn' => 'FHTTAUDIT0001',
            'meta' => ['migration_review' => ['match_status' => 'confirmed_pending_approval', 'reasons' => ['operator_approval_required']]],
        ], $overrides));
    }

    private function fakeMikrotikSecretFound(string $username = 'AUDITUSER'): void
    {
        $mock = Mockery::mock(MikrotikService::class);
        $mock->shouldReceive('connect')->andReturnSelf();
        $mock->shouldReceive('getPPPSecret')->with($username)->andReturn(['name' => $username]);
        $mock->shouldReceive('getProfiles')->andReturn([
            ['name' => 'STARTLITE'],
            ['name' => '200M+ STARTLITE'],
        ]);
        $mock->shouldReceive('disconnect')->andReturnNull();
        $this->app->instance(MikrotikService::class, $mock);
    }

    private function fakeMikrotikProfiles(array $profiles, string $username): void
    {
        $mock = Mockery::mock(MikrotikService::class);
        $mock->shouldReceive('connect')->andReturnSelf();
        $mock->shouldReceive('getPPPSecret')->with($username)->andReturn(['name' => $username]);
        $mock->shouldReceive('getProfiles')->andReturn(array_map(fn ($name) => ['name' => $name], $profiles));
        $mock->shouldReceive('disconnect')->andReturnNull();
        $this->app->instance(MikrotikService::class, $mock);
    }

    private function forbidWhatsapp(): void
    {
        $wa = Mockery::mock(EvolutionApiService::class);
        $wa->shouldNotReceive('sendToGroup');
        $wa->shouldNotReceive('send');
        $wa->shouldNotReceive('sendGroupMessage');
        $this->app->instance(EvolutionApiService::class, $wa);

        $genie = Mockery::mock(GenieAcsService::class);
        $genie->shouldNotReceive('syncDevice');
        $genie->shouldNotReceive('tagDevice');
        $this->app->instance(GenieAcsService::class, $genie);
    }

    private function callProtected(string $class, string $method, array $args): mixed
    {
        $service = app($class);
        $reflection = new ReflectionMethod($service, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($service, ...$args);
    }

    private function ensureOltOnusTestTable(): void
    {
        if (Schema::hasTable('olt_onus')) {
            return;
        }

        Schema::create('olt_onus', function ($table) {
            $table->id();
            $table->unsignedBigInteger('olt_id')->index();
            $table->string('onu_ref')->nullable();
            $table->string('pon_port')->nullable();
            $table->string('serial_number')->nullable();
            $table->string('onu_name')->nullable();
            $table->string('state')->nullable();
            $table->string('phase_state')->nullable();
            $table->decimal('rx_power_dbm', 8, 2)->nullable();
            $table->timestamps();
        });
    }
}
