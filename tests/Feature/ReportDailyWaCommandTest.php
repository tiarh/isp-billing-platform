<?php

namespace Tests\Feature;

use App\Console\Commands\ReportDailyWaCommand;
use App\Models\Area;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Setting;
use App\Models\WaReportDelivery;
use App\Services\EvolutionApiService;
use App\Services\PsbAreaRecapService;
use App\Services\Report\DailyReportService;
use App\Services\Report\MigrationDailyStats;
use App\Services\Report\ReportDeliveryLedger;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * report:daily-wa: once per WIB day per group (DB ledger), zero day = no send,
 * failure/unknown handling, no PSB health cache touched, no job dispatched.
 */
#[Group('blocking-psb')]
class ReportDailyWaCommandTest extends TestCase
{
    use RefreshDatabase;

    private const R = '120363-skynetreport@g.us';

    private const P = '120363-activation@g.us';

    private const ALERT = '120363-techteam@g.us';

    /** @var list<array{0: string, 1: string}> */
    private array $sent = [];

    /** @var array<string, mixed> */
    private array $sendResult = ['success' => true, 'message_id' => 'WAMID-1'];

    private string $state = 'open';

    /** @var list<string> */
    private array $channels = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->at('2026-10-15 08:03');
        Setting::set('report_wa_group_ids', [self::R]);
        Setting::set('report_daily_enabled', '1');
        Setting::set('psb_wa_group_ids', [self::P]);

        $area = Area::create(['name' => 'SKYNET-KLAMPOK', 'code' => 'klampok']);
        $package = Package::create(['code' => 'P10', 'name' => '10 Mbps', 'price' => 100000]);
        Customer::create([
            'code' => 'RD-1', 'name' => 'Pelanggan Satu', 'address' => 'Dusun Klampok', 'package_id' => $package->id,
            'area_id' => $area->id, 'pppoe_user' => 'userrd1', 'status' => 'active', 'join_date' => '2026-10-14',
        ]);

        $evo = \Mockery::mock(EvolutionApiService::class);
        $evo->shouldReceive('forChannel')->andReturnUsing(function (string $channel) use ($evo) {
            $this->channels[] = $channel;

            return $evo;
        });
        $evo->shouldReceive('getConnectionState')->andReturnUsing(fn () => $this->state);
        $evo->shouldReceive('sendToGroup')->andReturnUsing(function (string $jid, string $text) {
            $this->sent[] = [$jid, $text];

            return $this->sendResult;
        });
        $this->app->instance(EvolutionApiService::class, $evo);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function at(string $wib): void
    {
        $this->travelTo(CarbonImmutable::parse($wib, 'Asia/Jakarta'));
    }

    private function run_(array $args = []): int
    {
        return $this->artisan('report:daily-wa', $args)->run();
    }

    private function row(): ?WaReportDelivery
    {
        return WaReportDelivery::query()->where('group_jid', self::R)->first();
    }

    public function test_sends_once_per_group_across_the_catch_up_hours(): void
    {
        Queue::fake();
        $this->run_();
        $this->at('2026-10-15 09:03');
        $this->run_();
        $this->at('2026-10-15 11:03');
        $this->run_();

        $this->assertCount(1, $this->sent);
        $this->assertSame(self::R, $this->sent[0][0]);
        $this->assertStringContainsString('*LAPORAN HARIAN SKYNET*', $this->sent[0][1]);
        $this->assertStringContainsString('Data: Rabu, 14/10/2026', $this->sent[0][1]);
        $row = $this->row();
        $this->assertSame('sent', $row->status);
        $this->assertSame(1, $row->attempts);
        $this->assertSame('WAMID-1', $row->provider_message_id);
        $this->assertSame(sha1($this->sent[0][1]), $row->message_hash);
        $this->assertSame(['ops'], array_unique($this->channels));
        Queue::assertNothingPushed();
    }

    public function test_report_channel_setting_is_used(): void
    {
        Setting::set('report_wa_channel', 'ticket');

        $this->run_();

        $this->assertSame(['ticket'], array_unique($this->channels));
        $this->assertCount(1, $this->sent);
    }

    public function test_the_conditional_claim_lets_only_one_runner_win(): void
    {
        $ledger = app(ReportDeliveryLedger::class);
        $row = $ledger->ensureRow('daily_recap', '2026-10-14', self::R, 'ops');

        $this->assertTrue($ledger->claim($row, sha1('a'), 'ops'));
        $this->assertFalse($ledger->claim($row, sha1('a'), 'ops'));
        $this->assertSame(1, (int) WaReportDelivery::query()->whereKey($row->id)->value('attempts'));
    }

