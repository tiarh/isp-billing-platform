<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * GET /api/attendances/groups (Attendances page + PSB WA panel) must only expose
 * id, subject, name, members; never member phone numbers, last message or pushName.
 */
#[Group('blocking-core')]
class AttendanceGroupsNoRawLeakTest extends TestCase
{
    use RefreshDatabase;

    public function test_groups_response_is_whitelisted_and_name_never_falls_back_to_push_name(): void
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
                    'pushName' => 'Orang Fiktif',
                    'participants' => [
                        ['id' => '62800000001@s.whatsapp.net', 'admin' => 'admin'],
                        ['id' => '62800000002@s.whatsapp.net', 'admin' => null],
                    ],
                    'lastMessage' => ['message' => ['conversation' => 'rahasia isi pesan']],
                ],
                [
                    'remoteJid' => '120363000000002@g.us',
                    'pushName' => 'Grup Via Push',
                ],
            ]),
            'evo.test/*' => Http::response([]),
        ]);

        $user = User::factory()->create(['role' => 'superadmin']);

        $response = $this->actingAs($user)->getJson('/api/attendances/groups');

        $response->assertOk();
        $groups = $response->json('groups');
        $this->assertCount(2, $groups);

        $byId = collect($groups)->keyBy('id');
        $first = $byId['120363000000001@g.us'];
        $this->assertSame('Grup Uji', $first['subject']);
        $this->assertSame('Grup Uji', $first['name']);
        $this->assertSame(2, $first['members']);
        $this->assertEqualsCanonicalizing(['id', 'subject', 'name', 'members'], array_keys($first));

        $second = $byId['120363000000002@g.us'];
        // findChats group chats carry the group subject only as chat-level pushName.
        $this->assertSame('Grup Via Push', $second['name']);
        $this->assertEqualsCanonicalizing(['id', 'subject', 'name', 'members'], array_keys($second));

        $body = $response->getContent();
        $this->assertStringNotContainsString('participants', $body);
        $this->assertStringNotContainsString('lastMessage', $body);
        $this->assertStringNotContainsString('pushName', $body);
        $this->assertStringNotContainsString('62800000001', $body);
        $this->assertStringNotContainsString('rahasia isi pesan', $body);
        $this->assertStringNotContainsString('Orang Fiktif', $body);
        $response->assertJsonPath('configured', true);
    }
}
