<?php

namespace App\Services;

use App\Models\Area;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-site / multi-area KPI over unified PSB rows (Excel + WA + manual).
 * Read-only aggregation — does not write MT/OLT.
 */
class PsbKpiService
{
    public function __construct(
        protected PsbStarliteReportService $starlite,
        protected PsbSiteRegistry $sites,
    ) {}

    /**
     * @param  array{site?: ?string, area_id?: ?int, from?: ?string, to?: ?string}  $filters
     * @return array<string, mixed>
     */
    public function dashboard(array $filters = []): array
    {
        $base = $this->starlite->dashboard();
        $tz = 'Asia/Jakarta';
        $today = now($tz)->toDateString();

        $siteFilter = isset($filters['site']) && $filters['site'] !== '' && $filters['site'] !== 'all'
            ? $this->norm((string) $filters['site'])
            : null;
        $areaFilter = isset($filters['area_id']) && (int) $filters['area_id'] > 0
            ? (int) $filters['area_id']
            : null;
        $from = $this->parseDate($filters['from'] ?? null) ?: now($tz)->subDays(29)->toDateString();
        $to = $this->parseDate($filters['to'] ?? null) ?: $today;

        $rows = $this->starlite->unifiedRows();
        $enriched = [];
        foreach ($rows as $row) {
            $enriched[] = $this->enrichRow($row);
        }

        $filtered = array_values(array_filter($enriched, function (array $r) use ($siteFilter, $areaFilter, $from, $to) {
            $d = $r['work_date'] ?? $r['created_date'] ?? null;
            if ($d && ($d < $from || $d > $to)) {
                return false;
            }
            if ($siteFilter !== null) {
                $sk = $this->norm((string) ($r['site_key'] ?? ''));
                if ($sk !== $siteFilter && ! str_contains($sk, $siteFilter)) {
                    return false;
                }
            }
            if ($areaFilter !== null) {
                if ((int) ($r['area_id'] ?? 0) !== $areaFilter) {
                    return false;
                }
            }

            return true;
        }));

        // Target harian: default 20/hari (global). Per-site dari registry (boleh beda).
        $dailyTargetGlobal = $this->sites->globalDailyTarget(); // 20
        $dayCount = max(1, Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1);

        $targetDaily = $siteFilter
            ? $this->sites->targetForSite($siteFilter)
            : $dailyTargetGlobal;
        // Target periode = target harian × jumlah hari filter (bukan total success / 20)
        $targetPeriod = $targetDaily * $dayCount;

        $successToday = 0;
        $successTotal = 0;
        $pending = 0;
        $failed = 0;
        $inputToday = 0;
        $bySite = [];
        $byArea = [];
        $byRw = [];
        $successByDate = [];
        $csr = ['input' => 0, 'success' => 0, 'success_today' => 0, 'pending' => 0, 'failed' => 0];

        // Pre-seed known sites so non-Randuagung sites cleanly show daily_target = 0
        foreach ($this->sites->optionsForUi() as $opt) {
            $sk = (string) ($opt['key'] ?? '');
            if ($sk === '') {
                continue;
            }
            if ($siteFilter !== null && $this->norm($sk) !== $siteFilter) {
                continue;
            }
            $dt = (int) ($opt['daily_target'] ?? 0);
            $bySite[$sk] = [
                'site_key' => $sk,
                'name' => (string) ($opt['name'] ?? $sk),
                'vendor' => (string) ($opt['vendor'] ?? ''),
                'input' => 0,
                'success' => 0,
                'input_today' => 0,
                'success_today' => 0,
                'daily_target' => $dt,
                'day_count' => $dayCount,
                'target_period' => $dt * $dayCount,
                // legacy keys for old FE
                'target' => $dt * $dayCount,
                'pct' => 0.0,
                'pct_today' => 0.0,
                'pct_period' => 0.0,
                'has_kpi' => $dt > 0,
            ];
        }

        foreach ($filtered as $row) {
            $sk = (string) ($row['site_key'] ?? 'ra');
            $an = (string) ($row['area_name'] ?? '(tanpa area)');
            $aid = $row['area_id'] ?? null;
            $d = $row['work_date'] ?? $row['created_date'] ?? null;
            $ok = ($row['sheet_status'] ?? '') === 'SUCCESS';

            $classification = strtolower(trim((string) ($row['service_classification'] ?? '')));
            $serviceType = strtolower(trim((string) ($row['service_type'] ?? '')));
            $isCsr = in_array($classification, ['csr', 'sekolah', 'institusi'], true)
                || str_contains($serviceType, 'csr')
                || str_contains($serviceType, 'sekolah')
                || str_contains($serviceType, 'institusi');
            if ($isCsr) {
                $csr['input']++;
                if ($ok) {
                    $csr['success']++;
                    if ($d === $today) {
                        $csr['success_today']++;
                    }
                } elseif (in_array(($row['sheet_status'] ?? ''), ['PENDING', 'RECEIVED', 'WAITING_SN', 'PENDING_VLAN', 'SECRET_OK'], true)
                    || in_array(($row['status'] ?? ''), ['received', 'waiting_sn', 'pending_vlan', 'secret_ok'], true)) {
                    $csr['pending']++;
                } elseif (in_array(($row['sheet_status'] ?? ''), ['FAILED', 'TIMEOUT', 'CANCELLED'], true)
                    || in_array(($row['status'] ?? ''), ['failed', 'timeout', 'cancelled'], true)) {
                    $csr['failed']++;
                }

                continue;
            }

            if (! isset($bySite[$sk])) {
                $dt = (int) ($this->sites->targetForSite($sk) ?? 0);
                $bySite[$sk] = [
                    'site_key' => $sk,
                    'name' => $row['site_name'] ?? $sk,
                    'vendor' => '',
                    'input' => 0,
                    'success' => 0,
                    'input_today' => 0,
                    'success_today' => 0,
                    'daily_target' => $dt,
                    'day_count' => $dayCount,
                    'target_period' => $dt * $dayCount,
                    'target' => $dt * $dayCount,
                    'pct' => 0.0,
                    'pct_today' => 0.0,
                    'pct_period' => 0.0,
                    'has_kpi' => $dt > 0,
                ];
            }
            $bySite[$sk]['input']++;
            if ($ok) {
                $bySite[$sk]['success']++;
            }
            if ($d === $today) {
                $bySite[$sk]['input_today']++;
                if ($ok) {
                    $bySite[$sk]['success_today']++;
                }
            }

            $area = $this->areaBucket($aid, $an);
            $areaKey = $area['key'];
            if (! isset($byArea[$areaKey])) {
                $byArea[$areaKey] = [
                    'area_id' => $aid,
                    'area_name' => $area['name'],
                    'input' => 0,
                    'success' => 0,
                    'success_today' => 0,
                ];
            } elseif ($byArea[$areaKey]['area_id'] === null && $aid !== null) {
                $byArea[$areaKey]['area_id'] = $aid;
            }
            $byArea[$areaKey]['input']++;
            if ($ok) {
                $byArea[$areaKey]['success']++;
                if ($d === $today) {
                    $byArea[$areaKey]['success_today']++;
                }
            }

            $rw = $this->normalizeGroupNumber($row['rw'] ?? null);
            $rt = $this->normalizeGroupNumber($row['rt'] ?? null);
            if (! isset($byRw[$rw])) {
                $byRw[$rw] = ['rw' => $rw, 'total' => 0, 'success' => 0, 'rts' => []];
            }
            if (! isset($byRw[$rw]['rts'][$rt])) {
                $byRw[$rw]['rts'][$rt] = ['rt' => $rt, 'total' => 0, 'success' => 0];
            }
            $byRw[$rw]['total']++;
            $byRw[$rw]['rts'][$rt]['total']++;
            if ($ok) {
                $byRw[$rw]['success']++;
                $byRw[$rw]['rts'][$rt]['success']++;
            }

            if ($d === $today) {
                $inputToday++;
            }

            if ($ok) {
                $successTotal++;
                if ($d) {
                    $successByDate[$d] = ($successByDate[$d] ?? 0) + 1;
                }
                if ($d === $today) {
                    $successToday++;
                }
            } elseif (in_array(($row['sheet_status'] ?? ''), ['PENDING', 'RECEIVED', 'WAITING_SN', 'PENDING_VLAN', 'SECRET_OK'], true)
                || in_array(($row['status'] ?? ''), ['received', 'waiting_sn', 'pending_vlan', 'secret_ok'], true)) {
                $pending++;
            } elseif (in_array(($row['sheet_status'] ?? ''), ['FAILED', 'TIMEOUT', 'CANCELLED'], true)
                || in_array(($row['status'] ?? ''), ['failed', 'timeout', 'cancelled'], true)) {
                $failed++;
            }
        }

        foreach ($bySite as &$s) {
            $daily = (int) ($s['daily_target'] ?? 0);
            $period = (int) ($s['target_period'] ?? 0);
            $s['has_kpi'] = $daily > 0;
            $s['pct_today'] = $daily > 0 ? round(((int) $s['success_today'] / $daily) * 100, 1) : 0.0;
            $s['pct_period'] = $period > 0 ? round(((int) $s['success'] / $period) * 100, 1) : 0.0;
            // Default % for FE = today if filter is single day, else period
            $s['pct'] = $dayCount === 1 ? $s['pct_today'] : $s['pct_period'];
            $s['target'] = $period; // period target for column "Target"
            $s['target_daily'] = $daily;
        }
        unset($s);

        // Filter out empty junk sites (daily_target === 0 && input === 0)
        $cleanBySite = array_filter($bySite, function ($s) {
            return ($s['daily_target'] ?? 0) > 0 || ($s['input'] ?? 0) > 0 || ($s['success'] ?? 0) > 0;
        });

        uasort($cleanBySite, fn ($a, $b) => ($b['success_today'] ?? 0) <=> ($a['success_today'] ?? 0)
            ?: ($b['success'] ?? 0) <=> ($a['success'] ?? 0));
        uasort($byArea, fn ($a, $b) => $b['success'] <=> $a['success']);
        $byRw = $this->sortRwSummary($byRw);

        // History chart: target line = daily target (20), not period
        $history = $this->starlite->buildHistoryPublic(30, $targetDaily, $tz, $successByDate);

        // Merge KPI into starlite payload (backward compatible)
        return array_merge($base, [
            'filters' => [
                'site' => $siteFilter,
                'area_id' => $areaFilter,
                'from' => $from,
                'to' => $to,
                'day_count' => $dayCount,
            ],
            'sites' => $this->sites->optionsForUi(),
            'areas' => $this->areaOptions(),
            'target' => $targetDaily, // daily target (20) — for "hari ini"
            'target_daily' => $targetDaily,
            'target_period' => $targetPeriod,
            'day_count' => $dayCount,
            'success_today' => $successToday,
            'success_total' => $successTotal,
            'pending' => $pending,
            'failed' => $failed,
            'csr' => $csr,
            'csr_definition' => 'CSR/sekolah/institusi dipisahkan dari KPI RW/RT reguler.',
            'input_today' => $inputToday,
            'total_input' => max(0, count($filtered) - $csr['input']),
            'pct_today' => $targetDaily > 0 ? round(($successToday / $targetDaily) * 100, 1) : 0.0,
            'pct_period' => $targetPeriod > 0 ? round(($successTotal / $targetPeriod) * 100, 1) : 0.0,
            'by_site' => array_values($cleanBySite),
            'by_area' => array_values($byArea),
            'by_rw' => $byRw,
            'history' => $history,
            'recent_rows' => count($filtered) > 500 ? array_slice($filtered, 0, 500) : $filtered,
            'rows_shown' => min(500, count($filtered)),
            'rows_total' => count($filtered),
            'project_title' => 'SKYNET PROJECT - PERFORMANCE DASHBOARD',
            'cluster' => $siteFilter
                ? ('Site: '.$siteFilter)
                : 'Skynet multi-site (RA C300 · Krian · Hioso · HTB CCR · Excel)',
            'success_definition' => 'SUCCESS = unique PPPoE. Target harian default 20/site/hari. % hari ini = sukses hari ini ÷ 20. % periode = sukses periode ÷ (20 × jumlah hari).',
            'note' => 'Hioso: Bumiayu, Gadang, Martopuro, Mangliawan, Srigading. HTB: Randuagung CCR MT only. Excel lama = RA.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function enrichRow(array $row): array
    {
        $siteKey = null;
        $siteName = null;

        // Prefer draft site_profile_key if present in row
        if (! empty($row['site_profile_key'])) {
            $siteKey = (string) $row['site_profile_key'];
        } elseif (! empty($row['site_key'])) {
            $siteKey = (string) $row['site_key'];
        }

        $routerId = (int) ($row['router_id'] ?? 0);
        $oltId = (int) ($row['olt_id'] ?? 0);

        if ((! $siteKey || $siteKey === 'default_full_olt' || $siteKey === 'unknown') && $routerId > 0) {
            $s = $this->sites->byRouterId($routerId);
            if ($s) {
                $siteKey = (string) $s['key'];
                $siteName = (string) ($s['name'] ?? $siteKey);
            }
        }
        if ((! $siteKey || $siteKey === 'default_full_olt') && $oltId > 0) {
            $s = $this->sites->byOltId($oltId);
            if ($s) {
                $siteKey = (string) $s['key'];
                $siteName = (string) ($s['name'] ?? $siteKey);
            }
        }

        // Normalize long keys
        if ($siteKey === 'krian_full_olt') {
            $siteKey = 'krian';
        }
        if ($siteKey === 'ra_full_olt' || $siteKey === 'default_full_olt') {
            $siteKey = 'ra';
        }
        // Normalize legacy keys: htb_* / hioso_* → short key + correct display vendor
        if (str_starts_with((string) $siteKey, 'htb_')) {
            $rest = substr((string) $siteKey, 4);
            // old keys that are actually Hioso
            if (in_array($rest, ['bumiayu', 'gadang', 'martopuro', 'mangliawan', 'srigading'], true)) {
                $siteKey = $rest;
            } elseif ($rest === 'rdg' || $rest === 'randuagung') {
                $siteKey = 'rdg';
            } else {
                $siteKey = $rest;
            }
        }
        if (str_starts_with((string) $siteKey, 'hioso_')) {
            $siteKey = substr((string) $siteKey, 6);
        }

        // Excel / baris tanpa MT-OLT mapping → cluster RA (Randuagung), bukan "unknown"
        $srcProbe = strtolower((string) ($row['source'] ?? 'wa'));
        if ((! $siteKey || $siteKey === 'unknown') && ($srcProbe === 'excel' || str_starts_with((string) ($row['id'] ?? ''), 'E'))) {
            $siteKey = 'ra';
            $siteName = 'RA / Randuagung';
        }
        if (! $siteKey || $siteKey === 'unknown') {
            // fallback aman: mayoritas PSB FiberHome = RA
            $siteKey = 'ra';
            $siteName = $siteName ?: 'RA / Randuagung';
        }

        if (! $siteName && $siteKey) {
            $s = $this->sites->byKey($siteKey);
            $siteName = $s['name'] ?? $siteKey;
        }
        if ($siteKey === 'ra' && (! $siteName || $siteName === 'ra' || $siteName === 'unknown')) {
            $siteName = 'RA / Randuagung C300';
        }
        // Force Hioso labels (bukan HTB)
        $hiosoKeys = ['bumiayu' => 'Bumiayu Hioso', 'gadang' => 'Gadang Hioso', 'martopuro' => 'Martopuro Hioso', 'mangliawan' => 'Mangliawan Hioso', 'srigading' => 'Srigading Hioso'];
        if (isset($hiosoKeys[$siteKey])) {
            $siteName = $hiosoKeys[$siteKey];
        }
        if ($siteKey === 'rdg') {
            $siteName = 'Randuagung CCR (HTB / MT only)';
        }

        $areaId = $row['area_id'] ?? null;
        // KPI area is customer wilayah/daerah, not provisioning site/project.
        $areaName = $row['daerah'] ?? $row['area_name'] ?? null;
        if ($areaId && ! $areaName && Schema::hasTable('areas')) {
            $areaName = Area::query()->whereKey((int) $areaId)->value('name');
        }

        $src = strtolower((string) ($row['source'] ?? 'wa'));
        if (($row['group_jid'] ?? '') === 'manual@ebilling' || str_contains((string) ($row['sender_name'] ?? ''), 'manual')) {
            $src = 'manual';
        }

        $row['site_key'] = $siteKey;
        $row['site_name'] = $siteName ?: $siteKey;
        $row['area_id'] = $areaId ? (int) $areaId : null;
        $row['area_name'] = $areaName ?: '(tanpa area)';
        $row['source'] = $src;

        return $row;
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    protected function areaOptions(): array
    {
        if (! Schema::hasTable('areas')) {
            return [];
        }

        $seen = [];

        return Area::query()->orderBy('name')->get(['id', 'name'])
            ->filter(function ($a) use (&$seen) {
                $key = $this->normArea((string) $a->name);
                if ($key === '' || isset($seen[$key])) {
                    return false;
                }
                $seen[$key] = true;

                return true;
            })
            ->map(fn ($a) => ['id' => (int) $a->id, 'name' => $this->prettyAreaName((string) $a->name)])
            ->values()
            ->all();
    }

    /**
     * @return array{key: string, name: string}
     */
    protected function areaBucket(?int $areaId, string $areaName): array
    {
        $name = trim($areaName);

        if ($name === '' || $name === '(tanpa area)') {
            return [
                'key' => $areaId ? 'a'.$areaId : 'n:tanpa-area',
                'name' => '(tanpa area)',
            ];
        }

        $key = $this->normArea($name);

        return [
            'key' => $key !== '' ? 'n:'.$key : ($areaId ? 'a'.$areaId : 'n:tanpa-area'),
            'name' => $this->prettyAreaName($name),
        ];
    }

    protected function normArea(string $area): string
    {
        $area = strtolower(trim($area));
        $area = preg_replace('/[^a-z0-9]+/', '', $area) ?? $area;

        return $area;
    }

    protected function prettyAreaName(string $area): string
    {
        $area = trim(preg_replace('/\s+/', ' ', $area) ?? $area);
        if ($area === '') {
            return '(tanpa area)';
        }

        $special = [
            'randuagung' => 'Randuagung',
            'gondang' => 'Gondang',
            'suko' => 'Suko',
        ];
        $key = $this->normArea($area);

        return $special[$key] ?? ucwords(strtolower($area));
    }

    protected function normalizeGroupNumber(mixed $value): string
    {
        $value = trim((string) $value);
        if ($value !== '' && preg_match('/(\d{1,3})/', $value, $m)) {
            return str_pad((string) ((int) $m[1]), 2, '0', STR_PAD_LEFT);
        }

        return $value !== '' ? $value : '(kosong)';
    }

    /**
     * @param  array<string, array{rw: string, total: int, success: int, rts: array<string, array{rt: string, total: int, success: int}>}>  $summary
     * @return list<array{rw: string, total: int, success: int, rts: array<string, array{rt: string, total: int, success: int}>}>
     */
    protected function sortRwSummary(array $summary): array
    {
        uasort($summary, fn ($a, $b) => $a['rw'] === '(kosong)'
            ? 1
            : ($b['rw'] === '(kosong)' ? -1 : strnatcmp($a['rw'], $b['rw']))
        );

        foreach ($summary as &$rwData) {
            uasort($rwData['rts'], fn ($a, $b) => $a['rt'] === '(kosong)'
                ? 1
                : ($b['rt'] === '(kosong)' ? -1 : strnatcmp($a['rt'], $b['rt']))
            );
        }
        unset($rwData);

        return array_values($summary);
    }

    protected function parseDate(?string $d): ?string
    {
        if ($d === null || trim($d) === '') {
            return null;
        }
        try {
            return Carbon::parse($d)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    protected function norm(string $s): string
    {
        return strtolower(trim(preg_replace('/\s+/', '', $s) ?? $s));
    }
}
