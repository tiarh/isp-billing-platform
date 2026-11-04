<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Customer;
use App\Models\DeviceRecoveryCase;
use App\Models\DeviceRecoveryEvent;
use App\Models\DeviceRecoveryItem;
use App\Models\Package;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\DeviceRecoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Owner 2026-10-09: one technician form (no separate "Mulai pengambilan"),
 * with required photos: label SN + perangkat utuh (except a lost device).
 */
#[Group('blocking-core')]
class DeviceRecoveryPickupFormTest extends TestCase
{
    use RefreshDatabase;

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
        Bus::fake();
        Storage::fake('private');

        $this->service = app(DeviceRecoveryService::class);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->technician = User::factory()->create(['role' => 'teknisi']);
        $this->area = Area::create(['name' => 'Pickup Area', 'code' => 'PKA']);
        $this->package = Package::create(['code' => 'PKA-PKG', 'name' => 'Pickup package', 'price' => 100000]);
    }

    public function test_save_is_blocked_without_photos_and_case_stays_assigned(): void
    {
        [$case, $item] = $this->assignedCase('FHTTPICK0001');

        $this->actingAs($this->technician)
            ->post(route('device-recovery.capture', [$case, $item]), [
                'serial_number' => 'FHTTPICK0001',
                'condition' => 'good',
                'idempotency_key' => 'pickup-no-photo',
            ])
            ->assertSessionHasErrors([
                'label_sn_photos' => 'Foto label SN wajib diunggah minimal 1.',
                'device_photos' => 'Foto perangkat utuh wajib diunggah minimal 1.',
            ]);

        $this->assertSame(DeviceRecoveryService::STATUS_ASSIGNED, $case->fresh()->status);
        $this->assertSame(DeviceRecoveryService::ITEM_PENDING, $item->fresh()->status);
        $this->assertFalse(DeviceRecoveryEvent::query()->where('recovery_case_id', $case->id)->whereIn('event_type', ['pickup_started', 'serial_captured'])->exists());
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    public function test_save_is_blocked_when_one_photo_kind_is_missing(): void
    {
        [$case, $item] = $this->assignedCase('FHTTPICK0002');

        $this->actingAs($this->technician)
            ->post(route('device-recovery.capture', [$case, $item]), [
                'serial_number' => 'FHTTPICK0002',
                'condition' => 'good',
                'label_sn_photos' => [UploadedFile::fake()->image('label.jpg', 200, 200)],
                'idempotency_key' => 'pickup-label-only',
            ])
            ->assertSessionHasErrors(['device_photos'])
            ->assertSessionDoesntHaveErrors(['label_sn_photos']);

        $this->assertSame(DeviceRecoveryService::STATUS_ASSIGNED, $case->fresh()->status);
        // Uploaded file is removed when the save is rejected.
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    public function test_one_form_with_both_photo_kinds_starts_and_captures_atomically(): void
    {
        [$case, $item] = $this->assignedCase('FHTTPICK0003');

        $this->actingAs($this->technician)
            ->post(route('device-recovery.capture', [$case, $item]), [
                'serial_number' => 'fhtt-pick-0003',
                'condition' => 'good',
                'reason' => 'Diambil di rumah pelanggan',
                'label_sn_photos' => [UploadedFile::fake()->image('label.jpg', 200, 200)],
                'device_photos' => [
                    UploadedFile::fake()->image('onu-1.jpg', 200, 200),
                    UploadedFile::fake()->image('onu-2.jpg', 200, 200),
                ],
                'idempotency_key' => 'pickup-ok',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $case->refresh();
        $item->refresh();
        $this->assertSame(DeviceRecoveryService::STATUS_CARRIED, $case->status);
        $this->assertNotNull($case->started_at);
        $this->assertSame(DeviceRecoveryService::ITEM_CARRIED, $item->status);
        $this->assertSame('FHTTPICK0003', $item->captured_serial_number);
        $this->assertSame((int) $this->technician->id, (int) $item->captured_by);
        $this->assertCount(3, $item->photo_paths);
        $this->assertSame(['label_sn', 'perangkat_utuh', 'perangkat_utuh'], $item->meta['photo_kinds']);
        foreach ($item->photo_paths as $path) {
            Storage::disk('private')->assertExists($path);
        }

        $events = DeviceRecoveryEvent::query()->where('recovery_case_id', $case->id)->orderBy('id')->pluck('event_type')->all();
        $this->assertSame(['case_created', 'assigned', 'pickup_started', 'serial_captured'], $events);

        // Retry with the same key is idempotent: no duplicate events, extra uploads cleaned up.
        $this->actingAs($this->technician)
            ->post(route('device-recovery.capture', [$case, $item]), [
                'serial_number' => 'fhtt-pick-0003',
                'condition' => 'good',
                'label_sn_photos' => [UploadedFile::fake()->image('label.jpg', 200, 200)],
                'device_photos' => [UploadedFile::fake()->image('onu-1.jpg', 200, 200)],
                'idempotency_key' => 'pickup-ok',
            ])
            ->assertRedirect();
        $this->assertSame(4, DeviceRecoveryEvent::query()->where('recovery_case_id', $case->id)->count());
        $this->assertCount(3, Storage::disk('private')->allFiles());

        $this->actingAs($this->admin)
            ->getJson(route('device-recovery.show', $case))
            ->assertOk()
            ->assertJsonPath('items.0.photos.0.kind', 'label_sn')
            ->assertJsonPath('items.0.photos.1.kind', 'perangkat_utuh');
    }

    public function test_lost_device_needs_a_note_but_no_photos_and_goes_to_exception(): void
    {
        [$case, $item] = $this->assignedCase('FHTTPICK0004');

        $this->actingAs($this->technician)
            ->post(route('device-recovery.capture', [$case, $item]), [
                'condition' => 'lost',
                'idempotency_key' => 'pickup-lost-no-note',
            ])
            ->assertSessionHasErrors(['reason'])
            ->assertSessionDoesntHaveErrors(['label_sn_photos', 'device_photos']);
        $this->assertSame(DeviceRecoveryService::STATUS_ASSIGNED, $case->fresh()->status);

        $this->actingAs($this->technician)
            ->post(route('device-recovery.capture', [$case, $item]), [
                'condition' => 'lost',
                'reason' => 'Pelanggan pindah, perangkat tidak ada',
                'idempotency_key' => 'pickup-lost',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(DeviceRecoveryService::STATUS_EXCEPTION, $case->fresh()->status);
        $this->assertSame(DeviceRecoveryService::CONDITION_LOST, $item->fresh()->condition);
        $this->assertSame(DeviceRecoveryService::ITEM_EXCEPTION, $item->fresh()->status);
    }

    public function test_damaged_device_still_requires_both_photo_kinds(): void
    {
        [$case, $item] = $this->assignedCase('FHTTPICK0005');

        $this->actingAs($this->technician)
            ->post(route('device-recovery.capture', [$case, $item]), [
                'serial_number' => 'FHTTPICK0005',
                'condition' => 'damaged',
                'reason' => 'Casing pecah',
                'device_photos' => [UploadedFile::fake()->image('onu.jpg', 200, 200)],
                'idempotency_key' => 'pickup-damaged',
            ])
            ->assertSessionHasErrors(['label_sn_photos']);

        $this->assertSame(DeviceRecoveryService::STATUS_ASSIGNED, $case->fresh()->status);
    }

    public function test_other_technician_cannot_record_pickup(): void
    {
        [$case, $item] = $this->assignedCase('FHTTPICK0006');
        $other = User::factory()->create(['role' => 'teknisi']);

        $this->actingAs($other)
            ->post(route('device-recovery.capture', [$case, $item]), [
                'serial_number' => 'FHTTPICK0006',
                'condition' => 'good',
                'label_sn_photos' => [UploadedFile::fake()->image('label.jpg', 200, 200)],
                'device_photos' => [UploadedFile::fake()->image('onu.jpg', 200, 200)],
                'idempotency_key' => 'pickup-other-tech',
            ])
            ->assertForbidden();

        $this->assertSame(DeviceRecoveryService::STATUS_ASSIGNED, $case->fresh()->status);
    }

    public function test_legacy_started_case_and_legacy_photos_remain_usable(): void
    {
        [$case, $item] = $this->assignedCase('FHTTPICK0007');
        // Legacy flow: separate start, then the new form still works.
        $case = $this->service->start($case, $this->technician, 'legacy-start-'.$case->id);
        $this->assertSame(DeviceRecoveryService::STATUS_PICKUP_STARTED, $case->status);

        $this->actingAs($this->technician)
            ->post(route('device-recovery.capture', [$case, $item]), [
                'serial_number' => 'FHTTPICK0007',
                'condition' => 'good',
                'label_sn_photos' => [UploadedFile::fake()->image('label.jpg', 200, 200)],
                'device_photos' => [UploadedFile::fake()->image('onu.jpg', 200, 200)],
                'idempotency_key' => 'pickup-after-legacy-start',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertSame(DeviceRecoveryService::STATUS_CARRIED, $case->fresh()->status);
        $this->assertSame(1, DeviceRecoveryEvent::query()->where('recovery_case_id', $case->id)->where('event_type', 'pickup_started')->count());

        // Legacy item: plain photo_paths, no photo kinds in meta.
        [$legacyCase, $legacyItem] = $this->assignedCase('FHTTPICK0008');
        Storage::disk('private')->put('device-recovery/legacy.jpg', 'legacy-image');
        DeviceRecoveryCase::query()->whereKey($legacyCase->id)->update(['status' => DeviceRecoveryService::STATUS_CARRIED]);
        $legacyItem->forceFill([
            'status' => DeviceRecoveryService::ITEM_CARRIED,
            'condition' => DeviceRecoveryService::CONDITION_GOOD,
            'captured_serial_number' => 'FHTTPICK0008',
            'photo_paths' => ['device-recovery/legacy.jpg'],
            'meta' => null,
        ])->save();

        $this->actingAs($this->admin)
            ->getJson(route('device-recovery.show', $legacyCase))
            ->assertOk()
            ->assertJsonPath('items.0.photos.0.index', 0)
            ->assertJsonPath('items.0.photos.0.kind', null);
        $this->actingAs($this->admin)
            ->get(route('device-recovery.index'))
            ->assertOk();
    }

    /**
     * @return array{0: DeviceRecoveryCase, 1: DeviceRecoveryItem}
     */
    private function assignedCase(string $serial): array
    {
        $this->seq++;
        $customer = Customer::create([
            'code' => 'PKA-'.$this->seq,
            'name' => 'Pickup customer '.$this->seq,
            'phone' => '0813000000'.$this->seq,
            'address' => 'Alamat '.$this->seq,
            'area_id' => $this->area->id,
            'package_id' => $this->package->id,
            'pppoe_user' => 'pka-'.$this->seq,
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
        $case = $this->service->assign($case, $this->admin, $this->technician->id, null, 'assign-'.$case->id);
        $item = DeviceRecoveryItem::query()->where('recovery_case_id', $case->id)->firstOrFail();

        return [$case, $item];
    }
}
