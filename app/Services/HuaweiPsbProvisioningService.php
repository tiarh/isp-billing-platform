<?php

namespace App\Services;

use App\Exceptions\HuaweiOltSessionBusyException;
use App\Exceptions\PsbOltLockBusyException;
use App\Models\Olt;
use App\Models\PsbDraft;
use App\Models\Setting;
use App\Services\Olt\OltProvisioningGuard;
use App\Support\CacheLockWait;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * PSB adapter for Huawei MA5608T.
 *
 * This is intentionally separate from both the C300/FiberHome PSB path and
 * the Huawei migration path. Discovery is read-only; device writes are still
 * controlled by the shared OLT provisioning guard and a PSB-specific switch.
 */
final class HuaweiPsbProvisioningService
{
    public function __construct(
        private HuaweiOltOnlyProvisioningService $huawei,
    ) {}

    /**
     * Discover a new ONT placement without changing the OLT.
     *
     * @return array<string, mixed>
     */
    public function preflight(PsbDraft $draft, Olt $olt): array
    {
        $base = [
            'eligible' => false,
            'reason' => null,
            'olt' => ['id' => (int) $olt->id, 'name' => (string) $olt->name],
            'source' => 'huawei_ma5608t_psb_read_only',
        ];

        if (! $this->isHuawei($olt)) {
            $base['reason'] = 'target_olt_is_not_huawei';

            return $base;
        }

        $serial = $this->normalize($draft->sn);
        if ($serial === '' || preg_match('/^[A-Z0-9-]{8,64}$/', $serial) !== 1) {
            $base['reason'] = 'sn_invalid';

            return $base;
        }

        try {
            $autofind = $this->huawei->collectAutofind($olt);
            $matches = array_values(array_filter(
                $autofind['matches'] ?? [],
                fn (array $row): bool => $this->normalize($row['serial_number'] ?? '') === $serial,
            ));

            if ($matches === []) {
                $snapshot = $this->huawei->collectSnapshot($olt);
                $registered = $this->registeredSerialRows($snapshot, $serial);
                if ($registered !== []) {
                    return $this->resumeRegistered($base, $draft, $serial, $registered[0]);
                }
                $base['reason'] = 'sn_not_found_on_autofind';

                return $base;
            }
            if (count($matches) > 1) {
                $base['reason'] = 'sn_found_on_multiple_pons';

                return $base;
            }

            $match = $matches[0];
            $pon = $this->normalizePon((string) ($match['pon_port'] ?? ''));
            if ($pon === null) {
                $base['reason'] = 'autofind_pon_invalid';

                return $base;
            }

            $hint = $this->normalizePon((string) ($draft->pon_port ?? ''));
            if ($hint !== null && $hint !== $pon) {
                $base['reason'] = 'pon_hint_mismatch';
                $base['detected_pon'] = $pon;
                $base['hint_pon'] = $hint;

                return $base;
            }

            // A serial already registered anywhere on this OLT must not be
            // silently attached to a second service-port.
            $snapshot = $this->huawei->collectSnapshot($olt);
            $registered = $this->registeredSerialRows($snapshot, $serial);
            if ($registered !== []) {
                return $this->resumeRegistered($base, $draft, $serial, $registered[0]);
            }

            $portOnus = $this->huawei->collectPortOnus($olt, $pon);
            $onuId = $this->huawei->nextOnuId((array) ($portOnus['onus'] ?? []), $pon);
            $profile = $this->huawei->profileFor(
                $pon,
                $serial,
                isset($match['vendor_id']) ? (string) $match['vendor_id'] : null,
            );
            $result = array_merge($base, [
                'eligible' => true,
                'placement' => [
                    'pon_port' => $pon,
                    'onu_id' => $onuId,
                    'onu_ref' => "{$pon}:{$onuId}",
                    'serial_number' => $serial,
                    'vendor_id' => $match['vendor_id'] ?? null,
                    'source' => 'autofind_then_targeted_pon_readback',
                    'autofind_command' => $autofind['command'] ?? 'display ont autofind all',
                    'occupancy_command' => $portOnus['command'] ?? null,
                ],
                'profile' => $profile,
            ]);
            if (trim((string) $draft->pppoe_user) !== '') {
                $result['plan'] = $this->huawei->buildPlan(
                    $serial,
                    (string) $draft->pppoe_user,
                    $pon,
                    $onuId,
                    isset($match['vendor_id']) ? (string) $match['vendor_id'] : null,
                    $profile['vlan'],
                    $profile['tr069_vlan'],
                );
            }

            return $result;
        } catch (HuaweiOltSessionBusyException) {
            // Read-only discovery only; nothing was written. #487 auto-resume.
            throw new PsbOltLockBusyException(HuaweiOltSessionBusyException::MESSAGE, 'session', 'huawei');
        } catch (RuntimeException $exception) {
            $base['reason'] = 'huawei_preflight_rejected';
            $base['error_class'] = $exception::class;

            return $base;
        } catch (\Throwable $exception) {
            $base['reason'] = 'huawei_preflight_failed';
            $base['error_class'] = $exception::class;

            return $base;
        }
    }

