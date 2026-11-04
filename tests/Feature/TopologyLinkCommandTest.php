<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Customer;
use App\Models\OdpOdc;
use App\Models\Package;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TopologyLinkCommandTest extends TestCase
{
    use RefreshDatabase;

    private function area(): Area
    {
        return Area::create(['code' => 'AREA-'.uniqid(), 'name' => 'Test Area']);
    }

    private function odc(Area $area, string $name, float $lat, float $lng): OdpOdc
    {
        return OdpOdc::create([
            'area_id' => $area->id, 'name' => $name, 'type' => 'ODC',
            'lat' => $lat, 'lng' => $lng, 'olt_port' => '1/2/2',
        ]);
    }

    private function odp(Area $area, string $name, float $lat, float $lng): OdpOdc
    {
        return OdpOdc::create([
            'area_id' => $area->id, 'name' => $name, 'type' => 'ODP',
            'lat' => $lat, 'lng' => $lng,
        ]);
    }

    private function customer(Area $area, string $odpName): Customer
    {
        $package = Package::first() ?: Package::create([
            'code' => 'PKG-TOP', 'name' => 'Topo', 'price' => 100000,
        ]);

        return Customer::create([
            'code' => 'TOP-'.strtoupper(substr(uniqid(), -6)),
            'name' => 'Topo Customer',
            'phone' => '081200000000',
            'address' => 'addr',
            'package_id' => $package->id,
            'pppoe_user' => 'topo.'.uniqid(),
            'area_id' => $area->id,
            'status' => 'active',
            'odp_name' => $odpName,
        ]);
    }

    public function test_dry_run_does_not_write_but_apply_links_topology(): void
    {
        $area = $this->area();
        $odc = $this->odc($area, 'ODC-2', -6.8711, 111.6794);
        $odp = $this->odp($area, 'SKY_RDG_ODP_9.4', -6.8720, 111.6790); // ~100m away
        $customer = $this->customer($area, 'sky-rdg-odp-9.4');

        // Dry run: nothing written.
        $this->artisan('topology:link')->assertSuccessful();
        $this->assertNull($odp->refresh()->parent_id);
        $this->assertNull($customer->refresh()->odp_odc_id);

        // Apply: ODP -> nearest ODC, customer -> ODP by normalized name.
        $this->artisan('topology:link', ['--apply' => true])->assertSuccessful();

        $this->assertSame($odc->id, $odp->refresh()->parent_id);
        $this->assertSame($odp->id, $customer->refresh()->odp_odc_id);
        $this->assertSame('SKYRDGODP94', $odp->refresh()->code);
    }

    public function test_far_odc_is_not_linked(): void
    {
        $area = $this->area();
        $this->odc($area, 'ODC-FAR', -6.90, 111.90); // ~25km away
        $odp = $this->odp($area, 'ODP-FAR', -6.8711, 111.6794);

        $this->artisan('topology:link', ['--apply' => true, '--max-km' => 3])->assertSuccessful();

        $this->assertNull($odp->refresh()->parent_id);
    }

    public function test_odc_never_gets_a_parent(): void
    {
        $area = $this->area();
        $odc = $this->odc($area, 'ODC-A', -6.8711, 111.6794);
        $this->odc($area, 'ODC-B', -6.8712, 111.6795);

        $this->artisan('topology:link', ['--apply' => true])->assertSuccessful();

        $this->assertNull($odc->refresh()->parent_id);
    }
}
