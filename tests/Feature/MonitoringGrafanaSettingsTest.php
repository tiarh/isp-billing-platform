<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireControlPlaneTotp;
use App\Models\Area;
use App\Models\Router;
use App\Models\Setting;
use App\Models\User;
use App\Services\Grafana\GrafanaClient;
use App\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Canary;
use Tests\TestCase;

#[Group('blocking-core')]
class MonitoringGrafanaSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'glsa_SYNTHETIC_SETTINGS_TOKEN_0001';

    private const BASE = 'http://grafana.test:3001';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['services.grafana.allowed_hosts' => ['grafana.test']]);
    }

    private function configureGrafana(): void
    {
        Setting::set(GrafanaClient::SETTING_URL, self::BASE);
        Setting::setSecret(GrafanaClient::SETTING_TOKEN, self::TOKEN);
    }

    private function noc(): User
    {
        return User::factory()->create(['role' => 'noc']);
    }

    private function superadminWithTotp(): User
    {
        $user = User::factory()->create(['role' => 'superadmin']);
        app(TotpService::class)->enable($user, 'JBSWY3DPEHPK3PXP', ['recovery-code-1234']);

        return $user->fresh();
    }

    private function grant(string $routeName, User $user): array
    {
        return [RequireControlPlaneTotp::VERIFIED_KEY => [
            'route' => $routeName,
            'user_id' => $user->id,
            'expires_at' => time() + 120,
        ]];
    }

    private function makeRouter(int $id, string $name, bool $active = true): Router
    {
        $router = new Router([
            'name' => $name,
            'ip_address' => '198.18.26.'.$id,
            'username' => 'admin',
            'password' => Canary::of('pw'),
            'port' => 8728,
            'is_active' => $active,
        ]);
        $router->id = $id;
        $router->save();

        return $router;
    }

    public function test_access_matrix(): void
    {
        $url = route('monitoring.settings.index');

        $this->get($url)->assertRedirect(route('login'));

        foreach (['superadmin', 'noc', 'admin'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->get($url)->assertOk();
        }

        $scoped = User::factory()->create(['role' => 'admin']);
        $scoped->areas()->attach(Area::create(['code' => 'SET-A', 'name' => 'Area A']));
        $this->actingAs($scoped)->get($url)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'teknisi']))->get($url)->assertForbidden();
    }

    public function test_all_put_routes_require_control_plane_totp(): void
    {
        foreach (['connection.update', 'upstream-links.update', 'router-identities.update'] as $name) {
            $route = Route::getRoutes()->getByName('monitoring.settings.'.$name);
            $this->assertNotNull($route);
            $this->assertContains('control-plane-totp', $route->gatherMiddleware(), $name);
            $this->assertContains('global-admin', $route->gatherMiddleware(), $name);
        }
    }

    public function test_superadmin_without_grant_is_redirected_and_nothing_saved(): void
    {
        $user = $this->superadminWithTotp();

        $this->actingAs($user)
            ->put(route('monitoring.settings.connection.update'), [
                'url' => self::BASE, 'datasource_uid' => 'prom-main', 'token' => self::TOKEN,
            ])
            ->assertRedirect(route('totp.step-up', absolute: false));

        $this->assertNull(Setting::get(GrafanaClient::SETTING_URL));
        $this->assertSame('', Setting::secret(GrafanaClient::SETTING_TOKEN));
    }

    public function test_superadmin_with_session_grant_saves(): void
    {
        $user = $this->superadminWithTotp();

        $this->actingAs($user)
            ->withSession($this->grant('monitoring.settings.connection.update', $user))
            ->put(route('monitoring.settings.connection.update'), [
                'url' => self::BASE.'/', 'datasource_uid' => 'prom-main', 'token' => self::TOKEN,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(self::BASE, Setting::get(GrafanaClient::SETTING_URL));
        $this->assertSame('prom-main', Setting::get(GrafanaClient::SETTING_DATASOURCE_UID));
    }

    public function test_noc_saves_without_totp_and_token_is_encrypted_and_kept_when_blank(): void
    {
        $noc = $this->noc();
        $payload = ['url' => self::BASE, 'datasource_uid' => 'prom-main', 'token' => self::TOKEN];

        $this->actingAs($noc)->put(route('monitoring.settings.connection.update'), $payload)->assertSessionHasNoErrors();

        $raw = (string) DB::table('settings')->where('key', GrafanaClient::SETTING_TOKEN)->value('value');
        $this->assertStringNotContainsString(self::TOKEN, $raw);
        $this->assertSame(self::TOKEN, Setting::secret(GrafanaClient::SETTING_TOKEN));

        $this->actingAs($noc)->put(route('monitoring.settings.connection.update'), [
            'url' => self::BASE, 'datasource_uid' => 'other-uid', 'token' => '',
        ])->assertSessionHasNoErrors();

        $this->assertSame(self::TOKEN, Setting::secret(GrafanaClient::SETTING_TOKEN));
        $this->assertSame('other-uid', Setting::get(GrafanaClient::SETTING_DATASOURCE_UID));
    }

    public function test_saving_connection_clears_open_breaker(): void
    {
        Cache::put('grafana.cb.open', true, 60);

        $this->actingAs($this->noc())->put(route('monitoring.settings.connection.update'), [
            'url' => self::BASE, 'datasource_uid' => 'prom-main',
        ])->assertSessionHasNoErrors();

        $this->assertFalse(Cache::has('grafana.cb.open'));
    }

    public function test_token_never_appears_in_props_or_json(): void
    {
        $this->configureGrafana();
        $noc = $this->noc();

        $response = $this->actingAs($noc)->get(route('monitoring.settings.index'))->assertOk();
        $props = $response->viewData('page')['props'];
        $this->assertTrue($props['connection']['token_set']);
        $this->assertArrayNotHasKey('token', $props['connection']);
        $this->assertStringNotContainsString(self::TOKEN, json_encode($props));
        $this->assertStringNotContainsString(self::TOKEN, $response->getContent());

        Http::fake([self::BASE.'/*' => Http::response(['results' => ['A' => ['frames' => []]]])]);
        $json = $this->actingAs($noc)->postJson(route('monitoring.settings.test'))->assertOk();
        $this->assertStringNotContainsString(self::TOKEN, $json->getContent());
    }

    public function test_disallowed_host_is_rejected(): void
    {
        $this->actingAs($this->noc())->put(route('monitoring.settings.connection.update'), [
            'url' => 'http://evil.example:3001', 'datasource_uid' => 'prom-main', 'token' => self::TOKEN,
        ])->assertSessionHasErrors(['url' => 'Host tidak diizinkan (GRAFANA_ALLOWED_HOSTS)']);

        $this->assertNull(Setting::get(GrafanaClient::SETTING_URL));
    }

    public function test_label_values_sends_token_and_match_and_is_cached(): void
    {
        $this->configureGrafana();
        Http::fake([
            self::BASE.'/api/datasources/uid/prom-main/resources/api/v1/label/routerboard_name/values*' => Http::response(
                ['status' => 'success', 'data' => ['Krian', 'Arjosari']]
            ),
        ]);
        $noc = $this->noc();
        $url = route('monitoring.settings.label-values', 'routerboard_name');

        $this->actingAs($noc)->getJson($url)->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('values', ['Arjosari', 'Krian']);
        $this->actingAs($noc)->getJson($url)->assertOk();

        Http::assertSentCount(1);
        Http::assertSent(function (Request $r) {
            return $r->hasHeader('Authorization', 'Bearer '.self::TOKEN)
                && str_contains(urldecode($r->url()), 'match[]=mktxp_system_identity_info');
        });
    }

    public function test_label_values_unknown_source_is_404_and_open_breaker_sends_nothing(): void
    {
        $this->configureGrafana();
        Http::fake();
        $noc = $this->noc();

        $this->actingAs($noc)->getJson('/monitoring/settings/label-values/bogus')->assertNotFound();

        Cache::put('grafana.cb.open', true, 60);
        $this->actingAs($noc)->getJson(route('monitoring.settings.label-values', 'router'))
            ->assertOk()->assertJsonPath('status', 'unavailable');

        Http::assertNothingSent();
    }

    public function test_label_values_retries_without_match_on_4xx(): void
    {
        $this->configureGrafana();
        Http::fakeSequence()
            ->push(['status' => 'error'], 400)
            ->push(['status' => 'success', 'data' => ['THC']]);

        $result = app(GrafanaClient::class)->labelValues('upstream');

        $this->assertSame('ok', $result['status']);
        $this->assertSame(['THC'], $result['values']);
        Http::assertSentCount(2);
    }

    private function seedLinks(): void
    {
        DB::table('monitoring_upstream_links')->delete(); // migration seeds these rows
        DB::table('monitoring_upstream_links')->insert([
            'upstream_key' => 'THC', 'display_name' => 'THC', 'kind' => 'transit',
            'interface_name' => 'x', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_upstream_links_upsert_and_validation(): void
    {
        $this->seedLinks();
        $noc = $this->noc();
        $url = route('monitoring.settings.upstream-links.update');

        $this->actingAs($noc)->put($url, ['links' => [[
            'upstream_key' => 'THC', 'display_name' => 'THC', 'kind' => 'transit',
            'interface_name' => 'Content_1--THC', 'capacity_mbps' => 3000, 'is_active' => false,
        ], [
            'upstream_key' => 'NEWKEY', 'display_name' => 'Baru', 'kind' => 'ix',
            'interface_name' => 'Content_9--NEW', 'capacity_mbps' => null, 'is_active' => true,
        ]]])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('monitoring_upstream_links', ['upstream_key' => 'THC', 'capacity_mbps' => 3000, 'is_active' => false, 'interface_name' => 'Content_1--THC']);
        $this->assertDatabaseHas('monitoring_upstream_links', ['upstream_key' => 'NEWKEY', 'router_label' => 'Jakarta-Edge']);
        $this->assertSame(1, DB::table('monitoring_upstream_links')->where('upstream_key', 'THC')->count());

        $this->actingAs($noc)->putJson($url, ['links' => [[
            'upstream_key' => 'THC', 'display_name' => 'THC', 'kind' => 'transit',
            'interface_name' => 'bad;interface$(x)', 'capacity_mbps' => 3000,
        ]]])->assertStatus(422)->assertJsonValidationErrors(['links.0.interface_name']);

        $this->actingAs($noc)->putJson($url, ['links' => [[
            'upstream_key' => 'thc', 'display_name' => 'THC', 'kind' => 'bogus',
            'interface_name' => 'ok', 'capacity_mbps' => 0,
        ]]])->assertStatus(422);
    }

    public function test_router_identities_validation_save_and_clear(): void
    {
        $this->makeRouter(1, 'Randuagung');
        $noc = $this->noc();
        $url = route('monitoring.settings.router-identities.update');

        $this->actingAs($noc)->putJson($url, ['routers' => [['router_id' => 1, 'grafana_identity' => 'bad;id']]])
            ->assertStatus(422);
        $this->actingAs($noc)->putJson($url, ['routers' => [['router_id' => 999, 'grafana_identity' => 'X']]])
            ->assertStatus(422);

        $this->actingAs($noc)->put($url, ['routers' => [['router_id' => 1, 'grafana_identity' => 'Randuagung']]])
            ->assertSessionHasNoErrors();
        $this->assertSame('Randuagung', DB::table('routers')->where('id', 1)->value('grafana_identity'));

        $this->actingAs($noc)->put($url, ['routers' => [['router_id' => 1, 'grafana_identity' => 'Edge[N3K-Po1]']]])
            ->assertSessionHasNoErrors();
        $this->assertSame('Edge[N3K-Po1]', DB::table('routers')->where('id', 1)->value('grafana_identity'));

        $this->actingAs($noc)->put($url, ['routers' => [['router_id' => 1, 'grafana_identity' => null]]])
            ->assertSessionHasNoErrors();
        $this->assertNull(DB::table('routers')->where('id', 1)->value('grafana_identity'));
    }

    public function test_suggest_uses_router_id_label_then_name_and_writes_nothing(): void
    {
        $this->configureGrafana();
        $this->makeRouter(1, 'Randuagung Lama');
        $this->makeRouter(2, 'Arjosari');
        $this->makeRouter(3, 'Tidak Ada');
        $this->makeRouter(4, 'Retired', false);
        $this->makeRouter(900, 'EdgeFake');

        $series = fn (array $labels) => [
            'schema' => ['fields' => [['name' => 'Time'], ['name' => 'Value', 'labels' => $labels]]],
            'data' => ['values' => [[1], [1]]],
        ];
        Http::fake([
            self::BASE.'/api/ds/query' => Http::response(['results' => ['A' => ['frames' => [
                $series(['router_id' => '1', 'router' => 'Randuagung']),
                $series(['router_id' => '4', 'router' => 'RetiredLabel']),
                $series(['router_id' => '900', 'router' => 'Jakarta-Edge']),
            ]]]]),
            self::BASE.'/api/datasources/*' => Http::response(['status' => 'success', 'data' => ['Randuagung', 'ARJOSARI']]),
        ]);

        $rows = collect($this->actingAs($this->noc())->postJson(route('monitoring.settings.router-identities.suggest'))
            ->assertOk()->json('rows'))->keyBy('router_id');

        $this->assertSame('Randuagung', $rows[1]['suggested']);
        $this->assertSame('router_id_label', $rows[1]['source']);
        $this->assertSame('ARJOSARI', $rows[2]['suggested']);
        $this->assertSame('name_match', $rows[2]['source']);
        $this->assertNull($rows[3]['suggested']);
        $this->assertNull($rows[900]['source']);
        $this->assertFalse($rows->has(4));
        $this->assertSame(0, DB::table('routers')->whereNotNull('grafana_identity')->count());
    }
}
