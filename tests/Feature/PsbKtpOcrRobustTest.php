<?php

namespace Tests\Feature;

use App\Jobs\ProcessPsbAfterMediaOcrJob;
use App\Jobs\ProcessPsbMediaOcrJob;
use App\Models\PsbDraft;
use App\Models\Setting;
use App\Services\KtpOcrService;
use App\Services\Psb\PsbOcrReaders;
use App\Services\PsbMediaOcrOrchestrator;
use App\Services\PsbOrchestratorService;
use App\Support\OcrBudget;
use App\Support\TesseractRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use Tests\Fixtures\SyntheticOcrFixtures;
use Tests\Support\PsbCollaborators;
use Tests\TestCase;

/**
 * KTP photo OCR on a slow host (2026-10-17): column-wise text parsed right, one
 * Tesseract run and no Paddle for a readable KTP, a timeout is NOT "KTP belum
 * terbaca" (one silent automatic re-check first), invalid NIK never reaches the
 * draft. PSB WA flow around it (received WA, processDraft, CSR, text commands)
 * keeps today's behaviour.
 */
#[Group('blocking-psb')]
class PsbKtpOcrRobustTest extends TestCase
{
    use RefreshDatabase;

    private const JPEG = '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAEf/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABBQJ//9oACAEBAAEFAU//2gAMAwEAAgADAAAAEP/EABQRAQAAAAAAAAAAAAAAAAAAABD/2gAIAQMBAT8QH//EABQRAQAAAAAAAAAAAAAAAAAAABD/2gAIAQIBAT8QH//EABQQAQAAAAAAAAAAAAAAAAAAABD/2gAIAQEABj8QH//EABQQAQAAAAAAAAAAAAAAAAAAABD/2gAIAQEAAT8QH//Z';

    /** column | labels | timeout | empty | slow_ok (40 s run, full text) | invalid_nik */
    private string $mode = 'column';

