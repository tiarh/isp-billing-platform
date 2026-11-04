<?php

namespace App\Services;

use App\Models\Invoice;
use Illuminate\Support\Facades\URL;

final class BillingReceiptService
{
    public function url(Invoice $invoice): string
    {
        $days = max(1, (int) config('services.billing_receipt.ttl_days', 30));

        // The same signed PDF is the customer-facing invoice before payment
        // and the receipt after settlement. Sequential invoice IDs are never
        // sufficient authorization by themselves.
        return URL::temporarySignedRoute(
            'public.invoices.receipt',
            now()->addDays($days),
            ['invoice' => $invoice->getKey()],
        );
    }
}
