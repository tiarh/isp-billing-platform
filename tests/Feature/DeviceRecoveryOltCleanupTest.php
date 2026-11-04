<?php

namespace Tests\Feature;

use App\Jobs\DeleteDismantledOnuJob;
use App\Models\DeviceRecoveryCase;
use App\Models\DeviceRecoveryEvent;
use App\Models\DeviceRecoveryItem;
use App\Models\Olt;
use App\Models\Setting;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\DeviceRecoveryOltCleanupService;
use App\Services\DeviceRecoveryService;
use App\Services\HuaweiOltOnlyProvisioningService;
use App\Services\MikrotikService;
use App\Services\OltZteC300Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\StubsLifecycleGuard;
use Tests\Fixtures\ZteOnuState;
use Tests\TestCase;

#[Group('blocking-core')]
class DeviceRecoveryOltCleanupTest extends TestCase
{
    use RefreshDatabase;
    use StubsLifecycleGuard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allowLifecycleGuard();
        $this->allowCustomerLifecycleGuard();
        $this->seedPackage();
    }

    private function service(): DeviceRecoveryOltCleanupService
    {
        return app(DeviceRecoveryOltCleanupService::class);
    }

    private function seedOlt(int $id, string $vendor = 'zte_c300', string $name = 'OLT KRIAN'): void
    {
        DB::table('olts')->insert([
            'id' => $id,
            'name' => $name,
            'code' => 'OLT-'.$id,
            'vendor' => $vendor,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedPackage(int $id = 1): void
    {
        DB::table('packages')->insert([
            'id' => $id,
            'code' => 'PKG-'.$id,
            'name' => 'Paket Test',
            'price' => 100000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedCustomer(int $id, string $code, string $serial, ?int $oltId, string $status = 'terminated'): void
    {
        DB::table('customers')->insert([
            'id' => $id,
            'code' => $code,
            'name' => 'Customer '.$id,
            'phone' => '08120000'.str_pad((string) $id, 4, '0', STR_PAD_LEFT),
            'address' => 'Test Address '.$id,
            'pppoe_user' => 'user'.$id,
            'package_id' => 1,
            'onu_serial' => $serial,
            'olt_id' => $oltId,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedOnu(int $oltId, string $serial, string $onuRef, string $name = 'ONU'): void
    {
        DB::table('olt_onus')->insert([
            'olt_id' => $oltId,
            'serial_number' => $serial,
            'onu_ref' => $onuRef,
            'onu_name' => $name,
            'pon_port' => '1/2/1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedReceivedCase(int $customerId): DeviceRecoveryCase
    {
        $actor = User::factory()->create();
        $ticketId = DB::table('support_tickets')->insertGetId([
            'code' => 'TCK-'.$customerId,
            'customer_id' => $customerId,
            'status' => 'closed',
            'type' => 'other',
            'priority' => 'normal',
            'subject' => 'Dismantle '.$customerId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $caseId = DB::table('device_recovery_cases')->insertGetId([
            'ticket_id' => $ticketId,
            'customer_id' => $customerId,
            'area_id' => null,
            'status' => DeviceRecoveryService::STATUS_RECEIVED,
            'created_by' => $actor->id,
            'received_at' => now(),
            'version' => 5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DeviceRecoveryCase::query()->findOrFail($caseId);
    }

    private function fakeZte(): void
    {
        FakeCleanupZte::$deleted = [];
        FakeCleanupZte::$state = 'DyingGasp';
        $this->app->bind(OltZteC300Service::class, fn () => new class extends OltZteC300Service
        {
            // Live read-back gate: the OLT reports the inventory serial.
            public function liveOnuProbe(Olt $olt, string $onuRef): array
            {
                $serial = DB::table('olt_onus')->where('olt_id', $olt->id)->where('onu_ref', $onuRef)->value('serial_number');
                if (in_array([$olt->id, $onuRef], FakeCleanupZte::$deleted, true)) {
                    $serial = null;
                }

                return ZteOnuState::probe($onuRef, $serial === null ? null : FakeCleanupZte::$state, $serial);
            }

            public function deleteOnu(Olt $olt, string $onuRef): array
            {
                FakeCleanupZte::$deleted[] = [$olt->id, $onuRef];

                return ['success' => true, 'verified_absent' => true];
            }
        });
    }

    public function test_disabled_by_default_records_skip_and_never_deletes(): void
    {
        config(['device-recovery.mode' => 'read_only']);
        $this->seedOlt(9);
        $this->seedCustomer(10, 'CUST-10', 'FHTT1234', 9);
        $this->seedOnu(9, 'FHTT1234', '1/2/1:3');
        $case = $this->seedReceivedCase(10);
        $this->fakeZte();

        $result = $this->service()->deleteForCase($case);

        $this->assertSame('skipped_flag_off', $result['status']);
        $this->assertSame([], FakeCleanupZte::$deleted);
        $case->refresh();
        $this->assertSame('flag_off', $case->meta[DeviceRecoveryOltCleanupService::META_KEY]['reason']);
        $this->assertSame(1, DeviceRecoveryEvent::query()->where('event_type', 'olt_onu_delete')->count());
    }

    public function test_enabled_exact_match_deletes_onu_and_records_event(): void
    {
        config(['device-recovery.mode' => 'write']);
        Setting::set('device_recovery_olt_delete_enabled', '1', 'boolean', 'ops');
        $this->seedOlt(9, 'zte_c300');
        $this->seedCustomer(10, 'CUST-10', 'FHTT1234', 9);
        $this->seedOnu(9, 'FHTT1234', '1/2/1:3');
        $case = $this->seedReceivedCase(10);
        $this->fakeZte();

        $result = $this->service()->deleteForCase($case);

        $this->assertSame('deleted', $result['status']);
        $this->assertSame([[9, '1/2/1:3']], FakeCleanupZte::$deleted);
        $case->refresh();
        $this->assertTrue($case->meta[DeviceRecoveryOltCleanupService::META_KEY]['ok']);
    }

    public function test_serial_in_use_by_another_live_customer_is_skipped(): void
    {
        config(['device-recovery.mode' => 'write']);
        Setting::set('device_recovery_olt_delete_enabled', '1', 'boolean', 'ops');
        $this->seedOlt(9);
        $this->seedCustomer(10, 'CUST-10', 'FHTT1234', 9, 'terminated');
        $this->seedCustomer(11, 'CUST-11', 'FHTT1234', 9, 'active');
        $this->seedOnu(9, 'FHTT1234', '1/2/1:3');
        $case = $this->seedReceivedCase(10);
        $this->fakeZte();

        $result = $this->service()->deleteForCase($case);

        $this->assertSame('skipped_serial_in_use', $result['status']);
        $this->assertSame([], FakeCleanupZte::$deleted);
    }

    public function test_skip_then_real_delete_leaves_two_event_rows(): void
    {
        config(['device-recovery.mode' => 'write']);
        Setting::set('device_recovery_olt_delete_enabled', '1', 'boolean', 'ops');
        $this->seedOlt(9);
        $this->seedCustomer(10, 'CUST-10', 'FHTT1234', 9, 'terminated');
        $this->seedCustomer(11, 'CUST-11', 'FHTT1234', 9, 'active');
        $this->seedOnu(9, 'FHTT1234', '1/2/1:3');
        $case = $this->seedReceivedCase(10);
        $this->fakeZte();

        $this->assertSame('skipped_serial_in_use', $this->service()->deleteForCase($case)['status']);
        DB::table('customers')->where('id', 11)->update(['status' => 'terminated']);
        $this->assertSame('deleted', $this->service()->deleteForCase($case->fresh())['status']);

        $keys = DeviceRecoveryEvent::query()->where('event_type', 'olt_onu_delete')->orderBy('id')->pluck('idempotency_key')->all();
        $this->assertCount(2, $keys, 'the skip must not swallow the event of the real delete');
        $this->assertStringStartsWith('olt-onu-delete-skip:'.$case->id.':serial_in_use:', $keys[0]);
        $this->assertSame('olt-onu-delete:'.$case->id, $keys[1]);
    }

    public function test_real_delete_after_a_legacy_skip_event_under_the_base_key_still_gets_its_own_event(): void
    {
        config(['device-recovery.mode' => 'write']);
        Setting::set('device_recovery_olt_delete_enabled', '1', 'boolean', 'ops');
        $this->seedOlt(9);
        $this->seedCustomer(10, 'CUST-10', 'FHTT1234', 9, 'terminated');
        $this->seedOnu(9, 'FHTT1234', '1/2/1:3');
        $case = $this->seedReceivedCase(10);
        $this->fakeZte();
        // Pre-4b skips were stored under the base key.
        DeviceRecoveryEvent::query()->create([
            'recovery_case_id' => $case->id, 'item_id' => null, 'actor_id' => null, 'event_type' => 'olt_onu_delete',
            'from_status' => 'received', 'to_status' => 'received', 'reason' => 'serial_in_use', 'source' => 'system',
            'idempotency_key' => 'olt-onu-delete:'.$case->id, 'metadata' => ['ok' => false, 'reason' => 'serial_in_use'],
        ]);

        $this->assertSame('deleted', $this->service()->deleteForCase($case)['status']);

        $keys = DeviceRecoveryEvent::query()->where('event_type', 'olt_onu_delete')->orderBy('id')->pluck('idempotency_key')->all();
        $this->assertCount(2, $keys);
        $this->assertStringStartsWith('olt-onu-delete:'.$case->id.':', $keys[1]);
    }

    public function test_onu_not_found_is_skipped(): void
    {
        config(['device-recovery.mode' => 'write']);
        Setting::set('device_recovery_olt_delete_enabled', '1', 'boolean', 'ops');
        $this->seedOlt(9);
        $this->seedCustomer(10, 'CUST-10', 'FHTT9999', 9);
        $this->seedOnu(9, 'FHTT1234', '1/2/1:3');
        $case = $this->seedReceivedCase(10);
        $this->fakeZte();

        $result = $this->service()->deleteForCase($case);

        $this->assertSame('skipped_onu_not_found', $result['status']);
        $this->assertSame([], FakeCleanupZte::$deleted);
    }

    public function test_receive_dispatches_delete_job_when_enabled(): void
    {
        config(['device-recovery.mode' => 'write']);
        Setting::set('device_recovery_olt_delete_enabled', '1', 'boolean', 'ops');
        Bus::fake();

        $actor = User::factory()->create();
        $technician = User::factory()->create(['role' => 'teknisi']);
        DB::table('customers')->insert([
            'id' => 10, 'code' => 'CUST-10', 'name' => 'Cust', 'phone' => '081200000010',
            'address' => 'Test Address', 'pppoe_user' => 'user10', 'package_id' => 1, 'onu_serial' => 'FHTT1234',
            'status' => 'terminated', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('support_tickets')->insert([
            'id' => 20, 'code' => 'TCK-20', 'customer_id' => 10, 'status' => 'closed',
            'type' => 'other', 'priority' => 'normal', 'subject' => 'Dismantle 20',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $ticket = SupportTicket::query()->findOrFail(20);
        DB::table('ticket_activities')->insert([
            'ticket_id' => 20, 'user_id' => $actor->id, 'action' => 'customer_lifecycle_applied',
            'new_value' => json_encode(['lifecycle_action' => 'dismantle']),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $service = app(DeviceRecoveryService::class);
        $case = $service->createFromApprovedDismantle($ticket, $actor);
        $case = $service->assign($case, $actor, $technician->id, null, 'assign-x');
        $case = $service->start($case, $technician, 'start-x');
        $item = DeviceRecoveryItem::query()->where('recovery_case_id', $case->id)->firstOrFail();
        $case = $service->capture($case, $item, $technician, 'FHTT1234', 'good', null, [], 'capture-x');
        $service->receive($case, $actor, 'receive-x');

        Bus::assertDispatched(DeleteDismantledOnuJob::class);
    }

    public function test_receive_does_not_dispatch_delete_job_when_disabled(): void
    {
        config(['device-recovery.mode' => 'read_only']);
        Bus::fake();

        $actor = User::factory()->create();
        $technician = User::factory()->create(['role' => 'teknisi']);
        DB::table('customers')->insert([
            'id' => 10, 'code' => 'CUST-10', 'name' => 'Cust', 'phone' => '081200000010',
            'address' => 'Test Address', 'pppoe_user' => 'user10', 'package_id' => 1, 'onu_serial' => 'FHTT1234',
            'status' => 'terminated', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('support_tickets')->insert([
            'id' => 21, 'code' => 'TCK-21', 'customer_id' => 10, 'status' => 'closed',
            'type' => 'other', 'priority' => 'normal', 'subject' => 'Dismantle 21',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $ticket = SupportTicket::query()->findOrFail(21);
        DB::table('ticket_activities')->insert([
            'ticket_id' => 21, 'user_id' => $actor->id, 'action' => 'customer_lifecycle_applied',
            'new_value' => json_encode(['lifecycle_action' => 'dismantle']),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $service = app(DeviceRecoveryService::class);
        $case = $service->createFromApprovedDismantle($ticket, $actor);
        $case = $service->assign($case, $actor, $technician->id, null, 'assign-y');
        $case = $service->start($case, $technician, 'start-y');
        $item = DeviceRecoveryItem::query()->where('recovery_case_id', $case->id)->firstOrFail();
        $case = $service->capture($case, $item, $technician, 'FHTT1234', 'good', null, [], 'capture-y');
        $service->receive($case, $actor, 'receive-y');

        Bus::assertNotDispatched(DeleteDismantledOnuJob::class);
    }

    public function test_huawei_gadang_build_delete_plan_command_sequence(): void
    {
        $olt = new Olt(['vendor' => 'huawei_ma5608t', 'name' => 'OLT GADANG 3']);
        $olt->id = 11;

        $plan = app(HuaweiOltOnlyProvisioningService::class)->buildDeletePlan($olt, '0/0/7:29', 'FHTT0000A03A');

        $this->assertSame('delete_onu', $plan['operation']);
        $this->assertSame('0/0/7:29', $plan['onu_ref']);
        $this->assertSame(7, $plan['pon_index']);
        $this->assertSame(29, $plan['onu_id']);
        $this->assertContains('config', $plan['core_commands']);
        $this->assertContains('interface gpon 0/0', $plan['core_commands']);
        $this->assertContains('ont delete 7 29', $plan['core_commands']);
        $this->assertContains('save', $plan['optional_commands']);
        $this->assertContains('display ont info 0 0 7 29', $plan['optional_commands']);
    }

    public function test_huawei_vendor_is_supported_and_routes_to_huawei_delete(): void
    {
        config(['device-recovery.mode' => 'write']);
        Setting::set('device_recovery_olt_delete_enabled', '1', 'boolean', 'ops');
        $this->seedOlt(11, 'huawei_ma5608t', 'OLT GADANG 3');
        $this->seedCustomer(10, 'CUST-10', 'FHTT0000A03A', 11);
        $this->seedOnu(11, 'FHTT0000A03A', '0/0/7:29');
        $case = $this->seedReceivedCase(10);

        FakeCleanupHuawei::$deleted = [];
        $this->app->bind(HuaweiOltOnlyProvisioningService::class, fn () => new class(app(MikrotikService::class)) extends HuaweiOltOnlyProvisioningService
        {
            public function onuDetail(Olt $olt, string $onuRef): array
            {
                $gone = in_array([$olt->id, $onuRef], array_map(fn ($d) => [$d[0], $d[1]], FakeCleanupHuawei::$deleted), true);

                return ['onu' => [
                    'serial_number' => $gone ? null : DB::table('olt_onus')->where('olt_id', $olt->id)->where('onu_ref', $onuRef)->value('serial_number'),
                    'state' => 'offline',
                ]];
            }

            public function deleteOnu(Olt $olt, string $onuRef, ?string $serial = null, array $approval = []): array
            {
                FakeCleanupHuawei::$deleted[] = [$olt->id, $onuRef, $serial];

                return ['success' => true, 'verified_absent' => true, 'device_write' => true];
            }
        });

        $result = $this->service()->deleteForCase($case);

        $this->assertSame('deleted', $result['status']);
        $this->assertSame([[11, '0/0/7:29', 'FHTT0000A03A']], FakeCleanupHuawei::$deleted);
    }
}

/**
 * Static capture for the anonymous fake bound in fakeZte().
 */
class FakeCleanupZte
{
    public static array $deleted = [];

    public static ?string $state = 'DyingGasp';
}

class FakeCleanupHuawei
{
    public static array $deleted = [];
}
