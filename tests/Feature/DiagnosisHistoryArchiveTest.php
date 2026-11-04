<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Package;
use App\Models\Router;
use App\Models\Setting;
use App\Services\Grafana\GrafanaClient;
use App\Services\Outage\OnuRxDailyArchiver;
use App\Services\Outage\PppoePresenceArchiver;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Diagnosis history archive: retention 400 days, daily ONU RX, PPPoE session presence from Prometheus.
 * Synthetic ids and names only. Fixtures follow the real metric: label `interface` = `<pppoe-NAME>`, no `user`
 * label, `status="down"` series with value 0, and a failed router poll = scrape_up 0 with every session missing.
 */
#[Group('blocking-core')]
class DiagnosisHistoryArchiveTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'http://grafana.test:3001';

    private const TZ = 'Asia/Jakarta';

    /** 'ok' | 'timeout' | 'malformed' | 'empty' (no sessions, polls fine) | 'big' (memory case) */
    private string $mode = 'ok';

    /** @var list<int> */
    private array $failRouters = [];

    /** @var array<int, array<string, list<array{0:int,1:int}>>> router => user => absent [from, to) unix ranges */
    private array $absent = [];

    /** @var array<int, list<array{0:int,1:int}>> router => failed poll [from, to) unix ranges */
    private array $pollFail = [];

    /** @var list<array{kind:string, rid:int, from:int, to:int, step:int}> */
    private array $queries = [];

    /** @var mixed prebuilt response for the memory case */
    private $big = null;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $now = Carbon::parse('2026-10-21 02:00:00', self::TZ);
        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);
        config(['services.grafana.allowed_hosts' => ['grafana.test']]);
        Setting::set(GrafanaClient::SETTING_URL, self::BASE);
        Setting::setSecret(GrafanaClient::SETTING_TOKEN, 'glsa_SYNTHETIC_TEST_TOKEN_0000');

        $this->mode = 'ok';
        $this->failRouters = [];
        $this->absent = [];
        $this->pollFail = [];
        $this->queries = [];
        $this->big = null;

        Http::fake(function (Request $r) {
            $q = $r->data();
            $expr = $q['queries'][0]['expr'];
            if (preg_match('/^skynet_pppoe_session\{router_id="(\d+)"\}$/', $expr, $m)) {
                $kind = 'presence';
            } elseif (preg_match('/^skynet_scrape_up\{router_id="(\d+)"\}$/', $expr, $m)) {
                $kind = 'poll';
            } else {
                return Http::response(['message' => 'not whitelisted'], 400);
            }
            $rid = (int) $m[1];
            $from = intdiv((int) $q['from'], 1000);
            $to = intdiv((int) $q['to'], 1000);
            $step = intdiv((int) $q['queries'][0]['intervalMs'], 1000);
            $this->queries[] = ['kind' => $kind, 'rid' => $rid, 'from' => $from, 'to' => $to, 'step' => $step];

            if ($this->mode === 'timeout' || in_array($rid, $this->failRouters, true)) {
                throw new ConnectionException('timeout');
            }
            if ($this->mode === 'malformed') {
                return Http::response(['results' => ['A' => ['frames' => 'garbage']]]);
            }
            $inRanges = fn (int $t, array $ranges): bool => (bool) array_filter($ranges, fn ($x) => $t >= $x[0] && $t < $x[1]);
            $frame = fn (array $labels, array $times, array $values) => [
                'schema' => ['refId' => 'A', 'fields' => [
                    ['name' => 'Time', 'type' => 'time'],
                    ['name' => 'Value', 'type' => 'number', 'labels' => $labels],
                ]],
                'data' => ['values' => [$times, $values]],
            ];

            if ($kind === 'poll') {
                $times = [];
                for ($t = $from; $t <= $to; $t += $step) {
                    if (! $inRanges($t, $this->pollFail[$rid] ?? [])) {
                        $times[] = $t * 1000;
                    }
                }

                return Http::response(['results' => ['A' => ['frames' => $times === [] ? [] : [
                    $frame(['router_id' => (string) $rid, 'job' => 'skynet_traffic_106'], $times, array_fill(0, count($times), 1)),
                ]]]]);
            }
            if ($this->mode === 'big') {
                return $this->big;
            }
            if ($this->mode === 'empty') {
                return Http::response(['results' => ['A' => ['frames' => []]]]);
            }

            $frames = [];
            foreach ($this->absent[$rid] ?? [] as $user => $ranges) {
                $times = [];
                for ($t = $from; $t <= $to; $t += $step) {
                    if (! $inRanges($t, $ranges) && ! $inRanges($t, $this->pollFail[$rid] ?? [])) {
                        $times[] = $t * 1000;
                    }
                }
                if ($times === []) {
                    continue; // a session with no sample in the chunk has no series
                }
                $labels = ['interface' => '<pppoe-'.$user.'>', 'router_id' => (string) $rid, 'status' => 'up'];
                $frames[] = $frame($labels, $times, array_fill(0, count($times), 1));
            }
            // real exporter: ended sessions keep a status="down" series with value 0
            $downTimes = [$from * 1000, ($from + $step) * 1000];
            $frames[] = $frame(['interface' => '<pppoe-ghost.synthetic>', 'router_id' => (string) $rid, 'status' => 'down'], $downTimes, [0, 0]);

            return Http::response(['results' => ['A' => ['frames' => $frames]]]);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function router(int $id): Router
    {
        $router = new Router([
            'name' => 'R'.$id,
            'ip_address' => '10.99.0.'.($id % 250),
            'username' => 'admin',
            'password' => 'canary-pw',
            'port' => 8728,
            'is_active' => true,
        ]);
        $router->id = $id;
        $router->save();

        return $router;
    }

    private function customer(string $pppoe, int $routerId): Customer
    {
        $package = Package::query()->first()
            ?? Package::create(['name' => 'Pkg', 'code' => 'PKG-'.uniqid(), 'price' => 100000]);

        return Customer::create([
            'code' => 'C-'.uniqid(),
            'name' => 'Cust',
            'phone' => '081200000011',
            'address' => 'Addr',
            'pppoe_user' => $pppoe,
            'package_id' => $package->id,
            'status' => 'active',
            'router_id' => $routerId,
        ]);
    }

    private function wib(string $time): int
    {
        return CarbonImmutable::parse($time, self::TZ)->getTimestamp();
    }

    private function archiveDay(string $date, ?CarbonImmutable $now = null, bool $force = false, ?int $router = null): array
    {
        return app(PppoePresenceArchiver::class)->archiveDay(
            CarbonImmutable::parse($date, self::TZ),
            $now ?? CarbonImmutable::now(),
            $router,
            0,
            false,
            $force,
        );
    }

    // ------------------------------------------------------------ retention

    public function test_prune_default_is_400_days_and_covers_the_archive_tables(): void
    {
        $this->assertSame(400, config('outage.events_retention_days'));
        $today = now()->utc();

        $at = fn (int $days) => $today->copy()->subDays($days)->format('Y-m-d H:i:s');
        $day = fn (int $days) => $today->copy()->subDays($days)->format('Y-m-d');
        foreach ([100, 399, 401] as $age) {
            DB::table('onu_state_events')->insert(['olt_id' => 1, 'onu_ref' => '1/1/1:1', 'to_phase' => 'los', 'source' => 'olt_poll', 'occurred_at' => $at($age), 'collected_at' => $at($age)]);
            DB::table('onu_rx_daily')->insert(['olt_id' => 1, 'onu_ref' => '1/1/1:'.$age, 'day_wib' => $day($age), 'samples' => 1]);
            DB::table('pppoe_session_presence')->insert(['router_id' => 1, 'user_hash' => 'h'.$age, 'down_at' => $at($age)]);
            DB::table('pppoe_presence_runs')->insert(['router_id' => 1, 'day_wib' => $day($age), 'finished_at' => $at($age)]);
        }

        $this->artisan('outage:events-prune', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(3, DB::table('onu_rx_daily')->count());

        $this->artisan('outage:events-prune')->assertSuccessful();
        foreach (['onu_state_events', 'onu_rx_daily', 'pppoe_session_presence', 'pppoe_presence_runs'] as $table) {
            $this->assertSame(2, DB::table($table)->count(), $table);
        }
        $this->assertSame(0, DB::table('pppoe_session_presence')->where('user_hash', 'h401')->count());

        // an explicit --days still wins over the config
        $this->artisan('outage:events-prune', ['--days' => 200])->assertSuccessful();
        $this->assertSame(1, DB::table('onu_rx_daily')->count());
    }

    // ------------------------------------------------------------ daily RX

    private function tracker(int $olt, string $ref, ?int $customer, array $daily): void
    {
        DB::table('outage_onu_tracker')->insert([
            'olt_id' => $olt,
            'onu_ref' => $ref,
            'customer_id' => $customer,
            'phase' => 'online',
            'rx_daily' => json_encode($daily),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_rx_daily_is_archived_from_the_tracker_and_is_idempotent(): void
    {
        $this->tracker(1, '1/2/3:4', 7, [
            ['date' => '2026-10-18', 'dbm' => -20.5, 'n' => 22],
            ['date' => '2026-10-19', 'dbm' => -21.0, 'n' => 24],
            ['date' => '2026-10-20', 'dbm' => -22.0, 'n' => 5, 'v' => [-21.5, -23.0, -22.0, -20.0, -22.25]],
        ]);
        $this->tracker(1, '1/2/3:5', null, [['date' => 'garbage', 'dbm' => -20.0, 'n' => 1], ['date' => '2026-10-20', 'dbm' => 'x']]);
        $this->tracker(9, '1/1/1:1', 8, []);

        $this->artisan('outage:rx-archive', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, DB::table('onu_rx_daily')->count());

        $this->artisan('outage:rx-archive')->assertSuccessful();
        $this->assertSame(3, DB::table('onu_rx_daily')->count());
        $today = DB::table('onu_rx_daily')->where('day_wib', '2026-10-20')->first();
        $this->assertSame(-22.0, (float) $today->rx_median);
        $this->assertSame(-23.0, (float) $today->rx_min);
        $this->assertSame(-20.0, (float) $today->rx_max);
        $this->assertSame(-22.25, (float) $today->rx_last);
        $this->assertSame(5, (int) $today->samples);
        $this->assertSame(7, (int) $today->customer_id);
        $old = DB::table('onu_rx_daily')->where('day_wib', '2026-10-18')->first();
        $this->assertNull($old->rx_min);
        $this->assertSame(22, (int) $old->samples);

        $this->artisan('outage:rx-archive')->assertSuccessful();
        $this->assertSame(3, DB::table('onu_rx_daily')->count());
    }

    public function test_rx_row_without_raw_values_updates_median_and_count_but_keeps_min_max_last(): void
    {
        $this->tracker(1, '1/2/3:4', 7, [['date' => '2026-10-20', 'dbm' => -22.0, 'n' => 5, 'v' => [-21.0, -23.0]]]);
        app(OnuRxDailyArchiver::class)->run();

        // the tracker rolled over: raw values gone, day median / count slightly newer
        DB::table('outage_onu_tracker')->update(['rx_daily' => json_encode([['date' => '2026-10-20', 'dbm' => -22.5, 'n' => 6]])]);
        $stats = app(OnuRxDailyArchiver::class)->run();

        $this->assertSame(0, $stats['with_range']);
        $row = DB::table('onu_rx_daily')->first();
        $this->assertSame(1, DB::table('onu_rx_daily')->count());
        $this->assertSame(-23.0, (float) $row->rx_min);
        $this->assertSame(-21.0, (float) $row->rx_max);
        $this->assertSame(-23.0, (float) $row->rx_last);
        $this->assertSame(-22.5, (float) $row->rx_median);
        $this->assertSame(6, (int) $row->samples);
    }

    // ------------------------------------------------------------ gaps

    public function test_gaps_of_works_on_the_minute_bitmap_and_the_poll_health(): void
    {
        // present 0-9, absent 10-14, present 15-49, absent 50-59
        $bits = str_repeat('1', 10).str_repeat('0', 5).str_repeat('1', 35).str_repeat('0', 10);

        $this->assertSame([[10, 15, false], [50, null, false]], PppoePresenceArchiver::gapsOf($bits));

        // poll failed in minutes 12-14: gap 1 keeps its two known minutes, flagged uncertain
        $known = str_repeat('1', 12).str_repeat('0', 3).str_repeat('1', 45);
        $this->assertSame([[10, 15, true], [50, null, false]], PppoePresenceArchiver::gapsOf($bits, $known));

        // poll failed in minutes 10-14 and 50-59: both gaps lie only in unknown minutes: nothing stored
        $allUnknown = str_repeat('1', 10).str_repeat('0', 5).str_repeat('1', 35).str_repeat('0', 10);
        $this->assertSame([], PppoePresenceArchiver::gapsOf($bits, $allUnknown));

        $one = substr_replace(str_repeat('1', 60), '0', 20, 1);
        $this->assertSame([[20, 21, false]], PppoePresenceArchiver::gapsOf($one));
        $this->assertSame([], PppoePresenceArchiver::gapsOf(str_repeat('0', 20).'111')); // minutes before the first sample
        $this->assertSame([], PppoePresenceArchiver::gapsOf(str_repeat('0', 60)));
        $this->assertSame([[12, 15]], PppoePresenceArchiver::unknownRanges($known));
    }

    // ------------------------------------------------------------ presence

    public function test_presence_stores_gaps_with_customer_id_and_never_the_username(): void
    {
        $this->router(1);
        $alice = $this->customer('alice.synthetic', 1);
        $this->absent[1] = [
            'alice.synthetic' => [[$this->wib('2026-10-20 03:00:00'), $this->wib('2026-10-20 03:05:00')]],
            'bob.synthetic' => [[$this->wib('2026-10-20 23:30:00'), $this->wib('2026-10-21 03:00:00')]],
            'steady.synthetic' => [],
        ];

        $stats = $this->archiveDay('2026-10-20');

        $this->assertSame('done', $stats['status']);
        $this->assertSame(3, $stats['series']); // the status="down" ghost is not a session
        $this->assertSame(2, $stats['gaps']);
        $rows = DB::table('pppoe_session_presence')->orderBy('down_at')->get();
        $this->assertCount(2, $rows);

        $a = $rows[0];
        $this->assertSame('2026-10-19 20:00:00', $a->down_at); // 03:00 WIB in UTC
        $this->assertSame('2026-10-19 20:05:00', $a->up_at);
        $this->assertSame(5, (int) $a->minutes);
        $this->assertSame(0, (int) $a->uncertain);
        $this->assertSame($alice->id, (int) $a->customer_id);
        $this->assertSame(PppoePresenceArchiver::userHash('ALICE.synthetic'), $a->user_hash);

        $b = $rows[1];
        $this->assertSame('2026-10-20 16:30:00', $b->down_at);
        $this->assertNull($b->up_at);
        $this->assertNull($b->customer_id); // not mapped: only the hash
        $this->assertSame(16, strlen($b->user_hash));

        $dump = json_encode(DB::table('pppoe_session_presence')->get()).json_encode(DB::table('pppoe_presence_runs')->get());
        $this->assertStringNotContainsString('synthetic', $dump);
        $run = DB::table('pppoe_presence_runs')->where('router_id', 1)->where('day_wib', '2026-10-20')->first();
        $this->assertSame(0, (int) $run->unknown_minutes);
        $this->assertSame(3, (int) $run->series);
    }

    public function test_user_hash_uses_a_derived_key_not_the_raw_app_key(): void
    {
        $raw = substr(hash_hmac('sha256', 'alice.synthetic', (string) config('app.key')), 0, 16);

        $this->assertNotSame($raw, PppoePresenceArchiver::userHash('alice.synthetic'));
        $this->assertSame(PppoePresenceArchiver::userHash('alice.synthetic'), PppoePresenceArchiver::userHash(' ALICE.synthetic '));
    }

    public function test_presence_rerun_is_idempotent_and_the_next_day_closes_an_open_gap(): void
    {
        $this->router(1);
        $this->absent[1] = [
            'bob.synthetic' => [[$this->wib('2026-10-20 23:30:00'), $this->wib('2026-10-21 00:10:00')]],
            'alice.synthetic' => [[$this->wib('2026-10-20 03:00:00'), $this->wib('2026-10-20 03:05:00')]],
        ];

        $this->archiveDay('2026-10-20');
        $this->archiveDay('2026-10-20', null, true);
        $this->assertSame(2, DB::table('pppoe_session_presence')->count());

        $later = CarbonImmutable::parse('2026-10-22 02:00:00', self::TZ);
        $this->archiveDay('2026-10-21', $later);

        $closed = DB::table('pppoe_session_presence')->where('down_at', '2026-10-20 16:30:00')->first();
        $this->assertSame('2026-10-20 17:10:00', $closed->up_at); // 00:10 WIB
        $this->assertSame(40, (int) $closed->minutes);
        $this->assertSame(0, (int) $closed->uncertain);

        // re-reading the old day must not re-open the closed gap
        $this->archiveDay('2026-10-20', $later, true);
        $this->assertSame('2026-10-20 17:10:00', DB::table('pppoe_session_presence')->where('down_at', '2026-10-20 16:30:00')->value('up_at'));
        $this->assertSame(2, DB::table('pppoe_session_presence')->count());
    }

    public function test_failed_router_polls_are_not_stored_as_session_drops(): void
    {
        $this->router(15);
        $this->router(10);
        $day = '2026-10-20';
        // router 15: ONE failed poll minute (every session missing); one real drop; one drop that partly overlaps a failed poll
        $this->absent[15] = [
            'a.synthetic' => [[$this->wib("$day 12:00:00"), $this->wib("$day 12:10:00")]],
            'b.synthetic' => [],
            'c.synthetic' => [[$this->wib("$day 14:00:00"), $this->wib("$day 14:30:00")]],
        ];
        $this->pollFail[15] = [
            [$this->wib("$day 10:00:00"), $this->wib("$day 10:01:00")],
            [$this->wib("$day 14:20:00"), $this->wib("$day 14:40:00")],
        ];
        // router 10: a long failed poll takes every session with it
        $this->absent[10] = ['x.synthetic' => [], 'y.synthetic' => [], 'z.synthetic' => []];
        $this->pollFail[10] = [[$this->wib("$day 08:00:00"), $this->wib("$day 09:08:00")]];

        $this->archiveDay($day);

        $r15 = DB::table('pppoe_session_presence')->where('router_id', 15)->orderBy('down_at')->get();
        $this->assertCount(2, $r15); // the failed minute 10:00 produced no gap for a, b or c
        $this->assertSame(10, (int) $r15[0]->minutes);
        $this->assertSame(0, (int) $r15[0]->uncertain);
        $this->assertSame(40, (int) $r15[1]->minutes); // c is also missing in the failed poll minutes 14:30-14:39
        $this->assertSame(1, (int) $r15[1]->uncertain);
        $run15 = DB::table('pppoe_presence_runs')->where('router_id', 15)->first();
        $this->assertSame(21, (int) $run15->unknown_minutes);
        $this->assertSame([[600, 601], [860, 880]], json_decode($run15->unknown_ranges, true));

        $this->assertSame(0, DB::table('pppoe_session_presence')->where('router_id', 10)->count());
        $run10 = DB::table('pppoe_presence_runs')->where('router_id', 10)->first();
        $this->assertSame(68, (int) $run10->unknown_minutes);
        $this->assertSame([[480, 548]], json_decode($run10->unknown_ranges, true));
    }

    public function test_a_multi_day_poll_failure_never_becomes_a_fake_gap_and_open_gaps_wait_for_complete_days(): void
    {
        $this->router(16);
        // a real drop on 10-03 evening (router polled), then no successful poll on 10-04..10-06, back on 10-07
        $this->absent[16] = ['u.synthetic' => [[$this->wib('2026-10-03 23:00:00'), $this->wib('2026-10-07 08:00:00')]], 'v.synthetic' => []];
        $this->pollFail[16] = [[$this->wib('2026-10-04 00:00:00'), $this->wib('2026-10-07 00:00:00')]];
        $now = CarbonImmutable::parse('2026-10-08 02:00:00', self::TZ);

        // 10-05 is skipped on purpose: the open gap must stay open when a day in between is not read
        foreach (['2026-10-03', '2026-10-04', '2026-10-06', '2026-10-07'] as $d) {
            $this->archiveDay($d, $now);
        }
        $open = DB::table('pppoe_session_presence')->where('router_id', 16)->get();
        $this->assertCount(1, $open);
        $this->assertNull($open[0]->up_at);
        $this->assertSame('2026-10-03 16:00:00', $open[0]->down_at);

        // the failed days have no sessions and no gaps, and count as done (nothing to learn from them)
        $this->archiveDay('2026-10-05', $now);
        $run = DB::table('pppoe_presence_runs')->where('router_id', 16)->where('day_wib', '2026-10-05')->first();
        $this->assertSame(1440, (int) $run->unknown_minutes);
        $this->assertSame(0, (int) $run->series);
        $this->assertTrue(app(PppoePresenceArchiver::class)->isDone(16, '2026-10-05'));
        $this->assertSame(1, DB::table('pppoe_session_presence')->where('router_id', 16)->count());

        // now every day in between is read: the next read of 10-07 closes the gap, flagged uncertain
        $this->archiveDay('2026-10-07', $now, true);
        $row = DB::table('pppoe_session_presence')->where('router_id', 16)->first();
        $this->assertSame('2026-10-07 01:00:00', $row->up_at); // 08:00 WIB
        $this->assertSame(1, (int) $row->uncertain);
    }

    public function test_presence_reads_a_day_in_bounded_chunks(): void
    {
        $this->router(1);
        $this->absent[1] = ['steady.synthetic' => []];

        $this->archiveDay('2026-10-20');

        $presence = array_values(array_filter($this->queries, fn ($q) => $q['kind'] === 'presence'));
        $poll = array_values(array_filter($this->queries, fn ($q) => $q['kind'] === 'poll'));
        $this->assertCount(24, $presence); // 24 h in 1 h chunks
        $this->assertCount(24, $poll);
        $covered = 0;
        foreach ($presence as $q) {
            $this->assertSame(60, $q['step']);
            $this->assertLessThanOrEqual(60, intdiv($q['to'] - $q['from'], $q['step']) + 1);
            $covered += intdiv($q['to'] - $q['from'], $q['step']) + 1;
        }
        $this->assertSame(1440, $covered);
        $this->assertSame($this->wib('2026-10-20 00:00:00'), $presence[0]['from']);
        $this->assertSame($this->wib('2026-10-21 00:00:00') - 60, $presence[23]['to']);
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization') && $r->url() === self::BASE.'/api/ds/query');
    }

    public function test_presence_empty_timeout_and_malformed_answers_do_not_crash_or_mark_done(): void
    {
        $this->router(1);
        $this->absent[1] = ['alice.synthetic' => [[$this->wib('2026-10-20 03:00:00'), $this->wib('2026-10-20 03:05:00')]]];

        foreach (['timeout', 'malformed'] as $mode) {
            $this->mode = $mode;
            Cache::flush();
            $stats = $this->archiveDay('2026-10-20');
            $this->assertSame('failed', $stats['status'], $mode);
            $this->assertSame(1, $stats['routers_failed']);
        }
        $this->assertSame(0, DB::table('pppoe_session_presence')->count());
        $this->assertSame(0, DB::table('pppoe_presence_runs')->count());

        // router polled fine but no sessions at all: not final, retried
        $this->mode = 'empty';
        Cache::flush();
        $stats = $this->archiveDay('2026-10-20');
        $this->assertSame('done', $stats['status']);
        $this->assertSame(0, $stats['series']);
        $this->assertSame(0, DB::table('pppoe_session_presence')->count());
        $this->assertFalse(app(PppoePresenceArchiver::class)->isDone(1, '2026-10-20'));

        $this->mode = 'ok';
        $this->archiveDay('2026-10-20');
        $this->assertSame(1, DB::table('pppoe_session_presence')->count());
        $this->assertTrue(app(PppoePresenceArchiver::class)->isDone(1, '2026-10-20'));
    }

    public function test_presence_partial_failure_resumes_with_the_missing_router_only(): void
    {
        $this->router(1);
        $this->router(2);
        $this->absent[1] = ['a.synthetic' => [[$this->wib('2026-10-20 03:00:00'), $this->wib('2026-10-20 03:05:00')]]];
        $this->absent[2] = ['b.synthetic' => [[$this->wib('2026-10-20 04:00:00'), $this->wib('2026-10-20 04:05:00')]]];

        $this->failRouters = [2];
        $first = $this->archiveDay('2026-10-20');
        $this->assertSame('partial', $first['status']);
        $this->assertSame(1, DB::table('pppoe_session_presence')->count());
        $this->assertSame(1, DB::table('pppoe_presence_runs')->count());

        $this->failRouters = [];
        $this->queries = [];
        $second = $this->archiveDay('2026-10-20');
        $this->assertSame('done', $second['status']);
        $this->assertSame(1, $second['routers_skipped']);
        $this->assertSame([2], array_values(array_unique(array_column($this->queries, 'rid'))));
        $this->assertSame(2, DB::table('pppoe_session_presence')->count());
    }

    public function test_presence_skips_an_unfinished_day_old_days_and_krian_before_its_cutover(): void
    {
        $this->router(15);
        $this->absent[15] = ['k.synthetic' => []];

        $this->assertSame('skipped_open_day', $this->archiveDay('2026-10-21')['status']); // "now" is 02:00 on the 21st
        $this->assertSame('skipped_old', $this->archiveDay('2026-10-01')['status']);
        $this->assertSame([], $this->queries);

        $stats = $this->archiveDay('2026-10-10'); // Krian data up to 10-10 came from the CCR
        $this->assertSame(0, $stats['routers_ok']);
        $this->assertSame([], $this->queries);

        $this->assertSame(1, $this->archiveDay('2026-10-11')['routers_ok']);
    }

    public function test_presence_not_configured_does_nothing(): void
    {
        $this->router(1);
        Setting::setSecret(GrafanaClient::SETTING_TOKEN, '');
        config(['services.grafana.token' => null]);

        $this->assertSame('skipped_unconfigured', $this->archiveDay('2026-10-20')['status']);
        $this->assertSame([], $this->queries);
    }

    public function test_the_biggest_router_day_stays_far_below_the_scheduler_memory_limit(): void
    {
        $this->router(513);
        $dayStart = $this->wib('2026-10-20 00:00:00');
        $times = [];
        for ($i = 0; $i < 60; $i++) {
            $times[] = ($dayStart + $i * 60) * 1000;
        }
        $frames = [];
        for ($s = 0; $s < 1100; $s++) {
            $frames[] = [
                'schema' => ['refId' => 'A', 'fields' => [
                    ['name' => 'Time', 'type' => 'time'],
                    ['name' => 'Value', 'type' => 'number', 'labels' => ['interface' => '<pppoe-user'.$s.'.synthetic>', 'router_id' => '513', 'status' => 'up', 'host' => 'h', 'instance' => 'i', 'job' => 'skynet_traffic_106', 'router' => 'R', 'source' => 's']],
                ]],
                'data' => ['values' => [$times, array_fill(0, 60, 1)]],
            ];
        }
        // one prebuilt response for every hour: the Http fake keeps every response it returns
        $this->big = Http::response(['results' => ['A' => ['frames' => $frames]]]);
        unset($frames);
        $this->mode = 'big';

        gc_collect_cycles();
        $before = memory_get_usage();
        memory_reset_peak_usage();
        $stats = $this->archiveDay('2026-10-20');
        $delta = memory_get_peak_usage() - $before;

        $this->assertSame('done', $stats['status']);
        $this->assertSame(1100, $stats['series']);
        $this->assertSame(1100, $stats['gaps']); // present only the first hour: every session ends open
        $this->assertLessThan(64 * 1024 * 1024, $delta, 'peak delta '.round($delta / 1048576, 1).' MB');
    }

    // ------------------------------------------------------------ command

    public function test_backfill_command_is_resumable_and_starts_no_earlier_than_the_first_prometheus_day(): void
    {
        $this->router(1);
        $this->absent[1] = ['a.synthetic' => [[$this->wib('2026-10-19 03:00:00'), $this->wib('2026-10-19 03:05:00')]]];

        $this->failRouters = [1];
        $this->artisan('diagnosis:presence-archive', ['--backfill-from' => '2026-09-01', '--pause-ms' => 0])->assertSuccessful();
        $this->assertSame(0, DB::table('pppoe_presence_runs')->count());
        $minFrom = min(array_column($this->queries, 'from'));
        $this->assertGreaterThanOrEqual($this->wib('2026-10-02 00:00:00'), $minFrom);

        $this->failRouters = [];
        $this->queries = [];
        Cache::flush(); // the failures above opened the circuit breaker
        $this->artisan('diagnosis:presence-archive', ['--backfill-from' => '2026-10-18', '--pause-ms' => 0])->assertSuccessful();
        $days = DB::table('pppoe_presence_runs')->orderBy('day_wib')->pluck('day_wib')->map(fn ($d) => substr((string) $d, 0, 10))->all();
        $this->assertSame(['2026-10-18', '2026-10-19', '2026-10-20'], $days);
        $this->assertSame(1, DB::table('pppoe_session_presence')->count());

        // a third run queries nothing: everything is finished
        $this->queries = [];
        $this->artisan('diagnosis:presence-archive', ['--backfill-from' => '2026-10-18', '--pause-ms' => 0])->assertSuccessful();
        $this->assertSame([], $this->queries);
        $this->assertSame(1, DB::table('pppoe_session_presence')->count());
    }

    public function test_nightly_default_catches_up_yesterday_and_the_day_before(): void
    {
        $this->router(1);
        $this->absent[1] = ['a.synthetic' => []];

        $this->artisan('diagnosis:presence-archive', ['--pause-ms' => 0])->assertSuccessful();

        $days = DB::table('pppoe_presence_runs')->orderBy('day_wib')->pluck('day_wib')->map(fn ($d) => substr((string) $d, 0, 10))->all();
        $this->assertSame(['2026-10-19', '2026-10-20'], $days);

        $this->artisan('diagnosis:presence-archive', ['--date' => 'nonsense'])->assertFailed();
    }

    // ------------------------------------------------------------ client + schedule

    public function test_range_between_rejects_instant_templates_and_oversized_windows(): void
    {
        $client = app(GrafanaClient::class);
        $from = CarbonImmutable::parse('2026-10-20 00:00:00', self::TZ);

        try {
            $client->queryRangeBetween('pppoe_total', [], $from, $from->addHour());
            $this->fail('instant template accepted');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        try {
            $client->queryRangeBetween('pppoe_session_presence', ['rid' => 1], $from, $from->addDay(), 60);
            $this->fail('oversized window accepted');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        try {
            $client->queryRangeBetween('pppoe_session_presence', ['rid' => 'x"y'], $from, $from->addHour());
            $this->fail('bad label accepted');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame([], $this->queries);
    }

    public function test_archive_jobs_are_scheduled_off_peak_without_overlap(): void
    {
        config(['services.ebilling_scheduler.mode' => 'monitoring']);
        $schedule = new Schedule;
        $this->app->instance(Schedule::class, $schedule);
        \Illuminate\Support\Facades\Schedule::clearResolvedInstance(Schedule::class);
        require base_path('routes/console.php');

        $find = fn (string $needle) => array_values(array_filter($schedule->events(), fn ($e) => str_contains((string) $e->command, $needle)));

        $rx = $find('outage:rx-archive');
        $this->assertCount(2, $rx);
        $this->assertEqualsCanonicalizing(['52 23 * * *', '12 1 * * *'], array_map(fn ($e) => $e->expression, $rx));

        $presence = $find('diagnosis:presence-archive');
        $this->assertCount(1, $presence);
        $this->assertSame('23 1 * * *', $presence[0]->expression);
        $this->assertTrue($presence[0]->withoutOverlapping);
        $this->assertTrue($presence[0]->runInBackground);
        foreach (array_merge($rx, $presence) as $event) {
            $this->assertSame(self::TZ, (string) $event->timezone);
            $this->assertTrue($event->withoutOverlapping);
        }
    }
}
