<?php

namespace Tests\Feature;

use App\Jobs\ProcessPsbWaJob;
use App\Jobs\RecoverKtpMediaJob;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Setting;
use App\Services\KtpMediaRecoveryService;
use App\Services\PsbOrchestratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KtpMediaRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['filesystems.default' => 'local', 'app.url' => 'https://ebilling.test']);
        Storage::fake('local');
        Setting::set('ktp_media_recovery_allowed_hosts', 'legacy.test', 'text', 'media');
        $classifier = \Mockery::mock(PsbOrchestratorService::class);
        $classifier->shouldReceive('classifyWaActivationPhoto')->andReturnUsing(function (string $bytes): array {
            $image = getimagesizefromstring($bytes);
            $ratio = ($image[0] ?? 0) / max(1, ($image[1] ?? 1));
            $ktp = $ratio >= 1.38 && $ratio <= 1.82;

            return ['type' => $ktp ? 'ktp' : 'sn', 'ktp_score' => $ktp ? 80 : 0, 'sn_score' => $ktp ? 0 : 80];
        });
        $this->app->instance(PsbOrchestratorService::class, $classifier);
    }

    public function test_exact_media_stages_applies_and_rolls_back_idempotently(): void
    {
        $bytes = $this->image(160, 100);
        Http::fake(fn ($request) => Http::response($bytes, 200, ['Content-Type' => 'image/png']));
        $customer = $this->customer('https://legacy.test/ktp/customer-1.png');
        $service = app(KtpMediaRecoveryService::class);

        $first = $service->stage($customer, 'noc', 'canary exact KTP')['recovery'];
        $second = $service->stage($customer, 'noc', 'canary exact KTP')['recovery'];

        $this->assertSame($first->id, $second->id);
        $this->assertSame('ready', $first->status);
        $this->assertSame('image/png', $first->source_mime);
        $this->assertNotSame('https://legacy.test/ktp/customer-1.png', $first->getRawOriginal('source_locator'));

        $done = $service->execute($first);
        $this->assertSame('verified', $done->status);
        Storage::disk('local')->assertExists($done->target_path);
        $this->assertSame($done->target_path, $customer->fresh()->getRawOriginal('ktp_photo_url'));

        $service->rollback($done);
        $this->assertSame('https://legacy.test/ktp/customer-1.png', $customer->fresh()->getRawOriginal('ktp_photo_url'));
        Storage::disk('local')->assertMissing($done->target_path);
    }

    public function test_wrong_media_is_review_only_and_never_queued(): void
    {
        Http::fake(['https://legacy.test/*' => Http::response($this->image(100, 100), 200)]);
        $row = app(KtpMediaRecoveryService::class)
            ->stage($this->customer('https://legacy.test/router.png'), 'noc', 'audit')['recovery'];

        $this->assertSame('invalid_media', $row->status);
        $this->expectException(\RuntimeException::class);
        app(KtpMediaRecoveryService::class)->execute($row);
    }

    public function test_low_confidence_ktp_requires_resend_and_is_not_ready(): void
    {
        Http::fake(['https://legacy.test/*' => Http::response($this->image(160, 100), 200)]);
        $classifier = \Mockery::mock(PsbOrchestratorService::class);
        $classifier->shouldReceive('classifyWaActivationPhoto')->andReturn([
            'type' => 'ktp',
            'ktp_score' => 25,
            'sn_score' => 0,
        ]);
        $this->app->instance(PsbOrchestratorService::class, $classifier);

        $row = app(KtpMediaRecoveryService::class)
            ->stage($this->customer('https://legacy.test/low-confidence.png'), 'noc', 'audit')['recovery'];

        $this->assertSame('needs_resend', $row->status);
        $this->assertSame('low_confidence', $row->evidence['reason']);
        $this->assertSame(25, $row->confidence);
    }

    public function test_checksum_change_blocks_customer_update_and_removes_new_target(): void
    {
        $first = $this->image(160, 100, [255, 255, 255]);
        $changed = $this->image(160, 100, [220, 220, 220]);
        $current = $first;
        Http::fake(function () use (&$current) {
            return Http::response($current, 200, ['Content-Type' => 'image/png']);
        });
        $customer = $this->customer('https://legacy.test/changed.png');
        $row = app(KtpMediaRecoveryService::class)->stage($customer, 'noc', 'checksum test')['recovery'];
        $current = $changed;

        try {
            app(KtpMediaRecoveryService::class)->execute($row);
            $this->fail('Checksum change must block recovery.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Source changed', $e->getMessage());
        }

        $this->assertSame('https://legacy.test/changed.png', $customer->fresh()->getRawOriginal('ktp_photo_url'));
        Storage::disk('local')->assertMissing((string) $row->target_path);
        $this->assertSame('failed', $row->fresh()->status);
    }

    public function test_pause_guard_and_job_queue_are_isolated_from_psb(): void
    {
        Setting::set('ktp_media_recovery_paused', '1', 'boolean', 'media');
        $this->assertFalse(app(KtpMediaRecoveryService::class)->hostIsSafe());

        Queue::fake();
        RecoverKtpMediaJob::dispatch(123);
        Queue::assertPushedOn('media-recovery', RecoverKtpMediaJob::class);
        Queue::assertNotPushed(ProcessPsbWaJob::class);
    }

    public function test_command_requires_explicit_small_batch_and_audit_fields(): void
    {
        $this->artisan('ktp:recover-exact', ['--stage' => true, '--customer' => [1]])
            ->expectsOutput('--actor and --reason are required.')
            ->assertFailed();

        $this->artisan('ktp:recover-exact', [
            '--stage' => true,
            '--customer' => [1, 2, 3, 4],
            '--actor' => 'noc',
            '--reason' => 'canary',
        ])->expectsOutput('--stage requires 1-3 explicit --customer IDs.')->assertFailed();
    }

    private function customer(string $url): Customer
    {
        $package = Package::query()->firstOrCreate(
            ['code' => 'RECOVERY-PKG'],
            ['name' => 'Recovery Test', 'price' => 0, 'mikrotik_profile' => 'TEST']
        );
        $suffix = bin2hex(random_bytes(3));
        $customer = new Customer([
            'code' => 'REC-'.$suffix,
            'name' => 'RECOVERY TEST',
            'phone' => '0800'.$suffix,
            'address' => 'TEST',
            'package_id' => $package->id,
            'pppoe_user' => 'RECOVERY_'.$suffix,
            'status' => 'active',
        ]);
        $customer->forceFill(['ktp_photo_url' => $url, 'ktp_external_url' => $url]);
        $customer->save();

        return $customer;
    }

    /** @param array{0:int,1:int,2:int} $rgb */
    private function image(int $width, int $height, array $rgb = [255, 255, 255]): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, ...$rgb));
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }
}
