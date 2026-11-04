<?php

namespace App\Services\Migration;

use App\Models\CustomerMigration;
use App\Models\DeviceActionLog;
use Illuminate\Support\Facades\Log;

/**
 * Safety checks for a migration whose devices were already written.
 * A re-sent guided message must never re-derive the OLT/router of such a
 * migration, and a continuation must never switch to another OLT.
 * Stateless: no device call, no collaborator.
 */
final class MigrationResendGuard
{
    /** True when device writes already happened and the migration is not finished. */
    public function hasDeviceWrites(CustomerMigration $migration): bool
    {
        if (! $migration->exists
            || MigrationNormalizer::migrationAlreadySynced($migration)
            || MigrationNormalizer::isHuaweiOltOnlyMigration($migration)) {
            return false;
        }

        $meta = MigrationNormalizer::meta($migration);
        $mikrotik = (array) ($meta['mikrotik_migration'] ?? []);
        if (($mikrotik['ok'] ?? false) === true
            || ($mikrotik['target_prepared'] ?? false) === true
            || $migration->olt_at !== null
            || filled($meta['olt_provisioned_at'] ?? null)) {
            return true;
        }

        return DeviceActionLog::query()
            ->where('target_type', 'customer_migration')
            ->where('target_id', (string) $migration->id)
            ->where('action', 'migration-device-action-dry-run')
            ->whereIn('status', ['executed', 'verified', 'failed'])
            ->exists();
    }

    /**
     * True (and logged) when a guided re-fill must be refused. Throws when the
     * candidate is another customer than the one already on the migration.
     *
     * @param  array<string, mixed>  $candidate
     */
    public function refuseRefill(CustomerMigration $migration, array $candidate): bool
    {
        if (! $this->hasDeviceWrites($migration)) {
            return false;
        }
        Log::warning('[MIGRASI] guided refill refused: device writes exist', ['migration_id' => $migration->id]);
        $chosen = (int) ($candidate['customer_id'] ?? 0);
        if ($chosen > 0 && (int) $migration->customer_id > 0 && $chosen !== (int) $migration->customer_id) {
            throw new \RuntimeException('Migrasi ini sudah pernah dijalankan; pelanggan tidak bisa diganti.');
        }

        return true;
    }

    /** Reply for a re-sent message over a migration that already ran but has no step marker. */
    public function alreadyRunText(CustomerMigration $migration): string
    {
        return "Migrasi #{$migration->id} sudah pernah dijalankan; pesan ulang tidak dipakai.\nLanjutkan: #migrasi eksekusi #{$migration->id}\nCek: #migrasi cek #{$migration->id}";
    }

    /** The re-sent SN (normalized) when it differs from the migration SN, else null. */
    public function differentSn(CustomerMigration $migration, array $parsed): ?string
    {
        $resentSn = MigrationNormalizer::normalizeSerial((string) ($parsed['new_sn'] ?? ''));

        return $resentSn !== '' && $resentSn !== MigrationNormalizer::normalizeSerial((string) $migration->new_sn) ? $resentSn : null;
    }

    /**
     * A continuation must run on the OLT of the earlier execution of this
     * migration. Throws before any device write when the resolved OLT differs.
     *
     * @param  array<string, mixed>  $before  device action preflight
     */
    public function assertContinuationOlt(CustomerMigration $migration, DeviceActionLog $current, array $before): void
    {
        if (($before['continuation'] ?? false) !== true) {
            return;
        }
        $previous = DeviceActionLog::query()
            ->where('target_type', 'customer_migration')
            ->where('target_id', (string) $migration->id)
            ->where('action', 'migration-device-action-dry-run')
            ->whereIn('status', ['executed', 'verified', 'failed'])
            ->whereKeyNot($current->id)
            ->orderByDesc('id')
            ->first();
        $previousOltId = (int) (data_get($previous?->result, 'before.olt.id') ?? data_get($previous?->preflight, 'olt.id') ?? 0);
        $targetOltId = (int) data_get($before, 'olt.id', 0);
        if ($previousOltId > 0 && $targetOltId > 0 && $previousOltId !== $targetOltId) {
            throw new \RuntimeException('OLT tujuan berbeda dari eksekusi sebelumnya; lanjutan migrasi ditahan aman. Cek data migrasi dulu.');
        }
    }
}
