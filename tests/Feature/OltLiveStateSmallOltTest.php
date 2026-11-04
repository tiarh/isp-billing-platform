<?php

namespace Tests\Feature;

use App\Models\Olt;
use App\Models\OltCollectRun;
use App\Models\OltOnu;
use App\Services\OltCollectorService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Audit Krian 2026-10-08: live SNMP state was only persisted with >= 50 rows
 * or >= 20 online ONUs, so OLT KRIAN (8 ONUs) logged 1869 "skipped" runs and
 * 0 successes. Coverage is now relative to the OLT's known ONU count.
 */
#[Group('blocking-core')]
class OltLiveStateSmallOltTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_small_olt_live_state_is_persisted_with_fresh_timestamps(): void
    {
        $olt = Olt::create(['name' => 'OLT KRIAN (test)', 'code' => 'OLT-KRIAN-TEST', 'vendor' => 'zte_c300']);
        $stale = Carbon::now()->subHour();
        $snmp = [];
        foreach (range(1, 8) as $id) {
            OltOnu::create([
                'olt_id' => $olt->id,
                'onu_ref' => '1/2/1:'.$id,
                'pon_port' => '1/2/1',
                'state' => $id === 7 ? 'offline' : 'online',
                'state_collected_at' => $stale,
                'rx_power_dbm' => $id === 7 ? null : -20.0,
            ]);
            $snmp['1/2/1:'.$id] = ['state' => in_array($id, [7, 8], true) ? 'offline' : 'working'];
        }

        $service = app(OltCollectorService::class);
        $this->assertSame(7, $service->minimumLiveStateRows($olt));

        $run = $this->runStateSync($service, $olt, $snmp, 6);

        $this->assertSame('success', $run->status);
        $this->assertSame('snmp_status', $run->meta['source']);
        $this->assertSame(8, OltOnu::query()->where('olt_id', $olt->id)->where('state_collected_at', Carbon::now())->count(), 'online AND already-offline ONUs get a fresh stamp');
        $eight = OltOnu::query()->where('olt_id', $olt->id)->where('onu_ref', '1/2/1:8')->first();
        $this->assertSame('offline', $eight->state);
        $this->assertNull($eight->rx_power_dbm);
    }

    public function test_large_olt_with_poor_status_coverage_is_still_skipped(): void
    {
        $olt = Olt::create(['name' => 'OLT RA (test)', 'code' => 'OLT-RA-TEST', 'vendor' => 'zte_c300']);
        foreach (range(1, 120) as $id) {
            OltOnu::create(['olt_id' => $olt->id, 'onu_ref' => '1/1/1:'.$id, 'pon_port' => '1/1/1', 'state' => 'online']);
        }
        $snmp = [];
        foreach (range(1, 10) as $id) {
            $snmp['1/1/1:'.$id] = ['state' => 'working'];
        }

        $service = app(OltCollectorService::class);
        $this->assertSame(50, $service->minimumLiveStateRows($olt));

        $run = $this->runStateSync($service, $olt, $snmp, 10);

        $this->assertSame('skipped', $run->status);
    }

    /** @param array<string, array<string, string>> $snmp */
    private function runStateSync(OltCollectorService $service, Olt $olt, array $snmp, int $online): OltCollectRun
    {
        $method = new ReflectionMethod($service, 'runStateSyncSnmpPreferred');
        $method->setAccessible(true);

        return $method->invoke($service, $olt, $snmp, $online);
    }
}
