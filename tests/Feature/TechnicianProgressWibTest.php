<?php

namespace Tests\Feature;

use App\Models\PsbDraft;
use App\Models\TechnicianProgressReport;
use App\Services\TechnicianProgressService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use Tests\TestCase;

/**
 * WIB/UTC regressions in the technician progress service. Rows are seeded in UTC
 * (the way Laravel stores them) and the clock is moved to a WIB wall time.
 */
#[Group('blocking-core')]
class TechnicianProgressWibTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function wib(string $at): Carbon
    {
        return Carbon::parse($at, 'Asia/Jakarta');
    }

    private function report(string $createdAtUtc, string $workDate = '2026-10-14'): TechnicianProgressReport
    {
        $report = TechnicianProgressReport::create([
            'work_date' => $workDate,
            'group_jid' => 'grp@g.us',
            'sender_jid' => 'sender@s.whatsapp.net',
            'raw_text' => 'update',
        ]);
        $report->forceFill(['created_at' => $createdAtUtc, 'updated_at' => $createdAtUtc])->saveQuietly();

        return $report;
    }

    private function oltDraft(string $oltAtUtc): PsbDraft
    {
        $draft = PsbDraft::create([
            'status' => PsbDraft::STATUS_OLT_OK,
            'message_id' => 'msg-'.uniqid(),
        ]);
        $draft->forceFill(['olt_at' => $oltAtUtc])->saveQuietly();

        return $draft;
    }

    public function test_night_recap_sees_activity_after_since_in_wib(): void
    {
        $this->travelTo($this->wib('2026-10-14 20:35:00'));
        // 20:30 WIB == 13:30 UTC, after the 20:00 WIB cutoff.
        $this->report('2026-10-14 13:30:00');

        $this->assertTrue(app(TechnicianProgressService::class)->hasActivitySince('2026-10-14', '20:00'));
    }

    public function test_night_recap_ignores_activity_before_since_in_wib(): void
    {
        $this->travelTo($this->wib('2026-10-14 20:35:00'));
        // 19:00 WIB == 12:00 UTC, before the 20:00 WIB cutoff.
        $this->report('2026-10-14 12:00:00');

        $this->assertFalse(app(TechnicianProgressService::class)->hasActivitySince('2026-10-14', '20:00'));
    }

    public function test_daily_summary_command_does_not_skip_when_there_is_activity(): void
    {
        $this->travelTo($this->wib('2026-10-14 20:35:00'));
        $this->report('2026-10-14 13:30:00');

        // Default config is dry-run, so nothing is sent from the test.
        Artisan::call('technician-progress:daily', [
            '--mode' => 'summary',
            '--date' => '2026-10-14',
            '--if-active-since' => '20:00',
        ]);

        $this->assertStringNotContainsString('SKIP_NO_ACTIVITY_SINCE', Artisan::output());
    }

    public function test_psb_selesai_counts_items_between_midnight_and_seven_wib(): void
    {
        $this->travelTo($this->wib('2026-10-14 21:00:00'));
        $this->oltDraft('2026-10-13 18:00:00'); // 01:00 WIB 14 Oct: in
        $this->oltDraft('2026-10-14 05:00:00'); // 12:00 WIB 14 Oct: in
        $this->oltDraft('2026-10-13 16:30:00'); // 23:30 WIB 13 Oct: out

        $method = new ReflectionMethod(TechnicianProgressService::class, 'psbCompletionStats');
        $method->setAccessible(true);
        $stats = $method->invoke(app(TechnicianProgressService::class), '2026-10-14');

        $this->assertSame(2, $stats['total']);
    }

    public function test_psb_activity_since_uses_wib_day(): void
    {
        $this->travelTo($this->wib('2026-10-14 20:35:00'));
        $this->oltDraft('2026-10-14 13:30:00'); // 20:30 WIB, same WIB day, after cutoff

        $this->assertTrue(app(TechnicianProgressService::class)->hasActivitySince('2026-10-14', '20:00'));
    }

    public function test_photo_links_to_report_sent_minutes_earlier(): void
    {
        $this->travelTo($this->wib('2026-10-14 15:00:00'));
        $recent = $this->report(now()->subMinutes(3)->utc()->toDateTimeString());

        $method = new ReflectionMethod(TechnicianProgressService::class, 'latestReportForPhoto');
        $method->setAccessible(true);
        $found = $method->invoke(app(TechnicianProgressService::class), '2026-10-14', 'grp@g.us', 'sender@s.whatsapp.net');

        $this->assertNotNull($found);
        $this->assertSame($recent->id, $found->id);
    }

    public function test_photo_does_not_link_to_other_sender_or_other_day(): void
    {
        $this->travelTo($this->wib('2026-10-14 15:00:00'));
        $this->report(now()->subMinutes(3)->utc()->toDateTimeString());

        $method = new ReflectionMethod(TechnicianProgressService::class, 'latestReportForPhoto');
        $method->setAccessible(true);
        $service = app(TechnicianProgressService::class);

        $this->assertNull($method->invoke($service, '2026-10-14', 'grp@g.us', 'other@s.whatsapp.net'));
        $this->assertNull($method->invoke($service, '2026-10-13', 'grp@g.us', 'sender@s.whatsapp.net'));
    }

    public function test_since_cutoff_at_17_wib_is_exclusive_of_earlier_activity(): void
    {
        $this->travelTo($this->wib('2026-10-14 17:30:00'));
        $this->report('2026-10-14 09:59:00'); // 16:59 WIB, before 17:00
        $service = app(TechnicianProgressService::class);

        $this->assertFalse($service->hasActivitySince('2026-10-14', '17:00'));

        $this->report('2026-10-14 10:01:00'); // 17:01 WIB
        $this->assertTrue($service->hasActivitySince('2026-10-14', '17:00'));
    }

    public function test_photo_does_not_link_to_report_older_than_window(): void
    {
        $this->travelTo($this->wib('2026-10-14 15:00:00'));
        $this->report(now()->subMinutes(180)->utc()->toDateTimeString());

        $method = new ReflectionMethod(TechnicianProgressService::class, 'latestReportForPhoto');
        $method->setAccessible(true);

        $this->assertNull($method->invoke(app(TechnicianProgressService::class), '2026-10-14', 'grp@g.us', 'sender@s.whatsapp.net'));
    }
}
