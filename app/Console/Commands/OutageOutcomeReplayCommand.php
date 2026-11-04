<?php

namespace App\Console\Commands;

use App\Services\Outage\Outcome\OutcomeLabeler;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Read-only report: for subjects that closed in a date range, how many outcome labels the current labeler would write
 * per signature, against what is stored. Counts only, nothing is written, no device, no WA, no queue. Use it on real
 * history before diagnosis_calibrated_labels_enabled goes ON.
 *
 * Approximate: the live RX reading is today's, so "final read missing" means still missing now (not at the first final
 * pass), and "dropped" counts every rx_jump removed because the ONU was online at the open, the correct episode drops too.
 */
class OutageOutcomeReplayCommand extends Command
{
    protected $signature = 'outage:outcome-replay {--from= : First close date (YYYY-MM-DD, WIB). Default 14 days ago} {--to= : Last close date (YYYY-MM-DD, WIB). Default 2 days ago} {--max=2000 : Most subjects to replay}';

    protected $description = 'Read-only: labels the current labeler would write per signature vs the stored ones (counts only)';

    public function handle(OutcomeLabeler $labeler): int
    {
        try {
            $from = $this->date((string) $this->option('from'), now()->subDays(14))->startOfDay();
            $to = $this->date((string) $this->option('to'), now()->subDays(2))->endOfDay();
        } catch (\Throwable) {
            $this->error('Bad date, use YYYY-MM-DD');

            return self::FAILURE;
        }
        if (! OutcomeLabeler::ready()) {
            $this->error('outage_outcome_labels is missing (migration not run)');

            return self::FAILURE;
        }
        $max = max(1, min(5000, (int) $this->option('max')));
        $r = $labeler->replay($from, $to, $max);

        $this->info('outcome replay (read-only), closed '.$from->format('Y-m-d').' .. '.$to->format('Y-m-d').' WIB');
        $this->line('subjects: '.$r['subjects'].($r['capped'] ? ' (capped at --max, narrow the range)' : ''));
        $rows = [];
        foreach (array_unique(array_merge(array_keys($r['would']), array_keys($r['stored']))) as $signature) {
            $rows[] = [$signature, $r['would'][$signature] ?? 0, $r['stored'][$signature] ?? 0];
        }
        $this->table(['signature', 'would write', 'stored'], $rows);
        $this->line('final read missing (no next daily point, no live reading): '.$r['rx_missing']);
        $this->line('rx_jump dropped, ONU already online at open: '.$r['healthy_dropped']);

        return self::SUCCESS;
    }

    private function date(string $value, Carbon $default): Carbon
    {
        return $value === '' ? $default->copy()->timezone('Asia/Jakarta') : Carbon::createFromFormat('!Y-m-d', $value, 'Asia/Jakarta')->setTimezone('Asia/Jakarta');
    }
}
