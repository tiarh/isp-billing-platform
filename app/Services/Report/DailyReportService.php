<?php

namespace App\Services\Report;

use App\Services\PsbAreaRecapService;
use App\Services\Recap\MigrationRecapService;
use App\Services\Recap\OnuHealthService;
use App\Services\Recap\RecapRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Builds the numbers of the daily Skynet Report (read-only; no HTTP, no writes).
 * Each section has its own try/catch: one failing section never blocks the other.
 */
class DailyReportService
{
    public function __construct(
        private readonly PsbAreaRecapService $psb,
        private readonly MigrationDailyStats $migration,
    ) {}

    /**
     * @param  CarbonImmutable  $day  the WIB calendar day to report (time of day is ignored)
     * @return array{psb: ?array<string, mixed>, psb_mtd: ?array<string, mixed>, migration: ?array<string, mixed>, errors: list<string>}
     */
    public function build(CarbonImmutable $day): array
    {
        return $this->assemble($day, null);
    }

    /**
     * Same as build(), with the "Draft PSB dan kendala" section forced on (true) or off (false), whatever the flag
     * says. Used by the dry-run option --psb-detail to preview the section before the flag is switched on.
     *
     * @return array<string, mixed>
     */
    public function buildWithDetail(CarbonImmutable $day, bool $detail = true): array
    {
        return $this->assemble($day, $detail);
    }

    /**
     * @return array<string, mixed>
     */
    private function assemble(CarbonImmutable $day, ?bool $forceDetail): array
    {
        $day = $day->timezone('Asia/Jakarta')->startOfDay();
        $out = ['psb' => null, 'psb_mtd' => null, 'migration' => null, 'errors' => []];

        try {
            // Identical to "#rekap psb semua <D>".
            $out['psb'] = $this->psb->collect($this->request($day, $day), null, 'SEMUA AREA');
        } catch (\Throwable $e) {
            $out['errors'][] = 'psb:'.$e::class;
            Log::warning('[REPORT-DAILY] psb section failed', ['exception_class' => $e::class]);
        }

        // Month to date (1st of the reported month .. D). On the 1st the reported day is the
        // last day of the previous month, so this covers that whole month.
        try {
            $from = $day->startOfMonth();
            $mtd = $this->psb->collect($this->request($from, $day), null, 'SEMUA AREA');
            $out['psb_mtd'] = ['from' => $from->toDateString(), 'to' => $day->toDateString(), 'total' => (int) $mtd['total']];
        } catch (\Throwable $e) {
            $out['errors'][] = 'psb_mtd:'.$e::class;
            Log::warning('[REPORT-DAILY] psb month section failed', ['exception_class' => $e::class]);
        }

        try {
            $out['migration'] = $this->migration->collect($day);
        } catch (\Throwable $e) {
            $out['errors'][] = 'migration:'.$e::class;
            Log::warning('[REPORT-DAILY] migration section failed', ['exception_class' => $e::class]);
        }

        // "Draft PSB dan kendala": own flag (default OFF) or the dry-run option --psb-detail. With neither, the
        // 'psb_detail' key does not exist, so the report is byte-identical to before. Counts only, read-only.
        $registry = app(ReportGroupRegistry::class);
        if ($forceDetail === true || ($forceDetail === null && $registry->psbDetailDailyEnabled())) {
            try {
                $out['psb_detail'] = app(PsbDailyDetailStats::class)->collect($day, null, $registry->psbStuckDays());
            } catch (\Throwable $e) {
                $out['psb_detail'] = null;
                $out['errors'][] = 'psb_detail:'.$e::class;
                Log::warning('[REPORT-DAILY] psb detail section failed', ['exception_class' => $e::class]);
            }
        }

        // Migration auto-reconcile details (own flag, default OFF): "status DB a + otomatis b", ONU already
        // online, perlu dicek admin. Same read-only evaluator as the auto-reconcile job. With the flag OFF the
        // 'extra' key does not exist, so the section is byte-identical to before. A failure keeps the plain section.
        if ($out['migration'] !== null && app(ReportGroupRegistry::class)->migrationEvidenceEnabled()) {
            try {
                $out['migration']['extra'] = app(MigrationRecapService::class)->dailyExtra($day);
            } catch (\Throwable $e) {
                $out['errors'][] = 'migration_extra:'.$e::class;
                Log::warning('[REPORT-DAILY] migration evidence failed', ['exception_class' => $e::class]);
            }
        }

        // "Kesehatan ONU (posisi ...)": current snapshot, own flag (default OFF). With the flag OFF
        // the 'onu' key does not exist at all, so the report is byte-identical to before.
        if (app(ReportGroupRegistry::class)->onuDailyEnabled()) {
            try {
                $out['onu'] = app(OnuHealthService::class)->collect(null, 'SEMUA AREA');
            } catch (\Throwable $e) {
                $out['onu'] = null;
                $out['errors'][] = 'onu:'.$e::class;
                Log::warning('[REPORT-DAILY] onu section failed', ['exception_class' => $e::class]);
            }
        }

        return $out;
    }

    /**
     * Owner rule: nothing at all happened (PSB 0, migration selesai 0, migration masuk 0).
     * Only provable when both sections were read successfully.
     *
     * @param  array{psb: ?array<string, mixed>, migration: ?array<string, mixed>}  $report
     */
    public function isZeroDay(array $report): bool
    {
        if ($report['psb'] === null || $report['migration'] === null) {
            return false;
        }

        return (int) $report['psb']['total'] === 0
            && (int) $report['migration']['selesai']['total'] === 0
            && (int) $report['migration']['masuk'] === 0;
    }

    public function bothFailed(array $report): bool
    {
        return $report['psb'] === null && $report['migration'] === null;
    }

    private function request(CarbonImmutable $from, CarbonImmutable $to): RecapRequest
    {
        return new RecapRequest('psb', 'semua', true, $from, $to, true, false, 92);
    }
}
