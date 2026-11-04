<?php

namespace Tests\Feature;

use App\Exceptions\LockBusyException;
use App\Exceptions\PsbLockBusyException;
use App\Http\Controllers\OltC300Controller;
use App\Jobs\ProcessPsbWaJob;
use App\Jobs\ResumePsbDraftJob;
use App\Models\Area;
use App\Models\Customer;
use App\Models\KtpMediaRecovery;
use App\Models\Olt;
use App\Models\OltVlanMapping;
use App\Models\Package;
use App\Models\PppoeAddressPoolBlock;
use App\Models\PsbDraft;
use App\Models\Router;
use App\Models\Setting;
use App\Models\User;
use App\Services\EvolutionApiService;
use App\Services\HuaweiAwarePsbOrchestratorService;
use App\Services\KtpMediaRecoveryService;
use App\Services\KtpOcrService;
use App\Services\MikrotikService;
use App\Services\PppoeAddressPoolAllocator;
use App\Services\PppoeManualRenameReconcileService;
use App\Services\PsbMessageParser;
use App\Services\PsbOrchestratorService;
use App\Services\PsbWaMessages;
use App\Services\RadiusUserService;
use App\Support\CacheLockWait;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Canary;
use Tests\Support\PsbEdges\AllowAllDocumentGate;
use Tests\Support\PsbEdges\UncfgEchoOltController;
use Tests\TestCase;

/**
 * Roadmap 1.1 (PSB sites): a busy lock must give a clear answer instead of a
 * dead `if (! $lock->block())` branch. Real held locks, no sleeping
 * (config locks.wait_cap_seconds = 0).
 */
