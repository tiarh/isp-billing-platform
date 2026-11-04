<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\PsbDraft;
use App\Models\Setting;
use App\Models\User;
use App\Services\HuaweiAwarePsbOrchestratorService;
use App\Services\PsbOrchestratorService;
use App\Services\PsbSiteRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-psb')]
class PsbMultisiteSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_psb_site_settings_keep_ra_and_huawei_targets_together(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Setting::set('psb_router_id', '513', 'integer', 'psb');
        Setting::set('psb_package_id', '50', 'integer', 'psb');
        Setting::set('psb_olt_id', '1', 'integer', 'psb');

        $this->actingAs($admin)->postJson(route('psb-drafts.settings'), [
            'psb_wa_group_ids' => ['120363-aktivasi@g.us'],
            'psb_site_settings' => [
                'ra' => ['router_id' => 513, 'package_id' => 50, 'olt_id' => 1],
                'gadang_huawei' => ['router_id' => 511, 'package_id' => 200, 'olt_id' => 11],
            ],
        ])->assertOk();

        $settings = app(PsbSiteRegistry::class)->settingsForUi();
        $this->assertSame(513, $settings['ra']['router_id']);
        $this->assertSame(1, $settings['ra']['olt_id']);
        $this->assertSame(511, $settings['gadang_huawei']['router_id']);
        $this->assertSame(11, $settings['gadang_huawei']['olt_id']);
        $this->assertSame(513, (int) Setting::get('psb_router_id'));
        $this->assertSame(1, (int) Setting::get('psb_olt_id'));
    }

    public function test_unknown_site_key_is_rejected_without_saving_site_settings(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->postJson(route('psb-drafts.settings'), [
            'psb_site_settings' => [
                'wrong-site' => ['router_id' => 999, 'olt_id' => 999],
            ],
        ])->assertStatus(422);

        $this->assertDatabaseMissing('settings', ['key' => 'psb_site_settings']);
    }

    public function test_huawei_psb_resolves_gadang_area_from_daerah_not_starlite(): void
    {
        $gadang = Area::create(['code' => 'GADANG', 'name' => 'Gadang']);
        Area::create(['code' => 'RDG-STARLITE', 'name' => 'Randuagung Starlite']);
        $draft = new PsbDraft([
            'daerah' => 'Gadang',
            'site_profile_key' => 'gadang_huawei',
        ]);

        $resolveArea = new \ReflectionMethod(PsbOrchestratorService::class, 'resolveArea');
        $resolveArea->invoke(app(HuaweiAwarePsbOrchestratorService::class), $draft);

        $this->assertSame($gadang->id, $draft->area_id);
        $this->assertNotSame('Randuagung Starlite', Area::find($draft->area_id)?->name);
    }

    public function test_legacy_scalar_settings_contract_still_saves_without_site_map(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->postJson(route('psb-drafts.settings'), [
            'psb_router_id' => 513,
            'psb_package_id' => 50,
            'psb_olt_id' => 1,
            'psb_acs_url' => 'http://203.0.113.106:7547',
        ])->assertOk();

        $this->assertSame(513, (int) Setting::get('psb_router_id'));
        $this->assertSame(50, (int) Setting::get('psb_package_id'));
        $this->assertSame(1, (int) Setting::get('psb_olt_id'));
    }
}
