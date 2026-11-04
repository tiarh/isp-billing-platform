<?php

namespace App\Services\PaymentGateway\Duitku;

/**
 * Duitku signature formulas.
 *
 * Current docs (docs.duitku.com, 2026): HMAC-SHA256 keyed with the API key.
 * Older docs ("legacy"): plain hash over the same string with the API key
 * appended (MD5 for inquiry/callback/status, SHA256 for getpaymentmethod).
 * The mode used for outgoing requests is a setting; callbacks are accepted
 * when they match either keyed formula (both need the secret API key).
 */
class DuitkuSignature
{
    public static function inquiry(string $merchantCode, string $orderId, int $amount, string $apiKey, string $mode): string
    {
        $data = $merchantCode.$orderId.$amount;

        return $mode === DuitkuConfig::SIGNATURE_LEGACY
            ? md5($data.$apiKey)
            : hash_hmac('sha256', $data, $apiKey);
    }

    public static function paymentMethods(string $merchantCode, int $amount, string $datetime, string $apiKey, string $mode): string
    {
        $data = $merchantCode.$amount.$datetime;

        return $mode === DuitkuConfig::SIGNATURE_LEGACY
            ? hash('sha256', $data.$apiKey)
            : hash_hmac('sha256', $data, $apiKey);
    }

    public static function status(string $merchantCode, string $orderId, string $apiKey, string $mode): string
    {
        $data = $merchantCode.$orderId;

        return $mode === DuitkuConfig::SIGNATURE_LEGACY
            ? md5($data.$apiKey)
            : hash_hmac('sha256', $data, $apiKey);
    }

    /**
     * Callback: merchantCode + amount + merchantOrderId. The amount is used
     * exactly as received (string) so formatting differences cannot break it.
     */
    public static function callback(string $merchantCode, string $amount, string $orderId, string $apiKey, string $mode): string
    {
        $data = $merchantCode.$amount.$orderId;

        return $mode === DuitkuConfig::SIGNATURE_LEGACY
            ? md5($data.$apiKey)
            : hash_hmac('sha256', $data, $apiKey);
    }

    /**
     * @return string|null the matching mode, or null when the signature is invalid
     */
    public static function verifyCallback(string $merchantCode, string $amount, string $orderId, string $signature, string $apiKey): ?string
    {
        $signature = strtolower(trim($signature));
        if ($apiKey === '' || $signature === '' || $merchantCode === '' || $orderId === '') {
            return null;
        }

        foreach ([DuitkuConfig::SIGNATURE_HMAC, DuitkuConfig::SIGNATURE_LEGACY] as $mode) {
            if (hash_equals(self::callback($merchantCode, $amount, $orderId, $apiKey, $mode), $signature)) {
                return $mode;
            }
        }

        return null;
    }
}
