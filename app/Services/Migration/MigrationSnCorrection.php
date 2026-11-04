<?php

namespace App\Services\Migration;

use App\Models\CustomerMigration;
use App\Models\Olt;
use App\Models\OltOnu;
use App\Services\CustomerMigrationService;
use App\Support\CacheLockWait;
use App\Support\MigrationLockMessages;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * SN correction from WA (`#migrasi sn`, a re-sent `#migrasi` with another SN), the read-only
 * OLT lookup and the typo hints, moved out of CustomerMigrationService without a change of
 * behaviour.
 *
 * Holds the device lock `customer-migration-device-{id}` (120 s, wait 5 s) for the correction,
 * exactly as before. The facade is reached for `reply` only; every other facade dependency
 * (uncfg cache, customer lookup) comes through the seams at call time.
 */
final class MigrationSnCorrection
{
    public function __construct(
        private CustomerMigrationService $host,
        private MigrationSeams $seams,
        private MigrationWaSenders $senders,
        private MigrationGuidedPreviewRefresh $refresh,
        private MigrationPartialExecution $partials,
        private MigrationDeviceLedger $ledger,
        private MigrationDeviceActionPlanner $planner,
        private MigrationTargetResolver $resolver,
        private MigrationEvidenceReader $evidence,
        private CustomerMigrationWaMessages $texts,
    ) {}

    /** @param array<string, mixed> $payload @param array<string, mixed> $parsed */
    public function resendOverPartial(CustomerMigration $migration, array $parsed, array $payload): CustomerMigration
    {
        return ($sn = (new MigrationResendGuard)->differentSn($migration, $parsed)) !== null ? $this->correctMigrationSnFromWa($migration, $sn, $payload, 'resend') : $this->partials->markPartiallyExecuted($migration, 'resend_blocked');
    }

    /**
     * Read-only SN lookup on the target OLT: registered snapshot first, then
     * uncfg. Never writes.
     *
     * @return array<string, mixed>
     */
    public function snLookupOnOlt(CustomerMigration $migration, string $sn, ?Olt $olt): array
    {
        if (! $olt) {
            return ['found' => false, 'olt' => null, 'error' => 'OLT target belum dapat dipastikan'];
        }
        if (Schema::hasTable('olt_onus')) {
            $onu = OltOnu::query()
                ->where('olt_id', $olt->id)
                ->whereRaw('UPPER(REPLACE(REPLACE(COALESCE(serial_number, ""), "-", ""), " ", "")) = ?', [$sn])
                ->latest('id')
                ->first();
            if ($onu) {
                return [
                    'found' => true,
                    'source' => 'olt_snapshot',
                    'olt' => $olt->name,
                    'onu_ref' => MigrationNormalizer::normalizeOnuRef((string) $onu->onu_ref) ?: $onu->onu_ref,
                    'onu_name' => $onu->onu_name,
                    'state' => OltOnu::normalizeOperationalState($onu->state, $onu->phase_state, $onu->rx_power_dbm),
                ];
            }
        }
        $uncfg = ($this->seams->readUncfgOnus)($olt);
        foreach ($uncfg['onus'] as $row) {
            if (MigrationNormalizer::normalizeSerial((string) ($row['sn'] ?? '')) === $sn) {
                return [
                    'found' => true,
                    'source' => 'uncfg',
                    'olt' => $olt->name,
                    'port' => MigrationNormalizer::normalizePonPort((string) ($row['port'] ?? '')),
                    'onu_id' => isset($row['onu_id']) ? (int) $row['onu_id'] : null,
                ];
            }
        }

        return [
            'found' => false,
            'olt' => $olt->name,
            'uncfg_ok' => $uncfg['ok'],
            'uncfg_count' => count($uncfg['onus']),
            'error' => $uncfg['error'] ?? null,
        ];
    }

