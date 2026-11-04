<?php

namespace Tests\Feature;

use App\Models\PsbDraft;
use App\Models\Setting;
use App\Models\User;
use App\Services\EvolutionApiService;
use App\Services\PsbOrchestratorService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-psb')]
class PsbRecapScheduleTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{0: string, 1: string}> */
    private array $sent = [];

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->sent = [];
        Setting::set('psb_wa_group_ids', ['120363000000000001@g.us'], 'json', 'psb');

        // Fake Evolution sender: records messages, never calls the network.
        $evo = Mockery::mock(EvolutionApiService::class);
        $evo->shouldReceive('forChannel')->andReturnSelf();
        $evo->shouldReceive('sendToGroup')->andReturnUsing(function (string $jid, string $text) {
            $this->sent[] = [$jid, $text];

            return ['success' => true];
        });
        $this->app->instance(EvolutionApiService::class, $evo);
    }

    private function at(string $hhmm): void
    {
        $this->travelTo(Carbon::parse('2026-10-14 '.$hhmm, 'Asia/Jakarta'));
    }

    private function newDraft(): void
    {
        $this->seq++;
        PsbDraft::create([
            'status' => PsbDraft::STATUS_WAITING_SN,
            'activation_mode' => PsbDraft::MODE_FULL_OLT,
            'site_profile_key' => 'ra',
            'group_jid' => '120363000000000001@g.us',
            'message_id' => 'recap-'.$this->seq,
            'full_name' => 'Rekap '.$this->seq,
            'rw' => '1',
            'daerah' => 'Randuagung',
            'sn' => 'FHTTRECAP'.$this->seq,
        ]);
    }

    /** Seed a changed draft at the given WIB time and run the hourly report. */
    private function runAt(string $hhmm): int
    {
        $this->at($hhmm);
        $this->newDraft();

        return app(PsbOrchestratorService::class)->hourlyReport();
    }

    public function test_default_schedule_sends_at_09_11_and_21_wib(): void
    {
        $this->assertSame(1, $this->runAt('09:00'));
        $this->assertSame(1, $this->runAt('11:00'));
        $this->assertSame(1, $this->runAt('21:00'));
        $this->assertCount(3, $this->sent);
    }

    public function test_default_schedule_skips_off_hours(): void
    {
        foreach (['08:00', '10:00', '22:00', '00:00', '23:00'] as $time) {
            $this->assertSame(0, $this->runAt($time), $time);
        }
        $this->assertSame([], $this->sent);
    }

    public function test_default_schedule_sends_all_seven_slots(): void
    {
        foreach (['09', '11', '13', '15', '17', '19', '21'] as $h) {
            $this->assertSame(1, $this->runAt($h.':00'), $h);
        }
        $this->assertSame(
            [9, 11, 13, 15, 17, 19, 21],
            PsbOrchestratorService::recapSendHours(PsbOrchestratorService::recapSchedule())
        );
    }

    public function test_unchanged_fingerprint_skips_even_inside_schedule(): void
    {
        $this->assertSame(1, $this->runAt('09:00'));

        $this->at('11:00'); // no new draft, data unchanged
        $this->assertSame(0, app(PsbOrchestratorService::class)->hourlyReport());
        $this->assertCount(1, $this->sent);
    }

    public function test_zero_drafts_skips_inside_schedule(): void
    {
        $this->at('09:00');
        $this->assertSame(0, app(PsbOrchestratorService::class)->hourlyReport());
        $this->assertSame([], $this->sent);
    }

    public function test_custom_settings_start_8_end_20_interval_3(): void
    {
        Setting::set('psb_recap_start_hour', '8', 'integer', 'psb');
        Setting::set('psb_recap_end_hour', '20', 'integer', 'psb');
        Setting::set('psb_recap_interval_hours', '3', 'integer', 'psb');

        foreach (['08', '11', '14', '17', '20'] as $h) {
            $this->assertSame(1, $this->runAt($h.':00'), $h);
        }
        foreach (['09:00', '10:00', '12:00', '21:00', '07:00'] as $time) {
            $this->assertSame(0, $this->runAt($time), $time);
        }
    }

    public function test_invalid_settings_fall_back_to_defaults(): void
    {
        foreach ([
            ['abc', '25', '0'],
            ['-1', '99', '13'],
            ['', '', ''],
            ['20', '8', '2'], // end < start
        ] as [$start, $end, $interval]) {
            Setting::set('psb_recap_start_hour', $start, 'text', 'psb');
            Setting::set('psb_recap_end_hour', $end, 'text', 'psb');
            Setting::set('psb_recap_interval_hours', $interval, 'text', 'psb');

            $this->assertSame(['start' => 9, 'end' => 21, 'interval' => 2], PsbOrchestratorService::recapSchedule());
        }

        // Missing keys also use defaults.
        Setting::query()->whereIn('key', ['psb_recap_start_hour', 'psb_recap_end_hour', 'psb_recap_interval_hours'])->delete();
        Cache::flush();
        $this->assertSame(['start' => 9, 'end' => 21, 'interval' => 2], PsbOrchestratorService::recapSchedule());

        $this->assertSame(0, $this->runAt('10:00'));
        $this->assertSame(1, $this->runAt('11:00'));
    }

    public function test_settings_save_persists_valid_recap_values(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->postJson(route('psb-drafts.settings'), [
            'psb_wa_group_ids' => ['120363000000000001@g.us'],
            'psb_recap_start_hour' => 8,
            'psb_recap_end_hour' => 20,
            'psb_recap_interval_hours' => 3,
        ])->assertOk();

        $this->assertSame(['start' => 8, 'end' => 20, 'interval' => 3], PsbOrchestratorService::recapSchedule());
    }

    public function test_settings_save_rejects_end_before_start_and_bad_interval(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->postJson(route('psb-drafts.settings'), [
            'psb_recap_start_hour' => 15,
            'psb_recap_end_hour' => 10,
            'psb_recap_interval_hours' => 2,
        ])->assertStatus(422)->assertJsonValidationErrors(['psb_recap_end_hour']);

        $this->actingAs($admin)->postJson(route('psb-drafts.settings'), [
            'psb_recap_start_hour' => 9,
            'psb_recap_end_hour' => 21,
            'psb_recap_interval_hours' => 0,
        ])->assertStatus(422)->assertJsonValidationErrors(['psb_recap_interval_hours']);

        $this->actingAs($admin)->postJson(route('psb-drafts.settings'), [
            'psb_recap_start_hour' => 24,
        ])->assertStatus(422)->assertJsonValidationErrors(['psb_recap_start_hour']);

        $this->assertDatabaseMissing('settings', ['key' => 'psb_recap_start_hour']);
        $this->assertDatabaseMissing('settings', ['key' => 'psb_recap_interval_hours']);
    }

    public function test_settings_save_without_recap_fields_keeps_existing_values(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Setting::set('psb_recap_interval_hours', '4', 'integer', 'psb');

        $this->actingAs($admin)->postJson(route('psb-drafts.settings'), [
            'psb_wa_group_ids' => ['120363000000000001@g.us'],
        ])->assertOk();

        $this->assertSame(4, PsbOrchestratorService::recapSchedule()['interval']);
    }

    public function test_schedule_registers_hourly_report_all_day_without_window(): void
    {
        $source = (string) file_get_contents(base_path('routes/console.php'));

        $this->assertSame(1, substr_count($source, "Schedule::command('psb:hourly-report')"));
        $this->assertMatchesRegularExpression(
            "/Schedule::command\('psb:hourly-report'\)\s*->hourlyAt\(7\)\s*->timezone\('Asia\/Jakarta'\)\s*->withoutOverlapping\(10\)\s*->onOneServer\(\)\s*->runInBackground\(\)/s",
            $source
        );
        $this->assertDoesNotMatchRegularExpression(
            "/Schedule::command\('psb:hourly-report'\)[^;]*->between\(/s",
            $source
        );
    }

    /** @return list<string> command strings registered for the given scheduler mode */
    private function scheduledCommands(string $mode): array
    {
        config(['services.ebilling_scheduler.mode' => $mode]);

        $schedule = new Schedule;
        $this->app->instance(Schedule::class, $schedule);
        ScheduleFacade::clearResolvedInstance(Schedule::class);

        require base_path('routes/console.php');

        return array_map(fn ($e) => (string) $e->command, $schedule->events());
    }

    /** @param  list<string>  $commands */
    private function countCommand(array $commands, string $needle): int
    {
        return count(array_filter($commands, fn (string $c) => str_contains($c, $needle)));
    }

    public function test_monitoring_mode_registers_recap_once_without_poll_sn_or_technician_progress(): void
    {
        $commands = $this->scheduledCommands('monitoring');

        $this->assertSame(1, $this->countCommand($commands, 'psb:hourly-report'));
        $this->assertSame(0, $this->countCommand($commands, 'psb:poll-sn'));
        $this->assertSame(0, $this->countCommand($commands, 'technician-progress:'));
    }

    public function test_full_mode_registers_recap_once(): void
    {
        $commands = $this->scheduledCommands('full');

        $this->assertSame(1, $this->countCommand($commands, 'psb:hourly-report'));
        $this->assertSame(1, $this->countCommand($commands, 'psb:poll-sn'));
    }
}
