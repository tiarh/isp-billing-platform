<?php

namespace Tests\Feature;

use App\Jobs\ExecuteCustomerMigrationWaJob;
use App\Models\Customer;
use App\Models\CustomerMigration;
use App\Models\DeviceActionLog;
use App\Models\Package;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\MigrationGolden\MigrationGoldenHarness;
use Tests\Support\MigrationGolden\MigrationGoldenWorld;
use Tests\TestCase;

/**
 * Golden m14 (migration safety fix B): a Huawei cross-router run (Krian 15 to Gadang 511, OLT 11) that is run again
 * after the PPPoE copy never copies the secret again. Seven cases, one customer each:
 *  A admin execute after the OLT command failed;  B eksekusi after the OLT write guard stayed dry-run;
 *  C konfirmasi + eksekusi after a failed run;    D eksekusi after a run whose ACS tag is still pending;
 *  E the target secret was removed in between: the run stops before the executing marker, nothing is written;
 *  F a crash at the save of the copy result: the designed resume writes once more, no more;
 *  G the Huawei session is busy on a run that skipped the copy: ledger back to dry_run, a resend works.
 *
 * Fixture: tests/Fixtures/migration-flow/m14-huawei-cross-router-rerun.golden.json, written ONLY with the env
 * MIGRATION_FLOW_GOLDEN_WRITE=1 (never in CI). A golden change needs owner OK.
 */
#[Group('blocking-psb')]
class MigrationFlowGoldenHuaweiRerunTest extends TestCase
{
    use MigrationGoldenHarness;
    use MigrationGoldenWorld;
    use RefreshDatabase;

    private const EXECUTE = 'customer-migrations.device-action.execute';

    private const REASON = 'Admin jalankan migrasi';

    /** @var array<string, array{0: int, 1: int}> pppoe user => expected [adds, disables] over the whole scenario */
    private array $expectedWrites = [];

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
        $name = 'm14-huawei-cross-router-rerun';
        $this->seedWorld();
        $this->scenario();
        $this->compareToGolden($name, $this->encode($this->recordedScenario($name)));

