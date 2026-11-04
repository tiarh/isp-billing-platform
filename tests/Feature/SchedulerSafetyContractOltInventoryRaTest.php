<?php

namespace Tests\Feature;

use App\Jobs\RunMonitoringCollectorJob;
use App\Models\Olt;
use App\Models\OltCollectRun;
use App\Models\OltOnu;
use App\Services\OltCollectorService;
use App\Services\OltZteC300Service;
use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Audit 2026-10-08 §2.5: `olt:inventory-sync --olt_id=1` (olt-inv-ra) hit the
 * 600s worker timeout nearly every hour, holding worker-ops (and therefore
 * network-enforcement reconnect/isolate jobs) for ~10 min and leaving 218
 * `running` rows + a 3600s collector lock behind. Contract:
 *  - RA inventory runs every 6h on odd Jakarta hours with a bounded job timeout;
 *  - the collector stops at its budget and records a `partial` run;
 *  - orphaned `running` rows are closed and the lock is released.
 */
#[Group('blocking-core')]
#[Group('blocking-billing')]
class SchedulerSafetyContractOltInventoryRaTest extends TestCase
{
    use RefreshDatabase;

    private const RA_DESCRIPTION = 'Monitoring OLT inventory: OLT RA';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-08 03:00:00'));
        // This contract covers the SSH-only RA schedule; the SNMP inventory
        // schedule is SchedulerSafetyContractOltInventorySnmpTest.
        config(['services.olt_inventory_sync.snmp_olt_ids' => []]);
        // olt_collect_runs predates the tracked migrations (created on the
        // production DB directly); mirror the production columns for tests.
        if (! Schema::hasTable('olt_collect_runs')) {
            Schema::create('olt_collect_runs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('olt_id')->index();
                $table->string('tier', 32);
                $table->string('status', 16);
                $table->unsignedInteger('onus_total')->nullable();
                $table->unsignedInteger('onus_updated')->nullable();
                $table->unsignedInteger('duration_ms')->nullable();
                $table->json('meta')->nullable();
                $table->text('error')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();
            });
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_ra_inventory_runs_every_six_hours_off_the_billing_hours(): void
    {
        $event = $this->eventByDescription($this->monitoringModeEvents(), self::RA_DESCRIPTION);

        $this->assertSame('2 1-23/6 * * *', $event->expression);
        $this->assertSame('Asia/Jakarta', $event->timezone);

        $due = $this->dueTimesOverDay($event);
        $this->assertSame(['01:02', '07:02', '13:02', '19:02'], $due, 'RA inventory must run 4x/day on odd hours (never on the even-hour billing pass)');
    }

    public function test_ra_inventory_job_carries_a_bounded_timeout_and_budget(): void
    {
        Queue::fake();
        $event = $this->eventByDescription($this->monitoringModeEvents(), self::RA_DESCRIPTION);
        $event->run($this->app);

        Queue::assertPushed(RunMonitoringCollectorJob::class, function (RunMonitoringCollectorJob $job) {
            return $job->target === 'olt-inv-ra'
                && $job->command === 'olt:inventory-sync'
                && $job->parameters === ['--olt_id' => 1, '--timeout' => 130]
                && $job->timeout === 175
                && $job->timeout < 660;
        });
    }

    public function test_ra_inventory_schedule_and_timeout_are_configurable(): void
    {
        config([
            'services.olt_inventory_sync.ra_cron' => '17 */12 * * *',
            'services.olt_inventory_sync.timeout_seconds' => 100,
            'services.olt_inventory_sync.job_grace_seconds' => 20,
        ]);

        Queue::fake();
        $event = $this->eventByDescription($this->monitoringModeEvents(), self::RA_DESCRIPTION);
        $this->assertSame('17 */12 * * *', $event->expression);

        $event->run($this->app);
        Queue::assertPushed(RunMonitoringCollectorJob::class, fn (RunMonitoringCollectorJob $job) => $job->target === 'olt-inv-ra'
            && $job->parameters['--timeout'] === 100
            && $job->timeout === 120);
    }

    public function test_invalid_ra_cron_falls_back_to_the_default(): void
    {
        config(['services.olt_inventory_sync.ra_cron' => 'every six hours please']);

        $event = $this->eventByDescription($this->monitoringModeEvents(), self::RA_DESCRIPTION);
        $this->assertSame('2 1-23/6 * * *', $event->expression);
    }

    public function test_other_olt_inventories_keep_their_hourly_schedule_and_default_timeout(): void
    {
        Queue::fake();
        $events = $this->monitoringModeEvents();
        $others = [
            'Monitoring OLT inventory: OLT Krian' => '53 * * * *',
            'Monitoring OLT inventory: HiOSO Mangliawan' => '12 * * * *',
            'Monitoring OLT inventory: HiOSO Srigading' => '22 * * * *',
            'Monitoring OLT inventory: HiOSO Martopuro' => '32 * * * *',
        ];
        foreach ($others as $description => $expression) {
            $event = $this->eventByDescription($events, $description);
            $this->assertSame($expression, $event->expression, $description);
            $event->run($this->app);
        }

        Queue::assertPushed(RunMonitoringCollectorJob::class, fn (RunMonitoringCollectorJob $job) => $job->target === 'olt-inv-krian'
            && $job->timeout === 600
            && ! array_key_exists('--timeout', $job->parameters));
    }

