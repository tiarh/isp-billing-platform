<?php

namespace Tests\Feature;

use App\Console\Commands\PsbRecoverStuckOcr;
use App\Jobs\ProcessPsbAfterMediaOcrJob;
use App\Jobs\ProcessPsbMediaOcrJob;
use App\Models\PsbDraft;
use App\Models\Setting;
use App\Services\KtpOcrService;
use App\Services\PsbOrchestratorService;
use App\Services\PsbWaMessages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use Tests\Support\MarkerKtpOcr;
use Tests\Support\MarkerOcr;
use Tests\Support\OcrTerminalHarness;
use Tests\TestCase;

/**
 * PSB OCR terminal state: one plain "KTP belum terbaca" WA per OCR batch, a
 * sweeper that ends a batch whose OCR never finishes, and no SN re-OCR on a
 * KTP resend. Success paths (KTP found / CSR) must stay untouched.
 */
#[Group('blocking-psb')]
class PsbOcrTerminalTest extends TestCase
{
    use RefreshDatabase;

    private const JPEG = '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAEf/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABBQJ//9oACAEBAAEFAU//2gAMAwEAAgADAAAAEP/EABQRAQAAAAAAAAAAAAAAAAAAABD/2gAIAQMBAT8QH//EABQRAQAAAAAAAAAAAAAAAAAAABD/2gAIAQIBAT8QH//EABQQAQAAAAAAAAAAAAAAAAAAABD/2gAIAQEABj8QH//EABQQAQAAAAAAAAAAAAAAAAAAABD/2gAIAQEAAT8QH//Z';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['filesystems.default' => 'local']);
        Setting::set('psb_document_gate_enabled', '1', 'boolean', 'psb');
        Setting::set('psb_document_gate_enforced_after', now()->subDay()->toIso8601String(), 'text', 'psb');
    }

    protected function tearDown(): void
    {
        MarkerOcr::reset();
        parent::tearDown();
    }

    private function jpeg(string $marker = ''): string
    {
        return base64_decode(self::JPEG).$marker;
    }

    private function harness(): OcrTerminalHarness
    {
        app()->instance(KtpOcrService::class, new MarkerKtpOcr);
        MarkerOcr::install();
        $harness = app()->make(OcrTerminalHarness::class);
        $harness->interceptProcessDraft = true;
        app()->instance(PsbOrchestratorService::class, $harness);

        return $harness;
    }

    /** @param array<string,mixed> $meta @param array<string,mixed> $attrs */
    private function draft(array $meta = [], array $attrs = []): PsbDraft
    {
        return PsbDraft::create(array_merge([
            'status' => PsbDraft::STATUS_RECEIVED,
            'message_id' => 'msg-'.uniqid(),
            'group_jid' => 'ops@g.us',
            'full_name' => 'OCR TERMINAL TEST',
            'odp' => 'ODP-TEST-01',
            'rt' => '01',
            'rw' => '02',
            'tikor' => '-7.9,112.6',
            'daerah' => 'RA',
            'sn' => 'FHTTTEST1234',
            'router_id' => 513,
            'olt_id' => 1,
            'package_id' => 452,
            'last_error' => 'Foto diterima, validasi KTP/SN sedang diproses.',
            'meta' => $meta,
        ], $attrs));
    }

    /** @return array<string,mixed> */
    private function unreadableMeta(string $key = 'K1'): array
    {
        return [
            'waiting_photo' => true,
            'document_gate' => ['status' => 'classified'],
            'photo_class' => ['images' => 1, 'status' => 'completed', 'ocr_batch_completed' => $key, 'unknown_count' => 1, 'ktp_score' => null],
        ];
    }

    private function replyCount(OcrTerminalHarness $h, string $needle): int
    {
        return count(array_filter($h->replies, fn (string $text): bool => str_contains($text, $needle)));
    }

    public function test_ocr_success_with_ktp_sends_received_and_processes_draft(): void
    {
        Storage::disk('local')->put('psb/ktp.jpg', $this->jpeg());
        $h = $this->harness();
        $draft = $this->draft([
            'waiting_photo' => true,
            'document_gate' => ['status' => 'classified'],
            'photo_class' => ['images' => 2, 'status' => 'completed', 'ocr_batch_completed' => 'K1', 'unknown_count' => 0, 'ktp_score' => 100],
        ], ['ktp_photo_path' => 'psb/ktp.jpg']);

        $h->resumeAfterMediaOcr($draft, ['mode' => 'follow_up', 'batch_key' => 'K1']);

        $this->assertSame(1, $this->replyCount($h, 'PSB diterima'));
        $this->assertSame(0, $this->replyCount($h, 'KTP belum terbaca'));
        $this->assertSame([$draft->id], $h->processed);
        $this->assertArrayNotHasKey('ocr_terminal_notified', $draft->fresh()->meta);
    }

    public function test_unreadable_ktp_sends_one_plain_reply_and_repeats_stay_silent(): void
    {
        $h = $this->harness();
        $draft = $this->draft($this->unreadableMeta());

        for ($i = 0; $i < 3; $i++) {
            $h->resumeAfterMediaOcr($draft->fresh(), ['mode' => 'follow_up', 'batch_key' => 'K1']);
        }

        $this->assertCount(1, $h->replies);
        $this->assertStringContainsString('KTP belum terbaca', $h->replies[0]);
        $this->assertStringContainsString('#psb ktp #'.$draft->id, $h->replies[0]);
        $this->assertStringNotContainsString('ktp_missing', $h->replies[0]);
        $fresh = $draft->fresh();
        $this->assertStringNotContainsString('sedang diproses', (string) $fresh->last_error);
        $this->assertStringContainsString('KTP belum terbaca', (string) $fresh->last_error);
        $this->assertNotSame('ocr_pending', data_get($fresh->meta, 'document_gate.status'));
        $this->assertSame([], $h->processed);
    }

    public function test_exact_caption_ktp_photo_is_queued_then_gets_one_unreadable_reply(): void
    {
        Queue::fake();
        config(['services.ocrspace.async_media_enabled' => true]);
        $h = $this->harness();
        $draft = $this->draft(['waiting_photo' => true]);

        $h->attachFollowUpImages($draft, [$this->jpeg('UNREADABLE_PHOTO')], [
            'actor' => 'tech@s.whatsapp.net',
            'source' => 'wa_exact_caption',
            'message_id' => 'm-1',
        ]);

        Queue::assertPushedOn('ocr-media', ProcessPsbMediaOcrJob::class);
        $this->assertSame(1, $this->replyCount($h, 'sedang validasi'));

        /** @var ProcessPsbMediaOcrJob $job */
        $job = Queue::pushed(ProcessPsbMediaOcrJob::class)->first();
        $job->handle($h);
        Queue::assertPushedOn('psb', ProcessPsbAfterMediaOcrJob::class);

        $h->resumeAfterMediaOcr($draft->fresh(), $job->continuation);
        $h->resumeAfterMediaOcr($draft->fresh(), $job->continuation);

        $this->assertSame(1, $this->replyCount($h, 'sedang validasi'));
        $this->assertSame(1, $this->replyCount($h, 'KTP belum terbaca'));
    }

    public function test_csr_draft_without_ktp_proceeds_and_gets_no_ktp_reply(): void
    {
        $h = $this->harness();
        $meta = $this->unreadableMeta();
        $meta['document_policy'] = ['type' => 'exempt_csr', 'reason' => 'Sekolah'];
        $draft = $this->draft($meta, ['full_name' => 'SEKOLAH CSR TEST']);

        $h->resumeAfterMediaOcr($draft, ['mode' => 'follow_up', 'batch_key' => 'K1']);

        $this->assertSame([$draft->id], $h->processed);
        $this->assertSame(0, $this->replyCount($h, 'KTP belum terbaca'));
    }

    public function test_completed_but_pending_batch_is_repaired_and_answered_once(): void
    {
        $h = $this->harness();
        $draft = $this->draft([
            'ocr_batch_key' => 'K1',
            'document_gate' => ['status' => 'ocr_pending'],
            'photo_class' => ['images' => 1, 'status' => 'pending', 'ocr_batch_completed' => 'K1', 'unknown_count' => 1],
        ]);

        $h->resumeAfterMediaOcr($draft, ['mode' => 'recovery', 'batch_key' => 'K1']);

        $fresh = $draft->fresh();
        $this->assertSame('completed', data_get($fresh->meta, 'photo_class.status'));
        $this->assertNotSame('ocr_pending', data_get($fresh->meta, 'document_gate.status'));
        $this->assertArrayNotHasKey('ocr_batch_key', $fresh->meta);
        $this->assertCount(1, $h->replies);
        $this->assertStringContainsString('KTP belum terbaca', $h->replies[0]);

        $h->resumeAfterMediaOcr($fresh, ['mode' => 'recovery', 'batch_key' => 'K1']);
        $this->assertCount(1, $h->replies);
    }

    public function test_recovery_continuation_on_unfinished_ocr_sends_no_validation_reply(): void
    {
        $h = $this->harness();
        $draft = $this->draft([
            'ocr_batch_key' => 'K1',
            'document_gate' => ['status' => 'ocr_pending'],
            'photo_class' => ['images' => 1, 'status' => 'pending', 'queue' => 'ocr-media'],
        ]);

        $h->resumeAfterMediaOcr($draft, ['mode' => 'recovery', 'batch_key' => 'K1']);
        $h->resumeAfterMediaOcr($draft->fresh(), ['mode' => 'recovery', 'batch_key' => 'K1']);

        $this->assertSame([], $h->replies);
    }

    public function test_sweeper_caps_attempts_then_ends_batch_once(): void
    {
        Bus::fake();
        // The array store keeps unique-job locks outside flush(); the file store drops them.
        config(['cache.default' => 'file']);
        Cache::flush();
        $h = $this->harness();
        $draft = $this->draft([
            'ocr_batch_key' => 'K1',
            'waiting_photo' => true,
            'document_gate' => ['status' => 'ocr_pending', 'reason' => 'queued_ocr_media'],
            'photo_class' => ['images' => 1, 'status' => 'pending', 'queue' => 'ocr-media'],
        ]);
        $draft->forceFill(['updated_at' => now()->subMinutes(30)])->saveQuietly();

        for ($i = 0; $i < 4; $i++) {
            // The faked dispatch still takes the ShouldBeUnique lock; a real worker releases it.
            Cache::flush();
            $this->artisan('psb:recover-stuck-ocr', ['--apply' => true])->assertSuccessful();
        }
        Bus::assertDispatchedTimes(ProcessPsbAfterMediaOcrJob::class, 4);
        $this->assertSame([], $h->replies);

        $this->artisan('psb:recover-stuck-ocr', ['--apply' => true])
            ->expectsOutputToContain('terminal=1')
            ->assertSuccessful();

        Bus::assertDispatchedTimes(ProcessPsbAfterMediaOcrJob::class, 4);
        $fresh = $draft->fresh();
        $this->assertSame('failed', data_get($fresh->meta, 'photo_class.status'));
        $this->assertSame('missing', data_get($fresh->meta, 'document_gate.status'));
        $this->assertSame('ocr_failed', data_get($fresh->meta, 'document_gate.reason'));
        $this->assertCount(1, $h->replies);
        $this->assertStringContainsString('KTP belum terbaca', $h->replies[0]);

        Cache::flush();
        $this->artisan('psb:recover-stuck-ocr', ['--apply' => true])->assertSuccessful();
        Bus::assertDispatchedTimes(ProcessPsbAfterMediaOcrJob::class, 4);
        $this->assertCount(1, $h->replies);
        Cache::flush();
    }

    public function test_sweeper_dry_run_writes_nothing(): void
    {
        Bus::fake();
        $this->harness();
        $draft = $this->draft([
            'ocr_batch_key' => 'K1',
            'document_gate' => ['status' => 'ocr_pending'],
            'photo_class' => ['images' => 1, 'status' => 'pending'],
            'ocr_recovery' => ['batch' => 'K1', 'attempts' => 4],
        ]);
        $draft->forceFill(['updated_at' => now()->subMinutes(30)])->saveQuietly();

        $this->artisan('psb:recover-stuck-ocr')->expectsOutputToContain('terminal=1')->assertSuccessful();

        $this->assertSame('ocr_pending', data_get($draft->fresh()->meta, 'document_gate.status'));
    }

    public function test_new_photo_after_terminal_starts_a_new_batch_and_is_answered_again(): void
    {
        Queue::fake();
        config(['services.ocrspace.async_media_enabled' => true]);
        $h = $this->harness();
        $draft = $this->draft([
            'ocr_batch_key' => 'K1',
            'waiting_photo' => true,
            'document_gate' => ['status' => 'ocr_pending'],
            'photo_class' => ['images' => 1, 'status' => 'pending'],
        ]);
        $h->markMediaOcrTerminal($draft->fresh(), 'K1', 'test');
        $this->assertSame(1, $this->replyCount($h, 'KTP belum terbaca'));

        $h->attachFollowUpImages($draft->fresh(), [$this->jpeg('UNREADABLE_2')], ['source' => 'wa_exact_caption', 'message_id' => 'm-2']);
        $this->assertSame('pending', data_get($draft->fresh()->meta, 'photo_class.status'));
        $this->assertNotSame('K1', $draft->fresh()->meta['ocr_batch_key']);

        /** @var ProcessPsbMediaOcrJob $job */
        $job = Queue::pushed(ProcessPsbMediaOcrJob::class)->first();
        $job->handle($h);
        $h->resumeAfterMediaOcr($draft->fresh(), $job->continuation);

        $this->assertSame(2, $this->replyCount($h, 'KTP belum terbaca'));
    }

    public function test_new_photo_with_readable_ktp_after_terminal_processes_draft(): void
    {
        Queue::fake();
        config(['services.ocrspace.async_media_enabled' => true]);
        $h = $this->harness();
        $draft = $this->draft([
            'ocr_batch_key' => 'K1',
            'waiting_photo' => true,
            'document_gate' => ['status' => 'ocr_pending'],
            'photo_class' => ['images' => 1, 'status' => 'pending'],
        ]);
        $h->markMediaOcrTerminal($draft->fresh(), 'K1', 'test');

        $h->attachFollowUpImages($draft->fresh(), [$this->jpeg('KTP_MARKER')], ['source' => 'wa_exact_caption', 'message_id' => 'm-3']);
        /** @var ProcessPsbMediaOcrJob $job */
        $job = Queue::pushed(ProcessPsbMediaOcrJob::class)->first();
        $job->handle($h);
        $h->resumeAfterMediaOcr($draft->fresh(), $job->continuation);

        $this->assertSame([$draft->id], $h->processed);
        $this->assertSame(1, $this->replyCount($h, 'KTP belum terbaca'));
    }

    public function test_resending_the_same_photo_bytes_gets_a_new_reply_per_intake(): void
    {
        Queue::fake();
        config(['services.ocrspace.async_media_enabled' => true]);
        $h = $this->harness();
        $draft = $this->draft(['waiting_photo' => true]);
        $bytes = $this->jpeg('UNREADABLE_SAME');

        for ($round = 1; $round <= 2; $round++) {
            $h->attachFollowUpImages($draft->fresh(), [$bytes], ['source' => 'wa_exact_caption', 'message_id' => 'same-'.$round]);
            /** @var ProcessPsbMediaOcrJob $job */
            $job = Queue::pushed(ProcessPsbMediaOcrJob::class)->last();
            $job->handle($h);
            // Repeated continuations inside one intake stay silent.
            $h->resumeAfterMediaOcr($draft->fresh(), $job->continuation);
            $h->resumeAfterMediaOcr($draft->fresh(), $job->continuation);
            $this->assertSame($round, $this->replyCount($h, 'KTP belum terbaca'));
        }
    }

    public function test_inbound_update_resend_with_same_photo_keeps_draft_updated_reply(): void
    {
        $h = $this->harness();
        $draft = $this->draft($this->unreadableMeta());
        $continuation = ['mode' => 'inbound', 'batch_key' => 'K1', 'is_update' => true, 'payload' => ['group_jid' => 'ops@g.us']];

        $h->resumeAfterMediaOcr($draft->fresh(), $continuation);
        $h->resumeAfterMediaOcr($draft->fresh(), $continuation);
        $this->assertSame(1, $this->replyCount($h, 'Draft di-update'));
        $this->assertSame(1, $this->replyCount($h, 'KTP belum terbaca'));

        // The technician re-forwards the same #psb + photo: new intake, same batch key.
        $h->markPending($draft->fresh(), 'K1');
        $h->resumeAfterMediaOcr($draft->fresh(), $continuation);
        $this->assertSame(2, $this->replyCount($h, 'Draft di-update'));
    }

    public function test_sweeper_terminal_skips_when_a_new_batch_was_staged(): void
    {
        Bus::fake();
        $h = $this->harness();
        $draft = $this->draft([
            'ocr_batch_key' => 'K2',
            'document_gate' => ['status' => 'ocr_pending'],
            'photo_class' => ['images' => 1, 'status' => 'pending'],
            'ocr_recovery' => ['batch' => 'K1', 'attempts' => 4],
        ]);
        $draft->forceFill(['updated_at' => now()->subMinutes(30)])->saveQuietly();

        $this->artisan('psb:recover-stuck-ocr', ['--apply' => true])->assertSuccessful();

        $this->assertSame('ocr_pending', data_get($draft->fresh()->meta, 'document_gate.status'));
        $this->assertSame([], $h->replies);
    }

    public function test_mark_terminal_recheck_skips_when_a_new_batch_is_staged_after_the_sweeper_read_the_draft(): void
    {
        Bus::fake();
        $h = $this->harness();
        $draft = $this->draft([
            'ocr_batch_key' => 'K1',
            'document_gate' => ['status' => 'ocr_pending'],
            'photo_class' => ['images' => 1, 'status' => 'pending'],
            'ocr_recovery' => ['batch' => 'K1', 'attempts' => 4],
        ]);

        // The sweeper read batch K1 (attempts exhausted); before it takes the lock a new photo stages batch K2.
        $meta = $draft->meta;
        $meta['ocr_batch_key'] = 'K2';
        $draft->meta = $meta;
        $draft->saveQuietly();

        $markTerminal = new ReflectionMethod(PsbRecoverStuckOcr::class, 'markTerminal');
        $markTerminal->setAccessible(true);
        $result = $markTerminal->invoke(new PsbRecoverStuckOcr, (int) $draft->id, 'K1');

        $this->assertFalse($result);
        $fresh = $draft->fresh();
        $this->assertSame('ocr_pending', data_get($fresh->meta, 'document_gate.status'));
        $this->assertSame('K2', data_get($fresh->meta, 'ocr_batch_key'));
        $this->assertSame([], $h->replies);

        // Same batch still ends terminally (the re-check does not block the normal case).
        $this->assertTrue($markTerminal->invoke(new PsbRecoverStuckOcr, (int) $draft->id, 'K2'));
        $this->assertSame('missing', data_get($draft->fresh()->meta, 'document_gate.status'));
    }

    public function test_ktp_resend_skips_ocr_of_resolved_sn_photo(): void
    {
        Queue::fake();
        config(['services.ocrspace.async_media_enabled' => true]);
        $h = $this->harness();
        Storage::disk('local')->put('psb/sn_old.jpg', $this->jpeg('SN_MARKER'));
        $draft = $this->draft(['waiting_photo' => true], ['router_photo_path' => 'psb/sn_old.jpg']);

        $h->attachFollowUpImages($draft, [$this->jpeg('KTP_NEW')], ['source' => 'wa_exact_caption', 'message_id' => 'm-4']);

        /** @var ProcessPsbMediaOcrJob $job */
        $job = Queue::pushed(ProcessPsbMediaOcrJob::class)->first();
        $this->assertCount(1, $job->stagedPaths);
        $this->assertStringNotContainsString(hash('sha256', $this->jpeg('SN_MARKER')), $job->stagedPaths[0]);
        $this->assertSame('psb/sn_old.jpg', $draft->fresh()->router_photo_path);
    }

    public function test_sn_photo_is_still_included_when_sn_is_not_resolved_or_not_a_ktp_resend(): void
    {
        Queue::fake();
        config(['services.ocrspace.async_media_enabled' => true]);
        $h = $this->harness();
        Storage::disk('local')->put('psb/sn_old.jpg', $this->jpeg('SN_MARKER'));

        $noSn = $this->draft(['waiting_photo' => true], ['router_photo_path' => 'psb/sn_old.jpg', 'sn' => null]);
        $h->attachFollowUpImages($noSn, [$this->jpeg('KTP_A')], ['source' => 'wa_exact_caption', 'message_id' => 'm-5']);
        $batch = $this->draft(['waiting_photo' => true], ['router_photo_path' => 'psb/sn_old.jpg']);
        $h->attachFollowUpImages($batch, [$this->jpeg('KTP_B')], ['source' => 'wa_batch_followup', 'message_id' => 'm-6']);

        $jobs = Queue::pushed(ProcessPsbMediaOcrJob::class);
        $this->assertCount(2, $jobs);
        foreach ($jobs as $job) {
            $this->assertCount(2, $job->stagedPaths);
        }
    }

    public function test_ktp_unreadable_message_is_short_plain_indonesian(): void
    {
        $draft = $this->draft([], ['full_name' => 'MULYADI']);
        $text = (new PsbWaMessages)->ktpUnreadable($draft);

        $this->assertStringContainsString('KTP belum terbaca', $text);
        $this->assertStringContainsString('#psb ktp #'.$draft->id, $text);
        $this->assertStringContainsString('KTP: CSR', $text);
        $this->assertStringNotContainsString('missing', $text);
        $this->assertLessThanOrEqual(6, count(explode("\n", $text)));
    }
}
