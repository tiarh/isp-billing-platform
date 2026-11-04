<?php

namespace App\Services;

use App\Models\Router;
use App\Models\RouterMtSnapshot;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * Plan C / C0 — T0 pulse ingest into router_mt_snapshots (shadow).
 * Does not replace Plan B UI writers unless write_live is enabled.
 */
class MtPlanCPulseService
{
    /**
     * @return array{ingested:int, rejected:int, live_written:int, rows:list<array<string,mixed>>}
     */
    public function ingestFromExporter(?string $url = null, bool $writeLive = false, bool $dryRun = false, ?string $file = null): array
    {
        if (is_string($file) && $file !== '') {
            if (! is_readable($file)) {
                throw new \RuntimeException("metrics file not readable: {$file}");
            }
            $body = (string) file_get_contents($file);
        } else {
            $body = $this->fetchExporterBody($url);
        }
        $parsed = $this->parseExporterMetrics($body);

        return $this->ingestSamples($parsed, 'exporter', $writeLive, $dryRun);
    }

    /**
     * Optional API pulse (count-only) for sample routers — heavier; use sparingly.
     *
     * @param  list<int>|null  $routerIds
     * @return array{ingested:int, rejected:int, live_written:int, rows:list<array<string,mixed>>}
     */
    public function ingestFromApi(?array $routerIds = null, bool $writeLive = false, bool $dryRun = false, int $timeout = 12): array
    {
        $q = Router::query()->where('is_active', true);
        if ($routerIds) {
            $q->whereIn('id', $routerIds);
        }
        $routers = $q->orderBy('id')->get();
        $samples = [];
        $mt = app(MikrotikService::class);

        foreach ($routers as $router) {
            $t0 = microtime(true);
            try {
                $mt->connect($router, ['timeout' => $timeout, 'attempts' => 1]);
                try {
                    $online = $mt->countActiveConnectionsOnly(true);
                    $secret = $mt->getPPPSecretCount();
                } finally {
                    $mt->disconnect();
                }
                $samples[] = [
                    'router_id' => $router->id,
                    'router' => $router->name,
                    'up' => 1,
                    'active' => $online,
                    'secret' => $secret,
                    'scrape_ms' => (int) round((microtime(true) - $t0) * 1000),
                ];
            } catch (\Throwable $e) {
                $samples[] = [
                    'router_id' => $router->id,
                    'router' => $router->name,
                    'up' => 0,
                    'active' => null,
                    'secret' => null,
                    'scrape_ms' => (int) round((microtime(true) - $t0) * 1000),
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $this->ingestSamples($samples, 'api', $writeLive, $dryRun);
    }

    /**
     * C1: decorate router for UI — prefer latest good T0 snapshot when use_snapshot_ui.
     * Appends: mt_pulse_active, mt_pulse_quality, mt_pulse_stale_s, mt_pulse_source, mt_pulse_collected_at
     *
     * Display-only attributes are synced as original so accidental $router->save()
     * (e.g. WireGuard key gen on show) never tries to UPDATE non-column fields
     * like mt_pulse_active (which is NOT a DB column).
     */
    public function decorateRouterForDisplay(Router $router): Router
    {
        $displayKeys = [];
        $markDisplay = function (string $key, mixed $value) use ($router, &$displayKeys): void {
            $router->setAttribute($key, $value);
            $displayKeys[] = $key;
        };

        $markDisplay('mt_pulse_active', false);
        $markDisplay('mt_pulse_quality', null);
        $markDisplay('mt_pulse_stale_s', null);
        $markDisplay('mt_pulse_source', null);
        $markDisplay('mt_pulse_collected_at', null);

        $finish = function () use ($router, &$displayKeys): Router {
            // Prevent dirty display attrs from being written on next save()
            $router->syncOriginalAttributes(array_values(array_unique($displayKeys)));

            return $router;
        };

        if (! (bool) config('services.mt_plan_c.use_snapshot_ui', false)) {
            return $finish();
        }

        if (! Schema::hasTable('router_mt_snapshots')) {
            return $finish();
        }

        $maxStale = max(30, (int) config('services.mt_plan_c.max_stale_seconds', 180));

        $snap = RouterMtSnapshot::query()
            ->where('router_id', $router->id)
            ->where('quality', 'good')
            ->orderByDesc('id')
            ->first();

        if (! $snap) {
            // last non-fail as soft display only if very fresh
            $snap = RouterMtSnapshot::query()
                ->where('router_id', $router->id)
                ->whereIn('quality', ['good', 'suspect'])
                ->orderByDesc('id')
                ->first();
        }

        if (! $snap) {
            return $finish();
        }

        $stale = $snap->staleAgeSeconds();
        $markDisplay('mt_pulse_quality', $snap->quality);
        $markDisplay('mt_pulse_stale_s', $stale);
        $markDisplay('mt_pulse_source', $snap->source);
        $markDisplay('mt_pulse_collected_at', $snap->collected_at?->toIso8601String());

        if ($snap->quality === 'good' && $stale <= $maxStale) {
            $markDisplay('current_online_count', (int) $snap->online_count);
            if ((int) $snap->secret_count > 0) {
                $markDisplay('total_pppoe_count', (int) $snap->secret_count);
            }
            $markDisplay('mt_pulse_active', true);
        }

        return $finish();
    }

    /**
     * @param  Collection<int, Router>|\Illuminate\Database\Eloquent\Collection<int, Router>  $routers
     * @return Collection<int, Router>
     */
    public function decorateRoutersForDisplay($routers)
    {
        return $routers->map(fn (Router $r) => $this->decorateRouterForDisplay($r));
    }

    /**
     * Compare latest good snapshot vs routers table.
     *
     * @return list<array<string,mixed>>
     */
    public function compareLatest(): array
    {
        if (! Schema::hasTable('router_mt_snapshots')) {
            return [];
        }

        $rows = [];
        foreach (Router::query()->where('is_active', true)->orderBy('id')->get() as $router) {
            $snap = RouterMtSnapshot::query()
                ->where('router_id', $router->id)
                ->orderByDesc('id')
                ->first();
            $rows[] = [
                'router_id' => $router->id,
                'name' => $router->name,
                'live_online' => (int) ($router->current_online_count ?? 0),
                'live_secret' => (int) ($router->total_pppoe_count ?? 0),
                'snap_online' => $snap ? (int) $snap->online_count : null,
                'snap_secret' => $snap ? (int) $snap->secret_count : null,
                'quality' => $snap?->quality,
                'source' => $snap?->source,
                'stale_s' => $snap?->staleAgeSeconds(),
                'delta_online' => $snap ? ((int) $snap->online_count - (int) ($router->current_online_count ?? 0)) : null,
                'delta_secret' => $snap ? ((int) $snap->secret_count - (int) ($router->total_pppoe_count ?? 0)) : null,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array<string,mixed>>  $samples
     * @return array{ingested:int, rejected:int, live_written:int, rows:list<array<string,mixed>>}
     */
    public function ingestSamples(array $samples, string $source, bool $writeLive = false, bool $dryRun = false): array
    {
        $ingested = 0;
        $rejected = 0;
        $liveWritten = 0;
        $out = [];

        if (! Schema::hasTable('router_mt_snapshots') && ! $dryRun) {
            throw new \RuntimeException('router_mt_snapshots missing — run migrations');
        }

        foreach ($samples as $row) {
            $router = $this->resolveRouter($row);
            if (! $router) {
                $rejected++;
                $out[] = ['ok' => false, 'reason' => 'unknown_router', 'row' => $row];

                continue;
            }

            $up = (int) ($row['up'] ?? 0);
            $online = isset($row['active']) ? (int) $row['active'] : null;
            $secret = isset($row['secret']) ? (int) $row['secret'] : null;
            $scrapeMs = isset($row['scrape_ms']) ? (int) $row['scrape_ms'] : null;

            if ($up !== 1 || $online === null) {
                $quality = 'fail';
                $message = (string) ($row['error'] ?? 'exporter up=0 or no active');
                if (! $dryRun) {
                    RouterMtSnapshot::query()->create([
                        'router_id' => $router->id,
                        'online_count' => (int) ($router->current_online_count ?? 0),
                        'secret_count' => (int) ($router->total_pppoe_count ?? 0),
                        'tier' => 't0',
                        'source' => $source,
                        'quality' => $quality,
                        'scrape_ms' => $scrapeMs,
                        'message' => mb_substr($message, 0, 250),
                        'collected_at' => now(),
                    ]);
                }
                $rejected++;
                $out[] = ['ok' => false, 'router' => $router->name, 'quality' => $quality, 'message' => $message];

                continue;
            }

            [$quality, $message, $acceptedOnline, $acceptedSecret] = $this->applyGuards(
                $router,
                $online,
                $secret ?? 0
            );

            if ($quality === 'suspect' || $quality === 'fail') {
                // Still store sample for audit, but do not promote to live.
                if (! $dryRun) {
                    RouterMtSnapshot::query()->create([
                        'router_id' => $router->id,
                        'online_count' => $acceptedOnline,
                        'secret_count' => $acceptedSecret,
                        'tier' => 't0',
                        'source' => $source,
                        'quality' => $quality,
                        'scrape_ms' => $scrapeMs,
                        'message' => mb_substr($message, 0, 250),
                        'collected_at' => now(),
                    ]);
                }
                $rejected++;
                $out[] = [
                    'ok' => false,
                    'router' => $router->name,
                    'quality' => $quality,
                    'online' => $acceptedOnline,
                    'secret' => $acceptedSecret,
                    'message' => $message,
                ];

                continue;
            }

            if (! $dryRun) {
                RouterMtSnapshot::query()->create([
                    'router_id' => $router->id,
                    'online_count' => $acceptedOnline,
                    'secret_count' => $acceptedSecret,
                    'tier' => 't0',
                    'source' => $source,
                    'quality' => 'good',
                    'scrape_ms' => $scrapeMs,
                    'message' => null,
                    'collected_at' => now(),
                ]);
                $ingested++;

                // CLI --write-live OR config write_live promotes good pulse → routers.*
                $allowLive = $writeLive || (bool) config('services.mt_plan_c.write_live', false);
                if ($allowLive) {
                    $payload = [
                        'connection_status' => 'online',
                        'current_online_count' => $acceptedOnline,
                        'last_health_check_at' => now(),
                    ];
                    if ($acceptedSecret > 0) {
                        $payload['total_pppoe_count'] = $acceptedSecret;
                    }
                    // Clear stale fullSync "Stream timed out" — Online KPI is healthy via pulse.
                    if (
                        ($router->sync_status ?? '') === 'failed'
                        && $this->isStreamTimeoutMessage((string) ($router->sync_message ?? ''))
                    ) {
                        $payload['sync_status'] = 'success';
                        $payload['sync_message'] = sprintf(
                            'Plan C pulse OK (online=%d secret=%d). Secrets map lag OK to retry.',
                            $acceptedOnline,
                            $acceptedSecret
                        );
                        $payload['sync_finished_at'] = now();
                        $payload['sync_lock_until'] = null;
                    }
                    $router->update($payload);
                    $liveWritten++;
                } else {
                    // Even without write_live: clear false-red "Sync gagal" when pulse is good.
                    $this->clearStaleStreamTimeoutFailure($router, $acceptedOnline, $acceptedSecret);
                }
            } else {
                $ingested++;
            }

            $out[] = [
                'ok' => true,
                'router' => $router->name,
                'quality' => 'good',
                'online' => $acceptedOnline,
                'secret' => $acceptedSecret,
            ];
        }

        // prune old snapshots (keep last 200 per router)
        if (! $dryRun && Schema::hasTable('router_mt_snapshots')) {
            $this->pruneOldSnapshots(200);
        }

        return [
            'ingested' => $ingested,
            'rejected' => $rejected,
            'live_written' => $liveWritten,
            'rows' => $out,
        ];
    }

    /**
     * @return array{0:string,1:string,2:int,3:int} quality, message, online, secret
     */
    protected function applyGuards(Router $router, int $online, int $secret): array
    {
        $prevOnline = (int) ($router->current_online_count ?? 0);
        $prevSecret = (int) ($router->total_pppoe_count ?? 0);

        // Never accept hard zero online when we previously had a large pool online.
        if ($online === 0 && $prevOnline >= 20) {
            return ['suspect', "reject online=0 (prev={$prevOnline})", $prevOnline, $secret > 0 ? $secret : $prevSecret];
        }

        // Under-count vs last live (Plan B / UI truth)
        if ($prevOnline >= 50 && $online > 0 && $online < (int) floor($prevOnline * 0.85)) {
            // Prefer last good snapshot if higher
            $lastGood = RouterMtSnapshot::query()
                ->where('router_id', $router->id)
                ->where('quality', 'good')
                ->orderByDesc('id')
                ->first();
            if ($lastGood && $lastGood->online_count > $online) {
                return [
                    'suspect',
                    "reject under-count online={$online} prev={$prevOnline} snap={$lastGood->online_count}",
                    (int) $lastGood->online_count,
                    $secret > 0 ? $secret : (int) $lastGood->secret_count,
                ];
            }

            return ['suspect', "reject under-count online={$online} prev={$prevOnline}", $prevOnline, $secret > 0 ? $secret : $prevSecret];
        }

        $acceptedSecret = $secret;
        if ($secret > 0 && $prevSecret >= 100 && $secret < (int) floor($prevSecret * 0.85) && $secret <= $prevSecret) {
            $acceptedSecret = $prevSecret;
        }

        return ['good', 'ok', $online, $acceptedSecret];
    }

    protected function resolveRouter(array $row): ?Router
    {
        if (! empty($row['router_id']) && is_numeric($row['router_id'])) {
            $r = Router::query()->find((int) $row['router_id']);
            if ($r) {
                return $r;
            }
        }
        if (! empty($row['router'])) {
            return Router::query()->where('name', $row['router'])->first();
        }

        return null;
    }

    protected function fetchExporterBody(?string $url = null): string
    {
        $urls = array_values(array_filter([
            $url,
            (string) config('services.mktxp.ppp_metrics_url', ''),
            (string) config('services.mt_plan_c.exporter_url', ''),
            ...(array) config('site.ppp_metrics_fallback_urls'),
        ]));

        $last = null;
        foreach ($urls as $u) {
            try {
                $res = Http::timeout(5)->connectTimeout(2)->get($u);
                if ($res->successful() && str_contains($res->body(), 'skynet_ppp_')) {
                    return $res->body();
                }
                $last = 'HTTP '.$res->status().' '.$u;
            } catch (\Throwable $e) {
                $last = $e->getMessage();
            }
        }

        throw new \RuntimeException('exporter fetch failed: '.($last ?? 'no url'));
    }

    /**
     * @return list<array{router:string,router_id:string,up:int,active?:int,secret?:int}>
     */
    public function parseExporterMetrics(string $body): array
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

    protected function pruneOldSnapshots(int $keepPerRouter): void
    {
        $ids = Router::query()->pluck('id');
        foreach ($ids as $routerId) {
            $cutoff = RouterMtSnapshot::query()
                ->where('router_id', $routerId)
                ->orderByDesc('id')
                ->skip($keepPerRouter)
                ->value('id');
            if ($cutoff) {
                RouterMtSnapshot::query()
                    ->where('router_id', $routerId)
                    ->where('id', '<=', $cutoff)
                    ->delete();
            }
        }
    }

    public function isStreamTimeoutMessage(string $message): bool
    {
        $m = strtolower($message);

        return str_contains($m, 'stream timed out')
            || str_contains($m, 'timed out')
            || str_contains($m, 'connection timed out')
            || str_contains($m, 'operation timed out');
    }

    /**
     * UI: "Sync gagal / Stream timed out" while Connected+pulse is misleading — clear it.
     */
    public function clearStaleStreamTimeoutFailure(Router $router, ?int $online = null, ?int $secret = null): bool
    {
        if (($router->sync_status ?? '') !== 'failed') {
            return false;
        }
        if (! $this->isStreamTimeoutMessage((string) ($router->sync_message ?? ''))) {
            return false;
        }

        $online ??= (int) ($router->current_online_count ?? 0);
        $secret ??= (int) ($router->total_pppoe_count ?? 0);

        $router->update([
            'sync_status' => 'success',
            'sync_message' => sprintf(
                'Plan C pulse OK (online=%d secret=%d). Full secrets retry later.',
                $online,
                $secret
            ),
            'sync_finished_at' => now(),
            'sync_lock_until' => null,
            'connection_status' => 'online',
        ]);

        return true;
    }

    /** Clear all stream-timeout failures that have a recent good pulse. */
    public function clearAllStaleStreamTimeouts(): int
    {
        if (! Schema::hasTable('router_mt_snapshots')) {
            return 0;
        }

        $cleared = 0;
        $failed = Router::query()->where('sync_status', 'failed')->get();
        foreach ($failed as $router) {
            if (! $this->isStreamTimeoutMessage((string) ($router->sync_message ?? ''))) {
                continue;
            }
            $snap = RouterMtSnapshot::query()
                ->where('router_id', $router->id)
                ->where('quality', 'good')
                ->where('collected_at', '>=', now()->subMinutes(10))
                ->orderByDesc('id')
                ->first();
            if (! $snap) {
                continue;
            }
            if ($this->clearStaleStreamTimeoutFailure($router, (int) $snap->online_count, (int) $snap->secret_count)) {
                $cleared++;
            }
        }

        return $cleared;
    }
}
