<?php

namespace App\Services\Outage;

use App\Models\Customer;
use App\Models\Olt;
use App\Models\OltOnu;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * RCA-2: onu_state_events writer. TRANSITIONS only: the collectors hand over the state they are about to
 * write, only ONUs whose phase differs from the stored row produce a row. It never throws into a collector
 * (a failing history write must not break the live sync) and never reads or writes a device.
 */
class OnuStateEvents
{
    /** Hard cap per call: a glitch that flips a whole OLT must not write thousands of rows. */
    public const MAX_PER_CALL = 500;

    private static ?bool $tableReady = null;

    public static function tableReady(): bool
    {
        if (self::$tableReady === null) {
            try {
                self::$tableReady = Schema::hasTable('onu_state_events');
            } catch (\Throwable) {
                return false;
            }
        }

        return self::$tableReady;
    }

    /** Test hook. */
    public static function forgetSchemaCache(): void
    {
        self::$tableReady = null;
    }

    /**
     * Comparable phase: online | los | dying_gasp | offline. Null when nothing is known.
     */
    public static function phaseKey(?string $state, ?string $phase): ?string
    {
        $state = strtolower(trim((string) $state));
        if ($state === 'online') {
            return 'online';
        }
        if ($state === '') {
            return null;
        }
        $p = strtolower((string) preg_replace('/[\s_\-]+/', '', (string) $phase));

        return match (true) {
            $p === 'los' => 'los',
            in_array($p, ['dyinggasp', 'dgi'], true) => 'dying_gasp',
            default => 'offline',
        };
    }

    /**
     * @param  array<string,array{state:?string,phase:?string}>  $incoming  normalized onu_ref => state about to be written
     * @return int rows written
     */
    public static function recordTransitions(Olt $olt, array $incoming, string $source, ?CarbonInterface $now = null): int
    {
        if ($incoming === [] || ! self::tableReady()) {
            return 0;
        }

        try {
            $now = $now ? Carbon::instance($now) : now();
            $current = [];
            foreach (array_chunk(array_keys($incoming), 400) as $chunk) {
                $variants = [];
                foreach ($chunk as $ref) {
                    $variants[] = $ref;
                    $variants[] = 'gpon-onu_'.$ref;
                }
                foreach (OltOnu::query()->where('olt_id', $olt->id)->whereIn('onu_ref', $variants)->get(['onu_ref', 'state', 'phase_state']) as $row) {
                    $ref = OutageDetector::normalizeRef((string) $row->onu_ref);
                    if ($ref !== null) {
                        $current[$ref] = self::phaseKey($row->state, $row->phase_state);
                    }
                }
            }

            $changed = [];
            foreach ($incoming as $ref => $new) {
                $to = self::phaseKey($new['state'] ?? null, $new['phase'] ?? null);
                // Unknown row (first sight) or unknown state: baseline, not a transition.
                if ($to === null || ! array_key_exists($ref, $current) || $current[$ref] === null || $current[$ref] === $to) {
                    continue;
                }
                // Unknown phase code this tick (the phase UPDATE is skipped): the row keeps its LOS / DyingGasp, so an
                // offline -> offline "change" is not a transition and must not be written every 5 minutes.
                if (($new['phase'] ?? null) === null && $to === 'offline' && in_array($current[$ref], ['los', 'dying_gasp', 'offline'], true)) {
                    continue;
                }
                $changed[$ref] = ['from' => $current[$ref], 'to' => $to];
                if (count($changed) >= self::MAX_PER_CALL) {
                    break;
                }
            }

            return self::recordChanges($olt, $changed, $source, $now);
        } catch (\Throwable $e) {
            try {
                Log::warning('[Outage] onu_state_events write failed', ['olt_id' => $olt->id, 'exception_class' => $e::class]);
            } catch (\Throwable) {
            }

            return 0;
        }
    }

