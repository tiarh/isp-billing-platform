<?php

namespace App\Services\Outage;

use App\Models\Router;
use App\Services\Grafana\GrafanaClient;
use App\Services\Monitoring\PppoeCustomerMatcher;
use App\Services\Monitoring\PppoeUsageRollup;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Reads PPPoE sessions per router from Prometheus (skynet_pppoe_session, 1 sample per minute, read-only
 * query_range through the Grafana proxy) and stores only the gaps: a session that disappeared and came back
 * (pppoe_session_presence). Prometheus keeps ~40 days, this keeps 400.
 *
 * Real series carry no `user` label: the PPPoE name is the `interface` label as `<pppoe-NAME>`; `status="down"`
 * series exist with value 0 and count as absent.
 *
 * Failed router polls: when the exporter cannot poll a router, skynet_scrape_up{router_id} is 0 (or missing) and
 * EVERY session of that router is missing for that minute. That is not a drop. Per router-day the minutes with
 * scrape_up != 1 are "unknown": a gap lying only in unknown minutes is not stored, a gap that partly overlaps
 * them is stored with uncertain = 1, and the unknown minutes themselves are saved in pppoe_presence_runs
 * (unknown_minutes, unknown_ranges) so the analysis can leave those windows out.
 *
 * One unit of work = one router x one WIB day, read in 1 hour chunks (60 points x up to ~1100 sessions; the
 * scheduler container has a 128M PHP limit) and accumulated as one 1440 character minute bitmap per session.
 * It is written only when every chunk answered, then marked in pppoe_presence_runs. A re-run is idempotent and a
 * backfill can be stopped and resumed. The PPPoE name is mapped to customers.id at once and dropped; only a keyed
 * hash is kept so unmapped (or since ended) sessions can still be told apart. Names are never logged.
 *
 * Gap rules (step 60 s): minutes between two present minutes of a session are the gap, down_at = first absent
 * minute, up_at = next present minute. A session still gone at the end of the day is stored open (up_at NULL)
 * and closed by a later run when it shows up again, but only when every day in between was read completely.
 */
class PppoePresenceArchiver
{
    public const TZ = 'Asia/Jakarta';

    public const STEP = 60;

    public const DAY_MINUTES = 1440;

    /** 1 hour per query: 60 points x up to ~1100 series. */
    public const CHUNK_SECONDS = 3600;

    public const QUERY_TIMEOUT = 30;

    /** Prometheus holds nothing older than this WIB day (lowest sample 2026-10-02 20:15 UTC). */
    public const MIN_DATE = '2026-10-02';

    public const EDGE_ROUTER_ID = 900;

    /** Safety cap of open gaps examined per router and run. */
    private const MAX_OPEN = 20000;

    public function __construct(
        private readonly GrafanaClient $grafana,
        private readonly PppoeCustomerMatcher $matcher,
    ) {}

