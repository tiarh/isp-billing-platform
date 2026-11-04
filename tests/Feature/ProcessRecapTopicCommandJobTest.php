<?php

namespace Tests\Feature;

use App\Jobs\ProcessPsbRecapCommandJob;
use App\Jobs\ProcessRecapTopicCommandJob;
use App\Models\Area;
use App\Models\Customer;
use App\Models\Olt;
use App\Models\OltOnu;
use App\Models\Package;
use App\Models\Setting;
use App\Services\EvolutionApiService;
use App\Services\PsbAreaRecapService;
use App\Services\Recap\OnuHealthService;
use App\Services\Recap\RecapTopicMessages;
use App\Support\OpsTime;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-psb')]
class ProcessRecapTopicCommandJobTest extends TestCase
{
    use RefreshDatabase;

    private const G = '120363-activation@g.us';

    private const R = '120363-skynetreport@g.us';

    private int $seq = 0;

    /** @var list<string> */
    private array $texts = [];

    /** @var list<string> */
    private array $docs = [];

    private bool $docOk = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-15 09:12', 'Asia/Jakarta'));
        File::deleteDirectory(storage_path('app/recap/onu'));
        Setting::set('psb_wa_group_ids', [self::G]);
        Setting::set('recap_onu_cmd_enabled', '1');
        Setting::set('report_wa_group_ids', [self::R]);
        Setting::set('report_recap_cmd_enabled', '1');

