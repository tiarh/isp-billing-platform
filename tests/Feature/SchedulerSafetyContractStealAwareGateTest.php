<?php

namespace Tests\Feature;

use App\Services\HostCpuProbe;
use App\Services\MonitoringAdmissionGate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * 2026-10-08 VM102 measurement: load1/8 >= 0.70 held monitoring ~88% of the
 * time while real busy CPU was ~21% and hypervisor steal ~38%. The gate now
 * holds on PSI cpu stall or on load with the steal share removed, and falls
 * back to the raw load rule only when /proc data is unavailable.
 *
 * The class name contains "SchedulerSafetyContract" so it runs in the
 * blocking CI `test` job filter.
 */
#[Group('blocking-core')]
#[Group('blocking-billing')]
class SchedulerSafetyContractStealAwareGateTest extends TestCase
{
    private const CPU_REASONS = ['normalized_load_high', 'load_unavailable', 'cpu_pressure_high', 'steal_adjusted_load_high', 'cpu_usable_capacity_high'];

    private HostCpuProbe $probe;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-10-08 11:00:00');
        Cache::forget('monitoring.admission_gate.cpu_sample');

        Config::set('services.monitoring.admission_gate', [
            'enabled' => true,
            'strict' => true,
            'operator_hold' => false,
            'cooldown_seconds' => 120,
            'heartbeat_required' => false,
            'resource_checks_enabled' => true,
            'critical_queues' => ['psb'],
            'load_threshold' => 0.70,
            'cpu_cores' => 8,
            'cpu_signal' => 'steal_aware',
            'psi_cpu_some_threshold' => 40.0,
            'cpu_usable_busy_threshold' => 80.0,
            'available_memory_bytes' => 1,
            'root_free_bytes' => 1,
            'redis_used_ratio' => 0.70,
        ]);

        Queue::shouldReceive('size')->andReturn(0);
        Redis::shouldReceive('connection')->andReturn(new class
        {
            /** @return array<string, int> */
            public function info(): array
            {
                return ['used_memory' => 1, 'maxmemory' => 0, 'evicted_keys' => 0, 'rejected_connections' => 0];
            }
        });

        $this->probe = new class extends HostCpuProbe
        {
            /** @var list<float>|null */
            public ?array $load = null;

            public ?string $pressure = null;

            /** @var list<string|null> successive /proc/stat reads; the last one repeats */
            public array $stats = [];

            public function loadAverage(): ?array
            {
                return $this->load;
            }

            public function procStat(): ?string
            {
                return count($this->stats) > 1 ? array_shift($this->stats) : ($this->stats[0] ?? null);
            }

            public function pressureCpu(): ?string
            {
                return $this->pressure;
            }
        };
        $this->app->instance(HostCpuProbe::class, $this->probe);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** @param array<string, int> $c */
    private static function stat(array $c): string
    {
        $line = implode(' ', [
            $c['user'] ?? 0, $c['nice'] ?? 0, $c['system'] ?? 0, $c['idle'] ?? 0, $c['iowait'] ?? 0,
            $c['irq'] ?? 0, $c['softirq'] ?? 0, $c['steal'] ?? 0, 0, 0,
        ]);

        return "cpu  {$line}\ncpu0 1 2 3 4 5 6 7 8 0 0\nintr 12345\nprocs_running 9\n";
    }

    private static function psi(float $someAvg60): string
    {
        return sprintf("some avg10=%.2f avg60=%.2f avg300=10.00 total=1\nfull avg10=0.00 avg60=0.00 avg300=0.00 total=0\n", $someAvg60, $someAvg60);
    }

    private const BOOT = ['user' => 1000, 'system' => 500, 'idle' => 5000, 'iowait' => 50, 'softirq' => 100, 'steal' => 3000];

    /**
     * Two gate evaluations 60 s apart; the second one sees the given 60 s
     * delta (8 vCPU = 48000 jiffies) on top of BOOT.
     *
     * @param  array<string, int>  $delta
     * @return array<string, mixed>
     */
    private function evaluateWithDelta(array $delta, float $load1, ?float $psiSomeAvg60): array
    {
        $after = self::BOOT;
        foreach ($delta as $key => $value) {
            $after[$key] = ($after[$key] ?? 0) + $value;
        }

        $this->probe->load = [$load1, $load1, $load1];
        $this->probe->pressure = $psiSomeAvg60 === null ? null : self::psi($psiSomeAvg60);
        $this->probe->stats = [self::stat(self::BOOT), self::stat($after)];

        app(MonitoringAdmissionGate::class)->evaluate('monitoring');
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(60));

