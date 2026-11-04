<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Package;
use App\Models\Router;
use App\Models\Setting;
use App\Services\MikrotikService;
use App\Services\NetworkEnforcementReconciler;
use App\Services\OpsAlertService;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Canary;
use Tests\TestCase;

/**
 * F0 PR-D: read-only router <-> DB isolation reconciliation
 * (`billing:reconcile-network`). The class name contains
 * "SchedulerSafetyContract" so it runs in the blocking CI `test` job filter.
 *
 * MikrotikService is a strict Mockery mock: only the read methods are
 * expected, so any router write (setPPPSecretProfile, kickUser, isolateUser,
 * getClient()->query(...)) would fail the test.
 */
#[Group('blocking-core')]
#[Group('blocking-billing')]
class SchedulerSafetyContractNetworkReconcileTest extends TestCase
{
    use RefreshDatabase;

    private Package $package;

    protected function setUp(): void
    {
        parent::setUp();

        $this->package = Package::create([
            'name' => 'Reconcile Package',
            'code' => 'PKG-RECON-'.uniqid(),
            'price' => 100000,
        ]);
    }

    public function test_classifies_isolation_drift_between_router_and_db(): void
    {
        $router = $this->router('RA-OLT', ['total_pppoe_count' => 7]);
        $ok = $this->customer('C-OK', 'ok.user', 'active', $router);
        $activeIsolated = $this->customer('C-AI', 'active.isolated', 'active', $router);
        $isolatedOpen = $this->customer('C-IO', 'isolated.open', 'isolated', $router);
        $this->customer('C-II', 'isolated.ok', 'isolated', $router);
        $disabledActive = $this->customer('C-DA', 'disabled.active', 'active', $router);
        $disabledIsolated = $this->customer('C-DI', 'disabled.isolated', 'isolated', $router);
        $missing = $this->customer('C-MS', 'missing.user', 'active', $router);
        // Out of scope: terminated, imported (IMP-), no PPPoE user.
        $this->customer('C-TM', 'terminated.user', 'terminated', $router);
        $this->customer('IMP-0001', 'imported.user', 'active', $router);
        $this->customer('C-NP', '', 'active', $router);

        $mt = $this->strictMikrotik();
        $this->expectRouterRead($mt, $router, [
            ['name' => 'ok.user', 'profile' => '10M', 'disabled' => 'false'],
            ['name' => 'Active.Isolated', 'profile' => 'IsolirEbilling', 'disabled' => 'false'],
            ['name' => 'isolated.open', 'profile' => '20M', 'disabled' => 'false'],
            ['name' => 'isolated.ok', 'profile' => 'isolirebilling', 'disabled' => 'false'],
            ['name' => 'disabled.active', 'profile' => '10M', 'disabled' => 'true'],
            ['name' => 'disabled.isolated', 'profile' => 'isolirebilling', 'disabled' => 'yes'],
            ['name' => 'terminated.user', 'profile' => 'isolirebilling', 'disabled' => 'false'],
        ], [['name' => 'active.isolated'], ['name' => 'isolated.open']]);

        $report = app(NetworkEnforcementReconciler::class)->run();

        $this->assertTrue($report['read_only']);
        $byCustomer = $this->findingsByCustomer($report);
        $this->assertSame(['active_but_isolated_on_router', 'drift'], $this->kindSeverity($byCustomer[$activeIsolated->id]));
        $this->assertSame('IsolirEbilling', $byCustomer[$activeIsolated->id]['router_profile']);
        $this->assertTrue($byCustomer[$activeIsolated->id]['online']);
        $this->assertSame(['isolated_but_open_on_router', 'drift'], $this->kindSeverity($byCustomer[$isolatedOpen->id]));
        $this->assertSame('isolirebilling', $byCustomer[$isolatedOpen->id]['detail']['expected_profile']);
        $this->assertSame(['secret_disabled', 'drift'], $this->kindSeverity($byCustomer[$disabledActive->id]));
        $this->assertSame(['secret_disabled', 'info'], $this->kindSeverity($byCustomer[$disabledIsolated->id]));
        $this->assertSame(['secret_missing', 'drift'], $this->kindSeverity($byCustomer[$missing->id]));
        $this->assertArrayNotHasKey($ok->id, $byCustomer);
        $this->assertCount(5, $report['findings'], 'consistent, terminated, imported and blank customers are not reported');

        $this->assertSame(4, $report['totals']['drift']);
        $this->assertSame(1, $report['totals']['info']);
        $this->assertSame(0, $report['totals']['error']);
        $this->assertSame('ok', $report['routers'][0]['status']);
        $this->assertSame(7, $report['routers'][0]['customers']);
        $this->assertSame(7, $report['routers'][0]['customers_checked']);
        $this->assertSame(2, $report['routers'][0]['active_sessions']);
    }

