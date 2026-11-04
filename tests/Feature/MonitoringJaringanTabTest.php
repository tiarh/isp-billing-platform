<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * M-4/M-6: the Jaringan tab is the default Monitoring tab; the old Overview tab
 * and the hidden legacy tabs (overview-legacy, infrastructure, analytics) are
 * redirected to it.
 */
#[Group('blocking-core')]
class MonitoringJaringanTabTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'superadmin']);
    }

    private function viewTab(?string $query): Assert
    {
        $response = $this->actingAs($this->admin())->get('/monitoring'.($query === null ? '' : '?'.$query));
        $response->assertOk();

        return Assert::fromTestResponse($response);
    }

    public function test_default_tab_is_jaringan(): void
    {
        $this->viewTab(null)->component('Monitoring/Index')->where('viewTab', 'jaringan');
    }

    public function test_bogus_tab_falls_back_to_jaringan(): void
    {
        $this->viewTab('tab=bogus')->where('viewTab', 'jaringan');
    }

    public function test_overview_redirects_to_jaringan_and_keeps_other_params(): void
    {
        $this->actingAs($this->admin())
            ->get('/monitoring?tab=overview&month=2026-07&source=olt')
            ->assertRedirect(route('monitoring.index', ['tab' => 'jaringan', 'month' => '2026-07', 'source' => 'olt']));
    }

    public function test_removed_legacy_tabs_redirect_to_jaringan_and_keep_other_params(): void
    {
        foreach (['overview-legacy', 'infrastructure', 'analytics'] as $tab) {
            $this->actingAs($this->admin())
                ->get('/monitoring?tab='.$tab.'&month=2026-07')
                ->assertRedirect(route('monitoring.index', ['tab' => 'jaringan', 'month' => '2026-07']));
        }
    }

    public function test_other_tabs_are_honoured(): void
    {
        foreach (['gangguan', 'deteksi', 'alerts', 'noc'] as $tab) {
            $this->viewTab('tab='.$tab)->where('viewTab', $tab);
        }
    }

    public function test_traffic_tab_needs_the_traffic_feature(): void
    {
        config(['services.monitoring.traffic.enabled' => false]);
        $this->viewTab('tab=traffic')->where('viewTab', 'jaringan');

        config(['services.monitoring.traffic.enabled' => true]);
        $this->viewTab('tab=traffic')->where('viewTab', 'traffic');
    }
}
