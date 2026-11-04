<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Parity for the env() -> config() move (batch E). For every key: env var set
 * -> config value equals it; env var unset -> the default the old env() call
 * site used. The config files are re-evaluated as plain arrays so the test does
 * not depend on whatever .env or config cache the machine has.
 */
#[Group('blocking-core')]
class ConfigEnvParityTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: mixed, 5: mixed}>
     */
    public static function keys(): array
    {
        // file, config path, env var, set value, expected when set, expected when unset
        return [
            'midtrans is_production' => ['services', 'midtrans.is_production', 'MIDTRANS_IS_PRODUCTION', 'true', true, false],
            'midtrans server_key' => ['services', 'midtrans.server_key', 'MIDTRANS_SERVER_KEY', 'canary-pw', 'canary-pw', ''],
            'midtrans client_key' => ['services', 'midtrans.client_key', 'MIDTRANS_CLIENT_KEY', 'canary-pw', 'canary-pw', ''],
            'midtrans merchant_id' => ['services', 'midtrans.merchant_id', 'MIDTRANS_MERCHANT_ID', 'canary-pw', 'canary-pw', ''],
            'psb health token' => ['services', 'psb_wa_health.public_token', 'PSB_WA_HEALTH_PUBLIC_TOKEN', 'canary-pw', 'canary-pw', ''],
            'snmp live_timeout' => ['services', 'snmp_collector.live_timeout', 'SNMP_LIVE_TIMEOUT', '42', '42', null],
            'snmp walk_timeout' => ['services', 'snmp_collector.walk_timeout', 'SNMP_WALK_TIMEOUT', '42', '42', null],
            'snmp bulk_reps' => ['services', 'snmp_collector.bulk_reps', 'SNMP_BULK_REPS', '42', '42', null],
            'snmp mode' => ['services', 'snmp_collector.mode', 'SNMP_MODE', 'canary-pw', 'canary-pw', null],
            'snmp use_host_network' => ['services', 'snmp_collector.use_host_network', 'SNMP_USE_HOST_NETWORK', 'false', false, true],
            'snmp docker_image' => ['services', 'snmp_collector.docker_image', 'SNMP_DOCKER_IMAGE', 'canary-pw', 'canary-pw', null],
            'whatspie test_number' => ['services', 'whatspie.test_number', 'WHATSPIE_TEST_NUMBER', '628123456789', '628123456789', null],
            'csp asset_url' => ['app', 'csp_asset_url', 'ASSET_URL', 'https://cdn.example.test', 'https://cdn.example.test', ''],
            'trusted_proxies' => ['app', 'trusted_proxies', 'TRUSTED_PROXIES', '198.18.0.0/15', '198.18.0.0/15', '127.0.0.1/32'],
            'vite url' => ['app', 'vite_dev.url', 'VITE_DEV_SERVER_URL', 'http://vite.example.test:1', 'http://vite.example.test:1', ''],
            'vite host' => ['app', 'vite_dev.host', 'VITE_DEV_SERVER_HOST', 'canary-pw', 'canary-pw', 'localhost'],
            'vite port' => ['app', 'vite_dev.port', 'VITE_PORT', '4000', '4000', 5173],
        ];
    }

    #[DataProvider('keys')]
    public function test_env_set_and_unset(string $file, string $path, string $envVar, string $setValue, mixed $expectedSet, mixed $expectedUnset): void
    {
        $this->assertSame($expectedSet, $this->resolve($file, $path, $envVar, $setValue), "$envVar set");
        $this->assertSame($expectedUnset, $this->resolve($file, $path, $envVar, null), "$envVar unset");
    }

    public function test_app_asset_url_is_left_to_laravel(): void
    {
        $this->assertSame(
            null,
            $this->resolve('app', 'asset_url', 'ASSET_URL', 'https://cdn.example.test'),
            'app.asset_url must not be defined (Laravel reads it for asset()/Vite)'
        );
    }

    private function resolve(string $file, string $path, string $envVar, ?string $value): mixed
    {
        $hadEnv = array_key_exists($envVar, $_ENV);
        $hadServer = array_key_exists($envVar, $_SERVER);
        $oldEnv = $_ENV[$envVar] ?? null;
        $oldServer = $_SERVER[$envVar] ?? null;
        $oldPut = getenv($envVar);

        try {
            if ($value === null) {
                unset($_ENV[$envVar], $_SERVER[$envVar]);
                putenv($envVar);
            } else {
                $_ENV[$envVar] = $_SERVER[$envVar] = $value;
                putenv("$envVar=$value");
            }

            /** @var array<string, mixed> $config */
            $config = require base_path("config/$file.php");

            return data_get($config, $path);
        } finally {
            unset($_ENV[$envVar], $_SERVER[$envVar]);
            if ($hadEnv) {
                $_ENV[$envVar] = $oldEnv;
            }
            if ($hadServer) {
                $_SERVER[$envVar] = $oldServer;
            }
            if ($oldPut === false) {
                putenv($envVar);
            } else {
                putenv("$envVar=$oldPut");
            }
        }
    }
}
