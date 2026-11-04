<?php

namespace Tests\Feature;

use App\Exceptions\PsbLockBusyException;
use App\Exceptions\PsbOltLockBusyException;
use App\Http\Controllers\OltC300Controller;
use App\Jobs\ProcessPsbAfterMediaOcrJob;
use App\Jobs\ProcessPsbWaJob;
use App\Jobs\ResumePsbDraftJob;
use App\Models\Area;
use App\Models\Olt;
use App\Models\OltVlanMapping;
use App\Models\Package;
use App\Models\PsbDraft;
use App\Models\Router;
use App\Models\Setting;
use App\Models\User;
use App\Services\EvolutionApiService;
use App\Services\HuaweiAwarePsbOrchestratorService;
use App\Services\HuaweiOltOnlyProvisioningService;
use App\Services\HuaweiPsbProvisioningService;
use App\Services\KtpOcrService;
use App\Services\MikrotikService;
use App\Services\PsbMediaOcrOrchestrator;
use App\Services\PsbMessageParser;
use App\Services\PsbOrchestratorService;
use App\Services\PsbWaMessages;
use App\Services\RadiusUserService;
use App\Support\OltOperationLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Canary;
use Tests\Support\PsbEdges\AllowAllDocumentGate;
use Tests\Support\PsbEdges\UncfgEchoOltController;
use Tests\TestCase;

/**
 * PSB: a busy OLT/SN provisioning lock must not stall the draft. The draft
 * keeps its PPPoE, a token based ResumePsbDraftJob retries automatically.
 */
#[Group('blocking-psb')]
class PsbOltBusyResumeTest extends TestCase
{
    use RefreshDatabase;

    private const NOTICE = "Draft #%d: antrian OLT sedang dipakai.\nPPPoE sudah siap. Aktivasi OLT dicoba lagi otomatis +-1 menit.\nTidak perlu kirim ulang atau #psb lanjut.";

    /** @var list<object> */
    private array $held = [];

    private UncfgEchoOltController $olt;

    protected function tearDown(): void
    {
        foreach ($this->held as $lock) {
            $lock->release();
        }
        $this->held = [];
        parent::tearDown();
    }

    public function test_busy_olt_defers_with_marker_job_and_single_notice(): void
    {
        Queue::fake();
        $this->fixtures();
        $this->hold('psb-olt-1');
        $service = $this->service();
        $draft = $this->secretOkDraft();

        $service->processDraft($draft);

        $draft->refresh();
        $marker = $draft->meta['olt_busy_resume'];
        $this->assertSame(PsbDraft::STATUS_SECRET_OK, $draft->status);
        $this->assertNull($draft->olt_at);
        $this->assertNotNull($draft->secret_at);
        $this->assertSame('olt', $marker['stage']);
        $this->assertSame('olt', $marker['scope']);
        $this->assertSame('fiberhome', $marker['vendor']);
        $this->assertSame(1, $marker['attempt']);
        $this->assertSame('OLT antri: menunggu giliran OLT (otomatis, percobaan 1/5)', $draft->last_error);
        $this->assertSame(0, $this->olt->provisioned);
        $this->assertCount(1, $service->replies);
        $this->assertSame(sprintf(self::NOTICE, $draft->id), $service->replies[0]);
        $this->assertStringNotContainsString('OLT gagal', $service->replies[0]);

        Queue::assertPushed(ResumePsbDraftJob::class, function (ResumePsbDraftJob $job) use ($draft, $marker): bool {
            return $job->draftId === $draft->id
                && $job->token === $marker['token']
                && $job->attempt === 1
                && $job->queue === 'psb'
                && $job->delay >= 60 && $job->delay <= 75;
        });
    }

    public function test_successful_uncfg_detection_clears_stale_pending_uncfg_sn(): void
    {
        Queue::fake();
        $this->fixtures();
        $this->hold('psb-olt-1');
        $service = $this->service();
        $draft = $this->secretOkDraft(['pending_uncfg_sn' => 'FHTTSTALE999', 'pending_uncfg_port' => '1/2/3', 'pending_uncfg_onu_id' => 7]);

        $service->processDraft($draft);

        $meta = $draft->fresh()->meta;
        $this->assertArrayNotHasKey('pending_uncfg_sn', $meta);
        $this->assertArrayNotHasKey('pending_uncfg_port', $meta);
        $this->assertArrayNotHasKey('pending_uncfg_onu_id', $meta);
        $this->assertArrayHasKey('olt_busy_resume', $meta, 'the draft was still saved by the busy deferral');
    }

