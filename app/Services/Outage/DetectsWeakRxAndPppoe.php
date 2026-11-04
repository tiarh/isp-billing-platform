<?php

namespace App\Services\Outage;

use App\Models\Customer;
use App\Models\Olt;
use App\Models\OltOnu;
use App\Models\OutageOnuTracker;
use App\Models\OutageSignal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Roadmap 2.4 PR-3: weak_rx (RX trend, one median sample per WIB day) and pppoe_down (per customer, one
 * alarm per router when many drop together) for OutageDetector. SHADOW only: it reads collected columns
 * (olt_onus.rx_*, customers.is_online / pppoe_online_checked_at / router_id) and writes only
 * outage_onu_tracker + outage_signals. No device I/O, no tickets, no WA, no customers write.
 *
 * Used by OutageDetector (private helpers createEpisode / suppressionMap / resolve / summary live there).
 */
trait DetectsWeakRxAndPppoe
{
    /** @var array<int,list<array<string,mixed>>> this run's PPPoE observations per OLT id */
    private array $pppoeObs = [];

    /** @var list<array{ep:OutageSignal,dbm:float,mode:string}> weak_rx episodes that reached "weak" this run (all OLTs) */
    private array $weakPending = [];

    /**
     * RCA-1 weak_rx cap, once per detector run (after every OLT, so the ranking is global): the still-weak
     * candidates are the episodes that just reached "weak" plus every unresolved weekly_list episode. The
     * worst `outage_weak_rx_max_per_day` of them, minus what was already promoted today (WIB), become
     * would-be maintenance tickets; the rest is (or stays) the weekly list.
     */
    private function finalizeWeakRx(Carbon $now): void
    {
        $pending = $this->weakPending;
        $this->weakPending = [];
        if (OutageSettings::mode('weak_rx') === 'off') {
            return;
        }

        $listed = OutageSignal::query()->ofType('weak_rx')->where('would_action', 'weekly_list')
            ->where('state', OutageSignal::STATE_CONFIRMED)->get()
            // Stale data (not seen for over a day) is never promoted to a ticket; it stays on the list.
            ->filter(fn (OutageSignal $s): bool => $s->last_seen_at !== null && $s->last_seen_at->gte($now->copy()->subDay()))
            ->values();
        if ($pending === [] && $listed->isEmpty()) {
            return;
        }

        $candidates = [];
        foreach ($pending as $p) {
            $candidates[] = ['ep' => $p['ep'], 'dbm' => $p['dbm'], 'new' => true, 'mode' => $p['mode']];
        }
        foreach ($listed as $ep) {
            $p = (array) $ep->payload;
            $dbm = $p['latest_dbm'] ?? $p['min_dbm'] ?? $p['rx_dbm'] ?? null;
            $candidates[] = ['ep' => $ep, 'dbm' => is_numeric($dbm) ? (float) $dbm : 0.0, 'new' => false, 'mode' => 'shadow'];
        }
        usort($candidates, fn (array $a, array $b) => $a['dbm'] <=> $b['dbm']); // most negative first

        $todayStart = $now->copy()->timezone('Asia/Jakarta')->startOfDay()->utc();
        $usedToday = OutageSignal::query()->ofType('weak_rx')->where('would_action', 'ticket_maintenance')
            ->where('updated_at', '>=', $todayStart)->get(['id', 'confirmed_at', 'payload'])
            ->filter(function (OutageSignal $s) use ($todayStart): bool {
                $promoted = ((array) $s->payload)['promoted_at'] ?? null;
                $at = $promoted !== null ? Carbon::parse((string) $promoted) : $s->confirmed_at;

                return $at !== null && $at->gte($todayStart);
            })->count();
        $room = max(0, OutageSettings::weakRxMaxPerDay() - $usedToday);

        foreach ($candidates as $i => $c) {
            $ep = $c['ep'];
            if ($c['new']) {
                $this->confirmShadow($ep, $c['mode'], $i < $room ? 'ticket_maintenance' : 'weekly_list', $now);
            } elseif ($i < $room) {
                $payload = (array) $ep->payload;
                $payload['promoted_at'] = $now->toIso8601String();
                $ep->payload = $payload;
                $ep->would_action = 'ticket_maintenance';
                $ep->save();
                $this->summary['confirmed']++;
                $this->pushShadowEntry($ep, 'ticket_maintenance');
            }
        }
    }

