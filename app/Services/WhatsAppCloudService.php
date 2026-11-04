<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * WhatsAppCloudService — Official Meta WhatsApp Cloud API.
 *
 * 100% safe, official API — will NOT get your number banned.
 * Free tier: 1,000 conversations/month.
 *
 * Setup:
 *   1. Go to https://business.whatsapp.com/developers/developer-hub
 *   2. Create a Meta Business app
 *   3. Add WhatsApp product
 *   4. Get PHONE_NUMBER_ID and ACCESS_TOKEN
 *   5. Add template messages (or use free-form for 24h window)
 *
 * For template messages (outside 24h window), create template at:
 * https://business.whatsapp.com/wa/manage/message-templates/
 * Template name: "ticket_assignment" with body like:
 * "Tiket baru ditugaskan: {{1}} — {{2}}"
 */
class WhatsAppCloudService
{
    private string $accessToken;

    private string $phoneNumberId;

    private string $apiVersion;

    public function __construct()
    {
        $this->accessToken = config('services.whatsapp_cloud.access_token', '');
        $this->phoneNumberId = config('services.whatsapp_cloud.phone_number_id', '');
        $this->apiVersion = config('services.whatsapp_cloud.api_version', 'v21.0');
    }

    public function isConfigured(): bool
    {
        return ! empty($this->accessToken) && ! empty($this->phoneNumberId);
    }

    public function send(string $to, string $message): array
    {
        if (! $this->isConfigured()) {
            Log::warning('[WhatsApp Cloud] Not configured — missing access_token or phone_number_id');

            return ['success' => false, 'error' => 'Not configured'];
        }

        // Format phone number (ensure country code, no +, no spaces)
        $to = $this->formatPhone($to);

        try {
            // Try free-form message first (works within 24h customer window)
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$this->accessToken,
                'Content-Type' => 'application/json',
            ])->post("https://graph.facebook.com/{$this->apiVersion}/{$this->phoneNumberId}/messages", [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'text',
                'text' => [
                    'body' => $message,
                ],
            ]);

            $body = $response->json();

            if ($response->successful() && isset($body['messages'])) {
                Log::info('[WhatsApp Cloud] Message sent', ['to' => $to, 'message_id' => $body['messages'][0]['id'] ?? null]);

                return ['success' => true, 'message_id' => $body['messages'][0]['id'] ?? null];
            }

            // If free-form fails (outside 24h window), try template
            if (isset($body['error']['code']) && $body['error']['code'] === 1006) {
                return $this->sendTemplate($to, $message);
            }

            Log::error('[WhatsApp Cloud] Send failed', ['to' => $to, 'response' => $body]);

            return ['success' => false, 'error' => $body['error']['message'] ?? 'Unknown error'];
        } catch (\Exception $e) {
            Log::error('[WhatsApp Cloud] Exception', ['to' => $to, 'error' => $e->getMessage()]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function sendTemplate(string $to, string $message): array
    {
        $templateName = config('services.whatsapp_cloud.template_name', 'ticket_assignment');

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$this->accessToken,
                'Content-Type' => 'application/json',
            ])->post("https://graph.facebook.com/{$this->apiVersion}/{$this->phoneNumberId}/messages", [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'template',
                'template' => [
                    'name' => $templateName,
                    'language' => ['code' => 'id'],
                    'components' => [
                        [
                            'type' => 'body',
                            'parameters' => [
                                ['type' => 'text', 'text' => $message],
                            ],
                        ],
                    ],
                ],
            ]);

            $body = $response->json();

            if ($response->successful() && isset($body['messages'])) {
                Log::info('[WhatsApp Cloud] Template message sent', ['to' => $to]);

                return ['success' => true, 'message_id' => $body['messages'][0]['id'] ?? null];
            }

            Log::error('[WhatsApp Cloud] Template send failed', ['to' => $to, 'response' => $body]);

            return ['success' => false, 'error' => $body['error']['message'] ?? 'Template error'];
        } catch (\Exception $e) {
            Log::error('[WhatsApp Cloud] Template exception', ['error' => $e->getMessage()]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function formatPhone(string $phone): string
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (str_starts_with($phone, '0')) {
            $phone = '62'.substr($phone, 1);
        } elseif (str_starts_with($phone, '8')) {
            $phone = '62'.$phone;
        }

        return $phone;
    }
}
