<?php

namespace Tests\Feature;

use App\Models\Olt;
use App\Models\Router;
use App\Services\PsbSiteRegistry;
use App\Services\PsbSiteResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Canary;
use Tests\TestCase;

#[Group('blocking-psb')]
class PsbHuaweiSiteResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_gadang_huawei_is_a_separate_registry_site_from_legacy_gadang(): void
    {
        $router = new Router([
            'name' => 'SKYNET-GADANG',
            'ip_address' => '198.18.38.1',
            'username' => 'test',
            'password' => Canary::of('test'),
            'is_active' => true,
        ]);
        $router->id = 511;
        $router->save();

        $olt = new Olt([
            'name' => 'OLT GADANG 3',
            'code' => 'OLT-GADANG-3',
            'vendor' => 'huawei_ma5608t',
            'management_ip' => '198.18.38.2',
            'router_id' => $router->id,
        ]);
        $olt->id = 11;
        $olt->save();
        $registry = app(PsbSiteRegistry::class);
        $canonical = $registry->byKey('gadang');
        $legacy = $registry->byKey('gadang-hioso');
        $site = $registry->byKey('gadang_huawei');

        $this->assertSame('hioso', $legacy['vendor']);
        $this->assertSame(PsbSiteRegistry::MODE_PPPOE_VLAN, $legacy['mode']);
        $this->assertSame(PsbSiteRegistry::MODE_HUAWEI_OLT, $canonical['mode']);
        $this->assertSame(11, $canonical['olt_id']);
        $this->assertSame($router->id, $site['router']?->id);
        $this->assertSame($olt->id, $site['olt']?->id);
        $this->assertSame(511, $site['router_id']);
        $this->assertSame(11, $site['olt_id']);
        $this->assertSame(PsbSiteRegistry::MODE_HUAWEI_OLT, $site['mode']);
    }

    public function test_router_alias_and_explicit_legacy_alias_keep_their_site_modes(): void
    {
        $router = Router::create([
            'name' => 'SKYNET-GADANG',
            'ip_address' => '198.18.38.1',
            'username' => 'test',
            'password' => Canary::of('test'),
            'is_active' => true,
        ]);
        $router->id = 511;
        $router->save();

        $resolver = app(PsbSiteResolver::class);

        $this->assertSame(PsbSiteRegistry::MODE_HUAWEI_OLT, $resolver->resolve('Gadang', 511)['mode']);
        $this->assertSame('gadang_huawei', $resolver->resolve('Gadang', 511)['key']);
        $this->assertSame(PsbSiteRegistry::MODE_PPPOE_ONLY, $resolver->resolve('Gadang', 511, null, 'gadang-hioso')['mode']);
        $this->assertSame('gadang', $resolver->resolve('Gadang', 511, null, 'gadang-hioso')['key']);
    }
}
