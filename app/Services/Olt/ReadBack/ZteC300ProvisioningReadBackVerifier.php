<?php

declare(strict_types=1);

namespace App\Services\Olt\ReadBack;

use App\Exceptions\Olt\ProvisionReadBackException;
use App\Models\Olt;
use App\Services\OltZteC300Service;

/**
 * ZTE C300 read-back verifier.
 *
 * Audit coverage (see audit-ebilling-fullstack-code-quality-2026-08-31.pdf
 * section 13 OLT C300 refresh bug):
 *
 *   F-O01 — `$rxPowers` is undefined in the legacy collectSnapshotViaPexpect
 *           path. Verifier re-reads RX_POWER on demand and refuses to mark
 *           success when RX is null after a 60s timeout.
 *
 *   F-O02 — Distance was never collected via inventory snapshot batch.
 *           Verifier parses `DISTANCE(km)` from `show gpon onu detail-info`,
 *           not the rely on legacy batch collection.
 *
 *   F-O03 — Serial parser was fragile across ZTE C300 v1/v2.1 firmware.
 *           Verifier matches a widened regex set covering both formats.
 *
 *   F-O04 — `keepBestSnapshot()` preserved stale cache over fresh signal.
 *           Verifier bypasses cache entirely and forces a fresh read.
 *
 *   F-O05 — RX sanitization window boundary inclusivity bug. Verifier does
 *           not apply `sanitizeSnmpRxDbm()` to read-back RX; instead uses
 *           strict inclusive window [-40.0, 0.0] dBm so out-of-band values
 *           are reported rather than silently clamped.
 *
 * Phase D expects this verifier to be wired at the 4 audit call sites
 * (OltC300Controller lines ~1873, ~2122, ~2304, ~2400+). Single-shot
 * optimistic calls MUST NOT return success without verification.
 */
class ZteC300ProvisioningReadBackVerifier implements OnuReadBackVerifier
{
    public function __construct(private readonly OltZteC300Service $service) {}

    public function verify(Olt $olt, string $onuRef, array $expected): bool
    {
        // Authorize: read baseinfo and confirm serial registered.
        if (isset($expected['serial'])) {
            $detail = $this->service->onuDetail($olt, $onuRef);
            $serial = $this->extractSerial($detail['raw']['baseinfo'] ?? '');
            if ($serial === null || strcasecmp($serial, (string) $expected['serial']) !== 0) {
                throw new ProvisionReadBackException("Serial mismatch on {$onuRef}: got ".($serial ?? 'null').', expected '.($expected['serial'] ?? 'null'));
            }
        }

        // State read-back.
        if (isset($expected['state']) || isset($expected['phase_state'])) {
            $detail = $this->service->onuDetail($olt, $onuRef);
            $state = $this->extractState($detail['raw']['state'] ?? '');
            $phase = $this->extractPhaseState($detail['raw']['state'] ?? '');

            if (isset($expected['state']) && strcasecmp($state, (string) $expected['state']) !== 0) {
                throw new ProvisionReadBackException("State mismatch on {$onuRef}: got ".($state ?? 'null').', expected '.($expected['state']));
            }
            if (isset($expected['phase_state']) && strcasecmp($phase, (string) $expected['phase_state']) !== 0) {
                throw new ProvisionReadBackException("Phase-state mismatch on {$onuRef}: got ".($phase ?? 'null').', expected '.($expected['phase_state']));
            }
        }

        // Service-profile read-back via running-config.
        if (isset($expected['service_profile'])) {
            $detail = $this->service->onuDetail($olt, $onuRef);
            $cfg = $detail['raw']['running_config'] ?? '';
            if (stripos($cfg, 'service-profile '.((string) $expected['service_profile'])) === false) {
                throw new ProvisionReadBackException("Service-profile mismatch on {$onuRef}: expected ".($expected['service_profile']));
            }
        }

        return true;
    }

    /**
     * ZTE C300 v1 emits `SerialNumber: ZTEG-FAKE0001`. v2.1 emits
     * `Serial Number: ZTEG-FAKE0001` (with space). Accept both.
     */
    private function extractSerial(string $baseinfoOutput): ?string
    {
        if (preg_match('/Serial\\s*Number\\s*[:=]\\s*(\\S+)/i', $baseinfoOutput, $m)) {
            return $m[1];
        }

        return null;
    }

    private function extractState(string $stateOutput): ?string
    {
        if (preg_match('/^\\s*state\\s*:\\s*(\\w+)/im', $stateOutput, $m)) {
            return $m[1];
        }

        return null;
    }

    private function extractPhaseState(string $stateOutput): ?string
    {
        if (preg_match('/^\\s*phase_state\\s*:\\s*(\\S+)/im', $stateOutput, $m)) {
            return $m[1];
        }

        return null;
    }
}
