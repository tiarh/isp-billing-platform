<?php

namespace Tests\Feature;

use App\Jobs\ExecuteCustomerMigrationWaJob;
use App\Models\Customer;
use App\Models\CustomerMigration;
use App\Models\DeviceActionLog;
use App\Models\Package;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\MigrationGolden\MigrationGoldenHarness;
use Tests\Support\MigrationGolden\MigrationGoldenWorld;
use Tests\TestCase;

/**
 * Device-safety invariants of the migration device action (migration refactor M3-A, tests only).
 *
 * Plain asserts, no golden: they must hold before and after the M3 move. Every case drives the real
 * service through public entries (the WA job, the admin web routes, a public service method) with the
 * same fakes as the migration goldens, then reads the recorded device events and the ledger rows.
 *
 *  1. a PPPoE user is never written twice (eksekusi twice, admin execute twice, a continuation);
 *  2. a continuation never writes, disables or renames the PPPoE secret;
 *  3. an executing ledger goes back to dry_run only when a lock was busy before any device write; every
 *     other failure closes it as failed with manual reconciliation, and a busy lock after a write is a partial;
 *  4. idempotency keys: base, reuse while dry_run, retry of a blocked, a failed and an executed ledger;
 *  5. every lock is free after each case.
 */
#[Group('blocking-psb')]
class MigrationDeviceSafetyInvariantsTest extends TestCase
{
    use MigrationGoldenHarness;
    use MigrationGoldenWorld;
    use RefreshDatabase;

    private const EXECUTE = 'customer-migrations.device-action.execute';

    private const PREPARE = 'customer-migrations.device-action.prepare';

    private const REASON = 'Admin jalankan migrasi';

    /** Event types that change a device. */
    private const DEVICE_WRITES = [
        'mt.addPPPSecret', 'mt.disablePPPSecret', 'mt.renamePPPSecret', 'olt.provisionFiberhome',
        'olt.deleteOnu', 'olt.renameOnu', 'huawei.executePlan', 'acs.ensureTagAfterProvision',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootMigrationGolden();
        $this->seedWorld();
        $this->olt->uncfg = [];
    }

    protected function tearDown(): void
    {
        $this->shutdownMigrationGolden();
        parent::tearDown();
    }

    // ───────────────────────── helpers ─────────────────────────

    /** A guided draft whose new SN is waiting in the uncfg list of the target OLT. */
    private function draft(string $person, string $sn, bool $inUncfg = true): int
    {
        if ($inUncfg) {
            $this->olt->uncfg[] = ['sn' => $sn, 'port' => '1/2/2', 'onu_id' => 3 + count($this->olt->uncfg)];
        }

        return $this->guidedIntake('draft of '.$person, $person, $sn);
    }

    /** @return list<array<string, mixed>> recorded events of the given types, from step index $fromStep on */
    private function events(array $types, int $fromStep = 0, ?string $user = null): array
    {
        $out = [];
        foreach (array_slice($this->steps, $fromStep) as $step) {
            foreach ($step['events'] as $event) {
                if (in_array($event['type'] ?? '', $types, true) && ($user === null || ($event['user'] ?? $event['new'] ?? $event['old'] ?? null) === $user)) {
                    $out[] = $event;
                }
            }
        }

        return $out;
    }

    private function user(string $person): string
    {
        return (string) $this->person($person)->pppoe_user;
    }

    /** @return list<DeviceActionLog> the dry-run ledgers of a migration, oldest first */
    private function ledgers(int $id): array
    {
        return DeviceActionLog::query()
            ->where('target_type', 'customer_migration')
            ->where('target_id', (string) $id)
            ->where('action', 'migration-device-action-dry-run')
            ->orderBy('id')
            ->get()
            ->all();
    }

    private function migrationStatus(int $id): string
    {
        return (string) CustomerMigration::query()->findOrFail($id)->status;
    }

    private function assertLocksFree(): void
    {
        $this->probeLocksFree(['device-olt-pon-'.self::OLT_KRIAN.'-1/2/2', 'psb-olt-'.self::OLT_KRIAN, 'psb-huawei-olt-'.self::OLT_GADANG]);
    }

