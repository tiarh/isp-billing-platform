<?php

namespace App\Services\Outage\Diagnosis;

use App\Enums\OutageRootCause;
use App\Models\OutageDiagnosis;
use App\Models\SupportTicket;
use App\Models\TicketActivity;
use App\Services\EvolutionApiService;
use App\Services\Outage\OutageSettings;
use App\Services\Outage\OutageShadowNotifier;
use App\Support\WaGroupJid;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * RCA-4 weekly precision report: how often the FIRST diagnosis of an incident ticket matches the cause the
 * technician picked when closing it.
 *
 *  - prediction = the first diagnosis linked to the ticket: `ticket_open`, else the first `manual_check`
 *    (auto_refresh / auto_episode never count: they would let the system grade itself on later evidence).
 *  - truth = support_tickets.root_cause of incident tickets closed (resolved_at) in the WIB week.
 *  - "Ditutup otomatis" (closed without a cause) and "Tidak tahu" are counted separately and excluded.
 *  - a prediction of "unknown" / "data_blind" is an abstention: not part of any precision, but a miss for recall.
 *  - (2026-10-26) honest scoring: each prediction is scored under its OWN rules_version (`by_version`); a prediction
 *    written more than 15 minutes after its ticket is "backfilled" (counted, not scored); admin labels set more than
 *    7 days after the ticket was opened are "bulk_closed" (counted, not scored); strict class and family (fibre =
 *    drop_los + optical_degrade + odp_cut) are both reported, with a Wilson 95 % interval and coverage.
 */
class PrecisionReport
{
    public const ABSTAIN = ['unknown', 'data_blind'];

    /** Monday 00:00 WIB of the week that contains $at. */
    public static function weekStart(Carbon $at): Carbon
    {
        return $at->copy()->timezone('Asia/Jakarta')->startOfWeek(Carbon::MONDAY)->startOfDay();
    }

    /**
     * @return array<string,mixed>
     */
    public function compute(Carbon $weekStartWib): array
    {
        $start = $weekStartWib->copy()->timezone('Asia/Jakarta')->startOfDay();
        $end = $start->copy()->addWeek();
        $week = $this->evaluate($start, $end);
        $rolling = $this->evaluate($end->copy()->subWeeks(4), $end);
        $calStart = $end->copy()->subWeeks(self::CALIBRATION_WEEKS);

        return [
            'week_start' => $start->format('Y-m-d'),
            'week_end' => $end->copy()->subDay()->format('Y-m-d'),
            'rules_version' => Rules::RULES_VERSION,
            'generated_at' => now()->utc()->toIso8601String(),
            'tickets' => $week['tickets'],
            'closed_auto' => $week['closed_auto'],
            'truth_unknown' => $week['truth_unknown'],
            'truth_not_scored' => $week['truth_not_scored'],
            'no_prediction' => $week['no_prediction'],
            'backfilled' => $week['backfilled'],
            'bulk_closed' => $week['bulk_closed'],
            'labels' => $week['labels'],
            'abstained' => $week['abstained'],
            'evaluated' => $week['evaluated'],
            'predicted' => $week['predicted'],
            'correct' => $week['correct'],
            'correct_family' => $week['correct_family'],
            'coverage' => $week['coverage'],
            'ci' => $week['ci'],
            'ci_family' => $week['ci_family'],
            'by_version' => $week['by_version'],
            'by_source' => $week['by_source'],
            'classes' => $week['classes'],
            'confusions' => $week['confusions'],
            'rolling' => [
                'weeks' => 4, 'from' => $end->copy()->subWeeks(4)->format('Y-m-d'),
                'evaluated' => $rolling['evaluated'], 'predicted' => $rolling['predicted'], 'correct' => $rolling['correct'], 'correct_family' => $rolling['correct_family'],
                'coverage' => $rolling['coverage'], 'ci' => $rolling['ci'], 'ci_family' => $rolling['ci_family'], 'by_version' => $rolling['by_version'],
                'backfilled' => $rolling['backfilled'], 'bulk_closed' => $rolling['bulk_closed'], 'classes' => $rolling['classes'],
            ],
            'fill' => $this->fill($start, $end),
            'outcomes' => $this->outcomes($calStart, $end),
            'calibration' => $this->calibrationRows($calStart, $end),
            'disagreements' => $this->disagreements($calStart, $end),
        ];
    }

    /** Weeks the calibration looks back (ticket labels and independent outcome labels). */
    public const CALIBRATION_WEEKS = 8;

    /** "Pasti" needs this precision, this many independent checks (tickets included), this many tickets and this Wilson floor. */
    public const PASTI_PRECISION = 0.90;

    public const PASTI_MIN_N = 30;

    public const PASTI_MIN_TICKETS = 10;

    public const PASTI_WILSON_LOW = 0.75;

    /** A class this far under 50 % on this many checks is "belum tahu". */
    public const WEAK_PRECISION = 0.50;

    public const WEAK_MIN_N = 10;

    public const DISAGREE_MAX = 60;

