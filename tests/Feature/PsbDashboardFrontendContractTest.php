<?php

namespace Tests\Feature;

use Tests\TestCase;

class PsbDashboardFrontendContractTest extends TestCase
{
    public function test_psb_dashboard_chart_formatters_are_typed_and_visual_patterns_are_bounded(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/PsbDrafts/Dashboard.tsx'));

        $this->assertSame(3, substr_count($source, 'formatter={(v: unknown)'));
        $this->assertStringNotContainsString('violet-', $source);
        $this->assertStringNotContainsString('bg-gradient-', $source);
        $this->assertStringNotContainsString('backdrop-blur', $source);
        $this->assertStringNotContainsString('toLocaleString(', $source);
    }

    public function test_psb_dashboard_uses_shared_status_badge_for_read_only_flow(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/PsbDrafts/Dashboard.tsx'));

        $this->assertStringContainsString('import { StatusBadge, type StatusTone }', $source);
        $this->assertStringContainsString('Alur draft PSB', $source);
        $this->assertStringContainsString('<StatusBadge label={label}', $source);
        $this->assertStringNotContainsString('statusClass(', $source);
    }

    public function test_kpi_import_uses_destructive_confirmation_instead_of_browser_prompt(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/PsbDrafts/Dashboard.tsx'));

        $this->assertStringContainsString("from '@/Components/ConfirmDestructive'", $source);
        $this->assertStringContainsString('<ConfirmDestructive', $source);
        $this->assertStringContainsString("route('psb-drafts.import-starlite')", $source);
        $this->assertStringNotContainsString('confirm(', $source);
    }
}
