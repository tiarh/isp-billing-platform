<?php

namespace App\Services\Psb;

use App\Exceptions\PsbLockBusyException;
use App\Exceptions\PsbOltLockBusyException;
use App\Jobs\ResumePsbDraftJob;
use App\Models\Olt;
use App\Models\Package;
use App\Models\PsbDraft;
use App\Models\Router;
use App\Models\Setting;
use App\Services\PsbDocumentGateService;
use App\Services\PsbOrchestratorService;
use App\Services\PsbSiteResolver;
use App\Services\PsbWaMessages;
use App\Support\CacheLockWait;
use App\Support\SensitiveDataRedactor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * PSB provisioning conductor (moved verbatim from PsbOrchestratorService): the draft
 * lock, the site/router/package gates, the HTB and full_olt paths, the OLT-busy defer,
 * resume and give-up, and the SN poller.
 *
 * Built by the facade (one per orchestrator instance = per job), never by the container,
 * no statics. The resume token and the poller flag are private state of this instance.
 * Every re-entry (resume, poll) goes through the host's public processDraft, so the
 * Huawei-aware override still runs for Gadang. The entry is named process() on purpose.
 */
final class PsbProvisioningConductor
{
    /** Set only while ResumePsbDraftJob drives processDraft (token guard). */
    private ?string $oltResumeToken = null;

    /** True while pollWaitingSn drives processDraft: lock contention stays quiet (log only). */
    private bool $pollerRun = false;

    public function __construct(
        private readonly PsbOrchestratorService $host,
        private readonly PsbWaMessages $messages,
        private readonly PsbDocumentGateService $documentGate,
        private readonly PsbConfigResolver $config,
        private readonly PsbWaReplySender $replySender,
        private readonly PsbOltBusyMarker $oltBusy,
        private readonly PsbSecretProvisioner $secrets,
        private readonly PsbOltProvisioner $olt,
        private readonly PsbConductorSeams $seams,
    ) {}

    public function process(PsbDraft $draft): PsbDraft
    {
        $groupJid = (string) $draft->group_jid;

        // Koreksi menunggu approval: provisioning hanya lewat applyPsbCorrection.
        if ($draft->status === PsbDraft::STATUS_CORRECTION_PENDING) {
            Log::info('[PSB] processDraft skip correction pending', ['id' => $draft->id]);

            return $draft;
        }

        // Sudah final — jangan sentuh perangkat lagi
        if (in_array($draft->status, [PsbDraft::STATUS_APPLIED, PsbDraft::STATUS_OLT_OK], true)
            && $draft->olt_at
            && $draft->secret_at
            && $draft->pppoe_user
        ) {
            Log::info('[PSB] processDraft skip already complete', ['id' => $draft->id, 'status' => $draft->status]);

            return $draft;
        }

        if (! ($this->seams->hasMinPhoto)($draft)) {
            $gate = $this->documentGate->evaluate($draft);
            $draft->status = PsbDraft::STATUS_RECEIVED;
            $draft->last_error = 'Dokumen PSB belum valid: '.($gate['status'] ?? 'missing');
            $meta = is_array($draft->meta) ? $draft->meta : [];
            $meta['waiting_photo'] = true;
            $draft->meta = $meta;
            $draft->save();
            if ($groupJid !== '') {
                $this->host->reply($groupJid, $this->messages->documentBlocked($draft, $gate));
            }

            return $draft;
        }

        // Kill-switch ops (Setting) — default on
        if (! $this->config->writesEnabled()) {
            $draft->last_error = 'PSB write disabled (psb_writes_enabled=0)';
            $draft->save();
            $this->host->reply($groupJid, "⚠️ PSB write ke MT/OLT sedang *dimatikan* (mode aman).\nNOC: set `psb_writes_enabled` di settings.");

            return $draft;
        }
        // HTB: secret_ok + secret_at = complete (no OLT)
        if ($draft->status === PsbDraft::STATUS_SECRET_OK
            && $draft->secret_at
            && $draft->pppoe_user
            && in_array((string) $draft->activation_mode, [PsbSiteResolver::MODE_PPPOE_ONLY, PsbSiteResolver::MODE_PPPOE_VLAN], true)
        ) {
            Log::info('[PSB] processDraft skip HTB complete', ['id' => $draft->id]);

            return $draft;
        }

        // Lock per draft — cegah double process paralel (junk secret/ONU)
        ($this->seams->setDraftLockBusy)(false);
        $draftLock = Cache::lock('psb-draft-'.$draft->id, 300);
        if (! CacheLockWait::acquire($draftLock, 5)) {
            return $this->draftLockBusyOutcome($draft, $groupJid);
        }

        try {
            return $this->processDraftLocked($draft->fresh() ?? $draft, $groupJid);
        } finally {
            $draftLock->release();
        }
    }