    /**
     * ONU on the target OLT whose SN differs from the technician's SN by at
     * most two characters (8/B, 0/O, 1/I typos) and that is not owned by any
     * other customer. Suggestions only; never auto-applied.
     *
     * @param  list<array<string, mixed>>  $uncfgOnus
     * @return list<array{sn: string, onu_ref: ?string, source: string, distance: int, message: string}>
     */
    public function snTypoHints(CustomerMigration $migration, Olt $olt, array $uncfgOnus = []): array
    {
        $sn = MigrationNormalizer::normalizeSerial((string) $migration->new_sn);
        if (strlen($sn) < 8) {
            return [];
        }

        $candidates = [];
        if (Schema::hasTable('olt_onus')) {
            OltOnu::query()
                ->where('olt_id', $olt->id)
                ->whereNotNull('serial_number')
                ->where('serial_number', 'like', substr($sn, 0, 4).'%')
                ->limit(5000)
                ->get(['serial_number', 'onu_ref', 'onu_name'])
                ->each(function (OltOnu $onu) use (&$candidates): void {
                    $candidateSn = MigrationNormalizer::normalizeSerial((string) $onu->serial_number);
                    if ($candidateSn !== '') {
                        $candidates[$candidateSn] ??= ['onu_ref' => MigrationNormalizer::normalizeOnuRef((string) $onu->onu_ref) ?: $onu->onu_ref, 'source' => 'olt_snapshot'];
                    }
                });
        }
        foreach ($uncfgOnus as $row) {
            $candidateSn = MigrationNormalizer::normalizeSerial((string) ($row['sn'] ?? ''));
            if ($candidateSn !== '' && $candidateSn !== 'TIDAKTERBACA') {
                $candidates[$candidateSn] ??= ['onu_ref' => MigrationNormalizer::normalizePonPort((string) ($row['port'] ?? '')), 'source' => 'uncfg'];
            }
        }

        $customer = ($this->seams->resolveCustomer)($migration);
        $hints = [];
        foreach ($candidates as $candidateSn => $info) {
            if ($candidateSn === $sn || abs(strlen($candidateSn) - strlen($sn)) > 2) {
                continue;
            }
            $distance = levenshtein($candidateSn, $sn);
            if ($distance < 1 || $distance > 2) {
                continue;
            }
            if ($this->evidence->snOwnershipConflicts($migration, $candidateSn, $olt, $customer) !== []) {
                continue;
            }
            $ref = $info['onu_ref'] ?: '-';
            $hints[] = [
                'sn' => $candidateSn,
                'onu_ref' => $info['onu_ref'],
                'source' => $info['source'],
                'distance' => $distance,
                'message' => "SN {$sn} tidak ditemukan di OLT {$olt->name}. Maksud Anda {$candidateSn} (port {$ref})? Kirim #migrasi sn #{$migration->id} {$candidateSn}",
            ];
        }
        usort($hints, fn (array $a, array $b): int => $a['distance'] <=> $b['distance']);

        return array_slice($hints, 0, 2);
    }

