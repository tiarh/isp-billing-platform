<?php

namespace App\Services;

use App\Exceptions\HuaweiOltCommandException;
use App\Exceptions\HuaweiOltSessionBusyException;
use App\Exceptions\PsbLockBusyException;
use App\Exceptions\PsbOltLockBusyException;
use App\Models\Olt;
use App\Models\Package;
use App\Models\PsbDraft;
use App\Models\Router;
use App\Models\Setting;
use App\Support\CacheLockWait;
use App\Support\SensitiveDataRedactor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Additive PSB seam for Gadang Huawei.
 *
 * The existing orchestrator remains the source of truth for every other
 * site. This subclass is selected by the container and delegates all
 * non-Gadang work to the parent unchanged.
 */
final class HuaweiAwarePsbOrchestratorService extends PsbOrchestratorService
{
    private const SITE_KEY = 'gadang_huawei';

    private const ROUTER_ID = 511;

    private const OLT_ID = 11;

    public function supportsHuaweiGadang(PsbDraft $draft): bool
    {
        if (! $this->flagEnabled('psb_huawei_gadang_enabled', true)) {
            return false;
        }

        if (strtolower(trim((string) $draft->site_profile_key)) === self::SITE_KEY) {
            return true;
        }

        if ((int) $draft->router_id === self::ROUTER_ID || (int) $draft->olt_id === self::OLT_ID) {
            return true;
        }

        $area = strtolower((string) ($draft->daerah ?? ''));

        return str_contains($area, 'gadang');
    }

    public function processDraft(PsbDraft $draft): PsbDraft
    {
        if ($draft->status === PsbDraft::STATUS_CORRECTION_PENDING) {
            return parent::processDraft($draft); // skips: waiting for correction approval
        }

        if (! $this->supportsHuaweiGadang($draft)) {
            return parent::processDraft($draft);
        }

        $groupJid = (string) $draft->group_jid;

        if (in_array($draft->status, [PsbDraft::STATUS_APPLIED, PsbDraft::STATUS_OLT_OK], true)
            && $draft->olt_at
            && $draft->secret_at
            && $draft->pppoe_user) {
            return $draft;
        }

        if (! $this->hasMinPhoto($draft)) {
            $gate = $this->documentGate->evaluate($draft);
            $draft->status = PsbDraft::STATUS_RECEIVED;
            $draft->last_error = 'Dokumen PSB belum valid: '.($gate['status'] ?? 'missing');
            $meta = is_array($draft->meta) ? $draft->meta : [];
            $meta['waiting_photo'] = true;
            $draft->meta = $meta;
            $draft->save();
            if ($groupJid !== '') {
                $this->reply($groupJid, $this->messages->documentBlocked($draft, $gate));
            }

            return $draft;
        }

        if (! $this->writesEnabled()) {
            $draft->last_error = 'PSB write disabled (psb_writes_enabled=0)';
            $draft->save();
            $this->reply($groupJid, "⚠️ PSB write ke MT/OLT sedang *dimatikan* (mode aman).\nNOC: set `psb_writes_enabled` di settings.");

            return $draft;
        }

        $this->draftLockBusy = false;
        $lock = Cache::lock('psb-draft-'.$draft->id, 300);
        if (! CacheLockWait::acquire($lock, 5)) {
            return $this->draftLockBusyOutcome($draft, $groupJid);
        }

        try {
            return $this->processHuaweiLocked($draft->fresh() ?? $draft, $groupJid);
        } finally {
            $lock->release();
        }
    }

