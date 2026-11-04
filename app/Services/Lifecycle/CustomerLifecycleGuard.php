<?php

namespace App\Services\Lifecycle;

use App\Models\Customer;
use App\Models\GenieAcsDevice;
use App\Models\OltOnu;
use App\Models\User;
use App\Services\Monitoring\PppoeUsageRollup;
use App\Services\Monitoring\RouterPppoeReadModel;
use App\Services\OltOnuSerialResolver;
use App\Services\OnlineStatusEvaluator;
use App\Support\OnuLiveState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Roadmap 1.3: may this customer be terminated / archived, may this ONU
 * reference be deleted? Refused when PPPoE is active, the ONU is working, or
 * there was traffic in the last 2 days. Fail-closed: any signal that cannot be
 * proven clear blocks (unknown = deny). Strictly READ-ONLY: it never writes
 * to the database and never talks to a device (per-ref live reads are passed
 * in by the caller). There is deliberately no global off switch.
 *
 * Signals:
 *  - pppoe: customers.is_online + pppoe_online_checked_at (stale positive = in_use),
 *           fallback Grafana per-router live list (trashed customers use only this)
 *  - onu:   olt_onus snapshot (strict vendors need fresh evidence) + GenieACS
 *           inform (positive-only). Non-strict vendors (HiOSO) tolerate an
 *           unknown ONU when PPPoE and traffic are both proven clear (owner D1).
 *  - traffic: pppoe_usage_daily over whole WIB days; clear only if the router's
 *           rollup is alive and the covered days have quality ok.
 */
class CustomerLifecycleGuard
{
    public const MODE_CASE = 'case';

    public const MODE_DUPLICATE = 'duplicate';

    public const MODE_MANUAL = 'manual';

    /** @var array<string, array{last_write: mixed, start_rows: int}> */
    private array $rollupProbes = [];

    public function __construct(
        private readonly OnlineStatusEvaluator $evaluator,
        private readonly OltOnuSerialResolver $resolver,
        private readonly RouterPppoeReadModel $grafana,
        private readonly PppoeUsageRollup $rollup,
    ) {}

    public function canTerminate(Customer $customer, GuardContext $context): GuardDecision
    {
        return $this->evaluateCustomer('terminate', $customer, $context);
    }

    public function canArchive(Customer $customer, GuardContext $context): GuardDecision
    {
        return $this->evaluateCustomer('archive', $customer, $context);
    }

    /**
     * @param  string  $mode  case | manual (live ONU OFFLINE + PPPoE + traffic of $customer)
     *                        or duplicate (target live OFFLINE + working twin ONLINE; no PPPoE/traffic check)
     * @param  array{ok?: bool, absent?: bool, state?: string}|null  $liveRead  OnuLiveReadService::read() of the target ref
     * @param  array{ok?: bool, absent?: bool, state?: string}|null  $twinLiveRead  duplicate mode: live read of the working twin
     */
    public function canDeleteOnuRef(string $mode, ?Customer $customer, ?array $liveRead, GuardContext $context, ?array $twinLiveRead = null, ?string $vendor = null): GuardDecision
    {
        if (! in_array($mode, [self::MODE_CASE, self::MODE_DUPLICATE, self::MODE_MANUAL], true)) {
            throw new InvalidArgumentException('Unknown guard mode: '.$mode);
        }

        $signals = [$this->onuRefSignal($liveRead)];

        if ($mode === self::MODE_DUPLICATE) {
            $twin = $twinLiveRead === null ? OnuLiveState::UNKNOWN : $this->liveState($twinLiveRead);
            $signals[] = $twin === OnuLiveState::ONLINE
                ? GuardSignal::clear('onu_twin', 'twin_online', '')
                : GuardSignal::unknown('onu_twin', 'twin_not_online', 'ONU kembar yang bekerja tidak terbukti ONLINE; ONU duplikat tidak dihapus.');

            return GuardDecision::fromSignals('delete_onu_'.$mode, $signals);
        }

        if ($customer === null) {
            $signals[] = GuardSignal::notApplicable(GuardSignal::PPPOE, 'no_customer');
            $signals[] = GuardSignal::notApplicable(GuardSignal::TRAFFIC, 'no_customer');
        } else {
            $pppoe = $this->pppoeSignal($customer);
            $traffic = $this->trafficSignal($customer);
            $signals[] = $pppoe;
            $signals[] = $traffic;

            // Owner D1: a vendor without a live ONU read (HiOSO) cannot prove the ONU
            // state per ref. Tolerated only when the vendor is positively known to be
            // non-strict AND PPPoE and traffic are both proven clear.
            if ($liveRead === null
                && trim((string) $vendor) !== ''
                && ! $this->evaluator->isStrictVendor($vendor)
                && $pppoe->state === GuardSignal::CLEAR
                && $traffic->state === GuardSignal::CLEAR) {
                $signals[0] = GuardSignal::clear(GuardSignal::ONU, 'onu_unknown_tolerated_non_strict', '', ['source' => 'none', 'vendor_strict' => false, 'd1' => true]);
            }
        }

        return GuardDecision::fromSignals('delete_onu_'.$mode, $signals);
    }

