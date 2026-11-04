<?php

namespace Tests\Feature;

use App\Models\SupportTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketWorkflowSlaTest extends TestCase
{
    use RefreshDatabase;

    public function test_ticket_gets_default_sla_from_priority_when_due_at_empty(): void
    {
        $base = now();
        $this->travelTo($base);

        $urgent = SupportTicket::create(['gangguan' => 'Internet mati total', 'priority' => 'urgent']);
        $high = SupportTicket::create(['gangguan' => 'Redaman jelek', 'priority' => 'high']);
        $normal = SupportTicket::create(['gangguan' => 'Lambat', 'priority' => 'normal']);
        $low = SupportTicket::create(['gangguan' => 'Request info', 'priority' => 'low']);

        $this->assertSame($base->copy()->addHours(4)->timestamp, $urgent->due_at->timestamp);
        $this->assertSame($base->copy()->addHours(12)->timestamp, $high->due_at->timestamp);
        $this->assertSame($base->copy()->addHours(24)->timestamp, $normal->due_at->timestamp);
        $this->assertSame($base->copy()->addHours(72)->timestamp, $low->due_at->timestamp);
        $this->assertSame('Mendekati SLA', $urgent->sla_label);
    }

    public function test_resolved_ticket_sla_is_done_even_when_due_at_is_past(): void
    {
        $ticket = SupportTicket::create([
            'gangguan' => 'Sudah selesai',
            'priority' => 'urgent',
            'status' => 'resolved',
            'due_at' => now()->subHour(),
        ]);

        $this->assertSame('done', $ticket->sla_status);
        $this->assertSame('Selesai', $ticket->sla_label);
    }
}
