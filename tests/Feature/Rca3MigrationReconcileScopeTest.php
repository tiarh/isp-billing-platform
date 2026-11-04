<?php

namespace Tests\Feature;

use App\Models\CustomerMigration;
use App\Services\Migration\MigrationAutoReconcileService;
use App\Services\Migration\MigrationReconcileVerdict;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Concerns\BuildsAutoReconcileFixtures;
use Tests\TestCase;

/**
 * RCA-3 (#564 LOW): evaluate([id]) must not scan every migration. It loads the target rows plus the rows sharing
 * an identity key (indexed whereIn), and must give exactly the verdicts of evaluate(null) for those ids.
 */
#[Group('blocking-psb')]
class Rca3MigrationReconcileScopeTest extends TestCase
{
    use BuildsAutoReconcileFixtures;
    use RefreshDatabase;

    /**
     * What the evaluator DECIDES. An explicit id list has always traced every rule (full trace / evidence for the
     * trace view of `migration:auto-reconcile --id`), while evaluate(null) stops at the first failing rule, so trace and
     * evidence are not part of the comparison.
     *
     * @return array<string,mixed>
     */
    private function decision(MigrationReconcileVerdict $v): array
    {
        return ['id' => $v->migrationId, 'kind' => $v->kind, 'reason' => $v->reason, 'status' => $v->status, 'area' => $v->areaId,
            'created' => $v->createdAt, 'updated' => $v->updatedAt];
    }

    /** @return list<int> ids of every open row of the seeded set */
    private function seedSet(): array
    {
        $ids = [];

        // 1. plain eligible row
        $ids[] = $this->fixture()['migration']->id;

        // 2. an older open row with a NEWER synced twin of the same customer: never auto-synced
        $f = $this->fixture();
        $ids[] = $f['migration']->id;
        CustomerMigration::create(['status' => CustomerMigration::STATUS_SYNCED, 'synced_at' => now(), 'customer_id' => $f['customer']->id,
            'pppoe_user' => $f['migration']->pppoe_user, 'full_name' => 'x', 'new_sn' => 'FHTTTWIN0001']);

        // 3. duplicate open twins: older row has customer_id + pppoe, the newer one only the pppoe user (null customer_id)
        $g = $this->fixture();
        $ids[] = $g['migration']->id;
        $ids[] = CustomerMigration::create(['status' => CustomerMigration::STATUS_RECEIVED, 'customer_id' => null,
            'pppoe_user' => $g['migration']->pppoe_user, 'full_name' => 'x', 'new_sn' => 'FHTTTWIN0002'])->id;

        // 4. a row with a null customer_id (pppoe only), no twin
        $ids[] = CustomerMigration::create(['status' => CustomerMigration::STATUS_RECEIVED, 'customer_id' => null,
            'pppoe_user' => 'tanpa.customer', 'full_name' => 'x', 'new_sn' => 'FHTTNOCUST01'])->id;

        // 5. serial-only identities: an older row and a newer twin with the same new_sn
        $ids[] = CustomerMigration::create(['status' => CustomerMigration::STATUS_RECEIVED, 'customer_id' => null, 'pppoe_user' => null,
            'full_name' => 'x', 'new_sn' => 'FHTTSNONLY01'])->id;
        $ids[] = CustomerMigration::create(['status' => CustomerMigration::STATUS_WAITING_SN, 'customer_id' => null, 'pppoe_user' => null,
            'full_name' => 'x', 'new_sn' => 'FHTTSNONLY01'])->id;

        // 6. a newer CANCELLED twin does not make the older row stale
        $h = $this->fixture();
        $ids[] = $h['migration']->id;
        CustomerMigration::create(['status' => CustomerMigration::STATUS_CANCELLED, 'customer_id' => $h['customer']->id,
            'pppoe_user' => $h['migration']->pppoe_user, 'full_name' => 'x', 'new_sn' => 'FHTTCANC0001']);

        // 7. a synced row (closed) and an unrelated open row of another identity
        $k = $this->fixture();
        $ids[] = $k['migration']->id;
        $ids[] = $this->fixture(['pppoe_user' => 'ANDA.Mixed.Case'])['migration']->id;

        return $ids;
    }

