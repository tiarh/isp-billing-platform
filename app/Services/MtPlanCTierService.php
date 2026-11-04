<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Router;
use App\Models\RouterMtSecretState;
use App\Models\RouterMtSnapshot;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Plan C tiers T1 (sessions) + T2 (secrets diff).
 */
class MtPlanCTierService
{
    public function __construct(
        protected MikrotikService $mikrotik,
        protected RouterSyncService $routerSync,
        protected ?PppoeOnlineFlagWriter $flags = null,
    ) {}

    protected function flagWriter(): PppoeOnlineFlagWriter
    {
        return $this->flags ??= app(PppoeOnlineFlagWriter::class);
    }

    /**
     * C2: pull active session names → optional customer flags + snapshot.
     *
     * $readOnly: do not touch customer rows (monitoring mode), unless
     * $writeOnlineFlags is also set, in which case ONLY is_online +
     * pppoe_online_checked_at are written (see PppoeOnlineFlagWriter).
     *
     * @return array{ok:bool,router:string,online?:int,names?:int,multi?:int,ms?:int,online_flags?:string,error?:string}
     */
    public function syncSessions(
        Router $router,
        int $timeout = 18,
        bool $forceNames = false,
        bool $readOnly = false,
        bool $writeOnlineFlags = false,
    ): array {
        $t0 = microtime(true);
        $name = $router->name;

        try {
            $prevOnline = (int) ($router->current_online_count ?? 0);
            $prevSecret = (int) ($router->total_pppoe_count ?? 0);
            // Large pools: still try names but shorter budget; on fail keep is_online untouched
            $large = $prevOnline >= 200 || $prevSecret >= 300;
            $budget = $large ? max($timeout, 22) : $timeout;

            $this->mikrotik->connect($router, ['timeout' => $budget, 'attempts' => 1]);
            try {
                // Count-only first (KPI); never authoritatively wipe is_online without names.
                $stats = $this->mikrotik->getHealthStats(false);
                $online = (int) ($stats['online_count'] ?? 0);
                $multi = (int) ($stats['multi_session_users'] ?? 0);
                $sessionRows = (int) ($stats['session_rows'] ?? $online);
                $secret = isset($stats['total_pppoe_count']) ? (int) $stats['total_pppoe_count'] : null;
                $names = [];
                $flagMode = 'none';

                // Name dump (proplist=name only) for is_online flags — separate try so timeout
                // does not discard a good count-only result. Large pools: longer budget.
                try {
                    $active = $this->mikrotik->getActiveConnections(false);
                    $allNames = array_values(array_filter(array_column($active, 'name')));
                    $names = array_values(array_unique($allNames));
                    if ($names !== []) {
                        $unique = count($names);
                        $countOnly = $online;
                        if ($unique > $online) {
                            $online = $unique;
                            $multi = max(0, count($allNames) - $unique);
                        }
                        // Authoritative mass offline ONLY when the dump is complete enough.
                        // Partial stream (e.g. 277 vs count 460) must NOT wipe true onliners.
                        $complete = PppoeOnlineFlagWriter::isCompleteDump($unique, $countOnly);
                        if (! $readOnly) {
                            if ($complete) {
                                $this->mikrotik->syncCustomerOnlineStatus(
                                    array_map(fn ($n) => ['name' => $n], $names),
                                    true
                                );
                                $flagMode = 'authoritative';
                            } else {
                                // Soft: only promote true for names we saw; leave others untouched
                                Customer::where('router_id', $router->id)
                                    ->ebilling()
                                    ->whereIn('pppoe_user', $names)
                                    ->update([
                                        'is_online' => true,
                                        'mikrotik_sync_status' => 'synced',
                                        'mikrotik_synced_at' => now(),
                                        'mikrotik_sync_checked_at' => now(),
                                    ] + (PppoeOnlineFlagWriter::hasCheckedAtColumn() ? ['pppoe_online_checked_at' => now()] : []));
                                $flagMode = 'soft';
                            }
                        } elseif ($writeOnlineFlags) {
                            // Monitoring mode: customer rows stay read-only except the
                            // is_online + pppoe_online_checked_at pair (no router_id moves).
                            if ($complete) {
                                $this->flagWriter()->applyAuthoritative($router, $names);
                                $flagMode = 'authoritative';
                            } else {
                                $this->flagWriter()->applySeen($router, $names);
                                $flagMode = 'soft';
                            }
                        }
                        if ($flagMode === 'soft') {
                            Log::warning('[PlanC T1] partial name dump — soft promote only', [
                                'router' => $name,
                                'names' => $unique,
                                'count_only' => $countOnly,
                            ]);
                        }
                    }
                } catch (\Throwable $e) {
                    // Keep previous is_online rows — never mass-offline on stream timeout
                    Log::warning('[PlanC T1] name dump skipped', [
                        'router' => $name,
                        'error' => $e->getMessage(),
                    ]);
                }
            } finally {
                $this->mikrotik->disconnect();
            }

            $ms = (int) round((microtime(true) - $t0) * 1000);

            if (Schema::hasTable('router_mt_snapshots')) {
                RouterMtSnapshot::query()->create([
                    'router_id' => $router->id,
                    'online_count' => $online,
                    'secret_count' => $secret ?? $prevSecret,
                    'multi_session' => $multi,
                    'session_rows' => $sessionRows,
                    'tier' => 't1',
                    'source' => 'api_sessions',
                    'quality' => 'good',
                    'scrape_ms' => $ms,
                    'message' => 'names='.count($names ?? []),
                    'collected_at' => now(),
                ]);
            }

            // Router aggregate columns (not customer rows): keep the large/small
            // lane split and router detail honest. Krian 514 showed 0/1 from a
            // manual sync on 2 Oct while the T1 snapshot saw 6/8.
            if ((bool) config('services.mt_plan_c.write_live', true)) {
                $aggregate = ['current_online_count' => $online];
                if ($secret !== null && $secret > 0) {
                    $aggregate['total_pppoe_count'] = $secret;
                }
                $router->forceFill($aggregate)->saveQuietly();
            }

            return [
                'ok' => true,
                'router' => $name,
                'online' => $online,
                'names' => count($names ?? []),
                'multi' => $multi,
                'ms' => $ms,
                'online_flags' => $flagMode ?? 'none',
            ];
        } catch (\Throwable $e) {
            Log::warning('[PlanC T1] session sync failed', ['router' => $name, 'error' => $e->getMessage()]);

            return [
                'ok' => false,
                'router' => $name,
                'error' => $e->getMessage(),
                'ms' => (int) round((microtime(true) - $t0) * 1000),
            ];
        }
    }

