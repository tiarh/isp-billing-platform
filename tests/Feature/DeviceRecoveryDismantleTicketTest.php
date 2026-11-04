<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Customer;
use App\Models\DeviceRecoveryCase;
use App\Models\Package;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\DeviceRecoveryService;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * A ticket filed as "Dismantle" must land in Pengambilan Perangkat
 * (device_recovery_cases) without changing the customer or the ticket.
 */
#[Group('blocking-core')]
class DeviceRecoveryDismantleTicketTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        config(['device-recovery.mode' => 'write']);

        $notifier = Mockery::mock(NotificationService::class);
        $notifier->shouldIgnoreMissing(true);
        app()->instance(NotificationService::class, $notifier);

        $this->admin = User::factory()->create(['role' => 'admin']);
        $area = Area::create(['name' => 'Dismantle Area', 'code' => 'DSM']);
        $package = Package::create(['code' => 'DSM-PKG', 'name' => 'Dismantle package', 'price' => 100000]);
        $this->customer = Customer::create([
            'code' => 'DSM-CUSTOMER',
            'name' => 'Dismantle customer',
            'phone' => '08123456789',
            'address' => 'Dismantle address',
            'area_id' => $area->id,
            'package_id' => $package->id,
            'pppoe_user' => 'dsm-customer',
            'onu_serial' => 'fhtt-0000-A028',
            'status' => 'isolated',
        ]);
    }

    public function test_creating_dismantle_ticket_creates_pickup_case_without_mutating_customer(): void
    {
        $this->actingAs($this->admin)->post(route('tickets.store'), [
            'customer_id' => $this->customer->id,
            // The ticket form always posts these; store() reads the keys.
            'customer_name' => '',
            'customer_phone' => '',
            'customer_coords' => '',
            'gangguan' => 'Dismantle tidak lanjut',
            'type' => 'dismantle',
            'dismantle_reason' => 'not_continuing',
            'priority' => 'normal',
        ])->assertRedirect()->assertSessionHas('success');

        $ticket = SupportTicket::query()->where('customer_id', $this->customer->id)->firstOrFail();
        $this->assertSame('dismantle', $ticket->type);

        $case = DeviceRecoveryCase::query()->where('ticket_id', $ticket->id)->with('items')->firstOrFail();
        $this->assertSame(DeviceRecoveryService::STATUS_WAITING_ASSIGNMENT, $case->status);
        $this->assertSame('dismantle_ticket', $case->meta['source']);
        $this->assertNull($case->meta['lifecycle_activity_id']);
        $this->assertSame((int) $this->customer->area_id, (int) $case->area_id);
        $this->assertCount(1, $case->items);
        $this->assertSame('onu', $case->items->first()->device_type);
        $this->assertSame('FHTT0000A028', $case->items->first()->expected_serial_number);

        // Recovery never changes the customer's billing/network status.
        $this->assertSame('isolated', $this->customer->fresh()->status);
        $this->assertSame('open', $ticket->fresh()->status);
    }

    public function test_retyping_existing_ticket_to_dismantle_creates_exactly_one_case(): void
    {
        $ticket = SupportTicket::create([
            'customer_id' => $this->customer->id,
            'area_id' => $this->customer->area_id,
            'created_by' => $this->admin->id,
            'gangguan' => 'Dismantle tidak lanjut',
            'subject' => 'Dismantle tidak lanjut',
            'status' => 'assigned',
            'priority' => 'normal',
            'type' => 'other',
        ]);
        $this->assertDatabaseCount('device_recovery_cases', 0);

        $this->actingAs($this->admin)->put(route('tickets.update', $ticket), ['type' => 'dismantle', 'dismantle_reason' => 'not_continuing'])->assertRedirect();
        $this->actingAs($this->admin)->put(route('tickets.update', $ticket), ['type' => 'dismantle', 'dismantle_reason' => 'not_continuing'])->assertRedirect();

        $this->assertSame(1, DeviceRecoveryCase::query()->where('ticket_id', $ticket->id)->count());
        $this->assertSame(1, DeviceRecoveryCase::query()->firstOrFail()->events()->where('event_type', 'case_created')->count());
    }

    public function test_non_dismantle_ticket_does_not_create_case_and_still_requires_approval(): void
    {
        $this->actingAs($this->admin)->post(route('tickets.store'), [
            'customer_id' => $this->customer->id,
            // The ticket form always posts these; store() reads the keys.
            'customer_name' => '',
            'customer_phone' => '',
            'customer_coords' => '',
            'gangguan' => 'Internet mati',
            'type' => 'incident',
            'priority' => 'normal',
        ])->assertRedirect();

        $ticket = SupportTicket::query()->where('customer_id', $this->customer->id)->firstOrFail();
        $this->assertDatabaseCount('device_recovery_cases', 0);

        $this->expectException(ValidationException::class);
        app(DeviceRecoveryService::class)->createFromApprovedDismantle($ticket, $this->admin);
    }

    public function test_read_only_mode_keeps_ticket_creation_working_without_case(): void
    {
        config(['device-recovery.mode' => 'read_only']);

        $this->actingAs($this->admin)->post(route('tickets.store'), [
            'customer_id' => $this->customer->id,
            // The ticket form always posts these; store() reads the keys.
            'customer_name' => '',
            'customer_phone' => '',
            'customer_coords' => '',
            'gangguan' => 'Dismantle',
            'type' => 'dismantle',
            'dismantle_reason' => 'not_continuing',
            'priority' => 'normal',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseCount('support_tickets', 1);
        $this->assertDatabaseCount('device_recovery_cases', 0);
    }

    public function test_technician_actor_cannot_create_case_through_the_hook(): void
    {
        $technician = User::factory()->create(['role' => 'teknisi']);
        $ticket = SupportTicket::create([
            'customer_id' => $this->customer->id,
            'area_id' => $this->customer->area_id,
            'created_by' => $this->admin->id,
            'gangguan' => 'Dismantle',
            'subject' => 'Dismantle',
            'status' => 'open',
            'priority' => 'normal',
            'type' => 'dismantle',
        ]);

        $this->assertNull(app(DeviceRecoveryService::class)->ensureForDismantleTicket($ticket, $technician));
        $this->assertDatabaseCount('device_recovery_cases', 0);
    }

    public function test_ticket_without_customer_is_skipped(): void
    {
        $this->actingAs($this->admin)->post(route('tickets.store'), [
            'gangguan' => 'Dismantle tanpa pelanggan',
            'type' => 'dismantle',
            'dismantle_reason' => 'not_continuing',
            'priority' => 'normal',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseCount('device_recovery_cases', 0);
    }

    public function test_invalid_ticket_type_is_still_rejected(): void
    {
        $this->actingAs($this->admin)->post(route('tickets.store'), [
            'customer_id' => $this->customer->id,
            // The ticket form always posts these; store() reads the keys.
            'customer_name' => '',
            'customer_phone' => '',
            'customer_coords' => '',
            'gangguan' => 'Tipe aneh',
            'type' => 'bongkar',
            'priority' => 'normal',
        ])->assertSessionHasErrors('type');

        $this->assertDatabaseCount('support_tickets', 0);
    }

    public function test_show_page_exposes_dismantle_ticket_flag_and_case(): void
    {
        $this->actingAs($this->admin)->post(route('tickets.store'), [
            'customer_id' => $this->customer->id,
            // The ticket form always posts these; store() reads the keys.
            'customer_name' => '',
            'customer_phone' => '',
            'customer_coords' => '',
            'gangguan' => 'Dismantle',
            'type' => 'dismantle',
            'dismantle_reason' => 'not_continuing',
            'priority' => 'normal',
        ]);
        $ticket = SupportTicket::query()->firstOrFail();
        $case = DeviceRecoveryCase::query()->firstOrFail();

        $this->actingAs($this->admin)->get(route('tickets.show', $ticket))
            ->assertInertia(fn ($page) => $page
                ->where('deviceRecovery.dismantle_ticket', true)
                ->where('deviceRecovery.case.id', $case->id));
    }
}
