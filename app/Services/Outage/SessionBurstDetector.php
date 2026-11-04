<?php

namespace App\Services\Outage;

use App\Enums\CustomerStatus;
use App\Models\Customer;
use App\Models\OutageSignal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PR-D D1: PPPoE session bursts. Many customers of ONE router losing their session within a minute while their ONUs
 * stay online is a path / router event the ONU layer cannot see. DB only: reads pppoe_session_events (syslog lines, the
 * `at` index, at most MAX_ROWS), customers, onu_state_events, activity_log (by subject) and the billing ledger; writes
 * only outage_signals rows of type session_burst. No device access, no queue, no WA, no ticket.
 *
 * Burst = at least MIN_CUSTOMERS distinct customers disconnected within WINDOW_SECONDS on one router (overlapping
 * windows are one burst). Not a burst:
 *  - kick_no_reason     80 % or more of the lines have no reason and the whole span is at most 10 s (an admin / isolir kick);
 *  - isolated           members whose billing status is isolated / suspended / terminated are not counted;
 *  - ebilling_action    members eBilling itself kicked or changed within 3 minutes (isolate / reconnect log, profile or
 *                       status change, enforcement ledger row): the 00:00 isolation batches and admin mass restores;
 *  - too_small          fewer than MIN_CUSTOMERS are left after that.
 * The idea (a count over a sliding window, grouped by one key, with the recovery tracked afterwards) follows the burst
 * rules of NTT's BGPalerter and the RADIUS-accounting "stop burst" checks used by ISP NOCs.
 */
class SessionBurstDetector
{
    public const MIN_CUSTOMERS = 8;

    public const WINDOW_SECONDS = 60;

    public const LOOKBACK_MINUTES = 15;

    public const MAX_ROWS = 5000;

    public const MAX_IDS = 300;

    public const KICK_NO_REASON_SHARE = 0.8;

    public const KICK_SPAN_SECONDS = 10;

    public const OWN_ACTION_SECONDS = 180;

    public const ONU_WINDOW_MINUTES = 7;

    /** The same burst seen again (start within this many minutes) is the same row. */
    public const SAME_BURST_MINUTES = 5;

    /** An open burst older than this joins no new cluster and is closed. */
    public const OPEN_HOURS = 2;

    public const EXPIRE_HOURS = 3;

    /** Share of members that reconnected which resolves a burst. */
    public const RESOLVE_SHARE = 0.5;

    /** @var list<string> */
    private const OWN_ACTIONS = ['customer_isolated', 'customer_reconnected'];

    /**
     * Detect and write. $dryRun reads only.
     *
     * @return array{bursts:list<array<string,mixed>>,excluded:array<string,int>,created:int,updated:int,resolved:int,expired:int,truncated:bool}
     */
    public function run(?Carbon $now = null, bool $dryRun = false): array
    {
        $now = ($now ?? now())->copy()->utc();
        $out = $this->detect($now->copy()->subMinutes(self::LOOKBACK_MINUTES), $now);
        $stats = ['created' => 0, 'updated' => 0, 'resolved' => 0, 'expired' => 0];
        if (! $dryRun) {
            foreach ($out['bursts'] as $burst) {
                $stats[$this->persist($burst, $now)]++;
            }
            [$stats['resolved'], $stats['expired']] = $this->closeOpen($now);
        }

        return $out + $stats;
    }

    /**
     * Pure read of [from, to]: used by run() and by the read-only replay (--since / --until). A long range is read in
     * one hour slices, each at most MAX_ROWS lines.
     *
     * @return array{bursts:list<array<string,mixed>>,excluded:array<string,int>,truncated:bool}
     */
    public function detect(Carbon $from, Carbon $to): array
    {
        $bursts = [];
        $excluded = ['kick_no_reason' => 0, 'ebilling_action' => 0, 'isolated' => 0, 'too_small' => 0];
        $truncated = false;
        if (! Schema::hasTable('pppoe_session_events')) {
            return ['bursts' => [], 'excluded' => $excluded, 'truncated' => false];
        }
        $cursor = $from->copy()->utc();
        $end = $to->copy()->utc();
        while ($cursor->lt($end)) {
            $sliceEnd = $cursor->copy()->addHour()->min($end);
            $rows = DB::table('pppoe_session_events')->where('event', 'disconnected')->where('source', 'syslog')->whereNotNull('customer_id')
                ->where('at', '>=', $cursor->format('Y-m-d H:i:s'))->where('at', '<=', $sliceEnd->format('Y-m-d H:i:s'))
                ->orderBy('at')->limit(self::MAX_ROWS)->get(['router_id', 'customer_id', 'reason', 'at']);
            $truncated = $truncated || $rows->count() >= self::MAX_ROWS;
            $byRouter = [];
            foreach ($rows as $r) {
                $byRouter[(int) $r->router_id][] = ['c' => (int) $r->customer_id, 'reason' => trim((string) $r->reason), 't' => Carbon::parse($r->at, 'UTC')];
            }
            foreach ($byRouter as $routerId => $events) {
                foreach ($this->clusters($events) as $cluster) {
                    $burst = $this->evaluate($routerId, $cluster, $sliceEnd, $excluded);
                    if ($burst !== null) {
                        $bursts[] = $burst;
                    }
                }
            }
            $cursor = $sliceEnd->copy()->addSecond();
        }

        return ['bursts' => $bursts, 'excluded' => $excluded, 'truncated' => $truncated];
    }

