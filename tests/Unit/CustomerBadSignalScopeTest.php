<?php

namespace Tests\Unit;

use App\Models\Customer;
use App\Models\Olt;
use App\Models\Package;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerBadSignalScopeTest extends TestCase
{
    use RefreshDatabase;

    private function makeOlt(string $vendor = 'zte_c300'): Olt
    {
        return Olt::create([
            'name' => 'OLT-'.uniqid(),
            'code' => 'OLT-'.uniqid(),
            'vendor' => $vendor,
        ]);
    }

    private function makeCustomer(array $overrides = [], ?Olt $olt = null): Customer
    {
        $package = Package::create([
            'name' => '10M',
            'code' => 'PKG-BADSIG-'.uniqid(),
            'price' => 55000,
        ]);

        $olt ??= $this->makeOlt();

        return Customer::create(array_merge([
            'code' => 'C-'.uniqid(),
            'name' => 'Test Customer',
            'phone' => '080000000001',
            'address' => 'Addr',
            'pppoe_user' => 'test.'.uniqid(),
            'package_id' => $package->id,
            'status' => 'active',
            'is_online' => true,
            'olt_id' => $olt->id,
            'olt_port_label' => '1/2/1:1',
        ], $overrides));
    }

    public function test_bad_signal_scope_requires_pppoe_pon_online_weak_rx_on_c300(): void
    {
        $c300 = $this->makeOlt('zte_c300');

        $match = $this->makeCustomer([
            'olt_status' => 'online',
            'onu_rx_power_dbm' => -28.5,
        ], $c300);

        $this->makeCustomer(['olt_status' => 'offline', 'onu_rx_power_dbm' => -56.0], $c300);
        $this->makeCustomer(['olt_status' => 'online', 'onu_rx_power_dbm' => -24.0], $c300);
        $this->makeCustomer(['is_online' => false, 'olt_status' => 'online', 'onu_rx_power_dbm' => -30.0], $c300);

        $hioso = $this->makeOlt('hioso');
        $this->makeCustomer(['olt_status' => 'online', 'onu_rx_power_dbm' => -30.0], $hioso);

        $matches = Customer::query()->badSignalOntOnline()->pluck('id');

        $this->assertSame([$match->id], $matches->all());
    }

    public function test_bad_signal_scope_excludes_isolated_customers(): void
    {
        $c300 = $this->makeOlt('zte_c300');

        $this->makeCustomer([
            'status' => 'isolated',
            'mikrotik_profile' => 'isolirebilling',
            'olt_status' => 'online',
            'onu_rx_power_dbm' => -29.0,
        ], $c300);

        $this->assertSame(
            [],
            Customer::query()->badSignalOntOnline()->pluck('id')->all()
        );
    }

    public function test_bad_signal_scope_excludes_isolation_profile_even_when_active(): void
    {
        $c300 = $this->makeOlt('zte_c300');

        $this->makeCustomer([
            'status' => 'active',
            'mikrotik_profile' => 'isolirebilling',
            'olt_status' => 'online',
            'onu_rx_power_dbm' => -29.0,
        ], $c300);

        $this->assertFalse(Customer::query()->badSignalOntOnline()->exists());
    }

    public function test_pon_online_scope_accepts_working_state(): void
    {
        $c300 = $this->makeOlt('zte_c300');

        $customer = $this->makeCustomer([
            'olt_status' => 'working',
            'onu_rx_power_dbm' => -29.0,
        ], $c300);

        $this->assertTrue(
            Customer::query()->badSignalOntOnline()->whereKey($customer->id)->exists()
        );
    }
}
