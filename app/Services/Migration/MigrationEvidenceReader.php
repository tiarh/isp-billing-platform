<?php

namespace App\Services\Migration;

use App\Models\Customer;
use App\Models\CustomerMigration;
use App\Models\CustomerMigrationImportRow;
use App\Models\Olt;
use App\Models\OltOnu;
use App\Models\PsbDraft;
use App\Models\PsbStarliteEntry;
use App\Services\DeviceRecoveryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only evidence, snapshots and ownership checks of the migration flow, moved out of
 * CustomerMigrationService without a change of behaviour. Stateless.
 */
final class MigrationEvidenceReader
{
    /**
     * @return array<string, mixed>
     */
    public function oltSnapshotForMigration(CustomerMigration $migration, ?Olt $olt, ?Customer $customer = null): array
    {
        if (! $olt || ! Schema::hasTable('olt_onus')) {
            return ['available' => false];
        }

        $serial = MigrationNormalizer::normalizeSerial((string) $migration->new_sn);
        $ref = MigrationNormalizer::normalizeOnuRef((string) ($migration->new_onu_ref ?: $migration->old_onu_ref));
        $query = OltOnu::query()->where('olt_id', $olt->id);
        $onu = null;
        if ($serial !== '') {
            $onu = (clone $query)
                ->whereRaw('UPPER(REPLACE(REPLACE(COALESCE(serial_number, ""), "-", ""), " ", "")) = ?', [$serial])
                ->latest('id')
                ->first();
        }
        if (! $onu && $ref) {
            $onu = (clone $query)->whereRaw('LOWER(onu_ref) = ?', [strtolower($ref)])->latest('id')->first();
        }

        return [
            'available' => true,
            'found' => $onu !== null,
            'id' => $onu?->id,
            'onu_ref' => $onu?->onu_ref,
            'serial_number' => $onu?->serial_number,
            'onu_name' => $onu?->onu_name,
            'belongs_to_migration' => $onu ? $this->existingOnuBelongsToMigration($migration, $customer, $onu) : null,
            'last_seen' => optional($onu?->updated_at)->toIso8601String(),
        ];
    }

    public function existingOnuBelongsToMigration(
        CustomerMigration $migration,
        ?Customer $customer,
        OltOnu $onu
    ): bool {
        $serial = MigrationNormalizer::normalizeSerial((string) $migration->new_sn);
        if ($customer && $serial !== '' && MigrationNormalizer::normalizeSerial((string) $customer->onu_serial) === $serial) {
            return true;
        }

        $expectedRef = MigrationNormalizer::normalizeOnuRef((string) $migration->new_onu_ref);
        $actualRef = MigrationNormalizer::normalizeOnuRef((string) $onu->onu_ref);
        if ($expectedRef && $actualRef && strcasecmp($expectedRef, $actualRef) === 0) {
            return true;
        }

        $pppoe = MigrationNormalizer::normalizeUsername((string) ($migration->pppoe_user ?: $customer?->pppoe_user));
        $pppoeToken = strtoupper((string) preg_split('/[_\s-]+/', $pppoe, 2)[0]);
        $onuToken = strtoupper((string) preg_split('/[_\s-]+/', trim((string) $onu->onu_name), 2)[0]);

        if (strlen($pppoeToken) >= 4 && $pppoeToken === $onuToken) {
            return true;
        }

        return $this->onuNameDriftBelongsToMigration($customer, $onu, $pppoeToken, $onuToken);
    }

