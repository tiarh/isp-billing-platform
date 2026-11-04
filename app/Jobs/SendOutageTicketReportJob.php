<?php

namespace App\Jobs;

use App\Console\Commands\ReportDailyWaCommand;
use App\Models\SupportTicket;
use App\Models\WaReportDelivery;
use App\Services\EvolutionApiService;
use App\Services\Report\ReportDeliveryLedger;
use App\Services\Report\ReportGroupRegistry;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * The ONE WA message of a manual Deteksi ticket: a text to the Skynet Report group(s) only (never a customer, a
 * technician, the ticket group or Alert Ops). Once per ticket and group through the report ledger
 * (report_key deteksi_ticket:{id}); an unclear delivery is marked unknown and never sent again by itself.
 * Own `wa` queue only; never the psb / migration / billing queues.
 */
class SendOutageTicketReportJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public int $uniqueFor = 600;

    public function __construct(public int $ticketId, public string $text)
    {
        $this->onQueue('wa');
    }

    public function uniqueId(): string
    {
        return 'deteksi_ticket:'.$this->ticketId;
    }

    public function handle(EvolutionApiService $evolution, ReportDeliveryLedger $ledger): void
    {
        $ticket = SupportTicket::query()->find($this->ticketId);
        if ($ticket === null || $this->text === '') {
            return;
        }

        $registry = new ReportGroupRegistry;
        $jids = $registry->sendableGroupJids();
        if ($jids === []) {
            return;
        }

        $channel = $registry->channel();
        $wa = $evolution->forChannel($channel);
        $key = 'deteksi_ticket:'.$ticket->id;
        $day = $ticket->created_at->copy()->timezone('Asia/Jakarta')->format('Y-m-d');

        foreach ($jids as $jid) {
            $row = $ledger->ensureRow($key, $day, $jid, $channel);
            if (! $ledger->claim($row, sha1($this->text), $channel)) {
                continue; // already sent / being sent / limit reached
            }
            try {
                $result = $wa->sendToGroup($jid, $this->text);
            } catch (\Throwable $e) {
                $result = ['success' => false, 'error' => $e->getMessage()];
            }

            if (! empty($result['success'])) {
                $ledger->markSent($row, isset($result['message_id']) ? (string) $result['message_id'] : null);
            } elseif (ReportDailyWaCommand::isDeliveryUnknown($result)) {
                $ledger->markUnknown($row, (string) ($result['error'] ?? 'unknown'));
            } else {
                $ledger->markFailed($row, (string) ($result['error'] ?? 'unknown'));
            }
            Log::info('[OutageManualTicket] report message', ['ticket_id' => $ticket->id, 'status' => WaReportDelivery::query()->whereKey($row->id)->value('status')]);
        }
    }
}
