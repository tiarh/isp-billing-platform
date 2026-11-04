<?php

namespace Tests\Feature;

use App\Jobs\ApplyCustomerCreditsJob;
use App\Jobs\ReconnectCustomerJob;
use App\Models\Customer;
use App\Models\CustomerCredit;
use App\Models\Invoice;
use App\Models\PaymentReversal;
use App\Models\Setting;
use App\Models\Transaction;
use App\Services\BillingWaEventService;
use App\Services\CustomerAutoCreditGate;
use App\Services\CustomerCreditService;
use App\Services\InvoiceGenerationService;
use App\Services\PaymentReversalService;
use Carbon\Carbon;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Concerns\GatewayFixtures;
use Tests\TestCase;

/**
 * Pay PR-3: available customer credit is applied to newly generated monthly
 * invoices (ensureForPeriod only), oldest credit first, behind a kill switch.
 * Synthetic data only; money assertions are exact to the rupiah.
 */
#[Group('blocking-billing')]
class CustomerCreditAutoApplyTest extends TestCase
{
    use GatewayFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-08 03:00:00'); // 10:00 WIB
        Queue::fake();
        $this->gate(true);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---- creation hook -----------------------------------------------------

    public function test_new_invoice_is_fully_paid_by_equal_credit(): void
    {
        $customer = $this->billable();
        $credit = $this->credit($customer, 100000);

        $invoice = $this->generate($customer);
        Queue::assertPushed(ApplyCustomerCreditsJob::class, fn (ApplyCustomerCreditsJob $job) => $job->invoiceId === $invoice->id);
        $this->runJob($invoice);

        $invoice->refresh();
        $this->assertSame('paid', $invoice->status);
        $this->assertSame(100000.0, $invoice->totalPaid());

        $transaction = Transaction::query()->where('invoice_id', $invoice->id)->sole();
        $this->assertSame('100000.00', (string) $transaction->amount);
        $this->assertNull($transaction->admin_id);
        $this->assertSame('verified', $transaction->status);
        $this->assertSame('manual', $transaction->channel);
        $this->assertSame('other', $transaction->method);
        $this->assertSame('credit:'.$credit->id.':'.$invoice->id.':100000.00', $transaction->idempotency_key);
        $this->assertTrue($transaction->isCreditApplication());

        $credit->refresh();
        $this->assertSame(CustomerCredit::STATUS_APPLIED, $credit->status);
        $this->assertSame($invoice->id, $credit->applied_invoice_id);
        $this->assertSame($transaction->id, $credit->applied_transaction_id);
        $this->assertNull($credit->actor_id);
        $this->assertDatabaseHas('activity_log', ['description' => 'customer_credit_auto_applied']);
    }

    public function test_gate_off_dispatches_nothing_and_job_is_a_noop_if_gate_flips_off_later(): void
    {
        $this->gate(false);
        $customer = $this->billable();
        $this->credit($customer, 100000);

        $invoice = $this->generate($customer);
        Queue::assertNotPushed(ApplyCustomerCreditsJob::class);

        // Direct job run with gate OFF must not apply either (checked again in the job).
        $this->runJob($invoice);
        $this->assertSame(0, Transaction::query()->count());
        $this->assertSame('unpaid', $invoice->fresh()->status);
    }

    public function test_code_default_is_off_when_setting_is_absent(): void
    {
        Setting::query()->where('key', CustomerAutoCreditGate::SETTING_KEY)->delete();
        Cache::flush();

        $this->assertFalse(CustomerAutoCreditGate::enabled());
    }

    public function test_no_credit_means_no_dispatch(): void
    {
        $invoice = $this->generate($this->billable());

        $this->assertNotNull($invoice);
        Queue::assertNotPushed(ApplyCustomerCreditsJob::class);
    }

    public function test_needs_review_and_other_status_credits_are_ignored(): void
    {
        $customer = $this->billable();
        $this->credit($customer, 100000, CustomerCredit::STATUS_NEEDS_REVIEW);
        $this->credit($customer, 100000, CustomerCredit::STATUS_DISMISSED);

        $invoice = $this->generate($customer);
        Queue::assertNotPushed(ApplyCustomerCreditsJob::class);

        // Even a direct call must not touch them.
        $result = app(CustomerCreditService::class)->autoApplyToInvoice($invoice->id);
        $this->assertSame(['applied' => 0.0, 'credits' => 0, 'settled' => false], $result);
        $this->assertSame(0, Transaction::query()->count());
    }

