<?php

namespace Tests\Feature;

use Tests\TestCase;

class ProductionControlPlaneFrontendContractTest extends TestCase
{
    public function test_control_plane_page_uses_enterprise_layout_contract(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/ProductionControlPlane/Index.tsx'));

        $this->assertStringContainsString("import { ShieldCheck } from 'lucide-react';", $source);
        $this->assertStringContainsString('subtitle="Source of truth', $source);
        $this->assertStringContainsString('icon={ShieldCheck}', $source);
        $this->assertStringNotContainsString('description="Source of truth', $source);
        $this->assertStringNotContainsString('violet-', $source);
        $this->assertStringNotContainsString('bg-gradient-', $source);
        $this->assertStringNotContainsString('backdrop-blur', $source);
        $this->assertStringNotContainsString('<button', $source);
        $this->assertStringNotContainsString('<form', $source);
        $this->assertStringNotContainsString('router.post', $source);
        $this->assertStringNotContainsString('router.put', $source);
        $this->assertStringNotContainsString('router.delete', $source);
        $this->assertStringNotContainsString('fetch(', $source);
    }
}
