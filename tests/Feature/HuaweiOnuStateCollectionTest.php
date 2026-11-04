<?php

namespace Tests\Feature;

use App\Jobs\SyncOltOpticalJob;
use App\Models\Olt;
use App\Models\OltCollectRun;
use App\Models\OltOnu;
use App\Services\HuaweiOltSnmpOpticalService;
use App\Services\OltSnapshotBuilder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Audit 2026-10-08 (Gadang, Huawei OLT 11): ONU state was frozen since
 * 19 Sep and RX stayed "sticky" for dead ONTs. No OLT is contacted: the SNMP
 * walk is replaced by fixtures.
 */
#[Group('blocking-core')]
class HuaweiOnuStateCollectionTest extends TestCase
{
    use RefreshDatabase;

    private const IF_NAME_OID = '1.3.6.1.2.1.31.1.1.1.1';

    private const SERIAL_OID = '1.3.6.1.4.1.2011.6.128.1.1.2.43.1.3';

    private const RX_OID = '1.3.6.1.4.1.2011.6.128.1.1.2.51.1.4';

    private const DISTANCE_OID = '1.3.6.1.4.1.2011.6.128.1.1.2.46.1.20';

    private const STATE_OID = '1.3.6.1.4.1.2011.6.128.1.1.2.46.1.15';

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

    public function test_run_state_parser_maps_only_configured_codes(): void
    {
        $service = new HuaweiOltSnmpOpticalService;
        $states = $service->parseRunStateWalk(implode(PHP_EOL, [
            '.'.self::STATE_OID.'.4194304000.1 = INTEGER: 1',
            '.'.self::STATE_OID.'.4194304000.2 = INTEGER: 2',
            '.'.self::STATE_OID.'.4194304000.3 = INTEGER: -1',
            '.'.self::STATE_OID.'.4194304000.4 = INTEGER: 7',
        ]), self::STATE_OID);

        $this->assertSame(['4194304000.1' => 'online', '4194304000.2' => 'offline'], $states);
    }

    public function test_offline_ont_gets_fresh_offline_state_and_its_rx_is_cleared(): void
    {
        config(['services.huawei_olt_snmp.state_enabled' => true]);
        $olt = $this->huaweiOlt();
        $dead = OltOnu::create([
            'olt_id' => $olt->id, 'onu_ref' => '0/0/0:2', 'pon_port' => '0/0/0', 'serial_number' => 'FHTT0000A046',
            'state' => 'online', 'phase_state' => 'normal', 'state_collected_at' => Carbon::parse('2026-09-19 04:32:22'),
            'rx_power_dbm' => -16.64, 'rx_collected_at' => Carbon::now()->subDays(2),
        ]);
        $alive = OltOnu::create([
            'olt_id' => $olt->id, 'onu_ref' => '0/0/0:1', 'pon_port' => '0/0/0', 'serial_number' => 'HWTC00000001',
            'state' => 'online', 'phase_state' => 'normal', 'state_collected_at' => Carbon::parse('2026-09-19 04:32:22'),
            'rx_power_dbm' => -20.00, 'rx_collected_at' => Carbon::now()->subDays(2),
        ]);

        $this->runJob($olt, [
            self::RX_OID => ['.'.self::RX_OID.'.4194304000.1 = INTEGER: -2137'],
            self::STATE_OID => [
                '.'.self::STATE_OID.'.4194304000.1 = INTEGER: 1',
                '.'.self::STATE_OID.'.4194304000.2 = INTEGER: 2',
            ],
        ]);

        $dead->refresh();
        $this->assertSame('offline', $dead->state);
        $this->assertNull($dead->rx_power_dbm);
        $this->assertSame(Carbon::now()->toDateTimeString(), $dead->state_collected_at->toDateTimeString());

        $alive->refresh();
        $this->assertSame('online', $alive->state);
        $this->assertSame(-21.37, (float) $alive->rx_power_dbm);
        $this->assertSame(Carbon::now()->toDateTimeString(), $alive->state_collected_at->toDateTimeString());
        $this->assertSame(Carbon::now()->toDateTimeString(), $alive->rx_collected_at->toDateTimeString());

        $run = OltCollectRun::query()->where('olt_id', $olt->id)->latest('id')->first();
        $this->assertSame('success', $run->status);
        $this->assertSame(2, $run->meta['state_written']);
    }

