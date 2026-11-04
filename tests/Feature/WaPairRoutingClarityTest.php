<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\Wa\WaRoutingOverview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * WA Gateway pairing page: real routing per number, live status, masked number, unused-instance
 * badge and the Ticket & Progress selector. Evolution is always faked; numbers are fake.
 */
#[Group('blocking-core')]
class WaPairRoutingClarityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Setting::flushRequestMemo();
        config([
            'services.evolution_api.base_url' => 'http://evo.test',
            'services.evolution_api.api_key' => 'canary-pw',
            'services.evolution_api.instance_ops' => 'skynet-ebilling',
            'services.evolution_api.instance_billing' => 'skynet-billing',
            'services.evolution_api.instance_ticket' => 'skynet-ebilling',
            'services.evolution_api.instance_ticket_dedicated' => 'skynet-ticket',
        ]);
    }

    /** @param array<string, array{state:string, owner?:string}> $instances */
    private function fakeEvolution(array $instances): void
    {
        $rows = [];
        foreach ($instances as $name => $i) {
            $rows[] = ['name' => $name, 'connectionStatus' => $i['state'], 'ownerJid' => isset($i['owner']) ? $i['owner'].'@s.whatsapp.net' : null];
        }
        $fake = [];
        foreach ($instances as $name => $i) {
            $fake['evo.test/instance/connectionState/'.$name] = Http::response(['instance' => ['instanceName' => $name, 'state' => $i['state']]]);
        }
        $fake['evo.test/instance/fetchInstances*'] = Http::response($rows);
        $fake['evo.test/*'] = Http::response([], 404);
        Http::fake($fake);
    }

    private function superadmin(): User
    {
        return User::factory()->create(['role' => 'superadmin']);
    }

    public function test_props_show_real_routing_status_and_masked_number(): void
    {
        $this->fakeEvolution([
            'skynet-ebilling' => ['state' => 'open', 'owner' => '62800000001'],
            'skynet-billing' => ['state' => 'connecting'],
            'skynet-ticket' => ['state' => 'close'],
        ]);

        $response = $this->actingAs($this->superadmin())->get('/wa-gateway');

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Settings/WaPair')
            ->where('instances.0.name', 'skynet-ebilling')
            ->where('instances.0.connected', true)
            ->where('instances.0.number_masked', '••••0001')
            ->where('instances.0.unused', false)
            ->where('instances.1.name', 'skynet-billing')
            ->where('instances.1.state', 'connecting')
            ->where('instances.1.connected', false)
            ->where('instances.2.name', 'skynet-ticket')
            ->where('instances.2.state', 'close')
            ->where('instances.2.connected', false)
            ->where('instances.2.unused', true)
            ->where('instances.2.unused_message', 'TIDAK DIPAKAI — Ticket & Progress saat ini lewat Nomor 1 (skynet-ebilling)')
            ->where('instances.0.also_handles.0', 'Nomor 1 juga menangani Ticket & Progress')
            ->where('ticket_routing.current', 'skynet-ebilling')
            ->where('ticket_routing.can_edit', true)
            ->etc());

        $body = $response->getContent();
        $this->assertStringNotContainsString('62800000001', $body);
        $this->assertStringNotContainsString('canary-pw', $body);
    }

    public function test_routing_resolution_setting_overrides_config_fallback(): void
    {
        $overview = app(WaRoutingOverview::class);
        $this->assertSame('skynet-ebilling', $overview->resolve('ticket'));

        Setting::set('wa_instance_ticket', 'skynet-ticket', 'text', 'wa');
        Setting::flushRequestMemo();

        $this->assertSame('skynet-ticket', $overview->resolve('ticket'));
        $this->assertSame('skynet-ticket', $overview->resolve('progress'));
        $this->assertSame('skynet-ebilling', $overview->resolve('ops'));
        $this->assertSame('skynet-billing', $overview->resolve('billing'));

        $this->fakeEvolution([
            'skynet-ebilling' => ['state' => 'open'],
            'skynet-billing' => ['state' => 'open'],
            'skynet-ticket' => ['state' => 'open'],
        ]);
        $cards = $overview->cards();
        $this->assertFalse($cards[2]['unused']);
        $this->assertSame([], $cards[0]['also_handles']);
    }

    public function test_report_channel_ticket_follows_ticket_instance(): void
    {
        Setting::set('report_wa_channel', 'ticket', 'text', 'report');
        Setting::flushRequestMemo();

        $functions = collect(app(WaRoutingOverview::class)->functions())->keyBy('key');
        $this->assertSame('skynet-ebilling', $functions['report']['instance']);
        $this->assertSame('ticket', $functions['report']['channel']);
    }

    public function test_masking_never_exposes_more_than_last_four_digits(): void
    {
        $this->assertSame('••••0001', WaRoutingOverview::maskNumber('62800000001'));
        $this->assertNull(WaRoutingOverview::maskNumber(null));
        $this->assertNull(WaRoutingOverview::maskNumber(''));
    }

    public function test_selector_saves_setting_logs_activity_and_forgets_cache(): void
    {
        $this->fakeEvolution([
            'skynet-ebilling' => ['state' => 'open'],
            'skynet-billing' => ['state' => 'open'],
            'skynet-ticket' => ['state' => 'close'],
        ]);
        $this->assertSame('skynet-ebilling', app(WaRoutingOverview::class)->resolve('ticket'));

        $response = $this->actingAs($this->superadmin())
            ->postJson('/api/wa-gateway/ticket-routing', ['instance' => 'skynet-ticket']);

        $response->assertOk()->assertJsonPath('success', true)->assertJsonPath('current', 'skynet-ticket');
        $this->assertNotEmpty($response->json('warnings'), 'not connected must warn');
        $this->assertSame('skynet-ticket', Setting::where('key', 'wa_instance_ticket')->value('value'));
        $this->assertSame('skynet-ticket', app(WaRoutingOverview::class)->resolve('ticket'));
        $this->assertTrue(Activity::where('log_name', 'wa-gateway.routing')->where('description', 'wa_ticket_routing_changed')->exists());
    }

    public function test_selector_rejects_unknown_instance_and_non_superadmin(): void
    {
        $this->fakeEvolution(['skynet-ebilling' => ['state' => 'open']]);

        $this->actingAs($this->superadmin())
            ->postJson('/api/wa-gateway/ticket-routing', ['instance' => 'evil-instance'])
            ->assertStatus(422);
        $this->assertNull(Setting::where('key', 'wa_instance_ticket')->first());

        // Billing is a known instance but not a valid Ticket & Progress target.
        $this->actingAs($this->superadmin())
            ->postJson('/api/wa-gateway/ticket-routing', ['instance' => 'skynet-billing'])
            ->assertStatus(422);
        $this->assertNull(Setting::where('key', 'wa_instance_ticket')->first());

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)
            ->postJson('/api/wa-gateway/ticket-routing', ['instance' => 'skynet-ticket'])
            ->assertStatus(403);
        $this->assertNull(Setting::where('key', 'wa_instance_ticket')->first());
    }

    public function test_pairing_actions_target_the_instance_named_on_the_card(): void
    {
        $this->fakeEvolution([
            'skynet-ebilling' => ['state' => 'open'],
            'skynet-ticket' => ['state' => 'close'],
        ]);
        $user = $this->superadmin();

        $this->actingAs($user)->getJson('/api/wa-gateway/status?channel=ticket&instance=skynet-ticket&fresh=1')
            ->assertOk()
            ->assertJsonPath('instance_name', 'skynet-ticket')
            ->assertJsonPath('state', 'close');

        $this->actingAs($user)->getJson('/api/wa-gateway/status?channel=ticket&instance=not-known')
            ->assertStatus(422);

        // Without an instance param the legacy channel resolution (-> skynet-ebilling) is unchanged.
        $this->actingAs($user)->getJson('/api/wa-gateway/status?channel=ticket')
            ->assertOk()
            ->assertJsonPath('instance_name', 'skynet-ebilling');
    }
}