    private function crashBeforeOltStep(): void
    {
        $this->crashOnce(CustomerMigration::class, fn (CustomerMigration $m): bool => $m->isDirty('new_pon_port') && filled($m->new_pon_port), 'injected crash while the uncfg port is saved');
    }

    private function adminExecute(string $label, int $id): void
    {
        $this->adminPost($label, self::EXECUTE, $id, ['reason' => self::REASON]);
    }

    // ───────────────────────── 1: never two secret writes for one user ─────────────────────────

    public function test_eksekusi_twice_writes_the_secret_once(): void
    {
        $id = $this->draft('budi', 'FHTTC0FFEEB1');
        $this->say('eksekusi: first job queued', '#migrasi eksekusi #'.$id);
        $this->say('eksekusi again before the first job ran: the unique job lock keeps a second job out', '#migrasi eksekusi #'.$id);
        $this->drainJobs('every queued execution job', null, ExecuteCustomerMigrationWaJob::class);
        $this->say('eksekusi after the run: already done', '#migrasi eksekusi #'.$id);

        $ofClass = fn (array $e): bool => ($e['class'] ?? null) === ExecuteCustomerMigrationWaJob::class;
        $this->assertCount(1, array_filter($this->events(['job.dispatch']), $ofClass), 'a second eksekusi must not queue a second job');
        $this->assertCount(1, array_filter($this->events(['job.run']), $ofClass));
        $this->assertCount(1, $this->events(['mt.addPPPSecret'], 0, $this->user('budi')));
        $this->assertCount(1, $this->events(['olt.provisionFiberhome']));
        $this->assertSame(CustomerMigration::STATUS_SYNCED, $this->migrationStatus($id));
        $this->assertLocksFree();
    }

    public function test_admin_execute_twice_writes_the_secret_once(): void
    {
        $id = $this->draft('siti', 'FHTTC0FFEEB2');
        $this->adminExecute('admin execute: runs and syncs', $id);
        $this->adminExecute('admin execute again: already done', $id);

        $this->assertCount(1, $this->events(['mt.addPPPSecret'], 0, $this->user('siti')));
        $this->assertCount(1, $this->events(['olt.provisionFiberhome']));
        $this->assertSame(CustomerMigration::STATUS_SYNCED, $this->migrationStatus($id));
        $this->assertCount(1, array_filter($this->ledgers($id), fn (DeviceActionLog $l): bool => $l->status === 'verified'));
        $this->assertLocksFree();
    }

    public function test_a_continuation_after_a_crash_writes_the_secret_once_in_total(): void
    {
        $id = $this->draft('dewi', 'FHTTC0FFEEB3');
        $this->crashBeforeOltStep();
        $this->adminExecute('admin execute: crash after the PPPoE step', $id);
        $this->assertSame(1, $this->crashesFired(), 'the armed crash did not fire');
        $this->say('eksekusi on the failed ledger: continuation dry-run', '#migrasi eksekusi #'.$id);
        $this->say('eksekusi: continuation job queued', '#migrasi eksekusi #'.$id);
        $this->drainJobs('continuation job', null, ExecuteCustomerMigrationWaJob::class);

        $this->assertCount(1, $this->events(['mt.addPPPSecret'], 0, $this->user('dewi')));
        $this->assertCount(1, $this->events(['olt.provisionFiberhome']), 'the OLT is provisioned by the continuation, once');
        $this->assertSame(CustomerMigration::STATUS_SYNCED, $this->migrationStatus($id));
        $this->assertLocksFree();
    }

    /** @return array<string, array{0: string}> */
    public static function huaweiRerunCases(): array
    {
        return [
            'admin execute after a failed OLT command' => ['failed_admin'],
            'eksekusi after the OLT write guard stayed dry-run' => ['guard_dry_run'],
            'konfirmasi and eksekusi after a failed OLT command' => ['failed_konfirmasi'],
            'eksekusi after olt_ok with the ACS tag pending' => ['olt_ok_continuation'],
        ];
    }