    public function test_busy_sn_lock_defers_with_sn_scope(): void
    {
        Queue::fake();
        $this->fixtures();
        $this->hold('psb-olt-sn-FHTTBUSY0001');
        $service = $this->service();
        $draft = $this->secretOkDraft();

        $service->processDraft($draft);

        $draft->refresh();
        $this->assertSame('sn', $draft->meta['olt_busy_resume']['scope']);
        $this->assertSame(0, $this->olt->provisioned);
        Queue::assertPushed(ResumePsbDraftJob::class, 1);
        $probe = Cache::lock('psb-olt-1', 5);
        $this->assertTrue($probe->get(), 'OLT lock must be released after SN contention');
        $probe->release();
    }

    public function test_wa_job_is_not_released_and_resume_completes_after_lock_frees(): void
    {
        Queue::fake();
        $this->fixtures();
        $lock = $this->hold('psb-olt-1');
        $service = $this->service();
        $this->app->instance(PsbOrchestratorService::class, $service);
        $draft = $this->secretOkDraft();

        $job = new ProcessPsbWaJob(['draft_id' => $draft->id, 'group_jid' => 'ops@g.us', 'message_id' => 'm1']);
        $job->withFakeQueueInteractions();
        $job->handle($service);
        $job->assertNotReleased();
        $job->assertNotFailed();

        $draft->refresh();
        $token = $draft->meta['olt_busy_resume']['token'];
        $this->assertSame(PsbDraft::STATUS_SECRET_OK, $draft->status);

        $lock->release();
        $this->held = [];
        $this->fakeProvisionController();

        $resume = new ResumePsbDraftJob($draft->id, $token, 1);
        $resume->handle($service);

        $draft->refresh();
        $this->assertSame(PsbDraft::STATUS_OLT_OK, $draft->status);
        $this->assertNotNull($draft->olt_at);
        $this->assertNull($draft->last_error);
        $this->assertArrayNotHasKey('olt_busy_resume', $draft->meta);
        $this->assertSame(1, $this->olt->provisioned);
        $this->assertGreaterThanOrEqual(2, count($service->replies), 'notice + activation message');
        $this->assertSame(sprintf(self::NOTICE, $draft->id), $service->replies[0]);
    }

    public function test_exhaustion_gives_up_once_with_detail_and_stops_chain(): void
    {
        Queue::fake();
        $this->fixtures();
        $this->hold('psb-olt-1');
        $service = $this->service();
        $draft = $this->secretOkDraft(['olt_busy_resume' => $this->marker('old-token', 5)]);

        $service->processDraft($draft);

        $draft->refresh();
        $this->assertSame(PsbDraft::STATUS_SECRET_OK, $draft->status);
        $this->assertSame('OLT: Antrian OLT masih sibuk setelah 5 kali coba otomatis.', $draft->last_error);
        $this->assertArrayNotHasKey('olt_busy_resume', $draft->meta);
        $this->assertCount(1, $service->replies);
        $this->assertStringContainsString('*PPPoE OK, OLT gagal*', $service->replies[0]);
        $this->assertStringContainsString('Detail: Antrian OLT masih sibuk setelah 5 kali coba otomatis.', $service->replies[0]);
        Queue::assertNotPushed(ResumePsbDraftJob::class);
    }

    public function test_attempts_use_backoff_and_rotate_token_without_new_notice(): void
    {
        Queue::fake();
        $this->fixtures();
        $this->hold('psb-olt-1');
        $service = $this->service();
        $draft = $this->secretOkDraft(['olt_busy_resume' => $this->marker('t1', 1)]);

        $service->processDraft($draft);

        $draft->refresh();
        $marker = $draft->meta['olt_busy_resume'];
        $this->assertSame(2, $marker['attempt']);
        $this->assertNotSame('t1', $marker['token']);
        $this->assertSame([], $service->replies, 'notice is sent once per chain');
        $this->assertSame('OLT antri: menunggu giliran OLT (otomatis, percobaan 2/5)', $draft->last_error);
        Queue::assertPushed(ResumePsbDraftJob::class, fn (ResumePsbDraftJob $j) => $j->delay >= 90 && $j->delay <= 105);
        $this->assertSame([60, 90, 120, 180, 240], PsbOrchestratorService::OLT_BUSY_BACKOFF);
    }