        $area = Area::create(['name' => 'SKYNET-KLAMPOK', 'code' => 'klampok']);
        $package = Package::create(['code' => 'P10', 'name' => '10 Mbps', 'price' => 100000]);
        $olt = Olt::create(['name' => 'RA', 'code' => 'RA-1', 'vendor' => 'zte_c300']);
        Customer::create([
            'code' => 'T-1', 'name' => 'Budi Contoh', 'address' => 'Dusun', 'package_id' => $package->id, 'area_id' => $area->id,
            'pppoe_user' => 'budi1', 'status' => 'active', 'join_date' => '2026-01-01', 'olt_id' => $olt->id, 'olt_port_label' => '1/1/1:1', 'odp_name' => 'ODP-KLP-03',
        ]);
        OltOnu::create([
            'olt_id' => $olt->id, 'onu_ref' => '1/1/1:1', 'serial_number' => 'ZTEG00000001', 'state' => 'offline', 'phase_state' => 'LOS',
            'state_collected_at' => now()->subMinutes(3), 'inventory_collected_at' => now()->subMinutes(5),
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/recap/onu'));
        $this->travelBack();
        parent::tearDown();
    }

    private function fakeWhatsapp(string $channel = 'ops'): EvolutionApiService
    {
        $evo = \Mockery::mock(EvolutionApiService::class);
        $evo->shouldReceive('forChannel')->with($channel)->andReturnSelf();
        $evo->shouldReceive('sendToGroup')->andReturnUsing(function (string $jid, string $text) {
            $this->texts[] = $jid.'|'.$text;

            return ['success' => true];
        });
        $evo->shouldReceive('sendDocument')->andReturnUsing(function (string $jid, string $path) {
            $this->assertFileExists($path);
            $this->docs[] = $jid;

            return $this->docOk ? ['success' => true] : ['success' => false, 'error' => 'boom'];
        });

        return $evo;
    }

    /** @param array<string, mixed> $extra */
    private function run_(EvolutionApiService $evo, array $extra = [], ?string &$mid = null): void
    {
        $mid ??= 'MSG-'.(++$this->seq);
        $job = new ProcessRecapTopicCommandJob(array_merge([
            'group_jid' => self::G,
            'sender_jid' => '6281111111111@s.whatsapp.net',
            'message_id' => $mid,
            'text' => '#rekap onu klampok',
        ], $extra));
        $job->handle(app(PsbAreaRecapService::class), app(OnuHealthService::class), app(RecapTopicMessages::class), $evo);
    }

    public function test_job_is_on_the_wa_queue_and_unique(): void
    {
        $job = new ProcessRecapTopicCommandJob(['message_id' => 'X1', 'group_jid' => self::G, 'text' => '#rekap onu klampok']);

        $this->assertSame('wa', $job->queue);
        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame(2, $job->tries);
        $this->assertSame(120, $job->timeout);
        $this->assertSame('recap-topic-msg:X1', $job->uniqueId());
    }

    public function test_sends_text_then_excel_and_deletes_the_file(): void
    {
        $this->run_($this->fakeWhatsapp());

        $this->assertCount(1, $this->texts);
        $this->assertStringStartsWith(self::G.'|*REKAP KESEHATAN ONU - KLAMPOK*', $this->texts[0]);
        $this->assertStringContainsString('BUDI CONTOH - LOS', $this->texts[0]);
        $this->assertSame([self::G], $this->docs);
        $this->assertSame([], File::files(storage_path('app/recap/onu')), 'the Excel must not stay on disk');
    }

    public function test_semua_text_has_no_names(): void
    {
        $this->run_($this->fakeWhatsapp(), ['text' => '#rekap onu semua']);

        $this->assertStringContainsString('REKAP KESEHATAN ONU - SEMUA AREA', $this->texts[0]);
        $this->assertStringNotContainsString('BUDI', $this->texts[0]);
        $this->assertStringNotContainsString('budi1', $this->texts[0]);
        $this->assertStringNotContainsString('ZTEG00000001', $this->texts[0]);
    }

    public function test_no_problem_means_no_excel(): void
    {
        OltOnu::query()->update(['state' => 'online', 'phase_state' => 'working', 'rx_power_dbm' => -20, 'rx_collected_at' => now()]);

        $this->run_($this->fakeWhatsapp());

        $this->assertStringContainsString('Semua ONU terpantau normal.', $this->texts[0]);
        $this->assertSame([], $this->docs);
    }

    public function test_missing_area_gets_the_usage_text(): void
    {
        $this->run_($this->fakeWhatsapp(), ['text' => '#rekap onu']);

        $this->assertStringContainsString('Format: *#rekap onu <daerah|semua>*', $this->texts[0]);
        $this->assertSame([], $this->docs);
    }

    public function test_unknown_area_gets_a_not_found_text(): void
    {
        $this->run_($this->fakeWhatsapp(), ['text' => '#rekap onu zzzzqqq']);

        $this->assertStringContainsString('tidak ditemukan', $this->texts[0]);
        $this->assertSame([], $this->docs);
    }

    public function test_a_typed_date_is_ignored_with_a_note(): void
    {
        $this->run_($this->fakeWhatsapp(), ['text' => '#rekap onu klampok 1-14 okt']);

        $this->assertStringContainsString('(tanggal diabaikan: rekap ONU selalu kondisi saat ini)', $this->texts[0]);
    }

    public function test_flag_off_at_run_time_is_silent(): void
    {
        Setting::set('recap_onu_cmd_enabled', '0');

        $this->run_($this->fakeWhatsapp());

        $this->assertSame([], $this->texts);
        $this->assertSame([], $this->docs);
    }

    public function test_a_non_topic_text_is_silent(): void
    {
        $this->run_($this->fakeWhatsapp(), ['text' => '#rekap psb klampok']);
        $this->run_($this->fakeWhatsapp(), ['text' => 'halo']);

        $this->assertSame([], $this->texts);
    }

    public function test_report_context_uses_the_configured_channel(): void
    {
        Setting::set('report_wa_channel', 'ticket');

        $this->run_($this->fakeWhatsapp('ticket'), ['group_jid' => self::R, 'context' => 'report', 'text' => '#rekap onu semua']);

        $this->assertStringStartsWith(self::R.'|', $this->texts[0]);
        $this->assertSame([self::R], $this->docs);
    }

    public function test_report_context_is_silent_when_disabled_removed_or_overlapping(): void
    {
        Setting::set('report_recap_cmd_enabled', '0');
        $this->run_($this->fakeWhatsapp(), ['group_jid' => self::R, 'context' => 'report']);
        Setting::set('report_recap_cmd_enabled', '1');

        Setting::set('report_wa_group_ids', []);
        $this->run_($this->fakeWhatsapp(), ['group_jid' => self::R, 'context' => 'report']);

        Setting::set('report_wa_group_ids', [self::G]);
        $this->run_($this->fakeWhatsapp(), ['group_jid' => self::G, 'context' => 'report']);

        $this->assertSame([], $this->texts);
    }

    public function test_the_rate_limit_is_shared_with_rekap_psb_and_one_notice_per_window(): void
    {
        // A "#rekap psb" in the same group just took the cooldown key.
        Cache::add('psb-recap:group:'.md5(self::G), 1, now()->addSeconds(ProcessPsbRecapCommandJob::GROUP_COOLDOWN_SECONDS));

        $this->run_($this->fakeWhatsapp());
        $this->run_($this->fakeWhatsapp());

        $this->assertCount(1, $this->texts, 'one notice per window');
        $this->assertStringContainsString('Tunggu sekitar 20 detik', $this->texts[0]);
        $this->assertSame([], $this->docs);
    }

    public function test_the_per_sender_hourly_budget_is_shared(): void
    {
        $key = 'psb-recap:sender:'.md5('6281111111111@s.whatsapp.net').':'.OpsTime::now()->format('YmdH');
        Cache::put($key, ProcessPsbRecapCommandJob::SENDER_PER_HOUR, now()->addHour());

        $this->run_($this->fakeWhatsapp());

        $this->assertCount(1, $this->texts);
        $this->assertStringContainsString('Batas rekap tercapai', $this->texts[0]);
    }

    public function test_a_replayed_message_is_not_answered_twice(): void
    {
        $mid = 'MSG-REPLAY';
        $this->run_($this->fakeWhatsapp(), [], $mid);
        Cache::forget('psb-recap:group:'.md5(self::G));
        $this->run_($this->fakeWhatsapp(), [], $mid);

        $this->assertCount(1, $this->texts);
        $this->assertCount(1, $this->docs);
        $this->assertTrue(Cache::has('recap-topic:done:MSG-REPLAY'));
    }

    public function test_a_failed_document_only_adds_a_short_notice(): void
    {
        $this->docOk = false;

        $this->run_($this->fakeWhatsapp());

        $this->assertCount(2, $this->texts);
        $this->assertStringContainsString('File Excel gagal dikirim', $this->texts[1]);
        $this->assertSame([], File::files(storage_path('app/recap/onu')));
    }

    public function test_old_orphan_files_are_pruned_but_fresh_ones_stay(): void
    {
        File::ensureDirectoryExists(storage_path('app/recap/onu'));
        $old = storage_path('app/recap/onu/old.xlsx');
        $fresh = storage_path('app/recap/onu/fresh.xlsx');
        File::put($old, 'x');
        File::put($fresh, 'x');
        // Relative to the frozen test clock (travelTo in setUp), not the wall clock: a wall-clock offset
        // became "fresh" once real time passed the frozen date + 1 day.
        touch($old, now()->subDays(8)->getTimestamp());

        $this->run_($this->fakeWhatsapp(), ['text' => '#rekap onu']);

        $this->assertFileDoesNotExist($old);
        $this->assertFileExists($fresh);
    }

    public function test_a_collect_failure_retries_then_sends_the_internal_error_notice(): void
    {
        $evo = $this->fakeWhatsapp();
        $job = new ProcessRecapTopicCommandJob(['group_jid' => self::G, 'sender_jid' => 's', 'message_id' => 'MSG-ERR', 'text' => '#rekap onu klampok']);

        try {
            $job->handle(app(PsbAreaRecapService::class), new class extends OnuHealthService
            {
                public function collect(?int $areaId, string $areaLabel, ?CarbonImmutable $now = null, bool $dateIgnored = false): array
                {
                    throw new \RuntimeException('db gone');
                }
            }, app(RecapTopicMessages::class), $evo);
            $this->fail('expected the failure to propagate for the queue retry');
        } catch (\RuntimeException $e) {
            $this->assertSame('db gone', $e->getMessage());
        }

        // attempt 1 of 2: no notice yet
        $this->assertSame([], $this->texts);
    }
}
