<?php

namespace App\Services\Migration;

use App\Exceptions\LockBusyException;
use App\Models\Customer;
use App\Models\CustomerMigration;
use App\Models\DeviceActionLog;
use App\Services\CustomerMigrationService;
use App\Services\MigrationOperationClassifier;
use App\Support\MigrationLockMessages;
use Illuminate\Support\Carbon;

/**
 * Guided WA flow (intake, pilih, konfirmasi, batal, hp, ktp, replacement confirm, re-send
 * lookup), moved out of CustomerMigrationService without a change of behaviour.
 *
 * Takes no lock itself: the review lock and the device lock live in MigrationGuidedPreviewRefresh
 * and MigrationSnCorrection. The facade is reached for `reply` and `prepareDeviceActionApproval`
 * only; every other facade dependency comes through the seams at call time.
 */
final class MigrationGuidedWaFlow
{
    public function __construct(
        private CustomerMigrationService $host,
        private MigrationSeams $seams,
        private MigrationWaSenders $senders,
        private MigrationGuidedPreviewRefresh $refresh,
        private MigrationSnCorrection $snCorrection,
        private MigrationHuaweiOltOnlyIntake $huawei,
        private MigrationPartialExecution $partials,
        private MigrationDeviceLedger $ledger,
        private MigrationDeviceActionPlanner $planner,
        private MigrationTargetResolver $resolver,
        private CustomerMigrationWaMessages $texts,
    ) {}

    /** @param array<string, mixed> $payload @param array<string, mixed> $parsed */
    public function attachMigrationKtpFromWa(array $payload, array $parsed): CustomerMigration
    {
        $group = (string) ($payload['group_jid'] ?? '');
        $id = (int) ($parsed['migration_id'] ?? 0);
        $migration = CustomerMigration::query()
            ->whereKey($id)
            ->where('group_jid', $group)
            ->where('status', '!=', CustomerMigration::STATUS_CANCELLED)
            ->first();

        if (! $migration) {
            $this->host->reply($group, 'Draft migrasi tidak ditemukan. Kirim foto dengan caption: #migrasi ktp #ID');

            return new CustomerMigration(['status' => CustomerMigration::STATUS_FAILED]);
        }

        $paths = array_values(array_filter((array) ($payload['image_paths'] ?? [])));
        if ($paths === []) {
            $this->host->reply($group, "Foto KTP belum terbaca. Kirim ulang sebagai gambar dengan caption: #migrasi ktp #{$migration->id}");

            return $migration;
        }

        ($this->seams->attachStoredInboundImages)($migration, $paths, 'ktp');
        $migration->save();
        $migration = $migration->fresh() ?? $migration;
        if (! $migration->ktp_photo_path) {
            $this->host->reply($group, "Foto KTP migrasi #{$migration->id} gagal disimpan. Jangan kirim berulang; NOC cek storage.");

            return $migration;
        }

        $customer = ($this->seams->resolveCustomer)($migration);
        if ($customer && $migration->status === CustomerMigration::STATUS_SYNCED) {
            ($this->seams->syncCustomerAfterNewOnu)($migration, $customer->fresh() ?? $customer);
        }

        $this->host->reply($group, "Foto KTP tersimpan di migrasi #{$migration->id} dan terikat ke ID yang benar.");

        return $migration->fresh() ?? $migration;
    }

    /** @param array<string, mixed> $payload @param array<string, mixed> $parsed */
    public function updateMigrationPhoneFromWa(array $payload, array $parsed): CustomerMigration
    {
        $group = (string) ($payload['group_jid'] ?? '');
        $migration = $this->senders->findMigrationForCommand($group, $parsed);
        if (! $migration || (string) $migration->group_jid !== $group) {
            $this->host->reply($group, 'Migrasi tidak ditemukan. Pakai: #migrasi no hp #ID 085xxxxxxxx');

            return $migration ?? new CustomerMigration(['status' => CustomerMigration::STATUS_FAILED]);
        }

        $phone = preg_replace('/\D/', '', (string) ($parsed['phone'] ?? '')) ?: '';
        if (! preg_match('/^(?:0|62)\d{8,15}$/', $phone)) {
            $this->host->reply($group, "Nomor HP migrasi #{$migration->id} tidak valid. Contoh: #migrasi no hp #{$migration->id} 081234567890");

            return $migration;
        }

        $meta = MigrationNormalizer::meta($migration);
        $meta['timeline'] = MigrationNormalizer::appendTimeline($meta['timeline'] ?? [], 'phone_updated_from_wa', [
            'sender_jid' => (string) ($payload['sender_jid'] ?? ''),
            'old_phone' => $migration->phone,
            'new_phone' => $phone,
            'updated_at' => now()->toIso8601String(),
        ]);
        $migration->phone = $phone;
        $migration->meta = $meta;
        $migration->last_error = null;
        $migration->save();

        $customer = $migration->customer ?: ($migration->customer_id ? Customer::query()->find($migration->customer_id) : null);
        $customerUpdated = false;
        if ($customer && blank($customer->phone)) {
            $customer->phone = $phone;
            $customer->save();
            $customerUpdated = true;
        }

        $this->host->reply(
            $group,
            "Nomor HP migrasi #{$migration->id} tersimpan: {$phone}.\n".
            ($customerUpdated ? 'Customer lama juga diisi karena sebelumnya kosong.' : 'Customer lama tidak ditimpa bila sudah punya nomor.')."\n".
            "Cek ulang: #migrasi cek #{$migration->id}"
        );

        return $migration->fresh() ?? $migration;
    }

