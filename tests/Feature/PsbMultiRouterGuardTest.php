<?php

namespace Tests\Feature;

use App\Exceptions\PsbLockBusyException;
use App\Http\Controllers\CustomerController;
use App\Jobs\PollPsbSnJob;
use App\Jobs\ProcessPsbWaJob;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Router;
use App\Models\Setting;
use App\Models\User;
use App\Services\EvolutionApiService;
use App\Services\MikrotikService;
use App\Services\Psb\PsbConfigResolver;
use App\Services\Psb\PsbWaReplySender;
use App\Services\PsbOrchestratorService;
use App\Services\PsbSiteResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Canary;
use Tests\TestCase;

#[Group('blocking-psb')]
class PsbMultiRouterGuardTest extends TestCase
{
    use RefreshDatabase;

    // ── PSB-3: guard salah-target (silent default RA) ──────────────────────

    public function test_unknown_daerah_without_router_is_ambiguous(): void
    {
        $site = app(PsbSiteResolver::class)->resolve('daerah-baru-antah-berantah', null);

        $this->assertTrue($site['ambiguous']);
        $this->assertNotNull($site['message']);
    }

    public function test_unknown_daerah_with_forced_router_is_not_ambiguous(): void
    {
        $router = $this->router('SKYNET-BARU-OLT');

        $site = app(PsbSiteResolver::class)->resolve('daerah-baru-antah-berantah', $router->id);

        $this->assertFalse($site['ambiguous']);
    }

    public function test_known_daerah_is_not_ambiguous(): void
    {
        $site = app(PsbSiteResolver::class)->resolve('Skynet-Kalirejo RT 3', null);

        $this->assertFalse($site['ambiguous']);
        $this->assertSame('ra_full_olt', $site['key']);
    }

    // ── PSB-1/2: resolvePackageFromHint per-router + is_active ─────────────

    public function test_package_hint_prefers_target_router_and_ignores_other_router(): void
    {
        $routerA = $this->router('Router A');
        $routerB = $this->router('Router B');
        $pkgA = $this->package($routerA, '100M', 'Paket 100M A', active: true);
        $pkgB = $this->package($routerB, '100M', 'Paket 100M B', active: true);

        $svc = app(PsbOrchestratorService::class);

        $this->assertSame($pkgB->id, $svc->resolvePackageFromHint('100M', $routerB->id)?->id);
        // Router C tanpa paket: JANGAN fallback ke paket router lain
        $routerC = $this->router('Router C');
        $this->assertNull($svc->resolvePackageFromHint('100M', $routerC->id));
    }

    public function test_package_hint_falls_back_to_shared_package_only(): void
    {
        $routerA = $this->router('Router A');
        $this->package($routerA, '100M', 'Paket 100M A', active: true);
        $shared = Package::create([
            'name' => 'Paket 100M Shared', 'code' => 'SHARED100', 'price' => 100000,
            'mikrotik_profile' => '100M', 'is_active' => true,
        ]);

        $routerC = $this->router('Router C');
        $found = app(PsbOrchestratorService::class)->resolvePackageFromHint('100M', $routerC->id);

        $this->assertSame($shared->id, $found?->id);
    }

    public function test_numeric_package_hint_rejects_name_match_with_wrong_mikrotik_profile(): void
    {
        $router = $this->router('Router Gadang');
        $this->package($router, '200MB', 'Paket 100M', active: true);

        $this->assertNull(app(PsbOrchestratorService::class)->resolvePackageFromHint('100M', $router->id));
    }

    public function test_inactive_draft_package_is_never_picked_by_bot(): void
    {
        $router = $this->router('Router A');
        $this->package($router, 'KALIREJO-NET', 'Paket KALIREJO-NET', active: false); // draft hasil sync

        $this->assertNull(app(PsbOrchestratorService::class)->resolvePackageFromHint('KALIREJO-NET', $router->id));
    }

    // ── PSB-2: sync profile tidak menimpa data operator + token ────────────

