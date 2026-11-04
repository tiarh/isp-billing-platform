<?php

namespace Tests\Feature;

use Tests\TestCase;

class PackagesFrontendContractTest extends TestCase
{
    public function test_packages_page_uses_shared_table_and_status_primitives(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/Packages/Index.tsx'));

        $this->assertStringContainsString("from '@/Components/DataTable'", $source);
        $this->assertStringContainsString("from '@/Components/StatusBadge'", $source);
        $this->assertStringContainsString('<StatusBadge label="Perlu harga" tone="down" />', $source);
        $this->assertStringContainsString('<StatusBadge label="Draft" tone="degraded" />', $source);
        $this->assertStringContainsString("header: 'Router Asal'", $source);
        $this->assertStringContainsString('pkg.router?.name', $source);
        $this->assertStringContainsString('Paket ini sedang dipakai oleh ${packageToDelete?.customers_count ?? 0} pelanggan.', $source);
        $this->assertStringNotContainsString('text-emerald-600', $source);
        $this->assertStringNotContainsString('violet-', $source);
        $this->assertStringNotContainsString('bg-gradient-', $source);
        $this->assertStringNotContainsString('backdrop-blur', $source);
    }

    public function test_package_price_formatter_accepts_laravel_decimal_strings(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/lib/format.ts'));

        $this->assertStringContainsString('number | string | null | undefined', $source);
        $this->assertStringContainsString('const numericAmount = Number(amount);', $source);
        $this->assertStringContainsString('Number.isFinite(numericAmount) ? numericAmount : 0', $source);
    }
}
