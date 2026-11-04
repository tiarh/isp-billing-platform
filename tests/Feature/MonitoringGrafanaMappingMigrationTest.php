<?php

namespace Tests\Feature;

use App\Models\Router;
use App\Models\Setting;
use App\Services\Grafana\GrafanaClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-core')]
class MonitoringGrafanaMappingMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(string $file): object
    {
        return require base_path('database/migrations/'.$file);
    }

    public function test_mapping_migration_is_idempotent(): void
    {
        $m = $this->migration('2026_10_11_000001_monitoring_grafana_mapping.php');
        $m->up();
        $m->up();

        $this->assertTrue(Schema::hasColumn('routers', 'grafana_identity'));
        $this->assertTrue(Schema::hasColumn('routers', 'monitoring_enabled'));
        $this->assertTrue(Schema::hasTable('monitoring_upstream_links'));
    }

    public function test_legacy_uplink_mapping_table_is_gone_after_the_drop_migration(): void
    {
        $this->migration('2026_10_11_000001_monitoring_grafana_mapping.php')->up();
        $this->assertTrue(Schema::hasTable('router_uplink_mappings'));

        $this->migration('2026_10_12_000001_drop_legacy_monitoring_tables.php')->up();

        $this->assertFalse(Schema::hasTable('router_uplink_mappings'));
        $this->assertFalse(class_exists('App\Models\RouterUplinkMapping'));
        $this->assertTrue(Schema::hasTable('monitoring_upstream_links'));
    }

    public function test_seed_is_idempotent_and_does_not_overwrite_edits(): void
    {
        $seed = $this->migration('2026_10_11_000002_seed_monitoring_upstream_links.php');
        DB::table('monitoring_upstream_links')->delete();
        $seed->up();
        $this->assertSame(7, DB::table('monitoring_upstream_links')->count());
        $this->assertSame(2000, (int) DB::table('monitoring_upstream_links')->where('upstream_key', 'THC')->value('capacity_mbps'));
        $this->assertSame(0, DB::table('monitoring_upstream_links')->where('router_label', '!=', 'Jakarta-Edge')->count());

        DB::table('monitoring_upstream_links')->where('upstream_key', 'THC')->update(['capacity_mbps' => 4000]);
        $seed->up();

        $this->assertSame(7, DB::table('monitoring_upstream_links')->count());
        $this->assertSame(4000, (int) DB::table('monitoring_upstream_links')->where('upstream_key', 'THC')->value('capacity_mbps'));
        $seed->down();
        $this->assertSame(7, DB::table('monitoring_upstream_links')->count());
    }

    private function router(int $id, string $name, ?string $identity = null): void
    {
        $r = new Router(['name' => $name, 'ip_address' => '10.99.0.'.$id, 'username' => 'a', 'password' => 'b', 'port' => 8728, 'is_active' => true]);
        $r->id = $id;
        $r->save();
        DB::table('routers')->where('id', $id)->update(['grafana_identity' => $identity]);
    }

    private function fakeGrafana(): void
    {
        config(['services.grafana.allowed_hosts' => ['grafana.test']]);
        Setting::set(GrafanaClient::SETTING_URL, 'http://grafana.test:3001');
        Setting::setSecret(GrafanaClient::SETTING_TOKEN, 'glsa_SYNTHETIC_MIGRATION_TOKEN');
        $frame = fn (string $id, string $label) => [
            'schema' => ['fields' => [['name' => 'Time'], ['name' => 'Value', 'labels' => ['router_id' => $id, 'router' => $label]]]],
            'data' => ['values' => [[1], [1]]],
        ];
        Http::fake([
            'http://grafana.test:3001/api/ds/query' => Http::response(['results' => ['A' => ['frames' => [
                $frame('1', 'Fresh'),
                $frame('2', 'FreshTwo'),
            ]]]]),
            'http://grafana.test:3001/api/datasources/*' => Http::response(['status' => 'success', 'data' => ['Fresh', 'FreshTwo']]),
        ]);
    }

    public function test_command_dry_run_apply_and_force(): void
    {
        $this->fakeGrafana();
        $this->router(1, 'R One');
        $this->router(2, 'R Two', 'Manual');

        $this->artisan('monitoring:grafana-identities')->assertSuccessful();
        $this->assertNull(DB::table('routers')->where('id', 1)->value('grafana_identity'));
        $this->assertSame('Manual', DB::table('routers')->where('id', 2)->value('grafana_identity'));

        $this->artisan('monitoring:grafana-identities', ['--apply' => true])->assertSuccessful();
        $this->assertSame('Fresh', DB::table('routers')->where('id', 1)->value('grafana_identity'));
        $this->assertSame('Manual', DB::table('routers')->where('id', 2)->value('grafana_identity'));

        $this->artisan('monitoring:grafana-identities', ['--apply' => true, '--force' => true])->assertSuccessful();
        $this->assertSame('FreshTwo', DB::table('routers')->where('id', 2)->value('grafana_identity'));
    }
}
