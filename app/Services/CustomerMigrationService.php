<?php

namespace App\Services;

use App\Exceptions\HuaweiOltSessionBusyException;
use App\Exceptions\LockBusyException;
use App\Exceptions\PsbLockBusyException;
use App\Http\Controllers\OltC300Controller;
use App\Jobs\ExecuteCustomerMigrationWaJob;
use App\Models\Customer;
use App\Models\CustomerMigration;
use App\Models\DeviceActionLog;
use App\Models\Olt;
use App\Models\OltOnu;
use App\Models\PsbDraft;
use App\Models\Router;
use App\Models\Setting;
use App\Models\User;
use App\Services\CustomerOnu\CustomerOnuFieldSyncService;
use App\Services\Migration\CustomerMigrationWaMessages;
use App\Services\Migration\MigrationDeviceActionPlanner;
use App\Services\Migration\MigrationDeviceLedger;
use App\Services\Migration\MigrationEvidenceReader;
use App\Services\Migration\MigrationGuidedPreviewRefresh;
use App\Services\Migration\MigrationGuidedWaFlow;
use App\Services\Migration\MigrationHuaweiOltOnlyIntake;
use App\Services\Migration\MigrationNormalizer;
use App\Services\Migration\MigrationPartialExecution;
use App\Services\Migration\MigrationPlanBuilder;
use App\Services\Migration\MigrationResendGuard;
use App\Services\Migration\MigrationSeams;
use App\Services\Migration\MigrationSnCorrection;
use App\Services\Migration\MigrationTargetResolver;
use App\Services\Migration\MigrationWaSenders;
use App\Services\Migration\MigrationWriteGate;
use App\Services\Olt\OltProvisioningGuard;
use App\Support\CacheLockWait;
use App\Support\MigrationLockMessages;
use App\Support\OcrBudget;
use App\Support\SensitiveDataRedactor;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CustomerMigrationService
{
    /** Max total OCR time for the photos of one migration message (job timeout is 420 s). */
    private const PHOTO_OCR_BATCH_MAX_SECONDS = 300;

    public function __construct(
        protected CustomerMigrationMessageParser $parser,
        protected PsbOrchestratorService $psb,
        protected MikrotikService $mikrotik,
        protected OltZteC300Service $zte,
        protected GenieAcsService $genieAcs,
        protected ProductionControlPlaneService $controlPlane,
        protected GuidedMigrationLookupService $guidedLookup,
        protected MigrationOperationClassifier $operationClassifier,
        protected HuaweiOltOnlyProvisioningService $huaweiOltOnly,
    ) {}

    /**
     * Read-only uncfg listing cached per OLT for one request so SN typo hints
     * never open a second SSH session.
     *
     * @var array<int, list<array<string, mixed>>>
     */
    protected array $uncfgCache = [];

    /** One text builder per service instance. Never static, never in the container. */
    private ?CustomerMigrationWaMessages $waMessages = null;

    /** One evidence reader per service instance. Never static, never in the container. */
    private ?MigrationEvidenceReader $evidenceReader = null;

    /** Late-bound closure to partialExecutionState, so an override there still wins inside the texts. */
    private function texts(): CustomerMigrationWaMessages
    {
        return $this->waMessages ??= new CustomerMigrationWaMessages(
            fn (CustomerMigration $migration): ?array => $this->partialExecutionState($migration),
        );
    }

    private function evidence(): MigrationEvidenceReader
    {
        return $this->evidenceReader ??= new MigrationEvidenceReader;
    }

    /** One seams object per service instance: closures read psb/parser at call time. */
    private ?MigrationSeams $seams = null;

    /** One target resolver per service instance. Never static, never in the container. */
    private ?MigrationTargetResolver $targetResolver = null;

    /** One plan builder per service instance. Never static, never in the container. */
    private ?MigrationPlanBuilder $planBuilder = null;

    /** One device ledger helper per service instance. Stateless. Never static, never in the container. */
    private ?MigrationDeviceLedger $deviceLedger = null;

    /** One partial-execution helper per service instance. Never static, never in the container. */
    private ?MigrationPartialExecution $partialExecution = null;

    /** One device-action planner per service instance. Never static, never in the container. */
    private ?MigrationDeviceActionPlanner $devicePlanner = null;

    private function seams(): MigrationSeams
    {
        return $this->seams ??= new MigrationSeams(
            psb: fn (): PsbOrchestratorService => $this->psb,
            parser: fn (): CustomerMigrationMessageParser => $this->parser,
            controlPlane: fn (): ProductionControlPlaneService => $this->controlPlane,
            huaweiOltOnly: fn (): HuaweiOltOnlyProvisioningService => $this->huaweiOltOnly,
            operationClassifier: fn (): MigrationOperationClassifier => $this->operationClassifier,
            resolveCustomer: fn (CustomerMigration $migration): ?Customer => $this->resolveCustomer($migration),
            verifyExistingPppoeSecret: fn (Customer $customer, Router $router): array => $this->verifyExistingPppoeSecret($customer, $router),
            verifyTargetMigrationProfile: fn (Router $router, string $desired): array => $this->verifyTargetMigrationProfile($router, $desired),
            huaweiOltOnlyWriteFlags: fn (): array => $this->huaweiOltOnlyWriteFlags(),
            uncfgRows: fn (int $oltId): array => $this->uncfgCache[$oltId] ?? [],
            snTypoHints: fn (CustomerMigration $migration, Olt $olt, array $uncfgOnus): array => $this->snCorrection()->snTypoHints($migration, $olt, $uncfgOnus),
            canModifyMigrationFromWa: fn (CustomerMigration $migration, array $payload): bool => $this->senders()->canModifyMigrationFromWa($migration, $payload),
            waSenderKeys: fn (array $payload): array => $this->senders()->waSenderKeys($payload),
            prepareDeviceActionApprovalLocked: fn (CustomerMigration $migration, ?User $actor, string $reason): CustomerMigration => $this->planner()->prepareDeviceActionApprovalLocked($migration, $actor, $reason),
            guidedLookup: fn (): GuidedMigrationLookupService => $this->guidedLookup,
            syncCustomerAfterNewOnu: function (CustomerMigration $migration, Customer $customer, array $extra = []): void {
                $this->syncCustomerAfterNewOnu($migration, $customer, $extra);
            },
            attachStoredInboundImages: function (CustomerMigration $migration, array $imagePaths, string $hint = ''): void {
                $this->attachStoredInboundImages($migration, $imagePaths, $hint);
            },
            readUncfgOnus: fn (Olt $olt): array => $this->readUncfgOnus($olt),
        );
    }

    private function ledgers(): MigrationDeviceLedger
    {
        return $this->deviceLedger ??= new MigrationDeviceLedger;
    }

    private function partials(): MigrationPartialExecution
    {
        return $this->partialExecution ??= new MigrationPartialExecution($this, $this->seams(), $this->ledgers(), $this->texts());
    }

    private function planner(): MigrationDeviceActionPlanner
    {
        return $this->devicePlanner ??= new MigrationDeviceActionPlanner(
            $this->seams(), $this->ledgers(), $this->partials(), $this->resolver(), $this->plans(), $this->evidence(), $this->texts(),
        );
    }

    private function resolver(): MigrationTargetResolver
    {
        return $this->targetResolver ??= new MigrationTargetResolver($this->seams(), $this->evidence(), $this->plans());
    }

    private function plans(): MigrationPlanBuilder
    {
        return $this->planBuilder ??= new MigrationPlanBuilder;
    }

    /** One sender helper per service instance. Never static, never in the container. */
    private ?MigrationWaSenders $waSenders = null;

    /** One guided preview refresh per service instance. Never static, never in the container. */
    private ?MigrationGuidedPreviewRefresh $guidedPreviewRefresh = null;

    /** One SN correction helper per service instance. Never static, never in the container. */
    private ?MigrationSnCorrection $snCorrector = null;

    /** One Huawei OLT-only intake per service instance. Never static, never in the container. */
    private ?MigrationHuaweiOltOnlyIntake $huaweiIntaker = null;

    /** One guided WA flow per service instance. Never static, never in the container. */
    private ?MigrationGuidedWaFlow $guidedWaFlow = null;

    private function senders(): MigrationWaSenders
    {
        return $this->waSenders ??= new MigrationWaSenders($this->seams());
    }

    private function guidedRefresh(): MigrationGuidedPreviewRefresh
    {
        return $this->guidedPreviewRefresh ??= new MigrationGuidedPreviewRefresh($this, $this->seams(), $this->texts(), $this->ledgers(), $this->partials());
    }

    private function snCorrection(): MigrationSnCorrection
    {
        return $this->snCorrector ??= new MigrationSnCorrection(
            $this, $this->seams(), $this->senders(), $this->guidedRefresh(), $this->partials(), $this->ledgers(), $this->planner(), $this->resolver(), $this->evidence(), $this->texts(),
        );
    }

    private function huaweiIntake(): MigrationHuaweiOltOnlyIntake
    {
        return $this->huaweiIntaker ??= new MigrationHuaweiOltOnlyIntake($this, $this->seams(), $this->senders(), $this->resolver(), $this->texts());
    }

    private function guidedFlow(): MigrationGuidedWaFlow
    {
        return $this->guidedWaFlow ??= new MigrationGuidedWaFlow(
            $this, $this->seams(), $this->senders(), $this->guidedRefresh(), $this->snCorrection(), $this->huaweiIntake(), $this->partials(), $this->ledgers(), $this->planner(), $this->resolver(), $this->texts(),
        );
    }

    /** @param array<string, mixed> $payload @param array<string, mixed> $parsed */
    protected function migrationOperationLockKey(array $payload, array $parsed): string
    {
        $pppoe = MigrationNormalizer::normalizeUsername((string) ($parsed['pppoe_user'] ?? ''));
        $newSn = MigrationNormalizer::normalizeSerial((string) ($parsed['new_sn'] ?? ''));
        $identity = $pppoe !== '' && $newSn !== ''
            ? [
                'group' => trim((string) ($payload['group_jid'] ?? '')),
                'pppoe' => $pppoe,
                'new_sn' => $newSn,
                'target_router' => strtolower(trim((string) ($parsed['new_router_field'] ?? $parsed['router_field'] ?? ''))),
            ]
            : [
                'group' => trim((string) ($payload['group_jid'] ?? '')),
                'message_id' => trim((string) ($payload['message_id'] ?? '')),
                'name' => strtolower(trim((string) ($parsed['full_name'] ?? ''))),
                'new_sn' => $newSn,
            ];

        return 'customer-migration-operation-'.hash('sha256', json_encode($identity, JSON_UNESCAPED_SLASHES) ?: serialize($identity));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handleInbound(array $payload): CustomerMigration
    {
        if (! Schema::hasTable('customer_migrations')) {
            $group = (string) ($payload['group_jid'] ?? '');
            if ($group !== '') {
                $this->reply($group, 'Fitur #migrasi belum siap: tabel customer_migrations belum ada. Jalankan migrate dulu.');
            }

            return new CustomerMigration(['status' => CustomerMigration::STATUS_FAILED]);
        }

        $groupJid = (string) ($payload['group_jid'] ?? '');
        $text = (string) ($payload['text'] ?? '');
        $parsed = $this->parser->parse($text);

        if (! ($parsed['is_migrasi'] ?? false)) {
            return new CustomerMigration(['status' => 'ignored']);
        }

        if (MigrationNormalizer::boolSetting('migration_maintenance_mode', false)
            && ! ($parsed['is_help'] ?? false)
            && ! ($parsed['is_status'] ?? false)
            && ! ($parsed['is_cek'] ?? false)
            && ! ($parsed['is_batal'] ?? false)) {
            $this->reply($groupJid, "Maintenance migrasi sementara: proses perangkat dihentikan agar aman.\nPSB tetap berjalan normal. Cek status: #migrasi cek #ID");

            return new CustomerMigration(['status' => 'maintenance']);
        }

        if ($parsed['is_help'] ?? false) {
            $this->reply($groupJid, $this->helpMessage());

            return new CustomerMigration(['status' => 'help']);
        }

        if ($parsed['is_status'] ?? false) {
            $this->reply($groupJid, $this->texts()->todaySummary());

            return new CustomerMigration(['status' => 'status']);
        }

        if ($parsed['is_ktp'] ?? false) {
            return $this->guidedFlow()->attachMigrationKtpFromWa($payload, $parsed);
        }

        if ($parsed['is_phone_update'] ?? false) {
            return $this->guidedFlow()->updateMigrationPhoneFromWa($payload, $parsed);
        }

        if ($parsed['is_sn_update'] ?? false) {
            $migration = $this->senders()->findMigrationForCommand($groupJid, $parsed);
            if (! $migration) {
                $this->reply($groupJid, 'Migrasi tidak ditemukan. Pakai: #migrasi sn #ID <SN_BENAR>');

                return new CustomerMigration(['status' => CustomerMigration::STATUS_FAILED]);
            }

            return $this->snCorrection()->correctMigrationSnFromWa($migration, (string) ($parsed['sn_update_value'] ?? ''), $payload, 'sn_command');
        }

        if ($parsed['is_eksekusi'] ?? false) {
            return $this->dispatchWaExecution($payload, (int) ($parsed['migration_id'] ?? 0), false);
        }

        if ($parsed['is_konfirmasi'] ?? false) {
            if ($this->guidedLookup->canUseWa($payload)) {
                return $this->guidedFlow()->confirmGuidedSelectionFromWa($payload, $parsed);
            }
            $this->reply($groupJid, 'Konfirmasi migrasi hanya tersedia di grup aktivasi yang diizinkan.');

            return new CustomerMigration(['status' => CustomerMigration::STATUS_FAILED]);
        }

        if ($parsed['is_pilih'] ?? false) {
            if ($this->guidedLookup->canUseWa($payload)) {
                return $this->guidedFlow()->selectGuidedCandidateFromWa($payload, $parsed);
            }
            $migration = $this->senders()->findMigrationForCommand($groupJid, $parsed);
            $this->reply($groupJid, $this->candidateSelectionPreviewMessage($migration, (int) ($parsed['candidate_number'] ?? 0)));

            return $migration ?? new CustomerMigration(['status' => 'preview']);
        }

        if ($parsed['is_cek'] ?? false) {
            $migration = $this->senders()->findMigrationForCommand($groupJid, $parsed);
            $routerCredential = $migration && $migration->status === CustomerMigration::STATUS_SYNCED
                && ! MigrationNormalizer::isHuaweiOltOnlyMigration($migration)
                ? $this->readVerifiedMigrationPassword($migration)
                : null;
            $this->reply($groupJid, $migration ? $this->texts()->statusMessage($migration, $routerCredential) : 'Migrasi tidak ditemukan. Pakai: #migrasi cek #ID');

            return $migration ?? new CustomerMigration(['status' => CustomerMigration::STATUS_FAILED]);
        }

        if ($this->guidedLookup->canUseWa($payload)) {
            if ($parsed['is_batal'] ?? false) {
                return $this->guidedFlow()->cancelGuidedSelectionFromWa($payload, $parsed);
            }
            if ($parsed['is_lanjut'] ?? false) {
                $this->reply($groupJid, $this->texts()->guidedPreviewBlockedMessage('lanjut'));

                return new CustomerMigration(['status' => 'preview']);
            }

            // Site-aware: a Krian/RA `PPPoE + SN baru` short form resolves to a
            // full_olt site and must use the existing C300/FiberHome uncfg path
            // below. The guided lookup needs a customer discriminator and the
            // Huawei adapter only accepts the Huawei site, so neither may claim
            // a non-Huawei short form.
            if (! $this->shouldUseC300UncfgPath($parsed)) {
                return $this->guidedFlow()->createGuidedDraftFromWa($payload, $parsed);
            }
        }

        if ($parsed['is_batal'] ?? false) {
            $migration = $this->senders()->findMigrationForCommand($groupJid, $parsed);
            if (! $migration) {
                $this->reply($groupJid, 'Migrasi tidak ditemukan. Pakai: #migrasi batal #ID');

                return new CustomerMigration(['status' => CustomerMigration::STATUS_FAILED]);
            }
            if ($guarded = $this->partials()->guardPartialCancelFromWa($migration, $payload, $parsed)) {
                return $guarded;
            }
            $migration->status = CustomerMigration::STATUS_CANCELLED;
            $migration->last_error = 'Dibatalkan via WA';
            $migration->save();
            $this->reply($groupJid, "Migrasi #{$migration->id} dibatalkan. ONU/secret tidak dihapus otomatis.");

            return $migration;
        }

        $operationLock = Cache::lock($this->migrationOperationLockKey($payload, $parsed), 60);
        if (! CacheLockWait::acquire($operationLock, 5)) {
            $this->reply($groupJid, MigrationLockMessages::sameOperationBusy());

            return new CustomerMigration(['status' => CustomerMigration::STATUS_FAILED]);
        }

        try {
            $messageId = trim((string) ($payload['message_id'] ?? ''));
            if ($messageId !== '') {
                $existing = CustomerMigration::query()->where('message_id', $messageId)->first();
                if ($existing) {
                    return $existing;
                }
            }

            $migration = $this->findOrCreateMigration($payload, $parsed);
            if ($migration->exists && $this->partialExecutionState($migration->fresh() ?? $migration) !== null) {
                return $this->snCorrection()->resendOverPartial($migration->fresh() ?? $migration, $parsed, $payload);
            }
            if ($migration->getAttribute('_stable_duplicate') === true) {
                $this->reply(
                    $groupJid,
                    "Migrasi #{$migration->id} sudah memiliki operation reference ".(string) data_get($migration->meta, 'migration_operation.operation_reference', 'yang sama').". Tidak ada aktivasi ulang. Cek: #migrasi cek #{$migration->id}"
                );

                return $migration;
            }
            if ($migration->exists && (string) $migration->source === 'guided_lookup_wa') {
                // A guided draft never has Excel evidence: a re-sent short form
                // must not overwrite it or run the Excel review. A different SN
                // goes through the validated correction; anything else only
                // reports the guided state.
                $migration = $migration->fresh() ?? $migration;
                if (($sn = (new MigrationResendGuard)->differentSn($migration, $parsed)) !== null) {
                    return $this->snCorrection()->correctMigrationSnFromWa($migration, $sn, $payload, 'resend');
                }

                return $this->prepareReviewDraft($migration, (bool) ($parsed['is_lanjut'] ?? false));
            }
            $this->applyPayload($migration, $payload, $parsed);

            if ($parsed['is_lanjut'] ?? false) {
                $this->reply($groupJid, "Migrasi #{$migration->id} diperbarui untuk review. Belum ada action OLT/MikroTik/PPPoE.");
            }

            return $this->prepareReviewDraft($migration->fresh() ?? $migration, (bool) ($parsed['is_lanjut'] ?? false));
        } finally {
            try {
                $operationLock->release();
            } catch (\Throwable) {
            }
        }
    }

    public function processMigration(CustomerMigration $migration): CustomerMigration
    {
        return $this->prepareReviewDraft($migration->fresh() ?? $migration, true);
    }

    public function prepareReviewDraft(CustomerMigration $migration, bool $manualContinue = false): CustomerMigration
    {
        $groupJid = (string) $migration->group_jid;
        if ($migration->status === CustomerMigration::STATUS_CANCELLED) {
            return $migration;
        }

        $lock = Cache::lock('customer-migration-review-'.$migration->id, 60);
        if (! CacheLockWait::acquire($lock, 5)) {
            $this->reply($groupJid, MigrationLockMessages::reviewBusy((int) $migration->id));

            return $migration;
        }

        try {
            $migration = $migration->fresh() ?? $migration;
            if (MigrationNormalizer::migrationAlreadySynced($migration)) {
                return $migration;
            }

            // Device writes already happened: a review/retry must never reset
            // this to a "Tidak ada action perangkat" draft (#1102). Keep the
            // explicit partial state and tell the group how to continue.
            if ($this->partialExecutionState($migration) !== null) {
                $meta = MigrationNormalizer::meta($migration);
                $meta['timeline'] = MigrationNormalizer::appendTimeline($meta['timeline'] ?? [], 'review_skipped_partial_execution', [
                    'manual_continue' => $manualContinue,
                ]);
                $migration->meta = $meta;
                $migration->save();

                return $this->partials()->markPartiallyExecuted($migration, $manualContinue ? 'retry_review_blocked' : 'review_blocked');
            }

            // A guided draft never has Excel evidence. The Excel review would
            // overwrite the guided selection with `excel_confirm_missing`, so
            // every caller (web Retry, WA photo, re-sent short form) stops here.
            if ((string) $migration->source === 'guided_lookup_wa') {
                return $this->guidedRefresh()->skipReviewForGuidedDraft($migration, $manualContinue);
            }

            $customer = $this->resolveCustomer($migration);
            if ($customer) {
                $this->primeMigrationFromCustomer($migration, $customer);
                $migration = $migration->fresh() ?? $migration;
            }

            $operation = $this->planner()->classifyAndPersistOperation($migration, $customer);
            $review = $this->evidence()->reviewMigrationDraft($migration, $customer);
            $review['operation'] = $operation;
            if ($operation['operation_type'] === MigrationOperationClassifier::DUPLICATE_MIGRATION) {
                $review['match_status'] = 'duplicate_migration';
                $review['reasons'][] = $operation['reason_code'];
            } elseif (in_array($operation['operation_type'], [
                MigrationOperationClassifier::HARDWARE_REPLACEMENT,
                MigrationOperationClassifier::PENDING_TECHNICIAN_CONFIRMATION,
            ], true)) {
                $review['match_status'] = 'pending_technician_confirmation';
                $review['reasons'][] = $operation['reason_code'];
            }
            $review['reasons'] = array_values(array_unique($review['reasons']));
            $meta = MigrationNormalizer::meta($migration);
            $meta['migration_review'] = $review;
            $meta['timeline'] = MigrationNormalizer::appendTimeline($meta['timeline'] ?? [], 'draft_pending_review', [
                'manual_continue' => $manualContinue,
                'match_status' => $review['match_status'],
                'reasons' => $review['reasons'],
            ]);
            $meta['before_snapshot'] = $customer ? $this->customerSnapshot($customer) : null;
            $snapshotRouter = $customer ? $this->resolveRouterForMigration($migration, $customer) : null;
            $snapshotOlt = ($customer && $snapshotRouter) ? $this->resolveOltForMigration($migration, $customer, $snapshotRouter) : null;
            $meta['proposed_after_snapshot'] = $this->proposedCustomerSnapshot($migration, $customer, $snapshotOlt);
            $meta['device_write'] = false;
            $meta['device_write_blocked_reason'] = 'Sprint 4B-D WA migration is pending review only.';
            $migration->meta = $meta;
            $migration->status = CustomerMigration::STATUS_NEEDS_REVIEW;
            $migration->last_error = $review['message'];
            $migration->save();

            $this->reply($groupJid, $this->texts()->reviewMessage($migration->fresh() ?? $migration));

            return $migration->fresh() ?? $migration;
        } finally {
            try {
                $lock->release();
            } catch (\Throwable) {
            }
        }
    }

    /**
     * Web Retry for a WA guided draft: refresh the candidate list only (no Excel
     * review). The group gets a WA message only when the candidate order changed.
     *
     * @return array{handled: bool, ok: bool, message: string}
     */
    public function retryGuidedFromWeb(CustomerMigration $migration): array
    {
        return $this->guidedRefresh()->retryGuidedFromWeb($migration);
    }

    /**
     * Legacy executor kept for later approval sprint. Sprint 4B-D must not call it.
     */
    protected function executeMigration(CustomerMigration $migration): CustomerMigration
    {
        $groupJid = (string) $migration->group_jid;
        $lock = Cache::lock('customer-migration-'.$migration->id, 420);
        if (! CacheLockWait::acquire($lock, 5)) {
            // Thrown (not replied): executeApprovedDeviceAction must revert its
            // `executing` ledger. The caller reports the user-safe message.
            throw new LockBusyException(MigrationLockMessages::deviceBusyExecute((int) $migration->id));
        }

        try {
            return $this->processMigrationLocked($migration->fresh() ?? $migration);
        } finally {
            try {
                $lock->release();
            } catch (\Throwable) {
            }
        }
    }

    public function prepareDeviceActionApproval(CustomerMigration $migration, ?User $actor, string $reason): CustomerMigration
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 8) {
            throw new \InvalidArgumentException('Alasan approval minimal 8 karakter.');
        }

        $lock = Cache::lock('customer-migration-device-'.$migration->id, 120);
        if (! CacheLockWait::acquire($lock, 5)) {
            throw new LockBusyException(MigrationLockMessages::deviceBusyConfirm((int) $migration->id));
        }

        try {
            return $this->planner()->prepareDeviceActionApprovalLocked($migration, $actor, $reason);
        } finally {
            try {
                $lock->release();
            } catch (\Throwable) {
            }
        }
    }

    public function executeApprovedDeviceAction(CustomerMigration $migration, ?User $actor, string $reason, bool $scopedWaCanary = false): CustomerMigration
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 8) {
            throw new \InvalidArgumentException('Alasan eksekusi minimal 8 karakter.');
        }

        $lock = Cache::lock('customer-migration-device-'.$migration->id, 420);
        if (! CacheLockWait::acquire($lock, 5)) {
            throw new LockBusyException(MigrationLockMessages::deviceBusyExecute((int) $migration->id));
        }

        $executionStarted = false;
        try {
            $migration = $migration->fresh() ?? $migration;
            if (MigrationNormalizer::migrationAlreadySynced($migration)) {
                throw new \RuntimeException("Migrasi #{$migration->id} sudah selesai; tidak dieksekusi ulang.");
            }
            $meta = MigrationNormalizer::meta($migration);
            $approval = is_array($meta['device_action_approval'] ?? null) ? $meta['device_action_approval'] : [];
            $this->ledgers()->assertApprovalLedgerUsable($migration, $approval);

            if (MigrationNormalizer::isHuaweiOltOnlyMigration($migration)) {
                return $this->executeHuaweiOltOnlyDeviceAction($migration, $actor, $reason, $approval);
            }

            $flags = $this->planner()->migrationWriteFlags();
            if (! $this->migrationWritesAllowed($flags, $scopedWaCanary)) {
                $ledger = DeviceActionLog::query()->find((int) $approval['ledger_id']);
                if ($ledger && in_array($ledger->status, ['requested', 'dry_run', 'planned'], true)) {
                    $ledger->status = 'blocked';
                    $ledger->result = [
                        'blocked_reason' => 'kill_switch_off',
                        'write_flags' => $flags,
                        'actor_id' => $actor?->id,
                        'reason' => $reason,
                        'blocked_at' => now()->toIso8601String(),
                    ];
                    $ledger->save();
                }

                $meta['device_action_approval'] = array_merge($approval, [
                    'status' => 'blocked_kill_switch',
                    'blocked_by' => $actor?->id,
                    'blocked_reason' => 'migration/OLT/MikroTik/GenieACS write flag belum ON semua.',
                    'write_flags' => $flags,
                    'scoped_wa_canary' => $scopedWaCanary,
                    'blocked_at' => now()->toIso8601String(),
                ]);
                $meta['timeline'] = MigrationNormalizer::appendTimeline($meta['timeline'] ?? [], 'device_action_blocked', [
                    'reason' => 'kill_switch_off',
                    'write_flags' => $flags,
                ]);
                $migration->meta = $meta;
                $migration->last_error = 'Execute diblokir kill switch. Nyalakan flag migration + OLT/MikroTik/GenieACS secara sengaja jika benar-benar siap.';
                $migration->save();

                return $migration->fresh() ?? $migration;
            }

            $ledger = DeviceActionLog::query()->find((int) $approval['ledger_id']);
            if (! $ledger) {
                throw new \RuntimeException('Ledger approval migrasi tidak ditemukan; ulangi dry-run sebelum execute.');
            }
            if (in_array($ledger->status, ['executed', 'verified'], true)) {
                // Never a silent no-op: explain what already ran, prepare a
                // continuation dry-run for the remaining steps only, and ask
                // for an explicit second execute.
                return $this->partials()->handleAlreadyExecutedApproval($migration, $ledger, $actor, $scopedWaCanary);
            }
            if ($ledger->status === 'executing') {
                throw new \RuntimeException("Migrasi #{$migration->id} memiliki eksekusi device yang belum direkonsiliasi.");
            }

            $before = $this->deviceActionPreflight($migration);
            if (($before['mikrotik_secret']['found'] ?? null) !== true) {
                throw new \RuntimeException(($before['continuation'] ?? false)
                    ? 'Secret PPPoE di router target belum terverifikasi; lanjutan migrasi ditahan aman.'
                    : 'Secret PPPoE di router lama belum terverifikasi; migrasi ditahan aman.');
            }
            (new MigrationResendGuard)->assertContinuationOlt($migration, $ledger, $before);
            $ledger->status = 'executing';
            $ledger->executed_at = now();
            $ledger->save();
            $executionStarted = true;

            $result = $this->executeMigration($migration);
            $after = $this->deviceActionPreflight($result->fresh() ?? $result);

            $synced = $result->status === CustomerMigration::STATUS_SYNCED;
            $ledger->status = $synced ? 'verified' : 'executed';
            $ledger->result = [
                'before' => $before,
                'after' => $after,
                'migration_status' => $result->status,
                'actor_id' => $actor?->id,
                'reason' => $reason,
                'authorization_mode' => $scopedWaCanary ? 'wa_only_limited_canary' : 'control_plane_flags',
                'continuation' => (bool) ($before['continuation'] ?? false),
                'steps_done' => $this->ledgers()->executedStepSummary($result->fresh() ?? $result),
            ];
            $ledger->verified_at = $synced ? now() : null;
            $ledger->save();

            // The approval is consumed: a later execute must never treat this
            // ledger as a ready dry-run again.
            $result = $result->fresh() ?? $result;
            $resultMeta = MigrationNormalizer::meta($result);
            $resultMeta['device_action_approval'] = array_merge((array) ($resultMeta['device_action_approval'] ?? $approval), [
                'status' => $synced ? 'verified' : 'executed',
                'executed_ledger_id' => $ledger->id,
                'executed_at' => now()->toIso8601String(),
            ]);
            $result->meta = $resultMeta;
            $result->save();

            if (! $synced) {
                if ($this->partialExecutionState($result) !== null) {
                    $result = $this->partials()->markPartiallyExecuted($result, 'execute_incomplete', (string) $result->last_error);
                } else {
                    $this->replyIfWaitingForNewSn($result);
                }
            }

            return $result->fresh() ?? $result;
        } catch (LockBusyException $e) {
            // B6: a lock was busy BEFORE any device write (executeMigration /
            // processMigrationLocked only throw it pre-write; a busy lock after
            // the MikroTik step becomes a partial state instead). Put the ledger
            // back to dry_run so a single resend works, not a false
            // manual_reconciliation.
            if ($executionStarted) {
                $this->ledgers()->revertLedgerToDryRun((int) ($approval['ledger_id'] ?? 0));
            }

            throw $e;
        } catch (\Throwable $e) {
            if ($executionStarted) {
                $this->ledgers()->markDeviceActionExecutionFailed($migration, $approval, $actor, $e);
                try {
                    $failed = $migration->fresh() ?? $migration;
                    if ($this->partialExecutionState($failed) !== null) {
                        $this->partials()->markPartiallyExecuted($failed, 'execute_exception', (string) $failed->last_error, false);
                    }
                } catch (\Throwable) {
                }
            }

            throw $e;
        } finally {
            try {
                $lock->release();
            } catch (\Throwable) {
            }
        }
    }

    /** @param array<string, mixed> $approval */
    protected function executeHuaweiOltOnlyDeviceAction(CustomerMigration $migration, ?User $actor, string $reason, array $approval): CustomerMigration
    {
        $flags = $this->huaweiOltOnlyWriteFlags();
        if (! $flags['migration_actions'] || ! $flags['olt'] || ! $flags['genieacs'] || $flags['global_read_only']) {
            $ledger = DeviceActionLog::query()->find((int) ($approval['ledger_id'] ?? 0));
            if ($ledger && in_array($ledger->status, ['requested', 'dry_run', 'planned'], true)) {
                $ledger->status = 'blocked';
                $ledger->result = [
                    'blocked_reason' => 'huawei_olt_only_write_flags_off',
                    'write_flags' => $flags,
                    'actor_id' => $actor?->id,
                    'reason' => $reason,
                    'blocked_at' => now()->toIso8601String(),
                ];
                $ledger->save();
            }

            $meta = MigrationNormalizer::meta($migration);
            $meta['device_action_approval'] = array_merge($approval, [
                'status' => 'blocked_kill_switch',
                'blocked_reason' => 'migration/OLT/GenieACS write flag belum ON atau global read-only aktif.',
                'write_flags' => $flags,
                'blocked_at' => now()->toIso8601String(),
            ]);
            $meta['timeline'] = MigrationNormalizer::appendTimeline($meta['timeline'] ?? [], 'huawei_olt_only_device_action_blocked', [
                'reason' => 'write_flags_off',
                'write_flags' => $flags,
            ]);
            $migration->meta = $meta;
            $migration->last_error = 'Huawei OLT-only execute diblokir kill switch/global read-only.';
            $migration->save();

            return $migration->fresh() ?? $migration;
        }

        $before = $this->huaweiOltOnly->preflight($migration);
        if (($before['eligible'] ?? false) !== true) {
            throw new \RuntimeException('Huawei OLT-only preflight berubah: '.implode(', ', (array) ($before['reasons'] ?? ['preflight_failed'])));
        }
        $approvedHash = (string) data_get($migration->meta, 'device_action_preflight.plan.command_hash', '');
        $currentHash = (string) data_get($before, 'plan.command_hash', '');
        if ($approvedHash === '' || ! hash_equals($approvedHash, $currentHash)) {
            throw new \RuntimeException('Command plan Huawei berubah; ulangi konfirmasi dan dry-run sebelum execute.');
        }

        $oltId = (int) data_get($before, 'olt.id', 0);
        $olt = $oltId > 0 ? Olt::query()->find($oltId) : null;
        if (! $olt) {
            throw new \RuntimeException('OLT Huawei target tidak ditemukan saat execute.');
        }
        $ledger = DeviceActionLog::query()->find((int) ($approval['ledger_id'] ?? 0));
        if (! $ledger) {
            throw new \RuntimeException('Ledger approval Huawei tidak ditemukan; ulangi dry-run sebelum execute.');
        }
        if ($ledger && in_array($ledger->status, ['executed', 'verified'], true)) {
            return $migration;
        }
        if ($ledger && $ledger->status === 'executing') {
            throw new \RuntimeException("Migrasi #{$migration->id} memiliki eksekusi Huawei yang belum direkonsiliasi.");
        }
        // Cross-router: decided before the marker. A copy already verified is only re-read (C300 parity).
        $copy = ($before['cross_router'] ?? false) === true ? $this->partials()->huaweiCrossRouterCopyStep($migration, $before) : null;
        $executionStarted = false;
        if ($ledger) {
            $ledger->status = 'executing';
            $ledger->executed_at = now();
            $ledger->save();
            $executionStarted = true;
        }

        $mikrotikWritten = false;
        try {
            // Cross-router router replacement (e.g. Bumiayu -> Gadang): copy the PPPoE secret (same
            // username/password) to the target router BEFORE the OLT write, unless it is already done.
            if ($copy !== null && ! $copy['done']) {
                $this->applyMigrationPppoeSecret($migration, $copy['customer'], $copy['router']);
                $mikrotikWritten = true;
                $migration = $migration->fresh() ?? $migration;
            }

            $guardApproval = app(OltProvisioningGuard::class)->issueInternalApproval(
                $olt,
                'customer-migration-huawei-olt-only',
                (string) $migration->id,
            );
            $result = $this->huaweiOltOnly->executePlan($olt, (array) $before['plan'], $guardApproval);
            if (($result['device_write'] ?? false) !== true) {
                if ($ledger) {
                    $ledger->status = 'dry_run';
                    $ledger->result = [
                        'mode' => 'dry_run',
                        'device_write' => false,
                        'reason' => 'OLT provisioning guard remains dry-run.',
                        'actor_id' => $actor?->id,
                    ] + ($copy !== null ? ['mikrotik_step' => $copy['done'] ? 'already_verified' : 'written'] : []);
                    $ledger->save();
                }
                $migration->last_error = 'Huawei OLT provisioning guard masih dry-run; tidak ada device write.';
                $migration->save();

                return $migration->fresh() ?? $migration;
            }

            $tag = $this->genieAcs->ensureTagAfterProvision(
                MigrationNormalizer::normalizeSerial((string) $migration->new_sn),
                MigrationNormalizer::normalizeUsername((string) $migration->pppoe_user),
            );
            $meta = MigrationNormalizer::meta($migration);
            $meta['huawei_olt_only']['execution'] = [
                'device_write' => true,
                'verified' => (bool) ($result['verified'] ?? false),
                'command_hash' => $currentHash,
                'genieacs_tag' => [
                    'pushed' => (bool) ($tag['pushed'] ?? false),
                    'pending' => (bool) ($tag['pending'] ?? false),
                    'queued' => (bool) ($tag['queued'] ?? false),
                ],
                'executed_at' => now()->toIso8601String(),
            ];
            $meta['device_action_approval'] = array_merge($approval, [
                'status' => 'executed',
                'write_flags' => $flags,
                'executed_at' => now()->toIso8601String(),
            ]);
            $meta['timeline'] = MigrationNormalizer::appendTimeline($meta['timeline'] ?? [], 'huawei_olt_only_device_action_executed', [
                'command_hash' => $currentHash,
                'genieacs_tag_pushed' => (bool) ($tag['pushed'] ?? false),
                'genieacs_tag_pending' => (bool) ($tag['pending'] ?? false),
            ]);
            $migration->meta = $meta;
            $migration->new_olt_id = $olt->id;
            $migration->new_pon_port = data_get($before, 'placement.pon_port');
            $migration->new_onu_id = data_get($before, 'placement.onu_id');
            $migration->new_onu_ref = data_get($before, 'placement.onu_ref');
            $migration->vlan = data_get($before, 'plan.internet_vlan');
            $migration->tr069_vlan = data_get($before, 'plan.tr069_vlan');
            $migration->olt_at = now();
            $migration->status = CustomerMigration::STATUS_OLT_OK;
            $migration->last_error = ($tag['pending'] ?? false)
                ? 'OLT Huawei aktif; tag GenieACS menunggu Inform CPE.'
                : null;
            $migration->save();

            if ($ledger) {
                $ledger->status = ($tag['pushed'] ?? false) ? 'verified' : 'executed';
                $ledger->result = [
                    'device_write' => true,
                    'migration_status' => $migration->status,
                    'genieacs_tag_pushed' => (bool) ($tag['pushed'] ?? false),
                    'genieacs_tag_pending' => (bool) ($tag['pending'] ?? false),
                    'actor_id' => $actor?->id,
                    'reason' => $reason,
                ] + ($copy !== null ? ['mikrotik_step' => $copy['done'] ? 'already_verified' : 'written'] : []);
                $ledger->verified_at = ($tag['pushed'] ?? false) ? now() : null;
                $ledger->save();
            }

            $routerCredential = $this->readVerifiedMigrationPassword($migration);
            $this->reply(
                (string) $migration->group_jid,
                $this->huaweiOltOnlySuccessMessage($migration, $tag, $routerCredential)
            );

            return $migration->fresh() ?? $migration;
        } catch (HuaweiOltSessionBusyException $e) {
            // The Huawei session was busy on the FIRST connect: no OLT command
            // was sent (a later phase surfaces as HuaweiOltCommandException).
            // Like B6: ledger back to dry_run so one resend works, not a false
            // manual_reconciliation. The caller replies $e->userMessage() (H-1).
            if ($mikrotikWritten) {
                // Cross-router: the MikroTik step (target secret, source secret
                // disabled, DB router/username moved, possible rename) already
                // ran. A dry_run revert + "nothing sent" would be false, and a
                // resend cannot work after a rename (preflight/hash change), so
                // keep the visible manual-reconciliation state.
                if ($executionStarted) {
                    $this->ledgers()->markDeviceActionExecutionFailed($migration, $approval, $actor, $e);
                }
                // Short enough for shortWaError() (160 chars).
                $busyMessage = "Migrasi #{$migration->id}: PPPoE sudah pindah ke router target; OLT Huawei sibuk, belum ada perintah OLT. Perlu rekonsiliasi manual.";
                try {
                    $failed = $migration->fresh() ?? $migration;
                    $failed->last_error = $busyMessage;
                    $failed->save();
                } catch (\Throwable) {
                    // The failed ledger/approval state is already persisted.
                }

                throw new \RuntimeException($busyMessage, 0, $e);
            }

            if ($executionStarted) {
                $this->ledgers()->revertLedgerToDryRun((int) $ledger->id);
            }

            throw $e;
        } catch (\Throwable $e) {
            if ($executionStarted) {
                $this->ledgers()->markDeviceActionExecutionFailed($migration, $approval, $actor, $e);
            }

            throw $e;
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function deviceActionPreflight(CustomerMigration $migration): array
    {
        return $this->planner()->deviceActionPreflight($migration);
    }

    /**
     * @return array<string, mixed>
     */
    protected function migrationTargetContract(CustomerMigration $migration, array $preflight): array
    {
        return $this->plans()->migrationTargetContract($migration, $preflight);
    }

    /**
     * Indonesian operator hint for blocking preflight reasons.
     *
     * @param  array<int, string>  $reasons
     */
    protected function migrationReasonHint(array $reasons): string
    {
        return $this->plans()->migrationReasonHint($reasons);
    }

    /**
     * Sites where a migration must keep the customer's current package/profile.
     */
    protected function migrationPreservesCustomerPackage(int $routerId, string $routerName = ''): bool
    {
        return $this->plans()->migrationPreservesCustomerPackage($routerId, $routerName);
    }

    /**
     * @return array<string, mixed>
     */
    protected function pppoeMigrationPlan(CustomerMigration $migration, array $preflight): array
    {
        return $this->plans()->pppoeMigrationPlan($migration, $preflight);
    }

    /** @return array<string, bool> */
    protected function huaweiOltOnlyWriteFlags(): array
    {
        return (new MigrationWriteGate)->huaweiLaneFlags($this->controlPlane);
    }

    /** @param array<string, bool> $flags */
    protected function migrationWritesAllowed(array $flags, bool $scopedWaCanary): bool
    {
        return (new MigrationWriteGate)->writesAllowed($flags, $scopedWaCanary);
    }

    /**
     * @return array<string, mixed>
     */
    protected function oltSnapshotForMigration(CustomerMigration $migration, ?Olt $olt, ?Customer $customer = null): array
    {
        return $this->evidence()->oltSnapshotForMigration($migration, $olt, $customer);
    }

    /**
     * Manual FiberHome UI hook. Return non-null only when this is really a migration.
     *
     * @param  array<string, mixed>  $payload
     */
    public function recordManualFiberhomeProvision(array $payload): ?CustomerMigration
    {
        if (! Schema::hasTable('customer_migrations')) {
            return null;
        }

        $pppoe = MigrationNormalizer::normalizeUsername((string) ($payload['name'] ?? $payload['pppoe_user'] ?? ''));
        $sn = MigrationNormalizer::normalizeSerial((string) ($payload['sn'] ?? ''));
        if ($pppoe === '' || $sn === '') {
            return null;
        }

        $customer = $this->resolver()->findCustomerByPppoe($pppoe);
        $old = $customer ? $this->evidence()->oldOnuSnapshot($customer) : [];

        $port = MigrationNormalizer::normalizePonPort((string) ($payload['port'] ?? ''));
        $onuId = isset($payload['onu_id']) ? (int) $payload['onu_id'] : null;
        $onuRef = ($port && $onuId) ? "{$port}:{$onuId}" : null;

        $migration = CustomerMigration::query()
            ->where('source', 'manual_fiberhome')
            ->where('pppoe_user', $customer?->pppoe_user ?? $pppoe)
            ->whereBetween('created_at', [now('Asia/Jakarta')->startOfDay()->utc(), now('Asia/Jakarta')->endOfDay()->utc()])
            ->orderByDesc('id')
            ->first() ?: new CustomerMigration;

        $migration->fill(array_merge($old, [
            'status' => CustomerMigration::STATUS_OLT_OK,
            'source' => 'manual_fiberhome',
            'group_jid' => 'manual@ebilling',
            'sender_name' => 'manual-fiberhome',
            'message_id' => $migration->message_id ?: 'manual-migrasi-fh-'.md5(($customer?->id ?? $pppoe).$sn.now()->timestamp),
            'raw_text' => '[manual fiberhome migration] '.$pppoe,
            'customer_id' => $customer?->id,
            'pppoe_user' => $customer?->pppoe_user ?? $pppoe,
            'full_name' => $customer?->name ?? (string) ($payload['full_name'] ?? $pppoe),
            'phone' => $customer?->phone,
            'area_id' => $customer?->area_id,
            'router_id' => (int) ($payload['router_id'] ?? 0) ?: $customer?->router_id,
            'new_olt_id' => (int) ($payload['olt_id'] ?? 0) ?: $customer?->olt_id,
            'new_sn' => $sn,
            'new_pon_port' => $port,
            'new_onu_id' => $onuId,
            'new_onu_ref' => $onuRef,
            'vlan' => isset($payload['vlan']) ? (int) $payload['vlan'] : null,
            'tr069_vlan' => isset($payload['tr069_vlan']) ? (int) $payload['tr069_vlan'] : null,
            'olt_at' => now(),
        ]));
        $migration->meta = array_merge(MigrationNormalizer::meta($migration), [
            'manual_fiberhome_recorded_at' => now()->toIso8601String(),
            'psb_draft_id' => $payload['psb_draft_id'] ?? null,
            'psb_created_at' => $payload['psb_created_at'] ?? null,
            'customer_found' => (bool) $customer,
        ]);
        $migration->save();

        if (! $customer) {
            $migration->status = CustomerMigration::STATUS_FAILED;
            $migration->last_error = 'ONU FiberHome sudah aktif, tapi pelanggan eBilling belum ketemu dari PPPoE. Link customer manual lalu retry sync.';
            $migration->save();

            return $migration->fresh();
        }

        $this->syncCustomerAfterNewOnu($migration, $customer, [
            'olt_status' => 'online',
        ]);
        $this->syncTr069Tag($migration, $customer);
        $this->deleteOldOnuIfSafe($migration);

        $migration->status = CustomerMigration::STATUS_SYNCED;
        $migration->synced_at = now();
        if (! str_contains((string) $migration->last_error, 'hapus ONU lama gagal')) {
            $migration->last_error = null;
        }
        $migration->save();

        return $migration->fresh();
    }

    /**
     * Reasons a manually provisioned ONU (SN at OLT ref) may NOT be recorded
     * on this open migration. Same ownership guards as `#migrasi sn`: SN
     * owned by another customer / migration / ONU, plus the provisioned port
     * owned by another customer and a different target router or OLT.
     *
     * @return list<string>
     */
    public function manualProvisionLinkConflicts(
        CustomerMigration $migration,
        ?Customer $customer,
        string $sn,
        ?Olt $olt,
        ?string $onuRef,
        ?int $routerId,
    ): array {
        $sn = MigrationNormalizer::normalizeSerial($sn);
        if (! $customer) {
            return ["Migrasi #{$migration->id} belum tertaut ke pelanggan eBilling"];
        }

        $conflicts = [];
        if ($routerId && $migration->router_id && (int) $migration->router_id !== (int) $routerId) {
            $conflicts[] = "Router target berbeda (migrasi #{$migration->id}: router #{$migration->router_id}, provision: router #{$routerId})";
        }
        if ($olt && $migration->new_olt_id && (int) $migration->new_olt_id !== (int) $olt->id) {
            $conflicts[] = "OLT target berbeda (migrasi #{$migration->id}: OLT #{$migration->new_olt_id}, provision: {$olt->name})";
        }
        if ($sn === '') {
            return array_merge($conflicts, ['SN baru kosong']);
        }

        // Customer + other-migration SN ownership (OLT check done below so
        // the ONU that was just provisioned at $onuRef is not its own conflict).
        $conflicts = array_merge($conflicts, $this->snOwnershipConflicts($migration, $sn, null, $customer));

        $ref = $onuRef !== null ? MigrationNormalizer::normalizeOnuRef($onuRef) : null;
        if ($olt && Schema::hasTable('olt_onus')) {
            $onu = OltOnu::query()
                ->where('olt_id', $olt->id)
                ->whereRaw('UPPER(REPLACE(REPLACE(COALESCE(serial_number, ""), "-", ""), " ", "")) = ?', [$sn])
                ->latest('id')
                ->first();
            if ($onu && (! $ref || MigrationNormalizer::normalizeOnuRef((string) $onu->onu_ref) !== $ref)) {
                $probe = clone $migration;
                $probe->new_sn = $sn;
                $probe->new_onu_ref = null;
                if (! $this->evidence()->existingOnuBelongsToMigration($probe, $customer, $onu)) {
                    $conflicts[] = "SN sudah terdaftar di ONU lain {$onu->onu_ref} ({$onu->onu_name}) di {$olt->name}";
                }
            }
        }
        if ($olt && $ref) {
            $portOwner = Customer::query()
                ->whereKeyNot($customer->id)
                ->where('olt_id', $olt->id)
                ->whereRaw('LOWER(COALESCE(olt_port_label, "")) = ?', [$ref])
                ->first(['id', 'name', 'pppoe_user']);
            if ($portOwner) {
                $conflicts[] = "Port ONU {$ref} sudah tercatat milik pelanggan lain (".($portOwner->pppoe_user ?: $portOwner->name ?: '#'.$portOwner->id).')';
            }
        }

        return array_values(array_unique($conflicts));
    }

    /**
     * Record a manual UI provision on an existing open migration (DB only,
     * no device write): new ONU fields, customer DB sync, status synced and a
     * `manual_provision_linked` timeline entry. The PSB draft of the same
     * provision (if any) is linked to the customer with meta.migration_id.
     * Ownership guards are re-checked under a row lock.
     *
     * @param  array<string, mixed>  $payload  olt_id, router_id, sn, port, onu_id, name, vlan, tr069_vlan
     */
    public function applyManualProvisionLink(
        CustomerMigration $migration,
        array $payload,
        ?PsbDraft $draft,
        string $matchedBy,
        ?string $actor = null,
    ): CustomerMigration {
        return DB::transaction(function () use ($migration, $payload, $draft, $matchedBy, $actor): CustomerMigration {
            /** @var CustomerMigration $locked */
            $locked = CustomerMigration::query()->whereKey($migration->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isOpen()) {
                throw new \RuntimeException("Migrasi #{$locked->id} sudah {$locked->status}; tidak ditautkan ulang.");
            }

            $sn = MigrationNormalizer::normalizeSerial((string) ($payload['sn'] ?? ''));
            $port = MigrationNormalizer::normalizePonPort((string) ($payload['port'] ?? ''));
            $onuId = isset($payload['onu_id']) && (int) $payload['onu_id'] > 0 ? (int) $payload['onu_id'] : null;
            $onuRef = ($port && $onuId) ? "{$port}:{$onuId}" : null;
            $oltId = (int) ($payload['olt_id'] ?? 0);
            $olt = $oltId > 0 ? Olt::query()->find($oltId) : null;
            $routerId = (int) ($payload['router_id'] ?? 0) ?: null;
            $typedName = MigrationNormalizer::normalizeUsername((string) ($payload['name'] ?? ''));

            $customer = $this->resolveCustomer($locked);
            $conflicts = $this->manualProvisionLinkConflicts($locked, $customer, $sn, $olt, $onuRef, $routerId);
            if ($conflicts !== [] || ! $customer) {
                throw new \RuntimeException('Tidak bisa ditautkan ke migrasi #'.$locked->id.': '.implode('; ', $conflicts));
            }

            $previousSn = $locked->new_sn;
            $locked->new_sn = $sn;
            if ($olt) {
                $locked->new_olt_id = $olt->id;
            }
            if ($routerId) {
                $locked->router_id = $routerId;
            }
            if ($port) {
                $locked->new_pon_port = $port;
            }
            if ($onuId) {
                $locked->new_onu_id = $onuId;
            }
            if ($onuRef) {
                $locked->new_onu_ref = $onuRef;
            }
            if (! empty($payload['vlan'])) {
                $locked->vlan = (int) $payload['vlan'];
            }
            if (! empty($payload['tr069_vlan'])) {
                $locked->tr069_vlan = (int) $payload['tr069_vlan'];
            }
            $locked->olt_at = $locked->olt_at ?: now();
            $locked->save();

            $this->syncCustomerAfterNewOnu($locked, $customer, ['olt_status' => 'online']);

            $mikrotikVerified = (bool) data_get(MigrationNormalizer::meta($locked), 'mikrotik_migration.ok', false);
            $crossRouter = $locked->old_router_id && $locked->router_id
                && (int) $locked->old_router_id !== (int) $locked->router_id;
            $link = [
                'psb_draft_id' => $draft?->id,
                'matched_by' => $matchedBy,
                'previous_status' => $locked->status,
                'previous_sn' => $previousSn,
                'sn' => $sn,
                'olt_id' => $olt?->id,
                'router_id' => $locked->router_id,
                'onu_ref' => $onuRef,
                'typed_name' => $typedName !== '' ? $typedName : null,
                'actor' => $actor,
                'mikrotik_step_verified' => $mikrotikVerified,
                'cross_router' => (bool) $crossRouter,
                'device_writes' => 'none',
            ];
            $meta = MigrationNormalizer::meta($locked);
            $meta['manual_provision_link'] = $link + ['linked_at' => now()->toIso8601String()];
            $meta['timeline'] = MigrationNormalizer::appendTimeline($meta['timeline'] ?? [], 'manual_provision_linked', $link);
            if ($previousSn && MigrationNormalizer::normalizeSerial((string) $previousSn) !== $sn) {
                $corrections = is_array($meta['sn_corrections'] ?? null) ? $meta['sn_corrections'] : [];
                $corrections[] = ['from' => $previousSn, 'to' => $sn, 'origin' => 'manual_provision', 'at' => now()->toIso8601String()];
                $meta['sn_corrections'] = array_slice($corrections, -10);
            }
            $locked->meta = $meta;
            $locked->status = CustomerMigration::STATUS_SYNCED;
            $locked->synced_at = now();
            $locked->last_error = null;
            $locked->save();

            if ($draft) {
                $draft = PsbDraft::query()->whereKey($draft->id)->lockForUpdate()->first() ?? $draft;
                $draftMeta = is_array($draft->meta) ? $draft->meta : [];
                $typedDraftPppoe = (string) ($draft->pppoe_user ?? '');
                if ($typedDraftPppoe !== '' && strcasecmp($typedDraftPppoe, (string) $customer->pppoe_user) !== 0) {
                    $draftMeta['typed_pppoe_user'] = $typedDraftPppoe;
                }
                $draftMeta['migration_id'] = $locked->id;
                $draftMeta['migration_link'] = [
                    'matched_by' => $matchedBy,
                    'actor' => $actor,
                    'linked_at' => now()->toIso8601String(),
                ];
                $draft->customer_id = $customer->id;
                $draft->pppoe_user = $customer->pppoe_user ?: $draft->pppoe_user;
                $draft->full_name = $customer->name ?: $draft->full_name;
                $draft->area_id = $draft->area_id ?: $customer->area_id;
                $draft->package_id = $draft->package_id ?: $customer->package_id;
                $draft->meta = $draftMeta;
                $draft->save();
            }

            return $locked->fresh();
        });
    }

    /**
     * Read-only helpers for the manual provision matcher.
     */
    public function normalizeSerialForMatch(string $value): string
    {
        return MigrationNormalizer::normalizeSerial($value);
    }

    public function normalizeUsernameForMatch(string $value): string
    {
        return MigrationNormalizer::normalizeUsername($value);
    }

    public function onuNameTokenForMatch(string $value): string
    {
        return MigrationNormalizer::onuNameToken($value);
    }

    public function reply(string $groupJid, string $text, array $mentionJids = []): void
    {
        if ($groupJid === '') {
            return;
        }

        $this->psb->reply($groupJid, $text, $mentionJids);
    }

    /**
     * @param  array<string, mixed>  $preview
     */
    protected function guidedPreviewMessage(array $preview, ?CustomerMigration $draft = null, ?string $dryRunError = null): string
    {
        return $this->texts()->guidedPreviewMessage($preview, $draft, $dryRunError);
    }

    /** @param array<string, mixed> $payload */
    protected function confirmHuaweiOltOnlyFromWa(CustomerMigration $migration, array $payload): CustomerMigration
    {
        return $this->huaweiIntake()->confirmHuaweiOltOnlyFromWa($migration, $payload);
    }

    /** @param array<string, mixed> $candidate @param array<string, mixed> $payload */
    protected function confirmGuidedWaCandidate(CustomerMigration $migration, array $candidate, array $payload, int $number): CustomerMigration
    {
        return $this->guidedFlow()->confirmGuidedWaCandidate($migration, $candidate, $payload, $number);
    }

    /** @param array<string, mixed> $payload */
    protected function dispatchWaExecution(
        array $payload,
        int $id,
        bool $automatic,
        string $authorizationMode = 'sender_allowlist'
    ): CustomerMigration {
        $group = (string) ($payload['group_jid'] ?? '');
        $migration = $id > 0 ? CustomerMigration::query()->find($id) : null;
        if ($automatic) {
            $this->reply($group, 'Auto-live migrasi dinonaktifkan. Review dan konfirmasi teknisi harus selesai, lalu kirim #migrasi eksekusi #ID secara eksplisit.');

            return $migration ?? new CustomerMigration(['status' => CustomerMigration::STATUS_FAILED]);
        }
        $guard = $this->guidedLookup->waExecutionAllowlistGuard($payload, $this->senders()->migrationBoundSenderKeys($migration));
        if (! MigrationNormalizer::boolSetting('migration_wa_only_live_enabled', false) || ! $guard['allowed']) {
            $reason = ! MigrationNormalizer::boolSetting('migration_wa_only_live_enabled', false)
                ? 'WA-only live masih OFF'
                : implode(', ', $guard['reasons']);
            $this->reply($group, "Eksekusi migrasi diblokir: {$reason}.");

            return $migration ?? new CustomerMigration(['status' => CustomerMigration::STATUS_FAILED]);
        }
        if (! $migration || (string) $migration->group_jid !== $group) {
            $this->reply($group, 'Migrasi tidak ditemukan di grup ini. Pakai: #migrasi eksekusi #ID');

            return new CustomerMigration(['status' => CustomerMigration::STATUS_FAILED]);
        }
        if ($migration->status === CustomerMigration::STATUS_CANCELLED) {
            $this->reply($group, "Migrasi #{$migration->id} sudah dibatalkan; tidak dieksekusi.");

            return $migration;
        }
        // Done (normally or by auto-reconcile): say so before the operation guard, which would otherwise talk
        // about dry-run/identity of a migration that no longer needs any of it, and never queue a job.
        if (MigrationNormalizer::migrationAlreadySynced($migration)) {
            $this->reply($group, "Migrasi #{$migration->id} sudah selesai; tidak dieksekusi ulang.");

            return $migration;
        }
        // The global read-only switch holds on every lane: no job, no dry-run, no ledger or approval change.
        if ((new MigrationWriteGate)->globalReadOnly()) {
            $this->waExecutionStopped($migration, $group, 'global_read_only', $this->texts()->readOnlyEksekusiRefused((int) $migration->id));

            return $migration;
        }
        // The approval ledger already ran (or devices were partially changed
        // without a ready dry-run): never queue a job that would no-op
        // silently. Explain the state and prepare the continuation dry-run.
        $approvalLedger = $this->ledgers()->approvalLedger($migration);
        if (! MigrationNormalizer::migrationAlreadySynced($migration)
            && (($approvalLedger && in_array($approvalLedger->status, ['executed', 'verified'], true))
                || ($this->partialExecutionState($migration) !== null
                    && data_get($migration->meta, 'device_action_approval.status') !== 'dry_run_ready'))) {
            $lock = Cache::lock('customer-migration-device-'.$migration->id, 120);
            if (! CacheLockWait::acquire($lock, 5)) {
                $this->reply($group, MigrationLockMessages::deviceBusyExecute((int) $migration->id));

                return $migration;
            }
            try {
                return $this->partials()->handleAlreadyExecutedApproval($migration, $approvalLedger, $this->senders()->waActor($payload), true);
            } finally {
                try {
                    $lock->release();
                } catch (\Throwable) {
                }
            }
        }
        $operationGuard = $this->planner()->operationExecutionGuard($migration);
        if (! $operationGuard['allowed']) {
            $this->reply($group, "Migrasi #{$migration->id} diblokir: {$operationGuard['reason']}");

            return $migration;
        }
        if ($migration->status === CustomerMigration::STATUS_SYNCED) {
            $this->reply($group, "Migrasi #{$migration->id} sudah selesai; tidak dieksekusi ulang.");

            return $migration;
        }
        if (data_get($migration->meta, 'device_action_approval.status') !== 'dry_run_ready') {
            $this->reply($group, "Migrasi #{$migration->id} belum dry_run_ready. Pilih kandidat atau kirim ulang #migrasi dengan data lengkap.");

            return $migration;
        }
        $secretVerified = MigrationNormalizer::isHuaweiOltOnlyMigration($migration)
            ? data_get($migration->meta, 'device_action_preflight.router_secret.found')
            : data_get($migration->meta, 'device_action_preflight.mikrotik_secret.found');
        if ($secretVerified !== true) {
            $this->reply($group, MigrationNormalizer::isHuaweiOltOnlyMigration($migration)
                ? "Migrasi #{$migration->id} belum aman dieksekusi: secret PPPoE di router target belum terverifikasi secara read-only."
                : "Migrasi #{$migration->id} belum aman dieksekusi: secret PPPoE di router lama belum terverifikasi. Cek koneksi/router lalu ulangi dry-run.");

            return $migration;
        }

        // Re-read under a row lock so a concurrent auto-reconcile apply (which writes meta + status) is never
        // overwritten by this stale in-memory copy (lost-update window).
        $queued = DB::transaction(function () use ($migration, $payload, $automatic, $authorizationMode): ?CustomerMigration {
            $locked = CustomerMigration::query()->lockForUpdate()->find($migration->id);
            if (! $locked || MigrationNormalizer::migrationAlreadySynced($locked) || $locked->status === CustomerMigration::STATUS_CANCELLED) {
                return null;
            }
            $meta = MigrationNormalizer::meta($locked);
            $meta['wa_live_execution_queued'] = [
                'message_id' => (string) ($payload['message_id'] ?? ''),
                'sender_jid' => (string) ($payload['sender_jid'] ?? ''),
                'sender_name' => (string) ($payload['sender_name'] ?? ''),
                'automatic' => $automatic,
                'authorization_mode' => $authorizationMode,
                'queued_at' => now()->toIso8601String(),
            ];
            $locked->meta = $meta;
            $locked->save();

            return $locked;
        });
        if ($queued === null) {
            $current = $migration->fresh() ?? $migration;
            $this->reply($group, $current->status === CustomerMigration::STATUS_CANCELLED
                ? "Migrasi #{$migration->id} sudah dibatalkan; tidak dieksekusi."
                : "Migrasi #{$migration->id} sudah selesai; tidak dieksekusi ulang.");

            return $current;
        }
        $migration = $queued;

        $jobPayload = array_merge($payload, [
            '_migration_authorization_mode' => $authorizationMode,
        ]);
        ExecuteCustomerMigrationWaJob::dispatch($migration->id, $jobPayload);
        $this->reply($group, "Migrasi #{$migration->id} masuk antrean manual terkonfirmasi. Maksimal satu migrasi aktif; PSB tetap di antrean terpisah.");

        return $migration->fresh() ?? $migration;
    }

    /** @param array<string, mixed> $payload */
    public function executeWaMigration(int $id, array $payload): ?CustomerMigration
    {
        $group = (string) ($payload['group_jid'] ?? '');
        $migration = $id > 0 ? CustomerMigration::query()->find($id) : null;
        if (MigrationNormalizer::boolSetting('migration_maintenance_mode', false)) {
            if ($group !== '') {
                $this->reply($group, "Maintenance migrasi aktif. Device action untuk #{$id} ditahan aman; PSB tetap berjalan normal. Cek ulang setelah maintenance: #migrasi cek #{$id}");
            }

            return $migration;
        }
        $authorizationMode = (string) ($payload['_migration_authorization_mode'] ?? 'sender_allowlist');
        if (in_array($authorizationMode, ['technician_unique_auto', 'technician_confirmed_auto'], true)) {
            $this->waExecutionStopped($migration, $group, 'auto_live_disabled', "Eksekusi otomatis migrasi #{$id} dinonaktifkan. Kirim #migrasi eksekusi #{$id} secara eksplisit.");

            return $migration;
        }
        $guard = $this->guidedLookup->waExecutionAllowlistGuard($payload, $this->senders()->migrationBoundSenderKeys($migration));
        if (! MigrationNormalizer::boolSetting('migration_wa_only_live_enabled', false) || ! $guard['allowed']) {
            $reason = ! MigrationNormalizer::boolSetting('migration_wa_only_live_enabled', false)
                ? 'WA-only live masih OFF'
                : implode(', ', $guard['reasons']);
            $this->waExecutionStopped($migration, $group, 'wa_live_guard', "Eksekusi migrasi #{$id} dihentikan aman sebelum device action: {$reason}.");

            return $migration ?? new CustomerMigration(['status' => CustomerMigration::STATUS_FAILED]);
        }
        if (! $migration || (string) $migration->group_jid !== $group) {
            $this->waExecutionStopped(null, $group, 'migration_not_found', "Migrasi #{$id} tidak ditemukan di grup ini; tidak ada device action.");

            return new CustomerMigration(['status' => CustomerMigration::STATUS_FAILED]);
        }
        if ($migration->status === CustomerMigration::STATUS_SYNCED) {
            $this->waExecutionStopped($migration, $group, 'already_synced', "Migrasi #{$migration->id} sudah selesai; tidak dieksekusi ulang.");

            return $migration;
        }
        if ($migration->status === CustomerMigration::STATUS_CANCELLED) {
            $this->waExecutionStopped($migration, $group, 'cancelled', "Migrasi #{$migration->id} sudah dibatalkan; antrean eksekusi dihentikan tanpa device action.");

            return $migration;
        }
        $operationGuard = $this->planner()->operationExecutionGuard($migration);
        if (! $operationGuard['allowed']) {
            $this->waExecutionStopped($migration, $group, 'operation_guard', "Migrasi #{$migration->id} ditahan aman: {$operationGuard['reason']}");

            return $migration;
        }
        if (data_get($migration->meta, 'device_action_approval.status') !== 'dry_run_ready') {
            $approvalLedger = $this->ledgers()->approvalLedger($migration);
            if (($approvalLedger && in_array($approvalLedger->status, ['executed', 'verified'], true))
                || $this->partialExecutionState($migration) !== null) {
                $lock = Cache::lock('customer-migration-device-'.$migration->id, 120);
                if (! CacheLockWait::acquire($lock, 5)) {
                    // Busy: a clear message and NO exception, so the queue job
                    // is not retried (that used to be an accidental auto-resume
                    // of up to 120 tries). The technician re-sends explicitly.
                    $this->reply($group, MigrationLockMessages::deviceBusyExecute((int) $migration->id));

                    return $migration;
                }
                try {
                    return $this->partials()->handleAlreadyExecutedApproval($migration, $approvalLedger, $this->senders()->waActor($payload), true);
                } finally {
                    try {
                        $lock->release();
                    } catch (\Throwable) {
                    }
                }
            }
            $status = (string) data_get($migration->meta, 'device_action_approval.status', 'belum ada');
            $this->waExecutionStopped($migration, $group, 'not_dry_run_ready', "Migrasi #{$migration->id} tidak dieksekusi: dry-run belum siap (status approval: {$status}). Cek: #migrasi cek #{$migration->id}");

            return $migration;
        }

        try {
            if (Queue::size('psb') > 0 || Queue::size(GuidedMigrationLookupService::QUEUE) > 0) {
                return null;
            }
        } catch (\Throwable $e) {
            $this->reply($group, "Eksekusi migrasi #{$migration->id} diblokir: status queue tidak bisa diverifikasi.");

            return $migration;
        }

        $lock = Cache::lock('customer-migration-wa-live-active', 480);
        if (! $lock->get()) {
            $this->reply($group, 'Ada migrasi lain yang sedang berjalan. Tunggu selesai lalu cek /migrasi.');

            return $migration;
        }

        try {
            $meta = MigrationNormalizer::meta($migration);
            $meta['wa_live_execution'] = [
                'message_id' => (string) ($payload['message_id'] ?? ''),
                'sender_jid' => (string) ($payload['sender_jid'] ?? ''),
                'sender_name' => (string) ($payload['sender_name'] ?? ''),
                'authorization_mode' => $authorizationMode,
                'requested_at' => now()->toIso8601String(),
                'idempotency_key' => 'wa-live-migration:'.$migration->id,
            ];
            $migration->meta = $meta;
            $migration->save();

            $result = $this->executeApprovedDeviceAction(
                $migration,
                $this->senders()->waActor($payload),
                'WA-only live explicitly approved by '.((string) ($payload['sender_name'] ?? 'allowlisted sender')),
                true
            );
            if (data_get($result->meta, 'device_action_approval.status') === 'blocked_kill_switch') {
                $stop = $this->texts()->executionBlockedStop($result);
                $this->waExecutionStopped($result, $group, $stop['code'], $stop['text']);
            }

            return $result;
        } catch (LockBusyException $e) {
            // Busy before any device write (ledger already reverted): clear
            // message, state unchanged, no exception so the job is not retried.
            Log::warning('WA-only migration execution lock busy', ['migration_id' => $migration->id]);
            $this->reply($group, $e->userMessage());

            return $migration->fresh() ?? $migration;
        } catch (\Throwable $e) {
            $error = MigrationNormalizer::boundedError($e);
            Log::error('WA-only migration execution failed', [
                'migration_id' => $migration->id,
                'exception_class' => $e::class,
            ]);
            $this->reply($group, "Migrasi #{$migration->id} berhenti aman: ".$this->texts()->shortWaError($error)."\nCek: #migrasi cek #{$migration->id}");

            return $migration->fresh() ?? $migration;
        } finally {
            try {
                $lock->release();
            } catch (\Throwable) {
            }
        }
    }

    /** @param array<string, mixed> $payload */
    public function markWaExecutionJobFailed(int $id, array $payload, ?\Throwable $exception): void
    {
        $migration = CustomerMigration::query()->find($id);
        if (! $migration || $migration->status === CustomerMigration::STATUS_SYNCED) {
            return;
        }

        $meta = MigrationNormalizer::meta($migration);
        $meta['wa_live_execution_failed'] = [
            'failed_at' => now()->toIso8601String(),
            'error_class' => $exception ? class_basename($exception) : null,
        ];
        $migration->meta = $meta;
        $migration->last_error = 'Worker migrasi berhenti sebelum eksekusi selesai; aman untuk dicek dan retry dengan ID yang sama.';
        $migration->save();

        Log::error('WA-only migration job exhausted', [
            'migration_id' => $migration->id,
            'exception_class' => $exception ? $exception::class : null,
        ]);
        $this->reply(
            (string) ($payload['group_jid'] ?? $migration->group_jid),
            "Migrasi #{$migration->id} belum dieksekusi karena antrean/worker belum siap dalam batas aman.\n".
            "Tidak ada eksekusi ganda. Cek: #migrasi cek #{$migration->id}, lalu retry ID yang sama setelah queue normal."
        );
    }

    /**
     * The ONLY writer of the "migration auto-reconcile" feature (plan 2026-10-15). DB-only status change on the
     * customer_migrations row: no MikroTik / OLT / GenieACS / customers row / old ONU / WA. The caller
     * (migration:auto-reconcile) holds the migration locks; this method re-checks everything under a row lock.
     *
     * @param  array<string, mixed>  $verdictEvidence
     * @param  array{status?: string, updated_at?: string}  $expect  state the evaluator saw (changed => refuse)
     * @return array{applied: bool, reason: string, ledger_superseded_id: int|null}
     */
    public function markSyncedByAutoReconcile(int $id, array $verdictEvidence, string $runId, array $expect = []): array
    {
        return DB::transaction(function () use ($id, $verdictEvidence, $runId, $expect): array {
            $row = CustomerMigration::query()->lockForUpdate()->find($id);
            if (! $row) {
                return ['applied' => false, 'reason' => 'tidak_ada', 'ledger_superseded_id' => null];
            }
            if (MigrationNormalizer::migrationAlreadySynced($row) || $row->status === CustomerMigration::STATUS_CANCELLED) {
                return ['applied' => false, 'reason' => 'sudah_selesai', 'ledger_superseded_id' => null];
            }
            if ((isset($expect['status']) && (string) $row->status !== $expect['status'])
                || (isset($expect['updated_at']) && (string) $row->updated_at?->toDateTimeString() !== $expect['updated_at'])) {
                return ['applied' => false, 'reason' => 'berubah_saat_proses', 'ledger_superseded_id' => null];
            }
            if ($this->partialExecutionState($row) !== null) {
                return ['applied' => false, 'reason' => 'ada_jejak_eksekusi', 'ledger_superseded_id' => null];
            }
            if ($this->ledgers()->latestMigrationDeviceLedger($row, ['executing']) !== null) {
                return ['applied' => false, 'reason' => 'eksekusi_berjalan', 'ledger_superseded_id' => null];
            }

            $now = now();
            $supersededId = $this->ledgers()->supersedeUnusedDryRunLedgers($row, 'superseded_auto_reconcile', $runId, $now);

            $meta = MigrationNormalizer::meta($row);
            if (is_array($meta['device_action_approval'] ?? null)) {
                $meta['device_action_approval']['status'] = 'superseded_auto_reconcile';
            }
            $previousStatus = (string) $row->status;
            $evidence = $verdictEvidence;
            $customerId = isset($evidence['customer_id']) ? (int) $evidence['customer_id'] : null;
            // customer_id is deliberately NOT written to the row: PsbAreaRecapService::migrationDates(), the PSB part of
            // DailyReportService and MigrationDailyStats::identity() all key on customer_migrations.customer_id, so
            // filling it would silently change PSB recap / daily report numbers. It stays in meta only.
            $fillCustomer = false;
            $snSource = (string) ($evidence['sn_source'] ?? 'new_sn');
            $meta['auto_reconcile'] = [
                'version' => 1,
                'run_id' => $runId,
                'at' => $now->toIso8601String(),
                'actor' => 'system:migration:auto-reconcile',
                'reason' => 'otomatis: ONU + PPPoE online',
                'previous_status' => $previousStatus,
                'previous_last_error' => $row->last_error !== null ? mb_substr((string) $row->last_error, 0, 300) : null,
                'sn_source' => $snSource,
                'original_new_sn' => $row->new_sn,
                'effective_sn' => $snSource === 'customer_profile' ? ($evidence['effective_sn'] ?? null) : $row->new_sn,
                'evidence' => $evidence,
                'device_writes' => 'none',
                'customer_writes' => 'none',
                'old_onu' => 'not_touched',
                'customer_id_filled' => false,
                'resolved_customer_id' => $customerId ?: null,
                'ledger_superseded_id' => $supersededId,
            ];
            $meta['timeline'] = MigrationNormalizer::appendTimeline($meta['timeline'] ?? [], 'auto_reconciled_synced', [
                'run_id' => $runId,
                'previous_status' => $previousStatus,
                'sn_source' => $snSource,
            ]);
            $row->meta = $meta;
            $row->status = CustomerMigration::STATUS_SYNCED;
            $row->synced_at = $now;
            $row->last_error = null;
            $row->save();

            activity()
                ->performedOn($row)
                ->useLog('migration')
                ->event('migration_auto_synced')
                ->withProperties(['run_id' => $runId, 'previous_status' => $previousStatus, 'sn_source' => $snSource])
                ->log('otomatis: ONU + PPPoE online');

            return ['applied' => true, 'reason' => 'ok', 'ledger_superseded_id' => $supersededId];
        });
    }

    /**
     * Admin "Tandai selesai" (owner decision 2026-10-17): same DB-only close as the auto-reconcile, but decided by a
     * person with a written reason. Works on any open status (also failed / half-way rows that carry device traces).
     * NEVER touches MikroTik / OLT / GenieACS / old ONU / customers row / WA. The caller holds the migration locks.
     *
     * @param  array<string, mixed>  $snapshot  evidence + verdict shown to the admin (stored in meta.manual_complete)
     * @return array{applied: bool, reason: string, ledger_superseded_id: int|null}
     */
    public function markSyncedManually(int $id, array $snapshot, string $reason, User $actor): array
    {
        return DB::transaction(function () use ($id, $snapshot, $reason, $actor): array {
            $row = CustomerMigration::query()->lockForUpdate()->find($id);
            if (! $row) {
                return ['applied' => false, 'reason' => 'tidak_ada', 'ledger_superseded_id' => null];
            }
            if ($row->status === CustomerMigration::STATUS_CANCELLED) {
                return ['applied' => false, 'reason' => 'dibatalkan', 'ledger_superseded_id' => null];
            }
            if (MigrationNormalizer::migrationAlreadySynced($row)) {
                return ['applied' => false, 'reason' => 'sudah_selesai', 'ledger_superseded_id' => null];
            }
            if ($this->ledgers()->latestMigrationDeviceLedger($row, ['executing']) !== null) {
                return ['applied' => false, 'reason' => 'eksekusi_berjalan', 'ledger_superseded_id' => null];
            }

            $now = now();
            $runId = 'manual-'.$now->format('Ymd-His');
            $supersededId = $this->ledgers()->supersedeUnusedDryRunLedgers($row, 'superseded_manual_complete', $runId, $now);

            $meta = MigrationNormalizer::meta($row);
            if (is_array($meta['device_action_approval'] ?? null)) {
                $meta['device_action_approval']['status'] = 'superseded_manual_complete';
            }
            $previousStatus = (string) $row->status;
            $meta['manual_complete'] = [
                'version' => 1,
                'source' => 'manual',
                'at' => $now->toIso8601String(),
                'actor' => ['id' => (int) $actor->id, 'name' => (string) $actor->name],
                'reason' => $reason,
                'previous_status' => $previousStatus,
                'previous_last_error' => $row->last_error !== null ? mb_substr((string) $row->last_error, 0, 300) : null,
                'verdict' => $snapshot,
                'device_writes' => 'none',
                'customer_writes' => 'none',
                'old_onu' => 'not_touched',
                'ledger_superseded_id' => $supersededId,
            ];
            $meta['timeline'] = MigrationNormalizer::appendTimeline($meta['timeline'] ?? [], 'manual_completed_synced', [
                'actor_id' => (int) $actor->id,
                'previous_status' => $previousStatus,
            ]);
            $row->meta = $meta;
            $row->status = CustomerMigration::STATUS_SYNCED;
            $row->synced_at = $now;
            $row->last_error = null;
            $row->save();

            activity()
                ->performedOn($row)
                ->causedBy($actor)
                ->useLog('migration')
                ->event('migration_manual_synced')
                ->withProperties(['previous_status' => $previousStatus, 'source' => 'manual'])
                ->log('ditandai selesai manual: '.$reason);

            return ['applied' => true, 'reason' => 'ok', 'ledger_superseded_id' => $supersededId];
        });
    }

    /**
     * Undo markSyncedByAutoReconcile() while the row was not changed since (updated_at within 5 s of the stamp).
     * The cancelled dry-run ledger stays cancelled.
     *
     * @return array{reverted: bool, reason: string}
     */
    public function revertAutoReconcile(int $id): array
    {
        return DB::transaction(function () use ($id): array {
            $row = CustomerMigration::query()->lockForUpdate()->find($id);
            $auto = $row ? data_get($row->meta, 'auto_reconcile') : null;
            if (! $row || ! is_array($auto) || $row->status !== CustomerMigration::STATUS_SYNCED) {
                return ['reverted' => false, 'reason' => 'bukan_hasil_otomatis'];
            }
            // customers:sync-onu-fields copied this migration's ONU data onto the customer: revert that sync first.
            if (app(CustomerOnuFieldSyncService::class)->hasUnrevertedSyncForMigration($id)) {
                return ['reverted' => false, 'reason' => 'data_pelanggan_sudah_disinkron'];
            }
            $at = Carbon::parse((string) ($auto['at'] ?? 'now'));
            if ($row->updated_at === null || abs($row->updated_at->diffInSeconds($at, false)) > 5) {
                return ['reverted' => false, 'reason' => 'sudah_diubah_lagi'];
            }

            $meta = MigrationNormalizer::meta($row);
            unset($meta['auto_reconcile']);
            $meta['auto_reconcile_reverted'] = array_merge($auto, ['reverted_at' => now()->toIso8601String()]);
            $meta['timeline'] = MigrationNormalizer::appendTimeline($meta['timeline'] ?? [], 'auto_reconcile_reverted', [
                'run_id' => $auto['run_id'] ?? null,
                'note' => 'dry-run lama tetap dibatalkan; jalankan dry-run ulang sebelum #migrasi eksekusi',
            ]);
            $row->meta = $meta;
            $row->status = (string) ($auto['previous_status'] ?? CustomerMigration::STATUS_NEEDS_REVIEW);
            $row->synced_at = null;
            $row->last_error = $auto['previous_last_error'] ?? null;
            $row->save();

            activity()
                ->performedOn($row)
                ->useLog('migration')
                ->event('migration_auto_sync_reverted')
                ->withProperties(['run_id' => $auto['run_id'] ?? null])
                ->log('otomatis dibatalkan: kembali ke status sebelumnya');

            return ['reverted' => true, 'reason' => 'ok'];
        });
    }

    protected function processMigrationLocked(CustomerMigration $migration): CustomerMigration
    {
        if (data_get($migration->meta, 'migration_operation.operation_type') === MigrationOperationClassifier::DUPLICATE_MIGRATION) {
            return $migration;
        }
        if ($migration->status === CustomerMigration::STATUS_CANCELLED) {
            return $migration;
        }

        $customer = $this->resolveCustomer($migration);
        if (! $customer) {
            return $this->fail($migration, 'Pelanggan lama belum ketemu. Isi PPPoE yang sudah ada, contoh: #migrasi pppoe: USER sn: FHTT...');
        }

        $this->primeMigrationFromCustomer($migration, $customer);
        $router = $this->resolveRouterForMigration($migration, $customer);
        $olt = $this->resolveOltForMigration($migration, $customer, $router);
        if (! $router || ! $olt) {
            return $this->fail($migration, 'Router/OLT belum ketemu dari data pelanggan. Isi router/area atau lengkapi data pelanggan dulu.');
        }

        if ($this->partials()->mikrotikStepAlreadyVerified($migration, $router)) {
            // Continuation after a partial execution: the secret was already
            // copied/renamed and verified (and the source disabled). Never
            // repeat that write; only re-verify the target secret read-only.
            $targetCheck = $this->verifyExistingPppoeSecret($customer, $router);
            if (($targetCheck['found'] ?? null) !== true) {
                return $this->fail($migration, "Lanjutan migrasi ditahan: secret PPPoE {$customer->pppoe_user} di router target {$router->name} ".
                    (($targetCheck['found'] ?? null) === false ? 'tidak ditemukan' : 'belum bisa diverifikasi').
                    '. MikroTik tidak diulang otomatis; NOC cek secret target lalu retry ID yang sama.');
            }
            $meta = MigrationNormalizer::meta($migration);
            $meta['timeline'] = MigrationNormalizer::appendTimeline($meta['timeline'] ?? [], 'mikrotik_step_skipped_already_verified', [
                'target_router_id' => $router->id,
                'pppoe_user' => $customer->pppoe_user,
            ]);
            $migration->meta = $meta;
            $migration->new_olt_id = $olt->id;
            $migration->save();
        } else {
            // The existing PPPoE secret belongs to the source router. For a
            // cross-router migration the target is expected not to have it yet.
            $sourceRouter = $this->resolveSourceRouterForMigration($migration, $customer);
            if (! $sourceRouter) {
                return $this->fail($migration, 'Router lama migrasi belum dapat dipastikan. Isi Router lama dengan alias yang valid.');
            }

            $secretCheck = $this->verifyExistingPppoeSecret($customer, $sourceRouter);
            if (($secretCheck['found'] ?? null) !== true) {
                if (($secretCheck['found'] ?? null) === false) {
                    return $this->fail($migration, "PPPoE {$customer->pppoe_user} tidak ditemukan di MikroTik {$sourceRouter->name}. Migrasi dibatalkan supaya tidak membuat data palsu.");
                }

                return $this->fail($migration, "PPPoE {$customer->pppoe_user} belum bisa diverifikasi di MikroTik {$sourceRouter->name}. Migrasi ditahan aman; perbaiki koneksi/router lalu retry ID yang sama.");
            }
            if (! empty($secretCheck['warning'])) {
                $meta = MigrationNormalizer::meta($migration);
                $meta['pppoe_check_warning'] = $secretCheck['warning'];
                $migration->meta = $meta;
            }

            $migration->old_router_id = $sourceRouter->id;
            $migration->router_id = $router->id;
            $migration->new_olt_id = $olt->id;
            $migration->pppoe_user = $customer->pppoe_user;
            $migration->save();

            try {
                $this->applyMigrationPppoeSecret($migration, $customer, $router);
            } catch (PsbLockBusyException) {
                // Allocator queue busy before any MikroTik write: not a failure.
                // Surfaces to executeApprovedDeviceAction, which restores the
                // ledger so a single resend works.
                throw new LockBusyException(MigrationLockMessages::ipQueueBusy((int) $migration->id, (string) $router->name));
            } catch (\Throwable $e) {
                return $this->fail($migration, 'PPPoE migrasi gagal: '.MigrationNormalizer::boundedError($e));
            }
        }
        $migration = $migration->fresh() ?? $migration;
        $customer = $customer->fresh() ?? $customer;

        // An ONU that is already registered on the target OLT (e.g. installed
        // manually before the migration) never shows up in uncfg. Link it via
        // the snapshot instead of waiting forever for an uncfg entry.
        $registeredOnu = trim((string) $migration->new_pon_port) === ''
            && $this->partials()->registeredOnuForSn($migration, $olt) !== null;

        if (trim((string) $migration->new_sn) === '') {
            $uncfg = $this->findUncfgForMigration($migration, $olt);
            if (($uncfg['found'] ?? false) !== true) {
                $migration->status = CustomerMigration::STATUS_WAITING_SN;
                $migration->last_error = $uncfg['message'] ?? 'Menunggu SN FiberHome baru di uncfg.';
                $migration->save();
                $this->replyIfWaitingForNewSn($migration);

                return $migration;
            }
            $this->applyUncfg($migration, $uncfg);
        }

        if (! $registeredOnu && trim((string) $migration->new_pon_port) === '') {
            $uncfg = $this->findUncfgForMigration($migration, $olt);
            if (($uncfg['found'] ?? false) !== true) {
                $migration->status = CustomerMigration::STATUS_WAITING_SN;
                $migration->last_error = $uncfg['message'] ?? 'SN belum muncul di uncfg OLT.';
                $migration->save();
                $this->replyIfWaitingForNewSn($migration);

                return $migration;
            }
            $this->applyUncfg($migration, $uncfg);
        }

        try {
            $this->provisionNewOnu($migration, $olt, $router, $customer);
        } catch (LockBusyException $e) {
            // B5: the PON lock is busy. The MikroTik step is normally already
            // done, so this is a partial state, never "gagal". The caller sends
            // exactly ONE partial message that carries the busy text.
            $migration = $migration->fresh() ?? $migration;
            if (! $this->ledgers()->deviceWriteMarkerPresent($migration)) {
                throw $e; // no device write recorded: caller reverts the ledger
            }
            $meta = MigrationNormalizer::meta($migration);
            $meta['timeline'] = MigrationNormalizer::appendTimeline($meta['timeline'] ?? [], 'olt_pon_lock_busy', [
                'olt_id' => $olt->id,
                'port' => $migration->new_pon_port,
            ]);
            $migration->meta = $meta;
            $migration->last_error = $e->userMessage();
            $migration->save();

            return $migration;
        } catch (\Throwable $e) {
            return $this->fail($migration, MigrationNormalizer::boundedError($e));
        }
        $migration = $migration->fresh() ?? $migration;
        $this->syncCustomerAfterNewOnu($migration, $customer);
        $this->syncTr069Tag($migration, $customer->fresh() ?? $customer);
        $this->deleteOldOnuIfSafe($migration);

        $migration->status = CustomerMigration::STATUS_SYNCED;
        if (! str_contains((string) $migration->last_error, 'hapus ONU lama gagal')) {
            $migration->last_error = null;
        }
        $migration->synced_at = now();
        $migration->save();

        $migration = $migration->fresh() ?? $migration;
        [$prefix, $mentions] = $this->texts()->mentionPrefixForMigration($migration);
        $routerCredential = $this->readVerifiedMigrationPassword($migration);
        $this->reply((string) $migration->group_jid, $prefix.$this->successMessage($migration, $routerCredential), $mentions);

        return $migration->fresh() ?? $migration;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $parsed
     */
    protected function findOrCreateMigration(array $payload, array $parsed): CustomerMigration
    {
        $groupJid = (string) ($payload['group_jid'] ?? '');
        $pppoe = MigrationNormalizer::normalizeUsername((string) ($parsed['pppoe_user'] ?? ''));
        $newSn = MigrationNormalizer::normalizeSerial((string) ($parsed['new_sn'] ?? ''));
        $id = $parsed['migration_id'] ?? null;

        if ($id) {
            $found = CustomerMigration::query()->whereKey((int) $id)->first();
            if ($found) {
                return $found;
            }
        }

        if ($pppoe !== '' && $newSn !== '') {
            $targetRouter = null;
            $targetAlias = trim((string) ($parsed['new_router_field'] ?? $parsed['router_field'] ?? ''));
            if ($targetAlias !== '') {
                $targetRouter = $this->resolveMigrationRouterAlias($targetAlias);
            }
            if (! $targetRouter) {
                $targetRouter = $this->resolver()->findCustomerByPppoe($pppoe)?->router;
            }
            if ($targetRouter) {
                $stableMatch = CustomerMigration::query()
                    ->where('router_id', $targetRouter->id)
                    ->whereNotIn('status', [CustomerMigration::STATUS_CANCELLED])
                    ->whereRaw('UPPER(REPLACE(REPLACE(COALESCE(new_sn, ""), "-", ""), " ", "")) = ?', [$newSn])
                    ->get(['id', 'pppoe_user', 'meta', 'status', 'group_jid', 'message_id'])
                    ->first(fn (CustomerMigration $row): bool => trim((string) $row->pppoe_user) === $pppoe);
                if ($stableMatch) {
                    $stableMatch->setAttribute('_stable_duplicate', true);

                    return $stableMatch;
                }
            }
        }

        if ($pppoe !== '') {
            $found = CustomerMigration::query()
                ->where('group_jid', $groupJid)
                ->where('pppoe_user', $pppoe)
                ->whereBetween('created_at', [now('Asia/Jakarta')->startOfDay()->utc(), now('Asia/Jakarta')->endOfDay()->utc()])
                ->whereNotIn('status', [CustomerMigration::STATUS_SYNCED, CustomerMigration::STATUS_CANCELLED])
                ->orderByDesc('id')
                ->first();
            if ($found) {
                return $found;
            }
        }

        // Guided mode never reuses a recent sender implicitly; explicit #ID/PPPoE matched above.
        if ($this->guidedLookup->canUseWa($payload)) {
            return new CustomerMigration;
        }

        $sender = (string) ($payload['sender_jid'] ?? '');
        $recent = CustomerMigration::query()
            ->where('group_jid', $groupJid)
            ->where('sender_jid', $sender)
            ->where('created_at', '>=', now()->subMinutes(30))
            ->whereNotIn('status', [CustomerMigration::STATUS_SYNCED, CustomerMigration::STATUS_CANCELLED])
            ->orderByDesc('id')
            ->first();

        return $recent ?: new CustomerMigration;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $parsed
     */
    protected function applyPayload(CustomerMigration $migration, array $payload, array $parsed): void
    {
        $pppoe = MigrationNormalizer::normalizeUsername((string) ($parsed['pppoe_user'] ?? ''));
        $newSn = MigrationNormalizer::normalizeSerial((string) ($parsed['new_sn'] ?? ''));
        $oldSn = MigrationNormalizer::normalizeSerial((string) ($parsed['old_sn'] ?? ''));

        $fill = [
            'status' => CustomerMigration::STATUS_RECEIVED,
            'source' => $migration->source ?: 'wa',
            'group_jid' => (string) ($payload['group_jid'] ?? $migration->group_jid),
            'sender_jid' => filled($migration->sender_jid)
                ? (string) $migration->sender_jid
                : (string) ($payload['sender_jid'] ?? ''),
            'sender_name' => filled($migration->sender_name)
                ? (string) $migration->sender_name
                : (string) ($payload['sender_name'] ?? ''),
            'message_id' => $migration->message_id ?: (string) ($payload['message_id'] ?? ''),
            'raw_text' => (string) ($parsed['raw'] ?? $payload['text'] ?? $migration->raw_text),
            'pppoe_user' => $pppoe !== '' ? $pppoe : $migration->pppoe_user,
            'full_name' => $parsed['full_name'] ?: $migration->full_name,
            'phone' => $parsed['phone'] ?: $migration->phone,
            'nik' => $parsed['nik'] ?: $migration->nik,
            'rt' => $parsed['rt'] ?: $migration->rt,
            'rw' => $parsed['rw'] ?: $migration->rw,
            'odp' => $parsed['odp'] ?: $migration->odp,
            'tikor' => $parsed['tikor'] ?: $migration->tikor,
            'daerah' => $parsed['daerah'] ?: $migration->daerah,
            'new_sn' => $newSn !== '' ? $newSn : $migration->new_sn,
            'old_sn' => $oldSn !== '' ? $oldSn : $migration->old_sn,
            'old_onu_ref' => $parsed['old_onu_ref'] ?: $migration->old_onu_ref,
            'new_pon_port' => $parsed['pon_port'] ?: $migration->new_pon_port,
            'vlan' => $parsed['vlan_field'] ?: $migration->vlan,
            'tr069_vlan' => $parsed['tr069_vlan_field'] ?: $migration->tr069_vlan,
        ];

        $oldRouter = null;
        $newRouter = null;
        if (! empty($parsed['old_router_field'])) {
            $oldRouter = $this->resolveMigrationRouterAlias((string) $parsed['old_router_field']);
            if ($oldRouter) {
                $fill['old_router_id'] = $oldRouter->id;
            }
        }
        if (! empty($parsed['new_router_field'])) {
            $newRouter = $this->resolveMigrationRouterAlias((string) $parsed['new_router_field']);
        } elseif (! empty($parsed['router_field'])) {
            $newRouter = $this->resolveMigrationRouterAlias((string) $parsed['router_field']);
        }
        if ($newRouter) {
            $fill['router_id'] = $newRouter->id;
        }

        if (! empty($parsed['daerah'])) {
            $area = $this->resolver()->resolveArea((string) $parsed['daerah']);
            if ($area) {
                $fill['area_id'] = $area->id;
            }
        }

        $isNewMigration = ! $migration->exists;
        $migration->fill(array_filter($fill, fn ($v) => $v !== null && $v !== ''));

        $meta = MigrationNormalizer::meta($migration);
        if ($isNewMigration && (array) ($meta['wa_origin_sender_keys'] ?? []) === []) {
            $meta['wa_origin_sender_keys'] = $this->senders()->waSenderKeys($payload);
        }
        if ($pppoe !== '') {
            $meta['input_identity']['pppoe_user'] = $pppoe;
        }
        if (! empty($parsed['old_router_field']) || ! empty($parsed['new_router_field'])) {
            $meta['router_mapping'] = [
                'source_input' => trim((string) ($parsed['old_router_field'] ?? '')) ?: null,
                'target_input' => trim((string) ($parsed['new_router_field'] ?? $parsed['router_field'] ?? '')) ?: null,
                'source_router_id' => $oldRouter?->id,
                'target_router_id' => $newRouter?->id,
                'cross_router' => $oldRouter && $newRouter && $oldRouter->id !== $newRouter->id,
                'status' => ($parsed['old_router_field'] ?? null) && ! $oldRouter
                    ? 'source_router_unresolved'
                    : (($parsed['new_router_field'] ?? $parsed['router_field'] ?? null) && ! $newRouter
                        ? 'target_router_unresolved'
                        : 'resolved'),
            ];
        }
        if (! empty($parsed['package_field'])) {
            $meta['package_hint'] = (string) $parsed['package_field'];
            $package = $this->resolver()->resolvePackageFromHint((string) $parsed['package_field'], isset($fill['router_id']) ? (int) $fill['router_id'] : null);
            if ($package) {
                $meta['package_id'] = $package->id;
                $meta['mikrotik_profile'] = $package->mikrotik_profile;
                $meta['package_name'] = $package->name;
            }
        }
        $migration->meta = $meta;
        $this->attachStoredInboundImages($migration, (array) ($payload['image_paths'] ?? []), (string) ($parsed['raw'] ?? $payload['text'] ?? ''));
        $migration->save();
    }

    /** @param array<string, mixed> $payload @param array<string, mixed> $operation */
    protected function confirmReplacementFromWa(CustomerMigration $migration, array $payload, array $operation): CustomerMigration
    {
        return $this->guidedFlow()->confirmReplacementFromWa($migration, $payload, $operation);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function psbOverlapEvidence(CustomerMigration $migration): array
    {
        return $this->evidence()->psbOverlapEvidence($migration);
    }

    /**
     * @return array<string, mixed>
     */
    protected function customerSnapshot(Customer $customer): array
    {
        return $this->evidence()->customerSnapshot($customer);
    }

    /**
     * @return array<string, mixed>
     */
    protected function proposedCustomerSnapshot(CustomerMigration $migration, ?Customer $customer, ?Olt $targetOlt = null): array
    {
        return $this->evidence()->proposedCustomerSnapshot($migration, $customer, $targetOlt);
    }

    protected function resolveCustomer(CustomerMigration $migration): ?Customer
    {
        if ($migration->customer_id) {
            $customer = Customer::query()->find($migration->customer_id);
            if ($customer) {
                return $customer;
            }
        }

        if ($migration->pppoe_user) {
            $customer = $this->resolver()->findCustomerByPppoe((string) $migration->pppoe_user);
            if ($customer) {
                $migration->customer_id = $customer->id;
                $migration->save();

                return $customer;
            }
        }

        if ($migration->full_name) {
            $needle = trim((string) $migration->full_name);
            $matches = Customer::query()
                ->where('name', 'like', '%'.str_replace(['%', '_'], '', $needle).'%')
                ->limit(3)
                ->get();
            if ($matches->count() === 1) {
                $customer = $matches->first();
                $migration->customer_id = $customer->id;
                $migration->pppoe_user = $customer->pppoe_user;
                $migration->save();

                return $customer;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @return array{pppoe: ?string, source: string, customer_id?: int}
     */
    protected function resolvePppoeForHuaweiDraft(array $parsed, string $oldSn, string $targetRouterField): array
    {
        return $this->resolver()->resolvePppoeForHuaweiDraft($parsed, $oldSn, $targetRouterField);
    }

    protected function primeMigrationFromCustomer(CustomerMigration $migration, Customer $customer): void
    {
        $this->resolver()->primeMigrationFromCustomer($migration, $customer);
    }

    protected function resolveRouterForMigration(CustomerMigration $migration, Customer $customer): ?Router
    {
        return $this->resolver()->resolveRouterForMigration($migration, $customer);
    }

    protected function resolveSourceRouterForMigration(CustomerMigration $migration, Customer $customer): ?Router
    {
        return $this->resolver()->resolveSourceRouterForMigration($migration, $customer);
    }

    protected function resolveMigrationRouterAlias(string $alias): ?Router
    {
        return $this->resolver()->resolveMigrationRouterAlias($alias);
    }

    protected function resolveOltForMigration(CustomerMigration $migration, Customer $customer, ?Router $router): ?Olt
    {
        return $this->resolver()->resolveOltForMigration($migration, $customer, $router);
    }

    protected function resolveOltFromMigrationField(CustomerMigration $migration): ?Olt
    {
        return $this->resolver()->resolveOltFromMigrationField($migration);
    }

    /**
     * @return array{found: ?bool, warning?: string}
     */
    protected function verifyExistingPppoeSecret(Customer $customer, Router $router): array
    {
        return $this->readPppoeSecretProfile($router, trim((string) $customer->pppoe_user));
    }

    /**
     * Read-only lookup of one PPPoE secret: existence plus its live profile.
     *
     * @return array{found: ?bool, profile?: string, warning?: string}
     */
    protected function readPppoeSecretProfile(Router $router, string $user): array
    {
        if ($user === '') {
            return ['found' => false];
        }

        try {
            $this->mikrotik->connect($router, ['timeout' => 12, 'attempts' => 1]);
            try {
                $secret = $this->mikrotik->getPPPSecret($user);
                if ($secret === null) {
                    return ['found' => false];
                }

                return ['found' => true, 'profile' => trim((string) ($secret['profile'] ?? ''))];
            } finally {
                $this->mikrotik->disconnect();
            }
        } catch (\Throwable $e) {
            return [
                'found' => null,
                'warning' => 'MikroTik check dilewati: '.SensitiveDataRedactor::redact($e->getMessage()),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function findUncfgForMigration(CustomerMigration $migration, Olt $olt): array
    {
        $ctrl = app(OltC300Controller::class);
        $req = Request::create('/api/olt-c300/list-uncfg', 'POST', ['olt_id' => $olt->id]);
        $res = $ctrl->listUncfg($req);
        $data = $res->getData(true);
        if (! empty($data['error'])) {
            return ['found' => false, 'message' => $data['user_hint'] ?? $data['error']];
        }

        $onus = is_array($data['onus'] ?? null) ? $data['onus'] : [];
        $this->uncfgCache[$olt->id] = array_values(array_filter($onus, 'is_array'));
        $wantSn = MigrationNormalizer::normalizeSerial((string) $migration->new_sn);
        foreach ($onus as $u) {
            $sn = MigrationNormalizer::normalizeSerial((string) ($u['sn'] ?? ''));
            if ($sn === '' || $sn === 'TIDAKTERBACA') {
                $sn = $wantSn;
            }
            if ($wantSn !== '' && $sn !== '' && $sn !== $wantSn && ! str_contains($sn, $wantSn) && ! str_contains($wantSn, $sn)) {
                continue;
            }
            if ($wantSn === '' && count($onus) !== 1) {
                continue;
            }

            $port = MigrationNormalizer::normalizePonPort((string) ($u['port'] ?? ''));
            if (! $port) {
                continue;
            }
            $vlan = $this->resolver()->vlanForOltPort($olt, $port);
            if ($vlan === null) {
                return [
                    'found' => false,
                    'message' => "VLAN mapping belum ada untuk PON {$port} di OLT {$olt->name}. Isi VLAN Mapping OLT dulu.",
                    'port' => $port,
                    'sn' => $sn ?: $wantSn,
                ];
            }

            return [
                'found' => true,
                'sn' => $sn ?: $wantSn,
                'port' => $port,
                'onu_id' => isset($u['onu_id']) ? (int) $u['onu_id'] : null,
                'vlan' => $vlan,
            ];
        }

        return [
            'found' => false,
            'message' => $wantSn !== ''
                ? "SN {$wantSn} belum muncul di uncfg (terbaca ".count($onus).' ONU lain).'
                : 'SN belum dikirim dan uncfg tidak tunggal.',
        ];
    }

    /**
     * @param  array<string, mixed>  $uncfg
     */
    protected function applyUncfg(CustomerMigration $migration, array $uncfg): void
    {
        if (! empty($uncfg['sn'])) {
            $migration->new_sn = MigrationNormalizer::normalizeSerial((string) $uncfg['sn']);
        }
        if (! empty($uncfg['port'])) {
            $migration->new_pon_port = MigrationNormalizer::normalizePonPort((string) $uncfg['port']);
        }
        if (isset($uncfg['onu_id'])) {
            $migration->new_onu_id = (int) $uncfg['onu_id'];
        }
        if (isset($uncfg['vlan'])) {
            $migration->vlan = (int) $uncfg['vlan'];
        }
        $migration->save();
    }

    protected function provisionNewOnu(CustomerMigration $migration, Olt $olt, Router $router, Customer $customer): void
    {
        $sn = MigrationNormalizer::normalizeSerial((string) $migration->new_sn);
        $port = MigrationNormalizer::normalizePonPort((string) $migration->new_pon_port);
        $pppoe = MigrationNormalizer::normalizeUsername((string) $customer->pppoe_user);
        if ($sn !== '' && $pppoe !== '' && $this->markExistingNewOnuIfPresent($migration, $olt)) {
            return;
        }

        if ($sn === '' || ! $port || $pppoe === '') {
            throw new \RuntimeException('SN/PON/PPPoE migrasi belum lengkap.');
        }

        $meta = MigrationNormalizer::meta($migration);
        if (! empty($meta['olt_provisioned_at']) && $migration->olt_at) {
            return;
        }

        $ponLock = Cache::lock('device-olt-pon-'.$olt->id.'-'.$port, 240);
        if (! CacheLockWait::acquire($ponLock, 5)) {
            throw new LockBusyException(MigrationLockMessages::ponBusy((string) ($olt->name ?: 'OLT #'.$olt->id), (string) $port));
        }

        try {
            $req = Request::create('/api/olt-c300/provision-fiberhome', 'POST', [
                'olt_id' => $olt->id,
                'sn' => $sn,
                'port' => $port,
                'name' => $pppoe,
                'paket' => $this->resolver()->packageSpeed($customer, $migration),
                'router_id' => $router->id,
                'onu_type' => 'ALL',
                'dry_run' => false,
                'purpose' => 'migration_wa',
            ]);
            $req->attributes->set(
                'olt_provisioning_approval',
                app(OltProvisioningGuard::class)->issueInternalApproval($olt, 'migration_wa', 'migration-'.$migration->id),
            );
            $res = app(OltC300Controller::class)->provisionFiberhome($req);
            $data = $res->getData(true);
            if ($res->getStatusCode() >= 400 || empty($data['success'])) {
                throw new \RuntimeException($data['error'] ?? ('provision HTTP '.$res->getStatusCode()));
            }

            if (! empty($data['onu_id'])) {
                $migration->new_onu_id = (int) $data['onu_id'];
            }
            if (! empty($data['port'])) {
                $migration->new_pon_port = MigrationNormalizer::normalizePonPort((string) $data['port']);
            }
            if (! empty($data['vlan'])) {
                $migration->vlan = (int) $data['vlan'];
            }
            if (! empty($data['tr069_vlan'])) {
                $migration->tr069_vlan = (int) $data['tr069_vlan'];
            }
            if ($migration->new_pon_port && $migration->new_onu_id) {
                $migration->new_onu_ref = "{$migration->new_pon_port}:{$migration->new_onu_id}";
            }
            $migration->new_sn = $sn;
            $migration->status = CustomerMigration::STATUS_OLT_OK;
            $migration->olt_at = now();
            $meta['olt_provisioned_at'] = now()->toIso8601String();
            $meta['olt_response'] = [
                'onu_id' => $migration->new_onu_id,
                'port' => $migration->new_pon_port,
                'vlan' => $migration->vlan,
                'tr069_vlan' => $migration->tr069_vlan,
                'post_warnings' => $data['post_warnings'] ?? [],
            ];
            $migration->meta = $meta;
            $migration->save();
        } finally {
            $ponLock->release();
        }
    }

    protected function markExistingNewOnuIfPresent(CustomerMigration $migration, Olt $olt): bool
    {
        $sn = MigrationNormalizer::normalizeSerial((string) $migration->new_sn);
        if ($sn === '' || ! Schema::hasTable('olt_onus')) {
            return false;
        }

        $onu = OltOnu::query()
            ->where('olt_id', $olt->id)
            ->whereRaw('UPPER(REPLACE(REPLACE(COALESCE(serial_number, ""), "-", ""), " ", "")) = ?', [$sn])
            ->latest('id')
            ->first();
        if (! $onu) {
            return false;
        }
        $customer = $this->resolveCustomer($migration);
        if (! $this->evidence()->existingOnuBelongsToMigration($migration, $customer, $onu)) {
            throw new \RuntimeException(
                "ONU baru {$sn} sudah dipakai ONU lain {$onu->onu_ref} ({$onu->onu_name}); new_sn_in_use_by_other_onu."
            );
        }
        $state = OltOnu::normalizeOperationalState($onu->state, $onu->phase_state, $onu->rx_power_dbm);
        if ($state !== 'online') {
            throw new \RuntimeException("ONU baru {$sn} sudah ada di OLT {$olt->name}, tapi status {$state}; stop sebelum hapus ONU lama.");
        }

        $ref = MigrationNormalizer::normalizeOnuRef((string) $onu->onu_ref);
        $port = MigrationNormalizer::normalizePonPort((string) ($onu->pon_port ?: preg_replace('/:\d+$/', '', $ref)));
        $onuId = null;
        if (preg_match('/:(\d+)$/', $ref, $m)) {
            $onuId = (int) $m[1];
        }

        // The ONU name must follow the PPPoE (USERAB27..., never a truncated
        // USERAB7). The local inventory is renamed on sync; a mismatch on the
        // OLT itself is surfaced for a manual rename via the OLT menu.
        $expectedName = MigrationNormalizer::normalizeUsername((string) ($customer?->pppoe_user ?: $migration->pppoe_user));
        $nameMismatch = $expectedName !== ''
            && trim((string) $onu->onu_name) !== ''
            && MigrationNormalizer::onuNameToken((string) $onu->onu_name) !== MigrationNormalizer::onuNameToken($expectedName);

        $meta = MigrationNormalizer::meta($migration);
        $meta['olt_provisioned_at'] = now()->toIso8601String();
        $meta['olt_existing_onu'] = [
            'ok' => true,
            'onu_ref' => $ref ?: null,
            'sn' => $sn,
            'state' => $onu->state,
            'phase_state' => $onu->phase_state,
            'normalized_state' => $state,
            'source' => 'olt_onus_snapshot',
            'onu_name_on_olt' => $onu->onu_name,
            'expected_onu_name' => $expectedName ?: null,
            'name_mismatch' => $nameMismatch,
        ];

        $migration->new_onu_ref = $ref ?: $migration->new_onu_ref;
        $migration->new_pon_port = $port ?: $migration->new_pon_port;
        if ($onuId) {
            $migration->new_onu_id = $onuId;
        }
        if ($port && ! $migration->vlan) {
            $migration->vlan = $this->resolver()->vlanForOltPort($olt, $port);
        }
        $migration->status = CustomerMigration::STATUS_OLT_OK;
        $migration->olt_at = now();
        $migration->meta = $meta;
        $migration->save();

        return true;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    protected function syncCustomerAfterNewOnu(CustomerMigration $migration, Customer $customer, array $extra = []): void
    {
        $package = $this->resolver()->resolvePackageForMigration($migration, $customer);
        $migrationMeta = MigrationNormalizer::meta($migration);
        $liveMigrationProfile = trim((string) data_get($migrationMeta, 'mikrotik_migration.profile', ''));
        $update = [
            'router_id' => $migration->router_id ?: $customer->router_id,
            'olt_id' => $migration->new_olt_id ?: $customer->olt_id,
            'olt_port_label' => $migration->new_onu_ref ?: $customer->olt_port_label,
            'onu_serial' => $migration->new_sn ?: $customer->onu_serial,
            'olt_status' => $extra['olt_status'] ?? $customer->olt_status ?? 'online',
            'olt_last_synced_at' => now(),
            'mikrotik_sync_status' => 'synced',
            'mikrotik_synced_at' => now(),
        ];
        if ($package) {
            $update['package_id'] = $package->id;
            if ($liveMigrationProfile !== '' && (bool) data_get($migrationMeta, 'mikrotik_migration.cross_router', false)) {
                $update['mikrotik_profile'] = $liveMigrationProfile;
            } elseif ($package->mikrotik_profile) {
                $update['mikrotik_profile'] = $package->mikrotik_profile;
            }
        }

        if ($migration->full_name) {
            $update['name'] = $migration->full_name;
        }
        if ($migration->phone) {
            $update['phone'] = $migration->phone;
        }
        if ($migration->nik && Schema::hasColumn('customers', 'nik')) {
            $update['nik'] = $migration->nik;
        }
        if ($migration->area_id) {
            $update['area_id'] = $migration->area_id;
        }
        if ($migration->odp && Schema::hasColumn('customers', 'odp_name')) {
            $update['odp_name'] = $migration->odp;
        }
        if ($migration->ktp_photo_path && Schema::hasColumn('customers', 'ktp_photo_url')) {
            $cur = (string) ($customer->ktp_photo_url ?? '');
            if ($cur === '' || str_contains($cur, '/psb/') || str_contains($cur, 'psb/') || str_contains($cur, '/migrasi/')) {
                $update['ktp_photo_url'] = $migration->ktp_photo_path;
            }
        }
        if ($migration->router_photo_path && Schema::hasColumn('customers', 'odp_photo_url')) {
            $cur = (string) ($customer->odp_photo_url ?? '');
            if ($cur === '' || str_contains($cur, '/psb/') || str_contains($cur, 'psb/') || str_contains($cur, '/migrasi/') || preg_match('#/ktp_#i', $cur)) {
                $update['odp_photo_url'] = $migration->router_photo_path;
            }
        }
        if ($migration->tikor && preg_match('/(-?\d+\.?\d*)\s*,\s*(-?\d+\.?\d*)/', (string) $migration->tikor, $m)) {
            $update['geo_lat'] = $m[1];
            $update['geo_long'] = $m[2];
        }

        $addressParts = [];
        if ($migration->rt || $migration->rw) {
            $addressParts[] = trim('RT '.($migration->rt ?: '-').' / RW '.($migration->rw ?: '-'));
        }
        if ($migration->odp) {
            $addressParts[] = 'ODP '.$migration->odp;
        }
        if ($migration->daerah) {
            $addressParts[] = $migration->daerah;
        }
        if ($addressParts !== []) {
            $update['address'] = implode(' - ', $addressParts);
        }

        $customer->forceFill(array_filter($update, fn ($v) => $v !== null && $v !== ''))->save();

        if (Schema::hasTable('olt_onus') && $migration->new_olt_id && $migration->new_onu_ref) {
            OltOnu::query()
                ->where('olt_id', $migration->new_olt_id)
                ->whereRaw('LOWER(onu_ref) = ?', [strtolower((string) $migration->new_onu_ref)])
                ->update([
                    'serial_number' => $migration->new_sn,
                    'onu_name' => $customer->pppoe_user,
                    'name_source' => 'migration',
                    'inventory_collected_at' => now(),
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * Live PPPoE step for an approved migration execution.
     *
     * @return array<string, mixed>
     */
    protected function applyMigrationPppoeSecret(CustomerMigration $migration, Customer $customer, Router $router): array
    {
        $oldUser = MigrationNormalizer::normalizeUsername((string) ($migration->pppoe_user ?: $customer->pppoe_user));
        $sourceRouter = $this->resolveSourceRouterForMigration($migration, $customer);
        $preflight = [
            'customer_before' => $this->customerSnapshot($customer),
            'router' => ['id' => $router->id, 'name' => $router->name],
            'source_router' => $sourceRouter ? ['id' => $sourceRouter->id, 'name' => $sourceRouter->name] : null,
        ];
        $plan = $this->pppoeMigrationPlan($migration, $preflight);
        $newUser = MigrationNormalizer::normalizeUsername((string) ($plan['target_username'] ?? $oldUser));
        $package = $this->resolver()->resolvePackageForMigration($migration, $customer);
        $profile = trim((string) ($package?->mikrotik_profile ?? ''));
        if ($profile === '' && $sourceRouter && $oldUser !== '') {
            // Package without a MikroTik profile: keep the live source profile
            // instead of a possibly stale customers.mikrotik_profile.
            $live = $this->readPppoeSecretProfile($sourceRouter, $oldUser);
            $profile = ($live['found'] ?? null) === true ? trim((string) ($live['profile'] ?? '')) : '';
        }
        if ($profile === '') {
            $profile = trim((string) ($customer->mikrotik_profile ?? ''));
        }
        $comment = $this->migrationSecretComment($migration);

        if ($oldUser === '' || $newUser === '') {
            throw new \RuntimeException('PPPoE lama/baru kosong.');
        }
        if ($profile === '') {
            throw new \RuntimeException('Paket tidak punya profil MikroTik — pilih paket atau isi profil (package_profile_missing:package_'.($package?->id ?: 'none').').');
        }

        if (! $sourceRouter) {
            throw new \RuntimeException('Router lama migrasi belum dapat dipastikan. Isi Router lama dengan alias yang valid.');
        }

        $crossRouter = $sourceRouter->id !== $router->id;

        $persist = function (array $result, array $verified, array $extra = []) use ($migration, $customer, $router, $sourceRouter, $oldUser, $newUser, $profile, $comment, $package): array {
            $persistProfile = trim((string) ($extra['profile'] ?? $profile));
            $meta = MigrationNormalizer::meta($migration);
            $meta['mikrotik_migration'] = array_merge([
                'ok' => true,
                'old_username' => $oldUser,
                'new_username' => $newUser,
                'profile' => $persistProfile,
                'comment' => $comment,
                'result' => $result,
                'source_router' => ['id' => $sourceRouter->id, 'name' => $sourceRouter->name],
                'target_router' => ['id' => $router->id, 'name' => $router->name],
                'cross_router' => $sourceRouter->id !== $router->id,
                'verified_at' => now()->toIso8601String(),
            ], $extra);
            $migration->old_router_id = $sourceRouter->id;
            $migration->router_id = $router->id;
            $migration->pppoe_user = $newUser;
            $migration->meta = $meta;
            $migration->save();

            $update = [
                'pppoe_user' => $newUser,
                'router_id' => $router->id,
                'mikrotik_profile' => $persistProfile,
                'mikrotik_sync_status' => 'synced',
                'mikrotik_synced_at' => now(),
                'mikrotik_sync_checked_at' => now(),
            ];
            $verifiedPassword = trim((string) ($verified['password'] ?? ''));
            if ($verifiedPassword !== '' && preg_match('/^\*+$/', $verifiedPassword) !== 1) {
                $update['pppoe_password'] = $verifiedPassword;
            }
            foreach ([
                'pppoe_local_address' => ['local-address', 'local_address'],
                'pppoe_remote_address' => ['remote-address', 'remote_address'],
            ] as $column => $keys) {
                foreach ($keys as $key) {
                    $address = trim((string) ($verified[$key] ?? ''));
                    if ($address !== '') {
                        $update[$column] = $address;
                        break;
                    }
                }
            }
            if ($package) {
                $update['package_id'] = $package->id;
            }
            if ($migration->odp && Schema::hasColumn('customers', 'odp_name')) {
                $update['odp_name'] = $migration->odp;
            }
            $customer->forceFill($update)->save();

            return $meta['mikrotik_migration'];
        };

        if (! $crossRouter) {
            $this->mikrotik->connect($router, ['timeout' => 12, 'attempts' => 1]);
            try {
                $targetProfile = $this->resolveLiveMigrationProfile($profile);
                $result = $this->mikrotik->renamePPPSecret(
                    oldUsername: $oldUser,
                    newUsername: $newUser,
                    profile: $targetProfile,
                    comment: $comment
                );
                $verified = $this->mikrotik->getPPPSecret($newUser);
                if (! $verified) {
                    throw new \RuntimeException("Secret {$newUser} belum verified setelah update.");
                }
                $verifiedPassword = trim((string) ($verified['password'] ?? ''));
                if ($verifiedPassword === '' || preg_match('/^\*+$/', $verifiedPassword) === 1) {
                    throw new \RuntimeException('Password PPPoE tidak terbaca setelah update; database tidak diubah.');
                }
            } finally {
                $this->mikrotik->disconnect();
            }

            return $persist($result, $verified, [
                'profile' => $targetProfile,
                'source_secret_action' => 'same_router_rename',
            ]);
        }

        // Cross-router migration is copy-then-cutover: read the password from
        // the old router, create and verify the target secret first, then
        // disable (never delete) the old secret for rollback safety.
        $this->mikrotik->connect($sourceRouter, ['timeout' => 12, 'attempts' => 1]);
        try {
            $sourceSecret = $this->mikrotik->getPPPSecret($oldUser);
        } finally {
            $this->mikrotik->disconnect();
        }
        if (! $sourceSecret) {
            throw new \RuntimeException("Secret lama {$oldUser} tidak ditemukan di router {$sourceRouter->name}.");
        }

        $password = trim((string) ($sourceSecret['password'] ?? ''));
        if ($password === '' || preg_match('/^\*+$/', $password) === 1) {
            throw new \RuntimeException('Password PPPoE lama tidak terbaca dari router sumber; migrasi dihentikan aman tanpa fallback database.');
        }
        $passwordSource = 'source_router';

        $targetPrepared = false;
        $targetExisting = null;
        $allocated = null;
        $targetVerified = null;
        $targetProfile = $profile;
        $targetIpSource = 'target_profile';
        $adoptedFrom = null;

        $this->mikrotik->connect($router, ['timeout' => 12, 'attempts' => 1]);
        try {
            $targetProfile = $this->resolveLiveMigrationProfile($profile);
            $targetExisting = $this->mikrotik->getPPPSecret($newUser);
            $pending = (array) data_get(MigrationNormalizer::meta($migration), 'mikrotik_migration', []);
            $resumableTarget = $targetExisting
                && ($pending['cross_router'] ?? false) === true
                && (int) data_get($pending, 'target_router.id', 0) === $router->id
                && (string) ($pending['new_username'] ?? '') === $newUser;
            if ($targetExisting && ! $resumableTarget) {
                // A previous migration of the SAME customer (e.g. wrong SN, then
                // cancelled) already copied this secret to the target. Re-use it
                // instead of blocking; the password is re-checked against source below.
                $adoptedFrom = $this->priorMigrationOwningTargetSecret($migration, $router, $newUser);
                $resumableTarget = $adoptedFrom !== null;
            }
            if ($targetExisting && ! $resumableTarget) {
                throw new \RuntimeException("Secret target {$newUser} sudah ada di router {$router->name}; source dan target tidak diubah agar aman.");
            }

            $localAddress = trim((string) ($targetExisting['local-address'] ?? '')) ?: null;
            $remoteAddress = trim((string) ($targetExisting['remote-address'] ?? '')) ?: null;
            if (! $targetExisting) {
                $liveSecrets = $this->mikrotik->getPPPSecretsWithAddresses();
                // Resolve the site key of the TARGET router so a cross-router
                // migration matches the pool blocks that are scoped to that site
                // (e.g. Krian-OLT blocks carry site_profile_key=krian). Passing
                // null only matched global blocks, so scoped pools returned no
                // address and the migrated secret had no local/remote IP.
                $siteRecord = app(PsbSiteRegistry::class)->byRouterId((int) $router->id);
                $siteKey = is_array($siteRecord) ? ($siteRecord['key'] ?? null) : null;
                $allocator = app(PppoeAddressPoolAllocator::class);
                $allocated = $allocator->allocate(
                    $router,
                    $liveSecrets,
                    $siteKey,
                    $targetProfile,
                    $migration->daerah ?: null
                );
                // Fail closed: a target router that HAS pool blocks but yields no
                // allocation means site/profile/area filters did not match. Creating
                // the secret anyway leaves it without local/remote IP (the Krian-OLT
                // incident), so stop before any target write; source is untouched.
                if ($allocated === null && $allocator->hasActiveBlocks($router)) {
                    throw new \RuntimeException(sprintf(
                        'IP pool router %s tidak cocok untuk migrasi ini (site=%s, profile=%s, daerah=%s); secret target tidak dibuat. Cek site_profile_key/pppoe_profile/customer_area di block pool.',
                        $router->name,
                        $siteKey ?? '-',
                        $targetProfile,
                        $migration->daerah ?: '-'
                    ));
                }
                if ($allocated !== null) {
                    $localAddress = trim((string) ($allocated['local'] ?? '')) ?: null;
                    $remoteAddress = trim((string) ($allocated['remote'] ?? '')) ?: null;
                    $targetIpSource = 'target_allocator';
                }
            }

            $result = $this->mikrotik->addPPPSecret(
                username: $newUser,
                password: $password,
                profile: $targetProfile,
                localAddress: $localAddress,
                remoteAddress: $remoteAddress,
                comment: $comment
            );
            if (! ($result['success'] ?? false)) {
                throw new \RuntimeException((string) ($result['message'] ?? 'Gagal membuat secret target.'));
            }
            $targetVerified = $this->mikrotik->getPPPSecret($newUser);
            if (! $targetVerified) {
                throw new \RuntimeException("Secret {$newUser} belum verified di router target.");
            }
            $targetPassword = trim((string) ($targetVerified['password'] ?? ''));
            if ($targetPassword === '' || preg_match('/^\*+$/', $targetPassword) === 1) {
                throw new \RuntimeException('Password PPPoE target tidak terbaca setelah write; database tidak diubah.');
            }
            if (! hash_equals(hash('sha256', $password), hash('sha256', $targetPassword))) {
                throw new \RuntimeException('Password PPPoE target berbeda dari source; database tidak diubah.');
            }
            $targetPrepared = true;
            $pendingMeta = MigrationNormalizer::meta($migration);
            $pendingMeta['mikrotik_migration'] = [
                'cross_router' => true,
                'old_username' => $oldUser,
                'new_username' => $newUser,
                'source_router' => ['id' => $sourceRouter->id, 'name' => $sourceRouter->name],
                'target_router' => ['id' => $router->id, 'name' => $router->name],
                'profile' => $targetProfile,
                'target_ip_source' => $targetIpSource,
                'target_prepared' => true,
                'prepared_at' => now()->toIso8601String(),
            ];
            if ($adoptedFrom !== null) {
                $pendingMeta['mikrotik_migration']['adopted_from_migration_id'] = $adoptedFrom->id;
                $pendingMeta['timeline'] = MigrationNormalizer::appendTimeline($pendingMeta['timeline'] ?? [], 'mikrotik_target_secret_adopted', [
                    'from_migration_id' => $adoptedFrom->id,
                    'router_id' => $router->id,
                    'pppoe_user' => $newUser,
                ]);
            }
            $migration->meta = $pendingMeta;
            $migration->save();
        } catch (\Throwable $e) {
            if ($allocated !== null) {
                try {
                    app(PppoeAddressPoolAllocator::class)->releaseFailedAllocation($router, (int) ($allocated['block_id'] ?? 0), (string) ($allocated['remote'] ?? ''));
                } catch (\Throwable $releaseError) {
                    Log::warning('Migration allocator rollback failed', ['migration_id' => $migration->id, 'error' => $releaseError->getMessage()]);
                }
            }
            throw $e;
        } finally {
            $this->mikrotik->disconnect();
        }

        if (! $targetPrepared || ! $targetVerified) {
            throw new \RuntimeException('Secret target belum siap untuk cutover.');
        }

        $this->mikrotik->connect($sourceRouter, ['timeout' => 12, 'attempts' => 1]);
        try {
            $sourceAction = $this->mikrotik->disablePPPSecret($oldUser);
        } finally {
            $this->mikrotik->disconnect();
        }

        return $persist($result, $targetVerified, [
            'profile' => $targetProfile,
            'password_source' => $passwordSource,
            'target_ip_source' => $targetIpSource,
            'source_secret_action' => $sourceAction['message'] ?? 'disabled',
        ] + ($adoptedFrom !== null ? ['adopted_from_migration_id' => $adoptedFrom->id] : []));
    }

    /**
     * Earlier migration of the same customer that already created this PPPoE
     * secret on the target router (cross-router copy recorded in its meta).
     * Only such a record may "own" an existing target secret; anything else
     * (another customer, manual secret) keeps blocking the migration.
     */
    protected function priorMigrationOwningTargetSecret(CustomerMigration $migration, Router $router, string $newUser): ?CustomerMigration
    {
        if (! $migration->customer_id) {
            return null;
        }

        return CustomerMigration::query()
            ->where('customer_id', $migration->customer_id)
            ->where('id', '!=', $migration->id)
            ->orderByDesc('id')
            ->get()
            ->first(function (CustomerMigration $prior) use ($router, $newUser): bool {
                $mt = (array) data_get(MigrationNormalizer::meta($prior), 'mikrotik_migration', []);

                return ($mt['cross_router'] ?? false) === true
                    && (int) data_get($mt, 'target_router.id', 0) === (int) $router->id
                    && strcasecmp((string) ($mt['new_username'] ?? ''), $newUser) === 0
                    && (($mt['target_prepared'] ?? false) === true || ! empty($mt['verified_at']));
            });
    }

    protected function resolveLiveMigrationProfile(string $desired): string
    {
        $desired = trim($desired);
        $profiles = $this->mikrotik->getProfiles();
        $names = collect($profiles)
            ->map(fn (array $row): string => trim((string) ($row['name'] ?? '')))
            ->filter()
            ->values();
        if ($names->isEmpty()) {
            throw new \RuntimeException("Profile PPPoE '{$desired}' tidak tersedia di router target.");
        }

        $exact = $names->first(fn (string $name): bool => strcasecmp($name, $desired) === 0);
        if ($exact) {
            return $exact;
        }

        $compact = static fn (string $value): string => strtoupper(str_replace('STARLITE', 'STARTLITE', preg_replace('/[^A-Z0-9]+/i', '', $value) ?? $value));
        $desiredCompact = $compact($desired);
        $normalized = $names->first(fn (string $name): bool => $compact($name) === $desiredCompact);
        if ($normalized) {
            return $normalized;
        }

        $upper = strtoupper($desired);
        if (str_contains($upper, 'STARTLITE') || str_contains($upper, 'STARLITE')) {
            $startlite = $names->filter(fn (string $name): bool => str_contains($compact($name), 'STARTLITE'));
            if (str_contains($upper, '200M')) {
                $withBandwidth = $startlite->first(fn (string $name): bool => str_contains($compact($name), '200M'));
                if ($withBandwidth) {
                    return $withBandwidth;
                }
            }
            if ($startlite->count() === 1) {
                return $startlite->first();
            }
        }

        throw new \RuntimeException("Profile PPPoE '{$desired}' tidak tersedia di router target. Profile live: ".$names->implode(', '));
    }

    /**
     * Read-only target validation used by dry-run and execute preflight.
     * A missing profile or unreachable/invalid host must block approval before
     * any rename/copy/ONU action can start.
     *
     * @return array{found: ?bool, desired: string, resolved?: string, warning?: string}
     */
    protected function verifyTargetMigrationProfile(Router $router, string $desired): array
    {
        $connected = false;
        try {
            $this->mikrotik->connect($router, ['timeout' => 12, 'attempts' => 1]);
            $connected = true;

            return [
                'found' => true,
                'desired' => $desired,
                'resolved' => $this->resolveLiveMigrationProfile($desired),
            ];
        } catch (\Throwable $e) {
            $missing = str_contains(strtolower($e->getMessage()), 'tidak tersedia di router target');

            return [
                'found' => $missing ? false : null,
                'desired' => $desired,
                'warning' => 'Profile target belum terverifikasi: '.SensitiveDataRedactor::redact($e->getMessage()),
            ];
        } finally {
            if ($connected) {
                $this->mikrotik->disconnect();
            }
        }
    }

    public function attachFollowUpImages(CustomerMigration $migration, array $newImageBytes, string $caption = ''): CustomerMigration
    {
        $this->attachPhotoBytes($migration, $newImageBytes, $caption);
        $migration->save();

        $fresh = $migration->fresh() ?? $migration;
        $customer = $this->resolveCustomer($fresh);
        if ($customer && $fresh->status === CustomerMigration::STATUS_SYNCED) {
            $this->syncCustomerAfterNewOnu($fresh, $customer->fresh() ?? $customer);
            $fresh->save();

            return $fresh->fresh() ?? $fresh;
        }

        if ($customer && trim((string) $fresh->new_sn) !== '' && in_array($fresh->status, [
            CustomerMigration::STATUS_RECEIVED,
            CustomerMigration::STATUS_WAITING_SN,
            CustomerMigration::STATUS_FAILED,
        ], true)) {
            return $this->processMigration($fresh);
        }

        return $fresh;
    }

    protected function syncTr069Tag(CustomerMigration $migration, Customer $customer): void
    {
        $sn = MigrationNormalizer::normalizeSerial((string) $migration->new_sn);
        if ($sn === '') {
            return;
        }

        $tag = $this->genieAcs->resolvePreferredTag((string) $customer->pppoe_user, $customer);
        try {
            // Keep the live migration bounded: one immediate attempt, then use
            // the existing retry job when ACS has not informed the new ONU yet.
            $result = $this->genieAcs->ensureTagAfterProvision($sn, $tag);
        } catch (\Throwable $e) {
            $result = [
                'success' => false,
                'pending_manual_retry' => true,
                'error' => SensitiveDataRedactor::redact($e->getMessage()),
            ];
        }
        $meta = MigrationNormalizer::meta($migration);
        $meta['genieacs_tag'] = $result;
        $migration->meta = $meta;
        $migration->save();
    }

    protected function deleteOldOnuIfSafe(CustomerMigration $migration): void
    {
        $oldRef = MigrationNormalizer::normalizeOnuRef((string) $migration->old_onu_ref);
        $newRef = MigrationNormalizer::normalizeOnuRef((string) $migration->new_onu_ref);
        $oldOltId = (int) ($migration->old_olt_id ?? 0);
        if (! $oldRef || $oldOltId <= 0 || ($newRef && $oldRef === $newRef && (int) $migration->old_olt_id === (int) $migration->new_olt_id)) {
            $meta = MigrationNormalizer::meta($migration);
            $meta['old_onu_delete'] = 'skipped_no_old_ref';
            $migration->meta = $meta;
            $migration->save();

            return;
        }

        $oldSn = MigrationNormalizer::normalizeSerial((string) $migration->old_sn);
        if ($oldSn === '' || ! $this->oldOnuExactMatch($oldOltId, $oldRef, $oldSn)) {
            $meta = MigrationNormalizer::meta($migration);
            $meta['old_onu_delete'] = [
                'ok' => false,
                'ref' => $oldRef,
                'old_sn' => $oldSn ?: null,
                'reason' => $oldSn === '' ? 'skipped_unknown_old_onu' : 'skipped_old_onu_exact_match_missing',
                'at' => now()->toIso8601String(),
            ];
            $migration->meta = $meta;
            $migration->save();

            return;
        }

        $oldOlt = Olt::query()->find($oldOltId);
        if (! $oldOlt) {
            return;
        }

        try {
            $result = $this->zte->deleteOnu($oldOlt, $oldRef);
        } catch (\Throwable $e) {
            $error = MigrationNormalizer::boundedError($e);
            $meta = MigrationNormalizer::meta($migration);
            $meta['old_onu_delete'] = [
                'ok' => false,
                'ref' => $oldRef,
                'error' => $error,
                'at' => now()->toIso8601String(),
            ];
            $migration->meta = $meta;
            $migration->last_error = 'ONU baru OK, tapi hapus ONU lama gagal: '.$error;
            $migration->save();

            return;
        }

        $purged = 0;
        $localReconcileError = null;
        try {
            $purged = $this->purgeOldOnuLocal($oldOlt, $oldRef);
        } catch (\Throwable $e) {
            $localReconcileError = MigrationNormalizer::boundedError($e);
            Log::warning('[MIGRASI] old ONU deleted; local snapshot reconcile pending', [
                'migration_id' => $migration->id,
                'olt_id' => $oldOltId,
                'onu_ref' => $oldRef,
                'error' => $localReconcileError,
            ]);
        }

        $meta = MigrationNormalizer::meta($migration);
        $meta['old_onu_delete'] = [
            'ok' => (bool) ($result['success'] ?? true),
            'ref' => $oldRef,
            'olt_id' => $oldOltId,
            'verified_absent' => (bool) ($result['verified_absent'] ?? false),
            'purged_rows' => $purged,
            'local_reconcile' => $localReconcileError ? 'pending' : 'ok',
            'local_reconcile_error' => $localReconcileError,
            'at' => now()->toIso8601String(),
        ];
        $migration->meta = $meta;
        $migration->old_onu_deleted_at = now();
        $migration->save();
    }

    protected function oldOnuExactMatch(int $oltId, string $onuRef, string $oldSn): bool
    {
        if ($oltId <= 0 || $onuRef === '' || $oldSn === '' || ! Schema::hasTable('olt_onus')) {
            return false;
        }

        return OltOnu::query()
            ->where('olt_id', $oltId)
            ->whereRaw('LOWER(onu_ref) = ?', [strtolower($onuRef)])
            ->whereRaw('UPPER(REPLACE(REPLACE(COALESCE(serial_number, ""), "-", ""), " ", "")) = ?', [$oldSn])
            ->exists();
    }

    protected function purgeOldOnuLocal(Olt $olt, string $oldRef): int
    {
        $ref = MigrationNormalizer::normalizeOnuRef($oldRef);
        if (! $ref) {
            return 0;
        }

        if (! Schema::hasTable('olt_onus')) {
            return 0;
        }

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                return OltOnu::query()
                    ->where('olt_id', $olt->id)
                    ->whereRaw('LOWER(onu_ref) = ?', [$ref])
                    ->delete();
            } catch (\Throwable $e) {
                if (! $this->isDbLockTimeout($e) || $attempt === 2) {
                    throw $e;
                }
                usleep(150000);
            }
        }

        return 0;
    }

    protected function isDbLockTimeout(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'lock wait timeout')
            || str_contains($message, 'deadlock found');
    }

    /**
     * @param  list<string>  $imagePaths
     */
    protected function attachStoredInboundImages(CustomerMigration $migration, array $imagePaths, string $hint = ''): void
    {
        $bytesList = [];
        foreach ($imagePaths as $idx => $path) {
            $path = trim((string) $path);
            if ($path === '' || ! Storage::disk(config('filesystems.default'))->exists($path)) {
                continue;
            }

            try {
                $bytes = Storage::disk(config('filesystems.default'))->get($path);
                if (! is_string($bytes) || strlen($bytes) < 100) {
                    continue;
                }
                $bytesList[] = $bytes;
            } catch (\Throwable $e) {
                Log::warning('[MIGRASI] store image failed', ['path' => $path, 'error' => $e->getMessage()]);
            }
        }

        $this->attachPhotoBytes($migration, $bytesList, $hint);
    }

    /**
     * @param  list<string>  $bytesList
     */
    protected function attachPhotoBytes(CustomerMigration $migration, array $bytesList, string $hint = ''): void
    {
        $meta = MigrationNormalizer::meta($migration);
        $meta['photo_class'] ??= [];

        // One time budget for the whole message: one image budget per photo, at
        // most PHOTO_OCR_BATCH_MAX_SECONDS. The cap sits well under the migration
        // job timeout (ProcessCustomerMigrationWaJob: 420 s) because other work
        // runs in the same job; PSB uses a larger cap for its own 600 s job.
        $validCount = count(array_filter($bytesList, static fn ($b): bool => is_string($b) && strlen($b) >= 100));
        $imageSeconds = max(15, (int) config('services.ocrspace.media_image_budget_seconds', 90));
        $batchBudget = $this->photoBatchBudget((float) min(self::PHOTO_OCR_BATCH_MAX_SECONDS, max(1, $validCount) * $imageSeconds));

        foreach ($bytesList as $bytes) {
            if (! is_string($bytes) || strlen($bytes) < 100) {
                continue;
            }

            $skipped = $batchBudget->expired();
            if ($skipped) {
                // Budget spent: treat the rest like a photo that timed out
                // (unknown type, no OCR fields); the normal flow handles it.
                $class = [
                    'type' => 'unknown',
                    'sn' => null,
                    'ktp_ocr' => [],
                    'ktp_score' => 0,
                    'sn_score' => 0,
                    'classification_status' => 'terminal',
                    'ocr_degraded' => true,
                    'ocr_ms' => 0,
                ];
                Log::info('[MIGRASI] photo OCR skipped, batch budget spent', ['migration' => $migration->id]);
            } else {
                $class = $this->psb->classifyWaActivationPhoto($bytes, $batchBudget);
            }
            $label = $this->photoLabelFromHint($hint, (string) ($class['type'] ?? 'unknown'));
            if ($label === 'unknown') {
                $meta['photo_class'][] = [
                    'path' => null,
                    'label' => 'unknown',
                    'detected_type' => $class['type'] ?? 'unknown',
                    'ktp_score' => $class['ktp_score'] ?? null,
                    'sn_score' => $class['sn_score'] ?? null,
                    'sn' => $class['sn'] ?? null,
                    'hint' => $hint,
                    'status' => 'type_mismatch',
                    'sha256' => hash('sha256', $bytes),
                    'at' => now()->toIso8601String(),
                ];

                continue;
            }
            if ($skipped && ($label === 'ktp' ? $migration->ktp_photo_path : $migration->router_photo_path)) {
                // Never read, so it must not replace a photo that is already saved
                // (its data came from the earlier photo).
                $meta['photo_class'][] = [
                    'path' => null,
                    'label' => $label,
                    'detected_type' => 'unknown',
                    'hint' => $hint,
                    'status' => 'budget_skipped',
                    'sha256' => hash('sha256', $bytes),
                    'at' => now()->toIso8601String(),
                ];

                continue;
            }
            $prefix = $label === 'ktp' ? 'ktp' : 'sn';
            $path = 'migrasi/'.now()->format('Y/m').'/'.$prefix.'_'.Str::random(8).'.jpg';
            Storage::disk(config('filesystems.default'))->put($path, $bytes);

            if ($label === 'ktp') {
                $migration->ktp_photo_path = $path;
                $ocr = is_array($class['ktp_ocr'] ?? null) ? $class['ktp_ocr'] : [];
                if (! empty($ocr['name']) && ! $migration->full_name) {
                    $migration->full_name = (string) $ocr['name'];
                }
                if (! empty($ocr['rt']) && ! $migration->rt) {
                    $migration->rt = preg_replace('/\D/', '', (string) $ocr['rt']) ?: $migration->rt;
                }
                if (! empty($ocr['rw']) && ! $migration->rw) {
                    $migration->rw = preg_replace('/\D/', '', (string) $ocr['rw']) ?: $migration->rw;
                }
                if (! empty($ocr['nik'])) {
                    $migration->nik = preg_replace('/\D/', '', (string) $ocr['nik']) ?: $migration->nik;
                }
            } else {
                $migration->router_photo_path = $path;
                $sn = MigrationNormalizer::normalizeSerial((string) ($class['sn'] ?? ''));
                if ($sn !== '' && ! $migration->new_sn) {
                    $migration->new_sn = $sn;
                }
            }

            $meta['photo_class'][] = [
                'path' => $path,
                'label' => $label,
                'detected_type' => $class['type'] ?? 'unknown',
                'ktp_score' => $class['ktp_score'] ?? null,
                'sn_score' => $class['sn_score'] ?? null,
                'sn' => $class['sn'] ?? null,
                'hint' => $hint,
                'at' => now()->toIso8601String(),
            ];
        }

        $migration->meta = $meta;
    }

    /** Seam so tests can inject a fake clock. */
    protected function photoBatchBudget(float $seconds): OcrBudget
    {
        return OcrBudget::start($seconds);
    }

    protected function photoLabelFromHint(string $hint, string $detected): string
    {
        $h = strtolower($hint);
        $expected = null;
        if (preg_match('/\b(ktp|nik|identitas)\b/i', $h)) {
            $expected = 'ktp';
        } elseif (preg_match('/\b(sn|serial|stiker|router|modem|onu|ont|fiberhome|fhtt)\b/i', $h)) {
            $expected = 'sn';
        }

        if ((bool) config('services.ocrspace.media_hard_guard', false)) {
            if ($expected !== null) {
                return $detected === $expected ? $expected : 'unknown';
            }

            return in_array($detected, ['ktp', 'sn'], true) ? $detected : 'unknown';
        }

        if ($expected !== null) {
            return $expected;
        }

        // No caption hint: only trust a positive detection. An ambiguous photo
        // must not default to 'sn' (it would land in the ONT/SN field).
        return in_array($detected, ['ktp', 'sn'], true) ? $detected : 'unknown';
    }

    protected function fail(CustomerMigration $migration, string $message): CustomerMigration
    {
        $message = MigrationNormalizer::boundedError($message);
        $migration->status = CustomerMigration::STATUS_FAILED;
        $migration->last_error = $message;
        $migration->save();
        $this->reply((string) $migration->group_jid, "Migrasi #{$migration->id} gagal:\n{$message}");

        return $migration;
    }

    protected function helpMessage(): string
    {
        return $this->texts()->helpMessage();
    }

    protected function candidateSelectionPreviewMessage(?CustomerMigration $migration, int $candidateNumber): string
    {
        return $this->texts()->candidateSelectionPreviewMessage($migration, $candidateNumber);
    }

    /**
     * Read the target PPPoE password only for the allowlisted activation group
     * message. It is deliberately not persisted in migration metadata or logs.
     */
    protected function readVerifiedMigrationPassword(CustomerMigration $migration): ?string
    {
        if (! $this->psb->isAllowedGroup((string) $migration->group_jid)) {
            return null;
        }

        $username = MigrationNormalizer::normalizeUsername((string) $migration->pppoe_user);
        $router = $migration->router_id
            ? Router::query()->whereKey((int) $migration->router_id)->where('is_active', true)->first()
            : null;
        if ($username === '' || ! $router) {
            return null;
        }

        try {
            $this->mikrotik->connect($router, ['timeout' => 12, 'attempts' => 1]);
            $secret = $this->mikrotik->getPPPSecret($username);
            $routerCredential = trim((string) ($secret['password'] ?? ''));
            if ($routerCredential === '' || preg_match('/^\*+$/', $routerCredential) === 1) {
                return null;
            }

            return $routerCredential;
        } catch (\Throwable $e) {
            Log::warning('[MIGRASI] activation credential read failed', [
                'migration_id' => $migration->id,
                'router_id' => $router->id,
                'username' => $username,
                'error_code' => 'router_password_unreadable',
            ]);

            return null;
        } finally {
            $this->mikrotik->disconnect();
        }
    }

    protected function replyIfWaitingForNewSn(CustomerMigration $migration): void
    {
        if ($migration->status !== CustomerMigration::STATUS_WAITING_SN) {
            return;
        }
        // A partially executed migration gets the full partial summary
        // (done/pending/SN hint) instead of the generic waiting message.
        if ($this->partialExecutionState($migration) !== null) {
            return;
        }

        $meta = MigrationNormalizer::meta($migration);
        if (! empty($meta['wa_waiting_sn_reply_sent_at'])) {
            return;
        }

        $meta['wa_waiting_sn_reply_sent_at'] = now()->toIso8601String();
        $migration->meta = $meta;
        $migration->save();

        $this->reply((string) $migration->group_jid, $this->texts()->waitingNewSnMessage($migration->fresh() ?? $migration));
    }

    protected function successMessage(CustomerMigration $m, ?string $routerCredential = null): string
    {
        $oldDelete = $m->old_onu_deleted_at ? 'terhapus' : 'cek manual';
        $customer = $m->customer ?: ($m->customer_id ? Customer::query()->find($m->customer_id) : null);
        $profile = $customer?->package?->mikrotik_profile ?: $customer?->mikrotik_profile ?: '-';
        $vlan = $m->vlan ?: '-';
        $tr069 = $m->tr069_vlan ?: 69;
        $acs = (string) Setting::get('genieacs_acs_url', config('site.acs_url'));
        $acsStatus = data_get($m->meta, 'genieacs_tag.success') === true ? 'verified' : 'pending/manual retry';

        $lines = [
            "*Migrasi #{$m->id} selesai*",
            'Pelanggan: '.($customer?->name ?: $m->full_name ?: '-'),
            'PPPoE: '.($m->pppoe_user ?: '-'),
            $routerCredential !== null
                ? 'Password PPPoE: '.$routerCredential
                : 'Kredensial PPPoE: tidak terbaca dari MikroTik; jangan gunakan tebakan/database.',
            'Profile/Paket: '.$profile,
            'VLAN internet: '.$vlan,
            'TR-069: VLAN '.$tr069,
            'ACS URL: '.$acs,
            'OLT/ONU baru: '.($m->new_onu_ref ?: '-').' / '.($m->new_sn ?: '-'),
            'ONU lama: '.($m->old_onu_ref ?: '-').' / '.($m->old_sn ?: '-').' ('.$oldDelete.')',
            'Status: PPPoE verified - OLT verified - ACS '.$acsStatus,
        ];
        if (data_get($m->meta, 'olt_existing_onu.name_mismatch') === true) {
            $lines[] = "Catatan: nama ONU di OLT '".data_get($m->meta, 'olt_existing_onu.onu_name_on_olt')."' beda dengan PPPoE '"
                .data_get($m->meta, 'olt_existing_onu.expected_onu_name')."'. Inventory eBilling & tag ACS sudah memakai PPPoE; rename ONU di OLT lewat menu OLT.";
        }

        return implode("\n", $lines);
    }

    /**
     * The activation group is the only outbound exception allowed to contain
     * the live PPPoE password. The value is read immediately before sending,
     * never persisted, queued, logged, or included in status/error output.
     *
     * @param  array<string, mixed>  $tag
     */
    protected function huaweiOltOnlySuccessMessage(
        CustomerMigration $migration,
        array $tag,
        ?string $routerCredential,
    ): string {
        return $this->texts()->huaweiOltOnlySuccessMessage($migration, $tag, $routerCredential);
    }

    protected function migrationSecretComment(CustomerMigration $migration): string
    {
        return $this->plans()->migrationSecretComment($migration);
    }

    protected function writesEnabled(): bool
    {
        $v = Setting::get('psb_writes_enabled', '1');
        if (is_bool($v)) {
            return $v;
        }
        if (is_array($v)) {
            $v = $v[0] ?? '1';
        }

        return ! in_array(strtolower(trim((string) $v)), ['0', 'false', 'off', 'no'], true);
    }

    /**
     * Evidence that device writes already happened while the migration is
     * not synced. Null for plain drafts (nothing written yet).
     *
     * @return array<string, mixed>|null
     */
    public function partialExecutionState(CustomerMigration $migration): ?array
    {
        return $this->partials()->partialExecutionState($migration);
    }

    /**
     * Read-only uncfg listing (no write). Cached per OLT for this request so
     * typo hints never trigger a second SSH session.
     *
     * @return array{ok: bool, onus: list<array<string, mixed>>, error?: string}
     */
    protected function readUncfgOnus(Olt $olt): array
    {
        if (array_key_exists($olt->id, $this->uncfgCache)) {
            return ['ok' => true, 'onus' => $this->uncfgCache[$olt->id]];
        }
        try {
            $res = app(OltC300Controller::class)->listUncfg(Request::create('/api/olt-c300/list-uncfg', 'POST', ['olt_id' => $olt->id]));
            $data = $res->getData(true);
        } catch (\Throwable $e) {
            return ['ok' => false, 'onus' => [], 'error' => MigrationNormalizer::boundedError($e)];
        }
        if (! empty($data['error'])) {
            return ['ok' => false, 'onus' => [], 'error' => (string) ($data['user_hint'] ?? $data['error'])];
        }
        $onus = array_values(array_filter(is_array($data['onus'] ?? null) ? $data['onus'] : [], 'is_array'));
        $this->uncfgCache[$olt->id] = $onus;

        return ['ok' => true, 'onus' => $onus];
    }

    /**
     * Reasons the SN may not be assigned to this migration (owned by another
     * live customer, another open migration, or another ONU on the OLT).
     *
     * @return list<string>
     */
    protected function snOwnershipConflicts(CustomerMigration $migration, string $sn, ?Olt $olt, ?Customer $customer): array
    {
        return $this->evidence()->snOwnershipConflicts($migration, $sn, $olt, $customer);
    }

    /**
     * Every early stop of a WA execution job replies and leaves a timeline
     * entry; a queued `#migrasi eksekusi` must never end silently.
     */
    protected function waExecutionStopped(?CustomerMigration $migration, string $group, string $code, string $message): void
    {
        if ($migration && $migration->exists) {
            $meta = MigrationNormalizer::meta($migration);
            $meta['timeline'] = MigrationNormalizer::appendTimeline($meta['timeline'] ?? [], 'wa_execution_stopped', ['reason' => $code]);
            $migration->meta = $meta;
            $migration->save();
        }
        $this->reply($group, $message);
    }

    /** @param array<string, mixed> $parsed */
    protected function isHuaweiOltOnlyParsed(array $parsed): bool
    {
        return $this->resolver()->isHuaweiOltOnlyParsed($parsed);
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    protected function shouldUseC300UncfgPath(array $parsed): bool
    {
        return $this->resolver()->shouldUseC300UncfgPath($parsed);
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @return array<string, mixed>|null
     */
    protected function resolveMigrationTargetSite(array $parsed): ?array
    {
        return $this->resolver()->resolveMigrationTargetSite($parsed);
    }
}
