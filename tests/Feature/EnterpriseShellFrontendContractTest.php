<?php

namespace Tests\Feature;

use Tests\TestCase;

class EnterpriseShellFrontendContractTest extends TestCase
{
    public function test_shared_shell_headers_use_solid_surfaces_without_gradients_or_glow(): void
    {
        $files = [
            'resources/js/Components/enterprise/PublicPageShell.tsx',
            'resources/js/Components/enterprise/EnterprisePageShell.tsx',
        ];

        foreach ($files as $file) {
            $source = (string) file_get_contents(base_path($file));

            $this->assertStringContainsString('bg-card', $source, $file);
            $this->assertStringNotContainsString('bg-gradient-', $source, $file);
            $this->assertStringNotContainsString('blur-2xl', $source, $file);
            $this->assertStringNotContainsString('blur-3xl', $source, $file);
        }
    }
}
