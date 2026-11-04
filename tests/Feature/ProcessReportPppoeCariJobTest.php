<?php

namespace Tests\Feature;

use App\Jobs\ProcessReportPppoeCariJob;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Router;
use App\Models\Setting;
use App\Services\EvolutionApiService;
use App\Services\KtpOcrService;
use App\Services\MikrotikService;
use App\Services\PppoeSearchService;
use App\Services\PsbDocumentGateService;
use App\Services\PsbMessageParser;
use App\Services\PsbOrchestratorService;
use App\Services\PsbWaMessages;
use App\Services\RadiusUserService;
use App\Services\Report\ReportGroupRegistry;
use App\Support\OpsTime;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Canary;
use Tests\TestCase;

/**
 * Behaviour of the Report-group "#pppoe cari" job: parity with the Aktivasi reply, Report channel,
 * no PSB health cache, rate limits + single notice, idempotent replay, no password, read-only MikroTik.
 */
#[Group('blocking-psb')]
class ProcessReportPppoeCariJobTest extends TestCase
{
    use RefreshDatabase;

    private const R = '120363-skynetreport@g.us';

    private const P = '120363-activation@g.us';

    private int $seq = 0;

    /** @var list<string> */
    private array $texts = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->travelTo(CarbonImmutable::parse('2026-10-15 09:00', 'Asia/Jakarta'));
        Setting::set('psb_wa_group_ids', [self::P]);
        Setting::set('report_wa_group_ids', [self::R]);
        Setting::set('report_pppoe_cmd_enabled', '1');
        Setting::set('psb_pppoe_search_timeout_seconds', 4);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function fakeWhatsapp(string $channel = 'ops', bool $success = true): EvolutionApiService
    {
        $evo = \Mockery::mock(EvolutionApiService::class);
        $evo->shouldReceive('forChannel')->with($channel)->andReturnSelf();
        $evo->shouldReceive('sendToGroup')->andReturnUsing(function (string $jid, string $text) use ($success) {
            $this->texts[] = $jid.'|'.$text;

            return $success ? ['success' => true] : ['success' => false, 'error' => 'down'];
        });

        return $evo;
    }

    /** @var list<array<string, mixed>> */
    private array $connectOptions = [];

