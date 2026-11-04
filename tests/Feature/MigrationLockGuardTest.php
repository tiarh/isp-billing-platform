<?php

namespace Tests\Feature;

use App\Exceptions\LockBusyException;
use App\Http\Controllers\OltC300Controller;
use App\Jobs\ExecuteCustomerMigrationWaJob;
use App\Jobs\ProcessCustomerMigrationWaJob;
use App\Models\Customer;
use App\Models\CustomerMigration;
use App\Models\DeviceActionLog;
use App\Models\Olt;
use App\Models\OltOnu;
use App\Models\Package;
use App\Models\PppoeAddressPoolBlock;
use App\Models\Router;
use App\Models\Setting;
use App\Services\CustomerMigrationMessageParser;
use App\Services\CustomerMigrationService;
use App\Services\GenieAcsService;
use App\Services\GuidedMigrationLookupService;
use App\Services\HuaweiOltOnlyProvisioningService;
use App\Services\MikrotikService;
use App\Services\PsbOrchestratorService;
use App\Support\MigrationLockMessages;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Canary;
use Tests\TestCase;

/**
 * Roadmap 1.1 PR-B (migration sites): a busy lock must give one clear answer,
 * leave state unchanged and never auto-retry. Real held locks, no sleeping
 * (config locks.wait_cap_seconds = 0). All devices are fakes.
 */
#[Group('blocking-psb')]
class MigrationLockGuardTest extends TestCase
{
    use RefreshDatabase;

    private const GROUP = 'activation@g.us';

    private const TECH = '6281200000003@s.whatsapp.net';

    private const NOC = '6281200000002@s.whatsapp.net';

    private const NEW_SN = 'FHTT0000A00E';

    private const PPPOE = 'USERAB27_RT1_RW2';

    /** @var list<string> */
    private array $replies = [];

    /** @var list<object> */
    private array $held = [];

    private object $mikrotik;

    protected function setUp(): void
    {
        parent::setUp();
        config(['locks.wait_cap_seconds' => 0]);

        Setting::set(GuidedMigrationLookupService::FEATURE_FLAG, '1');
        Setting::set(GuidedMigrationLookupService::ALLOWED_GROUP_JIDS, self::GROUP);
        Setting::set(GuidedMigrationLookupService::ALLOWED_SENDER_JIDS, self::NOC);
        Setting::set('migration_wa_only_live_enabled', '1');
        Setting::set('control_plane.device_read_only_mode', '0');

        $psb = Mockery::mock(PsbOrchestratorService::class)->shouldIgnoreMissing();
        $psb->shouldReceive('reply')->andReturnUsing(function (string $group, string $text): bool {
            $this->replies[] = $text;

            return true;
        });
        $psb->shouldReceive('isAllowedGroup')->andReturnFalse();
        $this->app->instance(PsbOrchestratorService::class, $psb);

        $genie = Mockery::mock(GenieAcsService::class)->shouldIgnoreMissing();
        $genie->shouldReceive('resolvePreferredTag')->andReturnUsing(fn (string $pppoe): string => $pppoe);
        $genie->shouldReceive('ensureTagAfterProvision')->andReturn(['success' => true, 'pushed' => true]);
        $this->app->instance(GenieAcsService::class, $genie);

        $this->mikrotik = new class extends MikrotikService
        {
            /** @var array<int, array<string, array<string, mixed>>> */
            public array $secrets = [];

            public int $current = 0;

            /** @var list<string> */
            public array $writes = [];

            public function connect(Router $router, array $options = []): self
            {
                $this->current = (int) $router->id;

                return $this;
            }

            public function disconnect(): void {}

            public function getPPPSecret(string $username): ?array
            {
                return $this->secrets[$this->current][$username] ?? null;
            }

            public function getProfiles(): array
            {
                return [['name' => '10MB']];
            }

            public function getPPPSecretsWithAddresses(): array
            {
                return [];
            }

            public function addPPPSecret(
                string $username,
                string $password,
                string $profile,
                ?string $localAddress = null,
                ?string $remoteAddress = null,
                ?string $service = 'pppoe',
                ?string $comment = null
            ): array {
                $this->writes[] = "add:{$this->current}:{$username}";
                $this->secrets[$this->current][$username] = [
                    '.id' => '*new',
                    'name' => $username,
                    'password' => $password,
                    'profile' => $profile,
                    'local-address' => $localAddress ?? '198.19.1.1',
                    'remote-address' => $remoteAddress ?? '198.19.1.20',
                ];

                return ['success' => true, 'pushed' => true, 'message' => 'PPPoE secret added', 'secret_id' => '*new'];
            }

            public function disablePPPSecret(string $username): array
            {
                $this->writes[] = "disable:{$this->current}:{$username}";
                $this->secrets[$this->current][$username]['disabled'] = 'true';

                return ['success' => true, 'message' => 'disabled'];
            }

            public function renamePPPSecret(
                string $oldUsername,
                string $newUsername,
                ?string $password = null,
                ?string $profile = null,
                ?string $localAddress = null,
                ?string $remoteAddress = null,
                ?string $comment = null
            ): array {
                $this->writes[] = "rename:{$this->current}:{$oldUsername}";

                return ['success' => true];
            }
        };
        $this->app->instance(MikrotikService::class, $this->mikrotik);

        $this->app->instance(OltC300Controller::class, new class extends OltC300Controller
        {
            public function __construct() {}

            public function listUncfg(Request $request)
            {
                return response()->json(['onus' => []]);
            }
        });
    }

