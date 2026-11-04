<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\Setting;
use App\Support\CompanyContact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * PR-H1: company contact moved from literals to settings. With no setting
 * rows the customer-facing output must equal the previous hardcoded values.
 */
#[Group('blocking-core')]
class PublicInvoiceShowContactDefaultsTest extends TestCase
{
    use RefreshDatabase;

    // The contact defaults (email, phone, WA admin) come from config/site.php.

    private const PREVIOUS_INVOICE_COMPANY_NAME = 'PT. SKYNET LINTAS NUSANTARA';

    private const PREVIOUS_PUBLIC_COMPANY_NAME = 'Skynet Network';

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['company_name', 'company_address', 'company_email', 'company_phone', 'company_wa_admin', 'isolation_portal_wa_admin', 'payment_channels'] as $key) {
            Cache::forget("setting.$key");
        }
        Setting::flushRequestMemo();
        config(['services.isolation_portal.wa_admin' => '']);
    }

    public function test_contact_defaults_equal_previous_literals(): void
    {
        $this->assertSame(config('site.company.email'), CompanyContact::defaultEmail());
        $this->assertSame(config('site.company.phone'), CompanyContact::defaultPhone());
        $this->assertSame(config('site.company.wa_admin'), CompanyContact::defaultWaAdmin());

        $this->assertSame(config('site.company.email'), CompanyContact::email());
        $this->assertSame(config('site.company.phone'), CompanyContact::phone());
        $this->assertSame(config('site.company.wa_admin'), CompanyContact::waAdmin());
    }

    public function test_saved_contact_settings_override_and_empty_falls_back(): void
    {
        Setting::set('company_email', 'billing@example.test', 'text', 'billing');
        Setting::set('company_phone', '', 'text', 'billing');
        Setting::set('company_wa_admin', '628111', 'text', 'billing');

        $this->assertSame('billing@example.test', CompanyContact::email());
        $this->assertSame(config('site.company.phone'), CompanyContact::phone());
        $this->assertSame('628111', CompanyContact::waAdmin());
    }

    public function test_invoice_pdf_company_block_is_unchanged_without_settings(): void
    {
        $captured = $this->fakePdfWrapper();
        $invoice = $this->makeInvoice();

        $this->get(URL::signedRoute('public.invoices.receipt', $invoice))->assertOk();

        $this->assertSame([
            'name' => self::PREVIOUS_INVOICE_COMPANY_NAME,
            'address' => 'Randuagung Gg VIII RT3, RW7, No.01 Singosari - Malang 65153',
            'email' => config('site.company.email'),
            'phone' => config('site.company.phone'),
        ], $captured->data['company'] ?? null);
    }

    public function test_payment_page_wa_admin_prop_defaults_to_previous_number(): void
    {
        $invoice = $this->makeInvoice();

        $this->get(route('public.invoice.show', $invoice->uuid))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Public/Payment/Show')
                ->where('company.name', self::PREVIOUS_PUBLIC_COMPANY_NAME)
                ->where('company.wa_admin', config('site.company.wa_admin')));
    }

    public function test_payment_page_isolation_portal_number_still_wins(): void
    {
        Setting::set('isolation_portal_wa_admin', '0812-0000-0099', 'text', 'isolation_portal');
        $invoice = $this->makeInvoice();

        $this->get(route('public.invoice.show', $invoice->uuid))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('company.wa_admin', '0812-0000-0099'));
    }

    public function test_payment_page_and_invoice_controller_no_longer_hardcode_contact(): void
    {
        $show = (string) file_get_contents(base_path('resources/js/Pages/Public/Payment/Show.tsx'));
        $this->assertStringNotContainsString(config('site.company.wa_admin'), $show);

        $controller = (string) file_get_contents(base_path('app/Http/Controllers/InvoiceController.php'));
        $this->assertStringNotContainsString(config('site.company.email'), $controller);
        $this->assertStringNotContainsString(config('site.company.phone'), $controller);
        // Company name default is intentionally untouched (PR-H1 item 3).
        $this->assertStringContainsString("Setting::get('company_name', '".self::PREVIOUS_INVOICE_COMPANY_NAME."')", $controller);

        $settings = (string) file_get_contents(base_path('resources/js/Pages/Settings/Index.tsx'));
        foreach (['company_email', 'company_phone', 'company_wa_admin'] as $key) {
            $this->assertStringContainsString("key: '{$key}'", $settings);
        }
    }

    private function fakePdfWrapper(): object
    {
        $fake = new class
        {
            public array $data = [];

            public function loadView(string $view, array $data = []): static
            {
                $this->data = $data;

                return $this;
            }

            public function stream(string $filename = 'document.pdf')
            {
                return response('%PDF-fake', 200, ['Content-Type' => 'application/pdf']);
            }
        };
        $this->app->instance('dompdf.wrapper', $fake);

        return $fake;
    }

    private function makeInvoice(): Invoice
    {
        $area = Area::create(['code' => 'H1C', 'name' => 'Area H1 Contact']);
        $package = Package::create(['code' => 'H1C', 'name' => 'Paket H1', 'price' => 150000]);
        $customer = Customer::create([
            'code' => 'H1C001',
            'name' => 'Pelanggan H1',
            'phone' => '081234567890',
            'address' => 'Alamat Test',
            'area_id' => $area->id,
            'package_id' => $package->id,
            'pppoe_user' => 'h1c001',
            'status' => 'active',
        ]);

        return Invoice::create([
            'uuid' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'code' => 'INV-H1C-001',
            'period' => now()->startOfMonth()->format('Y-m-d'),
            'amount' => 150000,
            'due_date' => now()->addDays(7),
            'status' => 'unpaid',
            'generated_at' => now(),
        ]);
    }
}
