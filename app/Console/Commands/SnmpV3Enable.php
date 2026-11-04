<?php

namespace App\Console\Commands;

use App\Models\Router;
use App\Services\MikrotikService;
use Illuminate\Console\Command;
use RouterOS\Query;

/**
 * One-time rollout: enable SNMP (v3 read-only) on every active router so the
 * VM107 traffic collector can read byte counters without the RouterOS API
 * (plaintext, write-capable credentials).
 *
 * Dry run by default. --apply writes. Idempotent: an existing SNMP entry with
 * the same name is updated, never duplicated; SNMP is never disabled here.
 */
class SnmpV3Enable extends Command
{
    protected $signature = 'snmp:v3-enable
        {--apply : Write to routers (default: dry run)}
        {--router= : Limit to one router id}
        {--user=ebilling-snmp-readonly : SNMP v3 user (community entry name)}
        {--auth-pass= : SHA auth passphrase (generated when omitted)}
        {--priv-pass= : AES priv passphrase (generated when omitted)}';

    protected $description = 'Enable SNMP v3 read-only on active routers (backup + idempotent)';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $user = (string) $this->option('user');
        $authPass = (string) $this->option('auth-pass');
        $privPass = (string) $this->option('priv-pass');
        $routerId = (int) $this->option('router') ?: null;

        if ($apply && ($authPass === '' || $privPass === '')) {
            $authPass = $this->passphrase(24);
            $privPass = $this->passphrase(24);
            $this->warn('Passphrase SNMP dibuat otomatis (disimpan ke storage/app/snmp/).');
        }

        $this->line($apply ? 'MODE=APPLY' : 'MODE=DRY-RUN');

        $routers = Router::query()
            ->where('is_active', true)
            ->when($routerId, fn ($q) => $q->where('id', $routerId))
            ->orderBy('id')
            ->get();

        if ($routers->isEmpty()) {
            $this->error('Tidak ada router aktif.');

            return self::FAILURE;
        }

        $this->line('Router: '.$routers->count().' · user='.$user);
        $ok = 0;
        $failed = [];

        foreach ($routers as $router) {
            try {
                $service = app(MikrotikService::class)->connect($router, ['timeout' => 12, 'attempts' => 1]);
                $this->configureRouter($service, $router, $apply, $user, $authPass, $privPass);
                $ok++;
                $service->disconnect();
            } catch (\Throwable $e) {
                $failed[] = '#'.$router->id.' '.$router->name.': '.$e->getMessage();
                $this->line('  FAIL #'.$router->id.' '.$router->name.': '.$e->getMessage());
            }
        }

        $this->newLine();
        $this->info('ok='.$ok.' failed='.count($failed));

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }

    private function configureRouter(
        MikrotikService $service,
        Router $router,
        bool $apply,
        string $user,
        string $authPass,
        string $privPass,
    ): void {
        $before = $this->snapshot($service);
        $this->line('  #'.$router->id.' '.$router->name.' snmp_enabled='.($before['enabled'] ? 'yes' : 'no')
            .' entries='.count($before['entries']));

        if (! $apply) {
            $this->line('    would: /snmp set enabled=yes; /snmp/community upsert name='.$user);

            return;
        }

        $this->storeBackup($router, $before);
        $this->enableSnmp($service);
        $this->upsertCommunity($service, $user, $authPass, $privPass);

        $after = $this->snapshot($service);
        if (! $after['enabled']) {
            throw new \RuntimeException('SNMP masih disabled setelah apply');
        }
        if (! collect($after['entries'])->contains(fn ($e) => strcasecmp((string) ($e['name'] ?? ''), $user) === 0)) {
            throw new \RuntimeException('SNMP entry '.$user.' tidak ditemukan setelah apply');
        }
        $this->line('    ok: enabled + entry '.$user.' terpasang');
    }

    /** @return array{enabled: bool, entries: list<array<string,mixed>>} */
    private function snapshot(MikrotikService $service): array
    {
        $enabled = false;
        $entries = [];

        try {
            $res = $service->client()->query(new Query('/snmp/print'))->read();
            // /snmp/print returns a single associative map, not a list of rows.
            if (is_array($res) && array_key_exists('enabled', $res)) {
                $enabled = in_array(strtolower((string) $res['enabled']), ['true', 'yes'], true);
            } else {
                foreach ((array) $res as $row) {
                    if (is_array($row) && array_key_exists('enabled', $row)) {
                        $enabled = in_array(strtolower((string) $row['enabled']), ['true', 'yes'], true);
                        break;
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->line('    warn: /snmp/print gagal: '.$e->getMessage());
        }

        try {
            $res = $service->client()->query(new Query('/snmp/community/print'))->read();
            foreach ((array) $res as $row) {
                if (is_array($row) && isset($row['name'])) {
                    $entries[] = ['name' => $row['name'], 'read-access' => $row['read-access'] ?? null];
                }
            }
        } catch (\Throwable $e) {
            $this->line('    warn: /snmp/community/print gagal: '.$e->getMessage());
        }

        return ['enabled' => $enabled, 'entries' => $entries];
    }

    private function enableSnmp(MikrotikService $service): void
    {
        $res = $service->client()?->query((new Query('/snmp/set'))->equal('enabled', 'yes'))->read() ?? [];
        $message = is_array($res) ? (string) ($res['after']['message'] ?? '') : '';
        if ($message !== '') {
            throw new \RuntimeException('Router menolak /snmp/set: '.$message);
        }
    }

    private function upsertCommunity(MikrotikService $service, string $user, string $authPass, string $privPass): void
    {
        $entries = $service->client()?->query(new Query('/snmp/community/print'))->read() ?? [];
        $existingId = null;
        foreach ((array) $entries as $row) {
            if (is_array($row) && strcasecmp((string) ($row['name'] ?? ''), $user) === 0) {
                $existingId = $row['.id'] ?? null;
                break;
            }
        }

        $query = new Query($existingId !== null ? '/snmp/community/set' : '/snmp/community/add');
        if ($existingId !== null) {
            $query->equal('.id', $existingId);
        }
        $query->equal('name', $user)
            ->equal('authentication-password', $authPass)
            ->equal('authentication-protocol', 'SHA')
            ->equal('encryption-password', $privPass)
            ->equal('encryption-protocol', 'AES')
            ->equal('security', 'privacy')
            ->equal('read-access', 'yes')
            ->equal('write-access', 'no');

        $res = $service->client()?->query($query)->read();

        // RouterOS rejects an invalid value with a !trap that this client still
        // surfaces as a normal response (after.message). Never treat that as success.
        $message = is_array($res) ? (string) ($res['after']['message'] ?? '') : '';
        if ($message !== '') {
            throw new \RuntimeException('Router menolak perubahan SNMP: '.$message);
        }
    }

    private function storeBackup(Router $router, array $before): string
    {
        $dir = storage_path('app/snmp-backup');
        if (! is_dir($dir) && ! mkdir($dir, 0750, true) && ! is_dir($dir)) {
            throw new \RuntimeException('Gagal membuat direktori backup SNMP');
        }

        $path = $dir.'/snmp-before-'.$router->id.'-'.date('Ymd-His').'.json';
        file_put_contents($path, json_encode([
            'router_id' => $router->id,
            'router_name' => $router->name,
            'captured_at' => now()->toIso8601String(),
            'snmp' => $before,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->line('    backup: '.$path);

        return $path;
    }

    private function passphrase(int $length): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%^&*-_=+';
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $out;
    }
}
