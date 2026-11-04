<?php

namespace Tests\Feature;

use Tests\TestCase;

class PsbWaPanelCheckmarkFrontendContractTest extends TestCase
{
    public function test_group_selection_indicator_uses_lucide_check_without_glyphs(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Components/PsbWa/PsbWaPanel.tsx'));

        $this->assertStringContainsString('import { Activity, Cable, Check, CheckCircle2, Loader2, RefreshCw, Settings2 } from', $source);
        $this->assertStringContainsString('<Check className="h-3 w-3" aria-hidden="true" />', $source);
        $this->assertStringNotContainsString("on ? '✓' : ''", $source);
        $this->assertSame(0, preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $source));
    }
}
