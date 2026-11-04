<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use ZipArchive;

#[Group('blocking-billing')]
class CustomerExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_export_contains_filtered_operational_customers(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $package = Package::create([
            'code' => 'EXPORT-BASIC',
            'name' => 'Export Basic',
            'price' => 100000,
            'mikrotik_profile' => 'EXPORT-BASIC',
        ]);

        Customer::create([
            'code' => 'EXPORT-MATCH',
            'name' => 'Export Match Customer',
            'phone' => '080000000001',
            'address' => 'Export address',
            'pppoe_user' => 'export.match',
            'package_id' => $package->id,
            'status' => 'active',
        ]);
        Customer::create([
            'code' => 'EXPORT-OTHER',
            'name' => 'Export Other Customer',
            'phone' => '080000000002',
            'address' => 'Export address',
            'pppoe_user' => 'export.other',
            'package_id' => $package->id,
            'status' => 'active',
        ]);

        $response = $this->actingAs($admin)->get(route('customers.export', ['search' => 'Export Match']));
        $response->assertSuccessful();

        $path = $response->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive;
        $this->assertSame(true, $zip->open($path));
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($path);

        $this->assertSame(1, substr_count($sheet, 'Export Match Customer'));
        $this->assertStringNotContainsString('Export Other Customer', $sheet);
    }

    public function test_customer_export_is_rate_limited_and_audited_without_raw_filter_values(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $response = $this->actingAs($admin)->get(route('customers.export', [
                'search' => 'Sensitive Customer Name',
                'status' => 'active',
            ]));

            $response->assertSuccessful();
            $path = $response->baseResponse->getFile()?->getPathname();
            if ($path && is_file($path)) {
                @unlink($path);
            }
        }

        $this->actingAs($admin)
            ->get(route('customers.export', ['search' => 'Sensitive Customer Name']))
            ->assertTooManyRequests();

        $activity = DB::table('activity_log')
            ->where('description', 'customer_exported')
            ->where('causer_id', $admin->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertStringNotContainsString('Sensitive Customer Name', (string) $activity->properties);
        $this->assertStringContainsString('search_present', (string) $activity->properties);
    }
}
