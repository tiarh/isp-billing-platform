<?php

namespace App\Services;

use App\Models\Area;
use App\Models\Olt;
use App\Models\Router;
use App\Models\RouterVlan;
use App\Models\Setting;
use Illuminate\Support\Facades\Schema;

/**
 * Resolve PSB activation site: daerah → router + mode + optional VLAN.
 * Source: built-in matrix (Sprint 2) + Setting override JSON + router_vlans table.
 *
 * Modes:
 * - full_olt     → FiberHome @ ZTE C300 (path bot lama)
 * - pppoe_only   → MT secret only
 * - pppoe_vlan   → MT secret + VLAN service
 *
 * Vendor label (KPI):
 * - hioso → Bumiayu, Gadang, Martopuro, Mangliawan, Srigading
 * - htb   → hanya Randuagung CCR (MikroTik), bukan Hioso
 */
class PsbSiteResolver
{
    public const MODE_FULL_OLT = 'full_olt';

    public const MODE_PPPOE_ONLY = 'pppoe_only';

    public const MODE_PPPOE_VLAN = 'pppoe_vlan';

    /**
     * Built-in profiles — order matters (specific before broad).
     *
     * @return list<array{
     *   key: string,
     *   match: list<string>,
     *   mode: string,
     *   router_match: list<string>,
     *   olt_match: list<string>,
     *   vlan_required: bool,
     *   require_uncfg: bool,
     *   tr069_vlan?: int
     * }>
     */
    public function builtinProfiles(): array
    {
        return [
            // Hioso (bukan HTB)
            [
                'key' => 'hioso_bumiayu',
                'match' => ['bumiayu'],
                'mode' => self::MODE_PPPOE_VLAN,
                'router_match' => ['bumiayu'],
                'olt_match' => [],
                'vlan_required' => true,
                'require_uncfg' => false,
            ],
            [
                'key' => 'hioso_srigading',
                'match' => [], // STEP 2026-09-30: moved to ra_full_olt (owner)
                'mode' => self::MODE_PPPOE_VLAN,
                'router_match' => ['srigading'],
                'olt_match' => ['srigading'],
                'vlan_required' => true,
                'require_uncfg' => false,
            ],
            // STEP 2026-09-30 (owner): daerah-daerah Hioso Martopuro & Srigading
            // dikerahkan via C300 RA (ra_full_olt) untuk migrasi berbasis format
            // PSB WA. Match list-nya dikosongkan (profil disimpan, tanpa match)
            // supaya tidak dobel-match dengan ra_full_olt.
            [
                'key' => 'hioso_martopuro',
                'match' => [],
                'mode' => self::MODE_PPPOE_VLAN,
                'router_match' => ['martopuro'],
                'olt_match' => ['martopuro'],
                'vlan_required' => true,
                'require_uncfg' => false,
            ],
            [
                'key' => 'hioso_gadang',
                'match' => ['gadang'],
                'mode' => self::MODE_PPPOE_VLAN,
                'router_match' => ['gadang'],
                'olt_match' => ['gadang'],
                'vlan_required' => true,
                'require_uncfg' => false,
            ],
            [
                'key' => 'hioso_mangliawan',
                'match' => ['mangliawan'],
                'mode' => self::MODE_PPPOE_VLAN,
                'router_match' => ['mangliawan'],
                'olt_match' => ['mangliawan'],
                'vlan_required' => true,
                'require_uncfg' => false,
            ],
            // HTB = Randuagung CCR MikroTik only (bukan OLT Hioso)
            [
                'key' => 'htb_rdg',
                'match' => ['rdg', 'randuagung-ccr', 'randuagung mt'],
                'mode' => self::MODE_PPPOE_VLAN,
                'router_match' => ['randuagung'],
                'olt_match' => [],
                'vlan_required' => true,
                'require_uncfg' => false,
            ],
            // Krian C300 + MT Krian (FiberHome full_olt) — BEFORE generic RA
            [
                'key' => 'krian_full_olt',
                'match' => ['krian'],
                'mode' => self::MODE_FULL_OLT,
                // Prefer Skynet-Krian-OLT (baru, setara RA-OLT); fallback router "Krian"
                'router_match' => ['krian-olt', 'skynet-krian', 'krian'],
                // Prefer explicit "krian" in OLT name; avoid matching OLT RA
                'olt_match' => ['olt krian', 'krian'],
                'vlan_required' => true,
                'require_uncfg' => true,
                'tr069_vlan' => 69,
            ],
            // RA FiberHome C300 — cluster pasang baru (jangan match generik "c300" saja)
            [
                'key' => 'ra_full_olt',
                // STEP 2026-09-30 (owner): Purwodadi, SLB, Martopuro, Jatisari,
                // Gajahrejo, Kertosari, Srigading dikerahkan via C300 RA untuk
                // migrasi berbasis format PSB WA (sebelumnya site Hioso).
                'match' => ['kalirejo', 'klampok', 'randuagung', 'bedali', 'kunci', 'alamhijau', 'songsong', 'yonkav', 'mulyoarjo', 'singosari', 'lawang', 'purwodadi', 'slb', 'martopuro', 'jatisari', 'gajahrejo', 'gajahan', 'kertosari', 'srigading'],
                'mode' => self::MODE_FULL_OLT,
                'router_match' => ['ra-olt', 'ra olt', 'sky net-ra', 'skynet-ra'],
                'olt_match' => ['olt ra', 'ra-c300', 'ra c300'],
                'vlan_required' => true, // from PON map
                'require_uncfg' => true,
                'tr069_vlan' => 69,
            ],
        ];
    }

