<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Customer-facing company contact (invoice PDF, public payment page).
 *
 * Each value is a Setting (group billing) whose default is the literal that
 * used to be hardcoded, so output is unchanged until an admin saves a value.
 * An empty saved value also falls back to the default.
 */
final class CompanyContact
{
    public static function defaultEmail(): string
    {
        return (string) config('site.company.email');
    }

    public static function defaultPhone(): string
    {
        return (string) config('site.company.phone');
    }

    public static function defaultWaAdmin(): string
    {
        return (string) config('site.company.wa_admin');
    }

    public static function email(): string
    {
        return self::read('company_email', self::defaultEmail());
    }

    public static function phone(): string
    {
        return self::read('company_phone', self::defaultPhone());
    }

    /**
     * Fallback WhatsApp CS number for the public payment page, used only when
     * the isolation-portal number (isolation_portal_wa_admin) is empty.
     */
    public static function waAdmin(): string
    {
        return self::read('company_wa_admin', self::defaultWaAdmin());
    }

    private static function read(string $key, string $default): string
    {
        $value = trim((string) Setting::get($key, $default));

        return $value !== '' ? $value : $default;
    }
}
