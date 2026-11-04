<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\Transaction;
use App\Models\User;
use App\Services\OpsAlertService;
use App\Services\PaymentGatewayService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\GatewayFixtures;
use Tests\TestCase;

/**
 * PR-A: paid_at must not be in the future (WIB), overpay stays refused on every
 * path (no credit/deposit), and the CSV import accepts any non-void, non-settled
 * invoice like the single payment form.
 */
#[Group('blocking-billing')]
class PaymentPaidAtOverpayCsvPolicyTest extends TestCase
{
    use GatewayFixtures;
    use RefreshDatabase;

    private const FUTURE_MESSAGE = 'Tanggal bayar tidak boleh di masa depan.';

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Queue::fake();
        Http::fake();
        // 2026-10-13 12:00 WIB.
        $this->freeze('2026-10-13 05:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    // ---- Item 2: paid_at ----------------------------------------------------

    public function test_single_payment_tomorrow_is_rejected(): void
    {
        [$admin, $invoice] = $this->fixture();

        $this->actingAs($admin)->post(route('payments.store', $invoice), $this->payload($invoice, [
            'paid_at' => '2026-10-14',
        ]))->assertSessionHasErrors(['paid_at' => self::FUTURE_MESSAGE]);

        $this->assertSame(0, Transaction::count());
        $this->assertSame('unpaid', $invoice->fresh()->status);
    }

    public function test_single_payment_uses_wib_calendar_day_not_utc(): void
    {
        // 2026-10-13 20:00 UTC = 2026-10-14 03:00 WIB, so WIB today is the 14th.
        $this->freeze('2026-10-13 20:00:00');
        [$admin, $invoice] = $this->fixture();

        $this->actingAs($admin)->post(route('payments.store', $invoice), $this->payload($invoice, [
            'paid_at' => '2026-10-15',
        ]))->assertSessionHasErrors(['paid_at' => self::FUTURE_MESSAGE]);

        // A date-only value equal to WIB today means "now".
        $this->actingAs($admin)->post(route('payments.store', $invoice), $this->payload($invoice, [
            'paid_at' => '2026-10-14',
        ]))->assertSessionHasNoErrors();

        $this->assertSame(1, Transaction::count());
        $this->assertSame('2026-10-13 20:00:00', $this->storedPaidAt(Transaction::sole()));
    }

    public function test_single_payment_today_later_time_is_clamped_to_now(): void
    {
        [$admin, $invoice] = $this->fixture();

        // now = 05:00 UTC (12:00 WIB); 20:00 WIB is later but the same WIB day.
        $this->actingAs($admin)->post(route('payments.store', $invoice), $this->payload($invoice, [
            'paid_at' => '2026-10-13 20:00:00',
        ]))->assertSessionHasNoErrors();

        $this->assertSame('2026-10-13 05:00:00', $this->storedPaidAt(Transaction::sole()));
    }

    public function test_single_payment_offset_string_is_stored_as_the_utc_instant(): void
    {
        $this->freeze('2026-10-13 15:00:00');
        [$admin, $invoice] = $this->fixture();

        $this->actingAs($admin)->post(route('payments.store', $invoice), $this->payload($invoice, [
            'paid_at' => '2026-10-13T20:00:00+07:00',
        ]))->assertSessionHasNoErrors();

        $this->assertSame('2026-10-13 13:00:00', $this->storedPaidAt(Transaction::sole()));
    }

    public function test_single_payment_z_string_is_accepted_and_stored_as_is(): void
    {
        [$admin, $invoice] = $this->fixture();

        $this->actingAs($admin)->post(route('payments.store', $invoice), $this->payload($invoice, [
            'paid_at' => '2026-10-13T03:00:00Z',
        ]))->assertSessionHasNoErrors();

        $this->assertSame('2026-10-13 03:00:00', $this->storedPaidAt(Transaction::sole()));
    }

    public function test_single_payment_tz_less_string_is_wib(): void
    {
        $this->freeze('2026-10-13 15:00:00');
        [$admin, $invoice] = $this->fixture();

        $this->actingAs($admin)->post(route('payments.store', $invoice), $this->payload($invoice, [
            'paid_at' => '2026-10-13T20:00',
        ]))->assertSessionHasNoErrors();

        $this->assertSame('2026-10-13 13:00:00', $this->storedPaidAt(Transaction::sole()));
    }

    public function test_single_payment_near_wib_midnight(): void
    {
        // 16:30 UTC = 23:30 WIB on 10-13.
        $this->freeze('2026-10-13 16:30:00');
        [$admin, $invoice] = $this->fixture();

        // 00:30 WIB on the 14th is a future WIB day.
        $this->actingAs($admin)->post(route('payments.store', $invoice), $this->payload($invoice, [
            'paid_at' => '2026-10-14T00:30:00+07:00',
        ]))->assertSessionHasErrors(['paid_at' => self::FUTURE_MESSAGE]);
        $this->assertSame(0, Transaction::count());

        $this->actingAs($admin)->post(route('payments.store', $invoice), $this->payload($invoice, [
            'paid_at' => '2026-10-13T23:00:00+07:00',
        ]))->assertSessionHasNoErrors();
        $this->assertSame('2026-10-13 16:00:00', $this->storedPaidAt(Transaction::sole()));
    }

    public function test_reviewer_scenario_later_time_same_wib_day_is_clamped_never_future(): void
    {
        // 15:00 UTC = 22:00 WIB; 23:30 WIB is later than now on the same WIB day.
        $this->freeze('2026-10-13 15:00:00');
        [$admin, $invoice] = $this->fixture();

        $this->actingAs($admin)->post(route('payments.store', $invoice), $this->payload($invoice, [
            'paid_at' => '2026-10-13T23:30:00+07:00',
        ]))->assertSessionHasNoErrors();

        $this->assertSame('2026-10-13 15:00:00', $this->storedPaidAt(Transaction::sole()));
    }

    public function test_paid_at_form_sends_wib_offset_and_label(): void
    {
        $show = (string) file_get_contents(base_path('resources/js/Pages/Invoices/Show.tsx'));
        $helper = (string) file_get_contents(base_path('resources/js/lib/dateTime.ts'));

        $this->assertStringContainsString('Payment Date (WIB)', $show);
        $this->assertStringContainsString('formatWibDateTimeLocalInput(new Date())', $show);
        $this->assertStringContainsString('wibLocalInputToOffsetString(formData.paid_at)', $show);
        $this->assertStringNotContainsString('toISOString', $show);
        $this->assertStringContainsString('+07:00', $helper);
    }

    public function test_single_payment_without_paid_at_defaults_to_now(): void
    {
        [$admin, $invoice] = $this->fixture();

        $this->actingAs($admin)->post(route('payments.store', $invoice), $this->payload($invoice))
            ->assertSessionHasNoErrors();

        $this->assertTrue(Transaction::sole()->paid_at->equalTo(Carbon::now()));
    }

    public function test_single_payment_last_month_is_allowed_and_kept(): void
    {
        [$admin, $invoice] = $this->fixture();

        $this->actingAs($admin)->post(route('payments.store', $invoice), $this->payload($invoice, [
            'paid_at' => '2026-09-20',
        ]))->assertSessionHasNoErrors();

        // Date-only past = that day at 12:00 WIB (05:00 UTC).
        $this->assertSame('2026-09-20 05:00:00', $this->storedPaidAt(Transaction::sole()));
    }

    public function test_csv_future_paid_at_row_gets_a_row_error(): void
    {
        [$admin, $invoice] = $this->fixture();

        $response = $this->csv($admin, [
            'invoice_id,amount,method,paid_at,idempotency_key',
            $invoice->id.',100000,cash,2026-11-01,csv-future-1',
        ]);

        $response->assertSessionHasErrors('csv_file');
        $this->assertStringContainsString(self::FUTURE_MESSAGE, session('errors')->first('csv_file'));
        $this->assertSame(0, Transaction::count());
        $this->assertSame('unpaid', $invoice->fresh()->status);
    }

    public function test_csv_past_and_clamped_today_rows_are_accepted(): void
    {
        [$admin, $invoice, $customer] = $this->fixture();
        $other = $this->secondInvoice($customer);

        $this->csv($admin, [
            'invoice_id,amount,method,paid_at,idempotency_key',
            $invoice->id.',100000,cash,2026-09-01,csv-past-1',
            $other->id.',100000,cash,2026-10-13 20:00:00,csv-today-1',
        ])->assertSessionHasNoErrors();

        // tz-less CSV values are WIB.
        $this->assertSame('2026-09-01 05:00:00', $this->storedPaidAt(Transaction::where('invoice_id', $invoice->id)->sole()));
        $this->assertSame('2026-10-13 05:00:00', $this->storedPaidAt(Transaction::where('invoice_id', $other->id)->sole()));
    }

    public function test_csv_date_only_stays_in_its_month_for_utc_reports(): void
    {
        $this->freeze('2026-11-03 05:00:00');
        [$admin, $invoice] = $this->fixture();

        $this->csv($admin, [
            'invoice_id,amount,method,paid_at,idempotency_key',
            $invoice->id.',100000,cash,2026-11-01,csv-nov-1',
        ])->assertSessionHasNoErrors();

        $this->assertSame('2026-11-01 05:00:00', $this->storedPaidAt(Transaction::sole()));
    }

    public function test_csv_tz_less_time_is_wib(): void
    {
        $this->freeze('2026-10-13 15:00:00');
        [$admin, $invoice] = $this->fixture();

        $this->csv($admin, [
            'invoice_id,amount,method,paid_at,idempotency_key',
            $invoice->id.',100000,cash,2026-10-13 20:00,csv-wib-1',
        ])->assertSessionHasNoErrors();

        $this->assertSame('2026-10-13 13:00:00', $this->storedPaidAt(Transaction::sole()));
    }

    public function test_bulk_future_paid_at_is_a_validation_error(): void
    {
        [$admin, $invoice] = $this->fixture();

        $this->actingAs($admin)->post(route('payments.bulk'), $this->bulkPayload([$invoice], [
            'paid_at' => '2026-10-14',
        ]))->assertSessionHasErrors(['paid_at' => self::FUTURE_MESSAGE]);

        $this->assertSame(0, Transaction::count());
        $this->assertSame('unpaid', $invoice->fresh()->status);
    }

    public function test_bulk_past_date_is_kept_and_today_later_time_is_clamped(): void
    {
        [$admin, $invoice, $customer] = $this->fixture();
        $other = $this->secondInvoice($customer);

        $this->actingAs($admin)->post(route('payments.bulk'), $this->bulkPayload([$invoice], [
            'paid_at' => '2026-09-05',
            'idempotency_key' => 'bulk-past',
        ]))->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('payments.bulk'), $this->bulkPayload([$other], [
            'paid_at' => '2026-10-13 20:00:00',
            'idempotency_key' => 'bulk-today',
        ]))->assertSessionHasNoErrors();

        $this->assertSame('2026-09-05 05:00:00', $this->storedPaidAt(Transaction::where('invoice_id', $invoice->id)->sole()));
        $this->assertSame('2026-10-13 05:00:00', $this->storedPaidAt(Transaction::where('invoice_id', $other->id)->sole()));
    }

