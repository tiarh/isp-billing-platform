<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-core')]
class TicketsIndexFrontendContractTest extends TestCase
{
    public function test_tickets_index_uses_candidates_drawer_and_drops_wa_work_signals(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/Tickets/Index.tsx'));
        $controller = (string) file_get_contents(base_path('app/Http/Controllers/TicketController.php'));

        $this->assertStringContainsString('MonitoringCandidatesDrawer', $source);
        $this->assertStringNotContainsString('Sinyal Grup Kerjaan', $source);
        $this->assertStringNotContainsString('workSignals', $source);
        $this->assertStringNotContainsString('Kandidat Gangguan Monitoring', $source);
        $this->assertStringNotContainsString('workSignals', $controller);
        $this->assertStringNotContainsString('WaChatIntelligenceService', $controller);
    }

    public function test_status_change_clears_archive_but_keeps_it_for_all_and_archive(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/Tickets/Index.tsx'));

        $this->assertStringContainsString("key === 'status' && value !== 'archive' && value !== 'all' ? { archive: undefined }", $source);
    }

    public function test_candidates_drawer_is_lazy_semantic_and_gates_draft_creation(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Components/tickets/MonitoringCandidatesDrawer.tsx'));

        $this->assertStringContainsString("from '@/Components/ui/sheet'", $source);
        $this->assertStringContainsString("only: ['monitoringEvidence']", $source);
        $this->assertStringContainsString('canCreateDraft', $source);
        $this->assertStringContainsString('Tampilkan yang sudah bertiket', $source);
        $this->assertStringContainsString('Data monitoring terlambat', $source);
        $this->assertStringContainsString('PON offline', $source);
        $this->assertStringContainsString('Redaman buruk', $source);
        $this->assertDoesNotMatchRegularExpression('/\b(?:bg|text|border)-(?:red|amber|orange|yellow|green|emerald|blue|sky|indigo|violet)-\d{2,3}\b/', $source);
    }
}
