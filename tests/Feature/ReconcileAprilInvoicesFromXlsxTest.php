<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReconcileAprilInvoicesFromXlsxTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_marks_only_april_2026_last_payment_as_paid(): void
    {
        $package = $this->createPackage();

        $aprilCustomer = $this->createCustomer('RDG001', $package->id);
        $marchCustomer = $this->createCustomer('RDG002', $package->id);
        $mayCustomer = $this->createCustomer('RDG003', $package->id);
        $oldAprilCustomer = $this->createCustomer('RDG004', $package->id);
        $missingPaymentCustomer = $this->createCustomer('RDG005', $package->id);
        $inactiveCustomer = $this->createCustomer('RDG006', $package->id);

        $xlsx = $this->makeXlsx([
            ['RDG001', 'Aktif ', 46113],
            ['RDG002', 'Aktif', 'Sunday, March 01, 2026'],
            ['RDG003', 'Aktif', 'Friday, May 01, 2026'],
            ['RDG004', 'Aktif', '4/1/25'],
            ['RDG005', 'Aktif', 'Data Belum Ada'],
            ['RDG006', 'Tidak Aktif ', 46113],
        ]);

        $this->artisan('billing:reconcile-april-xlsx', ['path' => $xlsx])
            ->assertSuccessful();

        $this->assertSame('paid', $this->aprilInvoice($aprilCustomer)->status);
        $this->assertSame('unpaid', $this->aprilInvoice($marchCustomer)->status);
        $this->assertSame('unpaid', $this->aprilInvoice($mayCustomer)->status);
        $this->assertSame('unpaid', $this->aprilInvoice($oldAprilCustomer)->status);
        $this->assertSame('unpaid', $this->aprilInvoice($missingPaymentCustomer)->status);
        $this->assertNull($this->aprilInvoice($inactiveCustomer));
    }

    public function test_it_updates_existing_april_invoice_without_creating_duplicate(): void
    {
        $package = $this->createPackage();
        $customer = $this->createCustomer('RDG010', $package->id);

        $invoice = Invoice::create([
            'customer_id' => $customer->id,
            'period' => '2026-04-01',
            'amount' => 99000,
            'status' => 'unpaid',
            'due_date' => '2026-04-20',
            'generated_at' => now()->subDay(),
            'payment_link' => 'https://pay.example/invoice',
        ]);

        $xlsx = $this->makeXlsx([
            ['RDG010', 'Aktif', 'Wednesday, April 01, 2026'],
        ]);

        $this->artisan('billing:reconcile-april-xlsx', ['path' => $xlsx])
            ->assertSuccessful();

        $invoice->refresh();

        $this->assertSame('paid', $invoice->status);
        $this->assertSame('99000.00', $invoice->amount);
        $this->assertSame('https://pay.example/invoice', $invoice->payment_link);
        $this->assertSame(1, Invoice::where('customer_id', $customer->id)->where('period', '2026-04-01')->count());
    }

    private function createPackage(): Package
    {
        return Package::create([
            'code' => 'PKG-'.uniqid(),
            'name' => 'Paket Test',
            'price' => 111000,
        ]);
    }

    private function createCustomer(string $code, int $packageId): Customer
    {
        return Customer::create([
            'code' => $code,
            'name' => 'Customer '.$code,
            'phone' => '08123456789',
            'address' => 'Test Address',
            'pppoe_user' => strtolower($code),
            'package_id' => $packageId,
            'status' => 'active',
            'join_date' => '2026-01-01',
            'due_day' => 20,
        ]);
    }

    private function aprilInvoice(Customer $customer): ?Invoice
    {
        return Invoice::where('customer_id', $customer->id)
            ->where('period', '2026-04-01')
            ->first();
    }

    /**
     * @param  array<int, array{0: string, 1: string, 2: string|int}>  $rows
     */
    private function makeXlsx(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'april-xlsx-').'.xlsx';
        $dir = sys_get_temp_dir().'/april-xlsx-dir-'.uniqid();

        mkdir($dir.'/_rels', 0777, true);
        mkdir($dir.'/xl/_rels', 0777, true);
        mkdir($dir.'/xl/worksheets', 0777, true);

        file_put_contents($dir.'/[Content_Types].xml', $this->contentTypesXml());
        file_put_contents($dir.'/_rels/.rels', $this->rootRelsXml());
        file_put_contents($dir.'/xl/workbook.xml', $this->workbookXml());
        file_put_contents($dir.'/xl/_rels/workbook.xml.rels', $this->workbookRelsXml());
        file_put_contents($dir.'/xl/worksheets/sheet1.xml', $this->sheetXml($rows));

        $command = 'cd '.escapeshellarg($dir).' && zip -qr '.escapeshellarg($path).' .';
        exec($command, $output, $exitCode);

        if ($exitCode !== 0) {
            $this->fail('Failed to build XLSX test fixture.');
        }

        return $path;
    }

    private function sheetXml(array $rows): string
    {
        $headers = [
            'ID Pelanggan',
            'Nama Pelanggan',
            'Alamat Pelanggan',
            'Tlp',
            'Username',
            'Password',
            'Nama Langganan',
            'Harga',
            'Profile',
            'Status',
            'Router',
            'Pembayaran Terakhir',
        ];

        $xmlRows = [$this->rowXml(1, $headers)];
        foreach ($rows as $index => $row) {
            $xmlRows[] = $this->rowXml($index + 2, [
                $row[0],
                'Customer '.$row[0],
                'Address',
                '08123456789',
                strtolower($row[0]),
                'secret',
                'Paket Test',
                111000,
                '10MB',
                $row[1],
                'Router',
                $row[2],
            ]);
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetData>'.implode('', $xmlRows).'</sheetData>'
            .'</worksheet>';
    }

    private function rowXml(int $rowNumber, array $values): string
    {
        $cells = [];
        foreach ($values as $index => $value) {
            $column = $this->columnName($index + 1);
            $ref = $column.$rowNumber;

            if (is_int($value) || is_float($value)) {
                $cells[] = '<c r="'.$ref.'"><v>'.$value.'</v></c>';

                continue;
            }

            $cells[] = '<c r="'.$ref.'" t="inlineStr"><is><t>'
                .htmlspecialchars((string) $value, ENT_XML1)
                .'</t></is></c>';
        }

        return '<row r="'.$rowNumber.'">'.implode('', $cells).'</row>';
    }

    private function columnName(int $number): string
    {
        $name = '';
        while ($number > 0) {
            $number--;
            $name = chr(65 + ($number % 26)).$name;
            $number = intdiv($number, 26);
        }

        return $name;
    }

    private function contentTypesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'</Types>';
    }

    private function rootRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    private function workbookXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets>'
            .'</workbook>';
    }

    private function workbookRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'</Relationships>';
    }
}