    /**
     * Calibration of every predicted class over the window, scored on the EXACT cause (the family hit is a secondary
     * column, correct_family / precision_family; the headline family number is unchanged): how many tickets
     * and INDEPENDENT outcome labels checked it, and how many agreed. Circular labels (power_only) are counted next to
     * it but never scored. A prediction already scored through its ticket is not counted a second time as an outcome.
     *
     * @return list<array<string,mixed>>
     */
    public function calibrationRows(Carbon $startWib, Carbon $endWib): array
    {
        $eval = $this->evaluate($startWib, $endWib);
        // Votes: a manual ticket close, an independent outcome label, a circular one. One physical repair of one customer
        // is ONE vote, however many tickets, episodes and signatures describe it: votes of a customer whose windows touch
        // are merged. Ticket > independent > circular. A close made with the "Saran dari data" chip is not an independent
        // human check (the data suggested it): it only counts through its outcome label.
        $chip = $eval['scored'] === [] ? [] : $this->usedSuggestion(array_column($eval['scored'], 'ticket_id'), 'signature');
        $votes = [];
        foreach ($eval['scored'] as $s) {
            $votes[] = [
                'key' => $s['customer_id'] !== null ? 'c'.$s['customer_id'] : 't'.$s['ticket_id'], 'start' => $s['start']->copy()->subHours(24), 'end' => $s['end']->copy()->addHours(26),
                'kind' => isset($chip[$s['ticket_id']]) ? 'chip' : 'ticket', 'cause' => $s['cause'], 'hit' => $s['hit'], 'exact' => (bool) ($s['hit_exact'] ?? false),
            ];
        }
        if (Schema::hasTable('outage_outcome_labels') && Schema::hasTable('outage_diagnoses')) {
            $labels = DB::table('outage_outcome_labels as l')->join('outage_diagnoses as d', 'd.id', '=', 'l.diagnosis_id')
                ->where('l.final', 1)->whereNotNull('l.diagnosis_id')->where('d.state', OutageDiagnosis::STATE_DONE)
                ->where('l.labelled_at', '>=', $startWib->copy()->utc()->format('Y-m-d H:i:s'))->where('l.labelled_at', '<', $endWib->copy()->utc()->format('Y-m-d H:i:s'))
                ->orderBy('l.id')->limit(20000)->get(['l.diagnosis_id', 'l.outcome', 'l.independent', 'l.customer_id', 'l.window_from', 'l.window_to', 'd.cause_code']);
            foreach ($labels as $l) {
                if (in_array((string) $l->cause_code, self::ABSTAIN, true) || $l->window_from === null || $l->window_to === null) {
                    continue;
                }
                $votes[] = [
                    'key' => $l->customer_id !== null ? 'c'.$l->customer_id : 'd'.$l->diagnosis_id, 'start' => Carbon::parse($l->window_from, 'UTC'), 'end' => Carbon::parse($l->window_to, 'UTC'),
                    'kind' => (bool) $l->independent ? 'independent' : 'circular', 'cause' => (string) $l->cause_code, 'hit' => self::familyOf((string) $l->cause_code) === self::familyOf((string) $l->outcome),
                    'exact' => (string) $l->cause_code === (string) $l->outcome,
                ];
            }
        }
        $rows = [];
        $byKey = [];
        foreach ($votes as $v) {
            $byKey[$v['key']][] = $v;
        }
        foreach ($byKey as $list) {
            usort($list, fn (array $a, array $b) => $a['start']->getTimestamp() <=> $b['start']->getTimestamp());
            $clusters = [];
            foreach ($list as $v) {
                $last = array_key_last($clusters);
                if ($last !== null && $v['start']->lte($clusters[$last]['end'])) {
                    $clusters[$last]['votes'][] = $v;
                    $clusters[$last]['end'] = $v['end']->gt($clusters[$last]['end']) ? $v['end'] : $clusters[$last]['end'];
                } else {
                    $clusters[] = ['end' => $v['end'], 'votes' => [$v]];
                }
            }
            foreach ($clusters as $cluster) {
                $pick = null;
                foreach (['ticket', 'independent', 'circular'] as $kind) {
                    foreach ($cluster['votes'] as $v) {
                        if ($v['kind'] === $kind) {
                            $pick = $v;
                            break 2;
                        }
                    }
                }
                if ($pick === null) {
                    continue; // only chip closes: no check of its own
                }
                $rows[$pick['cause']] ??= ['n_ticket' => 0, 'n_independent' => 0, 'n_circular' => 0, 'correct' => 0, 'correct_family' => 0];
                if ($pick['kind'] === 'circular') {
                    $rows[$pick['cause']]['n_circular']++;
                } else {
                    $rows[$pick['cause']][$pick['kind'] === 'ticket' ? 'n_ticket' : 'n_independent']++;
                    // "correct" is the EXACT cause (owner 2026-10-28, review-621 L3): a family hit with another cause does not
                    // count toward "pasti". The family hit is kept as a secondary column.
                    $rows[$pick['cause']]['correct'] += $pick['exact'] ? 1 : 0;
                    $rows[$pick['cause']]['correct_family'] += $pick['hit'] ? 1 : 0;
                }
            }
        }

        $out = [];
        foreach ($rows as $cause => $r) {
            $n = $r['n_ticket'] + $r['n_independent'];
            $w = self::wilson($r['correct'], $n);
            $precision = $n > 0 ? $r['correct'] / $n : null;
            $out[] = [
                'cause' => $cause, 'label' => OutageRootCause::labelFor($cause), 'family' => self::familyOf($cause),
                'n_ticket' => $r['n_ticket'], 'n_independent' => $r['n_independent'], 'n_circular' => $r['n_circular'], 'correct' => $r['correct'],
                'correct_family' => $r['correct_family'], 'precision_family' => $n > 0 ? round($r['correct_family'] / $n, 4) : null,
                'precision' => $precision !== null ? round($precision, 4) : null, 'wilson_low' => $w['low'] ?? null,
                'state' => self::calibrationState($cause, $r['n_ticket'], $r['n_independent'], $r['correct']),
            ];
        }
        usort($out, fn (array $a, array $b) => [$b['n_ticket'] + $b['n_independent'], $a['cause']] <=> [$a['n_ticket'] + $a['n_independent'], $b['cause']]);

        return $out;
    }

