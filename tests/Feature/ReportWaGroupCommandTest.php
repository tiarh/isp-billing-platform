<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\EvolutionApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-psb')]
class ReportWaGroupCommandTest extends TestCase
{
    use RefreshDatabase;

    private const R = '120363-skynetreport@g.us';

    private const P = '120363-activation@g.us';

    /** @var array{member: ?bool, subject: ?string, size: ?int, error: ?string} */
    private array $membership = ['member' => true, 'subject' => 'SKYNET REPORT', 'size' => 12, 'error' => null];

    /** @var list<string> */
    private array $channels = [];

    /** @var array{success: bool, error: ?string, groups: array<int, array<string, mixed>>} */
    private array $chatsList = ['success' => true, 'error' => null, 'groups' => []];

    /** @var array{success: bool, error: ?string, groups: array<int, array<string, mixed>>} */
    private array $lightList = ['success' => true, 'error' => null, 'groups' => []];

    /** @var list<string> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('psb_wa_group_ids', [self::P]);

        $evo = \Mockery::mock(EvolutionApiService::class);
        $evo->shouldReceive('forChannel')->andReturnUsing(function (string $channel) use ($evo) {
            $this->channels[] = $channel;

            return $evo;
        });
        $evo->shouldReceive('groupMembership')->andReturnUsing(fn () => $this->membership);
        $evo->shouldReceive('getConnectionState')->andReturn('open');
        $groups = [
            ['id' => self::R, 'name' => 'SKYNET REPORT', 'members' => 12],
            ['id' => self::P, 'name' => 'Aktivasi', 'members' => 5],
        ];
        $this->chatsList = ['success' => true, 'error' => null, 'groups' => $groups];
        $this->lightList = ['success' => true, 'error' => null, 'groups' => $groups];
        $evo->shouldReceive('listGroupsViaChats')->andReturnUsing(function () {
            $this->calls[] = 'chats';

            return $this->chatsList;
        });
        $evo->shouldReceive('listGroupsLight')->andReturnUsing(function () {
            $this->calls[] = 'light';

            return $this->lightList;
        });
        $evo->shouldNotReceive('sendToGroup');
        $evo->shouldNotReceive('send');
        $evo->shouldNotReceive('sendDocument');
        $this->app->instance(EvolutionApiService::class, $evo);
    }

    private function saved(): mixed
    {
        Setting::flushRequestMemo();

        return Setting::get('report_wa_group_ids', []);
    }

    public function test_set_normalises_and_saves_the_jid_without_touching_the_flags(): void
    {
        $this->assertSame(0, Artisan::call('report:wa-group', ['action' => 'set', 'jid' => '120363111111111111']));

        $this->assertSame(['120363111111111111@g.us'], $this->saved());
        $this->assertSame(0, (int) Setting::get('report_daily_enabled', 0));
        $this->assertSame(0, (int) Setting::get('report_recap_cmd_enabled', 0));
    }

    public function test_set_with_channel_also_saves_the_channel(): void
    {
        Artisan::call('report:wa-group', ['action' => 'set', 'jid' => self::R, '--channel' => 'ticket']);

        $this->assertSame('ticket', Setting::get('report_wa_channel'));
        $this->assertContains('ticket', $this->channels);
    }

    public function test_set_refuses_a_psb_group_jid(): void
    {
        $this->assertSame(1, Artisan::call('report:wa-group', ['action' => 'set', 'jid' => self::P]));

        $this->assertSame([], $this->saved());
    }

    public function test_set_refuses_when_the_number_is_not_a_member(): void
    {
        $this->membership = ['member' => false, 'subject' => null, 'size' => null, 'error' => 'API returned 404'];

        $this->assertSame(1, Artisan::call('report:wa-group', ['action' => 'set', 'jid' => self::R]));
        $this->assertSame([], $this->saved());
    }

    public function test_set_with_unverified_membership_needs_force(): void
    {
        $this->membership = ['member' => null, 'subject' => null, 'size' => null, 'error' => 'timeout'];

        $this->assertSame(1, Artisan::call('report:wa-group', ['action' => 'set', 'jid' => self::R]));
        $this->assertSame([], $this->saved());

        $this->assertSame(0, Artisan::call('report:wa-group', ['action' => 'set', 'jid' => self::R, '--force' => true]));
        $this->assertSame([self::R], $this->saved());
    }

    public function test_clear_empties_the_list(): void
    {
        Setting::set('report_wa_group_ids', [self::R]);

        Artisan::call('report:wa-group', ['action' => 'clear']);

        $this->assertSame([], $this->saved());
    }

    public function test_check_reports_membership_and_overlap(): void
    {
        Artisan::call('report:wa-group', ['action' => 'check', 'jid' => self::R, '--channel' => 'ops']);
        $output = Artisan::output();
        $this->assertStringContainsString('Member: yes', $output);
        $this->assertStringContainsString('Overlap with psb_wa_group_ids: none', $output);

        Artisan::call('report:wa-group', ['action' => 'check', 'jid' => self::P]);
        $this->assertStringContainsString('OVERLAP: '.self::P.' ada di psb_wa_group_ids', Artisan::output());
    }

    public function test_groups_lists_names_and_jids_and_show_prints_the_settings(): void
    {
        Artisan::call('report:wa-group', ['action' => 'groups']);
        $output = Artisan::output();
        $this->assertStringContainsString('SKYNET REPORT', $output);
        $this->assertStringContainsString(self::R, $output);

        Artisan::call('report:wa-group', ['action' => 'show']);
        $this->assertStringContainsString('report_wa_group_ids: (empty = feature off)', Artisan::output());
    }

    public function test_groups_uses_the_chat_list_first_and_never_calls_fetch_all_groups_when_it_works(): void
    {
        $this->assertSame(0, Artisan::call('report:wa-group', ['action' => 'groups']));

        $this->assertSame(['chats'], $this->calls);
        $this->assertStringContainsString('chat list', Artisan::output());
    }

    public function test_groups_falls_back_to_fetch_all_groups_when_the_chat_list_fails_or_is_empty(): void
    {
        $this->chatsList = ['success' => false, 'error' => 'Illuminate\\Http\\Client\\ConnectionException', 'groups' => []];
        $this->assertSame(0, Artisan::call('report:wa-group', ['action' => 'groups']));
        $this->assertSame(['chats', 'light'], $this->calls);
        $this->assertStringContainsString(self::R, Artisan::output());

        $this->calls = [];
        $this->chatsList = ['success' => true, 'error' => null, 'groups' => []];
        $this->assertSame(0, Artisan::call('report:wa-group', ['action' => 'groups']));
        $this->assertSame(['chats', 'light'], $this->calls);
    }

    public function test_groups_fails_with_both_reasons_when_both_sources_fail(): void
    {
        $this->chatsList = ['success' => false, 'error' => 'API returned 500', 'groups' => []];
        $this->lightList = ['success' => false, 'error' => 'ConnectionException', 'groups' => []];

        $this->assertSame(1, Artisan::call('report:wa-group', ['action' => 'groups']));
        $out = Artisan::output();

        $this->assertStringContainsString('Could not list groups: ConnectionException', $out);
        $this->assertStringContainsString('findChats: API returned 500', $out);
    }

    public function test_invalid_channel_is_rejected(): void
    {
        $this->assertSame(1, Artisan::call('report:wa-group', ['action' => 'groups', '--channel' => 'billing']));
    }
}