    public function createGuidedDraftFromWa(array $payload, array $parsed): CustomerMigration
    {
        if ($this->resolver->isHuaweiOltOnlyParsed($parsed)) {
            return $this->huawei->createHuaweiOltOnlyDraftFromWa($payload, $parsed);
        }

        // A re-sent #migrasi for the same PPPoE with a different SN updates the
        // existing open migration (validated SN correction) instead of
        // silently creating a second draft the technician never selects.
        $resend = $this->findOpenMigrationForResend($payload, $parsed);
        if ($resend && ($this->partials->partialExecutionState($resend) !== null || $this->senders->canModifyMigrationFromWa($resend, $payload))) {
            return $this->snCorrection->correctMigrationSnFromWa($resend, (string) ($parsed['new_sn'] ?? ''), $payload, 'resend');
        }

        $preview = ($this->seams->guidedLookup)()->preview($this->refresh->guidedPreviewInput($parsed));
        $candidates = array_values(array_filter((array) ($preview['candidates'] ?? []), 'is_array'));
        // Name-based re-send (no PPPoE): when the lookup resolves to exactly
        // one customer that already has an open migration with another SN,
        // correct that migration instead of creating a parallel draft.
        $uniqueCustomerId = count($candidates) === 1 ? (int) ($candidates[0]['customer_id'] ?? 0) : 0;
        $openForCustomer = $uniqueCustomerId > 0 ? $this->findOpenMigrationForResend($payload, $parsed, $uniqueCustomerId) : null;
        if ($openForCustomer && ($this->partials->partialExecutionState($openForCustomer) !== null || $this->senders->canModifyMigrationFromWa($openForCustomer, $payload))) {
            return $this->snCorrection->correctMigrationSnFromWa($openForCustomer, (string) ($parsed['new_sn'] ?? ''), $payload, 'resend');
        }
        if ($candidates === [] && ($preview['status'] ?? null) === 'input_incomplete') {
            $this->host->reply((string) ($payload['group_jid'] ?? ''), $this->texts->guidedPreviewMessage($preview));

            return new CustomerMigration(['status' => 'preview']);
        }

        $key = 'guided-wa:'.substr(hash('sha256', json_encode([$payload['group_jid'] ?? '', $preview['idempotency_key'] ?? ''], JSON_UNESCAPED_SLASHES)), 0, 24);
        $migration = CustomerMigration::query()->firstOrNew(['message_id' => $key]);
        if ((new MigrationResendGuard)->hasDeviceWrites($migration)) {
            return $this->snCorrection->resendOverPartial($migration, $parsed, $payload);
        }
        if (! $migration->exists) {
            $migration->fill([
                'status' => CustomerMigration::STATUS_NEEDS_REVIEW,
                'source' => 'guided_lookup_wa',
                'group_jid' => (string) ($payload['group_jid'] ?? ''),
                'sender_jid' => (string) ($payload['sender_jid'] ?? ''),
                'sender_name' => (string) ($payload['sender_name'] ?? ''),
                'raw_text' => (string) ($parsed['raw'] ?? ''),
                'pppoe_user' => (string) ($parsed['pppoe_user'] ?? ''),
                'full_name' => (string) ($parsed['full_name'] ?? ''),
                'phone' => (string) ($parsed['phone'] ?? ''),
                'rt' => (string) ($parsed['rt'] ?? ''),
                'rw' => (string) ($parsed['rw'] ?? ''),
                'odp' => (string) ($parsed['odp'] ?? ''),
                'daerah' => (string) ($parsed['daerah'] ?? ''),
                'new_sn' => MigrationNormalizer::normalizeSerial((string) ($parsed['new_sn'] ?? '')),
                'old_sn' => MigrationNormalizer::normalizeSerial((string) ($parsed['old_sn'] ?? '')),
            ]);
        }
        $meta = MigrationNormalizer::meta($migration);
        if (trim((string) ($parsed['pppoe_user'] ?? '')) !== '') {
            $meta['input_identity']['pppoe_user'] = trim((string) $parsed['pppoe_user']);
        }
        $targetRouterField = trim((string) ($parsed['new_router_field'] ?? $parsed['router_field'] ?? ''));
        if ($targetRouterField !== '') {
            $targetRouter = $this->resolver->resolveMigrationRouterAlias($targetRouterField);
            $meta['router_mapping'] = [
                'target_input' => $targetRouterField,
                'target_router_id' => $targetRouter?->id,
                'status' => $targetRouter ? 'resolved' : 'target_router_unresolved',
            ];
            if ($targetRouter) {
                $migration->router_id = $targetRouter->id;
            }
        }
        $meta['guided_wa_preview'] = [
            'status' => $preview['status'] ?? 'needs_review',
            'reasons' => $preview['reasons'] ?? [],
            'candidates' => array_slice($candidates, 0, 5),
            'idempotency_key' => $preview['idempotency_key'] ?? null,
            'updated_at' => now()->toIso8601String(),
            'new_sn' => MigrationNormalizer::normalizeSerial((string) ($parsed['new_sn'] ?? '')),
        ];
        $migration->meta = $meta;
        if ($candidates === []) {
            $migration->last_error = 'Kandidat customer belum ditemukan; tersimpan untuk review dan retry aman.';
        }
        $migration->save();

        $error = null;
        if (($preview['status'] ?? null) === 'ready_for_selection') {
            try {
                $migration = $this->confirmGuidedWaCandidate($migration, $candidates[0], $payload, 1);
                $migration = $this->host->prepareDeviceActionApproval($migration, $this->senders->waActor($payload), 'WA guided high-confidence auto dry-run');
                $this->host->reply(
                    (string) $migration->group_jid,
                    "Kandidat unik #{$migration->id} sudah dry-run. Tidak ada auto-live; teknisi/NOC wajib kirim #migrasi konfirmasi #{$migration->id}, lalu #migrasi eksekusi #{$migration->id}."
                );
            } catch (LockBusyException $e) {
                // Busy: the draft and its candidate selection stay saved; only the
                // dry-run was not prepared and no last_error is written.
                $error = $e->userMessage();
            } catch (\Throwable $e) {
                $error = MigrationNormalizer::boundedError($e);
                $migration->last_error = 'Dry-run otomatis tertahan: '.$error;
                $migration->save();
            }
        }

        $replyText = $this->texts->guidedPreviewMessage($preview, $migration, $error);
        $hasSnConflict = collect($candidates)->contains(
            fn ($candidate): bool => $this->refresh->guidedHasSnConflict(array_values(array_filter((array) ($candidate['conflicts'] ?? []))))
        );
        if ($hasSnConflict && $migration->id) {
            // guidedPreviewMessage is golden-locked; the SN hint is appended.
            $replyText .= "\nSN baru sudah tercatat di pelanggan lain. Cek SN di ONT. Jika salah: #migrasi sn #{$migration->id} <SN_BENAR>";
        }
        $this->host->reply((string) $migration->group_jid, $replyText);

        return $migration->fresh() ?? $migration;
    }

