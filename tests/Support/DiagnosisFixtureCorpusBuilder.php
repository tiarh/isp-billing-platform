<?php

namespace Tests\Support;

use App\Models\Customer;
use App\Models\Olt;
use App\Models\OltOnu;
use App\Models\OutageOnuTracker;
use App\Models\OutageSignal;
use App\Models\Package;
use App\Models\Router;
use App\Services\Outage\Diagnosis\Backtest\CorpusScrubber;
use App\Services\Outage\Diagnosis\DiagnosisContextSnapshot;
use App\Services\Outage\Diagnosis\OutageCorrelator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Converts the hand-replay fixture export (ids only, serials hashed, strings scrubbed: ebilling-notes/backtest/
 * fixtures-20261019-25.json) into corpus rows. For each ticket the database is rebuilt as it was at the ticket's
 * creation time (events after that moment are left out, ONU phases come from the event history, RX from the daily
 * medians) and the REAL correlator builds the context, so the stored snapshot is what a deployed #618 would have
 * stored. Approximations (the fixture holds no live state for these): the GenieACS WLAN values and the `is_online`
 * poll are rebuilt from the syslog events; the ticket text is not known (no optical-complaint flag).
 *
 * Needs a database (RefreshDatabase). Used by the one-off regeneration test and by the round-trip property test.
 */
final class DiagnosisFixtureCorpusBuilder
{
    /** @param  array<string,mixed>  $f  decoded fixture */
    public function __construct(private readonly array $f) {}

    /**
     * @return list<array{ticket:array<string,mixed>,ctx:array<string,mixed>,row:array<string,mixed>}>
     */
    public function build(): array
    {
        $this->seedStatic();
        $out = [];
        foreach ($this->f['tickets'] as $t) {
            $at = Carbon::parse($t['created_at'], 'UTC');
            $ctx = $this->contextAt($t, $at);
            $out[] = ['ticket' => $t, 'ctx' => $ctx, 'row' => $this->row($t, $at, $ctx)];
        }

        return $out;
    }

    // ------------------------------------------------------------------ static rows

    /** @var array<string,array{olt_id:int,customer_id:?int,rx:array<string,float>}> ref => onu */
    private array $onus = [];

    private function seedStatic(): void
    {
        $package = Package::firstOrCreate(['code' => 'FX-PKG'], ['name' => 'Fixture', 'price' => 100000]);
        $routerIds = array_values(array_unique(array_map(fn ($c) => (int) $c['router_id'], $this->f['customers'])));
        foreach ($routerIds as $rid) {
            Router::unguarded(fn () => Router::create(['id' => $rid, 'name' => 'router-'.$rid, 'ip_address' => '10.99.'.intdiv($rid, 256).'.'.($rid % 256), 'username' => 'u', 'password' => 'canary-pw', 'port' => 8728, 'is_active' => true, 'connection_status' => 'online']));
        }
        $oltIds = array_values(array_unique(array_filter(array_map(fn ($c) => $c['olt_id'], $this->f['customers']))));
        foreach ($oltIds as $oid) {
            Olt::unguarded(fn () => Olt::create(['id' => $oid, 'name' => 'olt-'.$oid, 'code' => 'FX-'.$oid, 'vendor' => (int) $oid === 8 ? 'hioso' : 'zte_c300', 'management_ip' => '10.98.0.'.$oid, 'username' => 'u', 'password' => 'canary-pw']));
        }
        config(['outage.olt_ids' => $oltIds, 'outage.serial_fallback_olt_ids' => []]);

        $refOf = [];
        foreach ($this->f['tracker_ticket_onus'] as $o) {
            $refOf[(int) $o['customer_id']] = [(int) $o['olt_id'], (string) $o['onu_ref']];
            $this->registerOnu((int) $o['olt_id'], (string) $o['onu_ref'], (int) $o['customer_id'], $this->dailyFromTicketFormat($o['rx_daily']));
        }
        foreach ($this->colRows($this->f['tracker_pon_peers']) as $p) {
            $this->registerOnu((int) $p['olt_id'], (string) $p['onu_ref'], $p['customer_id'] !== null ? (int) $p['customer_id'] : null, $this->dailyFromPeerFormat($p['rx_daily']));
        }
        foreach ($this->f['customers'] as $c) {
            $status = in_array($c['status_now'], ['active', 'isolated', 'suspended', 'terminated'], true) ? $c['status_now'] : 'active';
            Customer::unguarded(fn () => Customer::create([
                'id' => (int) $c['id'], 'code' => 'FX-'.$c['id'], 'name' => 'Fixture '.$c['id'], 'phone' => '0812000'.str_pad((string) $c['id'], 6, '0', STR_PAD_LEFT), 'address' => 'Contoh',
                'pppoe_user' => 'fx.'.$c['id'], 'package_id' => $package->id, 'status' => $status, 'router_id' => (int) $c['router_id'],
                'olt_id' => $c['olt_id'] !== null ? (int) $c['olt_id'] : null, 'olt_port_label' => ($refOf[(int) $c['id']][1] ?? null), 'odp_name' => $c['odp_name'],
                'mikrotik_profile' => $c['mikrotik_profile_now'],
            ]));
        }
    }

