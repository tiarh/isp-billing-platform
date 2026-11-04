<?php

namespace App\Services\Report;

use App\Models\WaReportDelivery;
use App\Support\SensitiveDataRedactor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Durable once-per-day-per-group guard for Skynet Report sends. The unique key
 * (report_key, period_date, group_jid) plus conditional UPDATEs are the lock; cache,
 * withoutOverlapping and onOneServer are only a first line of defence.
 */
class ReportDeliveryLedger
{
    public const MAX_ATTEMPTS = 3;

    /** Statuses that must never be sent again automatically. */
    public const TERMINAL = [
        WaReportDelivery::STATUS_SENT,
        WaReportDelivery::STATUS_UNKNOWN,
        WaReportDelivery::STATUS_SENDING,
        WaReportDelivery::STATUS_SKIPPED,
    ];

    /** A claim (status sending) older than this means the process died mid-send. */
    public const STALE_MINUTES = 10;

    /**
     * Mark stale `sending` rows as `unknown` (never auto-resent). Returns how many rows changed.
     */
    public function sweepStale(): int
    {
        $stale = DB::table('wa_report_deliveries')
            ->where('status', WaReportDelivery::STATUS_SENDING)
            ->where('claimed_at', '<', now()->subMinutes(self::STALE_MINUTES))
            ->update([
                'status' => WaReportDelivery::STATUS_UNKNOWN,
                'error' => 'stale_sending',
                'updated_at' => now(),
            ]);
        if ($stale > 0) {
            Log::warning('[REPORT-DAILY] stale sending rows marked unknown', ['rows' => $stale]);
        }

        return $stale;
    }

    public function ensureRow(string $reportKey, string $periodDate, string $groupJid, string $channel): WaReportDelivery
    {
        $now = now();
        DB::table('wa_report_deliveries')->insertOrIgnore([
            'report_key' => $reportKey,
            'period_date' => $periodDate,
            'group_jid' => $groupJid,
            'channel' => $channel,
            'status' => WaReportDelivery::STATUS_PENDING,
            'attempts' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return WaReportDelivery::query()
            ->where('report_key', $reportKey)
            ->where('period_date', $periodDate)
            ->where('group_jid', $groupJid)
            ->firstOrFail();
    }

    /** True when the automatic run may still try this row. */
    public function isSendable(WaReportDelivery $row): bool
    {
        return in_array($row->status, [WaReportDelivery::STATUS_PENDING, WaReportDelivery::STATUS_FAILED], true)
            && (int) $row->attempts < self::MAX_ATTEMPTS;
    }

    /** Atomic claim for the automatic run. False = someone else owns it / limit reached. */
    public function claim(WaReportDelivery $row, string $messageHash, string $channel): bool
    {
        $affected = DB::table('wa_report_deliveries')
            ->where('id', $row->id)
            ->whereIn('status', [WaReportDelivery::STATUS_PENDING, WaReportDelivery::STATUS_FAILED])
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->update([
                'status' => WaReportDelivery::STATUS_SENDING,
                'attempts' => DB::raw('attempts + 1'),
                'claimed_at' => now(),
                'message_hash' => $messageHash,
                'channel' => $channel,
                'error' => null,
                'updated_at' => now(),
            ]);

        return $affected === 1;
    }

    /** Manual --resend: only failed / unknown / stuck-sending rows, attempts limit ignored. */
    public function claimForResend(WaReportDelivery $row, string $messageHash, string $channel): bool
    {
        $affected = DB::table('wa_report_deliveries')
            ->where('id', $row->id)
            ->whereIn('status', [
                WaReportDelivery::STATUS_FAILED,
                WaReportDelivery::STATUS_UNKNOWN,
                WaReportDelivery::STATUS_SENDING,
            ])
            ->update([
                'status' => WaReportDelivery::STATUS_SENDING,
                'attempts' => DB::raw('attempts + 1'),
                'claimed_at' => now(),
                'message_hash' => $messageHash,
                'channel' => $channel,
                'error' => null,
                'updated_at' => now(),
            ]);

        return $affected === 1;
    }

    public function markSent(WaReportDelivery $row, ?string $providerMessageId): void
    {
        $this->finish($row, WaReportDelivery::STATUS_SENT, null, $providerMessageId, true);
    }

    public function markFailed(WaReportDelivery $row, string $error): void
    {
        $this->finish($row, WaReportDelivery::STATUS_FAILED, $error);
    }

    public function markUnknown(WaReportDelivery $row, string $error): void
    {
        $this->finish($row, WaReportDelivery::STATUS_UNKNOWN, $error);
    }

    /** Pending row that will not be sent (zero day, overlap...). Only pending/failed rows. */
    public function markSkipped(WaReportDelivery $row, string $error): void
    {
        DB::table('wa_report_deliveries')
            ->where('id', $row->id)
            ->whereIn('status', [WaReportDelivery::STATUS_PENDING, WaReportDelivery::STATUS_FAILED])
            ->update([
                'status' => WaReportDelivery::STATUS_SKIPPED,
                'error' => $this->cleanError($error),
                'updated_at' => now(),
            ]);
    }

    /** Build failed before any send: keep the attempt counter, remember why. */
    public function noteBuildFailed(WaReportDelivery $row, string $error): void
    {
        DB::table('wa_report_deliveries')
            ->where('id', $row->id)
            ->whereIn('status', [WaReportDelivery::STATUS_PENDING, WaReportDelivery::STATUS_FAILED])
            ->update([
                'status' => WaReportDelivery::STATUS_FAILED,
                'error' => $this->cleanError($error),
                'updated_at' => now(),
            ]);
    }

    public function cleanError(string $error): string
    {
        $error = trim((string) preg_replace('/\s+/u', ' ', SensitiveDataRedactor::redact($error)));

        return mb_substr($error, 0, 160);
    }

    private function finish(WaReportDelivery $row, string $status, ?string $error, ?string $providerMessageId = null, bool $sent = false): void
    {
        $values = [
            'status' => $status,
            'error' => $error === null ? null : $this->cleanError($error),
            'updated_at' => now(),
        ];
        if ($providerMessageId !== null) {
            $values['provider_message_id'] = mb_substr($providerMessageId, 0, 120);
        }
        if ($sent) {
            $values['sent_at'] = now();
        }

        // Only the owner of the claim (status sending) may finish the row.
        DB::table('wa_report_deliveries')
            ->where('id', $row->id)
            ->where('status', WaReportDelivery::STATUS_SENDING)
            ->update($values);
    }
}
