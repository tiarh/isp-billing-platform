<?php

namespace Tests\Feature;

use App\Jobs\ProcessPsbRecapCommandJob;
use App\Models\Area;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Setting;
use App\Services\EvolutionApiService;
use App\Services\PsbAreaRecapService;
use App\Services\PsbRecapCommandParser;
use App\Services\PsbWaMessages;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * The recap job in the Report group context: replies via the Report channel (ops|ticket),
 * the PSB context stays on ops, and a removed / disabled / overlapping group is silent.
 */
#[Group('blocking-psb')]
class ProcessPsbRecapCommandJobReportContextTest extends TestCase
{
    use RefreshDatabase;

    private const R = '120363-skynetreport@g.us';

    private const P = '120363-activation@g.us';

    private int $seq = 0;

    /** @var list<string> */
    private array $texts = [];

    /** @var list<string> */
    private array $docs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-15 09:00', 'Asia/Jakarta'));
        File::deleteDirectory(storage_path('app/psb/recap'));
        Setting::set('psb_recap_cmd_enabled', '1');
        Setting::set('psb_wa_group_ids', [self::P]);
        Setting::set('report_wa_group_ids', [self::R]);
        Setting::set('report_recap_cmd_enabled', '1');

        $area = Area::create(['name' => 'SKYNET-KLAMPOK', 'code' => 'klampok']);
        $package = Package::create(['code' => 'P10', 'name' => '10 Mbps', 'price' => 100000]);
        Customer::create([
            'code' => 'RC-1', 'name' => 'Pelanggan Satu', 'address' => 'Dusun Klampok', 'package_id' => $package->id,
            'area_id' => $area->id, 'pppoe_user' => 'userrc1', 'status' => 'active', 'join_date' => '2026-10-14',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/psb/recap'));
        $this->travelBack();
        parent::tearDown();
    }

    private function fakeWhatsapp(string $expectedChannel): EvolutionApiService
    {
        $evo = \Mockery::mock(EvolutionApiService::class);
        $evo->shouldReceive('forChannel')->with($expectedChannel)->andReturnSelf();
        $evo->shouldReceive('sendToGroup')->andReturnUsing(function (string $jid, string $text) {
            $this->texts[] = $jid.'|'.$text;

            return ['success' => true];
        });
        $evo->shouldReceive('sendDocument')->andReturnUsing(function (string $jid) {
            $this->docs[] = $jid;

            return ['success' => true, 'message_id' => 'DOC'];
        });

        return $evo;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function runJob(EvolutionApiService $evo, string $group, array $extra = []): void
    {
        $job = new ProcessPsbRecapCommandJob(array_merge([
            'group_jid' => $group,
            'sender_jid' => '6281111111111@s.whatsapp.net',
            'message_id' => 'MSG-'.(++$this->seq),
            'text' => '#rekap psb semua 14/10',
        ], $extra));
        $job->handle(app(PsbRecapCommandParser::class), app(PsbAreaRecapService::class), app(PsbWaMessages::class), $evo);
    }

    public function test_report_context_replies_text_and_excel_through_the_ops_channel_by_default(): void
    {
        $this->runJob($this->fakeWhatsapp('ops'), self::R, ['context' => 'report']);

        $this->assertCount(1, $this->texts);
        $this->assertStringStartsWith(self::R.'|', $this->texts[0]);
        $this->assertSame([self::R], $this->docs);
    }

    public function test_report_context_uses_the_ticket_channel_when_configured(): void
    {
        Setting::set('report_wa_channel', 'ticket');

        $this->runJob($this->fakeWhatsapp('ticket'), self::R, ['context' => 'report']);

        $this->assertCount(1, $this->texts);
        $this->assertSame([self::R], $this->docs);
    }

    public function test_psb_context_still_uses_ops_even_when_the_report_channel_is_ticket(): void
    {
        Setting::set('report_wa_channel', 'ticket');

        $this->runJob($this->fakeWhatsapp('ops'), self::P);

        $this->assertCount(1, $this->texts);
        $this->assertSame([self::P], $this->docs);
    }

    public function test_report_context_is_silent_when_its_flag_is_off(): void
    {
        Setting::set('report_recap_cmd_enabled', '0');
        $evo = \Mockery::mock(EvolutionApiService::class);
        $evo->shouldNotReceive('forChannel');
        $evo->shouldNotReceive('sendToGroup');

        $this->runJob($evo, self::R, ['context' => 'report']);

        $this->addToAssertionCount(1);
    }

    public function test_report_context_is_silent_when_the_group_was_removed_after_dispatch(): void
    {
        Setting::set('report_wa_group_ids', []);
        $evo = \Mockery::mock(EvolutionApiService::class);
        $evo->shouldNotReceive('sendToGroup');

        $this->runJob($evo, self::R, ['context' => 'report']);

        $this->addToAssertionCount(1);
    }

    public function test_report_context_is_silent_when_the_group_became_a_psb_group(): void
    {
        Setting::set('psb_wa_group_ids', [self::P, self::R]);
        $evo = \Mockery::mock(EvolutionApiService::class);
        $evo->shouldNotReceive('sendToGroup');

        $this->runJob($evo, self::R, ['context' => 'report']);

        $this->addToAssertionCount(1);
    }

    public function test_report_context_is_silent_when_the_global_kill_switch_is_off(): void
    {
        Setting::set('psb_recap_cmd_enabled', '0');
        $evo = \Mockery::mock(EvolutionApiService::class);
        $evo->shouldNotReceive('sendToGroup');

        $this->runJob($evo, self::R, ['context' => 'report']);

        $this->addToAssertionCount(1);
    }
}
