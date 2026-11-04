<?php

namespace App\Services\Outage\Diagnosis;

use App\Enums\CustomerStatus;
use App\Models\Customer;
use App\Models\Olt;
use App\Models\OltOnu;
use App\Models\OutageOnuTracker;
use App\Models\OutageSignal;
use App\Models\Router;
use App\Services\Outage\OutageCauseText;
use App\Services\Outage\OutageDetector;
use App\Services\Outage\OutageSettings;
use App\Services\Outage\RouterHealthReadModel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RCA-4: builds the layered, read-only context of ONE customer for the diagnosis rules. DB only: it never opens a
 * device session and never writes. Layers, from the top: router -> OLT -> PON -> cluster -> ONU -> PPPoE -> CPE ->
 * billing/admin. A higher layer explains a lower one (a dead router is ONE CORE_ROUTER, never 300 x CPE_DEAD).
 *
 * `$probes` carries live answers of "Cek sekarang" (router / olt / cpe); without them the context is the stored
 * state only. Every section is always present (null / false when unknown) so the rules never test array keys.
 */
class OutageCorrelator
{
    /** Device data (collector, router poll) younger than this counts as fresh. */
    public const FRESH_MINUTES = 15;

    /** PPPoE flags (customers.is_online) older than this are not trusted for "PPPoE is down". */
    public const PPPOE_FRESH_MINUTES = 20;

    /** routers.isolation_profile NULL means this one (same default as MikrotikService). */
    public const DEFAULT_ISOLATION_PROFILE = 'isolirebilling';

    /** @var array<int,array<string,mixed>> per-run memo of the router layer (many customers share a router) */
    private array $routerMemo = [];

    private ?OutageHistoryReader $history = null;

    public function __construct(private readonly RouterHealthReadModel $routerHealth, private readonly CpeSnapshotReader $cpe) {}

    /** Forget the per-run memo (tests / long-running commands). */
    public function reset(): void
    {
        $this->routerMemo = [];
    }

    /**
     * @param  array<string,mixed>  $probes  live answers: router / olt / cpe (see DiagnoseNowService)
     * @param  int|null  $ticketId  the ticket the diagnosis belongs to (its text says whether the complaint is about optical power)
     * @return array<string,mixed>
     */
    public function contextFor(Customer $customer, ?Carbon $now = null, array $probes = [], ?int $ticketId = null): array
    {
        $now = $now ? Carbon::instance($now) : now();
        $ref = $customer->olt_port_label !== null ? OutageDetector::normalizeRef((string) $customer->olt_port_label) : null;
        $pon = $ref !== null ? OutageDetector::ponOf($ref) : null;
        $oltId = $customer->olt_id !== null ? (int) $customer->olt_id : null;
        $status = $customer->status instanceof \BackedEnum ? (string) $customer->status->value : (string) $customer->status;

        $ctx = [
            'now' => $now,
            'customer' => [
                'id' => (int) $customer->id,
                'status' => $status,
                'pppoe_user' => $customer->pppoe_user !== null ? (string) $customer->pppoe_user : null,
                'router_id' => $customer->router_id !== null ? (int) $customer->router_id : null,
                'olt_id' => $oltId,
                'ref' => $ref,
                'pon' => $pon,
                'odp' => $customer->odp_name !== null && trim((string) $customer->odp_name) !== '' ? trim((string) $customer->odp_name) : null,
                'is_online' => $customer->is_online === null ? null : (bool) $customer->is_online,
                'online_checked_at' => $customer->pppoe_online_checked_at ? Carbon::instance($customer->pppoe_online_checked_at) : null,
                'rx_dbm' => $customer->onu_rx_power_dbm !== null ? (float) $customer->onu_rx_power_dbm : null,
                'rx_at' => $customer->olt_last_synced_at ? Carbon::instance($customer->olt_last_synced_at)->utc() : null,
                'mikrotik_profile' => $customer->mikrotik_profile !== null ? (string) $customer->mikrotik_profile : null,
            ],
            'probes' => $probes,
            'rca5' => OutageSettings::diagnosisRca5Enabled(), // kill switch: off = the 2026-10-21.1 rules
        ];
        $ctx['router'] = $this->routerLayer($ctx, $probes['router'] ?? null);
        $ctx['admin'] = $this->adminLayer($ctx, $probes['router'] ?? null); // needs the router's isolation profile
        $ctx['olt'] = $this->oltLayer($ctx);
        $ctx['pon'] = $this->ponLayer($ctx);
        $ctx['cluster'] = $this->clusterLayer($ctx);
        $ctx['cpe'] = $this->mergeCpeProbe($this->cpe->forCustomer((int) $customer->id, $now), $probes['cpe'] ?? null, $now);
        $ctx['onu'] = $this->onuLayer($ctx, $probes['olt'] ?? null);
        $ctx['pppoe'] = $this->pppoeLayer($ctx, $probes['router'] ?? null);
        // PR-D evidence (stored always, shown / used only while the three flags are on): DB only, one indexed read each
        $ctx['pppoe']['burst'] = $this->burstLayer($ctx);
        $ctx['area_power'] = $this->areaPowerLayer($ctx);
        $ctx['traffic'] = $this->trafficLayer($ctx);
        $ctx['pon'] += $this->ponBootShare($ctx);
        if ($ctx['rca5']) {
            $ponNow = $this->ponNow($ctx);
            $ctx['pon'] = array_merge($ctx['pon'], $ponNow['pon']);
            $ctx['cluster']['odp_down_now'] = $ponNow['odp_down_now'];
        }

        // last 24 hours: ONU drops, PPPoE drops, restarts, the current RX (a failed read = no block, no history lines)
        $this->history ??= new OutageHistoryReader;
        $history = $this->history->build($ctx, $probes, $ticketId);
        if ($history !== null) {
            $ctx['history'] = $history['history'];
            $ctx['pppoe']['reconnects_unexplained_6h'] = $history['unexplained_6h'];
        }

        return $ctx;
    }

