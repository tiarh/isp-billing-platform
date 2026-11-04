<?php

namespace App\Services\Report;

use App\Console\Commands\ReportDailyWaCommand;
use App\Models\Setting;
use App\Models\WaReportDelivery;
use App\Services\EvolutionApiService;
use App\Services\Outage\Diagnosis\PrecisionReport;
use Illuminate\Support\Facades\Log;

/**
 * Weekly "Penyebab tiket gangguan" text to the Skynet Report group(s). Counts only, no names.
 *
 * Sent synchronously by the Monday `outage:diagnosis-precision` command: nothing is queued, so the wa / psb /
 * migration queues are never touched, and a group that is also a PSB group is never used (ReportGroupRegistry).
 * Once per week per group through the ReportDeliveryLedger. Flag `root_cause_weekly_wa_enabled` (Setting, default OFF).
 */
class RootCauseWeeklyWa
{
    public const REPORT_KEY = 'root_cause_weekly';

    public const FLAG = 'root_cause_weekly_wa_enabled';

    public function __construct(
        private readonly PrecisionReport $precision,
        private readonly ReportGroupRegistry $registry,
        private readonly ReportDeliveryLedger $ledger,
    ) {}

    public static function enabled(): bool
    {
        return in_array(Setting::get(self::FLAG, 0), [1, '1', true, 'true', 'on', 'yes'], true);
    }

    /**
     * @param  array<string,mixed>  $report  payload of PrecisionReport::compute / store
     * @return list<string> one status per group, or a single reason (disabled | no_text | no_group)
     */
    public function send(array $report): array
    {
        if (! self::enabled()) {
            return ['disabled'];
        }
        $text = $this->precision->rootCauseText($report);
        if ($text === null) {
            return ['no_text'];
        }
        $jids = $this->registry->sendableGroupJids();
        if ($jids === []) {
            return ['no_group'];
        }

        // The ops channel is the instance that also sends PSB WA: this report only goes out on the ticket channel.
        $channel = $this->registry->channel();
        if ($channel !== 'ticket') {
            Log::info('[Outage] weekly root cause WA skipped: report channel is not "ticket"', ['channel' => $channel]);

            return ['ops_channel_skipped'];
        }
        $period = (string) $report['week_start'];
        $wa = app(EvolutionApiService::class)->forChannel($channel);
        $out = [];
        foreach ($jids as $jid) {
            $row = $this->ledger->ensureRow(self::REPORT_KEY, $period, $jid, $channel);
            if (! $this->ledger->isSendable($row)) {
                $out[] = 'already_sent';

                continue;
            }
            if (! $this->ledger->claim($row, sha1($text), $channel)) {
                $out[] = 'already_sent';

                continue;
            }

            try {
                $result = $wa->sendToGroup($jid, $text);
            } catch (\Throwable $e) {
                $result = ['success' => false, 'error' => $e->getMessage()];
            }

            if (! empty($result['success'])) {
                $this->ledger->markSent($row, isset($result['message_id']) ? (string) $result['message_id'] : null);
                $out[] = 'sent';

                continue;
            }

            $error = (string) ($result['error'] ?? 'unknown');
            if (ReportDailyWaCommand::isDeliveryUnknown($result)) {
                $this->ledger->markUnknown($row, $error);
                $out[] = WaReportDelivery::STATUS_UNKNOWN;
            } else {
                $this->ledger->markFailed($row, $error);
                $out[] = WaReportDelivery::STATUS_FAILED;
            }
            Log::warning('[Outage] weekly root cause WA not delivered', ['status' => end($out)]);
        }

        return $out;
    }
}
