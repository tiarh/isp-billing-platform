<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\OpsAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class P0ProductionContractRecoveryOpsAlertTest extends TestCase
{
    use RefreshDatabase;

    public function test_acknowledged_backlog_is_visible_without_new_failure_alert(): void
    {
        config()->set('services.evolution_api.ops_alerts_enabled', false);
        $this->insertFailedJob('router-sync', 'App\\Jobs\\SyncRouterJob');
        $this->insertFailedJob('psb', 'App\\Jobs\\ProcessPsbWaJob');

        $service = app(OpsAlertService::class);
        $checkpoint = $service->acknowledgeExistingFailedJobs();
        $report = $service->runHealthSweep(false);

        $this->assertSame(2, $checkpoint['total']);
        $this->assertSame(2, $report['failed_jobs']);
        $this->assertSame(2, $report['failed_jobs_historical']);
        $this->assertSame(0, $report['failed_jobs_new']);
        $this->assertSame(0, $report['failed_jobs_unalerted']);
        $this->assertSame([], $report['alerts']);
    }

    public function test_failure_after_checkpoint_is_new_without_retry_or_delete(): void
    {
        config()->set('services.evolution_api.ops_alerts_enabled', false);
        $this->insertFailedJob('router-sync', 'App\\Jobs\\SyncRouterJob');

        $service = app(OpsAlertService::class);
        $service->acknowledgeExistingFailedJobs();
        $this->insertFailedJob('psb', 'App\\Jobs\\ProcessPsbWaJob');

        $report = $service->runHealthSweep(false);

        $this->assertSame(2, DB::table('failed_jobs')->count());
        $this->assertSame(1, $report['failed_jobs_historical']);
        $this->assertSame(1, $report['failed_jobs_new']);
        $this->assertSame(1, $report['failed_jobs_unalerted']);
        $this->assertContains('failed_jobs_new=1', $report['alerts']);
    }

    public function test_dry_run_acknowledge_does_not_change_checkpoint(): void
    {
        config()->set('services.evolution_api.ops_alerts_enabled', false);
        $this->insertFailedJob('psb', 'App\\Jobs\\ProcessPsbWaJob');

        $this->artisan('ops:health-alert', [
            '--acknowledge-existing' => true,
            '--dry-run' => true,
        ])->assertSuccessful();

        $this->assertSame(0, (int) Setting::get('ops_failed_jobs_ack_id', 0));
        $this->assertSame(1, DB::table('failed_jobs')->count());
    }

    public function test_exception_burst_is_not_broadcast_to_any_group(): void
    {
        Http::fake();

        $service = app(OpsAlertService::class);
        foreach (range(1, 5) as $_) {
            $service->reportException(new \RuntimeException('IsolateCustomerJob.php:73'));
        }

        Http::assertNothingSent();
    }

    public function test_failed_jobs_are_not_broadcast_when_ops_group_is_unconfigured(): void
    {
        config()->set('queue.default', 'sync');
        Http::fake();

        $this->insertFailedJob('network-enforcement', 'App\\Jobs\\IsolateCustomerJob');
        $report = app(OpsAlertService::class)->runHealthSweep(true);

        $this->assertSame(1, $report['failed_jobs']);
        $this->assertSame(1, $report['failed_jobs_new']);
        $this->assertSame(1, $report['failed_jobs_unalerted']);
        $this->assertContains('failed_jobs_new=1', $report['alerts']);
        Http::assertNothingSent();
    }

    private function insertFailedJob(string $queue, string $job): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'redis',
            'queue' => $queue,
            'payload' => json_encode(['displayName' => $job], JSON_THROW_ON_ERROR),
            'exception' => 'Synthetic test failure',
            'failed_at' => now(),
        ]);
    }
}