    /** A Huawei cross-router draft (Krian 15 to Gadang 511) with its dry-run; returns the migration id. */
    private function huaweiCrossDraft(string $person, string $sn): int
    {
        $this->huawei->autofind = [['serial_number' => $sn, 'pon_port' => '0/0/1', 'vendor_id' => 'HWTC']];
        $this->say('Huawei short form from Krian: draft', "#migrasi\nPPPoE: ".$this->user($person)."\nSN baru: ".$sn."\nOLT: Gadang\nRouter lama: Krian\nRouter baru: SKYNET-GADANG");
        $id = $this->lastMigrationId();
        $this->say('konfirmasi: Huawei cross-router dry-run', '#migrasi konfirmasi #'.$id);

        return $id;
    }

    /** First run: the PPPoE copy is written, then the OLT command fails. */
    private function huaweiCopyThenFail(int $id): void
    {
        $this->say('eksekusi: job queued', '#migrasi eksekusi #'.$id);
        $this->huawei->executeThrows = true;
        $this->drainJobs('job: copy, then the OLT command fails', null, ExecuteCustomerMigrationWaJob::class);
        $this->huawei->executeThrows = false;
    }

    #[DataProvider('huaweiRerunCases')]
    public function test_huawei_cross_router_rerun_writes_the_secret_once(string $case): void
    {
        $id = $this->huaweiCrossDraft('toni', 'HWTCC0FFEEF1');
        $user = $this->user('toni');

        if ($case === 'failed_admin') {
            $this->huaweiCopyThenFail($id);
            $this->adminExecute('admin execute on the failed ledger', $id);
        } elseif ($case === 'guard_dry_run') {
            $this->say('eksekusi: job queued', '#migrasi eksekusi #'.$id);
            $this->huawei->writeBlocked = true;
            $this->drainJobs('job: copy, the OLT write guard is dry-run', null, ExecuteCustomerMigrationWaJob::class);
            $this->huawei->writeBlocked = false;
            $this->say('eksekusi: job queued', '#migrasi eksekusi #'.$id);
            $this->drainJobs('job: the copy is skipped', null, ExecuteCustomerMigrationWaJob::class);
        } elseif ($case === 'failed_konfirmasi') {
            $this->huaweiCopyThenFail($id);
            $this->say('konfirmasi after the failed run', '#migrasi konfirmasi #'.$id);
            $this->say('eksekusi: job queued', '#migrasi eksekusi #'.$id);
            $this->drainJobs('job: the copy is skipped', null, ExecuteCustomerMigrationWaJob::class);
        } else {
            $this->say('eksekusi: job queued', '#migrasi eksekusi #'.$id);
            $this->acsFake->tagPending = true;
            $this->drainJobs('job: copy and OLT plan, ACS tag pending', null, ExecuteCustomerMigrationWaJob::class);
            $this->acsFake->tagPending = false;
            $this->huawei->autofind = [];
            $this->huawei->registered = [['serial_number' => 'HWTCC0FFEEF1', 'pon_port' => '0/0/1', 'vendor_id' => 'HWTC', 'onu_ref' => '0/0/1:0']];
            $this->say('eksekusi after olt_ok: continuation dry-run', '#migrasi eksekusi #'.$id);
            $this->say('eksekusi: continuation job queued', '#migrasi eksekusi #'.$id);
            $this->drainJobs('continuation job: the copy is skipped', null, ExecuteCustomerMigrationWaJob::class);
        }

        $this->assertSame(CustomerMigration::STATUS_OLT_OK, $this->migrationStatus($id));
        $this->assertCount(1, $this->events(['mt.addPPPSecret'], 0, $user), 'the PPPoE secret was written more than once');
        $this->assertCount(1, $this->events(['mt.disablePPPSecret'], 0, $user), 'the source secret was disabled more than once');
        $this->assertContains('mikrotik_step_skipped_already_verified', array_column((array) data_get(CustomerMigration::query()->findOrFail($id)->meta, 'timeline', []), 'step'));
        $this->assertLocksFree();
    }

