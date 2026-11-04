<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\NocMonitoringReadModel;
use App\Services\UnifiedConnectivityReadModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Canary;
use Tests\TestCase;

/**
 * Disposable capacity evidence for MON-004. Run explicitly; keep it out of
 * the normal regression gate because it intentionally creates 20k rows.
 */
class Mon004CapacityBenchmarkTest extends TestCase
{
    use RefreshDatabase;

    #[Group('mon004-capacity')]
    public function test_read_models_handle_twenty_thousand_customers_and_one_thousand_alerts(): void
    {
        Setting::set('monitoring_unified_read_model_v2', true, 'boolean', 'monitoring');
        Setting::set('monitoring_noc_cockpit_enabled', true, 'boolean', 'monitoring');
        Setting::set('monitoring_incident_correlation_enabled', true, 'boolean', 'monitoring');

        $packageId = DB::table('packages')->insertGetId([
            'code' => 'MON004-BENCH',
            'name' => 'MON004 benchmark',
            'price' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $routerRows = array_map(static fn (int $id): array => [
            'name' => 'MON004 Router '.$id,
            'ip_address' => '192.0.2.'.($id + 10),
            'port' => 8728,
            'username' => 'benchmark',
            'password' => Canary::of('bench'),
            'is_active' => true,
            'connection_status' => 'online',
            'created_at' => now(),
            'updated_at' => now(),
        ], range(1, 10));
        DB::table('routers')->insert($routerRows);
        $routerIds = DB::table('routers')->where('name', 'like', 'MON004 Router %')->pluck('id')->values();

        DB::table('olts')->insert(array_map(static fn (int $id): array => [
            'name' => 'MON004 OLT '.$id,
            'code' => 'MON004-OLT-'.$id,
            'router_id' => $routerIds[$id - 1],
            'management_ip' => '198.51.100.'.($id + 10),
            'location' => 'benchmark',
            'created_at' => now(),
            'updated_at' => now(),
        ], range(1, 10)));

        DB::table('support_tickets')->insert(array_map(static fn (int $id): array => [
            'code' => 'MON004-TICKET-'.$id,
            'type' => 'incident',
            'priority' => 'normal',
            'status' => 'open',
            'subject' => 'MON004 ticket '.$id,
            'meta' => json_encode(['benchmark' => true]),
            'created_at' => now(),
            'updated_at' => now(),
        ], range(1, 100)));

        $now = now();
        foreach (array_chunk(array_map(static fn (int $id): array => [
            'code' => 'MON004-'.$id,
            'name' => 'MON004 Customer '.$id,
            'phone' => '080000'.str_pad((string) $id, 6, '0', STR_PAD_LEFT),
            'address' => 'benchmark',
            'package_id' => $packageId,
            'pppoe_user' => 'mon004-'.$id,
            'status' => 'active',
            'is_online' => true,
            'olt_status' => 'working',
            'mikrotik_sync_checked_at' => $now,
            'olt_last_synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ], range(1, 20000)), 1000) as $chunk) {
            DB::table('customers')->insert($chunk);
        }

        foreach (array_chunk(array_map(static fn (int $id): array => [
            'source' => 'connectivity_scan',
            'entity_type' => 'customer',
            'entity_id' => 'bench-'.$id,
            'entity_name' => 'MON004 Customer '.$id,
            'issue' => 'offline',
            'severity' => 'warning',
            'status' => 'active',
            'dedupe_key' => 'mon004-bench-'.$id,
            'payload' => json_encode(['router' => 'bench-router-'.($id % 10), 'pon_port' => '1/1/'.($id % 32)]),
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ], range(1, 1000)), 250) as $chunk) {
            DB::table('monitoring_alert_events')->insert($chunk);
        }

        $started = microtime(true);
        $connectivity = app(UnifiedConnectivityReadModel::class)->payload(Request::create('/monitoring'));
        $connectivitySeconds = microtime(true) - $started;

        $started = microtime(true);
        $noc = app(NocMonitoringReadModel::class)->payload(Request::create('/monitoring', 'GET', [
            'noc_page' => 1,
            'noc_per_page' => 50,
        ]));
        $nocSeconds = microtime(true) - $started;

        $this->assertSame(20000, $connectivity['total_rows']);
        $this->assertCount(50, $connectivity['rows']);
        $this->assertSame(10, DB::table('routers')->where('name', 'like', 'MON004 Router %')->count());
        $this->assertSame(10, DB::table('olts')->where('code', 'like', 'MON004-OLT-%')->count());
        $this->assertSame(100, DB::table('support_tickets')->where('code', 'like', 'MON004-TICKET-%')->count());
        $this->assertSame(1000, $noc['summary']['affected_customers']);
        $this->assertGreaterThan(0, $noc['summary']['active_incidents']);
        $this->assertCount(50, $noc['incidents']);

        fwrite(STDERR, sprintf(
            "MON-004 benchmark: customers=20000 alerts=1000 connectivity=%.3fs noc=%.3fs memory=%dMB\n",
            $connectivitySeconds,
            $nocSeconds,
            (int) round(memory_get_peak_usage(true) / 1048576),
        ));
    }
}
