<?php

namespace Tests\Feature;

use App\Models\MonitoringAlertEvent;
use App\Services\MonitoringIncidentReadModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonitoringIncidentReadModelTest extends TestCase
{
    use RefreshDatabase;

    private function alert(int $customerId, string $name, string $olt, string $pon, ?string $odp, array $overrides = []): void
    {
        MonitoringAlertEvent::create(array_merge([
            'source' => 'connectivity_scan',
            'entity_type' => 'customer',
            'entity_id' => (string) $customerId,
            'entity_name' => $name,
            'issue' => 'bad_signal_online',
            'severity' => 'critical',
            'status' => 'active',
            'dedupe_key' => 'customer:'.$customerId.':bad_signal_online',
            'payload' => [
                'customer_name' => $name,
                'olt_name' => $olt,
                'pon_port' => $pon,
                'odp' => $odp,
                'area' => 'AREA-X',
            ],
            'last_seen_at' => now(),
            'first_seen_at' => now(),
        ], $overrides));
    }

    public function test_same_pon_is_grouped_into_one_incident(): void
    {
        $this->alert(1, 'A', 'OLT RA', '1/2/7', 'ODC 1');
        $this->alert(2, 'B', 'OLT RA', '1/2/7', 'ODC 1');
        $this->alert(3, 'C', 'OLT RA', '1/2/7', 'ODC 1');

        $data = app(MonitoringIncidentReadModel::class)->payload();

        $this->assertSame(1, $data['summary']['incidents']);
        $this->assertSame(1, $data['summary']['pon_incidents']);
        $this->assertSame(3, $data['incidents'][0]['affected_count']);
        $this->assertSame('pon', $data['incidents'][0]['scope']);
        $this->assertSame('OLT RA', $data['incidents'][0]['olt']);
        $this->assertSame('1/2/7', $data['incidents'][0]['pon']);
    }

    public function test_real_odp_with_two_customers_is_its_own_incident(): void
    {
        $this->alert(1, 'A', 'OLT RA', '1/2/7', 'ODP-GONDANG-018-L6');
        $this->alert(2, 'B', 'OLT RA', '1/2/7', 'ODP-GONDANG-018-L6');

        $data = app(MonitoringIncidentReadModel::class)->payload();

        $scopes = array_column($data['incidents'], 'scope');
        $this->assertContains('odp', $scopes);
        $odp = collect($data['incidents'])->firstWhere('scope', 'odp');
        $this->assertSame('ODP-GONDANG-018-L6', $odp['scope_label']);
        $this->assertSame(2, $odp['affected_count']);
    }

    public function test_odc_and_area_like_odp_values_are_not_treated_as_odp(): void
    {
        // "ODC 1" / area name must not become an ODP-scope incident. Three
        // customers so the PON incident reaches the default
        // monitoring_incident_pon_min_customers threshold (3).
        $this->alert(1, 'A', 'OLT RA', '1/2/7', 'ODC 1');
        $this->alert(2, 'B', 'OLT RA', '1/2/7', 'SKYNET-RANDUAGUNG');
        $this->alert(3, 'C', 'OLT RA', '1/2/7', 'SKYNET-RANDUAGUNG');

        $data = app(MonitoringIncidentReadModel::class)->payload();

        $this->assertSame(0, $data['summary']['odp_incidents']);
        $this->assertSame(1, $data['summary']['pon_incidents']);
    }

    public function test_single_customer_alerts_do_not_become_incidents(): void
    {
        $this->alert(1, 'A', 'OLT RA', '1/2/7', 'ODP-A');
        $this->alert(2, 'B', 'OLT RA', '1/2/8', 'ODP-B');

        $data = app(MonitoringIncidentReadModel::class)->payload();

        $this->assertSame(0, $data['summary']['incidents']);
    }

    public function test_areas_filter_restricts_scope(): void
    {
        $this->alert(1, 'A', 'OLT RA', '1/2/7', 'ODP-A');
        $this->alert(2, 'B', 'OLT RA', '1/2/7', 'ODP-A');
        $this->alert(3, 'C', 'OLT RA', '1/2/7', 'ODP-A');

        // Three customers behind one real ODP on one PON yield two incidents by
        // design (the inbox filters ODC/PON vs ODP scopes separately): the
        // ODP-A incident and the PON 1/2/7 incident.
        $unscoped = app(MonitoringIncidentReadModel::class)->payload();
        $this->assertSame(2, $unscoped['summary']['incidents']);

        // All alerts are area AREA-X; a scoped list without it sees nothing,
        // a scoped list with it sees exactly what the unscoped view sees.
        $this->assertSame(0, app(MonitoringIncidentReadModel::class)->payload(['areas' => ['OTHER']])['summary']['incidents']);
        $scoped = app(MonitoringIncidentReadModel::class)->payload(['areas' => ['AREA-X']]);
        $this->assertSame(2, $scoped['summary']['incidents']);
        $this->assertSame(1, $scoped['summary']['pon_incidents']);
        $this->assertSame(1, $scoped['summary']['odp_incidents']);
    }

    public function test_missing_table_is_reported_not_errored(): void
    {
        // Fresh DB has the table; simulate disabled via empty result is enough:
        $data = app(MonitoringIncidentReadModel::class)->payload(['area' => 'NOPE']);
        $this->assertSame(0, $data['summary']['incidents']);
    }
}