    public function test_push_profile_creates_draft_and_never_resets_operator_price(): void
    {
        $router = $this->router('Router Sync');
        Setting::set('wg_push_profile_token', 'rahasia-123');

        $this->withHeader('X-Push-Token', 'rahasia-123')->post('/wg/push-profile?router_id='.$router->id, [
            'name' => 'KALIREJO-NET', 'rate-limit' => '50M/50M',
        ])->assertOk();

        $pkg = Package::query()->where('router_id', $router->id)->where('mikrotik_profile', 'KALIREJO-NET')->first();
        $this->assertNotNull($pkg);
        $this->assertFalse((bool) $pkg->is_active); // draft: wajib direview operator
        $this->assertSame(0, (int) $pkg->price);

        // Operator isi harga + nama + aktifkan
        $pkg->forceFill(['price' => 150000, 'name' => 'Paket Kalirejo 50M', 'is_active' => true])->save();

        // Re-sync (rate limit berubah) TIDAK boleh me-reset harga/nama/status
        $this->withHeader('X-Push-Token', 'rahasia-123')->post('/wg/push-profile?router_id='.$router->id, [
            'name' => 'KALIREJO-NET', 'rate-limit' => '60M/60M',
        ])->assertOk();

        $pkg->refresh();
        $this->assertSame(150000, (int) $pkg->price);
        $this->assertSame('Paket Kalirejo 50M', $pkg->name);
        $this->assertTrue((bool) $pkg->is_active);
        $this->assertSame('60M/60M', $pkg->rate_limit);
    }

    public function test_push_profile_requires_token_when_configured(): void
    {
        $router = $this->router('Router Token');
        Setting::set('wg_push_profile_token', 'rahasia-123');

        $this->post('/wg/push-profile?router_id='.$router->id, [
            'name' => '50MB', 'rate-limit' => '50M/50M',
        ])->assertStatus(403);

        $this->post('/wg/push-profile?router_id='.$router->id.'&push_token=rahasia-123', [
            'name' => '50MB', 'rate-limit' => '50M/50M',
        ])->assertStatus(403);

        $this->withHeader('X-Push-Token', 'rahasia-123')->post('/wg/push-profile?router_id='.$router->id, [
            'name' => '50MB', 'rate-limit' => '50M/50M',
        ])->assertOk();
    }

    public function test_push_profile_fails_closed_when_token_is_not_configured(): void
    {
        $router = $this->router('Router No Token');

        $this->post('/wg/push-profile?router_id='.$router->id, [
            'name' => '50MB', 'rate-limit' => '50M/50M',
        ])->assertStatus(503);

        $this->assertDatabaseMissing('router_profiles', [
            'router_id' => $router->id,
            'name' => '50MB',
        ]);
    }

    // ── Auto-sync draft command + H1b export middleware ────────────────────

    public function test_sync_profiles_draft_command_creates_draft_and_preserves_operator_price(): void
    {
        $router = $this->router('Router Cmd');
        $existing = $this->package($router, '50MB', 'Paket Kalirejo 50M', active: true);
        $existing->forceFill(['price' => 150000])->save();

        $mock = \Mockery::mock(MikrotikService::class);
        $mock->shouldReceive('connect')->andReturnSelf();
        $mock->shouldReceive('getProfiles')->andReturn([
            ['name' => '50MB', 'rate-limit' => '55M/55M'],
            ['name' => 'BARU-NET', 'rate-limit' => '20M/20M'],
            ['name' => 'default', 'rate-limit' => ''],
        ]);
        $mock->shouldReceive('disconnect')->andReturnNull();
        $this->app->instance(MikrotikService::class, $mock);

        $exit = Artisan::call('routers:sync-profiles-draft', ['--router' => $router->id]);
        $out = Artisan::output();
        $this->assertSame(0, $exit, 'Output: '.$out);
        $this->assertStringContainsString('draft_baru=1', $out, 'Output asli command: '.$out);

        $existing->refresh();
        $this->assertSame(150000, (int) $existing->price);
        $this->assertSame('Paket Kalirejo 50M', $existing->name);
        $this->assertTrue((bool) $existing->is_active);
        $this->assertSame('55M/55M', $existing->rate_limit);

        $draft = Package::query()->where('router_id', $router->id)->where('mikrotik_profile', 'BARU-NET')->first();
        $this->assertNotNull($draft);
        $this->assertFalse((bool) $draft->is_active);
        $this->assertSame(0, (int) $draft->price);
        $this->assertNull(Package::query()->where('router_id', $router->id)->where('mikrotik_profile', 'default')->first());
    }

    public function test_customers_export_requires_admin(): void
    {
        $teknisi = User::factory()->create(['role' => 'teknisi']);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($teknisi)->get('/customers/export')->assertStatus(403);
        $this->actingAs($admin)->get('/customers/export')->assertSuccessful();
    }

