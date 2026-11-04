<?php

namespace App\Services\Psb;

use App\Models\PsbDraft;
use Illuminate\Support\Collection;

/**
 * PSB WA: draft intake. Finds the draft a new #psb message belongs to, refuses
 * an SN reused for another customer, copies parsed fields into a merged draft,
 * and resolves the draft a WA command talks about.
 *
 * Moved as-is from PsbOrchestratorService (PR 2 of the PSB refactor, no
 * behaviour change). The orchestrator keeps same-name delegators, so callers
 * and test harnesses still go through the facade. isLivePsbDraft and
 * psbCorrectionSnapshot are reached through the facade (closures), so an
 * override there still wins.
 */
final class PsbDraftIntake
{
    /**
     * @param  \Closure(array<string, mixed>): bool  $isLivePsbDraft  the orchestrator's isLivePsbDraft
     * @param  \Closure(PsbDraft): array<string, mixed>  $psbCorrectionSnapshot  the orchestrator's psbCorrectionSnapshot
     */
    public function __construct(
        private readonly \Closure $isLivePsbDraft,
        private readonly \Closure $psbCorrectionSnapshot,
    ) {}

    /**
     * Resend #psb dalam window → update draft lama (anti draft dobel).
     *
     * @param  array<string, mixed>  $parsed
     */
    public function findMergeableDraft(string $groupJid, ?string $senderJid, array $parsed): ?PsbDraft
    {
        if ($groupJid === '') {
            return null;
        }
        $candidates = $this->mergeCandidates($groupJid, $senderJid);
        if ($candidates->isEmpty()) {
            return null;
        }

        $name = strtoupper(preg_replace('/\s+/', '', (string) ($parsed['full_name'] ?? '')) ?? '');
        $sn = strtoupper(preg_replace('/\s+/', '', (string) ($parsed['sn'] ?? '')) ?? '');
        $strictLiveMergeStatuses = [
            PsbDraft::STATUS_SECRET_OK,
            PsbDraft::STATUS_OLT_OK,
            PsbDraft::STATUS_APPLIED,
            PsbDraft::STATUS_CORRECTION_PENDING,
        ];

        $mergeable = [
            PsbDraft::STATUS_FAILED,
            PsbDraft::STATUS_WAITING_SN,
            PsbDraft::STATUS_RECEIVED,
            PsbDraft::STATUS_TIMEOUT,
            PsbDraft::STATUS_PENDING_VLAN,
            PsbDraft::STATUS_SECRET_OK,
            PsbDraft::STATUS_OLT_OK, // ganti SN / resend lengkap
            PsbDraft::STATUS_APPLIED, // live correction pending, not a new draft
            PsbDraft::STATUS_CORRECTION_PENDING,
        ];

        foreach ($candidates as $c) {
            if (! in_array($c->status, $mergeable, true)) {
                continue;
            }
            $cSn = strtoupper(preg_replace('/\s+/', '', (string) ($c->sn ?? '')) ?? '');
            $cName = strtoupper(preg_replace('/\s+/', '', (string) ($c->full_name ?? '')) ?? '');
            $strictLiveMerge = in_array($c->status, $strictLiveMergeStatuses, true);
            if ($sn !== '' && $cSn !== '' && $sn === $cSn) {
                return $c;
            }
            if ($name !== '' && $cName !== '') {
                if ($name === $cName) {
                    return $c;
                }
                if ($strictLiveMerge) {
                    continue;
                }
                if (levenshtein($name, $cName) <= 3) {
                    return $c;
                }
                $rawName = strtoupper(trim((string) ($parsed['full_name'] ?? '')));
                $rawCName = strtoupper(trim((string) ($c->full_name ?? '')));
                $w1 = explode(' ', $rawName)[0] ?? '';
                $w2 = explode(' ', $rawCName)[0] ?? '';
                if ($w1 !== '' && $w1 === $w2 && strlen($w1) >= 3) {
                    return $c;
                }
            }
        }

        // Same sender, last open/failed without name match
        // HANYA JIKA parsed tidak mengandung nama baru (misal update foto)
        if ($name === '') {
            $open = $candidates->first(fn (PsbDraft $c) => in_array($c->status, [
                PsbDraft::STATUS_FAILED,
                PsbDraft::STATUS_WAITING_SN,
                PsbDraft::STATUS_RECEIVED,
                PsbDraft::STATUS_TIMEOUT,
                PsbDraft::STATUS_PENDING_VLAN,
            ], true));

            return $open;
        }

        return null;
    }

