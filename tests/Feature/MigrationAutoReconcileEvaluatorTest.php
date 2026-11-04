<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerMigration;
use App\Models\DeviceActionLog;
use App\Models\Olt;
use App\Models\OltOnu;
use App\Models\Setting;
use App\Services\Migration\MigrationAutoReconcileService;
use App\Services\Migration\MigrationReconcileVerdict;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Concerns\BuildsAutoReconcileFixtures;
use Tests\TestCase;

#[Group('blocking-psb')]
class MigrationAutoReconcileEvaluatorTest extends TestCase
{
    use BuildsAutoReconcileFixtures;
    use RefreshDatabase;

    public function test_baseline_is_eligible_with_evidence_and_link_proof(): void
    {
        $f = $this->fixture();
        $v = $this->verdict($f['migration']);

        $this->assertSame(MigrationReconcileVerdict::ELIGIBLE, $v->kind, $v->reason);
        $this->assertStringContainsString('pppoe_typed', (string) $v->linkProof);
        $this->assertStringContainsString('name_match', (string) $v->linkProof);
        $this->assertSame('new_sn', $v->evidence['sn_source']);
        $this->assertSame($f['customer']->id, $v->evidence['customer_id']);
        $this->assertSame('info_only_not_evidence', $v->evidence['rx_dbm']);
    }

    public function test_fallback_hitting_the_old_onu_on_the_same_olt_is_skipped_when_new_onu_absent(): void
    {
        // customers.onu_serial still holds the OLD ONU; the typed new SN is not provisioned yet; old ONU is live.
        $f = $this->fixture(
            ['old_olt_id' => 'same', 'new_sn' => 'FHTTNOTYET01', 'old_sn' => 'ZTEOLDX01'],
            ['onu_serial' => 'ZTEOLDX01'],
            ['serial_number' => 'ZTEOLDX01'],
        );
        $this->assertSkip($f['migration'], 'profile_sn_masih_onu_lama');
        $this->assertSame(MigrationReconcileVerdict::SKIP, $this->verdict($f['migration'])->kind);
    }

    public function test_fallback_without_known_old_sn_is_skipped(): void
    {
        $f = $this->fixture(
            ['new_sn' => 'FHTTNOTYET02', 'old_sn' => null],
            ['onu_serial' => 'FHTTPROF02'],
            ['serial_number' => 'FHTTPROF02'],
        );
        $this->assertSkip($f['migration'], 'profile_sn_masih_onu_lama');
    }

    public function test_fallback_matching_the_old_onu_slot_is_skipped(): void
    {
        $f = $this->fixture(
            ['old_olt_id' => 'same', 'new_sn' => 'FHTTNOTYET03', 'old_sn' => 'ZTEOLDX03', 'old_onu_ref' => 'gpon-onu_1/2/1:33'],
            ['onu_serial' => 'FHTTPROF03'],
            ['serial_number' => 'FHTTPROF03', 'onu_ref' => '1/2/1:33'],
        );
        $this->assertSkip($f['migration'], 'profile_sn_masih_onu_lama');
    }

    public function test_fallback_does_not_count_serial_on_customer_as_link_proof(): void
    {
        // no typed pppoe proof and ONU name does not match the customer -> no proof at all in fallback
        $f = $this->fixture(
            ['new_sn' => 'FHTTWRONG04', 'old_sn' => 'ZTEOLDX04', 'meta' => []],
            ['onu_serial' => 'FHTTREAL04'],
            ['serial_number' => 'FHTTREAL04', 'onu_name' => 'zzzz'],
        );
        $this->assertSkip($f['migration'], 'kaitan_onu_belum_terbukti');

        $ok = $this->fixture(
            ['new_sn' => 'FHTTWRONG05', 'old_sn' => 'ZTEOLDX05'],
            ['onu_serial' => 'FHTTREAL05'],
            ['serial_number' => 'FHTTREAL05', 'onu_name' => 'zzzz'],
        );
        $v = $this->verdict($ok['migration']);
        $this->assertSame(MigrationReconcileVerdict::ELIGIBLE, $v->kind, $v->reason);
        $this->assertSame('customer_profile', $v->evidence['sn_source']);
        $this->assertStringNotContainsString('serial_on_customer', (string) $v->linkProof);
    }

