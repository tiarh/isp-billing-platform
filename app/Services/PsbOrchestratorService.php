<?php

namespace App\Services;

use App\Contracts\PsbPppoePasswordPolicy;
use App\Enums\CustomerStatus;
use App\Exceptions\LockBusyException;
use App\Exceptions\PsbAlbumOcrWaitException;
use App\Exceptions\PsbLockBusyException;
use App\Exceptions\PsbOltLockBusyException;
use App\Jobs\ProcessPsbMediaOcrJob;
use App\Models\Customer;
use App\Models\Olt;
use App\Models\Package;
use App\Models\PsbDraft;
use App\Models\Router;
use App\Models\RouterProfile;
use App\Services\Psb\PsbAreaResolver;
use App\Services\Psb\PsbConductorSeams;
use App\Services\Psb\PsbConfigResolver;
use App\Services\Psb\PsbDraftIntake;
use App\Services\Psb\PsbLiveCorrection;
use App\Services\Psb\PsbOcrBatchState;
use App\Services\Psb\PsbOcrEngine;
use App\Services\Psb\PsbOcrReaders;
use App\Services\Psb\PsbOltBusyMarker;
use App\Services\Psb\PsbOltProvisioner;
use App\Services\Psb\PsbPhotoScorer;
use App\Services\Psb\PsbPppoeIpAllocator;
use App\Services\Psb\PsbPppoeNaming;
use App\Services\Psb\PsbProvisioningConductor;
use App\Services\Psb\PsbRecapStats;
use App\Services\Psb\PsbSeams;
use App\Services\Psb\PsbSecretProvisioner;
use App\Services\Psb\PsbUncfgMatcher;
use App\Services\Psb\PsbWaCommands;
use App\Services\Psb\PsbWaReplySender;
use App\Support\CacheLockWait;
use App\Support\OcrBudget;
use App\Support\PsbOcrBatchLedger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * WA-group #psb automation: draft + RA-OLT secret + FiberHome provision + 2 group replies.
 */
class PsbOrchestratorService
{
    /** Automatic OLT-busy resume: number of automatic retries before giving up. */
    public const OLT_BUSY_MAX_ATTEMPTS = 5;

    /** Seconds before automatic retry n (1-based); jitter 0-15 s is added. */
    public const OLT_BUSY_BACKOFF = [60, 90, 120, 180, 240];

    /** An OLT-busy resume chain stops being extended after this many minutes. */
    public const OLT_BUSY_CHAIN_MINUTES = 30;

    private bool $replyDeliveryFailed = false;

    /** @var array{batch_key:string,mode:string}|null Set only while continuing after async media OCR. */
    protected ?array $postOcr = null;

    /** Set when the last processDraft call found the draft lock busy (nothing was changed). */
    protected bool $draftLockBusy = false;

    public function __construct(
        protected EvolutionApiService $evolution,
        protected MikrotikService $mikrotik,
        protected PsbMessageParser $parser,
        protected PsbWaMessages $messages,
        protected KtpOcrService $ktpOcr,
        protected RadiusUserService $radius,
        protected PsbDocumentGateService $documentGate,
        protected ?PsbPppoePasswordPolicy $psbPasswordPolicy = null,
    ) {}

    private ?PsbConfigResolver $configResolver = null;

    private ?PsbPppoeNaming $pppoeNaming = null;

    private ?PsbOcrEngine $ocrEngine = null;

    private ?PsbRecapStats $recapStats = null;

    private ?PsbWaReplySender $replySender = null;

    private ?PsbDraftIntake $draftIntake = null;

    private ?PsbLiveCorrection $liveCorrection = null;

    private ?PsbOcrBatchState $ocrBatchState = null;

    private ?PsbWaCommands $waCommands = null;

    private ?PsbAreaResolver $areaResolver = null;

    private ?PsbPppoeIpAllocator $ipAllocator = null;

    private ?PsbOltBusyMarker $oltBusyMarker = null;

    private ?PsbUncfgMatcher $uncfgMatcher = null;

    private ?PsbProvisioningConductor $conductor = null;

    private ?PsbSecretProvisioner $secretProvisioner = null;

    private function areaResolver(): PsbAreaResolver
    {
        return $this->areaResolver ??= new PsbAreaResolver;
    }

    /** Same MikroTik instance as the facade; the allocator is called while connected, as before. */
    private function ipAllocator(): PsbPppoeIpAllocator
    {
        return $this->ipAllocator ??= new PsbPppoeIpAllocator($this->mikrotik);
    }

    private function oltBusy(): PsbOltBusyMarker
    {
        return $this->oltBusyMarker ??= new PsbOltBusyMarker;
    }

    private function uncfgMatcher(): PsbUncfgMatcher
    {
        return $this->uncfgMatcher ??= new PsbUncfgMatcher;
    }

    private function psbConfig(): PsbConfigResolver
    {
        return $this->configResolver ??= new PsbConfigResolver;
    }

    private function naming(): PsbPppoeNaming
    {
        return $this->pppoeNaming ??= new PsbPppoeNaming;
    }

    /** One engine per orchestrator instance (= per job). Never static, never in the container. */
    private function ocrEngine(): PsbOcrEngine
    {
        return $this->ocrEngine ??= new PsbOcrEngine($this->ktpOcr, new PsbPhotoScorer($this->ktpOcr), $this->ocrReaders());
    }

    /** OCR edges. The OCR worker adapter overrides this to use the installed Tesseract language packs. */
    protected function ocrReaders(): PsbOcrReaders
    {
        return new PsbOcrReaders(tesseractLanguage: static fn (): ?string => 'eng+ind');
    }

    private function recap(): PsbRecapStats
    {
        return $this->recapStats ??= new PsbRecapStats;
    }

    private function replySender(): PsbWaReplySender
    {
        return $this->replySender ??= new PsbWaReplySender($this->evolution, $this->psbConfig());
    }

    private function intake(): PsbDraftIntake
    {
        return $this->draftIntake ??= new PsbDraftIntake(
            fn (array $before): bool => $this->correction()->isLivePsbDraft($before),
            fn (PsbDraft $draft): array => $this->correction()->psbCorrectionSnapshot($draft),
        );
    }

    private function ocrBatch(): PsbOcrBatchState
    {
        return $this->ocrBatchState ??= new PsbOcrBatchState;
    }

    private function commands(): PsbWaCommands
    {
        return $this->waCommands ??= new PsbWaCommands(
            $this,
            $this->messages,
            $this->documentGate,
            $this->mikrotik,
            $this->intake(),
            $this->seams(),
        );
    }

    /** Late-bound closures to protected members, so overrides still win inside PsbWaCommands. */
    private function seams(): PsbSeams
    {
        return new PsbSeams(
            hasMinPhoto: fn (PsbDraft $draft): bool => $this->hasMinPhoto($draft),
            readVerifiedPppoePassword: fn (PsbDraft $draft): ?string => $this->readVerifiedPppoePassword($draft),
            siteResolver: fn (): PsbSiteResolver => $this->siteResolver(),
        );
    }

    /** Same MikroTik, allocator and naming as the facade; the password policy is resolved on first use. */
    private function secretProvisioner(): PsbSecretProvisioner
    {
        return $this->secretProvisioner ??= new PsbSecretProvisioner(
            $this->mikrotik,
            $this->radius,
            $this->ipAllocator(),
            $this->naming(),
            fn (): PsbPppoePasswordPolicy => $this->psbPasswordPolicy ??= app(PsbPppoePasswordPolicy::class),
        );
    }

    /** One conductor per orchestrator instance (= per job). Never static, never in the container. */
    private function conductor(): PsbProvisioningConductor
    {
        return $this->conductor ??= new PsbProvisioningConductor(
            $this,
            $this->messages,
            $this->documentGate,
            $this->psbConfig(),
            $this->replySender(),
            $this->oltBusy(),
            $this->secretProvisioner(),
            new PsbOltProvisioner($this->uncfgMatcher()),
            new PsbConductorSeams(
                hasMinPhoto: fn (PsbDraft $draft): bool => $this->hasMinPhoto($draft),
                readVerifiedPppoePassword: fn (PsbDraft $draft): ?string => $this->readVerifiedPppoePassword($draft),
                applySiteToDraft: fn (PsbDraft $draft): array => $this->applySiteToDraft($draft),
                setDraftLockBusy: function (bool $busy): void {
                    $this->draftLockBusy = $busy;
                },
            ),
        );
    }