    /** True inside a ResumePsbDraftJob run (#487 auto-resume). */
    private function inOltResumeRun(): bool
    {
        return $this->oltResumeToken !== null;
    }

    /** True while the SN poller drives processDraft (lock contention stays quiet). */
    public function inPollerRun(): bool
    {
        return $this->pollerRun;
    }

    /**
     * The per-draft lock stayed busy. Resume run: hand back to ResumePsbDraftJob
     * (same re-dispatch as before). Poller: log only. Otherwise one reply and the
     * draft is returned unchanged.
     */
    public function draftLockBusyOutcome(PsbDraft $draft, string $groupJid): PsbDraft
    {
        Log::warning('[PSB] draft busy', ['id' => $draft->id]);

        if ($this->inOltResumeRun()) {
            throw new PsbLockBusyException('Draft PSB sedang dipakai proses lain.');
        }

        ($this->seams->setDraftLockBusy)(true);
        if (! $this->inPollerRun() && $groupJid !== '') {
            $this->host->reply($groupJid, $this->messages->draftBusy((int) $draft->id));
        }

        return $draft;
    }

    /**
     * The router PPPoE/IP queue (or address allocator) was busy before any
     * device write: keep the status (NOT failed), record why, one reply.
     */
    public function secretQueueBusy(PsbDraft $draft, string $groupJid, Router $router): PsbDraft
    {
        $draft->last_error = "Antrian PPPoE/IP router {$router->name} sibuk; belum ada perubahan. Kirim #psb lanjut.";
        $draft->save();
        Log::warning('[PSB] secret queue busy', ['draft' => $draft->id, 'router' => $router->id]);

        if (! $this->inPollerRun() && $groupJid !== '') {
            $this->host->reply($groupJid, $this->messages->secretQueueBusy((int) $draft->id, (string) $router->name));
        }

        return $draft;
    }

