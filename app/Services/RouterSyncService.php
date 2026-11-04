<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Router;
use App\Models\RouterProfile;
use App\Models\RouterStagedCustomer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RouterSyncService
{
    protected MikrotikService $mikrotik;

    public function __construct(MikrotikService $mikrotik)
    {
        $this->mikrotik = $mikrotik;
    }

    /**
     * API socket budget for fullSync. Scales with PPPoE count; large RA-OLT (~800) needs more than 55s.
     * Must remain well under SyncRouterJob::$timeout (280s).
     */
    public function recommendedSyncTimeout(Router $router): int
    {
        // customers_count is never loaded here; total_pppoe_count is the scan truth.
        $estimate = max(
            (int) $router->total_pppoe_count,
            50
        );

        // ~0.08s/secret estimate + base 25s; cap 120s for huge fleets (RA-OLT).
        $scaled = 25 + (int) ceil($estimate * 0.08);

        return (int) min(120, max(35, $scaled));
    }

    /**
     * LIVE health (Plan B): count-only / name-proplist active + resource.
     * Does NOT pull PPP secrets. Never writes online=0 on failure (keeps last-known).
     *
     * @return array{success:bool,online_count?:int,source?:string,duration_ms?:int,error?:string,message:string}
     */
    public function syncLiveHealth(Router $router, int $timeout = 10, bool $syncCustomerFlags = true): array
    {
        $t0 = microtime(true);
        $budget = max(5, $timeout);
        // Large PPPoE pools need more socket time on flaky VPN (Krian ~400+ sessions).
        if ((int) $router->total_pppoe_count > 150) {
            $budget = max($budget, 22);
        }
        if ((int) $router->total_pppoe_count > 350 || str_contains(strtolower((string) $router->name), 'krian')) {
            $budget = max($budget, 28);
        }

        $attempts = 0;
        $lastError = null;

        while ($attempts < 2) {
            $attempts++;
            try {
                $this->mikrotik->connect($router, ['timeout' => $budget, 'attempts' => 1]);
                try {
                    // LIVE always count-only first path; name list only if requested.
                    $stats = $this->mikrotik->getHealthStats($syncCustomerFlags);
                    $onlineCount = (int) ($stats['online_count'] ?? 0);

                    if ($syncCustomerFlags && ! empty($stats['active_names'])) {
                        $rows = array_map(fn ($n) => ['name' => $n], $stats['active_names']);
                        $this->mikrotik->syncCustomerOnlineStatus($rows, true);
                    } elseif ($syncCustomerFlags && ($stats['source'] ?? '') === 'proplist_name' && empty($stats['active_names'])) {
                        $this->mikrotik->syncCustomerOnlineStatus([], true);
                    }

                    $prevOnline = (int) ($router->current_online_count ?? 0);
                    $prevSecret = (int) ($router->total_pppoe_count ?? 0);
                    $sampleSecret = (int) ($stats['total_pppoe_count'] ?? 0);
                    $secretHint = max($prevSecret, $sampleSecret);

                    // Guard flaky under-count (Krian: single sample 279 while true ~450).
                    $suspectLow = false;
                    if ($prevOnline >= 200 && $onlineCount > 0 && $onlineCount < (int) floor($prevOnline * 0.7)) {
                        $suspectLow = true;
                    }
                    // Also: Online << secrets on large pool (even if prev was already wrong/low).
                    if ($secretHint >= 300 && $onlineCount > 0 && $onlineCount < (int) floor($secretHint * 0.55)) {
                        $suspectLow = true;
                    }
                    if ($suspectLow) {
                        try {
                            $again = $this->mikrotik->countActiveConnectionsOnly(true);
                            $onlineCount = max($onlineCount, $again);
                        } catch (\Throwable) {
                            // keep first sample
                        }
                        if ($prevOnline >= 200 && $onlineCount < (int) floor($prevOnline * 0.7)) {
                            $onlineCount = $prevOnline;
                            $stats['source'] = ($stats['source'] ?? 'count_only').'+preserved';
                        }
                    }

                    $payload = [
                        'connection_status' => 'online',
                        'cpu_load' => $stats['cpu_load'],
                        'uptime' => $stats['uptime'],
                        'version' => $stats['version'],
                        'board_name' => $stats['board_name'],
                        'last_health_check_at' => now(),
                    ];

                    // Plan C / C4: when snapshot UI is primary, LIVE must not fight pulse Online/Secret.
                    $planCUi = (bool) config('services.mt_plan_c.use_snapshot_ui', false)
                        || (bool) config('services.mt_plan_c.enabled', false);
                    $skipOnlineWrite = false;
                    if ($planCUi && (bool) config('services.mt_plan_c.live_skip_online_write', true)) {
                        $skipOnlineWrite = true;
                        $stats['source'] = ($stats['source'] ?? 'count_only').'+plan_c_ui';
                    } else {
                        // Prefer mktxp PPP exporter numbers: skip LIVE Online write when under-count.
                        $prevOnlineNow = (int) ($router->current_online_count ?? 0);
                        $skipOnlineWrite = (bool) config('services.mktxp.enabled', true)
                            && $prevOnlineNow >= 50
                            && (int) $onlineCount > 0
                            && (int) $onlineCount < (int) floor($prevOnlineNow * 0.85);
                        if ($skipOnlineWrite) {
                            $stats['source'] = ($stats['source'] ?? 'count_only').'+skip_under_mktxp';
                        }
                    }

                    if (! $skipOnlineWrite) {
                        $payload['current_online_count'] = $onlineCount;
                        if (isset($stats['total_pppoe_count']) && (int) $stats['total_pppoe_count'] > 0) {
                            $newSecret = (int) $stats['total_pppoe_count'];
                            if ($prevSecret < 100 || $newSecret >= (int) floor($prevSecret * 0.85) || $newSecret > $prevSecret) {
                                $payload['total_pppoe_count'] = $newSecret;
                            }
                        }
                    }

                    $router->update($payload);
                } finally {
                    $this->mikrotik->disconnect();
                }

                $ms = (int) round((microtime(true) - $t0) * 1000);
                $sec = (int) ($stats['total_pppoe_count'] ?? $router->total_pppoe_count ?? 0);
                $multi = (int) ($stats['multi_session_users'] ?? 0);
                $rows = (int) ($stats['session_rows'] ?? $onlineCount);
                $over = $sec > 0 && $onlineCount > $sec ? ' OVER' : '';

                return [
                    'success' => true,
                    'online_count' => $onlineCount,
                    'secret_count' => $sec > 0 ? $sec : null,
                    'session_rows' => $rows,
                    'multi_session_users' => $multi,
                    'source' => $stats['source'] ?? 'count_only',
                    'duration_ms' => $ms,
                    'attempts' => $attempts,
                    'message' => "LIVE ok online={$onlineCount}/secret={$sec} sessions={$rows} multi={$multi} ({$ms}ms){$over}",
                ];
            } catch (\Exception $e) {
                $lastError = $e->getMessage();
                try {
                    $this->mikrotik->disconnect();
                } catch (\Throwable) {
                }
                // One quick retry on stream timeout (common on VPN flaky).
                if ($attempts < 2 && str_contains(strtolower($lastError), 'timeout')) {
                    usleep(250_000);
                    $budget = min(30, $budget + 5);

                    continue;
                }
                break;
            }
        }

        // Soft offline: keep last-known online count (no fake 0).
        $router->update([
            'connection_status' => 'offline',
            'last_health_check_at' => now(),
        ]);

        return [
            'success' => false,
            'error' => $lastError ?? 'unknown',
            'duration_ms' => (int) round((microtime(true) - $t0) * 1000),
            'message' => 'LIVE fail (online count preserved): '.($lastError ?? 'unknown'),
        ];
    }

    /**
     * @deprecated Use syncLiveHealth() — kept for Controller call sites
     */
    public function syncHealthStatus(Router $router, int $timeout = 8): array
    {
        return $this->syncLiveHealth($router, $timeout, true);
    }

    /**
     * Scan and Map Customers
     */
    public function syncCustomers(Router $router, bool $dryRun = false, int $timeout = 45): array
    {
        $stats = $this->initialScanStats();

        try {
            $this->mikrotik->connect($router, ['timeout' => $timeout, 'attempts' => 1]);

            $this->syncProfilesToDatabase($router);

            $secrets = $this->mikrotik->getPPPSecrets();
            $stats = $this->syncSecretsToEbillingCustomers($router, $secrets, $stats, $dryRun);

            // Update scan results
            if (! $dryRun) {
                $router->update([
                    'connection_status' => 'online',
                    'last_scanned_at' => now(),
                    'last_scan_customers_count' => $stats['mapped'],
                    'total_pppoe_count' => $stats['total_secrets'],
                ]);
            }

            $this->mikrotik->disconnect();

        } catch (\Exception $e) {
            if (! $dryRun) {
                $router->update(['connection_status' => 'offline']);
            }
            throw $e; // Re-throw to let caller handle critical failure
        }

        return $stats;
    }

    /**
     * Full Sync: Health + Customers + Status (One Connection)
     */

    /**
     * Efficient fullSync (target <40s):
     *  A) secrets-only connection (minimal proplist) → map DB → disconnect
     *  B) separate short connection for active+resource (optional, non-fatal)
     * Never profile-chunk / count-only / multi-minute waits.
     */
    public function fullSync(Router $router, ?int $timeout = null): array
    {
        $timeout ??= $this->recommendedSyncTimeout($router);
        // Give most of the budget to secrets (large fleets); leave room for health phase.
        $secretBudget = min(90, max(20, (int) $timeout - 20));
        $healthBudget = min(25, max(10, (int) $timeout - $secretBudget));
        $result = ['health' => [], 'scan' => [], 'success' => true];
        $t0 = microtime(true);

        try {
            // --- Phase A: secrets only ---
            $this->mikrotik->connect($router, ['timeout' => $secretBudget, 'attempts' => 1]);
            try {
                $secrets = $this->mikrotik->getPPPSecrets();
            } finally {
                $this->mikrotik->disconnect();
            }

            if ($secrets === []) {
                throw new \RuntimeException('PPP secrets empty');
            }

            $scanStats = $this->syncSecretsToEbillingCustomers(
                $router,
                $secrets,
                $this->initialScanStats(),
                false,
                []
            );
            $result['scan'] = $scanStats;
            $result['secrets_ms'] = (int) round((microtime(true) - $t0) * 1000);

            // --- Phase B: LIVE health (count-only / name list) — separate socket ---
            $onlineCount = null;
            $system = [];
            $t1 = microtime(true);
            $healthOk = false;
            try {
                $this->mikrotik->connect($router, ['timeout' => $healthBudget, 'attempts' => 1]);
                try {
                    $stats = $this->mikrotik->getHealthStats(true);
                    $onlineCount = (int) ($stats['online_count'] ?? 0);
                    if (! empty($stats['active_names'])) {
                        $rows = array_map(fn ($n) => ['name' => $n], $stats['active_names']);
                        $this->mikrotik->syncCustomerOnlineStatus($rows, true);
                    } elseif (($stats['source'] ?? '') === 'proplist_name') {
                        $this->mikrotik->syncCustomerOnlineStatus([], true);
                    }
                    $system = [
                        'cpu-load' => $stats['cpu_load'],
                        'uptime' => $stats['uptime'],
                        'version' => $stats['version'],
                        'board-name' => $stats['board_name'],
                    ];
                    $healthOk = true;
                    $result['health_source'] = $stats['source'] ?? null;
                } finally {
                    $this->mikrotik->disconnect();
                }
            } catch (\Exception $e) {
                Log::warning("Health phase failed on {$router->name}: {$e->getMessage()}");
                $result['health_error'] = $e->getMessage();
            }
            $result['health_ms'] = (int) round((microtime(true) - $t1) * 1000);

            // Secrets OK ⇒ connection online for map tier. Never wipe online count if health failed.
            $payload = [
                'connection_status' => 'online',
                'total_pppoe_count' => $scanStats['total_secrets'],
                'last_scanned_at' => now(),
                'last_scan_customers_count' => $scanStats['mapped'],
            ];
            if ($healthOk && $onlineCount !== null) {
                $payload['current_online_count'] = $onlineCount;
                $payload['cpu_load'] = isset($system['cpu-load']) ? (int) $system['cpu-load'] : null;
                $payload['uptime'] = $system['uptime'] ?? null;
                $payload['version'] = $system['version'] ?? null;
                $payload['board_name'] = $system['board-name'] ?? null;
                $payload['last_health_check_at'] = now();
            }
            $router->update($payload);

            $result['online_count'] = $onlineCount;
            $result['health_ok'] = $healthOk;
            $result['duration_ms'] = (int) round((microtime(true) - $t0) * 1000);

            return $result;
        } catch (\Exception $e) {
            try {
                $this->mikrotik->disconnect();
            } catch (\Throwable) {
            }
            $router->update([
                'connection_status' => 'offline',
                'last_health_check_at' => now(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'duration_ms' => (int) round((microtime(true) - $t0) * 1000),
            ];
        }
    }

    /**
     * Auto-detect and configure isolation profile if missing
     *
     * @param  list<array<string, mixed>>|null  $profiles
     */
    protected function detectAndSetIsolationProfile(Router $router, ?array $profiles = null): void
    {
        try {
            $profiles ??= $this->mikrotik->getProfiles();
            $commonNames = ['isolirebilling', 'isolir', 'isolated', 'nonpayment', 'block', 'suspend', 'expired'];

            foreach ($profiles as $profile) {
                $profileName = $profile['name'] ?? '';
                if (in_array(strtolower($profileName), $commonNames, true)) {
                    $router->update(['isolation_profile' => $profileName]);
                    Log::info("Auto-configured isolation profile for {$router->name}: {$profileName}");
                    break;
                }
            }
        } catch (\Exception $e) {
            Log::warning("Failed to auto-detect isolation profile for {$router->name}: ".$e->getMessage());
        }
    }

    /**
     * Sync router profiles to database for UI usage
     *
     * @param  list<array<string, mixed>>|null  $profiles
     */
    protected function syncProfilesToDatabase(Router $router, ?array $profiles = null): void
    {
        try {
            $profiles ??= $this->mikrotik->getProfiles();

            foreach ($profiles as $profile) {
                $name = $profile['name'] ?? '';

                // Skip system/isolation profiles
                if (in_array(strtolower($name), ['default', 'default-encryption'], true)) {
                    continue;
                }
                if (stripos($name, 'isolir') !== false || stripos($name, 'speedtest') !== false) {
                    continue;
                }

                $rateLimit = $profile['rate-limit'] ?? null;
                $bandwidth = $this->extractBandwidth($rateLimit);

                RouterProfile::updateOrCreate(
                    [
                        'router_id' => $router->id,
                        'name' => $name,
                    ],
                    [
                        'rate_limit' => $rateLimit,
                        'bandwidth' => $bandwidth,
                        'local_address' => $profile['local-address'] ?? null,
                        'remote_address' => $profile['remote-address'] ?? null,
                        'only_one' => $profile['only-one'] ?? null,
                    ]
                );
            }

            Log::info("Synced profiles to database for {$router->name}");
        } catch (\Exception $e) {
            Log::warning("Failed to sync profiles for {$router->name}: ".$e->getMessage());
        }
    }

    /**
     * Extract bandwidth from Mikrotik rate limit string
     */
    protected function extractBandwidth(?string $rateLimit): ?string
    {
        if (! $rateLimit) {
            return null;
        }

        $rateLimit = trim($rateLimit);
        // MikroTik: "460M/460M 920M/920M" — first pair is max rx/tx rate.
        $mainPair = explode(' ', $rateLimit)[0] ?? '';
        $segments = explode('/', $mainPair);

        return ($segments[0] ?? '') !== '' ? $segments[0] : null;
    }

    protected function initialScanStats(): array
    {
        return [
            'total_secrets' => 0,
            'mapped' => 0,
            'not_found_ebilling' => 0,
            'unmatched_mikrotik' => 0,
            'orphaned' => 0,
            'staged_router_only' => 0,
            'staged_matched' => 0,
            'staged_gone' => 0,
            'synced_package' => 0,
            'synced_status' => 0,
            'status_skipped_non_live' => 0,
            'status_skipped_non_live_ids' => [],
            'status_skipped_changed_mid_scan' => 0,
            'errors' => [],
        ];
    }

    protected function syncSecretsToEbillingCustomers(
        Router $router,
        array $secrets,
        array $stats,
        bool $dryRun = false,
        array $activeConnections = []
    ): array {
        $stats['total_secrets'] = count($secrets);
        $secretUsernames = $this->secretUsernames($secrets);
        $activeUsernames = array_flip($this->secretUsernames($activeConnections));
        $customersByPppoe = $this->ebillingCustomersByPppoe($router);
        $syncRows = [];

        foreach ($secrets as $secret) {
            $pppoeUsername = $secret['name'] ?? null;
            if (! $pppoeUsername) {
                continue;
            }

            $customer = $customersByPppoe[$pppoeUsername] ?? null;

            if (! $customer) {
                $stats['unmatched_mikrotik']++;
                if (! $dryRun) {
                    $this->stageRouterOnlySecret($router, $secret);
                    $stats['staged_router_only']++;
                }

                continue;
            }

            if (! $dryRun) {
                $isOnline = array_key_exists($pppoeUsername, $activeUsernames) ? true : null;
                $syncRows[] = $this->customerSyncRow($router, $customer, $secret, $stats, $isOnline);
                if ($this->markStagedSecretMatched($router, $pppoeUsername, $customer)) {
                    $stats['staged_matched']++;
                }
            }
            $stats['mapped']++;
        }

        if (! $dryRun && ! empty($syncRows)) {
            $this->updateCustomerSyncRows($syncRows, $stats);
        }

        if (! $dryRun) {
            $stats['staged_gone'] = $this->markStagedSecretsGone($router, $secretUsernames);
        }

        $stats['not_found_ebilling'] = $this->markAssignedEbillingCustomersMissingFromRouter($router, $secretUsernames, $dryRun);
        $stats['orphaned'] = $stats['unmatched_mikrotik'];

        if (($stats['status_skipped_non_live'] ?? 0) > 0) {
            Log::warning("Router sync left non-live customers unchanged on isolation profile for {$router->name}", [
                'count' => $stats['status_skipped_non_live'],
                'customer_ids' => array_slice($stats['status_skipped_non_live_ids'], 0, 50),
            ]);
        }

        Log::info("Completed full customer sync for {$router->name}", [
            'total_secrets' => $stats['total_secrets'],
            'mapped' => $stats['mapped'],
            'unmatched_mikrotik' => $stats['unmatched_mikrotik'],
            'not_found_ebilling' => $stats['not_found_ebilling'],
            'staged_router_only' => $stats['staged_router_only'],
            'staged_gone' => $stats['staged_gone'],
            'synced_status' => $stats['synced_status'],
            'status_skipped_non_live' => $stats['status_skipped_non_live'],
            'status_skipped_changed_mid_scan' => $stats['status_skipped_changed_mid_scan'],
        ]);

        return $stats;
    }

    protected function stageRouterOnlySecret(Router $router, array $secret): void
    {
        $username = (string) ($secret['name'] ?? '');
        if ($username === '') {
            return;
        }

        $now = now();

        $staged = RouterStagedCustomer::firstOrNew([
            'router_id' => $router->id,
            'pppoe_user' => $username,
        ]);

        if (! $staged->exists) {
            $staged->first_seen_at = $now;
        }

        $payload = $secret;
        unset($payload['password']);

        $staged->fill([
            'matched_customer_id' => null,
            'profile' => $secret['profile'] ?? null,
            'comment' => $secret['comment'] ?? null,
            'disabled' => filter_var($secret['disabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'status' => 'unmatched',
            'raw_payload' => $payload,
            'last_seen_at' => $now,
        ])->save();
    }

    protected function markStagedSecretMatched(Router $router, string $username, Customer $customer): bool
    {
        return RouterStagedCustomer::where('router_id', $router->id)
            ->where('pppoe_user', $username)
            ->where('status', '!=', 'matched')
            ->update([
                'matched_customer_id' => $customer->id,
                'status' => 'matched',
                'last_seen_at' => now(),
            ]) > 0;
    }

    protected function markStagedSecretsGone(Router $router, array $secretUsernames): int
    {
        $query = RouterStagedCustomer::where('router_id', $router->id)
            ->where('status', 'unmatched');

        if (! empty($secretUsernames)) {
            $query->whereNotIn('pppoe_user', $secretUsernames);
        }

        return $query->update(['status' => 'gone']);
    }

    protected function secretUsernames(array $secrets): array
    {
        return collect($secrets)
            ->pluck('name')
            ->filter(fn ($username) => is_string($username) && $username !== '')
            ->unique()
            ->values()
            ->all();
    }

    protected function ebillingCustomersByPppoe(?Router $router = null): array
    {
        $query = Customer::ebilling()
            ->whereNotNull('pppoe_user')
            ->where('pppoe_user', '!=', '');

        if ($router) {
            $query->where(function ($inner) use ($router) {
                $inner->where('router_id', $router->id)
                    ->orWhereNull('router_id');
            });
        }

        return $query
            ->get(['id', 'pppoe_user', 'router_id', 'status', 'mikrotik_profile', 'is_online'])
            ->keyBy('pppoe_user')
            ->all();
    }

    protected function markAssignedEbillingCustomersMissingFromRouter(Router $router, array $secretUsernames, bool $dryRun = false): int
    {
        $query = Customer::ebilling()
            ->where('router_id', $router->id)
            ->whereNotNull('pppoe_user')
            ->where('pppoe_user', '!=', '');

        if (! empty($secretUsernames)) {
            $query->whereNotIn('pppoe_user', $secretUsernames);
        }

        $count = (clone $query)->count();

        if (! $dryRun && $count > 0) {
            $query->update([
                'mikrotik_sync_status' => 'missing',
                'mikrotik_synced_at' => null,
                'mikrotik_sync_checked_at' => now(),
            ]);
        }

        return $count;
    }

    protected function customerSyncRow(Router $router, Customer $customer, array $secret, array &$stats, ?bool $isOnline = null): array
    {
        $profileName = $secret['profile'] ?? null;
        $now = now();
        $row = [
            'id' => $customer->id,
            'router_id' => $router->id,
            'mikrotik_profile' => $profileName,
            'mikrotik_sync_status' => 'synced',
            'mikrotik_synced_at' => $now,
            'mikrotik_sync_checked_at' => $now,
            'is_online' => $isOnline ?? $customer->is_online,
            'status' => $customer->status,
            'scan_status' => $customer->status,
            'updated_at' => $now,
        ];

        // Auto-Sync Status (Isolation Logic)
        // Only active <-> isolated transitions are driven by the PPPoE profile.
        // Any other status (terminated, pending_installation, suspended, inactive)
        // is left unchanged: resurrecting it to isolated would bill it monthly.
        if ($router->isolation_profile) {
            $onIsolationProfile = $profileName === $router->isolation_profile;

            if ($onIsolationProfile && $customer->status === 'active') {
                $row['status'] = 'isolated';
                $stats['synced_status']++;
            } elseif (! $onIsolationProfile && $customer->status === 'isolated') {
                $row['status'] = 'active';
                $stats['synced_status']++;
            } elseif ($onIsolationProfile && $customer->status !== 'isolated') {
                $stats['status_skipped_non_live'] = ($stats['status_skipped_non_live'] ?? 0) + 1;
                $stats['status_skipped_non_live_ids'][] = $customer->id;
            }
        }

        return $row;
    }

    /**
     * Write scan results. The status in each row was derived from the customer
     * as read at scan start; re-read it under a row lock and only write status
     * if it is still what the scan saw (payment reconnect, terminate, billing
     * isolation may have changed it mid-scan). Profile/sync fields are still written.
     */
    protected function updateCustomerSyncRows(array $rows, array &$stats): void
    {
        $changedIds = [];

        foreach (array_chunk($rows, 150) as $chunk) {
            // Ascending id order keeps lock acquisition consistent across chunks.
            usort($chunk, fn ($a, $b) => $a['id'] <=> $b['id']);

            DB::transaction(function () use ($chunk, &$changedIds, &$stats) {
                foreach ($chunk as $row) {
                    $id = $row['id'];
                    $scanStatus = $row['scan_status'] ?? null;
                    unset($row['id'], $row['scan_status']);

                    $currentStatus = Customer::whereKey($id)->lockForUpdate()->value('status');

                    if ($currentStatus !== $scanStatus) {
                        if ($row['status'] !== $scanStatus && isset($stats['synced_status'])) {
                            $stats['synced_status']--;
                        }
                        unset($row['status']);
                        $changedIds[] = $id;
                    }

                    Customer::whereKey($id)->update($row);
                }
            });
        }

        if ($changedIds !== []) {
            $stats['status_skipped_changed_mid_scan'] = ($stats['status_skipped_changed_mid_scan'] ?? 0) + count($changedIds);
            Log::warning('Router sync did not overwrite customer status changed mid-scan', [
                'count' => count($changedIds),
                'customer_ids' => array_slice($changedIds, 0, 50),
            ]);
        }
    }
}
