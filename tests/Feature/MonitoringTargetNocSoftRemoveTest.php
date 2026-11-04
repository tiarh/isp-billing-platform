<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Group;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * M-5 hid Target NOC; M-6 removes its code (the database tables stay until M-7).
 */
#[Group('blocking-core')]
class MonitoringTargetNocSoftRemoveTest extends TestCase
{
    use RefreshDatabase;

    public function test_global_admin_is_redirected_to_monitoring_jaringan(): void
    {
        $user = User::factory()->create(['role' => 'superadmin']);

        $this->actingAs($user)
            ->get('/monitoring-targets')
            ->assertRedirect(route('monitoring.index', ['tab' => 'jaringan']));
    }

    public function test_roles_without_access_still_get_403(): void
    {
        $user = User::factory()->create(['role' => 'teknisi']);

        $this->actingAs($user)->get('/monitoring-targets')->assertForbidden();
    }

    public function test_target_mutation_routes_and_old_bandwidth_upload_are_gone(): void
    {
        foreach (['store', 'update', 'probe', 'destroy'] as $action) {
            $this->assertNull(Route::getRoutes()->getByName('monitoring-targets.'.$action));
        }
        $this->assertNull(Route::getRoutes()->getByName('api.monitoring.bandwidth.upload'));

        $this->post('/api/monitoring/bandwidth/upload', ['rows' => []])->assertNotFound();
    }

    public function test_probe_command_job_and_service_are_removed(): void
    {
        $this->assertArrayNotHasKey('monitoring:probe-targets', Artisan::all());
        $this->assertFalse(class_exists('App\\Jobs\\ProbeMonitoringTargetsJob'));
        $this->assertFalse(class_exists('App\\Services\\MonitoringProbeService'));
        $this->assertFalse(class_exists('App\\Http\\Controllers\\MonitoringTargetController'));
    }

    public function test_sidebar_has_no_target_noc_entry(): void
    {
        $sidebar = (string) file_get_contents(resource_path('js/Components/AppSidebar.tsx'));

        $this->assertStringNotContainsString('Target NOC', $sidebar);
        $this->assertStringNotContainsString('monitoring-targets.index', $sidebar);
    }

    public function test_monitoring_page_has_no_target_strip_legacy_tabs_or_faq_entry(): void
    {
        $page = (string) file_get_contents(resource_path('js/Pages/Monitoring/Index.tsx'));
        $faq = (string) file_get_contents(resource_path('js/faq/categories/monitoring.ts'));

        $this->assertStringNotContainsString('targetStrip', $page);
        $this->assertStringNotContainsString('Target Monitoring', $page);
        $this->assertStringNotContainsString("id: 'target-noc'", $faq);
        $this->assertStringNotContainsString('monitoring-targets.index', $faq);
        $this->assertFileDoesNotExist(public_path('images/faq/target-noc.webp'));
        $this->assertFileDoesNotExist(resource_path('js/Pages/MonitoringTargets/Index.tsx'));
    }

    public function test_noc_sources_no_longer_include_host_monitoring_ping(): void
    {
        $source = (string) file_get_contents(app_path('Services/NocMonitoringReadModel.php'));

        $this->assertStringNotContainsString('host_monitoring_ping', $source);
        $this->assertStringNotContainsString('legacy_ping', $source);
    }

    public function test_runtime_code_no_longer_references_the_legacy_tables_or_probe_service(): void
    {
        $needles = ['monitoring_targets', 'monitoring_probe_results', 'router_uplink_mappings', 'MonitoringProbeService', 'probe-targets', 'bandwidth/upload'];
        $hits = [];

        foreach (['app', 'routes', 'config', 'database/seeders', 'resources/js'] as $dir) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($dir), RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (! $file->isFile() || ! in_array($file->getExtension(), ['php', 'ts', 'tsx'], true)) {
                    continue;
                }
                $contents = (string) file_get_contents($file->getPathname());
                foreach ($needles as $needle) {
                    if (str_contains($contents, $needle)) {
                        $hits[] = $file->getPathname().' => '.$needle;
                    }
                }
            }
        }

        $this->assertSame([], $hits, 'Legacy Target NOC references must not return outside migrations.');
    }
}