    /**
     * @param  array<string,array{from:?string,to:string}>  $changed  normalized ref => phase change (already compared)
     * @return int rows written
     */
    public static function recordChanges(Olt $olt, array $changed, string $source, ?CarbonInterface $now = null): int
    {
        if ($changed === [] || ! self::tableReady()) {
            return 0;
        }

        try {
            $now = $now ? Carbon::instance($now) : now();
            $changed = array_slice($changed, 0, self::MAX_PER_CALL, true);
            $customers = self::customersByRef($olt, array_keys($changed));
            $rows = [];
            foreach ($changed as $ref => $c) {
                $rows[] = [
                    'olt_id' => $olt->id,
                    'onu_ref' => $ref,
                    'customer_id' => $customers[$ref] ?? null,
                    'from_phase' => $c['from'],
                    'to_phase' => $c['to'],
                    'cause' => in_array($c['to'], ['los', 'dying_gasp'], true) ? $c['to'] : null,
                    'source' => $source,
                    'occurred_at' => $now->copy()->utc()->format('Y-m-d H:i:s'),
                    'collected_at' => $now->copy()->utc()->format('Y-m-d H:i:s'),
                ];
            }
            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table('onu_state_events')->insert($chunk);
            }

            return count($rows);
        } catch (\Throwable $e) {
            try {
                Log::warning('[Outage] onu_state_events write failed', ['olt_id' => $olt->id, 'exception_class' => $e::class]);
            } catch (\Throwable) {
            }

            return 0;
        }
    }

    /**
     * One explicit event (enrichment result). Never throws.
     *
     * @param  array{olt_id:int,onu_ref:string,customer_id:?int,from_phase:?string,to_phase:string,cause:?string,source:string,occurred_at:CarbonInterface}  $event
     */
    public static function recordOne(array $event, ?CarbonInterface $now = null): bool
    {
        if (! self::tableReady()) {
            return false;
        }
        try {
            $now = $now ? Carbon::instance($now) : now();
            $at = Carbon::instance($event['occurred_at'])->utc();
            // `dedupe_seconds`: the OLT history is read again and again and its corrected times jitter by the clock read
            // latency, so the same drop is the same row within +- that many seconds (default: exact time)
            $tolerance = max(0, (int) ($event['dedupe_seconds'] ?? 0));
            $exists = DB::table('onu_state_events')
                ->where('olt_id', $event['olt_id'])->where('onu_ref', $event['onu_ref'])
                ->where('source', $event['source'])
                ->whereBetween('occurred_at', [$at->copy()->subSeconds($tolerance)->format('Y-m-d H:i:s'), $at->copy()->addSeconds($tolerance)->format('Y-m-d H:i:s')])
                ->where('to_phase', $event['to_phase'])->exists();
            if ($exists) {
                return false; // the same history row is not written twice when the cache expires
            }
            DB::table('onu_state_events')->insert([
                'olt_id' => $event['olt_id'],
                'onu_ref' => $event['onu_ref'],
                'customer_id' => $event['customer_id'] ?? null,
                'from_phase' => $event['from_phase'] ?? null,
                'to_phase' => $event['to_phase'],
                'cause' => $event['cause'] ?? null,
                'source' => $event['source'],
                'occurred_at' => $at->format('Y-m-d H:i:s'),
                'collected_at' => $now->copy()->utc()->format('Y-m-d H:i:s'),
            ]);

            return true;
        } catch (\Throwable $e) {
            try {
                Log::warning('[Outage] onu_state_events write failed', ['exception_class' => $e::class]);
            } catch (\Throwable) {
            }

            return false;
        }
    }

    /**
     * @param  list<string>  $refs
     * @return array<string,int> normalized ref => customer id (only unambiguous matches)
     */
    private static function customersByRef(Olt $olt, array $refs): array
    {
        $variants = [];
        foreach ($refs as $ref) {
            $variants[] = $ref;
            $variants[] = 'gpon-onu_'.$ref;
        }
        $seen = [];
        foreach (Customer::query()->where('olt_id', $olt->id)->whereIn('olt_port_label', $variants)->get(['id', 'olt_port_label']) as $c) {
            $ref = OutageDetector::normalizeRef((string) $c->olt_port_label);
            if ($ref !== null) {
                $seen[$ref][] = (int) $c->id;
            }
        }
        $out = [];
        foreach ($seen as $ref => $ids) {
            if (count($ids) === 1) {
                $out[$ref] = $ids[0];
            }
        }

        return $out;
    }
}
