<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Setting;
use App\Models\SupportTicket;
use App\Models\WaChatSignal;
use App\Services\PsbMessageParser;
use App\Services\WaChatIntelligenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-core')]
class WaChatIntelligenceReadOnlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_allowlisted_group_message_is_logged_once_and_redacted_without_live_writes(): void
    {
        Setting::set('wa_chat_work_group_ids', ['120363-work@g.us']);

        $msg = $this->message('120363-work@g.us', 'MSG-1', 'Internet down NIK 3501010110000001 HP 081234567890 tikor -6.98221, 111.63221');
        $service = app(WaChatIntelligenceService::class);

        $first = $service->observeWebhookMessage($msg, 'Teknisi A');
        $second = $service->observeWebhookMessage($msg, 'Teknisi A');

        $this->assertNotNull($first);
        $this->assertNull($second);
        $this->assertDatabaseCount('wa_chat_signals', 1);
        $this->assertDatabaseCount('support_tickets', 0);
        $this->assertDatabaseCount('customers', 0);

        $signal = WaChatSignal::query()->firstOrFail();
        $this->assertSame('work', $signal->channel);
        $this->assertSame('incident', $signal->intent);
        $this->assertStringNotContainsString('3501010110000001', (string) $signal->redacted_text);
        $this->assertStringNotContainsString('081234567890', (string) $signal->redacted_text);
        $this->assertStringContainsString('[NIK]', (string) $signal->redacted_text);
        $this->assertStringContainsString('[HP]', (string) $signal->redacted_text);
    }

    public function test_non_allowlisted_group_is_ignored(): void
    {
        Setting::set('wa_chat_work_group_ids', ['120363-work@g.us']);

        $signal = app(WaChatIntelligenceService::class)
            ->observeWebhookMessage($this->message('120363-other@g.us', 'MSG-2', 'tolong cek gangguan'), 'Teknisi B');

        $this->assertNull($signal);
        $this->assertDatabaseCount('wa_chat_signals', 0);
    }

    public function test_activation_group_classifies_psb_migration_and_pppoe_commands(): void
    {
        Setting::set('psb_wa_group_ids', ['120363-aktivasi@g.us']);

        $service = app(WaChatIntelligenceService::class);
        $service->observeWebhookMessage($this->message('120363-aktivasi@g.us', 'MSG-3', '#psb contoh'), 'Teknisi A');
        $service->observeWebhookMessage($this->message('120363-aktivasi@g.us', 'MSG-4', '#migrasi pppoe: USER sn: FHTT12345678'), 'Teknisi A');
        $service->observeWebhookMessage($this->message('120363-aktivasi@g.us', 'MSG-5', '#pppoe cari kartono'), 'Teknisi A');

        $this->assertDatabaseHas('wa_chat_signals', ['message_id' => 'MSG-3', 'channel' => 'activation', 'intent' => 'psb_command']);
        $this->assertDatabaseHas('wa_chat_signals', ['message_id' => 'MSG-4', 'domain' => 'migration', 'intent' => 'migration_command']);
        $this->assertDatabaseHas('wa_chat_signals', ['message_id' => 'MSG-5', 'domain' => 'network', 'intent' => 'pppoe_search']);
        $this->assertSame(0, SupportTicket::query()->count());
        $this->assertSame(0, Customer::query()->count());
    }

    public function test_new_chat_does_not_invalidate_dashboard_snapshot(): void
    {
        Setting::set('psb_wa_group_ids', ['120363-aktivasi@g.us']);
        Cache::put('wa_chat_intelligence.dashboard', ['ready' => 'cached'], now()->addMinutes(5));

        app(WaChatIntelligenceService::class)
            ->observeWebhookMessage($this->message('120363-aktivasi@g.us', 'MSG-CACHE', '#psb contoh'), 'Teknisi A');

        $this->assertSame(['ready' => 'cached'], Cache::get('wa_chat_intelligence.dashboard'));
        $this->assertDatabaseHas('wa_chat_signals', ['message_id' => 'MSG-CACHE']);
    }

    public function test_psb_parser_supports_short_production_commands(): void
    {
        $parser = app(PsbMessageParser::class);

        $this->assertTrue($parser->parse('#help')['is_help']);
        $this->assertTrue($parser->parse('#psb contoh')['is_help']);
        $this->assertTrue($parser->parse('#status #103')['is_cek']);
        $this->assertSame(103, $parser->parse('#status #103')['draft_id']);
        $this->assertTrue($parser->parse('#lanjut #103')['is_lanjut']);
        $this->assertTrue($parser->parse('#batal #103')['is_batal']);
        $this->assertTrue($parser->parse('#pppoe cari kartono')['is_pppoe_cmd']);
        $this->assertSame('kartono', $parser->parse('#pppoe cari kartono')['pppoe_query']);
    }

    /**
     * @return array<string, mixed>
     */
    private function message(string $groupJid, string $messageId, string $text): array
    {
        return [
            'key' => [
                'remoteJid' => $groupJid,
                'participant' => '6281111111111@s.whatsapp.net',
                'id' => $messageId,
                'fromMe' => false,
            ],
            'pushName' => 'Teknisi',
            'messageTimestamp' => now()->timestamp,
            'message' => [
                'conversation' => $text,
            ],
        ];
    }
}