    /** @param array<string, mixed> $payload @param array<string, mixed> $parsed */
    public function selectGuidedCandidateFromWa(array $payload, array $parsed): CustomerMigration
    {
        $migration = $this->senders->findMigrationForCommand((string) ($payload['group_jid'] ?? ''), $parsed);
        $number = (int) ($parsed['candidate_number'] ?? 0);
        if (! $migration || $number < 1 || $number > 5 || (string) $migration->group_jid !== (string) ($payload['group_jid'] ?? '')) {
            $this->host->reply((string) ($payload['group_jid'] ?? ''), 'Pilihan migrasi tidak valid. Pakai: #migrasi pilih #ID <nomor 1-5>');

            return $migration ?? new CustomerMigration(['status' => CustomerMigration::STATUS_FAILED]);
        }
        if ($this->partials->partialExecutionState($migration) !== null) {
            $this->host->reply((string) $migration->group_jid, "Kandidat migrasi #{$migration->id} tidak dapat diubah: perangkat sudah sebagian dieksekusi untuk pelanggan ini.\nKoreksi SN: #migrasi sn #{$migration->id} <SN_BENAR>\nCek: #migrasi cek #{$migration->id}");

            return $migration;
        }
        if (in_array($migration->status, [CustomerMigration::STATUS_SYNCED, CustomerMigration::STATUS_CANCELLED], true)
            || filled(data_get($migration->meta, 'wa_live_execution_queued.queued_at'))
            || filled(data_get($migration->meta, 'wa_live_execution.requested_at'))) {
            $this->host->reply((string) $migration->group_jid, "Pilihan migrasi #{$migration->id} tidak dapat diubah karena sudah dibatalkan/masuk eksekusi/selesai.");

            return $migration;
        }
        if ($synced = $this->newerSyncedMigration($migration)) {
            $this->host->reply(
                (string) $migration->group_jid,
                "Migrasi #{$migration->id} sudah selesai melalui #{$synced->id} (synced). Jangan pilih atau eksekusi ulang."
            );

            return $migration;
        }
        if ($this->refresh->guidedPreviewIsStale($migration)) {
            // The list was made for an older SN. Show the fresh list instead of
            // selecting from numbers the technician may no longer see.
            $locked = $this->refresh->refreshGuidedPreviewLocked($migration, 'pilih_stale');
            if ($locked === null) {
                $this->host->reply((string) $migration->group_jid, MigrationLockMessages::reviewBusy((int) $migration->id));

                return $migration;
            }
            if (! $locked['result']['skipped']) {
                $migration = $locked['migration'];
                $this->host->reply((string) $migration->group_jid, $this->texts->guidedListMessage((int) $migration->id, (array) $locked['result']['candidates']));

                return $migration;
            }
            if ($locked['result']['reason'] === 'input_incomplete') {
                // The list cannot be rebuilt: never select from the old numbers.
                $this->host->reply(
                    (string) $migration->group_jid,
                    "Pelanggan #{$number} belum bisa dipilih: ".$this->texts->guidedConflictText('').".\nKirim ulang #migrasi dengan data yang benar (PPPoE/HP/SN lama)."
                );

                return $migration;
            }
        }
        $meta = MigrationNormalizer::meta($migration);
        $candidates = (array) data_get($meta, 'guided_wa_preview.candidates', []);
        $candidate = collect($candidates)->first(fn ($row) => is_array($row) && (int) ($row['number'] ?? 0) === $number);
        if (! is_array($candidate)) {
            $this->host->reply((string) $migration->group_jid, "Kandidat #{$number} tidak tersedia. Kirim ulang #migrasi untuk memperbarui kandidat.");

            return $migration;
        }
        $acknowledgedConflicts = array_values(array_filter((array) ($candidate['conflicts'] ?? [])));
        $blockingConflicts = $this->refresh->guidedCandidateBlockingConflicts($migration, $candidate);
        if ($blockingConflicts !== []) {
            $text = "Pelanggan #{$number} belum bisa dipilih: ".$this->texts->guidedConflictsText($blockingConflicts).'.';
            if ($this->refresh->guidedHasSnConflict($blockingConflicts)) {
                $text .= "\nCek SN di ONT. Jika salah: #migrasi sn #{$migration->id} <SN_BENAR>\nLalu pilih lagi: #migrasi pilih #{$migration->id} {$number}";
            } else {
                $text .= "\nKirim ulang #migrasi dengan data yang benar (PPPoE/HP/SN lama).";
            }
            $this->host->reply((string) $migration->group_jid, $text);

            return $migration;
        }
        $candidate['conflicts'] = [];
        $candidate['acknowledged_conflicts'] = $acknowledgedConflicts;

        try {
            $previousCustomerId = $migration->customer_id;
            $this->resetDryRunForCandidateChange($migration, (int) ($candidate['customer_id'] ?? 0));
            // The reset works on a fresh copy: continue on it, or the old approval is saved back.
            $migration = $migration->fresh() ?? $migration;
            $migration = $this->confirmGuidedWaCandidate($migration, $candidate, $payload, $number);
            $meta = MigrationNormalizer::meta($migration);
            $meta['guided_wa_confirmation'] = [
                'status' => 'pending',
                'candidate_number' => $number,
                'customer_id' => $migration->customer_id,
                'selected_by_sender_keys' => $this->senders->waSenderKeys($payload),
                'selected_at' => now()->toIso8601String(),
                'expires_at' => now()->addMinutes(30)->toIso8601String(),
                'previous_customer_id' => $previousCustomerId,
            ];
            $meta['timeline'] = MigrationNormalizer::appendTimeline($meta['timeline'] ?? [], 'guided_wa_confirmation_pending', [
                'candidate_number' => $number,
                'customer_id' => $migration->customer_id,
            ]);
            $migration->meta = $meta;
            $migration->last_error = 'Kandidat dipilih; menunggu konfirmasi teknisi sebelum dry-run/device action.';
            $migration->save();
            $this->host->reply((string) $migration->group_jid, $this->texts->guidedSelectionConfirmationMessage($migration, $number));
        } catch (\Throwable $e) {
            $error = MigrationNormalizer::boundedError($e);
            $migration->last_error = 'Pilihan kandidat tertahan: '.$error;
            $migration->save();
            $this->host->reply((string) $migration->group_jid, "Migrasi #{$migration->id} belum siap: ".$this->texts->shortWaError($error));
        }

        return $migration->fresh() ?? $migration;
    }

