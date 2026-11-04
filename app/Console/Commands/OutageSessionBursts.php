<?php

namespace App\Console\Commands;

use App\Services\Outage\AreaPowerDetector;
use App\Services\Outage\SessionBurstDetector;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * PR-D: session bursts (PPPoE sessions of one router lost together) and area power groups (many ONUs of one area in
 * dying gasp together). DB only: reads pppoe_session_events / onu_state_events, writes only outage_signals rows of the
 * types session_burst and area_power. No device access, no queue, no WA, no ticket.
 *
 *   outage:session-bursts                              the last 15 minutes, writes (the scheduler)
 *   outage:session-bursts --dry-run                    the same, writes nothing
 *   outage:session-bursts --since=2026-10-19           read-only replay over stored history (never writes)
 *   outage:session-bursts --since=-9d --until=-1d --details
 */
class OutageSessionBursts extends Command
{
    protected $signature = 'outage:session-bursts
        {--dry-run : Detect and print, write nothing}
        {--since= : Replay from this time (YYYY-MM-DD[ HH:MM] UTC or -9d). Read only, never writes}
        {--until= : End of the replay (default now)}
        {--details : Print one line per burst / group (ids and counts only)}';

    protected $description = 'Detect PPPoE session bursts and area power groups from stored history (DB only)';

    public function handle(SessionBurstDetector $bursts, AreaPowerDetector $areas): int
    {
        $since = $this->time((string) $this->option('since'));
        $until = $this->time((string) $this->option('until')) ?? now()->utc();
        $dry = (bool) $this->option('dry-run');
        $details = (bool) $this->option('details');

        try {
            if ($since !== null) {
                $b = $bursts->detect($since, $until);
                $a = $areas->detect($since, $until);
                $b += ['created' => 0, 'updated' => 0, 'resolved' => 0, 'expired' => 0];
                $a += ['created' => 0, 'updated' => 0, 'resolved' => 0, 'expired' => 0];
                $this->line(sprintf('replay %s .. %s (read only)', $since->format('Y-m-d H:i'), $until->format('Y-m-d H:i')));
            } else {
                $b = $bursts->run(now(), $dry);
                $a = $areas->run(now(), $dry);
            }
        } catch (\Throwable $e) {
            Log::warning('[Outage] session bursts failed', ['exception_class' => $e::class]);
            $this->error('Failed: '.$e::class);

            return self::FAILURE;
        }

        $this->info(sprintf(
            'session_burst: %d found (excluded: kick_no_reason=%d ebilling_action=%d isolated=%d too_small=%d) created=%d updated=%d resolved=%d expired=%d%s',
            count($b['bursts']), $b['excluded']['kick_no_reason'], $b['excluded']['ebilling_action'], $b['excluded']['isolated'], $b['excluded']['too_small'],
            $b['created'], $b['updated'], $b['resolved'], $b['expired'], $b['truncated'] ? ' TRUNCATED(5000 rows)' : '',
        ));
        $this->info(sprintf(
            'area_power: %d found created=%d updated=%d resolved=%d expired=%d%s',
            count($a['groups']), $a['created'], $a['updated'], $a['resolved'], $a['expired'], $a['truncated'] ? ' TRUNCATED(5000 rows)' : '',
        ));
        if ($details) {
            foreach ($b['bursts'] as $x) {
                $this->line(sprintf(
                    'burst router=%d start=%s members=%d span_s=%d pon=%s onu_down_share=%s reconnected=%d p50_min=%s',
                    $x['router_id'], $x['start']->format('Y-m-d H:i:s'), $x['count'], $x['span_s'], $x['pon'] ?? 'multi',
                    $x['onu_down_share'] === null ? 'n/a' : (string) $x['onu_down_share'], $x['reconnected'], $x['reconnect_p50_min'] === null ? 'n/a' : (string) $x['reconnect_p50_min'],
                ));
            }
            foreach ($a['groups'] as $x) {
                $this->line(sprintf(
                    'area olt=%d area=%d start=%s onus=%d in_area=%d pons=%d',
                    $x['olt_id'], $x['area_id'], $x['start']->format('Y-m-d H:i:s'), $x['count'], $x['in_area'], $x['pon_count'],
                ));
            }
        }
        if ($since === null && ! $dry) {
            Log::info('outage:session-bursts executed', ['bursts' => count($b['bursts']), 'area_groups' => count($a['groups'])]);
        }

        return self::SUCCESS;
    }

    private function time(string $value): ?Carbon
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        try {
            if (preg_match('/^-(\d+)([dh])$/', $value, $m) === 1) {
                return $m[2] === 'd' ? now()->utc()->subDays((int) $m[1]) : now()->utc()->subHours((int) $m[1]);
            }

            return Carbon::parse($value, 'UTC')->utc();
        } catch (\Throwable) {
            return null;
        }
    }
}
