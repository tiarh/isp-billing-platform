<?php

namespace Tests\Feature;

use App\Models\Olt;
use App\Models\User;
use App\Services\Lifecycle\CustomerLifecycleGuard;
use App\Services\Lifecycle\GuardDecision;
use App\Services\Lifecycle\GuardSignal;
use App\Services\OltZteC300Service;
use App\Support\OltOperationLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Tests\Fixtures\ZteOnuState;
use Tests\TestCase;

#[Group('blocking-core')]
class OltDeleteDuplicateOnusCommandTest extends TestCase
{
    use RefreshDatabase;

    private const SERIAL = 'FHTTC0000A01';

    private const OLD = '1/3/6:2';

    private const NEW = '1/5/5:3';

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('packages')->insert([
            'id' => 1, 'code' => 'PKG-1', 'name' => 'Paket Test', 'price' => 100000,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('olts')->insert([
            'id' => 1, 'name' => 'OLT RA', 'code' => 'OLT-1', 'vendor' => 'zte_c300',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DupOnuFake::reset();
        $this->app->bind(OltZteC300Service::class, fn () => new class extends OltZteC300Service
        {
            public function liveOnuProbe(Olt $olt, string $onuRef): array
            {
                if (isset(DupOnuFake::$throwOn[$onuRef])) {
                    throw new \RuntimeException('boom');
                }
                DupOnuFake::$reads[$onuRef] = (DupOnuFake::$reads[$onuRef] ?? 0) + 1;
                if (DupOnuFake::$onRead !== null) {
                    (DupOnuFake::$onRead)($onuRef, DupOnuFake::$reads[$onuRef]);
                }
                $live = DupOnuFake::$live[$onuRef] ?? null;

                return ZteOnuState::probe($onuRef, $live['phase'] ?? null, $live['serial'] ?? null);
            }

            public function deleteOnu(Olt $olt, string $onuRef): array
            {
                DupOnuFake::$deleted[] = $onuRef;
                if (DupOnuFake::$onDelete !== null) {
                    (DupOnuFake::$onDelete)();
                }
                if (! DupOnuFake::$deleteIsNoop) {
                    unset(DupOnuFake::$live[$onuRef]);
                }

                return ['success' => true, 'verified_absent' => true];
            }
        });
        config(['device-recovery.mode' => 'write']);
    }

    private function seedDuplicate(string $oldPhase = 'DyingGasp', string $newPhase = 'working'): void
    {
        foreach ([self::OLD, self::NEW] as $ref) {
            DB::table('olt_onus')->insert([
                'olt_id' => 1, 'serial_number' => self::SERIAL, 'onu_ref' => $ref, 'onu_name' => 'ONU',
                'pon_port' => substr($ref, 0, (int) strpos($ref, ':')), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DupOnuFake::$live[self::OLD] = ['serial' => self::SERIAL, 'phase' => $oldPhase];
        DupOnuFake::$live[self::NEW] = ['serial' => self::SERIAL, 'phase' => $newPhase];
    }

    private function seedCustomer(?string $label): void
    {
        DB::table('customers')->insert([
            'id' => 10, 'code' => 'CUST-10', 'name' => 'Customer 10', 'phone' => '081200000010',
            'address' => 'x', 'pppoe_user' => 'user10', 'package_id' => 1, 'onu_serial' => self::SERIAL,
            'olt_id' => 1, 'olt_port_label' => $label, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function execute(array $extra = []): PendingCommand
    {
        $actor = User::factory()->create();

        return $this->artisan('olt:delete-duplicate-onus', [
            '--olt' => [1], '--execute' => true, '--actor' => $actor->id, '--sleep' => 0,
        ] + $extra);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->seedDuplicate();

        $this->artisan('olt:delete-duplicate-onus', ['--olt' => [1]])
            ->expectsOutputToContain('DRY RUN (nothing written): rows=2 would_delete=1')
            ->assertSuccessful();

        $this->assertSame([], DupOnuFake::$deleted);
        $this->assertSame(2, DB::table('olt_onus')->count());
    }

    public function test_happy_path_deletes_only_offline_ref(): void
    {
        $this->seedDuplicate();
        $this->seedCustomer(self::NEW);

        $this->execute()->expectsOutputToContain('deleted=1 delete_failed=0')->assertSuccessful();

        $this->assertSame([self::OLD], DupOnuFake::$deleted);
        $this->assertSame([self::NEW], DB::table('olt_onus')->pluck('onu_ref')->all());
    }

    public function test_stale_inventory_row_of_absent_ref_is_purged_on_execute_only(): void
    {
        $this->seedDuplicate();
        // The old ref was already deleted on the OLT (only the inventory row is stale).
        unset(DupOnuFake::$live[self::OLD]);

        $this->artisan('olt:delete-duplicate-onus', ['--olt' => [1]])
            ->expectsOutputToContain('would_delete=0')
            ->assertSuccessful();
        $this->assertSame(2, DB::table('olt_onus')->count());

        $this->execute()->expectsOutputToContain('deleted=0 delete_failed=0')->assertSuccessful();

        $this->assertSame([], DupOnuFake::$deleted);
        $this->assertSame([self::NEW], DB::table('olt_onus')->pluck('onu_ref')->all());
    }

    public function test_busy_olt_lock_refuses_and_writes_nothing(): void
    {
        config(['device-recovery.olt_lock_wait_seconds' => 0]);
        $this->seedDuplicate();
        $held = Cache::lock(OltOperationLock::key(Olt::query()->findOrFail(1)), 60);
        $this->assertTrue($held->get());

        try {
            $this->execute()->assertFailed();
            // The dry run never takes the lock.
            $this->artisan('olt:delete-duplicate-onus', ['--olt' => [1]])->assertSuccessful();
        } finally {
            $held->release();
        }

        $this->assertSame([], DupOnuFake::$deleted);
        $this->assertSame(2, DB::table('olt_onus')->count());
    }

    public function test_psb_or_migration_activity_defers_the_delete(): void
    {
        config(['device-recovery.olt_lock_wait_seconds' => 0]);
        $this->seedDuplicate();
        $olt = Olt::query()->findOrFail(1);

        foreach ([OltOperationLock::psbKey($olt), OltOperationLock::migrationKey($olt, '1/3/6')] as $key) {
            $held = Cache::lock($key, 60);
            $this->assertTrue($held->get());
            try {
                $this->execute()->assertFailed();
            } finally {
                $held->release();
            }
        }

        $this->assertSame([], DupOnuFake::$deleted);
        $this->assertSame(2, DB::table('olt_onus')->count());
    }

    public function test_lock_is_taken_per_delete_and_never_holds_the_psb_key(): void
    {
        $this->seedDuplicate();
        $olt = Olt::query()->findOrFail(1);
        $seen = [];
        DupOnuFake::$onDelete = function () use ($olt, &$seen): void {
            $delete = Cache::lock(OltOperationLock::key($olt), 5);
            $psb = Cache::lock(OltOperationLock::psbKey($olt), 5);
            $seen = ['delete_key_free' => $delete->get(), 'psb_key_free' => $psb->get()];
            if ($seen['delete_key_free']) {
                $delete->release();
            }
            if ($seen['psb_key_free']) {
                $psb->release();
            }
        };

        $this->execute()->assertSuccessful();

        $this->assertSame([self::OLD], DupOnuFake::$deleted);
        $this->assertFalse($seen['delete_key_free'], 'delete lock must be held during the delete');
        $this->assertTrue($seen['psb_key_free'], 'PSB key must never be held by a delete');
    }

    public function test_lock_is_released_after_execute(): void
    {
        $this->seedDuplicate();

        $this->execute()->assertSuccessful();

        $lock = Cache::lock(OltOperationLock::key(Olt::query()->findOrFail(1)), 5);
        $this->assertTrue($lock->get());
        $lock->release();
    }

    public function test_serial_subset_filters(): void
    {
        $this->seedDuplicate();

        $this->execute(['--serial' => ['FHTTOTHER']])->expectsOutputToContain('deleted=0')->assertSuccessful();
        $this->assertSame([], DupOnuFake::$deleted);
    }

    public function test_both_online_is_skipped(): void
    {
        $this->seedDuplicate('working', 'working');

        $this->execute()->assertSuccessful();

        $this->assertSame([], DupOnuFake::$deleted);
        $this->assertSame(2, DB::table('olt_onus')->count());
    }

    public function test_both_offline_is_skipped(): void
    {
        $this->seedDuplicate('LOS', 'OffLine');

        $this->execute()->assertSuccessful();

        $this->assertSame([], DupOnuFake::$deleted);
        $this->assertSame(2, DB::table('olt_onus')->count());
    }

    public function test_unknown_state_is_skipped(): void
    {
        foreach (['syncMib', 'authpass', 'logging', 'authFail', 'O6'] as $phase) {
            DB::table('olt_onus')->delete();
            $this->seedDuplicate($phase, 'working');

            $this->execute()->assertSuccessful();
        }

        $this->assertSame([], DupOnuFake::$deleted);
    }

    public function test_target_turning_online_before_delete_is_rechecked_and_skipped(): void
    {
        $this->seedDuplicate();
        DupOnuFake::$onRead = function (string $ref, int $n): void {
            // 1st read = plan, 2nd read = pre-delete recheck: the old ref came back online.
            if ($ref === self::OLD && $n === 2) {
                DupOnuFake::$live[self::OLD]['phase'] = 'working';
            }
        };

        $this->execute()->expectsOutputToContain('deleted=0')->assertSuccessful();

        $this->assertSame([], DupOnuFake::$deleted);
        $this->assertSame(2, DB::table('olt_onus')->count());
    }

    public function test_working_ref_going_offline_before_delete_is_rechecked_and_skipped(): void
    {
        $this->seedDuplicate();
        DupOnuFake::$onRead = function (string $ref, int $n): void {
            if ($ref === self::NEW && $n === 2) {
                DupOnuFake::$live[self::NEW]['phase'] = 'DyingGasp';
            }
        };

        $this->execute()->assertSuccessful();

        $this->assertSame([], DupOnuFake::$deleted);
    }

    public function test_per_ref_rule_pin_ignores_customer_pppoe_and_traffic_roadmap_1_3(): void
    {
        // The customer legitimately uses the working twin: PPPoE online + traffic
        // must NOT block deleting the OFFLINE duplicate ref (guard duplicate mode).
        $this->seedDuplicate();
        $this->seedCustomer(self::NEW);
        DB::table('customers')->where('id', 10)->update(['is_online' => true, 'pppoe_online_checked_at' => now()]);

        $this->execute()->expectsOutputToContain('deleted=1 delete_failed=0')->assertSuccessful();

        $this->assertSame([self::OLD], DupOnuFake::$deleted);
    }

    public function test_guard_in_duplicate_mode_is_the_last_word_before_the_delete(): void
    {
        $this->seedDuplicate();
        $seen = [];
        $guard = Mockery::mock(CustomerLifecycleGuard::class)->makePartial();
        $guard->shouldReceive('canDeleteOnuRef')->andReturnUsing(function (string $mode, $customer, $read, $ctx, $twin) use (&$seen) {
            $seen = [$mode, $customer, $read['state'], $twin['state'], $ctx->automated];

            return GuardDecision::fromSignals('delete_onu_duplicate', [GuardSignal::unknown('onu_twin', 'twin_not_online', 'x')]);
        });
        $this->app->instance(CustomerLifecycleGuard::class, $guard);

        $this->execute()->expectsOutputToContain('deleted=0')->assertSuccessful();

        $this->assertSame([], DupOnuFake::$deleted);
        $this->assertSame(['duplicate', null, 'offline', 'online', true], $seen);
    }

    public function test_terminated_former_owner_neither_blocks_nor_allows(): void
    {
        $this->seedDuplicate();
        $this->seedCustomer(self::OLD);
        DB::table('customers')->update(['status' => 'terminated']);

        $this->execute()->assertSuccessful();

        $this->assertSame([self::OLD], DupOnuFake::$deleted);
    }

    public function test_live_read_failure_is_skipped(): void
    {
        $this->seedDuplicate();
        DupOnuFake::$throwOn[self::NEW] = true;

        $this->execute()->assertSuccessful();

        $this->assertSame([], DupOnuFake::$deleted);
    }

    public function test_live_serial_mismatch_is_skipped(): void
    {
        $this->seedDuplicate();
        DupOnuFake::$live[self::OLD]['serial'] = 'FHTT0000A005';

        $this->artisan('olt:delete-duplicate-onus', ['--olt' => [1]])
            ->expectsOutputToContain('would_delete=0')
            ->assertSuccessful();
        $this->execute()->assertSuccessful();

        $this->assertSame([], DupOnuFake::$deleted);
    }

    public function test_customer_with_ref_pointing_to_offline_ref_is_skipped(): void
    {
        $this->seedDuplicate();
        $this->seedCustomer(self::OLD);

        $this->artisan('olt:delete-duplicate-onus', ['--olt' => [1]])
            ->expectsOutputToContain('customer_ref_mismatch')
            ->assertSuccessful();
        $this->execute()->assertSuccessful();

        $this->assertSame([], DupOnuFake::$deleted);
        $this->assertSame(2, DB::table('olt_onus')->count());
    }

    public function test_customer_with_port_only_label_does_not_block(): void
    {
        $this->seedDuplicate();
        $this->seedCustomer('1/5/5');

        $this->execute()->assertSuccessful();

        $this->assertSame([self::OLD], DupOnuFake::$deleted);
    }

    public function test_readback_still_showing_serial_is_delete_failed(): void
    {
        $this->seedDuplicate();
        DupOnuFake::$deleteIsNoop = true;

        $this->execute()->expectsOutputToContain('delete_failed=1')->assertFailed();

        $this->assertSame([self::OLD], DupOnuFake::$deleted);
        $this->assertSame(2, DB::table('olt_onus')->count());
    }

    public function test_non_zte_olt_is_refused(): void
    {
        DB::table('olts')->insert([
            'id' => 2, 'name' => 'OLT HIOSO', 'code' => 'OLT-2', 'vendor' => 'hioso',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('olt:delete-duplicate-onus', ['--olt' => [2]])->assertFailed();
    }

    public function test_execute_without_actor_is_refused(): void
    {
        $this->seedDuplicate();

        $this->artisan('olt:delete-duplicate-onus', ['--olt' => [1], '--execute' => true, '--sleep' => 0])->assertFailed();

        $this->assertSame([], DupOnuFake::$deleted);
    }

    public function test_read_only_mode_is_refused(): void
    {
        config(['device-recovery.mode' => 'read_only']);
        $this->seedDuplicate();

        $this->execute()->assertFailed();

        $this->assertSame([], DupOnuFake::$deleted);
        $this->assertSame(2, DB::table('olt_onus')->count());
    }
}

class DupOnuFake
{
    /** @var array<string, array{serial: ?string, state: ?string, phase: ?string}> */
    public static array $live = [];

    /** @var list<string> */
    public static array $deleted = [];

    /** @var array<string, bool> */
    public static array $throwOn = [];

    public static bool $deleteIsNoop = false;

    /** @var array<string, int> */
    public static array $reads = [];

    public static ?\Closure $onRead = null;

    public static ?\Closure $onDelete = null;

    public static function reset(): void
    {
        self::$onDelete = null;
        self::$reads = [];
        self::$onRead = null;
        self::$live = [];
        self::$deleted = [];
        self::$throwOn = [];
        self::$deleteIsNoop = false;
    }
}
