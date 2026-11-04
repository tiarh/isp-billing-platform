<?php

namespace App\Services\Outage;

use App\Exceptions\HuaweiOltSessionBusyException;
use App\Models\Customer;
use App\Models\CustomerMigration;
use App\Models\Olt;
use App\Models\OutageSignal;
use App\Models\PsbDraft;
use App\Services\OltHuaweiMa5608tService;
use App\Services\OltZteC300Service;
use App\Support\OltOperationLock;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * RCA-2: on-demand cause read of ONE ONU after the shadow detector saw it go down without a clear cause.
 *
 * Safety rules (this class is the only place that opens an OLT session for enrichment):
 *  - READ-ONLY commands only (ZTE `show gpon onu detail-info`, Huawei `display ont info`).
 *  - It never takes a PSB / migration / delete lock: it only PROBES them and backs off when one is held, before
 *    and again after taking its own per-OLT mutex (same pattern as OltOperationLock::run).
 *  - At most one enrichment session per OLT (own mutex, never waits), a per-OLT hourly cap, a per-ONU cache.
 *  - Kill switch: setting outage_olt_enrich_enabled.
 * Results go to outage_signals.payload (cause_detail / enrich) and onu_state_events; nothing else is written.
 */
class OltCauseEnricher
{
    public const STATUS_OK = 'ok';

    public const STATUS_DISABLED = 'disabled';

    public const STATUS_CACHED = 'cached';

    public const STATUS_LOCKED = 'skipped_lock';

    public const STATUS_BUSY = 'busy';

    public const STATUS_RATE_LIMITED = 'rate_limited';

    public const STATUS_ERROR = 'error';

    public const STATUS_UNSUPPORTED = 'unsupported';

    /** Statuses a caller should retry later (the OLT is in use); everything else is final. */
    public const RETRY_STATUSES = [self::STATUS_LOCKED, self::STATUS_BUSY];

    private const MUTEX_SECONDS = 180;

    /** PSB draft / open migration of the OLT changed within this many minutes => enrichment stays away. */
    public const RECENT_PROVISIONING_MINUTES = 15;

    public const BACKOFF_MINUTES = 60;

    private const ZTE_TIMEOUT_SECONDS = 25;

    public const MODE_AUTO = 'auto';

    /** RCA-4 "Cek sekarang": a person is waiting, so tighter limits and a separate budget. */
    public const MODE_MANUAL = 'manual';

    private const ZTE_MANUAL_TIMEOUT_SECONDS = 12;

    /** A manual read of the same ONU younger than this is reused instead of asking the OLT again. */
    public const MANUAL_CACHE_SECONDS = 120;

    public function __construct(
        private readonly OltZteC300Service $zte,
        private readonly OltHuaweiMa5608tService $huawei,
    ) {}

    /** Whether the detector should queue an enrichment at all (kill switch + dispatch flag, tests turn it off). */
    public static function dispatchEnabled(): bool
    {
        return (bool) config('outage.olt_enrich_dispatch', true) && OutageSettings::oltEnrichEnabled();
    }