    /** Fill the tracker of rows that were just inserted (first sight) so RX / PPPoE state can be stored. */
    private function hydrateTrackers(Olt $olt, array &$rows): void
    {
        $missing = [];
        foreach ($rows as $ref => $r) {
            if ($r['tracker'] === null) {
                $missing[] = $ref;
            }
        }
        if ($missing === []) {
            return;
        }
        foreach (array_chunk($missing, 500) as $chunk) {
            $found = OutageOnuTracker::query()->where('olt_id', $olt->id)->whereIn('onu_ref', $chunk)->get()->keyBy('onu_ref');
            foreach ($found as $ref => $t) {
                $rows[$ref]['tracker'] = $t;
            }
        }
    }

    // ------------------------------------------------------------------ weak_rx

    /**
     * RX sampling (a sample is new only when rx_collected_at moved), one median per WIB day, and the
     * 3-consecutive-days rule (owner Q3) with hysteresis. Offline ONUs and invalid RX give no sample.
     *
     * @param  array<string,array<string,mixed>>  $rows
     */
    private function detectWeakRx(Olt $olt, string $family, array &$rows, Carbon $now): void
    {
        $mode = OutageSettings::modeFor('weak_rx', $family);
        if ($mode === 'off') {
            return;
        }

        $threshold = OutageSettings::weakRxDbm();
        $record = $threshold + 2.0;
        $recover = $threshold + 1.0; // hysteresis: back to -26 dBm (not -27.1) counts as recovered
        $days = OutageSettings::weakRxDays();
        $trendDb = OutageSettings::weakRxTrendDb();
        $today = $now->copy()->timezone('Asia/Jakarta')->startOfDay();
        $trendOn = OutageSettings::weakRxTrendEnabled();
        $minSamples = OutageSettings::weakRxMinDaySamples();
        $interval = OutageSettings::weakRxSampleMinutes();

        // 1) samples: at most one per ONU per ~interval (per-ONU jitter spreads the writes over a few
        // ticks) and at most WEAK_RX_MAX_WRITES_PER_RUN tracker writes per run; due ONUs wait for the next run.
        $writes = 0;
        foreach ($rows as $ref => $r) {
            if ($writes >= self::WEAK_RX_MAX_WRITES_PER_RUN) {
                break;
            }
            /** @var OutageOnuTracker|null $t */
            $t = $r['tracker'];
            if ($t === null || ! $r['eligible'] || $r['phase'] !== 'online' || $r['rx'] === null || $r['rx_at'] === null) {
                continue;
            }
            /** @var Carbon $at */
            $at = $r['rx_at'];
            if ($at->lt($now->copy()->subMinutes(30)) || $at->gt($now->copy()->addMinutes(5))) {
                continue; // stale (or future) reading: skipped
            }
            $samples = array_values((array) $t->rx_samples);
            $last = $samples === [] ? null : end($samples);
            if (is_array($last) && isset($last['at'])) {
                $lastAt = Carbon::parse($last['at']);
                if ($lastAt->gte($at)) {
                    continue; // same rx_collected_at (or older): not a new sample
                }
                $due = $interval - 5 * (crc32($olt->id.'|'.$ref) % 3); // 50..60 min at the default
                if ($at->lt($lastAt->copy()->addMinutes($due))) {
                    continue; // sampled recently: no tracker write
                }
            }
            $samples[] = ['at' => $at->toIso8601String(), 'dbm' => round((float) $r['rx'], 2)];
            $t->rx_samples = array_slice($samples, -6);
            $t->rx_daily = $this->addDailySample((array) $t->rx_daily, $at, (float) $r['rx']);
            $t->save();
            $writes++;
        }

        // 2) evaluation
        $evals = [];
        $frozen = [];
        foreach ($rows as $ref => $r) {
            $t = $r['tracker'];
            if ($t === null || ! $r['eligible'] || $r['customer'] === null) {
                continue;
            }
            // A day only counts once it has enough samples: a partial day never decides alone.
            $daily = array_values(array_filter(
                $this->normalizedDaily((array) $t->rx_daily),
                fn (array $d) => $d['n'] >= $minSamples,
            ));
            if ($daily === []) {
                continue;
            }
            $latestDate = Carbon::parse(end($daily)['date'], 'Asia/Jakarta')->startOfDay();
            if ($latestDate->lt($today->copy()->subDay())) {
                $frozen[] = $r['customer']->id; // no reading for 2+ days (ONU away / collector gap): frozen, not weak, not recovered

                continue;
            }
            $evals[$ref] = $this->rxEvaluate($daily, $threshold, $recover, $record, $days, $trendDb, $trendOn);
        }
        // Stale RX history: open (unconfirmed) episodes are marked, never confirmed or resolved.
        foreach (array_chunk($frozen, 500) as $chunk) {
            OutageSignal::query()->where('state', OutageSignal::STATE_OPEN)->ofType('weak_rx')
                ->whereIn('customer_id', $chunk)->get()
                ->each(function (OutageSignal $s) {
                    if ($s->suppressed_reason !== 'stale') {
                        $s->suppressed_reason = 'stale';
                        $s->save();
                    }
                });
        }
        if ($evals === []) {
            return;
        }

        $customerIds = array_values(array_unique(array_map(fn ($ref) => $rows[$ref]['customer']->id, array_keys($evals))));
        $open = collect();
        foreach (array_chunk($customerIds, 500) as $chunk) {
            $open = $open->concat(OutageSignal::query()->open()->ofType('weak_rx')->whereIn('customer_id', $chunk)->get());
        }
        $open = $open->keyBy('customer_id');

        $candidates = [];
        foreach ($evals as $ref => $e) {
            if ($open->has($rows[$ref]['customer']->id) || $e['latest'] < $record) {
                $candidates[$ref] = $rows[$ref];
            }
        }
        $suppressed = $this->suppressionMap($olt, array_values($candidates), $now, true);

        foreach ($candidates as $ref => $r) {
            $e = $evals[$ref];
            $cid = $r['customer']->id;
            $ep = $open->get($cid);
            $reason = $suppressed[$cid] ?? null;
            // Decision values (frozen once the episode is confirmed: PR-4 reads them) vs live info.
            $decision = [
                'rx_dbm' => $e['latest'],
                'worst_dbm' => $e['worst_dbm'],
                'min_dbm' => $e['min_dbm'],
            ];
            $info = [
                'family' => $family,
                'latest_dbm' => $e['latest'],
                'trend_db' => $e['trend_db'], // informational unless outage_weak_rx_trend_enabled
                'daily' => $e['view'],
                'thresholds' => ['weak_rx_dbm' => $threshold, 'record_dbm' => $record, 'recover_dbm' => $recover, 'days' => $days, 'trend_db' => $trendDb, 'trend_enabled' => $trendOn],
            ];
            $payloadView = $decision + $info;

            if ($ep === null) {
                $ep = $this->createEpisode('weak_rx', 'customer:'.$cid.':rx', [
                    'olt_id' => $olt->id,
                    'pon_port' => $r['pon'],
                    'customer_id' => $cid,
                    'area_id' => $r['customer']->area_id,
                    'opened_at' => $now,
                    'last_seen_at' => $now,
                    'observations' => 1,
                    'affected_count' => 1,
                    'payload' => $payloadView,
                ]);
                $this->summary['opened']++;
            } else {
                $confirmedAlready = $ep->state === OutageSignal::STATE_CONFIRMED;
                // Confirmed: worst_dbm / min_dbm / rx_dbm stay what decided the confirm.
                $current = (array) $ep->payload;
                $merged = array_merge($current, $confirmedAlready ? $info : $payloadView);
                $confirmsNow = ($e['weak'] || $e['trend']) && $reason === null;
                if (! $confirmedAlready && ! $confirmsNow) {
                    // Open, not confirmed (and not confirming in this run: the confirm run stores the values that
                    // decided it): keep the worst (lowest) decision median seen in the episode, so a replay with a
                    // looser threshold is not under-counted by a later recovery of the readings.
                    foreach (['worst_dbm', 'min_dbm'] as $k) {
                        if (isset($current[$k]) && is_numeric($current[$k])) {
                            $merged[$k] = min((float) $current[$k], (float) $payloadView[$k]);
                        }
                    }
                }
                $ep->payload = $merged;
                $ep->olt_id = $olt->id;
                $ep->pon_port = $r['pon'];
                if ($ep->last_seen_at === null || $ep->last_seen_at->lt($now->copy()->subMinutes(30)) || $ep->isDirty('payload')) {
                    $ep->last_seen_at = $now;
                    $ep->observations = (int) $ep->observations + 1;
                }
            }

            if ($ep->state === OutageSignal::STATE_CONFIRMED) {
                if ($e['recovered']) {
                    $payload = (array) $ep->payload;
                    $payload['recovery'] = ['latest_dbm' => $e['latest'], 'worst_dbm' => $e['worst_dbm'], 'at' => $now->toIso8601String()];
                    $ep->payload = $payload;
                    $this->resolve($ep, $now);

                    continue;
                }
            } elseif ($ep->state === OutageSignal::STATE_OPEN) {
                $ep->suppressed_reason = $reason;
                if ($reason !== null) {
                    $this->bump('suppressed', $reason);
                }
                if ($e['weak'] || $e['trend']) {
                    if ($reason === null) {
                        $payload = (array) $ep->payload;
                        $payload['rule'] = $e['weak'] ? 'days' : 'trend';
                        $ep->payload = $payload;
                        // RCA-1: no burst. Confirmations of this run are ranked below (worst RX first) against
                        // the per-day cap; the rest goes to the weekly list.
                        $this->weakPending[] = ['ep' => $ep, 'dbm' => (float) $e['latest'], 'mode' => $mode];

                        continue;
                    }
                } elseif ($e['latest'] >= $record) {
                    // Never confirmed and back above the record threshold: close quietly.
                    $this->resolve($ep, $now);

                    continue;
                }
            }
            if ($ep->isDirty()) {
                $ep->save();
            }
        }
    }

