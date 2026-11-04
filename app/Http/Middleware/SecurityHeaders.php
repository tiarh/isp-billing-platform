<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-XSS-Protection', '1; mode=block');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        if (app()->environment('production', 'staging') && $request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        $assetOrigins = $this->expandedAssetOrigins($request);

        $defaultSrc = $this->joinSources("'self'", ...$assetOrigins);

        // 'unsafe-eval' required by Zod 4 (validation-*.js feature-detects via new Function("")).
        // Without it, production CSP logs "blocks the use of eval" and may break form validation.
        $scriptSrc = $this->joinSources(
            "'self'",
            "'unsafe-inline'",
            "'unsafe-eval'",
            ...$assetOrigins,
        );

        if (app()->environment('local')) {
            $viteOrigin = $this->viteDevOrigin();
            if ($viteOrigin) {
                $scriptSrc = $this->joinSources($scriptSrc, $viteOrigin);
            }
        }

        $styleSrc = $this->joinSources(
            "'self'",
            "'unsafe-inline'",
            'https://fonts.bunny.net',
            ...$assetOrigins,
        );

        $connectSrc = $this->joinSources(
            "'self'",
            'https://*.tile.openstreetmap.org',
            'https://server.arcgisonline.com',
            ...$assetOrigins,
        );

        if (app()->environment('local')) {
            $viteOrigin = $this->viteDevOrigin();
            if ($viteOrigin) {
                $wsOrigin = preg_replace('#^http#', 'ws', $viteOrigin) ?? $viteOrigin;
                $connectSrc = $this->joinSources($connectSrc, $viteOrigin, $wsOrigin);
            }
        }

        // Extra image hosts of this deployment (config/site.php).
        $extraImgHosts = array_values(array_filter((array) config('site.csp_extra_hosts'), 'is_string'));

        $imgSrc = $this->joinSources(...[
            "'self'",
            'data:',
            'blob:',
            'https://tile.openstreetmap.org',
            'https://*.tile.openstreetmap.org',
            'https://server.arcgisonline.com',
            // Peta dark mode: CARTO dark_all (keyless), subdomains a-d.
            'https://*.basemaps.cartocdn.com',
            ...$extraImgHosts,
            // Duitku payment-method logos (paymentImage, e.g. images.duitku.com/hotlink-ok/BNI.PNG).
            // Single exact host, images only; no wildcard and no other directive widened.
            'https://images.duitku.com',
            ...$assetOrigins,
        ]);

        $directives = [
            "default-src {$defaultSrc}",
            "script-src {$scriptSrc}",
            "script-src-elem {$scriptSrc}",
            "style-src {$styleSrc}",
            "style-src-elem {$styleSrc}",
            "img-src {$imgSrc}",
            "font-src 'self' data: https://fonts.bunny.net",
            "connect-src {$connectSrc}",
            "worker-src 'self' blob:",
            "frame-ancestors 'self'",
        ];

        $response->headers->set('Content-Security-Policy', implode('; ', $directives));

        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(self), payment=()');
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');

        return $response;
    }

    /**
     * Origins that may host built Vite assets when Laravel emits absolute URLs (APP_URL / ASSET_URL).
     *
     * @return list<string>
     */
    /**
     * @return list<string>
     */
    private function assetOrigins(Request $request): array
    {
        $origins = array_filter([
            $this->originFromUrl((string) config('app.url')),
            $this->originFromUrl((string) config('app.csp_asset_url', '')),
            $request->getSchemeAndHttpHost(),
        ]);

        return array_values(array_unique($origins));
    }

    /**
     * Include http+https variants — production often emits http:// assets while the browser is on https:// (Traefik).
     *
     * @return list<string>
     */
    private function expandedAssetOrigins(Request $request): array
    {
        $expanded = [];

        foreach ($this->assetOrigins($request) as $origin) {
            $expanded[] = $origin;

            if (str_starts_with($origin, 'https://')) {
                $expanded[] = 'http://'.substr($origin, 8);
            } elseif (str_starts_with($origin, 'http://')) {
                $expanded[] = 'https://'.substr($origin, 7);
            }
        }

        return array_values(array_unique($expanded));
    }

    private function originFromUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return strtolower($parts['scheme']).'://'.$parts['host'].$port;
    }

    private function viteDevOrigin(): ?string
    {
        $explicit = trim((string) config('app.vite_dev.url', ''));
        if ($explicit !== '') {
            return $this->originFromUrl($explicit);
        }

        $host = trim((string) config('app.vite_dev.host', 'localhost'));
        $port = (int) config('app.vite_dev.port', 5173);

        return $this->originFromUrl("http://{$host}:{$port}");
    }

    private function joinSources(string ...$sources): string
    {
        return implode(' ', array_values(array_unique(array_filter($sources))));
    }
}
