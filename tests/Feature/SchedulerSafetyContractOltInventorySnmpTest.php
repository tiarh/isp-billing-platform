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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * OLT RA SNMP inventory (owner approved 2026-10-08). Verified read-only on
 * OLT RA: the 3902.1082.500 tree returns name/description/serial/phase equal
 * to the CLI for all 1223 ONUs in ~6 s. Contract:
 *  - index <ifIndex 0x1101SSPP>.<onuId> decodes to 1/S/P:onuId;
 *  - serial "1,FHTT…" loses its "<n>," prefix; status 4 = online, else offline;
 *  - SNMP is used only when complete and covering >= min coverage of the DB,
 *    otherwise (throttled) SSH fallback;
 *  - generic ONU-… names and empty values never overwrite stored data;
 *  - RA runs over SNMP every 15 min with a daily SSH cross-check.
 */
#[Group('blocking-core')]
#[Group('blocking-billing')]
class SchedulerSafetyContractOltInventorySnmpTest extends TestCase
{
    use RefreshDatabase;

    private const STATE = "OnuIndex   Admin State  OMCC State  Phase State  Channel\n"
        ."--------------------------------------------------------------\n"
        ."1/2/7:5    enable       enable      working      1(GPON)\n"
        ."1/3/1:14   enable       enable      working      1(GPON)\n"
        ."ONU Number: 2/2\n";

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-08 03:00:00'));
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

    public function test_payload_decodes_index_serial_prefix_state_and_fields(): void
    {
        $parsed = (new OltZteC300Service)->parseSnmpInventoryPayload($this->payload([
            '285278727.5' => ['status_code' => 4, 'name' => 'MOC Cust', 'description' => 'Jl. Mawar 1', 'serial' => '1,FHTT0000A004', 'distance_m' => 1520],
            '285278977.14' => ['status_code' => 2, 'name' => 'MIS Cust', 'serial' => 'FHTT0000BEEF'],
            '285278977.15' => ['status_code' => 5],
            '285278977.16' => ['status_code' => 7, 'name' => '  '],
            '1.0' => ['status_code' => 4],
        ]));

        $this->assertNotNull($parsed);
        $byRef = collect($parsed['onus'])->keyBy('onu_ref');
        $this->assertSame(['1/2/7:5', '1/3/1:14', '1/3/1:15', '1/3/1:16'], $byRef->keys()->all(), 'undecodable index skipped');

        $a = $byRef['1/2/7:5'];
        $this->assertSame('1/2/7', $a['pon_port']);
        $this->assertSame('online', $a['state']);
        $this->assertSame('working', $a['phase_state']);
        $this->assertSame('FHTT0000A004', $a['serial_number']);
        $this->assertSame('MOC Cust', $a['onu_name']);
        $this->assertSame('Jl. Mawar 1', $a['onu_description']);
        $this->assertSame(1520, $a['distance_m']);

        $this->assertSame(['offline', 'LOS'], [$byRef['1/3/1:14']['state'], $byRef['1/3/1:14']['phase_state']]);
        $this->assertSame(['offline', 'DyingGasp'], [$byRef['1/3/1:15']['state'], $byRef['1/3/1:15']['phase_state']]);
        $this->assertSame(['offline', 'OffLine'], [$byRef['1/3/1:16']['state'], $byRef['1/3/1:16']['phase_state']]);
        $this->assertNull($byRef['1/3/1:16']['onu_name'], 'blank name is null, never an empty string');
        $this->assertNull($byRef['1/3/1:15']['serial_number']);

        $this->assertNull((new OltZteC300Service)->parseSnmpInventoryPayload('{"ok":false}'));
        $this->assertNull((new OltZteC300Service)->parseSnmpInventoryPayload('not json'));
    }