    /** pasti | kemungkinan | belum_tahu (plan 2.3; the same gate as G3). */
    public static function calibrationState(string $cause, int $nTicket, int $nIndependent, int $correct): string
    {
        if (in_array($cause, self::ABSTAIN, true)) {
            return 'belum_tahu';
        }
        $n = $nTicket + $nIndependent;
        $precision = $n > 0 ? $correct / $n : 0.0;
        $low = self::wilson($correct, $n)['low'] ?? 0.0;
        if ($precision >= self::PASTI_PRECISION && $n >= self::PASTI_MIN_N && $nTicket >= self::PASTI_MIN_TICKETS && $low >= self::PASTI_WILSON_LOW) {
            return 'pasti';
        }
        if ($n >= self::WEAK_MIN_N && $precision < self::WEAK_PRECISION) {
            return 'belum_tahu';
        }

        return 'kemungkinan';
    }

    /**
     * Outcome labels written in the window, per class: independent (scored) and circular (shown only).
     *
     * @return array<string,mixed>
     */
    public function outcomes(Carbon $startWib, Carbon $endWib): array
    {
        $out = ['independent' => 0, 'circular' => 0, 'by_class' => []];
        if (! Schema::hasTable('outage_outcome_labels')) {
            return $out;
        }
        $rows = DB::table('outage_outcome_labels')->where('final', 1)
            ->where('labelled_at', '>=', $startWib->copy()->utc()->format('Y-m-d H:i:s'))->where('labelled_at', '<', $endWib->copy()->utc()->format('Y-m-d H:i:s'))
            ->groupBy('outcome', 'independent')->selectRaw('outcome, independent, COUNT(*) as n')->get();
        $by = [];
        foreach ($rows as $r) {
            $by[$r->outcome] ??= ['cause' => (string) $r->outcome, 'label' => OutageRootCause::labelFor((string) $r->outcome), 'independent' => 0, 'circular' => 0];
            if ((bool) $r->independent) {
                $by[$r->outcome]['independent'] += (int) $r->n;
                $out['independent'] += (int) $r->n;
            } else {
                $by[$r->outcome]['circular'] += (int) $r->n;
                $out['circular'] += (int) $r->n;
            }
        }
        usort($by, fn (array $a, array $b) => [$b['independent'] + $b['circular'], $a['cause']] <=> [$a['independent'] + $a['circular'], $b['cause']]);
        $out['by_class'] = array_values($by);

        return $out;
    }

    /**
     * "Label beda dengan data": a technician or admin picked a cause outside the family of what the data says fixed the
     * ticket. For weekly review only; nothing is changed. Ticket code and the two words, no customer.
     *
     * @return list<array<string,mixed>>
     */
    public function disagreements(Carbon $startWib, Carbon $endWib): array
    {
        if (! Schema::hasTable('outage_outcome_labels') || ! OutageRootCause::columnReady()) {
            return [];
        }
        $rows = DB::table('outage_outcome_labels as l')->join('support_tickets as t', function ($j): void {
            $j->on('t.id', '=', 'l.subject_id')->where('l.subject_type', '=', 'ticket');
        })->where('l.independent', 1)->where('l.final', 1)
            ->where('l.labelled_at', '>=', $startWib->copy()->utc()->format('Y-m-d H:i:s'))->where('l.labelled_at', '<', $endWib->copy()->utc()->format('Y-m-d H:i:s'))
            ->whereNotNull('t.root_cause')->where('t.root_cause', '<>', '')
            ->orderByDesc('l.id')->limit(400)->get(['t.id as ticket_id', 't.code', 't.root_cause', 'l.outcome', 'l.signature', 'l.labelled_at']);
        $out = [];
        foreach ($rows as $r) {
            $cause = (string) $r->root_cause;
            if (in_array($cause, [...OutageRootCause::NOT_SCORED, ...OutageRootCause::NO_FAULT], true) || self::familyOf($cause) === self::familyOf((string) $r->outcome)) {
                continue;
            }
            $out[] = [
                'ticket_id' => (int) $r->ticket_id, 'code' => $r->code !== null ? (string) $r->code : null,
                'label' => OutageRootCause::ticketLabel($cause), 'data' => OutageRootCause::labelFor((string) $r->outcome), 'signature' => (string) $r->signature,
            ];
            if (count($out) >= self::DISAGREE_MAX) {
                break;
            }
        }

        return $out;
    }

    /** Upsert the calibration rows of a week (the Monday run). @param  list<array<string,mixed>>  $rows */
    private function storeCalibration(string $weekStart, array $rows): void
    {
        if (! Schema::hasTable('outage_diagnosis_calibration')) {
            return;
        }
        $now = now();
        foreach ($rows as $r) {
            DB::table('outage_diagnosis_calibration')->updateOrInsert(
                ['week_start' => $weekStart, 'cause_code' => $r['cause']],
                [
                    'family' => $r['family'], 'n_ticket' => $r['n_ticket'], 'n_independent' => $r['n_independent'], 'n_circular' => $r['n_circular'], 'correct' => $r['correct'],
                    'precision_rate' => $r['precision'], 'wilson_low' => $r['wilson_low'], 'state' => $r['state'], 'updated_at' => $now, 'created_at' => $now,
                ],
            );
        }
        // a class that has no row this week must not keep an older state under the same week key
        DB::table('outage_diagnosis_calibration')->where('week_start', $weekStart)->whereNotIn('cause_code', array_column($rows, 'cause') ?: ['-'])->delete();
    }