    public function test_superseded_token_is_a_noop(): void
    {
        Queue::fake();
        $this->fixtures();
        $service = $this->service();
        $draft = $this->secretOkDraft(['olt_busy_resume' => $this->marker('current', 2)]);

        $this->assertNull($service->resumeDraftAfterOltBusy($draft->id, 'stale'));

        $this->assertSame(0, $this->olt->provisioned);
        $this->assertSame([], $service->replies);
        $this->assertSame('current', $draft->fresh()->meta['olt_busy_resume']['token']);
    }

    public function test_lanjut_while_job_queued_provisions_once_and_job_noops(): void
    {
        Queue::fake();
        $this->fixtures();
        $this->fakeProvisionController();
        $service = $this->service();
        $draft = $this->secretOkDraft(['olt_busy_resume' => $this->marker('queued', 1)]);

        // "#psb lanjut" = status received + processDraft
        $draft->forceFill(['status' => PsbDraft::STATUS_RECEIVED, 'last_error' => null])->save();
        $service->processDraft($draft->fresh());

        $this->assertSame(PsbDraft::STATUS_OLT_OK, $draft->fresh()->status);
        $this->assertSame(1, $this->olt->provisioned);

        (new ResumePsbDraftJob($draft->id, 'queued', 1))->handle($service);

        $this->assertSame(1, $this->olt->provisioned, 'queued job must not provision again');
    }

    public function test_resume_run_on_expired_chain_gives_up_instead_of_starting_a_new_chain(): void
    {
        Queue::fake();
        $this->fixtures();
        $this->hold('psb-olt-1');
        $service = $this->service();
        $marker = $this->marker('old', 2);
        $marker['first_at'] = now()->subMinutes(45)->toIso8601String();
        $draft = $this->secretOkDraft(['olt_busy_resume' => $marker]);

        $service->resumeDraftAfterOltBusy($draft->id, 'old');

        $draft->refresh();
        $this->assertStringStartsWith('OLT: Antrian OLT masih sibuk setelah 2 kali', (string) $draft->last_error);
        $this->assertArrayNotHasKey('olt_busy_resume', $draft->meta);
        $this->assertCount(1, $service->replies);
        $this->assertStringContainsString('OLT gagal', $service->replies[0]);
        Queue::assertNotPushed(ResumePsbDraftJob::class);
    }

    public function test_secret_ok_without_olt_antri_last_error_is_not_resumed(): void
    {
        Queue::fake();
        $this->fixtures();
        $service = $this->service();
        $draft = $this->secretOkDraft(['olt_busy_resume' => $this->marker('corr', 1)]);
        // e.g. a correction apply/cancel put the draft back to secret_ok
        $draft->forceFill(['last_error' => null])->save();

        $this->assertNull($service->resumeDraftAfterOltBusy($draft->id, 'corr'));
        $this->assertSame(0, $this->olt->provisioned);
        $this->assertSame([], $service->replies);
    }

    public function test_cancelled_and_correction_pending_drafts_are_not_resumed(): void
    {
        Queue::fake();
        $this->fixtures();
        $service = $this->service();

        foreach ([PsbDraft::STATUS_CANCELLED, PsbDraft::STATUS_CORRECTION_PENDING] as $status) {
            $draft = $this->secretOkDraft(['olt_busy_resume' => $this->marker('tok-'.$status, 1)]);
            $draft->forceFill(['status' => $status])->save();

            $this->assertNull($service->resumeDraftAfterOltBusy($draft->id, 'tok-'.$status));
            $this->assertSame($status, $draft->fresh()->status);
        }
        $this->assertSame(0, $this->olt->provisioned);
        $this->assertSame([], $service->replies);
    }

