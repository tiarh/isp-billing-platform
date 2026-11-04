<?php

namespace App\Services\Psb;

use App\Exceptions\LockBusyException;
use App\Models\OltOnu;
use App\Models\PsbDraft;
use App\Models\Router;
use App\Services\MikrotikService;
use App\Services\PppoeSearchService;
use App\Services\PsbDocumentGateService;
use App\Services\PsbOrchestratorService;
use App\Services\PsbSiteRegistry;
use App\Services\PsbWaMessages;
use Illuminate\Support\Facades\Schema;

/**
 * PSB WA commands: the bodies of every `handle*` command of the orchestrator,
 * plus probeLiveForDraft and handlePppoeQuery.
 *
 * Moved as-is from PsbOrchestratorService (PR 4 of the PSB refactor, no
 * behaviour change). The orchestrator keeps a same-name protected delegator for
 * each moved name, and handleInbound still dispatches through them. Calls that
 * used to go through `$this` now go either to the host (the orchestrator, so
 * HuaweiAwarePsbOrchestratorService::processDraft and test-harness overrides of
 * reply/processDraft/applyPsbCorrection/cancelPsbCorrection still win) or to a
 * PsbSeams closure (hasMinPhoto, readVerifiedPppoePassword, siteResolver).
 */
final class PsbWaCommands
{
    public function __construct(
        private readonly PsbOrchestratorService $host,
        private readonly PsbWaMessages $messages,
        private readonly PsbDocumentGateService $documentGate,
        private readonly MikrotikService $mikrotik,
        private readonly PsbDraftIntake $intake,
        private readonly PsbSeams $seams,
    ) {}

    /**
     * P1 / P2.6 — #psb cek [#ID] (status + live/redaman sekali).
     * Tanpa ID → draft terakhir di grup (anti-spam: 1 balasan).
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $parsed
     */
    public function handleCek(array $payload, array $parsed): PsbDraft
    {
        $groupJid = (string) ($payload['group_jid'] ?? '');
        $draft = $this->intake->resolveDraftInGroup($groupJid, $parsed['draft_id'] ?? null, allowAnyStatus: true);
        if (! $draft) {
            if (! empty($parsed['draft_id'])) {
                $this->host->reply($groupJid, $this->messages->draftNotFound((int) $parsed['draft_id']));
            } else {
                $this->host->reply($groupJid, $this->messages->noDraftWaiting());
            }

            return new PsbDraft(['status' => 'ignored']);
        }

        $live = $this->probeLiveForDraft($draft);
        $routerCredential = ($this->seams->readVerifiedPppoePassword)($draft);
        $this->host->reply($groupJid, $this->messages->draftCheck($draft, $live, $routerCredential));

        return $draft;
    }

    /**
     * P2.5 — #psb antri (max 5 open drafts di grup, 1 balasan).
     *
     * @param  array<string, mixed>  $payload
     */
    public function handleAntri(array $payload): PsbDraft
    {
        $groupJid = (string) ($payload['group_jid'] ?? '');
        $rows = PsbDraft::query()
            ->where('group_jid', $groupJid)
            ->whereNotIn('status', [PsbDraft::STATUS_APPLIED, PsbDraft::STATUS_CANCELLED, PsbDraft::STATUS_OLT_OK])
            ->orderByDesc('id')
            ->limit(5)
            ->get(['id', 'status', 'full_name', 'sn', 'pon_port']);

        $this->host->reply($groupJid, $this->messages->queueList($rows->all()));

        return new PsbDraft(['status' => 'antri']);
    }

    /**
     * P2 — #psb kirim lagi #ID
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $parsed
     */
    public function handleResend(array $payload, array $parsed): PsbDraft
    {
        $groupJid = (string) ($payload['group_jid'] ?? '');
        $draft = $this->intake->resolveDraftInGroup($groupJid, $parsed['draft_id'] ?? null, allowAnyStatus: true);
        if (! $draft) {
            if (empty($parsed['draft_id'])) {
                $this->host->reply($groupJid, $this->messages->needDraftId('#psb kirim lagi'));
            } else {
                $this->host->reply($groupJid, $this->messages->draftNotFound((int) $parsed['draft_id']));
            }

            return new PsbDraft(['status' => 'ignored']);
        }

        $routerCredential = ($this->seams->readVerifiedPppoePassword)($draft);
        $this->host->reply($groupJid, $this->messages->resendCredentials($draft, $this->host->acsUrl(), $routerCredential));

        return $draft;
    }

