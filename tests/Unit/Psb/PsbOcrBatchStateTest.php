<?php

namespace Tests\Unit\Psb;

use App\Models\PsbDraft;
use App\Services\Psb\PsbOcrBatchState;
use App\Support\PsbOcrBatchLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-psb')]
class PsbOcrBatchStateTest extends TestCase
{
    use RefreshDatabase;

    private function state(): PsbOcrBatchState
    {
        return new PsbOcrBatchState;
    }

    private function draft(string $status = PsbDraft::STATUS_WAITING_SN, array $meta = []): PsbDraft
    {
        return PsbDraft::create([
            'status' => $status,
            'group_jid' => 'ops@g.us',
            'sender_jid' => '6281200000011@s.whatsapp.net',
            'raw_text' => '#psb',
            'full_name' => 'BUDI CONTOH',
            'meta' => $meta,
        ]);
    }

    public function test_staging_is_deterministic_dedupes_by_content_and_skips_tiny_files(): void
    {
        Storage::fake(config('filesystems.default'));
        $a = str_repeat('a', 200);
        $b = str_repeat('b', 200);

        $one = $this->state()->stageMediaForOcr([$a, $a, 'tiny', $b], [['source' => 'x'], ['source' => 'y'], [], ['source' => 'z']]);
        $two = $this->state()->stageMediaForOcr([$a, $b]);

        $this->assertCount(2, $one['paths']);
        $this->assertSame($one['paths'], $two['paths']);
        $this->assertSame($one['batch_key'], $two['batch_key']);
        $this->assertSame(sha1(implode('|', $one['paths'])), $one['batch_key']);
        $this->assertSame(hash('sha256', $a), $one['contexts'][0]['sha256']);
        $this->assertSame('x', $one['contexts'][0]['source']);
        Storage::disk(config('filesystems.default'))->assertExists($one['paths'][0]);
    }

    public function test_parked_retry_files_are_carried_into_the_new_batch(): void
    {
        Storage::fake(config('filesystems.default'));
        $disk = Storage::disk(config('filesystems.default'));
        $parked = str_repeat('p', 200);
        $fresh = str_repeat('f', 200);
        $parkedPath = 'psb/2026/10/ocr-pending/'.hash('sha256', $parked).'.bin';
        $disk->put($parkedPath, $parked);

        $draft = $this->draft(PsbDraft::STATUS_RECEIVED, [
            'ocr_batch_key' => 'old-key',
            'ocr_batch_paths' => [$parkedPath, 'psb/missing.bin'],
            'photo_class' => ['status' => 'retry_pending'],
        ]);

        $staged = $this->state()->stageMediaForOcr([$fresh]);
        $merged = $this->state()->mergeParkedRetryFiles($draft, $staged);

        $this->assertCount(2, $merged['paths']);
        $this->assertSame($parkedPath, $merged['paths'][1]);
        $this->assertTrue($merged['contexts'][1]['sha256'] === hash('sha256', $parked));
        $this->assertSame('parked_retry', $merged['contexts'][1]['source']);
        $this->assertSame(sha1(implode('|', $merged['paths'])), $merged['batch_key']);
        $this->assertNotSame($staged['batch_key'], $merged['batch_key']);

        // Nothing parked (status not retry_pending): the batch is returned untouched.
        $other = $this->draft(PsbDraft::STATUS_RECEIVED, ['ocr_batch_paths' => [$parkedPath]]);
        $this->assertSame($staged, $this->state()->mergeParkedRetryFiles($other, $staged));
    }

    public function test_mark_pending_resets_terminal_marker_and_respects_locked_statuses(): void
    {
        $draft = $this->draft(PsbDraft::STATUS_WAITING_SN, ['ocr_terminal_notified' => 'k0', 'waiting_photo' => true]);
        $this->state()->markMediaOcrPending($draft, 'k1', ['p1.bin'], [['sha256' => 's1']]);

        $this->assertSame('k1', $draft->meta['ocr_batch_key']);
        $this->assertSame(['p1.bin'], $draft->meta['ocr_batch_paths']);
        $this->assertSame(['s1' => ['sha256' => 's1']], $draft->meta['ocr_batch_contexts']);
        $this->assertArrayNotHasKey('ocr_terminal_notified', $draft->meta);
        $this->assertSame('pending', $draft->meta['photo_class']['status']);
        $this->assertSame(PsbDraft::STATUS_RECEIVED, $draft->status);

        $locked = $this->draft(PsbDraft::STATUS_OLT_OK, ['waiting_photo' => true]);
        $this->state()->markMediaOcrPending($locked, 'k2');
        $this->assertSame(PsbDraft::STATUS_OLT_OK, $locked->status);
        $this->assertArrayNotHasKey('waiting_photo', $locked->meta);
    }