    protected function tearDown(): void
    {
        foreach ($this->held as $lock) {
            $lock->release();
        }
        $this->held = [];
        parent::tearDown();
    }

    // M-texts (verbatim, owner approved)
    public function test_texts_are_the_owner_approved_strings(): void
    {
        $this->assertSame('Migrasi dengan PPPoE/SN yang sama sedang diproses. Data aman, tidak diubah. Tunggu hasil bot +-1 menit; jangan kirim ulang.', MigrationLockMessages::sameOperationBusy());
        $this->assertSame('Migrasi #7 sedang direview proses lain. Data aman. Cek +-1 menit lagi: #migrasi cek #7', MigrationLockMessages::reviewBusy(7));
        $this->assertSame('Migrasi #7 sedang diproses perangkat. Data aman, tidak ada yang diubah. Ulangi #migrasi konfirmasi #7 +-1 menit lagi.', MigrationLockMessages::deviceBusyConfirm(7));
        $this->assertSame('Migrasi #7 sedang diproses perangkat. Belum ada eksekusi baru. Kirim ulang #migrasi eksekusi #7 +-1 menit lagi.', MigrationLockMessages::deviceBusyExecute(7));
        $this->assertSame('Migrasi #7 sedang diproses perangkat. SN tidak diubah. Ulangi #migrasi sn #7 <SN> +-1 menit lagi.', MigrationLockMessages::deviceBusySn(7));
        $this->assertSame('Antrian IP router R1 sedang dipakai. Migrasi #7 belum mengubah MikroTik/OLT. Kirim ulang #migrasi eksekusi #7 +-1 menit lagi.', MigrationLockMessages::ipQueueBusy(7, 'R1'));
        $this->assertSame('OLT O1 port 1/2/2 sedang dipakai proses lain; OLT belum diubah. Coba lagi +-1 menit.', MigrationLockMessages::ponBusy('O1', '1/2/2'));
    }

    // M1
    public function test_m1_same_operation_lock_busy_replies_once_and_creates_nothing(): void
    {
        Setting::set(GuidedMigrationLookupService::FEATURE_FLAG, '0');
        $this->fixture();
        $before = CustomerMigration::query()->count();
        $payload = $this->payload("#migrasi\nNama: SENO\nPPPoE: ".self::PPPOE."\nSN Baru: ".self::NEW_SN."\nRouter baru: Skynet-Krian-OLT", self::TECH);
        $parsed = app(CustomerMigrationMessageParser::class)->parse($payload['text']);
        $method = new \ReflectionMethod(CustomerMigrationService::class, 'migrationOperationLockKey');
        $method->setAccessible(true);
        $this->hold((string) $method->invoke(app(CustomerMigrationService::class), $payload, $parsed));

        app(CustomerMigrationService::class)->handleInbound($payload);

        $this->assertSame([MigrationLockMessages::sameOperationBusy()], $this->replies);
        $this->assertSame($before, CustomerMigration::query()->count());
    }

