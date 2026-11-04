<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatspieService
{
    protected string $baseUrl = 'https://api.whatspie.com';

    protected string $apiKey;

    protected string $deviceId;

    public function __construct()
    {
        $this->apiKey = config('services.whatspie.key', '');
        $this->deviceId = config('services.whatspie.device', '');
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
        if (empty($this->apiKey) || empty($this->deviceId)) {
            Log::warning('Whatspie credentials not configured. Skipping message.');

            return null;
        }

        // Format phone number: convert 08xxx to 628xxx
        $formattedPhone = $this->formatPhoneNumber($phone);

        // Ensure Device ID is clean
        $deviceId = trim($this->deviceId);

        // LOCAL TESTING SAFEGUARD
        if (app()->environment('local')) {
            $testNumber = config('services.whatspie.test_number');

            // If test number is defined and this phone matches it, allow it.
            // Otherwise, just simulate a success response to avoid spamming real users locally.
            if (empty($testNumber) || $this->formatPhoneNumber($testNumber) !== $formattedPhone) {
                Log::info("[LOCAL SAFEGUARD] Simulated WhatsApp to {$formattedPhone}: {$message}");

                return [
                    'status' => 'success',
                    'message' => 'Simulated message in local environment',
                    'simulated' => true,
                ];
            }
        }

        try {
            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'Authorization' => 'Bearer '.$this->apiKey,
            ])->post("{$this->baseUrl}/messages", [
                'device' => $deviceId,
                'receiver' => $formattedPhone,
                'type' => 'chat',
                'message' => $message,
                'simulate_typing' => 1,
            ]);

            if ($response->successful()) {
                Log::info("WhatsApp sent to {$formattedPhone}");

                return $response->json();
            } else {
                Log::error('Whatspie Error: '.$response->body());

                return null;
            }
        } catch (\Exception $e) {
            Log::error('Whatspie Exception: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Normalize phone number to international Indonesian format (62...).
     * Handles: +62xxx, 62xxx, 08xxx, 8xxx, spaces, dashes, dots.
     */
    private function formatPhoneNumber(string $phone): string
    {
        // Strip all non-numeric characters (spaces, dashes, dots, +)
        $phone = preg_replace('/[^0-9]/', '', trim($phone));

        // Remove leading zeros beyond one (e.g. 0008 -> keep processing)
        // 08xxxxxxxxx -> 628xxxxxxxxx
        if (str_starts_with($phone, '0')) {
            $phone = '62'.substr($phone, 1);
        }

        // 8xxxxxxxxx (without country code) -> 628xxxxxxxxx
        elseif (str_starts_with($phone, '8')) {
            $phone = '62'.$phone;
        }

        // Already correct: 62xxxxxxxxx — no change needed
        return $phone;
    }
}