    private function correction(): PsbLiveCorrection
    {
        return $this->liveCorrection ??= new PsbLiveCorrection(
            $this->mikrotik,
            fn (PsbDraft $draft): string => $this->naming()->buildPppoeUsername($draft),
            fn (string $error): string => $this->naming()->shortError($error),
            fn (mixed $phone): string => $this->intake()->normalizePsbPhone($phone),
        );
    }

    /**
     * Normalisasi JID grup WA — biar ganti grup di settings langsung cocok
     * dengan remoteJid dari webhook (tanpa redeploy, tanpa exact-string fragile).
     */
    public function normalizeGroupJid(string $jid): string
    {
        return $this->psbConfig()->normalizeGroupJid($jid);
    }

    /** @return list<string> */
    public function allowedGroupJids(): array
    {
        return $this->psbConfig()->allowedGroupJids();
    }

    public function isAllowedGroup(string $jid): bool
    {
        return $this->psbConfig()->isAllowedGroup($jid);
    }

    public function acsUrl(): string
    {
        return $this->psbConfig()->acsUrl();
    }

    /**
     * Read the verified PPPoE password from the live router for the
     * allowlisted activation group message only. The value stays in the
     * current call stack and is never persisted, queued, or logged.
     */
    protected function readVerifiedPppoePassword(PsbDraft $draft): ?string
    {
        if (! $this->isAllowedGroup((string) $draft->group_jid)) {
            return null;
        }

        $username = trim((string) $draft->pppoe_user);
        $router = $draft->router_id
            ? Router::query()->whereKey((int) $draft->router_id)->where('is_active', true)->first()
            : null;
        if ($username === '' || ! $router) {
            return null;
        }

        try {
            $this->mikrotik->connect($router, ['timeout' => 12, 'attempts' => 1]);
            $secret = $this->mikrotik->getPPPSecret($username);
            $routerCredential = trim((string) ($secret['password'] ?? ''));
            if ($routerCredential === '' || preg_match('/^\*+$/', $routerCredential) === 1) {
                return null;
            }

            return $routerCredential;
        } catch (\Throwable $e) {
            Log::warning('[PSB] activation credential read failed', [
                'draft_id' => $draft->id,
                'router_id' => $router->id,
                'username' => $username,
                'error_code' => 'router_password_unreadable',
            ]);

            return null;
        } finally {
            $this->mikrotik->disconnect();
        }
    }

    public function resolveRouter(): ?Router
    {
        return $this->psbConfig()->resolveRouter();
    }

    public function resolvePackage(): ?Package
    {
        return $this->psbConfig()->resolvePackage();
    }

    /**
     * Map chat "Paket: 200M|STARTLITE|100|…" → Package (mikrotik_profile for secret).
     * Prefer packages linked to the target MikroTik (router_id) when provided.
     */
    public function resolvePackageFromHint(string $hint, ?int $routerId = null): ?Package
    {
        return $this->psbConfig()->resolvePackageFromHint($hint, $routerId);
    }

    protected function packageMappingError(string $hint, Package $package): ?string
    {
        return $this->psbConfig()->packageMappingError($hint, $package);
    }

    /**
     * Apply wajib Router + Paket from #psb body (or alias already on parsed).
     *
     * @param  array<string, mixed>  $parsed
     * @return list<string> human-readable errors (empty = ok)
     */
    protected function applyRouterAndPackageFromParsed(PsbDraft $draft, array $parsed): array
    {
        $errors = [];
        $meta = is_array($draft->meta) ? $draft->meta : [];

        $alias = trim((string) ($parsed['router_field'] ?? $parsed['router_alias'] ?? ''));
        if ($alias === '' && ! empty($meta['router_alias'])) {
            $alias = trim((string) $meta['router_alias']);
        }
        if ($alias !== '') {
            $registrySite = app(PsbSiteRegistry::class)->byKey($alias);
            $router = $this->siteResolver()->resolveRouterAlias($alias);
            if (! $router) {
                $errors[] = "Router `{$alias}` tidak dikenali (contoh: ".$this->psbConfig()->registryAliasExamples().')';
            } else {
                $meta['forced_router_id'] = $router->id;
                $meta['router_alias'] = strtolower($alias);
                if ($registrySite) {
                    $meta['forced_site_key'] = (string) ($registrySite['key'] ?? '');
                }
                $draft->router_id = $router->id;
            }
        }

        $pkgHint = trim((string) ($parsed['package_field'] ?? ''));
        if ($pkgHint === '' && ! empty($meta['package_hint'])) {
            $pkgHint = trim((string) $meta['package_hint']);
        }
        if ($pkgHint !== '') {
            $routerId = (int) ($draft->router_id ?? $meta['forced_router_id'] ?? 0);
            $pkg = $this->resolvePackageFromHint($pkgHint, $routerId > 0 ? $routerId : null);
            if (! $pkg) {
                $profileExists = $routerId > 0 && class_exists(RouterProfile::class)
                    && RouterProfile::query()
                        ->where('router_id', $routerId)
                        ->where(function ($q) use ($pkgHint) {
                            $q->where('name', $pkgHint)
                                ->orWhereRaw('UPPER(name) = ?', [strtoupper($pkgHint)]);
                        })
                        ->exists();
                if ($profileExists) {
                    $errors[] = "Profile MT `{$pkgHint}` sudah ada di router, tapi mapping Paket Internet eBilling belum lengkap. Buka Router -> tab Paket PPP -> Sync Profile, lalu cek Paket Internet dan isi harga/nama paket.";
                } else {
                    $errors[] = "Paket/Profile `{$pkgHint}` belum ada di eBilling untuk router ini. Sync Profile MikroTik dulu dari halaman Router, atau buat Paket Internet dengan MikroTik Profile yang sama.";
                }
            } else {
                $draft->package_id = $pkg->id;
                $mappingError = $this->packageMappingError($pkgHint, $pkg);
                if ($mappingError !== null) {
                    $errors[] = $mappingError;
                } else {
                    $draft->mikrotik_profile = trim((string) $pkg->mikrotik_profile);
                }
                $meta['package_hint'] = $pkgHint;
                // Soft warn if profile name missing on target MT (secret may fail)
                if ($routerId > 0 && class_exists(RouterProfile::class)) {
                    $prof = (string) $draft->mikrotik_profile;
                    $hasProf = RouterProfile::query()
                        ->where('router_id', $routerId)
                        ->where(function ($q) use ($prof) {
                            $q->where('name', $prof)
                                ->orWhere('name', 'like', $prof.'%');
                        })
                        ->exists();
                    if (! $hasProf) {
                        $errors[] = "Profile MT `{$prof}` belum ada di router #{$routerId}. Buat PPP profile di MikroTik (nama sama) atau Paket pakai profile yang sudah ada (mis. 50MB / 100M), lalu sync.";
                    }
                }
            }
        }

        $draft->meta = $meta;

        return $errors;
    }

    public function resolveOlt(): ?Olt
    {
        return $this->psbConfig()->resolveOlt();
    }

    protected function siteResolver(): PsbSiteResolver
    {
        return app(PsbSiteResolver::class);
    }

