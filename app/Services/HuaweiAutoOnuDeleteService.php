<?php

namespace App\Services;

use App\Exceptions\HuaweiOltSessionBusyException;
use App\Http\Controllers\OltController;
use App\Models\Olt;
use App\Models\OltOperationLog;
use App\Models\Setting;
use App\Models\User;
use App\Support\OltResponseSanitizer;
use App\Support\OpsAlertSource;
use App\Support\SensitiveDataRedactor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Thin adapter that lets the unattended / operator auto-delete paths
 * (device-recovery + olt:delete-duplicate-onus) use the Huawei service-port
 * first delete sequence (HuaweiOnuMaintenanceService::deleteOnuWithServicePorts)
 * instead of the legacy bare `ont delete`.
 *
 * Gating:
 *  - the unattended DeleteDismantledOnuJob path needs the owner flag
 *    `huawei_olt_auto_delete_enabled` (default OFF) AND device-recovery
 *    mode=write;
 *  - an operator-initiated CLI run (--execute) does NOT need that flag
 *    (still needs mode=write, the kill switch / dry-run gate inside the
 *    maintenance service, live OFFLINE proof, SN pin and the OLT locks).
 *
 * This adapter never takes OltOperationLock: every caller already holds it
 * (the Cache lock is not re-entrant).
 */
class HuaweiAutoOnuDeleteService
{
    public const SOURCE_DEVICE_RECOVERY = 'device_recovery';

    public const SOURCE_DEVICE_RECOVERY_RETRY = 'device_recovery_retry';

    public const SOURCE_DUPLICATE_CLEANUP = 'duplicate_cleanup';

    public const FLAG = 'huawei_olt_auto_delete_enabled';

    private const ALERT_DEDUPE_SECONDS = 86400;

    public static function isHuawei(Olt $olt): bool
    {
        $vendor = strtolower(trim((string) ($olt->vendor ?? '')));

        return str_contains($vendor, 'huawei') || str_contains($vendor, 'ma5608');
    }