    public function test_serial_prefix_strip_and_state_map(): void
    {
        $this->assertSame('FHTT0000A004', OltZteC300Service::normalizeSnmpInventorySerial('1,FHTT0000A004'));
        $this->assertSame('FHTT0000A004', OltZteC300Service::normalizeSnmpInventorySerial('STRING: "12,FHTT0000A004"'));
        $this->assertSame('ZTEGC0000001', OltZteC300Service::normalizeSnmpInventorySerial('ZTEGC0000001'));
        $this->assertNull(OltZteC300Service::normalizeSnmpInventorySerial(''));
        $this->assertNull(OltZteC300Service::normalizeSnmpInventorySerial(null));

        $this->assertSame('online', OltZteC300Service::snmpInventoryState(4));
        foreach ([1, 2, 3, 5, 6, 7] as $code) {
            $this->assertSame('offline', OltZteC300Service::snmpInventoryState($code), "code {$code}");
        }
        $this->assertNull(OltZteC300Service::snmpInventoryState(null));
        $this->assertSame('online', OltOnu::normalizeOperationalState('online', 'working'));
        $this->assertSame('offline', OltOnu::normalizeOperationalState('offline', 'LOS'));
    }

    public function test_snmp_inventory_is_used_without_any_ssh_session(): void
    {
        $olt = $this->olt();
        $fake = $this->fake($this->payload([
            '285278727.5' => ['status_code' => 4, 'name' => 'A', 'serial' => '1,FHTT00000001'],
            '285278977.14' => ['status_code' => 5, 'name' => 'B'],
        ]));

        $snapshot = $fake->collectInventorySnapshot($olt, microtime(true) + 130);

        $this->assertSame(1, $fake->snmpCalls);
        $this->assertSame([], $fake->calls, 'no SSH session when SNMP is accepted');
        $this->assertSame('snmp', $snapshot['meta']['source']);
        $this->assertSame('zte-c300-snmp-inventory', $snapshot['meta']['collector']);
        $this->assertSame(1.0, $snapshot['meta']['snmp_coverage']);
        $this->assertCount(2, $snapshot['onus']);
    }

    public function test_low_coverage_falls_back_to_ssh(): void
    {
        $olt = $this->olt();
        for ($i = 1; $i <= 10; $i++) {
            OltOnu::create(['olt_id' => $olt->id, 'onu_ref' => "1/2/7:{$i}", 'pon_port' => '1/2/7', 'state' => 'online']);
        }
        $fake = $this->fake($this->payload([
            '285278727.5' => ['status_code' => 4, 'name' => 'A'],
        ]));

        $snapshot = $fake->collectInventorySnapshot($olt, microtime(true) + 130);

        $this->assertSame('ssh', $snapshot['meta']['source']);
        $this->assertTrue($snapshot['meta']['snmp_fallback']);
        $this->assertStringContainsString('coverage 0.10 below 0.90', $snapshot['meta']['snmp_rejected_reason']);
        $this->assertSame(['show gpon onu state'], $fake->calls[0]['commands']);
        $this->assertCount(2, $snapshot['onus']);
    }

    public function test_empty_or_incomplete_snmp_falls_back_to_ssh(): void
    {
        $fake = $this->fake('');
        $snapshot = $fake->collectInventorySnapshot($this->olt(), microtime(true) + 130);
        $this->assertSame('ssh', $snapshot['meta']['source']);
        $this->assertSame('SNMP returned no ONUs', $snapshot['meta']['snmp_rejected_reason']);

        $this->travel(2)->hours();
        $partial = $this->fake($this->payload(['285278727.5' => ['status_code' => 4]], ['status_partial' => true]));
        $snapshot = $partial->collectInventorySnapshot($this->olt('OLT-RA-2'), microtime(true) + 130);
        $this->assertSame('ssh', $snapshot['meta']['source']);
        $this->assertSame('SNMP status walk incomplete', $snapshot['meta']['snmp_rejected_reason']);
    }