    /** @return Collection<int, PsbDraft> */
    public function mergeCandidates(string $groupJid, ?string $senderJid)
    {
        $windowMin = 60;
        $q = PsbDraft::query()
            ->where('group_jid', $groupJid)
            ->where('created_at', '>=', now()->subMinutes($windowMin))
            ->whereNotIn('status', [PsbDraft::STATUS_CANCELLED])
            ->orderByDesc('id')
            ->limit(15);

        if ($senderJid) {
            $q->where('sender_jid', $senderJid);
        }

        return $q->get();
    }

    /**
     * A new #psb whose SN matches a LIVE draft but whose customer is clearly
     * another person (name AND phone/NIK differ). Returns that draft; the caller
     * must refuse the message without touching any draft, secret or customer.
     *
     * @param  array<string, mixed>  $parsed
     */
    public function findSnReuseConflict(string $groupJid, ?string $senderJid, array $parsed): ?PsbDraft
    {
        $sn = strtoupper(preg_replace('/\s+/', '', (string) ($parsed['sn'] ?? '')) ?? '');
        if ($groupJid === '' || $sn === '') {
            return null;
        }
        foreach ($this->mergeCandidates($groupJid, $senderJid) as $c) {
            $cSn = strtoupper(preg_replace('/\s+/', '', (string) ($c->sn ?? '')) ?? '');
            if ($cSn === '' || $cSn !== $sn) {
                continue;
            }
            // Same pick as findMergeableDraft: the first SN match decides.
            if (! ($this->isLivePsbDraft)(($this->psbCorrectionSnapshot)($c))) {
                return null;
            }

            return $this->isClearlyOtherCustomer($c, $parsed) ? $c : null;
        }

        return null;
    }

    /** @param array<string, mixed> $parsed */
    public function isClearlyOtherCustomer(PsbDraft $c, array $parsed): bool
    {
        $rawName = strtoupper(trim((string) ($parsed['full_name'] ?? '')));
        $rawCName = strtoupper(trim((string) ($c->full_name ?? '')));
        $name = preg_replace('/\s+/', '', $rawName) ?? '';
        $cName = preg_replace('/\s+/', '', $rawCName) ?? '';
        if ($name === '' || $cName === '') {
            return false;
        }
        // Same customer: equal, a small typo, or one name is a whole-word prefix of the other.
        $words = fn (string $n): array => array_values(array_filter(explode(' ', $n), fn ($w) => $w !== ''));
        $w1 = $words($rawName);
        $w2 = $words($rawCName);
        $n = min(count($w1), count($w2));
        $wholeWordPrefix = $n > 0 && array_slice($w1, 0, $n) === array_slice($w2, 0, $n);
        if ($name === $cName || levenshtein($name, $cName) <= 3 || $wholeWordPrefix) {
            return false;
        }

        // The parser gives no NIK, so the phone is the only other signal.
        $phone = $this->normalizePsbPhone($parsed['phone'] ?? null);
        $cPhone = $this->normalizePsbPhone($c->phone);

        return $phone !== '' && $cPhone !== '' && $phone !== $cPhone;
    }

