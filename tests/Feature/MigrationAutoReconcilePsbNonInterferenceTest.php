<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\GenieAcsService;
use App\Services\HuaweiOltOnlyProvisioningService;
use App\Services\MikrotikService;
use App\Services\OltZteC300Service;
use App\Services\PsbOrchestratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Concerns\BuildsAutoReconcileFixtures;
use Tests\TestCase;

#[Group('blocking-psb')]
class MigrationAutoReconcilePsbNonInterferenceTest extends TestCase
{
    use BuildsAutoReconcileFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(MikrotikService::class, Mockery::mock(MikrotikService::class));
        $this->app->instance(OltZteC300Service::class, Mockery::mock(OltZteC300Service::class));
        $this->app->instance(GenieAcsService::class, Mockery::mock(GenieAcsService::class));
        $this->app->instance(HuaweiOltOnlyProvisioningService::class, Mockery::mock(HuaweiOltOnlyProvisioningService::class));
        $psb = Mockery::mock(PsbOrchestratorService::class);
        $psb->shouldNotReceive('reply');
        $this->app->instance(PsbOrchestratorService::class, $psb);
        Http::preventStrayRequests();
        Queue::fake();
        Bus::fake();
    }

    public function test_psb_tables_queue_and_webhooks_are_untouched_by_an_apply_run(): void
    {
        $f = $this->fixture();
        DB::table('psb_drafts')->insert([
            ['status' => 'waiting_sn', 'sn' => 'FHTTPSB00001', 'pppoe_user' => 'psb.satu', 'created_at' => now(), 'updated_at' => now()],
            ['status' => 'applied', 'sn' => $f['migration']->new_sn, 'pppoe_user' => 'psb.dua', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $before = DB::table('psb_drafts')->orderBy('id')->get()->toJson();
        $starliteBefore = DB::table('psb_starlite_entries')->count();

        Setting::set('migration_auto_reconcile_apply', 1);
        Artisan::call('migration:auto-reconcile');

        $this->assertSame('synced', $f['migration']->fresh()->status, 'a finished (applied) PSB draft does not block');
        $this->assertSame($before, DB::table('psb_drafts')->orderBy('id')->get()->toJson());
        $this->assertSame($starliteBefore, DB::table('psb_starlite_entries')->count());
        Queue::assertNothingPushed();
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public function test_open_psb_draft_with_the_serial_blocks_auto_sync(): void
    {
        $f = $this->fixture();
        DB::table('psb_drafts')->insert(['status' => 'secret_ok', 'sn' => $f['migration']->new_sn, 'created_at' => now(), 'updated_at' => now()]);
        $before = DB::table('psb_drafts')->orderBy('id')->get()->toJson();

        Setting::set('migration_auto_reconcile_apply', 1);
        Artisan::call('migration:auto-reconcile');

        $this->assertSame('needs_review', $f['migration']->fresh()->status);
        $this->assertSame($before, DB::table('psb_drafts')->orderBy('id')->get()->toJson());
        $this->assertStringContainsString('sn_di_psb_terbuka', Artisan::output());
    }
}
