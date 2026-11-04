<?php

namespace Tests\Feature;

use App\Http\Controllers\OltC300Controller;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * STEP 2026-10-01 (owner): C300 traffic limits are FIXED per OLT, not derived
 * from the PSB paket:
 *   OLT RA (1)    -> UP500M / DW500M
 *   OLT KRIAN (9) -> UP375M / DW375M
 * Paket from the WA format stays billing data only; per-user speed is enforced
 * by the MikroTik profile.
 *
 * The limit is keyed by OLT id and reads the `psb_olt_traffic_limits` setting,
 * so this needs the application container and database (Tests\TestCase), not
 * a bare PHPUnit TestCase.
 */
class C300FixedTrafficLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_known_olts_return_their_fixed_limits(): void
    {
        $this->assertSame(500, $this->limit(1));
        $this->assertSame(375, $this->limit(9));
    }

    public function test_unknown_olt_returns_null_so_paket_still_applies(): void
    {
        $this->assertNull($this->limit(999));
    }

    public function test_setting_override_wins(): void
    {
        Setting::set('psb_olt_traffic_limits', json_encode(['9' => 200]));
        $this->assertSame(200, $this->limit(9));
        $this->assertSame(500, $this->limit(1));
    }

    private function limit(int $oltId): ?int
    {
        $method = new ReflectionMethod(OltC300Controller::class, 'c300FixedTrafficLimit');

        return $method->invoke(null, $oltId);
    }
}
