<?php

namespace App\Services\Outage\Outcome;

use App\Enums\OutageRootCause;
use App\Models\Customer;
use App\Models\OltOnu;
use App\Models\OutageDiagnosis;
use App\Models\OutageOnuTracker;
use App\Models\OutageOutcomeLabel;
use App\Models\OutageSignal;
use App\Models\SupportTicket;
use App\Services\Outage\Diagnosis\PrecisionReport;
use App\Services\Outage\Diagnosis\Rules;
use App\Services\Outage\OutageDetector;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the STORED data says really fixed an incident (roadmap v2 section 2.2), so the diagnosis is scored on more than
 * the few technician labels. DB only: indexed reads of data that is already collected, writes only
 * outage_outcome_labels. Never a device, never a WA, never a queue.
 *
 * Signatures (window = [open - 24 h, close + 26 h]):
 *  - rx_jump      RX after - RX before >= 3 dB                          -> drop_los (ONU was in LOS) or optical_degrade   independent
 *  - serial_swap  the customer's ONU serial really changed              -> cpe_dead                                       independent
 *  - unisolate    customer_reconnected and a session up after it        -> isolir_admin                                   independent
 *  - co_recovery  3+ ONUs of the PON back from LOS together after 30 min -> pon_cut / odp_cut                              independent
 *  - power_only   dying gasp, back online, RX and serial unchanged       -> power_off                                      CIRCULAR (shown, never scored)
 * A serial swap or a customer migration / PSB draft in the window cancels rx_jump / co_recovery (a new ONU moves RX).
 * LOS that simply came back with no RX change gets no label: that is an honest "unknown".
 */
final class OutcomeLabeler
{
    public const RX_JUMP_DB = 3.0;

    public const BEFORE_HOURS = 24;

    public const AFTER_HOURS = 26;

    public const MIN_AGE_HOURS = 2;

    /** Close-time width of one pass. Wider than the hourly schedule on purpose: one skipped run is caught up (idempotent upsert). */
    public const SLICE_HOURS = 3;

    public const CO_MIN_DOWN_MINUTES = 30;

    /** An episode's own ONU drop is logged within this many minutes of the episode open. */
    public const EPISODE_DROP_MINUTES = 10;

    public const CO_WINDOW_MINUTES = 10;

    public const CO_MIN_ONUS = 3;

    public const POWER_MAX_RX_DB = 1.0;

    public const EVIDENCE_MAX_BYTES = 1000;

    /** Signal types of one customer that can be labelled, plus the PON-wide one. */
    private const SIGNAL_TYPES = ['single_los', 'dying_gasp', 'weak_rx', 'pppoe_down', 'mass_los'];

    public static function ready(): bool
    {
        return Schema::hasTable('outage_outcome_labels');
    }

    /**
     * Hourly pass. Looks at the tickets and episodes that closed in the last $sliceHours before MIN_AGE_HOURS ago (labels,
     * provisional) and before AFTER_HOURS ago (final), oldest first, final slice first. At most $max subjects per slice;
     * subjects that already have a final label do not count, so a burst of closes is worked off over the passes.
     *
     * @return array{scanned:int,written:int,final:int,removed:int}
     */
    public function run(int $max = 500, int $sliceHours = self::SLICE_HOURS, ?Carbon $now = null): array
    {
        $now = ($now ?? now())->copy()->utc();
        $out = ['scanned' => 0, 'written' => 0, 'final' => 0, 'removed' => 0];
        if (! self::ready()) {
            return $out;
        }
        // Two slices only: subjects that closed MIN_AGE_HOURS ago (first, provisional pass) and AFTER_HOURS ago
        // (final pass). Every subject is read about twice, never every hour; a longer $sliceHours catches up after downtime.
        $slices = [];
        // final slice first, and each slice has its own cap: a burst of closes cannot starve the final pass
        foreach ([self::AFTER_HOURS, self::MIN_AGE_HOURS] as $age) {
            $to = $now->copy()->subHours($age);
            $slices[] = [$to->copy()->subHours($sliceHours), $to];
        }

        foreach ($this->subjects($slices, $max) as $subject) {
            $out['scanned']++;
            // one final answer per subject: once any final row exists, later final passes never add a second label
            if (OutageOutcomeLabel::query()->where('subject_type', $subject['type'])->where('subject_id', $subject['id'])->where('final', true)->exists()) {
                continue;
            }
            $final = $subject['closed']->copy()->addHours(self::AFTER_HOURS)->lte($now);
            $notes = [];
            $found = $this->labelsFor($subject, $now, $final, false, $notes);
            // The first final pass wins (one final answer per subject), so without any read after the close it must not
            // write a final label: an rx_jump may still show up. It stays provisional until the last final pass of the slice.
            $lastPass = $subject['closed']->copy()->addHours(self::AFTER_HOURS + max(0, min($sliceHours, self::SLICE_HOURS) - 1))->lte($now);
            $final = $final && ($lastPass || ! ($notes['rx_missing'] ?? false));
            $kept = [];
            foreach ($found as $label) {
                $kept[] = $label['signature'];
                $row = OutageOutcomeLabel::query()->where('subject_type', $label['subject_type'])->where('subject_id', $label['subject_id'])->where('signature', $label['signature'])->first();
                if ($row !== null && $row->final) {
                    continue; // finalised labels are never rewritten
                }
                $values = $label + ['final' => $final, 'labelled_at' => $now->format('Y-m-d H:i:s')];
                $row === null ? OutageOutcomeLabel::query()->create($values) : $row->forceFill($values)->save();
                $out['written']++;
                $out['final'] += $final ? 1 : 0;
            }
            // a provisional label whose evidence is gone (the +26 h read disagrees) is removed
            $stale = OutageOutcomeLabel::query()->where('subject_type', $subject['type'])->where('subject_id', $subject['id'])->where('final', false)->whereNotIn('signature', $kept ?: ['-'])->delete();
            $out['removed'] += (int) $stale;
        }

        return $out;
    }

