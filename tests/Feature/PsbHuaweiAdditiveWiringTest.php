<?php

namespace Tests\Feature;

use App\Models\PsbDraft;
use App\Models\Setting;
use App\Services\HuaweiAwarePsbOrchestratorService;
use App\Services\PsbOrchestratorService;
use App\Services\PsbSiteRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PsbHuaweiAdditiveWiringTest extends TestCase
{
    use RefreshDatabase;

    public function test_container_uses_huawei_seam_without_replacing_parent_contract(): void
    {
        $service = app(PsbOrchestratorService::class);

        $this->assertInstanceOf(HuaweiAwarePsbOrchestratorService::class, $service);
        $this->assertInstanceOf(PsbOrchestratorService::class, $service);
    }

    public function test_gadang_huawei_capability_is_enabled_by_default_and_other_sites_are_not_intercepted(): void
    {
        $service = app(HuaweiAwarePsbOrchestratorService::class);

        $this->assertTrue($service->supportsHuaweiGadang(new PsbDraft(['daerah' => 'Gadang'])));
        $this->assertFalse($service->supportsHuaweiGadang(new PsbDraft(['daerah' => 'Krian'])));
        $this->assertFalse($service->supportsHuaweiGadang(new PsbDraft));
    }

    public function test_gadang_huawei_capability_can_be_disabled_without_changing_legacy_registry_site(): void
    {
        Setting::set('psb_huawei_gadang_enabled', '0', 'boolean', 'psb', 'Enable PSB Huawei Gadang seam');

        try {
            $service = app(HuaweiAwarePsbOrchestratorService::class);
            $this->assertFalse($service->supportsHuaweiGadang(new PsbDraft(['daerah' => 'Gadang'])));
        } finally {
            Setting::set('psb_huawei_gadang_enabled', '1', 'boolean', 'psb', 'Enable PSB Huawei Gadang seam');
        }

        $legacy = (new PsbSiteRegistry)->byKey('gadang-hioso');
        $canonical = (new PsbSiteRegistry)->byKey('gadang');
        $this->assertSame(PsbSiteRegistry::MODE_PPPOE_VLAN, $legacy['mode']);
        $this->assertSame('hioso', $legacy['vendor']);
        $this->assertSame(PsbSiteRegistry::MODE_HUAWEI_OLT, $canonical['mode']);
    }
}