    /**
     * Mode "manual" (RCA-4 "Cek sekarang"): the same lock / skip rules and the same single mutex per OLT, but
     *  - the per-ONU cache only counts when the last manual read is younger than MANUAL_CACHE_SECONDS,
     *  - its own hourly budget (diagnose_olt_manual_per_hour per OLT, nothing global),
     *  - tighter Huawei limits (connect 5 s / read 8 s / deadline 15 s) and a ZTE timeout of ZTE_MANUAL_TIMEOUT_SECONDS,
     *  - no back-off marker is left for the detector.
     * It never waits: a held lock / mutex answers at once with skipped_lock / busy.
     *
     * @return array{status:string,cause?:?string,detail?:array<string,mixed>}
     */
    public function enrich(int $oltId, string $onuRef, ?int $customerId = null, ?int $episodeId = null, ?CarbonInterface $now = null, string $mode = self::MODE_AUTO): array
    {
        $now = $now ? Carbon::instance($now) : now();
        $manual = $mode === self::MODE_MANUAL;
        if (! OutageSettings::oltEnrichEnabled()) {
            return ['status' => self::STATUS_DISABLED];
        }
        $ref = OutageDetector::normalizeRef($onuRef);
        $olt = Olt::query()->find($oltId);
        if ($ref === null || $olt === null) {
            return ['status' => self::STATUS_UNSUPPORTED];
        }
        $family = OutageDetector::VENDOR_FAMILIES[(string) $olt->vendor] ?? null;
        if ($family === null) {
            return ['status' => self::STATUS_UNSUPPORTED];
        }

        // 1. per-ONU cache: claimed now, released again when the OLT was merely busy (so a retry can run).
        $cacheKey = 'outage:enrich:onu:'.$oltId.':'.$ref;
        if ($manual) {
            $manualAt = Cache::get('outage:enrich:manual-at:'.$oltId.':'.$ref);
            if (is_numeric($manualAt) && ($now->getTimestamp() - (int) $manualAt) < self::MANUAL_CACHE_SECONDS) {
                return ['status' => self::STATUS_CACHED];
            }
        } elseif (! Cache::add($cacheKey, 1, now()->addMinutes(OutageSettings::oltEnrichCacheMinutes()))) {
            return ['status' => self::STATUS_CACHED];
        }
        $release = function () use ($manual, $cacheKey): void {
            if (! $manual) {
                Cache::forget($cacheKey);
            }
        };

        // 2. PSB / migration / delete in progress: back off before anything else (not counted against the cap).
        if ($this->operationActive($olt, $ref)) {
            $release();

            return ['status' => self::STATUS_LOCKED];
        }

        // 3. one enrichment session per OLT, never waits.
        $mutex = Cache::lock('outage-enrich-olt:'.$oltId, self::MUTEX_SECONDS);
        if (! $mutex->get()) {
            $release();

            return ['status' => self::STATUS_BUSY];
        }

        try {
            // Re-probe now that the mutex is ours (a PSB run may have started in between).
            if ($this->operationActive($olt, $ref)) {
                $release();

                return ['status' => self::STATUS_LOCKED];
            }
            // 4. hourly cap per OLT (counted only for sessions that are really going to open).
            if (! ($manual ? $this->takeManualBudget($oltId, $now) : $this->takeBudget($oltId, $now))) {
                if (! $manual) {
                    $this->backOff($oltId, $ref);
                }

                return ['status' => self::STATUS_RATE_LIMITED];
            }

            try {
                $result = $family === 'zte'
                    ? $this->readZte($olt, $ref, $customerId, $episodeId, $now, $manual)
                    : $this->readHuawei($olt, $ref, $customerId, $episodeId, $now, $manual);
                if ($manual) {
                    Cache::put('outage:enrich:manual-at:'.$oltId.':'.$ref, $now->getTimestamp(), now()->addMinutes(10));
                } else {
                    unset($result['detail']); // only the manual caller needs the answer; the automatic result keeps its shape
                }
            } catch (HuaweiOltSessionBusyException) {
                $release();

                return ['status' => self::STATUS_BUSY];
            } catch (\Throwable $e) {
                $this->warn('read failed', $oltId, $e);
                if (! $manual) {
                    $this->backOff($oltId, $ref);
                }

                return ['status' => self::STATUS_ERROR];
            }

            return $result;
        } finally {
            try {
                $mutex->release();
            } catch (\Throwable) {
            }
        }
    }

