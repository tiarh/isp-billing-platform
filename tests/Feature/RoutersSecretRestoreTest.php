<?php

namespace Tests\Feature;

use App\Models\DeviceActionLog;
use App\Models\Router;
use App\Models\RouterStagedCustomer;
use App\Models\Setting;
use App\Models\User;
use App\Services\MikrotikService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\FakeRouterOsClient;
use Tests\Support\FakeSecretMikrotikService;
use Tests\TestCase;

/**
 * `routers:secret-restore`: pulihkan secret yang dihapus lewat "Hapus secret" dari cadangan terenkripsi.
 */
#[Group('blocking-core')]
class RoutersSecretRestoreTest extends TestCase
{
    use RefreshDatabase;

    private Router $router;

    private FakeRouterOsClient $fake;

    private DeviceActionLog $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $routerName = 'Router Uji A';
        $this->router = new Router(['name' => $routerName, 'ip_address' => '198.18.22.1', 'port' => 8728, 'is_active' => true]);
        // Kredensial router hanya supaya baris valid; tidak pernah dipakai (sambungan dipalsukan).
        $this->router->forceFill(['username' => $routerName]);
        $this->router->forceFill(['password' => 'canary-pw']);
        $this->router->save();
        $this->fake = new FakeRouterOsClient;
        $this->app->instance(MikrotikService::class, new FakeSecretMikrotikService($this->fake));
        Setting::set('router_secret_delete_enabled', '1');
        Setting::set('control_plane.device_read_only_mode', '0');