    /**
     * Calibration state per class of the newest stored week: cause => state. Empty before the first Monday run.
     *
     * @return array<string,string>
     */
    public static function latestCalibration(): array
    {
        $minute = intdiv(now()->getTimestamp(), 60);
        if (self::$calibrationMemo !== null && self::$calibrationMemo['at'] === $minute) {
            return self::$calibrationMemo['rows'];
        }
        $rows = [];
        if (Schema::hasTable('outage_diagnosis_calibration')) {
            $week = DB::table('outage_diagnosis_calibration')->max('week_start');
            if ($week !== null) {
                $rows = DB::table('outage_diagnosis_calibration')->where('week_start', $week)->pluck('state', 'cause_code')->all();
            }
        }
        self::$calibrationMemo = ['at' => $minute, 'rows' => $rows];

        return $rows;
    }

    /** @var array{at:int,rows:array<string,string>}|null per-minute memo of latestCalibration() */
    private static ?array $calibrationMemo = null;

    /** Test hook: forget the memo of latestCalibration(). */
    public static function forgetLatestCalibration(): void
    {
        self::$calibrationMemo = null;
    }

    /**
     * Prediction families: the action is the same inside a family ("Kirim teknisi FO"), so a hit inside the family is
     * a family hit. Every other class is its own family. The strict class is always reported next to it.
     */
    public const FAMILIES = [
        'fibre' => ['drop_los', 'optical_degrade', 'odp_cut'],
    ];

    /** A prediction (or a label) that is later than this after the ticket was opened is not a prediction of it (minutes). */
    public const TIMELY_MINUTES = 15;

    /** An admin label this many days after the ticket was opened is a bulk close: reported, not scored. */
    public const BULK_CLOSE_DAYS = 7;

    public static function familyOf(string $cause): string
    {
        foreach (self::FAMILIES as $family => $members) {
            if (in_array($cause, $members, true)) {
                return $family;
            }
        }

        return $cause;
    }

    /**
     * Wilson 95 % interval of k hits in n tries. 2 of 8 is 7 to 59 %, which cannot be told from 50 %.
     *
     * @return array{low:float,high:float}|null
     */
    public static function wilson(int $k, int $n): ?array
    {
        if ($n <= 0) {
            return null;
        }
        $z = 1.96;
        $p = $k / $n;
        $den = 1 + $z * $z / $n;
        $centre = ($p + $z * $z / (2 * $n)) / $den;
        $half = $z * sqrt(($p * (1 - $p) + $z * $z / (4 * $n)) / $n) / $den;

        return ['low' => round(max(0.0, $centre - $half), 4), 'high' => round(min(1.0, $centre + $half), 4)];
    }

    /**
     * How often the cause was filled when an incident ticket was closed in the week (plan ticket-root-cause-required).
     * Closes made by the system (source "system" or "Ditutup otomatis") are counted apart and left out of the rate.
     *
     * @return array<string,mixed>
     */
    public function fill(Carbon $startWib, Carbon $endWib): array
    {
        $out = [
            'closes' => 0, 'human' => 0, 'by_source' => ['technician' => 0, 'admin' => 0, 'api' => 0, 'system' => 0, 'none' => 0],
            'real' => 0, 'not_fault' => 0, 'self_recovered' => 0, 'other' => 0, 'unknown' => 0, 'system' => 0, 'empty' => 0,
            'filled' => 0, 'fill_rate' => null, 'top' => [],
            'suggestion' => ['shown' => 0, 'agreed' => 0, 'used' => 0, 'agreed_manual' => 0],
            'signature_used' => 0, // closes that used the "Saran dari data" chip

        ];
        if (! OutageRootCause::columnReady()) {
            return $out;
        }

        $detail = OutageRootCause::detailReady();
        $columns = ['id', 'root_cause', ...($detail ? ['root_cause_source', 'root_cause_suggested'] : [])];
        $tickets = SupportTicket::query()->where('type', 'incident')->whereIn('status', ['resolved', 'closed'])
            ->where('resolved_at', '>=', $startWib->copy()->utc())->where('resolved_at', '<', $endWib->copy()->utc())
            ->get($columns);
        $out['closes'] = $tickets->count();

        $causes = [];
        $suggested = [];
        foreach ($tickets as $t) {
            $code = trim((string) $t->root_cause);
            $source = $detail ? trim((string) $t->root_cause_source) : '';
            $bucket = in_array($source, ['technician', 'admin', 'api', 'system'], true) ? $source : 'none';
            if ($code === OutageRootCause::AutoClosed->value) {
                $bucket = 'system';
            }
            $out['by_source'][$bucket]++;

            if ($bucket === 'system') {
                $out['system']++; // closed by the system (auto_closed, or a triage close), left out of the rate

                continue;
            }
            $out['human']++;
            if ($code === '') {
                $out['empty']++;

                continue;
            }
            if ($code === OutageRootCause::Unknown->value) {
                // "Tidak tahu" is an answer, but not a clear cause.
                $out['unknown']++;
            } elseif ($code === OutageRootCause::NotFault->value) {
                $out['not_fault']++;
            } elseif ($code === OutageRootCause::SelfRecovered->value) {
                $out['self_recovered']++;
            } elseif ($code === OutageRootCause::Other->value) {
                $out['other']++;
            } else {
                $out['real']++;
                $causes[$code] = ($causes[$code] ?? 0) + 1;
            }
            if ($detail && trim((string) $t->root_cause_suggested) !== '') {
                $suggested[(int) $t->id] = trim((string) $t->root_cause_suggested) === $code;
            }
        }

        // "Penyebab jelas" = a real cause, "Bukan gangguan", "Pulih sendiri" or "Lainnya" with a note; "Tidak tahu" is not.
        $out['filled'] = $out['real'] + $out['not_fault'] + $out['self_recovered'] + $out['other'];
        $out['fill_rate'] = $out['human'] > 0 ? round($out['filled'] / $out['human'], 4) : null;

        arsort($causes);
        foreach (array_slice($causes, 0, 3, true) as $code => $count) {
            $out['top'][] = ['cause' => $code, 'label' => OutageRootCause::ticketLabel($code), 'count' => $count];
        }

        if ($suggested !== []) {
            $viaSuggestion = $this->usedSuggestion(array_keys($suggested));
            $out['suggestion']['shown'] = count($suggested);
            foreach ($suggested as $id => $agreed) {
                if (isset($viaSuggestion[$id])) {
                    $out['suggestion']['used']++;
                } elseif ($agreed) {
                    $out['suggestion']['agreed_manual']++;
                }
                if ($agreed) {
                    $out['suggestion']['agreed']++;
                }
            }
        }
        // "Saran dari data" chip (repair signature): closes that used it
        $out['signature_used'] = $tickets->isEmpty() ? 0 : count($this->usedSuggestion($tickets->pluck('id')->map(fn ($i) => (int) $i)->all(), 'signature'));

        return $out;
    }

