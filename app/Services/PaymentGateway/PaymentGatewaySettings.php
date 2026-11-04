<?php

namespace App\Services\PaymentGateway;

use App\Models\Customer;
use App\Models\PaymentGatewayTransaction;
use App\Models\Setting;

/**
 * Provider-independent online payment settings (Settings > Payment Gateway).
 */
class PaymentGatewaySettings
{
    public const FEE_BEARER = 'payment_gateway_fee_bearer';

    public const VA_EXPIRY_MINUTES = 'payment_gateway_va_expiry_minutes';

    public const MIN_AMOUNT = 'payment_gateway_min_amount';

    public const PLACEHOLDER_EMAIL_DOMAIN = 'payment_gateway_placeholder_email_domain';

    /** 3 hours: a short window limits double payment after "Ganti metode" (owner decision 2026-10-16). */
    public const DEFAULT_VA_EXPIRY_MINUTES = 180;

    /** Duitku rejects transactions below Rp10.000. */
    public const DEFAULT_MIN_AMOUNT = 10000;

    /** Reserved, never-deliverable domain for customers without an email (RFC 2606 style). */
    public const DEFAULT_PLACEHOLDER_EMAIL_DOMAIN = 'invalid.local';

    /** Pilot allowlist: customer ids that may start an online payment. Empty = everyone. */
    public const PILOT_CUSTOMER_IDS = 'payment_gateway_pilot_customer_ids';

    /**
     * @return list<int>
     */
    public function pilotCustomerIds(): array
    {
        $value = Setting::get(self::PILOT_CUSTOMER_IDS, []);
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return array_values(array_unique(array_filter(
            array_map('intval', is_array($value) ? $value : []),
            fn (int $id): bool => $id > 0,
        )));
    }

    public function pilotActive(): bool
    {
        return $this->pilotCustomerIds() !== [];
    }

    /**
     * May this customer START an online payment? Empty allowlist = yes.
     * Never used for callbacks: a paid VA is always recorded.
     */
    public function pilotAllows(?int $customerId): bool
    {
        $ids = $this->pilotCustomerIds();

        return $ids === [] || ($customerId !== null && in_array($customerId, $ids, true));
    }

    /** Default per owner decision: the customer pays the gateway fee. */
    public function feeBearer(): string
    {
        $value = (string) Setting::get(self::FEE_BEARER, PaymentGatewayTransaction::FEE_BEARER_CUSTOMER);

        return $value === PaymentGatewayTransaction::FEE_BEARER_MERCHANT
            ? PaymentGatewayTransaction::FEE_BEARER_MERCHANT
            : PaymentGatewayTransaction::FEE_BEARER_CUSTOMER;
    }

    /** Validity of virtual account / retail payment codes. */
    public function vaExpiryMinutes(): int
    {
        $minutes = (int) Setting::get(self::VA_EXPIRY_MINUTES, self::DEFAULT_VA_EXPIRY_MINUTES);

        return min(max($minutes, 60), 10080);
    }

    public function minAmount(): int
    {
        return max(1, (int) Setting::get(self::MIN_AMOUNT, self::DEFAULT_MIN_AMOUNT));
    }

    public function placeholderEmailDomain(): string
    {
        $domain = strtolower(trim((string) Setting::get(self::PLACEHOLDER_EMAIL_DOMAIN, self::DEFAULT_PLACEHOLDER_EMAIL_DOMAIN)));

        return preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain) ? $domain : self::DEFAULT_PLACEHOLDER_EMAIL_DOMAIN;
    }

    /** Customer email when valid, otherwise customer-{id}@{placeholder domain}. */
    public function emailFor(?Customer $customer): string
    {
        $email = trim((string) ($customer?->email ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && strlen($email) <= 255) {
            return $email;
        }

        return 'customer-'.($customer?->id ?? 0).'@'.$this->placeholderEmailDomain();
    }
}
