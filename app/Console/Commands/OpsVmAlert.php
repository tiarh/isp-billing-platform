<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\Ops\VmAlertService;
use Illuminate\Console\Command;

class OpsVmAlert extends Command
{
    protected $signature = 'ops:vm-alert
                            {--dry-run : Print numbers, decisions and exact texts; write and send nothing}
                            {--status : Print mode and stored state (read-only)}
                            {--set-mode= : Set the mode: off, log or on}
                            {--actor=cli : Who changed the mode (activity log)}';

    protected $description = 'WA alert to the ops group when VM102 CPU steal is high or GenieACS informs drop (mode off by default)';

    public function handle(VmAlertService $service): int
    {
        $setMode = $this->option('set-mode');
        if ($setMode !== null) {
            return $this->setMode((string) $setMode, (string) $this->option('actor'));
        }

        if ($this->option('status')) {
            $this->printStatus($service->status());

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->printDryRun($service->dryRun());

            return self::SUCCESS;
        }

        $mode = VmAlertService::currentMode();
        if ($mode === 'off') {
            $this->info('mode off');

            return self::SUCCESS;
        }

        $result = $service->run($mode);
        if (! $result['ok']) {
            $this->warn((string) ($result['error'] ?? 'run skipped'));

            return self::SUCCESS;
        }

        $this->info('mode='.$mode.' '.json_encode($result['decisions']).' sent='.implode(',', $result['sent']));

        return self::SUCCESS;
    }

    private function setMode(string $value, string $actor): int
    {
        $mode = strtolower(trim($value));
        if (! in_array($mode, VmAlertService::MODES, true)) {
            $this->error('Mode must be off, log or on.');

            return self::FAILURE;
        }

        $old = (string) Setting::get(VmAlertService::SETTING_MODE, 'off');
        Setting::set(VmAlertService::SETTING_MODE, $mode, 'text', 'ops');

        activity('ops')
            ->withProperties(['key' => VmAlertService::SETTING_MODE, 'old' => $old, 'new' => $mode, 'actor' => $actor])
            ->event('ops_vm_alert_mode_changed')
            ->log('ops_vm_alert_mode_changed');

        $this->info('ops_vm_alert_mode='.$mode.' (was '.$old.', actor '.$actor.')');

        return self::SUCCESS;
    }

    /** @param  array<string, mixed>  $s */
    private function printStatus(array $s): void
    {
        $this->info('mode='.$s['mode']);
        if (! $s['state_found']) {
            $this->line('state: none yet (learning)');

            return;
        }
        $state = $s['state'];
        $this->line('started_at='.date('Y-m-d H:i:s', (int) $state['started_at']).' ring='.$state['ring_entries'].' inform_samples='.$state['inform_raw_samples'].' hour_buckets='.$state['inform_hour_buckets'].' steal_last_slice='.($state['steal_pct_last_slice'] ?? 'n/a'));
        foreach ($state['types'] as $type => $t) {
            $this->line(sprintf(
                '%s: status=%s pending=%s sent=%s(%s) suppressed=%s recover_pending=%s last_alert_at=%s',
                $type,
                $t['status'],
                $t['pending'] ? 'yes' : 'no',
                $t['sent'] ? 'yes' : 'no',
                $t['sent_mode'] ?? '-',
                $t['suppressed'] ? 'yes' : 'no',
                $t['recover_pending'] ? 'yes' : 'no',
                $t['last_alert_at'] === null ? '-' : date('Y-m-d H:i:s', (int) $t['last_alert_at']),
            ));
        }
    }

    /** @param  array<string, mixed>  $d */
    private function printDryRun(array $d): void
    {
        $in = $d['inputs'];
        $this->warn('DRY RUN: nothing written, nothing sent');
        $this->line('steal_short_pct='.($d['short_steal_pct'] === null ? 'n/a' : round($d['short_steal_pct'], 1)).' (sampel singkat, bukan 30 menit)');
        $this->line('steal_ring_last_slice_pct='.($d['ring_steal_pct'] ?? 'n/a'));
        $this->line('inform_le_30m='.($in['inform_count'] ?? 'n/a'));
        $this->line('snapshot_age_min='.($in['snapshot_at'] === null ? 'n/a' : (int) round(($d['now'] - $in['snapshot_at']) / 60)));
        $this->line('operator_hold='.($in['hold'] ? 'yes' : 'no'));
        $normal = $d['normal'];
        $this->line('inform_normal='.($normal === null ? 'learning' : round($normal['value']).' ('.$normal['source'].', '.$normal['samples'].' samples)'));
        foreach ($d['decisions'] as $type => $decision) {
            $this->line('decision '.$type.'='.$decision);
        }
        foreach (['alerts' => 'would send (alert)', 'recoveries' => 'would send (pulih)'] as $key => $label) {
            foreach ($d['would_send'][$key] as $type => $text) {
                $this->line('--- '.$label.' '.$type);
                $this->line($text);
            }
        }
        foreach ($d['samples'] as $type => $text) {
            $this->line('--- contoh teks '.$type);
            $this->line($text);
        }
    }
}
