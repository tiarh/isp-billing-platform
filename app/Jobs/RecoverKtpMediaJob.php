<?php

namespace App\Jobs;

use App\Models\KtpMediaRecovery;
use App\Services\KtpMediaRecoveryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RecoverKtpMediaJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 1;

    public function __construct(public int $recoveryId)
    {
        $this->onQueue('media-recovery');
    }

    public function handle(KtpMediaRecoveryService $service): void
    {
        $recovery = KtpMediaRecovery::query()->find($this->recoveryId);
        if ($recovery) {
            $service->execute($recovery);
        }
    }
}
