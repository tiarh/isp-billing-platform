<?php

namespace App\Console\Commands;

use App\Models\OltVlanMapping;
use App\Models\Package;
use App\Models\PppoeAddressPoolBlock;
use App\Models\RouterProfile;
use App\Models\RouterVlan;
use App\Services\PsbSiteRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class AuditPsbSites extends Command
{
    protected $signature = 'psb:sites-audit {--site= : Stable site key or alias}';

    protected $description = 'Audit PSB site/router/OLT/profile/VLAN/allocator mappings without device access or writes.';

    public function handle(PsbSiteRegistry $registry): int
    {
        $requested = trim((string) ($this->option('site') ?? ''));
        $sites = $requested !== ''
            ? array_filter([$registry->byKey($requested)])
            : array_map(fn (array $site): array => $registry->byKey((string) ($site['key'] ?? '')) ?? $site, $registry->all());

        if ($requested !== '' && $sites === []) {
            $this->error("PSB site tidak ditemukan: {$requested}");

            return self::FAILURE;
        }

        $this->table(
            ['Site', 'Router/OLT', 'Mode', 'VLAN', 'Package/Profile', 'Allocator', 'Issues'],
            array_map(fn (array $site): array => $this->row($site), array_values($sites))
        );
        $this->line('Read-only audit: tidak ada query live perangkat dan tidak ada write.');

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $site */
    private function row(array $site): array
    {
        $router = $site['router'] ?? null;
        $olt = $site['olt'] ?? null;
        $routerId = (int) ($site['router_id'] ?? 0);
        $oltId = (int) ($site['olt_id'] ?? 0);
        $issues = [];

        if ($routerId <= 0 || ! $router) {
            $issues[] = 'router_missing';
        }
        if ((string) ($site['mode'] ?? '') === PsbSiteRegistry::MODE_FULL_OLT && ($oltId <= 0 || ! $olt)) {
            $issues[] = 'olt_missing';
        }

        $vlans = [];
        if ($routerId > 0 && Schema::hasTable('router_vlans')) {
            $vlans = RouterVlan::query()
                ->where('router_id', $routerId)
                ->where('is_active', true)
                ->orderBy('vlan')
                ->pluck('vlan')
                ->map(fn ($v): string => (string) $v)
                ->all();
        }
        if ($oltId > 0 && Schema::hasTable('olt_vlan_mappings')) {
            $vlans = array_values(array_unique(array_merge(
                $vlans,
                OltVlanMapping::query()
                    ->where('olt_id', $oltId)
                    ->where('is_active', true)
                    ->orderBy('vlan')
                    ->pluck('vlan')
                    ->map(fn ($v): string => (string) $v)
                    ->all()
            )));
        }
        if ($vlans === []) {
            $issues[] = 'vlan_unmapped';
        }

        $profiles = $routerId > 0
            ? RouterProfile::query()->where('router_id', $routerId)->orderBy('name')->pluck('name')->all()
            : [];
        $packages = $routerId > 0
            ? Package::query()->where('is_active', true)->where(function ($query) use ($routerId): void {
                $query->whereNull('router_id')->orWhere('router_id', $routerId);
            })->orderBy('name')->get(['name', 'mikrotik_profile'])->map(function (Package $package): string {
                $profile = trim((string) $package->mikrotik_profile);

                return $package->name.($profile !== '' ? " [{$profile}]" : ' [profile_missing]');
            })->all()
            : [];
        if ($profiles === [] && $packages === []) {
            $issues[] = 'profile_package_unmapped';
        }

        $blocks = [];
        if ($routerId > 0 && Schema::hasTable('pppoe_address_pool_blocks')) {
            $blocks = PppoeAddressPoolBlock::query()
                ->where('router_id', $routerId)
                ->where('is_active', true)
                ->orderBy('priority')
                ->orderBy('block_index')
                ->get(['block_name', 'site_profile_key', 'pppoe_profile', 'available_count', 'warning_threshold'])
                ->map(function (PppoeAddressPoolBlock $block): string {
                    $name = trim((string) ($block->block_name ?: $block->site_profile_key ?: 'block'));
                    $available = (int) ($block->available_count ?? 0);
                    $warning = (int) ($block->warning_threshold ?? 0);

                    return sprintf('%s:%d%s', $name, $available, $available <= $warning ? '!' : '');
                })->all();
        }
        if ($blocks === []) {
            $issues[] = 'allocator_block_missing';
        }

        return [
            (string) ($site['key'] ?? '-'),
            trim((string) ($router?->name ?? '-').' / '.(string) ($olt?->name ?? '-')),
            (string) ($site['mode'] ?? '-'),
            $vlans === [] ? '-' : implode(',', $vlans),
            $this->compact($packages !== [] ? $packages : $profiles),
            $blocks === [] ? '-' : $this->compact($blocks),
            $issues === [] ? 'OK' : implode('|', $issues),
        ];
    }

    /** @param array<int, string> $values */
    private function compact(array $values): string
    {
        $values = array_values(array_filter(array_map('strval', $values), fn (string $value): bool => trim($value) !== ''));

        return count($values) > 4
            ? implode(', ', array_slice($values, 0, 4)).' +'.(count($values) - 4)
            : ($values === [] ? '-' : implode(', ', $values));
    }
}