    public function test_sn_poller_defers_instead_of_swallowing_busy_olt(): void
    {
        Queue::fake();
        $this->fixtures();
        $this->hold('psb-olt-1');
        $service = $this->service();
        $draft = $this->secretOkDraft();
        $draft->forceFill(['status' => PsbDraft::STATUS_WAITING_SN, 'sn_wait_until' => now()->addMinutes(10)])->save();

        $this->assertSame(1, $service->pollWaitingSn());

        $draft->refresh();
        $this->assertSame(PsbDraft::STATUS_SECRET_OK, $draft->status);
        $this->assertSame(1, $draft->meta['olt_busy_resume']['attempt']);
        Queue::assertPushed(ResumePsbDraftJob::class, 1);
    }

    public function test_after_media_job_is_not_released_and_draft_is_deferred(): void
    {
        Queue::fake();
        $this->fixtures();
        $this->hold('psb-olt-1');
        $media = new OltBusyMediaHarness(
            new EvolutionApiService, new MikrotikService, new PsbMessageParser, new PsbWaMessages,
            new KtpOcrService, new RadiusUserService, new AllowAllDocumentGate,
        );
        $draft = $this->secretOkDraft();

        $job = new ProcessPsbAfterMediaOcrJob($draft->id, ['mode' => 'follow_up', 'batch_key' => 'b1']);
        $job->withFakeQueueInteractions();
        $job->handle($media);

        $job->assertNotReleased();
        $job->assertNotFailed();
        $this->assertSame(1, $draft->fresh()->meta['olt_busy_resume']['attempt']);
        Queue::assertPushed(ResumePsbDraftJob::class, 1);
    }

    public function test_admin_retry_returns_200_with_busy_message(): void
    {
        Queue::fake();
        $this->fixtures();
        $this->hold('psb-olt-1');
        $service = $this->service();
        $this->app->instance(PsbOrchestratorService::class, $service);
        $admin = User::factory()->create(['role' => 'admin']);
        $draft = $this->secretOkDraft();

        $this->actingAs($admin)->postJson("/psb-wa/{$draft->id}/retry")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', "Draft #{$draft->id} di-retry. OLT sedang dipakai; sistem mencoba lagi otomatis.");

        $this->assertSame(PsbDraft::STATUS_SECRET_OK, $draft->fresh()->status);
        Queue::assertPushed(ResumePsbDraftJob::class, 1);
    }

    public function test_job_redispatches_same_token_when_draft_lock_is_busy(): void
    {
        Queue::fake();
        $this->fixtures();
        $service = $this->service();
        $draft = $this->secretOkDraft(['olt_busy_resume' => $this->marker('same', 1)]);
        $this->hold('psb-draft-'.$draft->id);

        (new ResumePsbDraftJob($draft->id, 'same', 1))->handle($service);

        $this->assertSame(0, $this->olt->provisioned);
        Queue::assertPushed(ResumePsbDraftJob::class, function (ResumePsbDraftJob $j) use ($draft): bool {
            return $j->draftId === $draft->id && $j->token === 'same' && $j->lockRetries === 1 && $j->delay === 30;
        });
        $this->assertSame('same', $draft->fresh()->meta['olt_busy_resume']['token']);
    }

    public function test_failed_job_gives_up_cleanly(): void
    {
        Queue::fake();
        $this->fixtures();
        $service = $this->service();
        $this->app->instance(PsbOrchestratorService::class, $service);
        $draft = $this->secretOkDraft(['olt_busy_resume' => $this->marker('boom', 3)]);

        (new ResumePsbDraftJob($draft->id, 'boom', 3))->failed(new \RuntimeException('x'));

        $draft->refresh();
        $this->assertSame(PsbDraft::STATUS_SECRET_OK, $draft->status);
        $this->assertStringStartsWith('OLT: Antrian OLT', (string) $draft->last_error);
        $this->assertArrayNotHasKey('olt_busy_resume', $draft->meta);
        $this->assertCount(1, $service->replies);
        $this->assertStringContainsString('OLT gagal', $service->replies[0]);
    }

