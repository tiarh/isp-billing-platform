<?php

namespace App\Jobs;

use App\Services\CustomerMigrationService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Queue;

class ExecuteCustomerMigrationWaJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 120;

    public int $timeout = 420;

    public int $backoff = 15;

    public int $uniqueFor = 3600;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public int $migrationId, public array $payload)
    {
        $this->onQueue('migration-live');
    }

    public function uniqueId(): string
    {
        return 'migration-wa-live:'.$this->migrationId;
    }

    public function handle(CustomerMigrationService $service): void
    {
        if (Queue::size('psb') > 0 || Queue::size('migration-lookup') > 0) {
            $this->release(15);

            return;
        }

        if ($service->executeWaMigration($this->migrationId, $this->payload) === null) {
            $this->release(15);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        app(CustomerMigrationService::class)->markWaExecutionJobFailed(
            $this->migrationId,
            $this->payload,
            $exception
        );
    }
}
