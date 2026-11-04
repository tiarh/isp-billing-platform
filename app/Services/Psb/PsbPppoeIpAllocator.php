<?php

namespace App\Services\Psb;

use App\Models\PppoeAddressPoolBlock;
use App\Models\PsbDraft;
use App\Models\Router;
use App\Models\Setting;
use App\Services\MikrotikService;
use App\Services\PppoeAddressPoolAllocator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * PSB PPPoE IP suggestion and bookkeeping (moved verbatim from PsbOrchestratorService).
 * It uses the facade's own MikroTik instance and runs while the facade holds the router
 * lock and the connection, exactly as before. The facade keeps the overridable
 * suggestLiveIp seam and delegates here.
 */
final class PsbPppoeIpAllocator
{
    public function __construct(private MikrotikService $mikrotik) {}

    /**
     * Alokasi IP remote free dari RA-OLT.
     * - Cache 45 dtk map used-IP (PSB beruntun jauh lebih cepat, tetap aman di dalam lock router)
     * - alreadyConnected=true: jangan connect/disconnect lagi (satu sesi dengan add secret)
     *
     * @return array{local: ?string, remote: ?string}
     */
    public function suggestLiveIp(
        Router $router,
        bool $alreadyConnected = false,
        ?PsbDraft $draft = null,
        ?string $pppoeProfile = null
    ): array {
        $useStaticAllocator = $this->hasStaticAllocatorBlocks($router);
        // A5: router tanpa block master — jangan diam-diam pakai heuristik /24
        if (! $useStaticAllocator) {
            $requireBlocks = in_array(strtolower(trim((string) Setting::get('psb_require_static_allocator', '0'))), ['1', 'true', 'yes', 'on'], true);
            if ($requireBlocks) {
                throw new \RuntimeException("Router {$router->name} belum punya block address pool static. NOC: tambah block allocator dulu untuk router ini (mode heuristik dimatikan).");
            }
            Log::warning('[PSB] allocator blocks missing — fallback heuristik /24', ['router' => $router->id, 'name' => $router->name]);
            if ($draft) {
                $metaA5 = is_array($draft->meta) ? $draft->meta : [];
                $metaA5['ip_source'] = 'heuristic_dominant24';
                $draft->meta = $metaA5;
                // A connected caller saves the draft later, after it has disconnected.
                if (! $alreadyConnected) {
                    $draft->save();
                }
            }
        }
        $cacheKey = 'psb:ip-map:v1:'.$router->id;
        $map = Cache::get($cacheKey);

        if ($useStaticAllocator || ! is_array($map) || empty($map['used']) || empty($map['last_remote'])) {
            $timeout = ((int) ($router->total_pppoe_count ?? 0) >= 200) ? 28 : 18;
            $opened = false;
            if (! $alreadyConnected) {
                $this->mikrotik->connect($router, ['timeout' => $timeout, 'attempts' => 1]);
                $opened = true;
            }
            try {
                $secrets = $this->mikrotik->getPPPSecretsWithAddresses();
            } finally {
                if ($opened) {
                    $this->mikrotik->disconnect();
                }
            }

            if ($useStaticAllocator) {
                $allocated = app(PppoeAddressPoolAllocator::class)->allocate(
                    $router,
                    $secrets,
                    is_string($draft?->site_profile_key ?? null) ? (string) $draft->site_profile_key : null,
                    $pppoeProfile,
                    is_string($draft?->daerah ?? null) ? (string) $draft->daerah : null
                );
                if ($allocated !== null) {
                    return [
                        'local' => $allocated['local'],
                        'remote' => $allocated['remote'],
                        'block_id' => (int) ($allocated['block_id'] ?? 0),
                        'source' => 'allocator',
                    ];
                }
            }

            $usedRemotes = [];
            $withRemote = [];
            foreach ($secrets as $s) {
                $remote = $this->normalizeIp((string) ($s['remote_address'] ?? ''));
                $local = $this->normalizeIp((string) ($s['local_address'] ?? ''));
                if ($remote === '') {
                    continue;
                }
                $usedRemotes[$remote] = true;
                $withRemote[] = ['remote' => $remote, 'local' => $local];
            }
            if ($withRemote === []) {
                return ['local' => null, 'remote' => null];
            }
            usort($withRemote, fn ($a, $b) => ip2long($b['remote']) <=> ip2long($a['remote']));

            $prefixCounts = [];
            foreach ($withRemote as $row) {
                $p = $this->ipPrefix24($row['remote']);
                if ($p) {
                    $prefixCounts[$p] = ($prefixCounts[$p] ?? 0) + 1;
                }
            }
            arsort($prefixCounts);
            $dominant = (string) (array_key_first($prefixCounts) ?: '');
            $lastRemote = $withRemote[0]['remote'];
            foreach ($withRemote as $row) {
                if ($dominant !== '' && $this->ipPrefix24($row['remote']) !== $dominant) {
                    continue;
                }
                $lastRemote = $row['remote'];
                break;
            }

            $local = '';
            $localCounts = [];
            $prefix = $this->ipPrefix24($lastRemote);
            foreach ($withRemote as $row) {
                if ($prefix && $this->ipPrefix24($row['remote']) !== $prefix) {
                    continue;
                }
                if ($row['local'] !== '') {
                    $localCounts[$row['local']] = ($localCounts[$row['local']] ?? 0) + 1;
                }
            }
            if ($localCounts !== []) {
                arsort($localCounts);
                $local = (string) array_key_first($localCounts);
            } elseif ($prefix) {
                $local = $prefix.'.1';
            }

            $map = [
                'used' => $usedRemotes,
                'last_remote' => $lastRemote,
                'local' => $local,
            ];
            Cache::put($cacheKey, $map, 45);
        }

        $usedRemotes = $map['used'] ?? [];
        $lastRemote = (string) ($map['last_remote'] ?? '');
        $local = (string) ($map['local'] ?? '');
        if ($lastRemote === '') {
            return ['local' => null, 'remote' => null];
        }

        $suggested = $this->nextFreeRemoteIp($lastRemote, $usedRemotes);

        return ['local' => $local !== '' ? $local : null, 'remote' => $suggested];
    }