    public function test_collector_job_timeout_override_is_clamped_and_shrinks_overlap_lock(): void
    {
        $default = new RunMonitoringCollectorJob('olt', 'olt:inventory-sync', ['--olt_id' => 9], 'olt-inv-krian');
        $this->assertSame(600, $default->timeout);
        $this->assertSame(720, $this->overlapExpiry($default));

        $bounded = new RunMonitoringCollectorJob('olt', 'olt:inventory-sync', ['--olt_id' => 1], 'olt-inv-ra', 175);
        $this->assertSame(175, $bounded->timeout);
        $this->assertSame(295, $this->overlapExpiry($bounded));

        $this->assertSame(30, (new RunMonitoringCollectorJob('olt', 'x', [], 't', 5))->timeout);
        $this->assertSame(600, (new RunMonitoringCollectorJob('olt', 'x', [], 't', 9999))->timeout);
    }

    public function test_budget_exhaustion_records_partial_run_releases_lock_and_reaps_orphans(): void
    {
        $olt = Olt::create(['name' => 'OLT RA (test)', 'code' => 'OLT-RA-INV-TEST', 'vendor' => 'zte_c300']);
        OltOnu::create(['olt_id' => $olt->id, 'onu_ref' => '1/2/1:1', 'pon_port' => '1/2/1', 'state' => 'offline', 'onu_name' => 'Pelanggan A']);

        // Two orphans left by SIGKILLed workers (one hour old) and one run that
        // is still inside the lock TTL and must not be touched.
        $orphan = $this->runningRow($olt, Carbon::now()->subHour());
        $orphan2 = $this->runningRow($olt, Carbon::now()->subHours(6));
        $recent = $this->runningRow($olt, Carbon::now()->subSeconds(30));

        $fake = new class extends OltZteC300Service
        {
            public ?float $deadline = null;

            public function collectInventorySnapshot(Olt $olt, ?float $deadline = null): array
            {
                $this->deadline = $deadline;

                return [
                    'meta' => ['partial' => true, 'detail_total' => 3, 'detail_collected' => 1],
                    'onus' => [
                        ['onu_ref' => '1/2/1:1', 'pon_port' => '1/2/1', 'state' => 'working', 'phase_state' => 'working', 'onu_name' => null, 'serial_number' => null],
                        ['onu_ref' => '1/2/1:2', 'pon_port' => '1/2/1', 'state' => 'working', 'phase_state' => 'working', 'onu_name' => 'Pelanggan B', 'serial_number' => 'ZTEGC0000002'],
                        ['onu_ref' => '1/2/1:3', 'pon_port' => '1/2/1', 'state' => 'offline', 'phase_state' => 'offline', 'onu_name' => null, 'serial_number' => null],
                    ],
                ];
            }
        };
        $this->app->instance(OltZteC300Service::class, $fake);

        $started = microtime(true);
        $run = app(OltCollectorService::class)->runInventorySync($olt, 120);

        $this->assertSame('partial', $run->status);
        $this->assertNotNull($run->finished_at);
        $this->assertStringContainsString('120s budget reached after 1/3 ONU details', (string) $run->error);
        $this->assertSame(3, $run->onus_total);
        $this->assertSame(120, $run->meta['budget_seconds']);
        $this->assertEqualsWithDelta($started + 120, $fake->deadline, 5.0, 'collector receives a deadline = start + budget');

        // State for every ONU persisted; null detail never clobbers a known name.
        $one = OltOnu::query()->where('olt_id', $olt->id)->where('onu_ref', '1/2/1:1')->first();
        $this->assertSame('online', $one->state);
        $this->assertSame('Pelanggan A', $one->onu_name);
        $this->assertSame(3, OltOnu::query()->where('olt_id', $olt->id)->count());

        // Lock released for the next run.
        $lock = Cache::lock('olt-collect:'.$olt->id.':inventory', 10);
        $this->assertTrue($lock->get());
        $lock->release();

        $this->assertSame('failed', $orphan->fresh()->status);
        $this->assertNotNull($orphan->fresh()->finished_at);
        $this->assertStringContainsString('Abandoned', (string) $orphan->fresh()->error);
        $this->assertSame('failed', $orphan2->fresh()->status);
        $this->assertSame('running', $recent->fresh()->status);
    }

