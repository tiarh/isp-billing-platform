<?php

namespace Tests\Concerns;

use App\Models\Area;
use App\Models\Setting;
use App\Models\User;
use App\Services\EvolutionApiService;
use App\Services\Wa\WaGroupRoles;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;

/**
 * Fixtures for the Grup WhatsApp page tests: fake JIDs only (no real group ids or phones),
 * a faked Evolution API that never sends anything, and one user per role.
 */
trait FakesWaGroups
{
    protected const G_REPORT = '120363000000000001@g.us';

    protected const G_PSB = '120363000000000002@g.us';

    protected const G_OPS = '120363000000000003@g.us';

    protected const G_SHADOW = '120363000000000004@g.us';

    protected const G_UNDELIVERED = '120363000000000005@g.us';

    protected const G_NEW = '120363000000000006@g.us';

    /** @var array<string, array{member: ?bool, subject: ?string, size: ?int, error: ?string}> */
    protected array $membershipByJid = [];

    /** @var list<array{0: string, 1: string}> channel, jid of each membership probe */
    protected array $membershipCalls = [];

    protected function fakeEvolution(): void
    {
        $evo = \Mockery::mock(EvolutionApiService::class);
        $channel = 'ops';
        $evo->shouldReceive('forChannel')->andReturnUsing(function (string $c) use ($evo, &$channel) {
            $channel = $c;

            return $evo;
        });
        $evo->shouldReceive('groupMembership')->andReturnUsing(function (string $jid) use (&$channel) {
            $this->membershipCalls[] = [$channel, $jid];

            return $this->membershipByJid[$jid] ?? ['member' => true, 'subject' => 'Grup Uji', 'size' => 5, 'error' => null];
        });
        $rows = [
            // raw Evolution fields on purpose: the page must never forward them
            ['id' => self::G_REPORT, 'subject' => 'Report', 'name' => 'Report', 'members' => 7, 'participants' => [['id' => '62800000001@s.whatsapp.net']], 'lastMessage' => ['text' => 'rahasia']],
            ['id' => self::G_PSB, 'subject' => 'Aktivasi', 'name' => 'Aktivasi', 'members' => 9, 'participants' => [['id' => '62800000001@s.whatsapp.net']]],
            ['id' => self::G_NEW, 'subject' => 'Grup Baru', 'name' => 'Grup Baru', 'members' => 3],
        ];
        $evo->shouldReceive('listGroupsViaChats')->andReturn(['success' => true, 'error' => null, 'groups' => $rows]);
        $evo->shouldReceive('listGroupsLight')->andReturn(['success' => true, 'error' => null, 'groups' => $rows]);
        $evo->shouldNotReceive('sendToGroup');
        $evo->shouldNotReceive('send');
        $this->app->instance(EvolutionApiService::class, $evo);
        Cache::flush();
    }

    protected function seedWaSettings(): void
    {
        Setting::set('psb_wa_group_ids', [self::G_PSB], 'json', 'psb');
        Setting::set('report_wa_group_ids', [self::G_REPORT], 'json', 'report');
        Setting::set('report_wa_channel', 'ops', 'text', 'report');
        Setting::set('wa_report_group_jid', self::G_OPS, 'text', 'wa');
        Setting::set('wa_ticket_progress_share_group_jid', '120363000000000009@g.us', 'text', 'wa');
        Setting::flushRequestMemo();
    }

    protected function waVersion(): string
    {
        Setting::flushRequestMemo();

        return app(WaGroupRoles::class)->version();
    }

    protected function superadmin(): User
    {
        return User::factory()->create(['role' => 'superadmin']);
    }

    protected function unscopedAdmin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    protected function scopedAdmin(): User
    {
        $u = User::factory()->create(['role' => 'admin']);
        $u->areas()->attach(Area::create(['name' => 'Area Uji', 'code' => 'UJI']));

        return $u->fresh();
    }

    protected function noc(): User
    {
        return User::factory()->create(['role' => 'noc']);
    }

    protected function teknisi(): User
    {
        return User::factory()->create(['role' => 'teknisi']);
    }

    /** @param  array<string, mixed>  $fields */
    protected function putSection(User $user, string $section, array $fields, ?string $version = null, array $force = []): TestResponse
    {
        return $this->actingAs($user)->put(route('settings.wa-groups.update'), array_merge([
            'section' => $section,
            'version' => $version ?? $this->waVersion(),
            'force_unverified' => $force,
        ], $fields));
    }
}
