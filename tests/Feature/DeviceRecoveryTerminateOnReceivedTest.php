<?php

namespace Tests\Feature;

use App\Jobs\DeleteDismantledOnuJob;
use App\Models\Area;
use App\Models\Customer;
use App\Models\DeviceRecoveryCase;
use App\Models\DeviceRecoveryEvent;
use App\Models\DeviceRecoveryItem;
use App\Models\Olt;
use App\Models\Package;
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
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\StubsLifecycleGuard;
use Tests\Fixtures\ZteOnuState;
use Tests\TestCase;

/**
 * Owner 2026-10-09: device received at the warehouse => customer terminated
 * (unless keep_customer_active), then the guarded OLT ONU cleanup runs on the
 * serial actually collected. Multi-device homes (#278/#171) keep their live ONU.
 */
#[Group('blocking-core')]
class DeviceRecoveryTerminateOnReceivedTest extends TestCase
{
    use RefreshDatabase;
    use StubsLifecycleGuard;

    private DeviceRecoveryService $service;

    private User $admin;

    private User $technician;

    private Area $area;

    private Package $package;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allowLifecycleGuard();
        $this->allowCustomerLifecycleGuard();
        config(['device-recovery.mode' => 'write']);
        Bus::fake();

        $this->service = app(DeviceRecoveryService::class);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->technician = User::factory()->create(['role' => 'teknisi']);
        $this->area = Area::create(['name' => 'Recovery Area', 'code' => 'RCV']);
        $this->package = Package::create(['code' => 'RCV-PKG', 'name' => 'Recovery package', 'price' => 100000]);
        DB::table('olts')->insert([
            'id' => 9, 'name' => 'OLT C300', 'code' => 'OLT-9', 'vendor' => 'zte_c300',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        FakeTerminateZte::$deleted = [];
        FakeTerminateZte::$liveSerial = null;
        $this->app->bind(OltZteC300Service::class, fn () => new class extends OltZteC300Service
        {
            public function liveOnuProbe(Olt $olt, string $onuRef): array
            {
                $serial = FakeTerminateZte::$liveSerial
                    ?? DB::table('olt_onus')->where('olt_id', $olt->id)->where('onu_ref', $onuRef)->value('serial_number');
                if (in_array([$olt->id, $onuRef], FakeTerminateZte::$deleted, true)) {
                    $serial = null;
                }

                return ZteOnuState::probe($onuRef, $serial === null ? null : 'DyingGasp', $serial);
            }

            public function deleteOnu(Olt $olt, string $onuRef): array
            {
                FakeTerminateZte::$deleted[] = [$olt->id, $onuRef];

                return ['success' => true, 'verified_absent' => true];
            }
        });
    }

    public function test_received_terminates_active_isolated_and_inactive_customers_with_audit(): void
    {
        foreach (['active', 'isolated', 'inactive'] as $status) {
            $customer = $this->customer($status, 'FHTT-'.strtoupper($status));
            $pppoe = $customer->pppoe_user;
            $invoicesBefore = DB::table('invoices')->count();

            $case = $this->receivedCase($customer, 'FHTT-'.strtoupper($status));

            $customer->refresh();
            $this->assertSame('terminated', $customer->status, $status);
            $this->assertSame($pppoe, $customer->pppoe_user);
            $this->assertSame($invoicesBefore, DB::table('invoices')->count());
            $this->assertDatabaseHas('activity_log', [
                'subject_id' => $customer->id,
                'event' => 'terminated_device_recovered',
                'description' => DeviceRecoveryService::TERMINATE_ON_RECEIVE_REASON,
            ]);
            $event = DeviceRecoveryEvent::query()
                ->where('recovery_case_id', $case->id)
                ->where('event_type', 'warehouse_received')
                ->firstOrFail();
            $this->assertTrue($event->metadata['customer_terminated']);
            $this->assertSame($status, $event->metadata['customer_from_status']);
        }
    }

    public function test_keep_customer_active_case_never_terminates_customer(): void
    {
        $customer = $this->customer('active', 'FHTT-LIVE-0001');
        $case = $this->openCase($customer);
        $case = $this->service->setKeepCustomerActive($case, $this->admin, true, 'Dua perangkat di rumah', 'keep-'.$case->id);
        $this->assertTrue($case->keep_customer_active);

        $this->collectAndReceive($case, 'FHTT-EXTRA-0002', clearExpected: true);

        $this->assertSame('active', $customer->fresh()->status);
        $this->assertDatabaseMissing('activity_log', ['subject_id' => $customer->id, 'event' => 'terminated_device_recovered']);
    }

    public function test_already_terminated_customer_is_left_untouched_and_receive_retry_is_idempotent(): void
    {
        $customer = $this->customer('terminated', 'FHTT-OLD-0001');
        $case = $this->openCase($customer);
        $case = $this->collectAndReceive($case, 'FHTT-OLD-0001');
        $again = $this->service->receive($case, $this->admin, 'receive-'.$case->id);

        $this->assertSame(DeviceRecoveryService::STATUS_RECEIVED, $again->status);
        $this->assertSame('terminated', $customer->fresh()->status);
        $this->assertDatabaseMissing('activity_log', ['subject_id' => $customer->id, 'event' => 'terminated_device_recovered']);
        $this->assertSame(1, DeviceRecoveryEvent::query()->where('recovery_case_id', $case->id)->where('event_type', 'warehouse_received')->count());
    }

    public function test_keep_active_flag_is_idempotent_audited_and_locked_after_receive(): void
    {
        $customer = $this->customer('active', 'FHTT-FLAG-0001');
        $case = $this->openCase($customer);

        $this->service->setKeepCustomerActive($case, $this->admin, true, null, 'flag-1');
        $this->service->setKeepCustomerActive($case, $this->admin, true, null, 'flag-1');
        $this->service->setKeepCustomerActive($case, $this->admin, true, null, 'flag-2');
        $this->assertSame(1, DeviceRecoveryEvent::query()->where('event_type', 'keep_customer_active_set')->count());

        $this->service->setKeepCustomerActive($case, $this->admin, false, null, 'flag-3');
        $this->assertFalse($case->fresh()->keep_customer_active);
        $case = $this->collectAndReceive($case->fresh(), 'FHTT-FLAG-0001');

        $this->expectException(ValidationException::class);
        $this->service->setKeepCustomerActive($case, $this->admin, true, null, 'flag-4');
    }

    public function test_keep_active_route_requires_admin_and_write_mode(): void
    {
        $customer = $this->customer('active', 'FHTT-ROUTE-0001');
        $case = $this->openCase($customer);
        $payload = ['keep_customer_active' => true, 'idempotency_key' => 'route-keep-1'];

        $this->actingAs($this->technician)
            ->post(route('device-recovery.keep-customer-active', $case), $payload)
            ->assertForbidden();
        $this->assertFalse($case->fresh()->keep_customer_active);

        $this->actingAs($this->admin)
            ->post(route('device-recovery.keep-customer-active', $case), $payload)
            ->assertRedirect();
        $this->assertTrue($case->fresh()->keep_customer_active);

        config(['device-recovery.mode' => 'read_only']);
        $this->actingAs($this->admin)
            ->post(route('device-recovery.keep-customer-active', $case), ['keep_customer_active' => false, 'idempotency_key' => 'route-keep-2'])
            ->assertStatus(423);
        $this->assertTrue($case->fresh()->keep_customer_active);
    }

    // Termination happens inside receive()'s transaction; the cleanup job is
    // dispatched only after it commits, so the job always sees `terminated`.
    public function test_customer_is_terminated_before_cleanup_job_is_dispatched(): void
    {
        $this->enableCleanup();
        $customer = $this->customer('isolated', 'FHTT-ORDER-0001');

        $this->receivedCase($customer, 'FHTT-ORDER-0001');

        Bus::assertDispatched(DeleteDismantledOnuJob::class, function (DeleteDismantledOnuJob $job) use ($customer): bool {
            return (int) $job->case->customer_id === (int) $customer->id
                && Customer::query()->find($customer->id)?->status === 'terminated';
        });
    }

    public function test_cleanup_deletes_the_collected_serial_after_termination(): void
    {
        $this->enableCleanup();
        $customer = $this->customer('active', 'FHTT-GONE-0001');
        $this->onu('FHTT-GONE-0001', '1/2/1:3');

        $case = $this->receivedCase($customer, 'FHTT-GONE-0001');
        $result = app(DeviceRecoveryOltCleanupService::class)->deleteForCase($case);

        $this->assertSame('deleted', $result['status']);
        $this->assertSame('collected_item', $result['serial_source']);
        $this->assertSame([[9, '1/2/1:3']], FakeTerminateZte::$deleted);
    }

    public function test_multi_device_case_never_deletes_the_live_customer_onu(): void
    {
        $this->enableCleanup();
        $customer = $this->customer('active', 'FHTT-LIVE-0278');
        $this->onu('FHTT-LIVE-0278', '1/2/1:5');
        $case = $this->openCase($customer);
        $this->service->setKeepCustomerActive($case, $this->admin, true, 'Terpasang 2, ambil satu', 'keep-278');

        // Technician scans the live ONU by mistake: the customer's own serial.
        $case = $this->collectAndReceive($case->fresh(), 'FHTT-LIVE-0278', clearExpected: true);
        $result = app(DeviceRecoveryOltCleanupService::class)->deleteForCase($case);

        $this->assertSame('skipped_keep_active_serial_is_customer_onu', $result['status']);
        $this->assertSame([], FakeTerminateZte::$deleted);
        $this->assertSame('active', $customer->fresh()->status);
        $this->assertTrue(DB::table('olt_onus')->where('serial_number', 'FHTT-LIVE-0278')->exists());
    }

    public function test_multi_device_case_deletes_only_the_collected_extra_onu(): void
    {
        $this->enableCleanup();
        $customer = $this->customer('active', 'FHTT-LIVE-0171');
        $this->onu('FHTT-LIVE-0171', '1/2/1:6');
        $this->onu('FHTT-EXTRA-0171', '1/2/1:7');
        $case = $this->openCase($customer);
        $this->service->setKeepCustomerActive($case, $this->admin, true, 'Perangkat kedua', 'keep-171');

        $case = $this->collectAndReceive($case->fresh(), 'FHTT-EXTRA-0171', clearExpected: true);
        $result = app(DeviceRecoveryOltCleanupService::class)->deleteForCase($case);

        $this->assertSame('deleted', $result['status']);
        $this->assertSame([[9, '1/2/1:7']], FakeTerminateZte::$deleted);
        $this->assertSame('active', $customer->fresh()->status);
        $this->assertTrue(DB::table('olt_onus')->where('serial_number', 'FHTT-LIVE-0171')->exists());
    }

    public function test_multi_device_case_without_collected_serial_never_falls_back_to_customer_onu(): void
    {
        $this->enableCleanup();
        $customer = $this->customer('active', 'FHTT-LIVE-0290');
        $this->onu('FHTT-LIVE-0290', '1/2/1:8');
        $case = $this->openCase($customer);
        $this->service->setKeepCustomerActive($case, $this->admin, true, null, 'keep-290');
        DeviceRecoveryCase::query()->whereKey($case->id)->update(['status' => DeviceRecoveryService::STATUS_RECEIVED]);
        DeviceRecoveryItem::query()->where('recovery_case_id', $case->id)->update([
            'status' => DeviceRecoveryService::ITEM_RECEIVED,
            'captured_serial_number' => null,
        ]);

        $result = app(DeviceRecoveryOltCleanupService::class)->deleteForCase($case->fresh());

        $this->assertSame('skipped_keep_active_no_collected_serial', $result['status']);
        $this->assertSame([], FakeTerminateZte::$deleted);
    }

    public function test_live_serial_mismatch_on_olt_read_back_skips_delete(): void
    {
        $this->enableCleanup();
        $customer = $this->customer('active', 'FHTT-STALE-0001');
        $this->onu('FHTT-STALE-0001', '1/2/1:9');
        FakeTerminateZte::$liveSerial = 'FHTTNEWCUSTOMER';

        $case = $this->receivedCase($customer, 'FHTT-STALE-0001');
        $result = app(DeviceRecoveryOltCleanupService::class)->deleteForCase($case);

        $this->assertSame('skipped_live_serial_mismatch', $result['status']);
        $this->assertSame([], FakeTerminateZte::$deleted);
    }

    public function test_serial_still_on_a_live_customer_is_never_deleted(): void
    {
        $this->enableCleanup();
        $customer = $this->customer('active', 'FHTT-REUSE-0001');
        $this->customer('active', 'FHTT-REUSE-0001');
        $this->onu('FHTT-REUSE-0001', '1/2/1:10');

        $case = $this->receivedCase($customer, 'FHTT-REUSE-0001');
        $result = app(DeviceRecoveryOltCleanupService::class)->deleteForCase($case);

        $this->assertSame('skipped_serial_in_use', $result['status']);
        $this->assertSame([], FakeTerminateZte::$deleted);
    }

    public function test_huawei_live_serial_mismatch_skips_and_match_deletes(): void
    {
        $this->enableCleanup();
        DB::table('olts')->insert([
            'id' => 11, 'name' => 'OLT GADANG', 'code' => 'OLT-11', 'vendor' => 'huawei_ma5608t',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->app->bind(HuaweiOltOnlyProvisioningService::class, fn () => new class(app(MikrotikService::class)) extends HuaweiOltOnlyProvisioningService
        {
            public function onuDetail(Olt $olt, string $onuRef): array
            {
                $gone = in_array([$olt->id, $onuRef], FakeTerminateZte::$deleted, true);

                return ['onu' => ['serial_number' => $gone ? null : (FakeTerminateZte::$liveSerial
                    ?? DB::table('olt_onus')->where('olt_id', $olt->id)->where('onu_ref', $onuRef)->value('serial_number')), 'state' => 'offline']];
            }

            public function deleteOnu(Olt $olt, string $onuRef, ?string $serial = null, array $approval = []): array
            {
                FakeTerminateZte::$deleted[] = [$olt->id, $onuRef];

                return ['success' => true, 'verified_absent' => true, 'device_write' => true];
            }
        });

        $stale = $this->customer('active', 'FHTT0000A037', 11);
        DB::table('olt_onus')->insert([
            'olt_id' => 11, 'serial_number' => 'FHTT0000A037', 'onu_ref' => '0/0/7:29', 'onu_name' => 'ONU',
            'pon_port' => '0/0/7', 'created_at' => now(), 'updated_at' => now(),
        ]);
        FakeTerminateZte::$liveSerial = 'FHTT0000A03B';
        $case = $this->receivedCase($stale, 'FHTT0000A037');
        $result = app(DeviceRecoveryOltCleanupService::class)->deleteForCase($case);
        $this->assertSame('skipped_live_serial_mismatch', $result['status']);
        $this->assertSame([], FakeTerminateZte::$deleted);

        FakeTerminateZte::$liveSerial = null;
        $gone = $this->customer('active', 'FHTT0000A038', 11);
        DB::table('olt_onus')->insert([
            'olt_id' => 11, 'serial_number' => 'FHTT0000A038', 'onu_ref' => '0/0/7:30', 'onu_name' => 'ONU',
            'pon_port' => '0/0/7', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $case = $this->receivedCase($gone, 'FHTT0000A038');
        $result = app(DeviceRecoveryOltCleanupService::class)->deleteForCase($case);
        $this->assertSame('deleted', $result['status']);
        $this->assertSame([[11, '0/0/7:30']], FakeTerminateZte::$deleted);
    }

    private function enableCleanup(): void
    {
        Setting::set('device_recovery_olt_delete_enabled', '1', 'boolean', 'ops');
    }

    private function customer(string $status, ?string $serial, int $oltId = 9): Customer
    {
        $this->seq++;
        $customer = Customer::create([
            'code' => 'RCV-'.$this->seq,
            'name' => 'Recovery customer '.$this->seq,
            'phone' => '0812000000'.$this->seq,
            'address' => 'Alamat '.$this->seq,
            'area_id' => $this->area->id,
            'package_id' => $this->package->id,
            'pppoe_user' => 'pppoe-'.$this->seq,
            'onu_serial' => $serial,
            'olt_id' => $oltId,
            'status' => $status,
        ]);

        return $customer->fresh();
    }

    private function onu(string $serial, string $onuRef): void
    {
        DB::table('olt_onus')->insert([
            'olt_id' => 9, 'serial_number' => $serial, 'onu_ref' => $onuRef, 'onu_name' => 'ONU',
            'pon_port' => '1/2/1', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function openCase(Customer $customer): DeviceRecoveryCase
    {
        $ticket = SupportTicket::create([
            'customer_id' => $customer->id,
            'area_id' => $customer->area_id,
            'created_by' => $this->admin->id,
            'gangguan' => 'Dismantle',
            'subject' => 'Dismantle',
            'status' => 'assigned',
            'priority' => 'normal',
            'type' => 'dismantle',
        ]);

        return $this->service->createFromApprovedDismantle($ticket, $this->admin);
    }

    private function receivedCase(Customer $customer, string $capturedSerial): DeviceRecoveryCase
    {
        return $this->collectAndReceive($this->openCase($customer), $capturedSerial);
    }

    private function collectAndReceive(DeviceRecoveryCase $case, string $capturedSerial, bool $clearExpected = false): DeviceRecoveryCase
    {
        $item = DeviceRecoveryItem::query()->where('recovery_case_id', $case->id)->firstOrFail();
        if ($clearExpected) {
            $this->service->updateItem($case, $item, $this->admin, 'onu', null, 'clear-'.$case->id);
        }
        $case = $this->service->assign($case, $this->admin, $this->technician->id, null, 'assign-'.$case->id);
        $case = $this->service->start($case, $this->technician, 'start-'.$case->id);
        $case = $this->service->capture($case, $item->fresh(), $this->technician, $capturedSerial, 'good', null, [], 'capture-'.$case->id);
        $this->assertSame(DeviceRecoveryService::STATUS_CARRIED, $case->status);

        return $this->service->receive($case, $this->admin, 'receive-'.$case->id);
    }
}

class FakeTerminateZte
{
    public static array $deleted = [];

    public static ?string $liveSerial = null;
}
