<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\CustomerMigration;
use App\Models\DeviceActionLog;
use App\Models\Setting;
use App\Models\User;
use App\Services\CustomerMigrationService;
use App\Services\GenieAcsService;
use App\Services\GuidedMigrationLookupService;
use App\Services\HuaweiOltOnlyProvisioningService;
use App\Services\MikrotikService;
use App\Services\OltZteC300Service;
use App\Services\PsbOrchestratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Concerns\BuildsAutoReconcileFixtures;
use Tests\TestCase;

#[Group('blocking-psb')]
class MigrationManualCompleteTest extends TestCase
{
    use BuildsAutoReconcileFixtures;
    use RefreshDatabase;

    /** @var list<string> */
    private array $replies = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Bare mocks: ANY device call throws. WA is a recording stand-in (must stay empty for the close itself).
        $this->app->instance(MikrotikService::class, Mockery::mock(MikrotikService::class));
        $this->app->instance(OltZteC300Service::class, Mockery::mock(OltZteC300Service::class));
        $this->app->instance(GenieAcsService::class, Mockery::mock(GenieAcsService::class));
        $this->app->instance(HuaweiOltOnlyProvisioningService::class, Mockery::mock(HuaweiOltOnlyProvisioningService::class));
        $this->replies = [];
        $psb = Mockery::mock(PsbOrchestratorService::class);
        $psb->shouldReceive('isAllowedGroup')->andReturn(false);
        $psb->shouldReceive('reply')->andReturnUsing(function (string $group, string $text) {
            $this->replies[] = $text;

            return true;
        });
        $this->app->instance(PsbOrchestratorService::class, $psb);
        Http::preventStrayRequests();
        Queue::fake();
        Bus::fake();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function failedWithTraces(array $m = []): array
    {
        return $this->fixture(array_merge([
            'status' => CustomerMigration::STATUS_FAILED,
            'last_error' => 'MikroTik timeout',
            'olt_at' => now()->subHours(3),
            'meta' => [
                'input_identity' => ['pppoe_user' => 'x'],
                'mikrotik_migration' => ['ok' => true],
                'olt_provisioned_at' => now()->subHours(3)->toIso8601String(),
                'device_action_approval' => ['ledger_id' => 99, 'status' => 'dry_run_ready'],
            ],
        ], $m));
    }

    private function ledger(CustomerMigration $m, string $status, ?string $executedAt = null): DeviceActionLog
    {
        return DeviceActionLog::create([
            'domain' => 'migration', 'target_type' => 'customer_migration', 'target_id' => (string) $m->id,
            'action' => 'migration-device-action-dry-run', 'status' => $status,
            'idempotency_key' => 'k-'.$m->id.'-'.$status, 'payload_hash' => str_repeat('c', 64),
            'result' => ['mode' => 'dry_run', 'device_write' => false],
            'executed_at' => $executedAt,
        ]);
    }

    public function test_teknisi_gets_403_on_preview_and_complete(): void
    {
        $f = $this->failedWithTraces();
        $tech = User::factory()->create(['role' => 'teknisi']);

        $this->actingAs($tech)->getJson(route('customer-migrations.manual-complete.preview', $f['migration']))->assertForbidden();
        $this->actingAs($tech)->postJson(route('customer-migrations.manual-complete', $f['migration']), ['reason' => 'sudah dicek manual'])->assertForbidden();
        $this->assertSame('failed', $f['migration']->fresh()->status);
    }

