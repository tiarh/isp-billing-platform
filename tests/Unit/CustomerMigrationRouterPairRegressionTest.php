<?php

namespace Tests\Unit;

use App\Services\CustomerMigrationMessageParser;
use PHPUnit\Framework\TestCase;

class CustomerMigrationRouterPairRegressionTest extends TestCase
{
    public static function routerPairCases(): array
    {
        return [
            'case-832' => [
                "#migrasi\nNama: CUSTOMER_832\nPPPoE: USER_832_RT01_RW01\nRouter lama: Krian\nRouter baru: Skynet-Krian-OLT\nOLT baru: Krian-OLT\nSN baru: FHTT832SANITIZED",
                'Krian',
                'Skynet-Krian-OLT',
            ],
            'case-836' => [
                "#migrasi\nNama: CUSTOMER_836\nPPPoE: USER_836_RT02_RW02\nRouter asal: Randuagung\nRouter target: SKYNET-RA-OLT\nSN baru: FHTT836SANITIZED",
                'Randuagung',
                'SKYNET-RA-OLT',
            ],
        ];
    }

    /**
     * @dataProvider routerPairCases
     */
    public function test_sanitized_cross_router_cases_keep_source_and_target_separate(
        string $message,
        string $sourceRouter,
        string $targetRouter,
    ): void {
        $parsed = (new CustomerMigrationMessageParser)->parse($message);

        $this->assertSame($sourceRouter, $parsed['old_router_field']);
        $this->assertSame($targetRouter, $parsed['router_field']);
        $this->assertNotSame($parsed['old_router_field'], $parsed['router_field']);
        $this->assertFalse($parsed['is_eksekusi']);
    }

    public function test_router_alias_variants_are_not_silently_collapsed(): void
    {
        $parsed = (new CustomerMigrationMessageParser)->parse(
            "#migrasi\nRouter lama: Krian\nRouter baru: Skynet-Krian-OLT\nSN baru: FHTT832SANITIZED"
        );

        $this->assertSame('Krian', $parsed['old_router_field']);
        $this->assertSame('Skynet-Krian-OLT', $parsed['router_field']);
    }
}
