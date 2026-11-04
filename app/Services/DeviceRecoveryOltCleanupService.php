<?php

namespace App\Services;

use App\Exceptions\HuaweiOltSessionBusyException;
use App\Http\Controllers\OltController;
use App\Models\Customer;
use App\Models\DeviceRecoveryCase;
use App\Models\DeviceRecoveryEvent;
use App\Models\Olt;
use App\Models\OltOnu;
use App\Models\Setting;
use App\Models\User;
use App\Services\Lifecycle\CustomerLifecycleGuard;
use App\Services\Lifecycle\GuardContext;
use App\Services\Lifecycle\GuardDecision;
use App\Services\Lifecycle\GuardSignal;
use App\Support\OltOperationLock;
use App\Support\OnuLiveState;
use App\Support\OpsAlertSource;
use App\Support\SensitiveDataRedactor;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Optional, fail-closed OLT ONU cleanup for completed device-recovery
 * (dismantle) cases.
 *
 * When a dismantle recovery case reaches the terminal `received` state, the
 * customer's ONU may be deleted from the OLT so the port/serial is freed. This
 * is a device WRITE, so it is:
 *   - disabled by default (kill switch `device_recovery_olt_delete_enabled`),
 *   - additionally gated by `config('device-recovery.mode') === 'write'`,
 *   - only ever applied to an EXACT serial match in the OLT inventory,
 *   - never applied when the serial is still referenced by any live
 *     (non-terminated) customer, including the case's own customer,
 *   - targets the serial actually COLLECTED on the received case item; the
 *     customer's onu_serial is only a fallback when no serial was captured and
 *     the case is not keep_customer_active (multi-device home),
 *   - ZTE and Huawei: the live serial at the ONU ref is read back from the
 *     OLT and must match before the delete command is sent,
 *   - recorded append-only on the case (meta + event) whether it runs or skips.
 *
 * The recovery ledger itself stays append-only: this never deletes customer,
 * GenieACS, or billing rows.
 */
class DeviceRecoveryOltCleanupService
{
    public const META_KEY = 'olt_onu_delete';

    public const EVENT_TYPE = 'olt_onu_delete';

    /** Seconds to wait for the per-OLT lock (PSB uses the same 10s). */
    private const LOCK_WAIT_SECONDS = 10;

    public function __construct(
        private DeviceRecoveryService $recovery,
    ) {}

    public function isEnabled(): bool
    {
        if ((string) config('device-recovery.mode', 'read_only') !== 'write') {
            return false;
        }

        return (bool) filter_var(
            Setting::get('device_recovery_olt_delete_enabled', false),
            FILTER_VALIDATE_BOOLEAN
        );
    }

    /**
     * Read-only resolution of what would be deleted. Safe to call any time
     * (no device write, no DB write).
     *
     * @return array<string, mixed>
     */
    public function resolveTarget(DeviceRecoveryCase $case): array
    {
        $customer = $case->customer
            ?: ($case->customer_id ? Customer::withTrashed()->find($case->customer_id) : null);
        if (! $customer) {
            return ['ok' => false, 'reason' => 'customer_missing'];
        }

        $keepActive = (bool) ($case->keep_customer_active ?? false);
        $customerSerial = $this->recovery->normalizeSerial($customer->onu_serial ?? null);
        $collected = $this->collectedSerials($case);
        if (count($collected) > 1) {
            return ['ok' => false, 'reason' => 'multiple_collected_serials', 'collected_count' => count($collected)];
        }

        $serial = $collected[0] ?? null;
        $serialSource = 'collected_item';
        if ($serial === null) {
            if ($keepActive) {
                // Multi-device home: never fall back to the customer's (live) ONU.
                return ['ok' => false, 'reason' => 'keep_active_no_collected_serial'];
            }
            $serial = $customerSerial;
            $serialSource = 'customer_onu_serial';
        } elseif ($keepActive && $serial === $customerSerial) {
            return ['ok' => false, 'reason' => 'keep_active_serial_is_customer_onu', 'serial' => $serial];
        }

        $oltId = (int) ($customer->olt_id ?? 0);
        if ($oltId <= 0 || $serial === null || $serial === '') {
            return ['ok' => false, 'reason' => 'no_olt_or_serial', 'olt_id' => $oltId ?: null, 'serial' => $serial];
        }

        $olt = Olt::query()->find($oltId);
        if (! $olt) {
            return ['ok' => false, 'reason' => 'olt_missing', 'olt_id' => $oltId, 'serial' => $serial];
        }

        if (! $this->supportsOnuDelete($olt)) {
            return ['ok' => false, 'reason' => 'unsupported_vendor', 'olt_id' => $oltId, 'vendor' => $olt->vendor ?? null, 'serial' => $serial];
        }

        if (! Schema::hasTable('olt_onus')) {
            return ['ok' => false, 'reason' => 'inventory_unavailable', 'olt_id' => $oltId, 'serial' => $serial];
        }

        $candidates = OltOnu::query()
            ->where('olt_id', $oltId)
            ->whereRaw(
                "UPPER(REPLACE(REPLACE(COALESCE(serial_number, ''), '-', ''), ' ', '')) = ?",
                [$serial]
            )
            ->get();

        if ($candidates->isEmpty()) {
            return ['ok' => false, 'reason' => 'onu_not_found', 'olt_id' => $oltId, 'serial' => $serial];
        }
        if ($candidates->count() > 1) {
            return ['ok' => false, 'reason' => 'ambiguous_onu', 'olt_id' => $oltId, 'serial' => $serial, 'candidate_count' => $candidates->count()];
        }

        $onu = $candidates->first();
        $onuRef = trim((string) $onu->onu_ref);
        if ($onuRef === '') {
            return ['ok' => false, 'reason' => 'onu_ref_missing', 'olt_id' => $oltId, 'serial' => $serial];
        }

        // Safety: refuse when the serial is still referenced by ANY live
        // (non-dismantled, non-terminal) customer — including this case's own
        // customer (keep_customer_active, or not yet terminated).
        $otherCustomer = $this->liveCustomerUsesSerial($serial);
        if ($otherCustomer) {
            return ['ok' => false, 'reason' => 'serial_in_use', 'olt_id' => $oltId, 'serial' => $serial, 'onu_ref' => $onuRef];
        }

        return [
            'ok' => true,
            'olt_id' => $oltId,
            'olt_name' => $olt->name,
            'serial' => $serial,
            'serial_source' => $serialSource,
            'onu_ref' => $onuRef,
            'onu_name' => $onu->onu_name,
            'pon_port' => $onu->pon_port,
        ];
    }