    /**
     * P3 — #psb batal #ID (soft cancel, no MT/OLT delete)
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $parsed
     */
    public function handleBatal(array $payload, array $parsed): PsbDraft
    {
        $groupJid = (string) ($payload['group_jid'] ?? '');
        $draft = $this->intake->resolveDraftInGroup($groupJid, $parsed['draft_id'] ?? null, allowAnyStatus: true);
        if (! $draft) {
            if (empty($parsed['draft_id'])) {
                $this->host->reply($groupJid, $this->messages->needDraftId('#psb batal'));
            } else {
                $this->host->reply($groupJid, $this->messages->draftNotFound((int) $parsed['draft_id']));
            }

            return new PsbDraft(['status' => 'ignored']);
        }

        if ($draft->status === PsbDraft::STATUS_CORRECTION_PENDING) {
            try {
                $this->host->cancelPsbCorrection($draft, $payload['sender_name'] ?? $payload['sender_jid'] ?? null);
            } catch (LockBusyException $e) {
                $this->host->reply($groupJid, $this->messages->psbCorrectionBlocked($draft, $e->getMessage()));

                return $draft->fresh() ?? $draft;
            }
            $this->host->reply($groupJid, $this->messages->psbCorrectionCancelled($draft->fresh() ?? $draft));

            return $draft->fresh() ?? $draft;
        }

        if ($draft->status === PsbDraft::STATUS_APPLIED) {
            $this->host->reply($groupJid, $this->messages->draftStatusSkip((int) $draft->id, (string) $draft->status));

            return $draft;
        }

        $draft->status = PsbDraft::STATUS_CANCELLED;
        $draft->last_error = 'Dibatalkan via WA (soft — secret/ONU tidak dihapus)';
        $draft->sn_wait_until = null;
        $meta = is_array($draft->meta) ? $draft->meta : [];
        $meta['cancelled_at'] = now()->toIso8601String();
        $meta['cancelled_by'] = $payload['sender_name'] ?? $payload['sender_jid'] ?? null;
        unset($meta['olt_busy_resume'], $meta['pending_uncfg_sn'], $meta['pending_uncfg_port'], $meta['pending_uncfg_onu_id']);
        $draft->meta = $meta;
        $draft->save();
        $this->host->reply($groupJid, $this->messages->cancelled($draft));

        return $draft;
    }

    /**
     * P4 — #psb sn FHTT… [optional #draft]
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $parsed
     */
    public function handleSnCmd(array $payload, array $parsed): PsbDraft
    {
        $groupJid = (string) ($payload['group_jid'] ?? '');
        $sn = $parsed['sn'] ?? null;
        if (! $sn) {
            $this->host->reply($groupJid, "⚠️ Format: `#psb sn FHTT12345678`\nOpsional: `#psb sn FHTT… #42`");

            return new PsbDraft(['status' => 'ignored']);
        }

        $snStatuses = PsbDraft::REPROCESSABLE_STATUSES;

        $draftId = $parsed['draft_id'] ?? null;
        if (! $draftId) {
            $candidates = PsbDraft::query()
                ->where('group_jid', $groupJid)
                ->whereIn('status', $snStatuses)
                ->orderByDesc('id')
                ->limit(5)
                ->get();

            if ($candidates->count() > 1) {
                $rows = $candidates->map(function (PsbDraft $d) {
                    $name = trim((string) ($d->full_name ?: '-'));
                    $area = trim((string) ($d->daerah ?: $d->site_profile_key ?: '-'));

                    return "#{$d->id} - {$name} ({$area})";
                })->implode("\n");

                $this->host->reply($groupJid, "⚠️ Ada beberapa draft aktif. Ganti SN wajib pakai #ID agar tidak salah pelanggan.\n\n{$rows}\n\nFormat: `#psb sn {$sn} #ID`");

                return new PsbDraft(['status' => 'ambiguous_sn']);
            }
        }

        $draft = $this->intake->resolveDraftInGroup(
            $groupJid,
            $draftId,
            allowAnyStatus: false,
            statuses: $snStatuses
        );

        if (! $draft) {
            $this->host->reply($groupJid, $this->messages->noDraftWaiting());

            return new PsbDraft(['status' => 'ignored']);
        }
        if ($draft->isFinishedHtb()) {
            $this->host->reply($groupJid, $this->messages->draftNotReprocessable((int) $draft->id, (string) $draft->status));

            return $draft;
        }

        $old = (string) ($draft->sn ?? '');
        $draft->sn = $sn;
        $draft->last_error = null;
        $draft->status = PsbDraft::STATUS_RECEIVED;
        $meta = is_array($draft->meta) ? $draft->meta : [];
        unset($meta['pending_uncfg_sn'], $meta['pending_uncfg_port'], $meta['pending_uncfg_onu_id']);
        $draft->meta = $meta;
        $draft->save();
        $this->host->reply($groupJid, $this->messages->snUpdated($draft, $old));

        return $this->host->processDraft($draft->fresh());
    }