    /** @param array<string, mixed> $payload @param array<string, mixed> $parsed */
    public function confirmGuidedSelectionFromWa(array $payload, array $parsed): CustomerMigration
    {
        $group = (string) ($payload['group_jid'] ?? '');
        $migration = $this->senders->findMigrationForCommand($group, $parsed);
        if (! $migration || (string) $migration->group_jid !== $group) {
            $this->host->reply($group, 'Migrasi tidak ditemukan. Pakai: #migrasi konfirmasi #ID');

            return $migration ?? new CustomerMigration(['status' => CustomerMigration::STATUS_FAILED]);
        }
        if ($migration->status === CustomerMigration::STATUS_SYNCED) {
            $this->host->reply($group, "Migrasi #{$migration->id} sudah selesai; tidak dieksekusi ulang.");

            return $migration;
        }
        if ($migration->status === CustomerMigration::STATUS_CANCELLED) {
            $this->host->reply($group, "Migrasi #{$migration->id} sudah dibatalkan. Kirim #migrasi baru jika memang perlu.");

            return $migration;
        }
        if (! $this->senders->sameGuidedSelectionSender($migration, $payload)) {
            $this->host->reply($group, "Konfirmasi #{$migration->id} ditolak. Kandidat harus dikonfirmasi oleh teknisi yang memilihnya.");

            return $migration;
        }

        $confirmation = (array) data_get($migration->meta, 'guided_wa_confirmation', []);
        if (($confirmation['status'] ?? null) === 'pending' && filled($confirmation['expires_at'] ?? null)) {
            try {
                if (Carbon::parse((string) $confirmation['expires_at'])->isPast()) {
                    $this->host->reply($group, "Pilihan migrasi #{$migration->id} sudah kedaluwarsa. Pilih ulang: #migrasi pilih #{$migration->id} <nomor>");

                    return $migration;
                }
            } catch (\Throwable) {
                $this->host->reply($group, "Waktu konfirmasi migrasi #{$migration->id} tidak valid. Pilih ulang kandidat.");

                return $migration;
            }
        }

        if (MigrationNormalizer::isHuaweiOltOnlyMigration($migration)) {
            return $this->huawei->confirmHuaweiOltOnlyFromWa($migration, $payload);
        }

        // A guided draft needs a valid selection (match_status is set only by
        // pilih). Without one, konfirmasi must not be recorded: show the list.
        // A selection with a damaged review (e.g. by an Excel Retry) is
        // revalidated first and restored when it is still valid.
        if ((string) $migration->source === 'guided_lookup_wa'
            && (data_get($migration->meta, 'migration_review.match_status') !== 'confirmed_pending_approval'
                || $this->refresh->guidedPreviewIsStale($migration))) {
            $locked = $this->refresh->refreshGuidedPreviewLocked($migration, 'konfirmasi_without_selection');
            if ($locked === null) {
                $this->host->reply($group, MigrationLockMessages::reviewBusy((int) $migration->id));

                return $migration;
            }
            if (! $locked['result']['skipped']) {
                $migration = $locked['migration'];
                $result = $locked['result'];
                if ($result['selection'] === 'dropped') {
                    $this->host->reply($group, $this->texts->guidedSelectionDroppedMessage((int) $migration->id, (string) $result['conflict_text']));

                    return $migration;
                }
                if ($result['selection'] === 'none') {
                    $this->host->reply($group, $this->texts->guidedPickFirstMessage((int) $migration->id, (array) $result['candidates']));

                    return $migration;
                }
            } elseif ($locked['result']['reason'] === 'input_incomplete') {
                // The list cannot be rebuilt: do not fall into the device flow.
                $this->host->reply($group, $this->texts->guidedPickFirstMessage((int) $migration->id, []));

                return $migration;
            }
        }

        $customer = $migration->customer ?: ($migration->customer_id ? Customer::query()->find($migration->customer_id) : null);
        $missing = [];
        if (! $customer) {
            $missing[] = 'customer';
        }
        if ($customer && blank($migration->phone ?: $customer->phone)) {
            $missing[] = 'nomor HP';
        }
        if ($customer && blank($migration->ktp_photo_path ?: $customer->ktp_photo_url)) {
            $missing[] = 'foto KTP';
        }
        if ($missing !== []) {
            $this->host->reply(
                $group,
                "Migrasi #{$migration->id} belum dapat dikonfirmasi. Data kurang: ".implode(', ', $missing).".\n".
                "KTP: kirim gambar dengan caption #migrasi ktp #{$migration->id}; lalu ulangi #migrasi konfirmasi #{$migration->id}."
            );

            return $migration;
        }

        try {
            $operation = $this->planner->classifyAndPersistOperation($migration);
            if (in_array($operation['operation_type'], [
                MigrationOperationClassifier::HARDWARE_REPLACEMENT,
                MigrationOperationClassifier::PENDING_TECHNICIAN_CONFIRMATION,
            ], true)) {
                return $this->confirmReplacementFromWa($migration, $payload, $operation);
            }
            $meta = MigrationNormalizer::meta($migration);
            $meta['guided_wa_confirmation'] = array_merge($confirmation, [
                'status' => 'confirmed',
                'confirmed_by_sender_keys' => $this->senders->waSenderKeys($payload),
                'confirmed_at' => now()->toIso8601String(),
            ]);
            $meta['timeline'] = MigrationNormalizer::appendTimeline($meta['timeline'] ?? [], 'guided_wa_selection_confirmed', [
                'customer_id' => $migration->customer_id,
                'candidate_number' => data_get($meta, 'guided_wa_selection.candidate_number'),
            ]);
            $migration->meta = $meta;
            $migration->save();

            // A ready status alone is not enough: the ledger may be cancelled or belong to another migration.
            if (! $this->ledger->hasReusableDeviceActionDryRun($migration)) {
                $migration = $this->host->prepareDeviceActionApproval(
                    $migration,
                    $this->senders->waActor($payload),
                    'WA technician confirmed selected candidate'
                );
            }

            $this->host->reply(
                $group,
                "Konfirmasi teknisi untuk migrasi #{$migration->id} tersimpan. Tidak ada auto-live; kirim #migrasi eksekusi #{$migration->id} setelah review terakhir."
            );

            return $migration->fresh() ?? $migration;
        } catch (LockBusyException $e) {
            $this->host->reply($group, $e->userMessage());

            return $migration->fresh() ?? $migration;
        } catch (\Throwable $e) {
            $error = MigrationNormalizer::boundedError($e);
            $migration->last_error = 'Konfirmasi teknisi tertahan: '.$error;
            $migration->save();
            $this->host->reply($group, "Migrasi #{$migration->id} belum dieksekusi: ".$this->texts->shortWaError($error));

            return $migration->fresh() ?? $migration;
        }
    }