    /**
     * Superadmin override of a denied/unknown decision. UI only: automated
     * callers (jobs, commands, importers) never override. Pure check; the
     * caller records the activity log entry.
     *
     * @return array{ok: bool, error: ?string, reason: string}
     */
    public function authorizeOverride(?User $actor, string $reason, GuardDecision $decision, GuardContext $context): array
    {
        $reason = trim($reason);
        $error = null;
        if ($context->automated) {
            $error = 'automated_never_overridable';
        } elseif ($decision->allowed()) {
            $error = 'override_not_needed';
        } elseif ($actor === null || ! $actor->isSuperAdmin()) {
            $error = 'superadmin_required';
        } elseif (mb_strlen($reason) < (int) config('lifecycle_guard.override_reason_min', 20)) {
            $error = 'reason_too_short';
        }

        return ['ok' => $error === null, 'error' => $error, 'reason' => $reason];
    }

    // ------------------------------------------------------------------
    // Composition
    // ------------------------------------------------------------------

    private function evaluateCustomer(string $action, Customer $customer, GuardContext $context): GuardDecision
    {
        $pppoe = $this->pppoeSignal($customer);
        $traffic = $this->trafficSignal($customer, $context);
        $onu = $this->onuSignal($customer, $context);

        // Owner D1: on non-strict vendors (HiOSO has no reliable ONU state) an
        // unknown ONU is tolerated when PPPoE AND traffic are proven clear.
        if ($onu->state === GuardSignal::UNKNOWN
            && ($onu->evidence['vendor_strict'] ?? null) === false
            && $pppoe->state === GuardSignal::CLEAR
            && $traffic->state === GuardSignal::CLEAR) {
            $onu = GuardSignal::clear(GuardSignal::ONU, 'onu_unknown_tolerated_non_strict', '', $onu->evidence + ['d1' => true]);
        }

        return GuardDecision::fromSignals($action, [$pppoe, $onu, $traffic]);
    }

    // ------------------------------------------------------------------
    // PPPoE
    // ------------------------------------------------------------------

    private function pppoeSignal(Customer $customer): GuardSignal
    {
        try {
            $user = trim((string) $customer->pppoe_user);
            if ($user === '') {
                return GuardSignal::notApplicable(GuardSignal::PPPOE, 'no_pppoe_user');
            }

            $router = $customer->router;
            $routerName = $router?->name ?? '-';

            // Trashed customers are never refreshed by the session sync: their
            // flag is not evidence either way, only Grafana + traffic cover them.
            if (! $customer->trashed()) {
                $flag = $this->evaluator->pppoeSignal($customer);
                if ($flag['online']) {
                    return GuardSignal::inUse(
                        GuardSignal::PPPOE,
                        'pppoe_in_use',
                        'Sesi PPPoE masih aktif ('.$routerName.', dicek '.$this->evaluator->age($flag['checked_at']).').',
                        ['source' => 'customer_flag', 'fresh' => $flag['fresh'], 'checked_at' => $flag['checked_at']?->toIso8601String()],
                    );
                }
                if ($flag['fresh']) {
                    return GuardSignal::clear(GuardSignal::PPPOE, 'pppoe_offline_fresh', '', [
                        'source' => 'customer_flag',
                        'checked_at' => $flag['checked_at']?->toIso8601String(),
                    ]);
                }
                $checkedAge = $this->evaluator->age($flag['checked_at']);
            } else {
                $checkedAge = 'belum pernah';
            }

            $unknown = GuardSignal::unknown(
                GuardSignal::PPPOE,
                'pppoe_unknown',
                'Status PPPoE tidak diketahui (data terakhir '.$checkedAge.').',
                ['source' => 'none'],
            );
            if ($router === null) {
                return $unknown;
            }

            $live = $this->grafana->live($router);
            if (($live['status'] ?? null) !== 'ok' || ! is_array($live['users'] ?? null)) {
                return GuardSignal::unknown(GuardSignal::PPPOE, 'pppoe_unknown', $unknown->message, [
                    'source' => 'grafana',
                    'grafana_status' => (string) ($live['status'] ?? 'unavailable'),
                ]);
            }

            // An empty list from a router with sessions is indistinguishable from a dead exporter.
            if ($live['users'] === []) {
                return GuardSignal::unknown(GuardSignal::PPPOE, 'pppoe_unknown', $unknown->message, [
                    'source' => 'grafana',
                    'why' => 'empty_user_list',
                ]);
            }

            if (array_key_exists(mb_strtolower($user), $live['users'])) {
                return GuardSignal::inUse(
                    GuardSignal::PPPOE,
                    'pppoe_in_use',
                    'Sesi PPPoE masih aktif ('.$routerName.', dicek barusan).',
                    ['source' => 'grafana', 'fetched_at' => $live['fetched_at'] ?? null],
                );
            }

            return GuardSignal::clear(GuardSignal::PPPOE, 'pppoe_absent_grafana', '', [
                'source' => 'grafana',
                'fetched_at' => $live['fetched_at'] ?? null,
            ]);
        } catch (Throwable $e) {
            return $this->failed(GuardSignal::PPPOE, 'pppoe_unknown', 'Status PPPoE tidak diketahui (data terakhir tidak terbaca).', $customer, $e);
        }
    }