    public function test_fallback_can_be_disabled_and_is_throttled(): void
    {
        $olt = $this->olt();

        config(['services.olt_inventory_sync.snmp_fallback_ssh' => false]);
        $fake = $this->fake('');
        $snapshot = $fake->collectInventorySnapshot($olt, microtime(true) + 130);
        $this->assertSame([], $snapshot['onus']);
        $this->assertSame([], $fake->calls);
        $this->assertStringContainsString('SSH fallback disabled', $snapshot['meta']['warning']);

        config(['services.olt_inventory_sync.snmp_fallback_ssh' => true]);
        $first = $fake->collectInventorySnapshot($olt, microtime(true) + 130);
        $this->assertSame('ssh', $first['meta']['source']);
        $sshSessions = count($fake->calls);

        $second = $fake->collectInventorySnapshot($olt, microtime(true) + 130);
        $this->assertSame([], $second['onus']);
        $this->assertSame($sshSessions, count($fake->calls), 'second fallback within the window opens no SSH session');
        $this->assertStringContainsString('throttle window', $second['meta']['warning']);
    }

    public function test_source_selection_respects_allow_list_and_ssh_override(): void
    {
        $payload = $this->payload(['285278727.5' => ['status_code' => 4, 'name' => 'A']]);

        $ssh = $this->fake($payload)->withInventorySource('ssh');
        $snapshot = $ssh->collectInventorySnapshot($this->olt(), microtime(true) + 130);
        $this->assertSame(0, $ssh->snmpCalls);
        $this->assertSame('ssh', $snapshot['meta']['source']);

        config(['services.olt_inventory_sync.snmp_olt_ids' => []]);
        $auto = $this->fake($payload);
        $notListed = Olt::create(['name' => 'OLT RA test OLT-RA-3', 'code' => 'OLT-RA-3', 'vendor' => 'zte_c300', 'management_ip' => '198.18.2.1']);
        $this->assertSame('ssh', $auto->collectInventorySnapshot($notListed, microtime(true) + 130)['meta']['source']);
        $this->assertSame(0, $auto->snmpCalls);

        $forced = $this->fake($payload)->withInventorySource('snmp');
        $this->assertSame('snmp', $forced->collectInventorySnapshot($this->olt('OLT-RA-4'), microtime(true) + 130)['meta']['source']);
    }

    public function test_persist_keeps_real_names_and_never_writes_empty_values(): void
    {
        $olt = $this->olt();
        OltOnu::create(['olt_id' => $olt->id, 'onu_ref' => '1/2/7:1', 'pon_port' => '1/2/7', 'state' => 'online', 'onu_name' => 'Pelanggan GenieACS', 'name_source' => 'genieacs_tag', 'serial_number' => 'FHTT00000001', 'serial_source' => 'snmp']);
        OltOnu::create(['olt_id' => $olt->id, 'onu_ref' => '1/2/7:2', 'pon_port' => '1/2/7', 'state' => 'online', 'onu_name' => 'Pelanggan Provision', 'name_source' => 'provision']);
        OltOnu::create(['olt_id' => $olt->id, 'onu_ref' => '1/3/1:14', 'pon_port' => '1/3/1', 'state' => 'online', 'onu_name' => null, 'serial_number' => 'FHTT0000BEEF']);
        OltOnu::create(['olt_id' => $olt->id, 'onu_ref' => '1/3/1:15', 'pon_port' => '1/3/1', 'state' => 'offline', 'onu_name' => 'Old Name', 'distance_m' => 900]);

        $this->app->instance(OltZteC300Service::class, $this->fake($this->payload([
            '285278727.1' => ['status_code' => 4, 'name' => 'ONU-1/2/7:1', 'serial' => '1,FHTT00000001'],
            '285278727.2' => ['status_code' => 4, 'name' => 'ONU-2:2'],
            '285278977.14' => ['status_code' => 4, 'name' => 'MIS Cust', 'serial' => ''],
            '285278977.15' => ['status_code' => 7, 'name' => 'New OLT Name', 'distance_m' => 1500],
        ])));

        $run = app(OltCollectorService::class)->runInventorySync($olt, 130, 'snmp');

        $this->assertSame('success', $run->status, (string) $run->error);
        $this->assertSame('snmp', $run->meta['inventory_source']);
        $this->assertSame('snmp_inventory', $run->meta['source']);
        $this->assertSame('zte-c300-snmp-inventory', $run->meta['collector']);

        $row = fn (string $ref) => OltOnu::query()->where('olt_id', $olt->id)->where('onu_ref', $ref)->first();
        $this->assertSame('Pelanggan GenieACS', $row('1/2/7:1')->onu_name, 'generic ONU-… never replaces a real name');
        $this->assertSame('genieacs_tag', $row('1/2/7:1')->name_source);
        $this->assertSame('Pelanggan Provision', $row('1/2/7:2')->onu_name);
        $this->assertSame('MIS Cust', $row('1/3/1:14')->onu_name, 'empty DB name is filled');
        $this->assertSame('snmp_inventory', $row('1/3/1:14')->name_source);
        $this->assertSame('FHTT0000BEEF', $row('1/3/1:14')->serial_number, 'empty SNMP serial never clears a stored one');
        $this->assertSame('New OLT Name', $row('1/3/1:15')->onu_name);
        $this->assertSame('offline', $row('1/3/1:15')->state);
        $this->assertSame('OffLine', $row('1/3/1:15')->phase_state);
        $this->assertSame(1500, (int) $row('1/3/1:15')->distance_m);
        $this->assertSame(4, OltOnu::query()->where('olt_id', $olt->id)->count());
    }

