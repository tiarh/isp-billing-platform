<?php

namespace Tests\Feature;

use Tests\TestCase;

class SettingsFrontendContractTest extends TestCase
{
    public function test_settings_tabs_expose_group_metadata_without_changing_tab_ids(): void
    {
        $settings = (string) file_get_contents(base_path('resources/js/Pages/Settings/Index.tsx'));
        $shell = (string) file_get_contents(base_path('resources/js/Components/enterprise/EnterprisePageShell.tsx'));

        $this->assertStringContainsString("group: 'Umum'", $settings);
        $this->assertStringContainsString("group: 'WA'", $settings);
        $this->assertStringContainsString("group: 'Billing'", $settings);
        $this->assertStringContainsString("id: 'company'", $settings);
        $this->assertStringContainsString("id: 'isolation'", $settings);
        $this->assertStringContainsString("id: 'wagateway'", $settings);
        $this->assertStringContainsString("id: 'billingwa'", $settings);
        $this->assertStringContainsString("id: 'enforcement'", $settings);
        $this->assertStringContainsString("id: 'midtrans'", $settings);
        $this->assertStringContainsString('tab.group', $shell);
    }

    public function test_ungrouped_pages_keep_the_existing_flat_tab_navigation(): void
    {
        $shell = (string) file_get_contents(base_path('resources/js/Components/enterprise/EnterprisePageShell.tsx'));

        $this->assertStringContainsString('tabs.some((tab) => tab.group)', $shell);
        $this->assertStringContainsString('tabs.map(renderTab)', $shell);
        $this->assertStringContainsString('onTabChange?.(tab.id)', $shell);
        $this->assertStringContainsString('flex flex-wrap gap-2 border-b pb-1', $shell);
    }

    public function test_settings_expose_a_dirty_indicator_and_field_guidance(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/Settings/Index.tsx'));

        $this->assertStringContainsString('dirtySections', $source);
        $this->assertStringContainsString('Belum tersimpan', $source);
        $this->assertGreaterThanOrEqual(5, substr_count($source, 'onChangeCapture'));
        foreach (['company', 'isolation', 'wagateway', 'billingwa', 'enforcement', 'midtrans'] as $section) {
            $this->assertStringContainsString("clearSectionDirty('{$section}')", $source);
        }
        $this->assertStringContainsString('text-xs text-muted-foreground', $source);
    }

    public function test_isolation_settings_have_area_selector_and_safe_save_endpoint(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/Settings/Index.tsx'));

        $this->assertStringContainsString("fetch('/api/settings/isolation-portal'", $source);
        $this->assertStringContainsString('toggleAllIsolationAreas', $source);
        $this->assertStringContainsString('Pilih minimal satu area', $source);
        $this->assertStringContainsString('isolationPortal.all_areas', $source);
    }
}