    /** @param array<string, mixed> $payload @param array<string, mixed> $parsed */
    public function cancelGuidedSelectionFromWa(array $payload, array $parsed): CustomerMigration
    {
        $group = (string) ($payload['group_jid'] ?? '');
        $migration = $this->senders->findMigrationForCommand($group, $parsed);
        if (! $migration || (string) $migration->group_jid !== $group) {
            $this->host->reply($group, 'Migrasi tidak ditemukan. Pakai: #migrasi batal #ID');

            return $migration ?? new CustomerMigration(['status' => CustomerMigration::STATUS_FAILED]);
        }
        if ($guarded = $this->partials->guardPartialCancelFromWa($migration, $payload, $parsed)) {
            return $guarded;
        }
        if ($migration->status === CustomerMigration::STATUS_SYNCED
            || filled(data_get($migration->meta, 'wa_live_execution_queued.queued_at'))
            || filled(data_get($migration->meta, 'wa_live_execution.requested_at'))) {
            $this->host->reply($group, "Migrasi #{$migration->id} sudah masuk eksekusi/selesai. Batal WA ditolak; NOC cek ledger dan rollback hint.");

            return $migration;
        }
        if (! $this->senders->sameGuidedSelectionSender($migration, $payload)) {
            $this->host->reply($group, "Batal #{$migration->id} ditolak. Hanya teknisi yang membuat atau memilih draft tersebut yang boleh membatalkan.");

            return $migration;
        }

        $this->resetDryRunForCandidateChange($migration, 0);
        $migration = $migration->fresh() ?? $migration;
        $meta = MigrationNormalizer::meta($migration);
        $meta['guided_wa_confirmation'] = array_merge(
            (array) ($meta['guided_wa_confirmation'] ?? []),
            ['status' => 'cancelled', 'cancelled_at' => now()->toIso8601String()]
        );
        $meta['timeline'] = MigrationNormalizer::appendTimeline($meta['timeline'] ?? [], 'guided_wa_selection_cancelled');
        $migration->meta = $meta;
        $migration->status = CustomerMigration::STATUS_CANCELLED;
        $migration->last_error = 'Dibatalkan teknisi sebelum device action.';
        $migration->save();
        $this->host->reply($group, "Migrasi #{$migration->id} dibatalkan sebelum device action. Tidak ada PPPoE/ONU/customer yang diubah.");

        return $migration->fresh() ?? $migration;
    }

