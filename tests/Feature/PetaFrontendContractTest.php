<?php

namespace Tests\Feature;

use Tests\TestCase;

class PetaFrontendContractTest extends TestCase
{
    public function test_map_controls_keep_accessible_labels_and_avoid_blur_slop(): void
    {
        $index = (string) file_get_contents(base_path('resources/js/Pages/Peta/Index.tsx'));
        $sidebar = (string) file_get_contents(base_path('resources/js/Pages/Peta/PetaSidebar.tsx'));

        $this->assertStringContainsString('aria-label="Tutup panel peta"', $index);
        $this->assertStringContainsString('aria-label="Buka panel peta"', $index);
        $this->assertStringContainsString('aria-label="Tutup panel peta"', $sidebar);
        $this->assertStringNotContainsString('backdrop-blur', $index);
        $this->assertStringNotContainsString('backdrop-blur', $sidebar);
        $this->assertStringNotContainsString('violet-', $index);
        $this->assertStringNotContainsString('bg-gradient-', $index);
    }

    public function test_peta_view_uses_shared_status_formatting_and_accessible_controls(): void
    {
        $index = (string) file_get_contents(base_path('resources/js/Pages/Peta/Index.tsx'));
        $sidebar = (string) file_get_contents(base_path('resources/js/Pages/Peta/PetaSidebar.tsx'));

        $this->assertStringContainsString("from '@/Components/StatusBadge'", $sidebar);
        $this->assertStringContainsString("from '@/lib/format'", $sidebar);
        $this->assertStringContainsString('<StatusBadge', $sidebar);
        $this->assertStringContainsString('formatNumber(', $sidebar);
        $this->assertStringContainsString('aria-pressed={on}', $sidebar);
        $this->assertStringContainsString('aria-label="Cari ODP, pelanggan..."', $sidebar);
        $this->assertStringContainsString('aria-label="Filter RW"', $sidebar);
        $this->assertStringContainsString('type="button"', $sidebar);
        $this->assertStringContainsString('role="status"', $index);
        $this->assertStringContainsString('aria-pressed={sat}', $index);
        $this->assertStringNotContainsString('animate-spin', $index);
        $this->assertStringNotContainsString('animate-spin', $sidebar);
        $this->assertStringNotContainsString('toLocaleString(', $index);
        $this->assertStringNotContainsString('toLocaleString(', $sidebar);
    }
}