    /**
     * Archive one complete WIB day.
     *
     * status is one of: done, failed, partial, skipped_open_day, skipped_unconfigured, skipped_breaker,
     * skipped_tables, skipped_old.
     *
     * @return array{status: string, routers_ok: int, routers_failed: int, routers_skipped: int, series: int, gaps: int, linked: int, unknown_minutes: int}
     */
    public function archiveDay(
        CarbonImmutable $day,
        CarbonInterface $now,
        ?int $onlyRouter = null,
        int $pauseMs = 0,
        bool $dryRun = false,
        bool $force = false,
    ): array {
        $day = $day->setTimezone(self::TZ)->startOfDay();
        $stats = ['status' => 'done', 'routers_ok' => 0, 'routers_failed' => 0, 'routers_skipped' => 0, 'series' => 0, 'gaps' => 0, 'linked' => 0, 'unknown_minutes' => 0];

        if (! Schema::hasTable('pppoe_session_presence') || ! Schema::hasTable('pppoe_presence_runs')) {
            $stats['status'] = 'skipped_tables';

            return $stats;
        }
        if ($day->format('Y-m-d') < self::MIN_DATE) {
            $stats['status'] = 'skipped_old';

            return $stats;
        }
        $dayEnd = $day->addDay();
        if (CarbonImmutable::instance($now)->lessThan($dayEnd)) {
            $stats['status'] = 'skipped_open_day';

            return $stats;
        }
        if (! $this->grafana->isConfigured()) {
            $stats['status'] = 'skipped_unconfigured';

            return $stats;
        }

        $date = $day->format('Y-m-d');
        $first = true;
        foreach ($this->routerIds($onlyRouter, $date) as $routerId) {
            if (! $force && $this->isDone($routerId, $date)) {
                $stats['routers_skipped']++;

                continue;
            }
            if ($this->grafana->breakerIsOpen()) {
                $stats['status'] = 'skipped_breaker';

                break;
            }
            if (! $first && $pauseMs > 0) {
                usleep($pauseMs * 1000);
            }
            $first = false;

            $data = $this->fetchRouterDay($routerId, $day, $pauseMs);
            if ($data === null) {
                $stats['routers_failed']++;

                continue;
            }
            $stats['routers_ok']++;
            $users = $data['users'];
            $known = $data['known'];
            $unknownRanges = self::unknownRanges($known);
            $unknownMinutes = self::DAY_MINUTES - substr_count($known, '1');
            $stats['series'] += count($users);
            $stats['unknown_minutes'] += $unknownMinutes;

            $rows = $this->gapRows($routerId, $users, $known, $day->getTimestamp());
            $stats['gaps'] += count($rows);
            foreach ($rows as $r) {
                if ($r['customer_id'] !== null) {
                    $stats['linked']++;
                }
            }
            if ($dryRun) {
                continue;
            }
            $this->store($rows);
            $this->closeOpenGaps($routerId, $users, $known, $day);
            // An empty answer (no sessions although the router was polled) is not final: it may be an exporter
            // hole, so the day is retried. A day without any successful poll is final (nothing to learn).
            DB::table('pppoe_presence_runs')->upsert(
                [[
                    'router_id' => $routerId,
                    'day_wib' => $date,
                    'series' => count($users),
                    'gaps' => count($rows),
                    'unknown_minutes' => $unknownMinutes,
                    'unknown_ranges' => json_encode($unknownRanges),
                    'finished_at' => now()->utc()->format('Y-m-d H:i:s'),
                ]],
                ['router_id', 'day_wib'],
                ['series', 'gaps', 'unknown_minutes', 'unknown_ranges', 'finished_at'],
            );
        }

        if ($stats['status'] === 'done' && $stats['routers_failed'] > 0) {
            $stats['status'] = $stats['routers_ok'] === 0 ? 'failed' : 'partial';
        }
        Log::info('[PppoePresenceArchiver] day', ['date' => $date, 'dry_run' => $dryRun] + $stats);

        return $stats;
    }

    /** A router-day counts as done when it was read completely and had sessions, or no successful poll at all. */
    public function isDone(int $routerId, string $date): bool
    {
        return DB::table('pppoe_presence_runs')
            ->where('router_id', $routerId)
            ->where('day_wib', $date)
            ->where(function ($q): void {
                $q->where('series', '>', 0)->orWhere('unknown_minutes', '>=', self::DAY_MINUTES);
            })
            ->exists();
    }

    /**
     * Gaps of one session from its minute bitmap ('1' = present). Returns [down_minute, up_minute|null, uncertain]
     * as minute offsets from 00:00 WIB (up null = still gone at the end of the day). Minutes before the first
     * sample are not a gap. $known has '1' for minutes in which the router was polled successfully; a gap
     * without any known minute is dropped, a gap partly in unknown minutes is flagged uncertain. Without
     * $known every minute counts as known.
     *
     * @return list<array{0:int, 1:?int, 2:bool}>
     */
    public static function gapsOf(string $bits, ?string $known = null): array
    {
        $known ??= str_repeat('1', strlen($bits));
        $gaps = [];
        $prev = strpos($bits, '1');
        if ($prev === false) {
            return [];
        }
        while (($next = strpos($bits, '1', $prev + 1)) !== false) {
            if ($next - $prev > 1) {
                $unc = self::uncertain($known, $prev + 1, $next);
                if ($unc !== null) {
                    $gaps[] = [$prev + 1, $next, $unc];
                }
            }
            $prev = $next;
        }
        $len = strlen($bits);
        if ($prev < $len - 1) {
            $unc = self::uncertain($known, $prev + 1, $len);
            if ($unc !== null) {
                $gaps[] = [$prev + 1, null, $unc];
            }
        }

        return $gaps;
    }

    /** null = no known minute inside [a, b): drop; otherwise true when some minutes are unknown. */
    private static function uncertain(string $known, int $a, int $b): ?bool
    {
        $knownMissing = substr_count($known, '1', $a, $b - $a);
        if ($knownMissing === 0) {
            return null;
        }

        return $knownMissing < ($b - $a);
    }

    /**
     * Unknown windows as [start_minute, end_minute) offsets.
     *
     * @return list<array{0:int, 1:int}>
     */
    public static function unknownRanges(string $known): array
    {
        $out = [];
        $len = strlen($known);
        $pos = 0;
        while ($pos < $len && ($start = strpos($known, '0', $pos)) !== false) {
            $end = strpos($known, '1', $start);
            $end = $end === false ? $len : $end;
            $out[] = [$start, $end];
            $pos = $end;
        }

        return $out;
    }