    public function test_recover_command_gives_up_stale_waiting_drafts_and_respects_dry_run(): void
    {
        Queue::fake();
        $this->fixtures();
        $service = $this->service();
        $this->app->instance(PsbOrchestratorService::class, $service);

        $stale = $this->secretOkDraft(['olt_busy_resume' => $this->marker('stale', 2, now()->subMinutes(30))]);
        $stale->forceFill(['last_error' => 'OLT antri: menunggu giliran OLT (otomatis, percobaan 2/5)'])->save();
        $fresh = $this->secretOkDraft(['olt_busy_resume' => $this->marker('fresh', 1, now()->addMinute())]);
        $fresh->forceFill(['last_error' => 'OLT antri: menunggu giliran OLT (otomatis, percobaan 1/5)'])->save();

        $this->artisan('psb:recover-stuck-ocr')
            ->expectsOutputToContain('olt_busy_recovered=1')
            ->assertSuccessful();
        $this->assertStringStartsWith('OLT antri', (string) $stale->fresh()->last_error, 'dry run must not write');
        $this->assertSame([], $service->replies);

        $this->artisan('psb:recover-stuck-ocr', ['--apply' => true])
            ->expectsOutputToContain('olt_busy_recovered=1')
            ->assertSuccessful();

        $this->assertStringStartsWith('OLT: Antrian OLT', (string) $stale->fresh()->last_error);
        $this->assertArrayNotHasKey('olt_busy_resume', $stale->fresh()->meta);
        $this->assertStringStartsWith('OLT antri', (string) $fresh->fresh()->last_error);
        $this->assertCount(1, $service->replies);
    }

    public function test_kill_switch_off_ends_like_before_with_detail_and_no_job(): void
    {
        Queue::fake();
        $this->fixtures();
        $this->hold('psb-olt-1');
        Setting::set('psb_olt_busy_resume_enabled', '0');
        $service = $this->service();
        $draft = $this->secretOkDraft();

        $service->processDraft($draft);

        $draft->refresh();
        $this->assertSame(PsbDraft::STATUS_SECRET_OK, $draft->status);
        $this->assertStringStartsWith('OLT: Antrian OLT', (string) $draft->last_error);
        $this->assertArrayNotHasKey('olt_busy_resume', $draft->meta);
        $this->assertCount(1, $service->replies);
        $this->assertStringContainsString('*PPPoE OK, OLT gagal*', $service->replies[0]);
        Queue::assertNotPushed(ResumePsbDraftJob::class);
    }

    public function test_huawei_provision_throws_olt_busy_and_defers_through_the_gadang_seam(): void
    {
        Queue::fake();
        Http::fake();
        config(['psb.olt_lock_wait_seconds' => 0]);

        $router = new Router(['name' => 'SKYNET-GADANG', 'ip_address' => '198.18.2.16', 'username' => 't', 'password' => Canary::of('t'), 'is_active' => true]);
        $router->id = 511;
        $router->save();
        $olt = new Olt([
            'name' => 'OLT GADANG 3', 'code' => 'OLT-GADANG-3', 'vendor' => 'huawei_ma5608t',
            'management_ip' => '198.18.4.11', 'router_id' => 511, 'area_id' => null,
        ]);
        $olt->id = 11;
        $olt->save();
        $area = Area::create(['name' => 'Gadang', 'code' => 'GADANG']);
        $package = Package::create([
            'router_id' => 511, 'code' => 'GADANG-200M', 'name' => 'Gadang 200M', 'mikrotik_profile' => 'GADANG-200M',
            'rate_limit' => '200M/200M', 'price' => 200000, 'is_active' => true,
        ]);

        $huawei = Mockery::mock(HuaweiOltOnlyProvisioningService::class);
        $huawei->shouldReceive('collectAutofind')->andReturn([
            'matches' => [['pon_port' => '0/0/7', 'serial_number' => 'FHTT0000A03A', 'vendor_id' => 'FHTT']],
        ]);
        $huawei->shouldReceive('collectSnapshot')->andReturn(['onus' => []]);
        $huawei->shouldReceive('collectPortOnus')->andReturn(['onus' => []]);
        $huawei->shouldReceive('nextOnuId')->andReturn(1);
        $huawei->shouldReceive('profileFor')->andReturn(['vlan' => 108, 'line_profile' => 26, 'tr069_vlan' => 69]);
        $huawei->shouldReceive('buildPlan')->andReturn(['commands' => ['config'], 'command_hash' => 'h']);
        $huawei->shouldNotReceive('executePlan');
        $this->app->instance(HuaweiOltOnlyProvisioningService::class, $huawei);
        $this->app->forgetInstance(HuaweiPsbProvisioningService::class);

        $this->assertSame('psb-huawei-olt-11', OltOperationLock::psbKey($olt));
        $this->hold('psb-huawei-olt-11');

        $draft = PsbDraft::create([
            'status' => PsbDraft::STATUS_SECRET_OK,
            'group_jid' => 'ops@g.us',
            'full_name' => 'HUAWEI BUSY',
            'daerah' => 'Gadang Utara',
            'olt_id' => 11,
            'router_id' => 511,
            'package_id' => $package->id,
            'area_id' => $area->id,
            'sn' => 'FHTT0000A03A',
            'pppoe_user' => 'HUAWEIBUSY',
            'secret_at' => now(),
            'ktp_photo_path' => 'psb/ktp_fixture.jpg',
            'router_photo_path' => 'psb/sn_fixture.jpg',
            'meta' => ['area_manual' => $area->id],
        ]);

        try {
            app(HuaweiPsbProvisioningService::class)->provision($draft, $olt);
            $this->fail('expected PsbOltLockBusyException');
        } catch (PsbOltLockBusyException $e) {
            $this->assertSame('huawei', $e->vendor);
            $this->assertSame('olt', $e->scope);
            $this->assertNotInstanceOf(PsbLockBusyException::class, $e);
        }

        $seam = app(HuaweiAwarePsbOrchestratorService::class);
        $seam->processDraft($draft->fresh());

        $draft->refresh();
        $this->assertSame(PsbDraft::STATUS_SECRET_OK, $draft->status);
        $this->assertSame('huawei', $draft->meta['olt_busy_resume']['vendor']);
        $this->assertSame(1, $draft->meta['olt_busy_resume']['attempt']);
        $this->assertStringStartsWith('OLT antri:', (string) $draft->last_error);
        Queue::assertPushed(ResumePsbDraftJob::class, 1);
    }

