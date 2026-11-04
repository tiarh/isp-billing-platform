<?php

namespace Tests\Feature;

use App\Jobs\ExecuteCustomerMigrationWaJob;
use App\Models\Customer;
use App\Models\CustomerMigration;
use App\Models\DeviceActionLog;
use App\Models\Package;
use App\Models\Setting;
use App\Services\CustomerMigrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\MigrationGolden\MigrationGoldenHarness;
use Tests\Support\MigrationGolden\MigrationGoldenWorld;
use Tests\TestCase;

/**
 * Characterization goldens of the migration device-action ledger, part 3 (migration refactor M3-A, tests only).
 *
 * m09-m11 pin what the ledger, the idempotency keys and the partial-execution state do around a failure: a
 * crash after the PPPoE step, a ledger left in "executing", a double "eksekusi", a switch turned off between
 * the queue and the job, a continuation that cannot be prepared, and the Huawei OLT-only ledger edges. Every
 * step goes through a public entry (the WA job, the admin web routes, a public service method); no name that
 * M3 moves is reflected. Parts 1 and 2 are MigrationFlowGoldenTest and MigrationFlowGoldenEdgesTest.
 *
 * Fixtures: tests/Fixtures/migration-flow/<scenario>.golden.json, written ONLY with the env
 * MIGRATION_FLOW_GOLDEN_WRITE=1 (never in CI). A golden change needs owner OK.
 */
#[Group('blocking-psb')]
class MigrationFlowGoldenLedgerTest extends TestCase
{
    use MigrationGoldenHarness;
    use MigrationGoldenWorld;
    use RefreshDatabase;

    private const EXECUTE = 'customer-migrations.device-action.execute';

    private const PREPARE = 'customer-migrations.device-action.prepare';

    private const REASON = 'Admin jalankan migrasi';

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

    /** @return array<string, array{0: string}> */
    public static function scenarios(): array
    {
        $names = [
            'm09-ledger-recovery-and-idempotency',
            'm10-double-approval-kill-switch-and-blocked-continuation',
            'm11-huawei-ledger-edges',
        ];

        return array_combine($names, array_map(fn (string $n): array => [$n], $names));
    }

    #[DataProvider('scenarios')]
    public function test_scenario_matches_golden(string $name): void
    {
        $this->seedWorld();
        $this->{'scenario_'.str_replace('-', '_', $name)}();
        $this->compareToGolden($name, $this->encode($this->recordedScenario($name)));
    }

    private function service(): CustomerMigrationService
    {
        return app(CustomerMigrationService::class);
    }

    /** A step that changes the database directly (a worker killed, a row removed), so the golden shows it. */
    private function fixtureStep(string $label, \Closure $change): void
    {
        $this->step($label, 'fixture:db', $change);
    }

    private function setLedgerStatus(int $migrationId, string $status): void
    {
        DeviceActionLog::query()
            ->where('target_type', 'customer_migration')
            ->where('target_id', (string) $migrationId)
            ->where('action', 'migration-device-action-dry-run')
            ->update(['status' => $status, 'executed_at' => now()]);
    }

