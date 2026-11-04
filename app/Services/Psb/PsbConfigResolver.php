<?php

namespace App\Services\Psb;

use App\Models\Olt;
use App\Models\Package;
use App\Models\Router;
use App\Models\Setting;
use App\Services\PsbSiteRegistry;

/**
 * PSB WA: group allowlist, settings switches and router/package/OLT lookup.
 *
 * Moved as-is from PsbOrchestratorService (PR 1 of the PSB refactor). The
 * orchestrator keeps a delegator with the same name for every method here.
 */
final class PsbConfigResolver
{
    /**
     * Normalisasi JID grup WA — biar ganti grup di settings langsung cocok
     * dengan remoteJid dari webhook (tanpa redeploy, tanpa exact-string fragile).
     */
    public function normalizeGroupJid(string $jid): string
    {
        $jid = trim($jid);
        if ($jid === '') {
            return '';
        }
        // strip whitespace / accidental newlines from copy-paste
        $jid = preg_replace('/\s+/', '', $jid) ?? $jid;
        // bare numeric → @g.us
        if (preg_match('/^\d{8,}$/', $jid)) {
            return $jid.'@g.us';
        }
        // already has @g.us
        if (str_ends_with(strtolower($jid), '@g.us')) {
            // keep case of local part; domain lower
            $local = explode('@', $jid, 2)[0];

            return $local.'@g.us';
        }
        // other @ forms (e.g. wrong suffix) — if local is digits, force @g.us
        if (str_contains($jid, '@')) {
            $local = explode('@', $jid, 2)[0];
            if (preg_match('/^\d{8,}$/', $local)) {
                return $local.'@g.us';
            }
        }

        return $jid;
    }

