<?php

namespace Tests\Feature;

use Tests\TestCase;

class PsbImportsFrontendContractTest extends TestCase
{
    public function test_psb_import_uses_shared_currency_formatting(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/PsbImports/Index.tsx'));

        $this->assertStringContainsString("from '@/lib/format'", $source);
        $this->assertStringContainsString('formatIdr(', $source);
        $this->assertStringNotContainsString('toLocaleString(', $source);
        $this->assertStringNotContainsString('new Intl.NumberFormat', $source);
        $this->assertStringContainsString('<DataTable', $source);
        $this->assertStringNotContainsString('tone="violet"', $source);
    }
}
