<?php

namespace Tests\Doubles;

use App\Contracts\PaymentGateway;
use App\Services\PaymentGateway\Data\ChargeRequest;
use App\Services\PaymentGateway\Data\ChargeResult;
use App\Services\PaymentGateway\Data\PaymentMethodOption;
use App\Services\PaymentGateway\Data\StatusResult;

/**
 * In-memory gateway for tests: no HTTP, records every charge request.
 */
class FakePaymentGateway implements PaymentGateway
{
    /** @var list<ChargeRequest> */
    public array $charges = [];

    /** @var list<string> */
    public array $statusChecks = [];

    /** @var array<string, StatusResult> */
    public array $statuses = [];

    public ?\Throwable $chargeFailure = null;

    /** When set, paymentMethods() throws it (provider outage while listing methods). */
    public ?\Throwable $methodsFailure = null;

    /**
     * @param  list<PaymentMethodOption>|null  $methods
     */
    public function __construct(
        public bool $configured = true,
        public ?array $methods = null,
        public string $providerKey = 'fake',
    ) {
        $this->methods ??= [
            new PaymentMethodOption('BC', 'BCA Virtual Account', PaymentMethodOption::GROUP_VA, 4000),
            new PaymentMethodOption('NQ', 'QRIS', PaymentMethodOption::GROUP_QRIS, 700),
            new PaymentMethodOption('DA', 'DANA', PaymentMethodOption::GROUP_EWALLET, 1500),
        ];
    }

    public function provider(): string
    {
        return $this->providerKey;
    }

    public function label(): string
    {
        return 'Fake Gateway';
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function isProduction(): bool
    {
        return false;
    }

    public function paymentMethods(int $amount): array
    {
        if ($this->methodsFailure) {
            throw $this->methodsFailure;
        }

        return $this->methods;
    }

    public function createCharge(ChargeRequest $request): ChargeResult
    {
        $this->charges[] = $request;
        if ($this->chargeFailure) {
            throw $this->chargeFailure;
        }

        $group = collect($this->methods)->firstWhere('code', $request->methodCode)?->group;

        return new ChargeResult(
            kind: match ($group) {
                PaymentMethodOption::GROUP_VA, PaymentMethodOption::GROUP_RETAIL => ChargeResult::KIND_VA,
                PaymentMethodOption::GROUP_QRIS => ChargeResult::KIND_QR,
                default => ChargeResult::KIND_REDIRECT,
            },
            merchantOrderId: $request->merchantOrderId,
            providerReference: 'FAKEREF'.count($this->charges),
            amount: $request->amount,
            paymentUrl: 'https://pay.example.test/'.$request->merchantOrderId,
            vaNumber: $group === PaymentMethodOption::GROUP_VA ? '8800000000'.count($this->charges) : null,
            qrString: $group === PaymentMethodOption::GROUP_QRIS ? 'FAKE-QR-'.$request->merchantOrderId : null,
            expiresAt: now()->addMinutes($request->expiryMinutes ?? 60),
        );
    }

    public function checkStatus(string $merchantOrderId): StatusResult
    {
        $this->statusChecks[] = $merchantOrderId;

        return $this->statuses[$merchantOrderId] ?? StatusResult::unknown();
    }
}
