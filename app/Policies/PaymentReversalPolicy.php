<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;

class PaymentReversalPolicy
{
    public function reversePayment(User $user, Invoice $invoice): bool
    {
        return $user->isGlobalAdmin();
    }
}
