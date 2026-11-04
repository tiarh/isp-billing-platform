<?php

namespace Tests\Feature;

use Tests\TestCase;

class InventarisFrontendContractTest extends TestCase
{
    public function test_inventaris_page_uses_shared_kpi_and_table_primitives(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/Inventaris/Index.tsx'));

        $this->assertStringContainsString("from '@/Components/StatCard'", $source);
        $this->assertStringContainsString('<StatCard', $source);
        $this->assertStringContainsString("from '@/lib/format'", $source);
        $this->assertStringContainsString('formatNumber(', $source);
        $this->assertStringContainsString('<DataTable', $source);
        $this->assertStringContainsString('<StatusBadge', $source);
        $this->assertStringContainsString('<SharedEmptyState', $source);
        $this->assertStringContainsString("route('inventaris.import-preview')", $source);
        $this->assertStringContainsString("route('inventaris.export')", $source);
        $this->assertStringNotContainsString('bg-gradient-', $source);
        $this->assertStringNotContainsString('backdrop-blur', $source);
        $this->assertStringNotContainsString('toLocaleString(', $source);
        $this->assertStringContainsString('<Button type="button"', $source);
        $this->assertStringNotContainsString('<Button variant="outline" size="sm" className="h-9 gap-2" onClick={handleExport}', $source);
    }
}