    /**
     * Apply multi-site resolve (daerah → router/mode/vlan). Stores on draft.
     *
     * @return array{mode: string, router: ?Router, olt: ?Olt, vlan: ?int, vlan_required: bool, require_uncfg: bool, key: string, tr069_vlan?: ?int, mikrotik_profile: ?string}
     */
    protected function applySiteToDraft(PsbDraft $draft): array
    {
        $meta = is_array($draft->meta) ? $draft->meta : [];
        $forcedRouterId = isset($meta['forced_router_id']) ? (int) $meta['forced_router_id'] : null;
        $forcedVlan = $draft->vlan ? (int) $draft->vlan : (isset($meta['forced_vlan']) ? (int) $meta['forced_vlan'] : null);

        $forcedSiteKey = isset($meta['forced_site_key']) ? (string) $meta['forced_site_key'] : null;
        $site = $this->siteResolver()->resolve($draft->daerah, $forcedRouterId, $forcedVlan, $forcedSiteKey);

        $draft->activation_mode = $site['mode'];
        // Normalize site key for KPI (ra / krian not ra_full_olt)
        $siteKey = (string) ($site['key'] ?? 'unknown');
        if ($siteKey === 'krian_full_olt') {
            $siteKey = 'krian';
        } elseif ($siteKey === 'ra_full_olt' || $siteKey === 'default_full_olt') {
            $siteKey = 'ra';
        } elseif (str_starts_with($siteKey, 'htb_')) {
            $siteKey = substr($siteKey, 4);
        }
        $draft->site_profile_key = $siteKey;
        if ($site['router']) {
            $draft->router_id = $site['router']->id;
        }
        if ($site['olt']) {
            $draft->olt_id = $site['olt']->id;
        } elseif (($site['mode'] ?? '') !== PsbSiteResolver::MODE_FULL_OLT) {
            // HTB: OLT optional (inventory only)
            // keep existing olt_id if any
        }
        // Area is resolved after the meta below is saved (see resolveArea()).
        if ($site['vlan'] && empty($draft->vlan)) {
            $draft->vlan = $site['vlan'];
        }
        if (($site['tr069_vlan'] ?? null) && empty($draft->tr069_vlan)) {
            $draft->tr069_vlan = (int) $site['tr069_vlan'];
        }
        // Paket chat/package_id wins over router_vlans default profile
        if (! empty($site['mikrotik_profile']) && empty($draft->package_id) && empty($draft->mikrotik_profile)) {
            $draft->mikrotik_profile = $site['mikrotik_profile'];
        }
        $meta['site_resolve'] = [
            'key' => $site['key'],
            'mode' => $site['mode'],
            'router_id' => $site['router']?->id,
            'olt_id' => $site['olt']?->id,
            'vlan' => $site['vlan'],
            'tr069_vlan' => $site['tr069_vlan'] ?? null,
            'at' => now()->toIso8601String(),
        ];
        $draft->meta = $meta;
        $draft->save();

        // Area from locality / manual / (policy-gated) OLT area; never Starlite.
        $this->resolveArea($draft, $site['olt'] ?? null);

        return $site;
    }

    /**
     * @param  list<string>  $mentionJids
     */
    public function reply(string $groupJid, string $text, array $mentionJids = []): bool
    {
        if (! $this->replySender()->allowReplyMessage($groupJid, $text, $mentionJids)) {
            Log::info('[PSB] duplicate reply skipped', ['group_hash' => md5($this->normalizeGroupJid($groupJid))]);

            return true;
        }

        $sent = $this->replySender()->send($groupJid, $text, $mentionJids);
        if (! $sent) {
            $this->replyDeliveryFailed = true;
        }

        return $sent;
    }

    /** True when at least one reply in the current orchestration was not delivered. */
    public function hasReplyDeliveryFailure(): bool
    {
        return $this->replyDeliveryFailed;
    }