    /** @return list<string> */
    public function allowedGroupJids(): array
    {
        // Selalu baca Setting::get (cache di-forget saat save) — ganti grup langsung aktif
        $raw = Setting::get('psb_wa_group_ids', []);
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $raw = $decoded;
            } else {
                $split = preg_split('/[\s,]+/', $raw);
                $raw = is_array($split) ? $split : [];
            }
        }
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $j) {
            $n = $this->normalizeGroupJid((string) $j);
            if ($n !== '' && ! in_array($n, $out, true)) {
                $out[] = $n;
            }
        }

        return $out;
    }

    public function isAllowedGroup(string $jid): bool
    {
        $jid = $this->normalizeGroupJid($jid);
        if ($jid === '') {
            return false;
        }
        $allowed = $this->allowedGroupJids();
        if ($allowed === []) {
            return false;
        }

        if (in_array($jid, $allowed, true)) {
            return true;
        }

        // Fallback: bandingkan hanya bagian numerik (jaga format aneh dari WA)
        $bare = explode('@', $jid, 2)[0];
        foreach ($allowed as $a) {
            if (explode('@', $a, 2)[0] === $bare) {
                return true;
            }
        }

        return false;
    }

    public function acsUrl(): string
    {
        return (string) Setting::get('psb_acs_url', config('site.acs_url'));
    }

    public function resolveRouter(): ?Router
    {
        $id = (int) Setting::get('psb_router_id', 0);
        if ($id > 0) {
            $r = Router::query()->whereKey($id)->where('is_active', true)->first();
            if ($r) {
                return $r;
            }
        }

        return Router::query()
            ->where('is_active', true)
            ->where(function ($q) {
                $q->where('name', 'like', '%RA%OLT%')
                    ->orWhere('name', 'like', '%RA-OLT%')
                    ->orWhere('ip_address', config('site.psb_router_ip'));
            })
            ->orderByDesc('id')
            ->first();
    }

    public function resolvePackage(): ?Package
    {
        $id = (int) Setting::get('psb_package_id', 0);
        if ($id > 0) {
            $p = Package::query()->whereKey($id)->first();
            if ($p) {
                return $p;
            }
        }

        return Package::query()
            ->where(function ($q) {
                $q->where('name', 'like', '%STARTLITE%')
                    ->orWhere('name', 'like', '%200M%START%')
                    ->orWhere('mikrotik_profile', 'like', '%200%START%')
                    ->orWhere('mikrotik_profile', 'like', '%STARTLITE%')
                    ->orWhere('mikrotik_profile', '200MB')
                    ->orWhere('mikrotik_profile', 'like', '%200M%');
            })
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Map chat "Paket: 200M|STARTLITE|100|…" → Package (mikrotik_profile for secret).
     * Prefer packages linked to the target MikroTik (router_id) when provided.
     */
    public function resolvePackageFromHint(string $hint, ?int $routerId = null): ?Package
    {
        $raw = trim($hint);
        if ($raw === '') {
            return null;
        }

        if (ctype_digit($raw)) {
            $byId = Package::query()->whereKey((int) $raw)->first();
            if ($byId) {
                return $byId;
            }
        }

        $compact = strtoupper(preg_replace('/\s+/', '', $raw) ?? $raw);
        $compact = str_replace(['MBPS', 'MBIT'], 'M', $compact);

        // 100 / 100M / 100MB / 200MB → speed number
        $speed = null;
        if (preg_match('/^(\d{2,4})(M|MB)?$/i', $compact, $m)) {
            $speed = (int) $m[1];
        }

        $applySpeedFilter = function ($q) use ($speed, $compact, $raw) {
            if ($speed !== null) {
                $speedStr = (string) $speed;
                $q->where(function ($w) use ($speedStr, $speed) {
                    $w->where('mikrotik_profile', $speedStr.'MB')
                        ->orWhere('mikrotik_profile', $speedStr.'M')
                        ->orWhere('mikrotik_profile', $speedStr)
                        ->orWhere('mikrotik_profile', 'like', $speedStr.'%');
                    if ($speed === 200) {
                        $w->orWhere('mikrotik_profile', 'like', '%STARTLITE%')
                            ->orWhere('mikrotik_profile', 'like', '%200%START%');
                    }
                });

                return;
            }
            $like = '%'.str_replace(['%', '_'], '', $raw).'%';
            $q->where(function ($w) use ($like, $compact, $raw) {
                $w->where('name', 'like', $like)
                    ->orWhere('mikrotik_profile', 'like', $like)
                    ->orWhere('mikrotik_profile', $raw)
                    ->orWhere('mikrotik_profile', $compact);
                if (str_contains($compact, 'START')) {
                    $w->orWhere('name', 'like', '%STARTLITE%')
                        ->orWhere('mikrotik_profile', 'like', '%STARTLITE%');
                }
            });
        };

        $activeOnly = function ($q) {
            $q->where(function ($w) {
                $w->where('is_active', true)->orWhereNull('is_active');
            });
        };

        // 1) Package milik router target (dari profile sync) — hanya yang aktif
        if ($routerId && $routerId > 0) {
            $local = Package::query()->where('router_id', $routerId);
            $activeOnly($local);
            $applySpeedFilter($local);
            $foundLocal = $local->orderByDesc('id')->get()->first(
                fn (Package $package): bool => $speed === null || $this->profileMatchesSpeed($package->mikrotik_profile, $speed)
            );
            if ($foundLocal) {
                return $foundLocal;
            }

            // Router target diketahui: fallback hanya ke paket shared (router_id NULL),
            // JANGAN ambil paket milik router LAIN (profile-nya belum tentu ada di MT target).
            $shared = Package::query()->whereNull('router_id');
            $activeOnly($shared);
            $applySpeedFilter($shared);

            return $shared->orderByDesc('id')->get()->first(
                fn (Package $package): bool => $speed === null || $this->profileMatchesSpeed($package->mikrotik_profile, $speed)
            );
        }

        // 2) Router belum diketahui (site di-resolve belakangan): cari global aktif
        $global = Package::query();
        $activeOnly($global);
        $applySpeedFilter($global);

        return $global->orderByDesc('id')->get()->first(
            fn (Package $package): bool => $speed === null || $this->profileMatchesSpeed($package->mikrotik_profile, $speed)
        );
    }

    public function packageSpeedFromHint(string $hint): ?int
    {
        $compact = strtoupper(preg_replace('/\s+/', '', trim($hint)) ?? trim($hint));
        $compact = str_replace(['MBPS', 'MBIT'], 'M', $compact);

        return preg_match('/^(\d{2,4})(M|MB)?$/i', $compact, $matches)
            ? (int) $matches[1]
            : null;
    }

    public function profileMatchesSpeed(?string $profile, int $speed): bool
    {
        $profile = strtoupper(trim((string) $profile));
        if ($profile === '') {
            return false;
        }
        if ($speed === 200 && str_contains($profile, 'STARTLITE')) {
            return true;
        }

        return preg_match('/^'.preg_quote((string) $speed, '/').'(?:(?:MB|M))?(?:$|[^0-9])/i', $profile) === 1;
    }

    public function packageMappingError(string $hint, Package $package): ?string
    {
        $profile = trim((string) $package->mikrotik_profile);
        if ($profile === '') {
            return "Paket `{$hint}` belum memiliki MikroTik Profile; proses dihentikan.";
        }

        $speed = $this->packageSpeedFromHint($hint);
        if ($speed !== null && ! $this->profileMatchesSpeed($profile, $speed)) {
            return "Mapping paket `{$hint}` tidak cocok dengan profile MikroTik `{$profile}`; proses dihentikan (tanpa default 200M).";
        }

        return null;
    }

    public function resolveOlt(): ?Olt
    {
        $id = (int) Setting::get('psb_olt_id', 0);
        if ($id > 0) {
            $o = Olt::query()->whereKey($id)->first();
            if ($o) {
                return $o;
            }
        }

        // Prefer ZTE C300 RA (FiberHome CPE on C300)
        return Olt::query()
            ->where(function ($q) {
                $q->where('vendor', 'like', '%zte%')
                    ->orWhere('name', 'like', '%RA%')
                    ->orWhere('name', 'like', '%C300%');
            })
            ->orderBy('id')
            ->first()
            ?: Olt::query()->orderBy('id')->first();
    }

    /** Global safety switch (default true). */
    public function writesEnabled(): bool
    {
        $v = Setting::get('psb_writes_enabled', '1');
        if (is_bool($v)) {
            return $v;
        }
        $s = strtolower(trim((string) $v));

        return ! in_array($s, ['0', 'false', 'off', 'no'], true);
    }

    public function correctionWritesEnabled(): bool
    {
        $v = Setting::get('psb_correction_writes_enabled', '0');
        if (is_bool($v)) {
            return $v;
        }

        return in_array(strtolower(trim((string) $v)), ['1', 'true', 'on', 'yes'], true);
    }

    /** Contoh alias router untuk pesan bot — dari registry (dinamis), bukan hardcode. */
    public function registryAliasExamples(int $max = 6): string
    {
        try {
            $keys = array_map(fn ($s) => (string) $s['key'], app(PsbSiteRegistry::class)->optionsForUi());
            $keys = array_values(array_filter(array_unique($keys)));
            if ($keys !== []) {
                return implode(', ', array_slice($keys, 0, $max));
            }
        } catch (\Throwable) {
        }

        return 'ra, krian, bumiayu, rdg, gadang';
    }

    public function psbBlockUnknownSite(): bool
    {
        return in_array(strtolower(trim((string) Setting::get('psb_block_unknown_site', '1'))), ['1', 'true', 'yes', 'on'], true);
    }
}
