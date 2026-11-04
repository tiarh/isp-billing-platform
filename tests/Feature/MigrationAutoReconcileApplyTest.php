<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerMigration;
use App\Models\DeviceActionLog;
use App\Models\Setting;
use App\Services\CustomerMigrationService;
use App\Services\GenieAcsService;
use App\Services\HuaweiOltOnlyProvisioningService;
use App\Services\MikrotikService;
use App\Services\OltZteC300Service;
use App\Services\PsbOrchestratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Concerns\BuildsAutoReconcileFixtures;
use Tests\TestCase;

#[Group('blocking-psb')]
class MigrationAutoReconcileApplyTest extends TestCase
{
    use BuildsAutoReconcileFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Device services are bare mocks: ANY call throws. Nothing may reach a device or WhatsApp.
        $this->app->instance(MikrotikService::class, Mockery::mock(MikrotikService::class));
        $this->app->instance(OltZteC300Service::class, Mockery::mock(OltZteC300Service::class));
        $this->app->instance(GenieAcsService::class, Mockery::mock(GenieAcsService::class));
        $this->app->instance(HuaweiOltOnlyProvisioningService::class, Mockery::mock(HuaweiOltOnlyProvisioningService::class));
        $psb = Mockery::mock(PsbOrchestratorService::class);
        $psb->shouldNotReceive('reply');
        $this->app->instance(PsbOrchestratorService::class, $psb);
        Http::preventStrayRequests();
        Queue::fake();
        Bus::fake();
    }

    private function ledger(CustomerMigration $m, string $status, ?string $executedAt = null): DeviceActionLog
    {
        return DeviceActionLog::create([
            'domain' => 'migration', 'target_type' => 'customer_migration', 'target_id' => (string) $m->id,
            'action' => 'migration-device-action-dry-run', 'status' => $status,
            'idempotency_key' => 'k-'.$m->id.'-'.$status, 'payload_hash' => str_repeat('b', 64),
            'result' => ['mode' => 'dry_run', 'device_write' => false],
            'executed_at' => $executedAt,
        ]);
    }

    private function reconcile(array $opts = []): string
    {
        Artisan::call('migration:auto-reconcile', $opts);

        return Artisan::output();
    }

    private function autoRows(): int
    {
        return DB::table('activity_log')->where('description', 'otomatis: ONU + PPPoE online')->count();
    }

    public function test_apply_marks_synced_db_only_and_supersedes_unused_ledger(): void
    {
        $f = $this->fixture(['meta' => [
            'input_identity' => ['pppoe_user' => 'x'],
            'device_action_approval' => ['status' => 'dry_run_ready', 'ledger_id' => 1],
        ]]);
        $m = $f['migration'];
        $ledger = $this->ledger($m, 'dry_run');
        $customerBefore = Customer::query()->find($f['customer']->id)->getAttributes();
        $onusBefore = DB::table('olt_onus')->orderBy('id')->get()->toJson();

        Setting::set('migration_auto_reconcile_apply', 1);
        $out = $this->reconcile();
        $this->assertStringContainsString('mode=APPLY', $out);

        $m->refresh();
        $this->assertSame('synced', $m->status);
        $this->assertNotNull($m->synced_at);
        $this->assertNull($m->last_error);
        $this->assertNull($m->olt_at);
        $this->assertNull($m->old_onu_deleted_at);
        $auto = $m->meta['auto_reconcile'];
        $this->assertSame('needs_review', $auto['previous_status']);
        $this->assertSame('Menunggu review.', $auto['previous_last_error']);
        $this->assertSame('none', $auto['device_writes']);
        $this->assertSame('none', $auto['customer_writes']);
        $this->assertSame('not_touched', $auto['old_onu']);
        $this->assertSame('new_sn', $auto['sn_source']);
        $this->assertSame($ledger->id, $auto['ledger_superseded_id']);
        $this->assertSame('auto_reconciled_synced', collect($m->meta['timeline'])->last()['step']);
        $this->assertSame('superseded_auto_reconcile', $m->meta['device_action_approval']['status']);

        $ledger->refresh();
        $this->assertSame('cancelled', $ledger->status);
        $this->assertSame('superseded_auto_reconcile', $ledger->result['cancelled_reason']);
        $this->assertFalse($ledger->result['device_write']);

        $this->assertSame(1, $this->autoRows());
        $this->assertSame('migration_auto_synced', DB::table('activity_log')->where('description', 'otomatis: ONU + PPPoE online')->value('event'));
        $this->assertSame($customerBefore, Customer::query()->find($f['customer']->id)->getAttributes());
        $this->assertSame($onusBefore, DB::table('olt_onus')->orderBy('id')->get()->toJson());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_profile_serial_apply_records_sn_source_without_touching_new_sn_or_customer(): void
    {
        $f = $this->fixture(['new_sn' => 'FHTTWRONG0001'], ['onu_serial' => 'FHTTREAL0001'], ['serial_number' => 'FHTTREAL0001']);
        Setting::set('migration_auto_reconcile_apply', 1);
        $this->reconcile();

        $m = $f['migration']->fresh();
        $this->assertSame('synced', $m->status);
        $this->assertSame('FHTTWRONG0001', $m->new_sn);
        $this->assertSame('customer_profile', $m->meta['auto_reconcile']['sn_source']);
        $this->assertSame('FHTTWRONG0001', $m->meta['auto_reconcile']['original_new_sn']);
        $this->assertSame('FHTTREAL0001', $m->meta['auto_reconcile']['effective_sn']);
        $this->assertSame('FHTTREAL0001', $f['customer']->fresh()->onu_serial);
    }

    public function test_executed_ledger_row_is_never_applied_and_ledger_untouched(): void
    {
        $f = $this->fixture(['status' => 'failed']);
        $ledger = $this->ledger($f['migration'], 'executed', now()->toDateTimeString());
        Setting::set('migration_auto_reconcile_apply', 1);
        $out = $this->reconcile();

        $this->assertSame('failed', $f['migration']->fresh()->status);
        $this->assertSame('executed', $ledger->fresh()->status);
        $this->assertStringContainsString('PERLU DICEK ADMIN', $out);
        $this->assertSame(0, $this->autoRows());
    }

    public function test_apply_is_idempotent(): void
    {
        $f = $this->fixture();
        Setting::set('migration_auto_reconcile_apply', 1);
        $this->reconcile();
        $this->reconcile();
        $this->assertSame(1, $this->autoRows());
        $this->assertSame('synced', $f['migration']->fresh()->status);
    }

    public function test_dry_run_flag_wins_over_apply_setting_and_writes_only_last_run(): void
    {
        $f = $this->fixture();
        Setting::set('migration_auto_reconcile_apply', 1);
        $out = $this->reconcile(['--dry-run' => true]);

        $this->assertStringContainsString('DRY-RUN', $out);
        $this->assertSame('needs_review', $f['migration']->fresh()->status);
        $this->assertSame(0, $this->autoRows());
        $last = Setting::get('migration_auto_reconcile_last_run');
        $last = is_string($last) ? json_decode($last, true) : $last;
        $this->assertSame(1, $last['eligible']);
        $this->assertSame(0, $last['applied']);
    }

    public function test_apply_setting_off_is_report_only_and_apply_flag_overrides(): void
    {
        $f = $this->fixture();
        $out = $this->reconcile();
        $this->assertStringContainsString('apply setting OFF', $out);
        $this->assertSame('needs_review', $f['migration']->fresh()->status);

        $this->reconcile(['--apply' => true]);
        $this->assertSame('synced', $f['migration']->fresh()->status);
    }

    public function test_kill_switch_and_maintenance_mode(): void
    {
        $f = $this->fixture();
        Setting::set('migration_auto_reconcile_apply', 1);

        Setting::set('migration_auto_reconcile_enabled', 0);
        $this->assertStringContainsString('dimatikan', $this->reconcile());
        $this->assertSame('needs_review', $f['migration']->fresh()->status);

        Setting::set('migration_auto_reconcile_enabled', 1);
        Setting::set('migration_maintenance_mode', 1);
        $this->assertStringContainsString('mode maintenance migrasi', $this->reconcile());
        $this->assertSame('needs_review', $f['migration']->fresh()->status);
    }

    public function test_limit_caps_rows_per_run(): void
    {
        $a = $this->fixture();
        $b = $this->fixture();
        $c = $this->fixture();
        Setting::set('migration_auto_reconcile_apply', 1);
        $this->reconcile(['--limit' => 2]);

        $synced = CustomerMigration::query()->where('status', 'synced')->count();
        $this->assertSame(2, $synced);
        $this->assertNotNull($a);
        $this->assertNotNull($b);
        $this->assertNotNull($c);
    }

    public function test_busy_locks_are_skipped_without_waiting_and_wa_live_lock_is_never_probed(): void
    {
        $f = $this->fixture();
        $id = $f['migration']->id;
        Setting::set('migration_auto_reconcile_apply', 1);

        $held = Cache::lock("customer-migration-{$id}", 60);
        $this->assertTrue($held->get());
        $started = microtime(true);
        $out = $this->reconcile();
        $this->assertLessThan(3.0, microtime(true) - $started);
        $this->assertSame('needs_review', $f['migration']->fresh()->status);
        $this->assertStringContainsString('kunci_sibuk', $out);
        $held->release();

        // The global WA-live lock is held by a (simulated) running WA migration: we must not care.
        $wa = Cache::lock('customer-migration-wa-live-active', 60);
        $this->assertTrue($wa->get());
        $this->reconcile();
        $this->assertSame('synced', $f['migration']->fresh()->status);
        $this->assertFalse(Cache::lock('customer-migration-wa-live-active', 60)->get(), 'wa-live lock must still be held by its owner');
        $wa->release();

        // our own locks are released afterwards
        $again = Cache::lock("customer-migration-device-{$id}", 5);
        $this->assertTrue($again->get());
        $again->release();
    }

    public function test_pon_lock_busy_skips(): void
    {
        $f = $this->fixture();
        Setting::set('migration_auto_reconcile_apply', 1);
        $pon = Cache::lock('device-olt-pon-'.$f['olt']->id.'-1/2/1', 60);
        $this->assertTrue($pon->get());
        $out = $this->reconcile();
        $this->assertStringContainsString('kunci_pon_sibuk', $out);
        $this->assertSame('needs_review', $f['migration']->fresh()->status);
        $pon->release();
    }

    public function test_row_changed_between_evaluation_and_apply_is_refused(): void
    {
        $f = $this->fixture();
        $res = app(CustomerMigrationService::class)->markSyncedByAutoReconcile(
            $f['migration']->id, ['customer_id' => $f['customer']->id], 'run-x',
            ['status' => 'needs_review', 'updated_at' => '2020-01-01 00:00:00'],
        );
        $this->assertFalse($res['applied']);
        $this->assertSame('berubah_saat_proses', $res['reason']);
        $this->assertSame('needs_review', $f['migration']->fresh()->status);

        $partial = $this->fixture(['meta' => ['mikrotik_migration' => ['ok' => true]]]);
        $res = app(CustomerMigrationService::class)->markSyncedByAutoReconcile($partial['migration']->id, [], 'run-y');
        $this->assertSame('ada_jejak_eksekusi', $res['reason']);
    }

    public function test_revert_restores_previous_state_and_refuses_after_later_change(): void
    {
        $f = $this->fixture(['customer_id' => null]);
        $ledger = $this->ledger($f['migration'], 'dry_run');
        Setting::set('migration_auto_reconcile_apply', 1);
        $this->reconcile();
        $m = $f['migration']->fresh();
        $this->assertSame('synced', $m->status);
        // M2: the row's customer_id column is never filled (PSB recap / daily report / identity must not move).
        $this->assertNull($m->customer_id);
        $this->assertSame($f['customer']->id, $m->meta['auto_reconcile']['resolved_customer_id']);
        $this->assertFalse($m->meta['auto_reconcile']['customer_id_filled']);

        $out = $this->reconcile(['--revert' => [$m->id]]);
        $this->assertStringContainsString('DIKEMBALIKAN', $out);
        $m->refresh();
        $this->assertSame('needs_review', $m->status);
        $this->assertNull($m->synced_at);
        $this->assertNull($m->customer_id);
        $this->assertSame('Menunggu review.', $m->last_error);
        $this->assertArrayNotHasKey('auto_reconcile', $m->meta);
        $this->assertSame('cancelled', $ledger->fresh()->status);
        $this->assertSame('migration_auto_sync_reverted', DB::table('activity_log')->where('event', 'migration_auto_sync_reverted')->value('event'));

        // not an auto row
        $this->assertStringContainsString('TIDAK dikembalikan', $this->reconcile(['--revert' => [$m->id]]));

        // changed later than 5 s after the auto stamp
        $g = $this->fixture();
        $this->reconcile();
        DB::table('customer_migrations')->where('id', $g['migration']->id)->update(['updated_at' => now()->addHour()]);
        $this->assertStringContainsString('sudah_diubah_lagi', $this->reconcile(['--revert' => [$g['migration']->id]]));
        $this->assertSame('synced', $g['migration']->fresh()->status);
    }

    public function test_output_has_no_pii_and_id_option_prints_every_rule(): void
    {
        $f = $this->fixture(['raw_text' => 'RAHASIA-RAW', 'phone' => '089999999999', 'nik' => '3500000000000001']);
        $out = $this->reconcile(['--id' => [$f['migration']->id]]);
        foreach (['RAHASIA-RAW', '089999999999', '3500000000000001', 'RT 1 RW 2', '0812000000'] as $secret) {
            $this->assertStringNotContainsString($secret, $out);
        }
        foreach (['R2', 'R3', 'R4', 'R5', 'R6', 'R7', 'R8', 'R9', 'R10'] as $rule) {
            $this->assertStringContainsString($rule.' lulus', $out);
        }
        $json = $this->reconcile(['--json' => true]);
        $this->assertNotNull(json_decode($json, true));
    }

    public function test_sn_not_found_group_is_printed_separately(): void
    {
        $f = $this->fixture([], [], null);
        $out = $this->reconcile();
        $this->assertStringContainsString('SN TIDAK DITEMUKAN DI OLT (cek SN di laporan migrasi): 1', $out);
        $this->assertStringContainsString('#'.$f['migration']->id.' needs_review', $out);
    }
}
