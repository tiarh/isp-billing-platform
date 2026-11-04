<?php

namespace Tests\Unit;

use App\Services\TechnicianProgressTemplateService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class TechnicianProgressTemplateServiceTest extends TestCase
{
    public function test_builds_daily_progress_template(): void
    {
        $service = new TechnicianProgressTemplateService;

        $message = $service->buildMessage(
            Carbon::parse('2026-07-23', 'Asia/Jakarta'),
            "Team 1\nWIDI & FIKRI\nSite : SUKO\nTask : STANDBY PSB\nProgress : STANDBY PSB",
            '- DOKUMENTASI & REPORT WAJIB',
            [[
                'code' => 'TCK-1',
                'assignee' => 'JAZULI',
                'site' => 'SUKO',
                'task' => 'MAINTENANCE LOS',
                'progress' => 'IN PROGRESS',
            ]],
            [[
                'id' => 67,
                'name' => 'DWIJAYANTI',
                'site' => 'RA',
                'task' => 'PSB dwi_rt04',
                'progress' => 'OLT OK',
            ]],
            [],
            [[
                'team_no' => 1,
                'team_label' => 'Team 1',
                'technicians' => ['JAZULI'],
                'site' => 'SUKO',
                'task' => 'MAINTENANCE LOS',
                'progress' => 'IN PROGRESS',
            ]]
        );

        $this->assertStringContainsString('JADWAL TEAM TEKNISI SKYNET MALANG', $message);
        $this->assertStringContainsString('KAMIS, 23-7-2026', $message);
        $this->assertStringContainsString('*PEMBAGIAN TEAM TEKNISI', $message);
        $this->assertStringContainsString('Team 1', $message);
        $this->assertStringContainsString('JAZULI', $message);
        $this->assertStringNotContainsString('*AUTO TICKET', $message);
        $this->assertStringNotContainsString('*AUTO PSB', $message);
        $this->assertStringContainsString('*NOTE DAILY', $message);
    }

    public function test_internal_legacy_errors_are_not_in_share_message(): void
    {
        $service = new TechnicianProgressTemplateService;
        $diagnostic = 'tickets: HTTP 500 from legacy endpoint';

        $message = $service->messageForShare([
            'message' => "Jadwal aman\n{$diagnostic}\nNOTE DAILY",
            'legacy_errors' => [$diagnostic],
        ]);

        $this->assertSame("Jadwal aman\nNOTE DAILY", $message);
        $this->assertStringNotContainsString($diagnostic, $message);
    }
}