    /**
     * @param  array<int|string,mixed>  $daily
     * @return list<array{date:string,dbm:float,n:int,v?:list<float>}>
     */
    private function addDailySample(array $daily, Carbon $at, float $dbm): array
    {
        $date = $at->copy()->timezone('Asia/Jakarta')->format('Y-m-d');
        $daily = $this->normalizedDaily($daily);
        $idx = null;
        foreach ($daily as $i => $d) {
            if ($d['date'] === $date) {
                $idx = $i;
            }
        }
        if ($idx === null) {
            $daily[] = ['date' => $date, 'dbm' => round($dbm, 2), 'n' => 1, 'v' => [round($dbm, 2)]];
        } else {
            $values = array_values((array) ($daily[$idx]['v'] ?? []));
            $values[] = round($dbm, 2);
            $values = array_slice($values, -self::WEAK_RX_DAY_VALUES_MAX); // <= 24/day at the default 60 min
            $daily[$idx]['v'] = $values;
            $daily[$idx]['dbm'] = round(self::median($values), 2);
            $daily[$idx]['n'] = (int) ($daily[$idx]['n'] ?? 0) + 1;
        }
        usort($daily, fn ($a, $b) => strcmp($a['date'], $b['date']));
        $daily = array_slice($daily, -8);
        // Raw values are only needed for the day still collecting samples.
        $lastKey = array_key_last($daily);
        foreach ($daily as $i => &$d) {
            if ($i !== $lastKey) {
                unset($d['v']);
            }
        }
        unset($d);

        return array_values($daily);
    }