    public function test_huawei_rerun_with_the_target_secret_gone_stops_before_the_marker(): void
    {
        $id = $this->huaweiCrossDraft('dewi', 'HWTCC0FFEEF2');
        $user = $this->user('dewi');
        $this->huaweiCopyThenFail($id);
        $before = $this->ledgers($id)[0]->only(['status', 'executed_at', 'idempotency_key']);
        $this->assertSame('failed', $before['status']);
        unset($this->mt->tables[self::GADANG_ROUTER][$user]);
        $from = count($this->steps);

        $this->adminExecute('admin execute with the target secret gone', $id);

        $this->assertEquals($before, $this->ledgers($id)[0]->only(['status', 'executed_at', 'idempotency_key']), 'the ledger must not be touched');
        $this->assertSame([], $this->events(self::DEVICE_WRITES, $from), 'a run that stops must not write a device');
        $this->assertCount(1, $this->events(['mt.addPPPSecret'], 0, $user));
        $this->assertLocksFree();
    }

    // ───────────────────────── 2: a continuation never touches the secret ─────────────────────────

    public function test_a_continuation_never_writes_disables_or_renames_the_secret(): void
    {
        $id = $this->draft('agus', 'FHTTC0FFEEB9', false);
        $this->say('eksekusi: job queued', '#migrasi eksekusi #'.$id);
        $this->drainJobs('job: the secret moves, the typo SN is not found, partially_executed', null, ExecuteCustomerMigrationWaJob::class);
        $this->assertSame(CustomerMigration::STATUS_PARTIALLY_EXECUTED, $this->migrationStatus($id));
        $this->assertCount(1, $this->events(['mt.addPPPSecret']));

        $from = count($this->steps);
        $this->adminExecute('admin execute: continuation dry-run', $id);
        $this->adminExecute('admin execute: the continuation runs, still partial', $id);
        $this->say('eksekusi: continuation dry-run ready', '#migrasi eksekusi #'.$id);
        $this->say('eksekusi: continuation job queued', '#migrasi eksekusi #'.$id);
        $this->drainJobs('continuation job', null, ExecuteCustomerMigrationWaJob::class);

        $this->assertSame([], $this->events(['mt.addPPPSecret', 'mt.disablePPPSecret', 'mt.renamePPPSecret'], $from), 'a continuation wrote the secret again');
        $timeline = (array) data_get(CustomerMigration::query()->findOrFail($id)->meta, 'timeline', []);
        $this->assertContains('mikrotik_step_skipped_already_verified', array_column($timeline, 'step'));
        $this->assertLocksFree();
    }

    // ───────────────────────── 3: when an executing ledger may go back to dry_run ─────────────────────────

    public function test_a_busy_migration_lock_before_any_write_puts_the_ledger_back_to_dry_run(): void
    {
        $id = $this->draft('budi', 'FHTTC0FFEEC1');
        $this->hold('customer-migration-'.$id);
        $this->adminExecute('admin execute while the migration lock is held', $id);
        $this->release('customer-migration-'.$id);

        [$ledger] = $this->ledgers($id);
        $this->assertSame('dry_run', $ledger->status);
        $this->assertNull($ledger->executed_at);
        $this->assertArrayNotHasKey('manual_reconciliation_required', (array) $ledger->result);
        $this->assertSame([], $this->events(self::DEVICE_WRITES), 'nothing may be written while the lock is busy');

        $this->adminExecute('admin execute again with the lock free: one resend is enough', $id);
        $this->assertSame(CustomerMigration::STATUS_SYNCED, $this->migrationStatus($id));
        $this->assertCount(1, $this->events(['mt.addPPPSecret']));
        $this->assertLocksFree();
    }

    public function test_any_other_failure_closes_the_ledger_as_failed_with_manual_reconciliation(): void
    {
        $id = $this->draft('siti', 'FHTTC0FFEEC2');
        $this->crashBeforeOltStep();
        $this->adminExecute('admin execute: crash after the PPPoE step', $id);
        $this->assertSame(1, $this->crashesFired(), 'the armed crash did not fire');

        $ledger = $this->ledgers($id)[0];
        $this->assertSame('failed', $ledger->status);
        $this->assertTrue($ledger->result['manual_reconciliation_required'] ?? false);
        $this->assertSame('device_action_execution_failed', $ledger->result['failure_code'] ?? null);
        $migration = CustomerMigration::query()->findOrFail($id);
        $this->assertSame('execution_failed', data_get($migration->meta, 'device_action_approval.status'));
        $this->assertSame('execute_exception', data_get($migration->meta, 'partial_execution.trigger'));
        $this->assertSame(CustomerMigration::STATUS_PARTIALLY_EXECUTED, $migration->status);
        $this->assertLocksFree();
    }

