<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\Transaction;
use Carbon\Carbon;

class BillingWaTemplateService
{
    public const EVENT_PAYMENT = 'payment';

    public const EVENT_CUSTOMER_CREATED = 'customer_created';

    public const EVENT_ISOLATION = 'isolation';

    public function defaultTemplate(string $event): string
    {
        return match ($event) {
            self::EVENT_PAYMENT => "Halo {name},\n\nPembayaran Anda sebesar {amount} untuk invoice {invoice_code} sudah kami terima ({paid_at}).\n\nKuitansi atas nama: {name}\n{receipt_link}\n\nTerima kasih.\n{company_name}",
            self::EVENT_CUSTOMER_CREATED => "Halo {name},\n\nSelamat bergabung di {company_name}.\nPaket: {package}\nArea: {area}\nKode pelanggan: {code}\n\nSimpan nomor ini untuk info tagihan & layanan.",
            self::EVENT_ISOLATION => "Halo {name},\n\nLayanan internet Anda diisolir karena tagihan tertunggak.\nSegera lakukan pembayaran: {payment_link}\n\n{company_name}",
            default => '',
        };
    }

    public function settingKeyEnabled(string $event): string
    {
        return match ($event) {
            self::EVENT_PAYMENT => 'billing_wa_payment_enabled',
            self::EVENT_CUSTOMER_CREATED => 'billing_wa_customer_created_enabled',
            self::EVENT_ISOLATION => 'billing_wa_isolation_enabled',
            default => 'billing_wa_'.$event.'_enabled',
        };
    }

    public function settingKeyTemplate(string $event): string
    {
        return match ($event) {
            self::EVENT_PAYMENT => 'billing_wa_payment_template',
            self::EVENT_CUSTOMER_CREATED => 'billing_wa_customer_created_template',
            self::EVENT_ISOLATION => 'billing_wa_isolation_template',
            default => 'billing_wa_'.$event.'_template',
        };
    }

    /**
     * Isolation intentionally never fires in phase 1 (even if toggle is on).
     */
    public function isEnabled(string $event): bool
    {
        if ($event === self::EVENT_ISOLATION) {
            return false;
        }

        $key = $this->settingKeyEnabled($event);
        $default = '0';
        $raw = Setting::get($key, $default);

        // Avoid PHP (bool)"0" === true
        if (is_bool($raw)) {
            return $raw;
        }

        return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }

    public function isLiveSendingEnabled(?string $event = null): bool
    {
        $key = in_array($event, [self::EVENT_PAYMENT, self::EVENT_CUSTOMER_CREATED], true)
            ? 'wa_billing_writes_enabled'
            : 'wa_broadcast_writes_enabled';

        return filter_var(
            Setting::get($key, '0'),
            FILTER_VALIDATE_BOOLEAN,
        );
    }

    public function isEventLiveSendingEnabled(string $event): bool
    {
        if ($event === self::EVENT_ISOLATION) {
            return false;
        }

        return $this->isLiveSendingEnabled($event);
    }

