<?php

namespace App\Services\Outage;

use App\Enums\OutageRootCause;
use App\Models\Customer;
use App\Models\CustomerMigration;
use App\Models\Olt;
use App\Models\OltCollectRun;
use App\Models\OutageSignal;
use App\Models\PsbDraft;
use App\Models\SupportTicket;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Roadmap 2.4 PR-4: shadow-mode accuracy report. Pure read (outage_*, tickets, customers, OLT tables);
 * run OutageTicketMatcher first so matched_* is current. Contains counts and OLT/PON labels only.
 */
class OutageShadowReporter
{
    public const WOULD_TICKET = ['ticket_mass', 'ticket_single', 'ticket_maintenance'];

    /** Min NOC-labelled sample per signal type for the label precision to count. */
    public const LABEL_MIN_SAMPLE = 20;

    private const RECALL_LEAD_MINUTES = 48 * 60;

    private const RECALL_GRACE_MINUTES = 120;

    /**
     * Parse "mass_min:3,4,5;single_los:20,30,45;weak_rx:-26,-27,-28".
     *
     * @return array<string,list<float>>
     */
    public static function parseReplay(?string $spec): array
    {
        $out = [];
        foreach (preg_split('/\s*;\s*/', trim((string) $spec), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
            [$key, $vals] = array_pad(explode(':', $part, 2), 2, '');
            $key = trim($key);
            if (! in_array($key, ['mass_min', 'single_los', 'weak_rx'], true)) {
                continue;
            }
            $list = [];
            foreach (preg_split('/\s*,\s*/', trim($vals), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $v) {
                if (is_numeric($v)) {
                    $list[] = (float) $v;
                }
            }
            if ($list !== []) {
                $out[$key] = $list;
            }
        }

        return $out;
    }

    /**
     * @param  array<string,list<float>>  $replay
     * @return array<string,mixed>
     */
    public function build(CarbonInterface $from, CarbonInterface $to, array $replay = []): array
    {
        $from = Carbon::instance($from);
        $to = Carbon::instance($to);
        $days = max(1.0, round($from->diffInSeconds($to) / 86400, 2));

        $signals = OutageSignal::query()->whereBetween('opened_at', [$from, $to])->get();

        return [
            'from' => $from->copy()->timezone('Asia/Jakarta')->format('Y-m-d H:i').' WIB',
            'to' => $to->copy()->timezone('Asia/Jakarta')->format('Y-m-d H:i').' WIB',
            'days' => $days,
            'signals' => $this->perType($signals, $days),
            'top_pon' => $this->topPon($signals),
            'recall' => $this->recall($from, $to),
            'coverage' => $this->coverage(),
            'stale_olts' => $this->staleOlts(),
            'suppression' => $this->suppressionStats($signals),
            'data_blind' => $this->dataBlind($from, $to),
            'weak_rx_weekly' => $this->weakRxWeekly(),
            'incident_closes' => $this->incidentCloses($from, $to),
            'manual_tickets' => $this->manualTickets($from, $to, $signals),
            'replay' => $replay === [] ? [] : $this->replay($signals, $replay, $days),
        ];
    }

    /**
     * "down_unknown" (ZTE offline without a cause, RCA-1) is a single_los-type episode that must not be mixed
     * into the LOS numbers: it gets its own row and single_los excludes it.
     *
     * @return array<string,array<string,mixed>>
     */
    private function perType($signals, float $days): array
    {
        $isDownUnknown = fn (OutageSignal $s): bool => $s->signal_type === 'single_los' && (((array) $s->payload)['cause'] ?? null) === 'down_unknown';
        $out = [];
        foreach ([...OutageSignal::TYPES, 'down_unknown'] as $type) {
            $set = $type === 'down_unknown'
                ? $signals->filter($isDownUnknown)
                : $signals->where('signal_type', $type)->reject($type === 'single_los' ? $isDownUnknown : fn () => false);
            $would = $set->filter(fn (OutageSignal $s) => in_array($s->would_action, self::WOULD_TICKET, true));
            $matched = $would->filter(fn (OutageSignal $s) => $s->matched_ticket_id !== null);
            $tp = $would->where('label', 'tp')->count();
            $fp = $would->where('label', 'fp')->count();
            $labelled = $would->whereNotNull('label')->count();
            $lags = $matched->pluck('match_lag_minutes')->filter(fn ($v) => $v !== null)->map(fn ($v) => (int) $v)->sort()->values()->all();

            $out[$type] = [
                'opened' => $set->count(),
                'confirmed' => $set->whereNotNull('confirmed_at')->count(),
                'would_ticket' => $would->count(),
                'suppressed' => $set->whereNotNull('suppressed_reason')->groupBy('suppressed_reason')->map->count()->sortKeys()->all(),
                'would_ticket_per_day' => round($would->count() / $days, 2),
                'matched' => $matched->count(),
                'precision_matched' => $would->count() > 0 ? round($matched->count() / $would->count(), 3) : null,
                'label_tp' => $tp,
                'label_fp' => $fp,
                'label_sample' => $labelled,
                'label_sample_ok' => $labelled >= self::LABEL_MIN_SAMPLE,
                'precision_label' => ($tp + $fp) > 0 ? round($tp / ($tp + $fp), 3) : null,
                'lead_time_median_min' => $this->median($lags),
            ];
        }

        return $out;
    }

    /** @param list<int> $sorted */
    private function median(array $sorted): ?float
    {
        $n = count($sorted);
        if ($n === 0) {
            return null;
        }
        $mid = intdiv($n, 2);

        return $n % 2 === 1 ? (float) $sorted[$mid] : ($sorted[$mid - 1] + $sorted[$mid]) / 2;
    }

    /** @return list<array{olt:string,pon:string,count:int}> up to 3 PON ports with most would-ticket episodes */
    private function topPon($signals): array
    {
        $names = Olt::query()->pluck('name', 'id');
        $rows = [];
        foreach ($signals as $s) {
            if ($s->pon_port === null || $s->olt_id === null || ! in_array($s->would_action, self::WOULD_TICKET, true)) {
                continue;
            }
            $key = $s->olt_id.'|'.$s->pon_port;
            $rows[$key] = ($rows[$key] ?? 0) + 1;
        }
        arsort($rows);
        $out = [];
        foreach (array_slice($rows, 0, 3, true) as $key => $count) {
            [$oltId, $pon] = explode('|', (string) $key, 2);
            $out[] = ['olt' => (string) ($names[(int) $oltId] ?? 'OLT '.$oltId), 'pon' => $pon, 'count' => $count];
        }

        return $out;
    }

    /**
     * Recall: real incident tickets (non-auto, with a customer) for MONITORABLE customers that already had
     * a would-ticket episode confirmed before the ticket (or at most +2h after).
     *
     * @return array<string,mixed>
     */
    public function recall(CarbonInterface $from, CarbonInterface $to): array
    {
        $from = Carbon::instance($from);
        $to = Carbon::instance($to);
        $monitored = OutageSettings::oltIds();
        $tickets = SupportTicket::query()
            ->whereBetween('created_at', [$from, $to])
            ->where('type', 'incident')
            ->whereNotNull('customer_id')
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'cancelled'))
            ->select(['id', 'customer_id', 'meta', 'created_at'])
            ->get()
            ->reject(fn (SupportTicket $t) => OutageTicketMatcher::isDetectionTicket($t))
            ->values();

        $customers = [];
        foreach ($tickets->pluck('customer_id')->unique()->chunk(500) as $chunk) {
            foreach (Customer::query()->whereIn('id', $chunk->all())->get(['id', 'olt_id', 'olt_port_label', 'status']) as $c) {
                $customers[(int) $c->id] = $c;
            }
        }

        // customer id => list of [confirmed_at ts, kind] for would-ticket episodes around the window
        $conf = [];
        $other = [];
        $sigs = OutageSignal::query()
            ->whereIn('signal_type', OutageTicketMatcher::MATCHABLE)
            ->where('opened_at', '>=', $from->copy()->subMinutes(self::RECALL_LEAD_MINUTES))
            ->where('opened_at', '<=', $to->copy()->addMinutes(self::RECALL_GRACE_MINUTES))
            ->get();
        foreach ($sigs as $s) {
            $ids = $s->signal_type === 'mass_los' ? (array) $s->affected_customer_ids : [$s->customer_id];
            foreach ($ids as $cid) {
                if ($cid === null) {
                    continue;
                }
                if ($s->confirmed_at !== null && in_array($s->would_action, self::WOULD_TICKET, true)) {
                    $conf[(int) $cid][] = $s->confirmed_at->getTimestamp();
                } else {
                    $other[(int) $cid][] = $s->suppressed_reason === null ? 'below_threshold' : $s->suppressed_reason;
                }
            }
        }

        $monitorableTickets = 0;
        $covered = 0;
        $missed = ['not_mapped' => 0, 'olt_not_monitored' => 0, 'stale' => 0, 'below_threshold' => 0, 'detected_late' => 0, 'no_signal' => 0];
        foreach ($tickets as $t) {
            $c = $customers[(int) $t->customer_id] ?? null;
            $ts = $t->created_at->getTimestamp();
            $hit = false;
            foreach ($conf[(int) $t->customer_id] ?? [] as $at) {
                if ($at <= $ts + self::RECALL_GRACE_MINUTES * 60 && $at >= $ts - self::RECALL_LEAD_MINUTES * 60) {
                    $hit = true;
                    break;
                }
            }

            $monitorable = $c !== null && $c->olt_id !== null && in_array((int) $c->olt_id, $monitored, true)
                && trim((string) $c->olt_port_label) !== '';
            if ($monitorable) {
                $monitorableTickets++;
                if ($hit) {
                    $covered++;

                    continue;
                }
            } elseif ($hit) {
                continue; // detected although not "monitorable" by the simple rule: not a miss
            }

            if ($c === null || $c->olt_id === null || trim((string) $c->olt_port_label) === '') {
                $missed['not_mapped']++;
            } elseif (! in_array((int) $c->olt_id, $monitored, true)) {
                $missed['olt_not_monitored']++;
            } elseif (isset($conf[(int) $t->customer_id])) {
                $missed['detected_late']++; // would-ticket episode existed but outside [-48h, +2h] of the ticket
            } elseif (in_array('stale', $other[(int) $t->customer_id] ?? [], true)) {
                $missed['stale']++;
            } elseif (isset($other[(int) $t->customer_id])) {
                // A suppression (isolated, open_ticket, migration, ...) is not a detector miss: own bucket.
                $reasons = $other[(int) $t->customer_id];
                $real = array_values(array_diff($reasons, ['below_threshold']));
                if (in_array('below_threshold', $reasons, true) || $real === []) {
                    $missed['below_threshold']++;
                } else {
                    $key = 'suppressed:'.$real[0];
                    $missed[$key] = ($missed[$key] ?? 0) + 1;
                }
            } else {
                $missed['no_signal']++;
            }
        }

        return [
            'real_tickets' => $tickets->count(),
            'monitorable_tickets' => $monitorableTickets,
            'covered' => $covered,
            'recall' => $monitorableTickets > 0 ? round($covered / $monitorableTickets, 3) : null,
            'missed_reasons' => $missed,
        ];
    }