    public function test_a_busy_pon_lock_after_the_secret_moved_is_a_partial_state_and_never_a_revert(): void
    {
        $id = $this->draft('dewi', 'FHTTC0FFEEC3');
        $this->hold('device-olt-pon-'.self::OLT_KRIAN.'-1/2/2');
        $this->adminExecute('admin execute while the PON lock is held: the secret moves, the OLT step is busy', $id);
        $this->release('device-olt-pon-'.self::OLT_KRIAN.'-1/2/2');

        $ledger = $this->ledgers($id)[0];
        $this->assertSame('executed', $ledger->status, 'a busy lock after a device write must not revert or fail the ledger');
        $this->assertNotNull($ledger->executed_at);
        $this->assertSame(CustomerMigration::STATUS_PARTIALLY_EXECUTED, $this->migrationStatus($id));
        $this->assertSame([], $this->events(['olt.provisionFiberhome']));
        $this->assertCount(1, $this->events(['mt.addPPPSecret']));

        $from = count($this->steps);
        $this->adminExecute('admin execute: continuation dry-run', $id);
        $this->adminExecute('admin execute: the continuation runs with the lock free', $id);
        $this->assertSame(CustomerMigration::STATUS_SYNCED, $this->migrationStatus($id));
        $this->assertSame([], $this->events(['mt.addPPPSecret', 'mt.disablePPPSecret', 'mt.renamePPPSecret'], $from));
        $this->assertCount(1, $this->events(['olt.provisionFiberhome']));
        $this->assertLocksFree();
    }

    public function test_a_huawei_command_failure_closes_the_ledger_as_failed_and_a_busy_session_before_it_leaves_it_untouched(): void
    {
        $customer = Customer::create([
            'code' => 'GDG-41', 'name' => 'TONI HARTONO', 'phone' => '081200000041', 'address' => 'RT 1 RW 2 Gadang',
            'package_id' => (int) Package::query()->where('code', 'GADANG-200M')->value('id'),
            'pppoe_user' => 'USERTONI_GADANG_RT01_RW02', 'mikrotik_profile' => 'GADANG-200M', 'router_id' => self::GADANG_ROUTER, 'status' => 'active',
        ]);
        $this->fakeRouterRow($customer, self::GADANG_ROUTER, 'GADANG-200M');
        $this->huawei->autofind = [['serial_number' => 'HWTCC0FFEE41', 'pon_port' => '0/0/1', 'vendor_id' => 'HWTC']];
        $this->say('Huawei short form: draft', "#migrasi\nPPPoE: ".$customer->pppoe_user."\nSN baru: HWTCC0FFEE41\nOLT: Gadang");
        $id = $this->lastMigrationId();
        $this->say('konfirmasi: Huawei dry-run', '#migrasi konfirmasi #'.$id);

        $this->say('eksekusi: job queued', '#migrasi eksekusi #'.$id);
        $this->huawei->sessionBusy = true;
        $this->drainJobs('job with the OLT session busy: no OLT command, ledger untouched', null, ExecuteCustomerMigrationWaJob::class);
        $this->huawei->sessionBusy = false;
        $ledger = $this->ledgers($id)[0];
        $this->assertSame('dry_run', $ledger->status);
        $this->assertNull($ledger->executed_at);
        $this->assertSame([], $this->events(['huawei.executePlan']));

        $this->say('eksekusi: job queued', '#migrasi eksekusi #'.$id);
        $this->huawei->executeThrows = true;
        $this->drainJobs('job with a failed OLT command: ledger failed', null, ExecuteCustomerMigrationWaJob::class);
        $this->huawei->executeThrows = false;
        $ledger = $this->ledgers($id)[0];
        $this->assertSame('failed', $ledger->status);
        $this->assertTrue($ledger->result['manual_reconciliation_required'] ?? false);
        $this->assertSame('device_action_execution_failed', $ledger->result['failure_code'] ?? null);
        $this->probeLocksFree(['psb-huawei-olt-'.self::OLT_GADANG]);
    }

