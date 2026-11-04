<?php

namespace Tests\Feature;

use Tests\TestCase;

class DashboardFrontendContractTest extends TestCase
{
    public function test_dashboard_uses_shared_formatting_and_semantic_status_styles(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/Dashboard.tsx'));

        $this->assertStringContainsString("from '@/lib/format'", $source);
        $this->assertStringContainsString('formatIdr(', $source);
        $this->assertStringContainsString('formatMonth(', $source);
        $this->assertStringContainsString('formatDateTime(', $source);
        $this->assertStringContainsString('<StatusBadge', $source);
        $this->assertStringContainsString("payment.invoice?.customer?.name ?? 'Pelanggan tidak tersedia'", $source);
        $this->assertStringContainsString('payment.invoice?.customer?.code', $source);
        $this->assertStringContainsString('formatMonth(payment.invoice?.period)', $source);
        $this->assertStringNotContainsString('new Intl.NumberFormat', $source);
        $this->assertStringNotContainsString('toLocaleDateString(', $source);
        $this->assertStringNotContainsString('backdrop-blur', $source);
        $this->assertStringNotContainsString('bg-gradient-', $source);
        $this->assertStringNotContainsString('violet-', $source);
    }

    public function test_dashboard_a1_layout_links_detail_to_finance_operational_without_filter_form(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/Dashboard.tsx'));

        $this->assertStringContainsString('xl:grid-cols-[1fr_360px]', $source);
        $this->assertStringContainsString("route('invoices.create')", $source);
        $this->assertStringContainsString("tab: 'operational'", $source);
        $this->assertStringContainsString('Lihat semua', $source);
        $this->assertStringContainsString('Lihat detail', $source);
        $this->assertStringNotContainsString('ops_month', $source);
        $this->assertStringNotContainsString('<form', $source);
        $this->assertDoesNotMatchRegularExpression('/\b(?:text|bg|border)-(?:emerald|red|amber|blue|green|yellow|orange)-\d{2,3}/', $source);
    }

    public function test_dashboard_no_longer_shows_voice_of_ops_or_wa_work_signals(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/Dashboard.tsx'));
        $controller = (string) file_get_contents(base_path('app/Http/Controllers/DashboardController.php'));

        $this->assertStringNotContainsString('Voice of Ops', $source);
        $this->assertStringNotContainsString('wa_chat_intelligence', $source);
        $this->assertStringNotContainsString('wa_chat_intelligence', $controller);
    }
}
