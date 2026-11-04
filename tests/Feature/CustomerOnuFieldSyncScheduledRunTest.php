<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Setting;
use App\Services\CustomerOnu\CustomerOnuFieldSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Concerns\BuildsOnuSyncFixtures;
use Tests\TestCase;

#[Group('blocking-core')]
class CustomerOnuFieldSyncScheduledRunTest extends TestCase
{
    use BuildsOnuSyncFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->blockDevices();
        Queue::fake();
        Bus::fake();
        File::deleteDirectory(CustomerOnuFieldSyncService::planDir());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(CustomerOnuFieldSyncService::planDir());
        parent::tearDown();
    }

    private function scheduled(): string
    {
        Artisan::call('customers:sync-onu-fields', ['--scheduled' => true]);

        return Artisan::output();
    }

    private function oltOf(Customer $c): ?int
    {
        $v = Customer::query()->find($c->id)->olt_id;

        return $v === null ? null : (int) $v;
    }

    public function test_master_off_with_schedule_on_writes_nothing(): void
    {
        $f = $this->scenario();
        Setting::set('customer_onu_sync_schedule_enabled', 1);
        Setting::set('customer_onu_sync_enabled', 0);

        $this->assertStringContainsString('nonaktif', $this->scheduled());

        $this->assertNull($this->oltOf($f['customer']));
        $this->assertNull(Setting::get('customer_onu_sync_last_run'));
    }

    public function test_both_on_applies_sync_rows_only_and_records_the_run(): void
    {
        Setting::set('customer_onu_sync_schedule_enabled', 1);
        $sync = $this->scenario();
        $offline = $this->scenario([], ['state' => 'offline']);
        $conflict = $this->scenario();
        $this->scenario(['onu_serial' => $conflict['customer']->onu_serial], null);

        $out = $this->scheduled();

        $this->assertSame($sync['olt']->id, $this->oltOf($sync['customer']));
        $this->assertNull($this->oltOf($offline['customer']));
        $this->assertNull($this->oltOf($conflict['customer']), 'PERLU_DICEK_ADMIN is never applied');
        $this->assertStringContainsString('DITERAPKAN: 1', $out);
        $this->assertStringContainsString('PERLU DICEK ADMIN', $out);

        $last = Setting::get('customer_onu_sync_last_run');
        $last = is_array($last) ? $last : json_decode((string) $last, true);
        $this->assertSame('scheduled', $last['mode']);
        $this->assertSame(1, $last['applied']);
        $this->assertArrayNotHasKey('brake', $last);
        $this->assertFileExists(CustomerOnuFieldSyncService::planDir().'/'.$last['run_id'].'.json');

        $act = Activity::where('event', CustomerOnuFieldSyncService::EVENT_SYNCED)->first();
        $this->assertSame('scheduled', $act->properties['mode']);
        $this->assertSame('system:customers:sync-onu-fields:scheduled', $act->properties['actor']);
        Queue::assertNothingPushed();
    }

    public function test_run_is_capped_by_max_per_run(): void
    {
        Setting::set('customer_onu_sync_schedule_enabled', 1);
        Setting::set('customer_onu_sync_max_per_run', 1);
        $a = $this->scenario();
        $b = $this->scenario();

        $this->scheduled();

        $this->assertSame(1, Customer::query()->whereIn('id', [$a['customer']->id, $b['customer']->id])->whereNotNull('olt_id')->count());
    }

    public function test_brake_applies_nothing_when_too_many_rows(): void
    {
        Setting::set('customer_onu_sync_schedule_enabled', 1);
        Setting::set('customer_onu_sync_schedule_brake', 1);
        $a = $this->scenario();
        $b = $this->scenario();

        $out = $this->scheduled();

        $this->assertNull($this->oltOf($a['customer']));
        $this->assertNull($this->oltOf($b['customer']));
        $this->assertStringContainsString('REM: terlalu banyak', $out);
        $last = Setting::get('customer_onu_sync_last_run');
        $last = is_array($last) ? $last : json_decode((string) $last, true);
        $this->assertSame('terlalu_banyak', $last['brake']);
        $this->assertSame(0, $last['applied']);
        $this->assertSame(0, Activity::where('event', CustomerOnuFieldSyncService::EVENT_SYNCED)->count());
        // the plan file is still written for the operator
        $this->assertFileExists(CustomerOnuFieldSyncService::planDir().'/'.$last['run_id'].'.json');
    }

    public function test_exactly_at_the_brake_still_applies(): void
    {
        Setting::set('customer_onu_sync_schedule_enabled', 1);
        Setting::set('customer_onu_sync_schedule_brake', 2);
        $a = $this->scenario();
        $b = $this->scenario();

        $this->scheduled();

        $this->assertNotNull($this->oltOf($a['customer']));
        $this->assertNotNull($this->oltOf($b['customer']));
    }

    public function test_scheduled_cannot_be_combined_with_apply_plan_or_revert(): void
    {
        $f = $this->scenario();
        Setting::set('customer_onu_sync_schedule_enabled', 1);

        $this->assertSame(1, Artisan::call('customers:sync-onu-fields', ['--scheduled' => true, '--apply' => true]));
        $this->assertSame(1, Artisan::call('customers:sync-onu-fields', ['--scheduled' => true, '--plan' => '20261015-100000-abcd']));
        $this->assertSame(1, Artisan::call('customers:sync-onu-fields', ['--scheduled' => true, '--revert' => [$f['customer']->id]]));
        $this->assertNull($this->oltOf($f['customer']));
    }

    public function test_concurrent_run_is_refused_by_the_lock(): void
    {
        $f = $this->scenario();
        Setting::set('customer_onu_sync_schedule_enabled', 1);
        $lock = Cache::lock('customers:sync-onu-fields', 900);
        $this->assertTrue($lock->get());

        $this->assertStringContainsString('sedang berjalan', $this->scheduled());

        $this->assertNull($this->oltOf($f['customer']));
        $lock->release();
    }

    public function test_second_scheduled_run_is_idempotent(): void
    {
        Setting::set('customer_onu_sync_schedule_enabled', 1);
        $f = $this->scenario();

        $this->scheduled();
        $this->scheduled();

        $this->assertSame(1, Activity::where('event', CustomerOnuFieldSyncService::EVENT_SYNCED)->count());
        $this->assertSame($f['olt']->id, $this->oltOf($f['customer']));
    }
}
