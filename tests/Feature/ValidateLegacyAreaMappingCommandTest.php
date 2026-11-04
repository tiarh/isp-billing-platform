<?php

namespace Tests\Feature;

use App\Services\LegacyAreaResolver;
use Tests\TestCase;

class ValidateLegacyAreaMappingCommandTest extends TestCase
{
    public function test_command_passes_when_all_records_map(): void
    {
        $file = $this->customerFile([
            [
                'id' => 'BDL001',
                'name' => 'Bedali Customer',
                'address' => 'Jl. Bedali',
                'package' => ['name' => 'Internet'],
            ],
            [
                'id' => '2210174501',
                'name' => 'Wajak Customer',
                'address' => 'Jl. Desa',
                'package' => ['name' => 'Paket up to 5Mb WAJAK'],
            ],
        ]);

        $this->artisan('legacy:validate-area-mapping', ['--source' => 'file', '--file' => $file])
            ->assertSuccessful()
            ->expectsOutput('Unmapped: 0')
            ->expectsOutput('SKYNET-GENERAL: 0');
    }

    public function test_command_fails_when_one_record_is_unmapped(): void
    {
        $file = $this->customerFile([
            ['id' => 'BDL001', 'name' => 'Bedali Customer'],
            ['id' => 'UNKNOWN001', 'name' => 'Unknown Customer', 'address' => 'Unknown Address'],
        ]);

        $this->artisan('legacy:validate-area-mapping', ['--source' => 'file', '--file' => $file])
            ->assertFailed()
            ->expectsOutput('Unmapped: 1');
    }

    public function test_command_fails_if_resolver_returns_general(): void
    {
        $this->app->instance(LegacyAreaResolver::class, new class extends LegacyAreaResolver
        {
            public function resolve(array $customer): array
            {
                return [
                    'area' => 'SKYNET-GENERAL',
                    'reason' => 'api_area',
                    'source_value' => 'SKYNET-GENERAL',
                    'valid' => false,
                ];
            }
        });

        $file = $this->customerFile([
            ['id' => 'BDL001', 'name' => 'Bedali Customer'],
        ]);

        $this->artisan('legacy:validate-area-mapping', ['--source' => 'file', '--file' => $file])
            ->assertFailed()
            ->expectsOutput('SKYNET-GENERAL: 1');
    }

    /**
     * @param  array<int, array<string, mixed>>  $customers
     */
    private function customerFile(array $customers): string
    {
        $path = tempnam(sys_get_temp_dir(), 'legacy-area-customers-').'.json';
        file_put_contents($path, json_encode($customers, JSON_THROW_ON_ERROR));

        return $path;
    }
}
