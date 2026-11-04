<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerMigration;
use App\Models\Olt;
use App\Models\Package;
use App\Models\Router;
use App\Services\CustomerMigrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Canary;
use Tests\TestCase;

#[Group('blocking-psb')]
class CustomerMigrationGuidedWaTargetOltTest extends TestCase
{
    use RefreshDatabase;

    public function test_cross_router_guided_confirm_does_not_prefill_source_olt_and_resolves_target_olt(): void
    {
        $sourceRouter = $this->router('SRC');
        $sourceOlt = $this->olt($sourceRouter, 'OLT-HIOSO', 'hioso');
        $targetRouter = $this->router('TGT');
        $targetOlt = $this->olt($targetRouter, 'OLT-C300', 'zte_c300');
        $customer = $this->customer($sourceRouter, $sourceOlt);
        $migration = $this->migration($targetRouter);

        $confirmed = $this->confirm($migration, $customer);

        $this->assertSame($targetRouter->id, (int) $confirmed->router_id);
        $this->assertSame($sourceRouter->id, (int) $confirmed->old_router_id);
        $this->assertSame($sourceOlt->id, (int) $confirmed->old_olt_id);
        $this->assertNull($confirmed->new_olt_id);

        $resolved = $this->resolve($confirmed, $customer, $targetRouter);
        $this->assertSame($targetOlt->id, $resolved?->id);
    }

    public function test_same_router_guided_confirm_keeps_olt_prefill(): void
    {
        $router = $this->router('SAME');
        $olt = $this->olt($router, 'OLT-SAME', 'zte_c300');
        $customer = $this->customer($router, $olt);
        $migration = $this->migration($router);

        $confirmed = $this->confirm($migration, $customer);

        $this->assertSame($olt->id, (int) $confirmed->new_olt_id);
        $this->assertSame($olt->id, $this->resolve($confirmed, $customer, $router)?->id);
    }

    public function test_cross_router_guided_confirm_keeps_olt_explicitly_chosen_before(): void
    {
        $sourceRouter = $this->router('SRC');
        $sourceOlt = $this->olt($sourceRouter, 'OLT-HIOSO', 'hioso');
        $targetRouter = $this->router('TGT');
        $this->olt($targetRouter, 'OLT-C300', 'zte_c300');
        $chosen = $this->olt($targetRouter, 'OLT-OTHER', 'fiberhome');
        $customer = $this->customer($sourceRouter, $sourceOlt);
        $migration = $this->migration($targetRouter);
        $migration->new_olt_id = $chosen->id;
        $migration->save();

        $confirmed = $this->confirm($migration, $customer);

        $this->assertSame($chosen->id, (int) $confirmed->new_olt_id);
    }

    public function test_guided_confirm_refuses_customer_with_open_partially_executed_migration(): void
    {
        $router = $this->router('SAME');
        $olt = $this->olt($router, 'OLT-SAME', 'zte_c300');
        $customer = $this->customer($router, $olt);
        $partial = $this->migration($router);
        $partial->customer_id = $customer->id;
        $partial->status = CustomerMigration::STATUS_PARTIALLY_EXECUTED;
        $partial->save();
        $migration = $this->migration($router);

        try {
            $this->confirm($migration, $customer);
            $this->fail('Expected refusal');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString("migrasi #{$partial->id} yang sebagian dieksekusi", $e->getMessage());
            $this->assertStringContainsString("#migrasi eksekusi #{$partial->id}", $e->getMessage());
        }
        $this->assertNull($migration->fresh()->customer_id);
    }

    public function test_explicit_olt_line_beats_same_router_prefill(): void
    {
        $router = $this->router('SAME');
        $sourceOlt = $this->olt($router, 'OLT-OLD', 'hioso');
        $otherRouter = $this->router('OTH');
        $raOlt = Olt::create(['name' => 'RA', 'code' => 'RA', 'vendor' => 'zte_c300', 'router_id' => $otherRouter->id]);
        $customer = $this->customer($router, $sourceOlt);
        $migration = $this->migration($router);
        $migration->raw_text = "#migrasi\nRouter: Randuagung\nOLT: {$raOlt->name}\nSN baru: FHTTFAKE0001";
        $migration->save();

        $confirmed = $this->confirm($migration, $customer);

        $this->assertNull($confirmed->new_olt_id);
        $this->assertSame($raOlt->id, $this->resolve($confirmed, $customer, $router)?->id);
    }

    public function test_guided_confirm_refuses_failed_migration_with_recorded_partial_state(): void
    {
        $router = $this->router('SAME');
        $olt = $this->olt($router, 'OLT-SAME', 'zte_c300');
        $customer = $this->customer($router, $olt);
        $failed = $this->migration($router);
        $failed->customer_id = $customer->id;
        $failed->status = CustomerMigration::STATUS_FAILED;
        $failed->meta = array_merge((array) $failed->meta, ['mikrotik_migration' => ['ok' => true]]);
        $failed->save();
        $migration = $this->migration($router);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("#migrasi eksekusi #{$failed->id}");
        $this->confirm($migration, $customer);
    }