    /** Keyed hash of a PPPoE name (case-insensitive). Derived key: rotating APP_KEY breaks continuity of hashes. */
    public static function userHash(string $user): string
    {
        $key = hash_hmac('sha256', 'pppoe-presence-v1', (string) config('app.key'));

        return substr(hash_hmac('sha256', mb_strtolower(trim($user)), $key), 0, 16);
    }

    /**
     * @param  array<string, array{hash: string, customer_id: ?int, bits: string}>  $users
     * @return list<array<string, mixed>>
     */
    private function gapRows(int $routerId, array $users, string $known, int $dayStart): array
    {
        $rows = [];
        foreach ($users as $s) {
            foreach (self::gapsOf($s['bits'], $known) as [$down, $up, $unc]) {
                $rows[] = [
                    'router_id' => $routerId,
                    'customer_id' => $s['customer_id'],
                    'user_hash' => $s['hash'],
                    'down_at' => gmdate('Y-m-d H:i:s', $dayStart + $down * 60),
                    'up_at' => $up === null ? null : gmdate('Y-m-d H:i:s', $dayStart + $up * 60),
                    'minutes' => $up === null ? null : $up - $down,
                    'uncertain' => $unc ? 1 : 0,
                ];
            }
        }

        return $rows;
    }

    /**
     * Closed gaps are upserted (a re-run refreshes them); open gaps are only inserted, so a re-run of an old
     * day can never re-open a gap that a later day already closed.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function store(array $rows): void
    {
        $closed = array_values(array_filter($rows, fn (array $r) => $r['up_at'] !== null));
        $open = array_values(array_filter($rows, fn (array $r) => $r['up_at'] === null));
        foreach (array_chunk($closed, 500) as $chunk) {
            DB::table('pppoe_session_presence')->upsert($chunk, ['router_id', 'user_hash', 'down_at'], ['customer_id', 'up_at', 'minutes', 'uncertain']);
        }
        foreach (array_chunk($open, 500) as $chunk) {
            DB::table('pppoe_session_presence')->insertOrIgnore($chunk);
        }
    }

    /**
     * Sessions that were gone at the end of an earlier day and appear in this one: set up_at to their first
     * present minute of this day. Only when every day in between was read completely (otherwise the row stays
     * open); the row is flagged uncertain when an in-between day, or the start of this day before the session
     * came back, had unknown minutes.
     *
     * @param  array<string, array{hash: string, customer_id: ?int, bits: string}>  $users
     */
    private function closeOpenGaps(int $routerId, array $users, string $known, CarbonImmutable $day): void
    {
        $dayStart = $day->getTimestamp();
        $open = DB::table('pppoe_session_presence')
            ->where('router_id', $routerId)
            ->whereNull('up_at')
            ->where('down_at', '<', gmdate('Y-m-d H:i:s', $dayStart))
            ->limit(self::MAX_OPEN)
            ->get(['id', 'user_hash', 'down_at', 'uncertain']);
        if ($open->isEmpty()) {
            return;
        }

        $byHash = [];
        foreach ($users as $s) {
            $byHash[$s['hash']] = $s['bits'];
        }
        /** @var array<string, array{done: bool, unknown: bool}> $runs */
        $runs = [];
        $oldest = $open->min('down_at');
        $from = CarbonImmutable::createFromTimestampUTC((int) strtotime($oldest.' UTC'))->setTimezone(self::TZ)->startOfDay();
        foreach (DB::table('pppoe_presence_runs')->where('router_id', $routerId)
            ->where('day_wib', '>=', $from->format('Y-m-d'))->where('day_wib', '<', $day->format('Y-m-d'))
            ->get(['day_wib', 'series', 'unknown_minutes']) as $r) {
            $runs[substr((string) $r->day_wib, 0, 10)] = [
                'done' => (int) $r->series > 0 || (int) $r->unknown_minutes >= self::DAY_MINUTES,
                'unknown' => (int) $r->unknown_minutes > 0,
            ];
        }

        foreach ($open as $row) {
            $bits = $byHash[$row->user_hash] ?? null;
            if ($bits === null) {
                continue;
            }
            $k = strpos($bits, '1');
            if ($k === false) {
                continue;
            }
            $down = (int) strtotime($row->down_at.' UTC');
            $downDay = CarbonImmutable::createFromTimestampUTC($down)->setTimezone(self::TZ)->startOfDay();
            $uncertain = (bool) $row->uncertain || ($k > 0 && substr_count($known, '0', 0, $k) > 0);
            $complete = true;
            for ($d = $downDay->addDay(); $d->lessThan($day); $d = $d->addDay()) {
                $r = $runs[$d->format('Y-m-d')] ?? null;
                if ($r === null || ! $r['done']) {
                    $complete = false;

                    break;
                }
                $uncertain = $uncertain || $r['unknown'];
            }
            if (! $complete) {
                continue;
            }
            $up = $dayStart + $k * 60;
            DB::table('pppoe_session_presence')->where('id', $row->id)->whereNull('up_at')->update([
                'up_at' => gmdate('Y-m-d H:i:s', $up),
                'minutes' => max(0, intdiv($up - $down + 30, 60)),
                'uncertain' => $uncertain ? 1 : 0,
            ]);
        }
    }