    // ---- Item 3: overpay stays refused (tests only) ------------------------

    public function test_manual_partial_then_remaining_then_anything_more_is_refused(): void
    {
        [$admin, $invoice] = $this->fixture();

        $this->actingAs($admin)->post(route('payments.store', $invoice), $this->payload($invoice, [
            'amount' => 40000, 'idempotency_key' => 'op-1',
        ]))->assertSessionHasNoErrors();
        $this->assertSame('unpaid', $invoice->fresh()->status);

        // Remaining is 60000: one rupiah more is refused.
        $this->actingAs($admin)->post(route('payments.store', $invoice), $this->payload($invoice, [
            'amount' => 60001, 'idempotency_key' => 'op-2',
        ]))->assertSessionHasErrors('amount');
        $this->assertSame(1, Transaction::count());

        $this->actingAs($admin)->post(route('payments.store', $invoice), $this->payload($invoice, [
            'amount' => 60000, 'idempotency_key' => 'op-3',
        ]))->assertSessionHasNoErrors();
        $this->assertSame('paid', $invoice->fresh()->status);

        $this->actingAs($admin)->post(route('payments.store', $invoice), $this->payload($invoice, [
            'amount' => 1, 'idempotency_key' => 'op-4',
        ]))->assertSessionHasErrors(['amount' => 'Invoice sudah lunas.']);

        $this->assertSame(2, Transaction::count());
        $this->assertSame(100000.0, $invoice->fresh()->totalPaid());
    }

