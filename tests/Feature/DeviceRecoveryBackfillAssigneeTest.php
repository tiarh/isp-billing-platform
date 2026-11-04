<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Customer;
use App\Models\DeviceRecoveryCase;
use App\Models\DeviceRecoveryEvent;
use App\Models\Package;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\DeviceRecoveryService;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Owner 2026-10-09: backfill old waiting pickup cases to their ticket
 * technician. Dry run by default; only waiting cases with a technician move.
 */
#[Group('blocking-core')]
class DeviceRecoveryBackfillAssigneeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $technician;

    private Area $area;

    private Package $package;

    private MockInterface $notifier;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['device-recovery.mode' => 'write']);

        $this->notifier = Mockery::mock(NotificationService::class);
        $this->notifier->shouldIgnoreMissing(true);
        app()->instance(NotificationService::class, $this->notifier);

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->technician = User::factory()->create(['role' => 'teknisi', 'phone' => '628111000009']);
        $this->area = Area::create(['name' => 'Backfill Area', 'code' => 'BFA']);
        $this->package = Package::create(['code' => 'BFA-PKG', 'name' => 'Backfill package', 'price' => 100000]);
    }

    public function test_dry_run_reports_without_writing(): void
    {
        $withTech = $this->legacyCase($this->technician);
        $without = $this->legacyCase(null);
        $this->notifier->shouldNotReceive('sendRecoveryAssignment');
        $events = DeviceRecoveryEvent::query()->count();

        $this->artisan('device-recovery:backfill-assignee', ['--actor' => $this->admin->id])
            ->expectsOutputToContain('mode=dry-run cases=2 would_assign=1 skipped=1')
            ->assertSuccessful();

        $this->assertNull($withTech->fresh()->assigned_to);
        $this->assertSame(DeviceRecoveryService::STATUS_WAITING_ASSIGNMENT, $withTech->fresh()->status);
        $this->assertSame(DeviceRecoveryService::STATUS_WAITING_ASSIGNMENT, $without->fresh()->status);
        $this->assertSame($events, DeviceRecoveryEvent::query()->count());
    }

    public function test_dry_run_flag_wins_over_execute(): void
    {
        $case = $this->legacyCase($this->technician);

        $this->artisan('device-recovery:backfill-assignee', ['--execute' => true, '--dry-run' => true, '--actor' => $this->admin->id])
            ->expectsOutputToContain('mode=dry-run')
            ->assertSuccessful();

        $this->assertNull($case->fresh()->assigned_to);
    }

    public function test_execute_assigns_only_waiting_cases_with_technician_and_is_idempotent(): void
    {
        $withTech = $this->legacyCase($this->technician);
        $without = $this->legacyCase(null);
        $noc = User::factory()->create(['role' => 'noc']);
        $nocTicket = $this->legacyCase($noc);
        $this->notifier->shouldNotReceive('sendRecoveryAssignment');

        $this->artisan('device-recovery:backfill-assignee', ['--execute' => true, '--actor' => $this->admin->id])
            ->expectsOutputToContain('mode=execute cases=3 assigned=1 skipped=2')
            ->assertSuccessful();

        $withTech->refresh();
        $this->assertSame(DeviceRecoveryService::STATUS_ASSIGNED, $withTech->status);
        $this->assertSame($this->technician->id, (int) $withTech->assigned_to);
        $event = $withTech->events()->where('event_type', 'assigned')->sole();
        $this->assertSame((int) $this->admin->id, (int) $event->actor_id);
        $this->assertSame('ticket_assignee_backfill', $event->metadata['source']);
        $this->assertSame(DeviceRecoveryService::STATUS_WAITING_ASSIGNMENT, $without->fresh()->status);
        $this->assertSame(DeviceRecoveryService::STATUS_WAITING_ASSIGNMENT, $nocTicket->fresh()->status);

        $this->artisan('device-recovery:backfill-assignee', ['--execute' => true, '--actor' => $this->admin->id])
            ->expectsOutputToContain('mode=execute cases=2 assigned=0 skipped=2')
            ->assertSuccessful();
        $this->assertSame(1, $withTech->events()->where('event_type', 'assigned')->count());
    }

    public function test_cases_option_limits_scope_and_never_touches_non_waiting_case(): void
    {
        $first = $this->legacyCase($this->technician);
        $second = $this->legacyCase($this->technician);
        $cancelled = $this->legacyCase($this->technician);
        $cancelled->forceFill(['status' => DeviceRecoveryService::STATUS_CANCELLED])->save();

        $this->artisan('device-recovery:backfill-assignee', [
            '--execute' => true,
            '--actor' => $this->admin->id,
            '--cases' => $first->id.','.$cancelled->id,
        ])->expectsOutputToContain('mode=execute cases=2 assigned=1 skipped=1')->assertSuccessful();

        $this->assertSame(DeviceRecoveryService::STATUS_ASSIGNED, $first->fresh()->status);
        $this->assertSame(DeviceRecoveryService::STATUS_WAITING_ASSIGNMENT, $second->fresh()->status);
        $this->assertSame(DeviceRecoveryService::STATUS_CANCELLED, $cancelled->fresh()->status);
        $this->assertNull($cancelled->fresh()->assigned_to);
    }

    public function test_notify_flag_sends_pickup_wa_per_assigned_case(): void
    {
        $case = $this->legacyCase($this->technician);
        $this->notifier->shouldReceive('sendRecoveryAssignment')
            ->once()
            ->with(Mockery::on(fn (DeviceRecoveryCase $sent): bool => $sent->id === $case->id))
            ->andReturn(true);

        $this->artisan('device-recovery:backfill-assignee', ['--execute' => true, '--notify' => true, '--actor' => $this->admin->id])
            ->assertSuccessful();

        $this->assertSame(DeviceRecoveryService::STATUS_ASSIGNED, $case->fresh()->status);
    }

    public function test_execute_refused_in_read_only_mode_and_unknown_actor(): void
    {
        $case = $this->legacyCase($this->technician);

        config(['device-recovery.mode' => 'read_only']);
        $this->artisan('device-recovery:backfill-assignee', ['--execute' => true, '--actor' => $this->admin->id])->assertFailed();
        config(['device-recovery.mode' => 'write']);
        $this->artisan('device-recovery:backfill-assignee', ['--execute' => true, '--actor' => 999999])->assertFailed();

        $this->assertNull($case->fresh()->assigned_to);
    }

    /** A case as created before auto-assign: waiting, ticket technician set afterwards. */
    private function legacyCase(?User $ticketAssignee): DeviceRecoveryCase
    {
        $this->seq++;
        $customer = Customer::create([
            'code' => 'BFA-'.$this->seq,
            'name' => 'Backfill customer '.$this->seq,
            'phone' => '0813100000'.$this->seq,
            'address' => 'Alamat '.$this->seq,
            'area_id' => $this->area->id,
            'package_id' => $this->package->id,
            'pppoe_user' => 'backfill-'.$this->seq,
            'onu_serial' => 'FHTTC18600B'.$this->seq,
            'status' => 'isolated',
        ]);
        $ticket = SupportTicket::create([
            'customer_id' => $customer->id,
            'area_id' => $customer->area_id,
            'created_by' => $this->admin->id,
            'gangguan' => 'Dismantle',
            'subject' => 'Dismantle',
            'status' => 'open',
            'priority' => 'normal',
            'type' => 'dismantle',
        ]);
        $case = app(DeviceRecoveryService::class)->createFromApprovedDismantle($ticket, $this->admin);
        $this->assertSame(DeviceRecoveryService::STATUS_WAITING_ASSIGNMENT, $case->status);

        if ($ticketAssignee) {
            // Raw write: legacy data, no live sync hook involved.
            DB::table('support_tickets')->where('id', $ticket->id)->update(['assigned_to' => $ticketAssignee->id, 'status' => 'assigned']);
        }

        return $case;
    }
}
