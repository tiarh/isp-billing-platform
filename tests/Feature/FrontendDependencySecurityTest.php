<?php

namespace Tests\Feature;

use Tests\TestCase;

class FrontendDependencySecurityTest extends TestCase
{
    public function test_unused_xlsx_dependency_is_not_declared_or_locked(): void
    {
        $package = json_decode((string) file_get_contents(base_path('package.json')), true, 512, JSON_THROW_ON_ERROR);
        $lock = json_decode((string) file_get_contents(base_path('package-lock.json')), true, 512, JSON_THROW_ON_ERROR);

        $this->assertArrayNotHasKey('xlsx', $package['dependencies'] ?? []);
        $this->assertArrayNotHasKey('node_modules/xlsx', $lock['packages'] ?? []);
    }

    public function test_nonbreaking_security_updates_are_locked(): void
    {
        $lock = json_decode((string) file_get_contents(base_path('package-lock.json')), true, 512, JSON_THROW_ON_ERROR);
        $packages = $lock['packages'] ?? [];

        foreach ([
            'node_modules/axios' => '1.18.0',
            'node_modules/form-data' => '4.0.6',
            'node_modules/nanoid' => '3.3.18',
            'node_modules/postcss' => '8.5.23',
            'node_modules/shell-quote' => '1.10.0',
            'node_modules/esbuild' => '0.28.1',
        ] as $packagePath => $minimumVersion) {
            $this->assertArrayHasKey($packagePath, $packages);
            $this->assertGreaterThanOrEqual($minimumVersion, $packages[$packagePath]['version']);
        }

        // brace-expansion left the tree when ts-node-dev was replaced by tsx
        // (1d2b2e71). It must stay patched if any dependency brings it back.
        if (isset($packages['node_modules/brace-expansion'])) {
            $this->assertGreaterThanOrEqual('1.1.18', $packages['node_modules/brace-expansion']['version']);
        }

        if (isset($packages['node_modules/readdir-glob/node_modules/brace-expansion'])) {
            $this->assertGreaterThanOrEqual(
                '2.1.4',
                $packages['node_modules/readdir-glob/node_modules/brace-expansion']['version'],
            );
        }
    }

    public function test_unused_backend_workspace_is_not_declared(): void
    {
        $root = json_decode((string) file_get_contents(base_path('package.json')), true, 512, JSON_THROW_ON_ERROR);

        $lock = json_decode((string) file_get_contents(base_path('package-lock.json')), true, 512, JSON_THROW_ON_ERROR);

        // body-parser/express came only from the removed backend workspace.
        $this->assertArrayNotHasKey('node_modules/body-parser', $lock['packages'] ?? []);
        $this->assertArrayNotHasKey('backend', $lock['packages'] ?? []);
        $this->assertNotContains('backend', $root['workspaces'] ?? []);
        $this->assertDirectoryDoesNotExist(base_path('backend'));
    }
}