    /**
     * @return array{
     *   key: string,
     *   mode: string,
     *   router: ?Router,
     *   olt: ?Olt,
     *   area: ?Area,
     *   vlan: ?int,
     *   vlan_required: bool,
     *   require_uncfg: bool,
     *   tr069_vlan: ?int,
     *   mikrotik_profile: ?string,
     *   ambiguous: bool,
     *   message: ?string
     * }
     */
    public function resolve(?string $daerah, ?int $forcedRouterId = null, ?int $forcedVlan = null, ?string $forcedSiteKey = null): array
    {
        $daerahNorm = $this->norm($daerah ?? '');
        $profiles = $this->mergedProfiles();
        $profile = $this->matchProfile($daerahNorm, $profiles);
        $matchedKnownSite = $profile !== null;

        // Default = full_olt (path bot lama / RA) jika daerah tidak match HTB
        if ($profile === null) {
            $profile = [
                'key' => 'default_full_olt',
                'match' => [],
                'mode' => self::MODE_FULL_OLT,
                'router_match' => ['ra-olt', 'ra olt', 'skynet-ra'],
                'olt_match' => ['olt ra'],
                'vlan_required' => true,
                'require_uncfg' => true,
                'tr069_vlan' => 69,
            ];
        }

        $router = null;
        $registrySite = null;
        // STEP 2026-09-30 (owner): psb_sites override wins over name matching.
        // The krian site is now served by Skynet-Krian-OLT (514); without this,
        // findRouter's exact-name preference kept sending Krian PSB to router 15.
        // Profile keys are suffixed (krian_full_olt) while registry keys are the
        // bare site key (krian), so try both.
        if ($profile['key'] !== 'default_full_olt') {
            $registrySite = app(PsbSiteRegistry::class)->byKey((string) $profile['key']);
            if ($registrySite === null && preg_match('/^([a-z0-9]+)_(?:full_olt|pppoe_vlan|pppoe_only)$/', (string) $profile['key'], $m)) {
                $registrySite = app(PsbSiteRegistry::class)->byKey($m[1]);
            }
            // The registry override must also SELECT the router: previously the
            // registry was only consulted for forced sites, so the router here
            // stayed null and findRouter() kept sending krian to router 15.
            // NOTE: only the router is taken from the registry here — the profile
            // key/mode stay from the builtin matrix, because the suffixed keys
            // (krian_full_olt / ra_full_olt) are part of the public contract.
            if ($registrySite !== null) {
                $router = $registrySite['router'] ?? null;
            }
        }
        if ($forcedSiteKey !== null && trim($forcedSiteKey) !== '') {
            $registrySite = app(PsbSiteRegistry::class)->byKey($forcedSiteKey);
            if ($registrySite) {
                $router = $registrySite['router'] ?? null;
                $profile['key'] = (string) ($registrySite['key'] ?? $profile['key']);
                $profile['mode'] = (string) ($registrySite['mode'] ?? $profile['mode'] ?? self::MODE_FULL_OLT);
            }
        }
        if (! $registrySite && $forcedRouterId && $forcedRouterId > 0) {
            $router = Router::query()->whereKey($forcedRouterId)->where('is_active', true)->first();
            $registrySite = app(PsbSiteRegistry::class)->byRouterId((int) $forcedRouterId);
            // Forced router wins site identity (Krian vs RA) even if Daerah text mismatches
            if ($registrySite) {
                $profile['key'] = (string) ($registrySite['key'] ?? $profile['key']);
                $profile['mode'] = (string) ($registrySite['mode'] ?? $profile['mode'] ?? self::MODE_FULL_OLT);
            }
        }
        if (! $router) {
            $router = $this->findRouter($profile['router_match'] ?? []);
        }

        $olt = null;
        if ($registrySite && ! empty($registrySite['olt'])) {
            $olt = $registrySite['olt'];
        } elseif ($registrySite && ! empty($registrySite['olt_id'])) {
            $olt = Olt::query()->whereKey((int) $registrySite['olt_id'])->first();
        }
        if (! $olt) {
            $olt = $this->findOlt($profile['olt_match'] ?? []);
        }
        $area = $this->findArea($daerahNorm);

        $mode = (string) ($profile['mode'] ?? self::MODE_FULL_OLT);
        $vlanRequired = (bool) ($profile['vlan_required'] ?? false);
        $requireUncfg = (bool) ($profile['require_uncfg'] ?? ($mode === self::MODE_FULL_OLT));

        // VLAN: forced chat > router_vlans default > area-linked
        $vlan = $forcedVlan && $forcedVlan > 0 ? $forcedVlan : null;
        $profileName = null;
        if ($vlan === null && $router && Schema::hasTable('router_vlans')) {
            $rv = $this->pickRouterVlan($router->id, $area?->id);
            if ($rv) {
                $vlan = (int) $rv->vlan;
                $profileName = $rv->mikrotik_profile;
            }
        }

        // If mode is pppoe_vlan but no VLAN in DB yet → keep mode, vlan null (pending)
        // If NOC prefers skip VLAN gate: treat as pppoe_only when no vlan configured
        if ($mode === self::MODE_PPPOE_VLAN && $vlan === null && ! $vlanRequired) {
            $mode = self::MODE_PPPOE_ONLY;
        }
        // Soft: if vlan_required but empty table, allow pppoe_only progress with note
        // (user said skip VLAN numbers for now)
        if ($mode === self::MODE_PPPOE_VLAN && $vlan === null) {
            $mode = self::MODE_PPPOE_ONLY;
            $vlanRequired = false;
        }

        return [
            'key' => (string) ($profile['key'] ?? 'unknown'),
            'mode' => $mode,
            'router' => $router,
            'olt' => $olt,
            'area' => $area,
            'vlan' => $vlan,
            'vlan_required' => $vlanRequired && $mode === self::MODE_PPPOE_VLAN,
            'require_uncfg' => $requireUncfg,
            'tr069_vlan' => isset($profile['tr069_vlan']) ? (int) $profile['tr069_vlan'] : ($mode === self::MODE_FULL_OLT ? 69 : null),
            'mikrotik_profile' => $profileName,
            // Daerah tidak match site mana pun DAN tanpa Router: eksplisit
            // = jangan diam-diam dianggap RA (guard salah-target).
            'ambiguous' => ! $matchedKnownSite && ! ($forcedRouterId && $forcedRouterId > 0),
            'message' => (! $matchedKnownSite && ! ($forcedRouterId && $forcedRouterId > 0))
                ? 'Daerah tidak terdaftar di registry site PSB; butuh Router: eksplisit.'
                : null,
        ];
    }