    public function test_a_row_stuck_in_sending_is_never_sent_automatically(): void
    {
        $ledger = app(ReportDeliveryLedger::class);
        $ledger->claim($ledger->ensureRow('daily_recap', '2026-10-14', self::R, 'ops'), sha1('a'), 'ops');

        $this->run_();

        $this->assertSame([], $this->sent);
        $this->assertSame('sending', $this->row()->status);
    }

    public function test_api_failure_is_retried_hourly_and_stops_after_three_attempts(): void
    {
        $this->sendResult = ['success' => false, 'error' => 'Bad Request'];

        foreach (['08:03', '09:03', '10:03', '11:03'] as $time) {
            $this->at('2026-10-15 '.$time);
            $this->run_();
        }

        $this->assertCount(3, $this->sent);
        $row = $this->row();
        $this->assertSame('failed', $row->status);
        $this->assertSame(3, $row->attempts);
        $this->assertSame('Bad Request', $row->error);
    }

    public function test_connect_failure_is_a_retryable_failure_not_unknown(): void
    {
        $this->sendResult = ['success' => false, 'error' => 'cURL error 7: Failed to connect to evolution port 8085'];

        $this->run_();

        $this->assertSame('failed', $this->row()->status);
    }

    public function test_timeout_is_unknown_and_never_resent_automatically(): void
    {
        $this->sendResult = ['success' => false, 'error' => 'cURL error 28: Operation timed out after 30001 milliseconds'];

        $this->run_();
        $this->at('2026-10-15 09:03');
        $this->run_();

        $this->assertCount(1, $this->sent);
        $this->assertSame('unknown', $this->row()->status);
    }

    public function test_instance_not_open_consumes_no_attempt_and_retries_later(): void
    {
        $this->state = 'close';
        $this->run_();

        $this->assertSame([], $this->sent);
        $this->assertSame('pending', $this->row()->status);
        $this->assertSame(0, $this->row()->attempts);

        $this->state = 'open';
        $this->at('2026-10-15 09:03');
        $this->run_();

        $this->assertCount(1, $this->sent);
    }

    public function test_nothing_outside_the_send_window(): void
    {
        $this->at('2026-10-15 07:59');
        $this->run_();
        $this->at('2026-10-15 12:03');
        $this->run_();

        $this->assertSame([], $this->sent);
        $this->assertSame(0, WaReportDelivery::query()->count());
    }

    public function test_flag_off_or_empty_group_list_is_a_complete_no_op(): void
    {
        Setting::set('report_daily_enabled', '0');
        $this->run_();
        Setting::set('report_daily_enabled', '1');
        Setting::set('report_wa_group_ids', []);
        $this->run_();

        $this->assertSame([], $this->sent);
        $this->assertSame(0, WaReportDelivery::query()->count());
    }

    public function test_overlap_with_a_psb_group_is_skipped_in_the_ledger(): void
    {
        Setting::set('report_wa_group_ids', [self::P]);

        $this->run_();

        $this->assertSame([], $this->sent);
        $row = WaReportDelivery::query()->where('group_jid', self::P)->first();
        $this->assertSame('skipped', $row->status);
        $this->assertSame('overlap_psb_group', $row->error);
    }

    public function test_zero_day_is_not_sent_and_is_recorded_as_skipped(): void
    {
        DB::table('customers')->delete();

        $this->run_();
        $this->at('2026-10-15 09:03');
        $this->run_();

        $this->assertSame([], $this->sent);
        $row = $this->row();
        $this->assertSame('skipped', $row->status);
        $this->assertSame('zero_day', $row->error);
    }

    public function test_dry_run_prints_without_sending_or_writing_the_ledger_at_any_hour(): void
    {
        $this->at('2026-10-15 15:00');

        $this->artisan('report:daily-wa', ['--dry-run' => true, '--date' => '2026-10-14'])
            ->expectsOutputToContain('*LAPORAN HARIAN SKYNET*')
            ->assertExitCode(0);

        $this->assertSame([], $this->sent);
        $this->assertSame(0, WaReportDelivery::query()->count());
    }

    public function test_dry_run_flags_a_zero_day(): void
    {
        DB::table('customers')->delete();

        $this->artisan('report:daily-wa', ['--dry-run' => true])
            ->expectsOutputToContain('ZERO DAY')
            ->assertExitCode(0);
        $this->assertSame([], $this->sent);
    }

