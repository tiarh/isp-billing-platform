<?php

namespace App\Services\Migration;

use App\Models\Customer;
use App\Models\CustomerMigration;
use App\Models\DeviceActionLog;
use App\Models\Olt;
use App\Models\OltOnu;
use App\Models\Router;
use App\Models\User;
use App\Services\CustomerMigrationService;
use Illuminate\Support\Facades\Schema;

/**
 * Partial execution safety (#1102), moved out of CustomerMigrationService without a change
 * of behaviour.
 *
 * A migration whose approved device action already wrote to a device (the
 * MikroTik secret moved and the source disabled, or the OLT step done) but
 * is not synced must never look like a fresh draft again: review/retry may
 * not downgrade it, cancel needs an explicit force + rollback hint, and a
 * second execute continues only the remaining steps.
 *
 * Takes no lock: callers hold `customer-migration-device-{id}`. The facade is reached for
 * `reply` only; every other facade dependency comes through the seams at call time.
 */
final class MigrationPartialExecution
{
    public function __construct(
        private CustomerMigrationService $host,
        private MigrationSeams $seams,
        private MigrationDeviceLedger $ledger,
        private CustomerMigrationWaMessages $texts,
    ) {}

    public function mikrotikStepAlreadyVerified(CustomerMigration $migration, Router $router): bool
    {
        $mikrotik = (array) data_get(MigrationNormalizer::meta($migration), 'mikrotik_migration', []);
        if (($mikrotik['ok'] ?? false) !== true || MigrationNormalizer::migrationAlreadySynced($migration)) {
            return false;
        }
        if ((int) data_get($mikrotik, 'target_router.id', 0) !== (int) $router->id) {
            return false;
        }
        $newUsername = MigrationNormalizer::normalizeUsername((string) ($mikrotik['new_username'] ?? ''));

        return $newUsername !== '' && $newUsername === MigrationNormalizer::normalizeUsername((string) $migration->pppoe_user);
    }

    /**
     * Huawei cross-router run: decides, BEFORE the executing marker, whether the PPPoE copy is still to be done.
     * When the copy was already verified (same rule as the C300 continuation) the target secret is only re-read
     * and the copy is skipped. A target secret that is gone or cannot be read stops the run with nothing written.
     *
     * @param  array<string, mixed>  $before  the preflight of the run
     * @return array{router: Router, customer: Customer, done: bool}
     */
    public function huaweiCrossRouterCopyStep(CustomerMigration $migration, array $before): array
    {
        $router = Router::query()->find((int) data_get($before, 'router.id', 0));
        $customer = ($this->seams->resolveCustomer)($migration);
        if (! $router || ! $customer) {
            throw new \RuntimeException('Cross-router Huawei: customer/router target tidak ditemukan untuk copy PPPoE.');
        }
        if (! $this->mikrotikStepAlreadyVerified($migration, $router)) {
            return ['router' => $router, 'customer' => $customer, 'done' => false];
        }

        $check = ($this->seams->verifyExistingPppoeSecret)($customer, $router);
        if (($check['found'] ?? null) !== true) {
            throw new \RuntimeException("Lanjutan ditahan: akun {$customer->pppoe_user} ".
                (($check['found'] ?? null) === false ? 'tidak ditemukan' : 'belum bisa diverifikasi').
                " di router target {$router->name}. MikroTik tidak diulang; NOC cek dulu.");
        }
        $meta = MigrationNormalizer::meta($migration);
        $meta['timeline'] = MigrationNormalizer::appendTimeline($meta['timeline'] ?? [], 'mikrotik_step_skipped_already_verified', [
            'target_router_id' => $router->id,
            'pppoe_user' => $customer->pppoe_user,
        ]);
        $migration->meta = $meta;
        $migration->save();

        return ['router' => $router, 'customer' => $customer, 'done' => true];
    }

