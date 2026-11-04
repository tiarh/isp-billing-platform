<?php

namespace App\Console\Commands;

use App\Services\DeviceRecoveryReuseCloser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Daily safety net for DeviceRecoveryReuseCloser (writers that bypass the
 * Customer saved hook). Report-only unless the setting
 * device_recovery_close_reused_apply is on (owner: first week dry-run) or
 * --apply is passed explicitly. --dry-run always wins.
 */
class DeviceRecoveryCloseReused extends Command
{
    protected $signature = 'device-recovery:close-reused
        {--dry-run : Report only, never close (default unless the apply setting is on)}
        {--apply : Close matched cases even when the apply setting is off}';

    protected $description = 'Close open pickup cases whose ONU is now another live customer\'s onu_serial (status reused)';

    public function handle(DeviceRecoveryReuseCloser $closer): int
    {
        if (! $closer->isEnabled()) {
            $this->info('device-recovery:close-reused disabled (kill switch off or recovery read-only).');

            return self::SUCCESS;
        }

        $apply = ! $this->option('dry-run')
            && ($this->option('apply') || $closer->sweepApplyEnabled());
        $rows = $closer->sweep($apply);

        if ($rows !== []) {
            $this->table(array_keys($rows[0]), $rows);
        }
        $summary = [
            'mode' => $apply ? 'apply' : 'dry-run',
            'matched' => count($rows),
            'closed' => count(array_filter($rows, fn (array $row): bool => $row['result'] === 'closed')),
        ];
        $this->info(sprintf('mode=%s matched=%d closed=%d', $summary['mode'], $summary['matched'], $summary['closed']));
        Log::info('[device-recovery] close-reused sweep', $summary + [
            'case_ids' => array_column($rows, 'case_id'),
        ]);

        return self::SUCCESS;
    }
}
