<?php

use App\Exceptions\LifecycleBackstopException;
use App\Http\Middleware\AllowOltC300Api;
use App\Http\Middleware\CheckOltProvisioningApproval;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureClientAreaEnabled;
use App\Http\Middleware\EnsureDeviceRecoveryWritable;
use App\Http\Middleware\EnsureGlobalAdmin;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RedactOltJsonResponse;
use App\Http\Middleware\RequireControlPlaneTotp;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\VerifyAttendanceExportToken;
use App\Services\OpsAlertService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Default to the local reverse proxy until VM-102's actual proxy CIDR is verified.
        // Never treat an empty or wildcard value as "trust every proxy".
        $trusted = trim((string) env('TRUSTED_PROXIES', '127.0.0.1/32'));
        $trustedProxies = array_values(array_filter(
            array_map('trim', explode(',', $trusted)),
            static fn (string $proxy): bool => $proxy !== '' && $proxy !== '*' && $proxy !== '**',
        ));
        $middleware->trustProxies(at: $trustedProxies);

        $middleware->alias([
            'admin' => EnsureAdmin::class,
            'global-admin' => EnsureGlobalAdmin::class,
            'superadmin' => EnsureSuperAdmin::class,
            'device-recovery.writable' => EnsureDeviceRecoveryWritable::class,
            'allow-olt-api' => AllowOltC300Api::class,
            'olt-provisioning' => CheckOltProvisioningApproval::class,
            'attendance-export' => VerifyAttendanceExportToken::class,
            'control-plane-totp' => RequireControlPlaneTotp::class,
            'olt-safe-output' => RedactOltJsonResponse::class,
            'client-area.enabled' => EnsureClientAreaEnabled::class,
        ]);

        // Prepend the OLT API rate-limit middleware to the web group.
        // Route middleware remains responsible for authentication and authorization.
        $middleware->web(prepend: [
            AllowOltC300Api::class,
        ]);

        $middleware->web(append: [
            SecurityHeaders::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // CSRF: only true machine webhooks (shared secret). Session SPA uses X-CSRF-TOKEN.
        $middleware->validateCsrfTokens(except: [
            'webhooks/payment-gateway/*',
            'webhooks/genieacs/*',
            'webhooks/attendance/*',
            'webhooks/evolution/*', // alias path used by Evolution config
            'api/internal/monitoring/traffic/*',
        ]);

        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->reportable(function (Throwable $e): void {
            try {
                app(OpsAlertService::class)->reportException($e);
            } catch (Throwable) {
                // never break the exception pipeline
            }
        });

        $exceptions->renderable(function (Throwable $e, Request $request) {
            // Lifecycle backstop (enforce mode) renders its own T9 message.
            if ($e instanceof LifecycleBackstopException) {
                return null;
            }

            // Handle API routes — return JSON instead of HTML redirect
            if ($request->expectsJson() || str_starts_with($request->path(), 'api/')) {
                if ($e instanceof ValidationException ||
                    $e instanceof AuthenticationException ||
                    $e instanceof HttpResponseException ||
                    $e instanceof HttpExceptionInterface) {
                    return null;
                }

                Log::error('API Exception', [
                    'exception_class' => $e::class,
                    'file' => basename($e->getFile()),
                    'line' => $e->getLine(),
                ]);

                return response()->json([
                    'error' => 'Server error',
                    'message' => 'A server error occurred.',
                ], 500);
            }

            if ($request->header('X-Inertia')) {
                if (! $e instanceof ValidationException &&
                    ! $e instanceof AuthenticationException &&
                    ! $e instanceof HttpResponseException &&
                    ! $e instanceof HttpExceptionInterface) {

                    $message = 'A server error occurred.';

                    return back()->with('error', $message);
                }
            }
        });
    })->create();
