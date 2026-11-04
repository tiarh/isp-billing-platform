<?php

namespace Tests\Feature;

use App\Jobs\ProcessPsbAfterMediaOcrJob;
use App\Models\PsbDraft;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-psb')]
class PsbRecoverStuckOcrTest extends TestCase
{
    use RefreshDatabase;

    private function draft(string $gate, array $photoClass): PsbDraft
    {
        $draft = PsbDraft::create([
            'status' => 'received',
            'message_id' => 'msg-'.uniqid(),
            'meta' => [
                'document_gate' => ['status' => $gate, 'reason' => 'queued_ocr_media'],
                'photo_class' => $photoClass,
                'ocr_batch_key' => 'batch-abc',
            ],
        ]);
        $draft->forceFill(['updated_at' => now()->subMinutes(30)])->saveQuietly();

        return $draft;
    }

    public function test_it_requeues_continuation_for_stuck_draft(): void
    {
        Bus::fake();
        $draft = $this->draft('ocr_pending', ['queue' => 'ocr-media']);

        $this->artisan('psb:recover-stuck-ocr', ['--apply' => true])->assertSuccessful();

        Bus::assertDispatched(
            ProcessPsbAfterMediaOcrJob::class,
            fn ($job) => $job->draftId === $draft->id,
        );
    }

    public function test_dry_run_does_not_dispatch(): void
    {
        Bus::fake();
        $this->draft('ocr_pending', ['queue' => 'ocr-media']);

        $this->artisan('psb:recover-stuck-ocr')->assertSuccessful();

        Bus::assertNotDispatched(ProcessPsbAfterMediaOcrJob::class);
    }

    public function test_needs_manual_when_ocr_never_classified(): void
    {
        Bus::fake();
        $this->draft('ocr_pending', []);

        $this->artisan('psb:recover-stuck-ocr', ['--apply' => true])
            ->expectsOutputToContain('needs_manual=1')
            ->assertSuccessful();

        Bus::assertNotDispatched(ProcessPsbAfterMediaOcrJob::class);
    }

    private function draftIdleFor(int $minutes): PsbDraft
    {
        $draft = $this->draft('ocr_pending', ['queue' => 'ocr-media']);
        $draft->forceFill(['updated_at' => now()->utc()->subMinutes($minutes)->format('Y-m-d H:i:s')])->saveQuietly();

        return $draft;
    }

    /** @return array<string, array{0: string}> */
    public static function wibClocks(): array
    {
        return [
            'just after midnight WIB' => ['2026-10-14 00:30'],
            'just before 07:00 WIB' => ['2026-10-14 06:59'],
            'afternoon WIB' => ['2026-10-14 15:00'],
        ];
    }

    #[DataProvider('wibClocks')]
    public function test_only_drafts_idle_longer_than_the_threshold_are_dispatched(string $wib): void
    {
        Bus::fake();
        $this->travelTo(CarbonImmutable::parse($wib, 'Asia/Jakarta')->utc());
        $fresh = $this->draftIdleFor(1);
        $stale = $this->draftIdleFor(6);

        $this->artisan('psb:recover-stuck-ocr', ['--apply' => true])->assertSuccessful();

        Bus::assertDispatched(ProcessPsbAfterMediaOcrJob::class, fn ($job) => $job->draftId === $stale->id);
        Bus::assertNotDispatched(ProcessPsbAfterMediaOcrJob::class, fn ($job) => $job->draftId === $fresh->id);
        $this->travelBack();
    }

    #[DataProvider('wibClocks')]
    public function test_minutes_option_is_respected(string $wib): void
    {
        Bus::fake();
        $this->travelTo(CarbonImmutable::parse($wib, 'Asia/Jakarta')->utc());
        $tenMin = $this->draftIdleFor(10);
        $fortyMin = $this->draftIdleFor(40);

        $this->artisan('psb:recover-stuck-ocr', ['--apply' => true, '--minutes' => 30])->assertSuccessful();

        Bus::assertDispatched(ProcessPsbAfterMediaOcrJob::class, fn ($job) => $job->draftId === $fortyMin->id);
        Bus::assertNotDispatched(ProcessPsbAfterMediaOcrJob::class, fn ($job) => $job->draftId === $tenMin->id);
        $this->travelBack();
    }

    public function test_ignores_drafts_not_ocr_pending(): void
    {
        Bus::fake();
        $this->draft('waiting_photo', ['queue' => 'ocr-media']);

        $this->artisan('psb:recover-stuck-ocr', ['--apply' => true])->assertSuccessful();

        Bus::assertNotDispatched(ProcessPsbAfterMediaOcrJob::class);
    }
}
