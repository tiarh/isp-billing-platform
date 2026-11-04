<?php

namespace Tests\Feature;

use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-core')]
class TicketAdminGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_teknisi_cannot_assign_ticket(): void
    {
        $teknisi = User::factory()->create(['role' => 'teknisi']);
        $admin = User::factory()->create(['role' => 'admin']);
        $ticket = SupportTicket::create([
            'gangguan' => 'Internet mati',
            'status' => 'open',
            'priority' => 'normal',
            'type' => 'incident',
            'created_by' => $admin->id,
        ]);

        $this->actingAs($teknisi)
            ->post(route('tickets.assign', $ticket), ['assigned_to' => $teknisi->id])
            ->assertForbidden();
    }

    public function test_teknisi_cannot_destroy_ticket(): void
    {
        $teknisi = User::factory()->create(['role' => 'teknisi']);
        $admin = User::factory()->create(['role' => 'admin']);
        $ticket = SupportTicket::create([
            'gangguan' => 'Internet mati',
            'status' => 'open',
            'priority' => 'normal',
            'type' => 'incident',
            'created_by' => $admin->id,
        ]);

        $this->actingAs($teknisi)
            ->delete(route('tickets.destroy', $ticket))
            ->assertForbidden();

        $this->assertDatabaseHas('support_tickets', ['id' => $ticket->id]);
    }

    public function test_teknisi_cannot_update_or_comment_on_ticket_not_assigned_to_them(): void
    {
        $teknisi = User::factory()->create(['role' => 'teknisi']);
        $other = User::factory()->create(['role' => 'teknisi']);
        $admin = User::factory()->create(['role' => 'admin']);
        $ticket = SupportTicket::create([
            'gangguan' => 'Internet mati',
            'status' => 'open',
            'priority' => 'normal',
            'type' => 'incident',
            'created_by' => $admin->id,
            'assigned_to' => $other->id,
        ]);

        // Regression: update() lacked the assignee check that edit() and
        // technicianUpdate() already had, so any teknisi could close/rewrite
        // any ticket (AreaScope is a no-op for the teknisi role).
        $this->actingAs($teknisi)
            ->put(route('tickets.update', $ticket), ['status' => 'closed', 'gangguan' => 'diubah'])
            ->assertForbidden();

        $this->actingAs($teknisi)
            ->post(route('tickets.comment', $ticket), ['body' => 'komentar liar'])
            ->assertForbidden();

        $this->assertDatabaseHas('support_tickets', ['id' => $ticket->id, 'status' => 'open', 'gangguan' => 'Internet mati']);
        $this->assertDatabaseMissing('ticket_comments', ['ticket_id' => $ticket->id]);
    }

    public function test_assigned_teknisi_can_still_comment_on_their_ticket(): void
    {
        $teknisi = User::factory()->create(['role' => 'teknisi']);
        $admin = User::factory()->create(['role' => 'admin']);
        $ticket = SupportTicket::create([
            'gangguan' => 'Internet mati',
            'status' => 'open',
            'priority' => 'normal',
            'type' => 'incident',
            'created_by' => $admin->id,
            'assigned_to' => $teknisi->id,
        ]);

        $this->actingAs($teknisi)
            ->post(route('tickets.comment', $ticket), ['body' => 'sedang menuju lokasi'])
            ->assertRedirect();

        $this->assertDatabaseHas('ticket_comments', ['ticket_id' => $ticket->id, 'user_id' => $teknisi->id]);
    }

    public function test_admin_can_assign_ticket(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $teknisi = User::factory()->create(['role' => 'teknisi']);
        $ticket = SupportTicket::create([
            'gangguan' => 'Internet mati',
            'status' => 'open',
            'priority' => 'normal',
            'type' => 'incident',
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->post(route('tickets.assign', $ticket), ['assigned_to' => $teknisi->id])
            ->assertRedirect();

        $this->assertDatabaseHas('support_tickets', [
            'id' => $ticket->id,
            'assigned_to' => $teknisi->id,
            'status' => 'assigned',
        ]);
    }
}
