<?php

namespace App\Console\Commands;

use App\Services\Outage\OutageSettings;
use App\Services\Outage\Outcome\OutcomeLabeler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Hourly: labels tickets and episodes that closed at least 2 hours ago with what the stored data says fixed them
 * (RX jump, serial swap, unisolate, co-recovery, power only) and finalises the labels at +26 h. DB only: indexed reads,
 * writes only outage_outcome_labels, at most 500 subjects per run. Setting diagnosis_outcome_labels_enabled = 0 stops it.
 * Never fails the scheduler; nothing is queued, no device, no WA.
 */
class OutageOutcomeLabelCommand extends Command
{
    protected $signature = 'outage:outcome-label {--max=500 : Most subjects per run} {--slice=3 : Hours of close time per pass (3 = one skipped hourly run is caught up; raise to catch up after longer downtime)}';

    protected $description = 'Outcome labels of closed incidents from stored data (RX jump, serial swap, unisolate, co-recovery)';

    public function handle(OutcomeLabeler $labeler): int
    {
        try {
            if (! OutageSettings::diagnosisOutcomeLabelsEnabled()) {
                $this->line('outcome labels are off (diagnosis_outcome_labels_enabled = 0)');

                return self::SUCCESS;
            }
            $max = max(1, min(500, (int) $this->option('max')));
            $slice = max(1, min(168, (int) $this->option('slice') ?: OutcomeLabeler::SLICE_HOURS));
            $r = $labeler->run($max, $slice);
            $this->info("outcome labels: scanned {$r['scanned']}, written {$r['written']}, final {$r['final']}, removed {$r['removed']}");
        } catch (\Throwable $e) {
            try {
                Log::warning('[Outage] outage:outcome-label failed', ['exception_class' => $e::class]);
            } catch (\Throwable) {
            }
            $this->warn('outage:outcome-label failed: '.$e::class);
        }

        return self::SUCCESS;
    }
}