    private int $tesseractRuns = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['filesystems.default' => 'local']);
        Setting::set('psb_document_gate_enabled', '1', 'boolean', 'psb');
        Setting::set('psb_document_gate_enforced_after', now()->subDay()->toIso8601String(), 'text', 'psb');

        TesseractRunner::fakeRunner(function (array $cmd, int $timeout): array {
            if ($cmd[0] !== 'tesseract') {
                return ['text' => null, 'timed_out' => false, 'ms' => 1];
            }
            if (in_array('--list-langs', $cmd, true)) {
                return ['text' => "List of available languages (2):\nind\neng", 'timed_out' => false, 'ms' => 1];
            }
            $this->tesseractRuns++;

            return match ($this->mode) {
                'column' => ['text' => SyntheticOcrFixtures::ktpColumnWiseText(), 'timed_out' => false, 'ms' => 14000],
                'slow_ok' => ['text' => SyntheticOcrFixtures::ktpColumnWiseText(), 'timed_out' => false, 'ms' => 40000],
                'labels' => ['text' => SyntheticOcrFixtures::ktpLabelsOnlyText(), 'timed_out' => false, 'ms' => 9000],
                'invalid_nik' => ['text' => str_replace('3507120110000005', '3507129910000001', SyntheticOcrFixtures::ktpColumnWiseText()), 'timed_out' => false, 'ms' => 9000],
                'timeout' => ['text' => null, 'timed_out' => true, 'ms' => 45000],
                default => ['text' => null, 'timed_out' => false, 'ms' => 500],
            };
        });
    }

    protected function tearDown(): void
    {
        TesseractRunner::fakeRunner(null);
        parent::tearDown();
    }

    private function jpeg(string $marker = ''): string
    {
        return base64_decode(self::JPEG).$marker;
    }

    private function harness(?string $paddleText = null): RobustHarness
    {
        $ktp = new CountingKtpOcr;
        $ktp->paddleText = $paddleText;
        app()->instance(KtpOcrService::class, $ktp);
        $h = app()->make(RobustHarness::class);
        $h->interceptProcessDraft = true;
        app()->instance(PsbOrchestratorService::class, $h);

        return $h;
    }

    /** @param array<string,mixed> $meta @param array<string,mixed> $attrs */
    private function draft(array $meta = [], array $attrs = []): PsbDraft
    {
        return PsbDraft::create(array_merge([
            'status' => PsbDraft::STATUS_RECEIVED,
            'message_id' => 'msg-'.uniqid(),
            'group_jid' => 'ops@g.us',
            'full_name' => '',
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
            'meta' => array_merge(['waiting_photo' => true], $meta),
        ], $attrs));
    }

    private function replyCount(RobustHarness $h, string $needle): int
    {
        return count(array_filter($h->replies, fn (string $text): bool => str_contains($text, $needle)));
    }

    /** Stage one KTP photo like the exact-caption intake and return the queued OCR job. */
    private function stage(RobustHarness $h, PsbDraft $draft, string $marker = 'KTP_PHOTO'): ProcessPsbMediaOcrJob
    {
        config(['services.ocrspace.async_media_enabled' => true]);
        $h->attachFollowUpImages($draft->fresh(), [$this->jpeg($marker)], [
            'actor' => 'tech@s.whatsapp.net',
            'source' => 'wa_exact_caption',
            'message_id' => 'm-'.$marker,
        ]);

        /** @var ProcessPsbMediaOcrJob $job */
        $job = Queue::pushed(ProcessPsbMediaOcrJob::class)->last();

        return $job;
    }

    private function ktpCountingService(RobustHarness $h): CountingKtpOcr
    {
        /** @var CountingKtpOcr $svc */
        $svc = app(KtpOcrService::class);

        return $svc;
    }

    public function test_column_wise_ktp_uses_one_tesseract_run_and_no_paddle(): void
    {
        $this->mode = 'column';
        $h = $this->harness();
        $draft = $this->draft();

        $h->attach($draft, [$this->jpeg('A')]);

        $fresh = $draft->fresh();
        $this->assertSame(1, $this->tesseractRuns);
        $this->assertSame(0, $this->ktpCountingService($h)->paddleCalls);
        $this->assertNotSame('', (string) $fresh->ktp_photo_path);
        $this->assertSame('DRAFTE CO SANTOSO', $fresh->full_name);
        $this->assertSame('3507120110000005', $fresh->nik);
        $this->assertSame('ok', data_get($fresh->meta, 'photo_class.ocr_status.0'));
    }

    public function test_labels_only_text_never_puts_a_label_into_the_name(): void
    {
        $this->mode = 'labels';
        $h = $this->harness();
        $draft = $this->draft();

        $h->attach($draft, [$this->jpeg('B')]);

        $fresh = $draft->fresh();
        $this->assertSame('', (string) $fresh->full_name);
        $this->assertNotSame('TEMPAT', $fresh->full_name);
        $this->assertEmpty($fresh->nik);

        $class = $h->classifyWaActivationPhoto($this->jpeg('B2'));
        $this->assertNull($class['ktp_ocr']['name']);
    }

    public function test_invalid_nik_is_not_written_to_the_draft_and_only_last_four_digits_are_kept(): void
    {
        $this->mode = 'invalid_nik';
        $h = $this->harness();
        $draft = $this->draft([], ['nik' => '3507120110000005']);

        $h->attach($draft, [$this->jpeg('C')]);

        $fresh = $draft->fresh();
        $this->assertSame('3507120110000005', $fresh->nik, 'invalid read must not overwrite the NIK');
        $this->assertSame('0001', data_get($fresh->meta, 'photo_class.ktp_nik_unverified'));
        $this->assertStringNotContainsString('3507129910000001', json_encode($fresh->meta));
        $this->assertNotSame('', (string) $fresh->ktp_photo_path);
    }

    public function test_a_40_second_run_is_ok_and_a_timeout_triggers_exactly_one_paddle_call(): void
    {
        Queue::fake();
        // 40 s (steal 33-50%): Tesseract answers, Paddle stays idle.
        $this->mode = 'slow_ok';
        $h = $this->harness();
        $draft = $this->draft();
        $job = $this->stage($h, $draft, 'SLOW_OK');
        $job->handle($h);
        $this->assertSame(0, $this->ktpCountingService($h)->paddleCalls);
        $this->assertSame('classified', data_get($draft->fresh()->meta, 'document_gate.status'));

        // 50 s (killed at the 45 s cap): one Paddle call, no second Tesseract.
        $this->tesseractRuns = 0;
        $this->mode = 'timeout';
        $h2 = $this->harness(SyntheticOcrFixtures::ktpPaddleLinesText());
        $draft2 = $this->draft();
        $job2 = $this->stage($h2, $draft2, 'TIMEOUT_PADDLE_OK');
        $job2->handle($h2);

        $this->assertSame(1, $this->tesseractRuns);
        $this->assertSame(1, $this->ktpCountingService($h2)->paddleCalls);
        $fresh = $draft2->fresh();
        $this->assertNotSame('', (string) $fresh->ktp_photo_path);
        $this->assertSame('DRAFTE CO SANTOSO', $fresh->full_name);
        $this->assertNotSame('retry_pending', data_get($fresh->meta, 'photo_class.status'));
    }

    public function test_degraded_first_pass_sends_no_wa_queues_one_retry_and_keeps_the_files(): void
    {
        Queue::fake();
        $this->mode = 'timeout';
        $h = $this->harness();
        $draft = $this->draft();
        $job = $this->stage($h, $draft);
        $this->assertSame(1, $this->replyCount($h, 'sedang validasi'));

        $job->handle($h);

        $fresh = $draft->fresh();
        $this->assertSame('retry_pending', data_get($fresh->meta, 'photo_class.status'));
        $this->assertSame('ocr_pending', data_get($fresh->meta, 'document_gate.status'));
        $this->assertSame('ocr_retry_scheduled', data_get($fresh->meta, 'document_gate.reason'));
        $this->assertSame($job->continuation['batch_key'], data_get($fresh->meta, 'ocr_batch_key'));
        $this->assertSame('timeout', data_get($fresh->meta, 'photo_class.ocr_status.0'));
        $this->assertSame('Foto diterima, server lambat. Dicek ulang otomatis.', $fresh->last_error);
        $this->assertSame('', (string) $fresh->ktp_photo_path);
        // Staged files stay for the retry.
        foreach ($job->stagedPaths as $path) {
            Storage::disk('local')->assertExists($path);
        }
        // Silent: no WA besides the "sedang validasi" sent at staging.
        $this->assertSame(1, $this->replyCount($h, 'sedang validasi'));
        $this->assertCount(1, $h->replies);
        $this->assertSame(1, $this->tesseractRuns);

        Queue::assertNotPushed(ProcessPsbAfterMediaOcrJob::class);
        $jobs = Queue::pushed(ProcessPsbMediaOcrJob::class);
        $this->assertCount(2, $jobs);
        /** @var ProcessPsbMediaOcrJob $retry */
        $retry = $jobs->last();
        $this->assertSame(1, $retry->continuation['ocr_retry']);
        $this->assertSame($job->stagedPaths, $retry->stagedPaths);
        $this->assertStringEndsWith(':r1', $retry->uniqueId());
        $this->assertNotSame($job->uniqueId(), $retry->uniqueId());
        // 120 s default + up to 15 s jitter.
        $this->assertGreaterThanOrEqual(120, $retry->delay);
        $this->assertLessThanOrEqual(135, $retry->delay);
    }

    public function test_retry_that_reads_the_ktp_sends_received_and_processes_the_draft_once(): void
    {
        Queue::fake();
        $this->mode = 'timeout';
        $h = $this->harness();
        $draft = $this->draft();
        $job = $this->stage($h, $draft);
        $job->handle($h);
        /** @var ProcessPsbMediaOcrJob $retry */
        $retry = Queue::pushed(ProcessPsbMediaOcrJob::class)->last();

        $this->mode = 'column';
        $retry->handle($h);
        Queue::assertPushedOn('psb', ProcessPsbAfterMediaOcrJob::class);
        $h->resumeAfterMediaOcr($draft->fresh(), $retry->continuation);
        $h->resumeAfterMediaOcr($draft->fresh(), $retry->continuation);

        $fresh = $draft->fresh();
        $this->assertNotSame('', (string) $fresh->ktp_photo_path);
        $this->assertSame('DRAFTE CO SANTOSO', $fresh->full_name);
        $this->assertSame('completed', data_get($fresh->meta, 'photo_class.status'));
        $this->assertSame(1, $this->replyCount($h, 'PSB diterima'));
        $this->assertSame(0, $this->replyCount($h, 'KTP belum terbaca'));
        $this->assertSame([$draft->id], $h->processed);
        // Files of the batch are consumed only now.
        foreach ($job->stagedPaths as $path) {
            Storage::disk('local')->assertMissing($path);
        }
    }

    public function test_retry_that_is_slow_again_sends_exactly_one_slow_unreadable_reply(): void
    {
        Queue::fake();
        $this->mode = 'timeout';
        $h = $this->harness();
        $draft = $this->draft();
        $job = $this->stage($h, $draft);
        $job->handle($h);
        /** @var ProcessPsbMediaOcrJob $retry */
        $retry = Queue::pushed(ProcessPsbMediaOcrJob::class)->last();

        $retry->handle($h);
        // No third job: the re-check is one-shot.
        $this->assertCount(2, Queue::pushed(ProcessPsbMediaOcrJob::class));
        $h->resumeAfterMediaOcr($draft->fresh(), $retry->continuation);
        $h->resumeAfterMediaOcr($draft->fresh(), $retry->continuation);
        $h->resumeAfterMediaOcr($draft->fresh(), $retry->continuation);

        $this->assertSame(1, $this->replyCount($h, 'KTP belum terbaca'));
        $this->assertSame(1, $this->replyCount($h, 'Server sedang lambat.'));
        $this->assertSame([], $h->processed);
        $this->assertSame(2, $this->tesseractRuns);
        $this->assertTrue((bool) data_get($draft->fresh()->meta, 'photo_class.slow_unreadable'));
    }

    public function test_quick_no_text_result_is_answered_immediately_as_before(): void
    {
        Queue::fake();
        $this->mode = 'empty';
        $h = $this->harness();
        $draft = $this->draft();
        $job = $this->stage($h, $draft);

        $job->handle($h);

        $this->assertCount(1, Queue::pushed(ProcessPsbMediaOcrJob::class));
        Queue::assertPushedOn('psb', ProcessPsbAfterMediaOcrJob::class);
        $h->resumeAfterMediaOcr($draft->fresh(), $job->continuation);
        $this->assertSame(1, $this->replyCount($h, 'KTP belum terbaca'));
        $this->assertSame(0, $this->replyCount($h, 'Server sedang lambat'));
        $this->assertSame('no_text', data_get($draft->fresh()->meta, 'photo_class.ocr_status.0'));
    }

    public function test_csr_draft_is_never_parked_for_a_retry(): void
    {
        Queue::fake();
        $this->mode = 'timeout';
        $h = $this->harness();
        $draft = $this->draft(['document_policy' => ['type' => 'exempt_csr', 'reason' => 'Sekolah']], ['full_name' => 'SEKOLAH CSR TEST']);
        $job = $this->stage($h, $draft);

        $job->handle($h);

        $this->assertCount(1, Queue::pushed(ProcessPsbMediaOcrJob::class));
        $this->assertNotSame('retry_pending', data_get($draft->fresh()->meta, 'photo_class.status'));
        Queue::assertPushedOn('psb', ProcessPsbAfterMediaOcrJob::class);
        $h->resumeAfterMediaOcr($draft->fresh(), $job->continuation);
        $this->assertSame([$draft->id], $h->processed);
        $this->assertSame(0, $this->replyCount($h, 'KTP belum terbaca'));
    }

    public function test_a_new_photo_supersedes_a_pending_retry(): void
    {
        Queue::fake();
        $this->mode = 'timeout';
        $h = $this->harness();
        $draft = $this->draft();
        $job = $this->stage($h, $draft, 'FIRST');
        $job->handle($h);
        /** @var ProcessPsbMediaOcrJob $retry */
        $retry = Queue::pushed(ProcessPsbMediaOcrJob::class)->last();

        // The technician sends another photo before the re-check runs.
        $second = $this->stage($h, $draft->fresh(), 'SECOND');
        $newKey = $second->continuation['batch_key'];
        $this->assertNotSame($job->continuation['batch_key'], $newKey);
        $runsBefore = $this->tesseractRuns;

        $retry->handle($h);

        $fresh = $draft->fresh();
        $this->assertSame($runsBefore, $this->tesseractRuns, 'stale retry must not OCR');
        $this->assertSame($newKey, data_get($fresh->meta, 'ocr_batch_key'));
        $this->assertSame('pending', data_get($fresh->meta, 'photo_class.status'));
        // The parked photo is carried into the new batch, so it is kept; the
        // current batch keeps all its files.
        foreach ($retry->stagedPaths as $path) {
            $this->assertContains($path, $second->stagedPaths);
            Storage::disk('local')->assertExists($path);
        }
        foreach ($second->stagedPaths as $path) {
            Storage::disk('local')->assertExists($path);
        }
        Queue::assertNotPushed(ProcessPsbAfterMediaOcrJob::class);
    }

    public function test_parked_retry_photo_is_not_lost_when_a_new_photo_arrives(): void
    {
        Queue::fake();
        $ktpSvc = new CountingKtpOcr;
        $ktpSvc->outcome = 'busy';
        app()->instance(KtpOcrService::class, $ktpSvc);
        $h = app()->make(MarkerHarness::class);
        $h->interceptProcessDraft = true;
        app()->instance(PsbOrchestratorService::class, $h);
        $h->blankKtp = true; // the busy server read nothing from the KTP photo
        $draft = $this->draft();
        $parked = $this->stage($h, $draft, 'KTP_PHOTO_PARKED');
        $parked->handle($h);
        $this->assertSame('retry_pending', data_get($draft->fresh()->meta, 'photo_class.status'));
        /** @var ProcessPsbMediaOcrJob $retry */
        $retry = Queue::pushed(ProcessPsbMediaOcrJob::class)->last();

        // Photo B (an SN sticker, not a KTP) arrives inside the wait for the re-check.
        $second = $this->stage($h, $draft->fresh(), 'SN_PHOTO_B');

        $this->assertCount(2, $second->stagedPaths);
        foreach ($parked->stagedPaths as $path) {
            $this->assertContains($path, $second->stagedPaths);
        }
        $this->assertCount(2, $second->contexts);
        // The carried photo keeps its original message and sender.
        $carried = collect($second->contexts)->firstWhere('sha256', hash('sha256', $this->jpeg('KTP_PHOTO_PARKED')));
        $this->assertSame('m-KTP_PHOTO_PARKED', $carried['message_id'] ?? null);
        $this->assertSame('wa_exact_caption', $carried['source'] ?? null);
        $this->assertNotSame($parked->continuation['batch_key'], $second->continuation['batch_key']);
        $this->assertSame($second->stagedPaths, data_get($draft->fresh()->meta, 'ocr_batch_paths'));

        // The old re-check is superseded and must not delete the carried photo.
        $retry->handle($h);
        foreach ($parked->stagedPaths as $path) {
            Storage::disk('local')->assertExists($path);
        }

        // The new batch reads both photos; the KTP/NIK can only come from the carried photo.
        $h->blankKtp = false;
        $ktpSvc->paddleText = SyntheticOcrFixtures::ktpPaddleLinesText();
        $second->handle($h);

        $fresh = $draft->fresh();
        $this->assertSame(2, (int) data_get($fresh->meta, 'photo_class.images'));
        $this->assertNotSame('', (string) $fresh->ktp_photo_path);
        $this->assertSame('DRAFTE CO SANTOSO', $fresh->full_name);
        $this->assertSame('3507120110000005', $fresh->nik);
        $ktpIdx = data_get($fresh->meta, 'photo_class.ktp_idx');
        $this->assertSame(
            hash('sha256', $this->jpeg('KTP_PHOTO_PARKED')),
            data_get($fresh->meta, 'photo_class.sha256.'.$ktpIdx)
        );
    }

    public function test_same_photo_resent_while_parked_is_not_added_twice(): void
    {
        Queue::fake();
        $this->mode = 'timeout';
        $h = $this->harness();
        $draft = $this->draft();
        $parked = $this->stage($h, $draft, 'DUP_A');
        $parked->handle($h);
        $this->assertSame('retry_pending', data_get($draft->fresh()->meta, 'photo_class.status'));

        config(['services.ocrspace.async_media_enabled' => true]);
        $h->attachFollowUpImages($draft->fresh(), [$this->jpeg('DUP_A'), $this->jpeg('DUP_B')], [
            'actor' => 'tech@s.whatsapp.net', 'source' => 'wa_exact_caption', 'message_id' => 'm-dup',
        ]);
        /** @var ProcessPsbMediaOcrJob $current */
        $current = Queue::pushed(ProcessPsbMediaOcrJob::class)->last();

        $this->assertCount(2, $current->stagedPaths);
        $this->assertSame($current->stagedPaths, array_values(array_unique($current->stagedPaths)));
        $this->assertCount(2, $current->contexts);
    }

    public function test_many_photos_still_leave_one_paddle_slice_on_the_retry_budget(): void
    {
        // Retry-job config: image 135 s, reserve 53 s, batch 480 s; 6 photos, Tesseract uses all it is given.
        config([
            'services.ocrspace.ocr_async_job' => true,
            'services.ocrspace.paddle_reserve_seconds' => 53,
            'services.ocrspace.media_image_budget_seconds' => 135,
            'services.ocrspace.ocr_total_budget_seconds' => 480,
        ]);
        $ktp = new CountingKtpOcr;
        $ktp->paddleText = SyntheticOcrFixtures::ktpPaddleLinesText();
        app()->instance(KtpOcrService::class, $ktp);
        $h = app()->make(MarkerHarness::class);
        $h->fakeNow = 1000.0;

        $images = [];
        for ($i = 0; $i < 6; $i++) {
            $images[] = $this->jpeg('KTP_PHOTO_'.$i);
        }
        $batch = $h->classifyForTest($this->draft(), $images);

        $this->assertCount(6, $h->tesseractDeadlines);
        foreach ($h->tesseractDeadlines as $deadline) {
            $this->assertLessThanOrEqual(1000.0 + 480 - 53 + 0.001, $deadline);
        }
        $this->assertSame(1, $ktp->paddleCalls);
        $this->assertGreaterThanOrEqual(15, $ktp->seen[0]['remaining']);
        $this->assertNotNull($batch['ktpPick']);
        $this->assertFalse($batch['degraded']);
    }

    public function test_ten_photos_stop_getting_tesseract_floors_at_the_reserve_line(): void
    {
        config([
            'services.ocrspace.ocr_async_job' => true,
            'services.ocrspace.paddle_reserve_seconds' => 53,
            'services.ocrspace.media_image_budget_seconds' => 135,
            'services.ocrspace.ocr_total_budget_seconds' => 480,
        ]);
        $ktp = new CountingKtpOcr;
        $ktp->paddleText = SyntheticOcrFixtures::ktpPaddleLinesText();
        app()->instance(KtpOcrService::class, $ktp);
        $h = app()->make(MarkerHarness::class);
        $h->fakeNow = 1000.0;

        $images = [];
        for ($i = 0; $i < 10; $i++) {
            $images[] = $this->jpeg('KTP_PHOTO_'.$i);
        }
        $batch = $h->classifyForTest($this->draft(), $images);

        $this->assertCount(10, $h->tesseractDeadlines);
        foreach ($h->tesseractDeadlines as $deadline) {
            $this->assertLessThanOrEqual(1000.0 + 480 - 53 + 0.001, $deadline);
        }
        $this->assertSame(1, $ktp->paddleCalls);
        $this->assertGreaterThanOrEqual(15, $ktp->seen[0]['remaining']);
        $this->assertNotNull($batch['ktpPick']);
        $this->assertFalse($batch['degraded']);
    }

    public function test_ktp_as_last_of_ten_slow_photos_is_parked_for_the_recheck_without_unreadable_wa(): void
    {
        Queue::fake();
        $ktpSvc = new CountingKtpOcr;
        $ktpSvc->outcome = 'busy';
        app()->instance(KtpOcrService::class, $ktpSvc);
        $h = app()->make(MarkerHarness::class);
        $h->interceptProcessDraft = true;
        app()->instance(PsbOrchestratorService::class, $h);
        $h->fakeNow = 1000.0;
        $draft = $this->draft();

        config(['services.ocrspace.async_media_enabled' => true]);
        $images = [];
        for ($i = 0; $i < 9; $i++) {
            $images[] = $this->jpeg('SN_PHOTO_'.$i);
        }
        $images[] = $this->jpeg('KTP_PHOTO_LAST');
        $h->attachFollowUpImages($draft->fresh(), $images, [
            'actor' => 'tech@s.whatsapp.net', 'source' => 'wa_exact_caption', 'message_id' => 'm-ten',
        ]);
        /** @var ProcessPsbMediaOcrJob $job */
        $job = Queue::pushed(ProcessPsbMediaOcrJob::class)->last();
        $job->handle($h);

        // The last photo is past the reserve line: no Tesseract text, so the batch is degraded and parked.
        $this->assertLessThanOrEqual(1000.0 + 480 - 35 + 0.001, end($h->tesseractDeadlines));
        $this->assertSame('retry_pending', data_get($draft->fresh()->meta, 'photo_class.status'));
        $this->assertSame(0, $this->replyCount($h, 'KTP belum terbaca'));
        $this->assertGreaterThanOrEqual(1, $ktpSvc->paddleCalls);
    }

    public function test_first_pass_paddle_timeout_parks_the_batch_and_the_retry_reads_the_nik_without_unreadable_wa(): void
    {
        Queue::fake();
        config([
            'services.ocrspace.paddle_enabled' => true,
            'services.ocrspace.paddle_url' => 'http://paddle.test/ocr',
            'services.ocrspace.paddle_min_timeout_seconds' => 15,
            'services.ocrspace.paddle_drain_seconds' => 30,
        ]);
        Cache::flush();
        $paddleAnswers = false;
        $paddlePosts = 0;
        Http::fake(function ($request) use (&$paddleAnswers, &$paddlePosts) {
            if (str_ends_with($request->url(), '/health')) {
                return Http::response(['busy' => false], 200);
            }
            $paddlePosts++;
            if (! $paddleAnswers) {
                throw new ConnectionException('cURL error 28: Operation timed out after 30001 milliseconds');
            }

            return Http::response(['text' => SyntheticOcrFixtures::ktpPaddleLinesText()], 200);
        });
        app()->instance(KtpOcrService::class, new KtpOcrService);
        $h = app()->make(RobustHarness::class);
        $h->interceptProcessDraft = true;
        app()->instance(PsbOrchestratorService::class, $h);

        // Tesseract reads nothing: Paddle is needed.
        $this->mode = 'empty';
        $draft = $this->draft();
        $job = $this->stage($h, $draft);
        $job->handle($h);

        $this->assertSame(1, $paddlePosts);
        $this->assertSame('retry_pending', data_get($draft->fresh()->meta, 'photo_class.status'));
        $this->assertSame(0, $this->replyCount($h, 'KTP belum terbaca'));
        /** @var ProcessPsbMediaOcrJob $retry */
        $retry = Queue::pushed(ProcessPsbMediaOcrJob::class)->last();
        $this->assertSame(1, $retry->continuation['ocr_retry']);

        $paddleAnswers = true;
        $retry->handle($h);

        $fresh = $draft->fresh();
        $this->assertSame(2, $paddlePosts);
        $this->assertSame('3507120110000005', $fresh->nik);
        $this->assertSame('DRAFTE CO SANTOSO', $fresh->full_name);
        $this->assertNotSame('', (string) $fresh->ktp_photo_path);
        $this->assertSame(0, $this->replyCount($h, 'KTP belum terbaca'));
        $this->assertFalse((bool) data_get($fresh->meta, 'photo_class.slow_unreadable'));
    }

    public function test_batch_with_ktp_sn_and_odp_calls_paddle_once_for_the_ktp_photo(): void
    {
        config(['services.ocrspace.ocr_async_job' => true, 'services.ocrspace.paddle_max_calls_per_batch' => 2]);
        $ktp = new CountingKtpOcr;
        $ktp->paddleText = SyntheticOcrFixtures::ktpPaddleLinesText();
        app()->instance(KtpOcrService::class, $ktp);
        $h = app()->make(MarkerHarness::class);
        $draft = $this->draft();

        // ODP first, KTP last: the KTP photo still gets the (only) Paddle call.
        $batch = $h->classifyForTest($draft, [
            $this->jpeg('ODP_PHOTO'),
            $this->jpeg('SN_PHOTO'),
            $this->jpeg('KTP_PHOTO'),
        ]);

        $this->assertSame(1, $ktp->paddleCalls);
        $this->assertSame([SyntheticOcrFixtures::ktpLabelsOnlyText()], $ktp->paddleLocalTexts);
        $this->assertNotNull($batch['ktpPick']);
        $this->assertSame(2, $batch['ktpPick']['idx']);
        $this->assertSame('FHTTCANARY123', $batch['snPick']['sn_hit']);
        $this->assertFalse($batch['degraded']);
    }

    public function test_slow_tesseract_still_leaves_the_paddle_reserve(): void
    {
        config([
            'services.ocrspace.ocr_async_job' => true,
            'services.ocrspace.paddle_reserve_seconds' => 35,
            'services.ocrspace.media_image_budget_seconds' => 90,
            'services.ocrspace.ocr_total_budget_seconds' => 150,
        ]);
        $ktp = new CountingKtpOcr;
        $ktp->paddleText = SyntheticOcrFixtures::ktpPaddleLinesText();
        app()->instance(KtpOcrService::class, $ktp);
        $h = app()->make(MarkerHarness::class);
        $h->fakeNow = 1000.0;

        $batch = $h->classifyForTest($this->draft(), [$this->jpeg('KTP_PHOTO')]);

        // Tesseract was told to stop at image start + 55 s (90 - 35 reserve) and used all of it.
        $this->assertCount(1, $h->tesseractDeadlines);
        $this->assertEqualsWithDelta(1055.0, $h->tesseractDeadlines[0], 0.001);
        $this->assertSame(1, $ktp->paddleCalls);
        $this->assertGreaterThanOrEqual(35, $ktp->seen[0]['remaining']);
        $this->assertNotNull($batch['ktpPick']);
    }

    public function test_reserve_switched_off_gives_tesseract_the_whole_image_budget(): void
    {
        config([
            'services.ocrspace.ocr_async_job' => true,
            'services.ocrspace.paddle_reserve_seconds' => 0,
            'services.ocrspace.media_image_budget_seconds' => 90,
            'services.ocrspace.ocr_total_budget_seconds' => 150,
        ]);
        app()->instance(KtpOcrService::class, new CountingKtpOcr);
        $h = app()->make(MarkerHarness::class);
        $h->fakeNow = 1000.0;

        $h->classifyForTest($this->draft(), [$this->jpeg('KTP_PHOTO')]);

        $this->assertEqualsWithDelta(1090.0, $h->tesseractDeadlines[0], 0.001);
    }

    public function test_stale_retry_never_deletes_a_file_the_current_batch_also_uses(): void
    {
        Queue::fake();
        $this->mode = 'timeout';
        $h = $this->harness();
        $draft = $this->draft();
        $job = $this->stage($h, $draft, 'SHARED');
        $job->handle($h);
        /** @var ProcessPsbMediaOcrJob $retry */
        $retry = Queue::pushed(ProcessPsbMediaOcrJob::class)->last();

        // A new batch that contains the same photo plus another one (new key, shared path).
        config(['services.ocrspace.async_media_enabled' => true]);
        $h->attachFollowUpImages($draft->fresh(), [$this->jpeg('SHARED'), $this->jpeg('EXTRA')], [
            'actor' => 'tech@s.whatsapp.net', 'source' => 'wa_exact_caption', 'message_id' => 'm-x',
        ]);
        /** @var ProcessPsbMediaOcrJob $current */
        $current = Queue::pushed(ProcessPsbMediaOcrJob::class)->last();
        $this->assertNotSame($retry->continuation['batch_key'], $current->continuation['batch_key']);

        $retry->handle($h);

        foreach ($current->stagedPaths as $path) {
            Storage::disk('local')->assertExists($path);
        }
    }

    public function test_cleanup_uses_the_latest_draft_state_not_the_one_read_earlier(): void
    {
        Queue::fake();
        $this->mode = 'timeout';
        $h = $this->harness();
        $draft = $this->draft();
        $job = $this->stage($h, $draft, 'RACE');

        // The caller read the draft while another batch was current ...
        $stale = $draft->fresh();
        $meta = $stale->meta;
        $meta['ocr_batch_key'] = 'OLD';
        $meta['ocr_batch_paths'] = ['psb/unrelated.bin'];
        $stale->meta = $meta;
        // ... and the DB has since been re-staged with the batch that owns these files.
        $this->assertSame($job->continuation['batch_key'], data_get($draft->fresh()->meta, 'ocr_batch_key'));

        $h->cleanupSupersededRetryFiles($stale, $job->stagedPaths);

        foreach ($job->stagedPaths as $path) {
            Storage::disk('local')->assertExists($path);
        }
    }

    public function test_retry_gets_one_and_a_half_times_the_image_budget_and_config_is_restored(): void
    {
        Queue::fake();
        $this->mode = 'timeout';
        $h = $this->harness();
        $svc = $this->ktpCountingService($h);
        $draft = $this->draft();
        $job = $this->stage($h, $draft);
        $job->handle($h);
        /** @var ProcessPsbMediaOcrJob $retry */
        $retry = Queue::pushed(ProcessPsbMediaOcrJob::class)->last();
        $retry->handle($h);

        $this->assertCount(2, $svc->seen);
        $this->assertSame(90, $svc->seen[0]['image_budget']);
        $this->assertLessThanOrEqual(90, $svc->seen[0]['remaining']);
        $this->assertSame(135, $svc->seen[1]['image_budget']);
        $this->assertGreaterThan(100, $svc->seen[1]['remaining']);
        $this->assertSame(60, $svc->seen[1]['run_cap']);
        $this->assertSame(90, (int) config('services.ocrspace.media_image_budget_seconds'));
        $this->assertSame(45, (int) config('services.ocrspace.tesseract_run_cap'));
    }

    /** @return array<string,mixed> */
    private function inboundContinuation(ProcessPsbMediaOcrJob $job): array
    {
        return array_merge($job->continuation, [
            'mode' => 'inbound',
            'is_update' => false,
            'parsed' => [],
            'payload' => ['group_jid' => 'ops@g.us'],
        ]);
    }

    public function test_inbound_mode_retry_ok_sends_one_received_and_processes_once(): void
    {
        Queue::fake();
        $this->mode = 'timeout';
        $h = $this->harness();
        $draft = $this->draft();
        $job = $this->stage($h, $draft);
        $job->handle($h);
        /** @var ProcessPsbMediaOcrJob $retry */
        $retry = Queue::pushed(ProcessPsbMediaOcrJob::class)->last();

        $this->mode = 'column';
        $retry->handle($h);
        $h->resumeAfterMediaOcr($draft->fresh(), $this->inboundContinuation($retry));

        $this->assertSame(1, $this->replyCount($h, 'PSB diterima'));
        $this->assertSame(0, $this->replyCount($h, 'KTP belum terbaca'));
        $this->assertSame([$draft->id], $h->processed);
    }

    public function test_inbound_mode_retry_slow_again_sends_one_slow_unreadable(): void
    {
        Queue::fake();
        $this->mode = 'timeout';
        $h = $this->harness();
        $draft = $this->draft([], ['full_name' => 'BUDI TEST']);
        $job = $this->stage($h, $draft);
        $job->handle($h);
        /** @var ProcessPsbMediaOcrJob $retry */
        $retry = Queue::pushed(ProcessPsbMediaOcrJob::class)->last();
        $retry->handle($h);

        $h->resumeAfterMediaOcr($draft->fresh(), $this->inboundContinuation($retry));
        $h->resumeAfterMediaOcr($draft->fresh(), $this->inboundContinuation($retry));

        $this->assertSame(1, $this->replyCount($h, 'KTP belum terbaca'));
        $this->assertSame(1, $this->replyCount($h, 'Server sedang lambat.'));
        $this->assertSame([], $h->processed);
    }

    public function test_kill_switch_setting_zero_restores_the_old_immediate_unreadable_flow(): void
    {
        Queue::fake();
        Setting::set('psb_ocr_retry_enabled', '0', 'text', 'psb');
        $this->mode = 'timeout';
        $h = $this->harness();
        $draft = $this->draft();
        $job = $this->stage($h, $draft);

        $job->handle($h);

        $this->assertCount(1, Queue::pushed(ProcessPsbMediaOcrJob::class));
        Queue::assertPushedOn('psb', ProcessPsbAfterMediaOcrJob::class);
        $h->resumeAfterMediaOcr($draft->fresh(), $job->continuation);
        $this->assertSame(1, $this->replyCount($h, 'KTP belum terbaca'));
        $this->assertSame(0, $this->replyCount($h, 'Server sedang lambat'));
    }

    public function test_sweeper_leaves_a_retry_pending_batch_alone_until_its_window_ends(): void
    {
        Bus::fake();
        // The faked dispatch still takes the ShouldBeUnique lock; the file store drops it on flush.
        config(['cache.default' => 'file']);
        Cache::flush();
        $this->harness();
        $draft = $this->draft([
            'ocr_batch_key' => 'K1',
            'document_gate' => ['status' => 'ocr_pending', 'reason' => 'ocr_retry_scheduled'],
            'photo_class' => [
                'images' => 1,
                'status' => 'retry_pending',
                'retry' => ['reason' => 'timeout', 'due_at' => now()->addMinutes(3)->toIso8601String(), 'until' => now()->addMinutes(15)->toIso8601String()],
            ],
        ]);
        $draft->forceFill(['updated_at' => now()->subMinutes(30)])->saveQuietly();

        $this->artisan('psb:recover-stuck-ocr', ['--apply' => true])
            ->expectsOutputToContain('retry_pending')
            ->assertSuccessful();
        Bus::assertNotDispatched(ProcessPsbAfterMediaOcrJob::class);

        // Window over: the normal backstop takes it.
        $meta = $draft->fresh()->meta;
        $meta['photo_class']['retry']['until'] = now()->subDay()->toIso8601String();
        $fresh = $draft->fresh();
        $fresh->meta = $meta;
        $fresh->timestamps = false;
        $fresh->saveQuietly();

        Cache::flush();
        $this->artisan('psb:recover-stuck-ocr', ['--apply' => true])->assertSuccessful();
        Bus::assertDispatched(ProcessPsbAfterMediaOcrJob::class);
        Cache::flush();
    }

    public function test_sync_path_is_unchanged_a_timeout_is_classified_in_place_with_no_retry(): void
    {
        Queue::fake();
        config(['services.ocrspace.async_media_enabled' => false]);
        $this->mode = 'timeout';
        $h = $this->harness();
        $draft = $this->draft();

        $h->attach($draft, [$this->jpeg('SYNC')]);

        $fresh = $draft->fresh();
        $this->assertSame(1, (int) data_get($fresh->meta, 'photo_class.unknown_count'));
        $this->assertNotSame('retry_pending', data_get($fresh->meta, 'photo_class.status'));
        $this->assertFalse((bool) data_get($fresh->meta, 'photo_class.slow_unreadable'));
        Queue::assertNothingPushed();
        $this->assertSame(1, $this->tesseractRuns);
    }

    public function test_migration_classifier_reads_column_wise_name_and_reports_degraded_additively(): void
    {
        $this->mode = 'column';
        $h = $this->harness();

        $ok = $h->classifyWaActivationPhoto($this->jpeg('MIG_OK'));

        $this->assertSame('ktp', $ok['type']);
        $this->assertSame('DRAFTE CO SANTOSO', $ok['ktp_ocr']['name']);
        $this->assertSame('3507120110000005', $ok['ktp_ocr']['nik']);
        $this->assertSame('terminal', $ok['classification_status']);
        $this->assertFalse($ok['ocr_degraded']);
        $this->assertIsInt($ok['ocr_ms']);

        $this->mode = 'timeout';
        $slow = $h->classifyWaActivationPhoto($this->jpeg('MIG_SLOW'));

        $this->assertSame('unknown', $slow['type']);
        $this->assertSame('terminal', $slow['classification_status']);
        $this->assertTrue($slow['ocr_degraded']);
        $this->assertSame(2, $this->tesseractRuns);
    }

    /** One intake of $marker: stage, run the OCR job, run the continuation. */
    private function intake(RobustHarness $h, PsbDraft $draft, string $marker): ProcessPsbMediaOcrJob
    {
        $job = $this->stage($h, $draft, $marker);
        $job->handle($h);
        $h->resumeAfterMediaOcr($draft->fresh(), $job->continuation);

        return $job;
    }

    public function test_same_bytes_resent_after_an_unreadable_batch_run_ocr_again_once(): void
    {
        Queue::fake();
        $this->mode = 'empty';
        $h = $this->harness();
        $draft = $this->draft();

        $this->intake($h, $draft, 'SAME_FAILED');
        $runsAfterFailure = $this->tesseractRuns; // an empty read tries several page modes
        $this->assertGreaterThan(0, $runsAfterFailure);
        $this->assertSame('no_text', data_get($draft->fresh()->meta, 'photo_class.ocr_status.0'));
        $this->assertSame(1, $this->replyCount($h, 'KTP belum terbaca'));

        // The technician resends the very same photo and this time it reads.
        $this->mode = 'column';
        $this->intake($h, $draft, 'SAME_FAILED');

        $fresh = $draft->fresh();
        $this->assertSame($runsAfterFailure + 1, $this->tesseractRuns, 'fresh OCR ran exactly once more (one readable run)');
        $this->assertNotSame('', (string) $fresh->ktp_photo_path);
        $this->assertSame('DRAFTE CO SANTOSO', $fresh->full_name);
        $this->assertSame('ok', data_get($fresh->meta, 'photo_class.ocr_status.0'));
        $this->assertSame(1, $this->replyCount($h, 'KTP belum terbaca'));
    }

    public function test_same_bytes_resent_after_a_timeout_batch_run_ocr_again(): void
    {
        Queue::fake();
        $this->mode = 'timeout';
        $h = $this->harness();
        $draft = $this->draft();
        $job = $this->stage($h, $draft, 'SAME_TIMEOUT');
        $job->handle($h);
        /** @var ProcessPsbMediaOcrJob $retry */
        $retry = Queue::pushed(ProcessPsbMediaOcrJob::class)->last();
        $retry->handle($h);
        $h->resumeAfterMediaOcr($draft->fresh(), $retry->continuation);
        $this->assertSame(2, $this->tesseractRuns);
        $this->assertSame('timeout', data_get($draft->fresh()->meta, 'photo_class.ocr_status.0'));
        $this->assertSame(data_get($draft->fresh()->meta, 'photo_class.ocr_batch_completed'), $job->continuation['batch_key']);

        $this->mode = 'column';
        $this->intake($h, $draft, 'SAME_TIMEOUT');

        $this->assertSame(3, $this->tesseractRuns);
        $this->assertSame('DRAFTE CO SANTOSO', $draft->fresh()->full_name);
        $this->assertSame('ok', data_get($draft->fresh()->meta, 'photo_class.ocr_status.0'));
    }

    public function test_same_bytes_resent_after_a_successful_batch_get_no_new_ocr_and_no_extra_reply(): void
    {
        Queue::fake();
        $this->mode = 'column';
        $h = $this->harness();
        $draft = $this->draft();

        $first = $this->intake($h, $draft, 'SAME_OK');
        $this->assertSame(1, $this->tesseractRuns);
        $this->assertSame('ok', data_get($draft->fresh()->meta, 'photo_class.ocr_status.0'));
        $received = $this->replyCount($h, 'PSB diterima');
        $unreadable = $this->replyCount($h, 'KTP belum terbaca');
        $ktpPath = $draft->fresh()->ktp_photo_path;

        $second = $this->intake($h, $draft, 'SAME_OK');

        $this->assertSame($first->continuation['batch_key'], $second->continuation['batch_key']);
        $this->assertSame(1, $this->tesseractRuns, 'successful result keeps the short-circuit');
        $this->assertSame(0, $this->ktpCountingService($h)->paddleCalls);
        $this->assertSame($received, $this->replyCount($h, 'PSB diterima'));
        $this->assertSame($unreadable, $this->replyCount($h, 'KTP belum terbaca'));
        $this->assertSame($ktpPath, $draft->fresh()->ktp_photo_path);
    }

    public function test_different_bytes_after_a_successful_batch_are_still_classified(): void
    {
        Queue::fake();
        $this->mode = 'column';
        $h = $this->harness();
        $draft = $this->draft();

        $first = $this->intake($h, $draft, 'FIRST_OK');
        $this->assertSame(1, $this->tesseractRuns);

        // (A follow-up batch also carries the draft's saved KTP, so more than one run is fine.)
        $second = $this->intake($h, $draft, 'OTHER_BYTES');

        $this->assertNotSame($first->continuation['batch_key'], $second->continuation['batch_key']);
        $this->assertGreaterThan(1, $this->tesseractRuns);
    }

    public function test_completed_unread_batch_whose_files_are_gone_keeps_the_short_circuit_and_does_not_throw(): void
    {
        Queue::fake();
        $this->mode = 'empty';
        $h = $this->harness();
        $draft = $this->draft();
        $job = $this->intake($h, $draft, 'FILES_GONE');
        $runs = $this->tesseractRuns;
        $this->assertSame('no_text', data_get($draft->fresh()->meta, 'photo_class.ocr_status.0'));
        foreach ($job->stagedPaths as $path) {
            Storage::disk('local')->assertMissing($path);
        }

        // The same job runs again (worker retry) with nothing staged any more.
        $job->handle($h);

        $this->assertSame($runs, $this->tesseractRuns);
        $this->assertSame('classified', data_get($draft->fresh()->meta, 'document_gate.status'));
        $this->assertNull(data_get($draft->fresh()->meta, 'ocr_error_code'));
    }

    /** @param list<string> $statuses @param list<string> $shas */
    private function completedMeta(ProcessPsbMediaOcrJob $job, PsbDraft $draft, array $statuses, array $shas, ?int $ktpIdx, array $attachments): void
    {
        $meta = $draft->fresh()->meta;
        $meta['photo_class'] = [
            'status' => 'completed',
            'ocr_batch_completed' => $job->continuation['batch_key'],
            'ocr_status' => $statuses,
            'sha256' => $shas,
            'ktp_idx' => $ktpIdx,
        ];
        $meta['photo_attachments'] = $attachments;
        $draft->forceFill(['meta' => $meta])->save();
    }

    public function test_a_no_text_photo_beside_an_accepted_ktp_does_not_trigger_a_fresh_ocr(): void
    {
        Queue::fake();
        $this->mode = 'column';
        $h = $this->harness();
        $draft = $this->draft();
        $job = $this->stage($h, $draft, 'KTP_PLUS_HOUSE');
        $sha = $job->contexts[0]['sha256'];
        $this->completedMeta($job, $draft, ['ok', 'no_text'], [$sha, 'house-sha'], 0, [
            ['sha256' => $sha, 'classifier' => 'ktp'],
            ['sha256' => 'house-sha', 'classifier' => 'unknown'],
        ]);

        $job->handle($h);

        $this->assertSame(0, $this->tesseractRuns);
    }

    public function test_a_no_text_photo_classified_as_sn_does_not_trigger_a_fresh_ocr(): void
    {
        Queue::fake();
        $this->mode = 'column';
        $h = $this->harness();
        $draft = $this->draft();
        $job = $this->stage($h, $draft, 'SN_NO_TEXT');
        $sha = $job->contexts[0]['sha256'];
        $this->completedMeta($job, $draft, ['no_text'], [$sha], null, [
            ['sha256' => $sha, 'classifier' => 'sn'],
        ]);

        $job->handle($h);

        $this->assertSame(0, $this->tesseractRuns);
    }

    public function test_a_timeout_photo_beside_an_accepted_ktp_still_gets_a_fresh_ocr(): void
    {
        Queue::fake();
        $this->mode = 'column';
        $h = $this->harness();
        $draft = $this->draft();
        $job = $this->stage($h, $draft, 'TIMEOUT_BESIDE_KTP');
        $sha = $job->contexts[0]['sha256'];
        $this->completedMeta($job, $draft, ['timeout'], [$sha], null, [
            ['sha256' => $sha, 'classifier' => 'unknown'],
        ]);

        $job->handle($h);

        $this->assertSame(1, $this->tesseractRuns);
    }

    public function test_legacy_completed_batch_without_statuses_keeps_the_short_circuit(): void
    {
        Queue::fake();
        $this->mode = 'column';
        $h = $this->harness();
        $draft = $this->draft();
        $job = $this->stage($h, $draft, 'LEGACY');
        $key = $job->continuation['batch_key'];
        $meta = $draft->fresh()->meta;
        $meta['photo_class'] = ['status' => 'completed', 'ocr_batch_completed' => $key];
        $draft->forceFill(['meta' => $meta])->save();

        $job->handle($h);

        $this->assertSame(0, $this->tesseractRuns);
    }

    public function test_text_command_flow_does_not_touch_ocr_state(): void
    {
        // The retry machinery only exists inside processStagedMediaOcr: a draft
        // that never had a photo keeps its meta and gets no OCR fields.
        $h = $this->harness();
        $draft = $this->draft();

        $this->assertArrayNotHasKey('photo_class', $draft->fresh()->meta);
        $this->assertTrue($h->retryEnabledForTest());
        Setting::set('psb_ocr_retry_enabled', 'off', 'text', 'psb');
        Cache::flush();
        $this->assertFalse($h->retryEnabledForTest());
    }

    public function test_tesseract_empty_and_paddle_busy_is_also_a_slow_server_case(): void
    {
        Queue::fake();
        $this->mode = 'empty';
        $h = $this->harness();
        $this->ktpCountingService($h)->outcome = 'busy';
        $draft = $this->draft();
        $job = $this->stage($h, $draft);

        $job->handle($h);

        $this->assertSame('retry_pending', data_get($draft->fresh()->meta, 'photo_class.status'));
        $this->assertSame('paddle_busy', data_get($draft->fresh()->meta, 'photo_class.ocr_status.0'));
        $this->assertCount(1, $h->replies);
        $this->assertCount(2, Queue::pushed(ProcessPsbMediaOcrJob::class));
    }
}

