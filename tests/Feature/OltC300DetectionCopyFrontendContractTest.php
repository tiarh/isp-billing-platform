<?php

namespace Tests\Feature;

use Tests\TestCase;

class OltC300DetectionCopyFrontendContractTest extends TestCase
{
    public function test_ocr_detection_connection_and_uncfg_copy_has_no_emoji_without_changing_api_contract(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/OltC300/Index.tsx'));

        $ocrBlock = $this->slice($source, 'const handleUpload =', '// Helper: handle SSH-related errors');
        $connectionBlock = $this->slice($source, 'const handleTestConnection =', 'const handleUpdateCredentials =');
        $uncfgBlock = $this->slice($source, 'const handleListUncfg =', 'const handleUpdateCredentials =');

        $this->assertDoesNotMatchRegularExpression('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $ocrBlock);
        $this->assertDoesNotMatchRegularExpression('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $connectionBlock);
        $this->assertDoesNotMatchRegularExpression('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $uncfgBlock);

        foreach (['/api/olt-c300/ocr', '/api/olt-c300/detect', '/api/olt-c300/test-connection', '/api/olt-c300/list-uncfg'] as $endpoint) {
            $this->assertStringContainsString($endpoint, $source);
        }

        $this->assertStringContainsString("method: 'POST'", $ocrBlock);
        $this->assertStringContainsString("method: 'POST'", $connectionBlock);
        $this->assertStringContainsString("method: 'POST'", $uncfgBlock);
    }

    private function slice(string $source, string $start, string $end): string
    {
        $startAt = strpos($source, $start);
        $endAt = strpos($source, $end, $startAt === false ? 0 : $startAt);

        $this->assertNotFalse($startAt);
        $this->assertNotFalse($endAt);

        return substr($source, $startAt, $endAt - $startAt);
    }
}