    public function test_an_executing_ledger_is_never_touched_by_any_entry(): void
    {
        $id = $this->draft('agus', 'FHTTC0FFEEC5');
        $this->step('fixture: a worker was killed while the ledger was executing', 'fixture:db', function () use ($id): void {
            DeviceActionLog::query()->where('target_type', 'customer_migration')->where('target_id', (string) $id)->update(['status' => 'executing', 'executed_at' => now()]);
        });
        $before = $this->ledgers($id)[0]->only(['status', 'executed_at', 'idempotency_key']);
        $from = count($this->steps);

        $this->adminExecute('admin execute', $id);
        $this->adminPost('admin prepare', self::PREPARE, $id, ['reason' => 'Admin cek dry-run migrasi']);
        $this->say('eksekusi: job queued', '#migrasi eksekusi #'.$id);
        $this->drainJobs('job', null, ExecuteCustomerMigrationWaJob::class);
        $this->say('sn command', '#migrasi sn #'.$id.' FHTTC0FFEEC6');

        $ledgers = $this->ledgers($id);
        $this->assertCount(1, $ledgers, 'no new ledger may be made while one is executing');
        $this->assertEquals($before, $ledgers[0]->only(['status', 'executed_at', 'idempotency_key']));
        $this->assertSame([], $this->events(self::DEVICE_WRITES, $from), 'an executing ledger must not lead to a device write');
        $this->assertLocksFree();
    }

    // ───────────────────────── 4: idempotency keys ─────────────────────────

    public function test_idempotency_keys_follow_base_reuse_blocked_failed_and_executed(): void
    {
        $id = $this->draft('budi', 'FHTTC0FFEED1');
        $base = 'migration-device:'.$id.':ZTEGA0000011:FHTTC0FFEED1:onu:v1';
        $keys = fn (int $migrationId): array => array_map(fn (DeviceActionLog $l): string => (string) $l->idempotency_key, $this->ledgers($migrationId));

        $this->assertSame([$base], $keys($id), 'base key of the first dry-run');
        $this->adminPost('admin prepare while the dry-run is ready: reused', self::PREPARE, $id, ['reason' => 'Admin cek dry-run migrasi']);
        $this->assertSame([$base], $keys($id), 'a ready dry-run is reused, no new ledger');

        Setting::set('migration_device_actions_enabled', '0');
        $this->adminExecute('admin execute with the switch OFF: blocked', $id);
        Setting::set('migration_device_actions_enabled', '1');
        $blocked = $this->ledgers($id)[0];
        $this->assertSame('blocked', $blocked->status);
        $this->adminPost('admin prepare after the block', self::PREPARE, $id, ['reason' => 'Admin cek dry-run migrasi']);
        $this->assertSame([$base, $base.':retry:'.$blocked->id], $keys($id), 'retry of a blocked ledger');

        $this->crashBeforeOltStep();
        $this->adminExecute('admin execute: crash after the PPPoE step', $id);
        $this->assertSame(1, $this->crashesFired(), 'the armed crash did not fire');
        $failed = $this->ledgers($id)[1];
        $this->assertSame('failed', $failed->status);
        $this->say('eksekusi on the failed ledger: continuation dry-run', '#migrasi eksekusi #'.$id);
        $this->assertSame([$base, $base.':retry:'.$blocked->id, $base.':retry:'.$failed->id], $keys($id), 'retry of a failed ledger');
        $this->say('eksekusi: continuation job queued', '#migrasi eksekusi #'.$id);
        $this->drainJobs('continuation job', null, ExecuteCustomerMigrationWaJob::class);
        $this->assertSame('verified', $this->ledgers($id)[2]->status);

        // An executed ledger: a typo SN leaves the OLT step undone, so the base key is unchanged.
        $second = $this->draft('siti', 'FHTTC0FFEED9', false);
        $secondBase = 'migration-device:'.$second.':ZTEGA0000012:FHTTC0FFEED9:onu:v1';
        $this->say('eksekusi: job queued', '#migrasi eksekusi #'.$second);
        $this->drainJobs('job: partially executed', null, ExecuteCustomerMigrationWaJob::class);
        $executed = $this->ledgers($second)[0];
        $this->assertSame('executed', $executed->status);
        $this->adminExecute('admin execute on the executed ledger: continuation dry-run', $second);
        $this->assertSame([$secondBase, $secondBase.':retry:'.$executed->id], $keys($second), 'retry of an executed ledger');
        $this->assertLocksFree();
    }

