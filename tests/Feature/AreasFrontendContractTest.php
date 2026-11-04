<?php

namespace Tests\Feature;

use Tests\TestCase;

class AreasFrontendContractTest extends TestCase
{
    public function test_area_index_exposes_customer_and_device_counts_with_shared_formatting(): void
    {
        $index = (string) file_get_contents(base_path('resources/js/Pages/Areas/Index.tsx'));
        $controller = (string) file_get_contents(base_path('app/Http/Controllers/AreaController.php'));

        $this->assertStringContainsString('olts_count', $index);
        $this->assertStringContainsString("route('areas.show', area.id)", $index);
        $this->assertStringContainsString("from '@/lib/format'", $index);
        $this->assertStringContainsString('formatNumber(', $index);
        $this->assertStringContainsString("withCount(['customers', 'olts'])", $controller);
        $this->assertStringNotContainsString('bg-gradient-', $index);
        $this->assertStringNotContainsString('backdrop-blur', $index);
        $this->assertStringNotContainsString('toLocaleString(', $index);
    }

    public function test_area_create_and_edit_expose_alias_field(): void
    {
        foreach (['Create', 'Edit'] as $page) {
            $source = (string) file_get_contents(base_path("resources/js/Pages/Areas/{$page}.tsx"));

            $this->assertStringContainsString('Alias / ejaan lain', $source);
            $this->assertStringContainsString("setData('aliases'", $source);
            $this->assertStringContainsString('errors.aliases', $source);
        }
        $show = (string) file_get_contents(base_path('resources/js/Pages/Areas/Show.tsx'));
        $this->assertStringContainsString('Alias / ejaan lain', $show);
    }

    public function test_shared_table_actions_expose_accessible_names_for_icon_buttons(): void
    {
        $actions = (string) file_get_contents(base_path('resources/js/Components/TableActions.tsx'));

        $this->assertStringContainsString('aria-label={title}', $actions);
    }

    public function test_area_migration_uses_in_app_confirmation_instead_of_browser_prompt(): void
    {
        $migration = (string) file_get_contents(base_path('resources/js/Pages/Areas/Migration.tsx'));

        $this->assertStringContainsString("import { ConfirmDestructive } from '@/Components/ConfirmDestructive';", $migration);
        $this->assertStringContainsString('setConfirmOpen(true)', $migration);
        $this->assertStringContainsString('<ConfirmDestructive', $migration);
        $this->assertStringNotContainsString('confirm(', $migration);
    }
}
