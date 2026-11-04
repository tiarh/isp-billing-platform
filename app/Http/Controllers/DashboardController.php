<?php

namespace App\Http\Controllers;

use App\Enums\CustomerStatus;
use App\Models\Area;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Services\OperationalAreaDashboardService;
use App\Support\AreaScope;
use App\Support\OpsTime;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DashboardController extends Controller
{
    /**
     * Display the enhanced dashboard with accounting widgets
     */
    public function index(Request $request, OperationalAreaDashboardService $operationalArea)
    {
        $user = $request->user();
        $currentPeriod = OpsTime::now()->startOfMonth()->format('Y-m-d');
        $canViewBilling = $user?->isAdminLike() ?? false;

        $payload = $canViewBilling
            ? Cache::remember(
                'dashboard.core.v2.'.md5(json_encode([$currentPeriod, $this->cacheScopeKey($user)])),
                now()->addSeconds(60),
                fn () => $this->buildCorePayload($user, $currentPeriod)
            )
            : [
                // Technicians may use the operational dashboard, but must not receive billing data.
                'recent_payments' => [],
                'by_location' => [],
            ];

        // The dashboard only shows a compact, unfiltered summary. Detailed filters
        // live in Keuangan > Operasional, so ops_* query params are ignored here.
        $summaryRequest = Request::create($request->url(), 'GET');
        $summaryRequest->setUserResolver($request->getUserResolver());
        $operational = Arr::only(
            $operationalArea->summary($summaryRequest),
            ['summary', 'last_sync', 'stale', 'drilldowns']
        );

        return Inertia::render('Dashboard', $payload + [
            'can_view_billing' => $canViewBilling,
            'area_label' => $this->areaLabel($user),
            'operational_area' => $operational,
        ]);
    }

    private function areaLabel(?User $user): string
    {
        if ($user && method_exists($user, 'hasAreaScope') && $user->hasAreaScope()) {
            $areaIds = $user->accessibleAreaIds()->unique()->values()->all();
            if (count($areaIds) === 1) {
                return (string) (Area::query()->whereKey($areaIds[0])->value('name') ?? '1 area');
            }

            return count($areaIds).' area';
        }

        return 'Semua area';
    }

    protected function buildCorePayload(?User $user, string $currentPeriod): array
    {
        // Projected Revenue — SQL sum (never load all customers into memory / HTML)
        $projectedQuery = Customer::ebilling()
            ->where('customers.status', 'active')
            ->leftJoin('packages', 'packages.id', '=', 'customers.package_id');
        AreaScope::applyToCustomers($projectedQuery, $user);
        $projectedRevenue = (float) $projectedQuery->sum('packages.price');

        // Actual Revenue (effective, non-reversed payments this month)
        $actualRevenueQuery = Transaction::whereIn('status', ['verified', 'paid'])
            ->whereDoesntHave('reversals')
            ->whereBetween('paid_at', OpsTime::monthBounds(OpsTime::now()->format('Y-m')));
        AreaScope::applyToTransactions($actualRevenueQuery, $user);
        $actualRevenue = $actualRevenueQuery->sum('amount');

        // Outstanding (Projected - Actual)
        $outstanding = $projectedRevenue - $actualRevenue;

        // Overdue Invoices Count (Active Overdue)
        // Invoices that are unpaid AND past due date + grace period
        $gracePeriod = (int) Setting::get('billing_grace_period_days', 7);
        // due_date is a WIB calendar date: compare it with the WIB date, not the UTC clock.
        $overdueCutoff = OpsTime::now()->subDays($gracePeriod)->toDateString();

        $overdueQuery = Invoice::where('status', 'unpaid')
            // "Past grace": same rule as BillingEligibilityService (due <= today - grace).
            ->where('due_date', '<=', $overdueCutoff);
        AreaScope::applyToInvoices($overdueQuery, $user);
        $overdueCount = $overdueQuery->count();

        // Active Customer Count
        $activeCustomersQuery = Customer::ebilling()->where('status', 'active');
        AreaScope::applyToCustomers($activeCustomersQuery, $user);
        $activeCustomers = $activeCustomersQuery->count();

        // Billing Health Stats
        $paidInvoicesQuery = Invoice::where('period', $currentPeriod)->where('status', 'paid');
        AreaScope::applyToInvoices($paidInvoicesQuery, $user);
        $paidInvoicesCount = $paidInvoicesQuery->count();

        $unpaidInvoicesQuery = Invoice::where('period', $currentPeriod)->where('status', 'unpaid');
        AreaScope::applyToInvoices($unpaidInvoicesQuery, $user);
        $unpaidInvoicesCount = $unpaidInvoicesQuery->count();
        $totalBillable = $paidInvoicesCount + $unpaidInvoicesCount;
        $collectionRate = $totalBillable > 0 ? round(($paidInvoicesCount / $totalBillable) * 100, 1) : 0;

        // Customers who should have an invoice but don't
        $customersWithInvoiceQuery = Invoice::where('period', $currentPeriod);
        AreaScope::applyToInvoices($customersWithInvoiceQuery, $user);
        $customersWithInvoice = $customersWithInvoiceQuery->pluck('customer_id');

        $customersWithoutInvoiceQuery = Customer::ebilling()
            ->whereIn('status', ['active', 'isolated'])
            ->whereHas('package')
            ->whereNotIn('id', $customersWithInvoice);
        AreaScope::applyToCustomers($customersWithoutInvoiceQuery, $user);
        $customersWithoutInvoice = $customersWithoutInvoiceQuery->count();

        // Recent Payments — slim fields only
        $recentPaymentsQuery = Transaction::query()
            ->with([
                // Keep this projection aligned with the production invoice schema.
                // The dashboard only needs the relation for period/customer data.
                'invoice:id,customer_id,code,amount,period',
                'invoice.customer' => fn ($query) => $query->withTrashed()->select('id', 'name', 'code'),
                'admin:id,name',
            ])
            ->whereIn('status', ['verified', 'paid'])
            ->whereDoesntHave('reversals')
            ->select(['id', 'invoice_id', 'admin_id', 'amount', 'channel', 'method', 'reference', 'status', 'paid_at'])
            ->orderByDesc('paid_at')
            ->limit(5);
        AreaScope::applyToTransactions($recentPaymentsQuery, $user);
        $recentPayments = $recentPaymentsQuery->get()->each(function (Transaction $t): void {
            // Applied customer credit is stored as manual/other; show it as "saldo".
            if (str_starts_with((string) $t->reference, 'CREDIT-')) {
                $t->method = 'saldo';
            }
            $t->makeHidden('reference');
        });

        // Per-lokasi (cabang) — read-only; AreaScope for branch users
        $byLocation = $this->locationBreakdown($user);

        return [
            'stats' => [
                'projected_revenue' => $projectedRevenue,
                'actual_revenue' => $actualRevenue,
                'outstanding' => $outstanding,
                'overdue_count' => $overdueCount,
                'active_customers' => $activeCustomers,
                'paid_invoices' => $paidInvoicesCount,
                'unpaid_invoices' => $unpaidInvoicesCount,
                'collection_rate' => $collectionRate,
                'customers_without_invoice' => $customersWithoutInvoice,
            ],
            'recent_payments' => $recentPayments,
            'by_location' => $byLocation,
        ];
    }

    /**
     * MVP dashboard cabang: counts Aktif / Isolir / Dismantle + unpaid per area.
     * Does not touch PSB WA / OLT activation paths.
     *
     * @return list<array<string, mixed>>
     */
    protected function locationBreakdown(?User $user): array
    {
        $cust = Customer::ebilling()
            ->selectRaw('COALESCE(area_id, 0) as area_id')
            ->selectRaw("SUM(CASE WHEN status = '".CustomerStatus::Active->value."' THEN 1 ELSE 0 END) as active_count")
            ->selectRaw("SUM(CASE WHEN status = '".CustomerStatus::Isolated->value."' THEN 1 ELSE 0 END) as isolated_count")
            ->selectRaw("SUM(CASE WHEN status = '".CustomerStatus::Terminated->value."' THEN 1 ELSE 0 END) as dismantled_count")
            ->selectRaw('COUNT(*) as total_count')
            ->groupBy(DB::raw('COALESCE(area_id, 0)'));

        AreaScope::applyToCustomers($cust, $user);
        $rows = $cust->get()->keyBy(fn ($r) => (int) $r->area_id);

        $unpaid = Invoice::query()
            ->where('invoices.status', 'unpaid')
            ->join('customers', 'customers.id', '=', 'invoices.customer_id')
            ->selectRaw('COALESCE(customers.area_id, 0) as area_id')
            ->selectRaw('COUNT(*) as unpaid_count')
            ->selectRaw('COALESCE(SUM(invoices.amount), 0) as unpaid_sum')
            ->groupBy(DB::raw('COALESCE(customers.area_id, 0)'));

        if ($user && method_exists($user, 'hasAreaScope') && $user->hasAreaScope()) {
            $areaIds = $user->accessibleAreaIds()->all();
            if ($areaIds === []) {
                $unpaid->whereRaw('1 = 0');
            } else {
                $unpaid->whereIn('customers.area_id', $areaIds);
            }
        }
        $unpaidRows = $unpaid->get()->keyBy(fn ($r) => (int) $r->area_id);

        $areaIds = $rows->keys()->merge($unpaidRows->keys())->unique()->filter()->values();
        $names = Area::query()->whereIn('id', $areaIds->all())->pluck('name', 'id');

        $out = [];
        $allKeys = $rows->keys()->merge($unpaidRows->keys())->unique()->sort()->values();
        foreach ($allKeys as $aid) {
            $aid = (int) $aid;
            $c = $rows->get($aid);
            $u = $unpaidRows->get($aid);
            $out[] = [
                'area_id' => $aid > 0 ? $aid : null,
                'area_name' => $aid > 0 ? ($names[$aid] ?? "Area #{$aid}") : '(tanpa lokasi)',
                'active' => (int) ($c->active_count ?? 0),
                'isolated' => (int) ($c->isolated_count ?? 0),
                'berhenti' => (int) ($c->dismantled_count ?? 0),
                'total' => (int) ($c->total_count ?? 0),
                'unpaid_invoices' => (int) ($u->unpaid_count ?? 0),
                'unpaid_amount' => (float) ($u->unpaid_sum ?? 0),
            ];
        }

        // Sort: most active first
        usort($out, fn ($a, $b) => ($b['active'] + $b['isolated']) <=> ($a['active'] + $a['isolated']));

        return $out;
    }

    private function cacheScopeKey(?User $user): string
    {
        if (! $user) {
            return 'guest';
        }
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return 'superadmin';
        }
        if (method_exists($user, 'isGlobalAdmin') && $user->isGlobalAdmin()) {
            return 'admin.global';
        }
        if (method_exists($user, 'hasAreaScope') && $user->hasAreaScope()) {
            return 'areas.'.implode('-', $user->accessibleAreaIds()->sort()->values()->all());
        }

        return 'user.'.$user->id;
    }
}
