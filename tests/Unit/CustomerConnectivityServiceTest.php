<?php

namespace Tests\Unit;

use App\Models\Customer;
use App\Models\GenieAcsDevice;
use App\Services\CustomerConnectivityService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CustomerConnectivityServiceTest extends TestCase
{
    public function test_service_online_requires_fresh_successful_ping_for_genie_customer(): void
    {
        $customer = new Customer(['olt_status' => 'online', 'is_online' => true]);
        $device = new GenieAcsDevice([
            'last_ping_at' => Carbon::now()->subMinutes(10),
            'last_ping_success' => true,
            'last_inform_at' => Carbon::now()->subMinutes(5),
        ]);

        $result = (new CustomerConnectivityService)->evaluate($customer, $device);

        $this->assertTrue($result['service_online']);
        $this->assertSame('full_stack_ping_ok', $result['reason']);
    }

    public function test_stale_ping_is_pending_instead_of_false_online(): void
    {
        $customer = new Customer(['olt_status' => 'working', 'is_online' => true]);
        $device = new GenieAcsDevice([
            'last_ping_at' => Carbon::now()->subHours(2),
            'last_ping_success' => true,
            'last_inform_at' => Carbon::now()->subMinutes(5),
        ]);

        $result = (new CustomerConnectivityService)->evaluate($customer, $device);

        $this->assertNull($result['ping_ok']);
        $this->assertFalse($result['service_online']);
        $this->assertSame('ping_pending', $result['reason']);
    }

    public function test_non_genie_customer_uses_pon_and_pppoe(): void
    {
        $customer = new Customer(['olt_status' => 'active', 'is_online' => true]);

        $result = (new CustomerConnectivityService)->evaluate($customer, null);

        $this->assertTrue($result['service_online']);
        $this->assertSame('pon_and_pppoe_no_acs', $result['reason']);
    }
}
