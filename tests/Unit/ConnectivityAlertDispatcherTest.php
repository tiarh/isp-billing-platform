<?php

namespace Tests\Unit;

use App\Models\Area;
use App\Models\Customer;
use App\Models\MonitoringAlertEvent;
use App\Models\Olt;
use App\Models\OltOnu;
use App\Models\Package;
use App\Services\ConnectivityAlertDispatcher;
use App\Services\EvolutionApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ConnectivityAlertDispatcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_builds_customer_and_odp_messages(): void
    {
        $dispatcher = app(ConnectivityAlertDispatcher::class);

        $event = MonitoringAlertEvent::create([
            'source' => 'connectivity_scan',
            'entity_type' => 'customer',
            'entity_id' => '1',
            'entity_name' => 'DARMAWAN (KLRJ097)',
            'issue' => 'pon_pppoe_mismatch',
            'severity' => 'warning',
            'status' => 'active',
            'dedupe_key' => 'customer:1:pon_pppoe_mismatch',
            'payload' => [
                'customer_code' => 'KLRJ097',
                'customer_name' => 'DARMAWAN',
                'odp' => 'ODC PON 1',
                'pon_port' => '1/2/5',
                'olt_port_label' => '1/2/5:7',
                'onu_serial' => 'ZTEGC123',
                'pppoe_online' => true,
                'olt_status' => 'offline',
            ],
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $customerMsg = $dispatcher->buildCustomerMessage($event);
        $this->assertStringContainsString('PON offline · PPPoE aktif', $customerMsg);
        $this->assertStringContainsString('DARMAWAN', $customerMsg);
        $this->assertStringContainsString('ODC PON 1', $customerMsg);

        $odpMsg = $dispatcher->buildOdpMessage('ODC PON 1|1/2/5|pon_pppoe_mismatch', collect([$event]));
        $this->assertStringContainsString('[Connectivity ODP]', $odpMsg);
        $this->assertStringContainsString('KLRJ097', $odpMsg);
    }

    public function test_dispatch_odp_only_sends_single_digest_without_customer_spam(): void
    {
        config(['services.connectivity_alerts.group_id' => 'test-ops-group']);
        config(['services.connectivity_alerts.notify_mode' => 'odp_only']);

        $olt = Olt::create(['name' => 'Alert OLT', 'code' => 'ALERT-OLT', 'vendor' => 'zte_c300']);
        $package = Package::create(['name' => '10M', 'code' => 'PKG-WA-2', 'price' => 55000]);
        $customer = Customer::create([
            'code' => 'KLRJ099',
            'name' => 'BUDI',
            'address' => 'Alert Address',
            'pppoe_user' => 'klrj099.alert',
            'package_id' => $package->id,
            'status' => 'active',
            'is_online' => true,
            'olt_status' => 'offline',
            'olt_id' => $olt->id,
            'olt_port_label' => '1/3/11:1',
            'onu_serial' => 'FHTT-ALERT-1',
        ]);
        OltOnu::create([
            'olt_id' => $olt->id,
            'onu_ref' => '1/3/11:1',
            'serial_number' => 'FHTT-ALERT-1',
            'state' => 'offline',
            'state_collected_at' => now(),
        ]);

        $event = MonitoringAlertEvent::create([
            'source' => 'connectivity_scan',
            'entity_type' => 'customer',
            'entity_id' => (string) $customer->id,
            'entity_name' => 'BUDI (KLRJ099)',
            'issue' => 'pon_pppoe_mismatch',
            'severity' => 'warning',
            'status' => 'active',
            'dedupe_key' => 'customer:'.$customer->id.':pon_pppoe_mismatch',
            'payload' => [
                'customer_code' => 'KLRJ099',
                'customer_name' => 'BUDI',
                'odp' => 'SKYNET-KALIREJO',
                'pon_port' => '1/3/11',
                'pppoe_online' => true,
                'olt_status' => 'offline',
            ],
            'first_seen_at' => now()->subMinutes(20),
            'last_seen_at' => now(),
        ]);

        $evolution = Mockery::mock(EvolutionApiService::class);
        $evolution->shouldReceive('forChannel')->once()->with('ops')->andReturnSelf();
        $evolution->shouldReceive('isConfigured')->andReturn(true);
        $evolution->shouldReceive('sendToGroup')->once()->andReturn(['success' => true, 'message_id' => 'x']);

        $stats = app(ConnectivityAlertDispatcher::class)->dispatch(collect([$event]), $evolution, dryRun: false);

        $this->assertSame(0, $stats['customer_sent']);
        $this->assertSame(1, $stats['odp_sent']);

        $event->refresh();
        $this->assertNull($event->payload['wa_customer_sent_at'] ?? null);
        $this->assertNotNull($event->payload['wa_odp_sent_at'] ?? null);
    }

    public function test_dispatch_marks_customer_and_odp_payload_flags_when_notify_mode_both(): void
    {
        config(['services.connectivity_alerts.group_id' => 'test-ops-group']);
        config(['services.connectivity_alerts.notify_mode' => 'both']);

        $olt = Olt::create(['name' => 'Alert OLT', 'code' => 'ALERT-OLT', 'vendor' => 'zte_c300']);
        $package = Package::create(['name' => '10M', 'code' => 'PKG-WA-3', 'price' => 55000]);
        $customer = Customer::create([
            'code' => 'RDG033',
            'name' => 'SUSI',
            'address' => 'Alert Address',
            'pppoe_user' => 'rdg033.alert',
            'package_id' => $package->id,
            'status' => 'active',
            'is_online' => true,
            'olt_status' => 'offline',
            'olt_id' => $olt->id,
            'olt_port_label' => '1/3/11:1',
            'onu_serial' => 'FHTT-ALERT-2',
        ]);
        OltOnu::create([
            'olt_id' => $olt->id,
            'onu_ref' => '1/3/11:1',
            'serial_number' => 'FHTT-ALERT-2',
            'state' => 'offline',
            'state_collected_at' => now(),
        ]);

        $event = MonitoringAlertEvent::create([
            'source' => 'connectivity_scan',
            'entity_type' => 'customer',
            'entity_id' => (string) $customer->id,
            'entity_name' => 'SUSI (RDG033)',
            'issue' => 'bad_signal_online',
            'severity' => 'critical',
            'status' => 'active',
            'dedupe_key' => 'customer:2:bad_signal_online',
            'payload' => [
                'customer_code' => 'RDG033',
                'customer_name' => 'SUSI',
                'odp' => 'ODC',
                'pon_port' => '1/2/6',
                'onu_rx_power_dbm' => -31.5,
            ],
            'first_seen_at' => now()->subMinutes(10),
            'last_seen_at' => now(),
        ]);

        $evolution = Mockery::mock(EvolutionApiService::class);
        $evolution->shouldReceive('forChannel')->twice()->with('ops')->andReturnSelf();
        $evolution->shouldReceive('isConfigured')->andReturn(true);
        $evolution->shouldReceive('sendToGroup')->twice()->andReturn(['success' => true, 'message_id' => 'x']);

        $dispatcher = app(ConnectivityAlertDispatcher::class);
        $stats = $dispatcher->dispatch(collect([$event]), $evolution, dryRun: false);

        $this->assertSame(1, $stats['customer_sent']);
        $this->assertSame(1, $stats['odp_sent']);

        $event->refresh();
        $this->assertNotNull($event->payload['wa_customer_sent_at'] ?? null);
        $this->assertNotNull($event->payload['wa_odp_sent_at'] ?? null);
        $this->assertNotNull($event->last_sent_at);
    }

    public function test_dispatch_skips_migrated_customer_with_stale_ciot_serial(): void
    {
        $package = Package::create(['name' => '10M', 'code' => 'PKG-WA-1', 'price' => 55000]);
        $area = Area::create(['code' => 'A', 'name' => 'Area']);
        $olt = Olt::create(['name' => 'OLT RA', 'code' => 'OLT-RA', 'vendor' => 'zte_c300']);

        $customer = Customer::create([
            'code' => 'RDG9999',
            'name' => 'MIGRATED USER',
            'phone' => '080000000099',
            'address' => 'Addr',
            'pppoe_user' => 'USERBD_RT2_RW4_5',
            'package_id' => $package->id,
            'area_id' => $area->id,
            'status' => 'active',
            'is_online' => true,
            'olt_id' => $olt->id,
            'olt_port_label' => '1/3/13:1',
            'onu_serial' => 'CIOT13229930',
            'olt_status' => 'offline',
        ]);

        OltOnu::create([
            'olt_id' => $olt->id,
            'onu_ref' => '1/3/13:2',
            'serial_number' => 'FHTT0000A033',
            'onu_name' => 'USERBD_RT2_RW4_5',
            'state' => 'online',
            'state_collected_at' => now(),
        ]);

        $event = MonitoringAlertEvent::create([
            'source' => 'connectivity_scan',
            'entity_type' => 'customer',
            'entity_id' => (string) $customer->id,
            'entity_name' => 'MIGRATED USER (RDG9999)',
            'issue' => 'pon_pppoe_mismatch',
            'severity' => 'warning',
            'status' => 'active',
            'dedupe_key' => 'customer:'.$customer->id.':pon_pppoe_mismatch',
            'payload' => [
                'customer_code' => 'RDG9999',
                'onu_serial' => 'CIOT13229930',
                'odp' => 'ODC',
                'pon_port' => '1/3/13',
            ],
            'first_seen_at' => now()->subMinutes(20),
            'last_seen_at' => now(),
        ]);

        $evolution = Mockery::mock(EvolutionApiService::class);
        $evolution->shouldReceive('isConfigured')->andReturn(true);
        $evolution->shouldReceive('sendToGroup')->never();

        $dispatcher = app(ConnectivityAlertDispatcher::class);
        $stats = $dispatcher->dispatch(collect([$event]), $evolution, dryRun: false);

        $this->assertSame(0, $stats['customer_sent']);
        $this->assertSame(0, $stats['odp_sent']);
        $this->assertSame('recovered', $event->fresh()->status);
        $customer->refresh();
        $this->assertSame('FHTT0000A033', $customer->onu_serial);
    }

    public function test_customer_alert_repeats_after_repeat_window(): void
    {
        config(['services.connectivity_alerts.group_id' => 'test-ops-group']);
        config(['services.connectivity_alerts.notify_mode' => 'both']);

        $olt = Olt::create(['name' => 'Alert OLT', 'code' => 'ALERT-OLT', 'vendor' => 'zte_c300']);
        $package = Package::create(['name' => '10M', 'code' => 'PKG-WA-4', 'price' => 55000]);
        $customer = Customer::create([
            'code' => 'RDG001',
            'name' => 'REPEAT',
            'address' => 'Alert Address',
            'pppoe_user' => 'rdg001.alert',
            'package_id' => $package->id,
            'status' => 'active',
            'is_online' => true,
            'olt_status' => 'offline',
            'olt_id' => $olt->id,
            'olt_port_label' => '1/3/11:1',
            'onu_serial' => 'FHTT-ALERT-3',
        ]);
        OltOnu::create([
            'olt_id' => $olt->id,
            'onu_ref' => '1/3/11:1',
            'serial_number' => 'FHTT-ALERT-3',
            'state' => 'offline',
            'state_collected_at' => now(),
        ]);

        $event = MonitoringAlertEvent::create([
            'source' => 'connectivity_scan',
            'entity_type' => 'customer',
            'entity_id' => (string) $customer->id,
            'entity_name' => 'REPEAT (RDG001)',
            'issue' => 'pon_pppoe_mismatch',
            'severity' => 'warning',
            'status' => 'active',
            'dedupe_key' => 'customer:3:pon_pppoe_mismatch',
            'payload' => [
                'customer_code' => 'RDG001',
                'customer_name' => 'REPEAT',
                'odp' => 'ODC',
                'pon_port' => '1/2/1',
                'wa_customer_sent_at' => now()->subMinutes(121)->toISOString(),
            ],
            'first_seen_at' => now()->subHours(2),
            'last_seen_at' => now(),
            'last_sent_at' => now()->subMinutes(121),
        ]);

        $evolution = Mockery::mock(EvolutionApiService::class);
        $evolution->shouldReceive('forChannel')->twice()->with('ops')->andReturnSelf();
        $evolution->shouldReceive('isConfigured')->andReturn(true);
        $evolution->shouldReceive('sendToGroup')->twice()->andReturn(['success' => true]);

        $dispatcher = app(ConnectivityAlertDispatcher::class);
        $stats = $dispatcher->dispatch(collect([$event]), $evolution, dryRun: false);

        $this->assertSame(1, $stats['customer_sent']);
    }
}