    public function test_scoped_evaluate_equals_the_full_scan_filtered_to_that_id(): void
    {
        $ids = $this->seedSet();
        $svc = app(MigrationAutoReconcileService::class);
        $now = Carbon::now();
        $settings = $svc->settings();

        $full = [];
        foreach ($svc->evaluate(null, $settings, $now)['verdicts'] as $v) {
            $full[$v->migrationId] = $v;
        }
        $this->assertNotEmpty($full);

        $checked = 0;
        foreach ($ids as $id) {
            $scoped = $svc->evaluate([$id], $settings, $now)['verdicts'];
            $this->assertCount(1, $scoped, "one verdict for #{$id}");
            $this->assertArrayHasKey($id, $full, "full scan knows #{$id}");
            $this->assertSame($this->decision($full[$id]), $this->decision($scoped[0]), "verdict of #{$id} differs between the full and the scoped path");
            $checked++;
        }
        $this->assertSame(count($ids), $checked);

        // the interesting ones really are different kinds of verdict
        $reasons = array_unique(array_map(fn ($v) => $v->reason, $full));
        $this->assertContains('bukan_baris_terbaru', $reasons, 'older twins are skipped');
    }

    public function test_scoped_evaluate_of_several_ids_and_of_missing_ids_matches_too(): void
    {
        $ids = $this->seedSet();
        $svc = app(MigrationAutoReconcileService::class);
        $now = Carbon::now();
        $settings = $svc->settings();
        $closed = CustomerMigration::query()->where('status', CustomerMigration::STATUS_SYNCED)->value('id');
        $cancelled = CustomerMigration::query()->where('status', CustomerMigration::STATUS_CANCELLED)->value('id');

        $pick = [$ids[0], $ids[2], $ids[3], (int) $closed, (int) $cancelled, 999999];
        $scoped = [];
        foreach ($svc->evaluate($pick, $settings, $now)['verdicts'] as $v) {
            $scoped[$v->migrationId] = $v;
        }
        $full = [];
        foreach ($svc->evaluate(null, $settings, $now)['verdicts'] as $v) {
            $full[$v->migrationId] = $v;
        }

        foreach ([$ids[0], $ids[2], $ids[3]] as $id) {
            $this->assertSame($this->decision($full[$id]), $this->decision($scoped[$id]));
        }
        $this->assertSame('sudah_selesai', $scoped[(int) $closed]->reason);
        $this->assertSame('dibatalkan', $scoped[(int) $cancelled]->reason);
        $this->assertArrayNotHasKey(999999, $scoped);
    }

    public function test_scoped_evaluate_never_runs_an_unfiltered_scan_of_customer_migrations(): void
    {
        $ids = $this->seedSet();
        $svc = app(MigrationAutoReconcileService::class);

        $queries = [];
        DB::listen(function ($q) use (&$queries): void {
            if (str_contains($q->sql, 'from `customer_migrations`')) {
                $queries[] = $q->sql;
            }
        });
        $svc->evaluate([$ids[0]]);

        $this->assertNotEmpty($queries);
        foreach ($queries as $sql) {
            $this->assertMatchesRegularExpression('/`(id|customer_id|pppoe_user|new_sn)` in|`customer_migrations`\.`id`|UPPER\(|`(id|customer_id|pppoe_user|new_sn)` =/i', $sql, 'unfiltered scan: '.$sql);
        }

        // and the full path does still scan (byte-identical behaviour for evaluate(null))
        $queries = [];
        $svc->evaluate();
        $this->assertTrue(collect($queries)->contains(fn ($sql) => str_contains($sql, '`status` != ?') || str_contains($sql, 'not in')), 'full scan still runs for evaluate(null)');
    }
}
