<?php

namespace App\Services\Migration;

use App\Models\CustomerMigration;
use App\Models\DeviceActionLog;
use App\Services\CustomerMigrationService;
use App\Support\CacheLockWait;
use App\Support\MigrationLockMessages;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Guided candidate list refresh (review guard, web Retry, SN-fix refresh), moved out of
 * CustomerMigrationService without a change of behaviour.
 *
 * Holds the review lock `customer-migration-review-{id}` (60 s, wait 5 s) for the locked
 * refresh, exactly as before. The facade is reached for `reply` only; every other facade
 * dependency comes through the seams at call time.
 */
final class MigrationGuidedPreviewRefresh
{
    public function __construct(
        private CustomerMigrationService $host,
        private MigrationSeams $seams,
        private CustomerMigrationWaMessages $texts,
        private MigrationDeviceLedger $ledger,
        private MigrationPartialExecution $partials,
    ) {}

    /**
     * Review entry point for `guided_lookup_wa` drafts. It never runs the Excel
     * review (a guided draft has no Excel evidence); it refreshes the guided
     * candidates and tells the group the next step. Caller holds the review lock.
     */
    public function skipReviewForGuidedDraft(CustomerMigration $migration, bool $manualContinue): CustomerMigration
    {
        $groupJid = (string) $migration->group_jid;
        $id = (int) $migration->id;

        if (MigrationNormalizer::isHuaweiOltOnlyMigration($migration)) {
            $meta = MigrationNormalizer::meta($migration);
            $meta['timeline'] = MigrationNormalizer::appendTimeline($meta['timeline'] ?? [], 'review_skipped_huawei_olt_only', [
                'manual_continue' => $manualContinue,
            ]);
            $migration->meta = $meta;
            $migration->save();
            $this->host->reply($groupJid, "Migrasi #{$id} mode Huawei. Lanjut: #migrasi konfirmasi #{$id}, atau kirim ulang format Huawei.");

            return $migration->fresh() ?? $migration;
        }

        $result = $this->refreshGuidedPreview($migration, 'review_guard');
        if ($result['skipped']) {
            // Nothing was refreshed; still answer instead of staying silent.
            if ((int) data_get($migration->meta, 'guided_wa_selection.customer_id', 0) > 0) {
                $this->host->reply($groupJid, "Migrasi #{$id} sudah ada pilihan pelanggan. Lanjut: #migrasi konfirmasi #{$id}");
            } else {
                $stored = array_values(array_filter((array) data_get($migration->meta, 'guided_wa_preview.candidates', []), 'is_array'));
                $this->host->reply($groupJid, $this->texts->guidedPickFirstMessage($id, $stored));
            }

            return $migration->fresh() ?? $migration;
        }

        $meta = MigrationNormalizer::meta($migration);
        $meta['timeline'] = MigrationNormalizer::appendTimeline($meta['timeline'] ?? [], 'review_skipped_guided_wa', [
            'manual_continue' => $manualContinue,
        ]);
        $migration->meta = $meta;
        $migration->save();

        if ($result['selection'] === 'dropped') {
            $text = $this->texts->guidedSelectionDroppedMessage($id, (string) $result['conflict_text']);
        } elseif ($result['selection'] === 'kept') {
            $text = "Migrasi #{$id} sudah ada pilihan pelanggan. Lanjut: #migrasi konfirmasi #{$id}";
        } elseif ($result['order_changed']) {
            $text = $this->texts->guidedListMessage($id, (array) $result['candidates']);
        } else {
            $text = $this->texts->guidedPickFirstMessage($id, (array) $result['candidates']);
        }
        $this->host->reply($groupJid, $text);

        return $migration->fresh() ?? $migration;
    }

