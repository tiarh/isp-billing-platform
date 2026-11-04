<?php

namespace Tests\Feature;

use Tests\TestCase;

class SettingsEmojiFrontendContractTest extends TestCase
{
    public function test_settings_wa_labels_use_plain_copy_without_emoji_glyphs(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/Settings/Index.tsx'));

        $this->assertStringContainsString("useState('Test message dari eBilling WA Gateway')", $source);
        $this->assertStringContainsString('Ubah di Pengaturan › Grup WhatsApp', $source);
        $this->assertSame(0, preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $source));
    }
}
