<?php

namespace Tests\Feature;

use App\Jobs\DeleteDismantledOnuJob;
use App\Jobs\EnsureCustomerCurrentInvoiceJob;
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
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\StubsLifecycleGuard;
use Tests\TestCase;

/**
 * Owner 2026-10-10: "Lapor pengambilan tanpa tiket". A technician reports a
 * device collected before any admin ticket: Dismantle ticket + carried case,
 * group WA only, admin confirms with the normal warehouse receipt.
 */
#[Group('blocking-core')]
class DeviceRecoveryTechnicianReportTest extends TestCase
{
    use RefreshDatabase;
    use StubsLifecycleGuard;

    private User $admin;

    private User $technician;

    private Customer $customer;

    private MockInterface $notifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allowCustomerLifecycleGuard();
        config(['device-recovery.mode' => 'write', 'device-recovery.duplicate_window_days' => 30]);
        Bus::fake([DeleteDismantledOnuJob::class, EnsureCustomerCurrentInvoiceJob::class]);
        Storage::fake('private');

        $this->notifier = Mockery::mock(NotificationService::class);
        $this->notifier->shouldIgnoreMissing(true);
        app()->instance(NotificationService::class, $this->notifier);

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->technician = User::factory()->create(['role' => 'teknisi', 'phone' => '628120000019']);
        $area = Area::create(['name' => 'Report Area', 'code' => 'RPT']);
        $package = Package::create(['code' => 'RPT-PKG', 'name' => 'Report package', 'price' => 100000]);
        $this->customer = Customer::create([
            'code' => 'RPT-CUSTOMER',
            'name' => 'Report customer',
            'phone' => '08120000020',
            'address' => 'Jl. Laporan 1',
            'area_id' => $area->id,
            'package_id' => $package->id,
            'pppoe_user' => 'rpt-customer',
            'onu_serial' => 'FHTTRPT00001',
            'status' => 'active',
        ]);
    }

    public function test_only_technicians_can_report_or_search(): void
    {
        foreach (['admin', 'noc', 'superadmin'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user)->post(route('device-recovery.report'), $this->payload())->assertForbidden();
            $this->actingAs($user)->getJson(route('device-recovery.report-customers', ['q' => 'Report']))->assertForbidden();
        }

        $this->assertSame(0, SupportTicket::query()->count());
        $this->assertSame(0, DeviceRecoveryCase::query()->count());
    }

    public function test_routes_have_read_only_guard_and_rate_limit(): void
    {
        $report = app('router')->getRoutes()->getByName('device-recovery.report')->gatherMiddleware();
        $this->assertContains('device-recovery.writable', $report);
        $this->assertContains('throttle:10,60', $report);
        $this->assertContains('throttle:60,1', app('router')->getRoutes()->getByName('device-recovery.report-customers')->gatherMiddleware());
    }

    public function test_customer_search_returns_minimal_fields(): void
    {
        $response = $this->actingAs($this->technician)
            ->getJson(route('device-recovery.report-customers', ['q' => 'Report cust']))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $this->customer->id)
            ->assertJsonPath('0.onu_serial', 'FHTTRPT00001');
        $this->assertArrayNotHasKey('phone', $response->json(0));
        $this->assertArrayNotHasKey('address', $response->json(0));

        $this->actingAs($this->technician)
            ->getJson(route('device-recovery.report-customers', ['q' => 'ab']))
            ->assertUnprocessable();
    }

    public function test_happy_path_creates_ticket_and_carried_case_then_admin_receives(): void
    {
        $this->notifier->shouldReceive('sendTicketCreatedToGroup')->once()
            ->withArgs(fn (SupportTicket $ticket): bool => str_contains($ticket->gangguan, 'konfirmasi'))
            ->andReturn(true);
        $this->notifier->shouldNotReceive('sendTicketAssignment');
        $this->notifier->shouldNotReceive('sendRecoveryAssignment');

        $this->actingAs($this->technician)
            ->post(route('device-recovery.report'), $this->payload(['serial_number' => 'fhtt-rpt-00001']))
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $ticket = SupportTicket::query()->sole();
        $this->assertSame('dismantle', $ticket->type);
        $this->assertSame((int) $this->technician->id, (int) $ticket->created_by);
        $this->assertSame((int) $this->technician->id, (int) $ticket->assigned_to);
        $this->assertSame('in_progress', $ticket->status);
        $this->assertSame('other', $ticket->dismantle_reason);
        $this->assertStringContainsString('pelanggan pindah mendadak', $ticket->dismantle_reason_note);
        $this->assertTrue($ticket->meta['technician_report']);
        $this->assertTrue(TicketActivity::query()->where('ticket_id', $ticket->id)->where('action', 'created')->exists());

        $case = DeviceRecoveryCase::query()->sole();
        $this->assertSame((int) $ticket->id, (int) $case->ticket_id);
        $this->assertSame(DeviceRecoveryService::STATUS_CARRIED, $case->status);
        $this->assertSame((int) $this->technician->id, (int) $case->assigned_to);
        $this->assertSame('technician_report', $case->meta['source']);
        $item = DeviceRecoveryItem::query()->sole();
        $this->assertSame(DeviceRecoveryService::ITEM_CARRIED, $item->status);
        $this->assertSame('FHTTRPT00001', $item->captured_serial_number);
        $this->assertSame('FHTTRPT00001', $item->expected_serial_number);
        $this->assertSame(['label_sn', 'perangkat_utuh'], $item->meta['photo_kinds']);
        $this->assertCount(2, Storage::disk('private')->allFiles());
        $this->assertSame(['case_created', 'serial_captured'], DeviceRecoveryEvent::query()->where('recovery_case_id', $case->id)->orderBy('id')->pluck('event_type')->all());

        // Customer stays as is until the admin confirms the receipt.
        $this->assertSame('active', $this->customer->fresh()->status);

        $this->actingAs($this->admin)
            ->post(route('device-recovery.receive', $case), [
                'serials' => [$item->id => 'FHTTRPT00001'],
                'receipt_photos' => [UploadedFile::fake()->image('gudang.jpg', 200, 200)],
                'idempotency_key' => 'report-receive',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(DeviceRecoveryService::STATUS_RECEIVED, $case->fresh()->status);
        $this->assertSame('terminated', $this->customer->fresh()->status);
        $this->assertSame('closed', $ticket->fresh()->status);
    }

    public function test_replay_with_same_key_does_not_duplicate(): void
    {
        $this->actingAs($this->technician)->post(route('device-recovery.report'), $this->payload())->assertSessionHasNoErrors();
        $this->actingAs($this->technician)->post(route('device-recovery.report'), $this->payload())->assertSessionHasNoErrors();

        $this->assertSame(1, SupportTicket::query()->count());
        $this->assertSame(1, DeviceRecoveryCase::query()->count());
        $this->assertCount(2, Storage::disk('private')->allFiles());
    }

    public function test_photos_and_emergency_reason_are_required(): void
    {
        $this->actingAs($this->technician)
            ->post(route('device-recovery.report'), $this->payload(['label_sn_photos' => [], 'device_photos' => []]))
            ->assertSessionHasErrors(['label_sn_photos', 'device_photos']);

        $this->actingAs($this->technician)
            ->post(route('device-recovery.report'), $this->payload(['emergency_reason' => 'singkat']))
            ->assertSessionHasErrors(['emergency_reason']);

        $this->actingAs($this->technician)
            ->post(route('device-recovery.report'), $this->payload(['serial_number' => '']))
            ->assertSessionHasErrors(['serial_number']);

        $this->assertSame(0, SupportTicket::query()->count());
        $this->assertSame(0, DeviceRecoveryCase::query()->count());
        $this->assertCount(0, Storage::disk('private')->allFiles());
    }

    public function test_lost_device_without_photos_goes_to_exception(): void
    {
        $this->actingAs($this->technician)
            ->post(route('device-recovery.report'), $this->payload([
                'condition' => 'lost',
                'serial_number' => '',
                'reason' => 'Perangkat tidak ada di rumah pelanggan',
                'label_sn_photos' => [],
                'device_photos' => [],
            ]))
            ->assertSessionHasNoErrors();

        $case = DeviceRecoveryCase::query()->sole();
        $this->assertSame(DeviceRecoveryService::STATUS_EXCEPTION, $case->status);
        $this->assertSame(DeviceRecoveryService::ITEM_EXCEPTION, DeviceRecoveryItem::query()->sole()->status);
    }

    public function test_serial_mismatch_goes_to_exception_and_requires_note(): void
    {
        $this->actingAs($this->technician)
            ->post(route('device-recovery.report'), $this->payload(['serial_number' => 'ZTEGRPT99999']))
            ->assertSessionHasErrors(['reason']);
        $this->assertSame(0, SupportTicket::query()->count());

        $this->actingAs($this->technician)
            ->post(route('device-recovery.report'), $this->payload([
                'serial_number' => 'ZTEGRPT99999',
                'reason' => 'Pelanggan pernah ganti ONU sendiri',
                'idempotency_key' => 'report-mismatch',
            ]))
            ->assertSessionHasNoErrors();

        $case = DeviceRecoveryCase::query()->sole();
        $item = DeviceRecoveryItem::query()->sole();
        $this->assertSame(DeviceRecoveryService::STATUS_EXCEPTION, $case->status);
        $this->assertSame(DeviceRecoveryService::CONDITION_MISMATCH, $item->condition);
        $this->assertSame('ZTEGRPT99999', $item->captured_serial_number);
        $this->assertSame('pickup_exception', DeviceRecoveryEvent::query()->where('idempotency_key', 'report-mismatch')->sole()->event_type);
    }

    public function test_rejects_customer_with_open_case(): void
    {
        $ticket = SupportTicket::create([
            'customer_id' => $this->customer->id,
            'created_by' => $this->admin->id,
            'gangguan' => 'Dismantle',
            'subject' => 'Dismantle',
            'status' => 'open',
            'priority' => 'normal',
            'type' => 'dismantle',
        ]);
        app(DeviceRecoveryService::class)->createFromApprovedDismantle($ticket, $this->admin);

        $this->actingAs($this->technician)
            ->post(route('device-recovery.report'), $this->payload())
            ->assertSessionHasErrors(['customer_id']);

        $this->assertSame(1, SupportTicket::query()->count());
        $this->assertSame(1, DeviceRecoveryCase::query()->count());
        $this->assertCount(0, Storage::disk('private')->allFiles());
    }

    public function test_rejects_customer_inside_duplicate_window(): void
    {
        $this->actingAs($this->technician)->post(route('device-recovery.report'), $this->payload())->assertSessionHasNoErrors();
        $case = DeviceRecoveryCase::query()->sole();
        $item = DeviceRecoveryItem::query()->sole();
        app(DeviceRecoveryService::class)->receiveVerified($case, $this->admin, [$item->id => 'FHTTRPT00001'], ['device-recovery/gudang.jpg'], false, 'receive-window');
        $this->assertSame(DeviceRecoveryService::STATUS_RECEIVED, $case->fresh()->status);

        $this->actingAs($this->technician)
            ->post(route('device-recovery.report'), $this->payload(['idempotency_key' => 'report-second']))
            ->assertSessionHasErrors(['customer_id']);

        $this->assertSame(1, DeviceRecoveryCase::query()->count());
    }

    private function payload(array $overrides = []): array
    {
        return [
            'customer_id' => $this->customer->id,
            'serial_number' => 'FHTTRPT00001',
            'condition' => 'good',
            'reason' => '',
            'emergency_reason' => 'pelanggan pindah mendadak, perangkat diserahkan langsung',
            'label_sn_photos' => [UploadedFile::fake()->image('label.jpg', 200, 200)],
            'device_photos' => [UploadedFile::fake()->image('onu.jpg', 200, 200)],
            'idempotency_key' => 'report-1',
            ...$overrides,
        ];
    }
}
