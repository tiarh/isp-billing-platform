<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Router;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * M-4c-2b: the router detail page route (data comes from the 2a API).
 */
#[Group('blocking-core')]
class MonitoringRouterDetailPageTest extends TestCase
{
    use RefreshDatabase;

    private function router(int $id, bool $active = true): Router
    {
        $router = new Router([
            'name' => 'Site '.$id,
            'ip_address' => '192.0.2.'.($id % 250),
            'username' => 'u',
            'password' => 'x',
            'port' => 8728,
            'is_active' => $active,
        ]);
        $router->id = $id;
        $router->save();

        return $router;
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->router(5);

        $this->get('/monitoring/routers/5')->assertRedirect('/login');
    }

    public function test_teknisi_and_area_scoped_admin_get_403(): void
    {
        $this->router(5);

        $scoped = User::factory()->create(['role' => 'admin']);
        $scoped->areas()->attach(Area::create(['code' => 'A', 'name' => 'Area A']));

        foreach ([User::factory()->create(['role' => 'teknisi']), $scoped] as $denied) {
            $this->actingAs($denied)->get('/monitoring/routers/5')->assertForbidden();
        }
    }

    public function test_global_admins_get_the_page_with_tiny_props(): void
    {
        $this->router(5);

        foreach (['superadmin', 'noc', 'admin'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->get('/monitoring/routers/5')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Monitoring/RouterDetail')
                    ->where('router.id', 5)
                    ->where('router.name', 'Site 5')
                    ->where('router.is_active', true)
                    ->where('krianInvalidThrough', null)
                    ->has('historyStart')
                    ->missing('router.ip_address')
                    ->missing('router.password'));
        }
    }

    public function test_inactive_router_page_still_opens_for_history(): void
    {
        $this->router(6, false);

        $this->actingAs(User::factory()->create(['role' => 'superadmin']))
            ->get('/monitoring/routers/6')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('router.is_active', false));
    }

    public function test_krian_router_page_carries_the_invalid_through_date(): void
    {
        $this->router(15);

        $this->actingAs(User::factory()->create(['role' => 'superadmin']))
            ->get('/monitoring/routers/15')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('krianInvalidThrough', '2026-10-10'));
    }

    public function test_edge_unknown_and_non_numeric_routers_are_404(): void
    {
        $this->router(900);
        $user = User::factory()->create(['role' => 'superadmin']);

        $this->actingAs($user)->get('/monitoring/routers/900')->assertNotFound();
        $this->actingAs($user)->get('/monitoring/routers/4242')->assertNotFound();
        $this->actingAs($user)->get('/monitoring/routers/abc')->assertNotFound();
    }
}