    /**
     * @param  array<int|string,mixed>  $daily
     * @return list<array{date:string,dbm:float,n:int,v?:list<float>}>
     */
    private function normalizedDaily(array $daily): array
    {
        $out = [];
        foreach ($daily as $d) {
            if (is_array($d) && isset($d['date'], $d['dbm']) && is_numeric($d['dbm']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $d['date']) === 1) {
                $out[] = ['date' => (string) $d['date'], 'dbm' => (float) $d['dbm'], 'n' => (int) ($d['n'] ?? 1)] + (isset($d['v']) ? ['v' => (array) $d['v']] : []);
            }
        }
        usort($out, fn ($a, $b) => strcmp($a['date'], $b['date']));

        return $out;
    }

    /**
     * @param  list<array{date:string,dbm:float,n:int}>  $daily  sorted ascending
     * @return array{latest:float,weak:bool,trend:bool,recovered:bool,view:list<array{date:string,dbm:float,n:int}>}
     */
    private function rxEvaluate(array $daily, float $threshold, float $recover, float $record, int $days, float $trendDb, bool $trendOn = false): array
    {
        // Longest suffix of consecutive calendar days: a missing day breaks "N days in a row".
        $suffix = [];
        foreach (array_reverse($daily) as $d) {
            if ($suffix !== []) {
                $prev = Carbon::parse(end($suffix)['date'])->startOfDay();
                if (! Carbon::parse($d['date'])->startOfDay()->addDay()->equalTo($prev)) {
                    break;
                }
            }
            $suffix[] = $d;
        }
        $suffix = array_reverse($suffix);
        $latest = (float) end($daily)['dbm'];
        $lastN = array_slice($suffix, -$days);
        $haveN = count($suffix) >= $days;

        $weak = $haveN && count(array_filter($lastN, fn ($d) => $d['dbm'] < $threshold)) === $days;

        // The trend is informational (payload.trend_db) unless outage_weak_rx_trend_enabled: the owner rule
        // is only "N consecutive daily medians below the threshold".
        $trendDrop = null;
        if (count($suffix) >= 4) {
            $window = array_slice($suffix, -6);
            $trendDrop = round($window[0]['dbm'] - end($window)['dbm'], 2);
        }
        $trend = $trendOn && $trendDrop !== null && $trendDrop >= $trendDb && $latest < $record;
        // Recovered: N days at/above the hysteresis level (and, only when the trend rule is on, not still falling).
        $recovered = $haveN && ! $trend && count(array_filter($lastN, fn ($d) => $d['dbm'] >= $recover)) === $days;

        $view = array_map(fn ($d) => ['date' => $d['date'], 'dbm' => $d['dbm'], 'n' => $d['n']], array_slice($daily, -7));

        // worst_dbm = the daily median that decides the 3-day rule (the least weak of the last N days, so
        // "worst_dbm < threshold" holds exactly when every day was below it); falls back to the latest.
        $deciding = array_column($haveN ? $lastN : [end($daily)], 'dbm');

        return [
            'latest' => $latest, 'weak' => $weak, 'trend' => $trend, 'trend_db' => $trendDrop, 'recovered' => $recovered, 'view' => $view,
            'worst_dbm' => (float) max($deciding), 'min_dbm' => (float) min($deciding),
        ];
    }

