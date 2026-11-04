<?php

namespace Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class AuditWaGatewayConfigTest extends TestCase
{
    public function test_audit_wa_gateway_passes_with_faked_evolution(): void
    {
        config([
            'services.evolution_api.base_url' => 'http://evolution.test',
            'services.evolution_api.api_key' => 'test-key',
            'services.evolution_api.instance_name' => 'skynet-ebilling',
        ]);

        Http::fake([
            'http://evolution.test/instance/connectionState/skynet-ebilling' => Http::response([
                'instance' => ['state' => 'close', 'instanceName' => 'skynet-ebilling'],
            ]),
        ]);

        // CI does not run `npm run build`, so public/build is absent there.
        // Fake a built bundle that carries the CSRF recovery hooks (from
        // resources/js/lib/csrf.ts) like the production build does.
        $this->fakeBuiltBundle("addEventListener('inertia:invalid',r);addEventListener('inertia:navigate',s);document.querySelector('meta[name=\"csrf-token\"]')");

        $this->artisan('audit:wa-gateway-config')
            ->assertExitCode(0);
    }

    public function test_audit_wa_gateway_fails_when_build_lacks_csrf_recovery(): void
    {
        config([
            'services.evolution_api.base_url' => 'http://evolution.test',
            'services.evolution_api.api_key' => 'test-key',
            'services.evolution_api.instance_name' => 'skynet-ebilling',
        ]);

        Http::fake([
            'http://evolution.test/instance/connectionState/skynet-ebilling' => Http::response([
                'instance' => ['state' => 'open', 'instanceName' => 'skynet-ebilling'],
            ]),
        ]);

        $this->fakeBuiltBundle('console.log("stale bundle without csrf recovery")');

        $this->artisan('audit:wa-gateway-config')
            ->assertExitCode(1);
    }

    public function test_audit_wa_gateway_fails_when_evolution_unreachable(): void
    {
        config([
            'services.evolution_api.base_url' => 'http://evolution.test',
            'services.evolution_api.api_key' => 'test-key',
            'services.evolution_api.instance_name' => 'skynet-ebilling',
        ]);

        Http::fake(function () {
            throw new RuntimeException('connection refused');
        });

        $this->artisan('audit:wa-gateway-config')
            ->assertExitCode(1);
    }

    private function fakeBuiltBundle(string $source): void
    {
        $buildDir = public_path('build/assets');
        $bundle = $buildDir.'/app-ci-fixture.js';
        $real = new Filesystem;

        File::partialMock()
            ->shouldReceive('isDirectory')
            ->andReturnUsing(fn (string $path): bool => $path === $buildDir || $real->isDirectory($path));
        File::shouldReceive('glob')
            ->andReturnUsing(fn (string $pattern, int $flags = 0): array => $pattern === $buildDir.'/app-*.js' ? [$bundle] : $real->glob($pattern, $flags));
        File::shouldReceive('get')
            ->andReturnUsing(fn (string $path, bool $lock = false): string => $path === $bundle ? $source : $real->get($path, $lock));
    }
}
