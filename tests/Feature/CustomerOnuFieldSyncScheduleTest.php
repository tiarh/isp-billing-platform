<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Setting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Concerns\BuildsOnuSyncFixtures;
use Tests\TestCase;

/**
 * Contract of the daily customers:sync-onu-fields entry (owner 2026-10-15). It REPLACES the earlier "manual only,
 * never in schedule:list" rule: the entry now exists but is OFF until customer_onu_sync_schedule_enabled=1.
 */
#[Group('blocking-core')]
class CustomerOnuFieldSyncScheduleTest extends TestCase
{
    use BuildsOnuSyncFixtures;
    use RefreshDatabase;

    /** Minutes that other entries use (SchedulerStaggerContractTest rationale). */
    private const BUSY_MINUTES = [0, 5, 10, 13, 14, 15, 16, 20, 25, 26, 27, 28, 29, 30, 35, 40, 41, 43, 44, 45, 46, 50, 55, 56, 57, 58, 59];

    /** @return list<Event> */
    private function entries(string $mode): array
    {
        config(['services.ebilling_scheduler.mode' => $mode]);
        $schedule = new Schedule;
        $this->app->instance(Schedule::class, $schedule);
        ScheduleFacade::clearResolvedInstance(Schedule::class);
        require base_path('routes/console.php');

        return array_values(array_filter($schedule->events(), fn (Event $e) => str_contains((string) $e->command, 'customers:sync-onu-fields')));
    }

    public function test_exactly_one_daily_entry_in_monitoring_and_full_mode(): void
    {
        foreach (['monitoring', 'full'] as $mode) {
            $events = $this->entries($mode);
            $this->assertCount(1, $events, $mode);
            $e = $events[0];
            $this->assertStringContainsString('customers:sync-onu-fields --scheduled', (string) $e->command);
            $this->assertSame('23 9 * * *', $e->expression);
            $this->assertSame('Asia/Jakarta', (string) $e->timezone);
            $this->assertTrue($e->withoutOverlapping);
            $this->assertTrue($e->onOneServer);
            $this->assertSame(30, $e->expiresAt);
            $this->assertNotContains(23, self::BUSY_MINUTES);
        }
    }

    public function test_entry_is_gated_by_both_settings(): void
    {
        $e = $this->entries('monitoring')[0];

        $this->assertFalse($e->filtersPass($this->app), 'no setting rows: OFF');

        Setting::set('customer_onu_sync_schedule_enabled', 1);
        $this->assertTrue($e->filtersPass($this->app), 'master default ON + schedule ON');

        Setting::set('customer_onu_sync_enabled', 0);
        $this->assertFalse($e->filtersPass($this->app), 'master kill switch off');

        Setting::set('customer_onu_sync_enabled', 1);
        Setting::set('customer_onu_sync_schedule_enabled', 0);
        $this->assertFalse($e->filtersPass($this->app));

        foreach (['false', 'off', 'no'] as $off) { // same filter_var parsing as the command (L4)
            Setting::set('customer_onu_sync_schedule_enabled', $off);
            $this->assertFalse($e->filtersPass($this->app), $off);
        }
    }

    public function test_running_the_scheduled_command_with_defaults_writes_nothing(): void
    {
        $this->blockDevices();
        $f = $this->scenario();
        $settingsBefore = DB::table('settings')->count();

        Artisan::call('customers:sync-onu-fields', ['--scheduled' => true]);

        $this->assertStringContainsString('nonaktif', Artisan::output());
        $this->assertNull(Customer::query()->find($f['customer']->id)->olt_id);
        $this->assertSame(0, Activity::where('log_name', 'customer_onu_sync')->count());
        $this->assertSame($settingsBefore, DB::table('settings')->count(), 'last_run untouched');
        $this->assertNull(Setting::get('customer_onu_sync_last_run'));
    }
}
