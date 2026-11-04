<?php

namespace App\Console\Commands;

use App\Exceptions\HuaweiOltSessionBusyException;
use App\Http\Controllers\OltController;
use App\Models\Customer;
use App\Models\Olt;
use App\Models\OltOnu;
use App\Models\User;
use App\Services\DeviceRecoveryService;
use App\Services\HuaweiAutoOnuDeleteService;
use App\Services\HuaweiOltOnlyProvisioningService;
use App\Services\Lifecycle\CustomerLifecycleGuard;
use App\Services\Lifecycle\GuardContext;
use App\Services\OltZteC300Service;
use App\Services\OnuLiveReadService;
use App\Support\OltBusyException;
use App\Support\OltOperationLock;
use App\Support\OnuLiveState;
use App\Support\SensitiveDataRedactor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Operator-run cleanup of stale OFFLINE duplicate ONU registrations.
 *
 * A reused ONU that was re-registered at a new position leaves the same serial
 * twice in the OLT: the old position is offline, the new one is working. Only
 * the offline one may be deleted, and only when a LIVE read of every ref proves
 * it. Default is a dry run (no device write, no DB write).
 *
 * Every delete is followed by this command's OWN live read-back; the service's
 * `verified_absent` is deliberately ignored (it is optimistic).
 */
class OltDeleteDuplicateOnus extends Command
{
    /** Seconds to wait for the per-OLT lock before giving up. */
    private const LOCK_WAIT_SECONDS = 60;

    protected $signature = 'olt:delete-duplicate-onus
        {--olt=* : OLT ids (required)}
        {--serial=* : Optional subset of serials}
        {--execute : Actually delete (default: dry run)}
        {--actor= : User id for the audit trail (required with --execute)}
        {--sleep=2 : Seconds to wait between OLT writes with --execute}';

    protected $description = 'Dry-run (default) or delete stale OFFLINE duplicate ONU registrations of the same serial on ZTE/Huawei OLTs';

