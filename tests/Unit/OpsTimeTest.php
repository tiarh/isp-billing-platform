<?php

namespace Tests\Unit;

use App\Support\OpsTime;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-core')]
class OpsTimeTest extends TestCase
{
    public function test_local_converts_to_wib(): void
    {
        $utc = CarbonImmutable::parse('2026-10-13 20:30:00', 'UTC');

        $local = OpsTime::local($utc);

        $this->assertSame('Asia/Jakarta', $local->timezoneName);
        $this->assertSame('2026-10-14 03:30:00', $local->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-14', OpsTime::today($utc));
    }

    public function test_format_appends_wib_label_and_handles_null(): void
    {
        $utc = CarbonImmutable::parse('2026-10-20 07:00:00', 'UTC');

        $this->assertSame('20/10/2026 14:00 WIB', OpsTime::format($utc));
        $this->assertSame('20 Oct 2026 WIB', OpsTime::format($utc, 'd M Y'));
        $this->assertSame('-', OpsTime::format(null));
    }

    public function test_iso_carries_the_wib_offset_for_carbon_and_naive_db_strings(): void
    {
        $this->assertSame('2026-10-20T14:00:00+07:00', OpsTime::iso(CarbonImmutable::parse('2026-10-20 07:00:00', 'UTC')));
        // Naive "Y-m-d H:i:s" from DB::table()/max() is UTC.
        $this->assertSame('2026-10-20T14:00:00+07:00', OpsTime::iso('2026-10-20 07:00:00'));
        $this->assertNull(OpsTime::iso(null));
        $this->assertNull(OpsTime::iso(''));
        $this->assertNull(OpsTime::iso('not a date'));
    }

    public function test_parse_local_reads_wib_wall_clock_and_returns_utc(): void
    {
        $parsed = OpsTime::parseLocal('2026-10-20T14:00');

        $this->assertSame('UTC', $parsed->timezoneName);
        $this->assertSame('2026-10-20 07:00:00', $parsed->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-19 17:00:00', OpsTime::parseLocal('2026-10-20 00:00:00')->format('Y-m-d H:i:s'));
    }

    public function test_parse_local_keeps_an_explicit_offset(): void
    {
        $this->assertSame('2026-10-20 14:00:00', OpsTime::parseLocal('2026-10-20T14:00:00Z')->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-20 07:00:00', OpsTime::parseLocal('2026-10-20T14:00:00+07:00')->format('Y-m-d H:i:s'));
    }

    public function test_parse_local_round_trips_through_iso(): void
    {
        $first = OpsTime::parseLocal('2026-10-20T14:00');
        $again = OpsTime::parseLocal(substr((string) OpsTime::iso($first), 0, 16));

        $this->assertTrue($first->equalTo($again));
    }

    public function test_month_bounds_are_the_wib_month_in_utc(): void
    {
        [$start, $end] = OpsTime::monthBounds('2026-10');

        $this->assertSame('2026-09-30 17:00:00', $start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-31 16:59:59', $end->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $start->timezoneName);
    }

    public function test_month_bounds_handle_february_and_year_end(): void
    {
        [, $feb] = OpsTime::monthBounds('2028-02');
        $this->assertSame('2028-02-29 16:59:59', $feb->format('Y-m-d H:i:s'));

        [$dec, $decEnd] = OpsTime::monthBounds('2026-12');
        $this->assertSame('2026-11-30 17:00:00', $dec->format('Y-m-d H:i:s'));
        $this->assertSame('2026-12-31 16:59:59', $decEnd->format('Y-m-d H:i:s'));
    }

    public function test_range_bounds_cover_whole_wib_days(): void
    {
        [$start, $end] = OpsTime::rangeBounds('2026-10-01', '2026-10-03');

        $this->assertSame('2026-09-30 17:00:00', $start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-03 16:59:59', $end->format('Y-m-d H:i:s'));
    }

    public function test_day_bounds_still_work(): void
    {
        [$start, $end] = OpsTime::dayBounds(CarbonImmutable::parse('2026-10-13 20:00:00', 'UTC'));

        $this->assertSame('2026-10-13 17:00:00', $start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-14 16:59:59', $end->format('Y-m-d H:i:s'));
    }
}
