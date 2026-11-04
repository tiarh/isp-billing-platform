<?php

namespace Tests\Feature;

use Tests\TestCase;

class RoutersShowEmojiFrontendContractTest extends TestCase
{
    public function test_router_show_status_and_help_copy_use_plain_text_without_emoji_glyphs(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/Routers/Show.tsx'));

        foreach ([
            "'Online'",
            "'Offline'",
            "'Belum dicek'",
            "'Terhubung'",
            "'Menunggu Handshake'",
            'Jika mengisi nama pool di "Pool IP"',
            "vpn.radius_tables_ready ? 'Siap' : 'Belum ada'",
            'Nilai rahasia tidak ditampilkan di browser.',
            "vpn.credentials_configured.client_key ? 'tersimpan' : 'belum diatur'",
            "vpn.credentials_configured.preshared_key ? 'tersimpan' : 'belum diatur'",
            "vpn.credentials_configured.radius_secret ? 'tersimpan' : 'belum diatur'",
        ] as $copy) {
            $this->assertStringContainsString($copy, $source);
        }

        $this->assertStringNotContainsString('mikrotik_script', $source);
        $this->assertStringNotContainsString('server_peer_config', $source);
        $this->assertSame(0, preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $source));
    }
}
