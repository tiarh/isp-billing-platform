<?php

namespace Tests\Feature;

use App\Models\PsbDraft;
use App\Models\SupportTicket;
use App\Services\TechnicianProgressTemplateService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use Tests\TestCase;

#[Group('blocking-core')]
class TechnicianProgressTemplateWibTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function psbJobs(string $workDate): array
    {
        $method = new ReflectionMethod(TechnicianProgressTemplateService::class, 'psbJobs');
        $method->setAccessible(true);

        return $method->invoke(new TechnicianProgressTemplateService, Carbon::parse($workDate, 'Asia/Jakarta'));
    }

    private function ticketJobs(string $workDate): array
    {
        $method = new ReflectionMethod(TechnicianProgressTemplateService::class, 'ticketJobs');
        $method->setAccessible(true);

        return $method->invoke(new TechnicianProgressTemplateService, Carbon::parse($workDate, 'Asia/Jakarta'));
    }

    private function draft(string $name, array $utcColumns): PsbDraft
    {
        $draft = PsbDraft::create([
            'status' => PsbDraft::STATUS_OLT_OK,
            'message_id' => 'msg-'.uniqid(),
            'full_name' => $name,
        ]);
        $draft->forceFill($utcColumns)->saveQuietly();

        return $draft;
    }

    public function test_morning_schedule_includes_psb_created_between_midnight_and_seven_wib(): void
    {
        $this->travelTo(Carbon::parse('2026-10-14 08:00:00', 'Asia/Jakarta'));
        // 01:00 WIB 14 Oct == 18:00 UTC 13 Oct.
        $this->draft('PAGI BUTA', ['created_at' => '2026-10-13 18:00:00', 'updated_at' => '2026-10-13 18:00:00']);
        // 00:30 WIB 15 Oct == 17:30 UTC 14 Oct, belongs to the next WIB day.
        $this->draft('BESOK', ['created_at' => '2026-10-14 17:30:00', 'updated_at' => '2026-10-14 17:30:00']);

        $names = array_column($this->psbJobs('2026-10-14'), 'name');

        $this->assertContains('PAGI BUTA', $names);
        $this->assertNotContains('BESOK', $names);
    }

    public function test_evening_preview_for_next_day_picks_wib_day_items(): void
    {
        $this->travelTo(Carbon::parse('2026-10-14 21:00:00', 'Asia/Jakarta'));
        // 03:00 WIB 15 Oct == 20:00 UTC 14 Oct; olt_at of the D+1 job.
        $this->draft('D PLUS SATU', ['created_at' => '2026-10-14 01:00:00', 'olt_at' => '2026-10-14 20:00:00', 'updated_at' => '2026-10-14 20:00:00']);

        $names = array_column($this->psbJobs('2026-10-15'), 'name');

        $this->assertContains('D PLUS SATU', $names);
    }

    public function test_ticket_due_between_midnight_and_seven_wib_is_on_that_day(): void
    {
        $this->travelTo(Carbon::parse('2026-10-14 08:00:00', 'Asia/Jakarta'));
        $ticket = SupportTicket::create([
            'code' => 'TCK-WIB-1',
            'subject' => 'Tes',
            'status' => 'open',
        ]);
        $utc = '2026-10-13 19:00:00'; // 02:00 WIB 14 Oct
        $ticket->forceFill(['created_at' => '2026-10-01 00:00:00', 'updated_at' => '2026-10-01 00:00:00', 'due_at' => $utc])->saveQuietly();

        $codes = array_column($this->ticketJobs('2026-10-14'), 'code');

        $this->assertContains('TCK-WIB-1', $codes);
    }
}