    /**
     * Web Retry for a WA guided draft: refresh the candidate list only (no Excel
     * review). The group gets a WA message only when the candidate order changed.
     *
     * @return array{handled: bool, ok: bool, message: string}
     */
    public function retryGuidedFromWeb(CustomerMigration $migration): array
    {
        $migration = $migration->fresh() ?? $migration;
        $none = ['handled' => false, 'ok' => true, 'message' => ''];
        if ((string) $migration->source !== 'guided_lookup_wa'
            || in_array($migration->status, [CustomerMigration::STATUS_SYNCED, CustomerMigration::STATUS_CANCELLED], true)
            || $this->partials->partialExecutionState($migration) !== null) {
            return $none;
        }

        $id = (int) $migration->id;
        if (MigrationNormalizer::isHuaweiOltOnlyMigration($migration)) {
            return [
                'handled' => true,
                'ok' => true,
                'message' => "Migrasi #{$id} mode Huawei dari WA: Retry web tidak dipakai. Lanjut di WA: #migrasi konfirmasi #{$id}.",
            ];
        }

        $locked = $this->refreshGuidedPreviewLocked($migration, 'web_retry');
        if ($locked === null) {
            return ['handled' => true, 'ok' => false, 'message' => MigrationLockMessages::reviewBusy($id)];
        }
        $result = $locked['result'];
        if ($result['skipped']) {
            return $none;
        }

        $group = (string) $locked['migration']->group_jid;
        if ($result['selection'] === 'dropped') {
            $this->host->reply($group, $this->texts->guidedSelectionDroppedMessage($id, (string) $result['conflict_text']));
        }
        if ($result['order_changed']) {
            $this->host->reply($group, $this->texts->guidedListMessage($id, (array) $result['candidates']));
        }

        if ($result['selection'] === 'kept') {
            return [
                'handled' => true,
                'ok' => true,
                'message' => "Migrasi #{$id} dari WA: pilihan pelanggan masih berlaku. Lanjut di WA: #migrasi konfirmasi #{$id}.",
            ];
        }

        return [
            'handled' => true,
            'ok' => true,
            'message' => "Migrasi #{$id} dari WA: daftar pelanggan diperbarui. Teknisi perlu kirim #migrasi pilih #{$id} <nomor>.",
        ];
    }

    /**
     * Same as refreshGuidedPreview() but under the review lock, and saved.
     * Null when the lock is busy.
     *
     * @return array{migration: CustomerMigration, result: array<string, mixed>}|null
     */
    public function refreshGuidedPreviewLocked(CustomerMigration $migration, string $origin): ?array
    {
        $lock = Cache::lock('customer-migration-review-'.$migration->id, 60);
        if (! CacheLockWait::acquire($lock, 5)) {
            return null;
        }

        try {
            $fresh = $migration->fresh() ?? $migration;
            $result = $this->refreshGuidedPreview($fresh, $origin);
            if (! $result['skipped']) {
                $fresh->save();
            }

            return ['migration' => $fresh->fresh() ?? $fresh, 'result' => $result];
        } finally {
            try {
                $lock->release();
            } catch (\Throwable) {
            }
        }
    }

