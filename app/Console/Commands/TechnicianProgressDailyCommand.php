<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Models\TechnicianDailySchedule;
use App\Models\TechnicianDailyTask;
use App\Services\EvolutionApiService;
use App\Services\TechnicianProgressService;
use App\Services\TechnicianProgressTemplateService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class TechnicianProgressDailyCommand extends Command
{
    protected $signature = 'technician-progress:daily
                            {--mode=preview : preview|morning|summary}
                            {--date= : Work date Y-m-d}
                            {--at= : Summary time H:i}
                            {--if-active-since= : For summary, send only if there is activity since H:i}';

    protected $description = 'Build technician daily schedule/progress messages with dry-run guard';

    public function handle(TechnicianProgressTemplateService $template, TechnicianProgressService $progress): int
    {
        $mode = strtolower((string) $this->option('mode'));
        $config = $progress->config();
        $dryRun = (bool) ($config['dry_run_enabled'] ?? true);

        $date = $this->option('date');
        if (! $date) {
            $date = $mode === 'preview'
                ? now('Asia/Jakarta')->addDay()->toDateString()
                : now('Asia/Jakarta')->toDateString();
        }

        if ($mode === 'summary') {
            $since = (string) ($this->option('if-active-since') ?: '');
            if ($since !== '' && ! $progress->hasActivitySince((string) $date, $since)) {
                $this->info('SKIP_NO_ACTIVITY_SINCE: '.$since);
                Setting::set('ticket_progress_last_dynamic_summary_skip', [
                    'at' => now('Asia/Jakarta')->toDateTimeString(),
                    'date' => $date,
                    'since' => $since,
                    'reason' => 'no_activity',
                ], 'json', 'ticket', 'Last dynamic technician summary skip');

                return 0;
            }
            $message = $progress->buildSummaryMessage((string) $date, $this->option('at') ?: null);
            $enabled = (bool) ($config['summary_enabled'] ?? false);
        } else {
            $snapshot = $template->snapshot((string) $date);
            $message = $template->messageForShare($snapshot);
            $materialized = $progress->materializePlannedTasks((string) $date, $snapshot['planned_teams'] ?? []);
            $this->info('AUTO_TASKS: created='.$materialized['created'].' updated='.$materialized['updated']);
            $enabled = $mode === 'morning'
                ? (bool) ($config['auto_send_enabled'] ?? false)
                : false;
        }

        $groupJid = (string) ($config['share_group_jid'] ?? '');
        $this->line($message);

        $lastRun = Setting::get('ticket_progress_last_'.$mode, []);
        if (
            $mode === 'morning'
            && ! $dryRun
            && $enabled
            && (bool) Setting::get('ticket_progress_require_fix_before_send', true)
            && $this->hasUnapprovedManualDraft((string) $date)
        ) {
            $this->info('SKIP_UNAPPROVED_MANUAL_DRAFT: fix jadwal dulu untuk '.$date);
            Setting::set('ticket_progress_last_'.$mode, [
                'at' => now('Asia/Jakarta')->toDateTimeString(),
                'date' => $date,
                'dry_run' => false,
                'enabled' => true,
                'group_set' => $groupJid !== '',
                'skipped' => true,
                'reason' => 'unapproved_manual_draft',
            ], 'json', 'ticket', 'Last technician progress '.$mode);

            return 0;
        }
        if (
            $mode === 'morning'
            && ! $dryRun
            && $enabled
            && is_array($lastRun)
            && ($lastRun['date'] ?? null) === (string) $date
            && ! empty($lastRun['result']['success'])
        ) {
            $this->info('SKIP_DUPLICATE_MORNING_SEND: already sent for '.$date);

            return 0;
        }

        if ($dryRun || ! $enabled || $groupJid === '') {
            $this->info('DRY_RUN_OR_DISABLED: no WA sent');
            Setting::set('ticket_progress_last_'.$mode, [
                'at' => now('Asia/Jakarta')->toDateTimeString(),
                'date' => $date,
                'dry_run' => $dryRun,
                'enabled' => $enabled,
                'group_set' => $groupJid !== '',
            ], 'json', 'ticket', 'Last technician progress '.$mode);

            return 0;
        }

        $result = app(EvolutionApiService::class)
            ->forChannel('ticket')
            ->sendToGroup($groupJid, $message);

        Setting::set('ticket_progress_last_'.$mode, [
            'at' => Carbon::now('Asia/Jakarta')->toDateTimeString(),
            'date' => $date,
            'dry_run' => false,
            'enabled' => true,
            'group_set' => true,
            'result' => $result,
        ], 'json', 'ticket', 'Last technician progress '.$mode);

        if (! ($result['success'] ?? false)) {
            $this->error('WA send failed: '.($result['error'] ?? 'unknown'));

            return 1;
        }

        $this->info('WA_SENT: '.$groupJid);

        return 0;
    }

    protected function hasUnapprovedManualDraft(string $date): bool
    {
        $hasManual = TechnicianDailyTask::query()
            ->whereDate('work_date', $date)
            ->where('source', 'manual_wa')
            ->whereNotIn('status', ['close', 'closed', 'cancelled', 'canceled'])
            ->exists();
        if (! $hasManual) {
            return false;
        }

        $schedule = TechnicianDailySchedule::query()->whereDate('schedule_date', $date)->first();

        return ! $schedule || ! $schedule->approved_at;
    }
}