    public function registeredOnuForSn(CustomerMigration $migration, Olt $olt): ?OltOnu
    {
        $sn = MigrationNormalizer::normalizeSerial((string) $migration->new_sn);
        if ($sn === '' || ! Schema::hasTable('olt_onus')) {
            return null;
        }

        return OltOnu::query()
            ->where('olt_id', $olt->id)
            ->whereRaw('UPPER(REPLACE(REPLACE(COALESCE(serial_number, ""), "-", ""), " ", "")) = ?', [$sn])
            ->latest('id')
            ->first();
    }

    /**
     * Evidence that device writes already happened while the migration is
     * not synced. Null for plain drafts (nothing written yet).
     *
     * @return array<string, mixed>|null
     */
    public function partialExecutionState(CustomerMigration $migration): ?array
    {
        if (MigrationNormalizer::migrationAlreadySynced($migration) || MigrationNormalizer::isHuaweiOltOnlyMigration($migration)) {
            return null;
        }

        $meta = MigrationNormalizer::meta($migration);
        $mikrotik = (array) ($meta['mikrotik_migration'] ?? []);
        $mikrotikDone = ($mikrotik['ok'] ?? false) === true;
        $targetPreparedOnly = ! $mikrotikDone && ($mikrotik['target_prepared'] ?? false) === true;
        $oltDone = $migration->olt_at !== null || filled($meta['olt_provisioned_at'] ?? null);
        if (! $mikrotikDone && ! $targetPreparedOnly && ! $oltDone) {
            return null;
        }

        $crossRouter = ($mikrotik['cross_router'] ?? false) === true;
        $acsDone = data_get($meta, 'genieacs_tag.success') === true;
        $sn = MigrationNormalizer::normalizeSerial((string) $migration->new_sn);
        $oltName = $migration->new_olt_id
            ? (string) (Olt::query()->whereKey((int) $migration->new_olt_id)->value('name') ?? 'OLT #'.$migration->new_olt_id)
            : 'OLT target';

        $completed = [];
        $pending = [];
        if ($mikrotikDone) {
            $completed[] = 'mikrotik:target_secret_verified';
            if ($crossRouter) {
                $completed[] = 'mikrotik:source_secret_disabled';
            }
        } elseif ($targetPreparedOnly) {
            $completed[] = 'mikrotik:target_secret_prepared';
            $pending[] = 'mikrotik:source_cutover_needs_noc_check';
        }
        if ($oltDone) {
            $completed[] = 'olt:new_onu_linked';
        } else {
            $pending[] = 'olt:provision_or_link_new_onu';
        }
        if ($acsDone) {
            $completed[] = 'genieacs:tag';
        } else {
            $pending[] = 'genieacs:tag';
        }
        $pending[] = 'customer:sync_onu_olt';

        $executed = $this->ledger->latestMigrationDeviceLedger($migration, ['executed', 'verified', 'failed']);

        return [
            'mikrotik' => [
                'done' => $mikrotikDone,
                'target_prepared_only' => $targetPreparedOnly,
                'cross_router' => $crossRouter,
                'source_router' => (string) data_get($mikrotik, 'source_router.name', ''),
                'source_router_id' => data_get($mikrotik, 'source_router.id'),
                'target_router' => (string) data_get($mikrotik, 'target_router.name', ''),
                'target_router_id' => data_get($mikrotik, 'target_router.id'),
                'old_username' => (string) ($mikrotik['old_username'] ?? ''),
                'new_username' => (string) ($mikrotik['new_username'] ?? ''),
                'source_disabled' => $mikrotikDone && $crossRouter,
            ],
            'olt' => [
                'done' => $oltDone,
                'olt' => $oltName,
                'new_sn' => $sn ?: null,
                'onu_ref' => $migration->new_onu_ref,
            ],
            'genieacs' => ['done' => $acsDone],
            'customer_sync' => ['done' => false],
            'completed_steps' => $completed,
            'pending_steps' => $pending,
            'ledger_id' => $executed?->id,
            'ledger_status' => $executed?->status,
        ];
    }

