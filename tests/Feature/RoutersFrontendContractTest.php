<?php

namespace Tests\Feature;

use Tests\TestCase;

class RoutersFrontendContractTest extends TestCase
{
    public function test_router_vpn_errors_use_sonner_instead_of_browser_alerts(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/Routers/Show.tsx'));

        $this->assertStringContainsString("import { toast } from 'sonner';", $source);
        $this->assertStringContainsString("toast.error('Failed to find next IP: '", $source);
        $this->assertStringContainsString("toast.error('Failed to load server config: '", $source);
        $this->assertStringNotContainsString('alert(', $source);
    }
}
