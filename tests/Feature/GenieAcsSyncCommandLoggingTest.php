<?php

namespace Tests\Feature;

use App\Services\GenieAcsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-core')]
class GenieAcsSyncCommandLoggingTest extends TestCase
{
    private function fakeResult(int $durationMs): array
    {
        return [
            'synced' => 5,
            'fetched' => 6,
            'not_found' => 1,
            'backfilled_serials' => 2,
            'auto_tagged' => 0,
            'fetch_ms' => 120,
            'duration_ms' => $durationMs,
        ];
    }

    public function test_sync_logs_counts_and_stores_last_sync_in_cache(): void
    {
        Cache::forget('monitoring.genieacs.last_sync');
        Log::spy();
        $this->mock(GenieAcsService::class, function ($mock) {
            $mock->shouldReceive('syncDevices')->once()->andReturn($this->fakeResult(900));
        });

        $this->artisan('genieacs:sync')
            ->expectsOutputToContain('GenieACS snapshot selesai: 5/6 device, 900 ms.')
            ->assertExitCode(0);

        Log::shouldHaveReceived('info')->once()->withArgs(function ($message, $context) {
            return $message === '[GenieACS] snapshot sync done'
                && array_keys($context) === [
                    'synced', 'fetched', 'not_found', 'backfilled_serials',
                    'auto_tagged', 'fetch_ms', 'duration_ms',
                ]
                && $context['synced'] === 5
                && $context['not_found'] === 1;
        });
        Log::shouldNotHaveReceived('warning');

        $cached = Cache::get('monitoring.genieacs.last_sync');
        $this->assertSame(5, $cached['synced']);
        $this->assertSame(900, $cached['duration_ms']);
        $this->assertArrayHasKey('finished_at', $cached);
    }

    public function test_sync_over_sixty_seconds_logs_a_warning(): void
    {
        Log::spy();
        $this->mock(GenieAcsService::class, function ($mock) {
            $mock->shouldReceive('syncDevices')->once()->andReturn($this->fakeResult(61000));
        });

        $this->artisan('genieacs:sync')->assertExitCode(0);

        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn ($message, $context) => $message === '[GenieACS] snapshot sync slow'
                && $context['duration_ms'] === 61000
        );
    }

    public function test_lock_held_logs_notice_and_skips_sync(): void
    {
        Log::spy();
        $this->mock(GenieAcsService::class, function ($mock) {
            $mock->shouldReceive('syncDevices')->never();
        });

        $lock = Cache::lock('genieacs:snapshot-sync', 90);
        $this->assertTrue($lock->get());

        try {
            $this->artisan('genieacs:sync')
                ->expectsOutputToContain('GenieACS sync masih berjalan')
                ->assertExitCode(0);
        } finally {
            $lock->release();
        }

        Log::shouldHaveReceived('notice')->once();
    }

    public function test_failure_logs_duration_and_keeps_failure_exit_code(): void
    {
        Log::spy();
        $this->mock(GenieAcsService::class, function ($mock) {
            $mock->shouldReceive('syncDevices')->once()->andThrow(new \RuntimeException('boom'));
        });

        $this->artisan('genieacs:sync')
            ->expectsOutputToContain('GenieACS snapshot gagal')
            ->assertExitCode(1);

        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn ($message, $context) => $message === '[GenieACS] scheduled snapshot sync failed'
                && $context['error'] === 'boom'
                && array_key_exists('duration_ms', $context)
        );
    }

    public function test_logging_failure_does_not_change_exit_code(): void
    {
        Log::shouldReceive('info')->andThrow(new \RuntimeException('log down'));
        $this->mock(GenieAcsService::class, function ($mock) {
            $mock->shouldReceive('syncDevices')->once()->andReturn($this->fakeResult(900));
        });

        $this->artisan('genieacs:sync')
            ->expectsOutputToContain('GenieACS snapshot selesai')
            ->assertExitCode(0);
    }
}
