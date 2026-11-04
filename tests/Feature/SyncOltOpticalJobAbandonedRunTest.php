<?php

namespace Tests\Feature;

use App\Jobs\SyncOltOpticalJob;
use App\Models\Olt;
use App\Models\OltCollectRun;
use App\Services\HuaweiOltSnmpOpticalService;
use App\Services\OltSnapshotBuilder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * A worker killed mid-run (deploy recreate, OOM) leaves an olt_collect_runs row in
 * `running`. The redelivered attempt must close it and run a fresh sync.
 */
#[Group('blocking-core')]
class SyncOltOpticalJobAbandonedRunTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-13 10:00:00'));
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

    public function test_stale_running_row_is_closed_and_current_run_succeeds(): void
    {
        $olt = $this->huaweiOlt();
        $stale = $this->runningRow($olt, Carbon::now()->subSeconds(400));

        $this->runJob($olt);

        $stale->refresh();
        $this->assertSame('failed', $stale->status);
        $this->assertStringContainsString('Abandoned', (string) $stale->error);
        $this->assertNotNull($stale->finished_at);

        $current = OltCollectRun::query()->where('olt_id', $olt->id)->latest('id')->first();
        $this->assertNotSame($stale->id, $current->id);
        $this->assertSame('success', $current->status);
    }

    public function test_fresh_running_row_is_left_alone(): void
    {
        $olt = $this->huaweiOlt();
        $fresh = $this->runningRow($olt, Carbon::now()->subSeconds(60));

        $this->runJob($olt);

        $this->assertSame('running', $fresh->refresh()->status);
    }

    public function test_other_tiers_and_other_olts_are_not_reaped(): void
    {
        $olt = $this->huaweiOlt();
        $other = Olt::create([
            'name' => 'Other', 'code' => 'OTHER-OLT', 'vendor' => 'huawei_ma5608t',
            'management_ip' => '192.0.2.12', 'snmp_community' => 'x',
        ]);
        $otherOlt = $this->runningRow($other, Carbon::now()->subSeconds(900));
        $otherTier = $this->runningRow($olt, Carbon::now()->subSeconds(900), 'ssh_inventory');

        $this->runJob($olt);

        $this->assertSame('running', $otherOlt->refresh()->status);
        $this->assertSame('running', $otherTier->refresh()->status);
    }

    public function test_redelivered_attempt_with_lock_held_by_another_owner_runs_nothing(): void
    {
        $olt = $this->huaweiOlt();
        $job = new SyncOltOpticalJob($olt->id);

        $lock = Cache::lock('laravel-queue-overlap:'.SyncOltOpticalJob::class.':olt-collect:'.$olt->id.':huawei', 180);
        $this->assertTrue($lock->get());

        $ran = false;
        $job->middleware()[0]->handle($job, function () use (&$ran): void {
            $ran = true;
        });

        $this->assertFalse($ran, 'lock held elsewhere: job body must be skipped');
        $this->assertSame(0, OltCollectRun::query()->where('olt_id', $olt->id)->count());
        $lock->release();
    }

    private function runningRow(Olt $olt, Carbon $startedAt, string $tier = OltCollectRun::TIER_HUAWEI_OPTICAL): OltCollectRun
    {
        return OltCollectRun::query()->create([
            'olt_id' => $olt->id,
            'tier' => $tier,
            'status' => 'running',
            'started_at' => $startedAt,
        ]);
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

    private function runJob(Olt $olt): void
    {
        $fake = new class extends HuaweiOltSnmpOpticalService
        {
            public function collect(Olt $olt): array
            {
                return ['onus' => [[
                    'onu_ref' => '0/0/0:1',
                    'pon_port' => '0/0/0',
                    'snmp_index' => '4194304000.1',
                    'rx_power_dbm' => -20.0,
                ]], 'meta' => []];
            }
        };

        (new SyncOltOpticalJob($olt->id))->handle($fake, app(OltSnapshotBuilder::class));
    }
}