    /**
     * Execute a fresh, locked preflight and then the approved Huawei plan.
     * No write occurs when the PSB switch or shared dry-run guard is closed.
     *
     * @return array<string, mixed>
     */
    public function provision(PsbDraft $draft, Olt $olt): array
    {
        $lock = Cache::lock('psb-huawei-olt-'.$olt->id, 300);
        if (! CacheLockWait::acquire($lock, $this->lockWaitSeconds())) {
            throw new PsbOltLockBusyException('Antrian PSB Huawei sedang diproses.', 'olt', 'huawei');
        }

        try {
            $snLock = Cache::lock('psb-huawei-sn-'.$this->normalize($draft->sn), 300);
            if (! CacheLockWait::acquire($snLock, $this->lockWaitSeconds())) {
                throw new PsbOltLockBusyException('SN sedang diproses job PSB lain.', 'sn', 'huawei');
            }

            try {
                $preflight = $this->preflight($draft, $olt);
                if (($preflight['eligible'] ?? false) !== true) {
                    throw new RuntimeException('Huawei PSB preflight: '.(string) ($preflight['reason'] ?? 'rejected'));
                }

                if (! isset($preflight['plan']) || ! is_array($preflight['plan'])) {
                    throw new RuntimeException('PPPoE user Huawei belum tersedia untuk description ONT.');
                }
                $plan = $preflight['plan'];
                if (! $this->writesEnabled()) {
                    return [
                        'success' => false,
                        'device_write' => false,
                        'blocked' => 'psb_huawei_olt_writes_disabled',
                        'preflight' => $preflight,
                    ];
                }

                $approval = app(OltProvisioningGuard::class)->issueInternalApproval(
                    $olt,
                    'psb_wa_huawei',
                    'draft-'.$draft->id,
                );
                try {
                    $result = $this->huawei->executePlan($olt, $plan, $approval);
                } catch (HuaweiOltSessionBusyException) {
                    // executePlan only throws this raw on the FIRST connect, i.e.
                    // before any command was sent; later phases surface as a
                    // HuaweiOltCommandException (manual reconciliation, unchanged).
                    throw new PsbOltLockBusyException(HuaweiOltSessionBusyException::MESSAGE, 'session', 'huawei');
                }

                return [
                    'success' => ($result['verified'] ?? false) === true,
                    'device_write' => ($result['device_write'] ?? false) === true,
                    'preflight' => $preflight,
                    'result' => $result,
                ];
            } finally {
                $snLock->release();
            }
        } finally {
            $lock->release();
        }
    }

