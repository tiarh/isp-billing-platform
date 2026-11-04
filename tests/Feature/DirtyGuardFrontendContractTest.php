<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class DirtyGuardFrontendContractTest extends TestCase
{
    private function source(string $path): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.$path);
    }

    public function test_shared_guard_covers_browser_and_inertia_navigation(): void
    {
        $source = $this->source('resources/js/hooks/useUnsavedChangesGuard.ts');

        $this->assertStringContainsString("window.addEventListener('beforeunload'", $source);
        $this->assertStringContainsString("router.on('before'", $source);
        $this->assertStringContainsString('event.preventDefault()', $source);
        $this->assertStringContainsString('window.confirm(message)', $source);
    }

    public function test_shared_guard_ignores_own_saves_but_still_warns_on_logout(): void
    {
        $source = $this->source('resources/js/hooks/useUnsavedChangesGuard.ts');

        $this->assertStringContainsString("method !== 'get' && !LOGOUT_PATH.test(url.pathname)", $source);
        $this->assertStringContainsString('/\/logout\/?$/', $source);

        $sidebar = $this->source('resources/js/Components/AppSidebar.tsx');
        $this->assertStringContainsString("route('logout')", $sidebar);
        $this->assertStringContainsString('method="post"', $sidebar);
    }

    public function test_customer_psb_and_area_migration_forms_register_dirty_guard(): void
    {
        $pages = [
            'customer create' => 'resources/js/Pages/Customers/Create.tsx',
            'customer edit' => 'resources/js/Pages/Customers/Edit.tsx',
            'PSB activation' => 'resources/js/Pages/OltC300/Index.tsx',
            'area migration' => 'resources/js/Pages/Areas/Migration.tsx',
        ];

        foreach ($pages as $label => $path) {
            $source = $this->source($path);

            $this->assertStringContainsString('useUnsavedChangesGuard', $source, $label);
        }

        $this->assertStringContainsString('useUnsavedChangesGuard(form.isDirty && !form.processing)', $this->source($pages['customer create']));
        $this->assertStringContainsString('useUnsavedChangesGuard(form.isDirty && !form.processing)', $this->source($pages['customer edit']));
        $this->assertStringContainsString('useUnsavedChangesGuard(form.isDirty && !form.processing)', $this->source($pages['area migration']));
        $this->assertStringContainsString('useUnsavedChangesGuard(autoppFormDirty && !autoppLoading', $this->source($pages['PSB activation']));
    }
}
