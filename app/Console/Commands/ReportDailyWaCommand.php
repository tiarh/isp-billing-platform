<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Models\WaReportDelivery;
use App\Services\EvolutionApiService;
use App\Services\Report\DailyReportMessages;
use App\Services\Report\DailyReportService;
use App\Services\Report\ReportDeliveryLedger;
use App\Services\Report\ReportGroupRegistry;
use App\Services\Wa\WaGroupGuards;
use App\Support\OpsTime;
use App\Support\WaGroupJid;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Skynet Report: yesterday's PSB + migration summary to the Report group(s), once per
 * WIB day per group (DB ledger). Scheduled 08:03 WIB with hourly catch-up until 11:03.
 *
 * Read-only on business data. Sends nothing when the flag is off, the group list is empty,
 * the hour is outside 08-11 WIB, or yesterday was a zero day (owner rule).
 */
class ReportDailyWaCommand extends Command
{
    public const REPORT_KEY = 'daily_recap';

    public const FIRST_HOUR = 8;

    public const LAST_HOUR = 11;

    protected $signature = 'report:daily-wa
        {--date= : WIB date to report (Y-m-d); default yesterday}
        {--dry-run : build and print the report, send nothing, write no ledger}
        {--psb-detail : with --dry-run, also print the "Draft PSB dan kendala" section even when its flag is off}
        {--status : show the last 7 days of deliveries}
        {--resend : with --date, resend a failed/unknown/stuck delivery (never a sent one)}';

    protected $description = 'Skynet Report WA: daily PSB + migration report of yesterday (once per day per group)';

    public function handle(
        ReportGroupRegistry $registry,
        DailyReportService $reports,
        DailyReportMessages $messages,
        ReportDeliveryLedger $ledger,
        EvolutionApiService $evolution,
    ): int {
        if ($this->option('status')) {
            return $this->status();
        }

        if ($this->option('dry-run')) {
            return $this->dryRun($registry, $reports, $messages);
        }

        if ($this->option('resend')) {
            return $this->resend($registry, $reports, $messages, $ledger, $evolution);
        }

        if (trim((string) $this->option('date')) !== '') {
            $this->error('--date is only for --dry-run, --status and --resend. A live run always reports yesterday.');

            return self::FAILURE;
        }

        if (! $registry->dailyEnabled()) {
            $this->info('report_daily_enabled is off: nothing to do.');

            return self::SUCCESS;
        }
        if ($registry->groupJids() === []) {
            $this->info('No Report group configured (report_wa_group_ids empty): nothing to do.');

            return self::SUCCESS;
        }
        $hour = (int) OpsTime::now()->format('G');
        if ($hour < self::FIRST_HOUR || $hour > self::LAST_HOUR) {
            $this->info('Outside the send window (08:00-11:59 WIB): nothing to do.');

            return self::SUCCESS;
        }

        // A claim older than 10 minutes means the process died mid-send: make it visible as
        // unknown (never auto-resent; manual --resend only).
        $ledger->sweepStale();

        $day = OpsTime::now()->subDay()->startOfDay();
        $code = $this->live($day, $registry, $reports, $messages, $ledger, $evolution);

        if ($hour === self::LAST_HOUR) {
            $this->alertUndelivered($day, $registry, $ledger);
        }

        return $code;
    }