    /**
     * Read-only replay for a close-date range: how many labels of each signature the current labeler would write (as a
     * final pass, with the reads that exist now) against what is stored for the same subjects. Counts only, writes nothing.
     * Also counts the subjects whose final read had neither a next daily point nor a live reading (rx_missing) and the
     * rx_jumps dropped because the ONU was already online at the open (healthy_dropped).
     *
     * @return array{subjects:int,capped:bool,would:array<string,int>,stored:array<string,int>,rx_missing:int,healthy_dropped:int}
     */
    public function replay(Carbon $from, Carbon $to, int $max = 2000, ?Carbon $now = null): array
    {
        $now = ($now ?? now())->copy()->utc();
        $out = ['subjects' => 0, 'capped' => false, 'would' => [], 'stored' => [], 'rx_missing' => 0, 'healthy_dropped' => 0];
        if (! self::ready()) {
            return $out;
        }
        $ids = [];
        foreach ($this->subjects([[$from->copy()->utc(), $to->copy()->utc()]], $max, false) as $subject) {
            $out['subjects']++;
            $ids[$subject['type']][] = $subject['id'];
            $notes = [];
            foreach ($this->labelsFor($subject, $now, true, false, $notes) as $label) {
                $out['would'][$label['signature']] = ($out['would'][$label['signature']] ?? 0) + 1;
            }
            $out['rx_missing'] += ($notes['rx_missing'] ?? false) ? 1 : 0;
            $out['healthy_dropped'] += ($notes['healthy_dropped'] ?? false) ? 1 : 0;
        }
        $out['capped'] = $out['subjects'] >= $max;
        foreach ($ids as $type => $list) {
            foreach (array_chunk($list, 500) as $chunk) {
                $rows = OutageOutcomeLabel::query()->where('subject_type', $type)->whereIn('subject_id', $chunk)->selectRaw('signature, count(*) as c')->groupBy('signature')->pluck('c', 'signature');
                foreach ($rows as $signature => $c) {
                    $out['stored'][(string) $signature] = ($out['stored'][(string) $signature] ?? 0) + (int) $c;
                }
            }
        }
        ksort($out['would']);
        ksort($out['stored']);

        return $out;
    }

