<?php

namespace Tests\Feature;

use Tests\TestCase;

class FinancePerAreaFrontendContractTest extends TestCase
{
    public function test_finance_page_has_three_report_tabs_and_server_backed_filters(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/Finance/PerArea.tsx'));

        foreach (['Ringkasan', 'Detail Area', 'Operasional', 'period', 'as_of', 'area', 'search', 'status', 'Periode invoice', 'Tanggal acuan laporan', 'finance-as-of-help', 'ResponsiveContainer', 'LabelList', 'compactIdr', 'Tagihan dan outstanding per area'] as $required) {
            $this->assertStringContainsString($required, $source);
        }

        $this->assertStringContainsString('/api/finance/per-area/summary', $source);
        $this->assertStringContainsString('/api/finance/per-area/detail', $source);
        $this->assertStringContainsString('/api/finance/per-area/operations', $source);
        $this->assertStringContainsString('/api/finance/per-area/export', $source);
        $this->assertStringContainsString('/api/finance/per-area/bulk-wa/preview', $source);
        $this->assertStringContainsString('/api/finance/per-area/bulk-wa/execute', $source);
        $this->assertStringContainsString('SkeletonTable', $source);
        $this->assertStringContainsString('Coba lagi', $source);
        $this->assertStringContainsString('window.history.replaceState', $source);
    }

    public function test_finance_operational_tab_has_typed_filters_metrics_and_drilldowns(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/Finance/PerArea.tsx'));

        foreach (['Target Migrasi', 'Sub area', 'sub_area', 'migration_url', 'psb_url', 'Belum ada summary operasional untuk filter ini.'] as $required) {
            $this->assertStringContainsString($required, $source);
        }
    }

    public function test_finance_page_avoids_known_ai_slop_and_unsafe_feedback_patterns(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/Finance/PerArea.tsx'));

        foreach (['animate-spin', 'animate-pulse', 'backdrop-blur', 'bg-gradient-', 'violet-', 'toLocaleString(', 'alert('] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source);
        }

        $this->assertStringContainsString('type="submit"', $source);
        $this->assertStringContainsString('type="button"', $source);
        $this->assertStringContainsString('role="alert"', $source);
    }
}