    public function test_new_sn_equal_to_old_sn_on_same_olt_needs_a_real_router_move(): void
    {
        $same = $this->fixture(
            ['old_olt_id' => 'same', 'new_sn' => 'ZTEOLDX06', 'old_sn' => 'ZTEOLDX06'],
            ['onu_serial' => 'ZTEOLDX06'],
            ['serial_number' => 'ZTEOLDX06'],
        );
        $this->assertSkip($same['migration'], 'sn_baru_sama_dengan_onu_lama');

        // old router recorded but equal to the target router -> still no proof
        $sameRouter = $this->fixture(
            ['old_olt_id' => 'same', 'new_sn' => 'ZTEOLDX07', 'old_sn' => 'ZTEOLDX07'],
            ['onu_serial' => 'ZTEOLDX07'],
            ['serial_number' => 'ZTEOLDX07'],
        );
        DB::table('customer_migrations')->where('id', $sameRouter['migration']->id)->update(['old_router_id' => $sameRouter['router']->id]);
        $this->assertSkip($sameRouter['migration']->fresh(), 'sn_baru_sama_dengan_onu_lama');

        // real router move: old router differs from target, customer already on target
        $moved = $this->fixture(
            ['old_olt_id' => 'same', 'new_sn' => 'ZTEOLDX08', 'old_sn' => 'ZTEOLDX08'],
            ['onu_serial' => 'ZTEOLDX08'],
            ['serial_number' => 'ZTEOLDX08'],
        );
        $oldRouter = $this->fixture()['router'];
        DB::table('customer_migrations')->where('id', $moved['migration']->id)->update(['old_router_id' => $oldRouter->id]);
        $v = $this->verdict($moved['migration']->fresh());
        $this->assertSame(MigrationReconcileVerdict::ELIGIBLE, $v->kind, $v->reason);
        $this->assertArrayHasKey('router_move_proof', $v->evidence);
    }

    public function test_same_olt_row_without_router_move_or_offline_old_onu_proof_is_not_eligible(): void
    {
        // (a) fallback: typo'd new_sn + typo'd old_sn, empty old_onu_ref, router unchanged, profile SN = old ONU live
        $a = $this->fixture(
            ['old_olt_id' => 'same', 'new_sn' => 'FHTTTYPO0001', 'old_sn' => 'ZTEOLDTYPO', 'old_onu_ref' => null],
            ['onu_serial' => 'ZTEOLDREAL'],
            ['serial_number' => 'ZTEOLDREAL'],
        );
        $va = $this->verdict($a['migration']);
        $this->assertSame(MigrationReconcileVerdict::SKIP, $va->kind);
        $this->assertSame('migrasi_satu_olt_belum_terbukti', $va->reason);

        // (b) normal path: old_sn null, new_sn = ONU already serving the customer, router_id null
        $b = $this->fixture(
            ['old_olt_id' => 'same', 'old_sn' => null, 'new_sn' => 'ZTEOLDREAL2', 'router_id' => null],
            ['onu_serial' => 'ZTEOLDREAL2'],
            ['serial_number' => 'ZTEOLDREAL2'],
        );
        $vb = $this->verdict($b['migration']);
        $this->assertSame(MigrationReconcileVerdict::SKIP, $vb->kind);
        $this->assertSame('migrasi_satu_olt_belum_terbukti', $vb->reason);
    }

