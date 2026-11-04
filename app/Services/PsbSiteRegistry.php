<?php

namespace App\Services;

use App\Models\Olt;
use App\Models\Router;
use App\Models\Setting;

/**
 * Canonical PSB sites: stable key → router_id + olt_id + mode.
 * Avoids fragile name-LIKE double-MT (Krian vs Skynet-Krian-OLT).
 *
 * Override via Setting JSON `psb_sites` (list of site objects).
 */
class PsbSiteRegistry
{
    public const MODE_FULL_OLT = 'full_olt';

    public const MODE_PPPOE_ONLY = 'pppoe_only';

    public const MODE_PPPOE_VLAN = 'pppoe_vlan';

    public const MODE_HUAWEI_OLT = 'huawei_olt';

    /**
     * Built-in production defaults (ids verified 2026-07).
     * Overridden by Setting::psb_sites when present.
     *
     * @return list<array{
     *   key: string,
     *   name: string,
     *   aliases: list<string>,
     *   router_id: int|null,
     *   olt_id: int|null,
     *   mode: string,
     *   daily_target: int,
     *   is_active: bool
     * }>
     */
    public function builtinSites(): array
    {
        return [
            [
                'key' => 'ra',
                'name' => 'RA / Randuagung C300',
                'aliases' => ['ra', 'raolt', 'randuagung', 'sky net-ra', 'skynet-ra', 'skynet-ra-olt'],
                'router_id' => 513, // SKYNET-RA-OLT
                'olt_id' => 1,      // OLT RA
                'mode' => self::MODE_FULL_OLT,
                'daily_target' => 20, // KPI target 20/hari KHUSUS Project Randuagung
                'is_active' => true,
            ],
            [
                'key' => 'krian',
                'name' => 'Krian C300',
                'aliases' => ['krian', 'krianolt', 'skynetkrian', 'skynet-krian', 'skynet-krian-olt'],
                // STEP 2026-09-30 (owner): Krian uplink moves to Skynet-Krian-OLT.
                // PSB + migration target is now router 514 (OLT KRIAN managed via it).
                'router_id' => 514,
                'olt_id' => 9, // OLT KRIAN (zte_c300)
                'mode' => self::MODE_FULL_OLT,
                'daily_target' => 0,
                'is_active' => true,
            ],
            // Hioso OLT sites
            [
                'key' => 'bumiayu',
                'name' => 'Bumiayu Hioso',
                'aliases' => ['bumiayu'],
                'vendor' => 'hioso',
                'router_id' => null,
                'olt_id' => null,
                'mode' => self::MODE_PPPOE_VLAN,
                'router_match' => ['bumiayu'],
                'daily_target' => 0,
                'is_active' => true,
            ],
            [
                'key' => 'gadang',
                'name' => 'Gadang Hioso',
                'aliases' => ['gadang-hioso', 'gadang_hioso'],
                'vendor' => 'hioso',
                'router_id' => 511, // SKYNET-GADANG; explicit official alias target
                'olt_id' => null,
                'mode' => self::MODE_PPPOE_VLAN,
                'router_match' => ['gadang'],
                'daily_target' => 0,
                'is_active' => true,
            ],
            [
                'key' => 'gadang_huawei',
                'name' => 'Gadang Huawei MA5608T',
                'aliases' => ['gadang', 'gadang-huawei', 'gadang_huawei', 'olt-gadang-3', 'olt gadang 3'],
                'vendor' => 'huawei_ma5608t',
                'router_id' => 511, // SKYNET-GADANG
                'olt_id' => 11,      // OLT GADANG 3
                'mode' => self::MODE_HUAWEI_OLT,
                'router_match' => ['gadang'],
                'olt_match' => ['olt gadang 3', 'gadang 3'],
                'daily_target' => 0,
                'is_active' => true,
            ],
            [
                'key' => 'martopuro',
                'name' => 'Martopuro Hioso',
                'aliases' => ['martopuro'],
                'vendor' => 'hioso',
                'router_id' => null,
                'olt_id' => null,
                'mode' => self::MODE_PPPOE_VLAN,
                'router_match' => ['martopuro'],
                'daily_target' => 0,
                'is_active' => true,
            ],
            [
                'key' => 'mangliawan',
                'name' => 'Mangliawan Hioso',
                'aliases' => ['mangliawan'],
                'vendor' => 'hioso',
                'router_id' => null,
                'olt_id' => null,
                'mode' => self::MODE_PPPOE_VLAN,
                'router_match' => ['mangliawan'],
                'daily_target' => 0,
                'is_active' => true,
            ],
            [
                'key' => 'srigading',
                'name' => 'Srigading Hioso',
                'aliases' => ['srigading'],
                'vendor' => 'hioso',
                'router_id' => null,
                'olt_id' => null,
                'mode' => self::MODE_PPPOE_VLAN,
                'router_match' => ['srigading'],
                'daily_target' => 0,
                'is_active' => true,
            ],
            // HTB = hanya path MikroTik CCR Randuagung (bukan Hioso)
            [
                'key' => 'rdg',
                'name' => 'Randuagung CCR (HTB / MT only)',
                'aliases' => ['rdg', 'randuagung-mt', 'randuagung-ccr', 'htb-rdg', 'htb'],
                'vendor' => 'htb',
                'router_id' => null,
                'olt_id' => null,
                'mode' => self::MODE_PPPOE_VLAN,
                'router_match' => ['randuagung'],
                'daily_target' => 0,
                'is_active' => true,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    /** @var list<array<string, mixed>>|null */
    private ?array $allCache = null;

    /** @var array<string, array<string, mixed>> */
    private array $hydrateCache = [];

    public function all(): array
    {
        if ($this->allCache !== null) {
            return $this->allCache;
        }

        $extra = Setting::get('psb_sites', null);
        if (is_string($extra)) {
            $extra = json_decode($extra, true);
        }
        $list = $this->builtinSites();
        if (is_array($extra) && $extra !== []) {
            // Override by key: settings first
            $byKey = [];
            foreach ($list as $s) {
                $byKey[(string) $s['key']] = $s;
            }
            foreach ($extra as $s) {
                if (! is_array($s) || empty($s['key'])) {
                    continue;
                }
                $k = (string) $s['key'];
                $byKey[$k] = array_merge($byKey[$k] ?? [], $s);
            }
            $list = array_values($byKey);
        }

        return $this->allCache = array_values(array_filter($list, fn ($s) => ($s['is_active'] ?? true) !== false));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function byKey(string $key): ?array
    {
        $k = $this->norm($key);
        $matches = [];
        foreach ($this->all() as $s) {
            if ($this->norm((string) ($s['key'] ?? '')) === $k) {
                $matches[] = $s;

                continue;
            }
            foreach ($s['aliases'] ?? [] as $a) {
                if ($this->norm((string) $a) === $k) {
                    $matches[] = $s;
                    break;
                }
            }
        }

        // `Gadang` is the canonical Huawei target. Legacy Hioso remains
        // available only through its explicit `Gadang-Hioso` alias.
        if ($k === 'gadang') {
            foreach ($matches as $site) {
                if (($site['mode'] ?? null) === self::MODE_HUAWEI_OLT
                    && (int) ($site['olt_id'] ?? 0) === 11) {
                    return $this->hydrate($site);
                }
            }
        }

        if ($matches !== []) {
            return $this->hydrate($matches[0]);
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function byRouterId(int $routerId): ?array
    {
        if ($routerId <= 0) {
            return null;
        }
        $matches = [];
        foreach ($this->all() as $s) {
            $rid = (int) ($s['router_id'] ?? 0);
            if ($rid === $routerId) {
                $matches[] = $s;
            }
        }
        if ($matches !== []) {
            // Router #511 is shared by the legacy Hioso and new Huawei site;
            // the canonical router target must resolve to Huawei/OLT #11.
            if ($routerId === 511) {
                foreach ($matches as $site) {
                    if (($site['mode'] ?? null) === self::MODE_HUAWEI_OLT
                        && (int) ($site['olt_id'] ?? 0) === 11) {
                        return $this->hydrate($site);
                    }
                }
            }

            return $this->hydrate($matches[0]);
        }

        // Fallback: match by router name needles
        $router = Router::query()->whereKey($routerId)->first();
        if (! $router) {
            return null;
        }
        $name = $this->norm((string) $router->name);
        foreach ($this->all() as $s) {
            foreach (array_merge([(string) ($s['key'] ?? '')], $s['aliases'] ?? [], $s['router_match'] ?? []) as $needle) {
                $n = $this->norm((string) $needle);
                if ($n !== '' && str_contains($name, $n)) {
                    $hyd = $this->hydrate($s);
                    // Prefer exact registry router_id if set; else use this router
                    if (empty($hyd['router_id'])) {
                        $hyd['router_id'] = $routerId;
                        $hyd['router'] = $router;
                    }

                    return $hyd;
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function byOltId(int $oltId): ?array
    {
        if ($oltId <= 0) {
            return null;
        }
        foreach ($this->all() as $s) {
            if ((int) ($s['olt_id'] ?? 0) === $oltId) {
                return $this->hydrate($s);
            }
        }

        return null;
    }

    /**
     * Resolve chat alias (ra|krian|…) → Router model via stable id when possible.
     */
    public function resolveRouterAlias(string $alias): ?Router
    {
        $site = $this->byKey($alias);
        if ($site && ! empty($site['router'])) {
            return $site['router'];
        }
        if ($site && ! empty($site['router_match']) && is_array($site['router_match'])) {
            return $this->findRouterByNeedles($site['router_match']);
        }

        return $this->findRouterByNeedles([$alias]);
    }

    /**
     * @param  array<string, mixed>  $site
     * @return array<string, mixed>
     */
    protected function hydrate(array $site): array
    {
        // Memoize per registry instance: hydrate() runs Router/Olt lookups, and
        // KPI/enrich loops call byRouterId()/byOltId() once per row (was an N+1
        // that made the PSB WA dashboard take ~40s for a few hundred rows).
        $cacheKey = (string) ($site['key'] ?? '');
        if ($cacheKey !== '' && isset($this->hydrateCache[$cacheKey])) {
            return $this->hydrateCache[$cacheKey];
        }

        $router = null;
        $rid = (int) ($site['router_id'] ?? 0);
        if ($rid > 0) {
            $router = Router::query()->whereKey($rid)->where('is_active', true)->first()
                ?: Router::query()->whereKey($rid)->first();
        }
        if (! $router && ! empty($site['router_match']) && is_array($site['router_match'])) {
            $router = $this->findRouterByNeedles($site['router_match']);
            if ($router) {
                $site['router_id'] = $router->id;
            }
        }

        $olt = null;
        $oid = (int) ($site['olt_id'] ?? 0);
        if ($oid > 0) {
            $olt = Olt::query()->whereKey($oid)->first();
        }

        $site['router'] = $router;
        $site['olt'] = $olt;
        $site['daily_target'] = (int) ($site['daily_target'] ?? $this->globalDailyTarget());

        if ($cacheKey !== '') {
            $this->hydrateCache[$cacheKey] = $site;
        }

        return $site;
    }

    public function globalDailyTarget(): int
    {
        $t = (int) Setting::get('psb_daily_target', 20);

        return $t > 0 ? $t : 20;
    }

    /**
     * Per-site targets from Setting `psb_site_targets` JSON { "krian": 10, "ra": 20 }.
     *
     * @return array<string, int>
     */
    public function siteTargets(): array
    {
        $raw = Setting::get('psb_site_targets', null);
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        $out = [];
        if (is_array($raw)) {
            foreach ($raw as $k => $v) {
                $out[$this->norm((string) $k)] = max(0, (int) $v);
            }
        }
        foreach ($this->all() as $s) {
            $k = $this->norm((string) ($s['key'] ?? ''));
            if ($k !== '' && ! isset($out[$k])) {
                $out[$k] = (int) ($s['daily_target'] ?? 0);
            }
        }

        return $out;
    }

    public function targetForSite(?string $siteKey): int
    {
        if ($siteKey === null || $siteKey === '') {
            return $this->globalDailyTarget();
        }
        $map = $this->siteTargets();
        $k = $this->norm($siteKey);

        return $map[$k] ?? 0;
    }

    /**
     * @param  list<string>  $needles
     */
    protected function findRouterByNeedles(array $needles): ?Router
    {
        // Exact normalized-name match first, so "Krian" (#15) is not shadowed
        // by the higher-id "Skynet-Krian-OLT" (#514) LIKE fallback. Use a plain
        // alphanumeric token: norm() strips the "skynet-" prefix, which would
        // make "krian-olt" collide with the Krian-OLT router.
        $tokens = [];
        foreach ($needles as $n) {
            $token = $this->routerNameToken((string) $n);
            if ($token !== '') {
                $tokens[] = $token;
            }
        }
        if ($tokens !== []) {
            $exact = Router::query()
                ->where('is_active', true)
                ->get(['id', 'name'])
                ->filter(fn (Router $router): bool => in_array($this->routerNameToken((string) $router->name), $tokens, true))
                ->sortByDesc('id')
                ->first();
            if ($exact) {
                return Router::query()->find((int) $exact->id);
            }
        }

        $q = Router::query()->where('is_active', true);
        $q->where(function ($w) use ($needles) {
            foreach ($needles as $n) {
                $n = trim((string) $n);
                if ($n === '') {
                    continue;
                }
                $w->orWhereRaw('LOWER(name) LIKE ?', ['%'.strtolower($n).'%']);
            }
        });

        // Prefer higher id (newer Skynet-*-OLT) when multiple match
        return $q->orderByDesc('id')->first();
    }

    /** Lowercased alphanumeric router-name token for exact matching. */
    protected function routerNameToken(string $value): string
    {
        return strtolower(preg_replace('/[^a-z0-9]+/i', '', $value) ?? '');
    }

    protected function norm(string $s): string
    {
        $s = strtolower(trim($s));
        $s = preg_replace('/\s+/', '', $s) ?? $s;

        return $s;
    }

    /**
     * Lightweight list for FE filters.
     *
     * @return list<array{key: string, name: string, mode: string, router_id: int|null, olt_id: int|null, daily_target: int}>
     */
    public function optionsForUi(): array
    {
        $targets = $this->siteTargets();
        $out = [];
        foreach ($this->all() as $s) {
            $k = (string) ($s['key'] ?? '');
            $out[] = [
                'key' => $k,
                'name' => (string) ($s['name'] ?? $k),
                'mode' => (string) ($s['mode'] ?? self::MODE_FULL_OLT),
                'vendor' => (string) ($s['vendor'] ?? ''),
                'router_id' => isset($s['router_id']) ? (int) $s['router_id'] : null,
                'olt_id' => isset($s['olt_id']) ? (int) $s['olt_id'] : null,
                'daily_target' => $targets[$this->norm($k)] ?? (int) ($s['daily_target'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * Per-site PSB defaults. Legacy scalar settings remain the fallback for
     * older clients; this map prevents one site's selection from overwriting
     * another site's router/OLT/package defaults.
     *
     * @return array<string, array{router_id: int, package_id: int, olt_id: int, acs_url: string}>
     */
    public function settingsForUi(): array
    {
        $raw = Setting::get('psb_site_settings', []);
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        $raw = is_array($raw) ? $raw : [];
        $legacy = [
            'router_id' => (int) Setting::get('psb_router_id', 0),
            'package_id' => (int) Setting::get('psb_package_id', 0),
            'olt_id' => (int) Setting::get('psb_olt_id', 0),
            'acs_url' => (string) Setting::get('psb_acs_url', config('site.acs_url')),
        ];
        $out = [];

        foreach ($this->all() as $site) {
            $key = (string) ($site['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $saved = is_array($raw[$key] ?? null) ? $raw[$key] : [];
            $out[$key] = [
                'router_id' => (int) ($saved['router_id'] ?? $site['router_id'] ?? ($key === 'ra' ? $legacy['router_id'] : 0)),
                'package_id' => (int) ($saved['package_id'] ?? ($key === 'ra' ? $legacy['package_id'] : 0)),
                'olt_id' => (int) ($saved['olt_id'] ?? $site['olt_id'] ?? ($key === 'ra' ? $legacy['olt_id'] : 0)),
                'acs_url' => (string) ($saved['acs_url'] ?? ($key === 'ra' ? $legacy['acs_url'] : config('site.acs_url'))),
            ];
        }

        return $out;
    }
}