    /**
     * Strict mock: only connect / getPPPSecret / getActiveConnections / disconnect are allowed.
     * Any other call (a write such as addPPPSecret, disablePPPSecret, kickUser...) throws and is
     * visible in the reply text ("timeout/gagal baca live"), which the parity assertions reject.
     */
    private function mikrotik(): MikrotikService
    {
        $mock = \Mockery::mock(MikrotikService::class);
        $mock->shouldReceive('connect')->andReturnUsing(function ($router, array $options = []) use ($mock) {
            $this->connectOptions[] = $options;

            return $mock;
        });
        $mock->shouldReceive('disconnect')->andReturnNull();
        $mock->shouldReceive('getPPPSecret')->andReturn(['name' => 'KARTONO_RW4', 'profile' => '20M']);
        $mock->shouldReceive('getActiveConnections')->andReturn([['name' => 'KARTONO_RW4', 'address' => '198.18.11.244', 'uptime' => '1h2m']]);
        foreach (['addPPPSecret', 'disablePPPSecret', 'kickUser', 'deleteIsolationProfile'] as $write) {
            $mock->shouldNotReceive($write);
        }

        return $mock;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function runJob(EvolutionApiService $evo, ?MikrotikService $mikrotik = null, array $extra = [], string $group = self::R): ProcessReportPppoeCariJob
    {
        $job = new ProcessReportPppoeCariJob(array_merge([
            'group_jid' => $group,
            'sender_jid' => '6281111111111@s.whatsapp.net',
            'message_id' => 'MSG-'.(++$this->seq),
            'text' => '#pppoe cari kartono',
        ], $extra));
        $job->handle(new PppoeSearchService($mikrotik ?? $this->mikrotik()), $evo, app(ReportGroupRegistry::class));

        return $job;
    }

    private function customer(): Customer
    {
        $router = Router::create(['name' => 'RA-OLT', 'ip_address' => '198.18.9.1', 'username' => 'admin', 'password' => Canary::of('pw')]);
        $package = Package::create(['code' => 'PKG-R', 'name' => '20 Mbps', 'price' => 150000, 'mikrotik_profile' => '20M', 'is_active' => true]);

        return Customer::create([
            'code' => 'C-KARTONO', 'name' => 'Kartono', 'phone' => '081234567890', 'address' => 'RW 4 Randuagung',
            'package_id' => $package->id, 'router_id' => $router->id, 'pppoe_user' => 'KARTONO_RW4',
            'pppoe_password' => Canary::of('pw'), 'pppoe_local_address' => '198.18.11.1',
            'pppoe_remote_address' => '198.18.11.244', 'status' => 'active',
        ]);
    }

    public function test_reply_is_byte_identical_to_the_aktivasi_reply_and_goes_to_the_report_group(): void
    {
        $this->customer();

        // Aktivasi path (PsbOrchestratorService::handlePppoeQuery) with the same MikroTik answers.
        $aktivasi = new ReportPppoeAktivasiHarness(
            new EvolutionApiService, $this->mikrotik(), new PsbMessageParser, new PsbWaMessages,
            new KtpOcrService, new RadiusUserService, app(PsbDocumentGateService::class),
        );
        $aktivasi->handlePppoeQuery(self::P, 'kartono');
        Cache::flush();
        $this->connectOptions = [];

        $this->runJob($this->fakeWhatsapp(), $this->mikrotik());

        $this->assertCount(1, $this->texts);
        $this->assertSame(self::R.'|'.ReportPppoeAktivasiHarness::$lastReply, $this->texts[0]);
        $this->assertStringContainsString('Secret Live: ada (20M)', $this->texts[0]);
        $this->assertStringContainsString('Active Connection: online (198.18.11.244)', $this->texts[0]);
        // Live read is read-only with the existing timeout setting.
        $this->assertSame([['timeout' => 4, 'attempts' => 1]], $this->connectOptions);
    }

    public function test_password_and_phone_are_never_in_the_reply(): void
    {
        $this->customer();

        $this->runJob($this->fakeWhatsapp());

        $this->assertStringContainsString('tidak ditampilkan', $this->texts[0]);
        $this->assertStringNotContainsString('canary-pw', $this->texts[0]);
        $this->assertStringNotContainsString('081234567890', $this->texts[0]);
    }

    public function test_it_never_touches_the_psb_wa_health_cache(): void
    {
        $this->customer();

        $this->runJob($this->fakeWhatsapp());

        foreach (['psb_wa:last_reply_status', 'psb_wa:last_reply_at', 'psb_wa:last_reply_error'] as $key) {
            $this->assertNull(Cache::get($key), $key);
        }
    }

    public function test_ticket_channel_when_configured(): void
    {
        Setting::set('report_wa_channel', 'ticket');
        $this->customer();

        $this->runJob($this->fakeWhatsapp('ticket'));

        $this->assertCount(1, $this->texts);
    }

    public function test_not_found_reply(): void
    {
        $this->runJob($this->fakeWhatsapp(), null, ['text' => '#pppoe cari zzz-nope']);

        $this->assertStringContainsString('Tidak ditemukan pelanggan dengan kata kunci: *zzz-nope*', $this->texts[0]);
    }

    public function test_usage_reply_for_empty_query(): void
    {
        $this->runJob($this->fakeWhatsapp(), null, ['text' => '#pppoe cari']);

        $this->assertStringContainsString('Format cari PPPoE', $this->texts[0]);
    }

    public function test_replay_of_the_same_message_id_is_silent(): void
    {
        $this->customer();
        $evo = $this->fakeWhatsapp();

        $this->runJob($evo, null, ['message_id' => 'DUP-1']);
        $this->travel(30)->seconds();
        $this->runJob($evo, null, ['message_id' => 'DUP-1']);

        $this->assertCount(1, $this->texts);
    }

    public function test_group_rate_limit_one_search_per_10_seconds_and_a_single_notice(): void
    {
        $this->customer();
        $evo = $this->fakeWhatsapp();

        $this->runJob($evo);                      // allowed
        $this->runJob($evo);                      // blocked -> one notice
        $this->runJob($evo);                      // blocked -> silent
        $this->assertCount(2, $this->texts);
        $this->assertStringContainsString('terlalu cepat', $this->texts[1]);

        $this->travel(11)->seconds();             // next window: allowed again
        $this->runJob($evo);
        $this->assertCount(3, $this->texts);
        $this->assertStringContainsString('Detail PPPoE Pelanggan', $this->texts[2]);
    }

    public function test_sender_rate_limit_30_per_hour_and_a_single_notice(): void
    {
        $this->customer();
        $evo = $this->fakeWhatsapp();

        for ($i = 0; $i < 30; $i++) {
            $this->runJob($evo);
            $this->travel(11)->seconds();
        }
        $this->assertCount(30, $this->texts);

        $this->runJob($evo);                      // 31st: blocked -> one notice
        $this->travel(11)->seconds();
        $this->runJob($evo);                      // still blocked -> silent
        $this->assertCount(31, $this->texts);
        $this->assertStringContainsString('Batas pencarian PPPoE per jam', $this->texts[30]);

        // another sender is not affected by this sender's limit
        $this->travel(11)->seconds();
        $this->runJob($evo, null, ['sender_jid' => '6281200000047@s.whatsapp.net']);
        $this->assertCount(32, $this->texts);
        $this->assertStringContainsString('Detail PPPoE Pelanggan', $this->texts[31]);
    }

    public function test_flag_off_or_group_removed_or_psb_overlap_after_dispatch_is_silent(): void
    {
        $this->customer();
        $evo = $this->fakeWhatsapp();

        Setting::set('report_pppoe_cmd_enabled', '0');
        $this->runJob($evo);

        Setting::set('report_pppoe_cmd_enabled', '1');
        Setting::set('report_wa_group_ids', []);
        $this->runJob($evo);

        Setting::set('report_wa_group_ids', [self::P]);
        $this->runJob($evo, null, [], self::P);

        $this->assertSame([], $this->texts);
    }

    public function test_text_that_is_not_the_exact_command_is_silent_in_the_job_too(): void
    {
        $evo = $this->fakeWhatsapp();

        $this->runJob($evo, null, ['text' => '#pppoe cek kartono']);
        $this->runJob($evo, null, ['text' => '/pppoe cari kartono']);
        $this->runJob($evo, null, ['text' => "#pppoe cari kartono\nx"]);

        $this->assertSame([], $this->texts);
    }

    public function test_failed_send_retries_without_resending_and_final_failure_marks_done(): void
    {
        $this->customer();

        $job = new ProcessReportPppoeCariJob(['group_jid' => self::R, 'sender_jid' => 's', 'message_id' => 'FAIL-1', 'text' => '#pppoe cari kartono']);
        try {
            $job->handle(new PppoeSearchService($this->mikrotik()), $this->fakeWhatsapp('ops', false), app(ReportGroupRegistry::class));
            $this->fail('expected the failed send to throw so the queue retries');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('send failed', $e->getMessage());
        }
        $this->assertNull(Cache::get('report-pppoe-cari:text:FAIL-1'));
        $this->assertNull(Cache::get('report-pppoe-cari:done:FAIL-1'));
    }

    public function test_search_text_is_sent_once_even_if_the_job_is_run_again_after_success(): void
    {
        $this->customer();
        $evo = $this->fakeWhatsapp();

        $job = $this->runJob($evo, null, ['message_id' => 'ONCE-1']);
        $this->assertNotNull(Cache::get('report-pppoe-cari:text:ONCE-1'));
        $this->assertNotNull(Cache::get('report-pppoe-cari:done:ONCE-1'));
        $this->travel(30)->seconds();
        $job->handle(new PppoeSearchService($this->mikrotik()), $evo, app(ReportGroupRegistry::class));

        $this->assertCount(1, $this->texts);
    }

    public function test_over_limit_sender_does_not_claim_the_group_cooldown(): void
    {
        $this->customer();
        $evo = $this->fakeWhatsapp();
        $spammer = '6289999999999@s.whatsapp.net';
        Cache::put('report-pppoe-cari:sender:'.md5($spammer).':'.OpsTime::now()->format('YmdH'), ProcessReportPppoeCariJob::SENDER_PER_HOUR, now()->addHour());

        $this->runJob($evo, null, ['sender_jid' => $spammer]);   // over limit -> notice only
        $this->runJob($evo);                                      // another sender, same instant -> answered

        $this->assertCount(2, $this->texts);
        $this->assertStringContainsString('Batas pencarian PPPoE per jam', $this->texts[0]);
        $this->assertStringContainsString('Detail PPPoE Pelanggan', $this->texts[1]);
    }

    public function test_message_without_sender_id_has_no_shared_hourly_bucket_but_keeps_the_group_cooldown(): void
    {
        $this->customer();
        $evo = $this->fakeWhatsapp();

        for ($i = 0; $i < ProcessReportPppoeCariJob::SENDER_PER_HOUR + 1; $i++) {
            $this->runJob($evo, null, ['sender_jid' => '']);
            $this->travel(11)->seconds();
        }
        $this->assertCount(ProcessReportPppoeCariJob::SENDER_PER_HOUR + 1, $this->texts);
        foreach ($this->texts as $text) {
            $this->assertStringContainsString('Detail PPPoE Pelanggan', $text);
        }

        $this->runJob($evo, null, ['sender_jid' => '']);
        $this->runJob($evo, null, ['sender_jid' => '']);          // inside 10 s: group cooldown still applies
        $this->assertCount(ProcessReportPppoeCariJob::SENDER_PER_HOUR + 3, $this->texts);
        $this->assertStringContainsString('terlalu cepat', $this->texts[ProcessReportPppoeCariJob::SENDER_PER_HOUR + 2]);
    }

    public function test_final_attempt_failure_sends_one_failure_notice_and_marks_done(): void
    {
        $this->customer();
        $sent = [];
        $evo = \Mockery::mock(EvolutionApiService::class);
        $evo->shouldReceive('forChannel')->with('ops')->andReturnSelf();
        $evo->shouldReceive('sendToGroup')->andReturnUsing(function (string $jid, string $text) use (&$sent) {
            $sent[] = $text;

            // the search reply fails, the failure notice goes through
            return count($sent) === 1 ? ['success' => false, 'error' => 'down'] : ['success' => true];
        });

        $job = new ProcessReportPppoeCariJob(['group_jid' => self::R, 'sender_jid' => 's', 'message_id' => 'FINAL-1', 'text' => '#pppoe cari kartono']);
        $queueJob = \Mockery::mock(QueueJob::class);
        $queueJob->shouldReceive('attempts')->andReturn($job->tries);
        $job->setJob($queueJob);

        try {
            $job->handle(new PppoeSearchService($this->mikrotik()), $evo, app(ReportGroupRegistry::class));
            $this->fail('expected the failure to be rethrown');
        } catch (\RuntimeException) {
            // rethrown after the notice
        }

        $this->assertCount(2, $sent);
        $this->assertStringContainsString('Detail PPPoE Pelanggan', $sent[0]);
        $this->assertStringContainsString('gagal diproses', $sent[1]);
        $this->assertNotNull(Cache::get('report-pppoe-cari:done:FINAL-1'));

        // a replay after the final failure stays silent
        $job->handle(new PppoeSearchService($this->mikrotik()), $evo, app(ReportGroupRegistry::class));
        $this->assertCount(2, $sent);
    }

    public function test_extract_query_is_strict(): void
    {
        $this->assertSame('djoko comboran', ProcessReportPppoeCariJob::extractQuery('#pppoe cari djoko comboran'));
        $this->assertSame('', ProcessReportPppoeCariJob::extractQuery('#pppoe cari'));
        $this->assertSame('x', ProcessReportPppoeCariJob::extractQuery("  #PPPOE  CARI  x \t"));
        foreach (['#pppoe', '#pppoe cek x', '#pppoecari x', '/pppoe cari x', 'a #pppoe cari x', "#pppoe cari x\ny", '', '#pppoe carix'] as $bad) {
            $this->assertNull(ProcessReportPppoeCariJob::extractQuery($bad), $bad);
        }
    }
}

class ReportPppoeAktivasiHarness extends PsbOrchestratorService
{
    public static string $lastReply = '';

    public function reply(string $groupJid, string $text, array $mentionJids = []): bool
    {
        self::$lastReply = $text;

        return true;
    }
}