    /**
     * Entry from WA webhook / job. image_paths are relative paths on the local storage disk.
     *
     * @param  array{
     *   group_jid: string,
     *   sender_jid?: string,
     *   sender_name?: string,
     *   message_id?: string,
     *   text: string,
     *   image_paths?: list<string>,
     *   image_bytes_list?: list<string>
     * }  $payload
     */
    public function handleInbound(array $payload): PsbDraft
    {
        $this->replyDeliveryFailed = false;
        $groupJid = (string) ($payload['group_jid'] ?? '');
        $text = (string) ($payload['text'] ?? '');

        // Follow-up media is accepted only through an explicit #psb anchor
        // created by the controller. This deliberately bypasses recent-sender
        // draft merging so two technicians cannot cross-attach photos.
        if (! empty($payload['follow_up_anchor_message_id'])) {
            $anchor = (string) $payload['follow_up_anchor_message_id'];
            $draft = PsbDraft::query()
                ->where('group_jid', $groupJid)
                ->where('message_id', $anchor)
                ->where('status', '!=', PsbDraft::STATUS_CANCELLED)
                ->first();
            if (! $draft) {
                if (Cache::has($this->refusedAnchorKey($groupJid, $anchor))) {
                    Log::info('PSB follow-up dropped: its #psb was refused (SN already used)');

                    return new PsbDraft(['status' => 'ignored']);
                }
                throw new PsbLockBusyException('Draft PSB sedang dibuat, lampiran akan dicoba lagi.');
            }

            $bytes = [];
            $disk = Storage::disk(config('filesystems.default'));
            foreach ($payload['image_paths'] ?? [] as $path) {
                try {
                    if ($disk->exists($path)) {
                        $bytes[] = $disk->get($path);
                    }
                } catch (\Throwable) {
                }
            }
            if ($bytes !== []) {
                $this->attachFollowUpImages($draft, $bytes, [
                    'actor' => $payload['sender_jid'] ?? null,
                    'source' => 'wa_batch_followup',
                    'message_id' => $payload['message_id'] ?? null,
                    'media_contexts' => $payload['media_contexts'] ?? [],
                ]);
            }

            return $draft->fresh() ?? $draft;
        }
        $parsed = $this->parser->parse($text);

        if ($parsed['is_ktp_cmd'] ?? false) {
            $this->reply($groupJid, $this->messages->ktpCaptionNeedsPhoto((int) ($parsed['draft_id'] ?? 0)));

            return new PsbDraft(['status' => 'document_upload_waiting']);
        }

        // #psb bantu / /psb help → tutorial format (no draft)
        if ($parsed['is_help'] ?? false) {
            $this->reply($groupJid, $this->messages->help());

            return new PsbDraft(['status' => 'help']);
        }

        // /psb → rekap hari ini (no draft create)
        if ($parsed['is_status'] ?? false) {
            $this->reply($groupJid, $this->formatTodayReport());

            return new PsbDraft(['status' => 'report']);
        }

        // /pppoe <query> → cari secret PPPoE untuk teknisi
        if ($parsed['is_pppoe_cmd'] ?? false) {
            return $this->handlePppoeQuery($groupJid, $parsed['pppoe_query'] ?? null);
        }

        // P1 cek (#ID opsional → draft terakhir di grup)
        if ($parsed['is_cek'] ?? false) {
            return $this->commands()->handleCek($payload, $parsed);
        }

        // P2.5 antri (max 5 open drafts)
        if ($parsed['is_antri'] ?? false) {
            return $this->commands()->handleAntri($payload);
        }

        // P2 resend
        if ($parsed['is_resend'] ?? false) {
            return $this->commands()->handleResend($payload, $parsed);
        }

        // P3 batal
        if ($parsed['is_batal'] ?? false) {
            return $this->commands()->handleBatal($payload, $parsed);
        }

        // P6 confirm SN uncfg
        if ($parsed['is_confirm_sn'] ?? false) {
            return $this->commands()->handleConfirmSn($payload, $parsed);
        }

        // P4 SN command (before generic #psb create)
        if ($parsed['is_sn_cmd'] ?? false) {
            return $this->commands()->handleSnCmd($payload, $parsed);
        }

        // Sprint 2: #psb router ra|rdg|…  /  #psb vlan 100
        if ($parsed['is_router_cmd'] ?? false) {
            return $this->commands()->handleRouterCmd($payload, $parsed);
        }
        if ($parsed['is_vlan_cmd'] ?? false) {
            return $this->commands()->handleVlanCmd($payload, $parsed);
        }

        // done / #psb done → lanjut draft pending_vlan (NOC sudah set mapping)
        if ($parsed['is_done'] ?? false) {
            return $this->commands()->handleDone($payload, $parsed);
        }

        if ($parsed['is_lanjut']) {
            return $this->commands()->handleLanjut($payload, $parsed);
        }

        // Plain /psb without subcommand already handled; bare chatter shouldn't reach here
        if (! ($parsed['is_psb'] ?? false) && ! ($parsed['is_lanjut'] ?? false)) {
            return new PsbDraft(['status' => 'ignored']);
        }

        $messageId = $payload['message_id'] ?? null;
        if ($messageId) {
            $existing = PsbDraft::query()->where('message_id', $messageId)->first();
            if ($existing) {
                return $existing;
            }
        }

        $senderJid = $payload['sender_jid'] ?? null;

        // Same SN as a live draft but clearly another customer: refuse, change nothing.
        $snConflict = $this->intake()->findSnReuseConflict($groupJid, $senderJid, $parsed);
        if ($snConflict !== null) {
            $conflictSn = strtoupper(preg_replace('/\s+/', '', (string) ($parsed['sn'] ?? '')) ?? '');
            Log::info('PSB #psb refused: SN already used by a live draft', [
                'draft_id' => $snConflict->id,
                'sn' => $conflictSn,
            ]);
            if (! empty($messageId)) {
                // Photos sent after this #psb must be dropped quietly, not retried.
                Cache::put($this->refusedAnchorKey($groupJid, (string) $messageId), 1, now()->addMinutes(30));
            }
            if ($groupJid !== '') {
                $this->reply($groupJid, $this->messages->psbSnAlreadyUsed($conflictSn, $snConflict));
            }

            return $snConflict;
        }

        $merged = $this->intake()->findMergeableDraft($groupJid, $senderJid, $parsed);

        // Same name as a live draft, but SN and phone both differ: another customer. Refuse.
        if ($merged !== null && $this->intake()->isSameNameOtherLiveCustomer($merged, $parsed)) {
            Log::info('PSB #psb refused: same name as a live draft, SN and phone differ', [
                'draft_id' => $merged->id,
            ]);
            if (! empty($messageId)) {
                Cache::put($this->refusedAnchorKey($groupJid, (string) $messageId), 1, now()->addMinutes(30));
            }
            if ($groupJid !== '') {
                $this->reply($groupJid, $this->messages->psbSameNameOtherCustomer($merged));
            }

            return $merged;
        }

        $isUpdate = $merged !== null;
        $oldSn = $merged ? strtoupper((string) ($merged->sn ?? '')) : '';
        $beforeUpdate = $merged ? $this->correction()->psbCorrectionSnapshot($merged) : [];

        if ($isUpdate) {
            $draft = $this->intake()->applyParsedToDraft($merged, $payload, $parsed, $messageId, $oldSn);
        } else {
            $draft = new PsbDraft([
                'status' => PsbDraft::STATUS_RECEIVED,
                'group_jid' => $groupJid,
                'sender_jid' => $senderJid,
                'sender_name' => $payload['sender_name'] ?? null,
                'message_id' => $messageId,
                'raw_text' => $parsed['raw'],
                'full_name' => $parsed['full_name'],
                'rt' => $parsed['rt'],
                'rw' => $parsed['rw'],
                'odp' => $parsed['odp'],
                'phone' => $parsed['phone'],
                'tikor' => $parsed['tikor'],
                'daerah' => $parsed['daerah'],
                'sn' => $parsed['sn'],
                'vlan' => ! empty($parsed['vlan_value']) ? (int) $parsed['vlan_value'] : null,
                'tr069_vlan' => 69,
                'meta' => array_filter([
                    'sender_jid_pn' => $payload['sender_jid_pn'] ?? null,
                    'sender_jid_lid' => $payload['sender_jid_lid'] ?? null,
                ]),
            ]);
        }

        // Keep phone JID for mentions on update too
        if (! empty($payload['sender_jid_pn']) || ! empty($payload['sender_jid_lid'])) {
            $metaKeep = is_array($draft->meta) ? $draft->meta : [];
            if (! empty($payload['sender_jid_pn'])) {
                $metaKeep['sender_jid_pn'] = $payload['sender_jid_pn'];
            }
            if (! empty($payload['sender_jid_lid'])) {
                $metaKeep['sender_jid_lid'] = $payload['sender_jid_lid'];
            }
            $draft->meta = $metaKeep;
        }

        $this->applyDocumentPolicy($draft, $parsed, $payload);

        // Wajib: Router + Paket (batesin di MT via package profile; OLT PSB tetap 200M)
        $rpErrors = $this->applyRouterAndPackageFromParsed($draft, $parsed);
        if ($rpErrors !== []) {
            if ($isUpdate && $this->correction()->isLivePsbDraft($beforeUpdate)) {
                $metaWarn = is_array($draft->meta) ? $draft->meta : [];
                $metaWarn['psb_correction_validation_errors'] = $rpErrors;
                $draft->meta = $metaWarn;
            } else {
                $draft->status = PsbDraft::STATUS_FAILED;
                $draft->last_error = implode('; ', $rpErrors);
                $draft->save();
                $this->reply($groupJid, $this->messages->incomplete($rpErrors));

                return $draft;
            }
        }

        // Photos from local paths (preferred) or raw bytes
        $images = [];
        $mediaContexts = array_values($payload['media_contexts'] ?? []);
        foreach ($payload['image_paths'] ?? [] as $rel) {
            try {
                if (Storage::disk(config('filesystems.default'))->exists($rel)) {
                    $images[] = Storage::disk(config('filesystems.default'))->get($rel);
                }
            } catch (\Throwable) {
            }
        }
        if ($images === [] && ! empty($payload['image_bytes_list'])) {
            $images = $payload['image_bytes_list'];
        }
        if ($images !== []) {
            // Merge with existing photo bytes when updating
            if ($isUpdate) {
                $disk = Storage::disk(config('filesystems.default'));
                foreach ([$draft->ktp_photo_path, $draft->router_photo_path] as $p) {
                    if ($p && $disk->exists($p)) {
                        try {
                            $images[] = $disk->get($p);
                            $mediaContexts[] = [
                                'path' => $p,
                                'source' => 'existing_draft',
                            ];
                        } catch (\Throwable) {
                        }
                    }
                }
            }

            if ((bool) config('services.ocrspace.async_media_enabled', false)) {
                $staged = $this->ocrBatch()->mergeParkedRetryFiles($draft, $this->ocrBatch()->stageMediaForOcr($images, $mediaContexts));
                if ($staged['paths'] !== []) {
                    $this->ocrBatch()->markMediaOcrPending($draft, $staged['batch_key'], $staged['paths'], $staged['contexts']);
                    // A new draft needs an id before the unique OCR job is queued.
                    $draft->save();
                    PsbOcrBatchLedger::open((int) $draft->id, (string) $staged['batch_key']);
                    ProcessPsbMediaOcrJob::dispatch(
                        (int) $draft->id,
                        $staged['paths'],
                        $staged['contexts'],
                        [
                            'mode' => 'inbound',
                            'batch_key' => $staged['batch_key'],
                            'group_jid' => $groupJid,
                            'is_update' => $isUpdate,
                            'before_update' => $beforeUpdate,
                            'old_sn' => $oldSn,
                            'parsed' => $parsed,
                            'payload' => array_merge(
                                collect($payload)->except(['image_bytes_list', 'image_paths', 'media_contexts'])->all(),
                                ['image_paths' => [], 'media_contexts' => []]
                            ),
                        ]
                    );
                    $this->reply($groupJid, $this->messages->mediaValidationPending($draft));

                    return $draft;
                }
            } else {
                $this->ocrEngine()->attachPhotosAndOcr($draft, $images, $mediaContexts);
            }
        }

        return $this->finishInboundAfterMedia($draft, $parsed, $payload, $isUpdate, $beforeUpdate, $oldSn);
    }

