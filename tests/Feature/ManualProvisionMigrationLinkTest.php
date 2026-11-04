<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerMigration;
use App\Models\Olt;
use App\Models\OltOnu;
use App\Models\Package;
use App\Models\PsbDraft;
use App\Models\Router;
use App\Models\User;
use App\Services\GenieAcsService;
use App\Services\ManualProvisionMigrationLinker;
use App\Services\MikrotikService;
use App\Services\OltZteC300Service;
use App\Services\PsbOrchestratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Canary;
use Tests\TestCase;

/**
 * Manual OLT UI provision must complete an OPEN customer migration instead of
 * recording an orphan PSB draft (#1106 BUDIC exact PPPoE, #1102 USERAB7
 * typo name + typo SN). Devices are fakes that fail on any call: linking is a
 * DB-only operation.
 */
#[Group('blocking-psb')]
class ManualProvisionMigrationLinkTest extends TestCase
{
    use RefreshDatabase;

    private Router $source;

    private Router $target;

    private Olt $oldOlt;

    private Olt $olt;

    private Package $package;

    protected function setUp(): void
    {
        parent::setUp();

        // Any device call fails the test: no MikroTik / GenieACS / OLT write.
        $this->app->instance(MikrotikService::class, Mockery::mock(MikrotikService::class));
        $this->app->instance(GenieAcsService::class, Mockery::mock(GenieAcsService::class));
        $this->app->instance(OltZteC300Service::class, Mockery::mock(OltZteC300Service::class));
        $this->app->instance(PsbOrchestratorService::class, Mockery::mock(PsbOrchestratorService::class)->shouldIgnoreMissing());

        $this->source = Router::create(['name' => 'Krian', 'ip_address' => '127.0.0.15', 'username' => 'admin', 'password' => Canary::of('pw'), 'is_active' => true]);
        $this->target = Router::create(['name' => 'Skynet-Krian-OLT', 'ip_address' => '127.0.0.14', 'username' => 'admin', 'password' => Canary::of('pw'), 'is_active' => true]);
        $this->oldOlt = Olt::create(['name' => 'OLT KRIAN HTB', 'code' => 'OLT-KRIAN-HTB', 'vendor' => 'zte_c300', 'router_id' => $this->source->id]);
        $this->olt = Olt::create(['name' => 'OLT KRIAN', 'code' => 'OLT-KRIAN', 'vendor' => 'zte_c300', 'router_id' => $this->target->id]);
        $this->package = Package::create(['code' => 'KRIAN-10', 'name' => 'Krian 10MB', 'price' => 150000, 'mikrotik_profile' => '10MB', 'router_id' => $this->source->id]);
    }

    public function test_incident_1106_exact_pppoe_completes_failed_migration_and_links_psb_draft(): void
    {
        $customer = $this->customer('BUDIC_RT27_RW05', 'ZTEGOLD01106', '1/1/1:9');
        $migration = $this->migration($customer, CustomerMigration::STATUS_FAILED, 'FHTTA1106OLD');
        $migrationsBefore = CustomerMigration::query()->count();

        $payload = $this->provisioned('FHTTA1106NEW', '1/2/1', 7, 'BUDIC_RT27_RW05');
        $response = app(ManualProvisionMigrationLinker::class)->recordFiberhomeProvision($payload, 'psb_new', 'Teknisi Krian');

        $this->assertSame('migration_linked', $response['recorded_as']);
        $draft = PsbDraft::query()->findOrFail($response['record_id']);
        $this->assertSame($migration->id, $response['migration_link']['migration_id']);
        $this->assertSame('exact_pppoe', $response['migration_link']['matched_by']);
        $this->assertArrayNotHasKey('migration_candidates', $response);

        $migration->refresh();
        $this->assertSame(CustomerMigration::STATUS_SYNCED, $migration->status);
        $this->assertNotNull($migration->synced_at);
        $this->assertNull($migration->last_error);
        $this->assertSame('FHTTA1106NEW', $migration->new_sn);
        $this->assertSame($this->olt->id, (int) $migration->new_olt_id);
        $this->assertSame('1/2/1:7', $migration->new_onu_ref);
        $this->assertSame('1/2/1', $migration->new_pon_port);
        $this->assertSame(7, (int) $migration->new_onu_id);
        $timeline = collect((array) data_get($migration->meta, 'timeline'))->firstWhere('step', 'manual_provision_linked');
        $this->assertNotNull($timeline);
        $this->assertSame($draft->id, data_get($timeline, 'data.psb_draft_id'));
        $this->assertSame('none', data_get($timeline, 'data.device_writes'));
        $this->assertSame('FHTTA1106OLD', data_get($timeline, 'data.previous_sn'));

        $this->assertSame($customer->id, (int) $draft->customer_id);
        $this->assertSame($migration->id, data_get($draft->meta, 'migration_id'));

        $customer->refresh();
        $this->assertSame('FHTTA1106NEW', $customer->onu_serial);
        $this->assertSame('1/2/1:7', $customer->olt_port_label);
        $this->assertSame($this->olt->id, (int) $customer->olt_id);
        $this->assertSame($this->target->id, (int) $customer->router_id);

        $this->assertSame($migrationsBefore, CustomerMigration::query()->count(), 'No parallel migration record.');
        $this->assertSame(0, CustomerMigration::query()->where('source', 'manual_fiberhome')->count());
    }