    /**
     * C3: secrets pull; skip eBilling map if hash unchanged.
     *
     * @return array{ok:bool,router:string,status:string,secret_count?:int,mapped?:int,ms?:int,error?:string}
     */
    public function syncSecretsDiff(Router $router, bool $force = false, int $timeout = 40): array
    {
        $t0 = microtime(true);
        $name = $router->name;

        try {
            $budget = $this->routerSync->recommendedSyncTimeout($router);
            $budget = min($timeout, max(20, $budget));

            $this->mikrotik->connect($router, ['timeout' => $budget, 'attempts' => 1]);
            try {
                $secrets = $this->mikrotik->getPPPSecrets();
            } finally {
                $this->mikrotik->disconnect();
            }

            if ($secrets === []) {
                throw new \RuntimeException('PPP secrets empty');
            }

            $hash = $this->hashSecrets($secrets);
            $count = count($secrets);
            $state = null;
            if (Schema::hasTable('router_mt_secret_states')) {
                $state = RouterMtSecretState::query()->firstOrNew(['router_id' => $router->id]);
            }

            $unchanged = ! $force
                && $state
                && $state->secrets_hash
                && hash_equals((string) $state->secrets_hash, $hash);

            if ($unchanged) {
                if ($state) {
                    $state->fill([
                        'secret_count' => $count,
                        'last_checked_at' => now(),
                        'last_status' => 'skipped',
                        'last_message' => 'hash unchanged',
                    ])->save();
                }
                // Keep UI green — secrets unchanged is success for Plan C
                $this->markRouterSyncSoftSuccess($router, "Secrets unchanged ({$count}).");

                return [
                    'ok' => true,
                    'router' => $name,
                    'status' => 'skipped',
                    'secret_count' => $count,
                    'ms' => (int) round((microtime(true) - $t0) * 1000),
                ];
            }

            // Reuse existing map logic (profiles + customers + staged)
            $this->mikrotik->connect($router, ['timeout' => $budget, 'attempts' => 1]);
            try {
                // profiles best-effort inside syncCustomers path — call public sync via reflection-free API:
                $stats = $this->routerSync->syncCustomers($router, false, $budget);
            } finally {
                try {
                    $this->mikrotik->disconnect();
                } catch (\Throwable) {
                }
            }

            if ($state) {
                $state->fill([
                    'secrets_hash' => $hash,
                    'secret_count' => $count,
                    'mapped_count' => (int) ($stats['mapped'] ?? 0),
                    'last_checked_at' => now(),
                    'last_full_sync_at' => now(),
                    'last_status' => 'synced',
                    'last_message' => 'mapped='.($stats['mapped'] ?? 0),
                ])->save();
            }

            if (Schema::hasTable('router_mt_snapshots')) {
                RouterMtSnapshot::query()->create([
                    'router_id' => $router->id,
                    'online_count' => (int) ($router->fresh()->current_online_count ?? 0),
                    'secret_count' => $count,
                    'tier' => 't2',
                    'source' => 'api_secrets',
                    'quality' => 'good',
                    'scrape_ms' => (int) round((microtime(true) - $t0) * 1000),
                    'message' => 'secrets_diff_synced',
                    'collected_at' => now(),
                ]);
            }

            $this->markRouterSyncSoftSuccess(
                $router,
                sprintf('Secrets mapped %d/%d.', (int) ($stats['mapped'] ?? 0), $count)
            );

            return [
                'ok' => true,
                'router' => $name,
                'status' => 'synced',
                'secret_count' => $count,
                'mapped' => (int) ($stats['mapped'] ?? 0),
                'ms' => (int) round((microtime(true) - $t0) * 1000),
            ];
        } catch (\Throwable $e) {
            if (Schema::hasTable('router_mt_secret_states')) {
                RouterMtSecretState::query()->updateOrCreate(
                    ['router_id' => $router->id],
                    [
                        'last_checked_at' => now(),
                        'last_status' => 'failed',
                        'last_message' => mb_substr($e->getMessage(), 0, 250),
                    ]
                );
            }
            Log::warning('[PlanC T2] secrets diff failed', ['router' => $name, 'error' => $e->getMessage()]);

            // Stream timeout on secrets must NOT show permanent red "Sync gagal" when Online pulse is OK.
            $msg = $e->getMessage();
            if ($this->isStreamTimeout($msg) && $this->hasRecentGoodPulse($router)) {
                $this->markRouterSyncSoftSuccess(
                    $router,
                    'Online pulse OK; secrets dump timed out (will retry).'
                );

                return [
                    'ok' => false,
                    'router' => $name,
                    'status' => 'soft_fail',
                    'error' => $msg,
                    'ms' => (int) round((microtime(true) - $t0) * 1000),
                ];
            }

            return [
                'ok' => false,
                'router' => $name,
                'status' => 'failed',
                'error' => $msg,
                'ms' => (int) round((microtime(true) - $t0) * 1000),
            ];
        }
    }

