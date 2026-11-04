<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\OpsAlertService;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * PR-ALERT (audit O1/O3): failed-job COUNT alert to the ops WA group,
 * immediate alert for failed isolate/reconnect jobs, and a scheduler
 * dead-man ping. The class name contains "SchedulerSafetyContract" so it
 * runs in the blocking CI `test` job filter.
 */
#[Group('blocking-core')]
#[Group('blocking-billing')]
class SchedulerSafetyContractOpsAlertTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET_DETAIL = 'SQLSTATE password=canary-pw customer 0812-0000-0039';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config()->set('queue.default', 'sync');
        config()->set('services.evolution_api.ops_alerts_enabled', true);
        config()->set('services.evolution_api.base_url', 'http://evolution.test');
        config()->set('services.evolution_api.api_key', 'test-evolution-key');
        config()->set('services.evolution_api.ops_group_id', '120363000000000000@g.us');
        config()->set('services.evolution_api.delay_ms', 0);
        config()->set('services.evolution_api.ops_failed_jobs_threshold', 1);
        config()->set('ops.failed_job_alerts.enabled', true);
        config()->set('ops.dead_man.scheduler_url', '');
    }

    public function test_new_failed_jobs_send_one_count_only_message(): void
    {
        $this->fakeWaSender();
        $this->insertFailedJob('network-enforcement', 'App\\Jobs\\IsolateCustomerJob');
        $this->insertFailedJob('network-enforcement', 'App\\Jobs\\IsolateCustomerJob');
        $this->insertFailedJob('wa', 'App\\Jobs\\SendBillingWaJob');

        $report = app(OpsAlertService::class)->runHealthSweep(true);

        $this->assertTrue($report['failed_jobs_alert_sent']);
        $messages = $this->sentWaTexts();
        $this->assertCount(1, $messages);
        $text = $messages[0];
        $this->assertStringContainsString('3 failed job baru', $text);
        $this->assertStringContainsString('- IsolateCustomerJob: 2', $text);
        $this->assertStringContainsString('- SendBillingWaJob: 1', $text);
        $this->assertStringContainsString('Hanya hitungan', $text);
        // Never exception text, payload contents, namespaces or customer data.
        $this->assertStringNotContainsString('SQLSTATE', $text);
        $this->assertStringNotContainsString('canary-pw', $text);
        $this->assertStringNotContainsString('0812', $text);
        $this->assertStringNotContainsString('App\\Jobs', $text);
        $this->assertSame(
            (int) DB::table('failed_jobs')->max('id'),
            (int) Setting::get('ops_failed_jobs_last_alerted_id', 0)
        );

        // Same backlog on the next sweep: nothing new, no second message.
        app(OpsAlertService::class)->runHealthSweep(true);
        $this->assertCount(1, $this->sentWaTexts());
    }

    public function test_only_failures_after_the_last_alert_are_counted_next_time(): void
    {
        $this->fakeWaSender();
        $this->insertFailedJob('wa', 'App\\Jobs\\SendBillingWaJob');
        app(OpsAlertService::class)->runHealthSweep(true);

        $this->insertFailedJob('network-enforcement', 'App\\Jobs\\ReconnectCustomerJob');
        app(OpsAlertService::class)->runHealthSweep(true);

        $messages = $this->sentWaTexts();
        $this->assertCount(2, $messages);
        $this->assertStringContainsString('1 failed job baru', $messages[1]);
        $this->assertStringContainsString('- ReconnectCustomerJob: 1', $messages[1]);
        $this->assertStringNotContainsString('SendBillingWaJob', $messages[1]);
    }

    public function test_undelivered_alert_keeps_the_checkpoint_for_a_retry(): void
    {
        Http::fake(['*' => Http::response(['error' => 'down'], 500)]);
        $this->insertFailedJob('network-enforcement', 'App\\Jobs\\IsolateCustomerJob');

        $report = app(OpsAlertService::class)->runHealthSweep(true);

        $this->assertFalse($report['failed_jobs_alert_sent']);
        $this->assertSame(0, (int) Setting::get('ops_failed_jobs_last_alerted_id', 0));
    }

    public function test_acknowledged_backlog_and_disabled_switch_send_nothing(): void
    {
        $this->fakeWaSender();
        $this->insertFailedJob('wa', 'App\\Jobs\\SendBillingWaJob');
        app(OpsAlertService::class)->acknowledgeExistingFailedJobs();
        app(OpsAlertService::class)->runHealthSweep(true);
        $this->assertCount(0, $this->sentWaTexts());

        config()->set('ops.failed_job_alerts.enabled', false);
        $this->insertFailedJob('network-enforcement', 'App\\Jobs\\IsolateCustomerJob');
        app(OpsAlertService::class)->runHealthSweep(true);
        $this->assertCount(0, $this->sentWaTexts());

        // Dry-run (notify=false) never sends either.
        config()->set('ops.failed_job_alerts.enabled', true);
        app(OpsAlertService::class)->runHealthSweep(false);
        $this->assertCount(0, $this->sentWaTexts());
    }

    public function test_failed_enforcement_job_alerts_immediately_without_exception_text(): void
    {
        $this->fakeWaSender();

        event(new JobFailed('redis', $this->fakeJob('App\\Jobs\\IsolateCustomerJob', 'network-enforcement'), new \RuntimeException(self::SECRET_DETAIL)));
        // Burst of the same class is deduplicated.
        event(new JobFailed('redis', $this->fakeJob('App\\Jobs\\IsolateCustomerJob', 'network-enforcement'), new \RuntimeException(self::SECRET_DETAIL)));
        event(new JobFailed('redis', $this->fakeJob('App\\Jobs\\ReconnectCustomerJob', 'network-enforcement'), new \RuntimeException(self::SECRET_DETAIL)));

        $messages = $this->sentWaTexts();
        $this->assertCount(2, $messages);
        $this->assertStringContainsString('IsolateCustomerJob gagal permanen (queue network-enforcement)', $messages[0]);
        $this->assertStringContainsString('ReconnectCustomerJob gagal permanen', $messages[1]);
        foreach ($messages as $text) {
            $this->assertStringNotContainsString('SQLSTATE', $text);
            $this->assertStringNotContainsString('canary-pw', $text);
            $this->assertStringNotContainsString('0812', $text);
        }
    }

    public function test_non_enforcement_job_failure_waits_for_the_count_sweep(): void
    {
        $this->fakeWaSender();

        event(new JobFailed('redis', $this->fakeJob('App\\Jobs\\SendBillingWaJob', 'wa'), new \RuntimeException(self::SECRET_DETAIL)));

        $this->assertCount(0, $this->sentWaTexts());
    }

    public function test_listener_never_breaks_the_worker_failure_path(): void
    {
        $broken = Mockery::mock(OpsAlertService::class);
        $broken->shouldReceive('reportImmediateJobFailure')->andThrow(new \RuntimeException('boom'));
        $this->app->instance(OpsAlertService::class, $broken);

        event(new JobFailed('redis', $this->fakeJob('App\\Jobs\\IsolateCustomerJob', 'network-enforcement'), new \RuntimeException('x')));

        $this->addToAssertionCount(1);
    }

    public function test_dead_man_pings_at_most_once_per_interval_and_only_http_urls(): void
    {
        Http::fake(['hc-ping.test/*' => Http::response('OK', 200)]);
        $service = app(OpsAlertService::class);

        $this->assertFalse($service->schedulerDeadManConfigured());
        $this->assertFalse($service->pingSchedulerDeadMan());

        config()->set('ops.dead_man.scheduler_url', 'file:///etc/passwd');
        $this->assertFalse($service->schedulerDeadManConfigured());
        $this->assertFalse($service->pingSchedulerDeadMan());
        Http::assertNothingSent();

        config()->set('ops.dead_man.scheduler_url', 'https://hc-ping.test/00000000-0000-0000-0000-000000000000');
        $this->assertTrue($service->schedulerDeadManConfigured());
        $this->assertTrue($service->pingSchedulerDeadMan());
        $this->assertFalse($service->pingSchedulerDeadMan());
        Http::assertSentCount(1);
    }

    public function test_dead_man_failure_is_swallowed(): void
    {
        Http::fake(['hc-ping.test/*' => Http::response('nope', 503)]);
        config()->set('ops.dead_man.scheduler_url', 'https://hc-ping.test/check');

        $this->assertFalse(app(OpsAlertService::class)->pingSchedulerDeadMan());
    }

    public function test_dead_man_is_scheduled_every_minute_in_every_mode_and_gated_by_url(): void
    {
        foreach (['safe', 'monitoring', 'full'] as $mode) {
            $event = $this->deadManEvent($mode);
            $this->assertSame('* * * * *', $event->expression, $mode);

            config()->set('ops.dead_man.scheduler_url', '');
            $this->assertFalse($event->filtersPass($this->app), $mode.': disabled without URL');

            config()->set('ops.dead_man.scheduler_url', 'https://hc-ping.test/check');
            $this->assertTrue($event->filtersPass($this->app), $mode.': enabled with URL');
        }
    }

    public function test_failed_job_sweep_stays_scheduled_in_safe_mode(): void
    {
        $descriptions = array_map(
            static fn (ScheduledEvent $e): string => (string) $e->description,
            $this->scheduledEvents('safe')
        );

        $this->assertContains('Safe scheduler: private ops health only', $descriptions);
    }

    private function deadManEvent(string $mode): ScheduledEvent
    {
        $matches = array_values(array_filter(
            $this->scheduledEvents($mode),
            static fn (ScheduledEvent $e): bool => $e->description === 'Dead-man ping to external healthcheck (scheduler alive)'
        ));
        $this->assertCount(1, $matches, "dead-man entry missing in {$mode} mode");

        return $matches[0];
    }

    /** @return list<ScheduledEvent> */
    private function scheduledEvents(string $mode): array
    {
        config(['services.ebilling_scheduler.mode' => $mode]);

        $schedule = new Schedule;
        $this->app->instance(Schedule::class, $schedule);
        ScheduleFacade::clearResolvedInstance(Schedule::class);

        require base_path('routes/console.php');

        return $schedule->events();
    }

    private function fakeWaSender(): void
    {
        Http::fake([
            'evolution.test/message/sendText/*' => Http::response(['key' => ['id' => (string) Str::uuid()]], 200),
            '*' => Http::response([], 404),
        ]);
    }

    /** @return list<string> */
    private function sentWaTexts(): array
    {
        return Http::recorded(static fn (Request $request): bool => str_contains($request->url(), '/message/sendText/'))
            ->map(static fn (array $pair): string => (string) ($pair[0]->data()['text'] ?? ''))
            ->values()
            ->all();
    }

    private function fakeJob(string $class, string $queue): Job
    {
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('resolveName')->andReturn($class);
        $job->shouldReceive('getQueue')->andReturn($queue);

        return $job;
    }

    private function insertFailedJob(string $queue, string $job): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'redis',
            'queue' => $queue,
            'payload' => json_encode([
                'displayName' => $job,
                'data' => ['command' => 'O:8:"stdClass":1:{s:5:"phone";s:12:"081200000039";}'],
            ], JSON_THROW_ON_ERROR),
            'exception' => self::SECRET_DETAIL,
            'failed_at' => now(),
        ]);
    }
}
