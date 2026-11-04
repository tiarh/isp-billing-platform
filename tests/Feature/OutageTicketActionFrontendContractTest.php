<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Contract for the manual "Buat tiket" action of the Deteksi Gangguan tab. Picked up by the *FrontendContractTest glob.
 */
class OutageTicketActionFrontendContractTest extends TestCase
{
    private function read(string $path): string
    {
        $full = base_path($path);
        $this->assertFileExists($full);

        return (string) file_get_contents($full);
    }

    public function test_action_uses_the_two_named_routes_csrf_and_the_plain_indonesian_texts(): void
    {
        $src = $this->read('resources/js/Pages/Monitoring/OutageTicketAction.tsx');

        $this->assertStringContainsString("route('api.monitoring.outage-shadow.ticket-preview'", $src);
        $this->assertStringContainsString("route('api.monitoring.outage-shadow.ticket'", $src);
        $this->assertStringContainsString("method: 'POST'", $src);
        $this->assertStringContainsString("'X-CSRF-TOKEN'", $src);
        foreach ([
            'Buat tiket gangguan?',
            'Prioritas',
            'Catatan (opsional)',
            'Tiket dibuat tanpa teknisi. Pelanggan tidak dikirimi pesan.',
            'Grup Report menerima 1 pesan.',
            'Grup Report belum diatur, jadi tidak ada pesan WA.',
            'Batal',
            'Buat tiket',
            'Membuat…',
            'Anda tidak punya akses membuat tiket.',
            'Gagal membuat tiket. Coba lagi.',
            'Sudah ada tiket #',
            'Belum ada tiket',
            'Tiket #',
        ] as $text) {
            $this->assertStringContainsString($text, $src);
        }
        $this->assertStringContainsString('maxLength={500}', $src);
        $this->assertDoesNotMatchRegularExpression('/phone|nik|address/i', $src);
        $this->assertDoesNotMatchRegularExpression('/\b(?:bg|text|border)-(?:red|green|yellow|amber|blue|orange|rose|emerald)-\d{2,3}\b/', $src);
    }

    public function test_panel_only_renders_the_action_and_stays_free_of_post_calls(): void
    {
        $panel = $this->read('resources/js/Pages/Monitoring/OutageShadowPanel.tsx');

        $this->assertStringContainsString("from './OutageTicketAction'", $panel);
        $this->assertStringContainsString('<OutageTicketAction', $panel);
        $this->assertStringNotContainsString('Hanya dicatat — belum ada tiket', $panel);
        $this->assertDoesNotMatchRegularExpression("/method:\s*'(POST|PUT|PATCH|DELETE)'/i", $panel);
    }

    public function test_ticket_page_shows_the_detection_source(): void
    {
        $show = $this->read('resources/js/Pages/Tickets/Show.tsx');

        $this->assertStringContainsString('detectionSource', $show);
        $this->assertStringContainsString('Sumber: Deteksi Gangguan,', $show);
        $this->assertStringContainsString('kejadian #{detectionSource.episode_id}', $show);
    }
}