    /** @param list<float> $values */
    private static function median(array $values): float
    {
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 === 1 ? (float) $values[$mid] : ((float) $values[$mid - 1] + (float) $values[$mid]) / 2;
    }

    /** Valid, fresh-enough RX for a collected ONU row (null when it must not become a sample). */
    private static function rxOf(OltOnu $onu): ?float
    {
        if ($onu->rx_power_dbm === null || ! OltOnu::isValidLiveRxPower((float) $onu->rx_power_dbm)) {
            return null;
        }

        return (float) $onu->rx_power_dbm;
    }

    // --------------------------------------------------------------- pppoe_down

    /**
     * Per OLT (after the tracker write): PPPoE classification per eligible customer and the
     * pppoe_down_since clock. Episodes are handled later by processPppoe() (needs all OLTs for routers).
     *
     * @param  array<string,array<string,mixed>>  $rows
     */
    private function collectPppoe(Olt $olt, string $family, array $rows, Carbon $now): void
    {
        $mode = OutageSettings::modeFor('pppoe_down', $family);
        if ($mode === 'off') {
            return;
        }
        $maxAge = max(1, (int) config('services.online_freshness.ppp_max_age_minutes', 15));
        $freshAfter = $now->copy()->subMinutes($maxAge);

        $obs = [];
        $downRows = [];
        foreach ($rows as $r) {
            /** @var Customer|null $c */
            $c = $r['customer'];
            /** @var OutageOnuTracker|null $t */
            $t = $r['tracker'];
            if ($c === null || ! $r['eligible'] || $t === null) {
                continue;
            }
            if (in_array($r['phase'], ['los', 'dying_gasp', 'offline'], true)) {
                $state = 'na'; // the ONU itself is down: a LOS signal, not PPPoE
            } elseif ($r['phase'] !== 'online') {
                $state = 'unknown';
            } elseif ($c->pppoe_online_checked_at === null || $c->pppoe_online_checked_at->lt($freshAfter)) {
                $state = 'unknown'; // stale / never checked PPPoE data: no event
            } else {
                $state = $c->is_online ? 'up' : 'down';
                if ($c->router_id !== null && isset($this->blindRouters[(int) $c->router_id])) {
                    // RCA-1 DATA_BLIND: the router's PPPoE poll is stale / failed, so up/down is not evidence.
                    $state = 'unknown';
                    $this->blindRouterCustomers[(int) $c->router_id] = ($this->blindRouterCustomers[(int) $c->router_id] ?? 0) + 1;
                }
            }

            if ($state === 'down') {
                if ($t->pppoe_down_since === null) {
                    $t->pppoe_down_since = $now;
                    $t->save();
                }
                $downRows[] = $r;
            } elseif ($t->pppoe_down_since !== null) {
                // up / ONU down / unknown: the clock restarts (a stale gap is never counted as "down")
                $t->pppoe_down_since = null;
                $t->save();
            }
            $obs[$c->id] = ['row' => $r, 'state' => $state, 'since' => $state === 'down' ? $t->pppoe_down_since : null, 'reason' => null];
        }

        $suppressed = $this->suppressionMap($olt, $downRows, $now, true);
        foreach ($obs as $cid => &$o) {
            $o['reason'] = $suppressed[$cid] ?? null;
        }
        unset($o);

        $this->pppoeObs[$olt->id] = ['olt' => $olt, 'family' => $family, 'mode' => $mode, 'obs' => $obs];
    }

