<?php

namespace App\Console\Commands;

use App\Jobs\RecoverKtpMediaJob;
use App\Models\Customer;
use App\Models\KtpMediaRecovery;
use App\Services\KtpMediaRecoveryService;
use Illuminate\Console\Command;

class RecoverExactKtpMedia extends Command
{
    protected $signature = 'ktp:recover-exact
        {--customer=* : Customer IDs to stage, maximum 3}
        {--stage : Verify source and persist a recovery manifest}
        {--apply=* : Recovery manifest IDs to queue, maximum 3}
        {--rollback= : Verified recovery ID to roll back}
        {--actor= : Operator identity}
        {--reason= : Required audit reason}';

    protected $description = 'Stage and recover exact-match legacy KTP media safely';

    public function handle(KtpMediaRecoveryService $service): int
    {
        if ($rollbackId = (int) $this->option('rollback')) {
            $recovery = KtpMediaRecovery::query()->findOrFail($rollbackId);
            $service->rollback($recovery);
            $this->info("Recovery #{$rollbackId} rolled back.");

            return self::SUCCESS;
        }

        $actor = trim((string) $this->option('actor'));
        $reason = trim((string) $this->option('reason'));
        if (($this->option('stage') || $this->option('apply') !== []) && ($actor === '' || $reason === '')) {
            $this->error('--actor and --reason are required.');

            return self::FAILURE;
        }

        $customerIds = array_values(array_unique(array_map('intval', (array) $this->option('customer'))));
        if ($this->option('stage')) {
            if ($customerIds === [] || count($customerIds) > 3) {
                $this->error('--stage requires 1-3 explicit --customer IDs.');

                return self::FAILURE;
            }
            foreach ($customerIds as $customerId) {
                $result = $service->stage(Customer::query()->findOrFail($customerId), $actor, $reason);
                $this->line(json_encode($result['preview'], JSON_UNESCAPED_SLASHES));
            }
        }

        $applyIds = array_values(array_unique(array_map('intval', (array) $this->option('apply'))));
        if ($applyIds !== []) {
            if (count($applyIds) > 3 || ! $service->hostIsSafe()) {
                $this->error('Apply is limited to 3 and requires idle PSB/migration queues and a safe host.');

                return self::FAILURE;
            }
            foreach ($applyIds as $id) {
                $recovery = KtpMediaRecovery::query()->whereKey($id)->where('status', 'ready')->firstOrFail();
                RecoverKtpMediaJob::dispatch($recovery->id);
                $this->info("Recovery #{$id} queued on media-recovery.");
            }
        }

        if (! $this->option('stage') && $applyIds === []) {
            KtpMediaRecovery::query()->latest('id')->limit(20)->get()->each(
                fn (KtpMediaRecovery $row) => $this->line(json_encode($service->preview($row), JSON_UNESCAPED_SLASHES))
            );
        }

        return self::SUCCESS;
    }
}