    // M2
    public function test_m2_review_lock_busy_keeps_state(): void
    {
        [$migration] = $this->fixture();
        $migration->forceFill(['last_error' => 'keep me'])->save();
        $this->hold('customer-migration-review-'.$migration->id);

        $result = app(CustomerMigrationService::class)->prepareReviewDraft($migration->fresh());

        $this->assertSame([MigrationLockMessages::reviewBusy($migration->id)], $this->replies);
        $this->assertSame($migration->status, $result->status);
        $this->assertSame('keep me', $migration->fresh()->last_error);
    }

    // M3 + B6
    public function test_m3_execution_lock_busy_reverts_ledger_and_single_resend_works(): void
    {
        [$migration, , $target] = $this->fixture();
        $ledgerId = (int) data_get($migration->meta, 'device_action_approval.ledger_id');
        $lock = $this->hold('customer-migration-'.$migration->id);

        $result = app(CustomerMigrationService::class)->executeWaMigration($migration->id, $this->payload('#migrasi eksekusi #'.$migration->id, self::TECH));

        $this->assertSame([MigrationLockMessages::deviceBusyExecute($migration->id)], $this->replies);
        $this->assertSame([], $this->mikrotik->writes);
        $ledger = DeviceActionLog::query()->findOrFail($ledgerId);
        $this->assertSame('dry_run', $ledger->status, 'B6: ledger must be back to dry_run, not failed/manual reconciliation');
        $this->assertNull($ledger->executed_at);
        $this->assertSame('dry_run_ready', data_get($migration->fresh()->meta, 'device_action_approval.status'));
        $this->assertNotSame(CustomerMigration::STATUS_FAILED, $result->status);
        $this->assertNull(data_get($ledger->result, 'manual_reconciliation_required'));

        // A single resend works once the lock is free.
        $lock->release();
        $this->replies = [];
        app(CustomerMigrationService::class)->executeWaMigration($migration->id, $this->payload('#migrasi eksekusi #'.$migration->id, self::TECH));
        $this->assertSame(["add:{$target->id}:".self::PPPOE, 'disable:'.$migration->old_router_id.':'.self::PPPOE], $this->mikrotik->writes);
        $this->assertContains($ledger->fresh()->status, ['executed', 'verified']);
    }

    // M4 (confirm/dry-run path)
    public function test_m4_prepare_approval_device_lock_busy_throws_clear_message_and_creates_no_ledger(): void
    {
        [$migration] = $this->fixture(false);
        $this->hold('customer-migration-device-'.$migration->id);
        $ledgers = DeviceActionLog::query()->count();

        try {
            app(CustomerMigrationService::class)->prepareDeviceActionApproval($migration, null, 'Teknisi konfirmasi migrasi');
            $this->fail('expected LockBusyException');
        } catch (LockBusyException $e) {
            $this->assertSame(MigrationLockMessages::deviceBusyConfirm($migration->id), $e->userMessage());
        }
        $this->assertSame($ledgers, DeviceActionLog::query()->count());
        $this->assertSame([], $this->replies);
    }

    // M5
    public function test_m5_execute_device_lock_busy_throws_and_leaves_ledger_untouched(): void
    {
        [$migration] = $this->fixture();
        $ledgerId = (int) data_get($migration->meta, 'device_action_approval.ledger_id');
        $this->hold('customer-migration-device-'.$migration->id);

        try {
            app(CustomerMigrationService::class)->executeApprovedDeviceAction($migration, null, 'Eksekusi migrasi uji');
            $this->fail('expected LockBusyException');
        } catch (LockBusyException $e) {
            $this->assertSame(MigrationLockMessages::deviceBusyExecute($migration->id), $e->userMessage());
        }
        $this->assertSame('dry_run', DeviceActionLog::query()->findOrFail($ledgerId)->status);
        $this->assertSame([], $this->mikrotik->writes);
    }

