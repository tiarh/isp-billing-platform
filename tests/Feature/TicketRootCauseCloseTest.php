<?php

namespace Tests\Feature;

use App\Enums\OutageRootCause;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Outage RCA-1: closing an incident ticket needs "Penyebab sebenarnya" (web admin, technician update and the
 * legacy API). "Tidak tahu" is a valid answer; other ticket types and tickets that are already closed are
 * unaffected.
 */
#[Group('blocking-core')]
class TicketRootCauseCloseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $notifier = Mockery::mock(NotificationService::class)->shouldIgnoreMissing(true);
        app()->instance(NotificationService::class, $notifier);
    }

    private function ticket(array $o = []): SupportTicket
    {
        $admin = User::factory()->create(['role' => 'admin']);

        return SupportTicket::create($o + [
            'created_by' => $admin->id,
            'gangguan' => 'Internet mati',
            'status' => 'assigned',
            'priority' => 'normal',
            'type' => 'incident',
        ]);
    }

    public function test_taxonomy_has_every_plan_code_with_an_indonesian_label_and_an_unknown_answer(): void
    {
        $this->assertCount(23, OutageRootCause::cases());
        foreach (['upstream', 'core_router', 'olt_down', 'data_blind', 'pon_cut', 'odp_cut', 'drop_los', 'power_off', 'cpe_dead', 'cpe_reboot_loop',
            'optical_degrade', 'pppoe_auth', 'pppoe_flap', 'pppoe_stuck', 'isolir_admin', 'wlan_down', 'wlan_flap', 'wifi_client', 'unknown',
            'not_fault', 'self_recovered', 'other', 'auto_closed'] as $code) {
            $this->assertContains($code, OutageRootCause::codes());
            $this->assertNotSame('', (string) OutageRootCause::labelFor($code));
        }
        $this->assertSame('Belum tahu', OutageRootCause::labelFor('unknown'));
        // the technician's button words did not change; only the saved-cause display label (page, diagnosis, precision card) did
        $this->assertSame('Tidak tahu', OutageRootCause::ticketLabel('unknown'));
        // a person never sees the system-only codes
        $this->assertSame(OutageRootCause::humanCodes(), array_column(OutageRootCause::options(), 'value'));
        $this->assertNotContains('auto_closed', OutageRootCause::humanCodes());
        $this->assertNotContains('data_blind', OutageRootCause::humanCodes());
    }

    public function test_migration_adds_both_nullable_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('support_tickets', 'root_cause'));
        $this->assertTrue(Schema::hasColumn('outage_signals', 'cause_code'));
    }

    public function test_admin_cannot_close_an_incident_ticket_without_a_cause(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $ticket = $this->ticket();

        foreach (['resolved', 'closed'] as $status) {
            $this->actingAs($admin)->put(route('tickets.update', $ticket), ['status' => $status])
                ->assertSessionHasErrors('root_cause');
            $this->assertSame('assigned', $ticket->fresh()->status);
        }
        $this->assertNull($ticket->fresh()->resolved_at);
    }

    public function test_admin_closes_with_a_cause_and_the_timeline_records_it(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $ticket = $this->ticket();

        $this->actingAs($admin)->put(route('tickets.update', $ticket), ['status' => 'resolved', 'root_cause' => 'drop_los'])
            ->assertSessionHasNoErrors();

        $ticket = $ticket->fresh();
        $this->assertSame('resolved', $ticket->status);
        $this->assertSame('drop_los', $ticket->root_cause);
        $this->assertDatabaseHas('ticket_activities', [
            'ticket_id' => $ticket->id,
            'action' => 'root_cause_set',
            'description' => 'Penyebab: '.OutageRootCause::ticketLabel('drop_los'),
        ]);
    }

    public function test_unknown_is_a_valid_answer_and_an_invalid_code_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $ticket = $this->ticket();

        $this->actingAs($admin)->put(route('tickets.update', $ticket), ['status' => 'closed', 'root_cause' => 'banana'])
            ->assertSessionHasErrors('root_cause');
        $this->assertSame('assigned', $ticket->fresh()->status);

        $this->actingAs($admin)->put(route('tickets.update', $ticket), ['status' => 'closed', 'root_cause' => 'unknown'])
            ->assertSessionHasNoErrors();
        $this->assertSame('closed', $ticket->fresh()->status);
        $this->assertSame('unknown', $ticket->fresh()->root_cause);
    }

    public function test_technician_update_requires_the_cause_when_closing_an_incident(): void
    {
        $technician = User::factory()->create(['role' => 'teknisi']);
        $ticket = $this->ticket(['assigned_to' => $technician->id]);

        $this->actingAs($technician)->post(route('tickets.technician-update', $ticket), [
            'kendala' => 'Kabel putus', 'action_taken' => 'Ganti kabel', 'status' => 'resolved',
        ])->assertSessionHasErrors('root_cause');
        $this->assertSame('assigned', $ticket->fresh()->status);
        $this->assertNull($ticket->fresh()->kendala, 'a rejected close must not half-save the form');

        $this->actingAs($technician)->post(route('tickets.technician-update', $ticket), [
            'kendala' => 'Kabel putus', 'action_taken' => 'Ganti kabel', 'status' => 'resolved', 'root_cause' => 'pon_cut',
        ])->assertSessionHasNoErrors();

        $ticket = $ticket->fresh();
        $this->assertSame('resolved', $ticket->status);
        $this->assertSame('pon_cut', $ticket->root_cause);
        $this->assertDatabaseHas('ticket_activities', ['ticket_id' => $ticket->id, 'action' => 'root_cause_set']);
    }

    public function test_non_closing_updates_and_other_ticket_types_need_no_cause(): void
    {
        $technician = User::factory()->create(['role' => 'teknisi']);
        $admin = User::factory()->create(['role' => 'admin']);
        $incident = $this->ticket(['assigned_to' => $technician->id]);
        $billing = $this->ticket(['assigned_to' => $technician->id, 'type' => 'billing']);

        $this->actingAs($technician)->post(route('tickets.technician-update', $incident), [
            'kendala' => 'Sedang dicek', 'status' => 'in_progress',
        ])->assertSessionHasNoErrors();
        $this->assertSame('in_progress', $incident->fresh()->status);

        $this->actingAs($admin)->put(route('tickets.update', $billing), ['status' => 'resolved'])->assertSessionHasNoErrors();
        $this->assertSame('resolved', $billing->fresh()->status);
        $this->assertNull($billing->fresh()->root_cause);
    }

    public function test_a_ticket_that_is_already_resolved_is_unaffected_until_its_own_close(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        // legacy: closed before this feature existed (no cause); closing it further must not suddenly demand one
        $legacy = $this->ticket(['status' => 'resolved', 'resolved_at' => now()]);

        $this->actingAs($admin)->put(route('tickets.update', $legacy), ['status' => 'closed'])->assertSessionHasNoErrors();
        $this->assertSame('closed', $legacy->fresh()->status);

        // a still-open legacy ticket gets the requirement only when it is closed
        $open = $this->ticket();
        $this->actingAs($admin)->put(route('tickets.update', $open), ['priority' => 'high'])->assertSessionHasNoErrors();
        $this->assertSame('high', $open->fresh()->priority);
    }

    public function test_changing_the_type_to_incident_in_the_same_request_still_requires_the_cause(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $ticket = $this->ticket(['type' => 'other']);

        $this->actingAs($admin)->put(route('tickets.update', $ticket), ['type' => 'incident', 'status' => 'resolved'])
            ->assertSessionHasErrors('root_cause');
        $this->assertSame('assigned', $ticket->fresh()->status);
    }

    public function test_legacy_api_close_follows_the_same_rule(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $ticket = $this->ticket();

        $this->actingAs($admin)->patchJson(route('api.support-tickets.update', $ticket), ['status' => 'resolved'])
            ->assertStatus(422)->assertJsonValidationErrors('root_cause');

        $this->actingAs($admin)->patchJson(route('api.support-tickets.update', $ticket), ['status' => 'resolved', 'root_cause' => 'cpe_dead'])
            ->assertOk();
        $this->assertSame('cpe_dead', $ticket->fresh()->root_cause);
    }

    public function test_edit_form_can_change_the_cause_of_an_open_ticket_and_logs_it(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $ticket = $this->ticket(['root_cause' => 'drop_los']);

        $this->actingAs($admin)->put(route('tickets.update', $ticket), ['root_cause' => 'banana'])
            ->assertSessionHasErrors('root_cause');
        $this->assertSame('drop_los', $ticket->fresh()->root_cause);

        $this->actingAs($admin)->put(route('tickets.update', $ticket), ['gangguan' => 'Internet mati', 'root_cause' => 'odp_cut'])
            ->assertSessionHasNoErrors();

        $this->assertSame('odp_cut', $ticket->fresh()->root_cause);
        $this->assertSame('assigned', $ticket->fresh()->status);
        $this->assertDatabaseHas('ticket_activities', [
            'ticket_id' => $ticket->id,
            'action' => 'root_cause_changed',
            'description' => 'Penyebab diubah: '.OutageRootCause::ticketLabel('odp_cut'),
        ]);

        // an empty value from the form leaves the stored cause alone
        $this->actingAs($admin)->put(route('tickets.update', $ticket), ['root_cause' => ''])->assertSessionHasNoErrors();
        $this->assertSame('odp_cut', $ticket->fresh()->root_cause);
    }

    public function test_show_and_edit_pages_receive_the_options(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $ticket = $this->ticket();

        $this->actingAs($admin)->get(route('tickets.show', $ticket))
            ->assertInertia(fn ($page) => $page->component('Tickets/Show')->has('rootCauseOptions', 21)->where('rootCause', null)->where('rootCauseSuggestion', null));
        $this->actingAs($admin)->get(route('tickets.edit', $ticket))
            ->assertInertia(fn ($page) => $page->component('Tickets/Edit')->has('rootCauseOptions', 21));

        $ticket->update(['root_cause' => 'wlan_down']);
        $this->actingAs($admin)->get(route('tickets.show', $ticket))
            ->assertInertia(fn ($page) => $page->where('rootCause.code', 'wlan_down')->where('rootCause.label', 'WiFi modem mati'));
    }
}
