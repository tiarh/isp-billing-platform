<?php

namespace Tests\Feature;

use App\Jobs\IsolateCustomerJob;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\Setting;
use App\Support\OpsTime;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class H2TimezoneTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_ops_time_uses_wib_before_utc_midnight_rollover(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-27 23:30:00', 'UTC'));

        [$start, $end] = OpsTime::dayBounds();

        $this->assertSame('2026-07-28', OpsTime::today());
        $this->assertSame('2026-07-27 17:00:00', $start->toDateTimeString());
        $this->assertSame('2026-07-28 16:59:59', $end->toDateTimeString());
    }

    public function test_ops_time_uses_wib_after_utc_midnight_and_month_end(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-28 01:00:00', 'UTC'));

        $this->assertSame('2026-07-28', OpsTime::today());

        Carbon::setTestNow(Carbon::parse('2026-07-31 23:30:00', 'UTC'));

        $this->assertSame('2026-08-01', OpsTime::today());
    }

    public function test_overdue_command_cutoff_uses_wib_date_before_0700(): void
    {
        Bus::fake();
        Carbon::setTestNow(Carbon::parse('2026-07-27 23:30:00', 'UTC')); // 2026-07-28 06:30 WIB

        $package = Package::create([
            'code' => 'PKG-H2',
            'name' => 'H2 Package',
            'price' => 100000,
        ]);

        $customer = Customer::create([
            'code' => 'CUST-H2-WIB',
            'name' => 'H2 WIB Customer',
            'status' => 'active',
            'phone' => '081234567890',
            'address' => 'H2 Address',
            'package_id' => $package->id,
            'pppoe_user' => 'h2-wib',
            // The join month is never billable; join before the July period.
            'join_date' => '2026-04-01',
        ]);

        Invoice::create([
            'customer_id' => $customer->id,
            'code' => 'INV-H2-WIB',
            'period' => '2026-07-01',
            'amount' => 100000,
            'status' => 'unpaid',
            'due_date' => '2026-07-25',
            'generated_at' => now(),
        ]);

        Setting::set('billing_enforcement_dry_run', '0', 'boolean', 'billing');
        Setting::set('billing_isolir_after_days', 3, 'integer', 'billing');

        $this->artisan('billing:check-overdue')->assertExitCode(0);

        Bus::assertDispatched(IsolateCustomerJob::class, 1);
    }

    public function test_business_schedules_are_pinned_to_jakarta_without_changing_old_rhythm(): void
    {
        $source = file_get_contents(base_path('routes/console.php'));

        $this->assertMatchesRegularExpression("/routers:sync-profiles-draft'\\)\\s*->dailyAt\\('12:30'\\)\\s*->timezone\\('Asia\\/Jakarta'\\)/s", $source);
        $this->assertMatchesRegularExpression("/billing:generate'\\)\\s*->monthlyOn\\(1, '07:00'\\)\\s*->timezone\\('Asia\\/Jakarta'\\)/s", $source);
        $this->assertMatchesRegularExpression("/billing:check-overdue'\\)\\s*->dailyAt\\('15:00'\\)\\s*->timezone\\('Asia\\/Jakarta'\\)/s", $source);
    }
}