    /**
     * m09: ledger recovery and idempotency keys (C300 cross-router Krian 15 to 514).
     * Draft A: reuse, blocked, retry key of a blocked ledger, crash before the OLT step, retry key of a failed ledger.
     * Draft B: crash at the very last save, after every device step: the continuation is blocked and an admin closes it.
     * Draft C: a ledger left "executing" by a killed worker, and every entry that must refuse to touch it.
     */
    private function scenario_m09_ledger_recovery_and_idempotency(): void
    {
        $this->olt->uncfg = [
            ['sn' => 'FHTTC0FFEE91', 'port' => '1/2/2', 'onu_id' => 3],
            ['sn' => 'FHTTC0FFEE92', 'port' => '1/2/2', 'onu_id' => 4],
            ['sn' => 'FHTTC0FFEE93', 'port' => '1/2/2', 'onu_id' => 5],
        ];

        $a = $this->guidedIntake('draft A: guided intake, automatic dry-run (base idempotency key)', 'budi', 'FHTTC0FFEE91');
        $this->adminPost('admin prepare again: the ready dry-run is reused, no new ledger', self::PREPARE, $a, ['reason' => 'Admin cek dry-run migrasi']);
        Setting::set('migration_device_actions_enabled', '0');
        $this->adminPost('admin execute with the migration switch OFF: ledger blocked, no device call', self::EXECUTE, $a, ['reason' => self::REASON]);
        Setting::set('migration_device_actions_enabled', '1');
        $this->adminPost('admin prepare after the block: new dry-run ledger, retry key of the blocked ledger', self::PREPARE, $a, ['reason' => 'Admin cek dry-run migrasi']);

        $this->crashOnce(CustomerMigration::class, fn (CustomerMigration $m): bool => $m->isDirty('new_pon_port') && filled($m->new_pon_port), 'injected crash while the uncfg port is saved');
        $this->adminPost('admin execute: crash after the PPPoE step and before the OLT step: ledger failed, partial state saved, no WA message', self::EXECUTE, $a, ['reason' => self::REASON]);
        $this->assertSame(1, $this->crashesFired(), 'the armed crash of draft A did not fire');
        $this->say('cek after the crash: partial lines', '#migrasi cek #'.$a);
        $this->say('eksekusi on the failed ledger: continuation dry-run with the retry key of the failed ledger, no job', '#migrasi eksekusi #'.$a);
        $this->say('eksekusi: continuation job queued', '#migrasi eksekusi #'.$a);
        $this->drainJobs('continuation job: PPPoE step skipped, OLT provisioned, synced', null, ExecuteCustomerMigrationWaJob::class);

        $b = $this->guidedIntake('draft B: guided intake, automatic dry-run', 'siti', 'FHTTC0FFEE92');
        $this->crashOnce(CustomerMigration::class, fn (CustomerMigration $m): bool => $m->isDirty('status') && $m->status === CustomerMigration::STATUS_SYNCED, 'injected crash at the final synced save');
        $this->say('eksekusi: job queued', '#migrasi eksekusi #'.$b);
        $this->drainJobs('job: every device step done, the final synced save crashes: ledger failed, stop text, partial state saved', null, ExecuteCustomerMigrationWaJob::class);
        $this->assertSame(2, $this->crashesFired(), 'the armed crash of draft B did not fire');
        $this->say('eksekusi on the failed ledger after every device step ran: the continuation dry-run is blocked as an identity conflict', '#migrasi eksekusi #'.$b);
        $this->say('eksekusi again: same blocked state, no job, the same reply is not sent twice', '#migrasi eksekusi #'.$b);
        $this->callPublic('manual complete by an admin closes the stuck migration (database only, no device call)', 'public:markSyncedManually', fn () => $this->service()->markSyncedManually($b, [], 'Dicek manual: ONU dan PPPoE sudah online.', $this->admin()));
        $this->say('cek after the manual complete', '#migrasi cek #'.$b);

        $c = $this->guidedIntake('draft C: guided intake, automatic dry-run', 'dewi', 'FHTTC0FFEE93');
        $this->fixtureStep('fixture: a worker was killed while the ledger was executing (status set in the database)', fn () => $this->setLedgerStatus($c, 'executing'));
        $this->adminPost('admin execute on an executing ledger: refused, not reconciled', self::EXECUTE, $c, ['reason' => self::REASON]);
        $this->adminPost('admin prepare on an executing ledger: refused, still executing', self::PREPARE, $c, ['reason' => 'Admin cek dry-run migrasi']);
        $this->say('eksekusi: job queued (the approval still says dry_run_ready)', '#migrasi eksekusi #'.$c);
        $this->drainJobs('job on an executing ledger: stops safe, no device call', null, ExecuteCustomerMigrationWaJob::class);
        $this->say('sn command while the ledger is executing: refused, SN unchanged', '#migrasi sn #'.$c.' FHTTC0FFEE94');
        $this->callPublic('auto reconcile while the ledger is executing: refused', 'public:markSyncedByAutoReconcile', fn () => $this->service()->markSyncedByAutoReconcile($c, ['sn_source' => 'new_sn'], 'run-9', []));
        $this->callPublic('manual complete while the ledger is executing: refused', 'public:markSyncedManually', fn () => $this->service()->markSyncedManually($c, [], 'Dicek manual oleh admin', $this->admin()));
        $this->say('batal after the execution was queued: refused', '#migrasi batal #'.$c);

        $this->probeLocksFree();
    }

