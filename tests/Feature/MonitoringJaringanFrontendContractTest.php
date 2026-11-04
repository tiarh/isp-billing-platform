<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * M-4 contract for the Jaringan tab sources. Picked up by the
 * *FrontendContractTest glob, so it intentionally has no #[Group].
 */
class MonitoringJaringanFrontendContractTest extends TestCase
{
    private const DIR = 'resources/js/Components/monitoring/network/';

    private const FILES = [
        'types.ts',
        'useMonitoringWidget.ts',
        'useAutoRefresh.ts',
        'WidgetFrame.tsx',
        'format.ts',
        'RangeSelector.tsx',
        'NetworkKpiStrip.tsx',
        'UpstreamPanel.tsx',
        'RouterTable.tsx',
        'Sparkline.tsx',
        'ResourcesGrid.tsx',
        'PingPanel.tsx',
        'JaringanTab.tsx',
    ];

    private function source(string $file): string
    {
        $path = base_path(self::DIR.$file);
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private function index(): string
    {
        return (string) file_get_contents(base_path('resources/js/Pages/Monitoring/Index.tsx'));
    }

    public function test_components_use_semantic_tokens_and_shared_formatting(): void
    {
        foreach (self::FILES as $file) {
            $source = $this->source($file);

            $this->assertStringNotContainsString('toLocaleString(', $source, $file);
            $this->assertStringNotContainsString('window.prompt(', $source, $file);
            $this->assertStringNotContainsString('violet-', $source, $file);
            $this->assertStringNotContainsString('bg-gradient-', $source, $file);
            $this->assertStringNotContainsString('backdrop-blur', $source, $file);
            $this->assertDoesNotMatchRegularExpression('/\b(?:bg|text|border|stroke|fill)-(?:red|green|blue|amber|slate|gray|emerald)-\d{2,3}\b/', $source, $file);
            // No raw hex colours: charts and badges take their colours from tokens.
            $this->assertDoesNotMatchRegularExpression('/[\'"]#[0-9a-fA-F]{3,8}[\'"]/', $source, $file);
        }
        $this->assertStringContainsString("from '@/lib/format'", $this->source('format.ts'));
    }

    public function test_widgets_are_fetched_from_the_named_route_with_a_whitelisted_range(): void
    {
        $hook = $this->source('useMonitoringWidget.ts');

        $this->assertStringContainsString("route('api.monitoring.widgets.show', widget)", $hook);
        $this->assertStringContainsString('AbortController', $hook);
        $this->assertStringContainsString('MAX_CONCURRENT = 3', $hook);
        $this->assertStringContainsString("'summary', 'upstream', 'routers', 'ping', 'resources', 'router-traffic'", $hook);
        $this->assertStringContainsString('useDebouncedValue', $hook);
        $this->assertStringContainsString("response.status === 429 ? 'throttled'", $hook);
        // PromQL never comes from the browser.
        $this->assertStringNotContainsString('promql', strtolower($hook));

        $tab = $this->source('JaringanTab.tsx');
        $this->assertStringContainsString('useDebouncedValue(range, 300)', $tab);
        $this->assertStringContainsString('useAutoRefresh(60_000)', $tab);
    }

    public function test_auto_refresh_pauses_in_hidden_tabs(): void
    {
        $hook = $this->source('useAutoRefresh.ts');

        $this->assertStringContainsString("document.visibilityState !== 'hidden'", $hook);
        $this->assertStringContainsString("document.addEventListener('visibilitychange', onVisibilityChange);", $hook);
        $this->assertStringContainsString("document.removeEventListener('visibilitychange', onVisibilityChange);", $hook);
        // Tab flipping must not refetch more often than every 30s (60/min throttle).
        $this->assertStringContainsString('const minGapMs = Math.min(30_000, intervalMs);', $hook);
        $this->assertStringContainsString('lastTickAt', $hook);
        $this->assertStringContainsString('if (sinceLast >= minGapMs) {', $hook);
        $this->assertStringContainsString('schedule(Math.max(minGapMs - sinceLast, 0));', $hook);
    }

    public function test_range_switch_drops_old_data_and_aborted_requests_never_set_state(): void
    {
        $hook = $this->source('useMonitoringWidget.ts');

        $this->assertStringContainsString('current.envelope.range !== range ? emptyState<T>()', $hook);
        $this->assertSame(2, substr_count($hook, 'if (controller.signal.aborted) {'));
    }

    public function test_range_selector_is_an_accessible_radiogroup_stored_locally(): void
    {
        $source = $this->source('RangeSelector.tsx');

        $this->assertStringContainsString('role="radiogroup"', $source);
        $this->assertStringContainsString('role="radio"', $source);
        $this->assertStringContainsString('aria-checked', $source);
        $this->assertStringContainsString("'monitoring:range'", $source);
        $this->assertStringContainsString("DEFAULT_RANGE: MonitoringRange = '1h'", $source);
        $this->assertMatchesRegularExpression('/try\s*\{[^}]*localStorage/s', $source);

        $types = $this->source('types.ts');
        foreach (["'1h'", "'6h'", "'24h'", "'7d'", "'30d'"] as $range) {
            $this->assertStringContainsString($range, $types);
        }
    }

    public function test_thresholds_are_the_agreed_values(): void
    {
        $source = $this->source('format.ts');

        $this->assertStringContainsString('cpu: [70, 90]', $source);
        $this->assertStringContainsString('memory: [80, 90]', $source);
        $this->assertStringContainsString('disk: [80, 90]', $source);
        $this->assertStringContainsString('temperature: [55, 65]', $source);
    }

    public function test_router_table_is_sortable_responsive_and_has_a_name_slot(): void
    {
        $source = $this->source('RouterTable.tsx');

        $this->assertStringContainsString('aria-sort', $source);
        $this->assertStringContainsString('renderRouterName', $source);
        $this->assertStringContainsString('ICMP diblokir', $source);
        $this->assertStringContainsString('md:hidden', $source);
        $this->assertStringContainsString('hidden md:block', $source);
        $this->assertStringContainsString('<Sparkline', $source);
        $this->assertStringNotContainsString('terakhir data', strtolower($source));
    }

    public function test_tab_leaves_room_for_a_cpe_card_and_shows_one_setup_banner(): void
    {
        $source = $this->source('JaringanTab.tsx');

        $this->assertStringContainsString('{children}', $source);
        $this->assertStringContainsString('renderRouterName', $source);
        $this->assertStringContainsString('suppressSetup={setupRequired}', $source);
        $this->assertSame(1, substr_count($source, 'Koneksi Grafana belum dikonfigurasi'));
    }

    public function test_index_makes_jaringan_the_first_tab_and_hides_the_legacy_ones(): void
    {
        $source = $this->index();

        $this->assertStringContainsString("viewTab = 'jaringan'", $source);
        $this->assertMatchesRegularExpression("/tabs=\{\[\s*\{ id: 'jaringan', label: 'Jaringan' \},\s*\{ id: 'gangguan'/", $source);
        $this->assertStringNotContainsString("label: 'Overview'", $source);
        $this->assertStringNotContainsString("id: 'overview'", $source);
        $this->assertStringContainsString('<JaringanTab', $source);
        foreach (['overview-legacy', 'analytics', 'infrastructure'] as $removed) {
            $this->assertStringNotContainsString("'{$removed}'", $source);
        }
    }

    public function test_index_header_actions(): void
    {
        $source = $this->index();

        $this->assertStringContainsString('Sumber: Grafana · diperbarui', $source);
        $this->assertStringContainsString('<RangeSelector', $source);
        $this->assertStringContainsString('Buka di Grafana', $source);
        $this->assertStringContainsString('rel="noopener noreferrer"', $source);
        $this->assertStringContainsString('jaringanMeta.grafanaUrl &&', $source);
        $this->assertStringContainsString("route('monitoring.settings.index')", $source);
        $this->assertStringContainsString('isGlobalAdmin &&', $source);
    }

    public function test_legacy_inertia_reload_is_skipped_on_jaringan(): void
    {
        $source = $this->index();

        $this->assertStringContainsString("activeTabRef.current === 'jaringan'", $source);
        // The 60s legacy polling contract itself stays untouched.
        $this->assertStringContainsString('Math.min(300000, 60000 * Math.max(1, autoReloadFailures.current + 1))', $source);
    }

    public function test_controller_defaults_and_redirects(): void
    {
        $controller = (string) file_get_contents(base_path('app/Http/Controllers/MonitoringController.php'));

        $this->assertStringContainsString("['overview', 'overview-legacy', 'infrastructure', 'analytics']", $controller);
        $this->assertStringContainsString("['tab' => 'jaringan']", $controller);
        $this->assertStringContainsString(": 'jaringan',", $controller);
    }
}