    // ------------------------------------------------------------------
    // ONU (customer level)
    // ------------------------------------------------------------------

    private function onuSignal(Customer $customer, GuardContext $context): GuardSignal
    {
        try {
            if ($context->onuReusedByCustomerId !== null && $context->onuReusedByCustomerId !== (int) $customer->id) {
                return GuardSignal::notApplicable(GuardSignal::ONU, 'onu_reused_by_other_customer', '', [
                    'reused_by_customer_id' => $context->onuReusedByCustomerId,
                ]);
            }

            $serial = self::normalizeSerial($customer->onu_serial);
            if ($serial === '') {
                return GuardSignal::notApplicable(GuardSignal::ONU, 'no_onu_serial');
            }

            $rows = DB::table('olt_onus')
                ->leftJoin('olts', 'olts.id', '=', 'olt_onus.olt_id')
                ->whereRaw(self::serialSql('olt_onus.serial_number').' = ?', [$serial])
                ->orderBy('olt_onus.id')
                ->get([
                    'olt_onus.id', 'olt_onus.olt_id', 'olt_onus.onu_ref', 'olt_onus.serial_number',
                    'olt_onus.state', 'olt_onus.phase_state', 'olt_onus.rx_power_dbm',
                    'olt_onus.state_collected_at', 'olt_onus.rx_collected_at',
                    'olts.vendor as olt_vendor', 'olts.name as olt_name',
                ]);
            $row = $rows->isEmpty() ? null : $this->resolver->pick($rows, $customer->olt_id !== null ? (int) $customer->olt_id : null);

            $vendor = $row?->olt_vendor ?? $customer->olt?->vendor;
            // null = vendor unknown: never treated as non-strict (owner D1 needs a positively known non-strict vendor).
            $strict = trim((string) $vendor) === '' ? null : $this->evaluator->isStrictVendor($vendor);
            $oltName = (string) ($row?->olt_name ?? '-');

            $signal = $this->snapshotSignal($row, $strict, $serial, $oltName);

            if ($signal->state !== GuardSignal::IN_USE) {
                $inform = $this->genieAcsInform($customer, $serial, (int) config('lifecycle_guard.genieacs_inform_minutes', 30) * 60);
                if ($inform !== null) {
                    return GuardSignal::inUse(
                        GuardSignal::ONU,
                        'onu_in_use',
                        'ONU '.$serial.' masih working/online di '.$oltName.' (GenieACS inform '.$this->evaluator->age($inform).').',
                        ['source' => 'genieacs', 'last_inform_at' => $inform->toIso8601String(), 'vendor_strict' => $strict],
                    );
                }
            }

            return $signal;
        } catch (Throwable $e) {
            return $this->failed(GuardSignal::ONU, 'onu_unknown', 'Status ONU tidak diketahui (data OLT basi/tidak ada).', $customer, $e);
        }
    }

