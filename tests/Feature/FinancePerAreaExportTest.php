<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use ZipArchive;

#[Group('blocking-billing')]
class FinancePerAreaExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_contains_effective_finance_rows_and_sanitizes_formula_cells(): void
    {
        $admin = User::factory()->create(['role' => 'superadmin']);
        $area = Area::create(['code' => 'EXP-A', 'name' => 'Export Area']);
        $package = Package::create(['code' => 'EXP-PKG', 'name' => 'Export Package', 'price' => 100000]);
        $customer = Customer::create([
            'code' => '=2+2',
            'name' => '+Customer Formula',
            'phone' => '081234567890',
            'address' => 'Export address',
            'pppoe_user' => 'must-not-be-exported',
            'area_id' => $area->id,
            'package_id' => $package->id,
            'status' => 'active',
        ]);
        Invoice::create([
            'code' => '@INVOICE',
            'customer_id' => $customer->id,
            'period' => '2026-09-01',
            'amount' => 100000,
            'status' => 'unpaid',
            'due_date' => '2026-09-20',
            'generated_at' => '2026-09-01 00:00:00',
        ]);

        $response = $this->actingAs($admin)->get(route('api.finance.per-area.export', [
            'period' => '2026-09',
            'as_of' => '2026-09-30',
        ]));
        $response->assertSuccessful();

        $path = $response->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive;
        $this->assertSame(true, $zip->open($path));
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($path);

        $this->assertStringContainsString('Kode invoice', $sheet);
        $this->assertStringContainsString('&apos;@INVOICE', $sheet);
        $this->assertStringContainsString('&apos;=2+2', $sheet);
        $this->assertStringContainsString('&apos;+Customer Formula', $sheet);
        $this->assertStringContainsString('100000.00', $sheet);
        $this->assertStringNotContainsString('must-not-be-exported', $sheet);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'finance-per-area.export',
            'causer_id' => $admin->id,
            'description' => 'finance_per_area_exported',
        ]);
        $activity = DB::table('activity_log')->where('log_name', 'finance-per-area.export')->latest('id')->first();
        $this->assertStringNotContainsString('must-not-be-exported', (string) $activity->properties);
    }

    public function test_scoped_admin_cannot_export_another_area(): void
    {
        $allowed = Area::create(['code' => 'EXP-ALLOWED', 'name' => 'Allowed Area']);
        $blocked = Area::create(['code' => 'EXP-BLOCKED', 'name' => 'Blocked Area']);
        $admin = User::factory()->create(['role' => 'admin']);
        $admin->areas()->attach($allowed);

        $this->actingAs($admin)
            ->get(route('api.finance.per-area.export', ['period' => '2026-09', 'area' => $blocked->id]))
            ->assertForbidden();
    }

    public function test_export_is_rate_limited_and_invalid_period_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'superadmin']);

        $this->actingAs(User::factory()->create(['role' => 'superadmin']))
            ->getJson(route('api.finance.per-area.export', ['period' => '2026-09\' OR 1=1']))
            ->assertUnprocessable();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $response = $this->actingAs($admin)->get(route('api.finance.per-area.export', ['period' => '2026-09']));
            $response->assertSuccessful();
            $path = $response->baseResponse->getFile()?->getPathname();
            if ($path && is_file($path)) {
                @unlink($path);
            }
        }

        $this->actingAs($admin)
            ->get(route('api.finance.per-area.export', ['period' => '2026-09']))
            ->assertTooManyRequests();
    }
}