    public static function flagOn(): bool
    {
        return filter_var(Setting::get(self::FLAG, false), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * True when the service-port sequence must be used instead of the legacy
     * delete. False = caller keeps the legacy call unchanged.
     */
    public function enabled(Olt $olt, bool $operatorInitiated = false): bool
    {
        if (! self::isHuawei($olt)) {
            return false;
        }
        if ((string) config('device-recovery.mode', 'read_only') !== 'write') {
            return false;
        }

        return $operatorInitiated || self::flagOn();
    }

    /**
     * Read-only service-port preview for dry-runs. Never throws.
     *
     * @return array{ok: bool, count: int, indexes: list<int>, error: ?string}
     */
    public function preview(Olt $olt, string $onuRef): array
    {
        try {
            return app(HuaweiOnuMaintenanceService::class)->previewServicePorts($olt, $onuRef);
        } catch (\Throwable) {
            return ['ok' => false, 'count' => 0, 'indexes' => [], 'error' => 'preview_failed'];
        }
    }

    /**
     * Runs the service-port delete. Callers hold the OLT lock and perform
     * their OWN live read-back afterwards.
     *
     * @return array<string, mixed> result without raw outputs, plus path=service_port
     *                              and (partial failure only) _wa=[message, dedupeKey]
     *
     * @throws HuaweiOltSessionBusyException nothing was sent to the OLT
     */
    public function delete(
        Olt $olt,
        string $onuRef,
        string $serial,
        string $source,
        ?User $actor = null,
        ?int $caseId = null,
    ): array {
        // HuaweiOltSessionBusyException propagates untouched (raised before any command).
        $result = app(HuaweiOnuMaintenanceService::class)->deleteOnuWithServicePorts(
            $olt,
            $onuRef,
            $serial,
            allowOnline: false,
            operation: 'auto_'.$source,
        );

        unset($result['outputs']);
        $result['path'] = 'service_port';
        $result['onu_ref'] = (string) ($result['onu_ref'] ?? $onuRef);

        if (($result['save_pending'] ?? false) === true) {
            try {
                Cache::put(OltController::huaweiSavePendingKey($olt), now()->toISOString(), now()->addDays(30));
            } catch (\Throwable $e) {
                Log::warning('[huawei-auto-delete] save-pending marker failed', ['olt_id' => $olt->id, 'exception_class' => $e::class]);
            }
        }

        $this->audit($olt, $onuRef, $serial, $source, $actor, $caseId, $result);

        if (($result['partial'] ?? false) === true && ($result['success'] ?? false) !== true) {
            $hint = array_map('strval', (array) ($result['rebuild_hint'] ?? []));
            $removedCount = count((array) ($result['removed_service_ports'] ?? []));
            $message = $removedCount === 0
                ? sprintf(
                    'Hapus ONU otomatis Huawei SEBAGIAN: %s %s SN %s. Status service-port tidak pasti (perintah undo mungkin sudah terkirim), cek OLT (%s).',
                    (string) ($olt->name ?? '-'),
                    $result['onu_ref'],
                    $serial,
                    (string) ($result['error_code'] ?? 'unknown'),
                )
                : sprintf(
                    'Hapus ONU otomatis Huawei SEBAGIAN: %s %s SN %s. %d service-port sudah dihapus, ONU masih terdaftar (%s). Periksa/kembalikan: %s',
                    (string) ($olt->name ?? '-'),
                    $result['onu_ref'],
                    $serial,
                    $removedCount,
                    (string) ($result['error_code'] ?? 'unknown'),
                    $hint === [] ? '-' : implode(' | ', $hint),
                );
            $result['_wa'] = [$message, 'huawei-auto-delete-partial:'.$olt->id.':'.$result['onu_ref']];
        }

        return $result;
    }

    /**
     * Sends the partial-failure ops WA. Call AFTER the OLT lock is released.
     *
     * @param  mixed  $wa  [message, dedupeKey] or anything else (ignored)
     */
    public static function sendAlert(mixed $wa): void
    {
        if (! is_array($wa) || count($wa) < 2) {
            return;
        }

        try {
            app(OpsAlertService::class)->send((string) $wa[0], (string) $wa[1], self::ALERT_DEDUPE_SECONDS, '', OpsAlertSource::HUAWEI_ONU_DELETE);
        } catch (\Throwable $e) {
            Log::warning('[huawei-auto-delete] partial alert failed', ['exception_class' => $e::class]);
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function audit(Olt $olt, string $onuRef, string $serial, string $source, ?User $actor, ?int $caseId, array $result): void
    {
        $success = ($result['success'] ?? false) === true;

        try {
            $sanitizer = app(OltResponseSanitizer::class);
            OltOperationLog::create([
                'olt_id' => $olt->id,
                'user_id' => $actor?->id,
                'operation' => 'auto_delete_onu:'.$source,
                'onu_ref' => $result['onu_ref'],
                'status' => $success ? 'success' : 'failed',
                'payload' => $sanitizer->sanitize(['onu_ref' => $onuRef, 'source' => $source, 'case_id' => $caseId]),
                'result' => $sanitizer->sanitize($result),
                'error' => $success ? null : SensitiveDataRedactor::redact((string) ($result['message'] ?? $result['error_code'] ?? 'failed')),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[huawei-auto-delete] operation audit deferred', ['olt_id' => $olt->id, 'exception_class' => $e::class]);
        }

        if (($result['device_write'] ?? false) !== true && ($result['already_absent'] ?? false) !== true) {
            return;
        }

        try {
            activity()
                ->causedBy($actor)
                ->performedOn($olt)
                ->event('olt_onu_huawei_auto_delete')
                ->withProperties([
                    'source' => $source,
                    'onu_ref' => $result['onu_ref'],
                    'serial' => $serial,
                    'success' => $success,
                    'error_code' => $result['error_code'] ?? null,
                    'partial' => (bool) ($result['partial'] ?? false),
                    'removed_service_ports' => $result['removed_service_ports'] ?? [],
                    'rebuild_hint' => $result['rebuild_hint'] ?? [],
                    'save_pending' => (bool) ($result['save_pending'] ?? false),
                    'already_absent' => (bool) ($result['already_absent'] ?? false),
                    'case_id' => $caseId,
                ])
                ->log('Hapus ONU Huawei otomatis '.$result['onu_ref'].' di '.($olt->name ?? '-'));
        } catch (\Throwable $e) {
            Log::warning('[huawei-auto-delete] activity log failed', ['olt_id' => $olt->id, 'exception_class' => $e::class]);
        }
    }
}