    public function handle(): int
    {
        $oltIds = collect((array) $this->option('olt'))
            ->flatMap(fn ($v) => preg_split('/[\s,]+/', (string) $v, -1, PREG_SPLIT_NO_EMPTY))
            ->map(fn ($v) => (int) $v)
            ->filter(fn (int $v) => $v > 0)
            ->unique()
            ->values();
        if ($oltIds->isEmpty()) {
            $this->error('At least one --olt=<id> is required.');

            return self::FAILURE;
        }

        $subset = collect((array) $this->option('serial'))
            ->flatMap(fn ($v) => preg_split('/[\s,]+/', (string) $v, -1, PREG_SPLIT_NO_EMPTY))
            ->map(fn ($v) => $this->normalizeSerial((string) $v))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $execute = (bool) $this->option('execute');
        $actor = null;
        if ($execute) {
            $actorId = (int) $this->option('actor');
            $actor = $actorId > 0 ? User::query()->find($actorId) : null;
            if (! $actor) {
                $this->error('--actor=<user id> (existing user) is required with --execute.');

                return self::FAILURE;
            }
            if ((string) config('device-recovery.mode', 'read_only') !== 'write') {
                $this->error('device-recovery.mode is not "write"; refusing to execute.');

                return self::FAILURE;
            }
        }

        $olts = [];
        foreach ($oltIds as $id) {
            $olt = Olt::query()->find($id);
            if (! $olt) {
                $this->error("OLT {$id} not found.");

                return self::FAILURE;
            }
            if (! $this->isZte($olt) && ! $this->isHuawei($olt)) {
                $this->error(sprintf('OLT %d (%s) vendor "%s" is unsupported; only ZTE C300 / Huawei.', $id, $olt->name, (string) $olt->vendor));

                return self::FAILURE;
            }
            $olts[] = $olt;
        }
        if (! Schema::hasTable('olt_onus')) {
            $this->error('olt_onus inventory table is unavailable.');

            return self::FAILURE;
        }

        $failed = 0;
        $deleted = 0;
        $tableRows = [];
        $sleep = max(0, (int) $this->option('sleep'));
        $wrote = false;

        foreach ($olts as $olt) {
            foreach ($this->duplicateSerials($olt, $subset) as $serial => $refs) {
                if (! $execute) {
                    // Dry run: no lock, no write.
                    $plan = $this->plan($olt, $serial, $refs);
                    foreach ($plan['rows'] as $r) {
                        $reason = $r['reason'];
                        if ($r['action'] === 'delete' && $this->isHuawei($olt)) {
                            // Read-only preview (one `display service-port`, no write).
                            $preview = app(HuaweiAutoOnuDeleteService::class)->preview($olt, $r['ref']);
                            $reason .= $preview['ok']
                                ? sprintf(' (service_ports=%d)', $preview['count'])
                                : ' (service_ports=unknown)';
                        }
                        $tableRows[] = [$serial, $r['ref'], $r['live_serial'] ?? '-', $r['live_state'], $r['action'], $reason, $plan['customers']];
                    }

                    continue;
                }

                // One delete at a time per OLT, and never while a PSB / migration
                // run is active (probed). The lock is taken PER operation inside
                // executeGroup, never across a whole group.
                try {
                    $this->executeGroup($olt, $serial, $refs, $actor, $sleep, $tableRows, $deleted, $failed, $wrote);
                } catch (OltBusyException) {
                    $this->error(sprintf('OLT %d is busy (another ONU delete, or an active PSB/migration run); stopping, nothing further written. Re-run later.', $olt->id));
                    $this->table(['serial', 'ref', 'live_state', 'action', 'result', 'detail'], $tableRows);
                    $this->info(sprintf('EXECUTE actor=%d deleted=%d delete_failed=%d olt_busy=1', $actor->id, $deleted, $failed));

                    return self::FAILURE;
                }
            }
        }

        if (! $execute) {
            $this->table(['serial', 'ref', 'live_serial', 'live_state', 'action', 'reason', 'customer(s)'], $tableRows);
            $deletable = count(array_filter($tableRows, fn (array $r): bool => $r[4] === 'delete'));
            $huaweiPath = '';
            foreach ($olts as $olt) {
                if ($this->isHuawei($olt)) {
                    $huaweiPath .= sprintf(' huawei_path[olt %d]=%s', $olt->id, app(HuaweiAutoOnuDeleteService::class)->enabled($olt, true) ? 'service_port' : 'legacy (device-recovery.mode not write)');
                }
            }
            $this->info(sprintf('DRY RUN (nothing written): rows=%d would_delete=%d%s', count($tableRows), $deletable, $huaweiPath));

            return self::SUCCESS;
        }

        $this->table(['serial', 'ref', 'live_state', 'action', 'result', 'detail'], $tableRows);
        $this->info(sprintf('EXECUTE actor=%d deleted=%d delete_failed=%d', $actor->id, $deleted, $failed));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Run $fn under the OLT delete lock (PSB/migration probed, not held).
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     *
     * @throws OltBusyException
     */
    private function guarded(Olt $olt, string $ref, callable $fn): mixed
    {
        $locked = OltOperationLock::run($olt, (int) config('device-recovery.olt_lock_wait_seconds', self::LOCK_WAIT_SECONDS), $fn, $ref);
        if ($locked['busy']) {
            throw new OltBusyException;
        }

        return $locked['value'];
    }

    /**
     * Execute one serial group; the lock is taken per operation, not per group.
     *
     * @param  list<string>  $refs
     * @param  list<array<int, mixed>>  $tableRows
     */
    private function executeGroup(Olt $olt, string $serial, array $refs, User $actor, int $sleep, array &$tableRows, int &$deleted, int &$failed, bool &$wrote): void
    {
        // Plan = live reads of every ref, taken under the delete lock.
        $plan = $this->guarded($olt, $refs[0], fn (): array => $this->plan($olt, $serial, $refs));

        foreach ($plan['rows'] as $r) {
            if ($r['action'] === 'purge') {
                // Stale inventory row of an ONU that is clearly gone on the OLT:
                // re-confirm under the lock right before the (DB-only) purge.
                $stillAbsent = $this->guarded($olt, $r['ref'], fn (): bool => $this->liveRead($olt, $r['ref'])['absent']);
                if (! $stillAbsent) {
                    $tableRows[] = [$serial, $r['ref'], $r['live_state'], 'skip', 'recheck_absent_changed', '-'];

                    continue;
                }
                $this->purgeInventory($olt, $r['ref'], $serial);
                $tableRows[] = [$serial, $r['ref'], $r['live_state'], 'purge', 'purged_stale_inventory', '-'];
                $this->logAction($actor, $olt, $serial, $r['ref'], 'purged_stale_inventory');

                continue;
            }
            if ($r['action'] !== 'delete') {
                $tableRows[] = [$serial, $r['ref'], $r['live_state'], $r['action'], $r['reason'], '-'];
                if ($r['action'] === 'skip') {
                    $this->logAction($actor, $olt, $serial, $r['ref'], 'skipped:'.$r['reason']);
                }

                continue;
            }

            if ($wrote && $sleep > 0) {
                sleep($sleep);
            }
            // Lock PER DELETE (re-probing PSB/migration each time), with fresh
            // proof inside it: target still offline with the same serial,
            // working ref still online with the same serial.
            $outcome = $this->guarded($olt, $r['ref'], function () use ($olt, $serial, $r, $plan, $actor): array {
                $recheck = $this->recheck($olt, $serial, $r['ref'], (string) $plan['working_ref']);
                if ($recheck !== null) {
                    return ['recheck' => $recheck];
                }

                return $this->deleteOne($olt, $serial, $r['ref'], $actor);
            });
            if (isset($outcome['recheck'])) {
                $tableRows[] = [$serial, $r['ref'], $r['live_state'], 'skip', 'recheck_'.$outcome['recheck'], '-'];
                $this->logAction($actor, $olt, $serial, $r['ref'], 'skipped:recheck_'.$outcome['recheck']);

                continue;
            }
            $wrote = true;
            // Partial-failure ops WA goes out only now, after the lock is released.
            HuaweiAutoOnuDeleteService::sendAlert($outcome['_wa'] ?? null);
            $detail = $outcome['verified_absent'] ? 'verified_absent=yes' : 'verified_absent=no';
            $extra = ['verified_absent' => $outcome['verified_absent']];
            if (isset($outcome['rebuild_hint']) || isset($outcome['error_code'])) {
                if (($outcome['error_code'] ?? null) !== null) {
                    $detail .= ' '.$outcome['error_code'];
                }
                if (($outcome['partial'] ?? false) === true) {
                    $detail .= ', partial: rebuild_hint logged';
                }
                $extra += [
                    'error_code' => $outcome['error_code'] ?? null,
                    'partial' => (bool) ($outcome['partial'] ?? false),
                    'removed_service_ports' => $outcome['removed_service_ports'] ?? [],
                    'rebuild_hint' => $outcome['rebuild_hint'] ?? [],
                ];
            }
            $tableRows[] = [$serial, $r['ref'], $r['live_state'], 'delete', $outcome['result'], $detail];
            $this->logAction($actor, $olt, $serial, $r['ref'], $outcome['result'], $extra);
            if ($outcome['result'] === 'deleted') {
                $deleted++;
            } else {
                $failed++;
            }
        }
    }

    /**
     * @param  list<string>  $subset
     * @return array<string, list<string>> normalized serial => refs
     */
    private function duplicateSerials(Olt $olt, array $subset): array
    {
        $groups = [];
        foreach (OltOnu::query()->where('olt_id', $olt->id)->orderBy('id')->get(['onu_ref', 'serial_number']) as $row) {
            $serial = $this->normalizeSerial((string) $row->serial_number);
            $ref = trim((string) $row->onu_ref);
            if ($serial === '' || $ref === '') {
                continue;
            }
            $groups[$serial][strtolower($ref)] = $ref;
        }

        $out = [];
        foreach ($groups as $serial => $refs) {
            if (count($refs) > 1 && ($subset === [] || in_array($serial, $subset, true))) {
                $out[$serial] = array_values($refs);
            }
        }
        ksort($out);

        return $out;
    }

    /**
     * Live-read every ref and decide. Never writes.
     *
     * @param  list<string>  $refs
     * @return array{rows: list<array<string, mixed>>, customers: string, eligible: bool}
     */
    private function plan(Olt $olt, string $serial, array $refs): array
    {
        $reads = [];
        foreach ($refs as $ref) {
            $reads[$ref] = $this->liveRead($olt, $ref);
        }

        $customers = $this->customersFor($serial);
        $customerLabel = $customers->isEmpty() ? '-' : $customers->map(fn (Customer $c): string => trim(($c->code ?? '').' '.($c->name ?? '')))->implode('; ');

        // Refs that are CLEARLY gone on the OLT (strict probe) are stale
        // inventory rows: they are purged (execute only) and do not take part
        // in the duplicate rules below.
        $absent = array_keys(array_filter($reads, fn (array $r): bool => $r['absent']));
        $liveReads = array_diff_key($reads, array_flip($absent));

        $reason = null;
        $working = [];
        $offline = [];
        foreach ($liveReads as $ref => $read) {
            if ($read['error'] !== null) {
                $reason ??= 'live_read_failed';
            } elseif ($read['serial'] !== $serial) {
                $reason ??= 'live_serial_mismatch';
            } elseif ($read['class'] === 'online') {
                $working[] = $ref;
            } elseif ($read['class'] === 'offline') {
                $offline[] = $ref;
            } else {
                $reason ??= 'unknown_state';
            }
        }

        if ($reason === null && count($liveReads) < 2) {
            $reason = 'no_duplicate_left';
        }
        if ($reason === null) {
            if (count($working) === 0) {
                $reason = 'no_working_ref';
            } elseif (count($working) > 1) {
                $reason = 'multiple_working_refs';
            } elseif ($offline === []) {
                $reason = 'no_offline_ref';
            }
        }

        if ($reason === null) {
            $reason = $this->customerRefMismatch($olt, $customers, $working[0]);
        }

        $eligible = $reason === null;
        $rows = [];
        foreach ($reads as $ref => $read) {
            if ($read['absent']) {
                $rows[] = [
                    'ref' => $ref,
                    'live_serial' => null,
                    'live_state' => 'absent',
                    'action' => 'purge',
                    'reason' => 'stale_inventory_absent',
                ];

                continue;
            }
            $isWorking = in_array($ref, $working, true);
            $rows[] = [
                'ref' => $ref,
                'live_serial' => $read['serial'],
                'live_state' => $read['error'] !== null ? 'error' : (string) $read['label'],
                'action' => $reason === 'no_duplicate_left' ? 'keep' : (! $eligible ? 'skip' : ($isWorking ? 'keep' : 'delete')),
                'reason' => $eligible ? ($isWorking ? 'working_ref' : 'stale_offline_duplicate') : $reason,
            ];
        }

        return ['rows' => $rows, 'customers' => $customerLabel, 'eligible' => $eligible, 'working_ref' => $working[0] ?? ''];
    }

    /**
     * Returns null when still safe, else a short reason.
     */
    private function recheck(Olt $olt, string $serial, string $ref, string $workingRef): ?string
    {
        $target = $this->liveRead($olt, $ref);
        if ($target['error'] !== null || $target['serial'] !== $serial) {
            return 'target_changed';
        }
        if ($target['class'] !== OnuLiveState::OFFLINE) {
            return 'target_not_offline';
        }

        $working = $this->liveRead($olt, $workingRef);
        if ($workingRef === '' || $working['error'] !== null || $working['serial'] !== $serial) {
            return 'working_changed';
        }
        if ($working['class'] !== OnuLiveState::ONLINE) {
            return 'working_not_online';
        }

        // Roadmap 1.3 per-ref rule (duplicate mode): target ref OFFLINE + working
        // twin ONLINE. No customer PPPoE/traffic check: the customer legitimately
        // uses the working twin. Same verdict as above, via the shared guard.
        $decision = app(CustomerLifecycleGuard::class)->canDeleteOnuRef(
            CustomerLifecycleGuard::MODE_DUPLICATE,
            null,
            $target,
            GuardContext::automated('olt_delete_duplicate_onus'),
            $working,
        );
        if (! $decision->allowed()) {
            return 'guard_hold';
        }

        return null;
    }

    /**
     * Stored customer position (olt_port_label) must equal the working ref.
     * Labels without an ONU id (port only) or on another OLT carry no position.
     */
    private function customerRefMismatch(Olt $olt, $customers, string $workingRef): ?string
    {
        $want = $this->normalizeRef($workingRef);
        foreach ($customers as $customer) {
            if ($customer->olt_id !== null && (int) $customer->olt_id !== (int) $olt->id) {
                continue;
            }
            $stored = $this->normalizeRef((string) ($customer->olt_port_label ?? ''));
            if ($stored === '' || ! preg_match('/^\d+\/\d+\/\d+:\d+$/', $stored)) {
                continue;
            }
            if ($stored !== $want) {
                return 'customer_ref_mismatch';
            }
        }

        return null;
    }

    private function customersFor(string $serial)
    {
        // Live customers only: terminated/dismantled former owners neither
        // block nor allow a delete (soft-deleted rows are excluded by default).
        return Customer::query()
            ->whereNotIn('status', DeviceRecoveryService::RELEASED_CUSTOMER_STATUSES)
            ->whereRaw("UPPER(REPLACE(REPLACE(COALESCE(onu_serial, ''), '-', ''), ' ', '')) = ?", [$serial])
            ->get();
    }

    /**
     * @return array{ok: bool, state: string, serial: ?string, class: string, label: string, error: ?string, absent: bool}
     */
    private function liveRead(Olt $olt, string $ref): array
    {
        $read = app(OnuLiveReadService::class)->read($olt, $ref);
        if (! $read['ok']) {
            return ['ok' => false, 'state' => OnuLiveState::UNKNOWN, 'serial' => null, 'class' => OnuLiveState::UNKNOWN, 'label' => 'error', 'error' => 'live_read_failed', 'absent' => false];
        }

        return [
            'ok' => true,
            'state' => $read['state'],
            'absent' => $read['absent'],
            'serial' => $read['serial'],
            'class' => $read['state'],
            'label' => $read['absent'] ? 'absent' : $read['label'],
            'error' => null,
        ];
    }

    /**
     * Delete one offline ref, then verify with our own live read-back.
     *
     * @return array<string, mixed> result, verified_absent (+ Huawei service-port detail)
     */
    private function deleteOne(Olt $olt, string $serial, string $ref, ?User $actor = null): array
    {
        $huawei = [];

        try {
            // Operator --execute run: Huawei always uses the service-port
            // sequence (no flag needed); ZTE and everything else unchanged.
            $adapter = app(HuaweiAutoOnuDeleteService::class);
            if ($this->isHuawei($olt) && $adapter->enabled($olt, true)) {
                $result = $adapter->delete($olt, $ref, $serial, HuaweiAutoOnuDeleteService::SOURCE_DUPLICATE_CLEANUP, $actor);
                $huawei = [
                    'error_code' => $result['error_code'] ?? null,
                    'partial' => (bool) ($result['partial'] ?? false),
                    'removed_service_ports' => $result['removed_service_ports'] ?? [],
                    'rebuild_hint' => $result['rebuild_hint'] ?? [],
                    '_wa' => $result['_wa'] ?? null,
                ];
            } else {
                $result = $this->isHuawei($olt)
                    ? app(HuaweiOltOnlyProvisioningService::class)->deleteOnu($olt, $ref, $serial)
                    : app(OltZteC300Service::class)->deleteOnu($olt, $ref);
            }
            if (! (bool) ($result['success'] ?? true)) {
                // Still read back below: the device may have deleted anyway.
                Log::warning('[olt-duplicate-onu] delete reported failure', ['olt_id' => $olt->id, 'onu_ref' => $ref]);
            }
        } catch (HuaweiOltSessionBusyException) {
            // Raised before any command: nothing written, stop the run.
            throw new OltBusyException;
        } catch (\Throwable $e) {
            Log::warning('[olt-duplicate-onu] delete threw', [
                'olt_id' => $olt->id,
                'onu_ref' => $ref,
                'error' => SensitiveDataRedactor::redact($e->getMessage()),
            ]);
        }

        $back = $this->liveRead($olt, $ref);
        $absent = $back['error'] === null && $back['serial'] !== $serial;
        if (! $absent) {
            return ['result' => 'delete_failed', 'verified_absent' => false] + $huawei;
        }

        $this->purgeInventory($olt, $ref, $serial);

        return ['result' => 'deleted', 'verified_absent' => true] + $huawei;
    }

    private function purgeInventory(Olt $olt, string $ref, string $serial): void
    {
        try {
            OltOnu::query()
                ->where('olt_id', $olt->id)
                ->whereRaw('LOWER(onu_ref) = ?', [strtolower(trim($ref))])
                ->whereRaw("UPPER(REPLACE(REPLACE(COALESCE(serial_number, ''), '-', ''), ' ', '')) = ?", [$serial])
                ->delete();
            app(OltController::class)->purgeOnuFromSnapshotCaches($olt->fresh() ?? $olt, $ref);
        } catch (\Throwable $e) {
            Log::warning('[olt-duplicate-onu] inventory purge failed', [
                'olt_id' => $olt->id,
                'onu_ref' => $ref,
                'error' => SensitiveDataRedactor::redact($e->getMessage()),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function logAction(?User $actor, Olt $olt, string $serial, string $ref, string $result, array $extra = []): void
    {
        Log::info('[olt-duplicate-onu] action', [
            'actor_id' => $actor?->id,
            'olt_id' => $olt->id,
            'serial' => $serial,
            'onu_ref' => $ref,
            'result' => $result,
        ] + $extra);
    }

    private function normalizeSerial(string $serial): string
    {
        return strtoupper(str_replace(['-', ' '], '', trim($serial)));
    }

    private function normalizeRef(string $ref): string
    {
        $ref = strtolower(trim($ref));

        return preg_replace('/^gpon-onu_/i', '', $ref) ?? $ref;
    }

    private function isHuawei(Olt $olt): bool
    {
        $vendor = strtolower(trim((string) ($olt->vendor ?? '')));

        return str_contains($vendor, 'huawei') || str_contains($vendor, 'ma5608');
    }

    private function isZte(Olt $olt): bool
    {
        $vendor = strtolower(trim((string) ($olt->vendor ?? '')));

        return str_contains($vendor, 'zte') || str_contains($vendor, 'c300');
    }
}
