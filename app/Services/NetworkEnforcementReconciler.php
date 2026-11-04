<?php

namespace App\Services;

use App\Enums\CustomerStatus;
use App\Models\Customer;
use App\Models\Router;
use App\Models\Setting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * F0 PR-D: READ-ONLY reconciliation of the isolation state between MikroTik
 * PPP secrets and eBilling customer status.
 *
 * Router access is limited to print queries through MikrotikService
 * (getPPPSecrets, getActiveConnections, getProfiles, getPppAuthenticationSource).
 * This class never calls a MikroTik write method; the only writes it performs
 * are the optional report rows in `enforcement_drift` and the last-run summary
 * setting, both done by store().
 */
class NetworkEnforcementReconciler
{
    public const KIND_ACTIVE_BUT_ISOLATED = 'active_but_isolated_on_router';

    public const KIND_ISOLATED_BUT_OPEN = 'isolated_but_open_on_router';

    public const KIND_SECRET_MISSING = 'secret_missing';

    public const KIND_SECRET_ON_OTHER_ROUTER = 'secret_on_other_router';

    public const KIND_SECRET_DISABLED = 'secret_disabled';

    public const KIND_RADIUS = 'radius';

    public const KIND_ISOLATION_PROFILE_MISSING = 'isolation_profile_missing';

    public const KIND_SECRETS_INCOMPLETE = 'secrets_incomplete';

    public const KIND_ROUTER_UNREACHABLE = 'router_unreachable';

    public const KIND_ROUTER_SKIPPED_BUDGET = 'router_skipped_budget';

    public const SEVERITY_DRIFT = 'drift';

    public const SEVERITY_INFO = 'info';

    public const SEVERITY_ERROR = 'error';

    public const ISOLATED_STATUSES = [CustomerStatus::Isolated->value];

    public const OPEN_STATUSES = ['active'];

    public const SUMMARY_SETTING = 'network_reconcile_last_summary';

    /** @var (callable(): float)|null */
    private $clock;

    public function __construct(private MikrotikService $mikrotik) {}

    /** Test seam for the runtime budget. */
    public function setClock(callable $clock): void
    {
        $this->clock = $clock;
    }

