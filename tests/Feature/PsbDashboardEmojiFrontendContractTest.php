<?php

namespace Tests\Feature;

use Tests\TestCase;

class PsbDashboardEmojiFrontendContractTest extends TestCase
{
    public function test_psb_dashboard_uses_icon_components_without_emoji_glyphs(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/PsbDrafts/Dashboard.tsx'));

        $this->assertStringContainsString('Settings2', $source);
        $this->assertStringContainsString('Target', $source);
        $this->assertSame(0, preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $source));
    }
}