    private function snapshotSignal(?object $row, ?bool $strict, string $serial, string $oltName): GuardSignal
    {
        $unknown = static fn (string $why): GuardSignal => GuardSignal::unknown(
            GuardSignal::ONU,
            'onu_unknown',
            'Status ONU tidak diketahui (data OLT basi/tidak ada).',
            ['source' => 'olt_onus', 'why' => $why, 'vendor_strict' => $strict],
        );

        if ($row === null) {
            return $unknown('no_snapshot');
        }
        if (! filled($row->state) && ! filled($row->phase_state)) {
            return $unknown('no_state');
        }

        $value = OltOnu::normalizeOperationalState($row->state, $row->phase_state);
        $collected = $this->evaluator->carbon($row->state_collected_at);
        $maxAge = $strict !== false
            ? $this->evaluator->stateMaxAgeMinutes()
            : (int) config('lifecycle_guard.inventory_fresh_minutes', 120);
        $fresh = $this->evaluator->isFresh($collected, $maxAge);
        $evidence = [
            'source' => 'olt_onus',
            'olt_id' => (int) $row->olt_id,
            'onu_ref' => $row->onu_ref,
            'state' => $value,
            'fresh' => $fresh,
            'collected_at' => $collected?->toIso8601String(),
            'vendor_strict' => $strict,
        ];

        // A positive is evidence however old it is (stale positive = in_use).
        if ($value === 'online') {
            return GuardSignal::inUse(
                GuardSignal::ONU,
                'onu_in_use',
                'ONU '.$serial.' masih working/online di '.$oltName.' '.($row->onu_ref ?? '').' (dicek '.$this->evaluator->age($collected).').',
                $evidence,
            );
        }
        // A negative only counts when fresh; a stale negative proves nothing.
        if ($value === 'offline' && $fresh) {
            return GuardSignal::clear(GuardSignal::ONU, 'onu_offline_fresh', '', $evidence);
        }

        return GuardSignal::unknown(
            GuardSignal::ONU,
            'onu_unknown',
            'Status ONU tidak diketahui (data OLT basi/tidak ada).',
            $evidence + ['why' => $value === 'offline' ? 'stale_negative' : 'unrecognized_state'],
        );
    }

    private function genieAcsInform(Customer $customer, string $serial, int $seconds): ?Carbon
    {
        $since = now()->subSeconds($seconds);
        $device = GenieAcsDevice::query()
            ->where(function ($q) use ($customer, $serial): void {
                $q->where('customer_id', $customer->id);
                if ($serial !== '') {
                    $q->orWhereRaw(self::serialSql('serial_number').' = ?', [$serial]);
                }
            })
            ->where('last_inform_at', '>=', $since)
            ->orderByDesc('last_inform_at')
            ->first(['id', 'last_inform_at']);

        return $device?->last_inform_at ? Carbon::instance($device->last_inform_at) : null;
    }

    // ------------------------------------------------------------------
    // ONU per reference (live read passed in)
    // ------------------------------------------------------------------

    /** @param array<string, mixed>|null $read */
    private function liveState(?array $read): string
    {
        if ($read === null || ! ($read['ok'] ?? false)) {
            return OnuLiveState::UNKNOWN;
        }

        return match ((string) ($read['state'] ?? '')) {
            OnuLiveState::ONLINE => OnuLiveState::ONLINE,
            OnuLiveState::OFFLINE => OnuLiveState::OFFLINE,
            default => OnuLiveState::UNKNOWN,
        };
    }

    /** @param array<string, mixed>|null $read */
    private function onuRefSignal(?array $read): GuardSignal
    {
        $state = $this->liveState($read);
        if ($state === OnuLiveState::OFFLINE) {
            return GuardSignal::clear(GuardSignal::ONU, 'onu_ref_offline', '', ['source' => 'live_read']);
        }
        if ($state === OnuLiveState::ONLINE) {
            return GuardSignal::inUse(GuardSignal::ONU, 'onu_in_use', 'ONU masih working/online di OLT (cek langsung).', ['source' => 'live_read']);
        }

        return GuardSignal::unknown(GuardSignal::ONU, 'onu_unknown', 'Status ONU tidak diketahui (cek langsung gagal/ambigu).', ['source' => 'live_read']);
    }

    // ------------------------------------------------------------------
    // Traffic
    // ------------------------------------------------------------------

