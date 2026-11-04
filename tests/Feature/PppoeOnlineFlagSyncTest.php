<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Package;
use App\Models\Router;
use App\Services\MikrotikService;
use App\Services\MtPlanCTierService;
use App\Services\PppoeOnlineFlagWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Support\Canary;
use Tests\TestCase;

/**
 * Audit 2026-10-08 (Gadang + Krian online status): the monitoring session
 * lane must keep customers.is_online fresh (online AND offline) without ever
 * mass-offlining on a failed / partial router fetch. No router is contacted:
 * MikrotikService is a fake.
 */
#[Group('blocking-core')]
class PppoeOnlineFlagSyncTest extends TestCase
{
    use RefreshDatabase;

    private Package $package;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        PppoeOnlineFlagWriter::flushSchemaCache();
        $this->package = Package::create(['code' => 'PPP-FLAG', 'name' => 'PPP flag', 'price' => 0]);
        Carbon::setTestNow(Carbon::parse('2026-10-08 03:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_read_only_lane_without_flag_option_writes_no_customer_rows(): void
    {
        $router = $this->router('GADANG');
        $online = $this->customer($router, 'gd-online', ['is_online' => false]);
        $gone = $this->customer($router, 'gd-gone', ['is_online' => true]);
        $this->fakeMikrotik(count: 1, names: ['gd-online']);

        $result = app(MtPlanCTierService::class)->syncSessions($router, 12, false, true, false);

        $this->assertTrue($result['ok']);
        $this->assertSame('none', $result['online_flags']);
        $this->assertFalse((bool) $online->fresh()->is_online);
        $this->assertTrue((bool) $gone->fresh()->is_online);
        $this->assertNull($online->fresh()->pppoe_online_checked_at);
    }

    public function test_complete_dump_writes_online_and_offline_with_fresh_stamp_only(): void
    {
        $router = $this->router('GADANG');
        $other = $this->router('KRIAN-OLT');
        $online = $this->customer($router, 'gd-online', ['is_online' => false, 'mikrotik_sync_status' => 'synced']);
        $gone = $this->customer($router, 'gd-gone', ['is_online' => true, 'mikrotik_sync_status' => 'synced']);
        $missing = $this->customer($router, 'gd-missing', ['is_online' => true, 'mikrotik_sync_status' => 'missing']);
        $elsewhere = $this->customer($other, 'gd-online-2', ['is_online' => false]);
        $this->fakeMikrotik(count: 2, names: ['gd-online', 'gd-online-2']);

        $result = app(MtPlanCTierService::class)->syncSessions($router, 12, false, true, true);

        $this->assertSame('authoritative', $result['online_flags']);
        $now = Carbon::now()->toDateTimeString();

        $this->assertTrue((bool) $online->fresh()->is_online);
        $this->assertSame($now, $online->fresh()->pppoe_online_checked_at->toDateTimeString());
        // Monitoring flag writer touches ONLY is_online + pppoe_online_checked_at.
        $this->assertSame('synced', $online->fresh()->mikrotik_sync_status);

        $this->assertFalse((bool) $gone->fresh()->is_online);
        $this->assertSame($now, $gone->fresh()->pppoe_online_checked_at->toDateTimeString());

        // Secret missing on this router: not demoted, no fresh stamp => unknown.
        $this->assertTrue((bool) $missing->fresh()->is_online);
        $this->assertNull($missing->fresh()->pppoe_online_checked_at);

        // Customer of another router with the same session name: no router_id move.
        $this->assertSame($other->id, (int) $elsewhere->fresh()->router_id);
        $this->assertFalse((bool) $elsewhere->fresh()->is_online);
    }

    public function test_partial_dump_promotes_seen_names_and_never_mass_offlines(): void
    {
        $router = $this->router('RA-OLT');
        $seen = $this->customer($router, 'ra-seen', ['is_online' => false]);
        $stillOn = $this->customer($router, 'ra-unseen', [
            'is_online' => true,
            'pppoe_online_checked_at' => Carbon::now()->subHour(),
        ]);
        // 10 names vs count-only 100 => far below the completeness ratio.
        $names = array_merge(['ra-seen'], array_map(fn (int $i) => 'ra-x-'.$i, range(1, 9)));
        $this->fakeMikrotik(count: 100, names: $names);

        $result = app(MtPlanCTierService::class)->syncSessions($router, 12, false, true, true);

        $this->assertSame('soft', $result['online_flags']);
        $this->assertTrue((bool) $seen->fresh()->is_online);
        $this->assertTrue((bool) $stillOn->fresh()->is_online, 'partial dump must not demote');
        $this->assertSame(
            Carbon::now()->subHour()->toDateTimeString(),
            $stillOn->fresh()->pppoe_online_checked_at->toDateTimeString(),
            'unseen customers keep their ageing stamp (=> unknown once stale)',
        );
    }

    public function test_name_dump_failure_writes_nothing(): void
    {
        $router = $this->router('KRIAN');
        $on = $this->customer($router, 'kr-on', ['is_online' => true]);
        $this->fakeMikrotik(count: 400, names: [], namesThrow: true);

        $result = app(MtPlanCTierService::class)->syncSessions($router, 12, false, true, true);

        $this->assertTrue($result['ok']);
        $this->assertSame('none', $result['online_flags']);
        $this->assertTrue((bool) $on->fresh()->is_online);
        $this->assertNull($on->fresh()->pppoe_online_checked_at);
    }

    public function test_router_connect_failure_does_not_mass_offline(): void
    {
        $router = $this->router('KRIAN-OLT');
        $on = $this->customer($router, 'ko-on', ['is_online' => true]);
        $this->fakeMikrotik(count: 0, names: [], connectThrow: true);

        $result = app(MtPlanCTierService::class)->syncSessions($router, 12, false, true, true);

        $this->assertFalse($result['ok']);
        $this->assertTrue((bool) $on->fresh()->is_online);
        $this->assertNull($on->fresh()->pppoe_online_checked_at);
    }

    public function test_empty_name_list_is_never_authoritative(): void
    {
        $this->assertFalse(PppoeOnlineFlagWriter::isCompleteDump(0, 0));
        $this->assertFalse(PppoeOnlineFlagWriter::isCompleteDump(0, 50));
        $this->assertTrue(PppoeOnlineFlagWriter::isCompleteDump(6, 6));
        $this->assertTrue(PppoeOnlineFlagWriter::isCompleteDump(86, 100));
        $this->assertFalse(PppoeOnlineFlagWriter::isCompleteDump(84, 100));
        // Small pools are no longer authoritative on a partial stream.
        $this->assertFalse(PppoeOnlineFlagWriter::isCompleteDump(3, 40));

        $router = $this->router('EMPTY');
        $on = $this->customer($router, 'em-on', ['is_online' => true]);
        $this->assertSame(['online' => 0, 'offline' => 0], app(PppoeOnlineFlagWriter::class)->applyAuthoritative($router, []));
        $this->assertTrue((bool) $on->fresh()->is_online);
    }

    public function test_session_sync_refreshes_router_aggregate_counts(): void
    {
        $router = $this->router('KRIAN-OLT', ['current_online_count' => 0, 'total_pppoe_count' => 1]);
        $this->fakeMikrotik(count: 6, names: ['k1', 'k2', 'k3', 'k4', 'k5', 'k6'], secrets: 8);

        app(MtPlanCTierService::class)->syncSessions($router, 12, false, true, true);

        $router->refresh();
        $this->assertSame(6, (int) $router->current_online_count);
        $this->assertSame(8, (int) $router->total_pppoe_count);
    }

    public function test_full_sync_does_not_move_router_id_when_session_is_dual_active(): void
    {
        $krian = $this->router('Krian');
        $krianOlt = $this->router('Skynet-Krian-OLT');
        $dual = $this->customer($krian, 'dual-user', [
            'is_online' => true,
            'pppoe_online_checked_at' => Carbon::now()->subMinutes(3),
        ]);
        $stale = $this->customer($krian, 'moved-user', [
            'is_online' => false,
            'pppoe_online_checked_at' => Carbon::now()->subDays(2),
        ]);
        $this->fakeMikrotik(count: 2, names: ['dual-user', 'moved-user']);

        $mikrotik = app(MikrotikService::class);
        $mikrotik->connect($krianOlt);
        $mikrotik->syncCustomerOnlineStatus([['name' => 'dual-user'], ['name' => 'moved-user']], true);

        $this->assertSame($krian->id, (int) $dual->fresh()->router_id, 'dual-active must not flip router_id');
        $this->assertSame($krianOlt->id, (int) $stale->fresh()->router_id, 'genuine move still happens');
        $this->assertTrue((bool) $stale->fresh()->is_online);
        $this->assertNotNull($stale->fresh()->pppoe_online_checked_at);
    }

    public function test_command_kill_switch_keeps_lane_fully_read_only(): void
    {
        $router = $this->router('GADANG', ['is_active' => true]);
        $gone = $this->customer($router, 'cmd-gone', ['is_online' => true]);
        $this->fakeMikrotik(count: 1, names: ['someone-else']);

        config(['services.mt_plan_c.monitoring_write_online' => false]);
        $this->artisan('routers:mt-session-sync', ['--read-only' => true, '--write-online-flags' => true, '--timeout' => 8])
            ->assertExitCode(0);
        $this->assertTrue((bool) $gone->fresh()->is_online);

        config(['services.mt_plan_c.monitoring_write_online' => true]);
        $this->artisan('routers:mt-session-sync', ['--read-only' => true, '--write-online-flags' => true, '--timeout' => 8])
            ->assertExitCode(0);
        $this->assertFalse((bool) $gone->fresh()->is_online);
        $this->assertNotNull($gone->fresh()->pppoe_online_checked_at);
    }

    /** @param array<string, mixed> $attributes */
    private function router(string $name, array $attributes = []): Router
    {
        $this->seq++;

        return Router::create(array_merge([
            'name' => $name,
            'ip_address' => '127.0.10.'.$this->seq,
            'username' => 'test',
            'password' => Canary::of('test'),
            'is_active' => true,
        ], $attributes));
    }

    /** @param array<string, mixed> $attributes */
    private function customer(Router $router, string $pppoeUser, array $attributes = []): Customer
    {
        $this->seq++;

        return Customer::create(array_merge([
            'code' => 'PPF-'.$this->seq,
            'name' => 'PPP flag '.$this->seq,
            'phone' => '0812000'.$this->seq,
            'address' => 'NOC',
            'package_id' => $this->package->id,
            'status' => 'active',
            'router_id' => $router->id,
            'pppoe_user' => $pppoeUser,
        ], $attributes));
    }

    /** @param list<string> $names */
    private function fakeMikrotik(int $count, array $names, bool $namesThrow = false, bool $connectThrow = false, ?int $secrets = null): void
    {
        $fake = new class($count, $names, $namesThrow, $connectThrow, $secrets) extends MikrotikService
        {
            public function __construct(
                private int $count,
                private array $names,
                private bool $namesThrow,
                private bool $connectThrow,
                private ?int $secrets,
            ) {}

            public function connect(Router $router, array $options = []): MikrotikService
            {
                if ($this->connectThrow) {
                    throw new RuntimeException('socket timeout (fake)');
                }
                $this->router = $router;

                return $this;
            }

            public function getHealthStats(bool $withActiveNames = true): array
            {
                return [
                    'online_count' => $this->count,
                    'session_rows' => $this->count,
                    'multi_session_users' => 0,
                    'total_pppoe_count' => $this->secrets,
                ];
            }

            public function getActiveConnections(bool $withMeta = false): array
            {
                if ($this->namesThrow) {
                    throw new RuntimeException('stream timed out (fake)');
                }

                return array_map(fn (string $name) => ['name' => $name], $this->names);
            }

            public function disconnect(): void {}
        };

        $this->app->instance(MikrotikService::class, $fake);
        $this->app->forgetInstance(MtPlanCTierService::class);
    }
}