    private function live(
        CarbonImmutable $day,
        ReportGroupRegistry $registry,
        DailyReportService $reports,
        DailyReportMessages $messages,
        ReportDeliveryLedger $ledger,
        EvolutionApiService $evolution,
    ): int {
        $channel = $registry->channel();

        // Misconfiguration: a Report JID that is also a PSB group never gets a report.
        foreach ($registry->overlapWithPsb() as $jid) {
            $ledger->markSkipped($ledger->ensureRow(self::REPORT_KEY, $day->toDateString(), $jid, $channel), 'overlap_psb_group');
            $this->warn('Report group '.$this->short($jid).' overlaps psb_wa_group_ids: skipped.');
        }

        $rows = [];
        foreach ($registry->sendableGroupJids() as $jid) {
            $row = $ledger->ensureRow(self::REPORT_KEY, $day->toDateString(), $jid, $channel);
            if ($ledger->isSendable($row)) {
                $rows[] = $row;
            }
        }
        if ($rows === []) {
            $this->info('Nothing pending for '.$day->toDateString().'.');

            return self::SUCCESS;
        }

        $report = $reports->build($day);
        if ($reports->bothFailed($report)) {
            foreach ($rows as $row) {
                $ledger->noteBuildFailed($row, 'build_failed:'.implode(',', $report['errors']));
            }
            $this->error('Report build failed (both sections): nothing sent.');

            return self::FAILURE;
        }
        if ($reports->isZeroDay($report)) {
            foreach ($rows as $row) {
                $ledger->markSkipped($row, 'zero_day');
                $this->logResult($day, $row->group_jid, 'skipped', (int) $row->attempts);
            }
            $this->info('Zero day (no PSB, no migration activity): not sent.');

            return self::SUCCESS;
        }

        $text = $messages->daily($report, $day, OpsTime::now(), $registry->commandsEnabled(), $registry->topicCommandEnabled('onu'), $registry->topicCommandEnabled('migrasi'));
        $wa = $evolution->forChannel($channel);

        if ($wa->getConnectionState() !== 'open') {
            // No attempt consumed: the next hourly run retries.
            $this->warn('WA instance is not open: report left pending (no attempt consumed).');
            Log::warning('[REPORT-DAILY] instance not open, deferred', ['date' => $day->toDateString(), 'channel' => $channel]);

            return self::SUCCESS;
        }

        foreach ($rows as $row) {
            $this->deliver($row, $text, $wa, $ledger, $channel, $day, false);
        }

        return self::SUCCESS;
    }

    /**
     * Last catch-up run (11:03): any day/group that is neither sent nor skipped is a lost report.
     * Log an error and send ONE WA alert per day+group, only if report_undelivered_alert_jid is set and allowed. No data, no PII.
     */
    private function alertUndelivered(CarbonImmutable $day, ReportGroupRegistry $registry, ReportDeliveryLedger $ledger): void
    {
        $rows = WaReportDelivery::query()
            ->where('report_key', self::REPORT_KEY)
            ->where('period_date', $day->toDateString())
            ->whereIn('status', [
                WaReportDelivery::STATUS_UNKNOWN,
                WaReportDelivery::STATUS_FAILED,
                WaReportDelivery::STATUS_PENDING,
                WaReportDelivery::STATUS_SENDING,
            ])
            ->get();

        foreach ($rows as $row) {
            $date = $day->toDateString();
            Log::error('[REPORT-DAILY] not delivered', [
                'date' => $date,
                'group' => $this->short($row->group_jid),
                'status' => $row->status,
                'attempts' => (int) $row->attempts,
                'hint' => 'php artisan report:daily-wa --status; manual resend: --resend --date='.$date,
            ]);

            $alertJid = $this->alertJid($registry);
            if ($alertJid === null) {
                continue;
            }
            // One alert per day + group.
            if (! Cache::add('report-daily-undelivered:'.$date.':'.md5($row->group_jid), 1, now()->addDay())) {
                continue;
            }
            try {
                app(EvolutionApiService::class)->forChannel($registry->channel())->sendToGroup(
                    $alertJid,
                    'Laporan harian Skynet '.$day->format('d/m/Y').' belum terkirim ke grup Report (status: '.$row->status.'). Mohon dicek tim teknis.',
                );
            } catch (\Throwable $e) {
                Log::warning('[REPORT-DAILY] undelivered alert failed', ['exception_class' => $e::class]);
            }
        }
    }

    /**
     * Target of the "not delivered" alert: Setting report_undelivered_alert_jid (empty = no WA alert).
     * Refused when it is a Report group, the ops alert group or a PSB group.
     */
    private function alertJid(ReportGroupRegistry $registry): ?string
    {
        $jid = WaGroupJid::normalize((string) Setting::get('report_undelivered_alert_jid', ''));
        if ($jid === '') {
            return null;
        }
        $opsGroup = WaGroupJid::normalize((string) Setting::get('wa_report_group_jid', config('services.evolution_api.ops_group_id', '')));

        if (WaGroupGuards::undeliveredRefusal($jid, $registry->psbGroupJids(), $registry->groupJids(), $opsGroup) !== null) {
            Log::warning('[REPORT-DAILY] report_undelivered_alert_jid refused (report, ops or PSB group)', ['group' => $this->short($jid)]);

            return null;
        }

        return $jid;
    }