    /**
     * m10: a double "eksekusi", switches turned off between the queue and the job, and a half-done migration
     * whose continuation is blocked, held, refused to be re-sent and finally force-cancelled.
     */
    private function scenario_m10_double_approval_kill_switch_and_blocked_continuation(): void
    {
        $this->olt->uncfg = [
            ['sn' => 'FHTTC0FFEEA1', 'port' => '1/2/2', 'onu_id' => 3],
            ['sn' => 'FHTTC0FFEEA2', 'port' => '1/2/2', 'onu_id' => 4],
        ];

        $a = $this->guidedIntake('draft A: guided intake, automatic dry-run', 'budi', 'FHTTC0FFEEA1');
        $this->say('eksekusi: first job queued', '#migrasi eksekusi #'.$a);
        $this->say('eksekusi again before the first job ran: no second job (the unique job lock holds), the same reply is not sent twice', '#migrasi eksekusi #'.$a);
        $this->drainJobs('first job: executes and syncs', 1, ExecuteCustomerMigrationWaJob::class);
        $this->drainJobs('drain again: no second job exists, nothing runs', null, ExecuteCustomerMigrationWaJob::class);

        $b = $this->guidedIntake('draft B: guided intake, automatic dry-run', 'siti', 'FHTTC0FFEEA2');
        $this->say('eksekusi: job queued', '#migrasi eksekusi #'.$b);
        Setting::set('migration_wa_only_live_enabled', '0');
        $this->drainJobs('job runs after WA-only live was turned OFF: live guard stop, no device call', null, ExecuteCustomerMigrationWaJob::class);
        Setting::set('migration_wa_only_live_enabled', '1');
        $this->say('eksekusi again: job queued', '#migrasi eksekusi #'.$b);
        Setting::set('migration_device_actions_enabled', '0');
        $this->drainJobs('job runs after the migration device switch was turned OFF: the WA-only run is still allowed, executes and syncs', null, ExecuteCustomerMigrationWaJob::class);
        Setting::set('migration_device_actions_enabled', '1');

        // Draft C: a typo SN, so the OLT step cannot finish and the migration stays half done.
        $c = $this->guidedIntake('draft C: guided intake with a typo SN, automatic dry-run', 'dewi', 'FHTTC0FFEEA9');
        $user = (string) $this->person('dewi')->pppoe_user;
        $this->say('eksekusi: job queued', '#migrasi eksekusi #'.$c);
        $this->drainJobs('job: PPPoE step done, OLT misses the typo, partially_executed', null, ExecuteCustomerMigrationWaJob::class);
        $this->adminPost('admin execute on the executed ledger: continuation dry-run (retry key of the executed ledger), no WA message', self::EXECUTE, $c, ['reason' => self::REASON]);
        $this->adminPost('admin execute again: the continuation runs, PPPoE step skipped, OLT still misses the typo, partial again', self::EXECUTE, $c, ['reason' => self::REASON]);

        $targetRow = $this->mt->tables[self::KRIAN_NEW][$user] ?? null;
        $this->assertNotNull($targetRow, 'the moved PPPoE row must exist on the target router');
        $this->fixtureStep('fixture: the PPPoE row on the target router is removed', function () use ($user): void {
            unset($this->mt->tables[self::KRIAN_NEW][$user]);
        });
        $this->say('eksekusi with the target PPPoE row missing: continuation cannot be prepared', '#migrasi eksekusi #'.$c);
        $this->fixtureStep('fixture: the PPPoE row on the target router is back', function () use ($user, $targetRow): void {
            $this->mt->tables[self::KRIAN_NEW][$user] = $targetRow;
        });
        $this->say('eksekusi with the row back: continuation dry-run ready, no job', '#migrasi eksekusi #'.$c);
        $this->say('eksekusi: continuation job queued', '#migrasi eksekusi #'.$c);
        $this->mt->connectThrows = true;
        $this->drainJobs('continuation job with the target router unreachable: held safe before the executing marker', null, ExecuteCustomerMigrationWaJob::class);
        $this->mt->connectThrows = false;

        $this->say('the Excel short form re-sent from another group with the same SN: resend blocked, partial state shown', "#migrasi\nPPPoE: ".$user."\nSN baru: FHTTC0FFEEA9\nRouter baru: Skynet-Krian-OLT", self::STRANGER, self::OTHER_GROUP);
        $this->say('the full guided message re-sent with the same SN: resend blocked, partial state shown, the status stays partially_executed', $this->intakeText('DRAFTG LESTARI', $user, 'FHTTC0FFEEA9'));
        $this->say('batal paksa by a sender who is not bound: refused', '#migrasi batal paksa #'.$c, self::STRANGER);
        $this->say('batal paksa by the bound technician: cancelled, devices not touched', '#migrasi batal paksa #'.$c);
        $this->say('batal again: already force-cancelled', '#migrasi batal #'.$c);

        $this->probeLocksFree();
    }

