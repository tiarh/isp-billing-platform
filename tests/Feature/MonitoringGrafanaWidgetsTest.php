<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Setting;
use App\Models\User;
use App\Services\Grafana\GrafanaClient;
use App\Services\Grafana\GrafanaQueryTemplates;
use App\Services\Grafana\MonitoringWidgets;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * M-3: server-side Grafana widget endpoints. Synthetic token and dummy data only.
 */
#[Group('blocking-core')]
class MonitoringGrafanaWidgetsTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'glsa_SYNTHETIC_WIDGET_TOKEN_0000';

    private const BASE = 'http://grafana.test:3001';

    /** @var array<string, list<array{0: array<string,string>, 1: float|list<float>}>> */
    private array $fixtures = [];

    /** @var array<string, int> expr => http status to answer with */
    private array $failing = [];

    private bool $failAll = false;

    private bool $throwAll = false;

    /** @var list<string> */
    private array $sentExprs = [];

    /** @var list<string> */
    private array $sentModes = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->ensureSchema();
        config(['services.grafana.allowed_hosts' => ['grafana.test']]);
        Setting::set(GrafanaClient::SETTING_URL, self::BASE);
        Setting::setSecret(GrafanaClient::SETTING_TOKEN, self::TOKEN);

        Http::fake(function (Request $r) {
            $expr = $r->data()['queries'][0]['expr'];
            $this->sentExprs[] = $expr;
            $this->sentModes[] = $expr.'|'.($r->data()['queries'][0]['instant'] ? 'instant' : 'range');
            if ($this->throwAll) {
                throw new ConnectionException('cURL error 28 '.self::BASE.' Bearer '.self::TOKEN);
            }
            if ($this->failAll) {
                return Http::response(['message' => 'denied'], 401);
            }
            if (isset($this->failing[$expr])) {
                return Http::response(['message' => 'denied'], $this->failing[$expr]);
            }

            return Http::response($this->frames($this->fixtures[$expr] ?? []));
        });
    }

    protected function tearDown(): void
    {
        // Every query must use the mode (instant vs range) of a template with that expression.
        $allowed = [];
        foreach (GrafanaQueryTemplates::all() as $name => $def) {
            if ($def['params'] !== []) {
                continue;
            }
            foreach (array_keys(GrafanaQueryTemplates::RANGES) as $rng) {
                $allowed[GrafanaQueryTemplates::resolve($name, [], $rng)['expr'].'|'.$def['mode']] = true;
            }
        }
        foreach ($this->sentModes as $m) {
            $this->assertArrayHasKey($m, $allowed, 'Query sent with a mode that no template defines: '.$m);
        }
        parent::tearDown();
    }

    // TODO(M-2 merged): remove ensureSchema once the M-2 migration is on main.
    /** Create the M-2 contract schema when that migration is not merged yet. */
    private function ensureSchema(): void
    {
        $ddl = false;
        if (! Schema::hasTable('monitoring_upstream_links')) {
            Schema::create('monitoring_upstream_links', function (Blueprint $table): void {
                $table->id();
                $table->string('upstream_key', 32)->unique();
                $table->string('display_name');
                $table->string('kind');
                $table->string('router_label')->default('Jakarta-Edge');
                $table->string('interface_name');
                $table->unsignedInteger('capacity_mbps')->nullable();
                $table->integer('sort')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
            $ddl = true;
        }
        if (! Schema::hasColumn('routers', 'grafana_identity')) {
            Schema::table('routers', function (Blueprint $table): void {
                $table->string('grafana_identity', 96)->nullable();
            });
            $ddl = true;
        }
        if ($ddl) {
            // MySQL commits implicitly on DDL; reopen the transaction so RefreshDatabase still rolls back.
            $pdo = DB::connection()->getPdo();
            if (! $pdo->inTransaction()) {
                $pdo->beginTransaction();
            }
        }
        DB::table('monitoring_upstream_links')->delete();
    }

    // ------------------------------------------------------------------
    // Fixture helpers
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
    private function fx(string $template, array $series, string $range = '1h'): void
    {
        $this->fixtures[GrafanaQueryTemplates::resolve($template, [], $range)['expr']] = $series;
    }

    private function expr(string $template, string $range = '1h'): string
    {
        return GrafanaQueryTemplates::resolve($template, [], $range)['expr'];
    }

    private function seedRouters(): void
    {
        $now = now();
        $base = ['ip_address' => '192.0.2.1', 'port' => 8728, 'username' => 'u', 'password' => 'x', 'grafana_identity' => null, 'created_at' => $now, 'updated_at' => $now];
        DB::table('routers')->insert([
            array_merge($base, ['id' => 1, 'name' => 'Randuagung', 'is_active' => true, 'current_online_count' => 10, 'total_pppoe_count' => 100]),
            array_merge($base, ['id' => 4, 'name' => 'Martopuro', 'is_active' => true, 'current_online_count' => 5, 'total_pppoe_count' => 50, 'grafana_identity' => 'MARTO-RB']),
            array_merge($base, ['id' => 8, 'name' => 'Karangploso', 'is_active' => true, 'current_online_count' => 3, 'total_pppoe_count' => 30]),
            array_merge($base, ['id' => 16, 'name' => 'Kendit', 'is_active' => true, 'current_online_count' => 2, 'total_pppoe_count' => 20]),
            array_merge($base, ['id' => 12, 'name' => 'Retired Site', 'is_active' => false, 'current_online_count' => 9, 'total_pppoe_count' => 90]),
        ]);
    }

    private function seedLinks(): void
    {
        $now = now();
        $row = fn (string $key, string $name, string $kind, ?int $cap, int $sort, bool $active = true): array => [
            'upstream_key' => $key, 'display_name' => $name, 'kind' => $kind, 'router_label' => 'Jakarta-Edge',
            'interface_name' => 'if-'.$key, 'capacity_mbps' => $cap, 'sort' => $sort, 'is_active' => $active,
            'created_at' => $now, 'updated_at' => $now,
        ];
        DB::table('monitoring_upstream_links')->insert([
            $row('THC', 'THC', 'transit', 2000, 1),
            $row('TLN', 'TLN', 'ix', null, 2),
            $row('JKTIX', 'JKTIX', 'ix', 10000, 3),
            $row('IIX', 'IIX', 'ix', 10000, 4, false),
            $row('N3K_TOTAL', 'Total N3K', 'aggregate', null, 5),
        ]);
    }

    private function admin(string $role = 'superadmin'): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function widget(string $widget, string $range = '1h', ?User $user = null)
    {
        return $this->actingAs($user ?? $this->admin())->getJson('/api/monitoring/widgets/'.$widget.'?range='.$range);
    }

    private function unconfigure(): void
    {
        Setting::setSecret(GrafanaClient::SETTING_TOKEN, '');
        config(['services.grafana.token' => null]);
    }

    // ------------------------------------------------------------------
    // Permissions and request handling
    // ------------------------------------------------------------------

    public function test_global_admins_get_200_and_others_are_refused(): void
    {
        $this->unconfigure();

        foreach (['superadmin', 'noc', 'admin'] as $role) {
            $this->widget('summary', '1h', $this->admin($role))->assertOk();
        }

        $scoped = $this->admin('admin');
        $scoped->areas()->attach(Area::create(['code' => 'A', 'name' => 'Area A']));
        $this->widget('summary', '1h', $scoped)->assertForbidden();
        $this->widget('summary', '1h', $this->admin('teknisi'))->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/api/monitoring/widgets/summary')->assertRedirect('/login');
    }

    public function test_unknown_widget_is_404_and_bad_range_is_422(): void
    {
        $this->unconfigure();
        $user = $this->admin();

        $this->actingAs($user)->getJson('/api/monitoring/widgets/nope')->assertNotFound();
        foreach (['2h', '', 'abc', '1h;drop'] as $bad) {
            $this->actingAs($user)->getJson('/api/monitoring/widgets/summary?range='.urlencode($bad))->assertStatus(422);
        }
        $this->actingAs($user)->getJson('/api/monitoring/widgets/summary?range[]=1h')->assertStatus(422);
    }

    public function test_range_defaults_to_1h(): void
    {
        $this->unconfigure();

        $this->actingAs($this->admin())->getJson('/api/monitoring/widgets/summary')
            ->assertOk()->assertJsonPath('range', '1h')->assertJsonPath('step_seconds', 60);
    }

    public function test_expr_query_parameters_are_ignored_and_only_template_exprs_are_sent(): void
    {
        $this->seedRouters();
        $this->seedLinks();

        foreach (MonitoringWidgets::WIDGETS as $w) {
            $this->actingAs($this->admin())->getJson('/api/monitoring/widgets/'.$w.'?range=1h&expr=up&query=sum(up)&template=health_up&rid=1')->assertOk();
        }

        $allowed = [];
        foreach (GrafanaQueryTemplates::all() as $name => $def) {
            if ($def['params'] === []) {
                $allowed[] = GrafanaQueryTemplates::resolve($name, [], '1h')['expr'];
            }
        }
        $this->assertNotEmpty($this->sentExprs);
        foreach ($this->sentExprs as $expr) {
            $this->assertContains($expr, $allowed);
        }
        $this->assertNotContains('up', $this->sentExprs);
        $this->assertNotContains('sum(up)', $this->sentExprs);
    }

    public function test_grafana_not_configured_returns_setup_required_and_sends_nothing(): void
    {
        $this->seedRouters();
        $this->seedLinks();
        $this->unconfigure();

        foreach (MonitoringWidgets::WIDGETS as $w) {
            $this->widget($w)->assertOk()->assertJsonPath('status', 'setup_required')->assertJsonPath('widget', $w);
        }
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------
    // summary / routers / router-traffic
    // ------------------------------------------------------------------

    public function test_summary_sums_only_ebilling_active_ids(): void
    {
        $this->seedRouters();
        $this->seedLinks();
        $this->fx('pppoe_per_router', [
            [['router_id' => '1'], 120.0], [['router_id' => '4'], 30.0],
            [['router_id' => '900'], 5.0], [['router_id' => '513'], 1000.0], [['router_id' => '12'], 7.0],
        ]);
        $this->fx('collector_scrape_up', [
            [['router_id' => '1'], 1.0], [['router_id' => '4'], 1.0], [['router_id' => '8'], 0.0], [['router_id' => '513'], 1.0],
        ]);
        $this->fx('router_uplink_mbps', [
            [['router_id' => '1', 'router' => 'Randuagung', 'direction' => 'rx'], 100.04],
            [['router_id' => '1', 'router' => 'Randuagung', 'direction' => 'tx'], 50.0],
            [['router_id' => '4', 'router' => 'Martopuro', 'direction' => 'rx'], 20.0],
            [['router_id' => '900', 'router' => 'Jakarta-Edge', 'direction' => 'rx'], 5000.0],
            [['router_id' => '513', 'router' => 'X', 'direction' => 'rx'], 999.0],
        ]);
        $this->fx('upstream_rx_all', [[['upstream' => 'THC'], 700.0], [['upstream' => 'TLN'], 300.0], [['upstream' => 'N3K_TOTAL'], 3000.0]]);
        $this->fx('upstream_tx_all', [[['upstream' => 'THC'], 50.0], [['upstream' => 'TLN'], 10.0], [['upstream' => 'N3K_TOTAL'], 900.0]]);
        $this->fx('collector_targets_up', [
            [['job' => 'mktxp_106', 'instance' => 'a'], 1.0],
            [['job' => 'blackbox_exporter', 'instance' => 'b'], 0.0],
        ]);

        $r = $this->widget('summary')->assertOk();

        // JKTIX has no upstream data, so the summary is flagged partial
        $r->assertJsonPath('status', 'stale')->assertJsonPath('partial', true);
        $d = $r->json('data');
        $this->assertEquals(['online' => 2, 'total' => 4, 'scraped' => 3], $d['routers']);
        $this->assertEquals(['total' => 155, 'source' => 'mixed'], $d['pppoe']);
        $this->assertEquals(['rx_mbps' => 120.0, 'tx_mbps' => 50.0], $d['traffic']);
        // aggregate (N3K_TOTAL) excluded; capacity 2000 (THC) + unknown (TLN) => incomplete, no utilization
        $this->assertEquals(1000.0, $d['upstream']['rx_mbps']);
        $this->assertEquals(60.0, $d['upstream']['tx_mbps']);
        $this->assertFalse($d['upstream']['capacity_complete']);
        $this->assertNull($d['upstream']['utilization_pct']);
        $this->assertEquals([['job' => 'blackbox_exporter', 'up' => false], ['job' => 'mktxp_106', 'up' => true]], $d['collectors']);
    }

    public function test_summary_utilization_when_capacity_is_complete(): void
    {
        $this->seedRouters();
        $this->seedLinks();
        DB::table('monitoring_upstream_links')->where('upstream_key', 'TLN')->update(['capacity_mbps' => 10000]);
        $this->fx('upstream_rx_all', [[['upstream' => 'THC'], 700.0], [['upstream' => 'TLN'], 300.0]]);
        $this->fx('upstream_tx_all', [[['upstream' => 'THC'], 50.0]]);

        $u = $this->widget('summary')->json('data.upstream');

        $this->assertTrue($u['capacity_complete']);
        $this->assertEquals(22000.0, $u['capacity_mbps']);
        $this->assertEquals(4.5, $u['utilization_pct']); // 1000 / 22000 * 100 (JKTIX 10000 + THC 2000 + TLN 10000)
    }

    public function test_summary_falls_back_to_db_when_grafana_is_unavailable(): void
    {
        $this->seedRouters();
        $this->failAll = true;

        $r = $this->widget('summary')->assertOk();

        $r->assertJsonPath('status', 'unavailable');
        $this->assertEquals(['total' => 20, 'source' => 'db'], $r->json('data.pppoe')); // 10+5+3+2, retired router excluded
        $this->assertEquals(4, $r->json('data.routers.total'));
        $this->assertNull($r->json('data.routers.online'));
    }

    public function test_routers_widget_lists_unscraped_and_hides_inactive_routers(): void
    {
        $this->seedRouters();
        $this->fx('pppoe_per_router', [[['router_id' => '1'], 120.0], [['router_id' => '12'], 7.0], [['router_id' => '900'], 1.0]]);
        $this->fx('collector_scrape_up', [[['router_id' => '1'], 1.0], [['router_id' => '4'], 1.0], [['router_id' => '8'], 0.0]]);
        $this->fx('router_uplink_mbps', [
            [['router_id' => '1', 'router' => 'Randuagung', 'direction' => 'rx'], 80.26],
            [['router_id' => '1', 'router' => 'Randuagung', 'direction' => 'tx'], 12.0],
        ]);
        $this->fx('res_cpu', [[['routerboard_name' => 'Randuagung'], 17.0]]);
        $this->fx('res_memory_pct', [[['routerboard_name' => 'Randuagung'], 41.55]]);
        $this->fx('res_uptime', [[['routerboard_name' => 'Randuagung'], 86400.0]]);

        $rows = collect($this->widget('routers')->assertOk()->json('data.rows'))->keyBy('router_id');

        $this->assertEquals([1, 4, 8, 16], $rows->keys()->sort()->values()->all());
        $this->assertFalse($rows->has(12));
        $this->assertTrue($rows[1]['scraped']);
        $this->assertEquals(120, $rows[1]['pppoe_online']);
        $this->assertEquals('grafana', $rows[1]['pppoe_source']);
        $this->assertEquals(100, $rows[1]['pppoe_secrets']);
        $this->assertEquals(80.3, $rows[1]['rx_mbps']);
        $this->assertEquals(12.0, $rows[1]['tx_mbps']);
        $this->assertEquals(17.0, $rows[1]['cpu_pct']);
        $this->assertEquals(86400, $rows[1]['uptime_seconds']);
        $this->assertFalse($rows[8]['scraped']);   // series present but scrape_up = 0
        $this->assertFalse($rows[16]['scraped']);  // no series at all
        $this->assertEquals(2, $rows[16]['pppoe_online']);
        $this->assertEquals('db', $rows[16]['pppoe_source']);
        $this->assertNull($rows[16]['cpu_pct']);
    }

    public function test_router_traffic_returns_rx_tx_series_per_active_router(): void
    {
        $this->seedRouters();
        $this->fx('router_uplink_mbps_range', [
            [['router_id' => '1', 'router' => 'Randuagung', 'direction' => 'rx'], [10.04, 20.0]],
            [['router_id' => '1', 'router' => 'Randuagung', 'direction' => 'tx'], [1.0, 2.0]],
            [['router_id' => '12', 'router' => 'Retired', 'direction' => 'rx'], [5.0]],
            [['router_id' => '900', 'router' => 'Jakarta-Edge', 'direction' => 'rx'], [5.0]],
        ], '6h');

        $r = $this->widget('router-traffic', '6h')->assertOk();

        $r->assertJsonPath('step_seconds', 120);
        $series = $r->json('data.series');
        $this->assertCount(1, $series);
        $this->assertEquals(1, $series[0]['router_id']);
        $this->assertEquals([[1700000000000, 10.0], [1700000060000, 20.0]], $series[0]['rx']);
        $this->assertEquals([[1700000000000, 1.0], [1700000060000, 2.0]], $series[0]['tx']);
        Http::assertSent(fn (Request $q) => $q->data()['queries'][0]['intervalMs'] === 120000);
    }

    // ------------------------------------------------------------------
    // upstream
    // ------------------------------------------------------------------

    public function test_upstream_values_degraded_null_capacity_and_missing(): void
    {
        $this->seedRouters();
        $this->seedLinks();
        $range = '30d';
        $this->fx('upstream_rx_all', [[['upstream' => 'THC'], 1700.0], [['upstream' => 'TLN'], 300.44]], $range);
        $this->fx('upstream_tx_all', [[['upstream' => 'THC'], 100.0], [['upstream' => 'TLN'], 20.0]], $range);
        $this->fx('upstream_rx_range', [[['upstream' => 'THC'], [1600.0, 1700.0]], [['upstream' => 'TLN'], [300.44]]], $range);
        $this->fx('upstream_tx_range', [[['upstream' => 'THC'], [90.0, 100.0]]], $range);
        $this->fx('upstream_rx_p95', [[['upstream' => 'THC'], 1650.44]], $range);
        $this->fx('upstream_rx_peak', [[['upstream' => 'THC'], 1900.0]], $range);
        $this->fx('upstream_rx_avg', [[['upstream' => 'THC'], 1200.0]], $range);
        $this->fx('upstream_tx_p95', [[['upstream' => 'THC'], 110.0]], $range);
        $this->fx('upstream_tx_peak', [[['upstream' => 'THC'], 150.0]], $range);
        $this->fx('upstream_tx_avg', [[['upstream' => 'THC'], 80.0]], $range);

        $r = $this->widget('upstream', $range)->assertOk();

        $r->assertJsonPath('status', 'ok')->assertJsonPath('step_seconds', 3600);
        $links = collect($r->json('data.links'))->keyBy('key');
        $this->assertEquals(['THC', 'TLN', 'JKTIX', 'N3K_TOTAL'], $links->keys()->all()); // IIX inactive, ordered by sort

        $thc = $links['THC'];
        $this->assertEquals('THC', $thc['display_name']);
        $this->assertEquals(['rx' => 1700.0, 'tx' => 100.0], $thc['now']);
        $this->assertEquals(['rx' => 1650.4, 'tx' => 110.0], $thc['p95']);
        $this->assertEquals(['rx' => 1900.0, 'tx' => 150.0], $thc['peak']);
        $this->assertEquals(['rx' => 1200.0, 'tx' => 80.0], $thc['avg']);
        $this->assertEquals(85.0, $thc['utilization_pct']);
        $this->assertTrue($thc['degraded']);
        $this->assertFalse($thc['missing']);
        $this->assertEquals([[1700000000000, 1600.0], [1700000060000, 1700.0]], $thc['series']['rx']);

        $this->assertNull($links['TLN']['capacity_mbps']);
        $this->assertNull($links['TLN']['utilization_pct']);
        $this->assertFalse($links['TLN']['degraded']);
        $this->assertEquals(300.4, $links['TLN']['now']['rx']);

        $this->assertTrue($links['JKTIX']['missing']);
        $this->assertFalse($links['JKTIX']['degraded']);
        $this->assertNull($links['JKTIX']['now']['rx']);

        Http::assertSent(fn (Request $q) => $q->data()['queries'][0]['intervalMs'] === 3600000
            && $q->data()['queries'][0]['expr'] === 'skynet:upstream_rx_mbps:rate5m' && $q->data()['queries'][0]['range'] === true);
        Http::assertSent(fn (Request $q) => str_contains($q->data()['queries'][0]['expr'], '[30d]'));
    }

    public function test_upstream_at_exactly_80_percent_is_not_degraded(): void
    {
        $this->seedLinks();
        $this->fx('upstream_rx_all', [[['upstream' => 'THC'], 1600.0]]);

        $thc = collect($this->widget('upstream')->json('data.links'))->firstWhere('key', 'THC');

        $this->assertEquals(80.0, $thc['utilization_pct']);
        $this->assertFalse($thc['degraded']);
    }

    public function test_upstream_without_links_is_setup_required(): void
    {
        $this->seedRouters();

        $r = $this->widget('upstream')->assertOk();

        $r->assertJsonPath('status', 'setup_required');
        $this->assertEquals([], $r->json('data.links'));
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------
    // resources
    // ------------------------------------------------------------------

    public function test_resources_join_order_router_id_then_identity_then_name(): void
    {
        $this->seedRouters();
        $this->fx('res_cpu', [
            // router_id label wins even when routerboard_name points at another router
            [['router_id' => '1', 'routerboard_name' => 'MARTO-RB'], 12.0],
            [['routerboard_name' => 'MARTO-RB'], 30.0],        // grafana_identity => router 4
            [['routerboard_name' => 'KARANGPLOSO'], 5.0],      // case-insensitive name => router 8
            [['routerboard_name' => 'Unknown-Box'], 99.0],     // dropped
            [['router_id' => '513', 'routerboard_name' => 'X'], 98.0], // not an eBilling router
            [['router_id' => '12', 'routerboard_name' => 'Retired Site'], 97.0], // inactive
        ]);
        $this->fx('res_memory_pct', [[['router_id' => '1'], 40.04]]);
        $this->fx('res_disk_pct', [[['router_id' => '1'], 11.0]]);
        $this->fx('res_uptime', [[['router_id' => '1'], 3600.0]]);
        $this->fx('res_board_temp', [[['router_id' => '1'], 44.0]]);
        $this->fx('res_cpu_temp', [[['router_id' => '1'], 51.0]]);
        $this->fx('res_voltage', [[['router_id' => '1'], 24.1]]);

        $r = $this->widget('resources')->assertOk();

        $r->assertJsonPath('status', 'ok');
        $rows = collect($r->json('data.rows'))->keyBy('router_id');
        $this->assertCount(4, $rows);
        $this->assertEquals(12.0, $rows[1]['cpu_pct']);
        $this->assertEquals('router_id', $rows[1]['matched_by']);
        $this->assertEquals(40.0, $rows[1]['memory_pct']);
        $this->assertEquals(11.0, $rows[1]['disk_pct']);
        $this->assertEquals(3600, $rows[1]['uptime_seconds']);
        $this->assertEquals(44.0, $rows[1]['board_temp_c']);
        $this->assertEquals(51.0, $rows[1]['cpu_temp_c']);
        $this->assertEquals(24.1, $rows[1]['voltage_v']);
        $this->assertEquals(30.0, $rows[4]['cpu_pct']);
        $this->assertEquals('grafana_identity', $rows[4]['matched_by']);
        $this->assertNull($rows[4]['memory_pct']);
        $this->assertEquals(5.0, $rows[8]['cpu_pct']);
        $this->assertEquals('name', $rows[8]['matched_by']);
        $this->assertNull($rows[16]['cpu_pct']);
        $this->assertNull($rows[16]['matched_by']);
    }

    // ------------------------------------------------------------------
    // ping
    // ------------------------------------------------------------------

    public function test_ping_states_ms_conversion_icmp_blocked_and_inactive_routers_dropped(): void
    {
        $this->seedRouters();
        $this->fx('ping_rtt', [
            [['target_name' => 'THC', 'kind' => 'upstream', 'router_id' => '900'], 0.0213],
            [['target_name' => 'Resolver', 'kind' => 'internet'], 0.02],
            [['target_name' => 'Randuagung', 'kind' => 'router', 'router_id' => '1'], 0.15],
            [['target_name' => 'Retired Site', 'kind' => 'router', 'router_id' => '12'], 0.01],
        ]);
        $this->fx('ping_loss_pct', [
            [['target_name' => 'THC', 'kind' => 'upstream', 'router_id' => '900'], 0.0],
            [['target_name' => 'Resolver', 'kind' => 'internet'], 3.0],
            [['target_name' => 'Randuagung', 'kind' => 'router', 'router_id' => '1'], 0.0],
            [['target_name' => 'Kendit', 'kind' => 'router', 'router_id' => '16'], 100.0],
            [['target_name' => 'Karangploso', 'kind' => 'router', 'router_id' => '8'], 100.0],
            [['target_name' => 'Retired Site', 'kind' => 'router', 'router_id' => '12'], 100.0],
        ]);
        $this->fx('collector_scrape_up', [[['router_id' => '16'], 1.0], [['router_id' => '8'], 0.0]]);
        $this->fx('ping_rtt_range', [
            [['target_name' => 'THC', 'kind' => 'upstream', 'router_id' => '900'], [0.02, 0.03]],
            [['target_name' => 'Randuagung', 'kind' => 'router', 'router_id' => '1'], [0.1]],
        ]);
        $this->fx('ping_loss_range', [[['target_name' => 'THC', 'kind' => 'upstream'], [0.0, 50.0]]]);

        $r = $this->widget('ping')->assertOk();

        $r->assertJsonPath('status', 'ok');
        $targets = collect($r->json('data.targets'))->keyBy('target_name');
        $this->assertFalse($targets->has('Retired Site'));
        $this->assertEquals(21.3, $targets['THC']['rtt_ms']);
        $this->assertEquals('up', $targets['THC']['state']);
        $this->assertEquals('degraded', $targets['Resolver']['state']);      // loss 3%
        $this->assertEquals('degraded', $targets['Randuagung']['state']);    // rtt 150 ms
        $this->assertEquals('Randuagung', $targets['Randuagung']['router_name']);
        $this->assertEquals('icmp_blocked', $targets['Kendit']['state']);    // probe fails, API scrape up
        $this->assertNull($targets['Kendit']['rtt_ms']);
        $this->assertEquals('down', $targets['Karangploso']['state']);       // probe fails, scrape down
        $this->assertEquals(['rtt' => [['target_name' => 'THC', 'kind' => 'upstream', 'points' => [[1700000000000, 20.0], [1700000060000, 30.0]]]],
            'loss' => [['target_name' => 'THC', 'kind' => 'upstream', 'points' => [[1700000000000, 0.0], [1700000060000, 50.0]]]]], $r->json('data.series'));
    }

    public function test_ping_zero_rtt_from_failed_probe_is_down_or_icmp_blocked_never_up(): void
    {
        $this->seedRouters();
        $this->fx('ping_rtt', [
            [['target_name' => 'Dead', 'kind' => 'upstream', 'router_id' => '900'], 0.0],
            [['target_name' => 'Kendit', 'kind' => 'router', 'router_id' => '16'], 0.0],
            [['target_name' => 'Karangploso', 'kind' => 'router', 'router_id' => '8'], 0.0],
            [['target_name' => 'Fine', 'kind' => 'upstream', 'router_id' => '900'], 0.02],
        ]);
        $this->fx('collector_scrape_up', [[['router_id' => '16'], 1.0]]);
        $this->fx('ping_rtt_range', [[['target_name' => 'Dead', 'kind' => 'upstream'], [0.0, 0.02]]]);
        // loss data only for one target; the others have none
        $this->fx('ping_loss_pct', [[['target_name' => 'Fine', 'kind' => 'upstream', 'router_id' => '900'], 0.0]]);

        $r = $this->widget('ping')->assertOk();

        $t = collect($r->json('data.targets'))->keyBy('target_name');
        $this->assertSame('down', $t['Dead']['state']);
        $this->assertNull($t['Dead']['rtt_ms']);
        $this->assertSame('icmp_blocked', $t['Kendit']['state']);
        $this->assertSame('down', $t['Karangploso']['state']);
        $this->assertSame('up', $t['Fine']['state']);
        $this->assertNull($r->json('data.series.rtt.0.points.0.1'));
        $this->assertEquals(20.0, $r->json('data.series.rtt.0.points.1.1'));
    }

    public function test_ping_router_with_live_mktxp_cpu_is_icmp_blocked_even_when_scrape_up_is_zero(): void
    {
        $this->seedRouters();
        $this->fx('ping_rtt', [
            [['target_name' => 'Kendit', 'kind' => 'router', 'router_id' => '16'], 0.0],
            [['target_name' => 'Karangploso', 'kind' => 'router', 'router_id' => '8'], 0.0],
        ]);
        $this->fx('ping_loss_pct', [
            [['target_name' => 'Kendit', 'kind' => 'router', 'router_id' => '16'], 100.0],
            [['target_name' => 'Karangploso', 'kind' => 'router', 'router_id' => '8'], 100.0],
        ]);
        $this->fx('collector_scrape_up', [[['router_id' => '16'], 0.0]]);
        $this->fx('res_cpu', [[['router_id' => '16', 'routerboard_name' => 'Kendit'], 9.0]]);

        $r = $this->widget('ping')->assertOk();

        $t = collect($r->json('data.targets'))->keyBy('target_name');
        $this->assertSame('icmp_blocked', $t['Kendit']['state']);
        $this->assertSame('down', $t['Karangploso']['state']);
    }

    public function test_ping_router_without_scrape_up_or_cpu_series_is_down(): void
    {
        $this->seedRouters();
        $this->fx('ping_rtt', [[['target_name' => 'Kendit', 'kind' => 'router', 'router_id' => '16'], 0.0]]);
        $this->fx('ping_loss_pct', [[['target_name' => 'Kendit', 'kind' => 'router', 'router_id' => '16'], 100.0]]);

        $r = $this->widget('ping')->assertOk();

        $this->assertSame('down', $r->json('data.targets.0.state'));
    }

    public function test_ping_zero_rtt_with_failing_loss_query_is_still_down(): void
    {
        $this->seedRouters();
        $this->fx('ping_rtt', [[['target_name' => 'Dead', 'kind' => 'upstream', 'router_id' => '900'], 0.0]]);
        $this->failing[$this->expr('ping_loss_pct')] = 401;

        $r = $this->widget('ping')->assertOk();

        $this->assertSame('down', $r->json('data.targets.0.state'));
        $r->assertJsonPath('status', 'stale');
    }

    public function test_summary_pppoe_mixes_grafana_and_db_per_router(): void
    {
        $this->seedRouters();
        $this->fx('pppoe_per_router', [[['router_id' => '1'], 120.0], [['router_id' => '4'], 30.0]]);

        $p = $this->widget('summary')->json('data.pppoe');

        // 120 + 30 from Grafana, Karangploso 3 + Kendit 2 from DB
        $this->assertSame(['total' => 155, 'source' => 'mixed'], $p);
    }

    public function test_upstream_all_links_missing_is_setup_required(): void
    {
        $this->seedLinks();

        $this->widget('upstream')->assertOk()->assertJsonPath('status', 'setup_required');
    }

    public function test_upstream_outage_with_links_is_unavailable_not_setup_required(): void
    {
        $this->seedLinks();
        $this->failAll = true;

        $r = $this->widget('upstream')->assertOk();

        $r->assertJsonPath('status', 'unavailable');
        $this->assertCount(4, $r->json('data.links'));
    }

    public function test_upstream_open_breaker_with_links_is_unavailable_and_sends_no_http(): void
    {
        $this->seedLinks();
        Cache::put('grafana.cb.open', true, 60);

        $this->widget('upstream')->assertOk()->assertJsonPath('status', 'unavailable');
        Http::assertNothingSent();
    }

    public function test_summary_flags_partial_when_an_upstream_link_has_no_data(): void
    {
        $this->seedRouters();
        $this->seedLinks();
        $this->fx('upstream_rx_all', [[['upstream' => 'THC'], 700.0]]);

        $r = $this->widget('summary')->assertOk();

        $r->assertJsonPath('partial', true)->assertJsonPath('status', 'stale');
        $this->assertEquals(700.0, $r->json('data.upstream.rx_mbps'));
    }

    public function test_ping_without_probe_series_is_setup_required(): void
    {
        $this->seedRouters();

        $r = $this->widget('ping')->assertOk();

        $r->assertJsonPath('status', 'setup_required');
        $this->assertEquals([], $r->json('data.targets'));
    }

    // ------------------------------------------------------------------
    // Status handling, security, limits
    // ------------------------------------------------------------------

    public function test_stale_status_uses_last_good_data_with_old_fetched_at(): void
    {
        $this->seedRouters();
        $this->fx('pppoe_per_router', [[['router_id' => '1'], 120.0]]);
        $first = $this->widget('summary')->assertOk();
        $first->assertJsonPath('status', 'ok');
        $firstAt = $first->json('fetched_at');
        $this->assertNotNull($firstAt);

        $this->travel(5)->minutes();
        $this->failAll = true;
        $second = $this->widget('summary')->assertOk();

        $second->assertJsonPath('status', 'stale')->assertJsonPath('partial', true);
        $this->assertEquals($firstAt, $second->json('fetched_at'));
        $this->assertEquals(['total' => 130, 'source' => 'mixed'], $second->json('data.pppoe'));
        $this->assertNotNull($second->json('reason'));
    }

    public function test_optional_query_failure_is_stale_partial_with_null_field(): void
    {
        $this->seedRouters();
        $this->fx('res_cpu', [[['router_id' => '1'], 12.0]]);
        $this->fx('res_disk_pct', [[['router_id' => '1'], 11.0]]);
        $this->failing[$this->expr('res_disk_pct')] = 401;

        $r = $this->widget('resources')->assertOk();

        $r->assertJsonPath('status', 'stale')->assertJsonPath('partial', true);
        $row = collect($r->json('data.rows'))->firstWhere('router_id', 1);
        $this->assertEquals(12.0, $row['cpu_pct']);
        $this->assertNull($row['disk_pct']);
        $this->assertNotNull($r->json('fetched_at'));
    }

    public function test_unavailable_still_returns_db_rows(): void
    {
        $this->seedRouters();
        $this->failAll = true;

        $r = $this->widget('routers')->assertOk();

        $r->assertJsonPath('status', 'unavailable')->assertJsonPath('fetched_at', null);
        $rows = collect($r->json('data.rows'))->keyBy('router_id');
        $this->assertCount(4, $rows);
        $this->assertEquals(10, $rows[1]['pppoe_online']);
        $this->assertEquals('db', $rows[1]['pppoe_source']);
        $this->assertNull($rows[1]['scraped']);
        $this->assertNull($rows[1]['rx_mbps']);
    }

    public function test_open_breaker_sends_no_http_and_reports_unavailable(): void
    {
        $this->seedRouters();
        Cache::put('grafana.cb.open', true, 60);

        $r = $this->widget('routers')->assertOk();

        $r->assertJsonPath('status', 'unavailable');
        $this->assertCount(4, $r->json('data.rows'));
        Http::assertNothingSent();
    }

    public function test_combine_follows_status_rules_in_order(): void
    {
        $res = fn (string $status, ?string $at = null) => ['status' => $status, 'fetched_at' => $at, 'series' => [], 'reason' => $status === 'ok' ? null : 'r'];

        $this->assertEquals('setup_required', MonitoringWidgets::combine([
            ['primary' => true, 'result' => $res('setup_required')], ['primary' => true, 'result' => $res('unavailable')],
        ])['status']);
        $this->assertEquals('unavailable', MonitoringWidgets::combine([
            ['primary' => true, 'result' => $res('unavailable')], ['primary' => false, 'result' => $res('ok', '2026-01-01T00:00:00+00:00')],
        ])['status']);
        $stale = MonitoringWidgets::combine([
            ['primary' => true, 'result' => $res('ok', '2026-01-01T00:10:00+00:00')],
            ['primary' => true, 'result' => $res('stale', '2026-01-01T00:00:00+00:00')],
        ]);
        $this->assertEquals('stale', $stale['status']);
        $this->assertTrue($stale['partial']);
        $this->assertEquals('2026-01-01T00:00:00+00:00', $stale['fetched_at']);
        $this->assertEquals('ok', MonitoringWidgets::combine([['primary' => true, 'result' => $res('ok', '2026-01-01T00:00:00+00:00')]])['status']);
    }

    public function test_token_never_appears_in_response_or_logs(): void
    {
        $this->seedRouters();
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
            $logged[] = $e->message.' '.json_encode($e->context);
        });
        $this->throwAll = true;

        foreach (MonitoringWidgets::WIDGETS as $w) {
            $r = $this->widget($w)->assertOk();
            $this->assertStringNotContainsString(self::TOKEN, $r->getContent());
        }

        $this->assertNotEmpty($logged);
        $this->assertStringNotContainsString(self::TOKEN, implode("\n", $logged));
    }

    public function test_61st_request_in_a_minute_is_rate_limited(): void
    {
        $this->unconfigure();
        $user = $this->admin();

        for ($i = 0; $i < 60; $i++) {
            $this->actingAs($user)->getJson('/api/monitoring/widgets/summary')->assertOk();
        }

        $this->actingAs($user)->getJson('/api/monitoring/widgets/summary')->assertStatus(429);
    }
}
