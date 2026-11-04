<?php

namespace App\Services\Psb;

use App\Exceptions\PsbOltLockBusyException;
use App\Http\Controllers\OltC300Controller;
use App\Models\Olt;
use App\Models\PsbDraft;
use App\Models\Router;
use App\Models\Setting;
use App\Services\Olt\OltProvisioningGuard;
use App\Support\CacheLockWait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * PSB OLT edge for the FiberHome/C300 path: uncfg detection and provisioning
 * (moved verbatim from PsbOrchestratorService). The controller and the provisioning
 * guard are resolved from the container exactly as before. Built by the facade, never
 * by the container, no statics.
 */
final class PsbOltProvisioner
{
    public function __construct(private readonly PsbUncfgMatcher $matcher) {}

    /**
     * @return array{found: bool, sn?: string, port?: string, onu_id?: int|null, vlan?: int|null, message?: string}
     */
    public function findUncfgForDraft(PsbDraft $draft, Olt $olt): array
    {
        $ctrl = app(OltC300Controller::class);
        $req = Request::create('/api/olt-c300/list-uncfg', 'POST', ['olt_id' => $olt->id]);
        $res = $ctrl->listUncfg($req);
        $data = $res->getData(true);
        if (! empty($data['error'])) {
            return ['found' => false, 'message' => $data['user_hint'] ?? $data['error']];
        }

        $onus = $data['onus'] ?? [];

        return $this->matcher->match($draft, $olt, $onus);
    }

    public function provisionOlt(PsbDraft $draft, Olt $olt, Router $router): void
    {
        if (empty($draft->sn) || empty($draft->pon_port) || empty($draft->pppoe_user)) {
            throw new \RuntimeException('Provision OLT dibatalkan: SN/PON/user kosong (aman).');
        }

        // Idempotent: sudah pernah sukses di draft ini
        $meta = is_array($draft->meta) ? $draft->meta : [];
        if (! empty($meta['olt_provisioned_at']) && $draft->olt_at) {
            Log::info('[PSB] OLT skip — already provisioned', ['draft' => $draft->id, 'sn' => $draft->sn]);

            return;
        }

        $lock = Cache::lock('psb-olt-'.$olt->id, 200);
        if (! CacheLockWait::acquire($lock, $this->oltLockWaitSeconds())) {
            throw new PsbOltLockBusyException('Antrian OLT sibuk.', 'olt', 'fiberhome');
        }

        try {
            // Serial per-SN juga (anti 2 job provision SN sama)
            $snLock = Cache::lock('psb-olt-sn-'.strtoupper((string) $draft->sn), 200);
            if (! CacheLockWait::acquire($snLock, $this->oltLockWaitSeconds())) {
                throw new PsbOltLockBusyException('SN sedang di-provision job lain.', 'sn', 'fiberhome');
            }

            try {
                $ctrl = app(OltC300Controller::class);
                // onu_id: JANGAN kirim index uncfg. Biarkan provisionFiberhome gap-fill (sama manual).
                // Hanya kirim onu_id jika re-provision (sudah sukses claim/provision sebelumnya).
                $provOnuId = null;
                if (! empty($meta['olt_provisioned_at']) && (int) ($draft->onu_id ?? 0) > 0) {
                    $provOnuId = (int) $draft->onu_id;
                }
                $req = Request::create('/api/olt-c300/provision-fiberhome', 'POST', array_filter([
                    'olt_id' => $olt->id,
                    'sn' => $draft->sn,
                    'port' => $draft->pon_port,
                    'onu_id' => $provOnuId,
                    'name' => $draft->pppoe_user,
                    'paket' => $this->oltPaketForDraft($draft),
                    'router_id' => $router->id,
                    // RA + Krian: selalu type ALL (bukan FiberHome) — sama PSB manual
                    'onu_type' => 'ALL',
                    'dry_run' => false,
                    'purpose' => 'psb_wa',
                ], fn ($v) => $v !== null && $v !== ''));
                $req->attributes->set(
                    'olt_provisioning_approval',
                    app(OltProvisioningGuard::class)->issueInternalApproval($olt, 'psb_wa', 'draft-'.$draft->id),
                );
                $res = $ctrl->provisionFiberhome($req);
                $data = $res->getData(true);
                $code = $res->getStatusCode();
                if ($code >= 400 || empty($data['success'])) {
                    throw new \RuntimeException($data['error'] ?? ('provision HTTP '.$code));
                }
                if (! empty($data['onu_id'])) {
                    $draft->onu_id = (int) $data['onu_id'];
                }
                if (! empty($data['vlan'])) {
                    $draft->vlan = (int) $data['vlan'];
                }
                $meta['olt_last_response'] = [
                    'ok' => true,
                    'at' => now()->toIso8601String(),
                    'onu_id' => $draft->onu_id,
                ];
                $draft->meta = $meta;
                $draft->save();
                Log::info('[PSB] OLT provision OK', ['draft' => $draft->id, 'sn' => $draft->sn, 'port' => $draft->pon_port]);
            } finally {
                $snLock->release();
            }
        } finally {
            $lock->release();
        }
    }

    /** Seconds to wait for the OLT/SN provisioning locks. */
    private function oltLockWaitSeconds(): int
    {
        return 10;
    }

    /** Paket OLT untuk provision — map dari profile via Setting, default aman 200M (perilaku lama). */
    private function oltPaketForDraft(PsbDraft $draft): string
    {
        $default = trim((string) Setting::get('psb_olt_paket_default', '200M')) ?: '200M';
        $profile = strtoupper(trim((string) ($draft->mikrotik_profile ?: '')));
        if ($profile === '') {
            return $default;
        }
        $map = Setting::get('psb_olt_paket_map', null);
        if (is_string($map)) {
            $map = json_decode($map, true);
        }
        if (is_array($map)) {
            foreach ($map as $k => $v) {
                if (strtoupper(trim((string) $k)) === $profile && trim((string) $v) !== '') {
                    return trim((string) $v);
                }
            }
        }
        if (! str_starts_with($profile, '200')) {
            Log::info('[PSB] OLT paket default dipakai untuk profile non-200M (set Setting psb_olt_paket_map untuk override)', [
                'draft' => $draft->id, 'profile' => $profile, 'paket' => $default,
            ]);
        }

        return $default;
    }
}
