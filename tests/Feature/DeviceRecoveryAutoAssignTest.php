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
use App\Models\User;
use App\Services\DeviceRecoveryService;
use App\Services\EvolutionApiService;
use App\Services\NotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Owner 2026-10-09: a pickup case follows its dismantle ticket's technician
 * until pickup starts, and the technician gets the pickup info on WA.
 */
#[Group('blocking-core')]
class DeviceRecoveryAutoAssignTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $technician;

    private User $otherTechnician;

    private Customer $customer;

    private MockInterface $notifier;

    protected function setUp(): void
    {
        parent::setUp();
        config(['device-recovery.mode' => 'write']);
        Bus::fake([DeleteDismantledOnuJob::class, EnsureCustomerCurrentInvoiceJob::class]);

        $this->notifier = Mockery::mock(NotificationService::class);
        $this->notifier->shouldIgnoreMissing(true);
        app()->instance(NotificationService::class, $this->notifier);

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->technician = User::factory()->create(['role' => 'teknisi', 'phone' => '628111000001']);
        $this->otherTechnician = User::factory()->create(['role' => 'teknisi', 'phone' => '628111000002']);
        $area = Area::create(['name' => 'Auto Assign Area', 'code' => 'AAS']);
        $package = Package::create(['code' => 'AAS-PKG', 'name' => 'Auto assign package', 'price' => 100000]);
        $this->customer = Customer::create([
            'code' => 'AAS-CUSTOMER',
            'name' => 'Auto assign customer',
            'phone' => '08123456780',
            'address' => 'Jl. Pengambilan 1',
            'area_id' => $area->id,
            'package_id' => $package->id,
            'pppoe_user' => 'aas-customer',
            'onu_serial' => 'FHTT0000A023',
            'status' => 'isolated',
        ]);
    }

    public function test_dismantle_ticket_with_technician_creates_assigned_case_and_sends_one_wa(): void
    {
        $this->notifier->shouldReceive('sendTicketAssignment')->once()->andReturn(true);
        $this->notifier->shouldNotReceive('sendRecoveryAssignment');

        $this->storeDismantleTicket($this->technician->id)->assertSessionHas('success')->assertSessionMissing('warning');

        $case = DeviceRecoveryCase::query()->firstOrFail();
        $this->assertSame(DeviceRecoveryService::STATUS_ASSIGNED, $case->status);
        $this->assertSame($this->technician->id, (int) $case->assigned_to);
        $this->assertNull($case->scheduled_at);

        $event = DeviceRecoveryEvent::query()->where('recovery_case_id', $case->id)->where('event_type', 'assigned')->sole();
        $this->assertSame((int) $this->admin->id, (int) $event->actor_id);
        $this->assertSame(DeviceRecoveryService::STATUS_WAITING_ASSIGNMENT, $event->from_status);
        $this->assertSame(DeviceRecoveryService::TICKET_ASSIGNEE_REASON, $event->reason);
        $this->assertSame('ticket_assignee', $event->metadata['source']);
    }

    public function test_dismantle_ticket_without_assignee_stays_waiting(): void
    {
        $this->notifier->shouldNotReceive('sendRecoveryAssignment');

        $this->storeDismantleTicket(null)->assertSessionHas('success');

        $case = DeviceRecoveryCase::query()->firstOrFail();
        $this->assertSame(DeviceRecoveryService::STATUS_WAITING_ASSIGNMENT, $case->status);
        $this->assertNull($case->assigned_to);
    }

    public function test_non_technician_ticket_assignee_leaves_case_waiting(): void
    {
        $noc = User::factory()->create(['role' => 'noc']);

        $this->storeDismantleTicket($noc->id)->assertSessionHas('success');

        $this->assertSame(DeviceRecoveryService::STATUS_WAITING_ASSIGNMENT, DeviceRecoveryCase::query()->firstOrFail()->status);
    }

    public function test_reassigning_ticket_moves_pre_start_case_and_keeps_schedule(): void
    {
        $case = $this->caseFor($this->technician);
        $schedule = CarbonImmutable::now()->addDay()->startOfMinute();
        $case = app(DeviceRecoveryService::class)->schedule($case, $this->admin, $schedule, 'schedule-'.$case->id);

        $this->notifier->shouldReceive('sendTicketAssignment')->twice()->andReturn(true);
        $this->notifier->shouldNotReceive('sendRecoveryAssignment');

        $this->actingAs($this->admin)
            ->post(route('tickets.assign', $case->ticket_id), ['assigned_to' => $this->otherTechnician->id])
            ->assertRedirect();

        $case->refresh();
        $this->assertSame($this->otherTechnician->id, (int) $case->assigned_to);
        $this->assertSame(DeviceRecoveryService::STATUS_SCHEDULED, $case->status);
        $this->assertTrue($schedule->equalTo($case->scheduled_at));
        $this->assertSame(2, $case->events()->where('event_type', 'assigned')->count());

        // Same technician again: no new case event.
        $this->actingAs($this->admin)
            ->post(route('tickets.assign', $case->ticket_id), ['assigned_to' => $this->otherTechnician->id]);
        $this->assertSame(2, $case->events()->where('event_type', 'assigned')->count());
    }

    public function test_reassigning_ticket_after_pickup_carried_does_not_touch_case(): void
    {
        $case = $this->caseFor($this->technician);
        $service = app(DeviceRecoveryService::class);
        $item = DeviceRecoveryItem::query()->where('recovery_case_id', $case->id)->firstOrFail();
        $case = $service->start($case, $this->technician, 'start-'.$case->id);
        $case = $service->capture($case, $item, $this->technician, 'FHTT0000A023', 'good', null, [], 'capture-'.$case->id);
        $this->assertSame(DeviceRecoveryService::STATUS_CARRIED, $case->status);
        $version = $case->version;

        $this->actingAs($this->admin)
            ->post(route('tickets.assign', $case->ticket_id), ['assigned_to' => $this->otherTechnician->id])
            ->assertRedirect();

        $case->refresh();
        $this->assertSame($this->technician->id, (int) $case->assigned_to);
        $this->assertSame(DeviceRecoveryService::STATUS_CARRIED, $case->status);
        $this->assertSame($version, $case->version);
    }

    public function test_api_unassign_returns_pre_start_case_to_queue_and_reassign_sends_pickup_wa(): void
    {
        $case = $this->caseFor($this->technician);

        $this->actingAs($this->admin)
            ->patchJson(route('api.support-tickets.update', $case->ticket_id), ['assigned_to' => null])
            ->assertOk();

        $case->refresh();
        $this->assertSame(DeviceRecoveryService::STATUS_WAITING_ASSIGNMENT, $case->status);
        $this->assertNull($case->assigned_to);
        $this->assertSame(1, $case->events()->where('event_type', 'unassigned')->count());

        $this->notifier->shouldReceive('sendRecoveryAssignment')
            ->once()
            ->with(Mockery::on(fn (DeviceRecoveryCase $sent): bool => (int) $sent->assigned_to === $this->otherTechnician->id))
            ->andReturn(true);

        $this->actingAs($this->admin)
            ->patchJson(route('api.support-tickets.update', $case->ticket_id), ['assigned_to' => $this->otherTechnician->id])
            ->assertOk();

        $this->assertSame($this->otherTechnician->id, (int) $case->fresh()->assigned_to);
    }

    public function test_retyping_assigned_ticket_to_dismantle_assigns_and_sends_pickup_wa(): void
    {
        $ticket = $this->ticket($this->technician, 'other');

        $this->notifier->shouldReceive('sendRecoveryAssignment')
            ->once()
            ->with(Mockery::on(fn (DeviceRecoveryCase $sent): bool => (int) $sent->assigned_to === $this->technician->id))
            ->andReturn(true);

        $this->actingAs($this->admin)
            ->put(route('tickets.update', $ticket), ['type' => 'dismantle', 'dismantle_reason' => 'not_continuing'])
            ->assertRedirect();
        // Idempotent: second save neither re-creates nor re-notifies.
        $this->actingAs($this->admin)
            ->put(route('tickets.update', $ticket), ['type' => 'dismantle', 'dismantle_reason' => 'not_continuing'])
            ->assertRedirect();

        $case = DeviceRecoveryCase::query()->where('ticket_id', $ticket->id)->sole();
        $this->assertSame(DeviceRecoveryService::STATUS_ASSIGNED, $case->status);
    }

    public function test_wa_failure_never_breaks_the_save(): void
    {
        $ticket = $this->ticket($this->technician, 'other');
        $this->notifier->shouldReceive('sendRecoveryAssignment')->andThrow(new \RuntimeException('gateway down'));

        $this->actingAs($this->admin)
            ->put(route('tickets.update', $ticket), ['type' => 'dismantle', 'dismantle_reason' => 'not_continuing'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(DeviceRecoveryService::STATUS_ASSIGNED, DeviceRecoveryCase::query()->sole()->status);
    }

    public function test_ticket_assignment_wa_carries_pickup_info_and_recovery_wa_has_case_details(): void
    {
        config(['app.url' => 'https://billing.example.test']);
        $case = $this->caseFor($this->technician);
        $ticket = SupportTicket::query()->with('assignee')->findOrFail($case->ticket_id);

        $sent = [];
        $evolution = Mockery::mock(EvolutionApiService::class)->makePartial();
        $evolution->shouldReceive('send')->andReturnUsing(function (string $to, string $message) use (&$sent): array {
            $sent[] = [$to, $message];

            return ['success' => true];
        });

        $evolution->sendTicketAssignment($ticket);
        $evolution->sendRecoveryAssignment($case->fresh(['ticket', 'customer', 'assignee', 'items']));

        $this->assertCount(2, $sent);
        $this->assertSame('628111000001', $sent[0][0]);
        $this->assertStringContainsString('Pengambilan Perangkat', $sent[0][1]);
        $this->assertStringContainsString('FHTT0000A023', $sent[0][1]);
        $this->assertStringContainsString('https://billing.example.test/device-recovery/'.$case->id, $sent[0][1]);

        $this->assertStringContainsString('PENGAMBILAN PERANGKAT', $sent[1][1]);
        $this->assertStringContainsString('AAS-CUSTOMER - Auto assign customer', $sent[1][1]);
        $this->assertStringContainsString('Jl. Pengambilan 1', $sent[1][1]);
        $this->assertStringContainsString('SN diambil: FHTT0000A023', $sent[1][1]);
        $this->assertStringContainsString('https://billing.example.test/device-recovery/'.$case->id, $sent[1][1]);
    }

    private function storeDismantleTicket(?int $assigneeId)
    {
        return $this->actingAs($this->admin)->post(route('tickets.store'), [
            'customer_id' => $this->customer->id,
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

    private function ticket(?User $assignee, string $type): SupportTicket
    {
        return SupportTicket::create([
            'customer_id' => $this->customer->id,
            'area_id' => $this->customer->area_id,
            'created_by' => $this->admin->id,
            'assigned_to' => $assignee?->id,
            'gangguan' => 'Dismantle',
            'subject' => 'Dismantle',
            'status' => $assignee ? 'assigned' : 'open',
            'priority' => 'normal',
            'type' => $type,
        ]);
    }

    private function caseFor(User $technician): DeviceRecoveryCase
    {
        $case = app(DeviceRecoveryService::class)->createFromApprovedDismantle($this->ticket($technician, 'dismantle'), $this->admin);
        $this->assertSame((int) $technician->id, (int) $case->assigned_to);

        return $case;
    }
}
