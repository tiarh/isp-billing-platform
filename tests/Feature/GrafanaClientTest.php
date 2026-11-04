<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\Grafana\GrafanaClient;
use App\Services\Grafana\GrafanaQueryTemplates;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Canary;
use Tests\TestCase;

/**
 * M-1: server-side Grafana client. Synthetic token only.
 */
#[Group('blocking-core')]
class GrafanaClientTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'glsa_SYNTHETIC_TEST_TOKEN_0000';

    private const BASE = 'http://grafana.test:3001';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['services.grafana.allowed_hosts' => ['grafana.test', '198.18.27.2']]);
        Setting::set(GrafanaClient::SETTING_URL, self::BASE);
        Setting::setSecret(GrafanaClient::SETTING_TOKEN, self::TOKEN);
    }

    private function frames(array $rows): array
    {
        $frames = [];
        foreach ($rows as $labels => $value) {
            $frames[] = [
                'schema' => ['refId' => 'A', 'fields' => [
                    ['name' => 'Time', 'type' => 'time'],
                    ['name' => 'Value', 'type' => 'number', 'labels' => ['router_id' => (string) $labels]],
                ]],
                'data' => ['values' => [[1700000000000, 1700000060000], [$value - 1, $value]]],
            ];
        }

        return ['results' => ['A' => ['frames' => $frames]]];
    }

    public function test_success_parses_frames_and_sends_whitelisted_query_with_token(): void
    {
        Http::fake([self::BASE.'/api/ds/query' => Http::response($this->frames(['513' => 1060, '1' => 139]))]);

        $result = app(GrafanaClient::class)->query('pppoe_per_router');

        $this->assertSame('ok', $result['status']);
        $this->assertCount(2, $result['series']);
        $this->assertSame('513', $result['series'][0]['labels']['router_id']);
        $this->assertSame([[1700000060000, 1060.0]], $result['series'][0]['points']); // instant keeps last point

        Http::assertSent(function (Request $r) {
            $body = $r->data();

            return $r->url() === self::BASE.'/api/ds/query'
                && $r->hasHeader('Authorization', 'Bearer '.self::TOKEN)
                && $body['queries'][0]['datasource']['uid'] === 'prom-main'
                && $body['queries'][0]['expr'] === 'skynet_pppoe_online{router_id!="900"}'
                && $body['queries'][0]['instant'] === true;
        });
    }

    public function test_range_query_uses_step_for_range(): void
    {
        Http::fake([self::BASE.'/api/ds/query' => Http::response($this->frames(['1' => 5]))]);

        $result = app(GrafanaClient::class)->query('router_traffic_rx_range', [], '6h');

        $this->assertSame('ok', $result['status']);
        $this->assertCount(2, $result['series'][0]['points']); // range keeps all points
        Http::assertSent(fn (Request $r) => $r->data()['queries'][0]['intervalMs'] === 120000
            && $r->data()['queries'][0]['range'] === true);
    }

    public function test_setup_required_without_token_and_no_http(): void
    {
        Setting::setSecret(GrafanaClient::SETTING_TOKEN, '');
        config(['services.grafana.token' => null]);
        Http::fake();

        $result = app(GrafanaClient::class)->query('pppoe_total');

        $this->assertSame('setup_required', $result['status']);
        Http::assertNothingSent();
    }

    public function test_timeout_returns_unavailable_without_leaking_token(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out '.self::BASE.' Bearer '.self::TOKEN));

        $result = app(GrafanaClient::class)->query('pppoe_total');

        $this->assertSame('unavailable', $result['status']);
        $this->assertSame([], $result['series']);
        $this->assertStringNotContainsString(self::TOKEN, json_encode($result));
    }

    public function test_http_client_always_carries_an_explicit_bounded_timeout_and_connect_timeout(): void
    {
        $client = app(GrafanaClient::class);
        $method = new \ReflectionMethod($client, 'http');
        $method->setAccessible(true);
        $conn = ['url' => self::BASE, 'token' => self::TOKEN, 'datasource_uid' => 'prom-main'];

        foreach ([[], [7], [7, false]] as $args) {
            $request = $method->invoke($client, $conn, ...$args);
            $options = (new \ReflectionProperty($request, 'options'))->getValue($request);

            $this->assertSame(GrafanaClient::CONNECT_TIMEOUT, $options['connect_timeout']);
            $this->assertSame($args[0] ?? GrafanaClient::TIMEOUT, $options['timeout']);
            $this->assertFalse($options['allow_redirects']);
        }
        $this->assertLessThanOrEqual(5, GrafanaClient::CONNECT_TIMEOUT);
        $this->assertLessThanOrEqual(10, GrafanaClient::TIMEOUT);
    }

    public function test_server_error_is_retried_once_then_unavailable(): void
    {
        Http::fake([self::BASE.'/api/ds/query' => Http::response(['message' => 'boom'], 500)]);

        $result = app(GrafanaClient::class)->query('pppoe_total');

        $this->assertSame('unavailable', $result['status']);
        Http::assertSentCount(2);
    }

    public function test_client_error_is_not_retried(): void
    {
        Http::fake([self::BASE.'/api/ds/query' => Http::response(['message' => 'unauthorized'], 401)]);

        $result = app(GrafanaClient::class)->query('pppoe_total');

        $this->assertSame('unavailable', $result['status']);
        Http::assertSentCount(1);
    }

    public function test_breaker_opens_after_three_failures_then_blocks_http(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;

            throw new ConnectionException('timeout');
        });
        $client = app(GrafanaClient::class);

        foreach (['pppoe_total', 'pppoe_per_router', 'collector_scrape_up'] as $tpl) {
            $client->query($tpl);
        }
        $this->assertSame(3, $calls);
        $this->assertTrue($client->breakerIsOpen());

        $result = $client->query('res_cpu');
        $this->assertSame('unavailable', $result['status']);
        $this->assertSame(3, $calls); // no new HTTP while open
    }

    public function test_breaker_closes_after_open_window_and_success_resets(): void
    {
        $down = true;
        Http::fake(function () use (&$down) {
            if ($down) {
                throw new ConnectionException('timeout');
            }

            return Http::response($this->frames(['1' => 7]));
        });
        $client = app(GrafanaClient::class);
        foreach (['pppoe_total', 'pppoe_per_router', 'collector_scrape_up'] as $tpl) {
            $client->query($tpl);
        }
        $this->assertTrue($client->breakerIsOpen());

        // Open window elapsed and Grafana is back.
        Cache::forget('grafana.cb.open');
        $down = false;

        $result = $client->query('res_cpu');

        $this->assertSame('ok', $result['status']);
        $this->assertFalse($client->breakerIsOpen());
        $this->assertNull(Cache::get('grafana.cb.failures'));
    }

    public function test_cache_hit_avoids_second_http_call(): void
    {
        Http::fake([self::BASE.'/api/ds/query' => Http::response($this->frames(['1' => 7]))]);
        $client = app(GrafanaClient::class);

        $first = $client->query('res_cpu');
        $second = $client->query('res_cpu');

        $this->assertSame($first, $second);
        Http::assertSentCount(1);
    }

    public function test_stale_last_good_served_when_grafana_fails(): void
    {
        $down = false;
        Http::fake(function () use (&$down) {
            if ($down) {
                throw new ConnectionException('timeout');
            }

            return Http::response($this->frames(['1' => 7]));
        });
        $client = app(GrafanaClient::class);
        $good = $client->query('res_cpu');

        // Fresh cache expires, last_good remains.
        $key = 'grafana.w.res_cpu.'.sha1(json_encode([[], null, 'prom-main', self::BASE]));
        Cache::forget($key);
        $down = true;

        $result = $client->query('res_cpu');

        $this->assertSame('stale', $result['status']);
        $this->assertSame($good['series'], $result['series']);
        $this->assertSame($good['fetched_at'], $result['fetched_at']);
    }

    public function test_token_never_appears_in_logs_or_results_and_is_encrypted_at_rest(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
            $logged[] = $e->message.' '.json_encode($e->context);
        });
        Http::fake(fn () => throw new ConnectionException('failed '.self::BASE.' '.self::TOKEN));

        $client = app(GrafanaClient::class);
        $result = $client->query('pppoe_total');
        $test = $client->testConnection();
        $dash = $client->dashboards();

        $blob = json_encode([$result, $test, $dash]).implode("\n", $logged);
        $this->assertStringNotContainsString(self::TOKEN, $blob);
        $this->assertNotEmpty($logged);

        $raw = (string) Setting::where('key', GrafanaClient::SETTING_TOKEN)->value('value');
        $this->assertStringStartsWith(Setting::ENCRYPTED_PREFIX, $raw);
        $this->assertStringNotContainsString(self::TOKEN, $raw);
    }

    public function test_invalid_template_and_params_are_rejected_before_any_http(): void
    {
        Http::fake();
        $client = app(GrafanaClient::class);

        foreach ([
            ['not_a_template', [], '1h'],
            ['pppoe_total', [], '99y'],
            ['router_uplink_rx_range', ['rid' => '1"} or vector(1) #'], '1h'],
            ['router_uplink_rx_range', [], '1h'],
            ['pppoe_total', ['rid' => '1'], '1h'],
            ['edge_iface_rx_range', ['ifc' => 'x"}) or (up'], '1h'],
            ['edge_iface_rx_range', ['ifc' => "a\nb"], '1h'],
        ] as [$tpl, $params, $range]) {
            try {
                $client->query($tpl, $params, $range);
                $this->fail("expected rejection for {$tpl}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        Http::assertNothingSent();
    }

    public function test_valid_edge_interface_names_are_accepted(): void
    {
        foreach (['Bonding1-[N3K-Po1]', 'Content_596--JKTIX', 'VID.1329.SKYNET.LINTAS.NUSANTARA.IPT-MIX.EDGE2 VLAN 1329'] as $ifc) {
            $r = GrafanaQueryTemplates::resolve('edge_iface_rx_range', ['ifc' => $ifc]);
            $this->assertStringContainsString('interface="'.$ifc.'"', $r['expr']);
        }
        $this->assertSame('a\\\\b\\"c', GrafanaQueryTemplates::escapeLabel('a\\b"c'));
    }

    public function test_connection_falls_back_to_config_and_default_datasource(): void
    {
        Setting::set(GrafanaClient::SETTING_URL, '');
        Setting::setSecret(GrafanaClient::SETTING_TOKEN, '');
        config(['services.grafana.url' => 'http://198.18.27.2:3001', 'services.grafana.token' => Canary::of('token')]);

        $conn = app(GrafanaClient::class)->connection();

        $this->assertSame('http://198.18.27.2:3001', $conn['url']);
        $this->assertSame('canary-token', $conn['token']);
        $this->assertSame('prom-main', $conn['datasource_uid']);
    }

    public function test_disallowed_host_is_never_contacted(): void
    {
        Setting::set(GrafanaClient::SETTING_URL, 'http://evil.example:3001');
        Http::fake();
        $client = app(GrafanaClient::class);

        $this->assertSame('setup_required', $client->query('pppoe_total')['status']);
        $this->assertFalse($client->testConnection()['ok']);
        $this->assertFalse($client->dashboards()['enabled']);
        Http::assertNothingSent();
    }

    public function test_generic_settings_endpoint_rejects_grafana_keys(): void
    {
        $admin = User::factory()->create(['role' => 'superadmin']);

        foreach (['grafana_url', 'grafana_service_account_token', 'GRAFANA_URL'] as $key) {
            $this->actingAs($admin)->post(route('settings.update'), ['settings' => [
                ['key' => $key, 'value' => 'http://evil.example', 'group' => 'x', 'type' => 'text'],
            ]])->assertSessionHasErrors('settings.0.key');
        }

        $this->assertSame(self::BASE, Setting::get(GrafanaClient::SETTING_URL));
    }

    public function test_legacy_env_file_url_is_used_with_its_token(): void
    {
        Setting::set(GrafanaClient::SETTING_URL, '');
        Setting::setSecret(GrafanaClient::SETTING_TOKEN, '');
        config(['services.grafana.token' => null, 'services.grafana.url' => 'http://198.18.27.2:3001']);
        $path = storage_path('app/grafana.env');
        $this->assertFalse(is_file($path), 'unexpected pre-existing grafana.env');
        file_put_contents($path, "GRAFANA_URL=http://grafana.test:9999\nGRAFANA_TOKEN=legacy-synthetic\n");

        try {
            $conn = app(GrafanaClient::class)->connection();
        } finally {
            @unlink($path);
        }

        $this->assertSame('http://grafana.test:9999', $conn['url']);
        $this->assertSame('legacy-synthetic', $conn['token']);
    }

    public function test_instant_cache_key_ignores_range_and_long_range_ttl_is_30_minutes(): void
    {
        Http::fake([self::BASE.'/api/ds/query' => Http::response($this->frames(['1' => 7]))]);
        $client = app(GrafanaClient::class);
        $client->query('res_cpu', [], '1h');
        $client->query('res_cpu', [], '24h');
        Http::assertSentCount(1);

        $this->assertSame(1800, GrafanaQueryTemplates::resolve('upstream_rx_range', [], '30d')['ttl']);
        $this->assertSame(1800, GrafanaQueryTemplates::resolve('upstream_rx_range', [], '7d')['ttl']);
        $this->assertSame(300, GrafanaQueryTemplates::resolve('upstream_rx_range', [], '24h')['ttl']);
        $this->assertSame(60, GrafanaQueryTemplates::resolve('upstream_rx_range', [], '1h')['ttl']);
    }

    public function test_dashboards_have_no_stale_isp_uplink_fallback(): void
    {
        Http::fake(fn () => throw new ConnectionException('down'));

        $dash = app(GrafanaClient::class)->dashboards();

        $uids = array_column($dash['dashboards'], 'uid');
        $this->assertNotContains('isp-uplink-mikrotik', $uids);
        $this->assertContains('skynet-jakarta-edge', $uids);
        $this->assertNotNull($dash['error']);
    }

    public function test_m3_widget_templates_resolve_to_recording_rules_and_blackbox_selectors(): void
    {
        $r = GrafanaQueryTemplates::resolve('router_uplink_mbps');
        $this->assertSame('skynet:router_uplink_mbps:rate5m', $r['expr']);
        $this->assertSame('instant', $r['mode']);
        $this->assertSame('range', GrafanaQueryTemplates::resolve('router_uplink_mbps_range', [], '6h')['mode']);

        foreach (['p95' => 'quantile_over_time(0.95, ', 'peak' => 'max_over_time(', 'avg' => 'avg_over_time('] as $k => $prefix) {
            $x = GrafanaQueryTemplates::resolve('upstream_tx_'.$k, [], '30d');
            $this->assertSame($prefix.'skynet:upstream_tx_mbps:rate5m[30d])', $x['expr']);
            $this->assertSame(1800, $x['ttl']);
            $this->assertSame(300, GrafanaQueryTemplates::resolve('upstream_tx_'.$k, [], '1h')['ttl']);
        }

        $this->assertSame(
            'probe_icmp_duration_seconds{job="blackbox_icmp",phase="rtt",kind=~"upstream|internet"}',
            GrafanaQueryTemplates::resolve('ping_rtt_range')['expr']
        );
        $this->assertSame(
            '(1-avg_over_time(probe_success{job="blackbox_icmp",kind=~"upstream|internet"}[5m]))*100',
            GrafanaQueryTemplates::resolve('ping_loss_range')['expr']
        );
        $this->assertSame(
            'up{job=~"mktxp_106|skynet_traffic_106|blackbox_exporter"}',
            GrafanaQueryTemplates::resolve('collector_targets_up')['expr']
        );
    }

    public function test_pppoe_user_templates_validate_and_clamp_win(): void
    {
        $this->assertSame(
            'increase(skynet_user_rx_bytes{router_id="513"}[86400s])',
            GrafanaQueryTemplates::resolve('pppoe_user_rx_increase', ['rid' => '513', 'win' => '86400s'])['expr']
        );
        $this->assertSame(
            'increase(skynet_user_tx_bytes{router_id="1"}[90000s])',
            GrafanaQueryTemplates::resolve('pppoe_user_tx_increase', ['rid' => 1, 'win' => '999999s'])['expr']
        );
        $this->assertSame(
            'increase(skynet_user_tx_bytes{router_id="1"}[300s])',
            GrafanaQueryTemplates::resolve('pppoe_user_tx_increase', ['rid' => 1, 'win' => '060s'])['expr']
        );
        $this->assertSame(
            'rate(skynet_user_rx_bytes{router_id="15"}[5m])*8/1e6',
            GrafanaQueryTemplates::resolve('pppoe_user_rx_rate', ['rid' => '15'])['expr']
        );
        $this->assertSame(
            'rate(skynet_user_tx_bytes{router_id="15"}[5m])*8/1e6',
            GrafanaQueryTemplates::resolve('pppoe_user_tx_rate', ['rid' => '15'])['expr']
        );

        foreach (['86400', '12s', '1234567s', '1h', '86400s) or (up', '-300s', ' 300s'] as $bad) {
            try {
                GrafanaQueryTemplates::resolve('pppoe_user_rx_increase', ['rid' => '1', 'win' => $bad]);
                $this->fail("expected rejection for win={$bad}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_query_at_evaluates_at_instant_without_cache_and_feeds_breaker(): void
    {
        Http::fake([self::BASE.'/api/ds/query' => Http::response($this->frames(['1' => 7]))]);
        $client = app(GrafanaClient::class);
        $at = CarbonImmutable::parse('2026-10-11 00:00:00', 'Asia/Jakarta');

        $first = $client->queryAt('pppoe_user_rx_increase', ['rid' => 1, 'win' => '86400s'], $at);
        $second = $client->queryAt('pppoe_user_rx_increase', ['rid' => 1, 'win' => '86400s'], $at);

        $this->assertSame('ok', $first['status']);
        $this->assertSame('ok', $second['status']);
        Http::assertSentCount(2); // no result cache
        Http::assertSent(function (Request $r) use ($at) {
            $q = $r->data();

            return $q['to'] === (string) ($at->getTimestamp() * 1000)
                && $q['queries'][0]['expr'] === 'increase(skynet_user_rx_bytes{router_id="1"}[86400s])'
                && $q['queries'][0]['instant'] === true;
        });
    }

    public function test_query_at_respects_and_feeds_the_breaker(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;

            throw new ConnectionException('timeout');
        });
        $client = app(GrafanaClient::class);
        $at = CarbonImmutable::now();

        foreach ([1, 2, 3] as $rid) {
            $this->assertSame('unavailable', $client->queryAt('pppoe_user_tx_increase', ['rid' => $rid, 'win' => '900s'], $at)['status']);
        }
        $this->assertSame(3, $calls);
        $this->assertTrue($client->breakerIsOpen());

        $this->assertSame('unavailable', $client->queryAt('pppoe_user_tx_increase', ['rid' => 4, 'win' => '900s'], $at)['status']);
        $this->assertSame(3, $calls);
    }

    public function test_query_at_does_not_retry_a_5xx_but_still_feeds_the_breaker(): void
    {
        Http::fake([self::BASE.'/api/ds/query' => Http::response(['message' => 'boom'], 500)]);
        $client = app(GrafanaClient::class);

        $result = $client->queryAt('pppoe_user_rx_increase', ['rid' => 1, 'win' => '86400s'], CarbonImmutable::now());

        $this->assertSame('unavailable', $result['status']);
        Http::assertSentCount(1);
        $this->assertSame(1, (int) Cache::get('grafana.cb.failures'));
    }

    public function test_query_at_is_single_flight_and_rejects_range_templates(): void
    {
        Http::fake([self::BASE.'/api/ds/query' => Http::response($this->frames(['1' => 7]))]);
        $client = app(GrafanaClient::class);
        $at = CarbonImmutable::parse('2026-10-11 00:00:00', 'Asia/Jakarta');

        $key = 'grafana.at.pppoe_user_rx_increase.'.sha1(json_encode([['rid' => 1, 'win' => '900s'], $at->getTimestamp(), 'prom-main', self::BASE]));
        $held = Cache::lock($key.'.lock', 30);
        $this->assertTrue($held->get());

        $result = $client->queryAt('pppoe_user_rx_increase', ['rid' => 1, 'win' => '900s'], $at);
        $this->assertSame('unavailable', $result['status']);
        Http::assertNothingSent();
        $held->release();

        $this->expectException(InvalidArgumentException::class);
        $client->queryAt('router_uplink_rx_range', ['rid' => 1], $at);
    }
}