    /**
     * Ticket ids whose cause was set with the "Pakai saran" chip.
     *
     * @param  list<int>  $ticketIds
     * @return array<int,true>
     */
    private function usedSuggestion(array $ticketIds, string $via = 'suggestion'): array
    {
        $out = [];
        foreach (array_chunk($ticketIds, 500) as $chunk) {
            $rows = TicketActivity::query()->whereIn('ticket_id', $chunk)->where('action', 'root_cause_set')->get(['ticket_id', 'new_value']);
            foreach ($rows as $row) {
                if (is_array($row->new_value) && ($row->new_value['via'] ?? null) === $via) {
                    $out[(int) $row->ticket_id] = true;
                }
            }
        }

        return $out;
    }

    public const FILL_TARGET_PERCENT = 90;

    private const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

    /**
     * Weekly "Penyebab tiket gangguan" text for the Skynet Report group: counts only, no customer, ticket or
     * technician names. Null for a stored report that predates the fill block.
     *
     * @param  array<string,mixed>  $r
     */
    public function rootCauseText(array $r): ?string
    {
        $f = $r['fill'] ?? null;
        if (! is_array($f)) {
            return null;
        }

        $from = Carbon::parse((string) $r['week_start']);
        $to = Carbon::parse((string) $r['week_end']);
        $range = $from->month === $to->month
            ? $from->day.'–'.$to->day.' '.self::MONTHS[$to->month - 1]
            : $from->day.' '.self::MONTHS[$from->month - 1].'–'.$to->day.' '.self::MONTHS[$to->month - 1];
        $lines = ['Penyebab tiket gangguan '.$range];

        if ((int) $f['closes'] === 0) {
            $lines[] = 'Tidak ada tiket gangguan yang ditutup.';

            return implode("\n", $lines);
        }

        $s = $f['by_source'];
        $who = ['teknisi '.$s['technician'], 'admin '.$s['admin']];
        if ($s['api'] > 0) {
            $who[] = 'API '.$s['api'];
        }
        $who[] = 'otomatis '.$s['system'];
        if ($s['none'] > 0) {
            $who[] = 'tanpa sumber '.$s['none'];
        }
        $lines[] = 'Ditutup: '.$f['closes'].' ('.implode(', ', $who).')';

        if ((int) $f['human'] > 0) {
            $lines[] = 'Penyebab jelas: '.$f['filled'].' dari '.$f['human'].' ('.round(((float) $f['fill_rate']) * 100).'%). Target '.self::FILL_TARGET_PERCENT.'%.';
            $lines[] = 'Tidak tahu '.$f['unknown'].' · Lainnya '.$f['other'].' · Bukan gangguan '.$f['not_fault'].' · Pulih sendiri '.$f['self_recovered'];
            if ((int) $f['empty'] > 0) {
                $lines[] = 'Belum diisi: '.$f['empty'];
            }
        }
        if ($f['top'] !== []) {
            $lines[] = 'Terbanyak: '.implode(', ', array_map(fn (array $c): string => $c['label'].' '.$c['count'], $f['top']));
        }

        return implode("\n", array_merge($lines, $this->diagnosisLines($r)));
    }