    // ───────────────────────── 6: the global read-only switch holds on every lane ─────────────────────────

    /** Creates the Huawei same-router customer of Gadang and returns the migration id of its confirmed draft. */
    private function huaweiDraft(string $sn, int $n = 1): int
    {
        $customer = Customer::create([
            'code' => 'GDG-5'.$n, 'name' => 'ANDI WIJAYA', 'phone' => '08120000005'.$n, 'address' => 'RT 1 RW 2 Gadang',
            'package_id' => (int) Package::query()->where('code', 'GADANG-200M')->value('id'),
            'pppoe_user' => 'USERANDI'.$n.'_GADANG_RT01_RW02', 'mikrotik_profile' => 'GADANG-200M', 'router_id' => self::GADANG_ROUTER, 'status' => 'active',
        ]);
        $this->fakeRouterRow($customer, self::GADANG_ROUTER, 'GADANG-200M');
        $this->huawei->autofind = [['serial_number' => $sn, 'pon_port' => '0/0/1', 'vendor_id' => 'HWTC']];
        $this->say('Huawei short form: draft', "#migrasi\nPPPoE: ".$customer->pppoe_user."\nSN baru: ".$sn."\nOLT: Gadang");
        $id = $this->lastMigrationId();
        $this->say('konfirmasi: Huawei dry-run', '#migrasi konfirmasi #'.$id);

        return $id;
    }

