<?php

namespace Tests\Feature;

use Tests\TestCase;

class OltC300FiberHomeActionCopyFrontendContractTest extends TestCase
{
    public function test_fiberhome_action_copy_has_no_decorative_glyphs_without_changing_handlers(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/OltC300/Index.tsx'));
        $handlerBlock = $this->slice($source, 'const handleFiberhomeProvision', 'const handleReconfigure');
        $this->assertDoesNotMatchRegularExpression('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $handlerBlock);
        $this->assertStringContainsString('DRY RUN FiberHome — preview command plan', $handlerBlock);
        $this->assertStringContainsString('LIVE PROVISIONING FiberHome ke OLT', $handlerBlock);
        $this->assertStringContainsString('addFhLog', $handlerBlock);

        $fiberhomeBlock = $this->slice($source, '/* ===== FIBERHOME PROVISION TAB ===== */', '/* Result info box */');

        $this->assertDoesNotMatchRegularExpression('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $fiberhomeBlock);

        foreach (['handleFiberhomeListUncfg', 'handleFiberhomeDetect', 'checkFiberhomePort', 'handleFiberhomeProvision'] as $handler) {
            $this->assertStringContainsString("const {$handler}", $source);
        }

        foreach ([
            'Aktivasi ONU FiberHome (TR-069 VLAN',
            'Pilih MikroTik (PPPoE / TR-069)',
            'Cek next ONU ID untuk port ${port}',
            'Port ${port}: ONU ID ${result.nextId}, VLAN ${result.vlan}',
            'Dry Run (Preview)',
            'Provision Now',
        ] as $copy) {
            $this->assertStringContainsString($copy, $fiberhomeBlock);
        }

        $credentialsBlock = $this->slice($source, '/* ===== CREDENTIALS MODAL ===== */', '</EnterpriseIndexLayout>');
        $this->assertDoesNotMatchRegularExpression('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $credentialsBlock);
        $this->assertStringContainsString('Update Kredensial OLT', $credentialsBlock);
        $this->assertStringContainsString('Menyimpan...', $credentialsBlock);
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
