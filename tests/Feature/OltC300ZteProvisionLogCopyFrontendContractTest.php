<?php

namespace Tests\Feature;

use Tests\TestCase;

class OltC300ZteProvisionLogCopyFrontendContractTest extends TestCase
{
    public function test_zte_provision_logs_have_no_decorative_glyphs_without_changing_request_contract(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/OltC300/Index.tsx'));
        $provisionBlock = $this->slice($source, 'const handleProvision', 'const cancelConfirm');

        $this->assertDoesNotMatchRegularExpression('/[\x{1F000}-\x{1FAFF}\x{2139}\x{23F3}\x{26A0}\x{26A1}\x{2705}\x{270F}\x{274C}]/u', $provisionBlock);
        $this->assertStringContainsString("fetch('/api/olt-c300/provision'", $provisionBlock);
        $this->assertStringContainsString('password: provPassword', $provisionBlock);
        $this->assertStringContainsString('getProvisionCommands', $provisionBlock);
        $this->assertStringContainsString('processBackendLog', $provisionBlock);
        $this->assertStringContainsString('rollback dalam 60 detik', $provisionBlock);
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
