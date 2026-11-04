<?php

namespace Tests\Feature;

use App\Jobs\EnsureCustomerCurrentInvoiceJob;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use App\Services\InvoiceGenerationService;
use App\Support\OpsTime;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-core')]
class CustomerReactivationInvoiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_inactive_to_active_dispatches_a_current_month_invoice_job(): void
    {
        Bus::fake();
        $customer = $this->customer(['status' => 'inactive', 'due_day' => 20]);

        $customer->update(['status' => 'active']);

        Bus::assertDispatched(EnsureCustomerCurrentInvoiceJob::class, function (EnsureCustomerCurrentInvoiceJob $job) use ($customer) {
            return $job->customerId === $customer->id
                && $job->period === OpsTime::now()->startOfMonth()->toDateString();
        });
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_reactivation_job_is_idempotent_and_uses_customer_due_day(): void
    {
        // Before the due day: a late-created invoice would be floored to today.
        $this->travelTo(CarbonImmutable::now(OpsTime::TZ)->startOfMonth()->addDays(4)->setTime(10, 0));
        $customer = $this->customer(['status' => 'active', 'due_day' => 20]);
        $period = OpsTime::now()->startOfMonth();
        $job = new EnsureCustomerCurrentInvoiceJob($customer->id, $period->toDateString());

        $job->handle(app(InvoiceGenerationService::class));
        $job->handle(app(InvoiceGenerationService::class));

        $this->assertDatabaseCount('invoices', 1);
        $invoice = Invoice::query()->where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame($period->toDateString(), $invoice->period->toDateString());
        $this->assertSame($period->day(20)->toDateString(), $invoice->due_date->toDateString());
        $this->assertSame('unpaid', $invoice->status);
        $this->assertSame('150000.00', $invoice->amount);
    }

    public function test_isolated_to_active_does_not_dispatch_reactivation_invoice_job(): void
    {
        Bus::fake();
        $customer = $this->customer(['status' => 'isolated']);

        $customer->update(['status' => 'active']);

        Bus::assertNotDispatched(EnsureCustomerCurrentInvoiceJob::class);
    }

    private function customer(array $attributes = []): Customer
    {
        $package = Package::create([
            'code' => 'REACT-150',
            'name' => 'Reactivation Test',
            'price' => 150000,
        ]);

        return Customer::create(array_merge([
            'code' => 'REACT-'.str_pad((string) (Customer::count() + 1), 3, '0', STR_PAD_LEFT),
            'name' => 'Nozi Krian Test',
            'phone' => '081234567890',
            'address' => 'Krian test address',
            'package_id' => $package->id,
            'pppoe_user' => 'nozi-krian-test-'.(Customer::count() + 1),
            'status' => 'inactive',
            'join_date' => CarbonImmutable::now(OpsTime::TZ)->subMonths(2)->startOfMonth(),
        ], $attributes));
    }
}
