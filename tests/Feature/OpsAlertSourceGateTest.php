<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Ops\VmAlertService;
use App\Services\OpsAlertService;
use App\Support\OpsAlertSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Ops alerts per source: env flag true keeps today's behaviour; env flag false
 * sends only sources named in Setting ops_alert_sources_enabled and logs the
 * rest once (no message text) with a daily counter.
 */
#[Group('blocking-core')]
class OpsAlertSourceGateTest extends TestCase
{
    use RefreshDatabase;

    private const OPS_JID = '120363000000000000@g.us';

    private const SECRET_TEXT = 'Pelanggan Budi Contoh 081200000011 jalan Mawar';

    private string $fileCachePath = '';

    protected function setUp(): void
    {
        parent::setUp();

        // redis_down keeps its claims in the file store; isolate it per test.
        $this->fileCachePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ops-alert-src-cache-'.Str::uuid();
        config()->set('cache.stores.file.path', $this->fileCachePath);
        config()->set('cache.stores.file.lock_path', $this->fileCachePath);
        Cache::forgetDriver('file');
        config()->set('ops.failed_job_alerts.enabled', true);
        config()->set('services.evolution_api.ops_failed_jobs_threshold', 1);

        Carbon::setTestNow('2026-10-26 10:00:00');
        Cache::flush();
        config()->set('services.evolution_api.ops_alerts_enabled', false);
        config()->set('services.evolution_api.base_url', 'http://evolution.test');
        config()->set('services.evolution_api.api_key', 'test-evolution-key');
        config()->set('services.evolution_api.ops_group_id', self::OPS_JID);
        config()->set('services.evolution_api.delay_ms', 0);
        Http::fake([
            'evolution.test/message/sendText/*' => Http::response(['key' => ['id' => 'abc']], 200),
            '*' => Http::response([], 404),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Cache::forgetDriver('file');
        if ($this->fileCachePath !== '' && is_dir($this->fileCachePath)) {
            File::deleteDirectory($this->fileCachePath);
        }
        parent::tearDown();
    }

    private function sweep(): array
    {
        return $this->ops()->runHealthSweep(true);
    }

    private function insertFailedJob(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'redis',
            'queue' => 'wa',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\SendBillingWaJob'], JSON_THROW_ON_ERROR),
            'exception' => 'x',
            'failed_at' => now(),
        ]);
    }

    private function fakeRedis(array $lengths = []): void
    {
        config()->set('queue.default', 'redis');
        config()->set('ops.enforce_worker_alert', false);
        config()->set('ops.queue_depth.queues', ['default' => 500, 'wa' => 500]);
        $connection = \Mockery::mock();
        $connection->shouldReceive('llen')->andReturnUsing(
            static fn (string $key): int => $lengths[substr($key, strlen('queues:'))] ?? 0
        );
        Redis::shouldReceive('connection')->andReturn($connection);
    }

    /** Held back once with nothing enabled, then sent once when only $source is enabled; no other source counted. */
    private function assertPathUsesSource(string $source, callable $trigger): void
    {
        $trigger();
        $counts = $this->ops()->suppressedCounts(1);
        $this->assertSame(0, $this->sentCount(), $source);
        $this->assertSame(1, $counts[$source], $source);
        $this->assertSame(1, array_sum($counts), $source);

        $this->enable($source);
        $trigger();
        $this->assertSame(1, $this->sentCount(), $source);
        $this->assertSame(1, array_sum($this->ops()->suppressedCounts(1)), $source);
    }

    private function sentCount(): int
    {
        return Http::recorded(static fn (Request $r): bool => str_contains($r->url(), '/message/sendText/'))->count();
    }

    private function enable(string $value): void
    {
        Setting::set(OpsAlertService::SETTING_SOURCES_ENABLED, $value, 'text', 'ops');
    }

    private function ops(): OpsAlertService
    {
        return app(OpsAlertService::class);
    }

    public function test_env_false_and_source_not_enabled_sends_nothing_and_logs_once(): void
    {
        Log::spy();

        $first = $this->ops()->send(self::SECRET_TEXT, 'k1', 300, '', OpsAlertSource::QUEUE_DEPTH);
        $again = $this->ops()->send(self::SECRET_TEXT, 'k1', 300, '', OpsAlertSource::QUEUE_DEPTH);

        $this->assertFalse($first);
        $this->assertFalse($again);
        $this->assertSame(0, $this->sentCount());
        Log::shouldHaveReceived('info')->withArgs(static function ($message, $context = []): bool {
            return $message === '[OpsAlert] suppressed'
                && ($context['source'] ?? null) === 'queue_depth'
                && ($context['message_length'] ?? null) === mb_strlen(self::SECRET_TEXT)
                && strlen((string) ($context['dedupe'] ?? '')) === 8;
        })->once();
        $this->assertSame(1, $this->ops()->suppressedCounts(1)['queue_depth']);
    }