        // Satu penghapusan sungguhan lewat jalur normal, supaya ledger dan cadangannya asli.
        $row = RouterStagedCustomer::create([
            'router_id' => $this->router->id, 'pppoe_user' => 'tamu.pulih', 'profile' => 'prof-10', 'disabled' => false,
            'status' => 'unmatched', 'first_seen_at' => now()->subDay(), 'last_seen_at' => now()->subHour(),
        ]);
        $live = ['.id' => '*1', 'name' => 'tamu.pulih', 'profile' => 'prof-10', 'disabled' => 'false', 'service' => 'pppoe', 'comment' => 'catatan uji'];
        $live['password'] = 'canary-pw';
        $live['last-logged-out'] = '2026-11-01 09:00:00';
        $this->fake->secrets[] = $live;
        $this->actingAs(User::factory()->create(['role' => 'noc']))->deleteJson(route('api.routers.unlinked-secrets.destroy', [$this->router, $row]), [
            'confirm_username' => 'tamu.pulih', 'disconnect_online' => false, 'expected_profile' => 'prof-10', 'expected_disabled' => false, 'request_id' => 'req-pulih',
        ])->assertOk();
        $this->ledger = DeviceActionLog::firstOrFail();
        $this->fake->calls = [];
    }

    public function test_dry_run_is_the_default_reads_the_backup_and_writes_nothing(): void
    {
        $this->assertSame(0, Artisan::call('routers:secret-restore', ['ledger' => $this->ledger->id]));

        $output = Artisan::output();
        $this->assertStringContainsString('Dry-run', $output);
        $this->assertStringNotContainsString('canary-pw', $output);
        $this->assertSame([], $this->fake->secrets);
        $this->assertNotContains('/ppp/secret/add', $this->fake->endpoints());
        $this->assertArrayNotHasKey('restored_at', $this->ledger->fresh()->result);
    }

    public function test_apply_restores_only_whitelisted_fields_verifies_and_marks_restored(): void
    {
        $this->assertSame(0, Artisan::call('routers:secret-restore', ['ledger' => $this->ledger->id, '--apply' => true]));

        $this->assertCount(1, $this->fake->secrets);
        $restored = $this->fake->secrets[0];
        $this->assertSame('tamu.pulih', $restored['name']);
        $this->assertSame('prof-10', $restored['profile']);
        $this->assertSame('catatan uji', $restored['comment']);
        $this->assertSame('canary-pw', $restored['password']);
        // Field baca-saja dari router tidak ditulis balik.
        $this->assertArrayNotHasKey('last-logged-out', $restored);
        $this->assertStringNotContainsString('canary-pw', Artisan::output());
        $this->assertStringNotContainsString('canary-pw', implode("\n", $this->fake->calls));

        $result = $this->ledger->fresh()->result;
        $this->assertNotEmpty($result['restored_at']);
        $this->assertSame('cli', $result['restored_by']);

        // Kedua kali ditolak.
        $this->assertSame(1, Artisan::call('routers:secret-restore', ['ledger' => $this->ledger->id, '--apply' => true]));
        $this->assertStringContainsString('Sudah dipulihkan', Artisan::output());
        $this->assertCount(1, $this->fake->secrets);
    }

    public function test_it_refuses_when_a_secret_with_that_name_already_exists(): void
    {
        $existing = ['.id' => '*9', 'name' => 'tamu.pulih', 'profile' => 'prof-lain'];
        $this->fake->secrets[] = $existing;

        $this->assertSame(1, Artisan::call('routers:secret-restore', ['ledger' => $this->ledger->id, '--apply' => true]));

        $this->assertStringContainsString('sudah ada di router', Artisan::output());
        $this->assertSame([$existing], $this->fake->secrets);
        $this->assertNotContains('/ppp/secret/add', $this->fake->endpoints());
    }

    public function test_it_refuses_in_read_only_mode(): void
    {
        Setting::set('control_plane.device_read_only_mode', '1');

        $this->assertSame(1, Artisan::call('routers:secret-restore', ['ledger' => $this->ledger->id, '--apply' => true]));

        $this->assertStringContainsString('Mode aman perangkat aktif', Artisan::output());
        $this->assertSame([], $this->fake->calls);
    }

    public function test_an_unverified_delete_can_be_restored_but_never_overwrites_a_live_secret(): void
    {
        $this->ledger->forceFill(['status' => 'unverified'])->save();

        // Secret masih ada di router (hasil hapus tidak pasti): tidak ditimpa.
        $live = ['.id' => '*9', 'name' => 'tamu.pulih', 'profile' => 'prof-lain'];
        $this->fake->secrets = [$live];
        $this->assertSame(1, Artisan::call('routers:secret-restore', ['ledger' => $this->ledger->id, '--apply' => true]));
        $this->assertSame([$live], $this->fake->secrets);
        $this->assertNotContains('/ppp/secret/add', $this->fake->endpoints());

        // Dry-run (bawaan) tidak menulis; --apply memulihkan dari cadangan.
        $this->fake->secrets = [];
        $this->assertSame(0, Artisan::call('routers:secret-restore', ['ledger' => $this->ledger->id]));
        $this->assertSame([], $this->fake->secrets);
        $this->assertSame(0, Artisan::call('routers:secret-restore', ['ledger' => $this->ledger->id, '--apply' => true]));
        $this->assertSame('tamu.pulih', $this->fake->secrets[0]['name']);
        $this->assertStringNotContainsString('canary-pw', Artisan::output());
    }

    public function test_it_refuses_unverified_unknown_and_purged_ledgers(): void
    {
        $this->assertSame(1, Artisan::call('routers:secret-restore', ['ledger' => 999999]));

        $this->ledger->forceFill(['status' => 'refused_online'])->save();
        $this->assertSame(1, Artisan::call('routers:secret-restore', ['ledger' => $this->ledger->id, '--apply' => true]));
        $this->assertStringContainsString('belum terverifikasi', Artisan::output());

        $result = $this->ledger->result;
        unset($result['secret_backup']);
        $this->ledger->forceFill(['status' => 'verified', 'result' => $result])->save();
        $this->assertSame(1, Artisan::call('routers:secret-restore', ['ledger' => $this->ledger->id, '--apply' => true]));
        $this->assertStringContainsString('Cadangan sudah tidak ada', Artisan::output());

        $this->assertSame([], $this->fake->calls);
    }
}
