<?php

namespace Tests\Feature;

use App\Jobs\EnsureCustomerCurrentInvoiceJob;
use App\Jobs\SendLifecycleBackstopAlertJob;
use App\Models\Customer;
use App\Models\Setting;
use App\Services\CustomerOnu\CustomerOnuFieldSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Concerns\BuildsOnuSyncFixtures;
use Tests\TestCase;

#[Group('blocking-core')]
class CustomerOnuFieldSyncApplyTest extends TestCase
{
    use BuildsOnuSyncFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->blockDevices();
        Queue::fake();
        Bus::fake();
        File::deleteDirectory(CustomerOnuFieldSyncService::planDir());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(CustomerOnuFieldSyncService::planDir());
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function dryRun(array $opts = []): array
    {
        Artisan::call('customers:sync-onu-fields', $opts + ['--json' => true]);

        return json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function apply(string $runId, array $opts = []): string
    {
        Artisan::call('customers:sync-onu-fields', $opts + ['--apply' => true, '--plan' => $runId]);

        return Artisan::output();
    }

    /** @return array<string, mixed> */
    private function rawRow(int $id): array
    {
        return (array) DB::table('customers')->where('id', $id)->first();
    }

    public function test_dry_run_is_the_default_writes_a_plan_file_and_changes_no_customer(): void
    {
        $f = $this->scenario();
        $before = $this->rawRow($f['customer']->id);

        $out = $this->dryRun();

        $this->assertSame('DRY-RUN', $out['mode']);
        $this->assertSame(1, $out['counts']['SYNC']);
        $this->assertFileExists(CustomerOnuFieldSyncService::planDir().'/'.$out['run_id'].'.json');
        $this->assertSame($before, $this->rawRow($f['customer']->id));
        $this->assertSame(0, Activity::where('event', CustomerOnuFieldSyncService::EVENT_SYNCED)->count());
    }

    public function test_apply_changes_only_the_three_columns_and_keeps_the_encrypted_password_byte_identical(): void
    {
        $f = $this->scenario();
        $id = $f['customer']->id;
        $before = $this->rawRow($id);
        $this->assertNotSame('canary-pw', $before['pppoe_password'], 'stored encrypted');

        $run = $this->dryRun()['run_id'];
        $this->apply($run);

        $after = $this->rawRow($id);
        $changed = array_keys(array_filter($after, fn ($v, $k) => $before[$k] !== $v, ARRAY_FILTER_USE_BOTH));
        $changed = array_values(array_diff($changed, ['updated_at'])); // same-second saves keep the stamp
        sort($changed);
        $this->assertSame(['olt_id', 'olt_port_label'], $changed);
        $this->assertSame($before['pppoe_password'], $after['pppoe_password']);
        $this->assertSame($f['olt']->id, (int) $after['olt_id']);
        $this->assertSame($f['onu']->onu_ref, $after['olt_port_label']);
        $this->assertSame($before['onu_serial'], $after['onu_serial'], 'scope B never changes the serial');
    }

    public function test_legacy_plaintext_password_stays_untouched(): void
    {
        $f = $this->scenario();
        DB::table('customers')->where('id', $f['customer']->id)->update(['pppoe_password' => 'canary-pw']);
        $run = $this->dryRun()['run_id'];
        $this->apply($run);

        $this->assertSame('canary-pw', $this->rawRow($f['customer']->id)['pppoe_password']);
        $this->assertSame($f['olt']->id, (int) $this->rawRow($f['customer']->id)['olt_id']);
    }

    public function test_activity_entries_share_a_batch_and_carry_run_and_evidence(): void
    {
        $a = $this->scenario();
        $b = $this->scenario();
        $run = $this->dryRun()['run_id'];
        $this->apply($run);

        $synced = Activity::where('event', CustomerOnuFieldSyncService::EVENT_SYNCED)->get();
        $this->assertCount(2, $synced);
        $this->assertSame(1, $synced->pluck('batch_uuid')->unique()->count());
        $this->assertNotNull($synced->first()->batch_uuid);
        $p = $synced->firstWhere('subject_id', $a['customer']->id)->properties;
        $this->assertSame($run, $p['run_id']);
        $this->assertSame('manual', $p['mode']);
        $this->assertSame('system:customers:sync-onu-fields', $p['actor']);
        $this->assertSame('none', $p['device_writes']);
        $this->assertSame($a['olt']->id, $p['evidence']['olt_id']);
        $this->assertSame('B1', $p['sub_scope']);
        // the model's own LogsActivity entry (before/after of the dirty attributes) is in the same batch
        $model = Activity::where('subject_type', (new Customer)->getMorphClass())->where('subject_id', $b['customer']->id)->where('event', 'updated')->first();
        $this->assertNotNull($model);
        $this->assertSame($synced->first()->batch_uuid, $model->batch_uuid);
    }

    public function test_apply_requires_a_valid_recent_plan_and_production_confirmation(): void
    {
        $f = $this->scenario();
        Artisan::call('customers:sync-onu-fields', ['--apply' => true]);
        $this->assertNull($this->rawRow($f['customer']->id)['olt_id']);
        $this->assertSame(1, Artisan::call('customers:sync-onu-fields', ['--apply' => true, '--plan' => '20260101-000000-zzzz']));
        $this->assertSame(1, Artisan::call('customers:sync-onu-fields', ['--apply' => true, '--plan' => '../../etc/passwd']));

        $run = $this->dryRun()['run_id'];
        $this->app['env'] = 'production';
        $this->assertSame(1, Artisan::call('customers:sync-onu-fields', ['--apply' => true, '--plan' => $run]));
        $this->assertSame(1, Artisan::call('customers:sync-onu-fields', ['--apply' => true, '--plan' => $run, '--confirm' => 'SYNC-ONU-wrong']));
        $this->assertNull($this->rawRow($f['customer']->id)['olt_id']);
        $this->assertSame(0, Artisan::call('customers:sync-onu-fields', ['--apply' => true, '--plan' => $run, '--confirm' => 'SYNC-ONU-'.$run]));
        $this->assertSame($f['olt']->id, (int) $this->rawRow($f['customer']->id)['olt_id']);
        $this->app['env'] = 'testing';
    }

    public function test_old_plan_is_refused(): void
    {
        $f = $this->scenario();
        $run = $this->dryRun()['run_id'];
        $path = CustomerOnuFieldSyncService::planDir().'/'.$run.'.json';
        $plan = json_decode((string) file_get_contents($path), true);
        $plan['generated_at'] = now()->subHours(25)->toIso8601String();
        file_put_contents($path, json_encode($plan));

        $this->assertSame(1, Artisan::call('customers:sync-onu-fields', ['--apply' => true, '--plan' => $run]));
        $this->assertNull($this->rawRow($f['customer']->id)['olt_id']);
    }

    public function test_master_kill_switch_blocks_manual_apply(): void
    {
        $f = $this->scenario();
        $run = $this->dryRun()['run_id'];
        Setting::set('customer_onu_sync_enabled', 0);
        $this->apply($run);
        $this->assertNull($this->rawRow($f['customer']->id)['olt_id']);
    }

    public function test_drift_since_the_plan_is_not_applied(): void
    {
        $f = $this->scenario();
        $g = $this->scenario();
        $run = $this->dryRun()['run_id'];
        $f['customer']->forceFill(['olt_port_label' => '9/9/9:9'])->save(); // someone else wrote meanwhile
        $g['onu']->forceFill(['state' => 'offline'])->save();

        $out = $this->apply($run);

        $this->assertStringContainsString('berubah_sejak_rencana', $out);
        $this->assertNull($this->rawRow($f['customer']->id)['olt_id']);
        $this->assertNull($this->rawRow($g['customer']->id)['olt_id']);
        $this->assertSame(0, Activity::where('event', CustomerOnuFieldSyncService::EVENT_SYNCED)->count());
    }

    public function test_apply_honours_limit_and_filters_and_is_idempotent(): void
    {
        $a = $this->scenario();
        $b = $this->scenario();
        $run = $this->dryRun()['run_id'];

        $this->apply($run, ['--limit' => 1]);
        $this->assertSame(1, Customer::query()->whereNotNull('olt_id')->count());

        $this->apply($run);
        $this->assertSame(2, Customer::query()->whereNotNull('olt_id')->count());
        $this->assertSame(2, Activity::where('event', CustomerOnuFieldSyncService::EVENT_SYNCED)->count());

        $this->apply($run); // again: nothing left, nothing duplicated
        $this->assertSame(2, Activity::where('event', CustomerOnuFieldSyncService::EVENT_SYNCED)->count());

        $c = $this->scenario();
        $d = $this->scenario();
        $run2 = $this->dryRun()['run_id'];
        $this->apply($run2, ['--id' => [$d['customer']->id]]);
        $this->assertNull($this->rawRow($c['customer']->id)['olt_id']);
        $this->assertSame($d['olt']->id, (int) $this->rawRow($d['customer']->id)['olt_id']);
    }

    public function test_apply_is_refused_while_the_lock_is_held(): void
    {
        $f = $this->scenario();
        $run = $this->dryRun()['run_id'];
        $lock = Cache::lock('customers:sync-onu-fields', 900);
        $this->assertTrue($lock->get());

        $this->assertSame(1, Artisan::call('customers:sync-onu-fields', ['--apply' => true, '--plan' => $run]));
        $this->assertNull($this->rawRow($f['customer']->id)['olt_id']);
        $lock->release();
    }

    public function test_hooks_stay_quiet_no_invoice_job_no_backstop_no_recovery_changes(): void
    {
        $f = $this->scenario(['status' => 'isolated']);
        $tables = ['customer_migrations', 'psb_drafts', 'device_recovery_cases', 'olt_onus'];
        $hash = fn () => collect($tables)->map(fn ($t) => DB::table($t)->orderBy('id')->get()->toJson())->all();
        $beforeTables = $hash();
        config(['lifecycle_guard.backstop_mode' => 'enforce']);

        $run = $this->dryRun()['run_id'];
        $this->apply($run);

        $this->assertSame($f['olt']->id, (int) $this->rawRow($f['customer']->id)['olt_id']);
        $this->assertSame('isolated', $this->rawRow($f['customer']->id)['status']);
        $this->assertSame($beforeTables, $hash());
        Bus::assertNotDispatched(EnsureCustomerCurrentInvoiceJob::class);
        Queue::assertNotPushed(SendLifecycleBackstopAlertJob::class);
        $this->assertSame(0, Activity::where('event', 'lifecycle_backstop_violation')->count());
    }

    public function test_scope_a_apply_sets_the_serial_and_never_writes_the_migration(): void
    {
        $f = $this->scenario(['onu_serial' => 'ZTEOLD00009']);
        $m = $this->autoMigration($f['customer'], $f['olt'], $f['onu']->serial_number);
        $migrationBefore = DB::table('customer_migrations')->where('id', $m->id)->first();

        $run = $this->dryRun()['run_id'];
        $this->apply($run);

        $this->assertSame($f['onu']->serial_number, $this->rawRow($f['customer']->id)['onu_serial']);
        $this->assertEquals($migrationBefore, DB::table('customer_migrations')->where('id', $m->id)->first(), 'customer_migrations row (and its updated_at window) untouched');
        $props = Activity::where('event', CustomerOnuFieldSyncService::EVENT_SYNCED)->first()->properties;
        $this->assertSame($m->id, $props['migration_id']);
        $this->assertSame('A', $props['sub_scope']);
        $this->assertSame('new_sn', $props['sn_source']);
    }

    public function test_recheck_blocks_a_new_owner_appearing_between_evaluate_and_apply(): void
    {
        $f = $this->scenario();
        $service = app(CustomerOnuFieldSyncService::class);
        $verdict = $service->evaluate(['ids' => [$f['customer']->id]])['verdicts'][0];

        $this->scenario(['onu_serial' => $f['customer']->onu_serial], null); // second live owner shows up
        $res = $service->applyOne($verdict, 'run-x', 'manual');
        $this->assertSame(['applied' => false, 'reason' => 'sn_milik_pelanggan_lain'], $res);

        DB::table('customers')->where('onu_serial', $f['customer']->onu_serial)->where('id', '!=', $f['customer']->id)->update(['onu_serial' => 'NOBODY000001']);
        $this->openRecoveryCase($f['customer']);
        $this->assertSame('kasus_penarikan_pelanggan_terbuka', $service->applyOne($verdict, 'run-x', 'manual')['reason']);
        $this->assertNull($this->rawRow($f['customer']->id)['olt_id']);
    }

    public function test_l1_apply_rereads_the_migration_row_with_a_row_lock_and_refuses_a_changed_one(): void
    {
        $f = $this->scenario(['onu_serial' => 'ZTEOLD00030']);
        $m = $this->autoMigration($f['customer'], $f['olt'], $f['onu']->serial_number);
        $service = app(CustomerOnuFieldSyncService::class);
        $verdict = $service->evaluate(['ids' => [$f['customer']->id]])['verdicts'][0];

        $locked = false;
        DB::listen(function ($q) use (&$locked): void {
            if (stripos($q->sql, 'from `customer_migrations`') !== false && stripos($q->sql, 'for update') !== false) {
                $locked = true;
            }
        });
        $meta = $m->meta;
        unset($meta['auto_reconcile']); // as if #517 revert ran concurrently
        $m->forceFill(['meta' => $meta, 'status' => 'needs_review', 'synced_at' => null])->save();

        $this->assertSame(['applied' => false, 'reason' => 'migrasi_berubah'], $service->applyOne($verdict, 'run-l1', 'manual'));
        $this->assertTrue($locked, 'migration row read with FOR UPDATE');
        $this->assertSame('ZTEOLD00030', $this->rawRow($f['customer']->id)['onu_serial']);
    }

    public function test_l2_serial_revert_refused_when_another_live_customer_now_owns_the_old_serial(): void
    {
        $f = $this->scenario(['onu_serial' => 'ZTEOLD00031']);
        $this->autoMigration($f['customer'], $f['olt'], $f['onu']->serial_number);
        $this->apply($this->dryRun()['run_id']);
        $this->scenario(['onu_serial' => 'ZTEOLD00031'], null); // old device reused by someone else

        $res = app(CustomerOnuFieldSyncService::class)->revertCustomer($f['customer']->id);

        $this->assertSame(['reverted' => false, 'reason' => 'serial_sudah_dipakai_pelanggan_lain'], $res);
        $this->assertSame($f['onu']->serial_number, $this->rawRow($f['customer']->id)['onu_serial']);
    }

    public function test_l3_sync_row_whose_before_changed_is_reported_as_berubah_sejak_rencana(): void
    {
        $f = $this->scenario();
        $run = $this->dryRun()['run_id'];
        $f['customer']->forceFill(['olt_port_label' => '9/9/9:9'])->save(); // still SYNC, but before differs

        $out = $this->apply($run);

        $this->assertStringContainsString('dilewati berubah_sejak_rencana: 1', $out);
        $this->assertNull($this->rawRow($f['customer']->id)['olt_id']);
    }

    // ---- revert

    public function test_revert_per_customer_restores_null_and_logs(): void
    {
        $f = $this->scenario();
        $run = $this->dryRun()['run_id'];
        $this->apply($run);
        $this->assertNotNull($this->rawRow($f['customer']->id)['olt_id']);

        Artisan::call('customers:sync-onu-fields', ['--revert' => [$f['customer']->id]]);

        $row = $this->rawRow($f['customer']->id);
        $this->assertNull($row['olt_id']);
        $this->assertNull($row['olt_port_label']);
        $this->assertSame(1, Activity::where('event', CustomerOnuFieldSyncService::EVENT_REVERTED)->count());

        Artisan::call('customers:sync-onu-fields', ['--revert' => [$f['customer']->id]]);
        $this->assertStringContainsString('sudah_dikembalikan', Artisan::output());
    }

    public function test_revert_run_reverts_all_and_refuses_changed_customers(): void
    {
        $a = $this->scenario();
        $b = $this->scenario();
        $run = $this->dryRun()['run_id'];
        $this->apply($run);
        $b['customer']->forceFill(['olt_port_label' => '5/5/5:5'])->save();

        Artisan::call('customers:sync-onu-fields', ['--revert-run' => $run]);
        $out = Artisan::output();

        $this->assertNull($this->rawRow($a['customer']->id)['olt_id']);
        $this->assertNotNull($this->rawRow($b['customer']->id)['olt_id']);
        $this->assertStringContainsString('sudah_diubah_lagi', $out);
    }

    public function test_serial_revert_is_blocked_by_an_open_case_of_another_customer(): void
    {
        $f = $this->scenario(['onu_serial' => 'ZTEOLD00010']);
        $this->autoMigration($f['customer'], $f['olt'], $f['onu']->serial_number);
        $run = $this->dryRun()['run_id'];
        $this->apply($run);
        $other = $this->scenario([], null);
        $this->openRecoveryCase($other['customer'], 'ZTEOLD00010');

        $res = app(CustomerOnuFieldSyncService::class)->revertCustomer($f['customer']->id);

        $this->assertSame(['reverted' => false, 'reason' => 'kasus_penarikan_terbuka'], $res);
        $this->assertSame($f['onu']->serial_number, $this->rawRow($f['customer']->id)['onu_serial']);
    }
}