    public function test_router_custom_isolation_profile_is_respected(): void
    {
        $router = $this->router('Custom', ['isolation_profile' => 'BLOKIR']);
        $customer = $this->customer('C-CU', 'custom.user', 'isolated', $router);
        $this->customer('C-CA', 'custom.active', 'active', $router);

        $mt = $this->strictMikrotik();
        $this->expectRouterRead($mt, $router, [
            ['name' => 'custom.user', 'profile' => 'blokir', 'disabled' => 'false'],
            ['name' => 'custom.active', 'profile' => 'isolirebilling', 'disabled' => 'false'],
        ], [], [['name' => 'default'], ['name' => 'BLOKIR']]);

        $report = app(NetworkEnforcementReconciler::class)->run();

        $this->assertArrayNotHasKey($customer->id, $this->findingsByCustomer($report));
        $this->assertSame(0, $report['totals']['drift']);
    }

    public function test_missing_isolation_profile_on_router_is_reported(): void
    {
        $router = $this->router('No Profile');
        $this->customer('C-NP1', 'np.user', 'active', $router);

        $mt = $this->strictMikrotik();
        $this->expectRouterRead($mt, $router, [
            ['name' => 'np.user', 'profile' => '10M', 'disabled' => 'false'],
        ], [], [['name' => 'default'], ['name' => '10M']]);

        $report = app(NetworkEnforcementReconciler::class)->run();

        $this->assertSame(['isolation_profile_missing' => 1], $report['totals']['by_kind']);
        $this->assertSame('isolirebilling', $report['findings'][0]['detail']['isolation_profile']);
    }

    public function test_radius_router_missing_secret_is_flagged_radius_not_drift(): void
    {
        $radiusRouter = $this->router('SKYNET-RA-OLT', ['radius_enabled' => true]);
        $aaaRouter = $this->router('AAA Radius');
        $radiusCustomer = $this->customer('C-RD', 'radius.user', 'active', $radiusRouter);
        $aaaCustomer = $this->customer('C-AA', 'aaa.user', 'isolated', $aaaRouter);

        $mt = $this->strictMikrotik();
        $this->expectRouterRead($mt, $radiusRouter, [], [['name' => 'radius.user']]);
        $this->expectRouterRead($mt, $aaaRouter, [], [], null, ['readable' => true, 'use_radius' => true, 'source' => 'radius', 'rows' => 1]);

        $report = app(NetworkEnforcementReconciler::class)->run();

        $byCustomer = $this->findingsByCustomer($report);
        $this->assertSame(['radius', 'info'], $this->kindSeverity($byCustomer[$radiusCustomer->id]));
        $this->assertTrue($byCustomer[$radiusCustomer->id]['online']);
        $this->assertSame(['radius', 'info'], $this->kindSeverity($byCustomer[$aaaCustomer->id]));
        $this->assertSame(0, $report['totals']['drift']);
        $this->assertTrue($report['routers'][0]['radius']);
        $this->assertTrue($report['routers'][1]['radius']);
    }

