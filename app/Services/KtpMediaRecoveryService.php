<?php

namespace App\Services;

use App\Exceptions\LockBusyException;
use App\Models\Customer;
use App\Models\KtpMediaRecovery;
use App\Models\Setting;
use App\Support\CacheLockWait;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class KtpMediaRecoveryService
{
    /** @return array{recovery: KtpMediaRecovery, preview: array<string, mixed>} */
    public function stage(Customer $customer, string $actor, string $reason): array
    {
        $source = $this->sourceFor($customer);
        $key = hash('sha256', $customer->id.'|'.$source);
        $existing = KtpMediaRecovery::query()->where('idempotency_key', $key)->first();
        if ($existing) {
            return ['recovery' => $existing, 'preview' => $this->preview($existing)];
        }

        $check = $this->fetchAndVerify($source);
        $status = $check['valid'] ? 'ready' : ($check['reason'] === 'wrong_media' ? 'invalid_media' : 'needs_resend');
        $target = $check['valid']
            ? $this->targetPath($customer, $check['checksum'], $check['mime'])
            : null;
        $recovery = KtpMediaRecovery::create([
            'customer_id' => $customer->id,
            'status' => $status,
            'idempotency_key' => $key,
            'source_type' => 'customer_legacy_url',
            'source_locator' => $source,
            'target_path' => $target,
            'source_size' => $check['size'] ?? null,
            'source_mime' => $check['mime'] ?? null,
            'source_checksum' => $check['checksum'] ?? null,
            'confidence' => $check['confidence'] ?? 0,
            'evidence' => [
                'customer_id' => $customer->id,
                'customer_code' => $customer->code,
                'source_field' => $this->sourceField($customer),
                'classifier' => $check['classifier'] ?? null,
                'reason' => $check['reason'] ?? null,
            ],
            'before_snapshot' => [
                'ktp_photo_url' => $customer->getRawOriginal('ktp_photo_url'),
                'ktp_external_url' => $customer->getRawOriginal('ktp_external_url'),
            ],
            'requested_by' => $actor,
            'reason' => $reason,
        ]);

        return ['recovery' => $recovery, 'preview' => $this->preview($recovery)];
    }

    public function execute(KtpMediaRecovery $recovery): KtpMediaRecovery
    {
        if ($recovery->status === 'verified') {
            return $recovery;
        }
        if ($recovery->status !== 'ready') {
            throw new \RuntimeException('Recovery is not ready.');
        }
        if (! $this->hostIsSafe()) {
            throw new \RuntimeException('Recovery paused: queue or host is not safe.');
        }

        $lock = Cache::lock('ktp-media-recovery', 300);
        if (! CacheLockWait::acquire($lock, 1)) {
            throw new LockBusyException('Recovery worker is busy.');
        }

        try {
            $recovery->increment('attempts');
            $check = $this->fetchAndVerify($recovery->source_locator);
            if (! $check['valid'] || ! hash_equals((string) $recovery->source_checksum, (string) $check['checksum'])) {
                throw new \RuntimeException('Source changed or failed exact verification.');
            }

            $disk = Storage::disk(config('filesystems.default'));
            $path = (string) $recovery->target_path;
            $created = ! $disk->exists($path);
            if ($created) {
                $disk->put($path, $check['bytes']);
            }
            $stored = $disk->get($path);
            if (! hash_equals((string) $recovery->source_checksum, hash('sha256', $stored))) {
                if ($created) {
                    $disk->delete($path);
                }
                throw new \RuntimeException('Canonical object checksum mismatch.');
            }
            if (! $this->canonicalUrlIsReadable($path, (string) $recovery->source_checksum)) {
                if ($created) {
                    $disk->delete($path);
                }
                throw new \RuntimeException('Canonical media URL is not readable.');
            }

            $customer = $recovery->customer()->firstOrFail();
            $customer->forceFill([
                'ktp_external_url' => $customer->getRawOriginal('ktp_external_url') ?: $recovery->source_locator,
                'ktp_photo_url' => $path,
            ])->save();
            $recovery->forceFill([
                'status' => 'verified',
                'copied_at' => now(),
                'verified_at' => now(),
                'last_error' => null,
            ])->save();

            return $recovery->fresh();
        } catch (\Throwable $e) {
            $recovery->forceFill(['status' => 'failed', 'last_error' => $e->getMessage()])->save();
            throw $e;
        } finally {
            $lock->release();
        }
    }

    public function rollback(KtpMediaRecovery $recovery): KtpMediaRecovery
    {
        if ($recovery->status === 'rolled_back') {
            return $recovery;
        }
        if ($recovery->status !== 'verified') {
            throw new \RuntimeException('Only verified recovery can be rolled back.');
        }

        $before = is_array($recovery->before_snapshot) ? $recovery->before_snapshot : [];
        $customer = $recovery->customer()->firstOrFail();
        $customer->forceFill([
            'ktp_photo_url' => $before['ktp_photo_url'] ?? null,
            'ktp_external_url' => $before['ktp_external_url'] ?? null,
        ])->save();

        $path = (string) $recovery->target_path;
        if ($path !== '' && ! Customer::query()->where('ktp_photo_url', $path)->exists()) {
            Storage::disk(config('filesystems.default'))->delete($path);
        }
        $recovery->forceFill(['status' => 'rolled_back'])->save();

        return $recovery->fresh();
    }

    public function hostIsSafe(): bool
    {
        if (filter_var(Setting::get('ktp_media_recovery_paused', false), FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }
        foreach (['psb', 'migration-live', 'migration-lookup'] as $queue) {
            if (app('queue')->size($queue) > 0) {
                return false;
            }
        }
        $load = sys_getloadavg();

        return ! is_array($load) || ($load[0] ?? 0) <= (float) Setting::get('ktp_media_recovery_max_load', 8);
    }

    /** @return array<string, mixed> */
    public function preview(KtpMediaRecovery $recovery): array
    {
        return [
            'id' => $recovery->id,
            'customer_id' => $recovery->customer_id,
            'status' => $recovery->status,
            'source_type' => $recovery->source_type,
            'target_path' => $recovery->target_path,
            'size' => $recovery->source_size,
            'mime' => $recovery->source_mime,
            'checksum' => $recovery->source_checksum,
            'confidence' => $recovery->confidence,
            'evidence' => $recovery->evidence,
            'rollback' => 'ktp:recover-exact --rollback='.$recovery->id,
        ];
    }

    /** @return array<string, mixed> */
    private function fetchAndVerify(string $url): array
    {
        if (! filter_var($url, FILTER_VALIDATE_URL) || ! $this->hostAllowed($url)) {
            return ['valid' => false, 'reason' => 'source_not_allowed'];
        }
        try {
            $response = Http::timeout(15)->connectTimeout(5)
                ->withOptions(['allow_redirects' => false])
                ->get($url);
            if (! $response->successful()) {
                return ['valid' => false, 'reason' => 'source_http_'.$response->status()];
            }
            $bytes = $response->body();
            if ($bytes === '' || strlen($bytes) > 15 * 1024 * 1024) {
                return ['valid' => false, 'reason' => 'invalid_size'];
            }
            $image = @getimagesizefromstring($bytes);
            $mime = is_array($image) ? (string) ($image['mime'] ?? '') : '';
            if (! in_array($mime, ['image/jpeg', 'image/png'], true)) {
                return ['valid' => false, 'reason' => 'invalid_image'];
            }
            $class = app(PsbOrchestratorService::class)->classifyWaActivationPhoto($bytes);
            $confidence = min(100, (int) ($class['ktp_score'] ?? 0));
            $isKtp = ($class['type'] ?? 'unknown') === 'ktp';
            $valid = $isKtp && $confidence >= (int) Setting::get('ktp_media_recovery_min_confidence', 50);

            return [
                'valid' => $valid,
                'reason' => $valid ? null : ($isKtp ? 'low_confidence' : 'wrong_media'),
                'bytes' => $bytes,
                'size' => strlen($bytes),
                'mime' => $mime,
                'checksum' => hash('sha256', $bytes),
                'confidence' => $confidence,
                'classifier' => [
                    'type' => $class['type'] ?? 'unknown',
                    'ktp_score' => $class['ktp_score'] ?? 0,
                    'sn_score' => $class['sn_score'] ?? 0,
                ],
            ];
        } catch (\Throwable) {
            return ['valid' => false, 'reason' => 'source_unavailable'];
        }
    }

    private function sourceFor(Customer $customer): string
    {
        foreach (['ktp_external_url', 'ktp_photo_url'] as $field) {
            $value = trim((string) $customer->getRawOriginal($field));
            if (str_starts_with(strtolower($value), 'http')) {
                return $value;
            }
        }

        throw new \RuntimeException('Customer has no exact legacy KTP URL.');
    }

    private function sourceField(Customer $customer): ?string
    {
        foreach (['ktp_external_url', 'ktp_photo_url'] as $field) {
            if (str_starts_with(strtolower(trim((string) $customer->getRawOriginal($field))), 'http')) {
                return $field;
            }
        }

        return null;
    }

    private function hostAllowed(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $configured = Setting::get('ktp_media_recovery_allowed_hosts', config('site.ktp_media_allowed_hosts'));
        $allowed = array_filter(array_map('trim', explode(',', strtolower((string) $configured))));

        return $host !== '' && in_array($host, $allowed, true);
    }

    private function targetPath(Customer $customer, string $checksum, string $mime): string
    {
        $extension = $mime === 'image/png' ? 'png' : 'jpg';

        return 'ktp/recovered/'.now()->format('Y/m').'/'.$customer->id.'_'.substr($checksum, 0, 16).'.'.$extension;
    }

    private function canonicalUrlIsReadable(string $path, string $checksum): bool
    {
        $url = rtrim((string) config('app.url'), '/').'/storage/'.implode('/', array_map('rawurlencode', explode('/', $path)));
        try {
            $response = Http::timeout(10)->get($url);

            return $response->successful() && hash_equals($checksum, hash('sha256', $response->body()));
        } catch (\Throwable) {
            return false;
        }
    }
}
