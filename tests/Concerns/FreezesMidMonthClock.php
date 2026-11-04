<?php

namespace Tests\Concerns;

use Carbon\Carbon;

/**
 * Freezes the clock at a mid-month instant (15th, 12:00 WIB) so the test does
 * not depend on the day it runs.
 *
 * Why: billing periods and the dashboard use the WIB month (OpsTime), while
 * tests that build dates with now() use UTC. On the last UTC day of a month
 * (after 17:00 UTC) the two disagree on the month. On the 29th-31st,
 * now()->subMonth() also overflows into the current month (Sep 31 = Oct 1) and
 * two invoices land on the same period. A fixed 15th has neither problem.
 *
 * Laravel runs setUp{TraitName}() for each trait of the test class.
 */
trait FreezesMidMonthClock
{
    protected function setUpFreezesMidMonthClock(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-15 05:00:00', 'UTC'));
    }
}