    private function processDraftLocked(PsbDraft $draft, string $groupJid): PsbDraft
    {
        // Resume-token guard: a ResumePsbDraftJob run only proceeds while its
        // token is still the current one (lanjut / newer job may have won).
        if ($this->oltResumeTokenStale($draft)) {
            return $draft;
        }

        // Re-check after lock
        if ($draft->status === PsbDraft::STATUS_CANCELLED) {
            Log::info('[PSB] skip cancelled draft', ['id' => $draft->id]);

            return $draft;
        }
        if (in_array($draft->status, [PsbDraft::STATUS_APPLIED], true)) {
            return $draft;
        }
        if ($draft->status === PsbDraft::STATUS_OLT_OK && $draft->olt_at && $draft->pppoe_user) {
            return $draft;
        }
        // HTB complete: secret_ok is terminal success (no OLT write)
        $modeEarly = (string) ($draft->activation_mode ?: '');
        if ($modeEarly !== '' && $modeEarly !== PsbSiteResolver::MODE_FULL_OLT
            && $draft->status === PsbDraft::STATUS_SECRET_OK && $draft->secret_at && $draft->pppoe_user) {
            return $draft;
        }

        if (! ($this->seams->hasMinPhoto)($draft)) {
            $gate = $this->documentGate->evaluate($draft);
            $draft->status = PsbDraft::STATUS_RECEIVED;
            $draft->last_error = 'Dokumen PSB belum valid: '.($gate['status'] ?? 'missing');
            $draft->save();
            $this->host->reply($groupJid, $this->messages->documentBlocked($draft, $gate));

            return $draft;
        }

        // Sprint 2: resolve site from daerah (multi-router + HTB modes)
        $site = ($this->seams->applySiteToDraft)($draft);
        // Guard salah-target: daerah tidak dikenal + tanpa Router: → JANGAN default RA diam-diam
        if (! empty($site['ambiguous']) && $this->config->psbBlockUnknownSite()) {
            $draft->status = PsbDraft::STATUS_FAILED;
            $draft->last_error = 'Daerah `'.(string) $draft->daerah.'` belum terdaftar di registry site PSB (anti salah router).';
            $draft->save();
            $this->host->reply($groupJid,
                "❌ Daerah `{$draft->daerah}` belum terdaftar di sistem, jadi TIDAK diproses otomatis (mencegah secret dibuat di router salah).\n\n".
                "Cara lanjut:\n1. Tambahkan baris `Router: <alias>` di pesan #psb (contoh alias: ".$this->config->registryAliasExamples().") lalu kirim ulang, ATAU\n".
                "2. Minta NOC daftarkan site/daerah baru ini dulu di eBilling.\n\n".
                'Setelah itu balas: #psb lanjut #'.$draft->id
            );

            return $draft;
        }
        // No area (unknown/ambiguous locality) -> stop before any router/OLT/ACS write.
        if ($this->blockIfAreaMissing($draft, $groupJid, (array) data_get($draft->meta, 'area_resolution', []))) {
            return $draft;
        }
        $mode = (string) ($site['mode'] ?? PsbSiteResolver::MODE_FULL_OLT);

        $package = $draft->package_id ? Package::find($draft->package_id) : null;
        // Forced router from form "Router:" / #psb router x always wins
        $router = $draft->router_id ? Router::find($draft->router_id) : ($site['router'] ?? null);
        $olt = $site['olt'] ?? ($draft->olt_id ? Olt::find($draft->olt_id) : null);

        // full_olt: OLT fallback dari settings; router hanya fallback jika form Router: kosong (harusnya sudah wajib)
        if ($mode === PsbSiteResolver::MODE_FULL_OLT) {
            $cfgRouter = $this->host->resolveRouter();
            $cfgOlt = $this->host->resolveOlt();
            if (! $router) {
                $router = $cfgRouter;
            }
            if (! $olt) {
                $olt = $cfgOlt;
            }
            if ($cfgOlt && empty($site['olt']) && empty($draft->olt_id)) {
                $olt = $cfgOlt;
            }
        }

        if (! $router || ! $package) {
            $draft->status = PsbDraft::STATUS_FAILED;
            $miss = [];
            if (! $router) {
                $miss[] = 'Router (contoh: ra / krian / bumiayu)';
            }
            if (! $package) {
                $miss[] = 'Paket (contoh: 200M / STARTLITE / 100M)';
            }
            $draft->last_error = 'Config PSB belum lengkap: '.implode(', ', $miss);
            $draft->save();
            $this->host->reply($groupJid, $this->messages->incomplete($miss));

            return $draft;
        }
        $packageMappingError = $this->config->packageMappingError(
            is_array($draft->meta) ? (string) ($draft->meta['package_hint'] ?? '') : '',
            $package
        );
        if ($packageMappingError !== null) {
            $draft->status = PsbDraft::STATUS_FAILED;
            $draft->last_error = $packageMappingError;
            $draft->save();
            $this->host->reply($groupJid, $this->messages->incomplete([$packageMappingError]));

            return $draft;
        }
        if ($mode === PsbSiteResolver::MODE_FULL_OLT && ! $olt) {
            $draft->status = PsbDraft::STATUS_FAILED;
            $draft->last_error = 'Config PSB full_olt: OLT belum di-set.';
            $draft->save();
            $this->host->reply($groupJid, $this->messages->configIncomplete());

            return $draft;
        }

        $draft->router_id = $router->id;
        if ($olt) {
            $draft->olt_id = $olt->id;
        }
        $draft->package_id = $package->id;
        // Always align secret profile to chosen package (source of truth for MT rate limit)
        $draft->mikrotik_profile = trim((string) $package->mikrotik_profile);
        $draft->activation_mode = $mode;
        $draft->save();

        // ─── HTB path: secret only (± VLAN), NO FiberHome provision ───
        if ($mode === PsbSiteResolver::MODE_PPPOE_ONLY || $mode === PsbSiteResolver::MODE_PPPOE_VLAN) {
            return $this->processDraftHtb($draft, $groupJid, $router, $package, $olt, $site);
        }

        // ─── full_olt path (legacy FiberHome @ C300) ───
        // Detect SN on uncfg (READ only)
        $detect = $this->olt->findUncfgForDraft($draft, $olt);

        // P6: SN chat ≠ uncfg (single ONU) — minta konfirmasi, jangan auto write
        if (! empty($detect['sn_mismatch'])) {
            $meta = is_array($draft->meta) ? $draft->meta : [];
            $meta['pending_uncfg_sn'] = $detect['uncfg_sn'] ?? null;
            $meta['pending_uncfg_port'] = $detect['port'] ?? null;
            $meta['pending_uncfg_onu_id'] = $detect['onu_id'] ?? null;
            $draft->meta = $meta;
            $draft->status = PsbDraft::STATUS_WAITING_SN;
            $draft->sn_wait_until = now()->addMinutes(30);
            $draft->last_error = 'SN mismatch: chat='.($detect['chat_sn'] ?? '?').' uncfg='.($detect['uncfg_sn'] ?? '?');
            $draft->pon_port = $detect['port'] ?? $draft->pon_port;
            $draft->save();
            // Throttle spam mismatch (max 1 balasan / 12 mnt per draft)
            $mKey = 'psb-sn-mismatch-reply:'.$draft->id;
            if (! Cache::has($mKey)) {
                Cache::put($mKey, 1, now()->addMinutes(12));
                $this->host->reply($groupJid, $this->messages->snMismatch(
                    $draft,
                    (string) ($detect['chat_sn'] ?? $draft->sn ?? ''),
                    (string) ($detect['uncfg_sn'] ?? ''),
                    (string) ($detect['port'] ?? '?')
                ));
            }

            return $draft;
        }

        // The SN is no longer a mismatch: drop a stale "#psb ya" confirmation. The
        // branches below save the draft.
        $staleMeta = is_array($draft->meta) ? $draft->meta : [];
        if (array_key_exists('pending_uncfg_sn', $staleMeta) || array_key_exists('pending_uncfg_port', $staleMeta) || array_key_exists('pending_uncfg_onu_id', $staleMeta)) {
            unset($staleMeta['pending_uncfg_sn'], $staleMeta['pending_uncfg_port'], $staleMeta['pending_uncfg_onu_id']);
            $draft->meta = $staleMeta;
        }

        if (($detect['block_reason'] ?? null) === 'vlan_mapping_missing') {
            $draft->status = PsbDraft::STATUS_PENDING_VLAN;
            $draft->sn = ($detect['sn'] ?? '') ?: $draft->sn;
            $draft->pon_port = $detect['port'] ?? $draft->pon_port;
            $draft->vlan = null;
            $draft->sn_wait_until = null;
            $draft->last_error = $detect['message'] ?? 'VLAN mapping belum tersedia';
            $draft->save();
            $this->host->reply($groupJid, $this->messages->pendingVlan($draft));

            return $draft;
        }

        if (! ($detect['found'] ?? false)) {
            $draft->status = PsbDraft::STATUS_WAITING_SN;
            $draft->sn_wait_until = now()->addMinutes(15);
            $draft->last_error = $detect['message'] ?? 'SN belum di uncfg';
            $draft->save();
            $this->host->reply($groupJid, $this->messages->waitingSn($draft));

            return $draft;
        }

        $draft->sn = $detect['sn'];
        $draft->pon_port = $detect['port'];
        $draft->onu_id = $detect['onu_id'] ?? null;
        $vlan = $detect['vlan'] ?? null;

        if (! $vlan) {
            $draft->status = PsbDraft::STATUS_PENDING_VLAN;
            $draft->vlan = null;
            $draft->last_error = "PON {$draft->pon_port} belum ada mapping VLAN";
            $draft->save();
            $this->host->reply($groupJid, $this->messages->pendingVlan($draft));

            return $draft;
        }

        $draft->vlan = (int) $vlan;
        $draft->save();

        // ── MT: buat secret HANYA jika belum pernah sukses (anti re-allocate IP / junk) ──
        $needSecret = empty($draft->secret_at) || empty($draft->pppoe_user);
        if ($needSecret) {
            try {
                $existed = $this->secrets->createSecretAndDraftCustomer($draft, $router, $package, $olt);
            } catch (PsbLockBusyException) {
                // Router PPPoE/IP queue or allocator busy before any device write:
                // keep status (not FAILED), no WA job release.
                return $this->secretQueueBusy($draft, $groupJid, $router);
            } catch (\Throwable $e) {
                $msg = SensitiveDataRedactor::redact($e->getMessage());
                $draft->status = PsbDraft::STATUS_FAILED;
                $draft->last_error = $msg;
                $draft->save();
                if ($this->secrets->isIpPoolError($msg)) {
                    $this->host->reply($groupJid, $this->messages->ipPoolFull($msg));
                } else {
                    $this->host->reply($groupJid, $this->messages->pppoeFailed($msg));
                }

                return $draft;
            }

            $draft = $draft->fresh() ?? $draft;
            $routerCredential = ($this->seams->readVerifiedPppoePassword)($draft);
            [$mPrefix, $mJids] = $this->replySender->mentionPrefixForDraft($draft);
            if ($existed) {
                $this->host->reply($groupJid, $mPrefix.$this->messages->secretAlreadyExists($draft, $routerCredential), $mJids);
            } else {
                $this->host->reply($groupJid, $mPrefix.$this->messages->message1PppoeReady($draft, $routerCredential), $mJids);
            }
        }

        // ── OLT: skip jika sudah provision sukses ──
        if ($draft->olt_at && $draft->status === PsbDraft::STATUS_OLT_OK) {
            return $draft;
        }

        // Precondition ketat sebelum touch OLT
        if (empty($draft->sn) || empty($draft->pon_port) || empty($draft->vlan) || empty($draft->pppoe_user)) {
            $draft->status = PsbDraft::STATUS_FAILED;
            $draft->last_error = 'Precondition OLT gagal: sn/pon/vlan/pppoe_user kosong';
            $draft->save();
            $this->host->reply($groupJid, $this->messages->pppoeFailed($draft->last_error));

            return $draft;
        }

        try {
            $this->olt->provisionOlt($draft, $olt, $router);
        } catch (PsbLockBusyException $e) {
            throw $e; // auto-resume: JANGAN turunkan status / set last_error untuk kontensi lock
        } catch (PsbOltLockBusyException $e) {
            return $this->deferOltAfterBusyLock($draft, $groupJid, $e);
        } catch (\Throwable $e) {
            $draft->status = PsbDraft::STATUS_SECRET_OK;
            $error = SensitiveDataRedactor::redact($e->getMessage());
            $draft->last_error = 'OLT: '.$error;
            $this->oltBusy->clearOltBusyMarker($draft);
            $draft->save();
            $this->host->reply($groupJid, $this->messages->oltFailedAfterSecret($error));

            return $draft;
        }

        $draft->status = PsbDraft::STATUS_OLT_OK;
        $draft->olt_at = now();
        $draft->last_error = null;
        $meta = is_array($draft->meta) ? $draft->meta : [];
        $meta['olt_provisioned_at'] = now()->toIso8601String();
        unset($meta['olt_busy_resume']);
        $draft->meta = $meta;
        $draft->save();
        [$mPrefix, $mJids] = $this->replySender->mentionPrefixForDraft($draft);
        $routerCredential = ($this->seams->readVerifiedPppoePassword)($draft);
        $this->host->reply($groupJid, $mPrefix.$this->messages->message2OltDone($draft, $this->host->acsUrl(), $routerCredential), $mJids);

        return $draft;
    }