    public function resolve(string $event, ?int $areaId = null): string
    {
        if ($areaId) {
            $map = Setting::get('billing_wa_area_templates', []) ?: [];
            if (is_array($map)) {
                $areaKey = (string) $areaId;
                $override = $map[$areaKey][$event] ?? $map[$areaId][$event] ?? null;
                if (is_string($override) && trim($override) !== '') {
                    return $override;
                }
            }
        }

        $global = (string) Setting::get($this->settingKeyTemplate($event), '');
        if (trim($global) !== '') {
            return $global;
        }

        return $this->defaultTemplate($event);
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    public function render(string $template, Customer $customer, array $ctx = []): string
    {
        $customer->loadMissing(['package', 'area']);

        /** @var Invoice|null $invoice */
        $invoice = $ctx['invoice'] ?? null;
        /** @var Transaction|null $transaction */
        $transaction = $ctx['transaction'] ?? null;

        $amount = $ctx['amount']
            ?? $invoice?->amount
            ?? $customer->package?->price
            ?? 0;

        $paidAt = $transaction?->paid_at ?? $ctx['paid_at'] ?? now();
        if ($paidAt instanceof Carbon) {
            $paidAtStr = $paidAt->timezone('Asia/Jakarta')->format('d/m/Y H:i');
        } else {
            $paidAtStr = (string) $paidAt;
        }

        $due = $invoice?->due_date;
        $dueStr = $due
            ? (is_string($due) ? $due : Carbon::parse($due)->timezone('Asia/Jakarta')->format('d/m/Y'))
            : '-';

        $map = [
            '{name}' => (string) ($customer->name ?? ''),
            '{code}' => (string) ($customer->code ?? ''),
            '{phone}' => (string) ($customer->phone ?? ''),
            '{area}' => (string) ($customer->area?->name ?? '-'),
            '{package}' => (string) ($customer->package?->name ?? '-'),
            '{amount}' => 'Rp '.number_format((float) $amount, 0, ',', '.'),
            '{billing_amount}' => 'Rp '.number_format((float) $amount, 0, ',', '.'),
            '{invoice_code}' => (string) ($invoice?->code ?? '-'),
            '{due_date}' => $dueStr,
            '{paid_at}' => $paidAtStr,
            '{period}' => (string) ($invoice?->period ?? '-'),
            '{payment_link}' => $this->paymentLink($invoice, $ctx),
            '{receipt_link}' => (string) ($ctx['receipt_link'] ?? '-'),
            '{company_name}' => (string) Setting::get('company_name', 'Skynet Network'),
        ];

        $rendered = str_replace(array_keys($map), array_values($map), $template);

        // Preserve legacy custom templates while guaranteeing exactly one
        // receipt block for payment notifications.
        if (($ctx['event'] ?? null) === self::EVENT_PAYMENT
            && ! str_contains($template, '{receipt_link}')
            && ($ctx['receipt_link'] ?? '') !== ''
            && ! str_contains($rendered, (string) $ctx['receipt_link'])) {
            $rendered = rtrim($rendered)."\n\nKuitansi atas nama: ".($customer->name ?? '')."\n".$ctx['receipt_link'];
        }

        return $rendered;
    }

    /**
     * {payment_link}: the stored invoice link when present (previous
     * behaviour), else the public /pay page of the invoice, which shows the
     * online payment options when enabled and the bank accounts otherwise.
     * Before, invoices without a stored link rendered "-".
     *
     * @param  array<string, mixed>  $ctx
     */
    private function paymentLink(mixed $invoice, array $ctx): string
    {
        $stored = $invoice instanceof Invoice ? $invoice->payment_link : null;
        if (filled($stored)) {
            return (string) $stored;
        }
        if (filled($ctx['payment_link'] ?? null)) {
            return (string) $ctx['payment_link'];
        }

        $uuid = $invoice instanceof Invoice ? $invoice->uuid : null;

        return filled($uuid) ? route('public.invoice.show', $uuid) : '-';
    }

    /**
     * Snapshot for Settings UI.
     *
     * @return array<string, mixed>
     */
    public function configSnapshot(): array
    {
        return [
            'payment_enabled' => filter_var(Setting::get('billing_wa_payment_enabled', '0'), FILTER_VALIDATE_BOOLEAN),
            'customer_created_enabled' => filter_var(Setting::get('billing_wa_customer_created_enabled', '0'), FILTER_VALIDATE_BOOLEAN),
            'isolation_enabled' => false, // phase 1: never active
            'isolation_deferred' => true,
            'billing_sending_enabled' => $this->isLiveSendingEnabled(self::EVENT_PAYMENT),
            'broadcast_sending_enabled' => $this->isLiveSendingEnabled(),
            // Kept for older clients; it now explicitly means mass broadcast.
            'live_sending_enabled' => $this->isLiveSendingEnabled(),
            'payment_template' => (string) Setting::get('billing_wa_payment_template', $this->defaultTemplate(self::EVENT_PAYMENT)),
            'customer_created_template' => (string) Setting::get('billing_wa_customer_created_template', $this->defaultTemplate(self::EVENT_CUSTOMER_CREATED)),
            'isolation_template' => (string) Setting::get('billing_wa_isolation_template', $this->defaultTemplate(self::EVENT_ISOLATION)),
            'area_templates' => Setting::get('billing_wa_area_templates', []) ?: [],
            'placeholders' => [
                '{name}', '{code}', '{phone}', '{area}', '{package}', '{amount}',
                '{invoice_code}', '{due_date}', '{paid_at}', '{period}', '{payment_link}', '{receipt_link}', '{company_name}',
            ],
            'last_invoice_sync_at' => Setting::get('billing_invoice_last_sync_at', null),
        ];
    }
}