    /** After every OLT: episodes per customer, one alarm per router when 5+ customers are down together. */
    private function processPppoe(Carbon $now): void
    {
        if ($this->pppoeObs === []) {
            return;
        }
        $recordMin = self::PPPOE_RECORD_MINUTES;
        $confirmMin = OutageSettings::pppoeDownMinutes();

        $all = [];
        foreach ($this->pppoeObs as $oltId => $bucket) {
            foreach ($bucket['obs'] as $cid => $o) {
                $all[$cid] = $o + ['olt' => $bucket['olt'], 'family' => $bucket['family'], 'mode' => $bucket['mode']];
            }
        }
        $this->pppoeObs = [];
        if ($all === []) {
            return;
        }

        // --- router level
        $byRouter = [];
        $known = [];
        foreach ($all as $cid => $o) {
            $rid = $o['row']['customer']->router_id;
            if ($rid === null) {
                continue;
            }
            if (in_array($o['state'], ['up', 'down'], true)) {
                $known[(int) $rid] = true;
            }
            if ($o['state'] === 'down' && $o['reason'] === null && $o['since'] !== null
                && $o['since']->lte($now->copy()->subMinutes($confirmMin))) {
                $byRouter[(int) $rid][] = (int) $cid;
            }
        }
        $routerIds = array_values(array_unique(array_merge(array_keys($byRouter), array_keys($known))));
        $existing = collect();
        if ($routerIds !== []) {
            $existing = OutageSignal::query()->open()->ofType('pppoe_down')
                ->whereIn('scope_key', array_map(fn ($id) => 'router:'.$id.':ppp', $routerIds))->get()->keyBy('scope_key');
        }
        $routerEp = []; // router_id => episode that currently covers its members
        foreach ($routerIds as $rid) {
            $scope = 'router:'.$rid.':ppp';
            $ids = $byRouter[$rid] ?? [];
            $count = count($ids);
            $ep = $existing->get($scope);
            $mode = 'shadow';
            foreach ($ids as $cid) {
                $mode = $all[$cid]['mode'];
                break;
            }
            if ($ep === null) {
                if ($count < self::PPPOE_ROUTER_MIN) {
                    continue;
                }
                $ep = $this->createEpisode('pppoe_down', $scope, [
                    'opened_at' => $now,
                    'last_seen_at' => $now,
                    'observations' => 1,
                    'affected_count' => $count,
                    'affected_customer_ids' => array_slice($ids, 0, 300),
                    'payload' => ['router_id' => $rid, 'level' => 'router', 'low_runs' => 0, 'affected_customer_ids' => array_slice($ids, 0, 300), 'thresholds' => ['router_min' => self::PPPOE_ROUTER_MIN, 'down_minutes' => $confirmMin]],
                ]);
                $this->summary['opened']++;
            } else {
                $payload = (array) $ep->payload;
                if ($count >= 2) {
                    $payload['low_runs'] = 0;
                    $ep->affected_count = $count;
                    $ep->affected_customer_ids = array_slice($ids, 0, 300);
                    $payload['affected_customer_ids'] = array_slice($ids, 0, 300);
                } elseif (isset($known[$rid])) {
                    $payload['low_runs'] = (int) ($payload['low_runs'] ?? 0) + 1;
                    if ($payload['low_runs'] >= 2) {
                        $ep->payload = $payload;
                        $this->resolve($ep, $now);

                        continue;
                    }
                }
                $ep->payload = $payload;
                $ep->last_seen_at = $now;
                $ep->observations = (int) $ep->observations + 1;
            }
            if ($ep->state === OutageSignal::STATE_OPEN && $count >= self::PPPOE_ROUTER_MIN) {
                $this->confirmShadow($ep, $mode, 'noc_alarm', $now);
            } else {
                $ep->save();
            }
            $routerEp[$rid] = $ep;
        }

        // --- per customer
        $customerIds = array_keys($all);
        $open = collect();
        foreach (array_chunk($customerIds, 500) as $chunk) {
            $open = $open->concat(OutageSignal::query()->open()->ofType('pppoe_down')->whereIn('customer_id', $chunk)->get());
        }
        $open = $open->keyBy('customer_id');
        $downIds = array_keys(array_filter($all, fn ($o) => $o['state'] === 'down'));
        $sessionEvents = $this->recentPppoeEvents($downIds, $now);

        foreach ($all as $cid => $o) {
            $ep = $open->get($cid);
            if ($o['state'] === 'unknown') {
                // frozen: neither confirm nor resolve on unknown (stale PPPoE / unknown ONU) data
                if ($ep !== null && $ep->state === OutageSignal::STATE_OPEN && $ep->suppressed_reason !== 'stale') {
                    $ep->suppressed_reason = 'stale';
                    $ep->save();
                }

                continue;
            }
            if ($o['state'] !== 'down') {
                if ($ep !== null) {
                    $this->resolve($ep, $now); // PPPoE back, or the ONU itself is down now (LOS signal)
                }

                continue;
            }
            $since = $o['since'] ?? $now;
            $age = (int) $since->diffInMinutes($now);
            if ($ep === null && $age < $recordMin) {
                continue;
            }
            /** @var Customer $c */
            $c = $o['row']['customer'];
            $olt = $o['olt'];
            if ($ep === null) {
                $ep = $this->createEpisode('pppoe_down', 'customer:'.$cid.':ppp', [
                    'olt_id' => $olt->id,
                    'pon_port' => $o['row']['pon'],
                    'customer_id' => $cid,
                    'area_id' => $c->area_id,
                    'opened_at' => $since,
                    'last_seen_at' => $now,
                    'observations' => 1,
                    'affected_count' => 1,
                    'payload' => ['family' => $o['family'], 'level' => 'customer', 'router_id' => $c->router_id, 'thresholds' => ['down_minutes' => $confirmMin]],
                ]);
                $this->summary['opened']++;
            } else {
                $ep->olt_id = $olt->id;
                $ep->pon_port = $o['row']['pon'];
                if ($ep->last_seen_at === null || $ep->last_seen_at->lt($now->copy()->subMinutes(30))) {
                    $ep->last_seen_at = $now;
                    $ep->observations = (int) $ep->observations + 1;
                }
            }

            // RCA-2: the newest syslog session event of this customer (disconnect reason, stuck session, auth failure).
            if (isset($sessionEvents[$cid]) && $ep->state === OutageSignal::STATE_OPEN) {
                $p = (array) $ep->payload;
                $p['pppoe_event'] = $sessionEvents[$cid];
                $p['cause_detail'] = match ($sessionEvents[$cid]['event']) {
                    'stuck' => 'pppoe_stuck',
                    'auth_failed' => 'pppoe_auth',
                    default => $p['cause_detail'] ?? null,
                };
                $p = array_filter($p, fn ($v) => $v !== null);
                $ep->payload = $p;
            }

            $reason = $o['reason'];
            $parent = $c->router_id !== null ? ($routerEp[(int) $c->router_id] ?? null) : null;
            if ($parent !== null && $ep->state === OutageSignal::STATE_OPEN
                && in_array($cid, array_map('intval', (array) $parent->affected_customer_ids), true)) {
                $ep->parent_signal_id ??= $parent->id;
                $reason = 'inside_mass'; // covered by the single router-level alarm
            }
            if ($ep->state === OutageSignal::STATE_OPEN) {
                $ep->suppressed_reason = $reason;
                if ($reason !== null) {
                    $this->bump('suppressed', $reason);
                }
                if ($reason === null && $age >= $confirmMin) {
                    $this->confirmShadow($ep, $o['mode'], 'noc_alarm', $now);

                    continue;
                }
            }
            if ($ep->isDirty()) {
                $ep->save();
            }
        }
    }