    /**
     * Subjects whose close time is in a slice, OLDEST first, without those that already have a final label (they are never
     * read again, so they must not use up the cap): every run makes progress through a burst of closes.
     *
     * @param  list<array{0:Carbon,1:Carbon}>  $slices  [from, to] of the close time
     * @return \Generator<int,array<string,mixed>>
     */
    private function subjects(array $slices, int $max, bool $skipFinal = true): \Generator
    {
        $seen = [];
        $noFinal = fn (string $type, string $table) => function ($q) use ($type, $table) {
            $q->select(DB::raw(1))->from('outage_outcome_labels')->whereColumn('outage_outcome_labels.subject_id', $table.'.id')
                ->where('outage_outcome_labels.subject_type', $type)->where('outage_outcome_labels.final', true);
        };
        foreach ($slices as [$from, $to]) {
            $n = 0; // the cap is per slice
            $tickets = SupportTicket::query()->where('type', 'incident')->whereIn('status', OutageRootCause::CLOSING_STATUSES)->whereNotNull('customer_id')
                ->where('resolved_at', '>=', $from->format('Y-m-d H:i:s'))->where('resolved_at', '<=', $to->format('Y-m-d H:i:s'))
                ->when($skipFinal, fn ($q) => $q->whereNotExists($noFinal(OutageOutcomeLabel::SUBJECT_TICKET, 'support_tickets')))
                ->orderBy('resolved_at')->orderBy('id')->limit($max)->get(['id', 'customer_id', 'created_at', 'resolved_at']);
            foreach ($tickets as $t) {
                if (isset($seen['t'.$t->id])) {
                    continue;
                }
                $seen['t'.$t->id] = true;
                yield $this->ticketSubject($t, Carbon::instance($t->resolved_at)->utc());
                if (++$n >= $max) {
                    continue 2;
                }
            }
            $signals = OutageSignal::query()->whereIn('signal_type', self::SIGNAL_TYPES)->where('opened_at', '>=', $from->copy()->subDays(7)->format('Y-m-d H:i:s'))
                ->whereNotNull('resolved_at')->where('resolved_at', '>=', $from->format('Y-m-d H:i:s'))->where('resolved_at', '<=', $to->format('Y-m-d H:i:s'))
                ->when($skipFinal, fn ($q) => $q->whereNotExists($noFinal(OutageOutcomeLabel::SUBJECT_SIGNAL, 'outage_signals')))
                ->orderBy('resolved_at')->orderBy('id')->limit($max)->get(['id', 'signal_type', 'customer_id', 'olt_id', 'pon_port', 'opened_at', 'resolved_at', 'diagnosis_id']);
            foreach ($signals as $s) {
                if (($s->signal_type !== 'mass_los' && $s->customer_id === null) || isset($seen['s'.$s->id])) {
                    continue;
                }
                $seen['s'.$s->id] = true;
                yield [
                    'type' => OutageOutcomeLabel::SUBJECT_SIGNAL, 'id' => (int) $s->id, 'customer_id' => $s->customer_id !== null ? (int) $s->customer_id : null,
                    'opened' => Carbon::instance($s->opened_at)->utc(), 'closed' => Carbon::instance($s->resolved_at)->utc(),
                    'diagnosis_id' => $s->diagnosis_id !== null ? (int) $s->diagnosis_id : null,
                    'signal_type' => (string) $s->signal_type, 'olt_id' => $s->olt_id !== null ? (int) $s->olt_id : null, 'pon' => $s->pon_port,
                ];
                if (++$n >= $max) {
                    continue 2;
                }
            }
        }
    }

    /** @return array<string,mixed> */
    private function ticketSubject(SupportTicket $t, Carbon $closed): array
    {
        return [
            'type' => OutageOutcomeLabel::SUBJECT_TICKET, 'id' => (int) $t->id, 'customer_id' => (int) $t->customer_id,
            'opened' => Carbon::instance($t->created_at)->utc(), 'closed' => $closed,
            'diagnosis_id' => $this->firstTimelyDiagnosis((int) $t->id, Carbon::instance($t->created_at)->utc()),
            'signal_type' => null, 'olt_id' => null, 'pon' => null,
        ];
    }

    /** The prediction a ticket label scores: ticket_open, else the first manual_check, written within TIMELY_MINUTES. */
    private function firstTimelyDiagnosis(int $ticketId, Carbon $created): ?int
    {
        if (! Schema::hasTable('outage_diagnoses')) {
            return null;
        }
        $limit = $created->copy()->addMinutes(PrecisionReport::TIMELY_MINUTES)->format('Y-m-d H:i:s');
        $rows = DB::table('outage_diagnoses')->where('ticket_id', $ticketId)->where('state', OutageDiagnosis::STATE_DONE)
            ->whereIn('trigger', [OutageDiagnosis::TRIGGER_TICKET_OPEN, OutageDiagnosis::TRIGGER_MANUAL])->where('created_at', '<=', $limit)
            ->orderBy('id')->limit(10)->get(['id', 'trigger']);
        foreach ($rows as $r) {
            if ($r->trigger === OutageDiagnosis::TRIGGER_TICKET_OPEN) {
                return (int) $r->id;
            }
        }

        return $rows->isNotEmpty() ? (int) $rows->first()->id : null;
    }

