<?php

namespace Tests\Feature;

use Tests\TestCase;

class OltC300ReconfigureCopyFrontendContractTest extends TestCase
{
    public function test_reconfigure_tab_copy_has_no_emoji_without_changing_handler(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/OltC300/Index.tsx'));
        $reconfigureBlock = $this->slice($source, '/* ===== RECONFIGURE TAB ===== */', '/* ===== FIBERHOME PROVISION TAB ===== */');

        $this->assertDoesNotMatchRegularExpression('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $reconfigureBlock);
        $this->assertStringContainsString('const handleReconfigure', $source);
        $this->assertStringContainsString('Rekonfigurasi ONU', $reconfigureBlock);
        $this->assertStringContainsString('Password tidak ditampilkan pada log command.', $reconfigureBlock);
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