    /**
     * HTB / HiOSO: PPPoE on site MikroTik only. Never call provisionFiberhome.
     *
     * @param  array<string, mixed>  $site
     */
    private function processDraftHtb(
        PsbDraft $draft,
        string $groupJid,
        Router $router,
        Package $package,
        ?Olt $olt,
        array $site
    ): PsbDraft {
        $mode = (string) ($site['mode'] ?? PsbSiteResolver::MODE_PPPOE_ONLY);

        // VLAN gate (only if still required after soft-skip)
        if ($mode === PsbSiteResolver::MODE_PPPOE_VLAN && empty($draft->vlan) && ! empty($site['vlan_required'])) {
            $draft->status = PsbDraft::STATUS_PENDING_VLAN;
            $draft->last_error = 'Site HTB butuh VLAN service. Isi di Aktivasi→VLAN MikroTik atau kirim: #psb vlan NN #'.$draft->id;
            $draft->save();
            $this->host->reply($groupJid, $this->messages->pendingVlanHtb($draft));

            return $draft;
        }

        // SN from chat is enough (no uncfg). Soft warn if missing — still allow secret.
        if (empty($draft->sn)) {
            Log::info('[PSB] HTB without SN', ['draft' => $draft->id, 'mode' => $mode]);
        }

        $needSecret = empty($draft->secret_at) || empty($draft->pppoe_user);
        if ($needSecret) {
            try {
                $existed = $this->secrets->createSecretAndDraftCustomer($draft, $router, $package, $olt);
            } catch (PsbLockBusyException) {
                // Router PPPoE/IP queue or allocator busy before any device write:
                // keep status (not FAILED), no WA job release.
                return $this->secretQueueBusy($draft, $groupJid, $router);
            } catch (\Throwable $e) {
                $msg = SensitiveDataRedactor::redact($e->getMessage());
                $draft->status = PsbDraft::STATUS_FAILED;
                $draft->last_error = $msg;
                $draft->save();
                if ($this->secrets->isIpPoolError($msg)) {
                    $this->host->reply($groupJid, $this->messages->ipPoolFull($msg));
                } else {
                    $this->host->reply($groupJid, $this->messages->pppoeFailed($msg));
                }

                return $draft;
            }

            $draft = $draft->fresh() ?? $draft;
            $routerCredential = ($this->seams->readVerifiedPppoePassword)($draft);
            [$mPrefix, $mJids] = $this->replySender->mentionPrefixForDraft($draft);
            if ($existed) {
                $this->host->reply($groupJid, $mPrefix.$this->messages->secretAlreadyExists($draft, $routerCredential), $mJids);
            } else {
                $this->host->reply($groupJid, $mPrefix.$this->messages->message1PppoeReady($draft, $routerCredential), $mJids);
            }
        }

        // Terminal success for HTB = secret_ok (KPI counts this mode)
        $draft->status = PsbDraft::STATUS_SECRET_OK;
        $draft->last_error = null;
        $meta = is_array($draft->meta) ? $draft->meta : [];
        $meta['htb_complete'] = true;
        $meta['htb_mode'] = $mode;
        $meta['htb_completed_at'] = now()->toIso8601String();
        $draft->meta = $meta;
        $draft->save();

        [$mPrefix, $mJids] = $this->replySender->mentionPrefixForDraft($draft);
        $routerCredential = ($this->seams->readVerifiedPppoePassword)($draft);
        $this->host->reply($groupJid, $mPrefix.$this->messages->htbComplete($draft, $router->name, $routerCredential), $mJids);

        return $draft;
    }