    public function test_existing_invoice_is_not_touched_on_regenerate(): void
    {
        $customer = $this->billable();
        $existing = $this->gatewayInvoice($customer, ['period' => '2026-10-01']);
        $this->credit($customer, 100000);

        $returned = app(InvoiceGenerationService::class)->ensureForPeriod($customer, Carbon::parse('2026-10-01'));

        $this->assertSame($existing->id, $returned->id);
        Queue::assertNotPushed(ApplyCustomerCreditsJob::class);
        $this->assertSame('unpaid', $existing->fresh()->status);
    }

    public function test_admin_created_invoice_does_not_dispatch(): void
    {
        $customer = $this->billable();
        $this->credit($customer, 100000);

        $this->gatewayInvoice($customer, ['period' => '2026-10-01']);

        Queue::assertNotPushed(ApplyCustomerCreditsJob::class);
    }

    // ---- amounts -----------------------------------------------------------

    public function test_credit_smaller_than_invoice_leaves_exact_remainder_unpaid(): void
    {
        $customer = $this->billable();
        $credit = $this->credit($customer, 40000);
        $invoice = $this->generate($customer);
        $this->runJob($invoice);

        $invoice->refresh();
        $this->assertSame('unpaid', $invoice->status);
        $this->assertSame(40000.0, $invoice->totalPaid());
        $this->assertSame(60000.0, round((float) $invoice->amount - $invoice->totalPaid(), 2));
        $this->assertSame(CustomerCredit::STATUS_APPLIED, $credit->fresh()->status);
        $this->assertSame('40000.00', (string) Transaction::query()->sole()->amount);
    }

    public function test_credit_larger_than_invoice_splits_and_keeps_remainder_available(): void
    {
        $customer = $this->billable();
        $credit = $this->credit($customer, 130000);
        $invoice = $this->generate($customer);
        $this->runJob($invoice);

        $this->assertSame('paid', $invoice->fresh()->status);
        $transaction = Transaction::query()->sole();
        $this->assertSame('100000.00', (string) $transaction->amount);
        $this->assertSame('credit:'.$credit->id.':'.$invoice->id.':130000.00', $transaction->idempotency_key);

        $credit->refresh();
        $this->assertSame(CustomerCredit::STATUS_AVAILABLE, $credit->status);
        $this->assertSame('30000.00', (string) $credit->amount);
        $split = CustomerCredit::query()->where('parent_credit_id', $credit->id)->sole();
        $this->assertSame(CustomerCredit::STATUS_APPLIED, $split->status);
        $this->assertSame('100000.00', (string) $split->amount);
        $this->assertSame($transaction->id, $split->applied_transaction_id);
        $this->assertSame(130000.0, (float) CustomerCredit::query()->sum('amount'), 'no money created or lost');
    }

    public function test_two_credits_apply_oldest_first_and_remainder_stays_on_the_second(): void
    {
        $customer = $this->billable();
        $older = $this->credit($customer, 70000);
        $newer = $this->credit($customer, 50000);
        $invoice = $this->generate($customer);
        $this->runJob($invoice);

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(CustomerCredit::STATUS_APPLIED, $older->fresh()->status);
        $newer->refresh();
        $this->assertSame(CustomerCredit::STATUS_AVAILABLE, $newer->status);
        $this->assertSame('20000.00', (string) $newer->amount);

        $amounts = Transaction::query()->where('invoice_id', $invoice->id)->orderBy('id')->pluck('amount')->map(fn ($a) => (string) $a)->all();
        $this->assertSame(['70000.00', '30000.00'], $amounts);
        $this->assertSame(100000.0, $invoice->fresh()->totalPaid());
    }

    public function test_two_small_credits_both_applied_invoice_stays_unpaid_with_exact_remainder(): void
    {
        $customer = $this->billable();
        $a = $this->credit($customer, 30000);
        $b = $this->credit($customer, 20000);
        $invoice = $this->generate($customer);
        $this->runJob($invoice);

        $invoice->refresh();
        $this->assertSame('unpaid', $invoice->status);
        $this->assertSame(50000.0, $invoice->totalPaid());
        $this->assertSame(CustomerCredit::STATUS_APPLIED, $a->fresh()->status);
        $this->assertSame(CustomerCredit::STATUS_APPLIED, $b->fresh()->status);
        $this->assertSame(2, Transaction::query()->count());
    }

    // ---- idempotency -------------------------------------------------------

    public function test_job_run_twice_creates_one_transaction(): void
    {
        $customer = $this->billable();
        $credit = $this->credit($customer, 100000);
        $invoice = $this->generate($customer);

        $this->runJob($invoice);
        $this->runJob($invoice);
        app(CustomerCreditService::class)->autoApplyToInvoice($invoice->id);

        $this->assertSame(1, Transaction::query()->count());
        $this->assertSame(100000.0, $invoice->fresh()->totalPaid());
        $this->assertSame(CustomerCredit::STATUS_APPLIED, $credit->fresh()->status);
    }

