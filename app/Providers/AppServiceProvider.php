<?php

namespace App\Providers;

use App\Contracts\PsbPppoePasswordPolicy;
use App\Contracts\WaProvider;
use App\Models\Customer;
use App\Models\DeviceRecoveryCase;
use App\Models\Invoice;
use App\Models\SupportTicket;
use App\Policies\CustomerPolicy;
use App\Policies\DeviceRecoveryCasePolicy;
use App\Policies\PaymentReversalPolicy;
use App\Policies\SupportTicketPolicy;
use App\Services\EvolutionApiService;
use App\Services\EvolutionBillingWaProvider;
use App\Services\HuaweiAwarePsbOrchestratorService;
use App\Services\OpsAlertService;
use App\Services\PaymentGateway\PaymentGatewayManager;
use App\Services\PsbOrchestratorService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(WaProvider::class, fn ($app): WaProvider => new EvolutionBillingWaProvider(
            $app->make(EvolutionApiService::class),
        ));

        // PPPoE password policy for new PSB secrets; the class comes from config/site.php.
        $this->app->bind(PsbPppoePasswordPolicy::class, fn ($app): PsbPppoePasswordPolicy => $app->make((string) config('site.psb_password_policy')));

        // Additive seam: only the Huawei-aware subclass intercepts Gadang;
        // every other PSB site delegates to the unchanged orchestrator.
        $this->app->bind(PsbOrchestratorService::class, fn ($app): PsbOrchestratorService => $app->make(HuaweiAwarePsbOrchestratorService::class));

        // One manager per container so drivers registered with extend() stick.
        $this->app->singleton(PaymentGatewayManager::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Root-relative asset paths — avoids http/https APP_URL mismatch behind Traefik (CSP blocks cross-scheme assets).
        // Laravel already prefixes the Vite build directory (e.g. "build/assets/..."); do not add "/build/" again.
        if (! $this->app->environment('local')) {
            Vite::createAssetPathsUsing(static fn (string $path, ?bool $secure = null): string => '/'.ltrim($path, '/'));
        }

        $this->configurePublicUrl();

        $this->configureRateLimiting();
        $this->configureSlowQueryLogging();
        $this->configureWorkerHeartbeats();
        $this->configureFailedJobAlerts();

        Gate::policy(Customer::class, CustomerPolicy::class);
        Gate::policy(DeviceRecoveryCase::class, DeviceRecoveryCasePolicy::class);
        Gate::policy(Invoice::class, PaymentReversalPolicy::class);
        Gate::policy(SupportTicket::class, SupportTicketPolicy::class);
    }

    /**
     * PR-ALERT: enforcement jobs (isolate/reconnect) alert the ops group as
     * soon as they fail permanently. Only class + queue are sent; the
     * listener must never break the worker's failure path.
     */
    protected function configureFailedJobAlerts(): void
    {
        Event::listen(JobFailed::class, static function (JobFailed $event): void {
            try {
                app(OpsAlertService::class)->reportImmediateJobFailure(
                    (string) $event->job->resolveName(),
                    $event->job->getQueue(),
                );
            } catch (\Throwable $e) {
                Log::warning('[OpsAlert] immediate failed-job alert skipped', ['exception_class' => $e::class]);
            }
        });
    }

    /**
     * Keep the admission gate's critical-worker evidence fresh even while a
     * queue is idle. This is emitted by the existing queue worker loop; it
     * does not dispatch work, add a worker, or touch customer/device data.
     */
    protected function configureWorkerHeartbeats(): void
    {
        Queue::looping(function (Looping $event): void {
            $queueNames = array_values(array_filter(array_map(
                static fn (string $queue): string => trim($queue),
                explode(',', (string) $event->queue),
            )));
            $stamp = now('Asia/Jakarta')->toIso8601String();
            $ttl = now()->addMinutes(30);
            $keys = (array) config('services.monitoring.admission_gate.worker_heartbeat_keys', []);

            $psbKey = trim((string) ($keys['psb'] ?? ''));
            if ($psbKey !== '' && in_array('psb', $queueNames, true)) {
                Cache::put($psbKey, $stamp, $ttl);
            }

            $migrationKey = trim((string) ($keys['migration'] ?? ''));
            if ($migrationKey !== '' && array_intersect(
                ['migration-command', 'migration-live'],
                $queueNames,
            ) !== []) {
                Cache::put($migrationKey, $stamp, $ttl);
            }

            $ocrKey = trim((string) ($keys['ocr'] ?? ''));
            if ($ocrKey !== '' && in_array('ocr-media', $queueNames, true)) {
                Cache::put($ocrKey, $stamp, $ttl);
            }

            // Only the dedicated enforce worker (queue list is exactly network-enforcement) writes this key.
            // worker-ops also lists the queue first and must not keep it alive.
            $enforceKey = trim((string) ($keys['enforce'] ?? ''));
            if ($enforceKey !== '' && $queueNames === ['network-enforcement']) {
                Cache::put($enforceKey, $stamp, $ttl);
            }
        });
    }

    protected function configurePublicUrl(): void
    {
        $appUrl = (string) config('app.url', '');
        $httpsHosts = config('app.https_hosts', []);
        $requestHost = request()?->getHost();
        $forceHttps = $this->app->environment('production', 'staging')
            || (bool) config('app.force_https')
            || str_starts_with($appUrl, 'https://')
            || ($requestHost && in_array($requestHost, $httpsHosts, true));

        if ($forceHttps) {
            if (str_starts_with($appUrl, 'http://')) {
                config(['app.url' => 'https://'.substr($appUrl, 7)]);
                if ($this->app->environment('production', 'staging')) {
                    Log::warning('APP_URL was http:// — auto-normalized to https://', [
                        'host' => $requestHost,
                    ]);
                }
            }

            URL::forceScheme('https');
            URL::forceRootUrl(rtrim((string) config('app.url'), '/'));

            if (! config('session.secure')) {
                config(['session.secure' => true]);
            }
        } elseif ($this->app->environment('production', 'staging') && str_starts_with($appUrl, 'http://')) {
            Log::error('Production APP_URL masih http:// — risiko mixed-content', [
                'app_url' => $appUrl,
                'host' => $requestHost,
            ]);
        }
    }

    protected function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
        });

        // /storage proxy: anonymous guessing is slow (60/min per IP); staff
        // pages load many thumbnails at once, so a logged-in user gets 600/min.
        RateLimiter::for('storage-proxy', function (Request $request) {
            $user = $request->user();

            return $user
                ? Limit::perMinute(600)->by('storage:u'.$user->getAuthIdentifier())
                : Limit::perMinute(60)->by('storage:ip'.$request->ip());
        });

        RateLimiter::for('global-search', function (Request $request) {
            return Limit::perMinute(60)->by('gsearch:'.($request->user()?->id ?: $request->ip()));
        });

        RateLimiter::for('web', function (Request $request) {
            return Limit::perMinute(300)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('olt-operations', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('mikrotik-operations', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('router-api', function ($job) {
            $routerId = $job->customer->router_id ?? $job->customer->id ?? 'unknown';

            return Limit::perMinute(10)->by('router:'.$routerId);
        });

        // RCA-4 "Cek sekarang": a check is 5 calls (start, 3 probes, finish); the real policies (per user / hour,
        // per customer cool-down, global concurrency) live in DiagnoseNowService.
        RateLimiter::for('diagnose-now', function (Request $request) {
            return Limit::perMinute(60)->by('diagnose:'.($request->user()?->id ?: $request->ip()));
        });

        RateLimiter::for('monitoring-refresh', function (Request $request) {
            return Limit::perMinute(6)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('monitoring-widgets', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('monitoring-router-detail', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
        });

        // Global login brute-force protection: max 20 attempts per IP per hour
        RateLimiter::for('login-global', function (Request $request) {
            return Limit::perMinutes(60, 20)->by($request->ip());
        });

        RateLimiter::for('public-pay', function (Request $request) {
            return Limit::perMinute(20)->by($request->ip());
        });

        // Online payment start / method list. Keyed per IP + invoice because
        // isolated customers behind one router share a NAT IP.
        RateLimiter::for('public-pay-online', function (Request $request) {
            return Limit::perMinute(10)->by('pay-online:'.$request->ip().'|'.(string) $request->route('uuid'));
        });

        // Status polling while a VA/QR is displayed (every few seconds).
        RateLimiter::for('public-pay-status', function (Request $request) {
            return Limit::perMinute(60)->by('pay-status:'.$request->ip().'|'.(string) $request->route('uuid'));
        });

        // Provider callbacks come from a handful of provider IPs; a burst of
        // payments (due date) must not be throttled into provider retries.
        RateLimiter::for('payment-webhook', function (Request $request) {
            return Limit::perMinute(600)->by('payment-webhook:'.$request->ip());
        });

        // Captive/isolir portal: every isolated customer behind one MikroTik is
        // masqueraded to the router's single public IP, and phones re-probe
        // connectivity URLs on every reconnect. A per-IP limit of 10/min (the
        // old value) would 429 the whole router; the page itself is static
        // (cached settings, no customer/MikroTik lookup) so a high cap is safe.
        RateLimiter::for('isolir-portal', function (Request $request) {
            return Limit::perMinute(600)->by('isolir:'.$request->ip());
        });

        RateLimiter::for('customer-export', function (Request $request) {
            return Limit::perMinute(5)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('inventory-export', function (Request $request) {
            return Limit::perMinute(5)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('finance-export', function (Request $request) {
            return Limit::perMinute(5)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('finance-bulk-wa', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });
    }

    protected function configureSlowQueryLogging(): void
    {
        if (! $this->app->environment('production', 'staging')) {
            return;
        }

        $threshold = max(1, (int) config('services.monitoring.slow_query_ms', 250));

        DB::listen(function (QueryExecuted $query) use ($threshold): void {
            if ($query->time < $threshold) {
                return;
            }

            Log::warning('monitoring.slow_query', [
                'connection' => $query->connectionName,
                'time_ms' => round($query->time, 2),
                'threshold_ms' => $threshold,
                'route' => request()?->route()?->getName(),
            ]);
        });
    }
}