    // M5 via WA worker: clear reply, no exception
    public function test_m5_wa_execute_device_lock_busy_replies_and_does_not_throw(): void
    {
        [$migration] = $this->fixture();
        $this->hold('customer-migration-device-'.$migration->id);

        $result = app(CustomerMigrationService::class)->executeWaMigration($migration->id, $this->payload('#migrasi eksekusi #'.$migration->id, self::TECH));

        $this->assertSame([MigrationLockMessages::deviceBusyExecute($migration->id)], $this->replies);
        $this->assertSame($migration->id, $result->id);
        $this->assertSame([], $this->mikrotik->writes);
    }

    // M6
    public function test_m6_dispatch_on_executed_ledger_with_device_lock_busy_replies_and_queues_nothing(): void
    {
        Queue::fake([ExecuteCustomerMigrationWaJob::class]);
        [$migration] = $this->fixture();
        app(CustomerMigrationService::class)->executeWaMigration($migration->id, $this->payload('#migrasi eksekusi #'.$migration->id, self::TECH));
        $this->assertSame(CustomerMigration::STATUS_PARTIALLY_EXECUTED, $migration->fresh()->status);
        $this->hold('customer-migration-device-'.$migration->id);
        $ledgerCount = DeviceActionLog::query()->count();
        $this->replies = [];

        app(CustomerMigrationService::class)->handleInbound($this->payload('#migrasi eksekusi #'.$migration->id, self::TECH));

        $this->assertSame([MigrationLockMessages::deviceBusyExecute($migration->id)], $this->replies);
        Queue::assertNothingPushed();
        $this->assertSame($ledgerCount, DeviceActionLog::query()->count(), 'no continuation dry-run while busy');
        $this->assertSame(CustomerMigration::STATUS_PARTIALLY_EXECUTED, $migration->fresh()->status);
    }

    // M7: no accidental 120x auto-resume
    public function test_m7_live_job_on_busy_device_lock_replies_and_is_not_retried(): void
    {
        [$migration] = $this->fixture();
        app(CustomerMigrationService::class)->executeWaMigration($migration->id, $this->payload('#migrasi eksekusi #'.$migration->id, self::TECH));
        $this->assertSame(CustomerMigration::STATUS_PARTIALLY_EXECUTED, $migration->fresh()->status);
        $this->hold('customer-migration-device-'.$migration->id);
        $this->replies = [];
        $writes = $this->mikrotik->writes;

        $queueJob = Mockery::mock(QueueJob::class);
        $queueJob->shouldNotReceive('release');
        $queueJob->shouldNotReceive('fail');
        $job = new ExecuteCustomerMigrationWaJob($migration->id, $this->payload('#migrasi eksekusi #'.$migration->id, self::TECH));
        $job->setJob($queueJob);

        $job->handle(app(CustomerMigrationService::class)); // must not throw

        $this->assertSame([MigrationLockMessages::deviceBusyExecute($migration->id)], $this->replies);
        $this->assertSame($writes, $this->mikrotik->writes);
        $this->assertSame(CustomerMigration::STATUS_PARTIALLY_EXECUTED, $migration->fresh()->status);
    }

    // M8 + B5
    public function test_m8_pon_lock_busy_after_mikrotik_sends_one_partial_message_never_gagal(): void
    {
        [$migration, , $target, $olt] = $this->fixture(true, '1/2/2');
        $this->hold('device-olt-pon-'.$olt->id.'-1/2/2');

        app(CustomerMigrationService::class)->executeWaMigration($migration->id, $this->payload('#migrasi eksekusi #'.$migration->id, self::TECH));

        $this->assertCount(1, $this->replies, 'B5: exactly one message');
        $reply = $this->replies[0];
        $this->assertStringContainsString('SEBAGIAN DIEKSEKUSI', $reply);
        $this->assertStringContainsString(MigrationLockMessages::ponBusy('OLT KRIAN', '1/2/2'), $reply);
        $this->assertStringNotContainsString('gagal', strtolower($reply));
        $migration->refresh();
        $this->assertSame(CustomerMigration::STATUS_PARTIALLY_EXECUTED, $migration->status);
        $this->assertContains("add:{$target->id}:".self::PPPOE, $this->mikrotik->writes);
        $this->assertNull($migration->olt_at, 'OLT must not be touched');
        $this->assertStringContainsString('sebagian dieksekusi', (string) $migration->last_error);
        // B6 must never revert once a device write happened.
        $ledgerId = (int) data_get($migration->meta, 'device_action_approval.ledger_id');
        $this->assertSame('executed', DeviceActionLog::query()->findOrFail($ledgerId)->status);
        $this->assertSame('executed', data_get($migration->meta, 'device_action_approval.status'));
    }

