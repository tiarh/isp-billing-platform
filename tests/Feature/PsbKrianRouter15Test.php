<?php

namespace Tests\Feature;

use App\Models\CustomerMigration;
use App\Models\Olt;
use App\Models\Router;
use App\Services\CustomerMigrationService;
use App\Services\PsbSiteRegistry;
use App\Services\PsbSiteResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use Tests\Support\Canary;
use Tests\TestCase;

#[Group('blocking-psb')]
class PsbKrianRouter15Test extends TestCase
{
    use RefreshDatabase;

    public function test_krian_registry_points_to_router_514_and_olt_9(): void
    {
        $site = app(PsbSiteRegistry::class)->byKey('krian');

        $this->assertNotNull($site);
        $this->assertSame(514, $site['router_id']);
        $this->assertSame(9, $site['olt_id']);
        $this->assertSame(PsbSiteRegistry::MODE_FULL_OLT, $site['mode']);
    }

    public function test_krian_alias_resolves_to_router_514_skynet_krian_olt(): void
    {
        $this->seedRouters();

        $router = app(PsbSiteRegistry::class)->resolveRouterAlias('krian');

        $this->assertNotNull($router);
        $this->assertSame(514, $router->id);
        $this->assertSame('Skynet-Krian-OLT', $router->name);
    }

    public function test_site_resolver_picks_router_514_and_olt_9_for_krian(): void
    {
        $this->seedRouters();
        $this->seedOlts();

        $site = app(PsbSiteResolver::class)->resolve('Krian');

        $this->assertSame('krian_full_olt', $site['key']);
        $this->assertSame(PsbSiteResolver::MODE_FULL_OLT, $site['mode']);
        $this->assertSame(514, $site['router']?->id);
        $this->assertSame(9, $site['olt']?->id);
    }

    public function test_migration_router_alias_resolves_to_router_514(): void
    {
        $this->seedRouters();

        // `krian-olt` is the WA alias for the Krian-OLT router (514). The bare
        // `krian` alias intentionally still resolves to router 15 "Krian"
        // (exact-name), matching the legacy same-router migration flow.
        $router = $this->invoke('resolveMigrationRouterAlias', 'krian-olt');

        $this->assertNotNull($router);
        $this->assertSame(514, $router->id);
    }

    public function test_migration_olt_field_resolves_to_olt_9_for_krian(): void
    {
        $this->seedRouters();
        $this->seedOlts();

        $migration = new CustomerMigration([
            'raw_text' => "#migrasi\nPPPoE: KRIANUSER\nSN: FHTTKRIAN001\nRouter: Krian\nOLT: KRIAN",
        ]);
        $migration->exists = true;

        $olt = $this->invoke('resolveOltFromMigrationField', $migration);

        $this->assertNotNull($olt);
        $this->assertSame(9, $olt->id);
    }

    public function test_ra_and_gadang_huawei_resolution_are_unchanged(): void
    {
        $this->seedRouters();
        $this->seedOlts();

        $ra = app(PsbSiteResolver::class)->resolve('Kalirejo');
        $this->assertSame(513, $ra['router']?->id);
        $this->assertSame(1, $ra['olt']?->id);

        $gadang = app(PsbSiteRegistry::class)->byKey('gadang');
        $this->assertSame(511, $gadang['router_id']);
        $this->assertSame(11, $gadang['olt_id']);
    }

    private function seedRouters(): void
    {
        $this->router(514, 'Skynet-Krian-OLT');
        $this->router(15, 'Krian');
        $this->router(513, 'SKYNET-RA-OLT');
        $this->router(511, 'SKYNET-GADANG');
    }

    private function seedOlts(): void
    {
        $this->olt(9, 'OLT KRIAN', 'zte_c300', 514);
        $this->olt(1, 'OLT RA', 'zte_c300', 513);
        $this->olt(11, 'OLT GADANG 3', 'huawei_ma5608t', 511);
    }

    private function router(int $id, string $name): Router
    {
        $router = new Router([
            'name' => $name,
            'ip_address' => '10.150.'.intdiv($id, 250).'.'.($id % 250 + 1),
            'username' => 'test',
            'password' => Canary::of('test'),
            'is_active' => true,
        ]);
        $router->id = $id;
        $router->save();

        return $router;
    }

    private function olt(int $id, string $name, string $vendor, int $routerId): Olt
    {
        $olt = new Olt([
            'name' => $name,
            'code' => 'OLT-'.$id,
            'vendor' => $vendor,
            'management_ip' => '198.18.4.'.($id % 250 + 1),
            'router_id' => $routerId,
        ]);
        $olt->id = $id;
        $olt->save();

        return $olt;
    }

    private function invoke(string $method, mixed ...$args): mixed
    {
        $service = app(CustomerMigrationService::class);

        return (new ReflectionMethod($service, $method))->invoke($service, ...$args);
    }
}
