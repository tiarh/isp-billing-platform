<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Frontend contract of the "Penyebab sebenarnya" picker (plan ticket-root-cause-required): the props and test ids
 * the backend and the browser spec rely on, and plain Indonesian text.
 */
#[Group('blocking-core')]
class RootCausePickerFrontendContractTest extends TestCase
{
    private function source(string $path): string
    {
        return (string) file_get_contents(base_path($path));
    }

    public function test_the_picker_keeps_its_test_ids_and_plain_texts(): void
    {
        $s = $this->source('resources/js/Components/RootCausePicker.tsx');

        foreach (['root-cause-field', 'root-cause-error', 'root-cause-more', 'root-cause-note', 'root-cause-note-error', 'root-cause-suggestion', 'root-cause-use-suggestion'] as $testId) {
            $this->assertStringContainsString('data-testid="'.$testId.'"', $s, $testId);
        }
        $this->assertStringContainsString('data-testid={`root-cause-option-${option.value}`}', $s);
        foreach (['Penyebab lain…', 'Tulis singkat penyebabnya', 'Saran sistem:', 'Pakai saran', 'Wajib diisi. Tidak yakin? Buka Penyebab lain, lalu pilih Tidak tahu.'] as $text) {
            $this->assertStringContainsString($text, $s, $text);
        }
        // learn & measure: one hint line per choice, and the "Saran dari data" chip that saves with via = signature
        foreach (['root-cause-signature', 'root-cause-use-signature'] as $testId) {
            $this->assertStringContainsString('data-testid="'.$testId.'"', $s, $testId);
        }
        $this->assertStringContainsString('data-testid={`root-cause-hint-${option.value}`}', $s);
        $this->assertStringContainsString("onChange(signature.code, 'signature')", $s);
        $this->assertStringContainsString("'suggestion' | 'manual' | 'signature'", $s);
        $this->assertStringContainsString('Saran dari data:', $s);
        $this->assertStringContainsString('hint?: string | null', $s);
        // phone first: one column under 380 px, buttons at least 44 px high
        $this->assertStringContainsString('grid-cols-1', $s);
        $this->assertStringContainsString('min-h-11', $s);
        $this->assertStringContainsString('aria-pressed', $s);
        $this->assertStringContainsString('maxLength={ROOT_CAUSE_NOTE_MAX}', $s);
    }

    public function test_show_and_edit_use_the_picker_and_send_note_and_via(): void
    {
        $this->assertFileDoesNotExist(base_path('resources/js/Components/RootCauseField.tsx'));

        foreach (['resources/js/Pages/Tickets/Show.tsx', 'resources/js/Pages/Tickets/Edit.tsx'] as $path) {
            $s = $this->source($path);
            $this->assertStringContainsString("from '@/Components/RootCausePicker'", $s, $path);
            $this->assertStringNotContainsString('RootCauseField', $s, $path);
            foreach (['root_cause_note', 'root_cause_via', 'rootCauseSuggestion', 'repairSignature', 'signature={repairSignature}'] as $prop) {
                $this->assertStringContainsString($prop, $s, $path.' '.$prop);
            }
        }

        $show = $this->source('resources/js/Pages/Tickets/Show.tsx');
        $this->assertStringContainsString('data-testid="ticket-root-cause-note"', $show);
        $this->assertStringContainsString('data-testid="ticket-close-confirm"', $show);
    }
}
