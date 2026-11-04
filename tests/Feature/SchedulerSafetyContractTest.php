<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-core')]
#[Group('blocking-billing')]
class SchedulerSafetyContractTest extends TestCase
{
    public function test_safe_scheduler_does_not_register_heavy_collectors(): void
    {
        Artisan::call('schedule:list', ['--no-ansi' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString('ops:health-alert', $output);
        $this->assertStringContainsString('wa:heal --quiet-ok', $output);
        $this->assertStringNotContainsString('olt:snmp-sync', $output);
        $this->assertStringNotContainsString('genieacs:sync', $output);
        $this->assertStringNotContainsString('routers:mt-session-sync', $output);
    }

    public function test_monitoring_scheduler_mode_isolated_from_full_operations(): void
    {
        $source = (string) file_get_contents(base_path('routes/console.php'));

        $this->assertStringContainsString(
            'if ($monitoringSchedulerEnabled && ! $fullSchedulerEnabled)',
            $source,
        );
        $this->assertStringContainsString('routers:live-health', $source);
        $this->assertStringContainsString('olt:live-sync', $source);
        $this->assertStringContainsString('genieacs:sync', $source);
        $this->assertStringContainsString('connectivity:scan-pon-mismatch', $source);

        $monitoringStart = strpos($source, 'if ($monitoringSchedulerEnabled && ! $fullSchedulerEnabled)');
        $safeStart = strpos($source, 'if (! $fullSchedulerEnabled)', $monitoringStart ?: 0);
        $monitoringBlock = substr($source, $monitoringStart ?: 0, ($safeStart ?: strlen($source)) - ($monitoringStart ?: 0));
        $this->assertStringNotContainsString('connectivity:dispatch-alerts', $monitoringBlock);
    }

    public function test_monitoring_scheduler_mode_registers_olt_collector_lane(): void
    {
        $source = (string) file_get_contents(base_path('routes/console.php'));
        $start = strpos($source, 'if ($monitoringSchedulerEnabled && ! $fullSchedulerEnabled)');
        $end = strpos($source, 'if (! $fullSchedulerEnabled)', $start ?: 0);
        $block = substr($source, $start ?: 0, ($end ?: strlen($source)) - ($start ?: 0));

        $this->assertStringContainsString("'olt:snmp-sync'", $block);
        $this->assertStringContainsString("'olt:rx-sync'", $block);
        $this->assertStringContainsString("'olt:inventory-sync'", $block);
        $this->assertStringContainsString('->when($oltCollectorSchedule)', $block);
        $this->assertStringContainsString('allowsOltCollector()', $source);
    }

    public function test_huawei_optical_read_lane_is_registered_for_monitoring_and_full_modes(): void
    {
        $source = (string) file_get_contents(base_path('routes/console.php'));
        $monitoringBlockStart = strpos($source, 'if ($monitoringSchedulerEnabled) {');
        $monitoringOnlyBlockStart = strpos($source, 'if ($monitoringSchedulerEnabled && ! $fullSchedulerEnabled)');
        $registration = strpos($source, 'Schedule::job(new SyncOltOpticalJob((int) config(\'services.huawei_olt_snmp.olt_id\', 11)))');

        $this->assertSame(1, substr_count($source, 'Schedule::job(new SyncOltOpticalJob((int) config(\'services.huawei_olt_snmp.olt_id\', 11)))'));
        $this->assertNotFalse($monitoringBlockStart);
        $this->assertNotFalse($monitoringOnlyBlockStart);
        $this->assertNotFalse($registration);
        $this->assertGreaterThan($monitoringBlockStart, $registration);
        $this->assertLessThan($monitoringOnlyBlockStart, $registration);
        $this->assertStringContainsString('->when($huaweiOpticalScheduleGate)', $source);
        $this->assertStringContainsString('allowsHuaweiOptical()', $source);
        $this->assertStringContainsString('read-only SNMP', $source);
    }

    public function test_billing_overdue_has_jakarta_schedule_with_overlap_guard(): void
    {
        $source = (string) file_get_contents(base_path('routes/console.php'));

        // STEP 2026-10-02 (owner, R2): two-hourly pass for fast paid-arrears
        // reconnects, re-enabled after the paid-through-prior-month reconnect
        // fix (PR #305) was deployed and the September-paid backfill completed.
        // Both schedules must carry the overlap guard and Jakarta timezone.
        $active = preg_replace('/^\s*\/.*$/m', '', $source);
        $this->assertSame(2, substr_count((string) $active, "Schedule::command('billing:check-overdue')"));
        $this->assertMatchesRegularExpression(
            "/Schedule::command\('billing:check-overdue'\)\s*->dailyAt\('15:00'\)\s*->timezone\('Asia\/Jakarta'\)\s*->withoutOverlapping\(15\)/s",
            $source,
        );
        $this->assertMatchesRegularExpression(
            "/Schedule::command\('billing:check-overdue'\)\s*->everyTwoHours\(\)\s*->timezone\('Asia\/Jakarta'\)\s*->withoutOverlapping\(15\)/s",
            $source,
        );
        $command = (string) file_get_contents(base_path('app/Console/Commands/CheckOverdueInvoices.php'));
        $this->assertStringContainsString('billing_enforcement_dry_run', $command);
    }

    public function test_monthly_invoice_generation_is_registered_outside_the_full_only_block(): void
    {
        Artisan::call('schedule:list', ['--no-ansi' => true]);
        $output = Artisan::output();

        // Core billing must run in every scheduler mode (safe/monitoring/full).
        $this->assertStringContainsString('billing:generate', $output);

        $source = (string) file_get_contents(base_path('routes/console.php'));
        $registration = strpos($source, "Schedule::command('billing:generate')");
        $fullOnlyStart = strpos($source, 'if ($fullSchedulerEnabled) {');
        $this->assertNotFalse($registration);
        $this->assertNotFalse($fullOnlyStart);
        // The monthly invoice job must be registered BEFORE the full-only block.
        $this->assertLessThan($fullOnlyStart, $registration);
        $this->assertSame(1, substr_count($source, "Schedule::command('billing:generate')"));
        $this->assertMatchesRegularExpression(
            "/Schedule::command\('billing:generate'\)\s*->monthlyOn\(1, '07:00'\)\s*->timezone\('Asia\\/Jakarta'\)/s",
            $source,
        );
    }
}