#[Group('blocking-psb')]
class PsbLockGuardTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<object> */
    private array $held = [];

    /** @var list<string> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['locks.wait_cap_seconds' => 0]);
        Setting::set('psb_writes_enabled', '1');
        $this->app->instance(OltC300Controller::class, new UncfgEchoOltController);
    }

    protected function tearDown(): void
    {
        foreach ($this->held as $lock) {
            $lock->release();
        }
        $this->held = [];
        parent::tearDown();
    }

    // 1
    public function test_contract_lock_busy_exception_and_cache_lock_wait_run(): void
    {
        $this->assertTrue(is_subclass_of(LockBusyException::class, \RuntimeException::class));
        $this->assertFalse(is_subclass_of(LockBusyException::class, PsbLockBusyException::class));
        $this->assertFalse(is_subclass_of(PsbLockBusyException::class, LockBusyException::class));

        // free: runs fn, releases
        $free = Cache::lock('lg-free', 30);
        $this->assertSame('done', CacheLockWait::run($free, 5, fn () => 'done', fn () => 'busy'));
        $probe = Cache::lock('lg-free', 30);
        $this->assertTrue($probe->get(), 'run() must release after success');
        $probe->release();

        // fn throws: still released, exception propagates
        $boom = Cache::lock('lg-boom', 30);
        try {
            CacheLockWait::run($boom, 5, function (): void {
                throw new \RuntimeException('boom');
            }, fn () => 'busy');
            $this->fail('expected exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }
        $probe = Cache::lock('lg-boom', 30);
        $this->assertTrue($probe->get(), 'run() must release after an exception');
        $probe->release();

        // busy: onBusy result, fn never runs, holder keeps the lock; cap seam avoids waiting 30s
        $this->hold('lg-busy');
        $ran = false;
        $started = microtime(true);
        $res = CacheLockWait::run(Cache::lock('lg-busy', 30), 30, function () use (&$ran) {
            $ran = true;

            return 'ran';
        }, fn () => 'busy');
        $this->assertSame('busy', $res);
        $this->assertFalse($ran);
        $this->assertLessThan(5.0, microtime(true) - $started);
        $this->assertFalse(Cache::lock('lg-busy', 30)->get(), 'holder must keep its lock');
    }

    // 2
    public function test_p1_busy_draft_lock_replies_once_and_leaves_draft_unchanged(): void
    {
        $this->fixtures();
        $service = $this->service();
        $draft = $this->draft(['status' => PsbDraft::STATUS_PENDING_VLAN, 'last_error' => 'keep me']);
        $this->hold('psb-draft-'.$draft->id);

        $result = $service->processDraft($draft);

        $this->assertTrue($service->draftLockWasBusy());
        $this->assertSame($draft->id, $result->id);
        $this->assertCount(1, $service->replies);
        $this->assertSame(
            "Draft #{$draft->id} sedang dipakai proses lain. Perubahan terakhir sudah tersimpan, tapi aktivasi belum dijalankan.\n"
            ."Cek: #psb cek #{$draft->id}. Bila belum berubah, kirim #psb lanjut #{$draft->id} +-1 menit lagi.",
            $service->replies[0]
        );
        $draft->refresh();
        $this->assertSame(PsbDraft::STATUS_PENDING_VLAN, $draft->status);
        $this->assertSame('keep me', $draft->last_error);
    }

    // 3
    public function test_p1_resume_run_throws_psb_lock_busy_and_job_redispatches(): void
    {
        Queue::fake();
        $this->fixtures();
        $service = $this->service();
        $token = 'tok-1';
        $draft = $this->draft([
            'status' => PsbDraft::STATUS_SECRET_OK,
            'secret_at' => now(),
            'pppoe_user' => 'lgresume',
            'last_error' => 'OLT antri: menunggu giliran OLT (otomatis, percobaan 1/5)',
            'meta' => ['forced_router_id' => 513, 'area_manual' => (int) Area::query()->value('id'), 'olt_busy_resume' => [
                'token' => $token, 'stage' => 'olt', 'scope' => 'olt', 'vendor' => 'fiberhome', 'attempt' => 1,
                'first_at' => now()->subMinutes(2)->toIso8601String(), 'next_at' => now()->toIso8601String(),
            ]],
        ]);
        $this->hold('psb-draft-'.$draft->id);

        try {
            $service->resumeDraftAfterOltBusy($draft->id, $token);
            $this->fail('expected PsbLockBusyException');
        } catch (PsbLockBusyException) {
            $this->assertSame([], $service->replies, 'resume run is silent');
        }
        $this->assertFalse($service->draftLockWasBusy());

        (new ResumePsbDraftJob($draft->id, $token, 1))->handle($service);

        Queue::assertPushed(ResumePsbDraftJob::class, fn (ResumePsbDraftJob $j) => $j->lockRetries === 1 && $j->token === $token);
        $this->assertSame(PsbDraft::STATUS_SECRET_OK, $draft->fresh()->status);
    }

    // 4
    public function test_p1_poller_stays_quiet_on_busy_draft_lock_and_busy_router_queue(): void
    {
        $this->fixtures();
        $service = $this->service();
        $locked = $this->draft(['status' => PsbDraft::STATUS_WAITING_SN, 'sn_wait_until' => now()->addHour()]);
        $this->hold('psb-draft-'.$locked->id);

        $service->pollWaitingSn();

        $this->assertSame([], $service->replies);
        $this->assertSame(PsbDraft::STATUS_WAITING_SN, $locked->fresh()->status);

        // second draft: draft lock free, router PPPoE/IP queue busy -> still quiet
        $locked2 = $this->draft(['status' => PsbDraft::STATUS_WAITING_SN, 'sn_wait_until' => now()->addHour(), 'sn' => 'FHTTBUSY0002']);
        $this->held[0]->release();
        $this->held = [];
        $this->hold('psb-ip-router-513');

        $service->pollWaitingSn();

        $this->assertSame([], $service->replies, 'poller never replies on lock contention');
        $this->assertNotSame(PsbDraft::STATUS_FAILED, $locked2->fresh()->status);
    }

    // 5
    public function test_p2_huawei_gadang_seam_busy_draft_lock_replies_once(): void
    {
        $evo = Mockery::mock(EvolutionApiService::class);
        $evo->shouldReceive('forChannel')->andReturnSelf();
        $evo->shouldReceive('sendToGroup')->andReturnUsing(function (string $group, string $text): array {
            $this->sent[] = $text;

            return ['success' => true];
        });
        $this->app->instance(EvolutionApiService::class, $evo);

        $draft = PsbDraft::create([
            'status' => PsbDraft::STATUS_RECEIVED,
            'group_jid' => 'ops@g.us',
            'full_name' => 'HUAWEI LOCK',
            'daerah' => 'Gadang Utara',
            'olt_id' => 11,
            'router_id' => 511,
            'sn' => 'FHTT0000A03A',
            'ktp_photo_path' => 'psb/ktp_fixture.jpg',
            'router_photo_path' => 'psb/sn_fixture.jpg',
        ]);
        $this->hold('psb-draft-'.$draft->id);

        $seam = app(HuaweiAwarePsbOrchestratorService::class);
        $seam->processDraft($draft);

        $this->assertTrue($seam->draftLockWasBusy());
        $this->assertCount(1, $this->sent);
        $this->assertStringStartsWith("Draft #{$draft->id} sedang dipakai proses lain. Perubahan terakhir sudah tersimpan, tapi aktivasi belum dijalankan.", $this->sent[0]);
        $this->assertSame(PsbDraft::STATUS_RECEIVED, $draft->fresh()->status);
    }

    // 6
    public function test_p3_p4_correction_locks_raise_lock_busy_with_p4_text_and_wa_shows_reason(): void
    {
        Setting::set('psb_correction_writes_enabled', '1');
        $this->fixtures();
        $service = $this->service();
        $draft = $this->draft(['status' => PsbDraft::STATUS_CORRECTION_PENDING, 'meta' => ['psb_correction' => ['before' => [], 'after' => []]]]);
        $this->hold('psb-correction-'.$draft->id);
        $text = "Koreksi draft #{$draft->id} sedang diproses proses lain. Coba lagi +-1 menit.";

        try {
            $service->applyPsbCorrection($draft);
            $this->fail('expected LockBusyException (apply)');
        } catch (LockBusyException $e) {
            $this->assertSame($text, $e->getMessage());
            $this->assertNotInstanceOf(PsbLockBusyException::class, $e);
        }
        try {
            $service->cancelPsbCorrection($draft, 'tester');
            $this->fail('expected LockBusyException (cancel)');
        } catch (LockBusyException $e) {
            $this->assertSame($text, $e->getMessage());
        }

        // WA #psb batal: caught, shown as the reason, state unchanged
        $result = $service->batal(['group_jid' => 'ops@g.us'], ['draft_id' => $draft->id]);
        $this->assertSame(PsbDraft::STATUS_CORRECTION_PENDING, $result->status);
        $this->assertCount(1, $service->replies);
        $this->assertStringContainsString('Alasan: '.$text, $service->replies[0]);
        $this->assertStringContainsString('Perangkat/customer belum diubah.', $service->replies[0]);
        $this->assertSame(PsbDraft::STATUS_CORRECTION_PENDING, $draft->fresh()->status);

        // WA #psb lanjut path (apply): reason shown as well
        $service->replies = [];
        $service->lanjut(['group_jid' => 'ops@g.us'], ['draft_id' => $draft->id]);
        $this->assertCount(1, $service->replies);
        $this->assertStringContainsString('Alasan: '.$text, $service->replies[0]);
    }

    // 7
    public function test_admin_retry_apply_and_cancel_return_409_with_message(): void
    {
        $this->fixtures();
        Setting::set('psb_correction_writes_enabled', '1');
        $admin = User::factory()->create(['role' => 'admin']);

        $busy = $this->draft(['status' => PsbDraft::STATUS_PENDING_VLAN, 'last_error' => 'keep']);
        $this->hold('psb-draft-'.$busy->id);
        $this->actingAs($admin)
            ->postJson(route('psb-drafts.retry', ['id' => $busy->id]))
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', "Draft #{$busy->id} sedang dipakai proses lain. Tidak ada yang diubah; coba lagi +-1 menit.");
        $busy->refresh();
        $this->assertSame(PsbDraft::STATUS_PENDING_VLAN, $busy->status, 'busy retry must not touch the draft');
        $this->assertSame('keep', $busy->last_error);

        $corr = $this->draft(['status' => PsbDraft::STATUS_CORRECTION_PENDING, 'sn' => 'FHTTBUSY0003']);
        $this->hold('psb-correction-'.$corr->id);
        $msg = "Koreksi draft #{$corr->id} sedang diproses proses lain. Coba lagi +-1 menit.";
        $this->actingAs($admin)
            ->postJson(route('psb-drafts.apply-correction', ['id' => $corr->id]))
            ->assertStatus(409)
            ->assertJsonPath('message', $msg);
        $this->actingAs($admin)
            ->postJson(route('psb-drafts.cancel-correction', ['id' => $corr->id]))
            ->assertStatus(409)
            ->assertJsonPath('message', $msg);
    }

    // 8
    public function test_p5_busy_router_queue_keeps_status_replies_once_and_does_not_fail(): void
    {
        $this->fixtures();
        $service = $this->service();
        $draft = $this->draft(['status' => PsbDraft::STATUS_RECEIVED]);
        $this->hold('psb-ip-router-513');

        $result = $service->processDraft($draft);

        $this->assertFalse($service->draftLockWasBusy());
        $draft->refresh();
        $this->assertSame($draft->id, $result->id);
        $this->assertNotSame(PsbDraft::STATUS_FAILED, $draft->status);
        $this->assertSame(PsbDraft::STATUS_RECEIVED, $draft->status);
        $this->assertNull($draft->secret_at);
        $this->assertSame('Antrian PPPoE/IP router SKYNET-RA-OLT sibuk; belum ada perubahan. Kirim #psb lanjut.', $draft->last_error);
        $this->assertCount(1, $service->replies);
        $this->assertSame(
            "Draft #{$draft->id}: antrian PPPoE/IP router SKYNET-RA-OLT sedang dipakai.\n"
            ."Belum ada perubahan di MikroTik/OLT.\n"
            ."Kirim #psb lanjut #{$draft->id} +-1 menit lagi.",
            $service->replies[0]
        );
        $probe = Cache::lock('psb-draft-'.$draft->id, 5);
        $this->assertTrue($probe->get(), 'draft lock must be released');
        $probe->release();
    }

    // 9
    public function test_p6_busy_allocator_raises_psb_lock_busy_and_wa_job_would_be_unaffected(): void
    {
        $router = $this->poolRouter();
        $this->poolBlock($router);
        $this->hold('pppoe-address-pool-router-'.$router->id);

        $this->expectException(PsbLockBusyException::class);
        $this->expectExceptionMessage('Allocator PPPoE sedang dipakai. Coba ulang beberapa detik lagi.');
        app(PppoeAddressPoolAllocator::class)->allocate($router, []);
    }

    // 10
    public function test_p7_busy_allocator_release_returns_silently_with_warning(): void
    {
        Log::spy();
        $router = $this->poolRouter();
        $block = $this->poolBlock($router);
        $block->forceFill(['last_remote_address' => '198.18.11.5'])->save();
        $this->hold('pppoe-address-pool-router-'.$router->id);

        $released = app(PppoeAddressPoolAllocator::class)->releaseFailedAllocation($router, $block->id, '198.18.11.5');

        $this->assertFalse($released, 'skipped release must not be reported as rolled back');
        $this->assertSame('198.18.11.5', $block->fresh()->last_remote_address, 'pointer untouched while busy');
        Log::shouldHaveReceived('warning')->withArgs(fn ($m) => str_contains((string) $m, 'release allocation skipped'))->once();
    }

    // 11
    public function test_p8_ktp_recovery_and_manual_rename_busy_raise_lock_busy_with_existing_text(): void
    {
        config(['filesystems.default' => 'local', 'app.url' => 'https://ebilling.test']);
        Storage::fake('local');
        $recovery = new KtpMediaRecovery;
        $recovery->forceFill(['status' => 'ready']);
        $this->hold('ktp-media-recovery');
        $ktp = new class extends KtpMediaRecoveryService
        {
            public function hostIsSafe(): bool
            {
                return true;
            }
        };
        try {
            $ktp->execute($recovery);
            $this->fail('expected LockBusyException (ktp)');
        } catch (LockBusyException $e) {
            $this->assertSame('Recovery worker is busy.', $e->getMessage());
        }

        $customer = new Customer(['pppoe_user' => 'OLDUSER']);
        $customer->id = 4242;
        $this->hold('pppoe-manual-rename-customer-4242');
        $rename = Mockery::mock(PppoeManualRenameReconcileService::class)->makePartial();
        $rename->shouldReceive('preview')->andReturn([
            'eligible' => true, 'live_checked' => true, 'new_secret_live' => true, 'old_secret_live' => false,
        ]);
        try {
            $rename->apply($customer, 'NEWUSER', 'alasan cukup panjang');
            $this->fail('expected LockBusyException (rename)');
        } catch (LockBusyException $e) {
            $this->assertSame('Customer sedang direconcile proses lain.', $e->getMessage());
        }
    }

    // 12
    public function test_safety_net_psb_wa_job_replies_message_without_release_or_failure(): void
    {
        $psb = Mockery::mock(PsbOrchestratorService::class);
        $psb->shouldReceive('handleInbound')->andThrow(new LockBusyException('Pesan aman dari lock.'));
        $psb->shouldReceive('hasReplyDeliveryFailure')->andReturn(false);
        $psb->shouldReceive('reply')->once()->with('ops@g.us', 'Pesan aman dari lock.')->andReturn(true);

        $job = new ProcessPsbWaJob(['group_jid' => 'ops@g.us', 'message_id' => 'lg-1']);
        $job->withFakeQueueInteractions();
        $job->handle($psb);

        $job->assertNotReleased();
        $job->assertNotFailed();
    }

    // 13
    public function test_regression_uncontended_draft_lock_is_taken_and_released(): void
    {
        $this->fixtures();
        $service = $this->service();
        $draft = $this->draft(['status' => PsbDraft::STATUS_CANCELLED]);

        $service->processDraft($draft);

        $this->assertFalse($service->draftLockWasBusy());
        $this->assertSame([], $service->replies);
        $probe = Cache::lock('psb-draft-'.$draft->id, 5);
        $this->assertTrue($probe->get(), 'draft lock must be released after a normal run');
        $probe->release();
    }

    // ───────── fixtures ─────────

    private function hold(string $key): object
    {
        $lock = Cache::lock($key, 600);
        $this->assertTrue($lock->get(), 'fixture lock '.$key);
        $this->held[] = $lock;

        return $lock;
    }

    private function poolRouter(): Router
    {
        return Router::create([
            'name' => 'POOL-LG', 'ip_address' => '198.18.9.'.random_int(2, 200),
            'username' => 'admin', 'password' => Canary::of('pw'), 'is_active' => true,
        ]);
    }

    private function poolBlock(Router $router): PppoeAddressPoolBlock
    {
        return PppoeAddressPoolBlock::create([
            'router_id' => $router->id,
            'block_name' => 'block-11',
            'block_index' => 11,
            'address_format' => 'full_ipv4',
            'prefix_template' => '198.18.11',
            'local_address' => '198.18.11.1',
            'remote_start' => '198.18.11.2',
            'remote_end' => '198.18.11.254',
            'next_block_index' => null,
            'allocator_mode' => 'last_remote_pointer',
            'priority' => 11,
            'is_active' => true,
            'warning_threshold' => 10,
        ]);
    }

    private function fixtures(): void
    {
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

    /** @param array<string, mixed> $overrides */
    private function draft(array $overrides = []): PsbDraft
    {
        return PsbDraft::create($overrides + [
            'status' => PsbDraft::STATUS_RECEIVED,
            'group_jid' => 'ops@g.us',
            'full_name' => 'LOCK GUARD FIXTURE',
            'daerah' => 'Klampok',
            'rt' => '01',
            'rw' => '02',
            'odp' => 'ODP-1',
            'sn' => 'FHTTBUSY0001',
            'package_id' => Package::query()->value('id'),
            'router_id' => 513,
            'olt_id' => 1,
            'meta' => ['forced_router_id' => 513, 'area_manual' => (int) Area::query()->value('id')],
        ]);
    }

    private function service(): LockGuardHarness
    {
        return new LockGuardHarness(
            new EvolutionApiService, new MikrotikService, new PsbMessageParser, new PsbWaMessages,
            new KtpOcrService, new RadiusUserService, new AllowAllDocumentGate,
        );
    }
}

/** Records replies; never reaches a device. */
class LockGuardHarness extends PsbOrchestratorService
{
    /** @var list<string> */
    public array $replies = [];

    public function reply(string $groupJid, string $text, array $mentionJids = []): bool
    {
        $this->replies[] = $text;

        return true;
    }

    /** @param array<string, mixed> $payload @param array<string, mixed> $parsed */
    public function batal(array $payload, array $parsed): PsbDraft
    {
        return $this->handleInbound(array_merge($payload, ['text' => '#psb batal #'.$parsed['draft_id']]));
    }

    /** @param array<string, mixed> $payload @param array<string, mixed> $parsed */
    public function lanjut(array $payload, array $parsed): PsbDraft
    {
        return $this->handleInbound(array_merge($payload, ['text' => '#psb lanjut #'.$parsed['draft_id']]));
    }
}