    public function test_secret_on_sibling_router_is_reported_as_wrong_router_not_missing(): void
    {
        // Krian: router 15 (x86) and 514 (CCR) are two different devices.
        $krianX86 = $this->router('Krian x86');
        $krianCcr = $this->router('Krian CCR');
        $moved = $this->customer('C-KR', 'krian.moved', 'active', $krianX86);

        $mt = $this->strictMikrotik();
        $this->expectRouterRead($mt, $krianX86, [['name' => 'other', 'profile' => '10M', 'disabled' => 'false']]);
        $this->expectRouterRead($mt, $krianCcr, [['name' => 'krian.moved', 'profile' => '10M', 'disabled' => 'false']]);

        $report = app(NetworkEnforcementReconciler::class)->run();

        $finding = $this->findingsByCustomer($report)[$moved->id];
        $this->assertSame(['secret_on_other_router', 'drift'], $this->kindSeverity($finding));
        $this->assertSame([$krianCcr->id], $finding['detail']['found_on_router_ids']);
        $this->assertSame($krianX86->id, $finding['router_id']);
    }

    public function test_unreachable_router_is_reported_and_other_routers_still_scanned(): void
    {
        $down = $this->router('Down');
        $up = $this->router('Up');
        $this->customer('C-DN', 'down.user', 'active', $down);
        $upCustomer = $this->customer('C-UP', 'up.user', 'active', $up);

        $mt = $this->strictMikrotik();
        $mt->shouldReceive('connect')->once()
            ->withArgs(fn (Router $r, array $o) => $r->id === $down->id && $o['attempts'] === 1)
            ->andThrow(new \RuntimeException('timed out'));
        $this->expectRouterRead($mt, $up, [['name' => 'up.user', 'profile' => 'isolirebilling', 'disabled' => 'false']]);

        $report = app(NetworkEnforcementReconciler::class)->run();

        $this->assertSame('unreachable', $report['routers'][0]['status']);
        $this->assertSame(0, $report['routers'][0]['customers_checked']);
        $this->assertSame(['router_unreachable' => 1], $report['routers'][0]['counts']);
        $this->assertSame('active_but_isolated_on_router', $this->findingsByCustomer($report)[$upCustomer->id]['kind']);
        $this->assertSame(1, $report['totals']['error']);
    }

    public function test_incomplete_secret_read_on_large_router_does_not_flood_secret_missing(): void
    {
        $large = $this->router('Large 15', ['total_pppoe_count' => 100]);
        $this->customer('C-L1', 'large.missing1', 'active', $large);
        $this->customer('C-L2', 'large.missing2', 'active', $large);
        $isolatedOpen = $this->customer('C-L3', 'large.open', 'isolated', $large);

        $mt = $this->strictMikrotik();
        $this->expectRouterRead($mt, $large, [['name' => 'large.open', 'profile' => '10M', 'disabled' => 'false']]);

        $report = app(NetworkEnforcementReconciler::class)->run();

        $this->assertSame('incomplete', $report['routers'][0]['status']);
        $this->assertSame([
            'isolated_but_open_on_router' => 1,
            'secrets_incomplete' => 1,
        ], $report['totals']['by_kind']);
        $this->assertSame('isolated_but_open_on_router', $this->findingsByCustomer($report)[$isolatedOpen->id]['kind']);
    }

    public function test_runtime_budget_skips_remaining_routers(): void
    {
        config()->set('services.network_reconcile.budget_seconds', 60);
        $first = $this->router('First');
        $second = $this->router('Second');
        $this->customer('C-B2', 'budget.user', 'active', $second);

        $mt = $this->strictMikrotik();
        $this->expectRouterRead($mt, $first, []);

        $reconciler = app(NetworkEnforcementReconciler::class);
        $ticks = [0.0, 0.0, 61.0, 61.0, 61.0, 61.0, 61.0];
        $reconciler->setClock(function () use (&$ticks): float {
            return count($ticks) > 1 ? array_shift($ticks) : $ticks[0];
        });

        $report = $reconciler->run();

        $this->assertSame('ok', $report['routers'][0]['status']);
        $this->assertSame('skipped_budget', $report['routers'][1]['status']);
        $this->assertSame($second->id, $report['routers'][1]['router_id']);
        $this->assertSame(['router_skipped_budget' => 1], $report['totals']['by_kind']);
    }

