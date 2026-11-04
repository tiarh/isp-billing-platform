<?php

namespace Tests\Feature;

use App\Models\Olt;
use App\Models\OltCollectRun;
use App\Models\OltOnu;
use App\Services\OltCollectorService;
use App\Services\OltZteC300Service;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The 5-minute ZTE SNMP state tick persists the ONU phase code it already reads (status OID
 * 1.3.6.1.4.1.3902.1082.500.10.2.3.8.1.4) as phase_state + phase_collected_at, using the same
 * vocabulary as inventory. Readers of phase_state must not change behaviour.
 */
#[Group('blocking-core')]
class OltSnmpPhaseCodePersistTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-15 03:00:00'));
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

    /** @return array<string, array{0: int, 1: string, 2: string, 3: string}> code, snmp state, phase_state, db state */
    public static function codes(): array
    {
        return [
            'logging' => [1, 'logging', 'logging', 'offline'],
            'los' => [2, 'los', 'LOS', 'offline'],
            'sync' => [3, 'sync', 'syncMib', 'offline'],
            'working' => [4, 'online', 'working', 'online'],
            'dyinggasp' => [5, 'dyinggasp', 'DyingGasp', 'offline'],
            'auth_failed' => [6, 'auth_failed', 'authFailed', 'offline'],
            'offline' => [7, 'offline', 'OffLine', 'offline'],
        ];
    }

    #[DataProvider('codes')]
    public function test_mapping_and_persistence_per_status_code(int $code, string $snmpState, string $phase, string $dbState): void
    {
        $this->assertSame($phase, OltZteC300Service::snmpInventoryPhaseState($code));

        $olt = Olt::create(['name' => 'OLT P', 'code' => 'OLT-P-'.$code, 'vendor' => 'zte_c300']);
        $stale = Carbon::now()->subHour();
        OltOnu::create([
            'olt_id' => $olt->id, 'onu_ref' => '1/1/1:1', 'pon_port' => '1/1/1',
            'state' => 'online', 'phase_state' => 'working', 'state_collected_at' => $stale, 'inventory_collected_at' => $stale,
        ]);

        $run = $this->tick($olt, ['1/1/1:1' => ['state' => $snmpState, 'status_code' => $code]]);

        $this->assertSame('success', $run->status);
        $onu = OltOnu::query()->where('olt_id', $olt->id)->firstOrFail();
        $this->assertSame($dbState, $onu->state);
        $this->assertSame($phase, $onu->phase_state);
        $this->assertTrue($onu->phase_collected_at->equalTo(Carbon::now()));
        $this->assertTrue($onu->state_collected_at->equalTo(Carbon::now()));
        $this->assertTrue($onu->inventory_collected_at->equalTo($stale), 'the tick never touches inventory_collected_at');
    }

    public function test_rows_without_status_code_keep_phase_untouched(): void
    {
        $olt = Olt::create(['name' => 'OLT P', 'code' => 'OLT-P-NC', 'vendor' => 'zte_c300']);
        OltOnu::create(['olt_id' => $olt->id, 'onu_ref' => '1/1/1:1', 'pon_port' => '1/1/1', 'state' => 'online', 'phase_state' => 'LOS']);

        $this->tick($olt, ['1/1/1:1' => ['state' => 'offline']]);

        $onu = OltOnu::query()->where('olt_id', $olt->id)->firstOrFail();
        $this->assertSame('offline', $onu->state);
        $this->assertSame('LOS', $onu->phase_state);
        $this->assertNull($onu->phase_collected_at);
    }

    public function test_missing_rows_created_by_the_tick_get_phase_and_other_olts_are_untouched(): void
    {
        $olt = Olt::create(['name' => 'OLT P', 'code' => 'OLT-P-A', 'vendor' => 'zte_c300']);
        $other = Olt::create(['name' => 'OLT Q', 'code' => 'OLT-P-B', 'vendor' => 'zte_c300']);
        OltOnu::create(['olt_id' => $other->id, 'onu_ref' => '1/1/1:1', 'pon_port' => '1/1/1', 'state' => 'online', 'phase_state' => 'working']);

        $this->tick($olt, ['1/1/1:1' => ['state' => 'los', 'status_code' => 2]]);

        $this->assertSame('LOS', OltOnu::query()->where('olt_id', $olt->id)->firstOrFail()->phase_state);
        $untouched = OltOnu::query()->where('olt_id', $other->id)->firstOrFail();
        $this->assertSame('working', $untouched->phase_state);
        $this->assertNull($untouched->phase_collected_at);
    }

    /**
     * Pin for every phase_state reader (PSB, guided migration, OLT UI, device recovery, lifecycle guard,
     * snapshot builders all go through normalizeOperationalState): the new tick-written phase never
     * changes the operational verdict the tick's own state already gives.
     */
    #[DataProvider('codes')]
    public function test_readers_verdict_is_unchanged_by_the_tick_written_phase(int $code, string $snmpState, string $phase, string $dbState): void
    {
        $this->assertSame(
            OltOnu::normalizeOperationalState($dbState, null),
            OltOnu::normalizeOperationalState($dbState, $phase),
        );
    }

    /** #513 LOW-1: the phase UPDATE only touches rows whose state still equals the state this tick wrote. */
    public function test_phase_update_is_guarded_by_the_state_the_tick_wrote(): void
    {
        $olt = Olt::create(['name' => 'OLT P', 'code' => 'OLT-P-G', 'vendor' => 'zte_c300']);
        OltOnu::create(['olt_id' => $olt->id, 'onu_ref' => '1/1/1:1', 'pon_port' => '1/1/1', 'state' => 'online']);
        OltOnu::create(['olt_id' => $olt->id, 'onu_ref' => '1/1/1:2', 'pon_port' => '1/1/1', 'state' => 'offline']);

        $phaseQueries = [];
        DB::listen(function ($query) use (&$phaseQueries): void {
            if (str_contains($query->sql, 'phase_collected_at') && str_starts_with(strtolower($query->sql), 'update')) {
                $phaseQueries[] = $query;
            }
        });

        $this->tick($olt, [
            '1/1/1:1' => ['state' => 'los', 'status_code' => 2],
            '1/1/1:2' => ['state' => 'online', 'status_code' => 4],
        ]);

        $this->assertNotEmpty($phaseQueries);
        foreach ($phaseQueries as $query) {
            $this->assertMatchesRegularExpression('/ and `?state`? = \?$/', $query->sql, 'phase UPDATE carries a state guard');
        }
        $this->assertSame('LOS', OltOnu::query()->where('onu_ref', '1/1/1:1')->value('phase_state'));
        $this->assertSame('working', OltOnu::query()->where('onu_ref', '1/1/1:2')->value('phase_state'));
    }

    /** A row flipped back to the other state between the state UPDATE and the phase UPDATE keeps its phase. */
    public function test_phase_is_skipped_when_the_row_state_no_longer_matches_the_tick(): void
    {
        $olt = Olt::create(['name' => 'OLT P', 'code' => 'OLT-P-H', 'vendor' => 'zte_c300']);
        OltOnu::create(['olt_id' => $olt->id, 'onu_ref' => '1/1/1:1', 'pon_port' => '1/1/1', 'state' => 'online', 'phase_state' => 'working']);

        // Simulate the inventory per-row write landing right after the state stamp UPDATEs.
        $flipped = false;
        Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$flipped, $olt): void {
            $sql = strtolower($query->sql);
            // The unconditional stamp UPDATE (state_collected_at only) is the last statement before the phase UPDATE.
            if (! $flipped && str_starts_with($sql, 'update') && str_contains($sql, 'state_collected_at') && ! str_contains($sql, 'phase_collected_at') && count($query->bindings) === 4) {
                $flipped = true;
                DB::table('olt_onus')->where('olt_id', $olt->id)->update(['state' => 'online']);
            }
        });

        $this->tick($olt, ['1/1/1:1' => ['state' => 'los', 'status_code' => 2]]);

        $onu = OltOnu::query()->where('olt_id', $olt->id)->firstOrFail();
        $this->assertSame('online', $onu->state);
        $this->assertSame('working', $onu->phase_state, 'phase LOS must not be written onto a row that is online again');
    }

    /** @param array<string, array<string, mixed>> $snmp */
    private function tick(Olt $olt, array $snmp): OltCollectRun
    {
        $service = app(OltCollectorService::class);
        $method = new ReflectionMethod($service, 'persistSnmpStates');
        $method->setAccessible(true);

        return $method->invoke($service, $olt, $snmp);
    }
}
