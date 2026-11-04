<?php

namespace App\Console\Commands;

use App\Models\Router;
use App\Models\RouterMtSecretState;
use App\Services\MtPlanCTierService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/** Plan C / C3: T2 secrets hash — skip full map when unchanged; priority dirty first */
class RoutersMtSecretsDiff extends Command
{
    protected $signature = 'routers:mt-secrets-diff
                            {--router_id= : Single router}
                            {--only-active : Only is_active}
                            {--force : Ignore hash, always map}
                            {--limit=0 : Max routers this run (0=all)}
                            {--timeout=40 : API timeout}';

    protected $description = 'Plan C T2: PPP secrets diff (priority: failed/never/mismatch first)';

    public function handle(MtPlanCTierService $tier): int
    {
        if (! (bool) config('services.mt_plan_c.enabled', true)) {
            $this->warn('mt_plan_c disabled');

            return self::SUCCESS;
        }

        $q = Router::query();
        if ($this->option('only-active')) {
            $q->where('is_active', true);
        }
        if ($this->option('router_id')) {
            $q->where('id', (int) $this->option('router_id'));
        }
        $routers = $q->get();
        $routers = $this->prioritize($routers);

        $limit = max(0, (int) $this->option('limit'));
        if ($limit > 0) {
            $routers = $routers->take($limit);
            $this->line("limit={$limit} (priority order)");
        }

        $force = (bool) $this->option('force');
        $timeout = max(15, (int) $this->option('timeout'));
        $synced = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($routers as $router) {
            $r = $tier->syncSecretsDiff($router, $force, $timeout);
            $st = $r['status'] ?? 'failed';
            if ($st === 'synced') {
                $synced++;
                $this->info(sprintf('SYNC %s secrets=%s mapped=%s %sms', $r['router'], $r['secret_count'] ?? '-', $r['mapped'] ?? '-', $r['ms'] ?? '-'));
            } elseif ($st === 'skipped') {
                $skipped++;
                $this->line(sprintf('SKIP %s secrets=%s', $r['router'], $r['secret_count'] ?? '-'));
            } else {
                $failed++;
                $this->warn(sprintf('FAIL %s %s', $r['router'] ?? '?', $r['error'] ?? ''));
            }
        }

        $this->info("secrets-diff done synced={$synced} skipped={$skipped} failed={$failed}");

        return self::SUCCESS;
    }

    /**
     * Priority: never checked → last failed → Online/Secret mismatch → oldest check → id.
     *
     * @param  Collection<int, Router>  $routers
     * @return Collection<int, Router>
     */
    protected function prioritize($routers)
    {
        $states = [];
        if (Schema::hasTable('router_mt_secret_states')) {
            $states = RouterMtSecretState::query()
                ->get()
                ->keyBy('router_id');
        }

        return $routers->sortBy(function (Router $r) use ($states) {
            $st = $states[$r->id] ?? null;
            $online = (int) ($r->current_online_count ?? 0);
            $secret = (int) ($r->total_pppoe_count ?? 0);
            // Prefer pulse display if attrs set
            $mismatch = ($secret > 0 && $online > (int) floor($secret * 1.05))
                || ($secret > 50 && $online < (int) floor($secret * 0.5));

            $tier = 50;
            if (! $st || ! $st->last_checked_at) {
                $tier = 0; // never
            } elseif (($st->last_status ?? '') === 'failed') {
                $tier = 1;
            } elseif ($mismatch) {
                $tier = 2;
            } elseif (($st->last_status ?? '') === 'synced' && $st->last_full_sync_at
                && $st->last_full_sync_at->lt(now()->subHours(6))) {
                $tier = 3; // stale full map
            } else {
                $tier = 4;
            }

            $checked = $st?->last_checked_at?->timestamp ?? 0;

            return sprintf('%02d-%010d-%06d', $tier, $checked, $r->id);
        })->values();
    }
}