    /**
     * Map chat alias: ra | rdg | bumiayu | gadang | …
     * Prefer PsbSiteRegistry (stable router_id) over name LIKE.
     */
    public function resolveRouterAlias(string $alias): ?Router
    {
        $reg = app(PsbSiteRegistry::class)->resolveRouterAlias($alias);
        if ($reg) {
            return $reg;
        }

        $a = $this->norm($alias);
        $map = [
            'ra' => ['ra-olt', 'sky net-ra', 'skynet-ra'],
            'raolt' => ['ra-olt', 'skynet-ra'],
            'rdg' => ['randuagung'],
            'randuagung' => ['randuagung'],
            'bumiayu' => ['bumiayu'],
            'martopuro' => ['martopuro'],
            'gadang' => ['gadang'],
            'mangliawan' => ['mangliawan'],
            'krian' => ['krian-olt', 'skynet-krian', 'krian'],
            'krianolt' => ['krian-olt', 'skynet-krian', 'krian'],
            'skynetkrian' => ['krian-olt', 'skynet-krian', 'krian'],
            'srigading' => ['randuagung'], // secret on RDG
        ];
        $needles = $map[$a] ?? [$a];

        return $this->findRouter($needles);
    }

    protected function pickRouterVlan(int $routerId, ?int $areaId): ?RouterVlan
    {
        $q = RouterVlan::query()
            ->where('router_id', $routerId)
            ->where('is_active', true);

        if ($areaId) {
            $byArea = (clone $q)->where('area_id', $areaId)->orderByDesc('is_default')->first();
            if ($byArea) {
                return $byArea;
            }
        }

        return $q->where(function ($w) {
            $w->whereNull('area_id')->orWhere('is_default', true);
        })->orderByDesc('is_default')->orderBy('vlan')->first()
            ?: RouterVlan::query()->where('router_id', $routerId)->where('is_active', true)->orderByDesc('is_default')->first();
    }

