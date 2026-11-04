<?php

namespace App\Services\Migration;

use App\Exceptions\HuaweiOltCommandException;
use App\Models\CustomerMigration;
use App\Models\DeviceActionLog;
use App\Models\User;
use App\Services\HuaweiOltOnlyProvisioningService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Device-action ledger reads and writes of the migration flow (idempotency keys, revert,
 * failure close, supersede, lookups), moved out of CustomerMigrationService without a
 * change of behaviour. Stateless: it holds nothing and takes no lock. The facade keeps
 * every lock and the executing marker; callers hold `customer-migration-device-{id}`.
 */
final class MigrationDeviceLedger
{
    /**
     * Execute guard, before any device call: the approval must name a ledger, and that
     * ledger must not be cancelled or belong to another migration. Throws; nothing is changed.
     *
     * @param  array<string, mixed>  $approval
     */
    public function assertApprovalLedgerUsable(CustomerMigration $migration, array $approval): void
    {
        if (empty($approval['ledger_id'])) {
            throw new \RuntimeException('Buat dry-run/approval dulu sebelum execute.');
        }
        $ledger = DeviceActionLog::query()->find((int) $approval['ledger_id']);
        if ($ledger && ($ledger->status === 'cancelled'
                || ($ledger->target_type === 'customer_migration' && (string) $ledger->target_id !== (string) $migration->id))) {
            Log::warning('Migration execute refused: approval ledger is not usable', [
                'migration_id' => $migration->id,
                'ledger_id' => $ledger->id,
                'ledger_status' => $ledger->status,
                'ledger_target_id' => $ledger->target_id,
            ]);
            throw new \RuntimeException('Dry-run sudah dibatalkan atau bukan untuk migrasi ini. Konfirmasi ulang agar dry-run baru dibuat.');
        }
    }

    /** Restore an `executing` ledger to `dry_run` after a busy lock (no device write happened). */
    public function revertLedgerToDryRun(int $ledgerId): void
    {
        try {
            $ledger = DeviceActionLog::query()->find($ledgerId);
            if ($ledger && $ledger->status === 'executing') {
                $ledger->status = 'dry_run';
                $ledger->executed_at = null;
                $ledger->save();
            }
        } catch (\Throwable $e) {
            Log::critical('Migration ledger revert after busy lock failed', [
                'ledger_id' => $ledgerId,
                'exception_class' => $e::class,
            ]);
        }
    }

    /** @param array<string, mixed> $approval */
    public function markDeviceActionExecutionFailed(CustomerMigration $migration, array $approval, ?User $actor, \Throwable $exception): void
    {
        try {
            $ledger = DeviceActionLog::query()->find((int) ($approval['ledger_id'] ?? 0));
            if (! $ledger || $ledger->status !== 'executing') {
                return;
            }

            $failedAt = now();
            $failureContext = $exception instanceof HuaweiOltCommandException ? [
                'failure_type' => $exception->failureType,
                'failure_stage' => $exception->stage,
                'command_index' => $exception->commandIndex,
                'command_label' => $exception->commandLabel,
                'cause_class' => $exception->causeClass,
            ] : [];
            $ledger->status = 'failed';
            $ledger->verified_at = null;
            $ledger->result = array_merge((array) $ledger->result, [
                'failure_code' => 'device_action_execution_failed',
                'exception_class' => class_basename($exception),
                'device_write' => 'unknown',
                'manual_reconciliation_required' => true,
                'actor_id' => $actor?->id,
                'failed_at' => $failedAt->toIso8601String(),
            ], $failureContext);
            $ledger->save();

            $migration = $migration->fresh() ?? $migration;
            $meta = MigrationNormalizer::meta($migration);
            $currentApproval = (array) ($meta['device_action_approval'] ?? $approval);
            $meta['device_action_approval'] = array_merge($currentApproval, [
                'status' => 'execution_failed',
                'failure_code' => 'device_action_execution_failed',
                'manual_reconciliation_required' => true,
                'failed_at' => $failedAt->toIso8601String(),
            ], $failureContext);
            $meta['timeline'] = MigrationNormalizer::appendTimeline($meta['timeline'] ?? [], 'device_action_execution_failed', [
                'ledger_id' => $ledger->id,
                'exception_class' => class_basename($exception),
                'manual_reconciliation_required' => true,
                ...$failureContext,
            ]);
            $migration->meta = $meta;
            $migration->last_error = $exception instanceof HuaweiOltCommandException
                ? MigrationNormalizer::boundedError($exception).' Status device tidak dapat dipastikan; lakukan rekonsiliasi read-only sebelum dry-run ulang.'
                : 'Eksekusi device action gagal; status perangkat tidak dapat dipastikan. Lakukan rekonsiliasi read-only sebelum dry-run ulang.';
            $migration->save();

            Log::error('Device action execution failed and was closed safely', [
                'migration_id' => $migration->id,
                'ledger_id' => $ledger->id,
                'exception_class' => $exception::class,
            ]);
        } catch (\Throwable $stateException) {
            Log::critical('Device action failure state could not be persisted', [
                'migration_id' => $migration->id,
                'ledger_id' => (int) ($approval['ledger_id'] ?? 0),
                'exception_class' => $stateException::class,
            ]);
        }
    }

    public function migrationDeviceIdempotencyKey(CustomerMigration $migration): string
    {
        if (MigrationNormalizer::isHuaweiOltOnlyMigration($migration)) {
            return implode(':', [
                'migration-device',
                $migration->id,
                MigrationNormalizer::normalizeSerial((string) $migration->new_sn) ?: 'new',
                HuaweiOltOnlyProvisioningService::MODE,
                'v1',
            ]);
        }

        return implode(':', [
            'migration-device',
            $migration->id,
            MigrationNormalizer::normalizeSerial((string) $migration->old_sn) ?: 'old',
            MigrationNormalizer::normalizeSerial((string) $migration->new_sn) ?: 'new',
            $migration->new_onu_ref ?: 'onu',
            'v1',
        ]);
    }

