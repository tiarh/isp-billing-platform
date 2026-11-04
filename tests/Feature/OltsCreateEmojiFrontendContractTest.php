<?php

namespace Tests\Feature;

use Tests\TestCase;

class OltsCreateEmojiFrontendContractTest extends TestCase
{
    public function test_olt_create_uses_icon_components_without_emoji_glyphs(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/Olts/Create.tsx'));

        $this->assertStringContainsString('Cable', $source);
        $this->assertStringContainsString('KeyRound', $source);
        $this->assertStringContainsString('Monitor', $source);
        $this->assertStringContainsString('LockKeyhole', $source);
        $this->assertStringContainsString('RadioTower', $source);
        $this->assertSame(0, preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $source));
    }
}