    /**
     * Continue the normal inbound state machine after OCR has completed.
     * No device call is made before this document gate succeeds.
     *
     * @param  array<string,mixed>  $parsed
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $beforeUpdate
     */
    public function finishInboundAfterMedia(
        PsbDraft $draft,
        array $parsed,
        array $payload,
        bool $isUpdate,
        array $beforeUpdate = [],
        string $oldSn = ''
    ): PsbDraft {
        $groupJid = (string) ($payload['group_jid'] ?? $draft->group_jid ?? '');

        // Early resolve (no OLT yet); the hard "Perlu area" guard runs in processDraft.
        $this->resolveArea($draft);

        $missing = $this->areaResolver()->missingRequired($draft);
        if ($missing !== []) {
            $draft->status = PsbDraft::STATUS_FAILED;
            $draft->last_error = 'Field kurang: '.implode(', ', $missing);
            $draft->save();
            if ($groupJid !== '') {
                $this->reply($groupJid, $this->messages->incomplete($missing));
            }

            return $draft;
        }

        if ($isUpdate && $this->correction()->markLiveCorrectionPendingIfNeeded($draft, $beforeUpdate, $parsed, $payload)) {
            $draft->save();
            if ($groupJid !== '') {
                $this->reply($groupJid, $this->messages->psbCorrectionPending($draft));
            }

            return $draft;
        }

        // Still waiting for approval (resend without a new diff): never provision from here.
        if ($draft->status === PsbDraft::STATUS_CORRECTION_PENDING) {
            $draft->save();
            if ($groupJid !== '') {
                $this->reply($groupJid, $this->messages->psbCorrectionPending($draft));
            }

            return $draft;
        }

        if (! $this->hasMinPhoto($draft)) {
            $this->waitForOtherPhotoBatches($draft);
            $draft->status = PsbDraft::STATUS_RECEIVED;
            $gate = $this->documentGate->evaluate($draft);
            $meta = is_array($draft->meta) ? $draft->meta : [];
            $meta['waiting_photo'] = true;
            $photoCount = (int) data_get($meta, 'photo_class.images', 0);
            $ocrCompleted = data_get($meta, 'photo_class.status') === 'completed';
            $hasUnresolvedPhoto = ! $ocrCompleted
                && ($photoCount > 0 || data_get($meta, 'document_gate.status') === 'ocr_pending');
            if ($hasUnresolvedPhoto) {
                $meta['document_gate'] = [
                    'status' => 'ocr_pending',
                    'updated_at' => now('Asia/Jakarta')->toIso8601String(),
                ];
                $draft->last_error = 'Foto diterima, validasi KTP/SN masih berjalan.';
            } else {
                $draft->last_error = 'Dokumen PSB belum valid: '.($gate['status'] ?? 'missing');
            }
            $draft->meta = $meta;
            $draft->save();
            $postOcr = $this->postOcr !== null;
            $unreadable = $postOcr && ! $hasUnresolvedPhoto && $this->ocrBatch()->isKtpUnreadable($draft);
            if ($unreadable) {
                $draft->last_error = 'KTP belum terbaca. Kirim ulang: #psb ktp #'.$draft->id;
                $draft->save();
            }
            if ($groupJid !== '') {
                if ($isUpdate && ! $hasUnresolvedPhoto) {
                    $snChanged = $oldSn !== '' && strtoupper((string) $draft->sn) !== $oldSn;
                    $updatedText = $this->messages->draftUpdated((int) $draft->id, $draft->full_name, $snChanged)
                        ."\n\n".($unreadable ? $this->ktpUnreadableText($draft) : $this->messages->waitingPhoto($draft));
                    if ($postOcr) {
                        $this->sendOcrTerminalOnce($draft, (string) ($this->postOcr['batch_key'] ?? ''), $updatedText, $groupJid);
                    } else {
                        $this->reply($groupJid, $updatedText);
                    }
                } elseif ($hasUnresolvedPhoto) {
                    // Recovery runs repeat; the "sedang validasi" WA was sent once at staging.
                    if (! ($postOcr && ($this->postOcr['mode'] ?? '') === 'recovery')) {
                        $this->reply($groupJid, $this->messages->mediaValidationPending($draft));
                    }
                } elseif ($postOcr) {
                    $this->postOcrBlockedReply($draft, $this->messages->documentBlocked($draft, $gate), $groupJid);
                } else {
                    $this->reply($groupJid, $this->messages->documentBlocked($draft, $gate));
                }
            }

            return $draft;
        }

        $draft->save();
        $snChanged = $isUpdate && $oldSn !== '' && strtoupper((string) $draft->sn) !== $oldSn;
        if ($groupJid !== '') {
            $this->reply($groupJid, $isUpdate
                ? $this->messages->draftUpdated((int) $draft->id, $draft->full_name, $snChanged)
                : $this->messages->received((int) $draft->id, $draft->full_name));
        }

        return $this->draftProcessor()->processDraft($draft);
    }

    /**
     * Orchestrator that runs processDraft after an asynchronous media
     * continuation. Defaults to this instance; the OCR worker adapter
     * overrides it so site-aware (e.g. Gadang Huawei) logic is not bypassed.
     */
    protected function draftProcessor(): self
    {
        return $this;
    }

    /**
     * OCR worker entry point. Staged paths are deleted only after successful
     * classification; a retry can therefore reuse the same exact bytes.
     *
     * @param  list<string>  $stagedPaths
     * @param  list<array<string,mixed>>  $contexts
     * @param  array<string,mixed>  $continuation
     */
    public function processStagedMediaOcr(
        PsbDraft $draft,
        array $stagedPaths,
        array $contexts,
        array $continuation = []
    ): PsbDraft {
        $meta = is_array($draft->meta) ? $draft->meta : [];
        $batchKey = (string) ($continuation['batch_key'] ?? $meta['ocr_batch_key'] ?? '');
        if ($batchKey !== '' && data_get($meta, 'photo_class.ocr_batch_completed') === $batchKey
            && ! $this->ocrBatch()->completedBatchNeedsFreshOcr($meta, $stagedPaths)) {
            // A duplicate inbound can mark an already-completed batch pending
            // before Laravel's idempotent job runs. Restore the terminal OCR
            // state and continue the original state machine; otherwise the
            // draft remains stuck at ocr_pending forever.
            $this->markMediaOcrCompleted($draft, $batchKey);
            PsbOcrBatchLedger::close((int) $draft->id, $batchKey);

            return $this->continueAfterMediaOcr($draft, $continuation);
        }

        $disk = Storage::disk(config('filesystems.default'));
        $bytes = [];
        foreach ($stagedPaths as $path) {
            if (! is_string($path) || ! $disk->exists($path)) {
                continue;
            }
            $value = $disk->get($path);
            if (is_string($value) && strlen($value) > 100) {
                $bytes[] = $value;
            }
        }
        if ($bytes === []) {
            throw new \RuntimeException('Tidak ada media OCR yang bisa dibaca.');
        }

        $batch = $this->ocrEngine()->classifyBatch($draft, $bytes, $contexts);
        $isRetry = (int) ($continuation['ocr_retry'] ?? 0) === 1;

        // The server was too slow to read the photo (timeout/deadline), not the
        // photo too bad: do not tell the technician "KTP belum terbaca" yet.
        // Keep the files, say nothing, and check once more later.
        if ($batch !== null && $batch['degraded'] && ! $isRetry
            && $this->ocrBatch()->ocrRetryEnabled() && $batchKey !== ''
            && ! (($this->documentGate->evaluate($draft, false)['allowed'] ?? false))) {
            $this->ocrBatch()->markOcrRetryPending($draft, $batchKey, $batch);

            return $draft->fresh() ?? $draft;
        }

        if ($batch !== null) {
            $this->ocrEngine()->applyBatch($draft, $batch, ['retry' => $isRetry ? 1 : 0]);
        }
        $this->markMediaOcrCompleted($draft, $batchKey);
        PsbOcrBatchLedger::close((int) $draft->id, $batchKey);

        foreach ($stagedPaths as $path) {
            try {
                $disk->delete($path);
            } catch (\Throwable) {
                // Retention cleanup is best-effort; classification already passed.
            }
        }

        return $this->continueAfterMediaOcr($draft, $continuation);
    }

    /** Mark a media batch terminal without repeating the expensive OCR call. */
    protected function markMediaOcrCompleted(PsbDraft $draft, string $batchKey): void
    {
        $this->ocrBatch()->markMediaOcrCompleted($draft, $batchKey);
    }

    /**
     * A superseded retry batch: delete ONLY its own staged files, and only when the
     * draft's current batch is known and shares none of them (staged names are
     * content hashes, so the same photo resent lands on the same path).
     *
     * @param  list<string>  $stagedPaths
     */
    public function cleanupSupersededRetryFiles(PsbDraft $draft, array $stagedPaths): void
    {
        $this->ocrBatch()->cleanupSupersededRetryFiles($draft, $stagedPaths);
    }

    /** True when a terminal reply was already sent for a different OCR batch (staging clears the marker). */
    protected function anotherBatchAlreadyAnswered(PsbDraft $draft): bool
    {
        $batchKey = (string) ($this->postOcr['batch_key'] ?? '');
        if ($this->postOcr === null || $batchKey === '') {
            return false;
        }

        return $this->ocrBatch()->terminalNotifiedForOtherBatch($draft, $batchKey);
    }

    /**
     * Before saying "KTP missing" after OCR: when another photo batch of the same
     * draft is still being read (WA album split into several batches), do not
     * decide yet. Throws PsbAlbumOcrWaitException; the continuation job queues
     * itself again. Never waits in recovery/sync runs, for CSR/menyusul drafts,
     * or when the ledger is unavailable.
     */
    protected function waitForOtherPhotoBatches(PsbDraft $draft): void
    {
        $limit = $this->ocrBatch()->albumWaitSeconds();
        if ($limit <= 0 || $this->postOcr === null) {
            return;
        }
        if (! in_array($this->postOcr['mode'] ?? '', ['inbound', 'follow_up'], true)) {
            return;
        }
        if (! $this->documentGate->isEnforced($draft)) {
            return;
        }
        $gate = $this->documentGate->evaluate($draft, false);
        if (($gate['allowed'] ?? false) || ! in_array($gate['status'] ?? '', ['missing', 'invalid_media'], true)) {
            return;
        }

        $siblings = PsbOcrBatchLedger::openSiblings((int) $draft->id, (string) ($this->postOcr['batch_key'] ?? ''));
        if ($siblings === []) {
            return;
        }

        $now = now()->getTimestamp();
        $since = (int) ($this->postOcr['album_wait_since'] ?? 0);
        $waited = $since > 0 ? $now - $since : 0;
        if ($since > 0 && $waited >= $limit) {
            Log::info('[PSB] KTP decision wait limit reached', ['draft' => $draft->id, 'waited_s' => $waited]);

            return;
        }

        Log::info('[PSB] KTP decision waits for other photo batch', [
            'draft' => $draft->id,
            'siblings' => count($siblings),
            'waited_s' => $waited,
        ]);

        throw new PsbAlbumOcrWaitException($since > 0 ? $since : $now, $limit - $waited);
    }

