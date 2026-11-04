<?php

namespace App\Services\Migration;

use App\Models\CustomerMigration;
use App\Models\Setting;
use App\Services\HuaweiOltOnlyProvisioningService;
use App\Support\SensitiveDataRedactor;
use Illuminate\Support\Str;

/**
 * Pure helpers of the migration flow (no state, no device call). Moved out of
 * CustomerMigrationService without a change of behaviour; the facade keeps
 * one-line delegators for the names that still have callers there.
 */
final class MigrationNormalizer
{
    /**
     * @return array<string, mixed>
     */
    public static function meta(CustomerMigration $migration): array
    {
        return is_array($migration->meta) ? $migration->meta : [];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    public static function appendTimeline(mixed $timeline, string $step, array $data = []): array
    {
        $items = is_array($timeline) ? array_values($timeline) : [];
        $items[] = [
            'step' => $step,
            'status' => 'pending_review',
            'at' => now()->toIso8601String(),
            'data' => $data,
        ];

        return array_slice($items, -20);
    }

    public static function boundedError(\Throwable|string $error): string
    {
        $message = $error instanceof \Throwable ? $error->getMessage() : $error;
        $message = SensitiveDataRedactor::redact($message);
        $message = preg_replace('/,\s*SQL:\s.*$/s', '', trim($message)) ?: trim($message);

        return Str::limit(preg_replace('/\s+/', ' ', $message) ?: $message, 2000, '');
    }

    public static function boolSetting(string $key, bool $default): bool
    {
        $value = Setting::get($key, $default);
        if (is_bool($value)) {
            return $value;
        }
        if (is_array($value)) {
            $value = $value[0] ?? $default;
        }

        return in_array(Str::lower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    public static function migrationAlreadySynced(CustomerMigration $migration): bool
    {
        return $migration->status === CustomerMigration::STATUS_SYNCED || $migration->synced_at !== null;
    }

    public static function isHuaweiOltOnlyMigration(CustomerMigration $migration): bool
    {
        return (string) data_get($migration->meta, 'migration_operation_mode') === HuaweiOltOnlyProvisioningService::MODE;
    }

    public static function normalizeUsername(string $value): string
    {
        return trim(preg_replace('/[^\w.\-]+/u', '', $value) ?? $value);
    }

    public static function normalizeSerial(string $value): string
    {
        $serial = strtoupper(preg_replace('/[^A-Z0-9]/i', '', trim($value)) ?? '');

        return $serial === 'TIDAKTERBACA' ? '' : $serial;
    }

    public static function normalizeTextForCompare(string $value): string
    {
        $value = Str::lower(trim($value));
        $value = preg_replace('/[^a-z0-9.]+/i', '', $value) ?? $value;

        return $value;
    }

    public static function normalizeOnuRef(string $value): ?string
    {
        $ref = strtolower(trim($value));
        $ref = preg_replace('/^gpon-onu_/i', '', $ref) ?? $ref;

        return preg_match('/^\d+\/\d+\/\d+:\d+$/', $ref) ? $ref : null;
    }

    public static function normalizePonPort(string $value): ?string
    {
        $port = strtolower(trim($value));
        $port = preg_replace('/^gpon-olt_/i', '', $port) ?? $port;
        if (preg_match('/^(\d+\/\d+\/\d+)(?::\d+)?$/', $port, $m)) {
            return $m[1];
        }

        return null;
    }

    public static function rwNumber(string $value): ?string
    {
        return preg_match('/(\d{1,3})/', $value, $m) ? str_pad((string) ((int) $m[1]), 2, '0', STR_PAD_LEFT) : null;
    }

    public static function onuNameToken(string $value): string
    {
        return strtoupper((string) preg_split('/[_\s-]+/', trim($value), 2)[0]);
    }

    public static function normalizeOltToken(string $value): string
    {
        return strtolower(preg_replace('/[^a-z0-9]+/i', '', $value) ?? '');
    }

    public static function normalizeSiteToken(string $value): string
    {
        return strtolower(preg_replace('/[^a-z0-9]+/i', '', $value) ?? '');
    }

    public static function odpSuffix(string $odp): string
    {
        if (preg_match('/([A-Z]?\d+(?:\.\d+)?)\s*$/i', trim($odp), $m)) {
            return strtoupper($m[1]);
        }

        return '';
    }
}