    /**
     * A live CPE answer that carries its values (fake driver, or a summon that returned them) wins over the stored
     * snapshot. A real summon updates genieacs_devices instead, which CpeSnapshotReader has just read.
     *
     * @param  array<string,mixed>  $cpe
     * @param  array<string,mixed>|null  $probe
     * @return array<string,mixed>
     */
    private function mergeCpeProbe(array $cpe, ?array $probe, Carbon $now): array
    {
        if (($probe['status'] ?? null) !== 'ok' || ! is_array($probe['data'] ?? null)) {
            return $cpe;
        }
        $data = $probe['data'];
        $cpe['present'] = true;
        if (isset($data['uptime_s'])) {
            $cpe['uptime_s'] = $data['uptime_s']; // a live answer is as of now
            $cpe['uptime_now_s'] = (int) $data['uptime_s'];
        }
        foreach (['rssi', 'lan_active', 'ppp_status', 'ppp_last_error'] as $key) {
            if (array_key_exists($key, $data)) {
                $cpe[$key] = $data[$key];
            }
        }
        foreach (['24', '5'] as $band) {
            if (isset($data['wlan'][$band]) && is_array($data['wlan'][$band])) {
                $cpe['wlan'][$band] = array_merge($cpe['wlan'][$band], $data['wlan'][$band]);
            }
        }
        if (isset($data['wlan'])) {
            $cpe['wlan_at'] = $now;
            $cpe['wlan_fresh'] = true;
            $cpe['wlan_strong'] = true;
            $cpe['wlan_source'] = (string) ($data['source'] ?? 'summon');
        }

        return $cpe;
    }

    // ------------------------------------------------------------------ billing / admin