    /**
     * The diagnosis part of the weekly text: the current rules only, with the interval; older rules apart; what was
     * left out of the score. Short plain Indonesian, counts only.
     *
     * @param  array<string,mixed>  $r
     * @return list<string>
     */
    private function diagnosisLines(array $r): array
    {
        $version = (string) ($r['rules_version'] ?? Rules::RULES_VERSION);
        $cur = null;
        $oldCorrect = 0;
        $oldPredicted = 0;
        foreach ((array) ($r['by_version'] ?? []) as $v) {
            if ((string) $v['version'] === $version) {
                $cur = $v;
            } else {
                $oldCorrect += (int) $v['correct'];
                $oldPredicted += (int) $v['predicted'];
            }
        }
        if (! array_key_exists('by_version', $r)) { // a report stored before the version split
            $guessed = max(0, (int) $r['evaluated'] - (int) $r['abstained']);

            return [$guessed > 0
                ? 'Diagnosa sistem cocok: '.$r['correct'].' dari '.$guessed.' yang ditebak ('.round(100 * $r['correct'] / $guessed).'%)'
                : 'Diagnosa sistem cocok: belum ada yang bisa dinilai'];
        }

        $lines = [];
        if ($cur !== null && (int) $cur['predicted'] > 0) {
            $ci = is_array($cur['ci'] ?? null) ? ', kisaran '.round($cur['ci']['low'] * 100).'–'.round($cur['ci']['high'] * 100).'%' : '';
            $lines[] = 'Diagnosa sistem (aturan terbaru): cocok '.$cur['correct'].' dari '.$cur['predicted'].' yang ditebak ('.round(100 * $cur['correct'] / $cur['predicted']).'%'.$ci.')';
            $lines[] = 'Kabel drop, redaman dan ODP dihitung satu jenis: '.$cur['correct_family'].' dari '.$cur['predicted'].' ('.round(100 * $cur['correct_family'] / $cur['predicted']).'%)';
        } else {
            $lines[] = 'Diagnosa sistem (aturan terbaru): belum ada yang bisa dinilai';
        }
        if ($cur !== null && (int) $cur['evaluated'] > 0) {
            $lines[] = 'Ditebak '.$cur['predicted'].' dari '.$cur['evaluated'].' tiket ('.round(100 * $cur['predicted'] / $cur['evaluated']).'%)';
        }
        if ($oldPredicted > 0) {
            $lines[] = 'Aturan lama: cocok '.$oldCorrect.' dari '.$oldPredicted;
        }
        $late = (int) ($r['backfilled'] ?? 0);
        $bulk = (int) ($r['bulk_closed'] ?? 0);
        if ($late + $bulk > 0) {
            $lines[] = 'Tidak dinilai: tebakan telat '.$late.', ditutup massal admin '.$bulk;
        }

        return $lines;
    }