    /**
     * How much PSB / migration data still suppresses detection (RCA-1 age limits), plus how many episodes of
     * the window were suppressed by each. Read-only on psb_drafts / customer_migrations (counts only).
     *
     * @param  Collection<int,OutageSignal>|null  $signals
     * @return array<string,mixed>
     */
    public function suppressionStats($signals = null): array
    {
        $psbDays = OutageSettings::psbSuppressDays();
        $migDays = OutageSettings::migrationSuppressDays();
        $psbSince = now()->subDays($psbDays);
        $migSince = now()->subDays($migDays);

        $psbOpen = PsbDraft::query()->whereIn('status', OutageDetector::PSB_OPEN_STATUSES)->whereNotNull('customer_id');
        $migOpen = CustomerMigration::query()->open()->whereNotNull('customer_id');
        $psbAll = (int) (clone $psbOpen)->distinct()->count('customer_id');
        $psbNow = (int) (clone $psbOpen)->where('updated_at', '>=', $psbSince)->distinct()->count('customer_id');
        $migAll = (int) (clone $migOpen)->distinct()->count('customer_id');
        $migNow = (int) (clone $migOpen)->where('updated_at', '>=', $migSince)->distinct()->count('customer_id');

        $episodes = ['psb' => 0, 'migration' => 0];
        foreach (($signals ?? collect()) as $s) {
            if (isset($episodes[(string) $s->suppressed_reason])) {
                $episodes[(string) $s->suppressed_reason]++;
            }
        }

        return [
            'psb_days' => $psbDays,
            'migration_days' => $migDays,
            'psb_customers_open' => $psbAll,
            'psb_customers_suppressing' => $psbNow,
            'psb_customers_aged_out' => max(0, $psbAll - $psbNow),
            'migration_customers_open' => $migAll,
            'migration_customers_suppressing' => $migNow,
            'migration_customers_aged_out' => max(0, $migAll - $migNow),
            'episodes_suppressed_by_psb' => $episodes['psb'],
            'episodes_suppressed_by_migration' => $episodes['migration'],
        ];
    }