    /** True when a PSB / migration / ONU delete holds its lock on this OLT (probe only, nothing is kept). */
    private function operationActive(Olt $olt, string $ref): bool
    {
        if (OltOperationLock::externalOperationActive($olt, $ref)) {
            return true;
        }
        $keys = [OltOperationLock::key($olt)];
        // Huawei: PSB preflight opens several SSH sessions WITHOUT the psb lock, so the shared SSH session lock is
        // probed too (never waited for): a held session lock means PSB / the collector is on the OLT right now.
        if ((OutageDetector::VENDOR_FAMILIES[(string) $olt->vendor] ?? null) === 'huawei') {
            $keys[] = 'huawei-olt-ssh-session:'.(int) $olt->getKey();
        }
        foreach ($keys as $key) {
            $probe = Cache::lock($key, 5);
            if (! $probe->get()) {
                return true;
            }
            try {
                $probe->release();
            } catch (\Throwable) {
            }
        }

        return $this->recentProvisioning($olt);
    }

    /**
     * A PSB draft or an open migration of this OLT that changed in the last 15 minutes: PSB / migration is working
     * (or is about to work) on the device, so enrichment stays away. Read-only on psb_drafts / customer_migrations.
     */
    private function recentProvisioning(Olt $olt): bool
    {
        try {
            $since = now()->subMinutes(self::RECENT_PROVISIONING_MINUTES);

            return PsbDraft::query()->where('olt_id', $olt->id)->whereIn('status', OutageDetector::PSB_OPEN_STATUSES)->where('updated_at', '>=', $since)->exists()
                || CustomerMigration::query()->open()->where(fn ($q) => $q->where('old_olt_id', $olt->id)->orWhere('new_olt_id', $olt->id))->where('updated_at', '>=', $since)->exists();
        } catch (\Throwable) {
            return true; // unreadable: the safe answer is "someone may be provisioning"
        }
    }

    /** Per-OLT hourly cap AND a global hourly cap (the single ops worker must not spend its time on this). */
    private function takeBudget(int $oltId, Carbon $now): bool
    {
        $hour = $now->copy()->utc()->format('YmdH');
        $perOlt = 'outage:enrich:count:'.$oltId.':'.$hour;
        $global = 'outage:enrich:count:all:'.$hour;

        // RCA-4 (#563 follow-up): take first (atomic increment), compare after, give back when over the cap. A
        // get-then-increment pair let two concurrent takers both pass at cap-1.
        if (! $this->takeSlot($perOlt, OutageSettings::oltEnrichPerHour())) {
            return false;
        }
        if (! $this->takeSlot($global, OutageSettings::oltEnrichGlobalPerHour())) {
            Cache::decrement($perOlt);

            return false;
        }

        return true;
    }

    /** Manual (Cek sekarang) budget: per OLT only, its own counter. */
    private function takeManualBudget(int $oltId, Carbon $now): bool
    {
        return $this->takeSlot('outage:enrich:manual-count:'.$oltId.':'.$now->copy()->utc()->format('YmdH'), OutageSettings::diagnoseOltManualPerHour());
    }

    /** Atomic "take one of $cap": increment first, refuse (and undo) when the new value is over the cap. */
    private function takeSlot(string $key, int $cap): bool
    {
        Cache::add($key, 0, now()->addMinutes(70));
        $n = Cache::increment($key);
        if ($n === false || (int) $n > $cap) {
            if ($n !== false) {
                Cache::decrement($key);
            }

            return false;
        }

        return true;
    }

    /** Back-off after a rate-limited / failed read: the detector stops re-queueing this ONU for an hour. */
    private function backOff(int $oltId, string $ref): void
    {
        Cache::put(self::backoffKey($oltId, $ref), 1, now()->addMinutes(self::BACKOFF_MINUTES));
    }

    private static function backoffKey(int $oltId, string $ref): string
    {
        return 'outage:enrich:backoff:'.$oltId.':'.$ref;
    }

