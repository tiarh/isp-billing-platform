<?php

namespace Tests\Feature;

use Tests\TestCase;

class OltC300RenameResultCopyFrontendContractTest extends TestCase
{
    public function test_rename_result_copy_has_no_decorative_glyphs_without_changing_acs_contract(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/OltC300/Index.tsx'));
        $resultBlock = $this->slice($source, '{renameResult?.success && (', '<ConsoleLog log={renameLog}');

        $this->assertDoesNotMatchRegularExpression('/[\x{1F000}-\x{1FAFF}\x{2139}\x{23F3}\x{2705}\x{26A0}\x{270F}\x{274C}]/u', $resultBlock);

        foreach ([
            'renameResult?.success',
            'renameResult.message || \'Rename OK\'',
            'renameResult.acs_tag',
            'renameResult.acs_tag.pushed',
            "renameResult.acs_tag.message || 'pending/skip'",
            'tag pushed',
        ] as $contract) {
            $this->assertStringContainsString($contract, $resultBlock);
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
