<?php

namespace Tests\Unit;

use App\Models\Package;
use App\Services\PsbOrchestratorService;
use PHPUnit\Framework\TestCase;

class PsbPackageMappingTest extends TestCase
{
    public function test_requested_speed_must_match_mikrotik_profile(): void
    {
        $service = (new \ReflectionClass(PsbOrchestratorService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(PsbOrchestratorService::class, 'packageMappingError');
        $method->setAccessible(true);

        $error = $method->invoke($service, '100M', new Package(['mikrotik_profile' => '200MB']));

        $this->assertNotNull($error);
        $this->assertStringContainsString('tanpa default 200M', $error);
    }

    public function test_supported_speed_profiles_return_no_mapping_error(): void
    {
        $service = (new \ReflectionClass(PsbOrchestratorService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(PsbOrchestratorService::class, 'packageMappingError');
        $method->setAccessible(true);

        foreach ([50, 100, 200] as $speed) {
            $this->assertNull($method->invoke($service, $speed.'M', new Package(['mikrotik_profile' => $speed.'M/'.$speed.'M'])));
        }
    }

    public function test_empty_mikrotik_profile_is_rejected(): void
    {
        $service = (new \ReflectionClass(PsbOrchestratorService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(PsbOrchestratorService::class, 'packageMappingError');
        $method->setAccessible(true);

        $error = $method->invoke($service, '100M', new Package(['mikrotik_profile' => null]));

        $this->assertNotNull($error);
        $this->assertStringContainsString('belum memiliki MikroTik Profile', $error);
    }

    public function test_startlite_remains_a_valid_200m_profile_alias(): void
    {
        $service = (new \ReflectionClass(PsbOrchestratorService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(PsbOrchestratorService::class, 'packageMappingError');
        $method->setAccessible(true);

        $this->assertNull($method->invoke($service, '200M', new Package(['mikrotik_profile' => 'STARTLITE'])));
    }
}
