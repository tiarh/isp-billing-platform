<?php

namespace App\Jobs;

use App\Models\SupportTicket;
use App\Services\EvolutionApiService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SendWaNotificationJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public string $ticketCode;

    public string $type;

    public string $oldStatus;

    public string $newStatus;

    /**
     * One attempt only: every Evolution call already catches its own errors,
     * so a retry would only follow a worker kill mid-send — exactly the case
     * where the group message may already have been delivered.
     */
    public int $tries = 1;

    /** Must exceed the Evolution send timeout (45s read + 5s connect). */
    public int $timeout = 75;

    public function __construct(
        string $ticketCode,
        string $type = 'assignment',
        string $oldStatus = '',
        string $newStatus = ''
    ) {
        $this->ticketCode = $ticketCode;
        $this->type = $type;
        $this->oldStatus = $oldStatus;
        $this->newStatus = $newStatus;
        $this->onQueue('wa');
    }

    public function handle(): void
    {
        $ticket = SupportTicket::where('code', $this->ticketCode)->first();
        if (! $ticket) {
            Log::warning("[SendWaNotificationJob] Ticket not found: {$this->ticketCode}");

            return;
        }

        $evo = app(EvolutionApiService::class);

        if ($this->type === 'status_change') {
            $evo->sendTicketStatusChangeToGroup($ticket, $this->oldStatus, $this->newStatus);
        } elseif ($this->type === 'closed') {
            $evo->sendTicketClosedToGroup($ticket);
            // Second message in its own job so a failure/kill here can never
            // re-send the first one.
            self::dispatch($this->ticketCode, 'closed_report');
        } elseif ($this->type === 'closed_report') {
            $evo->sendTicketClosedReportToGroup($ticket);
        } else {
            $evo->sendTicketAssignment($ticket);
        }
    }
}