    public function test_contract_exception_is_distinct_and_lock_keys_match_provisioning(): void
    {
        $this->assertFalse(is_subclass_of(PsbOltLockBusyException::class, PsbLockBusyException::class));
        $this->assertTrue(is_subclass_of(PsbOltLockBusyException::class, \RuntimeException::class));

        $this->fixtures();
        $fiberhome = Olt::find(1);
        $this->assertSame('psb-olt-1', OltOperationLock::psbKey($fiberhome));

        // Holding exactly psbKey() must make a real PSB provisioning defer.
        Queue::fake();
        $this->hold(OltOperationLock::psbKey($fiberhome));
        $service = $this->service();
        $draft = $this->secretOkDraft();
        $service->processDraft($draft);
        $this->assertSame(1, $draft->fresh()->meta['olt_busy_resume']['attempt']);
    }

    public function test_regression_real_olt_error_and_uncontended_success_are_unchanged(): void
    {
        Queue::fake();
        $this->fixtures();

        $this->olt->answer = ['status' => 422, 'body' => ['success' => false, 'error' => 'ONU sudah terdaftar']];
        $failing = $this->service();
        $draft = $this->secretOkDraft(['olt_busy_resume' => $this->marker('prev', 1)]);
        $failing->processDraft($draft);

        $draft->refresh();
        $this->assertSame(PsbDraft::STATUS_SECRET_OK, $draft->status);
        $this->assertSame('OLT: ONU sudah terdaftar', $draft->last_error);
        $this->assertArrayNotHasKey('olt_busy_resume', $draft->meta);
        $this->assertStringContainsString('Detail: ONU sudah terdaftar', $failing->replies[0]);
        Queue::assertNotPushed(ResumePsbDraftJob::class);

        $this->fakeProvisionController();
        $ok = $this->service();
        $draft2 = $this->secretOkDraft();
        $ok->processDraft($draft2);
        $this->assertSame(PsbDraft::STATUS_OLT_OK, $draft2->fresh()->status);
        $this->assertSame(1, $this->olt->provisioned);
        Queue::assertNotPushed(ResumePsbDraftJob::class);
    }

    // ───────── fixtures ─────────

    private function hold(string $key): object
    {
        $lock = Cache::lock($key, 600);
        $this->assertTrue($lock->get(), 'fixture lock '.$key);
        $this->held[] = $lock;

        return $lock;
    }

