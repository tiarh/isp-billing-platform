<?php

namespace App\Services\Cpe;

use App\Services\Outage\CpeEvents;
use App\Services\Outage\OutageSettings;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * RCA-3: cpe_events transitions (boot from a `_lastBoot` change, wlan_down / wlan_up, assoc_zero, ppp_error)
 * plus the cpe_daily rollup, computed from the rows genieacs:sync is about to upsert.
 *
 * Rules:
 *  - transitions only, never per-sample rows; the first sync after deploy is a baseline (NULL -> value is nothing);
 *  - WLAN / PPP events need FRESH values on both sides (stale values are NULL columns, see CpeSnapshotExtractor);
 *  - never throws, never talks to a device, one indexed read per 400 devices.
 */
class CpeTransitions
{
    /** A boot older than the stored one by more than this is a new boot (inform jitter is smaller). */
    public const BOOT_MIN_DELTA_SECONDS = 60;

    /** boot is deduped against boot / uptime_reset events of the same device inside this window. */
    public const BOOT_DEDUPE_SECONDS = 600;

    /** An old stored value older than this is a baseline again (no transition can be claimed). */
    public const OLD_VALUE_MAX_HOURS = 6;

    /**
     * Call BEFORE the upsert overwrites the stored columns.
     *
     * @param  list<array<string,mixed>>  $rows  genieacs_devices rows (device_id, customer_id, uptime_seconds + CPE columns)
     * @param  bool  $rollup  false for watch / summon refreshes (events only, no cpe_daily counters)
     * @return array{events: int, daily: int}
     */
    public static function process(array $rows, ?CarbonInterface $now = null, bool $rollup = true): array
    {
        $result = ['events' => 0, 'daily' => 0];
        try {
            if ($rows === [] || ! OutageSettings::cpeLayerEnabled() || ! Schema::hasTable('genieacs_devices')
                || ! Schema::hasColumn('genieacs_devices', 'wlan_values_at')) {
                return $result;
            }
            $now = $now ? Carbon::instance($now) : Carbon::now();

            $incoming = [];
            foreach ($rows as $row) {
                $id = (string) ($row['device_id'] ?? '');
                // rows built without the extractor (cpe layer off at build time) carry no CPE keys
                if ($id !== '' && array_key_exists('wlan_values_at', $row)) {
                    $incoming[$id] = $row;
                }
            }
            if ($incoming === []) {
                return $result;
            }

            $stats = [];
            $columns = [
                'device_id', 'customer_id', 'uptime_seconds', 'last_boot_at', 'wlan24_status', 'wlan5_status',
                'wlan24_enabled', 'wlan5_enabled', 'wlan24_assoc', 'wlan5_assoc', 'wlan_values_at',
                'ppp_last_error', 'ppp_values_at',
            ];
            // RCA-4 history: the CPE's PPP session uptime (kill switch + column guard: the read stays as before otherwise)
            $pppReconnect = OutageSettings::cpePppReconnectEnabled() && self::pppUptimeColumn();
            if ($pppReconnect) {
                $columns[] = 'ppp_uptime_s';
            }
            foreach (array_chunk(array_keys($incoming), 400) as $chunk) {
                $old = DB::table('genieacs_devices')->whereIn('device_id', $chunk)->get($columns)->keyBy('device_id');

                foreach ($chunk as $id) {
                    $new = $incoming[$id];
                    $o = $old[$id] ?? null; // null = new device: nothing to compare with
                    $cid = isset($new['customer_id']) ? (int) $new['customer_id'] : ($o?->customer_id !== null ? (int) $o->customer_id : null);

                    $boot = false;
                    $assocZeroNow = false;
                    $pppErrorNow = false;
                    if ($o !== null) {
                        $result['events'] += self::compare($id, $cid, $o, $new, $now, $boot, $assocZeroNow, $pppErrorNow);
                    }

                    $s24 = self::effective($new['wlan24_status'] ?? null, $new['wlan24_enabled'] ?? null);
                    $s5 = self::effective($new['wlan5_status'] ?? null, $new['wlan5_enabled'] ?? null);
                    $uptime = isset($new['uptime_seconds']) && is_numeric($new['uptime_seconds']) ? (int) $new['uptime_seconds'] : null;
                    if (! $boot && $o !== null && $uptime !== null && $o->uptime_seconds !== null
                        && (int) $o->uptime_seconds - $uptime > CpeEvents::RESET_MIN_DROP_SECONDS) {
                        $boot = true;
                    }
                    if ($pppReconnect && $o !== null) {
                        $result['events'] += self::pppReconnect($id, $cid, $o, $new, $now, $boot);
                    }
                    $stats[] = [
                        'device_id' => $id,
                        'customer_id' => $cid,
                        'fresh_wlan' => ($new['wlan_values_at'] ?? null) !== null,
                        'down24' => $s24 !== null && $s24 !== 'up',
                        'down5' => $s5 !== null && $s5 !== 'up',
                        'assoc24' => $new['wlan24_assoc'] ?? null,
                        'assoc5' => $new['wlan5_assoc'] ?? null,
                        'assoc_zero' => $assocZeroNow,
                        'boot' => $boot,
                        'uptime' => $uptime,
                        'ppp_error' => $pppErrorNow,
                        'rx' => $new['_cpe']['rx_fresh'] ?? null,
                    ];
                }
            }

            // watch / summon refreshes only feed events, never the per-sync daily counters
            $result['daily'] = $rollup ? CpeDailyRollup::apply($stats, $now) : 0;
        } catch (\Throwable $e) {
            try {
                Log::warning('[CPE] transitions failed', ['exception_class' => $e::class]);
            } catch (\Throwable) {
            }
        }

        return $result;
    }

