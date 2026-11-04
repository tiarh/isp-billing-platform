<?php

namespace Tests\Feature;

use App\Http\Middleware\SecurityHeaders;
use App\Models\Area;
use App\Models\Customer;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * PR-UX4 (Fase 2, beban FE): R1, R6, I2, R5, C11.
 */
class FrontendLoadUx4FrontendContractTest extends TestCase
{
    use RefreshDatabase;

    private function source(string $path): string
    {
        return (string) file_get_contents(base_path($path));
    }

    // ---- R1 + R6: shared polling hook ---------------------------------------

    public function test_shared_poll_hook_pauses_when_hidden_backs_off_and_can_stop(): void
    {
        $hook = $this->source('resources/js/hooks/usePollWhileVisible.ts');

        $this->assertStringContainsString("document.visibilityState === 'hidden'", $hook);
        $this->assertStringContainsString("addEventListener('visibilitychange'", $hook);
        $this->assertStringContainsString('maxMs', $hook);
        $this->assertStringContainsString('Math.min(ceiling, Math.round(currentInterval * Math.max(1, backoff)))', $hook);
        $this->assertStringContainsString('setStopped(true)', $hook);
        $this->assertStringNotContainsString('setInterval', $hook, 'Sequential setTimeout loop: requests never overlap.');
    }

    public function test_router_sync_polling_uses_hook_with_backoff_and_five_minute_stop(): void
    {
        foreach (['resources/js/Pages/Routers/Index.tsx', 'resources/js/Pages/Routers/Show.tsx'] as $path) {
            $source = $this->source($path);

            $this->assertStringContainsString('usePollWhileVisible(() => reloadOnly([', $source, $path);
            $this->assertStringContainsString('maxIntervalMs: 15000,', $source, $path);
            $this->assertStringContainsString('maxMs: 5 * 60 * 1000,', $source, $path);
            $this->assertStringContainsString('<PollStoppedNotice', $source, $path);
            $this->assertStringNotContainsString('window.setInterval', $source, $path);
        }
    }

    public function test_other_pollers_use_the_shared_hook(): void
    {
        $olt = $this->source('resources/js/Pages/Olts/Show.tsx');
        $this->assertStringContainsString('usePollWhileVisible(', $olt);
        $this->assertStringNotContainsString('}, 45000);', $olt);

        foreach (['resources/js/Pages/Broadcasts/Index.tsx', 'resources/js/Pages/Broadcasts/Show.tsx'] as $path) {
            $source = $this->source($path);
            $this->assertStringContainsString('usePollWhileVisible(() => reloadOnly([', $source, $path);
            $this->assertStringNotContainsString('setInterval', $source, $path);
        }

        $wa = $this->source('resources/js/Pages/Settings/WaPair.tsx');
        $this->assertStringContainsString('usePollWhileVisible(() => refreshStatus(activeChannel), 5000', $wa);
        $this->assertStringContainsString('usePollWhileVisible(() => refreshQr(activeChannel), 20000', $wa);
        $this->assertStringContainsString('QR refresh otomatis setiap ~20 detik', $wa, 'Copy and behaviour now agree.');
        $this->assertStringNotContainsString('setInterval', $wa);
    }

    // ---- I2: Invoice/Create async customer search -----------------------------

    public function test_invoice_create_searches_customers_server_side(): void
    {
        $source = $this->source('resources/js/Pages/Invoices/Create.tsx');

        $this->assertStringContainsString("route('invoices.customer-options')", $source);
        $this->assertStringContainsString('<Command shouldFilter={false}>', $source);
        $this->assertStringContainsString('}, 300);', $source, 'Debounced.');
        $this->assertStringContainsString('controller.abort()', $source);
        $this->assertStringNotContainsString('customers.find(', $source);
    }

    public function test_invoice_create_no_longer_preloads_customers(): void
    {
        $admin = User::factory()->create(['role' => 'superadmin']);
        [$area] = $this->seedCustomers();

        $this->actingAs($admin)
            ->get(route('invoices.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Invoices/Create')
                ->has('customers', 0)
                ->has('areas'));
    }