    public function test_same_olt_row_with_fresh_offline_old_onu_is_eligible(): void
    {
        // recognised offline phases reported by the OLT are proof (and are recorded in evidence)
        foreach (['O7', 'LOS', 'DyingGasp', 'OffLine', 'OFFLINE', 'offline'] as $i => $phase) {
            $sn = 'ZTEOLDOFF1'.$i;
            $f = $this->fixture(['old_olt_id' => 'same', 'old_sn' => $sn]);
            OltOnu::create(['olt_id' => $f['olt']->id, 'onu_ref' => '1/1/1:5'.$i, 'serial_number' => $sn, 'state' => 'offline', 'phase_state' => $phase, 'state_collected_at' => now()->subMinutes(5), 'phase_collected_at' => now()->subMinutes(5)]);
            $v = $this->verdict($f['migration']);
            $this->assertSame(MigrationReconcileVerdict::ELIGIBLE, $v->kind, $phase.': '.$v->reason);
            $this->assertSame('old_onu_offline', $v->evidence['same_olt_proof']);
            $this->assertSame($f['olt']->id, $v->evidence['old_onu_row']['olt_id']);
            $this->assertSame('1/1/1:5'.$i, $v->evidence['old_onu_row']['onu_ref']);
            $this->assertSame('offline', $v->evidence['old_onu_row']['state']);
            $this->assertSame($phase, $v->evidence['old_onu_row']['phase_state']);
            $this->assertNotNull($v->evidence['old_onu_row']['state_collected_at']);
        }

        // stale old-ONU row is not proof
        $g = $this->fixture(['old_olt_id' => 'same', 'old_sn' => 'ZTEOLDOFF02']);
        OltOnu::create(['olt_id' => $g['olt']->id, 'onu_ref' => '1/1/1:56', 'serial_number' => 'ZTEOLDOFF02', 'state' => 'offline', 'phase_state' => 'O7', 'state_collected_at' => now()->subDays(2)]);
        $this->assertSkip($g['migration'], 'migrasi_satu_olt_belum_terbukti');

        // fresh state + OLD phase from a past outage (5-min tick refreshes state, not phase) is not proof
        $h = $this->fixture(['old_olt_id' => 'same', 'old_sn' => 'ZTEOLDOFF03']);
        OltOnu::create(['olt_id' => $h['olt']->id, 'onu_ref' => '1/1/1:57', 'serial_number' => 'ZTEOLDOFF03', 'state' => 'offline', 'phase_state' => 'LOS',
            'state_collected_at' => now()->subMinutes(5), 'phase_collected_at' => now()->subDays(3), 'inventory_collected_at' => now()->subDays(3)]);
        $this->assertSkip($h['migration'], 'migrasi_satu_olt_belum_terbukti');

        // no phase stamp at all: not proof
        $i = $this->fixture(['old_olt_id' => 'same', 'old_sn' => 'ZTEOLDOFF04']);
        OltOnu::create(['olt_id' => $i['olt']->id, 'onu_ref' => '1/1/1:58', 'serial_number' => 'ZTEOLDOFF04', 'state' => 'offline', 'phase_state' => 'LOS', 'state_collected_at' => now()->subMinutes(5)]);
        $this->assertSkip($i['migration'], 'migrasi_satu_olt_belum_terbukti');

        // phase stale but inventory (also writes phase_state) fresh: proof
        $j = $this->fixture(['old_olt_id' => 'same', 'old_sn' => 'ZTEOLDOFF05']);
        OltOnu::create(['olt_id' => $j['olt']->id, 'onu_ref' => '1/1/1:59', 'serial_number' => 'ZTEOLDOFF05', 'state' => 'offline', 'phase_state' => 'LOS',
            'state_collected_at' => now()->subMinutes(5), 'phase_collected_at' => now()->subDays(3), 'inventory_collected_at' => now()->subMinutes(10)]);
        $this->assertSame(MigrationReconcileVerdict::ELIGIBLE, $this->verdict($j['migration'])->kind);
    }

    public function test_same_olt_unparsed_or_unknown_old_onu_state_is_not_proof(): void
    {
        // 'unknown' / 'poweroff' raw strings, empty state, and 'offline' with no phase (the collector's
        // value for an unparsed state) must never prove the old ONU is down.
        $cases = [
            'unknown' => ['state' => 'unknown', 'phase_state' => null],
            'poweroff' => ['state' => 'poweroff', 'phase_state' => null],
            'null state' => ['state' => null, 'phase_state' => null],
            'empty state' => ['state' => '', 'phase_state' => null],
            'offline, no phase (unparsed)' => ['state' => 'offline', 'phase_state' => null],
            'unknown + junk phase' => ['state' => 'unknown', 'phase_state' => 'ZZ'],
        ];
        $i = 0;
        foreach ($cases as $label => $row) {
            $sn = 'ZTEOLDUNK'.(++$i);
            $f = $this->fixture(['old_olt_id' => 'same', 'old_sn' => $sn]);
            OltOnu::create(array_merge(['olt_id' => $f['olt']->id, 'onu_ref' => '1/1/1:6'.$i, 'serial_number' => $sn, 'state_collected_at' => now()->subMinutes(5)], $row));
            $v = $this->verdict($f['migration']);
            $this->assertSame(MigrationReconcileVerdict::SKIP, $v->kind, $label);
            $this->assertSame('migrasi_satu_olt_belum_terbukti', $v->reason, $label);
        }
    }