    /** Session events newer than this explain a PPPoE-down episode. */
    public const PPPOE_EVENT_LOOKBACK_MINUTES = 90;

    /**
     * Newest "interesting" PPPoE session event per customer (RCA-2, syslog). Read-only; empty while the table is
     * missing or no router sends syslog. A later "connected" hides older disconnect/stuck rows.
     *
     * @param  list<int|string>  $customerIds
     * @return array<int,array{event:string,reason:?string,at:string}>
     */
    private function recentPppoeEvents(array $customerIds, Carbon $now): array
    {
        if ($customerIds === [] || ! Schema::hasTable('pppoe_session_events')) {
            return [];
        }
        $out = [];
        $since = $now->copy()->subMinutes(self::PPPOE_EVENT_LOOKBACK_MINUTES)->utc()->format('Y-m-d H:i:s');
        foreach (array_chunk($customerIds, 500) as $chunk) {
            $rows = DB::table('pppoe_session_events')->whereIn('customer_id', $chunk)->where('at', '>=', $since)
                ->orderBy('at')->orderBy('id')->get(['customer_id', 'event', 'reason', 'at']);
            foreach ($rows as $row) {
                $cid = (int) $row->customer_id;
                if ($row->event === 'connected') {
                    unset($out[$cid]); // back online after that: the older event no longer explains the outage
                } elseif ($row->event !== 'other') {
                    $out[$cid] = ['event' => (string) $row->event, 'reason' => $row->reason, 'at' => Carbon::parse($row->at, 'UTC')->toIso8601String()];
                }
            }
        }

        return $out;
    }