    /**
     * Preview for the close form chip: only the signatures a person can check at the door (serial swap, RX jump,
     * unisolate), computed from the stored data right now. Null = nothing to suggest.
     *
     * @return array{code:string,label:string,text:string,signature:string}|null
     */
    public function preview(SupportTicket $ticket, ?Carbon $now = null): ?array
    {
        if (! $ticket->exists || (string) $ticket->type !== 'incident' || $ticket->customer_id === null || $ticket->created_at === null) {
            return null;
        }
        $now = ($now ?? now())->copy()->utc();
        $subject = $this->ticketSubject($ticket, $now);
        $subject['diagnosis_id'] = null;
        foreach ($this->labelsFor($subject, $now, false, true) as $l) {
            if (! $l['independent'] || ! in_array($l['signature'], ['serial_swap', 'rx_jump', 'unisolate'], true)) {
                continue;
            }
            $label = OutageRootCause::pickLabel($l['outcome']);
            if ($label === null) {
                continue;
            }

            return ['code' => $l['outcome'], 'label' => $label, 'text' => self::textFor($l), 'signature' => $l['signature']];
        }

        return null;
    }

    /**
     * Short plain Indonesian reason shown next to the chip.
     *
     * @param  array<string,mixed>  $l  a label row
     */
    public static function textFor(array $l): string
    {
        $e = (array) ($l['evidence'] ?? []);

        return match ($l['signature']) {
            'rx_jump' => 'redaman membaik '.self::dbm($e['before'] ?? null).' → '.self::dbm($e['after'] ?? null).' setelah perbaikan',
            'serial_swap' => 'ONU pelanggan diganti, serial baru terbaca',
            'unisolate' => 'isolir dibuka dan sesi internet sudah nyala',
            'co_recovery' => ($e['onus'] ?? 0).' ONU di jalur yang sama pulih bersamaan',
            default => 'listrik mati lalu nyala lagi',
        };
    }

    private static function dbm(mixed $v): string
    {
        return is_numeric($v) ? str_replace('.', ',', number_format((float) $v, 1, '.', '')) : '?';
    }

    // ------------------------------------------------------------------ one subject

    /**
     * @param  array<string,mixed>  $s  subject: type, id, customer_id, opened, closed, diagnosis_id, signal_type, olt_id, pon
     * @param  array<string,bool>|null  $notes  optional out: rx_missing (a final read had neither a next daily point nor a live
     *                                          reading, so an rx_jump may still come), healthy_dropped (rx_jump dropped by healthyAtOpen)
     * @return list<array<string,mixed>> label rows (columns of outage_outcome_labels, without id / final / labelled_at)
     */
    public function labelsFor(array $s, Carbon $now, bool $final, bool $preview = false, ?array &$notes = null): array
    {
        $notes = ['rx_missing' => false, 'healthy_dropped' => false];
        /** @var Carbon $opened */
        $opened = $s['opened'];
        /** @var Carbon $closed */
        $closed = $s['closed'];
        $from = $opened->copy()->subHours(self::BEFORE_HOURS);
        $to = $closed->copy()->addHours(self::AFTER_HOURS);
        $base = [
            'subject_type' => $s['type'], 'subject_id' => $s['id'], 'customer_id' => $s['customer_id'], 'olt_id' => $s['olt_id'] ?? null, 'onu_ref' => null,
            'diagnosis_id' => $s['diagnosis_id'] ?? null, 'window_from' => $from->format('Y-m-d H:i:s'), 'window_to' => $to->format('Y-m-d H:i:s'),
        ];
        $customer = $s['customer_id'] !== null ? Customer::query()->find($s['customer_id'], ['id', 'olt_id', 'olt_port_label']) : null;
        $oltId = $customer?->olt_id !== null ? (int) $customer->olt_id : ($s['olt_id'] ?? null);
        $ref = $customer?->olt_port_label !== null ? OutageDetector::normalizeRef((string) $customer->olt_port_label) : null;
        $base['olt_id'] = $oltId;
        $base['onu_ref'] = $ref;

        $make = function (string $signature, string $outcome, bool $independent, array $evidence) use ($base): array {
            return $base + [
                'signature' => $signature, 'outcome' => $outcome, 'family' => PrecisionReport::familyOf($outcome),
                'independent' => $independent, 'evidence' => self::capEvidence($evidence),
            ];
        };
        $labels = [];

        $swap = $customer !== null ? $this->serialSwap((int) $customer->id, $from, $to) : null;
        $moved = $customer !== null && $this->migratedOrPsb((int) $customer->id, $from, $to);
        if ($swap !== null && ! $moved) {
            $labels[] = $make('serial_swap', 'cpe_dead', true, $swap);
        }

        $tracker = $oltId !== null && $ref !== null ? OutageOnuTracker::query()->where('olt_id', $oltId)->where('onu_ref', $ref)->first(['rx_daily']) : null;
        $jump = null;
        if ($swap === null && ! $moved && $oltId !== null && $ref !== null) {
            // a closed subject needs a read taken after the close; a ticket that is still open only needs one after it was opened
            $jump = $this->rxJump($oltId, $ref, $tracker, $opened, $preview ? $opened : $closed, $final, $final && ! $preview, $notes['rx_missing']);
            if ($jump !== null && $this->healthyAtOpen($oltId, $ref, $opened, $from, $closed, $s['type'] === OutageOutcomeLabel::SUBJECT_SIGNAL && in_array($s['signal_type'] ?? null, ['single_los', 'dying_gasp'], true))) {
                $jump = null; // the ONU was already back online when this subject opened: the repair was someone else's
                $notes['healthy_dropped'] = true;
            }
            if ($jump !== null) {
                $cause = $this->hadLos($oltId, $ref, $from, $closed) ? 'drop_los' : 'optical_degrade';
                $labels[] = $make('rx_jump', $cause, true, $jump);
            }
        }

        if ($customer !== null && ($s['signal_type'] ?? null) !== 'mass_los' && in_array($s['signal_type'] ?? 'ticket', ['ticket', null, 'pppoe_down'], true)) {
            $reconnect = $this->unisolated((int) $customer->id, $from, $to);
            if ($reconnect !== null) {
                $labels[] = $make('unisolate', 'isolir_admin', true, $reconnect);
            }
        }

        if (! $preview && $swap === null && ! $moved) {
            $co = $this->coRecovery($s, $oltId, $ref, $from, $to);
            if ($co !== null) {
                $labels[] = $make('co_recovery', $co['outcome'], true, $co['evidence']);
            }
            if ($jump === null && $oltId !== null && $ref !== null) {
                $power = $this->powerOnly($oltId, $ref, $tracker, $opened, $closed, $from, $to);
                if ($power !== null) {
                    $labels[] = $make('power_only', 'power_off', false, $power);
                }
            }
        }

        return $labels;
    }