    /**
     * Read-only dry run: resolveTarget + live OLT read-back. No device write,
     * no DB write, no persist/recordSkip. `would_delete` is true only when the
     * live serial at onu_ref equals the target serial (the kill switch and
     * mode are NOT evaluated; `mode_write` reports the mode).
     *
     * @return array<string, mixed>
     */
    public function planForCase(DeviceRecoveryCase $case): array
    {
        $case = $case->fresh(['customer']) ?? $case;
        $modeWrite = (string) config('device-recovery.mode', 'read_only') === 'write';

        if ($case->status !== DeviceRecoveryService::STATUS_RECEIVED) {
            return ['ok' => false, 'would_delete' => false, 'reason' => 'not_received', 'live_serial' => null, 'mode_write' => $modeWrite];
        }

        $meta = is_array($case->meta) ? $case->meta : [];
        if (($meta[self::META_KEY]['ok'] ?? false) === true) {
            return ['ok' => true, 'would_delete' => false, 'reason' => 'already_deleted', 'live_serial' => null, 'mode_write' => $modeWrite]
                + array_intersect_key((array) $meta[self::META_KEY], array_flip(['olt_id', 'serial', 'onu_ref']));
        }

        $target = $this->resolveTarget($case);
        if (($target['ok'] ?? false) !== true) {
            return $target + ['would_delete' => false, 'live_serial' => null, 'mode_write' => $modeWrite];
        }

        $olt = Olt::query()->find((int) $target['olt_id']);
        if (! $olt) {
            return ['ok' => false, 'would_delete' => false, 'reason' => 'olt_missing', 'live_serial' => null, 'mode_write' => $modeWrite] + $target;
        }

        $read = $this->liveRead($olt, (string) $target['onu_ref']);
        $live = $read['serial'];
        $match = $live === (string) $target['serial'];
        $reason = $match ? 'ok' : ($live === null ? 'live_verify_failed' : 'live_serial_mismatch');
        if ($match && $read['state'] !== OnuLiveState::OFFLINE) {
            $match = false;
            $reason = $read['state'] === OnuLiveState::ONLINE ? 'onu_online' : 'live_state_unknown';
        }

        // Roadmap 1.3: the lifecycle guard has the last word (read-only here).
        $guard = null;
        if ($match) {
            $decision = $this->evaluateGuard($case, (string) $target['serial'], $read);
            $guard = $this->guardSummary($decision);
            if (! $decision->allowed()) {
                $match = false;
                $reason = 'guard_hold';
            }
        }

        return [
            'ok' => $match,
            'would_delete' => $match,
            'reason' => $reason,
            'live_serial' => $live,
            'live_state' => $read['state_label'],
            'mode_write' => $modeWrite,
        ] + ($guard === null ? [] : ['guard' => $guard]) + $target;
    }

