<?php

namespace Tests\Unit;

use App\Models\Customer;
use App\Models\Olt;
use App\Models\OltOnu;
use App\Models\Package;
use App\Services\OnuSerialImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnuSerialImportServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeCustomer(string $pppoe, ?string $serial = null): Customer
    {
        $package = Package::create([
            'name' => '10M',
            'code' => 'PKG-IMP-'.uniqid(),
            'price' => 55000,
        ]);

        $olt = Olt::create([
            'name' => 'OLT RA',
            'code' => 'OLT-RA',
            'vendor' => 'zte_c300',
        ]);

        return Customer::create([
            'code' => 'RDG999',
            'name' => 'TEST USER',
            'phone' => '080000000001',
            'address' => 'Addr',
            'pppoe_user' => $pppoe,
            'package_id' => $package->id,
            'status' => 'active',
            'olt_id' => $olt->id,
            'onu_serial' => $serial,
        ]);
    }

    public function test_import_updates_serial_by_pppoe_user(): void
    {
        $customer = $this->makeCustomer('USERBF_RT08_RW09_03', 'OLDSN123');
        $csv = $this->writeCsv([
            ['pppoe_user', 'serial_number'],
            ['USERBF_RT08_RW09_03', 'FHTT0000A02C'],
        ]);

        $service = app(OnuSerialImportService::class);
        $dry = $service->importFromPath($csv, apply: false, force: true);
        $this->assertSame(1, $dry['matched']);
        $this->assertSame(1, $dry['updated']);
        $this->assertSame('OLDSN123', $customer->fresh()->onu_serial);

        $live = $service->importFromPath($csv, apply: true, force: true);
        $this->assertSame(1, $live['updated']);
        $this->assertSame('FHTT0000A02C', $customer->fresh()->onu_serial);
    }

    public function test_import_skips_conflicts_without_force(): void
    {
        $this->makeCustomer('USERBE_RT08_RW09_01', 'FHTT0000A009');
        $csv = $this->writeCsv([
            ['pppoe_user', 'serial_number'],
            ['USERBE_RT08_RW09_01', 'FHTT0000A02D'],
        ]);

        $result = app(OnuSerialImportService::class)->importFromPath($csv, apply: true);

        $this->assertSame(1, $result['conflicts']);
        $this->assertSame(0, $result['updated']);
    }

    public function test_import_syncs_olt_port_from_matching_onu(): void
    {
        $customer = $this->makeCustomer('USERBG_RT08_RW09_02');
        OltOnu::create([
            'olt_id' => $customer->olt_id,
            'onu_ref' => '1/2/1:77',
            'serial_number' => 'FHTT0000A031',
            'state' => 'online',
            'state_collected_at' => now(),
        ]);

        $csv = $this->writeCsv([
            ['pppoe_user', 'serial_number'],
            ['USERBG_RT08_RW09_02', 'FHTT0000A031'],
        ]);

        app(OnuSerialImportService::class)->importFromPath($csv, apply: true);

        $customer->refresh();
        $this->assertSame('FHTT0000A031', $customer->onu_serial);
        $this->assertSame('1/2/1:77', $customer->olt_port_label);
        $this->assertSame('online', $customer->olt_status);
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function writeCsv(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'onu-import-');
        $handle = fopen($path, 'wb');
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        fclose($handle);

        return $path;
    }
}
