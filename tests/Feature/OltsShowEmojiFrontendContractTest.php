<?php

namespace Tests\Feature;

use Tests\TestCase;

class OltsShowEmojiFrontendContractTest extends TestCase
{
    public function test_olt_show_uses_icon_components_without_emoji_glyphs(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/Olts/Show.tsx'));

        $this->assertStringContainsString('<AlertTriangle', $source);
        $this->assertStringContainsString('<RadioTower', $source);
        $this->assertStringContainsString('title="Detail OLT"', $source);
        $this->assertStringContainsString('title="Catatan"', $source);
        $this->assertStringContainsString('title="Data GPON Snapshot"', $source);
        $this->assertSame(0, preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $source));
    }
}