    /**
     * @param  array<string,mixed>  $ctx
     * @param  array<string,mixed>|null  $routerProbe
     * @return array<string,mixed>
     */
    private function adminLayer(array $ctx, ?array $routerProbe): array
    {
        $status = $ctx['customer']['status'];
        $billing = in_array($status, [CustomerStatus::Isolated->value, CustomerStatus::Suspended->value, CustomerStatus::Terminated->value], true);
        $secret = is_array($routerProbe['secret'] ?? null) ? $routerProbe['secret'] : null;
        $secretDisabled = $secret !== null && filter_var($secret['disabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $secretProfile = $secret !== null ? strtolower((string) ($secret['profile'] ?? '')) : '';
        $needle = mb_strtolower(CustomerStatus::Isolated->label()); // the isolation profile name contains it
        // RCA-5: also the router's own isolation profile (NULL = the default MikrotikService uses), case-insensitive
        $routerProfile = ($ctx['rca5'] ?? true) && ($ctx['router']['id'] ?? null) !== null
            ? strtolower(trim((string) ($ctx['router']['isolation_profile'] ?? '')) ?: self::DEFAULT_ISOLATION_PROFILE)
            : null;
        $iso = fn (string $profile): bool => $profile !== '' && (str_contains($profile, $needle) || ($routerProfile !== null && $profile === $routerProfile));
        $probeIsolir = $secretDisabled || $iso($secretProfile);
        $dbIsolir = $iso(strtolower(trim((string) ($ctx['customer']['mikrotik_profile'] ?? ''))));

        return [
            'billing' => $billing,
            'status' => $status,
            'secret_isolir' => $probeIsolir,
            'secret_state' => $secret === null ? 'none' : ($probeIsolir ? 'isolated' : 'normal'),
            'db_isolir' => $dbIsolir,
            'secret_disabled' => $secret !== null ? $secretDisabled : null,
        ];
    }

    // ------------------------------------------------------------------ router

    /**
     * @param  array<string,mixed>  $ctx
     * @param  array<string,mixed>|null  $routerProbe
     * @return array<string,mixed>
     */
    private function routerLayer(array $ctx, ?array $routerProbe): array
    {
        $rid = $ctx['customer']['router_id'];
        $base = [
            'id' => $rid, 'name' => null, 'isolation_profile' => null, 'blind' => null, 'offline' => false, 'all_gone' => false,
            'customers_total' => 0, 'customers_fresh_online' => 0, 'health_checked_at' => null, 'health_fresh' => false,
            'active' => null, 'probe_status' => $routerProbe['status'] ?? null,
        ];
        if ($rid !== null) {
            $base = array_merge($base, $this->routerMemo($rid, $ctx['now']));
        }
        if (is_array($routerProbe) && ($routerProbe['status'] ?? null) === 'ok') {
            $base['active'] = is_array($routerProbe['active'] ?? null) ? $routerProbe['active'] : false; // false = no session
            // a successful live read proves the router is reachable right now
            if ($base['offline']) {
                $base['offline'] = false;
                $base['all_gone'] = false;
                $base['blind'] = null;
            }
        }

        return $base;
    }

    /** @return array<string,mixed> */
    private function routerMemo(int $rid, Carbon $now): array
    {
        $key = $rid;
        if (isset($this->routerMemo[$key]) && $this->routerMemo[$key]['_at'] === $now->getTimestamp()) {
            return $this->routerMemo[$key]['v'];
        }
        $router = Router::query()->find($rid, ['id', 'name', 'connection_status', 'last_health_check_at', 'isolation_profile']);
        $out = ['name' => $router?->name, 'isolation_profile' => $router?->isolation_profile !== null ? (string) $router->isolation_profile : null, 'blind' => null, 'offline' => false, 'all_gone' => false, 'customers_total' => 0, 'customers_fresh_online' => 0, 'health_checked_at' => null, 'health_fresh' => false];
        if ($router !== null) {
            $blind = $this->routerHealth->blindReason($rid, $now);
            $out['blind'] = $blind;
            $out['offline'] = $blind !== null && ($blind['reason'] ?? null) === 'router_offline';
            $checked = $router->last_health_check_at ? Carbon::instance($router->last_health_check_at) : null;
            $out['health_checked_at'] = $checked;
            $out['health_fresh'] = $checked !== null && $checked->gte($now->copy()->subMinutes(self::FRESH_MINUTES));
            if ($out['offline']) {
                $fresh = $now->copy()->subMinutes(OutageSettings::routerBlindMinutes())->format('Y-m-d H:i:s');
                $row = DB::table('customers')->where('router_id', $rid)->whereNotNull('pppoe_user')->where('status', 'active')
                    ->selectRaw('COUNT(*) as total, SUM(CASE WHEN is_online = 1 AND pppoe_online_checked_at >= ? THEN 1 ELSE 0 END) as fresh_online', [$fresh])->first();
                $total = (int) ($row->total ?? 0);
                $out['customers_total'] = $total;
                $out['customers_fresh_online'] = (int) ($row->fresh_online ?? 0);
                $out['all_gone'] = $total >= OutageDetector::PPPOE_ROUTER_MIN && $out['customers_fresh_online'] <= (int) floor($total * 0.1);
            }
        }
        $this->routerMemo[$key] = ['_at' => $now->getTimestamp(), 'v' => $out];

        return $out;
    }

    // ------------------------------------------------------------------ OLT / PON / cluster

    /**
     * @param  array<string,mixed>  $ctx
     * @return array<string,mixed>
     */
    private function oltLayer(array $ctx): array
    {
        $oltId = $ctx['customer']['olt_id'];
        $out = ['id' => $oltId, 'name' => null, 'family' => null, 'wide' => null, 'blind' => null];
        if ($oltId === null) {
            return $out;
        }
        $olt = Olt::query()->find($oltId, ['id', 'name', 'vendor']);
        if ($olt === null) {
            return $out;
        }
        $out['name'] = (string) $olt->name;
        $out['family'] = OutageDetector::VENDOR_FAMILIES[(string) $olt->vendor] ?? null;
        $wide = OutageSignal::query()->open()->ofType('olt_wide')->where('olt_id', $oltId)->orderByDesc('id')->first();
        if ($wide !== null && (int) (((array) $wide->payload)['consecutive'] ?? 0) >= 2) {
            $out['wide'] = ['id' => (int) $wide->id, 'since' => Carbon::instance($wide->opened_at), 'seen' => $wide->last_seen_at ? Carbon::instance($wide->last_seen_at) : null];
        }
        $blind = OutageSignal::query()->open()->ofType('data_blind')->where('scope_key', 'olt:'.$oltId.':blind')->first();
        if ($blind !== null) {
            $out['blind'] = ['id' => (int) $blind->id, 'reason' => ((array) $blind->payload)['reason'] ?? null];
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $ctx
     * @return array<string,mixed>
     */
    private function ponLayer(array $ctx): array
    {
        $out = ['mass' => null, 'los_others' => 0, 'total' => 0];
        $oltId = $ctx['customer']['olt_id'];
        $pon = $ctx['customer']['pon'];
        if ($oltId === null || $pon === null) {
            return $out;
        }
        $cid = $ctx['customer']['id'];
        $mass = OutageSignal::query()->open()->ofType('mass_los')->where('olt_id', $oltId)->where('pon_port', $pon)->orderByDesc('id')->first();
        if ($mass !== null) {
            $ids = array_map('intval', (array) $mass->affected_customer_ids);
            $payload = (array) $mass->payload;
            $total = (int) ($payload['pon_total'] ?? 0);
            $count = (int) $mass->affected_count;
            $out['mass'] = [
                'id' => (int) $mass->id,
                'includes' => in_array($cid, $ids, true),
                'count' => $count,
                'total' => $total,
                'ratio' => $total > 0 ? $count / $total : null,
                'whole' => (bool) ($payload['whole_pon'] ?? false),
                'confirmed' => $mass->confirmed_at !== null,
                'state' => $mass->confirmed_at !== null ? 'confirmed' : 'open',
                'since' => Carbon::instance($mass->opened_at),
                'seen' => $mass->last_seen_at ? Carbon::instance($mass->last_seen_at) : null,
                'ids' => $ids,
            ];
        }
        $prefix = $pon.':';
        $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix).'%';
        $rows = OutageOnuTracker::query()->where('olt_id', $oltId)->where('onu_ref', 'like', $like)
            ->selectRaw("COUNT(*) as total, SUM(CASE WHEN phase = 'los' AND (customer_id IS NULL OR customer_id <> ?) THEN 1 ELSE 0 END) as los_others", [$cid])->first();
        $out['total'] = (int) ($rows->total ?? 0);
        $out['los_others'] = (int) ($rows->los_others ?? 0);

        return $out;
    }

    /**
     * Same ODP label (customers.odp_name) among the affected customers of an open mass_los. No ODP data = no
     * cluster layer (RCA-5 adds the ODP / co-failure model).
     *
     * @param  array<string,mixed>  $ctx
     * @return array<string,mixed>
     */
    private function clusterLayer(array $ctx): array
    {
        $out = ['odp' => $ctx['customer']['odp'], 'odp_share' => null, 'odp_count' => 0];
        $mass = $ctx['pon']['mass'] ?? null;
        if ($out['odp'] === null || $mass === null || ! $mass['includes'] || $mass['ids'] === []) {
            return $out;
        }
        $ids = array_slice($mass['ids'], 0, 300);
        $same = (int) Customer::query()->whereIn('id', $ids)->where('odp_name', $out['odp'])->count();
        $out['odp_count'] = $same;
        $out['odp_share'] = count($ids) > 0 ? $same / count($ids) : null;

        return $out;
    }

    // ------------------------------------------------------------------ ONU

    /**
     * @param  array<string,mixed>  $ctx
     * @param  array<string,mixed>|null  $oltProbe
     * @return array<string,mixed>
     */
    private function onuLayer(array $ctx, ?array $oltProbe): array
    {
        $now = $ctx['now'];
        $oltId = $ctx['customer']['olt_id'];
        $ref = $ctx['customer']['ref'];
        $out = [
            'phase' => null, 'since' => null, 'since_hint' => null, 'collected_at' => null, 'fresh' => false,
            'cause' => null, 'cause_at' => null, 'cause_source' => null, 'cause_fresh' => false, 'flapping' => false,
            'never_registered' => false, 'online_seconds' => null, 'system_up_seconds' => null, 'rebooted' => null,
            'events_24h' => 0, 'drops' => [], 'dg_in_24h' => false, 'rx_dbm' => $ctx['customer']['rx_dbm'], 'rx_weak' => false, 'rx_trend' => false,
            'olt_probe' => $oltProbe['status'] ?? null,
            'phase_source' => null, 'rx_step' => null, 'rx_step_peers_pon' => 0, 'rx_step_peers_odp' => 0, 'rx_decline' => null,
        ];
        if ($oltId === null || $ref === null) {
            return $out;
        }

        $tracker = OutageOnuTracker::query()->where('olt_id', $oltId)->where('onu_ref', $ref)->first();
        // Freshness and phase come from olt_onus (written by the collectors every ~2 minutes), NOT from the tracker
        // stamp: the detector only moves last_state_collected_at when the phase changes, so a stable ONU looked
        // "unread" after 20 minutes. The tracker keeps the phase start and the RX history. olt_onus only for the
        // vendors the detector classifies (zte / huawei); without a row the tracker is used as before.
        $family = $ctx['olt']['family'] ?? null;
        $onuRow = $family !== null ? $this->oltOnuRow($oltId, $ref) : null;
        $livePhase = null;
        if ($onuRow !== null && $onuRow->state_collected_at !== null) {
            $classified = OutageDetector::classifyOnu((string) $family, $onuRow, $tracker?->phase, $now);
            $livePhase = $classified !== 'unknown' ? $classified : null;
        }
        if ($livePhase !== null) {
            $collected = Carbon::instance($onuRow->state_collected_at);
            $out['phase'] = $livePhase;
            $out['phase_source'] = 'olt_onus';
            // the phase start is only known while the tracker agrees with the collector (it is NULL after a stale gap)
            $out['since'] = $tracker !== null && (string) $tracker->phase === $livePhase && $tracker->phase_since_at ? Carbon::instance($tracker->phase_since_at) : null;
            $out['collected_at'] = $collected;
            $out['fresh'] = $collected->gte($now->copy()->subMinutes(OutageDetector::PHASE_MAX_AGE_MINUTES));
        } elseif ($tracker !== null) {
            $collected = $tracker->last_state_collected_at ? Carbon::instance($tracker->last_state_collected_at) : null;
            $out['phase'] = (string) $tracker->phase;
            $out['phase_source'] = 'tracker';
            $out['since'] = $tracker->phase_since_at ? Carbon::instance($tracker->phase_since_at) : null;
            $out['collected_at'] = $collected;
            $out['fresh'] = $collected !== null && $out['phase'] !== 'unknown' && $collected->gte($now->copy()->subMinutes(OutageDetector::PHASE_MAX_AGE_MINUTES));
        }
        if ($out['phase'] !== null) {
            $collected = $out['collected_at'];
            if (in_array($out['phase'], ['los', 'dying_gasp'], true)) {
                $out['cause'] = $out['phase'];
                $out['cause_at'] = $collected;
                $out['cause_source'] = 'olt_poll';
                $out['cause_fresh'] = $collected !== null && $collected->gte($now->copy()->subMinutes(self::FRESH_MINUTES));
            }
        }
        if ($tracker !== null) {
            $rxDays = (array) $tracker->rx_daily;
            if (OutageSettings::weakRxTrendEnabled() && count($rxDays) >= 3) {
                $vals = array_values(array_map(fn ($d) => (float) ($d['dbm'] ?? 0), array_slice($rxDays, -3)));
                $out['rx_trend'] = ($vals[0] - $vals[2]) >= OutageSettings::weakRxTrendDb() && $vals[1] <= $vals[0];
            }
            // RX step / decline from the daily medians (plan 4.3): independent of the (off) trend setting
            $step = $this->rxStep($rxDays, $now);
            if ($step !== null) {
                $out['rx_step'] = $step;
                $peers = $this->rxStepPeers($ctx, $step);
                $out['rx_step_peers_pon'] = $peers['pon'];
                $out['rx_step_peers_odp'] = $peers['odp'];
            }
            $out['rx_decline'] = $this->rxDecline($rxDays);
        }
        if ($out['rx_dbm'] !== null) {
            $out['rx_weak'] = $out['rx_dbm'] <= OutageSettings::weakRxDbm();
        }

        // Episode payload: the enrichment answer of the detector (RCA-2) for an open episode of this customer.
        $episode = OutageSignal::query()->open()->where('customer_id', $ctx['customer']['id'])->whereIn('signal_type', ['single_los', 'dying_gasp'])->orderByDesc('id')->first();
        if ($episode !== null) {
            $payload = (array) $episode->payload;
            $picked = OutageCauseText::forPayload($payload);
            $enrich = (array) ($payload['enrich'] ?? []);
            if ($picked !== null && ! in_array($picked['code'], ['flapping'], true)) {
                $enrichAt = isset($enrich['at']) ? $this->time($enrich['at']) : null;
                if ($out['cause'] === null || $picked['code'] !== 'down_unknown') {
                    $out['cause'] = $picked['code'];
                    $out['cause_at'] = $enrichAt ?? ($episode->last_seen_at ? Carbon::instance($episode->last_seen_at) : null);
                    $out['cause_source'] = $enrich !== [] ? 'olt_detail' : 'detector';
                    $out['cause_fresh'] = $enrich !== [] && $enrichAt !== null && $enrichAt->gte($now->copy()->subMinutes(self::FRESH_MINUTES));
                }
            }
            $out['flapping'] = ! empty($enrich['flapping']);
            $out['rebooted'] = $enrich['rebooted'] ?? null;
            $out['online_seconds'] = $enrich['online_seconds'] ?? null;
            $out['system_up_seconds'] = $enrich['system_up_seconds'] ?? null;
            if (($payload['cause_detail'] ?? null) === 'never_registered') {
                $out['never_registered'] = true;
            }
        }

        // State history (24 h): the newest cause, how many drops, any dying gasp.
        if (Schema::hasTable('onu_state_events')) {
            $events = DB::table('onu_state_events')->where('olt_id', $oltId)->where('onu_ref', $ref)
                ->where('occurred_at', '>=', $now->copy()->subDay()->utc()->format('Y-m-d H:i:s'))->orderByDesc('occurred_at')->limit(60)->get(['from_phase', 'to_phase', 'cause', 'source', 'occurred_at']);
            foreach ($events as $e) {
                if ($e->to_phase === 'online') {
                    continue;
                }
                $out['events_24h']++;
                // history: a drop is a change FROM online (los -> offline is the same outage, not a new drop)
                $dropAt = $this->time($e->occurred_at);
                if ($dropAt !== null && ($e->from_phase === null || $e->from_phase === 'online') && $e->cause !== 'never_registered') {
                    $out['drops'][] = ['at' => $dropAt, 'cause' => $this->dropCause($e->to_phase, $e->cause), 'source' => (string) $e->source];
                }
                if ($e->to_phase === 'dying_gasp' || $e->cause === 'dying_gasp') {
                    $out['dg_in_24h'] = true;
                }
                if ($e->cause === 'never_registered') {
                    $out['never_registered'] = true;
                }
                if ($out['cause'] === null && $e->cause !== null) {
                    $out['cause'] = (string) $e->cause;
                    $out['cause_at'] = $this->time($e->occurred_at);
                    $out['cause_source'] = (string) $e->source;
                }
            }
        }

        // Live OLT read ("Cek sekarang") wins: it is the authoritative, freshest source.
        $detail = is_array($oltProbe['detail'] ?? null) ? $oltProbe['detail'] : null;
        if (($oltProbe['status'] ?? null) === 'ok' && $detail !== null) {
            // The read also says whether the ONU is up right now: ZTE "Phase state", Huawei "Run state".
            $phase = strtolower((string) preg_replace('/[\s_\-]+/', '', (string) ($detail['phase'] ?? '')));
            $run = strtolower((string) ($detail['run_state'] ?? ''));
            $liveCause = $detail['cause'] ?? null;
            $live = match (true) {
                $phase === 'working' || $run === 'online' => 'online',
                $phase === 'los' => 'los',
                in_array($phase, ['dyinggasp', 'dgi'], true) => 'dying_gasp',
                $phase !== '' || $run !== '' => match ($liveCause) {
                    'los' => 'los',
                    'dying_gasp' => 'dying_gasp',
                    default => 'offline',
                },
                default => null,
            };
            if ($live !== null) {
                if ($live !== $out['phase']) {
                    $out['since'] = isset($detail['last_offline_at']) && $live !== 'online' ? $this->time($detail['last_offline_at']) : null;
                }
                $out['phase'] = $live;
            }
            $out['cause'] = $live === 'online' ? null : ($detail['cause'] ?? $out['cause']);
            $out['cause_at'] = $now;
            $out['cause_source'] = 'olt_live';
            $out['cause_fresh'] = true;
            $out['flapping'] = ! empty($detail['flapping']);
            $out['rebooted'] = $detail['rebooted'] ?? $out['rebooted'];
            $out['online_seconds'] = $detail['online_seconds'] ?? $out['online_seconds'];
            $out['system_up_seconds'] = $detail['system_up_seconds'] ?? $out['system_up_seconds'];
            $out['never_registered'] = ($detail['cause'] ?? null) === 'never_registered' || ($detail['run_state'] ?? null) === 'initial' || $out['never_registered'];
            $out['fresh'] = true;
        }

        // RCA-5: the phase start is NULL after every OLT stale gap. Hint for the rules: the live "last offline" time,
        // else the newest own drop of the last 24 h. Stays null when the start is really unknown.
        if ($out['since'] === null && ! in_array($out['phase'], [null, 'online', 'unknown'], true)) {
            $hint = isset($detail['last_offline_at']) ? $this->time($detail['last_offline_at']) : null;
            if ($hint === null) {
                foreach ($out['drops'] as $d) {
                    if ($hint === null || $d['at']->gt($hint)) {
                        $hint = $d['at'];
                    }
                }
            }
            if ($hint === null) {
                $hint = $this->lastDropStart($oltId, $ref, $now);
            }
            $out['since_hint'] = $hint;
        }

        return $out;
    }

    /** The newest olt_onus row of one ONU (the collector writes ref as "1/3/3:19" or "gpon-onu_1/3/3:19"). */
    private function oltOnuRow(int $oltId, string $ref): ?OltOnu
    {
        return OltOnu::query()->where('olt_id', $oltId)->whereIn('onu_ref', [$ref, 'gpon-onu_'.$ref])->orderByDesc('state_collected_at')->first();
    }

    /**
     * When the ONU went down, from onu_state_events (7 days, newest 10 rows): the first drop (from online / unknown)
     * that no later "online" event has closed. Null when it came back since or nothing is recorded. One indexed query.
     */
    private function lastDropStart(int $oltId, string $ref, Carbon $now): ?Carbon
    {
        if (! Schema::hasTable('onu_state_events')) {
            return null;
        }
        $events = DB::table('onu_state_events')->where('olt_id', $oltId)->where('onu_ref', $ref)
            ->where('occurred_at', '>=', $now->copy()->subDays(7)->utc()->format('Y-m-d H:i:s'))
            ->orderByDesc('occurred_at')->limit(10)->get(['from_phase', 'to_phase', 'occurred_at']);
        foreach ($events as $e) {
            if ($e->to_phase === 'online') {
                return null; // it came back after that
            }
            if ($e->from_phase === null || $e->from_phase === 'online') {
                return $this->time($e->occurred_at);
            }
        }

        return null;
    }

    /**
     * The newest worsening step of >= RX_STEP_DB between two consecutive daily medians, newer day within the last 4
     * days. Null when there is none.
     *
     * @param  array<int,mixed>  $rxDays  rx_daily of the tracker: [{date,dbm,n}]
     * @return array{from:float,to:float,drop:float,date:string}|null
     */
    private function rxStep(array $rxDays, Carbon $now): ?array
    {
        $points = $this->rxPoints($rxDays);
        $limit = $now->copy()->timezone('Asia/Jakarta')->subDays(4)->format('Y-m-d');
        $found = null;
        for ($i = 1; $i < count($points); $i++) {
            $drop = $points[$i - 1]['dbm'] - $points[$i]['dbm'];
            if ($drop >= Rules::RX_STEP_DB && $points[$i]['date'] >= $limit) {
                $found = ['from' => $points[$i - 1]['dbm'], 'to' => $points[$i]['dbm'], 'drop' => round($drop, 1), 'date' => $points[$i]['date']];
            }
        }

        return $found;
    }

    /** The last 3 daily medians fall steadily by >= RX_DECLINE_DB in total (what weak_rx_trend would catch, setting off). */
    private function rxDecline(array $rxDays): ?array
    {
        $points = array_slice($this->rxPoints($rxDays), -3);
        if (count($points) < 3) {
            return null;
        }
        [$a, $b, $c] = [$points[0]['dbm'], $points[1]['dbm'], $points[2]['dbm']];

        return ($a - $c) >= Rules::RX_DECLINE_DB && $b <= $a && $c <= $b ? ['from' => $a, 'to' => $c] : null;
    }

    /** @return list<array{date:string,dbm:float}> the last 4 daily medians, oldest first */
    private function rxPoints(array $rxDays): array
    {
        $points = [];
        foreach ($rxDays as $d) {
            if (is_array($d) && isset($d['date'], $d['dbm']) && is_numeric($d['dbm']) && (float) $d['dbm'] < 0) {
                $points[] = ['date' => (string) $d['date'], 'dbm' => (float) $d['dbm']];
            }
        }
        usort($points, fn (array $x, array $y) => strcmp($x['date'], $y['date']));

        return array_slice($points, -4);
    }

    /**
     * Other ONUs of the same PON that stepped down on the same day (+-1): shared fibre. Only runs when this ONU has a
     * step. One tracker query (<= 512 rows) and, with an ODP, one customers count.
     *
     * @param  array<string,mixed>  $ctx
     * @param  array{from:float,to:float,drop:float,date:string}  $step
     * @return array{pon:int,odp:int}
     */
    private function rxStepPeers(array $ctx, array $step): array
    {
        $out = ['pon' => 0, 'odp' => 0];
        $oltId = $ctx['customer']['olt_id'];
        $pon = $ctx['customer']['pon'];
        $ref = $ctx['customer']['ref'];
        if ($oltId === null || $pon === null || $ref === null) {
            return $out;
        }
        $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $pon.':').'%';
        $rows = OutageOnuTracker::query()->where('olt_id', $oltId)->where('onu_ref', 'like', $like)->where('onu_ref', '<>', $ref)
            ->whereNotNull('rx_daily')->limit(512)->get(['onu_ref', 'customer_id', 'rx_daily']);
        $ids = [];
        $day = Carbon::parse($step['date'], 'Asia/Jakarta');
        foreach ($rows as $row) {
            $peer = $this->rxStep((array) $row->rx_daily, $ctx['now']);
            if ($peer === null || abs($day->diffInDays(Carbon::parse($peer['date'], 'Asia/Jakarta'), false)) > 1) {
                continue;
            }
            $out['pon']++;
            if ($row->customer_id !== null) {
                $ids[] = (int) $row->customer_id;
            }
        }
        $odp = $ctx['customer']['odp'];
        if ($odp !== null && $ids !== []) {
            $out['odp'] = (int) Customer::query()->whereIn('id', array_slice(array_unique($ids), 0, 300))->where('odp_name', $odp)->count();
        }

        return $out;
    }

    /** Cause class of one stored drop: los | dying_gasp | other | null (cause not recorded). */
    private function dropCause(?string $toPhase, ?string $cause): ?string
    {
        if ($toPhase === 'los' || in_array($cause, ['los', 'link_drop'], true)) {
            return 'los';
        }
        if ($toPhase === 'dying_gasp' || $cause === 'dying_gasp') {
            return 'dying_gasp';
        }

        return ($cause === null || $cause === '') ? null : 'other';
    }

    /**
     * Share of the same PON that booted or sent a dying gasp within 10 minutes of the customer's latest restart.
     * Distinguishes an area power cut (many ONUs at once) from one ONU restarting by itself.
     *
     * @param  array<string,mixed>  $ctx
     * @return array{boot_share:?array<string,mixed>}
     */
    private function ponBootShare(array $ctx): array
    {
        $out = ['boot_share' => null];
        $times = $ctx['cpe']['boot_times'] ?? [];
        $oltId = $ctx['customer']['olt_id'];
        $pon = $ctx['customer']['pon'];
        $total = (int) ($ctx['pon']['total'] ?? 0);
        if ($times === [] || $oltId === null || $pon === null || $total <= 0 || ! Schema::hasTable('cpe_events')) {
            return $out;
        }
        $last = end($times);
        $from = $last->copy()->subMinutes(10)->utc()->format('Y-m-d H:i:s');
        $to = $last->copy()->addMinutes(10)->utc()->format('Y-m-d H:i:s');
        $custIds = Customer::query()->where('olt_id', $oltId)->where('olt_port_label', 'like', str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $pon).':%')->limit(300)->pluck('id')->all();
        if ($custIds === []) {
            return $out;
        }
        $booted = DB::table('cpe_events')->whereIn('customer_id', $custIds)->whereIn('type', ['boot', 'uptime_reset'])->whereBetween('at', [$from, $to])->distinct()->pluck('customer_id')->all();
        $gasped = Schema::hasTable('onu_state_events')
            ? DB::table('onu_state_events')->where('olt_id', $oltId)->whereIn('customer_id', $custIds)->where('to_phase', 'dying_gasp')->whereBetween('occurred_at', [$from, $to])->distinct()->pluck('customer_id')->all()
            : [];
        $count = count(array_unique(array_merge($booted, $gasped)));
        $out['boot_share'] = ['count' => $count, 'total' => $total, 'share' => $count / $total, 'at' => $last];

        return $out;
    }

    /**
     * RCA-5: how many ONUs of the customer's PON are down NOW, not how many dropped at some time. DB only, bounded:
     * one tracker query on (olt_id, onu_ref prefix) and, only when the ONU is down with a known start, one
     * onu_state_events query on a 30 minute window of the same PON. Leftovers (a phase start of NULL or older than
     * 7 days) and stale rows are never witnesses; unmapped ONUs on the same fibre are.
     *
     *   down_now     ONUs down now with a LOS-like phase (ZTE los, Huawei offline), fresh (<= 20 min), this ONU included
     *   known_total  ONUs of the PON that are online, or down with a phase start within 7 days
     *   recovered    other ONUs that dropped within 15 minutes of this one and are online again
     *   event_at     when this ONU dropped (the PON event)
     *
     * @param  array<string,mixed>  $ctx
     * @return array{pon:array<string,mixed>,odp_down_now:int}
     */
    private function ponNow(array $ctx): array
    {
        $out = ['pon' => ['down_now' => 0, 'known_total' => 0, 'recovered' => 0, 'event_at' => null], 'odp_down_now' => 0];
        $oltId = $ctx['customer']['olt_id'];
        $pon = $ctx['customer']['pon'];
        $ref = $ctx['customer']['ref'];
        if ($oltId === null || $pon === null || $ref === null) {
            return $out;
        }
        $now = $ctx['now'];
        $o = $ctx['onu'];
        $phases = ($ctx['olt']['family'] ?? null) === 'huawei' ? ['offline', 'los'] : ['los'];
        $freshFrom = $now->copy()->subMinutes(OutageDetector::PHASE_MAX_AGE_MINUTES);
        $knownFrom = $now->copy()->subDays(Rules::PON_KNOWN_DAYS);
        $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $pon.':').'%';

        $rows = $this->ponWitnessRows($ctx, $like, $pon);

        // A witness of a PON cut went down WITH this ONU: within PON_WITNESS_HOURS of its drop (24 h when its start is
        // unknown). An ONU that has been dead for days is a leftover: known (7 d), but not "down now" for this event.
        $ownSince = $o['since'] ?? $o['since_hint'] ?? null;
        $witnessFrom = $ownSince !== null ? $ownSince->copy()->subHours(Rules::PON_WITNESS_HOURS) : $now->copy()->subHours(24);

        $down = 0;
        $known = 0;
        $downCustomerIds = [];
        $online = [];
        foreach ($rows as $row) {
            if ((string) $row->onu_ref === $ref) {
                continue; // this ONU comes from the context (a live read wins over the stored row)
            }
            $phase = (string) $row->phase;
            $since = $row->phase_since_at ? Carbon::instance($row->phase_since_at)->utc() : null;
            $collected = $row->last_state_collected_at ? Carbon::instance($row->last_state_collected_at)->utc() : null;
            if ($phase === 'online') {
                $known++;
                $online[(string) $row->onu_ref] = true;

                continue;
            }
            if (! in_array($phase, ['los', 'offline', 'dying_gasp'], true) || $since === null || $since->lt($knownFrom)) {
                continue; // unknown phase or a leftover: neither known nor a witness
            }
            $known++;
            if (in_array($phase, $phases, true) && $collected !== null && $collected->gte($freshFrom) && $since->gte($witnessFrom)) {
                $down++;
                if ($row->customer_id !== null) {
                    $downCustomerIds[] = (int) $row->customer_id;
                }
            }
        }

        // this ONU
        $dyingGasp = $o['phase'] === 'dying_gasp' || $o['cause'] === 'dying_gasp';
        $ownDown = $o['fresh'] && ! $dyingGasp && in_array($o['phase'], ['los', 'offline'], true)
            && (in_array($o['phase'], $phases, true) || in_array($o['cause'], ['los', 'link_drop'], true));
        if ($ownDown) {
            $down++;
            $downCustomerIds[] = (int) $ctx['customer']['id'];
        }
        if ($ownDown || $o['phase'] === 'online' || (in_array($o['phase'], ['los', 'offline', 'dying_gasp'], true) && $ownSince !== null && $ownSince->gte($knownFrom))) {
            $known++;
        }
        $out['pon']['down_now'] = $down;
        $out['pon']['known_total'] = max($known, $down);

        $odp = $ctx['customer']['odp'];
        if ($odp !== null && $down >= Rules::ODP_CUT_MIN_DOWN && $downCustomerIds !== []) {
            $out['odp_down_now'] = (int) Customer::query()->whereIn('id', array_slice(array_unique($downCustomerIds), 0, 300))->where('odp_name', $odp)->count();
        }

        // other ONUs that dropped together with this one and are back online now
        if ($ownSince !== null && in_array($o['phase'], ['los', 'offline', 'dying_gasp'], true) && Schema::hasTable('onu_state_events')) {
            $together = Rules::TOGETHER_MINUTES;
            $events = DB::table('onu_state_events')->where('olt_id', $oltId)->where('onu_ref', 'like', $like)->where('onu_ref', '<>', $ref)
                ->whereBetween('occurred_at', [$ownSince->copy()->subMinutes($together)->utc()->format('Y-m-d H:i:s'), $ownSince->copy()->addMinutes($together)->utc()->format('Y-m-d H:i:s')])
                ->where('to_phase', '<>', 'online')
                ->where(fn ($q) => $q->whereNull('from_phase')->orWhere('from_phase', 'online'))
                ->limit(300)->pluck('onu_ref')->all();
            $out['pon']['recovered'] = count(array_filter(array_unique($events), fn ($r) => isset($online[(string) $r])));
            $out['pon']['event_at'] = $ownSince;
        }

        return $out;
    }

