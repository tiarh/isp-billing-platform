<?php

namespace Tests\Feature;

use App\Jobs\DeleteDismantledOnuJob;
use App\Models\DeviceRecoveryCase;
use App\Models\DeviceRecoveryEvent;
use App\Models\Olt;
use App\Models\Setting;
use App\Models\User;
use App\Services\DeviceRecoveryOltCleanupService;
use App\Services\DeviceRecoveryService;
use App\Services\Lifecycle\CustomerLifecycleGuard;
use App\Services\Lifecycle\GuardContext;
use App\Services\Lifecycle\GuardDecision;
use App\Services\Lifecycle\GuardSignal;
use App\Services\OltZteC300Service;
use App\Services\OpsAlertService;
use App\Support\OltOperationLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\StubsLifecycleGuard;
use Tests\Fixtures\ZteOnuState;
use Tests\TestCase;

#[Group('blocking-core')]
class DeviceRecoveryDeleteOnusCommandTest extends TestCase
{
    use RefreshDatabase;
    use StubsLifecycleGuard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allowLifecycleGuard();
        $this->allowCustomerLifecycleGuard();
        DB::table('packages')->insert([
            'id' => 1, 'code' => 'PKG-1', 'name' => 'Paket Test', 'price' => 100000,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('olts')->insert([
            'id' => 9, 'name' => 'OLT KRIAN', 'code' => 'OLT-9', 'vendor' => 'zte_c300',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        OperatorDeleteZteCapture::$deleted = [];
        OperatorDeleteZteCapture::$liveOverride = null;
        OperatorDeleteZteCapture::$gone = [];
        OperatorDeleteZteCapture::$noop = false;
        OperatorDeleteZteCapture::$storedSerial = null;
        OperatorDeleteZteCapture::$state = 'DyingGasp';
        OperatorDeleteZteCapture::$readbackThrows = false;
        OperatorDeleteZteCapture::$onDelete = null;
        $this->app->bind(OltZteC300Service::class, fn () => new class extends OltZteC300Service
        {
            public function liveOnuProbe(Olt $olt, string $onuRef): array
            {
                if (in_array($onuRef, OperatorDeleteZteCapture::$gone, true)) {
                    if (OperatorDeleteZteCapture::$readbackThrows) {
                        throw new \RuntimeException('read-back boom');
                    }

                    return ZteOnuState::probe($onuRef, null, null);
                }
                $serial = OperatorDeleteZteCapture::$liveOverride
                    ?? DB::table('olt_onus')->where('olt_id', $olt->id)->where('onu_ref', $onuRef)->value('serial_number')
                    ?? OperatorDeleteZteCapture::$storedSerial;

                // $state is the C300 Phase State token (real `show gpon onu state` row).
                return ZteOnuState::probe($onuRef, $serial === null ? null : OperatorDeleteZteCapture::$state, $serial);
            }

            public function deleteOnu(Olt $olt, string $onuRef): array
            {
                OperatorDeleteZteCapture::$deleted[] = [$olt->id, $onuRef];
                if (OperatorDeleteZteCapture::$onDelete !== null) {
                    (OperatorDeleteZteCapture::$onDelete)();
                }
                if (! OperatorDeleteZteCapture::$noop) {
                    OperatorDeleteZteCapture::$gone[] = $onuRef;
                }

                return ['success' => true, 'verified_absent' => true];
            }
        });
    }

    private function seedCase(int $customerId, string $serial, string $customerStatus = 'terminated', string $onuRef = '1/2/1:3'): DeviceRecoveryCase
    {
        DB::table('customers')->insert([
            'id' => $customerId,
            'code' => 'CUST-'.$customerId,
            'name' => 'Customer '.$customerId,
            'phone' => '08120000'.str_pad((string) $customerId, 4, '0', STR_PAD_LEFT),
            'address' => 'Test Address',
            'pppoe_user' => 'user'.$customerId,
            'package_id' => 1,
            'onu_serial' => $serial,
            'olt_id' => 9,
            'status' => $customerStatus,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('olt_onus')->insert([
            'olt_id' => 9, 'serial_number' => $serial, 'onu_ref' => $onuRef, 'onu_name' => 'ONU',
            'pon_port' => '1/2/1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $actor = User::factory()->create();
        $ticketId = DB::table('support_tickets')->insertGetId([
            'code' => 'TCK-'.$customerId, 'customer_id' => $customerId, 'status' => 'closed',
            'type' => 'other', 'priority' => 'normal', 'subject' => 'Dismantle',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $caseId = DB::table('device_recovery_cases')->insertGetId([
            'ticket_id' => $ticketId, 'customer_id' => $customerId, 'area_id' => null,
            'status' => DeviceRecoveryService::STATUS_RECEIVED, 'created_by' => $actor->id,
            'received_at' => now(), 'version' => 5, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return DeviceRecoveryCase::query()->findOrFail($caseId);
    }

    private function killSwitchOff(): void
    {
        Setting::set('device_recovery_olt_delete_enabled', '0', 'boolean', 'ops');
    }

    public function test_requires_case_option(): void
    {
        $this->artisan('device-recovery:delete-onus')->assertFailed();
    }

    public function test_dry_run_writes_nothing_and_never_deletes_even_with_switch_off(): void
    {
        config(['device-recovery.mode' => 'write']);
        $this->killSwitchOff();
        $case = $this->seedCase(10, 'FHTT1234');

        $this->artisan('device-recovery:delete-onus', ['--case' => [$case->id]])
            ->expectsOutputToContain('DRY RUN (nothing written): cases=1 would_delete=1')
            ->assertSuccessful();

        $this->assertSame([], OperatorDeleteZteCapture::$deleted);
        $this->assertSame(0, DeviceRecoveryEvent::query()->where('event_type', 'olt_onu_delete')->count());
        $this->assertNull($case->fresh()->meta);
        $this->assertSame(1, DB::table('olt_onus')->count());

        $plan = app(DeviceRecoveryOltCleanupService::class)->planForCase($case);
        $this->assertTrue($plan['would_delete']);
        $this->assertSame('FHTT1234', $plan['live_serial']);
    }

    public function test_execute_requires_actor(): void
    {
        config(['device-recovery.mode' => 'write']);
        $case = $this->seedCase(10, 'FHTT1234');

        $this->artisan('device-recovery:delete-onus', ['--case' => [$case->id], '--execute' => true, '--sleep' => 0])
            ->assertFailed();
        $this->assertSame([], OperatorDeleteZteCapture::$deleted);
    }

    public function test_execute_deletes_with_kill_switch_off_and_records_operator_run(): void
    {
        config(['device-recovery.mode' => 'write']);
        $this->killSwitchOff();
        $actor = User::factory()->create();
        $case = $this->seedCase(10, 'FHTT1234');

        $this->artisan('device-recovery:delete-onus', [
            '--case' => [$case->id], '--execute' => true, '--actor' => $actor->id, '--sleep' => 0,
        ])->expectsOutputToContain('deleted=1')->assertSuccessful();

        $this->assertSame([[9, '1/2/1:3']], OperatorDeleteZteCapture::$deleted);
        $record = $case->fresh()->meta[DeviceRecoveryOltCleanupService::META_KEY];
        $this->assertTrue($record['ok']);
        $this->assertTrue($record['operator_run']);
        $this->assertSame($actor->id, $record['operator_actor_id']);
        $this->assertSame($actor->id, DeviceRecoveryEvent::query()->where('event_type', 'olt_onu_delete')->value('actor_id'));
        $this->assertSame(0, DB::table('olt_onus')->count());
    }

    public function test_mode_read_only_still_refuses(): void
    {
        config(['device-recovery.mode' => 'read_only']);
        $actor = User::factory()->create();
        $case = $this->seedCase(10, 'FHTT1234');

        $this->artisan('device-recovery:delete-onus', [
            '--case' => [$case->id], '--execute' => true, '--actor' => $actor->id, '--sleep' => 0,
        ])->assertFailed();
        $this->assertSame([], OperatorDeleteZteCapture::$deleted);

        // Service level: explicit operator run is also refused outside write mode.
        $result = app(DeviceRecoveryOltCleanupService::class)->deleteForCase($case, $actor, explicitOperatorRun: true);
        $this->assertSame('skipped_mode_not_write', $result['status']);
        $this->assertSame([], OperatorDeleteZteCapture::$deleted);
    }

    public function test_live_serial_mismatch_is_skipped(): void
    {
        config(['device-recovery.mode' => 'write']);
        $actor = User::factory()->create();
        $case = $this->seedCase(10, 'FHTT1234');
        OperatorDeleteZteCapture::$liveOverride = 'FHTT9999';

        $this->artisan('device-recovery:delete-onus', ['--case' => [$case->id]])
            ->expectsOutputToContain('would_delete=0')
            ->assertSuccessful();

        $result = app(DeviceRecoveryOltCleanupService::class)->deleteForCase($case, $actor, explicitOperatorRun: true);
        $this->assertSame('skipped_live_serial_mismatch', $result['status']);
        $this->assertSame([], OperatorDeleteZteCapture::$deleted);
    }

    public function test_serial_in_use_is_skipped(): void
    {
        config(['device-recovery.mode' => 'write']);
        $actor = User::factory()->create();
        $case = $this->seedCase(10, 'FHTT1234');
        DB::table('customers')->insert([
            'id' => 11, 'code' => 'CUST-11', 'name' => 'Live', 'phone' => '081200000025', 'address' => 'x',
            'pppoe_user' => 'user11', 'package_id' => 1, 'onu_serial' => 'FHTT1234', 'olt_id' => 9,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = app(DeviceRecoveryOltCleanupService::class)->deleteForCase($case, $actor, explicitOperatorRun: true);

        $this->assertSame('skipped_serial_in_use', $result['status']);
        $this->assertSame([], OperatorDeleteZteCapture::$deleted);
        $this->assertFalse(app(DeviceRecoveryOltCleanupService::class)->planForCase($case)['would_delete']);
    }

    public function test_rerun_is_idempotent_already_deleted(): void
    {
        config(['device-recovery.mode' => 'write']);
        $actor = User::factory()->create();
        $case = $this->seedCase(10, 'FHTT1234');
        $service = app(DeviceRecoveryOltCleanupService::class);

        $this->assertSame('deleted', $service->deleteForCase($case, $actor, explicitOperatorRun: true)['status']);
        $this->assertSame('already_deleted', $service->deleteForCase($case, $actor, explicitOperatorRun: true)['status']);
        $this->assertCount(1, OperatorDeleteZteCapture::$deleted);
    }

    public function test_still_present_after_delete_is_failed_and_not_purged(): void
    {
        config(['device-recovery.mode' => 'write']);
        $actor = User::factory()->create();
        $case = $this->seedCase(10, 'FHTT1234');
        OperatorDeleteZteCapture::$noop = true;

        $result = app(DeviceRecoveryOltCleanupService::class)->deleteForCase($case, $actor, explicitOperatorRun: true);

        $this->assertSame('delete_failed', $result['status']);
        $this->assertSame('still_present_after_delete', $result['reason']);
        $this->assertFalse($result['verified_absent']);
        $this->assertSame(1, DB::table('olt_onus')->count());
        $this->assertFalse($case->fresh()->meta[DeviceRecoveryOltCleanupService::META_KEY]['ok']);
    }

    public function test_readback_exception_after_delete_is_readback_failed_and_not_purged(): void
    {
        config(['device-recovery.mode' => 'write']);
        $actor = User::factory()->create();
        $case = $this->seedCase(10, 'FHTT1234');
        OperatorDeleteZteCapture::$readbackThrows = true;

        $result = app(DeviceRecoveryOltCleanupService::class)->deleteForCase($case, $actor, explicitOperatorRun: true);

        $this->assertSame('delete_failed', $result['status']);
        $this->assertSame('readback_failed', $result['reason']);
        $this->assertFalse($result['verified_absent']);
        $this->assertSame(1, DB::table('olt_onus')->count());
        $this->assertFalse($case->fresh()->meta[DeviceRecoveryOltCleanupService::META_KEY]['ok']);
    }

    /** Case recorded ok=true (optimistic) whose inventory row was purged but the ONU is still live. */
    private function seedUnverifiedCase(string $serial = 'FHTT1234'): DeviceRecoveryCase
    {
        $case = $this->seedCase(10, $serial);
        DB::table('olt_onus')->delete();
        $case->forceFill(['meta' => [DeviceRecoveryOltCleanupService::META_KEY => [
            'ok' => true, 'reason' => 'deleted', 'olt_id' => 9, 'serial' => $serial, 'onu_ref' => '1/2/1:3', 'verified_absent' => true,
        ]]])->save();
        OperatorDeleteZteCapture::$storedSerial = $serial;

        return $case->fresh();
    }

    public function test_retry_unverified_happy_path(): void
    {
        config(['device-recovery.mode' => 'write']);
        $actor = User::factory()->create();
        $case = $this->seedUnverifiedCase();

        $this->artisan('device-recovery:delete-onus', ['--case' => [$case->id], '--retry-unverified' => true])
            ->expectsOutputToContain('would_delete=1')
            ->assertSuccessful();
        $this->assertSame([], OperatorDeleteZteCapture::$deleted);

        $this->artisan('device-recovery:delete-onus', [
            '--case' => [$case->id], '--retry-unverified' => true, '--execute' => true, '--actor' => $actor->id, '--sleep' => 0,
        ])->expectsOutputToContain('deleted=1')->assertSuccessful();

        $this->assertSame([[9, '1/2/1:3']], OperatorDeleteZteCapture::$deleted);
        $record = $case->fresh()->meta[DeviceRecoveryOltCleanupService::META_KEY];
        $this->assertTrue($record['ok']);
        $this->assertTrue($record['retry_unverified']);
    }

    public function test_retry_unverified_absent_is_verified_without_device_write(): void
    {
        config(['device-recovery.mode' => 'write']);
        $actor = User::factory()->create();
        $case = $this->seedUnverifiedCase();
        OperatorDeleteZteCapture::$gone = ['1/2/1:3'];

        $result = app(DeviceRecoveryOltCleanupService::class)->retryUnverified($case, $actor);

        $this->assertSame('deleted_verified_on_retry', $result['status']);
        $this->assertSame([], OperatorDeleteZteCapture::$deleted);
        // Second run is idempotent.
        $this->assertSame('already_deleted', app(DeviceRecoveryOltCleanupService::class)->retryUnverified($case->fresh(), $actor)['status']);
    }

    #[DataProvider('failedReasons')]
    public function test_failed_verification_with_absent_onu_is_recorded_and_purged_without_device_write(string $reason): void
    {
        config(['device-recovery.mode' => 'write']);
        $actor = User::factory()->create();
        $case = $this->seedCase(10, 'FHTT1234');
        $case->forceFill(['meta' => [DeviceRecoveryOltCleanupService::META_KEY => [
            'ok' => false, 'reason' => $reason, 'olt_id' => 9, 'serial' => 'FHTT1234', 'onu_ref' => '1/2/1:3',
        ]]])->save();
        OperatorDeleteZteCapture::$gone = ['1/2/1:3'];

        $this->artisan('device-recovery:delete-onus', ['--case' => [$case->id], '--retry-unverified' => true])
            ->expectsOutputToContain('would_delete=0')
            ->assertSuccessful();
        $this->assertSame(1, DB::table('olt_onus')->count());

        $this->artisan('device-recovery:delete-onus', [
            '--case' => [$case->id], '--retry-unverified' => true, '--execute' => true, '--actor' => $actor->id, '--sleep' => 0,
        ])->expectsOutputToContain('deleted=1')->assertSuccessful();

        $record = $case->fresh()->meta[DeviceRecoveryOltCleanupService::META_KEY];
        $this->assertTrue($record['ok']);
        $this->assertSame('deleted_verified_on_retry', $record['reason']);
        $this->assertTrue($record['verified_absent']);
        $this->assertSame([], OperatorDeleteZteCapture::$deleted);
        $this->assertSame(0, DB::table('olt_onus')->count());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function failedReasons(): array
    {
        return [
            'readback_failed' => ['readback_failed'],
            'still_present_after_delete' => ['still_present_after_delete'],
            'delete_failed' => ['delete_failed'],
        ];
    }

    public function test_busy_olt_lock_refuses_and_writes_nothing(): void
    {
        config(['device-recovery.mode' => 'write', 'device-recovery.olt_lock_wait_seconds' => 0]);
        $actor = User::factory()->create();
        $case = $this->seedCase(10, 'FHTT1234');
        $olt = Olt::query()->findOrFail(9);
        $held = Cache::lock(OltOperationLock::key($olt), 60);
        $this->assertTrue($held->get());

        try {
            $result = app(DeviceRecoveryOltCleanupService::class)->deleteForCase($case, $actor, explicitOperatorRun: true);
            $this->assertSame('olt_busy', $result['status']);

            $this->artisan('device-recovery:delete-onus', [
                '--case' => [$case->id], '--execute' => true, '--actor' => $actor->id, '--sleep' => 0,
            ])->assertFailed();
        } finally {
            $held->release();
        }

        $this->assertSame([], OperatorDeleteZteCapture::$deleted);
        $this->assertSame(1, DB::table('olt_onus')->count());
        $this->assertNull($case->fresh()->meta);
    }

    public function test_job_is_released_when_olt_is_busy(): void
    {
        config(['device-recovery.mode' => 'write', 'device-recovery.olt_lock_wait_seconds' => 0]);
        Setting::set('device_recovery_olt_delete_enabled', '1', 'boolean', 'ops');
        $case = $this->seedCase(10, 'FHTT1234');
        $held = Cache::lock(OltOperationLock::key(Olt::query()->findOrFail(9)), 60);
        $this->assertTrue($held->get());

        try {
            // Many busy cycles never fail the job (no tries limit, bounded by retryUntil).
            for ($i = 0; $i < 12; $i++) {
                $job = new DeleteDismantledOnuJob($case);
                $job->withFakeQueueInteractions();
                $job->handle(app(DeviceRecoveryOltCleanupService::class));
                $job->assertReleased();
                $this->assertGreaterThanOrEqual(DeleteDismantledOnuJob::BUSY_RELEASE_SECONDS, $job->job->releaseDelay);
                $this->assertLessThanOrEqual(DeleteDismantledOnuJob::BUSY_RELEASE_SECONDS + 60, $job->job->releaseDelay);
                $job->assertNotFailed();
            }
        } finally {
            $held->release();
        }

        $this->assertSame([], OperatorDeleteZteCapture::$deleted);
        $this->assertNull($case->fresh()->meta);
    }

    public function test_job_has_no_tries_limit_and_is_bounded_by_retry_until(): void
    {
        $job = new DeleteDismantledOnuJob($this->seedCase(10, 'FHTT1234'));

        $this->assertFalse(property_exists($job, 'tries'));
        $this->assertSame(3, $job->maxExceptions);
        $this->assertGreaterThanOrEqual(21600, $job->uniqueFor);
        $this->assertEqualsWithDelta(6 * 3600, now()->diffInSeconds($job->retryUntil()), 5);

        $middleware = $job->middleware();
        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);
        $this->assertSame(120, $middleware[0]->releaseAfter);
        $this->assertSame(900, $middleware[0]->expiresAfter);
    }

    public function test_job_defers_without_blocking_when_psb_or_migration_key_is_held(): void
    {
        config(['device-recovery.mode' => 'write']);
        Setting::set('device_recovery_olt_delete_enabled', '1', 'boolean', 'ops');
        $case = $this->seedCase(10, 'FHTT1234');
        $olt = Olt::query()->findOrFail(9);

        foreach ([OltOperationLock::psbKey($olt), OltOperationLock::migrationKey($olt, '1/2/1')] as $key) {
            $held = Cache::lock($key, 60);
            $this->assertTrue($held->get());
            try {
                $job = new DeleteDismantledOnuJob($case);
                $job->withFakeQueueInteractions();
                $job->handle(app(DeviceRecoveryOltCleanupService::class));
                $job->assertReleased();
            } finally {
                $held->release();
            }
        }

        $this->assertSame([], OperatorDeleteZteCapture::$deleted);
        $this->assertNull($case->fresh()->meta);
    }

    public function test_delete_defers_when_psb_key_is_held_and_probe_never_holds_it(): void
    {
        config(['device-recovery.mode' => 'write', 'device-recovery.olt_lock_wait_seconds' => 0]);
        $actor = User::factory()->create();
        $case = $this->seedCase(10, 'FHTT1234');
        $olt = Olt::query()->findOrFail(9);
        $held = Cache::lock(OltOperationLock::psbKey($olt), 60);
        $this->assertTrue($held->get());

        try {
            $this->assertSame('olt_busy', app(DeviceRecoveryOltCleanupService::class)->deleteForCase($case, $actor, explicitOperatorRun: true)['status']);
        } finally {
            $held->release();
        }
        $this->assertSame([], OperatorDeleteZteCapture::$deleted);

        // Once PSB is idle the delete proceeds, and during it PSB's key stays acquirable.
        $psbFree = null;
        OperatorDeleteZteCapture::$onDelete = function () use ($olt, &$psbFree): void {
            $probe = Cache::lock(OltOperationLock::psbKey($olt), 5);
            $psbFree = $probe->get();
            if ($psbFree) {
                $probe->release();
            }
        };
        $result = app(DeviceRecoveryOltCleanupService::class)->deleteForCase($case, $actor, explicitOperatorRun: true);

        $this->assertSame('deleted', $result['status']);
        $this->assertTrue($psbFree, 'PSB key must never be held by a delete');
    }

    public function test_delete_defers_when_migration_port_key_is_held(): void
    {
        config(['device-recovery.mode' => 'write', 'device-recovery.olt_lock_wait_seconds' => 0]);
        $actor = User::factory()->create();
        $case = $this->seedCase(10, 'FHTT1234');
        $olt = Olt::query()->findOrFail(9);

        // A migration on ANOTHER port does not block.
        $other = Cache::lock(OltOperationLock::migrationKey($olt, '1/9/9'), 60);
        $this->assertTrue($other->get());
        $held = Cache::lock(OltOperationLock::migrationKey($olt, '1/2/1'), 60);
        $this->assertTrue($held->get());

        try {
            $this->assertSame('olt_busy', app(DeviceRecoveryOltCleanupService::class)->deleteForCase($case, $actor, explicitOperatorRun: true)['status']);
        } finally {
            $held->release();
        }
        $this->assertSame([], OperatorDeleteZteCapture::$deleted);

        try {
            $this->assertSame('deleted', app(DeviceRecoveryOltCleanupService::class)->deleteForCase($case, $actor, explicitOperatorRun: true)['status']);
        } finally {
            $other->release();
        }
    }

    public function test_lock_is_released_after_a_delete(): void
    {
        config(['device-recovery.mode' => 'write']);
        $actor = User::factory()->create();
        $case = $this->seedCase(10, 'FHTT1234');

        app(DeviceRecoveryOltCleanupService::class)->deleteForCase($case, $actor, explicitOperatorRun: true);

        $lock = Cache::lock(OltOperationLock::key(Olt::query()->findOrFail(9)), 5);
        $this->assertTrue($lock->get());
        $lock->release();
    }

    public function test_retry_unverified_serial_mismatch_is_skipped(): void
    {
        config(['device-recovery.mode' => 'write']);
        $actor = User::factory()->create();
        $case = $this->seedUnverifiedCase();
        OperatorDeleteZteCapture::$liveOverride = 'FHTT9999';

        $result = app(DeviceRecoveryOltCleanupService::class)->retryUnverified($case, $actor);

        $this->assertSame('skipped_live_serial_mismatch', $result['status']);
        $this->assertSame([], OperatorDeleteZteCapture::$deleted);
    }

    public function test_online_onu_is_skipped_on_operator_and_auto_paths(): void
    {
        config(['device-recovery.mode' => 'write']);
        Setting::set('device_recovery_olt_delete_enabled', '1', 'boolean', 'ops');
        $actor = User::factory()->create();
        $case = $this->seedCase(10, 'FHTT1234');
        OperatorDeleteZteCapture::$state = 'working';
        $service = app(DeviceRecoveryOltCleanupService::class);

        $this->assertSame('onu_online', $service->planForCase($case)['reason']);
        $this->artisan('device-recovery:delete-onus', ['--case' => [$case->id]])
            ->expectsOutputToContain('would_delete=0')
            ->assertSuccessful();

        $this->assertSame('skipped_onu_online', $service->deleteForCase($case, $actor, explicitOperatorRun: true)['status']);
        $this->assertSame('skipped_onu_online', $service->deleteForCase($case->fresh())['status']);
        $this->assertSame([], OperatorDeleteZteCapture::$deleted);
        $this->assertSame(1, DB::table('olt_onus')->count());
    }

    public function test_unknown_state_is_skipped(): void
    {
        config(['device-recovery.mode' => 'write']);
        $actor = User::factory()->create();
        $case = $this->seedCase(10, 'FHTT1234');
        OperatorDeleteZteCapture::$state = 'syncMib';

        $result = app(DeviceRecoveryOltCleanupService::class)->deleteForCase($case, $actor, explicitOperatorRun: true);

        $this->assertSame('skipped_live_state_unknown', $result['status']);
        $this->assertSame([], OperatorDeleteZteCapture::$deleted);
    }

    public function test_retry_unverified_online_onu_is_skipped(): void
    {
        config(['device-recovery.mode' => 'write']);
        $actor = User::factory()->create();
        $case = $this->seedUnverifiedCase();
        OperatorDeleteZteCapture::$state = 'working';

        $result = app(DeviceRecoveryOltCleanupService::class)->retryUnverified($case, $actor);

        $this->assertSame('skipped_onu_online', $result['status']);
        $this->assertSame([], OperatorDeleteZteCapture::$deleted);
    }

    public function test_default_auto_path_still_respects_kill_switch(): void
    {
        config(['device-recovery.mode' => 'write']);
        $this->killSwitchOff();
        $case = $this->seedCase(10, 'FHTT1234');

        $result = app(DeviceRecoveryOltCleanupService::class)->deleteForCase($case);

        $this->assertSame('skipped_flag_off', $result['status']);
        $this->assertSame([], OperatorDeleteZteCapture::$deleted);
        $this->assertArrayNotHasKey('operator_run', $case->fresh()->meta[DeviceRecoveryOltCleanupService::META_KEY]);
    }

    // ---------------------------------------------------------------------
    // Roadmap 1.3 PR-2: lifecycle guard on the case ONU delete (B1-B4)
    // ---------------------------------------------------------------------

    /** @return list<array{0: string, 1: string, 2: int}> captured ops WA sends: [message, dedupe key, seconds] */
    private function captureOpsWa(): \ArrayObject
    {
        $sent = new \ArrayObject;
        $ops = Mockery::mock(OpsAlertService::class);
        $ops->shouldReceive('send')->andReturnUsing(function (string $message, string $key = '', int $seconds = 300) use ($sent): bool {
            $sent->append([$message, $key, $seconds]);

            return true;
        });
        $this->app->instance(OpsAlertService::class, $ops);

        return $sent;
    }

    public function test_guard_hold_on_auto_path_sends_no_olt_command_sends_wa_and_is_idempotent_per_day(): void
    {
        config(['device-recovery.mode' => 'write']);
        Setting::set('device_recovery_olt_delete_enabled', '1', 'boolean', 'ops');
        $this->denyLifecycleGuard();
        $wa = $this->captureOpsWa();
        $case = $this->seedCase(10, 'FHTT1234');
        $service = app(DeviceRecoveryOltCleanupService::class);

        $first = $service->deleteForCase($case);
        $second = $service->deleteForCase($case->fresh());

        $this->assertSame('skipped_guard_hold', $first['status']);
        $this->assertSame('skipped_guard_hold', $second['status']);
        $this->assertSame([], OperatorDeleteZteCapture::$deleted, 'no OLT delete command may be sent');
        $this->assertSame(1, DB::table('olt_onus')->count());

        $key = 'olt-onu-delete-guard:'.$case->id.':'.now()->format('Ymd');
        $this->assertSame(1, DeviceRecoveryEvent::query()->where('idempotency_key', $key)->count());
        $this->assertSame(0, DeviceRecoveryEvent::query()->where('idempotency_key', 'olt-onu-delete:'.$case->id)->count());
        $meta = $case->fresh()->meta[DeviceRecoveryOltCleanupService::META_KEY];
        $this->assertSame('guard_hold', $meta['reason']);
        $this->assertSame('deny', $meta['guard_verdict']);

        $this->assertCount(2, $wa, 'WA is attempted per run; the ops service dedupes by this key for a day');
        $this->assertSame(
            'Pagar ONU: hapus ONU FHTT1234 OLT KRIAN 1/2/1:3 (case #'.$case->id.') DITAHAN: Sesi PPPoE masih aktif (R1, dicek 2 menit lalu). Tidak ada perintah dikirim ke OLT.',
            $wa[0][0]
        );
        $this->assertSame($key, $wa[0][1]);
        $this->assertSame(86400, $wa[0][2]);
    }

    public function test_guard_hold_is_never_overridable_by_the_operator_run(): void
    {
        config(['device-recovery.mode' => 'write']);
        $this->denyLifecycleGuard();
        $this->captureOpsWa();
        $actor = User::factory()->create(['role' => 'superadmin']);
        $case = $this->seedCase(10, 'FHTT1234');

        $result = app(DeviceRecoveryOltCleanupService::class)->deleteForCase($case, $actor, explicitOperatorRun: true);

        $this->assertSame('skipped_guard_hold', $result['status']);
        $this->assertSame([], OperatorDeleteZteCapture::$deleted);
    }

    public function test_job_holds_without_releasing_and_without_olt_command(): void
    {
        config(['device-recovery.mode' => 'write']);
        Setting::set('device_recovery_olt_delete_enabled', '1', 'boolean', 'ops');
        $this->denyLifecycleGuard();
        $wa = $this->captureOpsWa();
        $case = $this->seedCase(10, 'FHTT1234');

        $job = new DeleteDismantledOnuJob($case);
        $job->handle(app(DeviceRecoveryOltCleanupService::class));

        $this->assertSame([], OperatorDeleteZteCapture::$deleted);
        $this->assertCount(1, $wa);
        $this->assertSame('guard_hold', $case->fresh()->meta[DeviceRecoveryOltCleanupService::META_KEY]['reason']);
    }

    public function test_hold_is_rechecked_next_day_and_the_real_delete_event_is_not_shadowed(): void
    {
        config(['device-recovery.mode' => 'write']);
        Setting::set('device_recovery_olt_delete_enabled', '1', 'boolean', 'ops');
        $this->denyLifecycleGuard();
        $this->captureOpsWa();
        $case = $this->seedCase(10, 'FHTT1234');
        $service = app(DeviceRecoveryOltCleanupService::class);

        $this->assertSame('skipped_guard_hold', $service->deleteForCase($case)['status']);

        $this->allowLifecycleGuard();
        Carbon::setTestNow(now()->addDay());
        try {
            $result = $service->deleteForCase($case->fresh());
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame('deleted', $result['status']);
        $this->assertSame([[9, '1/2/1:3']], OperatorDeleteZteCapture::$deleted);
        $this->assertSame(1, DeviceRecoveryEvent::query()->where('idempotency_key', 'olt-onu-delete:'.$case->id)->count());
        $this->assertSame(2, DeviceRecoveryEvent::query()->where('event_type', 'olt_onu_delete')->count());
    }

    public function test_suharto_isolated_but_online_customer_is_held_by_the_real_guard(): void
    {
        config(['device-recovery.mode' => 'write']);
        Setting::set('device_recovery_olt_delete_enabled', '1', 'boolean', 'ops');
        $this->app->forgetInstance(CustomerLifecycleGuard::class);
        $wa = $this->captureOpsWa();
        // Terminated + soft-deleted by the old destroy path, yet his PPPoE session is up.
        $case = $this->seedCase(10, 'FHTT1234', 'terminated');
        DB::table('customers')->where('id', 10)->update(['is_online' => true, 'pppoe_online_checked_at' => now()]);

        $plan = app(DeviceRecoveryOltCleanupService::class)->planForCase($case);
        $result = app(DeviceRecoveryOltCleanupService::class)->deleteForCase($case);

        $this->assertFalse($plan['would_delete']);
        $this->assertSame('guard_hold', $plan['reason']);
        $this->assertSame('deny', $plan['guard']['verdict']);
        $this->assertSame('skipped_guard_hold', $result['status']);
        $this->assertSame([], OperatorDeleteZteCapture::$deleted);
        $this->assertStringContainsString('Sesi PPPoE masih aktif', (string) $wa[0][0]);
    }

    public function test_plan_for_case_shows_the_guard_verdict_and_dry_run_counts_it_as_not_deletable(): void
    {
        config(['device-recovery.mode' => 'write']);
        $this->denyLifecycleGuard();
        $case = $this->seedCase(10, 'FHTT1234');

        $plan = app(DeviceRecoveryOltCleanupService::class)->planForCase($case);

        $this->assertFalse($plan['ok']);
        $this->assertFalse($plan['would_delete']);
        $this->assertSame('guard_hold', $plan['reason']);
        $this->assertSame(['pppoe:pppoe_in_use'], $plan['guard']['reasons']);
        $this->artisan('device-recovery:delete-onus', ['--case' => [$case->id]])
            ->expectsOutputToContain('would_delete=0')
            ->assertSuccessful();
        $this->assertNull($case->fresh()->meta, 'dry run writes nothing');
    }

    public function test_retry_unverified_re_evaluates_the_guard(): void
    {
        config(['device-recovery.mode' => 'write']);
        $this->denyLifecycleGuard();
        $actor = User::factory()->create();
        $case = $this->seedUnverifiedCase();

        $result = app(DeviceRecoveryOltCleanupService::class)->retryUnverified($case, $actor);

        $this->assertSame('skipped_guard_hold', $result['status']);
        $this->assertSame([], OperatorDeleteZteCapture::$deleted);
    }

    private function addReceivedItem(DeviceRecoveryCase $case, string $capturedSerial): void
    {
        DB::table('device_recovery_items')->insert([
            'recovery_case_id' => $case->id,
            'device_type' => 'onu',
            'expected_serial_number' => $capturedSerial,
            'captured_serial_number' => $capturedSerial,
            'expected_source' => 'customer',
            'status' => DeviceRecoveryService::ITEM_RECEIVED,
            'condition' => DeviceRecoveryService::CONDITION_GOOD,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_real_guard_checks_the_case_customer_even_when_collected_serial_is_in_the_other_gpon_form(): void
    {
        config(['device-recovery.mode' => 'write']);
        Setting::set('device_recovery_olt_delete_enabled', '1', 'boolean', 'ops');
        $this->app->forgetInstance(CustomerLifecycleGuard::class);
        $this->captureOpsWa();
        $case = $this->seedCase(10, 'FHTT0000A004');
        // Customer stores the 16-hex form of the very same ONU; he is still online.
        DB::table('customers')->where('id', 10)->update(['onu_serial' => '464854540000A004', 'is_online' => true, 'pppoe_online_checked_at' => now()]);
        $this->addReceivedItem($case, 'FHTT0000A004');

        $result = app(DeviceRecoveryOltCleanupService::class)->deleteForCase($case);

        $this->assertSame('skipped_guard_hold', $result['status']);
        $this->assertSame([], OperatorDeleteZteCapture::$deleted);
    }

    private function seedKeepActiveCase(): DeviceRecoveryCase
    {
        $case = $this->seedCase(10, 'FHTT0000A004');
        // Multi-device home: the customer's own ONU is another device; the collected one is FHTT0000A004.
        DB::table('customers')->where('id', 10)->update(['onu_serial' => 'FHTT0000A002']);
        DB::table('device_recovery_cases')->where('id', $case->id)->update(['keep_customer_active' => true]);
        $this->addReceivedItem($case, 'FHTT0000A004');

        return $case->fresh();
    }

    public function test_keep_active_case_still_checks_another_owner_even_soft_deleted_and_hex_form(): void
    {
        config(['device-recovery.mode' => 'write']);
        Setting::set('device_recovery_olt_delete_enabled', '1', 'boolean', 'ops');
        $this->app->forgetInstance(CustomerLifecycleGuard::class);
        $this->captureOpsWa();
        $case = $this->seedKeepActiveCase();
        DB::table('customers')->insert([
            'id' => 11, 'code' => 'CUST-11', 'name' => 'Suharto', 'phone' => '081200000011', 'address' => 'x',
            'pppoe_user' => 'user11', 'package_id' => 1, 'onu_serial' => '464854540000A004', 'olt_id' => 9,
            'status' => 'terminated', 'is_online' => true, 'pppoe_online_checked_at' => now(),
            'deleted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = app(DeviceRecoveryOltCleanupService::class)->deleteForCase($case);

        $this->assertSame('skipped_guard_hold', $result['status']);
        $this->assertSame([], OperatorDeleteZteCapture::$deleted);
    }

    public function test_normal_dismantle_checks_another_terminated_soft_deleted_owner_of_the_collected_serial(): void
    {
        config(['device-recovery.mode' => 'write']);
        Setting::set('device_recovery_olt_delete_enabled', '1', 'boolean', 'ops');
        $this->app->forgetInstance(CustomerLifecycleGuard::class);
        $this->captureOpsWa();
        $case = $this->seedCase(10, 'FHTT0000A004');
        // The collected serial is also registered to another, trashed customer who is still online.
        DB::table('customers')->where('id', 10)->update(['onu_serial' => 'FHTT0000A002']);
        $this->addReceivedItem($case, 'FHTT0000A004');
        DB::table('customers')->insert([
            'id' => 11, 'code' => 'CUST-11', 'name' => 'Suharto', 'phone' => '081200000011', 'address' => 'x',
            'pppoe_user' => 'user11', 'package_id' => 1, 'onu_serial' => 'FHTT0000A004', 'olt_id' => 9,
            'status' => 'terminated', 'is_online' => true, 'pppoe_online_checked_at' => now(),
            'deleted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = app(DeviceRecoveryOltCleanupService::class)->deleteForCase($case);

        $this->assertSame('skipped_guard_hold', $result['status']);
        $this->assertSame([], OperatorDeleteZteCapture::$deleted);
    }

    public function test_keep_active_case_without_another_owner_ignores_the_customers_other_device(): void
    {
        config(['device-recovery.mode' => 'write']);
        Setting::set('device_recovery_olt_delete_enabled', '1', 'boolean', 'ops');
        $this->app->forgetInstance(CustomerLifecycleGuard::class);
        $case = $this->seedKeepActiveCase();
        DB::table('customers')->where('id', 10)->update(['is_online' => true, 'pppoe_online_checked_at' => now()]);

        $result = app(DeviceRecoveryOltCleanupService::class)->deleteForCase($case);

        $this->assertSame('deleted', $result['status']);
        $this->assertSame([[9, '1/2/1:3']], OperatorDeleteZteCapture::$deleted);
    }

    public function test_hold_wa_is_sent_after_the_olt_lock_is_released(): void
    {
        config(['device-recovery.mode' => 'write']);
        Setting::set('device_recovery_olt_delete_enabled', '1', 'boolean', 'ops');
        $this->denyLifecycleGuard();
        $lockHeldDuringSend = null;
        $ops = Mockery::mock(OpsAlertService::class);
        $ops->shouldReceive('send')->andReturnUsing(function () use (&$lockHeldDuringSend): bool {
            $probe = Cache::lock(OltOperationLock::key(Olt::query()->findOrFail(9)), 5);
            $lockHeldDuringSend = ! $probe->get();
            $probe->release();

            return true;
        });
        $this->app->instance(OpsAlertService::class, $ops);
        $case = $this->seedCase(10, 'FHTT1234');

        app(DeviceRecoveryOltCleanupService::class)->deleteForCase($case);

        $this->assertFalse($lockHeldDuringSend);
    }

    public function test_guard_is_asked_in_case_mode_automated_for_the_cases_customer(): void
    {
        config(['device-recovery.mode' => 'write']);
        $seen = [];
        $guard = Mockery::mock(CustomerLifecycleGuard::class)->makePartial();
        $guard->shouldReceive('canDeleteOnuRef')->andReturnUsing(function (string $mode, $customer, $read, GuardContext $ctx) use (&$seen) {
            $seen = [$mode, $customer?->id, $read['state'] ?? null, $ctx->automated];

            return GuardDecision::fromSignals('delete_onu_case', [GuardSignal::clear(GuardSignal::ONU, 'onu_ref_offline', '')]);
        });
        $this->app->instance(CustomerLifecycleGuard::class, $guard);
        $case = $this->seedCase(10, 'FHTT1234');

        $result = app(DeviceRecoveryOltCleanupService::class)->deleteForCase($case, User::factory()->create(), explicitOperatorRun: true);

        $this->assertSame('deleted', $result['status']);
        $this->assertSame(['case', 10, 'offline', true], $seen);
    }
}

class OperatorDeleteZteCapture
{
    public static array $deleted = [];

    public static ?string $liveOverride = null;

    /** @var list<string> */
    public static array $gone = [];

    public static bool $noop = false;

    public static ?string $state = 'DyingGasp';

    public static bool $readbackThrows = false;

    public static ?\Closure $onDelete = null;

    public static ?string $storedSerial = null;
}
