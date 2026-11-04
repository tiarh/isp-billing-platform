<?php

namespace Tests\Feature;

use Tests\TestCase;

class OltC300TabLabelsFrontendContractTest extends TestCase
{
    public function test_tab_switcher_uses_functional_labels_without_changing_tab_handlers(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/OltC300/Index.tsx'));
        $tabBlock = $this->slice($source, '/* Tab Switcher', '</TabsList>');

        $this->assertDoesNotMatchRegularExpression('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $tabBlock);

        // S6 (PR-UX3): the switcher is ui/tabs; every tab is a TabsTrigger bound to activeTab.
        $this->assertStringContainsString('<Tabs value={activeTab} onValueChange={(value) => setActiveTab(value as AktivasiTab)}>', $source);
        foreach (['autopp', 'activate', 'reconfigure', 'fiberhome', 'huawei', 'psb', 'vlan-mt', 'vlan-olt', 'rename'] as $tab) {
            $this->assertStringContainsString('<TabsTrigger value="'.$tab.'"', $tabBlock);
        }
        $this->assertStringContainsString('overflow-x-auto', $tabBlock);

        foreach (['Auto PPPoE', 'Provision ZTE C300', 'Rekonfig ZTE', 'Provision FiberHome', 'PSB WA', 'VLAN MikroTik', 'VLAN OLT / Area', 'Rename ONU'] as $label) {
            $this->assertStringContainsString($label, $tabBlock);
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
