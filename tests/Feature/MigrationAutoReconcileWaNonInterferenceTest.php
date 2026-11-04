<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerMigration;
use App\Models\Olt;
use App\Models\Router;
use App\Models\Setting;
use App\Services\CustomerMigrationService;
use App\Services\GenieAcsService;
use App\Services\GuidedMigrationLookupService;
use App\Services\HuaweiOltOnlyProvisioningService;
use App\Services\MikrotikService;
use App\Services\OltZteC300Service;
use App\Services\PsbOrchestratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Concerns\BuildsAutoReconcileFixtures;
use Tests\TestCase;

#[Group('blocking-psb')]
class MigrationAutoReconcileWaNonInterferenceTest extends TestCase
{
    use BuildsAutoReconcileFixtures;
    use RefreshDatabase;

    /** @var list<string> */
    private array $replies = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(MikrotikService::class, Mockery::mock(MikrotikService::class));
        $this->app->instance(OltZteC300Service::class, Mockery::mock(OltZteC300Service::class));
        $this->app->instance(GenieAcsService::class, Mockery::mock(GenieAcsService::class));
        $this->app->instance(HuaweiOltOnlyProvisioningService::class, Mockery::mock(HuaweiOltOnlyProvisioningService::class));
        Http::preventStrayRequests();
        Queue::fake();
        Bus::fake();
        $this->replies = [];
    }

    /** WA stand-in that records every reply (the reconcile run itself must produce none). */
    private function recordingPsb(): void
    {
        $psb = Mockery::mock(PsbOrchestratorService::class);
        $psb->shouldReceive('isAllowedGroup')->andReturn(false);
        $psb->shouldReceive('reply')->andReturnUsing(function (string $group, string $text) {
            $this->replies[] = $text;

            return true;
        });
        $this->app->instance(PsbOrchestratorService::class, $psb);
        $this->app->forgetInstance(CustomerMigrationService::class);
    }

    private function silentPsb(): void
    {
        $psb = Mockery::mock(PsbOrchestratorService::class);
        $psb->shouldNotReceive('reply');
        $this->app->instance(PsbOrchestratorService::class, $psb);
        $this->app->forgetInstance(CustomerMigrationService::class);
    }

    private function applyRun(): void
    {
        Setting::set('migration_auto_reconcile_apply', 1);
        Artisan::call('migration:auto-reconcile');
    }

    public function test_reconcile_sends_no_wa_and_queues_nothing(): void
    {
        $this->silentPsb();
        $f = $this->fixture();
        $this->applyRun();

        $this->assertSame('synced', $f['migration']->fresh()->status);
        Queue::assertNothingPushed();
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public function test_cek_status_and_resend_keep_working_after_auto_sync(): void
    {
        $this->silentPsb();
        $f = $this->fixture();
        $id = $f['migration']->id;
        $this->applyRun();

        $this->recordingPsb();
        $svc = app(CustomerMigrationService::class);
        $svc->handleInbound(['group_jid' => 'activation@g.us', 'sender_jid' => 't@s.whatsapp.net', 'sender_name' => 'T', 'message_id' => 'cek-1', 'text' => "#migrasi cek #{$id}"]);
        $svc->handleInbound(['group_jid' => 'activation@g.us', 'sender_jid' => 't@s.whatsapp.net', 'sender_name' => 'T', 'message_id' => 'st-1', 'text' => '#migrasi status']);

        $this->assertCount(2, $this->replies);
        $this->assertStringContainsString((string) $id, $this->replies[0]);
        Queue::assertNothingPushed();
    }

    public function test_migrasi_eksekusi_after_auto_sync_replies_sudah_selesai_and_pushes_no_job(): void
    {
        $this->silentPsb();
        $f = $this->fixture(['meta' => ['input_identity' => ['pppoe_user' => 'a'], 'device_action_approval' => ['ledger_id' => 99, 'status' => 'dry_run_ready']]]);
        $id = $f['migration']->id;
        $this->applyRun();
        $this->assertSame('synced', $f['migration']->fresh()->status);

        // Real execute-guards ON so the command reaches the "already synced" branch instead of a live-OFF block.
        Setting::set(GuidedMigrationLookupService::FEATURE_FLAG, '1');
        Setting::set(GuidedMigrationLookupService::ALLOWED_GROUP_JIDS, 'activation@g.us');
        Setting::set(GuidedMigrationLookupService::ALLOWED_SENDER_JIDS, '6281200000002@s.whatsapp.net');
        Setting::set('migration_wa_only_live_enabled', '1');

        $this->recordingPsb();
        app(CustomerMigrationService::class)->handleInbound([
            'group_jid' => 'activation@g.us', 'sender_jid' => '6281200000002@s.whatsapp.net', 'sender_name' => 'T',
            'message_id' => 'ex-1', 'text' => "#migrasi eksekusi #{$id}",
        ]);

        $this->assertCount(1, $this->replies);
        $this->assertStringContainsString('sudah selesai; tidak dieksekusi ulang', $this->replies[0]);
        Queue::assertNothingPushed();
        Bus::assertNothingDispatched();
        $this->assertSame('synced', $f['migration']->fresh()->status);
    }

    public function test_ui_execute_and_prepare_refuse_a_synced_migration(): void
    {
        $this->silentPsb();
        $f = $this->fixture(['meta' => ['input_identity' => ['pppoe_user' => 'a'], 'device_action_approval' => ['ledger_id' => 99, 'status' => 'dry_run_ready']]]);
        $id = $f['migration']->id;
        $this->applyRun();
        $m = $f['migration']->fresh();
        $svc = app(CustomerMigrationService::class);

        foreach ([
            fn () => $svc->executeApprovedDeviceAction($m, null, 'eksekusi ulang dari UI'),
            fn () => $svc->prepareDeviceActionApproval($m, null, 'dry-run ulang dari UI'),
        ] as $call) {
            try {
                $call();
                $this->fail('synced migration must refuse');
            } catch (\RuntimeException $e) {
                $this->assertSame("Migrasi #{$id} sudah selesai; tidak dieksekusi ulang.", $e->getMessage());
            }
        }
        Queue::assertNothingPushed();
    }

    public function test_guard_also_covers_a_normally_synced_migration_with_leftover_ledger(): void
    {
        $this->silentPsb();
        $f = $this->fixture(['status' => 'synced', 'synced_at' => now(), 'meta' => ['device_action_approval' => ['ledger_id' => 5, 'status' => 'dry_run_ready']]]);
        $svc = app(CustomerMigrationService::class);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('sudah selesai; tidak dieksekusi ulang.');
        $svc->executeApprovedDeviceAction($f['migration'], null, 'eksekusi ulang dari UI');
    }

    public function test_row_with_queued_wa_execution_is_left_to_the_normal_path(): void
    {
        $this->silentPsb();
        $f = $this->fixture(['meta' => [
            'input_identity' => ['pppoe_user' => 'a'],
            'wa_live_execution_queued' => ['queued_at' => now()->subHour()->toIso8601String()],
            'device_action_approval' => ['status' => 'dry_run_ready'],
        ]]);
        $this->applyRun();
        $this->assertSame('needs_review', $f['migration']->fresh()->status);
    }

    public function test_normal_migration_still_reaches_synced_with_exactly_one_success_reply_between_reconcile_runs(): void
    {
        $this->recordingPsb();
        $f = $this->fixture(['status' => 'received']);
        $m = $f['migration'];
        // device writes already recorded => reconcile must leave it to the normal path (admin list only)
        DB::table('customer_migrations')->where('id', $m->id)->update(['meta' => json_encode([
            'input_identity' => ['pppoe_user' => $m->pppoe_user],
            'mikrotik_migration' => ['ok' => true, 'target_router' => ['id' => $f['router']->id], 'new_username' => $m->pppoe_user],
        ])]);

        $this->applyRun();                       // before: must not touch it
        $this->assertSame('received', $m->fresh()->status);
        $this->assertSame([], $this->replies);

        $svc = $this->app->make(NormalPathProbe::class);
        $svc->runProcess($m->fresh());           // the REAL processMigrationLocked tail (device steps stubbed)

        $this->applyRun();                       // after: nothing left to do
        $done = $m->fresh();
        $this->assertSame('synced', $done->status);
        $this->assertNotNull($done->synced_at);
        $this->assertArrayNotHasKey('auto_reconcile', $done->meta);
        $this->assertCount(1, $this->replies, 'exactly one success reply');
        $this->assertSame(0, DB::table('activity_log')->where('description', 'otomatis: ONU + PPPoE online')->count());
    }
}

/**
 * Real CustomerMigrationService with only the device-facing steps stubbed so the normal sync tail
 * (status synced + synced_at + one success reply) runs exactly as in production.
 */
class NormalPathProbe extends CustomerMigrationService
{
    public function runProcess(CustomerMigration $migration): CustomerMigration
    {
        return $this->processMigrationLocked($migration);
    }

    protected function verifyExistingPppoeSecret(Customer $customer, Router $router): array
    {
        return ['found' => true];
    }

    protected function provisionNewOnu(CustomerMigration $migration, Olt $olt, Router $router, Customer $customer): void {}

    protected function syncCustomerAfterNewOnu(CustomerMigration $migration, Customer $customer, array $extra = []): void {}

    protected function syncTr069Tag(CustomerMigration $migration, Customer $customer): void {}

    protected function deleteOldOnuIfSafe(CustomerMigration $migration): void {}

    protected function readVerifiedMigrationPassword(CustomerMigration $migration): ?string
    {
        return null;
    }
}