    private function trafficSignal(Customer $customer, ?GuardContext $context = null): GuardSignal
    {
        $unknownMessage = 'Data trafik 2 hari terakhir belum lengkap untuk router ini.';
        try {
            $windowHours = (int) config('lifecycle_guard.traffic_window_hours', 48);
            $windowDays = max(1, intdiv($windowHours, 24));
            $minBytes = (int) config('lifecycle_guard.min_bytes', 1048576);
            $today = Carbon::now(PppoeUsageRollup::TZ)->startOfDay();
            // 48 h => today + yesterday WIB (day -1 counts, day -2 does not).
            $start = $today->copy()->subDays($windowDays - 1);
            $user = mb_strtolower(trim((string) $customer->pppoe_user));

            $agg = DB::table('pppoe_usage_daily')
                ->where('usage_date_wib', '>=', $start->toDateString())
                ->where('quality', '!=', PppoeUsageRollup::QUALITY_INVALID)
                ->where(function ($q) use ($customer, $user): void {
                    $q->where('customer_id', $customer->id);
                    if ($user !== '') {
                        $q->orWhereRaw('LOWER(pppoe_user) = ?', [$user]);
                    }
                })
                ->selectRaw('COALESCE(SUM(download_bytes + upload_bytes), 0) as total_bytes')
                ->selectRaw('MAX(CASE WHEN download_bytes + upload_bytes > 0 THEN usage_date_wib END) as last_day')
                ->first();

            $total = (int) ($agg->total_bytes ?? 0);
            if ($total >= $minBytes) {
                return GuardSignal::inUse(
                    GuardSignal::TRAFFIC,
                    'traffic_in_use',
                    'Ada trafik '.$this->formatBytes($total).' dalam '.$windowDays.' hari terakhir (terakhir '.Carbon::parse((string) $agg->last_day)->format('d/m/Y').' WIB).',
                    ['source' => 'pppoe_usage_daily', 'bytes' => $total, 'last_day' => (string) $agg->last_day, 'since' => $start->toDateString()],
                );
            }

            $unknownEvidence = ['source' => 'pppoe_usage_daily', 'bytes' => $total, 'since' => $start->toDateString()];
            $routerId = $customer->router_id !== null ? (int) $customer->router_id : null;

            $proven = false;
            if ($routerId !== null) {
                $quality = $this->rollup->qualityFor($routerId, $start->toImmutable());
                if ($quality !== PppoeUsageRollup::QUALITY_OK) {
                    $unknownEvidence['why'] = 'window_quality_'.$quality;
                } else {
                    $probe = $this->rollupProbe($routerId, $start->toDateString());
                    $alive = $probe['last_write'] !== null
                        && $this->evaluator->isFresh($this->evaluator->carbon($probe['last_write']), (int) config('lifecycle_guard.rollup_alive_minutes', 60));
                    $unknownEvidence['why'] = ! $alive ? 'rollup_not_alive' : ($probe['start_rows'] > 0 ? null : 'window_start_day_not_rolled_up');
                    $proven = $alive && $probe['start_rows'] > 0;
                }
            } else {
                $unknownEvidence['why'] = 'no_router';
            }

            if ($proven) {
                return GuardSignal::clear(GuardSignal::TRAFFIC, 'traffic_clear', '', ['source' => 'pppoe_usage_daily', 'bytes' => $total, 'since' => $start->toDateString()]);
            }

            // Fallback (positive-only): a GenieACS inform inside the window.
            $serial = self::normalizeSerial($customer->onu_serial);
            // Reused ONU (takeover): the shared serial's informs belong to the new
            // account, so match this customer's own device link only (same rule
            // as the ONU signal).
            $matchSerial = ! ($context !== null
                && $context->onuReusedByCustomerId !== null
                && $context->onuReusedByCustomerId !== (int) $customer->id);
            $inform = $this->genieAcsInform($customer, $matchSerial ? $serial : '', $windowHours * 3600);
            if ($inform !== null) {
                return GuardSignal::inUse(
                    GuardSignal::TRAFFIC,
                    'traffic_in_use',
                    'Ada aktivitas perangkat dalam '.$windowDays.' hari terakhir (inform terakhir '.$inform->copy()->setTimezone(PppoeUsageRollup::TZ)->format('d/m/Y').' WIB).',
                    ['source' => 'genieacs', 'last_inform_at' => $inform->toIso8601String()],
                );
            }

            return GuardSignal::unknown(GuardSignal::TRAFFIC, 'traffic_unknown', $unknownMessage, $unknownEvidence);
        } catch (Throwable $e) {
            return $this->failed(GuardSignal::TRAFFIC, 'traffic_unknown', $unknownMessage, $customer, $e);
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function failed(string $name, string $reason, string $message, Customer $customer, Throwable $e): GuardSignal
    {
        // Class only: messages can carry SQL / hostnames / secrets.
        Log::warning('[lifecycle-guard] signal failed', [
            'signal' => $name,
            'customer_id' => $customer->id,
            'exception' => $e::class,
        ]);

        return GuardSignal::unknown($name, $reason, $message, ['why' => 'exception']);
    }

    /**
     * Rollup health of one router, memoized per guard instance (one query per
     * router per run): newest write inside the window and the number of valid
     * rows on the window's first day (proves that day was rolled up at all).
     *
     * @return array{last_write: mixed, start_rows: int}
     */
    private function rollupProbe(int $routerId, string $startDay): array
    {
        $key = $routerId.'|'.$startDay;
        if (isset($this->rollupProbes[$key])) {
            return $this->rollupProbes[$key];
        }

        $row = DB::table('pppoe_usage_daily')
            ->where('router_id', $routerId)
            ->where('usage_date_wib', '>=', $startDay)
            ->selectRaw('MAX(updated_at) as last_write')
            ->selectRaw('SUM(CASE WHEN usage_date_wib = ? AND quality != ? THEN 1 ELSE 0 END) as start_rows', [$startDay, PppoeUsageRollup::QUALITY_INVALID])
            ->first();

        return $this->rollupProbes[$key] = [
            'last_write' => $row->last_write ?? null,
            'start_rows' => (int) ($row->start_rows ?? 0),
        ];
    }

    /** Same normalization as DeviceRecoveryService::normalizeSerial: upper-case, only A-Z0-9. */
    public static function normalizeSerial(mixed $serial): string
    {
        return strtoupper(preg_replace('/[^A-Z0-9]/i', '', trim((string) $serial)) ?? '');
    }

    /**
     * Normalized serial plus its GPON ASCII/hex twin (FHTT0000A001 <-> 464854540000A001),
     * same equivalence as DeviceRecoveryService::serialsMatch().
     *
     * @return list<string>
     */
    public static function serialForms(mixed $serial): array
    {
        $normalized = self::normalizeSerial($serial);
        if ($normalized === '') {
            return [];
        }
        $forms = [$normalized];
        if (preg_match('/^([A-Z]{4})([0-9A-F]{8})$/', $normalized, $m) === 1) {
            $forms[] = strtoupper(bin2hex($m[1])).$m[2];
        } elseif (preg_match('/^((?:[0-9A-F]{2}){4})([0-9A-F]{8})$/', $normalized, $m) === 1) {
            $ascii = (string) hex2bin($m[1]);
            if (preg_match('/^[A-Z]{4}$/', $ascii) === 1) {
                $forms[] = $ascii.$m[2];
            }
        }

        return array_values(array_unique($forms));
    }

    /**
     * Every customer that references the serial (any form): soft-deleted and
     * terminated included. Throws on DB errors (callers treat that as unknown).
     *
     * @return Collection<int, Customer>
     */
    public static function ownersOfSerial(mixed $serial): Collection
    {
        $forms = self::serialForms($serial);
        if ($forms === []) {
            return collect();
        }

        return Customer::withTrashed()
            ->where(function ($q) use ($forms): void {
                foreach ($forms as $form) {
                    $q->orWhereRaw(self::serialSql('onu_serial').' = ?', [$form]);
                }
            })
            ->get();
    }

    /** Deny beats unknown beats allow. @param non-empty-list<GuardDecision> $decisions */
    public static function worst(array $decisions): GuardDecision
    {
        foreach ([GuardDecision::DENY, GuardDecision::UNKNOWN] as $verdict) {
            foreach ($decisions as $decision) {
                if ($decision->verdict === $verdict) {
                    return $decision;
                }
            }
        }

        return $decisions[0];
    }

    /** SQL counterpart of normalizeSerial() (superset of DeviceRecoveryOltCleanupService's REPLACE chain). */
    public static function serialSql(string $column): string
    {
        $expr = "COALESCE({$column}, '')";
        foreach (['-', ' ', ':', '.', '_', '/'] as $char) {
            $expr = "REPLACE({$expr}, '{$char}', '')";
        }

        return "UPPER({$expr})";
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2).' GB';
        }

        return number_format($bytes / 1048576, 1).' MB';
    }
}
