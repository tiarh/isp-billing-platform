<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\Transaction;
use App\Support\AnalyticsCache;
use App\Support\AreaScope;
use App\Support\OpsTime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    /**
     * Display the analytics dashboard
     */
    public function index()
    {
        return inertia('Analytics/Index');
    }

    public function financePerArea()
    {
        return inertia('Finance/PerArea');
    }

    /**
     * Get monthly revenue trend data
     * Returns: total_invoiced, total_collected, outstanding per month
     */
    public function revenueTrend(Request $request)
    {
        $months = $request->input('months', 60);
        $refresh = $request->boolean('refresh', false);
        $user = $request->user();

        $cacheKey = "analytics.revenue_trend.{$months}.".$this->cacheScopeKey($user);

        if ($refresh) {
            Cache::forget($cacheKey);
        }

        $data = Cache::remember($cacheKey, 3600, function () use ($months, $user) {
            $query = Invoice::selectRaw("
                    DATE_FORMAT(period, '%Y-%m') as month,
                    SUM(amount) as total_invoiced,
                    SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END) as total_collected,
                    SUM(CASE WHEN status = 'unpaid' THEN amount ELSE 0 END) as outstanding
                ")
                ->where('period', '>=', OpsTime::now()->startOfMonth()->subMonthsNoOverflow($months));
            AreaScope::applyToInvoices($query, $user);

            return $query->groupBy('month')->orderBy('month')->get();
        });

        return response()->json($data);
    }

    /**
     * Get Monthly Recurring Revenue (MRR) and trend
     */
    public function mrr(Request $request)
    {
        $refresh = $request->boolean('refresh', false);
        $user = $request->user();
        $cacheKey = 'analytics.mrr.'.$this->cacheScopeKey($user);

        if ($refresh) {
            Cache::forget($cacheKey);
        }

        $data = Cache::remember($cacheKey, 3600, function () use ($user) {
            // Current MRR
            $currentMrrQuery = Customer::ebilling()
                ->whereIn('status', ['active', 'isolated'])
                ->join('packages', 'customers.package_id', '=', 'packages.id');
            AreaScope::applyToCustomers($currentMrrQuery, $user);
            $currentMrr = $currentMrrQuery->sum('packages.price');

            // Last month MRR for comparison
            $lastMonthPeriod = OpsTime::now()->startOfMonth()->subMonthNoOverflow()->format('Y-m-d');
            $lastMonthMrrQuery = Invoice::where('period', $lastMonthPeriod);
            AreaScope::applyToInvoices($lastMonthMrrQuery, $user);
            $lastMonthMrr = $lastMonthMrrQuery->sum('amount');

            // Calculate growth
            $growth = 0;
            if ($lastMonthMrr > 0) {
                $growth = round((($currentMrr - $lastMonthMrr) / $lastMonthMrr) * 100, 2);
            }

            // 6-month trend
            $trendQuery = Invoice::selectRaw("
                    DATE_FORMAT(period, '%Y-%m') as month,
                    SUM(amount) as mrr
                ")
                ->where('period', '>=', OpsTime::now()->startOfMonth()->subMonthsNoOverflow(6));
            AreaScope::applyToInvoices($trendQuery, $user);
            $trend = $trendQuery->groupBy('month')->orderBy('month')->get();

            return [
                'current_mrr' => $currentMrr,
                'growth_percentage' => $growth,
                'trend' => $trend,
            ];
        });

        return response()->json($data);
    }

    /**
     * Get collection rate statistics
     */
    public function collectionRate(Request $request)
    {
        $refresh = $request->boolean('refresh', false);
        $user = $request->user();
        $cacheKey = 'analytics.collection_rate.'.$this->cacheScopeKey($user);

        if ($refresh) {
            Cache::forget($cacheKey);
        }

        $data = Cache::remember($cacheKey, 3600, function () use ($user) {
            $currentPeriod = OpsTime::now()->startOfMonth()->format('Y-m-d');

            $statsQuery = Invoice::selectRaw('
                    status,
                    COUNT(*) as count,
                    SUM(amount) as total_amount
                ')
                ->where('period', $currentPeriod);
            AreaScope::applyToInvoices($statsQuery, $user);
            $stats = $statsQuery->groupBy('status')->get()->keyBy('status');

            $totalCount = $stats->sum('count');
            $totalAmount = $stats->sum('total_amount');

            // Calculate average days to payment
            $avgDaysQuery = Transaction::join('invoices', 'transactions.invoice_id', '=', 'invoices.id')
                ->whereBetween('transactions.paid_at', OpsTime::monthBounds(OpsTime::now()->format('Y-m')))
                ->selectRaw('AVG(DATEDIFF(transactions.paid_at, invoices.generated_at)) as avg_days');
            AreaScope::applyToTransactions($avgDaysQuery, $user);
            $avgDaysToPayment = $avgDaysQuery->value('avg_days');

            return [
                'by_status' => $stats,
                'total_count' => $totalCount,
                'total_amount' => $totalAmount,
                'collection_rate' => $totalCount > 0 ? round(($stats->get('paid')->count ?? 0) / $totalCount * 100, 2) : 0,
                'avg_days_to_payment' => round($avgDaysToPayment ?? 0, 1),
            ];
        });

        return response()->json($data);
    }

    /**
     * Get revenue breakdown by area
     */
    public function revenueByArea(Request $request)
    {
        $months = $request->input('months', 3);
        $refresh = $request->boolean('refresh', false);
        $user = $request->user();

        $cacheKey = "analytics.revenue_by_area.{$months}.".$this->cacheScopeKey($user);

        if ($refresh) {
            Cache::forget($cacheKey);
        }

        $data = Cache::remember($cacheKey, 3600, function () use ($months, $user) {
            $query = DB::table('areas')
                ->leftJoin('customers', 'customers.area_id', '=', 'areas.id')
                ->leftJoin('invoices', 'invoices.customer_id', '=', 'customers.id')
                ->where('invoices.period', '>=', OpsTime::now()->startOfMonth()->subMonthsNoOverflow($months))
                ->selectRaw("
                    areas.name as area_name,
                    COUNT(DISTINCT customers.id) as customer_count,
                    SUM(invoices.amount) as total_billed,
                    SUM(CASE WHEN invoices.status = 'paid' THEN invoices.amount ELSE 0 END) as total_collected,
                    ROUND(
                        SUM(CASE WHEN invoices.status = 'paid' THEN invoices.amount ELSE 0 END) / 
                        NULLIF(SUM(invoices.amount), 0) * 100, 
                        2
                    ) as collection_rate
                ")
                ->groupBy('areas.id', 'areas.name');

            if ($user->hasAreaScope()) {
                $areaIds = $user->accessibleAreaIds()->all();
                empty($areaIds) ? $query->whereRaw('1 = 0') : $query->whereIn('areas.id', $areaIds);
            }

            return $query->orderByDesc('total_billed')->limit(10)->get();
        });

        return response()->json($data);
    }

    /**
     * Get package performance analytics
     */
    public function packagePerformance(Request $request)
    {
        $months = $request->input('months', 3);
        $refresh = $request->boolean('refresh', false);
        $user = $request->user();

        $cacheKey = "analytics.package_performance.{$months}.".$this->cacheScopeKey($user);

        if ($refresh) {
            Cache::forget($cacheKey);
        }

        $data = Cache::remember($cacheKey, 3600, function () use ($months, $user) {
            $query = Package::leftJoin('customers', 'customers.package_id', '=', 'packages.id')
                ->leftJoin('invoices', function ($join) use ($months) {
                    $join->on('invoices.customer_id', '=', 'customers.id')
                        ->where('invoices.period', '>=', OpsTime::now()->startOfMonth()->subMonthsNoOverflow($months));
                })
                ->whereIn('customers.status', ['active', 'isolated'])
                ->selectRaw('
                    packages.name as package_name,
                    packages.price,
                    COUNT(DISTINCT customers.id) as active_customers,
                    COALESCE(SUM(invoices.amount), 0) as total_revenue
                ');
            AreaScope::applyToCustomers($query, $user);

            return $query->groupBy('packages.id', 'packages.name', 'packages.price')
                ->orderByDesc('total_revenue')
                ->get();
        });

        return response()->json($data);
    }

    /**
     * Get payment method distribution
     */
    public function paymentMethods(Request $request)
    {
        $months = $request->input('months', 6);
        $refresh = $request->boolean('refresh', false);
        $user = $request->user();

        $cacheKey = "analytics.payment_methods.{$months}.".$this->cacheScopeKey($user);

        if ($refresh) {
            Cache::forget($cacheKey);
        }

        $data = Cache::remember($cacheKey, 3600, function () use ($months, $user) {
            $query = Transaction::selectRaw('
                    method,
                    COUNT(*) as transaction_count,
                    SUM(amount) as total_amount
                ')
                ->whereIn('status', ['verified', 'paid'])
                ->whereDoesntHave('reversals')
                ->where('paid_at', '>=', now()->subMonths($months));
            AreaScope::applyToTransactions($query, $user);

            return $query->groupBy('method')->get();
        });

        return response()->json($data);
    }

    /**
     * Get outstanding revenue aging report
     */
    public function outstandingAging(Request $request)
    {
        $refresh = $request->boolean('refresh', false);
        $user = $request->user();
        $cacheKey = 'analytics.outstanding_aging.'.$this->cacheScopeKey($user);

        if ($refresh) {
            Cache::forget($cacheKey);
        }

        $data = Cache::remember($cacheKey, 3600, function () use ($user) {
            $today = OpsTime::today();
            $query = Invoice::selectRaw("
                    CASE
                        WHEN DATEDIFF(?, due_date) <= 30 THEN '0-30 days'
                        WHEN DATEDIFF(?, due_date) <= 60 THEN '30-60 days'
                        WHEN DATEDIFF(?, due_date) <= 90 THEN '60-90 days'
                        ELSE '90+ days'
                    END as age_bucket,
                    COUNT(*) as invoice_count,
                    SUM(amount) as total_amount
                ", [$today, $today, $today])
                ->where('status', 'unpaid')
                // due_date is a WIB calendar date; "today" is the WIB date, not the UTC clock.
                ->where('due_date', '<', $today);
            AreaScope::applyToInvoices($query, $user);

            return $query->groupBy('age_bucket')
                ->orderByRaw("FIELD(age_bucket, '0-30 days', '30-60 days', '60-90 days', '90+ days')")
                ->get();
        });

        return response()->json($data);
    }

    /**
     * Get customer growth trend
     */
    public function customerGrowth(Request $request)
    {
        $months = $request->input('months', 60);
        $refresh = $request->boolean('refresh', false);
        $user = $request->user();

        $cacheKey = "analytics.customer_growth.{$months}.".$this->cacheScopeKey($user);

        if ($refresh) {
            Cache::forget($cacheKey);
        }

        $data = Cache::remember($cacheKey, 3600, function () use ($months, $user) {
            // New customers by month
            $newCustomersQuery = Customer::ebilling()->selectRaw("
                    DATE_FORMAT(join_date, '%Y-%m') as month,
                    COUNT(*) as new_customers
                ")
                ->where('join_date', '>=', OpsTime::now()->startOfMonth()->subMonthsNoOverflow($months));
            AreaScope::applyToCustomers($newCustomersQuery, $user);
            $newCustomers = $newCustomersQuery->groupBy('month')->orderBy('month')->get()->keyBy('month');

            // Active/Isolated count by month (current snapshot)
            $statusCountsQuery = Customer::ebilling()->selectRaw('
                    status,
                    COUNT(*) as count
                ')
                ->whereIn('status', ['active', 'isolated', 'terminated']);
            AreaScope::applyToCustomers($statusCountsQuery, $user);
            $statusCounts = $statusCountsQuery->groupBy('status')->get()->keyBy('status');

            return [
                'new_customers_trend' => $newCustomers,
                'current_status_breakdown' => $statusCounts,
            ];
        });

        return response()->json($data);
    }

    private function cacheScopeKey($user): string
    {
        // Includes the current WIB month (see AnalyticsCache::monthlyScope).
        if ($user->isSuperAdmin()) {
            return AnalyticsCache::monthlyScope('superadmin');
        }

        if ($user->isGlobalAdmin()) {
            return AnalyticsCache::monthlyScope('admin.global');
        }

        $areaIds = $user->accessibleAreaIds()->sort()->values()->implode('-');

        return AnalyticsCache::monthlyScope('admin.scoped.'.($areaIds ?: 'none'));
    }
}
