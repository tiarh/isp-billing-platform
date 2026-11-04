<?php

namespace App\Jobs;

use App\Contracts\WaProvider;
use App\Models\BillingWaLog;
use App\Services\BillingWaTemplateService;
use App\Services\IndonesianPhoneNormalizer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SendBillingWaJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 90;

    public int $uniqueFor = 900;

    public function __construct(
        public string $phone,
        public string $message,
        public ?int $logId = null,
        public ?string $event = null,
        public ?int $customerId = null,
    ) {
        $this->onQueue('billing');
    }

    public function uniqueId(): string
    {
        return 'billing-wa:'.($this->logId ?: sha1($this->phone.'|'.$this->message));
    }

    /**
     * Log statuses where this job must not (re)send: already delivered, a
     * terminal outcome, or an earlier attempt whose delivery is unknown.
     */
    public const NO_RESEND_STATUSES = ['sent', 'skipped', 'unconfirmed'];

    public function handle(WaProvider $provider): void
    {
        $current = $this->currentLogStatus();
        if ($current !== null && in_array($current, self::NO_RESEND_STATUSES, true)) {
            Log::info('[BillingWa] already final, not resending', [
                'log_id' => $this->logId,
                'status' => $current,
            ]);

            return;
        }

        if ($current === 'sending') {
            // A previous attempt died between "about to send" and recording the
            // outcome (worker killed / timeout). The message may have gone out.
            $this->updateLog('unconfirmed', 'Attempt sebelumnya terhenti saat mengirim — status kirim tidak pasti, tidak di-retry otomatis.');

            return;
        }

        $normalized = app(IndonesianPhoneNormalizer::class)->normalize($this->phone);
        if (! $normalized['is_valid']) {
            $this->skipLog('Nomor HP tidak valid: '.($normalized['reason'] ?? 'review'));

            return;
        }

        if (! $provider->isConfigured()) {
            $this->failLog('Evolution API not configured');

            return;
        }

        $billingEnabled = app(BillingWaTemplateService::class)->isEventLiveSendingEnabled(
            $this->event ?: BillingWaTemplateService::EVENT_PAYMENT
        );
        $billingState = $billingEnabled ? $provider->connectionState() : 'disabled';

        if (! $billingEnabled || $billingState !== 'open') {
            $reason = ! $billingEnabled
                ? 'Dry-run: WA billing belum di-enable.'
                : "WA billing dijeda: state={$billingState}.";

            $this->skipLog($reason);
            Log::info('[BillingWa] dry-run paused', [
                'event' => $this->event,
                'customer_id' => $this->customerId,
                'reason' => $reason,
            ]);

            return;
        }

        $this->updateLog('sending', null);
        $result = $provider->send((string) $normalized['evolution_recipient'], $this->message);
        $ok = (bool) ($result['success'] ?? false);

        if ($ok) {
            $this->successLog((string) ($result['message_id'] ?? ''));
            Log::info('[BillingWa] sent', [
                'event' => $this->event,
                'customer_id' => $this->customerId,
                'phone' => $this->maskedPhone((string) $normalized['canonical_local']),
            ]);

            return;
        }

        $err = (string) ($result['error'] ?? 'Evolution send failed');

        if ((bool) ($result['delivery_unknown'] ?? false)) {
            // Read timeout etc.: Evolution may still deliver. Never auto-retry.
            $this->updateLog('unconfirmed', $err);
            Log::warning('[BillingWa] delivery unknown, not retrying', [
                'event' => $this->event,
                'customer_id' => $this->customerId,
                'error' => $err,
            ]);

            return;
        }

        $willRetry = $this->attempts() < $this->tries;
        $this->updateLog($willRetry ? 'retrying' : 'failed', $err);
        Log::warning('[BillingWa] send failed', [
            'event' => $this->event,
            'customer_id' => $this->customerId,
            'error' => $err,
            'will_retry' => $willRetry,
        ]);

        // Retry only failures where Evolution did not accept the message.
        if ($willRetry) {
            $this->release(30 * $this->attempts());
        }
    }

    /**
     * Job died (exception / max attempts). Close out an in-flight log so the
     * dedupe layer does not treat it as pending forever.
     */
    public function failed(?Throwable $exception): void
    {
        $current = $this->currentLogStatus();
        if ($current === null || in_array($current, self::NO_RESEND_STATUSES, true) || $current === 'failed') {
            return;
        }

        $this->updateLog(
            $current === 'sending' ? 'unconfirmed' : 'failed',
            'Job WA billing gagal: '.($exception ? class_basename($exception) : 'unknown'),
        );
    }

    protected function currentLogStatus(): ?string
    {
        if (! $this->logId || ! Schema::hasTable('billing_wa_logs')) {
            return null;
        }

        $status = BillingWaLog::query()->whereKey($this->logId)->value('status');

        return is_string($status) ? $status : null;
    }

    protected function updateLog(string $status, ?string $error): void
    {
        if (! $this->logId || ! Schema::hasTable('billing_wa_logs')) {
            return;
        }
        BillingWaLog::query()->whereKey($this->logId)->update([
            'status' => $status,
            'error' => $error,
        ]);
    }

    protected function successLog(string $messageId = ''): void
    {
        if (! $this->logId || ! Schema::hasTable('billing_wa_logs')) {
            return;
        }
        BillingWaLog::query()->whereKey($this->logId)->update([
            'status' => 'sent',
            'error' => null,
            'sent_at' => now(),
            'provider_message_id' => $messageId !== '' ? $messageId : null,
        ]);
    }

    protected function failLog(string $error): void
    {
        $this->updateLog('failed', $error);
    }

    protected function skipLog(string $reason): void
    {
        if (! $this->logId || ! Schema::hasTable('billing_wa_logs')) {
            return;
        }

        BillingWaLog::query()->whereKey($this->logId)->update([
            'status' => 'skipped',
            'error' => $reason,
        ]);
    }

    private function maskedPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return strlen($digits) <= 4
            ? str_repeat('*', strlen($digits))
            : substr($digits, 0, 2).str_repeat('*', max(2, strlen($digits) - 4)).substr($digits, -2);
    }
}
