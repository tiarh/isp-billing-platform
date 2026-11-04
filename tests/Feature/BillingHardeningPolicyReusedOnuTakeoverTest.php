<?php

namespace Tests\Feature;

use App\Console\Commands\AutoActivateOnlineCustomers;
use App\Models\Customer;
use App\Models\CustomerPendingTermination;
use App\Models\GenieAcsDevice;
use App\Models\Invoice;
use App\Models\Olt;
use App\Models\OltOnu;
use App\Models\Package;
use App\Models\Router;
use App\Services\Lifecycle\LifecycleTerminationService;
use App\Services\Monitoring\RouterPppoeReadModel;
use App\Services\PppoeOnlineFlagWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Owner rule 2026-10-09 + roadmap 1.3 PR-4c (option C): an online
 * pending_installation account whose ONU serial belongs only to an isolated /
 * inactive / suspended account took the ONU over: new -> active (invoice via
 * the Customer::updated hook); the old account keeps its status and gets a
 * pending termination (billing hold, sweeper finalizes after the guard).
 * Old unpaid invoices stay. An active other
 * account or a shared PPPoE user keeps suspected_duplicate.
 * (Named BillingHardeningPolicy* so it runs in the blocking CI `test` job.)
 */
#[Group('blocking-billing')]
class BillingHardeningPolicyReusedOnuTakeoverTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private Router $router;

    private Olt $olt;

    protected function setUp(): void
    {
        parent::setUp();
        PppoeOnlineFlagWriter::flushSchemaCache();
        Carbon::setTestNow(Carbon::parse('2026-10-09 03:00:00', 'UTC'));
        $this->router = Router::create([
            'name' => 'RA takeover', 'ip_address' => '127.0.32.1', 'username' => 't', 'password' => 't', 'is_active' => true,
        ]);
        $this->olt = Olt::create(['name' => 'RA OLT', 'code' => 'RA-OLT-TKO', 'vendor' => 'zte_c300']);
        config(['billing.auto_activate.enabled' => true, 'billing.auto_activate.takeover_reused_onu' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_reused_onu_from_isolated_inactive_or_suspended_account_defers_old_termination(): void
    {
        foreach (['isolated', 'inactive', 'suspended'] as $oldStatus) {
            $old = $this->customer(['status' => $oldStatus, 'onu_serial' => 'ZTEGREUSE'.$oldStatus]);
            $oldInvoice = $this->unpaidInvoice($old);
            $new = $this->customer(['onu_serial' => 'ZTEGREUSE'.$oldStatus]);
            $this->onlineOnu($new->onu_serial);

            $this->artisan('customers:auto-activate-online')
                ->expectsOutputToContain('took_over_reused_onu: new #'.$new->id.' <- old #'.$old->id.' (old deferred)')
                ->assertExitCode(0);

            // Old account keeps its status; the new account is active and billed
            // by the Customer::updated hook exactly as before.
            $this->assertSame($oldStatus, $old->refresh()->status, $oldStatus);
            $this->assertSame('active', $new->refresh()->status, $oldStatus);

            $oldInvoice->refresh();
            $this->assertSame('unpaid', $oldInvoice->status);
            $this->assertSame(1, Invoice::query()->where('customer_id', $old->id)->count());
            $this->assertSame(1, Invoice::query()->where('customer_id', $new->id)->whereDate('period', '2026-10-01')->count());

            $row = CustomerPendingTermination::query()->where('customer_id', $old->id)->sole();
            $this->assertSame('pending', $row->status);
            $this->assertSame('takeover', $row->source);
            $this->assertSame((int) $new->id, (int) $row->takeover_customer_id);
            $this->assertSame('unknown', $row->last_verdict);
            $this->assertContains('pppoe:not_evaluated_takeover', $row->last_reasons);
            $this->assertTrue((bool) $row->hold_billing);
            $this->assertSame((int) $old->id, (int) $row->open_key);

            $this->assertDatabaseHas('activity_log', ['subject_id' => $old->id, 'event' => 'termination_deferred_reused_onu']);
            $this->assertDatabaseMissing('activity_log', ['subject_id' => $old->id, 'event' => 'terminated_reused_onu']);
            $this->assertDatabaseHas('activity_log', ['subject_id' => $new->id, 'event' => 'auto_activated']);
        }
    }

    public function test_takeover_works_under_backstop_enforce_and_old_account_is_never_terminated(): void
    {
        config(['lifecycle_guard.backstop_mode' => 'enforce']);
        $old = $this->customer(['status' => 'isolated', 'onu_serial' => 'ZTEGENFORCE01']);
        $new = $this->customer(['onu_serial' => 'ZTEGENFORCE01']);
        $this->onlineOnu($new->onu_serial);

        $this->artisan('customers:auto-activate-online')->assertExitCode(0);

        $this->assertSame('isolated', $old->refresh()->status, 'old account keeps its status (deferred, option C)');
        $this->assertSame('active', $new->refresh()->status);
        $this->assertSame('pending', CustomerPendingTermination::query()->where('customer_id', $old->id)->sole()->status);
        $this->assertDatabaseMissing('activity_log', ['event' => 'lifecycle_backstop_violation']);
    }

    public function test_billing_hold_stops_old_account_invoices_after_takeover(): void
    {
        $old = $this->customer(['status' => 'isolated', 'onu_serial' => 'ZTEGHOLD0001']);
        $new = $this->customer(['onu_serial' => 'ZTEGHOLD0001']);
        $this->onlineOnu($new->onu_serial);

        $this->artisan('customers:auto-activate-online')->assertExitCode(0);

        $this->assertTrue(app(LifecycleTerminationService::class)->isBillingHeld($old->refresh()));
        $this->assertFalse(app(LifecycleTerminationService::class)->isBillingHeld($new->refresh()));
    }

    public function test_takeover_links_an_already_open_warehouse_row_and_the_sweeper_waives_the_new_accounts_signals(): void
    {
        $model = \Mockery::mock(RouterPppoeReadModel::class);
        $model->shouldReceive('live')->andReturn(['status' => 'unavailable', 'fetched_at' => null, 'reason' => 'down', 'users' => null]);
        $this->app->instance(RouterPppoeReadModel::class, $model);

        $old = $this->customer(['status' => 'suspended', 'onu_serial' => 'ZTEGLINK0001']);
        $existing = CustomerPendingTermination::create([
            'customer_id' => $old->id, 'source' => 'warehouse_receive', 'status' => 'pending',
            'hold_billing' => true, 'requested_at' => now()->subDay(), 'recovery_case_id' => null,
        ]);
        $new = $this->customer(['onu_serial' => 'ZTEGLINK0001']);
        $this->onlineOnu($new->onu_serial);
        // The new account's fresh GenieACS inform on the shared serial.
        GenieAcsDevice::create(['device_id' => 'DEV-LINK', 'customer_id' => $new->id, 'serial_number' => 'ZTEGLINK0001', 'last_inform_at' => now()->subMinutes(5)]);

        $this->artisan('customers:auto-activate-online')->assertExitCode(0);

        $row = CustomerPendingTermination::query()->where('customer_id', $old->id)->sole();
        $this->assertSame($existing->id, $row->id);
        $this->assertSame('warehouse_receive', $row->source);
        $this->assertSame((int) $new->id, (int) $row->takeover_customer_id);
        $this->assertSame((int) $new->id, (int) $row->meta['takeover']['customer_id']);
        $this->assertNull($row->meta['takeover']['previous_takeover_customer_id']);
        $this->assertTrue($row->isTakeoverLinked());
        $this->assertSame('suspended', $old->refresh()->status);

        Artisan::call('customers:finalize-deferred-terminations');
        $out = Artisan::output();

        $this->assertStringContainsString('#'.$row->id, $out);
        $this->assertStringNotContainsString('traffic_in_use', $out);
        $this->assertStringNotContainsString('takeover_link_broken', $out);
        $this->assertStringNotContainsString('-> not_terminable', $out, 'suspended is allowed for takeover-linked rows');
        $this->assertStringNotContainsString('onu_in_use', $out);
    }

    public function test_takeover_aborts_when_old_account_status_is_not_takeover_eligible(): void
    {
        $old = $this->customer(['status' => 'active', 'onu_serial' => 'ZTEGCONC0001']);
        $new = $this->customer(['onu_serial' => 'ZTEGCONC0001']);

        $command = app(AutoActivateOnlineCustomers::class);
        $takeOver = new \ReflectionMethod($command, 'takeOver');
        $done = $takeOver->invoke($command, (int) $new->id, [(int) $old->id], []);

        $this->assertFalse($done);
        $this->assertSame('active', $old->refresh()->status);
        $this->assertSame('pending_installation', $new->refresh()->status);
        $this->assertSame(0, CustomerPendingTermination::query()->count());
    }

    public function test_active_other_account_is_never_taken_over(): void
    {
        $old = $this->customer(['status' => 'active', 'onu_serial' => 'ZTEGTWOINST1']);
        $new = $this->customer(['onu_serial' => 'ZTEGTWOINST1']);
        $this->onlineOnu($new->onu_serial);

        $this->artisan('customers:auto-activate-online')
            ->expectsOutputToContain('skip suspected_duplicate: '.$new->id)
            ->assertExitCode(0);

        $this->assertSame('active', $old->refresh()->status);
        $this->assertSame('pending_installation', $new->refresh()->status);
    }

    public function test_same_pppoe_user_is_a_duplicate_not_a_takeover(): void
    {
        // customers.pppoe_user is UNIQUE, so two persisted rows cannot share it;
        // exercise the conflict rule on an unsaved pending row instead.
        $old = $this->customer(['status' => 'isolated', 'onu_serial' => 'ZTEGSAMEUSR1', 'pppoe_user' => 'same-user-1']);
        $new = new Customer(['onu_serial' => 'ZTEGSAMEUSR1', 'pppoe_user' => 'Same-User-1', 'status' => 'pending_installation']);
        $new->id = 999999;

        $command = app(AutoActivateOnlineCustomers::class);
        $conflictMap = new \ReflectionMethod($command, 'conflictMap');
        $conflicts = $conflictMap->invoke($command, collect([$new]), true);

        $this->assertSame([999999 => ['duplicate_of' => (int) $old->id]], $conflicts);
    }

    public function test_terminated_old_account_does_not_block_activation(): void
    {
        $old = $this->customer(['status' => 'terminated', 'onu_serial' => 'ZTEGTERM0001']);
        $new = $this->customer(['onu_serial' => 'ZTEGTERM0001']);
        $this->onlineOnu($new->onu_serial);

        $this->artisan('customers:auto-activate-online')->assertExitCode(0);

        $this->assertSame('terminated', $old->refresh()->status);
        $this->assertSame('active', $new->refresh()->status);
    }

    public function test_failed_plain_activation_is_reported_as_activation_error_not_takeover_error(): void
    {
        $new = $this->customer(['onu_serial' => 'ZTEGACTERR01']);
        $this->onlineOnu($new->onu_serial);
        Customer::updating(function (): void {
            throw new \RuntimeException('deadlock');
        });

        $exit = Artisan::call('customers:auto-activate-online');
        $out = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertSame('pending_installation', $new->refresh()->status);
        $this->assertStringContainsString('skip activation_error: '.$new->id, $out);
        $this->assertStringNotContainsString('takeover_error', $out);
    }

    public function test_failed_takeover_keeps_the_takeover_error_key(): void
    {
        $old = $this->customer(['status' => 'suspended', 'onu_serial' => 'ZTEGACTERR02']);
        $new = $this->customer(['onu_serial' => 'ZTEGACTERR02']);
        $this->onlineOnu($new->onu_serial);
        Customer::updating(function (): void {
            throw new \RuntimeException('deadlock');
        });

        Artisan::call('customers:auto-activate-online');
        $out = Artisan::output();

        $this->assertSame('pending_installation', $new->refresh()->status);
        $this->assertStringContainsString('skip takeover_error: '.$new->id, $out);
        $this->assertStringNotContainsString('activation_error', $out);
        $this->assertSame('suspended', $old->refresh()->status);
    }

    public function test_dry_run_reports_would_take_over_and_writes_nothing(): void
    {
        $old = $this->customer(['status' => 'isolated', 'onu_serial' => 'ZTEGDRYRUN01']);
        $new = $this->customer(['onu_serial' => 'ZTEGDRYRUN01']);
        $this->onlineOnu($new->onu_serial);

        $this->artisan('customers:auto-activate-online', ['--dry-run' => true])
            ->expectsOutputToContain('would_take_over: new #'.$new->id.' <- old #'.$old->id.' (old deferred)')
            ->expectsOutputToContain('(old -> termination deferred)')
            ->expectsOutputToContain('"would_take_over":1')
            ->assertExitCode(0);

        $this->assertSame('isolated', $old->refresh()->status);
        $this->assertSame('pending_installation', $new->refresh()->status);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertSame(0, CustomerPendingTermination::query()->count());
        $this->assertDatabaseMissing('activity_log', ['event' => 'termination_deferred_reused_onu']);
    }

    public function test_takeover_is_idempotent_on_rerun(): void
    {
        $old = $this->customer(['status' => 'isolated', 'onu_serial' => 'ZTEGIDEMP001']);
        $new = $this->customer(['onu_serial' => 'ZTEGIDEMP001']);
        $this->onlineOnu($new->onu_serial);

        $this->artisan('customers:auto-activate-online')
            ->expectsOutputToContain('"took_over_reused_onu":1,"old_deferred":1')
            ->assertExitCode(0);
        $this->artisan('customers:auto-activate-online')
            ->expectsOutputToContain('"took_over_reused_onu":0')
            ->assertExitCode(0);

        $this->assertSame('isolated', $old->refresh()->status);
        $this->assertSame('active', $new->refresh()->status);
        $this->assertSame(1, Invoice::query()->where('customer_id', $new->id)->count());
        $this->assertSame(1, DB::table('activity_log')->where('subject_id', $old->id)->where('event', 'termination_deferred_reused_onu')->count());
        $this->assertSame(1, CustomerPendingTermination::query()->where('customer_id', $old->id)->count());
    }

    public function test_takeover_switch_off_keeps_suspected_duplicate(): void
    {
        config(['billing.auto_activate.takeover_reused_onu' => false]);
        $old = $this->customer(['status' => 'isolated', 'onu_serial' => 'ZTEGSWITCH01']);
        $new = $this->customer(['onu_serial' => 'ZTEGSWITCH01']);
        $this->onlineOnu($new->onu_serial);

        $this->artisan('customers:auto-activate-online')
            ->expectsOutputToContain('skip suspected_duplicate: '.$new->id)
            ->assertExitCode(0);

        $this->assertSame('isolated', $old->refresh()->status);
        $this->assertSame('pending_installation', $new->refresh()->status);
    }

    public function test_kill_switch_blocks_takeover(): void
    {
        config(['billing.auto_activate.enabled' => false]);
        $old = $this->customer(['status' => 'isolated', 'onu_serial' => 'ZTEGKILL0001']);
        $new = $this->customer(['onu_serial' => 'ZTEGKILL0001']);
        $this->onlineOnu($new->onu_serial);

        $this->artisan('customers:auto-activate-online')->assertExitCode(0);

        $this->assertSame('isolated', $old->refresh()->status);
        $this->assertSame('pending_installation', $new->refresh()->status);
    }

    /** @param array<string, mixed> $attributes */
    private function customer(array $attributes = []): Customer
    {
        $this->seq++;
        $package = Package::firstOrCreate(['code' => 'TKO-100'], ['name' => 'Paket 100', 'price' => 100000]);

        return Customer::create(array_merge([
            'name' => 'Takeover '.$this->seq,
            'phone' => '0813333'.$this->seq,
            'address' => 'RT 3 / RW 3',
            'package_id' => $package->id,
            'status' => 'pending_installation',
            'router_id' => $this->router->id,
            'olt_id' => $this->olt->id,
            'onu_serial' => sprintf('ZTEGTKO%05d', $this->seq),
            'pppoe_user' => 'takeover-'.$this->seq,
            'join_date' => '2026-09-20',
            'due_day' => 20,
            'is_online' => true,
            'pppoe_online_checked_at' => Carbon::now()->subMinutes(4),
        ], $attributes));
    }

    private function onlineOnu(string $serial): void
    {
        OltOnu::create([
            'olt_id' => $this->olt->id,
            'onu_ref' => '1/5/1:'.$this->seq,
            'serial_number' => $serial,
            'state' => 'online',
            'phase_state' => 'working',
            'rx_power_dbm' => -20.5,
            'state_collected_at' => Carbon::now()->subMinutes(2),
            'rx_collected_at' => Carbon::now()->subMinutes(2),
        ]);
    }

    private function unpaidInvoice(Customer $customer): Invoice
    {
        return Invoice::create([
            'customer_id' => $customer->id,
            'period' => '2026-09-01',
            'amount' => 100000,
            'due_date' => '2026-09-20',
            'status' => 'unpaid',
            'generated_at' => Carbon::now()->subMonth(),
        ]);
    }
}
