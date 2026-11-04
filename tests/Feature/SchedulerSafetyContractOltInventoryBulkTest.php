<?php

namespace Tests\Feature;

use App\Models\Olt;
use App\Models\OltOnu;
use App\Services\OltCollectorService;
use App\Services\OltZteC300Service;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Root cause of the hourly olt-inv-ra timeout (audit 2026-10-08, read-only
 * timing on OLT RA, 1223 ONUs / 46 PONs): the ZTE inventory issued
 * `show gpon onu detail-info` per ONU in batches of 8, each batch a fresh SSH
 * login (~5.5s) — 153 logins, ~14 min. Bulk mode reads state + running-config
 * (names) in two sessions (~19s + ~48s); serial/distance stay on SNMP.
 */
#[Group('blocking-core')]
#[Group('blocking-billing')]
class SchedulerSafetyContractOltInventoryBulkTest extends TestCase
{
    use RefreshDatabase;

    // Same column layout as the real OLT RA output (read-only capture).
    private const STATE = "OnuIndex   Admin State  OMCC State  Phase State  Channel\n"
        ."--------------------------------------------------------------\n"
        ."1/2/1:1    enable       enable      working      1(GPON)\n"
        ."1/2/1:2    enable       enable      working      1(GPON)\n"
        ."1/2/2:1    enable       disable     DyingGasp    1(GPON)\n"
        ."ONU Number: 3/3\n";

    private const RUNNING_CONFIG = "!\ninterface gpon-olt_1/2/1\n  onu 1 type ZTE-F660 sn ZTEGC0000001\n!\n"
        ."interface gpon-onu_1/2/1:1\n  name Budi Contoh\n  tcont 1 profile 100M\n!\n"
        ."interface gpon-onu_1/2/1:2\n  name ONU-2:1\n!\n"
        ."interface gpon-onu_1/2/2:1\n  name Siti Rahma\n!\nend\n";

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-08 03:00:00'));
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

    public function test_bulk_mode_is_the_default_and_never_issues_per_onu_detail_info(): void
    {
        $this->assertSame('bulk', config('services.olt_inventory_sync.zte_mode'));

        $fake = $this->fake(self::STATE, self::RUNNING_CONFIG);
        $snapshot = $fake->collectInventorySnapshot($this->olt(), microtime(true) + 130);

        $this->assertSame([['show gpon onu state'], ['show running-config']], array_column($fake->calls, 'commands'));
        foreach ($fake->calls as $call) {
            $this->assertStringNotContainsString('detail-info', implode(' ', $call['commands']));
        }

        $this->assertSame('bulk', $snapshot['meta']['inventory_mode']);
        $this->assertFalse($snapshot['meta']['partial']);
        $this->assertSame(3, $snapshot['meta']['names_found']);

        $byRef = collect($snapshot['onus'])->keyBy('onu_ref');
        $this->assertCount(3, $byRef);
        $this->assertSame('Budi Contoh', $byRef['1/2/1:1']['onu_name']);
        $this->assertSame('Siti Rahma', $byRef['1/2/2:1']['onu_name']);
        $this->assertSame('1/2/2', $byRef['1/2/2:1']['pon_port']);
        $this->assertNull($byRef['1/2/1:1']['serial_number'], 'serial stays owned by the SNMP tier');
        $this->assertNull($byRef['1/2/1:1']['distance_m']);
    }

    public function test_session_timeouts_respect_the_remaining_budget(): void
    {
        $fake = $this->fake(self::STATE, self::RUNNING_CONFIG);
        $fake->collectInventorySnapshot($this->olt(), microtime(true) + 60);

        $this->assertCount(2, $fake->calls);
        foreach ($fake->calls as $call) {
            $this->assertLessThanOrEqual(60, $call['timeout']);
        }
    }

    public function test_running_config_failure_keeps_state_and_records_partial(): void
    {
        $fake = $this->fake(self::STATE, null);
        $snapshot = $fake->collectInventorySnapshot($this->olt(), microtime(true) + 130);

        $this->assertTrue($snapshot['meta']['partial']);
        $this->assertStringContainsString('running-config returned no ONU names', $snapshot['meta']['partial_reason']);
        $this->assertCount(3, $snapshot['onus']);
        $this->assertNull($snapshot['onus'][0]['onu_name']);
    }

    public function test_exhausted_budget_skips_running_config(): void
    {
        $fake = $this->fake(self::STATE, self::RUNNING_CONFIG);
        $snapshot = $fake->collectInventorySnapshot($this->olt(), microtime(true) + 10);

        $this->assertSame([['show gpon onu state']], array_column($fake->calls, 'commands'));
        $this->assertTrue($snapshot['meta']['partial']);
        $this->assertStringContainsString('budget exhausted', $snapshot['meta']['partial_reason']);
        $this->assertCount(3, $snapshot['onus']);
    }

    public function test_failed_state_read_returns_no_onus(): void
    {
        $fake = $this->fake(null, self::RUNNING_CONFIG);
        $snapshot = $fake->collectInventorySnapshot($this->olt(), microtime(true) + 130);

        $this->assertSame([], $snapshot['onus']);
        $this->assertSame([['show gpon onu state']], array_column($fake->calls, 'commands'));
    }