    public function test_reason_is_required_and_has_minimum_length(): void
    {
        $f = $this->failedWithTraces();
        $url = route('customer-migrations.manual-complete', $f['migration']);

        $this->actingAs($this->admin())->postJson($url, [])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->actingAs($this->admin())->postJson($url, ['reason' => 'pendek'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->assertSame('failed', $f['migration']->fresh()->status);
        $this->assertNull($f['migration']->fresh()->synced_at);
    }

    public function test_preview_shows_evidence_and_is_read_only(): void
    {
        $f = $this->failedWithTraces();
        $this->ledger($f['migration'], 'executed', now()->subHours(3)->toDateTimeString());
        $before = $this->snapshot($f['migration']);
        $activityBefore = Activity::query()->count();

        $res = $this->actingAs($this->admin())->getJson(route('customer-migrations.manual-complete.preview', $f['migration']))->assertOk();

        $res->assertJsonPath('verdict.kind', 'perlu_dicek_admin')
            ->assertJsonPath('verdict.label', 'Perlu dicek admin')
            ->assertJsonPath('onu.found', true)
            ->assertJsonPath('onu.online', true)
            ->assertJsonPath('pppoe.online', true)
            ->assertJsonPath('traces.mikrotik_moved', true)
            ->assertJsonPath('traces.olt_provisioned', true)
            ->assertJsonPath('traces.ledgers.0.status', 'executed')
            ->assertJsonPath('blocked', null);
        $this->assertSame($before, $this->snapshot($f['migration']));
        $this->assertSame($activityBefore, Activity::query()->count());
    }

    public function test_complete_closes_failed_row_with_traces_db_only(): void
    {
        $f = $this->failedWithTraces();
        $dry = $this->ledger($f['migration'], 'dry_run');
        $id = $f['migration']->id;
        $customerBefore = DB::table('customers')->where('id', $f['customer']->id)->first();
        $onuBefore = DB::table('olt_onus')->where('id', $f['onu']->id)->first();
        $admin = $this->admin();

        $this->actingAs($admin)->postJson(route('customer-migrations.manual-complete', $id), ['reason' => 'sudah dicek, pelanggan jalan'])
            ->assertOk()->assertJsonPath('ok', true);

        $m = $f['migration']->fresh();
        $this->assertSame('synced', $m->status);
        $this->assertNotNull($m->synced_at);
        $this->assertNull($m->last_error);
        $this->assertNotNull($m->olt_at, 'olt_at stays as it was');
        $mc = $m->meta['manual_complete'];
        $this->assertSame('manual', $mc['source']);
        $this->assertSame($admin->id, $mc['actor']['id']);
        $this->assertSame('sudah dicek, pelanggan jalan', $mc['reason']);
        $this->assertSame('failed', $mc['previous_status']);
        $this->assertSame('perlu_dicek_admin', $mc['verdict']['verdict']['kind']);
        $this->assertSame('manual_completed_synced', collect($m->meta['timeline'])->last()['step']);
        $this->assertSame('superseded_manual_complete', $m->meta['device_action_approval']['status']);
        $this->assertSame('cancelled', $dry->fresh()->status);
        $this->assertSame('superseded_manual_complete', $dry->fresh()->result['cancelled_reason']);

        $log = Activity::query()->where('log_name', 'migration')->where('event', 'migration_manual_synced')->get();
        $this->assertCount(1, $log);
        $this->assertSame('ditandai selesai manual: sudah dicek, pelanggan jalan', $log[0]->description);
        $this->assertSame($admin->id, (int) $log[0]->causer_id);

        // customers row + olt_onus byte-identical; no device, WA, queue or HTTP
        $this->assertEquals($customerBefore, DB::table('customers')->where('id', $f['customer']->id)->first());
        $this->assertEquals($onuBefore, DB::table('olt_onus')->where('id', $f['onu']->id)->first());
        $this->assertSame([], $this->replies);
        Queue::assertNothingPushed();
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public function test_executed_ledger_is_left_untouched(): void
    {
        $f = $this->failedWithTraces();
        $done = $this->ledger($f['migration'], 'executed', now()->subHour()->toDateTimeString());

        $this->actingAs($this->admin())->postJson(route('customer-migrations.manual-complete', $f['migration']), ['reason' => 'sudah dicek manual'])->assertOk();

        $this->assertSame('executed', $done->fresh()->status);
    }

    public function test_offline_onu_is_allowed_with_a_warning(): void
    {
        $f = $this->failedWithTraces();
        DB::table('olt_onus')->where('id', $f['onu']->id)->update(['state' => 'offline', 'phase_state' => 'O7']);

        $res = $this->actingAs($this->admin())->getJson(route('customer-migrations.manual-complete.preview', $f['migration']))->assertOk();
        $res->assertJsonPath('verdict.kind', 'skip')->assertJsonPath('blocked', null);
        $this->assertStringContainsString('Bukti belum lengkap', $res->json('warning'));

        $this->actingAs($this->admin())->postJson(route('customer-migrations.manual-complete', $f['migration']), ['reason' => 'pelanggan jalan, ONU baru mati lampu'])->assertOk();
        $this->assertSame('synced', $f['migration']->fresh()->status);
    }

    public function test_already_synced_is_idempotent(): void
    {
        $f = $this->failedWithTraces();
        $url = route('customer-migrations.manual-complete', $f['migration']);
        $this->actingAs($this->admin())->postJson($url, ['reason' => 'sudah dicek manual'])->assertOk();
        $first = $f['migration']->fresh();

        $res = $this->actingAs($this->admin())->postJson($url, ['reason' => 'klik dua kali'])->assertStatus(422);
        $res->assertJsonPath('message', 'Migrasi sudah selesai.')->assertJsonPath('code', 'sudah_selesai');

        $again = $f['migration']->fresh();
        $this->assertEquals($first->synced_at, $again->synced_at);
        $this->assertSame('sudah dicek manual', $again->meta['manual_complete']['reason']);
        $this->assertSame(1, Activity::query()->where('event', 'migration_manual_synced')->count());
    }

    public function test_already_synced_row_by_inertia_post_flashes_selesai_message(): void
    {
        $f = $this->fixture(['status' => CustomerMigration::STATUS_SYNCED, 'synced_at' => now()->subDay()]);

        $this->actingAs($this->admin())->post(route('customer-migrations.manual-complete', $f['migration']), ['reason' => 'sudah dicek manual'])
            ->assertSessionHas('success', 'Migrasi sudah selesai.');
        $this->assertSame(0, Activity::query()->where('event', 'migration_manual_synced')->count());
    }

    public function test_terminated_customer_is_blocked(): void
    {
        $f = $this->failedWithTraces();
        DB::table('customers')->where('id', $f['customer']->id)->update(['status' => 'terminated']);

        $this->actingAs($this->admin())->getJson(route('customer-migrations.manual-complete.preview', $f['migration']))
            ->assertOk()->assertJsonPath('blocked', 'Pelanggan sudah berhenti. Tidak bisa ditandai selesai.');
        $this->actingAs($this->admin())->postJson(route('customer-migrations.manual-complete', $f['migration']), ['reason' => 'sudah dicek manual'])
            ->assertStatus(422);

        $this->assertSame('failed', $f['migration']->fresh()->status);
        $this->assertNull($f['migration']->fresh()->synced_at);
    }

    public function test_missing_customer_is_blocked(): void
    {
        $f = $this->failedWithTraces(['customer_id' => null, 'pppoe_user' => 'tidak-ada-di-db']);

        $this->actingAs($this->admin())->postJson(route('customer-migrations.manual-complete', $f['migration']), ['reason' => 'sudah dicek manual'])
            ->assertStatus(422)->assertJsonPath('code', 'diblokir');
        $this->assertSame('failed', $f['migration']->fresh()->status);
    }

    public function test_fresh_executing_ledger_blocks_up_front_with_wait_message(): void
    {
        $f = $this->failedWithTraces();
        $ledger = $this->ledger($f['migration'], 'executing');

        $res = $this->actingAs($this->admin())->getJson(route('customer-migrations.manual-complete.preview', $f['migration']))->assertOk();
        $blocked = (string) $res->json('blocked');
        $this->assertStringContainsString("Eksekusi #{$ledger->id} sedang berjalan sejak", $blocked);
        $this->assertStringContainsString('Tunggu sampai selesai.', $blocked);

        $post = $this->actingAs($this->admin())->postJson(route('customer-migrations.manual-complete', $f['migration']), ['reason' => 'sudah dicek manual'])
            ->assertStatus(422);
        $this->assertStringContainsString("Eksekusi #{$ledger->id}", (string) $post->json('message'));
        $this->assertSame('failed', $f['migration']->fresh()->status);
    }

    public function test_stuck_executing_ledger_blocks_with_stuck_message(): void
    {
        $f = $this->failedWithTraces();
        $ledger = $this->ledger($f['migration'], 'executing');
        DB::table('device_action_logs')->where('id', $ledger->id)->update(['updated_at' => now()->subHours(2), 'created_at' => now()->subHours(2)]);

        $res = $this->actingAs($this->admin())->getJson(route('customer-migrations.manual-complete.preview', $f['migration']))->assertOk();
        $blocked = (string) $res->json('blocked');
        $this->assertStringContainsString("Eksekusi #{$ledger->id} macet sejak", $blocked);
        $this->assertStringContainsString('Minta NOC/dev cek dulu.', $blocked);
        $this->assertStringNotContainsString('coba lagi', strtolower($blocked));

        $post = $this->actingAs($this->admin())->postJson(route('customer-migrations.manual-complete', $f['migration']), ['reason' => 'sudah dicek manual'])
            ->assertStatus(422);
        $this->assertStringContainsString('macet sejak', (string) $post->json('message'));
        $this->assertSame('failed', $f['migration']->fresh()->status);
    }

    // RCA-3 (#564 NIT): the age of an executing ledger is its start (executed_at), not the last touch.
    public function test_executing_ledger_age_uses_executed_at(): void
    {
        $f = $this->failedWithTraces();
        $ledger = $this->ledger($f['migration'], 'executing', now()->subHours(2)->format('Y-m-d H:i:s'));
        // recently touched row, but the execution started two hours ago: stuck
        DB::table('device_action_logs')->where('id', $ledger->id)->update(['updated_at' => now(), 'created_at' => now()]);

        $blocked = (string) $this->actingAs($this->admin())->getJson(route('customer-migrations.manual-complete.preview', $f['migration']))->assertOk()->json('blocked');
        $this->assertStringContainsString("Eksekusi #{$ledger->id} macet sejak", $blocked);

        // old row timestamps, but the execution started a minute ago: still running
        DB::table('device_action_logs')->where('id', $ledger->id)->update(['executed_at' => now()->subMinute(), 'updated_at' => now()->subHours(2), 'created_at' => now()->subHours(2)]);
        $blocked = (string) $this->actingAs($this->admin())->getJson(route('customer-migrations.manual-complete.preview', $f['migration']))->assertOk()->json('blocked');
        $this->assertStringContainsString("Eksekusi #{$ledger->id} sedang berjalan sejak", $blocked);
    }

    public function test_area_scoped_admin_only_manages_own_area_rows(): void
    {
        $own = Area::create(['name' => 'Area Sendiri', 'code' => 'OWN']);
        $other = Area::create(['name' => 'Area Lain', 'code' => 'OTH']);
        $mine = $this->failedWithTraces();
        $theirs = $this->failedWithTraces();
        DB::table('customer_migrations')->where('id', $mine['migration']->id)->update(['area_id' => $own->id]);
        DB::table('customer_migrations')->where('id', $theirs['migration']->id)->update(['area_id' => $other->id]);
        $scoped = $this->admin();
        $scoped->areas()->attach($own);

        $this->actingAs($scoped)->getJson(route('customer-migrations.manual-complete.preview', $theirs['migration']))->assertForbidden();
        $this->actingAs($scoped)->postJson(route('customer-migrations.manual-complete', $theirs['migration']), ['reason' => 'sudah dicek manual'])->assertForbidden();
        $this->assertSame('failed', $theirs['migration']->fresh()->status);

        $this->actingAs($scoped)->postJson(route('customer-migrations.manual-complete', $mine['migration']), ['reason' => 'sudah dicek manual'])->assertOk();
        $this->assertSame('synced', $mine['migration']->fresh()->status);

        // The list hides the button for other-area rows.
        $this->actingAs($scoped)->get(route('customer-migrations.index', ['status' => 'all']))
            ->assertInertia(fn ($page) => $page->has('migrations', 2)
                ->where('migrations', fn ($rows) => collect($rows)->pluck('can_manual_complete', 'id')->all() === [
                    $mine['migration']->id => true,
                    $theirs['migration']->id => false,
                ]));
    }

    public function test_busy_lock_refuses_without_waiting(): void
    {
        $f = $this->failedWithTraces();
        $lock = Cache::lock('customer-migration-'.$f['migration']->id, 60);
        $this->assertTrue($lock->get());

        $this->actingAs($this->admin())->postJson(route('customer-migrations.manual-complete', $f['migration']), ['reason' => 'sudah dicek manual'])
            ->assertStatus(422)->assertJsonPath('code', 'kunci_sibuk');
        $lock->release();
        $this->assertSame('failed', $f['migration']->fresh()->status);
    }

    // ---- PSB WA + migrasi WA safety ----

    public function test_manual_close_sends_no_wa_and_migrasi_replies_keep_working(): void
    {
        $f = $this->failedWithTraces();
        $id = $f['migration']->id;
        $this->actingAs($this->admin())->postJson(route('customer-migrations.manual-complete', $id), ['reason' => 'sudah dicek manual'])->assertOk();
        $this->assertSame([], $this->replies, 'closing sends no WA');

        $svc = app(CustomerMigrationService::class);
        $svc->handleInbound(['group_jid' => 'activation@g.us', 'sender_jid' => 't@s.whatsapp.net', 'sender_name' => 'T', 'message_id' => 'cek-1', 'text' => "#migrasi cek #{$id}"]);
        $svc->handleInbound(['group_jid' => 'activation@g.us', 'sender_jid' => 't@s.whatsapp.net', 'sender_name' => 'T', 'message_id' => 'st-1', 'text' => '#migrasi status']);
        $this->assertCount(2, $this->replies);
        $this->assertStringContainsString((string) $id, $this->replies[0]);
        Queue::assertNothingPushed();
    }

    public function test_migrasi_eksekusi_on_manually_closed_row_replies_sudah_selesai(): void
    {
        $f = $this->failedWithTraces();
        $id = $f['migration']->id;
        $this->actingAs($this->admin())->postJson(route('customer-migrations.manual-complete', $id), ['reason' => 'sudah dicek manual'])->assertOk();

        Setting::set(GuidedMigrationLookupService::FEATURE_FLAG, '1');
        Setting::set(GuidedMigrationLookupService::ALLOWED_GROUP_JIDS, 'activation@g.us');
        Setting::set(GuidedMigrationLookupService::ALLOWED_SENDER_JIDS, '6281200000002@s.whatsapp.net');
        Setting::set('migration_wa_only_live_enabled', '1');

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

    public function test_ui_execute_and_prepare_refuse_a_manually_closed_row(): void
    {
        $f = $this->failedWithTraces();
        $this->actingAs($this->admin())->postJson(route('customer-migrations.manual-complete', $f['migration']), ['reason' => 'sudah dicek manual'])->assertOk();
        $m = $f['migration']->fresh();
        $svc = app(CustomerMigrationService::class);

        foreach ([
            fn () => $svc->executeApprovedDeviceAction($m, $this->admin(), 'coba eksekusi ulang'),
            fn () => $svc->prepareDeviceActionApproval($m, $this->admin(), 'coba dry-run ulang'),
        ] as $call) {
            try {
                $call();
                $this->fail('expected refusal');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('sudah selesai', $e->getMessage());
            }
        }
    }

    public function test_psb_drafts_untouched(): void
    {
        $f = $this->failedWithTraces();
        $before = DB::table('psb_drafts')->count();
        $this->actingAs($this->admin())->postJson(route('customer-migrations.manual-complete', $f['migration']), ['reason' => 'sudah dicek manual'])->assertOk();
        $this->assertSame($before, DB::table('psb_drafts')->count());
    }

    /** @return array<string, mixed> */
    private function snapshot(CustomerMigration $m): array
    {
        return [
            'row' => (array) DB::table('customer_migrations')->where('id', $m->id)->first(),
            'ledgers' => DB::table('device_action_logs')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
        ];
    }
}
