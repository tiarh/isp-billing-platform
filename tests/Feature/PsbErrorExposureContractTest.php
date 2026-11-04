<?php

namespace Tests\Feature;

use Tests\TestCase;

class PsbErrorExposureContractTest extends TestCase
{
    public function test_psb_draft_error_responses_do_not_return_raw_exception_messages(): void
    {
        $source = file_get_contents(base_path('app/Http/Controllers/PsbDraftController.php'));

        $this->assertStringContainsString("'message' => 'PSB operation failed. Check the draft status and server logs.'", $source);
        $this->assertStringContainsString("'last_error' => SensitiveDataRedactor::redact((string) \$d->last_error)", $source);
        $this->assertStringNotContainsString("'message' => \$e->getMessage()", $source);
        $this->assertStringNotContainsString("with('error', \$e->getMessage())", $source);

        $jobSource = file_get_contents(base_path('app/Jobs/ProcessPsbWaJob.php'));
        $this->assertStringContainsString(
            "'Antrian perangkat sibuk. Tidak perlu kirim ulang; sistem akan mencoba lagi.'",
            $jobSource,
        );
        $this->assertStringNotContainsString('reply($group, $e->getMessage())', $jobSource);
    }
}