    /**
     * Persist the partially-executed state (never downgraded by review) and
     * optionally tell the group what already ran and what is pending.
     */
    public function markPartiallyExecuted(CustomerMigration $migration, string $trigger, ?string $lastError = null, bool $notify = true): CustomerMigration
    {
        $migration = $migration->fresh() ?? $migration;
        $state = $this->partialExecutionState($migration);
        if ($state === null) {
            $trigger === 'resend_blocked' && $this->host->reply((string) $migration->group_jid, (new MigrationResendGuard)->alreadyRunText($migration));

            return $migration;
        }

        $hints = [];
        if (! data_get($state, 'olt.done')) {
            $olt = $migration->new_olt_id ? Olt::query()->find((int) $migration->new_olt_id) : null;
            $registered = $olt ? $this->registeredOnuForSn($migration, $olt) : null;
            if ($olt && ! $registered) {
                $hints = ($this->seams->snTypoHints)($migration, $olt, ($this->seams->uncfgRows)($olt->id));
            }
        }

        $meta = MigrationNormalizer::meta($migration);
        $previous = (array) ($meta['partial_execution'] ?? []);
        $short = $this->texts->partialExecutionShortMessage($migration, $state);
        $meta['partial_execution'] = array_merge($state, [
            'message' => $short,
            'rollback_hint' => $this->texts->partialRollbackHint($migration, $state),
            'trigger' => $trigger,
            'sn_hints' => $hints,
            'first_marked_at' => $previous['first_marked_at'] ?? now()->toIso8601String(),
            'marked_at' => now()->toIso8601String(),
            'force_cancelled' => $previous['force_cancelled'] ?? null,
        ]);
        $meta['timeline'] = MigrationNormalizer::appendTimeline($meta['timeline'] ?? [], 'partially_executed', [
            'trigger' => $trigger,
            'completed_steps' => $state['completed_steps'],
            'pending_steps' => $state['pending_steps'],
            'ledger_id' => $state['ledger_id'],
        ]);
        // The partial summary already covers the "SN belum kedetek" case.
        $meta['wa_waiting_sn_reply_sent_at'] = $meta['wa_waiting_sn_reply_sent_at'] ?? now()->toIso8601String();
        $migration->meta = $meta;
        if ($migration->status !== CustomerMigration::STATUS_CANCELLED) {
            $migration->status = CustomerMigration::STATUS_PARTIALLY_EXECUTED;
        }
        $error = trim((string) $lastError);
        $migration->last_error = $short.($error !== '' && ! str_contains($error, 'sebagian dieksekusi') ? ' Error terakhir: '.$this->texts->shortWaError($error, 300) : '');
        $migration->save();

        $migration = $migration->fresh() ?? $migration;
        if ($notify) {
            $extra = $error !== '' && ! str_contains($error, 'sebagian dieksekusi')
                ? ['Error terakhir: '.$this->texts->shortWaError($error)]
                : [];
            $this->host->reply((string) $migration->group_jid, $this->texts->partialExecutionWaMessage($migration, $state, $extra));
        }

        return $migration;
    }