    public function pollWaitingSn(): int
    {
        $n = 0;
        // Limit batch: aman untuk OLT (jangan flood uncfg/provision)
        $drafts = PsbDraft::query()
            ->where('status', PsbDraft::STATUS_WAITING_SN)
            ->where(function ($q) {
                $q->whereNull('sn_wait_until')->orWhere('sn_wait_until', '>=', now());
            })
            ->orderBy('id')
            ->limit(5)
            ->get();

        foreach ($drafts as $draft) {
            $n++;
            $this->pollerRun = true;
            try {
                $this->host->processDraft($draft);
            } catch (\Throwable $e) {
                Log::warning('[PSB] poll failed', ['id' => $draft->id, 'error' => $e->getMessage()]);
            } finally {
                $this->pollerRun = false;
            }
            // Jeda singkat antar draft — kurangi tekanan OLT/MT
            usleep(250000);
        }

        // Timeout
        PsbDraft::query()
            ->where('status', PsbDraft::STATUS_WAITING_SN)
            ->whereNotNull('sn_wait_until')
            ->where('sn_wait_until', '<', now())
            ->each(function (PsbDraft $d) {
                $d->status = PsbDraft::STATUS_TIMEOUT;
                $d->last_error = 'Timeout menunggu SN di uncfg';
                $d->save();
                if ($d->group_jid) {
                    $this->host->reply((string) $d->group_jid, $this->messages->snTimeout($d));
                }
            });

        return $n;
    }

