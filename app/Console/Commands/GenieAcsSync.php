<?php

namespace App\Console\Commands;

use App\Services\GenieAcsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class GenieAcsSync extends Command
{
    protected $signature = 'genieacs:sync
                            {--auto-tag-limit=0 : Optional tag limit; keep zero for bounded snapshot sync}';

    protected $description = 'Bounded GenieACS snapshot sync without blocking PSB/Migrasi workers';

    public function handle(GenieAcsService $service): int
    {
        $lock = Cache::lock('genieacs:snapshot-sync', 90);
        if (! $lock->get()) {
            try {
                Log::notice('[GenieACS] snapshot sync skipped, previous run still holds the lock');
            } catch (\Throwable) {
                // logging must never change the exit code
            }
            $this->warn('GenieACS sync masih berjalan; lewati interval ini.');

            return self::SUCCESS;
        }

        $startedAt = microtime(true);

        try {
            $limit = max(0, (int) $this->option('auto-tag-limit'));
            $result = $service->syncDevices([
                'auto_tag' => $limit > 0,
                'auto_tag_limit' => $limit,
            ]);
            $this->recordSyncResult($result, $startedAt);
            $this->info(sprintf(
                'GenieACS snapshot selesai: %d/%d device, %d ms.',
                (int) ($result['synced'] ?? 0),
                (int) ($result['fetched'] ?? 0),
                (int) ($result['duration_ms'] ?? 0),
            ));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            try {
                Log::warning('[GenieACS] scheduled snapshot sync failed', [
                    'error' => $e->getMessage(),
                    'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                ]);
            } catch (\Throwable) {
                // logging must never change the exit code
            }
            $this->error('GenieACS snapshot gagal; snapshot terakhir dipertahankan.');

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }

    /**
     * Log the sync counts and remember the last run. Never throws: a logging or
     * cache failure must not change the command output or exit code.
     *
     * @param  array<string, mixed>  $result
     */
    private function recordSyncResult(array $result, float $startedAt): void
    {
        try {
            $durationMs = (int) ($result['duration_ms'] ?? round((microtime(true) - $startedAt) * 1000));
            $counts = [
                'synced' => (int) ($result['synced'] ?? 0),
                'fetched' => (int) ($result['fetched'] ?? 0),
                'not_found' => (int) ($result['not_found'] ?? 0),
                'backfilled_serials' => (int) ($result['backfilled_serials'] ?? 0),
                'auto_tagged' => (int) ($result['auto_tagged'] ?? 0),
                'fetch_ms' => (int) ($result['fetch_ms'] ?? 0),
                'duration_ms' => $durationMs,
            ];

            Log::info('[GenieACS] snapshot sync done', $counts);
            if ($durationMs > 60000) {
                Log::warning('[GenieACS] snapshot sync slow', $counts);
            }

            Cache::put('monitoring.genieacs.last_sync', $counts + [
                'finished_at' => now()->toIso8601String(),
            ], now()->addDay());
        } catch (\Throwable) {
            // best effort only
        }
    }
}
