<?php

namespace Tests\Feature;

use App\Services\EvolutionApiService;
use App\Services\Wa\WaGroupDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\FakesWaGroups;
use Tests\TestCase;

/** Group picker source: whitelist (no PII), cache, last-known-good fallback, no network on directory hits. */
#[Group('blocking-core')]
class WaGroupDirectoryTest extends TestCase
{
    use FakesWaGroups;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedWaSettings();
        $this->fakeEvolution();
    }

    public function test_directory_rows_are_whitelisted_to_jid_name_members(): void
    {
        $result = app(WaGroupDirectory::class)->list('ops');

        $this->assertFalse($result['stale']);
        $this->assertCount(3, $result['groups']);
        foreach ($result['groups'] as $row) {
            $this->assertSame(['jid', 'name', 'members'], array_keys($row));
        }
    }

    public function test_groups_endpoint_never_exposes_raw_evolution_fields(): void
    {
        $response = $this->actingAs($this->superadmin())->getJson(route('api.settings.wa-groups.groups'))->assertOk();
        $raw = $response->getContent();

        foreach (['participants', 'lastMessage', 'rahasia', '62800000001', 'pushName'] as $leak) {
            $this->assertStringNotContainsString($leak, $raw, "{$leak} must not reach the browser");
        }
        $groups = collect($response->json('groups'))->keyBy('jid');
        $this->assertSame('psb_group', $groups[self::G_PSB]['blocked']['report']);
        $this->assertSame('psb_group', $groups[self::G_PSB]['blocked']['shadow']);
        $this->assertSame('report_group', $groups[self::G_REPORT]['blocked']['undelivered']);
        $this->assertSame('report_or_ops_group', $groups[self::G_REPORT]['blocked']['shadow']);
        $this->assertNull($groups[self::G_NEW]['blocked']['report']);
        $this->assertTrue($groups[self::G_PSB]['is_psb']);
    }

    public function test_second_read_is_served_from_cache_without_calling_evolution(): void
    {
        $evo = \Mockery::mock(EvolutionApiService::class);
        $evo->shouldReceive('forChannel')->andReturn($evo);
        $evo->shouldReceive('listGroupsViaChats')->once()->andReturn(['success' => true, 'error' => null, 'groups' => [['id' => self::G_NEW, 'name' => 'Grup Baru', 'members' => 3]]]);
        $this->app->instance(EvolutionApiService::class, $evo);
        Cache::flush();

        $dir = app(WaGroupDirectory::class);
        $dir->list('ops');
        $second = $dir->list('ops');

        $this->assertFalse($second['stale']);
        $this->assertCount(1, $second['groups']);
    }

    public function test_slow_evolution_serves_last_known_good_as_stale_and_never_throws(): void
    {
        $dir = app(WaGroupDirectory::class);
        $this->assertCount(3, $dir->list('ops')['groups']);

        $evo = \Mockery::mock(EvolutionApiService::class);
        $evo->shouldReceive('forChannel')->andReturn($evo);
        $evo->shouldReceive('listGroupsViaChats')->andReturn(['success' => false, 'error' => 'Illuminate\\Http\\Client\\ConnectionException', 'groups' => []]);
        $evo->shouldReceive('listGroupsLight')->andThrow(new \RuntimeException('cURL error 28 timeout'));
        $this->app->instance(EvolutionApiService::class, $evo);

        $result = $dir->list('ops', true);

        $this->assertTrue($result['stale']);
        $this->assertCount(3, $result['groups']);
        $this->assertNotNull($result['fetched_at']);
        $this->assertNotNull($result['error_code']);
    }

    public function test_failure_with_no_last_known_good_returns_empty_stale_list_and_endpoint_is_not_500(): void
    {
        $evo = \Mockery::mock(EvolutionApiService::class);
        $evo->shouldReceive('forChannel')->andReturn($evo);
        $evo->shouldReceive('listGroupsViaChats')->andReturn(['success' => false, 'error' => 'timeout', 'groups' => []]);
        $evo->shouldReceive('listGroupsLight')->andReturn(['success' => false, 'error' => 'timeout', 'groups' => []]);
        $this->app->instance(EvolutionApiService::class, $evo);
        Cache::flush();

        $this->actingAs($this->superadmin())->getJson(route('api.settings.wa-groups.groups'))
            ->assertOk()
            ->assertJsonPath('stale', true)
            ->assertJsonPath('groups', []);
    }

    public function test_chats_failure_falls_back_to_the_light_list(): void
    {
        $evo = \Mockery::mock(EvolutionApiService::class);
        $evo->shouldReceive('forChannel')->andReturn($evo);
        $evo->shouldReceive('listGroupsViaChats')->andReturn(['success' => false, 'error' => 'timeout', 'groups' => []]);
        $evo->shouldReceive('listGroupsLight')->andReturn(['success' => true, 'error' => null, 'groups' => [['id' => self::G_NEW, 'name' => 'Grup Baru', 'members' => 3]]]);
        $this->app->instance(EvolutionApiService::class, $evo);
        Cache::flush();

        $result = app(WaGroupDirectory::class)->list('ops');

        $this->assertFalse($result['stale']);
        $this->assertSame(self::G_NEW, $result['groups'][0]['jid']);
    }

    public function test_hit_in_a_findchats_list_is_not_proof_of_membership_and_is_probed_like_the_cli(): void
    {
        $dir = app(WaGroupDirectory::class);
        $dir->list('ops'); // fake serves findChats first (may include groups the number already left)

        $this->membershipByJid[self::G_NEW] = ['member' => false, 'subject' => null, 'size' => null, 'error' => null];
        $this->assertFalse($dir->membership('ops', self::G_NEW)['member'], 'a chat-list hit must not turn into member=true');
        $this->assertSame([['ops', self::G_NEW]], $this->membershipCalls);
    }

    public function test_hit_in_a_fetchallgroups_list_needs_no_network_call_and_miss_is_cached(): void
    {
        $evo = \Mockery::mock(EvolutionApiService::class);
        $evo->shouldReceive('forChannel')->andReturn($evo);
        $evo->shouldReceive('listGroupsViaChats')->andReturn(['success' => false, 'error' => 'timeout', 'groups' => []]);
        $evo->shouldReceive('listGroupsLight')->andReturn(['success' => true, 'error' => null, 'groups' => [['id' => self::G_NEW, 'name' => 'Grup Baru', 'members' => 3]]]);
        $evo->shouldReceive('groupMembership')->once()->andReturn(['member' => true, 'subject' => 'X', 'size' => 1, 'error' => null]);
        $this->app->instance(EvolutionApiService::class, $evo);
        Cache::flush();

        $dir = app(WaGroupDirectory::class);
        $dir->list('ops');

        $this->assertTrue($dir->membership('ops', self::G_NEW)['member']);

        $this->assertTrue($dir->membership('ops', self::G_SHADOW)['member']);
        $this->assertTrue($dir->membership('ops', self::G_SHADOW)['member'], 'definite answers are cached for 10 minutes (probe ran once)');
    }

    public function test_concurrent_load_is_single_flight_and_serves_last_known_good(): void
    {
        $dir = app(WaGroupDirectory::class);
        $dir->list('ops'); // fills cache + LKG
        Cache::forget('wa-groups:dir:ops');

        $lock = Cache::lock('wa-groups:dir:ops:lock', 45);
        $this->assertTrue($lock->get());
        $evo = \Mockery::mock(EvolutionApiService::class);
        $evo->shouldNotReceive('forChannel');
        $this->app->instance(EvolutionApiService::class, $evo);

        $result = $dir->list('ops', true);

        $this->assertTrue($result['stale']);
        $this->assertSame('busy', $result['error_code']);
        $this->assertCount(3, $result['groups']);
        $lock->release();
    }

    public function test_push_name_never_becomes_the_group_name(): void
    {
        $evo = \Mockery::mock(EvolutionApiService::class);
        $evo->shouldReceive('forChannel')->andReturn($evo);
        $evo->shouldReceive('listGroupsViaChats')->andReturn(['success' => true, 'error' => null, 'groups' => [['id' => self::G_NEW, 'pushName' => 'Budi Contoh', 'members' => 3]]]);
        $this->app->instance(EvolutionApiService::class, $evo);
        Cache::flush();

        $row = app(WaGroupDirectory::class)->list('ops')['groups'][0];

        $this->assertStringNotContainsString('Budi', json_encode($row));
    }

    public function test_directory_never_leaks_raw_chat_fields(): void
    {
        $evo = \Mockery::mock(EvolutionApiService::class);
        $evo->shouldReceive('forChannel')->andReturn($evo);
        $evo->shouldReceive('listGroupsViaChats')->andReturn(['success' => true, 'error' => null, 'groups' => [[
            'id' => self::G_NEW, 'subject' => 'Grup Fiktif', 'name' => 'Grup Fiktif', 'pushName' => 'Grup Fiktif', 'members' => 3,
            'lastMessage' => ['message' => ['conversation' => 'rahasia-canary']],
        ]]]);
        $this->app->instance(EvolutionApiService::class, $evo);
        Cache::flush();

        $row = app(WaGroupDirectory::class)->list('ops')['groups'][0];

        $this->assertSame('Grup Fiktif', $row['name']);
        $this->assertSame(['jid', 'name', 'members'], array_keys($row));
        $this->assertStringNotContainsString('rahasia-canary', json_encode($row));
    }

    public function test_fresh_reload_is_rate_limited_to_three_per_minute(): void
    {
        $evo = \Mockery::mock(EvolutionApiService::class);
        $evo->shouldReceive('forChannel')->andReturn($evo);
        $evo->shouldReceive('listGroupsViaChats')->times(3)->andReturn(['success' => true, 'error' => null, 'groups' => [['id' => self::G_NEW, 'name' => 'Grup Baru', 'members' => 3]]]);
        $this->app->instance(EvolutionApiService::class, $evo);
        Cache::flush();

        $user = $this->superadmin();
        for ($i = 0; $i < 4; $i++) {
            $this->actingAs($user)->getJson(route('api.settings.wa-groups.groups', ['fresh' => 1]))->assertOk();
        }
    }

    public function test_membership_endpoint_rejects_malformed_jid_and_never_returns_raw_error(): void
    {
        $this->actingAs($this->superadmin())
            ->postJson(route('api.settings.wa-groups.membership'), ['channel' => 'ops', 'jid' => 'bukan-jid'])
            ->assertStatus(422);

        $this->membershipByJid[self::G_SHADOW] = ['member' => null, 'subject' => null, 'size' => null, 'error' => 'cURL error 28: http://internal-host:8085/secret'];
        $response = $this->actingAs($this->superadmin())
            ->postJson(route('api.settings.wa-groups.membership'), ['channel' => 'ops', 'jid' => self::G_SHADOW])
            ->assertOk();
        $this->assertStringNotContainsString('internal-host', $response->getContent());
        $response->assertJsonPath('member', null);
    }
}
