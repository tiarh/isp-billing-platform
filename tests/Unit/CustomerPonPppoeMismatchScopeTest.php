<?php

namespace Tests\Unit;

use App\Models\Customer;
use App\Models\Olt;
use App\Models\Package;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerPonPppoeMismatchScopeTest extends TestCase
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
            'code' => 'PKG-MISMATCH-'.uniqid(),
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

    public function test_mismatch_scope_matches_pon_offline_pppoe_online_on_c300(): void
    {
        $c300 = $this->makeOlt('zte_c300');

        $match = $this->makeCustomer([
            'olt_status' => 'offline',
        ], $c300);

        $this->makeCustomer(['olt_status' => 'online'], $c300);
        $this->makeCustomer(['is_online' => false, 'olt_status' => 'offline'], $c300);

        $hioso = $this->makeOlt('hioso');
        $this->makeCustomer(['olt_status' => 'offline'], $hioso);

        $matches = Customer::query()->ponPppoeMismatch()->pluck('id');

        $this->assertSame([$match->id], $matches->all());
    }

    public function test_mismatch_scope_excludes_isolated_customers(): void
    {
        $c300 = $this->makeOlt('zte_c300');

        $this->makeCustomer([
            'status' => 'isolated',
            'mikrotik_profile' => 'isolirebilling',
            'olt_status' => 'offline',
        ], $c300);

        $this->assertFalse(Customer::query()->ponPppoeMismatch()->exists());
    }

    public function test_pon_offline_scope_treats_null_and_working_correctly(): void
    {
        $c300 = $this->makeOlt('zte_c300');

        $offline = $this->makeCustomer(['olt_status' => null], $c300);
        $online = $this->makeCustomer(['olt_status' => 'working'], $c300);

        $this->assertTrue(Customer::query()->ponOffline()->whereKey($offline->id)->exists());
        $this->assertFalse(Customer::query()->ponOffline()->whereKey($online->id)->exists());
    }
}