    public function test_suppressed_log_never_contains_the_message_text(): void
    {
        Log::spy();

        $this->ops()->send(self::SECRET_TEXT, 'k1', 300, '', OpsAlertSource::PAYMENT_GATEWAY);

        Log::shouldHaveReceived('info')->withArgs(static function ($message, $context = []): bool {
            $dump = $message.json_encode($context);

            return ! str_contains($dump, 'Budi') && ! str_contains($dump, '0812') && ! str_contains($dump, 'Mawar');
        })->once();
    }

    public function test_dedupe_window_is_per_source_and_key_then_logs_again(): void
    {
        $this->ops()->send('a', 'k1', 300, '', OpsAlertSource::QUEUE_DEPTH);
        $this->ops()->send('a', 'k1', 300, '', OpsAlertSource::QUEUE_DEPTH);
        $this->ops()->send('a', 'k2', 300, '', OpsAlertSource::QUEUE_DEPTH);
        $this->ops()->send('a', 'k1', 300, '', OpsAlertSource::FAILED_JOBS);
        $this->assertSame(2, $this->ops()->suppressedCounts(1)['queue_depth']);
        $this->assertSame(1, $this->ops()->suppressedCounts(1)['failed_jobs']);

        Carbon::setTestNow('2026-10-26 10:06:00');
        $this->ops()->send('a', 'k1', 300, '', OpsAlertSource::QUEUE_DEPTH);

        $this->assertSame(3, $this->ops()->suppressedCounts(1)['queue_depth']);
        $this->assertSame(0, $this->sentCount());
    }

    public function test_counts_cover_seven_days(): void
    {
        $this->ops()->send('a', 'k1', 300, '', OpsAlertSource::OPS_PING);
        Carbon::setTestNow('2026-10-29 10:00:00');
        $this->ops()->send('a', 'k1', 300, '', OpsAlertSource::OPS_PING);

        $this->assertSame(1, $this->ops()->suppressedCounts(1)['ops_ping']);
        $this->assertSame(2, $this->ops()->suppressedCounts(7)['ops_ping']);

        Carbon::setTestNow('2026-11-04 10:00:00');
        $this->assertSame(1, $this->ops()->suppressedCounts(7)['ops_ping']);
    }

    public function test_env_false_and_source_enabled_sends_but_other_sources_do_not(): void
    {
        $this->enable(' VM_Alert , ,queue_depth ');

        $this->assertTrue($this->ops()->send('vm text', '', 300, '', OpsAlertSource::VM_ALERT));
        $this->assertTrue($this->ops()->send('queue text', 'q', 300, '', OpsAlertSource::QUEUE_DEPTH));
        $this->assertFalse($this->ops()->send('other', 'o', 300, '', OpsAlertSource::FAILED_JOBS));
        $this->assertFalse($this->ops()->send('legacy caller without source'));

        $this->assertSame(2, $this->sentCount());
        $this->assertSame(0, $this->ops()->suppressedCounts(1)['vm_alert']);
        $this->assertSame(1, $this->ops()->suppressedCounts(1)['failed_jobs']);
        $this->assertSame(1, $this->ops()->suppressedCounts(1)['general']);
    }

    public function test_all_is_not_a_wildcard(): void
    {
        $this->enable('all');
        $this->assertSame(['all'], $this->ops()->enabledSources());

        $this->assertFalse($this->ops()->send('x', 'k', 300, '', OpsAlertSource::VM_ALERT));
        $this->assertSame(0, $this->sentCount());
    }

    public function test_setting_parse_edge_cases(): void
    {
        $this->assertSame([], $this->ops()->enabledSources());

        $this->enable('');
        $this->assertSame([], $this->ops()->enabledSources());

        $this->enable(' , ,, ');
        $this->assertSame([], $this->ops()->enabledSources());

        $this->enable(' VM_ALERT,vm_alert ,Queue_Depth');
        $this->assertSame(['vm_alert', 'queue_depth'], $this->ops()->enabledSources());
    }

    public function test_env_true_sends_every_source_as_before(): void
    {
        config()->set('services.evolution_api.ops_alerts_enabled', true);
        $this->enable('');
        Log::spy();

        foreach (OpsAlertSource::all() as $i => $source) {
            $this->assertTrue($this->ops()->send('text '.$i, 'k'.$i, 300, '', $source), $source);
        }
        $this->assertTrue($this->ops()->send('legacy'));

        $this->assertSame(count(OpsAlertSource::all()) + 1, $this->sentCount());
        $this->assertSame(0, array_sum($this->ops()->suppressedCounts(7)));
    }

    public function test_queue_depth_sweep_uses_queue_depth_source(): void
    {
        $this->fakeRedis(['default' => 900]);

        $this->assertPathUsesSource(OpsAlertSource::QUEUE_DEPTH, fn () => $this->sweep());
    }