    /**
     * P6 — #psb ya #ID → pakai SN uncfg yang ditunda.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $parsed
     */
    public function handleConfirmSn(array $payload, array $parsed): PsbDraft
    {
        $groupJid = (string) ($payload['group_jid'] ?? '');
        $draft = $this->intake->resolveDraftInGroup($groupJid, $parsed['draft_id'] ?? null, allowAnyStatus: true);
        if (! $draft) {
            if (empty($parsed['draft_id'])) {
                $this->host->reply($groupJid, $this->messages->needDraftId('#psb ya'));
            } else {
                $this->host->reply($groupJid, $this->messages->draftNotFound((int) $parsed['draft_id']));
            }

            return new PsbDraft(['status' => 'ignored']);
        }

        if ($draft->status === PsbDraft::STATUS_CORRECTION_PENDING) {
            $this->host->reply($groupJid, $this->messages->psbCorrectionPending($draft));

            return $draft;
        }
        if (! in_array($draft->status, PsbDraft::REPROCESSABLE_STATUSES, true)) {
            $this->host->reply($groupJid, $this->messages->draftNotReprocessable((int) $draft->id, (string) $draft->status));

            return $draft;
        }

        $meta = is_array($draft->meta) ? $draft->meta : [];
        $uncfgSn = $meta['pending_uncfg_sn'] ?? null;
        if (! $uncfgSn) {
            $this->host->reply($groupJid, "ℹ️ Draft #{$draft->id} tidak menunggu konfirmasi SN.\nCek: *#psb cek #{$draft->id}*");

            return $draft;
        }

        $old = (string) ($draft->sn ?? '');
        $draft->sn = strtoupper((string) $uncfgSn);
        if (! empty($meta['pending_uncfg_port'])) {
            $draft->pon_port = $meta['pending_uncfg_port'];
        }
        if (! empty($meta['pending_uncfg_onu_id'])) {
            $draft->onu_id = (int) $meta['pending_uncfg_onu_id'];
        }
        unset($meta['pending_uncfg_sn'], $meta['pending_uncfg_port'], $meta['pending_uncfg_onu_id']);
        $draft->meta = $meta;
        $draft->last_error = null;
        $draft->status = PsbDraft::STATUS_RECEIVED;
        $draft->save();
        $this->host->reply($groupJid, $this->messages->snUpdated($draft, $old));

        return $this->host->processDraft($draft->fresh());
    }

