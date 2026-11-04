<?php

namespace App\Services\Outage;

use App\Models\OutageSignal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PR-D D2: area power. At least `area_power_min_onus` (5) ONUs of one OLT entering dying gasp in the same detector run
 * (within RUN_SECONDS), of which at least AREA_SHARE sit in one customers.area_id, is "kemungkinan listrik padam di area".
 * There is no PLN feed, so it is never worded as a fact. DB only: onu_state_events (the `at` index, at most MAX_ROWS), customers
 * (by id), writes only outage_signals rows of type area_power. No device access, no queue, no WA, no ticket.
 *
 * Resolved when half of the ONUs of the group are online again; closed after EXPIRE_HOURS whatever happens.
 */
class AreaPowerDetector
{
    /** "The same detector run": events of one run carry one stamp (the next run is ~300 s later), so 2 minutes, not 5. */
    public const RUN_SECONDS = 120;

    public const AREA_SHARE = 0.6;

    public const LOOKBACK_MINUTES = 15;

    public const MAX_ROWS = 5000;

    public const MAX_IDS = 300;

    public const SAME_MINUTES = 10;

    public const OPEN_HOURS = 2;

    public const EXPIRE_HOURS = 24;

    public const RESOLVE_SHARE = 0.5;

    /**
     * @return array{groups:list<array<string,mixed>>,created:int,updated:int,resolved:int,expired:int,truncated:bool}
     */
    public function run(?Carbon $now = null, bool $dryRun = false): array
    {
        $now = ($now ?? now())->copy()->utc();
        $out = $this->detect($now->copy()->subMinutes(self::LOOKBACK_MINUTES), $now);
        $stats = ['created' => 0, 'updated' => 0, 'resolved' => 0, 'expired' => 0];
        if (! $dryRun) {
            foreach ($out['groups'] as $g) {
                $stats[$this->persist($g, $now)]++;
            }
            [$stats['resolved'], $stats['expired']] = $this->closeOpen($now);
        }

        return $out + $stats;
    }

    /**
     * Pure read of [from, to] (also the read-only replay): read in one hour slices of at most MAX_ROWS lines.
     *
     * @return array{groups:list<array<string,mixed>>,truncated:bool}
     */
    public function detect(Carbon $from, Carbon $to): array
    {
        $groups = [];
        $truncated = false;
        if (! Schema::hasTable('onu_state_events')) {
            return ['groups' => [], 'truncated' => false];
        }
        $min = OutageSettings::areaPowerMinOnus();
        $cursor = $from->copy()->utc();
        $end = $to->copy()->utc();
        while ($cursor->lt($end)) {
            $sliceEnd = $cursor->copy()->addHour()->min($end);
            $rows = DB::table('onu_state_events')
                ->where(fn ($q) => $q->where('to_phase', 'dying_gasp')->orWhere('cause', 'dying_gasp'))
                ->where(fn ($q) => $q->whereNull('from_phase')->orWhere('from_phase', 'online'))
                ->where('occurred_at', '>=', $cursor->format('Y-m-d H:i:s'))->where('occurred_at', '<=', $sliceEnd->format('Y-m-d H:i:s'))
                ->orderBy('occurred_at')->limit(self::MAX_ROWS)->get(['olt_id', 'onu_ref', 'customer_id', 'occurred_at']);
            $truncated = $truncated || $rows->count() >= self::MAX_ROWS;
            $byOlt = [];
            foreach ($rows as $r) {
                $byOlt[(int) $r->olt_id][(string) $r->onu_ref] ??= ['ref' => (string) $r->onu_ref, 'c' => $r->customer_id !== null ? (int) $r->customer_id : null, 't' => Carbon::parse($r->occurred_at, 'UTC')];
            }
            foreach ($byOlt as $oltId => $onus) {
                $list = array_values($onus);
                $i = 0;
                $n = count($list);
                while ($i < $n) {
                    $run = [$list[$i]];
                    $j = $i + 1;
                    while ($j < $n && $list[$j]['t']->getTimestamp() <= $list[$i]['t']->getTimestamp() + self::RUN_SECONDS) {
                        $run[] = $list[$j];
                        $j++;
                    }
                    if (count($run) >= $min) {
                        $g = $this->evaluate($oltId, $run);
                        if ($g !== null) {
                            $groups[] = $g;
                        }
                        $i = $j; // the run is used up
                    } else {
                        $i++;
                    }
                }
            }
            $cursor = $sliceEnd->copy()->addSecond();
        }

        return ['groups' => $groups, 'truncated' => $truncated];
    }

