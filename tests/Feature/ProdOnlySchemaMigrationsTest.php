<?php

namespace Tests\Feature;

use App\Support\SchemaDriftAnalyzer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * The prod-only tables/columns now have migrations. They must be idempotent (a no-op when the objects already
 * exist, as on production) and a fresh schema must match the committed prod snapshot for exactly these objects.
 */
#[Group('blocking-olt-mysql')]
class ProdOnlySchemaMigrationsTest extends TestCase
{
    private const TABLES = ['olt_vlan_mappings', 'psb_import_candidates', 'olt_collect_runs', 'progress_reports'];

    private const COLUMNS = [
        'customers' => ['service_online_reason'],
        'support_tickets' => ['site', 'additional_technicians', 'material'],
        'wa_campaign_recipients' => ['customer_name'],
        'wa_campaigns' => ['image_path', 'custom_recipients'],
    ];

    private function migration(string $file): Migration
    {
        return require base_path('database/migrations/'.$file);
    }

    public function test_objects_exist_and_running_the_migrations_again_is_a_noop(): void
    {
        $before = $this->describe();

        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} must exist after migrate");
        }
        foreach (self::COLUMNS as $table => $cols) {
            foreach ($cols as $col) {
                $this->assertTrue(Schema::hasColumn($table, $col), "{$table}.{$col} must exist after migrate");
            }
        }

        $marker = DB::table('olt_vlan_mappings')->insertGetId([
            'olt_id' => 1, 'pon_port' => '1/1/1', 'vlan' => 100, 'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach (['2026_10_15_000003_create_olt_collect_runs_table.php', '2026_10_16_000010_create_prod_only_tables.php', '2026_10_16_000011_add_prod_only_columns.php'] as $file) {
            $m = $this->migration($file);
            $m->up();
            $m->up();
            $m->down();
        }

        $this->assertSame($before, $this->describe(), 'structure must be unchanged');
        $this->assertSame(1, DB::table('olt_vlan_mappings')->where('id', $marker)->count(), 'data must be untouched');
    }

    public function test_fresh_schema_matches_prod_snapshot_for_these_objects(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('mysql only');
        }

        $snapshot = json_decode((string) file_get_contents(base_path('tests/Fixtures/schema/prod-columns.json')), true);
        $keep = static fn (array $r): bool => in_array($r['t'], self::TABLES, true)
            || in_array($r['c'], self::COLUMNS[$r['t']] ?? [], true);

        $prod = array_values(array_filter($snapshot['columns'], $keep));
        $app = array_values(array_filter(SchemaDriftAnalyzer::readCurrentSchema(), $keep));

        $this->assertNotEmpty($prod);
        $this->assertCount(count($prod), $app);
        $report = SchemaDriftAnalyzer::compare($prod, $app);

        $this->assertSame([], $report['tables_only_prod']);
        $this->assertSame([], $report['columns_only_prod']);
        $this->assertSame([], $report['columns_only_app']);
        $this->assertSame([], $report['differences'], json_encode($report['differences']));
    }

    /** @return array<string, list<string>> */
    private function describe(): array
    {
        $out = [];
        foreach ([...self::TABLES, ...array_keys(self::COLUMNS)] as $table) {
            $cols = Schema::getColumns($table);
            $out[$table] = array_map(static fn (array $c): string => json_encode($c), $cols);
        }

        return $out;
    }
}
