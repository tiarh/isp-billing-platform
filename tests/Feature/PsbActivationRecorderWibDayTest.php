<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Package;
use App\Models\PsbDraft;
use App\Models\Router;
use App\Services\PsbActivationRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Canary;
use Tests\TestCase;

/**
 * Manual Auto-PPPoE activations dedupe per WIB day (not per UTC day).
 */
#[Group('blocking-psb')]
class PsbActivationRecorderWibDayTest extends TestCase
{
    use RefreshDatabase;

    private Router $router;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->router = Router::create([
            'name' => 'WIB-DAY-R1',
            'ip_address' => '198.18.18.5',
            'username' => 'admin',
            'password' => Canary::of('pw'),
            'is_active' => true,
        ]);
        $package = Package::create(['code' => 'WD-10', 'name' => 'WD 10MB', 'price' => 150000, 'mikrotik_profile' => '10MB', 'router_id' => $this->router->id]);
        $this->customer = Customer::create([
            'code' => 'WD-0001',
            'name' => 'WIB DAY',
            'phone' => '081200000026',
            'address' => 'Krian',
            'package_id' => $package->id,
            'pppoe_user' => 'USERWIBDAY_RT1_RW1',
            'mikrotik_profile' => '10MB',
            'router_id' => $this->router->id,
            'status' => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function recordAt(string $wib): void
    {
        $this->travelTo(CarbonImmutable::parse($wib, 'Asia/Jakarta')->utc());
        app(PsbActivationRecorder::class)->recordManualPppoe($this->customer, $this->router);
    }

    private function draftCount(): int
    {
        return PsbDraft::query()->where('customer_id', $this->customer->id)->count();
    }

    public function test_0630_and_0730_wib_on_the_same_day_share_one_draft(): void
    {
        $this->recordAt('2026-10-14 06:30');
        $this->recordAt('2026-10-14 07:30');

        $this->assertSame(1, $this->draftCount());
    }

    public function test_2330_wib_and_0030_wib_next_day_are_two_drafts(): void
    {
        $this->recordAt('2026-10-14 23:30');
        $this->recordAt('2026-10-15 00:30');

        $this->assertSame(2, $this->draftCount());
    }
}