    /**
     * Execute was requested but the approval ledger already ran. Reply what
     * was done/pending, record it, and prepare a continuation dry-run so the
     * next explicit `#migrasi eksekusi #ID` only runs the remaining steps.
     * Caller must hold `customer-migration-device-{id}`.
     */
    public function handleAlreadyExecutedApproval(CustomerMigration $migration, ?DeviceActionLog $ledger, ?User $actor, bool $notifyWa): CustomerMigration
    {
        $migration = $migration->fresh() ?? $migration;
        $group = (string) $migration->group_jid;
        $id = $migration->id;

        if (MigrationNormalizer::migrationAlreadySynced($migration)) {
            if ($notifyWa) {
                $this->host->reply($group, "Migrasi #{$id} sudah selesai; tidak dieksekusi ulang.");
            }

            return $migration;
        }

        $meta = MigrationNormalizer::meta($migration);
        $meta['timeline'] = MigrationNormalizer::appendTimeline($meta['timeline'] ?? [], 'execute_skipped_ledger_already_executed', [
            'ledger_id' => $ledger?->id,
            'ledger_status' => $ledger?->status,
        ]);
        $migration->meta = $meta;
        $migration->save();

        $state = $this->partialExecutionState($migration);
        if ($state !== null) {
            $migration = $this->markPartiallyExecuted($migration, 'execute_after_executed_ledger', null, false);
            $state = $this->partialExecutionState($migration) ?? $state;
        }

        $error = null;
        $ready = false;
        try {
            $migration = ($this->seams->prepareDeviceActionApprovalLocked)(
                $migration,
                $actor,
                'Lanjutan otomatis: ledger #'.($ledger?->id ?? '-').' sudah executed, siapkan langkah tersisa'
            );
            $approval = (array) data_get($migration->meta, 'device_action_approval', []);
            $newLedger = $this->ledger->approvalLedger($migration);
            $ready = ($approval['status'] ?? null) === 'dry_run_ready'
                && $newLedger
                && in_array($newLedger->status, ['dry_run', 'planned'], true)
                && (! $ledger || $newLedger->id !== $ledger->id);
        } catch (\Throwable $e) {
            $error = MigrationNormalizer::boundedError($e);
        }

        $migration = $migration->fresh() ?? $migration;
        $meta = MigrationNormalizer::meta($migration);
        $approval = (array) ($meta['device_action_approval'] ?? []);
        $lines = ["*Migrasi #{$id}: eksekusi sebelumnya (ledger #".($ledger?->id ?? '-').') sudah berjalan, belum selesai*'];
        if ($state !== null) {
            $lines = array_merge($lines, $this->texts->partialExecutionLines($migration, $state));
        }
        if ($ready) {
            $remaining = (array) ($approval['remaining_steps'] ?? []);
            $lines[] = 'Dry-run lanjutan siap (ledger #'.($approval['ledger_id'] ?? '-').'): '.($remaining !== [] ? implode(', ', $remaining) : 'langkah tersisa').'.';
            $lines[] = 'MikroTik tidak diulang; langkah yang sudah terverifikasi dilewati.';
            foreach ((array) data_get($meta, 'partial_execution.sn_hints', []) as $hint) {
                if (is_array($hint) && filled($hint['message'] ?? null)) {
                    $lines[] = (string) $hint['message'];
                }
            }
            if ((array) data_get($meta, 'partial_execution.sn_hints', []) !== []) {
                $lines[] = "Jika SN salah, koreksi dulu: #migrasi sn #{$id} <SN_BENAR>";
            }
            $lines[] = "Kirim lagi untuk melanjutkan: #migrasi eksekusi #{$id}";
            $meta['timeline'] = MigrationNormalizer::appendTimeline($meta['timeline'] ?? [], 'continuation_dry_run_ready', [
                'ledger_id' => $approval['ledger_id'] ?? null,
                'previous_ledger_id' => $ledger?->id,
            ]);
        } else {
            $reason = $error ?? 'dry-run lanjutan belum dapat dibuat';
            $lines[] = 'Lanjutan belum bisa disiapkan: '.$this->texts->shortWaError($reason, 220);
            foreach ((array) data_get($meta, 'partial_execution.sn_hints', []) as $hint) {
                if (is_array($hint) && filled($hint['message'] ?? null)) {
                    $lines[] = (string) $hint['message'];
                }
            }
            if (str_contains($reason, 'konfirmasi')) {
                $lines[] = "Konfirmasi ulang: #migrasi konfirmasi #{$id}";
            }
            $lines[] = "Koreksi SN bila salah: #migrasi sn #{$id} <SN_BENAR>";
            $lines[] = "Cek: #migrasi cek #{$id}";
            $meta['timeline'] = MigrationNormalizer::appendTimeline($meta['timeline'] ?? [], 'continuation_dry_run_blocked', [
                'previous_ledger_id' => $ledger?->id,
                'error' => $this->texts->shortWaError($reason, 200),
            ]);
            $migration->last_error = ($state !== null ? $this->texts->partialExecutionShortMessage($migration, $state).' ' : '')
                .'Lanjutan tertahan: '.$this->texts->shortWaError($reason, 300);
        }
        $migration->meta = $meta;
        $migration->save();

        if ($notifyWa) {
            $this->host->reply($group, implode("\n", $lines));
        }

        return $migration->fresh() ?? $migration;
    }