    /**
     * Lifecycle guard for the ONU of a dismantle case (case mode: live ref
     * OFFLINE + PPPoE + traffic of the customer). Automated: never overridable.
     * A serial that is NOT the customer's own ONU (other device of a
     * multi-device home) is not tied to the customer's PPPoE/traffic.
     *
     * @param  array<string, mixed>  $read  liveRead() result of the target ref
     */
    private function evaluateGuard(DeviceRecoveryCase $case, string $serial, array $read): GuardDecision
    {
        $guard = app(CustomerLifecycleGuard::class);
        $context = GuardContext::automated('device_recovery_olt_delete');
        $keepActive = (bool) ($case->keep_customer_active ?? false);

        try {
            $caseCustomer = $case->customer
                ?: ($case->customer_id ? Customer::withTrashed()->find($case->customer_id) : null);

            // Safe rule: the case customer is always evaluated, except in a
            // multi-device home (keep_customer_active) where the collected serial
            // is verifiably another device than the customer's own ONU.
            $customers = [];
            if ($caseCustomer !== null
                && ! ($keepActive && ! $this->recovery->serialsMatch($caseCustomer->onu_serial, $serial))) {
                $customers[$caseCustomer->id] = $caseCustomer;
            }
            // Always: whoever owns that serial (any status, soft-deleted too, hex-aware).
            foreach (CustomerLifecycleGuard::ownersOfSerial($serial) as $owner) {
                $customers[$owner->id] = $owner;
            }

            $decisions = [];
            foreach ($customers === [] ? [null] : array_values($customers) as $customer) {
                $decisions[] = $guard->canDeleteOnuRef(CustomerLifecycleGuard::MODE_CASE, $customer, $read, $context);
            }

            return CustomerLifecycleGuard::worst($decisions);
        } catch (\Throwable $e) {
            Log::warning('[device-recovery] guard owner lookup failed', ['case_id' => $case->id, 'exception_class' => $e::class]);

            return GuardDecision::fromSignals('delete_onu_case', [
                GuardSignal::unknown(GuardSignal::ONU, 'onu_unknown', 'Pemilik ONU tidak dapat ditentukan (data pelanggan tidak terbaca).'),
            ]);
        }
    }

    /** @return array{verdict: string, reasons: list<string>, message: string} */
    private function guardSummary(GuardDecision $decision): array
    {
        return [
            'verdict' => $decision->verdict,
            'reasons' => $decision->reasonCodes(),
            'message' => $decision->message(),
        ];
    }

    /**
     * Guard deny/unknown: HOLD. Never overridable, no OLT command sent. The
     * skip uses its own daily idempotency key so a hold neither spams nor
     * shadows the real delete event. A held case is re-dispatched by the
     * customers:finalize-deferred-terminations sweeper (PR-4b, setting
     * lifecycle_deferred_onu_redispatch_apply, default off) or by an operator
     * re-running the CLI / retry (re-evaluated then).
     * The ops WA is returned in '_wa' and sent by the caller AFTER the per-OLT
     * lock is released (HTTP must not run under the lock).
     *
     * @param  array<string, mixed>  $target
     * @param  array<string, mixed>  $op
     * @return array<string, mixed>
     */
    private function recordGuardHold(DeviceRecoveryCase $case, ?User $actor, Olt $olt, array $target, GuardDecision $decision, array $op): array
    {
        $summary = $this->guardSummary($decision);
        $record = [
            'ok' => false,
            'reason' => 'guard_hold',
            'guard_verdict' => $summary['verdict'],
            'guard_reasons' => $summary['reasons'],
            'guard_message' => $summary['message'],
            'olt_id' => (int) $target['olt_id'],
            'serial' => (string) $target['serial'],
            'onu_ref' => (string) $target['onu_ref'],
            'at' => now()->toIso8601String(),
        ] + $op;
        $record = $this->carryPreviousRebuildHints($case, $record);

        $day = now()->format('Ymd');
        $this->persist($case, $actor, self::EVENT_TYPE, $record, 'olt-onu-delete-guard:'.$case->id.':'.$day);

        return ['status' => 'skipped_guard_hold', '_wa' => [
            'Pagar ONU: hapus ONU '.$target['serial'].' '.($olt->name ?? '-').' '.$target['onu_ref']
                .' (case #'.$case->id.') DITAHAN: '.rtrim($summary['message'], ". \t\n").'. Tidak ada perintah dikirim ke OLT.',
            'olt-onu-delete-guard:'.$case->id.':'.$day,
        ]] + $record;
    }

