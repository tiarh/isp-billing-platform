<?php

namespace Tests\Feature;

use App\Http\Middleware\SecurityHeaders;
use App\Models\Area;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use ZipArchive;

#[Group('blocking-core')]
class CustomerInvoicesRedirectTest extends TestCase
{
    use RefreshDatabase;

    private function makeCustomer(Area $area, string $code): Customer
    {
        $package = Package::firstOrCreate(['code' => 'BASIC'], ['name' => 'Basic', 'price' => 100000]);

        return Customer::create([
            'code' => $code,
            'name' => 'Customer '.$code,
            'phone' => '0811111111',
            'address' => 'Addr',
            'area_id' => $area->id,
            'package_id' => $package->id,
            'pppoe_user' => strtolower($code),
            'status' => 'active',
        ]);
    }

    private function makeInvoice(Customer $customer, string $code): Invoice
    {
        return Invoice::create([
            'code' => $code,
            'customer_id' => $customer->id,
            'period' => now()->startOfMonth()->toDateString(),
            'amount' => 100000,
            'status' => 'unpaid',
            'due_date' => now()->addDays(7)->toDateString(),
        ]);
    }

    public function test_customer_invoices_redirects_to_filtered_invoice_index_and_lists_only_that_customer(): void
    {
        $user = User::factory()->create(['role' => 'superadmin']);
        $area = Area::create(['code' => 'A', 'name' => 'Area A']);
        $mine = $this->makeCustomer($area, 'A001');
        $other = $this->makeCustomer($area, 'A0012');
        $this->makeInvoice($mine, 'INV-MINE');
        $this->makeInvoice($other, 'INV-OTHER');

        $this->actingAs($user)
            ->get(route('customers.invoices', $mine))
            ->assertRedirect(route('invoices.index', ['customer_id' => $mine->id, 'search' => 'A001']));

        $this->actingAs($user)
            ->get(route('invoices.index', ['customer_id' => $mine->id, 'search' => 'A001']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Invoices/Index')
                ->has('invoices.data', 1)
                ->where('invoices.data.0.code', 'INV-MINE'));
    }

    public function test_index_filters_prop_and_export_keep_customer_id(): void
    {
        $user = User::factory()->create(['role' => 'superadmin']);
        $area = Area::create(['code' => 'A', 'name' => 'Area A']);
        $mine = $this->makeCustomer($area, 'A001');
        $other = $this->makeCustomer($area, 'A0012');
        $this->makeInvoice($mine, 'INV-MINE');
        $this->makeInvoice($other, 'INV-OTHER');

        $this->actingAs($user)
            ->get(route('invoices.index', ['customer_id' => $mine->id, 'search' => 'A001']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.customer_id', $mine->id)
                ->where('filters.search', 'A001'));

        $this->actingAs($user)
            ->get(route('invoices.index'))
            ->assertInertia(fn (Assert $page) => $page->where('filters.customer_id', null));

        // Export with the same filters (search=A001 alone would LIKE-match A0012).
        $response = $this->actingAs($user)
            ->get(route('invoices.export', ['customer_id' => $mine->id, 'search' => 'A001']));
        $file = $response->baseResponse->getFile();
        $this->assertNotNull($file);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($file->getPathname()));
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        $this->assertStringContainsString('Customer A001<', $sheet);
        $this->assertStringNotContainsString('Customer A0012', $sheet);

        // Frontend keeps the filter through table syncs and export.
        $index = (string) file_get_contents(base_path('resources/js/Pages/Invoices/Index.tsx'));
        $this->assertStringContainsString('customer_id?:', $index);
        $this->assertStringContainsString('routeParams={tableRouteParams}', $index);
        $this->assertStringContainsString('Hapus filter pelanggan', $index);
    }

    public function test_customer_invoices_stays_area_scoped(): void
    {
        $allowed = Area::create(['code' => 'A', 'name' => 'Area A']);
        $denied = Area::create(['code' => 'B', 'name' => 'Area B']);
        $admin = User::factory()->create(['role' => 'admin']);
        $admin->areas()->attach($allowed);
        $inArea = $this->makeCustomer($allowed, 'A001');
        $outArea = $this->makeCustomer($denied, 'B001');
        $this->makeInvoice($outArea, 'INV-OUT');

        $this->actingAs($admin)->get(route('customers.invoices', $inArea))->assertRedirect();
        $this->actingAs($admin)->get(route('customers.invoices', $outArea))->assertForbidden();

        // Even a hand-crafted customer_id filter cannot widen the index.
        $this->actingAs($admin)
            ->get(route('invoices.index', ['customer_id' => $outArea->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('invoices.data', 0));
    }

    public function test_missing_customers_invoices_page_component_is_not_rendered_anymore(): void
    {
        $source = (string) file_get_contents(base_path('app/Http/Controllers/InvoiceController.php'));
        $this->assertStringNotContainsString("'Customers/Invoices'", $source);
        $this->assertStringContainsString("route('customers.invoices', customer.id)", (string) file_get_contents(base_path('resources/js/Pages/Customers/Index.tsx')));
    }

    public function test_csp_allows_carto_dark_tiles_and_not_stadia(): void
    {
        $response = (new SecurityHeaders)->handle(Request::create('/peta', 'GET'), static fn () => response('ok'));
        $csp = (string) $response->headers->get('Content-Security-Policy');

        $this->assertMatchesRegularExpression('/img-src [^;]*https:\/\/\*\.basemaps\.cartocdn\.com/', $csp);
        $this->assertStringNotContainsString('stadiamaps', $csp);

        $peta = (string) file_get_contents(base_path('resources/js/Pages/Peta/PetaMap.tsx'));
        $this->assertStringContainsString('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', $peta);
        $this->assertStringContainsString('CARTO', $peta);
        $this->assertStringNotContainsString('stadiamaps', $peta);
    }
}
