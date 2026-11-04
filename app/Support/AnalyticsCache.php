<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

class AnalyticsCache
{
    private const MONTH_VARIANTS = [12, 24, 60];

    public static function flushAll(): void
    {
        foreach (['superadmin', 'admin.global'] as $scope) {
            self::forgetScope($scope);
        }
    }

    public static function flushForUser(?User $user): void
    {
        self::flushAll();

        if (! $user) {
            return;
        }

        self::forgetScope(self::scopeKey($user));
    }

    private static function scopeKey(User $user): string
    {
        if ($user->isSuperAdmin()) {
            return 'superadmin';
        }

        if ($user->isGlobalAdmin()) {
            return 'admin.global';
        }

        $areaIds = $user->accessibleAreaIds()->sort()->values()->implode('-');

        return 'admin.scoped.'.($areaIds ?: 'none');
    }

    /**
     * Cache scope suffix = user scope + current WIB month, so a new WIB month never
     * serves the previous month's cached analytics.
     */
    public static function monthlyScope(string $scope): string
    {
        return $scope.'.'.OpsTime::now()->format('Y-m');
    }

    private static function forgetScope(string $scope): void
    {
        self::forgetKeys($scope);
        self::forgetKeys(self::monthlyScope($scope));
    }

    private static function forgetKeys(string $scope): void
    {
        Cache::forget("analytics.mrr.{$scope}");
        Cache::forget("analytics.collection_rate.{$scope}");
        Cache::forget("analytics.outstanding_aging.{$scope}");

        foreach (self::MONTH_VARIANTS as $months) {
            Cache::forget("analytics.revenue_trend.{$months}.{$scope}");
            Cache::forget("analytics.revenue_by_area.{$months}.{$scope}");
            Cache::forget("analytics.package_performance.{$months}.{$scope}");
            Cache::forget("analytics.payment_methods.{$months}.{$scope}");
            Cache::forget("analytics.customer_growth.{$months}.{$scope}");
        }
    }
}