    /**
     * Sends the hold WA (if any) and strips the internal '_wa' key.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function flushHoldWa(DeviceRecoveryCase $case, array $result): array
    {
        $wa = $result['_wa'] ?? null;
        unset($result['_wa']);
        if (is_array($wa)) {
            try {
                app(OpsAlertService::class)->send((string) $wa[0], (string) $wa[1], 86400, '', OpsAlertSource::OLT_CLEANUP);
            } catch (\Throwable $e) {
                Log::warning('[device-recovery] ops WA failed', ['case_id' => $case->id, 'exception_class' => $e::class]);
            }
        }

        return $result;
    }

    /**
     * Delete the dismantled customer's ONU from the OLT when the recovery case
     * is complete. Fail-closed and idempotent; never throws for a skip.
     *
     * Skips ONLY the kill-switch half of isEnabled() when $explicitOperatorRun
     * is true (CLI --execute). config mode=write is still required and every
     * other gate is unchanged.
     *
     * @return array<string, mixed>
     */
    public function deleteForCase(DeviceRecoveryCase $case, ?User $actor = null, bool $explicitOperatorRun = false): array
    {
        $case = $case->fresh(['customer']) ?? $case;
        $op = $explicitOperatorRun
            ? ['operator_run' => true, 'operator_actor_id' => $actor?->id]
            : [];

        if ($case->status !== DeviceRecoveryService::STATUS_RECEIVED) {
            return $this->recordSkip($case, $actor, 'not_received', ['status' => $case->status] + $op);
        }

        $meta = is_array($case->meta) ? $case->meta : [];
        if (($meta[self::META_KEY]['ok'] ?? false) === true) {
            return ['status' => 'already_deleted'] + (array) $meta[self::META_KEY];
        }

        if ($explicitOperatorRun) {
            if ((string) config('device-recovery.mode', 'read_only') !== 'write') {
                return $this->recordSkip($case, $actor, 'mode_not_write', $op);
            }
        } elseif (! $this->isEnabled()) {
            return $this->recordSkip($case, $actor, 'flag_off');
        }

        $target = $this->resolveTarget($case);
        if (($target['ok'] ?? false) !== true) {
            return $this->recordSkip($case, $actor, (string) ($target['reason'] ?? 'unresolved'), $target + $op);
        }

        $olt = Olt::query()->find((int) $target['olt_id']);
        if (! $olt) {
            return $this->recordSkip($case, $actor, 'olt_missing', $target + $op);
        }

        // One delete at a time per OLT: live read -> delete -> read-back run
        // under the delete lock, and only while no PSB / migration run is
        // active (probed, never held). When busy nothing is written or
        // recorded; the job re-queues itself. The auto path never blocks.
        $wait = $explicitOperatorRun ? (int) config('device-recovery.olt_lock_wait_seconds', self::LOCK_WAIT_SECONDS) : 0;
        $locked = OltOperationLock::run($olt, $wait, function () use ($case, $actor, $olt, $target, $op): array {
            // Read-back gate (ZTE + Huawei): the ONU currently registered at
            // onu_ref must carry exactly the target serial; a stale inventory row
            // must never delete somebody else's ONU.
            $read = $this->liveRead($olt, (string) $target['onu_ref']);
            $live = $read['serial'];
            if ($live !== (string) $target['serial']) {
                return $this->recordSkip($case, $actor, $live === null ? 'live_verify_failed' : 'live_serial_mismatch', $target + [
                    'live_serial' => $live,
                ] + $op);
            }
            // Safety gate: only an ONU that is live OFFLINE may be deleted. A
            // working/online ONU (e.g. a wrongly recapped customer) is never touched.
            if ($read['state'] !== OnuLiveState::OFFLINE) {
                return $this->recordSkip(
                    $case,
                    $actor,
                    $read['state'] === OnuLiveState::ONLINE ? 'onu_online' : 'live_state_unknown',
                    $target + ['live_serial' => $live, 'live_state' => $read['state_label']] + $op
                );
            }

            // Roadmap 1.3 lifecycle guard: deny/unknown holds, never overridable.
            $decision = $this->evaluateGuard($case, (string) $target['serial'], $read);
            if (! $decision->allowed()) {
                return $this->recordGuardHold($case, $actor, $olt, $target, $decision, $op);
            }

            $record = $this->runDelete($case, $olt, $target, $op, $actor);
            if (($record['__session_busy'] ?? false) === true) {
                // Huawei session lock busy before any command: nothing written.
                return $this->busyResult($olt);
            }
            $wa = $record['_wa'] ?? null;
            unset($record['_wa']);

            $this->persist($case, $actor, self::EVENT_TYPE, $record, $this->deleteEventKey($case));

            return ['status' => $record['ok'] ? 'deleted' : 'delete_failed'] + $record + ($wa !== null ? ['_wa' => $wa] : []);
        }, (string) $target['onu_ref']);

        return $locked['busy'] ? $this->busyResult($olt) : $this->flushHoldWa($case, (array) $locked['value']);
    }