    /** @param array<string, mixed> $candidate @param array<string, mixed> $payload */
    public function confirmGuidedWaCandidate(CustomerMigration $migration, array $candidate, array $payload, int $number): CustomerMigration
    {
        if ((new MigrationResendGuard)->refuseRefill($migration, $candidate)) {
            return $migration;
        }
        if (empty($candidate['customer_id']) || ($candidate['conflicts'] ?? []) !== []) {
            throw new \RuntimeException('Pelanggan nomor ini belum terhubung ke data pelanggan. Pilih nomor lain atau kirim ulang #migrasi dengan PPPoE/HP.');
        }
        $customer = Customer::query()->find((int) $candidate['customer_id']);
        if (! $customer) {
            throw new \RuntimeException('Customer kandidat sudah tidak tersedia.');
        }

        // A customer with a half-done migration must continue that one; a
        // parallel draft would re-run the already finished device steps.
        $partial = CustomerMigration::query()
            ->where('customer_id', $customer->id)
            ->whereNotIn('status', [CustomerMigration::STATUS_SYNCED, CustomerMigration::STATUS_CANCELLED])
            ->whereNull('synced_at')
            ->whereKeyNot($migration->id)
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->first(fn (CustomerMigration $row): bool => $row->status === CustomerMigration::STATUS_PARTIALLY_EXECUTED
                || $this->partials->partialExecutionState($row) !== null);
        if ($partial) {
            throw new \RuntimeException("Pelanggan ini masih punya migrasi #{$partial->id} yang sebagian dieksekusi; lanjutkan dengan #migrasi eksekusi #{$partial->id} (atau, jika baris itu sudah basi/tidak dipakai lagi: #migrasi batal paksa #{$partial->id})");
        }

        $meta = MigrationNormalizer::meta($migration);
        $explicitTargetRouterId = data_get($meta, 'router_mapping.status') === 'resolved'
            ? (int) data_get($meta, 'router_mapping.target_router_id', 0)
            : 0;
        $targetRouterId = $explicitTargetRouterId ?: (int) $customer->router_id;
        $sameRouter = $targetRouterId === (int) $customer->router_id;
        // customer.olt_id is the SOURCE OLT. Only prefill it as the target OLT
        // for same-router (OLT swap) migrations. For cross-router migrations
        // leave it empty so resolveOltForMigration() applies the "OLT:" line /
        // target router OLT instead of the dead source OLT. An OLT explicitly
        // chosen earlier (different from the source OLT) is preserved.
        // A prefill left by a previously selected candidate is dropped when the
        // candidate changes. An explicit "OLT:" line always beats the prefill.
        $currentNewOltId = (int) $migration->new_olt_id;
        $previousCustomerId = (int) $migration->customer_id;
        if ($currentNewOltId > 0 && $previousCustomerId > 0 && $previousCustomerId !== (int) $customer->id) {
            $previousOltId = (int) Customer::query()->whereKey($previousCustomerId)->value('olt_id');
            if ($previousOltId === $currentNewOltId) {
                $currentNewOltId = 0;
            }
        }
        $hasExplicitOltLine = trim((string) (($this->seams->parser)()->parse((string) $migration->raw_text)['olt_field'] ?? '')) !== '';
        $newOltId = ($sameRouter && ! $hasExplicitOltLine)
            ? $customer->olt_id
            : (($currentNewOltId > 0 && $currentNewOltId !== (int) $customer->olt_id) ? $currentNewOltId : null);
        $migration->fill([
            'customer_id' => $customer->id,
            'pppoe_user' => $candidate['pppoe_user'] ?? $customer->pppoe_user,
            'full_name' => $migration->full_name ?: ($candidate['name'] ?? $customer->name),
            'phone' => $migration->phone ?: $customer->phone,
            'rt' => $migration->rt ?: ($candidate['rt'] ?? null),
            'rw' => $migration->rw ?: ($candidate['rw'] ?? null),
            'odp' => $migration->odp ?: ($candidate['odp'] ?? null),
            'daerah' => $migration->daerah ?: ($candidate['area'] ?? null),
            'area_id' => $customer->area_id,
            'old_router_id' => $customer->router_id,
            'old_olt_id' => $customer->olt_id,
            'old_onu_ref' => $candidate['old_onu_ref'] ?? $migration->old_onu_ref,
            'old_sn' => $candidate['old_sn'] ?? $migration->old_sn,
            'router_id' => $targetRouterId ?: $customer->router_id,
            'new_olt_id' => $newOltId,
            'status' => CustomerMigration::STATUS_NEEDS_REVIEW,
        ]);
        $meta['migration_review'] = [
            'match_status' => 'confirmed_pending_approval',
            'reasons' => ['guided_wa_candidate_confirmed'],
            'source' => 'guided_lookup_wa',
        ];
        $meta['guided_wa_selection'] = [
            'candidate_number' => $number,
            'customer_id' => $customer->id,
            'confidence' => $candidate['confidence'] ?? null,
            'score' => $candidate['score'] ?? null,
            'sources' => $candidate['sources'] ?? [],
            'acknowledged_conflicts' => $candidate['acknowledged_conflicts'] ?? [],
            'selected_by_sender' => (string) ($payload['sender_jid'] ?? ''),
            'selected_by_sender_keys' => $this->senders->waSenderKeys($payload),
            'selected_at' => now()->toIso8601String(),
        ];
        $meta['timeline'] = MigrationNormalizer::appendTimeline($meta['timeline'] ?? [], 'guided_wa_candidate_confirmed', [
            'candidate_number' => $number,
            'customer_id' => $customer->id,
        ]);
        $migration->meta = $meta;
        $migration->last_error = 'Kandidat terkonfirmasi; menunggu dry-run/eksekusi allowlisted.';
        $migration->save();

        return $migration->fresh() ?? $migration;
    }

