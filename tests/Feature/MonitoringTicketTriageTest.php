<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Customer;
use App\Models\MonitoringAlertEvent;
use App\Models\Package;
use App\Models\Setting;
use App\Models\SupportTicket;
use App\Models\TicketActivity;
use App\Models\User;
use App\Services\MonitoringTicketTriageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-core')]
class MonitoringTicketTriageTest extends TestCase
{
    use RefreshDatabase;

    public function test_triage_is_blocked_when_feature_flag_is_off(): void
    {
        $actor = User::factory()->create(['role' => 'noc']);
        $ticket = $this->monitoringTicket();

        $this->expectException(\RuntimeException::class);
        app(MonitoringTicketTriageService::class)->acknowledge($ticket, $actor, 'cek sumber');
    }

    public function test_acknowledge_and_false_positive_are_audited_and_idempotent(): void
    {
        Setting::set('monitoring_ticket_triage_enabled', true, 'boolean', 'monitoring');
        $actor = User::factory()->create(['role' => 'noc']);
        $ticket = $this->monitoringTicket();
        $service = app(MonitoringTicketTriageService::class);

        $service->acknowledge($ticket, $actor, 'cek snapshot');
        $service->acknowledge($ticket->fresh(), $actor, 'cek snapshot ulang');
        $service->markFalsePositive($ticket->fresh(), $actor, 'evidence stale');
        $service->markFalsePositive($ticket->fresh(), $actor, 'evidence stale ulang');

        $this->assertSame('resolved', $ticket->fresh()->status);
        // acknowledge + false positive + the "Bukan gangguan" cause the triage close fills for an incident
        $this->assertSame(3, TicketActivity::query()->where('ticket_id', $ticket->id)->count());
        $this->assertSame('not_fault', $ticket->fresh()->root_cause);
        $this->assertSame('system', $ticket->fresh()->root_cause_source);
        $this->assertSame(1, TicketActivity::query()->where('ticket_id', $ticket->id)->where('action', 'root_cause_set')->count());
        $this->assertSame('false_positive', $ticket->fresh()->meta['monitoring_triage_idempotency']['false_positive'] ? 'false_positive' : null);
    }

    public function test_verified_recovery_fills_pulih_sendiri_and_keeps_a_cause_that_is_already_there(): void
    {
        Setting::set('monitoring_ticket_triage_enabled', true, 'boolean', 'monitoring');
        $actor = User::factory()->create(['role' => 'admin']);
        $service = app(MonitoringTicketTriageService::class);

        $ticket = $this->monitoringTicket();
        $service->verifyRecovery($ticket, $actor, 'sudah normal');
        $this->assertSame('resolved', $ticket->fresh()->status);
        $this->assertSame('self_recovered', $ticket->fresh()->root_cause);
        $this->assertSame('system', $ticket->fresh()->root_cause_source);

        $withCause = $this->monitoringTicket();
        $withCause->update(['root_cause' => 'drop_los']);
        $service->verifyRecovery($withCause->fresh(), $actor, 'sudah normal');
        $this->assertSame('drop_los', $withCause->fresh()->root_cause);

        $billing = $this->monitoringTicket();
        $billing->update(['type' => 'billing']);
        $service->verifyRecovery($billing->fresh(), $actor, 'sudah normal');
        $this->assertNull($billing->fresh()->root_cause, 'only incident tickets get a cause');
    }

    public function test_recovery_requires_inactive_monitoring_evidence(): void
    {
        Setting::set('monitoring_ticket_triage_enabled', true, 'boolean', 'monitoring');
        $actor = User::factory()->create(['role' => 'admin']);
        $ticket = $this->monitoringTicket();
        MonitoringAlertEvent::create([
            'source' => 'connectivity_scan',
            'entity_type' => 'router',
            'entity_id' => 'router-1',
            'entity_name' => 'RA',
            'issue' => 'router_down',
            'status' => 'active',
            'dedupe_key' => 'triage-active',
            'payload' => [],
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $this->expectException(\RuntimeException::class);
        app(MonitoringTicketTriageService::class)->verifyRecovery($ticket, $actor, 'cek ulang');
    }

    public function test_non_admin_cannot_call_monitoring_triage_route(): void
    {
        Setting::set('monitoring_ticket_triage_enabled', true, 'boolean', 'monitoring');
        $ticket = $this->monitoringTicket();
        $technician = User::factory()->create(['role' => 'teknisi']);

        $this->actingAs($technician)
            ->post(route('monitoring.tickets.acknowledge', $ticket), ['reason' => 'cek'])
            ->assertForbidden();

        $this->assertSame(0, TicketActivity::query()->count());
    }

    public function test_admin_can_acknowledge_and_assign_through_the_routes(): void
    {
        Setting::set('monitoring_ticket_triage_enabled', true, 'boolean', 'monitoring');
        $ticket = $this->monitoringTicket();
        $admin = User::factory()->create(['role' => 'admin']);
        $assignee = User::factory()->create(['role' => 'noc']);

        $this->actingAs($admin)
            ->post(route('monitoring.tickets.acknowledge', $ticket), ['reason' => 'cek sumber'])
            ->assertRedirect();
        $this->assertSame(1, TicketActivity::query()->where('ticket_id', $ticket->id)->count());

        $this->actingAs($admin)
            ->post(route('monitoring.tickets.assign', $ticket), ['assignee_id' => $assignee->id, 'reason' => 'tangani'])
            ->assertRedirect();
        $this->assertSame($assignee->id, (int) $ticket->fresh()->assigned_to);
    }

    public function test_area_scoped_admin_gets_403_on_out_of_area_ticket_from_the_policy(): void
    {
        Setting::set('monitoring_ticket_triage_enabled', true, 'boolean', 'monitoring');
        $mine = Area::create(['name' => 'Area Saya', 'code' => 'SAYA']);
        $other = Area::create(['name' => 'Area Lain', 'code' => 'LAIN']);
        $package = Package::create(['code' => 'BASIC', 'name' => 'Basic', 'price' => 100000]);
        $customer = Customer::create([
            'code' => 'CUST-TRG-1', 'name' => 'Cust Triage', 'address' => 'A', 'area_id' => $other->id,
            'pppoe_user' => 'trg_user', 'package_id' => $package->id, 'status' => 'active',
        ]);
        $ticket = $this->monitoringTicket();
        $ticket->forceFill(['customer_id' => $customer->id])->save();
        $scoped = User::factory()->create(['role' => 'admin']);
        $scoped->areas()->attach($mine);

        $this->actingAs($scoped)
            ->post(route('monitoring.tickets.acknowledge', $ticket), ['reason' => 'cek'])
            ->assertForbidden();

        $this->assertSame(0, TicketActivity::query()->count());
    }

    private function monitoringTicket(): SupportTicket
    {
        return SupportTicket::create([
            'code' => 'MON-TRIAGE-'.uniqid(),
            'type' => 'incident',
            'priority' => 'high',
            'status' => 'open',
            'subject' => 'Monitoring triage test',
            'meta' => ['monitoring_alert_dedupe_key' => 'triage-active'],
        ]);
    }
}
