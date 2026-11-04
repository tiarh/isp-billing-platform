<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Router;
use App\Models\Setting;
use App\Services\DueDayResolver;
use App\Services\InvoiceGenerationService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Canary;
use Tests\TestCase;

/**
 * Owner 2026-10-09: new customers on router SKYNET-RA-OLT (513) or area
 * STARLITE-AKUISISI (14) get due_day 25; everyone else keeps join day.
 */
#[Group('blocking-billing')]
class BillingHardeningPolicyFixedDueDayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::flushRequestMemo();
        Carbon::setTestNow(Carbon::parse('2026-10-03 03:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_ra_olt_router_gets_fixed_due_day_25(): void
    {
        $customer = $this->customer(['router_id' => $this->router(513)->id, 'join_date' => '2026-10-03']);

        $this->assertSame(25, (int) $customer->refresh()->due_day);
    }

    public function test_starlite_akuisisi_area_gets_fixed_due_day_25(): void
    {
        $customer = $this->customer([
            'router_id' => $this->router(20)->id,
            'area_id' => $this->area(14)->id,
            'join_date' => '2026-10-03',
        ]);

        $this->assertSame(25, (int) $customer->refresh()->due_day);
    }

    public function test_other_router_and_area_keep_join_day(): void
    {
        $customer = $this->customer([
            'router_id' => $this->router(20)->id,
            'area_id' => $this->area(7)->id,
            'join_date' => '2026-10-03',
        ]);

        $this->assertSame(3, (int) $customer->refresh()->due_day);
    }

    public function test_no_router_or_area_keeps_join_day(): void
    {
        $customer = $this->customer(['join_date' => '2026-10-17']);

        $this->assertSame(17, (int) $customer->refresh()->due_day);
    }

    public function test_explicit_due_day_is_respected(): void
    {
        $customer = $this->customer([
            'router_id' => $this->router(513)->id,
            'area_id' => $this->area(14)->id,
            'join_date' => '2026-10-03',
            'due_day' => 7,
        ]);

        $this->assertSame(7, (int) $customer->refresh()->due_day);
    }

    public function test_settings_override_day_and_ids(): void
    {
        Setting::set('billing_fixed_due_day', 10, 'integer', 'billing');
        Setting::set('billing_fixed_due_day_router_ids', '600, 601', 'text', 'billing');
        Setting::set('billing_fixed_due_day_area_ids', '', 'text', 'billing');

        $listed = $this->customer(['router_id' => $this->router(601)->id, 'join_date' => '2026-10-03']);
        $raOlt = $this->customer(['router_id' => $this->router(513)->id, 'join_date' => '2026-10-03']);
        $starlite = $this->customer(['area_id' => $this->area(14)->id, 'join_date' => '2026-10-03']);

        $this->assertSame(10, (int) $listed->refresh()->due_day);
        $this->assertSame(3, (int) $raOlt->refresh()->due_day);
        $this->assertSame(3, (int) $starlite->refresh()->due_day);
    }

    public function test_resolver_used_by_psb_paths(): void
    {
        $resolver = app(DueDayResolver::class);

        $this->assertSame(25, $resolver->resolve(513, null, CarbonImmutable::parse('2026-10-03')));
        $this->assertSame(25, $resolver->resolve(20, 14, CarbonImmutable::parse('2026-10-03')));
        $this->assertSame(3, $resolver->resolve(20, 7, CarbonImmutable::parse('2026-10-03')));
        // PSB import keeps its legacy 20 fallback outside the fixed rule.
        $this->assertNull($resolver->fixedDueDayFor(20, 7));
        $this->assertSame([513, 14], DueDayResolver::parseIds('513, 14,,x'));
    }

    public function test_first_invoice_skips_join_month_and_falls_on_the_25th(): void
    {
        $customer = $this->customer(['router_id' => $this->router(513)->id, 'join_date' => '2026-10-03']);
        $service = app(InvoiceGenerationService::class);

        // Join month (October) is never billed; first period is November, due 25 Nov.
        $period = $service->resolveBillingPeriod($customer, CarbonImmutable::parse('2026-10-01'));
        $this->assertSame('2026-11-01', $period->toDateString());
        $this->assertSame('2026-11-25', $service->dueDateFor($customer, $period)->toDateString());
        $this->assertSame('2026-11-25', $service->dueDateForNewInvoice($customer, $period)->toDateString());

        // Joining after the 25th (e.g. 28 Oct) still gives 25 Nov, not 28 Nov.
        $late = $this->customer(['area_id' => $this->area(14)->id, 'join_date' => '2026-10-28']);
        $this->assertSame(25, (int) $late->refresh()->due_day);
        $this->assertSame('2026-11-25', $service->dueDateFor($late, CarbonImmutable::parse('2026-11-01'))->toDateString());

        // #347: an invoice created after its regular due date is due on its
        // creation date (WIB), fixed-day customers included.
        $this->assertSame('2026-12-02', $service->dueDateForNewInvoice(
            $late,
            CarbonImmutable::parse('2026-11-01'),
            Carbon::parse('2026-12-02 03:00:00', 'UTC'),
        )->toDateString());
    }

    private function router(int $id): Router
    {
        return Router::query()->find($id) ?? Router::forceCreate([
            'id' => $id,
            'name' => 'Router '.$id,
            'ip_address' => '10.30.0.'.($id % 250 + 1),
            'username' => 'admin',
            'password' => Canary::of('pw'),
            'port' => 8728,
            'is_active' => true,
            'radius_enabled' => false,
        ]);
    }

    private function area(int $id): Area
    {
        return Area::query()->find($id) ?? Area::forceCreate([
            'id' => $id,
            'code' => 'AREA-'.$id,
            'name' => 'Area '.$id,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function customer(array $overrides = []): Customer
    {
        $package = Package::first() ?: Package::create([
            'code' => 'PKG-DUE25', 'name' => 'Due 25', 'price' => 100000,
            'mikrotik_profile' => 'PACKAGE_PROFILE',
        ]);

        return Customer::create(array_merge([
            'code' => 'DUE-'.strtoupper(substr(uniqid(), -8)),
            'name' => 'Due Day Test', 'phone' => '081234567890', 'address' => 'Test',
            'pppoe_user' => 'due.'.substr(uniqid(), -8), 'package_id' => $package->id,
            'status' => 'pending_installation',
        ], $overrides));
    }
}
