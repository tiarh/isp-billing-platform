<?php

namespace App\Jobs;

use App\Contracts\WaProvider;
use App\Models\BillingWaLog;
use App\Models\Setting;
use App\Services\FinanceBulkWaService;
use App\Services\IndonesianPhoneNormalizer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

class SendFinanceBulkWaJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 90;

    public int $uniqueFor = 900;

    public function __construct(
        public int $logId,
        public string $phone,
        public string $message,
    ) {
        $this->onQueue('billing');
    }

    public function uniqueId(): string
    {
        return 'finance-bulk-wa:'.$this->logId;
    }

    public function handle(
        WaProvider $provider,
        IndonesianPhoneNormalizer $phones,
        FinanceBulkWaService $bulkWa,
    ): void {
        $log = BillingWaLog::query()->find($this->logId);
        if (! $log) {
            return;
        }
        if (in_array($log->status, SendBillingWaJob::NO_RESEND_STATUSES, true)) {
            return;
        }
        if ($log->status === 'sending') {
            // Previous attempt died mid-send; the message may have gone out.
            $this->updateLog('unconfirmed', 'Attempt sebelumnya terhenti saat mengirim — status kirim tidak pasti, tidak di-retry otomatis.');

            return;
        }

        $enabled = filter_var(Setting::get('finance_bulk_wa_enabled', '0'), FILTER_VALIDATE_BOOLEAN);
        $dryRun = filter_var(Setting::get('finance_bulk_wa_dry_run', '1'), FILTER_VALIDATE_BOOLEAN);
        if (! $enabled || $dryRun) {
            $this->updateLog('skipped', 'Finance bulk WA disabled or dry-run.');

            return;
        }
        if (! $bulkWa->isStillEligible($log)) {
            $this->updateLog('skipped', 'Invoice sudah tidak outstanding atau relasinya berubah.');

            return;
        }
        if (! $provider->isConfigured()) {
            $this->updateLog('failed', 'Evolution API not configured.');

            return;
        }

        $state = $provider->connectionState();
        if ($state !== 'open') {
            $willRetry = $this->attempts() < $this->tries;
            $this->updateLog($willRetry ? 'retrying' : 'failed', "WA billing paused: state={$state}.");
            if ($willRetry) {
                $this->release(30 * $this->attempts());
            }

            return;
        }

        $normalized = $phones->normalize($this->phone);
        if (! $normalized['is_valid']) {
            $this->updateLog('skipped', 'Nomor HP tidak valid.');

            return;
        }

        $limit = min(600, max(1, (int) Setting::get('finance_bulk_wa_rate_limit_per_minute', 10)));
        $rateKey = 'finance-bulk-wa:'.now('Asia/Jakarta')->format('YmdHi');
        if (RateLimiter::tooManyAttempts($rateKey, $limit)) {
            $this->release(60);

            return;
        }
        RateLimiter::hit($rateKey, 60);

        $this->updateLog('sending', null);
        try {
            $result = $provider->send((string) $normalized['evolution_recipient'], $this->message);
        } catch (Throwable) {
            // Outcome unknown — the request may have reached Evolution.
            $this->updateLog('unconfirmed', 'Evolution provider send threw; status kirim tidak pasti.');

            return;
        }

        if ((bool) ($result['success'] ?? false)) {
            $this->updateLog('sent', null, (string) ($result['message_id'] ?? ''));
            Log::info('[FinanceBulkWa] sent', ['log_id' => $this->logId]);

            return;
        }

        if ((bool) ($result['delivery_unknown'] ?? false)) {
            $this->updateLog('unconfirmed', 'Timeout Evolution: status kirim tidak pasti, tidak di-retry otomatis.');

            return;
        }

        $willRetry = $this->attempts() < $this->tries;
        $this->updateLog($willRetry ? 'retrying' : 'failed', 'Evolution provider rejected billing message.');
        if ($willRetry) {
            $this->release(30 * $this->attempts());
        }
    }

    public function failed(?Throwable $exception): void
    {
        $status = BillingWaLog::query()->whereKey($this->logId)->value('status');
        if ($status === 'sending') {
            $this->updateLog('unconfirmed', 'Job WA terhenti saat mengirim — status kirim tidak pasti.');
        } elseif (in_array($status, ['pending', 'retrying'], true)) {
            $this->updateLog('failed', 'Job WA gagal: '.($exception ? class_basename($exception) : 'unknown'));
        }
    }

    private function updateLog(string $status, ?string $error, string $messageId = ''): void
    {
        BillingWaLog::query()->whereKey($this->logId)->update([
            'status' => $status,
            'error' => $error,
            'sent_at' => $status === 'sent' ? now() : null,
            'provider_message_id' => $messageId !== '' ? $messageId : null,
        ]);
    }
}
