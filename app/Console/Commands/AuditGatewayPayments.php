<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\PaymentGatewayEvent;
use App\Models\PaymentGatewayTransaction;
use App\Services\PaymentGateway\GatewayPaymentRecorder;
use App\Services\PaymentGatewayService;
use Illuminate\Console\Command;

/**
 * Read-only audit of the payment gateway path (PR-PAY1).
 *
 * Never writes. Any backfill must be a separate, explicitly approved change.
 */
class AuditGatewayPayments extends Command
{
    protected $signature = 'payments:audit-gateway
        {--days=90 : Look-back window for gateway events}
        {--limit=200 : Max rows printed per section}
        {--fail-on-findings : Exit non-zero when any finding is reported}';

    protected $description = 'Read-only: gateway-settled invoices not marked paid, pending events without a final event, rejected/held gateway payments, stale online payment attempts';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $limit = max(1, (int) $this->option('limit'));
        $since = now()->subDays($days);

        $this->info("=== Payment gateway audit (read-only, last {$days} days for events) ===");

        $findings = 0;
        $findings += $this->settledButNotPaid($limit);
        $findings += $this->pendingWithoutFinal($since, $limit);
        $findings += $this->rejectedEvents($since, $limit);
        $findings += $this->heldEvents($limit);
        $findings += $this->paidAttemptsWithoutCredit($limit);
        $findings += $this->staleOpenAttempts($limit);

        $this->newLine();
        $this->line("Total findings: {$findings}. No data was changed.");

