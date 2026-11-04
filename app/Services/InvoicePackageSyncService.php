<?php

namespace App\Services;

use App\Models\Customer;

class InvoicePackageSyncService
{
    /**
     * Reconcile all eBilling customers in bounded chunks after a legacy invoice
     * import. This prevents the legacy amount from overriding the live package
     * price for the current billing state.
     *
     * @return array{customers:int, updated:int, skipped:int, reconciled_paid:int}
     */
    public function syncAll(?int $customerId = null): array
    {
        $summary = ['customers' => 0, 'updated' => 0, 'skipped' => 0, 'reconciled_paid' => 0];

        Customer::query()
            ->ebilling()
            ->whereNotNull('package_id')
            ->when($customerId, fn ($query) => $query->whereKey($customerId))
            ->with('package')
            ->chunkById(200, function ($customers) use (&$summary): void {
                foreach ($customers as $customer) {
                    $result = $this->syncCustomer($customer);
                    $summary['customers']++;
                    $summary['updated'] += $result['updated'];
                    $summary['skipped'] += $result['skipped'];
                    $summary['reconciled_paid'] += $result['reconciled_paid'];
                }
            });

        return $summary;
    }

    /**
     * Align active unpaid invoices with the customer's current package.
     *
     * A transaction is never rewritten. If the effective verified/paid amount
     * (after reversals) exactly equals the current package price, the invoice
     * amount and paid state can be safely repaired (for example, a payment was
     * recorded before a package correction). Any other mismatch stays untouched
     * for review.
     *
     * @return array{updated:int, skipped:int, reconciled_paid:int}
     */
    public function syncCustomer(Customer $customer): array
    {
        $customer->loadMissing('package');
        $price = $customer->package?->price;

        if ($price === null) {
            return ['updated' => 0, 'skipped' => 0, 'reconciled_paid' => 0];
        }

        $priceCents = $this->money($price);
        $updated = 0;
        $skipped = 0;
        $reconciledPaid = 0;

        $invoices = $customer->invoices()
            ->where('status', 'unpaid')
            ->with([
                'transactions:id,invoice_id,amount,status',
                'transactions.reversals:id,transaction_id,amount',
                'reversals:id,invoice_id,amount',
            ])
            ->get(['id', 'amount', 'status']);

        foreach ($invoices as $invoice) {
            $transactions = $invoice->transactions
                ->whereIn('status', ['verified', 'paid']);

            if ($transactions->isEmpty()) {
                if ($this->money($invoice->amount) !== $priceCents) {
                    $invoice->update(['amount' => $price]);
                    $updated++;
                }

                continue;
            }

            $paidCents = $this->money($invoice->totalPaid());
            if ($paidCents !== $priceCents) {
                $skipped++;

                continue;
            }

            $changes = [];
            if ($this->money($invoice->amount) !== $priceCents) {
                $changes['amount'] = $price;
                $updated++;
            }
            if ($invoice->status !== 'paid') {
                $changes['status'] = 'paid';
                $reconciledPaid++;
            }
            if ($changes !== []) {
                $invoice->update($changes);
            }
        }

        return [
            'updated' => $updated,
            'skipped' => $skipped,
            'reconciled_paid' => $reconciledPaid,
        ];
    }

    private function money(mixed $value): int
    {
        return (int) round((float) $value * 100);
    }
}