    private function dryRun(ReportGroupRegistry $registry, DailyReportService $reports, DailyReportMessages $messages): int
    {
        $day = $this->resolveDay();
        if ($day === null) {
            return self::FAILURE;
        }

        $report = $this->option('psb-detail') ? $reports->buildWithDetail($day) : $reports->build($day);
        $this->line('DRY RUN: nothing is sent, no ledger row is written.');
        $this->line('Data day: '.$day->toDateString().' | channel: '.$registry->channel().' | groups: '.count($registry->sendableGroupJids()));
        if ($reports->bothFailed($report)) {
            $this->error('Both sections failed: '.implode(', ', $report['errors']));

            return self::FAILURE;
        }
        if ($reports->isZeroDay($report)) {
            $this->warn('ZERO DAY: the live run would NOT send (ledger skipped, zero_day).');
        }
        if ($report['errors'] !== []) {
            $this->warn('Section errors: '.implode(', ', $report['errors']));
        }
        $this->line('---');
        $this->line($messages->daily($report, $day, OpsTime::now(), $registry->commandsEnabled(), $registry->topicCommandEnabled('onu')));
        $this->line('---');

        return self::SUCCESS;
    }

    private function resend(
        ReportGroupRegistry $registry,
        DailyReportService $reports,
        DailyReportMessages $messages,
        ReportDeliveryLedger $ledger,
        EvolutionApiService $evolution,
    ): int {
        if (! $this->option('date')) {
            $this->error('--resend needs --date=YYYY-MM-DD.');

            return self::FAILURE;
        }
        $day = $this->resolveDay();
        if ($day === null) {
            return self::FAILURE;
        }

        $channel = $registry->channel();
        $targets = [];
        foreach ($registry->sendableGroupJids() as $jid) {
            $row = WaReportDelivery::query()
                ->where('report_key', self::REPORT_KEY)
                ->where('period_date', $day->toDateString())
                ->where('group_jid', $jid)
                ->first();
            if ($row === null) {
                $this->warn($this->short($jid).': no ledger row for that date (nothing to resend).');

                continue;
            }
            if ($row->status === WaReportDelivery::STATUS_SENT) {
                $this->warn($this->short($jid).': already sent, refusing to resend.');

                continue;
            }
            if (! in_array($row->status, [WaReportDelivery::STATUS_FAILED, WaReportDelivery::STATUS_UNKNOWN, WaReportDelivery::STATUS_SENDING], true)) {
                $this->warn($this->short($jid).': status '.$row->status.' is not resendable.');

                continue;
            }
            $targets[] = $row;
        }
        if ($targets === []) {
            return self::SUCCESS;
        }

        $report = $reports->build($day);
        if ($reports->bothFailed($report)) {
            $this->error('Report build failed (both sections): nothing sent.');

            return self::FAILURE;
        }
        $text = $messages->daily($report, $day, OpsTime::now(), $registry->commandsEnabled(), $registry->topicCommandEnabled('onu'), $registry->topicCommandEnabled('migrasi'));
        $wa = $evolution->forChannel($channel);
        if ($wa->getConnectionState() !== 'open') {
            $this->error('WA instance is not open: nothing sent.');

            return self::FAILURE;
        }

        foreach ($targets as $row) {
            $this->deliver($row, $text, $wa, $ledger, $channel, $day, true);
        }

        return self::SUCCESS;
    }