    public function test_same_olt_old_sn_with_one_offline_and_one_live_row_is_blocked(): void
    {
        $f = $this->fixture(['old_olt_id' => 'same', 'old_sn' => 'ZTEOLDMIX01']);
        OltOnu::create(['olt_id' => $f['olt']->id, 'onu_ref' => '1/1/1:71', 'serial_number' => 'ZTEOLDMIX01', 'state' => 'offline', 'phase_state' => 'LOS', 'state_collected_at' => now()->subMinutes(5), 'phase_collected_at' => now()->subMinutes(5)]);
        OltOnu::create(['olt_id' => $f['olt']->id, 'onu_ref' => '1/1/1:72', 'serial_number' => 'ZTEOLDMIX01', 'state' => 'online', 'state_collected_at' => now()->subMinutes(5)]);
        $this->assertSkip($f['migration'], 'onu_lama_masih_online');
    }

    public function test_port_owned_by_another_customer_is_detected_without_new_onu_ref_or_with_a_different_one(): void
    {
        foreach ([null, '1/2/1:99'] as $i => $typedRef) {
            $f = $this->fixture(['new_onu_ref' => $typedRef], [], ['onu_ref' => '1/2/1:7'.$i]);
            $this->assertSame(MigrationReconcileVerdict::ELIGIBLE, $this->verdict($f['migration'])->kind);

            Customer::create([
                'code' => 'OWN-'.$i, 'name' => 'Pemilik Port '.$i, 'phone' => '08130000'.$i, 'address' => 'x',
                'package_id' => $f['customer']->package_id, 'pppoe_user' => 'pemilik.port'.$i,
                'olt_port_label' => '1/2/1:7'.$i, 'olt_id' => $f['olt']->id, 'status' => 'active',
            ]);
            $this->assertSkip($f['migration'], 'sn_milik_pelanggan_lain');
        }
    }

    public function test_older_open_row_with_newer_synced_twin_even_with_null_customer_id_is_not_latest(): void
    {
        $old = $this->fixture(['customer_id' => null, 'pppoe_user' => 'dup.user']);
        $new = $this->fixture(['pppoe_user' => 'dup.user', 'status' => 'synced', 'synced_at' => now()]);
        $this->assertGreaterThan($old['migration']->id, $new['migration']->id);
        $this->assertSkip($old['migration'], 'bukan_baris_terbaru');
    }

    public function test_status_rules(): void
    {
        foreach (['received', 'waiting_sn'] as $status) {
            $f = $this->fixture(['status' => $status]);
            $this->assertSame(MigrationReconcileVerdict::ELIGIBLE, $this->verdict($f['migration'])->kind, $status);
        }
        $f = $this->fixture(['status' => 'weird_status']);
        $this->assertSkip($f['migration'], 'status_tidak_didukung');

        // olt_ok: only Huawei OLT-only is auto; other olt_ok is the admin path
        $huawei = $this->fixture(['status' => 'olt_ok', 'meta' => ['migration_operation_mode' => 'olt_only_huawei', 'input_identity' => ['pppoe_user' => 'x']]]);
        $this->assertSame(MigrationReconcileVerdict::ELIGIBLE, $this->verdict($huawei['migration'])->kind);
        $zte = $this->fixture(['status' => 'olt_ok']);
        $this->assertSame(MigrationReconcileVerdict::PERLU_DICEK_ADMIN, $this->verdict($zte['migration'])->kind);

        foreach (['failed', 'partially_executed'] as $status) {
            $f = $this->fixture(['status' => $status]);
            $this->assertSame(MigrationReconcileVerdict::PERLU_DICEK_ADMIN, $this->verdict($f['migration'])->kind, $status);
        }
    }

    public function test_synced_and_cancelled_are_never_eligible(): void
    {
        $f = $this->fixture(['status' => 'synced', 'synced_at' => now()]);
        $this->assertSkip($f['migration'], 'sudah_selesai');
        $g = $this->fixture(['status' => 'cancelled']);
        $this->assertSkip($g['migration'], 'dibatalkan');
    }

