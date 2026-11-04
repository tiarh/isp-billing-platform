<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-core')]
class ReportDailyScheduleTest extends TestCase
{
    private const DESCRIPTION_PREFIX = 'Skynet Report WA: laporan harian kemarin';

    /**
     * @return array<string, array{0: string}>
     */
    public static function modes(): array
    {
        return ['safe' => ['safe'], 'monitoring' => ['monitoring'], 'full' => ['full']];
    }

    /** @return list<Event> */
    private function eventsFor(string $mode): array
    {
        config(['services.ebilling_scheduler.mode' => $mode]);

        $schedule = new Schedule;
        $this->app->instance(Schedule::class, $schedule);
        ScheduleFacade::clearResolvedInstance(Schedule::class);

        require base_path('routes/console.php');

        return $schedule->events();
    }

    /**
     * @return list<Event>
     */
    private function reportEvents(string $mode): array
    {
        return array_values(array_filter(
            $this->eventsFor($mode),
            fn (Event $e): bool => str_starts_with((string) $e->description, self::DESCRIPTION_PREFIX),
        ));
    }

    #[DataProvider('modes')]
    public function test_the_daily_report_is_registered_once_in_every_scheduler_mode(string $mode): void
    {
        $events = $this->reportEvents($mode);

        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertSame('3 8-11 * * *', $event->expression);
        $this->assertSame('Asia/Jakarta', (string) $event->timezone);
        $this->assertTrue($event->onOneServer);
        $this->assertGreaterThan(0, (int) $event->expiresAt);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertStringContainsString('report:daily-wa', (string) $event->command);
    }

    public function test_it_fires_at_0803_wib_with_catch_up_until_1103_and_never_on_a_boundary_minute(): void
    {
        $event = $this->reportEvents('monitoring')[0];
        $cron = new CronExpression($event->expression);

        $due = [];
        $start = CarbonImmutable::create(2026, 10, 15, 0, 0, 0, 'Asia/Jakarta');
        for ($m = 0; $m < 24 * 60; $m++) {
            if ($cron->isDue($start->addMinutes($m), 'Asia/Jakarta')) {
                $due[] = $start->addMinutes($m)->format('H:i');
            }
        }

        $this->assertSame(['08:03', '09:03', '10:03', '11:03'], $due);
    }

    public function test_the_psb_hourly_report_entry_is_unchanged(): void
    {
        $events = array_values(array_filter(
            $this->eventsFor('monitoring'),
            fn (Event $e): bool => str_contains((string) $e->command, 'psb:hourly-report'),
        ));

        $this->assertCount(1, $events);
        $this->assertSame('7 * * * *', $events[0]->expression);
    }
}
