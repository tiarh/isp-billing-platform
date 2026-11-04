<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\PaymentGatewayTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\InvoiceGenerationService;
use App\Support\OpsTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Storage is UTC, every user-visible day/month is WIB (UTC+7). These tests
 * freeze the clock at 2026-11-01 03:00 WIB (= 2026-10-31 20:00 UTC) where the
 * UTC month is still October but the WIB month is already November.
 */
#[Group('blocking-core')]
class WibDateLogicTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        // 2026-11-01 03:00 WIB == 2026-10-31 20:00 UTC
        Carbon::setTestNow(Carbon::parse('2026-11-01 03:00:00', 'Asia/Jakarta'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'superadmin']);
    }

    private function customer(string $code = 'W001'): Customer
    {
        $area = Area::firstOrCreate(['code' => 'WA'], ['name' => 'Area W']);
        $package = Package::firstOrCreate(['code' => 'WBASIC'], ['name' => 'Basic', 'price' => 100000]);

        return Customer::create([
            'code' => $code,
            'name' => 'Customer '.$code,
            'phone' => '0811111111',
            'address' => 'Addr',
            'area_id' => $area->id,
            'package_id' => $package->id,
            'pppoe_user' => strtolower($code),
            'status' => 'active',
            'join_date' => '2026-01-05',
        ]);
    }

    private function invoice(Customer $customer, string $period, string $dueDate, string $status = 'unpaid'): Invoice
    {
        return Invoice::create([
            'customer_id' => $customer->id,
            'period' => $period,
            'amount' => 100000,
            'due_date' => $dueDate,
            'status' => $status,
            'generated_at' => '2026-10-01 00:00:00',
        ]);
    }

    private function paidTransaction(Invoice $invoice, string $reference, string $paidAtUtc, int $amount): void
    {
        $tx = Transaction::create([
            'reference' => $reference,
            'invoice_id' => $invoice->id,
            'amount' => $amount,
            'channel' => 'manual',
            'method' => 'cash',
            'status' => 'paid',
            'paid_at' => '2026-01-01 00:00:00',
        ]);
        DB::table('transactions')->where('id', $tx->id)->update(['paid_at' => $paidAtUtc]);
    }

    public function test_dashboard_actual_revenue_uses_the_wib_month(): void
    {
        $customer = $this->customer();
        $invoice = $this->invoice($customer, '2026-11-01', '2026-11-10');

        // 2026-10-31 23:00 WIB: October. 2026-11-01 03:00 WIB: November.
        $this->paidTransaction($invoice, 'WIB-OCT', '2026-10-31 16:00:00', 111);
        $this->paidTransaction($invoice, 'WIB-NOV', '2026-10-31 20:00:00', 222);

        $this->actingAs($this->admin())
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.actual_revenue', fn ($v) => (float) $v === 222.0)
                ->where('stats.unpaid_invoices', 1));
    }

    public function test_dashboard_overdue_cutoff_uses_the_wib_date(): void
    {
        $customer = $this->customer();
        // Grace 7 days, WIB today 2026-11-01: cutoff 2026-10-25 (today - 7), counted when due <= cutoff
        // (same rule as BillingEligibilityService isolation).
        $this->invoice($customer, '2026-10-01', '2026-10-25');
        // Due after the cutoff: still inside grace.
        $this->invoice($customer, '2026-08-01', '2026-10-26');

        $this->actingAs($this->admin())
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('stats.overdue_count', 1));
    }

    public function test_analytics_mrr_uses_the_wib_month(): void
    {
        $customer = $this->customer();
        $this->invoice($customer, '2026-10-01', '2026-10-10')->update(['amount' => 50000]);
        $this->invoice($customer, '2026-11-01', '2026-11-10')->update(['amount' => 999]);

        $response = $this->actingAs($this->admin())->getJson(route('api.analytics.mrr'))->assertOk();

        // In WIB it is already November, so "last month" is October: (100000-50000)/50000.
        $this->assertEquals(100.0, $response->json('growth_percentage'));
    }

    public function test_analytics_collection_rate_current_period_is_the_wib_month(): void
    {
        $customer = $this->customer();
        $this->invoice($customer, '2026-10-01', '2026-10-10', 'paid');
        $this->invoice($customer, '2026-11-01', '2026-11-10', 'unpaid');

        $response = $this->actingAs($this->admin())->getJson(route('api.analytics.collection-rate'))->assertOk();

        $this->assertSame(1, (int) $response->json('total_count'));
        $this->assertEquals(0.0, $response->json('collection_rate'));
    }

    public function test_analytics_outstanding_aging_today_is_the_wib_date(): void
    {
        $customer = $this->customer();
        // Due yesterday (WIB) = overdue; due today (WIB) = not overdue yet.
        $this->invoice($customer, '2026-10-01', '2026-10-31');
        $this->invoice($customer, '2026-09-01', '2026-11-01');

        $rows = $this->actingAs($this->admin())
            ->getJson(route('api.analytics.outstanding-aging'))
            ->assertOk()
            ->json();

        $this->assertCount(1, $rows);
        $this->assertSame(1, (int) ($rows[0]['invoice_count'] ?? 0));
        $this->assertSame('0-30 days', $rows[0]['age_bucket'] ?? null);
    }

    public function test_payment_gateway_transaction_date_filter_uses_wib_days(): void
    {
        $customer = $this->customer();
        $invoice = $this->invoice($customer, '2026-11-01', '2026-11-10');

        // 2026-10-31 20:00 UTC = 2026-11-01 03:00 WIB.
        $inNov = $this->gatewayTransaction($invoice, 'WIB-PG-NOV', '2026-10-31 20:00:00');
        // 2026-10-31 16:00 UTC = 2026-10-31 23:00 WIB.
        $inOct = $this->gatewayTransaction($invoice, 'WIB-PG-OCT', '2026-10-31 16:00:00');

        $ids = fn (array $query) => collect(
            $this->actingAs($this->admin())
                ->get(route('payment-gateway.transactions', $query))
                ->assertOk()
                ->viewData('page')['props']['transactions']['data']
        )->pluck('id')->sort()->values()->all();

        $this->assertSame([$inNov], $ids(['date_from' => '2026-11-01', 'date_to' => '2026-11-01']));
        $this->assertSame([$inOct], $ids(['date_from' => '2026-10-31', 'date_to' => '2026-10-31']));
    }

    public function test_payment_gateway_transaction_rejects_impossible_dates(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->getJson(route('payment-gateway.transactions', ['date_from' => '2026-13-45']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('date_from');

        $this->actingAs($admin)
            ->getJson(route('payment-gateway.transactions', ['date_to' => '2026-13-45']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('date_to');

        // Inertia/browser request is redirected back with the error instead of a 500.
        $this->actingAs($admin)
            ->from(route('payment-gateway.transactions'))
            ->get(route('payment-gateway.transactions', ['date_from' => '2026-13-45']))
            ->assertRedirect(route('payment-gateway.transactions'))
            ->assertSessionHasErrors('date_from');

        // Valid and empty values still work.
        $this->actingAs($admin)
            ->get(route('payment-gateway.transactions', ['date_from' => '2026-11-01', 'date_to' => '']))
            ->assertOk();
    }

    public function test_analytics_cache_key_changes_across_a_wib_month_boundary(): void
    {
        $customer = $this->customer();
        $this->invoice($customer, '2026-10-01', '2026-10-10')->update(['amount' => 50000]);
        $admin = $this->admin();

        // 2026-10-31 23:00 WIB: still October in WIB (and UTC).
        Carbon::setTestNow(Carbon::parse('2026-10-31 23:00:00', 'Asia/Jakarta'));
        $this->actingAs($admin)->getJson(route('api.analytics.mrr'))->assertOk();
        $this->assertTrue(Cache::has('analytics.mrr.superadmin.2026-10'));
        $this->assertFalse(Cache::has('analytics.mrr.superadmin.2026-11'));

        // 2026-11-01 00:30 WIB (UTC is still Oct 31): new WIB month, fresh key, not the Oct cache.
        Carbon::setTestNow(Carbon::parse('2026-11-01 00:30:00', 'Asia/Jakarta'));
        $this->actingAs($admin)->getJson(route('api.analytics.mrr'))->assertOk();
        $this->assertTrue(Cache::has('analytics.mrr.superadmin.2026-11'));
    }

    public function test_support_ticket_due_at_with_explicit_offset_is_the_same_instant(): void
    {
        $admin = $this->admin();

        foreach (['2026-10-20T14:00:00+07:00', '2026-10-20T07:00:00Z'] as $value) {
            $id = $this->actingAs($admin)
                ->postJson(route('api.support-tickets.store'), ['subject' => 'Offset '.$value, 'due_at' => $value])
                ->assertSuccessful()
                ->json('id');

            $this->assertNotNull($id, "no ticket id for {$value}");
            $this->assertSame(
                '2026-10-20 07:00:00',
                DB::table('support_tickets')->where('id', $id)->value('due_at'),
                "due_at for {$value}"
            );
        }
    }

    private function gatewayTransaction(Invoice $invoice, string $orderId, string $createdAtUtc): int
    {
        $txn = PaymentGatewayTransaction::create([
            'provider' => 'duitku',
            'environment' => 'sandbox',
            'invoice_id' => $invoice->id,
            'merchant_order_id' => $orderId,
            'payment_method' => 'VA',
            'amount_invoice' => 100000,
            'fee' => 0,
            'amount_charged' => 100000,
            'status' => 'pending',
        ]);
        DB::table('payment_gateway_transactions')->where('id', $txn->id)->update(['created_at' => $createdAtUtc]);

        return $txn->id;
    }

    public function test_generated_invoice_stores_generated_at_in_utc(): void
    {
        $customer = $this->customer();

        $invoice = app(InvoiceGenerationService::class)
            ->ensureForPeriod($customer, OpsTime::now()->startOfMonth());

        $this->assertNotNull($invoice);
        $raw = DB::table('invoices')->where('id', $invoice->id)->value('generated_at');
        // 03:00 WIB must be stored as 20:00 UTC, not as the WIB wall clock.
        $this->assertSame('2026-10-31 20:00:00', $raw);
    }

    public function test_psb_import_kpis_and_chart_use_the_wib_month(): void
    {
        $this->createPsbCandidatesTable();

        $customer = $this->customer('PSB1');
        $customer->update(['join_date' => '2026-11-01']);

        DB::table('psb_import_candidates')->insert([
            ['customer_code' => 'NOV', 'name' => 'Nov', 'status' => 'applied', 'applied_at' => '2026-10-31 20:00:00', 'created_at' => now(), 'updated_at' => now()],
            ['customer_code' => 'OCT', 'name' => 'Oct', 'status' => 'applied', 'applied_at' => '2026-10-31 16:00:00', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->actingAs($this->admin())
            ->get(route('psb-imports.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.year', 2026)
                ->where('kpis.this_month_join', 1)
                ->where('kpis.this_month_applied', 1)
                ->where('kpis.last_month_applied', 1)
                ->where('chart.months.9.applied_count', 1)
                ->where('chart.months.10.applied_count', 1));
    }

    private function createPsbCandidatesTable(): void
    {
        Schema::dropIfExists('psb_import_candidates');
        Schema::create('psb_import_candidates', function ($table) {
            $table->id();
            $table->string('source_file')->nullable();
            $table->string('customer_code');
            $table->string('name');
            $table->string('pppoe_user')->nullable();
            $table->string('address')->nullable();
            $table->string('phone')->nullable();
            $table->string('area_name')->nullable();
            $table->string('package_name')->nullable();
            $table->string('speed')->nullable();
            $table->decimal('price', 12, 2)->nullable();
            $table->string('status')->default('staged');
            $table->string('match_status')->nullable();
            $table->text('match_notes')->nullable();
            $table->string('router_profile')->nullable();
            $table->unsignedBigInteger('matched_customer_id')->nullable();
            $table->unsignedBigInteger('matched_router_id')->nullable();
            $table->unsignedBigInteger('matched_router_staged_customer_id')->nullable();
            $table->unsignedBigInteger('resolved_area_id')->nullable();
            $table->unsignedBigInteger('resolved_package_id')->nullable();
            $table->unsignedBigInteger('resolved_router_id')->nullable();
            $table->unsignedBigInteger('applied_customer_id')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->longText('raw_payload')->nullable();
            $table->timestamps();
        });
    }
}