    /**
     * Sliding window over the time sorted lines: every start whose next WINDOW_SECONDS hold MIN_CUSTOMERS distinct
     * customers marks a range; overlapping ranges are merged into one cluster.
     *
     * @param  list<array{c:int,reason:string,t:Carbon}>  $events
     * @return list<list<array{c:int,reason:string,t:Carbon}>>
     */
    private function clusters(array $events): array
    {
        $n = count($events);
        if ($n < self::MIN_CUSTOMERS) {
            return [];
        }
        $ts = array_map(fn (array $e) => $e['t']->getTimestamp(), $events);
        $counts = [];
        $distinct = 0;
        $j = 0;
        $ranges = [];
        for ($i = 0; $i < $n; $i++) {
            while ($j < $n && $ts[$j] <= $ts[$i] + self::WINDOW_SECONDS) {
                $counts[$events[$j]['c']] = ($counts[$events[$j]['c']] ?? 0) + 1;
                if ($counts[$events[$j]['c']] === 1) {
                    $distinct++;
                }
                $j++;
            }
            if ($distinct >= self::MIN_CUSTOMERS) {
                $last = count($ranges) - 1;
                if ($last >= 0 && $i <= $ranges[$last][1]) {
                    $ranges[$last][1] = max($ranges[$last][1], $j - 1);
                } else {
                    $ranges[] = [$i, $j - 1];
                }
            }
            $counts[$events[$i]['c']]--;
            if ($counts[$events[$i]['c']] === 0) {
                $distinct--;
            }
        }

        return array_map(fn (array $r) => array_slice($events, $r[0], $r[1] - $r[0] + 1), $ranges);
    }

    /**
     * @param  list<array{c:int,reason:string,t:Carbon}>  $cluster
     * @param  array<string,int>  $excluded
     * @return array<string,mixed>|null
     */
    private function evaluate(int $routerId, array $cluster, Carbon $readTo, array &$excluded): ?array
    {
        $first = $cluster[0]['t'];
        $last = $cluster[count($cluster) - 1]['t'];
        $span = $last->getTimestamp() - $first->getTimestamp();
        $noReason = count(array_filter($cluster, fn (array $e) => $e['reason'] === ''));
        $noReasonShare = $noReason / count($cluster);
        if ($noReasonShare >= self::KICK_NO_REASON_SHARE && $span <= self::KICK_SPAN_SECONDS) {
            $excluded['kick_no_reason']++;

            return null;
        }

        // first line per customer
        $at = [];
        foreach ($cluster as $e) {
            $at[$e['c']] ??= $e['t'];
        }
        $ids = array_keys($at);

        $customers = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach (Customer::query()->whereIn('id', $chunk)->get(['id', 'status', 'olt_id', 'olt_port_label']) as $c) {
                $customers[(int) $c->id] = $c;
            }
        }
        $billing = [CustomerStatus::Isolated->value, CustomerStatus::Suspended->value, CustomerStatus::Terminated->value];
        $kept = [];
        $skipped = ['isolated' => 0, 'ebilling_action' => 0];
        // billing status first: the 00:00 isolation batch (hundreds of isolated customers) never reaches the activity_log read
        $candidates = [];
        foreach ($ids as $id) {
            $c = $customers[$id] ?? null;
            if ($c === null) {
                continue;
            }
            $status = $c->status instanceof \BackedEnum ? (string) $c->status->value : (string) $c->status;
            if (in_array($status, $billing, true)) {
                $skipped['isolated']++;

                continue;
            }
            $candidates[] = $id;
        }
        $own = $candidates === [] ? [] : $this->ownActions($candidates, $first, $last);
        foreach ($candidates as $id) {
            if ($this->hasOwnAction($own[$id] ?? [], $at[$id])) {
                $skipped['ebilling_action']++;

                continue;
            }
            $kept[] = $id;
        }
        if (count($kept) < self::MIN_CUSTOMERS) {
            // name the reason of the biggest loss, so the replay says WHY a burst did not count
            $reason = ($skipped['ebilling_action'] >= $skipped['isolated'] && $skipped['ebilling_action'] > 0) ? 'ebilling_action' : ($skipped['isolated'] > 0 ? 'isolated' : 'too_small');
            $excluded[$reason]++;

            return null;
        }