    /**
     * Cancel guard for partially executed migrations: plain `batal` is
     * refused with the state + rollback hint; `batal paksa` (bound sender or
     * NOC) cancels without touching devices and repeats the rollback steps.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $parsed
     */
    public function guardPartialCancelFromWa(CustomerMigration $migration, array $payload, array $parsed): ?CustomerMigration
    {
        $state = $this->partialExecutionState($migration);
        if ($state === null) {
            return null;
        }
        $group = (string) ($payload['group_jid'] ?? '');
        $id = $migration->id;
        $rollback = $this->texts->partialRollbackHint($migration, $state);

        if ($migration->status === CustomerMigration::STATUS_CANCELLED) {
            $this->host->reply($group, "Migrasi #{$id} sudah dibatalkan paksa sebelumnya. {$rollback}");

            return $migration;
        }

        if (! ($parsed['is_force'] ?? false)) {
            $meta = MigrationNormalizer::meta($migration);
            $meta['timeline'] = MigrationNormalizer::appendTimeline($meta['timeline'] ?? [], 'cancel_refused_partial_execution', [
                'sender_keys' => ($this->seams->waSenderKeys)($payload),
            ]);
            $migration->meta = $meta;
            $migration->save();
            $this->host->reply($group, implode("\n", array_merge(
                ["*Batal migrasi #{$id} DITOLAK: perangkat sudah sebagian diubah*"],
                $this->texts->partialExecutionLines($migration, $state),
                [
                    "Lanjutkan saja: #migrasi sn #{$id} <SN_BENAR> lalu #migrasi eksekusi #{$id}",
                    "Jika benar-benar harus batal: #migrasi batal paksa #{$id} (tidak ada rollback otomatis).",
                    $rollback,
                ]
            )));

            return $migration;
        }

        if (! ($this->seams->canModifyMigrationFromWa)($migration, $payload)) {
            $this->host->reply($group, "Batal paksa #{$id} ditolak. Hanya teknisi pembuat/pemilih draft atau NOC allowlisted yang boleh.");

            return $migration;
        }

        $ledger = $this->ledger->approvalLedger($migration);
        if ($ledger && $ledger->status === 'executing') {
            $this->host->reply($group, "Migrasi #{$id} sedang dieksekusi; batal paksa ditolak sampai eksekusi selesai.");

            return $migration;
        }
        if ($ledger && in_array($ledger->status, ['requested', 'dry_run', 'planned'], true)) {
            $ledger->status = 'cancelled';
            $ledger->result = array_merge((array) $ledger->result, [
                'cancelled_reason' => 'force_cancel_after_partial_execution',
                'device_write' => false,
                'cancelled_at' => now()->toIso8601String(),
            ]);
            $ledger->save();
        }

        $meta = MigrationNormalizer::meta($migration);
        $meta['partial_execution'] = array_merge((array) ($meta['partial_execution'] ?? $state), [
            'force_cancelled' => [
                'at' => now()->toIso8601String(),
                'sender_keys' => ($this->seams->waSenderKeys)($payload),
                'sender_name' => (string) ($payload['sender_name'] ?? ''),
                'rollback_hint' => $rollback,
            ],
        ]);
        $meta['timeline'] = MigrationNormalizer::appendTimeline($meta['timeline'] ?? [], 'force_cancelled_partial_execution', [
            'completed_steps' => $state['completed_steps'],
        ]);
        $migration->meta = $meta;
        $migration->status = CustomerMigration::STATUS_CANCELLED;
        $migration->last_error = 'Dibatalkan paksa setelah eksekusi sebagian. '.$rollback;
        $migration->save();
        $this->host->reply($group, implode("\n", array_merge(
            ["*Migrasi #{$id} dibatalkan PAKSA*", 'Perangkat TIDAK dikembalikan otomatis. Kondisi saat ini:'],
            $this->texts->partialExecutionLines($migration, $state),
            [$rollback]
        )));

        return $migration->fresh() ?? $migration;
    }
}
