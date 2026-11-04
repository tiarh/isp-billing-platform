<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\Grafana\GrafanaClient;
use Illuminate\Console\Command;

/**
 * Stopgap until the Monitoring > Pengaturan page exists: store the Grafana
 * connection in Settings. The token is read from a hidden prompt (never an
 * argument, so it stays out of shell history and process lists) and stored
 * encrypted.
 */
class GrafanaConfigure extends Command
{
    protected $signature = 'grafana:configure
        {--url= : Grafana base URL (default: services.grafana.url)}
        {--datasource-uid= : Prometheus datasource UID (default prom-main)}
        {--token : Prompt for the service-account token (hidden input)}
        {--test : Run a live connection test afterwards}';

    protected $description = 'Store Grafana URL / datasource UID / encrypted service-account token and optionally test the connection';

    public function handle(GrafanaClient $client): int
    {
        if ($url = trim((string) $this->option('url'))) {
            if (! preg_match('#^https?://#i', $url)) {
                $this->error('URL must start with http:// or https://');

                return self::FAILURE;
            }
            Setting::set(GrafanaClient::SETTING_URL, rtrim($url, '/'), 'text', 'monitoring', 'Grafana URL');
        }

        if ($uid = trim((string) $this->option('datasource-uid'))) {
            if (! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $uid)) {
                $this->error('Datasource UID may only contain letters, digits, - and _');

                return self::FAILURE;
            }
            Setting::set(GrafanaClient::SETTING_DATASOURCE_UID, $uid, 'text', 'monitoring', 'Grafana datasource UID');
        }

        if ($this->option('token')) {
            $token = (string) $this->secret('Grafana service-account token (Viewer)');
            if (trim($token) === '') {
                $this->error('Empty token, nothing stored.');

                return self::FAILURE;
            }
            Setting::setSecret(GrafanaClient::SETTING_TOKEN, $token, 'monitoring', 'Grafana service-account token');
            $this->info('Token stored (encrypted).');
        }

        if ($this->option('test')) {
            $result = $client->testConnection();
            $this->line(($result['ok'] ? 'OK: ' : 'FAIL: ').$result['message']);

            return $result['ok'] ? self::SUCCESS : self::FAILURE;
        }

        return self::SUCCESS;
    }
}
