<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Customer;
use App\Models\MonitoringAlertEvent;
use App\Models\Package;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\TicketMonitoringEvidenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-core')]
class TicketMonitoringEvidenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_monitoring_candidate_approval_creates_pending_draft_once(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = $this->customer();
        $event = $this->event($customer);

        $this->actingAs($admin)
            ->post(route('tickets.monitoring-candidates.draft', $event))
            ->assertRedirect();

        $ticket = SupportTicket::query()->firstOrFail();
        $this->assertSame('pending', $ticket->status);
        $this->assertSame('incident', $ticket->type);
        $this->assertSame($event->dedupe_key, $ticket->meta['monitoring_alert_dedupe_key']);

        $this->actingAs($admin)
            ->post(route('tickets.monitoring-candidates.draft', $event))
            ->assertRedirect(route('tickets.show', $ticket));

        $this->assertSame(1, SupportTicket::count());
    }

    public function test_ticket_pages_read_monitoring_evidence_from_snapshot(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = $this->customer();
        $event = $this->event($customer);
        $ticket = SupportTicket::create([
            'customer_id' => $customer->id,
            'gangguan' => 'Gangguan dari monitoring',
            'meta' => ['monitoring_alert_dedupe_key' => $event->dedupe_key],
        ]);

        // Candidates with an active ticket are hidden by default.
        $this->actingAs($admin)
            ->get(route('tickets.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Tickets/Index')
                ->missing('workSignals')
                ->where('monitoringSummary.ticketed', 1)
                ->where('monitoringSummary.total', 0)
                ->missing('monitoringEvidence')
                ->reloadOnly('monitoringEvidence', fn ($reload) => $reload
                    ->has('monitoringEvidence.candidates', 0)));

        $this->actingAs($admin)
            ->get(route('tickets.index', ['monitoring_show_ticketed' => '1']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->reloadOnly('monitoringEvidence', fn ($reload) => $reload
                    ->where('monitoringEvidence.candidates.0.dedupe_key', $event->dedupe_key)
                    ->where('monitoringEvidence.candidates.0.existing_ticket_id', $ticket->id)));

        $this->actingAs($admin)
            ->get(route('tickets.show', $ticket))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Tickets/Show')
                ->where('monitoringEvidence.0.dedupe_key', $event->dedupe_key)
                ->where('monitoringEvidence.0.issue_label', 'PON offline - PPPoE aktif'));
    }

    public function test_summary_payload_counts_issues_massal_and_ticketed(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customers = $this->customers(5);
        foreach ($customers as $customer) {
            $this->event($customer, ['payload' => ['odp' => 'ODP-A', 'pon_port' => '1/1/2']]);
        }
        $this->event($customers[0], ['issue' => 'bad_signal_online', 'severity' => 'critical']);
        SupportTicket::create([
            'customer_id' => $customers[1]->id,
            'gangguan' => 'Tiket aktif',
            'status' => 'open',
            'meta' => ['monitoring_alert_dedupe_key' => 'customer:'.$customers[1]->id.':pon_pppoe_mismatch'],
        ]);

        $summary = app(TicketMonitoringEvidenceService::class)->summary($admin);

        $this->assertSame(['pon_pppoe_mismatch' => 4, 'bad_signal_online' => 1], $summary['by_issue']);
        $this->assertSame(5, $summary['total']);
        $this->assertSame(1, $summary['ticketed']);
        $this->assertSame(1, $summary['massal']);
        $this->assertFalse($summary['stale']);
    }

    public function test_pon_offline_is_counted_and_listed_when_weak_signal_events_are_numerous(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = $this->customer();
        $this->event($customer);

        $rows = [];
        for ($i = 1; $i <= 1100; $i++) {
            $rows[] = [
                'source' => 'connectivity_scan',
                'entity_type' => 'customer',
                'entity_id' => (string) (900000 + $i),
                'entity_name' => 'Redaman '.$i,
                'issue' => 'bad_signal_online',
                'severity' => 'critical',
                'status' => 'active',
                'dedupe_key' => 'customer:'.(900000 + $i).':bad_signal_online',
                'payload' => json_encode(['customer_name' => 'Redaman '.$i, 'odp' => 'ODP-'.($i % 300), 'pon_port' => '1/1/1']),
                'first_seen_at' => now()->subMinutes(10),
                'last_seen_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        foreach (array_chunk($rows, 300) as $chunk) {
            MonitoringAlertEvent::query()->insert($chunk);
        }
        $service = app(TicketMonitoringEvidenceService::class);

        $summary = $service->summary($admin);
        $this->assertSame(1, $summary['by_issue']['pon_pppoe_mismatch']);
        $this->assertSame(1100, $summary['by_issue']['bad_signal_online']);

        $default = $service->indexPayload($admin);
        $this->assertCount(1, $default['candidates']);
        $this->assertSame('pon_pppoe_mismatch', $default['candidates'][0]['issue']);

        $signal = $service->indexPayload($admin, 50, ['monitoring_issue' => 'bad_signal_online']);
        $this->assertCount(50, $signal['candidates']);
    }

    public function test_stale_cutoff_uses_configured_minutes(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        [$a, $b] = $this->customers(2);
        $this->event($a, ['last_seen_at' => now()->subMinutes(40)]);
        $this->event($b, ['last_seen_at' => now()->subMinutes(20)]);
        $service = app(TicketMonitoringEvidenceService::class);

        config(['services.monitoring.candidate_stale_minutes' => 45]);
        $this->assertCount(2, $service->indexPayload($admin)['candidates']);

        config(['services.monitoring.candidate_stale_minutes' => 30]);
        $payload = $service->indexPayload($admin);
        $this->assertCount(1, $payload['candidates']);
        $this->assertFalse($payload['stale']);
        $this->assertSame(30, $payload['stale_minutes']);

        config(['services.monitoring.candidate_stale_minutes' => 10]);
        $this->assertTrue($service->indexPayload($admin)['stale']);
    }

    public function test_customer_with_any_open_ticket_counts_as_ticketed_and_links_it(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        [$a, $b, $c] = $this->customers(3);
        $this->event($a);
        $this->event($b);
        $this->event($c);
        $manual = SupportTicket::create(['customer_id' => $a->id, 'gangguan' => 'Manual NOC', 'type' => 'incident', 'status' => 'open']);
        SupportTicket::create(['customer_id' => $b->id, 'gangguan' => 'Sudah selesai', 'type' => 'incident', 'status' => 'closed']);
        $service = app(TicketMonitoringEvidenceService::class);

        $payload = $service->indexPayload($admin);
        $this->assertEqualsCanonicalizing([$b->id, $c->id], array_column($payload['candidates'], 'customer_id'));
        $this->assertSame(1, $payload['summary']['ticketed']);

        $withTicketed = $service->indexPayload($admin, 50, ['monitoring_show_ticketed' => '1']);
        $linked = collect($withTicketed['candidates'])->firstWhere('customer_id', $a->id);
        $this->assertSame($manual->id, $linked['existing_ticket_id']);
    }

    public function test_area_scoped_admin_only_sees_own_area_candidates_and_counts(): void
    {
        $areaA = Area::create(['code' => 'A', 'name' => 'Area A']);
        $areaB = Area::create(['code' => 'B', 'name' => 'Area B']);
        [$a, $b] = $this->customers(2);
        $a->update(['area_id' => $areaA->id]);
        $b->update(['area_id' => $areaB->id]);
        $this->event($a);
        $this->event($b);
        $scoped = User::factory()->create(['role' => 'admin']);
        $scoped->areas()->attach($areaA);
        $global = User::factory()->create(['role' => 'admin']);
        $service = app(TicketMonitoringEvidenceService::class);

        $payload = $service->indexPayload($scoped);
        $this->assertSame([$a->id], array_column($payload['candidates'], 'customer_id'));
        $this->assertSame(1, $payload['summary']['total']);
        $this->assertSame(1, $service->summary($scoped)['total']);
        $this->assertSame(2, $service->summary($global)['total']);
    }

    public function test_issue_filter_defaults_to_pon_offline_and_switches_to_bad_signal(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        [$a, $b] = $this->customers(2);
        $this->event($a);
        $this->event($b, ['issue' => 'bad_signal_online', 'severity' => 'critical']);
        $service = app(TicketMonitoringEvidenceService::class);

        $default = $service->indexPayload($admin);
        $this->assertCount(1, $default['candidates']);
        $this->assertSame('pon_pppoe_mismatch', $default['candidates'][0]['issue']);
        $this->assertSame('pon_pppoe_mismatch', $default['filters']['monitoring_issue']);

        $signal = $service->indexPayload($admin, 50, ['monitoring_issue' => 'bad_signal_online']);
        $this->assertCount(1, $signal['candidates']);
        $this->assertSame('bad_signal_online', $signal['candidates'][0]['issue']);

        $invalid = $service->indexPayload($admin, 50, ['monitoring_issue' => 'bogus']);
        $this->assertSame('pon_pppoe_mismatch', $invalid['filters']['monitoring_issue']);
    }

    public function test_stale_events_are_ignored_and_flagged(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        [$old, $fresh] = $this->customers(2);
        $this->event($old, ['last_seen_at' => now()->subMinutes(60)]);
        $service = app(TicketMonitoringEvidenceService::class);

        $payload = $service->indexPayload($admin);
        $this->assertSame([], $payload['candidates']);
        $this->assertTrue($payload['stale']);
        $this->assertTrue($payload['summary']['stale']);
        $this->assertSame(0, $payload['summary']['total']);

        $this->event($fresh);
        $payload = $service->indexPayload($admin);
        $this->assertCount(1, $payload['candidates']);
        $this->assertFalse($payload['stale']);
    }

    public function test_monitoring_status_filter_is_effective(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        [$a, $b] = $this->customers(2);
        $this->event($a);
        $this->event($b, ['status' => 'recovered']);
        $service = app(TicketMonitoringEvidenceService::class);

        $this->assertCount(1, $service->indexPayload($admin)['candidates']);
        $recovered = $service->indexPayload($admin, 50, ['monitoring_status' => 'recovered']);
        $this->assertCount(1, $recovered['candidates']);
        $this->assertSame('recovered', $recovered['candidates'][0]['status']);
        $this->assertCount(2, $service->indexPayload($admin, 50, ['monitoring_status' => 'all'])['candidates']);
    }

    public function test_candidate_list_is_limited_to_fifty(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        foreach ($this->customers(55) as $customer) {
            $this->event($customer);
        }

        $this->assertCount(50, app(TicketMonitoringEvidenceService::class)->indexPayload($admin)['candidates']);
    }

    public function test_technician_receives_no_monitoring_props_and_cannot_draft(): void
    {
        $tech = User::factory()->create(['role' => 'teknisi']);
        $event = $this->event($this->customer());

        $this->actingAs($tech)
            ->get(route('tickets.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Tickets/Index')
                ->missing('monitoringSummary')
                ->missing('monitoringEvidence')
                ->missing('workSignals'));

        $this->actingAs($tech)
            ->post(route('tickets.monitoring-candidates.draft', $event))
            ->assertForbidden();
        $this->assertSame(0, SupportTicket::count());
    }

    /**
     * @return array<int, Customer>
     */
    private function customers(int $count): array
    {
        $package = Package::query()->first() ?? Package::create(['code' => 'TME', 'name' => 'Test Monitoring', 'price' => 150000]);
        $out = [];
        for ($i = 1; $i <= $count; $i++) {
            $out[] = Customer::create([
                'code' => "C-TME-{$i}",
                'name' => "Pelanggan {$i}",
                'phone' => '0812345678'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'address' => 'RW 4 Randuagung',
                'package_id' => $package->id,
                'pppoe_user' => "evidence{$i}",
                'status' => 'active',
                'is_online' => true,
            ]);
        }

        return $out;
    }

    private function customer(): Customer
    {
        return $this->customers(1)[0];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function event(Customer $customer, array $overrides = []): MonitoringAlertEvent
    {
        $issue = $overrides['issue'] ?? 'pon_pppoe_mismatch';
        $payload = array_merge([
            'customer_name' => $customer->name,
            'customer_code' => $customer->code,
            'odp' => 'ODP RW4',
            'pon_port' => '1/1/1',
            'olt_status' => 'offline',
            'pppoe_online' => true,
            'onu_rx_power_dbm' => -28.5,
        ], $overrides['payload'] ?? []);
        unset($overrides['payload']);

        return MonitoringAlertEvent::create(array_merge([
            'source' => 'connectivity_scan',
            'entity_type' => 'customer',
            'entity_id' => (string) $customer->id,
            'entity_name' => $customer->name.' ('.$customer->code.')',
            'issue' => $issue,
            'severity' => 'warning',
            'status' => 'active',
            'dedupe_key' => 'customer:'.$customer->id.':'.$issue,
            'payload' => $payload,
            'first_seen_at' => now()->subMinutes(10),
            'last_seen_at' => now(),
        ], $overrides));
    }
}