    public function test_global_read_only_stops_every_lane_before_the_marker(): void
    {
        $writes = fn (int $from): array => $this->events(self::DEVICE_WRITES, $from);
        $noRun = fn (int $id): bool => count(array_filter($this->ledgers($id), fn (DeviceActionLog $l): bool => in_array($l->status, ['executing', 'executed', 'verified'], true))) === 0;

        // C300 WA: dispatch refused while ON, no job.
        $a = $this->draft('budi', 'FHTTC0FFEEG1');
        Setting::set('control_plane.device_read_only_mode', '1');
        $from = count($this->steps);
        $this->say('eksekusi while read-only is ON', '#migrasi eksekusi #'.$a);
        $this->assertSame([], array_filter($this->events(['job.dispatch'], $from), fn (array $e): bool => ($e['class'] ?? null) === ExecuteCustomerMigrationWaJob::class), 'no job may be queued');
        $this->assertSame('dry_run_ready', data_get(CustomerMigration::query()->findOrFail($a)->meta, 'device_action_approval.status'));
        $this->assertSame(['dry_run'], array_map(fn (DeviceActionLog $l): string => $l->status, $this->ledgers($a)));

        // C300 WA: a job queued before the switch went ON stops before the marker.
        Setting::set('control_plane.device_read_only_mode', '0');
        $this->say('eksekusi: job queued', '#migrasi eksekusi #'.$a);
        Setting::set('control_plane.device_read_only_mode', '1');
        $this->drainJobs('job after the flip', null, ExecuteCustomerMigrationWaJob::class);
        $this->assertSame([], $writes($from));
        $this->assertSame('blocked', $this->ledgers($a)[0]->status);
        $this->assertTrue($noRun($a));
        $this->assertSame(true, data_get(CustomerMigration::query()->findOrFail($a)->meta, 'device_action_approval.write_flags.global_read_only'));

        // C300 continuation of a partial migration: the queued continuation stops, the partial state stays.
        Setting::set('control_plane.device_read_only_mode', '0');
        $b = $this->draft('siti', 'FHTTC0FFEEG2');
        $this->hold('device-olt-pon-'.self::OLT_KRIAN.'-1/2/2');
        $this->say('eksekusi: job queued', '#migrasi eksekusi #'.$b);
        $this->drainJobs('job: PPPoE step done, PON lock busy', null, ExecuteCustomerMigrationWaJob::class);
        $this->release('device-olt-pon-'.self::OLT_KRIAN.'-1/2/2');
        $this->say('eksekusi: continuation dry-run', '#migrasi eksekusi #'.$b);
        $this->say('eksekusi: continuation job queued', '#migrasi eksekusi #'.$b);
        $partial = data_get(CustomerMigration::query()->findOrFail($b)->meta, 'partial_execution');
        $from = count($this->steps);
        Setting::set('control_plane.device_read_only_mode', '1');
        $this->drainJobs('continuation job after the flip', null, ExecuteCustomerMigrationWaJob::class);
        $this->assertSame([], $writes($from), 'a continuation must not write while read-only is ON');
        $this->assertEquals($partial, data_get(CustomerMigration::query()->findOrFail($b)->meta, 'partial_execution'));
        $this->assertSame(CustomerMigration::STATUS_PARTIALLY_EXECUTED, $this->migrationStatus($b));

        // Huawei WA: dispatch refused while ON; a queued job stops before the marker.
        Setting::set('control_plane.device_read_only_mode', '0');
        $h = $this->huaweiDraft('HWTCC0FFEEG3');
        Setting::set('control_plane.device_read_only_mode', '1');
        $from = count($this->steps);
        $this->say('Huawei eksekusi while read-only is ON', '#migrasi eksekusi #'.$h);
        $this->assertSame([], array_filter($this->events(['job.dispatch'], $from), fn (array $e): bool => ($e['class'] ?? null) === ExecuteCustomerMigrationWaJob::class));
        Setting::set('control_plane.device_read_only_mode', '0');
        $this->say('Huawei eksekusi: job queued', '#migrasi eksekusi #'.$h);
        Setting::set('control_plane.device_read_only_mode', '1');
        $this->drainJobs('Huawei job after the flip', null, ExecuteCustomerMigrationWaJob::class);
        $this->assertSame([], $writes($from));
        $this->assertSame('blocked', $this->ledgers($h)[0]->status);
        $this->assertTrue($noRun($h));

        // Admin web, both lanes: the red owner text, no device write, ledger blocked.
        $redText = 'Eksekusi ditahan: mode aman perangkat aktif atau saklar migrasi belum ON. Belum ada perubahan di perangkat.';
        Setting::set('control_plane.device_read_only_mode', '0');
        $c = $this->draft('dewi', 'FHTTC0FFEEG4');
        $h2 = $this->huaweiDraft('HWTCC0FFEEG5', 2);
        Setting::set('control_plane.device_read_only_mode', '1');
        foreach ([[$c, 'C300'], [$h2, 'Huawei']] as [$id, $lane]) {
            $from = count($this->steps);
            $this->adminExecute('admin execute '.$lane.' while read-only is ON', $id);
            $this->assertSame('blocked', $this->ledgers($id)[0]->status);
            $this->assertSame([], $writes($from), $lane.' admin execute wrote a device');
            $this->assertTrue($noRun($id));
            $this->assertSame([$redText], $this->events(['http.response'], $from)[0]['errors'] ?? null, $lane.' admin message');
        }
        $this->assertLocksFree();
    }

    // ───────────────────────── 5: locks after refusals ─────────────────────────

    public function test_every_lock_is_free_after_the_refused_paths(): void
    {
        $id = $this->draft('toni', 'FHTTC0FFEEE1');
        Setting::set('migration_device_actions_enabled', '0');
        $this->adminExecute('admin execute with the switch OFF: blocked', $id);
        Setting::set('migration_device_actions_enabled', '1');
        $this->say('eksekusi by a sender who is not allowed', '#migrasi eksekusi #'.$id, self::STRANGER);
        Setting::set('migration_wa_only_live_enabled', '0');
        $this->say('eksekusi with WA-only live OFF', '#migrasi eksekusi #'.$id);
        Setting::set('migration_wa_only_live_enabled', '1');
        $this->adminPost('admin execute with a too short reason: refused by validation', self::EXECUTE, $id, ['reason' => 'x']);
        $this->say('eksekusi after the block: no dry-run ready', '#migrasi eksekusi #'.$id);

        $this->assertSame('blocked', $this->ledgers($id)[0]->status);
        $this->assertSame([], $this->events(self::DEVICE_WRITES), 'a refused path must not write a device');
        $this->assertLocksFree();
    }
}
