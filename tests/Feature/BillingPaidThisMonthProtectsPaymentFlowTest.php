<?php

namespace Tests\Feature;

use App\Jobs\ReconnectCustomerJob;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\Router;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * 2026-10-08 audit Bug A, end to end through PaymentController::store:
 * a manual payment (stored as `verified`) for September made while the
 * October invoice is past grace must schedule a reconnect, and the admin
 * must be told whether a reconnect was scheduled.
 */
#[Group('blocking-billing')]
class BillingPaidThisMonthProtectsPaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-08 03:20:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_paying_september_while_october_is_past_grace_schedules_reconnect(): void
    {
        Bus::fake();
        [$customer, $september] = $this->scenario('isolated');

        $this->pay($september)
            ->assertRedirect()
            ->assertSessionHas('success', fn (string $message) => str_contains($message, 'Reconnect dijadwalkan'));

        $this->assertSame('verified', Transaction::query()->where('invoice_id', $september->id)->value('status'));
        $this->assertSame('paid', $september->refresh()->status);
        Bus::assertDispatched(ReconnectCustomerJob::class, fn ($job) => $job->customer->is($customer));
    }

    public function test_payment_for_customer_not_isolated_in_db_explains_why_no_reconnect(): void
    {
        Bus::fake();
        Log::spy();
        // Audit pattern B: admin set status=active via the edit form first.
        [$customer, $september] = $this->scenario('active');

        $this->pay($september)
            ->assertRedirect()
            ->assertSessionHas('success', fn (string $message) => str_contains($message, 'Reconnect tidak dijadwalkan')
                && str_contains($message, 'tombol Reconnect'));

        Bus::assertNotDispatched(ReconnectCustomerJob::class);
        Log::shouldHaveReceived('info')
            ->with('[billing] payment reconnect not dispatched', Mockery::on(
                fn (array $context) => $context['reason'] === 'customer_not_isolated'
                    && $context['customer_id'] === $customer->id
            ))
            ->once();
    }

    /**
     * @return array{0: Customer, 1: Invoice}
     */
    private function scenario(string $status): array
    {
        $package = Package::query()->create([
            'code' => 'PKG-L1-FLOW',
            'name' => 'L1 Flow',
            'price' => 115000,
            'mikrotik_profile' => 'L1_FLOW_PROFILE',
        ]);
        $router = Router::query()->create([
            'name' => 'L1 Flow Router',
            'ip_address' => '192.0.2.53',
            'username' => 'test',
            'password' => 'test-only-router-password',
            'port' => 8728,
            'is_active' => true,
        ]);
        $customer = Customer::query()->create([
            'code' => 'CUST-L1FLOW',
            'name' => 'L1 Flow Customer',
            'phone' => '081234567890',
            'address' => 'TEST',
            'pppoe_user' => 'l1flow.user',
            'package_id' => $package->id,
            'router_id' => $router->id,
            'status' => $status,
            'join_date' => '2026-01-01',
        ]);
        $september = Invoice::query()->create([
            'customer_id' => $customer->id,
            'period' => '2026-09-01',
            'due_date' => '2026-09-05',
            'status' => 'unpaid',
            'amount' => 115000,
            'generated_at' => now(),
        ]);
        Invoice::query()->create([
            'customer_id' => $customer->id,
            'period' => '2026-10-01',
            'due_date' => '2026-10-05',
            'status' => 'unpaid',
            'amount' => 115000,
            'generated_at' => now(),
        ]);

        return [$customer, $september];
    }

    private function pay(Invoice $invoice)
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $invoice->load('customer');

        return $this->actingAs($admin)->post(route('payments.store', $invoice), [
            'invoice_confirmation' => $invoice->code,
            'customer_confirmation' => $invoice->customer->code,
            'amount' => 115000,
            'method' => 'cash',
        ]);
    }
}
