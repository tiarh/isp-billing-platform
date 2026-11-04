<?php

namespace Tests\Feature;

use App\Jobs\ProcessPsbAfterMediaOcrJob;
use App\Jobs\ProcessPsbMediaOcrJob;
use App\Models\PsbDraft;
use App\Models\Setting;
use App\Services\KtpOcrService;
use App\Services\Psb\PsbOcrBatchState;
use App\Services\Psb\PsbOltBusyMarker;
use App\Services\PsbOrchestratorService;
use App\Support\PsbOcrBatchLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\MarkerKtpOcr;
use Tests\Support\MarkerOcr;
use Tests\Support\OcrTerminalHarness;
use Tests\TestCase;

/**
 * WA album: the photos of one album can end up in separate OCR batches. The
 * "KTP missing" decision must wait while another batch of the same draft is
 * still open. Real orchestrator and jobs; only OCR engines and WA sending are
 * faked (OcrTerminalHarness).
 */
#[Group('blocking-psb')]
class PsbAlbumKtpGateTest extends TestCase
{
    use RefreshDatabase;

    private const JPEG = '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAEf/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABBQJ//9oACAEBAAEFAU//2gAMAwEAAgADAAAAEP/EABQRAQAAAAAAAAAAAAAAAAAAABD/2gAIAQMBAT8QH//EABQRAQAAAAAAAAAAAAAAAAAAABD/2gAIAQIBAT8QH//EABQQAQAAAAAAAAAAAAAAAAAAABD/2gAIAQEABj8QH//EABQQAQAAAAAAAAAAAAAAAAAAABD/2gAIAQEAAT8QH//Z';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
        config([
            'filesystems.default' => 'local',
            'services.ocrspace.async_media_enabled' => true,
        ]);
        Setting::set('psb_document_gate_enabled', '1', 'boolean', 'psb');
        Setting::set('psb_document_gate_enforced_after', now()->subDay()->toIso8601String(), 'text', 'psb');
    }

    protected function tearDown(): void
    {
        MarkerOcr::reset();
        parent::tearDown();
    }

    private function jpeg(string $marker): string
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
    private function draft(array $meta = ['waiting_photo' => true], array $attrs = []): PsbDraft
    {
        return PsbDraft::create(array_merge([
            'status' => PsbDraft::STATUS_RECEIVED,
            'message_id' => 'msg-'.uniqid(),
            'group_jid' => 'ops@g.us',
            'full_name' => 'ALBUM TEST',
            'odp' => 'ODP-TEST-01',
            'rt' => '01',
            'rw' => '02',
            'tikor' => '-7.9,112.6',
            'daerah' => 'RA',
            'sn' => 'FHTTTEST1234',
            'router_id' => 513,
            'olt_id' => 1,
            'package_id' => 452,
            'last_error' => 'Menunggu foto',
            'meta' => $meta,
        ], $attrs));
    }

    private function stage(OcrTerminalHarness $h, PsbDraft $draft, string $marker, string $messageId): void
    {
        $h->attachFollowUpImages($draft->fresh(), [$this->jpeg($marker)], [
            'source' => 'wa_batch_followup',
            'message_id' => $messageId,
            'actor' => 'tech@s.whatsapp.net',
        ]);
    }

    /** Runs the OCR job that was queued as number $index (0 = first staged batch). */
    private function runOcr(OcrTerminalHarness $h, int $index): ProcessPsbMediaOcrJob
    {
        /** @var ProcessPsbMediaOcrJob $job */
        $job = Queue::pushed(ProcessPsbMediaOcrJob::class)->values()[$index];
        $job->handle($h);

        return $job;
    }

    private function afterJob(ProcessPsbMediaOcrJob $ocr, int $hop = 0): ?ProcessPsbAfterMediaOcrJob
    {
        $key = $ocr->continuation['batch_key'];

        return Queue::pushed(ProcessPsbAfterMediaOcrJob::class)->first(
            fn (ProcessPsbAfterMediaOcrJob $j): bool => $j->continuation['batch_key'] === $key
                && (int) ($j->continuation['album_wait_n'] ?? 0) === $hop
        );
    }

    private function replyCount(OcrTerminalHarness $h, string $needle): int
    {
        return count(array_filter($h->replies, fn (string $text): bool => str_contains($text, $needle)));
    }

    private function waitJobs(): int
    {
        return Queue::pushed(ProcessPsbAfterMediaOcrJob::class)
            ->filter(fn (ProcessPsbAfterMediaOcrJob $j): bool => (int) ($j->continuation['album_wait_n'] ?? 0) > 0)
            ->count();
    }

    public function test_sn_batch_first_then_ktp_batch_does_not_say_ktp_missing(): void
    {
        $h = $this->harness();
        $draft = $this->draft();
        $this->stage($h, $draft, 'SN_MARKER_A', 'm-sn');
        $this->stage($h, $draft, 'KTP_MARKER', 'm-ktp');

        $snOcr = $this->runOcr($h, 0);
        $this->afterJob($snOcr)->handle($h);

        $this->assertSame(0, $this->replyCount($h, 'Foto KTP belum ada'));
        $this->assertSame(1, $this->waitJobs());
        $waitJob = $this->afterJob($snOcr, 1);
        $this->assertNotNull($waitJob);
        $this->assertSame(1, $waitJob->continuation['album_wait_n']);
        $this->assertStringEndsWith(':aw1', $waitJob->uniqueId());

        $ktpOcr = $this->runOcr($h, 1);
        $this->afterJob($ktpOcr)->handle($h);
        $waitJob->handle($h);

        $this->assertSame(1, $this->replyCount($h, 'PSB diterima'));
        $this->assertSame(0, $this->replyCount($h, 'Foto KTP belum ada'));
        $this->assertSame(0, $this->replyCount($h, 'KTP belum terbaca'));
        $this->assertSame([$draft->id], $h->processed);
    }

    public function test_ktp_batch_first_then_sn_batch_is_received_once_without_waiting(): void
    {
        $h = $this->harness();
        $draft = $this->draft();
        $this->stage($h, $draft, 'KTP_MARKER', 'm-ktp');
        $this->stage($h, $draft, 'SN_MARKER_A', 'm-sn');

        $ktpOcr = $this->runOcr($h, 0);
        $this->afterJob($ktpOcr)->handle($h);
        $snOcr = $this->runOcr($h, 1);
        $this->afterJob($snOcr)->handle($h);

        $this->assertSame(0, $this->waitJobs());
        $this->assertSame(1, $this->replyCount($h, 'PSB diterima'));
        $this->assertSame(0, $this->replyCount($h, 'Foto KTP belum ada'));
        $this->assertSame([$draft->id], $h->processed);
    }

    public function test_no_ktp_in_any_batch_sends_exactly_one_missing_reply(): void
    {
        $h = $this->harness();
        $draft = $this->draft();
        $this->stage($h, $draft, 'SN_MARKER_A', 'm-1');
        $this->stage($h, $draft, 'SN_MARKER_B', 'm-2');

        $first = $this->runOcr($h, 0);
        $this->afterJob($first)->handle($h);
        $this->assertSame(0, $this->replyCount($h, 'Foto KTP belum ada'));

        $second = $this->runOcr($h, 1);
        $this->afterJob($second)->handle($h);
        $this->assertSame(1, $this->replyCount($h, 'Foto KTP belum ada'));
        $this->assertSame(0, $this->replyCount($h, 'Dokumen PSB kurang'));
        $this->assertStringNotContainsString('ktp_missing', implode("\n", $h->replies));

        // The waiting batch re-runs and finds that the other batch already decided.
        $this->afterJob($first, 1)->handle($h);
        $this->assertSame(1, $this->replyCount($h, 'Foto KTP belum ada'));
        $this->assertSame([], $h->processed);
    }

    public function test_unreadable_card_in_sibling_batch_gets_exactly_one_unreadable_reply(): void
    {
        $h = $this->harness();
        $draft = $this->draft();
        $this->stage($h, $draft, 'SN_MARKER_A', 'm-sn');
        $this->stage($h, $draft, 'UNREADABLE_CARD', 'm-card');

        $first = $this->runOcr($h, 0);
        $this->afterJob($first)->handle($h);
        $second = $this->runOcr($h, 1);
        $this->afterJob($second)->handle($h);
        $this->afterJob($first, 1)->handle($h);

        $this->assertSame(1, $this->replyCount($h, 'KTP belum terbaca'));
        $this->assertSame(0, $this->replyCount($h, 'Foto KTP belum ada'));
    }

    public function test_sibling_that_never_closes_is_waited_for_until_the_limit_then_one_missing_reply(): void
    {
        $h = $this->harness();
        $draft = $this->draft();
        $this->stage($h, $draft, 'SN_MARKER_A', 'm-sn');
        $this->stage($h, $draft, 'SN_MARKER_B', 'm-never');

        $first = $this->runOcr($h, 0);
        $this->afterJob($first)->handle($h);

        $hop = 1;
        while (($job = $this->afterJob($first, $hop)) !== null) {
            $this->assertLessThanOrEqual(7, $hop);
            $this->assertSame(0, $this->replyCount($h, 'Foto KTP belum ada'));
            $this->travelTo(now()->addSeconds(15));
            $job->handle($h);
            $hop++;
        }

        $this->assertLessThanOrEqual(7, $hop);
        $this->assertSame(1, $this->replyCount($h, 'Foto KTP belum ada'));
        $this->assertSame([], $h->processed);
    }

    public function test_retry_pending_sibling_counts_as_open_and_its_ktp_is_used(): void
    {
        $h = $this->harness();
        $draft = $this->draft();
        $this->stage($h, $draft, 'SN_MARKER_A', 'm-sn');
        $this->stage($h, $draft, 'KTP_MARKER', 'm-ktp');
        $second = Queue::pushed(ProcessPsbMediaOcrJob::class)->values()[1];
        PsbOcrBatchLedger::markRetry((int) $draft->id, $second->continuation['batch_key']);

        $first = $this->runOcr($h, 0);
        $this->afterJob($first)->handle($h);
        $this->travelTo(now()->addSeconds(30));
        $this->afterJob($first, 1)->handle($h);

        $this->assertNotNull($this->afterJob($first, 2));
        $this->assertSame(0, $this->replyCount($h, 'Foto KTP belum ada'));

        // The retry finally reads the KTP.
        $this->runOcr($h, 1);
        $this->afterJob($first, 2)->handle($h);

        $this->assertSame(1, $this->replyCount($h, 'PSB diterima'));
        $this->assertSame([$draft->id], $h->processed);
    }

    /**
     * Production order: the captioned photo is an INBOUND batch (draft has no waiting_photo),
     * the uncaptioned sibling is a follow-up batch.
     *
     * @return array{0: OcrTerminalHarness, 1: PsbDraft, 2: bool}
     */
    private function inboundAlbum(string $second, bool $waiterRunsBeforeSiblingContinuation = false): array
    {
        $h = $this->harness();
        $draft = $this->draft([]);
        $h->stageInbound($draft->fresh(), $this->jpeg('SN_MARKER_A'));
        $this->stage($h, $draft, $second, 'm-second');
        $h->replies = [];

        $inbound = $this->runOcr($h, 0);
        $this->afterJob($inbound)->handle($h);
        $this->assertSame([], $h->replies);
        $waited = $this->afterJob($inbound, 1);
        $this->assertNotNull($waited);
        $this->assertStringEndsWith(':aw1', $waited->uniqueId());

        $sibling = $this->runOcr($h, 1);
        if ($waiterRunsBeforeSiblingContinuation) {
            $waited->handle($h);
            $this->afterJob($sibling)->handle($h);
        } else {
            $this->afterJob($sibling)->handle($h);
            $waited->handle($h);
        }

        return [$h, $draft, true];
    }

    public function test_inbound_sn_then_followup_ktp_waits_and_receives_once(): void
    {
        [$h, $draft] = $this->inboundAlbum('KTP_MARKER');

        $this->assertSame(1, $this->replyCount($h, 'PSB diterima'));
        $this->assertSame(0, $this->replyCount($h, 'Foto KTP belum ada'));
        $this->assertSame([$draft->id], $h->processed);
    }

    public function test_inbound_ktp_race_waiter_runs_before_sibling_continuation(): void
    {
        [$h, $draft] = $this->inboundAlbum('KTP_MARKER', true);

        $this->assertSame(1, $this->replyCount($h, 'PSB diterima'));
        $this->assertSame([$draft->id], $h->processed);
    }

    public function test_inbound_without_any_ktp_sends_exactly_one_missing_reply(): void
    {
        [$h, $draft] = $this->inboundAlbum('SN_MARKER_B');

        $this->assertSame(1, $this->replyCount($h, 'Foto KTP belum ada'));
        $this->assertSame([], $h->processed);
    }

    public function test_inbound_without_ktp_race_gives_one_reply_when_waiter_decides_first(): void
    {
        [$h] = $this->inboundAlbum('SN_MARKER_B', true);

        $this->assertSame(1, $this->replyCount($h, 'Foto KTP belum ada'));
    }

    public function test_carried_parked_retry_batch_stops_counting_as_open(): void
    {
        $h = $this->harness();
        $parkedPath = 'psb/ocr-staging/'.sha1('parked').'.jpg';
        Storage::disk('local')->put($parkedPath, $this->jpeg('PARKED'));
        $draft = $this->draft([
            'waiting_photo' => true,
            'ocr_batch_key' => 'KPARKED',
            'ocr_batch_paths' => [$parkedPath],
            'photo_class' => ['status' => 'retry_pending'],
        ]);
        PsbOcrBatchLedger::markRetry((int) $draft->id, 'KPARKED');
        $this->assertSame(['KPARKED'], PsbOcrBatchLedger::openSiblings((int) $draft->id, 'NEW'));

        $this->stage($h, $draft, 'UNREADABLE_NEW', 'm-new');

        $newKey = Queue::pushed(ProcessPsbMediaOcrJob::class)->first()->continuation['batch_key'];
        $this->assertNotSame('KPARKED', $newKey);
        $this->assertSame([], PsbOcrBatchLedger::openSiblings((int) $draft->id, $newKey));
    }

    public function test_follow_up_blocked_reply_is_skipped_when_another_batch_already_answered(): void
    {
        $h = $this->harness();
        $meta = $this->unreadableMeta();
        $meta['photo_class']['unknown_count'] = 0;
        $meta['ocr_terminal_notified'] = 'OTHERBATCH';
        $draft = $this->draft($meta);

        $h->resumeAfterMediaOcr($draft->fresh(), ['mode' => 'follow_up', 'batch_key' => 'K1']);

        $this->assertSame([], $h->replies);
    }

    public function test_setting_off_words_turn_the_wait_off(): void
    {
        foreach (['off', 'false', 'no'] as $value) {
            Setting::set('psb_album_wait_seconds', $value, 'text', 'psb');
            $h = $this->harness();
            $draft = $this->draft($this->unreadableMeta());
            PsbOcrBatchLedger::open((int) $draft->id, 'OTHER');

            $h->resumeAfterMediaOcr($draft->fresh(), ['mode' => 'follow_up', 'batch_key' => 'K1']);

            $this->assertSame(1, $this->replyCount($h, 'KTP belum terbaca'), "value '{$value}'");
        }
    }

    public function test_empty_setting_means_default_wait_like_missing(): void
    {
        $waitSeconds = fn (): int => (new PsbOcrBatchState)->albumWaitSeconds();

        $this->assertSame(90, $waitSeconds(), 'missing');
        foreach (['', '   '] as $value) {
            Setting::set('psb_album_wait_seconds', $value, 'text', 'psb');
            $this->assertSame(90, $waitSeconds(), 'value '.var_export($value, true));
        }
        foreach (['0' => 0, 'off' => 0, '45' => 45, '999' => 180, '-5' => 0] as $value => $expected) {
            Setting::set('psb_album_wait_seconds', (string) $value, 'text', 'psb');
            $this->assertSame($expected, $waitSeconds(), "value '{$value}'");
        }

        $h = $this->harness();
        Setting::set('psb_album_wait_seconds', '', 'text', 'psb');
        $draft = $this->draft($this->unreadableMeta());
        PsbOcrBatchLedger::open((int) $draft->id, 'OTHER');

        $job = new ProcessPsbAfterMediaOcrJob((int) $draft->id, ['mode' => 'follow_up', 'batch_key' => 'K1']);
        $job->handle($h);

        $this->assertSame([], $h->replies);
        $this->assertSame(1, $this->waitJobs());
    }

    public function test_late_ktp_on_finished_draft_keeps_status_and_note(): void
    {
        foreach (PsbDraft::PHOTO_LOCKED_STATUSES as $i => $status) {
            // Both a clean draft and one that still carries an old waiting_photo flag.
            foreach ([[], ['waiting_photo' => true]] as $j => $meta) {
                $h = $this->harness();
                $draft = $this->draft($meta, [
                    'status' => $status,
                    'last_error' => null,
                    'message_id' => "msg-late-{$status}-{$j}",
                ]);

                $this->stage($h, $draft, 'KTP_MARKER', "late-{$status}-{$j}");
                $this->assertSame($status, $draft->fresh()->status, "after staging {$status}");
                $ocr = $this->runOcr($h, $i * 2 + $j);
                $this->afterJob($ocr)?->handle($h);

                $fresh = $draft->fresh();
                $this->assertSame($status, $fresh->status, "status {$status} #{$j}");
                $this->assertNull($fresh->last_error, "last_error {$status} #{$j}");
                $this->assertArrayNotHasKey('waiting_photo', (array) $fresh->meta, "flag {$status} #{$j}");
                $this->assertSame([], $h->processed, "processDraft {$status} #{$j}");
                $this->assertSame(0, $this->replyCount($h, 'PSB diterima'), "received reply {$status} #{$j}");
                $this->assertSame(0, $this->replyCount($h, 'Foto KTP belum ada'), "status {$status}");
                $this->assertSame(0, $this->replyCount($h, 'KTP belum terbaca'), "status {$status}");
            }
        }
    }

    public function test_late_ktp_keeps_olt_queue_note_and_resume_still_acts(): void
    {
        $h = $this->harness();
        $draft = $this->draft(['waiting_photo' => true], [
            'status' => PsbDraft::STATUS_SECRET_OK,
            'last_error' => 'OLT antri: menunggu giliran OLT (otomatis, percobaan 1/5)',
        ]);

        $this->stage($h, $draft, 'KTP_MARKER', 'late-olt-queue');
        $this->afterJob($this->runOcr($h, 0))?->handle($h);

        $fresh = $draft->fresh();
        $this->assertSame(PsbDraft::STATUS_SECRET_OK, $fresh->status);
        $this->assertStringStartsWith('OLT antri:', (string) $fresh->last_error);
        $this->assertTrue((new PsbOltBusyMarker)->oltBusyResumable($fresh));
        $this->assertSame([], $h->processed);
    }

    public function test_late_ktp_keeps_correction_pending_so_it_can_still_be_applied(): void
    {
        $h = $this->harness();
        $draft = $this->draft([], [
            'status' => PsbDraft::STATUS_CORRECTION_PENDING,
            'last_error' => 'Koreksi provisioning menunggu approval; perangkat/customer belum diubah.',
        ]);

        $this->stage($h, $draft, 'KTP_MARKER', 'late-correction');
        $this->afterJob($this->runOcr($h, 0))?->handle($h);

        $fresh = $draft->fresh();
        $this->assertSame(PsbDraft::STATUS_CORRECTION_PENDING, $fresh->status);
        $this->assertStringStartsWith('Koreksi provisioning', (string) $fresh->last_error);
        // applyPsbCorrection's first guard is status === correction_pending; it must pass the guard
        // (it then stops at the correction write switch, not at "tidak sedang menunggu koreksi").
        try {
            $h->applyPsbCorrection($fresh);
            $this->fail('expected the write switch to stop the correction');
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString('tidak sedang menunggu koreksi', $e->getMessage());
        }
    }

    public function test_ocr_worker_error_does_not_write_ocr_pending_on_a_finished_draft(): void
    {
        $h = $this->harness();
        $draft = $this->draft([], ['status' => PsbDraft::STATUS_OLT_OK, 'last_error' => null]);
        $this->stage($h, $draft, 'KTP_MARKER', 'late-ocr-down');
        $h->failOcr = true;

        try {
            $this->runOcr($h, 0);
            $this->fail('the OCR error should be rethrown');
        } catch (\RuntimeException $e) {
            $this->assertSame('ocr worker down', $e->getMessage());
        }

        $fresh = $draft->fresh();
        $this->assertSame(PsbDraft::STATUS_OLT_OK, $fresh->status);
        $this->assertNull($fresh->last_error);
        $this->assertNotSame('ocr_worker_error', data_get($fresh->meta, 'document_gate.reason'));
        $this->assertSame('RuntimeException', data_get($fresh->meta, 'ocr_error_code'));
    }

    public function test_late_ktp_on_unfinished_draft_still_resets_to_received(): void
    {
        $h = $this->harness();
        $draft = $this->draft(['waiting_photo' => true], ['status' => PsbDraft::STATUS_FAILED, 'last_error' => 'Field kurang: x']);

        $this->stage($h, $draft, 'late-failed', 'late-failed');

        $fresh = $draft->fresh();
        $this->assertSame(PsbDraft::STATUS_RECEIVED, $fresh->status);
        $this->assertSame('Foto diterima, validasi KTP/SN sedang diproses.', $fresh->last_error);
    }

    public function test_recovery_continuation_never_waits(): void
    {
        $h = $this->harness();
        $draft = $this->draft($this->unreadableMeta());
        PsbOcrBatchLedger::open((int) $draft->id, 'OTHER');

        $h->resumeAfterMediaOcr($draft->fresh(), ['mode' => 'recovery', 'batch_key' => 'K1']);

        $this->assertSame(1, $this->replyCount($h, 'KTP belum terbaca'));
    }

    public function test_setting_zero_turns_the_wait_off(): void
    {
        Setting::set('psb_album_wait_seconds', '0', 'text', 'psb');
        $h = $this->harness();
        $draft = $this->draft($this->unreadableMeta());
        PsbOcrBatchLedger::open((int) $draft->id, 'OTHER');

        $h->resumeAfterMediaOcr($draft->fresh(), ['mode' => 'follow_up', 'batch_key' => 'K1']);

        $this->assertSame(1, $this->replyCount($h, 'KTP belum terbaca'));
    }

    public function test_follow_up_continuation_waits_when_other_batch_is_open(): void
    {
        $h = $this->harness();
        $draft = $this->draft($this->unreadableMeta());
        PsbOcrBatchLedger::open((int) $draft->id, 'OTHER');

        $job = new ProcessPsbAfterMediaOcrJob((int) $draft->id, ['mode' => 'follow_up', 'batch_key' => 'K1']);
        $job->handle($h);

        $this->assertSame([], $h->replies);
        $this->assertSame(1, $this->waitJobs());
    }

    public function test_csr_and_pending_followup_drafts_do_not_wait(): void
    {
        $h = $this->harness();
        $csrMeta = $this->unreadableMeta();
        $csrMeta['document_policy'] = ['type' => 'exempt_csr', 'reason' => 'Sekolah'];
        $csr = $this->draft($csrMeta, ['full_name' => 'SEKOLAH CSR TEST']);
        PsbOcrBatchLedger::open((int) $csr->id, 'OTHER');
        $h->resumeAfterMediaOcr($csr->fresh(), ['mode' => 'follow_up', 'batch_key' => 'K1']);
        $this->assertSame([$csr->id], $h->processed);

        $pendingMeta = $this->unreadableMeta();
        $pendingMeta['document_policy'] = ['type' => 'pending_followup', 'reason' => ''];
        $pendingMeta['photo_class']['unknown_count'] = 0;
        $pending = $this->draft($pendingMeta);
        PsbOcrBatchLedger::open((int) $pending->id, 'OTHER');
        $job = new ProcessPsbAfterMediaOcrJob((int) $pending->id, ['mode' => 'follow_up', 'batch_key' => 'K2']);
        $job->handle($h);

        $this->assertSame(0, $this->waitJobs());
        $this->assertSame(1, $this->replyCount($h, 'KTP menyusul belum lengkap'));
    }

    public function test_async_off_writes_nothing_to_the_ledger(): void
    {
        config(['services.ocrspace.async_media_enabled' => false]);
        $h = $this->harness();
        $draft = $this->draft();

        $this->stage($h, $draft, 'SN_MARKER_A', 'm-sn');

        $this->assertNull(Cache::get('psb:ocr-ledger:'.$draft->id));
        $this->assertSame(0, Queue::pushed(ProcessPsbMediaOcrJob::class)->count());
    }

    public function test_waiting_continuation_is_silent_when_the_draft_status_changed(): void
    {
        $h = $this->harness();
        $draft = $this->draft($this->unreadableMeta(), ['status' => PsbDraft::STATUS_OLT_OK]);

        $job = new ProcessPsbAfterMediaOcrJob((int) $draft->id, [
            'mode' => 'follow_up',
            'batch_key' => 'K1',
            'album_wait_since' => now()->getTimestamp(),
            'album_wait_n' => 1,
            'album_wait_status' => PsbDraft::STATUS_RECEIVED,
        ]);
        $job->handle($h);

        $this->assertSame([], $h->replies);
        $this->assertSame(0, $this->waitJobs());
    }

    public function test_ledger_failure_falls_back_to_the_old_immediate_reply(): void
    {
        $h = $this->harness();
        $draft = $this->draft($this->unreadableMeta());
        // Someone else holds the ledger lock: the write fails open (no sibling known).
        $held = Cache::lock('psb:ocr-ledger-lock:'.$draft->id, 60);
        $this->assertTrue($held->get());
        PsbOcrBatchLedger::open((int) $draft->id, 'OTHER');
        $held->release();

        $h->resumeAfterMediaOcr($draft->fresh(), ['mode' => 'follow_up', 'batch_key' => 'K1']);

        $this->assertSame(1, $this->replyCount($h, 'KTP belum terbaca'));
    }

    /** @return array<string,mixed> */
    private function unreadableMeta(): array
    {
        return [
            'waiting_photo' => true,
            'document_gate' => ['status' => 'classified'],
            'photo_class' => ['images' => 1, 'status' => 'completed', 'ocr_batch_completed' => 'K1', 'unknown_count' => 1, 'ktp_score' => null],
        ];
    }
}