    public function test_detail_mode_remains_available_as_a_fallback(): void
    {
        config(['services.olt_inventory_sync.zte_mode' => 'detail']);
        $fake = $this->fake(self::STATE, self::RUNNING_CONFIG);
        $snapshot = $fake->collectInventorySnapshot($this->olt(), microtime(true) + 130);

        $this->assertSame('detail', $snapshot['meta']['inventory_mode']);
        $this->assertStringContainsString('detail-info', implode(' ', $fake->calls[1]['commands']));
    }

    public function test_collector_persists_bulk_inventory_without_clobbering_snmp_serials(): void
    {
        $olt = Olt::create(['name' => 'OLT RA (test)', 'code' => 'OLT-RA-BULK', 'vendor' => 'zte_c300']);
        OltOnu::create([
            'olt_id' => $olt->id,
            'onu_ref' => '1/2/1:1',
            'pon_port' => '1/2/1',
            'state' => 'offline',
            'serial_number' => 'ZTEGC0000001',
            'serial_source' => 'snmp',
            'distance_m' => 1500,
            'onu_name' => 'Old Name',
        ]);
        OltOnu::create([
            'olt_id' => $olt->id,
            'onu_ref' => '1/2/1:2',
            'pon_port' => '1/2/1',
            'state' => 'online',
            'onu_name' => 'Provisioned Customer',
            'name_source' => 'provision',
        ]);
        $this->app->instance(OltZteC300Service::class, $this->fake(self::STATE, self::RUNNING_CONFIG));

        $run = app(OltCollectorService::class)->runInventorySync($olt);

        $this->assertSame('success', $run->status, (string) $run->error);
        $this->assertSame('bulk', $run->meta['inventory_mode']);
        $this->assertSame(3, $run->meta['names_found']);

        $one = OltOnu::query()->where('olt_id', $olt->id)->where('onu_ref', '1/2/1:1')->first();
        $this->assertSame('Budi Contoh', $one->onu_name);
        $this->assertSame('ZTEGC0000001', $one->serial_number);
        $this->assertSame('snmp', $one->serial_source);
        $this->assertSame(1500, (int) $one->distance_m);
        $this->assertSame('online', $one->state);

        $two = OltOnu::query()->where('olt_id', $olt->id)->where('onu_ref', '1/2/1:2')->first();
        $this->assertSame('Provisioned Customer', $two->onu_name, 'generic ONU-2:1 never replaces a real name');

        $this->assertSame(3, OltOnu::query()->where('olt_id', $olt->id)->count());
    }

    /**
     * The pexpect collector must not duplicate output that arrives across
     * several 3s expect timeouts (pexpect keeps the unmatched buffer in
     * `before`). Runs only where python3 + pexpect exist (app image); the PHP
     * CI image has no python, so it is skipped there.
     */
    public function test_pexpect_run_command_does_not_duplicate_streamed_output(): void
    {
        $probe = new Process(['python3', '-c', 'import pexpect']);
        try {
            $probe->run();
        } catch (\Throwable) {
            $this->markTestSkipped('python3 not available');
        }
        if (! $probe->isSuccessful()) {
            $this->markTestSkipped('python3 + pexpect not available');
        }

        $script = <<<'PY'
import importlib.util, json, sys
import pexpect
spec = importlib.util.spec_from_file_location("zc", sys.argv[1])
zc = importlib.util.module_from_spec(spec); spec.loader.exec_module(zc)

class FakeChild:
    """Mimics pexpect: `before` is cumulative since the last match."""
    def __init__(self, events):
        self.events = list(events); self.pending = ""; self.before = ""; self.sent = []
    def sendline(self, s): self.sent.append(s)
    def send(self, s): self.sent.append(s)
    def expect(self, patterns, timeout=None):
        kind, data = self.events.pop(0)
        self.pending += data
        self.before = self.pending
        if kind == "timeout":
            return 3
        self.pending = ""
        return {"more": 0, "prompt": 2}[kind]

child = FakeChild([("timeout", "AAA\n"), ("timeout", "BBB\n"), ("more", "CCC\n"), ("timeout", "DDD\n"), ("prompt", "EEE\n")])
print(json.dumps(zc.run_command(child, "show running-config", r"[#>]\s*$")))
PY;

        $process = new Process(['python3', '-c', $script, base_path('app/Support/zte_c300_collect.py')]);
        $process->run();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertSame("AAA\nBBB\nCCC\nDDD\nEEE\n", json_decode(trim($process->getOutput()), true));
    }

    private function olt(): Olt
    {
        return new Olt(['name' => 'OLT RA (test)', 'vendor' => 'zte_c300']);
    }

    private function fake(?string $state, ?string $runningConfig): OltZteC300Service
    {
        return new class($state, $runningConfig) extends OltZteC300Service
        {
            /** @var list<array{commands: list<string>, timeout: int}> */
            public array $calls = [];

            public function __construct(private ?string $state, private ?string $runningConfig) {}

            protected function runPexpectCommands(Olt $olt, array $commands, int $timeout): ?array
            {
                $this->calls[] = ['commands' => $commands, 'timeout' => $timeout];
                $out = [];
                foreach ($commands as $command) {
                    if ($command === 'show gpon onu state') {
                        if ($this->state === null) {
                            return null;
                        }
                        $out[$command] = $this->state;
                    } elseif ($command === 'show running-config') {
                        if ($this->runningConfig === null) {
                            return null;
                        }
                        $out[$command] = $this->runningConfig;
                    } else {
                        $out[$command] = "Name:                 Cust\nSerial number:        ZTEGC0000009\nONU Distance:         1200m\n";
                    }
                }

                return $out;
            }
        };
    }
}
