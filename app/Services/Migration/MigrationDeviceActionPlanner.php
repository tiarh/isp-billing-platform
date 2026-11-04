<?php

namespace App\Services\Migration;

use App\Models\Customer;
use App\Models\CustomerMigration;
use App\Models\User;
use App\Services\HuaweiOltOnlyProvisioningService;
use App\Services\MigrationOperationClassifier;
use Illuminate\Support\Carbon;

/**
 * Device-action planning of the migration flow (dry-run preparation, preflight, payload,
 * operation classification and the execution guard), moved out of CustomerMigrationService
 * without a change of behaviour. Takes no lock and never writes a device: the facade keeps
 * `customer-migration-device-{id}`, the executing marker and the execute try/catch. Callers
 * of prepareDeviceActionApprovalLocked hold the device lock.
 *
 * The control plane, the Huawei service, the operation classifier and every overridden or
 * mocked facade method are reached through the seams at call time.
 */
final class MigrationDeviceActionPlanner
{
    public function __construct(
        private MigrationSeams $seams,
        private MigrationDeviceLedger $ledger,
        private MigrationPartialExecution $partials,
        private MigrationTargetResolver $resolver,
        private MigrationPlanBuilder $plans,
        private MigrationEvidenceReader $evidence,
        private CustomerMigrationWaMessages $texts,
    ) {}