    public function test_second_credit_is_not_spent_on_an_already_settled_invoice(): void
    {
        $customer = $this->billable();
        $this->credit($customer, 100000);
        $invoice = $this->generate($customer);
        $this->runJob($invoice);

        $late = $this->credit($customer, 50000);
        $this->runJob($invoice);

        $this->assertSame(CustomerCredit::STATUS_AVAILABLE, $late->fresh()->status);
        $this->assertSame('50000.00', (string) $late->fresh()->amount);
        $this->assertSame(1, Transaction::query()->count());
    }

    public function test_invoice_paid_before_job_runs_is_a_noop(): void
    {
        $customer = $this->billable();
        $credit = $this->credit($customer, 100000);
        $invoice = $this->generate($customer);
        Transaction::create([
            'reference' => 'MANUAL-PRE', 'invoice_id' => $invoice->id, 'amount' => 100000,
            'channel' => 'manual', 'method' => 'cash', 'status' => 'verified', 'paid_at' => now(),
        ]);
        $invoice->update(['status' => 'paid']);

        $this->runJob($invoice);

        $this->assertSame(1, Transaction::query()->count());
        $this->assertSame(CustomerCredit::STATUS_AVAILABLE, $credit->fresh()->status);
        $this->assertSame('100000.00', (string) $credit->fresh()->amount);
    }

    public function test_void_invoice_is_a_noop(): void
    {
        $customer = $this->billable();
        $credit = $this->credit($customer, 100000);
        $invoice = $this->gatewayInvoice($customer, ['status' => 'void']);

        $this->assertSame(0, app(CustomerCreditService::class)->autoApplyToInvoice($invoice->id)['credits']);
        $this->assertSame(CustomerCredit::STATUS_AVAILABLE, $credit->fresh()->status);
        $this->assertSame(0, app(CustomerCreditService::class)->autoApplyToInvoice(999999)['credits']);
    }

    public function test_other_customers_credit_is_untouched(): void
    {
        $customer = $this->billable();
        $other = $this->billable();
        $mine = $this->credit($customer, 100000);
        $theirs = $this->credit($other, 100000);

        $invoice = $this->generate($customer);
        $this->runJob($invoice);

        $this->assertSame(CustomerCredit::STATUS_APPLIED, $mine->fresh()->status);
        $this->assertSame(CustomerCredit::STATUS_AVAILABLE, $theirs->fresh()->status);
        $this->assertSame('100000.00', (string) $theirs->fresh()->amount);
    }

    // ---- side effects ------------------------------------------------------

    public function test_isolated_customer_is_reconnected_when_credit_settles_the_invoice(): void
    {
        $customer = $this->billable(['status' => 'isolated', 'router_id' => $this->gatewayRouter()->id]);
        $this->credit($customer, 100000);
        $invoice = $this->gatewayInvoice($customer);

        $result = app(CustomerCreditService::class)->autoApplyToInvoice($invoice->id);

        $this->assertTrue($result['settled']);
        Queue::assertPushed(ReconnectCustomerJob::class, fn (ReconnectCustomerJob $job) => $job->customer->is($customer));
    }

    public function test_no_payment_wa_and_no_wa_log_rows(): void
    {
        $wa = Mockery::mock(BillingWaEventService::class);
        $wa->shouldNotReceive('notifyPayment');
        $this->app->instance(BillingWaEventService::class, $wa);

        $customer = $this->billable();
        $this->credit($customer, 100000);
        $invoice = $this->generate($customer);
        $this->runJob($invoice);

        $this->assertSame('paid', $invoice->fresh()->status);
        if (Schema::hasTable('billing_wa_logs')) {
            $this->assertSame(0, DB::table('billing_wa_logs')->count());
        }
    }

    public function test_reversing_an_auto_application_returns_the_money_to_the_credit(): void
    {
        $customer = $this->billable();
        $credit = $this->credit($customer, 100000);
        $invoice = $this->generate($customer);
        $this->runJob($invoice);
        $transaction = Transaction::query()->where('invoice_id', $invoice->id)->sole();
        $admin = $this->gatewayUser();

        app(PaymentReversalService::class)->reverse($invoice, $transaction, $admin, [
            'idempotency_key' => 'rev-auto-1',
            'reason' => 'Salah tagihan',
            'invoice_confirmation' => $invoice->code,
            'customer_confirmation' => (string) $customer->code,
        ]);

        $this->assertSame('unpaid', $invoice->fresh()->status);
        $this->assertSame(0.0, $invoice->fresh()->totalPaid());
        $credit->refresh();
        $this->assertSame(CustomerCredit::STATUS_AVAILABLE, $credit->status);
        $this->assertSame('100000.00', (string) $credit->amount);
    }