    public function test_device_write_markers_send_waiting_sn_to_admin_path(): void
    {
        $f = $this->fixture(['status' => 'waiting_sn', 'meta' => ['mikrotik_migration' => ['ok' => true], 'input_identity' => ['pppoe_user' => 'a']]]);
        $this->assertSame(MigrationReconcileVerdict::PERLU_DICEK_ADMIN, $this->verdict($f['migration'])->kind);

        $g = $this->fixture();
        DeviceActionLog::create(['domain' => 'migration', 'target_type' => 'customer_migration', 'target_id' => (string) $g['migration']->id,
            'action' => 'migration-device-action-dry-run', 'status' => 'executing', 'idempotency_key' => 'k1', 'payload_hash' => str_repeat('a', 64)]);
        $this->assertSame(MigrationReconcileVerdict::PERLU_DICEK_ADMIN, $this->verdict($g['migration'])->kind);
    }

    public function test_onu_freshness_89_vs_91_minutes_and_inventory_only_is_not_evidence(): void
    {
        $fresh = $this->fixture([], [], ['state_collected_at' => now()->subMinutes(89)]);
        $this->assertSame(MigrationReconcileVerdict::ELIGIBLE, $this->verdict($fresh['migration'])->kind);

        $stale = $this->fixture([], [], ['state_collected_at' => now()->subMinutes(91)]);
        $this->assertSkip($stale['migration'], 'onu_data_basi');

        $inventoryOnly = $this->fixture([], [], ['state_collected_at' => null, 'inventory_collected_at' => now()]);
        $this->assertSkip($inventoryOnly['migration'], 'onu_data_basi');
    }

    public function test_offline_and_rx_only_onu_is_not_online(): void
    {
        $offline = $this->fixture([], [], ['state' => 'offline']);
        $this->assertSkip($offline['migration'], 'onu_offline');

        // blank state with a healthy RX is NOT evidence (RX is never passed)
        $rxOnly = $this->fixture([], [], ['state' => null, 'phase_state' => null, 'rx_power_dbm' => -19.5]);
        $this->assertSkip($rxOnly['migration'], 'onu_offline');

        $phase = $this->fixture([], [], ['state' => null, 'phase_state' => 'O5']);
        $this->assertSame(MigrationReconcileVerdict::ELIGIBLE, $this->verdict($phase['migration'])->kind);
    }

    public function test_huawei_hex_serial_matches_ascii_form(): void
    {
        $f = $this->fixture(['new_sn' => '485754430000A006'], [], ['serial_number' => 'HWTC0000A006']);
        $this->assertSame(MigrationReconcileVerdict::ELIGIBLE, $this->verdict($f['migration'])->kind);
    }

    public function test_duplicate_onu_rows_one_live_ok_two_live_ambiguous(): void
    {
        $f = $this->fixture();
        OltOnu::create(['olt_id' => $f['olt']->id, 'onu_ref' => '1/2/2:9', 'serial_number' => $f['migration']->new_sn, 'state' => 'offline', 'state_collected_at' => now()]);
        $v = $this->verdict($f['migration']);
        $this->assertSame(MigrationReconcileVerdict::ELIGIBLE, $v->kind);
        $this->assertSame(2, $v->evidence['duplicate_rows']);

        OltOnu::create(['olt_id' => $f['olt']->id, 'onu_ref' => '1/2/3:9', 'serial_number' => $f['migration']->new_sn, 'state' => 'online', 'state_collected_at' => now()]);
        $this->assertSkip($f['migration'], 'onu_ambigu');
    }

    public function test_sn_not_on_any_olt_vs_on_another_olt(): void
    {
        $none = $this->fixture([], [], null);
        $this->assertSkip($none['migration'], 'sn_tidak_ditemukan_di_olt_mana_pun');

        $other = $this->fixture([], [], null);
        $olt2 = Olt::create(['name' => 'OLT lain', 'code' => 'OLT-LAIN', 'vendor' => 'zte_c300', 'router_id' => $other['router']->id]);
        OltOnu::create(['olt_id' => $olt2->id, 'onu_ref' => '1/1/1:1', 'serial_number' => $other['migration']->new_sn, 'state' => 'online', 'state_collected_at' => now()]);
        $v = $this->verdict($other['migration']);
        $this->assertSame('onu_tidak_ada_di_olt_tujuan', $v->reason);
        $this->assertSame([$olt2->id], $v->evidence['other_olt_ids']);

        $svc = app(MigrationAutoReconcileService::class);
        $all = $svc->evaluate();
        $missing = $svc->snNotFound(null, $all['verdicts']);
        $this->assertSame([$none['migration']->id], array_column($missing, 'id'));
        $this->assertSame(['id', 'status', 'area_id', 'area', 'created_at'], array_keys($missing[0]));
    }