class CountingKtpOcr extends KtpOcrService
{
    public int $paddleCalls = 0;

    /** @var list<string> local Tesseract text of every photo that reached Paddle */
    public array $paddleLocalTexts = [];

    private ?string $currentLocal = null;

    public function parseKtpFast(
        UploadedFile $file,
        ?string $localRawText = null,
        ?OcrBudget $budget = null,
        bool $localTried = true,
        bool $allowPaddle = true,
    ): array {
        $this->currentLocal = $localRawText;

        return parent::parseKtpFast($file, $localRawText, $budget, $localTried, $allowPaddle);
    }

    public ?string $paddleText = null;

    /** What the Paddle client reports when it has no text for us (ok = answered, nothing found). */
    public string $outcome = 'ok';

    /** @var list<array{image_budget:int,remaining:int,run_cap:int}> */
    public array $seen = [];

    protected function callPaddleOcr(string $bytes, string $filename): ?string
    {
        $this->paddleCalls++;
        $this->paddleLocalTexts[] = (string) $this->currentLocal;
        $this->seen[] = [
            'image_budget' => (int) config('services.ocrspace.media_image_budget_seconds'),
            'remaining' => (int) round($this->activeBudget?->remaining() ?? 0),
            'run_cap' => (int) config('services.ocrspace.tesseract_run_cap'),
        ];
        $this->paddleOutcome = $this->paddleText === null ? $this->outcome : 'ok';

        return $this->paddleText;
    }
}

