<?php

namespace App\Services\Migration;

use App\Exceptions\HuaweiOltSessionBusyException;
use App\Exceptions\LockBusyException;
use App\Models\CustomerMigration;
use App\Services\CustomerMigrationService;
use App\Services\HuaweiOltOnlyProvisioningService;

/**
 * Huawei OLT-only intake and confirm from WA, moved out of CustomerMigrationService without a
 * change of behaviour.
 *
 * A Huawei draft never gets a guided candidate list and never calls the C300/FiberHome uncfg
 * path. The facade is reached for `reply` and `prepareDeviceActionApproval` only; the Huawei
 * adapter comes through the seams at call time.
 */
final class MigrationHuaweiOltOnlyIntake
{
    public function __construct(
        private CustomerMigrationService $host,
        private MigrationSeams $seams,
        private MigrationWaSenders $senders,
        private MigrationTargetResolver $resolver,
        private CustomerMigrationWaMessages $texts,
    ) {}

    /** @param array<string, mixed> $payload @param array<string, mixed> $parsed */
    public function createHuaweiOltOnlyDraftFromWa(array $payload, array $parsed): CustomerMigration
    {
        $group = (string) ($payload['group_jid'] ?? '');
        $pppoe = MigrationNormalizer::normalizeUsername((string) ($parsed['pppoe_user'] ?? ''));
        $newSn = MigrationNormalizer::normalizeSerial((string) ($parsed['new_sn'] ?? ''));
        $oldSn = MigrationNormalizer::normalizeSerial((string) ($parsed['old_sn'] ?? ''));
        $targetRouterField = trim((string) ($parsed['new_router_field'] ?? $parsed['router_field'] ?? ''));

        // PPPoE is optional for the technician. When omitted, resolve it
        // read-only from the existing customer (matched by old SN or name) so
        // the Huawei ONT description and router-secret check still work.
        $pppoeResolution = null;
        if ($pppoe === '') {
            $resolved = $this->resolver->resolvePppoeForHuaweiDraft($parsed, $oldSn, $targetRouterField);
            if ($resolved['pppoe'] !== null) {
                $pppoe = $resolved['pppoe'];
                $pppoeResolution = $resolved;
            }
        }

        $key = 'huawei-olt-only-wa:'.substr(hash('sha256', json_encode([
            $group,
            $pppoe,
            $newSn,
            $targetRouterField,
            trim((string) ($parsed['olt_field'] ?? '')),
        ], JSON_UNESCAPED_SLASHES) ?: serialize([$group, $pppoe, $newSn])), 0, 24);
        $migration = CustomerMigration::query()->firstOrNew(['message_id' => $key]);
        if (! $migration->exists) {
            $migration->fill([
                'source' => 'guided_lookup_wa',
                'group_jid' => $group,
                'sender_jid' => (string) ($payload['sender_jid'] ?? ''),
                'sender_name' => (string) ($payload['sender_name'] ?? ''),
                'message_id' => $key,
                'raw_text' => (string) ($parsed['raw'] ?? ''),
                'pppoe_user' => $pppoe,
                'full_name' => (string) ($parsed['full_name'] ?? ''),
                'phone' => (string) ($parsed['phone'] ?? ''),
                'rt' => $parsed['rt'] ?? null,
                'rw' => $parsed['rw'] ?? null,
                'daerah' => $parsed['daerah'] ?? null,
                'new_sn' => $newSn,
                'old_sn' => $oldSn,
                'new_pon_port' => $parsed['pon_port'] ?? null,
                'vlan' => $parsed['vlan_field'] ?? null,
                'tr069_vlan' => $parsed['tr069_vlan_field'] ?? null,
            ]);
        }

        $targetRouter = $targetRouterField !== ''
            ? $this->resolver->resolveMigrationRouterAlias($targetRouterField)
            : null;
        if ($targetRouter) {
            $migration->router_id = $targetRouter->id;
        }

        // Cross-router router replacement (e.g. Bumiayu -> Gadang). The PPPoE
        // secret is read from the source router and copied to the target.
        $sourceRouterField = trim((string) ($parsed['old_router_field'] ?? ''));
        $sourceRouter = $sourceRouterField !== ''
            ? $this->resolver->resolveMigrationRouterAlias($sourceRouterField)
            : null;
        if ($sourceRouter) {
            $migration->old_router_id = $sourceRouter->id;
        }
        $crossRouter = $sourceRouter !== null
            && $targetRouter !== null
            && (int) $sourceRouter->id !== (int) $targetRouter->id;

        $migration->status = CustomerMigration::STATUS_NEEDS_REVIEW;

        $preflight = [
            'eligible' => false,
            'reasons' => array_values(array_filter([
                $pppoe === '' ? 'pppoe_missing' : null,
                $newSn === '' ? 'new_sn_missing' : null,
            ])),
            'warnings' => [],
            'mode' => HuaweiOltOnlyProvisioningService::MODE,
            'checked_at' => now()->toIso8601String(),
        ];
        if ($preflight['reasons'] === []) {
            try {
                $preflight = ($this->seams->huaweiOltOnly)()->preflight($migration->fresh() ?? $migration);
            } catch (HuaweiOltSessionBusyException $e) {
                // Draft creation is read-only: keep the draft, mark not ready.
                $preflight['reasons'] = ['olt_session_busy'];
                $preflight['warnings'] = [$e->userMessage()];
            }
            if (! empty($preflight['router']['id'])) {
                $migration->router_id = (int) $preflight['router']['id'];
            }
            if (! empty($preflight['olt']['id'])) {
                $migration->new_olt_id = (int) $preflight['olt']['id'];
            }
            $migration->new_pon_port = data_get($preflight, 'placement.pon_port') ?: $migration->new_pon_port;
            $migration->new_onu_id = data_get($preflight, 'placement.onu_id') ?? $migration->new_onu_id;
            $migration->new_onu_ref = data_get($preflight, 'placement.onu_ref') ?: $migration->new_onu_ref;
            $migration->vlan = data_get($preflight, 'plan.internet_vlan') ?? $migration->vlan;
            $migration->tr069_vlan = data_get($preflight, 'plan.tr069_vlan') ?? $migration->tr069_vlan;
        }

        $meta = MigrationNormalizer::meta($migration);
        $meta['migration_operation_mode'] = HuaweiOltOnlyProvisioningService::MODE;
        $meta['no_customer_lookup'] = true;
        $meta['password_source'] = $crossRouter ? 'source_router_read_only' : 'target_router_read_only';
        $meta['cross_router'] = $crossRouter;
        if ($crossRouter) {
            $meta['router_mapping'] = [
                'source_input' => $sourceRouterField,
                'source_router_id' => $sourceRouter?->id,
                'target_input' => $targetRouterField,
                'target_router_id' => $targetRouter?->id,
                'cross_router' => true,
                'status' => 'resolved',
            ];
        }
        if (trim((string) ($parsed['keterangan'] ?? '')) !== '') {
            $meta['keterangan'] = trim((string) $parsed['keterangan']);
        }
        if (is_array($pppoeResolution)) {
            $meta['pppoe_resolution'] = $pppoeResolution;
        }
        $meta['guided_wa_selection'] = [
            'candidate_number' => 1,
            'selected_by_sender' => (string) ($payload['sender_jid'] ?? ''),
            'selected_by_sender_keys' => $this->senders->waSenderKeys($payload),
            'selected_at' => now()->toIso8601String(),
        ];
        $meta['guided_wa_confirmation'] = [
            'status' => 'pending',
            'selected_by_sender_keys' => $this->senders->waSenderKeys($payload),
            'expires_at' => now()->addMinutes(30)->toIso8601String(),
        ];
        $meta['huawei_olt_only'] = [
            'preflight' => $preflight,
            'requested_onu_ref_hint' => $parsed['new_onu_ref_hint'] ?? data_get($meta, 'huawei_olt_only.requested_onu_ref_hint'),
            'updated_at' => now()->toIso8601String(),
        ];
        $meta['device_write'] = false;
        $meta['timeline'] = MigrationNormalizer::appendTimeline($meta['timeline'] ?? [], 'huawei_olt_only_draft_received', [
            'mode' => HuaweiOltOnlyProvisioningService::MODE,
            'preflight_reasons' => $preflight['reasons'] ?? [],
        ]);
        $meta['wa_origin_sender_keys'] = $this->senders->waSenderKeys($payload);
        $migration->meta = $meta;
        $migration->last_error = ($preflight['eligible'] ?? false)
            ? 'Huawei OLT-only preflight siap; menunggu konfirmasi teknisi.'
            : 'Huawei OLT-only belum siap: '.implode(', ', (array) ($preflight['reasons'] ?? ['preflight_unavailable']));
        $migration->save();

        $lines = [
            "Draft Huawei OLT-only #{$migration->id}",
            'PPPoE: '.($migration->pppoe_user ?: '-'),
            'SN baru: '.($migration->new_sn ?: '-'),
            'Router target: '.($targetRouter?->name ?: data_get($preflight, 'router.name') ?: $targetRouterField ?: '-'),
            'OLT target: '.((string) data_get($preflight, 'olt.name', $parsed['olt_field'] ?? '-')),
            'Password tidak diminta dari teknisi; hanya diverifikasi read-only dari secret router target.',
            'Customer/KTP tidak diperlukan untuk mode ini; ONU lama tidak disentuh.',
        ];
        if (($preflight['eligible'] ?? false) === true) {
            $lines[] = 'Hasil discovery: '.data_get($preflight, 'placement.pon_port', '-').':'.data_get($preflight, 'placement.onu_id', '-');
            $lines[] = 'VLAN internet/TR-069: '.data_get($preflight, 'plan.internet_vlan', '-').' / '.data_get($preflight, 'plan.tr069_vlan', '-');
            $lines[] = "Konfirmasi: #migrasi konfirmasi #{$migration->id}";
        } else {
            $lines[] = 'Belum siap: '.implode(', ', (array) ($preflight['reasons'] ?? ['preflight_unavailable']));
            $lines[] = 'Pastikan ONT menyala, SN terdeteksi autofind, dan target tidak ambigu; lalu kirim ulang format yang sama.';
        }
        $lines[] = "Eksekusi hanya setelah review: #migrasi eksekusi #{$migration->id}";
        $this->host->reply($group, implode("\n", $lines));

        return $migration->fresh() ?? $migration;
    }