    /**
     * DATA_BLIND episodes (collector / router data stale or failed): open now and opened in the window.
     * Not outages: shown so a polling gap is never mistaken for an incident.
     *
     * @return array<string,mixed>
     */
    public function dataBlind(CarbonInterface $from, CarbonInterface $to): array
    {
        $names = Olt::query()->pluck('name', 'id');
        $open = OutageSignal::query()->open()->ofType('data_blind')->orderBy('opened_at')->get()->map(function (OutageSignal $s) use ($names): array {
            $p = (array) $s->payload;

            return [
                'id' => (int) $s->id,
                'kind' => (string) ($p['kind'] ?? 'olt'),
                'label' => ($p['kind'] ?? 'olt') === 'router'
                    ? 'Router '.($p['router_name'] ?? ('#'.($p['router_id'] ?? '?')))
                    : (string) ($names[(int) $s->olt_id] ?? 'OLT '.$s->olt_id),
                'reason' => (string) (OutageShadowEpisodeReadModel::BLIND_LABELS[(string) ($p['reason'] ?? '')] ?? ($p['reason'] ?? '')),
                'since' => $s->opened_at?->toIso8601String(),
            ];
        })->values()->all();

        return [
            'open' => $open,
            'episodes_in_window' => (int) OutageSignal::query()->ofType('data_blind')->whereBetween('opened_at', [$from, $to])->count(),
        ];
    }

