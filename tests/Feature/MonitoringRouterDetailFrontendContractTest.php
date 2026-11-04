<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * M-4c-2b contract for the router detail page. Picked up by the
 * *FrontendContractTest glob, so it intentionally has no #[Group].
 */
class MonitoringRouterDetailFrontendContractTest extends TestCase
{
    private const DIR = 'resources/js/Components/monitoring/router/';

    private const FILES = [
        'DailyBars.tsx',
        'DailyUsageChart.tsx',
        'PppoeTable.tsx',
        'PppoeUserDrawer.tsx',
        'RouterDetailHeader.tsx',
        'RouterKpiStrip.tsx',
        'TopUsersNow.tsx',
        'UplinkChart.tsx',
        'format.ts',
        'types.ts',
        'useRouterApi.ts',
    ];

    private function read(string $relative): string
    {
        $path = base_path($relative);
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function test_components_use_tokens_and_no_locale_formatting(): void
    {
        foreach (self::FILES as $file) {
            $source = $this->read(self::DIR.$file);

            $this->assertStringNotContainsString('toLocaleString(', $source, $file);
            $this->assertStringNotContainsString('bg-gradient-', $source, $file);
            $this->assertStringNotContainsString('violet-', $source, $file);
            $this->assertDoesNotMatchRegularExpression('/\b(?:bg|text|border|stroke|fill|ring)-(?:red|green|blue|amber|slate|gray|emerald|yellow|orange|purple|indigo|sky|teal|cyan|rose|pink)-\d{2,3}\b/', $source, $file);
            $this->assertDoesNotMatchRegularExpression('/[\'"]#[0-9a-fA-F]{3,8}[\'"]/', $source, $file);
            // No white text on surfaces that change with the theme.
            $this->assertDoesNotMatchRegularExpression('/\btext-white\b/', $source, $file);
        }
    }

    public function test_page_reuses_the_shared_monitoring_building_blocks(): void
    {
        $page = $this->read('resources/js/Pages/Monitoring/RouterDetail.tsx');
        $this->assertStringContainsString('max-w-6xl', $page);
        $this->assertStringContainsString('useStoredRange', $page);
        $this->assertStringContainsString('useAutoRefresh', $page);
        $this->assertStringContainsString("route('api.monitoring.routers.overview'", $page);

        $header = $this->read(self::DIR.'RouterDetailHeader.tsx');
        $this->assertStringContainsString('<RangeSelector', $header);
        $this->assertStringContainsString('rel="noopener noreferrer"', $header);

        foreach (['RouterKpiStrip.tsx', 'UplinkChart.tsx', 'TopUsersNow.tsx', 'DailyUsageChart.tsx', 'PppoeTable.tsx', 'PppoeUserDrawer.tsx'] as $file) {
            $this->assertStringContainsString('<WidgetFrame', $this->read(self::DIR.$file), $file);
        }

        $table = $this->read(self::DIR.'PppoeTable.tsx');
        $this->assertStringContainsString('aria-sort', $table);
        $this->assertStringContainsString('<MobileCard', $table);
        $this->assertStringContainsString('<Sparkline', $table);
        $this->assertStringContainsString("route('api.monitoring.routers.pppoe'", $table);
        $this->assertStringContainsString("'unknown'", $table);

        $this->assertStringContainsString("route('api.monitoring.routers.pppoe-daily'", $this->read(self::DIR.'PppoeUserDrawer.tsx'));
    }

    public function test_hook_keeps_data_on_failure_and_limits_concurrency(): void
    {
        $hook = $this->read(self::DIR.'useRouterApi.ts');

        $this->assertStringContainsString('AbortController', $hook);
        $this->assertStringContainsString("response.status === 429 ? 'throttled'", $hook);
        $this->assertStringContainsString('MAX_CONCURRENT', $hook);
    }

    public function test_gb_is_decimal_and_invalid_days_are_gaps(): void
    {
        $this->assertStringContainsString('1 GB = 1 miliar byte', $this->read(self::DIR.'DailyUsageChart.tsx'));
        $this->assertStringContainsString('tidak valid', $this->read(self::DIR.'DailyUsageChart.tsx'));
        $this->assertStringContainsString('Invalid and missing days stay empty', $this->read(self::DIR.'DailyBars.tsx'));
    }

    public function test_monitoring_index_links_router_names_to_the_detail_page(): void
    {
        $index = $this->read('resources/js/Pages/Monitoring/Index.tsx');

        $this->assertStringContainsString('renderRouterName=', $index);
        $this->assertStringContainsString("route('monitoring.routers.show', row.router_id)", $index);
    }
}
