<?php

namespace Tests\Feature;

use App\Exceptions\DiagnoseLimitException;
use App\Exceptions\HuaweiOltSessionBusyException;
use App\Models\Area;
use App\Models\Customer;
use App\Models\CustomerMigration;
use App\Models\Olt;
use App\Models\OltCollectRun;
use App\Models\OltOnu;
use App\Models\OutageDiagnosis;
use App\Models\OutageOnuTracker;
use App\Models\OutageSignal;
use App\Models\Package;
use App\Models\PsbDraft;
use App\Models\Router;
use App\Models\Setting;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\MikrotikService;
use App\Services\NotificationService;
use App\Services\OltHuaweiMa5608tService;
use App\Services\OltZteC300Service;
use App\Services\Outage\CpeEvents;
use App\Services\Outage\Diagnosis\DiagnoseNowService;
use App\Services\Outage\Diagnosis\OutageCorrelator;
use App\Services\Outage\Diagnosis\OutageDiagnoser;
use App\Services\Outage\Diagnosis\PrecisionReport;
use App\Services\Outage\OltCauseEnricher;
use App\Services\Outage\OnuStateEvents;
use App\Services\Outage\OutageDetector;
use App\Services\Outage\OutageSettings;
use App\Services\Outage\OutageSettingsWriter;
use App\Support\OltOperationLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use phpseclib3\Net\SSH2;
use PHPUnit\Framework\Attributes\Group;
use RouterOS\Query;
use Tests\Support\Canary;
use Tests\TestCase;

/**
 * RCA-4: layered correlation, stored diagnoses (ticket_open / auto_episode / auto_refresh / manual_check), the
 * "Cek sekarang" probes and their limits, the weekly precision report and the #563 follow-ups.
 */