    /** "KTP belum terbaca" text; adds the slow-server line only after the automatic re-check was also degraded. */
    protected function ktpUnreadableText(PsbDraft $draft): string
    {
        $meta = is_array($draft->meta) ? $draft->meta : [];

        return $this->messages->ktpUnreadable($draft, (bool) data_get($meta, 'photo_class.slow_unreadable', false));
    }

    /** Continue the PSB state machine after OCR, including idempotent retries. */
    protected function continueAfterMediaOcr(PsbDraft $draft, array $continuation): PsbDraft
    {
        // Marks "we are after OCR" for the reply helpers below. Synchronous
        // inbound and text-only paths never set it and keep their replies.
        $previous = $this->postOcr;
        $batchKey = (string) ($continuation['batch_key'] ?? '');
        if ($batchKey === '') {
            $batchKey = (string) data_get($draft->meta, 'photo_class.ocr_batch_completed', '');
        }
        $this->postOcr = [
            'batch_key' => $batchKey,
            'mode' => (string) ($continuation['mode'] ?? 'inbound'),
            'album_wait_since' => (int) ($continuation['album_wait_since'] ?? 0),
        ];

        try {
            if (($continuation['mode'] ?? 'inbound') === 'follow_up') {
                return $this->finishFollowUpAfterMedia($draft, $continuation);
            }

            return $this->finishInboundAfterMedia(
                $draft,
                is_array($continuation['parsed'] ?? null) ? $continuation['parsed'] : [],
                is_array($continuation['payload'] ?? null) ? $continuation['payload'] : [],
                (bool) ($continuation['is_update'] ?? false),
                is_array($continuation['before_update'] ?? null) ? $continuation['before_update'] : [],
                (string) ($continuation['old_sn'] ?? '')
            );
        } finally {
            $this->postOcr = $previous;
        }
    }

    /** One terminal WA per OCR batch key; later retries of the same batch stay silent. */
    protected function sendOcrTerminalOnce(PsbDraft $draft, string $batchKey, string $text, ?string $groupJid = null): void
    {
        $groupJid = (string) ($groupJid !== null && $groupJid !== '' ? $groupJid : $draft->group_jid);
        if ($groupJid === '') {
            return;
        }
        if ($batchKey !== '') {
            $stored = PsbDraft::query()->whereKey($draft->id)->value('meta');
            $stored = is_array($stored) ? $stored : (is_string($stored) ? (json_decode($stored, true) ?: []) : []);
            if (($stored['ocr_terminal_notified'] ?? null) === $batchKey) {
                Log::info('[PSB] OCR terminal reply already sent', ['draft' => $draft->id]);

                return;
            }
            $meta = is_array($draft->meta) ? $draft->meta : [];
            $meta['ocr_terminal_notified'] = $batchKey;
            $draft->meta = $meta;
            $draft->save();
        }

        if (! $this->reply($groupJid, $text) && $batchKey !== '') {
            $meta = is_array($draft->meta) ? $draft->meta : [];
            unset($meta['ocr_terminal_notified']);
            $draft->meta = $meta;
            $draft->save();
        }
    }

    /** Blocked reply after OCR: plain "KTP belum terbaca" when unreadable, else the given text. */
    protected function postOcrBlockedReply(PsbDraft $draft, string $defaultText, ?string $groupJid = null): void
    {
        $text = $this->ocrBatch()->isKtpUnreadable($draft) ? $this->ktpUnreadableText($draft) : $defaultText;
        $this->sendOcrTerminalOnce($draft, (string) ($this->postOcr['batch_key'] ?? ''), $text, $groupJid);
    }

    /**
     * Sweeper: OCR never finished for this batch. End it as "KTP belum terbaca"
     * so the technician resends the photo, instead of waiting forever.
     */
    public function markMediaOcrTerminal(PsbDraft $draft, string $batchKey, string $reason): void
    {
        $this->ocrBatch()->markTerminalMeta($draft, $batchKey, $reason);

        $this->sendOcrTerminalOnce($draft, $batchKey, $this->messages->ktpUnreadable($draft));
    }

    /** @param array<string,mixed> $continuation */
    protected function finishFollowUpAfterMedia(PsbDraft $draft, array $continuation): PsbDraft
    {
        $draft->save();
        $gate = $this->documentGate->evaluate($draft);
        $meta = is_array($draft->meta) ? $draft->meta : [];
        $waitingPhoto = ! in_array($draft->status, PsbDraft::PHOTO_LOCKED_STATUSES, true)
            && (! empty($meta['waiting_photo']) || str_contains((string) $draft->last_error, 'Menunggu foto'));
        if ($waitingPhoto && ($gate['allowed'] ?? false) && $this->areaResolver()->missingRequired($draft) === []) {
            unset($meta['waiting_photo']);
            $draft->meta = $meta;
            $draft->last_error = null;
            $draft->status = PsbDraft::STATUS_RECEIVED;
            $draft->save();
            $groupJid = (string) $draft->group_jid;
            if ($groupJid !== '') {
                $this->reply($groupJid, $this->messages->received((int) $draft->id, $draft->full_name));
                $this->draftProcessor()->processDraft($draft->fresh() ?? $draft);
            }
        } elseif ($waitingPhoto && data_get($meta, 'document_gate.status') === 'ocr_pending' && $draft->group_jid !== '') {
            $this->reply((string) $draft->group_jid, $this->messages->mediaValidationPending($draft));
        } elseif ($waitingPhoto && $draft->group_jid !== '') {
            $this->waitForOtherPhotoBatches($draft);
            if ($this->anotherBatchAlreadyAnswered($draft)) {
                // Another batch of this intake already sent the blocked reply.
                return $draft->fresh() ?? $draft;
            }
            $draft->last_error = $this->ocrBatch()->isKtpUnreadable($draft)
                ? 'KTP belum terbaca. Kirim ulang: #psb ktp #'.$draft->id
                : 'Dokumen PSB belum valid: '.($gate['status'] ?? 'missing');
            $draft->save();
            $this->postOcrBlockedReply($draft, $this->messages->documentBlocked($draft, $gate));
        }

        app(PsbDraftCustomerSyncService::class)->sync($draft);

        return $draft->fresh() ?? $draft;
    }

    protected function refusedAnchorKey(string $groupJid, string $messageId): string
    {
        return 'psb:refused-anchor:'.$groupJid.':'.$messageId;
    }

    protected function hasMinPhoto(PsbDraft $draft): bool
    {
        if ($this->documentGate->isEnforced($draft)) {
            return (bool) ($this->documentGate->evaluate($draft)['allowed'] ?? false);
        }

        $ktp = trim((string) ($draft->ktp_photo_path ?? ''));
        $snPh = trim((string) ($draft->router_photo_path ?? ''));
        if ($ktp === '' && $snPh === '') {
            return false;
        }
        // Reject weak KTP-only classification without any file that is SN path
        $meta = is_array($draft->meta) ? $draft->meta : [];
        $cls = $meta['photo_class'] ?? [];
        if ($ktp !== '' && $snPh === '' && is_array($cls)
            && array_key_exists('ktp_score', $cls) && $cls['ktp_score'] !== null
            && (int) $cls['ktp_score'] < 25) {
            return false;
        }

        return true;
    }

    /** @param array<string, mixed> $parsed @param array<string, mixed> $payload */
    protected function applyDocumentPolicy(PsbDraft $draft, array $parsed, array $payload): void
    {
        $meta = is_array($draft->meta) ? $draft->meta : [];
        $meta['document_context'] = array_filter([
            'actor' => $payload['sender_jid'] ?? null,
            'source' => 'psb_wa',
            'message_id' => $payload['message_id'] ?? null,
        ]);

        if (! empty($parsed['ktp_policy'])) {
            $meta['document_policy'] = [
                'type' => $parsed['ktp_policy'],
                'reason' => $parsed['ktp_reason'] ?? null,
                'due_at' => $parsed['ktp_due_at'] ?? null,
                'recorded_at' => now('Asia/Jakarta')->toIso8601String(),
            ];
        }
        $draft->meta = $meta;
    }

