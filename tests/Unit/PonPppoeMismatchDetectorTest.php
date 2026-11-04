<?php

namespace Tests\Unit;

use App\Models\Area;
use App\Models\Customer;
use App\Models\MonitoringAlertEvent;
use App\Models\OdpOdc;
use App\Models\Olt;
use App\Models\OltOnu;
use App\Models\Package;
use App\Services\PonPppoeMismatchDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PonPppoeMismatchDetectorTest extends TestCase
{
    use RefreshDatabase;

    private Olt $olt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->olt = Olt::create([
            'name' => 'OLT RA',
            'code' => 'OLT-RA',
            'vendor' => 'zte_c300',
        ]);
    }

    private function makeCustomer(array $overrides = []): Customer
    {
        $package = Package::create([
            'name' => '10M',
            'code' => 'PKG-DET-'.uniqid(),
            'price' => 55000,
        ]);

        $area = Area::create(['code' => 'A', 'name' => 'Area Test']);

        return Customer::create(array_merge([
            'code' => 'RDG001',
            'name' => 'JAFAR',
            'phone' => '080000000001',
            'address' => 'Addr',
            'pppoe_user' => 'jafar.test',
            'package_id' => $package->id,
            'area_id' => $area->id,
            'status' => 'active',
            'is_online' => true,
            'olt_id' => $this->olt->id,
            'olt_port_label' => '1/2/1:5',
            'onu_serial' => 'ZTEGC123456',
            'olt_status' => 'offline',
        ], $overrides));
    }

    private function makeOnu(Customer $customer, array $overrides = []): OltOnu
    {
        return OltOnu::create(array_merge([
            'olt_id' => $this->olt->id,
            'onu_ref' => '1/2/1:5',
            'serial_number' => $customer->onu_serial,
            'state' => 'offline',
            'state_collected_at' => now(),
        ], $overrides));
    }

    public function test_scan_upserts_and_recovers_verified_monitoring_events(): void
    {
        $area = Area::create(['code' => 'ODP-AREA', 'name' => 'Area Test']);
        OdpOdc::create([
            'area_id' => $area->id,
            'name' => 'ODP-RDG-01',
            'type' => 'ODP',
            'olt_port' => '1/2/1',
            'lat' => -7.9,
            'lng' => 112.6,
        ]);

        $customer = $this->makeCustomer();
        $this->makeOnu($customer);
        $detector = app(PonPppoeMismatchDetector::class);

        $first = $detector->scan();
        $this->assertSame(1, $first['scanned']);
        $this->assertSame(1, $first['active']);
        $this->assertSame(0, $first['skipped']);
        $this->assertSame(1, $first['upserted']);

        $event = MonitoringAlertEvent::query()->where('dedupe_key', 'customer:'.$customer->id.':pon_pppoe_mismatch')->first();
        $this->assertNotNull($event);
        $this->assertSame('connectivity_scan', $event->source);
        $this->assertSame('pon_pppoe_mismatch', $event->issue);
        $this->assertSame('ODP-RDG-01', $event->payload['odp']);
        $this->assertSame('Area Test', $event->payload['area']);

        $customer->update(['olt_status' => 'online']);
        $this->makeOnu($customer, ['state' => 'online']);
        $second = $detector->scan();
        $this->assertSame(0, $second['active']);
        $this->assertSame(1, $second['recovered']);
        $this->assertSame('recovered', $event->fresh()->status);
    }

    public function test_scan_excludes_sn_mismatch_between_customer_and_olt_onu(): void
    {
        $customer = $this->makeCustomer(['onu_serial' => 'ZTEGC111111']);
        $this->makeOnu($customer, ['serial_number' => 'ZTEGC222222']);
        $detector = app(PonPppoeMismatchDetector::class);

        $result = $detector->scan();

        $this->assertSame(1, $result['scanned']);
        $this->assertSame(0, $result['active']);
        $this->assertSame(1, $result['skipped']);
        $this->assertFalse(
            MonitoringAlertEvent::query()->where('dedupe_key', 'customer:'.$customer->id.':pon_pppoe_mismatch')->exists()
        );
    }

    public function test_scan_excludes_stale_olt_onu_state(): void
    {
        $customer = $this->makeCustomer();
        $this->makeOnu($customer, [
            'state_collected_at' => now()->subHours(72),
            'updated_at' => now()->subHours(72),
        ]);
        $detector = app(PonPppoeMismatchDetector::class);

        $result = $detector->scan();

        $this->assertSame(1, $result['scanned']);
        $this->assertSame(0, $result['active']);
        $this->assertSame(1, $result['skipped']);
    }

    public function test_pre_sync_updates_customer_olt_status_from_fresh_onu_row(): void
    {
        $customer = $this->makeCustomer([
            'olt_status' => 'offline',
            'olt_last_synced_at' => now()->subDays(5),
        ]);
        $this->makeOnu($customer, ['state' => 'offline']);
        $detector = app(PonPppoeMismatchDetector::class);

        $detector->scan();

        $customer->refresh();
        $this->assertSame('offline', $customer->olt_status);
        $this->assertTrue($customer->olt_last_synced_at?->gt(now()->subHour()));
    }

    public function test_dry_run_scan_does_not_persist_events(): void
    {
        $customer = $this->makeCustomer();
        $this->makeOnu($customer);
        $detector = app(PonPppoeMismatchDetector::class);

        $result = $detector->scan(false);
        $this->assertSame(1, $result['active']);
        $this->assertSame(0, $result['upserted']);
        $this->assertFalse(
            MonitoringAlertEvent::query()->where('dedupe_key', 'customer:'.$customer->id.':pon_pppoe_mismatch')->exists()
        );
    }

    public function test_scan_skips_mismatch_when_online_onu_matches_customer_code(): void
    {
        $customer = $this->makeCustomer([
            'code' => 'KLRJ098',
            'pppoe_user' => 'dwiprasetio.kalirejo',
            'onu_serial' => 'CIOT0A311720',
            'olt_port_label' => '1/3/11:27',
            'olt_status' => 'offline',
        ]);

        $this->makeOnu($customer, [
            'serial_number' => 'CIOT0A311720',
            'state' => 'offline',
        ]);

        OltOnu::create([
            'olt_id' => $this->olt->id,
            'onu_ref' => '1/3/11:30',
            'serial_number' => 'FHTTCNEW123456',
            'onu_name' => 'KLRJ098',
            'state' => 'online',
            'rx_power_dbm' => -23.1,
            'state_collected_at' => now(),
        ]);

        $detector = app(PonPppoeMismatchDetector::class);
        $result = $detector->scan();

        $this->assertSame(0, $result['active']);
        $this->assertSame(1, $result['migrated']);
        $customer->refresh();
        $this->assertSame('FHTTCNEW123456', $customer->onu_serial);
        $this->assertSame('online', $customer->olt_status);
    }

    public function test_scan_skips_mismatch_when_fhtt_migrated_onu_is_online_on_other_port(): void
    {
        $customer = $this->makeCustomer([
            'pppoe_user' => 'klrj098.user',
            'onu_serial' => 'CIOTOLD123456',
            'olt_port_label' => '1/2/1:5',
            'olt_status' => 'offline',
        ]);

        $this->makeOnu($customer, [
            'serial_number' => 'CIOTOLD123456',
            'state' => 'offline',
        ]);

        OltOnu::create([
            'olt_id' => $this->olt->id,
            'onu_ref' => '1/2/3:12',
            'serial_number' => 'FHTTCNEW123456',
            'onu_name' => 'klrj098.user',
            'state' => 'online',
            'rx_power_dbm' => -24.5,
            'state_collected_at' => now(),
        ]);

        $detector = app(PonPppoeMismatchDetector::class);
        $result = $detector->scan();

        $this->assertSame(1, $result['scanned']);
        $this->assertSame(0, $result['active']);
        $this->assertSame(1, $result['migrated']);
        $this->assertFalse(
            MonitoringAlertEvent::query()->where('dedupe_key', 'customer:'.$customer->id.':pon_pppoe_mismatch')->exists()
        );

        $customer->refresh();
        $this->assertSame('1/2/3:12', $customer->olt_port_label);
        $this->assertSame('FHTTCNEW123456', $customer->onu_serial);
        $this->assertSame('online', $customer->olt_status);
        $this->assertEquals(-24.5, (float) $customer->onu_rx_power_dbm);
    }
}
