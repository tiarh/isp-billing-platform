<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Outage\Diagnosis\Backtest\BacktestRunner;
use App\Services\Outage\Diagnosis\Backtest\SnapshotHydrator;
use App\Services\Outage\Diagnosis\DiagnosisContextSnapshot;
use App\Services\Outage\Diagnosis\OutageDiagnoser;
use App\Services\Outage\Diagnosis\Rules;
use App\Services\Outage\OutageSettings;
use App\Services\Outage\OutageSettingsWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\DiagnosisSyntheticContexts;
use Tests\TestCase;

/**
 * PR-D evidence lines and the traffic guard on hand-built contexts: every flag defaults OFF and then changes nothing;
 * with a flag on only evidence (and "Bisa juga") is added, except the traffic guard, which turns a hard answer the
 * traffic contradicts into "unknown" (never "not a fault").
 */
#[Group('blocking-core')]
class DiagnosisMoreEvidenceTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $now;

    private const FLAGS_ON = ['diagnosis_session_burst_enabled' => '1', 'diagnosis_area_power_enabled' => '1', 'diagnosis_traffic_guard_enabled' => '1'];

    protected function setUp(): void
    {
        parent::setUp();
        Setting::flushRequestMemo();
        $this->now = Carbon::parse('2026-10-28 03:10:00', 'UTC');
        Carbon::setTestNow($this->now);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function classify(array $ctx, array $flags = []): array
    {
        return OutageSettings::withOverrides($flags, fn () => app(OutageDiagnoser::class)->classify($ctx));
    }

    private function ago(int $minutes): Carbon
    {
        return $this->now->copy()->subMinutes($minutes);
    }

    /** The ONU has been in LOS for 90 minutes: a hard drop_los answer. */
    private function losContext(array $extra = []): array
    {
        $o = ['phase' => 'los', 'since' => $this->ago(90), 'cause' => 'los', 'cause_fresh' => true, 'cause_at' => $this->now, 'cause_source' => 'olt_poll'];

        return array_replace_recursive(DiagnosisSyntheticContexts::base($this->now), [
            'onu' => $o, 'pppoe' => ['online' => false],
            'history' => ['onu_drops' => [['at' => $this->ago(90), 'cause' => 'los', 'source' => 'olt_poll', 'area' => false]]],
        ], $extra);
    }

    private function texts(array $result): array
    {
        return array_column($result['evidence'], 'text');
    }

    public function test_the_three_flags_default_off_and_the_data_then_changes_nothing(): void
    {
        $snapshot = OutageSettings::diagnosisSnapshot();
        foreach (['diagnosis_session_burst_enabled', 'diagnosis_area_power_enabled', 'diagnosis_traffic_guard_enabled'] as $key) {
            $this->assertFalse($snapshot[$key], $key);
            $this->assertContains($key, OutageSettingsWriter::keys());
        }
        $this->assertSame(5, $snapshot['area_power_min_onus']);

        $plain = $this->classify($this->losContext());
        $withData = $this->classify($this->losContext([
            'pppoe' => ['burst' => ['at' => $this->ago(30), 'count' => 21, 'onu_down_share' => 0.0, 'pon' => '1/2/3', 'signal_id' => 1]],
            'area_power' => ['at' => $this->ago(30), 'count' => 12, 'area' => 'Area Uji', 'signal_id' => 2],
            'traffic' => ['at' => $this->ago(2), 'download_bps' => 3_200_000, 'upload_bps' => 100_000],
        ]));

        $this->assertSame($plain, $withData);
    }

    public function test_the_command_is_scheduled_in_the_background_without_overlap_and_never_on_a_queue(): void
    {
        $source = (string) file_get_contents(base_path('routes/console.php'));
        $pos = strpos($source, "Schedule::command('outage:session-bursts')");
        $this->assertNotFalse($pos);
        $block = substr($source, $pos, 560);
        foreach (["cron('1-59/10 * * * *')", 'withoutOverlapping(', 'onOneServer()', 'runInBackground()', "timezone('Asia/Jakarta')", 'OutageScheduleGate::shouldRun()'] as $needle) {
            $this->assertStringContainsString($needle, $block, $needle);
        }
        $this->assertStringNotContainsString('->job(', $block);
    }

    public function test_the_area_power_setting_is_validated(): void
    {
        $writer = new OutageSettingsWriter;
        $this->assertTrue($writer->validate('area_power_min_onus', '6')['ok']);
        $this->assertFalse($writer->validate('area_power_min_onus', '2')['ok']);
        $this->assertFalse($writer->validate('area_power_min_onus', 'x')['ok']);
    }

    public function test_the_burst_line_shows_when_the_onus_stayed_online_and_only_adds_a_bisa_juga(): void
    {
        $ctx = DiagnosisSyntheticContexts::base($this->now);
        $ctx['pppoe']['online'] = false;
        $ctx['pppoe']['burst'] = ['at' => $this->ago(40), 'count' => 21, 'onu_down_share' => 0.0, 'pon' => '1/2/3', 'signal_id' => 1];

        $off = $this->classify($ctx);
        $on = $this->classify($ctx, self::FLAGS_ON);

        $this->assertSame($off['cause_code'], $on['cause_code']);
        $this->assertSame($off['rule_id'], $on['rule_id']);
        $line = 'Jam 09:30, 21 pelanggan putus bersamaan, ONU tetap menyala. Kemungkinan gangguan jalur atau router, bukan di rumah.';
        $this->assertContains($line, $this->texts($on));
        $this->assertNotContains($line, $this->texts($off));
        $this->assertContains('upstream', $on['secondary']);
    }

    public function test_a_burst_where_the_onus_died_too_is_a_pon_event_not_a_session_line(): void
    {
        $ctx = DiagnosisSyntheticContexts::base($this->now);
        $ctx['pppoe']['burst'] = ['at' => $this->ago(40), 'count' => 65, 'onu_down_share' => 0.97, 'pon' => '1/2/3', 'signal_id' => 1];

        $on = $this->classify($ctx, self::FLAGS_ON);

        $this->assertSame([], array_filter($this->texts($on), fn (string $t) => str_contains($t, 'putus bersamaan')));
        $this->assertNotContains('upstream', $on['secondary']);
    }

    public function test_the_area_power_line_names_the_area_and_suggests_asking_about_the_power(): void
    {
        $ctx = $this->losContext(['area_power' => ['at' => $this->ago(45), 'count' => 12, 'area' => 'Area Uji', 'signal_id' => 2]]);

        $on = $this->classify($ctx, self::FLAGS_ON);

        $this->assertSame('drop_los', $on['cause_code'], 'the primary stays');
        $this->assertContains('Kemungkinan listrik padam di area Area Uji (12 ONU mati bersamaan 09:25).', $this->texts($on));
        $this->assertContains('power_off', $on['secondary']);

        $dg = DiagnosisSyntheticContexts::cases($this->now)['onu_dying_gasp_now'][0];
        $dg['area_power'] = ['at' => $this->ago(45), 'count' => 12, 'area' => 'Area Uji', 'signal_id' => 2];
        $r = $this->classify($dg, self::FLAGS_ON);
        $this->assertSame('power_off', $r['cause_code']);
        $this->assertSame('tanya_listrik', $r['suggested_action']);
        $this->assertNotContains('power_off', $r['secondary']);
    }

    public function test_bisa_juga_listrik_mati_only_while_the_onu_is_down_or_the_group_is_open(): void
    {
        $area = ['at' => $this->ago(300), 'count' => 12, 'area' => 'Area Uji', 'signal_id' => 2];
        $healthy = DiagnosisSyntheticContexts::base($this->now); // ONU online and fresh

        $closed = $this->classify($healthy + ['area_power' => $area + ['open' => false]], self::FLAGS_ON);
        $open = $this->classify($healthy + ['area_power' => $area + ['open' => true]], self::FLAGS_ON);
        $down = $this->classify($this->losContext(['area_power' => $area + ['open' => false]]), self::FLAGS_ON);

        $this->assertNotContains('power_off', $closed['secondary'], 'ONU back, group closed: context line only');
        $this->assertCount(1, array_filter($this->texts($closed), fn (string $t) => str_starts_with($t, 'Kemungkinan listrik padam')));
        $this->assertContains('power_off', $open['secondary']);
        $this->assertContains('power_off', $down['secondary'], 'ONU down now');
    }

    public function test_fresh_traffic_after_the_onu_went_down_turns_a_hard_answer_into_unknown_with_the_cause_kept(): void
    {
        $ctx = $this->losContext(['traffic' => ['at' => $this->ago(3), 'download_bps' => 200_000, 'upload_bps' => 10_000]]);

        $off = $this->classify($ctx);
        $on = $this->classify($ctx, ['diagnosis_traffic_guard_enabled' => '1']);

        $this->assertSame('drop_los', $off['cause_code']);
        $this->assertSame('unknown', $on['cause_code']);
        $this->assertSame('rendah', $on['confidence']);
        $this->assertSame('traffic_guard', $on['rule_id']);
        $this->assertContains('drop_los', $on['secondary']);
        $this->assertSame('Data bertentangan: internet masih jalan (200 kbps, 10:07).', $on['evidence'][0]['text']);
        $this->assertNotContains($on['cause_code'], ['not_fault', 'self_recovered']);
    }

    public function test_the_guard_ignores_stale_rows_old_samples_light_traffic_and_unknown_starts(): void
    {
        $flags = ['diagnosis_traffic_guard_enabled' => '1'];
        $cases = [
            'stale row (35 min)' => ['at' => $this->ago(35), 'download_bps' => 900_000, 'upload_bps' => 0],
            'sample taken before the ONU went down' => ['at' => $this->ago(100), 'download_bps' => 900_000, 'upload_bps' => 0],
            'sample 5 minutes after the start (rate may be from before)' => ['at' => $this->ago(85), 'download_bps' => 900_000, 'upload_bps' => 0],
            'light traffic (30 kbps)' => ['at' => $this->ago(2), 'download_bps' => 30_000, 'upload_bps' => 0],
        ];
        foreach ($cases as $label => $traffic) {
            $r = $this->classify($this->losContext(['traffic' => $traffic]), $flags);
            $this->assertSame('drop_los', $r['cause_code'], $label);
        }

        $unknownStart = $this->losContext(['traffic' => ['at' => $this->ago(2), 'download_bps' => 900_000, 'upload_bps' => 0], 'onu' => ['since' => null]]);
        $this->assertSame('drop_los', $this->classify($unknownStart, $flags)['cause_code'], 'no start, no proof');
    }

    public function test_the_traffic_lines_need_the_flag_and_a_fresh_sample(): void
    {
        $active = DiagnosisSyntheticContexts::base($this->now);
        $active['traffic'] = ['at' => $this->ago(9), 'download_bps' => 3_200_000, 'upload_bps' => 400_000];
        $idle = DiagnosisSyntheticContexts::base($this->now);
        $idle['traffic'] = ['at' => $this->ago(9), 'download_bps' => 2_000, 'upload_bps' => 1_000];
        $stale = DiagnosisSyntheticContexts::base($this->now);
        $stale['traffic'] = ['at' => $this->ago(40), 'download_bps' => 3_200_000, 'upload_bps' => 0];
        $flag = ['diagnosis_traffic_guard_enabled' => '1'];

        $this->assertContains('Internet aktif, pemakaian 3,2 Mbps (10:01).', $this->texts($this->classify($active, $flag)));
        $this->assertContains('Sesi aktif tapi hampir tanpa pemakaian sejak 10:01.', $this->texts($this->classify($idle, $flag)));
        $this->assertSame($this->texts($this->classify($stale)), $this->texts($this->classify($stale, $flag)));
        $this->assertNotContains('Internet aktif, pemakaian 3,2 Mbps (10:01).', $this->texts($this->classify($active)));
    }

    public function test_the_intermittent_fibre_shape_with_a_live_session_is_never_not_a_fault(): void
    {
        // T410: session up for hours, RX -27.2, repeated LOS drops, ONU online now: the answer stays a fibre one
        $ctx = DiagnosisSyntheticContexts::cases($this->now)['two_los_and_weak_rx'][0];
        $ctx['traffic'] = ['at' => $this->ago(4), 'download_bps' => 800_000, 'upload_bps' => 60_000];

        $r = $this->classify($ctx, self::FLAGS_ON);

        $this->assertSame('drop_los', $r['cause_code']);
        $this->assertNotContains($r['cause_code'], ['not_fault', 'self_recovered']);
    }

    public function test_snapshot_v2_round_trips_through_the_hydrator_with_the_new_blocks(): void
    {
        $ctx = $this->losContext([
            'pppoe' => ['burst' => ['at' => $this->ago(30), 'count' => 21, 'onu_down_share' => 0.1, 'pon' => '1/2/3', 'signal_id' => 1]],
            'area_power' => ['at' => $this->ago(45), 'count' => 12, 'area' => 'Area Uji', 'signal_id' => 2],
            'traffic' => ['at' => $this->ago(3), 'download_bps' => 200_000, 'upload_bps' => 10_000],
        ]);

        $snapshot = DiagnosisContextSnapshot::of($ctx);
        $hydrated = SnapshotHydrator::hydrate($snapshot);

        $this->assertSame(2, $snapshot['snapshot_version']);
        $this->assertInstanceOf(Carbon::class, $hydrated['traffic']['at']);
        $this->assertSame(200_000, $hydrated['traffic']['download_bps']);
        foreach ([[], self::FLAGS_ON] as $flags) {
            $a = $this->classify($ctx, $flags);
            $b = $this->classify($hydrated, $flags);
            $this->assertSame([$a['cause_code'], $a['confidence'], $a['rule_id'], $a['secondary'], $a['evidence']], [$b['cause_code'], $b['confidence'], $b['rule_id'], $b['secondary'], $b['evidence']]);
        }
    }

    public function test_a_version_1_snapshot_without_the_new_blocks_still_replays_with_all_flags_on(): void
    {
        $ctx = DiagnosisSyntheticContexts::base($this->now);
        $snapshot = DiagnosisContextSnapshot::of($ctx);
        $snapshot['snapshot_version'] = 1;
        unset($snapshot['traffic'], $snapshot['area_power']);

        $hydrated = SnapshotHydrator::hydrate($snapshot);

        $this->assertSame($this->classify($ctx)['cause_code'], $this->classify($hydrated, self::FLAGS_ON)['cause_code']);
    }

    public function test_the_back_test_runner_applies_the_three_flags_as_overrides(): void
    {
        $ctx = $this->losContext(['traffic' => ['at' => $this->ago(3), 'download_bps' => 200_000, 'upload_bps' => 10_000]]);
        $row = ['id' => 'R1', 'context' => DiagnosisContextSnapshot::of($ctx), 'labels' => [], 'stored_cause' => 'drop_los'];
        $runner = new BacktestRunner(app(OutageDiagnoser::class));

        $this->assertSame('drop_los', $runner->run([$row])->entries[0]['predicted']);
        $this->assertSame('unknown', $runner->run([$row], ['diagnosis_traffic_guard_enabled' => '1'])->entries[0]['predicted']);
        $this->assertSame(['drop_los', 'pon_cut', 'odp_cut', 'power_off', 'cpe_dead'], Rules::TRAFFIC_BLOCKS);
    }
}