    public function hasReusableDeviceActionDryRun(CustomerMigration $migration): bool
    {
        $approval = (array) data_get(MigrationNormalizer::meta($migration), 'device_action_approval', []);
        if (($approval['status'] ?? null) !== 'dry_run_ready' || empty($approval['ledger_id'])) {
            return false;
        }

        $ledger = DeviceActionLog::query()->find((int) $approval['ledger_id']);
        if (! $ledger || $ledger->action !== 'migration-device-action-dry-run') {
            return false;
        }

        $baseKey = $this->migrationDeviceIdempotencyKey($migration);

        return in_array($ledger->status, ['dry_run', 'planned'], true)
            && ($ledger->idempotency_key === $baseKey
                || str_starts_with((string) $ledger->idempotency_key, $baseKey.':retry:'));
    }

    public function nextMigrationDeviceIdempotencyKey(CustomerMigration $migration): string
    {
        $baseKey = $this->migrationDeviceIdempotencyKey($migration);
        $latest = DeviceActionLog::query()
            ->where('target_type', 'customer_migration')
            ->where('target_id', (string) $migration->id)
            ->where('action', 'migration-device-action-dry-run')
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->first(fn (DeviceActionLog $ledger): bool => $ledger->idempotency_key === $baseKey
                || str_starts_with((string) $ledger->idempotency_key, $baseKey.':retry:'));

        if (! $latest || in_array($latest->status, ['requested', 'dry_run', 'planned'], true)) {
            return $latest?->idempotency_key ?: $baseKey;
        }
        if ($latest->status === 'executing') {
            throw new \RuntimeException("Migrasi #{$migration->id} sedang dieksekusi.");
        }
        if ($latest->status === 'verified') {
            throw new \RuntimeException("Migrasi #{$migration->id} sudah memiliki ledger verified.");
        }

        return $baseKey.':retry:'.$latest->id;
    }

    /**
     * Direct marker of ANY device write on a (fresh) record, independent of
     * partialExecutionState() (which is null for synced / Huawei-only). When
     * true the ledger must never be reverted to dry_run.
     */
    public function deviceWriteMarkerPresent(CustomerMigration $migration): bool
    {
        $meta = MigrationNormalizer::meta($migration);

        return data_get($meta, 'mikrotik_migration.ok') === true
            || data_get($meta, 'mikrotik_migration.target_prepared') === true
            || $migration->olt_at !== null
            || filled($meta['olt_provisioned_at'] ?? null)
            || data_get($meta, 'genieacs_tag.success') === true;
    }

    /**
     * Cancel dry-run ledgers that never executed (DB only). Must run inside the caller's transaction.
     *
     * @return int|null id of the newest cancelled ledger
     */
    public function supersedeUnusedDryRunLedgers(CustomerMigration $row, string $cancelledReason, string $runId, Carbon $now): ?int
    {
        $supersededId = null;
        $unused = DeviceActionLog::query()
            ->where('target_type', 'customer_migration')
            ->where('target_id', (string) $row->id)
            ->where('action', 'migration-device-action-dry-run')
            ->whereIn('status', ['requested', 'dry_run', 'planned', 'blocked'])
            ->whereNull('executed_at')
            ->lockForUpdate()
            ->get();
        foreach ($unused as $ledger) {
            $ledger->status = 'cancelled';
            $ledger->result = array_merge((array) $ledger->result, [
                'cancelled_reason' => $cancelledReason,
                'device_write' => false,
                'customer_write' => false,
                'run_id' => $runId,
                'cancelled_at' => $now->toIso8601String(),
            ]);
            $ledger->save();
            $supersededId = max((int) $supersededId, (int) $ledger->id) ?: null;
        }

        return $supersededId;
    }

    public function latestMigrationDeviceLedger(CustomerMigration $migration, array $statuses = []): ?DeviceActionLog
    {
        if (! $migration->id) {
            return null;
        }

        return DeviceActionLog::query()
            ->where('target_type', 'customer_migration')
            ->where('target_id', (string) $migration->id)
            ->where('action', 'migration-device-action-dry-run')
            ->when($statuses !== [], fn ($query) => $query->whereIn('status', $statuses))
            ->orderByDesc('id')
            ->first();
    }

    public function hasExecutedDeviceLedger(CustomerMigration $migration): bool
    {
        return $this->latestMigrationDeviceLedger($migration, ['executed', 'verified']) !== null;
    }

    public function approvalLedger(CustomerMigration $migration): ?DeviceActionLog
    {
        $ledgerId = (int) data_get(MigrationNormalizer::meta($migration), 'device_action_approval.ledger_id', 0);

        return $ledgerId > 0 ? DeviceActionLog::query()->find($ledgerId) : null;
    }

    /** @return array<string, mixed> */
    public function executedStepSummary(CustomerMigration $migration): array
    {
        $meta = MigrationNormalizer::meta($migration);

        return [
            'mikrotik' => data_get($meta, 'mikrotik_migration.ok') === true ? 'verified' : 'not_done',
            'olt' => $migration->olt_at ? 'verified' : 'not_done',
            'genieacs' => data_get($meta, 'genieacs_tag.success') === true ? 'verified' : 'not_done',
            'customer_sync' => MigrationNormalizer::migrationAlreadySynced($migration) ? 'verified' : 'not_done',
        ];
    }
}