    /**
     * @return array<string, mixed>
     */
    public function genieAcsSnapshotForMigration(CustomerMigration $migration): array
    {
        if (! Schema::hasTable('genieacs_devices')) {
            return ['available' => false];
        }

        $serial = MigrationNormalizer::normalizeSerial((string) $migration->new_sn);
        if ($serial === '') {
            return ['available' => true, 'found' => false];
        }

        if (! Schema::hasColumn('genieacs_devices', 'serial_number')) {
            return ['available' => true, 'found' => false, 'warning' => 'serial_number_column_missing'];
        }

        $columns = collect(['id', 'device_id', 'serial_number', 'tags', 'tag', 'updated_at'])
            ->filter(fn (string $column) => Schema::hasColumn('genieacs_devices', $column))
            ->values()
            ->all();

        $row = DB::table('genieacs_devices')
            ->select($columns ?: ['id'])
            ->whereRaw('UPPER(REPLACE(REPLACE(COALESCE(serial_number, ""), "-", ""), " ", "")) = ?', [$serial])
            ->when(Schema::hasColumn('genieacs_devices', 'updated_at'), fn ($query) => $query->orderByDesc('updated_at'))
            ->first();

        return [
            'available' => true,
            'found' => $row !== null,
            'id' => $row->id ?? null,
            'serial_number' => $row->serial_number ?? null,
            'tag' => $row->tag ?? ($row->tags ?? null),
            'last_seen' => $row->updated_at ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function reviewMigrationDraft(CustomerMigration $migration, ?Customer $customer): array
    {
        $evidence = $this->confirmedMigrationEvidence($migration);
        $psbOverlap = $this->psbOverlapEvidence($migration);
        $reasons = [];
        $matchStatus = 'confirmed_pending_approval';

        if (! $customer) {
            $reasons[] = 'customer_missing';
            $matchStatus = 'needs_review';
        }

        if ($evidence === []) {
            $reasons[] = 'excel_confirm_missing';
            $matchStatus = 'needs_review';
        } elseif (count($evidence) > 1) {
            $reasons[] = 'excel_match_multiple';
            $matchStatus = 'needs_review';
        } else {
            $confirmed = $evidence[0];
            $this->applyConfirmedEvidence($migration, $confirmed);
            $conflicts = $this->migrationConflicts($migration, $confirmed);
            if ($conflicts !== []) {
                $reasons = array_merge($reasons, $conflicts);
                $matchStatus = 'needs_review';
            }
        }

        if ($psbOverlap !== []) {
            $reasons[] = 'psb_overlap';
            $matchStatus = 'needs_review';
        }

        if (trim((string) $migration->new_sn) === '') {
            $reasons[] = 'new_sn_missing';
            $matchStatus = 'needs_review';
        }

        if ($reasons === []) {
            $reasons[] = 'operator_approval_required';
        }

        $migration->save();

        return [
            'match_status' => $matchStatus,
            'reasons' => array_values(array_unique($reasons)),
            'message' => $matchStatus === 'confirmed_pending_approval'
                ? 'Draft migrasi terverifikasi ke starlite-akuisisi, menunggu approval operator. Tidak ada action perangkat.'
                : 'Draft migrasi perlu review: '.implode(', ', array_values(array_unique($reasons))).'. Tidak ada action perangkat.',
            'confirmed_evidence' => $evidence,
            'psb_overlap' => $psbOverlap,
            'ktp_policy' => [
                'preserve_existing' => ! $migration->ktp_photo_path && $customer && (string) ($customer->ktp_photo_url ?? '') !== '',
                'migration_photo' => $migration->ktp_photo_path,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function confirmedMigrationEvidence(CustomerMigration $migration): array
    {
        $out = [];
        $pppoe = MigrationNormalizer::normalizeUsername((string) $migration->pppoe_user);
        $name = trim((string) $migration->full_name);
        $oldSn = MigrationNormalizer::normalizeSerial((string) $migration->old_sn);
        $newSn = MigrationNormalizer::normalizeSerial((string) $migration->new_sn);

        $appendMigration = function (CustomerMigration $row) use (&$out): void {
            $meta = MigrationNormalizer::meta($row);
            $out['migration:'.$row->id] = [
                'source' => 'customer_migrations',
                'id' => $row->id,
                'status' => $row->status,
                'project' => $meta['project'] ?? null,
                'area' => $meta['area'] ?? $row->daerah,
                'rw' => $meta['rw'] ?? $row->rw,
                'pppoe_user' => $row->pppoe_user,
                'full_name' => $row->full_name,
                'old_sn' => MigrationNormalizer::normalizeSerial((string) $row->old_sn) ?: null,
                'new_sn' => MigrationNormalizer::normalizeSerial((string) $row->new_sn) ?: null,
                'old_onu_ref' => $row->old_onu_ref,
                'message_id' => $row->message_id,
            ];
        };

        CustomerMigration::query()
            ->where('source', 'excel_import')
            ->whereKeyNot($migration->id ?: 0)
            ->where(function ($q) use ($pppoe, $name, $oldSn, $newSn) {
                if ($pppoe !== '') {
                    $q->orWhereRaw('LOWER(pppoe_user) = ?', [strtolower($pppoe)]);
                }
                if ($name !== '') {
                    $q->orWhere('full_name', 'like', '%'.str_replace(['%', '_'], '', $name).'%');
                }
                if ($oldSn !== '') {
                    $q->orWhereRaw('UPPER(old_sn) = ?', [$oldSn]);
                }
                if ($newSn !== '') {
                    $q->orWhereRaw('UPPER(new_sn) = ?', [$newSn]);
                }
            })
            ->limit(5)
            ->get()
            ->each($appendMigration);

        if (Schema::hasTable('customer_migration_import_rows')) {
            CustomerMigrationImportRow::query()
                ->where('classification', 'migration')
                ->where('project', 'starlite-akuisisi')
                ->where('status', 'applied')
                ->where(function ($q) use ($pppoe, $name, $oldSn, $newSn) {
                    if ($pppoe !== '') {
                        $q->orWhereRaw('UPPER(pppoe_user) = ?', [strtoupper($pppoe)]);
                    }
                    if ($name !== '') {
                        $q->orWhere('full_name', 'like', '%'.str_replace(['%', '_'], '', $name).'%');
                    }
                    if ($oldSn !== '') {
                        $q->orWhereRaw('UPPER(sn_lama) = ?', [$oldSn]);
                    }
                    if ($newSn !== '') {
                        $q->orWhereRaw('UPPER(sn_baru) = ?', [$newSn]);
                    }
                })
                ->limit(5)
                ->get()
                ->each(function (CustomerMigrationImportRow $row) use (&$out): void {
                    $out['import_row:'.$row->id] = [
                        'source' => 'customer_migration_import_rows',
                        'id' => $row->id,
                        'status' => $row->status,
                        'project' => $row->project,
                        'area' => $row->area,
                        'rw' => $row->rw,
                        'pppoe_user' => $row->pppoe_user,
                        'full_name' => $row->full_name,
                        'old_sn' => MigrationNormalizer::normalizeSerial((string) $row->sn_lama) ?: null,
                        'new_sn' => MigrationNormalizer::normalizeSerial((string) $row->sn_baru) ?: null,
                        'old_onu_ref' => $row->old_onu_ref,
                        'match_status' => $row->match_status,
                    ];
                });
        }

        return array_values($out);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function psbOverlapEvidence(CustomerMigration $migration): array
    {
        $out = [];
        $pppoe = MigrationNormalizer::normalizeUsername((string) $migration->pppoe_user);
        $sn = MigrationNormalizer::normalizeSerial((string) $migration->new_sn);

        // A device returned via a `received` dismantle recovery case may be
        // reused as "SN baru": PSB history of its old, non-live owner is not an
        // overlap. Matches on the migrating customer's own PPPoE still count.
        $recovery = app(DeviceRecoveryService::class);
        $releasedIds = $sn !== '' ? $recovery->releasedCustomerIdsForSerial($sn) : [];
        $releasedPppoe = $sn !== '' ? array_map('strtoupper', $recovery->releasedPppoeUsersForSerial($sn)) : [];
        $excludeReleasedPppoe = function ($query) use ($releasedPppoe): void {
            if ($releasedPppoe === []) {
                return;
            }
            $query->where(fn ($owner) => $owner
                ->whereNull('pppoe_user')
                ->orWhereRaw(
                    'UPPER(pppoe_user) NOT IN ('.implode(',', array_fill(0, count($releasedPppoe), '?')).')',
                    $releasedPppoe
                ));
        };

        if (Schema::hasTable('psb_starlite_entries')) {
            PsbStarliteEntry::query()
                ->where(function ($q) use ($pppoe, $sn, $excludeReleasedPppoe) {
                    if ($pppoe !== '') {
                        $q->orWhereRaw('UPPER(pppoe_user) = ?', [strtoupper($pppoe)]);
                    }
                    if ($sn !== '') {
                        $q->orWhere(function ($snQuery) use ($sn, $excludeReleasedPppoe) {
                            $snQuery->whereRaw('UPPER(sn) = ?', [$sn]);
                            $excludeReleasedPppoe($snQuery);
                        });
                    }
                })
                ->limit(3)
                ->get()
                ->each(function (PsbStarliteEntry $entry) use (&$out): void {
                    $out[] = [
                        'source' => 'psb_starlite_entries',
                        'id' => $entry->id,
                        'pppoe_user' => $entry->pppoe_user,
                        'sn' => $entry->sn,
                        'service_type' => $entry->service_type,
                        'source_key' => $entry->source_key,
                    ];
                });
        }

        if (Schema::hasTable('psb_drafts')) {
            PsbDraft::query()
                ->where(function ($q) use ($pppoe, $sn, $releasedIds, $excludeReleasedPppoe) {
                    if ($pppoe !== '') {
                        $q->orWhereRaw('UPPER(pppoe_user) = ?', [strtoupper($pppoe)]);
                    }
                    if ($sn !== '') {
                        $q->orWhere(function ($snQuery) use ($sn, $releasedIds, $excludeReleasedPppoe) {
                            $snQuery->whereRaw('UPPER(sn) = ?', [$sn]);
                            if ($releasedIds !== []) {
                                $snQuery->where(fn ($owner) => $owner
                                    ->whereNull('customer_id')
                                    ->orWhereNotIn('customer_id', $releasedIds));
                            }
                            $excludeReleasedPppoe($snQuery);
                        });
                    }
                })
                ->limit(3)
                ->get(['id', 'pppoe_user', 'sn', 'status', 'message_id'])
                ->each(function (PsbDraft $draft) use (&$out): void {
                    $out[] = [
                        'source' => 'psb_drafts',
                        'id' => $draft->id,
                        'pppoe_user' => $draft->pppoe_user,
                        'sn' => $draft->sn,
                        'status' => $draft->status,
                        'message_id' => $draft->message_id,
                    ];
                });
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $confirmed
     */
    public function applyConfirmedEvidence(CustomerMigration $migration, array $confirmed): void
    {
        $migration->old_sn = $migration->old_sn ?: ($confirmed['old_sn'] ?? null);
        $migration->new_sn = $migration->new_sn ?: ($confirmed['new_sn'] ?? null);
        $migration->old_onu_ref = $migration->old_onu_ref ?: ($confirmed['old_onu_ref'] ?? null);
        $migration->pppoe_user = $migration->pppoe_user ?: ($confirmed['pppoe_user'] ?? null);
        $migration->full_name = $migration->full_name ?: ($confirmed['full_name'] ?? null);
        $migration->rw = $migration->rw ?: MigrationNormalizer::rwNumber((string) ($confirmed['rw'] ?? ''));
        $migration->daerah = $migration->daerah ?: ($confirmed['area'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $confirmed
     * @return list<string>
     */
    public function migrationConflicts(CustomerMigration $migration, array $confirmed): array
    {
        $conflicts = [];
        $checks = [
            'old_sn' => [MigrationNormalizer::normalizeSerial((string) $migration->old_sn), MigrationNormalizer::normalizeSerial((string) ($confirmed['old_sn'] ?? ''))],
            'new_sn' => [MigrationNormalizer::normalizeSerial((string) $migration->new_sn), MigrationNormalizer::normalizeSerial((string) ($confirmed['new_sn'] ?? ''))],
            'pppoe_user' => [strtolower(MigrationNormalizer::normalizeUsername((string) $migration->pppoe_user)), strtolower(MigrationNormalizer::normalizeUsername((string) ($confirmed['pppoe_user'] ?? '')))],
        ];

        foreach ($checks as $field => [$current, $expected]) {
            if ($current !== '' && $expected !== '' && $current !== $expected) {
                $conflicts[] = $field.'_conflict';
            }
        }

        return $conflicts;
    }

    /**
     * @return array<string, mixed>
     */
    public function customerSnapshot(Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'pppoe_user' => $customer->pppoe_user,
            'phone' => $customer->phone,
            'area_id' => $customer->area_id,
            'router_id' => $customer->router_id,
            'olt_id' => $customer->olt_id,
            'olt_port_label' => $customer->olt_port_label,
            'onu_serial' => $customer->onu_serial,
            'odp_name' => $customer->odp_name ?? null,
            'ktp_photo_url' => $customer->ktp_photo_url ?? null,
            // Used by the migration target contract to preserve the current
            // package/profile for sites that must not change customer speed.
            'package_id' => $customer->package_id,
            'mikrotik_profile' => $customer->mikrotik_profile,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function proposedCustomerSnapshot(CustomerMigration $migration, ?Customer $customer, ?Olt $targetOlt = null): array
    {
        return array_filter([
            'customer_id' => $customer?->id,
            'name' => $migration->full_name ?: $customer?->name,
            'pppoe_user' => $migration->pppoe_user ?: $customer?->pppoe_user,
            'phone' => $migration->phone ?: $customer?->phone,
            'area_id' => $migration->area_id ?: $customer?->area_id,
            'router_id' => $migration->router_id ?: $customer?->router_id,
            'olt_id' => $migration->new_olt_id ?: $targetOlt?->id ?: $customer?->olt_id,
            'olt_port_label' => $migration->new_onu_ref ?: $migration->new_pon_port,
            'onu_serial' => $migration->new_sn,
            'old_onu_ref' => $migration->old_onu_ref,
            'old_sn' => $migration->old_sn,
            'ktp_photo_url' => $migration->ktp_photo_path ?: ($customer?->ktp_photo_url ?? null),
            'odp_photo_url' => $migration->router_photo_path ?: null,
            'device_write' => false,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /**
     * @return array<string, mixed>
     */
    public function oldOnuSnapshot(Customer $customer): array
    {
        $oldOltId = $customer->olt_id ? (int) $customer->olt_id : null;
        $oldRef = MigrationNormalizer::normalizeOnuRef((string) ($customer->olt_port_label ?? ''));
        $oldSn = MigrationNormalizer::normalizeSerial((string) ($customer->onu_serial ?? ''));

        if (Schema::hasTable('olt_onus')) {
            $q = OltOnu::query();
            if ($oldOltId) {
                $q->where('olt_id', $oldOltId);
            }
            if ($oldSn !== '') {
                $onu = (clone $q)
                    ->whereRaw('UPPER(REPLACE(REPLACE(COALESCE(serial_number, ""), "-", ""), " ", "")) = ?', [$oldSn])
                    ->orderByDesc('id')
                    ->first();
                if ($onu) {
                    $oldOltId = (int) $onu->olt_id;
                    $oldRef = MigrationNormalizer::normalizeOnuRef((string) $onu->onu_ref) ?: $oldRef;
                    $oldSn = MigrationNormalizer::normalizeSerial((string) $onu->serial_number) ?: $oldSn;
                }
            }
            if (! $oldRef && $customer->pppoe_user) {
                $onu = (clone $q)
                    ->whereRaw('LOWER(COALESCE(onu_name, "")) = ?', [strtolower((string) $customer->pppoe_user)])
                    ->orderByDesc('id')
                    ->first();
                if ($onu) {
                    $oldOltId = (int) $onu->olt_id;
                    $oldRef = MigrationNormalizer::normalizeOnuRef((string) $onu->onu_ref) ?: $oldRef;
                    $oldSn = MigrationNormalizer::normalizeSerial((string) $onu->serial_number) ?: $oldSn;
                }
            }
        }

        return [
            'old_router_id' => $customer->router_id,
            'old_olt_id' => $oldOltId,
            'old_onu_ref' => $oldRef,
            'old_sn' => $oldSn !== '' ? $oldSn : null,
        ];
    }

    /**
     * Reasons the SN may not be assigned to this migration (owned by another
     * live customer, another open migration, or another ONU on the OLT).
     *
     * @return list<string>
     */
    public function snOwnershipConflicts(CustomerMigration $migration, string $sn, ?Olt $olt, ?Customer $customer): array
    {
        $conflicts = [];
        $owner = Customer::query()
            ->when($customer, fn ($query) => $query->whereKeyNot($customer->id))
            ->whereRaw('UPPER(REPLACE(REPLACE(COALESCE(onu_serial, ""), "-", ""), " ", "")) = ?', [$sn])
            ->first(['id', 'name', 'pppoe_user']);
        if ($owner) {
            $conflicts[] = 'SN sudah dipakai pelanggan lain ('.($owner->pppoe_user ?: $owner->name ?: '#'.$owner->id).')';
        }

        $otherMigration = CustomerMigration::query()
            ->whereKeyNot($migration->id ?: 0)
            ->whereNotIn('status', [CustomerMigration::STATUS_CANCELLED])
            ->whereRaw('UPPER(REPLACE(REPLACE(COALESCE(new_sn, ""), "-", ""), " ", "")) = ?', [$sn])
            // Unlinked drafts (e.g. an accidental re-sent draft) do not own an
            // SN; only an open migration of a different customer does.
            ->whereNotNull('customer_id')
            ->when($customer, fn ($query) => $query->where('customer_id', '!=', $customer->id))
            ->latest('id')
            ->first(['id']);
        if ($otherMigration) {
            $conflicts[] = "SN sudah dipakai migrasi #{$otherMigration->id}";
        }

        if ($olt && Schema::hasTable('olt_onus')) {
            $onu = OltOnu::query()
                ->where('olt_id', $olt->id)
                ->whereRaw('UPPER(REPLACE(REPLACE(COALESCE(serial_number, ""), "-", ""), " ", "")) = ?', [$sn])
                ->latest('id')
                ->first();
            if ($onu) {
                $probe = clone $migration;
                $probe->new_sn = $sn;
                $probe->new_onu_ref = null;
                if (! $this->existingOnuBelongsToMigration($probe, $customer, $onu)) {
                    $conflicts[] = "SN sudah terdaftar di ONU lain {$onu->onu_ref} ({$onu->onu_name}) di {$olt->name}";
                }
            }
        }

        return $conflicts;
    }

    /**
     * Name drift: the ONU was registered with a truncated/typo name (e.g.
     * USERAB7 for PPPoE USERAB27_...). Accept it as this migration's ONU only
     * when the names are within two characters and no other customer claims
     * the ONU by SN, port, or PPPoE.
     */
    public function onuNameDriftBelongsToMigration(?Customer $customer, OltOnu $onu, string $pppoeToken, string $onuToken): bool
    {
        if (! $customer || strlen($pppoeToken) < 4 || strlen($onuToken) < 4) {
            return false;
        }
        if (substr($pppoeToken, 0, 4) !== substr($onuToken, 0, 4) || levenshtein($pppoeToken, $onuToken) > 2) {
            return false;
        }

        $serial = MigrationNormalizer::normalizeSerial((string) $onu->serial_number);
        $ref = MigrationNormalizer::normalizeOnuRef((string) $onu->onu_ref);

        return ! Customer::query()
            ->whereKeyNot($customer->id)
            ->where(function ($query) use ($serial, $ref, $onu, $onuToken): void {
                $query->whereRaw('UPPER(pppoe_user) = ?', [$onuToken])
                    ->orWhereRaw('UPPER(pppoe_user) LIKE ?', [str_replace(['%', '_'], ['\%', '\_'], $onuToken).'\_%']);
                if ($serial !== '') {
                    $query->orWhereRaw('UPPER(REPLACE(REPLACE(COALESCE(onu_serial, ""), "-", ""), " ", "")) = ?', [$serial]);
                }
                if ($ref) {
                    $query->orWhere(function ($portQuery) use ($onu, $ref): void {
                        $portQuery->where('olt_id', $onu->olt_id)
                            ->whereRaw('LOWER(COALESCE(olt_port_label, "")) = ?', [strtolower($ref)]);
                    });
                }
            })
            ->exists();
    }
}
