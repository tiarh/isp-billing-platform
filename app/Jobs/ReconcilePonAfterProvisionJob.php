<?php

namespace App\Jobs;

use App\Models\Olt;
use App\Services\OltCollectorService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * After FiberHome/ZTE provision: refresh sibling ONUs on the same PON without
 * blocking the provision HTTP response (port lock already released).
 */
class ReconcilePonAfterProvisionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    /**
     * @param  array{olt_id: int, pon_port: string, onu_ref?: string}  $payload
     */
    public function __construct(public array $payload)
    {
        $this->onQueue('olt-sync');
    }

    public function middleware(): array
    {
        $oltId = (int) ($this->payload['olt_id'] ?? 0);
        $ponPort = trim((string) ($this->payload['pon_port'] ?? 'unknown'));

        return [
            (new WithoutOverlapping('olt-pon-reconcile:'.$oltId.':'.$ponPort))
                ->releaseAfter(30)
                ->expireAfter(180),
        ];
    }

    public function handle(OltCollectorService $collector): void
    {
        $oltId = (int) ($this->payload['olt_id'] ?? 0);
        $ponPort = trim((string) ($this->payload['pon_port'] ?? ''));
        if ($oltId < 1 || $ponPort === '') {
            return;
        }

        $olt = Olt::query()->find($oltId);
        if (! $olt) {
            return;
        }

        try {
            $rec = $collector->reconcilePonInventory($olt, $ponPort);
            Log::info('ReconcilePonAfterProvisionJob ok', [
                'olt_id' => $oltId,
                'pon' => $ponPort,
                'onu_ref' => $this->payload['onu_ref'] ?? null,
                'live_refs' => $rec['live_refs'] ?? null,
                'purged' => $rec['purged'] ?? null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('ReconcilePonAfterProvisionJob failed', [
                'olt_id' => $oltId,
                'pon' => $ponPort,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
