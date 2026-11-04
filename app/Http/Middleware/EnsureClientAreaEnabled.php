<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 404 unless the client area is enabled. With the `legal` parameter the
 * request is also allowed on the dedicated client-area host (public pages).
 */
class EnsureClientAreaEnabled
{
    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        $enabled = (bool) config('client_area.enabled');

        if (! $enabled && $mode === 'legal') {
            $host = (string) config('client_area.home_host');
            $enabled = $host !== '' && strtolower($request->getHost()) === $host;
        }

        abort_unless($enabled, 404);

        return $next($request);
    }
}