    public function test_profile_serial_fallback_success_and_failures(): void
    {
        // wrong new_sn typed, customer profile serial is live on the target OLT
        $f = $this->fixture(['new_sn' => 'FHTTWRONG0001'], ['onu_serial' => 'FHTTREAL0001'], ['serial_number' => 'FHTTREAL0001']);
        $v = $this->verdict($f['migration']);
        $this->assertSame(MigrationReconcileVerdict::ELIGIBLE, $v->kind, $v->reason);
        $this->assertSame('customer_profile', $v->evidence['sn_source']);
        $this->assertSame('FHTTREAL0001', $v->evidence['effective_sn']);
        $this->assertSame('FHTTWRONG0001', $v->evidence['original_new_sn']);
        // circular in fallback (key == profile serial): not a link proof
        $this->assertStringNotContainsString('serial_on_customer', (string) $v->linkProof);

        // profile serial only on another OLT
        $g = $this->fixture(['new_sn' => 'FHTTWRONG0002'], ['onu_serial' => 'FHTTREAL0002'], null);
        $olt2 = Olt::create(['name' => 'OLT lain 2', 'code' => 'OLT-LAIN2', 'vendor' => 'zte_c300', 'router_id' => $g['router']->id]);
        OltOnu::create(['olt_id' => $olt2->id, 'onu_ref' => '1/1/1:2', 'serial_number' => 'FHTTREAL0002', 'state' => 'online', 'state_collected_at' => now()]);
        $this->assertSkip($g['migration'], 'sn_profil_di_olt_lain');

        // profile serial owned by another customer
        $h = $this->fixture(['new_sn' => 'FHTTWRONG0003'], ['onu_serial' => 'FHTTREAL0003'], ['serial_number' => 'FHTTREAL0003']);
        $other = $this->fixture();
        Customer::query()->whereKey($other['customer']->id)->update(['onu_serial' => 'FHTTREAL0003']);
        $this->assertSkip($h['migration'], 'sn_milik_pelanggan_lain');

        // both missing
        $i = $this->fixture(['new_sn' => 'FHTTWRONG0004'], ['onu_serial' => 'FHTTREAL0004'], null);
        $this->assertSkip($i['migration'], 'sn_tidak_ditemukan_di_olt_mana_pun');
        $svc = app(MigrationAutoReconcileService::class);
        $ids = array_column($svc->snNotFound(), 'id');
        $this->assertContains($i['migration']->id, $ids);
    }

    public function test_customer_resolution_rules(): void
    {
        $byPppoe = $this->fixture(['customer_id' => null]);
        $v = $this->verdict($byPppoe['migration']);
        $this->assertSame(MigrationReconcileVerdict::ELIGIBLE, $v->kind, $v->reason);
        $this->assertTrue($v->evidence['customer_resolved_by_pppoe']);

        $none = $this->fixture(['customer_id' => null, 'pppoe_user' => 'tidak.ada', 'meta' => ['input_identity' => ['pppoe_user' => 'tidak.ada']]]);
        $this->assertSkip($none['migration'], 'pelanggan_tidak_ketemu');

        // (pelanggan_ambigu cannot be built here: customers.pppoe_user is UNIQUE, so it is a pure safety net)

        $term = $this->fixture([], ['status' => 'terminated']);
        $this->assertSkip($term['migration'], 'pelanggan_terminated');

        $diff = $this->fixture(['pppoe_user' => 'orang.lain']);
        $this->assertSkip($diff['migration'], 'pppoe_beda');

        $gone = $this->fixture();
        $gone['customer']->delete();
        $this->assertSkip($gone['migration'], 'pelanggan_tidak_ada');
    }

