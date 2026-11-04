<?php

namespace App\Http\Middleware;

use App\Services\AttendanceExportService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class VerifyAttendanceExportToken
{
    public function handle(Request $request, Closure $next): Response
    {
        if ((string) config('services.attendance_export.token', '') === '') {
            return response()->json(['error' => 'Attendance export token not configured'], 503);
        }

        if ($this->hasTokenInQuery($request)) {
            Log::warning('Attendance export rejected: token must not be sent in query string', [
                'ip' => $request->ip(),
            ]);

            return response()->json(['error' => 'Unauthorized'], 401);
        }

        if ($this->requiresHttps() && ! $this->isSecureRequest($request)) {
            Log::warning('Attendance export rejected: HTTPS required', [
                'ip' => $request->ip(),
            ]);

            return response()->json(['error' => 'HTTPS required'], 403);
        }

        if (! $this->ipAllowed($request->ip())) {
            Log::warning('Attendance export rejected: IP not allowed', [
                'ip' => $request->ip(),
            ]);

            return response()->json(['error' => 'Forbidden'], 403);
        }

        $failKey = 'attendance-export:fail:'.$request->ip();
        if (RateLimiter::tooManyAttempts($failKey, $this->maxFailures())) {
            return response()->json(['error' => 'Too many failed attempts'], 429);
        }

        if (! app(AttendanceExportService::class)->verifyToken($request)) {
            RateLimiter::hit($failKey, $this->decaySeconds());
            Log::warning('Attendance export rejected: invalid or missing token', [
                'ip' => $request->ip(),
            ]);

            return response()->json(['error' => 'Unauthorized'], 401);
        }

        RateLimiter::clear($failKey);

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    protected function hasTokenInQuery(Request $request): bool
    {
        foreach (['token', 'api_key', 'apikey', 'access_token', 'attendance_token'] as $key) {
            if ($request->query($key)) {
                return true;
            }
        }

        return false;
    }

    protected function requiresHttps(): bool
    {
        if (! app()->environment('production', 'staging')) {
            return false;
        }

        return (bool) config('services.attendance_export.require_https', true);
    }

    protected function isSecureRequest(Request $request): bool
    {
        if ($request->isSecure()) {
            return true;
        }

        $forwarded = strtolower((string) $request->header('X-Forwarded-Proto', ''));

        return $forwarded === 'https'
            || strtolower((string) $request->header('X-Forwarded-Ssl', '')) === 'on';
    }

    protected function ipAllowed(?string $ip): bool
    {
        $allowed = array_values(array_filter(array_map(
            static fn (string $ip): string => trim($ip),
            explode(',', (string) config('services.attendance_export.allowed_ips', ''))
        )));

        if ($allowed === []) {
            return true;
        }

        return $ip !== null && in_array($ip, $allowed, true);
    }

    protected function maxFailures(): int
    {
        return (int) config('services.attendance_export.max_failures', 20);
    }

    protected function decaySeconds(): int
    {
        return (int) config('services.attendance_export.failure_decay_seconds', 300);
    }
}