    /**
     * A new #psb with the exact name of a LIVE draft, but a different SN and a
     * different phone, is another customer with the same name: refuse, do not
     * turn it into a correction. Empty SN or phone on either side: not decided
     * here (today's merge/correction path stays).
     *
     * @param  array<string, mixed>  $parsed
     */
    public function isSameNameOtherLiveCustomer(PsbDraft $candidate, array $parsed): bool
    {
        $live = [
            PsbDraft::STATUS_SECRET_OK,
            PsbDraft::STATUS_OLT_OK,
            PsbDraft::STATUS_APPLIED,
            PsbDraft::STATUS_CORRECTION_PENDING,
        ];
        if (! in_array($candidate->status, $live, true)) {
            return false;
        }
        $name = strtoupper(preg_replace('/\s+/', '', (string) ($parsed['full_name'] ?? '')) ?? '');
        $cName = strtoupper(preg_replace('/\s+/', '', (string) ($candidate->full_name ?? '')) ?? '');
        if ($name === '' || $cName === '' || $name !== $cName) {
            return false;
        }
        $sn = strtoupper(preg_replace('/\s+/', '', (string) ($parsed['sn'] ?? '')) ?? '');
        $cSn = strtoupper(preg_replace('/\s+/', '', (string) ($candidate->sn ?? '')) ?? '');
        if ($sn === '' || $cSn === '' || $sn === $cSn) {
            return false;
        }
        $phone = $this->normalizePsbPhone($parsed['phone'] ?? null);
        $cPhone = $this->normalizePsbPhone($candidate->phone);

        return $phone !== '' && $cPhone !== '' && $phone !== $cPhone;
    }

    public function normalizePsbPhone(mixed $phone): string
    {
        $digits = preg_replace('/\D/', '', (string) ($phone ?? '')) ?? '';
        if (str_starts_with($digits, '62')) {
            $digits = '0'.substr($digits, 2);
        }

        return $digits;
    }

    /**
     * The merge field copy of handleInbound (the "$isUpdate" branch), moved
     * statement for statement. Returns the merged draft (same instance).
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $parsed
     */
    public function applyParsedToDraft(PsbDraft $merged, array $payload, array $parsed, mixed $messageId, string $oldSn): PsbDraft
    {
        $draft = $merged;
        $draft->message_id = $messageId ?: $draft->message_id;
        $draft->sender_name = $payload['sender_name'] ?? $draft->sender_name;
        $draft->raw_text = $parsed['raw'];
        if (! empty($parsed['full_name'])) {
            $draft->full_name = $parsed['full_name'];
        }
        if (! empty($parsed['rt'])) {
            $draft->rt = $parsed['rt'];
        }
        if (! empty($parsed['rw'])) {
            $draft->rw = $parsed['rw'];
        }
        if (! empty($parsed['odp'])) {
            $draft->odp = $parsed['odp'];
        }
        if (! empty($parsed['phone'])) {
            $draft->phone = $parsed['phone'];
        }
        if (! empty($parsed['tikor'])) {
            $draft->tikor = $parsed['tikor'];
        }
        if (! empty($parsed['daerah'])) {
            $draft->daerah = $parsed['daerah'];
        }
        if (! empty($parsed['vlan_value'])) {
            $draft->vlan = (int) $parsed['vlan_value'];
        }
        $newSn = strtoupper((string) ($parsed['sn'] ?? ''));
        if ($newSn !== '') {
            $draft->sn = $newSn;
        }
        // SN ganti setelah OLT ok → re-provision, jaga secret
        if ($newSn !== '' && $oldSn !== '' && $newSn !== $oldSn
            && in_array($draft->status, [PsbDraft::STATUS_OLT_OK, PsbDraft::STATUS_SECRET_OK], true)) {
            $draft->status = PsbDraft::STATUS_SECRET_OK;
            $draft->olt_at = null;
            $meta = is_array($draft->meta) ? $draft->meta : [];
            unset($meta['olt_provisioned_at'], $meta['olt_last_response']);
            $draft->meta = $meta;
        } elseif (! in_array($draft->status, PsbDraft::PHOTO_LOCKED_STATUSES, true)) {
            $draft->status = PsbDraft::STATUS_RECEIVED;
        }
        // A pending correction keeps its "menunggu approval" note.
        if ($draft->status !== PsbDraft::STATUS_CORRECTION_PENDING) {
            $draft->last_error = null;
        }

        return $draft;
    }

