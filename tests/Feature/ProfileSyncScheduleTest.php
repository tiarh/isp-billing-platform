<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-core')]
class ProfileSyncScheduleTest extends TestCase
{
    /** @return list<Event> events registered for the given scheduler mode */
    private function profileSyncEvents(string $mode): array
    {
        config(['services.ebilling_scheduler.mode' => $mode]);

        $schedule = new Schedule;
        $this->app->instance(Schedule::class, $schedule);
        ScheduleFacade::clearResolvedInstance(Schedule::class);

        require base_path('routes/console.php');

        return array_values(array_filter(
            $schedule->events(),
            fn (Event $e) => str_contains((string) $e->command, 'routers:sync-profiles-draft'),
        ));
    }

    public function test_safe_mode_registers_profile_sync_once_daily_at_1230_wib(): void
    {
        $events = $this->profileSyncEvents('safe');

        $this->assertCount(1, $events);
        $this->assertTrue($events[0]->runInBackground);
        $this->assertSame('30 12 * * *', $events[0]->expression);
        $this->assertSame('Asia/Jakarta', (string) $events[0]->timezone);
    }

    public function test_full_mode_registers_profile_sync_once_daily_at_1230_wib(): void
    {
        $events = $this->profileSyncEvents('full');

        $this->assertCount(1, $events);
        $this->assertTrue($events[0]->runInBackground);
        $this->assertSame('30 12 * * *', $events[0]->expression);
        $this->assertSame('Asia/Jakarta', (string) $events[0]->timezone);
    }
}