    private function processHuaweiLocked(PsbDraft $draft, string $groupJid): PsbDraft
    {
        if ($draft->status === PsbDraft::STATUS_CANCELLED) {
            return $draft;
        }

        // Resume-token guard (ResumePsbDraftJob): superseded token => no-op.
        if ($this->oltResumeTokenStale($draft)) {
            return $draft;
        }

        $site = app(PsbSiteRegistry::class)->byKey(self::SITE_KEY);
        $router = $site['router'] ?? null;
        $olt = $site['olt'] ?? null;
        if (! $router instanceof Router || ! $olt instanceof Olt
            || (int) $router->id !== self::ROUTER_ID || (int) $olt->id !== self::OLT_ID) {
            return $this->fail($draft, $groupJid, 'Registry Gadang Huawei belum menunjuk Router #511 dan OLT #11.');
        }

        $package = $this->resolvePackageForDraft($draft, (int) $router->id);
        if (! $package) {
            return $this->fail($draft, $groupJid, 'Paket PSB Gadang belum terpetakan ke router target.');
        }

        $draft->router_id = $router->id;
        $draft->olt_id = $olt->id;
        $draft->site_profile_key = self::SITE_KEY;
        $draft->activation_mode = PsbSiteRegistry::MODE_HUAWEI_OLT;
        $draft->package_id = $package->id;
        $mappingError = $this->packageMappingError(
            is_array($draft->meta) ? (string) ($draft->meta['package_hint'] ?? '') : '',
            $package
        );
        if ($mappingError !== null) {
            return $this->fail($draft, $groupJid, $mappingError);
        }
        $draft->mikrotik_profile = trim((string) $package->mikrotik_profile);
        $draft->save();

        // Direct/retry calls can enter this seam without the normal inbound
        // resolver. Resolve the physical area from the submitted daerah after
        // the Huawei site key is fixed, so Gadang never inherits RA/Starlite.
        $resolution = $this->resolveArea($draft, $olt);
        // No area -> "Perlu area" before any OLT/router/ACS write.
        if ($this->blockIfAreaMissing($draft, $groupJid, $resolution)) {
            return $draft;
        }

        if (trim((string) $draft->sn) === '') {
            return $this->fail($draft, $groupJid, 'SN ONU wajib diisi untuk discovery Huawei.');
        }

        $adapter = app(HuaweiPsbProvisioningService::class);
        try {
            $preflight = $adapter->preflight($draft, $olt);
        } catch (PsbOltLockBusyException $exception) {
            // Huawei SSH session busy on a read-only discovery: nothing was sent.
            if (! empty($draft->secret_at) && ! empty($draft->pppoe_user)) {
                return $this->deferOltAfterBusyLock($draft, $groupJid, $exception);
            }

            return $this->huaweiBusyBeforeSecret($draft, $groupJid);
        }
        if (($preflight['eligible'] ?? false) !== true) {
            $reason = (string) ($preflight['reason'] ?? 'huawei_preflight_failed');
            $draft->status = $reason === 'sn_not_found_on_autofind'
                ? PsbDraft::STATUS_WAITING_SN
                : PsbDraft::STATUS_FAILED;
            $draft->last_error = 'Huawei PSB: '.$reason;
            $draft->save();
            $this->reply($groupJid, "❌ PSB Huawei #{$draft->id} berhenti aman: {$reason}. Tidak ada ont add/service-port dikirim.");

            return $draft;
        }

        $placement = (array) ($preflight['placement'] ?? []);
        $profile = (array) ($preflight['profile'] ?? []);
        $draft->pon_port = (string) ($placement['pon_port'] ?? $draft->pon_port);
        $draft->onu_id = isset($placement['onu_id']) ? (int) $placement['onu_id'] : null;
        $draft->vlan = isset($profile['vlan']) ? (int) $profile['vlan'] : null;
        $draft->tr069_vlan = isset($profile['tr069_vlan']) ? (int) $profile['tr069_vlan'] : 69;
        $meta = is_array($draft->meta) ? $draft->meta : [];
        $meta['huawei_psb_preflight'] = [
            'pon_port' => $draft->pon_port,
            'onu_id' => $draft->onu_id,
            'vlan' => $draft->vlan,
            'tr069_vlan' => $draft->tr069_vlan,
            'line_profile' => $profile['line_profile'] ?? null,
            'at' => now()->toIso8601String(),
        ];
        $draft->meta = $meta;
        $draft->save();

        if (empty($draft->secret_at) || empty($draft->pppoe_user)) {
            try {
                $this->createSecretAndDraftCustomer($draft, $router, $package, $olt);
                $draft = $draft->fresh() ?? $draft;
            } catch (PsbLockBusyException) {
                return $this->secretQueueBusy($draft, $groupJid, $router);
            } catch (\Throwable $exception) {
                return $this->fail($draft, $groupJid, SensitiveDataRedactor::redact($exception->getMessage()));
            }
        }

        try {
            $result = $adapter->provision($draft, $olt);
            if (($result['device_write'] ?? false) !== true || ($result['success'] ?? false) !== true) {
                $draft->status = PsbDraft::STATUS_SECRET_OK;
                $draft->last_error = 'Huawei OLT write belum aktif: '.(string) ($result['blocked'] ?? 'guard');
                $this->clearOltBusyMarker($draft);
                $draft->save();

                return $draft;
            }
        } catch (PsbOltLockBusyException $exception) {
            return $this->deferOltAfterBusyLock($draft, $groupJid, $exception);
        } catch (PsbLockBusyException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $draft->status = PsbDraft::STATUS_SECRET_OK;
            $draft->last_error = 'OLT Huawei: '.SensitiveDataRedactor::redact($exception->getMessage());
            $failure = $this->safeHuaweiFailureContext($exception);
            $meta = is_array($draft->meta) ? $draft->meta : [];
            $meta['huawei_psb_last_failure'] = $failure;
            unset($meta['olt_busy_resume']);
            $draft->meta = $meta;
            $draft->save();
            Log::warning('[PSB] Huawei OLT provision failed', [
                'draft_id' => $draft->id,
                ...$failure,
            ]);

            return $draft;
        }

        $draft->status = PsbDraft::STATUS_OLT_OK;
        $draft->olt_at = now();
        $draft->last_error = null;
        $meta = is_array($draft->meta) ? $draft->meta : [];
        $meta['huawei_psb_provisioned_at'] = now()->toIso8601String();
        unset($meta['olt_busy_resume']);
        $draft->meta = $meta;
        $draft->save();
        $routerCredential = $this->readVerifiedPppoePassword($draft);
        $this->reply($groupJid, $this->messages->huaweiPsbDone($draft, $routerCredential));

        return $draft;
    }

