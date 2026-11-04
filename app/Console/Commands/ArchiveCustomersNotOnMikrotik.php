<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\GuardsCustomerArchive;
use App\Models\Customer;
use App\Models\Router;
use App\Services\Lifecycle\LifecycleBackstop;
use App\Services\MikrotikService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ArchiveCustomersNotOnMikrotik extends Command
{
    use GuardsCustomerArchive;

    public const SOURCE = 'cli_archive_not_on_mikrotik';

    protected $signature = 'customers:archive-not-on-mikrotik
                            {--apply : Soft-delete archive candidates}
                            {--backup-confirmed : Confirm a database backup exists before applying}
                            {--use-snapshot : Use locally persisted MikroTik sync statuses instead of connecting to routers}
                            {--timeout=15 : MikroTik connection timeout in seconds}
                            {--retries=5 : MikroTik audit attempts per router before aborting}
                            {--retry-delay=10 : Seconds to wait between MikroTik audit attempts}';

    protected $description = 'Archive eBilling customers whose PPPoE username is absent from live MikroTik routers';

    public function handle(MikrotikService $mikrotik): int
    {
        $apply = (bool) $this->option('apply');
        $backupConfirmed = (bool) $this->option('backup-confirmed');
        $useSnapshot = (bool) $this->option('use-snapshot');
        $timeout = max(1, (int) $this->option('timeout'));
        $retries = max(1, (int) $this->option('retries'));
        $retryDelay = max(0, (int) $this->option('retry-delay'));

        if ($apply && ! $backupConfirmed) {
            $this->error('Refusing to apply: pass --backup-confirmed after taking a production database backup.');

            return self::FAILURE;
        }

        $routers = Router::where('is_active', true)->orderBy('name')->get();
        if ($routers->isEmpty()) {
            $this->warn('No active routers found. Archive aborted.');

            return self::FAILURE;
        }

        if ($useSnapshot) {
            return $this->handleSnapshotArchive($routers, $apply);
        }

        $connectionCheck = $this->checkRouterConnections($routers, $mikrotik, $timeout, $retries, $retryDelay);

        if ($connectionCheck['failed_routers']->isNotEmpty()) {
            $this->error('Archive aborted because one or more router connections failed.');
            $this->table(['Router', 'IP', 'Error'], $connectionCheck['failed_routers']->all());

            return self::FAILURE;
        }

        $audit = $this->readMikrotikSecrets($routers, $mikrotik, $timeout, $retries, $retryDelay);

        if ($audit['failed_routers']->isNotEmpty()) {
            $this->error('Archive aborted because one or more routers could not be audited.');
            $this->table(['Router', 'IP', 'Error'], $audit['failed_routers']->all());

            return self::FAILURE;
        }

        $duplicateUsernames = $audit['all_secrets']
            ->groupBy('pppoe_user')
            ->filter(fn (Collection $secrets) => $secrets->pluck('router_id')->unique()->count() > 1)
            ->keys()
            ->values();

        if ($duplicateUsernames->isNotEmpty()) {
            $this->error('Archive aborted because duplicate MikroTik PPPoE usernames exist across routers.');
            $this->line($duplicateUsernames->implode(', '));

            return self::FAILURE;
        }

        $mikrotikUsernames = $audit['all_secrets']->pluck('pppoe_user')->unique()->values();
        $disabledUsernames = $audit['all_secrets']
            ->where('disabled', true)
            ->pluck('pppoe_user')
            ->unique()
            ->values();

        $customers = Customer::ebilling()
            ->with('router:id,name')
            ->select('id', 'code', 'name', 'pppoe_user', 'router_id', 'status')
            ->get();

        $candidates = $customers
            ->filter(fn (Customer $customer) => ! $this->validUsername($customer->pppoe_user)
                || ! $mikrotikUsernames->contains($customer->pppoe_user))
            ->values();
        $matchedCustomers = $customers
            ->filter(fn (Customer $customer) => $this->validUsername($customer->pppoe_user)
                && $mikrotikUsernames->contains($customer->pppoe_user))
            ->values();
        $disabledMatches = $matchedCustomers
            ->filter(fn (Customer $customer) => $disabledUsernames->contains($customer->pppoe_user))
            ->count();

        $this->printSummary($routers, $audit['router_rows'], $audit['all_secrets'], $customers, $matchedCustomers, $disabledMatches, $candidates);

        if (! $apply) {
            $this->previewArchiveVerdicts($candidates->pluck('id'), self::SOURCE);
            $this->warn('Dry run only. Re-run with --apply --backup-confirmed to soft-delete archive candidates.');

            return self::SUCCESS;
        }

        $deleted = $this->archiveCandidates($candidates);

        $this->info("Soft-deleted archive candidates: {$deleted}");

        return self::SUCCESS;
    }

    private function handleSnapshotArchive(Collection $routers, bool $apply): int
    {
        $customers = Customer::ebilling()
            ->with('router:id,name')
            ->select('id', 'code', 'name', 'pppoe_user', 'router_id', 'status', 'mikrotik_sync_status', 'mikrotik_sync_checked_at')
            ->get();

        $candidates = $customers
            ->filter(fn (Customer $customer) => ! $this->validUsername($customer->pppoe_user)
                || $customer->router_id === null
                || $customer->mikrotik_sync_status === 'missing')
            ->values();

        $kept = $customers
            ->filter(fn (Customer $customer) => $this->validUsername($customer->pppoe_user)
                && $customer->router_id !== null
                && $customer->mikrotik_sync_status !== 'missing')
            ->values();

        $this->printSnapshotSummary($routers, $customers, $kept, $candidates);

        if (! $apply) {
            $this->previewArchiveVerdicts($candidates->pluck('id'), self::SOURCE);
            $this->warn('Snapshot dry run only. Re-run with --use-snapshot --apply --backup-confirmed to soft-delete archive candidates.');

            return self::SUCCESS;
        }

        $deleted = $this->archiveCandidates($candidates);

        $this->info("Soft-deleted archive candidates: {$deleted}");

        return self::SUCCESS;
    }

    private function archiveCandidates(Collection $candidates): int
    {
        $deleted = 0;
        $skipped = 0;
        $candidateIds = $candidates->pluck('id')->all();

        Customer::ebilling()
            ->whereKey($candidateIds)
            ->with(['router', 'olt'])
            ->orderBy('id')
            ->chunkById(100, function ($customers) use (&$deleted, &$skipped) {
                DB::transaction(function () use ($customers, &$deleted, &$skipped) {
                    foreach ($customers as $customer) {
                        // Roadmap 1.3: PPPoE/ONU/traffic in use or unknown => skipped (CLI never overrides).
                        $decision = $this->archiveDecision($customer, self::SOURCE);
                        if (! $decision->allowed()) {
                            $skipped++;
                            $this->warn($this->guardLine($customer, $decision, 'skipped'));

                            continue;
                        }

                        activity()
                            ->performedOn($customer)
                            ->withProperties([
                                'reason' => 'not_on_live_mikrotik',
                                'pppoe_user' => $customer->pppoe_user,
                                'router' => $customer->router?->name,
                                'status' => $customer->status,
                            ])
                            ->log('archived_not_on_mikrotik');

                        LifecycleBackstop::allow([(int) $customer->getKey()], self::SOURCE, fn () => $customer->delete());
                        $deleted++;
                    }
                });
            });

        if ($skipped > 0) {
            $this->warn("Skipped by lifecycle guard (in use or unknown): {$skipped}");
        }

        return $deleted;
    }

    private function checkRouterConnections(Collection $routers, MikrotikService $mikrotik, int $timeout, int $retries, int $retryDelay): array
    {
        $failedRouters = collect();

        foreach ($routers as $router) {
            $connected = false;
            $lastError = null;

            for ($attempt = 1; $attempt <= $retries; $attempt++) {
                try {
                    $mikrotik->connect($router, ['timeout' => $timeout, 'attempts' => 1]);
                    $connected = true;
                    $this->line("Checking router connection {$router->name} ({$router->ip_address})... attempt {$attempt}/{$retries} succeeded");
                    break;
                } catch (\Throwable $e) {
                    $lastError = $e;
                    $this->warn("Checking router connection {$router->name} ({$router->ip_address})... attempt {$attempt}/{$retries} failed: {$e->getMessage()}");

                    if ($attempt < $retries) {
                        $this->line("Retrying in {$retryDelay} seconds...");

                        if ($retryDelay > 0) {
                            sleep($retryDelay);
                        }
                    }
                } finally {
                    $mikrotik->disconnect();
                }
            }

            if (! $connected) {
                $failedRouters->push([$router->name, $router->ip_address, $lastError?->getMessage() ?? 'Unknown MikroTik connection failure']);
            }
        }

        return [
            'failed_routers' => $failedRouters,
        ];
    }

    private function readMikrotikSecrets(Collection $routers, MikrotikService $mikrotik, int $timeout, int $retries, int $retryDelay): array
    {
        $allSecrets = collect();
        $routerRows = [];
        $failedRouters = collect();

        foreach ($routers as $router) {
            $secrets = null;
            $lastError = null;

            for ($attempt = 1; $attempt <= $retries; $attempt++) {
                try {
                    $mikrotik->connect($router, ['timeout' => $timeout, 'attempts' => 1]);
                    $secrets = collect($mikrotik->getPPPSecrets())
                        ->map(fn (array $secret) => $this->secretRow($router, $secret))
                        ->filter(fn (array $secret) => $this->validUsername($secret['pppoe_user']))
                        ->values();

                    $this->line("Auditing {$router->name} ({$router->ip_address})... attempt {$attempt}/{$retries} succeeded");
                    break;
                } catch (\Throwable $e) {
                    $lastError = $e;
                    $this->warn("Auditing {$router->name} ({$router->ip_address})... attempt {$attempt}/{$retries} failed: {$e->getMessage()}");

                    if ($attempt < $retries) {
                        $this->line("Retrying in {$retryDelay} seconds...");

                        if ($retryDelay > 0) {
                            sleep($retryDelay);
                        }
                    }
                } finally {
                    $mikrotik->disconnect();
                }
            }

            if ($secrets === null) {
                $failedRouters->push([$router->name, $router->ip_address, $lastError?->getMessage() ?? 'Unknown MikroTik audit failure']);

                continue;
            }

            $routerRows[] = [
                $router->name,
                $router->ip_address,
                $secrets->count(),
                $secrets->where('disabled', false)->count(),
                $secrets->where('disabled', true)->count(),
            ];

            $allSecrets = $allSecrets->merge($secrets);
        }

        return [
            'all_secrets' => $allSecrets,
            'router_rows' => $routerRows,
            'failed_routers' => $failedRouters,
        ];
    }

    private function printSummary(
        Collection $routers,
        array $routerRows,
        Collection $allSecrets,
        Collection $customers,
        Collection $matchedCustomers,
        int $disabledMatches,
        Collection $candidates
    ): void {
        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            [
                ['Routers audited', $routers->count()],
                ['eBilling customers total', $customers->count()],
                ['MikroTik PPPoE total', $allSecrets->count()],
                ['MikroTik enabled total', $allSecrets->where('disabled', false)->count()],
                ['MikroTik disabled total', $allSecrets->where('disabled', true)->count()],
                ['Matched kept', $matchedCustomers->count()],
                ['Disabled matches kept', $disabledMatches],
                ['Archive candidates', $candidates->count()],
            ]
        );

        $this->newLine();
        $this->table(['Router', 'IP', 'PPP Total', 'Enabled', 'Disabled'], $routerRows);

        $this->newLine();
        $this->table(
            ['Status', 'Archive Candidates'],
            $candidates
                ->groupBy('status')
                ->map(fn (Collection $customers, string $status) => [$status, $customers->count()])
                ->values()
                ->all()
        );

        $this->newLine();
        $this->table(
            ['Code', 'Name', 'PPPoE', 'Router', 'Status'],
            $candidates
                ->take(50)
                ->map(fn (Customer $customer) => [
                    $customer->code,
                    $customer->name,
                    $customer->pppoe_user ?? '',
                    $customer->router?->name ?? '',
                    $customer->status,
                ])
                ->all()
        );
    }

    private function printSnapshotSummary(
        Collection $routers,
        Collection $customers,
        Collection $kept,
        Collection $candidates
    ): void {
        $this->newLine();
        $this->warn('Using local MikroTik sync snapshot. Routers will not be contacted.');

        $this->table(
            ['Metric', 'Count'],
            [
                ['Active routers in scope', $routers->count()],
                ['eBilling customers total', $customers->count()],
                ['Snapshot kept', $kept->count()],
                ['Snapshot missing candidates', $customers->where('mikrotik_sync_status', 'missing')->count()],
                ['No router candidates', $customers->whereNull('router_id')->count()],
                ['Blank PPPoE candidates', $customers->filter(fn (Customer $customer) => ! $this->validUsername($customer->pppoe_user))->count()],
                ['Archive candidates', $candidates->count()],
            ]
        );

        $this->newLine();
        $this->table(
            ['Router', 'Last Scanned', 'Sync Status', 'Message'],
            $routers
                ->map(fn (Router $router) => [
                    $router->name,
                    optional($router->last_scanned_at)->toDateTimeString() ?? '',
                    $router->sync_status,
                    $router->sync_message ?? '',
                ])
                ->all()
        );

        $this->newLine();
        $this->table(
            ['Status', 'Archive Candidates'],
            $candidates
                ->groupBy('status')
                ->map(fn (Collection $customers, string $status) => [$status, $customers->count()])
                ->values()
                ->all()
        );

        $this->newLine();
        $this->table(
            ['Code', 'Name', 'PPPoE', 'Router', 'Status', 'MikroTik Snapshot'],
            $candidates
                ->take(50)
                ->map(fn (Customer $customer) => [
                    $customer->code,
                    $customer->name,
                    $customer->pppoe_user ?? '',
                    $customer->router?->name ?? '',
                    $customer->status,
                    $customer->mikrotik_sync_status ?? 'unknown',
                ])
                ->all()
        );
    }

    private function secretRow(Router $router, array $secret): array
    {
        return [
            'router_id' => $router->id,
            'pppoe_user' => (string) ($secret['name'] ?? ''),
            'disabled' => filter_var($secret['disabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    private function validUsername(mixed $username): bool
    {
        return is_string($username) && trim($username) !== '';
    }
}