    // Hardening: a direct write marker wins over partialExecutionState() (null for Huawei-only)
    public function test_write_marker_present_but_partial_state_null_never_reverts(): void
    {
        [$migration, $source, $target, $olt, $customer] = $this->fixture(true, '1/2/2');
        $ledgerId = (int) data_get($migration->meta, 'device_action_approval.ledger_id');
        $ledger = DeviceActionLog::query()->findOrFail($ledgerId);
        $ledger->forceFill(['status' => 'executing'])->save();
        $customer->forceFill(['router_id' => $target->id])->save();
        $this->mikrotik->secrets[$target->id][self::PPPOE] = ['name' => self::PPPOE, 'password' => Canary::of('source'), 'profile' => '10MB'];
        $meta = $migration->meta;
        $meta['migration_operation_mode'] = HuaweiOltOnlyProvisioningService::MODE; // partialExecutionState() => null
        $meta['mikrotik_migration'] = [
            'ok' => true,
            'cross_router' => true,
            'old_username' => self::PPPOE,
            'new_username' => self::PPPOE,
            'source_router' => ['id' => $source->id, 'name' => $source->name],
            'target_router' => ['id' => $target->id, 'name' => $target->name],
        ];
        $migration->forceFill(['meta' => $meta])->save();
        $service = app(CustomerMigrationService::class);
        $this->assertNull($service->partialExecutionState($migration->fresh()));
        $this->hold('device-olt-pon-'.$olt->id.'-1/2/2');

        $method = new \ReflectionMethod(CustomerMigrationService::class, 'executeMigration');
        $method->setAccessible(true);
        $result = $method->invoke($service, $migration->fresh()); // must not throw LockBusyException

        $this->assertSame(MigrationLockMessages::ponBusy('OLT KRIAN', '1/2/2'), $result->last_error);
        $this->assertSame('executing', $ledger->fresh()->status, 'never reverted to dry_run after a write marker');
        $this->assertSame([], $this->replies, 'no "gagal" reply');
    }

    // Busy catches of the confirm / dry-run callers
    public function test_confirm_guided_selection_busy_replies_and_prepares_nothing(): void
    {
        [$migration] = $this->fixture(false);
        $meta = $migration->meta;
        $keys = [preg_replace('/\D/', '', self::NOC)];
        $meta['guided_wa_selection']['selected_by_sender_keys'] = $keys;
        $meta['wa_origin_sender_keys'] = $keys;
        $migration->forceFill(['meta' => $meta, 'sender_jid' => self::NOC, 'last_error' => 'keep me'])->save();
        $this->hold('customer-migration-device-'.$migration->id);
        $ledgers = DeviceActionLog::query()->count();

        app(CustomerMigrationService::class)->handleInbound($this->payload('#migrasi konfirmasi #'.$migration->id, self::NOC));

        $this->assertSame([MigrationLockMessages::deviceBusyConfirm($migration->id)], $this->replies);
        $this->assertSame($ledgers, DeviceActionLog::query()->count());
        $this->assertSame('keep me', $migration->fresh()->last_error);
        $this->assertSame([], $this->mikrotik->writes);
    }

