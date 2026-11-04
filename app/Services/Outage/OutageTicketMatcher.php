<?php

namespace App\Services\Outage;

use App\Models\Customer;
use App\Models\OutageSignal;
use App\Models\SupportTicket;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Roadmap 2.4 PR-4: matches shadow episodes to REAL support tickets (read-only on tickets/customers;
 * the only write is the matched_* columns on outage_signals).
 *
 * single_los / weak_rx / pppoe_down: a real ticket for the same customer created in
 *   [opened_at - 2h, opened_at + 48h].
 * mass_los: a real ticket for ANY affected customer in that window, or a ticket in the same area whose
 *   text looks like an outage (keywords) within +-6h of opened_at.
 * Auto tickets (meta.auto_ticket) and cancelled tickets never count; they would make the shadow
 * report judge itself.
 */
class OutageTicketMatcher
{
    public const BEFORE_MINUTES = 120;

    public const AFTER_MINUTES = 48 * 60;

    public const AREA_WINDOW_MINUTES = 360;

    public const KEYWORDS = '/gangguan|\blos\b|tidak terhubung|putus|merah|massal|redaman/i';

    /** Signal types that can match a ticket (dying_gasp / olt_wide are log only). */
    public const MATCHABLE = ['single_los', 'mass_los', 'weak_rx', 'pppoe_down'];

    /** Ticket types that are never an outage report. */
    private const IGNORED_TICKET_TYPES = ['installation', 'billing', 'dismantle'];

    /**
     * Match every matchable episode opened in [from, to]. Idempotent: re-running recomputes the match.
     *
     * @return array{signals:int,matched:int,unmatched:int,changed:int}
     */
    public function run(CarbonInterface $from, CarbonInterface $to, bool $dryRun = false): array
    {
        $signals = OutageSignal::query()
            ->whereIn('signal_type', self::MATCHABLE)
            ->whereBetween('opened_at', [$from, $to])
            ->orderBy('id')
            ->get(['id', 'signal_type', 'scope_key', 'state', 'opened_at', 'confirmed_at', 'customer_id', 'area_id', 'affected_customer_ids',
                'matched_ticket_id', 'match_method', 'match_lag_minutes']);

        $stats = ['signals' => $signals->count(), 'matched' => 0, 'unmatched' => 0, 'changed' => 0];
        if ($signals->isEmpty()) {
            return $stats;
        }

        $tickets = $this->index($this->candidateTickets(
            Carbon::instance($from)->subMinutes(self::AREA_WINDOW_MINUTES),
            Carbon::instance($to)->addMinutes(self::AFTER_MINUTES),
        ));
        $areaByCustomer = $this->areasForMass($signals);

        foreach ($signals as $signal) {
            $hit = $this->matchOne($signal, $tickets, $areaByCustomer);
            $hit === null ? $stats['unmatched']++ : $stats['matched']++;

            $signal->matched_ticket_id = $hit['ticket_id'] ?? null;
            $signal->match_method = $hit['method'] ?? null;
            $signal->match_lag_minutes = $hit['lag'] ?? null;
            if ($signal->isDirty(['matched_ticket_id', 'match_method', 'match_lag_minutes'])) {
                $stats['changed']++;
                if (! $dryRun) {
                    // Query update, never a model save: the loaded copy is partial/stale and the model's
                    // saving hook would rewrite open_key from that stale state (the detector may have
                    // resolved the row meanwhile).
                    OutageSignal::query()->whereKey($signal->id)->update([
                        'matched_ticket_id' => $signal->matched_ticket_id,
                        'match_method' => $signal->match_method,
                        'match_lag_minutes' => $signal->match_lag_minutes,
                    ]);
                }
            }
        }

        return $stats;
    }

    /**
     * Bucket candidate tickets by customer and by area so each episode only looks at its own buckets
     * (about linear instead of episodes x tickets).
     *
     * @param  list<array{id:int,customer_id:?int,area_id:?int,text:string,at:Carbon,ts:int}>  $tickets
     * @return array{customer:array<int,list<array>>,area:array<int,list<array>>}
     */
    public function index(array $tickets): array
    {
        $idx = ['customer' => [], 'area' => []];
        foreach ($tickets as $t) {
            if ($t['customer_id'] !== null) {
                $idx['customer'][$t['customer_id']][] = $t;
            }
            if ($t['area_id'] !== null) {
                $idx['area'][$t['area_id']][] = $t;
            }
        }

        return $idx;
    }