    /** @param  array<string,mixed>  $e */
    private static function capEvidence(array $e): array
    {
        if (strlen((string) json_encode($e)) <= self::EVIDENCE_MAX_BYTES) {
            return $e;
        }

        return array_intersect_key($e, array_flip(['before', 'after', 'delta', 'onus', 'at']));
    }

    // ------------------------------------------------------------------ signatures

    /** @return array<string,mixed>|null */
    private function rxJump(int $oltId, string $ref, ?OutageOnuTracker $tracker, Carbon $opened, Carbon $readAfter, bool $final, bool $useDaily = true, bool &$missing = false): ?array
    {
        $points = $this->dailyPoints($tracker);
        $openDate = $opened->copy()->timezone('Asia/Jakarta')->format('Y-m-d');
        $before = null;
        foreach ($points as $date => $dbm) {
            if ($date < $openDate && $date >= Carbon::parse($openDate, 'Asia/Jakarta')->subDays(2)->format('Y-m-d')) {
                $before = $before === null ? $dbm : min($before, $dbm);
            }
        }
        if ($before === null) {
            return null;
        }
        // The RX must still have been bad when the subject opened: a repair that happened BEFORE it (a ticket or episode
        // that opens a day or two after) is not the fix of this subject. The day before the open day already 3 dB better
        // than the worst of the two days = repaired earlier. (Same-day order is told by the ONU events, healthyAtOpen().)
        $dayBefore = Carbon::parse($openDate, 'Asia/Jakarta')->subDay()->format('Y-m-d');
        if (isset($points[$dayBefore]) && $points[$dayBefore] >= $before + self::RX_JUMP_DB) {
            return null;
        }
        $closeDate = $readAfter->copy()->timezone('Asia/Jakarta')->format('Y-m-d');
        $candidates = [];
        $row = OltOnu::query()->where('olt_id', $oltId)->where('onu_ref', $ref)->first(['rx_power_dbm', 'rx_collected_at']);
        if ($row !== null && $row->rx_power_dbm !== null && $row->rx_collected_at !== null && Carbon::instance($row->rx_collected_at)->gte($readAfter) && (float) $row->rx_power_dbm < 0) {
            $candidates['live'] = (float) $row->rx_power_dbm;
        }
        // the first daily point after the close day: only in the final pass (+26 h), a day that is still running is no point yet
        foreach ($useDaily ? $points : [] as $date => $dbm) {
            if ($date > $closeDate) {
                $candidates['next'] = $dbm;
                break;
            }
        }
        if ($candidates === [] || ($final && ! isset($candidates['next']) && ! isset($candidates['live']))) {
            // a final pass with no read after the close: an rx_jump may still show up in a later pass
            $missing = $final;

            return null;
        }
        $after = min($candidates); // both reads must show the improvement
        $delta = $after - $before;

        return $delta >= self::RX_JUMP_DB ? ['before' => round($before, 1), 'after' => round($after, 1), 'delta' => round($delta, 1), 'reads' => count($candidates)] : null;
    }