    public function test_customer_options_filters_billable_customers_and_searches(): void
    {
        $admin = User::factory()->create(['role' => 'superadmin']);
        [$areaA, $areaB] = $this->seedCustomers();

        $all = $this->actingAs($admin)->getJson(route('invoices.customer-options'))->assertOk()->json();
        $names = collect($all)->pluck('name')->all();
        $this->assertContains('Budi Aktif', $names);
        $this->assertContains('Citra Isolir', $names);
        $this->assertNotContains('Dodi Nonaktif', $names, 'Only active/isolated customers.');
        $this->assertNotContains('Impor MikroTik', $names, 'eBilling customers only.');
        $this->assertSame(75000, (int) collect($all)->firstWhere('name', 'Budi Aktif')['package']['price']);

        $search = $this->actingAs($admin)->getJson(route('invoices.customer-options', ['q' => 'citra']))->assertOk()->json();
        $this->assertSame(['Citra Isolir'], collect($search)->pluck('name')->all());

        $byArea = $this->actingAs($admin)->getJson(route('invoices.customer-options', ['area_id' => $areaB->id]))->assertOk()->json();
        $this->assertSame(['Citra Isolir'], collect($byArea)->pluck('name')->all());

        $wildcard = $this->actingAs($admin)->getJson(route('invoices.customer-options', ['q' => '%']))->assertOk()->json();
        $this->assertSame([], $wildcard, 'LIKE wildcards are escaped.');
    }

    public function test_customer_options_is_limited_area_scoped_and_admin_only(): void
    {
        [$areaA, $areaB] = $this->seedCustomers();
        $package = Package::query()->firstOrFail();
        for ($i = 0; $i < 60; $i++) {
            $this->customer('Massal '.$i, $areaA, $package, 'active');
        }

        $superadmin = User::factory()->create(['role' => 'superadmin']);
        $this->assertCount(50, $this->actingAs($superadmin)->getJson(route('invoices.customer-options'))->assertOk()->json());

        $scoped = User::factory()->create(['role' => 'admin']);
        $scoped->areas()->attach($areaB);
        $rows = $this->actingAs($scoped)->getJson(route('invoices.customer-options'))->assertOk()->json();
        $this->assertSame([$areaB->id], collect($rows)->pluck('area_id')->unique()->values()->all());

        $this->actingAs(User::factory()->create(['role' => 'teknisi']))
            ->getJson(route('invoices.customer-options'))
            ->assertForbidden();
    }

    // ---- R5 + C11: Leaflet ---------------------------------------------------

    public function test_leaflet_is_bundled_and_unpkg_is_gone_from_csp(): void
    {
        $peta = $this->source('resources/js/Pages/Peta/PetaMap.tsx');
        $this->assertStringNotContainsString('unpkg.com', $peta);
        $this->assertStringContainsString("import('leaflet')", $peta);
        $this->assertStringContainsString("import 'leaflet/dist/leaflet.css';", $peta);

        $this->assertStringNotContainsString('unpkg.com', $this->source('app/Http/Middleware/SecurityHeaders.php'));

        $response = (new SecurityHeaders)->handle(Request::create('/peta', 'GET'), static fn () => response('ok'));
        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertNotSame('', $csp);
        $this->assertStringNotContainsString('unpkg.com', $csp);
    }

    public function test_customer_show_loads_map_picker_lazily(): void
    {
        $show = $this->source('resources/js/Pages/Customers/Show.tsx');

        $this->assertStringContainsString("const MapPicker = lazy(() => import('@/Components/MapPicker'));", $show);
        $this->assertStringNotContainsString("import MapPicker from '@/Components/MapPicker';", $show);
        $this->assertStringContainsString('<Suspense fallback=', $show);
    }

    /**
     * @return array{0: Area, 1: Area}
     */
    private function seedCustomers(): array
    {
        $areaA = Area::create(['code' => 'UX4A-'.Str::upper(Str::random(4)), 'name' => 'UX4 Area A']);
        $areaB = Area::create(['code' => 'UX4B-'.Str::upper(Str::random(4)), 'name' => 'UX4 Area B']);
        $package = Package::create(['code' => 'UX4-'.Str::upper(Str::random(4)), 'name' => 'UX4 Paket', 'price' => 75000]);

        $this->customer('Budi Aktif', $areaA, $package, 'active');
        $this->customer('Citra Isolir', $areaB, $package, 'isolated');
        $this->customer('Dodi Nonaktif', $areaA, $package, 'inactive');
        $this->customer('Impor MikroTik', $areaA, $package, 'active', 'IMP-'.Str::upper(Str::random(6)));

        return [$areaA, $areaB];
    }

    private function customer(string $name, Area $area, Package $package, string $status, ?string $code = null): Customer
    {
        return Customer::create([
            'code' => $code ?? 'UX4-'.Str::upper(Str::random(8)),
            'name' => $name,
            'address' => 'Jl. Test',
            'phone' => '081234567890',
            'pppoe_user' => 'ux4.'.Str::lower(Str::random(10)),
            'area_id' => $area->id,
            'package_id' => $package->id,
            'status' => $status,
        ]);
    }
}