    public function test_incident_1102_near_match_only_warns_and_links_after_explicit_confirm(): void
    {
        $customer = $this->customer('USERAB27_RT1_RW2', 'ZTEGOLD01102', '1/1/1:5');
        $migration = $this->migration($customer, CustomerMigration::STATUS_PARTIALLY_EXECUTED, 'FHTT0000A00B');

        // Technician typed a truncated PPPoE and the real SN.
        $payload = $this->provisioned('FHTT0000A00D', '1/2/2', 3, 'USERAB7');
        $response = app(ManualProvisionMigrationLinker::class)->recordFiberhomeProvision($payload, 'psb_new', 'Teknisi Krian');

        $this->assertSame('psb_new', $response['recorded_as']);
        $this->assertArrayNotHasKey('migration_link', $response);
        $draft = PsbDraft::query()->findOrFail($response['record_id']);
        $this->assertNull($draft->customer_id);
        $candidate = $response['migration_candidates'][0];
        $this->assertSame($migration->id, $candidate['migration_id']);
        $this->assertTrue($candidate['linkable']);
        $this->assertContains('pppoe_near', $candidate['reasons']);
        $this->assertContains('sn_near', $candidate['reasons']);
        $this->assertSame(
            "Ada migrasi terbuka #{$migration->id} untuk USERAB27_RT1_RW2 (SN FHTT0000A00B). Tautkan?",
            $response['migration_warning']
        );

        // Never automatic.
        $migration->refresh();
        $this->assertSame(CustomerMigration::STATUS_PARTIALLY_EXECUTED, $migration->status);
        $this->assertSame('FHTT0000A00B', $migration->new_sn);
        $this->assertSame('ZTEGOLD01102', $customer->fresh()->onu_serial);

        // Explicit confirm (button "Tautkan").
        $this->actingAs(User::factory()->create(['role' => 'superadmin']))
            ->postJson(route('api.olt-c300.link-migration'), [
                'migration_id' => $migration->id,
                'psb_draft_id' => $draft->id,
                'olt_id' => $this->olt->id,
                'router_id' => $this->target->id,
                'sn' => 'FHTT0000A00D',
                'port' => '1/2/2',
                'onu_id' => 3,
                'name' => 'USERAB7',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('migration_link.migration_id', $migration->id)
            ->assertJsonPath('migration_link.matched_by', 'confirmed');

        $migration->refresh();
        $this->assertSame(CustomerMigration::STATUS_SYNCED, $migration->status);
        $this->assertSame('FHTT0000A00D', $migration->new_sn);
        $this->assertSame('1/2/2:3', $migration->new_onu_ref);
        $this->assertSame('FHTT0000A00B', data_get($migration->meta, 'sn_corrections.0.from'));
        $timeline = collect((array) data_get($migration->meta, 'timeline'))->firstWhere('step', 'manual_provision_linked');
        $this->assertSame($draft->id, data_get($timeline, 'data.psb_draft_id'));
        $this->assertSame('confirmed', data_get($timeline, 'data.matched_by'));
        $this->assertSame('USERAB7', data_get($timeline, 'data.typed_name'));

        $draft->refresh();
        $this->assertSame($customer->id, (int) $draft->customer_id);
        $this->assertSame('USERAB27_RT1_RW2', $draft->pppoe_user);
        $this->assertSame('USERAB7', data_get($draft->meta, 'typed_pppoe_user'));
        $this->assertSame($migration->id, data_get($draft->meta, 'migration_id'));

        $customer->refresh();
        $this->assertSame('FHTT0000A00D', $customer->onu_serial);
        $this->assertSame('1/2/2:3', $customer->olt_port_label);
        // Local inventory name follows the full PPPoE (DB only, no OLT rename).
        $this->assertSame('USERAB27_RT1_RW2', OltOnu::query()->where('olt_id', $this->olt->id)->where('onu_ref', '1/2/2:3')->value('onu_name'));

        // A second confirm is refused: the migration is closed.
        $this->postJson(route('api.olt-c300.link-migration'), [
            'migration_id' => $migration->id,
            'olt_id' => $this->olt->id,
            'sn' => 'FHTT0000A00D',
            'port' => '1/2/2',
            'onu_id' => 3,
        ])->assertStatus(409);
    }

    public function test_ownership_guard_blocks_sn_owned_by_another_customer(): void
    {
        $customer = $this->customer('BUDI_RT3_RW4', 'ZTEGOLD00001', '1/1/1:2');
        $migration = $this->migration($customer, CustomerMigration::STATUS_FAILED, 'FHTTOWNED001');
        $this->customer('LAINNYA_RT2_RW3', 'FHTTOWNED001', '1/1/1:3');

        $payload = $this->provisioned('FHTTOWNED001', '1/2/3', 4, 'BUDI_RT3_RW4');
        $response = app(ManualProvisionMigrationLinker::class)->recordFiberhomeProvision($payload, 'psb_new');

        $this->assertSame('psb_new', $response['recorded_as']);
        $this->assertArrayNotHasKey('migration_link', $response);
        $candidate = $response['migration_candidates'][0];
        $this->assertSame($migration->id, $candidate['migration_id']);
        $this->assertFalse($candidate['linkable']);
        $this->assertStringContainsString('SN sudah dipakai pelanggan lain (LAINNYA_RT2_RW3)', implode(' ', $candidate['blocked_reasons']));

        $this->actingAs(User::factory()->create(['role' => 'superadmin']))
            ->postJson(route('api.olt-c300.link-migration'), [
                'migration_id' => $migration->id,
                'psb_draft_id' => $response['record_id'],
                'olt_id' => $this->olt->id,
                'router_id' => $this->target->id,
                'sn' => 'FHTTOWNED001',
                'port' => '1/2/3',
                'onu_id' => 4,
                'name' => 'BUDI_RT3_RW4',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $migration->refresh();
        $this->assertSame(CustomerMigration::STATUS_FAILED, $migration->status);
        $this->assertSame('ZTEGOLD00001', $customer->fresh()->onu_serial);
        $this->assertNull(data_get(PsbDraft::query()->find($response['record_id'])?->meta, 'migration_id'));
    }

    public function test_typed_unknown_pppoe_with_exact_sn_links_to_migration_customer(): void
    {
        $customer = $this->customer('USERSITI_RT5_RW6', 'ZTEGOLD00005', '1/1/1:6');
        $migration = $this->migration($customer, CustomerMigration::STATUS_NEEDS_REVIEW, 'FHTTEXACT005');

        $payload = $this->provisioned('FHTTEXACT005', '1/2/4', 5, 'XYZ_TYPO_NAME');
        $response = app(ManualProvisionMigrationLinker::class)->recordFiberhomeProvision($payload, 'psb_new');

        $this->assertSame('exact_sn', $response['migration_link']['matched_by']);
        $this->assertSame(CustomerMigration::STATUS_SYNCED, $migration->fresh()->status);
        $draft = PsbDraft::query()->findOrFail($response['record_id']);
        $this->assertSame($customer->id, (int) $draft->customer_id);
        $this->assertSame('USERSITI_RT5_RW6', $draft->pppoe_user);
        $this->assertSame('XYZ_TYPO_NAME', data_get($draft->meta, 'typed_pppoe_user'));
        $this->assertSame($migration->id, data_get($draft->meta, 'migration_id'));
    }

    public function test_router_mismatch_and_migration_wa_never_auto_link(): void
    {
        $customer = $this->customer('USERANDI_RT7_RW8', 'ZTEGOLD00007', '1/1/1:7');
        $migration = $this->migration($customer, CustomerMigration::STATUS_FAILED, 'FHTTROUTER07');

        $payload = $this->provisioned('FHTTROUTER07', '1/2/5', 6, 'USERANDI_RT7_RW8', $this->source->id);
        $response = app(ManualProvisionMigrationLinker::class)->recordFiberhomeProvision($payload, 'psb_new');
        $this->assertArrayNotHasKey('migration_link', $response);
        $this->assertFalse($response['migration_candidates'][0]['linkable']);
        $this->assertStringContainsString('Router target berbeda', implode(' ', $response['migration_candidates'][0]['blocked_reasons']));
        $this->assertSame(CustomerMigration::STATUS_FAILED, $migration->fresh()->status);

        // migration_wa: the WA flow still owns the MikroTik step; report only.
        $payload['router_id'] = $this->target->id;
        $wa = app(ManualProvisionMigrationLinker::class)->recordFiberhomeProvision($payload, 'migration_wa');
        $this->assertSame('migration_existing', $wa['recorded_as']);
        $this->assertSame($migration->id, $wa['migration_open']['migration_id']);
        $this->assertSame(CustomerMigration::STATUS_FAILED, $migration->fresh()->status);
    }

    public function test_cancelled_and_synced_migrations_are_not_open(): void
    {
        $customer = $this->customer('USERRINA_RT9_RW1', 'ZTEGOLD00009', '1/1/1:8');
        $this->migration($customer, CustomerMigration::STATUS_CANCELLED, 'FHTTCLOSED09');

        $payload = $this->provisioned('FHTTCLOSED09', '1/2/6', 8, 'USERRINA_RT9_RW1');
        $response = app(ManualProvisionMigrationLinker::class)->recordFiberhomeProvision($payload, 'psb_new');

        $this->assertSame('psb_new', $response['recorded_as']);
        $this->assertArrayNotHasKey('migration_link', $response);
        $this->assertArrayNotHasKey('migration_candidates', $response);
    }

    private function customer(string $pppoe, string $sn, string $ref): Customer
    {
        return Customer::create([
            'code' => 'MPL-'.substr(md5($pppoe), 0, 8),
            'name' => $pppoe,
            'phone' => '0812000'.random_int(10000, 99999),
            'address' => 'Krian',
            'package_id' => $this->package->id,
            'pppoe_user' => $pppoe,
            'mikrotik_profile' => '10MB',
            'onu_serial' => $sn,
            'olt_port_label' => $ref,
            'router_id' => $this->source->id,
            'olt_id' => $this->oldOlt->id,
            'status' => 'active',
        ]);
    }

    private function migration(Customer $customer, string $status, string $newSn): CustomerMigration
    {
        return CustomerMigration::create([
            'status' => $status,
            'source' => 'guided_lookup_wa',
            'group_jid' => 'activation@g.us',
            'sender_name' => 'Teknisi Krian',
            'message_id' => 'guided-wa:'.$customer->pppoe_user,
            'raw_text' => '#migrasi '.$customer->pppoe_user,
            'customer_id' => $customer->id,
            'pppoe_user' => $customer->pppoe_user,
            'full_name' => $customer->name,
            'old_router_id' => $this->source->id,
            'old_olt_id' => $this->oldOlt->id,
            'old_onu_ref' => $customer->olt_port_label,
            'old_sn' => $customer->onu_serial,
            'router_id' => $this->target->id,
            'new_olt_id' => $this->olt->id,
            'new_sn' => $newSn,
            'last_error' => 'OLT step gagal',
            'meta' => [],
        ]);
    }

    /**
     * What the controller has after a committed manual FiberHome provision:
     * the local inventory row (upsertOltRaInventory) named as typed.
     *
     * @return array<string, mixed>
     */
    private function provisioned(string $sn, string $port, int $onuId, string $name, ?int $routerId = null): array
    {
        OltOnu::create([
            'olt_id' => $this->olt->id,
            'onu_ref' => "{$port}:{$onuId}",
            'pon_port' => $port,
            'serial_number' => $sn,
            'onu_name' => $name,
            'state' => 'online',
            'phase_state' => 'working',
        ]);

        return [
            'olt_id' => $this->olt->id,
            'router_id' => $routerId ?? $this->target->id,
            'sn' => $sn,
            'port' => $port,
            'onu_id' => $onuId,
            'name' => $name,
            'vlan' => 309,
            'tr069_vlan' => 68,
        ];
    }
}
