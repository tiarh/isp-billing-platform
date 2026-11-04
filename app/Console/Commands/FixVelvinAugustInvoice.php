<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class FixVelvinAugustInvoice extends Command
{
    private const CUSTOMER_ID = 1937;

    private const INVOICE_ID = 2823305;

    private const INVOICE_CODE = 'INV-202608-RDG272-OF3Q';

    private const PERIOD = '2026-08-01';

    private const OLD_AMOUNT = 111000;

    private const NEW_AMOUNT = 75000;

    protected $signature = 'velvin:fix-august
                            {--dry-run : Preview the targeted invoice and transaction changes without writing}
                            {--execute : Apply the targeted invoice and transaction changes}';

    protected $description = 'Correct Velvin August 2026 invoice amount once, with an explicit execution flag';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $execute = (bool) $this->option('execute');

        if ($dryRun === $execute) {
            $this->error('Pilih tepat satu mode: --dry-run atau --execute.');

            return self::FAILURE;
        }

        $invoice = $this->targetInvoice()->with('customer')->first();

        if (! $invoice || ! $invoice->customer) {
            $this->info('tidak ada invoice 111000 di 2026-08');

            return self::SUCCESS;
        }

        $customer = $invoice->customer;

        if ($dryRun) {
            $this->showPreview($invoice);

            return self::SUCCESS;
        }

        $result = DB::transaction(function () use ($customer): ?array {
            $invoice = $this->targetInvoice()->lockForUpdate()->first();

            if (! $invoice) {
                return null;
            }

            $transactions = $this->targetTransactions($invoice)
                ->lockForUpdate()
                ->get();

            $invoice->update(['amount' => self::NEW_AMOUNT]);
            foreach ($transactions as $transaction) {
                $transaction->update(['amount' => self::NEW_AMOUNT]);
            }

            $freshInvoice = $invoice->fresh();
            if (! $freshInvoice || ! $freshInvoice->isSettled()) {
                throw new RuntimeException("Invoice #{$invoice->id} gagal diverifikasi settled.");
            }

            activity('billing-data-fix')
                ->performedOn($freshInvoice)
                ->withProperties([
                    'reason' => 'velvin_august_2026_amount_correction',
                    'customer_id' => (int) $customer->id,
                    'invoice_id' => (int) $freshInvoice->id,
                    'period' => self::PERIOD,
                    'invoice_amount_before' => self::OLD_AMOUNT,
                    'invoice_amount_after' => self::NEW_AMOUNT,
                    'transaction_ids' => $transactions->modelKeys(),
                    'transaction_amount_before' => self::OLD_AMOUNT,
                    'transaction_amount_after' => self::NEW_AMOUNT,
                ])
                ->log("fixed invoice #{$freshInvoice->id} 111000→75000");

            return [$freshInvoice, $transactions->modelKeys()];
        });

        if ($result === null) {
            $this->info('tidak ada invoice 111000 di 2026-08');

            return self::SUCCESS;
        }

        /** @var Invoice $invoice */
        [$invoice, $transactionIds] = $result;
        $message = "fixed invoice #{$invoice->id} 111000→75000";
        Log::info($message, [
            'customer_id' => (int) $customer->id,
            'invoice_id' => (int) $invoice->id,
            'transaction_ids' => $transactionIds,
        ]);
        $this->info($message);
        $this->line('settled: true');

        return self::SUCCESS;
    }

    private function targetInvoice(): Builder
    {
        return Invoice::query()
            ->whereKey(self::INVOICE_ID)
            ->where('code', self::INVOICE_CODE)
            ->where('customer_id', self::CUSTOMER_ID)
            ->where('period', self::PERIOD)
            ->where('amount', self::OLD_AMOUNT);
    }

    private function targetTransactions(Invoice $invoice): HasMany
    {
        return $invoice->transactions()
            ->whereIn('status', ['paid', 'verified'])
            ->where('amount', self::OLD_AMOUNT);
    }

    private function showPreview(Invoice $invoice): void
    {
        $transactions = $this->targetTransactions($invoice)->get();

        $this->line("DRY-RUN invoice #{$invoice->id}: 111000 → 75000");
        $this->line('DRY-RUN transaksi: '.($transactions->modelKeys() === []
            ? 'tidak ada'
            : implode(', ', $transactions->modelKeys())));
        $this->info('DRY-RUN selesai; tidak ada perubahan yang ditulis.');
    }
}
