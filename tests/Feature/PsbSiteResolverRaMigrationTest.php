<?php

namespace Tests\Feature;

use App\Models\Router;
use App\Services\PsbSiteRegistry;
use App\Services\PsbSiteResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * STEP 2026-09-30 (owner): Purwodadi, SLB, Martopuro, Jatisari, Gajahrejo,
 * Kertosari, Srigading dikerahkan via C300 RA (router skynet-ra-olt) untuk
 * migrasi berbasis format PSB WA.
 */
class PsbSiteResolverRaMigrationTest extends TestCase
{
    use RefreshDatabase;

    private PsbSiteResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new PsbSiteResolver;
    }

    /**
     * @dataProvider raMigrationDaerahProvider
     */
    public function test_migration_daerah_resolve_to_ra_full_olt(string $daerah): void
    {
        $site = $this->resolver->resolve($daerah);

        $this->assertSame('ra_full_olt', $site['key'], "Daerah {$daerah} harus resolve ke ra_full_olt");
        $this->assertSame(PsbSiteResolver::MODE_FULL_OLT, $site['mode']);
    }

    public static function raMigrationDaerahProvider(): array
    {
        return [
            'purwodadi' => ['purwodadi'],
            'slb' => ['slb'],
            'martopuro' => ['martopuro'],
            'jatisari' => ['jatisari'],
            'gajahrejo' => ['gajahrejo'],
            'gajahan' => ['gajahan'],
            'kertosari' => ['kertosari'],
            'srigading' => ['srigading'],
            // Existing RA clusters must keep resolving the same way.
            'bedali agung' => ['bedali agung'],
            'kunci' => ['kunci'],
        ];
    }

    public function test_hioso_sites_no_longer_match_their_old_daerah(): void
    {
        $profiles = collect($this->resolver->builtinProfiles())
            ->keyBy('key');

        $this->assertSame([], $profiles->get('hioso_martopuro')['match']);
        $this->assertSame([], $profiles->get('hioso_srigading')['match']);
    }

    public function test_unknown_daerah_falls_back_to_ra_default(): void
    {
        $site = $this->resolver->resolve('daerah-tidak-ada-xyz');

        $this->assertSame('default_full_olt', $site['key']);
        $this->assertSame(PsbSiteResolver::MODE_FULL_OLT, $site['mode']);
    }

    /**
     * STEP 2026-09-30 (owner): Krian uplink moves to Skynet-Krian-OLT (514).
     * The krian site registry override (psb_sites) must win over findRouter's
     * exact-name preference, which historically favoured router 15 "Krian".
     */
    public function test_krian_site_registry_override_wins_over_name_matching(): void
    {
        // Production has both routers; the test DB is empty, so create them
        // with their production ids. "Krian" (#15) is the exact-name match that
        // findRouter() would prefer without the registry override.
        $this->router(15, 'Krian');
        $this->router(514, 'Skynet-Krian-OLT');

        $profiles = collect($this->resolver->builtinProfiles())
            ->keyBy('key');

        // The built-in krian profile still carries the legacy router needles;
        // the override lives in the registry (psb_sites / builtin registry).
        $this->assertSame(['krian-olt', 'skynet-krian', 'krian'], $profiles->get('krian_full_olt')['router_match']);

        // The registry entry for the krian site must point at router 514.
        $site = app(PsbSiteRegistry::class)->byKey('krian');
        $this->assertSame(514, (int) ($site['router_id'] ?? 0), 'Registry krian harus router_id=514 (Skynet-Krian-OLT)');

        // And the resolver must honour it (router object resolved from registry).
        $resolved = $this->resolver->resolve('krian');
        $this->assertSame(
            514,
            (int) (data_get($resolved, 'router.id') ?? 0),
            "resolve('krian') harus menghasilkan router 514 Skynet-Krian-OLT",
        );
    }

    private function router(int $id, string $name): Router
    {
        $router = new Router;
        $router->forceFill([
            'id' => $id,
            'name' => $name,
            'ip_address' => '192.0.2.'.($id % 250 + 1),
            'username' => 'test',
            'password' => 'test-only-router-password',
            'port' => 8728,
            'is_active' => true,
        ])->save();

        return $router;
    }
}
