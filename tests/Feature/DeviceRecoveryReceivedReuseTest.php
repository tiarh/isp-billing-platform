<?php

namespace Tests\Feature;

use App\Models\CustomerMigration;
use App\Models\DeviceRecoveryCase;
use App\Models\Olt;
use App\Models\OltOnu;
use App\Models\PsbDraft;
use App\Models\PsbStarliteEntry;
use App\Models\Setting;
use App\Models\User;
use App\Services\CustomerMigrationService;
use App\Services\DeviceRecoveryOltCleanupService;
use App\Services\DeviceRecoveryService;
use App\Services\GuidedMigrationLookupService;
use App\Services\HuaweiOltOnlyProvisioningService;
use App\Services\MikrotikService;
use App\Services\OltZteC300Service;
use App\Services\PsbActivationRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\StubsLifecycleGuard;
use Tests\Fixtures\ZteOnuState;
use Tests\TestCase;

/**
 * A device returned through a dismantle recovery case (`received`) must be
 * reusable for a new PSB or as migration "SN baru": nothing in eBilling may
 * still treat the serial as "in use" by the old, non-live owner. A serial on
 * a live customer must stay blocked. All OLT access is faked.
 */
#[Group('blocking-core')]
class DeviceRecoveryReceivedReuseTest extends TestCase
{
    use RefreshDatabase;
    use StubsLifecycleGuard;

    private const SN = 'FHTTREUSE001';

