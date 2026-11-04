<?php

namespace Tests\Feature;

use Tests\TestCase;

class PackageShowFrontendContractTest extends TestCase
{
    public function test_package_show_lists_customers_through_the_shared_table_components(): void
    {
        $show = (string) file_get_contents(base_path('resources/js/Pages/Packages/Show.tsx'));
        $table = (string) file_get_contents(base_path('resources/js/Pages/Packages/Partials/PackageCustomersTable.tsx'));

        $this->assertStringContainsString('Pelanggan dengan paket ini', $show);
        $this->assertStringContainsString('<PackageCustomersTable', $show);
        $this->assertStringContainsString('billable_customers_count', $show);
        $this->assertStringContainsString('Detail Paket', $show);
        $this->assertStringNotContainsString('Package Details', $show);
        $this->assertStringNotContainsString('Active Customers', $show);
        $this->assertStringNotContainsString('Total Revenue', $show);
        $this->assertStringContainsString("route('packages.customers'", $table);
        $this->assertStringContainsString('<DataTableToolbar', $table);
        $this->assertStringContainsString('<DataTablePagination', $table);
        $this->assertStringContainsString('customerStatusTone(', $table);
        $this->assertStringContainsString("route('customers.index', { package_id: packageId })", $table);
        $this->assertStringContainsString('Lihat semua di halaman Pelanggan', $table);
    }

    public function test_package_show_uses_shared_currency_format_and_explicit_action_types(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/Packages/Show.tsx'));

        $this->assertStringContainsString("from '@/lib/format'", $source);
        $this->assertStringContainsString('formatIdr(', $source);
        $this->assertStringNotContainsString('new Intl.NumberFormat', $source);
        $this->assertStringContainsString('<Button type="button"', $source);
        $this->assertStringContainsString("from '@/Components/ConfirmDestructive'", $source);
        $this->assertStringContainsString('<ConfirmDestructive', $source);
        $this->assertStringNotContainsString('<Dialog', $source);
        $this->assertStringNotContainsString('DialogTrigger', $source);
        $this->assertStringNotContainsString('<Button variant="destructive" onClick={handleDelete}', $source);
    }
}
