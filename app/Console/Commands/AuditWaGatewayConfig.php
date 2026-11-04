<?php

namespace App\Console\Commands;

use App\Services\EvolutionApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

class AuditWaGatewayConfig extends Command
{
    protected $signature = 'audit:wa-gateway-config';

    protected $description = 'Audit WA gateway + CSRF regression — cegah broadcast/QR gagal setelah redeploy';

    public function handle(): int
    {
        $this->info('=== Audit WA Gateway + CSRF ===');
        $failed = 0;

        $baseUrl = (string) config('services.evolution_api.base_url');
        $apiKey = (string) config('services.evolution_api.api_key');
        $instance = (string) config('services.evolution_api.instance_name');

        $failed += $this->check('EVOLUTION_API_BASE_URL terisi', $baseUrl !== '');
        $failed += $this->check('EVOLUTION_API_API_KEY terisi', $apiKey !== '');
        $failed += $this->check('EVOLUTION instance name terisi', $instance !== '');

        $failed += $this->checkCsrfGuards();
        $failed += $this->checkQrNormalizationGuard();
        $failed += $this->checkBroadcastRoutes();

        if ($baseUrl !== '' && $apiKey !== '') {
            $failed += $this->checkEvolutionReachable($baseUrl, $apiKey, $instance);
        }

        $evo = app(EvolutionApiService::class);
        $failed += $this->check('EvolutionApiService configured', $evo->isConfigured());

        if ($failed > 0) {
            $this->error("Audit WA Gateway GAGAL — {$failed} check");

            return self::FAILURE;
        }

        $this->info('Audit WA Gateway LULUS');

        return self::SUCCESS;
    }

    private function checkCsrfGuards(): int
    {
        $failed = 0;

        $inertia = app_path('Http/Middleware/HandleInertiaRequests.php');
        $failed += $this->check(
            'Inertia share csrf_token',
            File::exists($inertia) && str_contains(File::get($inertia), "'csrf_token'")
        );

        $blade = resource_path('views/app.blade.php');
        $failed += $this->check(
            'app.blade.php meta csrf-token',
            File::exists($blade) && str_contains(File::get($blade), 'name="csrf-token"')
        );

        $provider = app_path('Providers/AppServiceProvider.php');
        $failed += $this->check(
            'AppServiceProvider force HTTPS + session.secure',
            File::exists($provider)
            && str_contains(File::get($provider), 'forceScheme')
            && str_contains(File::get($provider), 'session.secure')
        );

        $buildDir = public_path('build/assets');
        $hasCsrfRecovery = false;
        if (File::isDirectory($buildDir)) {
            foreach (File::glob($buildDir.'/app-*.js') ?: [] as $bundle) {
                $bundleSource = File::get($bundle);
                if (str_contains($bundleSource, 'inertia:invalid')
                    && str_contains($bundleSource, 'inertia:navigate')
                    && str_contains($bundleSource, 'meta[name="csrf-token"]')) {
                    $hasCsrfRecovery = true;
                    break;
                }
            }
        }
        $failed += $this->check('Frontend build punya CSRF recovery (inertia:invalid)', $hasCsrfRecovery);

        return $failed;
    }

    private function checkQrNormalizationGuard(): int
    {
        $serviceFile = app_path('Services/EvolutionApiService.php');
        if (! File::exists($serviceFile)) {
            return $this->check('EvolutionApiService normalizeBase64Image', false);
        }

        $source = File::get($serviceFile);
        if (! str_contains($source, 'normalizeBase64Image')) {
            return $this->check('EvolutionApiService normalizeBase64Image', false);
        }

        try {
            $method = new \ReflectionMethod(EvolutionApiService::class, 'normalizeBase64Image');
            $method->setAccessible(true);
            $normalized = $method->invoke(
                app(EvolutionApiService::class),
                'data:image/png;base64,abc123'
            );

            return $this->check('QR base64 dinormalisasi (tanpa data: prefix)', $normalized === 'abc123');
        } catch (\Throwable) {
            return $this->check('QR base64 dinormalisasi (tanpa data: prefix)', false);
        }
    }

    private function checkBroadcastRoutes(): int
    {
        $failed = 0;
        $failed += $this->check('route broadcasts.store ada', Route::has('broadcasts.store'));
        $failed += $this->check('route broadcasts.retry ada', Route::has('broadcasts.retry'));
        $failed += $this->check('route api.wa-gateway.qr-code ada', Route::has('api.wa-gateway.qr-code'));

        return $failed;
    }

    private function checkEvolutionReachable(string $baseUrl, string $apiKey, string $instance): int
    {
        try {
            $response = Http::timeout(10)
                ->withHeaders(['apikey' => $apiKey])
                ->get(rtrim($baseUrl, '/')."/instance/connectionState/{$instance}");

            if (! $response->successful()) {
                return $this->check("Evolution API HTTP {$response->status()}", false);
            }

            $state = $response->json('instance.state');
            $validStates = ['open', 'close', 'connecting', 'qr', 'refused'];

            return $this->check('Evolution API reachable dari container ini', true)
                + $this->check(
                    'Evolution connectionState valid',
                    is_string($state) && $state !== '' && in_array($state, $validStates, true)
                );
        } catch (\Throwable $e) {
            return $this->check('Evolution API reachable dari container ini', false);
        }
    }

    private function check(string $label, bool $ok): int
    {
        if ($ok) {
            $this->line("  OK  {$label}");

            return 0;
        }

        $this->error("  FAIL {$label}");

        return 1;
    }
}
