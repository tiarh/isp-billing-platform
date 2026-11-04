<?php

namespace App\Console\Commands;

use App\Models\PppoeAddressPoolBlock;
use App\Models\Router;
use App\Services\MikrotikService;
use App\Services\PppoeAddressPoolAllocator;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class PppoeBlocksReconcile extends Command
{
    protected $signature = 'pppoe:blocks-reconcile
        {--router_id= : Reconcile one router id only}
        {--only-active : Only active routers}
        {--timeout=18 : MikroTik read timeout in seconds}
        {--limit=0 : Limit router count}
        {--dry-run : Read and report without saving counters}';

    protected $description = 'Read-only reconcile PPPoE static address pool counters from MikroTik secrets';

    public function handle(PppoeAddressPoolAllocator $allocator, MikrotikService $mikrotik): int
    {
        if (! Schema::hasTable('pppoe_address_pool_blocks')) {
            $this->warn('Table pppoe_address_pool_blocks belum ada. Skip.');

            return self::SUCCESS;
        }

        $lock = Cache::lock('pppoe-blocks-reconcile', 20 * 60);
        if (! $lock->get()) {
            $this->warn('pppoe:blocks-reconcile masih berjalan. Skip.');

            return self::SUCCESS;
        }

        try {
            $routers = $this->routers();
            $timeout = max(3, (int) $this->option('timeout'));
            $dryRun = (bool) $this->option('dry-run');
            $summary = ['routers' => 0, 'blocks' => 0, 'warning' => 0, 'full' => 0, 'failed' => 0];

            foreach ($routers as $router) {
                $summary['routers']++;
                $this->line(($dryRun ? '[dry-run] ' : '')."Router {$router->id} {$router->name}");

                try {
                    $secrets = $mikrotik->connect($router, ['timeout' => $timeout, 'attempts' => 1])
                        ->getPPPSecretsWithAddresses();
                    $rows = $allocator->reconcileBlocks($router, $secrets, $dryRun);
                } catch (Throwable $e) {
                    $summary['failed']++;
                    $this->warn("  timeout/error: {$e->getMessage()}");
                    Log::warning('PPPoE block reconcile router failed', [
                        'router_id' => $router->id,
                        'router' => $router->name,
                        'error' => $e->getMessage(),
                    ]);

                    continue;
                } finally {
                    $mikrotik->disconnect();
                }

                foreach ($rows as $row) {
                    $summary['blocks']++;
                    $status = (string) ($row['status'] ?? 'unknown');
                    if ($status === 'warning') {
                        $summary['warning']++;
                    }
                    if ($status === 'full') {
                        $summary['full']++;
                    }
                    if (in_array($status, ['warning', 'full'], true)) {
                        Log::warning('PPPoE block capacity alert', [
                            'router_id' => $router->id,
                            'router' => $router->name,
                            'block_id' => $row['block_id'] ?? null,
                            'block_name' => $row['block_name'] ?? null,
                            'available_count' => $row['available_count'] ?? null,
                            'warning_threshold' => $row['warning_threshold'] ?? null,
                            'status' => $status,
                        ]);
                    }

                    $this->line(sprintf(
                        '  %-18s %s-%s used=%s avail=%s last=%s source=%s status=%s',
                        (string) ($row['block_name'] ?? 'block'),
                        (string) ($row['remote_start'] ?? '-'),
                        (string) ($row['remote_end'] ?? '-'),
                        (string) ($row['used_count'] ?? '-'),
                        (string) ($row['available_count'] ?? '-'),
                        (string) ($row['last_remote_address'] ?? '-'),
                        (string) ($row['last_remote_source'] ?? '-'),
                        $status
                    ));
                }
            }

            $this->info("Done routers={$summary['routers']} blocks={$summary['blocks']} warning={$summary['warning']} full={$summary['full']} failed={$summary['failed']}");

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }

    /**
     * @return Collection<int, Router>
     */
    private function routers()
    {
        $routerIds = PppoeAddressPoolBlock::query()
            ->where('is_active', true)
            ->where('allocator_mode', 'last_remote_pointer')
            ->distinct()
            ->pluck('router_id')
            ->filter()
            ->values();

        $query = Router::query()->whereIn('id', $routerIds)->orderBy('name')->orderBy('id');

        if ($id = $this->option('router_id')) {
            $query->whereKey((int) $id);
        }

        if ($this->option('only-active')) {
            $query->where('is_active', true);
        }

        $limit = (int) $this->option('limit');
        if ($limit > 0) {
            $query->limit($limit);
        }

        return $query->get();
    }
}