    public function test_completed_batch_needs_fresh_ocr_only_for_unread_ktp_candidates(): void
    {
        Storage::fake(config('filesystems.default'));
        $disk = Storage::disk(config('filesystems.default'));
        $disk->put('left.bin', 'x');
        $meta = fn (array $statuses, array $extra = []): array => array_merge([
            'photo_class' => ['ocr_status' => $statuses, 'sha256' => ['s0']],
            'photo_attachments' => [['sha256' => 's0', 'classifier' => 'ktp']],
        ], $extra);

        $this->assertFalse($this->state()->completedBatchNeedsFreshOcr([], ['left.bin']), 'no recorded statuses');
        $this->assertFalse($this->state()->completedBatchNeedsFreshOcr($meta(['ok']), ['left.bin']), 'all ok');
        $this->assertFalse($this->state()->completedBatchNeedsFreshOcr($meta(['timeout']), ['gone.bin']), 'no file left');
        $this->assertTrue($this->state()->completedBatchNeedsFreshOcr($meta(['timeout']), ['left.bin']));
        $this->assertTrue($this->state()->completedBatchNeedsFreshOcr($meta(['no_text']), ['left.bin']));

        $accepted = $meta(['no_text']);
        $accepted['photo_class']['ktp_idx'] = 0;
        $this->assertFalse($this->state()->completedBatchNeedsFreshOcr($accepted, ['left.bin']), 'no_text after KTP accepted');

        $sn = $meta(['timeout']);
        $sn['photo_attachments'][0]['classifier'] = 'sn';
        $this->assertFalse($this->state()->completedBatchNeedsFreshOcr($sn, ['left.bin']), 'SN photo never counts');
    }

    public function test_completed_terminal_and_retry_meta_shapes(): void
    {
        $draft = $this->draft(PsbDraft::STATUS_RECEIVED, ['ocr_batch_key' => 'k', 'ocr_batch_paths' => ['p'], 'ocr_batch_contexts' => ['s' => []]]);
        $this->state()->markMediaOcrCompleted($draft, 'k');
        $fresh = PsbDraft::find($draft->id);
        $this->assertSame('k', $fresh->meta['photo_class']['ocr_batch_completed']);
        $this->assertSame('completed', $fresh->meta['photo_class']['status']);
        $this->assertSame('classified', $fresh->meta['document_gate']['status']);
        $this->assertArrayNotHasKey('ocr_batch_key', $fresh->meta);

        $this->state()->markTerminalMeta($fresh, 'k', 'test');
        $fresh = PsbDraft::find($draft->id);
        $this->assertSame('failed', $fresh->meta['photo_class']['status']);
        $this->assertTrue($fresh->meta['waiting_photo']);
        $this->assertSame('KTP belum terbaca. Kirim ulang: #psb ktp #'.$fresh->id, $fresh->last_error);

        $retry = $this->draft(PsbDraft::STATUS_RECEIVED);
        $this->state()->markOcrRetryPending($retry, 'rk', ['degraded_reason' => 'slow', 'statuses' => ['timeout']]);
        $retry = PsbDraft::find($retry->id);
        $this->assertSame('retry_pending', $retry->meta['photo_class']['status']);
        $this->assertSame('rk', $retry->meta['ocr_batch_key']);
        $this->assertSame('ocr_retry_scheduled', $retry->meta['document_gate']['reason']);
        $this->assertContains('rk', PsbOcrBatchLedger::openSiblings((int) $retry->id, 'other'));
    }

    public function test_cleanup_deletes_only_files_outside_the_current_batch(): void
    {
        Storage::fake(config('filesystems.default'));
        $disk = Storage::disk(config('filesystems.default'));
        $disk->put('keep.bin', 'x');
        $disk->put('old.bin', 'x');

        $draft = $this->draft(PsbDraft::STATUS_RECEIVED);
        $this->state()->cleanupSupersededRetryFiles($draft, ['old.bin']);
        $disk->assertExists('old.bin');

        $draft->update(['meta' => ['ocr_batch_key' => 'cur', 'ocr_batch_paths' => ['keep.bin']]]);
        $this->state()->cleanupSupersededRetryFiles($draft, ['old.bin', 'keep.bin']);
        $disk->assertMissing('old.bin');
        $disk->assertExists('keep.bin');
    }

    public function test_settings_unreadable_and_other_batch_answered(): void
    {
        $this->assertTrue($this->state()->ocrRetryEnabled());
        $this->assertSame(90, $this->state()->albumWaitSeconds());

        $draft = $this->draft(PsbDraft::STATUS_RECEIVED, [
            'photo_class' => ['status' => 'completed', 'unknown_count' => 1],
            'ocr_terminal_notified' => 'a',
        ]);
        $this->assertTrue($this->state()->isKtpUnreadable($draft));
        $draft->ktp_photo_path = 'psb/ktp.jpg';
        $this->assertFalse($this->state()->isKtpUnreadable($draft));

        $this->assertTrue($this->state()->terminalNotifiedForOtherBatch($draft, 'b'));
        $this->assertFalse($this->state()->terminalNotifiedForOtherBatch($draft, 'a'));
    }
}
