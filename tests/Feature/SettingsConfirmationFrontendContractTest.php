<?php

namespace Tests\Feature;

use Tests\TestCase;

class SettingsConfirmationFrontendContractTest extends TestCase
{
    public function test_settings_live_actions_use_shared_confirmation_instead_of_browser_confirm(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/Settings/Index.tsx'));

        $this->assertStringContainsString("import { ConfirmDestructive } from '@/Components/ConfirmDestructive';", $source);
        $this->assertStringContainsString('<ConfirmDestructive', $source);
        $this->assertStringNotContainsString('window.confirm(', $source);

        foreach ([
            '/api/settings/billing-enforcement',
            '/api/settings/billing-wa/live',
            '/api/settings/billing-wa/billing-live',
        ] as $endpoint) {
            $this->assertStringContainsString($endpoint, $source);
        }
    }
}
