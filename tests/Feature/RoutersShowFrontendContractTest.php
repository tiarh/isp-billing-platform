<?php

namespace Tests\Feature;

use Tests\TestCase;

class RoutersShowFrontendContractTest extends TestCase
{
    public function test_router_show_uses_toast_feedback_for_vpn_lookup_failures(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/Routers/Show.tsx'));

        $this->assertStringContainsString("import { toast } from 'sonner';", $source);
        $this->assertStringContainsString("toast.error('Failed to find next IP: ' + (e.message || 'Unknown error'));", $source);
        $this->assertStringContainsString("toast.error('Failed to load server config: ' + (e.message || 'Unknown error'));", $source);
        $this->assertStringNotContainsString('alert(', $source);
    }
}
