<?php

namespace Tests\Feature;

use Tests\TestCase;

class OltC300FiberHomeResultCopyFrontendContractTest extends TestCase
{
    public function test_fiberhome_result_status_copy_has_no_decorative_glyphs_without_changing_result_contract(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/OltC300/Index.tsx'));
        $resultBlock = $this->slice($source, '/* Result info box */', '/* Console Log - FiberHome */');

        $this->assertDoesNotMatchRegularExpression('/[\x{1F000}-\x{1FAFF}\x{2139}\x{23F3}\x{2705}\x{26A0}\x{270F}\x{274C}]/u', $resultBlock);

        foreach ([
            'fhResult.success || fhResult.olt_committed',
            'fhResult.post_warnings.join',
            'fhResult.verification?.rx_power',
            'fhResult.olt_inventory?.rx_power_dbm',
            'fhResult.genieacs_tag?.pushed',
            'fhResult.genieacs_tag?.pending || fhResult.genieacs_tag?.queued',
            'pushed: ${fhResult.genieacs_tag.tag}',
            'pending: ${fhResult.genieacs_tag.tag || fhResult.name} (retry otomatis)',
            'retry otomatis',
        ] as $contract) {
            $this->assertStringContainsString($contract, $resultBlock);
        }

        $this->assertStringContainsString('GenieACS tag', $resultBlock);
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
