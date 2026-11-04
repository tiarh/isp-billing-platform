<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Olt;
use App\Models\Package;
use App\Models\PppoeAddressPoolBlock;
use App\Models\PsbDraft;
use App\Models\Router;
use App\Models\User;
use App\Services\EvolutionApiService;
use App\Services\HuaweiOltOnlyProvisioningService;
use App\Services\HuaweiPsbProvisioningService;
use App\Services\PppoeAddressPoolAllocator;
use App\Services\PsbSiteRegistry;
use App\Services\PsbWaMessages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Canary;
use Tests\TestCase;

#[Group('blocking-psb')]
class PsbHuaweiStagingAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_isolated_gadang_fixture_binds_huawei_packages_allocator_and_roles(): void
    {
        [$area, $router, $olt] = $this->gadangFixture();

        $packages = collect([50, 100, 200])->map(function (int $speed) use ($router): Package {
            $package = Package::create([
                'router_id' => $router->id,
                'code' => "GADANG-{$speed}M",
                'name' => "Gadang {$speed}M",
                'mikrotik_profile' => "GADANG-{$speed}M",
                'rate_limit' => "{$speed}M/{$speed}M",
                'speed_mbps' => $speed,
                'price' => $speed * 1000,
                'is_active' => true,
            ]);
            $package->forceFill(['speed_mbps' => $speed])->save();

            return $package;
        });

        PppoeAddressPoolBlock::create([
            'router_id' => $router->id,
            'site_profile_key' => 'gadang_huawei',
            'pppoe_profile' => 'GADANG-200M',
            'customer_area' => 'gadang',
            'block_name' => 'Gadang staging 50',
            'block_index' => 50,
            'address_format' => 'full_ipv4',
            'prefix_template' => '198.18.36',
            'local_address' => '198.18.36.1',
            'remote_start' => '198.18.36.2',
            'remote_end' => '198.18.36.254',
            'allocator_mode' => 'last_remote_pointer',
            'priority' => 50,
            'is_active' => true,
            'capacity' => 253,
            'available_count' => 253,
            'warning_threshold' => 10,
        ]);

        $technician = User::factory()->create(['role' => 'teknisi']);
        $technician->areas()->attach($area);
        $noc = User::factory()->create(['role' => 'noc']);

        $site = app(PsbSiteRegistry::class)->byKey('gadang_huawei');
        $profile = app(HuaweiOltOnlyProvisioningService::class)->profileFor('0/0/7', 'FHTT0000A03A', null);
        $allocation = app(PppoeAddressPoolAllocator::class)->allocate(
            $router,
            [['local_address' => '198.18.36.1', 'remote_address' => '198.18.36.2']],
            'gadang_huawei',
            'GADANG-200M',
            'Gadang',
        );

        $this->assertSame('Gadang', $area->name);
        $this->assertSame('SKYNET-GADANG', $router->name);
        $this->assertSame(511, (int) $router->id);
        $this->assertSame('OLT GADANG 3', $olt->name);
        $this->assertSame(11, (int) $olt->id);
        $this->assertSame('huawei_ma5608t', $olt->vendor);
        $this->assertSame($router->id, $olt->router_id);
        $this->assertSame(PsbSiteRegistry::MODE_HUAWEI_OLT, $site['mode']);
        $this->assertSame(69, $profile['tr069_vlan']);
        $this->assertArrayNotHasKey('vlan', $site);
        $this->assertSame([50, 100, 200], $packages->pluck('speed_mbps')->all());
        $this->assertSame($router->id, $packages->last()->router_id);
        $this->assertSame('198.18.36.3', $allocation['remote']);
        $this->assertSame('teknisi', $technician->role);
        $this->assertTrue($technician->areas()->whereKey($area->id)->exists());
        $this->assertSame('noc', $noc->role);
        $this->assertDatabaseHas('olts', [
            'id' => 11,
            'router_id' => 511,
            'management_ip' => '198.18.38.2',
            'vendor' => 'huawei_ma5608t',
        ]);
        $this->assertDatabaseHas('pppoe_address_pool_blocks', [
            'router_id' => 511,
            'site_profile_key' => 'gadang_huawei',
            'pppoe_profile' => 'GADANG-200M',
            'last_remote_address' => '198.18.36.3',
        ]);
    }

    public function test_staging_fake_wa_asserts_exact_activation_body_without_network(): void
    {
        config([
            'services.evolution_api.base_url' => 'http://evolution.staging.test',
            'services.evolution_api.api_key' => 'staging-key',
            'services.evolution_api.instance_name' => 'staging-fake',
        ]);
        Http::fake([
            'http://evolution.staging.test/message/sendText/*' => Http::response([
                'key' => ['id' => 'staging-message'],
            ]),
        ]);

        $message = (new PsbWaMessages)->received(1083, 'FIXTURE GADANG');
        $result = (new EvolutionApiService)->sendToGroup('120363-staging@g.us', $message);

        $this->assertTrue($result['success'] ?? false);
        Http::assertSent(function ($request) use ($message): bool {
            return str_contains($request->url(), '/message/sendText/')
                && ($request->data()['number'] ?? null) === '120363-staging@g.us'
                && ($request->data()['text'] ?? null) === $message;
        });
        Http::assertSentCount(1);
    }

    public function test_staging_lock_contention_stops_before_huawei_device_io(): void
    {
        $oltLock = Mockery::mock();
        $oltLock->shouldReceive('block')->once()->with(10)->andReturnFalse();

        $huawei = Mockery::mock(HuaweiOltOnlyProvisioningService::class);
        $huawei->shouldNotReceive('collectAutofind');
        $huawei->shouldNotReceive('executePlan');

        Cache::shouldReceive('lock')
            ->once()
            ->with('psb-huawei-olt-11', 300)
            ->andReturn($oltLock);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Antrian PSB Huawei sedang diproses.');

        $olt = new Olt(['vendor' => 'huawei_ma5608t']);
        $olt->id = 11;

        (new HuaweiPsbProvisioningService($huawei))->provision(
            new PsbDraft(['sn' => 'FHTT0000A03A', 'pppoe_user' => 'STAGING']),
            $olt,
        );
    }

    public function test_staging_same_sn_contention_stops_before_huawei_device_io(): void
    {
        $oltLock = Mockery::mock();
        $oltLock->shouldReceive('block')->once()->with(10)->andReturnTrue();
        $oltLock->shouldReceive('release')->once();

        $snLock = Mockery::mock();
        $snLock->shouldReceive('block')->once()->with(10)->andReturnFalse();

        $huawei = Mockery::mock(HuaweiOltOnlyProvisioningService::class);
        $huawei->shouldNotReceive('collectAutofind');
        $huawei->shouldNotReceive('executePlan');

        Cache::shouldReceive('lock')
            ->once()
            ->with('psb-huawei-olt-11', 300)
            ->andReturn($oltLock);
        Cache::shouldReceive('lock')
            ->once()
            ->with('psb-huawei-sn-FHTT0000A03A', 300)
            ->andReturn($snLock);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SN sedang diproses job PSB lain.');

        $olt = new Olt(['vendor' => 'huawei_ma5608t']);
        $olt->id = 11;

        (new HuaweiPsbProvisioningService($huawei))->provision(
            new PsbDraft(['sn' => 'fhtt0000a03a', 'pppoe_user' => 'STAGING']),
            $olt,
        );
    }

    /** @return array{Area, Router, Olt} */
    private function gadangFixture(): array
    {
        $area = Area::create(['name' => 'Gadang', 'code' => 'GADANG']);

        $router = new Router([
            'name' => 'SKYNET-GADANG',
            'ip_address' => '198.18.38.1',
            'username' => 'fixture-router-user',
            'password' => Canary::of('fixture'),
            'is_active' => true,
        ]);
        $router->id = 511;
        $router->save();

        $olt = new Olt([
            'name' => 'OLT GADANG 3',
            'code' => 'OLT-GADANG-3',
            'vendor' => 'huawei_ma5608t',
            'area_id' => $area->id,
            'router_id' => $router->id,
            'management_ip' => '198.18.38.2',
            'management_protocol' => 'ssh',
            'management_port' => 22,
            'snmp_community' => 'canary-community',
        ]);
        $olt->id = 11;
        $olt->save();

        return [$area, $router, $olt];
    }
}