    /**
     * Guard run BEFORE any OLT/router/GenieACS write: no usable area -> FAILED "Perlu area".
     *
     * @param  array{status: string, ...}  $resolution
     */
    public function blockIfAreaMissing(PsbDraft $draft, string $groupJid, array $resolution): bool
    {
        if (($resolution['area_id'] ?? null) !== null) {
            return false;
        }

        $draft->status = PsbDraft::STATUS_FAILED;
        $draft->last_error = 'Perlu area: '.(($resolution['status'] ?? '') === 'ambiguous'
            ? 'daerah `'.(string) $draft->daerah.'` cocok dengan lebih dari satu area.'
            : 'daerah `'.(string) $draft->daerah.'` belum punya area.');
        $draft->save();
        if ($groupJid !== '') {
            $this->host->reply($groupJid, $this->messages->areaNeeded(
                $draft,
                ($resolution['status'] ?? '') === 'ambiguous',
                $draft->secret_at === null
            ));
        }

        return true;
    }

    /** Kill switch (default on): off = busy OLT ends like before, with a clear detail. */
    private function oltBusyResumeEnabled(): bool
    {
        $v = Setting::get('psb_olt_busy_resume_enabled', '1');
        if (is_bool($v)) {
            return $v;
        }

        return ! in_array(strtolower(trim((string) $v)), ['0', 'false', 'off', 'no'], true);
    }