    /** @param array<string, mixed> $payload */
    public function confirmHuaweiOltOnlyFromWa(CustomerMigration $migration, array $payload): CustomerMigration
    {
        $group = (string) ($payload['group_jid'] ?? $migration->group_jid);
        $previousLastError = $migration->last_error;
        try {
            $meta = MigrationNormalizer::meta($migration);
            $confirmation = (array) ($meta['guided_wa_confirmation'] ?? []);
            $meta['guided_wa_confirmation'] = array_merge($confirmation, [
                'status' => 'confirmed',
                'confirmed_by_sender_keys' => $this->senders->waSenderKeys($payload),
                'confirmed_at' => now()->toIso8601String(),
            ]);
            $meta['timeline'] = MigrationNormalizer::appendTimeline($meta['timeline'] ?? [], 'huawei_olt_only_confirmed', [
                'mode' => HuaweiOltOnlyProvisioningService::MODE,
            ]);
            $migration->meta = $meta;
            $migration->last_error = 'Huawei OLT-only dikonfirmasi; menyiapkan dry-run read-only.';
            $migration->save();

            $migration = $this->host->prepareDeviceActionApproval(
                $migration->fresh() ?? $migration,
                $this->senders->waActor($payload),
                'Teknisi konfirmasi Huawei OLT-only'
            );
            $status = data_get($migration->meta, 'device_action_approval.status');
            $message = $status === 'dry_run_ready'
                ? "Huawei OLT-only #{$migration->id} sudah dry-run. Password hanya dibaca dari router target secara read-only; tidak ada input password teknisi, customer/KTP, MikroTik write, atau ONU lama. Kirim #migrasi eksekusi #{$migration->id} setelah review."
                : "Huawei OLT-only #{$migration->id} belum siap dieksekusi. Cek: #migrasi cek #{$migration->id}";
            $this->host->reply($group, $message);

            return $migration->fresh() ?? $migration;
        } catch (LockBusyException $e) {
            // Do not leave the stale "menyiapkan dry-run" text behind.
            $busy = $migration->fresh() ?? $migration;
            if ($busy->last_error === 'Huawei OLT-only dikonfirmasi; menyiapkan dry-run read-only.') {
                $busy->last_error = $previousLastError;
                $busy->save();
            }
            $this->host->reply($group, $e->userMessage());

            return $busy->fresh() ?? $busy;
        } catch (\Throwable $e) {
            $error = MigrationNormalizer::boundedError($e);
            $migration->last_error = 'Huawei OLT-only confirmation tertahan: '.$error;
            $migration->save();
            $this->host->reply($group, "Huawei OLT-only #{$migration->id} belum dry-run: ".$this->texts->shortWaError($error));

            return $migration->fresh() ?? $migration;
        }
    }
}
