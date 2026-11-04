<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class IsolationPortalSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_can_save_canonical_area_allowlist(): void
    {
        $user = User::factory()->create(['role' => 'superadmin']);
        $area = Area::create(['code' => 'ISO-1', 'name' => '  Gadang   Utara ']);

        $response = $this->actingAs($user)->postJson(route('api.settings.isolation-portal'), [
            'enabled' => true,
            'test_mode' => false,
            'test_ip' => '198.18.31.18',
            'areas' => ['gadang utara'],
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('isolation_portal.areas.0', 'Gadang   Utara');

        $this->assertSame(['Gadang   Utara'], Setting::get('isolation_portal_areas'));
    }

    public function test_unknown_area_is_rejected_without_writing_settings(): void
    {
        $user = User::factory()->create(['role' => 'superadmin']);
        Setting::set('isolation_portal_areas', ['Existing Area']);

        $this->actingAs($user)->postJson(route('api.settings.isolation-portal'), [
            'enabled' => true,
            'test_mode' => false,
            'test_ip' => '198.18.31.18',
            'areas' => ['Not A Real Area'],
        ])->assertStatus(422);

        $this->assertSame(['Existing Area'], Setting::get('isolation_portal_areas'));
    }

    public function test_non_superadmin_cannot_change_portal_settings(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        Area::create(['code' => 'ISO-2', 'name' => 'Gadang']);

        $this->actingAs($user)->postJson(route('api.settings.isolation-portal'), [
            'enabled' => true,
            'test_mode' => false,
            'test_ip' => '198.18.31.18',
            'areas' => ['Gadang'],
        ])->assertForbidden();
    }

    public function test_empty_area_allowlist_remains_fail_closed(): void
    {
        $user = User::factory()->create(['role' => 'superadmin']);

        $this->actingAs($user)->postJson(route('api.settings.isolation-portal'), [
            'enabled' => true,
            'test_mode' => false,
            'test_ip' => '198.18.31.18',
            'areas' => [],
        ])->assertOk();

        Cache::forget('setting.isolation_portal_areas');
        $this->assertSame([], Setting::get('isolation_portal_areas'));
    }

    public function test_contact_info_saves_with_no_area_selected_and_areas_key_stays_mandatory(): void
    {
        $user = User::factory()->create(['role' => 'superadmin']);
        Setting::set('isolation_portal_areas', ['Existing Area'], 'json', 'isolation_portal');

        $this->actingAs($user)->postJson(route('api.settings.isolation-portal'), [
            'enabled' => true,
            'test_mode' => false,
            'test_ip' => '198.18.31.18',
            'areas' => [],
            'wa_admin' => '0812-0000-0000',
        ])->assertOk();

        $this->assertSame('0812-0000-0000', Setting::get('isolation_portal_wa_admin'));
        $this->assertSame([], Setting::get('isolation_portal_areas'));

        // Omitting `areas` entirely is still a validation error, so a partial
        // client cannot silently wipe the allowlist.
        Setting::set('isolation_portal_areas', ['Existing Area'], 'json', 'isolation_portal');
        $this->actingAs($user)->postJson(route('api.settings.isolation-portal'), [
            'enabled' => true,
            'test_mode' => false,
            'test_ip' => '198.18.31.18',
        ])->assertStatus(422)->assertJsonValidationErrors('areas');
        $this->assertSame(['Existing Area'], Setting::get('isolation_portal_areas'));
    }

    public function test_wildcard_is_stored_without_other_area_values(): void
    {
        $user = User::factory()->create(['role' => 'superadmin']);

        $this->actingAs($user)->postJson(route('api.settings.isolation-portal'), [
            'enabled' => true,
            'test_mode' => false,
            'test_ip' => '198.18.31.18',
            'areas' => ['*', 'Not A Real Area'],
        ])->assertOk();

        $this->assertSame(['*'], Setting::get('isolation_portal_areas'));
    }

    public function test_test_mode_requires_a_valid_canary_ip_without_writing(): void
    {
        $user = User::factory()->create(['role' => 'superadmin']);
        Setting::set('isolation_portal_test_mode', '0', 'boolean', 'isolation_portal');
        Setting::set('isolation_portal_test_ip', '198.18.31.18', 'text', 'isolation_portal');

        $this->actingAs($user)->postJson(route('api.settings.isolation-portal'), [
            'enabled' => true,
            'test_mode' => true,
            'test_ip' => '',
            'areas' => [],
        ])->assertStatus(422);

        $this->assertFalse(Setting::get('isolation_portal_test_mode'));
        $this->assertSame('198.18.31.18', Setting::get('isolation_portal_test_ip'));
    }

    public function test_settings_page_exposes_portal_snapshot_and_area_catalog(): void
    {
        $user = User::factory()->create(['role' => 'superadmin']);
        Area::create(['code' => 'ISO-3', 'name' => 'Gadang']);
        Setting::set('isolation_portal_enabled', '1', 'boolean', 'isolation_portal');
        Setting::set('isolation_portal_areas', ['Gadang'], 'json', 'isolation_portal');

        $this->actingAs($user)->get(route('settings.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Settings/Index')
                ->where('isolation_portal.enabled', true)
                ->where('isolation_portal.areas', ['Gadang'])
                ->where('areas.0.name', 'Gadang'));
    }
}