    /**
     * @return array<string,mixed>
     */
    private function evaluate(Carbon $startWib, Carbon $endWib): array
    {
        $out = [
            'tickets' => 0, 'closed_auto' => 0, 'truth_unknown' => 0, 'truth_not_scored' => 0, 'no_prediction' => 0,
            'backfilled' => 0, 'bulk_closed' => 0, 'labels' => ['technician' => 0, 'admin' => 0, 'admin_bulk' => 0, 'other' => 0],
            'abstained' => 0, 'evaluated' => 0, 'predicted' => 0, 'correct' => 0, 'correct_family' => 0, 'coverage' => null, 'ci' => null, 'ci_family' => null,
            'by_version' => [], 'by_source' => ['deteksi_manual' => ['evaluated' => 0, 'correct' => 0]], 'classes' => [], 'confusions' => [],
            'scored_diagnosis_ids' => [], 'scored' => [],
        ];
        if (! OutageRootCause::columnReady() || ! Schema::hasTable('outage_diagnoses')) {
            return $out;
        }
        $detail = OutageRootCause::detailReady();
        $tickets = SupportTicket::query()->where('type', 'incident')->whereIn('status', ['resolved', 'closed'])
            ->where('resolved_at', '>=', $startWib->copy()->utc())->where('resolved_at', '<', $endWib->copy()->utc())
            ->get(['id', 'customer_id', 'root_cause', 'meta', 'created_at', 'resolved_at', ...($detail ? ['root_cause_source'] : [])]);
        $out['tickets'] = $tickets->count();
        $predictions = $this->predictionsFor($tickets);

        $pred = [];   // class => predicted count
        $hit = [];    // class => correct count
        $hitFamily = []; // class => correct inside the family
        $truth = [];  // class => truth count (evaluated tickets)
        $conf = [];   // "pred>actual" => count
        $versions = []; // rules_version => counts
        foreach ($tickets as $t) {
            $actual = trim((string) $t->root_cause);
            if ($actual === '' || $actual === OutageRootCause::AutoClosed->value) {
                $out['closed_auto']++;

                continue;
            }
            if ($actual === OutageRootCause::Unknown->value) {
                $out['truth_unknown']++;

                continue;
            }
            // "Lainnya", "Bukan gangguan" and "Pulih sendiri" say nothing about what the diagnosis should have found.
            if (in_array($actual, [OutageRootCause::Other->value, ...OutageRootCause::NO_FAULT], true)) {
                $out['truth_not_scored']++;

                continue;
            }
            // Who wrote the label. An admin label long after the ticket was opened is a bulk close (the cause was not
            // known when it was opened): reported, not scored.
            $source = $detail ? trim((string) $t->root_cause_source) : '';
            $bulk = $source === 'admin' && $t->created_at !== null && $t->resolved_at !== null
                && Carbon::instance($t->resolved_at)->gt(Carbon::instance($t->created_at)->addDays(self::BULK_CLOSE_DAYS));
            $out['labels'][$bulk ? 'admin_bulk' : (in_array($source, ['technician', 'admin'], true) ? $source : 'other')]++;
            if ($bulk) {
                $out['bulk_closed']++;

                continue;
            }
            $p = $predictions[(int) $t->id] ?? null;
            if ($p === null) {
                $out['no_prediction']++;

                continue;
            }
            if ($p['late']) {
                $out['backfilled']++; // written long after the ticket: not a prediction of it

                continue;
            }
            $cause = (string) $p['cause'];
            $version = (string) ($p['version'] ?: 'unknown');
            if (isset($p['id'])) {
                $out['scored_diagnosis_ids'][(int) $p['id']] = true;
            }
            $versions[$version] ??= ['evaluated' => 0, 'abstained' => 0, 'correct' => 0, 'correct_family' => 0];
            $out['evaluated']++;
            $versions[$version]['evaluated']++;
            // Tickets made by the Deteksi Gangguan button are counted on their own too (they are not a human report).
            $fromDeteksi = is_array($t->meta) && ($t->meta['source'] ?? null) === 'deteksi_manual';
            if ($fromDeteksi) {
                $out['by_source']['deteksi_manual']['evaluated']++;
            }
            $truth[$actual] = ($truth[$actual] ?? 0) + 1;
            if (in_array($cause, self::ABSTAIN, true)) {
                $out['abstained']++;
                $versions[$version]['abstained']++;

                continue;
            }
            $pred[$cause] = ($pred[$cause] ?? 0) + 1;
            // one entry per scored ticket prediction, for the calibration (it counts one vote per customer and repair)
            $out['scored'][] = [
                'ticket_id' => (int) $t->id, 'customer_id' => $t->customer_id !== null ? (int) $t->customer_id : null, 'diagnosis_id' => $p['id'] ?? null,
                'start' => Carbon::instance($t->created_at)->utc(), 'end' => Carbon::instance($t->resolved_at)->utc(),
                'cause' => $cause, 'hit' => self::familyOf($cause) === self::familyOf($actual), 'hit_exact' => $cause === $actual,
            ];
            if (self::familyOf($cause) === self::familyOf($actual)) {
                $out['correct_family']++;
                $versions[$version]['correct_family']++;
                $hitFamily[$cause] = ($hitFamily[$cause] ?? 0) + 1;
            }
            if ($cause === $actual) {
                $hit[$cause] = ($hit[$cause] ?? 0) + 1;
                $out['correct']++;
                $versions[$version]['correct']++;
                if ($fromDeteksi) {
                    $out['by_source']['deteksi_manual']['correct']++;
                }
            } else {
                $p = $cause;
                $key = $p.'>'.$actual;
                $conf[$key] = ($conf[$key] ?? 0) + 1;
            }
        }

        foreach (array_unique(array_merge(array_keys($pred), array_keys($truth))) as $code) {
            $predicted = $pred[$code] ?? 0;
            $correct = $hit[$code] ?? 0;
            $actualCount = $truth[$code] ?? 0;
            $out['classes'][] = [
                'cause' => $code,
                'label' => OutageRootCause::labelFor($code),
                'predicted' => $predicted,
                'correct' => $correct,
                'correct_family' => $hitFamily[$code] ?? 0,
                'precision' => $predicted > 0 ? round($correct / $predicted, 4) : null,
                'actual' => $actualCount,
                'recall' => $actualCount > 0 ? round($correct / $actualCount, 4) : null,
            ];
        }
        usort($out['classes'], fn ($a, $b) => [$b['predicted'], $a['cause']] <=> [$a['predicted'], $b['cause']]);

        arsort($conf);
        foreach ($conf as $key => $count) {
            [$p, $a] = explode('>', $key, 2);
            $out['confusions'][] = ['predicted' => $p, 'predicted_label' => OutageRootCause::labelFor($p), 'actual' => $a, 'actual_label' => OutageRootCause::labelFor($a), 'count' => $count];
        }

        $out['predicted'] = $out['evaluated'] - $out['abstained'];
        $out['coverage'] = $out['evaluated'] > 0 ? round($out['predicted'] / $out['evaluated'], 4) : null;
        $out['ci'] = self::wilson($out['correct'], $out['predicted']);
        $out['ci_family'] = self::wilson($out['correct_family'], $out['predicted']);
        // newest first, rows without a version last
        uksort($versions, fn ($a, $b) => (((string) $a === 'unknown') <=> ((string) $b === 'unknown')) ?: strcmp((string) $b, (string) $a));
        foreach ($versions as $version => $v) {
            $predicted = $v['evaluated'] - $v['abstained'];
            $out['by_version'][] = [
                'version' => (string) $version,
                'evaluated' => $v['evaluated'],
                'abstained' => $v['abstained'],
                'predicted' => $predicted,
                'correct' => $v['correct'],
                'correct_family' => $v['correct_family'],
                'precision' => $predicted > 0 ? round($v['correct'] / $predicted, 4) : null,
                'precision_family' => $predicted > 0 ? round($v['correct_family'] / $predicted, 4) : null,
                'coverage' => $v['evaluated'] > 0 ? round($predicted / $v['evaluated'], 4) : null,
                'ci' => self::wilson($v['correct'], $predicted),
                'ci_family' => self::wilson($v['correct_family'], $predicted),
            ];
        }

        return $out;
    }