    public function newerSyncedMigration(CustomerMigration $migration): ?CustomerMigration
    {
        $newSn = MigrationNormalizer::normalizeSerial((string) $migration->new_sn);
        $pppoe = MigrationNormalizer::normalizeUsername((string) $migration->pppoe_user);
        if ($newSn === '' || (! $migration->customer_id && $pppoe === '')) {
            return null;
        }

        return CustomerMigration::query()
            ->where('id', '>', $migration->id)
            ->where('status', CustomerMigration::STATUS_SYNCED)
            ->whereRaw('UPPER(new_sn) = ?', [$newSn])
            ->where(function ($query) use ($migration, $pppoe): void {
                if ($migration->customer_id) {
                    $query->where('customer_id', $migration->customer_id);
                }
                if ($pppoe !== '') {
                    $method = $migration->customer_id ? 'orWhereRaw' : 'whereRaw';
                    $query->{$method}('UPPER(pppoe_user) = ?', [$pppoe]);
                }
            })
            ->latest('id')
            ->first();
    }

    public function resetDryRunForCandidateChange(CustomerMigration $migration, int $newCustomerId): void
    {
        $migration = $migration->fresh() ?? $migration;
        if ($newCustomerId > 0 && (int) $migration->customer_id === $newCustomerId) {
            return;
        }
        $meta = MigrationNormalizer::meta($migration);
        $approval = (array) ($meta['device_action_approval'] ?? []);
        $ledgerId = (int) ($approval['ledger_id'] ?? 0);
        if ($ledgerId > 0) {
            $ledger = DeviceActionLog::query()->find($ledgerId);
            if ($ledger && in_array($ledger->status, ['requested', 'dry_run', 'planned'], true)
                && ! $ledger->executed_at) {
                $ledger->status = 'cancelled';
                $ledger->result = array_merge((array) $ledger->result, [
                    'cancelled_reason' => $newCustomerId > 0 ? 'candidate_reselected' : 'migration_cancelled_before_execute',
                    'device_write' => false,
                    'customer_write' => false,
                    'cancelled_at' => now()->toIso8601String(),
                ]);
                $ledger->save();
            }
        }
        unset(
            $meta['device_action_approval'],
            $meta['device_action_preflight'],
            $meta['wa_live_execution_queued'],
            $meta['wa_live_execution']
        );
        $migration->meta = $meta;
        $migration->save();
    }

