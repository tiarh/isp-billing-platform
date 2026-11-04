<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Customer;
use App\Models\CustomerPortalAccount;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-core')]
class ClientAreaTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    // Random per run so no password-like literal lives in the repo.
    private string $good;

    private string $bad;

    private string $fresh;

    protected function setUp(): void
    {
        parent::setUp();
        $this->good = Str::random(20);
        $this->bad = Str::random(20);
        $this->fresh = Str::random(20);
        RateLimiter::clear('client-area-login:ip:127.0.0.1');
    }

    private function enable(): void
    {
        config(['client_area.enabled' => true]);
    }

    private function customer(string $code = 'CA001', ?string $password = null): array
    {
        $password ??= $this->good;
        $this->seq++;
        $area = Area::query()->first() ?? Area::create(['code' => 'CAA', 'name' => 'CA Area']);
        $package = Package::query()->first() ?? Package::create(['code' => 'CAP', 'name' => 'CA Pkg', 'price' => 100000]);
        $customer = Customer::create([
            'code' => $code,
            'name' => 'Pelanggan '.$code,
            'phone' => '08123456'.str_pad((string) $this->seq, 4, '0', STR_PAD_LEFT),
            'address' => 'Alamat',
            'area_id' => $area->id,
            'package_id' => $package->id,
            'pppoe_user' => 'ca.'.strtolower($code),
            'status' => 'active',
        ]);
        $account = CustomerPortalAccount::create([
            'customer_id' => $customer->id,
            'password' => $password,
        ]);

        return [$customer, $account];
    }

    private function invoice(Customer $customer, string $status = 'unpaid', int $monthsAgo = 0): Invoice
    {
        return Invoice::create([
            'uuid' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'code' => 'INV-'.$customer->code.'-'.Str::random(4),
            'period' => now()->startOfMonth()->subMonths($monthsAgo),
            'amount' => 150000,
            'due_date' => now()->addDays(7),
            'status' => $status,
            'generated_at' => now(),
        ]);
    }

    public function test_flag_off_returns_404_for_all_client_area_and_legal_routes(): void
    {
        $this->get('/pelanggan/login')->assertNotFound();
        $this->post('/pelanggan/login', ['code' => 'x', 'password' => 'y'])->assertNotFound();
        $this->post('/pelanggan/logout')->assertNotFound();
        $this->get('/pelanggan')->assertNotFound();
        $this->get('/syarat-ketentuan')->assertNotFound();
        $this->get('/kebijakan-refund')->assertNotFound();
    }

    public function test_legal_pages_are_served_on_client_area_host_even_when_flag_off(): void
    {
        config(['client_area.home_host' => 'bayar.example.test']);

        $this->get('http://bayar.example.test/syarat-ketentuan')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('ClientArea/Terms'));
        $this->get('http://bayar.example.test/kebijakan-refund')->assertOk();
        $this->get('http://bayar.example.test/pelanggan/login')->assertNotFound();
    }

    public function test_enabled_serves_login_and_legal_pages(): void
    {
        $this->enable();

        $this->get('/pelanggan/login')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('ClientArea/Login'));
        $this->get('/syarat-ketentuan')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('ClientArea/Terms'));
        $this->get('/kebijakan-refund')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('ClientArea/Refund'));
    }

    public function test_root_redirects_to_client_login_only_on_home_host_when_enabled(): void
    {
        $this->enable();
        config(['client_area.home_host' => 'bayar.example.test']);

        $this->get('http://bayar.example.test/')->assertRedirect(route('client-area.login'));
        $this->get('http://other.example.test/')->assertRedirect(route('login'));
    }

    public function test_login_ok_shows_dashboard_with_regenerated_session(): void
    {
        $this->enable();
        [$customer] = $this->customer();
        $this->invoice($customer);

        $this->withSession(['probe' => 1]);
        $before = session()->getId();

        $this->post('/pelanggan/login', ['code' => 'CA001', 'password' => $this->good])
            ->assertRedirect(route('client-area.dashboard'));

        $this->assertAuthenticated('customer');
        $this->assertNotSame($before, session()->getId());
        $this->assertNotNull(CustomerPortalAccount::first()->last_login_at);

        $this->get('/pelanggan')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ClientArea/Dashboard')
                ->where('customer.code', 'CA001')
                ->has('invoices', 1));
    }

    public function test_wrong_password_unknown_code_and_inactive_use_the_same_generic_error(): void
    {
        $this->enable();
        [, $account] = $this->customer();

        $this->post('/pelanggan/login', ['code' => 'CA001', 'password' => $this->bad])
            ->assertSessionHasErrors('code');
        $this->post('/pelanggan/login', ['code' => 'NOPE', 'password' => $this->bad])
            ->assertSessionHasErrors('code');
        $this->assertGuest('customer');

        $account->update(['is_active' => false]);
        $this->post('/pelanggan/login', ['code' => 'CA001', 'password' => $this->good])
            ->assertSessionHasErrors('code');
        $this->assertGuest('customer');
    }

    public function test_lockout_after_n_failures_blocks_even_the_correct_password(): void
    {
        $this->enable();
        config(['client_area.lockout_attempts' => 3, 'client_area.throttle_code_attempts' => 50, 'client_area.throttle_ip_attempts' => 50]);
        [, $account] = $this->customer();

        for ($i = 0; $i < 3; $i++) {
            $this->post('/pelanggan/login', ['code' => 'CA001', 'password' => $this->bad]);
        }

        $this->assertTrue($account->fresh()->isLocked());
        $this->post('/pelanggan/login', ['code' => 'CA001', 'password' => $this->good])
            ->assertSessionHasErrors('code');
        $this->assertGuest('customer');

        $this->travel(20)->minutes();
        $this->post('/pelanggan/login', ['code' => 'CA001', 'password' => $this->good])
            ->assertRedirect(route('client-area.dashboard'));
        $this->assertAuthenticated('customer');
    }

    public function test_login_is_throttled_per_ip(): void
    {
        $this->enable();
        config(['client_area.throttle_ip_attempts' => 3, 'client_area.lockout_attempts' => 100]);
        $this->customer();

        for ($i = 0; $i < 3; $i++) {
            $this->post('/pelanggan/login', ['code' => 'CA001', 'password' => $this->bad]);
        }

        // Throttled: even the correct password is refused.
        $this->post('/pelanggan/login', ['code' => 'CA001', 'password' => $this->good])
            ->assertSessionHasErrors('code');
        $this->assertGuest('customer');
    }

    public function test_login_is_throttled_per_code(): void
    {
        $this->enable();
        config(['client_area.throttle_code_attempts' => 2, 'client_area.throttle_ip_attempts' => 100, 'client_area.lockout_attempts' => 100]);
        $this->customer();

        for ($i = 0; $i < 2; $i++) {
            $this->post('/pelanggan/login', ['code' => 'CA001', 'password' => $this->bad]);
        }

        $this->post('/pelanggan/login', ['code' => 'ca001', 'password' => $this->good])
            ->assertSessionHasErrors('code');
        $this->assertGuest('customer');
    }

    public function test_customer_only_sees_own_invoices_and_pay_uses_own_uuid(): void
    {
        $this->enable();
        [$a] = $this->customer('CA001');
        [$b] = $this->customer('CA002');
        $own = $this->invoice($a);
        $paid = $this->invoice($a, 'paid', 1);
        $other = $this->invoice($b);

        $this->post('/pelanggan/login', ['code' => 'CA001', 'password' => $this->good]);
        $this->get('/pelanggan')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('invoices', 2)
                ->where('invoices', function ($invoices) use ($own, $paid, $other) {
                    $rows = collect($invoices);
                    $uuids = $rows->pluck('uuid')->all();
                    $unpaid = $rows->firstWhere('uuid', $own->uuid);
                    $settled = $rows->firstWhere('uuid', $paid->uuid);

                    return in_array($own->uuid, $uuids, true)
                        && ! in_array($other->uuid, $uuids, true)
                        && $unpaid['pay_url'] === route('public.invoice.show', $own->uuid)
                        && $unpaid['receipt_url'] === null
                        && $settled['pay_url'] === null
                        && str_contains((string) $settled['receipt_url'], 'signature=')
                        && ! array_key_exists('id', $unpaid)
                        && ! array_key_exists('customer_id', $unpaid);
                }));
    }

    public function test_login_redirects_straight_to_dashboard_ignoring_staff_intended_url(): void
    {
        $this->enable();
        $this->customer();

        $this->withSession(['url.intended' => '/dashboard'])
            ->post('/pelanggan/login', ['code' => 'CA001', 'password' => $this->good])
            ->assertRedirect(route('client-area.dashboard'));
    }

    public function test_legal_pages_show_draft_marker_until_approved_and_follow_fee_bearer(): void
    {
        $this->enable();

        $this->get('/syarat-ketentuan')->assertInertia(fn (Assert $page) => $page
            ->where('legal.draft', true)
            ->where('legal.fee_bearer', 'customer'));
        $this->get('/kebijakan-refund')->assertInertia(fn (Assert $page) => $page->where('legal.draft', true));

        config(['client_area.legal_approved' => true]);
        $this->get('/syarat-ketentuan')->assertInertia(fn (Assert $page) => $page->where('legal.draft', false));

        Setting::set('payment_gateway_fee_bearer', 'merchant');
        $this->get('/syarat-ketentuan')->assertInertia(fn (Assert $page) => $page->where('legal.fee_bearer', 'merchant'));
    }

    public function test_client_area_sandbox_banner_follows_pay_page_condition(): void
    {
        $this->enable();

        // Midtrans (default provider) active: no banner even though Duitku env defaults to sandbox.
        $this->get('/pelanggan/login')->assertInertia(fn (Assert $page) => $page
            ->component('ClientArea/Login')
            ->where('sandbox', false));
    }

    public function test_every_login_path_performs_exactly_one_hash_check(): void
    {
        $this->enable();
        config(['client_area.lockout_attempts' => 100, 'client_area.throttle_code_attempts' => 100, 'client_area.throttle_ip_attempts' => 100]);
        [, $account] = $this->customer();

        Hash::spy();
        Hash::shouldReceive('make')->andReturn('x')->byDefault();
        Hash::shouldReceive('check')->times(5)->andReturn(false);

        $this->post('/pelanggan/login', ['code' => 'NOPE', 'password' => $this->bad]);
        $this->post('/pelanggan/login', ['code' => 'CA001', 'password' => $this->bad]);
        $account->update(['locked_until' => now()->addHour()]);
        $this->post('/pelanggan/login', ['code' => 'CA001', 'password' => $this->good]);
        $account->update(['locked_until' => null, 'is_active' => false]);
        $this->post('/pelanggan/login', ['code' => 'CA001', 'password' => $this->good]);
        $account->update(['is_active' => true]);
        $this->post('/pelanggan/login', ['code' => 'CA001', 'password' => $this->good]);
    }

    public function test_dashboard_requires_customer_login(): void
    {
        $this->enable();

        $this->get('/pelanggan')->assertRedirect(route('client-area.login'));
    }

    public function test_admin_session_unaffected_and_inertia_auth_user_is_null_for_customer(): void
    {
        $this->enable();
        [, $account] = $this->customer();
        $admin = User::factory()->create(['role' => 'superadmin']);

        // Customer-only session: shared auth.user stays null.
        $this->post('/pelanggan/login', ['code' => 'CA001', 'password' => $this->good]);
        $this->assertAuthenticated('customer');
        $this->get('/pelanggan')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('auth.user', null));

        // Admin + customer in the same browser: customer logout must keep admin logged in.
        Auth::guard('web')->login($admin);
        $this->post('/pelanggan/logout')->assertRedirect(route('client-area.login'));
        $this->assertAuthenticatedAs($admin);
        $this->assertGuest('customer');
    }

    public function test_pay_page_unchanged_when_flag_off(): void
    {
        [$customer] = $this->customer();
        $invoice = $this->invoice($customer);

        $this->get(route('public.invoice.show', $invoice->uuid))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Public/Payment/Show')
                ->where('invoice.uuid', $invoice->uuid)
                ->where('sandbox', false));
    }

    public function test_set_password_command_creates_and_activates_account(): void
    {
        [$customer, $account] = $this->customer();
        $account->update(['is_active' => false, 'failed_attempts' => 2]);

        $this->artisan('client-area:set-password', ['customer_code' => 'CA001'])
            ->expectsQuestion('Password baru (min. 10 karakter)', $this->fresh)
            ->expectsQuestion('Ulangi password', $this->fresh)
            ->assertSuccessful();

        $account->refresh();
        $this->assertTrue($account->is_active);
        $this->assertSame(0, $account->failed_attempts);
        $this->assertTrue(Hash::check($this->fresh, $account->password));
        $this->assertSame(1, CustomerPortalAccount::query()->where('customer_id', $customer->id)->count());

        $this->artisan('client-area:disable', ['customer_code' => 'CA001'])->assertSuccessful();
        $this->assertFalse($account->fresh()->is_active);
    }

    public function test_set_password_command_rejects_unknown_customer_and_short_password(): void
    {
        $this->artisan('client-area:set-password', ['customer_code' => 'NOPE'])->assertFailed();

        $this->customer();
        $this->artisan('client-area:set-password', ['customer_code' => 'CA001'])
            ->expectsQuestion('Password baru (min. 10 karakter)', Str::random(4))
            ->assertFailed();
    }

    public function test_web_guard_remains_default_guard(): void
    {
        $this->assertSame('web', config('auth.defaults.guard'));
        $this->assertNull(Auth::user());
    }
}
