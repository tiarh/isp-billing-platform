<?php

namespace Tests\Feature;

use App\Services\HuaweiOltOnlyProvisioningService;
use App\Services\MikrotikService;
use ReflectionProperty;
use Tests\TestCase;

class HuaweiOltOnlyProvisioningContainerTest extends TestCase
{
    public function test_container_injects_mikrotik_service_into_huawei_adapter(): void
    {
        $service = app(HuaweiOltOnlyProvisioningService::class);
        $property = new ReflectionProperty($service, 'mikrotik');

        self::assertInstanceOf(MikrotikService::class, $property->getValue($service));
    }
}