    /** Seconds to wait for the OLT/SN locks before reporting busy. */
    private function lockWaitSeconds(): int
    {
        return max(0, (int) config('psb.olt_lock_wait_seconds', 10));
    }

    private function writesEnabled(): bool
    {
        // Huawei PSB capability is enabled by default; the shared OLT
        // dry-run/kill-switch/approval guard still decides whether a device
        // write is actually permitted.
        $value = Setting::get('psb_huawei_olt_writes_enabled', '1');
        $enabled = is_bool($value) ? $value : in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);

        return $enabled && ! (bool) config('olt-provisioning.dry_run', true);
    }

    private function isHuawei(Olt $olt): bool
    {
        $vendor = strtolower(trim((string) $olt->vendor));

        return str_contains($vendor, 'huawei') || str_contains($vendor, 'ma5608');
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return list<array<string, mixed>>
     */
    private function registeredSerialRows(array $snapshot, string $serial): array
    {
        return array_values(array_filter(
            (array) ($snapshot['onus'] ?? []),
            fn (array $onu): bool => $this->normalize($onu['serial_number'] ?? '') === $serial,
        ));
    }

    /**
     * Resume only a placement previously recorded on this PSB draft. An
     * unrelated existing serial remains blocked to avoid an implicit attach.
     *
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $registered
     * @return array<string, mixed>
     */
    private function resumeRegistered(array $base, PsbDraft $draft, string $serial, array $registered): array
    {
        $meta = is_array($draft->meta) ? $draft->meta : [];
        $previous = is_array($meta['huawei_psb_preflight'] ?? null) ? $meta['huawei_psb_preflight'] : [];
        $pon = $this->normalizePon((string) ($registered['pon_port'] ?? ''));
        $id = isset($registered['onu_id']) && is_numeric($registered['onu_id'])
            ? (int) $registered['onu_id']
            : $this->onuIdFromRef((string) ($registered['onu_ref'] ?? ''));
        $previousPon = $this->normalizePon((string) ($previous['pon_port'] ?? ''));
        $previousId = isset($previous['onu_id']) && is_numeric($previous['onu_id']) ? (int) $previous['onu_id'] : null;

        if ($pon === null || $id === null || $previousPon !== $pon || $previousId !== $id) {
            $base['reason'] = 'sn_already_registered';

            return $base;
        }

        $vendorId = (string) ($registered['vendor_id'] ?? '');
        $profile = $this->huawei->profileFor($pon, $serial, $vendorId);
        $result = array_merge($base, [
            'eligible' => true,
            'resume' => true,
            'placement' => [
                'pon_port' => $pon,
                'onu_id' => $id,
                'onu_ref' => "{$pon}:{$id}",
                'serial_number' => $serial,
                'vendor_id' => $registered['vendor_id'] ?? null,
                'source' => 'registered_olt_resume',
            ],
            'profile' => $profile,
        ]);
        if (trim((string) $draft->pppoe_user) !== '') {
            $result['plan'] = $this->huawei->buildPlan(
                $serial,
                (string) $draft->pppoe_user,
                $pon,
                $id,
                $vendorId,
                $profile['vlan'],
                $profile['tr069_vlan'],
                true,
            );
        }

        return $result;
    }

    private function onuIdFromRef(string $value): ?int
    {
        return preg_match('/:\s*(\d{1,3})\s*$/', trim($value), $match) === 1
            ? (int) $match[1]
            : null;
    }

    private function normalize(?string $value): string
    {
        return strtoupper(preg_replace('/\s+/', '', trim((string) $value)) ?? '');
    }

    private function normalizePon(string $value): ?string
    {
        if (preg_match('/^(?:0\/)?0\/([0-9]{1,2})$/', trim($value), $match) === 1) {
            return '0/0/'.$match[1];
        }
        if (preg_match('/^0\/0\/([0-9]{1,2})$/', trim($value), $match) === 1) {
            return '0/0/'.$match[1];
        }

        return null;
    }
}
