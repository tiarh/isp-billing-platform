<?php

namespace Tests\Feature;

use App\Services\EvolutionApiService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Read-only membership probe + group listing used by report:wa-group. They only issue GETs,
 * never a message endpoint, and always ask Evolution for getParticipants=false.
 */
#[Group('blocking-psb')]
class EvolutionGroupMembershipTest extends TestCase
{
    private const JID = '120363-skynetreport@g.us';

    private function service(): EvolutionApiService
    {
        config([
            'services.evolution_api.base_url' => 'http://evo.test',
            'services.evolution_api.api_key' => 'test-evo-key',
        ]);

        return (new EvolutionApiService('inst-ops'))->forInstance('inst-ops');
    }

    private function assertNoMessageEndpointCalled(): void
    {
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '/message/') || $r->method() !== 'GET');
    }

    public function test_member_true_when_find_group_infos_returns_the_group(): void
    {
        Http::fake(['evo.test/group/findGroupInfos/*' => Http::response(['id' => self::JID, 'subject' => 'SKYNET REPORT', 'size' => 14])]);

        $result = $this->service()->groupMembership(self::JID);

        $this->assertTrue($result['member']);
        $this->assertSame('SKYNET REPORT', $result['subject']);
        $this->assertSame(14, $result['size']);
        $this->assertNoMessageEndpointCalled();
    }

    public function test_find_chats_group_rows_use_chat_push_name_as_the_group_name(): void
    {
        Http::fake(['evo.test/chat/findChats/*' => Http::response([
            ['id' => 'c1', 'remoteJid' => self::JID, 'pushName' => 'Grup Fiktif', 'lastMessage' => ['message' => ['conversation' => 'rahasia']]],
            ['id' => 'c2', 'remoteJid' => '628111111111@s.whatsapp.net', 'pushName' => 'Orang Fiktif'],
            ['id' => 'c3', 'remoteJid' => '120363-nopush@g.us'],
        ])]);

        $groups = $this->service()->listGroupsViaChats()['groups'];
        $byId = collect($groups)->keyBy('id');

        $this->assertCount(2, $groups);
        $this->assertSame('Grup Fiktif', $byId[self::JID]['name']);
        $this->assertSame('120363-nopush@g.us', $byId['120363-nopush@g.us']['name']);
    }

    public function test_push_name_is_not_used_for_other_group_sources(): void
    {
        Http::fake(['evo.test/group/fetchAllGroups/*' => Http::response([['id' => self::JID, 'pushName' => 'Orang Fiktif', 'size' => 3]])]);

        $groups = $this->service()->listGroupsLight()['groups'];

        $this->assertSame(self::JID, $groups[0]['name']);
    }

    public function test_member_false_on_a_4xx_only_when_the_group_list_loads_and_lacks_the_group(): void
    {
        Http::fake([
            'evo.test/group/findGroupInfos/*' => Http::response(['message' => 'not found'], 404),
            'evo.test/group/fetchAllGroups/*' => Http::response([['id' => '999999999999@g.us', 'subject' => 'Other', 'size' => 3]]),
        ]);

        $this->assertFalse($this->service()->groupMembership(self::JID)['member']);
    }

    public function test_a_4xx_from_find_group_infos_does_not_hide_a_group_present_in_the_list(): void
    {
        Http::fake([
            'evo.test/group/findGroupInfos/*' => Http::response(['message' => 'bad request'], 400),
            'evo.test/group/fetchAllGroups/*' => Http::response([['id' => self::JID, 'subject' => 'SKYNET REPORT', 'size' => 9]]),
        ]);

        $this->assertTrue($this->service()->groupMembership(self::JID)['member']);
    }

    public function test_a_4xx_with_an_unavailable_list_stays_unknown(): void
    {
        Http::fake([
            'evo.test/group/findGroupInfos/*' => Http::response(['message' => 'bad request'], 400),
            'evo.test/group/fetchAllGroups/*' => Http::response('', 500),
        ]);

        $this->assertNull($this->service()->groupMembership(self::JID)['member']);
    }

    public function test_server_error_falls_back_to_the_group_list_and_stays_unknown_when_not_listed(): void
    {
        Http::fake([
            'evo.test/group/findGroupInfos/*' => Http::response('', 500),
            'evo.test/group/fetchAllGroups/*' => Http::response([['id' => '999999999999@g.us', 'subject' => 'Other', 'size' => 3]]),
        ]);

        $this->assertNull($this->service()->groupMembership(self::JID)['member']);
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'fetchAllGroups') && str_contains($r->url(), 'getParticipants=false'));
        $this->assertNoMessageEndpointCalled();
    }

    public function test_fallback_list_containing_the_group_means_member(): void
    {
        Http::fake([
            'evo.test/group/findGroupInfos/*' => Http::response('', 500),
            'evo.test/group/fetchAllGroups/*' => Http::response([['id' => self::JID, 'subject' => 'SKYNET REPORT', 'size' => 9]]),
        ]);

        $result = $this->service()->groupMembership(self::JID);

        $this->assertTrue($result['member']);
        $this->assertSame(9, $result['size']);
    }

    public function test_list_groups_light_uses_get_participants_false_only(): void
    {
        Http::fake(['evo.test/group/fetchAllGroups/*' => Http::response([['id' => self::JID, 'subject' => 'SKYNET REPORT', 'size' => 9]])]);

        $list = $this->service()->listGroupsLight();

        $this->assertTrue($list['success']);
        $this->assertSame(self::JID, $list['groups'][0]['id']);
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'getParticipants=false'));
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), 'findChats'));
        $this->assertNoMessageEndpointCalled();
    }
}
