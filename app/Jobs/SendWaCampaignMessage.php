<?php

namespace App\Jobs;

use App\Models\WaCampaignRecipient;
use App\Services\BillingWaTemplateService;
use App\Services\EvolutionApiService;
use App\Services\IndonesianPhoneNormalizer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class SendWaCampaignMessage implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [5, 15, 30];

    public int $timeout = 90;

    public int $uniqueFor = 900;

    public function __construct(public WaCampaignRecipient $recipient)
    {
        $this->onQueue('wa');
    }

    public function uniqueId(): string
    {
        return 'wa-recipient:'.$this->recipient->getKey();
    }

    public function handle(EvolutionApiService $evo, IndonesianPhoneNormalizer $phones): void
    {
        $campaign = $this->recipient->campaign;

        $this->recipient->refresh();

        if ($this->recipient->status !== 'pending' && $this->recipient->status !== 'failed') {
            return;
        }

        $normalized = $phones->normalize($this->recipient->phone_number);
        if (! $normalized['is_valid']) {
            $this->markFailed($campaign, 'Review nomor HP: '.($normalized['reason'] ?? 'invalid'));

            return;
        }

        $customer = $this->recipient->customer;
        $message = $campaign->message_template;
        $displayName = $this->recipient->customer_name ?: ($customer?->name ?? '');

        if ($customer) {
            $billingAmount = $customer->package ? $customer->package->price : 0;
            $message = str_replace('{billing_amount}', 'Rp '.number_format($billingAmount, 0, ',', '.'), $message);
        } else {
            $message = str_replace('{billing_amount}', '', $message);
        }

        $message = str_replace('{name}', $displayName, $message);

        // Throttle mass broadcast sending to avoid session disconnect (1.2s per msg)
        usleep(1200000);

        // Use billing channel instance for broadcast sending
        $billingEvo = $evo->forChannel('billing');
        $broadcastEnabled = app(BillingWaTemplateService::class)->isLiveSendingEnabled();
        $billingState = $broadcastEnabled ? $billingEvo->getConnectionState() : 'disabled';

        if (! $broadcastEnabled || $billingState !== 'open') {
            $reason = ! $broadcastEnabled
                ? 'Dry-run: broadcast WA billing belum di-enable.'
                : "Broadcast dijeda: WA billing state={$billingState}.";

            DB::transaction(function () use ($campaign, $reason) {
                $campaign->refresh();
                $this->recipient->update(['error_message' => $reason]);
                if ($campaign->status !== 'paused') {
                    $campaign->update(['status' => 'paused']);
                }
            });

            return;
        }

        // Image + caption or plain text via Evolution (configured gateway)
        $response = $campaign->image_path
            ? $billingEvo->sendImage((string) $normalized['evolution_recipient'], $campaign->image_path, $message)
            : $billingEvo->send((string) $normalized['evolution_recipient'], $message);

        $ok = (bool) ($response['success'] ?? false);
        $errorMessage = $response['error'] ?? 'Evolution API error.';

        // A read timeout means Evolution may still deliver (it applies `delay` +
        // presence before replying). Retrying would double-send the broadcast,
        // so record it as `unconfirmed` (mungkin terkirim) instead of `failed`;
        // "Ulang Gagal" never touches unconfirmed rows.
        $deliveryUnknown = ! $ok && (bool) ($response['delivery_unknown'] ?? false);

        if (! $ok && ! $deliveryUnknown && $this->isTransientGatewayError($errorMessage) && $this->attempts() < $this->tries) {
            $this->release($this->backoff[$this->attempts() - 1] ?? 30);

            return;
        }

        DB::transaction(function () use ($campaign, $ok, $deliveryUnknown, $errorMessage, $response) {
            $campaign->refresh();

            if (! $ok && $deliveryUnknown) {
                $this->recipient->update([
                    'status' => 'unconfirmed',
                    'error_message' => $errorMessage,
                ]);
                $campaign->increment('unconfirmed_count');
            } elseif ($ok) {
                $this->recipient->update([
                    'status' => 'sent',
                    'sent_at' => now(),
                    'provider_message_id' => $response['message_id'] ?? null,
                    'error_message' => null,
                ]);
                $campaign->increment('sent_count');
            } else {
                $this->recipient->update([
                    'status' => 'failed',
                    'error_message' => $errorMessage,
                ]);
                $campaign->increment('failed_count');
            }

            $totalProcessed = $campaign->sent_count + $campaign->failed_count + $campaign->unconfirmed_count;
            if ($totalProcessed >= $campaign->total_recipients && $campaign->status !== 'completed') {
                $campaign->update(['status' => 'completed']);
            }
        });
    }

    protected function isTransientGatewayError(string $errorMessage): bool
    {
        $message = strtolower($errorMessage);

        return str_contains($message, 'timeout')
            || str_contains($message, 'timed out')
            || str_contains($message, 'session closed')
            || str_contains($message, 'connection closed')
            || str_contains($message, 'gateway tidak terhubung')
            || str_contains($message, 'evolution api');
    }

    protected function markFailed($campaign, string $errorMessage): void
    {
        DB::transaction(function () use ($campaign, $errorMessage) {
            $campaign->refresh();
            $this->recipient->update([
                'status' => 'failed',
                'error_message' => $errorMessage,
            ]);
            $campaign->increment('failed_count');
            $totalProcessed = $campaign->sent_count + $campaign->failed_count + $campaign->unconfirmed_count;
            if ($totalProcessed >= $campaign->total_recipients && $campaign->status !== 'completed') {
                $campaign->update(['status' => 'completed']);
            }
        });
    }
}
