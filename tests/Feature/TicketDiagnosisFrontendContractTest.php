<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * RCA-4 frontend contract: the "Diagnosa otomatis" panel (ticket create form + ticket detail) and the card
 * "Ketepatan diagnosa" of Monitoring > Deteksi Gangguan keep the props / route names the backend sends.
 */
#[Group('blocking-core')]
class TicketDiagnosisFrontendContractTest extends TestCase
{
    private function source(string $path): string
    {
        return (string) file_get_contents(base_path($path));
    }

    public function test_the_panel_speaks_plain_indonesian_and_uses_the_shared_helpers(): void
    {
        $s = $this->source('resources/js/Components/tickets/DiagnosisPanel.tsx');

        foreach (['Diagnosa otomatis', 'Cek sekarang', 'mengecek…', 'Keyakinan', 'Saran', 'Bukti', 'lihat semua', 'Belum ada diagnosa', 'terlalu lama, dilewati', 'Hasil cek belum bisa dihitung'] as $text) {
            $this->assertStringContainsString($text, $s, $text);
        }
        foreach (['diagnosis-panel', 'diagnosis-toggle', 'diagnosis-cause', 'diagnosis-confidence', 'diagnosis-action', 'diagnosis-evidence', 'diagnosis-evidence-more', 'diagnosis-time', 'diagnosis-check-now', 'diagnosis-probes', 'diagnosis-message'] as $testId) {
            $this->assertStringContainsString('data-testid="'.$testId.'"', $s, $testId);
        }
        $this->assertStringContainsString("from '@/lib/dateTime'", $s);
        $this->assertStringNotContainsString('toLocaleString(', $s);
        $this->assertStringNotContainsString('new Intl.', $s);
        $this->assertStringNotContainsString('violet-', $s);
        $this->assertStringContainsString('aria-expanded', $s);
        $this->assertStringContainsString('role="status"', $s);
    }

    public function test_the_panel_calls_only_routes_that_exist_and_each_probe_has_its_own_timeout(): void
    {
        $s = $this->source('resources/js/Components/tickets/DiagnosisPanel.tsx');
        $routes = $this->source('routes/web.php');

        foreach (['tickets.diagnose.start', 'tickets.diagnose.probe', 'tickets.diagnose.finish', 'tickets.diagnose.customer'] as $name) {
            $this->assertStringContainsString("route('".$name."'", $s, $name);
            $this->assertStringContainsString("->name('".substr($name, strlen('tickets.diagnose.'))."')", $routes, $name);
        }
        $this->assertStringContainsString("name('tickets.diagnose.')", $routes);
        $this->assertStringContainsString('timeouts[name]', $s, 'the browser aborts each probe on its own limit');
        $this->assertStringContainsString('Promise.all(', $s, 'the three probes run in parallel');
    }

    public function test_the_payload_keys_of_the_backend_match_the_typescript_type(): void
    {
        $s = $this->source('resources/js/Components/tickets/DiagnosisPanel.tsx');
        $php = $this->source('app/Services/Outage/Diagnosis/DiagnosisPresenter.php');

        foreach (['id', 'state', 'trigger', 'cause', 'label', 'confidence', 'action', 'evidence', 'secondary', 'probes', 'created_at'] as $key) {
            $this->assertStringContainsString("'".$key."'", $php, $key);
            $this->assertMatchesRegularExpression('/\b'.$key.'\??:/', $s, $key);
        }
        foreach (['checkNowEnabled', 'cooldownMinutes', 'timeouts', 'diagnosis'] as $key) {
            $this->assertStringContainsString("'".$key."'", $php, $key);
            $this->assertStringContainsString($key, $s, $key);
        }
        // the calibrated word (setting diagnosis_calibrated_labels_enabled): same key in the payload and the panel type
        $this->assertStringContainsString("'confidence_word'", $php);
        $this->assertMatchesRegularExpression('/\bconfidence_word\??:/', $s);
        foreach (['Pasti', 'Kemungkinan', 'Belum tahu'] as $word) {
            $this->assertStringContainsString("'".$word."'", $php.$s, $word);
        }
        $this->assertStringContainsString('diagnosis.confidence_word ??', $s, 'the old word is the fallback while the setting is off');
    }

