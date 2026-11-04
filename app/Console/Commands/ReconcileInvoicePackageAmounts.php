<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\InvoicePackageSyncService;
use Illuminate\Console\Command;

class ReconcileInvoicePackageAmounts extends Command
{
    protected $signature = 'billing:reconcile-package-invoices
                            {--apply : Apply only safe corrections; default is dry-run}
                            {--customer= : Limit to one customer id}';

    protected $description = 'Dry-run/apply safe invoice corrections from the current subscription package';

    public function handle(InvoicePackageSyncService $sync): int
    {
        if ($this->option('apply')) {
            $summary = $sync->syncAll($this->option('customer') !== null ? (int) $this->option('customer') : null);
            $this->info(sprintf(
                'APPLY customers=%d updated=%d reconciled_paid=%d review_skipped=%d',
                $summary['customers'],
                $summary['updated'],
                $summary['reconciled_paid'],
                $summary['skipped']
            ));

            return self::SUCCESS;
        }

        $query = Customer::query()
            ->ebilling()
            ->whereNotNull('package_id')
            ->with('package');

        if ($this->option('customer') !== null) {
            $query->whereKey((int) $this->option('customer'));
        }

        $summary = ['customers' => 0, 'updated' => 0, 'skipped' => 0, 'reconciled_paid' => 0];

        $query->chunkById(200, function ($customers) use (&$summary): void {
            foreach ($customers as $customer) {
                $summary['customers']++;

                $price = $customer->package?->price;
                if ($price === null) {
                    continue;
                }

                $candidates = $customer->invoices()
                    ->where('status', 'unpaid')
                    ->with('transactions:id,invoice_id,amount,status')
                    ->get(['id', 'amount', 'status']);

                foreach ($candidates as $invoice) {
                    $transactions = $invoice->transactions->whereIn('status', ['verified', 'paid']);
                    $paid = $transactions->sum(fn ($transaction) => (float) $transaction->amount);
                    if ($transactions->isEmpty() || abs($paid - (float) $price) < 0.01) {
                        $this->line(sprintf(
                            '%s invoice #%d: %s -> %s%s',
                            $customer->name,
                            $invoice->id,
                            $invoice->amount,
                            $price,
                            $transactions->isEmpty() ? '' : ' (mark paid)'
                        ));
                    }
                }
            }
        });

        $mode = $this->option('apply') ? 'APPLY' : 'DRY-RUN';
        $this->info(sprintf(
            '%s customers=%d updated=%d reconciled_paid=%d review_skipped=%d',
            $mode,
            $summary['customers'],
            $summary['updated'],
            $summary['reconciled_paid'],
            $summary['skipped']
        ));

        return self::SUCCESS;
    }
}
