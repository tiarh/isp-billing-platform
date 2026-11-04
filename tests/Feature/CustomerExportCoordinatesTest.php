<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use ZipArchive;

#[Group('blocking-billing')]
class CustomerExportCoordinatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_export_includes_tikor_latitude_longitude_and_maps_columns(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $package = Package::create([
            'code' => 'TIKOR-BASIC',
            'name' => 'Tikor Basic',
            'price' => 100000,
            'mikrotik_profile' => 'TIKOR-BASIC',
        ]);

        $this->makeCustomer($package, 'TIKOR-WITH', -6.4612345, 111.7183456);
        $this->makeCustomer($package, 'TIKOR-SHORT', -6.5, 111.25);
        $this->makeCustomer($package, 'TIKOR-NONE', null, null);
        $this->makeCustomer($package, 'TIKOR-ZERO', 0, 0);

        $rows = $this->exportRows($admin);
        $headers = $rows[0];

        $areaIndex = array_search('Area', $headers, true);
        $this->assertNotFalse($areaIndex);
        $this->assertSame(
            ['Tikor', 'Latitude', 'Longitude', 'Link Maps'],
            array_slice($headers, $areaIndex + 1, 4),
            'Coordinate columns must follow the address/area columns.'
        );
        $this->assertSame('Nama Langganan', $headers[$areaIndex + 5]);

        $byCode = [];
        foreach (array_slice($rows, 1) as $row) {
            $record = array_combine($headers, array_pad($row, count($headers), ''));
            $byCode[$record['ID Pelanggan']] = $record;
        }

        $this->assertSame('-6.4612345,111.7183456', $byCode['TIKOR-WITH']['Tikor']);
        $this->assertSame('-6.4612345', $byCode['TIKOR-WITH']['Latitude']);
        $this->assertSame('111.7183456', $byCode['TIKOR-WITH']['Longitude']);
        $this->assertSame('https://maps.google.com/?q=-6.4612345,111.7183456', $byCode['TIKOR-WITH']['Link Maps']);

        $this->assertSame('-6.5,111.25', $byCode['TIKOR-SHORT']['Tikor']);
        $this->assertSame('-6.5', $byCode['TIKOR-SHORT']['Latitude']);
        $this->assertSame('111.25', $byCode['TIKOR-SHORT']['Longitude']);

        foreach (['TIKOR-NONE', 'TIKOR-ZERO'] as $code) {
            $this->assertSame('', $byCode[$code]['Tikor'], $code);
            $this->assertSame('', $byCode[$code]['Latitude'], $code);
            $this->assertSame('', $byCode[$code]['Longitude'], $code);
            $this->assertSame('', $byCode[$code]['Link Maps'], $code);
        }
    }

    public function test_coordinate_export_stays_admin_only(): void
    {
        $teknisi = User::factory()->create(['role' => 'teknisi']);

        $this->actingAs($teknisi)->get(route('customers.export'))->assertForbidden();
    }

    private function makeCustomer(Package $package, string $code, mixed $lat, mixed $lng): Customer
    {
        return Customer::create([
            'code' => $code,
            'name' => 'Customer '.$code,
            'phone' => '0800'.random_int(10000000, 99999999),
            'address' => 'Alamat '.$code,
            'pppoe_user' => strtolower($code),
            'package_id' => $package->id,
            'status' => 'active',
            'geo_lat' => $lat,
            'geo_long' => $lng,
        ]);
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function exportRows(User $user): array
    {
        $response = $this->actingAs($user)->get(route('customers.export'));
        $response->assertSuccessful();

        $path = $response->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($path);

        // Numeric cells must never be written in scientific notation.
        $this->assertDoesNotMatchRegularExpression('/<v>[^<]*[eE][^<]*<\/v>/', $sheet);

        $xml = simplexml_load_string($sheet);
        $this->assertNotFalse($xml);

        $rows = [];
        foreach ($xml->sheetData->row as $row) {
            $values = [];
            foreach ($row->c as $cell) {
                $values[] = isset($cell->is) ? (string) $cell->is->t : (string) $cell->v;
            }
            $rows[] = $values;
        }

        return $rows;
    }
}
