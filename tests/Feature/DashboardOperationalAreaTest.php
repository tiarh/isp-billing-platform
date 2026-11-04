<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\CustomerMigration;
use App\Models\PsbDraft;
use App\Models\PsbStarliteEntry;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-core')]
class DashboardOperationalAreaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-07-25 10:00:00', 'Asia/Jakarta'));
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_dashboard_operational_area_separates_migration_psb_and_flags_default_rw_review(): void
    {
        $randuagung = Area::create(['code' => 'RA', 'name' => 'Randuagung']);
        Area::create(['code' => 'KRIAN', 'name' => 'Krian']);

        CustomerMigration::create([
            'status' => CustomerMigration::STATUS_SYNCED,
            'source' => 'wa',
            'pppoe_user' => 'KARTONO',
            'full_name' => 'Kartono',
            'rw' => '04',
            'daerah' => 'Randuagung',
            'area_id' => $randuagung->id,
            'old_sn' => 'ZTE123',
            'new_sn' => 'FHTT123456',
            'created_at' => now(),
            'synced_at' => now(),
        ]);
        CustomerMigration::create([
            'status' => CustomerMigration::STATUS_RECEIVED,
            'source' => 'excel_master',
            'pppoe_user' => 'SKYNETRA',
            'full_name' => 'Excel Area Alias',
            'rw' => null,
            'daerah' => 'Skynetranduagung',
            'old_sn' => 'ZTEALIAS',
            'new_sn' => null,
            'created_at' => now(),
        ]);

        PsbStarliteEntry::create([
            'installed_on' => '2026-07-23',
            'rw' => null,
            'full_name' => 'Poniti',
            'pppoe_user' => 'PONITI',
            'sn' => 'FHTT999',
            'service_type' => 'PSB',
            'install_status' => 'Success',
            'source' => 'excel',
        ]);
        PsbStarliteEntry::create([
            'installed_on' => '2026-07-23',
            'rw' => '11',
            'full_name' => 'RW KTP Beda',
            'pppoe_user' => 'RWKTPBEDA',
            'sn' => 'FHTT998',
            'service_type' => 'PSB',
            'install_status' => 'Success',
            'source' => 'excel',
        ]);

        PsbDraft::create([
            'status' => PsbDraft::STATUS_OLT_OK,
            'activation_mode' => PsbDraft::MODE_FULL_OLT,
            'site_profile_key' => 'krian',
            'group_jid' => 'work@wa',
            'message_id' => 'krian-001',
            'full_name' => 'Krian Baru',
            'rw' => '10',
            'daerah' => 'Krian',
            'sn' => 'FHTTKRIAN',
            'pppoe_user' => 'KRIAN001',
            'created_at' => now(),
            'olt_at' => now(),
        ]);

        $user = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($user)->getJson(route('api.finance.per-area.operations', ['period' => '2026-07', 'project' => 'randuagung-starlite']));

        $response->assertOk();
        $ops = $response->json();

        $this->assertSame('starlite-randuagung', $ops['filters']['project']);
        $this->assertSame(0, $ops['summary']['migration_target']);
        $this->assertSame(2, $ops['summary']['psb_this_month'], json_encode($ops));
        $this->assertSame(0, $ops['summary']['needs_review']);
        $this->assertTrue(collect($ops['rows'])->contains(fn ($row) => $row['project'] === 'starlite-randuagung' && $row['area'] === 'randuagung' && $row['rw'] === 'RW 4'));
        $this->assertSame(1, collect($ops['rows'])->where('project', 'starlite-randuagung')->count());
        $this->assertStringContainsString('/psb-wa/dashboard', $ops['drilldowns']['psb_url']);

        $all = $this->actingAs($user)->getJson(route('api.finance.per-area.operations', ['period' => '2026-07']))->json();
        $this->assertSame(2, $all['summary']['migration_target']);
        $this->assertSame(1, $all['summary']['migrated']);
        $this->assertSame(1, collect($all['rows'])->where('project', 'starlite-akuisisi')->where('area', 'randuagung')->count());
        $this->assertFalse(collect($all['rows'])->contains(fn ($row) => $row['area'] === 'skynetranduagung'));
        $this->assertTrue(collect($all['rows'])->contains(fn ($row) => $row['area'] === 'krian' && $row['rw'] === 'RW 10'));
    }

    public function test_dashboard_prefers_live_migration_evidence_after_cutoff_over_excel_snapshot_for_same_pppoe(): void
    {
        $area = Area::create(['code' => 'RA', 'name' => 'Randuagung']);
        CustomerMigration::create([
            'status' => CustomerMigration::STATUS_RECEIVED,
            'source' => 'excel_master',
            'pppoe_user' => 'SAME_PPP',
            'daerah' => 'Randuagung',
            'area_id' => $area->id,
            'created_at' => Carbon::parse('2026-07-23 10:00:00', 'Asia/Jakarta'),
        ]);
        CustomerMigration::create([
            'status' => CustomerMigration::STATUS_SYNCED,
            'source' => 'guided_lookup_wa',
            'pppoe_user' => 'same_ppp',
            'daerah' => 'Suko',
            'area_id' => $area->id,
            'new_sn' => 'FHTT-LIVE-001',
            'created_at' => Carbon::parse('2026-07-25 10:00:00', 'Asia/Jakarta'),
            'synced_at' => Carbon::parse('2026-07-25 10:00:00', 'Asia/Jakarta'),
        ]);

        $user = User::factory()->create(['role' => 'admin']);
        $ops = $this->actingAs($user)->getJson(route('api.finance.per-area.operations', ['period' => '2026-07']))->json();

        $this->assertSame(1, $ops['summary']['migration_target']);
        $this->assertSame(1, $ops['summary']['migrated']);
        $this->assertTrue(collect($ops['rows'])->contains(fn ($row) => $row['area'] === 'suko'));
    }

    public function test_dashboard_ignores_ops_filters_and_returns_only_compact_summary(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($user)->get('/dashboard?ops_month=2026-01&ops_project=krian&ops_rw=10&ops_sub_area=x');

        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $ops = $props['operational_area'];

        $this->assertEqualsCanonicalizing(['summary', 'last_sync', 'stale', 'drilldowns'], array_keys($ops));
        $this->assertSame('Semua area', $props['area_label']);
        $this->assertStringContainsString('2026-07-01', $ops['drilldowns']['psb_url']);
    }
}