    /**
     * Minute bitmaps of one router and WIB day, or null when any chunk failed.
     *
     * @return array{users: array<string, array{hash: string, customer_id: ?int, bits: string}>, known: string}|null
     */
    private function fetchRouterDay(int $routerId, CarbonImmutable $day, int $pauseMs): ?array
    {
        $dayStart = $day->getTimestamp();
        $dayEnd = $day->addDay()->getTimestamp();
        $empty = str_repeat('0', self::DAY_MINUTES);
        /** @var array<string, array{hash: string, customer_id: ?int, bits: string}> $users */
        $users = [];
        $known = $empty;

        $onSession = function (array $labels, array $times, array $values) use (&$users, $routerId, $dayStart, $empty): void {
            if (($labels['status'] ?? 'up') === 'down') {
                return;
            }
            $user = PppoeUsageRollup::normalizeUserLabel((string) ($labels['interface'] ?? $labels['user'] ?? $labels['name'] ?? ''));
            if ($user === '' || mb_strlen($user) > 128) {
                return;
            }
            $key = mb_strtolower($user);
            foreach ($values as $i => $v) {
                if (! is_numeric($v) || (float) $v <= 0 || ! is_finite((float) $v) || ! is_numeric($times[$i] ?? null)) {
                    continue;
                }
                $idx = intdiv(intdiv((int) $times[$i], 1000) - $dayStart, 60);
                if ($idx < 0 || $idx >= self::DAY_MINUTES) {
                    continue;
                }
                if (! isset($users[$key])) {
                    $users[$key] = [
                        'hash' => self::userHash($user),
                        'customer_id' => $this->matcher->resolve($routerId, $user),
                        'bits' => $empty,
                    ];
                }
                $users[$key]['bits'][$idx] = '1';
            }
        };
        $onPoll = function (array $labels, array $times, array $values) use (&$known, $dayStart): void {
            foreach ($values as $i => $v) {
                if (! is_numeric($v) || (float) $v !== 1.0 || ! is_numeric($times[$i] ?? null)) {
                    continue;
                }
                $idx = intdiv(intdiv((int) $times[$i], 1000) - $dayStart, 60);
                if ($idx >= 0 && $idx < self::DAY_MINUTES) {
                    $known[$idx] = '1';
                }
            }
        };

        for ($from = $dayStart; $from < $dayEnd; $from += self::CHUNK_SECONDS) {
            $to = min($from + self::CHUNK_SECONDS, $dayEnd) - self::STEP; // inclusive end: no sample twice
            foreach (['pppoe_session_presence' => $onSession, 'pppoe_scrape_up' => $onPoll] as $template => $each) {
                $result = $this->grafana->queryRangeBetween(
                    $template,
                    ['rid' => $routerId],
                    CarbonImmutable::createFromTimestampUTC($from),
                    CarbonImmutable::createFromTimestampUTC($to),
                    self::STEP,
                    self::QUERY_TIMEOUT,
                    $each,
                );
                if ($result['status'] !== 'ok') {
                    return null;
                }
            }
            if ($pauseMs > 0 && $from + self::CHUNK_SECONDS < $dayEnd) {
                usleep($pauseMs * 1000);
            }
        }

        return ['users' => $users, 'known' => $known];
    }

    /** @return list<int> */
    private function routerIds(?int $only, string $date): array
    {
        $query = Router::query()->where(function ($q): void {
            $q->where('is_active', true);
            if (Schema::hasColumn('routers', 'monitoring_enabled')) {
                $q->orWhere('monitoring_enabled', true);
            }
        })->where('id', '!=', self::EDGE_ROUTER_ID);
        if ($only !== null) {
            $query->where('id', $only);
        }
        $ids = $query->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();

        // Krian (router 15) data up to this day came from another device (CCR): not usable.
        if ($date <= PppoeUsageRollup::KRIAN_INVALID_THROUGH) {
            $ids = array_values(array_diff($ids, [PppoeUsageRollup::KRIAN_ROUTER_ID]));
        }

        return $ids;
    }
}
