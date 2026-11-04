<?php

namespace Tests\Feature;

use App\Jobs\ExecuteCustomerMigrationWaJob;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\MigrationGolden\MigrationGoldenHarness;
use Tests\Support\MigrationGolden\MigrationGoldenWorld;
use Tests\TestCase;

/**
 * Golden m13 (migration safety fix A): the global read-only switch holds on the WA lane too.
 *
 *  1 C300 cross-router (Krian 15 to 514): eksekusi while read-only is ON is refused before any job (nothing changes);
 *  2 the switch turned ON after the job was queued: the job stops before the executing marker, ledger blocked;
 *  3 switch OFF, new dry-run, eksekusi: full run (recovery);
 *  4 a half-done migration (PON lock busy): eksekusi refused while ON; a queued continuation stopped, partial state kept;
 *  5 Huawei same-router (Gadang 511): eksekusi refused while ON;
 *  6 admin execute while ON: blocked, red error message.
 *
 * Fixture: tests/Fixtures/migration-flow/m13-readonly-on-wa-lane.golden.json, written ONLY with the env
 * MIGRATION_FLOW_GOLDEN_WRITE=1 (never in CI). A golden change needs owner OK.
 */
#[Group('blocking-psb')]
class MigrationFlowGoldenReadOnlyTest extends TestCase
{
    use MigrationGoldenHarness;
    use MigrationGoldenWorld;
    use RefreshDatabase;

    private const EXECUTE = 'customer-migrations.device-action.execute';

    private const REASON = 'Admin jalankan migrasi';

    private const READ_ONLY = 'control_plane.device_read_only_mode';

    private const DEVICE_WRITES = [
        'mt.addPPPSecret', 'mt.disablePPPSecret', 'mt.renamePPPSecret', 'olt.provisionFiberhome',
        'olt.deleteOnu', 'olt.renameOnu', 'huawei.executePlan', 'acs.ensureTagAfterProvision',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootMigrationGolden();
    }

    protected function tearDown(): void
    {
        $this->shutdownMigrationGolden();
        parent::tearDown();
    }

    public function test_scenario_matches_golden(): void
    {
        $name = 'm13-readonly-on-wa-lane';
        $this->seedWorld();
        $quiet = $this->scenario();
        $this->compareToGolden($name, $this->encode($this->recordedScenario($name)));

        // The steps in which read-only was ON must not have touched a device.
        foreach ($quiet as [$from, $to]) {
            foreach (array_slice($this->steps, $from, $to - $from) as $step) {
                foreach ($step['events'] as $event) {
                    $this->assertNotContains($event['type'] ?? '', self::DEVICE_WRITES, 'device write in step "'.$step['label'].'"');
                }
            }
        }
    }