    public function test_confirm_huawei_olt_only_busy_replies_and_clears_stale_last_error(): void
    {
        [$migration] = $this->fixture(false);
        $meta = $migration->meta;
        $meta['migration_operation_mode'] = HuaweiOltOnlyProvisioningService::MODE;
        $migration->forceFill(['meta' => $meta, 'last_error' => 'keep me'])->save();
        $this->hold('customer-migration-device-'.$migration->id);
        $ledgers = DeviceActionLog::query()->count();

        $method = new \ReflectionMethod(CustomerMigrationService::class, 'confirmHuaweiOltOnlyFromWa');
        $method->setAccessible(true);
        $method->invoke(app(CustomerMigrationService::class), $migration->fresh(), $this->payload('#migrasi konfirmasi', self::NOC));

        $this->assertSame([MigrationLockMessages::deviceBusyConfirm($migration->id)], $this->replies);
        $this->assertSame($ledgers, DeviceActionLog::query()->count());
        $this->assertSame('keep me', $migration->fresh()->last_error, 'stale "menyiapkan dry-run" text must not stay');
        $this->assertSame([], $this->mikrotik->writes);
    }

    public function test_confirm_replacement_busy_replies_and_prepares_nothing(): void
    {
        [$migration] = $this->fixture(false);
        $migration->forceFill(['last_error' => 'keep me'])->save();
        $this->hold('customer-migration-device-'.$migration->id);
        $ledgers = DeviceActionLog::query()->count();

        $method = new \ReflectionMethod(CustomerMigrationService::class, 'confirmReplacementFromWa');
        $method->setAccessible(true);
        $method->invoke(app(CustomerMigrationService::class), $migration->fresh(), $this->payload('#migrasi konfirmasi', self::NOC), [
            'operation_reference' => 'REF-1',
            'operation_key' => 'KEY-1',
        ]);

        $this->assertSame([MigrationLockMessages::deviceBusyConfirm($migration->id)], $this->replies);
        $this->assertSame($ledgers, DeviceActionLog::query()->count());
        $this->assertSame('keep me', $migration->fresh()->last_error);
        $this->assertSame([], $this->mikrotik->writes);
    }

    public function test_create_guided_draft_dry_run_busy_shows_message_without_last_error(): void
    {
        [, $source, , , $first] = $this->fixture(false);
        Customer::create([
            'code' => 'KRN-2001',
            'name' => 'BUDI',
            'phone' => '081200000033',
            'address' => 'RT 3 RW 4 Krian',
            'package_id' => $first->package_id,
            'pppoe_user' => 'BUDIRT3_RW4',
            'mikrotik_profile' => '10MB',
            'onu_serial' => 'ZTEGOLD02001',
            'olt_port_label' => '1/1/1:6',
            'odp_name' => 'ODP-KRN-02',
            'router_id' => $source->id,
            'olt_id' => $first->olt_id,
            'status' => 'active',
        ]);
        $nextId = (int) CustomerMigration::query()->max('id') + 1;
        $this->hold('customer-migration-device-'.$nextId);
        $ledgers = DeviceActionLog::query()->count();

        app(CustomerMigrationService::class)->handleInbound($this->payload(
            "#migrasi\nNama: BUDI\nPPPoE: BUDIRT3_RW4\nSN Baru: FHTT0000A00F\nRouter baru: Skynet-Krian-OLT",
            self::NOC
        ));

        $joined = implode("\n", $this->replies);
        $this->assertStringContainsString(MigrationLockMessages::deviceBusyConfirm($nextId), $joined);
        $this->assertSame($ledgers, DeviceActionLog::query()->count());
        $draft = CustomerMigration::query()->find($nextId);
        $this->assertNotNull($draft);
        $this->assertStringNotContainsString('Dry-run otomatis tertahan', (string) $draft->last_error);
        $this->assertSame([], $this->mikrotik->writes);
    }

    // M9
    public function test_m9_sn_correction_device_lock_busy_keeps_sn(): void
    {
        [$migration] = $this->fixture();
        $this->hold('customer-migration-device-'.$migration->id);

        app(CustomerMigrationService::class)->handleInbound($this->payload('#migrasi sn #'.$migration->id.' FHTT0000A00D', self::TECH));

        $this->assertSame([MigrationLockMessages::deviceBusySn($migration->id)], $this->replies);
        $this->assertSame(self::NEW_SN, $migration->fresh()->new_sn);
    }

