<?php

namespace App\Services;

use App\Models\Olt;
use App\Support\OnuLiveState;
use App\Support\SensitiveDataRedactor;
use Illuminate\Support\Facades\Log;

/**
 * Read-only live ONU read used by every OLT ONU delete path (device-recovery
 * cleanup + olt:delete-duplicate-onus). ZTE uses detail-info (physical serial
 * + phase) with a port-table cross-check; Huawei uses its onuDetail().
 */
class OnuLiveReadService
{
    /**
     * ok=false when the read failed or the result is ambiguous (transport
     * error, %Error, several interfaces, ...). absent=true only when the ONU
     * is clearly gone.
     *
     * @return array{ok: bool, absent: bool, serial: ?string, state: string, label: string}
     */
    public function read(Olt $olt, string $onuRef): array
    {
        try {
            if ($this->isHuawei($olt)) {
                $r = OnuLiveState::fromHuaweiDetail((array) app(HuaweiOltOnlyProvisioningService::class)->onuDetail($olt, $onuRef));
            } else {
                $r = OnuLiveState::fromZteProbe((array) app(OltZteC300Service::class)->liveOnuProbe($olt, $onuRef), $onuRef);
            }
        } catch (\Throwable $e) {
            Log::warning('[onu-live-read] failed', [
                'olt_id' => $olt->id,
                'onu_ref' => $onuRef,
                'error' => SensitiveDataRedactor::redact($e->getMessage()),
            ]);

            return ['ok' => false, 'absent' => false, 'serial' => null, 'state' => OnuLiveState::UNKNOWN, 'label' => 'error'];
        }

        if ($r['description'] !== null && $r['serial'] !== null && OnuLiveState::normalizeSerial($r['description']) !== $r['serial']) {
            Log::info('[onu-live-read] description differs from physical serial; using Serial number', [
                'olt_id' => $olt->id,
                'onu_ref' => $onuRef,
                'serial' => $r['serial'],
                'description' => $r['description'],
            ]);
        }

        return [
            'ok' => $r['status'] !== OnuLiveState::UNKNOWN,
            'absent' => $r['status'] === OnuLiveState::ABSENT,
            'serial' => $r['serial'],
            'state' => $r['state'],
            'label' => $r['label'],
        ];
    }

    private function isHuawei(Olt $olt): bool
    {
        $vendor = strtolower(trim((string) ($olt->vendor ?? '')));

        return str_contains($vendor, 'huawei') || str_contains($vendor, 'ma5608');
    }
}
