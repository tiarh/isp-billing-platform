<?php

namespace Tests\Feature;

use App\Http\Controllers\MonitoringController;
use App\Models\Area;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Audit 2026-10-06 (P2, P9, P3): the same scope/setting lookups were repeated
 * as fresh queries / cache round-trips many times per request.
 */
#[Group('blocking-core')]
class RequestMemoPerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Setting::memoizeForRequest(null);
        parent::tearDown();
    }

    public function test_area_scope_lookups_query_the_pivot_once_per_user_instance(): void
    {
        $areaA = Area::create(['name' => 'Area A', 'code' => 'AA']);
        $areaB = Area::create(['name' => 'Area B', 'code' => 'AB']);
        $admin = User::factory()->create(['role' => 'admin']);
        $admin->areas()->attach([$areaA->id, $areaB->id]);
        $admin = $admin->fresh();

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->assertTrue($admin->hasAreaScope());
        $this->assertFalse($admin->isGlobalAdmin());
        $this->assertSame([$areaA->id, $areaB->id], $admin->accessibleAreaIds()->sort()->values()->all());
        $this->assertTrue($admin->accessibleAreaIds()->contains($areaB->id));
        $this->assertTrue($admin->hasAreaScope());

        $pivotQueries = collect(DB::getQueryLog())
            ->filter(fn (array $q) => str_contains($q['query'], 'area_user') || str_contains($q['query'], 'areas'))
            ->count();
        DB::disableQueryLog();

        $this->assertSame(1, $pivotQueries, 'hasAreaScope/isGlobalAdmin/accessibleAreaIds harus memakai satu query per instance.');
    }

    public function test_area_scope_memo_is_dropped_after_forget(): void
    {
        $area = Area::create(['name' => 'Area C', 'code' => 'AC']);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->assertFalse($admin->hasAreaScope());

        $admin->areas()->attach($area->id);
        $this->assertFalse($admin->hasAreaScope(), 'memo masih berlaku sampai forgetAreaScopeMemo()');

        $admin->forgetAreaScopeMemo();
        $this->assertTrue($admin->hasAreaScope());
        $this->assertSame([$area->id], $admin->accessibleAreaIds()->all());
    }

    public function test_teknisi_never_queries_the_area_pivot(): void
    {
        $teknisi = User::factory()->create(['role' => 'teknisi']);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->assertFalse($teknisi->hasAreaScope());
        $this->assertFalse($teknisi->isGlobalAdmin());
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(0, $count);
    }

    public function test_setting_get_is_memoized_per_request_and_flushed_on_set(): void
    {
        Setting::memoizeForRequest(true);
        Cache::flush();
        Setting::set('memo_probe', 'first');

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->assertSame('first', Setting::get('memo_probe'));
        $this->assertSame('first', Setting::get('memo_probe'));
        $this->assertSame('first', Setting::get('memo_probe'));
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(1, $queries, 'panggilan berulang dalam satu request tidak boleh menyentuh DB lagi');

        // set() must invalidate both the shared cache and the request memo.
        Setting::set('memo_probe', 'second');
        $this->assertSame('second', Setting::get('memo_probe'));
    }

    public function test_missing_setting_key_is_not_queried_on_every_call(): void
    {
        Setting::memoizeForRequest(false);
        Cache::flush();

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->assertSame('dflt-a', Setting::get('does_not_exist_probe', 'dflt-a'));
        $this->assertSame('dflt-b', Setting::get('does_not_exist_probe', 'dflt-b'));
        $this->assertNull(Setting::get('does_not_exist_probe'));
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Previously: a null-default miss was never cached (1 query per call)
        // and the first caller's default was cached forever for everyone.
        $this->assertSame(1, $queries);

        Setting::set('does_not_exist_probe', 'now-exists');
        $this->assertSame('now-exists', Setting::get('does_not_exist_probe', 'dflt-c'));
    }

    public function test_setting_boolean_false_is_cached_and_returned(): void
    {
        Setting::memoizeForRequest(false);
        Cache::flush();
        Setting::set('memo_bool_probe', '0', 'boolean');

        $this->assertFalse(Setting::get('memo_bool_probe', true));
        $this->assertFalse(Setting::get('memo_bool_probe', true));
    }

    public function test_monitoring_viewer_revalidation_threshold_is_close_to_cache_ttl(): void
    {
        // Pre-warm runs every 5 min with a 6 min TTL (RefreshMonitoringCacheJob). A 30s threshold made every
        // open tab dispatch a full generatePayload about every 30s.
        $this->assertGreaterThanOrEqual(60, MonitoringController::REVALIDATE_AFTER_SECONDS);
        $this->assertLessThan(120, MonitoringController::REVALIDATE_AFTER_SECONDS);
    }
}
