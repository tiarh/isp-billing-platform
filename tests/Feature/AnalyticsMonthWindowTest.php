<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * On days 29-31 `now()->subMonth()->startOfMonth()` overflows back into the
 * current month (Oct 31 -> "Sep 31" = Oct 1). These tests pin the analytics
 * month windows to overflow-safe maths.
 */
#[Group('blocking-core')]
class AnalyticsMonthWindowTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, array{string, string, string}> [now, current month start, previous month start] */
    public static function endOfMonthDates(): array
    {
        return [
            'oct 31 -> sep' => ['2026-10-31 10:00:00', '2026-10-01', '2026-09-01'],
            'mar 31 -> feb' => ['2026-03-31 10:00:00', '2026-03-01', '2026-02-01'],
            'may 31 -> apr' => ['2026-05-31 10:00:00', '2026-05-01', '2026-04-01'],
        ];
    }

    private function makeCustomer(Area $area, Package $package, string $code): Customer
    {
        return Customer::create([
            'code' => $code,
            'name' => 'Customer '.$code,
            'phone' => '0811111111',
            'address' => 'Addr',
            'area_id' => $area->id,
            'package_id' => $package->id,
            'pppoe_user' => strtolower($code),
            'status' => 'active',
        ]);
    }

    private function makeInvoice(Customer $customer, string $period, int $amount): void
    {
        Invoice::create([
            'customer_id' => $customer->id,
            'period' => $period,
            'amount' => $amount,
            'due_date' => $period,
            'status' => 'unpaid',
            'generated_at' => $period,
        ]);
    }

    #[DataProvider('endOfMonthDates')]
    public function test_mrr_last_month_is_previous_calendar_month(string $now, string $currentStart, string $previousStart): void
    {
        Carbon::setTestNow($now);
        Cache::flush();

        $user = User::factory()->create(['role' => 'superadmin']);
        $area = Area::create(['code' => 'A', 'name' => 'Area A']);
        $package = Package::create(['code' => 'BASIC', 'name' => 'Basic', 'price' => 100000]);
        $customer = $this->makeCustomer($area, $package, 'A001');

        // Previous month = 50.000; current month = 999 (old overflow bug would pick this one).
        $this->makeInvoice($customer, $previousStart, 50000);
        $this->makeInvoice($customer, $currentStart, 999);

        $response = $this->actingAs($user)->getJson(route('api.analytics.mrr'))->assertOk();

        // (100000 - 50000) / 50000 * 100 = 100.00
        $this->assertEquals(100.0, $response->json('growth_percentage'));
    }

    #[DataProvider('endOfMonthDates')]
    public function test_revenue_trend_window_includes_previous_month(string $now, string $currentStart, string $previousStart): void
    {
        Carbon::setTestNow($now);
        Cache::flush();

        $user = User::factory()->create(['role' => 'superadmin']);
        $area = Area::create(['code' => 'A', 'name' => 'Area A']);
        $package = Package::create(['code' => 'BASIC', 'name' => 'Basic', 'price' => 100000]);
        $customer = $this->makeCustomer($area, $package, 'A001');

        $this->makeInvoice($customer, $previousStart, 50000);
        $this->makeInvoice($customer, $currentStart, 999);

        $months = $this->actingAs($user)
            ->getJson(route('api.analytics.revenue-trend', ['months' => 1]))
            ->assertOk()
            ->json();

        $this->assertSame(
            [substr($previousStart, 0, 7), substr($currentStart, 0, 7)],
            array_column($months, 'month'),
        );
    }
}
