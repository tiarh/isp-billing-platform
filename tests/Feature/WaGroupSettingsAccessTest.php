<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\FakesWaGroups;
use Tests\TestCase;

/** Access matrix of the Grup WhatsApp page: superadmin + unscoped admin only; no TOTP step-up on save. */
#[Group('blocking-core')]
class WaGroupSettingsAccessTest extends TestCase
{
    use FakesWaGroups;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedWaSettings();
        $this->fakeEvolution();
    }

    public function test_guest_is_redirected_to_login_on_every_route(): void
    {
        $this->get(route('settings.wa-groups'))->assertRedirect(route('login'));
        $this->getJson(route('api.settings.wa-groups.groups'))->assertUnauthorized();
        $this->postJson(route('api.settings.wa-groups.membership'), ['channel' => 'ops', 'jid' => self::G_NEW])->assertUnauthorized();
        $this->put(route('settings.wa-groups.update'), ['section' => 'undelivered'])->assertRedirect(route('login'));
    }

    public function test_superadmin_and_unscoped_admin_can_open_the_page_and_read_the_lists(): void
    {
        foreach ([$this->superadmin(), $this->unscopedAdmin()] as $user) {
            $this->actingAs($user)->get(route('settings.wa-groups'))->assertOk()
                ->assertInertia(fn ($page) => $page->component('Settings/WaGroups')->has('settings')->has('version'));
            $this->actingAs($user)->getJson(route('api.settings.wa-groups.groups'))->assertOk();
            $this->actingAs($user)->postJson(route('api.settings.wa-groups.membership'), ['channel' => 'ops', 'jid' => self::G_NEW])->assertOk();
        }
    }

    public function test_noc_scoped_admin_and_technician_get_403_on_every_route(): void
    {
        $before = Setting::get('report_undelivered_alert_jid');
        foreach ([$this->noc(), $this->scopedAdmin(), $this->teknisi()] as $user) {
            $this->actingAs($user)->get(route('settings.wa-groups'))->assertForbidden();
            $this->actingAs($user)->getJson(route('api.settings.wa-groups.groups'))->assertForbidden();
            $this->actingAs($user)->postJson(route('api.settings.wa-groups.membership'), ['channel' => 'ops', 'jid' => self::G_NEW])->assertForbidden();
            $this->putSection($user, 'undelivered', ['jid' => self::G_UNDELIVERED])->assertForbidden();
        }
        Setting::flushRequestMemo();
        $this->assertSame($before, Setting::get('report_undelivered_alert_jid'));
    }

    public function test_unscoped_admin_saves_without_a_totp_step_up(): void
    {
        $this->putSection($this->unscopedAdmin(), 'undelivered', ['jid' => self::G_UNDELIVERED])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        Setting::flushRequestMemo();
        $this->assertSame(self::G_UNDELIVERED, Setting::get('report_undelivered_alert_jid'));
    }

    public function test_superadmin_without_totp_enrolled_saves_directly_not_redirected_to_enrolment(): void
    {
        $response = $this->putSection($this->superadmin(), 'undelivered', ['jid' => self::G_UNDELIVERED]);

        $response->assertSessionHasNoErrors();
        $this->assertStringNotContainsString('totp', (string) $response->headers->get('Location'));
        $this->assertStringNotContainsString('step-up', (string) $response->headers->get('Location'));
        Setting::flushRequestMemo();
        $this->assertSame(self::G_UNDELIVERED, Setting::get('report_undelivered_alert_jid'));
    }

    public function test_save_route_is_not_behind_the_control_plane_totp_middleware(): void
    {
        $route = app('router')->getRoutes()->getByName('settings.wa-groups.update');
        $this->assertNotNull($route);
        $this->assertNotContains('control-plane-totp', $route->gatherMiddleware());
    }
}