    /** @return array<string,float> WIB date => median dbm, only real (negative) values */
    private function dailyPoints(?OutageOnuTracker $tracker): array
    {
        $out = [];
        foreach ((array) ($tracker?->rx_daily ?? []) as $d) {
            if (is_array($d) && isset($d['date'], $d['dbm']) && is_numeric($d['dbm']) && (float) $d['dbm'] < 0) {
                $out[(string) $d['date']] = (float) $d['dbm'];
            }
        }
        ksort($out);

        return $out;
    }

    /**
     * The last ONU event before the subject opened was "back online" and no drop came after it: the ONU was healthy
     * when the subject opened (a repair of an earlier subject). No events at all = no statement.
     *
     * $episode is only true for a single_los / dying_gasp episode: its open IS the ONU drop. Then the FIRST drop within
     * EPISODE_DROP_MINUTES of the open is that episode's own drop, and the episode is "healthy at open" only when the ONU
     * had come back online earlier in the window after a down of at least CO_MIN_DOWN_MINUTES (a real outage that was
     * repaired, not a short flap or a power blip). Any other subject (ticket, weak_rx ...) keeps "a drop = still down".
     */
    private function healthyAtOpen(int $oltId, string $ref, Carbon $opened, Carbon $from, Carbon $to, bool $episode = false): bool
    {
        if (! Schema::hasTable('onu_state_events')) {
            return false;
        }
        $events = DB::table('onu_state_events')->where('olt_id', $oltId)->where('onu_ref', $ref)
            ->where('occurred_at', '>=', $from->format('Y-m-d H:i:s'))->where('occurred_at', '<=', $to->format('Y-m-d H:i:s'))
            ->orderBy('occurred_at')->limit(60)->get(['from_phase', 'to_phase', 'occurred_at']);
        $last = null;
        $backBefore = false;
        $downSince = null;
        foreach ($events as $e) {
            $at = Carbon::parse($e->occurred_at, 'UTC');
            if ($at->gte($opened) && $e->to_phase !== 'online') {
                // the first drop at or after the open. A ticket still sees a down ONU; an episode of its own drop
                // (within a few minutes of its open) is "healthy at open" when a long outage had already been repaired
                // earlier in the window: the RX jump belongs to that earlier outage, not to this episode.
                return $episode && $backBefore && $at->diffInMinutes($opened, true) <= self::EPISODE_DROP_MINUTES;
            }
            if ($at->lt($opened)) {
                $prev = $last;
                $last = $e->to_phase;
                if ($e->to_phase === 'online') {
                    // The first event of the window can already be the repair: the down event is older than the window
                    // (an outage over about a day). The ONU then was down since at least $from, a lower bound.
                    $since = $downSince ?? ($prev === null && $e->from_phase !== null && $e->from_phase !== 'online' ? $from : null);
                    $backBefore = $backBefore || ($since !== null && $since->diffInMinutes($at, true) >= self::CO_MIN_DOWN_MINUTES);
                    $downSince = null;
                } else {
                    $downSince ??= $at;
                }
            }
        }

        // Known gap, kept on purpose (conservative: a label is lost, never wrong): a subject opened while the ONU is online
        // is "healthy at open" whenever the window holds any event, also after a short flap. Telling that from a real repair
        // needs the replay on real history first (outage:outcome-replay counts it as healthy_dropped).
        return $last === 'online';
    }

    private function hadLos(int $oltId, string $ref, Carbon $from, Carbon $to): bool
    {
        if (! Schema::hasTable('onu_state_events')) {
            return false;
        }

        return DB::table('onu_state_events')->where('olt_id', $oltId)->where('onu_ref', $ref)
            ->where('occurred_at', '>=', $from->format('Y-m-d H:i:s'))->where('occurred_at', '<=', $to->format('Y-m-d H:i:s'))
            ->where(fn ($q) => $q->where('to_phase', 'los')->orWhere('cause', 'los'))->exists();
    }

