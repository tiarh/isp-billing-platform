<?php

namespace Tests\Feature;

use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-core')]
class TicketOpenAssignedFilterTest extends TestCase
{
    use RefreshDatabase;

    private function ticket(User $admin, string $status): SupportTicket
    {
        return SupportTicket::create([
            'created_by' => $admin->id,
            'gangguan' => 'Gangguan '.$status,
            'status' => $status,
            'priority' => 'normal',
            'type' => 'incident',
        ]);
    }

    private function statuses(User $user, array $query): array
    {
        $response = $this->actingAs($user)->get(route('tickets.index', $query));
        $response->assertOk();

        return collect($response->viewData('page')['props']['tickets']['data'])->pluck('status')->sort()->values()->all();
    }

    public function test_open_assigned_filter_returns_only_open_and_assigned_active_tickets(): void
    {
        $admin = User::factory()->create(['role' => 'superadmin']);
        foreach (['open', 'assigned', 'in_progress', 'pending', 'resolved', 'closed'] as $status) {
            $this->ticket($admin, $status);
        }

        $this->assertSame(['assigned', 'open'], $this->statuses($admin, ['status' => 'open_assigned']));
    }

    public function test_open_assigned_filter_for_technician_only_returns_own_tickets(): void
    {
        $admin = User::factory()->create(['role' => 'superadmin']);
        $technician = User::factory()->create(['role' => 'teknisi']);
        $otherTechnician = User::factory()->create(['role' => 'teknisi']);
        foreach (['open', 'assigned', 'in_progress', 'resolved'] as $status) {
            $own = $this->ticket($admin, $status);
            $own->assigned_to = $technician->id;
            $own->save();
        }
        $foreign = $this->ticket($admin, 'assigned');
        $foreign->assigned_to = $otherTechnician->id;
        $foreign->save();
        $this->ticket($admin, 'open');

        $this->assertSame(['assigned', 'open'], $this->statuses($technician, ['status' => 'open_assigned']));
    }

    public function test_single_status_and_archive_filters_are_unchanged(): void
    {
        $admin = User::factory()->create(['role' => 'superadmin']);
        foreach (['open', 'assigned', 'in_progress', 'resolved', 'closed'] as $status) {
            $this->ticket($admin, $status);
        }

        $this->assertSame(['open'], $this->statuses($admin, ['status' => 'open']));
        $this->assertSame(['assigned'], $this->statuses($admin, ['status' => 'assigned']));
        $this->assertSame(['closed', 'resolved'], $this->statuses($admin, ['status' => 'archive']));
    }
}
