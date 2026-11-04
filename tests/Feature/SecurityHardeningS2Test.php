<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\PaymentGatewayEvent;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SecurityHardeningS2Test extends TestCase
{
    use RefreshDatabase;

    public function test_payment_gateway_event_api_returns_projection_without_raw_payload(): void
    {
        $invoice = $this->invoice();
        PaymentGatewayEvent::create([
            'provider' => 'midtrans',
            'external_id' => 'evt-s2-1',
            'reference' => $invoice->code,
            'invoice_id' => $invoice->id,
            'amount' => $invoice->amount,
            'status' => 'settlement',
            'payload' => ['server_key' => 'must-not-leak', 'customer_phone' => '081234567890'],
        ]);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->getJson(route('api.payment-gateway.events', ['limit' => 1000]))
            ->assertOk()
            ->assertJsonPath('per_page', 100)
            ->assertJsonMissing(['payload' => ['server_key' => 'must-not-leak']])
            ->assertJsonMissing(['customer_phone' => '081234567890'])
            ->assertJsonPath('data.0.provider', 'midtrans');
    }

    public function test_scoped_admin_only_sees_payment_events_for_its_area(): void
    {
        $ownArea = Area::create(['code' => 'EVT-OWN', 'name' => 'Event Own']);
        $otherArea = Area::create(['code' => 'EVT-OTHER', 'name' => 'Event Other']);
        $package = Package::create(['code' => 'EVT-PKG', 'name' => 'Event Package', 'price' => 100000]);
        $ownCustomer = Customer::create([
            'code' => 'EVT-OWN-C',
            'name' => 'Event Own Customer',
            'phone' => '081234567890',
            'address' => 'Event own address',
            'area_id' => $ownArea->id,
            'package_id' => $package->id,
            'pppoe_user' => 'evt-own-c',
            'status' => 'active',
        ]);
        $otherCustomer = Customer::create([
            'code' => 'EVT-OTHER-C',
            'name' => 'Event Other Customer',
            'phone' => '081234567891',
            'address' => 'Event other address',
            'area_id' => $otherArea->id,
            'package_id' => $package->id,
            'pppoe_user' => 'evt-other-c',
            'status' => 'active',
        ]);
        $ownInvoice = $this->makeInvoice($ownCustomer, 'INV-EVT-OWN');
        $otherInvoice = $this->makeInvoice($otherCustomer, 'INV-EVT-OTHER');
        PaymentGatewayEvent::create([
            'provider' => 'midtrans',
            'external_id' => 'evt-own',
            'reference' => $ownInvoice->code,
            'invoice_id' => $ownInvoice->id,
            'amount' => $ownInvoice->amount,
            'status' => 'settlement',
            'payload' => ['safe' => true],
        ]);
        PaymentGatewayEvent::create([
            'provider' => 'midtrans',
            'external_id' => 'evt-other',
            'reference' => $otherInvoice->code,
            'invoice_id' => $otherInvoice->id,
            'amount' => $otherInvoice->amount,
            'status' => 'settlement',
            'payload' => ['safe' => true],
        ]);
        $admin = User::factory()->create(['role' => 'admin']);
        $admin->areas()->attach($ownArea);

        $this->actingAs($admin)
            ->getJson(route('api.payment-gateway.events'))
            ->assertOk()
            ->assertJsonFragment(['external_id' => 'evt-own'])
            ->assertJsonMissing(['external_id' => 'evt-other']);
    }

    public function test_scoped_admin_cannot_refresh_router_fleet(): void
    {
        $area = Area::create(['code' => 'MON-S2', 'name' => 'Monitoring S2']);
        $admin = User::factory()->create(['role' => 'admin']);
        $admin->areas()->attach($area);

        $this->actingAs($admin)
            ->postJson(route('monitoring.routers.refresh'))
            ->assertForbidden();
    }

    public function test_support_ticket_pagination_is_capped(): void
    {
        SupportTicket::create(['subject' => 'S2 ticket']);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->getJson(route('api.support-tickets.index', ['limit' => 1000]))
            ->assertOk()
            ->assertJsonPath('per_page', 100);
    }

    private function invoice(): Invoice
    {
        $area = Area::create(['code' => 'PAY-S2', 'name' => 'Payment S2']);
        $package = Package::create(['code' => 'PAY-S2', 'name' => 'Payment S2', 'price' => 100000]);
        $customer = Customer::create([
            'code' => 'PAY-S2-C',
            'name' => 'Payment S2 Customer',
            'phone' => '081234567890',
            'address' => 'S2 address',
            'area_id' => $area->id,
            'package_id' => $package->id,
            'pppoe_user' => 'pay-s2-c',
            'status' => 'active',
        ]);

        return Invoice::create([
            'uuid' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'code' => 'INV-PAY-S2',
            'period' => now()->startOfMonth()->format('Y-m-d'),
            'amount' => 100000,
            'due_date' => now()->addDays(7),
            'status' => 'unpaid',
            'generated_at' => now(),
        ]);
    }

    private function makeInvoice(Customer $customer, string $code): Invoice
    {
        return Invoice::create([
            'uuid' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'code' => $code,
            'period' => now()->startOfMonth()->format('Y-m-d'),
            'amount' => 100000,
            'due_date' => now()->addDays(7),
            'status' => 'unpaid',
            'generated_at' => now(),
        ]);
    }
}
