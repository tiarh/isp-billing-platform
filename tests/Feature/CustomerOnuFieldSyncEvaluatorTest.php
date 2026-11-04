<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerMigration;
use App\Models\CustomerPendingTermination;
use App\Models\OltOnu;
use App\Models\Setting;
use App\Services\CustomerOnu\CustomerOnuFieldSyncService;
use App\Services\CustomerOnu\CustomerOnuSyncVerdict as V;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Concerns\BuildsOnuSyncFixtures;
use Tests\TestCase;

#[Group('blocking-core')]
class CustomerOnuFieldSyncEvaluatorTest extends TestCase
{
    use BuildsOnuSyncFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->blockDevices();
    }

    public function test_b1_empty_olt_is_synced_with_serial_unchanged(): void
    {
        $f = $this->scenario();
        $v = $this->assertVerdict($f['customer'], V::SYNC, 'ok');

        $this->assertSame('B1', $v->subScope);
        $this->assertSame(V::SCOPE_SERIAL, $v->scope);
        $this->assertSame(['olt_id' => null, 'olt_port_label' => null, 'onu_serial' => $f['customer']->onu_serial], $v->before);
        $this->assertSame(['olt_id' => $f['olt']->id, 'olt_port_label' => $f['onu']->onu_ref, 'onu_serial' => $f['customer']->onu_serial], $v->after);
        $this->assertSame($f['olt']->id, $v->evidence['olt_id']);
        $this->assertArrayHasKey('pppoe', $v->evidence);
    }

    public function test_b3_incomplete_port_label_is_synced_and_a_matching_label_is_kept(): void
    {
        $f = $this->scenario(['olt_port_label' => '1/2/1']);
        $f['customer']->forceFill(['olt_id' => $f['olt']->id])->save();
        $v = $this->assertVerdict($f['customer'], V::SYNC, 'ok');
        $this->assertSame('B3', $v->subScope);
        $this->assertSame($f['onu']->onu_ref, $v->after['olt_port_label']);

        $g = $this->scenario(['olt_port_label' => 'gpon-onu_1/2/1:'.($this->sx + 1)]);
        $g['customer']->forceFill(['olt_id' => $g['olt']->id])->save();
        $res = app(CustomerOnuFieldSyncService::class)->evaluate(['ids' => [$g['customer']->id]]);
        $this->assertSame([], $res['verdicts'], 'a gpon-onu_ prefixed label of the same ref is "in line"');
    }

    public function test_customer_already_in_line_is_not_a_candidate(): void
    {
        $f = $this->scenario(['olt_port_label' => '1/2/1:1']);
        $f['customer']->forceFill(['olt_id' => $f['olt']->id])->save();
        $this->assertNull($this->verdictFor($f['customer']));
    }

    public function test_b2_dead_old_olt_needs_fresh_pppoe_and_router_match(): void
    {
        $f = $this->scenario();
        $dead = $this->scenario([], null); // another OLT, its own router
        $f['customer']->forceFill(['olt_id' => $dead['olt']->id, 'olt_port_label' => '0/0/9:1'])->save();
        OltOnu::create(['olt_id' => $dead['olt']->id, 'onu_ref' => '0/0/9:1', 'serial_number' => $f['customer']->onu_serial, 'state' => 'online', 'state_collected_at' => now()->subDays(3)]);

        $v = $this->assertVerdict($f['customer'], V::SYNC, 'ok');
        $this->assertSame('B2', $v->subScope);
        $this->assertSame($f['olt']->id, $v->after['olt_id']);
        $this->assertSame(1, $v->evidence['stale_rows_other_olt']);

        $f['customer']->forceFill(['pppoe_online_checked_at' => now()->subMinutes(45)])->save();
        $this->assertVerdict($f['customer'], V::SKIP, 'pppoe_tidak_online');
        $f['customer']->forceFill(['pppoe_online_checked_at' => now()->subMinutes(5), 'is_online' => false])->save();
        $this->assertVerdict($f['customer'], V::SKIP, 'pppoe_tidak_online');

        $f['customer']->forceFill(['is_online' => true, 'router_id' => $dead['router']->id])->save();
        $this->assertVerdict($f['customer'], V::PERLU_DICEK_ADMIN, 'router_olt_beda');
    }

    public function test_pppoe_is_not_required_for_b1(): void
    {
        $f = $this->scenario(['is_online' => false, 'pppoe_online_checked_at' => null]);
        $this->assertVerdict($f['customer'], V::SYNC, 'ok');
    }

    public function test_reality_rules(): void
    {
        $offline = $this->scenario([], ['state' => 'offline']);
        $this->assertVerdict($offline['customer'], V::SKIP, 'onu_offline');

        $stale = $this->scenario([], ['state_collected_at' => now()->subHours(3)]);
        $this->assertVerdict($stale['customer'], V::SKIP, 'onu_data_basi');

        $none = $this->scenario([], null);
        $this->assertVerdict($none['customer'], V::SKIP, 'sn_tidak_ada_di_olt');

        $badRef = $this->scenario([], ['onu_ref' => 'weird-ref']);
        $this->assertVerdict($badRef['customer'], V::SKIP, 'ref_tidak_standar');

        $noRouter = $this->scenario([], [], ['router_id' => null]);
        $this->assertVerdict($noRouter['customer'], V::SKIP, 'olt_tanpa_router');

        $dup = $this->scenario();
        $other = $this->scenario([], null);
        OltOnu::create(['olt_id' => $other['olt']->id, 'onu_ref' => '1/9/9:9', 'serial_number' => $dup['customer']->onu_serial, 'state' => 'online', 'state_collected_at' => now()->subMinutes(2)]);
        $this->assertVerdict($dup['customer'], V::PERLU_DICEK_ADMIN, 'onu_ganda');
    }

    public function test_onu_age_threshold_is_a_setting(): void
    {
        $f = $this->scenario([], ['state_collected_at' => now()->subMinutes(100)]);
        $this->assertVerdict($f['customer'], V::SKIP, 'onu_data_basi');
        Setting::set('customer_onu_sync_onu_max_age_minutes', 120);
        $this->assertVerdict($f['customer'], V::SYNC, 'ok');
    }

    public function test_customer_rules(): void
    {
        $dead = $this->scenario(['status' => 'terminated']);
        $this->assertVerdict($dead['customer'], V::SKIP, 'status_tidak_didukung');

        $isolated = $this->scenario(['status' => 'isolated']);
        $this->assertVerdict($isolated['customer'], V::SYNC, 'ok');

        $pending = $this->scenario();
        CustomerPendingTermination::create(['customer_id' => $pending['customer']->id, 'source' => 'warehouse_receive', 'status' => 'pending', 'hold_billing' => true, 'requested_at' => now()]);
        $this->assertVerdict($pending['customer'], V::SKIP, 'terminasi_tertunda');

        $trashed = $this->scenario();
        DB::table('customers')->where('id', $trashed['customer']->id)->update(['deleted_at' => now()]);
        $this->assertNull($this->verdictFor($trashed['customer']));
    }

    public function test_imp_customers_are_included_and_obey_the_same_rules(): void
    {
        $imp = $this->scenario(['code' => 'IMP-0451']);
        $v = $this->assertVerdict($imp['customer'], V::SYNC, 'ok');
        $this->assertSame('IMP-0451', $v->code);

        $impCase = $this->scenario(['code' => 'IMP-0452']);
        $this->openRecoveryCase($impCase['customer']);
        $this->assertVerdict($impCase['customer'], V::PERLU_DICEK_ADMIN, 'kasus_penarikan_pelanggan_terbuka');
    }

    public function test_ownership_rules(): void
    {
        $a = $this->scenario();
        $b = $this->scenario([], null);
        $b['customer']->forceFill(['onu_serial' => $a['customer']->onu_serial])->save();
        $this->assertVerdict($a['customer'], V::PERLU_DICEK_ADMIN, 'sn_milik_pelanggan_lain');

        $b['customer']->forceFill(['status' => 'terminated'])->save();
        $v = $this->assertVerdict($a['customer'], V::SYNC, 'ok');
        $this->assertContains('sn_pernah_dipakai_pelanggan_terminasi', $v->info);

        $port = $this->scenario();
        $this->scenario(['olt_id' => $port['olt']->id, 'olt_port_label' => $port['onu']->onu_ref], null);
        $this->assertVerdict($port['customer'], V::PERLU_DICEK_ADMIN, 'port_milik_pelanggan_lain');

        $name = $this->scenario();
        $this->scenario(['pppoe_user' => 'milik.orang'], null);
        $name['onu']->forceFill(['onu_name' => 'MILIK.orang'])->save();
        $this->assertVerdict($name['customer'], V::SKIP, 'nama_onu_milik_pelanggan_lain');
    }

    public function test_open_psb_migration_and_recovery_block(): void
    {
        $psb = $this->scenario();
        DB::table('psb_drafts')->insert(['status' => 'waiting_sn', 'sn' => $psb['customer']->onu_serial, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertVerdict($psb['customer'], V::SKIP, 'sn_di_psb_terbuka');

        $psbOwn = $this->scenario();
        DB::table('psb_drafts')->insert(['status' => 'secret_ok', 'sn' => 'OTHER0000001', 'customer_id' => $psbOwn['customer']->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertVerdict($psbOwn['customer'], V::SKIP, 'psb_terbuka');

        $done = $this->scenario();
        DB::table('psb_drafts')->insert(['status' => 'applied', 'sn' => $done['customer']->onu_serial, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertVerdict($done['customer'], V::SYNC, 'ok');

        $mig = $this->scenario();
        CustomerMigration::create(['status' => CustomerMigration::STATUS_WAITING_SN, 'customer_id' => $mig['customer']->id, 'full_name' => 'X', 'pppoe_user' => 'x']);
        $this->assertVerdict($mig['customer'], V::SKIP, 'migrasi_terbuka');

        $otherMig = $this->scenario();
        CustomerMigration::create(['status' => CustomerMigration::STATUS_SYNCED, 'synced_at' => now()->subDay(), 'full_name' => 'Y', 'pppoe_user' => 'orang.lain', 'new_sn' => $otherMig['customer']->onu_serial]);
        $this->assertVerdict($otherMig['customer'], V::SKIP, 'sn_di_migrasi_lain');

        $own = $this->scenario();
        $this->openRecoveryCase($own['customer']);
        $this->assertVerdict($own['customer'], V::PERLU_DICEK_ADMIN, 'kasus_penarikan_pelanggan_terbuka');

        $mine = $this->scenario();
        $pulled = $this->scenario([], null);
        $this->openRecoveryCase($pulled['customer'], $mine['customer']->onu_serial);
        $this->assertVerdict($mine['customer'], V::PERLU_DICEK_ADMIN, 'sn_ada_kasus_penarikan');
    }

    // ---- scope A

    public function test_scope_a_respects_the_six_hour_settle_on_both_stamps(): void
    {
        $f = $this->scenario(['onu_serial' => 'ZTEOLD00001']);
        $newSn = $f['onu']->serial_number;
        $m = $this->autoMigration($f['customer'], $f['olt'], $newSn, hoursAgo: 3);
        $this->assertVerdict($f['customer'], V::SKIP, 'migrasi_belum_6_jam');

        // `at` old enough, synced_at recent
        $m->forceFill(['synced_at' => now()->subHours(2)])->save();
        $meta = $m->meta;
        $meta['auto_reconcile']['at'] = now()->subHours(8)->toIso8601String();
        $m->forceFill(['meta' => $meta])->save();
        $this->assertVerdict($f['customer'], V::SKIP, 'migrasi_belum_6_jam');

        // synced_at old, `at` recent
        $m->forceFill(['synced_at' => now()->subHours(8)])->save();
        $meta['auto_reconcile']['at'] = now()->subHours(1)->toIso8601String();
        $m->forceFill(['meta' => $meta])->save();
        $this->assertVerdict($f['customer'], V::SKIP, 'migrasi_belum_6_jam');

        $meta['auto_reconcile']['at'] = now()->subHours(7)->toIso8601String();
        $m->forceFill(['meta' => $meta])->save();
        $v = $this->assertVerdict($f['customer'], V::SYNC, 'ok');
        $this->assertSame('A', $v->subScope);
        $this->assertSame(V::SCOPE_MIGRATION, $v->scope);
        $this->assertSame($m->id, $v->migrationId);
        $this->assertSame('20261015-100000-ab12', $v->migrationAutoRunId);
        $this->assertSame($newSn, $v->after['onu_serial']);
        $this->assertSame('ZTEOLD00001', $v->before['onu_serial']);

        Setting::set('customer_onu_sync_migration_settle_hours', 12);
        $this->assertVerdict($f['customer'], V::SKIP, 'migrasi_belum_6_jam');
    }

    public function test_scope_a_resolves_customer_by_meta_or_customer_id_and_checks_identity(): void
    {
        $f = $this->scenario(['onu_serial' => 'ZTEOLD00002']);
        $newSn = $f['onu']->serial_number;
        $m = $this->autoMigration($f['customer'], $f['olt'], $newSn, ['pppoe_user' => $f['customer']->pppoe_user], ['resolved_customer_id' => null]);
        // resolved_customer_id missing and customer_id NULL: nobody to sync
        $v = $this->verdictFor($f['customer']);
        $this->assertSame(V::SCOPE_SERIAL, $v?->scope, 'nobody resolved: falls back to the serial scope (old serial has no ONU)');
        $this->assertSame('sn_tidak_ada_di_olt', $v->reason);

        $m->forceFill(['customer_id' => $f['customer']->id])->save();
        $this->assertSame(V::SCOPE_MIGRATION, $this->verdictFor($f['customer'])->scope);

        $m->forceFill(['pppoe_user' => 'orang.lain', 'customer_id' => null])->save();
        $meta = $m->meta;
        $meta['auto_reconcile']['resolved_customer_id'] = $f['customer']->id;
        $m->forceFill(['meta' => $meta])->save();
        $this->assertVerdict($f['customer'], V::PERLU_DICEK_ADMIN, 'identitas_migrasi_beda');
    }

    public function test_scope_a_customer_profile_keeps_the_serial_and_wrong_olt_goes_to_admin(): void
    {
        $f = $this->scenario();
        $this->autoMigration($f['customer'], $f['olt'], 'WRONGNEW0001', auto: ['sn_source' => 'customer_profile', 'effective_sn' => $f['customer']->onu_serial]);
        $v = $this->assertVerdict($f['customer'], V::SYNC, 'ok');
        $this->assertSame($f['customer']->onu_serial, $v->after['onu_serial']);
        $this->assertSame('customer_profile', $v->snSource);

        $g = $this->scenario(['onu_serial' => 'ZTEOLD00003']);
        $elsewhere = $this->scenario([], null);
        $this->autoMigration($g['customer'], $elsewhere['olt'], $g['onu']->serial_number);
        $this->assertVerdict($g['customer'], V::PERLU_DICEK_ADMIN, 'onu_pindah_olt');
    }

    public function test_scope_a_ignores_reverted_rows_and_yields_to_a_newer_migration(): void
    {
        $f = $this->scenario(['onu_serial' => 'ZTEOLD00004']);
        $m = $this->autoMigration($f['customer'], $f['olt'], $f['onu']->serial_number);
        $meta = $m->meta;
        $meta['auto_reconcile_reverted'] = $meta['auto_reconcile'];
        unset($meta['auto_reconcile']);
        $m->forceFill(['meta' => $meta, 'status' => CustomerMigration::STATUS_NEEDS_REVIEW, 'synced_at' => null])->save();
        $v = $this->verdictFor($f['customer']);
        $this->assertSame(V::SCOPE_SERIAL, $v?->scope, 'reverted #517 row is not a scope A source');

        $g = $this->scenario();
        $this->autoMigration($g['customer'], $g['olt'], $g['onu']->serial_number);
        CustomerMigration::create(['status' => CustomerMigration::STATUS_WAITING_SN, 'customer_id' => $g['customer']->id, 'full_name' => 'X', 'pppoe_user' => $g['customer']->pppoe_user]);
        $v = $this->verdictFor($g['customer']);
        $this->assertSame('migrasi_terbuka', $v->reason, 'newer open migration blocks (scope A defers to the serial scope)');
    }

    public function test_scope_a_never_touches_a_customer_with_an_unsettled_migration_via_scope_b(): void
    {
        $f = $this->scenario();
        $this->autoMigration($f['customer'], $f['olt'], $f['onu']->serial_number, hoursAgo: 1);
        $v = $this->verdictFor($f['customer']);
        $this->assertSame('migrasi_belum_6_jam', $v->reason);
        $this->assertSame(V::SCOPE_MIGRATION, $v->scope);
    }

    public function test_l5_serial_scope_also_honours_the_settle_hours(): void
    {
        $f = $this->scenario();
        $this->autoMigration($f['customer'], $f['olt'], $f['onu']->serial_number, hoursAgo: 1);

        $this->assertNull($this->verdictFor($f['customer'], ['scope' => 'serial']), 'unsettled auto-migration customer is not touched via --scope=serial');
        $this->assertSame('migrasi_belum_6_jam', $this->verdictFor($f['customer'])->reason);
    }

    // ---- filters + purity

    public function test_scope_and_olt_filters(): void
    {
        $f = $this->scenario();
        $this->assertNotNull($this->verdictFor($f['customer'], ['scope' => 'serial']));
        $this->assertNull($this->verdictFor($f['customer'], ['scope' => 'migration']));
        $this->assertNotNull($this->verdictFor($f['customer'], ['olt_ids' => [$f['olt']->id]]));
        $this->assertNull($this->verdictFor($f['customer'], ['olt_ids' => [$f['olt']->id + 999]]));
    }

    public function test_evaluate_performs_no_writes(): void
    {
        $f = $this->scenario();
        $this->scenario(['status' => 'terminated']);
        $writes = [];
        DB::listen(function ($q) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|replace|alter|create|drop|truncate)\b/i', $q->sql)) {
                $writes[] = $q->sql;
            }
        });

        app(CustomerOnuFieldSyncService::class)->evaluate();

        $this->assertSame([], $writes);
        $this->assertNull(Customer::query()->find($f['customer']->id)->olt_id);
    }
}
