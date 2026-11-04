<?php

namespace Tests\Feature;

use Tests\TestCase;

class OltC300FiberHomeCopyFrontendContractTest extends TestCase
{
    public function test_fiberhome_provision_phase_log_has_no_decorative_glyphs(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/OltC300/Index.tsx'));
        $provisionBlock = $this->slice($source, 'const handleFiberhomeProvision', 'const csrfToken');

        $this->assertDoesNotMatchRegularExpression('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $provisionBlock);
        $this->assertStringContainsString('DRY RUN FiberHome — preview command plan', $provisionBlock);
        $this->assertStringContainsString('LIVE PROVISIONING FiberHome ke OLT', $provisionBlock);
        $this->assertStringContainsString('addFhLog', $provisionBlock);
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
