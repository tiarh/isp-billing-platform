<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\PaymentGateway\Data\ChargeRequest;
use App\Services\PaymentGateway\Data\ChargeResult;
use App\Services\PaymentGateway\Data\PaymentMethodOption;
use App\Services\PaymentGateway\Data\StatusResult;
use App\Services\PaymentGateway\Drivers\DuitkuGateway;
use App\Services\PaymentGateway\Duitku\DuitkuConfig;
use App\Services\PaymentGateway\Duitku\DuitkuSignature;
use App\Services\PaymentGateway\PaymentGatewayManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\GatewayFixtures;
use Tests\TestCase;

/**
 * PG-4: Duitku driver (direct API) against HTTP fakes. No real credentials:
 * merchant code and API key below are synthetic.
 */
#[Group('blocking-billing')]
class DuitkuGatewayTest extends TestCase
{
    use GatewayFixtures;
    use RefreshDatabase;

    private const MERCHANT = 'DTEST01';

    private const SECRET = 'test-duitku-key';

    private const ORDER = 'INV7-261010120000ABC';

    protected function setUp(): void
    {
        parent::setUp();

        Setting::set(DuitkuConfig::MERCHANT_CODE, self::MERCHANT, 'text', 'payment');
        Setting::setSecret(DuitkuConfig::API_KEY, self::SECRET, 'payment');
    }

    public function test_signature_vectors_for_both_modes(): void
    {
        $hmac = DuitkuConfig::SIGNATURE_HMAC;
        $legacy = DuitkuConfig::SIGNATURE_LEGACY;

        $this->assertSame('7c52b659879029109c17af6261883d43c8e1f26d5e8bb1fa89b2ee14d9f96f03', DuitkuSignature::inquiry(self::MERCHANT, self::ORDER, 150000, self::SECRET, $hmac));
        $this->assertSame('9b7be9952cd4a6ed1ad277f34591760b', DuitkuSignature::inquiry(self::MERCHANT, self::ORDER, 150000, self::SECRET, $legacy));
        $this->assertSame('60f125c435b850c14f7d7e2148e5b7187535eeaca20c2782b3a99cbdf44bfa35', DuitkuSignature::paymentMethods(self::MERCHANT, 150000, '2026-10-10 12:00:00', self::SECRET, $hmac));
        $this->assertSame('6b275d56c273e501dd7faa9c6027bb07db6f427e7a587bdfbc198bdf195f2450', DuitkuSignature::paymentMethods(self::MERCHANT, 150000, '2026-10-10 12:00:00', self::SECRET, $legacy));
        $this->assertSame('2514cccd06dee7ae4bb6089358d1ae4a8d0d95836d5c589f16a7da50680d8b73', DuitkuSignature::status(self::MERCHANT, self::ORDER, self::SECRET, $hmac));
        $this->assertSame('01584e188e608f2e4adca93732fe412b', DuitkuSignature::status(self::MERCHANT, self::ORDER, self::SECRET, $legacy));

        $this->assertSame($hmac, DuitkuSignature::verifyCallback(self::MERCHANT, '150000', self::ORDER, '831415131e92d968b0f061e133db8549e625c9c47afca558f5803e3d6a8f4d67', self::SECRET));
        $this->assertSame($legacy, DuitkuSignature::verifyCallback(self::MERCHANT, '150000', self::ORDER, 'BA501F00B141403B5AEDA71C36442A48', self::SECRET));
        $this->assertNull(DuitkuSignature::verifyCallback(self::MERCHANT, '150001', self::ORDER, '831415131e92d968b0f061e133db8549e625c9c47afca558f5803e3d6a8f4d67', self::SECRET));
        $this->assertNull(DuitkuSignature::verifyCallback(self::MERCHANT, '150000', self::ORDER, '831415131e92d968b0f061e133db8549e625c9c47afca558f5803e3d6a8f4d67', 'other-key'));
        $this->assertNull(DuitkuSignature::verifyCallback(self::MERCHANT, '150000', self::ORDER, '', self::SECRET));
    }

    public function test_manager_registers_duitku_and_config_reads_settings(): void
    {
        $driver = app(PaymentGatewayManager::class)->driver('duitku');
        $this->assertInstanceOf(DuitkuGateway::class, $driver);
        $this->assertTrue($driver->isConfigured());
        $this->assertFalse($driver->isProduction());

        $config = app(DuitkuConfig::class);
        $this->assertSame('https://sandbox.duitku.com/webapi/api/merchant', $config->baseUrl());
        $this->assertSame(config('services.duitku.callback_ips.sandbox'), $config->callbackIps());

        Setting::set(DuitkuConfig::ENVIRONMENT, 'production', 'text', 'payment');
        $this->assertSame('https://passport.duitku.com/webapi/api/merchant', $config->baseUrl());
        $this->assertSame(config('services.duitku.callback_ips.production'), $config->callbackIps());

        Setting::set(DuitkuConfig::CALLBACK_IPS, ['198.51.100.7', 'not-an-ip'], 'json', 'payment');
        $this->assertSame(['198.51.100.7'], $config->callbackIps());

        Setting::setSecret(DuitkuConfig::API_KEY, '', 'payment');
        $this->assertFalse($driver->isConfigured());
    }