    // M10 (M-4: allocator queue) + B6
    public function test_m10_allocator_queue_busy_before_mikrotik_write_reverts_ledger_and_resend_works(): void
    {
        [$migration, , $target] = $this->fixture();
        PppoeAddressPoolBlock::create([
            'router_id' => $target->id,
            'block_name' => 'block-11',
            'block_index' => 11,
            'address_format' => 'full_ipv4',
            'prefix_template' => '198.18.11',
            'local_address' => '198.18.11.1',
            'remote_start' => '198.18.11.2',
            'remote_end' => '198.18.11.254',
            'next_block_index' => null,
            'allocator_mode' => 'last_remote_pointer',
            'priority' => 11,
            'is_active' => true,
            'warning_threshold' => 10,
        ]);
        $ledgerId = (int) data_get($migration->meta, 'device_action_approval.ledger_id');
        $lock = $this->hold('pppoe-address-pool-router-'.$target->id);

        app(CustomerMigrationService::class)->executeWaMigration($migration->id, $this->payload('#migrasi eksekusi #'.$migration->id, self::TECH));

        $this->assertSame([MigrationLockMessages::ipQueueBusy($migration->id, 'Skynet-Krian-OLT')], $this->replies);
        $this->assertSame([], $this->mikrotik->writes);
        $this->assertSame('dry_run', DeviceActionLog::query()->findOrFail($ledgerId)->status);
        $this->assertNotSame(CustomerMigration::STATUS_FAILED, $migration->fresh()->status);

        $lock->release();
        $this->replies = [];
        app(CustomerMigrationService::class)->executeWaMigration($migration->id, $this->payload('#migrasi eksekusi #'.$migration->id, self::TECH));
        $this->assertNotSame([], $this->mikrotik->writes);
        $this->assertContains(DeviceActionLog::query()->findOrFail($ledgerId)->status, ['executed', 'verified']);
    }

    // M11 safety net
    public function test_m11_process_job_safety_net_replies_without_release_or_rethrow(): void
    {
        $service = Mockery::mock(CustomerMigrationService::class);
        $service->shouldReceive('handleInbound')->once()->andThrow(new LockBusyException('Pesan sibuk uji.'));
        $service->shouldReceive('reply')->once()->with(self::GROUP, 'Pesan sibuk uji.');

        $queueJob = Mockery::mock(QueueJob::class);
        $queueJob->shouldNotReceive('release');
        $queueJob->shouldNotReceive('fail');
        $job = new ProcessCustomerMigrationWaJob($this->payload('#migrasi cek #1', self::TECH));
        $job->setJob($queueJob);

        $job->handle($service); // no throw, no release
        $this->addToAssertionCount(1);
    }

    // M12 static
    public function test_m12_no_dead_block_branch_left_in_migration_service(): void
    {
        $source = (string) file_get_contents(base_path('app/Services/CustomerMigrationService.php'));
        $this->assertSame(0, preg_match('/->block\(/', $source));
    }

