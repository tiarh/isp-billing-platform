<?php

namespace Tests\Feature;

use Tests\TestCase;

class DataTableFrontendContractTest extends TestCase
{
    public function test_data_table_skips_the_initial_url_sync_request(): void
    {
        $source = file_get_contents(resource_path('js/Components/DataTable.tsx'));

        // Audit 2026-10-06 (P10/U4): the sync effect ran on mount, so every
        // DataTable page issued a second identical router.get after first paint.
        $this->assertStringContainsString('const skipInitialSync = useRef(true);', $source);
        $this->assertStringContainsString('if (skipInitialSync.current) {', $source);
        $this->assertStringContainsString("import { useState, useEffect, useRef, ReactNode } from 'react';", $source);
    }

    public function test_data_table_a3_density_nowrap_and_summary(): void
    {
        $source = file_get_contents(resource_path('js/Components/DataTable.tsx'));

        $this->assertStringContainsString('wrap?: boolean', $source);
        $this->assertStringContainsString('whitespace-nowrap', $source);
        $this->assertStringContainsString('h-[52px] py-2', $source);
        $this->assertStringContainsString('h-11 py-1.5', $source);
        $this->assertStringContainsString('summary?: ReactNode', $source);
        // A card button inside a mobile card must not also trigger onRowClick (double fetch).
        $this->assertStringContainsString("closest('a,button,input,select,textarea,label')", $source);
        $this->assertStringContainsString('<ul role="list" className="space-y-3 md:hidden">', $source);
        // Keyboard rows from PR-UX3 must survive.
        $this->assertStringContainsString('{...rowInteractionProps(item)}', $source);
    }

    public function test_data_table_toolbar_is_a_bare_row_with_pill_filters(): void
    {
        $toolbar = file_get_contents(resource_path('js/Components/DataTableToolbar.tsx'));
        $faceted = file_get_contents(resource_path('js/Components/DataTableFacetedFilter.tsx'));

        $this->assertStringNotContainsString('rounded-xl border border-border bg-card p-4', $toolbar);
        $this->assertStringContainsString('type="search"', $toolbar);
        $this->assertStringContainsString('summary', $toolbar);
        $this->assertStringNotContainsString('} selected', $faceted);
        $this->assertStringContainsString('dipilih', $faceted);
        $this->assertStringContainsString('Tidak ada hasil.', $faceted);
    }
}
