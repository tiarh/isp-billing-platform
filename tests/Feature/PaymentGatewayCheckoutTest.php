<?php

namespace Tests\Feature;

use App\Models\PaymentGatewayTransaction;
use App\Models\Setting;
use App\Models\Transaction;
use App\Services\PaymentGateway\CheckoutException;
use App\Services\PaymentGateway\PaymentGatewayCheckoutService;
use App\Services\PaymentGateway\PaymentGatewayManager;
use App\Services\PaymentGateway\PaymentGatewaySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\GatewayFixtures;
use Tests\Doubles\FakePaymentGateway;
use Tests\TestCase;

/**
 * PG-3: payment_gateway_transactions + provider-agnostic checkout rules.
 */
#[Group('blocking-billing')]
class PaymentGatewayCheckoutTest extends TestCase
{
    use GatewayFixtures;
    use RefreshDatabase;

    private FakePaymentGateway $fake;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fake = new FakePaymentGateway;
        app(PaymentGatewayManager::class)->extend('fake', fn () => $this->fake);
        Setting::set(PaymentGatewayManager::SETTING_ACTIVE, 'fake', 'text', 'payment');
        $this->setPaymentWrites(true);
    }

    public function test_customer_bears_fee_by_default_and_va_gets_configured_expiry(): void
    {
        $invoice = $this->gatewayInvoice($this->gatewayCustomer(), ['amount' => 150000]);

        $txn = $this->checkout()->start($invoice, 'BC');

        $this->assertSame(PaymentGatewayTransaction::STATUS_PENDING, $txn->status);
        $this->assertSame(150000, $txn->amount_invoice);
        $this->assertSame(4000, $txn->fee);
        $this->assertSame(PaymentGatewayTransaction::FEE_BEARER_CUSTOMER, $txn->fee_bearer);
        $this->assertSame(154000, $txn->amount_charged);
        $this->assertSame('va', $txn->method_group);
        $this->assertNotNull($txn->va_number);
        $this->assertSame('sandbox', $txn->environment);
        $this->assertMatchesRegularExpression('/^INV'.$invoice->id.'-\d{12}[A-Z0-9]{3}$/', $txn->merchant_order_id);
        $this->assertLessThanOrEqual(50, strlen($txn->merchant_order_id));

        $request = $this->fake->charges[0];
        $this->assertSame(154000, $request->amount);
        $this->assertSame(180, $request->expiryMinutes, 'VA/retail default validity is 3 hours');
        $this->assertSame(180, PaymentGatewaySettings::DEFAULT_VA_EXPIRY_MINUTES);
        $this->assertEqualsWithDelta(now()->addMinutes(180)->timestamp, $txn->expires_at->timestamp, 5);
        $this->assertStringEndsWith('/webhooks/payment-gateway/fake', $request->callbackUrl);
        $this->assertStringContainsString('/pay/'.$invoice->uuid, $request->returnUrl);
        $this->assertDatabaseHas('activity_log', ['description' => 'payment_gateway_charge_created']);
    }

    public function test_merchant_fee_bearer_charges_only_the_invoice(): void
    {
        Setting::set(PaymentGatewaySettings::FEE_BEARER, 'merchant', 'text', 'payment');
        $invoice = $this->gatewayInvoice($this->gatewayCustomer());

        $txn = $this->checkout()->start($invoice, 'NQ');

        $this->assertSame(100000, $txn->amount_charged);
        $this->assertSame(700, $txn->fee);
        $this->assertSame(PaymentGatewayTransaction::FEE_BEARER_MERCHANT, $txn->fee_bearer);
        $this->assertNull($this->fake->charges[0]->expiryMinutes, 'QRIS keeps the provider default expiry');
        $this->assertSame(0, $txn->toPublicArray()['fee']);
    }

    public function test_pending_charge_is_reused_and_other_method_creates_new_one(): void
    {
        $invoice = $this->gatewayInvoice($this->gatewayCustomer());

        $first = $this->checkout()->start($invoice, 'BC');
        $again = $this->checkout()->start($invoice, 'bc');
        $this->assertSame($first->id, $again->id);
        $this->assertCount(1, $this->fake->charges);

        $qris = $this->checkout()->start($invoice, 'NQ');
        $this->assertNotSame($first->id, $qris->id);
        $this->assertCount(2, $this->fake->charges);

        // An expired charge is never reused.
        $first->update(['expires_at' => now()->subMinute()]);
        $fresh = $this->checkout()->start($invoice, 'BC');
        $this->assertNotSame($first->id, $fresh->id);
    }

    public function test_va_expiry_setting_overrides_default_and_is_clamped(): void
    {
        $invoice = $this->gatewayInvoice($this->gatewayCustomer());
        Setting::set(PaymentGatewaySettings::VA_EXPIRY_MINUTES, '240', 'integer', 'payment');
        $this->checkout()->start($invoice, 'BC');
        $this->assertSame(240, $this->fake->charges[0]->expiryMinutes);

        Setting::set(PaymentGatewaySettings::VA_EXPIRY_MINUTES, '5', 'integer', 'payment');
        $this->assertSame(60, app(PaymentGatewaySettings::class)->vaExpiryMinutes());
    }

    public function test_fourth_different_code_is_refused_but_same_method_reuse_and_expired_codes_do_not_count(): void
    {
        $invoice = $this->gatewayInvoice($this->gatewayCustomer());

        $bca = $this->checkout()->start($invoice, 'BC');
        $this->checkout()->start($invoice, 'NQ');
        $this->checkout()->start($invoice, 'DA');
        $this->assertCount(3, $this->fake->charges);
        $this->assertCount(3, $this->checkout()->openTransactions($invoice, 'fake'));

        // Same method again is plain reuse, never refused (3 active already).
        $this->assertSame($bca->id, $this->checkout()->start($invoice, 'BC')->id);
        $this->assertCount(3, $this->fake->charges);

        // The BCA code no longer matches the remaining amount (e.g. partial manual payment): it still
        // counts as active, so a new BCA code would be a 4th one and is refused (no provider call).
        $bca->update(['amount_invoice' => 90000]);
        $this->assertRefused(CheckoutException::TOO_MANY_OPEN, fn () => $this->checkout()->start($invoice, 'BC'));
        $this->assertCount(3, $this->fake->charges);
        $this->assertSame(3, PaymentGatewayTransaction::query()->count());

        // Refusal is a 409 with a clear Indonesian reason, not a fallback trigger.
        $e = CheckoutException::tooManyOpen();
        $this->assertSame(409, $e->httpStatus());
        $this->assertSame('too_many_open', $e->reason);
        $this->assertStringContainsString('3 kode bayar aktif', $e->getMessage());

        // An expired code frees a slot.
        $bca->update(['expires_at' => now()->subMinute()]);
        $this->assertCount(2, $this->checkout()->openTransactions($invoice, 'fake'));
        $fresh = $this->checkout()->start($invoice, 'BC');
        $this->assertNotSame($bca->id, $fresh->id);
        $this->assertCount(4, $this->fake->charges);
    }

    public function test_code_in_its_last_two_minutes_is_still_listed_and_counts_for_the_cap(): void
    {
        $invoice = $this->gatewayInvoice($this->gatewayCustomer());
        $bca = $this->checkout()->start($invoice, 'BC');
        $this->checkout()->start($invoice, 'NQ');
        $this->checkout()->start($invoice, 'DA');

        // 1 minute left: no longer reusable (grace), but still payable at the provider.
        $bca->update(['expires_at' => now()->addMinute()]);
        $this->assertFalse($bca->fresh()->isReusable());
        $this->assertCount(3, $this->checkout()->openTransactions($invoice, 'fake'));

        $this->assertRefused(CheckoutException::TOO_MANY_OPEN, fn () => $this->checkout()->start($invoice, 'BC'));
        $this->assertCount(3, $this->fake->charges);
    }

    public function test_open_codes_of_another_invoice_do_not_count(): void
    {
        $other = $this->gatewayInvoice($this->gatewayCustomer());
        $this->checkout()->start($other, 'BC');
        $this->checkout()->start($other, 'NQ');
        $this->checkout()->start($other, 'DA');

        $invoice = $this->gatewayInvoice($this->gatewayCustomer());
        $this->assertSame(PaymentGatewayTransaction::STATUS_PENDING, $this->checkout()->start($invoice, 'BC')->status);
    }

    public function test_charge_covers_only_the_remaining_balance(): void
    {
        $invoice = $this->gatewayInvoice($this->gatewayCustomer(), ['amount' => 100000]);
        Transaction::create([
            'reference' => 'MANUAL-PG3',
            'invoice_id' => $invoice->id,
            'amount' => 40000,
            'channel' => 'manual',
            'method' => 'cash',
            'status' => 'verified',
            'paid_at' => now(),
        ]);

        $txn = $this->checkout()->start($invoice, 'NQ');

        $this->assertSame(60000, $txn->amount_invoice);
        $this->assertSame(60700, $txn->amount_charged);
    }

    public function test_refusals(): void
    {

        $this->assertRefused(CheckoutException::VOID, fn () => $this->checkout()->start($this->gatewayInvoice($this->gatewayCustomer(), ['status' => 'void']), 'BC'));
        $this->assertRefused(CheckoutException::NOT_PAYABLE, fn () => $this->checkout()->start($this->gatewayInvoice($this->gatewayCustomer(), ['status' => 'paid']), 'BC'));
        $this->assertRefused(CheckoutException::AMOUNT_TOO_SMALL, fn () => $this->checkout()->start($this->gatewayInvoice($this->gatewayCustomer(), ['amount' => 5000]), 'BC'));
        $this->assertRefused(CheckoutException::INVALID_METHOD, fn () => $this->checkout()->start($this->gatewayInvoice($this->gatewayCustomer()), 'ZZ'));

        $this->setPaymentWrites(false);
        $this->assertRefused(CheckoutException::UNAVAILABLE, fn () => $this->checkout()->start($this->gatewayInvoice($this->gatewayCustomer()), 'BC'));
        $this->setPaymentWrites(true);

        Setting::set(PaymentGatewayManager::SETTING_ACTIVE, 'none', 'text', 'payment');
        $this->assertRefused(CheckoutException::UNAVAILABLE, fn () => $this->checkout()->start($this->gatewayInvoice($this->gatewayCustomer()), 'BC'));

        $this->assertCount(0, $this->fake->charges);
        $this->assertSame(0, PaymentGatewayTransaction::query()->count());
    }

    public function test_provider_failure_marks_attempt_failed_with_safe_error(): void
    {
        $this->fake->chargeFailure = new \RuntimeException('Duitku HTTP 401');
        $invoice = $this->gatewayInvoice($this->gatewayCustomer());

        $this->assertRefused(CheckoutException::PROVIDER_ERROR, fn () => $this->checkout()->start($invoice, 'BC'));

        $txn = PaymentGatewayTransaction::query()->sole();
        $this->assertSame(PaymentGatewayTransaction::STATUS_FAILED, $txn->status);
        $this->assertStringContainsString('HTTP 401', (string) $txn->last_error);
    }

    public function test_placeholder_email_for_customers_without_valid_email(): void
    {
        $withoutEmail = $this->gatewayCustomer();
        $this->checkout()->start($this->gatewayInvoice($withoutEmail), 'BC');
        $this->assertSame('customer-'.$withoutEmail->id.'@invalid.local', $this->fake->charges[0]->email);

        $withEmail = $this->gatewayCustomer();
        $withEmail->forceFill(['email' => 'pelanggan@example.com'])->save();
        $this->checkout()->start($this->gatewayInvoice($withEmail), 'BC');
        $this->assertSame('pelanggan@example.com', $this->fake->charges[1]->email);

        Setting::set(PaymentGatewaySettings::PLACEHOLDER_EMAIL_DOMAIN, 'billing.invalid', 'text', 'payment');
        $this->assertSame('customer-'.$withoutEmail->id.'@billing.invalid', app(PaymentGatewaySettings::class)->emailFor($withoutEmail));
    }

    public function test_methods_for_applies_fee_bearer(): void
    {
        $invoice = $this->gatewayInvoice($this->gatewayCustomer());

        $methods = collect($this->checkout()->methodsFor($invoice))->keyBy('code');
        $this->assertSame(104000, $methods['BC']['total']);
        $this->assertSame(4000, $methods['BC']['fee']);

        Setting::set(PaymentGatewaySettings::FEE_BEARER, 'merchant', 'text', 'payment');
        $methods = collect($this->checkout()->methodsFor($invoice))->keyBy('code');
        $this->assertSame(100000, $methods['BC']['total']);
        $this->assertSame(0, $methods['BC']['fee']);

        $this->setPaymentWrites(false);
        $this->assertSame([], $this->checkout()->methodsFor($invoice));
    }

    private function checkout(): PaymentGatewayCheckoutService
    {
        return app(PaymentGatewayCheckoutService::class);
    }

    private function assertRefused(string $reason, callable $callback): void
    {
        try {
            $callback();
            $this->fail("Expected checkout refusal [{$reason}].");
        } catch (CheckoutException $e) {
            $this->assertSame($reason, $e->reason);
        }
    }
}