    public function test_csv_overpay_is_refused(): void
    {
        [$admin, $invoice] = $this->fixture();

        $this->csv($admin, [
            'invoice_id,amount,method,idempotency_key',
            $invoice->id.',100001,cash,csv-over-1',
        ])->assertSessionHasErrors('csv_file');

        $this->assertSame(0, Transaction::count());
        $this->assertSame('unpaid', $invoice->fresh()->status);

        // Partial then an amount above the remaining balance is refused too.
        $this->csv($admin, [
            'invoice_id,amount,method,idempotency_key',
            $invoice->id.',40000,cash,csv-over-2',
            $invoice->id.',60001,cash,csv-over-3',
        ])->assertSessionHasErrors('csv_file');

        $this->assertSame(1, Transaction::count());
        $this->assertSame(40000.0, $invoice->fresh()->totalPaid());
    }

    public function test_bulk_skips_an_invoice_that_already_has_a_partial_payment(): void
    {
        [$admin, $invoice] = $this->fixture();
        Transaction::create([
            'invoice_id' => $invoice->id,
            'reference' => 'PARTIAL-1',
            'idempotency_key' => 'partial-1',
            'channel' => 'manual',
            'amount' => 40000,
            'status' => 'verified',
            'method' => 'cash',
            'paid_at' => now(),
        ]);

        $this->actingAs($admin)->post(route('payments.bulk'), $this->bulkPayload([$invoice]))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Transaction::count());
        $this->assertSame(40000.0, $invoice->fresh()->totalPaid());
        $this->assertSame('unpaid', $invoice->fresh()->status);
    }

    public function test_gateway_amount_above_remaining_is_rejected_without_transaction(): void
    {
        $this->setPaymentWrites(true);
        $alerts = Mockery::mock(OpsAlertService::class);
        $alerts->shouldReceive('send')->andReturnTrue();
        $this->app->instance(OpsAlertService::class, $alerts);

        [, $invoice] = $this->fixture();
        Transaction::create([
            'invoice_id' => $invoice->id,
            'reference' => 'PARTIAL-GW',
            'idempotency_key' => 'partial-gw',
            'channel' => 'manual',
            'amount' => 40000,
            'status' => 'verified',
            'method' => 'cash',
            'paid_at' => now(),
        ]);

        $event = app(PaymentGatewayService::class)->process(
            provider: 'midtrans',
            eventKey: 'gw-over-1',
            creditKey: 'gw-over-1',
            reference: 'gw-over-1',
            status: 'settlement',
            amount: 60001.0, // remaining 60000 + 1
            paid: true,
            payload: [],
            eventStatusOverride: null,
            method: 'qris',
            invoice: $invoice,
        );

        $this->assertSame(PaymentGatewayService::STATUS_REJECTED_AMOUNT, $event->status);
        $this->assertSame(1, Transaction::count());
        $this->assertSame(0, Transaction::where('channel', 'gateway')->count());
        $this->assertSame('unpaid', $invoice->fresh()->status);
    }

    // ---- Item 4: CSV status policy ----------------------------------------

    public function test_csv_accepts_overdue_invoice_with_remaining_balance(): void
    {
        [$admin, $invoice] = $this->fixture();
        $invoice->update(['status' => 'overdue']);
        Transaction::create([
            'invoice_id' => $invoice->id,
            'reference' => 'PARTIAL-OD',
            'idempotency_key' => 'partial-od',
            'channel' => 'manual',
            'amount' => 40000,
            'status' => 'verified',
            'method' => 'cash',
            'paid_at' => now(),
        ]);

        $this->csv($admin, [
            'invoice_id,amount,method,idempotency_key',
            $invoice->id.',60000,cash,csv-overdue-1',
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, Transaction::count());
        $this->assertSame('paid', $invoice->fresh()->status);
    }

    public function test_csv_refuses_void_invoice(): void
    {
        [$admin, $invoice] = $this->fixture();
        $invoice->update(['status' => 'void']);

        $this->csv($admin, [
            'invoice_id,amount,method,idempotency_key',
            $invoice->id.',100000,cash,csv-void-1',
        ])->assertSessionHasErrors('csv_file');

        $this->assertStringContainsString(
            'Invoice yang sudah void tidak dapat menerima pembayaran.',
            session('errors')->first('csv_file'),
        );
        $this->assertSame(0, Transaction::count());
        $this->assertSame('void', $invoice->fresh()->status);
    }

    public function test_csv_refuses_settled_invoice_including_legacy_paid_without_transactions(): void
    {
        [$admin, $invoice, $customer] = $this->fixture();
        $legacy = $this->secondInvoice($customer);
        $legacy->update(['status' => 'paid']); // legacy: paid, no transaction rows

        $this->csv($admin, [
            'invoice_id,amount,method,idempotency_key',
            $legacy->id.',100000,cash,csv-legacy-1',
        ])->assertSessionHasErrors('csv_file');
        $this->assertStringContainsString('Invoice sudah lunas.', session('errors')->first('csv_file'));
        $this->assertSame(0, Transaction::count());

        // Settled through transactions, even if the status lags behind.
        Transaction::create([
            'invoice_id' => $invoice->id,
            'reference' => 'FULL-1',
            'idempotency_key' => 'full-1',
            'channel' => 'manual',
            'amount' => 100000,
            'status' => 'verified',
            'method' => 'cash',
            'paid_at' => now(),
        ]);
        $this->csv($admin, [
            'invoice_id,amount,method,idempotency_key',
            $invoice->id.',1,cash,csv-settled-1',
        ])->assertSessionHasErrors('csv_file');
        $this->assertSame(1, Transaction::count());
    }

    public function test_csv_accepts_reversed_then_unpaid_again_invoice(): void
    {
        [$admin, $invoice] = $this->fixture();
        $transaction = Transaction::create([
            'invoice_id' => $invoice->id,
            'reference' => 'REV-CSV-1',
            'idempotency_key' => 'rev-csv-1',
            'channel' => 'manual',
            'admin_id' => $admin->id,
            'amount' => 100000,
            'status' => 'paid',
            'method' => 'cash',
            'paid_at' => now(),
        ]);
        $invoice->update(['status' => 'paid']);

        $this->actingAs($admin)->post(route('payments.reverse', [$invoice, $transaction]), [
            'invoice_confirmation' => $invoice->code,
            'customer_confirmation' => $invoice->customer->code,
            'reason' => 'Salah memilih invoice saat input pembayaran',
            'idempotency_key' => 'rev-csv-key',
        ])->assertRedirect();
        $this->assertSame('unpaid', $invoice->fresh()->status);

        $this->csv($admin, [
            'invoice_id,amount,method,idempotency_key',
            $invoice->id.',100000,cash,csv-after-reversal',
        ])->assertSessionHasNoErrors();

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(100000.0, $invoice->fresh()->totalPaid());
    }

    public function test_csv_summary_counts_failed_validation_rows(): void
    {
        [$admin, $invoice, $customer] = $this->fixture();
        $voided = $this->secondInvoice($customer);
        $voided->update(['status' => 'void']);

        $this->csv($admin, [
            'invoice_id,amount,method,paid_at,idempotency_key',
            $invoice->id.',100000,cash,,csv-sum-ok',
            $voided->id.',100000,cash,,csv-sum-void',
            $invoice->id.',100000,cash,2026-12-01,csv-sum-future',
        ])->assertSessionHas('success', 'CSV payment selesai: 1 diproses, 2 gagal validasi, 0 dilewati.');
    }

    // ---- helpers ------------------------------------------------------------

    private function storedPaidAt(Transaction $transaction): string
    {
        return $transaction->paid_at->copy()->utc()->format('Y-m-d H:i:s');
    }

    private function freeze(string $utc): void
    {
        Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse($utc, 'UTC'));
    }

    /**
     * @param  list<string>  $lines
     */
    private function csv(User $admin, array $lines)
    {
        $csv = implode(PHP_EOL, $lines).PHP_EOL;

        return $this->actingAs($admin)->post(route('payments.bulk-import'), [
            'csv_file' => UploadedFile::fake()->createWithContent('payments.csv', $csv),
        ])->assertRedirect();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(Invoice $invoice, array $overrides = []): array
    {
        return array_merge([
            'invoice_confirmation' => $invoice->code,
            'customer_confirmation' => $invoice->customer->code,
            'amount' => 100000,
            'method' => 'cash',
            'idempotency_key' => 'pay-'.uniqid(),
        ], $overrides);
    }

    /**
     * @param  list<Invoice>  $invoices
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function bulkPayload(array $invoices, array $overrides = []): array
    {
        return array_merge([
            'invoice_ids' => array_map(fn (Invoice $invoice): int => $invoice->id, $invoices),
            'method' => 'cash',
            'idempotency_key' => 'bulk-'.uniqid(),
            'confirmed' => true,
            'invoice_confirmations' => array_map(function (Invoice $invoice): array {
                $invoice->refresh()->load('customer');

                return [
                    'id' => $invoice->id,
                    'code' => $invoice->code,
                    'customer_code' => $invoice->customer->code,
                    'customer_name' => $invoice->customer->name,
                    'area_id' => $invoice->customer->area_id,
                    'period' => $invoice->period->toDateString(),
                    'amount' => $invoice->amount,
                    'due_date' => $invoice->due_date?->toDateString(),
                    'status' => $invoice->status,
                ];
            }, $invoices),
        ], $overrides);
    }

    private function secondInvoice(Customer $customer): Invoice
    {
        return Invoice::create([
            'code' => 'INV-POL-'.uniqid(),
            'customer_id' => $customer->id,
            'period' => now()->subMonth()->startOfMonth(),
            'amount' => 100000,
            'due_date' => now()->addDays(7),
            'status' => 'unpaid',
            'generated_at' => now(),
        ]);
    }

    /**
     * @return array{0: User, 1: Invoice, 2: Customer}
     */
    private function fixture(): array
    {
        $area = Area::create(['code' => 'POL', 'name' => 'Policy Test']);
        $package = Package::create(['code' => 'POL100', 'name' => 'Policy Test', 'price' => 100000]);
        $customer = Customer::create([
            'code' => 'POL001',
            'name' => 'Policy Test Customer',
            'phone' => '081234567890',
            'address' => 'Test address',
            'area_id' => $area->id,
            'package_id' => $package->id,
            'pppoe_user' => 'policy.test',
            'status' => 'active',
        ]);
        $invoice = Invoice::create([
            'code' => 'INV-POL-001',
            'customer_id' => $customer->id,
            'period' => now()->startOfMonth(),
            'amount' => 100000,
            'due_date' => now()->addDays(7),
            'status' => 'unpaid',
            'generated_at' => now(),
        ]);

        return [User::factory()->create(['role' => 'superadmin']), $invoice->load('customer'), $customer];
    }
}
