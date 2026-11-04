<?php

namespace App\Services\PaymentGateway\Data;

use App\Models\Invoice;

final class ChargeRequest
{
    /**
     * @param  int  $amount  total amount the customer pays (invoice remainder + fee when the customer bears it)
     * @param  int|null  $expiryMinutes  null = provider default for the method
     */
    public function __construct(
        public readonly Invoice $invoice,
        public readonly string $merchantOrderId,
        public readonly int $amount,
        public readonly string $methodCode,
        public readonly string $customerName,
        public readonly string $email,
        public readonly ?string $phone,
        public readonly string $productDetails,
        public readonly string $returnUrl,
        public readonly string $callbackUrl,
        public readonly ?int $expiryMinutes = null,
    ) {}
}
