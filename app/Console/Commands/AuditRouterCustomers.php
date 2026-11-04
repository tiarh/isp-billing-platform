<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Router;
use App\Services\MikrotikService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class AuditRouterCustomers extends Command
{
    protected $signature = 'routers:audit-customers {router? : Optional router ID to audit}';

    protected $description = 'Read-only comparison of live MikroTik PPPoE users against eBilling customers';

    public function handle(MikrotikService $mikrotik): int
    {
        $routers = $this->routers();

        if ($routers->isEmpty()) {
            $this->warn('No routers found to audit.');

            return self::SUCCESS;
        }

        $ebillingCustomers = Customer::ebilling()
            ->select('id', 'code', 'name', 'pppoe_user', 'router_id')
            ->get();
        $softDeletedCustomers = Customer::onlyTrashed()
            ->ebilling()
            ->select('id', 'code', 'name', 'pppoe_user', 'router_id')
            ->get();

        $ebillingWithPppoe = $ebillingCustomers
            ->filter(fn (Customer $customer) => $this->validUsername($customer->pppoe_user));
        $ebillingWithoutPppoe = $ebillingCustomers->count() - $ebillingWithPppoe->count();
        $ebillingUsernames = $ebillingWithPppoe->pluck('pppoe_user')->unique()->values();
        $softDeletedUsernames = $softDeletedCustomers
            ->pluck('pppoe_user')
            ->filter(fn ($username) => $this->validUsername($username))
            ->unique()
            ->values();

        $routerRows = [];
        $allSecrets = collect();
        $failedRouters = 0;

        foreach ($routers as $router) {
            $this->line("Auditing {$router->name} ({$router->ip_address})...");

            try {
                $mikrotik->connect($router, ['timeout' => 10, 'attempts' => 1]);
                $secrets = collect($mikrotik->getPPPSecrets())
                    ->map(fn (array $secret) => $this->secretRow($router, $secret))
                    ->filter(fn (array $secret) => $this->validUsername($secret['pppoe_user']))
                    ->values();
            } catch (\Throwable $e) {
                $failedRouters++;
                $routerRows[] = [
                    $router->name,
                    $router->ip_address,
                    'ERROR',
                    'ERROR',
                    'ERROR',
                    'ERROR',
                    'ERROR',
                    'ERROR',
                    $e->getMessage(),
                ];

                continue;
            } finally {
                $mikrotik->disconnect();
            }

            $routerUsernames = $secrets->pluck('pppoe_user')->unique()->values();
            $matched = $routerUsernames->intersect($ebillingUsernames)->count();
            $mikrotikOnly = $routerUsernames->diff($ebillingUsernames)->count();
            $assignedMissing = $ebillingWithPppoe
                ->where('router_id', $router->id)
                ->pluck('pppoe_user')
                ->unique()
                ->diff($routerUsernames)
                ->count();

            $routerRows[] = [
                $router->name,
                $router->ip_address,
                $secrets->count(),
                $secrets->where('disabled', false)->count(),
                $secrets->where('disabled', true)->count(),
                $matched,
                $mikrotikOnly,
                $assignedMissing,
                '',
            ];

            $allSecrets = $allSecrets->merge($secrets);
        }

        $mikrotikUsernames = $allSecrets->pluck('pppoe_user')->unique()->values();
        $matchedUsernames = $ebillingUsernames->intersect($mikrotikUsernames);
        $mikrotikOnlyUsernames = $mikrotikUsernames->diff($ebillingUsernames);
        $ebillingOnlyUsernames = $ebillingUsernames->diff($mikrotikUsernames);

        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            [
                ['Routers audited', $routers->count()],
                ['Routers failed', $failedRouters],
                ['eBilling customers total', $ebillingCustomers->count()],
                ['eBilling with PPPoE', $ebillingWithPppoe->count()],
                ['eBilling without PPPoE', $ebillingWithoutPppoe],
                ['MikroTik PPPoE total', $allSecrets->count()],
                ['MikroTik enabled total', $allSecrets->where('disabled', false)->count()],
                ['MikroTik disabled total', $allSecrets->where('disabled', true)->count()],
                ['Synced/matched total', $matchedUsernames->count()],
                ['MikroTik-only total', $mikrotikOnlyUsernames->count()],
                ['eBilling-only total', $ebillingOnlyUsernames->count()],
                ['Soft-deleted matches', $softDeletedUsernames->intersect($mikrotikUsernames)->count()],
            ]
        );

        $this->newLine();
        $this->table(
            ['Router', 'IP', 'PPP Total', 'Enabled', 'Disabled', 'Matched', 'MikroTik-only', 'Assigned Missing', 'Error'],
            $routerRows
        );

        $this->printDuplicateWarnings($ebillingWithPppoe, $allSecrets);

        return $failedRouters > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function routers(): Collection
    {
        $query = Router::query();

        if ($routerId = $this->argument('router')) {
            return $query->whereKey($routerId)->get();
        }

        return $query->where('is_active', true)->orderBy('name')->get();
    }

    private function secretRow(Router $router, array $secret): array
    {
        return [
            'router_id' => $router->id,
            'router_name' => $router->name,
            'pppoe_user' => (string) ($secret['name'] ?? ''),
            'disabled' => filter_var($secret['disabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    private function validUsername(mixed $username): bool
    {
        return is_string($username) && trim($username) !== '';
    }

    private function printDuplicateWarnings(Collection $ebillingWithPppoe, Collection $allSecrets): void
    {
        $ebillingDuplicates = $ebillingWithPppoe
            ->groupBy('pppoe_user')
            ->filter(fn (Collection $customers) => $customers->count() > 1)
            ->keys();

        $mikrotikDuplicates = $allSecrets
            ->groupBy('pppoe_user')
            ->filter(fn (Collection $secrets) => $secrets->pluck('router_id')->unique()->count() > 1)
            ->keys();

        if ($ebillingDuplicates->isNotEmpty()) {
            $this->warn('Duplicate eBilling PPPoE usernames: '.$ebillingDuplicates->implode(', '));
        }

        if ($mikrotikDuplicates->isNotEmpty()) {
            $this->warn('Duplicate MikroTik PPPoE usernames across routers: '.$mikrotikDuplicates->implode(', '));
        }
    }
}