    /** Near-miss feedback (rate-limited per sender). */
    public function replyNearMiss(string $groupJid, ?string $senderJid = null): void
    {
        $key = 'psb-near-miss:'.md5($groupJid.'|'.($senderJid ?: 'x'));
        if (Cache::has($key)) {
            return;
        }
        Cache::put($key, 1, now()->addMinutes(10));
        Log::info('[PSB] near-miss trigger', ['group' => $groupJid, 'sender' => $senderJid]);
        $this->reply($groupJid, $this->messages->nearMissNeedHash());
    }

    /** Kirim pin notice ke grup (admin/ops). */
    public function sendGroupPinNotice(?string $groupJid = null): bool
    {
        $jid = $groupJid ?: ($this->allowedGroupJids()[0] ?? '');
        if ($jid === '') {
            return false;
        }
        $this->reply($jid, $this->messages->groupPinNotice());

        return true;
    }

    public function processDraft(PsbDraft $draft): PsbDraft
    {
        return $this->conductor()->process($draft);
    }

    /** True while the SN poller drives processDraft (lock contention stays quiet). */
    protected function inPollerRun(): bool
    {
        return $this->conductor()->inPollerRun();
    }

    /** True when the last processDraft call found the draft lock busy (draft unchanged). */
    public function draftLockWasBusy(): bool
    {
        return $this->draftLockBusy;
    }

    /**
     * The per-draft lock stayed busy. Resume run: hand back to ResumePsbDraftJob
     * (same re-dispatch as before). Poller: log only. Otherwise one reply and the
     * draft is returned unchanged.
     */
    protected function draftLockBusyOutcome(PsbDraft $draft, string $groupJid): PsbDraft
    {
        return $this->conductor()->draftLockBusyOutcome($draft, $groupJid);
    }

    /**
     * The router PPPoE/IP queue (or address allocator) was busy before any
     * device write: keep the status (NOT failed), record why, one reply.
     */
    protected function secretQueueBusy(PsbDraft $draft, string $groupJid, Router $router): PsbDraft
    {
        return $this->conductor()->secretQueueBusy($draft, $groupJid, $router);
    }

    protected function correctionBusyMessage(int $draftId): string
    {
        return "Koreksi draft #{$draftId} sedang diproses proses lain. Coba lagi +-1 menit.";
    }

    /** Global safety switch (default true). */
    protected function writesEnabled(): bool
    {
        return $this->psbConfig()->writesEnabled();
    }

    public function pollWaitingSn(): int
    {
        return $this->conductor()->pollWaitingSn();
    }

    /**
     * Jadwal rekap otomatis dari Settings; nilai tidak valid / kosong → default.
     *
     * @return array{start: int, end: int, interval: int}
     */
    public static function recapSchedule(): array
    {
        return PsbRecapStats::recapSchedule();
    }

    /**
     * Jam WIB (0–23) yang mengirim rekap untuk jadwal tertentu.
     *
     * @param  array{start: int, end: int, interval: int}  $schedule
     * @return list<int>
     */
    public static function recapSendHours(array $schedule): array
    {
        return PsbRecapStats::recapSendHours($schedule);
    }

    /**
     * Rekap otomatis per jam ke grup WA.
     *
     * Aturan anti-spam:
     * - Jadwal dari Settings (psb_recap_start_hour / psb_recap_end_hour /
     *   psb_recap_interval_hours, default 9 / 21 / 2): kirim hanya jika
     *   start <= jam WIB <= end DAN (jam - start) % interval === 0
     *   (default: 09, 11, 13, 15, 17, 19, 21 WIB). Scheduler memanggil tiap jam.
     * - Skip jika 0 draft hari ini
     * - Skip jika statistik tidak berubah sejak kiriman hourly terakhir (hari yang sama)
     */
    public function hourlyReport(): int
    {
        $now = now('Asia/Jakarta');
        $hour = (int) $now->format('G'); // 0–23
        $schedule = self::recapSchedule();
        if (! in_array($hour, self::recapSendHours($schedule), true)) {
            Log::info('[PSB] hourly report skipped — outside recap schedule', [
                'hour' => $hour,
                'schedule' => $schedule,
            ]);

            return 0;
        }

        $groups = $this->allowedGroupJids();
        if ($groups === []) {
            return 0;
        }

        $stats = $this->recap()->collectTodayStats();
        if ((int) ($stats['total'] ?? 0) === 0) {
            Log::info('[PSB] hourly report skipped — 0 drafts today');

            return 0;
        }

        // Fingerprint data (bukan jam) — kirim hanya jika ada perubahan
        $fingerprint = $this->recap()->todayStatsFingerprint($stats);
        $cacheKey = 'psb:hourly-fp:'.$now->toDateString();
        $prev = Cache::get($cacheKey);
        if ($prev !== null && hash_equals((string) $prev, $fingerprint)) {
            Log::info('[PSB] hourly report skipped — no data change', [
                'fingerprint' => substr($fingerprint, 0, 12),
            ]);

            return 0;
        }

        $msg = $this->messages->todayReport($stats);
        foreach ($groups as $g) {
            $this->reply($g, $msg);
        }

        // Simpan sampai akhir hari WIB (+ buffer)
        // Carbon 3: diffInSeconds is signed — $now → endOfDay is the positive direction.
        $ttl = max(60, (int) $now->diffInSeconds($now->copy()->endOfDay()) + 3600);
        Cache::put($cacheKey, $fingerprint, $ttl);

        return count($groups);
    }

    /** Rekap PSB hari ini (Asia/Jakarta) — dipakai /psb (manual, selalu kirim). */
    public function formatTodayReport(): string
    {
        return $this->messages->todayReport($this->recap()->collectTodayStats());
    }

    /**
     * Apply draft → full active customer (admin).
     */
    public function applyDraft(PsbDraft $draft): Customer
    {
        if ($draft->status === PsbDraft::STATUS_CORRECTION_PENDING) {
            throw new \RuntimeException('Draft masih menunggu koreksi provisioning. Selesaikan/approve koreksi dulu.');
        }
        if (! $draft->customer_id) {
            throw new \RuntimeException('Draft belum punya customer (secret belum dibuat).');
        }
        $customer = Customer::findOrFail($draft->customer_id);
        $customer->update([
            'status' => CustomerStatus::Active->value,
        ]);
        app(PsbDraftCustomerSyncService::class)->sync($draft);
        $draft->status = PsbDraft::STATUS_APPLIED;
        $draft->applied_at = now();
        $draft->save();

        return $customer;
    }

    public function applyPsbCorrection(PsbDraft $draft): PsbDraft
    {
        if ($draft->status !== PsbDraft::STATUS_CORRECTION_PENDING) {
            throw new \RuntimeException('Draft tidak sedang menunggu koreksi.');
        }
        if (! $this->writesEnabled() || ! $this->psbConfig()->correctionWritesEnabled()) {
            throw new \RuntimeException('Koreksi perangkat masih dikunci. Set `psb_correction_writes_enabled=1` setelah preflight dicek NOC.');
        }

        $lock = Cache::lock('psb-correction-'.$draft->id, 180);
        if (! CacheLockWait::acquire($lock, 5)) {
            throw new LockBusyException($this->correctionBusyMessage((int) $draft->id));
        }

        try {
            return $this->correction()->applyLocked($draft);
        } finally {
            $lock->release();
        }
    }

    public function cancelPsbCorrection(PsbDraft $draft, mixed $actor = null): PsbDraft
    {
        if ($draft->status !== PsbDraft::STATUS_CORRECTION_PENDING) {
            throw new \RuntimeException('Draft tidak sedang menunggu koreksi.');
        }

        $lock = Cache::lock('psb-correction-'.$draft->id, 60);
        if (! CacheLockWait::acquire($lock, 5)) {
            throw new LockBusyException($this->correctionBusyMessage((int) $draft->id));
        }

        try {
            return $this->correction()->cancelLocked($draft, $actor);
        } finally {
            $lock->release();
        }
    }

