<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\EvolutionApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class NotifyPsbActivationGroup extends Command
{
    protected $signature = 'psb:notify-activation-group
                            {message : Text message to send to activation group}
                            {--pin : Whether to pin the message in the group}';

    protected $description = 'Send an announcement to the WhatsApp activation group and optionally pin it';

    public function handle(EvolutionApiService $evo): int
    {
        $groupJids = Setting::get('psb_wa_group_ids', []);
        $groupJid = '';
        if (is_array($groupJids) && count($groupJids) > 0) {
            $groupJid = (string) $groupJids[0];
        }

        if ($groupJid === '') {
            $this->error('No activation group configured in Settings (psb_wa_group_ids).');

            return self::FAILURE;
        }

        $rawMessage = (string) $this->argument('message');
        $formatted = "*[UPDATE PSB WA - SKYNET EBILLING]*\n"
            ."--------------------------------\n"
            .'*Waktu:* '.now('Asia/Jakarta')->format('d M Y H:i')." WIB\n\n"
            .$rawMessage."\n\n"
            ."--------------------------------\n"
            .'_Pesan resmi dari Skynet eBilling System_';

        $this->info("Sending announcement to group: {$groupJid}");

        $response = $evo->sendGroupMessage($groupJid, $formatted);
        $ok = (bool) ($response['success'] ?? false);

        if (! $ok) {
            $this->error('Failed to send message: '.($response['error'] ?? 'Unknown error'));
            Log::error('[PSB] notify-activation-group failed', ['response' => $response]);

            return self::FAILURE;
        }

        $this->info('Message sent successfully!');

        $messageId = $response['message_id'] ?? $response['id'] ?? null;
        if ((bool) $this->option('pin') && $messageId) {
            $this->info("Pinning message {$messageId} in group {$groupJid}...");
            $pinRes = $evo->pinMessage($groupJid, (string) $messageId);
            if ($pinRes['success'] ?? false) {
                $this->info('Message pinned successfully!');
            } else {
                $this->warn('Failed to pin message: '.($pinRes['error'] ?? 'Pin API error'));
            }
        }

        return self::SUCCESS;
    }
}