    /**
     * @return array{
     *     run_id: string, read_only: bool, started_at: string, finished_at: string, duration_ms: int,
     *     routers: list<array<string, mixed>>, totals: array<string, mixed>, findings: list<array<string, mixed>>
     * }
     */
    public function run(?int $routerId = null): array
    {
        $startedAt = now();
        $t0 = $this->now();
        $budget = max(30, (int) config('services.network_reconcile.budget_seconds', 600));
        $timeout = max(3, (int) config('services.network_reconcile.connect_timeout', 25));

        $routers = Router::query()
            ->when($routerId !== null, fn ($q) => $q->whereKey($routerId))
            ->when($routerId === null, fn ($q) => $q->where('is_active', true))
            ->orderBy('id')
            ->get();

        $customersByRouter = $this->customersByRouter($routers->pluck('id')->all());

        $findings = [];
        $routerReports = [];
        /** @var array<int, array<string, true>> lower-case secret names per scanned router */
        $secretIndex = [];
        /** @var list<array<string, mixed>> */
        $missing = [];

        foreach ($routers as $router) {
            $customers = $customersByRouter->get($router->id, collect());
            $report = [
                'router_id' => $router->id,
                'name' => $router->name,
                'status' => 'ok',
                'radius' => (bool) $router->radius_enabled,
                'isolation_profile' => $this->isolationProfileName($router),
                'customers' => $customers->count(),
                'customers_checked' => 0,
                'secrets' => 0,
                'active_sessions' => 0,
                'duration_ms' => 0,
                'counts' => [],
            ];

            if (($this->now() - $t0) >= $budget) {
                $report['status'] = 'skipped_budget';
                $findings[] = $this->routerFinding($router, self::KIND_ROUTER_SKIPPED_BUDGET, self::SEVERITY_ERROR, [
                    'budget_seconds' => $budget,
                ]);
                $routerReports[] = $report;

                continue;
            }

            $rt0 = $this->now();
            try {
                $this->mikrotik->connect($router, ['timeout' => $timeout, 'attempts' => 1]);
                try {
                    $secrets = $this->mikrotik->getPPPSecrets();
                    $active = $this->mikrotik->getActiveConnections(false);
                    $profiles = $this->mikrotik->getProfiles();
                    $aaaRadius = $this->routerUsesRadius();
                    $report['radius'] = $report['radius'] || $aaaRadius;
                } finally {
                    $this->mikrotik->disconnect();
                }
            } catch (\Throwable $e) {
                Log::warning('[ReconcileNetwork] router read failed', [
                    'router_id' => $router->id,
                    'exception_class' => $e::class,
                ]);
                $report['status'] = 'unreachable';
                $report['duration_ms'] = (int) round(($this->now() - $rt0) * 1000);
                $findings[] = $this->routerFinding($router, self::KIND_ROUTER_UNREACHABLE, self::SEVERITY_ERROR, [
                    'error' => Str::limit($e->getMessage(), 160),
                ]);
                $routerReports[] = $report;

                continue;
            }

            $secretMap = $this->secretMap($secrets);
            $onlineMap = [];
            foreach ($active as $row) {
                $name = strtolower(trim((string) ($row['name'] ?? '')));
                if ($name !== '') {
                    $onlineMap[$name] = true;
                }
            }

            $report['secrets'] = count($secretMap);
            $report['active_sessions'] = count($onlineMap);
            $secretIndex[$router->id] = array_fill_keys(array_keys($secretMap), true);

            $isolationProfile = $report['isolation_profile'];
            $profileExists = false;
            foreach ($profiles as $profile) {
                if (strcasecmp(trim((string) ($profile['name'] ?? '')), $isolationProfile) === 0) {
                    $profileExists = true;
                    break;
                }
            }
            if (! $profileExists && $customers->isNotEmpty()) {
                $findings[] = $this->routerFinding($router, self::KIND_ISOLATION_PROFILE_MISSING, self::SEVERITY_DRIFT, [
                    'isolation_profile' => $isolationProfile,
                ]);
            }

            // A short secret list (stream timeout on large routers such as 15/513)
            // would turn into hundreds of false "secret_missing" rows. Report the
            // router instead and only classify the secrets that were read.
            $expected = (int) ($router->total_pppoe_count ?? 0);
            $incomplete = $expected > 0 && count($secretMap) < (int) floor($expected * 0.85);
            if ($incomplete) {
                $report['status'] = 'incomplete';
                $findings[] = $this->routerFinding($router, self::KIND_SECRETS_INCOMPLETE, self::SEVERITY_ERROR, [
                    'secrets_read' => count($secretMap),
                    'expected' => $expected,
                ]);
            }

            foreach ($customers as $customer) {
                $key = strtolower(trim((string) $customer->pppoe_user));
                $secret = $secretMap[$key] ?? null;
                $online = isset($onlineMap[$key]);

                if ($secret === null) {
                    if ($report['radius']) {
                        $findings[] = $this->customerFinding($router, $customer, self::KIND_RADIUS, self::SEVERITY_INFO, null, $online);
                        $report['customers_checked']++;
                    } elseif (! $incomplete) {
                        $missing[] = ['router' => $router, 'customer' => $customer, 'online' => $online];
                        $report['customers_checked']++;
                    }

                    continue;
                }

                $report['customers_checked']++;
                $finding = $this->classify($router, $customer, $secret, $online, $isolationProfile);
                if ($finding !== null) {
                    $findings[] = $finding;
                }
            }

            $report['duration_ms'] = (int) round(($this->now() - $rt0) * 1000);
            $routerReports[] = $report;
        }

        // Second pass: a secret missing on the assigned router may live on a
        // sibling router scanned in this run (Krian = router 15 x86 + 514 CCR,
        // two different devices). That is a wrong router_id, not a lost secret.
        foreach ($missing as $item) {
            $key = strtolower(trim((string) $item['customer']->pppoe_user));
            $elsewhere = [];
            foreach ($secretIndex as $scannedRouterId => $names) {
                if ($scannedRouterId !== $item['router']->id && isset($names[$key])) {
                    $elsewhere[] = $scannedRouterId;
                }
            }

            $findings[] = $elsewhere !== []
                ? $this->customerFinding($item['router'], $item['customer'], self::KIND_SECRET_ON_OTHER_ROUTER, self::SEVERITY_DRIFT, null, $item['online'], ['found_on_router_ids' => $elsewhere])
                : $this->customerFinding($item['router'], $item['customer'], self::KIND_SECRET_MISSING, self::SEVERITY_DRIFT, null, $item['online']);
        }

        foreach ($routerReports as &$report) {
            foreach ($findings as $finding) {
                if ($finding['router_id'] === $report['router_id']) {
                    $report['counts'][$finding['kind']] = ($report['counts'][$finding['kind']] ?? 0) + 1;
                }
            }
            ksort($report['counts']);
        }
        unset($report);

        return [
            'run_id' => (string) Str::uuid(),
            'read_only' => true,
            'started_at' => $startedAt->toIso8601String(),
            'finished_at' => now()->toIso8601String(),
            'duration_ms' => (int) round(($this->now() - $t0) * 1000),
            'routers' => $routerReports,
            'totals' => $this->totals($findings),
            'findings' => $findings,
        ];
    }

