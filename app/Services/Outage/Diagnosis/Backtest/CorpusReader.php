<?php

namespace App\Services\Outage\Diagnosis\Backtest;

use App\Enums\OutageRootCause;
use App\Models\OutageDiagnosis;
use App\Models\SupportTicket;
use App\Services\Outage\Diagnosis\PrecisionReport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Builds corpus rows from the database (read-only): a stored diagnosis with its context snapshot plus the labels known
 * today (the closed ticket's cause and the outcome labels). Bounded: at most $limit rows, contexts read in chunks of 100
 * by primary key. Only the FIRST timely prediction of a ticket (ticket_open, else the first manual_check) carries the
 * ticket label, like the weekly report; the other rows are kept without one.
 */
final class CorpusReader
{
    public const DEFAULT_LIMIT = 5000;

    private const CHUNK = 100;

    /**
     * @return \Generator<int,array<string,mixed>>
     */
    public function rows(Carbon $since, int $limit = self::DEFAULT_LIMIT): \Generator
    {
        if (! Schema::hasTable('outage_diagnoses') || ! Schema::hasColumn('outage_diagnoses', 'context')) {
            return;
        }
        $limit = max(1, min(self::DEFAULT_LIMIT, $limit));
        // metadata first (no context column): ids, ticket, trigger, time
        $meta = DB::table('outage_diagnoses')->where('state', OutageDiagnosis::STATE_DONE)->whereNotNull('context')
            ->where('created_at', '>=', $since->copy()->utc()->format('Y-m-d H:i:s'))
            ->whereIn('trigger', [OutageDiagnosis::TRIGGER_TICKET_OPEN, OutageDiagnosis::TRIGGER_MANUAL, OutageDiagnosis::TRIGGER_AUTO_EPISODE])
            ->orderBy('id')->limit($limit)->get(['id', 'customer_id', 'ticket_id', 'trigger', 'cause_code', 'confidence', 'rules_version', 'created_at']);
        if ($meta->isEmpty()) {
            return;
        }

        $tickets = $this->tickets($meta->pluck('ticket_id')->filter()->unique()->values()->all());
        $scored = $this->firstTimelyPerTicket($meta, $tickets);
        $identities = $this->identities($meta->pluck('customer_id')->filter()->unique()->values()->all());

        foreach ($meta->chunk(self::CHUNK) as $chunk) {
            $ids = $chunk->pluck('id')->all();
            $contexts = DB::table('outage_diagnoses')->whereIn('id', $ids)->pluck('context', 'id');
            $outcomes = $this->outcomeLabels($ids);
            foreach ($chunk as $m) {
                $ctx = json_decode((string) ($contexts[$m->id] ?? ''), true);
                if (! is_array($ctx)) {
                    continue;
                }
                $labels = $outcomes[(int) $m->id] ?? [];
                $ticket = $m->ticket_id !== null ? ($tickets[(int) $m->ticket_id] ?? null) : null;
                $timely = true;
                if ($ticket !== null) {
                    $timely = Carbon::parse($m->created_at, 'UTC')->lte($ticket['created_at']->copy()->addMinutes(PrecisionReport::TIMELY_MINUTES));
                    if (($scored[(int) $m->ticket_id] ?? null) === (int) $m->id && $ticket['closed']) {
                        array_unshift($labels, $ticket['label']);
                    }
                }

                yield [
                    'id' => 'd'.$m->id,
                    'diagnosis_id' => (int) $m->id,
                    'trigger' => (string) $m->trigger,
                    'created_at' => Carbon::parse($m->created_at, 'UTC')->utc()->toIso8601String(),
                    'customer_id' => $m->customer_id !== null ? (int) $m->customer_id : null,
                    'router_id' => $ctx['customer']['router_id'] ?? null,
                    'stored_cause' => $m->cause_code,
                    'stored_conf' => $m->confidence,
                    'stored_version' => $m->rules_version,
                    'timely' => $timely,
                    'context' => CorpusScrubber::scrubContext($ctx, $identities[(int) ($m->customer_id ?? 0)] ?? []),
                    'labels' => $labels,
                ];
            }
        }
    }

    /**
     * @param  list<int>  $ids
     * @return array<int,array{created_at:Carbon,closed:bool,label:array<string,mixed>}>
     */
    private function tickets(array $ids): array
    {
        $out = [];
        if ($ids === [] || ! OutageRootCause::columnReady()) {
            return $out;
        }
        $detail = OutageRootCause::detailReady();
        foreach (array_chunk($ids, 500) as $chunk) {
            $rows = SupportTicket::query()->whereIn('id', $chunk)->where('type', 'incident')
                ->get(['id', 'status', 'root_cause', 'created_at', 'resolved_at', ...($detail ? ['root_cause_source'] : [])]);
            foreach ($rows as $t) {
                $created = Carbon::instance($t->created_at)->utc();
                $closed = in_array((string) $t->status, OutageRootCause::CLOSING_STATUSES, true) && trim((string) $t->root_cause) !== '';
                $source = $detail ? trim((string) $t->root_cause_source) : '';
                $bulk = $source === 'admin' && $t->resolved_at !== null && Carbon::instance($t->resolved_at)->gt($created->copy()->addDays(PrecisionReport::BULK_CLOSE_DAYS));
                $out[(int) $t->id] = [
                    'created_at' => $created,
                    'closed' => $closed,
                    'label' => ['kind' => 'ticket', 'cause' => trim((string) $t->root_cause), 'source' => $source !== '' ? $source : 'none', 'bulk' => $bulk],
                ];
            }
        }

        return $out;
    }

    /**
     * ticket id => diagnosis id of its first timely prediction (ticket_open, else the first manual_check).
     *
     * @param  Collection<int,object>  $meta
     * @param  array<int,array{created_at:Carbon,closed:bool,label:array<string,mixed>}>  $tickets
     * @return array<int,int>
     */
    private function firstTimelyPerTicket($meta, array $tickets): array
    {
        $open = [];
        $manual = [];
        foreach ($meta as $m) {
            $tid = (int) ($m->ticket_id ?? 0);
            if ($tid === 0 || ! isset($tickets[$tid]) || $m->trigger === OutageDiagnosis::TRIGGER_AUTO_EPISODE) {
                continue;
            }
            $timely = Carbon::parse($m->created_at, 'UTC')->lte($tickets[$tid]['created_at']->copy()->addMinutes(PrecisionReport::TIMELY_MINUTES));
            if (! $timely) {
                continue;
            }
            if ($m->trigger === OutageDiagnosis::TRIGGER_TICKET_OPEN) {
                $open[$tid] ??= (int) $m->id;
            } else {
                $manual[$tid] ??= (int) $m->id;
            }
        }

        return $open + $manual;
    }

    /**
     * @param  list<int>  $diagnosisIds
     * @return array<int,list<array<string,mixed>>>
     */
    private function outcomeLabels(array $diagnosisIds): array
    {
        $out = [];
        if (! Schema::hasTable('outage_outcome_labels')) {
            return $out;
        }
        $rows = DB::table('outage_outcome_labels')->whereIn('diagnosis_id', $diagnosisIds)->get(['diagnosis_id', 'outcome', 'signature', 'independent']);
        foreach ($rows as $r) {
            $out[(int) $r->diagnosis_id][] = ['kind' => 'outcome', 'cause' => (string) $r->outcome, 'signature' => (string) $r->signature, 'independent' => (bool) $r->independent];
        }

        return $out;
    }

    /**
     * Values to cut out of the free-text strings of a customer's context (the PPPoE user, name, phone).
     *
     * @param  list<int>  $customerIds
     * @return array<int,list<string>>
     */
    private function identities(array $customerIds): array
    {
        $out = [];
        foreach (array_chunk($customerIds, 500) as $chunk) {
            foreach (DB::table('customers')->whereIn('id', $chunk)->get(['id', 'pppoe_user', 'name', 'phone']) as $c) {
                $out[(int) $c->id] = array_values(array_filter([(string) $c->pppoe_user, (string) $c->name, (string) $c->phone], fn (string $s) => $s !== ''));
            }
        }

        return $out;
    }
}
