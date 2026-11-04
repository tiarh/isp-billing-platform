<?php

namespace Tests\Unit;

use App\Services\CustomerMigrationService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

class CustomerMigrationServiceRoutingTest extends TestCase
{
    public function test_bare_pppoe_and_new_sn_use_the_huawei_short_form(): void
    {
        self::assertTrue($this->route([
            'pppoe_user' => 'USERAI_RT9_RW3_6.8',
            'new_sn' => 'FHTT0000A03A',
        ]));
    }

    public function test_customer_discriminator_stays_on_the_guided_path(): void
    {
        self::assertFalse($this->route([
            'full_name' => 'Arif Contoh',
            'pppoe_user' => 'USERAI_RT9_RW3_6.8',
            'new_sn' => 'FHTT0000A03A',
        ]));
    }

    public function test_explicit_huawei_mode_always_uses_the_huawei_path(): void
    {
        self::assertTrue($this->route([
            'mode_field' => 'OLT_ONLY_HUAWEI',
        ]));
    }

    /** @param array<string, mixed> $parsed */
    private function route(array $parsed): bool
    {
        $service = (new ReflectionClass(CustomerMigrationService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($service, 'isHuaweiOltOnlyParsed');

        return $method->invoke($service, $parsed);
    }
}