    public function test_command_router_option_json_store_and_prune(): void
    {
        $target = $this->router('Target', ['is_active' => false]);
        $this->router('Other');
        $customer = $this->customer('C-ST', 'store.user', 'isolated', $target);

        DB::table('enforcement_drift')->insert([
            'run_id' => '00000000-0000-0000-0000-000000000000',
            'kind' => 'secret_missing',
            'severity' => 'drift',
            'detected_at' => now()->subDays(30),
        ]);

        $mt = $this->strictMikrotik();
        $this->expectRouterRead($mt, $target, [['name' => 'store.user', 'profile' => '10M', 'disabled' => 'false']]);

        $exit = Artisan::call('billing:reconcile-network', ['--router' => (string) $target->id, '--json' => true, '--store' => true]);
        $this->assertSame(0, $exit);

        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(1, $report['routers'], 'only the requested router (even inactive) is scanned');
        $this->assertSame(1, $report['stored_rows']);

        $this->assertDatabaseCount('enforcement_drift', 1);
        $this->assertDatabaseHas('enforcement_drift', [
            'run_id' => $report['run_id'],
            'router_id' => $target->id,
            'customer_id' => $customer->id,
            'pppoe_user' => 'store.user',
            'kind' => 'isolated_but_open_on_router',
            'severity' => 'drift',
            'db_status' => 'isolated',
            'router_profile' => '10M',
            'router_disabled' => 0,
            'online' => 0,
        ]);

        $summary = Setting::get(NetworkEnforcementReconciler::SUMMARY_SETTING);
        $this->assertSame($report['run_id'], $summary['run_id']);
        $this->assertArrayNotHasKey('findings', $summary);
    }

    public function test_command_rejects_non_numeric_router(): void
    {
        $this->strictMikrotik();

        $this->artisan('billing:reconcile-network', ['--router' => '15;drop'])->assertExitCode(2);
    }

    public function test_notify_sends_count_only_summary_without_customer_data(): void
    {
        $router = $this->router('Notify Router');
        $this->customer('C-NT', 'secret.username', 'active', $router);

        $mt = $this->strictMikrotik();
        $this->expectRouterRead($mt, $router, [['name' => 'secret.username', 'profile' => 'isolirebilling', 'disabled' => 'false']]);

        $sent = [];
        $ops = Mockery::mock(OpsAlertService::class);
        $ops->shouldReceive('send')->once()->andReturnUsing(function (string $message) use (&$sent): bool {
            $sent[] = $message;

            return true;
        });
        $this->app->instance(OpsAlertService::class, $ops);

        $this->artisan('billing:reconcile-network', ['--notify' => true])->assertExitCode(0);

        $this->assertCount(1, $sent);
        $this->assertStringContainsString('Drift: 1', $sent[0]);
        $this->assertStringContainsString('active_but_isolated_on_router=1', $sent[0]);
        $this->assertStringNotContainsString('secret.username', $sent[0]);
        $this->assertStringNotContainsString('C-NT', $sent[0]);
        $this->assertDatabaseCount('enforcement_drift', 0);
    }

