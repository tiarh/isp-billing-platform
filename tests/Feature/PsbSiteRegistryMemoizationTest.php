<?php

namespace Tests\Feature;

use App\Services\PsbSiteRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PsbSiteRegistryMemoizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The PSB WA KPI loop resolves the site for every row via byRouterId()/
     * byOltId(). Hydration used to run Router/Olt queries each call (N+1) and
     * made the dashboard take ~40s. Memoization must keep it O(1) per lookup.
     */
    public function test_site_hydration_is_memoized_across_repeated_lookups(): void
    {
        $registry = app(PsbSiteRegistry::class);
        $registry->all(); // warm the settings cache before counting

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        for ($i = 0; $i < 25; $i++) {
            $registry->byRouterId(513);
            $registry->byOltId(1);
            $registry->byKey('ra');
        }

        $this->assertLessThan(
            8,
            $queries,
            "Site hydration must be memoized, but {$queries} queries ran for 75 lookups.",
        );
    }
}