    /**
     * @param  array{customer:array<int,list<array>>,area:array<int,list<array>>}  $index  from index()
     * @param  array<int,int>  $areaByCustomer
     * @return array{ticket_id:int,method:string,lag:int}|null
     */
    public function matchOne(OutageSignal $signal, array $index, array $areaByCustomer = []): ?array
    {
        $opened = $signal->opened_at->getTimestamp();
        $lo = $opened - self::BEFORE_MINUTES * 60;
        $hi = $opened + self::AFTER_MINUTES * 60;

        // mass_los and router-level pppoe_down carry affected_customer_ids; others a single customer_id.
        $customers = array_map('intval', array_filter((array) $signal->affected_customer_ids, 'is_numeric'));
        if ($signal->customer_id) {
            $customers[] = (int) $signal->customer_id;
        }
        $multi = count($customers) > 1 || $signal->signal_type === 'mass_los';
        $method = $multi ? 'mass_customer' : 'customer';

        $best = null;
        foreach (array_unique($customers) as $cid) {
            foreach ($index['customer'][$cid] ?? [] as $t) {
                if ($t['ts'] >= $lo && $t['ts'] <= $hi) {
                    $best = $this->closer($best, $t, $method, $opened);
                }
            }
        }
        if ($best !== null || $signal->signal_type !== 'mass_los') {
            return $best === null ? null : $this->result($best, $signal);
        }

        // Mass fallback: same area + outage-looking text within +-6h.
        $alo = $opened - self::AREA_WINDOW_MINUTES * 60;
        $ahi = $opened + self::AREA_WINDOW_MINUTES * 60;
        foreach ($this->signalAreas($signal, $areaByCustomer) as $aid) {
            foreach ($index['area'][$aid] ?? [] as $t) {
                if ($t['ts'] >= $alo && $t['ts'] <= $ahi && preg_match(self::KEYWORDS, $t['text']) === 1) {
                    $best = $this->closer($best, $t, 'area_keyword', $opened);
                }
            }
        }

        return $best === null ? null : $this->result($best, $signal);
    }

    /**
     * @param  array{t:array,method:string}|null  $best
     * @return array{t:array,method:string}
     */
    private function closer(?array $best, array $t, string $method, int $opened): array
    {
        if ($best === null || abs($t['ts'] - $opened) < abs($best['t']['ts'] - $opened)) {
            return ['t' => $t, 'method' => $method];
        }

        return $best;
    }

    /** @return array{ticket_id:int,method:string,lag:int} */
    private function result(array $best, OutageSignal $signal): array
    {
        $ref = Carbon::instance($signal->confirmed_at ?? $signal->opened_at);

        return [
            'ticket_id' => (int) $best['t']['id'],
            'method' => $best['method'],
            // ticket.created_at - confirmed_at (minutes; negative = the customer reported before we saw it).
            'lag' => (int) round(($best['t']['at']->getTimestamp() - $ref->getTimestamp()) / 60),
        ];
    }

    /**
     * @param  array<int,int>  $areaByCustomer
     * @return list<int>
     */
    private function signalAreas(OutageSignal $signal, array $areaByCustomer): array
    {
        $areas = [];
        if ($signal->area_id) {
            $areas[] = (int) $signal->area_id;
        }
        foreach ((array) $signal->affected_customer_ids as $cid) {
            if (isset($areaByCustomer[(int) $cid])) {
                $areas[] = $areaByCustomer[(int) $cid];
            }
        }

        return array_values(array_unique($areas));
    }

    /** @return array<int,int> customer id => area id for the customers behind mass episodes */
    private function areasForMass($signals): array
    {
        $ids = [];
        foreach ($signals as $s) {
            if ($s->signal_type === 'mass_los') {
                foreach ((array) $s->affected_customer_ids as $cid) {
                    $ids[(int) $cid] = true;
                }
            }
        }
        $map = [];
        foreach (array_chunk(array_keys($ids), 500) as $chunk) {
            foreach (Customer::query()->whereIn('id', $chunk)->whereNotNull('area_id')->get(['id', 'area_id']) as $c) {
                $map[(int) $c->id] = (int) $c->area_id;
            }
        }

        return $map;
    }

    /** @return list<array{id:int,customer_id:?int,area_id:?int,text:string,at:Carbon}> */
    private function candidateTickets(Carbon $from, Carbon $to): array
    {
        $out = [];
        SupportTicket::query()
            ->whereBetween('created_at', [$from, $to])
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'cancelled'))
            ->whereNotIn('type', self::IGNORED_TICKET_TYPES)
            ->orderBy('id')
            ->select(['id', 'customer_id', 'area_id', 'gangguan', 'subject', 'type', 'status', 'meta', 'created_at'])
            ->chunkById(1000, function ($chunk) use (&$out): void {
                foreach ($chunk as $t) {
                    if (self::isDetectionTicket($t)) {
                        continue;
                    }
                    $out[] = [
                        'id' => (int) $t->id,
                        'customer_id' => $t->customer_id ? (int) $t->customer_id : null,
                        'area_id' => $t->area_id ? (int) $t->area_id : null,
                        'text' => trim((string) $t->gangguan.' '.(string) $t->subject),
                        'at' => Carbon::instance($t->created_at),
                        'ts' => $t->created_at->getTimestamp(),
                    ];
                }
            });

        return $out;
    }

    public static function isAutoTicket(SupportTicket $t): bool
    {
        $meta = is_array($t->meta) ? $t->meta : [];

        return ! empty($meta['auto_ticket']);
    }

    /**
     * Tickets made BY the detection (automatic, or the manual "Buat tiket" button of Deteksi Gangguan). They are
     * never a human report, so they count neither as a match nor as recall: that would make the accuracy circular.
     */
    public static function isDetectionTicket(SupportTicket $t): bool
    {
        $meta = is_array($t->meta) ? $t->meta : [];

        return ! empty($meta['auto_ticket']) || ($meta['source'] ?? null) === 'deteksi_manual';
    }
}