    /**
     * Weak RX beyond the per-day cap: every unresolved weekly-list entry (worst first, ids only, no names).
     *
     * @return array<string,mixed>
     */
    public function weakRxWeekly(): array
    {
        $rows = OutageSignal::query()->ofType('weak_rx')->where('would_action', 'weekly_list')
            ->where('state', OutageSignal::STATE_CONFIRMED)->get();
        $names = Olt::query()->pluck('name', 'id');
        $list = $rows->map(function (OutageSignal $s) use ($names): array {
            $p = (array) $s->payload;
            $dbm = $p['min_dbm'] ?? $p['rx_dbm'] ?? $p['worst_dbm'] ?? null;

            return [
                'id' => (int) $s->id,
                'customer_id' => $s->customer_id !== null ? (int) $s->customer_id : null,
                'olt' => (string) ($names[(int) $s->olt_id] ?? 'OLT '.$s->olt_id),
                'pon' => $s->pon_port,
                'dbm' => is_numeric($dbm) ? (float) $dbm : null,
                'state' => $s->state,
            ];
        })->sortBy(fn (array $r) => $r['dbm'] ?? 0.0)->values();

        return [
            'count' => $list->count(),
            'max_per_day' => OutageSettings::weakRxMaxPerDay(),
            'list' => $list->take(50)->all(),
        ];
    }

