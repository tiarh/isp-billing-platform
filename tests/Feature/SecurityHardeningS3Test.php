<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Audit 2026-10-06 (docs/AUDIT_EBILLING_UX_PERF_SEC_20261006.md): route-level
 * gates that previously only required `auth`, so the teknisi role could reach
 * fleet-wide PII, plus the media-URL scheme guard.
 */
#[Group('blocking-core')]
class SecurityHardeningS3Test extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_export_requires_admin_and_is_throttled(): void
    {
        $route = app('router')->getRoutes()->getByName('invoices.export');
        $this->assertNotNull($route);
        $this->assertContains('admin', $route->gatherMiddleware());
        $this->assertContains('throttle:finance-export', $route->gatherMiddleware());

        $teknisi = User::factory()->create(['role' => 'teknisi']);
        $this->actingAs($teknisi)
            ->get(route('invoices.export'))
            ->assertForbidden();
    }

    public function test_radius_suite_module_pages_are_admin_only(): void
    {
        $teknisi = User::factory()->create(['role' => 'teknisi']);

        foreach (['modules.ticketing', 'modules.reseller', 'modules.hotspot', 'modules.genieacs'] as $name) {
            $this->assertContains('admin', app('router')->getRoutes()->getByName($name)->gatherMiddleware(), $name);
            $this->actingAs($teknisi)->get(route($name))->assertForbidden();
        }

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get(route('modules.ticketing'))->assertOk();
    }

    public function test_password_reset_submission_is_throttled(): void
    {
        $route = app('router')->getRoutes()->getByName('password.store');
        $this->assertNotNull($route);
        $this->assertContains('throttle:6,1', $route->gatherMiddleware());
    }

    public function test_customer_media_url_only_passes_through_http_schemes(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $package = Package::create([
            'code' => 'S3-BASIC',
            'name' => 'S3 Basic',
            'price' => 100000,
            'mikrotik_profile' => 'S3-BASIC',
        ]);

        $customer = Customer::create([
            'code' => 'S3-XSS',
            'name' => 'Scheme Guard Customer',
            'phone' => '080000000009',
            'address' => 'Jl. Uji',
            'pppoe_user' => 's3.scheme',
            'package_id' => $package->id,
            'status' => 'active',
            // Values like this can arrive via legacy sync / XLSX import and are
            // opened with window.open() in Customers/Show.
            'ktp_photo_url' => 'javascript://%0aalert(1)',
            'odp_photo_url' => 'https://cdn.example.test/odp.jpg',
        ]);

        $html = $this->actingAs($admin)
            ->get(route('customers.show', $customer))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString('https://cdn.example.test/odp.jpg', str_replace('\/', '/', $html));
    }
}