    public function test_reconciler_and_command_source_never_call_router_writes(): void
    {
        foreach ([
            'app/Services/NetworkEnforcementReconciler.php',
            'app/Console/Commands/ReconcileNetworkEnforcement.php',
        ] as $path) {
            $source = (string) file_get_contents(base_path($path));
            foreach ([
                'setPPPSecretProfile', 'isolateUser', 'reconnectUser', 'kickUser', 'isolateCustomerNow',
                'reconnectCustomerNow', 'addPPPSecret', 'disablePPPSecret', 'renamePPPSecret',
                'ensureIsolationProfile', 'deleteIsolationProfile', 'getClient', 'client()', 'new Query',
                '/remove', '/set', '/add', 'IsolateCustomerJob', 'ReconnectCustomerJob',
            ] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $source, "{$path} must stay read-only ({$forbidden})");
            }
        }
    }

    public function test_schedule_entry_is_registered_off_by_default_in_every_mode(): void
    {
        foreach (['safe', 'monitoring', 'full'] as $mode) {
            config()->set('services.network_reconcile.schedule_enabled', false);
            $event = $this->reconcileEvent($mode);

            $this->assertSame('47 7 * * *', $event->expression, $mode);
            $this->assertSame('Asia/Jakarta', $event->timezone, $mode);
            $this->assertTrue($event->withoutOverlapping, $mode);
            $this->assertStringContainsString('billing:reconcile-network --store', $event->command, $mode);
            $this->assertFalse($event->filtersPass($this->app), $mode.': must be OFF by default');

            config()->set('services.network_reconcile.schedule_enabled', true);
            $this->assertTrue($event->filtersPass($this->app), $mode.': enabled by flag');
        }
    }

    public function test_schedule_flag_defaults_to_off_in_config_source(): void
    {
        $source = (string) file_get_contents(base_path('config/services.php'));

        $this->assertStringContainsString("'schedule_enabled' => (bool) env('NETWORK_RECONCILE_SCHEDULE_ENABLED', false)", $source);
    }

    private function reconcileEvent(string $mode): ScheduledEvent
    {
        config(['services.ebilling_scheduler.mode' => $mode]);

        $schedule = new Schedule;
        $this->app->instance(Schedule::class, $schedule);
        ScheduleFacade::clearResolvedInstance(Schedule::class);

        require base_path('routes/console.php');

        $matches = array_values(array_filter(
            $schedule->events(),
            static fn (ScheduledEvent $e): bool => $e->description === 'Read-only router vs DB isolation reconciliation (enforcement_drift)'
        ));
        $this->assertCount(1, $matches, "reconcile entry missing in {$mode} mode");

        return $matches[0];
    }

    private function strictMikrotik(): MockInterface
    {
        $mt = Mockery::mock(MikrotikService::class);
        $this->app->instance(MikrotikService::class, $mt);

        return $mt;
    }

    private function expectRouterRead(
        MockInterface $mt,
        Router $router,
        array $secrets,
        array $active = [],
        ?array $profiles = null,
        array $aaa = ['readable' => true, 'use_radius' => false, 'source' => 'mikrotik_local', 'rows' => 1],
    ): void {
        $profiles ??= [['name' => 'default'], ['name' => 'isolirebilling']];
        $mt->shouldReceive('connect')->once()
            ->withArgs(fn (Router $r, array $o) => $r->id === $router->id && $o['attempts'] === 1 && $o['timeout'] === 25)
            ->andReturnSelf();
        $mt->shouldReceive('getPPPSecrets')->once()->andReturn($secrets);
        $mt->shouldReceive('getActiveConnections')->once()->with(false)->andReturn($active);
        $mt->shouldReceive('getProfiles')->once()->andReturn($profiles);
        $mt->shouldReceive('getPppAuthenticationSource')->once()->andReturn($aaa);
        $mt->shouldReceive('disconnect')->once();
    }

    /** @return array<int, array<string, mixed>> */
    private function findingsByCustomer(array $report): array
    {
        $out = [];
        foreach ($report['findings'] as $finding) {
            if ($finding['customer_id'] !== null) {
                $out[$finding['customer_id']] = $finding;
            }
        }

        return $out;
    }

    private function kindSeverity(array $finding): array
    {
        return [$finding['kind'], $finding['severity']];
    }

    private function router(string $name, array $overrides = []): Router
    {
        return Router::create(array_merge([
            'name' => $name,
            'ip_address' => '10.9.'.random_int(0, 250).'.'.random_int(1, 250),
            'username' => 'admin',
            'password' => Canary::of('pw'),
            'port' => 8728,
            'is_active' => true,
        ], $overrides));
    }

    private function customer(string $code, string $pppoeUser, string $status, Router $router): Customer
    {
        return Customer::create([
            'code' => $code,
            'name' => $code,
            'phone' => '080000000000',
            'address' => 'Reconcile Address',
            'pppoe_user' => $pppoeUser,
            'package_id' => $this->package->id,
            'router_id' => $router->id,
            'status' => $status,
        ]);
    }
}
