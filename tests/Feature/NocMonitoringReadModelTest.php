<?php

namespace Tests\Feature;

use App\Jobs\RefreshMonitoringCacheJob;
use App\Models\Customer;
use App\Models\MonitoringAlertEvent;
use App\Models\Package;
use App\Models\Router;
use App\Models\Setting;
use App\Models\SupportTicket;
use App\Services\NocMonitoringReadModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Tests\Support\Canary;
use Tests\TestCase;

class NocMonitoringReadModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_same_pon_events_are_grouped_as_one_read_only_incident(): void
    {
        Setting::set('monitoring_noc_cockpit_enabled', true, 'boolean', 'monitoring');
        Setting::set('monitoring_incident_correlation_enabled', true, 'boolean', 'monitoring');

        foreach (['A', 'B'] as $suffix) {
            MonitoringAlertEvent::create([
                'source' => 'connectivity_scan',
                'entity_type' => 'customer',
                'entity_id' => 'noc-'.$suffix,
                'entity_name' => 'Customer '.$suffix,
                'issue' => 'pon_pppoe_mismatch',
                'severity' => 'warning',
                'status' => 'active',
                'dedupe_key' => 'noc-test-'.$suffix,
                'payload' => [
                    'router' => 'RA',
                    'olt_name' => 'OLT RA',
                    'pon_port' => '1/2/3',
                    'odp' => 'ODP-TEST',
                ],
                'first_seen_at' => now()->subMinutes(10),
                'last_seen_at' => now(),
            ]);
        }

        $payload = app(NocMonitoringReadModel::class)->payload();

        $this->assertTrue($payload['read_only']);
        $this->assertArrayHasKey('queue_status', $payload);
        $this->assertArrayHasKey('monitoring-evaluate', $payload['queue_status']['queues']);
        $this->assertTrue($payload['queue_status']['queues']['monitoring']['consumer_active']);
        $this->assertFalse($payload['queue_status']['queues']['migration-lookup']['consumer_active']);
        $this->assertFalse($payload['queue_status']['queues']['monitoring-evaluate']['consumer_active']);
        $this->assertSame(1, $payload['summary']['active_incidents']);
        $this->assertSame(2, $payload['incidents'][0]['affected_count']);
        $this->assertSame('1/2/3', $payload['incidents'][0]['pon']);
    }

    public function test_shadow_compare_is_bounded_and_read_only(): void
    {
        Setting::set('monitoring_noc_cockpit_enabled', true, 'boolean', 'monitoring');
        $package = Package::create(['code' => 'NOC-SHADOW', 'name' => 'NOC Shadow', 'price' => 0]);
        Customer::create([
            'code' => 'NOC-SHADOW-001',
            'name' => 'NOC Shadow Customer',
            'phone' => '0800000000',
            'address' => 'NOC',
            'package_id' => $package->id,
            'pppoe_user' => 'shadow-test-user',
            'status' => 'active',
        ]);

        $exit = Artisan::call('monitoring:shadow-compare', ['--limit' => 0, '--json' => true]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertSame(0, $payload['limit']);
        $this->assertSame(1, $payload['sample_size']);
        $this->assertSame(['setup_required' => 1], $payload['unified']);
        $this->assertTrue($payload['read_only']);
        $this->assertFalse($payload['device_io']);
        $this->assertFalse($payload['writes']);
        $this->assertArrayHasKey('transition_breakdown', $payload);
        $this->assertArrayHasKey('review_candidates', $payload);
    }

    public function test_monitoring_cache_job_falls_back_when_lane_is_not_consumed(): void
    {
        Config::set('services.monitoring.queue_decomposition_enabled', true);
        Config::set('services.monitoring.queue_consumers', ['psb', 'migration-live', 'monitoring']);

        $job = new RefreshMonitoringCacheJob;

        $this->assertSame('monitoring', $job->queue);
    }

    public function test_monitoring_cache_job_can_select_evaluate_lane_only_when_explicitly_consumed(): void
    {
        Config::set('services.monitoring.queue_decomposition_enabled', true);
        Config::set('services.monitoring.queue_consumers', ['monitoring', 'monitoring-evaluate']);

        $job = new RefreshMonitoringCacheJob;

        $this->assertSame('monitoring-evaluate', $job->queue);
    }

    public function test_isolated_customer_event_is_excluded_from_noc_inbox(): void
    {
        Setting::set('monitoring_noc_cockpit_enabled', true, 'boolean', 'monitoring');
        Setting::set('monitoring_incident_correlation_enabled', true, 'boolean', 'monitoring');

        $package = Package::create(['code' => 'NOC-ISO', 'name' => 'NOC Isolation', 'price' => 0]);
        $customer = Customer::create([
            'code' => 'NOC-ISO-001',
            'name' => 'Isolated NOC Customer',
            'phone' => '0800000000',
            'address' => 'NOC',
            'package_id' => $package->id,
            'pppoe_user' => 'noc-iso-001',
            'status' => 'isolated',
        ]);

        MonitoringAlertEvent::create([
            'source' => 'connectivity_scan',
            'entity_type' => 'customer',
            'entity_id' => (string) $customer->id,
            'entity_name' => $customer->name,
            'issue' => 'pon_pppoe_mismatch',
            'severity' => 'critical',
            'status' => 'active',
            'dedupe_key' => 'isolated-test-'.$customer->id,
            'payload' => ['pon_port' => '1/1/1'],
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $payload = app(NocMonitoringReadModel::class)->payload();

        $this->assertSame(0, $payload['summary']['active_incidents']);
        $this->assertSame([], $payload['incidents']);
    }

    public function test_twenty_customer_events_become_one_p1_parent(): void
    {
        Setting::set('monitoring_noc_cockpit_enabled', true, 'boolean', 'monitoring');
        Setting::set('monitoring_incident_correlation_enabled', true, 'boolean', 'monitoring');

        foreach (range(1, 20) as $id) {
            MonitoringAlertEvent::create([
                'source' => 'connectivity_scan',
                'entity_type' => 'customer',
                'entity_id' => 'p1-'.$id,
                'entity_name' => 'P1 Customer '.$id,
                'issue' => 'pon_pppoe_mismatch',
                'severity' => 'warning',
                'status' => 'active',
                'dedupe_key' => 'p1-test-'.$id,
                'payload' => ['router' => 'RA', 'pon_port' => '1/2/9'],
                'first_seen_at' => now()->subMinutes(5),
                'last_seen_at' => now(),
            ]);
        }

        $payload = app(NocMonitoringReadModel::class)->payload();

        $this->assertSame(1, $payload['summary']['active_incidents']);
        $this->assertSame(1, $payload['summary']['p1']);
        $this->assertSame('P1', $payload['incidents'][0]['priority']);
        $this->assertSame(20, $payload['incidents'][0]['affected_count']);
    }

    public function test_active_ticket_is_linked_read_only_by_monitoring_dedupe_key(): void
    {
        Setting::set('monitoring_noc_cockpit_enabled', true, 'boolean', 'monitoring');
        Setting::set('monitoring_incident_correlation_enabled', true, 'boolean', 'monitoring');
        $ticket = SupportTicket::create([
            'code' => 'NOC-TICKET-001',
            'type' => 'incident',
            'priority' => 'high',
            'status' => 'open',
            'subject' => 'NOC test incident',
            'meta' => ['monitoring_alert_dedupe_key' => 'noc-ticket-key'],
        ]);
        MonitoringAlertEvent::create([
            'source' => 'connectivity_scan',
            'entity_type' => 'customer',
            'entity_id' => 'ticket-customer',
            'entity_name' => 'Ticket Customer',
            'issue' => 'pon_pppoe_mismatch',
            'severity' => 'warning',
            'status' => 'active',
            'dedupe_key' => 'noc-ticket-key',
            'payload' => ['router' => 'RA', 'pon_port' => '1/2/4'],
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $incident = app(NocMonitoringReadModel::class)->payload()['incidents'][0];

        $this->assertSame($ticket->id, $incident['ticket_id']);
        $this->assertSame('open', $incident['ticket_status']);
    }

    public function test_coverage_reports_mapping_gaps_and_does_not_hide_customers(): void
    {
        Setting::set('monitoring_noc_cockpit_enabled', true, 'boolean', 'monitoring');
        Setting::set('monitoring_incident_correlation_enabled', false, 'boolean', 'monitoring');
        $package = Package::create(['code' => 'NOC-COVERAGE', 'name' => 'NOC Coverage', 'price' => 0]);
        $router = Router::create([
            'name' => 'NOC Coverage Router',
            'ip_address' => '192.0.2.10',
            'username' => 'test',
            'password' => Canary::of('test'),
            'is_active' => true,
        ]);

        Customer::create([
            'code' => 'NOC-COVERAGE-PPPOE',
            'name' => 'Mapped PPPoE',
            'phone' => '0800000001',
            'address' => 'NOC',
            'package_id' => $package->id,
            'pppoe_user' => 'noc-coverage-pppoe',
            'router_id' => $router->id,
            'onu_serial' => 'FHTTNOC0001',
            'status' => 'active',
        ]);
        Customer::create([
            'code' => 'NOC-COVERAGE-UNKNOWN',
            'name' => 'Needs Mapping',
            'phone' => '0800000002',
            'address' => 'NOC',
            'package_id' => $package->id,
            'pppoe_user' => 'noc-coverage-unknown',
            'status' => 'active',
        ]);
        Customer::create([
            'code' => 'NOC-COVERAGE-ISOLATED',
            'name' => 'Isolated',
            'phone' => '0800000003',
            'address' => 'NOC',
            'package_id' => $package->id,
            'pppoe_user' => 'noc-coverage-isolated',
            'status' => 'isolated',
        ]);

        $coverage = app(NocMonitoringReadModel::class)->payload()['coverage'];

        $this->assertSame(3, $coverage['total']);
        $this->assertSame(1, $coverage['isolated']);
        $this->assertSame(2, $coverage['needs_mapping']);
        $this->assertSame(3, array_sum($coverage['strategies']));
    }

    public function test_source_health_distinguishes_fresh_collector_from_stale_customer_projection(): void
    {
        Setting::set('monitoring_noc_cockpit_enabled', true, 'boolean', 'monitoring');
        Setting::set('monitoring_incident_correlation_enabled', false, 'boolean', 'monitoring');
        $package = Package::create(['code' => 'NOC-FRESHNESS', 'name' => 'NOC Freshness', 'price' => 0]);
        Router::create([
            'name' => 'NOC Fresh Router',
            'ip_address' => '192.0.2.20',
            'username' => 'test',
            'password' => Canary::of('test'),
            'is_active' => true,
            'last_health_check_at' => now(),
            'last_sync_stats' => ['success' => true, 'duration_ms' => 12],
        ]);
        Customer::create([
            'code' => 'NOC-FRESHNESS-001',
            'name' => 'Stale Projection',
            'phone' => '0800000020',
            'address' => 'NOC',
            'package_id' => $package->id,
            'pppoe_user' => 'stale-projection',
            'status' => 'active',
            'mikrotik_sync_checked_at' => now()->subHours(2),
        ]);

        $source = collect(app(NocMonitoringReadModel::class)->payload()['sources'])
            ->firstWhere('key', 'mikrotik_pppoe');

        $this->assertSame('stale', $source['status']);
        $this->assertSame('healthy', $source['collector_status']);
        $this->assertTrue($source['collector_fresh']);
        $this->assertSame('Collector sehat; timestamp projection pelanggan masih stale.', $source['freshness_note']);
    }

    public function test_sources_and_coverage_no_longer_report_the_removed_host_ping_source(): void
    {
        Setting::set('monitoring_noc_cockpit_enabled', true, 'boolean', 'monitoring');
        Setting::set('monitoring_incident_correlation_enabled', false, 'boolean', 'monitoring');

        $payload = app(NocMonitoringReadModel::class)->payload();
        $keys = collect($payload['sources'])->pluck('key')->all();

        $this->assertContains('mikrotik_pppoe', $keys);
        $this->assertContains('genieacs', $keys);
        $this->assertNotContains('host_monitoring_ping', $keys);
        $this->assertArrayNotHasKey('legacy_ping', $payload['coverage']['strategies']);
        $this->assertSame(
            ['fiberhome_tr069', 'pppoe_only', 'hioso_htb', 'manual', 'unknown'],
            array_keys($payload['coverage']['strategies']),
        );
    }

    public function test_incidents_are_grouped_before_bounded_server_pagination(): void
    {
        Setting::set('monitoring_noc_cockpit_enabled', true, 'boolean', 'monitoring');
        Setting::set('monitoring_incident_correlation_enabled', true, 'boolean', 'monitoring');

        foreach (range(1, 12) as $number) {
            MonitoringAlertEvent::create([
                'source' => 'connectivity_scan',
                'entity_type' => 'customer',
                'entity_id' => 'noc-page-'.$number,
                'entity_name' => 'Page Customer '.$number,
                'issue' => 'offline',
                'severity' => 'warning',
                'status' => 'active',
                'dedupe_key' => 'noc-page-key-'.$number,
                'payload' => ['incident_key' => 'router|pon|'.$number],
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ]);
        }

        $payload = app(NocMonitoringReadModel::class)->payload(Request::create('/monitoring', 'GET', [
            'noc_page' => 2,
            'noc_per_page' => 10,
        ]));

        $this->assertCount(2, $payload['incidents']);
        $this->assertSame(2, $payload['incident_pagination']['page']);
        $this->assertSame(2, $payload['incident_pagination']['last_page']);
        $this->assertSame(12, $payload['incident_pagination']['total']);
    }
}