    public function test_reselecting_candidate_drops_previous_customer_olt_prefill(): void
    {
        $sourceRouter = $this->router('SRC');
        $sourceOlt = $this->olt($sourceRouter, 'OLT-A', 'hioso');
        $first = $this->customer($sourceRouter, $sourceOlt);
        $secondRouter = $this->router('SRC2');
        $secondOlt = $this->olt($secondRouter, 'OLT-B', 'hioso');
        $second = $this->customer($secondRouter, $secondOlt);
        $targetRouter = $this->router('TGT');
        $this->olt($targetRouter, 'OLT-C300', 'zte_c300');
        $migration = $this->migration($targetRouter);
        $migration->customer_id = $first->id;
        $migration->new_olt_id = $sourceOlt->id;
        $migration->save();

        $confirmed = $this->confirm($migration, $second);

        $this->assertNull($confirmed->new_olt_id);
    }

    public function test_proposed_snapshot_shows_target_olt_not_source_olt(): void
    {
        $sourceRouter = $this->router('SRC');
        $sourceOlt = $this->olt($sourceRouter, 'OLT-HIOSO', 'hioso');
        $targetRouter = $this->router('TGT');
        $targetOlt = $this->olt($targetRouter, 'OLT-C300', 'zte_c300');
        $customer = $this->customer($sourceRouter, $sourceOlt);
        $migration = $this->migration($targetRouter);
        $method = new \ReflectionMethod(CustomerMigrationService::class, 'proposedCustomerSnapshot');
        $method->setAccessible(true);
        $service = app(CustomerMigrationService::class);

        // Resolved target OLT (cross-router, new_olt_id still empty) wins over the source OLT.
        $snapshot = $method->invoke($service, $migration, $customer, $targetOlt);
        $this->assertSame($targetOlt->id, $snapshot['olt_id']);

        // An explicit new_olt_id wins over everything.
        $migration->new_olt_id = $targetOlt->id;
        $snapshot = $method->invoke($service, $migration, $customer, null);
        $this->assertSame($targetOlt->id, $snapshot['olt_id']);
    }

    public function test_partial_guard_message_mentions_force_cancel_for_stale_rows(): void
    {
        $router = $this->router('SAME');
        $olt = $this->olt($router, 'OLT-SAME', 'zte_c300');
        $customer = $this->customer($router, $olt);
        $partial = $this->migration($router);
        $partial->customer_id = $customer->id;
        $partial->status = CustomerMigration::STATUS_PARTIALLY_EXECUTED;
        $partial->save();

        try {
            $this->confirm($this->migration($router), $customer);
            $this->fail('Expected refusal');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString("#migrasi batal paksa #{$partial->id}", $e->getMessage());
        }
    }

    private function confirm(CustomerMigration $migration, Customer $customer): CustomerMigration
    {
        $method = new \ReflectionMethod(CustomerMigrationService::class, 'confirmGuidedWaCandidate');
        $method->setAccessible(true);

        return $method->invoke(app(CustomerMigrationService::class), $migration, [
            'customer_id' => $customer->id,
            'pppoe_user' => $customer->pppoe_user,
            'conflicts' => [],
        ], ['sender_jid' => 'teknisi@s.whatsapp.net'], 1);
    }

    private function resolve(CustomerMigration $migration, Customer $customer, Router $router): ?Olt
    {
        $method = new \ReflectionMethod(CustomerMigrationService::class, 'resolveOltForMigration');
        $method->setAccessible(true);

        return $method->invoke(app(CustomerMigrationService::class), $migration, $customer, $router);
    }

    private function router(string $tag): Router
    {
        return Router::create([
            'name' => 'RTR-'.$tag.'-'.uniqid(),
            'ip_address' => '127.0.0.1',
            'username' => 'admin',
            'password' => Canary::of('pw'),
            'is_active' => true,
        ]);
    }

    private function olt(Router $router, string $name, string $vendor): Olt
    {
        return Olt::create([
            'name' => $name.'-'.uniqid(),
            'code' => 'C-'.uniqid(),
            'vendor' => $vendor,
            'router_id' => $router->id,
        ]);
    }

    private function customer(Router $router, Olt $olt): Customer
    {
        $package = Package::create(['code' => 'PK-'.uniqid(), 'name' => 'Paket Uji', 'price' => 150000]);

        return Customer::create([
            'code' => 'MIG-'.uniqid(),
            'name' => 'Pelanggan Uji',
            'phone' => '081200000000',
            'address' => 'RT 01 RW 01',
            'pppoe_user' => 'USERUJI'.uniqid(),
            'package_id' => $package->id,
            'router_id' => $router->id,
            'olt_id' => $olt->id,
            'status' => 'active',
        ]);
    }

    private function migration(Router $targetRouter): CustomerMigration
    {
        return CustomerMigration::create([
            'status' => CustomerMigration::STATUS_NEEDS_REVIEW,
            'source' => 'guided_lookup_wa',
            'group_jid' => 'activation@g.us',
            'message_id' => 'mig-'.uniqid(),
            'new_sn' => 'FHTTFAKE'.strtoupper(substr(uniqid(), -6)),
            'meta' => [
                'router_mapping' => [
                    'status' => 'resolved',
                    'target_router_id' => $targetRouter->id,
                ],
            ],
        ]);
    }
}
