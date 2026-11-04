<?php

namespace Tests\Feature;

use Tests\TestCase;

class MonitoringPollingFrontendContractTest extends TestCase
{
    public function test_monitoring_auto_reload_pauses_in_hidden_tabs_and_uses_a_60s_base_interval(): void
    {
        $source = file_get_contents(resource_path('js/Pages/Monitoring/Index.tsx'));

        // Audit 2026-10-06 (P4): 15 props were reloaded every 30s even in
        // hidden tabs, each reload re-aggregating a month of probe rows.
        $this->assertStringContainsString("if (document.visibilityState === 'hidden') {", $source);
        $this->assertStringContainsString("document.addEventListener('visibilitychange', onVisibilityChange);", $source);
        $this->assertStringContainsString("document.removeEventListener('visibilitychange', onVisibilityChange);", $source);
        $this->assertStringContainsString('Math.min(300000, 60000 * Math.max(1, autoReloadFailures.current + 1))', $source);
        $this->assertStringNotContainsString('30000 * Math.max(1, autoReloadFailures.current + 1)', $source);
    }
}