    /** lower-case effective WLAN state: status, else the enabled flag; null when neither is fresh. */
    public static function effective(?string $status, mixed $enabled): ?string
    {
        if ($status !== null && trim($status) !== '') {
            return strtolower(trim($status));
        }
        if ($enabled === null || $enabled === '') {
            return null;
        }

        return ((bool) $enabled) ? 'up' : 'disabled';
    }

    /**
     * @param  array<string,mixed>  $new
     */
    private static function compare(string $id, ?int $cid, object $o, array $new, CarbonInterface $now, bool &$boot, bool &$assocZeroNow, bool &$pppErrorNow): int
    {
        $written = 0;

        // --- boot (`_lastBoot` moved forward) ---
        $newBoot = $new['last_boot_at'] ?? null;
        if ($newBoot !== null && $o->last_boot_at !== null) {
            $newAt = Carbon::parse($newBoot, 'UTC');
            $oldAt = Carbon::parse($o->last_boot_at, 'UTC');
            if ($newAt->diffInSeconds($oldAt, false) < -self::BOOT_MIN_DELTA_SECONDS) {
                $boot = true;
                if (! self::bootAlreadyRecorded($id, $newAt)) {
                    $written += CpeEvents::record($cid, $id, 'boot', $newAt, ['source' => 'last_boot']) ? 1 : 0;
                }
            }
        }

        // --- WLAN / PPP: both sides fresh and the old side not ancient ---
        $oldFreshEnough = $o->wlan_values_at !== null
            && Carbon::parse($o->wlan_values_at, 'UTC')->gte($now->copy()->subHours(self::OLD_VALUE_MAX_HOURS));
        $newFresh = ($new['wlan_values_at'] ?? null) !== null;

        if ($oldFreshEnough && $newFresh) {
            $down = [];
            $up = [];
            foreach (['24' => 'band24', '5' => 'band5'] as $suffix => $band) {
                $from = self::effective($o->{'wlan'.$suffix.'_status'}, $o->{'wlan'.$suffix.'_enabled'});
                $to = self::effective($new['wlan'.$suffix.'_status'] ?? null, $new['wlan'.$suffix.'_enabled'] ?? null);
                if ($from === null || $to === null || $from === $to) {
                    continue;
                }
                if ($from === 'up') {
                    $down[$band] = ['from' => $from, 'to' => $to];
                } elseif ($to === 'up') {
                    $up[$band] = ['from' => $from, 'to' => $to];
                }
            }
            if ($down !== []) {
                $written += CpeEvents::record($cid, $id, 'wlan_down', $now, ['bands' => $down]) ? 1 : 0;
            }
            if ($up !== []) {
                $written += CpeEvents::record($cid, $id, 'wlan_up', $now, ['bands' => $up]) ? 1 : 0;
            }

            // assoc >0 -> 0 on every fresh band while the ONU kept its uptime
            $bands = [];
            foreach (['24', '5'] as $suffix) {
                $n = $new['wlan'.$suffix.'_assoc'] ?? null;
                if ($n !== null) {
                    $bands[$suffix] = (int) $n;
                }
            }
            if ($bands !== [] && array_sum($bands) === 0) {
                $assocZeroNow = true;
                $oldSum = 0;
                $oldKnown = true;
                foreach (array_keys($bands) as $suffix) {
                    $v = $o->{'wlan'.$suffix.'_assoc'};
                    if ($v === null) {
                        $oldKnown = false;
                        break;
                    }
                    $oldSum += (int) $v;
                }
                $uptimeNew = $new['uptime_seconds'] ?? null;
                $uptimeKept = is_numeric($uptimeNew) && $o->uptime_seconds !== null && (int) $uptimeNew >= (int) $o->uptime_seconds;
                if ($oldKnown && $oldSum > 0 && $uptimeKept) {
                    $written += CpeEvents::record($cid, $id, 'assoc_zero', $now, ['was' => $oldSum]) ? 1 : 0;
                }
            }
        }

        // --- PPP last connection error changed to a real error ---
        $newErr = $new['ppp_last_error'] ?? null;
        if ($newErr !== null && trim((string) $newErr) !== '') {
            $isError = strcasecmp(trim((string) $newErr), 'ERROR_NONE') !== 0;
            $pppErrorNow = $isError;
            if ($isError && $o->ppp_last_error !== null && $o->ppp_last_error !== $newErr) {
                $written += CpeEvents::record($cid, $id, 'ppp_error', $now, ['error' => mb_substr((string) $newErr, 0, 48)]) ? 1 : 0;
            }
        }

        return $written;
    }

