<?php

namespace App\Services\Outage\Diagnosis;

use App\Models\OutageSignal;
use App\Models\SupportTicket;
use App\Services\Outage\OutageSettings;
use Illuminate\Support\Carbon;

/**
 * RCA-4 history: builds the `history` block of the context (the rolling last 24 hours) from what OutageCorrelator
 * already read plus two small indexed queries. DB only, never opens a device session, never writes, never throws
 * (a failed read gives null: the diagnosis then simply has no history lines).
 *
 *  - onu_drops: ONU drops (not the ones that follow another phase change), poll rows and the OLT's own history merged
 *    into one list; drops that fall inside a mass_los / olt_wide episode of the same OLT (+PON) are `area`.
 *  - ppp_drops: PPPoE drops / reconnects clustered per 10 minutes from the router log, the CPE, pppoe_down
 *    episodes and the live router read; a cluster that follows an ONU drop or restart is `explained`.
 * All times are UTC Carbon objects; display happens in DiagnosisEvidence::clock() (WIB).
 */
class OutageHistoryReader
{
    /** Same event seen by two sources (a 5 minute poll vs the OLT's own history) is one drop within this many seconds. */
    private const MERGE_SOURCES_SECONDS = 360;

    private const MERGE_SAME_SOURCE_SECONDS = 120;

    /** PPP events of one source closer than this are one drop (a disconnect and the reconnect after it). */
    private const PPP_CLUSTER_SECONDS = 600;

    /** A polled / derived time (episode, CPE, router) joins a cluster this close. */
    private const PPP_CROSS_SOURCE_SECONDS = 900;