    /**
     * Cross-router incident-style fixture with a prepared dry-run.
     *
     * @return array{0: CustomerMigration, 1: Router, 2: Router, 3: Olt, 4: Customer}
     */
    private function fixture(bool $prepare = true, ?string $ponPort = null): array
    {
        $source = Router::create(['name' => 'Krian', 'ip_address' => '127.0.0.15', 'username' => 'admin', 'password' => Canary::of('pw'), 'is_active' => true]);
        $target = Router::create(['name' => 'Skynet-Krian-OLT', 'ip_address' => '127.0.0.14', 'username' => 'admin', 'password' => Canary::of('pw'), 'is_active' => true]);
        $oldOlt = Olt::create(['name' => 'OLT KRIAN HTB', 'code' => 'OLT-KRIAN-HTB', 'vendor' => 'zte_c300', 'router_id' => $source->id]);
        $olt = Olt::create(['name' => 'OLT KRIAN', 'code' => 'OLT-KRIAN', 'vendor' => 'zte_c300', 'router_id' => $target->id]);
        $package = Package::create(['code' => 'KRIAN-10', 'name' => 'Krian 10MB', 'price' => 150000, 'mikrotik_profile' => '10MB', 'router_id' => $source->id]);
        $customer = Customer::create([
            'code' => 'KRN-1102',
            'name' => 'SENO',
            'phone' => '081200000032',
            'address' => 'RT 1 RW 2 Krian',
            'package_id' => $package->id,
            'pppoe_user' => self::PPPOE,
            'mikrotik_profile' => '10MB',
            'onu_serial' => 'ZTEGOLD01102',
            'olt_port_label' => '1/1/1:5',
            'odp_name' => 'ODP-KRN-01',
            'ktp_photo_url' => 'customers/ktp/seno.jpg',
            'router_id' => $source->id,
            'olt_id' => $oldOlt->id,
            'status' => 'active',
        ]);
        // An unrelated, already registered ONU (not the migration SN).
        OltOnu::create([
            'olt_id' => $olt->id,
            'onu_ref' => '1/2/2:3',
            'pon_port' => '1/2/2',
            'serial_number' => 'FHTT0000A00D',
            'onu_name' => 'OTHER',
            'state' => 'online',
            'phase_state' => 'working',
        ]);
        $this->mikrotik->secrets[$source->id][self::PPPOE] = [
            '.id' => '*old',
            'name' => self::PPPOE,
            'password' => Canary::of('source'),
            'profile' => '10MB',
            'disabled' => 'false',
        ];

        $senderKeys = [preg_replace('/\D/', '', self::TECH)];
        $migration = CustomerMigration::create([
            'status' => CustomerMigration::STATUS_NEEDS_REVIEW,
            'source' => 'guided_lookup_wa',
            'group_jid' => self::GROUP,
            'sender_jid' => self::TECH,
            'sender_name' => 'Teknisi Krian',
            'message_id' => 'guided-wa:lock-guard',
            'raw_text' => "#migrasi\nPPPoE: ".self::PPPOE."\nSN Baru: ".self::NEW_SN,
            'customer_id' => $customer->id,
            'pppoe_user' => self::PPPOE,
            'full_name' => 'SENO',
            'phone' => '081200000032',
            'odp' => 'ODP-KRN-01',
            'old_router_id' => $source->id,
            'old_olt_id' => $oldOlt->id,
            'old_onu_ref' => '1/1/1:5',
            'old_sn' => 'ZTEGOLD01102',
            'router_id' => $target->id,
            'new_olt_id' => $olt->id,
            'new_sn' => self::NEW_SN,
            'new_pon_port' => $ponPort,
            'meta' => [
                'wa_origin_sender_keys' => $senderKeys,
                'input_identity' => ['pppoe_user' => self::PPPOE],
                'router_mapping' => [
                    'source_input' => 'Krian',
                    'target_input' => 'Skynet-Krian-OLT',
                    'source_router_id' => $source->id,
                    'target_router_id' => $target->id,
                    'cross_router' => true,
                    'status' => 'resolved',
                ],
                'guided_wa_selection' => ['candidate_number' => 1, 'customer_id' => $customer->id, 'selected_by_sender_keys' => $senderKeys],
                'guided_wa_confirmation' => ['status' => 'confirmed', 'selected_by_sender_keys' => $senderKeys],
                'migration_review' => ['match_status' => 'confirmed_pending_approval', 'reasons' => ['guided_wa_candidate_confirmed']],
            ],
        ]);

        if ($prepare) {
            $migration = app(CustomerMigrationService::class)->prepareDeviceActionApproval($migration, null, 'Teknisi konfirmasi migrasi Krian');
            $this->assertSame('dry_run_ready', data_get($migration->meta, 'device_action_approval.status'));
        }
        $this->replies = [];

        return [$migration->fresh(), $source, $target, $olt, $customer];
    }

    /** @return array<string, mixed> */
    private function payload(string $text, string $sender): array
    {
        return [
            'group_jid' => self::GROUP,
            'sender_jid' => $sender,
            'sender_name' => 'Teknisi',
            'message_id' => 'wa-'.md5($text.$sender.microtime()),
            'text' => $text,
        ];
    }

    private function hold(string $key): object
    {
        $lock = Cache::lock($key, 600);
        $this->assertTrue($lock->get(), 'fixture lock '.$key);
        $this->held[] = $lock;

        return $lock;
    }
}
