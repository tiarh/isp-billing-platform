<?php

namespace App\Console\Commands;

use App\Models\Router;
use App\Services\RouterSyncService;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Plan B LIVE tier: light PPPoE online count for all active routers.
 * Parallel workers (default 4) so fleet of ~14 finishes under ~60s without hammering each MT.
 */
class RoutersLiveHealth extends Command
{
    protected $signature = 'routers:live-health
                            {--router_id= : Single router id}
                            {--concurrency=4 : Parallel workers (fleet mode)}
                            {--timeout=10 : API timeout per router}
                            {--only-active : Only is_active=1 routers}
                            {--no-customer-flags : Skip is_online customer updates}';

    protected $description = 'LIVE MikroTik health: active count-only + resource (no secrets pull)';

    public function handle(RouterSyncService $sync): int
    {
        $routerId = $this->option('router_id');
        $timeout = max(5, (int) $this->option('timeout'));
        $syncFlags = ! $this->option('no-customer-flags');

        // Production UI lists all routers; is_active was historically false for all 14.
        // Default: every router row. --only-active restricts when flags are maintained.
        $base = Router::query()->orderBy('id');
        if ($this->option('only-active')) {
            $base->where('is_active', true);
        }

        if ($routerId) {
            $router = (clone $base)->find($routerId);
            if (! $router) {
                $this->error("Router #{$routerId} not found");

                return self::FAILURE;
            }

            $r = $sync->syncLiveHealth($router, $timeout, $syncFlags);
            $this->line(($r['success'] ? 'OK' : 'FAIL')." {$router->name}: {$r['message']}");

            return $r['success'] ? self::SUCCESS : self::FAILURE;
        }

        $ids = (clone $base)->pluck('id')->all();
        if ($ids === []) {
            $this->warn('No routers');

            return self::SUCCESS;
        }

        $concurrency = max(1, min(8, (int) $this->option('concurrency')));
        $this->info('LIVE health fleet='.count($ids)." concurrency={$concurrency} timeout={$timeout}s");

        $t0 = microtime(true);
        $ok = 0;
        $fail = 0;
        $rows = [];

        // Worker pool via subprocess so one slow router does not block the fleet.
        $queue = $ids;
        /** @var array<int, array{process: Process, id: int}> $running */
        $running = [];

        $startWorker = function (int $id) use ($timeout, $syncFlags): Process {
            $cmd = [
                PHP_BINARY,
                base_path('artisan'),
                'routers:live-health',
                '--router_id='.$id,
                '--timeout='.$timeout,
            ];
            if (! $syncFlags) {
                $cmd[] = '--no-customer-flags';
            }
            $p = new Process($cmd, base_path());
            $p->setTimeout($timeout + 15);
            $p->start();

            return $p;
        };

        while ($queue !== [] || $running !== []) {
            while (count($running) < $concurrency && $queue !== []) {
                $id = array_shift($queue);
                $running[$id] = ['process' => $startWorker($id), 'id' => $id];
            }

            foreach ($running as $id => $meta) {
                $p = $meta['process'];
                if ($p->isRunning()) {
                    continue;
                }
                $out = trim($p->getOutput().$p->getErrorOutput());
                $success = $p->isSuccessful() && str_starts_with($out, 'OK');
                if ($success) {
                    $ok++;
                } else {
                    $fail++;
                }
                $name = Router::query()->where('id', $id)->value('name') ?? "#{$id}";
                $rows[] = [$name, $success ? 'ok' : 'fail', $out];
                unset($running[$id]);
            }

            if ($running !== []) {
                usleep(80_000);
            }
        }

        $ms = (int) round((microtime(true) - $t0) * 1000);
        $this->table(['Router', 'Status', 'Detail'], $rows);
        $this->info("LIVE fleet done ok={$ok} fail={$fail} wall_ms={$ms}");

        return $fail > 0 && $ok === 0 ? self::FAILURE : self::SUCCESS;
    }
}