    /**
     * Reuse PSB WA local OCR/photo heuristics for migration photos.
     *
     * @return array{type: string, sn: ?string, ktp_ocr: array<string, mixed>, ktp_score: int, sn_score: int, classification_status: string, classification_error?: string}
     */
    public function classifyWaActivationPhoto(string $bytes, ?OcrBudget $batch = null): array
    {
        return $this->ocrEngine()->classifyWaActivationPhoto($bytes, $batch);
    }

    /**
     * Foto lanjutan dari WA (pesan terpisah setelah #psb) — merge + re-klasifikasi.
     */
    public function attachFollowUpImages(PsbDraft $draft, array $newImageBytes, array $context = []): PsbDraft
    {
        if ($context !== []) {
            $meta = is_array($draft->meta) ? $draft->meta : [];
            $meta['document_context'] = array_filter([
                'actor' => $context['actor'] ?? null,
                'source' => $context['source'] ?? 'wa_exact_caption',
                'message_id' => $context['message_id'] ?? null,
            ]);
            $draft->meta = $meta;
        }
        $bytesList = [];
        $contextList = [];
        $disk = Storage::disk(config('filesystems.default'));
        // A KTP resend (#psb ktp #ID) does not need the SN photo OCR'd again
        // when the draft already has a resolved SN photo (saves 25-90 s per resend).
        $skipSnPhoto = ($context['source'] ?? null) === 'wa_exact_caption'
            && trim((string) $draft->sn) !== ''
            && trim((string) $draft->router_photo_path) !== ''
            && $disk->exists((string) $draft->router_photo_path);
        foreach ([$draft->ktp_photo_path, $skipSnPhoto ? null : $draft->router_photo_path] as $p) {
            if ($p && $disk->exists($p)) {
                try {
                    $bytesList[] = $disk->get($p);
                    $contextList[] = [
                        'path' => $p,
                        'source' => 'existing_draft',
                    ];
                } catch (\Throwable) {
                }
            }
        }
        $mediaContexts = array_values($context['media_contexts'] ?? []);
        $newIndex = 0;
        foreach ($newImageBytes as $b) {
            if (is_string($b) && strlen($b) > 100) {
                $bytesList[] = $b;
                $contextList[] = $mediaContexts[$newIndex] ?? [
                    'source' => $context['source'] ?? 'wa_follow_up',
                    'message_id' => $context['message_id'] ?? null,
                    'sender' => $context['actor'] ?? null,
                ];
                $newIndex++;
            }
        }
        if ((bool) config('services.ocrspace.async_media_enabled', false)) {
            $staged = $this->ocrBatch()->mergeParkedRetryFiles($draft, $this->ocrBatch()->stageMediaForOcr($bytesList, $contextList));
            if ($staged['paths'] !== []) {
                $this->ocrBatch()->markMediaOcrPending($draft, $staged['batch_key'], $staged['paths'], $staged['contexts']);
                $draft->save();
                PsbOcrBatchLedger::open((int) $draft->id, (string) $staged['batch_key']);
                ProcessPsbMediaOcrJob::dispatch(
                    (int) $draft->id,
                    $staged['paths'],
                    $staged['contexts'],
                    [
                        'mode' => 'follow_up',
                        'batch_key' => $staged['batch_key'],
                        'context' => $context,
                    ]
                );
                if ($draft->group_jid !== '') {
                    $this->reply((string) $draft->group_jid, $this->messages->mediaValidationPending($draft));
                }

                return $draft->fresh() ?? $draft;
            }
        } else {
            $this->ocrEngine()->attachPhotosAndOcr($draft, $bytesList, $contextList);
        }
        $draft->save();

        $gate = $this->documentGate->evaluate($draft);

        // Kalau sebelumnya nunggu foto & field lengkap → lanjut process
        $meta = is_array($draft->meta) ? $draft->meta : [];
        $waitingPhoto = ! in_array($draft->status, PsbDraft::PHOTO_LOCKED_STATUSES, true)
            && (! empty($meta['waiting_photo']) || str_contains((string) $draft->last_error, 'Menunggu foto'));
        if ($waitingPhoto && ($gate['allowed'] ?? false) && $this->areaResolver()->missingRequired($draft) === []) {
            unset($meta['waiting_photo']);
            $draft->meta = $meta;
            $draft->last_error = null;
            $draft->status = PsbDraft::STATUS_RECEIVED;
            $draft->save();
            $groupJid = (string) $draft->group_jid;
            if ($groupJid !== '') {
                $this->reply($groupJid, $this->messages->received((int) $draft->id, $draft->full_name));
                $this->processDraft($draft->fresh() ?? $draft);
            }
        } elseif ($waitingPhoto && data_get($meta, 'document_gate.status') === 'ocr_pending' && $draft->group_jid !== '') {
            $this->reply((string) $draft->group_jid, $this->messages->mediaValidationPending($draft));
        } elseif ($waitingPhoto && $draft->group_jid !== '') {
            $this->reply((string) $draft->group_jid, $this->messages->documentBlocked($draft, $gate));
        }

        app(PsbDraftCustomerSyncService::class)->sync($draft);

        return $draft->fresh() ?? $draft;
    }

    /**
     * Resolve the draft's area. Precedence:
     *   1. manual   - meta.area_manual (admin picked it in the drafts list)
     *   2. locality - AreaLocalityResolver on "daerah" (alias > name > code)
     *   3. OLT area - only when the OLT has an area AND the policy allows (non-RA sites by default)
     *   4. null     - caller must block the draft ("Perlu area") before any device write
     * Never assigns Randuagung Starlite implicitly. Records meta.area_resolution.
     *
     * @return array{status: string, source: string, area_id: ?int, matched_key: ?string, candidates: list<array{area_id: int, key: string, via: string}>}
     */
    protected function resolveArea(PsbDraft $draft, ?Olt $siteOlt = null): array
    {
        return $this->areaResolver()->resolveArea($draft, $siteOlt);
    }

    /**
     * Guard run BEFORE any OLT/router/GenieACS write: no usable area -> FAILED "Perlu area".
     *
     * @param  array{status: string, ...}  $resolution
     */
    protected function blockIfAreaMissing(PsbDraft $draft, string $groupJid, array $resolution): bool
    {
        return $this->conductor()->blockIfAreaMissing($draft, $groupJid, $resolution);
    }

    /**
     * @return bool true if secret already existed on MikroTik (P5 idempotent)
     */
    protected function createSecretAndDraftCustomer(PsbDraft $draft, Router $router, Package $package, ?Olt $olt): bool
    {
        return $this->secretProvisioner()->createSecretAndDraftCustomer($draft, $router, $package, $olt);
    }

    /** Removes meta.olt_busy_resume (caller saves). */
    protected function clearOltBusyMarker(PsbDraft $draft): void
    {
        $this->oltBusy()->clearOltBusyMarker($draft);
    }

    /**
     * True when the current run is a ResumePsbDraftJob run whose token is no
     * longer current (superseded by lanjut / a newer job) or the draft is no
     * longer waiting for the OLT.
     */
    protected function oltResumeTokenStale(PsbDraft $draft): bool
    {
        return $this->conductor()->oltResumeTokenStale($draft);
    }

    /**
     * OLT/SN lock stayed busy (called under the draft lock): keep PPPoE as is,
     * save a resume marker and queue an automatic retry. Gives up after
     * OLT_BUSY_MAX_ATTEMPTS automatic retries or when the kill switch is off.
     */
    protected function deferOltAfterBusyLock(PsbDraft $draft, string $groupJid, PsbOltLockBusyException $e): PsbDraft
    {
        return $this->conductor()->deferOltAfterBusyLock($draft, $groupJid, $e);
    }

    /**
     * Run by ResumePsbDraftJob: an automatic "#psb lanjut" that bypasses
     * handleInbound (no dedupe). Does nothing unless the token is current.
     * May throw LockTimeoutException when the draft lock is busy.
     */
    public function resumeDraftAfterOltBusy(int $draftId, string $token): ?PsbDraft
    {
        return $this->conductor()->resumeDraftAfterOltBusy($draftId, $token);
    }

    /**
     * Gives up a pending OLT-busy resume (job failed / sweeper). Never waits
     * for the draft lock; returns false when it cannot act safely.
     */
    public function giveUpOltBusyResume(int $draftId, string $token, string $reason): bool
    {
        return $this->conductor()->giveUpOltBusyResume($draftId, $token, $reason);
    }

    public function handlePppoeQuery(string $groupJid, ?string $query): PsbDraft
    {
        return $this->commands()->handlePppoeQuery($groupJid, $query);
    }
}
