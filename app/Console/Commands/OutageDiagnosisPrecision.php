<?php

namespace App\Console\Commands;

use App\Services\Outage\Diagnosis\PrecisionReport;
use App\Services\Report\RootCauseWeeklyWa;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * RCA-4: weekly precision of the diagnosis per class (first prediction of an incident ticket vs the cause the
 * technician picked at close). Stores the result in outage_diagnosis_reports (card "Ketepatan diagnosa" in
 * Monitoring > Deteksi Gangguan). The WA summary only goes out when diagnosis_weekly_wa_enabled = 1 (default OFF).
 * DB only. Never fails the scheduler.
 */
class OutageDiagnosisPrecision extends Command
{
    protected $signature = 'outage:diagnosis-precision {--week= : Monday (WIB, Y-m-d) of the week; default = the last full week} {--dry-run : Print, store nothing}';

    protected $description = 'Weekly precision / recall of the outage diagnosis per class (RCA-4)';

    /** Stored diagnoses feed the precision history, so they are kept a year. */
    private const RETENTION_DAYS = 365;

    private function pruneOld(): int
    {
        if (! Schema::hasTable('outage_diagnoses')) {
            return 0;
        }
        $cutoff = now()->subDays(self::RETENTION_DAYS)->format('Y-m-d H:i:s');
        $deleted = 0;
        while (($ids = DB::table('outage_diagnoses')->where('created_at', '<', $cutoff)->orderBy('id')->limit(1000)->pluck('id')->all()) !== []) {
            $deleted += DB::table('outage_diagnoses')->whereIn('id', $ids)->delete();
        }

        return $deleted;
    }

    public function handle(PrecisionReport $report, RootCauseWeeklyWa $rootCauseWa): int
    {
        try {
            $weekOpt = (string) $this->option('week');
            $week = $weekOpt !== ''
                ? Carbon::parse($weekOpt, 'Asia/Jakarta')
                : now()->timezone('Asia/Jakarta')->subWeek();
            $start = PrecisionReport::weekStart($week);

            if ($this->option('dry-run')) {
                $payload = $report->compute($start);
                $this->line('--- Teks WA mingguan "Penyebab tiket gangguan" (tidak dikirim) ---');
                $this->line((string) $report->rootCauseText($payload));
                $this->line('--- Data ---');
                $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

                return self::SUCCESS;
            }

            $payload = $report->store($start);
            $this->info('week '.$payload['week_start'].': '.$payload['evaluated'].' evaluated, '.$payload['correct'].' correct, '.$payload['closed_auto'].' closed without cause');
            $this->line('weekly WA: '.$report->sendSummary($payload));
            try {
                $this->line('weekly root cause WA: '.implode(', ', $rootCauseWa->send($payload)));
            } catch (\Throwable $e) {
                // never lets the prune below be skipped
                Log::warning('[Outage] weekly root cause WA failed', ['exception_class' => $e::class]);
                $this->warn('weekly root cause WA failed: '.$e::class);
            }
            $this->line('pruned '.$this->pruneOld().' diagnosis row(s) older than '.self::RETENTION_DAYS.' days');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            try {
                Log::warning('[Outage] outage:diagnosis-precision failed', ['exception_class' => $e::class]);
            } catch (\Throwable) {
            }
            $this->warn('outage:diagnosis-precision failed: '.$e::class);

            return self::SUCCESS;
        }
    }
}