    /**
     * Incident tickets closed in the window: with a cause (labelled by a person) versus without one. The
     * second group is closes that skip the form (automatic closes, e.g. device pickup or recovery verify),
     * so the labelled sample is not read as if it covered every ticket.
     *
     * @return array{with_cause:int,auto_closed:int}
     */
    public function incidentCloses(CarbonInterface $from, CarbonInterface $to): array
    {
        if (! OutageRootCause::columnReady()) {
            return ['with_cause' => 0, 'auto_closed' => 0];
        }
        $base = SupportTicket::query()->where('type', 'incident')->whereIn('status', ['resolved', 'closed'])
            ->whereBetween('resolved_at', [$from, $to]);

        return [
            'with_cause' => (int) (clone $base)->whereNotNull('root_cause')->where('root_cause', '!=', '')->where('root_cause', '!=', OutageRootCause::AutoClosed->value)->count(),
            'auto_closed' => (int) (clone $base)->where(fn ($q) => $q->whereNull('root_cause')->orWhere('root_cause', '')->orWhere('root_cause', OutageRootCause::AutoClosed->value))->count(),
        ];
    }

    /** Closed-ticket root causes that mean "there was a real fault" (every pppoe_* too). */
    public const REAL_CAUSES = ['pon_cut', 'odp_cut', 'drop_los', 'cpe_dead', 'cpe_reboot_loop', 'optical_degrade', 'olt_down', 'core_router', 'upstream'];

    /** Root causes that mean "not a network fault" (WiFi problems do not cause LOS / PPPoE down). Any unlisted code is "unknown". */
    public const NOT_FAULT_CAUSES = ['power_off', 'isolir_admin', 'data_blind', 'wifi_client', 'wlan_down', 'wlan_flap', 'not_fault', 'self_recovered'];

