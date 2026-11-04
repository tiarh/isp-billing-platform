<?php

namespace Tests\Unit;

use App\Services\PsbRecapCommandParser;
use App\Services\Recap\RecapRequest;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-psb')]
class PsbRecapCommandParserTest extends TestCase
{
    /** Wed 14 Oct 2026, 21:30 WIB. */
    private function now(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-10-14 21:30', 'Asia/Jakarta');
    }

    private function parse(string $text, int $maxDays = 92): ?RecapRequest
    {
        return (new PsbRecapCommandParser)->parse($text, $this->now(), $maxDays);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function dateCases(): array
    {
        return [
            'day range + short month' => ['#rekap psb klampok 1-14 okt', 'klampok', '2026-10-01', '2026-10-14'],
            'spaced range + long month' => ['#rekap psb klampok 1 - 14 oktober', 'klampok', '2026-10-01', '2026-10-14'],
            'untyped year follows the START date, end clipped to today (1 okt - 3 nov)' => ['#rekap psb klampok 1 okt - 3 nov', 'klampok', '2026-10-01', '2026-10-14'],
            'untyped year, month end beyond today is clipped (1-31 okt)' => ['#rekap psb klampok 1-31 okt', 'klampok', '2026-10-01', '2026-10-14'],
            'start in the future rolls the whole range to the previous year (20 nov - 5 des)' => ['#rekap psb klampok 20 nov - 5 des', 'klampok', '2025-11-20', '2025-12-05'],
            'range across months in the past' => ['#rekap psb klampok 20 sep - 3 okt', 'klampok', '2026-09-20', '2026-10-03'],
            'dd/mm/yyyy pair' => ['#rekap psb klampok 01/10/2026 14/10/2026', 'klampok', '2026-10-01', '2026-10-14'],
            'dd/mm-dd/mm' => ['#rekap psb klampok 01/10-14/10', 'klampok', '2026-10-01', '2026-10-14'],
            'single dd/mm' => ['#rekap psb klampok 14/10', 'klampok', '2026-10-14', '2026-10-14'],
            'single day + month' => ['#rekap psb klampok 14 sep', 'klampok', '2026-09-14', '2026-09-14'],
            'explicit year' => ['#rekap psb klampok 1-14 okt 2025', 'klampok', '2025-10-01', '2025-10-14'],
            'hari ini' => ['#rekap psb klampok hari ini', 'klampok', '2026-10-14', '2026-10-14'],
            'kemarin' => ['#rekap psb klampok kemarin', 'klampok', '2026-10-13', '2026-10-13'],
            'minggu ini' => ['#rekap psb klampok minggu ini', 'klampok', '2026-10-12', '2026-10-14'],
            'bulan ini' => ['#rekap psb klampok bulan ini', 'klampok', '2026-10-01', '2026-10-14'],
            'bulan lalu' => ['#rekap psb klampok bulan lalu', 'klampok', '2026-09-01', '2026-09-30'],
            'whole month short' => ['#rekap psb klampok okt', 'klampok', '2026-10-01', '2026-10-14'],
            'whole month + year (past)' => ['#rekap psb klampok september 2026', 'klampok', '2026-09-01', '2026-09-30'],
            'whole month rolls to previous year' => ['#rekap psb klampok des', 'klampok', '2025-12-01', '2025-12-31'],
            'year rollover december to january' => ['#rekap psb klampok 1 des - 3 jan', 'klampok', '2025-12-01', '2026-01-03'],
            'agustus alias agt' => ['#rekap psb klampok agt', 'klampok', '2026-08-01', '2026-08-31'],
            'no date = 1st of month to today' => ['#rekap psb klampok', 'klampok', '2026-10-01', '2026-10-14'],
            'multi word area' => ['#rekap psb randu agung 1-14 okt', 'randu agung', '2026-10-01', '2026-10-14'],
            'multi word area, mixed case, extra spaces' => ['# Rekap  PSB  Randu   Agung  1-14  OKT', 'randu agung', '2026-10-01', '2026-10-14'],
        ];
    }

    #[DataProvider('dateCases')]
    public function test_date_grammar_and_area_split(string $text, string $area, string $from, string $to): void
    {
        $request = $this->parse($text);

        $this->assertNotNull($request);
        $this->assertNull($request->error, $text);
        $this->assertSame($area, mb_strtolower($request->areaQuery), $text);
        $this->assertSame($from, $request->fromDate(), $text);
        $this->assertSame($to, $request->toDate(), $text);
        $this->assertFalse($request->allAreas);
    }

    public function test_semua_and_all_mean_every_area(): void
    {
        foreach (['#rekap psb semua', '#rekap psb all', '#rekap psb SEMUA 1-14 okt'] as $text) {
            $request = $this->parse($text);
            $this->assertTrue($request->allAreas, $text);
            $this->assertNull($request->error, $text);
        }
    }

    public function test_excel_and_xlsx_suffix_are_accepted_aliases(): void
    {
        $a = $this->parse('#rekap psb klampok 1-14 okt excel');
        $b = $this->parse('#rekap psb klampok xlsx');
        $c = $this->parse('#rekap psb klampok');

        $this->assertTrue($a->excelAlias);
        $this->assertSame('klampok', $a->areaQuery);
        $this->assertSame('2026-10-14', $a->toDate());
        $this->assertTrue($b->excelAlias);
        $this->assertSame('klampok', $b->areaQuery);
        $this->assertFalse($c->excelAlias);
    }

    public function test_to_in_the_future_is_cut_to_today(): void
    {
        $request = $this->parse('#rekap psb klampok 10/10/2026 30/10/2026');

        $this->assertSame('2026-10-10', $request->fromDate());
        $this->assertSame('2026-10-14', $request->toDate());
    }

    public function test_from_after_to_is_swapped(): void
    {
        $request = $this->parse('#rekap psb klampok 14-1 okt');

        $this->assertNull($request->error);
        $this->assertSame('2026-10-01', $request->fromDate());
        $this->assertSame('2026-10-14', $request->toDate());
    }

    public function test_range_over_the_max_days_is_flagged(): void
    {
        // 1 Jul - 14 Oct 2026 = 106 days
        $this->assertSame(RecapRequest::ERROR_RANGE_TOO_LONG, $this->parse('#rekap psb klampok 1 jul - 14 okt')->error);
        // 92 days exactly is fine: 15 Jul - 14 Oct; 14 Jul is 93 days
        $this->assertNull($this->parse('#rekap psb klampok 15 jul - 14 okt')->error);
        $this->assertSame(RecapRequest::ERROR_RANGE_TOO_LONG, $this->parse('#rekap psb klampok 14 jul - 14 okt')->error);
        $this->assertSame(RecapRequest::ERROR_RANGE_TOO_LONG, $this->parse('#rekap psb klampok 1-14 okt', 10)->error);
        $this->assertNull($this->parse('#rekap psb klampok 1-10 okt', 10)->error);
    }

    public function test_invalid_date_and_missing_area_are_flagged(): void
    {
        $this->assertSame(RecapRequest::ERROR_INVALID_DATE, $this->parse('#rekap psb klampok 31/02')->error);
        $this->assertSame(RecapRequest::ERROR_INVALID_DATE, $this->parse('#rekap psb klampok 32 okt - 33 okt')->error);
        $this->assertSame(RecapRequest::ERROR_MISSING_AREA, $this->parse('#rekap psb')->error);
        $this->assertSame(RecapRequest::ERROR_MISSING_AREA, $this->parse('#rekap psb 1-14 okt')->error);
    }

    public function test_year_boundary_rule_start_year_is_current_unless_start_is_in_the_future(): void
    {
        // Today = 10 Jan 2026. Rule: the year comes from the START date (current year,
        // previous year when the start would be in the future); the end follows the
        // start and is clipped to today. Whole month "des" = previous December.
        $now = CarbonImmutable::parse('2026-01-10 09:00', 'Asia/Jakarta');
        $parse = fn (string $text) => (new PsbRecapCommandParser)->parse($text, $now, 92);

        $des = $parse('#rekap psb klampok des');
        $this->assertSame(['2025-12-01', '2025-12-31'], [$des->fromDate(), $des->toDate()]);

        $range = $parse('#rekap psb klampok 20 des - 5 jan');
        $this->assertSame(['2025-12-20', '2026-01-05'], [$range->fromDate(), $range->toDate()]);

        $mid = $parse('#rekap psb klampok 1-31 jan');
        $this->assertSame(['2026-01-01', '2026-01-10'], [$mid->fromDate(), $mid->toDate()]);

        $future = $parse('#rekap psb klampok 15 jan - 20 jan');
        $this->assertSame(['2025-01-15', '2025-01-20'], [$future->fromDate(), $future->toDate()]);
    }

    public function test_a_bare_year_after_the_area_is_rejected_as_an_invalid_date(): void
    {
        $this->assertSame(RecapRequest::ERROR_INVALID_DATE, $this->parse('#rekap psb klampok 2026')->error);
        $this->assertSame(RecapRequest::ERROR_INVALID_DATE, $this->parse('#rekap psb randu agung 2025')->error);
        // A year attached to a month stays valid.
        $this->assertNull($this->parse('#rekap psb klampok okt 2026')->error);
    }

    public function test_default_range_follows_the_wib_date_not_utc(): void
    {
        // 14 Oct 00:30 WIB is still 13 Oct in UTC.
        $now = CarbonImmutable::parse('2026-10-14 00:30', 'Asia/Jakarta');
        $request = (new PsbRecapCommandParser)->parse('#rekap psb klampok', $now, 92);

        $this->assertSame('2026-10-01', $request->fromDate());
        $this->assertSame('2026-10-14', $request->toDate());
        $this->assertSame(14, $request->days());
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function matchCases(): array
    {
        return [
            'plain' => ['#rekap psb klampok', true],
            'space after hash' => ['# rekap psb klampok', true],
            'leading spaces + uppercase' => ['  #REKAP PSB semua', true],
            'bare topic' => ['#rekap psb', true],
            'no hash' => ['rekap psb klampok', false],
            'slash style' => ['/rekap psb klampok', false],
            'other topic' => ['#rekap migrasi klampok', false],
            'topic prefix only' => ['#rekap psbx klampok', false],
            'mid sentence' => ['tolong #rekap psb klampok', false],
            'multi line' => ["#rekap psb klampok\nhalo", false],
            'empty' => ['', false],
        ];
    }

    #[DataProvider('matchCases')]
    public function test_matches_is_anchored_single_line_text(string $text, bool $expected): void
    {
        $this->assertSame($expected, (new PsbRecapCommandParser)->matches($text), $text);
        if (! $expected) {
            $this->assertNull((new PsbRecapCommandParser)->parse($text, $this->now(), 92));
        }
    }
}
