<?php

namespace App\Services;

use App\Exceptions\PsbLockBusyException;
use App\Models\PppoeAddressPoolBlock;
use App\Models\Router;
use App\Support\CacheLockWait;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class PppoeAddressPoolAllocator
{
    /**
     * Reconcile existing allocator blocks from read-only MikroTik secret rows.
     *
     * @param  list<array<string, mixed>>  $liveSecrets
     * @return list<array<string, mixed>>
     */
    public function reconcileBlocks(Router $router, array $liveSecrets, bool $dryRun = false): array
    {
        if (! Schema::hasTable('pppoe_address_pool_blocks')) {
            return [];
        }

        $used = $this->usedRemoteSet($liveSecrets);
        $blocks = PppoeAddressPoolBlock::query()
            ->where('router_id', $router->id)
            ->where('is_active', true)
            ->where('allocator_mode', 'last_remote_pointer')
            ->orderBy('priority')
            ->orderBy('block_index')
            ->orderBy('id')
            ->get();

        $now = now();
        $rows = [];

        foreach ($blocks as $block) {
            $format = $block->address_format === 'short_static' ? 'short_static' : 'full_ipv4';
            $range = $this->rangeParts((string) $block->remote_start, (string) $block->remote_end, $format);
            if ($range === null) {
                $rows[] = [
                    'block_id' => $block->id,
                    'block_name' => $block->block_name,
                    'status' => 'invalid_range',
                    'available_count' => (int) ($block->available_count ?? 0),
                ];

                continue;
            }

            $usedHosts = $this->usedHostsInRange($used, $range['prefix'], $format);
            $capacity = max(0, $range['end'] - $range['start'] + 1);
            $usedCount = count(array_filter(array_keys($usedHosts), fn (int $host) => $host >= $range['start'] && $host <= $range['end']));
            $liveHighest = $usedHosts === [] ? null : max(array_keys($usedHosts));

            $dbLast = $block->last_remote_address
                ? $this->addressParts((string) $block->last_remote_address, $format)
                : null;
            $dbHost = ($dbLast !== null && $dbLast['prefix'] === $range['prefix']) ? $dbLast['host'] : null;
            $candidates = array_filter([$liveHighest, $dbHost], fn ($value) => $value !== null);
            $lastHost = $candidates === [] ? null : max($candidates);
            $lastRemote = $lastHost === null ? null : $this->formatAddress($range['prefix'], (int) $lastHost, $format);
            $source = $liveHighest === null
                ? 'empty_live_read'
                : (($dbHost !== null && $dbHost > $liveHighest) ? 'db_pointer_guard' : 'live_mikrotik');
            $available = max(0, $capacity - $usedCount);
            $warning = (int) ($block->warning_threshold ?? 0);

            if (! $dryRun) {
                $block->forceFill([
                    'capacity' => $capacity,
                    'used_count' => min($capacity, $usedCount),
                    'available_count' => $available,
                    'last_remote_address' => $lastRemote,
                    'last_remote_source' => $source,
                    'last_remote_synced_at' => $now,
                    'last_synced_at' => $now,
                ])->save();
            }

            $rows[] = [
                'block_id' => $block->id,
                'block_name' => $block->block_name,
                'block_index' => (int) $block->block_index,
                'local_address' => $block->local_address,
                'remote_start' => $block->remote_start,
                'remote_end' => $block->remote_end,
                'capacity' => $capacity,
                'used_count' => min($capacity, $usedCount),
                'available_count' => $available,
                'warning_threshold' => $warning,
                'last_remote_address' => $lastRemote,
                'last_remote_source' => $source,
                'status' => $available <= 0 ? 'full' : (($warning > 0 && $available <= $warning) ? 'warning' : 'ok'),
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $liveSecrets
     * @return array{local:string,remote:string,source:string,block_id:int,block_index:int,address_format:string}|null
     */
    public function allocate(
        Router $router,
        array $liveSecrets = [],
        ?string $siteProfileKey = null,
        ?string $pppoeProfile = null,
        ?string $customerArea = null
    ): ?array {
        if (! Schema::hasTable('pppoe_address_pool_blocks')) {
            return null;
        }

        if ($this->matchingBlocks($router, $siteProfileKey, $pppoeProfile, $customerArea)->isEmpty()) {
            return null;
        }

        $lock = Cache::lock('pppoe-address-pool-router-'.$router->id, 60);
        if (! CacheLockWait::acquire($lock, 20)) {
            throw new PsbLockBusyException('Allocator PPPoE sedang dipakai. Coba ulang beberapa detik lagi.');
        }

        try {
            return DB::transaction(function () use ($router, $liveSecrets, $siteProfileKey, $pppoeProfile, $customerArea) {
                $blocks = $this->matchingBlocks($router, $siteProfileKey, $pppoeProfile, $customerArea, true);

                if ($blocks->isEmpty()) {
                    return null;
                }

                $used = $this->usedRemoteSet($liveSecrets);
                // A6: used-set kosong padahal live-read seharusnya berisi = tidak reliable →
                // fase hole-reuse dilewati (pointer-forward saja, aman dari collision).
                $allowHoles = $used !== [];

                // Fase 1: pointer-forward semua block (perilaku spec Sprint 1.1:
                // .254 → lanjut block berikutnya). Fase 2 (A2): hanya kalau SEMUA block
                // pointer-forward habis, reuse hole bekas dismantle berdasarkan used-set live.
                foreach (['forward', 'holes'] as $phase) {
                    if ($phase === 'holes' && ! $allowHoles) {
                        break;
                    }
                    $visited = [];
                    $block = $blocks->first();
                    while ($block) {
                        if (isset($visited[$block->id])) {
                            break;
                        }
                        $visited[$block->id] = true;

                        $allocation = $this->nextInBlock($block, $used, $phase === 'holes');
                        if ($allocation !== null) {
                            $this->syncBlockCounters($block, $used, $allocation['remote']);

                            return $allocation;
                        }

                        if ($phase === 'forward') {
                            $this->syncBlockCounters($block, $used);
                        }
                        $block = $this->nextBlock($blocks, $block);
                    }
                }

                throw new \RuntimeException('Blok PPPoE penuh di router '.$router->name.'. Minta NOC tambah block address pool static untuk router ini.');
            }, 3);
        } finally {
            $lock->release();
        }
    }

    /**
     * True bila router punya block pool aktif (mode pointer). Dipakai caller untuk
     * fail-closed: router ber-pool yang tidak menghasilkan alokasi berarti filter
     * site/profile/area tidak cocok, bukan router tanpa pool.
     */
    public function hasActiveBlocks(Router $router): bool
    {
        if (! Schema::hasTable('pppoe_address_pool_blocks')) {
            return false;
        }

        return PppoeAddressPoolBlock::query()
            ->where('router_id', $router->id)
            ->where('is_active', true)
            ->where('allocator_mode', 'last_remote_pointer')
            ->exists();
    }

    /**
     * A1: rollback pointer bila write secret ke MikroTik GAGAL setelah alokasi,
     * supaya IP tidak bocor permanen. Hanya mundur bila pointer masih menunjuk
     * remote yang gagal (belum ada alokasi lain sesudahnya).
     */
    public function releaseFailedAllocation(Router $router, int $blockId, string $failedRemote): bool
    {
        if ($blockId <= 0 || trim($failedRemote) === '' || ! Schema::hasTable('pppoe_address_pool_blocks')) {
            return true;
        }

        $lock = Cache::lock('pppoe-address-pool-router-'.$router->id, 60);
        if (! CacheLockWait::acquire($lock, 10)) {
            // Best-effort; the daily reconcile fixes the pointer. Never throw here:
            // the caller is handling the original MikroTik error.
            Log::warning('[PPPoE pool] release allocation skipped, allocator busy', [
                'router' => $router->id,
                'block' => $blockId,
            ]);

            return false;
        }

        try {
            DB::transaction(function () use ($blockId, $failedRemote) {
                $block = PppoeAddressPoolBlock::query()->whereKey($blockId)->lockForUpdate()->first();
                if (! $block) {
                    return;
                }
                $clean = $this->cleanAddress($failedRemote);
                if ($this->cleanAddress((string) $block->last_remote_address) !== $clean) {
                    return; // pointer sudah maju karena alokasi lain — jangan diganggu
                }
                $format = $block->address_format === 'short_static' ? 'short_static' : 'full_ipv4';
                $range = $this->rangeParts((string) $block->remote_start, (string) $block->remote_end, $format);
                $parts = $this->addressParts($clean, $format);
                $prev = null;
                if ($range !== null && $parts !== null && ($parts['host'] - 1) >= $range['start']) {
                    $prev = $this->formatAddress($range['prefix'], $parts['host'] - 1, $format);
                }
                $block->forceFill([
                    'last_remote_address' => $prev,
                    'last_remote_source' => 'rollback_failed_write',
                    'last_remote_synced_at' => now(),
                ])->save();
            }, 3);

            return true;
        } finally {
            $lock->release();
        }
    }

    /**
     * A4: filter block di PHP dengan normalisasi area (strip skynet/sky-net/spasi)
     * supaya block `kalirejo` match `Daerah: Skynet-Kalirejo`.
     *
     * @return Collection<int, PppoeAddressPoolBlock>
     */
    private function matchingBlocks(
        Router $router,
        ?string $siteProfileKey,
        ?string $pppoeProfile,
        ?string $customerArea,
        bool $forUpdate = false
    ): Collection {
        $query = PppoeAddressPoolBlock::query()
            ->where('router_id', $router->id)
            ->where('is_active', true)
            ->where('allocator_mode', 'last_remote_pointer')
            ->orderBy('priority')
            ->orderBy('block_index')
            ->orderBy('id');
        if ($forUpdate) {
            $query->lockForUpdate();
        }
        $all = $query->get();

        $site = $this->normKey($siteProfileKey);
        $profile = $this->normKey($pppoeProfile);
        $area = $this->normAreaKey($customerArea);

        return $all->filter(function (PppoeAddressPoolBlock $block) use ($site, $profile, $area) {
            $blockSite = $this->normKey($block->site_profile_key);
            $blockProfile = $this->normKey($block->pppoe_profile);
            $blockArea = $this->normAreaKey($block->customer_area);

            return ($blockSite === null || ($site !== null && $blockSite === $site))
                && ($blockProfile === null || ($profile !== null && $blockProfile === $profile))
                && ($blockArea === null || ($area !== null && $blockArea === $area));
        })->values();
    }

    /** @param list<array<string, mixed>> $liveSecrets */
    private function usedRemoteSet(array $liveSecrets): array
    {
        $used = [];
        foreach ($liveSecrets as $secret) {
            $remote = $this->cleanAddress((string) ($secret['remote_address'] ?? ''));
            if ($remote !== '') {
                $used[$remote] = true;
            }
        }

        return $used;
    }

    /** @return array{local:string,remote:string,source:string,block_id:int,block_index:int,address_format:string}|null */
    private function nextInBlock(PppoeAddressPoolBlock $block, array $used, bool $holesMode = false): ?array
    {
        $format = $block->address_format === 'short_static' ? 'short_static' : 'full_ipv4';
        $range = $this->rangeParts((string) $block->remote_start, (string) $block->remote_end, $format);
        if ($range === null) {
            return null;
        }

        // A3: used-set diparse dual-format (live MT bisa full_ipv4 walau block short_static)
        $usedHosts = $this->usedHostsInRange($used, $range['prefix'], $format);
        $pick = null;
        $lastRemoteSource = 'live_mikrotik';

        if ($holesMode) {
            // A2 (fase 2): semua block pointer-forward sudah habis — reuse hole
            // bekas dismantle berdasarkan used-set live (pertama yang free dari bawah).
            for ($host = $range['start']; $host <= $range['end']; $host++) {
                if (! isset($usedHosts[$host])) {
                    $pick = $host;
                    $lastRemoteSource = 'hole_reuse_live';
                    break;
                }
            }
        } else {
            $lastHost = $range['start'] - 1;
            $liveHighest = $usedHosts === [] ? null : max(array_keys($usedHosts));
            if ($liveHighest !== null) {
                $lastHost = max($lastHost, $liveHighest);
            } elseif ($block->last_remote_address) {
                $lastRemoteSource = (string) ($block->last_remote_source ?: 'db_pointer');
            }
            if ($block->last_remote_address) {
                $dbLast = $this->addressParts((string) $block->last_remote_address, $format);
                if ($dbLast !== null && $dbLast['prefix'] === $range['prefix'] && $dbLast['host'] > $lastHost) {
                    $lastHost = $dbLast['host'];
                    $lastRemoteSource = (string) ($block->last_remote_source ?: 'db_pointer');
                }
            }

            for ($host = max($range['start'], $lastHost + 1); $host <= $range['end']; $host++) {
                if (isset($usedHosts[$host])) {
                    continue;
                }
                $pick = $host;
                break;
            }
        }

        if ($pick === null) {
            return null;
        }

        $remote = $this->formatAddress($range['prefix'], $pick, $format);
        $block->forceFill([
            'last_remote_address' => $remote,
            'last_remote_source' => $lastRemoteSource,
            'last_remote_synced_at' => now(),
            'last_synced_at' => now(),
        ])->save();

        return [
            'local' => (string) $block->local_address,
            'remote' => $remote,
            'source' => 'pppoe_address_pool_blocks',
            'block_id' => (int) $block->id,
            'block_index' => (int) $block->block_index,
            'address_format' => $format,
        ];
    }

    /**
     * @param  array<string, true>  $used
     * @return array<int, true>
     */
    private function usedHostsInRange(array $used, string $prefix, string $format): array
    {
        $hosts = [];
        foreach (array_keys($used) as $remote) {
            $host = $this->hostForPrefix((string) $remote, $prefix, $format);
            if ($host !== null) {
                $hosts[$host] = true;
            }
        }

        return $hosts;
    }

    /** A3: coba format block dulu, lalu format satunya (mapping via oktet ke-3). */
    private function hostForPrefix(string $remote, string $prefix, string $format): ?int
    {
        $native = $this->addressParts($remote, $format);
        if ($native !== null && $native['prefix'] === $prefix) {
            return $native['host'];
        }

        if ($format === 'short_static') {
            $full = $this->addressParts($remote, 'full_ipv4');
            if ($full !== null) {
                $octets = explode('.', $full['prefix']);
                if ((string) (int) end($octets) === $prefix) {
                    return $full['host'];
                }
            }
        } else {
            $short = $this->addressParts($remote, 'short_static');
            if ($short !== null) {
                $octets = explode('.', $prefix);
                if ((int) $short['prefix'] === (int) end($octets)) {
                    return $short['host'];
                }
            }
        }

        return null;
    }

    /** @param Collection<int, PppoeAddressPoolBlock> $blocks */
    private function nextBlock(Collection $blocks, PppoeAddressPoolBlock $current): ?PppoeAddressPoolBlock
    {
        if ($current->next_block_index !== null) {
            $next = $blocks->first(fn (PppoeAddressPoolBlock $block) => (int) $block->block_index === (int) $current->next_block_index);
            if ($next) {
                return $next;
            }
        }

        return $blocks
            ->filter(fn (PppoeAddressPoolBlock $block) => (int) $block->block_index > (int) $current->block_index)
            ->first();
    }

    private function syncBlockCounters(PppoeAddressPoolBlock $block, array $used, ?string $allocatedRemote = null): void
    {
        if ($allocatedRemote !== null) {
            $used[$allocatedRemote] = true;
        }

        $format = $block->address_format === 'short_static' ? 'short_static' : 'full_ipv4';
        $range = $this->rangeParts((string) $block->remote_start, (string) $block->remote_end, $format);
        if ($range === null) {
            return;
        }

        $capacity = max(0, $range['end'] - $range['start'] + 1);
        $usedCount = 0;
        foreach (array_keys($this->usedHostsInRange($used, $range['prefix'], $format)) as $host) {
            if ($host >= $range['start'] && $host <= $range['end']) {
                $usedCount++;
            }
        }

        $block->forceFill([
            'capacity' => $capacity,
            'used_count' => min($capacity, $usedCount),
            'available_count' => max(0, $capacity - $usedCount),
            'last_synced_at' => now(),
        ])->save();
    }

    /** @return array{prefix:string,start:int,end:int}|null */
    private function rangeParts(string $start, string $end, string $format): ?array
    {
        $startParts = $this->addressParts($start, $format);
        $endParts = $this->addressParts($end, $format);
        if (! $startParts || ! $endParts || $startParts['prefix'] !== $endParts['prefix']) {
            return null;
        }

        return [
            'prefix' => $startParts['prefix'],
            'start' => min($startParts['host'], $endParts['host']),
            'end' => max($startParts['host'], $endParts['host']),
        ];
    }

    /** @return array{prefix:string,host:int}|null */
    private function addressParts(string $address, string $format): ?array
    {
        $address = $this->cleanAddress($address);
        if ($format === 'short_static') {
            if (! preg_match('/^(\d{1,3})\.(\d{1,3})$/', $address, $m)) {
                return null;
            }

            return ['prefix' => (string) ((int) $m[1]), 'host' => (int) $m[2]];
        }

        if (! preg_match('/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/', $address, $m)) {
            return null;
        }

        foreach (array_slice($m, 1) as $part) {
            if ((int) $part > 255) {
                return null;
            }
        }

        return ['prefix' => ((int) $m[1]).'.'.((int) $m[2]).'.'.((int) $m[3]), 'host' => (int) $m[4]];
    }

    private function formatAddress(string $prefix, int $host, string $format): string
    {
        return $format === 'short_static'
            ? $prefix.'.'.$host
            : $prefix.'.'.$host;
    }

    private function cleanAddress(string $address): string
    {
        $address = trim($address);
        $address = preg_replace('#/\d+$#', '', $address) ?? $address;
        $address = preg_replace('/\s+.*/', '', $address) ?? $address;

        return trim($address);
    }

    private function normKey(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : strtolower($value);
    }

    /** A4: normalisasi area — `Skynet-Kalirejo` match block `kalirejo`. */
    private function normAreaKey(?string $value): ?string
    {
        $v = strtolower(trim((string) $value));
        if ($v === '') {
            return null;
        }
        $v = str_replace(['sky-net', 'skynet'], '', $v);
        $v = preg_replace('/[^a-z0-9]+/', '', $v) ?? $v;

        return $v === '' ? null : $v;
    }
}