    /**
     * Accuracy data of the manual "Buat tiket" button, per episode type: how often it was clicked, when (state and
     * minutes after open / confirm) and what the closed tickets turned out to be. Counts only; a proxy precision is
     * shown from LABEL_MIN_SAMPLE closed tickets on. These tickets never count as match or recall.
     *
     * @param  Collection<int,OutageSignal>|null  $signals  episodes opened in the window
     * @return array<string,array<string,mixed>>
     */
    public function manualTickets(CarbonInterface $from, CarbonInterface $to, $signals = null): array
    {
        $from = Carbon::instance($from);
        $to = Carbon::instance($to);
        $signals ??= OutageSignal::query()->whereBetween('opened_at', [$from, $to])->get();
        $typeOf = function (string $type, mixed $cause): string {
            return $type === 'single_los' && $cause === 'down_unknown' ? 'down_unknown' : $type;
        };

        $shown = [];
        foreach ($signals as $s) {
            if (OutageTicketEvidence::kind($s) !== null) {
                $key = $typeOf((string) $s->signal_type, ((array) $s->payload)['cause'] ?? null);
                $shown[$key] = ($shown[$key] ?? 0) + 1;
            }
        }

        // Same population as "shown": tickets of the episodes opened in the window (click rate can never pass 1).
        $signalIds = array_flip($signals->pluck('id')->map(fn ($id) => (int) $id)->all());
        $tickets = SupportTicket::query()
            ->where('meta->source', 'deteksi_manual')
            ->where('created_at', '>=', $from)
            ->get(['id', 'status', 'root_cause', 'meta', 'created_at'])
            ->filter(fn (SupportTicket $t) => isset($signalIds[(int) (((array) $t->meta)['outage_signal_id'] ?? 0)]));

        $by = [];
        foreach ($tickets as $t) {
            $meta = is_array($t->meta) ? $t->meta : [];
            $snap = (array) ($meta['episode_snapshot'] ?? []);
            $key = $typeOf((string) ($meta['signal_type'] ?? ''), $meta['cause'] ?? null);
            $by[$key] ??= ['clicked' => 0, 'state_at_click' => [], 'open_lags' => [], 'confirm_lags' => [], 'real' => 0, 'not_fault' => 0, 'unknown' => 0, 'open' => 0, 'as_guessed' => 0];
            $row = &$by[$key];
            $row['clicked']++;
            $state = (string) ($snap['state_at_click'] ?? '-');
            $row['state_at_click'][$state] = ($row['state_at_click'][$state] ?? 0) + 1;
            $at = Carbon::instance($t->created_at);
            foreach (['opened_at' => 'open_lags', 'confirmed_at' => 'confirm_lags'] as $field => $bucket) {
                if (! empty($snap[$field])) {
                    $row[$bucket][] = max(0, (int) round(Carbon::parse($snap[$field])->diffInSeconds($at, false) / 60));
                }
            }

            $cause = trim((string) $t->root_cause);
            if (! in_array($t->status, ['resolved', 'closed', 'cancelled'], true)) {
                $row['open']++;
            } elseif ($t->status === 'cancelled' || in_array($cause, self::NOT_FAULT_CAUSES, true)) {
                $row['not_fault']++;
            } elseif (in_array($cause, self::REAL_CAUSES, true) || str_starts_with($cause, 'pppoe_')) {
                $row['real']++;
            } else {
                $row['unknown']++; // empty, 'unknown' or any code not listed above
            }
            if (in_array($t->status, ['resolved', 'closed'], true) && $cause !== '' && $cause !== 'unknown' && $cause === (string) ($snap['diagnosed_cause'] ?? '')) {
                $row['as_guessed']++;
            }
            unset($row);
        }

        $out = [];
        foreach (array_unique([...array_keys($shown), ...array_keys($by)]) as $type) {
            $b = $by[$type] ?? ['clicked' => 0, 'state_at_click' => [], 'open_lags' => [], 'confirm_lags' => [], 'real' => 0, 'not_fault' => 0, 'unknown' => 0, 'open' => 0, 'as_guessed' => 0];
            $n = (int) ($shown[$type] ?? 0);
            $judged = $b['real'] + $b['not_fault'];
            sort($b['open_lags']);
            sort($b['confirm_lags']);
            ksort($b['state_at_click']);
            $out[$type] = [
                'shown' => $n,
                'clicked' => $b['clicked'],
                'click_rate' => $n > 0 ? round($b['clicked'] / $n, 3) : null,
                'state_at_click' => $b['state_at_click'],
                'median_open_to_click_min' => $this->median($b['open_lags']),
                'median_confirm_to_click_min' => $this->median($b['confirm_lags']),
                'outcome' => ['real_fault' => $b['real'], 'not_fault' => $b['not_fault'], 'unknown' => $b['unknown'], 'still_open' => $b['open']],
                'as_guessed' => $b['as_guessed'],
                'proxy_precision' => $judged >= self::LABEL_MIN_SAMPLE ? round($b['real'] / $judged, 3) : null,
                'proxy_sample_ok' => $judged >= self::LABEL_MIN_SAMPLE,
            ];
        }
        ksort($out);

        return $out;
    }

