<?php

namespace Database\Seeders;

use App\Models\SalesLead;
use App\Models\SupportTicket;
use App\Models\User;
use App\Models\WaCampaign;
use App\Models\WaCampaignRecipient;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Sandbox-only fixture for the rows-per-page browser spec: 55 fake tickets, 55 fake saleskit leads and one broadcast with 55 fake recipients,
 * so 20/50 per page and page 2 have something to show. Fake data only.
 */
class PerPageE2ESeeder extends Seeder
{
    public const COUNT = 55;

    public function run(): void
    {
        if (! app()->environment('testing')) {
            throw new RuntimeException('PerPageE2ESeeder hanya boleh dijalankan pada APP_ENV=testing.');
        }

        $admin = User::query()->where('email', 'admin@local')->firstOrFail();

        // Broadcast list (search) and recipient log (search + page 2): fake phone numbers only.
        WaCampaign::query()->firstOrCreate(
            ['name' => 'Promo Lain E2E'],
            ['message_template' => 'Tes', 'target_type' => 'all', 'status' => 'completed'],
        );
        $campaign = WaCampaign::query()->firstOrCreate(
            ['name' => 'Uji Baris Siaran'],
            ['message_template' => 'Tes', 'target_type' => 'all', 'status' => 'completed', 'total_recipients' => self::COUNT, 'sent_count' => self::COUNT],
        );
        for ($i = 1; $i <= self::COUNT; $i++) {
            WaCampaignRecipient::query()->firstOrCreate(
                ['wa_campaign_id' => $campaign->id, 'phone_number' => '08120000'.str_pad((string) $i, 3, '0', STR_PAD_LEFT)],
                ['status' => 'sent', 'sent_at' => now()],
            );
        }

        for ($i = 1; $i <= self::COUNT; $i++) {
            $label = str_pad((string) $i, 3, '0', STR_PAD_LEFT);

            SupportTicket::query()->firstOrCreate(
                ['code' => 'TCK-E2EPP-'.$label],
                [
                    'created_by' => $admin->id,
                    'gangguan' => 'Uji Baris Tiket '.$label,
                    'subject' => 'Uji Baris Tiket '.$label,
                    'status' => 'open',
                    'priority' => 'normal',
                    'type' => 'incident',
                ],
            );

            SalesLead::query()->firstOrCreate(
                ['code' => 'E2EPP-'.$label],
                [
                    'name' => 'Calon Uji Baris '.$label,
                    'daerah' => 'UJIBARIS',
                    'status' => SalesLead::STATUS_CALON,
                ],
            );
        }
    }
}
