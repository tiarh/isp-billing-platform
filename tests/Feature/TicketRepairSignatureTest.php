<?php

namespace Tests\Feature;

use App\Enums\OutageRootCause;
use App\Models\Customer;
use App\Models\Olt;
use App\Models\OltOnu;
use App\Models\OutageOnuTracker;
use App\Models\Package;
use App\Models\Setting;
use App\Models\SupportTicket;
use App\Models\TicketActivity;
use App\Models\User;
use App\Services\Outage\Diagnosis\PrecisionReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Canary;
use Tests\TestCase;

/**
 * Close form of an incident (learn & measure section 2.4): one plain hint under each choice, and the "Saran dari data"
 * chip (repair signature) behind a setting that is off by default. The chip is never pre-selected, the server recomputes
 * it and keeps via = signature only when it names the chosen cause. Nothing is closed automatically.
 */
#[Group('blocking-core')]
class TicketRepairSignatureTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $open;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(); // the closed-ticket WA goes through the real NotificationService; no provider in tests
        $this->open = Carbon::parse('2026-10-22 03:00:00', 'UTC');
        Carbon::setTestNow($this->open->copy()->addHours(6));
        Setting::flushRequestMemo();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** An open incident whose ONU went from -28.0 (the two days before) to -20.7 (read an hour ago). */
    private function openTicket(): SupportTicket
    {
        $n = ++$this->seq;
        $olt = Olt::firstOrCreate(['code' => 'RS-OLT'], ['name' => 'OLT RS', 'vendor' => 'zte_c300', 'management_ip' => '198.18.8.9', 'username' => 'u', 'password' => Canary::of('pw')]);
        $package = Package::firstOrCreate(['code' => 'RS-PKG'], ['name' => 'RS pkg', 'price' => 100000]);
        $ref = '1/2/10:'.$n;
        $c = Customer::create([
            'code' => 'RS-C'.$n, 'name' => 'Pelanggan RS '.$n, 'phone' => '0812777'.$n, 'address' => 'Jl. Contoh', 'pppoe_user' => 'USER_RS_'.$n,
            'package_id' => $package->id, 'status' => 'active', 'olt_id' => $olt->id, 'olt_port_label' => $ref,
        ]);
        OutageOnuTracker::create(['olt_id' => $olt->id, 'onu_ref' => $ref, 'customer_id' => $c->id, 'phase' => 'online', 'rx_daily' => [
            ['date' => '2026-10-20', 'dbm' => -28.0, 'n' => 20], ['date' => '2026-10-21', 'dbm' => -27.9, 'n' => 20],
        ]]);
        $read = Carbon::now()->subHour();
        OltOnu::create(['olt_id' => $olt->id, 'onu_ref' => $ref, 'pon_port' => '1/2/10', 'state' => 'working', 'phase_state' => 'working', 'rx_power_dbm' => -20.7, 'state_collected_at' => $read, 'phase_collected_at' => $read, 'rx_collected_at' => $read]);
        $admin = User::factory()->create(['role' => 'admin']);
        $ticket = SupportTicket::create(['created_by' => $admin->id, 'customer_id' => $c->id, 'gangguan' => 'Mati', 'status' => 'assigned', 'type' => 'incident', 'priority' => 'normal']);
        DB::table('support_tickets')->where('id', $ticket->id)->update(['created_at' => $this->open->format('Y-m-d H:i:s')]);

        return $ticket->fresh();
    }

    private function chipOn(): void
    {
        Setting::set('diagnosis_signature_chip_enabled', '1');
        Setting::flushRequestMemo();
    }

    // ------------------------------------------------------------------ hints

    public function test_every_main_choice_has_its_plain_hint_and_the_two_fibre_hints_are_the_owner_text(): void
    {
        $byValue = array_column(OutageRootCause::pickOptions(), null, 'value');

        $this->assertSame('ONU merah / tidak ada sinyal', $byValue['drop_los']['hint']);
        $this->assertSame('ONU nyala tapi redaman jelek atau putus-sambung', $byValue['optical_degrade']['hint']);
        foreach (['drop_los', 'optical_degrade', 'power_off', 'cpe_dead', 'pppoe_auth', 'wifi_client', 'isolir_admin', 'self_recovered', 'not_fault', 'odp_cut', 'pon_cut'] as $code) {
            $this->assertNotEmpty($byValue[$code]['hint'], $code);
            $this->assertLessThanOrEqual(60, mb_strlen($byValue[$code]['hint']), $code.' stays one short line');
        }
        $this->assertSame('Normal lagi sebelum dicek', $byValue['self_recovered']['hint']);
        $this->assertNull($byValue['other']['hint'], '"Lainnya" asks for a note instead');
    }

    public function test_the_ticket_pages_send_the_hints_without_any_setting(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $ticket = $this->openTicket();

        $this->actingAs($admin)->get(route('tickets.show', $ticket))->assertInertia(fn ($page) => $page
            ->where('rootCauseOptions.0.value', 'drop_los')->where('rootCauseOptions.0.hint', 'ONU merah / tidak ada sinyal')->where('repairSignature', null));
        $this->actingAs($admin)->get(route('tickets.edit', $ticket))->assertInertia(fn ($page) => $page->where('rootCauseOptions.1.hint', 'ONU nyala tapi redaman jelek atau putus-sambung'));
    }

    // ------------------------------------------------------------------ the chip

    public function test_the_chip_is_off_by_default_and_never_selects_a_cause(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $ticket = $this->openTicket();

        $this->actingAs($admin)->get(route('tickets.show', $ticket))->assertInertia(fn ($page) => $page->where('repairSignature', null));
        $this->assertNull(OutageRootCause::signatureFor($ticket, $admin));
    }

    public function test_with_the_setting_on_the_chip_names_the_repair_on_show_and_edit_for_an_admin(): void
    {
        $this->chipOn();
        $admin = User::factory()->create(['role' => 'admin']);
        $ticket = $this->openTicket();

        foreach (['tickets.show', 'tickets.edit'] as $route) {
            $this->actingAs($admin)->get(route($route, $ticket))->assertInertia(fn ($page) => $page
                ->where('repairSignature.code', 'optical_degrade')
                ->where('repairSignature.label', 'Redaman / konektor')
                ->where('repairSignature.signature', 'rx_jump')
                ->where('repairSignature.text', 'redaman membaik -28,0 → -20,7 setelah perbaikan'), $route);
        }
        $this->actingAs($admin)->get(route('tickets.show', $ticket))->assertInertia(fn ($page) => $page->where('rootCause', null));
        // viewing it changes nothing
        $this->assertSame('assigned', $ticket->fresh()->status);
        $this->assertNull($ticket->fresh()->root_cause);
    }

    public function test_the_chip_is_hidden_from_a_role_that_does_not_see_diagnoses_and_after_a_cause_is_set(): void
    {
        $this->chipOn();
        $technician = User::factory()->create(['role' => 'teknisi']);
        $admin = User::factory()->create(['role' => 'admin']);
        $ticket = $this->openTicket();

        $this->assertNull(OutageRootCause::signatureFor($ticket, $technician), 'the panel roles are admin + noc by default');
        $this->assertNotNull(OutageRootCause::signatureFor($ticket, $admin));

        DB::table('support_tickets')->where('id', $ticket->id)->update(['root_cause' => 'drop_los']);
        $this->assertNull(OutageRootCause::signatureFor($ticket->fresh(), $admin));
    }

    // ------------------------------------------------------------------ saving

    private function via(SupportTicket $ticket): ?string
    {
        $activity = TicketActivity::query()->where('ticket_id', $ticket->id)->where('action', 'root_cause_set')->latest('id')->first();

        return $activity?->new_value['via'] ?? null;
    }

    public function test_using_the_chip_saves_the_cause_with_via_signature_and_the_technician_still_chooses(): void
    {
        $this->chipOn();
        $admin = User::factory()->create(['role' => 'admin']);
        $ticket = $this->openTicket();

        $this->actingAs($admin)->put(route('tickets.update', $ticket), ['status' => 'resolved', 'root_cause' => 'optical_degrade', 'root_cause_via' => 'signature'])->assertSessionHasNoErrors();

        $ticket = $ticket->fresh();
        $this->assertSame('resolved', $ticket->status);
        $this->assertSame('optical_degrade', $ticket->root_cause);
        $this->assertSame('admin', $ticket->root_cause_source);
        $this->assertSame('signature', $this->via($ticket));
    }

    public function test_the_server_keeps_via_signature_only_when_it_names_the_chosen_cause(): void
    {
        $this->chipOn();
        $admin = User::factory()->create(['role' => 'admin']);

        $other = $this->openTicket();
        $this->actingAs($admin)->put(route('tickets.update', $other), ['status' => 'resolved', 'root_cause' => 'cpe_dead', 'root_cause_via' => 'signature'])->assertSessionHasNoErrors();
        $this->assertSame('manual', $this->via($other), 'a different cause than the chip: manual');

        $forged = $this->openTicket();
        DB::table('olt_onus')->where('onu_ref', '1/2/10:'.$this->seq)->update(['rx_power_dbm' => -27.9]);
        $this->actingAs($admin)->put(route('tickets.update', $forged), ['status' => 'resolved', 'root_cause' => 'optical_degrade', 'root_cause_via' => 'signature'])->assertSessionHasNoErrors();
        $this->assertSame('manual', $this->via($forged), 'no repair in the data: the claim is dropped');
    }

    public function test_with_the_setting_off_a_signature_claim_is_saved_as_manual(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $ticket = $this->openTicket();

        $this->actingAs($admin)->put(route('tickets.update', $ticket), ['status' => 'resolved', 'root_cause' => 'optical_degrade', 'root_cause_via' => 'signature'])->assertSessionHasNoErrors();

        $this->assertSame('manual', $this->via($ticket));
        $this->actingAs($admin)->put(route('tickets.update', $this->openTicket()), ['status' => 'resolved', 'root_cause' => 'drop_los', 'root_cause_via' => 'banana'])->assertSessionHasErrors('root_cause_via');
    }

    public function test_the_weekly_report_counts_the_closes_that_used_the_chip(): void
    {
        $this->chipOn();
        $admin = User::factory()->create(['role' => 'admin']);
        $ticket = $this->openTicket();
        $this->actingAs($admin)->put(route('tickets.update', $ticket), ['status' => 'resolved', 'root_cause' => 'optical_degrade', 'root_cause_via' => 'signature'])->assertSessionHasNoErrors();
        DB::table('support_tickets')->where('id', $ticket->id)->update(['resolved_at' => '2026-10-21 03:00:00']);

        $fill = (new PrecisionReport)->fill(Carbon::parse('2026-10-19', 'Asia/Jakarta'), Carbon::parse('2026-10-26', 'Asia/Jakarta'));

        $this->assertSame(1, $fill['signature_used']);
        $this->assertSame(0, $fill['suggestion']['used'], 'the other chip is counted apart');
    }
}
