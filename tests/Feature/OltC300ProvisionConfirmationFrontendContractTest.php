<?php

namespace Tests\Feature;

use Tests\TestCase;

class OltC300ProvisionConfirmationFrontendContractTest extends TestCase
{
    public function test_fiberhome_live_provision_uses_shared_confirmation(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/OltC300/Index.tsx'));

        $this->assertStringContainsString(
            "import { ConfirmDestructive } from '@/Components/ConfirmDestructive';",
            $source,
        );
        $this->assertStringContainsString(
            'const [fhProvisionConfirmOpen, setFhProvisionConfirmOpen] = useState(false);',
            $source,
        );
        $this->assertStringContainsString(
            'onClick={() => setFhProvisionConfirmOpen(true)}',
            $source,
        );
        $this->assertStringContainsString('<ConfirmDestructive', $source);
        $this->assertStringContainsString('void handleFiberhomeProvision(false, typed);', $source);
        // S4: the typed phrase is forwarded, never a hardcoded constant.
        $this->assertStringContainsString('live_confirm: dryRun ? undefined : liveConfirm', $source);
        $this->assertStringNotContainsString("live_confirm: dryRun ? undefined : 'PROVISION OLT'", $source);
        $this->assertStringNotContainsString('confirm(', $source);
    }

    public function test_confirmation_does_not_change_fiberhome_request_boundary(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/OltC300/Index.tsx'));

        $this->assertStringContainsString("fetch('/api/olt-c300/provision-fiberhome'", $source);
        $this->assertStringContainsString("method: 'POST'", $source);
        $this->assertStringContainsString('dry_run: dryRun', $source);
        $this->assertStringContainsString('purpose: fhPurpose', $source);
        $this->assertStringContainsString('live_confirm:', $source);
    }
}
