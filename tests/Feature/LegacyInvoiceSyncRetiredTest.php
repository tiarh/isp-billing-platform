<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Owner 2026-10-13: the legacy invoice feed is frozen since 2026-09-28, so the
 * 2-hourly billing:sync-invoices-legacy schedule is retired. The command itself
 * stays available for manual runs.
 */
#[Group('blocking-core')]
class LegacyInvoiceSyncRetiredTest extends TestCase
{
    public function test_legacy_invoice_sync_is_not_scheduled(): void
    {
        $console = (string) file_get_contents(base_path('routes/console.php'));

        $this->assertDoesNotMatchRegularExpression('/Schedule::command\(\s*[\'"]billing:sync-invoices-legacy/', $console);
    }

    public function test_legacy_invoice_sync_command_remains_available_for_manual_runs(): void
    {
        $this->assertArrayHasKey('billing:sync-invoices-legacy', Artisan::all());
    }
}
