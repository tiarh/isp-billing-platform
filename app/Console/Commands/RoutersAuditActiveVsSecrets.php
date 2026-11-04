<?php

namespace App\Console\Commands;

use App\Models\Router;
use App\Services\MikrotikService;
use Illuminate\Console\Command;
use RouterOS\Query;

/**
 * Audit Online vs Secret: unique actives, multi-session, orphan actives (no secret).
 * Read-only by default. --kick-multi removes extra sessions per username (keeps 1).
 */
class RoutersAuditActiveVsSecrets extends Command
{
    protected $signature = 'routers:audit-active-vs-secrets
                            {--router_id= : Single router}
                            {--kick-multi : Kick duplicate sessions (keep newest by uptime if available)}
                            {--limit=20 : Max sample lines per category}';

    protected $description = 'Audit unique active vs PPP secrets (orphan sessions / multi-login)';

    public function handle(MikrotikService $mt): int
    {
        $q = Router::query()->orderBy('name');
        if ($this->option('router_id')) {
            $q->where('id', (int) $this->option('router_id'));
        }
        $limit = max(5, (int) $this->option('limit'));
        $kick = (bool) $this->option('kick-multi');

        foreach ($q->get() as $router) {
            $this->info("=== {$router->name} ===");
            try {
                $mt->connect($router, ['timeout' => 25, 'attempts' => 1]);
                try {
                    $active = $mt->getActiveConnections(true);
                    $secrets = $mt->getPPPSecrets();
                    $secretNames = [];
                    foreach ($secrets as $s) {
                        $n = strtolower(trim((string) ($s['name'] ?? '')));
                        if ($n !== '') {
                            $secretNames[$n] = true;
                        }
                    }

                    $byUser = [];
                    foreach ($active as $row) {
                        $n = strtolower(trim((string) ($row['name'] ?? '')));
                        if ($n === '') {
                            continue;
                        }
                        $byUser[$n][] = $row;
                    }

                    $unique = count($byUser);
                    $sessionRows = count($active);
                    $multi = 0;
                    $orphan = [];
                    $multiSamples = [];
                    foreach ($byUser as $user => $rows) {
                        if (count($rows) > 1) {
                            $multi += count($rows) - 1;
                            if (count($multiSamples) < $limit) {
                                $multiSamples[] = $user.' x'.count($rows);
                            }
                        }
                        if (! isset($secretNames[$user])) {
                            $orphan[] = $user;
                        }
                    }

                    $this->line("sessions={$sessionRows} unique={$unique} secrets=".count($secretNames)
                        ." multi_extra={$multi} orphan_active=".count($orphan));
                    if ($unique > count($secretNames)) {
                        $this->warn('unique > secrets (orphan/RADIUS/deleted secret with live session)');
                    }
                    if ($multiSamples) {
                        $this->line('multi: '.implode(', ', $multiSamples));
                    }
                    if ($orphan) {
                        $this->line('orphan sample: '.implode(', ', array_slice($orphan, 0, $limit)));
                    }

                    if ($kick && $multi > 0) {
                        $kicked = 0;
                        foreach ($byUser as $user => $rows) {
                            if (count($rows) <= 1) {
                                continue;
                            }
                            // Keep first row; kickUser removes all actives for name — use carefully.
                            // Prefer: remove extras by .id if present.
                            usort($rows, function ($a, $b) {
                                return strcmp((string) ($b['uptime'] ?? ''), (string) ($a['uptime'] ?? ''));
                            });
                            $keep = array_shift($rows);
                            foreach ($rows as $extra) {
                                $id = $extra['.id'] ?? null;
                                if (! $id) {
                                    continue;
                                }
                                try {
                                    $q = (new Query('/ppp/active/remove'))->equal('.id', $id);
                                    $mt->getClient()->query($q)->read();
                                    $kicked++;
                                } catch (\Throwable $e) {
                                    $this->warn("kick fail {$user}: {$e->getMessage()}");
                                }
                            }
                            unset($keep);
                        }
                        $this->info("kicked_extra_sessions={$kicked}");
                    }
                } finally {
                    $mt->disconnect();
                }
            } catch (\Throwable $e) {
                $this->error('FAIL: '.$e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