    /**
     * @param  array<string,mixed>  $ctx  the context so far (customer, olt, router, cpe, onu incl. `drops`, pppoe incl. `events`)
     * @param  array<string,mixed>  $probes
     * @return array{history:array<string,mixed>,unexplained_6h:int}|null
     */
    public function build(array $ctx, array $probes, ?int $ticketId = null): ?array
    {
        try {
            return $this->read($ctx, $probes, $ticketId);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{history:array<string,mixed>,unexplained_6h:int} */
    private function read(array $ctx, array $probes, ?int $ticketId): array
    {
        /** @var Carbon $now */
        $now = $ctx['now'];
        $since = $now->copy()->subHours(Rules::HISTORY_HOURS);

        $onu = $this->onuDrops($ctx, $probes, $since, $now);
        $boots = array_values(array_filter((array) ($ctx['cpe']['boot_times'] ?? []), fn ($t) => $t instanceof Carbon && $t->gte($since)));

        $onuLog = in_array($ctx['olt']['family'] ?? null, ['zte', 'huawei'], true);
        [$ppp, $last] = $this->pppDrops($ctx, $probes, $since, $now, array_map(fn (array $d) => $d['at'], $onu), $boots, $onuLog);

        // router-log drops of the last 6 hours that nothing explains (the 6 hour pppoe_down rule)
        $sixHours = $now->copy()->subHours(6);
        $unexplained = count(array_filter($ppp, fn (array $d) => ! $d['explained'] && in_array('syslog', $d['sources'], true) && ($d['end'] ?? $d['at'])->gte($sixHours)));

        $family = $ctx['olt']['family'] ?? null;
        $rxAt = $ctx['customer']['rx_at'] ?? ($ctx['onu']['collected_at'] ?? null);
        $rx = [
            'dbm' => $ctx['customer']['rx_dbm'],
            'at' => $rxAt instanceof Carbon ? $rxAt : null,
            'old' => $rxAt instanceof Carbon && $rxAt->lt($since),
        ];
        $source = 'none';
        foreach ($ppp as $d) {
            if (in_array('syslog', $d['sources'], true)) {
                $source = 'syslog';
                break;
            }
            $source = 'poll';
        }

        return [
            'history' => [
                'onu_drops' => $onu,
                'ppp_drops' => $ppp,
                'ppp_source' => $source,
                'ppp_last' => $last,
                'onu_log' => in_array($family, ['zte', 'huawei'], true),
                'ppp_log' => $ctx['customer']['router_id'] !== null && in_array((int) $ctx['customer']['router_id'], array_values(OutageSettings::pppoeSyslogSources()), true),
                'rx' => $rx,
                'ticket_rx_complaint' => $this->ticketMentionsRx($ticketId),
            ],
            'unexplained_6h' => $unexplained,
        ];
    }

    // ------------------------------------------------------------------ ONU

    /**
     * @return list<array{at:Carbon,cause:?string,source:string,area:bool}>
     */
    private function onuDrops(array $ctx, array $probes, Carbon $since, Carbon $now): array
    {
        $out = [];
        $add = function (Carbon $at, ?string $cause, string $source) use (&$out, $since, $now): void {
            if ($at->lt($since) || $at->gt($now->copy()->addMinutes(5))) {
                return; // outside the window; a time in the future is a clock error
            }
            foreach ($out as $i => $d) {
                $gap = abs($d['at']->getTimestamp() - $at->getTimestamp());
                $limit = $d['source'] === $source ? self::MERGE_SAME_SOURCE_SECONDS : self::MERGE_SOURCES_SECONDS;
                if ($gap <= $limit) {
                    // the OLT's own history (exact time + cause) wins over a 5 minute poll
                    $authoritative = in_array($source, ['olt_detail', 'olt_live', 'huawei_info'], true) && ! in_array($d['source'], ['olt_detail', 'olt_live', 'huawei_info'], true);
                    if ($authoritative || ($d['cause'] === null && $cause !== null)) {
                        $out[$i]['cause'] = $cause ?? $d['cause'];
                    }
                    if ($authoritative) {
                        $out[$i]['at'] = $at;
                        $out[$i]['source'] = $source;
                    } elseif ($source === 'olt_live' && $d['source'] === 'olt_detail') {
                        $out[$i]['source'] = 'olt_live';
                    }

                    return;
                }
            }
            $out[] = ['at' => $at, 'cause' => $cause, 'source' => $source, 'area' => false];
        };

        foreach ((array) ($ctx['onu']['drops'] ?? []) as $d) {
            $add($d['at'], $d['cause'], (string) $d['source']);
        }
        $live = $probes['olt']['detail']['history_drops'] ?? [];
        foreach (is_array($live) ? $live : [] as $row) {
            try {
                $at = Carbon::parse((string) ($row['at'] ?? ''), 'UTC')->utc();
            } catch (\Throwable) {
                continue;
            }
            $add($at, isset($row['cause']) && $row['cause'] !== '' ? (string) $row['cause'] : null, 'olt_live');
        }
        usort($out, fn (array $a, array $b) => $a['at']->getTimestamp() <=> $b['at']->getTimestamp());

        return $this->flagArea($ctx, $out, $since);
    }

    /**
     * A drop inside a mass_los (same PON) or olt_wide episode of the same OLT (+- 15 minutes: the episode opens after
     * the first drops) is part of an outage that hit other customers too, not a fault of this line.
     *
     * @param  list<array<string,mixed>>  $drops
     * @return list<array<string,mixed>>
     */
    private function flagArea(array $ctx, array $drops, Carbon $since): array
    {
        $oltId = $ctx['customer']['olt_id'] ?? null;
        if ($drops === [] || $oltId === null) {
            return $drops;
        }
        $pon = $ctx['customer']['pon'] ?? null;
        $now = $ctx['now'];
        $rca5 = (bool) ($ctx['rca5'] ?? true);
        $episodes = OutageSignal::query()->whereIn('signal_type', ['mass_los', 'olt_wide'])->where('olt_id', $oltId)
            ->where('opened_at', '>=', $since->copy()->subDay()->utc()->format('Y-m-d H:i:s'))
            ->orderByDesc('opened_at')->limit(50)->get(['signal_type', 'pon_port', 'opened_at', 'resolved_at', 'last_seen_at', 'confirmed_at', 'payload']);
        foreach ($episodes as $ep) {
            if ($ep->signal_type === 'mass_los' && ($pon === null || (string) $ep->pon_port !== (string) $pon)) {
                continue;
            }
            $from = Carbon::instance($ep->opened_at)->utc()->subMinutes(15);
            if ($rca5 && $ep->signal_type === 'mass_los') {
                // RCA-5: only a confirmed / whole PON event marks a drop as "area", and only around its first
                // minutes (a mass_los kept open for a day by two leftovers is not the drop moment of every later drop)
                if ($ep->confirmed_at === null && empty(((array) $ep->payload)['whole_pon'])) {
                    continue;
                }
                $to = Carbon::instance($ep->opened_at)->utc()->addMinutes(30);
            } else {
                $endRaw = $ep->resolved_at ?? $ep->last_seen_at ?? $now;
                $to = Carbon::instance($endRaw)->utc()->addMinutes(10);
            }
            foreach ($drops as $i => $d) {
                if ($d['at']->gte($from) && $d['at']->lte($to)) {
                    $drops[$i]['area'] = true;
                }
            }
        }

        return $drops;
    }

    // ------------------------------------------------------------------ PPPoE

    /**
     * Real PPP drops, not events: a disconnect / episode start / last-logged-out OPENS a drop, the next reconnect (router
     * log, CPE, live session) CLOSES it however long that takes; every source that describes the same drop is merged
     * into it. A reconnect with no open drop is one drop. A "stuck" burst next to a drop belongs to it; a lone burst is
     * at most one drop. Episode `resolved_at` is NOT a close (the detector resolves 30 min after the session is back).
     *
     * @param  list<Carbon>  $onuTimes  ONU drops
     * @param  list<Carbon>  $boots  ONU restarts
     * @return array{0:list<array<string,mixed>>,1:?array<string,mixed>} drops, last unexplained disconnect reason
     */
    private function pppDrops(array $ctx, array $probes, Carbon $since, Carbon $now, array $onuTimes, array $boots, bool $onuLog): array
    {
        /** @var list<array{at:Carbon,source:string,kind:string,reason:?string}> $items */
        $items = [];
        foreach ((array) ($ctx['pppoe']['events'] ?? []) as $e) {
            if (in_array($e['event'], ['connected', 'disconnected', 'stuck'], true) && $e['at']->gte($since)) {
                $items[] = ['at' => $e['at'], 'source' => $e['source'] === 'syslog' ? 'syslog' : 'poll', 'kind' => $e['event'] === 'connected' ? 'reconnect' : (string) $e['event'], 'reason' => $e['reason'] ?? null];
            }
        }
        foreach ((array) ($ctx['cpe']['ppp_reconnects'] ?? []) as $at) {
            if ($at instanceof Carbon && $at->gte($since)) {
                $items[] = ['at' => $at, 'source' => 'cpe', 'kind' => 'reconnect', 'reason' => null];
            }
        }
        $customerId = (int) $ctx['customer']['id'];
        $episodes = OutageSignal::query()->where('customer_id', $customerId)->where('signal_type', 'pppoe_down')
            ->where('opened_at', '>=', $since->copy()->utc()->format('Y-m-d H:i:s'))->orderByDesc('opened_at')->limit(30)->get(['opened_at']);
        foreach ($episodes as $ep) {
            // an episode opens after the session has been gone for pppoe_down_minutes: that is when it dropped
            $items[] = ['at' => Carbon::instance($ep->opened_at)->utc()->subMinutes(OutageSettings::pppoeDownMinutes()), 'source' => 'episode', 'kind' => 'drop', 'reason' => null];
        }
        $uptime = $ctx['pppoe']['router_session_uptime_s'] ?? null;
        if ($uptime !== null && $uptime > 0 && $uptime < Rules::HISTORY_HOURS * 3600) {
            $items[] = ['at' => $now->copy()->subSeconds((int) $uptime), 'source' => 'router_live', 'kind' => 'reconnect', 'reason' => null];
        }
        $secret = is_array($probes['router']['secret'] ?? null) ? $probes['router']['secret'] : [];
        $loggedOut = $this->routerTime((string) ($secret['last-logged-out'] ?? ''), $since, $now);
        if ($loggedOut !== null) {
            $items[] = ['at' => $loggedOut, 'source' => 'router_secret', 'kind' => 'disconnected', 'reason' => isset($secret['last-disconnect-reason']) ? (string) $secret['last-disconnect-reason'] : null];
        }

        usort($items, fn (array $a, array $b) => $a['at']->getTimestamp() <=> $b['at']->getTimestamp());
        /** @var list<array<string,mixed>> $d */
        $d = [];
        foreach ($items as $item) {
            $i = count($d) - 1;
            $cur = $i >= 0 ? $d[$i] : null;
            $ts = $item['at']->getTimestamp();
            if (in_array($item['kind'], ['disconnected', 'drop'], true)) {
                // the same drop seen by another source (or a second disconnect right after the first)
                if ($cur !== null && ! $cur['stuck_only'] && $ts - $cur['start']->getTimestamp() <= self::PPP_CROSS_SOURCE_SECONDS) {
                    $d[$i]['sources'][] = $item['source'];
                    $this->takeReason($d[$i], $item);

                    continue;
                }
                $d[] = $this->newDrop($item, $item['at'], null);
                $this->takeReason($d[count($d) - 1], $item);
            } elseif ($item['kind'] === 'reconnect') {
                if ($cur !== null && $cur['end'] === null && ! $cur['stuck_only']) {
                    $d[$i]['end'] = $item['at']; // closes the open drop, however long it lasted
                    $d[$i]['sources'][] = $item['source'];
                    $d[$i]['last'] = $item['at'];

                    continue;
                }
                if ($cur !== null && $cur['end'] !== null && $ts - $cur['end']->getTimestamp() <= self::PPP_CROSS_SOURCE_SECONDS) {
                    $d[$i]['sources'][] = $item['source']; // the same reconnect from another source

                    continue;
                }
                if ($cur !== null && $cur['stuck_only'] && $ts - $cur['last']->getTimestamp() <= self::PPP_CLUSTER_SECONDS) {
                    $d[$i]['stuck_only'] = false; // the burst was the retry of this reconnect
                    $d[$i]['end'] = $item['at'];
                    $d[$i]['sources'][] = $item['source'];
                    $d[$i]['last'] = $item['at'];

                    continue;
                }
                $d[] = $this->newDrop($item, $item['at'], $item['at']); // a reconnect nobody saw the drop of
            } else { // stuck
                if ($cur !== null && $ts - $cur['last']->getTimestamp() <= self::PPP_CLUSTER_SECONDS) {
                    $d[$i]['last'] = $item['at'];
                    $d[$i]['sources'][] = $item['source'];

                    continue;
                }
                $d[] = $this->newDrop($item, $item['at'], $item['at']);
                $d[count($d) - 1]['stuck_only'] = true; // a burst away from any drop: one drop at most
            }
        }

        $drops = [];
        $last = null;
        foreach ($d as $x) {
            $explained = $this->explained($x, $onuTimes, $boots, $onuLog);
            $drops[] = ['at' => $x['start'], 'end' => $x['end'], 'explained' => $explained, 'sources' => array_values(array_unique($x['sources'])), 'reason' => $x['reason']];
            if (! $explained && $x['reason'] !== null && $x['reason'] !== '') {
                $last = ['at' => $x['start'], 'reason' => (string) $x['reason'], 'source' => $x['reason_source'] === 'syslog' ? 'syslog' : 'router_live'];
            }
        }

        return [$drops, $last];
    }

    /** @return array<string,mixed> */
    private function newDrop(array $item, Carbon $start, ?Carbon $end): array
    {
        return ['start' => $start, 'end' => $end, 'last' => $item['at'], 'sources' => [$item['source']], 'reason' => null, 'reason_source' => $item['source'], 'stuck_only' => false];
    }

    private function takeReason(array &$drop, array $item): void
    {
        if ($item['kind'] === 'disconnected' && $item['reason'] !== null && $item['reason'] !== '') {
            $drop['reason'] = $item['reason'];
            $drop['reason_source'] = $item['source'];
        }
    }

    /**
     * An ONU drop shortly before / during the PPP drop explains it. An ONU restart explains it on EVERY OLT only when it
     * comes right with the drop (-2 .. +10 min of its start). A later restart (inside the drop, or after an unclosed one)
     * counts only where the OLT keeps no ONU history (HiOSO: a switched-off ONU is invisible), or when an ONU drop is
     * recorded between the drop and the restart. Otherwise it is the customer restarting the modem during a NETWORK
     * outage ("sudah restart modem"): the ONU kept its power and the OLT saw nothing.
     *
     * @param  array<string,mixed>  $drop
     * @param  list<Carbon>  $onuTimes
     * @param  list<Carbon>  $boots
     */
    private function explained(array $drop, array $onuTimes, array $boots, bool $onuLog): bool
    {
        $start = $drop['start']->getTimestamp();
        $end = ($drop['end'] ?? $drop['last'])->getTimestamp();
        $open = $drop['end'] === null && ! $drop['stuck_only'];
        foreach ($onuTimes as $t) {
            $ts = $t->getTimestamp();
            if ($ts >= $start - Rules::EXPLAIN_MINUTES * 60 && $ts <= ($open ? $start : $end) + 120) {
                return true;
            }
        }
        foreach ($boots as $b) {
            $ts = $b->getTimestamp();
            if ($ts >= $start - 120 && $ts <= $start + Rules::EXPLAIN_MINUTES * 60) {
                return true; // right with the drop: every OLT
            }
            // later: an unclosed drop may end hours after the ONU came back (night power-off)
            if ($ts > $start + Rules::EXPLAIN_MINUTES * 60 && $ts <= ($open ? $start + 12 * 3600 : $end + Rules::EXPLAIN_MINUTES * 60)) {
                if (! $onuLog) {
                    return true;
                }
                foreach ($onuTimes as $t) {
                    if ($t->getTimestamp() >= $start - Rules::EXPLAIN_MINUTES * 60 && $t->getTimestamp() <= $ts) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * RouterOS prints local router time: v7 "2026-10-20 14:03:11", v6 "oct/20/2026 14:03:11". Converted to UTC with
     * config diagnosis.router_clock_timezone. Unparsable, older than the window or in the future => null.
     */
    private function routerTime(string $text, Carbon $since, Carbon $now): ?Carbon
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }
        $tz = (string) config('diagnosis.router_clock_timezone', 'Asia/Jakarta');
        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $text) === 1) {
                $at = Carbon::createFromFormat('Y-m-d H:i:s', $text, $tz);
            } elseif (preg_match('/^([A-Za-z]{3})\/(\d{2})\/(\d{4}) (\d{2}:\d{2}:\d{2})$/', $text, $m) === 1) {
                $at = Carbon::createFromFormat('M/d/Y H:i:s', ucfirst(strtolower($m[1])).'/'.$m[2].'/'.$m[3].' '.$m[4], $tz);
            } else {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }
        if (! $at instanceof Carbon) {
            return null;
        }
        $at = $at->utc();

        return ($at->lt($since) || $at->gt($now->copy()->addMinutes(2))) ? null : $at;
    }

    // ------------------------------------------------------------------ ticket text

    /** The ticket talks about optical power ("redaman", LOS, red light): then a normal RX now deserves a note. */
    private function ticketMentionsRx(?int $ticketId): bool
    {
        if ($ticketId === null) {
            return false;
        }
        $ticket = SupportTicket::query()->whereKey($ticketId)->first();
        if ($ticket === null) {
            return false;
        }
        $text = implode(' ', array_filter([(string) ($ticket->subject ?? ''), (string) ($ticket->description ?? ''), (string) ($ticket->gangguan ?? '')]));

        return preg_match('/redaman|\blos\b|lampu merah/i', $text) === 1;
    }
}
