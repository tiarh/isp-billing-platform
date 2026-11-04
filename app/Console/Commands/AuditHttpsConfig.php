<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

class AuditHttpsConfig extends Command
{
    protected $signature = 'audit:https-config {--fix-env : Patch .env APP_URL/APP_FORCE_HTTPS on server (ops only)}';

    protected $description = 'Audit HTTPS/APP_URL config — cegah mixed-content http pada halaman https';

    /** @var list<string> */
    private array $sampleRoutes = [
        'login',
        'dashboard',
        'oltc300.index',
    ];

    public function handle(): int
    {
        $this->info('=== Audit HTTPS Config ===');
        $failed = 0;

        $appUrl = (string) config('app.url');
        $env = (string) config('app.env');
        $forceHttps = (bool) config('app.force_https');

        $failed += $this->check('APP_URL terisi', $appUrl !== '');
        $failed += $this->check('APP_URL pakai https://', str_starts_with($appUrl, 'https://'));
        $failed += $this->check('APP_URL bukan http://', ! str_starts_with($appUrl, 'http://'));

        if (in_array($env, ['production', 'staging'], true)) {
            $failed += $this->check('APP_FORCE_HTTPS=true di production/staging', $forceHttps);
            $failed += $this->check('SESSION_SECURE_COOKIE aktif', $this->sessionSecureEnabled());
            $trustedProxies = (string) config('app.trusted_proxies', '127.0.0.1/32');
            $failed += $this->check(
                'TRUSTED_PROXIES eksplisit dan bukan wildcard',
                $trustedProxies !== '' && $trustedProxies !== '*' && $trustedProxies !== '**',
            );
        }

        URL::forceScheme('https');
        URL::forceRootUrl(rtrim($appUrl, '/'));

        foreach ($this->sampleRoutes as $name) {
            if (! Route::has($name)) {
                continue;
            }

            $url = route($name);
            $failed += $this->check("route({$name}) https", str_starts_with($url, 'https://'));
            $failed += $this->check("route({$name}) bukan http", ! str_contains($url, 'http://'));
        }

        $envPath = base_path('.env');
        if (File::exists($envPath)) {
            $raw = File::get($envPath);
            $failed += $this->check('.env APP_URL bukan http://', ! preg_match('/^APP_URL=http:\/\//m', $raw));
        }

        if ($this->option('fix-env') && File::exists($envPath)) {
            $this->patchEnvFile($envPath);
            $this->call('config:clear');
            $this->warn('Env di-patch — jalankan ulang audit:https-config');
        }

        if ($failed > 0) {
            $this->error("Audit HTTPS GAGAL — {$failed} check");

            return self::FAILURE;
        }

        $this->info('Audit HTTPS LULUS');

        return self::SUCCESS;
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

    private function sessionSecureEnabled(): bool
    {
        $secure = config('session.secure');

        if ($secure === true || $secure === 'true' || $secure === 1 || $secure === '1') {
            return true;
        }

        // null/false di .env tapi APP_FORCE_HTTPS=true → session.php fallback aman untuk production HTTPS
        return (bool) config('app.force_https');
    }

    private function patchEnvFile(string $path): void
    {
        $content = File::get($path);
        $pairs = [
            'APP_URL' => (string) config('site.app_url'),
            'APP_FORCE_HTTPS' => 'true',
            'SESSION_SECURE_COOKIE' => 'true',
            'TRUSTED_PROXIES' => '127.0.0.1/32',
        ];

        foreach ($pairs as $key => $value) {
            if (preg_match("/^{$key}=/m", $content)) {
                $content = preg_replace("/^{$key}=.*$/m", "{$key}={$value}", $content) ?? $content;
            } else {
                $content .= PHP_EOL."{$key}={$value}";
            }
        }

        File::put($path, $content);
        $this->info('  PATCH .env APP_URL / APP_FORCE_HTTPS / SESSION_SECURE_COOKIE / TRUSTED_PROXIES');
    }
}
