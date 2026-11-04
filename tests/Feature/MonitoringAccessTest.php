<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * M-4b: the whole Monitoring page (all tabs incl. Gangguan and NOC) is for
 * superadmin, noc and unscoped (global) admin only.
 */
#[Group('blocking-core')]
class MonitoringAccessTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: string}> */
    public static function pages(): array
    {
        return [
            'index' => ['/monitoring'],
            'index jaringan tab' => ['/monitoring?tab=jaringan'],
            'noc' => ['/monitoring/noc'],
        ];
    }

    private function user(string $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function scopedAdmin(): User
    {
        $admin = $this->user('admin');
        $admin->areas()->attach(Area::create(['code' => 'A', 'name' => 'Area A']));

        return $admin;
    }

    #[DataProvider('pages')]
    public function test_teknisi_is_forbidden(string $url): void
    {
        $this->actingAs($this->user('teknisi'))->get($url)->assertForbidden();
    }

    #[DataProvider('pages')]
    public function test_area_scoped_admin_is_forbidden(string $url): void
    {
        $this->actingAs($this->scopedAdmin())->get($url)->assertForbidden();
    }

    public function test_connectivity_alert_settings_are_global_admin_only(): void
    {
        $url = '/api/monitoring/connectivity/settings';

        foreach ([$this->user('teknisi'), $this->scopedAdmin()] as $denied) {
            $this->actingAs($denied)->getJson($url)->assertForbidden();
            $this->actingAs($denied)->postJson($url, [])->assertForbidden();
        }

        foreach (['superadmin', 'noc', 'admin'] as $role) {
            $this->assertNotSame(403, $this->actingAs($this->user($role))->getJson($url)->getStatusCode());
        }
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/monitoring')->assertRedirect('/login');
        $this->get('/monitoring/noc')->assertRedirect('/login');
    }

    #[DataProvider('pages')]
    public function test_superadmin_noc_and_unscoped_admin_can_open(string $url): void
    {
        foreach (['superadmin', 'noc', 'admin'] as $role) {
            $response = $this->actingAs($this->user($role))->get($url);
            $this->assertContains($response->getStatusCode(), [200, 302], "Role [{$role}] harus boleh membuka {$url}.");
            $this->assertNotSame(403, $response->getStatusCode());
        }
    }

    public function test_global_roles_get_200_on_monitoring_index(): void
    {
        foreach (['superadmin', 'noc', 'admin'] as $role) {
            $this->actingAs($this->user($role))->get('/monitoring')->assertOk();
        }
    }

    public function test_sidebar_monitoring_item_requires_global_admin(): void
    {
        $source = (string) file_get_contents(resource_path('js/Components/AppSidebar.tsx'));

        $this->assertMatchesRegularExpression(
            "/\\{[^{}]*name:\\s*'Monitoring'[^{}]*route:\\s*'monitoring\\.index'[^{}]*access:\\s*'global_admin'[^{}]*\\}/",
            $source,
        );
    }
}
