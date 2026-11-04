<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Package;
use App\Models\Router;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Canary;
use Tests\TestCase;

class StarliteProfileNormalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_does_not_write_profile_changes(): void
    {
        $starlite = Package::create([
            'code' => 'STARLITE-UPTO-200',
            'name' => 'Paket UpTo 200Mbps Starlite',
            'price' => 100000,
            'mikrotik_profile' => null,
        ]);

        $customer = Customer::create([
            'code' => 'RDGS001',
            'name' => 'Pelanggan Biasa',
            'phone' => '0800',
            'address' => 'Randuagung',
            'package_id' => $starlite->id,
            'pppoe_user' => 'PELANGGAN_RT01_RW04_1',
            'mikrotik_profile' => null,
        ]);

        $this->artisan('customers:normalize-starlite-profiles')->assertExitCode(0);

        $this->assertNull($starlite->fresh()->mikrotik_profile);
        $this->assertNull($customer->fresh()->mikrotik_profile);
    }

    public function test_apply_sets_starlite_to_200m_and_school_to_csr_without_touching_other_customers(): void
    {
        $router = Router::create([
            'name' => 'SKYNET-RA-OLT',
            'ip_address' => '127.0.0.1',
            'username' => 'test',
            'password' => Canary::of('test'),
        ]);
        $starlite = Package::create([
            'code' => 'STARLITE-UPTO-200',
            'name' => 'Paket UpTo 200Mbps Starlite',
            'price' => 100000,
            'mikrotik_profile' => null,
        ]);
        $csr = Package::create([
            'code' => 'CSR-RA',
            'name' => 'Paket CSR',
            'price' => 0,
            'router_id' => $router->id,
            'mikrotik_profile' => 'CSR',
        ]);
        $regular = Package::create([
            'code' => 'REGULAR-10M',
            'name' => 'Paket 10M',
            'price' => 100000,
            'mikrotik_profile' => '10M',
        ]);

        $normal = Customer::create([
            'code' => 'RDGS001',
            'name' => 'Pelanggan Biasa',
            'phone' => '0800',
            'address' => 'Randuagung',
            'package_id' => $starlite->id,
            'router_id' => $router->id,
            'pppoe_user' => 'PELANGGAN_RT01_RW04_1',
            'mikrotik_profile' => null,
        ]);
        $school = Customer::create([
            'code' => 'RDGS002',
            'name' => 'MTS MIFTAHUL FALAH',
            'phone' => '0801',
            'address' => 'Capang',
            'package_id' => $starlite->id,
            'router_id' => $router->id,
            'pppoe_user' => 'USERAZ_RT02_RW07_1',
            'mikrotik_profile' => 'STARTLITE',
        ]);
        $maSchool = Customer::create([
            'code' => 'RDGS003',
            'name' => 'MA MIFTAHUL F',
            'phone' => '0803',
            'address' => 'Capang',
            'package_id' => $starlite->id,
            'router_id' => $router->id,
            'pppoe_user' => 'USERAY_RT02_RW07_2',
            'mikrotik_profile' => 'CSR',
        ]);
        $other = Customer::create([
            'code' => 'REG001',
            'name' => 'Pelanggan Reguler',
            'phone' => '0802',
            'address' => 'Krian',
            'package_id' => $regular->id,
            'router_id' => $router->id,
            'pppoe_user' => 'REGULER_RT01_RW01_1',
            'mikrotik_profile' => '10M',
        ]);

        $this->artisan('customers:normalize-starlite-profiles', [
            '--apply' => true,
            '--reason' => 'test normalization',
        ])->assertExitCode(0);

        $this->assertSame('200M+ STARTLITE', $starlite->fresh()->mikrotik_profile);
        $this->assertSame($starlite->id, $normal->fresh()->package_id);
        $this->assertSame('200M+ STARTLITE', $normal->fresh()->mikrotik_profile);
        $this->assertSame($csr->id, $school->fresh()->package_id);
        $this->assertSame('CSR', $school->fresh()->mikrotik_profile);
        $this->assertSame($csr->id, $maSchool->fresh()->package_id);
        $this->assertSame('CSR', $maSchool->fresh()->mikrotik_profile);
        $this->assertSame($regular->id, $other->fresh()->package_id);
        $this->assertSame('10M', $other->fresh()->mikrotik_profile);
    }
}