    /**
     * m11: Huawei OLT-only ledger edges on Gadang (router 511, OLT 11): blocked ledger and retry keys, a kill
     * switch between the queue and the job, a changed command plan, a closed write guard, success, a second
     * confirm and execute on a finished ledger, and a cross-router run that fails after the PPPoE copy.
     */
    private function scenario_m11_huawei_ledger_edges(): void
    {
        $gadangPackage = (int) Package::query()->where('code', 'GADANG-200M')->value('id');
        $same = Customer::create([
            'code' => 'GDG-31', 'name' => 'DRAFTC WIJAYA', 'phone' => '081200000031', 'address' => 'RT 1 RW 2 Gadang', 'package_id' => $gadangPackage,
            'pppoe_user' => 'USERANDI_USERGDG_RT01_RW02', 'mikrotik_profile' => 'GADANG-200M', 'router_id' => self::GADANG_ROUTER, 'status' => 'active',
        ]);
        $this->fakeRouterRow($same, self::GADANG_ROUTER, 'GADANG-200M');
        $sn = 'HWTCC0FFEE31';
        $ont = ['serial_number' => $sn, 'pon_port' => '0/0/1', 'vendor_id' => 'HWTC'];
        $this->huawei->autofind = [$ont];

        $this->say('Huawei short form: draft with the read-only preflight', "#migrasi\nPPPoE: ".$same->pppoe_user."\nSN baru: ".$sn."\nOLT: Gadang");
        $id = $this->lastMigrationId();
        $this->say('konfirmasi: Huawei dry-run (base idempotency key)', '#migrasi konfirmasi #'.$id);
        Setting::set('migration_device_actions_enabled', '0');
        $this->adminPost('admin execute with the migration switch OFF: Huawei ledger blocked, no OLT command', self::EXECUTE, $id, ['reason' => self::REASON]);
        $this->say('eksekusi while the approval is blocked: no job, no dry-run or approval', '#migrasi eksekusi #'.$id);
        Setting::set('migration_device_actions_enabled', '1');
        $this->say('konfirmasi: new dry-run ledger, retry key of the blocked ledger', '#migrasi konfirmasi #'.$id);
        $this->say('eksekusi: job queued', '#migrasi eksekusi #'.$id);
        Setting::set('control_plane.device_read_only_mode', '1');
        $this->drainJobs('job runs after the global read-only switch was turned ON: kill switch stop, ledger blocked, no OLT command', null, ExecuteCustomerMigrationWaJob::class);
        Setting::set('control_plane.device_read_only_mode', '0');
        $this->say('konfirmasi: third ledger, retry key of the second blocked ledger', '#migrasi konfirmasi #'.$id);

        $this->huawei->autofind = [];
        $this->huawei->registered = [$ont + ['onu_ref' => '0/0/1:0']];
        $this->say('eksekusi: job queued', '#migrasi eksekusi #'.$id);
        $this->drainJobs('job after the ONT became registered: the command plan changed, stops before the executing marker, ledger stays dry_run', null, ExecuteCustomerMigrationWaJob::class);
        $this->huawei->registered = [];
        $this->huawei->autofind = [$ont];

        $this->huawei->writeBlocked = true;
        $this->say('eksekusi: job queued', '#migrasi eksekusi #'.$id);
        $this->drainJobs('job with the OLT write guard still dry-run: executing marker set, nothing written, ledger back to dry_run', null, ExecuteCustomerMigrationWaJob::class);
        $this->huawei->writeBlocked = false;

        $this->say('eksekusi: job queued', '#migrasi eksekusi #'.$id);
        $this->drainJobs('job: Huawei plan executed, success text', null, ExecuteCustomerMigrationWaJob::class);
        $this->say('konfirmasi after the success: a new dry-run is refused', '#migrasi konfirmasi #'.$id);
        $this->adminPost('admin execute again on the verified ledger: nothing is sent to the OLT', self::EXECUTE, $id, ['reason' => self::REASON]);
        $this->adminPost('admin prepare again on the finished ledger', self::PREPARE, $id, ['reason' => 'Admin cek dry-run migrasi']);

        // Cross-router: the PPPoE row moves from Krian 15 to Gadang 511 before the OLT command, then the OLT command fails.
        $cross = $this->person('toni');
        $snCross = 'HWTCC0FFEE32';
        $this->huawei->autofind = [['serial_number' => $snCross, 'pon_port' => '0/0/1', 'vendor_id' => 'HWTC']];
        $this->say('Huawei short form from another router: draft with the read-only preflight', "#migrasi\nPPPoE: ".$cross->pppoe_user."\nSN baru: ".$snCross."\nOLT: Gadang\nRouter lama: Krian\nRouter baru: SKYNET-GADANG");
        $crossId = $this->lastMigrationId();
        $this->say('konfirmasi: Huawei cross-router dry-run', '#migrasi konfirmasi #'.$crossId);
        $this->say('eksekusi: job queued', '#migrasi eksekusi #'.$crossId);
        $this->huawei->executeThrows = true;
        $this->drainJobs('job: the PPPoE row is copied, then the OLT command fails: ledger failed, manual reconciliation', null, ExecuteCustomerMigrationWaJob::class);
        $this->huawei->executeThrows = false;
        $this->say('eksekusi on the failed Huawei ledger: no job', '#migrasi eksekusi #'.$crossId);
        $this->say('cek after the failed run', '#migrasi cek #'.$crossId);

        $this->probeLocksFree(['psb-huawei-olt-'.self::OLT_GADANG]);
    }
}
