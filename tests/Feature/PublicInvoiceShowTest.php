<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-core')]
class PublicInvoiceShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_invoice_page_renders_without_auth(): void
    {
        $area = Area::create(['code' => 'PUB', 'name' => 'Area Public']);
        $package = Package::create(['code' => 'BASIC', 'name' => 'Basic', 'price' => 150000]);
        $customer = Customer::create([
            'code' => 'PUB001',
            'name' => 'Pelanggan Public',
            'phone' => '081234567890',
            'address' => 'Alamat Test',
            'area_id' => $area->id,
            'package_id' => $package->id,
            'pppoe_user' => 'pub001',
            'status' => 'active',
        ]);
        $invoice = Invoice::create([
            'uuid' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'code' => 'INV-PUB-001',
            'period' => now()->startOfMonth()->format('Y-m-d'),
            'amount' => 150000,
            'due_date' => now()->addDays(7),
            'status' => 'unpaid',
            'generated_at' => now(),
        ]);

        $this->get(route('public.invoice.show', $invoice->uuid))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Public/Payment/Show')
                ->has('invoice')
                ->missing('invoice.id')
                ->missing('invoice.customer.address')
                ->missing('invoice.transactions')
                ->has('channels')
                ->has('company')
                ->has('manual_accounts'));
    }

    public function test_public_midtrans_pay_returns_json_redirect_for_browser_flow(): void
    {
        Http::fake([
            'https://app.sandbox.midtrans.com/snap/v1/transactions' => Http::response([
                'token' => 'sandbox-token',
                'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/sandbox-token',
            ], 200),
        ]);
        Setting::set('midtrans_client_key', 'SB-Mid-client-test', 'text', 'payment', 'Midtrans Client Key');
        Setting::set('midtrans_server_key', 'SB-Mid-server-test', 'text', 'payment', 'Midtrans Server Key');
        Setting::set('control_plane.payment_writes_enabled', '1', 'boolean', 'control_plane', 'Payment write');
        Setting::set('midtrans_is_production', '0', 'boolean', 'payment', 'Midtrans production mode');

        $area = Area::create(['code' => 'PAY', 'name' => 'Payment Area']);
        $package = Package::create(['code' => 'PAY', 'name' => 'Payment Package', 'price' => 100000]);
        $customer = Customer::create([
            'code' => 'PAY001', 'name' => 'Pelanggan Payment', 'phone' => '081234567890',
            'address' => 'Alamat Test', 'area_id' => $area->id, 'package_id' => $package->id,
            'pppoe_user' => 'pay001', 'status' => 'active',
        ]);
        $invoice = Invoice::create([
            'uuid' => (string) Str::uuid(), 'customer_id' => $customer->id, 'code' => 'INV-PAY-001',
            'period' => now()->startOfMonth()->format('Y-m-d'), 'amount' => 100000,
            'due_date' => now()->addDays(7), 'status' => 'unpaid', 'generated_at' => now(),
        ]);

        $this->postJson(route('public.invoice.pay', $invoice->uuid), ['method' => 'midtrans'])
            ->assertOk()
            ->assertJsonPath('token', 'sandbox-token')
            ->assertJsonPath('redirect_url', 'https://app.sandbox.midtrans.com/snap/v4/redirection/sandbox-token')
            ->assertJsonPath('payment_link', route('public.invoice.show', $invoice->uuid));

        $this->assertSame(route('public.invoice.show', $invoice->uuid), $invoice->fresh()->payment_link);
        Http::assertSent(function ($request) use ($invoice) {
            return $request->data()['callbacks']['finish'] === route('public.invoice.show', $invoice->uuid).'?payment_result=finish'
                && ! array_key_exists('enabled_payments', $request->data());
        });

        $this->post(route('public.invoice.pay', $invoice->uuid), ['method' => 'midtrans'])
            ->assertRedirect('https://app.sandbox.midtrans.com/snap/v4/redirection/sandbox-token');
    }

    public function test_public_midtrans_pay_returns_safe_error_instead_of_provider_details(): void
    {
        Http::fake([
            'https://app.sandbox.midtrans.com/snap/v1/transactions' => Http::response([
                'error_messages' => ['Access denied due to unauthorized transaction'],
            ], 401),
        ]);
        Setting::set('midtrans_client_key', 'SB-Mid-client-test', 'text', 'payment', 'Midtrans Client Key');
        Setting::set('midtrans_server_key', 'SB-Mid-server-test', 'text', 'payment', 'Midtrans Server Key');
        Setting::set('control_plane.payment_writes_enabled', '1', 'boolean', 'control_plane', 'Payment write');
        Setting::set('midtrans_is_production', '0', 'boolean', 'payment', 'Midtrans production mode');

        $area = Area::create(['code' => 'ERR', 'name' => 'Error Area']);
        $package = Package::create(['code' => 'ERR', 'name' => 'Error Package', 'price' => 100000]);
        $customer = Customer::create([
            'code' => 'ERR001', 'name' => 'Pelanggan Error', 'address' => 'Alamat Test',
            'area_id' => $area->id, 'package_id' => $package->id, 'pppoe_user' => 'err001', 'status' => 'active',
        ]);
        $invoice = Invoice::create([
            'uuid' => (string) Str::uuid(), 'customer_id' => $customer->id, 'code' => 'INV-ERR-001',
            'period' => now()->startOfMonth()->format('Y-m-d'), 'amount' => 100000,
            'due_date' => now()->addDays(7), 'status' => 'unpaid', 'generated_at' => now(),
        ]);

        $this->postJson(route('public.invoice.pay', $invoice->uuid), ['method' => 'midtrans'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Pembayaran Midtrans belum siap. Coba lagi sebentar atau gunakan transfer bank.')
            ->assertJsonMissing(['error_messages' => ['Access denied due to unauthorized transaction']]);
    }
}