    /**
     * Caller must hold `customer-migration-device-{id}`. After a partial
     * execution this prepares a continuation dry-run (idempotency key
     * `<base>:retry:<ledgerId>`) whose steps skip what is already verified.
     */
    public function prepareDeviceActionApprovalLocked(CustomerMigration $migration, ?User $actor, string $reason): CustomerMigration
    {
        $migration = $migration->fresh() ?? $migration;
        if (MigrationNormalizer::migrationAlreadySynced($migration)) {
            throw new \RuntimeException("Migrasi #{$migration->id} sudah selesai; tidak dieksekusi ulang.");
        }
        if (MigrationNormalizer::isHuaweiOltOnlyMigration($migration)) {
            return $this->prepareHuaweiOltOnlyDeviceAction($migration, $actor, $reason);
        }
        $hasReusableDryRun = $this->ledger->hasReusableDeviceActionDryRun($migration);
        $operation = $this->classifyAndPersistOperation($migration, null, ! $hasReusableDryRun);
        if ($operation['operation_type'] === MigrationOperationClassifier::DUPLICATE_MIGRATION) {
            throw new \RuntimeException('Migrasi duplikat; operasi yang sama sudah selesai atau sedang tercatat.');
        }
        if ($operation['operation_type'] === MigrationOperationClassifier::PENDING_TECHNICIAN_CONFIRMATION) {
            throw new \RuntimeException('Replacement perangkat belum dikonfirmasi teknisi.');
        }
        if ($operation['operation_type'] === MigrationOperationClassifier::CONFLICT) {
            throw new \RuntimeException('Identitas customer/router/PPPoE migrasi belum valid atau conflict.');
        }
        if (($operation['requires_confirmation'] ?? false)
            && data_get($migration->meta, 'replacement_confirmation.status') !== 'confirmed') {
            throw new \RuntimeException('Replacement membutuhkan konfirmasi teknisi untuk reference '.$operation['operation_reference'].'.');
        }
        $replacementConfirmationExpiresAt = data_get($migration->meta, 'replacement_confirmation.expires_at');
        if (($operation['requires_confirmation'] ?? false)
            && is_string($replacementConfirmationExpiresAt)
            && $replacementConfirmationExpiresAt !== ''
            && Carbon::parse($replacementConfirmationExpiresAt)->isPast()) {
            throw new \RuntimeException('Konfirmasi replacement sudah kedaluwarsa; minta konfirmasi teknisi ulang.');
        }
        if ($hasReusableDryRun) {
            return $migration;
        }

        $preflight = $this->deviceActionPreflight($migration);
        $hardReasons = array_diff((array) ($preflight['reasons'] ?? []), [
            'pppoe_missing',
            'old_pppoe_secret_missing',
        ]);
        if ($hardReasons !== []) {
            throw new \RuntimeException('Migrasi belum layak action perangkat: '.implode(', ', (array) ($preflight['reasons'] ?? []))
                .$this->plans->migrationReasonHint((array) ($preflight['reasons'] ?? [])));
        }

        $continuation = (bool) ($preflight['continuation'] ?? false);
        $previousLedger = $continuation ? $this->ledger->latestMigrationDeviceLedger($migration) : null;
        $payload = $this->deviceActionPayload($migration, $preflight, $reason);
        $ledger = ($this->seams->controlPlane)()->ledgerRequested(
            domain: 'migration',
            targetType: 'customer_migration',
            targetId: (string) $migration->id,
            action: 'migration-device-action-dry-run',
            payload: $payload,
            actor: $actor,
            preflight: $preflight,
            idempotencyKey: $this->ledger->nextMigrationDeviceIdempotencyKey($migration),
            rollbackHint: $continuation
                ? $this->texts->partialRollbackHint($migration, (array) ($preflight['partial_execution'] ?? []))
                : $this->texts->migrationRollbackHint($migration, $preflight)
        );
        if ($ledger->status === 'requested') {
            $ledger->status = 'dry_run';
            $ledger->result = [
                'mode' => 'dry_run',
                'device_write' => false,
                'customer_write' => false,
                'execute_allowed' => false,
                'reason' => $reason,
                'continuation' => $continuation,
                'prepared_at' => now()->toIso8601String(),
            ];
            $ledger->save();
        }

        $meta = MigrationNormalizer::meta($migration);
        $meta['device_action_approval'] = [
            'status' => 'dry_run_ready',
            'ledger_id' => $ledger->id,
            'requested_by' => $actor?->id,
            'reason' => $reason,
            'requested_at' => now()->toIso8601String(),
            'idempotency_key' => $ledger->idempotency_key,
            'write_flags' => $this->migrationWriteFlags(),
            'rollback_hint' => $ledger->rollback_hint,
            'continuation' => $continuation,
            'continuation_of_ledger_id' => $previousLedger && $previousLedger->id !== $ledger->id ? $previousLedger->id : null,
            'remaining_steps' => $continuation ? (array) data_get($payload, 'remaining_steps', []) : null,
        ];
        $meta['device_action_preflight'] = $preflight;
        $meta['timeline'] = MigrationNormalizer::appendTimeline($meta['timeline'] ?? [], $continuation ? 'device_action_continuation_dry_run_prepared' : 'device_action_dry_run_prepared', [
            'ledger_id' => $ledger->id,
            'reason' => $reason,
            'write_flags' => $meta['device_action_approval']['write_flags'],
            'continuation_of_ledger_id' => $meta['device_action_approval']['continuation_of_ledger_id'],
        ]);
        $migration->meta = $meta;
        $migration->last_error = $continuation
            ? trim((string) data_get($meta, 'partial_execution.message', 'Sebagian dieksekusi.')).' Dry-run lanjutan siap (ledger #'.$ledger->id.'): hanya langkah tersisa; MikroTik tidak diulang.'
            : 'Dry-run action migrasi siap. Belum ada write perangkat/customer; execute butuh ACK MIG-008 dan kill switch.';
        $migration->save();

        return $migration->fresh() ?? $migration;
    }