    private function fakeProvisionController(): void
    {
        $this->olt->answer = ['status' => 200, 'body' => ['success' => true, 'onu_id' => 7, 'vlan' => 100]];
    }

    /** @return array<string, mixed> */
    private function marker(string $token, int $attempt, ?\DateTimeInterface $nextAt = null): array
    {
        return [
            'token' => $token,
            'stage' => 'olt',
            'scope' => 'olt',
            'vendor' => 'fiberhome',
            'attempt' => $attempt,
            'first_at' => now()->subMinutes(5)->toIso8601String(),
            'next_at' => ($nextAt ?? now()->addMinute())->format(DATE_ATOM),
        ];
    }

    private function fixtures(): void
    {
        // Device edges: the C300 controller echoes uncfg rows and answers provisioning; no lock waits.
        config(['locks.wait_cap_seconds' => 0]);
        $this->olt = new UncfgEchoOltController;
        $this->app->instance(OltC300Controller::class, $this->olt);
        $router = new Router(['name' => 'SKYNET-RA-OLT', 'ip_address' => '198.18.2.14', 'username' => 'test', 'password' => Canary::of('test'), 'is_active' => true]);
        $router->id = 513;
        $router->save();
        $olt = new Olt(['name' => 'OLT RA', 'code' => 'OLT-1', 'vendor' => 'zte_c300', 'management_ip' => '198.18.4.2', 'router_id' => 513, 'area_id' => null]);
        $olt->id = 1;
        $olt->save();
        Package::create([
            'router_id' => 513, 'code' => 'RA-200M', 'name' => 'RA 200M', 'mikrotik_profile' => '200M',
            'rate_limit' => '200M/200M', 'price' => 200000, 'is_active' => true,
        ]);
        Area::create(['name' => 'SKYNET-KLAMPOK', 'code' => 'klampok']);
        OltVlanMapping::create(['olt_id' => 1, 'pon_port' => '1/1/1', 'service_type' => 'internet', 'vlan' => 100, 'is_active' => true]);
    }

    /** @param array<string, mixed> $meta */
    private function secretOkDraft(array $meta = []): PsbDraft
    {
        return PsbDraft::create([
            'status' => PsbDraft::STATUS_SECRET_OK,
            'group_jid' => 'ops@g.us',
            'full_name' => 'OLT BUSY FIXTURE',
            'daerah' => 'Klampok',
            'sn' => 'FHTTBUSY0001',
            'pppoe_user' => 'busyuser'.random_int(1000, 9999),
            'last_error' => isset($meta['olt_busy_resume'])
                ? 'OLT antri: menunggu giliran OLT (otomatis, percobaan '.$meta['olt_busy_resume']['attempt'].'/5)'
                : null,
            'secret_at' => now(),
            'package_id' => Package::query()->value('id'),
            'router_id' => 513,
            'olt_id' => 1,
            'meta' => ['forced_router_id' => 513, 'area_manual' => (int) Area::query()->value('id')] + $meta,
        ]);
    }

    private function service(): OltBusyHarness
    {
        return new OltBusyHarness(
            new EvolutionApiService, new MikrotikService, new PsbMessageParser, new PsbWaMessages,
            new KtpOcrService, new RadiusUserService, new AllowAllDocumentGate,
        );
    }
}

/** Records replies; devices are the test edges (echo C300 controller), the OLT/SN lock gate is real. */
class OltBusyHarness extends PsbOrchestratorService
{
    /** @var list<string> */
    public array $replies = [];

    public function handleInbound(array $payload): PsbDraft
    {
        return $this->processDraft(PsbDraft::query()->findOrFail((int) $payload['draft_id']));
    }

    public function reply(string $groupJid, string $text, array $mentionJids = []): bool
    {
        $this->replies[] = $text;

        return true;
    }
}

class OltBusyMediaHarness extends PsbMediaOcrOrchestrator
{
    public function reply(string $groupJid, string $text, array $mentionJids = []): bool
    {
        return true;
    }

    protected function finishFollowUpAfterMedia(PsbDraft $draft, array $continuation): PsbDraft
    {
        return $this->processDraft($draft->fresh() ?? $draft);
    }
}
