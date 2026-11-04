<?php

namespace Tests\Feature;

use App\Models\Olt;
use App\Models\User;
use App\Services\HuaweiOltOnlyProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-psb')]
class OltHuaweiUiProvisionTest extends TestCase
{
    use RefreshDatabase;

    public function test_detect_rejects_a_non_huawei_olt(): void
    {
        $olt = $this->olt('OLT RA', 'zte_c300');

        $this->actingAs($this->admin())
            ->postJson('/api/olt-huawei/detect', ['olt_id' => $olt->id, 'sn' => 'FHTTTEST0001'])
            ->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    public function test_detect_returns_placement_for_a_huawei_olt(): void
    {
        $olt = $this->olt('OLT GADANG 3', 'huawei_ma5608t');
        $this->fakeHuawei($olt, [
            'matches' => [['pon_port' => '0/0/7', 'serial_number' => 'FHTTTEST0001', 'vendor_id' => 'FHTT']],
        ], ['onus' => [['onu_ref' => '0/0/7:1', 'onu_id' => 1]]]);

        $this->actingAs($this->admin())
            ->postJson('/api/olt-huawei/detect', ['olt_id' => $olt->id, 'sn' => 'FHTTTEST0001'])
            ->assertOk()
            ->assertJson([
                'success' => true,
                'pon_port' => '0/0/7',
                'onu_id' => 2,
                'vlan' => 108,
                'tr069_vlan' => 69,
            ]);
    }

    public function test_detect_blocks_when_sn_is_not_in_autofind_or_snapshot(): void
    {
        $olt = $this->olt('OLT GADANG 3', 'huawei_ma5608t');
        $this->fakeHuawei($olt, ['matches' => []], ['onus' => []], []);

        $this->actingAs($this->admin())
            ->postJson('/api/olt-huawei/detect', ['olt_id' => $olt->id, 'sn' => 'FHTTUNKNOWN1'])
            ->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    public function test_provision_dry_run_returns_a_plan_without_writing(): void
    {
        $olt = $this->olt('OLT GADANG 3', 'huawei_ma5608t');
        $this->fakeHuawei($olt, [
            'matches' => [['pon_port' => '0/0/7', 'serial_number' => 'FHTTTEST0001', 'vendor_id' => 'FHTT']],
        ], ['onus' => []]);

        $this->actingAs($this->admin())
            ->postJson('/api/olt-huawei/provision', [
                'olt_id' => $olt->id,
                'sn' => 'FHTTTEST0001',
                'name' => 'USER_RT1_RW1_1.1',
                'dry_run' => true,
            ])
            ->assertOk()
            ->assertJson(['success' => true, 'dry_run' => true])
            // olt-safe-output middleware redacts raw device commands in the JSON.
            ->assertJsonPath('plan.commands', '[REDACTED_DEVICE_COMMANDS]')
            ->assertJsonPath('plan.internet_vlan', 108);
    }

    public function test_provision_requires_a_valid_name(): void
    {
        $olt = $this->olt('OLT GADANG 3', 'huawei_ma5608t');

        $this->actingAs($this->admin())
            ->postJson('/api/olt-huawei/provision', [
                'olt_id' => $olt->id,
                'sn' => 'FHTTTEST0001',
                'name' => 'bad name!',
                'dry_run' => true,
            ])
            ->assertStatus(422);
    }

    /** @param array<string, mixed> $autofind @param array<string, mixed> $portOnus */
    private function fakeHuawei(Olt $olt, array $autofind, array $portOnus, ?array $snapshot = null): void
    {
        $fake = Mockery::mock(HuaweiOltOnlyProvisioningService::class);
        $fake->shouldReceive('canonicalSerial')->andReturnUsing(
            fn (string $value): string => strtoupper(preg_replace('/[^A-Z0-9]/i', '', trim($value)) ?? ''),
        );
        $fake->shouldReceive('uiPlacementRead')->andReturnUsing(function (Olt $olt, string $sn) use ($autofind, $portOnus): array {
            $matches = array_values(array_filter(
                (array) ($autofind['matches'] ?? []),
                fn (array $row): bool => $row['serial_number'] === $sn,
            ));
            $entries = array_map(fn (array $row): array => ['sn' => $row['serial_number']] + $row, $matches);

            return [
                'entries' => $entries,
                'matches' => array_map(fn (array $row): array => ['sn' => $row['serial_number']] + $row, $matches),
                'port' => count($matches) === 1 ? $portOnus + ['pon_port' => $matches[0]['pon_port'], 'occupied_ids' => []] : null,
            ];
        });
        $fake->shouldReceive('collectPortOnus')->andReturn($portOnus + ['occupied_ids' => []]);
        $fake->shouldReceive('collectSnapshot')->andReturn($snapshot ?? ['onus' => []]);
        $fake->shouldReceive('normalizePonPort')->andReturnUsing(
            fn (string $value): ?string => preg_match('/^0\/0\/\d{1,2}$/', trim($value)) ? trim($value) : null,
        );
        $fake->shouldReceive('nextOnuId')->andReturnUsing(function (array $onus): int {
            $ids = array_map(fn ($o) => (int) ($o['onu_id'] ?? 0), $onus);

            return ($ids ? max($ids) : 0) + 1;
        });
        $fake->shouldReceive('profileFor')->andReturn(['vlan' => 108, 'line_profile' => 26, 'tr069_vlan' => 69]);
        $fake->shouldReceive('buildPlan')->andReturn([
            'commands' => ['config', 'interface gpon 0/0'],
            'internet_vlan' => 108,
            'tr069_vlan' => 69,
        ]);
        $this->app->instance(HuaweiOltOnlyProvisioningService::class, $fake);
    }

    private function olt(string $name, string $vendor): Olt
    {
        return Olt::create([
            'name' => $name,
            'code' => 'OLT-'.substr(md5($name), 0, 6),
            'vendor' => $vendor,
            'management_ip' => '198.18.4.1',
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'superadmin']);
    }
}
