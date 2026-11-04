<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * FonnteService — Free WhatsApp gateway for Indonesia.
 *
 * ⚠️ DEPRECATED — This service uses an UNOFFICIAL WhatsApp API that causes WA number BANS.
 * DO NOT USE in production. Switch to WhatsAppCloudService (official Meta API) or
 * WhatsAppLinkService (wa.me deep links, zero risk).
 *
 * Website: https://fonnte.com
 * API Docs: https://docs.fonnte.com
 * Free tier: 1,000 messages/month, text-only
 *
 * Usage:
 *   - Register at https://md.fonnte.com
 *   - Add a device (scan QR with WhatsApp)
 *   - Copy your API token from the dashboard
 *   - Set FONNTE_API_KEY in .env
 *
 * @deprecated Use WhatsAppCloudService or WhatsAppLinkService instead.
 * @see WhatsAppCloudService Official Meta WhatsApp Cloud API
 * @see WhatsAppLinkService wa.me deep links (safe, no API)
 */
class FonnteService
{
    protected string $baseUrl = 'https://api.fonnte.com';

    protected string $apiKey;

    public function __construct()
    {
        $this->apiKey = config('services.fonnte.key', '');
    }

    /**
     * Send a text message to a phone number.
     *
     * @param  string  $phone  The recipient's phone number (local format 08xxx is fine, will be converted)
     * @param  string  $message  The message content
     * @return array|null The response data or null on failure
     */
    public function sendMessage(string $phone, string $message): ?array
    {
        if (empty($this->apiKey)) {
            Log::warning('Fonnte credentials not configured. Skipping message.');

            return null;
        }

        // Format phone number: convert 08xxx to 628xxx
        $formattedPhone = $this->formatPhoneNumber($phone);

        // LOCAL TESTING SAFEGUARD
        if (app()->environment('local')) {
            $testNumber = config('services.fonnte.test_number');

            // If test number is defined and this phone matches it, allow it.
            // Otherwise, just simulate a success response to avoid spamming real users locally.
            if (empty($testNumber) || $this->formatPhoneNumber($testNumber) !== $formattedPhone) {
                Log::info("[LOCAL SAFEGUARD] Simulated Fonnte WhatsApp to {$formattedPhone}: {$message}");

                return [
                    'status' => 'success',
                    'message' => 'Simulated message in local environment',
                    'simulated' => true,
                ];
            }
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => $this->apiKey,
            ])->post("{$this->baseUrl}/send", [
                'target' => $formattedPhone,
                'message' => $message,
                'countryCode' => '62', // Indonesia
            ]);

            if ($response->successful()) {
                $body = $response->json();
                Log::info("Fonnte: WhatsApp sent to {$formattedPhone}", ['response' => $body]);

                return $body;
            } else {
                Log::error('Fonnte Error: '.$response->body());

                return null;
            }
        } catch (\Exception $e) {
            Log::error('Fonnte Exception: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Check if the API key is configured.
     */
    public function isConfigured(): bool
    {
        return ! empty($this->apiKey);
    }

    /**
     * Normalize phone number to international Indonesian format (62...).
     * Handles: +62xxx, 62xxx, 08xxx, 8xxx, spaces, dashes, dots.
     */
    private function formatPhoneNumber(string $phone): string
    {
        // Strip all non-numeric characters
        $phone = preg_replace('/[^0-9]/', '', trim($phone));

        // 08xxxxxxxxx -> 628xxxxxxxxx
        if (str_starts_with($phone, '0')) {
            $phone = '62'.substr($phone, 1);
        }
        // 8xxxxxxxxx (without country code) -> 628xxxxxxxxx
        elseif (str_starts_with($phone, '8')) {
            $phone = '62'.$phone;
        }

        return $phone;
    }
}
