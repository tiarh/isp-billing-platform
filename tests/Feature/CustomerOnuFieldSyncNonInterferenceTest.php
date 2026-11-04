<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerMigration;
use App\Models\Setting;
use App\Services\CustomerMigrationService;
use App\Services\CustomerOnu\CustomerOnuFieldSyncService;
use App\Services\PsbOrchestratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Concerns\BuildsAutoReconcileFixtures;
use Tests\Feature\Concerns\BuildsOnuSyncFixtures;
use Tests\TestCase;

/**
 * The ONU field sync must not disturb the PSB WA flow, the migration WA flow or the #517 auto-reconcile revert.
 */
#[Group('blocking-psb')]
class CustomerOnuFieldSyncNonInterferenceTest extends TestCase
{
    use BuildsAutoReconcileFixtures;
    use BuildsOnuSyncFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->blockDevices();
        $psb = Mockery::mock(PsbOrchestratorService::class);
        $psb->shouldNotReceive('reply');
        $this->app->instance(PsbOrchestratorService::class, $psb);
        Queue::fake();
        Bus::fake();
        File::deleteDirectory(CustomerOnuFieldSyncService::planDir());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        File::deleteDirectory(CustomerOnuFieldSyncService::planDir());
        parent::tearDown();
    }

    private function scheduledRun(): string
    {
        Setting::set('customer_onu_sync_schedule_enabled', 1);
        Artisan::call('customers:sync-onu-fields', ['--scheduled' => true]);

        return Artisan::output();
    }

    /** @return array<string, string> */
    private function tableHashes(array $tables): array
    {
        $out = [];
        foreach ($tables as $t) {
            $out[$t] = DB::table($t)->orderBy('id')->get()->toJson();
        }

        return $out;
    }

    public function test_psb_and_migration_tables_queue_and_http_are_untouched_by_a_scheduled_run(): void
    {
        $f = $this->scenario();
        DB::table('psb_drafts')->insert([
            ['status' => 'waiting_sn', 'sn' => 'FHTTPSB00001', 'pppoe_user' => 'psb.satu', 'created_at' => now(), 'updated_at' => now()],
            ['status' => 'applied', 'sn' => $f['customer']->onu_serial, 'pppoe_user' => 'psb.dua', 'created_at' => now(), 'updated_at' => now()],
        ]);
        CustomerMigration::create(['status' => CustomerMigration::STATUS_WAITING_SN, 'customer_id' => $this->scenario()['customer']->id, 'full_name' => 'Z', 'pppoe_user' => 'z']);
        $tables = ['psb_drafts', 'psb_starlite_entries', 'customer_migrations', 'device_recovery_cases', 'olt_onus', 'device_action_logs'];
        $before = $this->tableHashes($tables);

        $this->scheduledRun();

        $this->assertSame($f['olt']->id, (int) Customer::query()->find($f['customer']->id)->olt_id, 'a finished PSB draft does not block');
        $this->assertSame($before, $this->tableHashes($tables));
        Queue::assertNothingPushed();
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public function test_open_psb_draft_and_open_migration_block_the_sync(): void
    {
        $psb = $this->scenario();
        DB::table('psb_drafts')->insert(['status' => 'secret_ok', 'sn' => $psb['customer']->onu_serial, 'created_at' => now(), 'updated_at' => now()]);
        $mig = $this->scenario();
        CustomerMigration::create(['status' => CustomerMigration::STATUS_OLT_OK, 'customer_id' => $mig['customer']->id, 'full_name' => 'M', 'pppoe_user' => $mig['customer']->pppoe_user]);
        $before = $this->tableHashes(['psb_drafts', 'customer_migrations']);

        $out = $this->scheduledRun();

        $this->assertNull(Customer::query()->find($psb['customer']->id)->olt_id);
        $this->assertNull(Customer::query()->find($mig['customer']->id)->olt_id);
        $this->assertStringContainsString('sn_di_psb_terbuka', $out);
        $this->assertStringContainsString('migrasi_terbuka', $out);
        $this->assertSame($before, $this->tableHashes(['psb_drafts', 'customer_migrations']));
    }

    public function test_migration_auto_reconcile_then_sync_interleave_without_touching_the_migration_row(): void
    {
        $f = $this->fixture();
        Setting::set('migration_auto_reconcile_apply', 1);
        Artisan::call('migration:auto-reconcile');
        $migration = CustomerMigration::query()->find($f['migration']->id);
        $this->assertSame('synced', $migration->status);

        // right after the auto-sync the 6 h settle holds the customer back
        $this->scheduledRun();
        $this->assertSame($f['customer']->onu_serial, Customer::query()->find($f['customer']->id)->onu_serial);

        Carbon::setTestNow(now()->addHours(7));
        $f['onu']->forceFill(['state_collected_at' => now()->subMinutes(2)])->save();
        $f['customer']->forceFill(['pppoe_online_checked_at' => now()->subMinutes(2)])->save();
        $migrationRow = DB::table('customer_migrations')->where('id', $migration->id)->first();

        $this->scheduledRun();

        $after = Customer::query()->find($f['customer']->id);
        $this->assertSame($f['migration']->new_sn, $after->onu_serial);
        $this->assertSame($f['onu']->onu_ref, $after->olt_port_label);
        $this->assertEquals($migrationRow, DB::table('customer_migrations')->where('id', $migration->id)->first());
        Queue::assertNothingPushed();
    }

    public function test_h1_migration_revert_is_refused_while_a_sync_is_unreverted_and_allowed_after(): void
    {
        $f = $this->scenario(['onu_serial' => 'ZTEOLD00020']);
        $m = $this->autoMigration($f['customer'], $f['olt'], $f['onu']->serial_number);
        // the #517 revert window: updated_at within 5 s of the auto_reconcile stamp
        DB::table('customer_migrations')->where('id', $m->id)->update(['updated_at' => Carbon::parse($m->meta['auto_reconcile']['at'])->toDateTimeString()]);
        $migrations = app(CustomerMigrationService::class);

        $this->scheduledRun();
        $this->assertSame($f['onu']->serial_number, Customer::query()->find($f['customer']->id)->onu_serial);

        $this->assertSame(['reverted' => false, 'reason' => 'data_pelanggan_sudah_disinkron'], $migrations->revertAutoReconcile($m->id));
        $this->assertSame('synced', CustomerMigration::query()->find($m->id)->status);

        Artisan::call('customers:sync-onu-fields', ['--revert' => [$f['customer']->id]]);
        $this->assertSame('ZTEOLD00020', Customer::query()->find($f['customer']->id)->onu_serial);

        $this->assertSame(['reverted' => true, 'reason' => 'ok'], $migrations->revertAutoReconcile($m->id));
        $this->assertSame('needs_review', CustomerMigration::query()->find($m->id)->status);
    }

    public function test_517_revert_still_works_when_no_sync_happened(): void
    {
        $f = $this->scenario(['onu_serial' => 'ZTEOLD00021']);
        $m = $this->autoMigration($f['customer'], $f['olt'], $f['onu']->serial_number);
        DB::table('customer_migrations')->where('id', $m->id)->update(['updated_at' => Carbon::parse($m->meta['auto_reconcile']['at'])->toDateTimeString()]);

        $this->assertSame(['reverted' => true, 'reason' => 'ok'], app(CustomerMigrationService::class)->revertAutoReconcile($m->id));
    }

    public function test_a_synced_customer_keeps_ready_migration_intake_data(): void
    {
        // after the sync the customer carries the live OLT/port/serial, which is what migration intake snapshots read
        $f = $this->scenario(['onu_serial' => 'ZTEOLD00022']);
        $this->autoMigration($f['customer'], $f['olt'], $f['onu']->serial_number);

        $this->scheduledRun();

        $c = Customer::query()->find($f['customer']->id);
        $this->assertSame([$f['olt']->id, $f['onu']->onu_ref, $f['onu']->serial_number], [(int) $c->olt_id, $c->olt_port_label, $c->onu_serial]);
        $this->assertSame('active', $c->status);
    }
}