    public function prepareHuaweiOltOnlyDeviceAction(CustomerMigration $migration, ?User $actor, string $reason): CustomerMigration
    {
        $preflight = ($this->seams->huaweiOltOnly)()->preflight($migration);
        if (($preflight['eligible'] ?? false) !== true) {
            throw new \RuntimeException('Huawei OLT-only belum layak: '.implode(', ', (array) ($preflight['reasons'] ?? ['preflight_failed'])));
        }

        $payload = [
            'mode' => 'dry_run',
            'operation_mode' => HuaweiOltOnlyProvisioningService::MODE,
            'migration_id' => $migration->id,
            'pppoe_user' => $migration->pppoe_user,
            'new_sn' => MigrationNormalizer::normalizeSerial((string) $migration->new_sn),
            'new_olt_id' => data_get($preflight, 'olt.id'),
            'new_onu_ref' => data_get($preflight, 'placement.onu_ref'),
            'reason' => $reason,
            'steps' => [
                ['domain' => 'mikrotik', 'action' => 'read_target_pppoe_secret', 'mode' => 'read_only'],
                ['domain' => 'olt', 'action' => 'provision_huawei_ma5608t_ont_and_service_ports', 'mode' => 'planned_only'],
                ['domain' => 'genieacs', 'action' => 'tag_after_cpe_inform', 'mode' => 'planned_only'],
                ['domain' => 'olt', 'action' => 'old_onu', 'mode' => 'not_touched'],
                ['domain' => 'customer', 'action' => 'customer_link', 'mode' => 'not_touched'],
            ],
            'plan' => [
                'command_hash' => data_get($preflight, 'plan.command_hash'),
                'commands' => data_get($preflight, 'plan.commands', []),
                'pon_port' => data_get($preflight, 'placement.pon_port'),
                'onu_id' => data_get($preflight, 'placement.onu_id'),
                'internet_vlan' => data_get($preflight, 'plan.internet_vlan'),
                'tr069_vlan' => data_get($preflight, 'plan.tr069_vlan'),
                'line_profile' => data_get($preflight, 'plan.line_profile'),
            ],
            'device_write' => false,
            'customer_write' => false,
        ];
        $ledger = ($this->seams->controlPlane)()->ledgerRequested(
            domain: 'migration',
            targetType: 'customer_migration',
            targetId: (string) $migration->id,
            action: 'migration-device-action-dry-run',
            payload: $payload,
            actor: $actor,
            preflight: $preflight,
            idempotencyKey: $this->ledger->nextMigrationDeviceIdempotencyKey($migration),
            rollbackHint: 'Huawei OLT-only: disable new ONU/service-port only after a separately approved incident rollback; ONU lama tidak disentuh.'
        );
        if ($ledger->status === 'requested') {
            $ledger->status = 'dry_run';
            $ledger->result = [
                'mode' => 'dry_run',
                'device_write' => false,
                'customer_write' => false,
                'execute_allowed' => false,
                'reason' => $reason,
                'prepared_at' => now()->toIso8601String(),
            ];
            $ledger->save();
        }

        $meta = MigrationNormalizer::meta($migration);
        $flags = ($this->seams->huaweiOltOnlyWriteFlags)();
        $meta['device_action_approval'] = [
            'status' => 'dry_run_ready',
            'ledger_id' => $ledger->id,
            'requested_by' => $actor?->id,
            'reason' => $reason,
            'requested_at' => now()->toIso8601String(),
            'idempotency_key' => $ledger->idempotency_key,
            'write_flags' => $flags,
            'rollback_hint' => $ledger->rollback_hint,
        ];
        $meta['device_action_preflight'] = $preflight;
        $meta['timeline'] = MigrationNormalizer::appendTimeline($meta['timeline'] ?? [], 'huawei_olt_only_dry_run_prepared', [
            'ledger_id' => $ledger->id,
            'command_hash' => data_get($preflight, 'plan.command_hash'),
            'write_flags' => $flags,
        ]);
        $migration->meta = $meta;
        $migration->new_olt_id = data_get($preflight, 'olt.id') ?: $migration->new_olt_id;
        $migration->new_pon_port = data_get($preflight, 'placement.pon_port') ?: $migration->new_pon_port;
        $migration->new_onu_id = data_get($preflight, 'placement.onu_id');
        $migration->new_onu_ref = data_get($preflight, 'placement.onu_ref');
        $migration->vlan = data_get($preflight, 'plan.internet_vlan');
        $migration->tr069_vlan = data_get($preflight, 'plan.tr069_vlan');
        $migration->last_error = 'Huawei OLT-only dry-run siap. Belum ada OLT/GenieACS/customer write.';
        $migration->save();

        return $migration->fresh() ?? $migration;
    }

