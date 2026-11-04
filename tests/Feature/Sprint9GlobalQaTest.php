<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-core')]
class Sprint9GlobalQaTest extends TestCase
{
    use RefreshDatabase;

    public function test_sidebar_has_no_dead_portal_or_hash_routes(): void
    {
        $source = file_get_contents(resource_path('js/Components/AppSidebar.tsx'));

        $this->assertStringNotContainsString('Portal Pelanggan', $source);
        $this->assertStringNotContainsString("href = '#'", $source);
        $this->assertStringNotContainsString("href='#'", $source);
        $this->assertStringNotContainsString('href="#"', $source);

        preg_match_all("/route:\\s*'([^']+)'/", $source, $matches);
        $routes = array_unique($matches[1] ?? []);

        $this->assertNotEmpty($routes);
        foreach ($routes as $route) {
            $this->assertTrue(Route::has($route), "Sidebar route [{$route}] harus terdaftar.");
        }
    }

    public function test_main_authenticated_routes_do_not_return_500_for_superadmin(): void
    {
        $this->withoutExceptionHandling();

        $user = User::factory()->create(['role' => 'superadmin']);

        $routes = [
            'dashboard',
            'customers.index',
            'psb-drafts.dashboard',
            'customer-migrations.index',
            'attendances.index',
            'invoices.index',
            'modules.payment-gateway',
            'analytics.index',
            'packages.index',
            'wa-gateway.pair',
            'broadcasts.index',
            'tickets.index',
            'monitoring.index',
            'monitoring-targets.index',
            'peta.index',
            'inventaris.index',
            'routers.index',
            'olts.index',
            'areas.index',
            'oltc300.index',
            'modules.reseller',
            'modules.genieacs',
            'modules.support',
            'settings.index',
            'users.index',
            'production-control-plane.index',
        ];

        foreach ($routes as $route) {
            $response = $this->actingAs($user)->get(route($route));
            $this->assertNotSame(500, $response->getStatusCode(), "Route [{$route}] tidak boleh 500.");
        }

        $analytics = $this->actingAs($user)->get('/monitoring?month=2026-07&tab=analytics');
        $this->assertNotSame(500, $analytics->getStatusCode(), 'Monitoring analytics tidak boleh 500.');

        $jaringan = $this->actingAs($user)->get('/monitoring?tab=jaringan');
        $this->assertNotSame(500, $jaringan->getStatusCode(), 'Monitoring jaringan tidak boleh 500.');
    }
}
