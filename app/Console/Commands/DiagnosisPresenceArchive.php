<?php

namespace App\Console\Commands;

use App\Services\Outage\PppoePresenceArchiver;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Nightly PPPoE presence archive (Prometheus skynet_pppoe_session -> pppoe_session_presence, read only).
 *
 *   default                      the last --catch-up complete WIB days (2) that are not done yet
 *   --backfill-from=2026-10-02   every complete day from that date up to yesterday, oldest first;
 *                                idempotent and resumable (finished router-days are skipped)
 *   --date=2026-10-20            one day (with --force: read again even if done)
 *   --router=15                  one router only
 *   --pause-ms=250               pause between queries (rate limit)
 *   --budget-minutes=20          stop cleanly after this long; run again to continue
 *   --dry-run                    read Prometheus and count, write nothing
 */
class DiagnosisPresenceArchive extends Command
{
    protected $signature = 'diagnosis:presence-archive
        {--backfill-from= : First WIB day (YYYY-MM-DD) of a backfill, up to yesterday}
        {--date= : One WIB day (YYYY-MM-DD)}
        {--catch-up=2 : Complete days (up to yesterday) checked by the nightly run}
        {--router= : Only this router id}
        {--pause-ms=250 : Pause between queries in milliseconds}
        {--budget-minutes=0 : Stop after this many minutes (0 = no limit)}
        {--force : Read days again even if finished}
        {--dry-run : Read and count only}';

    protected $description = 'Archive PPPoE session gaps from Prometheus into pppoe_session_presence (read only, resumable)';

    public function handle(PppoePresenceArchiver $archiver): int
    {
        // The scheduler container runs PHP with 128M; the biggest router (~1100 sessions) needs headroom.
        @ini_set('memory_limit', '256M');

        $now = CarbonImmutable::now();
        $yesterday = $now->setTimezone(PppoePresenceArchiver::TZ)->startOfDay()->subDay();
        $dry = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $router = $this->option('router') !== null && $this->option('router') !== '' ? (int) $this->option('router') : null;
        $pause = max(0, min(5000, (int) $this->option('pause-ms')));
        $budget = max(0, (int) $this->option('budget-minutes'));

        $days = $this->days($yesterday);
        if ($days === null) {
            return self::FAILURE;
        }

        $lock = Cache::lock('diagnosis:presence-archive', 2 * 3600);
        if (! $lock->get()) {
            $this->warn('Another presence archive run is active.');

            return self::SUCCESS;
        }

        try {
            $started = microtime(true);
            foreach ($days as $day) {
                if ($budget > 0 && (microtime(true) - $started) > $budget * 60) {
                    $this->warn('Time budget reached; run again to continue.');

                    break;
                }
                $stats = $archiver->archiveDay($day, $now, $router, $pause, $dry, $force);
                $this->line(sprintf(
                    '%s %s routers ok=%d failed=%d skipped=%d series=%d gaps=%d linked=%d unknown_min=%d',
                    $day->format('Y-m-d'),
                    $stats['status'],
                    $stats['routers_ok'],
                    $stats['routers_failed'],
                    $stats['routers_skipped'],
                    $stats['series'],
                    $stats['gaps'],
                    $stats['linked'],
                    $stats['unknown_minutes'],
                ));
                if (in_array($stats['status'], ['skipped_unconfigured', 'skipped_breaker', 'skipped_tables'], true)) {
                    $this->warn('Stopped: Prometheus not usable now. Run again later; finished days are kept.');

                    break;
                }
            }
        } finally {
            $lock->release();
        }

        // Soft failure: a Prometheus problem must not make the scheduler job fail; the next run catches up.
        return self::SUCCESS;
    }

    /** @return list<CarbonImmutable>|null */
    private function days(CarbonImmutable $yesterday): ?array
    {
        $tz = PppoePresenceArchiver::TZ;
        $min = CarbonImmutable::parse(PppoePresenceArchiver::MIN_DATE, $tz)->startOfDay();

        $single = $this->option('date');
        if ($single !== null && $single !== '') {
            $day = $this->parseDay((string) $single);
            if ($day === null) {
                $this->error('--date must be YYYY-MM-DD.');

                return null;
            }

            return [$day];
        }

        $from = $this->option('backfill-from');
        if ($from !== null && $from !== '') {
            $start = $this->parseDay((string) $from);
            if ($start === null) {
                $this->error('--backfill-from must be YYYY-MM-DD.');

                return null;
            }
            $start = $start->lessThan($min) ? $min : $start;
        } else {
            $n = max(1, min(30, (int) $this->option('catch-up')));
            $start = $yesterday->subDays($n - 1);
            $start = $start->lessThan($min) ? $min : $start;
        }

        $days = [];
        for ($d = $start; $d->lessThanOrEqualTo($yesterday); $d = $d->addDay()) {
            $days[] = $d;
        }

        return $days;
    }

    private function parseDay(string $value): ?CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }
        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value, PppoePresenceArchiver::TZ) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }
}