    // ---- catch-up sweep ----------------------------------------------------

    public function test_catch_up_sweep_applies_credit_that_appeared_after_the_invoice(): void
    {
        $customer = $this->billable();
        $invoice = $this->generate($customer);
        $credit = $this->credit($customer, 100000);

        Artisan::call('billing:generate', ['--catch-up' => true]);

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(CustomerCredit::STATUS_APPLIED, $credit->fresh()->status);
        $this->assertSame('100000.00', (string) Transaction::query()->sole()->amount);
        $this->assertStringContainsString('"credit_applied":1', Artisan::output());

        // Second run: nothing left to do, no double apply.
        Artisan::call('billing:generate', ['--catch-up' => true]);
        $this->assertSame(1, Transaction::query()->count());
    }

    public function test_catch_up_sweep_dry_run_writes_nothing(): void
    {
        $customer = $this->billable();
        $invoice = $this->generate($customer);
        $credit = $this->credit($customer, 100000);

        Artisan::call('billing:generate', ['--catch-up' => true, '--dry-run' => true]);

        $this->assertSame('unpaid', $invoice->fresh()->status);
        $this->assertSame(0, Transaction::query()->count());
        $this->assertSame(CustomerCredit::STATUS_AVAILABLE, $credit->fresh()->status);
        $this->assertSame('100000.00', (string) $credit->fresh()->amount);
    }

    public function test_catch_up_sweep_with_gate_off_applies_nothing(): void
    {
        $this->gate(false);
        $customer = $this->billable();
        $invoice = $this->generate($customer);
        $this->credit($customer, 100000);

        Artisan::call('billing:generate', ['--catch-up' => true]);

        $this->assertSame('unpaid', $invoice->fresh()->status);
        $this->assertSame(0, Transaction::query()->count());
        $this->assertStringNotContainsString('credit_applied', Artisan::output());
    }

    public function test_catch_up_sweep_ignores_admin_created_current_month_invoice(): void
    {
        $customer = $this->billable();
        $admin = $this->gatewayInvoice($customer, ['period' => '2026-10-01']);
        $credit = $this->credit($customer, 100000);

        Artisan::call('billing:generate', ['--catch-up' => true]);

        $this->assertSame('unpaid', $admin->fresh()->status);
        $this->assertSame(0, Transaction::query()->count());
        $this->assertSame(CustomerCredit::STATUS_AVAILABLE, $credit->fresh()->status);
        $this->assertStringContainsString('"credit_applied":0', Artisan::output());
    }

    public function test_catch_up_sweep_skips_non_billable_customer(): void
    {
        $customer = $this->billable();
        $invoice = $this->generate($customer);
        $credit = $this->credit($customer, 100000);
        $customer->update(['status' => 'inactive']);

        Artisan::call('billing:generate', ['--catch-up' => true]);
        $this->assertSame(0, app(CustomerCreditService::class)->autoApplyToInvoice($invoice->id)['credits']);

        $this->assertSame('unpaid', $invoice->fresh()->status);
        $this->assertSame(CustomerCredit::STATUS_AVAILABLE, $credit->fresh()->status);
    }

    public function test_reversed_auto_application_is_not_respent_by_job_or_sweep(): void
    {
        $customer = $this->billable();
        $credit = $this->credit($customer, 100000);
        $invoice = $this->generate($customer);
        $this->runJob($invoice);
        $this->reverseAll($invoice, $customer, 'rev-m1');

        Artisan::call('billing:generate', ['--catch-up' => true]);
        $this->runJob($invoice);

        $invoice->refresh();
        $this->assertSame('unpaid', $invoice->status);
        $this->assertSame(0.0, $invoice->totalPaid());
        $this->assertSame(1, Transaction::query()->count());
        $this->assertSame(1, PaymentReversal::query()->count());
        $credit->refresh();
        $this->assertSame(CustomerCredit::STATUS_AVAILABLE, $credit->status);
        $this->assertSame('100000.00', (string) $credit->amount);
    }

    public function test_reversed_split_application_is_not_respent_either(): void
    {
        $customer = $this->billable();
        $this->credit($customer, 130000);
        $invoice = $this->generate($customer);
        $this->runJob($invoice);
        $this->reverseAll($invoice, $customer, 'rev-m1-split');

        Artisan::call('billing:generate', ['--catch-up' => true]);

        $this->assertSame('unpaid', $invoice->fresh()->status);
        $this->assertSame(1, Transaction::query()->count());
        $this->assertSame(130000.0, (float) CustomerCredit::query()->where('status', CustomerCredit::STATUS_AVAILABLE)->sum('amount'));
    }