        return $findings > 0 && $this->option('fail-on-findings') ? self::FAILURE : self::SUCCESS;
    }

    private function settledButNotPaid(int $limit): int
    {
        $this->newLine();
        $this->line('[1] Invoices with PG-% gateway transactions that are settled but status is not "paid" (M1)');

        $rows = Invoice::query()
            ->where('status', '!=', 'paid')
            ->whereHas('transactions', fn ($q) => $q->where('reference', 'like', 'PG-%'))
            ->with(['transactions', 'reversals'])
            ->orderBy('id')
            ->get()
            ->filter(fn (Invoice $invoice): bool => $invoice->isSettled())
            ->map(fn (Invoice $invoice): array => [
                $invoice->id,
                $invoice->code,
                $invoice->status,
                number_format((float) $invoice->amount, 2, '.', ''),
                number_format($invoice->totalPaid(), 2, '.', ''),
            ])
            ->values();

        $this->printRows(['invoice_id', 'code', 'status', 'amount', 'paid'], $rows->all(), $limit);

        return $rows->count();
    }

    private function pendingWithoutFinal(\DateTimeInterface $since, int $limit): int
    {
        $this->newLine();
        $this->line('[2] Midtrans "pending" events without any later event for the same order (M2)');

        $pending = PaymentGatewayEvent::query()
            ->where('provider', 'midtrans')
            ->where('status', 'pending')
            ->where('created_at', '>=', $since)
            ->whereNotNull('reference')
            ->orderBy('id')
            ->get(['id', 'reference', 'invoice_id', 'amount', 'created_at']);

        $rows = $pending
            ->filter(fn (PaymentGatewayEvent $event): bool => ! PaymentGatewayEvent::query()
                ->where('provider', 'midtrans')
                ->where('reference', $event->reference)
                ->where('status', '!=', 'pending')
                ->exists())
            ->map(fn (PaymentGatewayEvent $event): array => [
                $event->id,
                $event->reference,
                $event->invoice_id,
                (string) $event->amount,
                $event->created_at?->toDateTimeString(),
            ])
            ->values();

        $this->printRows(['event_id', 'order_id', 'invoice_id', 'amount', 'created_at'], $rows->all(), $limit);
        if ($rows->isNotEmpty()) {
            $this->line('    Check these orders in the Midtrans dashboard; a settled order here was never credited.');
        }

        return $rows->count();
    }

    private function rejectedEvents(\DateTimeInterface $since, int $limit): int
    {
        $this->newLine();
        $this->line('[3] Rejected / unmatched gateway payments (money may need a refund or manual credit)');

        $rows = PaymentGatewayEvent::query()
            ->whereIn('status', [
                PaymentGatewayService::STATUS_REJECTED_AMOUNT,
                PaymentGatewayService::STATUS_REJECTED_VOID,
                PaymentGatewayService::STATUS_REJECTED_UNSIGNED,
                PaymentGatewayService::STATUS_UNMATCHED,
                GatewayPaymentRecorder::STATUS_AMOUNT_MISMATCH,
                // Duitku credits (money tracked in customer_credits, admin decision may be pending).
                PaymentGatewayService::STATUS_CREDITED_TO_BALANCE,
                PaymentGatewayService::STATUS_SETTLED_EXCESS_REVIEW,
            ])
            ->where('created_at', '>=', $since)
            ->orderBy('id')
            ->get(['id', 'provider', 'status', 'reference', 'invoice_id', 'amount', 'created_at'])
            ->map(fn (PaymentGatewayEvent $event): array => [
                $event->id,
                $event->provider,
                $event->status,
                $event->reference,
                $event->invoice_id,
                (string) $event->amount,
                $event->created_at?->toDateTimeString(),
            ]);

        $this->printRows(['event_id', 'provider', 'status', 'order_id', 'invoice_id', 'amount', 'created_at'], $rows->all(), $limit);

        return $rows->count();
    }

    private function heldEvents(int $limit): int
    {
        $this->newLine();
        $this->line('[4] Paid gateway notifications held by the Payment write kill switch (credited when switched ON)');

        $rows = PaymentGatewayEvent::query()
            ->where('status', PaymentGatewayService::STATUS_HELD)
            ->orderBy('id')
            ->get(['id', 'provider', 'reference', 'invoice_id', 'credit_amount', 'held_at'])
            ->map(fn (PaymentGatewayEvent $event): array => [
                $event->id,
                $event->provider,
                $event->reference,
                $event->invoice_id,
                (string) $event->credit_amount,
                $event->held_at?->toDateTimeString(),
            ]);

        $this->printRows(['event_id', 'provider', 'order_id', 'invoice_id', 'credit', 'held_at'], $rows->all(), $limit);

        return $rows->count();
    }

    private function paidAttemptsWithoutCredit(int $limit): int
    {
        $this->newLine();
        $this->line('[5] Online payment attempts marked paid without a linked transaction');

        $rows = PaymentGatewayTransaction::query()
            ->where('status', PaymentGatewayTransaction::STATUS_PAID)
            ->whereNull('transaction_id')
            ->orderBy('id')
            ->get(['id', 'provider', 'merchant_order_id', 'invoice_id', 'amount_invoice', 'paid_at'])
            ->map(fn (PaymentGatewayTransaction $txn): array => [
                $txn->id,
                $txn->provider,
                $txn->merchant_order_id,
                $txn->invoice_id,
                $txn->amount_invoice,
                $txn->paid_at?->toDateTimeString(),
            ]);

        $this->printRows(['attempt_id', 'provider', 'order_id', 'invoice_id', 'amount', 'paid_at'], $rows->all(), $limit);

        return $rows->count();
    }

    private function staleOpenAttempts(int $limit): int
    {
        $this->newLine();
        $this->line('[6] Online payment attempts still open more than 1 day after expiry (check the provider dashboard)');

        $rows = PaymentGatewayTransaction::query()
            ->open()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now()->subDay())
            ->orderBy('id')
            ->get(['id', 'provider', 'merchant_order_id', 'invoice_id', 'amount_charged', 'expires_at', 'status_check_count'])
            ->map(fn (PaymentGatewayTransaction $txn): array => [
                $txn->id,
                $txn->provider,
                $txn->merchant_order_id,
                $txn->invoice_id,
                $txn->amount_charged,
                $txn->expires_at?->toDateTimeString(),
                $txn->status_check_count,
            ]);

        $this->printRows(['attempt_id', 'provider', 'order_id', 'invoice_id', 'charged', 'expires_at', 'checks'], $rows->all(), $limit);

        return $rows->count();
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function printRows(array $headers, array $rows, int $limit): void
    {
        if ($rows === []) {
            $this->line('    none');

            return;
        }

        $this->table($headers, array_slice($rows, 0, $limit));
        if (count($rows) > $limit) {
            $this->line('    ... '.(count($rows) - $limit).' more (raise --limit)');
        }
    }
}
