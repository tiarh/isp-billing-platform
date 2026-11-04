<?php

namespace Tests\Feature;

use Tests\TestCase;

class PublicLayoutFrontendContractTest extends TestCase
{
    public function test_public_layout_uses_a_solid_sticky_navigation_surface(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Layouts/PublicLayout.tsx'));

        $this->assertStringContainsString('bg-card sticky top-0', $source);
        $this->assertStringNotContainsString('backdrop-blur', $source);
    }
}
