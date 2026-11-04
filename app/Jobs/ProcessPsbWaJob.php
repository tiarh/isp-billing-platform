<?php

namespace App\Jobs;

use App\Exceptions\LockBusyException;
use App\Exceptions\PsbLockBusyException;
use App\Models\Setting;
use App\Services\PsbOrchestratorService;
use App\Services\PsbWaMessages;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Unique by WA message_id → cegah double-dispatch (anti junk MT/OLT).
 */
class ProcessPsbWaJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Tinggi karena release() lock-busy ikut menaikkan attempts (bukan error). */
    public int $tries = 10;

    /** Error NYATA tetap gagal cepat seperti sebelumnya. */
    public int $maxExceptions = 2;

    public int $timeout = 300;

    /** Unique lock seconds (job length + margin). */
    public int $uniqueFor = 600;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public array $payload)
    {
        $this->onQueue('psb');
    }

    public function uniqueId(): string
    {
        $mid = (string) ($this->payload['message_id'] ?? '');
        if ($mid !== '') {
            return 'psb-wa-msg:'.$mid;
        }

        // Fallback: group + text hash (no message_id)
        $g = (string) ($this->payload['group_jid'] ?? '');
        $t = substr(sha1((string) ($this->payload['text'] ?? '')), 0, 16);

        return 'psb-wa-fb:'.$g.':'.$t;
    }

    public function handle(PsbOrchestratorService $psb): void
    {
        try {
            Cache::put('psb_wa:last_job_started_at', now('Asia/Jakarta')->toIso8601String(), now()->addDays(7));
            $psb->handleInbound($this->payload);
            if ($psb->hasReplyDeliveryFailure()) {
                throw new \RuntimeException('PSB reply delivery failed; retryable');
            }
            Cache::put('psb_wa:last_job_ok_at', now('Asia/Jakarta')->toIso8601String(), now()->addDays(7));
        } catch (PsbLockBusyException $e) {
            // Kontensi lock (router/OLT/SN/pool) — bukan error. Auto-resume:
            if (! filter_var(Setting::get('psb_auto_resume_enabled', '1'), FILTER_VALIDATE_BOOLEAN)) {
                $group = (string) ($this->payload['group_jid'] ?? '');
                if ($group !== '') {
                    $psb->reply($group, 'Antrian perangkat sibuk. Tidak perlu kirim ulang; sistem akan mencoba lagi.');
                }

                return;
            }
            if ($this->attempts() === 1) {
                $group = (string) ($this->payload['group_jid'] ?? '');
                if ($group !== '') {
                    $psb->reply($group, '⏳ Antrian perangkat sibuk — otomatis dicoba lagi ±1 menit. Tidak perlu kirim ulang.');
                }
            }
            $this->release(45 + random_int(0, 20)); // jitter anti serentak

            return;
        } catch (LockBusyException $e) {
            // Safety net: a non-resumable lock was busy. Tell the user, keep state,
            // no release/retry and no rethrow (the message is user-safe).
            Log::warning('[PSB] ProcessPsbWaJob lock busy', ['message_id' => $this->payload['message_id'] ?? null]);
            $group = (string) ($this->payload['group_jid'] ?? '');
            if ($group !== '') {
                $psb->reply($group, $e->userMessage());
            }

            return;
        } catch (\Throwable $e) {
            Cache::put('psb_wa:last_job_failed_at', now('Asia/Jakarta')->toIso8601String(), now()->addDays(7));
            Cache::put('psb_wa:last_job_error', class_basename($e), now()->addDays(7));
            Log::error('[PSB] ProcessPsbWaJob failed', [
                'exception_class' => $e::class,
                'message_id' => $this->payload['message_id'] ?? null,
            ]);
            $group = (string) ($this->payload['group_jid'] ?? '');
            if ($group !== '') {
                $psb->reply($group, app(PsbWaMessages::class)->jobError('Kesalahan internal. Cek status #psb dan jangan kirim ulang.'));
            }
            throw $e;
        }
    }
}
