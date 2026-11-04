<?php

namespace Tests\Feature;

use App\Jobs\SyncRouterJob;
use App\Models\Area;
use App\Models\Customer;
use App\Models\CustomerMigration;
use App\Models\DeviceActionLog;
use App\Models\Package;
use App\Models\PsbDraft;
use App\Models\Router;
use App\Models\RouterStagedCustomer;
use App\Models\Setting;
use App\Models\User;
use App\Services\MikrotikService;
use App\Services\Routers\UnlinkedSecretDeleter;
use App\Services\Routers\UnlinkedSecretPrefill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakeRouterOsClient;
use Tests\Support\FakeSecretMikrotikService;
use Tests\TestCase;

/**
 * "Hapus secret" untuk secret yang belum terhubung. Hanya sisi jaringan RouterOS yang dipalsukan
 * (FakeRouterOsClient); setiap penolakan harus terbukti tidak mengirim perintah hapus.
 */
#[Group('blocking-core')]
class RouterUnlinkedSecretDeleteTest extends TestCase
{
    use RefreshDatabase;

    private Router $router;

    private FakeRouterOsClient $fake;

    private FakeSecretMikrotikService $mt;

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->router = $this->makeRouter('Router Uji A', '198.18.22.1');
        $this->fake = new FakeRouterOsClient;
        $this->mt = new FakeSecretMikrotikService($this->fake);
        $this->app->instance(MikrotikService::class, $this->mt);
        Setting::set('router_secret_delete_enabled', '1');
        Setting::set('control_plane.device_read_only_mode', '0');
        Log::listen(function (MessageLogged $event): void {
            $this->logged[] = $event->message.' '.json_encode($event->context);
        });
    }

    // ---- siapa boleh -----------------------------------------------------

    public function test_only_superadmin_and_noc_may_delete_and_see_the_button(): void
    {
        $row = $this->row('tamu.peran');
        $check = route('api.routers.unlinked-secrets.delete-check', [$this->router, $row]);
        $list = route('api.routers.unlinked-secrets.index', $this->router);

        foreach ([$this->scopedAdmin(), User::factory()->create(['role' => 'admin']), User::factory()->create(['role' => 'teknisi'])] as $user) {
            $this->actingAs($user)->getJson($check)->assertForbidden();
            $this->destroy($row, [], $user)->assertForbidden();
        }
        // Admin penuh masih boleh melihat daftar, tapi tidak ada tombol hapus.
        $this->actingAs(User::factory()->create(['role' => 'admin']))->getJson($list)->assertOk()->assertJsonPath('meta.can_delete', false);

        $this->actingAs($this->noc())->getJson($list)->assertJsonPath('meta.can_delete', true);
        $this->actingAs(User::factory()->create(['role' => 'superadmin']))->getJson($list)->assertJsonPath('meta.can_delete', true);

        $this->assertSame(0, $this->mt->connects);
        $this->assertNoRemove();
        $this->assertSame(0, DeviceActionLog::count());
    }

    public function test_the_button_is_hidden_when_flag_is_off_read_only_is_on_or_router_is_archived(): void
    {
        $list = route('api.routers.unlinked-secrets.index', $this->router);
        $this->actingAs($this->noc());

        Setting::set('router_secret_delete_enabled', '0');
        $this->getJson($list)->assertJsonPath('meta.can_delete', false);

        Setting::set('router_secret_delete_enabled', '1');
        Setting::set('control_plane.device_read_only_mode', '1');
        $this->getJson($list)->assertJsonPath('meta.can_delete', false);

        Setting::set('control_plane.device_read_only_mode', '0');
        $this->router->forceFill(['is_active' => false])->save();
        $this->getJson($list)->assertJsonPath('meta.can_delete', false);
    }

    public function test_delete_is_rate_limited(): void
    {
        $row = $this->row('tamu.laju');
        $this->live('tamu.laju');
        $this->actingAs($this->noc());

        for ($i = 0; $i < 10; $i++) {
            $this->getJson(route('api.routers.unlinked-secrets.delete-check', [$this->router, $row]))->assertOk();
        }
        $this->getJson(route('api.routers.unlinked-secrets.delete-check', [$this->router, $row]))->assertStatus(429);
    }

    // ---- penolakan dari database (tanpa menyentuh router) ----------------

    public function test_flag_off_and_read_only_refuse_without_touching_the_router(): void
    {
        $row = $this->row('tamu.flag');

        Setting::set('router_secret_delete_enabled', '0');
        $this->destroy($row)->assertStatus(423)->assertJsonPath('code', 'flag_off')->assertJsonPath('message', 'Hapus secret belum diaktifkan.');

        Setting::set('router_secret_delete_enabled', '1');
        Setting::set('control_plane.device_read_only_mode', '1');
        $this->destroy($row)->assertStatus(423)->assertJsonPath('code', 'read_only')->assertJsonPath('message', 'Mode aman perangkat aktif. Tidak ada yang dihapus.');

        $this->assertNoDeviceTouched();
    }

    public function test_the_flag_is_off_by_default_and_read_only_is_on_when_settings_are_missing(): void
    {
        DB::table('settings')->whereIn('key', ['router_secret_delete_enabled', 'control_plane.device_read_only_mode'])->delete();
        Cache::flush();
        $row = $this->row('tamu.bawaan');

        $this->destroy($row)->assertStatus(423)->assertJsonPath('code', 'read_only');
        Setting::set('control_plane.device_read_only_mode', '0');
        $this->destroy($row)->assertStatus(423)->assertJsonPath('code', 'flag_off');

        $this->assertNoDeviceTouched();
    }

    public function test_archived_router_and_router_without_address_are_refused(): void
    {
        $row = $this->row('tamu.arsip');
        $this->router->forceFill(['is_active' => false])->save();
        $this->destroy($row)->assertStatus(409)->assertJsonPath('message', 'Router ini sudah diarsipkan.');

        $this->router->forceFill(['is_active' => true, 'ip_address' => '', 'vpn_enabled' => false])->save();
        $this->destroy($row)->assertStatus(409)->assertJsonPath('code', 'unreachable');

        $this->assertNoDeviceTouched();
    }

    public function test_a_linked_gone_or_other_router_row_is_refused(): void
    {
        $linked = $this->row('tamu.cocok', ['status' => 'matched']);
        $gone = $this->row('tamu.hilang', ['status' => 'gone']);
        $other = $this->makeRouter('Router Uji B', '198.18.22.2');
        $foreign = $this->row('tamu.router.lain', [], $other);

        $this->destroy($linked)->assertStatus(409)->assertJsonPath('code', 'not_unmatched');
        $this->destroy($gone)->assertStatus(409)->assertJsonPath('code', 'not_unmatched');
        // Baris milik router lain tidak bisa dibuka lewat router ini (404, bukan dihapus).
        $this->actingAs($this->noc())->deleteJson(route('api.routers.unlinked-secrets.destroy', [$this->router, $foreign]), $this->body($foreign))->assertNotFound();

        $this->assertNoDeviceTouched();
    }

    public function test_typed_username_must_match_exactly(): void
    {
        $row = $this->row('tamu.ketik');

        $this->destroy($row, ['confirm_username' => 'TAMU.KETIK'])->assertStatus(422)->assertJsonPath('code', 'confirm_mismatch');
        $this->destroy($row, ['confirm_username' => 'tamu.ketik.x'])->assertStatus(422);
        $this->destroy($row, ['confirm_username' => ''])->assertStatus(422);

        $this->assertNoDeviceTouched();
    }

    public function test_a_customer_with_the_same_username_blocks_on_any_router_even_deleted_or_imported(): void
    {
        $other = $this->makeRouter('Router Uji B', '198.18.22.2');
        $package = $this->package();
        $cases = [
            'tamu.pelanggan.satu' => $this->customer($package, 'tamu.pelanggan.satu', $other),
            'tamu.pelanggan.dua' => $this->customer($package, 'tamu.pelanggan.dua'),
            'tamu.pelanggan.tiga' => $this->customer($package, 'tamu.pelanggan.tiga', null, ['code' => 'IMP-0001']),
            'tamu.pelanggan.empat' => $this->customer($package, 'TAMU.PELANGGAN.EMPAT'),
        ];
        $cases['tamu.pelanggan.dua']->delete();

        foreach ($cases as $username => $customer) {
            $response = $this->destroy($this->row($username))->assertStatus(409)->assertJsonPath('code', 'customer_exists');
            $this->assertStringContainsString("Username ini dipakai pelanggan #{$customer->id} (", $response->json('message'));
            $this->assertStringContainsString('). Tidak bisa dihapus dari sini.', $response->json('message'));
        }

        $this->assertNoDeviceTouched();
    }

    public function test_a_lookalike_customer_is_a_warning_that_needs_the_tick_by_rt_rw_or_by_suffix(): void
    {
        $package = $this->package();
        $sameRtRw = $this->customer($package, 'USERMIRIP_RT02_RW03_ODP.1A');
        $sameSuffix = $this->customer($package, 'USERSERUPA_RT04_RW05_ODP.9Z');
        $deleted = $this->customer($package, 'USERLAMA_RT01_RW01_ODP.1A');
        $deleted->delete();

        // Tanpa centang: ditolak, router tidak disentuh. Awalan sama + RT/RW sama (aturan ganti nama manual).
        $first = $this->row('usermirip_rt2_rw3_odp.2b');
        $a = $this->destroy($first)->assertStatus(409)->assertJsonPath('code', 'lookalike_unconfirmed');
        $this->assertSame("Username ini mirip pelanggan lain (#{$sameRtRw->id}). Centang dulu bahwa sudah dicek.", $a->json('message'));
        // Awalan sama + akhiran sama, RT salah satu digit (aturan salah ketik).
        $second = $this->row('userserupa_rt14_rw05_odp.9z');
        $this->destroy($second)->assertStatus(409)->assertJsonPath('code', 'lookalike_unconfirmed');
        // Pelanggan yang sudah dihapus tetap dihitung.
        $third = $this->row('userlama_rt01_rw01_odp.3c');
        $this->destroy($third)->assertStatus(409)->assertJsonPath('code', 'lookalike_unconfirmed');
        $this->assertNoDeviceTouched();
        $this->assertSame(0, DeviceActionLog::count());

        // Tick = false yang dikirim eksplisit juga ditolak.
        $this->destroy($first, ['lookalike_ack' => false])->assertStatus(409)->assertJsonPath('code', 'lookalike_unconfirmed');
        $this->assertNoDeviceTouched();

        // Dengan centang: dihapus. Centang dan id pelanggan yang mirip tercatat di ledger dan aktivitas.
        $this->live('usermirip_rt2_rw3_odp.2b');
        $this->live('userserupa_rt14_rw05_odp.9z');
        $noc = $this->noc();
        $this->destroy($first, ['lookalike_ack' => true], $noc)->assertOk()->assertJsonPath('ok', true);
        $this->destroy($second, ['lookalike_ack' => true], $noc)->assertOk();

        $this->assertSame([], $this->fake->secrets);
        $this->assertSame('gone', $first->fresh()->status);
        $log = DeviceActionLog::where('target_id', "{$this->router->id}:usermirip_rt2_rw3_odp.2b")->firstOrFail();
        $this->assertTrue($log->preflight['lookalike_ack']);
        $this->assertTrue($log->result['lookalike_ack']);
        $this->assertSame([$sameRtRw->id], $log->result['lookalike_ids']);
        $this->assertSame([$sameSuffix->id], DeviceActionLog::where('target_id', "{$this->router->id}:userserupa_rt14_rw05_odp.9z")->firstOrFail()->result['lookalike_ids']);
        $activity = json_decode((string) DB::table('activity_log')->where('description', 'router_secret_deleted')->orderBy('id')->value('properties'), true);
        $this->assertTrue($activity['lookalike_ack']);
        $this->assertSame([$sameRtRw->id], $activity['lookalike_ids']);

        // Awalan lain = bukan mirip: tidak perlu centang.
        $this->live('userbeda_rt02_rw03_odp.2b');
        $this->destroy($this->row('userbeda_rt02_rw03_odp.2b'))->assertOk();
    }

    public function test_the_exact_same_username_stays_a_hard_refusal_even_with_the_lookalike_tick(): void
    {
        $this->customer($this->package(), 'USERSAMA_RT02_RW03_ODP.1A');

        $this->destroy($this->row('usersama_rt02_rw03_odp.1a'), ['lookalike_ack' => true])->assertStatus(409)->assertJsonPath('code', 'customer_exists');

        $this->assertNoDeviceTouched();
    }

    public function test_delete_check_lists_the_lookalike_customers_with_their_router(): void
    {
        $other = $this->makeRouter('Router Uji B', '198.18.22.2');
        $package = $this->package();
        $near = $this->customer($package, 'USERDEKAT_RT02_RW03_ODP.1A', $other);
        $this->customer($package, 'USERDEKAT_RT02_RW03_ODP.2C');
        $row = $this->row('userdekat_rt02_rw03_odp.3D');
        $this->live('userdekat_rt02_rw03_odp.3D');

        $response = $this->actingAs($this->noc())->getJson(route('api.routers.unlinked-secrets.delete-check', [$this->router, $row]))->assertOk();

        $response->assertJsonCount(2, 'secret.lookalikes')
            ->assertJsonPath('secret.lookalikes.0.id', $near->id)
            ->assertJsonPath('secret.lookalikes.0.text', "Mirip pelanggan #{$near->id} (Router Uji B)")
            ->assertJsonPath('secret.lookalikes.0.username', $near->pppoe_user)
            ->assertJsonPath('secret.lookalikes.1.router', 'Router Uji A');

        $plain = $this->row('tamu.tanpa.mirip');
        $this->live('tamu.tanpa.mirip');
        $this->getJson(route('api.routers.unlinked-secrets.delete-check', [$this->router, $plain]))->assertOk()->assertJsonPath('secret.lookalikes', []);
    }

    public function test_a_psb_draft_blocks_unless_cancelled(): void
    {
        PsbDraft::create(['status' => PsbDraft::STATUS_OLT_OK, 'message_id' => 'uji-draft-1', 'full_name' => 'SITI CONTOH', 'pppoe_user' => 'tamu.psb.satu']);
        PsbDraft::create(['status' => PsbDraft::STATUS_CANCELLED, 'message_id' => 'uji-draft-2', 'full_name' => 'SITI CONTOH', 'pppoe_user' => 'tamu.psb.dua']);
        $draftId = PsbDraft::where('pppoe_user', 'tamu.psb.satu')->value('id');

        $this->destroy($this->row('tamu.psb.satu'))->assertStatus(409)->assertJsonPath('code', 'psb_draft')
            ->assertJsonPath('message', "Username ini sedang dipakai PSB #{$draftId}.");
        $this->assertNoDeviceTouched();

        $this->live('tamu.psb.dua');
        $this->destroy($this->row('tamu.psb.dua'))->assertOk();
    }

    public function test_an_open_migration_blocks_unless_finished(): void
    {
        $open = CustomerMigration::create(['status' => CustomerMigration::STATUS_NEEDS_REVIEW, 'source' => 'test', 'message_id' => 'uji-mig-1', 'full_name' => 'BUDI CONTOH', 'pppoe_user' => 'tamu.mig.satu']);
        CustomerMigration::create(['status' => CustomerMigration::STATUS_SYNCED, 'source' => 'test', 'message_id' => 'uji-mig-2', 'full_name' => 'BUDI CONTOH', 'pppoe_user' => 'tamu.mig.dua']);

        $this->destroy($this->row('tamu.mig.satu'))->assertStatus(409)->assertJsonPath('code', 'open_migration')
            ->assertJsonPath('message', "Username ini sedang dipakai migrasi #{$open->id}.");
        $this->assertNoDeviceTouched();

        $this->live('tamu.mig.dua');
        $this->destroy($this->row('tamu.mig.dua'))->assertOk();
    }

    // ---- penolakan setelah baca router langsung ---------------------------

    public function test_a_secret_that_is_already_gone_marks_the_row_gone_and_sends_no_remove(): void
    {
        $row = $this->row('tamu.hilang.live');

        $this->destroy($row)->assertStatus(409)->assertJsonPath('code', 'gone')
            ->assertJsonPath('message', 'Secret sudah tidak ada di router. Daftar diperbarui.');

        $this->assertSame('gone', $row->fresh()->status);
        $this->assertSame('refused_gone', DeviceActionLog::firstOrFail()->status);
        $this->assertNoRemove();
    }

    public function test_a_changed_secret_is_refused(): void
    {
        $row = $this->row('tamu.berubah');
        $this->live('tamu.berubah', ['profile' => 'prof-lain']);
        $this->destroy($row)->assertStatus(409)->assertJsonPath('code', 'changed')->assertJsonPath('message', 'Data secret berubah. Buka ulang dialog.');

        $this->fake->secrets = [];
        $this->live('tamu.berubah', ['disabled' => 'true']);
        $this->destroy($row)->assertStatus(409)->assertJsonPath('code', 'changed');

        $this->assertNoRemove();
        $this->assertSame('unmatched', $row->fresh()->status);
    }

    public function test_an_online_secret_needs_the_tick(): void
    {
        $row = $this->row('tamu.online');
        $this->live('tamu.online');
        $this->online('tamu.online');

        $this->destroy($row)->assertStatus(409)->assertJsonPath('code', 'online_needs_tick')
            ->assertJsonPath('message', 'Secret sedang online. Centang putus koneksi kalau tetap mau hapus.');

        $this->assertNoRemove();
        $this->assertCount(1, $this->fake->sessions);
        $this->assertSame('refused_online', DeviceActionLog::firstOrFail()->status);
    }

    public function test_an_unreadable_password_means_no_backup_and_no_delete(): void
    {
        $this->live('tamu.masker', ['password' => '*****']);
        $this->live('tamu.kosong', ['password' => '']);

        foreach (['tamu.masker', 'tamu.kosong'] as $name) {
            $this->destroy($this->row($name))->assertStatus(409)->assertJsonPath('code', 'password_unreadable')
                ->assertJsonPath('message', 'Password secret tidak terbaca, cadangan tidak lengkap. Tidak dihapus.');
        }

        $this->assertNoRemove();
        $this->assertSame(0, DeviceActionLog::whereNotNull('result->secret_backup')->count());
    }

    public function test_a_running_sync_blocks_the_delete(): void
    {
        $row = $this->row('tamu.sinkron');
        $this->live('tamu.sinkron');
        // Kunci diambil dari middleware job sinkron yang asli, bukan ditulis ulang di tes.
        $job = new SyncRouterJob($this->router->id);
        $key = $job->middleware()[0]->getLockKey($job);
        $this->assertSame($key, UnlinkedSecretDeleter::syncLockKey($this->router));
        $held = Cache::lock($key, 60);
        $this->assertTrue($held->get());

        $this->destroy($row)->assertStatus(409)->assertJsonPath('code', 'busy')
            ->assertJsonPath('message', 'Router sedang disinkron. Coba lagi 1–2 menit.');

        $this->assertNoDeviceTouched();
        $held->release();
    }

    public function test_an_unreachable_router_deletes_nothing_and_keeps_no_backup(): void
    {
        $row = $this->row('tamu.putus');
        $this->live('tamu.putus');
        $this->mt->unreachable = true;

        $this->destroy($row)->assertStatus(409)->assertJsonPath('code', 'unreachable');

        $this->assertNoRemove();
        $this->assertSame('refused_unreachable', DeviceActionLog::firstOrFail()->status);
        $this->assertNull(DeviceActionLog::firstOrFail()->result['secret_backup'] ?? null);
    }

    public function test_when_the_backup_cannot_be_saved_nothing_is_removed(): void
    {
        $row = $this->row('tamu.cadangan');
        $this->live('tamu.cadangan');
        DeviceActionLog::updating(function (DeviceActionLog $log): void {
            if ($log->status === 'backed_up') {
                throw new \RuntimeException('disk penuh');
            }
        });

        $this->destroy($row)->assertStatus(409)->assertJsonPath('code', 'backup_failed');

        $this->assertNoRemove();
        $this->assertCount(1, $this->fake->secrets);
        $this->assertSame('unmatched', $row->fresh()->status);
    }

    public function test_a_rejected_remove_is_reported_and_the_row_stays(): void
    {
        $row = $this->row('tamu.ditolak');
        $this->live('tamu.ditolak');
        $this->fake->removeError = 'not enough permissions (9)';

        $this->destroy($row)->assertStatus(409)->assertJsonPath('code', 'remove_rejected')
            ->assertJsonPath('message', 'MikroTik menolak hapus: not enough permissions (9)');

        $this->assertSame('remove_rejected', DeviceActionLog::firstOrFail()->status);
        $this->assertSame('unmatched', $row->fresh()->status);
        $this->assertCount(1, $this->fake->secrets);
    }

    public function test_a_remove_that_cannot_be_verified_is_reported_and_the_row_stays(): void
    {
        $row = $this->row('tamu.belum');
        $this->live('tamu.belum');
        $this->fake->removeIsNoop = true;

        $this->destroy($row)->assertStatus(409)->assertJsonPath('code', 'unverified')
            ->assertJsonPath('message', 'Perintah hapus terkirim tapi belum terverifikasi. Cek di Winbox.');

        $this->assertSame('unverified', DeviceActionLog::firstOrFail()->status);
        $this->assertSame('unmatched', $row->fresh()->status);
    }

    // ---- balapan: hapus lawan "Tambah pelanggan" / pelanggan baru -----------

    public function test_a_row_linked_by_another_admin_after_the_first_checks_is_never_removed(): void
    {
        $package = $this->package();
        $row = $this->row('tamu.balap.satu');
        $this->live('tamu.balap.satu');
        // Admin lain menekan "Tambah pelanggan" tepat setelah pemeriksaan pertama lolos (saat ledger dibuat).
        DeviceActionLog::created(function () use ($row, $package): void {
            app(UnlinkedSecretPrefill::class)->createLinked($row->id, fn (): Customer => $this->customer($package, 'tamu.balap.satu'));
        });

        $this->destroy($row)->assertStatus(409)->assertJsonPath('code', 'not_unmatched')
            ->assertJsonPath('message', 'Secret ini sudah terhubung atau sudah hilang dari router.');

        $this->assertNoRemove();
        $this->assertSame(0, $this->mt->connects, 'router tidak dihubungi');
        $this->assertCount(1, $this->fake->secrets);
        $customer = Customer::where('pppoe_user', 'tamu.balap.satu')->firstOrFail();
        $fresh = $row->fresh();
        $this->assertSame('matched', $fresh->status);
        $this->assertSame($customer->id, $fresh->matched_customer_id);
        $this->assertSame('refused_changed', DeviceActionLog::firstOrFail()->status);
        $this->assertNull(DeviceActionLog::firstOrFail()->result['secret_backup'] ?? null);
    }

    public function test_a_row_being_deleted_cannot_be_turned_into_a_customer(): void
    {
        $package = $this->package();
        $row = $this->row('tamu.balap.dua');
        $this->live('tamu.balap.dua');
        $blocked = null;
        // Admin lain menekan "Tambah pelanggan" saat router sedang dibaca (baris sudah diklaim).
        $this->fake->onRead = function (string $endpoint) use (&$blocked, $row, $package): void {
            if ($blocked === null && $endpoint === '/ppp/secret/print') {
                try {
                    app(UnlinkedSecretPrefill::class)->createLinked($row->id, fn (): Customer => $this->customer($package, 'tamu.balap.dua'));
                } catch (ValidationException $e) {
                    $blocked = $e->errors()['router_staged_customer_id'][0];
                }
            }
        };

        $this->destroy($row)->assertOk()->assertJsonPath('ok', true);

        $this->assertSame(UnlinkedSecretPrefill::CHANGED_MESSAGE, $blocked);
        $this->assertSame(0, Customer::where('pppoe_user', 'tamu.balap.dua')->count(), 'tidak ada pelanggan setengah jadi');
        $this->assertSame([], $this->fake->secrets);
        $this->assertSame('gone', $row->fresh()->status);
        $this->assertNull($row->fresh()->matched_customer_id);
    }

    public function test_a_row_is_claimed_while_deleting_so_prefill_refuses_it(): void
    {
        $row = $this->row('tamu.klaim');
        $this->live('tamu.klaim');
        $seen = null;
        $this->fake->onRead = function (string $endpoint) use (&$seen, $row): void {
            if ($seen === null && $endpoint === '/ppp/secret/print') {
                $seen = $row->fresh()->status;
                $this->assertNull(app(UnlinkedSecretPrefill::class)->forCreate($row->fresh()), 'form Tambah pelanggan menolak baris yang dihapus');
            }
        };

        $this->destroy($row)->assertOk();

        $this->assertSame('deleting', $seen);
    }

    public function test_a_customer_created_while_the_router_is_read_stops_the_remove_and_releases_the_row(): void
    {
        $package = $this->package();
        $row = $this->row('tamu.balap.tiga');
        $this->live('tamu.balap.tiga');
        $created = false;
        // Pelanggan baru (bukan lewat baris ini) memakai username yang sama selama router dibaca.
        $this->fake->onRead = function (string $endpoint) use (&$created, $package): void {
            if (! $created && $endpoint === '/ppp/secret/print') {
                $created = true;
                $this->customer($package, 'tamu.balap.tiga');
            }
        };

        $response = $this->destroy($row)->assertStatus(409)->assertJsonPath('code', 'customer_exists');

        $this->assertStringContainsString('Username ini dipakai pelanggan #', $response->json('message'));
        $this->assertNoRemove();
        $this->assertCount(1, $this->fake->secrets);
        $this->assertSame('unmatched', $row->fresh()->status, 'baris dilepas kembali');
        $log = DeviceActionLog::firstOrFail();
        $this->assertSame('refused_changed', $log->status);
        $this->assertNull($log->result['secret_backup'] ?? null, 'cadangan tidak dibuat');
    }

    public function test_a_psb_draft_or_migration_created_while_the_router_is_read_stops_the_remove(): void
    {
        $row = $this->row('tamu.balap.empat');
        $this->live('tamu.balap.empat');
        $created = false;
        $this->fake->onRead = function (string $endpoint) use (&$created): void {
            if (! $created && $endpoint === '/ppp/secret/print') {
                $created = true;
                PsbDraft::create(['status' => PsbDraft::STATUS_OLT_OK, 'message_id' => 'uji-draft-balap', 'full_name' => 'SITI CONTOH', 'pppoe_user' => 'tamu.balap.empat']);
            }
        };

        $this->destroy($row)->assertStatus(409)->assertJsonPath('code', 'psb_draft');

        $this->assertNoRemove();
        $this->assertSame('unmatched', $row->fresh()->status);
    }

    public function test_a_lookalike_that_appears_after_the_first_check_still_needs_the_tick(): void
    {
        $package = $this->package();
        $row = $this->row('userbaru_rt02_rw03_odp.2b');
        $this->live('userbaru_rt02_rw03_odp.2b');
        $created = false;
        $this->fake->onRead = function (string $endpoint) use (&$created, $package): void {
            if (! $created && $endpoint === '/ppp/secret/print') {
                $created = true;
                $this->customer($package, 'USERBARU_RT02_RW03_ODP.1A');
            }
        };

        // Pemeriksaan pertama lolos (belum ada yang mirip), pemeriksaan di dalam kunci menemukan yang baru.
        $this->destroy($row)->assertStatus(409)->assertJsonPath('code', 'lookalike_unconfirmed');

        $this->assertNoRemove();
        $this->assertSame('unmatched', $row->fresh()->status);
    }

    // ---- berhasil ---------------------------------------------------------

    public function test_offline_delete_backs_up_removes_verifies_and_keeps_the_password_out_of_every_log(): void
    {
        $row = $this->row('tamu.berhasil');
        $this->live('tamu.berhasil', ['comment' => 'catatan uji', 'remote-address' => '198.18.7.7']);
        $noc = $this->noc();

        $this->destroy($row, [], $noc)->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('message', 'Secret tamu.berhasil dihapus dari Router Uji A. Cadangan disimpan.');

        $this->assertSame([], $this->fake->secrets);
        $this->assertSame('gone', $row->fresh()->status);
        $this->assertNotNull(RouterStagedCustomer::find($row->id), 'baris tidak dihapus, hanya ditandai hilang');

        $log = DeviceActionLog::firstOrFail();
        $this->assertSame('ppp_secret_delete', $log->action);
        $this->assertSame('verified', $log->status);
        $this->assertSame($noc->id, $log->requested_by);
        $this->assertNotNull($log->executed_at);
        $this->assertNotNull($log->verified_at);
        $this->assertSame("php artisan routers:secret-restore {$log->id}", $log->rollback_hint);

        // Cadangan terenkripsi dan bisa dibuka: isi lengkap termasuk password.
        $cipher = $log->result['secret_backup'];
        $this->assertStringNotContainsString('canary-pw', $cipher);
        $backup = json_decode(Crypt::decryptString($cipher), true);
        $this->assertSame('tamu.berhasil', $backup['name']);
        $this->assertSame('canary-pw', $backup['password']);
        $this->assertSame('catatan uji', $backup['comment']);
        $this->assertSame('198.18.7.7', $backup['remote-address']);

        // Tidak ada password di payload, preflight, sisa hasil, log aplikasi, aktivitas, dan catatan panggilan.
        $result = $log->result;
        unset($result['secret_backup']);
        $visible = json_encode([$log->payload, $log->preflight, $result, $log->rollback_hint, $this->logged, DB::table('activity_log')->get(), $this->fake->calls]);
        $this->assertStringNotContainsString('canary-pw', (string) $visible);

        $this->assertDatabaseHas('activity_log', ['description' => 'router_secret_deleted', 'subject_id' => $this->router->id, 'causer_id' => $noc->id]);
        $this->assertNotContains('/ppp/active/remove', $this->fake->endpoints());
    }

    public function test_online_delete_with_the_tick_removes_the_secret_first_then_drops_the_session(): void
    {
        $row = $this->row('tamu.hidup');
        $this->live('tamu.hidup');
        $this->online('tamu.hidup');

        $this->destroy($row, ['disconnect_online' => true])->assertOk()->assertJsonPath('ok', true);

        $endpoints = $this->fake->endpoints();
        $remove = array_search('/ppp/secret/remove', $endpoints, true);
        $kick = array_search('/ppp/active/remove', $endpoints, true);
        $this->assertNotFalse($remove);
        $this->assertNotFalse($kick);
        $this->assertLessThan($kick, $remove, 'secret dihapus dulu, baru sesi diputus');
        $this->assertSame([], $this->fake->sessions);
        $this->assertTrue(DeviceActionLog::firstOrFail()->result['online_disconnected']);
        $this->assertSame('gone', $row->fresh()->status);
    }

    public function test_a_session_that_cannot_be_dropped_leaves_the_delete_unverified(): void
    {
        $row = $this->row('tamu.degil');
        $this->live('tamu.degil');
        $this->online('tamu.degil');
        $this->fake->kickError = true;

        $this->destroy($row, ['disconnect_online' => true])->assertStatus(409)->assertJsonPath('code', 'unverified');

        // Secret sudah terbukti hilang (baris `gone`); hanya sesinya yang belum terputus.
        $this->assertTrue(DeviceActionLog::firstOrFail()->result['kick_failed']);
        $this->assertSame('gone', $row->fresh()->status);
        $this->assertSame([], $this->fake->secrets);
    }

    public function test_the_session_is_not_dropped_unless_the_remove_is_confirmed(): void
    {
        $row = $this->row('tamu.nokick');
        $this->live('tamu.nokick');
        $this->online('tamu.nokick');
        $this->fake->removeIsNoop = true;

        $this->destroy($row, ['disconnect_online' => true])->assertStatus(409)->assertJsonPath('code', 'unverified');

        $this->assertNotContains('/ppp/active/remove', $this->fake->endpoints());
        $this->assertCount(1, $this->fake->sessions);
        $this->assertSame('unmatched', $row->fresh()->status);
    }

    public function test_an_unknown_result_after_the_remove_is_recorded_as_unknown_with_the_backup_kept(): void
    {
        $row = $this->row('tamu.takpasti');
        $this->live('tamu.takpasti');
        // Perintah hapus terkirim, lalu koneksi putus sebelum hasilnya bisa dicek.
        $removed = false;
        $this->fake->onRead = function (string $endpoint) use (&$removed): void {
            if ($removed && $endpoint === '/ppp/secret/print') {
                throw new \RuntimeException('connection lost');
            }
            $removed = $removed || $endpoint === '/ppp/secret/remove';
        };

        $response = $this->destroy($row)->assertStatus(409)->assertJsonPath('code', 'unverified_unknown');

        $this->assertStringNotContainsString('Tidak ada yang dihapus', $response->json('message'));
        $this->assertStringContainsString('Status tidak diketahui', $response->json('message'));
        $log = DeviceActionLog::firstOrFail();
        $this->assertSame('unverified', $log->status);
        $this->assertSame('unverified_unknown', $log->result['code']);
        $this->assertNotEmpty($log->result['secret_backup']);
        // Baris tidak dikembalikan ke "belum terhubung" secara buta; sinkron berikutnya mencatat lagi kalau secret masih ada.
        $this->assertSame('gone', $row->fresh()->status);
    }

    public function test_a_failure_after_the_remove_is_not_recorded_as_nothing_deleted(): void
    {
        $row = $this->row('tamu.catat');
        $this->live('tamu.catat');
        // Gagal menulis catatan hasil akhir tepat setelah secret terhapus dan terverifikasi.
        Activity::creating(function (): void {
            throw new \RuntimeException('disk penuh');
        });

        $this->destroy($row)->assertOk()->assertJsonPath('ok', true);

        $this->assertSame([], $this->fake->secrets);
        $this->assertSame('verified', DeviceActionLog::firstOrFail()->status);
        $this->assertSame('gone', $row->fresh()->status);
    }

    public function test_the_same_request_id_twice_removes_only_once(): void
    {
        $row = $this->row('tamu.ulang');
        $this->live('tamu.ulang');
        $body = $this->body($row);

        $this->destroyWith($row, $body)->assertOk();
        $second = $this->destroyWith($row, $body);

        // Setelah sukses baris sudah "gone", jadi pengulangan ditolak dari database; ledger tetap satu dan hanya satu hapus.
        $second->assertStatus(409);
        $this->assertSame(1, DeviceActionLog::count());
        $this->assertSame(1, count(array_keys($this->fake->endpoints(), '/ppp/secret/remove', true)));
    }

    public function test_a_replayed_request_returns_the_stored_result_without_a_second_remove(): void
    {
        $row = $this->row('tamu.putar');
        $this->live('tamu.putar');
        $body = $this->body($row);
        $this->destroyWith($row, $body)->assertOk();
        // Simulasi: baris belum sempat ditandai hilang saat permintaan yang sama datang lagi.
        $row->newQuery()->whereKey($row->id)->update(['status' => 'unmatched']);

        $replay = $this->destroyWith($row, $body)->assertOk();

        $replay->assertJsonPath('replayed', true);
        $this->assertSame(1, DeviceActionLog::count());
        $this->assertSame(1, count(array_keys($this->fake->endpoints(), '/ppp/secret/remove', true)));
    }

    public function test_backups_older_than_180_days_are_purged_on_the_next_delete(): void
    {
        $old = DeviceActionLog::create([
            'domain' => 'mikrotik', 'target_type' => 'router_ppp_secret', 'target_id' => '1:lama', 'action' => 'ppp_secret_delete',
            'status' => 'verified', 'idempotency_key' => 'uji-lama', 'payload_hash' => str_repeat('a', 64),
            'result' => ['secret_backup' => Crypt::encryptString('{"name":"lama"}'), 'code' => 'deleted'],
            'rollback_hint' => 'php artisan routers:secret-restore 1',
        ]);
        $young = DeviceActionLog::create([
            'domain' => 'mikrotik', 'target_type' => 'router_ppp_secret', 'target_id' => '1:baru', 'action' => 'ppp_secret_delete',
            'status' => 'verified', 'idempotency_key' => 'uji-baru', 'payload_hash' => str_repeat('b', 64),
            'result' => ['secret_backup' => Crypt::encryptString('{"name":"baru"}'), 'code' => 'deleted'],
        ]);
        DB::table('device_action_logs')->where('id', $old->id)->update(['created_at' => now()->subDays(181)]);
        DB::table('device_action_logs')->where('id', $young->id)->update(['created_at' => now()->subDays(179)]);

        $row = $this->row('tamu.bersih');
        $this->live('tamu.bersih');
        $this->destroy($row)->assertOk();

        $this->assertArrayNotHasKey('secret_backup', $old->fresh()->result);
        $this->assertArrayHasKey('backup_purged_at', $old->fresh()->result);
        $this->assertNull($old->fresh()->rollback_hint);
        $this->assertArrayHasKey('secret_backup', $young->fresh()->result);
        $this->assertSame(0, app(UnlinkedSecretDeleter::class)->purgeOldBackups());
    }

    // ---- pemeriksaan dialog -------------------------------------------------

    public function test_delete_check_shows_live_state_without_password_and_changes_nothing(): void
    {
        $row = $this->row('tamu.periksa');
        $this->live('tamu.periksa', ['disabled' => 'true', 'last-logged-out' => '2026-11-01 09:00:00']);
        $this->online('tamu.periksa');

        $response = $this->actingAs($this->noc())->getJson(route('api.routers.unlinked-secrets.delete-check', [$this->router, $row]))->assertOk();

        $response->assertJsonPath('secret.username', 'tamu.periksa')
            ->assertJsonPath('secret.router', 'Router Uji A')
            ->assertJsonPath('secret.profile', 'prof-10')
            ->assertJsonPath('secret.disabled', true)
            ->assertJsonPath('secret.online', true)
            ->assertJsonPath('secret.uptime', '1h2m')
            ->assertJsonPath('secret.address', '198.18.7.7');
        $this->assertStringNotContainsString('canary-pw', $response->getContent());
        $this->assertNoRemove();
        $this->assertSame(0, DeviceActionLog::count());
        $this->assertSame('unmatched', $row->fresh()->status);
    }

    public function test_delete_check_refuses_with_the_same_texts_and_updates_the_list_when_the_secret_is_gone(): void
    {
        $package = $this->package();
        $this->customer($package, 'tamu.dipakai');
        $used = $this->row('tamu.dipakai');
        $gone = $this->row('tamu.sudah.hilang');
        $this->actingAs($this->noc());

        $this->getJson(route('api.routers.unlinked-secrets.delete-check', [$this->router, $used]))
            ->assertStatus(409)->assertJsonPath('code', 'customer_exists');
        $this->getJson(route('api.routers.unlinked-secrets.delete-check', [$this->router, $gone]))
            ->assertStatus(409)->assertJsonPath('message', 'Secret sudah tidak ada di router. Daftar diperbarui.');

        $this->assertSame('gone', $gone->fresh()->status);
        $this->assertNoRemove();
    }

    // ---- helper ---------------------------------------------------------

    private function assertNoRemove(): void
    {
        $this->assertNotContains('/ppp/secret/remove', $this->fake->endpoints());
    }

    private function assertNoDeviceTouched(): void
    {
        $this->assertSame(0, $this->mt->connects, 'router tidak boleh dihubungi');
        $this->assertSame([], $this->fake->calls);
        $this->assertNoRemove();
    }

    /** @param  array<string, mixed>  $override */
    private function destroy(RouterStagedCustomer $row, array $override = [], ?User $as = null): TestResponse
    {
        return $this->destroyWith($row, array_merge($this->body($row), $override), $as);
    }

    /** @param  array<string, mixed>  $body */
    private function destroyWith(RouterStagedCustomer $row, array $body, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->noc())->deleteJson(route('api.routers.unlinked-secrets.destroy', [$this->router, $row]), $body);
    }

    /** @return array<string, mixed> */
    private function body(RouterStagedCustomer $row): array
    {
        return [
            'confirm_username' => $row->pppoe_user,
            'disconnect_online' => false,
            'expected_profile' => 'prof-10',
            'expected_disabled' => false,
            'request_id' => 'req-'.uniqid(),
        ];
    }

    private function noc(): User
    {
        return User::firstWhere('role', 'noc') ?? User::factory()->create(['role' => 'noc']);
    }

    private function scopedAdmin(): User
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $admin->areas()->attach(Area::create(['code' => 'AREA-UJI', 'name' => 'Area Uji']));

        return $admin;
    }

    private function makeRouter(string $name, string $ip): Router
    {
        $router = new Router(['name' => $name, 'ip_address' => $ip, 'port' => 8728, 'is_active' => true]);
        // Kredensial router hanya supaya baris valid; tidak pernah dipakai (sambungan dipalsukan).
        $router->forceFill(['username' => $name]);
        $router->forceFill(['password' => 'canary-pw']);
        $router->save();

        return $router;
    }

    private function package(): Package
    {
        return Package::firstOrCreate(['code' => 'PKG-UJI'], [
            'name' => 'Paket 10', 'mikrotik_profile' => 'prof-10', 'router_id' => $this->router->id, 'price' => 150000, 'is_active' => true,
        ]);
    }

    /** @param  array<string, mixed>  $extra */
    private function customer(Package $package, string $pppoeUser, ?Router $router = null, array $extra = []): Customer
    {
        return Customer::create($extra + [
            'code' => 'CUST-'.uniqid(),
            'name' => 'SITI CONTOH',
            'phone' => '6281200000001',
            'address' => 'Alamat uji',
            'pppoe_user' => $pppoeUser,
            'router_id' => ($router ?? $this->router)->id,
            'package_id' => $package->id,
            'status' => 'active',
            'join_date' => now(),
        ]);
    }

    /** @param  array<string, mixed>  $extra */
    private function row(string $pppoeUser, array $extra = [], ?Router $router = null): RouterStagedCustomer
    {
        return RouterStagedCustomer::create($extra + [
            'router_id' => ($router ?? $this->router)->id,
            'pppoe_user' => $pppoeUser,
            'profile' => 'prof-10',
            'disabled' => false,
            'status' => 'unmatched',
            'first_seen_at' => now()->subDay(),
            'last_seen_at' => now()->subHour(),
        ]);
    }

    /** Baris secret di router palsu. Nilai di $extra menimpa bawaan. @param  array<string, mixed>  $extra */
    private function live(string $name, array $extra = []): void
    {
        $row = ['.id' => '*'.(count($this->fake->secrets) + 1), 'name' => $name, 'profile' => 'prof-10', 'disabled' => 'false', 'service' => 'pppoe'];
        $row['password'] = 'canary-pw';
        $this->fake->secrets[] = $extra + $row;
    }

    private function online(string $name): void
    {
        $this->fake->sessions[] = ['.id' => '*S'.(count($this->fake->sessions) + 1), 'name' => $name, 'uptime' => '1h2m', 'address' => '198.18.7.7', 'caller-id' => 'AA:BB:CC:DD:EE:FF'];
    }
}