    public function test_payment_methods_signs_request_filters_unsupported_and_enabled_codes(): void
    {
        $this->travelTo(now('Asia/Jakarta')->setDate(2026, 10, 10)->setTime(12, 0, 0));
        Http::fake(['sandbox.duitku.com/*' => Http::response([
            'paymentFee' => [
                ['paymentMethod' => 'BC', 'paymentName' => 'BCA VA', 'paymentImage' => 'https://images.example.test/bca.png', 'totalFee' => '4000'],
                ['paymentMethod' => 'NQ', 'paymentName' => 'QRIS', 'totalFee' => '700.50'],
                ['paymentMethod' => 'DA', 'paymentName' => 'DANA', 'totalFee' => 1500],
                ['paymentMethod' => 'IR', 'paymentName' => 'Indomaret', 'totalFee' => 7500],
                ['paymentMethod' => 'VC', 'paymentName' => 'Credit Card', 'totalFee' => 5000],
                ['paymentMethod' => 'DN', 'paymentName' => 'Indodana', 'totalFee' => 0],
            ],
            'responseCode' => '00',
            'responseMessage' => 'SUCCESS',
        ])]);

        $driver = app(DuitkuGateway::class);
        $methods = collect($driver->paymentMethods(150000))->keyBy('code');

        $this->assertSame(['BC', 'NQ', 'DA', 'IR'], $methods->keys()->all());
        $this->assertSame(PaymentMethodOption::GROUP_VA, $methods['BC']->group);
        $this->assertSame(701, $methods['NQ']->fee, 'fractional fees round up');
        $this->assertSame(PaymentMethodOption::GROUP_RETAIL, $methods['IR']->group);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://sandbox.duitku.com/webapi/api/merchant/paymentmethod/getpaymentmethod'
                && $request['merchantcode'] === self::MERCHANT
                && $request['amount'] === 150000
                && $request['datetime'] === '2026-10-10 12:00:00'
                && $request['signature'] === '60f125c435b850c14f7d7e2148e5b7187535eeaca20c2782b3a99cbdf44bfa35';
        });

        Setting::set(DuitkuConfig::ENABLED_METHODS, ['bc', 'IR'], 'json', 'payment');
        $this->assertSame(['BC', 'IR'], collect($driver->paymentMethods(150000))->pluck('code')->all());
        $this->assertCount(4, $driver->availableMethods(150000));
        Http::assertSentCount(1); // cached for 10 minutes
    }

    public function test_create_charge_va_sends_signed_inquiry(): void
    {
        Http::fake(['sandbox.duitku.com/webapi/api/merchant/v2/inquiry' => Http::response([
            'merchantCode' => self::MERCHANT,
            'reference' => 'DREF0001',
            'paymentUrl' => 'https://sandbox.duitku.com/topup/topupdirectv2.aspx?ref=DREF0001',
            'vaNumber' => '7007014000000001',
            'amount' => '154000',
            'statusCode' => '00',
            'statusMessage' => 'SUCCESS',
        ])]);

        $result = app(DuitkuGateway::class)->createCharge($this->chargeRequest('BC', 154000, 1440));

        $this->assertSame(ChargeResult::KIND_VA, $result->kind);
        $this->assertSame('7007014000000001', $result->vaNumber);
        $this->assertSame('DREF0001', $result->providerReference);
        $this->assertSame(154000, $result->amount);
        $this->assertEqualsWithDelta(now()->addMinutes(1440)->timestamp, $result->expiresAt->timestamp, 5);
        $this->assertArrayNotHasKey('signature', $result->raw);

        Http::assertSent(function (Request $request): bool {
            return $request['merchantCode'] === self::MERCHANT
                && $request['paymentAmount'] === 154000
                && $request['paymentMethod'] === 'BC'
                && $request['merchantOrderId'] === self::ORDER
                && $request['expiryPeriod'] === 1440
                && $request['customerVaName'] === 'Budi Contoso Panjang'
                && mb_strlen($request['customerVaName']) <= 20
                && $request['email'] === 'customer-7@invalid.local'
                && $request['callbackUrl'] === 'https://ebilling.example.test/webhooks/payment-gateway/duitku'
                && $request['signature'] === DuitkuSignature::inquiry(self::MERCHANT, self::ORDER, 154000, self::SECRET, DuitkuConfig::SIGNATURE_HMAC);
        });
    }

    public function test_create_charge_qris_and_ewallet_kinds_and_legacy_mode(): void
    {
        Setting::set(DuitkuConfig::SIGNATURE_MODE, DuitkuConfig::SIGNATURE_LEGACY, 'text', 'payment');
        Http::fakeSequence('sandbox.duitku.com/*')
            ->push(['reference' => 'DREF0002', 'qrString' => '00020101021226FAKEQR', 'paymentUrl' => 'https://sandbox.duitku.com/x', 'statusCode' => '00'])
            ->push(['reference' => 'DREF0003', 'paymentUrl' => 'https://sandbox.duitku.com/ewallet/DREF0003', 'statusCode' => '00']);

        $qr = app(DuitkuGateway::class)->createCharge($this->chargeRequest('NQ', 100700));
        $this->assertSame(ChargeResult::KIND_QR, $qr->kind);
        $this->assertSame('00020101021226FAKEQR', $qr->qrString);

        $ewallet = app(DuitkuGateway::class)->createCharge($this->chargeRequest('DA', 101500));
        $this->assertSame(ChargeResult::KIND_REDIRECT, $ewallet->kind);
        $this->assertSame('https://sandbox.duitku.com/ewallet/DREF0003', $ewallet->paymentUrl);

        Http::assertSent(fn (Request $request): bool => ! isset($request['expiryPeriod'])
            && $request['signature'] === md5(self::MERCHANT.self::ORDER.'100700'.self::SECRET));
    }

    public function test_create_charge_errors_never_contain_the_api_key(): void
    {
        Http::fakeSequence('sandbox.duitku.com/*')
            ->push(['Message' => 'Wrong signature'], 401)
            ->push(['statusCode' => '01', 'statusMessage' => 'Minimum Payment 10000 IDR'])
            ->push(['statusCode' => '00']);

        foreach (['HTTP 401', 'Minimum Payment', 'without vaNumber'] as $expected) {
            try {
                app(DuitkuGateway::class)->createCharge($this->chargeRequest('BC', 154000));
                $this->fail('expected failure');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString($expected, $e->getMessage());
                $this->assertStringNotContainsString(self::SECRET, $e->getMessage());
            }
        }
    }

    public function test_check_status_maps_status_codes(): void
    {
        Http::fakeSequence('sandbox.duitku.com/webapi/api/merchant/transactionStatus')
            ->push(['merchantOrderId' => self::ORDER, 'reference' => 'DREF0001', 'amount' => '154000', 'fee' => '4000.00', 'statusCode' => '00', 'statusMessage' => 'SUCCESS'])
            ->push(['merchantOrderId' => self::ORDER, 'statusCode' => '01'])
            ->push(['merchantOrderId' => self::ORDER, 'statusCode' => '02'])
            ->push(['merchantOrderId' => 'OTHER', 'statusCode' => '00'])
            ->push(['Message' => 'error'], 500);

        $driver = app(DuitkuGateway::class);
        $paid = $driver->checkStatus(self::ORDER);
        $this->assertSame(StatusResult::PAID, $paid->state);
        $this->assertSame(154000, $paid->amount);
        $this->assertSame(4000, $paid->fee);
        $this->assertSame('DREF0001', $paid->providerReference);

        $this->assertSame(StatusResult::PENDING, $driver->checkStatus(self::ORDER)->state);
        $this->assertSame(StatusResult::FAILED, $driver->checkStatus(self::ORDER)->state);
        $this->assertSame(StatusResult::UNKNOWN, $driver->checkStatus(self::ORDER)->state);
        $this->assertSame(StatusResult::UNKNOWN, $driver->checkStatus(self::ORDER)->state);

        Http::assertSent(fn (Request $request): bool => $request['signature'] === '2514cccd06dee7ae4bb6089358d1ae4a8d0d95836d5c589f16a7da50680d8b73');
    }

    public function test_unconfigured_driver_never_calls_duitku(): void
    {
        Http::fake();
        Setting::set(DuitkuConfig::MERCHANT_CODE, '', 'text', 'payment');

        $this->expectException(\RuntimeException::class);
        try {
            app(DuitkuGateway::class)->paymentMethods(150000);
        } finally {
            Http::assertNothingSent();
        }
    }

    private function chargeRequest(string $method, int $amount, ?int $expiry = null): ChargeRequest
    {
        $customer = $this->gatewayCustomer(['name' => 'Budi Contoso Panjang Sekali Namanya']);
        $invoice = $this->gatewayInvoice($customer);

        return new ChargeRequest(
            invoice: $invoice,
            merchantOrderId: self::ORDER,
            amount: $amount,
            methodCode: $method,
            customerName: $customer->name,
            email: 'customer-7@invalid.local',
            phone: '081200000000',
            productDetails: 'Tagihan internet',
            returnUrl: 'https://ebilling.example.test/pay/'.$invoice->uuid.'?payment_result=return',
            callbackUrl: 'https://ebilling.example.test/webhooks/payment-gateway/duitku',
            expiryMinutes: $expiry,
        );
    }
}
