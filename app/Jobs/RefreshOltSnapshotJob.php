<?php

namespace App\Jobs;

use App\Models\Olt;
use App\Services\OltCollectorService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Schema;
use Throwable;

class RefreshOltSnapshotJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 480;

    public int $tries = 2;

    public function __construct(public int $oltId)
    {
        $this->onQueue('olt-sync');
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('olt-snapshot:'.$this->oltId))
                ->releaseAfter(15)
                ->expireAfter(600),
        ];
    }

    public function handle(OltCollectorService $collector): void
    {
        $olt = Olt::query()->find($this->oltId);
        if (! $olt) {
            return;
        }

        if (Schema::hasColumn('olts', 'sync_status')) {
            $olt->forceFill([
                'sync_status' => 'running',
                'sync_started_at' => now(),
                'last_sync_error' => null,
            ])->save();
        }

        try {
            // Hioso: inventory+optical. Huawei and ZTE: SNMP optical read.
            // The 2-minute live tick stays status-only and is not this job.
            $collector->runSnapshotRefresh($olt);
            $olt->refresh();

            if (Schema::hasColumn('olts', 'sync_status')) {
                $olt->forceFill([
                    'sync_status' => 'idle',
                    'sync_started_at' => null,
                    'last_sync_error' => null,
                    'last_gpon_synced_at' => now(),
                ])->save();
            }
        } catch (Throwable $e) {
            if (Schema::hasColumn('olts', 'sync_status')) {
                $olt->forceFill([
                    'sync_status' => 'failed',
                    'sync_started_at' => null,
                    'last_sync_error' => $e->getMessage(),
                ])->save();
            }

            throw $e;
        }
    }
}