    /**
     * True when the current run is a ResumePsbDraftJob run whose token is no
     * longer current (superseded by lanjut / a newer job) or the draft is no
     * longer waiting for the OLT.
     */
    public function oltResumeTokenStale(PsbDraft $draft): bool
    {
        return $this->oltBusy->oltResumeTokenStale($draft, $this->oltResumeToken);
    }

    /**
     * OLT/SN lock stayed busy (called under the draft lock): keep PPPoE as is,
     * save a resume marker and queue an automatic retry. Gives up after
     * OLT_BUSY_MAX_ATTEMPTS automatic retries or when the kill switch is off.
     */
    public function deferOltAfterBusyLock(PsbDraft $draft, string $groupJid, PsbOltLockBusyException $e): PsbDraft
    {
        $meta = is_array($draft->meta) ? $draft->meta : [];
        $prev = $this->oltBusy->oltBusyMarker($draft);
        $now = now();

        ['first' => $first, 'live' => $live, 'done' => $done, 'next' => $next] = $this->oltBusy->chain($prev, $now);

        // A resume run never starts a fresh chain: expired chain => give up.
        if ($this->oltResumeToken !== null && ! $live) {
            return $this->giveUpLocked($draft, $groupJid, (int) ($prev['attempt'] ?? 0), $e->vendor);
        }

        if (! $this->oltBusyResumeEnabled() || $next > PsbOrchestratorService::OLT_BUSY_MAX_ATTEMPTS) {
            return $this->giveUpLocked($draft, $groupJid, $done, $e->vendor);
        }

        $token = (string) Str::uuid();
        $delay = $this->oltBusy->backoffSeconds($next);
        $meta['olt_busy_resume'] = [
            'token' => $token,
            'stage' => 'olt',
            'scope' => $e->scope,
            'vendor' => $e->vendor,
            'attempt' => $next,
            'first_at' => $first->toIso8601String(),
            'next_at' => $now->copy()->addSeconds($delay)->toIso8601String(),
        ];
        $draft->meta = $meta;
        $draft->status = PsbDraft::STATUS_SECRET_OK;
        $draft->last_error = sprintf(
            'OLT antri: menunggu giliran OLT (otomatis, percobaan %d/%d)',
            $next,
            PsbOrchestratorService::OLT_BUSY_MAX_ATTEMPTS
        );
        $draft->save();

        ResumePsbDraftJob::dispatch((int) $draft->id, $token, $next)->delay($delay);

        Log::info('[PSB] olt busy deferred', [
            'draft' => $draft->id,
            'scope' => $e->scope,
            'vendor' => $e->vendor,
            'attempt' => $next,
            'delay' => $delay,
        ]);

        if ($next === 1 && $groupJid !== '') {
            $this->host->reply($groupJid, $this->messages->oltBusyQueued($draft));
        }

        return $draft;
    }

