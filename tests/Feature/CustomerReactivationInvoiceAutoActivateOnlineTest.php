<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Olt;
use App\Models\OltOnu;
use App\Models\Package;
use App\Models\Router;
use App\Services\PppoeOnlineFlagWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Owner decision 2026-10-08: pending_installation -> active automatically when
 * the ONU is online on the OLT with a valid RX AND PPPoE is active, all fresh
 * (OnlineStatusEvaluator windows). Activation creates the current invoice via
 * the Customer model hook. (Named CustomerReactivationInvoice* so it runs in
 * the blocking CI `test` job filter.)
 */
#[Group('blocking-core')]
class CustomerReactivationInvoiceAutoActivateOnlineTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private Router $router;

    private Olt $olt;

    protected function setUp(): void
    {
        parent::setUp();
        PppoeOnlineFlagWriter::flushSchemaCache();
        // 2026-10-08 10:00 WIB
        Carbon::setTestNow(Carbon::parse('2026-10-08 03:00:00', 'UTC'));
        $this->router = Router::create([
            'name' => 'RA test', 'ip_address' => '127.0.30.1', 'username' => 't', 'password' => 't', 'is_active' => true,
        ]);
        $this->olt = Olt::create(['name' => 'RA OLT', 'code' => 'RA-OLT-T', 'vendor' => 'zte_c300']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_online_fresh_pending_customer_is_activated_logged_and_billed(): void
    {
        $customer = $this->pending(['join_date' => '2026-09-03', 'due_day' => 3]);
        $this->onu($customer, 'online', -19.5, 3, 4);

        $this->artisan('customers:auto-activate-online')->assertExitCode(0);

        $customer->refresh();
        $this->assertSame('active', $customer->status);
        $this->assertDatabaseHas('activity_log', [
            'subject_id' => $customer->id,
            'event' => 'auto_activated',
            'description' => 'auto: online OLT+PPPoE',
        ]);
        $invoice = Invoice::query()->where('customer_id', $customer->id)->sole();
        $this->assertSame('2026-10-01', $invoice->period->toDateString());
        // Regular due 2026-10-03 already passed: late invoice is due on its creation date (WIB).
        $this->assertSame('2026-10-08', $invoice->due_date->toDateString());
    }

    public function test_dry_run_lists_but_changes_nothing(): void
    {
        $customer = $this->pending();
        $this->onu($customer, 'online', -20.0, 2, 2);

        $this->artisan('customers:auto-activate-online', ['--dry-run' => true])
            ->expectsOutputToContain('WOULD ACTIVATE #'.$customer->id)
            ->assertExitCode(0);

        $this->assertSame('pending_installation', $customer->refresh()->status);
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_stale_pppoe_does_not_activate(): void
    {
        $customer = $this->pending(['pppoe_online_checked_at' => Carbon::now()->subHours(3)]);
        $this->onu($customer, 'online', -20.0, 2, 2);

        $this->artisan('customers:auto-activate-online')->assertExitCode(0);

        $this->assertSame('pending_installation', $customer->refresh()->status);
    }

    public function test_stale_onu_state_or_rx_does_not_activate(): void
    {
        $staleState = $this->pending();
        $this->onu($staleState, 'online', -20.0, 60, 2);
        $staleRx = $this->pending();
        $this->onu($staleRx, 'online', -20.0, 2, 90);

        $this->artisan('customers:auto-activate-online')->assertExitCode(0);

        $this->assertSame('pending_installation', $staleState->refresh()->status);
        $this->assertSame('pending_installation', $staleRx->refresh()->status);
    }

    public function test_olt_online_but_pppoe_down_does_not_activate(): void
    {
        $customer = $this->pending(['is_online' => false]);
        $this->onu($customer, 'online', -20.0, 2, 2);

        $this->artisan('customers:auto-activate-online')->assertExitCode(0);

        $this->assertSame('pending_installation', $customer->refresh()->status);
    }

    public function test_pppoe_up_but_onu_offline_does_not_activate(): void
    {
        $customer = $this->pending();
        $this->onu($customer, 'offline', null, 2, 2);

        $this->artisan('customers:auto-activate-online')->assertExitCode(0);

        $this->assertSame('pending_installation', $customer->refresh()->status);
    }

    public function test_invalid_rx_does_not_activate(): void
    {
        $customer = $this->pending();
        $this->onu($customer, 'online', null, 2, 2);

        $this->artisan('customers:auto-activate-online')->assertExitCode(0);

        $this->assertSame('pending_installation', $customer->refresh()->status);
    }

    public function test_customer_without_package_is_skipped_and_reported(): void
    {
        $customer = $this->pending();
        $this->onu($customer, 'online', -20.0, 2, 2);
        // Orphaned package reference (package row gone).
        Schema::disableForeignKeyConstraints();
        DB::table('customers')->where('id', $customer->id)->update(['package_id' => 999999]);
        Schema::enableForeignKeyConstraints();

        $this->artisan('customers:auto-activate-online')
            ->expectsOutputToContain('skip no_package: '.$customer->id)
            ->assertExitCode(0);

        $this->assertSame('pending_installation', $customer->refresh()->status);
    }

    public function test_suspected_duplicate_is_skipped(): void
    {
        // Active owner of the same ONU (e.g. one person, two installs): never
        // auto-activated. Isolated/inactive/suspended owners are a reused-ONU
        // takeover instead (BillingHardeningPolicyReusedOnuTakeoverTest).
        $existing = $this->pending(['status' => 'active', 'onu_serial' => 'ZTEGDUP0001']);
        $customer = $this->pending(['onu_serial' => 'ZTEGDUP0001']);
        $this->onu($customer, 'online', -20.0, 2, 2);

        $this->artisan('customers:auto-activate-online')
            ->expectsOutputToContain('skip suspected_duplicate: '.$customer->id)
            ->assertExitCode(0);

        $this->assertSame('pending_installation', $customer->refresh()->status);
        $this->assertSame('active', $existing->refresh()->status);
    }

    public function test_price_zero_customer_is_activated_without_invoice(): void
    {
        $csr = Package::create(['code' => 'CSR-T', 'name' => 'Paket CSR', 'price' => 0]);
        $customer = $this->pending(['package_id' => $csr->id]);
        $this->onu($customer, 'online', -20.0, 2, 2);

        $this->artisan('customers:auto-activate-online')->assertExitCode(0);

        $this->assertSame('active', $customer->refresh()->status);
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_kill_switch_and_cap(): void
    {
        $a = $this->pending();
        $this->onu($a, 'online', -20.0, 2, 2);
        $b = $this->pending();
        $this->onu($b, 'online', -20.0, 2, 2);

        config(['billing.auto_activate.enabled' => false]);
        $this->artisan('customers:auto-activate-online')->assertExitCode(0);
        $this->assertSame('pending_installation', $a->refresh()->status);

        config(['billing.auto_activate.enabled' => true, 'billing.auto_activate.max_per_run' => 1]);
        $this->artisan('customers:auto-activate-online')->assertExitCode(0);
        $statuses = Customer::query()->whereKey([$a->id, $b->id])->pluck('status')->sort()->values()->all();
        $this->assertSame(['active', 'pending_installation'], $statuses);
    }

    /** @param array<string, mixed> $attributes */
    private function pending(array $attributes = []): Customer
    {
        $this->seq++;
        $package = Package::firstOrCreate(['code' => 'AUTO-100'], ['name' => 'Paket 100', 'price' => 100000]);

        return Customer::create(array_merge([
            'name' => 'Auto PSB '.$this->seq,
            'phone' => '0813111'.$this->seq,
            'address' => 'RT 1 / RW 1',
            'package_id' => $package->id,
            'status' => 'pending_installation',
            'router_id' => $this->router->id,
            'olt_id' => $this->olt->id,
            'onu_serial' => sprintf('ZTEGAUTO%04d', $this->seq),
            'pppoe_user' => 'auto-psb-'.$this->seq,
            'join_date' => '2026-09-20',
            'due_day' => 20,
            'is_online' => true,
            'pppoe_online_checked_at' => Carbon::now()->subMinutes(4),
        ], $attributes));
    }

    private function onu(Customer $customer, string $state, ?float $rx, int $stateAgeMinutes, int $rxAgeMinutes): void
    {
        DB::table('olt_onus')->where('serial_number', $customer->onu_serial)->delete();
        OltOnu::create([
            'olt_id' => $this->olt->id,
            'onu_ref' => '1/1/1:'.$customer->id,
            'serial_number' => $customer->onu_serial,
            'state' => $state,
            'phase_state' => $state === 'online' ? 'WORKING' : 'LOS',
            'rx_power_dbm' => $rx,
            'state_collected_at' => Carbon::now()->subMinutes($stateAgeMinutes),
            'rx_collected_at' => Carbon::now()->subMinutes($rxAgeMinutes),
        ]);
    }
}