    /**
     * Event key of a real delete attempt. The base key olt-onu-delete:{case} is
     * kept for the first attempt (and for an already successful one, where
     * firstOrCreate is a no-op). When an older NON-ok event (legacy skip, failed
     * attempt) already holds the base key, a timestamped key makes sure the new
     * attempt still leaves its own event row.
     */
    private function deleteEventKey(DeviceRecoveryCase $case): string
    {
        $base = 'olt-onu-delete:'.$case->id;
        $existing = DeviceRecoveryEvent::query()->where('idempotency_key', $base)->first();
        if ($existing === null || (($existing->metadata['ok'] ?? false) === true)) {
            return $base;
        }

        return $base.':'.now()->format('YmdHis');
    }

    /** A non-terminal customer references the serial in any form (ASCII/hex GPON, separators). */
    private function liveCustomerUsesSerial(string $serial): bool
    {
        $forms = CustomerLifecycleGuard::serialForms($serial);
        if ($forms === []) {
            return false;
        }

        return Customer::query()
            ->where(function ($q) use ($forms): void {
                foreach ($forms as $form) {
                    $q->orWhereRaw(CustomerLifecycleGuard::serialSql('onu_serial').' = ?', [$form]);
                }
            })
            ->whereNotIn('status', DeviceRecoveryService::RELEASED_CUSTOMER_STATUSES)
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    private function busyResult(Olt $olt): array
    {
        return ['status' => 'olt_busy', 'reason' => 'olt_busy', 'olt_id' => $olt->id, 'ok' => false];
    }

    /**
     * A case whose recorded delete may not really have happened: ok=true
     * (possibly unverified) or a failed verification. Such cases can be
     * re-checked with retryUnverified().
     */
    public function isRetryable(DeviceRecoveryCase $case): bool
    {
        $meta = is_array($case->meta) ? $case->meta : [];
        $prev = (array) ($meta[self::META_KEY] ?? []);
        if (($prev['ok'] ?? false) === true) {
            return true;
        }

        return in_array($prev['reason'] ?? null, ['readback_failed', 'still_present_after_delete', 'delete_failed'], true);
    }

    /**
     * Delete the ONU, then verify with our OWN live read-back (the vendor
     * service's verified_absent is optimistic). A serial that is still present
     * is recorded as still_present_after_delete and the inventory is NOT purged.
     *
     * @param  array<string, mixed>  $target
     * @param  array<string, mixed>  $op
     * @return array<string, mixed>
     */
    private function runDelete(DeviceRecoveryCase $case, Olt $olt, array $target, array $op, ?User $actor = null): array
    {
        $huawei = [];

        try {
            $operatorInitiated = (bool) ($op['operator_run'] ?? false);
            $adapter = app(HuaweiAutoOnuDeleteService::class);
            if ($this->isHuaweiVendor($olt) && $adapter->enabled($olt, $operatorInitiated)) {
                // Service-ports first (operator CLI always; the unattended job
                // only with the owner flag). Session busy = nothing was sent.
                try {
                    $result = $adapter->delete(
                        $olt,
                        (string) $target['onu_ref'],
                        (string) $target['serial'],
                        ($op['retry_unverified'] ?? false) ? HuaweiAutoOnuDeleteService::SOURCE_DEVICE_RECOVERY_RETRY : HuaweiAutoOnuDeleteService::SOURCE_DEVICE_RECOVERY,
                        $actor,
                        (int) $case->id,
                    );
                } catch (HuaweiOltSessionBusyException) {
                    // Only the new path: nothing was sent. The legacy branch keeps
                    // its old behaviour (busy persists as delete_error).
                    return ['__session_busy' => true];
                }
                $huawei = [
                    'huawei_path' => 'service_port',
                    'huawei_error_code' => $result['error_code'] ?? null,
                    'partial' => (bool) ($result['partial'] ?? false),
                    'removed_service_ports' => $result['removed_service_ports'] ?? [],
                    'rebuild_hint' => $result['rebuild_hint'] ?? [],
                    'save_pending' => (bool) ($result['save_pending'] ?? false),
                    'already_absent' => (bool) ($result['already_absent'] ?? false),
                ];
                if (isset($result['_wa'])) {
                    $huawei['_wa'] = $result['_wa'];
                }
            } else {
                $result = $this->isHuaweiVendor($olt)
                    ? app(HuaweiOltOnlyProvisioningService::class)
                        ->deleteOnu($olt, (string) $target['onu_ref'], (string) $target['serial'])
                    : app(OltZteC300Service::class)->deleteOnu($olt, (string) $target['onu_ref']);
            }
            $ok = (bool) ($result['success'] ?? true);
            $reason = $ok ? 'deleted' : 'delete_failed';
            if ($ok) {
                // Our OWN read-back decides: a successful read AND the serial
                // absent (or a different serial). Never trust the vendor result.
                $after = $this->liveRead($olt, (string) $target['onu_ref']);
                if (! $after['ok']) {
                    $ok = false;
                    $reason = 'readback_failed';
                } elseif ($after['serial'] === (string) $target['serial']) {
                    $ok = false;
                    $reason = 'still_present_after_delete';
                }
            }
            $record = [
                'ok' => $ok,
                'reason' => $reason,
                'olt_id' => (int) $target['olt_id'],
                'serial' => (string) $target['serial'],
                'serial_source' => $target['serial_source'] ?? null,
                'onu_ref' => (string) $target['onu_ref'],
                'verified_absent' => $ok,
                'at' => now()->toIso8601String(),
            ] + $op + $huawei;
            // Huawei and legacy (non service-port) records alike keep earlier hints.
            $record = $this->carryPreviousRebuildHints($case, $record);
            if ($ok) {
                $record['inventory_purged'] = $this->purgeInventory($olt, (string) $target['onu_ref'], (string) $target['serial']);
            }
        } catch (\Throwable $e) {
            Log::warning('[device-recovery] ONU delete failed', [
                'case_id' => $case->id,
                'onu_ref' => $target['onu_ref'] ?? null,
                'error' => $e->getMessage(),
            ]);
            $record = [
                'ok' => false,
                'reason' => 'delete_error',
                'olt_id' => (int) $target['olt_id'],
                'serial' => (string) $target['serial'],
                'onu_ref' => (string) $target['onu_ref'],
                'error' => SensitiveDataRedactor::redact($e->getMessage()),
                'at' => now()->toIso8601String(),
            ] + $op;
            // A thrown error must not wipe the hints an earlier run recorded.
            $record = $this->carryPreviousRebuildHints($case, $record);
        }

        return $record;
    }

    /**
     * Keep every earlier rebuild hint (a retry may find fewer or no service-ports
     * left, or the retry itself may fail/throw): accumulate, newest last, capped at 5.
     *
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function carryPreviousRebuildHints(DeviceRecoveryCase $case, array $record): array
    {
        $prev = (array) ((is_array($case->meta) ? $case->meta : [])[self::META_KEY] ?? []);
        $hints = array_values((array) ($prev['previous_rebuild_hints'] ?? []));
        if ((array) ($prev['rebuild_hint'] ?? []) !== []) {
            $hints[] = (array) $prev['rebuild_hint'];
        }
        if ($hints !== []) {
            $record['previous_rebuild_hints'] = array_slice($hints, -5);
        }

        return $record;
    }

    /**
     * Resolve a retry for a case whose meta claims ok=true (possibly an
     * unverified/optimistic delete). Uses the stored meta olt_id/serial/onu_ref
     * (the inventory row was purged) and a live read. Read-only.
     *
     * reason: retry_still_present | already_deleted | live_serial_mismatch |
     * live_verify_failed | serial_in_use | not_previously_deleted | ...
     *
     * @return array<string, mixed>
     */
    public function planRetryUnverified(DeviceRecoveryCase $case): array
    {
        $case = $case->fresh(['customer']) ?? $case;
        $modeWrite = (string) config('device-recovery.mode', 'read_only') === 'write';
        $base = ['ok' => false, 'would_delete' => false, 'live_serial' => null, 'mode_write' => $modeWrite];

        $meta = is_array($case->meta) ? $case->meta : [];
        $prev = (array) ($meta[self::META_KEY] ?? []);
        if (($prev['reason'] ?? null) === 'deleted_verified_on_retry') {
            return ['ok' => true, 'reason' => 'already_deleted'] + array_intersect_key($prev, array_flip(['olt_id', 'serial', 'onu_ref'])) + $base;
        }
        if (! $this->isRetryable($case)) {
            return ['reason' => 'not_previously_deleted'] + $base;
        }

        $serial = (string) ($prev['serial'] ?? '');
        $onuRef = trim((string) ($prev['onu_ref'] ?? ''));
        $oltId = (int) ($prev['olt_id'] ?? 0);
        if ($serial === '' || $onuRef === '' || $oltId <= 0) {
            return ['reason' => 'meta_incomplete'] + $base;
        }
        $target = ['olt_id' => $oltId, 'serial' => $serial, 'onu_ref' => $onuRef, 'serial_source' => $prev['serial_source'] ?? null];

        $olt = Olt::query()->find($oltId);
        if (! $olt) {
            return ['reason' => 'olt_missing'] + $target + $base;
        }
        if (! $this->supportsOnuDelete($olt)) {
            return ['reason' => 'unsupported_vendor'] + $target + $base;
        }

        $read = $this->liveRead($olt, $onuRef);
        if (! $read['ok']) {
            return ['reason' => 'live_verify_failed'] + $target + $base;
        }
        if ($read['absent']) {
            // Strictly proven gone (real %Error 20202 + complete port table
            // without the index): record + purge only, no device write.
            return ['ok' => true, 'reason' => 'absent_verified', 'olt_name' => $olt->name, 'live_state' => 'absent'] + $target + $base;
        }
        if ($read['serial'] === null) {
            return ['reason' => 'live_verify_failed'] + $target + $base;
        }
        if ($read['serial'] !== $serial) {
            return ['reason' => 'live_serial_mismatch', 'live_serial' => $read['serial']] + $target + $base;
        }

        if ($read['state'] !== OnuLiveState::OFFLINE) {
            return [
                'reason' => $read['state'] === OnuLiveState::ONLINE ? 'onu_online' : 'live_state_unknown',
                'live_serial' => $read['serial'],
                'live_state' => $read['state_label'],
            ] + $target + $base;
        }

        // Roadmap 1.3 lifecycle guard (retry is operator-run but still not overridable).
        $decision = $this->evaluateGuard($case, $serial, $read);
        if (! $decision->allowed()) {
            return [
                'reason' => 'guard_hold',
                'live_serial' => $read['serial'],
                'live_state' => $read['state_label'],
                'guard' => $this->guardSummary($decision),
            ] + $target + $base;
        }

        if ($this->liveCustomerUsesSerial($serial)) {
            return ['reason' => 'serial_in_use', 'live_serial' => $read['serial']] + $target + $base;
        }

        return [
            'ok' => true,
            'would_delete' => true,
            'reason' => 'retry_still_present',
            'live_serial' => $read['serial'],
            'olt_name' => $olt->name,
        ] + $target + $base;
    }

    /**
     * Operator-run retry of a delete that was recorded ok=true but is still
     * present live. Requires config mode=write; never throws for a skip.
     *
     * @return array<string, mixed>
     */
    public function retryUnverified(DeviceRecoveryCase $case, ?User $actor = null): array
    {
        $case = $case->fresh(['customer']) ?? $case;
        if ((string) config('device-recovery.mode', 'read_only') !== 'write') {
            return ['status' => 'skipped_mode_not_write', 'reason' => 'mode_not_write'];
        }
        if ($case->status !== DeviceRecoveryService::STATUS_RECEIVED) {
            return ['status' => 'skipped_not_received', 'reason' => 'not_received'];
        }

        // Cheap pre-check without touching the device.
        $prev = (array) ((is_array($case->meta) ? $case->meta : [])[self::META_KEY] ?? []);
        if (($prev['reason'] ?? null) === 'deleted_verified_on_retry') {
            return ['status' => 'already_deleted'] + $prev;
        }
        $oltForLock = Olt::query()->find((int) ($prev['olt_id'] ?? 0));
        if (! $oltForLock) {
            return ['status' => 'skipped_'.($this->isRetryable($case) ? 'olt_missing' : 'not_previously_deleted')];
        }

        $locked = OltOperationLock::run($oltForLock, (int) config('device-recovery.olt_lock_wait_seconds', self::LOCK_WAIT_SECONDS), function () use ($case, $actor, $oltForLock): array {
            $plan = $this->planRetryUnverified($case);
            $op = ['operator_run' => true, 'operator_actor_id' => $actor?->id, 'retry_unverified' => true];
            $key = 'olt-onu-delete-retry:'.$case->id.':'.now()->format('YmdHisu');

            if (($plan['reason'] ?? null) === 'already_deleted') {
                return ['status' => 'already_deleted'] + $plan;
            }
            if (($plan['reason'] ?? null) === 'absent_verified') {
                // The delete did succeed earlier; only the verification failed.
                // No device write: record verified absence and purge the exact
                // inventory row + snapshot entry.
                $record = [
                    'ok' => true,
                    'reason' => 'deleted_verified_on_retry',
                    'olt_id' => (int) $plan['olt_id'],
                    'serial' => (string) $plan['serial'],
                    'serial_source' => $plan['serial_source'] ?? null,
                    'onu_ref' => (string) $plan['onu_ref'],
                    'verified_absent' => true,
                    'inventory_purged' => $this->purgeInventory($oltForLock, (string) $plan['onu_ref'], (string) $plan['serial']),
                    'at' => now()->toIso8601String(),
                ] + $op;
                $record = $this->carryPreviousRebuildHints($case, $record);
                $this->persist($case, $actor, self::EVENT_TYPE, $record, $key);

                return ['status' => 'deleted_verified_on_retry'] + $record;
            }
            if (! ($plan['would_delete'] ?? false)) {
                return ['status' => 'skipped_'.(string) ($plan['reason'] ?? 'unresolved')] + $plan;
            }

            $record = $this->runDelete($case, $oltForLock, $plan, $op, $actor);
            if (($record['__session_busy'] ?? false) === true) {
                return $this->busyResult($oltForLock);
            }
            $wa = $record['_wa'] ?? null;
            unset($record['_wa']);
            $this->persist($case, $actor, self::EVENT_TYPE, $record, $key);

            return ['status' => $record['ok'] ? 'deleted' : 'delete_failed'] + $record + ($wa !== null ? ['_wa' => $wa] : []);
        }, (string) ($prev['onu_ref'] ?? ''));

        return $locked['busy'] ? $this->busyResult($oltForLock) : $this->flushHoldWa($case, (array) $locked['value']);
    }

    /**
     * After a successful device delete, drop the now-stale inventory row and
     * cached GPON snapshot entry (same as the manual OLT "Hapus ONU" action)
     * so migration/PSB preflight does not keep seeing the serial as an
     * existing ONU until the next hourly inventory sync. Exact olt_id +
     * onu_ref + serial match only; never throws.
     *
     * @return array{db: int, snapshot: int}
     */
    private function purgeInventory(Olt $olt, string $onuRef, string $serial): array
    {
        $purged = ['db' => 0, 'snapshot' => 0];

        try {
            $purged['db'] = OltOnu::query()
                ->where('olt_id', $olt->id)
                ->whereRaw('LOWER(onu_ref) = ?', [strtolower(trim($onuRef))])
                ->whereRaw(
                    "UPPER(REPLACE(REPLACE(COALESCE(serial_number, ''), '-', ''), ' ', '')) = ?",
                    [$serial]
                )
                ->delete();
            $purged['snapshot'] = app(OltController::class)->purgeOnuFromSnapshotCaches($olt->fresh() ?? $olt, $onuRef);
        } catch (\Throwable $e) {
            Log::warning('[device-recovery] ONU inventory purge failed', [
                'olt_id' => $olt->id,
                'onu_ref' => $onuRef,
                'error' => SensitiveDataRedactor::redact($e->getMessage()),
            ]);
        }

        return $purged;
    }

    /**
     * Normalized serials captured on the case's received items (the devices
     * actually collected).
     *
     * @return list<string>
     */
    private function collectedSerials(DeviceRecoveryCase $case): array
    {
        return $case->items()
            ->where('status', DeviceRecoveryService::ITEM_RECEIVED)
            ->pluck('captured_serial_number')
            ->map(fn ($serial): ?string => $this->recovery->normalizeSerial($serial))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array{ok: bool, serial: ?string, state: string, state_label: string} ok=false when the read itself failed
     */
    private function liveRead(Olt $olt, string $onuRef): array
    {
        $read = app(OnuLiveReadService::class)->read($olt, $onuRef);

        return [
            'ok' => $read['ok'],
            'absent' => $read['absent'],
            'serial' => $read['serial'],
            'state' => $read['state'],
            'state_label' => $read['label'],
        ];
    }

    private function supportsOnuDelete(Olt $olt): bool
    {
        return $this->isHuaweiVendor($olt) || $this->isZteVendor($olt);
    }

    private function isHuaweiVendor(Olt $olt): bool
    {
        $vendor = strtolower(trim((string) ($olt->vendor ?? '')));

        return str_contains($vendor, 'huawei') || str_contains($vendor, 'ma5608');
    }

    private function isZteVendor(Olt $olt): bool
    {
        $vendor = strtolower(trim((string) ($olt->vendor ?? '')));

        return str_contains($vendor, 'zte') || str_contains($vendor, 'c300');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function recordSkip(DeviceRecoveryCase $case, ?User $actor, string $reason, array $payload = []): array
    {
        $record = [
            'ok' => false,
            'reason' => $reason,
            'at' => now()->toIso8601String(),
        ] + array_filter($payload, static fn ($v): bool => $v !== null && $v !== '');
        $record = $this->carryPreviousRebuildHints($case, $record);

        // Own daily key per reason: a skip must never occupy the key of the real
        // delete event (olt-onu-delete:{case}), or a later delete would leave no event row.
        $this->persist($case, $actor, self::EVENT_TYPE, $record, 'olt-onu-delete-skip:'.$case->id.':'.$reason.':'.now()->format('Ymd'));

        return ['status' => 'skipped_'.$reason] + $record;
    }

    /**
     * Append-only audit: store the outcome on the case meta and as an event.
     *
     * @param  array<string, mixed>  $record
     */
    private function persist(DeviceRecoveryCase $case, ?User $actor, string $eventType, array $record, ?string $idempotencyKey = null): void
    {
        $meta = is_array($case->meta) ? $case->meta : [];
        $meta[self::META_KEY] = $record;
        $case->forceFill(['meta' => $meta])->save();

        DeviceRecoveryEvent::query()->firstOrCreate(
            ['idempotency_key' => $idempotencyKey ?? 'olt-onu-delete:'.$case->id],
            [
                'recovery_case_id' => $case->id,
                'item_id' => null,
                'actor_id' => $actor?->id,
                'event_type' => $eventType,
                'from_status' => $case->status,
                'to_status' => $case->status,
                'reason' => $record['reason'] ?? null,
                'source' => 'system',
                'metadata' => $record,
            ],
        );
    }
}