    public function test_complete_inventory_still_records_success(): void
    {
        $olt = Olt::create(['name' => 'OLT RA (test)', 'code' => 'OLT-RA-INV-OK', 'vendor' => 'zte_c300']);
        $this->app->instance(OltZteC300Service::class, new class extends OltZteC300Service
        {
            public function collectInventorySnapshot(Olt $olt, ?float $deadline = null): array
            {
                return [
                    'meta' => ['partial' => false, 'detail_total' => 1, 'detail_collected' => 1],
                    'onus' => [['onu_ref' => '1/2/1:1', 'pon_port' => '1/2/1', 'state' => 'working', 'phase_state' => 'working', 'onu_name' => 'X', 'serial_number' => 'ZTEGC0000001']],
                ];
            }
        });

        $run = app(OltCollectorService::class)->runInventorySync($olt);

        $this->assertSame('success', $run->status);
        $this->assertNull($run->error);
        $this->assertSame(130, $run->meta['budget_seconds']);
    }

    public function test_zte_collector_stops_detail_batches_at_the_deadline(): void
    {
        config(['services.olt_inventory_sync.zte_mode' => 'detail']);
        $olt = new Olt(['name' => 'OLT RA (test)', 'vendor' => 'zte_c300']);
        $fake = $this->pexpectFake(20);

        // Plenty of budget: every detail batch runs (3 batches of <= 8).
        $full = $fake->collectInventorySnapshot($olt, microtime(true) + 600);
        $this->assertFalse($full['meta']['partial']);
        $this->assertSame(20, $full['meta']['detail_collected']);
        $this->assertSame(4, count($fake->calls));
        $this->assertCount(20, $full['onus']);

        // Budget nearly spent after the state read: no detail batch is started,
        // but the state snapshot of all ONUs is still returned.
        $fake->calls = [];
        $partial = $fake->collectInventorySnapshot($olt, microtime(true) + 5);
        $this->assertTrue($partial['meta']['partial']);
        $this->assertSame(0, $partial['meta']['detail_collected']);
        $this->assertSame(20, $partial['meta']['detail_total']);
        $this->assertCount(20, $partial['onus']);
        $this->assertSame(1, count($fake->calls));
        $this->assertLessThanOrEqual(10, $fake->calls[0]['timeout'], 'state read is bounded by the remaining budget (floor 10s)');

        // Every per-session timeout respects the remaining budget.
        $fake->calls = [];
        $fake->collectInventorySnapshot($olt, microtime(true) + 40);
        foreach ($fake->calls as $call) {
            $this->assertLessThanOrEqual(40, $call['timeout']);
        }
    }

    private function pexpectFake(int $onus): OltZteC300Service
    {
        return new class($onus) extends OltZteC300Service
        {
            /** @var list<array{commands: list<string>, timeout: int}> */
            public array $calls = [];

            public function __construct(private int $onus) {}

            protected function runPexpectCommands(Olt $olt, array $commands, int $timeout): ?array
            {
                $this->calls[] = ['commands' => $commands, 'timeout' => $timeout];
                $out = [];
                foreach ($commands as $command) {
                    if ($command === 'show gpon onu state') {
                        $lines = [];
                        for ($i = 1; $i <= $this->onus; $i++) {
                            $lines[] = "1/2/1:{$i}    enable       enable      working      1(GPON)";
                        }
                        $out[$command] = implode("\n", $lines);
                    } else {
                        $out[$command] = "Name:                 Cust\nSerial number:        ZTEGC0000001\nONU Distance:         1200m\n";
                    }
                }

                return $out;
            }
        };
    }

    private function runningRow(Olt $olt, Carbon $startedAt): OltCollectRun
    {
        return OltCollectRun::query()->create([
            'olt_id' => $olt->id,
            'tier' => OltCollectRun::TIER_INVENTORY,
            'status' => 'running',
            'started_at' => $startedAt,
        ]);
    }

    private function overlapExpiry(RunMonitoringCollectorJob $job): int
    {
        $middleware = collect($job->middleware())->first(fn ($m) => $m instanceof WithoutOverlapping);
        $this->assertNotNull($middleware);

        return (int) $middleware->expiresAfter;
    }

    /** @param list<Event> $events */
    private function eventByDescription(array $events, string $description): Event
    {
        $matches = array_values(array_filter($events, fn (Event $e) => $e->description === $description));
        $this->assertCount(1, $matches, "Missing schedule entry: {$description}");

        return $matches[0];
    }

    /** @return list<string> Jakarta HH:MM times the entry is due over one day */
    private function dueTimesOverDay(Event $event): array
    {
        $cron = new CronExpression($event->expression);
        $start = CarbonImmutable::create(2026, 10, 6, 0, 0, 0, 'Asia/Jakarta');
        $due = [];
        for ($m = 0; $m < 24 * 60; $m++) {
            $at = $start->addMinutes($m);
            if ($cron->isDue($at, 'Asia/Jakarta')) {
                $due[] = $at->format('H:i');
            }
        }

        return $due;
    }

    /** @return list<Event> */
    private function monitoringModeEvents(): array
    {
        config(['services.ebilling_scheduler.mode' => 'monitoring']);

        $schedule = new Schedule;
        $this->app->instance(Schedule::class, $schedule);
        ScheduleFacade::clearResolvedInstance(Schedule::class);

        require base_path('routes/console.php');

        return $schedule->events();
    }
}