    /**
     * Recompute the guided candidate list for the draft's CURRENT SN and fix the
     * selection state to match. Pure DB read plus meta changes on the model; the
     * caller saves. Never touches a device.
     *
     * @return array{skipped: bool, reason: string, candidates: list<array<string, mixed>>, order_changed: bool, selection: string, conflict_text: string}
     */
    public function refreshGuidedPreview(CustomerMigration $migration, string $origin): array
    {
        $skip = static fn (string $reason): array => [
            'skipped' => true,
            'reason' => $reason,
            'candidates' => [],
            'order_changed' => false,
            'selection' => 'none',
            'conflict_text' => '',
        ];
        if ((string) $migration->source !== 'guided_lookup_wa') {
            return $skip('not_guided');
        }
        if (in_array($migration->status, [CustomerMigration::STATUS_SYNCED, CustomerMigration::STATUS_CANCELLED], true)) {
            return $skip('closed');
        }
        if (MigrationNormalizer::isHuaweiOltOnlyMigration($migration)) {
            return $skip('huawei_olt_only');
        }
        if ($this->partials->partialExecutionState($migration) !== null || $this->ledger->hasExecutedDeviceLedger($migration)) {
            return $skip('executed');
        }

        $meta = MigrationNormalizer::meta($migration);
        $newSn = MigrationNormalizer::normalizeSerial((string) $migration->new_sn);
        $selectedId = (int) data_get($meta, 'guided_wa_selection.customer_id', 0);
        $preview = ($this->seams->guidedLookup)()->preview($this->guidedRefreshInput($migration, $meta, $newSn));
        if ($selectedId > 0 && ($preview['status'] ?? null) === 'input_incomplete') {
            // The lookup cannot judge this input; keep the selection as it is.
            return $skip('input_incomplete');
        }

        $candidates = array_slice(array_values(array_filter((array) ($preview['candidates'] ?? []), 'is_array')), 0, 5);
        $oldCandidates = array_values(array_filter((array) data_get($meta, 'guided_wa_preview.candidates', []), 'is_array'));
        $idsOf = static fn (array $rows): array => array_map(static fn (array $row): int => (int) ($row['customer_id'] ?? 0), $rows);
        $previousOrder = $idsOf($oldCandidates);
        $orderChanged = $previousOrder !== $idsOf($candidates);
        $conflictsOf = static fn (array $rows): array => array_map(
            static fn (array $row): array => array_values(array_filter((array) ($row['conflicts'] ?? []))),
            array_slice($rows, 0, 3)
        );

        $meta['guided_wa_preview'] = array_merge((array) ($meta['guided_wa_preview'] ?? []), [
            'status' => $preview['status'] ?? 'needs_review',
            'reasons' => $preview['reasons'] ?? [],
            'candidates' => $candidates,
            'idempotency_key' => $preview['idempotency_key'] ?? null,
            'updated_at' => now()->toIso8601String(),
            'new_sn' => $newSn,
            'refreshed_from' => $origin,
            'previous_order' => $previousOrder,
        ]);

        $selection = 'none';
        $conflictText = '';
        if ($selectedId <= 0) {
            unset($meta['migration_review']);
            if (data_get($meta, 'guided_wa_confirmation.status') === 'confirmed') {
                $meta['guided_wa_confirmation'] = array_merge((array) $meta['guided_wa_confirmation'], [
                    'status' => 'cancelled',
                    'reason' => 'no_candidate_selected',
                    'cancelled_at' => now()->toIso8601String(),
                ]);
            }
            $migration->last_error = "Menunggu teknisi pilih pelanggan: #migrasi pilih #{$migration->id} <nomor>";
        } else {
            $fresh = collect($candidates)->first(fn (array $row): bool => (int) ($row['customer_id'] ?? 0) === $selectedId);
            $blocking = is_array($fresh) ? $this->guidedCandidateBlockingConflicts($migration, $fresh) : [];
            $valid = is_array($fresh) && $blocking === [] && (int) $migration->customer_id === $selectedId;
            if ($valid) {
                $selection = 'kept';
                $meta['guided_wa_selection']['candidate_number'] = (int) ($fresh['number'] ?? 0);
                if (data_get($meta, 'migration_review.match_status') !== 'confirmed_pending_approval') {
                    $meta['migration_review'] = [
                        'match_status' => 'confirmed_pending_approval',
                        'reasons' => ['guided_wa_selection_revalidated'],
                        'source' => 'guided_lookup_wa',
                    ];
                }
                if (data_get($meta, 'device_action_approval.status') !== 'dry_run_ready') {
                    $migration->last_error = 'Pelanggan sudah dipilih; menunggu konfirmasi teknisi.';
                }
            } else {
                $selection = 'dropped';
                $conflictText = $blocking !== [] ? $this->texts->guidedConflictsText($blocking) : 'data belum cocok';
                unset($meta['guided_wa_selection']);
                $meta['guided_wa_confirmation'] = array_merge((array) ($meta['guided_wa_confirmation'] ?? []), [
                    'status' => 'cancelled',
                    'reason' => 'sn_conflict_after_change',
                    'cancelled_at' => now()->toIso8601String(),
                ]);
                $meta['migration_review'] = array_merge((array) ($meta['migration_review'] ?? []), [
                    'match_status' => 'needs_review',
                    'reasons' => ['guided_selection_invalid_after_sn_change'],
                    'source' => 'guided_lookup_wa',
                ]);
                $ledgerId = (int) data_get($meta, 'device_action_approval.ledger_id', 0);
                if ($ledgerId > 0) {
                    $ledger = DeviceActionLog::query()->find($ledgerId);
                    if ($ledger && in_array($ledger->status, ['requested', 'dry_run', 'planned'], true) && ! $ledger->executed_at) {
                        $ledger->status = 'cancelled';
                        $ledger->result = array_merge((array) $ledger->result, [
                            'cancelled_reason' => 'sn_conflict_after_change',
                            'device_write' => false,
                            'customer_write' => false,
                            'cancelled_at' => now()->toIso8601String(),
                        ]);
                        $ledger->save();
                    }
                }
                unset($meta['device_action_approval'], $meta['device_action_preflight'], $meta['wa_live_execution_queued'], $meta['wa_live_execution']);
                $migration->last_error = "Menunggu teknisi pilih pelanggan: #migrasi pilih #{$migration->id} <nomor>";
            }
        }

        $meta['timeline'] = MigrationNormalizer::appendTimeline($meta['timeline'] ?? [], 'guided_wa_preview_refreshed', [
            'origin' => $origin,
            'from_conflicts' => $conflictsOf($oldCandidates),
            'to_conflicts' => $conflictsOf($candidates),
            'order_changed' => $orderChanged,
            'selection' => $selection,
        ]);
        $migration->meta = $meta;

        return [
            'skipped' => false,
            'reason' => '',
            'candidates' => $candidates,
            'order_changed' => $orderChanged,
            'selection' => $selection,
            'conflict_text' => $conflictText,
        ];
    }

