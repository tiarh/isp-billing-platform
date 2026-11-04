<?php

namespace Tests\Feature;

use App\Models\OltOnu;
use App\Services\CustomerOnu\CustomerOnuFieldSyncService;
use App\Services\Recap\OnuHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Concerns\BuildsOnuSyncFixtures;
use Tests\TestCase;

/**
 * Readers of customers.olt_id / olt_port_label get more accurate after a sync (owner 2026-10-15).
 */
#[Group('blocking-core')]
class CustomerOnuFieldSyncConsumersTest extends TestCase
{
    use BuildsOnuSyncFixtures;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        File::deleteDirectory(CustomerOnuFieldSyncService::planDir());
        parent::tearDown();
    }

    public function test_onu_health_maps_by_port_after_the_sync_where_it_was_ambiguous_before(): void
    {
        $this->blockDevices();
        $f = $this->scenario();
        $dead = $this->scenario([], null);
        // the same serial still sits (stale) on a dead OLT: serial matching alone is ambiguous
        OltOnu::create(['olt_id' => $dead['olt']->id, 'onu_ref' => '0/0/9:1', 'serial_number' => $f['customer']->onu_serial, 'state' => 'online', 'state_collected_at' => now()->subDays(3)]);
        $health = fn (): array => app(OnuHealthService::class)->collect(null, 'SEMUA AREA')['counts'];

        $before = $health();
        $this->assertSame(1, $before['ambigu']);

        Artisan::call('customers:sync-onu-fields', ['--json' => true]);
        $run = json_decode(Artisan::output(), true)['run_id'];
        Artisan::call('customers:sync-onu-fields', ['--apply' => true, '--plan' => $run]);

        $after = $health();
        $this->assertSame(0, $after['ambigu']);
        $this->assertGreaterThanOrEqual(1, $after['terpantau']);
    }
}