    /** @return array<int,string> OLT id => name, one query per detector run. */
    private function oltNames(): array
    {
        return $this->oltNameMap ??= Olt::query()->pluck('name', 'id')->map(fn ($n) => (string) $n)->all();
    }

    /**
     * Confirm. weak_rx / pppoe_down are reported by the daily digest; a per-event WA entry (ids only, no
     * names) is appended ONLY when outage_shadow_wa_include_names is on (flag off = summary unchanged).
     */
    private function confirmShadow(OutageSignal $ep, string $mode, string $wouldAction, Carbon $now): void
    {
        $ep->state = OutageSignal::STATE_CONFIRMED;
        $ep->confirmed_at = $now;
        $ep->mode_at_confirm = $mode === 'live' ? 'shadow' : $mode; // PR-3 never acts
        $ep->would_action = $wouldAction;
        $ep->action_taken = 'none';
        $ep->suppressed_reason = null;
        $ep->save();
        $this->summary['confirmed']++;
        $this->pushShadowEntry($ep, $wouldAction);
    }

    /** Per-event shadow WA entry (ids only). weekly_list entries never get one (that would be the burst). */
    private function pushShadowEntry(OutageSignal $ep, string $wouldAction): void
    {
        if ($wouldAction !== 'weekly_list' && OutageSettings::shadowWaIncludeNames()) {
            $level = (string) (((array) $ep->payload)['level'] ?? 'customer');
            $this->summary['new_confirmed'][] = [
                'id' => $ep->id,
                'type' => $ep->signal_type,
                'olt_id' => $ep->olt_id,
                'olt_name' => $ep->olt_id !== null ? (string) ($this->oltNames()[(int) $ep->olt_id] ?? '') : '',
                'pon_port' => $ep->pon_port,
                'customer_id' => $ep->customer_id !== null ? (int) $ep->customer_id : null,
                'affected_count' => (int) $ep->affected_count,
                'level' => $level,
                'would_action' => $wouldAction,
            ];
        }
    }
}