    /**
     * Persist the findings of one run and the last-run summary. DB only.
     */
    public function store(array $report): int
    {
        $detectedAt = now();
        $rows = array_map(static fn (array $f): array => [
            'run_id' => $report['run_id'],
            'router_id' => $f['router_id'],
            'customer_id' => $f['customer_id'],
            'pppoe_user' => $f['pppoe_user'],
            'kind' => $f['kind'],
            'severity' => $f['severity'],
            'db_status' => $f['db_status'],
            'router_profile' => $f['router_profile'],
            'router_disabled' => $f['router_disabled'],
            'online' => $f['online'],
            'detail' => $f['detail'] === [] ? null : json_encode($f['detail']),
            'detected_at' => $detectedAt,
        ], $report['findings']);

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('enforcement_drift')->insert($chunk);
        }

        $retentionDays = max(1, (int) config('services.network_reconcile.retention_days', 14));
        DB::table('enforcement_drift')->where('detected_at', '<', now()->subDays($retentionDays))->delete();

        $summary = $report;
        unset($summary['findings']);
        Setting::set(self::SUMMARY_SETTING, $summary, 'json', 'ops', 'Last router<->DB isolation reconciliation');

        return count($rows);
    }

    /**
     * Count-only NOC summary (no customer names, usernames or phone numbers).
     */
    public function summaryMessage(array $report): string
    {
        $totals = $report['totals'];
        $lines = [
            '[eBilling] Rekonsiliasi isolir router vs DB (read-only)',
            sprintf('Drift: %d | Info: %d | Error router: %d', $totals['drift'], $totals['info'], $totals['error']),
        ];
        foreach ($report['routers'] as $router) {
            $parts = [];
            foreach ($router['counts'] as $kind => $count) {
                $parts[] = "{$kind}={$count}";
            }
            $lines[] = sprintf('- %s (#%d) %s%s', $router['name'], $router['router_id'], $router['status'], $parts === [] ? '' : ': '.implode(', ', $parts));
        }
        $lines[] = 'Detail: php artisan billing:reconcile-network --json (tabel enforcement_drift).';

        return implode("\n", $lines);
    }

    /** @param  array{name: string, profile: string, disabled: bool}  $secret */
    private function classify(Router $router, Customer $customer, array $secret, bool $online, string $isolationProfile): ?array
    {
        $status = strtolower((string) $customer->status);
        $onIsolation = strcasecmp($secret['profile'], $isolationProfile) === 0;
        $expectIsolated = in_array($status, self::ISOLATED_STATUSES, true);

        if ($secret['disabled']) {
            // Active customer with a disabled secret cannot log in: real drift.
            // Isolated customer is blocked anyway, but a reconnect only swaps
            // the profile and would leave them offline: report as info.
            return $this->customerFinding($router, $customer, self::KIND_SECRET_DISABLED, $expectIsolated ? self::SEVERITY_INFO : self::SEVERITY_DRIFT, $secret, $online);
        }

        if (! $expectIsolated && $onIsolation) {
            return $this->customerFinding($router, $customer, self::KIND_ACTIVE_BUT_ISOLATED, self::SEVERITY_DRIFT, $secret, $online);
        }

        if ($expectIsolated && ! $onIsolation) {
            return $this->customerFinding($router, $customer, self::KIND_ISOLATED_BUT_OPEN, self::SEVERITY_DRIFT, $secret, $online, [
                'expected_profile' => $isolationProfile,
            ]);
        }

        return null;
    }

    /** @param  list<int>  $routerIds */
    private function customersByRouter(array $routerIds): Collection
    {
        if ($routerIds === []) {
            return collect();
        }

        return Customer::query()
            ->ebilling()
            ->whereIn('router_id', $routerIds)
            ->whereIn('status', array_merge(self::OPEN_STATUSES, self::ISOLATED_STATUSES))
            ->whereNotNull('pppoe_user')
            ->where('pppoe_user', '!=', '')
            ->orderBy('id')
            ->get(['id', 'code', 'router_id', 'pppoe_user', 'status'])
            ->groupBy('router_id');
    }

    /** @return array<string, array{name: string, profile: string, disabled: bool}> */
    private function secretMap(array $secrets): array
    {
        $map = [];
        foreach ($secrets as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $disabled = $row['disabled'] ?? false;
            $map[strtolower($name)] = [
                'name' => $name,
                'profile' => trim((string) ($row['profile'] ?? '')),
                'disabled' => is_bool($disabled)
                    ? $disabled
                    : in_array(strtolower(trim((string) $disabled)), ['true', 'yes', '1'], true),
            ];
        }

        return $map;
    }

    private function routerUsesRadius(): bool
    {
        try {
            $source = $this->mikrotik->getPppAuthenticationSource();

            return (bool) ($source['readable'] ?? false) && (bool) ($source['use_radius'] ?? false);
        } catch (\Throwable) {
            return false;
        }
    }

    private function isolationProfileName(Router $router): string
    {
        $configured = trim((string) ($router->isolation_profile ?? ''));

        return $configured !== '' ? $configured : 'isolirebilling';
    }

    private function routerFinding(Router $router, string $kind, string $severity, array $detail = []): array
    {
        return [
            'router_id' => $router->id,
            'router' => $router->name,
            'customer_id' => null,
            'customer_code' => null,
            'pppoe_user' => null,
            'kind' => $kind,
            'severity' => $severity,
            'db_status' => null,
            'router_profile' => null,
            'router_disabled' => null,
            'online' => null,
            'detail' => $detail,
        ];
    }

    private function customerFinding(Router $router, Customer $customer, string $kind, string $severity, ?array $secret, bool $online, array $detail = []): array
    {
        return [
            'router_id' => $router->id,
            'router' => $router->name,
            'customer_id' => $customer->id,
            'customer_code' => $customer->code,
            'pppoe_user' => $customer->pppoe_user,
            'kind' => $kind,
            'severity' => $severity,
            'db_status' => (string) $customer->status,
            'router_profile' => $secret['profile'] ?? null,
            'router_disabled' => $secret['disabled'] ?? null,
            'online' => $online,
            'detail' => $detail,
        ];
    }

    private function totals(array $findings): array
    {
        $totals = [
            self::SEVERITY_DRIFT => 0,
            self::SEVERITY_INFO => 0,
            self::SEVERITY_ERROR => 0,
            'by_kind' => [],
        ];
        foreach ($findings as $finding) {
            $totals[$finding['severity']]++;
            $totals['by_kind'][$finding['kind']] = ($totals['by_kind'][$finding['kind']] ?? 0) + 1;
        }
        ksort($totals['by_kind']);

        return $totals;
    }

    private function now(): float
    {
        return $this->clock !== null ? (float) ($this->clock)() : microtime(true);
    }
}