    /**
     * @return array{pppoe_live: ?bool, pppoe_detail: ?string, rx_dbm: ?float, rx_note: ?string}
     */
    public function probeLiveForDraft(PsbDraft $draft): array
    {
        $out = [
            'pppoe_live' => null,
            'pppoe_detail' => null,
            'rx_dbm' => null,
            'rx_note' => null,
        ];

        // Redaman dari inventory (DB) — tidak spam OLT CLI
        if ($draft->sn && Schema::hasTable('olt_onus')) {
            try {
                $onu = OltOnu::query()
                    ->whereRaw('UPPER(serial_number) = ?', [strtoupper((string) $draft->sn)])
                    ->orderByDesc('rx_collected_at')
                    ->orderByDesc('id')
                    ->first();
                if ($onu && $onu->rx_power_dbm !== null) {
                    $out['rx_dbm'] = (float) $onu->rx_power_dbm;
                } elseif ($onu) {
                    $out['rx_note'] = 'ONU ada di inventory, RX belum terisi';
                }
            } catch (\Throwable $e) {
                $out['rx_note'] = 'Gagal baca inventory RX';
            }
        }

        // PPPoE active di RA-OLT (read-only print active)
        if ($draft->pppoe_user && $draft->router_id) {
            $router = Router::find($draft->router_id) ?: $this->host->resolveRouter();
            if ($router) {
                try {
                    $this->mikrotik->connect($router, ['timeout' => 12, 'attempts' => 1]);
                    try {
                        $actives = $this->mikrotik->getActiveConnections(false);
                        $user = (string) $draft->pppoe_user;
                        $hit = null;
                        foreach ($actives as $a) {
                            $n = (string) ($a['name'] ?? $a['user'] ?? '');
                            if (strcasecmp($n, $user) === 0) {
                                $hit = $a;
                                break;
                            }
                        }
                        if ($hit) {
                            $out['pppoe_live'] = true;
                            $addr = $hit['address'] ?? $hit['caller-id'] ?? '';
                            $out['pppoe_detail'] = $addr !== '' ? (string) $addr : 'session up';
                        } else {
                            $out['pppoe_live'] = false;
                            $out['pppoe_detail'] = 'secret mungkin ada, belum dial';
                        }
                    } finally {
                        $this->mikrotik->disconnect();
                    }
                } catch (\Throwable $e) {
                    $out['pppoe_live'] = null;
                    $out['pppoe_detail'] = 'MT unreachable';
                }
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $parsed
     */
    public function handleLanjut(array $payload, array $parsed): PsbDraft
    {
        $groupJid = (string) ($payload['group_jid'] ?? '');
        $sn = $parsed['sn'] ?? null;
        $draftId = isset($parsed['draft_id']) ? (int) $parsed['draft_id'] : null;
        $draft = $this->intake->findLanjutDraft($groupJid, $sn, $draftId);

        if (! $draft) {
            $this->host->reply(
                $groupJid,
                $draftId ? $this->messages->draftNotFound($draftId) : $this->messages->noDraftWaiting()
            );

            return new PsbDraft(['status' => PsbDraft::STATUS_FAILED]);
        }

        if ($draft->status === PsbDraft::STATUS_CORRECTION_PENDING) {
            try {
                $this->host->applyPsbCorrection($draft);
                $this->host->reply($groupJid, $this->messages->psbCorrectionApplied($draft->fresh() ?? $draft));
            } catch (\Throwable $e) {
                $this->host->reply($groupJid, $this->messages->psbCorrectionBlocked($draft, $e->getMessage()));
            }

            return $draft->fresh() ?? $draft;
        }

        if ($sn) {
            $draft->sn = $sn;
        }
        if (! ($this->seams->hasMinPhoto)($draft)) {
            $gate = $this->documentGate->evaluate($draft);
            $draft->last_error = 'Dokumen PSB belum valid: '.($gate['status'] ?? 'missing');
            $meta = is_array($draft->meta) ? $draft->meta : [];
            $meta['waiting_photo'] = true;
            $draft->meta = $meta;
            $draft->status = PsbDraft::STATUS_RECEIVED;
            $draft->save();
            $this->host->reply($groupJid, $this->messages->documentBlocked($draft, $gate));

            return $draft;
        }
        $draft->last_error = null;
        $draft->status = PsbDraft::STATUS_RECEIVED;
        $draft->save();
        $this->host->reply($groupJid, $this->messages->lanjutAck($draft));

        return $this->host->processDraft($draft->fresh());
    }

    /**
     * #psb router ra|rdg|bumiayu|… [#ID]
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $parsed
     */
    public function handleRouterCmd(array $payload, array $parsed): PsbDraft
    {
        $groupJid = (string) ($payload['group_jid'] ?? '');
        $alias = (string) ($parsed['router_alias'] ?? '');
        if (empty($parsed['draft_id']) && $this->intake->openDraftsForCmd($groupJid)->count() > 1) {
            $this->host->reply($groupJid, $this->messages->severalDraftsNeedId());

            return new PsbDraft(['status' => 'ambiguous_cmd']);
        }
        $draft = $this->intake->findDraftForCmd($groupJid, $parsed);
        if (! $draft) {
            $this->host->reply($groupJid, ! empty($parsed['draft_id']) ? $this->messages->draftNotFound((int) $parsed['draft_id']) : $this->messages->noDraftWaiting());

            return new PsbDraft(['status' => PsbDraft::STATUS_FAILED]);
        }
        if ($draft->status === PsbDraft::STATUS_CORRECTION_PENDING) {
            $this->host->reply($groupJid, $this->messages->psbCorrectionPending($draft));

            return $draft;
        }
        if (! $draft->isReprocessable()) {
            $this->host->reply($groupJid, $this->messages->draftNotReprocessable((int) $draft->id, (string) $draft->status));

            return $draft;
        }
        $router = ($this->seams->siteResolver)()->resolveRouterAlias($alias);
        if (! $router) {
            $this->host->reply($groupJid, "⚠️ Router `{$alias}` tidak dikenali.\nContoh: *#psb router ra* · *#psb router krian* · *#psb router rdg* · *#psb router bumiayu* · *#psb router gadang*");

            return $draft;
        }
        $meta = is_array($draft->meta) ? $draft->meta : [];
        $meta['forced_router_id'] = $router->id;
        $registrySite = app(PsbSiteRegistry::class)->byKey($alias);
        if ($registrySite) {
            $meta['forced_site_key'] = (string) ($registrySite['key'] ?? '');
        }
        $draft->meta = $meta;
        $draft->router_id = $router->id;
        $draft->last_error = null;
        $draft->status = PsbDraft::STATUS_RECEIVED;
        $draft->save();
        $this->host->reply($groupJid, "✅ Draft *#{$draft->id}* → MikroTik *{$router->name}*\nLanjut proses…");

        return $this->host->processDraft($draft->fresh() ?? $draft);
    }

    /**
     * #psb vlan 100 [#ID]
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $parsed
     */
    public function handleVlanCmd(array $payload, array $parsed): PsbDraft
    {
        $groupJid = (string) ($payload['group_jid'] ?? '');
        $vlan = (int) ($parsed['vlan_value'] ?? 0);
        if (empty($parsed['draft_id']) && $this->intake->openDraftsForCmd($groupJid)->count() > 1) {
            $this->host->reply($groupJid, $this->messages->severalDraftsNeedId());

            return new PsbDraft(['status' => 'ambiguous_cmd']);
        }
        $draft = $this->intake->findDraftForCmd($groupJid, $parsed);
        if (! $draft) {
            $this->host->reply($groupJid, ! empty($parsed['draft_id']) ? $this->messages->draftNotFound((int) $parsed['draft_id']) : $this->messages->noDraftWaiting());

            return new PsbDraft(['status' => PsbDraft::STATUS_FAILED]);
        }
        if ($draft->status === PsbDraft::STATUS_CORRECTION_PENDING) {
            $this->host->reply($groupJid, $this->messages->psbCorrectionPending($draft));

            return $draft;
        }
        if (! $draft->isReprocessable()) {
            $this->host->reply($groupJid, $this->messages->draftNotReprocessable((int) $draft->id, (string) $draft->status));

            return $draft;
        }
        if ($vlan < 1 || $vlan > 4094) {
            $this->host->reply($groupJid, '⚠️ VLAN harus 1–4094. Contoh: *#psb vlan 100 #'.$draft->id.'*');

            return $draft;
        }
        $meta = is_array($draft->meta) ? $draft->meta : [];
        $meta['forced_vlan'] = $vlan;
        $draft->meta = $meta;
        $draft->vlan = $vlan;
        $draft->last_error = null;
        $draft->status = PsbDraft::STATUS_RECEIVED;
        $draft->save();
        $this->host->reply($groupJid, "✅ Draft *#{$draft->id}* VLAN *{$vlan}*\nLanjut proses…");

        return $this->host->processDraft($draft->fresh() ?? $draft);
    }

    /**
     * NOC finished VLAN mapping → resume pending_vlan draft(s).
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $parsed
     */
    public function handleDone(array $payload, array $parsed): PsbDraft
    {
        $groupJid = (string) ($payload['group_jid'] ?? '');
        $draftId = $parsed['draft_id'] ?? null;

        $q = PsbDraft::query()
            ->where('group_jid', $groupJid)
            ->where('status', PsbDraft::STATUS_PENDING_VLAN)
            ->orderByDesc('id');

        if ($draftId) {
            $draft = PsbDraft::query()
                ->where('group_jid', $groupJid)
                ->where('id', $draftId)
                ->first();
            if (! $draft) {
                $this->host->reply($groupJid, $this->messages->draftNotFound((int) $draftId));

                return new PsbDraft(['status' => PsbDraft::STATUS_FAILED]);
            }
            if ($draft->status !== PsbDraft::STATUS_PENDING_VLAN
                && ! in_array($draft->status, [PsbDraft::STATUS_WAITING_SN, PsbDraft::STATUS_TIMEOUT, PsbDraft::STATUS_FAILED, PsbDraft::STATUS_SECRET_OK], true)) {
                $this->host->reply($groupJid, $this->messages->draftStatusSkip((int) $draftId, (string) $draft->status));

                return $draft;
            }
        } else {
            $draft = $q->first();
        }

        if (! $draft) {
            $this->host->reply($groupJid, $this->messages->noPendingVlan());

            return new PsbDraft(['status' => 'ignored']);
        }

        $this->host->reply($groupJid, $this->messages->doneAck($draft));
        $draft->status = PsbDraft::STATUS_RECEIVED;
        $draft->last_error = null;
        $draft->save();

        return $this->host->processDraft($draft->fresh());
    }

    public function handlePppoeQuery(string $groupJid, ?string $query): PsbDraft
    {
        // Search + formatting live in PppoeSearchService (shared with the Skynet Report group).
        $result = (new PppoeSearchService($this->mikrotik))->search($query);
        $this->host->reply($groupJid, $result['text']);

        return new PsbDraft(['status' => $result['status']]);
    }
}