    /**
     * The customer's ONU serial really changed in the window (activity_log, subject index) or a second GenieACS device
     * appeared.
     *
     * @return array<string,mixed>|null
     */
    private function serialSwap(int $customerId, Carbon $from, Carbon $to): ?array
    {
        if (Schema::hasTable('activity_log')) {
            $rows = DB::table('activity_log')->where('subject_type', Customer::class)->where('subject_id', $customerId)
                ->where('created_at', '>=', $from->format('Y-m-d H:i:s'))->where('created_at', '<=', $to->format('Y-m-d H:i:s'))
                ->orderBy('id')->limit(100)->get(['properties', 'created_at']);
            foreach ($rows as $r) {
                $p = json_decode((string) $r->properties, true);
                $old = is_array($p) ? (string) ($p['old']['onu_serial'] ?? '') : '';
                $new = is_array($p) ? (string) ($p['attributes']['onu_serial'] ?? '') : '';
                if ($old !== '' && $new !== '' && OutageDetector::serialKey($old) !== OutageDetector::serialKey($new)) {
                    return ['source' => 'activity', 'at' => Carbon::parse($r->created_at, 'UTC')->toIso8601String()];
                }
            }
        }
        if (Schema::hasTable('genieacs_devices')) {
            $new = DB::table('genieacs_devices')->where('customer_id', $customerId)->where('created_at', '>=', $from->format('Y-m-d H:i:s'))->where('created_at', '<=', $to->format('Y-m-d H:i:s'))->limit(1)->first(['created_at']);
            $older = $new !== null && DB::table('genieacs_devices')->where('customer_id', $customerId)->where('created_at', '<', $from->format('Y-m-d H:i:s'))->exists();
            if ($new !== null && $older) {
                return ['source' => 'acs', 'at' => Carbon::parse($new->created_at, 'UTC')->toIso8601String()];
            }
        }

        return null;
    }

