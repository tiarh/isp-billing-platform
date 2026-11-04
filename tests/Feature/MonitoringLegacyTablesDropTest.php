<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-core')]
class MonitoringLegacyTablesDropTest extends TestCase
{
    use RefreshDatabase;

    private const TABLES = ['monitoring_probe_results', 'monitoring_targets', 'router_uplink_mappings'];

    private function migration(): object
    {
        return require base_path('database/migrations/2026_10_12_000001_drop_legacy_monitoring_tables.php');
    }

    private function dropAll(): void
    {
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
    }

    public function test_up_drops_all_three_tables_and_is_idempotent(): void
    {
        $this->dropAll();
        $this->migration()->down();
        $now = now();
        $targetId = DB::table('monitoring_targets')->insertGetId([
            'name' => 'Core 1', 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('monitoring_probe_results')->insert([
            'monitoring_target_id' => $targetId, 'status' => 'up', 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('router_uplink_mappings')->insert([
            'router_id' => 1, 'uplink_interface' => 'ether1', 'created_at' => $now, 'updated_at' => $now,
        ]);
        foreach (self::TABLES as $table) {
            $this->assertSame(1, DB::table($table)->count());
        }

        $this->migration()->up();
        foreach (self::TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), $table.' should be dropped');
        }

        // Fresh/CI databases without the tables: must not throw.
        $this->migration()->up();
        foreach (self::TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }
    }

    public function test_down_recreates_empty_schemas(): void
    {
        $this->dropAll();

        $this->migration()->down();

        $this->assertTrue(Schema::hasColumns('monitoring_targets', [
            'id', 'type', 'name', 'provider', 'area_name', 'target_ip', 'source_type', 'probe_source',
            'status', 'last_latency_ms', 'last_packet_loss', 'last_checked_at', 'last_error', 'is_active',
            'created_at', 'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('monitoring_probe_results', [
            'id', 'monitoring_target_id', 'status', 'latency_ms', 'packet_loss', 'error', 'checked_at',
        ]));
        $this->assertTrue(Schema::hasColumns('router_uplink_mappings', [
            'id', 'router_id', 'grafana_router_label', 'uplink_interface', 'role', 'provider', 'is_active',
        ]));
        foreach (self::TABLES as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table.' must be empty after down()');
        }

        // down() is also safe to repeat.
        $this->migration()->down();
        $this->assertTrue(Schema::hasTable('monitoring_targets'));
    }
}
