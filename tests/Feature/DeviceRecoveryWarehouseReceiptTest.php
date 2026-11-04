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
 * Owner 2026-10-09: "Diterima gudang" verifies the physical SN against the
 * captured SN and needs a receipt photo; the dismantle ticket then closes
 * itself (also after an accepted exception).
 */
#[Group('blocking-core')]
class DeviceRecoveryWarehouseReceiptTest extends TestCase
{
    use RefreshDatabase;
    use StubsLifecycleGuard;

    private DeviceRecoveryService $service;

    private User $admin;

    private User $globalAdmin;

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
        $this->globalAdmin = User::factory()->create(['role' => 'superadmin']);
        $this->technician = User::factory()->create(['role' => 'teknisi']);
        $this->area = Area::create(['name' => 'Gudang Area', 'code' => 'GDG']);
        $this->package = Package::create(['code' => 'GDG-PKG', 'name' => 'Gudang package', 'price' => 100000]);
    }

    public function test_receipt_requires_physical_serial_and_receipt_photo(): void
    {
        [$case, $item, $ticket] = $this->carriedCase('FHTTGDG00001');

        $this->actingAs($this->admin)
            ->post(route('device-recovery.receive', $case), ['idempotency_key' => 'rcv-empty'])
            ->assertSessionHasErrors([
                'serials' => 'SN fisik perangkat wajib diketik atau di-scan.',
                'receipt_photos' => 'Foto penerimaan gudang wajib diunggah minimal 1.',
            ]);

        $this->actingAs($this->admin)
            ->post(route('device-recovery.receive', $case), [
                'serials' => [$item->id => ''],
                'receipt_photos' => [UploadedFile::fake()->image('gudang.jpg', 200, 200)],
                'idempotency_key' => 'rcv-blank-sn',
            ])
            ->assertSessionHasErrors(['serials.'.$item->id]);

        $this->assertSame(DeviceRecoveryService::STATUS_CARRIED, $case->fresh()->status);
        $this->assertSame('assigned', $ticket->fresh()->status);
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    public function test_mismatch_is_rejected_without_changing_the_case(): void
    {
        [$case, $item, $ticket] = $this->carriedCase('FHTTGDG00002');

        $this->actingAs($this->admin)
            ->post(route('device-recovery.receive', $case), [
                'serials' => [$item->id => 'FHTTGDG99999'],
                'receipt_photos' => [UploadedFile::fake()->image('gudang.jpg', 200, 200)],
                'idempotency_key' => 'rcv-mismatch',
            ])
            ->assertSessionHasErrors(['serials']);

        $this->assertSame(DeviceRecoveryService::STATUS_CARRIED, $case->fresh()->status);
        $this->assertSame(DeviceRecoveryService::ITEM_CARRIED, $item->fresh()->status);
        $this->assertSame('assigned', $ticket->fresh()->status);
        $this->assertFalse(DeviceRecoveryEvent::query()->where('idempotency_key', 'rcv-mismatch')->exists());
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    public function test_mismatch_submitted_as_exception_goes_to_review_and_keeps_ticket_open(): void
    {
        [$case, $item, $ticket] = $this->carriedCase('FHTTGDG00003');

        $this->actingAs($this->admin)
            ->post(route('device-recovery.receive', $case), [
                'serials' => [$item->id => 'FHTTGDG99999'],
                'receipt_photos' => [UploadedFile::fake()->image('gudang.jpg', 200, 200)],
                'submit_as_exception' => '1',
                'idempotency_key' => 'rcv-mismatch-exception',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $case->refresh();
        $item->refresh();
        $this->assertSame(DeviceRecoveryService::STATUS_EXCEPTION, $case->status);
        $this->assertStringStartsWith('SN gudang tidak cocok', (string) $case->exception_reason);
        $this->assertSame(DeviceRecoveryService::ITEM_EXCEPTION, $item->status);
        $this->assertSame(DeviceRecoveryService::CONDITION_MISMATCH, $item->condition);
        $this->assertSame('FHTTGDG99999', $item->meta['warehouse_serial']);
        $this->assertSame(['label_sn', 'perangkat_utuh', 'penerimaan'], $item->meta['photo_kinds']);
        $this->assertSame('assigned', $ticket->fresh()->status);
        $this->assertSame('active', Customer::query()->find($case->customer_id)->status);
        $this->assertTrue(DeviceRecoveryEvent::query()->where('recovery_case_id', $case->id)->where('event_type', 'warehouse_serial_mismatch')->exists());

        // Global admin accepts the exception => ticket closes automatically.
        $this->service->resolveException($case, $this->globalAdmin, 'accept', 'Diterima dengan SN berbeda', 'resolve-'.$case->id);
        $this->assertSame(DeviceRecoveryService::STATUS_EXCEPTION_ACCEPTED, $case->fresh()->status);
        $this->assertSame('closed', $ticket->fresh()->status);
        $this->assertTrue(TicketActivity::query()
            ->where('ticket_id', $ticket->id)
            ->where('description', 'like', '%ditutup otomatis: exception pengambilan perangkat disetujui%')
            ->exists());
    }

    public function test_an_incident_ticket_closed_by_the_receipt_gets_ditutup_otomatis_and_an_existing_cause_is_kept(): void
    {
        [$case, $item, $ticket] = $this->carriedCase('FHTTGDG00041');
        $ticket->update(['type' => 'incident']);

        $this->actingAs($this->admin)
            ->post(route('device-recovery.receive', $case), [
                'serials' => [$item->id => 'FHTTGDG00041'],
                'receipt_photos' => [UploadedFile::fake()->image('gudang.jpg', 200, 200)],
                'idempotency_key' => 'rcv-incident',
            ])
            ->assertSessionHasNoErrors();

        $ticket->refresh();
        $this->assertSame('closed', $ticket->status);
        $this->assertSame('auto_closed', $ticket->root_cause);
        $this->assertSame('system', $ticket->root_cause_source);
        $this->assertSame(1, TicketActivity::query()->where('ticket_id', $ticket->id)->where('action', 'root_cause_set')->count());

        [$case2, $item2, $ticket2] = $this->carriedCase('FHTTGDG00042');
        $ticket2->update(['type' => 'incident', 'root_cause' => 'drop_los', 'root_cause_source' => 'technician']);

        $this->actingAs($this->admin)
            ->post(route('device-recovery.receive', $case2), [
                'serials' => [$item2->id => 'FHTTGDG00042'],
                'receipt_photos' => [UploadedFile::fake()->image('gudang.jpg', 200, 200)],
                'idempotency_key' => 'rcv-incident-cause',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('closed', $ticket2->fresh()->status);
        $this->assertSame('drop_los', $ticket2->fresh()->root_cause);
        $this->assertSame('technician', $ticket2->fresh()->root_cause_source);
    }

    public function test_matching_serial_receives_terminates_and_auto_closes_ticket(): void
    {
        [$case, $item, $ticket] = $this->carriedCase('FHTTGDG00004');

        $this->actingAs($this->admin)
            ->post(route('device-recovery.receive', $case), [
                'serials' => [$item->id => 'fhtt-gdg-00004'],
                'receipt_photos' => [UploadedFile::fake()->image('gudang.jpg', 200, 200)],
                'idempotency_key' => 'rcv-ok',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $case->refresh();
        $item->refresh();
        $this->assertSame(DeviceRecoveryService::STATUS_RECEIVED, $case->status);
        $this->assertSame(DeviceRecoveryService::ITEM_RECEIVED, $item->status);
        $this->assertSame((int) $this->admin->id, (int) $item->verified_by);
        $this->assertSame('FHTTGDG00004', $item->meta['warehouse_serial']);
        $this->assertSame(['label_sn', 'perangkat_utuh', 'penerimaan'], $item->meta['photo_kinds']);
        Storage::disk('private')->assertExists($item->photo_paths[2]);
        $this->assertSame('terminated', Customer::query()->find($case->customer_id)->status);

        $ticket->refresh();
        $this->assertSame('closed', $ticket->status);
        $this->assertNotNull($ticket->resolved_at);
        $this->assertNull($ticket->root_cause, 'a dismantle ticket is not an incident: no cause is written');
        $this->assertSame(1, TicketActivity::query()
            ->where('ticket_id', $ticket->id)
            ->where('action', 'status_changed')
            ->where('description', 'like', '%ditutup otomatis: perangkat diterima gudang%')
            ->count());
        $event = DeviceRecoveryEvent::query()->where('idempotency_key', 'rcv-ok')->firstOrFail();
        $this->assertSame('warehouse_received', $event->event_type);
        $this->assertTrue($event->metadata['ticket_auto_closed']);
        $this->assertTrue($event->metadata['warehouse_serial_verified']);

        // Replay: no second event, no extra photo, no second ticket activity.
        $this->actingAs($this->admin)
            ->post(route('device-recovery.receive', $case), [
                'serials' => [$item->id => 'FHTTGDG00004'],
                'receipt_photos' => [UploadedFile::fake()->image('gudang.jpg', 200, 200)],
                'idempotency_key' => 'rcv-ok',
            ])
            ->assertRedirect();
        $this->assertSame(1, DeviceRecoveryEvent::query()->where('recovery_case_id', $case->id)->where('event_type', 'warehouse_received')->count());
        $this->assertCount(1, Storage::disk('private')->allFiles());
    }

    public function test_gpon_hex_and_mac_forms_match(): void
    {
        $this->assertTrue($this->service->serialsMatch('FHTT0000A028', '464854540000A028'));
        $this->assertTrue($this->service->serialsMatch('46485454-0000A028', 'fhtt0000A028'));
        $this->assertTrue($this->service->serialsMatch('AA:BB:CC:DD:EE:FF', 'aabb.ccdd.eeff'));
        $this->assertFalse($this->service->serialsMatch('FHTT0000A028', 'FHTT0000A029'));
        $this->assertFalse($this->service->serialsMatch(null, 'FHTT0000A028'));

        [$case, $item] = $this->carriedCase('ZTEG0000A007');
        $case = $this->service->receiveVerified($case, $this->admin, [$item->id => '5A5445470000A007'], ['device-recovery/receipt.jpg'], false, 'rcv-hex');
        $this->assertSame(DeviceRecoveryService::STATUS_RECEIVED, $case->status);
    }

    public function test_keep_customer_active_still_closes_ticket_but_not_customer(): void
    {
        [$case, $item, $ticket] = $this->carriedCase('FHTTGDG00006', keepActive: true);

        $case = $this->service->receiveVerified($case, $this->admin, [$item->id => 'FHTTGDG00006'], ['device-recovery/receipt.jpg'], false, 'rcv-keep');

        $this->assertSame(DeviceRecoveryService::STATUS_RECEIVED, $case->status);
        $this->assertSame('active', Customer::query()->find($case->customer_id)->status);
        $this->assertSame('closed', $ticket->fresh()->status);
    }

    public function test_already_closed_ticket_is_not_touched(): void
    {
        [$case, $item, $ticket] = $this->carriedCase('FHTTGDG00007');
        $ticket->forceFill(['status' => 'closed'])->save();

        $this->service->receiveVerified($case, $this->admin, [$item->id => 'FHTTGDG00007'], ['device-recovery/receipt.jpg'], false, 'rcv-closed');

        $this->assertSame('closed', $ticket->fresh()->status);
        $this->assertFalse(TicketActivity::query()->where('ticket_id', $ticket->id)->where('description', 'like', '%ditutup otomatis%')->exists());
    }

    public function test_resolved_ticket_is_closed_on_receipt(): void
    {
        [$case, $item, $ticket] = $this->carriedCase('FHTTGDG00008');
        $ticket->forceFill(['status' => 'resolved', 'resolved_at' => now()->subHour()])->save();

        $this->service->receiveVerified($case, $this->admin, [$item->id => 'FHTTGDG00008'], ['device-recovery/receipt.jpg'], false, 'rcv-resolved');

        $this->assertSame('closed', $ticket->fresh()->status);
        $this->assertTrue(TicketActivity::query()->where('ticket_id', $ticket->id)->where('description', 'like', '%resolved -> closed (ditutup otomatis%')->exists());
    }

    public function test_accepted_lost_device_exception_auto_closes_ticket_once_and_keeps_customer(): void
    {
        [$case, $item, $ticket] = $this->assignedCase('FHTTGDG00009');
        $case = $this->service->recordPickup($case, $item, $this->technician, null, 'lost', 'Perangkat tidak ada di lokasi', [], 'pickup-lost-'.$case->id);
        $this->assertSame(DeviceRecoveryService::STATUS_EXCEPTION, $case->status);
        $this->assertSame('assigned', $ticket->fresh()->status);

        $this->actingAs($this->globalAdmin)
            ->post(route('device-recovery.resolve-exception', $case), [
                'resolution' => 'accept',
                'reason' => 'Perangkat hilang, disetujui',
                'idempotency_key' => 'resolve-lost-'.$case->id,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(DeviceRecoveryService::STATUS_EXCEPTION_ACCEPTED, $case->fresh()->status);
        $ticket->refresh();
        $this->assertSame('closed', $ticket->status);
        $this->assertNotNull($ticket->resolved_at);
        $this->assertSame(1, TicketActivity::query()
            ->where('ticket_id', $ticket->id)
            ->where('action', 'status_changed')
            ->where('description', 'like', '%ditutup otomatis: exception pengambilan perangkat disetujui%')
            ->count());
        // Terminate behaviour unchanged: only warehouse receipt terminates.
        $this->assertSame('active', Customer::query()->find($case->customer_id)->status);
        $event = DeviceRecoveryEvent::query()->where('idempotency_key', 'resolve-lost-'.$case->id)->firstOrFail();
        $this->assertTrue($event->metadata['ticket_auto_closed']);
    }

    public function test_reopened_exception_does_not_close_ticket(): void
    {
        [$case, $item, $ticket] = $this->assignedCase('FHTTGDG00010');
        $case = $this->service->recordPickup($case, $item, $this->technician, null, 'lost', 'Perangkat tidak ada di lokasi', [], 'pickup-lost-'.$case->id);

        $this->service->resolveException($case, $this->globalAdmin, 'reopen', 'Coba ambil lagi', 'reopen-'.$case->id);

        $this->assertSame(DeviceRecoveryService::STATUS_ASSIGNED, $case->fresh()->status);
        $this->assertSame('assigned', $ticket->fresh()->status);
        $this->assertFalse(TicketActivity::query()->where('ticket_id', $ticket->id)->where('description', 'like', '%ditutup otomatis%')->exists());
    }

    public function test_technician_cannot_mark_received(): void
    {
        [$case, $item] = $this->carriedCase('FHTTGDG00008');

        $this->actingAs($this->technician)
            ->post(route('device-recovery.receive', $case), [
                'serials' => [$item->id => 'FHTTGDG00008'],
                'receipt_photos' => [UploadedFile::fake()->image('gudang.jpg', 200, 200)],
                'idempotency_key' => 'rcv-tech',
            ])
            ->assertForbidden();

        $this->assertSame(DeviceRecoveryService::STATUS_CARRIED, $case->fresh()->status);
    }

    /**
     * @return array{0: DeviceRecoveryCase, 1: DeviceRecoveryItem, 2: SupportTicket}
     */
    private function assignedCase(string $serial, bool $keepActive = false): array
    {
        $this->seq++;
        $customer = Customer::create([
            'code' => 'GDG-'.$this->seq,
            'name' => 'Gudang customer '.$this->seq,
            'phone' => '0814000000'.$this->seq,
            'address' => 'Alamat '.$this->seq,
            'area_id' => $this->area->id,
            'package_id' => $this->package->id,
            'pppoe_user' => 'gdg-'.$this->seq,
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
        $case = $this->service->assign($case, $this->admin, $this->technician->id, null, 'assign-'.$case->id);
        $item = DeviceRecoveryItem::query()->where('recovery_case_id', $case->id)->firstOrFail();

        return [$case, $item, $ticket];
    }

    /**
     * @return array{0: DeviceRecoveryCase, 1: DeviceRecoveryItem, 2: SupportTicket}
     */
    private function carriedCase(string $serial, bool $keepActive = false): array
    {
        [$case, $item, $ticket] = $this->assignedCase($serial, $keepActive);
        $case = $this->service->recordPickup($case, $item, $this->technician, $serial, 'good', null, [
            ['path' => 'device-recovery/label.jpg', 'kind' => DeviceRecoveryService::PHOTO_KIND_LABEL_SN],
            ['path' => 'device-recovery/onu.jpg', 'kind' => DeviceRecoveryService::PHOTO_KIND_DEVICE],
        ], 'pickup-'.$case->id);
        $this->assertSame(DeviceRecoveryService::STATUS_CARRIED, $case->status);

        return [$case, $item->fresh(), $ticket];
    }
}