    public function test_command_passes_the_source_option(): void
    {
        $olt = Olt::create(['name' => 'OLT RA (test)', 'code' => 'OLT-RA-CMD', 'vendor' => 'zte_c300', 'management_ip' => '198.18.2.1', 'management_protocol' => 'snmp']);
        $run = new OltCollectRun(['status' => 'success', 'onus_total' => 0, 'onus_updated' => 0, 'meta' => ['inventory_source' => 'snmp']]);

        $mock = Mockery::mock(OltCollectorService::class);
        $mock->shouldReceive('runInventorySync')
            ->once()
            ->withArgs(fn (Olt $o, ?int $budget, ?string $source) => $o->id === $olt->id && $budget === 130 && $source === 'snmp')
            ->andReturn($run);
        $this->app->instance(OltCollectorService::class, $mock);

        $this->artisan('olt:inventory-sync', ['--olt_id' => $olt->id, '--timeout' => 130, '--source' => 'snmp'])
            ->assertSuccessful();
    }

    public function test_ra_runs_over_snmp_every_fifteen_minutes_with_daily_ssh_cross_check(): void
    {
        Queue::fake();
        $events = $this->monitoringModeEvents();

        $snmp = $this->eventByDescription($events, 'Monitoring OLT inventory: OLT RA');
        $this->assertSame('4,19,34,49 * * * *', $snmp->expression);
        $this->assertCount(96, $this->dueTimesOverDay($snmp));
        $snmp->run($this->app);
        Queue::assertPushed(RunMonitoringCollectorJob::class, fn (RunMonitoringCollectorJob $job) => $job->target === 'olt-inv-ra'
            && $job->parameters === ['--olt_id' => 1, '--timeout' => 130, '--source' => 'snmp']
            && $job->timeout === 175);

        $ssh = $this->eventByDescription($events, 'Monitoring OLT inventory: OLT RA (SSH cross-check)');
        $this->assertSame(['03:10'], $this->dueTimesOverDay($ssh));
        $ssh->run($this->app);
        Queue::assertPushed(RunMonitoringCollectorJob::class, fn (RunMonitoringCollectorJob $job) => $job->target === 'olt-inv-ra-ssh'
            && $job->parameters['--source'] === 'ssh'
            && $job->timeout === 175);
    }

