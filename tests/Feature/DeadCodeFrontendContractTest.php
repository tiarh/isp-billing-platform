<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Redesign A PR-8: code removed as unreferenced must not come back through a
 * stale branch or rebase.
 */
class DeadCodeFrontendContractTest extends TestCase
{
    private const DELETED = [
        'resources/js/Components/PrimaryButton.tsx',
        'resources/js/Components/SecondaryButton.tsx',
        'resources/js/Components/DangerButton.tsx',
        'resources/js/Components/TextInput.tsx',
        'resources/js/Components/InputLabel.tsx',
        'resources/js/Components/InputError.tsx',
        'resources/js/Components/NavLink.tsx',
        'resources/js/Components/ResponsiveNavLink.tsx',
        'resources/js/Components/ActivityFeed.tsx',
        'resources/js/Components/ResourcePageHeader.tsx',
        'resources/js/Components/ProfileSelector.tsx',
        'resources/js/Components/enterprise/LazySection.tsx',
        'resources/js/Components/enterprise/ResponsivePanel.tsx',
        'resources/js/Components/ui/form.tsx',
        'resources/js/Components/ui/progress.tsx',
        'resources/js/lib/oltPorts.ts',
        'resources/js/Pages/Modules/Placeholder.tsx',
        'resources/js/Pages/PsbDrafts/Index.tsx',
        'app/Http/Controllers/Auth/RegisteredUserController.php',
        'resources/js/Layouts/GuestLayout.tsx',
        'resources/js/Components/ApplicationLogo.tsx',
        'resources/js/Pages/Auth/Register.tsx',
    ];

    public function test_deleted_dead_files_stay_deleted(): void
    {
        foreach (self::DELETED as $path) {
            $this->assertFileDoesNotExist(base_path($path), $path.' was removed as dead code in Redesign A PR-8.');
        }
    }

    public function test_metric_card_takes_no_tone_prop(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Components/enterprise/EnterpriseMetricCard.tsx'));
        $this->assertStringNotContainsString('tone?:', $source);
        $this->assertStringNotContainsString('tone,', $source);
        $this->assertStringNotContainsString('toneMap', $source);
    }
}
