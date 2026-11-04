<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

final class SafeRemoteSnapshotDownloader
{
    public function download(string $url): ?string
    {
        $candidates = $this->candidates($url);
        if ($candidates === []) {
            return null;
        }

        $maxBytes = (int) config('services.migration_snapshot.max_bytes', 20 * 1024 * 1024);
        $timeout = (int) config('services.migration_snapshot.timeout', 30);

        foreach ($candidates as $candidate) {
            try {
                $response = Http::timeout($timeout)
                    ->connectTimeout(min(8, $timeout))
                    ->withoutRedirecting()
                    ->withHeaders(['User-Agent' => 'skynet-ebilling'])
                    ->get($candidate);

                $contentLength = (int) ($response->header('Content-Length') ?? 0);
                if (! $response->successful() || ($contentLength > 0 && $contentLength > $maxBytes)) {
                    continue;
                }

                $body = $response->body();
                if (strlen($body) > $maxBytes || strlen($body) <= 100) {
                    continue;
                }

                $prefix = substr($body, 0, 500);
                if (str_starts_with($body, 'PK') || str_contains($prefix, ',')) {
                    return $body;
                }
            } catch (\Throwable) {
                // URL import is optional; never expose transport/provider details to the UI.
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function candidates(string $url): array
    {
        $parts = parse_url(trim($url));
        if (! is_array($parts)) {
            return [];
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $allowedHosts = (array) config('services.migration_snapshot.allowed_hosts', []);

        if ($scheme !== 'https'
            || $host === ''
            || array_key_exists('user', $parts)
            || array_key_exists('pass', $parts)
            || (($parts['port'] ?? 443) !== 443)
            || ! in_array($host, $allowedHosts, true)
            || $this->isPrivateIpLiteral($host)
            || in_array($host, ['localhost', 'localhost.localdomain'], true)) {
            return [];
        }

        $normalized = rtrim(trim($url), '/');
        $candidates = [$normalized];
        if (str_contains($normalized, '/share/')) {
            $candidates[] = $normalized.'/download';
        }

        return array_values(array_unique($candidates));
    }

    private function isPrivateIpLiteral(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
}
