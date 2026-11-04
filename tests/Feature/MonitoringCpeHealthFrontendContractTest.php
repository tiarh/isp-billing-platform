<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * M-4d contract for the CPE health card. Picked up by the *FrontendContractTest
 * glob, so it intentionally has no #[Group].
 */
class MonitoringCpeHealthFrontendContractTest extends TestCase
{
    private const DIR = 'resources/js/Components/monitoring/network/';

    private function source(string $file): string
    {
        $path = base_path(self::DIR.$file);
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function test_card_uses_tokens_and_the_shared_status_badge(): void
    {
        foreach (['CpeHealthCard.tsx', 'useCpeHealth.ts'] as $file) {
            $source = $this->source($file);

            $this->assertStringNotContainsString('toLocaleString(', $source, $file);
            $this->assertStringNotContainsString('bg-gradient-', $source, $file);
            $this->assertDoesNotMatchRegularExpression('/\b(?:bg|text|border|stroke|fill)-(?:red|green|blue|amber|slate|gray|emerald|yellow|orange)-\d{2,3}\b/', $source, $file);
            $this->assertDoesNotMatchRegularExpression('/[\'"]#[0-9a-fA-F]{3,8}[\'"]/', $source, $file);
        }

        $card = $this->source('CpeHealthCard.tsx');
        $this->assertStringContainsString('<StatusBadge', $card);
        $this->assertStringContainsString('<WidgetFrame', $card);
        $this->assertStringContainsString('bg-status-up', $card);
        $this->assertStringContainsString('bg-status-down', $card);
        // Aggregates only: nothing per-customer is rendered, and firmware is out of scope.
        $this->assertStringNotContainsString('pppoe', strtolower($card));
        $this->assertStringNotContainsString('firmware', strtolower($card));
    }

    public function test_forbidden_copy_is_generic_for_the_cpe_card_only(): void
    {
        $this->assertStringContainsString('forbiddenText="Akses ditolak untuk akun ini."', $this->source('CpeHealthCard.tsx'));

        $frame = $this->source('WidgetFrame.tsx');
        $this->assertStringContainsString('forbiddenText', $frame);
        // The Grafana widgets keep their copy.
        $this->assertStringContainsString("forbidden: 'Akses ke data Grafana ditolak untuk akun ini.'", $frame);
    }

    public function test_hook_fetches_the_named_cpe_route_and_keeps_data_on_failure(): void
    {
        $hook = $this->source('useCpeHealth.ts');

        $this->assertStringContainsString("route('api.monitoring.widgets.cpe')", $hook);
        $this->assertStringContainsString('AbortController', $hook);
        $this->assertStringContainsString("response.status === 429 ? 'throttled'", $hook);
        $this->assertStringContainsString('if (controller.signal.aborted) {', $hook);
        $this->assertStringContainsString('}, [tick]);', $hook);
    }

    public function test_jaringan_tab_renders_the_card_below_resources_on_the_shared_tick(): void
    {
        $tab = $this->source('JaringanTab.tsx');

        $this->assertStringContainsString('useCpeHealth(tick)', $tab);
        $this->assertStringContainsString('<CpeHealthCard state={cpe} />', $tab);
        $this->assertLessThan(strpos($tab, '<CpeHealthCard'), strpos($tab, '<ResourcesGrid'));
        $this->assertLessThan(strpos($tab, '{children}'), strpos($tab, '<CpeHealthCard'));
    }

    public function test_route_is_registered_before_the_generic_widget_route(): void
    {
        $routes = (string) file_get_contents(base_path('routes/web.php'));
        $cpe = strpos($routes, "'/api/monitoring/widgets/cpe'");
        $generic = strpos($routes, "'/api/monitoring/widgets/{widget}'");

        $this->assertNotFalse($cpe);
        $this->assertNotFalse($generic);
        $this->assertLessThan($generic, $cpe);
    }
}
