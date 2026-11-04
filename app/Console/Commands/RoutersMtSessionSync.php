<?php

namespace App\Console\Commands;

use App\Models\Router;
use App\Services\MtPlanCTierService;
use Illuminate\Console\Command;

/** Plan C / C2: T1 session names → is_online */
class RoutersMtSessionSync extends Command
{
    protected $signature = 'routers:mt-session-sync
                            {--router_id= : Single router}
                            {--only-active : Only is_active routers}
                            {--skip-large : Skip pools with online/secret >= 200 (run separately)}
                            {--only-large : Only large pools}
                            {--read-only : Do not update customer rows}
                            {--write-online-flags : With --read-only, still write ONLY customers.is_online + pppoe_online_checked_at}
                            {--timeout=18 : API timeout seconds}';

    protected $description = 'Plan C T1: sync PPP active names to customers.is_online';

    public function handle(MtPlanCTierService $tier): int
    {
        if (! (bool) config('services.mt_plan_c.enabled', true)) {
            $this->warn('mt_plan_c disabled');

            return self::SUCCESS;
        }

        $q = Router::query()->orderBy('id');
        if ($this->option('only-active')) {
            $q->where('is_active', true);
        }
        if ($this->option('router_id')) {
            $q->where('id', (int) $this->option('router_id'));
        }
        $routers = $q->get();
        $skipLarge = (bool) $this->option('skip-large');
        $onlyLarge = (bool) $this->option('only-large');
        $readOnly = (bool) $this->option('read-only');
        // Runtime kill-switch: MT_PLAN_C_MONITORING_WRITE_ONLINE=false keeps the lane fully read-only.
        $writeOnlineFlags = (bool) $this->option('write-online-flags')
            && (bool) config('services.mt_plan_c.monitoring_write_online', true);

        $routers = $routers->filter(function (Router $r) use ($skipLarge, $onlyLarge) {
            $online = (int) ($r->current_online_count ?? 0);
            $secret = (int) ($r->total_pppoe_count ?? 0);
            $large = $online >= 200 || $secret >= 300;
            if ($skipLarge && $large) {
                return false;
            }
            if ($onlyLarge && ! $large) {
                return false;
            }

            return true;
        })->sortBy(function (Router $r) {
            // small pools first
            return max((int) $r->current_online_count, (int) $r->total_pppoe_count);
        })->values();

        $timeout = max(8, (int) $this->option('timeout'));
        $ok = 0;
        $fail = 0;
        $skipped = 0;

        foreach ($routers as $router) {
            $r = $tier->syncSessions($router, $timeout, false, $readOnly, $writeOnlineFlags);
            if ($r['ok'] ?? false) {
                $ok++;
                $this->info(sprintf(
                    'OK %s online=%s names=%s multi=%s flags=%s %sms',
                    $r['router'],
                    $r['online'] ?? '-',
                    $r['names'] ?? '-',
                    $r['multi'] ?? '-',
                    $r['online_flags'] ?? '-',
                    $r['ms'] ?? '-'
                ));
            } else {
                $fail++;
                $this->warn(sprintf('FAIL %s %s', $r['router'] ?? '?', $r['error'] ?? ''));
            }
        }

        $this->info("session-sync done ok={$ok} fail={$fail}");

        return self::SUCCESS;
    }
}
