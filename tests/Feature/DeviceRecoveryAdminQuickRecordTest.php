<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Customer;
use App\Models\DeviceRecoveryCase;
use App\Models\DeviceRecoveryEvent;
use App\Models\DeviceRecoveryItem;
use App\Models\Package;
use App\Models\SupportTicket;
use App\Models\TicketActivity;
use App\Models\User;
use App\Services\DeviceRecoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\StubsLifecycleGuard;
use Tests\TestCase;

/**
 * Owner 2026-10-09: admin "Sudah diambil, catat langsung" for a device the
 * technician already brought in: one transaction straight to received.
 */
#[Group('blocking-core')]
class DeviceRecoveryAdminQuickRecordTest extends TestCase
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
        $this->allowCustomerLifecycleGuard();
        config(['device-recovery.mode' => 'write']);
        Bus::fake();
        Storage::fake('private');

        $this->service = app(DeviceRecoveryService::class);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->technician = User::factory()->create(['role' => 'teknisi']);
        $this->area = Area::create(['name' => 'Quick Area', 'code' => 'QCK']);
        $this->package = Package::create(['code' => 'QCK-PKG', 'name' => 'Quick package', 'price' => 100000]);
    }

    public function test_waiting_case_goes_straight_to_received_with_full_audit(): void
    {
        [$case, $item, $ticket] = $this->openCase('FHTTQCK00001');
        $this->assertSame(DeviceRecoveryService::STATUS_WAITING_ASSIGNMENT, $case->status);

        $this->actingAs($this->admin)
            ->post(route('device-recovery.quick-record', $case), $this->payload([
                'serial_number' => 'fhtt-qck-00001',
                'idempotency_key' => 'quick-ok',
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $case->refresh();
        $item->refresh();
        $this->assertSame(DeviceRecoveryService::STATUS_RECEIVED, $case->status);
        $this->assertSame((int) $this->technician->id, (int) $case->assigned_to);
        $this->assertNotNull($case->started_at);
        $this->assertNotNull($case->received_at);
        $this->assertSame(DeviceRecoveryService::ITEM_RECEIVED, $item->status);
        $this->assertSame('FHTTQCK00001', $item->captured_serial_number);
        $this->assertSame((int) $this->technician->id, (int) $item->captured_by);
        $this->assertSame((int) $this->admin->id, (int) $item->verified_by);
        $this->assertSame(['label_sn', 'perangkat_utuh'], $item->meta['photo_kinds']);
        $this->assertCount(2, Storage::disk('private')->allFiles());

        $captured = DeviceRecoveryEvent::query()->where('recovery_case_id', $case->id)->where('event_type', 'serial_captured')->firstOrFail();
        $this->assertSame((int) $this->admin->id, (int) $captured->actor_id);
        $this->assertTrue($captured->metadata['admin_quick_record']);
        $this->assertSame((int) $this->technician->id, (int) $captured->metadata['captured_by']);
        $received = DeviceRecoveryEvent::query()->where('idempotency_key', 'quick-ok')->firstOrFail();
        $this->assertSame('warehouse_received', $received->event_type);
        $this->assertSame((int) $this->admin->id, (int) $received->actor_id);
        $this->assertTrue($received->metadata['customer_terminated']);
        $this->assertTrue($received->metadata['ticket_auto_closed']);

        $this->assertSame('terminated', Customer::query()->find($case->customer_id)->status);
        $this->assertSame('closed', $ticket->fresh()->status);
        $this->assertTrue(TicketActivity::query()->where('ticket_id', $ticket->id)->where('description', 'like', '%ditutup otomatis: perangkat diterima gudang%')->exists());

        // Replay with the same key: no duplicate events, new uploads discarded.
        $this->actingAs($this->admin)
            ->post(route('device-recovery.quick-record', $case), $this->payload([
                'serial_number' => 'FHTTQCK00001',
                'idempotency_key' => 'quick-ok',
            ]))
            ->assertRedirect();
        $this->assertSame(1, DeviceRecoveryEvent::query()->where('recovery_case_id', $case->id)->where('event_type', 'warehouse_received')->count());
        $this->assertCount(2, Storage::disk('private')->allFiles());
    }

    public function test_photos_technician_and_confirmation_are_required(): void
    {
        [$case] = $this->openCase('FHTTQCK00002');

        $this->actingAs($this->admin)
            ->post(route('device-recovery.quick-record', $case), [
                'serial_number' => 'FHTTQCK00002',
                'condition' => 'good',
                'idempotency_key' => 'quick-missing',
            ])
            ->assertSessionHasErrors(['technician_id', 'confirm_received']);

        $this->actingAs($this->admin)
            ->post(route('device-recovery.quick-record', $case), [
                'technician_id' => $this->technician->id,
                'serial_number' => 'FHTTQCK00002',
                'condition' => 'good',
                'confirm_received' => '1',
                'idempotency_key' => 'quick-no-photos',
            ])
            ->assertSessionHasErrors(['label_sn_photos', 'device_photos']);

        $this->actingAs($this->admin)
            ->post(route('device-recovery.quick-record', $case), $this->payload([
                'technician_id' => $this->admin->id,
                'serial_number' => 'FHTTQCK00002',
                'idempotency_key' => 'quick-not-technician',
            ]))
            ->assertSessionHasErrors(['technician_id']);

        $this->assertSame(DeviceRecoveryService::STATUS_WAITING_ASSIGNMENT, $case->fresh()->status);
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    public function test_serial_mismatch_with_expected_goes_to_exception_not_received(): void
    {
        [$case, $item, $ticket] = $this->openCase('FHTTQCK00003');
        $case = $this->service->assign($case, $this->admin, $this->technician->id, null, 'assign-'.$case->id);

        $this->actingAs($this->admin)
            ->post(route('device-recovery.quick-record', $case), $this->payload([
                'serial_number' => 'FHTTQCK99999',
                'idempotency_key' => 'quick-mismatch-no-reason',
            ]))
            ->assertSessionHasErrors(['reason']);
        $this->assertSame(DeviceRecoveryService::STATUS_ASSIGNED, $case->fresh()->status);

        $this->actingAs($this->admin)
            ->post(route('device-recovery.quick-record', $case), $this->payload([
                'serial_number' => 'FHTTQCK99999',
                'reason' => 'Perangkat dari teknisi berbeda SN',
                'idempotency_key' => 'quick-mismatch',
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $case->refresh();
        $this->assertSame(DeviceRecoveryService::STATUS_EXCEPTION, $case->status);
        $this->assertSame(DeviceRecoveryService::CONDITION_MISMATCH, $item->fresh()->condition);
        $this->assertSame(DeviceRecoveryService::ITEM_EXCEPTION, $item->fresh()->status);
        $this->assertNull($case->received_at);
        $this->assertSame('active', Customer::query()->find($case->customer_id)->status);
        $this->assertSame('assigned', $ticket->fresh()->status);
    }

    public function test_keep_customer_active_is_honoured(): void
    {
        [$case, $item, $ticket] = $this->openCase('FHTTQCK00004');
        $this->service->setKeepCustomerActive($case, $this->admin, true, 'Dua perangkat', 'keep-'.$case->id);

        $case = $this->service->quickRecord($case->fresh(), $this->admin, $this->technician->id, 'FHTTQCK00004', 'good', null, [
            ['path' => 'device-recovery/label.jpg', 'kind' => DeviceRecoveryService::PHOTO_KIND_LABEL_SN],
            ['path' => 'device-recovery/onu.jpg', 'kind' => DeviceRecoveryService::PHOTO_KIND_DEVICE],
        ], 'quick-keep');

        $this->assertSame(DeviceRecoveryService::STATUS_RECEIVED, $case->status);
        $this->assertSame('active', Customer::query()->find($case->customer_id)->status);
        $this->assertSame('closed', $ticket->fresh()->status);
    }

    public function test_only_admins_and_only_before_technician_capture(): void
    {
        [$case, $item] = $this->openCase('FHTTQCK00005');
        $case = $this->service->assign($case, $this->admin, $this->technician->id, null, 'assign-'.$case->id);

        $this->actingAs($this->technician)
            ->post(route('device-recovery.quick-record', $case), $this->payload(['serial_number' => 'FHTTQCK00005', 'idempotency_key' => 'quick-tech']))
            ->assertForbidden();

        $this->service->recordPickup($case, $item, $this->technician, 'FHTTQCK00005', 'good', null, [
            ['path' => 'device-recovery/label.jpg', 'kind' => DeviceRecoveryService::PHOTO_KIND_LABEL_SN],
            ['path' => 'device-recovery/onu.jpg', 'kind' => DeviceRecoveryService::PHOTO_KIND_DEVICE],
        ], 'pickup-'.$case->id);
        $this->assertSame(DeviceRecoveryService::STATUS_CARRIED, $case->fresh()->status);

        $this->actingAs($this->admin)
            ->post(route('device-recovery.quick-record', $case), $this->payload(['serial_number' => 'FHTTQCK00005', 'idempotency_key' => 'quick-after-capture']))
            ->assertSessionHasErrors(['status']);
        $this->assertSame(DeviceRecoveryService::STATUS_CARRIED, $case->fresh()->status);

        config(['device-recovery.mode' => 'read_only']);
        [$readOnlyCase] = $this->openCase('FHTTQCK00006');
        $this->actingAs($this->admin)
            ->post(route('device-recovery.quick-record', $readOnlyCase), $this->payload(['serial_number' => 'FHTTQCK00006', 'idempotency_key' => 'quick-read-only']))
            ->assertStatus(423);
    }

    public function test_multi_device_case_is_rejected(): void
    {
        [$case] = $this->openCase('FHTTQCK00007');
        $this->service->addItem($case, $this->admin, 'router', 'ROUTER-QCK-7', 'add-'.$case->id);

        $this->actingAs($this->admin)
            ->post(route('device-recovery.quick-record', $case), $this->payload(['serial_number' => 'FHTTQCK00007', 'idempotency_key' => 'quick-multi']))
            ->assertSessionHasErrors(['status']);
        $this->assertSame(DeviceRecoveryService::STATUS_WAITING_ASSIGNMENT, $case->fresh()->status);
    }

    private function payload(array $overrides): array
    {
        return [
            'technician_id' => $this->technician->id,
            'condition' => 'good',
            'label_sn_photos' => [UploadedFile::fake()->image('label.jpg', 200, 200)],
            'device_photos' => [UploadedFile::fake()->image('onu.jpg', 200, 200)],
            'confirm_received' => '1',
            ...$overrides,
        ];
    }

    /**
     * @return array{0: DeviceRecoveryCase, 1: DeviceRecoveryItem, 2: SupportTicket}
     */
    private function openCase(string $serial): array
    {
        $this->seq++;
        $customer = Customer::create([
            'code' => 'QCK-'.$this->seq,
            'name' => 'Quick customer '.$this->seq,
            'phone' => '0815000000'.$this->seq,
            'address' => 'Alamat '.$this->seq,
            'area_id' => $this->area->id,
            'package_id' => $this->package->id,
            'pppoe_user' => 'qck-'.$this->seq,
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
        $item = DeviceRecoveryItem::query()->where('recovery_case_id', $case->id)->firstOrFail();

        return [$case, $item, $ticket];
    }
}
