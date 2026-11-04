<?php

namespace Tests\Feature;

use App\Jobs\ReconcilePonAfterProvisionJob;
use Tests\TestCase;

class OltReconcileQueueIsolationTest extends TestCase
{
    public function test_pon_reconcile_is_isolated_from_the_psb_worker(): void
    {
        $job = new ReconcilePonAfterProvisionJob([
            'olt_id' => 1,
            'pon_port' => '1/3/4',
            'onu_ref' => '1/3/4:9',
        ]);

        $this->assertSame('olt-sync', $job->queue);
        $this->assertSame(1, $job->tries);
        $this->assertSame(120, $job->timeout);

        $collector = file_get_contents(app_path('Services/OltCollectorService.php'));

        $this->assertStringNotContainsString('])->afterResponse()', $collector);
        $this->assertStringContainsString(
            "Setting::get('olt_deferred_pon_reconcile_enabled', false)",
            $collector
        );
    }
}
