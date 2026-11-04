<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Customer;
use App\Models\CustomerMigration;
use App\Models\CustomerPendingTermination;
use App\Models\Invoice;
use App\Models\Olt;
use App\Models\OltOnu;
use App\Models\Package;
use App\Models\Router;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\CustomerMigrationService;
use App\Services\DeviceRecoveryService;
use App\Services\PppoeOnlineFlagWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Roadmap 1.3 PR-4a pin tests (plan section 8): the pending-termination hold
 * must not touch the PSB auto-activation + first-invoice flow.
 */
#[Group('blocking-psb')]
#[Group('blocking-core')]
class LifecyclePendingHoldPsbPinTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private Router $router;

    private Olt $olt;

    protected function setUp(): void
    {
        parent::setUp();
        PppoeOnlineFlagWriter::flushSchemaCache();
        Carbon::setTestNow(Carbon::parse('2026-10-08 03:00:00', 'UTC'));
        $this->router = Router::create(['name' => 'RA pin', 'ip_address' => '127.0.33.1', 'username' => 't', 'password' => 't', 'is_active' => true]);
        $this->olt = Olt::create(['name' => 'RA OLT', 'code' => 'RA-OLT-PIN', 'vendor' => 'zte_c300']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_pin_a_psb_pending_installation_auto_activates_and_gets_invoice(): void
    {
        $customer = $this->psbCustomer();
        $this->onlineOnu($customer);

        $this->artisan('customers:auto-activate-online')->assertExitCode(0);

        $this->assertSame('active', $customer->refresh()->status);
        $invoice = Invoice::query()->where('customer_id', $customer->id)->sole();
        $this->assertSame('2026-10-01', $invoice->period->toDateString());
    }

    public function test_pin_b_pending_row_on_another_customer_does_not_affect_psb_activation_and_invoice(): void
    {
        $other = $this->psbCustomer(['status' => 'active', 'onu_serial' => 'ZTEGOTHER0001', 'pppoe_user' => 'pin-other']);
        CustomerPendingTermination::create([
            'customer_id' => $other->id, 'source' => 'warehouse_receive', 'status' => 'pending',
            'hold_billing' => true, 'requested_at' => now(),
        ]);
        $customer = $this->psbCustomer();
        $this->onlineOnu($customer);

        $this->artisan('customers:auto-activate-online')->assertExitCode(0);

        $this->assertSame('active', $customer->refresh()->status);
        $this->assertSame(1, Invoice::query()->where('customer_id', $customer->id)->whereDate('period', '2026-10-01')->count());
        $this->assertSame(0, Invoice::query()->where('customer_id', $other->id)->count());
    }

    public function test_pin_c_takeover_new_psb_account_active_with_invoice_old_pending_and_old_case_closed_reused(): void
    {
        config(['device-recovery.mode' => 'write']);
        $admin = User::factory()->create(['role' => 'admin']);
        $area = Area::create(['name' => 'Pin Area', 'code' => 'PNA']);
        $old = $this->psbCustomer(['status' => 'isolated', 'onu_serial' => 'FHTT0000A02B', 'area_id' => $area->id]);
        $ticket = SupportTicket::create([
            'customer_id' => $old->id, 'area_id' => $area->id, 'created_by' => $admin->id,
            'gangguan' => 'Dismantle', 'subject' => 'Dismantle', 'status' => 'assigned', 'priority' => 'normal', 'type' => 'dismantle',
        ]);
        $case = app(DeviceRecoveryService::class)->createFromApprovedDismantle($ticket, $admin);

        $new = $this->psbCustomer(['onu_serial' => 'FHTT0000A02B']);
        $this->onlineOnu($new);

        $this->artisan('customers:auto-activate-online')->assertExitCode(0);

        $this->assertSame('active', $new->refresh()->status);
        $this->assertSame(1, Invoice::query()->where('customer_id', $new->id)->whereDate('period', '2026-10-01')->count());
        $this->assertSame('isolated', $old->refresh()->status);
        $row = CustomerPendingTermination::query()->where('customer_id', $old->id)->sole();
        $this->assertSame('takeover', $row->source);
        $this->assertSame('pending', $row->status);
        $this->assertSame(DeviceRecoveryService::STATUS_REUSED, $case->refresh()->status);
    }

    public function test_pin_d_huawei_draft_old_sn_resolves_the_new_account_pppoe_after_takeover(): void
    {
        $old = $this->psbCustomer(['status' => 'isolated', 'onu_serial' => 'ZTEGPIND0001', 'pppoe_user' => 'pin-old-user']);
        $new = $this->psbCustomer(['status' => 'active', 'onu_serial' => 'ZTEGPIND0001', 'pppoe_user' => 'pin-new-user']);
        CustomerPendingTermination::create([
            'customer_id' => $old->id, 'source' => 'takeover', 'takeover_customer_id' => $new->id, 'status' => 'pending',
            'hold_billing' => true, 'requested_at' => now(),
        ]);

        $service = app(CustomerMigrationService::class);
        $resolved = (new ReflectionMethod($service, 'resolvePppoeForHuaweiDraft'))->invoke($service, ['full_name' => 'X'], 'ZTEGPIND0001', '');

        $this->assertSame('old_sn', $resolved['source']);
        $this->assertSame((int) $new->id, $resolved['customer_id']);
    }

    public function test_pin_e_sn_ownership_conflicts_ignore_pending_rows_and_new_sn(): void
    {
        $old = $this->psbCustomer(['status' => 'isolated', 'onu_serial' => 'ZTEGPINE0001']);
        $new = $this->psbCustomer(['status' => 'active', 'onu_serial' => 'ZTEGPINE0001']);
        $service = app(CustomerMigrationService::class);
        $method = new ReflectionMethod($service, 'snOwnershipConflicts');
        $migration = new CustomerMigration;

        $before = $method->invoke($service, $migration, 'ZTEGPINE0001', null, null);
        $this->assertNotSame([], $before);
        $this->assertSame([], $method->invoke($service, $migration, 'ZTEGPINE9999', null, $new));

        CustomerPendingTermination::create([
            'customer_id' => $old->id, 'source' => 'takeover', 'takeover_customer_id' => $new->id, 'status' => 'pending',
            'hold_billing' => true, 'requested_at' => now(),
        ]);

        $this->assertSame($before, $method->invoke($service, $migration, 'ZTEGPINE0001', null, null));
        $this->assertSame([], $method->invoke($service, $migration, 'ZTEGPINE9999', null, $new));
    }

    private function psbCustomer(array $attributes = []): Customer
    {
        $this->seq++;
        $package = Package::firstOrCreate(['code' => 'PIN-100'], ['name' => 'Paket 100', 'price' => 100000]);

        return Customer::create(array_merge([
            'name' => 'Pin PSB '.$this->seq,
            'phone' => '0813444'.$this->seq,
            'address' => 'RT 1 / RW 1',
            'package_id' => $package->id,
            'status' => 'pending_installation',
            'router_id' => $this->router->id,
            'olt_id' => $this->olt->id,
            'onu_serial' => sprintf('ZTEGPIN%05d', $this->seq),
            'pppoe_user' => 'pin-psb-'.$this->seq,
            'join_date' => '2026-09-03',
            'due_day' => 3,
            'is_online' => true,
            'pppoe_online_checked_at' => Carbon::now()->subMinutes(4),
        ], $attributes));
    }

    private function onlineOnu(Customer $customer): void
    {
        OltOnu::create([
            'olt_id' => $this->olt->id,
            'onu_ref' => '1/1/1:'.$customer->id,
            'serial_number' => $customer->onu_serial,
            'state' => 'online',
            'phase_state' => 'WORKING',
            'rx_power_dbm' => -19.5,
            'state_collected_at' => Carbon::now()->subMinutes(3),
            'rx_collected_at' => Carbon::now()->subMinutes(4),
        ]);
    }
}
