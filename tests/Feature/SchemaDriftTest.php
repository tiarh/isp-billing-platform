<?php

namespace Tests\Feature;

use App\Support\SchemaDriftAnalyzer;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Guards against NEW dangerous drift between prod (sanitized snapshot) and the migrated schema,
 * e.g. the hidden `attendances.updated_at ON UPDATE CURRENT_TIMESTAMP` that only prod had.
 * Known drift lives in tests/Fixtures/schema/drift-allowlist.php. MySQL only; read-only.
 */
#[Group('blocking-olt-mysql')]
class SchemaDriftTest extends TestCase
{
    public function test_no_new_dangerous_schema_drift_vs_prod_snapshot(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('mysql only');
        }

        $snapshot = json_decode((string) file_get_contents(base_path('tests/Fixtures/schema/prod-columns.json')), true);
        $this->assertIsArray($snapshot['columns'] ?? null, 'prod snapshot must contain a columns list');

        /** @var array<string,string> $allowlist */
        $allowlist = require base_path('tests/Fixtures/schema/drift-allowlist.php');
        foreach ($allowlist as $key => $reason) {
            $this->assertNotSame('', trim((string) $reason), "allowlist entry {$key} needs a reason");
        }

        // Other tests create ad-hoc tables in the shared MySQL DB (e.g. olt_vlan_mappings), so only
        // compare tables that migrations themselves create.
        $owned = array_flip($this->migrationOwnedTables());
        $only = static fn (array $rows): array => array_values(array_filter($rows, static fn (array $r): bool => isset($owned[$r['t']])));

        $report = SchemaDriftAnalyzer::compare($only($snapshot['columns']), $only(SchemaDriftAnalyzer::readCurrentSchema()));

        $found = [];
        foreach ($report['dangerous'] as $d) {
            $found[$d['key']] = $d['detail'];
        }

        $new = array_diff_key($found, $allowlist);
        $stale = array_diff_key($allowlist, $found);

        $this->assertSame(
            [],
            $new,
            "New dangerous schema drift vs prod snapshot (fix the migration or add to tests/Fixtures/schema/drift-allowlist.php with a reason):\n"
            .json_encode($new, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
        $this->assertSame(
            [],
            $stale,
            "Stale allowlist entries (drift is gone, remove them):\n".json_encode($stale, JSON_PRETTY_PRINT)
        );
    }

    /** @return list<string> */
    private function migrationOwnedTables(): array
    {
        $tables = [];
        foreach (glob(base_path('database/migrations/*.php')) ?: [] as $file) {
            if (preg_match_all('/Schema::create\(\s*[\'"]([A-Za-z0-9_]+)[\'"]/', (string) file_get_contents($file), $m)) {
                array_push($tables, ...$m[1]);
            }
        }

        return array_values(array_unique($tables));
    }

    public function test_analyzer_flags_on_update_nullability_and_type_family_only(): void
    {
        $prod = [
            ['t' => 'a', 'c' => 'u', 'type' => 'timestamp', 'nul' => 'NO', 'def' => 'CURRENT_TIMESTAMP', 'extra' => 'DEFAULT_GENERATED on update CURRENT_TIMESTAMP'],
            ['t' => 'a', 'c' => 'n', 'type' => 'varchar(10)', 'nul' => 'NO', 'def' => null, 'extra' => ''],
            ['t' => 'a', 'c' => 'f', 'type' => 'timestamp', 'nul' => 'YES', 'def' => null, 'extra' => ''],
            ['t' => 'a', 'c' => 'i', 'type' => 'int', 'nul' => 'YES', 'def' => null, 'extra' => ''],
            ['t' => 'a', 'c' => 'gone', 'type' => 'int', 'nul' => 'YES', 'def' => null, 'extra' => ''],
            ['t' => 'legacy', 'c' => 'x', 'type' => 'int', 'nul' => 'YES', 'def' => null, 'extra' => ''],
        ];
        $app = [
            ['t' => 'a', 'c' => 'u', 'type' => 'timestamp', 'nul' => 'NO', 'def' => 'CURRENT_TIMESTAMP', 'extra' => 'DEFAULT_GENERATED'],
            ['t' => 'a', 'c' => 'n', 'type' => 'varchar(10)', 'nul' => 'YES', 'def' => null, 'extra' => ''],
            ['t' => 'a', 'c' => 'f', 'type' => 'datetime', 'nul' => 'YES', 'def' => null, 'extra' => ''],
            ['t' => 'a', 'c' => 'i', 'type' => 'bigint', 'nul' => 'YES', 'def' => null, 'extra' => ''],
            ['t' => 'a', 'c' => 'new', 'type' => 'int', 'nul' => 'YES', 'def' => null, 'extra' => ''],
            ['t' => 'appOnly', 'c' => 'y', 'type' => 'int', 'nul' => 'YES', 'def' => null, 'extra' => ''],
        ];

        $r = SchemaDriftAnalyzer::compare($prod, $app);

        $this->assertSame(['legacy'], $r['tables_only_prod']);
        $this->assertSame(['appOnly'], $r['tables_only_app']);
        $this->assertSame(['a.gone'], $r['columns_only_prod']);
        $this->assertSame(['a.new'], $r['columns_only_app']);
        $keys = array_column($r['dangerous'], 'key');
        sort($keys);
        $this->assertSame(['a.f|type_family', 'a.n|nullability', 'a.u|on_update'], $keys);
    }

    public function test_default_generated_and_current_timestamp_spelling_are_normalised(): void
    {
        $prod = [['t' => 'a', 'c' => 'c', 'type' => 'timestamp', 'nul' => 'NO', 'def' => 'CURRENT_TIMESTAMP', 'extra' => 'DEFAULT_GENERATED']];
        $app = [['t' => 'a', 'c' => 'c', 'type' => 'timestamp', 'nul' => 'NO', 'def' => 'current_timestamp()', 'extra' => '']];

        $this->assertSame([], SchemaDriftAnalyzer::compare($prod, $app)['differences']);
    }
}
