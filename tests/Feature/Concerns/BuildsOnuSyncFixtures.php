<?php

namespace Tests\Feature\Concerns;

use App\Models\Area;
use App\Models\Customer;
use App\Models\CustomerMigration;
use App\Models\DeviceRecoveryCase;
use App\Models\Olt;
use App\Models\OltOnu;
use App\Models\Package;
use App\Models\Router;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\CustomerOnu\CustomerOnuFieldSyncService;
use App\Services\CustomerOnu\CustomerOnuSyncVerdict;
use App\Services\DeviceRecoveryService;
use App\Services\GenieAcsService;
use App\Services\HuaweiOltOnlyProvisioningService;
use App\Services\MikrotikService;
use App\Services\OltZteC300Service;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\Support\Canary;

/**
 * Customer ONU field sync fixtures. Device services are bare mocks (any call throws), HTTP is blocked.
 */
trait BuildsOnuSyncFixtures
{
    private int $sx = 0;

    private function blockDevices(): void
    {
        $this->app->instance(MikrotikService::class, Mockery::mock(MikrotikService::class));
        $this->app->instance(OltZteC300Service::class, Mockery::mock(OltZteC300Service::class));
        $this->app->instance(GenieAcsService::class, Mockery::mock(GenieAcsService::class));
        $this->app->instance(HuaweiOltOnlyProvisioningService::class, Mockery::mock(HuaweiOltOnlyProvisioningService::class));
        Http::preventStrayRequests();
    }

    /**
     * A customer that would be synced (B1: olt_id NULL) with a fresh online ONU carrying its serial.
     *
     * @param  array<string, mixed>  $c  customer overrides
     * @param  array<string, mixed>|null  $o  olt_onus overrides (null = no ONU row)
     * @param  array<string, mixed>  $oltOverrides
     * @return array{customer: Customer, onu: OltOnu|null, olt: Olt, router: Router}
     */
    private function scenario(array $c = [], ?array $o = [], array $oltOverrides = []): array
    {
        $n = ++$this->sx;
        $router = Router::create(['name' => 'OS-R'.$n, 'ip_address' => '198.18.6.'.$n, 'username' => 'admin', 'password' => Canary::of('pw'), 'is_active' => true]);
        $olt = Olt::create(array_merge(['name' => 'OS OLT '.$n, 'code' => 'OS-OLT-'.$n, 'vendor' => 'huawei', 'router_id' => $router->id], $oltOverrides));
        $package = Package::create(['code' => 'OSP-'.$n, 'name' => 'Paket '.$n, 'price' => 150000]);
        $serial = 'FHTTSYN'.str_pad((string) $n, 5, '0', STR_PAD_LEFT);
        $pppoe = 'pelanggan.sync'.$n;

        $customer = Customer::create(array_merge([
            'code' => 'OS-'.$n,
            'name' => 'Pelanggan Sync '.$n,
            'phone' => '0810000011'.$n,
            'address' => 'RT 1 RW 2',
            'package_id' => $package->id,
            'pppoe_user' => $pppoe,
            'pppoe_password' => 'canary-pw',
            'onu_serial' => $serial,
            'olt_port_label' => null,
            'router_id' => $router->id,
            'olt_id' => null,
            'status' => 'active',
            'is_online' => true,
            'pppoe_online_checked_at' => now()->subMinutes(5),
        ], $c));

        $onu = null;
        if ($o !== null) {
            $onu = OltOnu::create(array_merge([
                'olt_id' => $olt->id,
                'onu_ref' => '1/2/1:'.$n,
                'pon_port' => '1/2/1',
                'serial_number' => $serial,
                'onu_name' => $pppoe,
                'state' => 'online',
                'state_collected_at' => now()->subMinutes(5),
                'inventory_collected_at' => now()->subMinutes(5),
            ], $o));
        }

        return ['customer' => $customer, 'onu' => $onu, 'olt' => $olt, 'router' => $router];
    }

    /**
     * A migration auto-synced by #517 (scope A source). Defaults: synced 8 h ago, sn_source new_sn.
     *
     * @param  array<string, mixed>  $m
     * @param  array<string, mixed>  $auto
     */
    private function autoMigration(Customer $customer, Olt $olt, string $newSn, array $m = [], array $auto = [], int $hoursAgo = 8): CustomerMigration
    {
        $at = now()->subHours($hoursAgo);
        $migration = CustomerMigration::create(array_merge([
            'status' => CustomerMigration::STATUS_SYNCED,
            'source' => 'wa',
            'customer_id' => null, // #517 deliberately leaves it NULL
            'pppoe_user' => $customer->pppoe_user,
            'full_name' => $customer->name,
            'new_olt_id' => $olt->id,
            'new_sn' => $newSn,
            'synced_at' => $at,
            'meta' => ['auto_reconcile' => array_merge([
                'version' => 1,
                'run_id' => '20261015-100000-ab12',
                'at' => $at->toIso8601String(),
                'sn_source' => 'new_sn',
                'original_new_sn' => $newSn,
                'effective_sn' => $newSn,
                'resolved_customer_id' => $customer->id,
            ], $auto)],
        ], $m));

        return $migration->fresh();
    }

    private function openRecoveryCase(Customer $customer, ?string $serial = null): DeviceRecoveryCase
    {
        $area = Area::query()->first() ?? Area::create(['name' => 'OS-AREA', 'code' => 'os-area']);
        $admin = User::factory()->create(['role' => 'admin']);
        $ticket = SupportTicket::create(['customer_id' => $customer->id, 'area_id' => $area->id, 'created_by' => $admin->id, 'gangguan' => 'Dismantle', 'subject' => 'Dismantle', 'status' => 'assigned', 'priority' => 'normal', 'type' => 'dismantle']);
        $case = DeviceRecoveryCase::create(['ticket_id' => $ticket->id, 'customer_id' => $customer->id, 'area_id' => $area->id, 'status' => DeviceRecoveryService::STATUS_WAITING_ASSIGNMENT, 'created_by' => $admin->id]);
        if ($serial !== null) {
            $case->items()->create(['device_type' => 'onu', 'expected_serial_number' => $serial, 'status' => 'expected']);
        }

        return $case;
    }

    /** @param array<string, mixed> $opts */
    private function verdictFor(Customer $customer, array $opts = []): ?CustomerOnuSyncVerdict
    {
        $res = app(CustomerOnuFieldSyncService::class)->evaluate($opts + ['ids' => [$customer->id]]);
        foreach ($res['verdicts'] as $v) {
            if ($v->customerId === (int) $customer->id) {
                return $v;
            }
        }

        return null;
    }

    private function assertVerdict(Customer $customer, string $kind, string $reason, array $opts = []): CustomerOnuSyncVerdict
    {
        $v = $this->verdictFor($customer, $opts);
        $this->assertNotNull($v, "no verdict for {$customer->code}, expected {$kind}/{$reason}");
        $this->assertSame($kind.'/'.$reason, $v->kind.'/'.$v->reason);

        return $v;
    }
}
