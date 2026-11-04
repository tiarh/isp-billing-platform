<?php

namespace Tests\Feature;

use Tests\TestCase;

class OltC300CredentialCopyFrontendContractTest extends TestCase
{
    public function test_credential_feedback_has_no_glyph_without_changing_secret_request_contract(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/OltC300/Index.tsx'));
        $credentialBlock = $this->slice($source, 'const handleUpdateCredentials', 'const handleProvision');

        $this->assertDoesNotMatchRegularExpression('/[\x{1F000}-\x{1FAFF}\x{2139}\x{23F3}\x{26A0}\x{26A1}\x{2705}\x{270F}\x{274C}]/u', $credentialBlock);
        $this->assertStringContainsString("fetch('/api/olt-c300/update-credentials'", $credentialBlock);
        $this->assertStringContainsString("method: 'PUT'", $credentialBlock);
        $this->assertStringContainsString('password: credPassword', $credentialBlock);
        $this->assertStringContainsString("setCredPassword('')", $credentialBlock);
        $this->assertStringContainsString("addLog('success', `Kredensial OLT diupdate", $credentialBlock);
        $this->assertStringNotContainsString('credPassword}`', $credentialBlock);
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
