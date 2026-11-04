<?php

namespace App\Services;

use App\Models\Area;
use App\Models\CustomerMigration;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class OperationalAreaDashboardService
{
    public function __construct(
        protected PsbStarliteReportService $psbReport,
        protected PsbSiteRegistry $sites,
        protected ServiceAreaClassificationService $classifications,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(Request $request): array
    {
        $month = $this->parseMonth($request->query('ops_month'));
        $filters = [
            'month' => $month,
            'area' => $this->filterArea($request->query('ops_area')),
            'project' => $this->filterProject($request->query('ops_project')),
            'rw' => $this->filterRw($request->query('ops_rw')),
            'sub_area' => $this->filterValue($request->query('ops_sub_area')),
        ];

        $user = $request->user();
        $areaScopeKey = ($user && method_exists($user, 'hasAreaScope') && $user->hasAreaScope())
            ? implode('-', $user->accessibleAreaIds()->all())
            : 'global';

        return Cache::remember(
            'dashboard.operational_area.'.md5(json_encode([$filters, $areaScopeKey, Cache::get('dashboard.operational_area.version', 1)])),
            now()->addMinutes(5),
            fn () => $this->buildSummary($filters, $request)
        );
    }

    /**
     * @param  array{month: string, area: string, project: string, rw: string, sub_area: string}  $filters
     * @return array<string, mixed>
     */
    protected function buildSummary(array $filters, Request $request): array
    {
        $monthStart = Carbon::createFromFormat('!Y-m', $filters['month'], 'Asia/Jakarta')->startOfMonth();
        $monthEnd = (clone $monthStart)->endOfMonth();

        $migrationRows = $this->migrationRows($request);
        $psbRows = $this->psbRows($request);
        $rawRows = array_merge($migrationRows, $psbRows);
        $allRows = array_values(array_filter($rawRows, fn ($row) => $this->matchesFilters($row, $filters, $monthStart, $monthEnd)));

        $migration = array_values(array_filter($allRows, fn ($row) => ($row['project'] ?? '') === 'starlite-akuisisi'));
        $psb = array_values(array_filter($allRows, fn ($row) => ($row['type'] ?? '') === 'psb'));

        $migrated = count(array_filter($migration, fn ($row) => (bool) ($row['migrated'] ?? false)));
        $migrationTarget = count($migration);
        $psbTarget = $this->psbTarget($filters, $monthStart, $monthEnd);
        $psbSuccess = count(array_filter($psb, fn ($row) => (bool) ($row['success'] ?? false)));
        $reviewCount = count(array_filter($allRows, fn ($row) => (bool) ($row['needs_review'] ?? false)));

        return [
            'filters' => $filters,
            'options' => $this->options($rawRows),
            'summary' => [
                'migration_target' => $migrationTarget,
                'migrated' => $migrated,
                'not_migrated' => max(0, $migrationTarget - $migrated),
                'migration_progress' => $migrationTarget > 0 ? round(($migrated / $migrationTarget) * 100, 1) : 0.0,
                'psb_this_month' => $psbSuccess,
                'psb_target' => $psbTarget,
                'psb_progress' => $psbTarget > 0 ? round(($psbSuccess / $psbTarget) * 100, 1) : 0.0,
                'needs_review' => $reviewCount,
            ],
            'rows' => $this->groupRows($allRows),
            'last_sync' => now('Asia/Jakarta')->format('d/m/Y H:i').' WIB',
            'stale' => false,
            'source_note' => 'Read-only dari customer_migrations, psb_starlite_entries, dan psb_drafts unified/cache. Tidak query live OLT/MikroTik saat dashboard dibuka.',
            'snapshot_note' => 'Excel Starlite hanya snapshot sampai sekitar 2026-07-23/2026-07-24; data baru setelah cutoff dibaca dari WA/manual existing.',
            'drilldowns' => [
                'migration_url' => route('customer-migrations.index', [
                    'search' => $filters['area'] !== 'all' ? $filters['area'] : null,
                ], false),
                'psb_url' => route('psb-drafts.dashboard', [
                    'site' => $this->siteFilterForPsb($filters['area']),
                    'from' => $monthStart->toDateString(),
                    'to' => $monthEnd->toDateString(),
                ], false),
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function migrationRows(Request $request): array
    {
        if (! Schema::hasTable('customer_migrations')) {
            return [];
        }

        $query = CustomerMigration::query()->with('area:id,name');
        if ($request->user() && method_exists($request->user(), 'hasAreaScope') && $request->user()->hasAreaScope()) {
            $areaIds = $request->user()->accessibleAreaIds()->all();
            $query = $areaIds === [] ? $query->whereRaw('1 = 0') : $query->whereIn('area_id', $areaIds);
        }

        $byIdentity = [];
        foreach ($query->limit(5000)->get() as $candidate) {
            $identity = $this->migrationIdentity($candidate);
            if (! isset($byIdentity[$identity]) || $this->preferMigrationRow($candidate, $byIdentity[$identity])) {
                $byIdentity[$identity] = $candidate;
            }
        }

        return collect($byIdentity)->map(function (CustomerMigration $row) {
            $classification = $this->classifications->forMigration($row);
            $area = (string) ($classification['customer_area'] ?: 'review');
            $project = (string) $classification['project'];
            $rw = $this->canonicalRw($row->rw, $area, $project);
            $newSn = strtoupper((string) $row->new_sn);

            return [
                'type' => 'migration',
                'project' => $project,
                'project_label' => $this->label($project),
                'area' => $area,
                'area_label' => $this->label($area),
                'sub_area' => $this->subArea($row->daerah),
                'rw' => $rw['rw'],
                'needs_review' => $rw['needs_review'],
                'date' => $row->synced_at?->timezone('Asia/Jakarta')->toDateString()
                    ?: $row->olt_at?->timezone('Asia/Jakarta')->toDateString()
                    ?: $row->created_at?->timezone('Asia/Jakarta')->toDateString(),
                'migrated' => in_array($row->status, [CustomerMigration::STATUS_OLT_OK, CustomerMigration::STATUS_SYNCED], true)
                    || str_starts_with($newSn, 'FHTT'),
                'success' => false,
            ];
        })->all();
    }

    private function migrationIdentity(CustomerMigration $row): string
    {
        if ($row->customer_id) {
            return 'customer:'.$row->customer_id;
        }
        if ($row->pppoe_user) {
            return 'pppoe:'.strtoupper(trim((string) $row->pppoe_user));
        }
        if ($row->new_sn) {
            return 'sn:'.strtoupper(trim((string) $row->new_sn));
        }

        return 'row:'.$row->id;
    }

    private function preferMigrationRow(CustomerMigration $candidate, CustomerMigration $current): bool
    {
        $cutoff = Carbon::parse('2026-07-24 00:00:00', 'Asia/Jakarta');
        $candidateLive = $candidate->created_at?->gte($cutoff) && $candidate->source !== 'excel_master';
        $currentLive = $current->created_at?->gte($cutoff) && $current->source !== 'excel_master';
        if ($candidateLive !== $currentLive) {
            return $candidateLive;
        }

        $rank = [
            CustomerMigration::STATUS_SYNCED => 4,
            CustomerMigration::STATUS_OLT_OK => 3,
            CustomerMigration::STATUS_NEEDS_REVIEW => 2,
            CustomerMigration::STATUS_RECEIVED => 1,
        ];
        $candidateRank = $rank[$candidate->status] ?? 0;
        $currentRank = $rank[$current->status] ?? 0;
        if ($candidateRank !== $currentRank) {
            return $candidateRank > $currentRank;
        }

        return ($candidate->created_at?->getTimestamp() ?? 0) > ($current->created_at?->getTimestamp() ?? 0);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function psbRows(Request $request): array
    {
        if (! $this->psbReport->tableReady() && ! $this->psbReport->historyReady()) {
            return [];
        }

        $areaScope = null;
        if ($request->user() && method_exists($request->user(), 'hasAreaScope') && $request->user()->hasAreaScope()) {
            $areaScope = $request->user()->accessibleAreaIds()->all();
        }
        $areaIdsByKey = $this->areaIdsByCanonicalKey();

        return collect($this->psbReport->unifiedRows())->map(function (array $row) {
            $classification = $this->classifications->forPsbPayload($row);
            $project = (string) $classification['project'];
            $area = (string) ($classification['customer_area'] ?: 'review');
            $type = ($classification['service_origin'] ?? '') === 'migration_acquisition' ? 'migration' : 'psb';
            $rw = $this->canonicalRw($row['rw'] ?? null, $area, $project);

            return [
                'type' => $type,
                'project' => $project,
                'project_label' => $this->label($project),
                'area' => $area,
                'area_label' => $this->label($area),
                'sub_area' => $this->subArea($row['daerah'] ?? null),
                'rw' => $rw['rw'],
                'needs_review' => $rw['needs_review'],
                'date' => $row['work_date'] ?? $row['created_date'] ?? null,
                'migrated' => ($row['sheet_status'] ?? '') === 'SUCCESS',
                'success' => ($row['sheet_status'] ?? '') === 'SUCCESS',
            ];
        })->filter(function (array $row) use ($areaScope, $areaIdsByKey) {
            if ($areaScope === null) {
                return true;
            }
            if ($areaScope === []) {
                return false;
            }

            $areaId = $areaIdsByKey[$row['area']] ?? null;

            return $areaId !== null && in_array($areaId, $areaScope, true);
        })->values()->all();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    protected function groupRows(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $key = implode('|', [$row['project'], $row['area']]);
            $grouped[$key] ??= [
                'project' => $row['project'],
                'project_label' => $row['project_label'],
                'area' => $row['area'],
                'area_label' => $row['area_label'],
                'sub_areas' => [],
                'rws' => [],
                'sub_area' => '-',
                'rw' => '-',
                'migration_target' => 0,
                'migrated' => 0,
                'psb_success' => 0,
                'needs_review' => 0,
            ];
            if (($row['sub_area'] ?? '-') !== '-') {
                $grouped[$key]['sub_areas'][(string) $row['sub_area']] = true;
            }
            if (($row['rw'] ?? '-') !== '-') {
                $grouped[$key]['rws'][(string) $row['rw']] = true;
            }
            if (($row['project'] ?? '') === 'starlite-akuisisi') {
                $grouped[$key]['migration_target']++;
                if ($row['migrated'] ?? false) {
                    $grouped[$key]['migrated']++;
                }
            }
            if (($row['type'] ?? '') === 'psb' && ($row['success'] ?? false)) {
                $grouped[$key]['psb_success']++;
            }
            if ($row['needs_review'] ?? false) {
                $grouped[$key]['needs_review']++;
            }
        }

        foreach ($grouped as &$row) {
            $subAreas = array_keys($row['sub_areas']);
            sort($subAreas);
            $rws = array_keys($row['rws']);
            sort($rws, SORT_NATURAL);
            $row['sub_area'] = count($subAreas) === 0 ? '-' : (count($subAreas) === 1 ? $subAreas[0] : count($subAreas).' sub-area');
            $row['rw'] = count($rws) === 0 ? '-' : (count($rws) === 1 ? $rws[0] : count($rws).' RW');
            unset($row['sub_areas'], $row['rws']);
        }
        unset($row);

        usort($grouped, fn ($a, $b) => ($b['migrated'] + $b['psb_success']) <=> ($a['migrated'] + $a['psb_success']));

        return array_slice(array_values($grouped), 0, 12);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, list<array{value: string, label: string}>>
     */
    protected function options(array $rows): array
    {
        $make = function (string $key) use ($rows) {
            $values = collect($rows)->pluck($key)->filter()->unique()->sort()->values();

            return $values->map(fn ($v) => ['value' => (string) $v, 'label' => $this->label((string) $v)])->prepend(['value' => 'all', 'label' => 'Semua'])->values()->all();
        };

        return [
            'areas' => $make('area'),
            'projects' => $make('project'),
            'rws' => $make('rw'),
            'sub_areas' => $make('sub_area'),
        ];
    }

    protected function matchesFilters(array $row, array $filters, Carbon $from, Carbon $to): bool
    {
        $date = $row['date'] ?? null;
        if ($date) {
            try {
                $d = Carbon::parse($date, 'Asia/Jakarta');
                if ($d->lt($from) || $d->gt($to)) {
                    return false;
                }
            } catch (\Throwable) {
                return false;
            }
        }

        foreach (['area', 'project', 'rw', 'sub_area'] as $key) {
            if ($filters[$key] !== 'all' && (string) ($row[$key] ?? '') !== $filters[$key]) {
                return false;
            }
        }

        return true;
    }

    protected function canonicalProject(string $project, string $area): string
    {
        $p = $this->key($project);
        if (in_array($p, ['randuagungstarlite', 'starliteranduagung'], true)) {
            return 'starlite-randuagung';
        }
        if (in_array($p, ['starliteakuisisi', 'akuisisi', 'acquisition', 'migrasi'], true)) {
            return 'starlite-akuisisi';
        }
        if (in_array($p, ['psbnew', 'psb'], true)) {
            return 'psb-new';
        }

        return $p ?: $area;
    }

    protected function canonicalArea(mixed $rawArea, mixed $siteKey = null, mixed $areaName = null): string
    {
        $area = $this->classifications->physicalArea(
            $rawArea === null ? null : (string) $rawArea,
            $areaName === null ? null : (string) $areaName,
            $siteKey === null ? null : (string) $siteKey,
        );

        return $area['value'] ?: 'review';
    }

    /**
     * @return array{rw: string, needs_review: bool}
     */
    protected function canonicalRw(mixed $raw, string $area, string $project): array
    {
        $value = trim((string) $raw);
        if ($project === 'starlite-randuagung') {
            return ['rw' => 'RW 4', 'needs_review' => false];
        }

        if ($value !== '' && preg_match('/(?:rw\\s*)?(\\d{1,3})/i', $value, $m)) {
            return ['rw' => 'RW '.((int) $m[1]), 'needs_review' => false];
        }

        return ['rw' => '(review)', 'needs_review' => true];
    }

    protected function subArea(mixed $raw): string
    {
        $value = trim((string) $raw);

        return $value !== '' ? $value : '-';
    }

    protected function psbTarget(array $filters, Carbon $from, Carbon $to): int
    {
        if ($filters['project'] !== 'all' && $filters['project'] !== 'starlite-randuagung') {
            return 0;
        }
        $site = $filters['area'] === 'all' ? 'ra' : $this->siteFilterForPsb($filters['area']);
        $daily = $site ? $this->sites->targetForSite($site) : $this->sites->globalDailyTarget();
        $days = max(1, $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1);

        return max(0, (int) $daily * $days);
    }

    protected function siteFilterForPsb(string $area): ?string
    {
        if ($area === 'all') {
            return null;
        }
        if (in_array($area, ['randuagung', 'gondang', 'suko', 'kalirejo', 'randutelu', 'capang'], true)) {
            return 'ra';
        }

        return $area;
    }

    protected function isRanduagungStarlitePsbRow(array $row, string $area): bool
    {
        return $this->classifications->isRanduagungSite((string) ($row['site_key'] ?? $row['site_profile_key'] ?? ''))
            || $area === 'randuagung';
    }

    protected function parseMonth(mixed $month): string
    {
        $raw = trim((string) $month);
        if (preg_match('/^\\d{4}-\\d{2}$/', $raw)) {
            try {
                Carbon::createFromFormat('!Y-m', $raw, 'Asia/Jakarta');

                return $raw;
            } catch (\Throwable) {
            }
        }

        return now('Asia/Jakarta')->format('Y-m');
    }

    protected function filterValue(mixed $value): string
    {
        $v = trim((string) $value);

        return $v !== '' ? $v : 'all';
    }

    protected function filterArea(mixed $value): string
    {
        $v = $this->filterValue($value);

        return $v === 'all' ? 'all' : $this->canonicalArea($v);
    }

    protected function filterProject(mixed $value): string
    {
        $v = $this->filterValue($value);

        return $v === 'all' ? 'all' : $this->canonicalProject($v, $this->canonicalArea($v));
    }

    protected function filterRw(mixed $value): string
    {
        $v = $this->filterValue($value);
        if ($v === 'all') {
            return 'all';
        }

        return $this->canonicalRw($v, '', '')['rw'];
    }

    protected function key(string $value): string
    {
        $lower = strtolower($value);

        return preg_replace('/[^a-z0-9]+/', '', $lower) ?? '';
    }

    protected function label(string $value): string
    {
        if ($value === 'all') {
            return 'Semua';
        }

        return str($value)->replace('-', ' ')->title()->toString();
    }

    /**
     * @return array<string, int>
     */
    protected function areaIdsByCanonicalKey(): array
    {
        if (! Schema::hasTable('areas')) {
            return [];
        }

        $areas = [];
        foreach (Area::query()->get(['id', 'name']) as $area) {
            $key = $this->canonicalArea($area->name);
            if (! isset($areas[$key]) || $this->key($area->name) === $key) {
                $areas[$key] = (int) $area->id;
            }
        }

        return $areas;
    }
}