    /**
     * The CPE's PPP session uptime went DOWN (new < old - 60 s) while the ONU kept running: the PPP session
     * reconnected. Both values must be fresh (new: the extractor read it; old: its values were stored within
     * OLD_VALUE_MAX_HOURS). The reconnect happened at (time the CPE reported the value) - new uptime. Hourly data: at
     * most one event per refresh. Also stores the new value in ppp_uptime_s (a missing column = nothing happens).
     *
     * @param  array<string,mixed>  $new
     */
    private static function pppReconnect(string $id, ?int $cid, object $o, array $new, CarbonInterface $now, bool $boot): int
    {
        try {
            $newUp = $new['_cpe']['ppp_uptime_s'] ?? null;
            $newAt = $new['_cpe']['ppp_uptime_at'] ?? null; // the time the CPE itself reported the value
            $oldUp = $o->ppp_uptime_s ?? null;

            // store the new value for the next comparison (own write: the column is not in the GenieACS upsert list).
            // A stale / missing value is stored as NULL (baseline again); an unchanged value costs no query.
            $store = is_numeric($newUp) ? (int) $newUp : null;
            if ($store !== ($oldUp === null ? null : (int) $oldUp)) {
                DB::table('genieacs_devices')->where('device_id', $id)->update(['ppp_uptime_s' => $store]);
            }

            if ($boot || $store === null || $oldUp === null || ! $newAt instanceof CarbonInterface || ($new['ppp_values_at'] ?? null) === null || $o->ppp_values_at === null) {
                return 0;
            }
            if (Carbon::parse($o->ppp_values_at, 'UTC')->lt($now->copy()->subHours(self::OLD_VALUE_MAX_HOURS))) {
                return 0; // the old side is ancient: baseline again
            }
            if ($store >= (int) $oldUp - self::BOOT_MIN_DELTA_SECONDS) {
                return 0;
            }
            // a PPP uptime cannot be longer than the ONU uptime: when the ONU restarted, boot / uptime_reset says it
            $deviceUp = isset($new['uptime_seconds']) && is_numeric($new['uptime_seconds']) ? (int) $new['uptime_seconds'] : null;
            if ($deviceUp !== null && $o->uptime_seconds !== null && (int) $o->uptime_seconds - $deviceUp > CpeEvents::RESET_MIN_DROP_SECONDS) {
                return 0;
            }
            // the reconnect happened (value time - uptime): the leaf may be up to 2 hours older than this sync
            $at = Carbon::instance($newAt)->utc()->subSeconds($store);

            return CpeEvents::record($cid, $id, 'ppp_reconnect', $at, ['old_uptime' => (int) $oldUp, 'new_uptime' => $store]) ? 1 : 0;
        } catch (\Throwable) {
            return 0; // never into the sync
        }
    }

    private static ?bool $pppUptimeColumn = null;

    /** genieacs_devices.ppp_uptime_s exists (memoized per process: one information_schema read, not one per inform). */
    private static function pppUptimeColumn(): bool
    {
        if (self::$pppUptimeColumn === null) {
            try {
                self::$pppUptimeColumn = Schema::hasColumn('genieacs_devices', 'ppp_uptime_s');
            } catch (\Throwable) {
                return false;
            }
        }

        return self::$pppUptimeColumn;
    }

    /** Test hook: null = look at the schema again, true / false = pretend the column is there / not there. */
    public static function forcePppUptimeColumn(?bool $present): void
    {
        self::$pppUptimeColumn = $present;
    }

    private static function bootAlreadyRecorded(string $deviceId, CarbonInterface $bootAt): bool
    {
        $from = $bootAt->copy()->utc()->subSeconds(self::BOOT_DEDUPE_SECONDS)->format('Y-m-d H:i:s');
        $to = $bootAt->copy()->utc()->addSeconds(self::BOOT_DEDUPE_SECONDS)->format('Y-m-d H:i:s');

        try {
            return DB::table('cpe_events')->where('device_id', $deviceId)->where(function ($q) use ($from, $to): void {
                $q->where(fn ($b) => $b->where('type', 'boot')->whereBetween('at', [$from, $to]))
                    ->orWhere(fn ($u) => $u->where('type', 'uptime_reset')->where('at', '>=', $from));
            })->exists();
        } catch (\Throwable) {
            return false; // table missing: record() will fail quietly too
        }
    }
}