    /** @return list<array{0: int, 1: int}> step ranges [from, to) that must hold no device write */
    private function scenario(): array
    {
        $quiet = [];
        $this->olt->uncfg = [
            ['sn' => 'FHTTC0FFEE61', 'port' => '1/2/2', 'onu_id' => 3],
            ['sn' => 'FHTTC0FFEE62', 'port' => '1/2/2', 'onu_id' => 4],
            ['sn' => 'FHTTC0FFEE63', 'port' => '1/2/2', 'onu_id' => 5],
        ];

        // 1 + 2 + 3: refusal at the dispatch, stop of a queued job, recovery.
        $a = $this->guidedIntake('draft A: guided intake, automatic dry-run', 'budi', 'FHTTC0FFEE61');
        Setting::set(self::READ_ONLY, '1');
        $from = count($this->steps);
        $this->say('eksekusi while read-only is ON: refused, no job, nothing changes', '#migrasi eksekusi #'.$a);
        $quiet[] = [$from, count($this->steps)];
        Setting::set(self::READ_ONLY, '0');
        $this->say('eksekusi with read-only OFF: job queued', '#migrasi eksekusi #'.$a);
        Setting::set(self::READ_ONLY, '1');
        $from = count($this->steps);
        $this->drainJobs('job runs after read-only was turned ON: stops before the executing marker, ledger blocked, no device call', null, ExecuteCustomerMigrationWaJob::class);
        $quiet[] = [$from, count($this->steps)];
        Setting::set(self::READ_ONLY, '0');
        $this->say('konfirmasi with read-only OFF: new dry-run, retry key of the blocked ledger', '#migrasi konfirmasi #'.$a);
        $this->say('eksekusi: job queued', '#migrasi eksekusi #'.$a);
        $this->drainJobs('job: executes and syncs', null, ExecuteCustomerMigrationWaJob::class);

        // 4: a half-done migration (PON lock busy after the PPPoE step).
        $b = $this->guidedIntake('draft B: guided intake, automatic dry-run', 'siti', 'FHTTC0FFEE62');
        $this->hold('device-olt-pon-'.self::OLT_KRIAN.'-1/2/2');
        $this->say('eksekusi: job queued', '#migrasi eksekusi #'.$b);
        $this->drainJobs('job: the PPPoE step is done, the PON lock is busy: partially_executed', null, ExecuteCustomerMigrationWaJob::class);
        $this->release('device-olt-pon-'.self::OLT_KRIAN.'-1/2/2');
        Setting::set(self::READ_ONLY, '1');
        $from = count($this->steps);
        $this->say('eksekusi on the partial migration while read-only is ON: refused, no continuation dry-run', '#migrasi eksekusi #'.$b);
        $quiet[] = [$from, count($this->steps)];
        Setting::set(self::READ_ONLY, '0');
        $this->say('eksekusi with read-only OFF: continuation dry-run, no job', '#migrasi eksekusi #'.$b);
        $this->say('eksekusi: continuation job queued', '#migrasi eksekusi #'.$b);
        Setting::set(self::READ_ONLY, '1');
        $from = count($this->steps);
        $this->drainJobs('continuation job runs after read-only was turned ON: stops before the executing marker, partial state kept', null, ExecuteCustomerMigrationWaJob::class);
        $quiet[] = [$from, count($this->steps)];
        Setting::set(self::READ_ONLY, '0');

        // 6: admin execute while read-only is ON.
        $c = $this->guidedIntake('draft C: guided intake, automatic dry-run', 'dewi', 'FHTTC0FFEE63');
        Setting::set(self::READ_ONLY, '1');
        $from = count($this->steps);
        $this->adminPost('admin execute while read-only is ON: ledger blocked, red error message, no device call', self::EXECUTE, $c, ['reason' => self::REASON]);
        $quiet[] = [$from, count($this->steps)];
        Setting::set(self::READ_ONLY, '0');

        // 5: Huawei same-router.
        $gadangPackage = (int) Package::query()->where('code', 'GADANG-200M')->value('id');
        $same = Customer::create([
            'code' => 'GDG-61', 'name' => 'DRAFTC WIJAYA', 'phone' => '081200000061', 'address' => 'RT 1 RW 2 Gadang', 'package_id' => $gadangPackage,
            'pppoe_user' => 'USERANDI_USERGDG_RT01_RW02', 'mikrotik_profile' => 'GADANG-200M', 'router_id' => self::GADANG_ROUTER, 'status' => 'active',
        ]);
        $this->fakeRouterRow($same, self::GADANG_ROUTER, 'GADANG-200M');
        $this->huawei->autofind = [['serial_number' => 'HWTCC0FFEE61', 'pon_port' => '0/0/1', 'vendor_id' => 'HWTC']];
        $this->say('Huawei short form: draft with the read-only preflight', "#migrasi\nPPPoE: ".$same->pppoe_user."\nSN baru: HWTCC0FFEE61\nOLT: Gadang");
        $h = $this->lastMigrationId();
        $this->say('konfirmasi: Huawei dry-run', '#migrasi konfirmasi #'.$h);
        Setting::set(self::READ_ONLY, '1');
        $from = count($this->steps);
        $this->say('Huawei eksekusi while read-only is ON: refused, no job', '#migrasi eksekusi #'.$h);
        $quiet[] = [$from, count($this->steps)];
        Setting::set(self::READ_ONLY, '0');

        $this->probeLocksFree(['device-olt-pon-'.self::OLT_KRIAN.'-1/2/2', 'psb-huawei-olt-'.self::OLT_GADANG]);

        return $quiet;
    }
}
