<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Router;
use App\Models\Setting;
use App\Models\User;
use App\Services\Grafana\GrafanaClient;
use App\Services\Grafana\GrafanaQueryTemplates;
use App\Services\Monitoring\PppoeCustomerMatcher;
use App\Services\Monitoring\PppoeUsageRollup;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * M-4c-2a: router detail JSON API (overview, PPPoE table, daily history).
 * Synthetic data only.
 */
#[Group('blocking-core')]
class MonitoringRouterDetailTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'http://grafana.test:3001';

    private const TOKEN = 'glsa_SYNTHETIC_ROUTER_DETAIL_0000';

    /** @var array<string, list<array{0: array<string,string>, 1: float|list<float>}>> */
    private array $fixtures = [];

    /** @var list<string> */
    private array $sent = [];

    private bool $failAll = false;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->fixtures = [];
        $this->sent = [];
        $this->failAll = false;

        config(['services.grafana.allowed_hosts' => ['grafana.test']]);
        Setting::set(GrafanaClient::SETTING_URL, self::BASE);
        Setting::setSecret(GrafanaClient::SETTING_TOKEN, self::TOKEN);
        $this->travelTo(CarbonImmutable::parse('2026-10-12 12:00:00', 'Asia/Jakarta'));

        Http::fake(function (Request $r) {
            $expr = $r->data()['queries'][0]['expr'];
            $this->sent[] = $expr;
            if ($this->failAll) {
                return Http::response(['message' => 'denied '.self::TOKEN], 500);
            }

            return Http::response($this->frames($this->fixtures[$expr] ?? []));
        });
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** @param list<array{0: array<string,string>, 1: float|list<float>}> $series */
    private function frames(array $series): array
    {
        $frames = [];
        foreach ($series as [$labels, $values]) {
            $values = (array) $values;
            $times = [];
            foreach (array_keys($values) as $i) {
                $times[] = 1700000000000 + $i * 60000;
            }
            $frames[] = [
                'schema' => ['refId' => 'A', 'fields' => [
                    ['name' => 'Time', 'type' => 'time'],
                    ['name' => 'Value', 'type' => 'number', 'labels' => $labels],
                ]],
                'data' => ['values' => [$times, array_values($values)]],
            ];
        }

        return ['results' => ['A' => ['frames' => $frames]]];
    }

    /** @param list<array{0: array<string,string>, 1: float|list<float>}> $series */
    private function fx(string $template, array $params, array $series, string $range = '1h'): void
    {
        $this->fixtures[GrafanaQueryTemplates::resolve($template, $params, $range)['expr']] = $series;
    }

    /** Live per-user rates (Mbps): download comes from the DIRECTION constant. */
    private function live(int $routerId, array $users): void
    {
        $down = 'pppoe_user_'.PppoeUsageRollup::DIRECTION['download'].'_rate';
        $up = 'pppoe_user_'.PppoeUsageRollup::DIRECTION['upload'].'_rate';
        $downSeries = [];
        $upSeries = [];
        foreach ($users as $label => [$d, $u]) {
            $downSeries[] = [['user' => (string) $label, 'router_id' => (string) $routerId], $d];
            $upSeries[] = [['user' => (string) $label, 'router_id' => (string) $routerId], $u];
        }
        $this->fx($down, ['rid' => $routerId], $downSeries);
        $this->fx($up, ['rid' => $routerId], $upSeries);
    }

    private function router(int $id, bool $active = true, int $online = 0, int $secrets = 0): Router
    {
        $router = new Router([
            'name' => 'Site '.$id,
            'ip_address' => '192.0.2.'.($id % 250),
            'username' => 'u',
            'password' => 'x',
            'port' => 8728,
            'is_active' => $active,
            'current_online_count' => $online,
            'total_pppoe_count' => $secrets,
        ]);
        $router->id = $id;
        $router->save();

        return $router;
    }

    private function customer(string $pppoe, ?int $routerId, string $name = 'Cust', string $status = 'active'): Customer
    {
        $package = Package::query()->first()
            ?? Package::create(['name' => 'Pkg', 'code' => 'PKG-'.uniqid(), 'price' => 100000]);

        return Customer::create([
            'code' => 'C-'.uniqid(),
            'name' => $name,
            'phone' => '080000000000',
            'address' => 'Addr',
            'pppoe_user' => $pppoe,
            'package_id' => $package->id,
            'status' => $status,
            'router_id' => $routerId,
        ]);
    }

    private function usage(string $date, int $routerId, string $user, int $down, int $up, string $quality = 'ok', ?int $customerId = null): void
    {
        DB::table('pppoe_usage_daily')->insert([
            'usage_date_wib' => $date,
            'router_id' => $routerId,
            'pppoe_user' => $user,
            'customer_id' => $customerId,
            'download_bytes' => $down,
            'upload_bytes' => $up,
            'is_final' => true,
            'quality' => $quality,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function admin(string $role = 'superadmin'): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function api(int $routerId, string $path, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->admin())->getJson('/api/monitoring/routers/'.$routerId.'/'.$path);
    }

    /** @return array<string, array<string, mixed>> rows keyed by lowercase pppoe_user */
    private function rowsByUser(array $json): array
    {
        $out = [];
        foreach ($json['data']['rows'] as $row) {
            $out[mb_strtolower($row['pppoe_user'])] = $row;
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Access and validation
    // ------------------------------------------------------------------

    public function test_only_global_admins_can_read_the_three_endpoints(): void
    {
        $this->router(1);
        $urls = ['overview', 'pppoe', 'pppoe/daily?user=x'];

        $this->getJson('/api/monitoring/routers/1/overview')->assertUnauthorized();

        $scoped = $this->admin('admin');
        $scoped->areas()->attach(Area::create(['code' => 'A', 'name' => 'Area A']));
        foreach ([$this->admin('teknisi'), $scoped] as $denied) {
            foreach ($urls as $url) {
                $this->api(1, $url, $denied)->assertForbidden();
            }
        }
        foreach (['superadmin', 'noc', 'admin'] as $role) {
            $user = $this->admin($role);
            foreach ($urls as $url) {
                $this->api(1, $url, $user)->assertOk();
            }
        }
    }

    public function test_invalid_parameters_are_rejected_with_422(): void
    {
        $this->router(1);
        $user = $this->admin();

        foreach (['range=2h', 'range=', 'range[]=1h', 'range=1h;drop'] as $qs) {
            $this->api(1, 'overview?'.$qs, $user)->assertStatus(422);
        }
        foreach ([
            'status=bogus', 'sort=bogus', 'dir=sideways', 'per_page=5', 'per_page=101', 'per_page=abc',
            'page=0', 'q='.str_repeat('a', 65), 'q[]=a',
        ] as $qs) {
            $this->api(1, 'pppoe?'.$qs, $user)->assertStatus(422);
        }
        $this->api(1, 'pppoe/daily', $user)->assertStatus(422);
        $this->api(1, 'pppoe/daily?user='.str_repeat('a', 129), $user)->assertStatus(422);
    }

    public function test_edge_router_and_unknown_router_are_404(): void
    {
        $this->router(900);
        $user = $this->admin();
        foreach (['overview', 'pppoe', 'pppoe/daily?user=x'] as $url) {
            $this->api(900, $url, $user)->assertNotFound();
            $this->api(4242, $url, $user)->assertNotFound();
        }
        $this->assertSame([], $this->sent);
    }

    public function test_the_limiter_allows_120_requests_per_minute_per_user(): void
    {
        $this->router(1);
        $user = $this->admin();
        for ($i = 0; $i < 120; $i++) {
            $this->api(1, 'pppoe/daily?user=x', $user)->assertOk();
        }
        $this->api(1, 'pppoe/daily?user=x', $user)->assertStatus(429);
    }

    // ------------------------------------------------------------------
    // PPPoE table: live + customers
    // ------------------------------------------------------------------

    public function test_live_labels_are_normalized_linked_and_direction_follows_the_constant(): void
    {
        $this->router(1);
        $alice = $this->customer('alice01', 1, 'Alice Example');
        $this->live(1, [
            '<pppoe-Alice01>' => [8.0, 2.0],
            '<pppoe-ALICE01>' => [1.0, 0.5],
            'plainuser' => [3.0, 1.0],
        ]);

        $json = $this->api(1, 'pppoe')->assertOk()->assertJsonPath('status', 'ok')->json();
        $rows = $this->rowsByUser($json);

        $this->assertSame('tx', PppoeUsageRollup::DIRECTION['download']);
        $this->assertCount(2, $rows, 'case variants merge into one row');
        $this->assertSame($alice->id, $rows['alice01']['customer_id']);
        $this->assertSame('Alice Example', $rows['alice01']['name']);
        $this->assertSame('Alice01', $rows['alice01']['pppoe_user']);
        $this->assertSame('online', $rows['alice01']['status']);
        $this->assertEquals(9.0, $rows['alice01']['download_mbps']);
        $this->assertEquals(2.5, $rows['alice01']['upload_mbps']);
        $this->assertSame('unlinked', $rows['plainuser']['status']);
        $this->assertTrue($rows['plainuser']['is_online']);
        $this->assertNull($rows['plainuser']['customer_id']);
    }

    public function test_offline_isolated_unlinked_rows_and_counts(): void
    {
        $this->router(1);
        $this->customer('alice01', 1, 'Alice Example');
        $this->customer('bob01', 1, 'Bob Example');
        $this->customer('carol01', 1, 'Carol Example', 'isolated');
        $this->router(2);
        $this->customer('inactive01', 1, 'Gone Example', 'terminated');
        $this->customer('other01', 2, 'Other Router');
        $this->live(1, ['<pppoe-alice01>' => [1.0, 1.0], '<pppoe-stranger>' => [0.5, 0.5]]);
        $this->usage('2026-10-10', 1, 'ghost', 1_000_000_000, 0);

        $json = $this->api(1, 'pppoe')->assertOk()->json();
        $rows = $this->rowsByUser($json);

        $this->assertSame(['alice01', 'bob01', 'carol01', 'ghost', 'stranger'], collect(array_keys($rows))->sort()->values()->all());
        $this->assertSame('online', $rows['alice01']['status']);
        $this->assertSame('offline', $rows['bob01']['status']);
        $this->assertNull($rows['bob01']['download_mbps']);
        $this->assertSame('offline', $rows['carol01']['status']);
        $this->assertTrue($rows['carol01']['isolated']);
        $this->assertSame('unlinked', $rows['stranger']['status']);
        $this->assertSame('unlinked', $rows['ghost']['status']);
        $this->assertFalse($rows['ghost']['is_online']);
        $this->assertEquals(1.0, $rows['ghost']['month_gb']);
        $this->assertSame(
            ['all' => 5, 'online' => 1, 'offline' => 2, 'unlinked' => 2, 'unknown' => 0],
            $json['data']['meta']['counts']
        );

        $offline = $this->api(1, 'pppoe?status=offline')->json();
        $this->assertSame(2, $offline['data']['meta']['total']);
        $this->assertSame(5, $offline['data']['meta']['counts']['all'], 'counts ignore the status filter');
    }

    public function test_search_sort_and_paging(): void
    {
        $this->router(1);
        for ($i = 1; $i <= 12; $i++) {
            $n = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            $this->customer('user'.$n, 1, 'Cust '.$n);
        }
        $this->live(1, []);

        $page1 = $this->api(1, 'pppoe?sort=name&dir=desc&per_page=10')->assertOk()->json();
        $this->assertSame(12, $page1['data']['meta']['total']);
        $this->assertSame(2, $page1['data']['meta']['last_page']);
        $this->assertCount(10, $page1['data']['rows']);
        $this->assertSame('Cust 12', $page1['data']['rows'][0]['name']);

        $page2 = $this->api(1, 'pppoe?sort=name&dir=desc&per_page=10&page=2')->json();
        $this->assertCount(2, $page2['data']['rows']);
        $this->assertSame('Cust 02', $page2['data']['rows'][0]['name']);

        $clamped = $this->api(1, 'pppoe?per_page=10&page=99')->json();
        $this->assertSame(2, $clamped['data']['meta']['page']);

        $q = $this->api(1, 'pppoe?q='.urlencode('CUST 1'))->json();
        $this->assertSame(['Cust 10', 'Cust 11', 'Cust 12'], array_column($q['data']['rows'], 'name'));
        $byUser = $this->api(1, 'pppoe?q=user03')->json();
        $this->assertSame(1, $byUser['data']['meta']['total']);
        $this->assertSame(1, $byUser['data']['meta']['counts']['all']);
    }

    public function test_sort_by_rate_and_usage_columns(): void
    {
        $this->router(1);
        $this->live(1, ['<pppoe-aa>' => [1.0, 0.0], '<pppoe-bb>' => [9.0, 0.0], '<pppoe-cc>' => [5.0, 0.0]]);
        $this->usage('2026-10-12', 1, 'aa', 3_000_000_000, 0);
        $this->usage('2026-10-12', 1, 'bb', 1_000_000_000, 0);

        $byDown = $this->api(1, 'pppoe?sort=download_now&dir=desc')->json();
        $this->assertSame(['bb', 'cc', 'aa'], array_column($byDown['data']['rows'], 'pppoe_user'));
        $byToday = $this->api(1, 'pppoe?sort=today&dir=desc')->json();
        $this->assertSame('aa', $byToday['data']['rows'][0]['pppoe_user']);
    }

    // ------------------------------------------------------------------
    // History quality
    // ------------------------------------------------------------------

    public function test_invalid_days_are_excluded_from_totals_and_flagged_and_partial_is_counted(): void
    {
        $this->router(PppoeUsageRollup::KRIAN_ROUTER_ID);
        $id = PppoeUsageRollup::KRIAN_ROUTER_ID;
        $alice = $this->customer('alice01', $id, 'Alice Example');
        $this->live($id, ['<pppoe-alice01>' => [1.0, 1.0]]);
        $this->usage('2026-10-05', $id, 'alice01', 5_000_000_000, 5_000_000_000, 'invalid', $alice->id);
        $this->usage('2026-10-11', $id, 'alice01', 2_000_000_000, 1_000_000_000, 'ok', $alice->id);
        $this->usage('2026-10-12', $id, 'alice01', 1_000_000_000, 500_000_000, 'ok', $alice->id);
        $this->usage('2026-10-03', $id, 'bob01', 1_000_000_000, 0, 'partial');

        $rows = $this->rowsByUser($this->api($id, 'pppoe')->assertOk()->json());

        $this->assertEquals(4.5, $rows['alice01']['month_gb']);
        $this->assertEquals(1.5, $rows['alice01']['today_gb']);
        $this->assertTrue($rows['alice01']['has_invalid_days']);
        $this->assertFalse($rows['alice01']['history_partial']);
        // spark: 2026-10-06 .. 2026-10-12, the invalid day lies outside the window
        $this->assertEquals([0.0, 0.0, 0.0, 0.0, 0.0, 3.0, 1.5], $rows['alice01']['spark7']);

        $this->assertEquals(1.0, $rows['bob01']['month_gb']);
        $this->assertTrue($rows['bob01']['history_partial']);
        $this->assertFalse($rows['bob01']['has_invalid_days']);

        $overview = $this->api($id, 'overview')->assertOk()->json();
        $this->assertEquals(5.5, $overview['data']['kpi']['month_gb']);
        $this->assertTrue($overview['data']['kpi']['month_has_invalid_days']);
        $this->assertTrue($overview['data']['kpi']['month_history_partial']);
    }

    public function test_spark_marks_invalid_days_inside_the_window_as_null(): void
    {
        $this->router(1);
        $this->live(1, ['<pppoe-aa>' => [1.0, 1.0]]);
        $this->usage('2026-10-08', 1, 'aa', 9_000_000_000, 0, 'invalid');
        $this->usage('2026-10-09', 1, 'aa', 1_000_000_000, 0, 'ok');

        $rows = $this->rowsByUser($this->api(1, 'pppoe')->json());
        $this->assertSame([0.0, 0.0, null, 1.0, 0.0, 0.0, 0.0], array_map(
            static fn ($v) => $v === null ? null : (float) $v,
            $rows['aa']['spark7']
        ));
    }

    public function test_month_is_the_calendar_month_in_wib(): void
    {
        $this->router(1);
        $this->live(1, ['<pppoe-aa>' => [1.0, 1.0]]);
        $this->usage('2026-09-30', 1, 'aa', 7_000_000_000, 0);
        $this->usage('2026-10-01', 1, 'aa', 2_000_000_000, 0);

        $rows = $this->rowsByUser($this->api(1, 'pppoe')->json());
        $this->assertEquals(2.0, $rows['aa']['month_gb']);
    }

    // ------------------------------------------------------------------
    // Grafana outage and cache
    // ------------------------------------------------------------------

    public function test_grafana_outage_keeps_db_rows_and_history_without_leaking_errors(): void
    {
        $this->router(1);
        $alice = $this->customer('alice01', 1, 'Alice Example');
        $this->usage('2026-10-12', 1, 'alice01', 1_000_000_000, 0, 'ok', $alice->id);
        $this->failAll = true;

        $res = $this->api(1, 'pppoe')->assertOk()->assertJsonPath('status', 'unavailable');
        $json = $res->json();
        $this->assertFalse($json['data']['meta']['live']);
        $this->assertSame(
            ['all' => 1, 'online' => 0, 'offline' => 0, 'unlinked' => 0, 'unknown' => 1],
            $json['data']['meta']['counts']
        );
        $this->assertSame(1, $this->api(1, 'pppoe?status=unknown')->json('data.meta.total'));
        $rows = $this->rowsByUser($json);
        $this->assertSame('unknown', $rows['alice01']['status']);
        $this->assertEquals(1.0, $rows['alice01']['month_gb']);
        $this->assertNull($rows['alice01']['download_mbps']);
        $this->assertStringNotContainsString(self::TOKEN, json_encode($json));
        $this->assertStringNotContainsString('grafana.test', json_encode($json['reason']));

        $overview = $this->api(1, 'overview')->assertOk()->json();
        $this->assertSame('unavailable', $overview['status']);
        $this->assertCount(30, $overview['data']['daily']);
        $this->assertEquals(1.0, $overview['data']['kpi']['month_gb']);
        $this->assertNull($overview['data']['kpi']['download_now_mbps']);
        $this->assertStringNotContainsString(self::TOKEN, json_encode($overview));
    }

    public function test_unconfigured_grafana_reports_setup_required(): void
    {
        $this->router(1);
        $this->customer('alice01', 1);
        Setting::setSecret(GrafanaClient::SETTING_TOKEN, '');
        config(['services.grafana.token' => null]);

        $this->api(1, 'pppoe')->assertOk()->assertJsonPath('status', 'setup_required')
            ->assertJsonPath('data.meta.total', 1);
        $this->assertSame([], $this->sent);
    }

    public function test_live_data_is_cached_so_a_second_call_sends_no_http(): void
    {
        $this->router(1);
        $this->live(1, ['<pppoe-aa>' => [1.0, 1.0]]);

        $this->api(1, 'pppoe')->assertOk();
        $this->assertCount(2, $this->sent);
        $this->api(1, 'pppoe?status=online')->assertOk();
        $this->api(1, 'pppoe?sort=month')->assertOk();
        $this->assertCount(2, $this->sent, 'live rates are cached per router for 60 s');
    }

    // ------------------------------------------------------------------
    // Overview
    // ------------------------------------------------------------------

    public function test_overview_kpis_uplink_top_and_daily(): void
    {
        $this->router(1, true, 7, 120);
        $alice = $this->customer('alice01', 1, 'Alice Example');
        $this->live(1, [
            '<pppoe-alice01>' => [8.0, 2.0],
            '<pppoe-stranger>' => [4.0, 1.0],
            '<pppoe-idle>' => [0.0, 0.0],
        ]);
        $this->fx('pppoe_per_router', [], [
            [['router_id' => '1'], 42.0],
            [['router_id' => '4'], 9.0],
        ]);
        $this->fx('router_uplink_rx_range', ['rid' => 1], [
            [['interface' => 'a'], [10.0, 20.0]],
            [['interface' => 'b'], [5.0, 5.0]],
        ]);
        $this->fx('router_uplink_tx_range', ['rid' => 1], [[['interface' => 'a'], [1.0, 2.0]]]);
        $this->usage('2026-10-11', 1, 'alice01', 2_000_000_000, 1_000_000_000, 'ok', $alice->id);
        $this->usage('2026-10-12', 1, 'alice01', 1_000_000_000, 500_000_000, 'ok', $alice->id);
        $this->usage('2026-10-12', 1, 'stranger', 500_000_000, 0);

        $json = $this->api(1, 'overview?range=6h')->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('range', '6h')
            ->assertJsonPath('step_seconds', 120)
            ->json();

        $kpi = $json['data']['kpi'];
        $this->assertSame(42, $kpi['pppoe_online']);
        $this->assertSame('grafana', $kpi['pppoe_online_source']);
        $this->assertSame(120, $kpi['pppoe_secrets']);
        $this->assertEquals(12.0, $kpi['download_now_mbps']);
        $this->assertEquals(3.0, $kpi['upload_now_mbps']);
        $this->assertEquals(5.0, $kpi['month_gb']);

        $this->assertEquals([15.0, 25.0], array_column($json['data']['uplink']['rx'], 1));
        $this->assertEquals([1.0, 2.0], array_column($json['data']['uplink']['tx'], 1));

        $top = $json['data']['top'];
        $this->assertSame(['alice01', 'stranger'], array_column($top, 'pppoe_user'), 'idle users are left out');
        $this->assertSame('Alice Example', $top[0]['name']);
        $this->assertNull($top[1]['name']);

        $daily = $json['data']['daily'];
        $this->assertCount(30, $daily);
        $this->assertSame('2026-10-12', $daily[29]['date']);
        $this->assertSame('2026-09-13', $daily[0]['date']);
        $this->assertEquals(1.5, $daily[29]['download_gb']);
        $this->assertEquals(0.5, $daily[29]['upload_gb']);
        $this->assertSame('none', $daily[0]['quality']);
        $this->assertStringContainsString('/d/skynet-per-router', $json['grafana_dashboard_url']);
        $this->assertStringContainsString('from=now-6h', $json['grafana_dashboard_url']);
    }

    public function test_overview_falls_back_to_database_counts_when_prometheus_has_no_router_series(): void
    {
        $this->router(1, true, 7, 120);
        $this->live(1, []);

        $kpi = $this->api(1, 'overview')->assertOk()->json('data.kpi');
        $this->assertSame(7, $kpi['pppoe_online']);
        $this->assertSame('db', $kpi['pppoe_online_source']);
    }

    public function test_overview_daily_marks_invalid_days_as_null(): void
    {
        $id = PppoeUsageRollup::KRIAN_ROUTER_ID;
        $this->router($id);
        $this->live($id, []);
        $this->usage('2026-10-05', $id, 'aa', 4_000_000_000, 0, 'invalid');
        $this->usage('2026-10-05', $id, 'bb', 1_000_000_000, 0, 'invalid');
        $this->usage('2026-10-11', $id, 'aa', 1_000_000_000, 0, 'ok');

        $daily = collect($this->api($id, 'overview')->json('data.daily'))->keyBy('date');
        $this->assertSame('invalid', $daily['2026-10-05']['quality']);
        $this->assertNull($daily['2026-10-05']['download_gb']);
        $this->assertSame('ok', $daily['2026-10-11']['quality']);
        $this->assertEquals(1.0, $daily['2026-10-11']['download_gb']);
    }

    // ------------------------------------------------------------------
    // Daily drawer
    // ------------------------------------------------------------------

    public function test_daily_returns_30_days_for_a_user_case_insensitively(): void
    {
        $this->router(1);
        $alice = $this->customer('Alice01', 1, 'Alice Example');
        $this->usage('2026-10-12', 1, 'Alice01', 1_000_000_000, 500_000_000, 'ok', $alice->id);
        $this->usage('2026-10-08', 1, 'Alice01', 2_000_000_000, 0, 'invalid', $alice->id);
        $this->usage('2026-10-04', 1, 'Alice01', 1_000_000_000, 0, 'ok', $alice->id);
        $this->usage('2026-10-12', 1, 'someone-else', 9_000_000_000, 0);

        $json = $this->api(1, 'pppoe/daily?user='.urlencode('alice01'))->assertOk()->json('data');

        $this->assertTrue($json['found']);
        $this->assertCount(30, $json['days']);
        $this->assertSame('2026-10-12', $json['days'][29]['date']);
        $this->assertEquals(1.0, $json['days'][29]['download_gb']);
        $this->assertSame($alice->id, $json['customer_id']);
        $this->assertTrue($json['has_invalid_days']);
        $this->assertEquals(['download_gb' => 2.0, 'upload_gb' => 0.5], $json['totals']);

        $byDate = collect($json['days'])->keyBy('date');
        $this->assertSame('invalid', $byDate['2026-10-08']['quality']);
        $this->assertNull($byDate['2026-10-08']['download_gb']);
    }

    public function test_daily_accepts_usernames_with_special_characters_and_reports_unknown_users(): void
    {
        $this->router(1);
        $this->usage('2026-10-12', 1, 'a.b/c@example.test', 1_000_000_000, 0);

        $hit = $this->api(1, 'pppoe/daily?user='.urlencode('a.b/c@example.test'))->assertOk()->json('data');
        $this->assertTrue($hit['found']);
        $this->assertEquals(1.0, $hit['days'][29]['download_gb']);

        $miss = $this->api(1, 'pppoe/daily?user=nobody')->assertOk()->json('data');
        $this->assertFalse($miss['found']);
        $this->assertCount(30, $miss['days']);
    }

    // ------------------------------------------------------------------
    // Customer matcher (extracted from the rollup, behaviour unchanged)
    // ------------------------------------------------------------------

    public function test_matcher_exact_lowercase_late_bound_and_ambiguous(): void
    {
        $this->router(1);
        $this->router(2);
        $exact = $this->customer('Mixed01', 1);
        $late = $this->customer('latebound', null);

        $matcher = new PppoeCustomerMatcher;
        $this->assertSame($exact->id, $matcher->resolve(1, 'Mixed01'));
        $this->assertSame($exact->id, $matcher->resolve(1, 'mixed01'));
        $this->assertNull($matcher->resolve(2, 'mixed01'), 'bound to another router');
        $this->assertSame($late->id, $matcher->resolve(2, 'LATEBOUND'));
        $this->assertNull($matcher->resolve(1, 'nobody'));
    }

    // ------------------------------------------------------------------
    // Logging
    // ------------------------------------------------------------------

    public function test_usernames_are_never_logged(): void
    {
        $this->router(1);
        $this->customer('secretuser', 1, 'Secret Name');
        $this->live(1, ['<pppoe-secretuser>' => [1.0, 1.0], '<pppoe-secretstranger>' => [1.0, 1.0]]);
        $this->usage('2026-10-12', 1, 'secretuser', 1, 1);

        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged): void {
            $logged[] = $e->message.' '.json_encode($e->context);
        });

        $this->api(1, 'pppoe')->assertOk();
        $this->api(1, 'pppoe?q=secret')->assertOk();
        $this->api(1, 'overview')->assertOk();
        $this->api(1, 'pppoe/daily?user=secretuser')->assertOk();
        $this->failAll = true;
        Cache::flush();
        $this->api(1, 'pppoe')->assertOk();

        foreach ($logged as $line) {
            $this->assertStringNotContainsString('secretuser', $line);
            $this->assertStringNotContainsString('secretstranger', $line);
            $this->assertStringNotContainsString(self::TOKEN, $line);
        }
    }
}