#[Group('blocking-core')]
class OutageRca4DiagnosisTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    /** UTC; 05:00 UTC = 12:00 WIB. */
    private string $base = '2026-10-15 05:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        Setting::flushRequestMemo();
        OutageSettings::resetWarnThrottle();
        OnuStateEvents::forgetSchemaCache();
        CpeEvents::forgetSchemaCache();
        Cache::flush();
        config(['diagnosis.probe_driver' => 'real', 'diagnosis.fake_delay_ms' => 0, 'outage.olt_enrich_dispatch' => false]);
        app()->instance(NotificationService::class, Mockery::mock(NotificationService::class)->shouldIgnoreMissing(true));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ fixtures

    private function olt(string $vendor = 'zte_c300'): Olt
    {
        $olt = Olt::create(['name' => 'OLT UJI '.($this->seq + 1), 'code' => 'R4-'.++$this->seq, 'vendor' => $vendor, 'management_ip' => '198.18.8.9', 'username' => 'u', 'password' => Canary::of('pw')]);
        config(['outage.olt_ids' => [$olt->id], 'outage.serial_fallback_olt_ids' => [$olt->id]]);

        return $olt;
    }

    private function router(array $o = []): Router
    {
        $router = Router::create($o + ['name' => 'RA-OLT '.++$this->seq, 'ip_address' => '198.18.6.'.$this->seq, 'username' => 'admin', 'password' => Canary::of('pw'), 'port' => 8728, 'is_active' => true, 'connection_status' => 'online']);
        DB::table('routers')->where('id', $router->id)->update(['last_health_check_at' => now()]);

        return $router->fresh();
    }

    private function customer(?Olt $olt, ?string $ref, array $o = []): Customer
    {
        $package = Package::firstOrCreate(['code' => 'R4-PKG'], ['name' => 'RCA4 pkg', 'price' => 100000]);
        $n = ++$this->seq;

        return Customer::create(array_merge([
            'code' => 'R4-C'.$n,
            'name' => 'Pelanggan RCA4 '.$n,
            'phone' => '0812666'.$n,
            'address' => 'Jl. Contoh',
            'pppoe_user' => 'USER_R4_'.$n,
            'package_id' => $package->id,
            'status' => 'active',
            'olt_id' => $olt?->id,
            'olt_port_label' => $ref,
        ], $o));
    }

    private function tracker(Olt $olt, string $ref, string $phase, ?Carbon $collected = null, ?Customer $c = null): OutageOnuTracker
    {
        return OutageOnuTracker::create([
            'olt_id' => $olt->id, 'onu_ref' => $ref, 'customer_id' => $c?->id, 'phase' => $phase,
            'phase_since_at' => now()->subMinutes(5), 'last_state_collected_at' => $collected ?? now(),
        ]);
    }

    private function admin(string $role = 'admin'): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function enableCheckNow(): void
    {
        Setting::set('diagnosis_check_now_enabled', '1');
        Setting::flushRequestMemo();
    }

    private function fx(string $name): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/outage/'.$name));
    }

    private function diagnoser(): OutageDiagnoser
    {
        return app(OutageDiagnoser::class);
    }

    // ------------------------------------------------------------------ correlation

    public function test_a_dead_router_is_one_core_router_never_many_cpe_dead(): void
    {
        $olt = $this->olt();
        $router = $this->router(['connection_status' => 'offline']);
        $customers = [];
        for ($i = 1; $i <= 6; $i++) {
            $c = $this->customer($olt, '1/1/1:'.$i, ['router_id' => $router->id, 'is_online' => false]);
            // the ONUs are dead silent too (offline for an hour, no ACS): alone this would be CPE_DEAD
            $this->tracker($olt, '1/1/1:'.$i, 'offline', now(), $c)->update(['phase_since_at' => now()->subHour()]);
            $customers[] = $c;
        }

        foreach ($customers as $c) {
            $d = $this->diagnoser()->diagnose($c, OutageDiagnosis::TRIGGER_AUTO_REFRESH);
            $this->assertSame('core_router', $d->cause_code, 'customer '.$c->id);
        }
        $this->assertSame(0, OutageDiagnosis::query()->where('cause_code', 'cpe_dead')->count());
        $this->assertSame(6, OutageDiagnosis::query()->where('cause_code', 'core_router')->count());
    }

    public function test_the_correlator_reads_pon_olt_and_onu_layers_from_the_detector_tables(): void
    {
        $olt = $this->olt();
        $c = $this->customer($olt, '1/2/3:4');
        $this->tracker($olt, '1/2/3:4', 'los', now(), $c);
        $this->tracker($olt, '1/2/3:5', 'los', now());
        $this->tracker($olt, '1/2/3:6', 'online', now());
        $this->tracker($olt, '1/2/3:7', 'los', now()); // RCA-5: the PON cut needs 3 ONUs down now
        $mass = OutageSignal::create([
            'signal_type' => 'mass_los', 'scope_key' => 'pon:'.$olt->id.':1/2/3', 'state' => 'confirmed', 'olt_id' => $olt->id, 'pon_port' => '1/2/3',
            'opened_at' => now()->subMinutes(10), 'last_seen_at' => now(), 'observations' => 3, 'affected_count' => 3, 'affected_customer_ids' => [$c->id],
            'payload' => ['pon_total' => 4, 'whole_pon' => false],
        ]);

        $ctx = app(OutageCorrelator::class)->contextFor($c->fresh(), now());

        $this->assertSame('los', $ctx['onu']['phase']);
        $this->assertTrue($ctx['onu']['fresh']);
        $this->assertSame($mass->id, $ctx['pon']['mass']['id']);
        $this->assertTrue($ctx['pon']['mass']['includes']);
        $this->assertSame(4, $ctx['pon']['total']);
        $this->assertSame(2, $ctx['pon']['los_others'], 'the other LOS ONUs of the PON, not the customer itself');
        $this->assertSame(3, $ctx['pon']['down_now'], 'RCA-5: down now, the customer included');
        $this->assertSame(4, $ctx['pon']['known_total']);
        $d = $this->diagnoser()->diagnose($c->fresh(), OutageDiagnosis::TRIGGER_AUTO_REFRESH);
        $this->assertSame('pon_cut', $d->cause_code);
    }

    public function test_correlation_is_db_only_and_never_touches_a_device(): void
    {
        $olt = $this->olt();
        $c = $this->customer($olt, '1/2/3:4', ['router_id' => $this->router()->id]);
        Http::fake();
        $this->mock(MikrotikService::class, fn ($m) => $m->shouldNotReceive('connect'));
        $this->mock(OltZteC300Service::class, fn ($m) => $m->shouldNotReceive('onuDetailAndClock'));
        $this->mock(OltHuaweiMa5608tService::class, fn ($m) => $m->shouldNotReceive('ontInfoAndClock'));

        $this->diagnoser()->diagnose($c, OutageDiagnosis::TRIGGER_AUTO_REFRESH);
        $this->diagnoser()->preview($c);

        Http::assertNothingSent();
    }

    public function test_wifi_rules_work_without_the_rca3_tables_and_columns(): void
    {
        $olt = $this->olt();
        $c = $this->customer($olt, '1/2/3:4');
        $this->tracker($olt, '1/2/3:4', 'online', now(), $c);
        DB::table('customers')->where('id', $c->id)->update(['is_online' => 1, 'pppoe_online_checked_at' => now()]);

        $ctx = app(OutageCorrelator::class)->contextFor($c->fresh(), now());

        $this->assertFalse($ctx['cpe']['present']);
        $this->assertFalse($ctx['cpe']['wlan_fresh']);
        $d = $this->diagnoser()->diagnose($c->fresh(), OutageDiagnosis::TRIGGER_AUTO_REFRESH);
        $this->assertSame('unknown', $d->cause_code);
        $this->assertSame('rendah', $d->confidence);
    }

    // ------------------------------------------------------------------ auto_episode

    private function at(int $minutes): Carbon
    {
        return Carbon::parse($this->base.' UTC')->addMinutes($minutes);
    }

    private function tick(Olt $olt, int $minutes, array $phases): array
    {
        $t = $this->at($minutes);
        Carbon::setTestNow($t);
        foreach ($phases as $ref => $p) {
            [$state, $phase] = match ($p) {
                'online' => ['online', 'working'],
                'los' => ['offline', 'LOS'],
                default => ['offline', 'OffLine'],
            };
            OltOnu::updateOrCreate(['olt_id' => $olt->id, 'onu_ref' => $ref], [
                'state' => $state, 'phase_state' => $phase, 'serial_number' => 'ZTEG'.md5($ref), 'distance_m' => 1000,
                'state_collected_at' => $t, 'inventory_collected_at' => $t, 'phase_collected_at' => null,
            ]);
        }
        OltCollectRun::create(['olt_id' => $olt->id, 'tier' => 'state', 'status' => 'success', 'started_at' => $t, 'finished_at' => $t]);

        return app(OutageDetector::class)->run(null, false);
    }

    public function test_the_detector_stores_an_auto_episode_diagnosis_and_never_touches_cause_code(): void
    {
        $olt = $this->olt();
        $c = $this->customer($olt, '1/1/1:1');

        $this->tick($olt, 0, ['1/1/1:1' => 'online']);
        $this->tick($olt, 5, ['1/1/1:1' => 'los']);

        $ep = OutageSignal::query()->where('signal_type', 'single_los')->where('customer_id', $c->id)->firstOrFail();
        $this->assertSame('drop_los', $ep->diagnosed_cause);
        $this->assertNull($ep->cause_code, 'cause_code stays the human label');
        $d = OutageDiagnosis::query()->findOrFail($ep->diagnosis_id);
        $this->assertSame(OutageDiagnosis::TRIGGER_AUTO_EPISODE, $d->trigger);
        $this->assertSame($ep->id, (int) $d->signal_id);
        $this->assertSame('drop_los', $d->cause_code);
        $this->assertNotEmpty($d->evidence);
    }

    public function test_a_dry_run_and_the_kill_switch_store_no_episode_diagnosis(): void
    {
        $olt = $this->olt();
        $this->customer($olt, '1/1/1:1');
        $this->tick($olt, 0, ['1/1/1:1' => 'online']);

        Carbon::setTestNow($this->at(5));
        OltOnu::query()->where('onu_ref', '1/1/1:1')->update(['state' => 'offline', 'phase_state' => 'LOS', 'state_collected_at' => $this->at(5), 'inventory_collected_at' => $this->at(5)]);
        OltCollectRun::create(['olt_id' => $olt->id, 'tier' => 'state', 'status' => 'success', 'started_at' => $this->at(5), 'finished_at' => $this->at(5)]);
        app(OutageDetector::class)->run(null, true);
        $this->assertSame(0, OutageDiagnosis::query()->count());

        Setting::set('diagnosis_enabled', '0');
        Setting::flushRequestMemo();
        app(OutageDetector::class)->run(null, false);
        $this->assertSame(1, OutageSignal::query()->where('signal_type', 'single_los')->count());
        $this->assertSame(0, OutageDiagnosis::query()->count(), 'diagnosis_enabled = 0 stores nothing');
    }

    // ------------------------------------------------------------------ ticket_open

    public function test_an_incident_ticket_gets_a_ticket_open_diagnosis_without_any_device_io(): void
    {
        Queue::fake();
        Http::fake();
        $this->mock(OltCauseEnricher::class, fn ($m) => $m->shouldNotReceive('enrich'));
        $this->mock(MikrotikService::class, fn ($m) => $m->shouldNotReceive('connect'));
        $this->mock(OltZteC300Service::class, fn ($m) => $m->shouldNotReceive('onuDetailAndClock'));
        $this->mock(OltHuaweiMa5608tService::class, fn ($m) => $m->shouldNotReceive('ontInfoAndClock'));
        $olt = $this->olt();
        $c = $this->customer($olt, '1/1/1:1');

        $t = microtime(true);
        $this->actingAs($this->admin())->post(route('tickets.store'), ['customer_id' => $c->id, 'customer_name' => '', 'customer_phone' => '', 'customer_coords' => '', 'gangguan' => 'Internet mati', 'type' => 'incident', 'priority' => 'normal'])->assertRedirect()->assertSessionHasNoErrors();
        $elapsed = microtime(true) - $t;

        $ticket = SupportTicket::query()->where('customer_id', $c->id)->firstOrFail();
        $d = OutageDiagnosis::query()->where('ticket_id', $ticket->id)->firstOrFail();
        $this->assertSame(OutageDiagnosis::TRIGGER_TICKET_OPEN, $d->trigger);
        $this->assertSame(OutageDiagnosis::STATE_DONE, $d->state);
        // plan budget: < 200 ms on the production database; the CI bound is looser so a slow runner cannot flake it
        $this->assertLessThan(750, (int) $d->duration_ms, 'DB only, no device I/O');
        $this->assertLessThan(5.0, $elapsed);
        Http::assertNothingSent();
    }

    public function test_other_ticket_types_and_a_disabled_flag_store_no_diagnosis_and_never_block_the_ticket(): void
    {
        $olt = $this->olt();
        $c = $this->customer($olt, '1/1/1:1');
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('tickets.store'), ['customer_id' => $c->id, 'customer_name' => '', 'customer_phone' => '', 'customer_coords' => '', 'gangguan' => 'Tagihan', 'type' => 'billing', 'priority' => 'normal'])->assertSessionHasNoErrors();
        $this->assertSame(0, OutageDiagnosis::query()->count());

        Setting::set('diagnosis_enabled', '0');
        Setting::flushRequestMemo();
        $this->actingAs($admin)->post(route('tickets.store'), ['customer_id' => $c->id, 'customer_name' => '', 'customer_phone' => '', 'customer_coords' => '', 'gangguan' => 'Mati', 'type' => 'incident', 'priority' => 'normal'])->assertSessionHasNoErrors();
        $this->assertSame(0, OutageDiagnosis::query()->count());
        $this->assertSame(2, SupportTicket::query()->count());
    }

    public function test_a_finished_check_is_linked_to_the_ticket_made_right_after_it(): void
    {
        $olt = $this->olt();
        $c = $this->customer($olt, '1/1/1:1');
        $admin = $this->admin();
        $check = OutageDiagnosis::create(['customer_id' => $c->id, 'created_by' => $admin->id, 'trigger' => 'manual_check', 'state' => 'done', 'cause_code' => 'wlan_down', 'confidence' => 'sedang']);
        $other = OutageDiagnosis::create(['customer_id' => $c->id, 'created_by' => $this->admin()->id, 'trigger' => 'manual_check', 'state' => 'done', 'cause_code' => 'unknown', 'confidence' => 'rendah']);

        $this->actingAs($admin)->post(route('tickets.store'), ['customer_id' => $c->id, 'customer_name' => '', 'customer_phone' => '', 'customer_coords' => '', 'gangguan' => 'WiFi mati', 'type' => 'incident', 'priority' => 'normal', 'diagnosis_check_id' => $check->id])->assertSessionHasNoErrors();
        $ticket = SupportTicket::query()->firstOrFail();

        $this->assertSame($ticket->id, (int) $check->fresh()->ticket_id);
        $this->actingAs($admin)->post(route('tickets.store'), ['customer_id' => $c->id, 'customer_name' => '', 'customer_phone' => '', 'customer_coords' => '', 'gangguan' => 'Lagi', 'type' => 'incident', 'priority' => 'normal', 'diagnosis_check_id' => $other->id]);
        $this->assertNull($other->fresh()->ticket_id, "somebody else's check is never linked");
        // the prediction of the precision report is the ticket_open row, the check comes second
        $this->assertSame(2, OutageDiagnosis::query()->where('ticket_id', $ticket->id)->count());
    }

    public function test_diagnose_open_refreshes_open_incident_tickets_and_skips_an_unchanged_answer(): void
    {
        $olt = $this->olt();
        $c = $this->customer($olt, '1/1/1:1');
        $admin = $this->admin();
        $open = SupportTicket::create(['created_by' => $admin->id, 'customer_id' => $c->id, 'gangguan' => 'Mati', 'status' => 'open', 'type' => 'incident', 'priority' => 'normal']);
        SupportTicket::create(['created_by' => $admin->id, 'customer_id' => $c->id, 'gangguan' => 'Lama', 'status' => 'closed', 'type' => 'incident', 'priority' => 'normal']);
        SupportTicket::create(['created_by' => $admin->id, 'customer_id' => $c->id, 'gangguan' => 'Tagihan', 'status' => 'open', 'type' => 'billing', 'priority' => 'normal']);

        Artisan::call('outage:diagnose-open');
        $this->assertSame(1, OutageDiagnosis::query()->count());
        $this->assertSame(OutageDiagnosis::TRIGGER_TICKET_OPEN, OutageDiagnosis::query()->firstOrFail()->trigger, 'a ticket without any diagnosis gets its first one as ticket_open');

        Artisan::call('outage:diagnose-open');
        $this->assertSame(1, OutageDiagnosis::query()->count(), 'an unchanged answer is not stored again');

        // a change (the customer is isolated now, on the router's isolation profile) stores a new auto_refresh row
        DB::table('customers')->where('id', $c->id)->update(['status' => 'isolated', 'mikrotik_profile' => 'isolirebilling']);
        Artisan::call('outage:diagnose-open');
        $rows = OutageDiagnosis::query()->where('ticket_id', $open->id)->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(OutageDiagnosis::TRIGGER_AUTO_REFRESH, $rows[1]->trigger);
        $this->assertSame('isolir_admin', $rows[1]->cause_code);

        Setting::set('diagnosis_enabled', '0');
        Setting::flushRequestMemo();
        DB::table('customers')->where('id', $c->id)->update(['status' => 'active']);
        Artisan::call('outage:diagnose-open');
        $this->assertSame(2, OutageDiagnosis::query()->count(), 'diagnosis_enabled = 0 is a no-op');
    }

    // ------------------------------------------------------------------ Cek sekarang: HTTP flow (fake driver)

    private function seedHealthyOnuCustomer(): array
    {
        $olt = $this->olt();
        $router = $this->router();
        $c = $this->customer($olt, '1/1/1:1', ['router_id' => $router->id, 'is_online' => true, 'pppoe_online_checked_at' => now()]);
        $this->tracker($olt, '1/1/1:1', 'online', now(), $c);

        return [$olt, $router, $c];
    }

    public function test_check_now_runs_three_probes_and_finishes_with_whatever_came_back(): void
    {
        config(['diagnosis.probe_driver' => 'fake']);
        $this->enableCheckNow();
        [, , $c] = $this->seedHealthyOnuCustomer();
        $admin = $this->admin();

        $start = $this->actingAs($admin)->postJson(route('tickets.diagnose.start'), ['customer_id' => $c->id])->assertOk();
        $id = $start->json('check');
        $this->assertSame(['router' => 6, 'olt' => 15, 'cpe' => 12], $start->json('timeouts'));
        $this->assertSame(OutageDiagnosis::STATE_RUNNING, OutageDiagnosis::findOrFail($id)->state);

        $this->actingAs($admin)->postJson(route('tickets.diagnose.probe', ['diagnosis' => $id, 'probe' => 'router']))->assertOk()->assertJsonPath('status', 'ok');
        $olt = $this->actingAs($admin)->postJson(route('tickets.diagnose.probe', ['diagnosis' => $id, 'probe' => 'olt']))->assertOk();
        $olt->assertJsonPath('status', 'skipped');
        $this->assertSame('dilewati: OLT sedang dipakai pasang baru', $olt->json('text'));
        $this->actingAs($admin)->postJson(route('tickets.diagnose.probe', ['diagnosis' => $id, 'probe' => 'cpe']))->assertOk();

        $fin = $this->actingAs($admin)->postJson(route('tickets.diagnose.finish', ['diagnosis' => $id]))->assertOk();
        $fin->assertJsonPath('diagnosis.cause', 'wlan_down')->assertJsonPath('failed', false);
        $this->assertSame('sedang', $fin->json('diagnosis.confidence'), 'the band mapping is not verified yet');
        $this->assertSame('Bantu nyalakan WiFi lewat telepon', $fin->json('diagnosis.action'));
        $row = OutageDiagnosis::findOrFail($id);
        $this->assertSame(OutageDiagnosis::STATE_DONE, $row->state);
        $this->assertSame(OutageDiagnosis::TRIGGER_MANUAL, $row->trigger);
        $this->assertSame('skipped', $row->probes['olt']['status']);
        $this->assertSame('ok', $row->probes['router']['status']);
        $this->assertNotNull($row->duration_ms);

        // a finished check cannot be probed again
        $this->actingAs($admin)->postJson(route('tickets.diagnose.probe', ['diagnosis' => $id, 'probe' => 'router']))->assertStatus(410);
    }

    public function test_a_probe_that_never_answers_is_shown_as_such_and_classification_still_runs(): void
    {
        config(['diagnosis.probe_driver' => 'fake']);
        $this->enableCheckNow();
        [, , $c] = $this->seedHealthyOnuCustomer();
        $admin = $this->admin();
        $id = $this->actingAs($admin)->postJson(route('tickets.diagnose.start'), ['customer_id' => $c->id])->json('check');

        $fin = $this->actingAs($admin)->postJson(route('tickets.diagnose.finish', ['diagnosis' => $id]))->assertOk();

        $this->assertNotNull($fin->json('diagnosis.cause'));
        $row = OutageDiagnosis::findOrFail($id);
        foreach (['router', 'olt', 'cpe'] as $name) {
            $this->assertSame('failed', $row->probes[$name]['status'], $name);
            $this->assertSame('tidak menjawab', $row->probes[$name]['text']);
        }
    }

    public function test_the_second_click_waits_two_minutes_and_the_message_is_plain_indonesian(): void
    {
        config(['diagnosis.probe_driver' => 'fake']);
        $this->enableCheckNow();
        [, , $c] = $this->seedHealthyOnuCustomer();
        $admin = $this->admin();

        $this->actingAs($admin)->postJson(route('tickets.diagnose.start'), ['customer_id' => $c->id])->assertOk();
        $again = $this->actingAs($admin)->postJson(route('tickets.diagnose.start'), ['customer_id' => $c->id]);

        $again->assertStatus(429);
        $this->assertSame('Tunggu 2 menit sebelum cek lagi.', $again->json('message'));

        Carbon::setTestNow(now()->addMinutes(3));
        Cache::flush();
        $this->actingAs($admin)->postJson(route('tickets.diagnose.start'), ['customer_id' => $c->id])->assertOk();
    }

    public function test_per_user_hourly_limit_and_global_concurrency_answer_429(): void
    {
        config(['diagnosis.probe_driver' => 'fake']);
        $this->enableCheckNow();
        Setting::set('diagnose_now_per_user_hour', '2');
        Setting::set('diagnose_now_concurrent', '1');
        Setting::flushRequestMemo();
        $olt = $this->olt();
        $a = $this->customer($olt, '1/1/1:1');
        $b = $this->customer($olt, '1/1/1:2');
        $c = $this->customer($olt, '1/1/1:3');
        $admin = $this->admin();

        $first = $this->actingAs($admin)->postJson(route('tickets.diagnose.start'), ['customer_id' => $a->id])->assertOk();
        $busy = $this->actingAs($this->admin())->postJson(route('tickets.diagnose.start'), ['customer_id' => $b->id]);
        $busy->assertStatus(429);
        $this->assertSame('Sedang ada cek lain. Coba lagi sebentar.', $busy->json('message'));

        // the slot is free again after finish
        $this->actingAs($admin)->postJson(route('tickets.diagnose.finish', ['diagnosis' => $first->json('check')]))->assertOk();
        $this->actingAs($admin)->postJson(route('tickets.diagnose.start'), ['customer_id' => $b->id])->assertOk();
        $this->actingAs($admin)->postJson(route('tickets.diagnose.finish', ['diagnosis' => OutageDiagnosis::query()->latest('id')->value('id')]))->assertOk();

        $limit = $this->actingAs($admin)->postJson(route('tickets.diagnose.start'), ['customer_id' => $c->id]);
        $limit->assertStatus(429);
        $this->assertStringContainsString('Terlalu banyak cek', $limit->json('message'));
    }

    public function test_role_gate_and_the_check_now_flag(): void
    {
        config(['diagnosis.probe_driver' => 'fake']);
        $olt = $this->olt();
        $c = $this->customer($olt, '1/1/1:1');

        // flag off (default): even an admin gets 403
        $this->actingAs($this->admin())->postJson(route('tickets.diagnose.start'), ['customer_id' => $c->id])->assertForbidden();

        $this->enableCheckNow();
        // default panel roles are admin + noc: a technician is refused, superadmin always passes
        $this->actingAs($this->admin('teknisi'))->postJson(route('tickets.diagnose.start'), ['customer_id' => $c->id])->assertForbidden();
        $this->actingAs($this->admin('teknisi'))->getJson(route('tickets.diagnose.customer', $c->id))->assertForbidden();
        $this->actingAs($this->admin('noc'))->getJson(route('tickets.diagnose.customer', $c->id))->assertOk();
        $this->actingAs($this->admin('superadmin'))->getJson(route('tickets.diagnose.customer', $c->id))->assertOk();

        // somebody else's check cannot be probed
        $id = $this->actingAs($this->admin())->postJson(route('tickets.diagnose.start'), ['customer_id' => $c->id])->json('check');
        $this->actingAs($this->admin('noc'))->postJson(route('tickets.diagnose.probe', ['diagnosis' => $id, 'probe' => 'router']))->assertForbidden();

        // the owner widens the panel to technicians (the "CS" step): the technician now sees the DB-only diagnosis
        Setting::set('diagnosis_panel_roles', 'admin,noc,teknisi');
        Setting::flushRequestMemo();
        $this->actingAs($this->admin('teknisi'))->getJson(route('tickets.diagnose.customer', $c->id))->assertOk()->assertJsonPath('diagnosis.trigger', 'preview');
    }

    public function test_check_now_has_its_own_role_list_admin_first_independent_of_the_panel_roles(): void
    {
        config(['diagnosis.probe_driver' => 'fake']);
        $this->enableCheckNow();
        $olt = $this->olt();
        $c = $this->customer($olt, '1/1/1:1');
        $c2 = $this->customer($olt, '1/1/1:2');

        // noc sees the panel but cannot use the button (default check-now roles = admin)
        $noc = $this->admin('noc');
        $this->actingAs($noc)->getJson(route('tickets.diagnose.customer', $c->id))->assertOk()->assertJsonPath('checkNowEnabled', false);
        $this->actingAs($noc)->postJson(route('tickets.diagnose.start'), ['customer_id' => $c->id])->assertForbidden();
        $this->actingAs($this->admin())->getJson(route('tickets.diagnose.customer', $c->id))->assertJsonPath('checkNowEnabled', true);

        Setting::set('diagnosis_check_now_roles', 'admin,noc');
        Setting::flushRequestMemo();
        $this->actingAs($noc)->postJson(route('tickets.diagnose.start'), ['customer_id' => $c2->id])->assertOk();
        // a role outside the panel roles can never use it, whatever the check-now list says
        Setting::set('diagnosis_check_now_roles', 'admin,teknisi');
        Setting::flushRequestMemo();
        $this->actingAs($this->admin('teknisi'))->postJson(route('tickets.diagnose.start'), ['customer_id' => $c->id])->assertForbidden();
    }

    public function test_area_scope_and_idor_are_enforced_on_every_diagnose_endpoint(): void
    {
        config(['diagnosis.probe_driver' => 'fake']);
        $this->enableCheckNow();
        $olt = $this->olt();
        $areaA = Area::create(['name' => 'Area A', 'code' => 'AA']);
        $areaB = Area::create(['name' => 'Area B', 'code' => 'BB']);
        $inside = $this->customer($olt, '1/1/1:1', ['area_id' => $areaA->id]);
        $outside = $this->customer($olt, '1/1/1:2', ['area_id' => $areaB->id]);
        $scoped = $this->admin();
        DB::table('area_user')->insert(['area_id' => $areaA->id, 'user_id' => $scoped->id, 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($scoped)->getJson(route('tickets.diagnose.customer', $outside->id))->assertForbidden();
        $this->actingAs($scoped)->postJson(route('tickets.diagnose.start'), ['customer_id' => $outside->id])->assertForbidden();
        $mine = $this->actingAs($scoped)->postJson(route('tickets.diagnose.start'), ['customer_id' => $inside->id])->assertOk()->json('check');

        // another admin's check: probe / finish / show are refused for everybody but the owner (and show for admins in scope)
        $other = $this->admin();
        $this->actingAs($other)->postJson(route('tickets.diagnose.probe', ['diagnosis' => $mine, 'probe' => 'router']))->assertForbidden();
        $this->actingAs($other)->postJson(route('tickets.diagnose.finish', ['diagnosis' => $mine]))->assertForbidden();
        $this->actingAs($scoped)->postJson(route('tickets.diagnose.finish', ['diagnosis' => $mine]))->assertOk();

        // a diagnosis of a customer outside the scope cannot be read through show()
        $foreign = OutageDiagnosis::create(['customer_id' => $outside->id, 'created_by' => $scoped->id, 'trigger' => 'manual_check', 'state' => 'done', 'cause_code' => 'unknown']);
        $this->actingAs($scoped)->getJson(route('tickets.diagnose.show', $foreign->id))->assertForbidden();

        // a ticket of another customer is refused (422)
        $admin = $this->admin();
        $ticket = SupportTicket::create(['created_by' => $admin->id, 'customer_id' => $outside->id, 'gangguan' => 'Mati', 'status' => 'open', 'type' => 'incident', 'priority' => 'normal']);
        $this->actingAs($admin)->postJson(route('tickets.diagnose.start'), ['customer_id' => $inside->id, 'ticket_id' => $ticket->id])->assertStatus(422);
    }

    public function test_a_probe_runs_once_per_check_and_never_after_finish_and_abandoned_checks_lose_raw_answers(): void
    {
        config(['diagnosis.probe_driver' => 'real']);
        $olt = $this->olt();
        $router = $this->router();
        $c = $this->customer($olt, '1/5/3:1', ['router_id' => $router->id]);
        $svc = new class extends MikrotikService
        {
            public int $reads = 0;

            public function connect(Router $router, array $options = []): MikrotikService
            {
                return $this;
            }

            protected function ensureConnected(): void {}

            public function disconnect(): void {}

            protected function readQueryRows(Query $query): array
            {
                $this->reads++;

                return str_contains($query->getEndpoint(), 'active') ? [['name' => 'x', 'uptime' => '2h', 'address' => '198.18.1.2', 'caller-id' => 'AA:BB:CC:DD:EE:FF']] : [['disabled' => 'false', 'profile' => 'default']];
            }
        };
        $this->app->instance(MikrotikService::class, $svc);
        $service = $this->service();
        $check = $this->runningCheck($c);

        $first = $service->probe($check, 'router');
        $reads = $svc->reads;
        $again = $service->probe($check, 'router');
        $this->assertSame($reads, $svc->reads, 'a repeated probe does not open another router session');
        $this->assertSame($first['status'], $again['status']);
        $stored = json_encode($check->fresh()->probes);
        $this->assertStringNotContainsString('AA:BB:CC', $stored, 'no caller MAC is stored');
        $this->assertStringNotContainsString('198.18.1.2', $stored, 'no address is stored');

        // after finish a late probe is refused and writes nothing
        $service->finish($check->fresh());
        $late = $service->probe($check->fresh(), 'olt');
        $this->assertSame('finished', $late['reason']);
        $this->assertSame('no_answer', OutageDiagnosis::findOrFail($check->id)->probes['olt']['reason'], 'the late probe wrote nothing');

        // a check whose browser never called finish is closed by the 15 minute command and keeps summaries only
        $abandoned = $this->runningCheck($c);
        DB::table('outage_diagnoses')->where('id', $abandoned->id)->update(['created_at' => now()->subMinutes(30), 'probes' => json_encode(['olt' => ['status' => 'ok', 'text' => 'selesai', 'detail' => ['cause' => 'los', 'secret' => 'x']]])]);
        Artisan::call('outage:diagnose-open');
        $row = OutageDiagnosis::findOrFail($abandoned->id);
        $this->assertSame(OutageDiagnosis::STATE_FAILED, $row->state);
        $this->assertEquals(['olt' => ['status' => 'ok', 'text' => 'selesai']], $row->probes);
    }

    public function test_diagnose_open_refreshes_the_newest_tickets_first_when_over_the_cap(): void
    {
        $olt = $this->olt();
        $c = $this->customer($olt, '1/1/1:1');
        $admin = $this->admin();
        $old = SupportTicket::create(['created_by' => $admin->id, 'customer_id' => $c->id, 'gangguan' => 'Lama', 'status' => 'open', 'type' => 'incident', 'priority' => 'normal']);
        $new = SupportTicket::create(['created_by' => $admin->id, 'customer_id' => $c->id, 'gangguan' => 'Baru', 'status' => 'open', 'type' => 'incident', 'priority' => 'normal']);

        Artisan::call('outage:diagnose-open', ['--cap' => 1]);

        $this->assertSame(1, OutageDiagnosis::query()->where('ticket_id', $new->id)->count());
        $this->assertSame(0, OutageDiagnosis::query()->where('ticket_id', $old->id)->count());
    }

    public function test_the_customer_endpoint_is_a_stored_nothing_preview(): void
    {
        $olt = $this->olt();
        $c = $this->customer($olt, '1/1/1:1');
        $this->tracker($olt, '1/1/1:1', 'los', now(), $c);

        $res = $this->actingAs($this->admin())->getJson(route('tickets.diagnose.customer', $c->id))->assertOk();

        $res->assertJsonPath('diagnosis.cause', 'drop_los')->assertJsonPath('diagnosis.state', 'preview')->assertJsonPath('checkNowEnabled', false);
        $this->assertNull($res->json('diagnosis.id'));
        $this->assertSame(0, OutageDiagnosis::query()->count(), 'a preview is not stored');
    }

    public function test_ticket_pages_get_the_panel_props_only_for_allowed_roles(): void
    {
        $olt = $this->olt();
        $c = $this->customer($olt, '1/1/1:1');
        $admin = $this->admin();
        $tech = $this->admin('teknisi');
        $ticket = SupportTicket::create(['created_by' => $admin->id, 'customer_id' => $c->id, 'assigned_to' => $tech->id, 'gangguan' => 'Mati', 'status' => 'assigned', 'type' => 'incident', 'priority' => 'normal']);
        OutageDiagnosis::create(['customer_id' => $c->id, 'ticket_id' => $ticket->id, 'trigger' => 'ticket_open', 'state' => 'done', 'cause_code' => 'wlan_down', 'confidence' => 'tinggi', 'suggested_action' => 'bantu_wifi', 'evidence' => [['layer' => 'cpe', 'source' => 'summon', 'at' => null, 'text' => 'WiFi 5 GHz mati, dicek 09:15.', 'fresh' => true]]]);

        $this->actingAs($admin)->get(route('tickets.show', $ticket))->assertInertia(fn ($page) => $page
            ->where('diagnosisPanel.diagnosis.cause', 'wlan_down')
            ->where('diagnosisPanel.diagnosis.label', 'WiFi mati')
            ->where('diagnosisPanel.diagnosis.confidence', 'tinggi')
            ->where('diagnosisPanel.diagnosis.action', 'Bantu nyalakan WiFi lewat telepon')
            ->where('diagnosisPanel.checkNowEnabled', false)
            ->has('diagnosisPanel.diagnosis.evidence', 1));
        $this->actingAs($tech)->get(route('tickets.show', $ticket))->assertInertia(fn ($page) => $page->where('diagnosisPanel', null));
        $this->actingAs($admin)->get(route('tickets.create'))->assertInertia(fn ($page) => $page->has('diagnosisPanel.timeouts'));
        $this->actingAs($tech)->get(route('tickets.create'))->assertInertia(fn ($page) => $page->where('diagnosisPanel', null));
    }

    // ------------------------------------------------------------------ Cek sekarang: real probes

    private function service(?OltZteC300Service $zte = null, ?OltHuaweiMa5608tService $huawei = null): DiagnoseNowService
    {
        return new DiagnoseNowService(
            new OltCauseEnricher($zte ?? $this->zteDouble(), $huawei ?? $this->huaweiDouble()),
            app(OutageDiagnoser::class),
        );
    }

    private function zteDouble(?string $text = null): OltZteC300Service
    {
        return new class($text ?? $this->fx('zte_detail_offline_dyinggasp.txt')) extends OltZteC300Service
        {
            public int $calls = 0;

            /** @var list<int> */
            public array $timeouts = [];

            public function __construct(public string $text) {}

            public function onuDetailAndClock(Olt $olt, string $onuRef, int $timeoutSeconds = 25): array
            {
                $this->calls++;
                $this->timeouts[] = $timeoutSeconds;

                return ['detail' => $this->text, 'clock' => null];
            }
        };
    }

    private function huaweiDouble(?string $text = null): OltHuaweiMa5608tService
    {
        return new class($text ?? $this->fx('huawei_ont_never_registered.txt')) extends OltHuaweiMa5608tService
        {
            public int $calls = 0;

            public int $manualCalls = 0;

            public function __construct(public string $text) {}

            public function ontInfoAndClock(Olt $olt, string $onuRef): array
            {
                $this->calls++;

                return ['info' => $this->text, 'clock' => null];
            }

            public function ontInfoAndClockManual(Olt $olt, string $onuRef): array
            {
                $this->manualCalls++;

                return ['info' => $this->text, 'clock' => null];
            }
        };
    }

    private function runningCheck(Customer $c): OutageDiagnosis
    {
        return OutageDiagnosis::create(['customer_id' => $c->id, 'created_by' => $this->admin()->id, 'trigger' => 'manual_check', 'state' => 'running', 'probes' => []]);
    }

    public function test_the_olt_probe_is_skipped_at_once_while_psb_migration_delete_or_the_huawei_session_holds_the_olt(): void
    {
        $zteOlt = $this->olt();
        $huaweiOlt = $this->olt('huawei_ma5608t');
        $zte = $this->zteDouble();
        $huawei = $this->huaweiDouble();
        $svc = $this->service($zte, $huawei);
        $cz = $this->customer($zteOlt, '1/5/3:1');
        $ch = $this->customer($huaweiOlt, '0/0/5:3');

        $cases = [
            [$cz, OltOperationLock::psbKey($zteOlt)],
            [$cz, OltOperationLock::migrationKey($zteOlt, '1/5/3')],
            [$cz, OltOperationLock::key($zteOlt)],
            [$ch, OltOperationLock::psbKey($huaweiOlt)],
            [$ch, 'huawei-olt-ssh-session:'.$huaweiOlt->id],
            [$cz, 'outage-enrich-olt:'.$zteOlt->id],
        ];
        foreach ($cases as [$customer, $key]) {
            $held = Cache::lock($key, 60);
            $this->assertTrue($held->get());
            $started = microtime(true);
            $r = $svc->probe($this->runningCheck($customer), 'olt');
            $elapsed = microtime(true) - $started;
            $held->release();

            $this->assertSame('skipped', $r['status'], $key);
            $this->assertLessThan(1.0, $elapsed, 'never waits for '.$key);
            $this->assertStringStartsWith('dilewati: OLT sedang dipakai', $r['text'], $key);
        }
        $this->assertSame(0, $zte->calls);
        $this->assertSame(0, $huawei->calls + $huawei->manualCalls, 'no OLT session was opened');
    }

    public function test_a_recent_psb_draft_or_migration_also_skips_the_olt_probe(): void
    {
        $olt = $this->olt();
        $zte = $this->zteDouble();
        $svc = $this->service($zte);
        $c = $this->customer($olt, '1/5/3:1');
        $draft = PsbDraft::create(['status' => 'olt_ok', 'message_id' => 'r4-psb-1', 'full_name' => 'x', 'olt_id' => $olt->id]);
        DB::table('psb_drafts')->where('id', $draft->id)->update(['updated_at' => now()->subMinutes(5)]);

        $r = $svc->probe($this->runningCheck($c), 'olt');
        $this->assertSame('skipped', $r['status']);
        $this->assertSame('dilewati: OLT sedang dipakai pasang baru', $r['text']);

        DB::table('psb_drafts')->where('id', $draft->id)->update(['updated_at' => now()->subMinutes(30)]);
        $mig = CustomerMigration::create(['status' => 'received', 'customer_id' => $c->id, 'full_name' => 'x', 'pppoe_user' => 'mig.r4', 'new_sn' => 'FHTTR4001', 'new_olt_id' => $olt->id]);
        $this->assertSame('skipped', $svc->probe($this->runningCheck($c), 'olt')['status']);
        DB::table('customer_migrations')->where('id', $mig->id)->update(['updated_at' => now()->subMinutes(20)]);
        $this->assertSame('ok', $svc->probe($this->runningCheck($c), 'olt')['status']);
        $this->assertSame(1, $zte->calls);
    }

    public function test_the_manual_olt_read_uses_tight_limits_its_own_budget_and_a_two_minute_cache(): void
    {
        $olt = $this->olt();
        $huaweiOlt = $this->olt('huawei_ma5608t');
        config(['outage.olt_ids' => [$olt->id, $huaweiOlt->id]]);
        $zte = $this->zteDouble();
        $huawei = $this->huaweiDouble();
        $svc = $this->service($zte, $huawei);
        Setting::set('diagnose_olt_manual_per_hour', '2');
        Setting::flushRequestMemo();
        Carbon::setTestNow($this->at(0));
        $a = $this->customer($olt, '1/5/3:1');
        $b = $this->customer($olt, '1/5/3:2');
        $c = $this->customer($olt, '1/5/3:3');

        $r = $svc->probe($this->runningCheck($a), 'olt');
        $this->assertSame('ok', $r['status']);
        $this->assertSame([12], $zte->timeouts, 'ZTE manual timeout');
        $this->assertSame('dying_gasp', $r['detail']['cause']);

        // the same ONU within 2 minutes is reused, after 3 minutes it is read again
        $this->assertSame('skipped', $svc->probe($this->runningCheck($a), 'olt')['status']);
        $this->assertSame(1, $zte->calls);
        Carbon::setTestNow($this->at(3));
        $this->assertSame('ok', $svc->probe($this->runningCheck($a), 'olt')['status']);

        // budget of 2 manual reads per hour per OLT
        $this->assertSame('skipped', $svc->probe($this->runningCheck($b), 'olt')['status']);
        $this->assertSame('rate_limited', $svc->probe($this->runningCheck($b), 'olt')['reason']);
        // ... separate from the automatic enrichment budget (10 per hour)
        $enricher = new OltCauseEnricher($zte, $huawei);
        $this->assertSame('ok', $enricher->enrich($olt->id, '1/5/3:3', $c->id)['status']);

        // Huawei: its own manual entry point with the tight session limits, never the automatic one
        $h = $this->customer($huaweiOlt, '0/0/5:3');
        $this->assertSame('ok', $svc->probe($this->runningCheck($h), 'olt')['status']);
        $this->assertSame(1, $huawei->manualCalls);
        $this->assertSame(0, $huawei->calls);
        $this->assertSame(5, OltHuaweiMa5608tService::MANUAL_CONNECT_TIMEOUT_SECONDS);
        $this->assertSame(8, OltHuaweiMa5608tService::MANUAL_READ_TIMEOUT_SECONDS);
        $this->assertSame(15, OltHuaweiMa5608tService::MANUAL_SESSION_DEADLINE_SECONDS);
    }

    public function test_a_live_olt_read_beats_the_stored_state_in_the_diagnosis(): void
    {
        $olt = $this->olt();
        $c = $this->customer($olt, '1/5/3:1');
        $this->tracker($olt, '1/5/3:1', 'online', now()->subHours(3), $c); // stale collector row
        $svc = $this->service();
        $check = $this->runningCheck($c);

        $svc->probe($check, 'olt'); // the fixture: DyingGasp, offline since 2026-09-30

        $done = $svc->finish($check->fresh());
        $this->assertSame('power_off', $done->cause_code);
        $this->assertSame('tinggi', $done->confidence);
        $this->assertSame('tanya_listrik', $done->suggested_action);
    }

    public function test_the_router_probe_only_sends_print_commands(): void
    {
        $olt = $this->olt();
        $router = $this->router();
        $c = $this->customer($olt, '1/5/3:1', ['router_id' => $router->id]);
        $svc = new class extends MikrotikService
        {
            /** @var list<string> */
            public array $endpoints = [];

            public function connect(Router $router, array $options = []): MikrotikService
            {
                return $this;
            }

            protected function ensureConnected(): void {}

            public function disconnect(): void {}

            protected function readQueryRows(Query $query): array
            {
                $this->endpoints[] = $query->getEndpoint();

                return str_contains($query->getEndpoint(), 'active') ? [['name' => 'x', 'uptime' => '2h10m', 'address' => '198.18.1.2']] : [['disabled' => 'false', 'profile' => 'default']];
            }
        };
        $this->app->instance(MikrotikService::class, $svc);

        $r = $this->service()->probe($this->runningCheck($c), 'router');

        $this->assertSame('ok', $r['status']);
        $this->assertSame(['/ppp/active/print', '/ppp/secret/print'], $svc->endpoints);
        foreach ($svc->endpoints as $endpoint) {
            $this->assertStringEndsWith('/print', $endpoint, 'read-only: print commands only');
        }
        $this->assertSame('2h10m', $r['active']['uptime']);
    }

    public function test_a_router_that_is_busy_or_unreachable_is_skipped_with_a_reason(): void
    {
        $olt = $this->olt();
        $router = $this->router();
        $c = $this->customer($olt, '1/5/3:1', ['router_id' => $router->id]);

        $held = Cache::lock('diag-router:'.$router->id, 30);
        $this->assertTrue($held->get());
        $t = microtime(true);
        $busy = $this->service()->probe($this->runningCheck($c), 'router');
        $this->assertLessThan(1.0, microtime(true) - $t);
        $this->assertSame('skipped', $busy['status']);
        $this->assertSame('dilewati: router sedang dicek', $busy['text']);
        $held->release();

        $this->mock(MikrotikService::class, fn ($m) => $m->shouldReceive('connect')->andThrow(new \RuntimeException('timeout')));
        $down = $this->service()->probe($this->runningCheck($c), 'router');
        $this->assertSame('failed', $down['status']);
        $this->assertSame('router tidak terjangkau', $down['text']);
        $this->assertNull(Cache::lock('diag-router:'.$router->id, 5)->get() ? null : 'lock leaked', 'the router mutex is released');
    }

    public function test_the_cpe_probe_is_skipped_visibly_without_the_rca3_summon_service(): void
    {
        if (class_exists('App\\Services\\Cpe\\CpeSummonService')) {
            $this->markTestSkipped('RCA-3 summon service is present; covered by its own tests.');
        }
        $olt = $this->olt();
        $c = $this->customer($olt, '1/5/3:1');

        $r = $this->service()->probe($this->runningCheck($c), 'cpe');

        $this->assertSame('skipped', $r['status']);
        $this->assertSame('dilewati: cek ONU belum aktif', $r['text']);
    }

    public function test_the_fake_probe_driver_is_refused_in_production(): void
    {
        config(['diagnosis.probe_driver' => 'fake']);
        $this->enableCheckNow();
        $olt = $this->olt();
        $c = $this->customer($olt, '1/5/3:1');
        $this->app['env'] = 'production';

        try {
            $this->service()->start($this->admin(), $c);
            $this->fail('the fake driver must be refused in production');
        } catch (DiagnoseLimitException $e) {
            $this->assertSame(403, $e->status);
            // a probe on an existing check answers "dilewati" instead of canned values
            $r = $this->service()->probe($this->runningCheck($c), 'router');
            $this->assertSame('skipped', $r['status']);
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    // ------------------------------------------------------------------ precision report

    private function closedTicket(User $by, Customer $c, ?string $cause, Carbon $resolvedAt, string $status = 'resolved'): SupportTicket
    {
        $t = SupportTicket::create(['created_by' => $by->id, 'customer_id' => $c->id, 'gangguan' => 'Mati', 'status' => $status, 'type' => 'incident', 'priority' => 'normal', 'root_cause' => $cause]);
        DB::table('support_tickets')->where('id', $t->id)->update(['resolved_at' => $resolvedAt->copy()->utc()->format('Y-m-d H:i:s')]);

        return $t;
    }

    private function prediction(SupportTicket $t, string $trigger, string $cause): void
    {
        OutageDiagnosis::create(['customer_id' => $t->customer_id, 'ticket_id' => $t->id, 'trigger' => $trigger, 'state' => 'done', 'cause_code' => $cause, 'confidence' => 'sedang']);
    }

    public function test_the_weekly_precision_counts_the_first_prediction_and_keeps_unlabelled_closes_separate(): void
    {
        $now = Carbon::parse('2026-10-19 08:12:00', 'Asia/Jakarta'); // Monday
        Carbon::setTestNow($now);
        $olt = $this->olt();
        $c = $this->customer($olt, '1/1/1:1');
        $admin = $this->admin();
        $inWeek = Carbon::parse('2026-10-14 10:00:00', 'Asia/Jakarta');

        $t1 = $this->closedTicket($admin, $c, 'drop_los', $inWeek);             // correct
        $t2 = $this->closedTicket($admin, $c, 'drop_los', $inWeek);             // predicted cpe_dead
        $t3 = $this->closedTicket($admin, $c, 'wlan_down', $inWeek);            // predicted unknown = abstained
        $t4 = $this->closedTicket($admin, $c, null, $inWeek);                   // Ditutup otomatis
        $t5 = $this->closedTicket($admin, $c, 'unknown', $inWeek);              // Tidak tahu
        $t6 = $this->closedTicket($admin, $c, 'power_off', $inWeek);            // first manual_check is right, a later refresh is wrong
        $t7 = $this->closedTicket($admin, $c, 'cpe_dead', $inWeek);             // no diagnosis at all
        $t8 = $this->closedTicket($admin, $c, 'cpe_dead', $inWeek);             // auto_refresh only: not a prediction
        $t9 = $this->closedTicket($admin, $c, 'power_off', Carbon::parse('2026-10-07 10:00:00', 'Asia/Jakarta')); // previous week
        $this->closedTicket($admin, $c, 'drop_los', Carbon::parse('2026-10-20 10:00:00', 'Asia/Jakarta')); // after the week

        $this->prediction($t1, 'ticket_open', 'drop_los');
        $this->prediction($t1, 'auto_refresh', 'cpe_dead'); // later answers never count
        $this->prediction($t2, 'ticket_open', 'cpe_dead');
        $this->prediction($t3, 'ticket_open', 'unknown');
        $this->prediction($t5, 'ticket_open', 'drop_los');
        $this->prediction($t6, 'manual_check', 'power_off');
        $this->prediction($t6, 'auto_refresh', 'drop_los');
        $this->prediction($t8, 'auto_refresh', 'cpe_dead');
        $this->prediction($t9, 'ticket_open', 'power_off');

        $report = app(PrecisionReport::class)->compute(PrecisionReport::weekStart($now->copy()->subWeek()));

        $this->assertSame('2026-10-12', $report['week_start']);
        $this->assertSame(8, $report['tickets']);
        $this->assertSame(1, $report['closed_auto']);
        $this->assertSame(1, $report['truth_unknown']);
        $this->assertSame(2, $report['no_prediction']);
        $this->assertSame(4, $report['evaluated']);
        $this->assertSame(1, $report['abstained']);
        $this->assertSame(2, $report['correct']);
        $by = collect($report['classes'])->keyBy('cause');
        $this->assertSame(1, $by['drop_los']['predicted']);
        $this->assertSame(1.0, $by['drop_los']['precision']);
        $this->assertSame(0.5, $by['drop_los']['recall'], 'two real drop_los tickets, one was found');
        $this->assertSame(0, $by['cpe_dead']['correct']);
        $this->assertSame(0.0, $by['cpe_dead']['precision']);
        $this->assertSame(1, $by['power_off']['correct']);
        $this->assertSame([['predicted' => 'cpe_dead', 'predicted_label' => 'Modem rusak', 'actual' => 'drop_los', 'actual_label' => 'Kabel ke rumah putus', 'count' => 1]], $report['confusions']);
        // 4 weeks: the previous week's correct power_off counts too
        $this->assertSame(5, $report['rolling']['evaluated']);
        $this->assertSame(3, $report['rolling']['correct']);
    }

    public function test_the_precision_command_stores_one_row_per_week_and_sends_no_wa_by_default(): void
    {
        Http::fake();
        Carbon::setTestNow(Carbon::parse('2026-10-19 08:12:00', 'Asia/Jakarta'));
        $olt = $this->olt();
        $c = $this->customer($olt, '1/1/1:1');
        $t = $this->closedTicket($this->admin(), $c, 'drop_los', Carbon::parse('2026-10-14 10:00:00', 'Asia/Jakarta'));
        $this->prediction($t, 'ticket_open', 'drop_los');

        Artisan::call('outage:diagnosis-precision');
        Artisan::call('outage:diagnosis-precision');

        $this->assertSame(1, DB::table('outage_diagnosis_reports')->count());
        $stored = PrecisionReport::latest();
        $this->assertSame('2026-10-12', $stored['week_start']);
        $this->assertSame(1, $stored['correct']);
        Http::assertNothingSent();

        $report = $this->actingAs($this->admin('noc'))->getJson(route('api.monitoring.outage-shadow.diagnosis-precision'))->assertOk();
        $this->assertSame(1, $report->json('report.correct'));
        $this->assertStringContainsString('Ketepatan diagnosa', app(PrecisionReport::class)->summaryText($stored));
        $this->assertSame('disabled', app(PrecisionReport::class)->sendSummary($stored));
    }

    // ------------------------------------------------------------------ #563 follow-ups

    public function test_the_hourly_cap_is_taken_atomically_so_two_takers_at_cap_minus_one_cannot_both_pass(): void
    {
        Setting::set('outage_olt_enrich_per_hour', '3');
        Setting::flushRequestMemo();
        $olt = $this->olt();
        $enricher = new OltCauseEnricher($this->zteDouble(), $this->huaweiDouble());
        $take = new \ReflectionMethod($enricher, 'takeBudget');
        $take->setAccessible(true);
        $now = Carbon::parse($this->base.' UTC');
        $key = 'outage:enrich:count:'.$olt->id.':'.$now->format('YmdH');

        Cache::put($key, 2, now()->addHour()); // cap - 1
        $results = [$take->invoke($enricher, $olt->id, $now), $take->invoke($enricher, $olt->id, $now)];

        $this->assertSame([true, false], $results, 'exactly one taker passes');
        $this->assertSame(3, (int) Cache::get($key), 'the refused taker gave its increment back');

        // many takers: exactly the cap passes, the counter never drifts above it
        Cache::forget($key);
        $passed = 0;
        for ($i = 0; $i < 12; $i++) {
            $passed += $take->invoke($enricher, $olt->id, $now) ? 1 : 0;
        }
        $this->assertSame(3, $passed);
        $this->assertSame(3, (int) Cache::get($key));
    }

    public function test_the_global_cap_gives_the_per_olt_slot_back(): void
    {
        Setting::set('outage_olt_enrich_per_hour', '5');
        Setting::set('outage_olt_enrich_global_per_hour', '1');
        Setting::flushRequestMemo();
        $olt = $this->olt();
        $enricher = new OltCauseEnricher($this->zteDouble(), $this->huaweiDouble());
        $take = new \ReflectionMethod($enricher, 'takeBudget');
        $take->setAccessible(true);
        $now = Carbon::parse($this->base.' UTC');
        $hour = $now->format('YmdH');

        $this->assertTrue($take->invoke($enricher, $olt->id, $now));
        $this->assertFalse($take->invoke($enricher, $olt->id, $now));
        $this->assertSame(1, (int) Cache::get('outage:enrich:count:'.$olt->id.':'.$hour), 'the refused call did not eat a per-OLT slot');
        $this->assertSame(1, (int) Cache::get('outage:enrich:count:all:'.$hour));
    }

    private function deadlineHuawei(array $durations): object
    {
        return new class($durations) extends OltHuaweiMa5608tService
        {
            public float $clock = 1000.0;

            /** @var list<int> */
            public array $timeouts = [];

            /** @var list<string> */
            public array $commands = [];

            public function __construct(public array $durations) {}

            protected function nowFloat(): float
            {
                return $this->clock;
            }

            protected function connect(Olt $olt): SSH2
            {
                $ssh = Mockery::mock(SSH2::class);
                $ssh->shouldReceive('setTimeout')->andReturnUsing(function (int $t): void {
                    $this->timeouts[] = $t;
                });

                return $ssh;
            }

            protected function run(SSH2 $ssh, string $command): string
            {
                $this->guardSessionDeadline($ssh); // the same first step as the real run()
                $this->commands[] = $command;
                $this->clock += $this->durations[count($this->commands) - 1] ?? 0;

                return 'ok';
            }

            protected function disconnectSafely(?SSH2 $ssh): void {}
        };
    }

    public function test_the_huawei_manual_session_checks_its_deadline_before_every_command_and_bounds_each_read(): void
    {
        $olt = $this->olt('huawei_ma5608t');

        // the first command eats 11 of the 15 seconds: the next read may only take the 4 that are left
        $svc = $this->deadlineHuawei([11, 1]);
        $svc->ontInfoAndClockManual($olt, '0/0/7:26');
        $this->assertSame([8, 4], $svc->timeouts, 'min(read 8 s, what is left)');
        $this->assertSame(['display time', 'display ont info 0 0 7 26'], $svc->commands);

        // one slow read that overruns the whole deadline: the second command is never sent
        $slow = $this->deadlineHuawei([16]);
        try {
            $slow->ontInfoAndClockManual($olt, '0/0/7:26');
            $this->fail('the deadline must stop the session');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('deadline exceeded', $e->getMessage());
        }
        $this->assertSame(['display time'], $slow->commands, 'no command after the deadline');

        // the automatic enrichment keeps its own, looser limits
        $auto = $this->deadlineHuawei([0, 0]);
        $auto->ontInfoAndClock($olt, '0/0/7:26');
        $this->assertSame([15, 15], $auto->timeouts);
    }

    public function test_the_real_huawei_run_guards_the_deadline_first(): void
    {
        $source = (string) file_get_contents(base_path('app/Services/OltHuaweiMa5608tService.php'));

        $this->assertMatchesRegularExpression('/protected function run\(SSH2 \$ssh, string \$command\): string\s*\{\s*\$this->guardSessionDeadline\(\$ssh\);/', $source);
    }

    // ------------------------------------------------------------------ settings

    public function test_diagnosis_settings_have_safe_defaults_and_a_validated_writer(): void
    {
        $this->assertTrue(OutageSettings::diagnosisEnabled());
        $this->assertSame(['admin', 'noc'], OutageSettings::diagnosisPanelRoles());
        $this->assertFalse(OutageSettings::diagnosisCheckNowEnabled(), '"Cek sekarang" is OFF by default');
        $this->assertSame(20, OutageSettings::diagnoseNowPerUserHour());
        $this->assertSame(2, OutageSettings::diagnoseNowCustomerCooldownMinutes());
        $this->assertSame(2, OutageSettings::diagnoseNowConcurrent());
        $this->assertSame(10, OutageSettings::diagnoseOltManualPerHour());
        $this->assertFalse(OutageSettings::diagnosisWeeklyWaEnabled());
        $this->assertTrue(OutageSettings::diagnosisPanelVisibleTo('superadmin'));
        $this->assertFalse(OutageSettings::diagnosisPanelVisibleTo('teknisi'));
        $this->assertArrayHasKey('diagnosis_check_now_enabled', OutageSettings::snapshot());

        $w = new OutageSettingsWriter;
        $this->assertTrue($w->set('diagnosis_check_now_enabled', '1', 'test')['ok']);
        $this->assertTrue($w->set('diagnosis_panel_roles', 'admin, noc,teknisi', 'test')['ok']);
        $this->assertTrue($w->set('diagnose_olt_manual_per_hour', '5', 'test')['ok']);
        Setting::flushRequestMemo();
        $this->assertTrue(OutageSettings::diagnosisCheckNowEnabled());
        $this->assertSame(['admin', 'noc', 'teknisi'], OutageSettings::diagnosisPanelRoles());
        $this->assertSame(5, OutageSettings::diagnoseOltManualPerHour());
        $this->assertDatabaseHas('activity_log', ['event' => 'outage_setting_changed']);

        $this->assertFalse($w->set('diagnosis_panel_roles', 'admin,hacker', 'test')['ok']);
        $this->assertFalse($w->set('diagnose_now_concurrent', '99', 'test')['ok']);
        $this->assertFalse($w->set('diagnosis_enabled', 'maybe', 'test')['ok']);
    }

    public function test_the_cpe_probe_maps_the_rca3_summon_answers_and_a_fresh_answer_feeds_the_diagnosis(): void
    {
        $class = 'App\\Services\\Cpe\\CpeSummonService';
        if (! class_exists($class)) {
            class_alias(Rca4FakeSummon::class, $class); // RCA-3 not merged: stand-in with the documented interface
        }
        $fake = new Rca4FakeSummon;
        $this->app->instance($class, $fake); // also overrides the real service when RCA-3 is present
        [, , $c] = $this->seedHealthyOnuCustomer();
        $svc = $this->service();

        $fake->answer = ['status' => 'queued', 'reason' => 'queued', 'message' => 'x', 'data' => null];
        $this->assertSame(['status' => 'queued', 'text' => 'ONU belum menjawab, hasil masuk paling lama 5 menit'], array_diff_key($svc->probe($this->runningCheck($c), 'cpe'), ['at' => 1]));

        $fake->answer = ['status' => 'skipped', 'reason' => 'busy_psb', 'message' => 'Dilewati: pemasangan atau migrasi sedang jalan.', 'data' => null];
        $this->assertSame('dilewati: pelanggan sedang dipasang atau dimigrasi', $svc->probe($this->runningCheck($c), 'cpe')['text']);

        $fake->answer = ['status' => 'skipped', 'reason' => 'something_new', 'message' => 'Tunggu 2 menit sebelum cek lagi.', 'data' => null];
        $this->assertSame('dilewati: tunggu 2 menit sebelum cek lagi', $svc->probe($this->runningCheck($c), 'cpe')['text']);

        $fake->answer = ['status' => 'error', 'reason' => 'timeout', 'message' => 'x', 'data' => null];
        $failed = $svc->probe($this->runningCheck($c), 'cpe');
        $this->assertSame('failed', $failed['status']);
        $this->assertSame('ONU tidak terjangkau', $failed['text']);

        // a fresh answer: 5 GHz is off -> WLAN_DOWN from the summon values (the router flag of the customer says online)
        $fake->answer = ['status' => 'fresh', 'reason' => 'ok', 'message' => 'ok', 'device_id' => 'dev1', 'data' => [
            'at' => now()->toIso8601String(),
            'wlan24' => ['status' => 'Up', 'enabled' => true, 'assoc' => 2], 'wlan5' => ['status' => 'Disabled', 'enabled' => false, 'assoc' => 0],
            'ppp' => ['status' => 'Connected', 'error' => 'ERROR_NONE'], 'rssi' => [-60, -62], 'lan_active' => 1,
        ]];
        $check = $this->runningCheck($c);
        $ok = $svc->probe($check, 'cpe');
        $this->assertSame('ok', $ok['status']);
        $this->assertSame('Disabled', $ok['data']['wlan']['5']['status']);

        $done = $svc->finish($check->fresh());
        $this->assertSame('wlan_down', $done->cause_code);
        $this->assertStringContainsString('5 GHz', $done->evidence[0]['text']);
    }

    public function test_the_huawei_busy_session_surfaces_as_a_skip_not_an_error(): void
    {
        $huaweiOlt = $this->olt('huawei_ma5608t');
        $c = $this->customer($huaweiOlt, '0/0/5:3');
        $huawei = new class extends OltHuaweiMa5608tService
        {
            public function ontInfoAndClockManual(Olt $olt, string $onuRef): array
            {
                throw new HuaweiOltSessionBusyException;
            }
        };

        $r = $this->service(null, $huawei)->probe($this->runningCheck($c), 'olt');

        $this->assertSame('skipped', $r['status']);
        $this->assertSame('dilewati: OLT sedang dipakai', $r['text']);
        $this->assertNull(Cache::get('outage:enrich:manual-at:'.$huaweiOlt->id.':0/0/5:3'), 'a busy OLT leaves no "read lately" mark');
    }
}

/**
 * Stand-in for the RCA-3 `App\Services\Cpe\CpeSummonService` (interface notes: summonCustomer() answers
 * status fresh|queued|skipped|error, reason, message, data).
 */
class Rca4FakeSummon
{
    /** @var array<string,mixed> */
    public array $answer = ['status' => 'skipped', 'reason' => 'off', 'message' => 'Cek ONU belum diaktifkan.', 'data' => null];

    /** @return array<string,mixed> */
    public function summonCustomer(int $customerId, string $reason, mixed $user = null): array
    {
        return $this->answer;
    }
}
