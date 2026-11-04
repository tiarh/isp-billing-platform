<?php

namespace Tests\Feature;

use Tests\TestCase;

class MonitoringTrafficFrontendContractTest extends TestCase
{
    public function test_customer_traffic_panel_uses_shared_formatting_and_semantic_tokens(): void
    {
        $panel = (string) file_get_contents(base_path('resources/js/Pages/Monitoring/CustomerTrafficPanel.tsx'));

        $this->assertStringContainsString("from '@/lib/format'", $panel);
        $this->assertStringContainsString('formatDateTime(', $panel);
        $this->assertStringContainsString('formatNumber(', $panel);
        $this->assertStringNotContainsString('toLocaleString(', $panel);
        $this->assertStringNotContainsString('violet-', $panel);
    }
}