        return app(MonitoringAdmissionGate::class)->evaluate('monitoring');
    }

    /** @param array<string, mixed> $decision @return list<string> */
    private static function cpuReasons(array $decision): array
    {
        return array_values(array_intersect($decision['reason_codes'], self::CPU_REASONS));
    }

    public function test_vm102_steal_heavy_load_does_not_hold_monitoring(): void
    {
        // Measured shape: steal 38%, real busy 21%, load1 7.8 on 8 vCPU (raw 0.975).
        $decision = $this->evaluateWithDelta(
            ['user' => 6000, 'system' => 3600, 'softirq' => 480, 'steal' => 18240, 'iowait' => 240, 'idle' => 19440],
            7.8,
            18.2,
        );

        $resources = $decision['checks']['resources'];
        $this->assertSame([], self::cpuReasons($decision));
        $this->assertSame('steal_aware', $resources['cpu_signal']);
        $this->assertEqualsWithDelta(0.975, $resources['normalized_load'], 0.001);
        $this->assertEqualsWithDelta(38.0, $resources['steal_pct'], 0.01);
        $this->assertEqualsWithDelta(21.0, $resources['real_busy_pct'], 0.01);
        $this->assertEqualsWithDelta(18.2, $resources['psi_cpu_some_avg60'], 0.001);
        $this->assertEqualsWithDelta(0.347, $resources['steal_adjusted_load'], 0.002);
        $this->assertSame('delta', $resources['cpu']['stat_window']);
        $this->assertSame(60, $resources['cpu']['stat_window_seconds']);
    }

    public function test_psi_cpu_stall_holds_even_when_steal_adjusted_load_is_low(): void
    {
        $decision = $this->evaluateWithDelta(
            ['user' => 6000, 'system' => 3600, 'softirq' => 480, 'steal' => 18240, 'iowait' => 240, 'idle' => 19440],
            7.8,
            45.0,
        );

        $this->assertSame(['cpu_pressure_high'], self::cpuReasons($decision));
        $this->assertFalse($decision['allowed']);
    }

    public function test_real_cpu_work_still_holds_without_steal(): void
    {
        // Steal 2%, busy 75%, load1 7.2/8: genuine saturation.
        $decision = $this->evaluateWithDelta(
            ['user' => 30000, 'system' => 6000, 'steal' => 960, 'idle' => 11040],
            7.2,
            null,
        );

        $this->assertSame(['steal_adjusted_load_high'], self::cpuReasons($decision));
        $this->assertNull($decision['checks']['resources']['psi_cpu_some_avg60']);
        $this->assertEqualsWithDelta(75.0, $decision['checks']['resources']['real_busy_pct'], 0.01);
    }

    public function test_usable_capacity_rule_holds_when_non_stolen_cpu_is_exhausted(): void
    {
        // Steal 50%, busy 45% of total = 90% of what the hypervisor leaves us.
        $decision = $this->evaluateWithDelta(
            ['user' => 18000, 'system' => 3600, 'steal' => 24000, 'idle' => 2400],
            3.0,
            null,
        );

        $this->assertSame(['cpu_usable_capacity_high'], self::cpuReasons($decision));
        $this->assertEqualsWithDelta(90.0, $decision['checks']['resources']['usable_busy_pct'], 0.01);
    }

    public function test_first_sample_uses_since_boot_counters(): void
    {
        $this->probe->load = [7.8, 7.8, 7.8];
        $this->probe->stats = [self::stat(self::BOOT)];

        $resources = app(MonitoringAdmissionGate::class)->evaluate('monitoring')['checks']['resources'];

        $this->assertSame('since_boot', $resources['cpu']['stat_window']);
        $this->assertEqualsWithDelta(3000 / 9650 * 100, $resources['steal_pct'], 0.01);
        $this->assertSame('steal_aware', $resources['cpu_signal']);
    }

    public function test_counter_reset_rebases_instead_of_negative_delta(): void
    {
        $this->probe->load = [1.0, 1.0, 1.0];
        $this->probe->stats = [
            self::stat(self::BOOT),
            self::stat(['user' => 10, 'idle' => 90]),
        ];

        app(MonitoringAdmissionGate::class)->evaluate('monitoring');
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(60));
        $resources = app(MonitoringAdmissionGate::class)->evaluate('monitoring')['checks']['resources'];

        $this->assertSame('since_boot', $resources['cpu']['stat_window']);
        $this->assertEqualsWithDelta(0.0, $resources['steal_pct'], 0.001);
    }

    public function test_falls_back_to_raw_load_when_proc_is_unavailable(): void
    {
        $this->probe->load = [6.0, 6.0, 6.0];
        $this->probe->stats = [null];
        $this->probe->pressure = null;

        $decision = app(MonitoringAdmissionGate::class)->evaluate('monitoring');

        $this->assertSame(['normalized_load_high'], self::cpuReasons($decision));
        $this->assertSame('load', $decision['checks']['resources']['cpu_signal']);
        $this->assertNull($decision['checks']['resources']['steal_pct']);
    }

    public function test_load_signal_switch_restores_legacy_rule(): void
    {
        Config::set('services.monitoring.admission_gate.cpu_signal', 'load');

        $decision = $this->evaluateWithDelta(
            ['user' => 6000, 'system' => 3600, 'softirq' => 480, 'steal' => 18240, 'iowait' => 240, 'idle' => 19440],
            7.8,
            18.2,
        );

        $this->assertSame(['normalized_load_high'], self::cpuReasons($decision));
        $this->assertSame('load', $decision['checks']['resources']['cpu_signal']);
    }

    public function test_hold_log_explains_cpu_signal_for_noc(): void
    {
        Log::spy();

        $this->evaluateWithDelta(
            ['user' => 6000, 'system' => 3600, 'softirq' => 480, 'steal' => 18240, 'iowait' => 240, 'idle' => 19440],
            7.8,
            45.0,
        );

        Log::shouldHaveReceived('notice')->withArgs(function (string $message, array $context): bool {
            return str_contains($message, 'admission gate held')
                && in_array('cpu_pressure_high', $context['reason_codes'], true)
                && ($context['cpu']['cpu_signal'] ?? null) === 'steal_aware'
                && abs(($context['cpu']['steal_pct'] ?? 0) - 38.0) < 0.01
                && abs(($context['cpu']['real_busy_pct'] ?? 0) - 21.0) < 0.01
                && ($context['cpu']['psi_cpu_some_avg60'] ?? null) === 45.0;
        });
    }

    public function test_config_exposes_steal_aware_defaults(): void
    {
        $config = require base_path('config/services.php');
        $gate = $config['monitoring']['admission_gate'];

        $this->assertSame('steal_aware', $gate['cpu_signal']);
        $this->assertSame(40.0, $gate['psi_cpu_some_threshold']);
        $this->assertSame(80.0, $gate['cpu_usable_busy_threshold']);
    }
}