    private function deliver(WaReportDelivery $row, string $text, EvolutionApiService $wa, ReportDeliveryLedger $ledger, string $channel, CarbonImmutable $day, bool $manual): void
    {
        $hash = sha1($text);
        $claimed = $manual ? $ledger->claimForResend($row, $hash, $channel) : $ledger->claim($row, $hash, $channel);
        if (! $claimed) {
            $this->line($this->short($row->group_jid).': not claimable (already handled), skipped.');

            return;
        }

        try {
            $result = $wa->sendToGroup($row->group_jid, $text);
        } catch (\Throwable $e) {
            $result = ['success' => false, 'error' => $e->getMessage()];
        }

        if (! empty($result['success'])) {
            $ledger->markSent($row, isset($result['message_id']) ? (string) $result['message_id'] : null);
            $status = WaReportDelivery::STATUS_SENT;
        } else {
            $error = (string) ($result['error'] ?? 'unknown');
            if (self::isDeliveryUnknown($result)) {
                // The message MAY have been delivered: never auto-resend (manual --resend only).
                $ledger->markUnknown($row, $error);
                $status = WaReportDelivery::STATUS_UNKNOWN;
            } else {
                $ledger->markFailed($row, $error);
                $status = WaReportDelivery::STATUS_FAILED;
            }
        }

        $attempts = (int) (WaReportDelivery::query()->whereKey($row->id)->value('attempts') ?? 0);
        $this->logResult($day, $row->group_jid, $status, $attempts);
        $this->line($this->short($row->group_jid).': '.$status.' (attempt '.$attempts.')');
    }

    /**
     * A failure before the request reached Evolution (DNS, refused, connect timeout) and an
     * HTTP error answer are safe to retry. A read timeout or a dropped connection after the
     * request went out may still have delivered the message.
     *
     * @param  array<string, mixed>  $result
     */
    public static function isDeliveryUnknown(array $result): bool
    {
        $lower = strtolower((string) ($result['error'] ?? ''));
        if (array_key_exists('delivery_unknown', $result)) {
            return (bool) $result['delivery_unknown'];
        }

        $connectPhase = str_contains($lower, 'connection timed out')
            || str_contains($lower, 'failed to connect')
            || str_contains($lower, 'connection refused')
            || str_contains($lower, 'could not resolve')
            || str_contains($lower, 'curl error 6:')
            || str_contains($lower, 'curl error 7:');
        if ($connectPhase) {
            return false;
        }

        // Any other transport-level error happened after the request may have gone out: unknown.
        // (Only an HTTP error answer such as "Bad Request" stays a retryable failure.)
        foreach (['curl error', 'timed out', 'timeout', 'transfer closed', 'connection reset', 'empty reply', 'ssl', 'http/2', 'stream'] as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function status(): int
    {
        $since = OpsTime::now()->subDays(7)->toDateString();
        $rows = WaReportDelivery::query()
            ->where('report_key', self::REPORT_KEY)
            ->where('period_date', '>=', $since)
            ->orderByDesc('period_date')
            ->orderBy('id')
            ->get();

        $this->table(
            ['Data day', 'Group', 'Channel', 'Status', 'Attempts', 'Sent (WIB)', 'Error'],
            $rows->map(fn (WaReportDelivery $r): array => [
                $r->period_date->toDateString(),
                $this->short($r->group_jid),
                $r->channel,
                $this->isStuck($r) ? 'STUCK -> --resend --date='.$r->period_date->toDateString() : $r->status,
                (int) $r->attempts,
                $r->sent_at ? OpsTime::format($r->sent_at) : '-',
                (string) $r->error,
            ])->all(),
        );

        return self::SUCCESS;
    }

    private function isStuck(WaReportDelivery $row): bool
    {
        return $row->status === WaReportDelivery::STATUS_SENDING
            && $row->claimed_at !== null
            && $row->claimed_at->lt(now()->subMinutes(ReportDeliveryLedger::STALE_MINUTES));
    }

    private function resolveDay(): ?CarbonImmutable
    {
        $date = trim((string) $this->option('date'));
        if ($date === '') {
            return OpsTime::now()->subDay()->startOfDay();
        }
        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date, OpsTime::TZ);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            $this->error('Invalid --date, expected YYYY-MM-DD.');

            return null;
        }

        return $parsed;
    }

    private function short(string $jid): string
    {
        return substr(md5($jid), 0, 8);
    }

    private function logResult(CarbonImmutable $day, string $jid, string $status, int $attempts): void
    {
        Log::info('[REPORT-DAILY] result', [
            'date' => $day->toDateString(),
            'group' => $this->short($jid),
            'status' => $status,
            'attempts' => $attempts,
        ]);
    }
}
