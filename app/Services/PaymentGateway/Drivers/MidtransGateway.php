<?php

namespace App\Services\PaymentGateway\Drivers;

use App\Contracts\PaymentGateway;
use App\Services\MidtransService;
use App\Services\PaymentGateway\Data\ChargeRequest;
use App\Services\PaymentGateway\Data\ChargeResult;
use App\Services\PaymentGateway\Data\PaymentMethodOption;
use App\Services\PaymentGateway\Data\StatusResult;

/**
 * Midtrans Snap behind the PaymentGateway interface (kept as fallback).
 * Snap is a hosted page: the customer picks the method at Midtrans, and
 * Midtrans chooses its own order id. Notifications keep their dedicated
 * signature path (PaymentGatewayController::webhook).
 */
class MidtransGateway implements PaymentGateway
{
    public function __construct(private readonly MidtransService $midtrans) {}

    public function provider(): string
    {
        return 'midtrans';
    }

    public function label(): string
    {
        return 'Midtrans';
    }

    public function isConfigured(): bool
    {
        return $this->midtrans->isConfigured();
    }

    public function isProduction(): bool
    {
        return $this->midtrans->isProduction();
    }

    public function paymentMethods(int $amount): array
    {
        return [new PaymentMethodOption(
            code: 'midtrans',
            name: 'Bayar Online (Midtrans)',
            group: PaymentMethodOption::GROUP_HOSTED,
        )];
    }

    public function createCharge(ChargeRequest $request): ChargeResult
    {
        $snap = $this->midtrans->createSnap($request->invoice);

        return new ChargeResult(
            kind: ChargeResult::KIND_REDIRECT,
            merchantOrderId: $snap['order_id'],
            providerReference: $snap['token'],
            amount: $this->midtrans->chargeableAmount($request->invoice),
            paymentUrl: $snap['redirect_url'],
        );
    }

    public function checkStatus(string $merchantOrderId): StatusResult
    {
        // Not used: Midtrans relies on its signed HTTP notification.
        return StatusResult::unknown(['reason' => 'not_supported']);
    }
}
