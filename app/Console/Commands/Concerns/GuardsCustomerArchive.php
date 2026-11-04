<?php

namespace App\Console\Commands\Concerns;

use App\Models\Customer;
use App\Services\Lifecycle\CustomerLifecycleGuard;
use App\Services\Lifecycle\GuardContext;
use App\Services\Lifecycle\GuardDecision;
use App\Services\Lifecycle\GuardSignal;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Roadmap 1.3 A7: manual archive artisan commands go through the lifecycle
 * guard. The CLI never overrides: a customer whose PPPoE/ONU/traffic is in
 * use or unknown is SKIPPED and the reason is printed. Output holds ids and
 * codes only (no names, phones).
 */
trait GuardsCustomerArchive
{
    protected function archiveDecision(Customer $customer, string $source): GuardDecision
    {
        try {
            return app(CustomerLifecycleGuard::class)->canArchive($customer, GuardContext::automated($source));
        } catch (Throwable $e) {
            Log::warning('[LifecycleGuard] cli archive check failed', ['customer_id' => $customer->id, 'exception_class' => $e::class]);

            return GuardDecision::fromSignals('archive', [
                GuardSignal::unknown(GuardSignal::PPPOE, 'guard_failed', 'Pagar gagal memeriksa status pelanggan.'),
            ]);
        }
    }

    protected function guardLine(Customer $customer, GuardDecision $decision, string $result): string
    {
        return sprintf(
            'customer=%d code=%s decision=%s reasons=%s result=%s',
            $customer->id,
            $customer->code,
            $decision->verdict,
            implode(',', $decision->reasonCodes()),
            $result,
        );
    }

    /**
     * Dry run: print the guard verdict of each candidate (full models are
     * reloaded because the candidate lists select only a few columns).
     *
     * @param  iterable<int>  $ids
     */
    protected function previewArchiveVerdicts(iterable $ids, string $source): void
    {
        $ids = collect($ids)->map(fn ($id) => (int) $id)->unique()->values();
        $counts = [GuardDecision::ALLOW => 0, GuardDecision::DENY => 0, GuardDecision::UNKNOWN => 0];

        Customer::query()->whereKey($ids->all())->with(['router', 'olt'])->orderBy('id')->chunkById(100, function ($customers) use (&$counts, $source) {
            foreach ($customers as $customer) {
                $decision = $this->archiveDecision($customer, $source);
                $counts[$decision->verdict]++;
                $this->line($this->guardLine($customer, $decision, 'dry-run'));
            }
        });

        $this->info(sprintf(
            'Lifecycle guard verdicts (the CLI never overrides; deny/unknown are skipped on --apply): allow=%d deny=%d unknown=%d',
            $counts[GuardDecision::ALLOW],
            $counts[GuardDecision::DENY],
            $counts[GuardDecision::UNKNOWN],
        ));
    }
}
