<?php

namespace App\Console\Commands;

use App\Jobs\IsolateCustomerJob;
use App\Jobs\ReconnectCustomerJob;
use App\Models\Customer;
use App\Services\MikrotikService;
use Illuminate\Console\Command;

class TestIsolationFlow extends Command
{
    protected $signature = 'network:test-isolation
        {customer_id : Customer ID to test}
        {--yes : Actually change the router and customer state}
        {--restore : Reconnect and restore the original profile after isolation}';

    protected $description = 'Safely preflight or run a live MikroTik isolation/reconnection test for one customer';

    public function handle(MikrotikService $mikrotik): int
    {
        $customer = Customer::with(['router', 'package'])->find($this->argument('customer_id'));

        if (! $customer) {
            $this->error('Customer not found.');

            return self::FAILURE;
        }

        if (! $customer->router || ! $customer->pppoe_user) {
            $this->error('Customer must have both a router and PPPoE username.');

            return self::FAILURE;
        }

        $this->info("Customer: {$customer->name} ({$customer->pppoe_user})");
        $this->info("Router: {$customer->router->name}");

        try {
            $mikrotik->connect($customer->router, ['timeout' => 10, 'attempts' => 1]);
            $secret = $mikrotik->getPPPSecret($customer->pppoe_user);

            if (! $secret) {
                $this->error('PPPoE secret was not found on the router.');

                return self::FAILURE;
            }

            $originalProfile = $secret['profile'] ?? 'default';
            $isolationProfile = $customer->router->isolation_profile ?: 'isolirebilling';

            $this->info("Current router profile: {$originalProfile}");
            $this->info("Isolation profile target: {$isolationProfile}");

            if (! $this->option('yes')) {
                $this->warn('Preflight only. Re-run with --yes to isolate this customer.');

                return self::SUCCESS;
            }
        } catch (\Throwable $e) {
            $this->error("Preflight failed: {$e->getMessage()}");

            return self::FAILURE;
        } finally {
            $mikrotik->disconnect();
        }

        try {
            $this->info('Running isolation job...');
            IsolateCustomerJob::dispatchSync($customer);
            $customer->refresh();

            $mikrotik->connect($customer->router, ['timeout' => 10, 'attempts' => 1]);
            $isolatedSecret = $mikrotik->getPPPSecret($customer->pppoe_user);
            $isolatedProfile = $isolatedSecret['profile'] ?? null;
            $mikrotik->disconnect();

            if (strcasecmp((string) $isolatedProfile, $isolationProfile) !== 0) {
                $this->error("Isolation verification failed. Router profile is '{$isolatedProfile}'.");

                return self::FAILURE;
            }

            $this->info('Isolation verified.');

            if (! $this->option('restore')) {
                $this->warn('Customer was left isolated because --restore was not provided.');

                return self::SUCCESS;
            }

            $this->info('Running reconnection job...');
            ReconnectCustomerJob::dispatchSync($customer);
            $customer->refresh();

            $mikrotik->connect($customer->router, ['timeout' => 10, 'attempts' => 1]);
            $restoredSecret = $mikrotik->getPPPSecret($customer->pppoe_user);
            $restoredProfile = $restoredSecret['profile'] ?? null;
            $mikrotik->disconnect();

            if ($restoredProfile !== $originalProfile) {
                $this->error("Reconnection verification failed. Expected '{$originalProfile}', got '{$restoredProfile}'.");

                return self::FAILURE;
            }

            $this->info('Reconnection verified. Original profile restored.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Live test failed: {$e->getMessage()}");

            return self::FAILURE;
        } finally {
            $mikrotik->disconnect();
        }
    }
}