    /**
     * Today's end state for a failed OLT step (secret_ok + OLT error + reply)
     * with a clear detail instead of an empty one. Caller holds the draft lock.
     */
    private function giveUpLocked(PsbDraft $draft, string $groupJid, int $attempts, string $vendor): PsbDraft
    {
        $detail = $attempts > 0
            ? sprintf('Antrian OLT masih sibuk setelah %d kali coba otomatis.', $attempts)
            : 'Antrian OLT sedang sibuk.';

        $draft->status = PsbDraft::STATUS_SECRET_OK;
        $draft->last_error = ($vendor === 'huawei' ? 'OLT Huawei: ' : 'OLT: ').$detail;
        $this->oltBusy->clearOltBusyMarker($draft);
        $draft->save();

        Log::warning('[PSB] olt busy gave up', ['draft' => $draft->id, 'vendor' => $vendor, 'attempts' => $attempts]);

        if ($groupJid !== '') {
            $this->host->reply($groupJid, $this->messages->oltFailedAfterSecret($detail));
        }

        return $draft;
    }

    /**
     * Run by ResumePsbDraftJob: an automatic "#psb lanjut" that bypasses
     * handleInbound (no dedupe). Does nothing unless the token is current.
     * May throw LockTimeoutException when the draft lock is busy.
     */
    public function resumeDraftAfterOltBusy(int $draftId, string $token): ?PsbDraft
    {
        $draft = PsbDraft::query()->find($draftId);
        if (! $draft) {
            return null;
        }
        $marker = $this->oltBusy->oltBusyMarker($draft);
        if (($marker['token'] ?? null) !== $token || ! $this->oltBusy->oltBusyResumable($draft)) {
            Log::info('[PSB] olt busy resume noop', ['draft' => $draftId, 'status' => $draft->status]);

            return null;
        }

        $this->oltResumeToken = $token;
        try {
            return $this->host->processDraft($draft);
        } finally {
            $this->oltResumeToken = null;
        }
    }

    /**
     * Gives up a pending OLT-busy resume (job failed / sweeper). Never waits
     * for the draft lock; returns false when it cannot act safely.
     */
    public function giveUpOltBusyResume(int $draftId, string $token, string $reason): bool
    {
        $draft = PsbDraft::query()->find($draftId);
        if (! $draft) {
            return false;
        }

        $lock = Cache::lock('psb-draft-'.$draftId, 300);
        if (! $lock->get()) {
            return false;
        }

        try {
            $draft = $draft->fresh() ?? $draft;
            $marker = $this->oltBusy->oltBusyMarker($draft);
            if (($marker['token'] ?? null) !== $token
                || $draft->status !== PsbDraft::STATUS_SECRET_OK
                || $draft->olt_at !== null) {
                return false;
            }

            Log::warning('[PSB] olt busy resume given up', ['draft' => $draftId, 'reason' => $reason]);
            $this->giveUpLocked(
                $draft,
                (string) $draft->group_jid,
                max(0, (int) ($marker['attempt'] ?? 1) - 1),
                (string) ($marker['vendor'] ?? 'fiberhome')
            );

            return true;
        } finally {
            $lock->release();
        }
    }
}
