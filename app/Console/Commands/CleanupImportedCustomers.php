<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\GuardsCustomerArchive;
use App\Models\Customer;
use App\Services\Lifecycle\LifecycleBackstop;
use Illuminate\Console\Command;

class CleanupImportedCustomers extends Command
{
    use GuardsCustomerArchive;

    public const SOURCE = 'cli_cleanup_imported';

    protected $signature = 'customers:cleanup-imported {--apply : Soft-delete eligible IMP-* customers}';

    protected $description = 'Soft-delete imported MikroTik customer rows that have no invoice history';

    public function handle(): int
    {
        $apply = $this->option('apply');

        $total = Customer::importedFromMikrotik()->count();
        $eligible = Customer::importedFromMikrotik()->doesntHave('invoices')->count();
        $blocked = $total - $eligible;

        $this->info("Imported rows found: {$total}");
        $this->info("Candidates (no invoice history, before the lifecycle guard): {$eligible}");
        $this->info("Skipped because invoice history exists: {$blocked}");

        if (! $apply) {
            $this->previewArchiveVerdicts(
                Customer::importedFromMikrotik()->doesntHave('invoices')->pluck('customers.id'),
                self::SOURCE,
            );
            $this->warn('Dry run only. Re-run with --apply to soft-delete eligible rows.');

            return self::SUCCESS;
        }

        $deleted = 0;
        $skipped = 0;

        Customer::importedFromMikrotik()
            ->doesntHave('invoices')
            ->with(['router', 'olt'])
            ->orderBy('id')
            ->chunkById(200, function ($customers) use (&$deleted, &$skipped) {
                foreach ($customers as $customer) {
                    // Roadmap 1.3: PPPoE/ONU/traffic in use or unknown => skipped (CLI never overrides).
                    $decision = $this->archiveDecision($customer, self::SOURCE);
                    if (! $decision->allowed()) {
                        $skipped++;
                        $this->warn($this->guardLine($customer, $decision, 'skipped'));

                        continue;
                    }

                    LifecycleBackstop::allow([(int) $customer->getKey()], self::SOURCE, fn () => $customer->delete());
                    $deleted++;
                }
            });

        $this->info("Soft-deleted imported rows: {$deleted}");
        if ($skipped > 0) {
            $this->warn("Skipped by lifecycle guard (in use or unknown): {$skipped}");
        }

        return self::SUCCESS;
    }
}
