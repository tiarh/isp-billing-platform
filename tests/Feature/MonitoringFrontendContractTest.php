<?php

namespace Tests\Feature;

use Tests\TestCase;

class MonitoringFrontendContractTest extends TestCase
{
    public function test_monitoring_view_uses_shared_formatting_and_no_browser_prompt(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/Monitoring/Index.tsx'));

        $this->assertStringNotContainsString('window.prompt(', $source);
        $this->assertStringNotContainsString('toLocaleString(', $source);
        $this->assertStringNotContainsString('tone="violet"', $source);
        $this->assertStringNotContainsString('tone="cyan"', $source);
        $this->assertStringNotContainsString('tone="sky"', $source);
        $this->assertStringNotContainsString('tone="indigo"', $source);
        $this->assertStringContainsString("from '@/lib/format'", $source);
        $this->assertStringContainsString('<Dialog', $source);
        $this->assertStringNotContainsString('only: reloadProps,\n                preserveScroll:', $source);
        $this->assertStringNotContainsString('only: reloadProps,\n                preserveState:', $source);
    }

    public function test_monitoring_page_uses_shared_status_and_panel_loading_primitives(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/Monitoring/Index.tsx'));

        $this->assertStringContainsString("from '@/Components/StatusBadge'", $source);
        $this->assertStringContainsString("from '@/Components/SkeletonCard'", $source);
        $this->assertStringContainsString('formatRelative', $source);
        $this->assertStringNotContainsString('function statusClass', $source);
        $this->assertStringNotContainsString('function targetStateClass', $source);
        $this->assertStringNotContainsString('function PanelLoading', $source);
    }
}
