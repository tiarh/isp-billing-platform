<?php

namespace Tests\Feature;

use App\Models\OltOnu;
use App\Models\PsbDraft;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PsbWaPanelPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_panel_uses_indexable_olt_serial_lookup_and_keeps_rx_payload(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        OltOnu::create([
            'serial_number' => 'FHTT-PERF-001',
            'rx_power_dbm' => -20.5,
            'rx_collected_at' => now(),
        ]);
        PsbDraft::create([
            'status' => PsbDraft::STATUS_WAITING_SN,
            'site_profile_key' => 'ra',
            'full_name' => 'PSB PERFORMANCE FIXTURE',
            'sn' => 'FHTT-PERF-001',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $response = $this->actingAs($admin)
            ->getJson('/api/psb-wa/panel?site=ra&per_page=50');

        $response->assertOk()
            ->assertJsonPath('pagination.per_page', 50)
            ->assertJsonPath('drafts.0.rx_dbm', -20.5)
            ->assertJsonMissingPath('drafts.0.pppoe_password');

        $onuQuery = collect($queries)
            ->first(fn (string $sql): bool => str_contains(strtolower($sql), 'from `olt_onus`'));

        $this->assertNotNull($onuQuery);
        $this->assertStringNotContainsString('upper(serial_number)', strtolower((string) $onuQuery));
        $this->assertMatchesRegularExpression('/serial_number.*\bin\b/i', (string) $onuQuery);
    }
}