    /**
     * @return array<string, mixed>
     */
    public function deviceActionPreflight(CustomerMigration $migration): array
    {
        $migration = $migration->fresh() ?? $migration;
        $customer = ($this->seams->resolveCustomer)($migration);
        $alreadySynced = MigrationNormalizer::migrationAlreadySynced($migration);
        if ($customer && ! $alreadySynced) {
            $this->resolver->primeMigrationFromCustomer($migration, $customer);
            $migration = $migration->fresh() ?? $migration;
            $customer = $customer->fresh() ?? $customer;
        }

        $router = $customer ? $this->resolver->resolveRouterForMigration($migration, $customer) : null;
        $sourceRouter = ($customer && $router) ? $this->resolver->resolveSourceRouterForMigration($migration, $customer) : null;
        $olt = ($customer && $router) ? $this->resolver->resolveOltForMigration($migration, $customer, $router) : null;
        $review = is_array($migration->meta['migration_review'] ?? null) ? $migration->meta['migration_review'] : [];
        $reasons = [];
        $warnings = [];
        // Continuation: the MikroTik step of an earlier approved execution is
        // already verified on this target router; only the remaining steps run.
        $continuation = ! $alreadySynced && $customer && $router && $this->partials->mikrotikStepAlreadyVerified($migration, $router);
        $partialState = $alreadySynced ? null : $this->partials->partialExecutionState($migration);

        if (! $customer) {
            $reasons[] = 'customer_missing';
        }
        // A migration that already passed review and ran an approved device
        // ledger must not be blocked by a later (stale) review downgrade.
        if (! $alreadySynced
            && ($review['match_status'] ?? null) !== 'confirmed_pending_approval'
            && ! ($partialState !== null && $this->ledger->hasExecutedDeviceLedger($migration))) {
            $reasons[] = 'not_confirmed_pending_approval';
        }
        if (! $router) {
            $reasons[] = 'router_missing';
        }
        if ($this->resolver->migrationHasExplicitSourceRouter($migration) && ! $sourceRouter) {
            $reasons[] = 'source_router_missing';
        }
        if (! $olt) {
            $reasons[] = 'olt_missing';
        }
        if (MigrationNormalizer::normalizeSerial((string) $migration->new_sn) === '') {
            $reasons[] = 'new_sn_missing';
        }
        if ($customer && MigrationNormalizer::normalizeUsername((string) $customer->pppoe_user) === '') {
            $reasons[] = 'pppoe_missing';
        }

        $oltSnapshot = $this->evidence->oltSnapshotForMigration($migration, $olt, $customer);
        if (($oltSnapshot['found'] ?? false) && ! ($oltSnapshot['belongs_to_migration'] ?? false)) {
            $reasons[] = 'new_sn_in_use_by_other_onu';
        }

        if ($continuation) {
            // The source secret was intentionally disabled by the first run;
            // the secret that must exist now is the one on the target router.
            $secret =
                array_merge(($this->seams->verifyExistingPppoeSecret)($customer, $router), ['scope' => 'target_router_after_partial']);
            if (($secret['found'] ?? null) === false) {
                $reasons[] = 'target_pppoe_secret_missing';
            } elseif (($secret['found'] ?? null) !== true) {
                $reasons[] = 'target_pppoe_secret_unverified';
                $warnings[] = 'target_pppoe_secret_unverified';
            }
        } else {
            $secret = ($customer && $sourceRouter) ? ($this->seams->verifyExistingPppoeSecret)($customer, $sourceRouter) : ['found' => null, 'warning' => 'skip'];
            if (($secret['found'] ?? null) === false) {
                $reasons[] = 'old_pppoe_secret_missing';
            } elseif (($secret['found'] ?? null) !== true) {
                $reasons[] = 'old_pppoe_secret_unverified';
                $warnings[] = 'old_pppoe_secret_unverified';
            }
        }

        $target = $router ? $this->plans->migrationTargetContract($migration, [
            'customer_before' => $customer ? $this->evidence->customerSnapshot($customer) : null,
            'router' => ['id' => $router->id, 'name' => $router->name],
            'mikrotik_secret' => $secret,
        ]) : [];
        if (($target['preserve_package'] ?? false) && ! empty($target['package_blocker'])) {
            $reasons[] = (string) $target['package_blocker'];
        }
        if (! empty($target['profile_warning'])) {
            $warnings[] = (string) $target['profile_warning'];
        }
        $targetProfile = trim((string) ($target['mikrotik_profile'] ?? $target['profile'] ?? ''));
        $targetProfileCheck = ['found' => null, 'desired' => $targetProfile];
        if ($router && $targetProfile !== '') {
            $targetProfileCheck = ($this->seams->verifyTargetMigrationProfile)($router, $targetProfile);
            if (($targetProfileCheck['found'] ?? null) === false) {
                $reasons[] = 'target_profile_missing';
            } elseif (($targetProfileCheck['found'] ?? null) !== true) {
                $reasons[] = 'target_profile_unverified';
            }
            if (! empty($targetProfileCheck['warning'])) {
                $warnings[] = (string) $targetProfileCheck['warning'];
            }
        }

        return [
            'eligible' => $reasons === [],
            'reasons' => array_values(array_unique($reasons)),
            'warnings' => array_values(array_unique($warnings)),
            'migration' => [
                'id' => $migration->id,
                'status' => $migration->status,
                'review_status' => $review['match_status'] ?? null,
                'old_sn' => MigrationNormalizer::normalizeSerial((string) $migration->old_sn) ?: null,
                'new_sn' => MigrationNormalizer::normalizeSerial((string) $migration->new_sn) ?: null,
                'old_onu_ref' => $migration->old_onu_ref,
                'new_onu_ref' => $migration->new_onu_ref,
            ],
            'customer_before' => $customer ? $this->evidence->customerSnapshot($customer) : null,
            'customer_after_proposed' => $this->evidence->proposedCustomerSnapshot($migration, $customer, $olt),
            'router' => $router ? ['id' => $router->id, 'name' => $router->name] : null,
            'source_router' => $sourceRouter ? ['id' => $sourceRouter->id, 'name' => $sourceRouter->name] : null,
            'cross_router' => $sourceRouter && $router && $sourceRouter->id !== $router->id,
            'olt' => $olt ? ['id' => $olt->id, 'name' => $olt->name, 'vendor' => $olt->vendor ?? null] : null,
            'mikrotik_secret' => $secret,
            'target_profile' => $targetProfileCheck,
            'olt_snapshot' => $oltSnapshot,
            'genieacs_snapshot' => $this->evidence->genieAcsSnapshotForMigration($migration),
            'write_flags' => $this->migrationWriteFlags(),
            'continuation' => $continuation,
            'partial_execution' => $partialState,
            'checked_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function deviceActionPayload(CustomerMigration $migration, array $preflight, string $reason): array
    {
        $target = $this->plans->migrationTargetContract($migration, $preflight);

        if ($preflight['continuation'] ?? false) {
            $partial = (array) ($preflight['partial_execution'] ?? []);
            $oltDone = (bool) data_get($partial, 'olt.done', false);
            $steps = [
                ['domain' => 'mikrotik', 'action' => 'verify_target_pppoe_secret', 'mode' => 'read_only'],
                ['domain' => 'mikrotik', 'action' => 'same_router_rename_or_cross_router_copy_then_cutover', 'mode' => 'skip_already_verified'],
                ['domain' => 'olt', 'action' => 'provision_or_link_new_onu', 'mode' => $oltDone ? 'skip_already_verified' : 'planned_only'],
                ['domain' => 'customer', 'action' => 'sync_customer_snapshot', 'mode' => 'planned_only'],
                ['domain' => 'genieacs', 'action' => 'sync_tag_label', 'mode' => 'planned_only'],
                ['domain' => 'olt', 'action' => 'delete_old_onu_if_safe', 'mode' => 'planned_only'],
            ];

            return [
                'mode' => 'dry_run',
                'continuation' => true,
                'migration_id' => $migration->id,
                'customer_id' => $migration->customer_id,
                'pppoe_user' => $migration->pppoe_user,
                'old_sn' => MigrationNormalizer::normalizeSerial((string) $migration->old_sn) ?: null,
                'new_sn' => MigrationNormalizer::normalizeSerial((string) $migration->new_sn) ?: null,
                'old_olt_id' => $migration->old_olt_id,
                'new_olt_id' => $migration->new_olt_id,
                'old_onu_ref' => $migration->old_onu_ref,
                'new_onu_ref' => $migration->new_onu_ref,
                'reason' => $reason,
                'target_contract' => $target,
                'completed_steps' => (array) ($partial['completed_steps'] ?? []),
                'remaining_steps' => array_values(array_map(
                    fn (array $step): string => $step['domain'].':'.$step['action'],
                    array_filter($steps, fn (array $step): bool => $step['mode'] === 'planned_only')
                )),
                'steps' => $steps,
                'dry_run_diff' => [
                    'olt' => $this->plans->oltDryRunDiff($migration, $preflight, $target),
                    'genieacs' => $this->plans->genieAcsDryRunDiff($migration, $preflight),
                    'mikrotik' => [
                        'mode' => 'skip_already_verified',
                        'target_secret' => $preflight['mikrotik_secret'] ?? null,
                    ],
                ],
                'device_write' => false,
                'customer_write' => false,
                'write_blocked_until_ack' => true,
            ];
        }

        return [
            'mode' => 'dry_run',
            'migration_id' => $migration->id,
            'customer_id' => $migration->customer_id,
            'pppoe_user' => $migration->pppoe_user,
            'old_sn' => MigrationNormalizer::normalizeSerial((string) $migration->old_sn) ?: null,
            'new_sn' => MigrationNormalizer::normalizeSerial((string) $migration->new_sn) ?: null,
            'old_olt_id' => $migration->old_olt_id,
            'new_olt_id' => $migration->new_olt_id,
            'old_onu_ref' => $migration->old_onu_ref,
            'new_onu_ref' => $migration->new_onu_ref,
            'reason' => $reason,
            'target_contract' => $target,
            'steps' => [
                ['domain' => 'mikrotik', 'action' => 'verify_old_pppoe_secret_on_source_router', 'mode' => 'read_only'],
                ['domain' => 'mikrotik', 'action' => 'same_router_rename_or_cross_router_copy_then_cutover', 'mode' => 'planned_only'],
                ['domain' => 'olt', 'action' => 'provision_new_onu', 'mode' => 'planned_only'],
                ['domain' => 'customer', 'action' => 'sync_customer_snapshot', 'mode' => 'planned_only'],
                ['domain' => 'genieacs', 'action' => 'sync_tag_label', 'mode' => 'planned_only'],
                ['domain' => 'olt', 'action' => 'delete_old_onu_if_safe', 'mode' => 'planned_only'],
            ],
            'dry_run_diff' => [
                'customer' => [
                    'before' => $preflight['customer_before'] ?? null,
                    'after' => $preflight['customer_after_proposed'] ?? null,
                    'ktp' => $this->plans->ktpMigrationPlan($migration, $preflight),
                ],
                'mikrotik' => $this->plans->mikrotikDryRunDiff($migration, $preflight, $target),
                'olt' => $this->plans->oltDryRunDiff($migration, $preflight, $target),
                'genieacs' => $this->plans->genieAcsDryRunDiff($migration, $preflight),
                'unknown_or_skipped' => $this->plans->unknownMigrationInputs($migration, $preflight),
            ],
            'device_write' => false,
            'customer_write' => false,
            'write_blocked_until_ack' => true,
        ];
    }

    /**
     * @return array<string, bool>
     */
    public function migrationWriteFlags(): array
    {
        return [
            'migration_actions' => MigrationNormalizer::boolSetting('migration_device_actions_enabled', false),
            'global_read_only' => MigrationNormalizer::boolSetting('control_plane.device_read_only_mode', true),
            'olt' => ($this->seams->controlPlane)()->writeEnabled('olt'),
            'mikrotik' => ($this->seams->controlPlane)()->writeEnabled('mikrotik'),
            'genieacs' => ($this->seams->controlPlane)()->writeEnabled('genieacs'),
        ];
    }

    /**
     * Classify the stable customer/router/PPPoE/SN identity once all known
     * fields have been hydrated. The result is metadata only; it never writes
     * a device or customer.
     *
     * @return array<string, mixed>
     */
    public function classifyAndPersistOperation(
        CustomerMigration $migration,
        ?Customer $customer = null,
        bool $persist = true
    ): array {
        $customer ??= $migration->customer ?: ($migration->customer_id ? Customer::query()->find($migration->customer_id) : null);
        $meta = MigrationNormalizer::meta($migration);
        $inputPppoe = trim((string) data_get($meta, 'input_identity.pppoe_user', $migration->pppoe_user));
        $targetRouterId = data_get($meta, 'router_mapping.status') === 'target_router_unresolved'
            ? 0
            : (int) ($migration->router_id ?: $customer?->router_id ?: 0);
        $newSn = MigrationNormalizer::normalizeSerial((string) $migration->new_sn);
        $alreadyProcessed = false;
        if ($customer && $targetRouterId > 0 && $newSn !== '') {
            $alreadyProcessed = CustomerMigration::query()
                ->whereKeyNot($migration->id ?: 0)
                ->where('status', CustomerMigration::STATUS_SYNCED)
                ->where('customer_id', $customer->id)
                ->where('router_id', $targetRouterId)
                ->whereRaw('UPPER(REPLACE(REPLACE(COALESCE(new_sn, ""), "-", ""), " ", "")) = ?', [$newSn])
                ->get(['pppoe_user'])
                ->contains(fn (CustomerMigration $row): bool => trim((string) $row->pppoe_user) === trim((string) ($customer->pppoe_user ?? '')));
        }
        // After a partial execution the MikroTik step already moved/renamed
        // the secret and the customer row. Classify the ORIGINAL operation
        // (source router, pre-rename PPPoE) so the half-done state is not
        // misread as a PPPoE conflict or a new same-router replacement.
        $currentRouterId = $customer?->router_id;
        $mikrotikDone = (array) data_get($meta, 'mikrotik_migration', []);
        if (($mikrotikDone['ok'] ?? false) === true && ! MigrationNormalizer::migrationAlreadySynced($migration)) {
            $oldUsername = MigrationNormalizer::normalizeUsername((string) ($mikrotikDone['old_username'] ?? ''));
            $newUsername = MigrationNormalizer::normalizeUsername((string) ($mikrotikDone['new_username'] ?? ''));
            if ($newUsername !== '' && $oldUsername !== '' && MigrationNormalizer::normalizeUsername($inputPppoe) === $oldUsername) {
                $inputPppoe = $newUsername;
            }
            $sourceRouterId = (int) data_get($mikrotikDone, 'source_router.id', 0);
            if (($mikrotikDone['cross_router'] ?? false) === true && $sourceRouterId > 0) {
                $currentRouterId = $sourceRouterId;
            }
        }
        $operation = ($this->seams->operationClassifier)()->classify([
            'customer_id' => $customer?->id,
            'current_router_id' => $currentRouterId,
            'target_router_id' => $targetRouterId,
            'old_router_id' => $migration->old_router_id,
            'pppoe_user' => $inputPppoe ?: $migration->pppoe_user,
            'customer_pppoe_user' => $customer?->pppoe_user,
            'current_sn' => $customer?->onu_serial,
            'new_sn' => $migration->new_sn,
            'already_processed' => $alreadyProcessed,
            'replacement_reason_present' => ($this->seams->operationClassifier)()->hasReplacementReason((string) $migration->raw_text),
            'technician_confirmed' => data_get($meta, 'replacement_confirmation.status') === 'confirmed',
        ]);
        $operation['classified_at'] = now()->toIso8601String();
        $operation['target_router_id'] = $targetRouterId ?: null;
        $operation['customer_id'] = $customer?->id;
        $existingOperation = is_array($meta['migration_operation'] ?? null)
            ? $meta['migration_operation']
            : [];
        $currentComparable = [
            'operation_key' => $operation['operation_key'],
            'operation_reference' => $operation['operation_reference'],
            'operation_type' => $operation['operation_type'],
            'reason_code' => $operation['reason_code'],
            'requires_confirmation' => $operation['requires_confirmation'],
            'target_router_id' => $operation['target_router_id'],
            'customer_id' => $operation['customer_id'],
        ];
        $existingComparable = array_intersect_key($existingOperation, $currentComparable);
        if ($persist && $existingComparable !== $currentComparable) {
            $meta['migration_operation'] = $operation;
            $migration->meta = $meta;
            $migration->save();
        } elseif (isset($existingOperation['classified_at'])) {
            $operation['classified_at'] = $existingOperation['classified_at'];
        }

        return $operation;
    }

    /** @return array{allowed: bool, reason: string, operation: array<string, mixed>} */
    public function operationExecutionGuard(CustomerMigration $migration): array
    {
        if (MigrationNormalizer::isHuaweiOltOnlyMigration($migration)) {
            $approval = (array) data_get(MigrationNormalizer::meta($migration), 'device_action_approval', []);

            return ($approval['status'] ?? null) === 'dry_run_ready'
                ? ['allowed' => true, 'reason' => '', 'operation' => ['operation_type' => 'huawei_olt_only']]
                : ['allowed' => false, 'reason' => 'Huawei OLT-only belum memiliki dry-run/approval.', 'operation' => ['operation_type' => 'huawei_olt_only']];
        }

        $operation = $this->classifyAndPersistOperation($migration);
        if ($operation['operation_type'] === MigrationOperationClassifier::DUPLICATE_MIGRATION) {
            return ['allowed' => false, 'reason' => 'operasi yang sama sudah selesai/tercatat; tidak ada aktivasi ulang.', 'operation' => $operation];
        }
        if ($operation['operation_type'] === MigrationOperationClassifier::PENDING_TECHNICIAN_CONFIRMATION) {
            return ['allowed' => false, 'reason' => 'replacement perangkat belum dikonfirmasi teknisi.', 'operation' => $operation];
        }
        if (in_array($operation['operation_type'], [
            MigrationOperationClassifier::CONFLICT,
            MigrationOperationClassifier::NOT_FOUND,
        ], true)) {
            return ['allowed' => false, 'reason' => 'identitas customer/router/PPPoE/SN belum valid atau conflict.', 'operation' => $operation];
        }
        if (($operation['requires_confirmation'] ?? false)
            && data_get($migration->meta, 'replacement_confirmation.status') !== 'confirmed') {
            return ['allowed' => false, 'reason' => 'konfirmasi teknisi replacement belum tersedia.', 'operation' => $operation];
        }

        $expiresAt = data_get($migration->meta, 'replacement_confirmation.expires_at');
        if (($operation['requires_confirmation'] ?? false) && is_string($expiresAt) && $expiresAt !== '') {
            try {
                if (Carbon::parse($expiresAt)->isPast()) {
                    return ['allowed' => false, 'reason' => 'konfirmasi teknisi replacement sudah kedaluwarsa.', 'operation' => $operation];
                }
            } catch (\Throwable) {
                return ['allowed' => false, 'reason' => 'waktu konfirmasi teknisi replacement tidak valid.', 'operation' => $operation];
            }
        }

        return ['allowed' => true, 'reason' => '', 'operation' => $operation];
    }
}