    /**
     * The ONUs of one PON as ponNow() reads them: phase and collection time from olt_onus (fresh every ~2 minutes),
     * phase start and customer from the tracker. A ref without an olt_onus row, and a vendor the detector does not
     * classify, keep the tracker row as it is. A down ONU whose tracker start is NULL (a stale gap erased it) gets its
     * start from the newest drop in onu_state_events (one query, 7 days). Bounded: 512 rows per table.
     *
     * @param  array<string,mixed>  $ctx
     * @return Collection<int,object>
     */
    private function ponWitnessRows(array $ctx, string $like, string $pon): Collection
    {
        $oltId = $ctx['customer']['olt_id'];
        $now = $ctx['now'];
        $tracker = OutageOnuTracker::query()->where('olt_id', $oltId)->where('onu_ref', 'like', $like)->limit(512)
            ->get(['onu_ref', 'customer_id', 'phase', 'phase_since_at', 'last_state_collected_at'])->keyBy('onu_ref');
        $family = $ctx['olt']['family'] ?? null;
        if ($family === null) {
            return $tracker->values();
        }
        $likeGpon = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], 'gpon-onu_'.$pon.':').'%';
        $onus = OltOnu::query()->where('olt_id', $oltId)->whereNotNull('state_collected_at')
            ->where(fn ($q) => $q->where('onu_ref', 'like', $like)->orWhere('onu_ref', 'like', $likeGpon))->limit(512)->get();
        if ($onus->isEmpty()) {
            return $tracker->values();
        }

        $rows = collect();
        $seen = [];
        foreach ($onus as $onu) {
            $r = OutageDetector::normalizeRef((string) $onu->onu_ref);
            if ($r === null || isset($seen[$r])) {
                continue;
            }
            $seen[$r] = true;
            $t = $tracker->get($r);
            $phase = OutageDetector::classifyOnu((string) $family, $onu, $t?->phase, $now);
            if ($phase === 'unknown') {
                if ($t !== null) {
                    $rows->push($t);
                }

                continue;
            }
            $rows->push((object) [
                'onu_ref' => $r,
                'customer_id' => $t?->customer_id,
                'phase' => $phase,
                'phase_since_at' => $t !== null && (string) $t->phase === $phase ? $t->phase_since_at : null,
                'last_state_collected_at' => $onu->state_collected_at,
            ]);
        }
        foreach ($tracker as $ref => $t) {
            if (! isset($seen[(string) $ref])) {
                $rows->push($t);
            }
        }

        $lost = $rows->filter(fn ($row) => in_array((string) $row->phase, ['los', 'offline', 'dying_gasp'], true) && $row->phase_since_at === null)->pluck('onu_ref')->all();
        if ($lost !== [] && Schema::hasTable('onu_state_events')) {
            $starts = DB::table('onu_state_events')->where('olt_id', $oltId)->where('onu_ref', 'like', $like)
                ->where('occurred_at', '>=', $now->copy()->subDays(Rules::PON_KNOWN_DAYS)->utc()->format('Y-m-d H:i:s'))
                ->where('to_phase', '<>', 'online')->where(fn ($q) => $q->whereNull('from_phase')->orWhere('from_phase', 'online'))
                ->groupBy('onu_ref')->selectRaw('onu_ref, MAX(occurred_at) as drop_at')->limit(512)->pluck('drop_at', 'onu_ref');
            $rows = $rows->map(function ($row) use ($starts, $lost) {
                if (in_array($row->onu_ref, $lost, true) && isset($starts[$row->onu_ref])) {
                    $row = (object) [
                        'onu_ref' => $row->onu_ref, 'customer_id' => $row->customer_id, 'phase' => $row->phase,
                        'phase_since_at' => $this->time($starts[$row->onu_ref]), 'last_state_collected_at' => $row->last_state_collected_at,
                    ];
                }

                return $row;
            });
        }

        return $rows;
    }

    // ------------------------------------------------------------------ PPPoE

    /**
     * @param  array<string,mixed>  $ctx
     * @param  array<string,mixed>|null  $routerProbe
     * @return array<string,mixed>
     */
    private function pppoeLayer(array $ctx, ?array $routerProbe): array
    {
        $now = $ctx['now'];
        $checked = $ctx['customer']['online_checked_at'];
        $fresh = $checked !== null && $checked->gte($now->copy()->subMinutes(self::PPPOE_FRESH_MINUTES));
        $out = [
            'online' => $fresh ? $ctx['customer']['is_online'] : null,
            'fresh' => $fresh, 'at' => $checked, 'source' => $fresh ? 'poll' : null,
            'stuck_count' => 0, 'stuck_at' => null, 'auth_failed_at' => null, 'reconnects_6h' => 0, 'syslog' => false,
            'router_session_uptime_s' => null, 'router_session' => null,
            'events' => [], // the router log of the last 24 h (history block only)
        ];
        // The live router read is the freshest PPPoE truth.
        if (($routerProbe['status'] ?? null) === 'ok') {
            $active = $ctx['router']['active'];
            $out['online'] = is_array($active);
            $out['fresh'] = true;
            $out['at'] = $now;
            $out['source'] = 'router_live';
            $out['router_session'] = is_array($active);
            $out['router_session_uptime_s'] = is_array($active) ? $this->routerUptime((string) ($active['uptime'] ?? '')) : null;
        }

        if (Schema::hasTable('pppoe_session_events')) {
            // 24 h (history); the 6 hour rules below keep their own 6 hour filter
            $sixHours = $now->copy()->subHours(6);
            $events = DB::table('pppoe_session_events')->where('customer_id', $ctx['customer']['id'])
                ->where('at', '>=', $now->copy()->subHours(Rules::HISTORY_HOURS)->utc()->format('Y-m-d H:i:s'))->orderByDesc('at')->limit(300)->get(['event', 'source', 'reason', 'at']);
            $stuck = [];
            foreach ($events as $e) {
                $at = $this->time($e->at);
                if ($at === null) {
                    continue;
                }
                $out['events'][] = ['event' => (string) $e->event, 'source' => (string) $e->source, 'reason' => $e->reason !== null ? (string) $e->reason : null, 'at' => $at];
                if ($at->gte($sixHours)) {
                    $out['syslog'] = $out['syslog'] || $e->source === 'syslog';
                }
                if ($e->event === 'stuck') {
                    $stuck[] = $at;
                } elseif ($e->event === 'auth_failed' && $out['auth_failed_at'] === null && $at->gte($now->copy()->subMinutes(60))) {
                    $out['auth_failed_at'] = $at;
                } elseif ($e->event === 'connected' && $at->gte($sixHours)) {
                    $out['reconnects_6h']++;
                }
            }
            // "already active" three times inside 10 minutes, the newest one within the last hour
            usort($stuck, fn (Carbon $a, Carbon $b) => $a->getTimestamp() <=> $b->getTimestamp());
            foreach ($stuck as $i => $at) {
                if ($at->lt($now->copy()->subMinutes(60))) {
                    continue;
                }
                $inWindow = count(array_filter($stuck, fn (Carbon $o) => $o->getTimestamp() <= $at->getTimestamp() && $o->getTimestamp() > $at->getTimestamp() - 600));
                if ($inWindow >= 3) {
                    $out['stuck_count'] = $inWindow;
                    $out['stuck_at'] = $at;
                }
            }
        }

        return $out;
    }

    /**
     * PR-D: the session_burst of the last 6 hours this customer was part of (outage:session-bursts), or null.
     *
     * @param  array<string,mixed>  $ctx
     * @return array{at:Carbon,count:int,onu_down_share:?float,pon:?string,signal_id:int}|null
     */
    private function burstLayer(array $ctx): ?array
    {
        $rid = $ctx['customer']['router_id'];
        if ($rid === null) {
            return null;
        }
        try {
            $s = OutageSignal::query()->where('signal_type', 'session_burst')->where('scope_key', 'like', 'router:'.(int) $rid.':%')
                ->where('opened_at', '>=', $ctx['now']->copy()->subHours(6)->utc()->format('Y-m-d H:i:s'))
                ->whereJsonContains('affected_customer_ids', (int) $ctx['customer']['id'])->orderByDesc('opened_at')->first();
        } catch (\Throwable) {
            return null;
        }
        if ($s === null) {
            return null;
        }
        $p = (array) $s->payload;

        return [
            'at' => Carbon::instance($s->opened_at)->utc(), 'count' => (int) $s->affected_count,
            'onu_down_share' => isset($p['onu_down_share']) && is_numeric($p['onu_down_share']) ? (float) $p['onu_down_share'] : null,
            'pon' => $s->pon_port, 'signal_id' => (int) $s->id,
        ];
    }

    /**
     * PR-D: the area_power group of the last 12 hours this customer's ONU was part of (outage:session-bursts), or null.
     *
     * @param  array<string,mixed>  $ctx
     * @return array{at:Carbon,count:int,area:?string,signal_id:int,open:bool}|null
     */
    private function areaPowerLayer(array $ctx): ?array
    {
        $oltId = $ctx['customer']['olt_id'];
        if ($oltId === null) {
            return null;
        }
        try {
            $s = OutageSignal::query()->where('signal_type', 'area_power')->where('olt_id', (int) $oltId)
                ->where('opened_at', '>=', $ctx['now']->copy()->subHours(12)->utc()->format('Y-m-d H:i:s'))
                ->whereJsonContains('affected_customer_ids', (int) $ctx['customer']['id'])->orderByDesc('opened_at')->first();
            if ($s === null) {
                return null;
            }
            $area = $s->area_id !== null ? DB::table('areas')->where('id', $s->area_id)->value('name') : null;
        } catch (\Throwable) {
            return null;
        }

        $total = (int) (((array) $s->payload)['count'] ?? 0);

        return [
            'at' => Carbon::instance($s->opened_at)->utc(), 'count' => $total > 0 ? $total : (int) $s->affected_count,
            'area' => $area !== null ? (string) $area : null, 'signal_id' => (int) $s->id, 'open' => ! $s->isClosed(),
        ];
    }

    /**
     * PR-D: the newest per-customer traffic sample of the router (unique index on customer + router). The `online`
     * column is ignored (always 1); freshness is judged by the rules from `at`.
     *
     * @param  array<string,mixed>  $ctx
     * @return array{at:Carbon,download_bps:int,upload_bps:int}|null
     */
    private function trafficLayer(array $ctx): ?array
    {
        $rid = $ctx['customer']['router_id'];
        if ($rid === null) {
            return null;
        }
        try {
            $row = DB::table('customer_traffic_current')->where('customer_id', (int) $ctx['customer']['id'])->where('router_id', (int) $rid)->first(['sampled_at', 'download_bps', 'upload_bps']);
        } catch (\Throwable) {
            return null;
        }
        $at = $row !== null ? $this->time($row->sampled_at) : null;

        return $at === null ? null : ['at' => $at, 'download_bps' => (int) $row->download_bps, 'upload_bps' => (int) $row->upload_bps];
    }

    /** RouterOS uptime "1d2h3m4s" -> seconds. */
    public function routerUptime(string $value): ?int
    {
        if ($value === '' || preg_match_all('/(\d+)(w|d|h|m|s)/', $value, $m, PREG_SET_ORDER) < 1) {
            return null;
        }
        $mult = ['w' => 604800, 'd' => 86400, 'h' => 3600, 'm' => 60, 's' => 1];
        $total = 0;
        foreach ($m as $part) {
            $total += (int) $part[1] * $mult[$part[2]];
        }

        return $total;
    }

    private function time(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return Carbon::parse((string) $value, 'UTC')->utc();
        } catch (\Throwable) {
            return null;
        }
    }
}
