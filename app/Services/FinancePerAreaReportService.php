<?php

namespace App\Services;

use App\Models\Area;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

class FinancePerAreaReportService
{
    public const FORMULA_VERSION = 'finance-per-area-v3';

    private const TIMEZONE = 'Asia/Jakarta';

    public function summary(User $user, string $period, ?int $areaId, ?string $asOf): array
    {
        $periodDate = $this->periodDate($period);
        $asOfDate = $this->asOfDate($asOf);
        $scope = $this->scopeKey($user);
        $fingerprint = $this->dataFingerprint();
        $key = 'finance.per_area.summary.'.sha1(json_encode([
            self::FORMULA_VERSION,
            $period,
            $areaId,
            $asOfDate->toDateString(),
            $scope,
            $fingerprint,
        ], JSON_THROW_ON_ERROR));

        return $this->rememberWithLock($key, fn (): array => $this->buildSummary(
            $user,
            $periodDate,
            $areaId,
            $asOfDate,
        ));
    }

    public function detail(User $user, string $period, ?int $areaId, ?string $asOf, array $filters): array
    {
        $periodDate = $this->periodDate($period);
        $asOfDate = $this->asOfDate($asOf);
        $gracePeriodDays = $this->gracePeriodDays();
        $query = $this->invoiceRowsQuery($user, $periodDate, $areaId);

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function ($builder) use ($search): void {
                $like = '%'.$search.'%';
                $builder->where('c.code', 'like', $like)
                    ->orWhere('c.name', 'like', $like)
                    ->orWhere('c.pppoe_user', 'like', $like)
                    ->orWhere('i.code', 'like', $like);
            });
        }

        if (isset($filters['package_id'])) {
            $query->where('c.package_id', (int) $filters['package_id']);
        }

        $this->applyDerivedFilters($query, $filters, $asOfDate);

        $sort = $filters['sort'] ?? 'outstanding_desc';
        if ($sort === 'due_date_asc') {
            $query->orderBy('i.due_date')->orderBy('c.name');
        } elseif ($sort === 'name_asc') {
            $query->orderBy('c.name')->orderBy('i.id');
        } else {
            $effective = $this->effectivePaidSql();
            $outstanding = "CASE WHEN {$effective} < i.amount THEN i.amount - {$effective} ELSE 0 END";
            $query->orderByRaw($outstanding.' DESC')->orderBy('c.name')->orderBy('i.id');
        }

        $paginator = $query->paginate((int) ($filters['per_page'] ?? 50))->withQueryString();
        $paginator->getCollection()->transform(
            function (object $row) use ($asOfDate, $gracePeriodDays): array {
                $formatted = $this->formatInvoiceRow($row, $asOfDate, $gracePeriodDays);

                foreach (array_keys($formatted) as $key) {
                    if (str_starts_with($key, '_')) {
                        unset($formatted[$key]);
                    }
                }

                return $formatted;
            },
        );

        return [
            'period' => $period,
            'scope' => $this->scopeLabel($user, $areaId),
            'as_of' => $asOfDate->toDateString(),
            'formula_version' => self::FORMULA_VERSION,
            'stale' => false,
            'generated_at' => now(self::TIMEZONE)->toIso8601String(),
            'area_id' => $areaId,
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    public function exportRows(User $user, string $period, ?int $areaId, ?string $asOf, array $filters): LazyCollection
    {
        $periodDate = $this->periodDate($period);
        $asOfDate = $this->asOfDate($asOf);
        $gracePeriodDays = $this->gracePeriodDays();
        $query = $this->invoiceRowsQuery($user, $periodDate, $areaId);

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function ($builder) use ($search): void {
                $like = '%'.$search.'%';
                $builder->where('c.code', 'like', $like)
                    ->orWhere('c.name', 'like', $like)
                    ->orWhere('c.pppoe_user', 'like', $like)
                    ->orWhere('i.code', 'like', $like);
            });
        }

        if (isset($filters['package_id'])) {
            $query->where('c.package_id', (int) $filters['package_id']);
        }

        $this->applyDerivedFilters($query, $filters, $asOfDate);

        if (! empty($filters['outstanding_only'])) {
            $effective = $this->effectivePaidSql();
            $settled = "({$effective} >= i.amount OR (i.status = 'paid' AND COALESCE(pt.transaction_count, 0) = 0))";
            $query->whereRaw('NOT '.$settled);
        }

        return LazyCollection::make(function () use ($query, $asOfDate, $gracePeriodDays): \Generator {
            foreach ($query->orderBy('i.id')->cursor() as $row) {
                $formatted = $this->formatInvoiceRow($row, $asOfDate, $gracePeriodDays);

                foreach (array_keys($formatted) as $key) {
                    if (str_starts_with($key, '_')) {
                        unset($formatted[$key]);
                    }
                }

                yield $formatted;
            }
        });
    }

    private function buildSummary(
        User $user,
        CarbonImmutable $period,
        ?int $areaId,
        CarbonImmutable $asOf,
    ): array {
        $current = $this->buildSnapshot($user, $period, $areaId, $asOf);
        $previous = $this->buildSnapshot($user, $period->subMonthNoOverflow(), $areaId, $asOf);
        $previousByArea = collect($previous['areas'])->keyBy(fn (array $row): string => (string) ($row['area_id'] ?? 'unassigned'));

        foreach ($current['areas'] as &$area) {
            $previousArea = $previousByArea->get((string) ($area['area_id'] ?? 'unassigned'));
            $area['collection_rate_mom_delta'] = $previousArea
                && $area['collection_rate'] !== null
                && $previousArea['collection_rate'] !== null
                ? round($area['collection_rate'] - $previousArea['collection_rate'], 2)
                : null;
        }
        unset($area);

        usort($current['areas'], static function (array $left, array $right): int {
            return [$left['collection_rate'] ?? PHP_FLOAT_MAX, $left['area_name']]
                <=> [$right['collection_rate'] ?? PHP_FLOAT_MAX, $right['area_name']];
        });

        $current['scope'] = $this->scopeLabel($user, $areaId);
        $current['previous_period'] = $period->subMonthNoOverflow()->format('Y-m');
        $current['generated_at'] = now(self::TIMEZONE)->toIso8601String();

        return $current;
    }

    private function buildSnapshot(User $user, CarbonImmutable $period, ?int $areaId, CarbonImmutable $asOf): array
    {
        $areas = [];
        $areaQuery = Area::query()->select(['id', 'code', 'name'])->orderBy('name');
        if ($user->hasAreaScope()) {
            $areaQuery->whereIn('id', $user->accessibleAreaIds()->all());
        }
        if ($areaId !== null) {
            $areaQuery->whereKey($areaId);
        }

        foreach ($areaQuery->get() as $area) {
            $areas[(string) $area->id] = $this->emptyArea((int) $area->id, $area->code, $area->name);
        }

        $quality = [
            'missing_area' => 0,
            'missing_due_date' => 0,
            'orphan_customer' => 0,
            'unknown_status' => 0,
            'paid_without_transaction' => 0,
        ];
        $gracePeriodDays = $this->gracePeriodDays();

        foreach ($this->invoiceRowsQuery($user, $period, $areaId)->cursor() as $row) {
            $formatted = $this->formatInvoiceRow($row, $asOf, $gracePeriodDays);
            $key = (string) ($formatted['area_id'] ?? 'unassigned');
            if (! isset($areas[$key])) {
                $areas[$key] = $this->emptyArea(null, null, 'Tanpa area');
            }

            $this->addToArea($areas[$key], $formatted);
            $quality['missing_area'] += $formatted['area_id'] === null ? 1 : 0;
            $quality['missing_due_date'] += $formatted['due_date'] === null ? 1 : 0;
            $quality['orphan_customer'] += $formatted['_orphan_customer'] ? 1 : 0;
            $quality['unknown_status'] += $formatted['status'] === 'unknown' ? 1 : 0;
            $quality['paid_without_transaction'] += $formatted['_legacy_settled'] ? 1 : 0;
        }

        $totals = $this->emptyArea(null, null, 'Semua area');
        foreach ($areas as $area) {
            $this->mergeArea($totals, $area);
        }

        return [
            'period' => $period->format('Y-m'),
            'as_of' => $asOf->toDateString(),
            'formula_version' => self::FORMULA_VERSION,
            'stale' => false,
            'totals' => $this->finalizeArea($totals),
            'areas' => array_values(array_map(fn (array $area): array => $this->finalizeArea($area), $areas)),
            'data_quality' => $quality,
        ];
    }

    private function invoiceRowsQuery(User $user, CarbonImmutable $period, ?int $areaId): QueryBuilder
    {
        $periodStart = $period->startOfMonth()->toDateString();
        $periodEnd = $period->endOfMonth()->toDateString();
        $payments = DB::table('transactions as t')
            ->join('invoices as pi', 'pi.id', '=', 't.invoice_id')
            ->whereBetween('pi.period', [$periodStart, $periodEnd])
            ->select('t.invoice_id')
            ->selectRaw("SUM(CASE WHEN t.status IN ('verified', 'paid') THEN t.amount ELSE 0 END) AS paid_amount")
            ->selectRaw('COUNT(*) AS transaction_count')
            ->groupBy('t.invoice_id');
        $reversals = DB::table('payment_reversals as r')
            ->join('invoices as ri', 'ri.id', '=', 'r.invoice_id')
            ->whereBetween('ri.period', [$periodStart, $periodEnd])
            ->select('r.invoice_id')
            ->selectRaw('SUM(r.amount) AS reversed_amount')
            ->groupBy('r.invoice_id');

        $query = DB::table('invoices as i')
            ->leftJoin('customers as c', 'c.id', '=', 'i.customer_id')
            ->leftJoin('areas as a', 'a.id', '=', 'c.area_id')
            ->leftJoin('packages as p', 'p.id', '=', 'c.package_id')
            ->leftJoinSub($payments, 'pt', 'pt.invoice_id', '=', 'i.id')
            ->leftJoinSub($reversals, 'rt', 'rt.invoice_id', '=', 'i.id')
            ->whereBetween('i.period', [$periodStart, $periodEnd])
            ->where('i.status', '!=', 'void')
            ->select([
                'i.id as invoice_id',
                'i.code as invoice_code',
                'i.amount as invoice_amount',
                'i.status as invoice_status',
                'i.due_date',
                'i.period',
                'i.customer_id as customer_id',
                'c.id as customer_record_id',
                'c.code as customer_code',
                'c.name as customer_name',
                'p.id as package_id',
                'p.name as package_name',
                'a.id as area_id',
                'a.code as area_code',
                'a.name as area_name',
            ])
            ->selectRaw('COALESCE(pt.paid_amount, 0) AS paid_amount')
            ->selectRaw('COALESCE(pt.transaction_count, 0) AS transaction_count')
            ->selectRaw('COALESCE(rt.reversed_amount, 0) AS reversed_amount');

        if ($user->hasAreaScope()) {
            $areaIds = $user->accessibleAreaIds()->all();
            empty($areaIds) ? $query->whereRaw('1 = 0') : $query->whereIn('c.area_id', $areaIds);
        }
        if ($areaId !== null) {
            $query->where('c.area_id', $areaId);
        }

        return $query;
    }

    private function applyDerivedFilters(QueryBuilder $query, array $filters, CarbonImmutable $asOf): void
    {
        $effective = $this->effectivePaidSql();
        $settled = "({$effective} >= i.amount OR (i.status = 'paid' AND COALESCE(pt.transaction_count, 0) = 0))";

        if (($filters['status'] ?? null) === 'paid') {
            $query->whereRaw($settled);
        } elseif (($filters['status'] ?? null) === 'legacy_settled') {
            $query->where('i.status', 'paid')->whereRaw('COALESCE(pt.transaction_count, 0) = 0');
        } elseif (($filters['status'] ?? null) === 'unknown') {
            $query->whereNotIn('i.status', ['paid', 'unpaid', 'overdue']);
        } elseif (in_array($filters['status'] ?? null, ['unpaid', 'overdue'], true)) {
            $query->whereRaw('NOT '.$settled);
            $query->where('i.status', $filters['status']);
        }

        if (($filters['due'] ?? null) === 'unknown') {
            $query->whereNull('i.due_date');
        } elseif (($filters['due'] ?? null) === 'not_due') {
            $query->whereDate('i.due_date', '>', $asOf->toDateString());
        } elseif (($filters['due'] ?? null) === 'overdue') {
            $query->whereDate('i.due_date', '<', $asOf->toDateString());
        }
    }

    private function effectivePaidSql(): string
    {
        $paid = 'COALESCE(pt.paid_amount, 0)';
        $reversed = 'COALESCE(rt.reversed_amount, 0)';

        return "CASE WHEN {$paid} - {$reversed} > 0 THEN {$paid} - {$reversed} ELSE 0 END";
    }

    private function formatInvoiceRow(object $row, CarbonImmutable $asOf, int $gracePeriodDays): array
    {
        $amount = $this->moneyToCents($row->invoice_amount);
        $paid = max(0, $this->moneyToCents($row->paid_amount) - $this->moneyToCents($row->reversed_amount));
        $invoiceStatus = (string) ($row->invoice_status ?? '');
        $legacySettled = $invoiceStatus === 'paid' && (int) $row->transaction_count === 0;
        $settled = $legacySettled || $paid >= $amount;
        $outstanding = $settled ? 0 : max(0, $amount - $paid);
        $overpayment = max(0, $paid - $amount);
        $aging = $this->aging($row->due_date, $outstanding, $asOf, $gracePeriodDays);
        $status = $this->reportStatus($invoiceStatus, $settled, $legacySettled, $aging);

        return [
            'invoice_id' => (int) $row->invoice_id,
            'invoice_code' => (string) $row->invoice_code,
            'customer_id' => (int) $row->customer_id,
            'customer_code' => (string) $row->customer_code,
            'customer_name' => $row->customer_name === null ? null : (string) $row->customer_name,
            'package_id' => $row->package_id === null ? null : (int) $row->package_id,
            'package_name' => $row->package_name,
            'area_id' => $row->area_id === null ? null : (int) $row->area_id,
            'area_code' => $row->area_code,
            'area_name' => $row->area_name,
            'period' => CarbonImmutable::parse($row->period, self::TIMEZONE)->format('Y-m'),
            'amount' => $this->centsToAmount($amount),
            'effective_paid' => $this->centsToAmount($paid),
            'outstanding' => $this->centsToAmount($outstanding),
            'overpayment' => $this->centsToAmount($overpayment),
            'due_date' => $row->due_date ? (string) $row->due_date : null,
            'status' => $status,
            'invoice_status' => $invoiceStatus,
            'aging' => $aging,
            '_orphan_customer' => $row->customer_record_id === null,
            '_amount_cents' => $amount,
            '_paid_cents' => $paid,
            '_legacy_settled' => $legacySettled,
            '_recorded_paid_cents' => $paid + ($legacySettled ? $amount : 0),
            '_outstanding_cents' => $outstanding,
            '_overpayment_cents' => $overpayment,
            '_collected_for_rate_cents' => min($amount, $paid),
        ];
    }

    private function emptyArea(?int $id, ?string $code, string $name): array
    {
        return [
            'area_id' => $id,
            'area_code' => $code,
            'area_name' => $name,
            'invoice_count' => 0,
            'paid_count' => 0,
            'legacy_settled_count' => 0,
            'unpaid_count' => 0,
            'billable_cents' => 0,
            'cash_paid_cents' => 0,
            'legacy_settled_cents' => 0,
            'recorded_paid_cents' => 0,
            'collected_for_rate_cents' => 0,
            'outstanding_cents' => 0,
            'overpayment_cents' => 0,
            'aging' => ['not_due' => 0, '0_7' => 0, '8_30' => 0, 'over_30' => 0, 'unknown' => 0],
            'collection_rate' => 0.0,
        ];
    }

    private function addToArea(array &$area, array $row): void
    {
        $area['invoice_count']++;
        $area['billable_cents'] += $row['_amount_cents'];
        $area['cash_paid_cents'] += $row['_paid_cents'];
        $area['legacy_settled_cents'] += $row['_legacy_settled'] ? $row['_amount_cents'] : 0;
        $area['recorded_paid_cents'] += $row['_recorded_paid_cents'];
        $area['collected_for_rate_cents'] += $row['_collected_for_rate_cents'];
        $area['outstanding_cents'] += $row['_outstanding_cents'];
        $area['overpayment_cents'] += $row['_overpayment_cents'];
        $area['legacy_settled_count'] += $row['_legacy_settled'] ? 1 : 0;
        $area[$row['status'] === 'paid' || $row['status'] === 'legacy_settled' ? 'paid_count' : 'unpaid_count']++;
        foreach ($area['aging'] as $bucket => $_) {
            $area['aging'][$bucket] += ($row['aging']['bucket'] ?? null) === $bucket ? 1 : 0;
        }
    }

    private function finalizeArea(array $area): array
    {
        $area['collection_rate'] = $area['billable_cents'] > 0
            ? round(($area['collected_for_rate_cents'] / $area['billable_cents']) * 100, 2)
            : null;
        $area['billable_amount'] = $this->centsToAmount($area['billable_cents']);
        $area['cash_paid_amount'] = $this->centsToAmount($area['cash_paid_cents']);
        $area['legacy_settled_amount'] = $this->centsToAmount($area['legacy_settled_cents']);
        $area['recorded_paid_amount'] = $this->centsToAmount($area['recorded_paid_cents']);
        $area['outstanding_amount'] = $this->centsToAmount($area['outstanding_cents']);
        $area['overpayment_amount'] = $this->centsToAmount($area['overpayment_cents']);
        unset($area['billable_cents'], $area['cash_paid_cents'], $area['legacy_settled_cents'], $area['recorded_paid_cents'], $area['collected_for_rate_cents'], $area['outstanding_cents'], $area['overpayment_cents']);

        return $area;
    }

    private function mergeArea(array &$total, array $area): void
    {
        foreach (['invoice_count', 'paid_count', 'legacy_settled_count', 'unpaid_count', 'billable_cents', 'cash_paid_cents', 'legacy_settled_cents', 'recorded_paid_cents', 'collected_for_rate_cents', 'outstanding_cents', 'overpayment_cents'] as $key) {
            $value = $area[$key] ?? null;
            if ($value !== null) {
                $total[$key] += $value;
            }
        }
        foreach ($total['aging'] as $bucket => $_) {
            $total['aging'][$bucket] += $area['aging'][$bucket] ?? 0;
        }
    }

    private function aging(?string $dueDate, int $outstanding, CarbonImmutable $asOf, int $gracePeriodDays): array
    {
        if ($outstanding <= 0) {
            return ['bucket' => 'settled', 'days' => 0];
        }
        if (! $dueDate) {
            return ['bucket' => 'unknown', 'days' => null];
        }

        $cutoff = CarbonImmutable::parse($dueDate, self::TIMEZONE)->addDays($gracePeriodDays)->startOfDay();
        if ($asOf->lessThanOrEqualTo($cutoff)) {
            return ['bucket' => 'not_due', 'days' => 0];
        }

        $days = $cutoff->diffInDays($asOf);

        return [
            'bucket' => $days <= 7 ? '0_7' : ($days <= 30 ? '8_30' : 'over_30'),
            'days' => $days,
        ];
    }

    private function gracePeriodDays(): int
    {
        $grace = Setting::get('billing_isolir_after_days');
        if ($grace === null || $grace === '') {
            $grace = Setting::get('billing_grace_period_days', 3);
        }

        return max(0, (int) $grace);
    }

    private function reportStatus(string $invoiceStatus, bool $settled, bool $legacySettled, array $aging): string
    {
        if ($legacySettled) {
            return 'legacy_settled';
        }
        if ($settled) {
            return 'paid';
        }
        if (! in_array($invoiceStatus, ['paid', 'unpaid', 'overdue'], true)) {
            return 'unknown';
        }
        if ($invoiceStatus === 'overdue' || in_array($aging['bucket'], ['0_7', '8_30', 'over_30'], true)) {
            return 'overdue';
        }

        return 'unpaid';
    }

    private function rememberWithLock(string $key, callable $callback): array
    {
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $lock = Cache::lock($key.':lock', 10);
        if ($lock->get()) {
            try {
                $cached = Cache::get($key);
                if (is_array($cached)) {
                    return $cached;
                }
                $value = $callback();
                Cache::put($key, $value, now()->addMinutes(15));

                return $value;
            } finally {
                $lock->release();
            }
        }

        return $callback();
    }

    private function dataFingerprint(): string
    {
        $tables = ['invoices', 'transactions', 'payment_reversals', 'customers', 'areas', 'packages'];
        $state = [];

        foreach ($tables as $table) {
            $state[$table] = [
                'count' => DB::table($table)->count(),
                'updated_at' => (string) DB::table($table)->max('updated_at'),
            ];
        }

        return sha1(json_encode($state, JSON_THROW_ON_ERROR));
    }

    private function scopeKey(User $user): string
    {
        if ($user->isSuperAdmin()) {
            return 'superadmin';
        }
        if ($user->isGlobalAdmin()) {
            return 'admin.global';
        }

        return 'admin.scoped.'.implode('-', $user->accessibleAreaIds()->sort()->values()->all());
    }

    private function scopeLabel(User $user, ?int $areaId): string
    {
        if ($areaId !== null) {
            return 'area:'.$areaId;
        }
        if ($user->hasAreaScope()) {
            return 'areas:'.implode('-', $user->accessibleAreaIds()->sort()->values()->all());
        }

        return 'all';
    }

    private function periodDate(string $period): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d', $period.'-01', self::TIMEZONE)->startOfMonth();
    }

    private function asOfDate(?string $asOf): CarbonImmutable
    {
        return $asOf
            ? CarbonImmutable::createFromFormat('Y-m-d', $asOf, self::TIMEZONE)->startOfDay()
            : CarbonImmutable::now(self::TIMEZONE)->startOfDay();
    }

    private function moneyToCents(mixed $value): int
    {
        $normalized = trim((string) $value);
        if ($normalized === '' || ! preg_match('/^\d+(?:\.\d{1,2})?$/', $normalized)) {
            return 0;
        }
        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    private function centsToAmount(int $cents): string
    {
        return sprintf('%d.%02d', intdiv(max(0, $cents), 100), max(0, $cents) % 100);
    }
}
