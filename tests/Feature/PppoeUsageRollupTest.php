<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Package;
use App\Models\Router;
use App\Models\Setting;
use App\Services\Grafana\GrafanaClient;
use App\Services\Monitoring\PppoeUsageRollup;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Canary;
use Tests\TestCase;

/**
 * M-4c-1: daily PPPoE usage rollup from Prometheus into pppoe_usage_daily.
 */
#[Group('blocking-core')]
class PppoeUsageRollupTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'http://grafana.test:3001';

    private const TZ = 'Asia/Jakarta';

    /** @var list<string> */
    private array $exprs = [];

    /** @var list<string> */
    private array $toMs = [];

    private bool $failAll = false;

    /** @var list<int> */
    private array $failRouters = [];

    /** @var array<int, array{rx: array<string, float>, tx: array<string, float>}> */
    private array $fixtures = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['services.grafana.allowed_hosts' => ['grafana.test']]);
        Setting::set(GrafanaClient::SETTING_URL, self::BASE);
        Setting::setSecret(GrafanaClient::SETTING_TOKEN, 'glsa_SYNTHETIC_TEST_TOKEN_0000');

        $this->exprs = [];
        $this->toMs = [];
        $this->fixtures = [];
        $this->failRouters = [];

        Http::fake(function (Request $r) {
            $q = $r->data();
            $expr = $q['queries'][0]['expr'];
            $this->exprs[] = $expr;
            $this->toMs[] = (string) $q['to'];
            if ($this->failAll) {
                throw new ConnectionException('timeout');
            }
            if (! preg_match('/^increase\(skynet_user_(rx|tx)_bytes\{router_id="(\d+)"\}\[(\d+)s\]\)$/', $expr, $m)) {
                return Http::response(['message' => 'not whitelisted'], 400);
            }
            if (in_array((int) $m[2], $this->failRouters, true)) {
                return Http::response(['message' => 'boom'], 500);
            }
            $rows = $this->fixtures[(int) $m[2]][$m[1]] ?? [];
            $frames = [];
            foreach ($rows as $user => $value) {
                $frames[] = [
                    'schema' => ['refId' => 'A', 'fields' => [
                        ['name' => 'Time', 'type' => 'time'],
                        ['name' => 'Value', 'type' => 'number', 'labels' => ['user' => (string) $user, 'router_id' => $m[2]]],
                    ]],
                    'data' => ['values' => [[1700000000000], [$value]]],
                ];
            }

            return Http::response(['results' => ['A' => ['frames' => $frames]]]);
        });
    }

    private function router(int $id, bool $active = true): Router
    {
        $router = new Router([
            'name' => 'R'.$id,
            'ip_address' => '198.18.26.'.$id,
            'username' => 'admin',
            'password' => Canary::of('pw'),
            'port' => 8728,
            'is_active' => $active,
        ]);
        $router->id = $id;
        $router->save();

        return $router;
    }

    private function customer(string $pppoe, ?int $routerId, string $status = 'active'): Customer
    {
        $package = Package::query()->first()
            ?? Package::create(['name' => 'Pkg', 'code' => 'PKG-'.uniqid(), 'price' => 100000]);

        return Customer::create([
            'code' => 'C-'.uniqid(),
            'name' => 'Cust',
            'phone' => '080000000000',
            'address' => 'Addr',
            'pppoe_user' => $pppoe,
            'package_id' => $package->id,
            'status' => $status,
            'router_id' => $routerId,
        ]);
    }

    private function wib(string $time): CarbonImmutable
    {
        return CarbonImmutable::parse($time, self::TZ);
    }

    private function service(): PppoeUsageRollup
    {
        return app(PppoeUsageRollup::class);
    }

    private function rows(): Collection
    {
        return DB::table('pppoe_usage_daily')->orderBy('router_id')->orderBy('pppoe_user')->get();
    }

    public function test_window_arithmetic_at_midnight_month_start_and_day_end(): void
    {
        $svc = $this->service();
        $day = $svc->dayStart($this->wib('2026-11-01 15:30:00'));

        $this->assertSame('2026-11-01 00:00:00', $day->format('Y-m-d H:i:s'));
        $this->assertSame(self::TZ, $day->getTimezone()->getName());
        $this->assertSame(0, $svc->windowSeconds($day, $this->wib('2026-11-01 00:00:00')));
        $this->assertSame(420, $svc->windowSeconds($day, $this->wib('2026-11-01 00:07:00')));
        $this->assertSame(55800, $svc->windowSeconds($day, $this->wib('2026-11-01 15:30:00')));
        $this->assertSame(86400, $svc->windowSeconds($day, $this->wib('2026-11-02 00:00:00')));
        $this->assertSame(86400, $svc->windowSeconds($day, $this->wib('2026-11-03 12:00:00')));
        // 17:30 UTC on Oct 31 is already Nov 1 00:30 WIB.
        $this->assertSame(
            '2026-11-01',
            $svc->dayStart(CarbonImmutable::parse('2026-10-31 17:30:00', 'UTC'))->format('Y-m-d')
        );
    }

    public function test_rollup_stores_direction_mapping_links_customers_and_keeps_unlinked(): void
    {
        $this->router(1);
        $alice = $this->customer('alice', 1);
        $bob = $this->customer('Bob', 1);
        $this->fixtures[1] = [
            'rx' => ['alice' => 100.4, 'bob' => 7.0, 'ghost' => 5.0],
            'tx' => ['alice' => 900.6, 'bob' => 70.0, 'ghost' => 50.0],
        ];
        $now = $this->wib('2026-10-12 10:00:00');
        $this->travelTo($now);

        $stats = $this->service()->rollupDay($this->service()->dayStart($now), $now);

        $this->assertSame('done', $stats['status']);
        $this->assertSame(['rows' => 3, 'linked' => 2, 'unlinked' => 1], array_intersect_key($stats, array_flip(['rows', 'linked', 'unlinked'])));
        $rows = $this->rows()->keyBy('pppoe_user');
        // DIRECTION: customer download = exporter tx, upload = rx.
        $this->assertSame(['download' => 'tx', 'upload' => 'rx'], PppoeUsageRollup::DIRECTION);
        $this->assertSame(901, (int) $rows['alice']->download_bytes);
        $this->assertSame(100, (int) $rows['alice']->upload_bytes);
        $this->assertSame($alice->id, (int) $rows['alice']->customer_id);
        $this->assertSame($bob->id, (int) $rows['bob']->customer_id); // lowercase fallback
        $this->assertNull($rows['ghost']->customer_id);
        $this->assertSame('2026-10-12', $rows['alice']->usage_date_wib);
        $this->assertSame(0, (int) $rows['alice']->is_final);
        $this->assertSame('ok', $rows['alice']->quality);

        // Window = seconds since 00:00 WIB, evaluated at "now".
        $this->assertContains('increase(skynet_user_tx_bytes{router_id="1"}[36000s])', $this->exprs);
        $this->assertContains('increase(skynet_user_rx_bytes{router_id="1"}[36000s])', $this->exprs);
        $this->assertSame((string) ($now->getTimestamp() * 1000), $this->toMs[0]);
    }

    public function test_customer_on_other_router_is_not_linked_and_inactive_status_is_ignored(): void
    {
        $this->router(1);
        $this->router(5, false);
        $this->customer('other', 5);
        $this->customer('gone', 1, 'terminated');
        $this->customer('anyrouter', null);
        $this->fixtures[1] = ['rx' => ['other' => 1, 'gone' => 1, 'anyrouter' => 1], 'tx' => ['other' => 1, 'gone' => 1, 'anyrouter' => 1]];
        $now = $this->wib('2026-10-12 10:00:00');
        $this->travelTo($now);

        $this->service()->rollupDay($this->service()->dayStart($now), $now);

        $rows = $this->rows()->keyBy('pppoe_user');
        $this->assertNull($rows['other']->customer_id);
        $this->assertNull($rows['gone']->customer_id);
        $this->assertNotNull($rows['anyrouter']->customer_id);
    }

    public function test_rerun_is_idempotent_keeps_larger_totals_and_replace_overrides(): void
    {
        $this->router(1);
        $this->fixtures[1] = ['rx' => ['alice' => 100.0], 'tx' => ['alice' => 900.0]];
        $now = $this->wib('2026-10-12 10:00:00');
        $this->travelTo($now);
        $day = $this->service()->dayStart($now);

        $this->service()->rollupDay($day, $now);
        $this->service()->rollupDay($day, $now);
        $this->assertSame(1, DB::table('pppoe_usage_daily')->count());

        // User went offline later: smaller value must not shrink the stored total.
        $this->fixtures[1] = ['rx' => ['alice' => 10.0], 'tx' => ['alice' => 20.0]];
        $this->service()->rollupDay($day, $now);
        $this->assertSame(900, (int) $this->rows()->first()->download_bytes);

        $this->service()->rollupDay($day, $now, replace: true);
        $this->assertSame(20, (int) $this->rows()->first()->download_bytes);
        $this->assertSame(1, DB::table('pppoe_usage_daily')->count());
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->router(1);
        $this->fixtures[1] = ['rx' => ['alice' => 100.0], 'tx' => ['alice' => 900.0]];
        $now = $this->wib('2026-10-12 10:00:00');
        $this->travelTo($now);

        $stats = $this->service()->rollupDay($this->service()->dayStart($now), $now, dryRun: true);

        $this->assertSame(1, $stats['rows']);
        $this->assertSame(0, DB::table('pppoe_usage_daily')->count());
    }

    public function test_finalize_at_midnight_wib_uses_24h_window_and_marks_rows_final(): void
    {
        $this->router(1);
        $this->fixtures[1] = ['rx' => ['alice' => 100.0], 'tx' => ['alice' => 900.0]];
        $now = $this->wib('2026-10-12 00:07:00');
        $this->travelTo($now);
        $day = $this->wib('2026-10-11 00:00:00');

        $stats = $this->service()->rollupDay($day, $now, final: true);

        $this->assertSame('done', $stats['status']);
        $row = $this->rows()->first();
        $this->assertSame('2026-10-11', $row->usage_date_wib);
        $this->assertSame(1, (int) $row->is_final);
        $this->assertContains('increase(skynet_user_tx_bytes{router_id="1"}[86400s])', $this->exprs);
        // Evaluated at 2026-10-12 00:00 WIB exactly (= 2026-10-11 17:00 UTC).
        $this->assertSame((string) ($this->wib('2026-10-12 00:00:00')->getTimestamp() * 1000), $this->toMs[0]);

        // A finalized day is never touched by regular runs again.
        $this->exprs = [];
        $again = $this->service()->rollupDay($day, $this->wib('2026-10-11 23:50:00'));
        $this->assertSame('skipped_final', $again['status']);
        $this->assertSame([], $this->exprs);
    }

    public function test_finalize_before_day_end_and_tiny_windows_are_skipped_without_http(): void
    {
        $this->router(1);
        $day = $this->wib('2026-11-01 00:00:00');

        $early = $this->wib('2026-11-01 12:00:00');
        $this->travelTo($early);
        $this->assertSame('skipped_window', $this->service()->rollupDay($day, $early, final: true)['status']);

        $tiny = $this->wib('2026-11-01 00:03:00'); // 180s < 300s minimum window
        $this->travelTo($tiny);
        $this->assertSame('skipped_window', $this->service()->rollupDay($day, $tiny)['status']);
        $this->assertSame([], $this->exprs);

        $ok = $this->wib('2026-11-01 00:07:00'); // month start, 420s
        $this->travelTo($ok);
        $this->assertSame('done', $this->service()->rollupDay($day, $ok)['status']);
        $this->assertContains('increase(skynet_user_tx_bytes{router_id="1"}[420s])', $this->exprs);
    }

    public function test_krian_history_is_invalid_and_early_days_are_partial(): void
    {
        $this->router(15);
        $this->router(1);
        foreach ([15, 1] as $rid) {
            $this->fixtures[$rid] = ['rx' => ['u'.$rid => 1.0], 'tx' => ['u'.$rid => 2.0]];
        }
        $svc = $this->service();
        $now = $this->wib('2026-10-13 00:07:00');
        $this->travelTo($now);

        foreach (['2026-10-03', '2026-10-10', '2026-10-11'] as $date) {
            $svc->rollupDay($this->wib($date.' 00:00:00'), $now, final: true);
        }

        $q = fn (int $rid, string $date) => DB::table('pppoe_usage_daily')
            ->where('router_id', $rid)->where('usage_date_wib', $date)->value('quality');
        $this->assertSame('invalid', $q(15, '2026-10-03'));
        $this->assertSame('invalid', $q(15, '2026-10-10'));
        $this->assertSame('ok', $q(15, '2026-10-11'));
        $this->assertSame('partial', $q(1, '2026-10-03'));
        $this->assertSame('ok', $q(1, '2026-10-10'));
        $this->assertSame('ok', $q(1, '2026-10-11'));
    }

    public function test_purge_removes_only_rows_older_than_13_months(): void
    {
        $now = $this->wib('2026-10-12 10:00:00');
        $base = ['router_id' => 1, 'customer_id' => null, 'download_bytes' => 1, 'upload_bytes' => 1, 'is_final' => 1, 'quality' => 'ok', 'created_at' => now(), 'updated_at' => now()];
        DB::table('pppoe_usage_daily')->insert([
            $base + ['usage_date_wib' => '2025-09-11', 'pppoe_user' => 'old'],
            $base + ['usage_date_wib' => '2025-09-12', 'pppoe_user' => 'edge'],
            $base + ['usage_date_wib' => '2026-10-01', 'pppoe_user' => 'new'],
        ]);

        $this->assertSame(1, $this->service()->purge($now, dryRun: true));
        $this->assertSame(3, DB::table('pppoe_usage_daily')->count());

        $this->assertSame(1, $this->service()->purge($now));
        $this->assertSame(['edge', 'new'], DB::table('pppoe_usage_daily')->orderBy('pppoe_user')->pluck('pppoe_user')->all());
    }

    public function test_open_breaker_skips_without_any_http_or_writes(): void
    {
        $this->router(1);
        $now = $this->wib('2026-10-12 10:00:00');
        $this->travelTo($now);
        Cache::put('grafana.cb.open', true, 60);

        $stats = $this->service()->rollupDay($this->service()->dayStart($now), $now);

        $this->assertSame('skipped_breaker', $stats['status']);
        Http::assertNothingSent();
        $this->assertSame(0, DB::table('pppoe_usage_daily')->count());
    }

    public function test_grafana_failure_keeps_existing_rows_and_one_failed_query_skips_that_router(): void
    {
        $this->router(1);
        $this->router(4);
        $this->fixtures[1] = ['rx' => ['a' => 1.0], 'tx' => ['a' => 2.0]];
        $now = $this->wib('2026-10-12 10:00:00');
        $this->travelTo($now);
        $this->service()->rollupDay($this->service()->dayStart($now), $now);
        $this->assertSame(1, DB::table('pppoe_usage_daily')->count());

        $this->failAll = true;
        $stats = $this->service()->rollupDay($this->service()->dayStart($now), $now);

        $this->assertNotSame('done', $stats['status']);
        $this->assertSame(1, DB::table('pppoe_usage_daily')->count());
    }

    public function test_only_whitelisted_expressions_are_sent_and_only_active_routers_are_queried(): void
    {
        $this->router(1);
        $this->router(4);
        $this->router(12, false);
        $now = $this->wib('2026-10-12 10:00:00');
        $this->travelTo($now);

        $this->service()->rollupDay($this->service()->dayStart($now), $now);

        $this->assertCount(4, $this->exprs); // 2 routers x (rx + tx)
        foreach ($this->exprs as $expr) {
            $this->assertMatchesRegularExpression('/^increase\(skynet_user_(rx|tx)_bytes\{router_id="(1|4)"\}\[36000s\]\)$/', $expr);
        }
    }

    public function test_command_runs_logs_counts_only_and_never_usernames(): void
    {
        $this->router(1);
        $secretUser = 'very.secret.user@isp';
        $this->customer($secretUser, 1);
        $this->fixtures[1] = ['rx' => [$secretUser => 5.0, 'unlinked.person' => 5.0], 'tx' => [$secretUser => 9.0, 'unlinked.person' => 9.0]];
        $this->travelTo($this->wib('2026-10-12 10:00:00'));

        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged): void {
            $logged[] = $e->message.' '.json_encode($e->context);
        });

        $exit = Artisan::call('monitoring:pppoe-usage-rollup');
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertSame(2, DB::table('pppoe_usage_daily')->count());
        $this->assertStringContainsString('rows=2 linked=1 unlinked=1', $output);
        $blob = implode("\n", $logged).$output;
        $this->assertStringNotContainsString($secretUser, $blob);
        $this->assertStringNotContainsString('unlinked.person', $blob);
    }

    public function test_command_self_heals_yesterday_finalize_then_rolls_up_today(): void
    {
        $this->router(1);
        $this->fixtures[1] = ['rx' => ['a' => 1.0], 'tx' => ['a' => 2.0]];
        $this->travelTo($this->wib('2026-10-12 01:00:00'));
        $this->pendingRow(1, 'a', 1);

        Artisan::call('monitoring:pppoe-usage-rollup');

        $this->assertSame(1, (int) DB::table('pppoe_usage_daily')->where('usage_date_wib', '2026-10-11')->value('is_final'));
        $this->assertSame(1, DB::table('pppoe_usage_daily')->where('usage_date_wib', '2026-10-12')->count());
    }

    private function pendingRow(int $routerId, string $user, int $bytes, string $date = '2026-10-11'): void
    {
        DB::table('pppoe_usage_daily')->insert([
            'usage_date_wib' => $date, 'router_id' => $routerId, 'pppoe_user' => $user, 'customer_id' => null,
            'download_bytes' => $bytes, 'upload_bytes' => $bytes, 'is_final' => 0, 'quality' => 'ok',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_finalize_overwrites_with_full_day_value_and_marks_absent_users_final(): void
    {
        $this->router(1);
        $this->pendingRow(1, 'online', 5000);
        $this->pendingRow(1, 'offline_at_midnight', 777);
        $this->fixtures[1] = ['rx' => ['online' => 40.0], 'tx' => ['online' => 90.0]];
        $now = $this->wib('2026-10-12 00:07:00');
        $this->travelTo($now);

        $this->service()->rollupDay($this->wib('2026-10-11 00:00:00'), $now, final: true);

        $rows = $this->rows()->keyBy('pppoe_user');
        $this->assertSame(90, (int) $rows['online']->download_bytes); // overwritten, not max()
        $this->assertSame(777, (int) $rows['offline_at_midnight']->download_bytes);
        $this->assertSame(1, (int) $rows['offline_at_midnight']->is_final);
        $this->assertSame(1, (int) $rows['online']->is_final);
    }

    public function test_self_heal_requeries_only_failed_routers_and_stops_after_the_cap(): void
    {
        $this->router(1);
        $this->router(4);
        $this->router(9, false);
        $this->pendingRow(1, 'a', 1);
        $this->pendingRow(4, 'b', 1);
        $this->pendingRow(9, 'inactive_user', 1);
        $this->fixtures[1] = ['rx' => ['a' => 1.0], 'tx' => ['a' => 2.0]];
        $this->fixtures[4] = ['rx' => ['b' => 1.0], 'tx' => ['b' => 2.0]];
        $svc = $this->service();
        $now = $this->wib('2026-10-12 00:07:00');
        $this->travelTo($now);
        $yesterday = $this->wib('2026-10-11 00:00:00');

        // Router 4 keeps failing, router 1 answers.
        $this->failRouters = [4];
        $this->exprs = [];
        $first = $svc->pendingFinalizeRouters($now);
        $this->assertEqualsCanonicalizing([1, 4], $first);
        // Deactivated router's pending rows are closed without any query.
        $this->assertSame(1, (int) DB::table('pppoe_usage_daily')->where('router_id', 9)->value('is_final'));
        $svc->rollupDay($yesterday, $now, final: true, restrictRouters: $first);

        $this->assertSame(1, (int) DB::table('pppoe_usage_daily')->where('router_id', 1)->value('is_final'));
        $this->assertSame(0, (int) DB::table('pppoe_usage_daily')->where('router_id', 4)->value('is_final'));

        // Next attempts only target router 4, until the cap (4 total) is used up.
        $this->assertSame([4], $svc->pendingFinalizeRouters($now));
        $this->assertSame([4], $svc->pendingFinalizeRouters($now));
        $this->assertSame([4], $svc->pendingFinalizeRouters($now));
        $this->assertSame([], $svc->pendingFinalizeRouters($now));
        $this->assertSame([], $svc->pendingFinalizeRouters($now));
    }

    public function test_self_heal_stops_after_six_am_wib(): void
    {
        $this->router(1);
        $this->pendingRow(1, 'a', 1);

        $this->assertSame([], $this->service()->pendingFinalizeRouters($this->wib('2026-10-12 06:00:00')));
        $this->assertSame([1], $this->service()->pendingFinalizeRouters($this->wib('2026-10-12 05:59:00')));
    }

    public function test_usernames_differing_only_by_case_are_merged_before_upsert(): void
    {
        $this->router(1);
        $this->fixtures[1] = [
            'rx' => ['Budi' => 10.0, 'budi' => 5.0],
            'tx' => ['Budi' => 100.0, 'budi' => 50.0],
        ];
        $now = $this->wib('2026-10-12 10:00:00');
        $this->travelTo($now);

        $this->service()->rollupDay($this->service()->dayStart($now), $now);

        $this->assertSame(1, DB::table('pppoe_usage_daily')->count());
        $row = $this->rows()->first();
        $this->assertSame('Budi', $row->pppoe_user);
        $this->assertSame(150, (int) $row->download_bytes);
        $this->assertSame(15, (int) $row->upload_bytes);
    }

    public function test_bracketed_pppoe_interface_label_is_normalized_and_linked_on_same_router(): void
    {
        $this->router(1);
        $this->router(2);
        $abc = $this->customer('abc', 1);
        $other = $this->customer('xyz', 2);
        $this->fixtures[1] = ['rx' => ['<pppoe-abc>' => 10.0, '<pppoe-xyz>' => 3.0], 'tx' => ['<pppoe-abc>' => 100.0, '<pppoe-xyz>' => 30.0]];
        $now = $this->wib('2026-10-12 10:00:00');
        $this->travelTo($now);

        $this->service()->rollupDay($this->service()->dayStart($now), $now, onlyRouter: 1);

        $rows = $this->rows()->keyBy('pppoe_user');
        $this->assertSame(['abc', 'xyz'], $rows->keys()->all());
        $this->assertSame($abc->id, (int) $rows['abc']->customer_id);
        $this->assertSame(100, (int) $rows['abc']->download_bytes);
        $this->assertNull($rows['xyz']->customer_id); // customer lives on another router
        $this->assertNotSame($other->id, (int) $rows['xyz']->customer_id);
    }

    public function test_bracketed_label_matching_is_case_insensitive(): void
    {
        $this->router(1);
        $budi = $this->customer('Budi', 1);
        $this->fixtures[1] = ['rx' => ['<PPPoE-budi>' => 1.0], 'tx' => ['<PPPoE-budi>' => 2.0]];
        $now = $this->wib('2026-10-12 10:00:00');
        $this->travelTo($now);

        $this->service()->rollupDay($this->service()->dayStart($now), $now);

        $row = $this->rows()->first();
        $this->assertSame('budi', $row->pppoe_user);
        $this->assertSame($budi->id, (int) $row->customer_id);
    }

    public function test_labels_collapsing_to_one_user_are_summed(): void
    {
        $this->router(1);
        $this->fixtures[1] = [
            'rx' => ['<pppoe-abc>' => 10.0, 'abc' => 5.0],
            'tx' => ['<pppoe-abc>' => 100.0, 'abc' => 50.0],
        ];
        $now = $this->wib('2026-10-12 10:00:00');
        $this->travelTo($now);

        $this->service()->rollupDay($this->service()->dayStart($now), $now);

        $this->assertSame(1, DB::table('pppoe_usage_daily')->count());
        $row = $this->rows()->first();
        $this->assertSame('abc', $row->pppoe_user);
        $this->assertSame(150, (int) $row->download_bytes);
        $this->assertSame(15, (int) $row->upload_bytes);
    }

    public function test_non_bracket_labels_are_unchanged(): void
    {
        $this->assertSame('pppoe-abc', PppoeUsageRollup::normalizeUserLabel('pppoe-abc'));
        $this->assertSame('abc-1', PppoeUsageRollup::normalizeUserLabel(' abc-1 '));
        $this->assertSame('abc', PppoeUsageRollup::normalizeUserLabel('<abc>'));
        $this->assertSame('abc', PppoeUsageRollup::normalizeUserLabel('<pppoe-abc>'));
        $this->assertSame('abc-1', PppoeUsageRollup::normalizeUserLabel('<pppoe-abc-1>'));
    }

    public function test_cleanup_migration_removes_only_bracketed_rows(): void
    {
        $base = ['router_id' => 1, 'customer_id' => null, 'download_bytes' => 1, 'upload_bytes' => 1, 'is_final' => 0, 'quality' => 'ok', 'usage_date_wib' => '2026-10-11', 'created_at' => now(), 'updated_at' => now()];
        DB::table('pppoe_usage_daily')->insert([
            $base + ['pppoe_user' => '<pppoe-abc>'],
            $base + ['pppoe_user' => '<other>'],
            $base + ['pppoe_user' => 'abc'],
            $base + ['pppoe_user' => 'pppoe-keep'],
        ]);

        $migration = require base_path('database/migrations/2026_10_12_000002_purge_bracketed_pppoe_usage_daily_rows.php');
        $migration->up();

        $this->assertSame(['abc', 'pppoe-keep'], DB::table('pppoe_usage_daily')->orderBy('pppoe_user')->pluck('pppoe_user')->all());
    }

    public function test_command_skips_when_the_shared_lock_is_held(): void
    {
        $this->router(1);
        $this->fixtures[1] = ['rx' => ['a' => 1.0], 'tx' => ['a' => 2.0]];
        $this->travelTo($this->wib('2026-10-12 10:00:00'));
        $lock = Cache::lock('monitoring:pppoe-usage-rollup', 60);
        $this->assertTrue($lock->get());

        $this->assertSame(0, Artisan::call('monitoring:pppoe-usage-rollup'));
        $this->assertStringContainsString('dilewati', Artisan::output());
        $this->assertSame([], $this->exprs);
        $lock->release();

        Artisan::call('monitoring:pppoe-usage-rollup');
        $this->assertSame(1, DB::table('pppoe_usage_daily')->count());
    }

    public function test_command_rejects_dates_before_the_allowed_history(): void
    {
        $this->router(1);
        $this->travelTo($this->wib('2026-10-12 10:00:00'));

        foreach ([['--backfill-from' => '2026-09-30'], ['--finalize-date' => '2026-09-15']] as $opts) {
            $this->assertNotSame(0, Artisan::call('monitoring:pppoe-usage-rollup', $opts + ['--dry-run' => true]));
        }
        $this->assertSame([], $this->exprs);

        // Retention bound: 40 days before today.
        $this->travelTo($this->wib('2026-12-20 10:00:00'));
        $this->assertNotSame(0, Artisan::call('monitoring:pppoe-usage-rollup', ['--backfill-from' => '2026-10-15', '--dry-run' => true]));
    }

    public function test_command_finalize_backfill_and_date_validation(): void
    {
        $this->router(1);
        $this->fixtures[1] = ['rx' => ['a' => 1.0], 'tx' => ['a' => 2.0]];
        $this->travelTo($this->wib('2026-10-12 12:00:00'));

        $this->assertSame(0, Artisan::call('monitoring:pppoe-usage-rollup', ['--finalize' => true]));
        $this->assertSame(
            ['2026-10-11' => 1],
            DB::table('pppoe_usage_daily')->where('is_final', 1)->pluck('is_final', 'usage_date_wib')->map(fn ($v) => (int) $v)->all()
        );

        Artisan::call('monitoring:pppoe-usage-rollup', ['--backfill-from' => '2026-10-09']);
        $this->assertSame(
            ['2026-10-09' => 1, '2026-10-10' => 1, '2026-10-11' => 1, '2026-10-12' => 0],
            DB::table('pppoe_usage_daily')->orderBy('usage_date_wib')->pluck('is_final', 'usage_date_wib')->map(fn ($v) => (int) $v)->all()
        );

        foreach ([['--finalize-date' => '2026-10-12'], ['--finalize-date' => 'junk'], ['--backfill-from' => '2026-10-13']] as $opts) {
            $this->assertNotSame(0, Artisan::call('monitoring:pppoe-usage-rollup', $opts + ['--dry-run' => true]));
        }

        DB::table('pppoe_usage_daily')->delete();
        Artisan::call('monitoring:pppoe-usage-rollup', ['--backfill-from' => '2026-10-09', '--dry-run' => true]);
        $this->assertSame(0, DB::table('pppoe_usage_daily')->count());
    }

    public function test_schedule_is_registered_for_monitoring_modes_behind_the_admission_gate(): void
    {
        $source = (string) file_get_contents(base_path('routes/console.php'));
        $monitoringBlock = strpos($source, 'if ($monitoringSchedulerEnabled) {');
        $monitoringOnly = strpos($source, 'if ($monitoringSchedulerEnabled && ! $fullSchedulerEnabled)');
        $this->assertNotFalse($monitoringBlock);
        $this->assertNotFalse($monitoringOnly);

        foreach (["Schedule::command('monitoring:pppoe-usage-rollup')", "Schedule::command('monitoring:pppoe-usage-rollup --finalize')"] as $needle) {
            $this->assertSame(1, substr_count($source, $needle));
            $pos = strpos($source, $needle);
            $this->assertGreaterThan($monitoringBlock, $pos);
            $this->assertLessThan($monitoringOnly, $pos);
        }
        $this->assertMatchesRegularExpression(
            "/Schedule::command\('monitoring:pppoe-usage-rollup'\)\s*->cron\('14,29,44,59 \* \* \* \*'\)\s*->timezone\('Asia\/Jakarta'\)\s*->withoutOverlapping\(\d+\)\s*->onOneServer\(\)\s*->runInBackground\(\)\s*->when\(\\\$monitoringScheduleGate\)/s",
            $source
        );
        $this->assertMatchesRegularExpression(
            "/Schedule::command\('monitoring:pppoe-usage-rollup --finalize'\)\s*->dailyAt\('00:07'\)\s*->timezone\('Asia\/Jakarta'\)\s*->withoutOverlapping\(\d+\)\s*->onOneServer\(\)\s*->runInBackground\(\)\s*->when\(\\\$monitoringScheduleGate\)/s",
            $source
        );
        $this->assertStringNotContainsString('--backfill-from', preg_replace('/^\s*\/\/.*$/m', '', $source));

        // Safe mode (default) must not register it.
        Artisan::call('schedule:list', ['--no-ansi' => true]);
        $this->assertStringNotContainsString('monitoring:pppoe-usage-rollup', Artisan::output());
    }

    public function test_migration_creates_table_idempotently_with_unique_key(): void
    {
        $migration = require base_path('database/migrations/2026_10_11_000003_create_pppoe_usage_daily_table.php');
        $migration->up(); // already present via RefreshDatabase: must be a no-op

        $this->assertTrue(Schema::hasColumns('pppoe_usage_daily', [
            'usage_date_wib', 'router_id', 'pppoe_user', 'customer_id', 'download_bytes', 'upload_bytes', 'is_final', 'quality',
        ]));

        $row = ['usage_date_wib' => '2026-10-12', 'router_id' => 1, 'pppoe_user' => 'dup', 'customer_id' => null, 'created_at' => now(), 'updated_at' => now()];
        DB::table('pppoe_usage_daily')->insert($row);
        $this->assertSame(0, (int) DB::table('pppoe_usage_daily')->value('download_bytes'));
        $this->assertSame(0, (int) DB::table('pppoe_usage_daily')->value('is_final'));
        $this->assertSame('ok', DB::table('pppoe_usage_daily')->value('quality'));

        $this->expectException(QueryException::class);
        DB::table('pppoe_usage_daily')->insert($row);
    }
}
