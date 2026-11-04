<?php

namespace Tests\Feature;

use Illuminate\Console\Application;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Every command that routes/console.php schedules must be a registered artisan command, in every
 * scheduler mode. `olt:auto-sync` was scheduled for months in full mode without ever existing
 * ("Command not defined" every 30 minutes).
 */
#[Group('blocking-core')]
class ScheduledCommandsExistTest extends TestCase
{
    /** @return list<Event> */
    private function events(string $mode, bool $decomposition): array
    {
        config([
            'services.ebilling_scheduler.mode' => $mode,
            'services.monitoring.queue_decomposition_enabled' => $decomposition,
        ]);
        $schedule = new Schedule;
        $this->app->instance(Schedule::class, $schedule);
        ScheduleFacade::clearResolvedInstance(Schedule::class);
        require base_path('routes/console.php');

        return array_values($schedule->events());
    }

    public function test_every_scheduled_artisan_command_exists_in_every_mode(): void
    {
        $registered = Artisan::all();
        $prefix = Application::formatCommandString('');
        $checked = 0;

        foreach (['safe', 'monitoring', 'full'] as $mode) {
            foreach ([false, true] as $decomposition) {
                foreach ($this->events($mode, $decomposition) as $event) {
                    $command = (string) ($event->command ?? '');
                    if ($command === '' || ! str_starts_with($command, $prefix)) {
                        continue; // closure / job / exec events
                    }
                    $name = (string) strtok(substr($command, strlen($prefix)), ' ');
                    $checked++;
                    $this->assertArrayHasKey(
                        $name,
                        $registered,
                        "Scheduled command '{$name}' does not exist (mode={$mode}, decomposition=".($decomposition ? 'on' : 'off').')',
                    );
                }
            }
        }

        $this->assertGreaterThan(20, $checked, 'the scan must actually see the schedule');
    }

    public function test_every_command_run_through_the_monitoring_collector_job_exists(): void
    {
        // Job events hide the command inside RunMonitoringCollectorJob, so scan the source.
        $source = (string) file_get_contents(base_path('routes/console.php'));
        preg_match_all('/\$monitoringJob\(\s*\'[^\']+\',\s*\'([^\']+)\'/', $source, $matches);
        $names = array_values(array_unique($matches[1]));
        $registered = Artisan::all();

        $this->assertNotEmpty($names);
        foreach ($names as $name) {
            $this->assertArrayHasKey($name, $registered, "Collector job command '{$name}' does not exist");
        }
    }

    public function test_olt_auto_sync_is_gone_from_the_schedule(): void
    {
        $this->assertStringNotContainsString('olt:auto-sync', (string) file_get_contents(base_path('routes/console.php')));
        $this->assertArrayNotHasKey('olt:auto-sync', Artisan::all());
    }
}
