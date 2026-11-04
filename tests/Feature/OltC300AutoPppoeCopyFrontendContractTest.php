<?php

namespace Tests\Feature;

use Tests\TestCase;

class OltC300AutoPppoeCopyFrontendContractTest extends TestCase
{
    public function test_auto_pppoe_feedback_has_no_decorative_glyphs_without_changing_request_contract(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/OltC300/Index.tsx'));
        $autoPppoeBlock = $this->slice($source, 'const runKtpOcr', 'const goToZteProvision');

        $this->assertDoesNotMatchRegularExpression(
            '/[\x{1F000}-\x{1FAFF}\x{2139}\x{23F3}\x{26A0}\x{26A1}\x{2705}\x{270F}\x{274C}]/u',
            $autoPppoeBlock,
        );
        $this->assertStringContainsString("fetch('/api/ktp-ocr'", $autoPppoeBlock);
        $this->assertStringContainsString("fetch('/api/olt-c300/auto-pppoe'", $autoPppoeBlock);
        $this->assertStringContainsString("method: 'POST'", $autoPppoeBlock);
        $this->assertStringContainsString("fd.append('dry_run', autoppDryRun ? '1' : '0')", $autoPppoeBlock);
        $this->assertStringContainsString("addFhLog('success', `Aktivasi auto:", $autoPppoeBlock);
        $this->assertStringNotContainsString('password', $autoPppoeBlock);

        $this->assertStringContainsString("autoppOcrNote.startsWith('OCR:') ? 'text-status-up'", $source);
    }

    public function test_auto_pppoe_result_copy_has_no_decorative_glyphs_without_changing_actions(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/OltC300/Index.tsx'));
        $resultBlock = $this->slice($source, '{autoppError && (', "{activeTab === 'activate' && (");

        $this->assertDoesNotMatchRegularExpression(
            '/[\x{1F000}-\x{1FAFF}\x{2139}\x{23F3}\x{26A0}\x{26A1}\x{2705}\x{270F}\x{274C}]/u',
            $resultBlock,
        );
        $this->assertStringNotContainsString('purple-', $resultBlock);
        $this->assertStringContainsString('<Paperclip', $source);
        $this->assertStringContainsString('<AlertCircle', $resultBlock);
        $this->assertStringContainsString('<Search', $resultBlock);
        $this->assertStringContainsString('<CheckCircle2', $resultBlock);
        $this->assertStringContainsString('<ArrowRight', $resultBlock);
        $this->assertStringContainsString("autoppResult.next_step === 'zte_provision'", $resultBlock);
        $this->assertStringContainsString("autoppResult.next_step === 'fiberhome_hint'", $resultBlock);
        $this->assertStringContainsString('goToZteProvision', $resultBlock);
        $this->assertStringContainsString('goToFiberhomeProvision', $resultBlock);
        $this->assertStringContainsString('PPPoE Password'.':', $resultBlock);
    }

    private function slice(string $source, string $start, string $end): string
    {
        $startOffset = strpos($source, $start);
        $endOffset = strpos($source, $end, $startOffset === false ? 0 : $startOffset);

        $this->assertNotFalse($startOffset, "Missing source anchor: {$start}");
        $this->assertNotFalse($endOffset, "Missing source anchor: {$end}");

        return substr($source, $startOffset, $endOffset - $startOffset);
    }
}