    /** @return array<string,mixed> */
    private function coverage(): array
    {
        $names = Olt::query()->pluck('name', 'id');
        $active = (int) Customer::query()->where('status', 'active')->count();
        $perOlt = [];
        $monitorable = 0;
        foreach (OutageSettings::oltIds() as $id) {
            $total = (int) Customer::query()->where('status', 'active')->where('olt_id', $id)->count();
            $mapped = (int) Customer::query()->where('status', 'active')->where('olt_id', $id)
                ->whereNotNull('olt_port_label')->where('olt_port_label', '!=', '')->count();
            $monitorable += $mapped;
            $perOlt[] = ['olt' => (string) ($names[$id] ?? 'OLT '.$id), 'active' => $total, 'monitorable' => $mapped];
        }

        return [
            'active_customers' => $active,
            'monitorable' => $monitorable,
            'ratio' => $active > 0 ? round($monitorable / $active, 3) : null,
            'per_olt' => $perOlt,
        ];
    }

    /** @return list<array{olt:string,minutes_since_success:?int}> monitored OLTs whose collector has no fresh success */
    public function staleOlts(): array
    {
        $names = Olt::query()->pluck('name', 'id');
        $limit = OutageSettings::stateMaxAgeMinutes();
        $out = [];
        foreach (OutageSettings::oltIds() as $id) {
            $last = OltCollectRun::query()->where('olt_id', $id)->where('status', 'success')
                ->whereIn('tier', ['state', 'huawei_optical'])->max('finished_at');
            $age = $last === null ? null : (int) Carbon::parse($last)->diffInMinutes(now());
            if ($age === null || $age > max($limit, 30)) {
                $out[] = ['olt' => (string) ($names[$id] ?? 'OLT '.$id), 'minutes_since_success' => $age];
            }
        }

        return $out;
    }

    /**
     * Re-evaluate would_action for alternative thresholds from the stored episode data.
     * Population: episodes not suppressed for another reason (quiet_hours still counts).
     *
     * @param  array<string,list<float>>  $replay
     * @return array<string,list<array<string,mixed>>>
     */
    private function replay($signals, array $replay, float $days): array
    {
        $typeFor = ['mass_min' => 'mass_los', 'single_los' => 'single_los', 'weak_rx' => 'weak_rx'];
        $out = [];
        foreach ($replay as $key => $candidates) {
            $pop = $signals->filter(fn (OutageSignal $s) => $s->signal_type === $typeFor[$key]
                && ($s->suppressed_reason === null || $s->suppressed_reason === 'quiet_hours'));
            foreach ($candidates as $cand) {
                $would = 0;
                $matched = 0;
                $noData = 0;
                foreach ($pop as $s) {
                    $value = $this->replayValue($key, $s);
                    if ($value === null) {
                        $noData++;

                        continue;
                    }
                    $pass = $key === 'weak_rx' ? $value < $cand : $value >= $cand;
                    if ($pass) {
                        $would++;
                        if ($s->matched_ticket_id !== null) {
                            $matched++;
                        }
                    }
                }
                $out[$key][] = [
                    'threshold' => $cand,
                    'would_ticket' => $would,
                    'per_day' => round($would / $days, 2),
                    'matched' => $matched,
                    'precision_matched' => $would > 0 ? round($matched / $would, 3) : null,
                    'no_data' => $noData,
                ];
            }
        }

        return $out;
    }

    private function replayValue(string $key, OutageSignal $s): ?float
    {
        $payload = (array) $s->payload;
        if ($key === 'mass_min') {
            return (float) $s->affected_count;
        }
        if ($key === 'single_los') {
            $end = $s->resolved_at ?? $s->last_seen_at ?? $s->confirmed_at;

            return $end === null ? null : max(0.0, (float) ($s->opened_at->diffInMinutes($end)));
        }
        foreach (['worst_dbm', 'min_dbm', 'rx_dbm'] as $k) {
            if (isset($payload[$k]) && is_numeric($payload[$k])) {
                return (float) $payload[$k];
            }
        }

        return null;
    }
}