    /**
     * `#migrasi sn #ID <SN>` (and a re-sent `#migrasi` with a new SN):
     * validate, check ownership, update new_sn, re-run the read-only OLT
     * lookup and reply. Works for partially executed migrations; prepares the
     * continuation dry-run when the ONU is found. Never writes a device.
     *
     * @param  array<string, mixed>  $payload
     */
    public function correctMigrationSnFromWa(CustomerMigration $migration, string $rawSn, array $payload, string $origin): CustomerMigration
    {
        $group = (string) ($payload['group_jid'] ?? '');
        $id = $migration->id;
        $sn = MigrationNormalizer::normalizeSerial($rawSn);

        if ((string) $migration->group_jid !== $group) {
            $this->host->reply($group, 'Migrasi tidak ditemukan di grup ini. Pakai: #migrasi sn #ID <SN>');

            return $migration;
        }
        if (MigrationNormalizer::migrationAlreadySynced($migration)) {
            $this->host->reply($group, "Migrasi #{$id} sudah selesai; SN tidak diubah.");

            return $migration;
        }
        $partial = $this->partials->partialExecutionState($migration);
        if ($migration->status === CustomerMigration::STATUS_CANCELLED && $partial === null) {
            $this->host->reply($group, "Migrasi #{$id} sudah dibatalkan; SN tidak diubah. Kirim #migrasi baru jika memang perlu.");

            return $migration;
        }
        if (MigrationNormalizer::isHuaweiOltOnlyMigration($migration)) {
            $this->host->reply($group, "Migrasi #{$id} mode Huawei OLT-only: kirim ulang format Huawei dengan SN baru.");

            return $migration;
        }
        if (! $this->senders->canModifyMigrationFromWa($migration, $payload)) {
            $this->host->reply($group, "Koreksi SN #{$id} ditolak. Hanya teknisi pembuat/pemilih draft atau NOC allowlisted yang boleh mengubah SN.");

            return $migration;
        }
        if (preg_match('/^[A-Z]{4}[0-9A-F]{8}$/', $sn) !== 1) {
            $this->host->reply($group, "Format SN tidak valid: {$sn}. Contoh: FHTT12345678 (4 huruf + 8 karakter 0-9/A-F). SN migrasi #{$id} tidak diubah.");

            return $migration;
        }

        $lock = Cache::lock('customer-migration-device-'.$id, 120);
        if (! CacheLockWait::acquire($lock, 5)) {
            $this->host->reply($group, MigrationLockMessages::deviceBusySn((int) $id));

            return $migration;
        }

        try {
            $migration = $migration->fresh() ?? $migration;
            $approvalLedger = $this->ledger->approvalLedger($migration);
            if ($approvalLedger && $approvalLedger->status === 'executing') {
                $this->host->reply($group, "Migrasi #{$id} sedang dieksekusi. SN tidak diubah; tunggu hasilnya.");

                return $migration;
            }

            $oldSn = MigrationNormalizer::normalizeSerial((string) $migration->new_sn);
            $customer = ($this->seams->resolveCustomer)($migration);
            $router = $customer ? $this->resolver->resolveRouterForMigration($migration, $customer) : null;
            $olt = ($customer && $router) ? $this->resolver->resolveOltForMigration($migration, $customer, $router) : null;
            if (! $olt && $migration->new_olt_id) {
                $olt = Olt::query()->find((int) $migration->new_olt_id);
            }

            $conflicts = $this->evidence->snOwnershipConflicts($migration, $sn, $olt, $customer);
            $meta = MigrationNormalizer::meta($migration);
            if ($conflicts !== []) {
                $meta['timeline'] = MigrationNormalizer::appendTimeline($meta['timeline'] ?? [], 'sn_correction_rejected', [
                    'from' => $oldSn ?: null,
                    'to' => $sn,
                    'origin' => $origin,
                    'conflicts' => $conflicts,
                ]);
                $migration->meta = $meta;
                $migration->save();
                $this->host->reply($group, "SN {$sn} ditolak untuk migrasi #{$id}: ".implode('; ', $conflicts).". SN tetap {$oldSn}.");

                return $migration->fresh() ?? $migration;
            }

            $lookup = $this->snLookupOnOlt($migration, $sn, $olt);
            $changed = $sn !== $oldSn;
            if ($changed) {
                $migration->new_sn = $sn;
                // Placement belonged to the previous SN; re-derive it.
                $migration->new_pon_port = null;
                $migration->new_onu_id = null;
                $migration->new_onu_ref = null;
                if (($lookup['source'] ?? null) === 'uncfg' && filled($lookup['port'] ?? null)) {
                    $migration->new_pon_port = $lookup['port'];
                }

                // A not-yet-executed dry-run was planned for the old SN.
                if ($approvalLedger && in_array($approvalLedger->status, ['requested', 'dry_run', 'planned'], true)) {
                    $approvalLedger->status = 'cancelled';
                    $approvalLedger->result = array_merge((array) $approvalLedger->result, [
                        'cancelled_reason' => 'sn_corrected',
                        'device_write' => false,
                        'customer_write' => false,
                        'cancelled_at' => now()->toIso8601String(),
                    ]);
                    $approvalLedger->save();
                    unset($meta['device_action_approval'], $meta['device_action_preflight'], $meta['wa_live_execution_queued'], $meta['wa_live_execution']);
                }

                // An explicit SN command from the bound technician/NOC re-affirms
                // the replacement device for this operation.
                if (is_array($meta['replacement_confirmation'] ?? null)
                    && ($meta['replacement_confirmation']['status'] ?? null) === 'confirmed') {
                    $meta['replacement_confirmation'] = array_merge($meta['replacement_confirmation'], [
                        'new_sn' => $sn,
                        'confirmed_by_sender_keys' => $this->senders->waSenderKeys($payload),
                        'confirmed_at' => now()->toIso8601String(),
                        'expires_at' => now()->addMinutes(30)->toIso8601String(),
                        'via' => 'sn_correction',
                    ]);
                }
                unset($meta['wa_waiting_sn_reply_sent_at']);
            }

            $meta['sn_corrections'] = array_slice(array_merge((array) ($meta['sn_corrections'] ?? []), [[
                'from' => $oldSn ?: null,
                'to' => $sn,
                'changed' => $changed,
                'origin' => $origin,
                'sender_keys' => $this->senders->waSenderKeys($payload),
                'sender_name' => (string) ($payload['sender_name'] ?? ''),
                'message_id' => (string) ($payload['message_id'] ?? ''),
                'lookup' => $lookup,
                'at' => now()->toIso8601String(),
            ]]), -20);
            $meta['timeline'] = MigrationNormalizer::appendTimeline($meta['timeline'] ?? [], $changed ? 'sn_corrected' : 'sn_rechecked', [
                'from' => $oldSn ?: null,
                'to' => $sn,
                'origin' => $origin,
                'olt_found' => (bool) ($lookup['found'] ?? false),
                'lookup_source' => $lookup['source'] ?? null,
            ]);
            $migration->meta = $meta;
            if ($changed && $partial === null && $migration->status !== CustomerMigration::STATUS_CANCELLED) {
                $migration->last_error = "SN dikoreksi {$oldSn} -> {$sn}; ".(($lookup['found'] ?? false) ? 'ONU ditemukan di OLT.' : 'SN belum terlihat di OLT.');
            }
            // Guided draft: the candidate list was made for the old SN. Recompute
            // it (also when the SN is unchanged: this recovers a damaged draft).
            $refresh = null;
            if ($partial === null && (string) $migration->source === 'guided_lookup_wa') {
                $refresh = $this->refresh->refreshGuidedPreview($migration, $origin === 'resend' ? 'resend' : 'sn_command');
                if ($refresh['skipped']) {
                    $refresh = null;
                }
            }
            $migration->save();
            $migration = $migration->fresh() ?? $migration;

            if ($partial !== null) {
                $migration = $this->partials->markPartiallyExecuted($migration, 'sn_corrected', null, false);
            }

            $lines = [$changed ? "*Migrasi #{$id}: SN diperbarui*" : "*Migrasi #{$id}: SN sama, dicek ulang*"];
            $lines[] = 'SN: '.($oldSn ?: '-').' -> '.$sn;
            $oltName = $olt?->name ?: 'OLT target';
            if (($lookup['found'] ?? false) && ($lookup['source'] ?? null) === 'olt_snapshot') {
                $lines[] = "ONU ditemukan di OLT {$oltName} port {$lookup['onu_ref']} (status {$lookup['state']}, nama ONU ".($lookup['onu_name'] ?: '-').').';
                $expected = MigrationNormalizer::onuNameToken((string) ($customer?->pppoe_user ?: $migration->pppoe_user));
                if (filled($lookup['onu_name'] ?? null) && $expected !== '' && MigrationNormalizer::onuNameToken((string) $lookup['onu_name']) !== $expected) {
                    $lines[] = "Nama ONU di OLT '{$lookup['onu_name']}' beda dengan PPPoE '".($customer?->pppoe_user ?: $migration->pppoe_user)."'. Saat dilanjutkan, inventory eBilling & tag ACS memakai PPPoE; rename ONU di OLT lewat menu OLT bila perlu.";
                }
            } elseif ($lookup['found'] ?? false) {
                $lines[] = "SN terdeteksi di uncfg OLT {$oltName} port ".($lookup['port'] ?: '-').'.';
            } else {
                $lines[] = "SN {$sn} belum ditemukan di OLT {$oltName}".(filled($lookup['error'] ?? null) ? ' ('.$this->texts->shortWaError((string) $lookup['error'], 100).')' : '').'.';
                if ($olt) {
                    foreach ($this->snTypoHints($migration, $olt, ($this->seams->uncfgRows)($olt->id)) as $hint) {
                        $lines[] = $hint['message'];
                    }
                }
                $lines[] = 'Pastikan ONT menyala & FO/PON benar, tunggu 30-60 detik, lalu cek lagi dengan perintah yang sama.';
            }

            $prepared = false;
            if (($lookup['found'] ?? false) && ($partial !== null || $this->ledger->hasExecutedDeviceLedger($migration))) {
                try {
                    $migration = $this->planner->prepareDeviceActionApprovalLocked(
                        $migration,
                        $this->senders->waActor($payload),
                        'Koreksi SN '.($oldSn ?: '-').' -> '.$sn.' oleh teknisi; siapkan langkah tersisa'
                    );
                    $prepared = data_get($migration->meta, 'device_action_approval.status') === 'dry_run_ready'
                        && in_array($this->ledger->approvalLedger($migration)?->status, ['dry_run', 'planned'], true);
                } catch (\Throwable $e) {
                    $lines[] = 'Dry-run lanjutan belum siap: '.$this->texts->shortWaError(MigrationNormalizer::boundedError($e), 220);
                    if (str_contains(strtolower($e->getMessage()), 'konfirmasi')) {
                        $lines[] = "Konfirmasi ulang: #migrasi konfirmasi #{$id}";
                    }
                }
            }

            if ($partial !== null) {
                $state = $this->partials->partialExecutionState($migration) ?? $partial;
                $lines = array_merge($lines, $this->texts->partialExecutionLines($migration, $state));
            }
            if ($prepared) {
                $lines[] = 'Dry-run lanjutan siap (ledger #'.data_get($migration->meta, 'device_action_approval.ledger_id').'). Hanya langkah tersisa; MikroTik tidak diulang.';
                $lines[] = "Lanjutkan: #migrasi eksekusi #{$id}";
            } elseif ($refresh !== null && $refresh['selection'] === 'dropped') {
                $lines[] = $this->texts->guidedSelectionDroppedMessage((int) $id, (string) $refresh['conflict_text']);
            } elseif ($refresh !== null && $refresh['selection'] === 'none') {
                $lines[] = $this->texts->guidedListMessage((int) $id, (array) $refresh['candidates']);
            } elseif ($partial === null && ! $this->ledger->hasExecutedDeviceLedger($migration)) {
                $lines[] = "Lanjut: #migrasi konfirmasi #{$id} lalu #migrasi eksekusi #{$id}";
            }
            $lines[] = "Cek: #migrasi cek #{$id}";
            $this->host->reply($group, implode("\n", $lines));

            return $migration->fresh() ?? $migration;
        } finally {
            try {
                $lock->release();
            } catch (\Throwable) {
            }
        }
    }
}
