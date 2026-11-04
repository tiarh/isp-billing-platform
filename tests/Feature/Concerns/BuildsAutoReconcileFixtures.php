<?php

namespace Tests\Feature\Concerns;

use App\Models\Customer;
use App\Models\CustomerMigration;
use App\Models\Olt;
use App\Models\OltOnu;
use App\Models\Package;
use App\Models\Router;
use App\Services\Migration\MigrationAutoReconcileService;
use App\Services\Migration\MigrationReconcileVerdict;
use Illuminate\Support\Facades\DB;
use Tests\Support\Canary;

trait BuildsAutoReconcileFixtures
{
    private int $fx = 0;

    /**
     * A migration that passes every rule (R1-R10) unless overridden.
     *
     * @param  array<string, mixed>  $m  migration overrides
     * @param  array<string, mixed>  $c  customer overrides
     * @param  array<string, mixed>|null  $o  olt_onus overrides (null = no ONU row)
     * @return array{migration: CustomerMigration, customer: Customer, onu: OltOnu|null, olt: Olt, router: Router}
     */
    private function fixture(array $m = [], array $c = [], ?array $o = [], array $times = []): array
    {
        $n = ++$this->fx;
        $router = Router::create(['name' => 'RA-CORE-'.$n, 'ip_address' => '198.18.2.'.$n, 'username' => 'admin', 'password' => Canary::of('pw'), 'is_active' => true]);
        $olt = Olt::create(['name' => 'OLT RA '.$n, 'code' => 'OLT-RA-'.$n, 'vendor' => 'zte_c300', 'router_id' => $router->id]);
        // Default: a normal cross-OLT migration (old ONU on its own OLT). Pass 'old_olt_id' => 'same' for same-OLT rows.
        $sameOlt = ($m['old_olt_id'] ?? null) === 'same';
        unset($m['old_olt_id']);
        $oldOlt = $sameOlt ? $olt : Olt::create(['name' => 'OLT OLD '.$n, 'code' => 'OLT-OLD-'.$n, 'vendor' => 'zte_c300', 'router_id' => $router->id]);
        $package = Package::create(['code' => 'PKG-'.$n, 'name' => 'Paket '.$n, 'price' => 150000]);
        $pppoe = 'budi.santoso'.$n;
        $sn = 'FHTTNEW'.str_pad((string) $n, 5, '0', STR_PAD_LEFT);

        $customer = Customer::create(array_merge([
            'code' => 'RC-'.$n,
            'name' => 'Budi Contoh '.$n,
            'phone' => '0812000000'.$n,
            'address' => 'RT 1 RW 2',
            'package_id' => $package->id,
            'pppoe_user' => $pppoe,
            'onu_serial' => 'ZTEOLD'.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
            'olt_port_label' => '1/1/1:'.$n,
            'router_id' => $router->id,
            'olt_id' => $olt->id,
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
                'serial_number' => $sn,
                'onu_name' => $pppoe,
                'state' => 'online',
                'state_collected_at' => now()->subMinutes(5),
                'inventory_collected_at' => now()->subMinutes(5),
            ], $o));
        }

        $migration = CustomerMigration::create(array_merge([
            'status' => CustomerMigration::STATUS_NEEDS_REVIEW,
            'source' => 'wa',
            'group_jid' => 'activation@g.us',
            'message_id' => 'rc-'.$n,
            'customer_id' => $customer->id,
            'pppoe_user' => $pppoe,
            'full_name' => $customer->name,
            'old_olt_id' => $oldOlt->id,
            'old_onu_ref' => '1/1/1:'.$n,
            'old_sn' => 'ZTEOLD'.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
            'router_id' => $router->id,
            'new_olt_id' => $olt->id,
            'new_pon_port' => '1/2/1',
            'new_onu_ref' => '1/2/1:'.$n,
            'new_sn' => $sn,
            'last_error' => 'Menunggu review.',
            'meta' => ['input_identity' => ['pppoe_user' => $pppoe]],
        ], $m));

        DB::table('customer_migrations')->where('id', $migration->id)->update([
            'created_at' => now()->subMinutes($times['created'] ?? 180),
            'updated_at' => now()->subMinutes($times['updated'] ?? 120),
        ]);

        return ['migration' => $migration->fresh(), 'customer' => $customer, 'onu' => $onu, 'olt' => $olt, 'old_olt' => $oldOlt, 'router' => $router];
    }

    private function verdict(CustomerMigration $migration): MigrationReconcileVerdict
    {
        $result = app(MigrationAutoReconcileService::class)->evaluate([$migration->id]);

        return $result['verdicts'][0];
    }

    private function assertSkip(CustomerMigration $migration, string $reason): void
    {
        $v = $this->verdict($migration);
        $this->assertSame(MigrationReconcileVerdict::SKIP, $v->kind, 'expected skip '.$reason.' got '.$v->kind.'/'.$v->reason);
        $this->assertSame($reason, $v->reason);
    }
}