    /**
     * @param  list<string>|null  $statuses
     */
    public function resolveDraftInGroup(
        string $groupJid,
        ?int $draftId,
        bool $allowAnyStatus = false,
        ?array $statuses = null,
    ): ?PsbDraft {
        if ($draftId) {
            $q = PsbDraft::query()->where('group_jid', $groupJid)->where('id', $draftId);
            if (! $allowAnyStatus && $statuses) {
                $q->whereIn('status', $statuses);
            }

            return $q->first();
        }

        $q = PsbDraft::query()->where('group_jid', $groupJid)->orderByDesc('id');
        if (! $allowAnyStatus) {
            $q->whereIn('status', $statuses ?? [
                PsbDraft::STATUS_WAITING_SN,
                PsbDraft::STATUS_PENDING_VLAN,
                PsbDraft::STATUS_TIMEOUT,
                PsbDraft::STATUS_FAILED,
                PsbDraft::STATUS_RECEIVED,
                PsbDraft::STATUS_SECRET_OK,
            ]);
        }

        return $q->first();
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    public function findDraftForCmd(string $groupJid, array $parsed): ?PsbDraft
    {
        $id = $parsed['draft_id'] ?? null;
        if ($id) {
            // A #ID from another group is never used.
            return PsbDraft::query()->whereKey($id)->where('group_jid', $groupJid)->first();
        }

        return PsbDraft::query()
            ->where('group_jid', $groupJid)
            // Correction pending stays pickable so the handlers answer "menunggu approval"
            // instead of silently acting on an older draft.
            ->whereIn('status', [...PsbDraft::REPROCESSABLE_STATUSES, PsbDraft::STATUS_CORRECTION_PENDING])
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            // A finished HTB draft is never picked without #ID.
            ->first(fn (PsbDraft $d): bool => ! $d->isFinishedHtb());
    }

    /**
     * Open drafts of a group that router/vlan without #ID could act on
     * (same set `#psb sn` counts; correction_pending is not counted).
     *
     * @return Collection<int, PsbDraft>
     */
    public function openDraftsForCmd(string $groupJid): Collection
    {
        return PsbDraft::query()
            ->where('group_jid', $groupJid)
            ->whereIn('status', PsbDraft::REPROCESSABLE_STATUSES)
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->reject(fn (PsbDraft $d): bool => $d->isFinishedHtb())
            ->values();
    }

    /**
     * The draft query of `#psb lanjut`, moved statement for statement from
     * PsbOrchestratorService::handleLanjut (PR 4).
     */
    public function findLanjutDraft(string $groupJid, mixed $sn, ?int $draftId): ?PsbDraft
    {
        $q = PsbDraft::query()
            ->where('group_jid', $groupJid)
            ->whereIn('status', [
                PsbDraft::STATUS_WAITING_SN,
                PsbDraft::STATUS_PENDING_VLAN,
                PsbDraft::STATUS_TIMEOUT,
                PsbDraft::STATUS_FAILED,
                PsbDraft::STATUS_SECRET_OK,
                PsbDraft::STATUS_RECEIVED, // waiting foto
                PsbDraft::STATUS_CORRECTION_PENDING,
            ])
            ->orderByDesc('id');

        if ($draftId) {
            $draft = (clone $q)->whereKey($draftId)->first();
        } elseif ($sn) {
            $draft = (clone $q)->where('sn', $sn)->first() ?: $q->first();
        } else {
            $draft = $q->first();
        }

        return $draft;
    }
}