    public function test_resend_only_for_failed_unknown_or_stuck_and_never_for_sent(): void
    {
        $ledger = app(ReportDeliveryLedger::class);
        $row = $ledger->ensureRow('daily_recap', '2026-10-14', self::R, 'ops');
        $ledger->claim($row, 'x', 'ops');
        $ledger->markUnknown($row, 'timed out');

        $this->artisan('report:daily-wa', ['--resend' => true])->assertExitCode(1);
        $this->assertSame([], $this->sent);

        $this->artisan('report:daily-wa', ['--resend' => true, '--date' => '2026-10-14'])->assertExitCode(0);
        $this->assertCount(1, $this->sent);
        $this->assertSame('sent', $this->row()->status);

        $this->artisan('report:daily-wa', ['--resend' => true, '--date' => '2026-10-14'])->assertExitCode(0);
        $this->assertCount(1, $this->sent, 'a sent delivery is never resent');
    }

    public function test_a_stale_sending_row_becomes_unknown_but_a_fresh_one_is_left_alone(): void
    {
        $ledger = app(ReportDeliveryLedger::class);
        $old = $ledger->ensureRow('daily_recap', '2026-10-13', self::R, 'ops');
        $ledger->claim($old, 'x', 'ops');
        DB::table('wa_report_deliveries')->where('id', $old->id)->update(['claimed_at' => now()->subMinutes(11)]);
        $fresh = $ledger->ensureRow('daily_recap', '2026-10-14', self::R, 'ops');
        $ledger->claim($fresh, 'x', 'ops');
        DB::table('wa_report_deliveries')->where('id', $fresh->id)->update(['claimed_at' => now()->subMinutes(5)]);

        $this->run_();

        $this->assertSame([], $this->sent);
        $this->assertSame('unknown', WaReportDelivery::query()->find($old->id)->status);
        $this->assertSame('stale_sending', WaReportDelivery::query()->find($old->id)->error);
        $this->assertSame('sending', WaReportDelivery::query()->find($fresh->id)->status);
    }

    public function test_status_shows_stuck_for_a_stale_sending_row(): void
    {
        $ledger = app(ReportDeliveryLedger::class);
        $row = $ledger->ensureRow('daily_recap', '2026-10-14', self::R, 'ops');
        $ledger->claim($row, 'x', 'ops');
        DB::table('wa_report_deliveries')->where('id', $row->id)->update(['claimed_at' => now()->subMinutes(30)]);

        Artisan::call('report:daily-wa', ['--status' => true]);

        $output = Artisan::output();
        $this->assertStringContainsString('STUCK', $output);
        $this->assertStringContainsString('--resend --date=2026-10-14', $output);
    }

    /**
     * @return list<array{0: string, 1: string}> sends to anything but the Report group
     */
    private function alertSends(): array
    {
        return array_values(array_filter($this->sent, fn (array $s): bool => $s[0] !== self::R));
    }

    private function runUntilLastCatchUp(): void
    {
        foreach (['08:03', '09:03', '10:03', '11:03'] as $time) {
            $this->at('2026-10-15 '.$time);
            $this->run_();
        }
    }

    public function test_alert_is_sent_once_to_the_configured_other_group_only_at_the_last_catch_up_run(): void
    {
        $this->sendResult = ['success' => false, 'error' => 'Bad Request'];
        Setting::set('report_undelivered_alert_jid', self::ALERT);
        Setting::set('report_wa_channel', 'ticket');

        foreach (['08:03', '09:03', '10:03'] as $time) {
            $this->at('2026-10-15 '.$time);
            $this->run_();
        }
        $this->assertSame([], $this->alertSends(), 'no alert before the last catch-up run');

        $this->at('2026-10-15 11:03');
        $this->run_();
        $this->run_();

        $alerts = $this->alertSends();
        $this->assertCount(1, $alerts, 'one alert per day and group');
        $this->assertSame(self::ALERT, $alerts[0][0]);
        $this->assertSame('Laporan harian Skynet 14/10/2026 belum terkirim ke grup Report (status: failed). Mohon dicek tim teknis.', $alerts[0][1]);
        $this->assertStringNotContainsString('artisan', $alerts[0][1]);
        $this->assertSame(['ticket'], array_unique($this->channels));
    }

