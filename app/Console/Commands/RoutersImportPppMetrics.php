<?php

namespace App\Console\Commands;

use App\Models\Router;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Import PPPoE Online/Secret from skynet-ppp-exporter (count-only, light).
 * Does not hit MikroTik directly — safe for fleet schedule.
 */
class RoutersImportPppMetrics extends Command
{
    protected $signature = 'routers:import-ppp-metrics
                            {--url= : Metrics URL (default services.mktxp.ppp_metrics_url)}
                            {--file= : Read Prometheus text from file (use with host cron when Docker cannot reach host ports)}
                            {--dry-run : Parse only, no DB write}';

    protected $description = 'Import Online/Secret from local mktxp PPP count exporter (no MT API)';

    public function handle(): int
    {
        $body = null;
        $file = $this->option('file');
        if (is_string($file) && $file !== '') {
            if (! is_readable($file)) {
                $this->error("file not readable: {$file}");

                return self::FAILURE;
            }
            $body = (string) file_get_contents($file);
        } else {
            $urls = array_values(array_filter([
                $this->option('url') ?: null,
                (string) config('services.mktxp.ppp_metrics_url', ''),
                ...(array) config('site.ppp_metrics_fallback_urls'),
            ]));

            $lastErr = null;
            foreach ($urls as $url) {
                try {
                    $res = Http::timeout(5)->connectTimeout(2)->get($url);
                    if ($res->successful() && str_contains($res->body(), 'skynet_ppp_')) {
                        $body = $res->body();
                        $this->line("fetched {$url}");
                        break;
                    }
                    $lastErr = 'HTTP '.$res->status().' '.$url;
                } catch (\Throwable $e) {
                    $lastErr = $e->getMessage();
                }
            }
            if ($body === null) {
                $this->error('fetch fail: '.($lastErr ?? 'no url'));

                return self::FAILURE;
            }
        }

        $parsed = $this->parseMetrics($body);
        if ($parsed === []) {
            $this->warn('no skynet_ppp_* samples');

            return self::SUCCESS;
        }

        $dry = (bool) $this->option('dry-run');
        $updated = 0;

        foreach ($parsed as $row) {
            $router = null;
            if (! empty($row['router_id']) && is_numeric($row['router_id'])) {
                $router = Router::query()->find((int) $row['router_id']);
            }
            if (! $router && ! empty($row['router'])) {
                $router = Router::query()->where('name', $row['router'])->first();
            }
            if (! $router) {
                $this->line("skip unknown {$row['router']} id={$row['router_id']}");

                continue;
            }

            $line = sprintf(
                '%s up=%d active=%s secret=%s',
                $router->name,
                $row['up'],
                $row['active'] ?? '-',
                $row['secret'] ?? '-'
            );

            if ($row['up'] !== 1) {
                $this->warn("  FAIL keep last-known: {$line}");
                // Soft: mark health offline? Keep online counts (no fake 0).
                if (! $dry) {
                    $router->update(['last_health_check_at' => now()]);
                }

                continue;
            }

            $payload = [
                'connection_status' => 'online',
                'last_health_check_at' => now(),
            ];
            if (isset($row['active']) && $row['active'] >= 0) {
                $payload['current_online_count'] = (int) $row['active'];
            }
            if (isset($row['secret']) && $row['secret'] > 0) {
                $prev = (int) ($router->total_pppoe_count ?? 0);
                $sec = (int) $row['secret'];
                // Avoid under-writing secrets from flaky count (same guard as LIVE)
                if ($prev < 100 || $sec >= (int) floor($prev * 0.85) || $sec > $prev) {
                    $payload['total_pppoe_count'] = $sec;
                }
            }

            $this->info(($dry ? 'DRY ' : 'OK ').$line);
            if (! $dry) {
                $router->update($payload);
                $updated++;
            }
        }

        $this->info("import done updated={$updated}");

        return self::SUCCESS;
    }

    /**
     * @return list<array{router:string,router_id:string,up:int,active?:int,secret?:int}>
     */
    protected function parseMetrics(string $body): array
    {
        $by = [];
        foreach (preg_split('/\r\n|\r|\n/', $body) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (! preg_match('/^(skynet_ppp_(?:active|secret|up))\{([^}]*)\}\s+([0-9.eE+-]+)$/', $line, $m)) {
                continue;
            }
            $metric = $m[1];
            $labels = $m[2];
            $val = (int) round((float) $m[3]);
            $router = $this->label($labels, 'router') ?? '';
            $routerId = $this->label($labels, 'router_id') ?? '';
            $key = $router !== '' ? $router : $routerId;
            if ($key === '') {
                continue;
            }
            $by[$key] ??= ['router' => $router, 'router_id' => $routerId, 'up' => 0];
            if ($metric === 'skynet_ppp_active') {
                $by[$key]['active'] = $val;
            } elseif ($metric === 'skynet_ppp_secret') {
                $by[$key]['secret'] = $val;
            } elseif ($metric === 'skynet_ppp_up') {
                $by[$key]['up'] = $val;
            }
        }

        return array_values($by);
    }

    protected function label(string $labels, string $name): ?string
    {
        if (preg_match('/\b'.preg_quote($name, '/').'="([^"]*)"/', $labels, $m)) {
            return $m[1];
        }

        return null;
    }
}