    protected function setUp(): void
    {
        parent::setUp();
        $this->allowLifecycleGuard();
        $this->allowCustomerLifecycleGuard();
        DB::table('packages')->insert([
            'id' => 1, 'code' => 'PKG-1', 'name' => 'Paket Test', 'price' => 100000,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        FakeReuseOlt::$deleted = [];
    }

    // ---- OLT cleanup purges stale inventory so migration preflight is clear ----

    public function test_zte_successful_delete_purges_stale_inventory_and_snapshot(): void
    {
        $this->enableCleanup();
        $this->seedOlt(9, 'zte_c300');
        $this->seedCustomer(10, self::SN, 9, 'terminated');
        $this->seedOnu(9, self::SN, '1/2/1:3', 'user10');
        $this->seedOnu(9, 'FHTTOTHER999', '1/2/1:4', 'neighbour');
        $this->seedSnapshot(9, [['onu_ref' => '1/2/1:3', 'serial_number' => self::SN], ['onu_ref' => '1/2/1:4', 'serial_number' => 'FHTTOTHER999']]);
        $case = $this->seedRecoveryCase(10, self::SN);
        $this->fakeZte(true);

        $result = app(DeviceRecoveryOltCleanupService::class)->deleteForCase($case);

        $this->assertSame('deleted', $result['status']);
        $this->assertSame([[9, '1/2/1:3']], FakeReuseOlt::$deleted);
        $this->assertSame(['db' => 1, 'snapshot' => 1], $result['inventory_purged']);
        $this->assertFalse(OltOnu::query()->where('olt_id', 9)->where('serial_number', self::SN)->exists());
        // Neighbouring ONU on the same PON is untouched.
        $this->assertTrue(OltOnu::query()->where('olt_id', 9)->where('onu_ref', '1/2/1:4')->exists());
        $refs = array_column(Olt::query()->find(9)->last_gpon_snapshot['onus'], 'onu_ref');
        $this->assertSame(['1/2/1:4'], $refs);

        // Migration "SN baru" OLT snapshot no longer sees the reused serial.
        $migration = $this->migrationFor('NEWUSER', self::SN);
        $snapshot = $this->invokeMigration('oltSnapshotForMigration', $migration, Olt::query()->find(9), null);
        $this->assertFalse($snapshot['found']);
    }

    public function test_huawei_gadang_successful_delete_purges_stale_inventory(): void
    {
        $this->enableCleanup();
        $this->seedOlt(11, 'huawei_ma5608t', 'OLT GADANG 3');
        $this->seedCustomer(10, self::SN, 11, 'terminated');
        $this->seedOnu(11, self::SN, '0/0/7:29', 'user10');
        $case = $this->seedRecoveryCase(10, self::SN);
        $this->app->bind(HuaweiOltOnlyProvisioningService::class, fn () => new class(app(MikrotikService::class)) extends HuaweiOltOnlyProvisioningService
        {
            public function onuDetail(Olt $olt, string $onuRef): array
            {
                $gone = in_array([$olt->id, $onuRef], FakeReuseOlt::$deleted, true);

                return ['onu' => ['serial_number' => $gone ? null : DB::table('olt_onus')->where('olt_id', $olt->id)->where('onu_ref', $onuRef)->value('serial_number'), 'state' => 'offline']];
            }

            public function deleteOnu(Olt $olt, string $onuRef, ?string $serial = null, array $approval = []): array
            {
                FakeReuseOlt::$deleted[] = [$olt->id, $onuRef];

                return ['success' => true, 'verified_absent' => true, 'device_write' => true];
            }
        });

        $result = app(DeviceRecoveryOltCleanupService::class)->deleteForCase($case);

        $this->assertSame('deleted', $result['status']);
        $this->assertSame([[11, '0/0/7:29']], FakeReuseOlt::$deleted);
        $this->assertFalse(OltOnu::query()->where('olt_id', 11)->where('serial_number', self::SN)->exists());
    }

    public function test_failed_delete_keeps_inventory_row(): void
    {
        $this->enableCleanup();
        $this->seedOlt(9, 'zte_c300');
        $this->seedCustomer(10, self::SN, 9, 'terminated');
        $this->seedOnu(9, self::SN, '1/2/1:3', 'user10');
        $case = $this->seedRecoveryCase(10, self::SN);
        $this->fakeZte(false);

        $result = app(DeviceRecoveryOltCleanupService::class)->deleteForCase($case);

        $this->assertSame('delete_failed', $result['status']);
        $this->assertArrayNotHasKey('inventory_purged', $result);
        $this->assertTrue(OltOnu::query()->where('olt_id', 9)->where('serial_number', self::SN)->exists());
        $this->assertFalse($case->fresh()->meta[DeviceRecoveryOltCleanupService::META_KEY]['ok']);
    }

    public function test_kill_switch_off_never_deletes_or_purges(): void
    {
        config(['device-recovery.mode' => 'write']);
        Setting::set('device_recovery_olt_delete_enabled', '0', 'boolean', 'ops');
        $this->seedOlt(9, 'zte_c300');
        $this->seedCustomer(10, self::SN, 9, 'terminated');
        $this->seedOnu(9, self::SN, '1/2/1:3', 'user10');
        $case = $this->seedRecoveryCase(10, self::SN);
        $this->fakeZte(true);

        $result = app(DeviceRecoveryOltCleanupService::class)->deleteForCase($case);

        $this->assertSame('skipped_flag_off', $result['status']);
        $this->assertSame([], FakeReuseOlt::$deleted);
        // The ONU still physically exists on the OLT: migration must keep
        // seeing it (fail-closed) until it is deleted.
        $this->assertTrue(OltOnu::query()->where('olt_id', 9)->where('serial_number', self::SN)->exists());
        $migration = $this->migrationFor('NEWUSER', self::SN);
        $snapshot = $this->invokeMigration('oltSnapshotForMigration', $migration, Olt::query()->find(9), null);
        $this->assertTrue($snapshot['found']);
        $this->assertFalse($snapshot['belongs_to_migration']);
    }

    // ---- Released-serial resolver ----

    public function test_released_serial_resolver_only_reports_received_non_live_owner(): void
    {
        $recovery = app(DeviceRecoveryService::class);
        $this->seedCustomer(10, self::SN, null, 'terminated');
        $this->seedRecoveryCase(10, self::SN);

        $this->assertSame([10], $recovery->releasedCustomerIdsForSerial(' fhtt-reuse001 '));
        $this->assertSame(['user10'], $recovery->releasedPppoeUsersForSerial(self::SN));

        // Case not yet received (device still with technician) => not released.
        $this->seedCustomer(11, 'FHTTCARRIED01', null, 'terminated');
        $this->seedRecoveryCase(11, 'FHTTCARRIED01', DeviceRecoveryService::STATUS_CARRIED, DeviceRecoveryService::ITEM_CARRIED);
        $this->assertSame([], $recovery->releasedCustomerIdsForSerial('FHTTCARRIED01'));

        // Owner re-activated => serial is live again, never released.
        $this->seedCustomer(12, 'FHTTLIVE0001', null, 'active');
        $this->seedRecoveryCase(12, 'FHTTLIVE0001');
        $this->assertSame([], $recovery->releasedCustomerIdsForSerial('FHTTLIVE0001'));
    }

    // ---- Migration (#migrasi SN baru) ----

    public function test_migration_psb_overlap_ignores_released_owner_history(): void
    {
        $this->seedCustomer(10, self::SN, null, 'terminated');
        $this->seedRecoveryCase(10, self::SN);
        $this->seedStarlite('user10', self::SN);
        $this->seedDraft('user10', self::SN, 10);

        $migration = $this->migrationFor('NEWUSER', self::SN);

        $this->assertSame([], $this->invokeMigration('psbOverlapEvidence', $migration));
    }

    public function test_migration_psb_overlap_still_blocks_serial_of_live_customer(): void
    {
        // Old owner released, but the device was already re-installed for a
        // live customer: the overlap must still be reported.
        $this->seedCustomer(10, self::SN, null, 'terminated');
        $this->seedRecoveryCase(10, self::SN);
        $this->seedCustomer(11, self::SN, null, 'active');
        $this->seedStarlite('user11', self::SN);
        $this->seedDraft('user11', self::SN, 11);

        $evidence = $this->invokeMigration('psbOverlapEvidence', $this->migrationFor('NEWUSER', self::SN));

        $this->assertEqualsCanonicalizing(['psb_starlite_entries', 'psb_drafts'], array_column($evidence, 'source'));
        $this->assertSame(['user11', 'user11'], array_column($evidence, 'pppoe_user'));
    }

    public function test_migration_psb_overlap_without_recovery_is_unchanged(): void
    {
        $this->seedCustomer(10, self::SN, null, 'terminated');
        $this->seedStarlite('user10', self::SN);

        $evidence = $this->invokeMigration('psbOverlapEvidence', $this->migrationFor('NEWUSER', self::SN));

        $this->assertSame(['psb_starlite_entries'], array_column($evidence, 'source'));
    }

    public function test_migration_psb_overlap_keeps_own_pppoe_match(): void
    {
        $this->seedCustomer(10, self::SN, null, 'terminated');
        $this->seedRecoveryCase(10, self::SN);
        $this->seedStarlite('NEWUSER', 'FHTTSOMEOTHER');

        $evidence = $this->invokeMigration('psbOverlapEvidence', $this->migrationFor('NEWUSER', self::SN));

        $this->assertSame(['NEWUSER'], array_column($evidence, 'pppoe_user'));
    }

    public function test_guided_lookup_does_not_flag_released_serial_as_linked(): void
    {
        $this->seedCustomer(10, self::SN, null, 'terminated');
        $this->seedRecoveryCase(10, self::SN);
        $this->seedStarlite('user10', self::SN);
        $this->seedCustomer(20, 'ZTETARGET001', null, 'active', 'TARGET MIGRASI', '081999999999');

        $result = app(GuidedMigrationLookupService::class)->preview([
            'name' => 'TARGET MIGRASI',
            'new_sn' => self::SN,
            'phone' => '081999999999',
        ]);

        $this->assertNotEmpty($result['candidates']);
        $this->assertSame(20, $result['candidates'][0]['customer_id']);
        $this->assertNotContains('new_sn_already_linked_to_other_customer', $result['candidates'][0]['conflicts']);
        $this->assertNotContains('new_sn_exists_in_other_psb_history', $result['candidates'][0]['conflicts']);
    }

    public function test_guided_lookup_still_flags_serial_of_live_customer(): void
    {
        $this->seedCustomer(10, self::SN, null, 'terminated');
        $this->seedRecoveryCase(10, self::SN);
        $this->seedCustomer(11, self::SN, null, 'active');
        $this->seedCustomer(20, 'ZTETARGET001', null, 'active', 'TARGET MIGRASI', '081999999999');

        $result = app(GuidedMigrationLookupService::class)->preview([
            'name' => 'TARGET MIGRASI',
            'new_sn' => self::SN,
            'phone' => '081999999999',
        ]);

        $this->assertContains('new_sn_already_linked_to_other_customer', $result['candidates'][0]['conflicts']);
    }

    // ---- PSB (manual FiberHome provision KPI record) ----

    public function test_psb_record_does_not_hijack_released_owner_draft(): void
    {
        $this->seedCustomer(10, self::SN, null, 'terminated');
        $this->seedRecoveryCase(10, self::SN);
        $oldDraft = $this->seedDraft('user10', self::SN, 10, PsbDraft::STATUS_APPLIED);

        $draft = app(PsbActivationRecorder::class)->recordFiberhomeProvision([
            'sn' => self::SN,
            'name' => 'newpsb_rt01_rw01',
            'port' => '1/2/1',
            'onu_id' => 7,
        ]);

        $this->assertNotNull($draft);
        $this->assertNotSame($oldDraft->id, $draft->id);
        $this->assertSame('newpsb_rt01_rw01', $draft->pppoe_user);
        $this->assertSame(self::SN, $draft->sn);
        $this->assertSame(PsbDraft::STATUS_APPLIED, $oldDraft->fresh()->status);
        $this->assertSame('user10', $oldDraft->fresh()->pppoe_user);
    }

    public function test_psb_record_still_matches_live_draft_by_serial(): void
    {
        $this->seedCustomer(11, self::SN, null, 'active');
        $liveDraft = $this->seedDraft('user11', self::SN, 11, PsbDraft::STATUS_SECRET_OK);

        $draft = app(PsbActivationRecorder::class)->recordFiberhomeProvision([
            'sn' => self::SN,
            'name' => 'renamed_user',
            'port' => '1/2/1',
            'onu_id' => 7,
        ]);

        $this->assertSame($liveDraft->id, $draft->id);
        $this->assertSame(PsbDraft::STATUS_OLT_OK, $draft->status);
    }

    // ---- helpers ----

    private function enableCleanup(): void
    {
        config(['device-recovery.mode' => 'write']);
        Setting::set('device_recovery_olt_delete_enabled', '1', 'boolean', 'ops');
    }

    private function fakeZte(bool $success): void
    {
        $this->app->bind(OltZteC300Service::class, fn () => $success
            ? new class extends OltZteC300Service
            {
                public function liveOnuProbe(Olt $olt, string $onuRef): array
                {
                    $gone = in_array([$olt->id, $onuRef], FakeReuseOlt::$deleted, true);

                    $serial = $gone ? null : DB::table('olt_onus')->where('olt_id', $olt->id)->where('onu_ref', $onuRef)->value('serial_number');

                    return ZteOnuState::probe($onuRef, $serial === null ? null : 'DyingGasp', $serial);
                }

                public function deleteOnu(Olt $olt, string $onuRef): array
                {
                    FakeReuseOlt::$deleted[] = [$olt->id, $onuRef];

                    return ['success' => true, 'verified_absent' => true];
                }
            }
            : new class extends OltZteC300Service
            {
                public function liveOnuProbe(Olt $olt, string $onuRef): array
                {
                    $serial = DB::table('olt_onus')->where('olt_id', $olt->id)->where('onu_ref', $onuRef)->value('serial_number');

                    return ZteOnuState::probe($onuRef, $serial === null ? null : 'DyingGasp', $serial);
                }

                public function deleteOnu(Olt $olt, string $onuRef): array
                {
                    FakeReuseOlt::$deleted[] = [$olt->id, $onuRef];

                    return ['success' => false, 'verified_absent' => false];
                }
            });
    }

    private function seedOlt(int $id, string $vendor, string $name = 'OLT KRIAN'): void
    {
        DB::table('olts')->insert([
            'id' => $id, 'name' => $name, 'code' => 'OLT-'.$id, 'vendor' => $vendor,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * @param  list<array<string, string>>  $onus
     */
    private function seedSnapshot(int $oltId, array $onus): void
    {
        Olt::query()->findOrFail($oltId)->forceFill([
            'last_gpon_snapshot' => ['onus' => $onus, 'meta' => ['onus_count' => count($onus)]],
        ])->save();
    }

    private function seedCustomer(
        int $id,
        string $serial,
        ?int $oltId,
        string $status,
        ?string $name = null,
        ?string $phone = null,
    ): void {
        DB::table('customers')->insert([
            'id' => $id,
            'code' => 'CUST-'.$id,
            'name' => $name ?? 'Customer '.$id,
            'phone' => $phone ?? '08120000'.str_pad((string) $id, 4, '0', STR_PAD_LEFT),
            'address' => 'RT 01 RW 01',
            'pppoe_user' => 'user'.$id,
            'package_id' => 1,
            'onu_serial' => $serial,
            'olt_id' => $oltId,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedOnu(int $oltId, string $serial, string $onuRef, string $name): void
    {
        DB::table('olt_onus')->insert([
            'olt_id' => $oltId,
            'serial_number' => $serial,
            'onu_ref' => $onuRef,
            'onu_name' => $name,
            'pon_port' => explode(':', $onuRef)[0],
            'state' => 'offline',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedRecoveryCase(
        int $customerId,
        string $serial,
        string $status = DeviceRecoveryService::STATUS_RECEIVED,
        string $itemStatus = DeviceRecoveryService::ITEM_RECEIVED,
    ): DeviceRecoveryCase {
        $actor = User::factory()->create();
        $ticketId = DB::table('support_tickets')->insertGetId([
            'code' => 'TCK-'.$customerId,
            'customer_id' => $customerId,
            'status' => 'closed',
            'type' => 'other',
            'priority' => 'normal',
            'subject' => 'Dismantle '.$customerId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $caseId = DB::table('device_recovery_cases')->insertGetId([
            'ticket_id' => $ticketId,
            'customer_id' => $customerId,
            'status' => $status,
            'created_by' => $actor->id,
            'received_at' => $status === DeviceRecoveryService::STATUS_RECEIVED ? now() : null,
            'version' => 5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('device_recovery_items')->insert([
            'recovery_case_id' => $caseId,
            'device_type' => 'onu',
            'expected_serial_number' => $serial,
            'captured_serial_number' => $serial,
            'expected_source' => 'customer',
            'status' => $itemStatus,
            'condition' => DeviceRecoveryService::CONDITION_GOOD,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DeviceRecoveryCase::query()->findOrFail($caseId);
    }

    private function seedStarlite(string $pppoe, string $serial): void
    {
        PsbStarliteEntry::create([
            'installed_on' => now()->toDateString(),
            'rw' => 'RW 1',
            'full_name' => 'Pelanggan '.$pppoe,
            'pppoe_user' => $pppoe,
            'sn' => $serial,
            'service_type' => 'PSB',
            'install_status' => 'Success',
            'source' => 'migration_staging',
        ]);
    }

    private function seedDraft(string $pppoe, string $serial, ?int $customerId, string $status = PsbDraft::STATUS_APPLIED): PsbDraft
    {
        return PsbDraft::query()->create([
            'status' => $status,
            'message_id' => 'draft-'.$pppoe.'-'.uniqid(),
            'full_name' => 'Pelanggan '.$pppoe,
            'sn' => $serial,
            'pppoe_user' => $pppoe,
            'customer_id' => $customerId,
        ]);
    }

    private function migrationFor(string $pppoe, string $newSn): CustomerMigration
    {
        return new CustomerMigration([
            'pppoe_user' => $pppoe,
            'new_sn' => $newSn,
        ]);
    }

    private function invokeMigration(string $method, mixed ...$args): mixed
    {
        $service = app(CustomerMigrationService::class);
        $reflection = new \ReflectionMethod($service, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($service, ...$args);
    }
}

class FakeReuseOlt
{
    /** @var list<array{0: int, 1: string}> */
    public static array $deleted = [];
}