    /** @param array<string, mixed> $payload @param array<string, mixed> $operation */
    public function confirmReplacementFromWa(CustomerMigration $migration, array $payload, array $operation): CustomerMigration
    {
        $group = (string) ($payload['group_jid'] ?? $migration->group_jid);
        $meta = MigrationNormalizer::meta($migration);
        $confirmation = (array) ($meta['replacement_confirmation'] ?? []);
        // Only short-circuit when the confirmation still matches the current SN
        // AND a reusable dry-run exists. After an executed ledger or an SN
        // correction the technician's confirmation must prepare a new dry-run;
        // replying "sudah dikonfirmasi" there left #1102 stuck.
        if (($confirmation['status'] ?? null) === 'confirmed'
            && filled($confirmation['expires_at'] ?? null)
            && Carbon::parse((string) $confirmation['expires_at'])->isFuture()
            && MigrationNormalizer::normalizeSerial((string) ($confirmation['new_sn'] ?? '')) === MigrationNormalizer::normalizeSerial((string) $migration->new_sn)
            && $this->ledger->hasReusableDeviceActionDryRun($migration)) {
            $this->host->reply($group, "Replacement #{$migration->id} sudah dikonfirmasi. Tidak ada auto-live; kirim #migrasi eksekusi #{$migration->id}.");

            return $migration;
        }

        $meta['replacement_confirmation'] = [
            'status' => 'confirmed',
            'operation_reference' => $operation['operation_reference'],
            'operation_key' => $operation['operation_key'],
            'customer_id' => $migration->customer_id,
            'target_router_id' => $migration->router_id,
            'pppoe_user' => $migration->pppoe_user,
            'new_sn' => MigrationNormalizer::normalizeSerial((string) $migration->new_sn),
            'confirmed_by_sender_keys' => $this->senders->waSenderKeys($payload),
            'confirmed_at' => now()->toIso8601String(),
            'expires_at' => now()->addMinutes(30)->toIso8601String(),
        ];
        $meta['timeline'] = MigrationNormalizer::appendTimeline($meta['timeline'] ?? [], 'replacement_confirmed', [
            'operation_reference' => $operation['operation_reference'],
            'customer_id' => $migration->customer_id,
            'target_router_id' => $migration->router_id,
        ]);
        $review = is_array($meta['migration_review'] ?? null) ? $meta['migration_review'] : [];
        $review['match_status'] = 'confirmed_pending_approval';
        $review['reasons'] = array_values(array_diff(
            array_values(array_unique((array) ($review['reasons'] ?? []))),
            ['SAME_TARGET_NEW_SN_NO_CONFIRMATION']
        ));
        $review['reasons'][] = 'replacement_confirmed_by_technician';
        $meta['migration_review'] = $review;
        $migration->meta = $meta;
        $migration->save();

        try {
            $migration = $this->host->prepareDeviceActionApproval(
                $migration->fresh() ?? $migration,
                $this->senders->waActor($payload),
                'Teknisi konfirmasi replacement '.$operation['operation_reference']
            );
        } catch (LockBusyException $e) {
            $this->host->reply($group, $e->userMessage());

            return $migration->fresh() ?? $migration;
        } catch (\Throwable $e) {
            $error = MigrationNormalizer::boundedError($e);
            $migration->last_error = 'Konfirmasi replacement tersimpan, dry-run belum siap: '.$error;
            $migration->save();
            $this->host->reply($group, "Replacement #{$migration->id} belum masuk eksekusi: ".$this->texts->shortWaError($error));

            return $migration->fresh() ?? $migration;
        }

        $this->host->reply($group, "Replacement #{$migration->id} dikonfirmasi untuk reference {$operation['operation_reference']}. Tidak ada auto-live; kirim #migrasi eksekusi #{$migration->id} setelah cek dry-run.");

        return $migration->fresh() ?? $migration;
    }

    /**
     * Existing open migration of the same PPPoE in this group that a re-sent
     * `#migrasi` with a different SN must update instead of creating a new
     * draft (#1102: the corrected SN landed in a new draft and was lost).
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $parsed
     */
    public function findOpenMigrationForResend(array $payload, array $parsed, int $customerId = 0): ?CustomerMigration
    {
        $pppoe = strtoupper(MigrationNormalizer::normalizeUsername((string) ($parsed['pppoe_user'] ?? '')));
        $newSn = MigrationNormalizer::normalizeSerial((string) ($parsed['new_sn'] ?? ''));
        $group = (string) ($payload['group_jid'] ?? '');
        if (($pppoe === '' && $customerId <= 0) || $newSn === '' || $group === '') {
            return null;
        }

        return CustomerMigration::query()
            ->where('group_jid', $group)
            ->where('created_at', '>=', now()->subDays(14))
            ->where('status', '!=', CustomerMigration::STATUS_SYNCED)
            ->whereNull('synced_at')
            ->when($customerId > 0, fn ($query) => $query->where('customer_id', $customerId))
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->first(function (CustomerMigration $row) use ($pppoe, $newSn, $customerId): bool {
                if (MigrationNormalizer::isHuaweiOltOnlyMigration($row)) {
                    return false;
                }
                if (MigrationNormalizer::normalizeSerial((string) $row->new_sn) === $newSn) {
                    return false;
                }
                if ($row->status === CustomerMigration::STATUS_CANCELLED && $this->partials->partialExecutionState($row) === null) {
                    return false;
                }
                if ($customerId > 0) {
                    return (int) $row->customer_id === $customerId;
                }
                $meta = MigrationNormalizer::meta($row);
                $identities = array_map(
                    fn ($value): string => strtoupper(MigrationNormalizer::normalizeUsername((string) $value)),
                    [
                        $row->pppoe_user,
                        data_get($meta, 'input_identity.pppoe_user'),
                        data_get($meta, 'mikrotik_migration.old_username'),
                        data_get($meta, 'mikrotik_migration.new_username'),
                    ]
                );

                return in_array($pppoe, $identities, true);
            });
    }
}