    protected function markRouterSyncSoftSuccess(Router $router, string $message): void
    {
        $router->update([
            'sync_status' => 'success',
            'sync_message' => mb_substr($message, 0, 250),
            'sync_finished_at' => now(),
            'sync_lock_until' => null,
            'connection_status' => 'online',
        ]);
    }

    protected function isStreamTimeout(string $message): bool
    {
        $m = strtolower($message);

        return str_contains($m, 'stream timed out')
            || str_contains($m, 'timed out')
            || str_contains($m, 'connection timed out');
    }

    protected function hasRecentGoodPulse(Router $router): bool
    {
        if (! Schema::hasTable('router_mt_snapshots')) {
            return (int) ($router->current_online_count ?? 0) > 0
                || (int) ($router->total_pppoe_count ?? 0) > 0;
        }

        return RouterMtSnapshot::query()
            ->where('router_id', $router->id)
            ->where('quality', 'good')
            ->where('collected_at', '>=', now()->subMinutes(15))
            ->exists();
    }

    /**
     * @param  list<array<string,mixed>>  $secrets
     */
    public function hashSecrets(array $secrets): string
    {
        $lines = [];
        foreach ($secrets as $s) {
            if (! is_array($s)) {
                continue;
            }
            $name = strtolower(trim((string) ($s['name'] ?? '')));
            if ($name === '') {
                continue;
            }
            $profile = (string) ($s['profile'] ?? '');
            $disabled = ! empty($s['disabled']) && $s['disabled'] !== 'false' ? '1' : '0';
            $lines[] = $name.'|'.$profile.'|'.$disabled;
        }
        sort($lines);

        return hash('sha256', implode("\n", $lines));
    }
}
