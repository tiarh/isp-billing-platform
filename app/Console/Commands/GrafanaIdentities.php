<?php

namespace App\Console\Commands;

use App\Services\Grafana\RouterIdentitySuggester;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GrafanaIdentities extends Command
{
    protected $signature = 'monitoring:grafana-identities
        {--apply : Write suggestions (only fills NULL values)}
        {--force : With --apply, also overwrite existing values}';

    protected $description = 'Suggest (dry-run) or apply routers.grafana_identity from Grafana labels';

    public function handle(RouterIdentitySuggester $suggester): int
    {
        $rows = $suggester->suggest();
        $apply = (bool) $this->option('apply');
        $force = (bool) $this->option('force');

        $table = [];
        $written = 0;
        foreach ($rows as $row) {
            $action = '-';
            if ($row['suggested'] !== null && ! preg_match('/^[A-Za-z0-9 ._:\/\[\]-]{1,96}$/', $row['suggested'])) {
                $table[] = [$row['router_id'], $row['router_name'], $row['current'] ?? '', $row['suggested'], $row['source'] ?? '', $row['in_mktxp'] ? 'ya' : 'tidak', 'dilewati (nilai tidak valid)'];

                continue;
            }
            if ($row['suggested'] !== null && $row['suggested'] !== $row['current']) {
                if ($row['current'] === null || $row['current'] === '') {
                    $action = $apply ? 'isi' : 'akan diisi';
                } else {
                    $action = $apply && $force ? 'timpa' : 'dilewati (sudah ada)';
                }
                if ($apply && in_array($action, ['isi', 'timpa'], true)) {
                    DB::table('routers')->where('id', $row['router_id'])->update(['grafana_identity' => $row['suggested']]);
                    $written++;
                }
            }
            $table[] = [
                $row['router_id'], $row['router_name'], $row['current'] ?? '', $row['suggested'] ?? '',
                $row['source'] ?? '', $row['in_mktxp'] ? 'ya' : 'tidak', $action,
            ];
        }

        $this->table(['ID', 'Router', 'Saat ini', 'Saran', 'Sumber', 'Di mktxp', 'Aksi'], $table);
        $this->info($apply ? "Ditulis: {$written}" : 'Dry-run: tidak ada yang ditulis. Gunakan --apply.');

        return self::SUCCESS;
    }
}