    /** True while the ONU was read lately (cache) or is backing off: the detector does not queue another job. */
    public static function recentlyAttempted(int $oltId, string $ref): bool
    {
        try {
            return Cache::has('outage:enrich:onu:'.$oltId.':'.$ref) || Cache::has(self::backoffKey($oltId, $ref));
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array{status:string,cause:?string,detail:array<string,mixed>} */
    private function readZte(Olt $olt, string $ref, ?int $customerId, ?int $episodeId, Carbon $now, bool $manual = false): array
    {
        $read = $this->zte->onuDetailAndClock($olt, $ref, $manual ? self::ZTE_MANUAL_TIMEOUT_SECONDS : self::ZTE_TIMEOUT_SECONDS);
        $p = ZteDetailInfoParser::parse($read['detail']);
        // The ZTE clock is UTC but unsynchronised: drift = OLT now - app now, history times are corrected by it.
        $drift = OltClock::drift(OltClock::parseZte($read['clock']), $now);
        OltClock::note((int) $olt->id, $drift, $now);
        $oltNow = $now->copy()->addSeconds($drift ?? 0);
        $flapping = ZteDetailInfoParser::isFlapping($p['history'], $oltNow);
        $cause = $p['cause'];
        $rawOffline = $p['last_offline_at'];
        $p['last_offline_at'] = $rawOffline !== null ? OltClock::correct($rawOffline, $drift) : null;

        if ($p['last_offline_at'] !== null && $cause !== null) {
            OnuStateEvents::recordOne([
                'olt_id' => (int) $olt->id,
                'onu_ref' => $ref,
                'customer_id' => $customerId,
                'from_phase' => 'online',
                'to_phase' => in_array($cause, ['los', 'dying_gasp'], true) ? $cause : 'offline',
                'cause' => $cause,
                'source' => 'olt_detail',
                'occurred_at' => $p['last_offline_at'],
                'dedupe_seconds' => 120,
            ], $now);
        }

        // RCA-4 history: keep EVERY drop of the 10-row history of the last 24 h, also while the ONU is online now
        // (the intermittent case). Times: drift removed before the 24 h filter. Same read, no new OLT command.
        $historyDrops = [];
        foreach (ZteDetailInfoParser::drops($p['history'], $now->copy()->subHours(24), $drift) as $drop) {
            if ($drop['at']->gt($now->copy()->addMinutes(5))) {
                continue; // a time in the future is a clock error, never history
            }
            $historyDrops[] = ['at' => $drop['at']->toIso8601String(), 'cause' => $drop['cause']];
            if ($p['last_offline_at'] !== null && $cause !== null && $drop['at']->getTimestamp() === $p['last_offline_at']->getTimestamp()) {
                continue; // the newest drop was written above
            }
            OnuStateEvents::recordOne([
                'olt_id' => (int) $olt->id,
                'onu_ref' => $ref,
                'customer_id' => $customerId,
                'from_phase' => 'online',
                'to_phase' => in_array($drop['cause'], ['los', 'dying_gasp'], true) ? $drop['cause'] : 'offline',
                'cause' => $drop['cause'],
                'source' => 'olt_detail',
                'occurred_at' => $drop['at'],
                'dedupe_seconds' => 120,
            ], $now);
        }

        $enrich = [
            'cause' => $cause,
            'flapping' => $flapping,
            'source' => 'olt_detail',
            'last_offline_at' => $p['last_offline_at']?->toIso8601String(),
            'last_offline_raw' => $rawOffline?->toIso8601String(),
            'drift_seconds' => $drift,
            'online_seconds' => $p['online_duration_seconds'],
        ];
        $this->applyToEpisode($olt, $ref, $customerId, $episodeId, $enrich, $now);

        return ['status' => self::STATUS_OK, 'cause' => $cause, 'detail' => $enrich + ['history_drops' => $historyDrops, 'at' => $now->copy()->utc()->toIso8601String(), 'phase' => $p['phase']]];
    }

    /** @return array{status:string,cause:?string,detail:array<string,mixed>} */
    private function readHuawei(Olt $olt, string $ref, ?int $customerId, ?int $episodeId, Carbon $now, bool $manual = false): array
    {
        $read = $manual ? $this->huawei->ontInfoAndClockManual($olt, $ref) : $this->huawei->ontInfoAndClock($olt, $ref);
        $p = HuaweiOntInfoParser::parse($read['info']);
        $drift = OltClock::drift(OltClock::parseHuawei($read['clock']), $now);
        OltClock::note((int) $olt->id, $drift, $now);
        $cause = $p['cause'];
        $rawDown = $p['last_dying_gasp_at'] ?? $p['last_down_at'];
        $down = $rawDown !== null ? OltClock::correct($rawDown, $drift) : null;
        $p['last_down_at'] = $p['last_down_at'] !== null ? OltClock::correct($p['last_down_at'], $drift) : null;

        if ($p['never_registered']) {
            OnuStateEvents::recordOne([
                'olt_id' => (int) $olt->id, 'onu_ref' => $ref, 'customer_id' => $customerId,
                'from_phase' => null, 'to_phase' => 'offline', 'cause' => 'never_registered',
                'source' => 'huawei_info', 'occurred_at' => $now,
            ], $now);
        } elseif ($p['last_down_at'] !== null) {
            OnuStateEvents::recordOne([
                'olt_id' => (int) $olt->id, 'onu_ref' => $ref, 'customer_id' => $customerId,
                'from_phase' => 'online', 'to_phase' => $cause === 'los' ? 'los' : ($cause === 'dying_gasp' ? 'dying_gasp' : 'offline'),
                'cause' => $cause, 'source' => 'huawei_info', 'occurred_at' => $p['last_down_at'],
            ], $now);
        }

        $enrich = [
            'cause' => $p['never_registered'] ? 'never_registered' : $cause,
            'source' => 'huawei_info',
            'rebooted' => $p['rebooted'],
            'last_offline_at' => $down?->toIso8601String(),
            'last_offline_raw' => $rawDown?->toIso8601String(),
            'drift_seconds' => $drift,
            'run_state' => $p['run_state'],
            'online_seconds' => $p['online_seconds'],
            'system_up_seconds' => $p['system_up_seconds'],
        ];
        // The episode payload keeps its historic shape (cause as parsed); the returned detail names never_registered.
        $this->applyToEpisode($olt, $ref, $customerId, $episodeId, array_merge($enrich, ['cause' => $cause]), $now);

        return ['status' => self::STATUS_OK, 'cause' => $cause, 'detail' => $enrich + ['at' => $now->copy()->utc()->toIso8601String()]];
    }

    /**
     * Writes cause_detail / enrich into the open episode payload (the one that triggered the read). The existing
     * payload.cause stays as the detector set it; the panel and the detector read cause_detail first.
     *
     * @param  array<string,mixed>  $enrich
     */
    private function applyToEpisode(Olt $olt, string $ref, ?int $customerId, ?int $episodeId, array $enrich, Carbon $now): void
    {
        $ep = $episodeId !== null ? OutageSignal::query()->open()->whereKey($episodeId)->first() : null;
        if ($ep === null && $customerId !== null) {
            $ep = OutageSignal::query()->open()->where('customer_id', $customerId)->where('signal_type', 'single_los')->first();
        }
        if ($ep === null) {
            return;
        }
        $payload = (array) $ep->payload;
        $payload['enrich'] = array_filter($enrich + ['at' => $now->copy()->utc()->toIso8601String()], fn ($v) => $v !== null);
        if (! empty($enrich['cause'])) {
            $payload['cause_detail'] = $enrich['cause'];
        }
        $ep->payload = $payload;
        $ep->save();
    }

    private function warn(string $what, int $oltId, \Throwable $e): void
    {
        try {
            Log::warning('[Outage] OLT enrichment '.$what, ['olt_id' => $oltId, 'exception_class' => $e::class]);
        } catch (\Throwable) {
        }
    }

    /** Customer id of an ONU ref, only when exactly one customer points at it (for the history rows). */
    public static function customerOf(Olt $olt, string $ref): ?int
    {
        $ids = Customer::query()->where('olt_id', $olt->id)->whereIn('olt_port_label', [$ref, 'gpon-onu_'.$ref])->pluck('id');

        return $ids->count() === 1 ? (int) $ids->first() : null;
    }
}
