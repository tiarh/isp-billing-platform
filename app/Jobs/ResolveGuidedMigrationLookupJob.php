<?php

namespace App\Jobs;

use App\Services\GuidedMigrationLookupService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ResolveGuidedMigrationLookupJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    public int $uniqueFor = 300;

    /**
     * @param  array<string, mixed>  $input
     */
    public function __construct(public array $input)
    {
        $this->onQueue(GuidedMigrationLookupService::QUEUE);
    }

    public function uniqueId(): string
    {
        return hash('sha256', json_encode($this->input, JSON_UNESCAPED_SLASHES));
    }

    public function handle(GuidedMigrationLookupService $service): void
    {
        $guard = $service->dispatchGuard();
        if (! $guard['allowed']) {
            Log::notice('[MIGRATION LOOKUP] paused', ['reasons' => $guard['reasons']]);

            return;
        }

        $result = $service->preview($this->input);
        Log::info('[MIGRATION LOOKUP] preview complete', [
            'idempotency_key' => $result['idempotency_key'] ?? null,
            'status' => $result['status'] ?? 'unknown',
            'candidate_count' => count($result['candidates'] ?? []),
            'device_write' => false,
        ]);
    }
}
