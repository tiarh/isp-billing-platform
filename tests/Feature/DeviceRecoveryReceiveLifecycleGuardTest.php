<?php

namespace Tests\Feature;

use App\Jobs\DeleteDismantledOnuJob;
use App\Models\Area;
use App\Models\Customer;
use App\Models\CustomerPendingTermination;
use App\Models\DeviceRecoveryCase;
use App\Models\DeviceRecoveryEvent;
use App\Models\DeviceRecoveryItem;
use App\Models\Package;
use App\Models\Setting;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\DeviceRecoveryService;
use App\Services\Lifecycle\CustomerLifecycleGuard;
use App\Services\Lifecycle\GuardDecision;
use App\Services\Lifecycle\GuardSignal;
use App\Services\OpsAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use Tests\Concerns\StubsLifecycleGuard;
use Tests\TestCase;

/**
 * Roadmap 1.3 PR-4b (A4): a warehouse receipt terminates the customer only when
 * the lifecycle guard allows. Otherwise the receipt still succeeds but the
 * termination is deferred (pending row + billing hold), no ONU delete is
 * dispatched, and ops/admin are told (texts T1/T3/T10 owner approved).
 */
#[Group('blocking-core')]
class DeviceRecoveryReceiveLifecycleGuardTest extends TestCase
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
        config(['device-recovery.mode' => 'write']);
        Setting::set('device_recovery_olt_delete_enabled', '1', 'boolean', 'ops');
        Bus::fake();
        Storage::fake('private');

        $this->service = app(DeviceRecoveryService::class);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->technician = User::factory()->create(['role' => 'teknisi']);
        $this->area = Area::create(['name' => 'Guard Area', 'code' => 'GRD']);
        $this->package = Package::create(['code' => 'GRD-PKG', 'name' => 'Guard package', 'price' => 100000]);
    }

    public function test_allow_terminates_with_guard_audit_and_dispatches_onu_job(): void
    {
        $this->allowCustomerLifecycleGuard();
        $this->allowLifecycleGuard();
        $this->opsAlertNever();
        [$case, $item] = $this->carriedCase('FHTTGRD00001');

        $received = $this->service->receive($case, $this->admin, 'rcv-allow');

        $this->assertSame(DeviceRecoveryService::STATUS_RECEIVED, $received->status);
        $this->assertSame('terminated', Customer::query()->find($case->customer_id)->status);
        $this->assertDatabaseHas('activity_log', ['subject_id' => $case->customer_id, 'event' => 'terminated_guard_allowed']);
        $this->assertDatabaseHas('activity_log', ['subject_id' => $case->customer_id, 'event' => 'terminated_device_recovered']);
        $this->assertSame(0, CustomerPendingTermination::query()->count());
        Bus::assertDispatched(DeleteDismantledOnuJob::class);
    }

    public function test_deny_defers_termination_but_the_receipt_succeeds(): void
    {
        $this->denyCustomerLifecycleGuard();
        $ops = $this->opsAlertOnce();
        [$case] = $this->carriedCase('FHTTGRD00002');
        $customer = Customer::query()->find($case->customer_id);

        $received = $this->service->receive($case, $this->admin, 'rcv-deny');

        $this->assertSame(DeviceRecoveryService::STATUS_RECEIVED, $received->status);
        $this->assertSame('active', $customer->fresh()->status);
        $row = CustomerPendingTermination::query()->where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame('pending', $row->status);
        $this->assertSame('warehouse_receive', $row->source);
        $this->assertSame((int) $case->id, (int) $row->recovery_case_id);
        $this->assertTrue($row->hold_billing);
        $this->assertSame($customer->id, (int) $row->open_key);
        $this->assertDatabaseHas('activity_log', ['subject_id' => $customer->id, 'event' => 'termination_deferred']);
        $this->assertDatabaseMissing('activity_log', ['subject_id' => $customer->id, 'event' => 'terminated_device_recovered']);

        $event = DeviceRecoveryEvent::query()->where('idempotency_key', 'rcv-deny')->firstOrFail();
        $this->assertTrue($event->metadata['customer_termination_deferred']);
        $this->assertArrayNotHasKey('customer_terminated', $event->metadata);
        $this->assertSame('closed', SupportTicket::query()->find($case->ticket_id)->status);

        Bus::assertNotDispatched(DeleteDismantledOnuJob::class);
        $this->assertStringContainsString('Terminate DITUNDA: pelanggan '.$customer->code.' (Pengambilan #'.$case->id.') — perangkat sudah diterima gudang tapi masih terdeteksi dipakai: Sesi PPPoE masih aktif (R1, dicek 2 menit lalu). Tagihan baru ditahan, tidak diisolir. Dicek ulang otomatis tiap jam.', $ops->message);
        $this->assertSame('deferred-termination:'.$customer->id.':'.now()->setTimezone('Asia/Jakarta')->format('Ymd'), $ops->key);
        $this->assertSame(86400, $ops->ttl);
    }

    public function test_unknown_verdict_defers_like_deny(): void
    {
        $this->stubCustomerLifecycleGuard(GuardDecision::fromSignals('terminate', [
            GuardSignal::unknown(GuardSignal::PPPOE, 'pppoe_unknown', 'Status PPPoE tidak diketahui.'),
        ]));
        $this->opsAlertOnce();
        [$case] = $this->carriedCase('FHTTGRD00003');

        $this->service->receive($case, $this->admin, 'rcv-unknown');

        $this->assertSame('active', Customer::query()->find($case->customer_id)->status);
        $row = CustomerPendingTermination::query()->firstOrFail();
        $this->assertSame('unknown', $row->last_verdict);
        Bus::assertNotDispatched(DeleteDismantledOnuJob::class);
    }

    public function test_guard_that_throws_defers_fail_closed(): void
    {
        $guard = Mockery::mock(CustomerLifecycleGuard::class)->makePartial();
        $guard->shouldReceive('canTerminate')->andThrow(new \RuntimeException('grafana down'));
        $this->app->instance(CustomerLifecycleGuard::class, $guard);
        $this->opsAlertOnce();
        [$case] = $this->carriedCase('FHTTGRD00004');

        $received = $this->service->receive($case, $this->admin, 'rcv-throw');

        $this->assertSame(DeviceRecoveryService::STATUS_RECEIVED, $received->status);
        $this->assertSame('active', Customer::query()->find($case->customer_id)->status);
        $row = CustomerPendingTermination::query()->firstOrFail();
        $this->assertContains('pppoe:guard_failed', $row->last_reasons);
    }

    public function test_missing_verdict_at_lock_time_defers_fail_closed(): void
    {
        $this->allowCustomerLifecycleGuard();
        $this->opsAlertOnce();
        [$case] = $this->carriedCase('FHTTGRD00005');
        $actor = $this->admin;
        $method = new ReflectionMethod(DeviceRecoveryService::class, 'terminateCustomerAfterReceive');
        $method->setAccessible(true);

        $result = DB::transaction(fn () => $method->invoke($this->service, $case, $actor, null, 'warehouse_receive'));

        $this->assertTrue($result['customer_termination_deferred']);
        $this->assertSame('active', Customer::query()->find($case->customer_id)->status);
        $row = CustomerPendingTermination::query()->firstOrFail();
        $this->assertSame('unknown', $row->last_verdict);
        $this->assertContains('pppoe:not_evaluated', $row->last_reasons);
    }

    public function test_keep_customer_active_never_calls_the_guard(): void
    {
        $guard = Mockery::mock(CustomerLifecycleGuard::class)->makePartial();
        $guard->shouldReceive('canTerminate')->never();
        $this->app->instance(CustomerLifecycleGuard::class, $guard);
        $this->allowLifecycleGuard();
        [$case] = $this->assignedCase('FHTTGRD00006', keepActive: true);
        $item = DeviceRecoveryItem::query()->where('recovery_case_id', $case->id)->firstOrFail();
        $case = $this->service->assign($case, $this->admin, $this->technician->id, null, 'assign-'.$case->id);
        $case = $this->service->recordPickup($case, $item, $this->technician, 'FHTTGRD00006', 'good', null, $this->pickupPhotos(), 'pickup-'.$case->id);

        $this->service->receive($case, $this->admin, 'rcv-keep');

        $this->assertSame('active', Customer::query()->find($case->customer_id)->status);
        $this->assertSame(0, CustomerPendingTermination::query()->count());
    }

    public function test_replay_creates_no_second_row_or_event(): void
    {
        $this->denyCustomerLifecycleGuard();
        $ops = $this->opsAlertOnce();
        [$case] = $this->carriedCase('FHTTGRD00007');

        $this->service->receive($case, $this->admin, 'rcv-replay');
        $this->service->receive($case->fresh(), $this->admin, 'rcv-replay');

        $this->assertSame(1, CustomerPendingTermination::query()->count());
        $this->assertSame(1, DeviceRecoveryEvent::query()->where('recovery_case_id', $case->id)->where('event_type', 'warehouse_received')->count());
        $this->assertSame(1, DB::table('activity_log')->where('event', 'termination_deferred')->count());
        Bus::assertNotDispatched(DeleteDismantledOnuJob::class);
        $this->assertGreaterThanOrEqual(1, $ops->calls);
    }

    public function test_receive_verified_deny_defers_and_flashes_t1_over_http(): void
    {
        $this->denyCustomerLifecycleGuard();
        $this->opsAlertOnce();
        [$case, $item] = $this->carriedCase('FHTTGRD00008');
        $customer = Customer::query()->find($case->customer_id);

        $this->actingAs($this->admin)
            ->post(route('device-recovery.receive', $case), [
                'serials' => [$item->id => 'FHTTGRD00008'],
                'receipt_photos' => [UploadedFile::fake()->image('gudang.jpg', 200, 200)],
                'idempotency_key' => 'rcv-http',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('warning', 'Perangkat diterima gudang. Pelanggan '.$customer->code.' BELUM diterminate karena masih terdeteksi dipakai: Sesi PPPoE masih aktif (R1, dicek 2 menit lalu). Tagihan baru ditahan dan pelanggan tidak diisolir. Sistem cek ulang otomatis tiap jam; pastikan SN yang diterima benar.');

        $this->assertSame(DeviceRecoveryService::STATUS_RECEIVED, $case->fresh()->status);
        $this->assertSame('active', $customer->fresh()->status);
        $this->assertSame(1, CustomerPendingTermination::query()->count());
        Bus::assertNotDispatched(DeleteDismantledOnuJob::class);
    }

    public function test_receive_verified_allow_flashes_t2_unchanged(): void
    {
        $this->allowCustomerLifecycleGuard();
        $this->allowLifecycleGuard();
        [$case, $item] = $this->carriedCase('FHTTGRD00009');

        $this->actingAs($this->admin)
            ->post(route('device-recovery.receive', $case), [
                'serials' => [$item->id => 'FHTTGRD00009'],
                'receipt_photos' => [UploadedFile::fake()->image('gudang.jpg', 200, 200)],
                'idempotency_key' => 'rcv-http-ok',
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Perangkat diterima gudang.');

        $this->assertSame('terminated', Customer::query()->find($case->customer_id)->status);
        Bus::assertDispatched(DeleteDismantledOnuJob::class);
    }

    public function test_quick_record_deny_defers_and_flashes_t1(): void
    {
        $this->denyCustomerLifecycleGuard();
        $this->opsAlertOnce();
        [$case] = $this->assignedCase('FHTTGRD00010');
        $customer = Customer::query()->find($case->customer_id);

        $this->actingAs($this->admin)
            ->post(route('device-recovery.quick-record', $case), [
                'technician_id' => $this->technician->id,
                'serial_number' => 'FHTTGRD00010',
                'condition' => 'good',
                'label_sn_photos' => [UploadedFile::fake()->image('label.jpg', 200, 200)],
                'device_photos' => [UploadedFile::fake()->image('onu.jpg', 200, 200)],
                'confirm_received' => '1',
                'idempotency_key' => 'quick-deny',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('warning');

        $this->assertSame(DeviceRecoveryService::STATUS_RECEIVED, $case->fresh()->status);
        $this->assertSame('active', $customer->fresh()->status);
        $row = CustomerPendingTermination::query()->firstOrFail();
        $this->assertSame('warehouse_quick_record', $row->source);
        Bus::assertNotDispatched(DeleteDismantledOnuJob::class);
    }

    public function test_quick_record_with_bad_condition_goes_to_exception_and_never_terminates_or_defers(): void
    {
        // Owner Q4: the guard is evaluated regardless of condition; a bad device
        // goes to exception review exactly as before, nothing is terminated.
        $this->denyCustomerLifecycleGuard();
        [$case] = $this->assignedCase('FHTTGRD00011');

        $this->service->quickRecord($case, $this->admin, $this->technician->id, 'FHTTGRD00011', 'damaged', 'Rusak parah', $this->pickupPhotos(), 'quick-bad');

        $this->assertSame(DeviceRecoveryService::STATUS_EXCEPTION, $case->fresh()->status);
        $this->assertSame('active', Customer::query()->find($case->customer_id)->status);
        $this->assertSame(0, CustomerPendingTermination::query()->count());
    }

    public function test_pending_dismantle_case_receipt_with_deny_is_deferred(): void
    {
        // #501: a pickup case born from a pending (denied) lifecycle dismantle request.
        $this->denyCustomerLifecycleGuard();
        $this->opsAlertOnce();
        [$case] = $this->carriedCase('FHTTGRD00012');
        $meta = (array) DeviceRecoveryCase::query()->find($case->id)->meta;
        DeviceRecoveryCase::query()->whereKey($case->id)->update(['meta' => json_encode([...$meta, 'source' => 'pending_dismantle'])]);

        $this->service->receive($case->fresh(), $this->admin, 'rcv-pd');

        $this->assertSame('active', Customer::query()->find($case->customer_id)->status);
        $this->assertSame('pending', CustomerPendingTermination::query()->firstOrFail()->status);
        Bus::assertNotDispatched(DeleteDismantledOnuJob::class);
    }

    public function test_suharto_regression_live_customer_is_not_terminated_by_a_receipt(): void
    {
        // The incident: a customer still online (PPPoE/traffic) lost service because a
        // device from an old ticket was received. The receipt must not terminate.
        $this->stubCustomerLifecycleGuard(GuardDecision::fromSignals('terminate', [
            GuardSignal::inUse(GuardSignal::PPPOE, 'pppoe_in_use', 'Sesi PPPoE masih aktif (R1, dicek 1 menit lalu).'),
            GuardSignal::inUse(GuardSignal::TRAFFIC, 'traffic_in_use', 'Masih ada trafik hari ini.'),
        ]));
        $this->opsAlertOnce();
        [$case] = $this->carriedCase('FHTTGRD00013');
        $customer = Customer::query()->find($case->customer_id);
        $customer->forceFill(['is_online' => true])->save();

        $this->service->receive($case, $this->admin, 'rcv-suharto');

        $this->assertSame('active', $customer->fresh()->status);
        $row = CustomerPendingTermination::query()->firstOrFail();
        $this->assertContains('pppoe:pppoe_in_use', $row->first_reasons);
        $this->assertContains('traffic:traffic_in_use', $row->first_reasons);
    }

    // ------------------------------------------------------------------

    private function opsAlertNever(): void
    {
        $mock = Mockery::mock(OpsAlertService::class);
        $mock->shouldReceive('send')->never();
        $this->app->instance(OpsAlertService::class, $mock);
    }

    private function opsAlertOnce(): object
    {
        $spy = new class
        {
            public int $calls = 0;

            public string $message = '';

            public string $key = '';

            public int $ttl = 0;
        };
        $mock = Mockery::mock(OpsAlertService::class);
        $mock->shouldReceive('send')->andReturnUsing(function (string $message, string $key = '', int $ttl = 300) use ($spy): bool {
            $spy->calls++;
            $spy->message = $message;
            $spy->key = $key;
            $spy->ttl = $ttl;

            return true;
        });
        $this->app->instance(OpsAlertService::class, $mock);

        return $spy;
    }

    /** @return list<array{path: string, kind: string}> */
    private function pickupPhotos(): array
    {
        return [
            ['path' => 'device-recovery/label.jpg', 'kind' => DeviceRecoveryService::PHOTO_KIND_LABEL_SN],
            ['path' => 'device-recovery/onu.jpg', 'kind' => DeviceRecoveryService::PHOTO_KIND_DEVICE],
        ];
    }

    /** @return array{0: DeviceRecoveryCase, 1: DeviceRecoveryItem, 2: SupportTicket} */
    private function assignedCase(string $serial, bool $keepActive = false): array
    {
        $this->seq++;
        $customer = Customer::create([
            'code' => 'GRD-'.$this->seq,
            'name' => 'Guard customer '.$this->seq,
            'phone' => '0816000000'.$this->seq,
            'address' => 'Alamat '.$this->seq,
            'area_id' => $this->area->id,
            'package_id' => $this->package->id,
            'pppoe_user' => 'grd-'.$this->seq,
            'onu_serial' => $serial,
            'status' => 'active',
        ]);
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
        $case = $this->service->createFromApprovedDismantle($ticket, $this->admin);
        if ($keepActive) {
            $this->service->setKeepCustomerActive($case, $this->admin, true, 'Dua perangkat', 'keep-'.$case->id);
        }
        $item = DeviceRecoveryItem::query()->where('recovery_case_id', $case->id)->firstOrFail();

        return [$case->fresh(), $item, $ticket];
    }

    /** @return array{0: DeviceRecoveryCase, 1: DeviceRecoveryItem, 2: SupportTicket} */
    private function carriedCase(string $serial): array
    {
        [$case, $item, $ticket] = $this->assignedCase($serial);
        $case = $this->service->assign($case, $this->admin, $this->technician->id, null, 'assign-'.$case->id);
        $case = $this->service->recordPickup($case, $item, $this->technician, $serial, 'good', null, $this->pickupPhotos(), 'pickup-'.$case->id);
        $this->assertSame(DeviceRecoveryService::STATUS_CARRIED, $case->status);

        return [$case, $item->fresh(), $ticket];
    }
}
