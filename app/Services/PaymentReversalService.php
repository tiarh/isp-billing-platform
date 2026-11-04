<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\PaymentReversal;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PaymentReversalService
{
    public function reverse(
        Invoice $invoice,
        Transaction $transaction,
        User $actor,
        array $attributes,
    ): PaymentReversal {
        return DB::transaction(function () use ($invoice, $transaction, $actor, $attributes): PaymentReversal {
            $lockedInvoice = Invoice::query()
                ->with(['customer' => fn ($query) => $query->withTrashed()])
                ->lockForUpdate()
                ->findOrFail($invoice->getKey());
            $lockedTransaction = Transaction::query()
                ->whereKey($transaction->getKey())
                ->where('invoice_id', $lockedInvoice->getKey())
                ->lockForUpdate()
                ->first();

            if (! $lockedTransaction) {
                throw ValidationException::withMessages([
                    'transaction' => 'Transaksi tidak terkait dengan invoice yang dipilih.',
                ]);
            }

            $this->assertIdentity($lockedInvoice, $attributes);

            $existing = PaymentReversal::query()
                ->where('idempotency_key', $attributes['idempotency_key'])
                ->lockForUpdate()
                ->first();
            if ($existing) {
                if ((int) $existing->invoice_id !== (int) $lockedInvoice->id
                    || (int) $existing->transaction_id !== (int) $lockedTransaction->id) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => 'Idempotency key sudah digunakan untuk reversal lain.',
                    ]);
                }

                return $existing;
            }

            if ($lockedInvoice->status === 'void') {
                throw ValidationException::withMessages([
                    'invoice_confirmation' => 'Invoice void tidak dapat dikoreksi melalui reversal pembayaran.',
                ]);
            }

            if (! in_array($lockedTransaction->status, ['verified', 'paid'], true)) {
                throw ValidationException::withMessages([
                    'transaction' => 'Hanya transaksi pembayaran yang valid yang dapat dibatalkan.',
                ]);
            }

            if (PaymentReversal::query()->where('transaction_id', $lockedTransaction->id)->exists()) {
                throw ValidationException::withMessages([
                    'transaction' => 'Transaksi ini sudah memiliki reversal.',
                ]);
            }

            $reason = trim((string) $attributes['reason']);
            $reference = 'REV-'.now()->format('YmdHis').'-'.strtoupper(Str::random(8));
            $reversal = PaymentReversal::create([
                'transaction_id' => $lockedTransaction->id,
                'invoice_id' => $lockedInvoice->id,
                'amount' => $lockedTransaction->amount,
                'reversal_reference' => $reference,
                'idempotency_key' => $attributes['idempotency_key'],
                'reason' => $reason,
                'reversed_by' => $actor->id,
                'reversed_at' => now(),
                'metadata' => [
                    'source' => 'admin_invoice_correction',
                    'invoice_id' => (int) $lockedInvoice->id,
                    'transaction_id' => (int) $lockedTransaction->id,
                ],
            ]);

            // A reversed customer-credit application returns the money to the credit (never untracked).
            app(CustomerCreditService::class)->restoreOnReversal($lockedTransaction, $actor, $reason);

            $nextStatus = $lockedInvoice->totalPaid() >= (float) $lockedInvoice->amount
                ? 'paid'
                : 'unpaid';
            if ($lockedInvoice->status !== $nextStatus) {
                $lockedInvoice->update(['status' => $nextStatus]);
            }

            activity('billing.payment_reversal')
                ->causedBy($actor)
                ->performedOn($lockedInvoice)
                ->withProperties([
                    'action' => 'payment_reversal',
                    'invoice_id' => (int) $lockedInvoice->id,
                    'customer_id' => (int) $lockedInvoice->customer_id,
                    'transaction_id' => (int) $lockedTransaction->id,
                    'amount' => (float) $lockedTransaction->amount,
                    'reason' => $reason,
                    'reversal_reference' => $reference,
                    'reversed_at' => $reversal->reversed_at?->toIso8601String(),
                    'resulting_invoice_status' => $nextStatus,
                ])
                ->log('payment_reversed');

            return $reversal;
        });
    }

    private function assertIdentity(Invoice $invoice, array $attributes): void
    {
        if (! hash_equals((string) $invoice->code, trim((string) $attributes['invoice_confirmation']))) {
            throw ValidationException::withMessages([
                'invoice_confirmation' => 'Kode invoice tidak cocok.',
            ]);
        }

        $expectedCustomer = (string) ($invoice->customer?->code ?: $invoice->customer?->name);
        if ($expectedCustomer === ''
            || ! hash_equals($expectedCustomer, trim((string) $attributes['customer_confirmation']))) {
            throw ValidationException::withMessages([
                'customer_confirmation' => 'Konfirmasi pelanggan tidak cocok.',
            ]);
        }
    }
}