    public function test_empty_alert_jid_sends_no_wa_alert_but_logs_the_error(): void
    {
        $this->sendResult = ['success' => false, 'error' => 'Bad Request'];
        Log::spy();

        $this->runUntilLastCatchUp();

        $this->assertSame([], $this->alertSends());
        Log::shouldHaveReceived('error')
            ->withArgs(fn ($m): bool => is_string($m) && str_contains($m, '[REPORT-DAILY] not delivered'))
            ->once();
    }

    public function test_alert_jid_that_is_a_report_ops_or_psb_group_is_refused(): void
    {
        $this->sendResult = ['success' => false, 'error' => 'Bad Request'];
        Setting::set('wa_report_group_jid', '120363-opsalert@g.us');

        foreach ([self::R, '120363-opsalert@g.us', self::P] as $jid) {
            Setting::set('report_undelivered_alert_jid', $jid);
            $this->sent = [];
            DB::table('wa_report_deliveries')->delete();
            Cache::flush();
            $this->runUntilLastCatchUp();

            $this->assertSame([], $this->alertSends(), 'refused: '.$jid);
            $toReport = array_filter($this->sent, fn (array $s): bool => $s[0] === self::R);
            $this->assertCount(3, $toReport, 'only the 3 report attempts, no alert text: '.$jid);
        }
    }

    public function test_no_alert_for_a_sent_or_zero_day(): void
    {
        Setting::set('report_undelivered_alert_jid', self::ALERT);

        $this->at('2026-10-15 11:03');
        $this->run_();

        $this->assertSame('sent', $this->row()->status);

        DB::table('wa_report_deliveries')->delete();
        DB::table('customers')->delete();
        $this->run_();
        $this->assertSame('skipped', $this->row()->status);
        $this->assertSame([], $this->alertSends());
    }

    public function test_non_connect_transport_errors_are_unknown_and_http_errors_are_failed(): void
    {
        $this->assertTrue(ReportDailyWaCommand::isDeliveryUnknown(['error' => 'cURL error 18: transfer closed with outstanding read data remaining']));
        $this->assertTrue(ReportDailyWaCommand::isDeliveryUnknown(['error' => 'cURL error 92: HTTP/2 stream 1 was not closed cleanly']));
        $this->assertTrue(ReportDailyWaCommand::isDeliveryUnknown(['error' => 'cURL error 35: SSL connect error']));
        $this->assertFalse(ReportDailyWaCommand::isDeliveryUnknown(['error' => 'cURL error 6: Could not resolve host']));
        $this->assertFalse(ReportDailyWaCommand::isDeliveryUnknown(['error' => 'Bad Request']));
        $this->assertFalse(ReportDailyWaCommand::isDeliveryUnknown(['error' => 'Unknown error']));
    }

    public function test_a_live_run_refuses_date(): void
    {
        $this->artisan('report:daily-wa', ['--date' => '2026-10-10'])->assertExitCode(1);

        $this->assertSame([], $this->sent);
        $this->assertSame(0, WaReportDelivery::query()->count());
    }

    public function test_status_option_lists_the_ledger(): void
    {
        $this->run_();

        $this->assertSame(0, Artisan::call('report:daily-wa', ['--status' => true]));
        $output = Artisan::output();
        $this->assertStringContainsString('2026-10-14', $output);
        $this->assertStringContainsString('sent', $output);
    }

    public function test_it_never_touches_the_psb_reply_health_cache(): void
    {
        $this->run_();

        $this->assertNull(Cache::get('psb_wa:last_reply_status'));
        $this->assertNull(Cache::get('psb_wa:last_reply_at'));
        $this->assertNull(Cache::get('psb_wa:last_reply_error'));
    }

    public function test_when_both_sections_fail_nothing_is_sent_and_no_attempt_is_consumed(): void
    {
        $failing = new class(app(PsbAreaRecapService::class), app(MigrationDailyStats::class)) extends DailyReportService
        {
            public function build(CarbonImmutable $day): array
            {
                return ['psb' => null, 'psb_mtd' => null, 'migration' => null, 'errors' => ['psb:X', 'migration:Y']];
            }
        };
        $this->app->instance(DailyReportService::class, $failing);

        $this->artisan('report:daily-wa')->assertExitCode(1);

        $this->assertSame([], $this->sent);
        $row = $this->row();
        $this->assertSame('failed', $row->status);
        $this->assertSame(0, $row->attempts);
        $this->assertStringStartsWith('build_failed:', (string) $row->error);
    }
}