    /** A1: rollback pointer allocator bila write MT gagal (best-effort, log-only on error). */
    public function releaseAllocatedIpIfAny(Router $router, array $ip): void
    {
        if (empty($ip['block_id']) || empty($ip['remote'])) {
            return;
        }
        try {
            $released = app(PppoeAddressPoolAllocator::class)->releaseFailedAllocation($router, (int) $ip['block_id'], (string) $ip['remote']);
            if ($released) {
                Log::info('[PSB] allocator pointer rolled back after failed MT write', ['router' => $router->id, 'remote' => $ip['remote']]);
            }
        } catch (\Throwable $e) {
            Log::warning('[PSB] allocator pointer rollback failed', ['router' => $router->id, 'err' => $e->getMessage()]);
        }
    }

    public function hasStaticAllocatorBlocks(Router $router): bool
    {
        try {
            return Schema::hasTable('pppoe_address_pool_blocks')
                && PppoeAddressPoolBlock::query()
                    ->where('router_id', $router->id)
                    ->where('is_active', true)
                    ->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    public function isPppoeStaticAddressValid(string $address): bool
    {
        $address = trim($address);
        if ($address === '') {
            return false;
        }

        return (bool) filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            || (bool) preg_match('/^\d{1,3}\.\d{1,3}$/', $address);
    }

    /** Setelah secret sukses: tandai IP terpakai biar PSB berikutnya tidak collision. */
    public function markRemoteIpUsed(Router $router, string $remote, string $local = ''): void
    {
        $cacheKey = 'psb:ip-map:v1:'.$router->id;
        $map = Cache::get($cacheKey);
        if (! is_array($map)) {
            $map = ['used' => [], 'last_remote' => $remote, 'local' => $local];
        }
        $map['used'][$remote] = true;
        $map['last_remote'] = $remote;
        if ($local !== '') {
            $map['local'] = $local;
        }
        Cache::put($cacheKey, $map, 45);
    }

    public function normalizeIp(string $value): string
    {
        $value = trim($value);
        if ($value === '' || ! filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return '';
        }

        return $value;
    }

    public function ipPrefix24(string $ip): ?string
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return null;
        }
        $parts = explode('.', $ip);

        return $parts[0].'.'.$parts[1].'.'.$parts[2];
    }

    /** @param  array<string, bool>  $usedRemotes */
    public function nextFreeRemoteIp(string $from, array $usedRemotes): ?string
    {
        $from = $this->normalizeIp($from);
        $prefix = $this->ipPrefix24($from);
        if (! $prefix) {
            return null;
        }
        $parts = explode('.', $from);
        $start = (int) $parts[3];
        for ($oct = $start + 1; $oct <= 254; $oct++) {
            $c = $prefix.'.'.$oct;
            if (! isset($usedRemotes[$c])) {
                return $c;
            }
        }
        for ($oct = 2; $oct <= $start; $oct++) {
            $c = $prefix.'.'.$oct;
            if (! isset($usedRemotes[$c])) {
                return $c;
            }
        }

        return null;
    }
}
