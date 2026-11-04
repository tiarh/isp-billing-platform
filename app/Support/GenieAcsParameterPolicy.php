<?php

namespace App\Support;

/**
 * Audit S5: the GenieACS "set parameter" endpoint used to accept any TR-069
 * path, including InternetGatewayDevice.ManagementServer.URL / Password, which
 * lets an operator (or a stolen session) point a CPE at a foreign ACS. Only
 * customer-facing Wi-Fi settings are writable from eBilling.
 */
class GenieAcsParameterPolicy
{
    /** @var list<string> Anchored, case-sensitive TR-098 / TR-181 Wi-Fi paths. */
    private const ALLOWED_PATTERNS = [
        // TR-098 (InternetGatewayDevice) WLAN configuration
        '/\AInternetGatewayDevice\.LANDevice\.\d{1,3}\.WLANConfiguration\.\d{1,3}\.(SSID|Enable|SSIDAdvertisementEnabled|KeyPassphrase|PreSharedKey\.\d{1,3}\.(KeyPassphrase|PreSharedKey)|BeaconType|WPAAuthenticationMode|WPAEncryptionModes|IEEE11iAuthenticationMode|IEEE11iEncryptionModes|Channel|AutoChannelEnable|MaxBitRate|Standard|RadioEnabled)\z/',
        // TR-181 (Device) Wi-Fi
        '/\ADevice\.WiFi\.SSID\.\d{1,3}\.(SSID|Enable)\z/',
        '/\ADevice\.WiFi\.AccessPoint\.\d{1,3}\.(Enable|SSIDAdvertisementEnabled)\z/',
        '/\ADevice\.WiFi\.AccessPoint\.\d{1,3}\.Security\.(KeyPassphrase|PreSharedKey|ModeEnabled)\z/',
        '/\ADevice\.WiFi\.Radio\.\d{1,3}\.(Enable|Channel|AutoChannelEnable|OperatingStandards)\z/',
    ];

    public static function allows(string $name): bool
    {
        foreach (self::ALLOWED_PATTERNS as $pattern) {
            if (preg_match($pattern, $name) === 1) {
                return true;
            }
        }

        return false;
    }

    /** Parameter values that are secrets must never reach the audit log. */
    public static function isSecret(string $name): bool
    {
        return preg_match('/(KeyPassphrase|PreSharedKey)\z/', $name) === 1;
    }
}
