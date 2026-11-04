<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Customer;
use App\Models\Olt;
use App\Models\OutageSignal;
use App\Models\Package;
use App\Models\Setting;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\EvolutionApiService;
use App\Services\Outage\OutageShadowDigest;
use App\Services\Outage\OutageShadowReporter;
use App\Services\Outage\OutageTicketMatcher;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Roadmap 2.4 PR-4: ticket matcher, shadow report / replay / label, daily digest. Read-only towards
 * tickets and customers; WA only to the technical log group.
 */
#[Group('blocking-core')]
class OutageShadowReportTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private Olt $olt;

    /** 05:00 UTC = 12:00 WIB, outside the 22-07 quiet window. */
    private string $base = '2026-10-15 05:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        Setting::flushRequestMemo();
        Carbon::setTestNow(Carbon::parse($this->base.' UTC'));
        $this->olt = Olt::create(['name' => 'OLT RA', 'code' => 'RPT-1', 'vendor' => 'zte_c300']);
        config(['outage.olt_ids' => [$this->olt->id]]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function customer(array $o = []): Customer
    {
        $package = Package::firstOrCreate(['code' => 'RPT-PKG'], ['name' => 'Report pkg', 'price' => 100000]);
        $n = ++$this->seq;

        return Customer::create(array_merge([
            'code' => 'RPT-C'.$n,
            'name' => 'Rahasia Pelanggan '.$n,
            'phone' => '0813999'.$n,
            'address' => 'Jl. Contoh',
            'pppoe_user' => 'rpt.'.$n,
            'package_id' => $package->id,
            'status' => 'active',
            'olt_id' => $this->olt->id,
            'olt_port_label' => '1/1/1:'.$n,
        ], $o));
    }

    private function ticket(?Customer $c, int $minutesFromBase, array $o = []): SupportTicket
    {
        $t = SupportTicket::create(array_merge([
            'customer_id' => $c?->id,
            'created_by' => User::factory()->create(['role' => 'admin'])->id,
            'gangguan' => 'Internet mati',
            'subject' => 'Internet mati',
            'status' => 'open',
            'priority' => 'normal',
            'type' => 'incident',
        ], $o));
        $t->area_id = $c?->area_id; // not mass assignable
        $t->created_at = Carbon::parse($this->base.' UTC')->addMinutes($minutesFromBase);
        $t->save();

        return $t;
    }

    private function signal(array $o = []): OutageSignal
    {
        $n = ++$this->seq;

        return OutageSignal::create(array_merge([
            'signal_type' => 'single_los',
            'scope_key' => 'customer:'.$n,
            'olt_id' => $this->olt->id,
            'pon_port' => '1/1/1',
            'state' => 'confirmed',
            'opened_at' => Carbon::parse($this->base.' UTC'),
            'confirmed_at' => Carbon::parse($this->base.' UTC')->addMinutes(30),
            'last_seen_at' => Carbon::parse($this->base.' UTC')->addMinutes(60),
            'affected_count' => 1,
            'would_action' => 'ticket_single',
            'mode_at_confirm' => 'shadow',
        ], $o));
    }

    private function span(): array
    {
        return [Carbon::parse($this->base.' UTC')->subDay(), Carbon::parse($this->base.' UTC')->addDays(3)];
    }

    // -------------------------------------------------------------- matcher

    public function test_individual_match_uses_customer_and_the_minus_2h_plus_48h_window(): void
    {
        $c = $this->customer();
        $other = $this->customer();
        $s = $this->signal(['customer_id' => $c->id]);

        $this->ticket($other, 10);                       // other customer: never
        $this->ticket($c, -121);                         // 1 minute before the window
        $this->ticket($c, 48 * 60 + 1);                  // 1 minute after the window
        [$from, $to] = $this->span();
        $this->assertSame(0, (new OutageTicketMatcher)->run($from, $to)['matched']);

        $inside = $this->ticket($c, 45);
        $stats = (new OutageTicketMatcher)->run($from, $to);

        $s->refresh();
        $this->assertSame(1, $stats['matched']);
        $this->assertSame($inside->id, $s->matched_ticket_id);
        $this->assertSame('customer', $s->match_method);
        $this->assertSame(15, $s->match_lag_minutes, 'ticket.created_at - confirmed_at');

        $early = $this->ticket($c, -20);                 // reported 20 min before opened_at: inside the window and closest
        (new OutageTicketMatcher)->run($from, $to);
        $this->assertSame($early->id, $s->fresh()->matched_ticket_id);
        $this->assertSame(-50, $s->fresh()->match_lag_minutes, 'negative: the customer reported before we confirmed');
    }

    public function test_auto_and_cancelled_tickets_never_count(): void
    {
        $c = $this->customer();
        $s = $this->signal(['customer_id' => $c->id]);
        $this->ticket($c, 20, ['meta' => ['auto_ticket' => ['signal_id' => $s->id]]]);
        $this->ticket($c, 25, ['status' => 'cancelled']);
        $this->ticket($c, 26, ['type' => 'billing']);

        [$from, $to] = $this->span();
        $this->assertSame(0, (new OutageTicketMatcher)->run($from, $to)['matched']);
        $this->assertNull($s->fresh()->matched_ticket_id);
    }

    public function test_mass_matches_any_affected_customer_or_area_plus_keyword(): void
    {
        $area = Area::create(['code' => 'A1', 'name' => 'Area 1']);
        $elsewhere = Area::create(['code' => 'A2', 'name' => 'Area 2']);
        $affected = [$this->customer(['area_id' => $area->id]), $this->customer(['area_id' => $area->id])];
        $bystander = $this->customer(['area_id' => $area->id]);
        $mass = $this->signal([
            'signal_type' => 'mass_los', 'scope_key' => 'pon:1:1/1/1', 'would_action' => 'ticket_mass',
            'affected_count' => 2, 'affected_customer_ids' => [$affected[0]->id, $affected[1]->id],
        ]);
        [$from, $to] = $this->span();

        // Same area, no outage wording: no match. Outage wording in another area: no match.
        $this->ticket($bystander, 30, ['gangguan' => 'Ganti password wifi', 'subject' => 'Ganti password wifi']);
        $far = $this->customer(['area_id' => $elsewhere->id]);
        $this->ticket($far, 30, ['gangguan' => 'LOS merah', 'subject' => 'LOS merah']);
        $this->assertSame(0, (new OutageTicketMatcher)->run($from, $to)['matched']);

        // Same area + keyword inside +-6h: match; outside +-6h: no match.
        $this->ticket($bystander, 361, ['gangguan' => 'Lampu LOS merah']);
        $this->assertSame(0, (new OutageTicketMatcher)->run($from, $to)['matched']);
        $kw = $this->ticket($bystander, 90, ['gangguan' => 'Lampu LOS merah']);
        (new OutageTicketMatcher)->run($from, $to);
        $this->assertSame($kw->id, $mass->fresh()->matched_ticket_id);
        $this->assertSame('area_keyword', $mass->fresh()->match_method);

        // A ticket for an affected customer wins (customer method) even without keywords.
        $direct = $this->ticket($affected[1], 600, ['gangguan' => 'Tolong dicek']);
        (new OutageTicketMatcher)->run($from, $to);
        $this->assertSame($direct->id, $mass->fresh()->matched_ticket_id);
        $this->assertSame('mass_customer', $mass->fresh()->match_method);
    }

    public function test_rematch_is_idempotent_and_dry_run_writes_nothing(): void
    {
        $c = $this->customer();
        $s = $this->signal(['customer_id' => $c->id]);
        $this->ticket($c, 40);
        [$from, $to] = $this->span();

        $dry = (new OutageTicketMatcher)->run($from, $to, true);
        $this->assertSame(1, $dry['changed']);
        $this->assertNull($s->fresh()->matched_ticket_id);

        $first = (new OutageTicketMatcher)->run($from, $to);
        $second = (new OutageTicketMatcher)->run($from, $to);
        $this->assertSame(1, $first['changed']);
        $this->assertSame(0, $second['changed']);
        $this->assertSame(1, SupportTicket::query()->count(), 'tickets are read only');
    }

    // --------------------------------------------------------------- report

    public function test_precision_recall_lead_time_and_missed_reasons(): void
    {
        $now = Carbon::parse($this->base.' UTC');
        // Four would-have-ticketed individual episodes, two of them matched by a real ticket.
        $a = $this->customer();
        $b = $this->customer();
        $c = $this->customer();
        $d = $this->customer();
        foreach ([$a, $b, $c, $d] as $cust) {
            $this->signal(['customer_id' => $cust->id]);
        }
        $this->ticket($a, 40); // lag 10
        $this->ticket($b, 60); // lag 30
        // Recall denominators: a, b covered; c has a ticket but the episode came 5h later (not covered);
        // unmapped + unmonitored customers are reported as missed reasons.
        $this->ticket($c, -600);
        $unmapped = $this->customer(['olt_id' => null, 'olt_port_label' => null]);
        $this->ticket($unmapped, 100);
        $otherOlt = Olt::create(['name' => 'HiOSO', 'code' => 'RPT-2', 'vendor' => 'hioso']);
        $this->ticket($this->customer(['olt_id' => $otherOlt->id]), 100);
        $this->ticket($this->customer(), 100, ['type' => 'incident']); // monitorable, no signal at all
        // auto ticket is not a real ticket: does not count towards recall either
        $this->ticket($d, 10, ['meta' => ['auto_ticket' => ['signal_id' => 1]]]);

        $from = $now->copy()->subDay();
        $to = $now->copy()->addDay();
        (new OutageTicketMatcher)->run($from, $to);
        OutageSignal::query()->where('customer_id', $a->id)->update(['label' => 'tp']);
        OutageSignal::query()->where('customer_id', $c->id)->update(['label' => 'fp']);

        $r = (new OutageShadowReporter)->build($from, $to, []);

        $s = $r['signals']['single_los'];
        $this->assertSame(4, $s['would_ticket']);
        $this->assertSame(2, $s['matched']);
        $this->assertSame(0.5, $s['precision_matched']);
        $this->assertSame(0.5, $s['precision_label']);
        $this->assertFalse($s['label_sample_ok'], 'fewer than 20 labels');
        $this->assertSame(20.0, $s['lead_time_median_min']);
        $this->assertSame(2.0, $s['would_ticket_per_day']);

        // Real incident tickets with a customer (auto excluded): a, b, c, unmapped, otherOlt, monitorable-no-signal = 6.
        // Monitorable: a, b, c, no-signal = 4; covered: a, b = 2.
        $rc = $r['recall'];
        $this->assertSame(6, $rc['real_tickets']);
        $this->assertSame(4, $rc['monitorable_tickets']);
        $this->assertSame(2, $rc['covered']);
        $this->assertSame(0.5, $rc['recall']);
        $this->assertSame(1, $rc['missed_reasons']['not_mapped']);
        $this->assertSame(1, $rc['missed_reasons']['olt_not_monitored']);
        $this->assertSame(1, $rc['missed_reasons']['no_signal']);
        $this->assertSame(1, $rc['missed_reasons']['detected_late'], 'c: episode confirmed 10h after the ticket');
        $this->assertSame(0, $rc['missed_reasons']['below_threshold']);
    }

    public function test_replay_recomputes_would_action_for_alternative_mass_min(): void
    {
        $matchedCustomer = $this->customer();
        foreach ([2, 3, 4, 6] as $n) {
            $ids = $n === 3 ? [$matchedCustomer->id] : [];
            $this->signal([
                'signal_type' => 'mass_los', 'would_action' => $n >= 4 ? 'ticket_mass' : 'none',
                'affected_count' => $n, 'affected_customer_ids' => $ids,
            ]);
        }
        $this->ticket($matchedCustomer, 45);
        $this->signal(['signal_type' => 'mass_los', 'affected_count' => 9, 'suppressed_reason' => 'maintenance', 'would_action' => 'none']);

        $from = Carbon::parse($this->base.' UTC')->subDay();
        $to = Carbon::parse($this->base.' UTC')->addDay();
        (new OutageTicketMatcher)->run($from, $to);

        $this->assertSame(['mass_min' => [3.0, 4.0, 5.0], 'single_los' => [20.0, 30.0], 'weak_rx' => [-26.0, -27.0]],
            OutageShadowReporter::parseReplay('mass_min:3,4,5;single_los:20,30;weak_rx:-26,-27;bogus:1'));

        $rows = (new OutageShadowReporter)->build($from, $to, OutageShadowReporter::parseReplay('mass_min:3,4,5'))['replay']['mass_min'];
        $byT = array_column($rows, null, 'threshold');
        $this->assertSame(3, $byT[3.0]['would_ticket']);   // 3, 4, 6 (suppressed 9 never counts)
        $this->assertSame(2, $byT[4.0]['would_ticket']);   // 4, 6
        $this->assertSame(1, $byT[5.0]['would_ticket']);   // 6
        $this->assertSame(1, $byT[3.0]['matched']);
        $this->assertEqualsWithDelta(0.333, $byT[3.0]['precision_matched'], 0.001);
        $this->assertSame(0, $byT[5.0]['matched']);
    }

    public function test_report_and_label_commands(): void
    {
        $s = $this->signal();
        $this->assertSame(0, Artisan::call('outage:shadow-label', ['id' => $s->id, 'label' => 'fp', '--user' => User::factory()->create(['role' => 'admin'])->id]));
        $this->assertSame('fp', $s->fresh()->label);
        $this->assertNotNull($s->fresh()->labelled_by);

        $this->assertSame(1, Artisan::call('outage:shadow-label', ['id' => $s->id, 'label' => 'maybe']));
        $this->assertSame(1, Artisan::call('outage:shadow-label', ['id' => 999999, 'label' => 'tp']));
        $this->assertSame(1, Artisan::call('outage:shadow-label', ['id' => $s->id, 'label' => 'tp', '--user' => 424242]));
        $this->assertSame('fp', $s->fresh()->label);
        $this->assertSame(0, Artisan::call('outage:shadow-label', ['id' => $s->id, 'label' => 'clear']));
        $this->assertNull($s->fresh()->label);

        $this->assertSame(0, Artisan::call('outage:shadow-label', ['--pending' => true]));

        $this->assertSame(0, Artisan::call('outage:shadow-report', ['--json' => true, '--replay' => 'mass_min:3,4', '--from' => '2026-10-14', '--to' => '2026-10-17']));
        $json = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($json);
        $this->assertSame(1, $json['signals']['single_los']['would_ticket']);
        $this->assertArrayHasKey('mass_min', $json['replay']);
        $this->assertSame(1, Artisan::call('outage:shadow-report', ['--from' => '2026-10-17', '--to' => '2026-10-14']));
    }

    // ---------------------------------------------------------------- digest

    private function fakeEvolution(int $expectedSends, ?array &$texts = null): MockInterface
    {
        $texts = [];
        $mock = \Mockery::mock(EvolutionApiService::class);
        $mock->shouldReceive('forChannel')->with('ops')->andReturnSelf();
        $mock->shouldReceive('sendToGroup')->times($expectedSends)->andReturnUsing(function (string $jid, string $text) use (&$texts) {
            $texts[] = [$jid, $text];

            return ['success' => true];
        });
        $this->app->instance(EvolutionApiService::class, $mock);

        return $mock;
    }

    public function test_digest_flag_off_by_default_sends_nothing(): void
    {
        Http::fake();
        Queue::fake();
        Setting::set('outage_shadow_log_jid', '120363000000000001@g.us');
        $this->fakeEvolution(0);

        $this->assertFalse(OutageShadowDigest::enabled());
        $this->assertFalse(OutageShadowDigest::shouldRun());
        $this->assertSame('disabled', (new OutageShadowDigest)->send());
        $this->assertSame(0, Artisan::call('outage:shadow-digest'));
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_digest_needs_a_log_group_and_never_goes_to_psb_or_report_groups(): void
    {
        Setting::set('outage_shadow_digest_enabled', '1');
        $this->fakeEvolution(0);

        $this->assertSame('no_jid', (new OutageShadowDigest)->send());

        Setting::set('psb_wa_group_ids', ['120363000000000002@g.us']);
        Setting::set('report_wa_group_ids', ['120363000000000003@g.us']);
        Setting::set('wa_report_group_jid', '120363000000000004@g.us');
        foreach (['120363000000000002@g.us' => 'psb', '120363000000000003@g.us' => 'report', '120363000000000004@g.us' => 'report'] as $jid => $kind) {
            Setting::set('outage_shadow_log_jid', $jid);
            $this->assertStringStartsWith('refused:', (new OutageShadowDigest)->send(), $kind);
        }

        // Even with the allow flag the PSB group stays refused.
        Setting::set('outage_shadow_allow_report_group', '1');
        Setting::set('outage_shadow_log_jid', '120363000000000002@g.us');
        $this->assertStringStartsWith('refused:', (new OutageShadowDigest)->send());
    }

    public function test_digest_sends_once_per_day_to_the_log_group_without_any_customer_data(): void
    {
        Setting::set('outage_shadow_digest_enabled', '1');
        Setting::set('outage_shadow_log_jid', '120363000000000001@g.us');
        $texts = [];
        $this->fakeEvolution(1, $texts);

        $c = $this->customer(['name' => 'Budi Rahasia', 'phone' => '081200000046', 'pppoe_user' => 'budi.secret']);
        $this->signal(['customer_id' => $c->id, 'opened_at' => now()->subHours(3), 'confirmed_at' => now()->subHours(2)]);
        $this->signal(['signal_type' => 'mass_los', 'scope_key' => 'pon:1:1/1/1', 'would_action' => 'ticket_mass', 'affected_count' => 5, 'affected_customer_ids' => [$c->id], 'opened_at' => now()->subHours(3), 'confirmed_at' => now()->subHours(2)]);
        $this->ticket($c, -60, ['gangguan' => 'Budi Rahasia internet mati 081200000046']);

        $now = Carbon::parse($this->base.' UTC');
        $this->assertSame('sent', (new OutageShadowDigest)->send($now));
        $this->assertSame('already_sent', (new OutageShadowDigest)->send($now->copy()->addHours(1)), 'deduped per WIB day');

        $this->assertSame('120363000000000001@g.us', $texts[0][0]);
        $body = $texts[0][1];
        $this->assertStringContainsString('[SHADOW 2.4] Ringkasan harian', $body);
        $this->assertStringContainsString('seharusnya tiket 2', $body);
        $this->assertStringContainsString('OLT RA PON 1/1/1', $body);
        $this->assertStringContainsString('tidak ada WA ke pelanggan', $body);
        foreach (['Budi', 'Rahasia', '081200000046', 'budi.secret', (string) $c->code] as $pii) {
            $this->assertStringNotContainsString($pii, $body);
        }
    }

    public function test_digest_failure_can_be_retried_and_quiet_hours_hold_it(): void
    {
        Setting::set('outage_shadow_digest_enabled', '1');
        Setting::set('outage_shadow_log_jid', '120363000000000001@g.us');
        $mock = \Mockery::mock(EvolutionApiService::class);
        $mock->shouldReceive('forChannel')->with('ops')->andReturnSelf();
        $mock->shouldReceive('sendToGroup')->twice()->andReturn(['success' => false], ['success' => true]);
        $this->app->instance(EvolutionApiService::class, $mock);

        $this->assertSame('quiet_hours', (new OutageShadowDigest)->send(Carbon::parse('2026-10-15 17:00:00 UTC')), '00:00 WIB');
        $now = Carbon::parse($this->base.' UTC');
        $this->assertSame('failed', (new OutageShadowDigest)->send($now));
        $this->assertSame('sent', (new OutageShadowDigest)->send($now));
    }

    // -------------------------------------------------------- scheduler

    /** @return list<Event> */
    private function monitoringEvents(): array
    {
        config(['services.ebilling_scheduler.mode' => 'monitoring']);
        $schedule = new Schedule;
        $this->app->instance(Schedule::class, $schedule);
        ScheduleFacade::clearResolvedInstance(Schedule::class);
        require base_path('routes/console.php');

        return $schedule->events();
    }

    public function test_matcher_volume_is_indexed_and_covers_router_level_and_keyword_boundaries(): void
    {
        // 120 customers, 120 episodes, 240 tickets: only each customer's own tickets are examined.
        $first = null;
        $customers = [];
        for ($i = 0; $i < 120; $i++) {
            $c = $this->customer();
            $customers[] = $c;
            $first ??= $c;
            $this->signal(['customer_id' => $c->id, 'scope_key' => 'customer:v'.$i]);
        }
        foreach ($customers as $i => $c) {
            $this->ticket($c, 20 + ($i % 50));
            $this->ticket($c, 20 + ($i % 50), ['type' => 'billing']);
        }
        [$from, $to] = $this->span();
        $stats = (new OutageTicketMatcher)->run($from, $to);
        $this->assertSame(120, $stats['matched']);
        $this->assertSame(0, $stats['unmatched']);

        // Router-level pppoe_down: no customer_id, affected_customer_ids carries the customers.
        $router = $this->signal([
            'signal_type' => 'pppoe_down', 'customer_id' => null, 'scope_key' => 'router:9', 'would_action' => 'noc_alarm',
            'affected_customer_ids' => [$customers[3]->id, $customers[4]->id],
        ]);
        (new OutageTicketMatcher)->run($from, $to);
        $this->assertNotNull($router->fresh()->matched_ticket_id);
        $this->assertSame('mass_customer', $router->fresh()->match_method);

        // "los" must be a whole word in the area fallback.
        $this->assertSame(1, preg_match(OutageTicketMatcher::KEYWORDS, 'lampu LOS merah'));
        $this->assertSame(0, preg_match(OutageTicketMatcher::KEYWORDS, 'kalosi closing'));
    }

    public function test_matcher_write_never_restores_open_key_of_an_episode_resolved_meanwhile(): void
    {
        $c = $this->customer();
        $s = $this->signal(['customer_id' => $c->id, 'scope_key' => 'customer:race', 'open_key' => 'customer:race']);
        $this->assertSame('customer:race', $s->fresh()->open_key);
        $this->ticket($c, 40);

        // The detector resolves the episode right after the matcher loaded it (open_key -> NULL).
        $fired = false;
        OutageSignal::retrieved(function (OutageSignal $loaded) use (&$fired, $s): void {
            if (! $fired && $loaded->id === $s->id) {
                $fired = true;
                DB::table('outage_signals')->where('id', $s->id)->update(['state' => 'resolved', 'open_key' => null]);
            }
        });

        [$from, $to] = $this->span();
        (new OutageTicketMatcher)->run($from, $to);

        $row = $s->fresh();
        $this->assertTrue($fired);
        $this->assertNotNull($row->matched_ticket_id, 'matched_* was written');
        $this->assertSame('resolved', $row->state);
        $this->assertNull($row->open_key, 'open_key must stay NULL');
        // A new episode for the same scope can open (unique open_key not blocked).
        $new = $this->signal(['customer_id' => $c->id, 'scope_key' => 'customer:race', 'state' => 'open', 'confirmed_at' => null]);
        $this->assertSame('customer:race', $new->fresh()->open_key);
    }

    public function test_suppressed_episodes_are_not_counted_as_below_threshold_misses(): void
    {
        $c = $this->customer();
        $this->signal(['customer_id' => $c->id, 'state' => 'open', 'confirmed_at' => null, 'would_action' => 'none', 'suppressed_reason' => 'open_ticket']);
        $this->ticket($c, 40);
        $r = (new OutageShadowReporter)->recall(Carbon::parse($this->base.' UTC')->subDay(), Carbon::parse($this->base.' UTC')->addDay());
        $this->assertSame(0, $r['missed_reasons']['below_threshold']);
        $this->assertSame(1, $r['missed_reasons']['suppressed:open_ticket']);
    }

    public function test_digest_dry_run_writes_no_matches_and_report_dates_are_wib(): void
    {
        $c = $this->customer();
        $s = $this->signal(['customer_id' => $c->id]);
        $this->ticket($c, 40);
        $this->assertSame(0, Artisan::call('outage:shadow-digest', ['--dry-run' => true]));
        $this->assertNull($s->fresh()->matched_ticket_id, 'dry-run must not write matched_*');

        Artisan::call('outage:shadow-report', ['--json' => true, '--no-match' => true, '--from' => '2026-10-14', '--to' => '2026-10-15']);
        $json = json_decode(trim(Artisan::output()), true);
        $this->assertSame('2026-10-14 00:00 WIB', $json['from']);
    }

    public function test_digest_schedule_is_0742_and_0942_wib_gated_and_clear_of_reserved_minutes(): void
    {
        $events = $this->monitoringEvents();
        $matches = array_values(array_filter($events, fn (Event $e) => str_contains((string) $e->command, 'outage:shadow-digest')));
        $this->assertCount(1, $matches);
        $event = $matches[0];

        $this->assertSame('42 7,9 * * *', $event->expression);
        $this->assertSame('Asia/Jakarta', (string) $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
        $this->assertFalse($event->filtersPass($this->app), 'flag off: the schedule filter blocks it');

        // Reserved minutes (stagger contract): :14 :29 :44 :59, :41, :16 :46, and the :x0/:x5 boundaries.
        $cron = new CronExpression($event->expression);
        $fires = [];
        foreach ([7, 9] as $hour) {
            for ($m = 0; $m < 60; $m++) {
                if ($cron->isDue(CarbonImmutable::create(2026, 10, 15, $hour, $m, 0, 'Asia/Jakarta'), 'Asia/Jakarta')) {
                    $fires[] = [$hour, $m];
                }
            }
        }
        $this->assertSame([[7, 42], [9, 42]], $fires);
        foreach ($fires as [$hour, $minute]) {
            $this->assertNotSame(0, $minute % 5, 'never a :x0/:x5 boundary');
            $this->assertNotContains($minute, [14, 29, 44, 59, 41, 16, 46]);
            // The per-minute cap of the stagger contract (max 5 recurring entries) holds at the digest minute.
            $at = CarbonImmutable::create(2026, 10, 15, $hour, $minute, 0, 'Asia/Jakarta');
            $due = array_filter($events, fn (Event $e) => (new CronExpression($e->expression))->isDue($at, 'Asia/Jakarta') && $e->expression !== '* * * * *');
            $this->assertLessThanOrEqual(5, count($due));
        }
    }

    public function test_digest_schedule_filter_passes_only_when_the_flag_is_on(): void
    {
        $event = array_values(array_filter($this->monitoringEvents(), fn (Event $e) => str_contains((string) $e->command, 'outage:shadow-digest')))[0];
        Setting::set('outage_shadow_digest_enabled', '1');
        Setting::flushRequestMemo();
        $this->assertTrue($event->filtersPass($this->app));
    }
}
