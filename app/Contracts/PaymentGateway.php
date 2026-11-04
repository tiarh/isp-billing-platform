<?php

namespace App\Contracts;

use App\Services\PaymentGateway\Data\ChargeRequest;
use App\Services\PaymentGateway\Data\ChargeResult;
use App\Services\PaymentGateway\Data\PaymentMethodOption;
use App\Services\PaymentGateway\Data\StatusResult;

/**
 * Outgoing side of an online payment provider (create a charge, list
 * methods, check status). Incoming notifications are provider specific and
 * are verified in PaymentGatewayController before reaching
 * PaymentGatewayService::process(), which owns all money decisions.
 *
 * Drivers: midtrans (hosted Snap), duitku (direct API). A hosted-page
 * provider (e.g. Duitku POP) returns ChargeResult::KIND_REDIRECT.
 */
interface PaymentGateway
{
    /** Stable lowercase key used in routes, settings and event rows (e.g. "duitku"). */
    public function provider(): string;

    public function label(): string;

    /** Credentials present (does not call the provider). */
    public function isConfigured(): bool;

    public function isProduction(): bool;

    /**
     * Methods the customer can choose for this amount (already filtered by
     * the methods enabled in settings). Fee is what the provider charges.
     *
     * @return list<PaymentMethodOption>
     */
    public function paymentMethods(int $amount): array;

    /**
     * @throws \RuntimeException with a message safe to log (never secrets)
     */
    public function createCharge(ChargeRequest $request): ChargeResult;

    /**
     * Server-to-server status check for one order. Providers may rate-limit
     * this; callers must keep it occasional (fallback for missed callbacks).
     */
    public function checkStatus(string $merchantOrderId): StatusResult;
}
