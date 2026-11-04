<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * WhatsAppLinkService — Generates wa.me deep links for manual WhatsApp send.
 *
 * 100% safe, no API call, no ban risk, free unlimited.
 * NOT automated — admin clicks the link to open WhatsApp Web with pre-filled message.
 *
 * This is the SAFEST default when no official API is configured.
 */
class WhatsAppLinkService
{
    /**
     * Generate a wa.me link with pre-filled message.
     */
    public function generateLink(string $phone, string $message): string
    {
        $phone = $this->formatPhone($phone);

        return 'https://wa.me/'.$phone.'?text='.urlencode($message);
    }

    /**
     * "Send" by returning the link for frontend to display as clickable button.
     */
    public function send(string $to, string $message): array
    {
        $link = $this->generateLink($to, $message);
        Log::info('[WhatsApp Link] Generated wa.me link', ['to' => $to]);

        return ['success' => true, 'link' => $link, 'manual' => true];
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
