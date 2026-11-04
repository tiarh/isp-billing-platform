<?php

namespace App\Services;

use App\Models\Invoice;
use Illuminate\Support\Facades\DB;

/**
 * The status transition behind "void invoice": row lock, unpaid only, no
 * payment history. Shared by InvoiceController::void and the one-off
 * customers:status-normalize command. Activity log / cache flush stay with the caller.
 */
class InvoiceVoidService
{
    /** @return string|null error message, null when the invoice is now void */
    public function void(int $invoiceId): ?string
    {
        // Lock the invoice row (same lock GatewayPaymentRecorder/PaymentGatewayService
        // takes when crediting) so a concurrent manual or gateway payment cannot slip
        // in between the check and the void.
        return DB::transaction(function () use ($invoiceId): ?string {
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoiceId);

            if ($locked->status !== 'unpaid') {
                return 'Only unpaid invoices can be voided.';
            }

            if ($locked->transactions()->exists()) {
                return 'Invoice tidak bisa dibatalkan karena sudah memiliki riwayat pembayaran (termasuk yang sudah di-reverse).';
            }

            $locked->update(['status' => 'void']);

            return null;
        });
    }
}
