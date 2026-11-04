<?php

namespace Tests\Feature;

use App\Jobs\ExecuteCustomerMigrationWaJob;
use App\Models\Customer;
use App\Models\CustomerMigration;
use App\Models\DeviceActionLog;
use App\Models\Olt;
use App\Models\OltOnu;
use App\Models\Package;
use App\Models\PppoeAddressPoolBlock;
use App\Models\Router;
use App\Models\Setting;
use App\Models\User;
use App\Services\CustomerMigrationService;
use App\Services\GenieAcsService;
use App\Services\GuidedMigrationLookupService;
use App\Services\HuaweiOltOnlyProvisioningService;
use App\Services\MikrotikService;
use App\Services\ProductionControlPlaneService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Canary;
use Tests\TestCase;

#[Group('blocking-psb')]
class CustomerMigrationDeviceActionApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_prepare_device_action_creates_idempotent_ledger_without_customer_write(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $migration = $this->confirmedMigration();
        $this->fakeMikrotikSecretFound();

        $this->actingAs($admin)
            ->post(route('customer-migrations.device-action.prepare', $migration), [
                'reason' => 'Operator confirm migrasi dari staging',
            ])
            ->assertSessionHasNoErrors()
            // Kontrak flash H1: dry-run sukses harus memberi feedback via flash.success.
            ->assertSessionHas('success')
            ->assertSessionMissing('status');

        $this->actingAs($admin)
            ->post(route('customer-migrations.device-action.prepare', $migration), [
                'reason' => 'Operator confirm migrasi dari staging',
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $migration->refresh();
        $customer = $migration->customer->fresh();
        $ledger = DeviceActionLog::first();

        $this->assertSame(1, DeviceActionLog::count());
        $this->assertSame('dry_run', $ledger->status);
        $this->assertSame('migration', $ledger->domain);
        $this->assertSame('customer_migration', $ledger->target_type);
        $this->assertSame('dry_run_ready', $migration->meta['device_action_approval']['status']);
        $this->assertFalse($migration->meta['device_action_approval']['write_flags']['olt']);
        $this->assertFalse($ledger->payload['device_write']);
        $this->assertFalse($ledger->payload['customer_write']);
        $this->assertTrue($ledger->payload['write_blocked_until_ack']);
        $this->assertSame('STARTLITE/200M', $ledger->payload['target_contract']['profile']);
        $this->assertSame('planned_only_no_write', $ledger->payload['dry_run_diff']['olt']['mode']);
        $this->assertSame('OLD_SN', $customer->onu_serial);
        $this->assertSame('OLDREF', $customer->olt_port_label);
    }

    public function test_migration_router_lookup_prefers_exact_router_name_over_site_alias(): void
    {
        $old = Router::create([
            'name' => 'Randuagung',
            'ip_address' => '198.18.28.2',
            'username' => 'admin',
            'password' => Canary::of('pw'),
            'is_active' => true,
        ]);
        $new = Router::create([
            'name' => 'SKYNET-RA-OLT',
            'ip_address' => '198.18.24.2',
            'username' => 'admin',
            'password' => Canary::of('pw'),
            'is_active' => true,
        ]);

        $service = app(CustomerMigrationService::class);
        $method = new \ReflectionMethod($service, 'resolveMigrationRouterAlias');
        $method->setAccessible(true);

        $this->assertSame($old->id, $method->invoke($service, 'Randuagung')->id);
        $this->assertSame($new->id, $method->invoke($service, 'Skynet-RA-OLT')->id);
    }

    public function test_userya_format_prepares_cross_router_target_profile_without_device_write(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $source = Router::create([
            'name' => 'Randuagung',
            'ip_address' => '198.18.28.2',
            'username' => 'admin',
            'password' => Canary::of('pw'),
            'is_active' => true,
        ]);
        $target = Router::create([
            'name' => 'SKYNET-RA-OLT',
            'ip_address' => '198.18.24.2',
            'username' => 'admin',
            'password' => Canary::of('pw'),
            'is_active' => true,
        ]);
        $sourceOlt = Olt::create([
            'name' => 'OLT RA',
            'code' => 'OLT-RA',
            'vendor' => 'zte_c300',
            'router_id' => $source->id,
        ]);
        $targetOlt = Olt::create([
            'name' => 'OLT RA FiberHome',
            'code' => 'OLT-RA-FH',
            'vendor' => 'fiberhome',
            'router_id' => $target->id,
        ]);
        $oldPackage = Package::create([
            'code' => 'USERYA-OLD',
            'name' => '10MB',
            'price' => 100000,
            'mikrotik_profile' => '10MB',
            'router_id' => $source->id,
        ]);
        $targetPackage = Package::create([
            'code' => 'USERYA-STARTLITE',
            'name' => 'Paket 200M+ STARTLITE',
            'price' => 200000,
            'mikrotik_profile' => '200M+ STARTLITE',
            'router_id' => $target->id,
        ]);
        $customer = Customer::create([
            'code' => 'USERYA-E2E',
            'name' => 'USER YA',
            'phone' => '081200000013',
            'address' => 'RANDUTELU',
            'package_id' => $oldPackage->id,
            'pppoe_user' => 'USERYA_RANDUTELU',
            'mikrotik_profile' => '10MB',
            'router_id' => $source->id,
            'olt_id' => $sourceOlt->id,
            'status' => 'active',
        ]);
        $migration = CustomerMigration::create([
            'status' => CustomerMigration::STATUS_NEEDS_REVIEW,
            'source' => 'wa',
            'customer_id' => $customer->id,
            'pppoe_user' => 'USERYA_RANDUTELU',
            'full_name' => 'USER YA',
            'rt' => '02',
            'rw' => '10',
            'daerah' => 'RANDUTELU',
            'odp' => '034_L6',
            'old_router_id' => $source->id,
            'old_olt_id' => $sourceOlt->id,
            'router_id' => $target->id,
            'new_olt_id' => $targetOlt->id,
            'new_sn' => 'FHTT0000A053',
            'raw_text' => "#migrasi\nNama: USER YA\nPPPOE : USERYA_RANDUTELU\nOLT: RA\nDaerah: RANDUTELU\nRT: 02\nRW: 10\nODP BARU : 034_L6\nSN BARU: FHTT0000A053\nRouter lama : randuagung\nRouter Baru : Skynet-RA-OLT\nKeterangan: migrasi htb ke FiberHome",
            'meta' => [
                'migration_review' => [
                    'match_status' => 'confirmed_pending_approval',
                ],
            ],
        ]);
        $this->fakeMikrotikSecretFound('USERYA_RANDUTELU');

        $prepared = app(CustomerMigrationService::class)->prepareDeviceActionApproval(
            $migration,
            $admin,
            'E2E Yuwono cross-router dry-run'
        );
        $ledger = DeviceActionLog::firstOrFail();

        $this->assertSame('dry_run_ready', data_get($prepared->meta, 'device_action_approval.status'));
        $this->assertTrue(data_get($ledger->preflight, 'cross_router'));
        $this->assertSame($source->id, data_get($ledger->preflight, 'source_router.id'));
        $this->assertSame($target->id, data_get($ledger->preflight, 'router.id'));
        $this->assertSame($targetPackage->id, data_get($ledger->payload, 'target_contract.package_id'));
        $this->assertSame('200M+ STARTLITE', data_get($ledger->payload, 'target_contract.mikrotik_profile'));
        $this->assertFalse((bool) data_get($ledger->payload, 'device_write'));
        $this->assertSame($source->id, $customer->fresh()->router_id);
    }

    public function test_dwi_ayu_format_rejects_sn_already_owned_by_yuwono_onu(): void
    {
        $this->ensureOltOnusTestTable();
        $admin = User::factory()->create(['role' => 'admin']);
        $migration = $this->confirmedMigration();
        $source = $migration->router;
        $source->forceFill(['name' => 'Randuagung'])->save();
        $target = Router::create([
            'name' => 'SKYNET-RA-OLT',
            'ip_address' => '198.18.24.2',
            'username' => 'admin',
            'password' => Canary::of('pw'),
            'is_active' => true,
        ]);
        $targetOlt = Olt::create([
            'name' => 'OLT RA FiberHome',
            'code' => 'OLT-RA-FH',
            'vendor' => 'fiberhome',
            'router_id' => $target->id,
        ]);
        Package::create([
            'code' => 'USERDWI-STARTLITE',
            'name' => 'Paket 200M+ STARTLITE',
            'price' => 200000,
            'mikrotik_profile' => '200M+ STARTLITE',
            'router_id' => $target->id,
        ]);
        $migration->customer->forceFill([
            'name' => 'USER DWI CONTOH',
            'pppoe_user' => 'USERDWI_RT02_RW10',
        ])->save();
        $migration->forceFill([
            'pppoe_user' => 'USERDWI_RT02_RW10',
            'full_name' => 'USER DWI CONTOH',
            'rt' => '02',
            'rw' => '10',
            'daerah' => 'RANDUTELU',
            'odp' => '034_L6',
            'old_router_id' => $source->id,
            'router_id' => $target->id,
            'new_olt_id' => $targetOlt->id,
            'new_sn' => 'FHTT0000A053',
            'raw_text' => "#migrasi\nNama: USER DWI CONTOH\nPPPOE : USERDWI_RT02_RW10\nOLT: RA\nDaerah: RANDUTELU\nRT: 02\nRW: 10\nODP BARU : 034_L6\nSN BARU: FHTT0000A053\nRouter lama : randuagung\nRouter Baru : Skynet-RA-OLT\nKeterangan: migrasi htb ke FiberHome",
        ])->save();
        OltOnu::create([
            'olt_id' => $targetOlt->id,
            'onu_ref' => '1/3/9:17',
            'pon_port' => '1/3/9',
            'serial_number' => 'FHTT0000A053',
            'onu_name' => 'USERAS_RT02_RW10_L6',
            'state' => 'online',
        ]);
        $this->fakeMikrotikSecretFound((string) $migration->pppoe_user);

        try {
            app(CustomerMigrationService::class)->prepareDeviceActionApproval(
                $migration->fresh(),
                $admin,
                'E2E USER DWI duplicate SN guard'
            );
            $this->fail('Duplicate SN owned by another ONU must be blocked.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('new_sn_in_use_by_other_onu', $e->getMessage());
        }

        $this->assertSame(0, DeviceActionLog::count());
        $this->assertSame($source->id, $migration->customer->fresh()->router_id);
    }

    public function test_reprepare_after_executed_waiting_sn_creates_one_retry_ledger(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $migration = $this->confirmedMigration();
        $this->fakeMikrotikSecretFound();

        $service = app(CustomerMigrationService::class);
        $service->prepareDeviceActionApproval($migration, $admin, 'Dry-run attempt pertama');

        $first = DeviceActionLog::firstOrFail();
        $first->forceFill([
            'status' => 'executed',
            'executed_at' => now(),
            'result' => ['migration_status' => CustomerMigration::STATUS_WAITING_SN],
        ])->save();

        $migration->forceFill(['status' => CustomerMigration::STATUS_NEEDS_REVIEW])->save();
        $secondPrepare = $service->prepareDeviceActionApproval(
            $migration->fresh(),
            $admin,
            'Dry-run ulang setelah SN tersedia'
        );
        $secondUpdatedAt = $secondPrepare->updated_at;
        $this->travel(1)->seconds();
        $thirdPrepare = $service->prepareDeviceActionApproval(
            $secondPrepare,
            $admin,
            'Klik ulang harus memakai ledger aktif'
        );

        $this->assertSame(2, DeviceActionLog::count());
        $this->assertSame('executed', $first->fresh()->status);
        $retry = DeviceActionLog::query()->latest('id')->firstOrFail();
        $this->assertSame('dry_run', $retry->status);
        $this->assertSame($first->idempotency_key.':retry:'.$first->id, $retry->idempotency_key);
        $this->assertSame($retry->id, data_get($thirdPrepare->meta, 'device_action_approval.ledger_id'));
        $this->assertSame('dry_run_ready', data_get($thirdPrepare->meta, 'device_action_approval.status'));
        $this->assertTrue($thirdPrepare->updated_at->equalTo($secondUpdatedAt));
    }

    public function test_execute_is_blocked_by_default_kill_switch_and_does_not_write_customer(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $migration = $this->confirmedMigration();
        $this->fakeMikrotikSecretFound();

        app(CustomerMigrationService::class)->prepareDeviceActionApproval($migration, $admin, 'Operator confirm migrasi dari staging');

        $this->actingAs($admin)
            ->post(route('customer-migrations.device-action.execute', $migration), [
                'reason' => 'Operator coba execute aman',
            ])
            ->assertSessionHasErrors('migration_action');

        $migration->refresh();
        $customer = $migration->customer->fresh();
        $ledger = DeviceActionLog::first();

        $this->assertSame('blocked', $ledger->status);
        $this->assertSame('blocked_kill_switch', $migration->meta['device_action_approval']['status']);
        $this->assertStringContainsString('kill switch', strtolower((string) $migration->last_error));
        $this->assertSame('OLD_SN', $customer->onu_serial);
        $this->assertSame('OLDREF', $customer->olt_port_label);
    }

    public function test_huawei_execution_failure_closes_ledger_and_requires_reconciliation(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $migration = $this->confirmedMigration([
            'meta' => [
                'migration_operation_mode' => HuaweiOltOnlyProvisioningService::MODE,
                'migration_review' => ['match_status' => 'confirmed_pending_approval'],
                'device_action_preflight' => [
                    'plan' => ['command_hash' => 'approved-command-hash'],
                ],
            ],
        ]);
        $ledger = DeviceActionLog::create([
            'domain' => 'migration',
            'target_type' => 'customer_migration',
            'target_id' => (string) $migration->id,
            'action' => 'migration-device-action-dry-run',
            'status' => 'dry_run',
            'idempotency_key' => 'migration-device-action:'.$migration->id,
            'payload_hash' => hash('sha256', 'huawei-failure'),
            'payload' => [],
            'preflight' => [],
            'requested_at' => now(),
        ]);
        $migration->forceFill([
            'meta' => array_merge((array) $migration->meta, [
                'device_action_approval' => [
                    'status' => 'dry_run_ready',
                    'ledger_id' => $ledger->id,
                ],
            ]),
        ])->save();

        Setting::set('migration_device_actions_enabled', '1');
        Setting::set('control_plane.device_read_only_mode', '0');
        Setting::set('control_plane.olt_writes_enabled', '1');
        Setting::set('control_plane.genieacs_writes_enabled', '1');

        $huawei = Mockery::mock(HuaweiOltOnlyProvisioningService::class);
        $huawei->shouldReceive('preflight')->once()->andReturn([
            'eligible' => true,
            'plan' => ['command_hash' => 'approved-command-hash'],
            'olt' => ['id' => $migration->new_olt_id],
            'placement' => [
                'pon_port' => '1/1/1',
                'onu_id' => 10,
                'onu_ref' => '1/1/1:10',
            ],
        ]);
        $huawei->shouldReceive('executePlan')
            ->once()
            ->andThrow(new \RuntimeException('screen-length 0 temporary rejected by Huawei'));
        $this->app->instance(HuaweiOltOnlyProvisioningService::class, $huawei);

        try {
            app(CustomerMigrationService::class)->executeApprovedDeviceAction(
                $migration->fresh(),
                $admin,
                'Operator retry Huawei after failed command',
            );
            $this->fail('Huawei execution failure must be rethrown after safe state persistence.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('screen-length', $exception->getMessage());
        }

        $ledger->refresh();
        $migration->refresh();

        $this->assertSame('failed', $ledger->status);
        $this->assertSame('device_action_execution_failed', $ledger->result['failure_code']);
        $this->assertSame('RuntimeException', $ledger->result['exception_class']);
        $this->assertSame('unknown', $ledger->result['device_write']);
        $this->assertTrue($ledger->result['manual_reconciliation_required']);
        $this->assertSame('execution_failed', data_get($migration->meta, 'device_action_approval.status'));
        $this->assertTrue((bool) data_get($migration->meta, 'device_action_approval.manual_reconciliation_required'));
        $this->assertStringContainsString('rekonsiliasi read-only', (string) $migration->last_error);
        $this->assertStringNotContainsString('screen-length', (string) $migration->last_error);
    }

    public function test_preflight_blocks_when_source_secret_cannot_be_verified(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $migration = $this->confirmedMigration();
        $mock = Mockery::mock(MikrotikService::class);
        $mock->shouldReceive('connect')->andThrow(new \InvalidArgumentException("Parameter 'host' has wrong type 'NULL' but should be 'string'"));
        $this->app->instance(MikrotikService::class, $mock);

        $service = app(CustomerMigrationService::class);
        $preflight = new \ReflectionMethod($service, 'deviceActionPreflight');
        $preflight->setAccessible(true);
        $result = $preflight->invoke($service, $migration);

        $this->assertFalse($result['eligible']);
        $this->assertContains('old_pppoe_secret_unverified', $result['reasons']);
        $this->assertContains('old_pppoe_secret_unverified', $result['warnings']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('old_pppoe_secret_unverified');
        $service->prepareDeviceActionApproval($migration, $admin, 'Block host null test');
        $this->assertSame(0, DeviceActionLog::count());
    }

    public function test_preflight_blocks_when_target_profile_is_not_live(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $migration = $this->confirmedMigration();
        $mock = Mockery::mock(MikrotikService::class);
        $mock->shouldReceive('connect')->andReturnSelf();
        $mock->shouldReceive('getPPPSecret')->with('OLDUSER')->andReturn(['name' => 'OLDUSER']);
        $mock->shouldReceive('getProfiles')->andReturn([
            ['name' => '10M'],
        ]);
        $mock->shouldReceive('disconnect')->andReturnNull();
        $this->app->instance(MikrotikService::class, $mock);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('target_profile_missing');
        app(CustomerMigrationService::class)->prepareDeviceActionApproval(
            $migration,
            $admin,
            'Block target profile test'
        );

        $this->assertSame(0, DeviceActionLog::count());
    }

    public function test_mikrotik_connect_rejects_router_without_host_before_routeros_client(): void
    {
        $router = Router::make([
            'name' => 'Router tanpa host',
            'ip_address' => null,
            'username' => 'admin',
            'password' => Canary::of('pw'),
            'is_active' => true,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('IP address/VPN address');

        (new MikrotikService)->connect($router);
    }

    public function test_wa_canary_authorization_is_scoped_without_opening_global_device_flags(): void
    {
        $service = app(CustomerMigrationService::class);
        $method = new \ReflectionMethod($service, 'migrationWritesAllowed');
        $method->setAccessible(true);
        $flags = ['migration_actions' => false, 'global_read_only' => false, 'olt' => false, 'mikrotik' => false, 'genieacs' => false];

        $this->assertFalse($method->invoke($service, $flags, false));
        Setting::set('migration_wa_only_live_enabled', '1');
        $this->assertTrue($method->invoke($service, $flags, true));
        $this->assertFalse(app(ProductionControlPlaneService::class)->writeEnabled('olt'));
        $this->assertFalse(app(ProductionControlPlaneService::class)->writeEnabled('mikrotik'));
    }

    public function test_legacy_auto_execute_settings_cannot_queue_an_automatic_migration(): void
    {
        Setting::set(GuidedMigrationLookupService::FEATURE_FLAG, '1');
        Setting::set(GuidedMigrationLookupService::ALLOWED_GROUP_JIDS, 'activation@g.us');
        Setting::set(
            GuidedMigrationLookupService::ALLOWED_SENDER_JIDS,
            '6281200000011@s.whatsapp.net,6281200000012@s.whatsapp.net'
        );
        Setting::set('migration_wa_only_live_enabled', '1');
        Setting::set('migration_wa_auto_execute_enabled', '1');
        $migration = $this->confirmedMigration([
            'status' => CustomerMigration::STATUS_SYNCED,
            'synced_at' => now(),
        ]);
        $ledger = DeviceActionLog::create([
            'domain' => 'migration',
            'target_type' => 'customer_migration',
            'target_id' => (string) $migration->id,
            'action' => 'migration-device-action-dry-run',
            'status' => 'verified',
            'idempotency_key' => 'test-canary',
            'payload_hash' => hash('sha256', 'test-canary'),
            'payload' => [],
            'preflight' => [],
            'result' => ['authorization_mode' => 'wa_only_limited_canary'],
            'requested_at' => now(),
            'verified_at' => now(),
        ]);
        $migration->meta = array_merge((array) $migration->meta, [
            'device_action_approval' => ['status' => 'dry_run_ready', 'ledger_id' => $ledger->id],
        ]);
        $migration->save();
        Queue::fake([ExecuteCustomerMigrationWaJob::class]);
        $service = app(CustomerMigrationService::class);
        $dispatch = new \ReflectionMethod($service, 'dispatchWaExecution');
        $dispatch->setAccessible(true);
        $dispatch->invoke($service, [
            'group_jid' => 'activation@g.us',
            'sender_jid' => '6281200000011@s.whatsapp.net',
        ], $migration->id, true, 'technician_unique_auto');

        Queue::assertNothingPushed();
        $this->assertNull(data_get($migration->fresh()->meta, 'wa_live_execution_queued'));
    }

    public function test_post_execute_preflight_does_not_overwrite_original_onu_history(): void
    {
        $migration = $this->confirmedMigration();
        $customer = $migration->customer;
        $customer->forceFill([
            'onu_serial' => 'FHTTNEW123',
            'olt_port_label' => '1/1/1:10',
        ])->save();

        $method = new \ReflectionMethod(app(CustomerMigrationService::class), 'primeMigrationFromCustomer');
        $method->setAccessible(true);
        $method->invoke(app(CustomerMigrationService::class), $migration, $customer->fresh());

        $migration->refresh();
        $this->assertSame('OLD_SN', $migration->old_sn);
        $this->assertSame('OLDREF', $migration->old_onu_ref);
        $this->assertSame($customer->olt_id, $migration->old_olt_id);
    }

    public function test_migration_acs_tag_uses_bounded_retry_helper_when_device_has_not_informed(): void
    {
        Queue::fake();
        $migration = $this->confirmedMigration();
        $genie = Mockery::mock(GenieAcsService::class);
        $genie->shouldReceive('resolvePreferredTag')->once()->andReturn('Pelanggan Migrasi');
        $genie->shouldReceive('ensureTagAfterProvision')
            ->once()
            ->with('FHTTNEW123', 'Pelanggan Migrasi')
            ->andReturn(['pushed' => false, 'pending' => true, 'queued' => true]);
        $this->app->instance(GenieAcsService::class, $genie);

        $service = app(CustomerMigrationService::class);
        $method = new \ReflectionMethod($service, 'syncTr069Tag');
        $method->setAccessible(true);
        $method->invoke($service, $migration, $migration->customer);

        $this->assertSame(true, data_get($migration->fresh()->meta, 'genieacs_tag.queued'));
    }

    public function test_synced_migration_preflight_ignores_stale_excel_review_blocker(): void
    {
        $migration = $this->confirmedMigration([
            'status' => CustomerMigration::STATUS_NEEDS_REVIEW,
            'synced_at' => now(),
            'new_onu_ref' => '1/1/1:28',
            'meta' => [
                'migration_review' => [
                    'match_status' => 'needs_review',
                    'reasons' => ['excel_confirm_missing'],
                ],
            ],
        ]);
        $migration->customer->forceFill([
            'onu_serial' => 'FHTTNEW123',
            'olt_port_label' => '1/1/1:28',
            'ktp_photo_url' => 'https://ebilling.example.test/storage/migrasi/2026/07/ktp.jpg',
        ])->save();
        $this->fakeMikrotikSecretFound();

        $service = app(CustomerMigrationService::class);
        $method = new \ReflectionMethod($service, 'deviceActionPreflight');
        $method->setAccessible(true);
        $preflight = $method->invoke($service, $migration->fresh());

        $this->assertTrue($preflight['eligible']);
        $this->assertNotContains('not_confirmed_pending_approval', $preflight['reasons']);
        $migration->refresh();
        $this->assertSame(CustomerMigration::STATUS_NEEDS_REVIEW, $migration->status);
        $this->assertNotNull($migration->synced_at);
    }

    public function test_prepare_device_action_allows_unknown_old_onu_in_dry_run(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $migration = $this->confirmedMigration([
            'old_sn' => null,
            'old_onu_ref' => null,
        ]);
        $migration->customer->forceFill([
            'onu_serial' => null,
            'olt_port_label' => null,
        ])->save();
        $this->fakeMikrotikSecretFound();

        $this->actingAs($admin)
            ->post(route('customer-migrations.device-action.prepare', $migration), [
                'reason' => 'Operator dry-run data lama belum lengkap',
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $ledger = DeviceActionLog::firstOrFail();
        $this->assertSame('dry_run', $ledger->status);
        $this->assertContains('old_sn_unknown', $ledger->payload['dry_run_diff']['unknown_or_skipped']);
        $this->assertContains('old_onu_ref_unknown', $ledger->payload['dry_run_diff']['unknown_or_skipped']);
        $this->assertSame('unknown', $ledger->payload['dry_run_diff']['olt']['old_sn']);
        $this->assertSame('unknown', $ledger->payload['dry_run_diff']['olt']['old_onu_ref']);
    }

    public function test_prepare_device_action_plans_pppoe_rename_only_when_odp_changes(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $migration = $this->confirmedMigration([
            'pppoe_user' => 'USERC_RT03_RW15_6',
            'odp' => 'ODP-KALIREJO-7.1',
        ]);
        $migration->customer->forceFill([
            'pppoe_user' => 'USERC_RT03_RW15_6',
            'odp_name' => 'ODP-KALIREJO-6',
            'ktp_photo_url' => null,
        ])->save();
        $this->fakeMikrotikSecretFound('USERC_RT03_RW15_6');

        $this->actingAs($admin)
            ->post(route('customer-migrations.device-action.prepare', $migration), [
                'reason' => 'Operator cek ODP baru sebelum live',
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $payload = DeviceActionLog::firstOrFail()->payload;
        $plan = $payload['dry_run_diff']['mikrotik']['pppoe_plan'];

        $this->assertSame('rename_for_new_odp', $plan['username_action']);
        $this->assertSame('USERC_RT03_RW15_7.1', $plan['target_username']);
        $this->assertTrue($plan['odp_changed']);
        $this->assertSame('set_target_profile', $plan['profile_action']);
        $this->assertSame('preserve_existing', $plan['password_action']);
        $this->assertSame('STARTLITE/200M', $payload['target_contract']['profile']);
        $this->assertSame('request_upload_from_technician', $payload['dry_run_diff']['customer']['ktp']['action']);
        $this->assertSame('OLD_SN', $migration->customer->fresh()->onu_serial);
    }

    public function test_prepare_device_action_renames_numeric_odp_suffix_and_preserves_password(): void
    {
        $migration = $this->confirmedMigration([
            'pppoe_user' => 'USERAN_RT7_RW3_03',
            'odp' => '05',
        ]);
        $migration->customer->forceFill([
            'pppoe_user' => 'USERAN_RT7_RW3_03',
            'odp_name' => '03',
            'pppoe_password' => 'canary-pw',
        ])->save();

        $service = app(CustomerMigrationService::class);
        $method = new \ReflectionMethod($service, 'pppoeMigrationPlan');
        $method->setAccessible(true);
        $plan = $method->invoke($service, $migration->fresh(), [
            'customer_before' => [
                'pppoe_user' => 'USERAN_RT7_RW3_03',
                'odp_name' => '03',
            ],
        ]);

        $this->assertSame('USERAN_RT7_RW3_05', $plan['target_username']);
        $this->assertTrue($plan['odp_changed']);
        $this->assertSame('preserve_existing', $plan['password_action']);
        $this->assertSame('canary-pw', $migration->customer->fresh()->pppoe_password);
    }

    public function test_live_ready_pppoe_step_renames_secret_updates_profile_comment_and_customer(): void
    {
        $migration = $this->confirmedMigration([
            'pppoe_user' => 'USERC_RT03_RW15_6',
            'odp' => 'ODP-KALIREJO-7.1',
        ]);
        $customer = $migration->customer;
        $customer->forceFill([
            'pppoe_user' => 'USERC_RT03_RW15_6',
            'odp_name' => 'ODP-KALIREJO-6',
            'mikrotik_profile' => '10M',
        ])->save();
        $targetPackage = Package::create([
            'code' => 'STARTLITE-200',
            'name' => '200M STARTLITE',
            'price' => 200000,
            'mikrotik_profile' => '200M+ STARTLITE',
            'router_id' => $migration->router_id,
        ]);

        $mock = Mockery::mock(MikrotikService::class);
        $mock->shouldReceive('connect')->once()->andReturnSelf();
        $mock->shouldReceive('getProfiles')->once()->andReturn([
            ['name' => '200M+ STARTLITE'],
        ]);
        $mock->shouldReceive('renamePPPSecret')->once()->withArgs(function (...$args) {
            $old = $args['oldUsername'] ?? $args[0] ?? null;
            $new = $args['newUsername'] ?? $args[1] ?? null;
            $profile = $args['profile'] ?? $args[3] ?? null;
            $comment = $args['comment'] ?? $args[6] ?? null;

            return $old === 'USERC_RT03_RW15_6'
                && $new === 'USERC_RT03_RW15_7.1'
                && ($args['password'] ?? null) === null
                && $profile === '200M+ STARTLITE'
                && str_contains((string) $comment, 'migrasi');
        })->andReturn(['success' => true, 'message' => 'Secret renamed', 'secret_id' => '*1']);
        $mock->shouldReceive('getPPPSecret')->once()->with('USERC_RT03_RW15_7.1')->andReturn([
            'name' => 'USERC_RT03_RW15_7.1',
            'password' => Canary::of('live'),
        ]);
        $mock->shouldReceive('disconnect')->once()->andReturnNull();
        $this->app->instance(MikrotikService::class, $mock);

        $service = app(CustomerMigrationService::class);
        $method = new \ReflectionMethod($service, 'applyMigrationPppoeSecret');
        $method->setAccessible(true);
        $result = $method->invoke($service, $migration->fresh(), $customer->fresh(), $migration->router);

        $migration->refresh();
        $customer->refresh();

        $this->assertTrue($result['ok']);
        $this->assertSame('USERC_RT03_RW15_7.1', $migration->pppoe_user);
        $this->assertSame('USERC_RT03_RW15_7.1', $customer->pppoe_user);
        $this->assertSame('canary-live', $customer->pppoe_password);
        $this->assertSame('200M+ STARTLITE', $customer->mikrotik_profile);
        $this->assertSame($targetPackage->id, $customer->package_id);
        $this->assertSame('ODP-KALIREJO-7.1', $customer->odp_name);
    }

    public function test_cross_router_pppoe_step_copies_password_allocates_target_profile_and_disables_source(): void
    {
        $migration = $this->confirmedMigration([
            'pppoe_user' => 'USERC_RT03_RW15_6',
            'odp' => 'ODP-KALIREJO-7.1',
        ]);
        $migration->customer->forceFill([
            'pppoe_user' => 'USERC_RT03_RW15_6',
            'odp_name' => 'ODP-KALIREJO-6',
        ])->save();
        $source = $migration->router;
        $target = Router::create([
            'name' => 'Skynet-RA-OLT',
            'ip_address' => '127.0.0.2',
            'username' => 'admin',
            'password' => Canary::of('pw'),
            'is_active' => true,
        ]);
        $targetOlt = Olt::create([
            'name' => 'OLT RA FiberHome',
            'code' => 'OLT-RA-FH',
            'vendor' => 'fiberhome',
            'router_id' => $target->id,
        ]);
        Package::create([
            'code' => 'STARTLITE-RA',
            'name' => '200M STARTLITE',
            'price' => 200000,
            'mikrotik_profile' => '200M+ STARTLITE',
            'router_id' => $target->id,
        ]);
        $migration->forceFill([
            'old_router_id' => $source->id,
            'router_id' => $target->id,
            'new_olt_id' => $targetOlt->id,
        ])->save();

        $mock = Mockery::mock(MikrotikService::class);
        $mock->shouldReceive('connect')->times(3)->andReturnSelf();
        $mock->shouldReceive('disconnect')->times(3)->andReturnNull();
        $mock->shouldReceive('getPPPSecret')->with('USERC_RT03_RW15_6')->andReturn([
            '.id' => '*old',
            'name' => 'USERC_RT03_RW15_6',
            'password' => Canary::of('source'),
            'disabled' => 'false',
        ]);
        $mock->shouldReceive('getProfiles')->once()->andReturn([
            ['name' => 'STARTLITE'],
        ]);
        $mock->shouldReceive('getPPPSecret')->with('USERC_RT03_RW15_7.1')->andReturn(
            null,
            [
                '.id' => '*new',
                'name' => 'USERC_RT03_RW15_7.1',
                'password' => Canary::of('source'),
                'profile' => 'STARTLITE',
                'local-address' => '198.18.9.1',
                'remote-address' => '198.18.30.10',
            ]
        );
        $mock->shouldReceive('getPPPSecretsWithAddresses')->once()->andReturn([]);
        $mock->shouldReceive('addPPPSecret')->once()->withArgs(function (...$args): bool {
            $username = $args['username'] ?? $args[0] ?? null;
            $password = $args['password'] ?? $args[1] ?? null;
            $profile = $args['profile'] ?? $args[2] ?? null;

            return $username === 'USERC_RT03_RW15_7.1'
                && $password === 'canary-source'
                && $profile === 'STARTLITE';
        })->andReturn([
            'success' => true,
            'pushed' => true,
            'message' => 'PPPoE secret added',
            'secret_id' => '*new',
        ]);
        $mock->shouldReceive('disablePPPSecret')->once()->with('USERC_RT03_RW15_6')->andReturn([
            'success' => true,
            'message' => 'Secret source disabled',
            'secret_id' => '*old',
        ]);
        $this->app->instance(MikrotikService::class, $mock);

        $service = app(CustomerMigrationService::class);
        $method = new \ReflectionMethod($service, 'applyMigrationPppoeSecret');
        $method->setAccessible(true);
        $result = $method->invoke($service, $migration->fresh(), $migration->customer->fresh(), $target);

        $migration->refresh();
        $customer = $migration->customer->fresh();

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['cross_router']);
        $this->assertSame($source->id, $result['source_router']['id']);
        $this->assertSame($target->id, $result['target_router']['id']);
        $this->assertSame('STARTLITE', $result['profile']);
        $this->assertSame('source_router', $result['password_source']);
        $this->assertSame($target->id, $customer->router_id);
        $this->assertSame('198.18.30.10', $customer->pppoe_remote_address);
        $this->assertSame('canary-source', $customer->pppoe_password);
    }

    public function test_cross_router_migration_allocates_ip_from_site_scoped_target_pool(): void
    {
        [$migration, $target] = $this->krianOltCrossRouterFixture();
        $this->krianOltPoolBlock($target, 'krian');
        Setting::set('psb_sites', json_encode([['key' => 'krian', 'router_id' => $target->id]]));

        $mock = $this->krianOltMikrotikMock(expectTargetWrite: true);
        $mock->shouldReceive('addPPPSecret')->once()->withArgs(function (...$args): bool {
            return ($args['localAddress'] ?? $args[3] ?? null) === '198.19.1.1'
                && ($args['remoteAddress'] ?? $args[4] ?? null) === '198.19.1.2';
        })->andReturn(['success' => true, 'pushed' => true, 'message' => 'PPPoE secret added', 'secret_id' => '*new']);
        $this->app->instance(MikrotikService::class, $mock);

        $result = $this->invokeApplyMigrationPppoeSecret($migration, $target);

        $this->assertTrue($result['ok']);
        $this->assertSame('target_allocator', $result['target_ip_source']);
        $this->assertSame('target_allocator', data_get($migration->fresh()->meta, 'mikrotik_migration.target_ip_source'));
    }

    public function test_cross_router_migration_fails_closed_when_target_pool_does_not_match(): void
    {
        [$migration, $target] = $this->krianOltCrossRouterFixture();
        // Block scoped to another site: allocator returns null for this target.
        $this->krianOltPoolBlock($target, 'other-site');
        Setting::set('psb_sites', json_encode([['key' => 'krian', 'router_id' => $target->id]]));

        $mock = $this->krianOltMikrotikMock(expectTargetWrite: false);
        $mock->shouldNotReceive('addPPPSecret');
        $mock->shouldNotReceive('disablePPPSecret');
        $this->app->instance(MikrotikService::class, $mock);

        try {
            $this->invokeApplyMigrationPppoeSecret($migration, $target);
            $this->fail('Migration must stop when the target pool yields no address.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('IP pool router Skynet-Krian-OLT tidak cocok', $e->getMessage());
        }

        $this->assertNotSame($target->id, $migration->customer->fresh()->router_id);
    }

    /** @return array{0: CustomerMigration, 1: Router} */
    private function krianOltCrossRouterFixture(): array
    {
        $migration = $this->confirmedMigration(['pppoe_user' => 'KRIAN_USER_1', 'odp' => 'ODP-KRIAN-2']);
        $migration->customer->forceFill(['pppoe_user' => 'KRIAN_USER_1', 'odp_name' => 'ODP-KRIAN-1'])->save();
        $target = Router::create([
            'name' => 'Skynet-Krian-OLT',
            'ip_address' => '127.0.0.3',
            'username' => 'admin',
            'password' => Canary::of('pw'),
            'is_active' => true,
        ]);
        // Krian targets preserve the customer's current package
        // (migration_preserve_package_router_needles=krian), so the target
        // profile comes from that package.
        Package::query()->whereKey($migration->customer->package_id)->update(['mikrotik_profile' => '10MB']);
        $migration->forceFill([
            'old_router_id' => $migration->router_id,
            'router_id' => $target->id,
        ])->save();

        return [$migration->fresh(), $target];
    }

    private function krianOltPoolBlock(Router $router, string $siteKey): void
    {
        PppoeAddressPoolBlock::create([
            'router_id' => $router->id,
            'site_profile_key' => $siteKey,
            'block_name' => 'KRIAN-OLT POOL 198.19.1.0/24',
            'block_index' => 1,
            'address_format' => 'full_ipv4',
            'prefix_template' => '198.19.1',
            'local_address' => '198.19.1.1',
            'remote_start' => '198.19.1.2',
            'remote_end' => '198.19.1.254',
            'allocator_mode' => 'last_remote_pointer',
            'priority' => 100,
            'is_active' => true,
            'warning_threshold' => 10,
        ]);
    }

    private function krianOltMikrotikMock(bool $expectTargetWrite): MockInterface
    {
        $mock = Mockery::mock(MikrotikService::class);
        $mock->shouldReceive('connect')->andReturnSelf();
        $mock->shouldReceive('disconnect')->andReturnNull();
        $mock->shouldReceive('getPPPSecret')->with('KRIAN_USER_1')->andReturn([
            '.id' => '*old', 'name' => 'KRIAN_USER_1', 'password' => Canary::of('source'), 'disabled' => 'false',
        ]);
        $mock->shouldReceive('getProfiles')->andReturn([['name' => '10MB']]);
        $mock->shouldReceive('getPPPSecretsWithAddresses')->once()->andReturn([]);
        if ($expectTargetWrite) {
            $mock->shouldReceive('getPPPSecret')->with(Mockery::not('KRIAN_USER_1'))->andReturn(null, [
                '.id' => '*new', 'name' => 'KRIAN_USER_1', 'password' => Canary::of('source'), 'profile' => '10MB',
                'local-address' => '198.19.1.1', 'remote-address' => '198.19.1.2',
            ]);
            $mock->shouldReceive('disablePPPSecret')->once()->andReturn(['success' => true, 'message' => 'Secret source disabled']);
        } else {
            $mock->shouldReceive('getPPPSecret')->with(Mockery::not('KRIAN_USER_1'))->andReturn(null);
        }

        return $mock;
    }

    private function invokeApplyMigrationPppoeSecret(CustomerMigration $migration, Router $target): array
    {
        $service = app(CustomerMigrationService::class);
        $method = new \ReflectionMethod($service, 'applyMigrationPppoeSecret');
        $method->setAccessible(true);

        return $method->invoke($service, $migration->fresh(), $migration->customer->fresh(), $target);
    }

    public function test_existing_new_onu_marks_olt_ok_without_provisioning_again(): void
    {
        $this->ensureOltOnusTestTable();

        $migration = $this->confirmedMigration([
            'new_sn' => 'FHTTNEW123',
            'new_pon_port' => null,
            'new_onu_id' => null,
            'new_onu_ref' => null,
        ]);
        OltOnu::create([
            'olt_id' => $migration->new_olt_id,
            'onu_ref' => '1/1/1:20',
            'pon_port' => '1/1/1',
            'serial_number' => 'FHTTNEW123',
            'onu_name' => 'OLDUSER_RT01_RW01_L1',
            'state' => 'online',
            'phase_state' => 'O5',
        ]);

        $service = app(CustomerMigrationService::class);
        $method = new \ReflectionMethod($service, 'provisionNewOnu');
        $method->setAccessible(true);
        $method->invoke($service, $migration->fresh(), $migration->newOlt, $migration->router, $migration->customer);

        $migration->refresh();

        $this->assertSame(CustomerMigration::STATUS_OLT_OK, $migration->status);
        $this->assertSame('1/1/1:20', $migration->new_onu_ref);
        $this->assertSame(20, $migration->new_onu_id);
        $this->assertTrue($migration->meta['olt_existing_onu']['ok']);
        $this->assertSame('olt_onus_snapshot', $migration->meta['olt_existing_onu']['source']);
    }

    public function test_existing_new_onu_must_be_online_before_olt_ok(): void
    {
        $this->ensureOltOnusTestTable();
        $migration = $this->confirmedMigration([
            'new_sn' => 'FHTTOFFLINE1',
            'new_pon_port' => null,
            'new_onu_id' => null,
            'new_onu_ref' => null,
        ]);
        OltOnu::create([
            'olt_id' => $migration->new_olt_id,
            'onu_ref' => '1/1/1:21',
            'pon_port' => '1/1/1',
            'serial_number' => 'FHTTOFFLINE1',
            'onu_name' => 'OLDUSER_OFFLINE',
            'state' => 'offline',
            'phase_state' => 'LOS',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('stop sebelum hapus ONU lama');

        $service = app(CustomerMigrationService::class);
        $method = new \ReflectionMethod($service, 'provisionNewOnu');
        $method->setAccessible(true);
        $method->invoke($service, $migration->fresh(), $migration->newOlt, $migration->router, $migration->customer);
    }

    public function test_local_old_onu_purge_does_not_rewrite_large_olt_snapshot(): void
    {
        $this->ensureOltOnusTestTable();
        $migration = $this->confirmedMigration();
        $olt = $migration->newOlt;
        $snapshot = [
            'meta' => ['collected_at' => now()->toIso8601String()],
            'onus' => [
                ['onu_ref' => '1/2/5:48', 'serial_number' => 'OLD_SN'],
                ['onu_ref' => '1/2/5:49', 'serial_number' => 'KEEP_SN'],
            ],
        ];
        $olt->forceFill(['last_gpon_snapshot' => $snapshot])->save();
        OltOnu::create([
            'olt_id' => $olt->id,
            'onu_ref' => '1/2/5:48',
            'pon_port' => '1/2/5',
            'serial_number' => 'OLD_SN',
        ]);

        $service = app(CustomerMigrationService::class);
        $method = new \ReflectionMethod($service, 'purgeOldOnuLocal');
        $method->setAccessible(true);
        $purged = $method->invoke($service, $olt->fresh(), '1/2/5:48');

        $this->assertSame(1, $purged);
        $this->assertDatabaseMissing('olt_onus', [
            'olt_id' => $olt->id,
            'onu_ref' => '1/2/5:48',
        ]);
        $this->assertSame($snapshot, $olt->fresh()->last_gpon_snapshot);
    }

    public function test_migration_last_error_drops_sql_payload_and_stays_bounded(): void
    {
        $migration = $this->confirmedMigration();
        $migration->last_error = 'ONU baru OK, tapi cache gagal: SQLSTATE[HY000] lock timeout, SQL: update olts set last_gpon_snapshot = '
            .str_repeat('X', 70000);
        $migration->save();

        $error = (string) $migration->fresh()->last_error;

        $this->assertLessThanOrEqual(4000, mb_strlen($error));
        $this->assertStringNotContainsString('SQL: update olts', $error);
        $this->assertStringContainsString('lock timeout', $error);
    }

    public function test_pppoe_rename_rejects_existing_target_when_old_secret_still_exists(): void
    {
        $service = new class extends MikrotikService
        {
            protected function ensureConnected(): void {}

            protected function findPPPSecret(string $username): ?array
            {
                return match ($username) {
                    'OLDUSER', 'NEWUSER' => ['.id' => '*'.$username, 'name' => $username],
                    default => null,
                };
            }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Secret target');

        $service->renamePPPSecret('OLDUSER', 'NEWUSER');
    }

    public function test_prepare_rejects_unconfirmed_migration(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $migration = $this->confirmedMigration([
            'meta' => ['migration_review' => ['match_status' => 'needs_review', 'reasons' => ['excel_match_multiple']]],
        ]);
        $this->fakeMikrotikSecretFound();

        $this->actingAs($admin)
            ->post(route('customer-migrations.device-action.prepare', $migration), [
                'reason' => 'Operator confirm migrasi dari staging',
            ])
            // Error path kontrak H1: reject dilempar via errors.migration_action
            // (di-share otomatis sebagai props.errors oleh Inertia) tanpa flash sukses.
            ->assertSessionHasErrors('migration_action')
            ->assertSessionMissing('success');

        $this->assertSame(0, DeviceActionLog::count());
    }

    private function confirmedMigration(array $overrides = []): CustomerMigration
    {
        $package = Package::create(['code' => 'MIG-ACTION', 'name' => 'Migrasi Action', 'price' => 150000]);
        $router = Router::create([
            'name' => 'RA',
            'ip_address' => '127.0.0.1',
            'username' => 'admin',
            'password' => Canary::of('pw'),
            'is_active' => true,
        ]);
        $olt = Olt::create([
            'name' => 'OLT RA',
            'code' => 'OLT-RA',
            'vendor' => 'zte_c300',
            'router_id' => $router->id,
        ]);
        $customer = Customer::create([
            'code' => 'MIG-ACTION-001',
            'name' => 'Pelanggan Migrasi',
            'phone' => '081234567890',
            'address' => 'RW 4',
            'package_id' => $package->id,
            'pppoe_user' => 'OLDUSER',
            'onu_serial' => 'OLD_SN',
            'olt_port_label' => 'OLDREF',
            'router_id' => $router->id,
            'olt_id' => $olt->id,
            'status' => 'active',
        ]);

        return CustomerMigration::create(array_merge([
            'status' => CustomerMigration::STATUS_NEEDS_REVIEW,
            'source' => 'wa',
            'customer_id' => $customer->id,
            'pppoe_user' => 'OLDUSER',
            'full_name' => 'Pelanggan Migrasi',
            'old_router_id' => $router->id,
            'old_olt_id' => $olt->id,
            'old_onu_ref' => 'OLDREF',
            'old_sn' => 'OLD_SN',
            'router_id' => $router->id,
            'new_olt_id' => $olt->id,
            'new_pon_port' => '1/1/1',
            'new_onu_id' => 10,
            'new_onu_ref' => '1/1/1:10',
            'new_sn' => 'FHTTNEW123',
            'meta' => [
                'migration_review' => ['match_status' => 'confirmed_pending_approval', 'reasons' => ['operator_approval_required']],
                'replacement_confirmation' => [
                    'status' => 'confirmed',
                    'confirmed_at' => now()->toIso8601String(),
                    'expires_at' => now()->addHour()->toIso8601String(),
                ],
            ],
        ], $overrides));
    }

    private function fakeMikrotikSecretFound(string $username = 'OLDUSER'): void
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