    /**
     * @param  list<array<string, mixed>>  $profiles
     * @return array<string, mixed>|null
     */
    protected function matchProfile(string $daerahNorm, array $profiles): ?array
    {
        if ($daerahNorm === '') {
            return null;
        }
        foreach ($profiles as $p) {
            foreach ($p['match'] ?? [] as $m) {
                $m = $this->norm((string) $m);
                if ($m !== '' && (str_contains($daerahNorm, $m) || str_contains($m, $daerahNorm))) {
                    return $p;
                }
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function mergedProfiles(): array
    {
        $extra = Setting::get('psb_site_profiles', null);
        if (is_string($extra)) {
            $extra = json_decode($extra, true);
        }
        $list = $this->builtinProfiles();
        if (is_array($extra) && $extra !== []) {
            // Prepend overrides (higher priority)
            $list = array_merge(array_values($extra), $list);
        }

        return $list;
    }

    /**
     * @param  list<string>  $needles
     */
    protected function findRouter(array $needles): ?Router
    {
        if ($needles === []) {
            return null;
        }

        // Prefer an exact normalized-name match before the LIKE fallback.
        // "Krian" (router #15) and "Skynet-Krian-OLT" (#514) both contain
        // "krian"; without this the higher id (#514) wins by orderByDesc, which
        // would send Krian PSB to the wrong router.
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

        return $q->orderByDesc('id')->first();
    }

    /** Lowercased alphanumeric router-name token for exact matching. */
    protected function routerNameToken(string $value): string
    {
        return strtolower(preg_replace('/[^a-z0-9]+/i', '', $value) ?? '');
    }

    /**
     * @param  list<string>  $needles
     */
    protected function findOlt(array $needles): ?Olt
    {
        if ($needles === []) {
            return null;
        }
        $q = Olt::query()->where(function ($w) use ($needles) {
            foreach ($needles as $n) {
                $n = trim((string) $n);
                if ($n === '') {
                    continue;
                }
                $like = '%'.strtolower($n).'%';
                $w->orWhereRaw('LOWER(name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(vendor, ?)) LIKE ?', ['', $like]);
            }
        });

        return $q->orderBy('id')->first();
    }

    protected function findArea(string $daerahNorm): ?Area
    {
        if ($daerahNorm === '') {
            return null;
        }

        // Alias chat → fragment area (klampok/kalirejo jangan ke-fall ke randuagung)
        $aliases = [
            'kalmpok' => 'klampok',
            'klampok' => 'klampok',
            'kalirejo' => 'kalirejo',
            'rdg' => 'randuagung',
        ];
        $want = $aliases[$daerahNorm] ?? $daerahNorm;

        $areas = Area::query()->get(['id', 'name', 'code']);
        $best = null;
        $bestScore = -1;
        foreach ($areas as $a) {
            $n = strtolower(preg_replace('/[^a-z0-9]+/i', '', (string) $a->name) ?? '');
            $n = preg_replace('/^(skynet|subnet)/', '', $n) ?? $n;
            $code = strtolower(preg_replace('/[^a-z0-9]+/i', '', (string) ($a->code ?? '')) ?? '');
            $score = 0;
            if ($n === $want || $code === $want || str_ends_with($code, $want)) {
                $score = 100;
            } elseif (str_contains($n, $want) || str_contains($want, $n)) {
                $score = 50 + min(strlen($n), 20);
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $a;
            }
        }

        return $bestScore >= 50 ? $best : null;
    }

    protected function norm(string $s): string
    {
        $s = strtolower(trim($s));
        $s = preg_replace('/\s+/', '', $s) ?? $s;
        $s = str_replace(['sky-net', 'skynet-', 'skynet'], '', $s);

        return $s;
    }
}