    /**
     * Huawei session busy before the PPPoE secret exists. The #487 auto-resume
     * marker needs status secret_ok (which would be false here), so keep the
     * draft state as is, never FAILED, and ask for a manual resend.
     */
    private function huaweiBusyBeforeSecret(PsbDraft $draft, string $groupJid): PsbDraft
    {
        $draft->last_error = 'OLT Huawei sibuk; belum ada perintah dikirim. Kirim #psb lanjut.';
        $this->clearOltBusyMarker($draft);
        $draft->save();
        Log::info('[PSB] huawei session busy before secret', ['draft' => $draft->id]);

        // psb:poll-sn retries waiting_sn drafts itself; stay quiet there.
        if (! $this->inPollerRun() && $groupJid !== '') {
            $this->reply($groupJid, HuaweiOltSessionBusyException::MESSAGE."\nDraft #{$draft->id}: kirim #psb lanjut #{$draft->id} +-1 menit lagi.");
        }

        return $draft;
    }

    /**
     * Keep enough bounded context to resume and diagnose a failed Huawei
     * command without persisting raw CLI output, passwords, or SN secrets.
     *
     * @return array{failure_type: string, failure_stage: string, command_index: ?int, command_label: ?string, cause_class: string}
     */
    private function safeHuaweiFailureContext(\Throwable $exception): array
    {
        if ($exception instanceof HuaweiOltCommandException) {
            return [
                'failure_type' => $exception->failureType,
                'failure_stage' => $exception->stage,
                'command_index' => $exception->commandIndex,
                'command_label' => $exception->commandLabel,
                'cause_class' => $exception->causeClass,
            ];
        }

        return [
            'failure_type' => 'unknown',
            'failure_stage' => 'provision',
            'command_index' => null,
            'command_label' => null,
            'cause_class' => class_basename($exception),
        ];
    }

    private function resolvePackageForDraft(PsbDraft $draft, int $routerId): ?Package
    {
        if ($draft->package_id && ($package = Package::find($draft->package_id))) {
            return $package;
        }

        $hint = is_array($draft->meta) ? trim((string) ($draft->meta['package_hint'] ?? '')) : '';

        return $hint !== '' ? $this->resolvePackageFromHint($hint, $routerId) : null;
    }

    private function fail(PsbDraft $draft, string $groupJid, string $reason): PsbDraft
    {
        $draft->status = PsbDraft::STATUS_FAILED;
        $draft->last_error = $reason;
        $this->clearOltBusyMarker($draft);
        $draft->save();
        if ($groupJid !== '') {
            $this->reply($groupJid, "❌ PSB Huawei #{$draft->id} berhenti aman: {$reason}");
        }

        return $draft;
    }

    private function flagEnabled(string $key, bool $default): bool
    {
        $value = Setting::get($key, $default ? '1' : '0');
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }
}
