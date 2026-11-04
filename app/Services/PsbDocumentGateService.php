<?php

namespace App\Services;

use App\Models\PsbDraft;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

class PsbDocumentGateService
{
    /** @return array<string, mixed> */
    public function evaluate(PsbDraft $draft, bool $persist = true): array
    {
        if (! $this->isEnforced($draft)) {
            return ['allowed' => $this->hasLegacyPhoto($draft), 'status' => 'legacy'];
        }

        $meta = is_array($draft->meta) ? $draft->meta : [];
        $photo = $this->verifyPhoto($draft, $meta);
        if ($photo['valid']) {
            $state = $this->state('verified', true, $meta, ['evidence' => $photo]);
        } else {
            $policy = is_array($meta['document_policy'] ?? null) ? $meta['document_policy'] : [];
            $state = match ($policy['type'] ?? null) {
                'pending_followup' => $this->pendingState($policy, $meta),
                'exempt_csr' => $this->csrState($draft, $policy, $meta),
                default => $this->state(
                    $draft->ktp_photo_path ? 'invalid_media' : 'missing',
                    false,
                    $meta,
                    ['reason' => $photo['reason']]
                ),
            };
        }

        if ($persist && $draft->exists) {
            $old = is_array($meta['document_gate'] ?? null) ? $meta['document_gate'] : [];
            $stableOld = $old;
            unset($stableOld['checked_at']);
            $stableNew = $state;
            unset($stableNew['checked_at']);
            if ($stableOld !== $stableNew) {
                $meta['document_gate'] = $state;
                $draft->meta = $meta;
                $draft->save();
            }
        }

        return $state;
    }

    public function isEnforced(PsbDraft $draft): bool
    {
        if (! filter_var(Setting::get('psb_document_gate_enabled', false), FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }

        $cutoff = trim((string) Setting::get('psb_document_gate_enforced_after', ''));
        if ($cutoff === '' || ! $draft->created_at) {
            return true;
        }

        try {
            return $draft->created_at->gte(Carbon::parse($cutoff));
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    private function verifyPhoto(PsbDraft $draft, array $meta): array
    {
        $path = trim((string) $draft->ktp_photo_path);
        if ($path === '') {
            return ['valid' => false, 'reason' => 'ktp_missing'];
        }
        if (preg_match('#/(sn_|router_)#i', $path)) {
            return ['valid' => false, 'reason' => 'wrong_media_path'];
        }

        $class = is_array($meta['photo_class'] ?? null) ? $meta['photo_class'] : [];
        if (array_key_exists('ktp_score', $class) && $class['ktp_score'] !== null && (int) $class['ktp_score'] < 25) {
            return ['valid' => false, 'reason' => 'ktp_score_too_low'];
        }

        try {
            $disk = Storage::disk(config('filesystems.default'));
            if (! $disk->exists($path)) {
                return ['valid' => false, 'reason' => 'object_missing'];
            }
            $size = (int) $disk->size($path);
            if ($size <= 0 || $size > 15 * 1024 * 1024) {
                return ['valid' => false, 'reason' => 'invalid_size'];
            }
            $bytes = $disk->get($path);
            $image = @getimagesizefromstring($bytes);
            $mime = is_array($image) ? (string) ($image['mime'] ?? '') : '';
            if (! in_array($mime, ['image/jpeg', 'image/png'], true)) {
                return ['valid' => false, 'reason' => 'invalid_image'];
            }

            return [
                'valid' => true,
                'path' => $path,
                'size' => $size,
                'mime' => $mime,
                'sha256' => hash('sha256', $bytes),
            ];
        } catch (\Throwable) {
            return ['valid' => false, 'reason' => 'storage_unavailable'];
        }
    }

    /** @return array<string, mixed> */
    private function pendingState(array $policy, array $meta): array
    {
        $reason = trim((string) ($policy['reason'] ?? ''));
        $dueAt = trim((string) ($policy['due_at'] ?? ''));
        $validDue = false;
        try {
            $validDue = $dueAt !== '' && Carbon::parse($dueAt, 'Asia/Jakarta')->endOfDay()->isFuture();
        } catch (\Throwable) {
        }

        return $this->state('pending_followup', $reason !== '' && $validDue, $meta, [
            'reason' => $reason !== '' ? $reason : 'reason_required',
            'due_at' => $dueAt ?: null,
        ]);
    }

    /** @return array<string, mixed> */
    private function csrState(PsbDraft $draft, array $policy, array $meta): array
    {
        $package = $draft->package ?: ($draft->package_id ? $draft->package()->first() : null);
        $haystack = strtoupper(implode(' ', [
            (string) $draft->full_name,
            (string) ($package?->name ?? ''),
            (string) ($package?->mikrotik_profile ?? ''),
        ]));
        $eligible = str_contains($haystack, 'CSR')
            || (bool) preg_match('/\b(SEKOLAH|MADRASAH|PONDOK|PESANTREN|YAYASAN|MASJID|MUSHOLA|MI|MTS|MA|SD|SMP|SMA|SMK'
                .'|BALAI\s+DESA|KANTOR\s+DESA|POSKAMLING|POSYANDU|PUSKESMAS|GEREJA|PURA|VIHARA|KLENTENG|LANGGAR|SURAU|TPQ)\b/u', $haystack);
        $reason = trim((string) ($policy['reason'] ?? 'CSR'));

        return $this->state('exempt_csr', $eligible, $meta, [
            'reason' => $eligible ? $reason : 'csr_not_eligible',
        ]);
    }

    /** @return array<string, mixed> */
    private function state(string $status, bool $allowed, array $meta, array $extra = []): array
    {
        $context = is_array($meta['document_context'] ?? null) ? $meta['document_context'] : [];

        return array_merge([
            'status' => $status,
            'allowed' => $allowed,
            'actor' => $context['actor'] ?? null,
            'source' => $context['source'] ?? 'psb_wa',
            'message_id' => $context['message_id'] ?? null,
            'checked_at' => now('Asia/Jakarta')->toIso8601String(),
        ], $extra);
    }

    private function hasLegacyPhoto(PsbDraft $draft): bool
    {
        return trim((string) $draft->ktp_photo_path) !== ''
            || trim((string) $draft->router_photo_path) !== '';
    }
}