    public function test_redis_down_sweep_uses_redis_down_source(): void
    {
        config()->set('queue.default', 'redis');
        config()->set('ops.enforce_worker_alert', false);
        Redis::shouldReceive('connection')->andThrow(new \RuntimeException('redis down'));

        $this->assertPathUsesSource(OpsAlertSource::REDIS_DOWN, fn () => $this->sweep());
    }

    public function test_enforce_heartbeat_sweep_uses_enforce_heartbeat_source(): void
    {
        $this->fakeRedis();
        config()->set('ops.enforce_worker_alert', true);
        $key = (string) config('services.monitoring.admission_gate.worker_heartbeat_keys.enforce');

        $this->assertPathUsesSource(OpsAlertSource::ENFORCE_HEARTBEAT, function () use ($key): void {
            Cache::forget($key);
            Cache::put('ops.enforce_worker_missing_since', now()->subSeconds(901)->timestamp, now()->addDay());
            $this->sweep();
        });
    }

    public function test_failed_jobs_sweep_uses_failed_jobs_source(): void
    {
        config()->set('queue.default', 'sync');
        $this->insertFailedJob();

        $this->assertPathUsesSource(OpsAlertSource::FAILED_JOBS, fn () => $this->sweep());
    }

    public function test_immediate_failed_job_uses_failed_job_immediate_source(): void
    {
        $this->assertPathUsesSource(
            OpsAlertSource::FAILED_JOB_IMMEDIATE,
            fn () => $this->ops()->reportImmediateJobFailure('App\\Jobs\\IsolateCustomerJob', 'network-enforcement')
        );
    }

    public function test_held_back_failed_jobs_batch_is_counted_once_not_per_sweep(): void
    {
        config()->set('queue.default', 'sync');
        $this->insertFailedJob();

        for ($i = 0; $i < 10; $i++) {
            $this->sweep();
            Carbon::setTestNow(now()->addMinutes(30));
        }
        $this->assertSame(1, $this->ops()->suppressedCounts(7)['failed_jobs']);

        $this->insertFailedJob();
        $this->sweep();

        $this->assertSame(2, $this->ops()->suppressedCounts(7)['failed_jobs']);
        $this->assertSame(0, $this->sentCount());
    }

    public function test_counter_day_is_the_wib_day(): void
    {
        // 2026-10-26 18:00 UTC = 2026-10-27 01:00 WIB
        Carbon::setTestNow('2026-10-26 18:00:00');
        $this->ops()->send('x', 'k', 300, '', OpsAlertSource::OPS_PING);

        $this->assertNotNull(Cache::get('ops_alert_suppressed_count:ops_ping:20261027'));
        $this->assertNull(Cache::get('ops_alert_suppressed_count:ops_ping:20261026'));
    }

    public function test_failing_cache_store_does_not_crash_the_caller(): void
    {
        Cache::partialMock()
            ->shouldReceive('add')->andThrow(new \RuntimeException('cache down'))
            ->shouldReceive('increment')->andThrow(new \RuntimeException('cache down'))
            ->shouldReceive('get')->andThrow(new \RuntimeException('cache down'));

        $this->assertFalse($this->ops()->send('x', 'k', 300, '', OpsAlertSource::QUEUE_DEPTH));
        $this->assertSame(0, $this->sentCount());
        $this->assertSame(0, array_sum($this->ops()->suppressedCounts(1)));
    }

    public function test_vm_alert_service_delivers_with_vm_alert_source(): void
    {
        $service = app(VmAlertService::class);
        $deliver = new \ReflectionMethod($service, 'deliver');

        // not enabled: held back and counted under vm_alert
        $this->assertFalse($deliver->invoke($service, 'on', ['steal'], 'VM text'));
        $this->assertSame(0, $this->sentCount());
        $this->assertSame(1, $this->ops()->suppressedCounts(1)['vm_alert']);

        // enabled by source only: the env flag stays false
        $this->enable('vm_alert');
        $this->assertTrue($deliver->invoke($service, 'on', ['steal'], 'VM text'));
        $this->assertSame(1, $this->sentCount());
        $this->assertFalse(config('services.evolution_api.ops_alerts_enabled'));
    }

    public function test_alert_sources_command_is_read_only_and_lists_counts(): void
    {
        $this->enable('vm_alert');
        $this->ops()->send('x', 'k', 300, '', OpsAlertSource::NETWORK_RECONCILE);

        $this->assertSame(0, Artisan::call('ops:alert-sources'));
        $out = Artisan::output();

        $this->assertStringContainsString('EVOLUTION_API_OPS_ALERTS_ENABLED=false', $out);
        $this->assertStringContainsString('ops_alert_sources_enabled=vm_alert', $out);
        $this->assertMatchesRegularExpression('/network_reconcile\s*\|\s*off\s*\|\s*1\s*\|\s*1/', $out);
        $this->assertMatchesRegularExpression('/vm_alert\s*\|\s*on\s*\|\s*0\s*\|\s*0/', $out);
        $this->assertSame(0, $this->sentCount());
        $this->assertSame(1, $this->ops()->suppressedCounts(1)['network_reconcile']);
    }
}