    public function test_dispatch_failure_never_aborts_invoice_generation(): void
    {
        $customer = $this->billable();
        $this->credit($customer, 100000);
        $bus = Mockery::mock(Dispatcher::class);
        $bus->shouldReceive('dispatch')->andThrow(new RuntimeException('redis down'));
        $this->app->instance(Dispatcher::class, $bus);

        $invoice = app(InvoiceGenerationService::class)->ensureForPeriod($customer, Carbon::parse('2026-10-01'));

        $this->assertNotNull($invoice);
        $this->assertSame('unpaid', $invoice->fresh()->status);
    }

    public function test_auto_apply_locks_invoice_before_credits_and_flushes_analytics(): void
    {
        $customer = $this->billable();
        $this->credit($customer, 100000);
        $invoice = $this->generate($customer);
        Cache::put('analytics.mrr.superadmin', 'stale');

        DB::enableQueryLog();
        app(CustomerCreditService::class)->autoApplyToInvoice($invoice->id);
        $sql = array_map(fn (array $q) => strtolower((string) $q['query']), DB::getQueryLog());
        DB::disableQueryLog();

        $at = function (string $table) use ($sql): ?int {
            foreach ($sql as $i => $q) {
                if (preg_match('/^select .* from [`"]?'.$table.'[`"]? where/', $q)) {
                    return $i;
                }
            }

            return null;
        };
        $this->assertNotNull($at('invoices'));
        $this->assertNotNull($at('customer_credits'));
        $this->assertLessThan($at('customer_credits'), $at('invoices'));
        $this->assertNull(Cache::get('analytics.mrr.superadmin'));
    }

    public function test_every_ensure_for_period_invoice_gets_the_marker(): void
    {
        $invoice = $this->generate($this->billable());

        $this->assertDatabaseHas('activity_log', [
            'subject_type' => $invoice->getMorphClass(),
            'subject_id' => $invoice->id,
            'description' => InvoiceGenerationService::AUTO_GENERATED_MARKER,
        ]);
    }

    public function test_catch_up_sweep_ignores_arrears_of_older_periods(): void
    {
        $customer = $this->billable();
        $old = $this->gatewayInvoice($customer, ['period' => '2026-09-01', 'status' => 'overdue']);
        $this->credit($customer, 100000);

        Artisan::call('billing:generate', ['--catch-up' => true]);

        $this->assertSame('overdue', $old->fresh()->status);
        $this->assertSame(0, Transaction::query()->where('invoice_id', $old->id)->count());
    }

    // ---- helpers -----------------------------------------------------------

    private function gate(bool $on): void
    {
        Setting::set(CustomerAutoCreditGate::SETTING_KEY, $on ? '1' : '0', 'boolean', 'billing');
    }

    private function billable(array $overrides = []): Customer
    {
        return $this->gatewayCustomer($overrides + ['join_date' => '2026-08-15', 'due_day' => 15]);
    }

    private function generate(Customer $customer): Invoice
    {
        $invoice = app(InvoiceGenerationService::class)->ensureForPeriod($customer, Carbon::parse('2026-10-01'));
        $this->assertNotNull($invoice);

        return $invoice;
    }

    private function runJob(Invoice $invoice): void
    {
        (new ApplyCustomerCreditsJob($invoice->id))->handle(app(CustomerCreditService::class));
    }

    private function reverseAll(Invoice $invoice, Customer $customer, string $key): void
    {
        $admin = $this->gatewayUser();
        foreach (Transaction::query()->where('invoice_id', $invoice->id)->get() as $i => $transaction) {
            app(PaymentReversalService::class)->reverse($invoice->fresh(), $transaction, $admin, [
                'idempotency_key' => $key.'-'.$i,
                'reason' => 'Refund manual',
                'invoice_confirmation' => $invoice->code,
                'customer_confirmation' => (string) $customer->code,
            ]);
        }
    }

    private function credit(Customer $customer, int $amount, string $status = CustomerCredit::STATUS_AVAILABLE): CustomerCredit
    {
        return CustomerCredit::create([
            'customer_id' => $customer->id,
            'provider' => 'duitku',
            'gateway_reference' => 'INV0-AUTO'.uniqid(),
            'amount' => $amount,
            'reason_code' => CustomerCredit::REASON_DOUBLE_PAYMENT,
            'status' => $status,
        ]);
    }
}