class RobustHarness extends PsbMediaOcrOrchestrator
{
    /** @var list<string> */
    public array $replies = [];

    /** @var list<int> */
    public array $processed = [];

    public bool $interceptProcessDraft = false;

    public function reply(string $groupJid, string $text, array $mentionJids = []): bool
    {
        $this->replies[] = $text;

        return true;
    }

    public function processDraft(PsbDraft $draft): PsbDraft
    {
        if (! $this->interceptProcessDraft) {
            return parent::processDraft($draft);
        }
        $this->processed[] = (int) $draft->id;

        return $draft;
    }

    /** @param list<string> $images */
    public function attach(PsbDraft $draft, array $images): void
    {
        PsbCollaborators::ocrEngine($this->ktpOcr, $this->ocrReaders())->attachPhotosAndOcr($draft, $images, []);
        $draft->save();
    }

    public function retryEnabledForTest(): bool
    {
        return PsbCollaborators::ocrBatch()->ocrRetryEnabled();
    }
}

class MarkerHarness extends RobustHarness
{
    /** Fake clock (seconds). null = real clock. */
    public ?float $fakeNow = null;

    /** True = Tesseract reads nothing from a KTP photo (slow host). */
    public bool $blankKtp = false;

    /** @var list<float> */
    public array $tesseractDeadlines = [];

