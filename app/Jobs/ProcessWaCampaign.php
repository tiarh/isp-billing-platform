<?php

namespace App\Jobs;

use App\Enums\CustomerStatus;
use App\Models\Customer;
use App\Models\WaCampaign;
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
use Throwable;

class ProcessWaCampaign implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300;

    /** A partial run must not be blindly replayed; operators use "retry" instead. */
    public $tries = 1;

    public $uniqueFor = 900;

    /** Customers that must never receive a broadcast, even for target "all". */
    public const EXCLUDED_CUSTOMER_STATUSES = [CustomerStatus::Terminated->value];

    private const CHUNK = 500;

    public function __construct(public WaCampaign $campaign)
    {
        $this->onQueue('wa');
    }

    public function uniqueId(): string
    {
        return 'wa-campaign:'.$this->campaign->getKey();
    }

    public function handle(EvolutionApiService $evo, IndonesianPhoneNormalizer $phones): void
    {
        $this->campaign->update(['status' => 'processing']);

        $query = Customer::query()
            ->whereNotNull('phone')
            ->whereNotIn('status', self::EXCLUDED_CUSTOMER_STATUSES);

        if ($this->campaign->target_type === 'active') {
            $query->where('status', 'active');
        } elseif ($this->campaign->target_type === 'isolated') {
            $query->where('status', 'isolated');
        }

        if ($this->campaign->target_area_id) {
            $query->where('area_id', $this->campaign->target_area_id);
        }

        $total = (clone $query)->count();

        $this->campaign->update(['total_recipients' => $total]);

        if ($total === 0) {
            $this->campaign->update(['status' => 'completed']);

            return;
        }

        $campaignId = (int) $this->campaign->id;

        $query->select(['id', 'phone'])->chunkById(self::CHUNK, function ($customers) use ($phones, $campaignId) {
            $existing = WaCampaignRecipient::query()
                ->where('wa_campaign_id', $campaignId)
                ->whereIn('customer_id', $customers->pluck('id'))
                ->get(['id', 'customer_id', 'status', 'phone_number'])
                ->keyBy('customer_id');

            $now = now();
            $rows = [];
            foreach ($customers as $customer) {
                $normalized = $phones->normalize($customer->phone);
                $recipient = $existing->get($customer->id);

                if (! $recipient) {
                    $rows[] = [
                        'wa_campaign_id' => $campaignId,
                        'customer_id' => $customer->id,
                        'phone_number' => $normalized['canonical_local'] ?: trim((string) $customer->phone),
                        'status' => 'pending',
                        'error_message' => $normalized['is_valid'] ? null : 'Review nomor HP: '.($normalized['reason'] ?? 'invalid'),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    continue;
                }

                // Never rewrite a sent recipient. Pending snapshots can be healed
                // when a customer was entered as 812..., 62..., or +62....
                if ($recipient->status === 'pending' && $normalized['is_valid']) {
                    $recipient->update([
                        'phone_number' => $normalized['canonical_local'],
                        'error_message' => null,
                    ]);
                }
            }

            if ($rows !== []) {
                // Unique (wa_campaign_id, customer_id) makes a concurrent/replayed
                // run a no-op instead of a duplicate recipient.
                WaCampaignRecipient::query()->insertOrIgnore($rows);
            }
        });

        $broadcastEnabled = app(BillingWaTemplateService::class)->isLiveSendingEnabled();
        $billingState = $broadcastEnabled
            ? $evo->forChannel('billing')->getConnectionState()
            : 'disabled';

        if (! $broadcastEnabled || $billingState !== 'open') {
            $reason = ! $broadcastEnabled
                ? 'Dry-run: broadcast WA billing belum di-enable.'
                : "Broadcast dijeda: WA billing state={$billingState}.";

            $this->campaign->recipients()
                ->where('status', 'pending')
                ->update(['error_message' => $reason]);

            $this->campaign->update(['status' => 'paused']);

            return;
        }

        $delaySeconds = 0;
        $this->campaign->recipients()
            ->where('status', 'pending')
            ->chunkById(self::CHUNK, function ($recipients) use (&$delaySeconds) {
                foreach ($recipients as $recipient) {
                    // random delay between 4 and 9 seconds added to total delay to stagger
                    $delaySeconds += rand(4, 9);
                    SendWaCampaignMessage::dispatch($recipient)->delay(now()->addSeconds($delaySeconds));
                }
            });
    }

    public function failed(?Throwable $exception): void
    {
        $campaign = WaCampaign::find($this->campaign->getKey());
        if (! $campaign || $campaign->status === 'completed') {
            return;
        }

        $campaign->recipients()
            ->where('status', 'pending')
            ->update([
                'status' => 'failed',
                'error_message' => 'Broadcast job gagal diproses; periksa worker WA.',
            ]);
        $campaign->update(['status' => 'failed']);
    }
}
