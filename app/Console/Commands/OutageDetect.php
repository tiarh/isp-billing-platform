<?php

namespace App\Console\Commands;

use App\Services\Outage\OutageDetector;
use App\Services\Outage\OutageSettings;
use App\Services\Outage\OutageShadowNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Roadmap 2.4 PR-2: shadow outage detector. Reads only collected tables, writes only outage_* rows.
 * Never fails the scheduler: every error is swallowed into a log line and exit code 0 (except --dry-run).
 */
class OutageDetect extends Command
{
    protected $signature = 'outage:detect {--dry-run : Compute and print, write nothing (rolled back)} {--json : JSON output}';

    protected $description = 'Outage detector (shadow mode: records only, no tickets, no customer WA)';

    public function handle(OutageDetector $detector, OutageShadowNotifier $notifier): int
    {
        $dry = (bool) $this->option('dry-run');

        try {
            if (! $dry && ! OutageSettings::enabled()) {
                $this->line('outage_automation_enabled is off: nothing to do.');

                return self::SUCCESS;
            }

            $summary = $detector->runSafely(null, $dry);
            if (isset($summary['error'])) {
                $this->warn('Detector failed: '.$summary['error']);

                return $dry ? self::FAILURE : self::SUCCESS;
            }

            if (! $dry) {
                $notifier->send($summary);
            }

            if ($this->option('json')) {
                $this->line((string) json_encode($summary, JSON_UNESCAPED_SLASHES));
            } else {
                $this->printSummary($summary, $dry);
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            try {
                Log::warning('[Outage] outage:detect failed', ['exception_class' => $e::class]);
            } catch (\Throwable) {
            }
            $this->warn('outage:detect failed: '.$e::class);

            return $dry ? self::FAILURE : self::SUCCESS;
        }
    }

    /** @param array<string,mixed> $s */
    private function printSummary(array $s, bool $dry): void
    {
        $this->line(($dry ? 'DRY RUN (rolled back) ' : '').'opened='.$s['opened'].' confirmed='.$s['confirmed'].' resolved='.$s['resolved'].' expired='.$s['expired']);
        foreach ((array) $s['olts'] as $id => $o) {
            $this->line('  OLT '.$id.': '.($o['status'] ?? '?').(isset($o['onus']) ? ' onus='.$o['onus'] : ''));
        }
        if (! empty($s['suppressed'])) {
            $this->line('  suppressed: '.json_encode($s['suppressed']));
        }
    }
}
