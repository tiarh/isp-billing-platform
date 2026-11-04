<?php

namespace Tests\Unit;

use App\Http\Controllers\PaymentGatewayController;
use App\Models\Invoice;
use App\Services\MidtransService;
use App\Services\PaymentGateway\PaymentWriteGate;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-billing')]
class PaymentGatewayControllerTest extends TestCase
{
    public function test_admin_snap_creation_hides_provider_exception_details(): void
    {
        $invoice = Mockery::mock(Invoice::class)->makePartial();
        $invoice->status = 'unpaid';
        $invoice->shouldReceive('isSettled')->andReturnFalse();
        $invoice->shouldReceive('load')->once()->with('customer')->andReturnSelf();

        $gate = Mockery::mock(PaymentWriteGate::class);
        $gate->shouldReceive('enabled')->andReturnTrue();
        $this->app->instance(PaymentWriteGate::class, $gate);

        $midtrans = Mockery::mock(MidtransService::class);
        $midtrans->shouldReceive('createSnap')
            ->once()
            ->andThrow(new \RuntimeException('provider_secret=do-not-disclose'));

        $response = (new PaymentGatewayController)->createMidtransSnap($invoice, $midtrans);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame([
            'success' => false,
            'message' => 'Pembayaran Midtrans belum siap. Coba lagi sebentar.',
        ], $response->getData(true));
        $this->assertStringNotContainsString('provider_secret', $response->getContent());
    }
}