    /**
     * ticket id => its first TIMELY prediction (ticket_open, else the first manual_check), with the rules version that
     * made it. A prediction written more than TIMELY_MINUTES after the ticket was created is not one (the RCA-4 deploy
     * back-filled `ticket_open` rows for tickets opened days before): such a ticket gets `late` = true and no cause.
     * The context snapshot column is never selected.
     *
     * @param  iterable<SupportTicket>  $tickets  with id and created_at
     * @return array<int,array{id:?int,cause:?string,version:?string,late:bool}>
     */
    public function predictionsFor(iterable $tickets): array
    {
        $created = [];
        foreach ($tickets as $t) {
            $created[(int) $t->id] = $t->created_at !== null ? Carbon::instance($t->created_at) : null;
        }
        $out = [];
        foreach (array_chunk(array_keys($created), 500) as $chunk) {
            $rows = OutageDiagnosis::query()->whereIn('ticket_id', $chunk)->where('state', OutageDiagnosis::STATE_DONE)
                ->whereIn('trigger', [OutageDiagnosis::TRIGGER_TICKET_OPEN, OutageDiagnosis::TRIGGER_MANUAL])
                ->whereNotNull('cause_code')->orderBy('id')->get(['id', 'ticket_id', 'trigger', 'cause_code', 'rules_version', 'created_at']);
            $open = [];
            $manual = [];
            $any = [];
            foreach ($rows as $r) {
                $tid = (int) $r->ticket_id;
                $any[$tid] = true;
                $at = $created[$tid] ?? null;
                $timely = $at === null || $r->created_at === null || Carbon::instance($r->created_at)->lte($at->copy()->addMinutes(self::TIMELY_MINUTES));
                if (! $timely) {
                    continue;
                }
                $row = ['id' => (int) $r->id, 'cause' => (string) $r->cause_code, 'version' => $r->rules_version !== null ? (string) $r->rules_version : null, 'late' => false];
                if ($r->trigger === OutageDiagnosis::TRIGGER_TICKET_OPEN) {
                    $open[$tid] ??= $row;
                } else {
                    $manual[$tid] ??= $row;
                }
            }
            foreach ($chunk as $tid) {
                $p = $open[$tid] ?? ($manual[$tid] ?? null);
                if ($p !== null) {
                    $out[(int) $tid] = $p;
                } elseif (isset($any[$tid])) {
                    $out[(int) $tid] = ['id' => null, 'cause' => null, 'version' => null, 'late' => true];
                }
            }
        }

        return $out;
    }

    /** Compute and upsert the report of one week. @return array<string,mixed> */
    public function store(Carbon $weekStartWib): array
    {
        $payload = $this->compute($weekStartWib);
        $key = $payload['week_start'];
        try {
            $this->storeCalibration($key, (array) $payload['calibration']);
            self::forgetLatestCalibration();
        } catch (\Throwable $e) {
            Log::warning('[Outage] calibration store failed', ['exception_class' => $e::class]);
        }
        $exists = DB::table('outage_diagnosis_reports')->where('week_start', $key)->exists();
        if ($exists) {
            DB::table('outage_diagnosis_reports')->where('week_start', $key)->update(['payload' => json_encode($payload), 'created_at' => now()]);
        } else {
            DB::table('outage_diagnosis_reports')->insert(['week_start' => $key, 'payload' => json_encode($payload), 'created_at' => now()]);
        }

        return $payload;
    }

    /**
     * The latest stored report (for the Monitoring card).
     *
     * @return array<string,mixed>|null
     */
    public static function latest(): ?array
    {
        if (! Schema::hasTable('outage_diagnosis_reports')) {
            return null;
        }
        $row = DB::table('outage_diagnosis_reports')->orderByDesc('week_start')->first();
        $payload = $row !== null ? json_decode((string) $row->payload, true) : null;

        return is_array($payload) ? $payload : null;
    }

    /** Counts-only weekly summary text (no names, no ticket codes). */
    public function summaryText(array $r): string
    {
        $lines = ['Ketepatan diagnosa minggu '.$r['week_start'].' s/d '.$r['week_end']];
        $lines[] = 'Tiket gangguan tutup: '.$r['tickets'].' (dinilai '.$r['evaluated'].', ditutup otomatis '.$r['closed_auto'].', tidak tahu '.$r['truth_unknown'].', tanpa tebakan '.$r['no_prediction'].')';
        $lines[] = 'Tebakan benar: '.$r['correct'].' dari '.max(0, $r['evaluated'] - $r['abstained']).' yang ditebak';
        if (array_key_exists('by_version', $r)) {
            $lines = array_merge($lines, $this->diagnosisLines($r));
        }
        foreach (array_slice($r['classes'], 0, 6) as $c) {
            if ($c['predicted'] > 0) {
                $lines[] = $c['label'].': '.$c['correct'].'/'.$c['predicted'].($c['precision'] !== null ? ' ('.round($c['precision'] * 100).'%)' : '');
            }
        }
        $lines[] = '(hanya dicatat; tidak ada tiket dan tidak ada WA ke pelanggan)';

        return implode("\n", $lines);
    }

    /**
     * Weekly summary to the technical log group. Flag diagnosis_weekly_wa_enabled (default OFF) and the same
     * target guard as the daily digest (never a PSB group). Returns a status code.
     */
    public function sendSummary(array $report): string
    {
        if (! OutageSettings::diagnosisWeeklyWaEnabled()) {
            return 'disabled';
        }
        $jid = OutageSettings::shadowLogJid();
        if ($jid === '') {
            return 'no_jid';
        }
        $reason = (new OutageShadowNotifier)->refusalReason($jid, OutageSettings::shadowAllowReportGroup());
        if ($reason !== null) {
            return 'refused:'.$reason;
        }
        $key = 'outage-diagnosis-weekly:'.$report['week_start'];
        if (! Cache::add($key, 1, now()->addDays(10))) {
            return 'already_sent';
        }
        try {
            $result = app(EvolutionApiService::class)->forChannel('ops')->sendToGroup(WaGroupJid::normalize($jid), $this->summaryText($report));
            if (($result['success'] ?? false) === true) {
                return 'sent';
            }
        } catch (\Throwable $e) {
            Log::warning('[Outage] weekly diagnosis summary error', ['exception_class' => $e::class]);
        }
        Cache::forget($key);

        return 'failed';
    }
}
