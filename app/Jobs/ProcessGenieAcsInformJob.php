<?php

namespace App\Jobs;

use App\Services\GenieAcsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Async ingest after GenieACS inform / post-provision.
 * Retries while CPE has not yet registered on ACS.
 *
 * Post FiberHome activate/migrate: payload has serial + tag (nama pelanggan).
 * Prefer direct pushTag — does not require full device fetch.
 */
class ProcessGenieAcsInformJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 20;

    /**
     * Backoff until ~2h total — FiberHome first-inform can lag aktivasi.
     * Post-provision starts aggressive (5s) so tags appear quickly once CPE is on ACS.
     *
     * @var list<int>
     */
    public array $backoff = [5, 10, 15, 20, 30, 45, 60, 90, 120, 180, 240, 300, 300, 300, 300, 300, 300, 300, 300, 300];

    public int $timeout = 60;

    /**
     * @param  array{device_id?: string, serial?: string, serial_number?: string, event?: string, tag?: string, name?: string}  $payload
     */
    public function __construct(public array $payload) {}

    public function handle(GenieAcsService $genieacs): void
    {
        try {
            $serial = strtoupper(trim((string) ($this->payload['serial'] ?? $this->payload['serial_number'] ?? '')));
            $tag = trim((string) ($this->payload['tag'] ?? $this->payload['name'] ?? ''));

            // Fast path: explicit tag from OLT activate/migrate (nama pelanggan form)
            if ($serial !== '' && $tag !== '') {
                $tagResult = $genieacs->pushTagForSerial($serial, $tag);
                if ($tagResult['pushed'] ?? false) {
                    Log::info('[GenieACS] ProcessGenieAcsInformJob tag pushed', $tagResult + [
                        'event' => $this->payload['event'] ?? null,
                    ]);
                    try {
                        $genieacs->ingestDeviceInform($this->payload);
                    } catch (\Throwable $e) {
                        Log::debug('[GenieACS] post-tag ingest skipped', ['error' => $e->getMessage()]);
                    }

                    return;
                }

                // Always retry while pending / not pushed — never fail the job hard for ACS lag
                if (($tagResult['pending'] ?? false) || ! ($tagResult['pushed'] ?? false)) {
                    $this->retryOrGiveUp('tag pending', [
                        'serial' => $serial,
                        'tag' => $tag,
                        'message' => $tagResult['message'] ?? null,
                    ]);

                    return;
                }
            }

            $result = $genieacs->ingestDeviceInform($this->payload);

            if (($result['ok'] ?? false) === true) {
                Log::info('[GenieACS] ProcessGenieAcsInformJob ok', $result);

                return;
            }

            if (($result['pending'] ?? false) === true || ! ($result['ok'] ?? false)) {
                $this->retryOrGiveUp('ingest pending', [
                    'payload' => $this->payload,
                    'message' => $result['message'] ?? null,
                ]);

                return;
            }
        } catch (\Throwable $e) {
            $this->retryOrGiveUp('soft exception', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Release with backoff while attempts remain; on last try exit cleanly
     * so MaxAttemptsExceededException does not fill failed_jobs.
     *
     * @param  array<string, mixed>  $context
     */
    private function retryOrGiveUp(string $reason, array $context = []): void
    {
        $attempt = $this->attempts();
        $context['attempt'] = $attempt;
        $context['reason'] = $reason;

        if ($attempt >= $this->tries) {
            Log::warning('[GenieACS] ProcessGenieAcsInformJob give up (no failed_jobs)', $context);

            return;
        }

        Log::info('[GenieACS] ProcessGenieAcsInformJob retry', $context);
        $delay = $this->backoff[min($attempt - 1, count($this->backoff) - 1)] ?? 60;
        $this->release($delay);
    }
}
