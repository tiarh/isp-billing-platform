<?php

namespace App\Console\Commands;

use App\Models\SupportTicket;
use App\Support\DismantleReason;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Classify existing Dismantle tickets that have no reason yet, from their
 * subject keywords. Dry-run unless --apply; idempotent (only rows with
 * dismantle_reason IS NULL are touched, so re-runs are no-ops).
 */
class BackfillDismantleReasons extends Command
{
    protected $signature = 'tickets:backfill-dismantle-reason
        {--apply : Write the reasons (default is a dry-run that writes nothing)}
        {--dry-run : Preview only (default)}
        {--limit=0 : Process at most N tickets (0 = all)}';

    protected $description = 'Backfill support_tickets.dismantle_reason for existing Dismantle tickets from subject keywords (dry-run by default)';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply') && ! (bool) $this->option('dry-run');
        $limit = max(0, (int) $this->option('limit'));

        $query = SupportTicket::query()
            ->whereNull('dismantle_reason')
            ->where(fn ($q) => $q->where('type', 'dismantle')
                ->orWhereRaw('LOWER(subject) LIKE ?', ['%dismantl%']))
            ->orderBy('id');
        if ($limit > 0) {
            $query->limit($limit);
        }

        $tickets = $query->get();
        $counts = array_fill_keys(DismantleReason::codes(), 0);
        $rows = [];
        $written = 0;

        foreach ($tickets as $ticket) {
            $classified = DismantleReason::classifySubject($ticket->subject ?: $ticket->gangguan);
            $counts[$classified['reason']]++;
            $rows[] = [
                $ticket->id,
                $ticket->code,
                $ticket->type,
                mb_strimwidth((string) $ticket->subject, 0, 60, '…'),
                $classified['reason'],
                $classified['note'] === null ? '' : mb_strimwidth($classified['note'], 0, 40, '…'),
            ];

            if ($apply) {
                DB::transaction(function () use ($ticket, $classified, &$written): void {
                    // Conditional update: never overwrite a reason set in
                    // the meantime (idempotent).
                    $updated = SupportTicket::query()
                        ->whereKey($ticket->id)
                        ->whereNull('dismantle_reason')
                        ->update([
                            'dismantle_reason' => $classified['reason'],
                            'dismantle_reason_note' => $classified['note'],
                        ]);
                    if ($updated === 1) {
                        $written++;
                        DismantleReason::recordChange($ticket->fresh(['customer']), null, null, null, 'backfill');
                    }
                });
            }
        }

        if ($rows !== []) {
            $this->table(['id', 'code', 'type', 'subject', 'reason', 'note'], $rows);
        }
        $this->table(['reason', 'label', 'count'], array_map(
            fn (string $code): array => [$code, DismantleReason::label($code), $counts[$code]],
            DismantleReason::codes(),
        ));

        $this->info($apply
            ? sprintf('APPLIED: %d of %d dismantle ticket(s) without reason updated.', $written, count($rows))
            : sprintf('DRY-RUN: %d dismantle ticket(s) without reason would be updated (re-run with --apply to write). Nothing was written.', count($rows)));

        return self::SUCCESS;
    }
}
