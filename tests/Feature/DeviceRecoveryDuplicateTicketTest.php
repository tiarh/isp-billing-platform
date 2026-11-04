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
use Illuminate\Support\Facades\Bus;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\StubsLifecycleGuard;
use Tests\TestCase;

/**
 * Owner 2026-10-10: a dismantle ticket filed after the device is already at
 * the office (or on its way) links to that case and closes, instead of
 * opening a second pickup and sending the technician an assignment WA.
 */
#[Group('blocking-core')]
class DeviceRecoveryDuplicateTicketTest extends TestCase
{
    use RefreshDatabase;
    use StubsLifecycleGuard;

    private DeviceRecoveryService $service;

    private User $admin;

    private User $technician;

    private Area $area;

    private Package $package;

    private MockInterface $notifier;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allowCustomerLifecycleGuard();
        config(['device-recovery.mode' => 'write', 'device-recovery.duplicate_window_days' => 30]);
        Bus::fake([DeleteDismantledOnuJob::class, EnsureCustomerCurrentInvoiceJob::class]);

        $this->notifier = Mockery::mock(NotificationService::class);
        $this->notifier->shouldIgnoreMissing(true);
        app()->instance(NotificationService::class, $this->notifier);

        $this->service = app(DeviceRecoveryService::class);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->technician = User::factory()->create(['role' => 'teknisi', 'phone' => '628120000018']);
        $this->area = Area::create(['name' => 'Dup Area', 'code' => 'DUP']);
        $this->package = Package::create(['code' => 'DUP-PKG', 'name' => 'Dup package', 'price' => 100000]);
    }

    public function test_ticket_for_customer_with_received_device_links_and_closes_without_assignment_wa(): void
    {
        $customer = $this->customer('FHTTDUP00001');
        $received = $this->receivedCase($customer, 'FHTTDUP00001');

        $this->notifier->shouldReceive('sendTicketCreatedToGroup')->once()->andReturn(true);
        $this->notifier->shouldNotReceive('sendTicketAssignment');
        $this->notifier->shouldNotReceive('sendRecoveryAssignment');

        $this->storeDismantleTicket($customer, $this->technician->id)
            ->assertSessionHas('success', fn (string $message): bool => str_contains($message, 'ditutup otomatis'))
            ->assertSessionMissing('warning');

        $this->assertSame(1, DeviceRecoveryCase::query()->count());
        $ticket = SupportTicket::query()->whereKeyNot($received->ticket_id)->sole();
        $this->assertSame('closed', $ticket->status);
        $this->assertNotNull($ticket->resolved_at);
        $this->assertSame($received->id, (int) $ticket->meta[DeviceRecoveryService::TICKET_LINKED_META]);

        $linkActivity = TicketActivity::query()->where('ticket_id', $ticket->id)->where('action', DeviceRecoveryService::TICKET_LINKED_ACTION)->sole();
        $this->assertSame($received->id, (int) $linkActivity->new_value['recovery_case_id']);
        $this->assertStringContainsString('sudah diterima gudang', $linkActivity->description);
        $this->assertTrue(TicketActivity::query()->where('ticket_id', $ticket->id)->where('description', 'like', '%ditutup otomatis%')->exists());

        $event = DeviceRecoveryEvent::query()->where('recovery_case_id', $received->id)->where('event_type', 'duplicate_ticket_linked')->sole();
        $this->assertSame((int) $ticket->id, (int) $event->metadata['linked_ticket_id']);
        $this->assertTrue($event->metadata['ticket_auto_closed']);

        // The earlier case is untouched.
        $this->assertSame(DeviceRecoveryService::STATUS_RECEIVED, $received->fresh()->status);

        // Show page points at the linked case.
        $this->actingAs($this->admin)->get(route('tickets.show', $ticket))
            ->assertInertia(fn ($page) => $page
                ->where('deviceRecovery.case.id', $received->id)
                ->where('deviceRecovery.case.linked', true));
    }

    public function test_ticket_for_customer_with_carried_device_links_and_closes(): void
    {
        $customer = $this->customer('FHTTDUP00002');
        $carried = $this->carriedCase($customer, 'FHTTDUP00002');

        $this->notifier->shouldNotReceive('sendTicketAssignment');
        $this->notifier->shouldNotReceive('sendRecoveryAssignment');

        $this->storeDismantleTicket($customer, $this->technician->id);

        $this->assertSame(1, DeviceRecoveryCase::query()->count());
        $ticket = SupportTicket::query()->whereKeyNot($carried->ticket_id)->sole();
        $this->assertSame('closed', $ticket->status);
        $this->assertSame(DeviceRecoveryService::STATUS_CARRIED, $carried->fresh()->status);
        $this->assertStringContainsString('sedang dibawa teknisi', TicketActivity::query()->where('ticket_id', $ticket->id)->where('action', DeviceRecoveryService::TICKET_LINKED_ACTION)->sole()->description);
    }

    public function test_window_boundary_inside_links_and_outside_creates_new_case(): void
    {
        $inside = $this->customer('FHTTDUP00003');
        $this->travelTo(now()->subDays(30)->addMinutes(5));
        $this->receivedCase($inside, 'FHTTDUP00003');
        $this->travelBack();

        $insideCase = $this->service->createFromApprovedDismantle($this->ticket($inside), $this->admin);
        $this->assertSame(1, DeviceRecoveryCase::query()->where('customer_id', $inside->id)->count());
        $this->assertSame(DeviceRecoveryService::STATUS_RECEIVED, $insideCase->status);

        $outside = $this->customer('FHTTDUP00004');
        $this->travelTo(now()->subDays(30)->subMinutes(5));
        $this->receivedCase($outside, 'FHTTDUP00004');
        $this->travelBack();

        $ticket = $this->ticket($outside, $this->technician);
        $newCase = $this->service->createFromApprovedDismantle($ticket, $this->admin);
        $this->assertSame((int) $ticket->id, (int) $newCase->ticket_id);
        $this->assertSame(DeviceRecoveryService::STATUS_ASSIGNED, $newCase->status);
        $this->assertSame(2, DeviceRecoveryCase::query()->where('customer_id', $outside->id)->count());
    }

    public function test_reinstall_after_window_creates_case_and_notifies_technician(): void
    {
        $customer = $this->customer('FHTTDUP00005');
        $this->travelTo(now()->subDays(45));
        $this->receivedCase($customer, 'FHTTDUP00005');
        $this->travelBack();
        $customer->forceFill(['status' => 'active'])->save();

        $this->notifier->shouldReceive('sendTicketAssignment')->once()->andReturn(true);

        $this->storeDismantleTicket($customer, $this->technician->id)
            ->assertSessionHas('success', fn (string $message): bool => str_contains($message, 'masuk Pengambilan Perangkat'));

        $this->assertSame(2, DeviceRecoveryCase::query()->where('customer_id', $customer->id)->count());
        $new = DeviceRecoveryCase::query()->latest('id')->firstOrFail();
        $this->assertSame(DeviceRecoveryService::STATUS_ASSIGNED, $new->status);
        $this->assertNotSame('closed', $new->ticket->status);
    }

    public function test_same_serial_received_on_another_case_links(): void
    {
        $previousOwner = $this->customer('FHTTDUP00006');
        $this->receivedCase($previousOwner, 'FHTTDUP00006');
        $customer = $this->customer('FHTT-DUP-00006');

        $case = $this->service->createFromApprovedDismantle($ticket = $this->ticket($customer, $this->technician), $this->admin);

        $this->assertNotSame((int) $ticket->id, (int) $case->ticket_id);
        $this->assertSame(0, DeviceRecoveryCase::query()->where('customer_id', $customer->id)->count());
        $this->assertSame('closed', $ticket->fresh()->status);
    }

    public function test_open_case_on_another_ticket_does_not_block_a_new_case(): void
    {
        $customer = $this->customer('FHTTDUP00007');
        $this->service->createFromApprovedDismantle($this->ticket($customer), $this->admin);

        $case = $this->service->createFromApprovedDismantle($ticket = $this->ticket($customer), $this->admin);

        $this->assertSame((int) $ticket->id, (int) $case->ticket_id);
        $this->assertSame(2, DeviceRecoveryCase::query()->where('customer_id', $customer->id)->count());
    }

    public function test_retype_to_dismantle_links_once_without_recovery_wa(): void
    {
        $customer = $this->customer('FHTTDUP00008');
        $received = $this->receivedCase($customer, 'FHTTDUP00008');
        $ticket = $this->ticket($customer, $this->technician, 'other');

        $this->notifier->shouldNotReceive('sendRecoveryAssignment');

        $this->actingAs($this->admin)
            ->put(route('tickets.update', $ticket), ['type' => 'dismantle', 'dismantle_reason' => 'not_continuing'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(1, DeviceRecoveryCase::query()->count());
        $this->assertSame('closed', $ticket->fresh()->status);

        // Re-saving the ticket (or re-running the hook) stays a no-op.
        $this->service->ensureForDismantleTicket($ticket->fresh(), $this->admin);
        $this->assertSame(1, TicketActivity::query()->where('ticket_id', $ticket->id)->where('action', DeviceRecoveryService::TICKET_LINKED_ACTION)->count());
        $this->assertSame(1, DeviceRecoveryEvent::query()->where('recovery_case_id', $received->id)->where('event_type', 'duplicate_ticket_linked')->count());
    }

    public function test_window_zero_disables_the_check(): void
    {
        config(['device-recovery.duplicate_window_days' => 0]);
        $customer = $this->customer('FHTTDUP00009');
        $this->receivedCase($customer, 'FHTTDUP00009');

        $case = $this->service->createFromApprovedDismantle($ticket = $this->ticket($customer), $this->admin);

        $this->assertSame((int) $ticket->id, (int) $case->ticket_id);
        $this->assertSame(DeviceRecoveryService::STATUS_WAITING_ASSIGNMENT, $case->status);
    }

    public function test_quick_record_credits_ticket_to_technician_without_wa(): void
    {
        $customer = $this->customer('FHTTDUP00010');
        $ticket = $this->ticket($customer);
        $case = $this->service->createFromApprovedDismantle($ticket, $this->admin);
        $this->assertNull($ticket->fresh()->assigned_to);

        $this->notifier->shouldNotReceive('sendTicketAssignment');
        $this->notifier->shouldNotReceive('sendRecoveryAssignment');

        $case = $this->quickRecord($case, 'FHTTDUP00010', 'quick-credit');

        $ticket->refresh();
        $this->assertSame(DeviceRecoveryService::STATUS_RECEIVED, $case->status);
        $this->assertSame((int) $this->technician->id, (int) $ticket->assigned_to);
        $this->assertNotNull($ticket->assigned_at);
        $this->assertSame('closed', $ticket->status);
        $activity = TicketActivity::query()->where('ticket_id', $ticket->id)->where('action', 'assigned')->sole();
        $this->assertTrue($activity->new_value['auto']);
        $this->assertSame((int) $this->technician->id, (int) $activity->new_value['assignee_id']);
        $captured = DeviceRecoveryEvent::query()->where('recovery_case_id', $case->id)->where('event_type', 'serial_captured')->sole();
        $this->assertTrue($captured->metadata['ticket_assignee_set']);
    }

    private function customer(string $serial): Customer
    {
        $this->seq++;

        return Customer::create([
            'code' => 'DUP-'.$this->seq,
            'name' => 'Dup customer '.$this->seq,
            'phone' => '0816000000'.$this->seq,
            'address' => 'Alamat '.$this->seq,
            'area_id' => $this->area->id,
            'package_id' => $this->package->id,
            'pppoe_user' => 'dup-'.$this->seq,
            'onu_serial' => $serial,
            'status' => 'active',
        ]);
    }

    private function ticket(Customer $customer, ?User $assignee = null, string $type = 'dismantle'): SupportTicket
    {
        return SupportTicket::create([
            'customer_id' => $customer->id,
            'area_id' => $customer->area_id,
            'created_by' => $this->admin->id,
            'assigned_to' => $assignee?->id,
            'gangguan' => 'Dismantle',
            'subject' => 'Dismantle',
            'status' => $assignee ? 'assigned' : 'open',
            'priority' => 'normal',
            'type' => $type,
        ]);
    }

    private function receivedCase(Customer $customer, string $serial): DeviceRecoveryCase
    {
        $case = $this->service->createFromApprovedDismantle($this->ticket($customer), $this->admin);
        $case = $this->quickRecord($case, $serial, 'quick-'.$case->id);
        $this->assertSame(DeviceRecoveryService::STATUS_RECEIVED, $case->status);

        return $case;
    }

    private function carriedCase(Customer $customer, string $serial): DeviceRecoveryCase
    {
        $case = $this->service->createFromApprovedDismantle($this->ticket($customer, $this->technician), $this->admin);
        $item = DeviceRecoveryItem::query()->where('recovery_case_id', $case->id)->firstOrFail();
        $case = $this->service->recordPickup($case, $item, $this->technician, $serial, 'good', null, $this->photos(), 'pickup-'.$case->id);
        $this->assertSame(DeviceRecoveryService::STATUS_CARRIED, $case->status);

        return $case;
    }

    private function quickRecord(DeviceRecoveryCase $case, string $serial, string $key): DeviceRecoveryCase
    {
        return $this->service->quickRecord($case, $this->admin, (int) $this->technician->id, $serial, 'good', null, $this->photos(), $key);
    }

    /** @return list<array{path: string, kind: string}> */
    private function photos(): array
    {
        return [
            ['path' => 'device-recovery/label.jpg', 'kind' => DeviceRecoveryService::PHOTO_KIND_LABEL_SN],
            ['path' => 'device-recovery/onu.jpg', 'kind' => DeviceRecoveryService::PHOTO_KIND_DEVICE],
        ];
    }

    private function storeDismantleTicket(Customer $customer, ?int $assigneeId)
    {
        return $this->actingAs($this->admin)->post(route('tickets.store'), [
            'customer_id' => $customer->id,
            'customer_name' => '',
            'customer_phone' => '',
            'customer_coords' => '',
            'gangguan' => 'Dismantle tidak lanjut',
            'type' => 'dismantle',
            'dismantle_reason' => 'not_continuing',
            'priority' => 'normal',
            'assigned_to' => $assigneeId ?? '__none__',
        ])->assertRedirect();
    }
}