        usort($kept, fn (int $a, int $b) => $at[$a]->getTimestamp() <=> $at[$b]->getTimestamp());
        $start = $at[$kept[0]];
        $end = $at[$kept[count($kept) - 1]];

        // PON of the members: the dominant one (half or more), else "multi"
        $pons = [];
        $mapped = 0;
        foreach ($kept as $id) {
            $c = $customers[$id];
            $ref = $c->olt_id !== null && $c->olt_port_label !== null ? OutageDetector::normalizeRef((string) $c->olt_port_label) : null;
            $pon = $ref !== null ? OutageDetector::ponOf($ref) : null;
            if ($pon !== null) {
                $mapped++;
                $key = (int) $c->olt_id.'|'.$pon;
                $pons[$key] = ($pons[$key] ?? 0) + 1;
            }
        }
        arsort($pons);
        $oltId = null;
        $pon = null;
        if ($pons !== [] && (reset($pons) / count($kept)) >= 0.5) {
            [$o, $pon] = explode('|', (string) key($pons));
            $oltId = (int) $o;
        }

        $down = $mapped > 0 ? $this->onuDownCount($kept, $start, $end) : null;
        [$reconnected, $p50] = $this->reconnects($kept, $at, $readTo);

        return [
            'router_id' => $routerId,
            'start' => $start,
            'end' => $end,
            'span_s' => $end->getTimestamp() - $start->getTimestamp(),
            'count' => count($kept),
            'customer_ids' => array_slice($kept, 0, self::MAX_IDS),
            'olt_id' => $oltId,
            'pon' => $pon,
            'pon_count' => count($pons),
            'onu_mapped' => $mapped,
            'onu_down' => $down,
            'onu_down_share' => $down !== null && $mapped > 0 ? round($down / $mapped, 3) : null,
            'reconnected' => $reconnected,
            'reconnect_p50_min' => $p50,
            'no_reason_share' => round($noReasonShare, 3),
            'skipped' => $skipped,
        ];
    }

    /**
     * eBilling's own actions on these customers around the burst: activity_log rows of the isolate / reconnect calls and of
     * profile / status updates (read by subject, so the index is used), and the enforcement ledger. Times are UTC.
     *
     * @param  list<int>  $ids
     * @return array<int,list<Carbon>>
     */
    private function ownActions(array $ids, Carbon $first, Carbon $last): array
    {
        $out = [];
        $from = $first->copy()->subSeconds(self::OWN_ACTION_SECONDS)->format('Y-m-d H:i:s');
        $to = $last->copy()->addSeconds(self::OWN_ACTION_SECONDS)->format('Y-m-d H:i:s');
        foreach (array_chunk($ids, 300) as $chunk) {
            if (Schema::hasTable('activity_log')) {
                // Index shape (activity_log has ~1.1 M rows): the Spatie `subject` index (subject_type, subject_id) serves
                // "subject_type = ? AND subject_id IN (<= 300 ids)"; created_at only filters those few rows. Never a scan by
                // created_at or description. Bounded: 300 ids per read, LIMIT 3000. Pinned by an EXPLAIN test.
                $rows = DB::table('activity_log')->where('subject_type', Customer::class)->whereIn('subject_id', $chunk)
                    ->whereBetween('created_at', [$from, $to])->limit(3000)->get(['subject_id', 'description', 'properties', 'created_at']);
                foreach ($rows as $r) {
                    if (! in_array((string) $r->description, self::OWN_ACTIONS, true) && ! ($r->description === 'updated' && $this->changesProfileOrStatus((string) $r->properties))) {
                        continue;
                    }
                    $out[(int) $r->subject_id][] = Carbon::parse($r->created_at, 'UTC');
                }
            }
            if (Schema::hasTable('billing_enforcement_actions')) {
                $rows = DB::table('billing_enforcement_actions')->whereIn('customer_id', $chunk)
                    ->where(fn ($q) => $q->whereBetween('started_at', [$from, $to])->orWhereBetween('completed_at', [$from, $to]))
                    ->limit(3000)->get(['customer_id', 'started_at', 'completed_at']);
                foreach ($rows as $r) {
                    foreach (['started_at', 'completed_at'] as $col) {
                        if ($r->{$col} !== null) {
                            $out[(int) $r->customer_id][] = Carbon::parse($r->{$col}, 'UTC');
                        }
                    }
                }
            }
        }

        return $out;
    }

    private function changesProfileOrStatus(string $properties): bool
    {
        $p = json_decode($properties, true);
        if (! is_array($p)) {
            return false;
        }
        foreach (['attributes', 'old'] as $bag) {
            if (is_array($p[$bag] ?? null) && (array_key_exists('mikrotik_profile', $p[$bag]) || array_key_exists('status', $p[$bag]))) {
                return true;
            }
        }

        return false;
    }

    /** @param  list<Carbon>  $actions */
    private function hasOwnAction(array $actions, Carbon $disconnectAt): bool
    {
        foreach ($actions as $a) {
            if (abs($a->getTimestamp() - $disconnectAt->getTimestamp()) <= self::OWN_ACTION_SECONDS) {
                return true;
            }
        }

        return false;
    }

    /**
     * Members whose ONU went down (any non-online transition) within ONU_WINDOW_MINUTES of the burst. One indexed read
     * per 300 customers.
     *
     * @param  list<int>  $ids
     */
    private function onuDownCount(array $ids, Carbon $start, Carbon $end): int
    {
        if (! Schema::hasTable('onu_state_events')) {
            return 0;
        }
        $from = $start->copy()->subMinutes(self::ONU_WINDOW_MINUTES)->format('Y-m-d H:i:s');
        $to = $end->copy()->addMinutes(self::ONU_WINDOW_MINUTES)->format('Y-m-d H:i:s');
        $down = [];
        foreach (array_chunk($ids, 300) as $chunk) {
            foreach (DB::table('onu_state_events')->whereIn('customer_id', $chunk)->whereBetween('occurred_at', [$from, $to])
                ->where('to_phase', '<>', 'online')->distinct()->pluck('customer_id') as $cid) {
                $down[(int) $cid] = true;
            }
        }

        return count($down);
    }

    /**
     * How many members connected again after their own disconnect (up to $readTo) and the median minutes it took.
     *
     * @param  list<int>  $ids
     * @param  array<int,Carbon>  $at
     * @return array{0:int,1:?float}
     */
    private function reconnects(array $ids, array $at, Carbon $readTo): array
    {
        $first = null;
        foreach ($ids as $id) {
            if ($first === null || $at[$id]->lt($first)) {
                $first = $at[$id];
            }
        }
        if ($first === null) {
            return [0, null];
        }
        $minutes = [];
        foreach (array_chunk($ids, 300) as $chunk) {
            $rows = DB::table('pppoe_session_events')->whereIn('customer_id', $chunk)->where('event', 'connected')
                ->where('at', '>=', $first->format('Y-m-d H:i:s'))->where('at', '<=', $readTo->copy()->addHours(self::EXPIRE_HOURS)->format('Y-m-d H:i:s'))
                ->orderBy('at')->limit(self::MAX_ROWS)->get(['customer_id', 'at']);
            foreach ($rows as $r) {
                $cid = (int) $r->customer_id;
                $t = Carbon::parse($r->at, 'UTC');
                if (! isset($minutes[$cid]) && isset($at[$cid]) && $t->gt($at[$cid])) {
                    $minutes[$cid] = ($t->getTimestamp() - $at[$cid]->getTimestamp()) / 60;
                }
            }
        }
        if ($minutes === []) {
            return [0, null];
        }
        $vals = array_values($minutes);
        sort($vals);
        $mid = intdiv(count($vals), 2);
        $p50 = count($vals) % 2 === 1 ? $vals[$mid] : ($vals[$mid - 1] + $vals[$mid]) / 2;

        return [count($vals), round($p50, 1)];
    }

    // ------------------------------------------------------------------ write

    /** @param  array<string,mixed>  $b  @return 'created'|'updated' */
    private function persist(array $b, Carbon $now): string
    {
        /** @var Carbon $start */
        $start = $b['start'];
        $pon = $b['pon'] ?? 'multi';
        // the OLT is part of the scope: two OLTs behind one router can share a PON label
        $scope = 'router:'.$b['router_id'].':olt:'.($b['olt_id'] ?? 'x').':pon:'.$pon;
        $payload = [
            'router_id' => $b['router_id'], 'span_s' => $b['span_s'], 'pon_count' => $b['pon_count'], 'onu_mapped' => $b['onu_mapped'],
            'onu_down' => $b['onu_down'], 'onu_down_share' => $b['onu_down_share'], 'reconnected' => $b['reconnected'],
            'reconnect_p50_min' => $b['reconnect_p50_min'], 'no_reason_share' => $b['no_reason_share'], 'burst_start' => $start->toIso8601String(),
        ];

        // the same burst seen by an earlier run (any state): refresh it, never a second row
        $same = OutageSignal::query()->where('signal_type', 'session_burst')->where('scope_key', $scope)
            ->whereBetween('opened_at', [$start->copy()->subMinutes(self::SAME_BURST_MINUTES)->format('Y-m-d H:i:s'), $start->copy()->addMinutes(self::SAME_BURST_MINUTES)->format('Y-m-d H:i:s')])
            ->orderByDesc('id')->first();
        $open = $same ?? OutageSignal::query()->open()->where('scope_key', $scope)->first();
        if ($open !== null && $open->opened_at->lt($start->copy()->subHours(self::OPEN_HOURS)) && ! $open->isClosed()) {
            $this->close($open, OutageSignal::STATE_EXPIRED, $now);
            $open = null;
        }
        if ($open !== null) {
            if (! $open->isClosed()) {
                $ids = array_values(array_unique(array_merge((array) $open->affected_customer_ids, $b['customer_ids'])));
                $open->affected_customer_ids = array_slice($ids, 0, self::MAX_IDS);
                $open->affected_count = max((int) $open->affected_count, (int) $b['count']);
                $open->payload = $payload + ['count' => $open->affected_count];
                $open->last_seen_at = $now;
                $open->observations = (int) $open->observations + 1;
                $open->save();
            }

            return 'updated';
        }

        OutageSignal::query()->create([
            'signal_type' => 'session_burst', 'scope_key' => $scope, 'olt_id' => $b['olt_id'], 'pon_port' => $b['pon'],
            'state' => OutageSignal::STATE_CONFIRMED, 'opened_at' => $start, 'confirmed_at' => $start, 'last_seen_at' => $now,
            'observations' => 1, 'affected_count' => $b['count'], 'affected_customer_ids' => $b['customer_ids'],
            'payload' => $payload + ['count' => $b['count']], 'would_action' => 'none', 'action_taken' => 'none', 'mode_at_confirm' => 'shadow',
        ]);

        return 'created';
    }

    /** @return array{0:int,1:int} resolved, expired */
    private function closeOpen(Carbon $now): array
    {
        $resolved = 0;
        $expired = 0;
        foreach (OutageSignal::query()->open()->where('signal_type', 'session_burst')->limit(200)->get() as $s) {
            $ids = array_map('intval', (array) $s->affected_customer_ids);
            $back = 0;
            if ($ids !== []) {
                $seen = [];
                foreach (array_chunk($ids, 300) as $chunk) {
                    foreach (DB::table('pppoe_session_events')->whereIn('customer_id', $chunk)->where('event', 'connected')
                        ->where('at', '>=', $s->opened_at->copy()->utc()->format('Y-m-d H:i:s'))->distinct()->pluck('customer_id') as $cid) {
                        $seen[(int) $cid] = true;
                    }
                }
                $back = count($seen);
            }
            // keep the Deteksi line ("M sudah tersambung lagi") current on every run, not only when a run sees the burst
            $payload = (array) $s->payload;
            if (($payload['reconnected'] ?? null) !== $back) {
                $payload['reconnected'] = $back;
                $s->payload = $payload;
                $s->save();
            }
            if ($ids !== [] && $back >= (int) ceil(count($ids) * self::RESOLVE_SHARE)) {
                $this->close($s, OutageSignal::STATE_RESOLVED, $now);
                $resolved++;
            } elseif ($s->opened_at->lt($now->copy()->subHours(self::EXPIRE_HOURS))) {
                $this->close($s, OutageSignal::STATE_EXPIRED, $now);
                $expired++;
            }
        }

        return [$resolved, $expired];
    }

    private function close(OutageSignal $s, string $state, Carbon $now): void
    {
        $s->state = $state;
        $s->resolved_at = $now;
        $s->save();
    }
}
