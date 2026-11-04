<?php

namespace Tests\Feature;

use Tests\TestCase;

class RouterVlanPanelFrontendContractTest extends TestCase
{
    public function test_vlan_delete_uses_shared_destructive_confirmation_instead_of_browser_prompt(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Components/Aktivasi/RouterVlanPanel.tsx'));

        $this->assertStringContainsString("from '@/Components/ConfirmDestructive'", $source);
        $this->assertStringContainsString('<ConfirmDestructive', $source);
        $this->assertStringContainsString('/api/router-vlans/${row.id}', $source);
        $this->assertStringNotContainsString('window.confirm(', $source);
    }

    public function test_vlan_table_loading_uses_layout_skeleton_instead_of_full_area_spinner(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Components/Aktivasi/RouterVlanPanel.tsx'));

        $this->assertStringContainsString("from '@/Components/ui/skeleton'", $source);
        $this->assertStringContainsString('<Skeleton className="h-10 w-full" />', $source);
        $this->assertStringNotContainsString('Memuat…', $source);
    }
}