        foreach ($this->expectedWrites as $user => [$adds, $disables]) {
            $this->assertSame($adds, $this->countEvents('mt.addPPPSecret', $user), 'secret adds of '.$user);
            $this->assertSame($disables, $this->countEvents('mt.disablePPPSecret', $user), 'secret disables of '.$user);
        }
    }

    private function countEvents(string $type, string $user): int
    {
        $n = 0;
        foreach ($this->steps as $step) {
            foreach ($step['events'] as $event) {
                if (($event['type'] ?? '') === $type && ($event['user'] ?? null) === $user) {
                    $n++;
                }
            }
        }

        return $n;
    }

    /** A guided Huawei draft from Krian 15 to Gadang 511 with its dry-run; returns the migration id. */
    private function crossDraft(string $case, Customer $customer, string $sn): int
    {
        $this->huawei->autofind = [['serial_number' => $sn, 'pon_port' => '0/0/1', 'vendor_id' => 'HWTC']];
        $this->say($case.': Huawei short form from Krian: draft with the read-only preflight', "#migrasi\nPPPoE: ".$customer->pppoe_user."\nSN baru: ".$sn."\nOLT: Gadang\nRouter lama: Krian\nRouter baru: SKYNET-GADANG");
        $id = $this->lastMigrationId();
        $this->say($case.': konfirmasi: Huawei cross-router dry-run', '#migrasi konfirmasi #'.$id);

        return $id;
    }

    /** First run of a draft: the PPPoE copy is written, then the OLT command fails. */
    private function copyThenFail(string $case, int $id): void
    {
        $this->say($case.': eksekusi: job queued', '#migrasi eksekusi #'.$id);
        $this->huawei->executeThrows = true;
        $this->drainJobs($case.': job: the PPPoE row is copied, then the OLT command fails: ledger failed', null, ExecuteCustomerMigrationWaJob::class);
        $this->huawei->executeThrows = false;
    }

    private function expect(Customer $customer, int $adds, int $disables): void
    {
        $this->expectedWrites[(string) $customer->pppoe_user] = [$adds, $disables];
    }

    private function scenario(): void
    {
        $extra = Customer::create([
            'code' => 'KRN-HADI', 'name' => 'HADI SANTOSO', 'phone' => '081200000017', 'address' => 'RT 1 RW 2 Krian',
            'package_id' => (int) Package::query()->where('code', 'KRIAN-10')->value('id'),
            'pppoe_user' => 'USERHADI_RT01_RW02', 'mikrotik_profile' => '10MB', 'router_id' => self::KRIAN_OLD, 'status' => 'active',
        ]);
        $this->fakeRouterRow($extra, self::KRIAN_OLD);

        // A: copy done, OLT command fails; the admin executes again.
        $a = $this->person('budi');
        $idA = $this->crossDraft('A', $a, 'HWTCC0FFEE51');
        $this->copyThenFail('A', $idA);
        $this->adminPost('A: admin execute on the failed ledger: the copy is only re-read, the OLT plan runs', self::EXECUTE, $idA, ['reason' => self::REASON]);
        $this->expect($a, 1, 1);

        // B: copy done, the OLT write guard stays dry-run (ledger back to dry_run); the next eksekusi.
        $b = $this->person('siti');
        $idB = $this->crossDraft('B', $b, 'HWTCC0FFEE52');
        $this->say('B: eksekusi: job queued', '#migrasi eksekusi #'.$idB);
        $this->huawei->writeBlocked = true;
        $this->drainJobs('B: job: the PPPoE row is copied, the OLT write guard is dry-run: ledger back to dry_run', null, ExecuteCustomerMigrationWaJob::class);
        $this->huawei->writeBlocked = false;
        $this->say('B: eksekusi: job queued', '#migrasi eksekusi #'.$idB);
        $this->drainJobs('B: job: the copy is skipped, the OLT plan runs, success text', null, ExecuteCustomerMigrationWaJob::class);
        $this->expect($b, 1, 1);

        // C: failed, then konfirmasi (retry key) and eksekusi.
        $c = $this->person('dewi');
        $idC = $this->crossDraft('C', $c, 'HWTCC0FFEE53');
        $this->copyThenFail('C', $idC);
        $this->say('C: konfirmasi after the failed run: new dry-run, retry key of the failed ledger', '#migrasi konfirmasi #'.$idC);
        $this->say('C: eksekusi: job queued', '#migrasi eksekusi #'.$idC);
        $this->drainJobs('C: job: the copy is skipped, the OLT plan runs, success text', null, ExecuteCustomerMigrationWaJob::class);
        $this->expect($c, 1, 1);

        // D: success with the ACS tag pending (olt_ok), then eksekusi again for the rest.
        $d = $this->person('agus');
        $idD = $this->crossDraft('D', $d, 'HWTCC0FFEE54');
        $this->say('D: eksekusi: job queued', '#migrasi eksekusi #'.$idD);
        $this->acsFake->tagPending = true;
        $this->drainJobs('D: job: copy and OLT plan done, the ACS tag is pending: olt_ok', null, ExecuteCustomerMigrationWaJob::class);
        $this->acsFake->tagPending = false;
        $this->huawei->autofind = [];
        $this->huawei->registered = [['serial_number' => 'HWTCC0FFEE54', 'pon_port' => '0/0/1', 'vendor_id' => 'HWTC', 'onu_ref' => '0/0/1:0']];
        $this->say('D: eksekusi after olt_ok: continuation dry-run (service-port plan), no job', '#migrasi eksekusi #'.$idD);
        $this->say('D: eksekusi: continuation job queued', '#migrasi eksekusi #'.$idD);
        $this->drainJobs('D: continuation job: the copy is skipped, the service-port plan and the ACS tag run', null, ExecuteCustomerMigrationWaJob::class);
        $this->huawei->registered = [];
        $this->expect($d, 1, 1);

        // E: the target secret is removed in between: the run stops before the executing marker.
        $e = $this->person('andi');
        $idE = $this->crossDraft('E', $e, 'HWTCC0FFEE55');
        $this->copyThenFail('E', $idE);
        $userE = (string) $e->pppoe_user;
        $this->step('fixture: the PPPoE row on the target router is removed', 'fixture:db', function () use ($userE): void {
            unset($this->mt->tables[self::GADANG_ROUTER][$userE]);
        });
        $this->adminPost('E: admin execute with the target secret gone: stops before the executing marker, nothing written', self::EXECUTE, $idE, ['reason' => self::REASON]);
        $this->expect($e, 1, 1);

        // F: crash at the save of the copy result: the designed resume writes once more.
        $f = $this->person('toni');
        $idF = $this->crossDraft('F', $f, 'HWTCC0FFEE56');
        $this->crashOnce(CustomerMigration::class, fn (CustomerMigration $m): bool => $m->isDirty('meta') && data_get($m->meta, 'mikrotik_migration.ok') === true, 'injected crash at the save of the copy result');
        $this->say('F: eksekusi: job queued', '#migrasi eksekusi #'.$idF);
        $this->drainJobs('F: job: the copy is written, the crash hits the save of its result: ledger failed', null, ExecuteCustomerMigrationWaJob::class);
        $this->assertSame(1, $this->crashesFired(), 'the armed crash did not fire');
        $this->say('F: konfirmasi after the crash: new dry-run', '#migrasi konfirmasi #'.$idF);
        $this->say('F: eksekusi: job queued', '#migrasi eksekusi #'.$idF);
        $this->drainJobs('F: job: only the target secret is prepared in the data, so the designed resume writes once more, then the OLT plan runs', null, ExecuteCustomerMigrationWaJob::class);
        $this->expect($f, 2, 2);

        // G: the Huawei session is busy on a run that skipped the copy.
        $idG = $this->crossDraft('G', $extra, 'HWTCC0FFEE57');
        $this->copyThenFail('G', $idG);
        $this->say('G: konfirmasi after the failed run: new dry-run', '#migrasi konfirmasi #'.$idG);
        $this->say('G: eksekusi: job queued', '#migrasi eksekusi #'.$idG);
        $this->huawei->executeBusy = true;
        $this->drainJobs('G: job: the copy is skipped, the OLT session is busy: no OLT command, ledger back to dry_run, busy text', null, ExecuteCustomerMigrationWaJob::class);
        $this->huawei->executeBusy = false;
        $this->say('G: eksekusi again: job queued', '#migrasi eksekusi #'.$idG);
        $this->drainJobs('G: job: the copy is skipped, the OLT plan runs, success text', null, ExecuteCustomerMigrationWaJob::class);
        $this->expect($extra, 1, 1);

        $ledgerStatuses = DeviceActionLog::query()->where('target_type', 'customer_migration')->where('target_id', (string) $idG)->orderBy('id')->pluck('status')->all();
        $this->assertSame('verified', end($ledgerStatuses));

        $this->probeLocksFree(['psb-huawei-olt-'.self::OLT_GADANG]);
    }
}
