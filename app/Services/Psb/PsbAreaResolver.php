<?php

namespace App\Services\Psb;

use App\Models\Area;
use App\Models\Olt;
use App\Models\PsbDraft;
use App\Models\Setting;
use App\Services\AreaLocalityResolver;
use App\Services\PsbSiteRegistry;

/**
 * PSB area resolve + required-field check (moved verbatim from PsbOrchestratorService).
 * No device calls and no WA replies: the facade keeps blockIfAreaMissing (it replies).
 */
final class PsbAreaResolver
{
    /**
     * Resolve the draft's area. Precedence:
     *   1. manual   - meta.area_manual (admin picked it in the drafts list)
     *   2. locality - AreaLocalityResolver on "daerah" (alias > name > code)
     *   3. OLT area - only when the OLT has an area AND the policy allows (non-RA sites by default)
     *   4. null     - caller must block the draft ("Perlu area") before any device write
     * Never assigns Randuagung Starlite implicitly. Records meta.area_resolution.
     *
     * @return array{status: string, source: string, area_id: ?int, matched_key: ?string, candidates: list<array{area_id: int, key: string, via: string}>}
     */
    public function resolveArea(PsbDraft $draft, ?Olt $siteOlt = null): array
    {
        $meta = is_array($draft->meta) ? $draft->meta : [];
        $manualId = (int) ($meta['area_manual'] ?? 0);
        $resolution = null;
        // A secret already exists on the router: the customer carries this area, so a
        // retry never re-resolves or moves it (moving old drafts is a separate backfill).
        $deviceWritten = $draft->secret_at !== null;

        if ($deviceWritten && (int) $draft->area_id > 0) {
            $resolution = ['status' => 'resolved', 'source' => 'existing', 'area_id' => (int) $draft->area_id, 'matched_key' => null, 'candidates' => []];
        } elseif ($manualId > 0 && Area::query()->whereKey($manualId)->exists()) {
            $resolution = ['status' => 'resolved', 'source' => 'manual', 'area_id' => $manualId, 'matched_key' => null, 'candidates' => []];
        }

        if ($resolution === null) {
            $match = (new AreaLocalityResolver)->resolve((string) $draft->daerah);
            if ($match['status'] === AreaLocalityResolver::STATUS_MATCHED) {
                $resolution = [
                    'status' => 'resolved',
                    'source' => (string) $match['via'],
                    'area_id' => (int) $match['area_id'],
                    'matched_key' => $match['matched_key'],
                    'candidates' => [],
                ];
            } elseif ($match['status'] === AreaLocalityResolver::STATUS_AMBIGUOUS) {
                $resolution = ['status' => 'ambiguous', 'source' => 'none', 'area_id' => null, 'matched_key' => null, 'candidates' => $match['candidates']];
            } elseif ($deviceWritten || $this->psbAreaUnmatchedPolicy($draft) !== 'block') {
                // (legacy draft with a secret but no area always may use the OLT area)
                $olt = $siteOlt ?? ((int) $draft->olt_id > 0 ? Olt::query()->find((int) $draft->olt_id) : null);
                $oltAreaId = $this->areaIdFromOlt($olt);
                if ($oltAreaId !== null) {
                    $resolution = ['status' => 'resolved', 'source' => 'olt_fallback', 'area_id' => $oltAreaId, 'matched_key' => null, 'candidates' => []];
                }
            }
            if ($resolution === null) {
                $resolution = ['status' => 'unmatched', 'source' => 'none', 'area_id' => null, 'matched_key' => null, 'candidates' => $match['candidates']];
            }
        }

        $draft->area_id = $resolution['area_id'];
        $meta['area_resolution'] = $resolution + ['at' => now()->toIso8601String()];
        $draft->meta = $meta;
        $draft->save();

        return $resolution;
    }

    /**
     * 'block' = unknown locality never falls back to the OLT's area; 'olt' = it may.
     * Setting psb_area_unmatched_policy ('block'|'olt') overrides everything; otherwise
     * (unset/'auto') RA-OLT (former Starlite site keys) blocks and every other site
     * (Krian, Gadang, ...) keeps the old OLT-area fallback.
     */
    public function psbAreaUnmatchedPolicy(?PsbDraft $draft = null): string
    {
        $policy = strtolower(trim((string) Setting::get('psb_area_unmatched_policy', '')));
        if (in_array($policy, ['block', 'olt'], true)) {
            return $policy;
        }

        return $this->isRaOltDraft($draft) ? 'block' : 'olt';
    }

    /** RA-OLT = the former Starlite path: site keys ra/rdg/ra_full_olt/default_full_olt or an RA registry router. */
    public function isRaOltDraft(?PsbDraft $draft): bool
    {
        if ($draft === null) {
            return false;
        }
        $raKeys = ['ra', 'rdg', 'htbrdg', 'rafullolt', 'defaultfullolt'];
        if (in_array($this->areaKey((string) $draft->site_profile_key), $raKeys, true)) {
            return true;
        }
        if ($draft->router_id) {
            $registry = app(PsbSiteRegistry::class)->byRouterId((int) $draft->router_id);

            return in_array($this->areaKey((string) ($registry['key'] ?? '')), ['ra', 'rdg', 'htbrdg'], true);
        }

        return false;
    }

    /** Area id carried by the site OLT, used only as a fallback label. */
    public function areaIdFromOlt(?Olt $olt): ?int
    {
        $areaId = (int) ($olt->area_id ?? 0);

        return $areaId > 0 ? $areaId : null;
    }

    public function areaKey(string $value): string
    {
        return strtolower(preg_replace('/[^a-z0-9]+/i', '', $value) ?? '');
    }

    /**
     * Field WAJIB teks untuk PSB.
     * Foto min.1 dicek terpisah (hasMinPhoto) — mode B.
     * Opsional: No HP.
     * Router + Paket wajib (batesin speed di MikroTik).
     *
     * @return list<string>
     */
    public function missingRequired(PsbDraft $draft): array
    {
        $miss = [];
        if (trim((string) $draft->full_name) === '') {
            $miss[] = 'Nama lengkap';
        }
        if (trim((string) $draft->odp) === '') {
            $miss[] = 'ODP';
        }
        if (trim((string) $draft->rt) === '') {
            $miss[] = 'RT';
        }
        if (trim((string) $draft->rw) === '') {
            $miss[] = 'RW';
        }
        if (trim((string) $draft->tikor) === '') {
            $miss[] = 'Tikor (koordinat)';
        }
        if (trim((string) $draft->daerah) === '') {
            $miss[] = 'Daerah / area';
        }
        if (trim((string) $draft->sn) === '') {
            $miss[] = 'SN';
        }

        $meta = is_array($draft->meta) ? $draft->meta : [];
        $hasRouter = (int) ($draft->router_id ?? 0) > 0
            || (int) ($meta['forced_router_id'] ?? 0) > 0;
        if (! $hasRouter) {
            $miss[] = 'Router (contoh: ra / krian / bumiayu / rdg)';
        }

        if ((int) ($draft->package_id ?? 0) <= 0) {
            $miss[] = 'Paket (contoh: 200M / STARTLITE / 100M)';
        }

        return $miss;
    }
}
