<?php

namespace Tests\Feature;

use App\Models\PsbDraft;
use App\Models\Setting;
use App\Services\EvolutionApiService;
use App\Services\PsbOrchestratorService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * The PSB "today" recap (/psb and psb:hourly-report) must count the WIB day
 * (Asia/Jakarta) even though created_at is stored in UTC.
 */
#[Group('blocking-psb')]
class PsbTodayRecapWibWindowTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{0:string,1:string}> */
    private array $sent = [];

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function wib(string $dateTime): CarbonImmutable
    {
        return CarbonImmutable::parse($dateTime, 'Asia/Jakarta');
    }

    /** Seed a draft whose created_at is the given WIB instant, stored as UTC. */
    private function seedDraft(string $wibDateTime): PsbDraft
    {
        $draft = PsbDraft::create([
            'status' => 'received',
            'message_id' => 'msg-'.uniqid(),
        ]);
        $draft->forceFill(['created_at' => $this->wib($wibDateTime)->utc()->format('Y-m-d H:i:s')])->saveQuietly();

        return $draft;
    }

    private function total(): int
    {
        $report = app(PsbOrchestratorService::class)->formatTodayReport();
        $this->assertSame(1, preg_match('/Total: \*(\d+)\*/', $report, $m), $report);

        return (int) $m[1];
    }

    private function fakeSender(): void
    {
        $fake = \Mockery::mock(EvolutionApiService::class);
        $fake->shouldReceive('forChannel')->andReturnSelf();
        $fake->shouldReceive('sendToGroup')->andReturnUsing(function (string $jid, string $text) {
            $this->sent[] = [$jid, $text];

            return ['success' => true];
        });
        $this->app->instance(EvolutionApiService::class, $fake);
    }

    public function test_today_total_follows_the_wib_day_around_midnight_and_0700(): void
    {
        $this->travelTo($this->wib('2026-10-13 23:55')->utc());
        $this->seedDraft('2026-10-13 23:50');

        $this->travelTo($this->wib('2026-10-14 00:30')->utc());
        $this->seedDraft('2026-10-14 00:10');
        $this->assertSame(1, $this->total(), 'D-1 23:50 WIB must not count on D');

        $this->travelTo($this->wib('2026-10-14 06:59')->utc());
        $this->seedDraft('2026-10-14 06:50');
        $this->assertSame(2, $this->total());

        $this->travelTo($this->wib('2026-10-14 07:15')->utc());
        $this->seedDraft('2026-10-14 07:10');
        $this->assertSame(3, $this->total());

        $this->travelTo($this->wib('2026-10-14 23:55')->utc());
        $this->seedDraft('2026-10-14 23:50');
        $this->assertSame(4, $this->total());
    }

    public function test_first_recap_slot_counts_drafts_created_after_wib_midnight(): void
    {
        $this->fakeSender();
        Cache::flush();
        Setting::set('psb_wa_group_ids', '120363000000000001@g.us');

        // Previous WIB day (negative case) and a draft just after WIB midnight.
        $this->travelTo($this->wib('2026-10-13 23:55')->utc());
        $this->seedDraft('2026-10-13 23:50');
        $this->travelTo($this->wib('2026-10-14 00:30')->utc());
        $this->seedDraft('2026-10-14 00:10');

        // First default recap slot (09:00 WIB); 00:30 is outside the schedule (#477).
        $this->travelTo($this->wib('2026-10-14 09:00')->utc());

        $notified = app(PsbOrchestratorService::class)->hourlyReport();

        $this->assertSame(1, $notified);
        $this->assertCount(1, $this->sent);
        $this->assertStringContainsString('Total: *1*', $this->sent[0][1]);
    }
}