    /**
     * @param  list<array{ref:string,c:?int,t:Carbon}>  $run
     * @return array<string,mixed>|null
     */
    private function evaluate(int $oltId, array $run): ?array
    {
        $cids = array_values(array_filter(array_map(fn (array $o) => $o['c'], $run)));
        if ($cids === []) {
            return null;
        }
        $areaOf = [];
        foreach (array_chunk($cids, 500) as $chunk) {
            foreach (DB::table('customers')->whereIn('id', $chunk)->whereNotNull('area_id')->get(['id', 'area_id']) as $c) {
                $areaOf[(int) $c->id] = (int) $c->area_id;
            }
        }
        if ($areaOf === []) {
            return null;
        }
        $perArea = array_count_values($areaOf);
        arsort($perArea);
        $area = (int) key($perArea);
        $inArea = (int) reset($perArea);
        if ($inArea / count($run) < self::AREA_SHARE) {
            return null;
        }
        $members = array_keys(array_filter($areaOf, fn (int $a) => $a === $area));
        $pons = [];
        foreach ($run as $o) {
            $pon = OutageDetector::ponOf((string) OutageDetector::normalizeRef($o['ref']));
            if ($pon !== null) {
                $pons[$pon] = true;
            }
        }

        return [
            'olt_id' => $oltId,
            'area_id' => $area,
            'start' => $run[0]['t'],
            'count' => count($run),
            'in_area' => $inArea,
            'share' => round($inArea / count($run), 3),
            'pon_count' => count($pons),
            'customer_ids' => array_slice($members, 0, self::MAX_IDS),
            'onu_refs' => array_slice(array_map(fn (array $o) => $o['ref'], $run), 0, self::MAX_IDS),
        ];
    }

    // ------------------------------------------------------------------ write

    /** @param  array<string,mixed>  $g */
    private function persist(array $g, Carbon $now): string
    {
        /** @var Carbon $start */
        $start = $g['start'];
        $scope = 'olt:'.$g['olt_id'].':area:'.$g['area_id'];
        $payload = ['count' => $g['count'], 'in_area' => $g['in_area'], 'share' => $g['share'], 'pon_count' => $g['pon_count'], 'onu_refs' => $g['onu_refs'], 'group_start' => $start->toIso8601String()];

        $same = OutageSignal::query()->where('signal_type', 'area_power')->where('scope_key', $scope)
            ->whereBetween('opened_at', [$start->copy()->subMinutes(self::SAME_MINUTES)->format('Y-m-d H:i:s'), $start->copy()->addMinutes(self::SAME_MINUTES)->format('Y-m-d H:i:s')])
            ->orderByDesc('id')->first();
        $existing = $same ?? OutageSignal::query()->open()->where('scope_key', $scope)->first();
        if ($existing !== null && ! $existing->isClosed() && $existing->opened_at->lt($start->copy()->subHours(self::OPEN_HOURS))) {
            $this->close($existing, OutageSignal::STATE_EXPIRED, $now);
            $existing = null;
        }
        if ($existing !== null) {
            if (! $existing->isClosed()) {
                $existing->affected_customer_ids = array_slice(array_values(array_unique(array_merge((array) $existing->affected_customer_ids, $g['customer_ids']))), 0, self::MAX_IDS);
                // affected_count = the ONUs of the area (what affected_customer_ids lists); payload.count = all ONUs of the run
                $existing->affected_count = max((int) $existing->affected_count, (int) $g['in_area']);
                $existing->payload = array_merge($payload, ['count' => max((int) (((array) $existing->payload)['count'] ?? 0), (int) $g['count'])]);
                $existing->last_seen_at = $now;
                $existing->observations = (int) $existing->observations + 1;
                $existing->save();
            }

            return 'updated';
        }

        OutageSignal::query()->create([
            'signal_type' => 'area_power', 'scope_key' => $scope, 'olt_id' => $g['olt_id'], 'area_id' => $g['area_id'],
            'state' => OutageSignal::STATE_CONFIRMED, 'opened_at' => $start, 'confirmed_at' => $start, 'last_seen_at' => $now,
            'observations' => 1, 'affected_count' => $g['in_area'], 'affected_customer_ids' => $g['customer_ids'],
            'payload' => $payload, 'would_action' => 'none', 'action_taken' => 'none', 'mode_at_confirm' => 'shadow',
        ]);

        return 'created';
    }

    /** @return array{0:int,1:int} resolved, expired */
    private function closeOpen(Carbon $now): array
    {
        $resolved = 0;
        $expired = 0;
        foreach (OutageSignal::query()->open()->where('signal_type', 'area_power')->limit(200)->get() as $s) {
            $refs = array_values(array_filter((array) (((array) $s->payload)['onu_refs'] ?? []), 'is_string'));
            $back = 0;
            if ($refs !== [] && Schema::hasTable('onu_state_events')) {
                $seen = DB::table('onu_state_events')->where('olt_id', $s->olt_id)->whereIn('onu_ref', $refs)->where('to_phase', 'online')
                    ->where('occurred_at', '>', $s->opened_at->copy()->utc()->format('Y-m-d H:i:s'))->distinct()->pluck('onu_ref');
                $back = $seen->count();
            }
            if ($refs !== [] && $back >= (int) ceil(count($refs) * self::RESOLVE_SHARE)) {
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
