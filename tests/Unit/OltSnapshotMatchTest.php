<?php

namespace Tests\Unit;

use App\Http\Controllers\OltController;
use App\Models\Area;
use App\Models\Customer;
use App\Models\Olt;
use App\Models\OltOnu;
use App\Models\Package;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OltSnapshotMatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolve_cached_snapshot_populates_matched_customers_from_builder(): void
    {
        $olt = Olt::create([
            'name' => 'OLT Test',
            'code' => 'OLT-T',
            'vendor' => 'zte_c300',
        ]);

        $package = Package::create(['name' => '10M', 'code' => 'PKG-OLT', 'price' => 55000]);
        $area = Area::create(['code' => 'A1', 'name' => 'Area 1']);

        $customer = Customer::create([
            'code' => 'TST001',
            'name' => 'JAFAR',
            'phone' => '080000000099',
            'address' => 'Addr',
            'pppoe_user' => 'jafar.test',
            'package_id' => $package->id,
            'area_id' => $area->id,
            'status' => 'active',
            'olt_id' => $olt->id,
            'olt_port_label' => '1/2/1:5',
            'onu_serial' => 'FHTTC123456',
            'olt_status' => 'online',
        ]);

        OltOnu::create([
            'olt_id' => $olt->id,
            'onu_ref' => '1/2/1:5',
            'pon_port' => '1/2/1',
            'serial_number' => 'FHTTC123456',
            'onu_name' => 'jafar.test',
            'state' => 'online',
            'rx_power_dbm' => -24.5,
            'state_collected_at' => now(),
        ]);

        $controller = app(OltController::class);
        $method = new \ReflectionMethod($controller, 'resolveCachedSnapshot');
        $method->setAccessible(true);
        $snapshot = $method->invoke($controller, $olt->fresh());

        $this->assertNotEmpty($snapshot['onus']);
        $this->assertNotEmpty($snapshot['matched_customers']);
        $this->assertSame(1, $snapshot['meta']['matched_customers_count'] ?? count($snapshot['matched_customers']));
        $this->assertSame($customer->id, $snapshot['matched_customers'][0]['customer_id']);
    }
}