    /**
     * Preview input for a refresh: re-parse the stored raw text (so a stale
     * pilih never leaks the customer's own data into the search), with the
     * draft's current SN. Row fields only when there is no raw text.
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public function guidedRefreshInput(CustomerMigration $migration, array $meta, string $newSn): array
    {
        if (trim((string) $migration->raw_text) !== '') {
            $input = $this->guidedPreviewInput(($this->seams->parser)()->parse((string) $migration->raw_text));
        } else {
            $input = $this->guidedPreviewInput([
                'full_name' => $migration->full_name,
                'pppoe_user' => data_get($meta, 'input_identity.pppoe_user'),
                'phone' => $migration->phone,
                'rt' => $migration->rt,
                'rw' => $migration->rw,
                'odp' => $migration->odp,
                'daerah' => $migration->daerah,
            ]);
            $input['old_sn'] = '';
        }
        $input['new_sn'] = $newSn;

        return $input;
    }

    /**
     * The stored candidate list was made for another SN than the draft has now.
     */
    public function guidedPreviewIsStale(CustomerMigration $migration): bool
    {
        $meta = MigrationNormalizer::meta($migration);
        $storedSn = MigrationNormalizer::normalizeSerial((string) data_get($meta, 'guided_wa_preview.new_sn', ''));
        if ($storedSn !== '') {
            return $storedSn !== MigrationNormalizer::normalizeSerial((string) $migration->new_sn);
        }

        // Legacy drafts (no stored SN): stale only when an SN correction is
        // newer than the stored list. No corrections = never stale.
        $corrections = (array) ($meta['sn_corrections'] ?? []);
        if ($corrections === []) {
            return false;
        }
        $lastAt = (string) data_get($corrections[array_key_last($corrections)], 'at', '');
        if ($lastAt === '') {
            return false;
        }
        $updatedAt = (string) data_get($meta, 'guided_wa_preview.updated_at', '');
        try {
            return $updatedAt === '' || Carbon::parse($lastAt)->greaterThan(Carbon::parse($updatedAt));
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param list<string> $codes */
    public function guidedHasSnConflict(array $codes): bool
    {
        return array_intersect($codes, [
            'new_sn_already_linked_to_other_customer',
            'new_sn_exists_in_other_psb_history',
            'excel_new_sn_conflict',
        ]) !== [];
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @return array<string, mixed>
     */
    public function guidedPreviewInput(array $parsed): array
    {
        return [
            'name' => (string) ($parsed['full_name'] ?? ''),
            'new_sn' => (string) ($parsed['new_sn'] ?? ''),
            'old_sn' => (string) ($parsed['old_sn'] ?? ''),
            'pppoe_user' => (string) ($parsed['pppoe_user'] ?? ''),
            'phone' => (string) ($parsed['phone'] ?? ''),
            'area' => (string) ($parsed['daerah'] ?? ''),
            'odp' => (string) ($parsed['odp'] ?? ''),
            'rt' => (string) ($parsed['rt'] ?? ''),
            'rw' => (string) ($parsed['rw'] ?? ''),
            'customer_code' => '',
            'onu_name' => (string) ($parsed['onu_name'] ?? ''),
            'olt' => (string) ($parsed['olt_field'] ?? $parsed['router_field'] ?? ''),
        ];
    }

    /** @param array<string, mixed> $candidate @return list<string> */
    public function guidedCandidateBlockingConflicts(CustomerMigration $migration, array $candidate): array
    {
        $conflicts = array_values(array_filter((array) ($candidate['conflicts'] ?? [])));
        $safeOldSnMismatch = $conflicts === ['old_sn_mismatch']
            && ($candidate['confidence'] ?? '') === 'high'
            && (int) ($candidate['score'] ?? 0) >= 100
            && (int) ($candidate['customer_id'] ?? 0) > 0
            && MigrationNormalizer::normalizeUsername((string) ($candidate['pppoe_user'] ?? '')) === MigrationNormalizer::normalizeUsername((string) $migration->pppoe_user)
            && MigrationNormalizer::normalizeSerial((string) ($candidate['old_sn'] ?? '')) !== '';

        return $safeOldSnMismatch ? [] : $conflicts;
    }
}