    public function test_ra_schedule_is_configurable_and_reverts_to_ssh_when_snmp_disabled(): void
    {
        config([
            'services.olt_inventory_sync.ra_snmp_cron' => '*/10 * * * *',
            'services.olt_inventory_sync.ra_ssh_cron' => 'nonsense',
        ]);
        $events = $this->monitoringModeEvents();
        $this->assertSame('*/10 * * * *', $this->eventByDescription($events, 'Monitoring OLT inventory: OLT RA')->expression);
        $this->assertSame('10 3 * * *', $this->eventByDescription($events, 'Monitoring OLT inventory: OLT RA (SSH cross-check)')->expression, 'invalid cron falls back');

        config(['services.olt_inventory_sync.snmp_olt_ids' => []]);
        Queue::fake();
        $events = $this->monitoringModeEvents();
        $ra = $this->eventByDescription($events, 'Monitoring OLT inventory: OLT RA');
        $this->assertSame('2 1-23/6 * * *', $ra->expression);
        $this->assertSame([], array_values(array_filter($events, fn (Event $e) => $e->description === 'Monitoring OLT inventory: OLT RA (SSH cross-check)')));
        $ra->run($this->app);
        Queue::assertPushed(RunMonitoringCollectorJob::class, fn (RunMonitoringCollectorJob $job) => $job->target === 'olt-inv-ra'
            && ! array_key_exists('--source', $job->parameters));
    }

    public function test_config_defaults(): void
    {
        $this->assertSame([1], config('services.olt_inventory_sync.snmp_olt_ids'));
        $this->assertTrue(config('services.olt_inventory_sync.snmp_fallback_ssh'));
        $this->assertSame(0.9, config('services.olt_inventory_sync.snmp_min_coverage'));
        $this->assertSame('1.3.6.1.4.1.3902.1082.500.10.2.3.3.1.2', config('services.olt_inventory_sync.snmp_oids.name'));
        $this->assertSame('1.3.6.1.4.1.3902.1082.500.10.2.3.3.1.18', config('services.olt_inventory_sync.snmp_oids.serial'));
        $this->assertSame('1.3.6.1.4.1.3902.1082.500.10.2.3.8.1.4', config('services.olt_inventory_sync.snmp_oids.status'));
    }

    /**
     * @param  array<string, array<string, mixed>>  $byIndex
     * @param  array<string, mixed>  $stats
     */
    private function payload(array $byIndex, array $stats = []): string
    {
        $onus = [];
        foreach ($byIndex as $index => $info) {
            $onus['k'.$index] = array_merge(['snmp_index' => (string) $index], $info);
        }

        return (string) json_encode(['ok' => true, 'onus' => $onus, 'stats' => array_merge(['mode' => 'inventory', 'status_partial' => false], $stats)]);
    }

    private function olt(string $code = 'OLT-RA-SNMP'): Olt
    {
        $olt = Olt::create(['name' => 'OLT RA test '.$code, 'code' => $code, 'vendor' => 'zte_c300', 'management_ip' => '198.18.2.1']);
        config(['services.olt_inventory_sync.snmp_olt_ids' => array_values(array_unique(array_merge(
            (array) config('services.olt_inventory_sync.snmp_olt_ids', []),
            [$olt->id],
        )))]);

        return $olt;
    }

    private function fake(string $snmpOutput): OltZteC300Service
    {
        return new class($snmpOutput, self::STATE) extends OltZteC300Service
        {
            public int $snmpCalls = 0;

            /** @var list<array{commands: list<string>, timeout: int}> */
            public array $calls = [];

            public function __construct(private string $snmpOutput, private string $state) {}

            protected function runSnmpInventoryScript(Olt $olt): string
            {
                $this->snmpCalls++;

                return $this->snmpOutput;
            }

            protected function runPexpectCommands(Olt $olt, array $commands, int $timeout): ?array
            {
                $this->calls[] = ['commands' => $commands, 'timeout' => $timeout];
                $out = [];
                foreach ($commands as $command) {
                    $out[$command] = $command === 'show gpon onu state'
                        ? $this->state
                        : "interface gpon-onu_1/2/7:5\n  name SSH Name\n!\n";
                }

                return $out;
            }
        };
    }

    /** @param list<Event> $events */
    private function eventByDescription(array $events, string $description): Event
    {
        $matches = array_values(array_filter($events, fn (Event $e) => $e->description === $description));
        $this->assertCount(1, $matches, "Missing schedule entry: {$description}");

        return $matches[0];
    }

    /** @return list<string> */
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