    public function test_the_create_form_shows_the_panel_only_for_a_incident_with_a_customer_and_sends_the_check_id(): void
    {
        $s = $this->source('resources/js/Pages/Tickets/Create.tsx');

        $this->assertStringContainsString('import DiagnosisPanel', $s);
        $this->assertStringContainsString("diagnosisPanel && data.type === 'incident' && data.customer_id !== ''", $s);
        $this->assertStringContainsString('fetchPreview', $s);
        $this->assertStringContainsString("diagnosis_check_id: '' as string", $s);
        $this->assertStringContainsString("setData('diagnosis_check_id'", $s);
    }

    public function test_the_ticket_detail_passes_the_ticket_to_the_panel(): void
    {
        $s = $this->source('resources/js/Pages/Tickets/Show.tsx');

        $this->assertStringContainsString('import DiagnosisPanel', $s);
        $this->assertStringContainsString('diagnosisPanel?: DiagnosisPanelProps | null', $s);
        $this->assertStringContainsString('ticketId={ticket.id}', $s);
        $this->assertStringContainsString('initial={diagnosisPanel}', $s);
    }

    public function test_the_precision_card_pages_the_confusion_list_and_names_the_excluded_closes(): void
    {
        $card = $this->source('resources/js/Components/monitoring/DiagnosisPrecisionCard.tsx');
        $panel = $this->source('resources/js/Pages/Monitoring/OutageShadowPanel.tsx');

        $this->assertStringContainsString('Ketepatan diagnosa', $card);
        $this->assertStringContainsString('ditutup otomatis', $card);
        $this->assertStringContainsString('Tidak dinilai', $card);
        $this->assertStringContainsString('"Tidak tahu"', $card);
        foreach (['diagnosis-precision', 'diagnosis-precision-classes', 'diagnosis-precision-confusions', 'diagnosis-precision-excluded', 'diagnosis-precision-sum', 'diagnosis-precision-versions', 'diagnosis-precision-outcomes', 'diagnosis-precision-disagree'] as $testId) {
            $this->assertStringContainsString('data-testid="'.$testId.'"', $card, $testId);
        }
        // the two pagers build their test ids from one prefix: -prev / -page / -next
        foreach (['-prev', '-page', '-next'] as $suffix) {
            $this->assertStringContainsString('data-testid={`${testid}'.$suffix.'`}', $card, $suffix);
        }
        $this->assertStringContainsString('testid="diagnosis-precision"', $card);
        $this->assertStringContainsString('testid="diagnosis-precision-disagree"', $card);
        $this->assertStringContainsString("route('api.monitoring.outage-shadow.diagnosis-precision')", $card);
        // every new field of the weekly report that the card reads is written by PrecisionReport (same key, same spelling)
        $report = $this->source('app/Services/Outage/Diagnosis/PrecisionReport.php');
        foreach (['by_version', 'calibration', 'outcomes', 'disagreements', 'truth_not_scored', 'backfilled', 'bulk_closed', 'correct_family', 'precision_family', 'ci_family', 'wilson_low', 'n_independent', 'n_circular', 'by_class', 'signature'] as $key) {
            $this->assertStringContainsString("'".$key."'", $report, $key);
            $this->assertStringContainsString($key, $card, $key);
        }
        foreach (['Pasti', 'Kemungkinan', 'Belum tahu', 'Label beda dengan data', 'Per versi aturan'] as $text) {
            $this->assertStringContainsString($text, $card, $text);
        }
        $this->assertStringContainsString('<DiagnosisPrecisionCard />', $panel);
        // the system guess is shown in the row ("Dugaan:") and in the detail panel ("Dugaan sistem:")
        $drawer = $this->source('resources/js/Components/monitoring/deteksi/EpisodeDrawer.tsx');
        $this->assertStringContainsString('Dugaan sistem:', $drawer);
        $this->assertStringContainsString('diagnosed_label', $drawer);
        $this->assertStringContainsString('diagnosed_label', $this->source('resources/js/Components/monitoring/deteksi/types.ts'));
    }
}
