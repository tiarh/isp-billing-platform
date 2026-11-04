<?php

namespace App\Services;

use App\Jobs\SendBillingWaJob;
use App\Models\BillingWaLog;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Transaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class BillingWaEventService
{
    /**
     * Log statuses that block a second trigger from re-sending: queued or in
     * flight (pending/sending/retrying), delivered (sent), or delivery unknown
     * after a timeout (unconfirmed — an operator must check before re-sending).
     */
    public const IN_FLIGHT_OR_DONE = ['pending', 'sending', 'retrying', 'sent', 'unconfirmed'];

    public function __construct(
        protected BillingWaTemplateService $templates,
        protected BillingReceiptService $receipts,
        protected IndonesianPhoneNormalizer $phones,
    ) {}

    public function notifyPayment(Invoice $invoice, ?Transaction $transaction = null): void
    {
        $invoice->loadMissing(['customer.package', 'customer.area']);
        $customer = $invoice->customer;
        if (! $customer) {
            return;
        }

        $invoice->loadMissing('transactions');
        if (! $invoice->isSettled()) {
            Log::info('[BillingWa] payment notification skipped: invoice not settled', [
                'invoice_id' => $invoice->id,
            ]);

            return;
        }

        $this->dispatch(
            BillingWaTemplateService::EVENT_PAYMENT,
            $customer,
            [
                'invoice' => $invoice,
                'transaction' => $transaction,
                'amount' => $invoice->amount,
                'receipt_link' => $this->receipts->url($invoice),
            ],
            dedupeKey: 'payment:invoice:'.$invoice->id.':settled:v2',
        );
    }

    public function notifyCustomerCreated(Customer $customer): void
    {
        $customer->loadMissing(['package', 'area']);
        $this->dispatch(
            BillingWaTemplateService::EVENT_CUSTOMER_CREATED,
            $customer,
            [],
            dedupeKey: 'customer_created:'.$customer->id,
        );
    }

    /**
     * Phase 1: isolation WA intentionally not sent.
     */
    public function notifyIsolation(Customer $customer): void
    {
        Log::info('[BillingWa] isolation notify deferred (not enabled this phase)', [
            'customer_id' => $customer->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    protected function dispatch(string $event, Customer $customer, array $ctx = [], ?string $dedupeKey = null): void
    {
        if (! $this->templates->isEnabled($event)) {
            Log::info('[BillingWa] event disabled', ['event' => $event, 'customer_id' => $customer->id]);

            return;
        }

        if ($event === BillingWaTemplateService::EVENT_ISOLATION) {
            $this->notifyIsolation($customer);

            return;
        }

        $normalized = $this->phones->normalize($customer->phone);
        if (! $normalized['is_valid']) {
            $this->logSkip(
                $event,
                $customer,
                $ctx,
                $this->maskPhone((string) $customer->phone),
                'Nomor HP tidak valid: '.($normalized['reason'] ?? 'review'),
                $dedupeKey,
            );

            return;
        }

        $phone = (string) $normalized['canonical_local'];
        $ctx['event'] = $event;

        $template = $this->templates->resolve($event, $customer->area_id ? (int) $customer->area_id : null);
        if (trim($template) === '') {
            $this->logSkip($event, $customer, $ctx, $phone, 'Template kosong', $dedupeKey);

            return;
        }

        $message = $this->templates->render($template, $customer, $ctx);

        $logId = null;
        if (Schema::hasTable('billing_wa_logs')) {
            /** @var Invoice|null $invoice */
            $invoice = $ctx['invoice'] ?? null;
            $attributes = [
                'event' => $event,
                'customer_id' => $customer->id,
                'invoice_id' => $invoice?->id,
                'area_id' => $customer->area_id,
                'phone' => $this->maskPhone($phone),
                'message' => $this->redactMessage($message, $ctx),
                'status' => 'pending',
                'dedupe_key' => $dedupeKey,
            ];
            $log = null;
            if ($dedupeKey !== null) {
                $log = BillingWaLog::query()->where('dedupe_key', $dedupeKey)->first();
                if ($log && in_array($log->status, self::IN_FLIGHT_OR_DONE, true)) {
                    Log::info('[BillingWa] dedupe skip', ['dedupe_key' => $dedupeKey]);

                    return;
                }
                if ($log) {
                    $log->update($attributes);
                }
            }
            if (! $log) {
                try {
                    $log = BillingWaLog::query()->create($attributes);
                } catch (QueryException $e) {
                    // Another worker won the unique dedupe race.
                    if ($dedupeKey !== null && BillingWaLog::query()->where('dedupe_key', $dedupeKey)->exists()) {
                        return;
                    }
                    throw $e;
                }
            }
            $logId = $log->id;
        }

        SendBillingWaJob::dispatch($phone, $message, $logId, $event, $customer->id)->afterCommit();
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    protected function logSkip(string $event, Customer $customer, array $ctx, string $phone, string $reason, ?string $dedupeKey): void
    {
        Log::info('[BillingWa] skipped', [
            'event' => $event,
            'customer_id' => $customer->id,
            'reason' => $reason,
        ]);

        if (! Schema::hasTable('billing_wa_logs')) {
            return;
        }

        /** @var Invoice|null $invoice */
        $invoice = $ctx['invoice'] ?? null;
        $attributes = [
            'event' => $event,
            'customer_id' => $customer->id,
            'invoice_id' => $invoice?->id,
            'area_id' => $customer->area_id,
            'phone' => $phone ?: null,
            'message' => null,
            'status' => 'skipped',
            'error' => $reason,
            'dedupe_key' => $dedupeKey,
        ];
        if ($dedupeKey !== null) {
            BillingWaLog::query()->firstOrCreate(['dedupe_key' => $dedupeKey], $attributes);
        } else {
            BillingWaLog::query()->create($attributes);
        }
    }

    private function maskPhone(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') {
            return null;
        }

        return strlen($digits) <= 4
            ? str_repeat('*', strlen($digits))
            : substr($digits, 0, 2).str_repeat('*', max(2, strlen($digits) - 4)).substr($digits, -2);
    }

    /** @param array<string, mixed> $ctx */
    private function redactMessage(string $message, array $ctx): string
    {
        $receipt = (string) ($ctx['receipt_link'] ?? '');

        return $receipt !== '' ? str_replace($receipt, '[RECEIPT_LINK]', $message) : $message;
    }
}
