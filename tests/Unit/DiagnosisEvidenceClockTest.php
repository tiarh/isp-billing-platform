<?php

namespace Tests\Unit;

use App\Services\Outage\Diagnosis\DiagnosisEvidence;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DiagnosisEvidenceClockTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_same_wib_day_prints_hours_and_minutes_only(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-30 14:00:00', 'Asia/Jakarta'));

        $this->assertSame('12:00', DiagnosisEvidence::clock(Carbon::parse('2026-10-30 12:00:00', 'Asia/Jakarta')));
    }

    public function test_earlier_wib_day_prints_the_date_before_the_time(): void
    {
        // 02:00 WIB: an event 7 hours back is on the previous WIB day
        Carbon::setTestNow(Carbon::parse('2026-10-30 02:00:00', 'Asia/Jakarta'));

        $this->assertSame('29/10 19:00', DiagnosisEvidence::clock(Carbon::parse('2026-10-29 19:00:00', 'Asia/Jakarta')));
    }

    public function test_day_is_judged_in_wib_not_utc(): void
    {
        // 02:00 WIB on the 30th is still the 29th in UTC; the event at 01:00 WIB (18:00 UTC the 29th) is the same WIB day
        Carbon::setTestNow(Carbon::parse('2026-10-29 19:00:00', 'UTC'));

        $this->assertSame('01:00', DiagnosisEvidence::clock(Carbon::parse('2026-10-29 18:00:00', 'UTC')));
    }

    public function test_unknown_time_is_a_dash(): void
    {
        $this->assertSame('-', DiagnosisEvidence::clock(null));
    }
}
