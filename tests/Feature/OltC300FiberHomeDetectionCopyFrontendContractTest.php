<?php

namespace Tests\Feature;

use Tests\TestCase;

class OltC300FiberHomeDetectionCopyFrontendContractTest extends TestCase
{
    public function test_fiberhome_detection_logs_have_no_decorative_glyphs_without_changing_handlers(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/OltC300/Index.tsx'));
        $detectionBlock = $this->slice($source, '// ===== FiberHome LIST UNCFG', '// ===== FiberHome provision handler');

        $this->assertDoesNotMatchRegularExpression('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $detectionBlock);

        foreach (['handleFiberhomeListUncfg', 'checkFiberhomePort', 'handleFiberhomeSelectUncfg', 'handleFiberhomeDetect'] as $handler) {
            $this->assertStringContainsString("const {$handler}", $source);
        }

        foreach (['/api/olt-c300/list-uncfg', '/api/olt-c300/check', '/api/olt-c300/detect'] as $endpoint) {
            $this->assertStringContainsString($endpoint, $detectionBlock);
        }

        $this->assertStringContainsString('addFhLog', $detectionBlock);
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
