<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Models\WaReportDelivery;
use App\Services\EvolutionApiService;
use App\Services\PaymentGateway\PaymentReconciliation;
use App\Services\Report\ReportDeliveryLedger;
use App\Services\Report\ReportGroupRegistry;
use App\Support\OpsTime;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Daily reconciliation of online payments (yesterday, WIB) to the Skynet Report
 * WA group, once per WIB day per group through ReportDeliveryLedger
 * (report_key pay_recon).
 *
 * Strictly read-only on business data (see PaymentReconciliation). Sends totals
 * only. Sends nothing when the flag is off, no Report group is set, the hour is
 * outside 08-09 WIB, or the day was a zero day. --dry-run prints the message and
 * writes nothing (no ledger, no WA).
 */
class ReconcilePaymentsDaily extends Command
{
    public const REPORT_KEY = 'pay_recon';

    public const SETTING_ENABLED = 'payments_recon_daily_enabled';

    public const FIRST_HOUR = 8;

    public const LAST_HOUR = 9;

    protected $signature = 'payments:reconcile-daily
        {--date= : WIB date to reconcile (Y-m-d); default yesterday. Only with --dry-run}
        {--dry-run : print the digest, send nothing, write no ledger}';

    protected $description = 'Read-only: reconcile online payments of yesterday and send a totals-only WA digest to the Skynet Report group (flag payments_recon_daily_enabled)';

    public function handle(
        PaymentReconciliation $recon,
        ReportGroupRegistry $registry,
        ReportDeliveryLedger $ledger,
        EvolutionApiService $evolution,
    ): int {
        $dryRun = (bool) $this->option('dry-run');
        $date = trim((string) $this->option('date'));

        if ($date !== '' && ! $dryRun) {
            $this->error('--date is only for --dry-run. A live run always reports yesterday.');

            return self::FAILURE;
        }

        $day = $this->resolveDay($date);
        if ($day === null) {
            return self::FAILURE;
        }

        if ($dryRun) {
            $result = $recon->run($day);
            $this->line('DRY RUN: nothing is sent, no ledger row is written.');
            $this->line('Data day: '.$day->toDateString().' | groups: '.count($registry->sendableGroupJids()).' | flag: '.($this->enabled() ? 'on' : 'off'));
            if ($recon->isZeroDay($result)) {
                $this->warn('ZERO DAY: the live run would NOT send.');
            }
            $this->line('---');
            $this->line($recon->message($result, $day, OpsTime::now()));
            $this->line('---');

            return self::SUCCESS;
        }

        if (! $this->enabled()) {
            $this->info(self::SETTING_ENABLED.' is off: nothing to do.');

            return self::SUCCESS;
        }
        if ($registry->sendableGroupJids() === []) {
            $this->info('No Report group configured (report_wa_group_ids empty): nothing to do.');

            return self::SUCCESS;
        }
        $hour = (int) OpsTime::now()->format('G');
        if ($hour < self::FIRST_HOUR || $hour > self::LAST_HOUR) {
            $this->info('Outside the send window (08:00-09:59 WIB): nothing to do.');

            return self::SUCCESS;
        }

        $ledger->sweepStale();

        $channel = $registry->channel();
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

        $result = $recon->run($day);
        if ($recon->isZeroDay($result)) {
            foreach ($rows as $row) {
                $ledger->markSkipped($row, 'zero_day');
            }
            $this->info('Zero day (no online payment, nothing to check): not sent.');

            return self::SUCCESS;
        }

        $text = $recon->message($result, $day, OpsTime::now());
        $wa = $evolution->forChannel($channel);
        if ($wa->getConnectionState() !== 'open') {
            // No attempt consumed: the next run retries.
            $this->warn('WA instance is not open: digest left pending (no attempt consumed).');
            Log::warning('[PAY-RECON] instance not open, deferred', ['date' => $day->toDateString(), 'channel' => $channel]);

            return self::SUCCESS;
        }

        foreach ($rows as $row) {
            $this->deliver($row, $text, $wa, $ledger, $channel, $day);
        }

        return self::SUCCESS;
    }

    private function deliver(WaReportDelivery $row, string $text, EvolutionApiService $wa, ReportDeliveryLedger $ledger, string $channel, CarbonImmutable $day): void
    {
        if (! $ledger->claim($row, sha1($text), $channel)) {
            $this->line('Not claimable (already handled), skipped.');

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
        } elseif (ReportDailyWaCommand::isDeliveryUnknown($result)) {
            // May have been delivered: never auto-resend.
            $ledger->markUnknown($row, (string) ($result['error'] ?? 'unknown'));
            $status = WaReportDelivery::STATUS_UNKNOWN;
        } else {
            $ledger->markFailed($row, (string) ($result['error'] ?? 'unknown'));
            $status = WaReportDelivery::STATUS_FAILED;
        }

        Log::info('[PAY-RECON] result', ['date' => $day->toDateString(), 'group' => substr(md5($row->group_jid), 0, 8), 'status' => $status]);
        $this->line(substr(md5($row->group_jid), 0, 8).': '.$status);
    }

    private function enabled(): bool
    {
        return in_array(Setting::get(self::SETTING_ENABLED, 0), [1, '1', true, 'true', 'on', 'yes'], true);
    }

    private function resolveDay(string $date): ?CarbonImmutable
    {
        if ($date === '') {
            return OpsTime::now()->subDay()->startOfDay();
        }
        try {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date, OpsTime::TZ);
        } catch (\Throwable) {
            $parsed = false;
        }
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            $this->error('Invalid --date, expected YYYY-MM-DD.');

            return null;
        }

        return $parsed;
    }
}
