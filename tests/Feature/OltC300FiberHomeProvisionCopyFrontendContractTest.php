<?php

namespace Tests\Feature;

use Tests\TestCase;

class OltC300FiberHomeProvisionCopyFrontendContractTest extends TestCase
{
    public function test_fiberhome_provision_logs_have_no_decorative_glyphs_without_changing_request_contract(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/OltC300/Index.tsx'));
        $provisionBlock = $this->slice($source, 'const handleFiberhomeProvision', 'const handleReconfigure');

        $this->assertDoesNotMatchRegularExpression('/[\x{1F000}-\x{1FAFF}\x{2139}\x{23F3}\x{2705}\x{26A0}\x{270F}\x{274C}]/u', $provisionBlock);
        $this->assertStringContainsString("fetch('/api/olt-c300/provision-fiberhome'", $provisionBlock);
        foreach (['olt_id:', 'sn:', 'port:', 'onu_id:', 'name:', 'paket:', 'dry_run:', 'mikrotik:', 'router_id:', 'purpose:'] as $payloadKey) {
            $this->assertStringContainsString($payloadKey, $provisionBlock);
        }
        $this->assertStringContainsString('processBackendLog', $provisionBlock);
        $this->assertStringContainsString('data.verification', $provisionBlock);
    }

    public function test_fiberhome_ui_aligns_router_to_selected_krian_olt(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/OltC300/Index.tsx'));

        $this->assertStringContainsString('FiberHome C300 sites: RA and Krian', $source);
        $this->assertStringContainsString('const matchedSite = mikrotikOptions.find', $source);
        $this->assertStringContainsString('Number(o.olt_id || 0) === Number(targetOltId)', $source);
        $this->assertStringContainsString('Pilih router sesuai OLT C300:', $source);
        $this->assertStringContainsString('VLAN 68 hanya jalur migrasi lama', $source);

        $controller = (string) file_get_contents(base_path('app/Http/Controllers/OltC300Controller.php'));
        $this->assertStringContainsString("\$oltQuery = Olt::query()->where('router_id'", $controller);
        $this->assertStringContainsString("'olt_id' => \$mappedOltId ? (int) \$mappedOltId : null", $controller);
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
