<?php

namespace Tests\Feature;

use Tests\TestCase;

class OltC300ActivationCopyFrontendContractTest extends TestCase
{
    public function test_activation_tab_copy_has_no_emoji_without_changing_handlers(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/OltC300/Index.tsx'));
        $activationBlock = $this->slice($source, '/* Step 1: Upload & Detect */', '/* Console Log - Activate */');

        $this->assertDoesNotMatchRegularExpression('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $activationBlock);

        foreach (['handleUpload', 'handleDetect', 'handleListUncfg', 'handleTestConnection', 'handleProvision'] as $handler) {
            $this->assertStringContainsString("const {$handler}", $source);
        }

        foreach (['const cancelConfirm', 'const reset'] as $handler) {
            $this->assertStringContainsString($handler, $source);
        }

        $this->assertStringContainsString('<ArrowLeft', $activationBlock);
        $this->assertStringContainsString('<ArrowRight', $activationBlock);
        $this->assertStringContainsString("setStep('provisioning')", $source);
        $this->assertStringContainsString("setStep('done')", $source);

        foreach ([
            'Langkah 1: Ambil Foto & Deteksi SN ONU',
            'Ambil Foto / Upload',
            'Deteksi ONU',
            'title="Lihat semua ONU uncfg"',
            'title="Tes koneksi SSH ke OLT"',
            'title="Update username/password OLT"',
            'Langkah 2: Konfirmasi & Aktifkan',
            'Aktifkan ONU Sekarang',
            'Sedang Mengaktifkan...',
            'ONU Berhasil Diaktifkan!',
        ] as $copy) {
            $this->assertStringContainsString($copy, $activationBlock);
        }
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