    /** @param list<string> $images */
    public function classifyForTest(PsbDraft $draft, array $images): ?array
    {
        return PsbCollaborators::ocrEngine($this->ktpOcr, $this->ocrReaders())->classifyBatch($draft, $images);
    }

    /** Fake Tesseract reader + fake clock for the OCR engine (two job-path tests need the clock). */
    protected function ocrReaders(): PsbOcrReaders
    {
        return new PsbOcrReaders(
            tesseractLanguage: static fn (): ?string => 'ind+eng',
            tesseract: function (string $bytes, string $language, int $runCap, array $psms, ?float $deadline): array {
                $this->tesseractDeadlines[] = (float) $deadline;
                if ($this->fakeNow !== null && $deadline !== null) {
                    if ($deadline <= $this->fakeNow) {
                        // Deadline already past: like the real runner, nothing is read and the clock stays.
                        return ['text' => '', 'runs' => [], 'timed_out' => false, 'deadline_hit' => true];
                    }
                    // A slow host: Tesseract uses every second it is allowed.
                    $this->fakeNow = $deadline;
                }

                return ['text' => $this->markerText($bytes), 'runs' => [], 'timed_out' => false, 'deadline_hit' => false];
            },
            clock: fn (): float => $this->fakeNow ?? microtime(true),
        );
    }

    private function markerText(string $bytes): string
    {
        if (str_contains($bytes, 'SN_PHOTO')) {
            return 'SN: FHTTCANARY123';
        }
        if (str_contains($bytes, 'ODP_PHOTO')) {
            return 'ODP-01 PON PORT 1 L1.2';
        }
        if ($this->blankKtp) {
            return '';
        }

        return SyntheticOcrFixtures::ktpLabelsOnlyText();
    }
}
