<?php

namespace App\Services\PaymentGateway;

use App\Models\PaymentGatewayTransaction;
use App\Services\PaymentGateway\Data\StatusResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Fallback for missed callbacks: asks the provider for the status of open
 * payment attempts. Duitku rate-limits transactionStatus and asks merchants
 * not to poll it in bulk, so checks are sparse: an attempt is checked only
 * after 10 minutes, then with growing back-off, at most MAX_CHECKS times,
 * and only within LOOKBACK_DAYS.
 */
class GatewayStatusChecker
{
    /** Minutes to wait before check #1, #2, #3, #4. */
    public const BACKOFF_MINUTES = [10, 30, 120, 360];

    public const MAX_CHECKS = 4;

    public const LOOKBACK_DAYS = 3;

    /** Providers whose status is confirmed only through their own signed notification. */
    private const NOTIFICATION_ONLY = ['midtrans'];

    public function __construct(
        private readonly PaymentGatewayManager $gateways,
        private readonly GatewayPaymentRecorder $recorder,
    ) {}

    /**
     * @return Collection<int, PaymentGatewayTransaction>
     */
    public function due(int $limit = 20): Collection
    {
        $now = now();

        return PaymentGatewayTransaction::query()
            ->open()
            ->whereNotIn('provider', self::NOTIFICATION_ONLY)
            ->where('created_at', '>=', $now->copy()->subDays(self::LOOKBACK_DAYS))
            ->where('created_at', '<=', $now->copy()->subMinutes(self::BACKOFF_MINUTES[0]))
            ->where('status_check_count', '<', self::MAX_CHECKS)
            ->orderBy('last_status_check_at')
            ->orderBy('id')
            ->limit(max($limit * 5, 50))
            ->get()
            ->filter(function (PaymentGatewayTransaction $txn) use ($now): bool {
                $wait = self::BACKOFF_MINUTES[min($txn->status_check_count, count(self::BACKOFF_MINUTES) - 1)];
                $since = $txn->last_status_check_at ?? $txn->created_at;

                return $since->lte($now->copy()->subMinutes($wait));
            })
            ->take($limit)
            ->values();
    }

    /**
     * @return string paid|pending|failed|expired|unknown|skipped
     */
    public function check(PaymentGatewayTransaction $txn): string
    {
        if (! $txn->isOpen() || in_array($txn->provider, self::NOTIFICATION_ONLY, true) || ! $this->gateways->has($txn->provider)) {
            return 'skipped';
        }

        $gateway = $this->gateways->driver($txn->provider);
        if (! $gateway->isConfigured()) {
            return 'skipped';
        }

        try {
            $result = $gateway->checkStatus($txn->merchant_order_id);
        } catch (\Throwable $e) {
            Log::warning('[PaymentGateway] status check failed', [
                'provider' => $txn->provider,
                'order_id' => $txn->merchant_order_id,
                'exception_class' => $e::class,
            ]);
            $result = StatusResult::unknown(['error' => class_basename($e)]);
        }

        $txn->forceFill([
            'status_check_count' => $txn->status_check_count + 1,
            'last_status_check_at' => now(),
        ])->save();

        $payload = array_merge($result->raw, ['_source' => 'status_check']);

        switch ($result->state) {
            case StatusResult::PAID:
                if ($result->amount === null) {
                    return 'unknown';
                }
                $this->recorder->recordPaid($txn, $result->amount, $txn->merchant_order_id.':status:paid', $payload);

                return 'paid';

            case StatusResult::FAILED:
                $this->recorder->recordNotPaid($txn, $txn->merchant_order_id.':status:failed', 'failed', $payload, final: true);

                return $txn->fresh()?->status === PaymentGatewayTransaction::STATUS_EXPIRED ? 'expired' : 'failed';

            case StatusResult::PENDING:
                if ($txn->status_check_count >= self::MAX_CHECKS && $txn->isExpired()) {
                    $txn->update(['status' => PaymentGatewayTransaction::STATUS_EXPIRED]);

                    return 'expired';
                }

                return 'pending';

            default:
                return 'unknown';
        }
    }

    /**
     * Attempts that ran out of checks long after their expiry are closed
     * locally so they stop showing as pending. No provider call.
     */
    public function closeStale(): int
    {
        return PaymentGatewayTransaction::query()
            ->open()
            ->where('status_check_count', '>=', self::MAX_CHECKS)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now()->subDay())
            ->update(['status' => PaymentGatewayTransaction::STATUS_EXPIRED, 'updated_at' => now()]);
    }
}