    public function test_pppoe_online_rules(): void
    {
        $this->assertSkip($this->fixture([], ['is_online' => false])['migration'], 'pppoe_offline');
        $this->assertSkip($this->fixture([], ['pppoe_online_checked_at' => now()->subMinutes(31)])['migration'], 'pppoe_data_basi');
        $this->assertSame(MigrationReconcileVerdict::ELIGIBLE, $this->verdict($this->fixture([], ['pppoe_online_checked_at' => now()->subMinutes(29)])['migration'])->kind);

        $f = $this->fixture();
        $other = $this->fixture();
        Customer::query()->whereKey($f['customer']->id)->update(['router_id' => $other['router']->id]);
        $this->assertSkip($f['migration'], 'pppoe_tercatat_di_router_lain');

        $svc = app(MigrationAutoReconcileService::class);
        $g = $this->fixture();
        $res = $svc->evaluate([$g['migration']->id], array_merge($svc->settings(), ['column_ok' => false]));
        $this->assertSame('kolom_pppoe_tidak_ada', $res['verdicts'][0]->reason);
    }

    public function test_ownership_and_link_rules(): void
    {
        $f = $this->fixture();
        $other = $this->fixture();
        Customer::query()->whereKey($other['customer']->id)->update(['onu_serial' => $f['migration']->new_sn]);
        $this->assertSkip($f['migration'], 'sn_milik_pelanggan_lain');

        $g = $this->fixture();
        CustomerMigration::create(['status' => 'received', 'source' => 'wa', 'pppoe_user' => 'lain.lagi', 'new_sn' => $g['migration']->new_sn]);
        $this->assertSkip($g['migration'], 'sn_di_migrasi_lain');

        $h = $this->fixture();
        DB::table('psb_drafts')->insert(['status' => 'waiting_sn', 'sn' => $h['migration']->new_sn, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSkip($h['migration'], 'sn_di_psb_terbuka');

        $i = $this->fixture(['meta' => ['input_identity' => ['pppoe_user' => '']]], [], ['onu_name' => 'zzzz']);
        $this->assertSkip($i['migration'], 'kaitan_onu_belum_terbukti');

        $j = $this->fixture(['meta' => ['input_identity' => ['pppoe_user' => ''], 'guided_wa_confirmation' => ['status' => 'confirmed']]], [], ['onu_name' => 'zzzz']);
        $this->assertSame('guided_confirmed', $this->verdict($j['migration'])->linkProof);

        $k = $this->fixture(['meta' => ['guided_wa_confirmation' => ['status' => 'pending']]]);
        $this->assertSkip($k['migration'], 'pilihan_kandidat_belum_dikonfirmasi');

        foreach (['DUPLICATE_MIGRATION', 'CONFLICT', 'NOT_FOUND', 'PENDING_TECHNICIAN_CONFIRMATION'] as $op) {
            $x = $this->fixture(['meta' => ['migration_operation' => ['operation_type' => $op], 'input_identity' => ['pppoe_user' => 'a']]]);
            $this->assertSkip($x['migration'], 'identitas_duplikat_atau_konflik');
        }
    }

    public function test_serial_on_customer_alone_is_a_link_proof(): void
    {
        $f = $this->fixture(['meta' => ['input_identity' => ['pppoe_user' => '']]], ['onu_serial' => 'FHTTMATCH001'], ['serial_number' => 'FHTTMATCH001', 'onu_name' => 'zzzz']);
        Customer::query()->whereKey($f['customer']->id)->update(['onu_serial' => 'FHTTMATCH001']);
        CustomerMigration::query()->whereKey($f['migration']->id)->update(['new_sn' => 'FHTTMATCH001']);
        $this->assertSame('serial_on_customer', $this->verdict($f['migration']->fresh())->linkProof);
    }

    public function test_old_onu_still_online_blocks(): void
    {
        $f = $this->fixture();
        OltOnu::create(['olt_id' => $f['old_olt']->id, 'onu_ref' => '1/1/1:1', 'serial_number' => $f['migration']->old_sn, 'state' => 'online', 'state_collected_at' => now()]);
        $this->assertSkip($f['migration'], 'onu_lama_masih_online');
    }

    public function test_age_queue_and_ledger_gates_for_apply(): void
    {
        $this->assertSkip($this->fixture([], [], [], ['updated' => 10])['migration'], 'baru_diubah');
        $this->assertSkip($this->fixture([], [], [], ['created' => 50, 'updated' => 40])['migration'], 'terlalu_baru');

        $q = $this->fixture(['meta' => [
            'input_identity' => ['pppoe_user' => 'a'],
            'wa_live_execution_queued' => ['queued_at' => now()->subHours(2)->toIso8601String()],
            'device_action_approval' => ['status' => 'dry_run_ready'],
        ]]);
        $this->assertSkip($q['migration'], 'eksekusi_antre');

        $old = $this->fixture(['meta' => [
            'input_identity' => ['pppoe_user' => 'a'],
            'wa_live_execution_queued' => ['queued_at' => now()->subHours(30)->toIso8601String()],
            'device_action_approval' => ['status' => 'dry_run_ready'],
        ]]);
        $this->assertSame(MigrationReconcileVerdict::ELIGIBLE, $this->verdict($old['migration'])->kind);
    }

    public function test_only_latest_row_per_identity_is_evaluated(): void
    {
        $f = $this->fixture();
        $newer = CustomerMigration::create(['status' => 'received', 'source' => 'wa', 'customer_id' => $f['customer']->id, 'pppoe_user' => $f['migration']->pppoe_user]);
        $res = app(MigrationAutoReconcileService::class)->evaluate();
        $byId = [];
        foreach ($res['verdicts'] as $v) {
            $byId[$v->migrationId] = $v;
        }
        $this->assertSame('bukan_baris_terbaru', $byId[$f['migration']->id]->reason);
        $this->assertArrayHasKey($newer->id, $byId);
        $this->assertSame(1, $res['histogram']['received']);
    }

    public function test_settings_defaults_and_clamps(): void
    {
        $s = app(MigrationAutoReconcileService::class)->settings();
        $this->assertTrue($s['enabled']);
        $this->assertFalse($s['apply']);
        $this->assertSame(90, $s['onu_max_age']);
        $this->assertSame(30, $s['pppoe_max_age']);
        $this->assertSame(30, $s['quiet']);
        $this->assertSame(50, $s['max_per_run']);

        Setting::set('migration_auto_reconcile_max_per_run', 9999);
        Setting::set('migration_auto_reconcile_onu_max_age_minutes', 1);
        $s = app(MigrationAutoReconcileService::class)->settings();
        $this->assertSame(500, $s['max_per_run']);
        $this->assertSame(15, $s['onu_max_age']);
    }

    public function test_admin_api_filters_by_area(): void
    {
        $f = $this->fixture(['status' => 'failed', 'area_id' => 7]);
        $g = $this->fixture(['status' => 'failed', 'area_id' => 8]);
        $svc = app(MigrationAutoReconcileService::class);
        $this->assertCount(2, $svc->perluDicekAdmin());
        $only = $svc->perluDicekAdmin([7]);
        $this->assertSame([$f['migration']->id], array_map(fn ($v) => $v->migrationId, $only));
        $this->assertNotNull($g);
    }

    public function test_evaluator_performs_zero_writes(): void
    {
        $this->fixture();
        $this->fixture(['status' => 'failed']);
        $this->fixture([], [], null);
        $before = [
            CustomerMigration::query()->orderBy('id')->get()->toJson(),
            Customer::query()->orderBy('id')->get()->toJson(),
            DB::table('olt_onus')->orderBy('id')->get()->toJson(),
            DB::table('device_action_logs')->count(),
            DB::table('settings')->count(),
        ];

        $writes = [];
        DB::listen(function (QueryExecuted $q) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|replace|alter|create|drop|truncate)\b/i', $q->sql) === 1) {
                $writes[] = $q->sql;
            }
        });

        $svc = app(MigrationAutoReconcileService::class);
        $svc->evaluate();
        $svc->perluDicekAdmin();
        $svc->snNotFound();

        $this->assertSame([], $writes);
        $this->assertSame($before, [
            CustomerMigration::query()->orderBy('id')->get()->toJson(),
            Customer::query()->orderBy('id')->get()->toJson(),
            DB::table('olt_onus')->orderBy('id')->get()->toJson(),
            DB::table('device_action_logs')->count(),
            DB::table('settings')->count(),
        ]);
    }
}