    /** @param  array<string,float>  $rx */
    private function registerOnu(int $oltId, string $ref, ?int $customerId, array $rx): void
    {
        $key = $oltId.'|'.$ref;
        if (isset($this->onus[$key])) {
            // the ticket export and the PON peers list the same ONU: merge (days of the later call win)
            $rx = $rx + $this->onus[$key]['rx'];
            $customerId ??= $this->onus[$key]['customer_id'];
            ksort($rx);
        }
        $this->onus[$key] = ['olt_id' => $oltId, 'customer_id' => $customerId, 'rx' => $rx, 'ref' => $ref];
        OutageOnuTracker::query()->updateOrCreate(['olt_id' => $oltId, 'onu_ref' => $ref], ['customer_id' => $customerId, 'phase' => 'online', 'rx_daily' => $this->dailyRows($rx)]);
    }

    /** @param  array<string,float>  $rx @return list<array{date:string,dbm:float,n:int}> */
    private function dailyRows(array $rx): array
    {
        $out = [];
        foreach ($rx as $date => $dbm) {
            $out[] = ['date' => $date, 'dbm' => $dbm, 'n' => 12];
        }

        return $out;
    }

    /** @param  list<array{0:int,1:float,2:string}>  $rows  [n, dbm, date] @return array<string,float> */
    private function dailyFromTicketFormat(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r[2]] = (float) $r[1];
        }

        return $out;
    }

    /** @param  list<array{0:string,1:float,2:int}>  $rows  [date, dbm, n] @return array<string,float> */
    private function dailyFromPeerFormat(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r[0]] = (float) $r[1];
        }

        return $out;
    }

    /** @param  array{cols:list<string>,rows:list<list<mixed>>}  $section @return list<array<string,mixed>> */
    private function colRows(array $section): array
    {
        return array_map(fn (array $r) => array_combine($section['cols'], $r), $section['rows']);
    }

    // ------------------------------------------------------------------ one ticket

    /** @param  array<string,mixed>  $t */
    private function contextAt(array $t, Carbon $at): array
    {
        $fmt = fn (Carbon $c): string => $c->copy()->utc()->format('Y-m-d H:i:s');
        $limit = $fmt($at);

        // events up to the ticket only (the correlator reads "the last 24 h" without an upper bound)
        DB::table('onu_state_events')->delete();
        DB::table('pppoe_session_events')->delete();
        DB::table('cpe_events')->delete();
        $onuEvents = array_values(array_filter($this->colRows($this->f['onu_state_events']), fn (array $e) => $e['occurred_at'] <= $limit));
        foreach (array_chunk($onuEvents, 200) as $chunk) {
            DB::table('onu_state_events')->insert(array_map(fn (array $e) => [
                'id' => $e['id'], 'olt_id' => $e['olt_id'], 'onu_ref' => $e['onu_ref'], 'customer_id' => $e['customer_id'], 'from_phase' => $e['from_phase'], 'to_phase' => $e['to_phase'],
                'cause' => $e['cause'], 'source' => $e['source'], 'occurred_at' => $e['occurred_at'], 'collected_at' => $e['occurred_at'],
            ], $chunk));
        }
        $pppoe = array_values(array_filter($this->colRows($this->f['pppoe_session_events']), fn (array $e) => $e['at'] <= $limit));
        foreach (array_chunk($pppoe, 200) as $chunk) {
            DB::table('pppoe_session_events')->insert(array_map(fn (array $e) => [
                'id' => $e['id'], 'router_id' => $e['router_id'], 'pppoe_user' => 'fx.'.$e['customer_id'], 'customer_id' => $e['customer_id'], 'event' => $e['event'],
                'reason' => $e['reason'], 'at' => $e['at'], 'source' => $e['source'],
            ], $chunk));
        }
        $cpe = array_values(array_filter($this->colRows($this->f['cpe_events']), fn (array $e) => $e['at'] <= $limit));
        foreach ($cpe as $e) {
            DB::table('cpe_events')->insert(['id' => $e['id'], 'customer_id' => $e['customer_id'], 'device_id' => 'fx-device', 'type' => $e['type'], 'at' => $e['at']]);
        }

        // signals that existed at the ticket time, open or closed as they were then
        OutageSignal::query()->delete();
        foreach ($this->f['outage_signals'] as $s) {
            if ($s['opened_at'] > $limit) {
                continue;
            }
            $resolved = $s['resolved_at'] !== null && $s['resolved_at'] <= $limit;
            OutageSignal::unguarded(fn () => OutageSignal::create([
                'id' => $s['id'], 'signal_type' => $s['signal_type'], 'scope_key' => 'fx:'.$s['id'], 'olt_id' => $s['olt_id'], 'pon_port' => $s['pon_port'], 'odp_label' => $s['odp_label'],
                'customer_id' => $s['customer_id'], 'state' => $resolved ? 'resolved' : ($s['confirmed_at'] !== null && $s['confirmed_at'] <= $limit ? 'confirmed' : 'open'),
                'opened_at' => $s['opened_at'], 'confirmed_at' => ($s['confirmed_at'] !== null && $s['confirmed_at'] <= $limit) ? $s['confirmed_at'] : null,
                'resolved_at' => $resolved ? $s['resolved_at'] : null, 'last_seen_at' => min($s['last_seen_at'] ?? $limit, $limit), 'affected_count' => $s['affected_count'],
                'affected_customer_ids' => $s['affected_customer_ids'], 'payload' => $s['payload'],
            ]));
        }

        // ONU phases at the ticket time, from the event history
        OltOnu::query()->delete();
        $fresh = $fmt($at->copy()->subMinute());
        $rxAt = $fmt($at->copy()->subMinutes(30));
        foreach ($this->onus as $onu) {
            $phase = 'online';
            $since = null;
            foreach ($onuEvents as $e) {
                if ((int) $e['olt_id'] === $onu['olt_id'] && $e['onu_ref'] === $onu['ref']) {
                    $phase = (string) $e['to_phase'];
                    $since = $e['occurred_at'];
                }
            }
            $rx = $this->rxAt($onu['rx'], $at);
            OutageOnuTracker::query()->where('olt_id', $onu['olt_id'])->where('onu_ref', $onu['ref'])->update(['phase' => $phase, 'phase_since_at' => $since, 'last_state_collected_at' => $fresh]);
            [$state, $phaseState] = match ($phase) {
                'online' => ['working', 'working'], 'los' => ['offline', 'LOS'], 'dying_gasp' => ['offline', 'DyingGasp'], default => ['offline', 'OffLine'],
            };
            OltOnu::create([
                'olt_id' => $onu['olt_id'], 'onu_ref' => $onu['ref'], 'pon_port' => substr($onu['ref'], 0, (int) strrpos($onu['ref'], ':')), 'state' => $state, 'phase_state' => $phaseState,
                'rx_power_dbm' => $rx, 'state_collected_at' => $fresh, 'phase_collected_at' => $fresh, 'rx_collected_at' => $rx !== null ? $rxAt : null,
            ]);
        }
        DB::table('routers')->update(['last_health_check_at' => $fresh, 'connection_status' => 'online']);

        // the customer row as the poll left it
        $customerId = (int) $t['customer_id'];
        $last = null;
        foreach ($pppoe as $e) {
            if ((int) $e['customer_id'] === $customerId) {
                $last = $e;
            }
        }
        $own = collect($this->onus)->firstWhere('customer_id', $customerId);
        DB::table('customers')->where('id', $customerId)->update([
            'is_online' => $last !== null && $last['event'] === 'connected' ? 1 : 0, // no check time without a syslog line = "not known"
            'pppoe_online_checked_at' => $last === null ? null : $fresh,
            'onu_rx_power_dbm' => $own !== null ? $this->rxAt($own['rx'], $at) : null,
            'olt_last_synced_at' => $own !== null ? $rxAt : null,
        ]);

        /** @var OutageCorrelator $correlator */
        $correlator = app(OutageCorrelator::class);
        $correlator->reset();

        return $correlator->contextFor(Customer::query()->findOrFail($customerId), $at, [], null);
    }

    /** The daily median of the ticket day (or the newest day before it). @param  array<string,float>  $rx */
    private function rxAt(array $rx, Carbon $at): ?float
    {
        $day = $at->copy()->timezone('Asia/Jakarta')->format('Y-m-d');
        $best = null;
        foreach ($rx as $date => $dbm) {
            if ($date <= $day) {
                $best = $dbm;
            }
        }

        return $best;
    }

    // ------------------------------------------------------------------ the corpus row

    /**
     * @param  array<string,mixed>  $t
     * @param  array<string,mixed>  $ctx
     * @return array<string,mixed>
     */
    private function row(array $t, Carbon $at, array $ctx): array
    {
        $stored = null;
        foreach ($this->f['outage_diagnoses'] as $d) {
            if ((int) $d['ticket_id'] === (int) $t['id'] && $d['trigger'] === 'ticket_open') {
                $stored = $stored ?? $d;
            }
        }
        $created = Carbon::parse($t['created_at'], 'UTC');
        $resolved = $t['resolved_at'] !== null ? Carbon::parse($t['resolved_at'], 'UTC') : null;
        $source = $t['root_cause_source'] ?? null;
        $bulk = $source === 'admin' && $resolved !== null && $resolved->gt($created->copy()->addDays(7));

        return [
            'id' => 'T'.$t['id'],
            'diagnosis_id' => null,
            'trigger' => 'ticket_open',
            'created_at' => $at->copy()->utc()->toIso8601String(),
            'customer_id' => (int) $t['customer_id'],
            'router_id' => $ctx['customer']['router_id'] ?? null,
            'stored_cause' => $stored['cause_code'] ?? null,
            'stored_conf' => $stored['confidence'] ?? null,
            'stored_version' => $stored['rules_version'] ?? null,
            'timely' => true,
            'context' => CorpusScrubber::scrubContext(DiagnosisContextSnapshot::of($ctx)),
            'labels' => [['kind' => 'ticket', 'cause' => (string) $t['root_cause'], 'source' => $source ?? 'none', 'bulk' => $bulk]],
        ];
    }
}
