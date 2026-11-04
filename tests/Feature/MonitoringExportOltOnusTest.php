<?php

namespace Tests\Feature;

use App\Models\Olt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-core')]
class MonitoringExportOltOnusTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_rejects_missing_or_wrong_token_with_401(): void
    {
        config(['services.monitoring_export.token' => 'canary-pw']);
        Olt::create(['name' => 'OLT EXPORT', 'code' => 'OLT-EXP-2', 'vendor' => 'zte_c300']);

        $this->getJson(route('api.monitoring.export'))->assertUnauthorized();
        $this->withToken('wrong-canary')->getJson(route('api.monitoring.export'))->assertUnauthorized();
        $this->withHeader('X-Monitoring-Sync-Token', 'wrong-canary')->getJson(route('api.monitoring.export'))->assertUnauthorized();
    }

    public function test_export_with_valid_token_returns_olt_onus_from_cached_snapshot(): void
    {
        config(['services.monitoring_export.token' => 'canary-pw']);
        Olt::create([
            'name' => 'OLT EXPORT',
            'code' => 'OLT-EXP-1',
            'vendor' => 'zte_c300',
            'last_gpon_snapshot' => ['onus' => [[
                'onu_ref' => 'gpon-onu_1/1/1:1',
                'pon_port' => '1/1/1',
                'state' => 'online',
                'serial_number' => 'ZTEGEXPORT01',
                'onu_name' => 'Pelanggan X',
            ]]],
            'last_gpon_synced_at' => now(),
        ]);

        $response = $this->withToken('canary-pw')->getJson(route('api.monitoring.export'));

        $response->assertOk()
            ->assertJsonStructure(['exported_at', 'stats' => ['olt_onus'], 'customers', 'routers', 'olt_onus'])
            ->assertJsonPath('stats.olt_onus', 1)
            ->assertJsonPath('olt_onus.0.serial_number', 'ZTEGEXPORT01')
            ->assertJsonPath('olt_onus.0.olt_name', 'OLT EXPORT');
    }
}
