<?php

namespace Tests\Feature;

use App\Models\Olt;
use App\Models\Router;
use App\Models\Setting;
use App\Services\CustomerMigrationService;
use App\Services\GuidedMigrationLookupService;
use App\Services\HuaweiOltOnlyProvisioningService;
use App\Services\PsbOrchestratorService;
use App\Services\PsbSiteRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use Tests\Support\Canary;
use Tests\TestCase;

#[Group('blocking-psb')]
class CustomerMigrationSiteRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_krian_short_form_is_not_hijacked_into_huawei(): void
    {
        $this->seedSites();

        self::assertFalse($this->huaweiOltOnly([
            'pppoe_user' => 'KRIAN_RT1_RW1_1.1',
            'new_sn' => 'FHTTKRIAN001',
            'router_field' => 'Krian',
        ]));
    }

    public function test_krian_short_form_uses_the_c300_uncfg_path(): void
    {
        $this->seedSites();

        self::assertTrue($this->c300Uncfg([
            'pppoe_user' => 'KRIAN_RT1_RW1_1.1',
            'new_sn' => 'FHTTKRIAN001',
            'daerah' => 'Krian',
        ]));
    }

    public function test_krian_short_form_resolves_to_the_krian_c300_site(): void
    {
        $this->seedSites();

        $site = $this->targetSite([
            'pppoe_user' => 'KRIAN_RT1_RW1_1.1',
            'new_sn' => 'FHTTKRIAN001',
            'router_field' => 'Skynet-Krian-OLT',
        ]);

        self::assertNotNull($site);
        self::assertSame('krian', $site['key']);
        self::assertSame(514, $site['router_id']);
        self::assertSame(9, $site['olt_id']);
        self::assertSame(PsbSiteRegistry::MODE_FULL_OLT, $site['mode']);
    }

    public function test_gadang_short_form_stays_on_the_huawei_path(): void
    {
        $this->seedSites();

        self::assertTrue($this->huaweiOltOnly([
            'pppoe_user' => 'GADANG_RT1_RW1_1.1',
            'new_sn' => 'FHTTGADANG01',
            'daerah' => 'Gadang',
        ]));
    }

    public function test_gadang_free_text_olt_resolves_to_huawei_not_hioso(): void
    {
        $this->seedSites();

        $site = $this->targetSite([
            'pppoe_user' => 'GADANG_RT1_RW1_1.1',
            'new_sn' => 'FHTTGADANG01',
            'olt_field' => 'OLT GADANG 3',
        ]);

        self::assertNotNull($site);
        self::assertSame('gadang_huawei', $site['key']);
        self::assertSame(11, $site['olt_id']);
        self::assertSame(PsbSiteRegistry::MODE_HUAWEI_OLT, $site['mode']);
    }

    public function test_guided_customer_form_on_a_non_huawei_site_stays_guided(): void
    {
        $this->seedSites();

        $parsed = [
            'full_name' => 'Arif Contoh',
            'pppoe_user' => 'KRIAN_RT1_RW1_1.1',
            'new_sn' => 'FHTTKRIAN001',
            'daerah' => 'Krian',
        ];

        self::assertFalse($this->huaweiOltOnly($parsed));
        self::assertFalse($this->c300Uncfg($parsed));
    }

    public function test_gadang_router_replacement_form_uses_the_huawei_path(): void
    {
        $this->seedSites();

        // Router FiberHome rusak -> ganti unit baru. Format lengkap teknisi
        // (Nama, PPPoE, SN baru, SN lama, OLT/Daerah/Router Gadang, RT/RW,
        // Keterangan) harus masuk jalur Huawei olt-only, bukan guided lookup.
        $parsed = [
            'full_name' => 'Pelanggan Gadang',
            'pppoe_user' => 'GADANG_RT1_RW1_1.1',
            'new_sn' => 'FHTTGADANG01',
            'old_sn' => 'FHTTOLDLAMA1',
            'daerah' => 'Gadang',
            'router_field' => 'Gadang',
            'olt_field' => 'OLT GADANG 3',
            'rt' => '01',
            'rw' => '02',
            'keterangan' => 'router fiberhome rusak, wlan mati',
        ];

        self::assertTrue($this->huaweiOltOnly($parsed));
    }

    public function test_krian_full_form_with_sn_is_not_hijacked_into_huawei(): void
    {
        $this->seedSites();

        self::assertFalse($this->huaweiOltOnly([
            'full_name' => 'Pelanggan Krian',
            'pppoe_user' => 'KRIAN_RT1_RW1_1.1',
            'new_sn' => 'FHTTKRIAN001',
            'old_sn' => 'FHTTOLDLAMA1',
            'daerah' => 'Krian',
            'router_field' => 'Krian',
        ]));
    }

    public function test_cross_router_to_gadang_uses_the_huawei_path(): void
    {
        $this->seedSites();

        // Bumiayu -> Gadang router replacement: Router Baru resolves to the
        // Huawei site, so the Huawei olt-only path must take it.
        self::assertTrue($this->huaweiOltOnly([
            'full_name' => 'Pelanggan Pindah',
            'pppoe_user' => 'BUMIAYU_USER',
            'new_sn' => 'FHTTGADANG02',
            'old_sn' => 'FHTTOLDBUMI1',
            'new_router_field' => 'Gadang',
            'old_router_field' => 'Bumiayu',
            'olt_field' => 'OLT GADANG 3',
            'daerah' => 'Gadang',
        ]));
    }

    public function test_short_form_without_any_site_hint_keeps_the_historical_huawei_contract(): void
    {
        $this->seedSites();

        self::assertTrue($this->huaweiOltOnly([
            'pppoe_user' => 'USERAI_RT9_RW3_6.8',
            'new_sn' => 'FHTT0000A03A',
        ]));
    }

    public function test_explicit_huawei_mode_always_uses_the_huawei_path(): void
    {
        $this->seedSites();

        self::assertTrue($this->huaweiOltOnly(['mode_field' => 'OLT_ONLY_HUAWEI']));
    }

    public function test_krian_short_form_in_guided_group_is_not_saved_as_huawei(): void
    {
        $this->seedSites();
        Setting::set(GuidedMigrationLookupService::FEATURE_FLAG, '1');
        Setting::set(GuidedMigrationLookupService::ALLOWED_GROUP_JIDS, 'activation@g.us');

        $psb = Mockery::mock(PsbOrchestratorService::class)->shouldIgnoreMissing();
        $psb->shouldReceive('reply')->andReturnTrue();
        $psb->shouldReceive('resolveRouter')->andReturn(null);
        $this->app->instance(PsbOrchestratorService::class, $psb);

        $migration = app(CustomerMigrationService::class)->handleInbound([
            'group_jid' => 'activation@g.us',
            'sender_jid' => 'teknisi@s.whatsapp.net',
            'sender_name' => 'Teknisi',
            'message_id' => 'mig-krian-short-form',
            'text' => "#migrasi\nPPPoE: KRIAN_RT1_RW1_1.1\nSN: FHTTKRIAN001\nRouter: Krian",
        ]);

        $this->assertTrue($migration->exists);
        $this->assertNotSame(
            HuaweiOltOnlyProvisioningService::MODE,
            data_get($migration->meta, 'migration_operation_mode'),
        );
        $this->assertSame(15, (int) $migration->router_id);
    }

    private function huaweiOltOnly(array $parsed): bool
    {
        return (bool) $this->invoke('isHuaweiOltOnlyParsed', $parsed);
    }

    private function c300Uncfg(array $parsed): bool
    {
        return (bool) $this->invoke('shouldUseC300UncfgPath', $parsed);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function targetSite(array $parsed): ?array
    {
        $site = $this->invoke('resolveMigrationTargetSite', $parsed);

        return is_array($site) ? $site : null;
    }

    private function invoke(string $method, array $parsed): mixed
    {
        $service = app(CustomerMigrationService::class);

        return (new ReflectionMethod($service, $method))->invoke($service, $parsed);
    }

    private function seedSites(): void
    {
        $gadang = new Router([
            'name' => 'SKYNET-GADANG',
            'ip_address' => '198.18.38.1',
            'username' => 'test',
            'password' => Canary::of('test'),
            'is_active' => true,
        ]);
        $gadang->id = 511;
        $gadang->save();

        $gadangOlt = new Olt([
            'name' => 'OLT GADANG 3',
            'code' => 'OLT-GADANG-3',
            'vendor' => 'huawei_ma5608t',
            'management_ip' => '198.18.38.2',
            'router_id' => $gadang->id,
        ]);
        $gadangOlt->id = 11;
        $gadangOlt->save();

        $krian = new Router([
            'name' => 'Skynet-Krian-OLT',
            'ip_address' => '198.18.39.1',
            'username' => 'test',
            'password' => Canary::of('test'),
            'is_active' => true,
        ]);
        $krian->id = 514;
        $krian->save();

        $krianOlt = new Olt([
            'name' => 'OLT KRIAN',
            'code' => 'OLT-KRIAN',
            'vendor' => 'zte_c300',
            'management_ip' => '198.18.39.2',
            'router_id' => $krian->id,
        ]);
        $krianOlt->id = 9;
        $krianOlt->save();

        // Krian PSB/migration target BNG (router #15); the OLT row stays linked
        // to Skynet-Krian-OLT (#514) as in production.
        $krianBng = new Router([
            'name' => 'Krian',
            'ip_address' => '198.18.39.3',
            'username' => 'test',
            'password' => Canary::of('test'),
            'is_active' => true,
        ]);
        $krianBng->id = 15;
        $krianBng->save();
    }
}
