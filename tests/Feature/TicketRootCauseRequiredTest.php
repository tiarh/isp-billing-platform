<?php

namespace Tests\Feature;

use App\Enums\OutageRootCause;
use App\Models\OutageDiagnosis;
use App\Models\Setting;
use App\Models\SupportTicket;
use App\Models\TicketActivity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Plan ticket-root-cause-required: the cause is required on every incident close, "Lainnya" needs a note, the
 * system-only codes cannot be sent by a person, the suggestion is recomputed by the server, and the picker list.
 */
#[Group('blocking-core')]
class TicketRootCauseRequiredTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The closed-ticket WA goes through the real NotificationService; no provider is configured in tests.
        Http::fake();
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

    // ------------------------------------------------------------------ enum

    public function test_pick_options_are_the_short_list_then_penyebab_lain_and_every_code_a_person_can_send_is_offered(): void
    {
        $options = OutageRootCause::pickOptions();
        $main = array_column(array_filter($options, fn (array $o): bool => $o['group'] === 'main'), 'value');
        $more = array_column(array_filter($options, fn (array $o): bool => $o['group'] === 'more'), 'value');

        $this->assertSame(['drop_los', 'optical_degrade', 'power_off', 'cpe_dead', 'pppoe_auth', 'wifi_client', 'isolir_admin', 'self_recovered', 'not_fault', 'other'], $main);
        $this->assertContains('unknown', $more, '"Tidak tahu" is under "Penyebab lain"');
        $this->assertNotContains('unknown', $main);
        $this->assertEqualsCanonicalizing(OutageRootCause::humanCodes(), array_column($options, 'value'));
        $this->assertSame('Bukan gangguan (PSB / pindah / dismantle)', OutageRootCause::pickLabel('not_fault'));
        // the saved cause keeps its normal label
        $this->assertSame('Bukan gangguan', OutageRootCause::labelFor('not_fault'));
    }

    public function test_detector_and_rules_never_emit_the_new_codes(): void
    {
        $dir = base_path('app/Services/Outage');
        $files = [$dir.'/Diagnosis/Rules.php', $dir.'/OutageDetector.php', $dir.'/Diagnosis/OutageDiagnoser.php'];
        foreach ($files as $file) {
            $this->assertFileExists($file);
            $source = (string) file_get_contents($file);
            foreach (['not_fault', 'self_recovered', 'auto_closed', 'NotFault', 'SelfRecovered', 'AutoClosed'] as $needle) {
                $this->assertStringNotContainsString($needle, $source, basename($file).' must not mention '.$needle);
            }
        }
    }

    public function test_migration_is_idempotent_and_adds_the_three_columns(): void
    {
        foreach (['root_cause_note', 'root_cause_suggested', 'root_cause_source'] as $column) {
            $this->assertTrue(Schema::hasColumn('support_tickets', $column));
        }

        $migration = require base_path('database/migrations/2026_10_22_000001_add_root_cause_detail_to_support_tickets.php');
        // running it again is a no-op (guarded by hasColumn); down() is not run here: DDL would end the test transaction
        $migration->up();
        $migration->up();
        foreach (['root_cause_note', 'root_cause_suggested', 'root_cause_source'] as $column) {
            $this->assertTrue(Schema::hasColumn('support_tickets', $column));
        }
    }

    // ------------------------------------------------------------------ "Lainnya" note, system-only codes

    public function test_other_needs_a_note_and_the_note_is_saved(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $ticket = $this->ticket();

        $this->actingAs($admin)->put(route('tickets.update', $ticket), ['status' => 'resolved', 'root_cause' => 'other'])
            ->assertSessionHasErrors('root_cause_note');
        $this->actingAs($admin)->put(route('tickets.update', $ticket), ['status' => 'resolved', 'root_cause' => 'other', 'root_cause_note' => 'abc'])
            ->assertSessionHasErrors('root_cause_note');
        $this->assertSame('assigned', $ticket->fresh()->status);

        $this->actingAs($admin)->put(route('tickets.update', $ticket), ['status' => 'resolved', 'root_cause' => 'other', 'root_cause_note' => 'Kabel LAN dalam rumah putus'])
            ->assertSessionHasNoErrors();

        $ticket = $ticket->fresh();
        $this->assertSame('resolved', $ticket->status);
        $this->assertSame('other', $ticket->root_cause);
        $this->assertSame('Kabel LAN dalam rumah putus', $ticket->root_cause_note);
        $this->assertSame('admin', $ticket->root_cause_source);

        $this->actingAs($admin)->get(route('tickets.show', $ticket))
            ->assertInertia(fn ($page) => $page->where('rootCause.code', 'other')->where('rootCause.note', 'Kabel LAN dalam rumah putus'));
    }

    public function test_other_with_a_note_works_on_the_technician_form_and_the_api_too(): void
    {
        $technician = User::factory()->create(['role' => 'teknisi']);
        $admin = User::factory()->create(['role' => 'admin']);
        $viaForm = $this->ticket(['assigned_to' => $technician->id]);
        $viaApi = $this->ticket();

        $this->actingAs($technician)->post(route('tickets.technician-update', $viaForm), ['status' => 'resolved', 'root_cause' => 'other'])
            ->assertSessionHasErrors('root_cause_note');
        $this->actingAs($technician)->post(route('tickets.technician-update', $viaForm), ['status' => 'resolved', 'root_cause' => 'other', 'root_cause_note' => 'Port ODP penuh'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Port ODP penuh', $viaForm->fresh()->root_cause_note);
        $this->assertSame('technician', $viaForm->fresh()->root_cause_source);

        $this->actingAs($admin)->patchJson(route('api.support-tickets.update', $viaApi), ['status' => 'resolved', 'root_cause' => 'other'])
            ->assertStatus(422)->assertJsonValidationErrors('root_cause_note');
        $this->actingAs($admin)->patchJson(route('api.support-tickets.update', $viaApi), ['status' => 'resolved', 'root_cause' => 'other', 'root_cause_note' => 'Setting modem salah'])
            ->assertOk();
        $this->assertSame('api', $viaApi->fresh()->root_cause_source);
        $this->assertSame('Setting modem salah', $viaApi->fresh()->root_cause_note);
    }

    public function test_not_fault_and_self_recovered_are_accepted_and_system_only_codes_are_refused(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        foreach (['not_fault', 'self_recovered'] as $code) {
            $ticket = $this->ticket();
            $this->actingAs($admin)->put(route('tickets.update', $ticket), ['status' => 'closed', 'root_cause' => $code])->assertSessionHasNoErrors();
            $this->assertSame($code, $ticket->fresh()->root_cause);
        }

        foreach (['auto_closed', 'data_blind'] as $code) {
            $ticket = $this->ticket();
            $this->actingAs($admin)->put(route('tickets.update', $ticket), ['status' => 'closed', 'root_cause' => $code])->assertSessionHasErrors('root_cause');
            $this->actingAs($admin)->patchJson(route('api.support-tickets.update', $ticket), ['status' => 'closed', 'root_cause' => $code])
                ->assertStatus(422)->assertJsonValidationErrors('root_cause');
            $this->assertSame('assigned', $ticket->fresh()->status);
            $this->assertNull($ticket->fresh()->root_cause);
        }
    }

    // ------------------------------------------------------------------ every close path

    public function test_retyping_an_incident_in_the_close_request_still_needs_the_cause(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $ticket = $this->ticket();

        $this->actingAs($admin)->put(route('tickets.update', $ticket), ['type' => 'other', 'status' => 'resolved'])
            ->assertSessionHasErrors('root_cause');
        $this->actingAs($admin)->patchJson(route('api.support-tickets.update', $ticket), ['type' => 'request', 'status' => 'resolved'])
            ->assertStatus(422)->assertJsonValidationErrors('root_cause');
        $this->assertSame('assigned', $ticket->fresh()->status);
        $this->assertSame('incident', $ticket->fresh()->type);

        $this->actingAs($admin)->put(route('tickets.update', $ticket), ['type' => 'other', 'status' => 'resolved', 'root_cause' => 'cpe_dead'])
            ->assertSessionHasNoErrors();
        $this->assertSame('other', $ticket->fresh()->type);
        $this->assertSame('cpe_dead', $ticket->fresh()->root_cause);
    }

    public function test_a_ticket_that_is_not_an_incident_closes_without_a_cause_and_a_closed_ticket_is_not_forced(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $install = $this->ticket(['type' => 'installation']);
        $this->actingAs($admin)->put(route('tickets.update', $install), ['status' => 'resolved'])->assertSessionHasNoErrors();
        $this->assertNull($install->fresh()->root_cause);

        $closed = $this->ticket(['status' => 'closed', 'resolved_at' => now()]);
        $this->actingAs($admin)->put(route('tickets.update', $closed), ['priority' => 'high'])->assertSessionHasNoErrors();
        $this->assertSame('high', $closed->fresh()->priority);
    }

    public function test_a_technician_who_is_not_assigned_still_gets_403(): void
    {
        $technician = User::factory()->create(['role' => 'teknisi']);
        $other = User::factory()->create(['role' => 'teknisi']);
        $ticket = $this->ticket(['assigned_to' => $other->id]);

        $this->actingAs($technician)->post(route('tickets.technician-update', $ticket), ['status' => 'resolved', 'root_cause' => 'drop_los'])->assertForbidden();
        $this->actingAs($technician)->put(route('tickets.update', $ticket), ['status' => 'resolved', 'root_cause' => 'drop_los'])->assertForbidden();
        $this->assertNull($ticket->fresh()->root_cause);
    }

    public function test_editing_a_ticket_the_system_closed_does_not_send_back_a_system_code(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $ticket = $this->ticket(['status' => 'closed', 'resolved_at' => now(), 'root_cause' => 'auto_closed', 'root_cause_source' => 'system']);

        // the Edit form blanks a system-only cause, so an empty value leaves it alone
        $this->actingAs($admin)->put(route('tickets.update', $ticket), ['priority' => 'high', 'root_cause' => ''])->assertSessionHasNoErrors();
        $this->assertSame('auto_closed', $ticket->fresh()->root_cause);

        // a person may replace it with a real answer
        $this->actingAs($admin)->put(route('tickets.update', $ticket), ['root_cause' => 'drop_los'])->assertSessionHasNoErrors();
        $this->assertSame('drop_los', $ticket->fresh()->root_cause);
        $this->assertSame('admin', $ticket->fresh()->root_cause_source);
        $this->assertDatabaseHas('ticket_activities', ['ticket_id' => $ticket->id, 'action' => 'root_cause_changed']);
    }

    // ------------------------------------------------------------------ suggestion

    private function diagnosis(SupportTicket $ticket, string $cause, string $confidence = 'sedang'): OutageDiagnosis
    {
        return OutageDiagnosis::create(['customer_id' => 1, 'ticket_id' => $ticket->id, 'trigger' => 'ticket_open', 'state' => 'done', 'cause_code' => $cause, 'confidence' => $confidence]);
    }

    public function test_show_offers_the_latest_diagnosis_as_a_suggestion_but_not_unknown_or_data_blind(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $ticket = $this->ticket();

        $this->actingAs($admin)->get(route('tickets.show', $ticket))->assertInertia(fn ($page) => $page->where('rootCauseSuggestion', null));

        $this->diagnosis($ticket, 'pon_cut');
        $this->actingAs($admin)->get(route('tickets.show', $ticket))->assertInertia(fn ($page) => $page
            ->where('rootCauseSuggestion.code', 'pon_cut')
            ->where('rootCauseSuggestion.label', 'Kabel PON / backbone putus')
            ->where('rootCauseSuggestion.confidence', 'sedang'));
        $this->actingAs($admin)->get(route('tickets.edit', $ticket))->assertInertia(fn ($page) => $page->where('rootCauseSuggestion.code', 'pon_cut'));

        $this->diagnosis($ticket, 'unknown');
        $this->actingAs($admin)->get(route('tickets.show', $ticket))->assertInertia(fn ($page) => $page->where('rootCauseSuggestion', null));
        $this->diagnosis($ticket, 'data_blind');
        $this->actingAs($admin)->get(route('tickets.show', $ticket))->assertInertia(fn ($page) => $page->where('rootCauseSuggestion', null));
    }

    public function test_a_technician_does_not_see_the_suggestion_while_the_diagnosis_panel_is_hidden_for_the_role(): void
    {
        $technician = User::factory()->create(['role' => 'teknisi']);
        $ticket = $this->ticket(['assigned_to' => $technician->id]);
        $this->diagnosis($ticket, 'drop_los');

        $this->actingAs($technician)->get(route('tickets.show', $ticket))->assertInertia(fn ($page) => $page->where('rootCauseSuggestion', null));

        Setting::set('diagnosis_panel_roles', 'admin,noc,teknisi');
        Setting::flushRequestMemo();
        $this->actingAs($technician)->get(route('tickets.show', $ticket))->assertInertia(fn ($page) => $page->where('rootCauseSuggestion.code', 'drop_los'));
    }

    public function test_the_server_stores_the_suggestion_it_computed_and_via_is_suggestion_only_when_the_code_matches(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $used = $this->ticket();
        $this->diagnosis($used, 'drop_los');
        $this->actingAs($admin)->put(route('tickets.update', $used), ['status' => 'resolved', 'root_cause' => 'drop_los', 'root_cause_via' => 'suggestion'])->assertSessionHasNoErrors();
        $this->assertSame('drop_los', $used->fresh()->root_cause_suggested);
        $this->assertSame('suggestion', TicketActivity::query()->where('ticket_id', $used->id)->where('action', 'root_cause_set')->firstOrFail()->new_value['via']);

        // the client claims "suggestion" but picked something else: manual, and the client never sets the suggested code
        $other = $this->ticket();
        $this->diagnosis($other, 'drop_los');
        $this->actingAs($admin)->put(route('tickets.update', $other), ['status' => 'resolved', 'root_cause' => 'cpe_dead', 'root_cause_via' => 'suggestion', 'root_cause_suggested' => 'cpe_dead'])->assertSessionHasNoErrors();
        $this->assertSame('drop_los', $other->fresh()->root_cause_suggested);
        $this->assertSame('manual', TicketActivity::query()->where('ticket_id', $other->id)->where('action', 'root_cause_set')->firstOrFail()->new_value['via']);

        // nothing suggested: null
        $none = $this->ticket();
        $this->actingAs($admin)->put(route('tickets.update', $none), ['status' => 'resolved', 'root_cause' => 'cpe_dead', 'root_cause_via' => 'suggestion'])->assertSessionHasNoErrors();
        $this->assertNull($none->fresh()->root_cause_suggested);
        $this->assertSame('manual', TicketActivity::query()->where('ticket_id', $none->id)->where('action', 'root_cause_set')->firstOrFail()->new_value['via']);
    }

    public function test_a_suggestion_is_stored_only_while_the_ticket_has_no_cause_yet(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $ticket = $this->ticket(['root_cause' => 'not_fault', 'root_cause_source' => 'system']);
        $this->diagnosis($ticket, 'drop_los');

        // the screen offered no suggestion (a cause exists), so none is counted when it is replaced
        $this->actingAs($admin)->get(route('tickets.show', $ticket))->assertInertia(fn ($page) => $page->where('rootCauseSuggestion', null));
        $this->actingAs($admin)->put(route('tickets.update', $ticket), ['root_cause' => 'drop_los', 'root_cause_via' => 'suggestion'])->assertSessionHasNoErrors();

        $this->assertNull($ticket->fresh()->root_cause_suggested);
        $this->assertSame('manual', TicketActivity::query()->where('ticket_id', $ticket->id)->where('action', 'root_cause_changed')->firstOrFail()->new_value['via']);
    }

    public function test_the_timeline_does_not_show_internal_column_names(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $technician = User::factory()->create(['role' => 'teknisi']);
        $viaEdit = $this->ticket();
        $viaForm = $this->ticket(['assigned_to' => $technician->id]);

        $this->actingAs($admin)->put(route('tickets.update', $viaEdit), ['status' => 'resolved', 'root_cause' => 'other', 'root_cause_note' => 'Port ODP penuh', 'priority' => 'high'])->assertSessionHasNoErrors();
        $this->actingAs($technician)->post(route('tickets.technician-update', $viaForm), ['status' => 'resolved', 'root_cause' => 'other', 'root_cause_note' => 'Port ODP penuh'])->assertSessionHasNoErrors();

        foreach ([$viaEdit, $viaForm] as $ticket) {
            foreach (TicketActivity::query()->where('ticket_id', $ticket->id)->get() as $activity) {
                $text = (string) $activity->description.json_encode($activity->new_value);
                foreach (['root_cause_source', 'root_cause_suggested', 'root_cause_via', 'root_cause_note'] as $internal) {
                    $this->assertStringNotContainsString($internal, $text);
                }
            }
        }
        $this->assertSame('Port ODP penuh', $viaEdit->fresh()->root_cause_note);
    }

    public function test_before_migrate_the_detail_columns_are_ignored_and_other_still_closes(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $ticket = $this->ticket();
        $this->diagnosis($ticket, 'drop_los');

        OutageRootCause::forceDetailReady(false);
        try {
            $this->actingAs($admin)->put(route('tickets.update', $ticket), ['status' => 'resolved', 'root_cause' => 'other'])
                ->assertSessionHasErrors('root_cause_note');
            $this->actingAs($admin)->put(route('tickets.update', $ticket), ['status' => 'resolved', 'root_cause' => 'other', 'root_cause_note' => 'Port ODP penuh', 'root_cause_via' => 'suggestion'])
                ->assertSessionHasNoErrors();
        } finally {
            OutageRootCause::forceDetailReady(null);
        }

        $ticket = $ticket->fresh();
        $this->assertSame('resolved', $ticket->status);
        $this->assertSame('other', $ticket->root_cause);
        $this->assertNull($ticket->root_cause_note);
        $this->assertNull($ticket->root_cause_source);
        $this->assertNull($ticket->root_cause_suggested);
    }

    public function test_the_saved_cause_uses_the_words_of_the_button(): void
    {
        $this->assertSame('Modem rusak', OutageRootCause::ticketLabel('cpe_dead'));
        $this->assertSame('WiFi modem mati', OutageRootCause::ticketLabel('wlan_down'));
        $this->assertSame('Bukan gangguan', OutageRootCause::ticketLabel('not_fault'));
        $this->assertSame('Lainnya', OutageRootCause::ticketLabel('other'));
        $this->assertSame('Ditutup otomatis', OutageRootCause::ticketLabel('auto_closed'));
        $this->assertSame('Tidak tahu', OutageRootCause::ticketLabel('unknown'));
        $this->assertNull(OutageRootCause::ticketLabel(null));
    }

    public function test_an_invalid_via_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $ticket = $this->ticket();

        $this->actingAs($admin)->put(route('tickets.update', $ticket), ['status' => 'resolved', 'root_cause' => 'drop_los', 'root_cause_via' => 'banana'])
            ->assertSessionHasErrors('root_cause_via');
    }
}
