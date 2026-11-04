<?php

namespace App\Console\Commands;

use App\Services\Monitoring\PppoeUsageRollup;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Rolls per-PPPoE-user Prometheus usage up into pppoe_usage_daily (WIB days).
 * Never auto-runs a backfill: --backfill-from is an explicit operator action.
 * Output is counts only (no usernames).
 */
class MonitoringPppoeUsageRollup extends Command
{
    protected $signature = 'monitoring:pppoe-usage-rollup
        {--finalize : Finalize yesterday (WIB) from a 24h window}
        {--finalize-date= : Finalize this WIB day (YYYY-MM-DD, must be before today)}
        {--backfill-from= : Finalize every day from this WIB date through yesterday, then roll up today (manual only)}
        {--router= : Only this router id}
        {--replace : Overwrite stored totals instead of keeping the larger value (use after a direction fix)}
        {--dry-run : Query Prometheus and count, but write nothing}';

    /** Pause between router queries during a manual backfill (shared breaker/Prometheus). */
    private const BACKFILL_PAUSE_MS = 500;

    protected $description = 'Roll up daily PPPoE usage per user from Prometheus into pppoe_usage_daily';

    public function handle(PppoeUsageRollup $rollup): int
    {
        $now = CarbonImmutable::now(PppoeUsageRollup::TZ);
        $today = $rollup->dayStart($now);
        $dryRun = (bool) $this->option('dry-run');
        $replace = (bool) $this->option('replace');
        $router = $this->option('router') !== null && $this->option('router') !== '' ? (int) $this->option('router') : null;

        // One lock for regular, --finalize and backfill runs (separate scheduler
        // mutexes would let a slow finalize overlap the next run).
        $lock = Cache::lock('monitoring:pppoe-usage-rollup', 3600);
        if (! $lock->get()) {
            $this->warn('Rollup lain sedang berjalan; dilewati.');
            Log::info('[PppoeUsageRollup] skipped: lock held');

            return self::SUCCESS;
        }

        try {
            try {
                // Planned under the lock: self-heal bookkeeping must not be spent on a skipped run.
                $days = $this->plan($rollup, $now, $today);
            } catch (\InvalidArgumentException $e) {
                $this->error($e->getMessage());

                return self::INVALID;
            }

            return $this->runPlan($rollup, $now, $days, $dryRun, $replace, $router);
        } finally {
            $lock->release();
        }
    }

    /** @param  list<array{0: CarbonImmutable, 1: bool, 2?: list<int>|null}>  $days */
    private function runPlan(PppoeUsageRollup $rollup, CarbonImmutable $now, array $days, bool $dryRun, bool $replace, ?int $router): int
    {
        $pause = $this->option('backfill-from') ? self::BACKFILL_PAUSE_MS : 0;

        foreach ($days as $entry) {
            [$day, $final] = $entry;
            $stats = $rollup->rollupDay($day, $now, $final, $dryRun, $replace, $router, $entry[2] ?? null, $pause);
            $this->line(sprintf(
                '%s%s %s routers_ok=%d routers_failed=%d rows=%d linked=%d unlinked=%d',
                $day->format('Y-m-d'),
                $final ? ' final' : '',
                $stats['status'],
                $stats['routers_ok'],
                $stats['routers_failed'],
                $stats['rows'],
                $stats['linked'],
                $stats['unlinked'],
            ));
            if ($stats['status'] === 'skipped_breaker' || $stats['status'] === 'skipped_unconfigured') {
                $this->warn('Grafana tidak tersedia; run dihentikan tanpa menulis data lanjutan.');

                return self::SUCCESS;
            }
        }

        try {
            $purged = $rollup->purge($now, $dryRun);
            $this->line(($dryRun ? 'purge_candidates=' : 'purged=').$purged);
        } catch (Throwable) {
            $this->warn('purge gagal; dicoba lagi di run berikutnya.');
        }

        return self::SUCCESS;
    }

    /** @return list<array{0: CarbonImmutable, 1: bool, 2?: list<int>|null}> */
    private function plan(PppoeUsageRollup $rollup, CarbonImmutable $now, CarbonImmutable $today): array
    {
        $yesterday = $today->subDay();

        $backfill = $this->option('backfill-from');
        if ($backfill !== null && $backfill !== '') {
            $from = $this->parseDate((string) $backfill);
            if ($from->greaterThan($yesterday)) {
                throw new \InvalidArgumentException('--backfill-from must be before today (WIB).');
            }
            $this->assertWithinBounds($from, $today);
            $plan = [];
            for ($d = $from; $d->lessThanOrEqualTo($yesterday); $d = $d->addDay()) {
                $plan[] = [$d, true, null];
            }
            $plan[] = [$today, false, null];

            return $plan;
        }

        $finalizeDate = $this->option('finalize-date');
        if ($finalizeDate !== null && $finalizeDate !== '') {
            $day = $this->parseDate((string) $finalizeDate);
            if ($day->greaterThan($yesterday)) {
                throw new \InvalidArgumentException('--finalize-date must be before today (WIB).');
            }
            $this->assertWithinBounds($day, $today);

            return [[$day, true]];
        }

        if ($this->option('finalize')) {
            return [[$yesterday, true]];
        }

        // Regular 15-minute run. Self-heal: if the 00:07 finalize was skipped
        // (gate closed, Grafana down), finalize yesterday first.
        // Bounded (see PppoeUsageRollup::pendingFinalizeRouters) and limited to
        // the routers that still have pending rows.
        $plan = [];
        $pending = $this->option('dry-run') ? [] : $rollup->pendingFinalizeRouters($now);
        if ($pending !== []) {
            $plan[] = [$yesterday, true, $pending];
        }
        $plan[] = [$today, false, null];

        return $plan;
    }

    private function assertWithinBounds(CarbonImmutable $date, CarbonImmutable $today): void
    {
        $oldest = max(
            PppoeUsageRollup::MIN_BACKFILL_DATE,
            $today->subDays(PppoeUsageRollup::PROMETHEUS_RETENTION_DAYS)->format('Y-m-d'),
        );
        if ($date->format('Y-m-d') < $oldest) {
            throw new \InvalidArgumentException('Date is older than the allowed history (oldest '.$oldest.').');
        }
    }

    private function parseDate(string $value): CarbonImmutable
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new \InvalidArgumentException('Date must be YYYY-MM-DD.');
        }
        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value, PppoeUsageRollup::TZ)->startOfDay();
        } catch (Throwable) {
            throw new \InvalidArgumentException('Date must be YYYY-MM-DD.');
        }
    }
}
