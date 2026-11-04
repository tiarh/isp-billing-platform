<?php

namespace Tests\Feature;

use App\Jobs\RunMonitoringCollectorJob;
use App\Models\Customer;
use App\Models\CustomerMigration;
use App\Models\Olt;
use App\Models\OltCollectRun;
use App\Models\OltOnu;
use App\Models\OutageOnuTracker;
use App\Models\OutageSignal;
use App\Models\Package;
use App\Models\PsbDraft;
use App\Models\Router;
use App\Models\Setting;
use App\Models\SupportTicket;
use App\Services\Outage\OutageDetector;
use App\Services\Outage\OutageSettings;
use App\Services\Outage\OutageSettingsWriter;
use App\Services\Outage\OutageShadowEpisodeReadModel;
use App\Services\Outage\OutageShadowNotifier;
use App\Services\Outage\OutageShadowReporter;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Outage RCA-1 (shadow only): suppression age limits, DATA_BLIND, ZTE down_unknown, weak_rx daily cap,
 * report blocks and the OLT 4 schedule. The detector never writes to PSB / migration tables and sends nothing.
 */
#[Group('blocking-core')]
class OutageRcaFoundationTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ fixtures

    private function olt(): Olt
    {
        $olt = Olt::create(['name' => 'OLT RA '.($this->seq + 1), 'code' => 'RCA-'.++$this->seq, 'vendor' => 'zte_c300']);
        config(['outage.olt_ids' => [$olt->id], 'outage.serial_fallback_olt_ids' => [$olt->id]]);

        return $olt;
    }

    private function customer(Olt $olt, string $ref, array $o = []): Customer
    {
        $package = Package::firstOrCreate(['code' => 'RCA-PKG'], ['name' => 'Outage pkg', 'price' => 100000]);
        $n = ++$this->seq;

        return Customer::create(array_merge([
            'code' => 'RCA-C'.$n,
            'name' => 'Outage Customer '.$n,
            'phone' => '0812000'.$n,
            'address' => 'Jl. Contoh',
            'pppoe_user' => 'rca.'.$n,
            'package_id' => $package->id,
            'status' => 'active',
            'olt_id' => $olt->id,
            'olt_port_label' => $ref,
        ], $o));
    }

    private function router(array $o = []): Router
    {
        return Router::create($o + [
            'name' => 'R'.++$this->seq,
            'ip_address' => '198.18.9.'.$this->seq,
            'username' => 'admin',
            'password' => 'canary-pw',
            'port' => 8728,
            'is_active' => true,
        ]);
    }

    private function at(int $minutes): Carbon
    {
        return Carbon::parse($this->base.' UTC')->addMinutes($minutes);
    }

    /**
     * One collector snapshot + one detector run.
     *
     * @param  array<string,string>  $phases  ref => online|los|offline
     * @param  array<string,float>  $rx  ref => dBm
     * @param  array<string,string>  $ppp  ref => up|down
     * @return array<string,mixed>
     */
    private function tick(Olt $olt, int $minutes, array $phases, array $rx = [], array $ppp = [], bool $collectorRun = true, bool $run = true): array
    {
        $t = $this->at($minutes);
        Carbon::setTestNow($t);

        foreach ($phases as $ref => $p) {
            [$state, $phase] = match ($p) {
                'online' => ['online', 'working'],
                'los' => ['offline', 'LOS'],
                default => ['offline', 'OffLine'],
            };
            OltOnu::updateOrCreate(
                ['olt_id' => $olt->id, 'onu_ref' => $ref],
                [
                    'state' => $state,
                    'phase_state' => $phase,
                    'serial_number' => 'ZTEG'.md5($ref),
                    'distance_m' => 1000,
                    'state_collected_at' => $t,
                    'inventory_collected_at' => $t,
                    'phase_collected_at' => null,
                    'rx_power_dbm' => $rx[$ref] ?? null,
                    'rx_collected_at' => isset($rx[$ref]) ? $t : null,
                ],
            );
        }
        foreach ($ppp as $ref => $state) {
            DB::table('customers')->where('olt_id', $olt->id)->where('olt_port_label', $ref)->update(
                $state === 'up'
                    ? ['is_online' => 1, 'pppoe_online_checked_at' => $t]
                    : ['is_online' => 0, 'pppoe_online_checked_at' => $t],
            );
        }
        if ($collectorRun) {
            OltCollectRun::create(['olt_id' => $olt->id, 'tier' => 'state', 'status' => 'success', 'started_at' => $t, 'finished_at' => $t]);
        }

        return $run ? app(OutageDetector::class)->run(null, false) : [];
    }

    private function single(Customer $c): ?OutageSignal
    {
        return OutageSignal::query()->where('signal_type', 'single_los')->where('customer_id', $c->id)->first();
    }

    // ------------------------------------------------- 1. suppression age limits

    public function test_psb_draft_only_suppresses_when_updated_within_three_days(): void
    {
        $olt = $this->olt();
        $old = $this->customer($olt, '1/1/1:1');
        $fresh = $this->customer($olt, '1/1/1:2');
        $oldDraft = PsbDraft::create(['status' => 'olt_ok', 'message_id' => 'rca-psb-old', 'customer_id' => $old->id, 'full_name' => 'x']);
        $freshDraft = PsbDraft::create(['status' => 'olt_ok', 'message_id' => 'rca-psb-fresh', 'customer_id' => $fresh->id, 'full_name' => 'x']);
        DB::table('psb_drafts')->where('id', $oldDraft->id)->update(['updated_at' => $this->at(0)->subDays(5)]);
        DB::table('psb_drafts')->where('id', $freshDraft->id)->update(['updated_at' => $this->at(0)->subDay()]);

        $this->tick($olt, 0, ['1/1/1:1' => 'online', '1/1/1:2' => 'online']);
        foreach (range(5, 40, 5) as $m) {
            $this->tick($olt, $m, ['1/1/1:1' => 'los', '1/1/1:2' => 'los']);
        }

        $this->assertSame(OutageSignal::STATE_CONFIRMED, $this->single($old)->state, 'a 5 day old olt_ok draft no longer blinds the customer');
        $this->assertNull($this->single($old)->suppressed_reason);
        $this->assertSame(OutageSignal::STATE_OPEN, $this->single($fresh)->state);
        $this->assertSame('psb', $this->single($fresh)->suppressed_reason);
        // read-only towards PSB: the draft rows are exactly as the test left them
        $this->assertSame('olt_ok', $oldDraft->fresh()->status);
        $this->assertSame('olt_ok', $freshDraft->fresh()->status);
        $this->assertSame(0, (int) DB::table('psb_drafts')->where('id', $oldDraft->id)->where('updated_at', '>', $this->at(0)->subDays(4))->count(), 'the detector never touches psb_drafts');
    }

    public function test_open_migration_only_suppresses_when_updated_within_seven_days(): void
    {
        $olt = $this->olt();
        $old = $this->customer($olt, '1/1/1:1');
        $fresh = $this->customer($olt, '1/1/1:2');
        $mOld = CustomerMigration::create(['status' => 'received', 'customer_id' => $old->id, 'full_name' => 'x', 'pppoe_user' => 'mig.old', 'new_sn' => 'FHTTOLD1']);
        $mFresh = CustomerMigration::create(['status' => 'received', 'customer_id' => $fresh->id, 'full_name' => 'x', 'pppoe_user' => 'mig.fresh', 'new_sn' => 'FHTTNEW1']);
        DB::table('customer_migrations')->where('id', $mOld->id)->update(['updated_at' => $this->at(0)->subDays(10)]);
        DB::table('customer_migrations')->where('id', $mFresh->id)->update(['updated_at' => $this->at(0)->subDays(2)]);

        $this->tick($olt, 0, ['1/1/1:1' => 'online', '1/1/1:2' => 'online']);
        foreach (range(5, 40, 5) as $m) {
            $this->tick($olt, $m, ['1/1/1:1' => 'los', '1/1/1:2' => 'los']);
        }

        $this->assertSame(OutageSignal::STATE_CONFIRMED, $this->single($old)->state);
        $this->assertSame('migration', $this->single($fresh)->suppressed_reason);
        $this->assertSame('received', $mOld->fresh()->status);
        $this->assertSame('received', $mFresh->fresh()->status);
    }

    public function test_age_limits_are_settings(): void
    {
        $this->assertSame(3, OutageSettings::psbSuppressDays());
        $this->assertSame(7, OutageSettings::migrationSuppressDays());

        $writer = new OutageSettingsWriter;
        $this->assertTrue($writer->validate('outage_psb_suppress_days', '10')['ok']);
        $this->assertTrue($writer->validate('outage_migration_suppress_days', '14')['ok']);
        $this->assertFalse($writer->validate('outage_psb_suppress_days', '0')['ok']);
        $this->assertTrue($writer->validate('outage_weak_rx_max_per_day', '0')['ok']);
        $this->assertTrue($writer->validate('outage_data_blind_enabled', '0')['ok']);
        $this->assertTrue($writer->validate('outage_down_unknown_enabled', '1')['ok']);

        Setting::set('outage_psb_suppress_days', '10');
        Setting::flushRequestMemo();
        $this->assertSame(10, OutageSettings::psbSuppressDays());
        $this->assertSame(10, OutageSettings::snapshot()['psb_suppress_days']);
    }

    public function test_suppression_counts_are_reported(): void
    {
        $olt = $this->olt();
        $a = $this->customer($olt, '1/1/1:1');
        $b = $this->customer($olt, '1/1/1:2');
        Carbon::setTestNow($this->at(0));
        $d1 = PsbDraft::create(['status' => 'olt_ok', 'message_id' => 'rca-r-1', 'customer_id' => $a->id, 'full_name' => 'x']);
        PsbDraft::create(['status' => 'olt_ok', 'message_id' => 'rca-r-2', 'customer_id' => $b->id, 'full_name' => 'x']);
        DB::table('psb_drafts')->where('id', $d1->id)->update(['updated_at' => $this->at(0)->subDays(9)]);

        $s = (new OutageShadowReporter)->suppressionStats();

        $this->assertSame(2, $s['psb_customers_open']);
        $this->assertSame(1, $s['psb_customers_suppressing']);
        $this->assertSame(1, $s['psb_customers_aged_out']);
        $this->assertSame(3, $s['psb_days']);
        $this->assertSame(7, $s['migration_days']);
    }

    // ------------------------------------------------------------ 3. DATA_BLIND

    public function test_stale_olt_collector_records_data_blind_instead_of_an_outage_and_resolves_when_fresh(): void
    {
        $olt = $this->olt();
        $c = $this->customer($olt, '1/1/1:1');
        $this->tick($olt, 0, ['1/1/1:1' => 'online']);

        // 30 minutes without a collector run: the data is blind, not the network.
        Carbon::setTestNow($this->at(30));
        $summary = app(OutageDetector::class)->run(null, false);

        $blind = OutageSignal::query()->where('signal_type', 'data_blind')->firstOrFail();
        $this->assertSame('olt:'.$olt->id.':blind', $blind->scope_key);
        $this->assertSame('olt', $blind->payload['kind']);
        $this->assertSame('collector_stale', $blind->payload['reason']);
        $this->assertSame('none', $blind->would_action);
        $this->assertSame(OutageSignal::STATE_OPEN, $blind->state);
        $this->assertSame(1, $summary['data_blind']['olt']);
        $this->assertNull($this->single($c));
        $this->assertSame('stale', $summary['olts'][$olt->id]['status']);

        // collector failed
        OltCollectRun::create(['olt_id' => $olt->id, 'tier' => 'state', 'status' => 'failed', 'started_at' => $this->at(30), 'finished_at' => $this->at(30)]);
        app(OutageDetector::class)->run(null, false);
        $this->assertSame('collector_failed', $blind->fresh()->payload['reason']);
        $this->assertSame(1, OutageSignal::query()->where('signal_type', 'data_blind')->count());

        // fresh data again
        $this->tick($olt, 35, ['1/1/1:1' => 'online']);
        $this->assertSame(OutageSignal::STATE_RESOLVED, $blind->fresh()->state);
    }

    public function test_data_blind_can_be_switched_off(): void
    {
        $olt = $this->olt();
        $this->customer($olt, '1/1/1:1');
        $this->tick($olt, 0, ['1/1/1:1' => 'online']);
        Setting::set('outage_data_blind_enabled', '0');
        Setting::flushRequestMemo();

        Carbon::setTestNow($this->at(30));
        app(OutageDetector::class)->run(null, false);

        $this->assertSame(0, OutageSignal::query()->where('signal_type', 'data_blind')->count());
    }

    public function test_blind_router_never_opens_pppoe_down_and_is_recorded(): void
    {
        $olt = $this->olt();
        $router = $this->router(['connection_status' => 'offline']);
        $c = $this->customer($olt, '1/1/1:1', ['router_id' => $router->id]);

        foreach (range(0, 20, 5) as $m) {
            $this->tick($olt, $m, ['1/1/1:1' => 'online'], [], ['1/1/1:1' => 'down']);
        }

        $this->assertSame(0, OutageSignal::query()->where('signal_type', 'pppoe_down')->count(), 'router unreadable: PPPoE "down" is not evidence');
        $blind = OutageSignal::query()->where('signal_type', 'data_blind')->where('scope_key', 'router:'.$router->id.':blind')->firstOrFail();
        $this->assertSame('router', $blind->payload['kind']);
        $this->assertSame('router_offline', $blind->payload['reason']);
        $this->assertSame(1, $blind->payload['pppoe_customers_unknown']);

        // router readable again: the normal PPPoE rule applies and the blind episode closes
        $router->update(['connection_status' => 'online']);
        foreach (range(25, 45, 5) as $m) {
            $this->tick($olt, $m, ['1/1/1:1' => 'online'], [], ['1/1/1:1' => 'down']);
        }
        $this->assertSame(OutageSignal::STATE_RESOLVED, $blind->fresh()->state);
        $this->assertSame(1, OutageSignal::query()->where('signal_type', 'pppoe_down')->where('customer_id', $c->id)->count());
    }

    public function test_router_with_an_overdue_health_check_is_blind(): void
    {
        $olt = $this->olt();
        $router = $this->router(['connection_status' => 'online']);
        DB::table('routers')->where('id', $router->id)->update(['last_health_check_at' => $this->at(0)->subHours(5)]);
        $this->customer($olt, '1/1/1:1', ['router_id' => $router->id]);

        $this->tick($olt, 0, ['1/1/1:1' => 'online'], [], ['1/1/1:1' => 'up']);

        $blind = OutageSignal::query()->where('signal_type', 'data_blind')->firstOrFail();
        $this->assertSame('health_stale', $blind->payload['reason']);
    }

    // ------------------------------------------------------ 4. ZTE down_unknown

    public function test_zte_offline_without_a_cause_for_30_minutes_is_a_down_unknown_single_in_shadow(): void
    {
        $olt = $this->olt();
        $c = $this->customer($olt, '1/1/1:1');

        $this->tick($olt, 0, ['1/1/1:1' => 'online']);
        foreach (range(5, 30, 5) as $m) {
            $this->tick($olt, $m, ['1/1/1:1' => 'offline']);
        }
        $this->assertSame(OutageSignal::STATE_OPEN, $this->single($c)->state, '25 minutes down is not enough');

        $summary = $this->tick($olt, 35, ['1/1/1:1' => 'offline']);

        $sig = $this->single($c);
        $this->assertSame(OutageSignal::STATE_CONFIRMED, $sig->state);
        $this->assertSame('down_unknown', $sig->payload['cause']);
        $this->assertSame('ticket_single', $sig->would_action);
        $this->assertSame('shadow', $sig->mode_at_confirm);
        $this->assertSame('none', $sig->action_taken);
        $this->assertSame(['down_unknown_minutes' => 30], $sig->payload['thresholds']);
        $this->assertTrue($summary['new_confirmed'][0]['cause_unknown']);

        // it never feeds the whole-PON LOS statistics
        $this->assertSame(0, OutageSignal::query()->where('signal_type', 'mass_los')->count());
    }

    public function test_down_unknown_does_not_create_mass_los_and_real_los_keeps_its_own_cause(): void
    {
        $olt = $this->olt();
        $refs = ['1/1/1:1', '1/1/1:2', '1/1/1:3', '1/1/1:4'];
        foreach ($refs as $r) {
            $this->customer($olt, $r);
        }
        $this->tick($olt, 0, array_fill_keys($refs, 'online'));
        foreach (range(5, 40, 5) as $m) {
            $this->tick($olt, $m, array_fill_keys($refs, 'offline'));
        }
        $this->assertSame(0, OutageSignal::query()->where('signal_type', 'mass_los')->count());
        $this->assertSame(4, OutageSignal::query()->where('signal_type', 'single_los')->where('state', OutageSignal::STATE_CONFIRMED)->count());

        // an explicit LOS ONU stays a LOS (cause = los, 30 minute rule)
        $olt2 = $this->olt();
        $c = $this->customer($olt2, '1/2/1:1');
        $this->tick($olt2, 0, ['1/2/1:1' => 'online']);
        foreach (range(5, 35, 5) as $m) {
            $this->tick($olt2, $m, ['1/2/1:1' => 'los']);
        }
        $this->assertSame('los', $this->single($c)->payload['cause']);
        $this->assertSame(OutageSignal::STATE_CONFIRMED, $this->single($c)->state);
    }

    public function test_down_unknown_can_be_switched_off(): void
    {
        Setting::set('outage_down_unknown_enabled', '0');
        Setting::flushRequestMemo();
        $olt = $this->olt();
        $c = $this->customer($olt, '1/1/1:1');
        $this->tick($olt, 0, ['1/1/1:1' => 'online']);
        foreach (range(5, 45, 5) as $m) {
            $this->tick($olt, $m, ['1/1/1:1' => 'offline']);
        }

        $this->assertNull($this->single($c));
    }

    public function test_report_keeps_down_unknown_out_of_the_los_numbers(): void
    {
        $olt = $this->olt();
        $los = $this->customer($olt, '1/1/1:1');
        $unk = $this->customer($olt, '1/1/1:2');
        $this->tick($olt, 0, ['1/1/1:1' => 'online', '1/1/1:2' => 'online']);
        foreach (range(5, 40, 5) as $m) {
            $this->tick($olt, $m, ['1/1/1:1' => 'los', '1/1/1:2' => 'offline']);
        }

        $report = (new OutageShadowReporter)->build($this->at(-60), $this->at(60));

        $this->assertSame(1, $report['signals']['single_los']['confirmed']);
        $this->assertSame(1, $report['signals']['down_unknown']['confirmed']);
        $this->assertSame(1, $report['signals']['down_unknown']['would_ticket']);
        $this->assertNotNull($this->single($los));
        $this->assertNotNull($this->single($unk));
    }

    // ------------------------------------------------------- 5. weak RX no burst

    /** Three days x 3 hourly samples for every ref; median per ref = $mids[ref]. */
    private function weakDays(Olt $olt, array $mids): void
    {
        $online = array_fill_keys(array_keys($mids), 'online');
        $this->tick($olt, 0, $online);
        foreach ([0, 1, 2] as $day) {
            foreach ([0, 60, 120] as $off) {
                $rx = [];
                foreach ($mids as $ref => $mid) {
                    $rx[$ref] = $mid + ($off === 0 ? -0.2 : ($off === 120 ? 0.2 : 0.0));
                }
                $this->tick($olt, $day * 1440 + $off, $online, $rx);
            }
        }
    }

    public function test_weak_rx_confirms_at_most_n_per_day_worst_first_and_the_rest_is_a_weekly_list(): void
    {
        Setting::set('outage_weak_rx_max_per_day', '2');
        Setting::flushRequestMemo();
        $olt = $this->olt();
        $mids = ['1/1/1:1' => -28.0, '1/1/1:2' => -31.0, '1/1/1:3' => -29.5, '1/1/1:4' => -28.3];
        $customers = [];
        foreach ($mids as $ref => $mid) {
            $customers[$ref] = $this->customer($olt, $ref);
        }

        $this->weakDays($olt, $mids);

        $by = fn (string $ref) => OutageSignal::query()->where('signal_type', 'weak_rx')->where('customer_id', $customers[$ref]->id)->firstOrFail();
        $this->assertSame('ticket_maintenance', $by('1/1/1:2')->would_action, '-31 dBm is the worst');
        $this->assertSame('ticket_maintenance', $by('1/1/1:3')->would_action);
        $this->assertSame('weekly_list', $by('1/1/1:1')->would_action);
        $this->assertSame('weekly_list', $by('1/1/1:4')->would_action);
        $this->assertSame(OutageSignal::STATE_CONFIRMED, $by('1/1/1:1')->state);
        $this->assertSame(2, OutageSignal::query()->where('would_action', 'ticket_maintenance')->count());

        $weekly = (new OutageShadowReporter)->weakRxWeekly();
        $this->assertSame(2, $weekly['count']);
        $this->assertSame(2, $weekly['max_per_day']);
        $this->assertEqualsWithDelta(-28.3, $weekly['list'][0]['dbm'], 0.3, 'worst of the weekly list first');
        $report = (new OutageShadowReporter)->build($this->at(-60), $this->at(3 * 1440));
        $this->assertSame(2, $report['signals']['weak_rx']['would_ticket'], 'weekly_list entries are not would-be tickets');
        $this->assertSame(2, $report['weak_rx_weekly']['count']);
    }

    public function test_weak_rx_cap_is_shared_across_runs_of_the_same_day(): void
    {
        Setting::set('outage_weak_rx_max_per_day', '1');
        Setting::flushRequestMemo();
        $olt = $this->olt();
        $this->customer($olt, '1/1/1:1');
        $this->customer($olt, '1/1/1:2');

        $this->weakDays($olt, ['1/1/1:1' => -29.0, '1/1/1:2' => -30.0]);

        $this->assertSame(1, OutageSignal::query()->where('would_action', 'ticket_maintenance')->count());
        $this->assertSame(1, OutageSignal::query()->where('would_action', 'weekly_list')->count());
    }

    public function test_weekly_list_drains_into_the_daily_cap_over_two_days_and_never_expires(): void
    {
        $olt = $this->olt();
        $mids = [];
        for ($i = 1; $i <= 15; $i++) {
            $ref = '1/1/'.$i.':1';
            $this->customer($olt, $ref);
            $mids[$ref] = -28.0 - $i * 0.1; // 15 weak ONUs, ref 15 is the worst
        }
        $this->weakDays($olt, $mids); // day 2 (WIB): 10 worst promoted, 5 on the list

        $this->assertSame(10, OutageSignal::query()->where('would_action', 'ticket_maintenance')->count());
        $this->assertSame(5, OutageSignal::query()->where('would_action', 'weekly_list')->count());

        // next WIB day: the 5 still-weak list entries use the new day's room
        $online = array_fill_keys(array_keys($mids), 'online');
        $this->tick($olt, 3 * 1440, $online, $mids);

        $this->assertSame(15, OutageSignal::query()->where('would_action', 'ticket_maintenance')->count());
        $this->assertSame(0, OutageSignal::query()->where('would_action', 'weekly_list')->count());
        $promoted = OutageSignal::query()->where('would_action', 'ticket_maintenance')->get()->filter(fn ($s) => isset($s->payload['promoted_at']));
        $this->assertCount(5, $promoted);
    }

    public function test_weekly_list_keeps_every_unresolved_entry_after_more_than_seven_days(): void
    {
        Setting::set('outage_weak_rx_max_per_day', '2');
        Setting::flushRequestMemo();
        $olt = $this->olt();
        $mids = [];
        for ($i = 1; $i <= 5; $i++) {
            $ref = '1/1/'.$i.':1';
            $this->customer($olt, $ref);
            $mids[$ref] = -28.0 - $i * 0.1;
        }
        $this->weakDays($olt, $mids);
        $this->assertSame(3, OutageSignal::query()->where('would_action', 'weekly_list')->count());

        Carbon::setTestNow($this->at(20 * 1440));
        $weekly = (new OutageShadowReporter)->weakRxWeekly();

        $this->assertSame(3, $weekly['count'], 'still weak and unresolved: not dropped after 7 days');
    }

    public function test_weekly_list_entry_with_stale_data_is_not_promoted_but_a_fresh_one_is(): void
    {
        Setting::set('outage_weak_rx_max_per_day', '2');
        Setting::flushRequestMemo();
        $olt = $this->olt();
        $mids = [];
        $customers = [];
        for ($i = 1; $i <= 4; $i++) {
            $ref = '1/1/'.$i.':1';
            $customers[$ref] = $this->customer($olt, $ref);
            $mids[$ref] = -28.0 - $i * 0.1;
        }
        $this->weakDays($olt, $mids); // 2 promoted, 2 on the list
        $listed = OutageSignal::query()->where('would_action', 'weekly_list')->orderBy('id')->get();
        $this->assertCount(2, $listed);
        [$fresh, $stale] = [$listed[0], $listed[1]];

        // Day 5: readings are 2+ days old (frozen), so the detector itself does not touch the entries.
        $now = $this->at(4 * 1440);
        $fresh->forceFill(['last_seen_at' => $now->copy()->subMinutes(60)])->save();
        $stale->forceFill(['last_seen_at' => $now->copy()->subDays(2)])->save();
        $this->tick($olt, 4 * 1440, array_fill_keys(array_keys($mids), 'online'));

        $fresh->refresh();
        $stale->refresh();
        $this->assertSame('ticket_maintenance', $fresh->would_action);
        $this->assertArrayHasKey('promoted_at', (array) $fresh->payload);
        $this->assertSame('weekly_list', $stale->would_action, 'stale data is never promoted');
        $this->assertArrayNotHasKey('promoted_at', (array) $stale->payload);
    }

    public function test_weak_rx_cap_is_ranked_across_olts_per_day(): void
    {
        Setting::set('outage_weak_rx_max_per_day', '1');
        Setting::flushRequestMemo();
        $a = $this->olt();
        $b = Olt::create(['name' => 'OLT B', 'code' => 'RCA-B', 'vendor' => 'zte_c300']);
        config(['outage.olt_ids' => [$a->id, $b->id], 'outage.serial_fallback_olt_ids' => [$a->id, $b->id]]);
        $ca = $this->customer($a, '1/1/1:1');
        $cb = $this->customer($b, '1/1/1:1');

        foreach ([0, 1, 2] as $day) {
            foreach ([0, 60, 120] as $off) {
                $m = $day * 1440 + $off;
                $this->tick($a, $m, ['1/1/1:1' => 'online'], ['1/1/1:1' => -28.0], [], true, false);
                $this->tick($b, $m, ['1/1/1:1' => 'online'], ['1/1/1:1' => -31.0], [], true, false);
                Carbon::setTestNow($this->at($m));
                app(OutageDetector::class)->run(null, false);
            }
        }

        $of = fn (Customer $c) => OutageSignal::query()->where('signal_type', 'weak_rx')->where('customer_id', $c->id)->firstOrFail()->would_action;
        $this->assertSame('ticket_maintenance', $of($cb), 'the worst ONU of ANY OLT gets the only slot');
        $this->assertSame('weekly_list', $of($ca));
    }

    // ------------------------------------------- down_unknown must be baseline for old offline ONUs

    public function test_already_offline_trackers_never_confirm_as_down_unknown_after_deploy(): void
    {
        $olt = $this->olt();
        $old = $this->customer($olt, '1/1/1:1');
        $nullSince = $this->customer($olt, '1/1/1:2');
        foreach ([[$old, '1/1/1:1', $this->at(0)->subDays(3)], [$nullSince, '1/1/1:2', null]] as [$c, $ref, $since]) {
            OutageOnuTracker::query()->create([
                'olt_id' => $olt->id, 'onu_ref' => $ref, 'customer_id' => $c->id, 'phase' => 'offline',
                'phase_since_at' => $since, 'last_state_collected_at' => $this->at(0)->subMinutes(5),
            ]);
        }

        $confirmed = 0;
        foreach (range(0, 60, 5) as $m) {
            $summary = $this->tick($olt, $m, ['1/1/1:1' => 'offline', '1/1/1:2' => 'offline']);
            $confirmed += count($summary['new_confirmed']);
        }

        $this->assertSame(0, $confirmed);
        $this->assertSame(0, OutageSignal::query()->whereNotNull('confirmed_at')->count());
        $this->assertSame(2, OutageSignal::query()->where('signal_type', 'single_los')->where('suppressed_reason', 'baseline')->count());
    }

    // ------------------------------------------------------- wording, filters, reports

    public function test_wa_line_for_down_unknown_says_offline_tanpa_sebab(): void
    {
        $olt = $this->olt();
        $c = $this->customer($olt, '1/1/1:1');
        $entry = ['id' => 1, 'type' => 'single_los', 'olt_id' => $olt->id, 'olt_name' => 'OLT RA', 'pon_port' => '1/1/1', 'customer_id' => $c->id,
            'affected_count' => 1, 'would_action' => 'ticket_single', 'cause_unknown' => true, 'cause' => 'down_unknown'];
        $customers = ['c' => [$c->id => ['code' => $c->code, 'name' => $c->name, 'pppoe_user' => $c->pppoe_user]], 'rx' => []];

        $text = (new OutageShadowNotifier)->message([$entry], 0, $customers, true);
        $this->assertStringContainsString('Offline tanpa sebab', $text);
        $this->assertStringNotContainsString('LOS individual', $text);

        $los = (new OutageShadowNotifier)->message([['cause' => 'los'] + $entry], 0, $customers, true);
        $this->assertStringContainsString('LOS individual', $los);
    }

    public function test_panel_filters_split_down_unknown_from_los_including_rows_without_a_cause(): void
    {
        $olt = $this->olt();
        $mk = fn (string $scope, ?array $payload) => OutageSignal::query()->create([
            'signal_type' => 'single_los', 'scope_key' => $scope, 'olt_id' => $olt->id, 'state' => 'open',
            'opened_at' => now()->subHour(), 'payload' => $payload,
        ])->id;
        $unknown = $mk('t:1', ['cause' => 'down_unknown']);
        $los = $mk('t:2', ['cause' => 'los']);
        $huawei = $mk('t:3', ['family' => 'huawei', 'cause_unknown' => true]);
        $legacy = $mk('t:4', null);
        $blind = OutageSignal::query()->create(['signal_type' => 'data_blind', 'scope_key' => 'olt:'.$olt->id.':blind', 'olt_id' => $olt->id,
            'state' => 'open', 'opened_at' => now()->subHour(), 'payload' => ['kind' => 'olt', 'reason' => 'collector_stale']])->id;

        $ids = fn (string $type) => collect((new OutageShadowEpisodeReadModel)->page(OutageShadowEpisodeReadModel::normalizeFilters(['type' => $type]))['data'])->pluck('id')->sort()->values()->all();

        $this->assertSame([$unknown], $ids('down_unknown'));
        $this->assertSame([$los, $huawei, $legacy], $ids('single_los'));
        $this->assertSame([$blind], $ids('data_blind'));
        $row = collect((new OutageShadowEpisodeReadModel)->page(OutageShadowEpisodeReadModel::normalizeFilters(['type' => 'data_blind']))['data'])->first();
        $this->assertSame('Data tidak terbaca', $row['type_label']);
        $this->assertSame('Data OLT lama', $row['detail']);
    }

    public function test_report_counts_incident_closes_without_a_cause_as_auto_closed(): void
    {
        Carbon::setTestNow($this->at(0));
        $mk = fn (array $o) => SupportTicket::query()->create($o + ['gangguan' => 'x', 'status' => 'closed', 'priority' => 'normal', 'type' => 'incident', 'resolved_at' => now()]);
        $mk(['root_cause' => 'drop_los']);
        $mk([]);
        $mk(['type' => 'billing']);

        $c = (new OutageShadowReporter)->incidentCloses($this->at(-60), $this->at(60));

        $this->assertSame(['with_cause' => 1, 'auto_closed' => 1], $c);
    }

    // ------------------------------------------------------- 2. OLT 4 schedule

    /** @return list<Event> */
    private function monitoringModeEvents(): array
    {
        config(['services.ebilling_scheduler.mode' => 'monitoring']);
        $schedule = new Schedule;
        $this->app->instance(Schedule::class, $schedule);
        ScheduleFacade::clearResolvedInstance(Schedule::class);
        require base_path('routes/console.php');

        return $schedule->events();
    }

    public function test_monitoring_schedule_collects_olt_4_and_no_longer_references_olt_3(): void
    {
        $events = $this->monitoringModeEvents();
        $byDescription = [];
        foreach ($events as $e) {
            $byDescription[(string) $e->description] = $e->expression;
        }

        $this->assertSame('6,21,36,51 * * * *', $byDescription['Monitoring OLT RX: HiOSO Srigading'] ?? null);
        $this->assertSame('22 * * * *', $byDescription['Monitoring OLT inventory: HiOSO Srigading'] ?? null);
        foreach (array_keys($byDescription) as $description) {
            $this->assertStringNotContainsString('HiOSO Gadang', $description);
        }

        // L4: an explicit short timeout so OLT 4 can never hold the shared olt lane for 600 s
        Queue::fake();
        foreach ($events as $e) {
            if (in_array((string) $e->description, ['Monitoring OLT RX: HiOSO Srigading', 'Monitoring OLT inventory: HiOSO Srigading'], true)) {
                $e->run($this->app);
            }
        }
        Queue::assertPushed(RunMonitoringCollectorJob::class, fn (RunMonitoringCollectorJob $j) => $j->target === 'olt-rx-hioso-4' && $j->timeout === 240);
        Queue::assertPushed(RunMonitoringCollectorJob::class, fn (RunMonitoringCollectorJob $j) => $j->target === 'olt-inv-hioso-4' && $j->timeout === 240);

        // same cadence as the other HiOSO entries
        $this->assertSame(
            substr_count((string) $byDescription['Monitoring OLT RX: HiOSO Mangliawan'], ','),
            substr_count((string) $byDescription['Monitoring OLT RX: HiOSO Srigading'], ','),
        );
    }

    // ----------------------------------------------------- safety / read-only

    public function test_detector_changes_stay_read_only_towards_psb_and_migration_and_send_nothing(): void
    {
        $source = (string) file_get_contents(app_path('Services/Outage/OutageDetector.php'))
            .(string) file_get_contents(app_path('Services/Outage/DetectsWeakRxAndPppoe.php'))
            .(string) file_get_contents(app_path('Services/Outage/OutageShadowReporter.php'));

        // no write call on the PSB / migration models anywhere in the outage services
        $this->assertDoesNotMatchRegularExpression('/(PsbDraft|CustomerMigration)::query\(\)[^;]*->(update|delete|insert|create|save)\(/s', $source);
        $this->assertStringNotContainsString('Evolution', $source);
        $this->assertStringNotContainsString('sendToGroup', $source);
    }
}
