<?php

namespace Tests\Feature;

use Tests\TestCase;

class OltC300ReconfigureLogCopyFrontendContractTest extends TestCase
{
    public function test_reconfigure_logs_have_no_decorative_glyphs_without_changing_request_contract(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/OltC300/Index.tsx'));
        $reconfigureBlock = $this->slice($source, 'const handleReconfigure', 'const copyFhLog');

        $this->assertDoesNotMatchRegularExpression('/[\x{1F000}-\x{1FAFF}\x{23F3}\x{2600}-\x{27BF}]/u', $reconfigureBlock);
        $this->assertStringContainsString("fetch('/api/olt-c300/reconfigure'", $reconfigureBlock);
        $this->assertStringContainsString('password: rPassword', $reconfigureBlock);
        $this->assertStringContainsString('getReconfigCommands', $reconfigureBlock);
        $this->assertStringContainsString('processBackendLog', $reconfigureBlock);
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
