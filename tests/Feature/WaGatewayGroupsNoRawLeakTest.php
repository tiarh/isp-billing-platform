<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * GET /api/wa-gateway/groups must only expose the fields the UI uses
 * (id, subject, name, members count) and never member phone numbers or last message content.
 */
#[Group('blocking-core')]
class WaGatewayGroupsNoRawLeakTest extends TestCase
{
    use RefreshDatabase;

    public function test_groups_response_is_whitelisted_and_has_no_participants_or_last_message(): void
    {
        Cache::flush();
        config([
            'services.evolution_api.base_url' => 'http://evo.test',
            'services.evolution_api.api_key' => 'canary-pw',
            'services.evolution_api.instance_name' => 'inst-ops',
        ]);

        Http::fake([
            'evo.test/chat/findChats/*' => Http::response([
                [
                    'remoteJid' => '120363000000001@g.us',
                    'subject' => 'Grup Uji',
                    'size' => 2,
                    'pushName' => 'Fake Push',
                    'participants' => [
                        ['id' => '62800000001@s.whatsapp.net', 'admin' => 'admin'],
                        ['id' => '62800000002@s.whatsapp.net', 'admin' => null],
                    ],
                    'lastMessage' => ['message' => ['conversation' => 'rahasia isi pesan']],
                ],
            ]),
            'evo.test/*' => Http::response([]),
        ]);

        $user = User::factory()->create(['role' => 'superadmin']);

        $response = $this->actingAs($user)->getJson('/api/wa-gateway/groups?channel=ops');

        $response->assertOk();
        $groups = $response->json('groups');
        $this->assertCount(1, $groups);
        $this->assertSame('120363000000001@g.us', $groups[0]['id']);
        $this->assertSame('Grup Uji', $groups[0]['subject']);
        $this->assertSame('Grup Uji', $groups[0]['name']);
        $this->assertSame(2, $groups[0]['members']);
        $this->assertEqualsCanonicalizing(['id', 'subject', 'name', 'members'], array_keys($groups[0]));

        $body = $response->getContent();
        $this->assertStringNotContainsString('participants', $body);
        $this->assertStringNotContainsString('lastMessage', $body);
        $this->assertStringNotContainsString('62800000001', $body);
        $this->assertStringNotContainsString('rahasia isi pesan', $body);
        $this->assertStringNotContainsString('Fake Push', $body);
    }
}
