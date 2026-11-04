<?php

namespace Tests\Feature;

use App\Enums\CustomerStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use App\Services\BillingEligibilityService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Owner decision 2026-10-08 (audit "Muraji" #147957): any customer that
 * becomes billable gets its CURRENT-period invoice; due day = join day; a late
 * invoice is due on its creation date (WIB) so isolation is H+3 after it
 * exists; daily catch-up generate is idempotent; Rp0 packages are never billed
 * nor isolated. (Named CustomerReactivationInvoice* for the blocking CI job.)
 */
#[Group('blocking-core')]
class CustomerReactivationInvoiceActivationPolicyTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_pending_installation_to_active_creates_current_invoice_with_late_due_floor(): void
    {
        $this->at('2026-10-08 03:00:00'); // 10:00 WIB
        $muraji = $this->customer(['status' => 'pending_installation', 'join_date' => '2026-09-03', 'due_day' => 3]);

        $muraji->update(['status' => 'active']);

        $invoice = Invoice::query()->where('customer_id', $muraji->id)->sole();
        $this->assertSame('2026-10-01', $invoice->period->toDateString());
        $this->assertSame('2026-10-08', $invoice->due_date->toDateString());
        $this->assertSame('unpaid', $invoice->status);
    }

    public function test_due_day_follows_join_day_when_not_yet_passed(): void
    {
        $this->at('2026-10-08 03:00:00');
        $customer = $this->customer(['status' => 'pending_installation', 'join_date' => '2026-09-20', 'due_day' => 20]);

        $customer->update(['status' => 'active']);

        $this->assertSame('2026-10-20', Invoice::query()->where('customer_id', $customer->id)->sole()->due_date->toDateString());
    }

    public function test_activation_in_join_month_creates_nothing(): void
    {
        $this->at('2026-10-08 03:00:00');
        $customer = $this->customer(['status' => 'pending_installation', 'join_date' => '2026-10-02', 'due_day' => 2]);

        $customer->update(['status' => 'active']);

        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_created_as_active_after_the_first_is_billed_by_daily_catch_up(): void
    {
        $this->at('2026-10-02 03:00:00');
        $customer = $this->customer(['status' => 'active', 'join_date' => '2026-09-15', 'due_day' => 15]);
        $this->assertDatabaseCount('invoices', 0);

        $this->artisan('billing:generate', ['--catch-up' => true])->assertExitCode(0);

        $this->assertSame('2026-10-15', Invoice::query()->where('customer_id', $customer->id)->sole()->due_date->toDateString());
    }

    public function test_price_zero_and_null_join_date_get_no_invoice_on_activation(): void
    {
        $this->at('2026-10-08 03:00:00');
        $csr = Package::create(['code' => 'CSR-P', 'name' => 'Paket CSR', 'price' => 0]);
        $free = $this->customer(['status' => 'pending_installation', 'package_id' => $csr->id, 'join_date' => '2026-09-03']);
        $noJoin = $this->customer(['status' => 'inactive', 'join_date' => '2026-09-03']);
        DB::table('customers')->where('id', $noJoin->id)->update(['join_date' => null]);

        $free->update(['status' => 'active']);
        $noJoin->refresh()->update(['status' => 'active']);

        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_wib_boundary_0030_on_the_first(): void
    {
        // 2026-10-31 17:30 UTC = 2026-11-01 00:30 WIB.
        $this->at('2026-10-31 17:30:00');

        $new = $this->customer(['status' => 'pending_installation', 'join_date' => null, 'due_day' => null]);
        $this->assertSame('2026-11-01', $new->refresh()->join_date->toDateString());
        $this->assertSame(1, (int) $new->due_day);

        $customer = $this->customer(['status' => 'pending_installation', 'join_date' => '2026-10-05', 'due_day' => 5]);
        $customer->update(['status' => 'active']);

        $invoice = Invoice::query()->where('customer_id', $customer->id)->sole();
        $this->assertSame('2026-11-01', $invoice->period->toDateString());
        $this->assertSame('2026-11-05', $invoice->due_date->toDateString());
    }

    public function test_psb_uses_wib_dates_and_isolated_status_spelling(): void
    {
        $source = (string) file_get_contents(app_path('Services/Psb/PsbSecretProvisioner.php'));

        $this->assertStringNotContainsString("'join_date' => now()", $source);
        $this->assertStringNotContainsString("'due_day' => (int) now()->day", $source);
        $this->assertStringContainsString("'join_date' => OpsTime::today()", $source);
        $this->assertSame(2, substr_count($source, 'CustomerStatus::provisionedLive()'));
        $this->assertSame(['active', 'isolated', 'suspended'], CustomerStatus::provisionedLive());
    }

    public function test_daily_catch_up_is_idempotent_and_reports_skips(): void
    {
        $this->at('2026-10-08 03:00:00');
        $missing = $this->customer(['status' => 'active', 'join_date' => '2026-09-03', 'due_day' => 3]);
        $billed = $this->customer(['status' => 'active', 'join_date' => '2026-08-10', 'due_day' => 10]);
        $billedInvoice = Invoice::create([
            'customer_id' => $billed->id, 'period' => '2026-10-01', 'due_date' => '2026-10-10',
            'amount' => 100000, 'status' => 'unpaid', 'generated_at' => now(),
        ]);
        $joinMonth = $this->customer(['status' => 'active', 'join_date' => '2026-10-03', 'due_day' => 3]);
        $csr = Package::create(['code' => 'CSR-G', 'name' => 'Paket CSR', 'price' => 0]);
        $free = $this->customer(['status' => 'active', 'join_date' => '2026-09-03', 'package_id' => $csr->id]);
        $noJoin = $this->customer(['status' => 'active', 'join_date' => '2026-10-03']);
        DB::table('customers')->where('id', $noJoin->id)->update(['join_date' => null, 'due_day' => 0]);
        $arrears = $this->customer(['status' => 'active', 'join_date' => '2026-08-01', 'due_day' => 1]);

        $this->artisan('billing:generate', ['--catch-up' => true, '--dry-run' => true])->assertExitCode(0);
        $this->assertSame(1, Invoice::query()->count());

        $this->artisan('billing:generate', ['--catch-up' => true])
            ->expectsOutputToContain('join_date_null (owner decision, not billed): '.$noJoin->id)
            ->expectsOutputToContain('price_zero (owner decision, not billed): '.$free->id)
            ->assertExitCode(0);
        $this->artisan('billing:generate', ['--catch-up' => true])->assertExitCode(0);

        $this->assertSame(1, Invoice::query()->where('customer_id', $missing->id)->count());
        $this->assertSame('2026-10-08', Invoice::query()->where('customer_id', $missing->id)->sole()->due_date->toDateString());
        $this->assertSame($billedInvoice->id, Invoice::query()->where('customer_id', $billed->id)->sole()->id);
        $this->assertSame(0, Invoice::query()->whereIn('customer_id', [$joinMonth->id, $free->id, $noJoin->id])->count());
        // Current period only: no September arrears invoice.
        $this->assertSame(['2026-10-01'], Invoice::query()->where('customer_id', $arrears->id)->pluck('period')->map->toDateString()->all());
    }

    public function test_late_created_invoice_is_not_isolated_within_grace(): void
    {
        $this->at('2026-10-08 03:00:00');
        $customer = $this->customer(['status' => 'pending_installation', 'join_date' => '2026-09-03', 'due_day' => 3]);
        $customer->update(['status' => 'active']);
        $eligibility = app(BillingEligibilityService::class);

        $h2 = $eligibility->evaluate($customer->refresh(), CarbonImmutable::parse('2026-10-10 15:00', 'Asia/Jakarta'));
        $this->assertFalse($h2['isolation_eligible']);
        $this->assertFalse($h2['grace_elapsed']);

        $h3 = $eligibility->evaluate($customer, CarbonImmutable::parse('2026-10-11 15:00', 'Asia/Jakarta'));
        $this->assertTrue($h3['grace_elapsed']);
        $this->assertTrue($h3['isolation_eligible']);
    }

    public function test_price_zero_customer_is_never_isolation_eligible(): void
    {
        $this->at('2026-10-08 03:00:00');
        $csr = Package::create(['code' => 'CSR-I', 'name' => 'Paket CSR', 'price' => 0]);
        $customer = $this->customer(['status' => 'active', 'join_date' => '2026-07-01', 'due_day' => 1, 'package_id' => $csr->id]);
        // Old paid-package invoice left unpaid long past grace.
        Invoice::create([
            'customer_id' => $customer->id, 'period' => '2026-09-01', 'due_date' => '2026-09-01',
            'amount' => 100000, 'status' => 'unpaid', 'generated_at' => now(),
        ]);

        $decision = app(BillingEligibilityService::class)->evaluate($customer->refresh());

        $this->assertFalse($decision['isolation_eligible']);
        $this->assertSame('price_zero_exempt', $decision['reason']);
    }

    private function at(string $utc): void
    {
        Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
    }

    /** @param array<string, mixed> $attributes */
    private function customer(array $attributes = []): Customer
    {
        $this->seq++;
        $package = Package::firstOrCreate(['code' => 'POL-100'], ['name' => 'Paket 100', 'price' => 100000]);

        return Customer::create(array_merge([
            'name' => 'Policy '.$this->seq,
            'phone' => '0813222'.$this->seq,
            'address' => 'RT 2',
            'package_id' => $package->id,
            'pppoe_user' => 'policy-'.$this->seq,
        ], $attributes));
    }
}