    public function test_state_stays_untouched_while_oid_is_not_verified_but_dark_ont_rx_is_cleared(): void
    {
        config(['services.huawei_olt_snmp.state_enabled' => false]);
        $olt = $this->huaweiOlt();
        $frozenAt = Carbon::parse('2026-09-19 04:32:22');
        $dark = OltOnu::create([
            'olt_id' => $olt->id, 'onu_ref' => '0/0/0:2', 'pon_port' => '0/0/0',
            'state' => 'online', 'state_collected_at' => $frozenAt, 'rx_power_dbm' => -16.64, 'rx_collected_at' => Carbon::now()->subDays(2),
        ]);
        OltOnu::create([
            'olt_id' => $olt->id, 'onu_ref' => '0/0/0:1', 'pon_port' => '0/0/0',
            'state' => 'online', 'state_collected_at' => $frozenAt, 'rx_power_dbm' => -20.0, 'rx_collected_at' => Carbon::now()->subDays(2),
        ]);

        $this->runJob($olt, [
            self::RX_OID => ['.'.self::RX_OID.'.4194304000.1 = INTEGER: -2137'],
        ]);

        $dark->refresh();
        $this->assertNull($dark->rx_power_dbm, 'ONT absent from a good RX walk is dark');
        $this->assertSame('online', $dark->state, 'state is only written by the verified run-state walk');
        $this->assertSame($frozenAt->toDateTimeString(), $dark->state_collected_at->toDateTimeString());
    }

    public function test_short_rx_walk_does_not_wipe_the_olt(): void
    {
        config(['services.huawei_olt_snmp.state_enabled' => false]);
        $olt = $this->huaweiOlt();
        foreach (range(1, 6) as $id) {
            OltOnu::create([
                'olt_id' => $olt->id, 'onu_ref' => '0/0/0:'.$id, 'pon_port' => '0/0/0',
                'state' => 'online', 'rx_power_dbm' => -20.0, 'rx_collected_at' => Carbon::now()->subMinutes(5),
            ]);
        }

        // 1 of 6 rows < 50% guard => nothing cleared.
        $this->runJob($olt, [
            self::RX_OID => ['.'.self::RX_OID.'.4194304000.1 = INTEGER: -2137'],
        ]);

        $this->assertSame(6, OltOnu::query()->where('olt_id', $olt->id)->whereNotNull('rx_power_dbm')->count());
    }

    private function huaweiOlt(): Olt
    {
        return Olt::create([
            'name' => 'OLT GADANG 3 (test)',
            'code' => 'OLT-GADANG-3-TEST',
            'vendor' => 'huawei_ma5608t',
            'management_ip' => '192.0.2.11',
            'snmp_community' => 'canary-community',
        ]);
    }

    /** @param array<string, list<string>> $walks */
    private function runJob(Olt $olt, array $walks): void
    {
        $walks += [
            self::IF_NAME_OID => ['.'.self::IF_NAME_OID.'.4194304000 = STRING: "GPON 0/0/0"'],
            self::SERIAL_OID => [],
            self::RX_OID => [],
            self::DISTANCE_OID => [],
            self::STATE_OID => [],
        ];
        $fake = new class($walks) extends HuaweiOltSnmpOpticalService
        {
            /** @param array<string, list<string>> $walks */
            public function __construct(private array $walks) {}

            protected function walk(Olt $olt, string $oid): string
            {
                return implode(PHP_EOL, $this->walks[$oid] ?? []);
            }
        };

        (new SyncOltOpticalJob($olt->id))->handle($fake, app(OltSnapshotBuilder::class));
    }
}
