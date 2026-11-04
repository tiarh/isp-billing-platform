<?php

namespace Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class AuditHttpsConfigTest extends TestCase
{
    public function test_audit_https_config_passes_with_https_app_url(): void
    {
        config([
            'app.url' => 'https://ebilling.example.test',
            'app.force_https' => true,
            'app.env' => 'production',
            'session.secure' => true,
        ]);

        URL::forceScheme('https');
        URL::forceRootUrl('https://ebilling.example.test');

        // The command also reads the raw base_path('.env'). In CI that file is
        // a copy of .env.example (APP_URL=http://localhost), so present a
        // production-shaped .env instead of depending on the runner's file.
        $this->fakeEnvFile("APP_URL=https://ebilling.example.test\nAPP_FORCE_HTTPS=true\n");

        $this->artisan('audit:https-config')
            ->assertSuccessful();
    }

    public function test_audit_https_config_fails_when_app_url_is_http_in_production(): void
    {
        config([
            'app.url' => 'http://ebilling.example.test',
            'app.force_https' => false,
            'app.env' => 'production',
            'session.secure' => false,
        ]);

        $this->artisan('audit:https-config')
            ->assertFailed();
    }

    public function test_audit_https_config_fails_when_env_file_still_has_http_app_url(): void
    {
        config([
            'app.url' => 'https://ebilling.example.test',
            'app.force_https' => true,
            'app.env' => 'production',
            'session.secure' => true,
        ]);

        $this->fakeEnvFile("APP_URL=http://ebilling.example.test\n");

        $this->artisan('audit:https-config')
            ->assertFailed();
    }

    private function fakeEnvFile(string $contents): void
    {
        $envPath = base_path('.env');
        $real = new Filesystem;

        File::partialMock()
            ->shouldReceive('exists')
            ->andReturnUsing(fn (string $path): bool => $path === $envPath || $real->exists($path));
        File::shouldReceive('get')
            ->andReturnUsing(fn (string $path, bool $lock = false): string => $path === $envPath ? $contents : $real->get($path, $lock));
    }
}