    public function test_customer_export_never_includes_archive_rows(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $package = Package::create([
            'name' => 'Export Test',
            'code' => 'EXPORT-TEST',
            'price' => 100000,
            'mikrotik_profile' => 'EXPORT-TEST',
            'is_active' => true,
        ]);
        $active = Customer::create([
            'code' => 'EXPORT-ACTIVE',
            'name' => 'Export Active',
            'phone' => '080000000001',
            'address' => 'Test address',
            'package_id' => $package->id,
            'pppoe_user' => 'EXPORT_ACTIVE',
            'status' => 'active',
        ]);
        $archived = Customer::create([
            'code' => 'EXPORT-ARCHIVED',
            'name' => 'Export Archived',
            'phone' => '080000000002',
            'address' => 'Test address',
            'package_id' => $package->id,
            'pppoe_user' => 'EXPORT_ARCHIVED',
            'status' => 'terminated',
        ]);
        $archived->delete();

        $request = Request::create('/customers/export?archive=1', 'GET');
        $request->setUserResolver(fn () => $admin);
        $controller = app(CustomerController::class);
        $method = new \ReflectionMethod($controller, 'customerIndexQuery');
        $method->setAccessible(true);

        $ids = $method->invoke($controller, $request, false)->pluck('id')->all();

        $this->assertContains($active->id, $ids);
        $this->assertNotContains($archived->id, $ids);
    }

    // ── Batch 1 paralelisasi: queue dedikasi + auto-resume ─────────────────

    public function test_psb_job_targets_dedicated_queue_and_poll_moves_to_monitoring(): void
    {
        $this->assertSame('psb', (new ProcessPsbWaJob(['message_id' => 'q1']))->queue);
        $this->assertSame('monitoring', (new PollPsbSnJob)->queue);
    }

    public function test_lock_busy_triggers_release_with_single_notice(): void
    {
        $orch = \Mockery::mock(PsbOrchestratorService::class);
        $orch->shouldReceive('handleInbound')->andThrow(new PsbLockBusyException('Antrian OLT sibuk'));
        $orch->shouldReceive('reply')->once()->withArgs(fn ($g, $t) => str_contains($t, 'otomatis dicoba lagi'))->andReturnTrue();

        $job = \Mockery::mock(ProcessPsbWaJob::class.'[attempts,release]', [['group_jid' => 'g@g.us', 'message_id' => 'm1']]);
        $job->shouldReceive('attempts')->andReturn(1);
        $job->shouldReceive('release')->once()->withArgs(fn ($d) => $d >= 45 && $d <= 65);

        $job->handle($orch);
        $this->addToAssertionCount(1); // ekspektasi mockery = assertion
    }

    public function test_lock_busy_kill_switch_restores_old_behavior(): void
    {
        Setting::set('psb_auto_resume_enabled', '0');

        $orch = \Mockery::mock(PsbOrchestratorService::class);
        $orch->shouldReceive('handleInbound')->andThrow(new PsbLockBusyException('Antrian IP/secret RA sibuk'));
        $orch->shouldReceive('reply')->once()->withArgs(fn ($g, $t) => str_contains($t, 'Antrian perangkat sibuk'))->andReturnTrue();

        $job = \Mockery::mock(ProcessPsbWaJob::class.'[attempts,release]', [['group_jid' => 'g@g.us', 'message_id' => 'm2']]);
        $job->shouldReceive('release')->never();

        $job->handle($orch);
        $this->addToAssertionCount(1);
    }

    public function test_reply_dedupe_blocks_only_identical_messages(): void
    {
        Setting::set('psb_reply_dedupe_seconds', '15');
        $svc = new PsbWaReplySender(new EvolutionApiService, new PsbConfigResolver);
        $m = new \ReflectionMethod($svc, 'allowReplyMessage');
        $m->setAccessible(true);

        $group = 'dedupe-'.uniqid().'@g.us';
        $this->assertTrue($m->invoke($svc, $group, 'Balasan sama', []));
        $this->assertFalse($m->invoke($svc, $group, 'Balasan sama', []));

        for ($i = 0; $i < 25; $i++) {
            $this->assertTrue($m->invoke($svc, $group, 'Balasan pelanggan '.$i, []));
        }
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function router(string $name): Router
    {
        return Router::create([
            'name' => $name,
            'ip_address' => '198.18.18.'.random_int(2, 200),
            'username' => 'admin',
            'password' => Canary::of('pw'),
            'is_active' => true,
        ]);
    }

    private function package(Router $router, string $profile, string $name, bool $active): Package
    {
        return Package::create([
            'router_id' => $router->id,
            'name' => $name,
            'code' => strtoupper(preg_replace('/[^a-z0-9]+/i', '_', $name)),
            'price' => $active ? 100000 : 0,
            'mikrotik_profile' => $profile,
            'is_active' => $active,
        ]);
    }
}
