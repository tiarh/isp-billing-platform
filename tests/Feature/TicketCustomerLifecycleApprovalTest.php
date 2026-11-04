<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Package;
use App\Models\SupportTicket;
use App\Models\TicketActivity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\StubsLifecycleGuard;
use Tests\TestCase;

class TicketCustomerLifecycleApprovalTest extends TestCase
{
    use RefreshDatabase;
    use StubsLifecycleGuard;

    public function test_lifecycle_request_is_pending_and_does_not_silent_update_customer(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = $this->customer(['status' => 'active']);
        $ticket = SupportTicket::create([
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'gangguan' => 'Minta isolir manual',
            'type' => 'request',
        ]);

        $this->actingAs($admin)
            ->post(route('tickets.customer-lifecycle.request', $ticket), [
                'lifecycle_action' => 'isolir',
                'reason' => 'Permintaan pelanggan via ticket',
            ])
            ->assertRedirect();

        $this->assertSame('active', $customer->fresh()->status);
        $this->assertSame(1, TicketActivity::where('action', 'customer_lifecycle_requested')->count());

        $this->actingAs($admin)
            ->put(route('tickets.update', $ticket), ['status' => 'closed'])
            ->assertRedirect();

        $this->assertSame('active', $customer->fresh()->status);
    }

    public function test_lifecycle_approval_updates_customer_once_and_is_idempotent(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = $this->customer(['status' => 'active']);
        $ticket = SupportTicket::create([
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'gangguan' => 'Pelanggan isolir',
            'type' => 'request',
        ]);

        $this->actingAs($admin)->post(route('tickets.customer-lifecycle.request', $ticket), [
            'lifecycle_action' => 'isolir',
            'reason' => 'Sudah disetujui NOC',
        ]);
        $activity = TicketActivity::where('action', 'customer_lifecycle_requested')->firstOrFail();

        $this->actingAs($admin)
            ->post(route('tickets.customer-lifecycle.approve', [$ticket, $activity]))
            ->assertRedirect();
        $this->actingAs($admin)
            ->post(route('tickets.customer-lifecycle.approve', [$ticket, $activity]))
            ->assertRedirect();

        $this->assertSame('isolated', $customer->fresh()->status);
        $this->assertSame(1, TicketActivity::where('action', 'customer_lifecycle_applied')->count());
    }

    public function test_dismantle_requires_resolved_ticket_and_uses_terminated_status(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = $this->customer(['status' => 'isolated']);
        $ticket = SupportTicket::create([
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'gangguan' => 'Dismantle pelanggan',
            'type' => 'request',
            'status' => 'open',
        ]);

        $this->actingAs($admin)->post(route('tickets.customer-lifecycle.request', $ticket), [
            'lifecycle_action' => 'dismantle',
            'reason' => 'Perangkat sudah ditarik',
        ]);
        $activity = TicketActivity::where('action', 'customer_lifecycle_requested')->firstOrFail();

        $this->actingAs($admin)
            ->post(route('tickets.customer-lifecycle.approve', [$ticket, $activity]))
            ->assertSessionHasErrors('lifecycle');
        $this->assertSame('isolated', $customer->fresh()->status);

        $ticket->update(['status' => 'closed']);
        $this->allowCustomerLifecycleGuard();
        $this->actingAs($admin)
            ->post(route('tickets.customer-lifecycle.approve', [$ticket, $activity]))
            ->assertRedirect();

        $this->assertSame('terminated', $customer->fresh()->status);
    }

    private function customer(array $attributes = []): Customer
    {
        $package = Package::create(['code' => 'LFC', 'name' => 'Lifecycle', 'price' => 100000]);

        return Customer::create(array_merge([
            'code' => 'C-LFC-'.str_pad((string) (Customer::count() + 1), 3, '0', STR_PAD_LEFT),
            'name' => 'Pelanggan Lifecycle',
            'phone' => '080000003',
            'address' => 'RW 4 Randuagung',
            'package_id' => $package->id,
            'pppoe_user' => 'lfc'.(Customer::count() + 1),
            'status' => 'active',
            'odp_name' => 'ODP RW4',
        ], $attributes));
    }
}