    /** A customer migration or a PSB draft in the window: the new ONU is a migration, not a repair. Read only. */
    private function migratedOrPsb(int $customerId, Carbon $from, Carbon $to): bool
    {
        foreach (['customer_migrations', 'psb_drafts'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'customer_id')
                && DB::table($table)->where('customer_id', $customerId)->where('created_at', '>=', $from->format('Y-m-d H:i:s'))->where('created_at', '<=', $to->format('Y-m-d H:i:s'))->exists()) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string,mixed>|null */
    private function unisolated(int $customerId, Carbon $from, Carbon $to): ?array
    {
        if (! Schema::hasTable('activity_log')) {
            return null;
        }
        $log = DB::table('activity_log')->where('subject_type', Customer::class)->where('subject_id', $customerId)->where('description', 'customer_reconnected')
            ->where('created_at', '>=', $from->format('Y-m-d H:i:s'))->where('created_at', '<=', $to->format('Y-m-d H:i:s'))->orderByDesc('id')->first(['created_at']);
        if ($log === null) {
            return null;
        }
        $at = Carbon::parse($log->created_at, 'UTC');
        $up = Schema::hasTable('pppoe_session_events') && DB::table('pppoe_session_events')->where('customer_id', $customerId)->where('event', 'connected')
            ->where('at', '>=', $at->format('Y-m-d H:i:s'))->where('at', '<=', $at->copy()->addHours(6)->format('Y-m-d H:i:s'))->exists();
        if (! $up) {
            $up = (bool) DB::table('customers')->where('id', $customerId)->where('is_online', 1)->where('pppoe_online_checked_at', '>=', $at->format('Y-m-d H:i:s'))->exists();
        }

        return $up ? ['at' => $at->toIso8601String()] : null;
    }

    /**
     * 3+ ONUs of the PON back from LOS in the same or the adjacent detector run, after at least 30 minutes down. A
     * dying-gasp group has no LOS pre-state, so it is never a co-recovery (that is power_only).
     *
     * @param  array<string,mixed>  $s
     * @return array{outcome:string,evidence:array<string,mixed>}|null
     */
    private function coRecovery(array $s, ?int $oltId, ?string $ref, Carbon $from, Carbon $to): ?array
    {
        if ($oltId === null || ! Schema::hasTable('onu_state_events')) {
            return null;
        }
        $pon = ($s['signal_type'] ?? null) === 'mass_los' ? (string) ($s['pon'] ?? '') : ($ref !== null ? (string) OutageDetector::ponOf($ref) : '');
        if ($pon === '') {
            return null;
        }
        $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $pon.':').'%';
        $events = DB::table('onu_state_events')->where('olt_id', $oltId)->where('onu_ref', 'like', $like)
            ->where('occurred_at', '>=', $from->format('Y-m-d H:i:s'))->where('occurred_at', '<=', $to->format('Y-m-d H:i:s'))
            ->orderBy('occurred_at')->limit(400)->get(['onu_ref', 'from_phase', 'to_phase', 'occurred_at']);
        // the recovery that matters: own ONU for a customer subject, any recovery inside the episode for a PON subject
        $recoveries = [];
        foreach ($events as $e) {
            if ($e->to_phase === 'online' && $e->from_phase === 'los') {
                $recoveries[] = ['ref' => (string) $e->onu_ref, 'at' => Carbon::parse($e->occurred_at, 'UTC')];
            }
        }
        $anchor = null;
        foreach ($recoveries as $r) {
            if (($s['signal_type'] ?? null) === 'mass_los' ? ($r['at']->gte($s['opened']) && $r['at']->lte($s['closed']->copy()->addMinutes(self::CO_WINDOW_MINUTES))) : ($r['ref'] === $ref && $r['at']->gte($s['opened']->copy()->subHour()))) {
                $anchor = $r; // the FIRST recovery after the open: a later one can be an unrelated event
                break;
            }
        }
        if ($anchor === null) {
            return null;
        }
        $group = array_values(array_filter($recoveries, fn (array $r) => abs($r['at']->diffInMinutes($anchor['at'], false)) <= self::CO_WINDOW_MINUTES));
        $refs = array_unique(array_column($group, 'ref'));
        if (count($refs) < self::CO_MIN_ONUS) {
            return null;
        }
        // each of them was down at least 30 minutes: its last LOS start before the recovery
        $long = 0;
        foreach ($refs as $r) {
            $rec = collect($group)->where('ref', $r)->first()['at'];
            $drop = null;
            foreach ($events as $e) {
                if ($e->onu_ref === $r && $e->to_phase === 'los' && Carbon::parse($e->occurred_at, 'UTC')->lt($rec)) {
                    $drop = Carbon::parse($e->occurred_at, 'UTC');
                }
            }
            if ($drop !== null && $drop->diffInMinutes($rec) >= self::CO_MIN_DOWN_MINUTES) {
                $long++;
            }
        }
        if ($long < self::CO_MIN_ONUS) {
            return null;
        }
        $known = (int) OutageOnuTracker::query()->where('olt_id', $oltId)->where('onu_ref', 'like', $like)->count();
        $outcome = Rules::ponEnough($long, max($known, $long)) ? 'pon_cut' : 'odp_cut';

        return ['outcome' => $outcome, 'evidence' => ['onus' => $long, 'known' => $known, 'at' => $anchor['at']->toIso8601String()]];
    }

    /** @return array<string,mixed>|null */
    private function powerOnly(int $oltId, string $ref, ?OutageOnuTracker $tracker, Carbon $opened, Carbon $closed, Carbon $from, Carbon $to): ?array
    {
        if (! Schema::hasTable('onu_state_events')) {
            return null;
        }
        $rows = DB::table('onu_state_events')->where('olt_id', $oltId)->where('onu_ref', $ref)
            ->where('occurred_at', '>=', $from->format('Y-m-d H:i:s'))->where('occurred_at', '<=', $to->format('Y-m-d H:i:s'))
            ->orderBy('occurred_at')->limit(60)->get(['to_phase', 'cause', 'occurred_at']);
        $gasp = null;
        $back = false;
        foreach ($rows as $e) {
            if ($gasp === null && ($e->to_phase === 'dying_gasp' || $e->cause === 'dying_gasp')) {
                $gasp = Carbon::parse($e->occurred_at, 'UTC');
            } elseif ($gasp !== null && $e->to_phase === 'online') {
                $back = true;
            }
        }
        if ($gasp === null || ! $back) {
            return null;
        }
        $points = $this->dailyPoints($tracker);
        $openDate = $opened->copy()->timezone('Asia/Jakarta')->format('Y-m-d');
        $closeDate = $closed->copy()->timezone('Asia/Jakarta')->format('Y-m-d');
        $before = null;
        $after = null;
        foreach ($points as $date => $dbm) {
            if ($date <= $openDate) {
                $before = $dbm;
            }
            if ($date >= $closeDate && $after === null) {
                $after = $dbm;
            }
        }
        if ($before === null || $after === null || abs($after - $before) >= self::POWER_MAX_RX_DB) {
            return null;
        }

        return ['before' => round($before, 1), 'after' => round($after, 1), 'at' => $gasp->toIso8601String()];
    }
}
